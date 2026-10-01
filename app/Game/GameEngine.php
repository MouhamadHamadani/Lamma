<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Runs a game. For now only the start: validate, move the room to playing, tell everyone. Questions come next. */
class GameEngine
{
    /**
     * Start the game. Rules: only the host, only from the lobby, at least one connected player, every connected player
     * ready. A lock plus a row lock make a double click (or two hosts' tabs) start it exactly once.
     *
     * @throws GameStartException
     */
    public function start(Room $room, User $by): Room
    {
        $lock = Cache::lock("room:{$room->id}:start", 10);
        if (! $lock->get()) {
            throw GameStartException::inProgress();
        }

        try {
            $started = DB::transaction(function () use ($room, $by) {
                $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

                if ($locked->host_id !== $by->id) {
                    throw GameStartException::notHost();
                }
                if ($locked->status !== RoomStatus::Lobby) {
                    throw GameStartException::notInLobby();
                }

                $connected = $locked->players()->connected()->get();
                if ($connected->isEmpty()) {
                    throw GameStartException::noPlayers();
                }
                if ($connected->contains(fn ($player) => ! $player->is_ready)) {
                    throw GameStartException::notEveryoneReady();
                }

                $locked->update(['status' => RoomStatus::Playing, 'started_at' => now()]);

                return $locked;
            });
        } finally {
            $lock->release();
        }

        GameStarted::broadcast($started)->toOthers();

        return $started;
    }
}
