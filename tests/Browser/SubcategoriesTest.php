<?php

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * Subcategories in a real browser, with the shipped content: the create-room screen with Sports expanded (a phone, a laptop and a short laptop: no
 * JavaScript error, no accessibility problem, and no scroll on the host sizes), picking a chip, and a game started from a subcategory showing
 * "Sports · Football" in the category chip of the host screen.
 */

dataset('create room sizes', [
    'phone 390x844' => [390, 844, false],
    'laptop 1440x900' => [1440, 900, false], // a setup page, not a game: it was never held to "no scroll" between 1280x720 and 1920x1080 only
    'short laptop 1280x720' => [1280, 720, true],
    'TV 1920x1080' => [1920, 1080, true],
]);

dataset('locales', [
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
]);

beforeEach(fn () => Queue::fake());

it('opens the subcategories of Sports and picks one, in both languages, without errors, accessibility problems or (on the 1280x720 and 1920x1080 host screens) scrolling', function (int $width, int $height, bool $host, string $locale, string $dir) {
    (new ContentSeeder)->run();
    $sports = Category::where('slug', 'sports')->firstOrFail();
    $this->actingAs(User::factory()->create(['preferred_locale' => $locale]));

    $page = visit('/rooms/create')->withLocale($locale)->resize($width, $height)->wait(1);

    $page->assertScript("document.documentElement.dir === '{$dir}'")
        ->click("[aria-controls=\"subcategories-{$sports->id}\"]")->wait(1)
        ->assertScript("document.querySelector('[aria-controls=\"subcategories-{$sports->id}\"]').getAttribute('aria-expanded') === 'true'")
        ->assertScript("document.querySelectorAll('[data-test=subcategories] [aria-pressed]').length === 7")
        ->assertScript("[...document.querySelectorAll('[data-test=subcategories] button')].every((chip) => chip.offsetHeight >= 44)")
        ->click('[data-test=subcategory-football]')->wait(1)
        ->assertScript("document.querySelector('[data-test=subcategory-football]').getAttribute('aria-pressed') === 'true'")
        ->assertScript("document.querySelector('[data-test=category-sports]').getAttribute('aria-pressed') === 'mixed'")
        ->assertNoSmoke();

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
    if ($host) {
        $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')->assertScript('document.body.scrollHeight <= window.innerHeight + 1');
    }
    $page->assertNoAccessibilityIssues();
})->with('create room sizes')->with('locales');

it('starts a game from a subcategory and shows "Sports · Football" in the chip, in English and in Arabic', function (HostScreenLocale $screen, string $chip) {
    (new ContentSeeder)->run();
    $football = Category::where('slug', 'football')->firstOrFail();
    $room = gameRoom(players: 3, questions: 3, settings: ['hostScreenLocale' => $screen, 'secondsPerQuestion' => 30], category: $football);
    app(GameEngine::class)->start($room, $room->host);
    $this->actingAs($room->host);

    $page = visit("/host/{$room->code}")->resize(1280, 720)->wait(1);

    $page->assertSeeIn('[data-test=category]', $chip)
        ->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertNoSmoke();
})->with([
    'English' => [HostScreenLocale::En, 'Sports · Football'],
    'Arabic' => [HostScreenLocale::Ar, 'رياضة · كرة القدم'],
]);
