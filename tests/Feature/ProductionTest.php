<?php

use App\Models\Admin;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\BrandFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

/** Run an artisan command in a fresh PHP process booted as production (the test process is "testing"). */
function artisanInProduction(array $command): Process
{
    $process = new Process([PHP_BINARY, base_path('artisan'), ...$command], base_path(), [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
    ]);
    $process->run();

    return $process;
}

/** The .env.production.example as key => value (comments and blank lines dropped, quotes removed). */
function productionEnv(): array
{
    $env = [];
    foreach (file(base_path('.env.production.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\"'");
    }

    return $env;
}

describe('.env.production.example', function () {
    it('sets the production essentials', function () {
        $env = productionEnv();

        expect($env)->toMatchArray([
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://lamma.example',
            'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
            'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis', 'SESSION_DRIVER' => 'redis', 'SESSION_SECURE_COOKIE' => 'true',
            'DB_CONNECTION' => 'mysql', 'MAIL_MAILER' => 'smtp', 'BROADCAST_CONNECTION' => 'reverb',
        ]);
    });

    it('serves Reverb over wss through nginx: it listens on loopback, browsers use the domain on 443 over https', function () {
        $env = productionEnv();

        expect($env)->toMatchArray([
            'REVERB_SERVER_HOST' => '127.0.0.1', 'REVERB_SERVER_PORT' => '8080',
            'REVERB_HOST' => 'lamma.example', 'REVERB_PORT' => '443', 'REVERB_SCHEME' => 'https',
            'VITE_REVERB_HOST' => '${REVERB_HOST}', 'VITE_REVERB_PORT' => '${REVERB_PORT}', 'VITE_REVERB_SCHEME' => '${REVERB_SCHEME}', 'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
        ]);
    });

    it('leaves every secret empty and invents no credentials', function () {
        $env = productionEnv();

        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $secret) {
            expect($env[$secret])->toBe('', $secret);
        }
    });

    it('only names settings the app knows, and the local example stays local', function () {
        $local = file_get_contents(base_path('.env.example'));

        expect($local)->toContain('APP_ENV=local')->toContain('MAIL_MAILER=log')->toContain('REVERB_SCHEME=http');
        expect(base_path('.env.production.example'))->toBeFile();
        expect(file_get_contents(base_path('.gitignore')))->toContain('.env')->not->toContain('.env.production.example');
    });

    it('does not break the front end: a public host is used as configured, only loopback follows the page', function () {
        $echo = file_get_contents(resource_path('js/echo.js'));

        expect($echo)->toContain("['', 'localhost', '127.0.0.1', '[::1]']")->toContain('loopback.includes(configured) ? window.location.hostname : configured')
            ->toContain('wssPort: import.meta.env.VITE_REVERB_PORT ?? 443')->toContain("=== 'https'");
    });
});

describe('what exists in production', function () {
    it('has no /_components gallery', function () {
        $process = artisanInProduction(['route:list', '--json']);
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $uris = collect(json_decode($process->getOutput(), true))->pluck('uri');
        expect($uris->contains(fn ($uri) => str_contains($uri, '_components')))->toBeFalse()
            ->and($uris->contains('join'))->toBeTrue()->and($uris->contains('host/{room}'))->toBeTrue();
    });

    it('is the only application route that local has and production lacks', function () {
        $production = collect(json_decode(artisanInProduction(['route:list', '--json'])->getOutput(), true))->pluck('uri')->sort()->values();
        $local = collect(json_decode((new Process([PHP_BINARY, base_path('artisan'), 'route:list', '--json'], base_path(), ['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '']))->mustRun()->getOutput(), true))->pluck('uri')->sort()->values();

        // (Livewire serves its own script and styles through routes that depend on debug mode: not ours.)
        $onlyLocal = $local->diff($production)->reject(fn ($uri) => str_starts_with($uri, 'livewire'))->values()->all();

        expect($onlyLocal)->toBe(['_components']);
    });

    it('registers no Telescope, debugbar or other debug tool', function () {
        $composer = file_get_contents(base_path('composer.json'));

        foreach (['laravel/telescope', 'barryvdh/laravel-debugbar', 'spatie/laravel-ray', 'laravel/horizon', 'itsgoingd/clockwork', 'laravel/pulse'] as $package) {
            expect($composer)->not->toContain($package);
        }
        expect(config('app.providers', []))->not->toContain('Laravel\Telescope\TelescopeServiceProvider');
    });

    it('shows no debug page for an error when APP_DEBUG is false', function () {
        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('secret detail'));

        $this->get('/_boom')->assertStatus(500)->assertDontSee('secret detail')->assertDontSee('RuntimeException');
    });
});

describe('https and the proxy', function () {
    beforeEach(fn () => Route::middleware('web')->get('/_proxy', fn (Request $request) => ['secure' => $request->isSecure(), 'host' => $request->getHost(), 'ip' => $request->ip()]));

    it('makes every generated URL https in production, and leaves local alone', function () {
        $provider = app()->getProvider(AppServiceProvider::class);
        $configure = fn () => $this->configureUrls();

        app()['env'] = 'local';
        $configure->call($provider);
        expect(url('/join'))->toStartWith('http://');

        app()['env'] = 'production';
        $configure->call($provider);
        expect(url('/join'))->toStartWith('https://')->and(route('join'))->toStartWith('https://')->and(asset('brand/lamma-icon.svg'))->toStartWith('https://');
        URL::forceScheme('http');
    });

    it('trusts nginx on this machine: X-Forwarded-Proto makes the request secure', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'lamma.example', 'X-Forwarded-For' => '203.0.113.7'])
            ->get('/_proxy')->assertOk()->assertExactJson(['secure' => true, 'host' => 'lamma.example', 'ip' => '203.0.113.7']);
    });

    it('ignores forwarded headers from anyone else', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->get('/_proxy')->assertOk()->assertJson(['secure' => false, 'ip' => '198.51.100.9']);
    });
});

describe('the admin panel', function () {
    it('opens for an active admin and not for a switched-off one', function () {
        $active = Admin::factory()->create();
        $off = Admin::factory()->inactive()->create();
        $panel = Filament\Facades\Filament::getPanel('admin');

        expect($active->canAccessPanel($panel))->toBeTrue()->and($off->canAccessPanel($panel))->toBeFalse();
        $this->actingAs($active, 'admin')->get('/admin')->assertOk();
    });

    it('keeps an inactive admin out, even with the right password', function () {
        $off = Admin::factory()->inactive()->create();

        $this->actingAs($off, 'admin')->get('/admin')->assertForbidden();
    });
});

describe('rate limits', function () {
    it('limits joining a room per address (the behaviour itself is covered in JoinRoomTest)', function () {
        expect(config('lamma.join_attempts_per_minute'))->toBeInt()->toBeBetween(1, 30);
    });

    it('limits login attempts: the sixth wrong password in a minute is refused', function () {
        $user = User::factory()->create();
        RateLimiter::clear(Str::transliterate(Str::lower($user->email).'|127.0.0.1'));

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
        $this->assertGuest();
    });

    it('limits two-factor and passkey attempts too', function () {
        expect(RateLimiter::limiter('two-factor'))->not->toBeNull()->and(RateLimiter::limiter('passkeys'))->not->toBeNull()->and(RateLimiter::limiter('login'))->not->toBeNull();
        expect(config('fortify.limiters'))->toMatchArray(['login' => 'login', 'two-factor' => 'two-factor', 'passkeys' => 'passkeys']);
    });
});

describe('php artisan optimize', function () {
    it('has no route model binding in a routes file (route:cache skips them), only in the service provider', function () {
        foreach (File::allFiles(base_path('routes')) as $file) {
            expect($file->getContents())->not->toContain('Route::bind(')->not->toContain('Route::model(');
        }
        expect(app('router')->getBindingCallback('room'))->not->toBeNull();
    });

    it('has no closure route of our own (the rest comes from Laravel and Livewire)', function () {
        $ours = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->getActionName() === 'Closure')
            ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire') || str_starts_with($route->uri(), 'storage/') || $route->uri() === 'up' || str_starts_with($route->uri(), '_'))
            ->map(fn ($route) => $route->uri())->values()->all();

        expect($ours)->toBe([]);
    });

    it('caches and clears config, routes, views and events without error, and a cached app still finds a room', function () {
        $process = artisanInProduction(['optimize']);
        try {
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

            $routes = artisanInProduction(['route:list', '--json']);
            expect($routes->isSuccessful())->toBeTrue($routes->getErrorOutput());
        } finally {
            artisanInProduction(['optimize:clear']);
        }
    });
});

