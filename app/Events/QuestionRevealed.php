<?php

namespace App\Events;

use App\Models\Room;
use App\Models\RoomQuestion;

/** The question is over: the correct option, who picked each option, and the points each player gained. */
class QuestionRevealed extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $reveal;

    public function __construct(Room $room, RoomQuestion $roomQuestion)
    {
        parent::__construct($room->code);

        $question = $roomQuestion->question()->with('options')->firstOrFail();
        $answers = $roomQuestion->answers()->with('roomPlayer')->get();
        $options = collect($roomQuestion->option_order)->map(fn (int $id) => $question->options->firstWhere('id', $id))->filter();
        $gained = $answers->pluck('points', 'room_player_id');

        $this->reveal = [
            'position' => $roomQuestion->position,
            'correct_option_id' => $question->options->firstWhere('is_correct', true)?->id,
            'picks' => $options->map(fn ($option) => [
                'option_id' => $option->id,
                'players' => $answers->where('question_option_id', $option->id)
                    ->map(fn ($answer) => ['id' => $answer->roomPlayer->id, 'nickname' => $answer->roomPlayer->nickname])->values()->all(),
            ])->values()->all(),
            'points' => $room->players()->orderBy('id')->pluck('id')->map(fn (int $id) => ['player_id' => $id, 'points' => (int) ($gained[$id] ?? 0)])->all(),
            'revealed_at' => self::iso($roomQuestion->revealed_at),
        ];
    }

    protected function payload(): array
    {
        return $this->reveal;
    }
}
