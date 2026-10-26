<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\RoomRestarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\QuestionPicker;
use App\Game\RoomManager;
use App\Game\SeenQuestions;
use App\Listeners\ClaimGuestResults;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class, RoomRestarted::class]);
    Queue::fake();
});

/** Say these players saw these questions (in a throwaway room), $ago minutes ago. */
function sawQuestions(User|string $who, array $questionIds, int $ago = 60): void
{
    $room = Room::factory()->finished()->create();
    DB::table('question_seen')->insert(array_map(fn (int $id) => [
        'question_id' => $id,
        'user_id' => $who instanceof User ? $who->id : null,
        'guest_token' => $who instanceof User ? null : $who,
        'room_id' => $room->id,
        'seen_at' => now()->subMinutes($ago),
    ], $questionIds));
}

/** A lobby with exactly these participants (users or guest tokens), all connected and ready. */
function lobbyFor(array $who, int $questions, $category, ?User $host = null): Room
{
    $room = gameRoom(players: 0, questions: $questions, host: $host, category: $category);
    foreach ($who as $person) {
        RoomPlayer::factory()->for($room)->when($person instanceof User, fn ($factory) => $factory->forUser($person), fn ($factory) => $factory->state(['guest_token' => $person]))
            ->create(['is_ready' => true, 'left_at' => null]);
    }

    return $room;
}

/** Reveal and advance every question of a started game, the way the host's clock would. */
function playThrough(Room $room): void
{
    $engine = app(GameEngine::class);
    foreach ($room->roomQuestions()->pluck('position') as $position) {
        $engine->reveal($room, $position, force: true);
        $engine->advance($room, $position, force: true);
    }
}

describe('no question twice in one game', function () {
    it('is enforced by the database: room_questions is unique per room and question', function () {
        $room = Room::factory()->create();
        $question = Question::factory()->create();
        RoomQuestion::factory()->create(['room_id' => $room->id, 'question_id' => $question->id, 'position' => 1]);

        expect(fn () => RoomQuestion::factory()->create(['room_id' => $room->id, 'question_id' => $question->id, 'position' => 2]))
            ->toThrow(UniqueConstraintViolationException::class);
        // ...but the same question in another room is fine.
        expect(RoomQuestion::factory()->create(['room_id' => Room::factory()->create()->id, 'question_id' => $question->id, 'position' => 1])->exists)->toBeTrue();
    });

    it('never repeats inside a game over 200 simulated games with changing groups, hosts and pool sizes', function () {
        $category = categoryWithQuestions(30);
        $hosts = User::factory()->count(2)->create();
        $people = [...User::factory()->count(3)->create()->all(), ...array_map(fn () => str()->random(64), range(1, 3))];

        for ($game = 0; $game < 200; $game++) {
            $count = [5, 10, 15, 20][$game % 4];
            $group = collect($people)->shuffle()->take(2 + $game % 3)->all();
            $room = lobbyFor($group, $count, $category, $hosts[$game % 2]);

            startGame($room);

            $asked = $room->roomQuestions()->pluck('question_id');
            expect($asked)->toHaveCount($count)->and($asked->unique())->toHaveCount($count);
            // What the reveals would write, without clocking through every question: this loop is about the picking.
            $asked->each(fn (int $id) => app(SeenQuestions::class)->record($room, $id));
        }
    });
});

describe('what each player has seen', function () {
    it('is written at the reveal, for the users and the guests of the room', function () {
        $user = User::factory()->create();
        $token = str_repeat('a', 64);
        $room = lobbyFor([$user, $token], 3, categoryWithQuestions(8));
        startGame($room);
        $first = $room->roomQuestions()->where('position', 1)->first();

        expect(DB::table('question_seen')->count())->toBe(0); // opened, not revealed yet

        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->reveal($room, 1, force: true); // a second call is a no-op

        $rows = DB::table('question_seen')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('question_id')->unique()->all())->toBe([$first->question_id])
            ->and($rows->pluck('room_id')->unique()->all())->toBe([$room->id])
            ->and($rows->firstWhere('user_id', $user->id)->guest_token)->toBeNull()
            ->and($rows->firstWhere('guest_token', $token)->user_id)->toBeNull();
    });

    it('prefers questions none of the players has seen, users and guests alike', function () {
        $category = categoryWithQuestions(24);
        $ids = Question::pluck('id')->all();
        $user = User::factory()->create();
        $token = str_repeat('b', 64);
        sawQuestions($user, array_slice($ids, 0, 8));
        sawQuestions($token, array_slice($ids, 6, 8));   // 0-13 are seen by somebody; 14-23 by nobody
        $room = lobbyFor([$user, $token], 10, $category);

        startGame($room);

        expect($room->roomQuestions()->pluck('question_id')->sort()->values()->all())->toBe(array_slice($ids, 14, 10));
    });

    it('then takes the ones fewest of the players have seen', function () {
        $category = categoryWithQuestions(12);
        $ids = Question::pluck('id')->all();
        $one = User::factory()->create();
        $two = User::factory()->create();
        sawQuestions($one, array_slice($ids, 0, 6));      // 0-5
        sawQuestions($two, array_slice($ids, 4, 5));      // 4-8: so 4 and 5 are seen by both
        $room = lobbyFor([$one, $two], 10, $category);

        startGame($room);

        $asked = $room->roomQuestions()->pluck('question_id')->all();
        expect($asked)->toHaveCount(10)->and(array_intersect($asked, [$ids[4], $ids[5]]))->toBe([]); // only the two seen by both are left out
    });

    it('then the ones seen longest ago', function () {
        $category = categoryWithQuestions(10);
        $ids = Question::pluck('id')->all();
        $user = User::factory()->create();
        foreach ($ids as $index => $id) {
            sawQuestions($user, [$id], ago: 100 - $index * 10);   // the first was seen longest ago
        }
        $room = lobbyFor([$user], 5, $category);

        startGame($room);

        expect($room->roomQuestions()->pluck('question_id')->sort()->values()->all())->toBe(array_slice($ids, 0, 5));
    });

    it('still starts when the pool is too small to avoid every repeat', function () {
        $category = categoryWithQuestions(10);
        $ids = Question::pluck('id')->all();
        $user = User::factory()->create();
        sawQuestions($user, $ids);
        $room = lobbyFor([$user], 10, $category);

        startGame($room);

        expect($room->fresh()->status)->toBe(RoomStatus::Playing)->and($room->roomQuestions()->count())->toBe(10);
    });

    it('ignores what other people have seen', function () {
        $category = categoryWithQuestions(12);
        $ids = Question::pluck('id')->all();
        sawQuestions(User::factory()->create(), array_slice($ids, 0, 6));
        sawQuestions(str_repeat('z', 64), array_slice($ids, 0, 6));
        $room = lobbyFor([User::factory()->create(), str_repeat('y', 64)], 12, $category);

        expect(app(QuestionPicker::class)->freshFor($room->settings, $room->players()->get()))->toBe(12);
    });

    it('counts the fresh questions of a group, for the create-room warning', function () {
        $category = categoryWithQuestions(20);
        $ids = Question::pluck('id')->all();
        $user = User::factory()->create();
        $token = str_repeat('c', 64);
        sawQuestions($user, array_slice($ids, 0, 12));
        sawQuestions($token, array_slice($ids, 8, 6));    // 0-13 seen by somebody: 6 are left
        $room = lobbyFor([$user, $token], 10, $category);

        expect(app(QuestionPicker::class)->freshFor($room->settings, $room->players()->get()))->toBe(6);
    });
});

