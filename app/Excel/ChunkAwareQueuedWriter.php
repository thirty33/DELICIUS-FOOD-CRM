<?php

namespace App\Excel;

use App\Jobs\ChunkAwareAppendQueryToSheet;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Files\TemporaryFile;
use Maatwebsite\Excel\Files\TemporaryFileFactory;
use Maatwebsite\Excel\Jobs\AppendDataToSheet;
use Maatwebsite\Excel\Jobs\AppendPaginatedToSheet;
use Maatwebsite\Excel\Jobs\AppendViewToSheet;
use Maatwebsite\Excel\Jobs\CloseSheet;
use Maatwebsite\Excel\Jobs\QueueExport;
use Maatwebsite\Excel\Jobs\StoreQueuedExport;
use Maatwebsite\Excel\QueuedWriter;
use Maatwebsite\Excel\Writer;
use Traversable;

/**
 * Custom QueuedWriter that extends the original to use ChunkAwareAppendQueryToSheet
 * for FromQuery exports.
 *
 * This allows exports to know which chunk they're processing
 * and load only the relevant IDs from S3 instead of all IDs.
 *
 * Since the parent methods are private, we need to override the store() method
 * and reimplement the job building logic with our custom job class.
 */
class ChunkAwareQueuedWriter extends QueuedWriter
{
    /**
     * @var Writer
     */
    protected $writer;

    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @var TemporaryFileFactory
     */
    protected $temporaryFileFactory;

    /**
     * @param  Writer  $writer
     * @param  TemporaryFileFactory  $temporaryFileFactory
     */
    public function __construct(Writer $writer, TemporaryFileFactory $temporaryFileFactory)
    {
        parent::__construct($writer, $temporaryFileFactory);
        $this->writer = $writer;
        $this->chunkSize = config('excel.exports.chunk_size', 1000);
        $this->temporaryFileFactory = $temporaryFileFactory;
    }

    /**
     * Override store to use our custom job building logic.
     *
     * @param  object  $export
     * @param  string  $filePath
     * @param  string|null  $disk
     * @param  string|null  $writerType
     * @param  array|string  $diskOptions
     * @return PendingDispatch
     */
    public function store($export, string $filePath, ?string $disk = null, ?string $writerType = null, $diskOptions = [])
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $temporaryFile = $this->temporaryFileFactory->make($extension);

        $jobs = $this->buildExportJobsWithChunkAwareness($export, $temporaryFile, $writerType);

        $jobs->push(new StoreQueuedExport(
            $temporaryFile,
            $filePath,
            $disk,
            $diskOptions
        ));

