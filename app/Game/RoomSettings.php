<?php

namespace App\Game;

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * Value object for `rooms.settings` (JSON). Cast automatically on Room.
 *
 * @implements Arrayable<string, mixed>
 */
final class RoomSettings implements Arrayable, Castable
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public array $categoryIds = [],
        public int $questionCount = 10,
        public int $secondsPerQuestion = 20,
        public ?Difficulty $difficulty = null,
        public HostScreenLocale $hostScreenLocale = HostScreenLocale::Both,
    ) {}

    /**
     * Missing or invalid keys fall back to the defaults.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = new self;

        return new self(
            categoryIds: array_values(array_map('intval', $data['category_ids'] ?? $defaults->categoryIds)),
            questionCount: (int) ($data['question_count'] ?? $defaults->questionCount),
            secondsPerQuestion: (int) ($data['seconds_per_question'] ?? $defaults->secondsPerQuestion),
            difficulty: Difficulty::tryFrom((string) ($data['difficulty'] ?? '')),
            hostScreenLocale: HostScreenLocale::tryFrom((string) ($data['host_screen_locale'] ?? '')) ?? $defaults->hostScreenLocale,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category_ids' => $this->categoryIds,
            'question_count' => $this->questionCount,
            'seconds_per_question' => $this->secondsPerQuestion,
            'difficulty' => $this->difficulty?->value,
            'host_screen_locale' => $this->hostScreenLocale->value,
        ];
    }

    /** @return CastsAttributes<RoomSettings, RoomSettings|array<string, mixed>> */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): RoomSettings
            {
                return RoomSettings::fromArray(json_decode($value ?? '[]', true) ?? []);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): string
            {
                $settings = match (true) {
                    $value instanceof RoomSettings => $value,
                    is_array($value) => RoomSettings::fromArray($value),
                    default => new RoomSettings,
                };

                return json_encode($settings->toArray(), JSON_THROW_ON_ERROR);
            }
        };
    }
}
