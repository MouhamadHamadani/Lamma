<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\Pages\Concerns\HandlesQuestionTypes;
use App\Filament\Resources\Questions\QuestionResource;
use App\Models\Question;
use Filament\Resources\Pages\CreateRecord;

class CreateQuestion extends CreateRecord
{
    use HandlesQuestionTypes;

    protected static string $resource = QuestionResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->withoutTypeFields($data);
    }

    protected function afterCreate(): void
    {
        /** @var Question $question */
        $question = $this->record;

        $this->settleOptions($question);
    }
}
