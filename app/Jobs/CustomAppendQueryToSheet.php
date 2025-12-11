<?php

namespace App\Jobs;

use Maatwebsite\Excel\Jobs\AppendQueryToSheet;
use Maatwebsite\Excel\Writer;

/**
 * Custom AppendQueryToSheet job that injects page number before calling query().
 *
 * This solves the problem where Laravel Excel's FromQuery exports don't know
 * which chunk is being processed, causing exports with chunked S3 IDs to load
 * ALL chunks instead of just the one being processed.
 *
 * @see https://github.com/SpartnerNL/Laravel-Excel/issues/2881
 */
class CustomAppendQueryToSheet extends AppendQueryToSheet
{
    /**
     * Handle the job.
     *
     * Injects the current page and chunk size into the export before calling
     * the parent handler, which calls query() without passing these values.
     *
     * @param Writer $writer
     * @return void
     */
    public function handle(Writer $writer)
    {
        // Inject page and chunk size BEFORE query() is called
        if (method_exists($this->sheetExport, 'setCurrentPage')) {
            $this->sheetExport->setCurrentPage($this->page);
        }

        if (method_exists($this->sheetExport, 'setCurrentChunkSize')) {
            $this->sheetExport->setCurrentChunkSize($this->chunkSize);
        }

        return parent::handle($writer);
    }
}