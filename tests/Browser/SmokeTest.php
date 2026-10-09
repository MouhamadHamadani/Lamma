<?php

use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;

/*
 * Real-browser smoke tests (Chromium through Playwright): every public and account page, in English and Arabic, on a phone (390x844) and a
 * laptop (1440x900). Each page must raise no JavaScript error and write nothing to the console, pass the axe accessibility audit at its
 * default level (critical and serious problems), carry the right <html lang dir>, and on the phone not scroll sideways.
 *
 * Run:  vendor/bin/pest tests/Browser   (needs `npm run build` and `npx playwright install chromium` once)
 */

dataset('locales', [
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
]);

dataset('viewports', [
    'phone 390x844' => [390, 844],
    'laptop 1440x900' => [1440, 900],
]);

dataset('public pages', [
    'landing' => '/',
    'join' => '/join',
    'log in' => '/login',
    'register' => '/register',
    'forgot password' => '/forgot-password',
    '404' => '/this-page-does-not-exist',
]);

dataset('account pages', [
    'my games' => '/me/games',
    'settings: profile' => '/settings/profile',
    'settings: security' => '/settings/security',
]);

/** The checks every page gets. */
function assertHealthy($page, string $locale, string $dir, int $width): void
{
    $page->assertNoSmoke()
        ->assertNoAccessibilityIssues()
        ->assertScript("document.documentElement.lang === '{$locale}'")
        ->assertScript("document.documentElement.dir === '{$dir}'");

    if ($width < 768) {
        $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');       // no sideways scroll on a phone
    }
}

it('is healthy on every public page', function (string $path, string $locale, string $dir, int $width, int $height) {
    $page = visit($path)->withLocale($locale)->resize($width, $height);

    assertHealthy($page, $locale, $dir, $width);
})->with('public pages')->with('locales')->with('viewports');

it('is healthy on every account page for a logged-in user', function (string $path, string $locale, string $dir, int $width, int $height) {
    $user = User::factory()->create(['preferred_locale' => $locale]);
    // The security tab asks to confirm the password first: a real session would have done that already.
    $this->actingAs($user)->withoutMiddleware(RequirePassword::class);

    $page = visit($path)->resize($width, $height);

    assertHealthy($page, $locale, $dir, $width);
})->with('account pages')->with('locales')->with('viewports');

it('shows the account menu on a logged-in page and opens it with the keyboard', function (string $locale) {
    $this->actingAs(User::factory()->create(['preferred_locale' => $locale, 'name' => 'Sara Ahmad']));

    $page = visit('/me/games')->resize(390, 844);

    $page->assertSee('Sara')
        ->assertScript("document.querySelector('[data-test=user-menu-button]').getAttribute('aria-expanded') === 'false'")
        ->click('[data-test=user-menu-button]')
        ->assertScript("document.querySelector('[data-test=user-menu-button]').getAttribute('aria-expanded') === 'true'")
        ->assertNoAccessibilityIssues()
        ->keys('[data-test=user-menu-button]', 'Escape')
        ->assertScript("document.querySelector('[data-test=user-menu-button]').getAttribute('aria-expanded') === 'false'");
})->with(['en', 'ar']);

it('opens and closes the delete-account dialog, with the password field focused and Escape closing it', function () {
    $this->actingAs(User::factory()->create(['preferred_locale' => 'en']));

    $page = visit('/settings/profile')->resize(1440, 900);

    $page->assertScript("document.querySelector('dialog').open === false")
        ->click('[data-test=delete-user-button]')
        ->assertScript("document.querySelector('dialog').open === true")
        ->assertScript("document.activeElement && document.activeElement.name === 'password'")
        ->assertNoAccessibilityIssues()
        ->keys('input[name=password]', 'Escape')
        ->assertScript("document.querySelector('dialog').open === false");
});

it('switches language from the header on a public page and flips the direction', function () {
    $page = visit('/login')->withLocale('en')->resize(1440, 900);

    $page->assertScript("document.documentElement.dir === 'ltr'")
        ->click('[data-test=locale-switch-ar]')
        ->assertScript("document.documentElement.dir === 'rtl'")
        ->assertScript("document.documentElement.lang === 'ar'");
});
