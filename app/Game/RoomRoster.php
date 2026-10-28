<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\PlayerLeft;
use App\Events\PlayerReadyChanged;
use App\Events\PlayerUpdated;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Who is in a room's lobby and in what state, and the broadcast that goes with each change.
 * Only the lobby changes: once the game has started the roster is the game's business.
 */
class RoomRoster
{
    /** How long a disconnected player stays in the lobby before they are dropped. */
    public const GRACE_SECONDS = 30;

    public function __construct(private readonly PlayerPhoto $photos) {}

    /**
     * A player changes their nickname in the lobby. The same rules as joining: 2 to 20 characters, unique in the room ignoring case. Returns
     * whether anything changed; the others are told.
     *
     * @throws JoinException when the name is taken
     */
    public function rename(RoomPlayer $player, string $nickname): bool
    {
        $room = $player->room;
        $nickname = Str::squish($nickname);
        if ($room->status !== RoomStatus::Lobby || $nickname === $player->nickname) {
            return false;
        }
        if ($room->hasNickname($nickname, $player->id)) {
            throw JoinException::nicknameTaken();
        }

        try {
            $player->update(['nickname' => $nickname]);
        } catch (UniqueConstraintViolationException) {
            throw JoinException::nicknameTaken();
        }
        PlayerUpdated::broadcast($room, $player)->toOthers();

        return true;
    }

    /**
     * A player's new selfie, until the game starts. The old file is deleted; the others are told.
     *
     * @throws PhotoException
     */
    public function setPhoto(RoomPlayer $player, UploadedFile $file): void
    {
        $room = $player->room;
        if ($room->status !== RoomStatus::Lobby) {
            throw PhotoException::unavailable();
        }

        $this->photos->set($player, $file);
        PlayerUpdated::broadcast($room, $player)->toOthers();
    }

    /** A player takes their own photo away (back to the initial). Returns whether they had one. */
    public function removePhoto(RoomPlayer $player): bool
    {
        $room = $player->room;
        if ($room->status !== RoomStatus::Lobby || ! $this->photos->remove($player)) {
            return false;
        }

        PlayerUpdated::broadcast($room, $player)->toOthers();

        return true;
    }

    /**
     * The host takes a player's photo down (a picture that should not be on the screen), in any state of the game. The phone shows "The host
     * removed your photo" the next time it draws (a cache marker, so a phone that missed the broadcast still finds out).
     *
     * @throws AuthorizationException
     */
    public function removePhotoAsHost(Room $room, User $by, int $playerId): bool
    {
        if ($room->host_id !== $by->id) {
            throw new AuthorizationException('Only the host can remove a photo.');
        }

        $player = $room->players()->find($playerId);

        return $player !== null && $this->takeDown($player);
    }

    /**
     * The admin panel takes a photo down (moderation, in any room). Same effect as the host's, and the phone says so the same way. The panel's
     * own login is the authorization.
     */
    public function removePhotoAsAdmin(RoomPlayer $player): bool
    {
        return $this->takeDown($player);
    }

    private function takeDown(RoomPlayer $player): bool
    {
        if (! $this->photos->remove($player)) {
            return false;
        }

        Cache::put(self::hostRemovedKey($player->id), true, now()->addHour());
        PlayerUpdated::broadcast($player->room, $player)->toOthers();

        return true;
    }

    /** Was this player's photo taken down by the host? Asking forgets it, so the message shows once. */
    public static function wasRemovedByHost(int $playerId): bool
    {
        return Cache::pull(self::hostRemovedKey($playerId)) === true;
    }

    private static function hostRemovedKey(int $playerId): string
    {
        return "player:{$playerId}:photo-removed-by-host";
    }

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
