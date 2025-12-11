<?php

namespace App\Jobs;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Files\TemporaryFile;
use Maatwebsite\Excel\Jobs\AppendQueryToSheet;
use Maatwebsite\Excel\Jobs\Middleware\LocalizeJob;
use Maatwebsite\Excel\Writer;

/**
 * Custom AppendQueryToSheet that injects the current page number
 * into the export class when the job is HANDLED (after deserialization).
 *
 * This allows exports to know which chunk they're processing
 * and load only the relevant IDs from S3.
 *
 * NOTE: We cannot inject in the constructor because the $sheetExport object
 * is shared across all jobs during creation. We must inject in handle()
 * after deserialization when each job has its own copy.
 */
class ChunkAwareAppendQueryToSheet extends AppendQueryToSheet
{
    /**
     * Handle the job.
     *
     * Injects the page number into the export BEFORE calling query(),
     * so the export knows which chunk to load from S3.
     *
     * When using chunk awareness (HasChunkAwareness trait), the export's query()
     * already returns only the IDs for the current chunk, so we DON'T need forPage().
     * When NOT using chunk awareness, we fall back to the standard forPage() behavior.
     *
     * @param  Writer  $writer
     * @return void
     */
    public function handle(Writer $writer)
    {
        // Inject page number AFTER deserialization, BEFORE query() is called
        // Now each job has its own copy of $sheetExport
        if (method_exists($this->sheetExport, 'setCurrentPage')) {
            $this->sheetExport->setCurrentPage($this->page);
        }

        if (method_exists($this->sheetExport, 'setCurrentChunkSize')) {
            $this->sheetExport->setCurrentChunkSize($this->chunkSize);
        }

        // Check if export has chunk awareness (loads IDs per chunk from S3)
        $hasChunkAwareness = method_exists($this->sheetExport, 'hasChunkAwareness')
            && $this->sheetExport->hasChunkAwareness();

        // Now call the parent handle which will call query()
        (new LocalizeJob($this->sheetExport))->handle($this, function () use ($writer, $hasChunkAwareness) {
            if ($this->sheetExport instanceof WithEvents) {
                $this->registerListeners($this->sheetExport->registerEvents());
            }

            $writer = $writer->reopen($this->temporaryFile, $this->writerType);

            $sheet = $writer->getSheetByIndex($this->sheetIndex);

            // When using chunk awareness, query() already returns only the IDs for this chunk
            // so we DON'T need forPage() - it would incorrectly slice the already-filtered results
            // When NOT using chunk awareness, use standard forPage() pagination
            if ($hasChunkAwareness) {
                $query = $this->sheetExport->query();
            } else {
                $query = $this->sheetExport->query()->forPage($this->page, $this->chunkSize);
            }

            $sheet->appendRows($query->get(), $this->sheetExport);

            $writer->write($this->sheetExport, $this->temporaryFile, $this->writerType);

            $this->raise(new AfterChunk($sheet, $this->sheetExport, ($this->page - 1) * $this->chunkSize));
            $this->clearListeners();
        });
    }
}