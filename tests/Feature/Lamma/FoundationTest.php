<?php

use Illuminate\Support\Facades\Blade;

it('sets lang and dir on the Lamma layout from the current locale', function (string $locale, string $dir) {
    app()->setLocale($locale);

    expect(Blade::render('<x-layouts::lamma>hi</x-layouts::lamma>'))
        ->toContain("<html lang=\"{$locale}\" dir=\"{$dir}\"")
        ->toContain('bg-cream');
})->with([['ar', 'rtl'], ['en', 'ltr']]);

it('shows only the other language on the toggle and links to the locale route', function (string $current, string $other, string $label) {
    app()->setLocale($current);

    $html = Blade::render('<x-lamma.language-switcher />');

    expect($html)
        ->toContain('href="'.route('locale.switch', $other).'"')
        ->toContain(">{$label}</a>")
        ->toContain('lang="'.$other.'"')
        ->not->toContain(route('locale.switch', $current))
        ->and(substr_count($html, 'hreflang='))->toBe(1);
})->with([['en', 'ar', 'عربي'], ['ar', 'en', 'EN']]);

it('renders the toggle on both pages and switching sends the visitor back', function () {
    $this->withSession(['locale' => 'en'])->get('/')->assertSee('>عربي</a>', false)->assertDontSee('>EN</a>', false);
    $this->withSession(['locale' => 'ar'])->get('/')->assertSee('>EN</a>', false)->assertDontSee('>عربي</a>', false);

    $this->from('/')->get(route('locale.switch', 'ar'))->assertRedirect('/')->assertSessionHas('locale', 'ar');
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
    expect(file_get_contents(resource_path('css/lamma-theme.css')))->toContain('--font-display')->toContain('--color-coral')
        ->toContain(':lang(ar) { letter-spacing: 0 !important; text-transform: none !important; }');
});
