<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The host's sounds and motion: the lobby (a row pops in with a sound, the tiles bounce, the Start button wobbles once), the 3 · 2 · 1 · Go! countdown,
 * the question (answer ticks, the ring), the reveal (ring pulse, confetti, rank-up) and the podium. Everything here is display: the rules are
 * in the game tests.
 */

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

function motionGame(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);
}

/** A game whose "Start game" was just pressed: the countdown is running, question 1 has not opened. */
function countingDown(array $settings = []): Room
{
    $room = gameRoom(players: 2, questions: 3, settings: ['hostScreenLocale' => HostScreenLocale::En, ...$settings]);
    app(GameEngine::class)->start($room, $room->host);

    return $room->fresh();
}

describe('the lobby', function () {
    it('pops a new player in with the join sound', function () {
        $room = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);

        $page->dispatch("echo-presence:room.{$room->code},PlayerJoined", ['player' => ['nickname' => 'Sara']])->assertDispatched('lamma-sound', name: 'join');
        expect(file_get_contents(resource_path('views/components/lamma/player-row.blade.php')))->toContain('motion-safe:animate-spring-in')->toContain('motion-reduce:animate-fade-in');
    });

    it('drops the room code tiles in one after the other, once, and only fades them under reduced motion', function () {
        $html = Blade::render('<x-lamma.room-code code="K7MP" />');

        expect(substr_count($html, 'motion-safe:animate-tile-bounce'))->toBe(4)->and(substr_count($html, 'motion-reduce:animate-fade-in'))->toBe(4)
            ->and($html)->toContain('animation-delay: 0ms')->toContain('animation-delay: 90ms')->toContain('animation-delay: 180ms')->toContain('animation-delay: 270ms');
    });

    it('chimes and wobbles the Start button once when the last player taps Ready', function () {
        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $waiting = $room->players()->orderBy('id')->first();
        $waiting->update(['is_ready' => false]);
        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
        $page->assertNotDispatched('lamma-wobble');

        $waiting->update(['is_ready' => true]);
        $page->dispatch("echo-presence:room.{$room->code},PlayerReadyChanged")->assertDispatched('lamma-sound', name: 'ready-on')->assertDispatched('lamma-wobble');

        // Not again on the next refresh...
        $page->call('$refresh')->assertNotDispatched('lamma-wobble')->assertNotDispatched('lamma-sound');

        // ...but again after someone un-readies and the room becomes ready once more.
        $waiting->update(['is_ready' => false]);
        $page->dispatch("echo-presence:room.{$room->code},PlayerReadyChanged")->assertNotDispatched('lamma-wobble');
        $waiting->update(['is_ready' => true]);
        $page->dispatch("echo-presence:room.{$room->code},PlayerReadyChanged")->assertDispatched('lamma-wobble');
    });

    it('stays quiet when the host opens a lobby that is already ready, and the button listens for the wobble', function () {
        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);

        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);

        $page->assertNotDispatched('lamma-wobble')->assertNotDispatched('lamma-sound')->assertSeeHtml('x-on:lamma-wobble.window="lammaFx.wobble($el)"');
    });
});

describe('the countdown', function () {
    it('fills the screen with 3 as soon as the game starts, and says nothing about the question', function () {
        $room = countingDown();

        $page = motionGame($room)->assertSeeHtml('data-test="countdown"')->assertSeeHtml('data-n="3"')->assertSeeHtml('lammaCountdown(')->assertSee('Get ready…');

        expect($page->html())->not->toContain('data-test="question-text"')->not->toContain('data-test="answers"');
    });

    it('counts from the server\'s deadline: 3, then 2, 1 and Go! as the seconds pass', function () {
        $room = countingDown();

        foreach ([[0, '3'], [1000, '2'], [2000, '1'], [2999, '1'], [3000, '0'], [4500, '0']] as [$elapsed, $n]) {
            $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000')->addMilliseconds($elapsed));

            expect(motionGame($room)->html())->toContain('data-n="'.$n.'"');
        }
    });

    it('shows the number it would on a screen that opens half way through', function () {
        $room = countingDown();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:01.500'));

        motionGame($room)->assertSeeHtml('data-n="2"');
    });

    it('asks the server to open question 1 the moment it ends, and not before', function () {
        $room = countingDown();
        $page = motionGame($room);

        expect($page->html())->toContain('x-init="setTimeout(() => $wire.tick(), 3150)"');

        $page->call('tick');
        expect($room->fresh()->currentQuestion())->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.100'));
        $page->call('tick')->assertSee('Question 1 of 3')->assertDontSeeHtml('data-test="countdown"');
        expect($room->fresh()->currentQuestion()->position)->toBe(1);
    });

    it('is gone once the question is open, and never shown in the lobby', function () {
        $lobby = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        Livewire::actingAs($lobby->host)->test(HostLobby::class, ['room' => $lobby])->assertDontSeeHtml('data-test="countdown"');

        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);
        motionGame($room)->assertDontSeeHtml('data-test="countdown"')->assertSeeHtml('data-test="question-text"');
    });

    it('fades the tile only under reduced motion and counts in Arabic words on an Arabic screen', function () {
        expect(file_get_contents(resource_path('js/fx.js')))->toContain('reduced() ? [{ opacity: 0.5 }, { opacity: 1 }]');
        app()->setLocale('ar');

        expect(Blade::render('<x-lamma.countdown :ends-at="now()->addSeconds(3)" />'))->toContain('aria-hidden="true"')->toContain('data-n="3"');
    });
});