        return new PendingDispatch(
            (new QueueExport($export, $temporaryFile, $writerType))->chain($jobs->toArray())
        );
    }

    /**
     * Build export jobs using ChunkAwareAppendQueryToSheet for FromQuery exports.
     *
     * @param  object  $export
     * @param  TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @return Collection
     */
    private function buildExportJobsWithChunkAwareness($export, TemporaryFile $temporaryFile, string $writerType): Collection
    {
        $sheetExports = [$export];
        if ($export instanceof WithMultipleSheets) {
            $sheetExports = $export->sheets();
        }

        $jobs = new Collection;
        foreach ($sheetExports as $sheetIndex => $sheetExport) {
            if ($sheetExport instanceof FromCollection) {
                $jobs = $jobs->merge($this->exportCollectionChunkAware($sheetExport, $temporaryFile, $writerType, $sheetIndex));
            } elseif ($sheetExport instanceof FromQuery) {
                // This is the key difference: use our custom method that creates ChunkAwareAppendQueryToSheet
                $jobs = $jobs->merge($this->exportQueryChunkAware($sheetExport, $temporaryFile, $writerType, $sheetIndex));
            } elseif ($sheetExport instanceof FromView) {
                $jobs = $jobs->merge($this->exportViewChunkAware($sheetExport, $temporaryFile, $writerType, $sheetIndex));
            }

            $jobs->push(new CloseSheet($sheetExport, $temporaryFile, $writerType, $sheetIndex));
        }

        return $jobs;
    }

    /**
     * Export query using ChunkAwareAppendQueryToSheet.
     *
     * This is the key method that uses our custom job to inject page numbers
     * before serialization.
     *
     * @param  FromQuery  $export
     * @param  TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @param  int  $sheetIndex
     * @return Collection
     */
    private function exportQueryChunkAware(
        FromQuery $export,
        TemporaryFile $temporaryFile,
        string $writerType,
        int $sheetIndex
    ): Collection {
        $query = $export->query();

        // Handle Scout builder separately
        if ($query instanceof \Laravel\Scout\Builder) {
            return $this->exportScoutChunkAware($export, $temporaryFile, $writerType, $sheetIndex);
        }

        $count = $export instanceof WithCustomQuerySize ? $export->querySize() : $query->count();
        $chunkSize = $this->getChunkSizeFor($export);
        $spins = ceil($count / $chunkSize);

        $jobs = new Collection();

        for ($page = 1; $page <= $spins; $page++) {
            // Use ChunkAwareAppendQueryToSheet which injects page number before serialization
            $jobs->push(new ChunkAwareAppendQueryToSheet(
                $export,
                $temporaryFile,
                $writerType,
                $sheetIndex,
                $page,
                $chunkSize
            ));
        }

        return $jobs;
    }

    /**
     * Handle Scout builder exports.
     *
     * @param  FromQuery  $export
     * @param  TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @param  int  $sheetIndex
     * @return Collection
     */
    private function exportScoutChunkAware(
        FromQuery $export,
        TemporaryFile $temporaryFile,
        string $writerType,
        int $sheetIndex
    ): Collection {
        $jobs = new Collection();

        $chunk = $export->query()->paginate($this->getChunkSizeFor($export));
        // Append first page
        $jobs->push(new AppendDataToSheet(
            $export,
            $temporaryFile,
            $writerType,
            $sheetIndex,
            $chunk->items()
        ));

        // Append rest of pages
        for ($page = 2; $page <= $chunk->lastPage(); $page++) {
            $jobs->push(new AppendPaginatedToSheet(
                $export,
                $temporaryFile,
                $writerType,
                $sheetIndex,
                $page,
                $this->getChunkSizeFor($export)
            ));
        }

        return $jobs;
    }

    /**
     * Export collection (same as parent).
     *
     * @param  FromCollection  $export
     * @param  TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @param  int  $sheetIndex
     * @return Collection|LazyCollection
     */
    private function exportCollectionChunkAware(
        FromCollection $export,
        TemporaryFile $temporaryFile,
        string $writerType,
        int $sheetIndex
    ) {
        return $export
            ->collection()
            ->chunk($this->getChunkSizeFor($export))
            ->map(function ($rows) use ($writerType, $temporaryFile, $sheetIndex, $export) {
                if ($rows instanceof Traversable) {
                    $rows = iterator_to_array($rows);
                }

                return new AppendDataToSheet(
                    $export,
                    $temporaryFile,
                    $writerType,
                    $sheetIndex,
                    $rows
                );
            });
    }

    /**
     * Export view (same as parent).
     *
     * @param  FromView  $export
     * @param  TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @param  int  $sheetIndex
     * @return Collection
     */
    private function exportViewChunkAware(
        FromView $export,
        TemporaryFile $temporaryFile,
        string $writerType,
        int $sheetIndex
    ): Collection {
        $jobs = new Collection();
        $jobs->push(new AppendViewToSheet(
            $export,
            $temporaryFile,
            $writerType,
            $sheetIndex
        ));

        return $jobs;
    }

    /**
     * Get the chunk size for the export.
     *
     * @param  object|WithCustomChunkSize  $export
     * @return int
     */
    private function getChunkSizeFor($export): int
    {
        if ($export instanceof WithCustomChunkSize) {
            return $export->chunkSize();
        }

        return $this->chunkSize;
    }
}