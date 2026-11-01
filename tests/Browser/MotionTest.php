<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\GameEngine;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Queue;
use Tests\Browser\Support\ForgetGuards;

/*
 * Sound and motion in real browsers (Chromium). Every host and phone screen at 390x844, 1280x720 and 1920x1080: no JavaScript error, no console
 * output, nothing axe finds, no scrolling on the host; the same screens with prefers-reduced-motion: no running transform animation and no confetti
 * canvas; and a whole game with the sound store recording what is asked of it, to see which sounds play in which order and that nothing plays while muted.
 * Reverb is not running (the screens follow the game by polling, as in FullGameTest); the states are built in the database and opened one after another.
 */

beforeEach(fn () => Queue::fake());

dataset('motion sizes', [
    '390x844' => [390, 844],
    '1280x720' => [1280, 720],
    '1920x1080' => [1920, 1080],
]);

/** Poll until the JavaScript expression is truthy (the other pages keep being served while this waits). */
function motionWait($page, string $expression, int $seconds = 25, string $what = ''): void
{
    for ($i = 0; $i < $seconds * 3; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.33);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

/** axe (critical and serious), able to leave out what is deliberately dimmed: on the reveal the wrong answers fade to 35% (HANDOFF section 7). */
function motionAxe($page, array $exclude = []): void
{
    $skip = json_encode(array_map(fn (string $selector) => [$selector], $exclude));
    $violations = $page->script("async () => (await window.axe.run({ exclude: {$skip} })).violations
        .filter((violation) => ['critical', 'serious'].includes(violation.impact))
        .map((violation) => violation.id + ': ' + violation.nodes.map((node) => node.target.join(' ')).join(' | '))");

    expect($violations)->toBe([]);
}

/**
 * A screen that is fine: nothing in the console, nothing for axe. $scroll says what must not scroll: 'none' (a host screen on a laptop or TV: nothing at all),
 * 'sideways' (a phone: never sideways) or 'free' (a host screen in a phone-sized window, which is not what it is designed for).
 */
function motionClean($page, string $scroll, array $exclude = [], float $settle = 1.0): void
{
    $page->wait($settle);                                     // an opacity animation reads as low contrast to axe until it has finished
    $page->assertNoSmoke();
    motionAxe($page, $exclude);
    if ($scroll !== 'free') {
        $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
    }
    if ($scroll === 'none') {
        $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')->assertScript('document.body.scrollHeight <= window.innerHeight + 1');
    }
}

/** The 3 - 2 - 1 tile is decorative (aria-hidden) and its number fades in again every second, so axe would catch it half way; its colours are checked by AccessibilityTest. */
const COUNTDOWN = '[data-test=countdown]';

/** Running animations that move something (transform, translate, scale, rotate): there must be none under reduced motion. */
function motionMoving($page): array
{
    return $page->script("() => document.getAnimations()
        .filter((a) => a.playState === 'running' && (a.effect?.getKeyframes?.() ?? []).some((k) => ['transform', 'translate', 'scale', 'rotate'].some((p) => p in k)))
        .map((a) => (a.animationName ?? a.constructor.name) + ' on ' + (a.effect?.target?.tagName ?? '?') + '.' + (a.effect?.target?.className?.toString().slice(0, 60) ?? ''))");
}

/** A host with a room of $players players of different shapes and lengths of name, in both scripts. */
function motionRoom(int $players = 8, int $questions = 3, int $seconds = 30, HostScreenLocale $screen = HostScreenLocale::En): array
{
    $host = User::factory()->create(['preferred_locale' => $screen === HostScreenLocale::Ar ? 'ar' : 'en']);
    $room = gameRoom(players: $players, questions: $questions, settings: ['secondsPerQuestion' => $seconds, 'hostScreenLocale' => $screen], host: $host, category: categoryWithQuestions(20));
    $names = ['Sara', 'علي', 'Maximiliano', 'مريم الزهراء', 'Jo', 'عبد الرحمن', 'Alexandria', 'ليلى'];
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $player->update(['nickname' => $names[$i % count($names)].($i >= count($names) ? $i : ''), 'locale' => 'en']);
    }

    return [$host, $room->fresh()];
}

/** The countdown of a started game, stretched so the screen holds still while it is looked at. */
function motionCountdown(Room $room, int $seconds = 12): void
{
    app(GameEngine::class)->start($room, $room->host);
    $room->fresh()->update(['countdown_ends_at' => now()->addSeconds($seconds)]);
}

describe('every host screen', function () {
    it('is free of errors, console output and accessibility problems, and does not scroll', function (int $width, int $height) {
        [$host, $room] = motionRoom();
        $this->actingAs($host);
        $engine = app(GameEngine::class);
        $scroll = $width >= 1280 ? 'none' : 'free';                  // the host screens are for a laptop or a TV: a phone-sized window need only be free of errors

        $page = visit("/host/{$room->code}")->resize($width, $height)->assertSeeIn('[data-test=ready-count]', 'of 8 ready');
        motionClean($page, $scroll);

        motionCountdown($room);
        $page->navigate("/host/{$room->code}")->assertScript("document.querySelector('[data-test=countdown]') !== null");
        motionClean($page, $scroll, exclude: [COUNTDOWN]);

        $engine->startQuestion($room->fresh(), force: true);
        $question = $room->fresh()->currentQuestion();
        foreach ($room->players()->orderBy('id')->take(3)->get() as $player) {
            answer($player, $question, true);
        }
        $page->navigate("/host/{$room->code}")->assertSeeIn('[data-test=host-game]', '3 of 8 answered');
        motionClean($page, $scroll);

        foreach ($room->players()->orderBy('id')->get()->slice(3)->values() as $i => $player) {
            answer($player, $question, $i % 2 === 0);
        }
        $page->navigate("/host/{$room->code}")->assertScript("document.querySelector('[data-test=correct-count]') !== null");
        motionClean($page, $scroll, exclude: ['.opacity-35'], settle: 1.5);

        $done = finishedGame(['Sara' => 500, 'علي' => 400, 'Maximiliano' => 400, 'مريم' => 300, 'Jo' => 100], host: $host);
        $page->navigate("/host/{$done->code}")->assertSeeIn('[data-test=host-results]', 'Play again');
        motionClean($page, $scroll, settle: 4.5);                  // the podium builds up for about three seconds
    })->with('motion sizes');

    it('shows the countdown, the podium and the lobby in Arabic too', function () {
        [$host, $room] = motionRoom(players: 4, screen: HostScreenLocale::Ar);
        $this->actingAs($host);

        $page = visit("/host/{$room->code}")->resize(1280, 720);
        $page->assertScript("document.documentElement.dir === 'rtl'");
        motionClean($page, 'none');

        motionCountdown($room);
        $page->navigate("/host/{$room->code}")->assertScript("document.querySelector('[data-test=countdown]') !== null");
        motionClean($page, 'none', exclude: [COUNTDOWN]);
    });
});

describe('every phone screen', function () {
    it('is free of errors, console output and accessibility problems, and never scrolls sideways', function (int $width, int $height) {
        [$host, $room] = motionRoom(players: 0, questions: 3);
        $engine = app(GameEngine::class);

        $phone = visit("/join?code={$room->code}")->resize($width, $height);
        $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
        motionWait($phone, "location.pathname === '/play/{$room->code}'", 15, 'joining the room');
        $me = $room->players()->firstOrFail();
        $me->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
        $other = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);

        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=ready-button]') !== null");
        motionClean($phone, 'sideways');

        motionCountdown($room);
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=countdown]') !== null");
        motionClean($phone, 'sideways', exclude: [COUNTDOWN]);

        $engine->startQuestion($room->fresh(), force: true);
        $question = $room->fresh()->currentQuestion();
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=player-game]').dataset.phase === 'question'");
        motionClean($phone, 'sideways');

        $right = array_search(optionOf($question), $question->option_order, true);
        $phone->script("() => document.querySelectorAll('[data-test=answers] button')[{$right}].click()");
        motionWait($phone, "document.querySelector('[data-test=player-game]').dataset.phase === 'answered'", 10, 'the answer to lock in');
        motionClean($phone, 'sideways');

        answer($other, $question, false);                            // everyone has answered: the question is revealed
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=player-game]').dataset.phase === 'correct'");
        motionClean($phone, 'sideways', settle: 1.5);

        // question 2: the phone answers wrong, and question 3: time runs out. Then the game is over.
        $engine->advance($room, 1, force: true);
        $second = $room->fresh()->currentQuestion();
        answer($me->fresh(), $second, false);
        answer($other, $second, true);
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=player-game]').dataset.phase === 'wrong'");
        motionClean($phone, 'sideways', settle: 1.5);

        $engine->advance($room, 2, force: true);
        $engine->reveal($room, 3, force: true);
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=player-game]').dataset.phase === 'timeout'");
        motionClean($phone, 'sideways', settle: 1.5);

        $engine->advance($room, 3, force: true);
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=player-results]') !== null");
        motionClean($phone, 'sideways', settle: 2.5);
    })->with('motion sizes');

    it('shows a streak chip from three right answers in a row, in the browser', function () {
        [$host, $room] = motionRoom(players: 0, questions: 4);
        $engine = app(GameEngine::class);
        $phone = visit("/join?code={$room->code}")->resize(390, 844);
        $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
        motionWait($phone, "location.pathname === '/play/{$room->code}'", 15, 'joining the room');
        $me = $room->players()->firstOrFail();
        $me->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
        $other = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);
        startGame($room);

        foreach ([1, 2, 3] as $position) {
            $question = $room->fresh()->currentQuestion();
            answer($me->fresh(), $question, true);
            answer($other, $question, false);
            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }
        $phone->navigate("/play/{$room->code}")->assertScript("document.querySelector('[data-test=streak]')?.dataset.streak === '3'");
        motionClean($phone, 'sideways', settle: 1.5);
    });
});

