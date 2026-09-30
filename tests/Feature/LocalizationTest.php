<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;

beforeEach(function () {
    // Probe route behind the real `web` group (and therefore SetLocale).
    Route::middleware('web')->get('/__locale', fn () => app()->getLocale());
    config(['app.locale' => 'ar']);
});

describe('locale switch route', function () {
    it('stores the locale in the session and redirects back', function () {
        $this->from('/login')->get(route('locale.switch', 'en'))
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'en');
    });

    it('also saves the locale on a logged-in user', function () {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $this->actingAs($user)->get(route('locale.switch', 'en'));

        expect($user->fresh()->preferred_locale)->toBe('en');
    });

    it('rejects unsupported locales', function () {
        $this->get('/locale/fr')->assertNotFound();
        $this->get('/locale/xx')->assertNotFound();
    });
});

describe('SetLocale precedence', function () {
    it('prefers the session over everything', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        $this->actingAs($user)
            ->withSession(['locale' => 'ar'])
            ->withHeaders(['Accept-Language' => 'en'])
            ->get('/__locale')->assertSeeText('ar');
    });

    it("uses the user's preferred_locale before Accept-Language", function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'ar'])
            ->get('/__locale')->assertSeeText('en');
    });

    it('uses Accept-Language when there is no session or user', function () {
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/__locale')->assertSeeText('en');
    });

    it('skips unsupported Accept-Language entries', function () {
        $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'])
            ->get('/__locale')->assertSeeText('en');
    });

    // The test client sends Accept-Language: en-us by default, so blank it out for these two.
    it('falls back to the app default', function () {
        $this->withHeaders(['Accept-Language' => ''])->get('/__locale')->assertSeeText('ar');
        $this->withHeaders(['Accept-Language' => 'fr'])->get('/__locale')->assertSeeText('ar');
    });

    it('ignores an unsupported locale in the session', function () {
        $this->withSession(['locale' => 'fr'])->withHeaders(['Accept-Language' => ''])->get('/__locale')->assertSeeText('ar');
    });
});

describe('layout direction', function () {
    it('renders Arabic pages right-to-left', function () {
        $this->withSession(['locale' => 'ar'])->get('/')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl"', false);
    });

    it('renders English pages left-to-right', function () {
        $this->withSession(['locale' => 'en'])->get('/')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr"', false);
    });

    it('renders the auth pages in Arabic after switching', function () {
        $this->get(route('locale.switch', 'ar'));

        $this->get(route('login'))
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('Log in', [], 'ar'));
    });
});

it('formats numbers with Western digits in Arabic', function () {
    app()->setLocale('ar');

    expect(Number::format(1234567))->toMatch('/^[0-9,]+$/')
        ->and(now()->subMinutes(5)->diffForHumans())->toMatch('/[0-9]/');
});

describe('translation files', function () {
    $ui = fn (string $locale): array => json_decode(file_get_contents(lang_path("{$locale}.json")), true);

    it('has the same keys in ar.json and en.json', function () use ($ui) {
        expect(array_keys($ui('ar')))->toEqualCanonicalizing(array_keys($ui('en')));
    });

    it('has a non-empty Arabic string for every key', function () use ($ui) {
        expect(array_filter($ui('ar'), fn ($value) => trim((string) $value) === ''))->toBeEmpty();
    });

    it('covers every literal __() key used in app/, views and routes', function () use ($ui) {
        $used = [];
        foreach ([app_path(), resource_path('views'), base_path('routes')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php') || str_contains($file->getPathname(), 'app'.DIRECTORY_SEPARATOR.'Filament')) {
                    continue; // Filament admin is English-only
                }
                preg_match_all('/(?:__|trans)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $key = ($match[1] ?? '') !== '' ? str_replace("\\'", "'", $match[1]) : ($match[2] ?? '');
                    // "group.key" style strings live in lang/<locale>/<group>.php, not in the JSON files
                    if ($key !== '' && ! preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $key)) {
                        $used[$key] = true;
                    }
                }
            }
        }

        expect(array_diff(array_keys($used), array_keys($ui('ar'))))->toBeEmpty()
            ->and(array_diff(array_keys($used), array_keys($ui('en'))))->toBeEmpty();
    });
});
