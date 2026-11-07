<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        $perDay = Room::real()->completed()->where('finished_at', '>=', $first)->pluck('finished_at')->countBy(fn ($finishedAt) => $finishedAt->toDateString());

        $games = [];
        for ($day = 0; $day < $days; $day++) {
            $date = $first->addDays($day)->toDateString();
            $games[$date] = (int) ($perDay[$date] ?? 0);
        }

        return $games;
    }

    /**
     * The funnel of the last $days days (today included), computed from the database: rooms created, games started, games played to the end, how many of those
     * were played again, and the average number of people in a started game. Demo games and bots are NOT in these figures: a demo has its own (what is left of
     * the last two hours' rooms plus what lamma:prune tallied: App\Game\DemoStats).
     *
     * @return array{created: int, started: int, finished: int, rematched: int, rematch_rate: int|null, average_players: float|null, demo: array{started: int, finished: int, converted: int}}
     */
    public function funnel(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $rooms = fn () => Room::query()->real()->where('created_at', '>=', $since);

        $started = $rooms()->whereNotNull('started_at')->count();
        $finished = $rooms()->completed()->count();
        $rematched = $rooms()->completed()->whereNotNull('next_room_id')->count();
        $players = RoomPlayer::query()->where('is_bot', false)->whereIn('room_id', $rooms()->whereNotNull('started_at')->select('rooms.id'))->count();

        return [
            'created' => $rooms()->count(),
            'started' => $started,
            'finished' => $finished,
            'rematched' => $rematched,
            'rematch_rate' => self::share($rematched, $finished),
            'average_players' => $started > 0 ? round($players / $started, 1) : null,
            'demo' => app(DemoStats::class)->summary($days),
        ];
    }

    /** $part as a whole percentage of $whole, or null when there is nothing to compare with. */
    public static function share(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    /**
     * Categories by how many of their questions were asked in rooms, most first. `room_questions_count` is on each. With $rollUp (the
     * default) a subcategory's questions count for its parent, so the list is of top-level categories; without it the list is of the
     * subcategories alone.
     *
     * @return Collection<int, Category>
     */
    public function mostPlayedCategories(int $limit = 8, bool $rollUp = true): Collection
    {
        $asked = DB::table('room_questions')
            ->join('questions', 'questions.id', '=', 'room_questions.question_id')
            ->join('categories', 'categories.id', '=', 'questions.category_id')
            ->join('rooms', 'rooms.id', '=', 'room_questions.room_id')
            ->where('rooms.is_demo', false)
            ->whereNotNull('room_questions.started_at')
            ->whereNull('questions.deleted_at')
            ->when(! $rollUp, fn ($query) => $query->whereNotNull('categories.parent_id'))
            ->selectRaw(($rollUp ? 'coalesce(categories.parent_id, categories.id)' : 'categories.id').' as category_id, count(*) as asked')
            ->groupByRaw($rollUp ? 'coalesce(categories.parent_id, categories.id)' : 'categories.id')
            ->orderByDesc('asked')->orderBy('category_id')
            ->limit($limit)
            ->get();

        $counts = $asked->pluck('asked', 'category_id');

        return Category::query()->with('parent')->whereIn('id', $counts->keys())->get()
            ->each(fn (Category $category) => $category->setAttribute('room_questions_count', (int) $counts[$category->id]))
            ->sortBy([['room_questions_count', 'desc'], ['id', 'asc']])
            ->values();
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
                'answers as answers_count' => fn ($answers) => $answers->whereIn('room_questions.room_id', Room::real()->select('rooms.id')),
                'answers as wrong_count' => fn ($answers) => $answers->where('player_answers.is_correct', false)->whereIn('room_questions.room_id', Room::real()->select('rooms.id')),
            ])
            ->whereHas('answers', fn ($answers) => $answers->where('player_answers.is_correct', false)->whereIn('room_questions.room_id', Room::real()->select('rooms.id')))
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
            'lobby' => Room::query()->real()->where('status', RoomStatus::Lobby)->where('created_at', '>=', $since)->count(),
            'playing' => Room::query()->real()->where('status', RoomStatus::Playing)->where('created_at', '>=', $since)->count(),
            'players' => RoomPlayer::query()->connected()->whereIn('room_id', Room::query()->real()->active()->where('created_at', '>=', $since)->select('rooms.id'))->count(),
        ];
    }
}
