<?php

use App\Models\Room;
use App\Models\User;

/*
 * The sound manager in a real browser (Chromium): silent and harmless until a tap unlocks audio, a host starts with sound on and a phone with it off,
 * and the choice survives a reload. Headless Chromium has a real AudioContext (output goes nowhere), so `store.played` shows what really started.
 */

/** The sound store's state, as the page sees it. */
function soundState($page): array
{
    return $page->script("() => { const s = Alpine.store('sound'); return { enabled: s.enabled, played: s.played, saved: localStorage.getItem('lamma:sound') }; }");
}

/** Poll until the JavaScript expression is truthy (the pages keep running meanwhile). */
function soundWaitFor($page, string $expression, string $what): void
{
    for ($i = 0; $i < 30; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.3);
    }

    throw new RuntimeException("Timed out waiting for {$what}");
}

function hostLobby(): array
{
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = gameRoom(players: 1, settings: [], host: $host);

    return [$host, $room];
}

it('does nothing, and throws nothing, when a sound is asked for before audio is unlocked', function () {
    [$host, $room] = hostLobby();
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize(1280, 720);
    soundWaitFor($page, "document.querySelector('[data-test=sound-toggle]')", 'the sound toggle');

    // Asked for before any tap: ignored, even with the sound on and every name known.
    $result = $page->script("() => { const s = Alpine.store('sound'); s.enabled = true; ['tap', 'win', 'countdown-go', 'nonsense'].forEach((n) => s.play(n)); s.buzz('tap'); return s.played.length; }");

    expect($result)->toBe(0);
    $page->assertNoJavaScriptErrors();
});

it('starts a host with the sound on, and a first click then unlocks real playback', function () {
    [$host, $room] = hostLobby();
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize(1280, 720);
    soundWaitFor($page, "document.querySelector('[data-test=sound-toggle]')", 'the sound toggle');

    expect(soundState($page)['enabled'])->toBeTrue();
    $page->assertAttribute('[data-test=sound-toggle]', 'aria-pressed', 'true');

    // The first click anywhere on the page (the Start game click, in a game) wakes the audio context.
    $page->click('[data-test=room-code]');
    soundWaitFor($page, "(() => { Alpine.store('sound').play('tap'); return Alpine.store('sound').played.includes('tap'); })()", 'audio to unlock');
    $page->assertNoJavaScriptErrors();
});

it('keeps the mute across a reload, and a reload does not turn it back on', function () {
    [$host, $room] = hostLobby();
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize(1280, 720);
    soundWaitFor($page, "document.querySelector('[data-test=sound-toggle]')", 'the sound toggle');

    $page->click('[data-test=sound-toggle]');
    soundWaitFor($page, "document.querySelector('[data-test=sound-toggle]').getAttribute('aria-pressed') === 'false'", 'the toggle to mute');
    expect(soundState($page)['saved'])->toBe('0');

    $page->script('() => location.reload()');
    soundWaitFor($page, "document.querySelector('[data-test=sound-toggle]') && Alpine.store('sound')", 'the page to come back');
    $page->wait(0.5);

    $state = soundState($page);
    expect($state['enabled'])->toBeFalse()->and($state['saved'])->toBe('0');
    $page->assertAttribute('[data-test=sound-toggle]', 'aria-pressed', 'false');

    // Asked to play while muted: nothing starts, even after a click unlocked audio.
    $page->script("() => Alpine.store('sound').play('tap')");
    expect(soundState($page)['played'])->toBe([]);
    $page->assertNoJavaScriptErrors();
});

it('starts a phone with the sound off, and the switch turns it on and remembers it', function () {
    [, $room] = hostLobby();

    $phone = visit("/join?code={$room->code}")->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
    soundWaitFor($phone, "location.pathname === '/play/{$room->code}' && document.querySelector('[data-test=sound-toggle]')", 'the phone lobby');

    expect(soundState($phone)['enabled'])->toBeFalse();
    $phone->assertAttribute('[data-test=sound-toggle]', 'aria-pressed', 'false');

    $phone->click('[data-test=sound-toggle]');
    soundWaitFor($phone, "document.querySelector('[data-test=sound-toggle]').getAttribute('aria-pressed') === 'true'", 'the toggle to switch on');
    expect(soundState($phone)['saved'])->toBe('1');
    $phone->assertNoJavaScriptErrors();

    expect(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});