describe('play again', function () {
    it('avoids the questions just played when there are enough others', function () {
        $category = categoryWithQuestions(15);
        $host = User::factory()->create();
        $room = lobbyFor([str_repeat('d', 64), str_repeat('e', 64)], 5, $category, $host);
        startGame($room);
        $played = $room->roomQuestions()->pluck('question_id')->all();
        playThrough($room);
        expect($room->fresh()->status)->toBe(RoomStatus::Finished);

        $again = app(RoomManager::class)->playAgain($room->fresh(), $host);
        $again->players()->update(['left_at' => null, 'is_ready' => true]);
        startGame($again);

        $second = $again->roomQuestions()->pluck('question_id')->all();
        expect($second)->toHaveCount(5)->and(array_intersect($played, $second))->toBe([]);
    });

    it('avoids them even when the players are different people, through the host\'s recent rooms', function () {
        $category = categoryWithQuestions(15);
        $host = User::factory()->create();
        $first = lobbyFor([str_repeat('f', 64)], 5, $category, $host);
        startGame($first);
        $played = $first->roomQuestions()->pluck('question_id')->all();
        playThrough($first);

        $second = lobbyFor([str_repeat('g', 64), User::factory()->create()], 5, $category, $host);
        startGame($second);

        expect(array_intersect($played, $second->roomQuestions()->pluck('question_id')->all()))->toBe([]);
    });
});

describe('housekeeping', function () {
    it('forgets what guests saw after the claim window, and keeps what users saw', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        $hours = (int) config('lamma.guest_claim_hours');
        $question = Question::factory()->create();
        $user = User::factory()->create();
        $room = Room::factory()->finished()->create();
        $row = fn (?User $who, ?string $token, $at) => DB::table('question_seen')->insertGetId(['question_id' => $question->id, 'user_id' => $who?->id, 'guest_token' => $token, 'room_id' => $room->id, 'seen_at' => $at]);
        $old = $row(null, str_repeat('h', 64), now()->subHours($hours)->subSecond());
        $edge = $row(null, str_repeat('i', 64), now()->subHours($hours));
        $mine = $row($user, null, now()->subDays(60));

        $this->artisan('lamma:prune')->expectsOutputToContain('Forgot 1 guest sightings.')->assertSuccessful();

        expect(DB::table('question_seen')->pluck('id')->sort()->values()->all())->toBe(collect([$edge, $mine])->sort()->values()->all())
            ->and(DB::table('question_seen')->where('id', $old)->exists())->toBeFalse();
    });

    it('is attached to the account when the device logs in', function () {
        $token = str_repeat('l', 64);
        $user = User::factory()->create();
        $room = lobbyFor([$token], 3, categoryWithQuestions(8));
        startGame($room);
        playThrough($room);
        $stranger = lobbyFor([str_repeat('m', 64)], 3, categoryWithQuestions(8));
        startGame($stranger);
        playThrough($stranger);

        $request = Request::create('/login');
        $request->cookies->set(config('lamma.guest_cookie'), $token);
        app()->instance('request', $request);
        app(ClaimGuestResults::class)->handle(new Login('web', $user, false));

        expect(DB::table('question_seen')->where('user_id', $user->id)->count())->toBe(3)
            ->and(DB::table('question_seen')->where('guest_token', $token)->count())->toBe(0)
            ->and(DB::table('question_seen')->whereNull('user_id')->count())->toBe(3); // the stranger's
    });
});
