<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\RoomClosed;
use App\Events\RoomRestarted;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Models\Question;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, GameFinished::class, RoomRestarted::class, RoomClosed::class]);
    Queue::fake();
});

function results(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);
}

/** The ranks of the podium steps, left to right, and the names on each. */
function podium(Testable $page): array
{
    preg_match_all('/data-rank="(\d)" data-test="podium-step">(.*?)<div\s+class="bg-/s', $page->html(), $steps, PREG_SET_ORDER);

    return collect($steps)->mapWithKeys(fn (array $step) => [(int) $step[1] => trim(preg_replace('/\s+/', ' ', strip_tags($step[2])))])->all();
}

describe('the winner', function () {
    it('says who won in English with the Arabic line under it when the room is set to Both', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500], settings: ['hostScreenLocale' => HostScreenLocale::Both]);

        $page = results($room);

        $page->assertSeeHtml('<bdi dir="ltr">Ali</bdi> wins!')->assertSeeHtml('lang="ar" dir="rtl"')->assertSeeHtml('الفوز من نصيب <bdi dir="ltr">Ali</bdi>!')
            ->assertSee('Game over · 3 questions')->assertSee('Great game, everyone. Same players, another round?');
    });

    it('shows one language only when the room is set to English or Arabic', function () {
        $english = results(finishedGame(['Ali' => 800, 'Sara' => 700], settings: ['hostScreenLocale' => HostScreenLocale::En]));
        $english->assertSeeHtml(' wins!')->assertDontSeeHtml('data-test="headline-ar"')->assertDontSee('الفوز');

        $arabic = results(finishedGame(['Ali' => 800, 'Sara' => 700], settings: ['hostScreenLocale' => HostScreenLocale::Ar]));
        $arabic->assertSeeHtml('الفوز من نصيب <bdi dir="ltr">Ali</bdi>!')->assertDontSee('wins!')->assertSee('العب مجددًا');
    });

    it('names two players who tie for first, and calls three or more a tie', function () {
        $two = results(finishedGame(['Ali' => 800, 'Sara' => 800, 'Maya' => 500]));
        $two->assertSeeHtml('<bdi dir="ltr">Ali</bdi> &amp; <bdi dir="ltr">Sara</bdi> win!');

        $three = results(finishedGame(['Ali' => 800, 'Sara' => 800, 'Maya' => 800, 'Omar' => 100]));
        $three->assertSeeHtml("It's a tie!")->assertSeeHtml('data-test="tied-names"')->assertSeeHtml('<bdi dir="ltr">Maya</bdi>');
    });

    it('has no winner when nobody scored, and no podium or confetti', function () {
        $page = results(finishedGame(['Ali' => 0, 'Sara' => 0]));

        $page->assertSee('No winner this time')->assertSee('Nobody scored this time.')->assertDontSeeHtml('data-test="podium-step"');
        expect($page->html())->not->toContain('pointer-events-none absolute inset-0')->and(podium($page))->toBe([]);
    });

    it('keeps names left to right, and escapes them', function () {
        $page = results(finishedGame(['<b>Ali</b>' => 800, 'Sara' => 700]));

        $page->assertDontSeeHtml('<b>Ali</b>')->assertSeeHtml('&lt;b&gt;Ali&lt;/b&gt;');
    });
});

describe('the podium', function () {
    it('stands second on the left, first in the middle and third on the right, with names and points', function () {
        $page = results(finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]));

        $steps = podium($page);

        expect(array_keys($steps))->toBe([2, 1, 3])
            ->and($steps[1])->toContain('Ali')->toContain('800 pts')
            ->and($steps[2])->toContain('Sara')->toContain('700 pts')
            ->and($steps[3])->toContain('Maya')->toContain('500 pts');
    });

    it('colours the steps teal, sun and coral and crowns only the first', function () {
        $page = results(finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]));

        $page->assertSeeHtml('bg-sun h-[clamp(130px,30dvh,270px)]')->assertSeeHtml('bg-teal h-[clamp(100px,21dvh,190px)]')->assertSeeHtml('bg-coral h-[clamp(80px,15.5dvh,140px)]');
        expect(substr_count($page->html(), 'data-test="crown"'))->toBe(1);
    });

    it('grows the bars third, second, first with the crown last, and fades instead under reduced motion', function () {
        $html = results(finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]))->html();

        expect($html)->toContain('animation-delay: 0ms')->toContain('animation-delay: 750ms')->toContain('animation-delay: 1500ms')->toContain('animation-delay: 2100ms')
            ->toContain('motion-safe:animate-bar-grow')->toContain('motion-reduce:animate-fade-in');
    });

    it('shares a step between players who tie, and the next player is third', function () {
        $steps = podium(results(finishedGame(['Ali' => 800, 'Sara' => 800, 'Maya' => 500, 'Omar' => 100])));

        expect(array_keys($steps))->toBe([1, 3])
            ->and($steps[1])->toContain('Ali')->toContain('Sara')->and($steps[3])->toContain('Maya')->not->toContain('Omar');
    });

    it('shares the second step too', function () {
        $steps = podium(results(finishedGame(['Ali' => 800, 'Sara' => 500, 'Maya' => 500, 'Omar' => 100])));

        expect(array_keys($steps))->toBe([2, 1])
            ->and($steps[2])->toContain('Sara')->toContain('Maya');
    });

    it('has fewer steps with fewer than three players', function () {
        expect(array_keys(podium(results(finishedGame(['Ali' => 300])))))->toBe([1]);
        expect(array_keys(podium(results(finishedGame(['Ali' => 300, 'Sara' => 100])))))->toBe([2, 1]);
    });

    it('turns four tied third-placers into a count', function () {
        $page = results(finishedGame(['Ali' => 900, 'Sara' => 800, 'A1' => 100, 'A2' => 100, 'A3' => 100, 'A4' => 100]));

        $page->assertSee('+1');
    });
});

