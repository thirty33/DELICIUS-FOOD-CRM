<?php

namespace App\Jobs;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Facades\ImageSigner;
use App\Models\ExportProcess;
use App\Services\Labels\Generators\HorecaLabelGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateHorecaLabelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes for PDF generation

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

    private array $labelData;
    private string $elaborationDate;
    private int $exportProcessId;
    private int $advanceOrderId;

    /**
     * Create a new job instance
     *
     * @param array $labelData Collection of label data arrays (ingredient_name, net_weight, etc.)
     * @param string $elaborationDate Elaboration date in d/m/Y format
     * @param int $exportProcessId Export process ID for tracking
     * @param int $advanceOrderId Advance order ID for reference
     */
    public function __construct(
        array $labelData,
        string $elaborationDate,
        int $exportProcessId,
        int $advanceOrderId
    ) {
        $this->labelData = $labelData;
        $this->elaborationDate = $elaborationDate;
        $this->exportProcessId = $exportProcessId;
        $this->advanceOrderId = $advanceOrderId;
    }

    /**
     * Execute the job
     *
     * @param HorecaLabelGenerator $labelGenerator
     * @return void
     * @throws \Exception
     */
    public function handle(HorecaLabelGenerator $labelGenerator): void
    {
        $exportProcess = ExportProcess::findOrFail($this->exportProcessId);
        $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSING]);

        // Convert array to collection for generator
        $labelCollection = collect($this->labelData);

        // Set elaboration date and generate PDF
        $labelGenerator->setElaborationDate($this->elaborationDate);
        $pdfContent = $labelGenerator->generate($labelCollection);

        // Generate file name
        $labelCount = $labelCollection->count();
        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        // Build file name: horeca_labels_OP{id}_{count}_etiquetas_{timestamp}.pdf
        $fileName = "horeca_labels_OP{$this->advanceOrderId}_{$labelCount}_etiquetas_{$timestamp}.pdf";

        $s3Path = "pdfs/horeca-labels/{$dateStr}/{$fileName}";

        // Upload to S3
        $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

        if (!$uploadResult) {
            throw new \Exception('Error al subir PDF de etiquetas HORECA a S3');
        }

        // Generate signed URL (valid for 1 day)
        $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);

        // Update export process with success
        $exportProcess->update([
            'status' => ExportProcess::STATUS_PROCESSED,
            'file_url' => $signedUrlData['signed_url']
        ]);
    }

    /**
     * Handle job failure
     *
     * @param Throwable $e
     * @return void
     */
    public function failed(Throwable $e): void
    {
        ExportErrorHandler::handle($e, $this->exportProcessId, 'horeca_label_job_failure');
    }
}