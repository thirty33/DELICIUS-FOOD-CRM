<?php

namespace App\Jobs;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Contracts\Labels\LabelGeneratorInterface;
use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Facades\ImageSigner;
use App\Models\ExportProcess;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateNutritionalLabelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes for PDF generation with large quantities

    private array $productIds;
    private string $elaborationDate;
    private int $exportProcessId;
    private array $quantities;

    public function __construct(array $productIds, string $elaborationDate, int $exportProcessId, array $quantities = [])
    {
        $this->productIds = $productIds;
        $this->elaborationDate = $elaborationDate;
        $this->exportProcessId = $exportProcessId;
        $this->quantities = $quantities;
    }

    public function handle(LabelGeneratorInterface $labelGenerator, NutritionalInformationRepositoryInterface $repository)
    {
        $exportProcess = ExportProcess::findOrFail($this->exportProcessId);
        $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSING]);

        // Fetch products with nutritional information using repository
        // Repository will handle quantities and repeat products as needed
        $products = $repository->getProductsForLabelGeneration($this->productIds, $this->quantities);

        if ($products->isEmpty()) {
            throw new \Exception('No se encontraron productos con información nutricional y etiqueta habilitada');
        }

        // Set elaboration date and generate PDF
        $labelGenerator->setElaborationDate($this->elaborationDate);
        $pdfContent = $labelGenerator->generate($products);

        // Generate file name
        $productCount = $products->count();
        $productIds = $products->pluck('id')->sort()->values();
        $firstProduct = $productIds->first();
        $lastProduct = $productIds->last();
        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        if ($productCount === 1) {
            $fileName = "etiqueta_nutricional_producto_{$firstProduct}_{$timestamp}.pdf";
        } else {
            $fileName = "etiquetas_nutricionales_{$productCount}_productos_{$firstProduct}_al_{$lastProduct}_{$timestamp}.pdf";
        }

        $s3Path = "pdfs/nutritional-labels/{$dateStr}/{$fileName}";

        // Upload to S3
        $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

        if (!$uploadResult) {
            throw new \Exception('Error al subir PDF a S3');
        }

        // Generate signed URL
        $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);

        // Update export process
        $exportProcess->update([
            'status' => ExportProcess::STATUS_PROCESSED,
            'file_url' => $signedUrlData['signed_url']
        ]);

        Log::info('Nutritional labels generated successfully', [
            'export_process_id' => $this->exportProcessId,
            'product_count' => $productCount,
            'file_path' => $s3Path
        ]);
    }

    public function failed(Throwable $e): void
    {
        ExportErrorHandler::handle($e, $this->exportProcessId, 'job_failure');

        Log::error('Job de generación de etiquetas nutricionales falló', [
            'export_process_id' => $this->exportProcessId,
            'product_ids' => $this->productIds,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}