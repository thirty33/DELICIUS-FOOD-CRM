<?php

namespace App\Forms;

use App\Enums\OrderStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Branch;
use Filament\Forms;

class OrderExportFilterForm
{
    /**
     * Get the form schema for order export filters
     *
     * @return array
     */
    public static function getSchema(): array
    {
        return [
            Forms\Components\Select::make('user_roles')
                ->label('Tipo de usuario')
                ->multiple()
                ->options([
                    RoleName::ADMIN->value => 'Admin',
                    RoleName::CAFE->value => 'Café',
                    RoleName::AGREEMENT->value => 'Convenio',
                ])
                ->default([
                    RoleName::CAFE->value,
                    RoleName::AGREEMENT->value,
                ])
                ->placeholder('Seleccionar tipos de usuario')
                ->helperText('Dejar vacío para incluir todos los tipos'),
            Forms\Components\Select::make('agreement_types')
                ->label('Tipo de convenio')
                ->multiple()
                ->options([
                    PermissionName::CONSOLIDADO->value => 'Consolidado',
                    PermissionName::INDIVIDUAL->value => 'Individual',
                ])
                ->default([
                    PermissionName::CONSOLIDADO->value,
                    PermissionName::INDIVIDUAL->value,
                ])
                ->placeholder('Seleccionar tipos de convenio')
                ->helperText('Dejar vacío para incluir todos los tipos. Solo aplica si se seleccionó "Convenio" en tipo de usuario'),
            Forms\Components\Select::make('branch_ids')
                ->label('Sucursales')
                ->multiple()
                ->options(function () {
                    return Branch::query()
                        ->orderBy('fantasy_name')
                        ->get()
                        ->mapWithKeys(function ($branch) {
                            return [$branch->id => ($branch->branch_code ?? 'N/A') . ' - ' . $branch->fantasy_name];
                        });
                })
                ->searchable()
                ->preload()
                ->placeholder('Seleccionar sucursales')
                ->helperText('Dejar vacío para incluir todas las sucursales'),
            Forms\Components\Select::make('order_statuses')
                ->label('Estados de pedidos')
                ->multiple()
                ->options(OrderStatus::getSelectOptions())
                ->default([
                    OrderStatus::PROCESSED->value,
                    OrderStatus::PARTIALLY_SCHEDULED->value,
                ])
                ->required()
                ->helperText('Seleccione los estados de pedidos a incluir'),
        ];
    }
}
