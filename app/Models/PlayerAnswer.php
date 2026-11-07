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
 * @property int|null $question_option_id Multiple choice and true/false: the option tapped. Null for a closest-number guess
 * @property float|null $number_value Closest number: the guess, as the server read it (App\Game\GuessNumber)
 * @property Carbon $answered_at Millisecond precision
 * @property bool $is_correct
 * @property int $points the total: (base_points + speed_bonus) x multiplier
 * @property int|null $base_points null on answers from before the speed bonus, like the next two
 * @property int|null $speed_bonus
 * @property int|null $closest_bonus Closest number: the +50 for the closest guess, decided at the reveal
 * @property int|null $multiplier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_question_id', 'room_player_id', 'question_option_id', 'number_value', 'answered_at', 'is_correct', 'points', 'base_points', 'speed_bonus', 'closest_bonus', 'multiplier'])]
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
            'base_points' => 'integer',
            'speed_bonus' => 'integer',
            'closest_bonus' => 'integer',
            'number_value' => 'float',
            'multiplier' => 'integer',
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
