<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use App\Support\QuestionImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importQuestions')
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Import questions from a CSV file')
                ->modalDescription('One row per question. The category can be a category ("Sports") or a subcategory with its parent ("Sports > Football"), by slug or name in either language. Rows with a problem are skipped and listed; the rest are imported. Excel: Save As > CSV UTF-8.')
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(1024)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path($data['file']);
                    $result = app(QuestionImporter::class)->import($path);
                    Storage::disk('local')->delete($data['file']);

                    $notification = Notification::make()
                        ->title($result['imported'].' imported, '.$result['skipped'].' skipped')
                        ->body($result['errors'] === [] ? null : implode("\n", array_slice($result['errors'], 0, 15)).(count($result['errors']) > 15 ? "\n…and ".(count($result['errors']) - 15).' more.' : ''))
                        ->persistent();
                    ($result['skipped'] === 0 && $result['errors'] === [] ? $notification->success() : $notification->warning())->send();
                }),
            Action::make('importTemplate')
                ->label('CSV template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (QuestionImporter::template()), 'questions-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
            CreateAction::make(),
        ];
    }
}
