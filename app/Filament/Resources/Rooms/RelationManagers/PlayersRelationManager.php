<?php

namespace App\Filament\Resources\Rooms\RelationManagers;

use App\Game\PlayerPhoto;
use App\Game\RoomRoster;
use App\Models\RoomPlayer;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The players of a room, with their selfie as a thumbnail. A moderation tool only: nothing here adds or changes a player, the one action takes a
 * photo down (the file is deleted now and the phone says "The host removed your photo"). Photos are deleted by `lamma:prune` anyway once the room
 * is over (lamma.photo_retention_hours).
 */
class PlayersRelationManager extends RelationManager
{
    protected static string $relationship = 'players';

    protected static ?string $title = 'Players';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nickname')
            ->columns([
                ImageColumn::make('avatar_path')->label('Photo')->disk(PlayerPhoto::DISK)->visibility('public')->circular()->imageSize(56),
                TextColumn::make('nickname')->searchable(),
                TextColumn::make('locale')->badge(),
                TextColumn::make('score')->numeric()->sortable(),
                IconColumn::make('is_ready')->boolean()->label('Ready'),
                TextColumn::make('avatar_updated_at')->label('Photo taken')->dateTime()->placeholder('-'),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->recordActions([
                Action::make('removePhoto')
                    ->label('Remove photo')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove this photo?')
                    ->modalDescription('The file is deleted now and the player sees "The host removed your photo". They keep playing with their initial.')
                    ->visible(fn (RoomPlayer $record): bool => $record->avatar_path !== null)
                    ->action(fn (RoomPlayer $record) => app(RoomRoster::class)->removePhotoAsAdmin($record)),
            ]);
    }
}
