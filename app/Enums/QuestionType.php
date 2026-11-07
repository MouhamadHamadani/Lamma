<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a question is answered. A "picture" question is not a type of its own: it is a multiple-choice or true/false question that also has an image.
 */
enum QuestionType: string implements HasColor, HasLabel
{
    /** Four (or five) options, one of them correct. Every question before Phase 13. */
    case MultipleChoice = 'multiple_choice';

    /** Two options, True and False, in that order. */
    case TrueFalse = 'true_false';

    /** A number: the closest guess within the tolerance wins. No options. */
    case ClosestNumber = 'closest_number';

    public function getLabel(): string
    {
        return match ($this) {
            self::MultipleChoice => __('Multiple choice'),
            self::TrueFalse => __('True or false'),
            self::ClosestNumber => __('Closest number'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MultipleChoice => 'gray',
            self::TrueFalse => 'info',
            self::ClosestNumber => 'warning',
        };
    }

    /** The question is answered by tapping an option (and has option rows). */
    public function hasOptions(): bool
    {
        return $this !== self::ClosestNumber;
    }

    /** The question is answered by typing a number. */
    public function takesNumber(): bool
    {
        return $this === self::ClosestNumber;
    }
}
