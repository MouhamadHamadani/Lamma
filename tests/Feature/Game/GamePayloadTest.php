<?php

use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\PlayerJoined;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\RoomClosed;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\Scoreboard;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** Every key, at any depth, of a payload. */
function allKeys(array $payload): array
{
    $keys = [];
    array_walk_recursive($payload, function () {});
    $walk = function (array $node) use (&$walk, &$keys) {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($payload);

    return $keys;
}

function lastPayload(string $event): array
{
    return Event::dispatched($event)->last()[0]->broadcastWith();
}

describe('QuestionStarted', function () {
    it('never says which option is correct', function () {
        $room = gameRoom(questions: 5);
        startGame($room);

        foreach (Event::dispatched(QuestionStarted::class) as [$event]) {
            $payload = $event->broadcastWith();
            $json = json_encode($payload);

            expect(allKeys($payload))->not->toContain('is_correct')->not->toContain('correct')->not->toContain('correct_option_id')->not->toContain('answer')
                ->and($json)->not->toContain('is_correct')->not->toContain('correct');
        }
    });

    it('carries the question, category and options in both languages, in display order', function () {
        $category = categoryWithQuestions(8, category: ['name' => ['en' => 'Geography', 'ar' => 'جغرافيا'], 'slug' => 'geography']);
        $room = gameRoom(questions: 5, settings: ['secondsPerQuestion' => 30], category: $category);
        $question = startGame($room);

        $payload = lastPayload(QuestionStarted::class);
        $data = $payload['question'];
        $model = $question->question()->with('options')->first();

        expect($payload['code'])->toBe($room->code)
            ->and($data['position'])->toBe(1)->and($data['total'])->toBe(5)->and($data['seconds'])->toBe(30)
            ->and($data['category']['name'])->toBe(['ar' => 'جغرافيا', 'en' => 'Geography'])
            ->and($data['category']['tint'])->toBeIn(['teal', 'coral', 'sun', 'navy'])->and($data['category']['icon'])->toBe('globe')
            ->and($data['text'])->toBe(['ar' => $model->getTranslation('text', 'ar'), 'en' => $model->getTranslation('text', 'en')])
            ->and(array_column($data['options'], 'id'))->toBe($question->option_order)
            ->and($data['options'][0]['text'])->toHaveKeys(['ar', 'en'])
            ->and(array_keys($data['options'][0]))->toBe(['id', 'text']);
    });

    it('gives the deadline as ISO time with milliseconds and the server time to correct clock drift', function () {
        $room = gameRoom();
        $question = startGame($room);

        $payload = lastPayload(QuestionStarted::class);

        expect($payload['question']['started_at'])->toBe('2026-10-01T12:00:00.000Z')
            ->and($payload['question']['ends_at'])->toBe('2026-10-01T12:00:20.000Z')
            ->and($payload['server_time'])->toBe(now()->getTimestampMs())
            ->and(CarbonImmutable::parse($payload['question']['ends_at'])->equalTo($question->ends_at))->toBeTrue();
    });

    it('lists every option of the question and nothing else', function () {
        $room = gameRoom(questions: 3);
        $question = startGame($room);

        expect(count(lastPayload(QuestionStarted::class)['question']['options']))->toBe($question->question->options()->count());
    });
});

describe('PlayerAnswered', function () {
    it('has the position, the player and the counts, and no option', function () {
        [$room, $question, $players] = openQuestion();

        answer($players[1], $question, true);

        $payload = lastPayload(PlayerAnswered::class);
        expect(array_keys($payload))->toBe(['code', 'server_time', 'position', 'player', 'answered', 'expected'])
            ->and($payload['player'])->toBe(['id' => $players[1]->id, 'nickname' => $players[1]->nickname]);
    });
});

describe('QuestionRevealed', function () {
    it('names the correct option, who picked each option and the points gained', function () {
        [$room, $question, $players] = openQuestion();
        $right = optionOf($question, true);
        $wrong = optionOf($question, false);
        answer($players[0], $question, true);
        answer($players[1], $question, false);
        at('12:00:21.000');
        app(GameEngine::class)->reveal($room, 1);

        $payload = lastPayload(QuestionRevealed::class);
        $picks = collect($payload['picks'])->keyBy('option_id');

        expect($payload['position'])->toBe(1)->and($payload['correct_option_id'])->toBe($right)
            ->and(array_column($payload['picks'], 'option_id'))->toBe($question->option_order)
            ->and(array_column($picks[$right]['players'], 'id'))->toBe([$players[0]->id])
            ->and(array_column($picks[$wrong]['players'], 'id'))->toBe([$players[1]->id])
            ->and(collect($payload['points'])->pluck('points', 'player_id')->all())->toBe([$players[0]->id => 100, $players[1]->id => 0, $players[2]->id => 0])
            ->and($payload['revealed_at'])->toBe('2026-10-01T12:00:21.000Z');
    });

    it('lists players who did not answer nowhere in the picks, with 0 points', function () {
        [$room, $question, $players] = openQuestion();
        at('12:00:21.000');
        app(GameEngine::class)->reveal($room, 1);

        $payload = lastPayload(QuestionRevealed::class);

        expect(collect($payload['picks'])->flatMap(fn ($pick) => $pick['players']))->toBeEmpty()
            ->and(collect($payload['points'])->sum('points'))->toBe(0);
    });
});

describe('ScoreboardUpdated', function () {
    it('ranks players with their total and what they gained, ties sharing a rank', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question, true);
        answer($players[1], $question, true);
        answer($players[2], $question, false);

        $ranking = lastPayload(ScoreboardUpdated::class)['ranking'];

        expect(array_column($ranking, 'rank'))->toBe([1, 1, 3])
            ->and(array_column($ranking, 'total'))->toBe([100, 100, 0])
            ->and(array_column($ranking, 'gained'))->toBe([100, 100, 0])
            ->and(array_column(array_column($ranking, 'player'), 'id'))->toBe([$players[0]->id, $players[1]->id, $players[2]->id])
            ->and(array_keys($ranking[0]['player']))->toBe(['id', 'nickname', 'locale']);
    });

    it('adds this round to the totals of earlier rounds', function () {
        [$room, $first, $players] = openQuestion();
        answer($players[0], $first);
        answer($players[1], $first, false);
        answer($players[2], $first, false);
        app(GameEngine::class)->advance($room, 1, force: true);
        $second = $room->fresh()->currentQuestion();
        answer($players[0], $second, false);
        answer($players[1], $second, true);
        answer($players[2], $second, true);

        $ranking = lastPayload(ScoreboardUpdated::class)['ranking'];

        expect(collect($ranking)->pluck('total', 'player.id')->all())->toBe([$players[0]->id => 100, $players[1]->id => 100, $players[2]->id => 100])
            ->and(collect($ranking)->pluck('gained', 'player.id')->all())->toBe([$players[0]->id => 0, $players[1]->id => 100, $players[2]->id => 100]);
    });

    it('knows where each player stood before the round, to animate the rows', function () {
        $ranking = [
            ['rank' => 1, 'player' => ['id' => 3, 'nickname' => 'C', 'locale' => 'en'], 'total' => 200, 'gained' => 100],
            ['rank' => 2, 'player' => ['id' => 1, 'nickname' => 'A', 'locale' => 'en'], 'total' => 150, 'gained' => 0],
            ['rank' => 3, 'player' => ['id' => 2, 'nickname' => 'B', 'locale' => 'en'], 'total' => 100, 'gained' => 0],
        ];

        expect(app(Scoreboard::class)->previousPositions($ranking))->toEqual([1 => 0, 3 => 1, 2 => 2]); // A led on 150; C and B were level on 100
    });

    it('writes ranks as 2nd in English and as a plain number in Arabic', function () {
        app()->setLocale('en');
        expect(array_map(Scoreboard::ordinal(...), [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 101, 111]))->toBe(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '101st', '111th']);

        app()->setLocale('ar');
        expect(Scoreboard::ordinal(2))->toBe('2');
    });
});

