<?php

use App\Models\User;
use App\Support\ErrorPage;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // Real error pages, not the debug page; and no noise in the log file.
    config(['app.debug' => false, 'logging.default' => 'null']);

    // A built front end is what the pages link to; never the dev server a developer may have running.
    Vite::useHotFile(storage_path('framework/no-hot-file'));

    Route::middleware('web')->group(function () {
        Route::get('/_abort/{code}', fn (int $code) => abort($code));
        Route::get('/_expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
        Route::get('/_boom', fn () => throw new RuntimeException('boom'));
        Route::get('/_query', fn () => User::count());
    });
});

/** Request $url as a browser that speaks $locale (via the session, which is how the language switcher remembers it). */
function pageIn(string $locale, string $url)
{
    return test()->withSession(['locale' => $locale])->get($url);
}

dataset('pages', [
    '403' => [403, ['en' => 'You can’t go here', 'ar' => 'لا يمكنك دخول هذه الصفحة']],
    '404' => [404, ['en' => 'Page not found', 'ar' => 'الصفحة غير موجودة']],
    '419' => [419, ['en' => 'Page expired', 'ar' => 'انتهت صلاحية الصفحة']],
    '429' => [429, ['en' => 'Too many attempts', 'ar' => 'محاولات كثيرة جداً']],
    '500' => [500, ['en' => 'Something went wrong', 'ar' => 'حدث خطأ ما']],
]);

describe('the error pages', function () {
    it('render in English and Arabic, in the Lamma style, with a way home and the language switch', function (int $code, array $titles) {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
            $other = $locale === 'en' ? 'ar' : 'en';

            pageIn($locale, "/_abort/{$code}")
                ->assertStatus($code)
                ->assertSee("<html lang=\"{$locale}\" dir=\"{$dir}\"", false)
                ->assertSee($titles[$locale])->assertDontSee($titles[$other])
                ->assertSee('data-test="error-code"', false)->assertSee(">{$code}</div>", false)
                ->assertSee('bg-cream', false)->assertSee('brand/lamma-icon.svg', false)->assertSee('shadow-sticker-lg', false)
                ->assertSee('href="'.route('home').'"', false)
                ->assertSee('data-test="locale-switch-'.$other.'"', false);
        }
    })->with('pages');

    it('say what happened in one line, and offer the right button', function () {
        pageIn('en', '/_abort/419')->assertSee('Refresh and try again')->assertSee('Your session timed out.');
        pageIn('ar', '/_abort/419')->assertSee('حدّث الصفحة وحاول مجدداً');
        pageIn('en', '/_abort/429')->assertSee('Please wait a moment, then try again.');
        pageIn('en', '/_abort/403')->assertSee('Back to home');
    });

    it('send "Refresh and try again" back to the page the visitor came from', function () {
        $this->withSession(['locale' => 'en'])->withHeader('Referer', url('/join'))->get('/_abort/419')->assertSee('href="'.url('/join').'"', false);
        $this->withSession(['locale' => 'en'])->withHeader('Referer', 'https://evil.example/phish')->get('/_abort/419')->assertDontSee('evil.example')->assertSee('href="'.route('home').'"', false);
    });

    it('show a page for any other client or server error too', function () {
        pageIn('en', '/_abort/405')->assertStatus(405)->assertSee('That didn’t work')->assertSee('>405</div>', false);
        pageIn('en', '/_abort/502')->assertStatus(502)->assertSee('Something went wrong')->assertSee('>502</div>', false);
        pageIn('ar', '/_abort/408')->assertStatus(408)->assertSee('لم ينجح ذلك');
        pageIn('en', '/_abort/401')->assertStatus(401)->assertSee('Log in to continue')->assertSee('href="'.route('login').'"', false);
    });

    it('answer an unknown address with the 404 page', function () {
        // No route matched, so no session either: the browser's language decides.
        $this->withHeader('Accept-Language', 'en-US')->get('/no/such/page')->assertNotFound()->assertSee('Page not found');
        $this->withHeader('Accept-Language', 'ar-LB')->get('/no/such/page')->assertNotFound()->assertSee('الصفحة غير موجودة');
    });

    it('answer a form whose token expired with the 419 page', function () {
        pageIn('en', '/_expired')->assertStatus(419)->assertSee('Page expired')->assertSee('Refresh and try again');
        pageIn('ar', '/_expired')->assertStatus(419)->assertSee('انتهت صلاحية الصفحة');
    });

    it('answer an unexpected exception with the 500 page, without leaking it', function () {
        pageIn('en', '/_boom')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('boom')->assertDontSee('RuntimeException');
    });

    it('load no script: no Livewire, no Echo, nothing that needs the network', function (int $code) {
        $html = pageIn('en', "/_abort/{$code}")->getContent();

        expect($html)->not->toContain('<script')->not->toContain('livewire')->not->toContain('Echo')->not->toContain('data-realtime')
            ->and(preg_match('/<form/i', $html))->toBe(0);
    })->with([403, 404, 419, 429, 500, 503]);
});

