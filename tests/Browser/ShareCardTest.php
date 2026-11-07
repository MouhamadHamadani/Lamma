<?php

use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * The result share card in a real browser (Chromium): the PNG the button draws on a canvas is a real 1080x1350 image with the brand colours and the Arabic
 * shaped by the browser, the button shares it as a file where the browser can and downloads it where it cannot, and under reduced motion nothing moves.
 *
 * Run:  timeout 300 vendor/bin/pest tests/Browser/ShareCardTest.php   (LAMMA_SHOTS=1 saves screenshots in tests/Browser/Screenshots)
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class]);
});

function shareWait($page, string $expression, int $seconds = 20, string $what = ''): void
{
    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

/** A finished game this user played (second place, against Sara and Rival), as a phone in this language. */
function shareFinishedFor(User $user, string $locale): string
{
    [$room, $mine] = savedGame($user, 400, ['Sara' => 450, 'Rival' => 100, 'Maya' => 50]);
    $room->players()->update(['locale' => $locale]);
    $mine->update(['nickname' => $locale === 'ar' ? 'ليلى' : 'Layla']);

    return $room->code;
}

/** What the card the button draws turns out to be: made by the page itself, so this runs the real code. */
function shareCardFacts($page): array
{
    return $page->script("async () => {
        const root = document.querySelector('[data-test=share-result]');
        const blob = await Alpine.\$data(root).make();
        const bitmap = await createImageBitmap(blob);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width; canvas.height = bitmap.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(bitmap, 0, 0);
        const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const rgb = (hex) => { const n = parseInt(hex.replace('#', ''), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        const count = (hex) => { const [r, g, b] = rgb(hex); let found = 0; for (let i = 0; i < data.length; i += 4) { if (data[i] === r && data[i + 1] === g && data[i + 2] === b) found++; } return found; };
        const corner = ctx.getImageData(3, 3, 1, 1).data;
        return { type: blob.type, size: blob.size, width: bitmap.width, height: bitmap.height, navy: count(css('--color-navy')), sun: count(css('--color-sun')), cream: count(css('--color-cream')),
                 teal: count(css('--color-teal')), coral: count(css('--color-coral')), opaque: corner[3] === 255 };
    }");
}

it('draws a real 1080x1350 PNG with the brand colours, for an English phone and an Arabic phone', function (string $locale) {
    $user = User::factory()->create(['preferred_locale' => $locale]);
    $code = shareFinishedFor($user, $locale);
    $this->actingAs($user);

    $page = visit("/play/{$code}")->withLocale($locale)->resize(390, 844);
    shareWait($page, "document.querySelector('[data-test=share-result]')", 15, 'the share button');
    $page->assertSee($locale === 'ar' ? 'شارك النتيجة' : 'Share result');

    $card = shareCardFacts($page);

    expect($card['type'])->toBe('image/png')->and($card['width'])->toBe(1080)->and($card['height'])->toBe(1350)->and($card['size'])->toBeGreaterThan(30000)
        ->and($card['opaque'])->toBeTrue()->and($card['navy'])->toBeGreaterThan(500000)->and($card['sun'])->toBeGreaterThan(500)->and($card['cream'])->toBeGreaterThan(5000)
        ->and($card['teal'])->toBeGreaterThan(500)->and($card['coral'])->toBeGreaterThan(500);
    if (getenv('LAMMA_SHOTS')) {
        $page->screenshot(fullPage: true, filename: "share-phone-{$locale}");
    }
    $page->assertNoJavaScriptErrors();
})->with(['en', 'ar']);

it('draws the host\'s card from the podium, with no place of its own', function () {
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = finishedGame(['Ali' => 300, 'سارة' => 200, 'Hadi' => 100], questions: 3, host: $host);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->withLocale('en')->resize(1280, 720);
    shareWait($page, "document.querySelector('[data-test=share-result]')", 15, 'the share button on the podium');

    $card = shareCardFacts($page);

    expect($card['width'])->toBe(1080)->and($card['height'])->toBe(1350)->and($card['size'])->toBeGreaterThan(30000)->and($card['sun'])->toBeGreaterThan(500);
    // Nothing scrolls and the button is on the screen, not below it.
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript("document.querySelector('[data-test=share-button]').getBoundingClientRect().bottom <= window.innerHeight");
    $page->assertNoJavaScriptErrors();
});

it('shares the PNG as a file where the browser can, and says so', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);
    $code = shareFinishedFor($user, 'en');
    $this->actingAs($user);

    $page = visit("/play/{$code}")->withLocale('en')->resize(390, 844);
    shareWait($page, "document.querySelector('[data-test=share-result]')");
    $page->script("() => { window.__shared = null; Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true });
        Object.defineProperty(navigator, 'share', { value: async (data) => { window.__shared = { files: data.files.map((f) => ({ name: f.name, type: f.type, size: f.size })), title: data.title, text: data.text }; }, configurable: true }); }");

    $page->click('[data-test=share-button]');
    shareWait($page, 'window.__shared !== null', 15, 'the share sheet to be asked');

    $shared = $page->script('() => window.__shared');
    expect($shared['files'])->toHaveCount(1)->and($shared['files'][0]['name'])->toBe('lamma-result.png')->and($shared['files'][0]['type'])->toBe('image/png')
        ->and($shared['files'][0]['size'])->toBeGreaterThan(30000)->and($shared['title'])->toBe('My result on Lamma');
    shareWait($page, "document.querySelector('[data-test=share-status]').textContent.trim() === 'Result shared'", 5, 'the status line');
    $page->assertNoJavaScriptErrors();
});

it('downloads the PNG where sharing a file is not possible', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);
    $code = shareFinishedFor($user, 'en');
    $this->actingAs($user);

    $page = visit("/play/{$code}")->withLocale('en')->resize(390, 844);
    shareWait($page, "document.querySelector('[data-test=share-result]')");
    $page->script("() => { window.__downloads = []; Object.defineProperty(navigator, 'canShare', { value: () => false, configurable: true });
        const click = HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function () { if (this.download) { window.__downloads.push({ name: this.download, blob: this.href.startsWith('blob:') }); return; } return click.call(this); }; }");

    $page->click('[data-test=share-button]');
    shareWait($page, 'window.__downloads.length === 1', 15, 'the download');

    expect($page->script('() => window.__downloads'))->toBe([['name' => 'lamma-result.png', 'blob' => true]]);
    shareWait($page, "document.querySelector('[data-test=share-status]').textContent.trim() === 'Result saved to your device'", 5, 'the status line');
    $page->assertNoJavaScriptErrors();
});