describe('GameFinished', function () {
    it('carries the final ranking', function () {
        $room = gameRoom(players: 2, questions: 3);
        $players = $room->players()->orderBy('id')->get();
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            answer($players[1], $room->fresh()->currentQuestion(), true);
            answer($players[0], $room->fresh()->currentQuestion(), false);
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        $ranking = lastPayload(GameFinished::class)['ranking'];

        expect(array_column(array_column($ranking, 'player'), 'id'))->toBe([$players[1]->id, $players[0]->id])
            ->and(array_column($ranking, 'total'))->toBe([300, 0]);
    });
});

describe('every event', function () {
    it('carries the server time, and never a token, an email or an account id', function () {
        $user = User::factory()->create(['email' => 'private@example.com']);
        $room = gameRoom(players: 1, questions: 3);
        $room->players()->update(['guest_token' => str_repeat('s', 64), 'user_id' => $user->id]);
        $player = $room->players()->first();
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            answer($player, $room->fresh()->currentQuestion(), true);
            app(GameEngine::class)->advance($room, $position, force: true);
        }
        $events = collect([QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class, GameStarted::class])
            ->flatMap(fn ($class) => Event::dispatched($class)->map(fn ($call) => $call[0]));
        $events->push(new RoomClosed($room));

        expect($events->count())->toBeGreaterThan(10);
        foreach ($events as $event) {
            $payload = $event->broadcastWith();

            expect($payload['server_time'])->toBeInt()->toBeGreaterThan(1_700_000_000_000)
                ->and($payload['code'])->toBe($room->code)
                ->and(json_encode($payload))->not->toContain(str_repeat('s', 64))->not->toContain('private@example.com')->not->toContain('guest_token')->not->toContain('user_id')->not->toContain('email');
        }
    });

    it('goes to the room\'s presence channel only', function () {
        $room = gameRoom(players: 1, questions: 3);
        startGame($room);

        foreach ([GameStarted::class, QuestionStarted::class] as $class) {
            $channels = Event::dispatched($class)->first()[0]->broadcastOn();

            expect($channels)->toHaveCount(1)->and($channels[0]->name)->toBe('presence-room.'.$room->code);
        }
    });

    it('is sent without a queue worker (ShouldBroadcastNow)', function () {
        foreach ([QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class, GameStarted::class] as $class) {
            expect(is_subclass_of($class, ShouldBroadcastNow::class))->toBeTrue();
        }
    });

    it('is a fresh clock reading each time', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();
        $event = new PlayerJoined($room, $player);

        $first = $event->broadcastWith()['server_time'];
        $this->travel(2)->seconds();

        expect($event->broadcastWith()['server_time'])->toBe($first + 2000);
    });
});
