<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', LandingController::class)->name('home');

// Visual check of the Lamma Blade components in EN and AR; never registered outside local.
if (app()->environment('local')) {
    Route::view('_components', 'dev.components')->name('dev.components');
}

// Placeholders until Phase 3 builds the real screens; the landing page already links here.
Route::get('join', fn (Request $request) => view('placeholder', [
    'title' => __('Join a game'),
    'code' => (string) Str::of((string) $request->query('code'))->upper()->replaceMatches('/[^A-Z0-9]/', '')->limit(6, ''),
]))->name('join');
Route::get('rooms/create', fn () => view('placeholder', ['title' => __('Host a game'), 'code' => null]))->middleware('auth')->name('rooms.create');

Route::get('locale/{locale}', LocaleController::class)->name('locale.switch');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
