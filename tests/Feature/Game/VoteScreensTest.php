<?php

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\Category;
use App\Models\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const VOTE_TOKEN = 'v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3v0t3';

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

/**
 * A Vote-mode game at its first vote, three players (the first one is the phone under test, a guest), named categories.
 *
 * @return array{0: Room, 1: CategoryVoteRound, 2: list<RoomPlayer>}
 */
function votingRoom(HostScreenLocale $mode = HostScreenLocale::Both, string $locale = 'en', int $players = 3): array
{
    $categories = [
        categoryWithQuestions(6, category: ['name' => ['en' => 'Opening Act', 'ar' => 'الافتتاح']]),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Planets', 'ar' => 'الكواكب']]),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Rivers', 'ar' => 'الأنهار']]),
    ];
    $room = voteRoom(players: $players, questions: 10, every: 5, categories: $categories, settings: ['hostScreenLocale' => $mode]);
    $room->players()->orderBy('id')->first()->update(['guest_token' => VOTE_TOKEN, 'locale' => $locale, 'nickname' => 'Phone']);
    $round = playToVote($room);

    return [$room, $round, $room->players()->orderBy('id')->get()->all()];
}

function voteHost(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);
}

function votePhone(Room $room): Testable
{
    Auth::guard('player')->forgetUser();

    return Livewire::withCookie('lamma_guest', VOTE_TOKEN)->test(PlayerGame::class, ['room' => $room]);
}

function optionName(CategoryVoteRound $round, int $index, string $lang = 'en'): string
{
    return Category::find($round->option_ids[$index])->getTranslation('name', $lang);
}

describe('the host vote screen', function () {
    it('shows the title in both languages, the timer, the offered tiles with their counts, who has voted and Skip vote', function () {
        [$room, $round] = votingRoom();

        voteHost($room)
            ->assertSeeHtml('data-vote="open"')
            ->assertSee('Choose the next category')
            ->assertSeeHtml('data-test="vote-title-alt"')
            ->assertSee('اختر الفئة التالية')
            ->assertSeeHtml('role="timer"')
            ->assertSee(optionName($round, 0))
            ->assertSee(optionName($round, 0, 'ar'))
            ->assertSee(optionName($round, 1))
            ->assertSeeHtml('aria-label="Category A: '.optionName($round, 0).'"')
            ->assertSeeHtml('data-test="vote-count">0<')
            ->assertSee('0 of 3 voted')
            ->assertSeeHtml('data-test="skip-vote"')
            ->assertSee('Next category')
            ->assertSeeHtml('data-vote-marker')
            ->assertSee('Vote started');
    });

    it('shows one language when the room is set to one', function () {
        [$room] = votingRoom(HostScreenLocale::Ar);

        voteHost($room)->assertSeeHtml('lang="ar"')->assertSee('اختر الفئة التالية')->assertDontSeeHtml('data-test="vote-title-alt"')->assertDontSee('Choose the next category');
    });

    it('updates the counts and the voted avatars as phones vote', function () {
        [$room, $round, $players] = votingRoom();
        app(GameEngine::class)->castVote($players[1], $round->option_ids[1]);

        voteHost($room)->assertSeeHtml('data-test="vote-count">1<')->assertSee('1 of 3 voted');
    });

    it('lets the host skip the vote, once, with the votes so far', function () {
        [$room, $round, $players] = votingRoom();
        app(GameEngine::class)->castVote($players[1], $round->option_ids[1]);

        voteHost($room)->call('skipVote', 1)->call('skipVote', 1)->assertSeeHtml('data-vote="result"');

        expect($round->fresh()->winner_category_id)->toBe($round->option_ids[1]);
    });

    it('refuses Skip vote to anyone but the host', function () {
        [$room] = votingRoom();

        Livewire::actingAs(User::factory()->create())->test(HostGame::class, ['room' => $room])->call('skipVote', 1)->assertForbidden();
        expect(votes()->openRound($room))->not->toBeNull();
    });

    it('shows the winner with its badge, the others faded, and who voted for each', function () {
        [$room, $round, $players] = votingRoom(players: 2);
        app(GameEngine::class)->castVote($players[0], $round->option_ids[0]);
        app(GameEngine::class)->castVote($players[1], $round->option_ids[0]);

        $html = voteHost($room)->assertSeeHtml('data-vote="result"')->assertSee('The next category is')->assertSee('Winner')->html();

        expect($html)->toContain('data-winner="true"')
            ->and(substr_count($html, 'data-winner="false"'))->toBe(1)
            ->and($html)->toContain('opacity-35')
            ->and($html)->toContain('data-voter="'.$players[0]->id.'"')
            ->and($html)->toContain('data-voter="'.$players[1]->id.'"')
            ->and($html)->toContain(e(optionName($round, 0)).' won');
    });

    it('says a tie or nobody voting is a random pick, in both languages', function () {
        [$room, $round, $players] = votingRoom(players: 2);
        app(GameEngine::class)->castVote($players[0], $round->option_ids[0]);
        app(GameEngine::class)->castVote($players[1], $round->option_ids[1]);

        voteHost($room)->assertSee('Tie, picking randomly')->assertSee('تعادل، نختار عشوائيًا')->assertSeeHtml('lammaVoteLanding(true');

        [$quiet] = votingRoom();
        app(GameEngine::class)->closeVote($quiet, 1, force: true);

        voteHost($quiet)->assertSee('Nobody voted, picking randomly')->assertSee('لم يصوّت أحد، نختار عشوائيًا');
    });

    it('is right-to-left as a whole page in Arabic, left-to-right in English', function () {
        [$room] = votingRoom(HostScreenLocale::Ar);
        $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertOk()->assertSee('dir="rtl"', false)->assertSee('اختر الفئة التالية');

        [$english] = votingRoom(HostScreenLocale::En);
        $this->actingAs($english->host)->get(route('host.lobby', $english->code))->assertOk()->assertSee('dir="ltr"', false)->assertSee('Choose the next category');
    });

    it('rebuilds the vote on a reload: the same tiles in the same order, the counts and the time left', function () {
        [$room, $round, $players] = votingRoom();
        app(GameEngine::class)->castVote($players[2], $round->option_ids[0]);
        $this->travel(4)->seconds();

        $html = voteHost($room)->html();

        expect(strpos($html, optionName($round, 0)))->toBeLessThan(strpos($html, optionName($round, 1)))
            ->and($html)->toContain('data-test="vote-count">1<')
            ->and($html)->toContain('lammaTimer('.$round->ends_at->getTimestampMs().', '.now()->getTimestampMs());
    });
});

