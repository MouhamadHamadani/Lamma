<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Game\RoomCodeGenerator;
use App\Game\RoomSettings;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => app(RoomCodeGenerator::class)->generate(),
            'host_id' => User::factory(),
            'status' => RoomStatus::Lobby,
            'settings' => new RoomSettings,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function playing(): static
    {
        return $this->state(['status' => RoomStatus::Playing, 'started_at' => now()]);
    }

    public function finished(): static
    {
        return $this->state([
            'status' => RoomStatus::Finished,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);
    }
}
