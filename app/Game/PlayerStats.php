<?php

namespace App\Game;

use App\Models\PlayerAnswer;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A user's saved games and what they add up to. Only rows tied to an account count (RoomPlayer::savedToAccount): a guest's
 * score is not saved until it is claimed, and only games that ran to the end are listed (a room closed part-way has no result).
 */
class PlayerStats
{
    /**
     * Games played, games won (first place with at least one point, ties included), and how many of the questions in those
     * games they answered correctly: unanswered questions count as not correct.
     *
     * @return array{played: int, wins: int, correct: int, questions: int, rate: int|null}
     */
    public function totals(User $user): array
    {
        $played = $this->saved($user);

        $wins = (clone $played)->where('room_players.score', '>', 0)
            ->whereNotExists(fn ($other) => $other->from('room_players as rivals')
                ->whereColumn('rivals.room_id', 'room_players.room_id')
                ->whereColumn('rivals.score', '>', 'room_players.score'))
            ->count();

        $correct = PlayerAnswer::query()->where('is_correct', true)->whereIn('room_player_id', (clone $played)->select('room_players.id'))->count();
        $questions = RoomQuestion::query()->whereIn('room_id', (clone $played)->select('room_players.room_id'))->count();

        return [
            'played' => (clone $played)->count(),
            'wins' => $wins,
            'correct' => $correct,
            'questions' => $questions,
            'rate' => $questions > 0 ? (int) round($correct / $questions * 100) : null,
        ];
    }

    /**
     * The user's saved games, newest first. Each row carries rank (competition ranking: ties share), players_count,
     * correct_count and questions_count, and its room.
     *
     * @return LengthAwarePaginator<int, RoomPlayer>
     */
    public function history(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $this->saved($user)
            ->join('rooms', 'rooms.id', '=', 'room_players.room_id')
            ->select('room_players.*')
            ->selectSub(
                RoomPlayer::query()->from('room_players as rivals')->selectRaw('count(*) + 1')
                    ->whereColumn('rivals.room_id', 'room_players.room_id')->whereColumn('rivals.score', '>', 'room_players.score'),
                'rank',
            )
            ->selectSub(
                RoomPlayer::query()->from('room_players as everyone')->selectRaw('count(*)')->whereColumn('everyone.room_id', 'room_players.room_id'),
                'players_count',
            )
            ->selectSub(
                PlayerAnswer::query()->selectRaw('count(*)')->whereColumn('player_answers.room_player_id', 'room_players.id')->where('player_answers.is_correct', true),
                'correct_count',
            )
            ->selectSub(
                RoomQuestion::query()->selectRaw('count(*)')->whereColumn('room_questions.room_id', 'room_players.room_id'),
                'questions_count',
            )
            ->with('room')
            ->orderByDesc('rooms.finished_at')
            ->orderByDesc('room_players.id')
            ->paginate($perPage);
    }

    /**
     * The user's saved rows in games that ran to the end.
     *
     * @return Builder<RoomPlayer>
     */
    private function saved(User $user): Builder
    {
        return RoomPlayer::query()->savedToAccount()->where('room_players.user_id', $user->id)->whereHas('room', fn ($room) => $room->completed());
    }
}
