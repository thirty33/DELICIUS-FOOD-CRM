<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportProcessResource\Pages;
use App\Models\ImportProcess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Database\Eloquent\Builder;
use App\Exports\ImportErrorLogExport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportProcessResource extends Resource
{
    protected static ?string $model = ImportProcess::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected static ?string $navigationLabel = 'Procesos de Importación';

    protected static ?string $modelLabel = 'Proceso de Importación';

    protected static ?string $pluralModelLabel = 'Procesos de Importación';

    protected static ?int $navigationSort = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('Módulo de Importación/Exportación');
    }
    
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'en cola' => 'gray',
                        'procesando' => 'warning',
                        'procesado' => 'success',
                        'procesado con errores' => 'danger',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(array_combine(
                        ImportProcess::getValidTypes(),
                        ImportProcess::getValidTypes()
                    )),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(array_combine(
                        ImportProcess::getValidStatuses(),
                        ImportProcess::getValidStatuses()
                    )),
                Filter::make('created_at')
                    ->label('Fecha')
                    ->form([
                        DateTimePicker::make('start_date')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y H:i:s'),
                        DateTimePicker::make('end_date')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y H:i:s'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['start_date'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['end_date'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    })
            ])
            ->actions([
                Tables\Actions\Action::make('download_log')
                    ->label('Descargar Log')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn($record) => $record->status === ImportProcess::STATUS_PROCESSED_WITH_ERRORS)
                    ->action(function ($record) {

                        $fullRecord = ImportProcess::find($record->id);
        
                        if (empty($fullRecord->error_log)) {
                            return;
                        }

                        $fileName = "logs/import_errors/log_errores_importacion_{$record->id}_" . time() . '.xlsx';

                        Excel::store(
                            new ImportErrorLogExport($fullRecord->error_log),
                            $fileName,
                            's3',
                            \Maatwebsite\Excel\Excel::XLSX
                        );

                        $fileUrl = Storage::disk('s3')->url($fileName);

                        $record->update([
                            'file_error_url' => $fileUrl
                        ]);

                        return Storage::disk('s3')->download($fileName);

                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reload')
                    ->label('Recargar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function () {
                        return redirect()->back();
                    })
            ])
            ->bulkActions([])
            ->modifyQueryUsing(function (Builder $query) {
                return $query->select('id', 'created_at', 'type', 'status', 'file_url', 'file_error_url');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportProcesses::route('/'),
        ];
    }
}
