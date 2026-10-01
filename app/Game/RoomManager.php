<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

    public function close(Room $room): void
    {
        if ($room->status === RoomStatus::Finished) {
            return;
        }

        $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);
    }
}
