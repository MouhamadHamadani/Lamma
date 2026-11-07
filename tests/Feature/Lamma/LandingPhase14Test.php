<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\File;

/*
 * The landing page after Phase 14: the demo button, the "free for now" line, the optional how-it-works clip, and the fixes a browser check found (each phone of the
 * bilingual demo is tagged with its own language, the two navigations are labelled, nothing untagged in the wrong language).
 */

/** The page as a DOM, for questions a string match cannot answer ("what language is the element this text is in?"). */
function landingDom(string $locale): DOMXPath
{
    $html = test()->withSession(['locale' => $locale])->get('/')->assertOk()->getContent();
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();

    return new DOMXPath($dom);
}

function howItWorksFiles(array $files): void
{
    File::ensureDirectoryExists(public_path('media'));
    foreach ($files as $name) {
        File::put(public_path("media/{$name}"), 'x');
    }
}

afterEach(function () {
    foreach (['how-it-works.mp4', 'how-it-works.jpg', 'how-it-works.png', 'how-it-works.en.vtt', 'how-it-works.ar.vtt'] as $name) {
        File::delete(public_path("media/{$name}"));
    }
    if (is_dir(public_path('media')) && count(File::files(public_path('media'))) === 0) {
        File::deleteDirectory(public_path('media'));
    }
});

describe('the buttons', function () {
    it('has "Try a demo" next to "Host a game", pointing to /demo, in both languages', function (string $locale, string $demo, string $host) {
        $page = $this->withSession(['locale' => $locale])->get('/')->assertOk();

        $page->assertSee('data-test="demo-button"', false)->assertSee($demo)->assertSee('href="'.route('demo').'"', false)
            ->assertSee('data-test="host-button"', false)->assertSee($host);
        expect(route('demo', absolute: false))->toBe('/demo');
    })->with([
        'english' => ['en', 'Try a demo', 'Host a game'],
        'arabic' => ['ar', 'جرّب نسخة تجريبية', 'استضف لعبة'],
    ]);

    it('sends the Host button to room creation for everyone: a guest is sent to log in by the page itself and comes back', function () {
        $this->withSession(['locale' => 'en'])->get('/')->assertSee('data-test="host-button"', false)->assertSee('href="'.route('rooms.create').'"', false);
        $this->get('/rooms/create')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->withSession(['locale' => 'en'])->get('/')->assertSee('href="'.route('rooms.create').'"', false);
    });
});

describe('the free line', function () {
    it('says hosting and playing are free for now, near both Host buttons, in both languages', function (string $locale, string $line) {
        $html = $this->withSession(['locale' => $locale])->get('/')->assertOk()->getContent();

        expect(substr_count($html, $line))->toBe(2)->and($html)->toContain('data-test="free-line"');
    })->with([
        'english' => ['en', 'Hosting and playing are free for now.'],
        'arabic' => ['ar', 'الاستضافة واللعب مجانيان في الوقت الحالي.'],
    ]);

    it('is a flag: lamma.landing.show_free_line, on by default', function () {
        expect(config('lamma.landing.show_free_line'))->toBeTrue();

        config(['lamma.landing.show_free_line' => false]);
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertDontSee('free for now')->assertDontSee('data-test="free-line"', false);
    });

    it('keeps the words in the lang files, in both languages', function () {
        foreach (['en', 'ar'] as $locale) {
            expect(json_decode(file_get_contents(lang_path("{$locale}.json")), true))->toHaveKey('Hosting and playing are free for now.');
        }
    });
});

describe('"See how it works"', function () {
    it('scrolls to the three steps when there is no clip, with no dialog and no video', function () {
        $page = $this->withSession(['locale' => 'en'])->get('/')->assertOk();

        $page->assertSee('data-test="how-link"', false)->assertSee('href="#how"', false)->assertSee('id="how"', false)
            ->assertDontSee('how-video')->assertDontSee('<video', false)->assertDontSee('open-dialog');
    });

    it('opens the clip in a dialog when public/media/how-it-works.mp4 exists, and still scrolls without JavaScript', function () {
        howItWorksFiles(['how-it-works.mp4']);

        $page = $this->withSession(['locale' => 'en'])->get('/')->assertOk();

        $page->assertSee('href="#how"', false)->assertSee("\$dispatch('open-dialog', { name: 'how-video' })", false)
            ->assertSee('<dialog', false)->assertSee('data-test="how-video"', false)->assertSee(asset('media/how-it-works.mp4'), false)
            ->assertSee('preload="none"', false)->assertDontSee('poster=', false)->assertDontSee('<track', false);
    });

    it('uses a poster and captions when they exist, the captions of the page language by default', function () {
        howItWorksFiles(['how-it-works.mp4', 'how-it-works.jpg', 'how-it-works.en.vtt', 'how-it-works.ar.vtt']);

        $html = $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->getContent();

        expect($html)->toContain('poster="'.asset('media/how-it-works.jpg').'"')->and($html)->toContain('<track kind="captions" src="'.asset('media/how-it-works.en.vtt').'" srclang="en"')
            ->and($html)->toMatch('/<track kind="captions" src="[^"]+how-it-works\.ar\.vtt" srclang="ar" label="العربية"\s+default\s*>/')->and($html)->not->toMatch('/srclang="en"[^>]*\sdefault\s*>/');
    });
});

