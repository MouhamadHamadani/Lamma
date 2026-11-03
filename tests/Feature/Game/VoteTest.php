<?php

use App\Enums\VoteOutcome;
use App\Events\PlayerVoted;
use App\Events\QuestionStarted;
use App\Events\VoteResult;
use App\Events\VoteStarted;
use App\Game\AnswerRejected;
use App\Game\GameEngine;
use App\Game\Transition;
use App\Jobs\AdvanceQuestion;
use App\Jobs\CloseVote;
use App\Models\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

function voteEngine(): GameEngine
{
    return app(GameEngine::class);
}

/** @return list<RoomPlayer> */
function playersOf(Room $room): array
{
    return $room->players()->orderBy('id')->get()->all();
}

/** Every pool category of the room except the first, by id. */
function othersInPool(Room $room): array
{
    return $room->pool()->pluck('categories.id')->reject(fn ($id) => $id === $room->first_category_id)->values()->all();
}

describe('opening a vote', function () {
    it('opens after the reveal of the last question of a batch, instead of the next question', function () {
        $room = voteRoom(questions: 10, every: 5);
        startGame($room);
        foreach (range(1, 4) as $n) {
            voteEngine()->reveal($room, $n, force: true);
            expect(voteEngine()->advance($room, $n, force: true))->toBe(Transition::Done);
        }
        voteEngine()->reveal($room, 5, force: true);
        Event::fake();
        Queue::fake();

        expect(voteEngine()->advance($room, 5))->toBe(Transition::TooEarly);
        $this->travel(5)->seconds();
        expect(voteEngine()->advance($room, 5))->toBe(Transition::Voting);

        $round = votes()->openRound($room);
        expect($round->round)->toBe(1)
            ->and($round->ends_at->getTimestampMs())->toBe(now()->addSeconds(10)->getTimestampMs())
            ->and($room->fresh()->currentQuestion()->position)->toBe(5)
            ->and($room->roomQuestions()->count())->toBe(5);
        Event::assertDispatched(VoteStarted::class);
        Event::assertNotDispatched(QuestionStarted::class);
        Queue::assertPushed(CloseVote::class, fn (CloseVote $job) => $job->round === 1 && $job->delay->getTimestampMs() === now()->addSeconds(10)->addMilliseconds(500)->getTimestampMs());
    });

    it('opens on the host\'s Next question at a batch boundary, and only once on a double click', function () {
        $room = voteRoom(questions: 10, every: 5);
        playToVote($room);
        $round = votes()->openRound($room);

        expect(voteEngine()->advance($room, 5, force: true))->toBe(Transition::Already)
            ->and(CategoryVoteRound::count())->toBe(1)
            ->and($round->fresh()->isOpen())->toBeTrue();
    });

    it('offers up to three random pool categories, never one already played', function () {
        $room = voteRoom(questions: 10, every: 5, pool: 5, perCategory: 6);
        $round = playToVote($room);

        expect($round->option_ids)->toHaveCount(3)
            ->and($round->option_ids)->not->toContain($room->first_category_id)
            ->and(array_diff($round->option_ids, othersInPool($room)))->toBeEmpty()
            ->and($round->option_ids)->toBe(array_values(array_unique($round->option_ids)));
    });

    it('offers fewer when fewer are left', function () {
        $room = voteRoom(questions: 10, every: 5, pool: 3, perCategory: 6);
        $round = playToVote($room);

        expect($round->option_ids)->toEqualCanonicalizing(othersInPool($room));
    });

    it('starts a new cycle when every pool category was played, never offering the one just played', function () {
        [$a, $b, $c] = collect(range(1, 3))->map(fn () => categoryWithQuestions(12))->all();
        $room = voteRoom(questions: 20, every: 3, categories: [$a, $b, $c]);
        startGame($room);
        CategoryVoteRound::factory()->for($room)->create(['round' => 1, 'option_ids' => [$b->id, $c->id], 'closed_at' => now(), 'winner_category_id' => $b->id, 'outcome' => VoteOutcome::Votes]);
        CategoryVoteRound::factory()->for($room)->create(['round' => 2, 'option_ids' => [$c->id], 'closed_at' => now(), 'winner_category_id' => $c->id, 'outcome' => VoteOutcome::OnlyOption]);

        expect(votes()->offer($room))->toEqualCanonicalizing([$a->id, $b->id]);

        CategoryVoteRound::factory()->for($room)->create(['round' => 3, 'option_ids' => [$a->id, $b->id], 'closed_at' => now(), 'winner_category_id' => $a->id, 'outcome' => VoteOutcome::Votes]);

        // The new cycle began with A: B and C are unused in it.
        expect(votes()->offer($room))->toEqualCanonicalizing([$b->id, $c->id]);
    });

    it('skips the vote when only one category can be offered: no vote screen, the next question opens from it', function () {
        [$a, $b, $c] = collect(range(1, 3))->map(fn () => categoryWithQuestions(12))->all();
        $room = voteRoom(questions: 15, every: 5, categories: [$a, $b, $c]);
        $round = playToVote($room);
        voteEngine()->castVote(playersOf($room)[0], $round->option_ids[0]);
        voteEngine()->castVote(playersOf($room)[1], $round->option_ids[0]);
        $winner = $round->option_ids[0];
        $left = $winner === $b->id ? $c->id : $b->id;
        foreach (range(5, 9) as $n) {
            voteEngine()->advance($room, $n, force: true);
            voteEngine()->reveal($room, $n + 1, force: true);
        }
        Event::fake();

        expect(voteEngine()->advance($room, 10, force: true))->toBe(Transition::Done);

        $skipped = $room->voteRounds()->where('round', 2)->sole();
        expect($skipped->outcome)->toBe(VoteOutcome::OnlyOption)
            ->and($skipped->winner_category_id)->toBe($left)
            ->and($skipped->ends_at)->toBeNull()
            ->and(votes()->openRound($room))->toBeNull()
            ->and(topCategoryOf($room->fresh()->currentQuestion()))->toBe($left)
            ->and($room->fresh()->currentQuestion()->position)->toBe(11);
        Event::assertNotDispatched(VoteStarted::class);
        Event::assertDispatched(QuestionStarted::class);
    });

    it('never opens a vote after the last batch, nor in a Classic game', function () {
        $room = voteRoom(questions: 10, every: 5);
        playToVote($room);
        voteEngine()->closeVote($room, 1, force: true);
        foreach (range(5, 9) as $n) {
            voteEngine()->advance($room, $n, force: true);
            voteEngine()->reveal($room, $n + 1, force: true);
        }
        expect(voteEngine()->advance($room, 10, force: true))->toBe(Transition::Finished);

        $classic = gameRoom(questions: 6);
        startGame($classic);
        foreach (range(1, 5) as $n) {
            voteEngine()->reveal($classic, $n, force: true);
            expect(voteEngine()->advance($classic, $n, force: true))->toBe(Transition::Done);
        }
        expect(CategoryVoteRound::where('room_id', $classic->id)->count())->toBe(0);
    });
});

