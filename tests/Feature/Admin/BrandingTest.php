<?php

use App\Models\Admin;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('uses coral as the primary colour, with an accessible shade 600 for buttons and links', function () {
    $primary = Filament::getPanel('admin')->getColors()['primary'];

    expect($primary[500])->toStartWith('oklch(')                        // generated from coral, #FF5A5F
        ->and($primary[600])->toBe('#C2343A')                           // coral-700
        ->and(Color::calculateContrastRatio('#FFFFFF', $primary[600]))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
});

it('uses IBM Plex Sans Arabic and the Lamma logo for light and dark mode', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getFontFamily())->toBe('IBM Plex Sans Arabic')
        ->and($panel->getBrandLogo())->toEndWith('brand/lamma-logo-horizontal.svg')
        ->and($panel->getDarkModeBrandLogo())->toEndWith('brand/lamma-logo-horizontal-dark.svg')
        ->and($panel->getFavicon())->toEndWith('favicon.svg')
        ->and(public_path('brand/lamma-logo-horizontal.svg'))->toBeFile()
        ->and(public_path('brand/lamma-logo-horizontal-dark.svg'))->toBeFile();
});

it('renders the admin login with the brand logo, the font and the coral palette', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('brand/lamma-logo-horizontal.svg', false)
        ->assertSee('brand/lamma-logo-horizontal-dark.svg', false)
        ->assertSee('IBM Plex Sans Arabic', false)
        ->assertSee('ibm-plex-sans-arabic', false)                      // the font stylesheet
        ->assertSee('--primary-600:oklch(0.544', false)                 // coral-700 as shade 600
        ->assertDontSee('--primary-600:oklch(0.6', false);              // not Filament's generated, lighter 600
});

it('keeps the admin panel in English whatever language the visitor uses', function () {
    $response = $this->actingAs(Admin::factory()->create(), 'admin')
        ->withSession(['locale' => 'ar'])->get('/admin')
        ->assertOk();

    expect($response->getContent())->toMatch('/<html\s+lang="en"/');
});
