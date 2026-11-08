<?php

use App\Game\RoomPresence;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The lobby's presence channel (presence-room.CODE): the room's host and its participants. Guests have no account,
// so besides the web guard the "player" guard (their lamma_guest cookie) may authenticate, and so may the "demo" guard (the host cookie of a demo room,
// whose host has no account). "host" goes first but only answers a host SCREEN (it sends the X-Lamma-Role: host header and is the room's host), so a host screen opened
// in a browser that also holds a player row (a second tab on the same laptop) is still the host. Without the header "player" decides: a device that has a row in this room is
// that player, which keeps the host's phone apart from their big screen (RoomPresence). Returns the member info.
Broadcast::channel(
    'room.{code}',
    fn ($user, string $code) => app(RoomPresence::class)->memberFor($code, $user) ?? false,
    ['guards' => ['host', 'player', 'web', 'demo']],
);
