<?php

use App\Enums\RoomStatus;
use App\Jobs\BotAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Browser\Support\ForgetGuards;

/*
 * Phase 14.1, problem 1, in a real browser: the host screen and a player tab in ONE browser context (the same cookies: how a host tests alone on a laptop, and a demo host
 * who taps "Join from this laptop"). The channel itself needs Reverb, which these tests do not run (the screens follow by polling, as in FullGameTest), so what is checked
 * here is what the browser can: the host screen carries and sends the role marker (the one thing that keeps it the host on the channel; tests/Feature/Broadcasting/
 * HostScreenRoleTest.php checks the channel's answer, and it was checked live with Reverb), the tab that plays does not, and with both open the lobby counts, Start and a whole
 * game work.
 *
 * Run:  timeout 400 vendor/bin/pest tests/Browser/SameBrowserTest.php
 */

function sameWait($page, string $expression, int $seconds = 25, string $what = ''): void
{
    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

/** A second TAB of the same browser context as $page (same cookies): the plugin's visit() makes a new context every time, so the page's own context is asked for another page. */
function sameBrowserTab(AwaitableWebpage $page, string $path): AwaitableWebpage
{
    $url = $page->url();
    $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).':'.parse_url($url, PHP_URL_PORT);
    $tab = $page->page()->context()->newPage();
    $tab->goto($origin.$path);

    return new AwaitableWebpage($tab, $origin.$path);
}

/**
 * A click through the DOM. These tests run without Reverb (the screens follow by polling), and with the socket down the "Reconnecting..." card (`fixed inset-0`, shown by
 * `lammaConnection` about ten seconds after a page loads) covers the whole page, so a real mouse click on a button is intercepted by it and Playwright waits for it for
 * minutes. The buttons below are the ones this slow test reaches after that moment.
 */
function domClick($page, string $selector): void
{
    $page->script("() => document.querySelector('{$selector}').click()");
}

/** What the page's Echo sends with every channel authorization. */
function echoHeaders($page): array
{
    sameWait($page, 'window.Echo', 15, 'Echo to be created');

    return $page->script("() => ({ role: document.documentElement.dataset.realtimeRole ?? null, headers: Object.keys(window.Echo?.connector?.options?.auth?.headers ?? {}), sends: window.Echo?.connector?.options?.auth?.headers?.['X-Lamma-Role'] ?? null })");
}

it('keeps the host screen the host while a player tab of the same logged-in browser is open, and the game works with both', function () {
    Queue::fake();
    $host = User::factory()->create(['preferred_locale' => 'en', 'name' => 'Hadi Host']);
    $room = gameRoom(players: 0, questions: 3, settings: ['secondsPerQuestion' => 20], host: $host, category: categoryWithQuestions(15));
    $this->actingAs($host);

    // Tab 1: the big screen. Tab 2, same browser: the player tab.
    $screen = visit("/host/{$room->code}")->withLocale('en')->resize(1280, 720);
    sameWait($screen, "document.querySelector('[data-test=room-code]')", 15, 'the host lobby');
    $tab = sameBrowserTab($screen, "/join?code={$room->code}")->resize(390, 844);
    sameWait($tab, "document.querySelector('[data-test=nickname-input]')", 15, 'the join form');
    $tab->type('[data-test=nickname-input]', 'Hadi')->click('[data-test=join-button]');
    sameWait($tab, "location.pathname === '/play/{$room->code}'", 15, 'joining from the second tab');

    // The host screen says it is the host on every channel authorization; the tab that plays says nothing.
    expect(echoHeaders($screen))->toMatchArray(['role' => 'host', 'sends' => 'host']);
    expect(echoHeaders($tab))->toMatchArray(['role' => null, 'sends' => null]);
    expect(echoHeaders($tab)['headers'])->not->toContain('X-Lamma-Role');

    // One player row, tied to this browser's account and guest cookie: the host's own player tab.
    $player = RoomPlayer::query()->where('room_id', $room->id)->sole();
    expect($player->user_id)->toBe($host->id)->and($player->guest_token)->not->toBeNull();
    $player->update(['left_at' => null]); // what the presence connection of the player tab does

    domClick($tab, '[data-test=ready-button]');
    sameWait($tab, "document.querySelector('[data-test=ready-button]').getAttribute('aria-pressed') === 'true'", 10, 'Ready to register');
    sameWait($screen, "document.querySelector('[data-test=ready-count]').textContent.includes('1 of 1')", 20, 'the host to count the player ready');
    sameWait($screen, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 20, 'Start game to unlock');

    domClick($screen, '[data-test=start-button]');
    sameWait($screen, "document.querySelector('[data-test=host-game]')", 15, 'the game on the big screen');
    sameWait($tab, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 25, 'the first question on the player tab');
    $tab->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
    sameWait($tab, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]')?.dataset.phase)", 25, 'the reveal on the player tab');
    sameWait($screen, "document.querySelector('[data-test=correct-count]')", 25, 'the reveal on the big screen');

    expect(Room::findOrFail($room->id)->status)->toBe(RoomStatus::Playing);
    $screen->assertNoJavaScriptErrors();
    $tab->assertNoJavaScriptErrors();
});

