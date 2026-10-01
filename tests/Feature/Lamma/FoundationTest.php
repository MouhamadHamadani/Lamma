<?php

use Illuminate\Support\Facades\Blade;

it('sets lang and dir on the Lamma layout from the current locale', function (string $locale, string $dir) {
    app()->setLocale($locale);

    expect(Blade::render('<x-layouts::lamma>hi</x-layouts::lamma>'))
        ->toContain("<html lang=\"{$locale}\" dir=\"{$dir}\"")
        ->toContain('bg-cream');
})->with([['ar', 'rtl'], ['en', 'ltr']]);

it('shows the current locale as selected and links to the other one', function () {
    app()->setLocale('en');

    expect(Blade::render('<x-lamma.language-switcher />'))
        ->toContain('aria-current="true"')
        ->toContain(route('locale.switch', 'ar'))
        ->not->toContain(route('locale.switch', 'en'));
});

it('ships the brand files and app icons', function (string $file) {
    expect(public_path($file))->toBeFile();
})->with([
    'brand/lamma-icon.svg', 'brand/lamma-logo-horizontal.svg', 'brand/lamma-logo-horizontal-dark.svg',
    'brand/lamma-logo-stacked.svg', 'brand/lamma-logo-stacked-dark.svg', 'brand/lamma-app-icon.svg',
    'favicon.svg', 'favicon.ico', 'apple-touch-icon.png',
]);

it('loads the brand fonts and theme tokens', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain("@import './lamma-theme.css'");
    expect(file_get_contents(base_path('vite.config.js')))->toContain('Baloo Bhaijaan 2')->toContain('IBM Plex Sans Arabic');
    expect(file_get_contents(resource_path('css/lamma-theme.css')))->toContain('--font-display')->toContain('--color-coral');
});
