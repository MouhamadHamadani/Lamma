<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomPlayer;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/** The daily clean-up behind `lamma:prune`. */
class Housekeeping
{
    /** A lobby nobody started within this many hours was abandoned. */
    public const LOBBY_HOURS = 6;

    public function __construct(
        private readonly RoomManager $rooms,
        private readonly SeenQuestions $seen,
        private readonly PlayerPhoto $photos,
    ) {}

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
                // Nobody will see this room's results, so its photos go at once instead of waiting out the retention time.
                $this->photos->purge($room);
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

    /**
     * Forget which questions guests have seen once the claim window is over (the same window as claiming a guest's results).
     *
     * @return int how many rows went
     */
    public function forgetGuestSightings(): int
    {
        return $this->seen->pruneGuests();
    }

    /**
     * Delete the selfies of every room that is over: finished more than lamma.photo_retention_hours ago (a room closed with "Close room" keeps
     * its photos that long, so the results screen still has them), or never finished and older than that (a game nobody ended). The room's
     * photo folder goes with them, and so does any folder whose room no longer exists.
     *
     * @return int how many photos were deleted
     */
    public function prunePhotos(): int
    {
        $cutoff = now()->subHours((int) config('lamma.photo_retention_hours'));
        $photos = 0;

        Room::query()
            ->whereHas('players', fn ($players) => $players->whereNotNull('avatar_path'))
            ->where(fn ($rooms) => $rooms
                ->where(fn ($finished) => $finished->where('status', RoomStatus::Finished)->where('finished_at', '<', $cutoff))
                ->orWhere(fn ($open) => $open->where('status', '!=', RoomStatus::Finished)->where('created_at', '<', $cutoff)))
            ->each(function (Room $room) use (&$photos) {
                $photos += $this->photos->purge($room);
            });

        return $photos + $this->photos->sweepOrphans();
    }

    /**
     * Raw selfie uploads that were never used (someone took a picture on the join page and left): Livewire keeps every upload in its temporary
     * folder (the private disk, never served) and would clear it after a day on its own schedule. They go with the same retention time as the photos,
     * on our hourly one. Admin uploads younger than that (a question image in a form that is still open) are untouched.
     *
     * @return int how many files were deleted
     */
    public function pruneTemporaryUploads(): int
    {
        $storage = FileUploadConfiguration::storage();
        $cutoff = now()->subHours((int) config('lamma.photo_retention_hours'))->getTimestamp();
        $deleted = 0;

        foreach ($storage->files(FileUploadConfiguration::directory()) as $file) {
            if ($storage->lastModified($file) < $cutoff && $storage->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
