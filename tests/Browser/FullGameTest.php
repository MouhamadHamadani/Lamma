<?php

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Tests\Browser\Support\ForgetGuards;

/*
 * One whole game in three real browsers: the host's big screen, an Arabic-speaking guest's phone and an English-speaking logged-in
 * player's phone. Every step is a real click or key press: log in, join with the code, tap Ready, Start, answer, and watch each screen
 * change, up to the podium and the results. Each visit() is its own browser context, so the three keep separate cookies and sessions.
 *
 * Reverb is NOT running in this test (it needs a second server process and a front-end build that points at it), so the screens follow
 * the game the way they do when the socket is down: the host screen's 2-second tick moves the timers, and the phones poll every 4-5
 * seconds. Real-time pushes are covered by the Echo/broadcast unit tests and by docs/manual-test-plan.md. The only thing done outside
 * the browser is what a presence connection would do: marking the phones as connected (rooms are given `left_at = null`).
 */

// Three people at once: every request must find out who it is from its own cookie, not from the previous request (see ForgetGuards).
beforeEach(fn () => app(Kernel::class)->prependMiddleware(ForgetGuards::class));

/** A progress line for LAMMA_TRACE=<file> (a stuck run shows where it stopped). */
function trace(string $line): void
{
    if ($file = getenv('LAMMA_TRACE')) {
        file_put_contents($file, date('H:i:s').' '.$line.PHP_EOL, FILE_APPEND);
    }
}

/** With LAMMA_SHOTS=1, save a screenshot of the screen as it is (tests/Browser/Screenshots), for a human to look at. */
function shot($page, string $name): void
{
    if (getenv('LAMMA_SHOTS')) {
        $page->screenshot(fullPage: false, filename: $name);
    }
}

