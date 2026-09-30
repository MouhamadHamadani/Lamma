<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomQuestion>
 */
class RoomQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'question_id' => Question::factory(),
            'position' => 1,
            'started_at' => null,
            'ends_at' => null,
            'revealed_at' => null,
        ];
    }
}