describe('Play again and New game', function () {
    it('makes a new room with the same settings and sends the host to its lobby', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700], settings: ['secondsPerQuestion' => 10]);

        results($room)->call('playAgain')->assertRedirect(route('host.lobby', Room::latest('id')->first()->code));

        $new = Room::latest('id')->first();
        expect($new->id)->not->toBe($room->id)->and($new->status)->toBe(RoomStatus::Lobby)->and($new->settings->secondsPerQuestion)->toBe(10)->and($room->fresh()->next_room_id)->toBe($new->id);
    });

    it('copies the players who are still connected, with scores 0 and not ready', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]);
        $room->players()->where('nickname', 'Maya')->update(['left_at' => now()]);

        results($room)->call('playAgain');

        $new = Room::latest('id')->first();
        expect($new->players()->orderBy('id')->pluck('nickname')->all())->toBe(['Ali', 'Sara'])
            ->and($new->players()->pluck('score')->unique()->all())->toBe([0])
            ->and($new->players()->pluck('is_ready')->unique()->all())->toBe([false]);
    });

    it('tells every phone where to go with RoomRestarted on the old room', function () {
        $room = finishedGame(['Ali' => 800]);

        results($room)->call('playAgain');

        $new = Room::latest('id')->first();
        Event::assertDispatched(RoomRestarted::class, fn (RoomRestarted $e) => $e->roomCode === $room->code && $e->broadcastWith()['new_code'] === $new->code);
    });

    it('makes one room however many times it is clicked', function () {
        $room = finishedGame(['Ali' => 800]);
        $before = Room::count();

        results($room)->call('playAgain')->call('playAgain');

        expect(Room::count())->toBe($before + 1);
        Event::assertDispatchedTimes(RoomRestarted::class, 1);
    });

    it('says so when there are no longer enough questions, and makes nothing', function () {
        $room = finishedGame(['Ali' => 800]);
        Question::query()->update(['is_active' => false]);
        $before = Room::count();

        results($room)->call('playAgain')->assertSee('playable questions are left for a game of 3')->assertSeeHtml('data-test="play-again-error"')->assertNoRedirect();

        expect(Room::count())->toBe($before)->and($room->fresh()->next_room_id)->toBeNull();
        Event::assertNotDispatched(RoomRestarted::class);
    });

    it('is the host\'s alone', function () {
        $room = finishedGame(['Ali' => 800]);
        $before = Room::count();

        Livewire::actingAs(User::factory()->create())->test(HostResults::class, ['room' => $room])->call('playAgain')->assertForbidden();

        expect(Room::count())->toBe($before);
    });

    it('links New game to the create-room screen', function () {
        results(finishedGame(['Ali' => 800]))->assertSeeHtml('href="'.route('rooms.create').'"')->assertSeeHtml('data-test="new-game"');
    });
});

describe('inside the lobby page', function () {
    it('shows the results when the game ends, and again after a refresh', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->assertSeeHtml('data-test="host-results"')->assertSee('wins!')->assertDontSeeHtml('data-test="start-button"');
        $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertOk()->assertSee('data-test="host-results"', false);
    });

    it('sends the host to create a room when the room was closed before the game ran', function () {
        $room = Room::factory()->finished()->create();

        $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertRedirect(route('rooms.create'));
    });
});
