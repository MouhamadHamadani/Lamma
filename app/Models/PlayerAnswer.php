<?php

namespace App\Models;

use Database\Factories\PlayerAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_question_id
 * @property int $room_player_id
 * @property int|null $question_option_id
 * @property Carbon $answered_at Millisecond precision
 * @property bool $is_correct
 * @property int $points
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_question_id', 'room_player_id', 'question_option_id', 'answered_at', 'is_correct', 'points'])]
class PlayerAnswer extends Model
{
    /** @use HasFactory<PlayerAnswerFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'is_correct' => 'boolean',
            'points' => 'integer',
        ];
    }

    /** @return BelongsTo<RoomQuestion, $this> */
    public function roomQuestion(): BelongsTo
    {
        return $this->belongsTo(RoomQuestion::class);
    }

    /** @return BelongsTo<RoomPlayer, $this> */
    public function roomPlayer(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class);
    }

    /** @return BelongsTo<QuestionOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'question_option_id');
    }
}