describe('the question', function () {
    it('ticks, a little higher with each answer', function () {
        $room = gameRoom(players: 3, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);
        $page = motionGame($room);

        $page->dispatch("echo-presence:room.{$room->code},PlayerAnswered", ['answered' => 1])->assertDispatched('lamma-sound', name: 'tap', rate: 1);
        $page->dispatch("echo-presence:room.{$room->code},PlayerAnswered", ['answered' => 3])->assertDispatched('lamma-sound', name: 'tap', rate: 1.12);
        $page->dispatch("echo-presence:room.{$room->code},PlayerAnswered", ['answered' => 40])->assertDispatched('lamma-sound', name: 'tap', rate: 1.6);
    });

    it('gives the answered avatar its check badge with a pop, and trembles the ring in the last three seconds', function () {
        [$room] = [gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En])];
        $question = startGame($room);
        answer($room->players()->orderBy('id')->first(), $question);

        $html = motionGame($room)->html();

        expect($html)->toContain('motion-safe:animate-chip-pop')->toContain("motion-safe:animate-ring-shake': secs <= 3 && secs > 0");
    });
});

describe('the reveal', function () {
    it('pulses a ring out of the correct tile and bursts confetti from it, and from no other', function () {
        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);
        $page = motionGame($room)->call('skipTimer', 1);

        expect(substr_count($page->html(), 'motion-safe:animate-ring-pulse'))->toBe(1)->and(substr_count($page->html(), 'lammaConfetti.burst($el, 18)'))->toBe(1);
        $page->assertSeeHtml("\$store.sound.play('reveal')");
    });

    it('draws no ring and no confetti on the question itself', function () {
        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);

        expect(motionGame($room)->html())->not->toContain('animate-ring-pulse')->not->toContain('lammaConfetti');
    });

    it('shows an up arrow and plays the rank-up sound once when a row climbs', function () {
        $room = gameRoom(players: 3, questions: 3, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $players = $room->players()->orderBy('id')->get();
        $first = startGame($room);
        answer($players[0], $first, true);
        answer($players[1], $first, false);
        answer($players[2], $first, false);
        app(GameEngine::class)->advance($room, 1, force: true);
        $second = $room->fresh()->currentQuestion();
        answer($players[0], $second, false);
        answer($players[1], $second, false);
        answer($players[2], $second, true);

        $html = motionGame($room)->html();

        // Player 3 was level second, now level first; player 2 fell.
        expect(substr_count($html, 'data-test="rank-up"'))->toBe(1)->and($html)->toContain('Moved up')->and(substr_count($html, "play('rank-up')"))->toBe(1);
    });

    it('is quiet when nobody climbed', function () {
        $room = gameRoom(players: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $question = startGame($room);
        foreach ($room->players as $player) {
            answer($player, $question, true);
        }

        expect(motionGame($room)->html())->not->toContain('data-test="rank-up"')->not->toContain("play('rank-up')");
    });

    it('counts up the top scorer\'s points with ticks and nobody else\'s', function () {
        $room = gameRoom(players: 3, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $question = startGame($room);
        answer($room->players()->orderBy('id')->skip(1)->first(), $question, true);
        answer($room->players()->orderBy('id')->skip(2)->first(), $question, true);

        $html = motionGame($room)->call('skipTimer', 1)->html();

        expect(substr_count($html, 'lammaCountUp(200, 400, true)'))->toBe(1)->and(substr_count($html, 'lammaCountUp(200, 400, false)'))->toBe(1);
    });
});

describe('the podium', function () {
    it('thuds as each bar lands, 3rd, 2nd then 1st, then plays the fanfare and rains confetti for six seconds', function () {
        $room = finishedGame(['Ali' => 300, 'Sara' => 200, 'Maya' => 100]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect($html)->toContain("setTimeout(() => { \$store.sound.play('podium-bar', { rate: 1.25 }) }, 520)")
            ->toContain("setTimeout(() => { \$store.sound.play('podium-bar', { rate: 1 }) }, 1270)")
            ->toContain("setTimeout(() => { \$store.sound.play('podium-bar', { rate: 0.8 }) }, 2020)")
            ->toContain("setTimeout(() => { \$store.sound.play('win'); lammaConfetti.rain(6000) }, 2800)");
        // the winner's name scales in once the bars are up
        expect($html)->toContain('motion-safe:animate-name-in')->toContain('motion-safe:[animation-delay:2800ms]')->toContain('motion-reduce:animate-fade-in');
    });

    it('does not cheer when nobody scored: the headline shows at once and nothing plays', function () {
        $room = finishedGame(['Ali' => 0, 'Sara' => 0]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect($html)->not->toContain('podium-bar')->not->toContain("play('win')")->not->toContain('lammaConfetti')->not->toContain('animation-delay:2800ms');
    });

    it('has no bar thud for a step nobody stands on', function () {
        $room = finishedGame(['Ali' => 300]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect(substr_count($html, "play('podium-bar'"))->toBe(1)->and($html)->toContain("play('win')");
    });
});