describe('the phone vote screen', function () {
    it('shows the timer and the offered categories in the player\'s language, and no counts', function () {
        [$room, $round] = votingRoom(locale: 'en');

        votePhone($room)
            ->assertSeeHtml('data-vote="vote"')
            ->assertSee('Choose the next category')
            ->assertSee(optionName($round, 0))
            ->assertSee(optionName($round, 1))
            ->assertDontSee(optionName($round, 0, 'ar'))
            ->assertSeeHtml('role="timer"')
            ->assertSeeHtml('wire:click="vote('.$round->option_ids[0].')"')
            ->assertDontSeeHtml('data-test="vote-count"')
            ->assertSee('Vote started');
    });

    it('locks the vote at one tap and shows it big, with how many have voted', function () {
        [$room, $round] = votingRoom();

        votePhone($room)->call('vote', $round->option_ids[1])
            ->assertSeeHtml('data-vote="voted"')
            ->assertSee('Vote locked in')
            ->assertSeeHtml('data-test="locked-vote"')
            ->assertSee(optionName($round, 1))
            ->assertSee('1 of 3 voted');
        votePhone($room)->call('vote', $round->option_ids[0]);

        expect(CategoryVote::sole()->category_id)->toBe($round->option_ids[1]);
    });

    it('never draws what anyone else voted for', function () {
        [$room, $round, $players] = votingRoom();
        $players[1]->update(['nickname' => 'Zebedee']);
        app(GameEngine::class)->castVote($players[1], $round->option_ids[1]);

        votePhone($room)->assertDontSee('Zebedee')->assertDontSeeHtml('data-test="vote-count"')->assertSee('Choose the next category');
    });

    it('shows the winner and "Your pick won!" when the player\'s pick won', function () {
        [$room, $round, $players] = votingRoom(players: 2);
        votePhone($room)->call('vote', $round->option_ids[0]);
        app(GameEngine::class)->castVote($players[1], $round->option_ids[0]);

        votePhone($room)->assertSeeHtml('data-test="vote-result"')->assertSee('Your pick won!')->assertSee(optionName($round, 0))
            ->assertSee(optionName($round, 0).' won')->assertDontSee("You didn't vote");
    });

    it('says "You didn\'t vote" and shows the result to a player who did not vote', function () {
        [$room, $round, $players] = votingRoom(players: 2);
        app(GameEngine::class)->castVote($players[1], $round->option_ids[1]);
        app(GameEngine::class)->closeVote($room, 1, force: true);

        votePhone($room)->assertSee("You didn't vote")->assertSee(optionName($round, 1))->assertDontSee('Your pick won!');
    });

    it('is in Arabic, right to left, for an Arabic player', function () {
        [$room, $round] = votingRoom(locale: 'ar');

        votePhone($room)->assertSee('اختر الفئة التالية')->assertSee(optionName($round, 0, 'ar'))->assertDontSee(optionName($round, 0));

        Auth::guard('player')->forgetUser();
        $this->withCookie('lamma_guest', VOTE_TOKEN)->withCredentials()->get(route('play', $room->code))
            ->assertOk()->assertSee('dir="rtl"', false)->assertSee('اختر الفئة التالية');
    });

    it('lands back on the vote after a reload, with its own vote and the time left, and on the result once it closed', function () {
        [$room, $round] = votingRoom();
        votePhone($room)->call('vote', $round->option_ids[0]);
        $this->travel(3)->seconds();

        votePhone($room)->assertSeeHtml('data-vote="voted"')->assertSeeHtml('lammaTimer('.$round->ends_at->getTimestampMs().', '.now()->getTimestampMs());

        $this->travelTo($round->ends_at->addSeconds(1));
        app(GameEngine::class)->tick($room);

        votePhone($room)->assertSeeHtml('data-test="vote-result"');
    });

    it('lets a player who reconnects during the vote still vote while there is time', function () {
        [$room, $round, $players] = votingRoom();
        $players[0]->update(['left_at' => now()]);
        $this->travel(2)->seconds();
        $players[0]->update(['left_at' => null]);

        votePhone($room)->assertSeeHtml('data-vote="vote"')->call('vote', $round->option_ids[1])->assertSeeHtml('data-vote="voted"');
    });
});
