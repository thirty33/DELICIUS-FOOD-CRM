<?php

namespace App\Exports\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Trait for exports that need to know which chunk is being processed.
 *
 * This trait works with CustomAppendQueryToSheet to receive the current page
 * number before query() is called, allowing exports to load only the IDs
 * needed for the current chunk from S3.
 *
 * Usage:
 * 1. Use this trait in your export class
 * 2. Set $orderLineIdsS3BasePath and $totalChunks in constructor
 * 3. Call getIdsForCurrentChunk() in your query() method
 *
 * The CustomAppendQueryToSheet job will call setCurrentPage() before query()
 * is executed, so your export knows which chunk file to load from S3.
 */
trait HasChunkAwareness
{
    /**
     * Current page number being processed (1-indexed).
     * Set by CustomAppendQueryToSheet before query() is called.
     */
    protected ?int $currentPage = null;

    /**
     * Current chunk size being used.
     * Set by CustomAppendQueryToSheet before query() is called.
     */
    protected ?int $currentChunkSize = null;

    // NOTE: The following properties must be defined in the class using this trait:
    // - protected ?string $orderLineIdsS3BasePath (S3 base path for chunk files)
    // - protected ?int $totalChunks (total number of chunks in S3)

    /**
     * Set the current page number.
     * Called by CustomAppendQueryToSheet before query() is executed.
     *
     * @param int $page Page number (1-indexed)
     * @return self
     */
    public function setCurrentPage(int $page): self
    {
        $this->currentPage = $page;
        return $this;
    }

    /**
     * Set the current chunk size.
     * Called by CustomAppendQueryToSheet before query() is executed.
     *
     * @param int $size Chunk size
     * @return self
     */
    public function setCurrentChunkSize(int $size): self
    {
        $this->currentChunkSize = $size;
        return $this;
    }

    /**
     * Get the current page number.
     *
     * @return int|null
     */
    public function getCurrentPage(): ?int
    {
        return $this->currentPage;
    }

    /**
     * Get the current chunk size.
     *
     * @return int|null
     */
    public function getCurrentChunkSize(): ?int
    {
        return $this->currentChunkSize;
    }

    /**
     * Check if chunk awareness is available (page number has been set).
     *
     * @return bool
     */
    public function hasChunkAwareness(): bool
    {
        return $this->currentPage !== null;
    }

    /**
     * Get IDs for the current chunk from S3.
     *
     * Loads ONLY the chunk file corresponding to the current page,
     * instead of loading all chunks and merging them.
     *
     * @return array Array of IDs for the current chunk
     */
    protected function getIdsForCurrentChunk(): array
    {
        if ($this->orderLineIdsS3BasePath === null) {
            return [];
        }

        if ($this->currentPage === null) {
            Log::warning('HasChunkAwareness: currentPage not set, falling back to loading all chunks', [
                's3_base_path' => $this->orderLineIdsS3BasePath,
            ]);
            return $this->loadAllChunks();
        }

        // Convert 1-indexed page to 0-indexed chunk file
        $chunkIndex = $this->currentPage - 1;

        if ($chunkIndex < 0 || $chunkIndex >= $this->totalChunks) {
            Log::warning('HasChunkAwareness: chunk index out of range', [
                'current_page' => $this->currentPage,
                'chunk_index' => $chunkIndex,
                'total_chunks' => $this->totalChunks,
            ]);
            return [];
        }

        $chunkPath = "{$this->orderLineIdsS3BasePath}-chunk-{$chunkIndex}.json";

        try {
            $json = Storage::disk('s3')->get($chunkPath);
            $ids = json_decode($json, true) ?? [];

            Log::info('HasChunkAwareness: Loaded single chunk from S3', [
                'current_page' => $this->currentPage,
                'chunk_index' => $chunkIndex,
                'chunk_path' => $chunkPath,
                'ids_count' => count($ids),
            ]);

            return $ids;
        } catch (\Exception $e) {
            Log::error('HasChunkAwareness: Failed to load chunk from S3', [
                'chunk_path' => $chunkPath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fallback method to load all chunks (original behavior).
     * Used when chunk awareness is not available.
     *
     * @return array All IDs from all chunks
     */
    protected function loadAllChunks(): array
    {
        if ($this->orderLineIdsS3BasePath === null) {
            return [];
        }

        $allIds = [];

        for ($i = 0; $i < $this->totalChunks; $i++) {
            $chunkPath = "{$this->orderLineIdsS3BasePath}-chunk-{$i}.json";

            try {
                $json = Storage::disk('s3')->get($chunkPath);
                $chunkIds = json_decode($json, true) ?? [];
                $allIds = array_merge($allIds, $chunkIds);
            } catch (\Exception $e) {
                Log::warning('HasChunkAwareness: Failed to load chunk in fallback mode', [
                    'chunk_index' => $i,
                    'chunk_path' => $chunkPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('HasChunkAwareness: Loaded all chunks (fallback mode)', [
            'total_chunks' => $this->totalChunks,
            'total_ids' => count($allIds),
        ]);

        return $allIds;
    }
}