describe('what a browser check found', function () {
    it('tags the English phone and card as English on the Arabic page, and the Arabic ones as Arabic on the English page', function () {
        $arabic = landingDom('ar');
        foreach (['Answer locked in', 'Which planet is known as the Red Planet?'] as $english) {
            expect($arabic->query('//*[text()[contains(., "'.$english.'")]]/ancestor::*[@lang][1]/@lang')->item(0)->nodeValue)->toBe('en');
        }

        $english = landingDom('en');
        foreach (['اختر إجابتك', 'أي كوكب يُعرف بالكوكب الأحمر؟'] as $text) {
            expect($english->query('//*[text()[contains(., "'.$text.'")]]/ancestor::*[@lang][1]/@lang')->item(0)->nodeValue)->toBe('ar');
        }
    });

    it('has no English text on the Arabic page outside an element tagged lang="en", apart from names and codes', function () {
        $dom = landingDom('ar');
        $untagged = [];

        foreach ($dom->query('//body//text()[normalize-space()][not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]') as $node) {
            $text = trim($node->nodeValue);
            if (preg_match('/[A-Za-z]{3,}/', $text) !== 1) {
                continue;
            }
            $language = $dom->query('ancestor::*[@lang][1]/@lang', $node)->item(0)?->nodeValue;
            $words = array_diff(preg_split('/[^A-Za-z0-9]+/', preg_replace('/(?<![A-Za-z0-9])[0-9]+(?![A-Za-z0-9])/', '', $text), -1, PREG_SPLIT_NO_EMPTY), ['Lamma', 'EN', 'K7MP', 'Sara', 'Ali', 'Maya']);
            if ($language !== 'en' && $words !== []) {
                $untagged[] = $text;
            }
        }

        expect($untagged)->toBe([]);
    });

    it('separates a phone\'s name, its language and its timer, and says which language each phone is in', function () {
        $html = $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->getContent();

        expect($html)->toContain('<span class="truncate">Sara</span><span class="rounded-md bg-tint-navy px-1.5 py-px text-[9px] text-navy">EN</span>')
            ->and($html)->toContain('<span class="truncate">علي</span><span class="rounded-md bg-tint-navy px-1.5 py-px text-[9px] text-navy">ع</span>')
            ->and($html)->toContain('lang="en" dir="ltr"')->and($html)->toContain('lang="ar" dir="rtl"');
    });

    it('has one labelled navigation in the header and one in the footer, and no copy of the phone menu until it is opened', function (string $locale, string $main, string $footer) {
        $dom = landingDom($locale);

        $labels = [];
        foreach ($dom->query('//nav[not(ancestor::template)]') as $nav) {
            $labels[] = $nav->getAttribute('aria-label');
        }

        expect($labels)->toBe([$main, $footer])->and($dom->query('//*[@id="mobile-menu"][not(ancestor::template)]')->length)->toBe(0);
    })->with([
        'english' => ['en', 'Main menu', 'Footer menu'],
        'arabic' => ['ar', 'القائمة الرئيسية', 'قائمة أسفل الصفحة'],
    ]);

    it('gives every link that holds a logo a name, in both languages', function (string $locale) {
        Category::factory()->create();
        $dom = landingDom($locale);

        $logoLinks = $dom->query('//a[.//img]');
        expect($logoLinks->length)->toBeGreaterThan(0);
        foreach ($logoLinks as $link) {
            expect(trim(preg_replace('/\s+/', ' ', $link->textContent)))->not->toBe('');
        }
    })->with(['en', 'ar']);

    it('hides the wordmark on a narrow phone from the eye only, never from a screen reader', function () {
        foreach (['player-game', 'player-lobby', 'player-results'] as $screen) {
            $view = file_get_contents(resource_path("views/livewire/player/{$screen}.blade.php"));

            expect($view)->toContain('max-[379px]:[&>span]:sr-only')->and($view)->not->toContain('max-[379px]:[&>span]:hidden');
        }
    });

    it('names the logo in the e-mail header, for clients that block images', function () {
        expect(file_get_contents(resource_path('views/vendor/mail/html/header.blade.php')))->toContain("alt=\"{{ config('app.name') }}\"");
    });
});
