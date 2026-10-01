<?php

namespace App\Events;

use App\Models\Room;
use App\Models\RoomQuestion;
use App\Support\CategoryStyle;

/**
 * A question is open: everything a screen needs to draw it, in both languages, with the options in display order
 * (A, B, C, D). It NEVER says which option is correct; that only goes out with QuestionRevealed.
 */
class QuestionStarted extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $question;

    public function __construct(Room $room, RoomQuestion $roomQuestion)
    {
        parent::__construct($room->code);

        $question = $roomQuestion->question()->with(['category', 'options'])->firstOrFail();
        $options = collect($roomQuestion->option_order)->map(fn (int $id) => $question->options->firstWhere('id', $id))->filter();

        $this->question = [
            'position' => $roomQuestion->position,
            'total' => $room->roomQuestions()->count(),
            'seconds' => $room->settings->secondsPerQuestion,
            'category' => [
                'name' => self::translations($question->category, 'name'),
                'tint' => CategoryStyle::tintName($question->category->slug),
                'icon' => CategoryStyle::icon($question->category->slug),
            ],
            'text' => self::translations($question, 'text'),
            'options' => $options->map(fn ($option) => ['id' => $option->id, 'text' => self::translations($option, 'text')])->values()->all(),
            'started_at' => self::iso($roomQuestion->started_at),
            'ends_at' => self::iso($roomQuestion->ends_at),
        ];
    }

    protected function payload(): array
    {
        return ['question' => $this->question];
    }
}
