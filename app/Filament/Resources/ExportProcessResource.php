<?php

namespace App\Filament\Resources;

use App\Exports\ImportErrorLogExport;
use App\Filament\Resources\ExportProcessResource\Pages;
use App\Models\ExportProcess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportProcessResource extends Resource
{
    protected static ?string $model = ExportProcess::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square';

    protected static ?string $navigationLabel = 'Procesos de Exportación';

    protected static ?string $modelLabel = 'Proceso de Exportación';

    protected static ?string $pluralModelLabel = 'Procesos de Exportación';

    protected static ?int $navigationSort = 101;

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
                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) > 50) {
                            return $state;
                        }
                        return null;
                    })
                    ->searchable()
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
                        ExportProcess::getValidTypes(),
                        ExportProcess::getValidTypes()
                    )),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(array_combine(
                        ExportProcess::getValidStatuses(),
                        ExportProcess::getValidStatuses()
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
                Tables\Actions\Action::make('download_file')
                    ->label('Descargar Archivo')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn($record) => !empty($record->file_url) && $record->status === ExportProcess::STATUS_PROCESSED)
                    ->action(function ($record) {
                        // Obtener el nombre del archivo desde la URL
                        $filePath = parse_url($record->file_url, PHP_URL_PATH);
                        $filePath = ltrim($filePath, '/');

                        // Generar nombre personalizado basado en tipo y descripción
                        $originalFileName = basename($filePath);
                        $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);

                        $customFileName = $record->type;
                        if ($record->description) {
                            $customFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $record->description);
                        }

                        $downloadName = $customFileName . '_' . $record->created_at->format('Ymd_His') . '.' . $extension;

                        return Storage::disk('s3')->download($filePath, $downloadName);
                    }),
                Tables\Actions\Action::make('download_log')
                    ->label('Descargar Log')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn($record) => !empty($record->error_log) && $record->status === ExportProcess::STATUS_PROCESSED_WITH_ERRORS)
                    ->action(function ($record) {

                        $fileName = "logs/import_errors/log_errores_importacion_{$record->id}_" . time() . '.xlsx';

                        Excel::store(
                            new ImportErrorLogExport($record->error_log),
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
            ->bulkActions([])
            ->headerActions([
                Tables\Actions\Action::make('reload')
                    ->label('Recargar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function () {
                        return redirect()->back();
                    })
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExportProcesses::route('/'),
        ];
    }
}