it('plays a whole demo in one browser: the big screen and "Join from this laptop" are two tabs of the same context', function () {
    app(Kernel::class)->prependMiddleware(ForgetGuards::class);
    Queue::fake()->except(BotAnswer::class);
    demoContent();

    // Tab 1: the demo's big screen (no account: a host cookie).
    $screen = visit('/demo')->withLocale('en')->resize(1280, 720);
    $screen->click('[data-test=start-demo]');
    sameWait($screen, "document.querySelector('[data-test=room-code]')", 20, 'the demo lobby');
    $room = Room::sole();
    expect(echoHeaders($screen))->toMatchArray(['role' => 'host', 'sends' => 'host']);
    $screen->assertScript("document.querySelector('[data-test=join-from-laptop]') === null"); // not until a minute and a half has passed

    // Nobody scanned the code for 90 seconds: the banner offers the join page, in a new tab.
    $room->forceFill(['created_at' => now()->subMinutes(2)])->save();
    sameWait($screen, "document.querySelector('[data-test=join-from-laptop]')", 20, 'the "Join from this laptop" link');
    $screen->assertScript("document.querySelector('[data-test=join-from-laptop]').getAttribute('target') === '_blank'")
        ->assertScript("document.querySelector('[data-test=join-from-laptop]').getAttribute('rel') === 'noopener'")
        ->assertScript("document.querySelector('[data-test=join-from-laptop]').getAttribute('href').endsWith('/join?code={$room->code}')")
        ->assertScript("document.querySelector('[data-test=waiting-for-you]').textContent.includes('Scan the code')");
    $path = parse_url($screen->script("() => document.querySelector('[data-test=join-from-laptop]').href"), PHP_URL_PATH).'?code='.$room->code;

    // Tab 2, same browser: what a click on that link opens.
    $tab = sameBrowserTab($screen, $path)->resize(390, 844);
    sameWait($tab, "document.querySelector('[data-test=nickname-input]')", 15, 'the join form');
    $tab->type('[data-test=nickname-input]', 'Laila')->click('[data-test=join-button]');
    sameWait($tab, "location.pathname === '/play/{$room->code}'", 15, 'joining from this laptop');
    expect(echoHeaders($tab))->toMatchArray(['role' => null]);
    $room->players()->where('is_bot', false)->update(['left_at' => null]);

    domClick($tab, '[data-test=ready-button]');
    sameWait($tab, "document.querySelector('[data-test=ready-button]').getAttribute('aria-pressed') === 'true'", 10, 'Ready to register');
    sameWait($screen, "document.querySelector('[data-test=ready-count]').textContent.includes('3 of 3')", 20, 'the big screen to count three ready');
    // The banner is gone once somebody real is in.
    $screen->assertScript("document.querySelector('[data-test=join-from-laptop]') === null");
    sameWait($screen, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 20, 'Start game to unlock');

    domClick($screen, '[data-test=start-button]');
    foreach ([1, 2, 3, 4] as $question) {
        sameWait($tab, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 30, "question {$question} on the player tab");
        $tab->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
        sameWait($tab, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]')?.dataset.phase)", 25, "the reveal of question {$question}");
        sameWait($screen, "document.querySelector('[data-test=correct-count]')", 25, "the big screen's reveal of question {$question}");
    }

    sameWait($screen, "document.querySelector('[data-test=host-results]')", 40, 'the podium');
    sameWait($tab, "document.querySelector('[data-test=player-results]')", 40, 'the results on the player tab');
    expect($room->fresh()->isCompleted())->toBeTrue();
    $screen->assertScript("document.querySelector('[data-test=host-your-own]') !== null && document.querySelector('[data-test=play-again]') === null");
    $tab->assertScript("document.querySelectorAll('[data-test=leaderboard] li').length === 3");
    $screen->assertNoJavaScriptErrors();
    $tab->assertNoJavaScriptErrors();
});
