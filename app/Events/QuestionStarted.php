<?php

namespace App\Events;

use App\Models\Room;
use App\Models\RoomQuestion;
use App\Support\CategoryStyle;

/**
 * A question is open: everything a screen needs to draw it, in both languages, with the options in display order
 * (A, B, C, D); a closest-number question has none and is answered with a typed number. It NEVER says which option is correct, what number is
 * (or how near counts), or the fun fact; that only goes out with QuestionRevealed.
 */
class QuestionStarted extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $question;

    public function __construct(Room $room, RoomQuestion $roomQuestion)
    {
        parent::__construct($room->code);

        $question = $roomQuestion->question()->with(['category.parent', 'options'])->firstOrFail();
        $options = collect($roomQuestion->option_order)->map(fn (int $id) => $question->options->firstWhere('id', $id))->filter();

        $this->question = [
            'position' => $roomQuestion->position,
            'total' => $room->totalQuestions(),
            'seconds' => $room->settings->secondsPerQuestion,
            'category' => [
                // "Sports · Football" for a subcategory, in each language.
                'name' => ['ar' => $question->category->fullName('ar'), 'en' => $question->category->fullName('en')],
                'tint' => CategoryStyle::tintName($question->category->tintSlug()),
                'icon' => CategoryStyle::icon($question->category->slug),
            ],
            'type' => $question->type->value,
            'text' => self::translations($question, 'text'),
            // The picture (if any) is part of the question; the correct number, the tolerance and the fun fact are not: they only go out with QuestionRevealed.
            'image_url' => $question->imageUrl(),
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
