<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryVote;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryVote>
 */
class CategoryVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'round' => 1,
            'room_player_id' => RoomPlayer::factory(),
            'category_id' => Category::factory(),
        ];
    }
}