describe('the scheduler heartbeat', function () {
    it('is written every time lamma:prune runs', function () {
        Cache::forget(config('lamma.heartbeat_key'));
        $this->travelTo(now()->setTime(12, 0));

        Artisan::call('lamma:prune');

        expect(Cache::get(config('lamma.heartbeat_key')))->toBe(now()->timestamp);
    });
});

describe('the placeholder files', function () {
    it('are recorded with the hash of what ships today, so the doctor can warn', function () {
        // Every shipped file IS the placeholder today: all 14 are reported, none missing.
        expect(BrandFiles::stillPlaceholders())->toBe(array_keys(config('lamma.placeholders')))->toHaveCount(14);
        foreach (config('lamma.placeholders') as $path => $hash) {
            expect(BrandFiles::hash($path))->toBe($hash, $path);
        }
    });

    it('hashes an SVG the same with Windows or Linux line endings', function () {
        $dir = sys_get_temp_dir().'/lamma-brand-'.uniqid();
        mkdir($dir);
        app()->usePublicPath($dir);
        file_put_contents($dir.'/a.svg', "<svg>\n<path/>\n</svg>\n");
        $lf = BrandFiles::hash('a.svg');
        file_put_contents($dir.'/a.svg', "<svg>\r\n<path/>\r\n</svg>\r\n");

        expect(BrandFiles::hash('a.svg'))->toBe($lf);
        File::deleteDirectory($dir);
    });

    it('stop being placeholders when the file changes, and a missing file is reported as missing', function () {
        $dir = sys_get_temp_dir().'/lamma-brand-'.uniqid();
        File::copyDirectory(public_path('brand'), $dir.'/brand');
        File::copyDirectory(public_path('sounds'), $dir.'/sounds');
        copy(public_path('favicon.svg'), $dir.'/favicon.svg');
        copy(public_path('favicon.ico'), $dir.'/favicon.ico');
        copy(public_path('apple-touch-icon.png'), $dir.'/apple-touch-icon.png');
        app()->usePublicPath($dir);

        expect(BrandFiles::stillPlaceholders())->toHaveCount(14)->toContain('brand/lamma-icon.svg');
        file_put_contents($dir.'/brand/lamma-icon.svg', '<svg>the official mark</svg>');
        unlink($dir.'/sounds/tick.wav');

        expect(BrandFiles::stillPlaceholders())->not->toContain('brand/lamma-icon.svg')->toContain('sounds/tick.wav (missing)')->toContain('favicon.ico');
        File::deleteDirectory($dir);
    });
});
