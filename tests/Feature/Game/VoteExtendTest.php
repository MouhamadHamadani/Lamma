<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\VoteExtended;
use App\Events\VoteResult;
use App\Game\AnswerRejected;
use App\Game\CategoryVote;
use App\Game\GameEngine;
use App\Game\GameView;
use App\Game\Transition;
use App\Jobs\CloseVote;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const EXTEND_TOKEN = 'x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0x0';

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

function extendEngine(): GameEngine
{
    return app(GameEngine::class);
}

/** @return array{0: Room, 1: CategoryVoteRound} a Vote game at its first vote; the first player is a guest phone under test */
function votingGame(array $settings = [], string $locale = 'en'): array
{
    $room = voteRoom(players: 2, questions: 10, every: 5, settings: $settings);
    $room->players()->orderBy('id')->first()->update(['guest_token' => EXTEND_TOKEN, 'locale' => $locale]);
    $round = playToVote($room);

    return [$room, $round];
}

function extendHost(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);
}

function extendPhone(Room $room): Testable
{
    Auth::guard('player')->forgetUser();

    return Livewire::withCookie('lamma_guest', EXTEND_TOKEN)->test(PlayerGame::class, ['room' => $room]);
}

/** The CloseVote jobs queued so far, oldest first. */
function closeVoteJobs(): array
{
    return Queue::pushed(CloseVote::class)->values()->all();
}

describe('how long a vote is', function () {
    it('stays open 15 seconds', function () {
        expect(config('lamma.vote_seconds'))->toBe(15);

        [, $round] = votingGame();

        expect($round->ends_at->getTimestampMs())->toBe(now()->addSeconds(15)->getTimestampMs());
    });
});

describe('the host\'s +10 s (GameEngine::extendVote)', function () {
    it('moves the deadline, marks the round, tells the screens and queues a CloseVote for the new deadline', function () {
        [$room, $round] = votingGame();
        $deadline = $round->ends_at;
        $this->travel(4)->seconds();

        expect(extendEngine()->extendVote($room, 1))->toBe(Transition::Done);

        $fresh = $round->fresh();
        expect($fresh->ends_at->getTimestampMs())->toBe($deadline->addSeconds(10)->getTimestampMs())
            ->and($fresh->extended_at)->not->toBeNull()
            ->and($fresh->isOpen())->toBeTrue();
        Event::assertDispatched(VoteExtended::class, fn (VoteExtended $event) => $event->broadcastWith()['round'] === 1 && $event->broadcastWith()['ends_at'] === '2026-10-01T12:00:25.000Z');

        $jobs = closeVoteJobs();
        expect($jobs)->toHaveCount(2)
            ->and($jobs[1]->deadlineMs)->toBe($fresh->ends_at->getTimestampMs())
            ->and($jobs[1]->delay->getTimestampMs())->toBe($fresh->ends_at->addMilliseconds(500)->getTimestampMs());
    });

    it('works once per round: a second press moves nothing and queues nothing', function () {
        [$room, $round] = votingGame();
        extendEngine()->extendVote($room, 1);
        $deadline = $round->fresh()->ends_at->getTimestampMs();

        expect(extendEngine()->extendVote($room, 1))->toBe(Transition::Already)
            ->and($round->fresh()->ends_at->getTimestampMs())->toBe($deadline)
            ->and(closeVoteJobs())->toHaveCount(2);
        Event::assertDispatchedTimes(VoteExtended::class, 1);
    });

    it('does nothing once the vote closed, for a round that is not there, or after the game', function () {
        [$room, $round] = votingGame();
        expect(extendEngine()->extendVote($room, 2))->toBe(Transition::Stale);

        extendEngine()->closeVote($room, 1, force: true);
        $deadline = $round->fresh()->ends_at->getTimestampMs();

        expect(extendEngine()->extendVote($room, 1))->toBe(Transition::Stale)
            ->and($round->fresh()->ends_at->getTimestampMs())->toBe($deadline)
            ->and($round->fresh()->extended_at)->toBeNull();

        $room->update(['status' => RoomStatus::Finished]);
        expect(extendEngine()->extendVote($room, 1))->toBe(Transition::Stale);
        Event::assertNotDispatched(VoteExtended::class);
    });

    it('makes the old job exit as stale, and the new one close the vote at the new deadline', function () {
        [$room, $round] = votingGame();
        extendEngine()->extendVote($room, 1);
        [$old, $new] = closeVoteJobs();

        // The old deadline passes: the old job goes away without closing and without trying again.
        $this->travelTo($old->delay);
        $old->withFakeQueueInteractions();
        $old->handle(extendEngine());
        $old->assertNotReleased();
        expect($round->fresh()->isOpen())->toBeTrue();

        // Its turn has not come either if it runs early: it tries again.
        $new->withFakeQueueInteractions();
        $new->handle(extendEngine());
        $new->assertReleased(1);
        expect($round->fresh()->isOpen())->toBeTrue();

        $this->travelTo($new->delay);
        $late = new CloseVote($room->id, 1, $new->deadlineMs);
        $late->handle(extendEngine());
        expect($round->fresh()->isOpen())->toBeFalse();
        Event::assertDispatchedTimes(VoteResult::class, 1);
    });

    it('keeps the host\'s tick and the votes on the new deadline', function () {
        [$room, $round] = votingGame();
        $player = $room->players()->orderBy('id')->first();
        $other = $room->players()->orderBy('id')->skip(1)->first();
        extendEngine()->extendVote($room, 1);

        // Past the old deadline and its grace: still open, and a vote still counts.
        $this->travel(20)->seconds();
        expect(extendEngine()->tick($room))->toBe(Transition::TooEarly)->and($round->fresh()->isOpen())->toBeTrue();
        extendEngine()->castVote($player, $round->option_ids[0]);

        $this->travel(6)->seconds();
        expect(fn () => extendEngine()->castVote($other, $round->option_ids[0]))->toThrow(AnswerRejected::class);

        expect(extendEngine()->tick($room))->toBe(Transition::Done)->and($round->fresh()->winner_category_id)->toBe($round->option_ids[0]);
    });

    it('still closes early when everyone has voted, and on Skip vote', function () {
        [$room, $round] = votingGame();
        extendEngine()->extendVote($room, 1);
        foreach ($room->players as $player) {
            extendEngine()->castVote($player, $round->option_ids[0]);
        }
        expect($round->fresh()->isOpen())->toBeFalse();

        [$room, $round] = votingGame();
        extendEngine()->extendVote($room, 1);
        expect(extendEngine()->closeVote($room, 1, force: true))->toBe(Transition::Done);
    });
});

