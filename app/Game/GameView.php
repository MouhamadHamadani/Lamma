<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Enums\VoteOutcome;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;

/**
 * What a screen shows during a game, read from the database every time: the current question, its options in display order,
 * who has answered, and (only once revealed) the correct option and the ranking. Both the host screen and the phones build
 * their state from here, so a page refresh or a reconnect mid-question lands exactly where the game is. The correct option
 * is null until the question is revealed.
 */
class GameView
{
    public function __construct(private readonly Scoreboard $scoreboard, private readonly GameEngine $engine, private readonly CategoryVote $votes, private readonly ScoringService $scoring) {}

    public function forRoom(Room $room): ?GameState
    {
        $roomQuestion = $room->currentQuestion();
        if ($roomQuestion === null) {
            return null;
        }

        /** @var Question $question */
        $question = $roomQuestion->question()->with(['category.parent', 'options'])->firstOrFail();
        $revealed = $roomQuestion->revealed_at !== null;
        $ranking = $revealed ? $this->scoreboard->ranking($room, $roomQuestion) : [];
        $progress = $this->engine->progress($room, $roomQuestion);

        return new GameState(
            roomQuestion: $roomQuestion,
            question: $question,
            category: $question->category,
            options: collect($roomQuestion->option_order)->map(fn (int $id) => $question->options->firstWhere('id', $id))->filter()->values(),
            players: $room->players()->orderBy('joined_at')->orderBy('id')->get(),
            answers: $roomQuestion->answers()->get()->keyBy('room_player_id'),
            total: $room->totalQuestions(),
            answered: $progress['answered'],
            expected: $progress['expected'],
            correctOptionId: $revealed ? $question->options->firstWhere('is_correct', true)?->id : null,
            ranking: $ranking,
            previousPositions: $revealed ? $this->scoreboard->previousPositions($ranking) : [],
            finished: $room->status === RoomStatus::Finished,
            streaks: $revealed ? $this->scoreboard->streaks($room, $roomQuestion) : [],
            doubled: $this->scoring->isDoubled($room, $roomQuestion),
            correctNumber: $revealed && $question->type->takesNumber() ? (float) $question->answer_number : null,
            guesses: $revealed && $question->type->takesNumber() ? $this->guesses($room, $roomQuestion) : [],
        );
    }

    /**
     * Closest number, after the reveal: every guess with its owner, how far it was and what it scored, nearest first (the earlier guess first on a tie).
     *
     * @return list<array{player: RoomPlayer, value: float, distance: float, points: int, closest: bool}>
     */
    private function guesses(Room $room, RoomQuestion $roomQuestion): array
    {
        $players = $room->players()->get()->keyBy('id');
        $answer = (float) $roomQuestion->question->answer_number;

        $guesses = [];
        foreach ($roomQuestion->answers()->whereNotNull('number_value')->orderBy('answered_at')->orderBy('id')->get() as $given) {
            if (($player = $players->get($given->room_player_id)) === null) {
                continue;
            }
            $guesses[] = [
                'player' => $player,
                'value' => (float) $given->number_value,
                'distance' => $this->scoring->distance((float) $given->number_value, $answer),
                'points' => $given->points,
                'closest' => ($given->closest_bonus ?? 0) > 0,
            ];
        }
        usort($guesses, fn (array $a, array $b) => $a['distance'] <=> $b['distance']); // (stable: the earlier guess stays first on a tie)

        return $guesses;
    }

    /**
     * Vote mode: the category vote on screen now, open or showing its result, from its batch's last reveal until the next question opens. Null at any
     * other moment (and for a batch boundary with only one category left: there was no vote to show).
     */
    public function vote(Room $room): ?VoteState
    {
        $question = $room->isVote() && $room->status === RoomStatus::Playing ? $room->currentQuestion() : null;
        $round = $question?->revealed_at !== null ? $this->votes->roundAfter($room, $question->position) : null;
        if ($round === null || $round->outcome === VoteOutcome::OnlyOption) {
            return null;
        }

        $categories = Category::query()->whereIn('id', $round->option_ids)->get()->keyBy('id');
        $progress = $this->votes->progress($room, $round);

        return new VoteState(
            round: $round,
            position: $question->position,
            options: collect($round->option_ids)->map(fn (int $id) => $categories->get($id))->filter()->values(),
            players: $room->players()->orderBy('joined_at')->orderBy('id')->get(),
            votes: $round->votes()->get()->keyBy('room_player_id'),
            counts: $this->votes->counts($round),
            voted: $progress['voted'],
            expected: $progress['expected'],
        );
    }
}
