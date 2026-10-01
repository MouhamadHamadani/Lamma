<?php

namespace App\Game;

use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Illuminate\Support\Collection;

/**
 * Chooses the questions for a room: random, from the QuestionPool (active, chosen categories and difficulty, fully translated
 * in Arabic and English), avoiding what this host was asked in their last three rooms when there are enough other questions.
 */
class QuestionPicker
{
    /** How many of the host's earlier rooms count as "recent". */
    public const RECENT_ROOMS = 3;

    public function __construct(private readonly QuestionPool $pool) {}

    /**
     * @return Collection<int, Question> up to the room's question count, options loaded; fewer only when the pool is too small
     */
    public function pick(Room $room): Collection
    {
        $settings = $room->settings;
        $needed = $settings->questionCount;
        $recent = $this->recentlyAsked($room);

        $fresh = $this->pool->query($settings)->with('options')->whereNotIn('id', $recent)->inRandomOrder()->limit($needed)->get();
        if ($fresh->count() >= $needed) {
            return $fresh->values();
        }

        // Not enough new ones: top up with questions from the host's recent rooms rather than run short.
        $repeats = $this->pool->query($settings)->with('options')->whereIn('id', $recent)->inRandomOrder()->limit($needed - $fresh->count())->get();

        return $fresh->concat($repeats)->shuffle()->values();
    }

    /** @return list<int> question ids asked in this host's last RECENT_ROOMS rooms that actually had questions */
    public function recentlyAsked(Room $room): array
    {
        $rooms = Room::query()
            ->where('host_id', $room->host_id)
            ->where('id', '<', $room->id)
            ->whereHas('roomQuestions')
            ->latest('id')
            ->limit(self::RECENT_ROOMS)
            ->pluck('id');

        return array_values(RoomQuestion::query()->whereIn('room_id', $rooms)->pluck('question_id')->unique()->map(fn ($id) => (int) $id)->all());
    }
}