it('does nothing and says nothing when the person closes the share sheet', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);
    $code = shareFinishedFor($user, 'en');
    $this->actingAs($user);

    $page = visit("/play/{$code}")->withLocale('en')->resize(390, 844);
    shareWait($page, "document.querySelector('[data-test=share-result]')");
    $page->script("() => { window.__asked = 0; window.__downloads = 0; Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true });
        Object.defineProperty(navigator, 'share', { value: async () => { window.__asked++; const error = new Error('closed'); error.name = 'AbortError'; throw error; }, configurable: true });
        const click = HTMLAnchorElement.prototype.click; HTMLAnchorElement.prototype.click = function () { if (this.download) { window.__downloads++; return; } return click.call(this); }; }");

    $page->click('[data-test=share-button]');
    shareWait($page, 'window.__asked === 1', 15, 'the share sheet');
    $page->wait(1);

    expect($page->script('() => window.__downloads'))->toBe(0)->and($page->script("() => document.querySelector('[data-test=share-status]').textContent.trim()"))->toBe('');
});

it('lets nothing move under reduced motion: the status appears without its fade', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);
    $code = shareFinishedFor($user, 'en');
    $this->actingAs($user);

    $page = visit("/play/{$code}", ['reducedMotion' => 'reduce'])->withLocale('en')->resize(390, 844);
    shareWait($page, "document.querySelector('[data-test=share-result]')");
    $page->script("() => { Object.defineProperty(navigator, 'canShare', { value: () => false, configurable: true });
        HTMLAnchorElement.prototype.click = function () {}; }");

    $page->click('[data-test=share-button]');
    shareWait($page, "document.querySelector('[data-test=share-status]').textContent.trim() !== ''", 15, 'the status line');

    expect($page->script("() => getComputedStyle(document.querySelector('[data-test=share-status]')).animationName"))->toBe('none');
});