describe('with reduced motion', function () {
    it('moves nothing and bursts no confetti, on the host and on a phone', function () {
        [$host, $room] = motionRoom(players: 3);
        $this->actingAs($host);
        $engine = app(GameEngine::class);

        $page = visit("/host/{$room->code}", ['reducedMotion' => 'reduce'])->resize(1280, 720);
        $page->assertScript("matchMedia('(prefers-reduced-motion: reduce)').matches === true");
        expect($page->script("() => Alpine.store('sound').enabled"))->toBeFalse();   // the OS asks for less: sound starts off for a host too
        $page->wait(1.5);
        expect(motionMoving($page))->toBe([]);

        motionCountdown($room);
        $page->navigate("/host/{$room->code}")->wait(1.5);
        expect(motionMoving($page))->toBe([]);

        $engine->startQuestion($room->fresh(), force: true);
        $page->navigate("/host/{$room->code}")->wait(1.5);
        expect(motionMoving($page))->toBe([]);                        // the timer ring is a stroke and a number: it does not tremble or pulse

        $question = $room->fresh()->currentQuestion();
        foreach ($room->players as $player) {
            answer($player, $question, true);
        }
        $page->navigate("/host/{$room->code}")->wait(1.5);
        expect(motionMoving($page))->toBe([]);
        // The helpers themselves refuse to draw: no canvas, however often they are asked.
        $page->script("() => { lammaConfetti.burst('start', 30); lammaConfetti.rain(2000); }");
        $page->wait(0.5);
        $page->assertScript("document.querySelector('canvas[data-confetti]') === null");

        $done = finishedGame(['Sara' => 300, 'Ali' => 200], host: $host);
        $page->navigate("/host/{$done->code}")->wait(4.5);
        expect(motionMoving($page))->toBe([]);
        $page->assertScript("document.querySelector('canvas[data-confetti]') === null")->assertNoSmoke();
        // ...but the winner is still named, and the podium is all there.
        $page->assertScript("document.querySelector('[data-test=headline]').textContent.includes('Sara')")->assertScript("document.querySelectorAll('[data-test=podium-step]').length >= 2");
    });

    it('can tell the difference: the same countdown does move without it, and bursts confetti', function () {
        [$host, $room] = motionRoom(players: 3);
        $this->actingAs($host);
        motionCountdown($room);

        $page = visit("/host/{$room->code}")->resize(1280, 720);
        $page->assertScript("matchMedia('(prefers-reduced-motion: reduce)').matches === false")->wait(0.4);

        expect(motionMoving($page))->not->toBeEmpty();
        $page->script("() => lammaConfetti.burst('start', 20)");
        $page->wait(0.3)->assertScript("document.querySelector('canvas[data-confetti]') !== null")->assertScript("document.querySelector('canvas[data-confetti]').getAttribute('aria-hidden') === 'true'");
    });

    it('also stills the phone: the reveal, the check and the results', function () {
        [$host, $room] = motionRoom(players: 0, questions: 3);
        $phone = visit("/join?code={$room->code}", ['reducedMotion' => 'reduce'])->resize(390, 844);
        $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
        motionWait($phone, "location.pathname === '/play/{$room->code}'", 15, 'joining the room');
        $me = $room->players()->firstOrFail();
        $me->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
        $other = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);

        $phone->navigate("/play/{$room->code}")->wait(1.5);
        expect(motionMoving($phone))->toBe([])->and($phone->script("() => Alpine.store('sound').enabled"))->toBeFalse();

        $question = startGame($room);
        $phone->navigate("/play/{$room->code}")->wait(1.5);
        expect(motionMoving($phone))->toBe([]);

        answer($me->fresh(), $question, true);
        answer($other, $question, false);
        $phone->navigate("/play/{$room->code}")->wait(1.5);
        expect(motionMoving($phone))->toBe([]);
        // The check mark is simply there (it is not drawn stroke by stroke), and the teal layer simply fades.
        $phone->assertScript("document.querySelector('[data-test=check-draw]') !== null")->assertScript("getComputedStyle(document.querySelector('[data-test=check-draw] path')).strokeDashoffset === '0px' || getComputedStyle(document.querySelector('[data-test=check-draw] path')).strokeDashoffset === '0'")
            ->assertScript("document.querySelector('canvas[data-confetti]') === null");
        $phone->assertNoSmoke();
    });
});

