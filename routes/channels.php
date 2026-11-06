<?php

use App\Game\RoomPresence;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The lobby's presence channel (presence-room.CODE): the room's host and its participants. Guests have no account,
// so besides the web guard the "player" guard (their lamma_guest cookie) may authenticate. It goes first: a device that has a row
// in this room is that player, which keeps the host's phone apart from their big screen (RoomPresence). Returns the member info.
Broadcast::channel(
    'room.{code}',
    fn ($user, string $code) => app(RoomPresence::class)->memberFor($code, $user) ?? false,
    ['guards' => ['player', 'web']],
);
