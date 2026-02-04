<?php

namespace App\Filament\Resources\ConversationResource\Pages;

use App\Actions\Conversations\CreateConversationAction;
use App\Filament\Resources\ConversationResource;
use App\Models\Branch;
use App\Models\Company;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    protected ?string $pollingInterval = '30s';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('newConversation')
                ->label(__('Nueva Conversación'))
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\Radio::make('source_type')
                        ->label(__('Buscar por'))
                        ->options([
                            'company' => __('Empresa'),
                            'branch' => __('Sucursal'),
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('company_id', null);
                            $set('branch_id', null);
                            $set('phone_preview', null);
                        }),

                    Forms\Components\Select::make('company_id')
                        ->label(__('Empresa'))
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) {
                            return Company::where('name', 'like', "%{$search}%")
                                ->orWhere('fantasy_name', 'like', "%{$search}%")
                                ->orWhere('tax_id', 'like', "%{$search}%")
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Company $c) => [
                                    $c->id => "{$c->fantasy_name} — {$c->name} ({$c->tax_id})",
                                ]);
                        })
                        ->visible(fn (Forms\Get $get) => $get('source_type') === 'company')
                        ->required(fn (Forms\Get $get) => $get('source_type') === 'company')
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) {
                                $set('phone_preview', null);
                                return;
                            }
                            $company = Company::find($state);
                            $phone = $company?->routeNotificationForWhatsApp();
                            $set('phone_preview', $phone ?: __('Sin teléfono registrado'));
                        }),

                    Forms\Components\Select::make('branch_id')
                        ->label(__('Sucursal'))
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) {
                            return Branch::where('fantasy_name', 'like', "%{$search}%")
                                ->orWhere('contact_name', 'like', "%{$search}%")
                                ->orWhere('address', 'like', "%{$search}%")
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Branch $b) => [
                                    $b->id => "{$b->fantasy_name} — {$b->contact_name} ({$b->address})",
                                ]);
                        })
                        ->visible(fn (Forms\Get $get) => $get('source_type') === 'branch')
                        ->required(fn (Forms\Get $get) => $get('source_type') === 'branch')
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) {
                                $set('phone_preview', null);
                                return;
                            }
                            $branch = Branch::find($state);
                            $phone = $branch?->routeNotificationForWhatsApp();
                            $set('phone_preview', $phone ?: __('Sin teléfono registrado'));
                        }),

                    Forms\Components\Placeholder::make('phone_preview')
                        ->label(__('Teléfono'))
                        ->content(function (Forms\Get $get) {
                            $preview = $get('phone_preview');
                            if (! $preview) {
                                return __('Selecciona una empresa o sucursal');
                            }
                            if ($preview === __('Sin teléfono registrado')) {
                                return $preview;
                            }
                            return '+' . $preview;
                        })
                        ->visible(fn (Forms\Get $get) => $get('source_type') !== null),
                ])
                ->modalSubmitActionLabel(__('Iniciar Chat'))
                ->action(function (array $data) {
                    try {
                        $conversation = CreateConversationAction::execute($data);
                        $this->redirect(url('/chat/' . $conversation->id));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title(__('No se puede iniciar el chat'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}