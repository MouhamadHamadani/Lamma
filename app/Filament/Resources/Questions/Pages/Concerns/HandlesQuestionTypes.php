<?php

namespace App\Filament\Resources\Questions\Pages\Concerns;

use App\Enums\QuestionType;
use App\Models\Question;

/**
 * What the create and edit pages do beyond saving the columns, so a question is always complete for its type: a true/false question's two options are made from
 * the one "The statement is" choice (it is no column), a closest-number question has no options, and the number and tolerance of a question that is not a number
 * question are cleared. (A multiple-choice question's options are saved by the form's own repeater.)
 */
trait HandlesQuestionTypes
{
    /** What the admin chose for a true/false question, until the question is saved. */
    protected ?bool $trueFalseAnswer = null;

    /**
     * Take the form's extra field out of the data and tidy what belongs to another type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withoutTypeFields(array $data): array
    {
        $this->trueFalseAnswer = isset($data['true_false_answer']) ? $data['true_false_answer'] === 'true' : null;
        unset($data['true_false_answer']);

        if (QuestionType::tryFrom((string) ($data['type'] instanceof QuestionType ? $data['type']->value : ($data['type'] ?? ''))) !== QuestionType::ClosestNumber) {
            $data['answer_number'] = null;
            $data['tolerance'] = null;
        }

        return $data;
    }

    /** After the question and its repeater are saved: the options its type calls for. */
    protected function settleOptions(Question $question): void
    {
        if ($question->type === QuestionType::TrueFalse && $this->trueFalseAnswer !== null) {
            $question->syncTrueFalse($this->trueFalseAnswer);
        } elseif ($question->type === QuestionType::ClosestNumber) {
            $question->options()->delete();
        }
    }
}
