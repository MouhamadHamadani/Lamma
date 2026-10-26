<?php

namespace App\Game;

use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Chooses the questions for a room from the QuestionPool (active, chosen categories and difficulty, fully translated in Arabic and
 * English). Never the same question twice in one game (the pool is a set, and room_questions has a unique index on room + question).
 * Best first: questions that fewest of the room's players have seen (none, ideally), then those not asked in the host's last three
 * rooms (covers "Play again" and players whose history we do not have), then the ones seen longest ago, then at random. When the pool is
 * too small to avoid every repeat it still returns a full game; only a pool smaller than the game itself comes back short.
 */
class QuestionPicker
{
    /** How many of the host's earlier rooms count as "recent". */
    public const RECENT_ROOMS = 3;

    public function __construct(private readonly QuestionPool $pool, private readonly SeenQuestions $seen) {}

    /**
     * @return Collection<int, Question> up to the room's question count, options loaded, in random order; fewer only when the pool is too small
     */
    public function pick(Room $room): Collection
    {
        $ranked = $this->ranked($room->settings, $room->players()->get(), $this->recentlyAsked($room));
        $ids = array_slice($ranked, 0, $room->settings->questionCount);
        $questions = Question::query()->with('options')->findMany($ids)->keyBy('id');

        return collect($ids)->shuffle()->map(fn (int $id) => $questions->get($id))->filter()->values();
    }

    /**
     * Every playable question id, best first.
     *
     * @param  Collection<int, RoomPlayer>  $players
     * @param  list<int>  $recent  question ids to avoid if there is a choice
     * @return list<int>
     */
    public function ranked(RoomSettings $settings, Collection $players, array $recent = []): array
    {
        $seen = $this->seen->summarize($players);
        $recent = array_flip($recent);

        $ranked = $this->pool->query($settings)->pluck('questions.id')
            ->map(fn ($id) => (int) $id)
            ->map(fn (int $id) => [$id, $seen[$id]['seen_by'] ?? 0, isset($recent[$id]) ? 1 : 0, $seen[$id]['last_seen'] ?? 0, random_int(0, PHP_INT_MAX)])
            ->sort(fn (array $a, array $b) => [$a[1], $a[2], $a[3], $a[4]] <=> [$b[1], $b[2], $b[3], $b[4]])
            ->map(fn (array $row) => $row[0]);

        return array_values($ranked->all());
    }

    /**
     * How many playable questions none of these players has seen.
     *
     * @param  Collection<int, RoomPlayer>  $players
     */
    public function freshFor(RoomSettings $settings, Collection $players): int
    {
        $seen = $this->seen->summarize($players);

        return $this->pool->query($settings)->pluck('questions.id')->reject(fn ($id) => isset($seen[(int) $id]))->count();
    }

    /**
     * The players of the host's latest game (inside the guest window): the group they are most likely to play with again.
     *
     * @return Collection<int, RoomPlayer>
     */
    public function lastGroup(User $host): Collection
    {
        $room = Room::query()
            ->where('host_id', $host->id)
            ->where('created_at', '>=', now()->subHours((int) config('lamma.guest_claim_hours')))
            ->whereHas('roomQuestions')
            ->latest('id')
            ->first();

        return $room ? $room->players()->get() : new Collection;
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