describe('a whole game, with the sound store recording', function () {
    // Three people at once: every request must find out who it is from its own cookie (see ForgetGuards).
    beforeEach(fn () => app(Kernel::class)->prependMiddleware(ForgetGuards::class));

    /** Wraps the store's play() so each call is written down with whether the store was muted, and returns nothing: `played` is the store's own record of what really started. */
    function motionRecord($page): void
    {
        $page->script("() => { const s = Alpine.store('sound'); const real = s.play.bind(s); window.__asked = []; s.play = (name, options) => { window.__asked.push([name, s.isMuted()]); return real(name, options); }; }");
    }

    function motionAsked($page): array
    {
        return $page->script('() => window.__asked.map((entry) => entry[0])');
    }

    function motionPlayed($page): array
    {
        return $page->script("() => [...Alpine.store('sound').played]");
    }

    it('plays the expected sounds in order for one question, and nothing while muted', function () {
        $host = User::factory()->create(['name' => 'Hadi Host', 'email' => 'host@lamma.test', 'preferred_locale' => 'en']);
        $room = gameRoom(players: 0, questions: 2, settings: ['secondsPerQuestion' => 10, 'hostScreenLocale' => HostScreenLocale::En], host: $host, category: categoryWithQuestions(15));
        $code = $room->code;

        $hostScreen = visit('/login')->withLocale('en')->resize(1280, 720);
        $hostScreen->type('[name=email]', $host->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
        motionWait($hostScreen, "location.pathname === '/me/games'", 15, 'the host login');
        $hostScreen->navigate("/host/{$code}");
        motionWait($hostScreen, "document.querySelector('[data-test=room-code]') && window.Alpine?.store('sound')", 15, 'the host lobby');

        $phone = visit("/join?code={$code}")->withLocale('en')->resize(390, 844);
        $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
        motionWait($phone, "location.pathname === '/play/{$code}' && window.Alpine?.store('sound')", 15, 'the phone lobby');
        $room->players()->update(['left_at' => null]);              // what the host screen's presence connection would do

        // A phone starts silent, a host starts with sound on.
        expect($phone->script("() => Alpine.store('sound').enabled"))->toBeFalse()->and($hostScreen->script("() => Alpine.store('sound').enabled"))->toBeTrue();
        motionRecord($hostScreen);
        motionRecord($phone);

        $phone->click('[data-test=ready-button]');                    // a real tap: it also wakes the phone's audio, though the sound is off
        motionWait($phone, "document.querySelector('[data-test=ready-button]').getAttribute('aria-pressed') === 'true'", 10, 'Ready');
        motionWait($hostScreen, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 15, 'Start game to unlock');
        $hostScreen->click('[data-test=start-button]');               // a real click wakes the host's audio

        motionWait($hostScreen, "document.querySelector('[data-test=question-text]')", 20, 'question 1 on the host');
        motionWait($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 20, 'question 1 on the phone');

        // The phone is muted, so its sounds are asked for and not played.
        $phone->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
        motionWait($phone, "document.querySelector('[data-test=player-game]').dataset.phase === 'answered' || ['correct','wrong'].includes(document.querySelector('[data-test=player-game]').dataset.phase)", 10, 'the tap');
        expect(motionAsked($phone))->toContain('lock-in')->and(motionPlayed($phone))->toBe([]);

        motionWait($hostScreen, "document.querySelector('[data-test=correct-count]')", 20, 'the reveal on the host');
        motionWait($phone, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]').dataset.phase)", 20, 'the reveal on the phone');

        $asked = motionAsked($hostScreen);
        // The host: the "ready" chime when the last player was ready, the countdown (a tick for each number it was there for, then go), the question, then the
        // reveal. (No answer tick here: it comes from the PlayerAnswered broadcast, and Reverb is not running in this test; HostMotionTest covers it.)
        $go = array_search('countdown-go', $asked, true);
        $start = array_search('question-start', $asked, true);
        $reveal = array_search('reveal', $asked, true);
        $ticks = array_values(array_filter(array_slice($asked, 0, (int) $go), fn ($name) => $name !== 'ready-on'));
        expect($go)->not->toBeFalse()->and($start)->toBeGreaterThan($go)->and($reveal)->toBeGreaterThan($start)
            ->and(array_search('ready-on', $asked, true))->toBeLessThan($go)
            ->and($ticks)->each->toBe('countdown-tick')->and(count($ticks))->toBeBetween(1, 3)
            ->and(count(array_filter($asked, fn ($name) => $name === 'question-start')))->toBe(1)
            ->and(count(array_filter($asked, fn ($name) => $name === 'reveal')))->toBe(1);
        expect(motionPlayed($hostScreen))->toContain('question-start')->toContain('reveal');       // the host really played them

        // The answer tick reaches the host from the server as a browser event (Livewire dispatch, as the ready chime did above); here it is sent by hand, pitch and all.
        $hostScreen->script("() => window.dispatchEvent(new CustomEvent('lamma-sound', { detail: { name: 'tap', rate: 1.2 } }))");
        expect(motionAsked($hostScreen))->toContain('tap')->and(motionPlayed($hostScreen))->toContain('tap');

        // The phone's own result: one "correct" or one "wrong", the right buzz, and still nothing played (it is muted).
        $phoneAsked = motionAsked($phone);
        $result = in_array('correct', $phoneAsked, true) ? 'correct' : 'wrong';
        expect(count(array_filter($phoneAsked, fn ($name) => in_array($name, ['correct', 'wrong'], true))))->toBe(1)->and($phoneAsked)->toContain($result)->and(motionPlayed($phone))->toBe([]);

        // Turn the phone's sound on (a real click): what it is asked for in question 2 now really plays.
        $phone->click('[data-test=sound-toggle]');
        motionWait($phone, "document.querySelector('[data-test=sound-toggle]').getAttribute('aria-pressed') === 'true'", 5, 'the phone to unmute');

        // Mute the host (a real click) before question 2: it is asked for its sounds and plays none of them.
        $hostScreen->click('[data-test=sound-toggle]');
        motionWait($hostScreen, "document.querySelector('[data-test=sound-toggle]').getAttribute('aria-pressed') === 'false'", 5, 'the host to be muted');
        $playedWhenMuted = count(motionPlayed($hostScreen));
        $askedWhenMuted = count(motionAsked($hostScreen));

        motionWait($hostScreen, "document.querySelector('[data-test=progress-label]').textContent.includes('2')", 25, 'question 2 on the host');
        $hostScreen->wait(1);
        expect(count(motionAsked($hostScreen)))->toBeGreaterThan($askedWhenMuted)       // question-start was asked for...
            ->and(count(motionPlayed($hostScreen)))->toBe($playedWhenMuted)             // ...and nothing played
            ->and($hostScreen->script("() => Alpine.store('sound').isMuted()"))->toBeTrue()
            ->and($hostScreen->script("() => localStorage.getItem('lamma:sound')"))->toBe('0');

        motionWait($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 25, 'question 2 on the phone');
        $phone->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
        motionWait($phone, "Alpine.store('sound').played.includes('lock-in')", 10, 'the unmuted phone to play its lock-in');
        expect($phone->script("() => localStorage.getItem('lamma:sound')"))->toBe('1');

        foreach ([$hostScreen, $phone] as $screen) {
            $screen->assertNoJavaScriptErrors();
        }
        expect(Room::findOrFail($room->id)->status)->toBe(RoomStatus::Playing);
    });
});