describe('casting a vote', function () {
    it('counts a vote once, broadcasts only the counts, and refuses a second vote', function () {
        $room = voteRoom(players: 3, questions: 10, every: 5);
        $round = playToVote($room);
        [$me] = playersOf($room);

        voteEngine()->castVote($me, $round->option_ids[0]);

        expect(fn () => voteEngine()->castVote($me, $round->option_ids[1]))->toThrow(fn (AnswerRejected $e) => expect($e->reason)->toBe(AnswerRejected::ALREADY_ANSWERED))
            ->and(CategoryVote::count())->toBe(1)
            ->and(CategoryVote::sole()->category_id)->toBe($round->option_ids[0])
            ->and($round->fresh()->isOpen())->toBeTrue();
        Event::assertDispatched(PlayerVoted::class, fn (PlayerVoted $event) => $event->voted === 1 && $event->expected === 3 && $event->counts[$round->option_ids[0]] === 1);
    });

    it('accepts a vote within the grace period and refuses one after it', function () {
        $room = voteRoom(players: 3, questions: 10, every: 5);
        $round = playToVote($room);
        [$a, $b] = playersOf($room);

        $this->travelTo($round->ends_at->addMilliseconds(400));
        voteEngine()->castVote($a, $round->option_ids[0]);

        $this->travelTo($round->ends_at->addMilliseconds(501));
        expect(fn () => voteEngine()->castVote($b, $round->option_ids[0]))->toThrow(fn (AnswerRejected $e) => expect($e->reason)->toBe(AnswerRejected::LATE))
            ->and(CategoryVote::count())->toBe(1);
    });

    it('refuses a category that was not offered', function () {
        $room = voteRoom(questions: 10, every: 5, pool: 5, perCategory: 6);
        $round = playToVote($room);
        $notOffered = collect(othersInPool($room))->diff($round->option_ids)->first();

        expect(fn () => voteEngine()->castVote(playersOf($room)[0], $notOffered))->toThrow(fn (AnswerRejected $e) => expect($e->reason)->toBe(AnswerRejected::INVALID_OPTION))
            ->and(fn () => voteEngine()->castVote(playersOf($room)[0], $room->first_category_id))->toThrow(AnswerRejected::class)
            ->and(CategoryVote::count())->toBe(0);
    });

    it('refuses a vote when no vote is open', function () {
        $room = voteRoom(questions: 10, every: 5);
        startGame($room);

        expect(fn () => voteEngine()->castVote(playersOf($room)[0], othersInPool($room)[0]))->toThrow(fn (AnswerRejected $e) => expect($e->reason)->toBe(AnswerRejected::CLOSED));

        playToVote($room);
        voteEngine()->closeVote($room, 1, force: true);

        expect(fn () => voteEngine()->castVote(playersOf($room)[0], othersInPool($room)[0]))->toThrow(fn (AnswerRejected $e) => expect($e->reason)->toBe(AnswerRejected::CLOSED))
            ->and(CategoryVote::count())->toBe(0);
    });

    it('does not wait for disconnected players, and lets a player who reconnects vote while there is time', function () {
        $room = voteRoom(players: 3, questions: 10, every: 5);
        $round = playToVote($room);
        [$a, $b, $c] = playersOf($room);
        $c->update(['left_at' => now()]);

        voteEngine()->castVote($a, $round->option_ids[0]);
        expect($round->fresh()->isOpen())->toBeTrue();

        $b->update(['left_at' => now()]);
        $b->update(['left_at' => null]); // back
        voteEngine()->castVote($b, $round->option_ids[1]);

        expect($round->fresh()->isOpen())->toBeFalse()
            ->and(votes()->counts($round->fresh()))->toBe([$round->option_ids[0] => 1, $round->option_ids[1] => 1]);
    });
});

