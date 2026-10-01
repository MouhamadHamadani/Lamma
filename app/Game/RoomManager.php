<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\RoomClosed;
use App\Events\RoomRestarted;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Creating and closing rooms. */
class RoomManager
{
    public function __construct(
        private readonly RoomCodeGenerator $codes,
        private readonly QuestionPool $questions,
    ) {}

    /**
     * A new lobby for the host. A host has at most one room in lobby or playing, so any earlier one is closed first.
     *
     * @throws NotEnoughQuestions
     */
    public function create(User $host, RoomSettings $settings): Room
    {
        $available = $this->questions->available($settings);
        if ($available < $settings->questionCount) {
            throw new NotEnoughQuestions($available, $settings->questionCount);
        }

        return DB::transaction(function () use ($host, $settings) {
            Room::query()->where('host_id', $host->id)->active()->get()->each($this->close(...));

            // The unique index is the final guard against two rooms drawing the same code at once.
            for ($attempt = 1; ; $attempt++) {
                try {
                    return Room::create([
                        'code' => $this->codes->generate(),
                        'host_id' => $host->id,
                        'status' => RoomStatus::Lobby,
                        'settings' => $settings,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt >= 3) {
                        throw $e;
                    }
                }
            }
        });
    }

    /**
     * "Play again": a new lobby with the same settings and the players who are still connected (scores 0, not ready), and a
     * RoomRestarted broadcast on the old room so every phone follows to it. The old room remembers the new one (next_room_id),
     * so asking twice returns the same room, and a phone that missed the broadcast still finds it.
     *
     * @throws AuthorizationException when $by is not the host
     * @throws NotEnoughQuestions when the settings no longer have enough playable questions
     */
    public function playAgain(Room $old, User $by): Room
    {
        if ($old->host_id !== $by->id) {
            throw new AuthorizationException('Only the host can start another round.');
        }
        if ($old->status !== RoomStatus::Finished) {
            throw new LogicException('Only a finished game can be played again.');
        }

        [$new, $created] = DB::transaction(function () use ($old, $by) {
            $locked = Room::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->next_room_id ? Room::find($locked->next_room_id) : null;
            if ($existing !== null && $existing->status !== RoomStatus::Finished) {
                return [$existing, false];
            }

            $new = $this->create($by, $locked->settings);

            // A new row starts disconnected: its phone has not joined the new room's channel yet (see RoomRoster).
            foreach ($locked->players()->connected()->orderBy('joined_at')->orderBy('id')->get() as $player) {
                RoomPlayer::create([
                    'room_id' => $new->id,
                    'user_id' => $player->user_id,
                    'guest_token' => $player->guest_token,
                    'nickname' => $player->nickname,
                    'locale' => $player->locale,
                    'score' => 0,
                    'is_ready' => false,
                    'joined_at' => now(),
                    'left_at' => now(),
                ]);
            }

            $locked->update(['next_room_id' => $new->id]);

            return [$new, true];
        });

        if ($created) {
            RoomRestarted::broadcast($old, $new->code)->toOthers();
        }

        return $new;
    }

    public function close(Room $room): void
    {
        if ($room->status === RoomStatus::Finished) {
            return;
        }

        $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);
        RoomClosed::broadcast($room)->toOthers();
    }
}
