<?php

use App\Enums\RoomStatus;
use App\Enums\VoteOutcome;
use App\Events\GameFinished;
use App\Events\QuestionStarted;
use App\Events\VoteResult;
use App\Events\VoteStarted;
use App\Game\GameEngine;
use App\Jobs\AdvanceQuestion;
use App\Jobs\CloseVote;
use App\Jobs\RevealQuestion;
use App\Jobs\StartQuestion;
use App\Livewire\Player\PlayerGame;
use App\Models\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Models\RoomQuestion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SIM_TOKEN = 'si1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1msi1m';

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

/**
 * Play the queue like a worker (every job the game queues, the vote's included): take the earliest job nobody has run, jump the clock to when it is
 * due, run it, until the queue is quiet. Whenever a vote has just opened, $atVote($round) plays the phones' part.
 */
function runVoteQueue(Room $room, Closure $atVote): void
{
    $ran = [];
    $voted = [];
    for ($guard = 0; $guard < 500; $guard++) {
        if (($open = votes()->openRound($room->fresh())) !== null && ! in_array($open->round, $voted, true)) {
            $voted[] = $open->round;
            $atVote($open);
        }

        $next = collect([StartQuestion::class, RevealQuestion::class, AdvanceQuestion::class, CloseVote::class])
            ->flatMap(fn (string $job) => Queue::pushed($job))
            ->reject(fn ($job) => in_array(spl_object_id($job), $ran, true))
            ->sortBy(fn ($job) => $job->delay->getTimestampMs())
            ->first();
        if ($next === null) {
            return;
        }

        $ran[] = spl_object_id($next);
        if ($next->delay->isAfter(now())) {
            test()->travelTo($next->delay);
        }
        $next->handle(app(GameEngine::class));
    }

    throw new RuntimeException('The queue never went quiet.');
}

it('plays a whole Vote game of 20 questions, voting every 5, from the start to GameFinished: a tie, a reconnect, a vote nobody answers', function () {
    $room = voteRoom(players: 3, questions: 20, every: 5, pool: 5, perCategory: 8);
    [$a, $b, $c] = $room->players()->orderBy('id')->get()->all();
    $c->update(['guest_token' => SIM_TOKEN, 'locale' => 'en']);
    $engine = app(GameEngine::class);

    $engine->start($room, $room->host);
    runVoteQueue($room, function (CategoryVoteRound $round) use ($engine, $a, $b, $c, $room) {
        match ($round->round) {
            // Round 1: C's phone drops; A and B split their votes. Nobody waits for C, and the tie is settled at random.
            1 => [$c->update(['left_at' => now()]), $engine->castVote($a, $round->option_ids[0]), $engine->castVote($b, $round->option_ids[1])],
            // Round 2: C comes back mid-vote, finds the vote on the phone and still votes; the last vote closes it at once.
            2 => (function () use ($engine, $a, $b, $c, $round, $room) {
                test()->travel(4)->seconds();
                $c->update(['left_at' => null]);
                Auth::guard('player')->forgetUser();
                Livewire::withCookie('lamma_guest', SIM_TOKEN)->test(PlayerGame::class, ['room' => $room])->assertSeeHtml('data-vote="vote"');
                $engine->castVote($a, $round->option_ids[0]);
                $engine->castVote($b, $round->option_ids[0]);
                expect($round->fresh()->isOpen())->toBeTrue();
                $engine->castVote($c, $round->option_ids[1]);
            })(),
            // Round 3: nobody votes; the CloseVote job closes it at the deadline.
            default => null,
        };
    });

    $room = $room->fresh();
    $rounds = $room->voteRounds()->get();
    $questions = $room->roomQuestions()->get();
    $batch = fn (int $from) => $questions->whereBetween('position', [$from, $from + 4])->map(fn (RoomQuestion $question) => topCategoryOf($question))->unique()->values()->all();

    expect($room->status)->toBe(RoomStatus::Finished)
        ->and($room->isCompleted())->toBeTrue()
        ->and($questions->pluck('position')->all())->toBe(range(1, 20))
        ->and($questions->pluck('question_id')->duplicates())->toBeEmpty()
        ->and($rounds->pluck('outcome')->all())->toBe([VoteOutcome::Tie, VoteOutcome::Votes, VoteOutcome::NoVotes])
        ->and($rounds[0]->winner_category_id)->toBeIn(array_slice($rounds[0]->option_ids, 0, 2))
        ->and($rounds[1]->winner_category_id)->toBe($rounds[1]->option_ids[0])
        ->and($rounds[2]->winner_category_id)->toBeIn($rounds[2]->option_ids)
        ->and($batch(1))->toBe([$room->first_category_id])
        ->and($batch(6))->toBe([$rounds[0]->winner_category_id])
        ->and($batch(11))->toBe([$rounds[1]->winner_category_id])
        ->and($batch(16))->toBe([$rounds[2]->winner_category_id])
        ->and(CategoryVote::query()->where('room_id', $room->id)->count())->toBe(5)
        // The next batch only opens once the result has been on screen for its 3 seconds.
        ->and($questions->firstWhere('position', 6)->started_at->diffInMilliseconds($rounds[0]->closed_at, true))->toBeGreaterThanOrEqual(3000.0)
        ->and($rounds[2]->closed_at->diffInMilliseconds($rounds[2]->ends_at, true))->toBe(500.0);
    // Every category offered once per cycle: no vote offers the first category or a winner again (5 in the pool, 4 batches).
    expect(collect($rounds)->flatMap(fn ($round) => $round->option_ids)->intersect([$room->first_category_id]))->toBeEmpty();

    Event::assertDispatchedTimes(VoteStarted::class, 3);
    Event::assertDispatchedTimes(VoteResult::class, 3);
    Event::assertDispatchedTimes(QuestionStarted::class, 20);
    Event::assertDispatchedTimes(GameFinished::class, 1);
});

it('still plays a Classic game exactly as before: everything drawn at the start, no vote anywhere', function () {
    $room = gameRoom(players: 2, questions: 10);
    app(GameEngine::class)->start($room, $room->host);
    expect($room->roomQuestions()->count())->toBe(10);

    runVoteQueue($room, fn () => throw new RuntimeException('A Classic game never votes.'));

    expect($room->fresh()->status)->toBe(RoomStatus::Finished)
        ->and($room->roomQuestions()->whereNotNull('revealed_at')->count())->toBe(10)
        ->and(CategoryVoteRound::count())->toBe(0);
    Event::assertNotDispatched(VoteStarted::class);
    Event::assertDispatchedTimes(QuestionStarted::class, 10);
    Event::assertDispatchedTimes(GameFinished::class, 1);
    Queue::assertNotPushed(CloseVote::class);
});
