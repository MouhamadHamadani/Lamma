<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\SaveScoreController;
use App\Livewire\Host\CreateRoom;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\JoinRoom;
use App\Livewire\Player\MyGames;
use App\Livewire\Player\PlayerLobby;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// Visual check of the Lamma Blade components in EN and AR; never registered outside local.
if (app()->environment('local')) {
    Route::view('_components', 'dev.components')->name('dev.components');
}

Route::livewire('rooms/create', CreateRoom::class)->middleware('auth')->name('rooms.create');
Route::livewire('join', JoinRoom::class)->name('join');
// The host screen: only the room's host. The phone screen: only the room's participants (user or guest token).
Route::livewire('host/{room}', HostLobby::class)->middleware(['auth', 'can:host,room'])->name('host.lobby');
Route::livewire('play/{room}', PlayerLobby::class)->middleware('room.player')->name('play');
// "Save your score" on the results screen: log in or sign up, then come back to the results page (SaveScoreController).
Route::get('play/{room}/save/{action}', SaveScoreController::class)->whereIn('action', ['login', 'register'])->middleware('room.player')->name('play.save');

// The logged-in user's saved games and totals.
Route::livewire('me/games', MyGames::class)->middleware('auth')->name('me.games');

Route::get('locale/{locale}', LocaleController::class)->name('locale.switch');

require __DIR__.'/settings.php';
