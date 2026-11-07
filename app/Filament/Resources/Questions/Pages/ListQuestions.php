<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use App\Game\QuestionImage;
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
                ->modalDescription('One row per question. The category can be a category ("Sports") or a subcategory with its parent ("Sports > Football"), by slug or name in either language. Optional columns: type (multiple_choice, true_false, closest_number), answer (true/false, or the number), tolerance, image (a file name from the pictures you choose below), explanation_ar, explanation_en: see the template. Rows with a problem are skipped and listed; the rest are imported. Excel: Save As > CSV UTF-8.')
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(1024)
                        ->required(),
                    // The pictures the file's `image` column names, chosen together with the CSV (select all the files of the folder). Each is checked and re-saved as WebP.
                    FileUpload::make('pictures')
                        ->label('Pictures (optional)')
                        ->helperText('JPEG, PNG or WebP, up to 2 MB each. The image column of a row holds the file name, like pyramids.jpg.')
                        ->disk('local')
                        ->directory('imports/pictures')
                        ->multiple()
                        ->maxFiles(100)
                        ->preserveFilenames()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(QuestionImage::MAX_BYTES / 1024),
                ])
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');
                    $pictures = array_map(fn (string $picture) => $disk->path($picture), array_values((array) ($data['pictures'] ?? [])));
                    $result = app(QuestionImporter::class)->import($disk->path($data['file']), $pictures);
                    $disk->delete([$data['file'], ...array_values((array) ($data['pictures'] ?? []))]);

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
