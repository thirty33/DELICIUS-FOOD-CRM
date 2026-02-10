<?php

namespace App\Mcp\Tools;

use App\Exports\MenuDataExport;
use App\Models\ExportProcess;
use App\Models\Menu;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Maatwebsite\Excel\Facades\Excel;

class ExportMenusTool extends Tool
{
    protected string $name = 'export-menus';

    protected string $description = <<<'MARKDOWN'
        Exports menus to an Excel (.xlsx) file compatible with the MenusImport system. Receives an array of menu IDs and generates the file using the existing export pipeline. Returns the file path and export summary.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $menuIds = $request->get('menu_ids', []);
        $filename = $request->get('filename');

        if (empty($menuIds)) {
            return Response::error('The "menu_ids" parameter is required and cannot be empty.');
        }

        $existingMenus = Menu::whereIn('id', $menuIds)->pluck('id');
        $missingIds = array_diff($menuIds, $existingMenus->toArray());

        if (! empty($missingIds)) {
            return Response::error('Menu IDs not found: ' . implode(', ', $missingIds));
        }

        try {
            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_MENUS,
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-',
            ]);

            if (! $filename) {
                $filename = "exports/menus/menus_export_{$exportProcess->id}_" . time() . '.xlsx';
            } else {
                $filename = "exports/menus/{$filename}";
            }

            Excel::store(
                new MenuDataExport($existingMenus, $exportProcess->id),
                $filename,
                's3',
                \Maatwebsite\Excel\Excel::XLSX
            );

            $fileUrl = Storage::disk('s3')->url($filename);
            $exportProcess->update([
                'file_url' => $fileUrl,
            ]);

            $lines = [];
            $lines[] = 'Export completed.';
            $lines[] = '';
            $lines[] = 'Export summary:';
            $lines[] = "- ExportProcess ID: {$exportProcess->id}";
            $lines[] = "- Status: {$exportProcess->status}";
            $lines[] = '- Menus exported: ' . count($menuIds);
            $lines[] = '- Downloadable from the Export Processes module in Filament.';

            return Response::text(implode("\n", $lines));
        } catch (\Exception $e) {
            return Response::error('Export failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'menu_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Array of menu IDs to export.')
                ->required(),
            'filename' => $schema->string()
                ->description('Output filename (without path). Default: menus-export-{timestamp}.xlsx.'),
        ];
    }
}