<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** What the admin dashboard and the question list show about play: games per day, popular categories, hard questions, live rooms. */
class GameStats
{
    /** A lobby or game older than this is not "active": it was abandoned (see the lamma:prune command, which closes such lobbies). */
    public const ACTIVE_HOURS = 6;

    /**
     * Games that ran to the end, per day, for the last $days days including today.
     *
     * @return array<string, int> 'Y-m-d' => games, oldest first, every day present
     */
    public function gamesPerDay(int $days = 30): array
    {
        $first = now()->subDays($days - 1)->startOfDay();
        $perDay = Room::completed()->where('finished_at', '>=', $first)->pluck('finished_at')->countBy(fn ($finishedAt) => $finishedAt->toDateString());

        $games = [];
        for ($day = 0; $day < $days; $day++) {
            $date = $first->addDays($day)->toDateString();
            $games[$date] = (int) ($perDay[$date] ?? 0);
        }

        return $games;
    }

    /**
     * Categories by how many of their questions were asked in rooms, most first. `room_questions_count` is on each.
     *
     * @return Collection<int, Category>
     */
    public function mostPlayedCategories(int $limit = 8): Collection
    {
        return Category::query()
            ->withCount(['roomQuestions' => fn ($asked) => $asked->whereNotNull('room_questions.started_at')])
            ->whereHas('roomQuestions', fn ($asked) => $asked->whereNotNull('room_questions.started_at'))
            ->orderByDesc('room_questions_count')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The questions players got wrong most often. Each has answers_count and wrong_count (answers given, not times asked), so
     * % correct = (answers_count - wrong_count) / answers_count.
     *
     * @return Builder<Question>
     */
    public function hardestQuestionsQuery(int $limit = 10): Builder
    {
        return Question::query()->withTrashed()->with('category')
            ->withCount([
                'answers as answers_count',
                'answers as wrong_count' => fn ($answers) => $answers->where('player_answers.is_correct', false),
            ])
            ->whereHas('answers', fn ($answers) => $answers->where('player_answers.is_correct', false))
            ->orderByDesc('wrong_count')
            ->orderBy('questions.id')
            ->limit($limit);
    }

    /** The share of answers to this question that were right, 0-100, or null if nobody answered it. */
    public static function percentCorrect(int $answers, int $wrong): ?int
    {
        return $answers > 0 ? (int) round(($answers - $wrong) / $answers * 100) : null;
    }

    /**
     * Rooms in use right now: lobbies and games started within the last few hours, and the players whose phones are connected.
     *
     * @return array{lobby: int, playing: int, players: int}
     */
    public function activeRooms(): array
    {
        $since = now()->subHours(self::ACTIVE_HOURS);

        return [
            'lobby' => Room::query()->where('status', RoomStatus::Lobby)->where('created_at', '>=', $since)->count(),
            'playing' => Room::query()->where('status', RoomStatus::Playing)->where('created_at', '>=', $since)->count(),
            'players' => RoomPlayer::query()->connected()->whereIn('room_id', Room::query()->active()->where('created_at', '>=', $since)->select('rooms.id'))->count(),
        ];
    }
}
