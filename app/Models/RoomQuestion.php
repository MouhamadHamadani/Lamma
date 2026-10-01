<?php

namespace App\Models;

use Database\Factories\RoomQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One question asked in a room. Timestamps carry milliseconds; the server owns the clock.
 *
 * @property int $id
 * @property int $room_id
 * @property int $question_id
 * @property int $position
 * @property list<int>|null $option_order Option ids in the order every screen shows them (A, B, C, D)
 * @property Carbon|null $started_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $revealed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_id', 'question_id', 'position', 'option_order', 'started_at', 'ends_at', 'revealed_at'])]
class RoomQuestion extends Model
{
    /** @use HasFactory<RoomQuestionFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'option_order' => 'array',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }

    /** @return HasMany<PlayerAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(PlayerAnswer::class);
    }
}
