<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults to a guest player; use ->forUser() for a registered one.
 *
 * @extends Factory<RoomPlayer>
 */
class RoomPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => null,
            'guest_token' => Str::random(64),
            'nickname' => fake()->firstName(),
            'locale' => fake()->randomElement(['ar', 'en']),
            'score' => 0,
            'is_ready' => false,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user ?? User::factory(),
            'guest_token' => null,
        ]);
    }
}
