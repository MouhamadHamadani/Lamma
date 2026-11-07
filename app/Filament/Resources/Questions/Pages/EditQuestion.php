<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Enums\QuestionType;
use App\Filament\Resources\Questions\Pages\Concerns\HandlesQuestionTypes;
use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Question;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditQuestion extends EditRecord
{
    use HandlesQuestionTypes;

    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * A true/false question shows which of True and False is the correct one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Question $question */
        $question = $this->record;

        if ($question->type === QuestionType::TrueFalse) {
            $correct = $question->options()->where('is_correct', true)->first();
            $data['true_false_answer'] = $correct?->getTranslation('text', 'en', false) === 'False' ? 'false' : 'true';
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withoutTypeFields($data);
    }

    protected function afterSave(): void
    {
        /** @var Question $question */
        $question = $this->record;

        $this->settleOptions($question);
    }
}