describe('the screens', function () {
    it('offers +10 s next to Skip vote while the vote is open, once, on the host only', function () {
        [$room, $round] = votingGame();

        $page = extendHost($room)->assertSeeHtml('data-test="extend-vote"')->assertSeeHtml('<bdi dir="ltr">+10</bdi> s')->assertSeeHtml('aria-label="Add 10 seconds to the vote"');
        extendPhone($room)->assertDontSeeHtml('data-test="extend-vote"');

        $page->call('extendVote', 1);

        $page->assertDontSeeHtml('data-test="extend-vote"')->assertSeeHtml('data-test="skip-vote"');
        expect($page->html())->toMatch('/data-test="announcer">\s*Vote extended\s*</');
        expect($round->fresh()->extended_at)->not->toBeNull();
    });

    it('says it in Arabic on an Arabic big screen', function () {
        [$room] = votingGame(['hostScreenLocale' => HostScreenLocale::Ar]);

        extendHost($room)->assertSeeHtml('<bdi dir="ltr">+10</bdi> ث')->assertSeeHtml('أضف 10 ثوانٍ إلى التصويت');
    });

    it('is gone from the result', function () {
        [$room] = votingGame();
        extendEngine()->closeVote($room, 1, force: true);

        extendHost($room)->assertDontSeeHtml('data-test="extend-vote"');
    });

    it('is refused for anyone but the host', function () {
        [$room, $round] = votingGame();

        Livewire::actingAs(User::factory()->create())->test(HostGame::class, ['room' => $room])->call('extendVote', 1)->assertForbidden();

        expect($round->fresh()->extended_at)->toBeNull();
    });

    it('shrugs off a double click', function () {
        [$room] = votingGame();

        extendHost($room)->call('extendVote', 1)->call('extendVote', 1);

        Event::assertDispatchedTimes(VoteExtended::class, 1);
    });

    it('redraws the timers from the new deadline on both screens: a new key, the longer length', function () {
        [$room, $round] = votingGame();
        $before = extendHost($room)->html();
        expect($before)->toContain('wire:key="vote-timer-1-15"');

        extendEngine()->extendVote($room, 1);
        $end = $round->fresh()->ends_at->getTimestampMs();

        expect(extendHost($room)->html())->toContain('wire:key="vote-timer-1-25"')->toContain("lammaTimer({$end}, ".now()->getTimestampMs().', 25,');
        expect(extendPhone($room)->html())->toContain('wire:key="vote-timer-1-25"')->toContain("lammaTimer({$end}, ".now()->getTimestampMs().', 25,');
    });

    it('tells a phone that has not voted, and leaves a locked vote alone', function () {
        [$room, $round] = votingGame();
        extendEngine()->extendVote($room, 1);

        expect(extendPhone($room)->html())->toMatch('/data-test="announcer">\s*Vote extended\s*</');

        extendPhone($room)->call('vote', $round->option_ids[0]);
        expect(extendPhone($room)->html())->toMatch('/data-test="announcer">\s*Vote locked in\s*</');
    });

    it('listens for the event on both screens', function () {
        [$room] = votingGame();
        $channel = 'echo-presence:room.'.$room->code.',VoteExtended';

        foreach ([new HostGame, new PlayerGame] as $screen) {
            $screen->room = $room;

            expect($screen->getListeners())->toHaveKey($channel);
        }
    });

    it('draws a vote that was not extended at its plain length', function () {
        [$room] = votingGame();

        expect(app(GameView::class)->vote($room->fresh())->seconds())->toBe(CategoryVote::seconds());
    });
});
