<?php

namespace App\Game;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/**
 * The host of a demo room: nobody with an account, just the browser that holds the room's host cookie (PlayerIdentity::hostFor builds one only
 * after the cookie's token matched the room). It is Authenticatable so the presence channel's "demo" guard can hand it to channel authorization, like a
 * RoomPlayer; its identifier is prefixed so it can never equal a User or a player.
 */
final class DemoHost implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(public readonly int $roomId) {}

    public function getAuthIdentifier(): string
    {
        return 'demo-host:'.$this->roomId;
    }
}
