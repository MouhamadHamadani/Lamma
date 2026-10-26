<?php

/*
 * Google Analytics and its consent notice, in a real browser, with analytics switched on as it is in production (a valid ID, APP_ENV=production).
 * Nothing may reach Google, and no analytics cookie may be written, until the visitor accepts; afterwards the gtag script is added to the page and a
 * page_view with the SANITIZED path is queued in dataLayer (never the room code from the address). Whether Google actually answers is not tested here
 * (the browser may be offline), only what the page does.
 */

dataset('locales', [
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
]);

beforeEach(function () {
    app()['env'] = 'production';
    config(['lamma.analytics.id' => 'G-ABC123DEF4']);
});

/** True when something in the page script returns truthy. */
function dataLayerHas(string $predicate): string
{
    return "(window.dataLayer || []).some((entry) => {$predicate})";
}

it('asks first: nothing loads, nothing is stored, no request goes to Google, and the notice is shown', function (string $locale, string $dir) {
    $page = visit('/')->withLocale($locale)->resize(1280, 800)->wait(1);

    $page->assertNoSmoke()
        ->assertScript("document.documentElement.dir === '{$dir}'")
        ->assertScript("getComputedStyle(document.querySelector('[data-test=consent-banner]')).display !== 'none'")
        ->assertScript("document.querySelector('[data-test=consent-banner] button').offsetHeight >= 44")
        ->assertScript("!document.querySelector('script[src*=\"googletagmanager\"]')")
        ->assertScript("performance.getEntriesByType('resource').every((entry) => !/googletagmanager|google-analytics|doubleclick/.test(entry.name))")
        ->assertScript("!document.cookie.split(';').some((cookie) => cookie.trim().startsWith('_ga'))")
        ->assertScript("localStorage.getItem('lamma_consent') === null")
        ->assertScript(dataLayerHas("entry[0] === 'consent' && entry[1] === 'default' && entry[2].analytics_storage === 'denied' && entry[2].ad_storage === 'denied'"))
        ->assertScript('!'.dataLayerHas("entry[0] === 'event' && entry[1] === 'page_view'"));
})->with('locales');

it('after accepting: the gtag script is added and page_view is queued with the sanitized path, and the notice stays away', function () {
    $page = visit('/join?code=K7MP9Z&nickname=Sara')->withLocale('en')->resize(390, 844)->wait(1);

    $page->click('[data-test=consent-accept]')->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript("localStorage.getItem('lamma_consent') === 'granted'")
        ->assertScript("!!document.querySelector('script[src*=\"googletagmanager.com/gtag/js?id=G-ABC123DEF4\"]')")
        ->assertScript(dataLayerHas("entry[0] === 'consent' && entry[1] === 'update' && entry[2].analytics_storage === 'granted'"))
        ->assertScript(dataLayerHas("entry[0] === 'event' && entry[1] === 'page_view' && entry[2].page_path === '/join' && entry[2].page_title === 'join'"))
        // Nothing with a room code, a nickname or the query string went into dataLayer.
        ->assertScript('!/K7MP9Z|Sara|nickname|code=/.test(JSON.stringify(Array.from(window.dataLayer, (entry) => Array.from(entry))))')
        ->assertScript("getComputedStyle(document.querySelector('[data-test=consent-banner]')).display === 'none'");

    // Remembered: after a reload analytics loads at once (page_view queued again) and the notice does not come back.
    $page->script('location.reload()');
    $page->wait(1.5)
        ->assertScript("getComputedStyle(document.querySelector('[data-test=consent-banner]')).display === 'none'")
        ->assertScript("!!document.querySelector('script[src*=\"googletagmanager.com/gtag/js?id=G-ABC123DEF4\"]')")
        ->assertScript(dataLayerHas("entry[0] === 'event' && entry[1] === 'page_view' && entry[2].page_path === '/join'"));
});

it('after declining: nothing loads and the choice is remembered', function () {
    $page = visit('/')->withLocale('en')->resize(1280, 800)->wait(1);

    $page->click('[data-test=consent-decline]')->wait(1)
        ->assertNoSmoke()
        ->assertScript("localStorage.getItem('lamma_consent') === 'denied'")
        ->assertScript("!document.querySelector('script[src*=\"googletagmanager\"]')")
        ->assertScript('!'.dataLayerHas("entry[0] === 'event'"))
        ->assertScript("getComputedStyle(document.querySelector('[data-test=consent-banner]')).display === 'none'");
});

it('can be reopened from "Cookie settings" in the footer', function () {
    $page = visit('/')->withLocale('en')->resize(1280, 800)->wait(1);
    $page->click('[data-test=consent-decline]')->wait(0.5);

    $page->script("document.querySelector('[data-test=cookie-settings]').scrollIntoView()");
    $page->click('[data-test=cookie-settings]')->wait(0.5)
        ->assertScript("getComputedStyle(document.querySelector('[data-test=consent-banner]')).display !== 'none'");
});

it('does not cover the join button on a phone, and does not scroll sideways', function () {
    $page = visit('/join')->withLocale('en')->resize(390, 844)->wait(1);

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("document.querySelector('[data-test=consent-banner]').getBoundingClientRect().top >= document.querySelector('[data-test=join-button]').getBoundingClientRect().bottom - 1")
        ->assertNoAccessibilityIssues();
});

it('is silent when analytics is off', function () {
    config(['lamma.analytics.id' => null]);

    visit('/')->withLocale('en')->wait(1)
        ->assertNoSmoke()
        ->assertScript('typeof window.lammaAnalytics === "undefined"')
        ->assertScript("!document.querySelector('[data-test=consent-banner]')")
        ->assertScript('typeof window.lammaTrack === "function"');
});
