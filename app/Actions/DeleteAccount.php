<?php

namespace App\Actions;

use App\Game\RoomManager;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delete a player's account without hurting anyone else's results. The database does the rest: room_players.user_id and
 * rooms.host_id are set to null (never cascaded), so finished games stay intact for the other players. Only the rooms the user is
 * still hosting (a lobby or a game in progress) are closed first, through RoomManager so phones are told.
 */
class DeleteAccount
{
    public function __construct(private readonly RoomManager $rooms) {}

    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            Room::query()->where('host_id', $user->id)->active()->get()->each($this->rooms->close(...));

            $user->delete();
        });
    }
}
