<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one demo bot will do on one question: the moment it answers and whether it is right (App\Game\Bots). Written when the question opens; read by the BotAnswer job and by
 * GameEngine::tick(). Timestamps carry milliseconds, like the question's own.
 *
 * @property int $id
 * @property int $room_id
 * @property int $room_player_id
 * @property int $position
 * @property Carbon $answer_at
 * @property bool $correct
 * @property Carbon|null $answered_at
 */
#[Fillable(['room_id', 'room_player_id', 'position', 'answer_at', 'correct', 'answered_at'])]
class BotPlan extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'answer_at' => 'datetime',
            'correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<RoomPlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
