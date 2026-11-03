<?php

namespace Database\Factories;

use App\Models\CategoryVoteRound;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryVoteRound>
 */
class CategoryVoteRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'round' => 1,
            'option_ids' => [],
            'ends_at' => now()->addSeconds(10),
            'closed_at' => null,
            'winner_category_id' => null,
            'outcome' => null,
        ];
    }
}
