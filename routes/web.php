<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Livewire\Host\CreateRoom;
use App\Livewire\Player\JoinRoom;
use App\Models\Room;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', LandingController::class)->name('home');

// Visual check of the Lamma Blade components in EN and AR; never registered outside local.
if (app()->environment('local')) {
    Route::view('_components', 'dev.components')->name('dev.components');
}

// {room} is a room code, in any letter case.
Route::bind('room', fn (string $code) => Room::where('code', Str::upper($code))->firstOrFail());

Route::livewire('rooms/create', CreateRoom::class)->middleware('auth')->name('rooms.create');
Route::livewire('join', JoinRoom::class)->name('join');
Route::get('host/{room}', fn (Room $room) => $room->code)->middleware('auth')->name('host.lobby'); // replaced by Host\HostLobby
Route::get('play/{room}', fn (Room $room) => $room->code)->name('play'); // replaced by Player\PlayerLobby

Route::get('locale/{locale}', LocaleController::class)->name('locale.switch');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
