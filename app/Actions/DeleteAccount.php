<?php

namespace App\Actions;

use App\Game\PlayerPhoto;
use App\Game\RoomManager;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delete a player's account without hurting anyone else's results. The database does the rest: room_players.user_id and
 * rooms.host_id are set to null (never cascaded), so finished games stay intact for the other players. Only the rooms the user is
 * still hosting (a lobby or a game in progress) are closed first, through RoomManager so phones are told, and the account's player photos are deleted.
 */
class DeleteAccount
{
    public function __construct(private readonly RoomManager $rooms, private readonly PlayerPhoto $photos) {}

    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            Room::query()->where('host_id', $user->id)->active()->get()->each($this->rooms->close(...));

            // Their selfies go with the account (the rows stay, without them, so other players keep their finished games).
            RoomPlayer::query()->where('user_id', $user->id)->whereNotNull('avatar_path')->each(fn (RoomPlayer $player) => $this->photos->remove($player));

            $user->delete();
        });
    }
}
