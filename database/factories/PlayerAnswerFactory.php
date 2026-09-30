<?php

namespace Database\Factories;

use App\Models\PlayerAnswer;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerAnswer>
 */
class PlayerAnswerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_question_id' => RoomQuestion::factory(),
            'room_player_id' => RoomPlayer::factory(),
            'question_option_id' => null,
            'answered_at' => now(),
            'is_correct' => false,
            'points' => 0,
        ];
    }
}
