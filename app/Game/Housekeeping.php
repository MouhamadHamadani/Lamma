<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomPlayer;

/** The daily clean-up behind `lamma:prune`. */
class Housekeeping
{
    /** A lobby nobody started within this many hours was abandoned. */
    public const LOBBY_HOURS = 6;

    public function __construct(private readonly RoomManager $rooms) {}

    /**
     * Finish lobbies older than LOBBY_HOURS (a host who opened a room and left). It goes through RoomManager::close, so any phone still
     * waiting in one is told the room is closed. Games being played and finished rooms are left alone.
     *
     * @return int how many rooms were closed
     */
    public function closeAbandonedLobbies(): int
    {
        $closed = 0;

        Room::query()->where('status', RoomStatus::Lobby)->where('created_at', '<', now()->subHours(self::LOBBY_HOURS))
            ->each(function (Room $room) use (&$closed) {
                $this->rooms->close($room);
                $closed++;
            });

        return $closed;
    }

    /**
     * Forget the guest token of guest rows older than the claim window (lamma.guest_claim_hours), so a guest's results can never be
     * attached to an account after that. Rows already tied to an account never have a token that matters and are left alone.
     *
     * @return int how many tokens were cleared
     */
    public function clearExpiredGuestTokens(): int
    {
        return RoomPlayer::query()
            ->whereNull('user_id')
            ->whereNotNull('guest_token')
            ->where('created_at', '<', now()->subHours((int) config('lamma.guest_claim_hours')))
            ->update(['guest_token' => null]);
    }
}