describe('closing a vote', function () {
    it('closes as soon as every connected player has voted, draws the winner\'s batch and queues the next question after the result', function () {
        $room = voteRoom(players: 2, questions: 10, every: 5);
        $round = playToVote($room);
        [$a, $b] = playersOf($room);
        $winner = $round->option_ids[1];

        voteEngine()->castVote($a, $winner);
        voteEngine()->castVote($b, $winner);

        $closed = $round->fresh();
        expect($closed->isOpen())->toBeFalse()
            ->and($closed->winner_category_id)->toBe($winner)
            ->and($closed->outcome)->toBe(VoteOutcome::Votes)
            ->and($room->roomQuestions()->count())->toBe(10)
            ->and($room->roomQuestions()->where('position', '>', 5)->get()->map(fn ($q) => topCategoryOf($q))->unique()->all())->toBe([$winner]);
        Event::assertDispatchedTimes(VoteResult::class, 1);
        Queue::assertPushed(AdvanceQuestion::class, fn (AdvanceQuestion $job) => $job->position === 5 && $job->delay->getTimestamp() === now()->addSeconds(3)->getTimestamp());
    });

    it('closes at the deadline (the job tries again while it is early)', function () {
        $room = voteRoom(players: 2, questions: 10, every: 5);
        $round = playToVote($room);
        voteEngine()->castVote(playersOf($room)[0], $round->option_ids[0]);

        (new CloseVote($room->id, 1))->handle(voteEngine());
        expect($round->fresh()->isOpen())->toBeTrue()
            ->and(voteEngine()->closeVote($room, 1))->toBe(Transition::TooEarly);

        $this->travelTo($round->ends_at->addMilliseconds(500));
        expect(voteEngine()->closeVote($room, 1))->toBe(Transition::Done)
            ->and($round->fresh()->winner_category_id)->toBe($round->option_ids[0]);
    });

    it('opens the next question only after the result beat, from the winner', function () {
        $room = voteRoom(players: 1, questions: 10, every: 5);
        $round = playToVote($room);
        voteEngine()->castVote(playersOf($room)[0], $round->option_ids[0]);

        expect(voteEngine()->advance($room, 5))->toBe(Transition::TooEarly)
            ->and(voteEngine()->tick($room))->toBe(Transition::TooEarly);

        $this->travel(3)->seconds();
        (new AdvanceQuestion($room->id, 5))->handle(voteEngine());

        $current = $room->fresh()->currentQuestion();
        expect($current->position)->toBe(6)
            ->and($current->revealed_at)->toBeNull()
            ->and(topCategoryOf($current))->toBe($round->option_ids[0]);
    });

    it('breaks a tie at random among the tied categories only, and says so', function () {
        $room = voteRoom(players: 2, questions: 20, every: 1, pool: 3, perCategory: 12);
        startGame($room);
        [$a, $b] = playersOf($room);
        [$first, $tiedOne, $tiedTwo] = [$room->first_category_id, ...othersInPool($room)];

        foreach (range(1, 10) as $n) {
            $round = CategoryVoteRound::factory()->for($room)->create(['round' => $n, 'option_ids' => [$first, $tiedOne, $tiedTwo]]);
            CategoryVote::create(['room_id' => $room->id, 'round' => $n, 'room_player_id' => $a->id, 'category_id' => $tiedOne]);
            CategoryVote::create(['room_id' => $room->id, 'round' => $n, 'room_player_id' => $b->id, 'category_id' => $tiedTwo]);

            $closed = votes()->close($room->fresh(), $round);

            expect($closed->outcome)->toBe(VoteOutcome::Tie)->and($closed->winner_category_id)->toBeIn([$tiedOne, $tiedTwo]);
        }
    });

    it('picks at random among the offered categories when nobody voted, and says so', function () {
        $room = voteRoom(players: 2, questions: 10, every: 5, pool: 5, perCategory: 6);
        $round = playToVote($room);

        $this->travelTo($round->ends_at->addSeconds(1));
        voteEngine()->tick($room);

        $closed = $round->fresh();
        expect($closed->outcome)->toBe(VoteOutcome::NoVotes)
            ->and($closed->winner_category_id)->toBeIn($round->option_ids);
        Event::assertDispatched(VoteResult::class, fn (VoteResult $event) => $event->result['no_votes'] === true && $event->result['was_tie'] === false);
    });

    it('closes once whatever asks: the job twice, a double Skip vote, the job after the host skipped', function () {
        $room = voteRoom(players: 2, questions: 10, every: 5);
        $round = playToVote($room);
        voteEngine()->castVote(playersOf($room)[0], $round->option_ids[0]);

        expect(voteEngine()->closeVote($room, 1, force: true))->toBe(Transition::Done)
            ->and(voteEngine()->closeVote($room, 1, force: true))->toBe(Transition::Already);

        $this->travelTo($round->ends_at->addSeconds(1));
        (new CloseVote($room->id, 1))->handle(voteEngine());
        (new CloseVote($room->id, 1))->handle(voteEngine());

        expect($room->roomQuestions()->count())->toBe(10)
            ->and($round->fresh()->winner_category_id)->toBe($round->option_ids[0]);
        Event::assertDispatchedTimes(VoteResult::class, 1);
        Queue::assertPushed(AdvanceQuestion::class, 1 + 5); // one per question so far, plus the one after the vote
    });

    it('skips nothing when the advance job of the reveal and the host race the result beat', function () {
        $room = voteRoom(players: 1, questions: 10, every: 5);
        $round = playToVote($room);
        voteEngine()->closeVote($room, $round->round, force: true);

        $this->travel(3)->seconds();
        expect(voteEngine()->advance($room, 5))->toBe(Transition::Done)
            ->and(voteEngine()->advance($room, 5))->toBe(Transition::Stale)
            ->and(voteEngine()->advance($room, 5, force: true))->toBe(Transition::Stale)
            ->and($room->fresh()->currentQuestion()->position)->toBe(6)
            ->and($room->roomQuestions()->whereNotNull('started_at')->count())->toBe(6);
    });

    it('either counts or refuses a vote that meets the close, never half of it', function () {
        $room = voteRoom(players: 3, questions: 10, every: 5);
        $round = playToVote($room);
        [$a, $b] = playersOf($room);

        voteEngine()->castVote($a, $round->option_ids[0]);
        voteEngine()->closeVote($room, 1, force: true);
        expect(fn () => voteEngine()->castVote($b, $round->option_ids[0]))->toThrow(AnswerRejected::class);

        $closed = $round->fresh();
        expect(CategoryVote::count())->toBe(1)
            ->and(votes()->counts($closed))->toBe([$round->option_ids[0] => 1] + array_fill_keys(array_slice($round->option_ids, 1), 0))
            ->and($closed->winner_category_id)->toBe($round->option_ids[0]);
        Event::assertDispatched(VoteResult::class, fn (VoteResult $event) => collect($event->result['counts'])->sum('votes') === 1);
    });
});
