<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Models\Question;
use App\Models\Room;

/**
 * What a screen shows during a game, read from the database every time: the current question, its options in display order,
 * who has answered, and (only once revealed) the correct option and the ranking. Both the host screen and the phones build
 * their state from here, so a page refresh or a reconnect mid-question lands exactly where the game is. The correct option
 * is null until the question is revealed.
 */
class GameView
{
    public function __construct(private readonly Scoreboard $scoreboard, private readonly GameEngine $engine) {}

    public function forRoom(Room $room): ?GameState
    {
        $roomQuestion = $room->currentQuestion();
        if ($roomQuestion === null) {
            return null;
        }

        /** @var Question $question */
        $question = $roomQuestion->question()->with(['category', 'options'])->firstOrFail();
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
            total: $room->roomQuestions()->count(),
            answered: $progress['answered'],
            expected: $progress['expected'],
            correctOptionId: $revealed ? $question->options->firstWhere('is_correct', true)?->id : null,
            ranking: $ranking,
            previousPositions: $revealed ? $this->scoreboard->previousPositions($ranking) : [],
            finished: $room->status === RoomStatus::Finished,
        );
    }
}
