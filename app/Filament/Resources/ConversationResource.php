<?php

namespace App\Filament\Resources;

use App\Enums\ConversationStatus;
use App\Filament\Resources\ConversationResource\Pages;
use App\Models\Conversation;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 80;

    public static function getNavigationGroup(): ?string
    {
        return 'Canales de Venta';
    }

    public static function getLabel(): ?string
    {
        return __('Conversación');
    }

    public static function getNavigationLabel(): string
    {
        return __('Conversaciones');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Conversaciones');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label(__('Cliente'))
                    ->searchable()
                    ->default('Sin nombre'),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('Teléfono'))
                    ->searchable()
                    ->formatStateUsing(fn (string $state) => '+'.$state),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestMessage.body')
                    ->label(__('Último mensaje'))
                    ->limit(50)
                    ->default('—'),
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label(__('Fecha último mensaje'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->default('—'),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->multiple()
                    ->options(ConversationStatus::class),
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('Empresa'))
                    ->multiple()
                    ->relationship('company', 'fantasy_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => ($record->company_code ?? 'N/A').' - '.$record->fantasy_name
                    )
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label(__('Sucursal'))
                    ->multiple()
                    ->relationship('branch', 'fantasy_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => ($record->branch_code ?? 'N/A').' - '.$record->fantasy_name
                    )
                    ->searchable()
                    ->preload(),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\Action::make('openChat')
                    ->label(__('Abrir Chat'))
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->url(fn (Conversation $record) => url('/chat/'.$record->id)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConversations::route('/'),
        ];
    }
}
