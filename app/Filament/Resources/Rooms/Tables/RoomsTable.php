<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Enums\GameMode;
use App\Enums\RoomStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('host.name')
                    ->label('Host')
                    ->placeholder('Demo (no account)')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('game_mode')
                    ->label('Mode')
                    ->badge(),
                // The two bots of a demo are not players.
                TextColumn::make('players_count')
                    ->counts(['players' => fn ($players) => $players->where('is_bot', false)])
                    ->label('Players')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(RoomStatus::class),
                TernaryFilter::make('is_demo')
                    ->label('Demo rooms')
                    ->trueLabel('Only demos')
                    ->falseLabel('Without demos'),
                SelectFilter::make('game_mode')
                    ->label('Mode')
                    ->options(GameMode::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
