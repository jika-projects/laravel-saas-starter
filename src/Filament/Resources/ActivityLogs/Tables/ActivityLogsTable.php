<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->label('Action')
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Subject Type')
                    ->badge(),
                TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->numeric(),
                TextColumn::make('causer.name')
                    ->label('User')
                    ->default('System'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ]);
    }
}
