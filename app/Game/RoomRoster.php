<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\PlayerLeft;
use App\Events\PlayerReadyChanged;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who is in a room's lobby and in what state, and the broadcast that goes with each change.
 * Only the lobby changes: once the game has started the roster is the game's business.
 */
class RoomRoster
{
    /** How long a disconnected player stays in the lobby before they are dropped. */
    public const GRACE_SECONDS = 30;

    /** Tap Ready on or off. Returns whether anything changed. */
    public function setReady(RoomPlayer $player, bool $ready): bool
    {
        $room = $player->room;
        if ($room->status !== RoomStatus::Lobby || $player->is_ready === $ready) {
            return false;
        }

        $player->update(['is_ready' => $ready]);
        PlayerReadyChanged::broadcast($room, $player)->toOthers();

        return true;
    }

    public function toggleReady(RoomPlayer $player): bool
    {
        return $this->setReady($player, ! $player->is_ready);
    }

    /** A player leaves by themselves. */
    public function leave(RoomPlayer $player): bool
    {
        return $this->drop($player->room, $player);
    }

    /**
     * The host removes a player. A player already gone is not an error (two clicks, or they left a moment ago).
     *
     * @throws AuthorizationException
     */
    public function remove(Room $room, User $by, int $playerId): bool
    {
        if ($room->host_id !== $by->id) {
            throw new AuthorizationException('Only the host can remove a player.');
        }

        $player = $room->players()->find($playerId);

        return $player !== null && $this->drop($room, $player);
    }

    /** The player's phone is on the presence channel (again). */
    public function markConnected(Room $room, int $playerId): void
    {
        $room->players()->whereKey($playerId)->whereNotNull('left_at')->update(['left_at' => null]);
    }

    /** The player's phone left the presence channel. The first moment counts: the clock does not restart. */
    public function markDisconnected(Room $room, int $playerId): void
    {
        $room->players()->whereKey($playerId)->whereNull('left_at')->update(['left_at' => now()]);
    }

    /**
     * The presence channel's member list is the truth: players in it are connected, the rest are not.
     *
     * @param  list<int>  $presentPlayerIds
     */
    public function syncPresence(Room $room, array $presentPlayerIds): void
    {
        $room->players()->whereIn('id', $presentPlayerIds)->whereNotNull('left_at')->update(['left_at' => null]);
        $room->players()->whereNotIn('id', $presentPlayerIds)->whereNull('left_at')->update(['left_at' => now()]);
    }

    /** Drop the players who have been gone longer than the grace period. Returns how many. */
    public function pruneDisconnected(Room $room): int
    {
        if ($room->status !== RoomStatus::Lobby) {
            return 0;
        }

        return $room->players()
            ->where('left_at', '<=', now()->subSeconds(self::GRACE_SECONDS))
            ->get()
            ->filter(fn (RoomPlayer $player) => $this->drop($room, $player))
            ->count();
    }

    private function drop(Room $room, RoomPlayer $player): bool
    {
        if ($room->status !== RoomStatus::Lobby) {
            return false;
        }

        $player->delete();
        PlayerLeft::broadcast($room, $player)->toOthers();

        return true;
    }
}
