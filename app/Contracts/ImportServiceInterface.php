<?php

namespace App\Contracts;

use App\Models\ImportProcess;

interface ImportServiceInterface
{
    /**
     * Execute an import operation
     *
     * @param string $importerClass Fully qualified importer class name
     * @param string $filePath Path to the Excel file to import
     * @param string $importType Type of import (use ImportProcess::TYPE_* constants)
     * @param array $importerArguments Arguments to pass to the importer constructor
     * @param string|null $disk Storage disk (default: null for local, 's3' for S3)
     * @return ImportProcess The import process record with updated status and errors
     */
    public function import(
        string $importerClass,
        string $filePath,
        string $importType,
        array $importerArguments = [],
        ?string $disk = null
    ): ImportProcess;

    /**
     * Execute import with repository injection (for imports that need repositories)
     *
     * @param string $importerClass Fully qualified importer class name
     * @param string $filePath Path to the Excel file to import
     * @param string $importType Type of import (use ImportProcess::TYPE_* constants)
     * @param object $repository Repository instance to inject
     * @param string|null $disk Storage disk (default: null for local, 's3' for S3)
     * @return ImportProcess The import process record with updated status and errors
     */
    public function importWithRepository(
        string $importerClass,
        string $filePath,
        string $importType,
        object $repository,
        ?string $disk = null
    ): ImportProcess;
}