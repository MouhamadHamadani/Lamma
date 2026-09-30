<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Enums\RoomStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('players_count')
                    ->counts('players')
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
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