describe('a room that no longer exists', function () {
    it('shows the 404 page with a Join a game button, on the host and the phone address', function (string $path) {
        $this->actingAs(User::factory()->create());

        foreach (['en' => 'That room doesn’t exist', 'ar' => 'هذه الغرفة غير موجودة'] as $locale => $title) {
            pageIn($locale, $path)->assertNotFound()->assertSee($title)->assertSee('href="'.route('join').'"', false)
                ->assertSee($locale === 'en' ? 'Join a game' : 'انضم إلى لعبة');
        }
    })->with(['/host/ZZZZZZ', '/play/ZZZZZZ', '/play/ZZZZZZ/save/login']);

    it('keeps the plain wording for an ordinary missing page', function () {
        pageIn('en', '/nothing-here')->assertNotFound()->assertDontSee('Join a game');
    });
});

describe('which language the page speaks', function () {
    function requestWith(array $headers = []): Request
    {
        return Request::create('/x', 'GET', server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
    }

    it('takes the browser language when it is one of ours', function (string $header, string $expected) {
        expect(ErrorPage::locale(requestWith(['Accept-Language' => $header])))->toBe($expected);
    })->with([
        'English' => ['en-US,en;q=0.9', 'en'],
        'Arabic first' => ['ar-LB,ar;q=0.9,en;q=0.5', 'ar'],
        'French, which we do not speak' => ['fr-FR,fr;q=0.9', 'ar'],
        'nothing at all' => ['', 'ar'],
    ]);

    it('prefers the session to the browser, and what SetLocale decided to both', function () {
        $withSession = requestWith(['Accept-Language' => 'en-US']);
        $withSession->setLaravelSession(app('session')->driver());
        $withSession->session()->put('locale', 'ar');
        expect($withSession->hasSession())->toBeTrue()->and(ErrorPage::locale($withSession))->toBe('ar');

        $decided = requestWith(['Accept-Language' => 'en-US']);
        $decided->attributes->set('locale', 'ar');
        expect(ErrorPage::locale($decided))->toBe('ar');
    });

    it('follows the browser when there is no session, and Arabic when it says nothing', function () {
        $this->withHeader('Accept-Language', 'en-GB')->get('/_abort/404')->assertSee('<html lang="en" dir="ltr"', false)->assertSee('Page not found');
        $this->withHeader('Accept-Language', 'ar')->get('/_abort/404')->assertSee('<html lang="ar" dir="rtl"', false);
        $this->withHeader('Accept-Language', '')->get('/_abort/404')->assertSee('<html lang="ar" dir="rtl"', false)->assertSee('الصفحة غير موجودة');
    });

    it('follows the language the user switched to', function () {
        $this->get(route('locale.switch', 'en'));
        $this->get('/_abort/404')->assertSee('<html lang="en"', false);
        $this->get(route('locale.switch', 'ar'));
        $this->get('/_abort/404')->assertSee('<html lang="ar"', false);
    });
});

describe('when the infrastructure is down', function () {
    // The test database comes back before RefreshDatabase tears its transaction down.
    afterEach(function () {
        config(['database.default' => 'sqlite', 'session.driver' => 'array']);
        DB::purge('broken');
    });

    /** Point the default connection (and with $session the session store) at a MySQL that is not there. */
    function breakDatabase(bool $session = false): void
    {
        config([
            'database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'nope', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'options' => [PDO::ATTR_TIMEOUT => 1]],
            'database.default' => 'broken',
        ]);
        if ($session) {
            config(['session.driver' => 'database', 'session.connection' => 'broken']);
        }
        DB::purge('broken');
    }

    it('renders the 500 page with the database connection broken', function () {
        breakDatabase();

        $this->withSession([])->withHeader('Accept-Language', 'en')->get('/_query')
            ->assertStatus(500)->assertSee('Something went wrong')->assertSee('>500</div>', false)->assertDontSee('SQLSTATE');
    });

    it('renders every error page without a single query', function (int $code) {
        breakDatabase();
        (new RegisterErrorViewPaths)();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach (['en', 'ar'] as $locale) {
            expect(view("errors::{$code}", ['exception' => new HttpException($code)])->render())->toContain('data-test="error-code"');
            app()->setLocale($locale);
        }

        expect($queries)->toBe(0);
    })->with([403, 404, 419, 429, 500, 503]);

    it('renders the 500 page when the session store is down too, in the browser language', function () {
        breakDatabase(session: true);

        // An address no route matches never starts a session; one that does fails at the session store. Both still get a page.
        $this->withHeader('Accept-Language', 'en')->get('/no/such/page')->assertNotFound()->assertSee('Page not found');
        $this->withHeader('Accept-Language', 'ar')->get('/_query')->assertStatus(500)->assertSee('<html lang="ar" dir="rtl"', false)->assertSee('حدث خطأ ما');
        $this->withHeader('Accept-Language', 'en')->get('/_query')->assertStatus(500)->assertSee('<html lang="en" dir="ltr"', false)->assertSee('Something went wrong');
    });
});

describe('maintenance', function () {
    afterEach(fn () => Artisan::call('up'));

    it('is a bilingual page that carries its own CSS, served with a Retry-After', function () {
        Artisan::call('down', ['--render' => 'errors::503', '--retry' => 15]);

        $response = $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '15');
        $html = $response->getContent();

        expect($html)->toContain('We’ll be right back')->toContain('نعود بعد قليل')
            ->toContain('lang="ar"')->toContain('lang="en"')->toContain('>503</div>')
            ->not->toContain('<script')->not->toContain('data-test="locale-switch');
        // The built stylesheet is inside the page, not a <link> to a hashed file the deploy is about to replace.
        if (ErrorPage::inlineCss() !== null) {
            expect($html)->toContain('<style>')->not->toContain('rel="stylesheet"');
        }
    });

    it('is the page `php artisan down --render=errors::503` renders', function () {
        (new RegisterErrorViewPaths)();
        $html = view('errors::503')->render();

        expect($html)->toContain('<html lang="ar" dir="rtl"')->toContain('نعود بعد قليل')->toContain('We’ll be right back')->toContain('href="'.route('home').'"');
    });
});

describe('the translations', function () {
    it('has every error page string in both languages', function () {
        $keys = ['Error :code', 'Back to home', 'Join a game', 'You can’t go here', 'You don’t have permission to see this page.', 'That room doesn’t exist',
            'The room code may be wrong, or the host has closed the game.', 'Page not found', 'The page you’re looking for doesn’t exist or has moved.', 'Page expired',
            'Your session timed out. Refresh and try again.', 'Refresh and try again', 'Too many attempts', 'Please wait a moment, then try again.', 'Something went wrong',
            'It’s on us. Please try again in a moment.', 'We’ll be right back', 'Lamma is being updated. Try again in a minute.', 'That didn’t work', 'Something about that request wasn’t right.'];

        foreach (['en', 'ar'] as $locale) {
            $lines = json_decode(file_get_contents(lang_path("{$locale}.json")), true);
            foreach ($keys as $key) {
                expect($lines)->toHaveKey($key);
                expect($lines[$key])->not->toBe('');
            }
        }
    });
});