/** Poll the page until the JavaScript expression is truthy (the other pages keep being served while this waits). */
function waitUntil($page, string $expression, int $seconds = 25, string $what = ''): void
{
    trace('waiting: '.($what ?: $expression));

    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

/** The text of the first element matching the selector. */
function textOf($page, string $selector): string
{
    return (string) $page->script("() => (document.querySelector('{$selector}')?.textContent ?? '').replace(/\\s+/g, ' ').trim()");
}

/** Tap the nth answer button (0 = the first one on screen) the way a thumb would. */
function tapAnswer($page, int $index): void
{
    $page->script("() => document.querySelectorAll('[data-test=answers] button')[{$index}].click()");
}

/** Where the right answer sits on the screen: the position of the correct option in the order every screen shows. */
function correctPosition(Room $room): int
{
    $question = $room->fresh()->currentQuestion();

    return array_search(optionOf($question), $question->option_order, true);
}

function logIn($page, User $user): void
{
    $page->type('[name=email]', $user->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
    waitUntil($page, "location.pathname === '/me/games'", 15, 'the login to finish');
}

function joinRoom($page, string $code, string $nickname): void
{
    $page->type('[data-test=code-input]', $code)->type('[data-test=nickname-input]', $nickname)->click('[data-test=join-button]');
    waitUntil($page, "location.pathname === '/play/{$code}'", 15, "joining room {$code}");
}

it('plays a whole game, from the lobby to the podium', function () {
    $host = User::factory()->create(['name' => 'Hadi Host', 'email' => 'host@lamma.test', 'preferred_locale' => 'en']);
    $maya = User::factory()->create(['name' => 'Maya', 'email' => 'maya@lamma.test', 'preferred_locale' => 'en']);
    $room = gameRoom(players: 0, questions: 3, settings: ['secondsPerQuestion' => 10], host: $host, category: categoryWithQuestions(15));
    $code = $room->code;

    // ---- the three screens ----
    $hostScreen = visit('/login')->withLocale('en')->resize(1280, 720);
    logIn($hostScreen, $host);
    $hostScreen->navigate("/host/{$code}");
    waitUntil($hostScreen, "document.querySelector('[data-test=room-code]')", 15, 'the host lobby');

    $guest = visit("/join?code={$code}")->withLocale('ar')->resize(390, 844);
    $guest->assertScript("document.documentElement.dir === 'rtl'");
    joinRoom($guest, $code, 'ليلى');

    $player = visit('/login')->withLocale('en')->resize(390, 844);
    logIn($player, $maya);
    $player->navigate("/join?code={$code}");
    joinRoom($player, $code, 'Maya');

    // ---- the lobby ----
    $guest->assertScript("document.documentElement.lang === 'ar'")->assertSee('ليلى');
    $player->assertSee('Maya');
    expect($room->players()->count())->toBe(2);
    // What the host screen's presence connection would do once the phones are on the channel:
    $room->players()->update(['left_at' => null]);

    foreach ([$guest, $player] as $phone) {
        $phone->click('[data-test=ready-button]');
        waitUntil($phone, "document.querySelector('[data-test=ready-button]').getAttribute('aria-pressed') === 'true'", 10, 'Ready to register');
    }
    waitUntil($hostScreen, "document.querySelector('[data-test=ready-count]').textContent.includes('2 of 2')", 15, 'the host to see both players ready');
    waitUntil($hostScreen, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 15, 'Start game to unlock');
    $hostScreen->assertNoJavaScriptErrors();

    // ---- start: the guest answers right, Maya answers wrong (question 1) ----
    $hostScreen->click('[data-test=start-button]');
    waitUntil($hostScreen, "document.querySelector('[data-test=host-game]')", 15, 'the game to start on the host screen');
    foreach ([$guest, $player] as $phone) {
        waitUntil($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 20, 'the first question on a phone');
    }
    $hostScreen->assertScript("document.querySelector('[data-test=progress-label]').textContent.includes('1')");
    $guest->wait(1)->assertNoAccessibilityIssues();                                    // the question as an Arabic phone shows it
    foreach ([$guest, $player] as $phone) {
        $phone->assertScript('document.documentElement.scrollWidth <= window.innerWidth');       // a phone never scrolls sideways
    }
    shot($guest, 'game-1-guest-question');
    shot($hostScreen, 'game-1-host-question');
    $player->assertScript("document.documentElement.dir === 'ltr'");

    $scores = [];
    $plan = [[true, false], [true, false], [false, true]];                              // [guest right?, Maya right?] per question
    foreach ($plan as $n => [$guestRight, $mayaRight]) {
        $right = correctPosition($room);
        $wrong = $right === 0 ? 1 : 0;
        tapAnswer($guest, $guestRight ? $right : $wrong);
        waitUntil($guest, "document.querySelector('[data-test=player-game]').dataset.phase === 'answered'", 10, 'the guest to be locked in');
        tapAnswer($player, $mayaRight ? $right : $wrong);

        // Everyone has answered, so the question is revealed at once; each phone shows its own result.
        waitUntil($guest, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]').dataset.phase)", 20, 'the reveal on the guest phone');
        waitUntil($player, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]').dataset.phase)", 20, 'the reveal on Maya\'s phone');
        expect($guest->script("() => document.querySelector('[data-test=player-game]').dataset.phase"))->toBe($guestRight ? 'correct' : 'wrong');
        expect($player->script("() => document.querySelector('[data-test=player-game]').dataset.phase"))->toBe($mayaRight ? 'correct' : 'wrong');
        waitUntil($hostScreen, "document.querySelector('[data-test=correct-count]')", 20, 'the reveal on the host screen');

        if ($n === 0) {
            expect(textOf($guest, '[data-test=result-title]'))->toContain('صحيح');             // Arabic phone, Arabic words
            expect(textOf($player, '[data-test=result-title]'))->toContain('Not quite');        // English phone, English words
            $guest->wait(1)->assertNoAccessibilityIssues();
            $player->assertNoAccessibilityIssues();
            shot($guest, 'game-2-guest-correct');
            shot($player, 'game-2-maya-wrong');
            shot($hostScreen, 'game-2-host-reveal');
        }

        // The reveal lasts 5 seconds, then the host screen's tick opens the next question (or finishes the game).
        if ($n < 2) {
            foreach ([$guest, $player] as $phone) {
                waitUntil($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 25, 'the next question');
            }
        }
    }

    // ---- the end: podium on the host, results on the phones ----
    waitUntil($hostScreen, "document.querySelector('[data-test=host-results]')", 30, 'the podium');
    foreach ([$guest, $player] as $phone) {
        waitUntil($phone, "document.querySelector('[data-test=player-results]')", 30, 'the results screen');
    }
    expect(Room::findOrFail($room->id)->status)->toBe(RoomStatus::Finished);

    $hostScreen->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1');   // the podium fits the screen
    expect(textOf($hostScreen, '[data-test=headline]'))->toContain('ليلى');                            // the guest won with 200 points
    expect($hostScreen->script("() => document.querySelectorAll('[data-test=podium-step]').length"))->toBeGreaterThanOrEqual(2);

    expect($guest->script("() => document.querySelector('[data-test=player-results]').dataset.rank"))->toBe('1');
    expect($player->script("() => document.querySelector('[data-test=player-results]').dataset.rank"))->toBe('2');
    $guest->assertScript("document.querySelector('[data-test=save-login]') !== null");                // a guest is offered to save the score...
    $player->assertScript("document.querySelector('[data-test=save-login]') === null");              // ...a logged-in player already has it saved
    expect(textOf($player, '[data-test=save-card]'))->toContain('Saved to your profile');
    $guest->wait(4)->assertNoAccessibilityIssues();
    shot($guest, 'game-3-guest-results');
    shot($player, 'game-3-maya-results');
    shot($hostScreen, 'game-3-host-podium');

    foreach ([$guest, $player] as $phone) {
        $phone->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
    }
    foreach ([$hostScreen, $guest, $player] as $screen) {
        $screen->assertNoJavaScriptErrors();
    }

    // Maya's finished game is in her history.
    $player->navigate('/me/games')->assertSee('Maya')->assertScript("document.querySelector('[data-test=total-played]').textContent.trim() === '1'");
});
