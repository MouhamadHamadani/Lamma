<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Category;
use App\Models\Room;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RoomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code')->fontFamily('mono'),
                TextEntry::make('host.name')->label('Host'),
                TextEntry::make('status')->badge(),
                TextEntry::make('players_count')
                    ->label('Players')
                    ->state(fn (Room $record): int => $record->players()->count()),
                TextEntry::make('started_at')->dateTime()->placeholder('-'),
                TextEntry::make('finished_at')->dateTime()->placeholder('-'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('game_mode')
                    ->label('Mode')
                    ->badge(),
                // Vote mode: the categories actually played (the first, then each vote's winner), not the pool.
                TextEntry::make('categories')
                    ->state(fn (Room $record): array => Category::whereIn('id', $record->categoryIds())->get()->map->name->all())
                    ->badge()
                    ->placeholder('All'),
                TextEntry::make('pool')
                    ->label('Vote pool')
                    ->state(fn (Room $record): array => $record->pool()->get()->map->name->all())
                    ->badge()
                    ->visible(fn (Room $record): bool => $record->isVote()),
                TextEntry::make('vote_every')
                    ->label('Vote every')
                    ->suffix(' questions')
                    ->visible(fn (Room $record): bool => $record->isVote()),
                TextEntry::make('question_count')
                    ->state(fn (Room $record): int => $record->settings->questionCount),
                TextEntry::make('seconds_per_question')
                    ->state(fn (Room $record): int => $record->settings->secondsPerQuestion),
                TextEntry::make('difficulty')
                    ->state(fn (Room $record) => $record->settings->difficulty)
                    ->badge()
                    ->placeholder('Any'),
                TextEntry::make('host_screen_locale')
                    ->state(fn (Room $record) => $record->settings->hostScreenLocale),
                TextEntry::make('show_photos')
                    ->label('Player photos')
                    ->state(fn (Room $record): string => $record->settings->showPhotos ? 'On' : 'Off')
                    ->badge(),
                TextEntry::make('double_final')
                    ->label('Double last question')
                    ->state(fn (Room $record): string => $record->settings->doubleFinal ? 'On' : 'Off')
                    ->badge(),
            ])
            ->columns(3);
    }
}
