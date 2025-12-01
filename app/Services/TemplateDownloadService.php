<?php

namespace App\Services;

use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Template Download Service
 *
 * Service for downloading Excel template files.
 * Provides a standardized way to download template exports across the application.
 *
 * Usage:
 * $service = new TemplateDownloadService();
 * return $service->download(
 *     exporterClass: NutritionalInformationTemplateExport::class,
 *     filename: 'template_importacion_info_nutricional.xlsx'
 * );
 */
class TemplateDownloadService
{
    /**
     * Download an Excel template file
     *
     * @param string $exporterClass Fully qualified class name of the export class (must implement FromArray)
     * @param string $filename Name of the file to download (with .xlsx extension)
     * @return BinaryFileResponse
     * @throws \Exception If exporter class doesn't exist or doesn't implement required interfaces
     */
    public function download(string $exporterClass, string $filename): BinaryFileResponse
    {
        // Validate exporter class exists
        if (!class_exists($exporterClass)) {
            throw new \Exception("Exporter class does not exist: {$exporterClass}");
        }

        // Validate filename has .xlsx extension
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            throw new \Exception("Filename must have .xlsx extension: {$filename}");
        }

        // Create instance of exporter class
        $exporter = new $exporterClass();

        // Validate exporter implements FromArray (required for templates)
        if (!($exporter instanceof \Maatwebsite\Excel\Concerns\FromArray)) {
            throw new \Exception("Exporter class must implement FromArray interface: {$exporterClass}");
        }

        // Download the template
        return Excel::download($exporter, $filename);
    }

    /**
     * Download a template with default filename based on exporter class name
     *
     * Example: NutritionalInformationTemplateExport -> nutritional_information_template.xlsx
     *
     * @param string $exporterClass Fully qualified class name of the export class
     * @return BinaryFileResponse
     */
    public function downloadWithAutoFilename(string $exporterClass): BinaryFileResponse
    {
        // Extract class name from fully qualified name
        $className = class_basename($exporterClass);

        // Convert PascalCase to snake_case
        $snakeCaseName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

        // Remove "Export" suffix if present
        $snakeCaseName = preg_replace('/_export$/', '', $snakeCaseName);

        // Add .xlsx extension
        $filename = $snakeCaseName . '.xlsx';

        return $this->download($exporterClass, $filename);
    }
}