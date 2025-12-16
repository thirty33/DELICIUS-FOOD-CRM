<?php

namespace App\Jobs;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Contracts\Labels\LabelGeneratorInterface;
use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Facades\ImageSigner;
use App\Models\ExportProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    public $timeout = 900; // 15 minutes for PDF generation with large quantities (increased for production)

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180, 300, 600]; // 1min, 3min, 5min, 10min between retries
    }

    private array $productIds;
    private string $elaborationDate;
    private int $exportProcessId;
    private array $quantities;
    private ?string $productionArea;
    private ?string $productionOrderCode;
    private array $startIndex;

    public function __construct(
        array $productIds,
        string $elaborationDate,
        int $exportProcessId,
        array $quantities = [],
        ?string $productionArea = null,
        ?string $productionOrderCode = null,
        array $startIndex = []
    ) {
        $this->productIds = $productIds;
        $this->elaborationDate = $elaborationDate;
        $this->exportProcessId = $exportProcessId;
        $this->quantities = $quantities;
        $this->productionArea = $productionArea;
        $this->productionOrderCode = $productionOrderCode;
        $this->startIndex = $startIndex;
    }

    public function handle(LabelGeneratorInterface $labelGenerator, NutritionalLabelDataPreparerInterface $dataPreparer)
    {

        $exportProcess = ExportProcess::findOrFail($this->exportProcessId);
        $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSING]);

        // Fetch products with nutritional information and label_index using preparer
        $products = $dataPreparer->getExpandedProducts($this->productIds, $this->quantities, $this->startIndex);

        // Set elaboration date and generate PDF
        $labelGenerator->setElaborationDate($this->elaborationDate);
        $pdfContent = $labelGenerator->generate($products);

        // Generate file name with production area and order code
        $productCount = $products->count();
        $productIds = $products->pluck('id')->unique()->sort()->values();
        $firstProduct = $productIds->first();
        $lastProduct = $productIds->last();
        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        // Build file name components
        $fileNameParts = ['etiquetas_nutricionales'];

        // Add production order code if available
        if ($this->productionOrderCode) {
            $fileNameParts[] = $this->productionOrderCode;
        }

        // Add production area if available (sanitize for filename)
        if ($this->productionArea) {
            $sanitizedArea = str_replace([' ', '/', '\\'], '_', $this->productionArea);
            $fileNameParts[] = $sanitizedArea;
        }

        // Add product count and range
        if ($productCount === 1) {
            $fileNameParts[] = "producto_{$firstProduct}";
        } else {
            $uniqueCount = $productIds->count();
            $fileNameParts[] = "{$productCount}_etiquetas";
            $fileNameParts[] = "{$uniqueCount}_productos_{$firstProduct}_al_{$lastProduct}";
        }

        // Add timestamp
        $fileNameParts[] = $timestamp;

        $fileName = implode('_', $fileNameParts) . '.pdf';

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
    }

    public function failed(Throwable $e): void
    {
        ExportErrorHandler::handle($e, $this->exportProcessId, 'job_failure');
    }
}