<?php

namespace App\Events;

use App\Game\RoomPresence;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a room's lobby. Sent straight away (no queue worker needed) on presence-room.{code}, which only the
 * room's host and participants can join. Payloads carry only what the screens show: never a guest token or an email.
 */
abstract class RoomEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $roomCode) {}

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel(RoomPresence::channel($this->roomCode))];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['code' => $this->roomCode];
    }
}
