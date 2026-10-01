<?php

use Illuminate\Support\Facades\Route;

beforeEach(fn () => app()->setLocale('en'));

/** Pretend the app booted in the local environment and register the routes file again. */
function bootLocalRoutes(): void
{
    app()['env'] = 'local';
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::getRoutes()->refreshNameLookups();
}

it('does not expose the component gallery outside the local environment', function () {
    expect(Route::has('dev.components'))->toBeFalse();

    $this->get('/_components')->assertNotFound();
    $this->get('/dev/components')->assertNotFound();
});

it('serves the component gallery at /_components when the environment is local', function () {
    bootLocalRoutes();

    $this->get('/_components')
        ->assertOk()
        ->assertSee('English (LTR)')
        ->assertSee('العربية (RTL)')
        ->assertSee('role="timer"', false);
    expect(route('dev.components', absolute: false))->toBe('/_components');
});

it('renders every component in English and Arabic on the gallery, and leaves the locale alone', function () {
    $html = view('dev.components')->render();

    expect($html)
        ->toContain('<html lang="en" dir="ltr"')
        ->toContain('lang="ar" dir="rtl"')
        ->toContain('aria-label="Answer B: Mars"')
        ->toContain('aria-label="الإجابة B: المريخ"')
        ->not->toContain('<x-lamma');
    expect(app()->getLocale())->toBe('en');
});

it('lists every Lamma component in the gallery', function (string $file) {
    $name = basename($file, '.blade.php');

    expect(file_get_contents(resource_path('views/dev/components.blade.php')))->toContain("<x-lamma.{$name}");
})->with(fn () => glob(__DIR__.'/../../../resources/views/components/lamma/*.blade.php'));

it('shows every answer state and size and both timers on the gallery', function () {
    $source = file_get_contents(resource_path('views/dev/components.blade.php'));

    foreach (['selected', 'locked', 'correct', 'faded', 'size="phone"', '<x-lamma.timer-ring', '<x-lamma.timer-bar', 'disabled', 'variant="ghost"'] as $needle) {
        expect($source)->toContain($needle);
    }
});
