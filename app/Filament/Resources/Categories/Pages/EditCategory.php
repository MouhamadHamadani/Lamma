<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A parent with subcategories cannot be deleted (the database refuses): move or delete them first.
            DeleteAction::make()->hidden(fn (Category $record): bool => $record->children()->exists()),
        ];
    }
}
