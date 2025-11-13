<?php

namespace Tests\Traits;

/**
 * Trait ConfiguresImportTests
 *
 * Restores the original test configuration for import tests that was changed
 * when .env.testing was modified to use:
 * - QUEUE_CONNECTION=sync
 * - FILESYSTEM_DISK=local
 * - EXCEL_REMOTE_DISK=null
 *
 * These changes broke import tests because they expect:
 * - Queue to run synchronously (sync) ✓
 * - Filesystem to use S3 (for Storage::fake('s3'))
 * - Excel to use S3 as remote disk
 *
 * Usage:
 * ```php
 * use Tests\Traits\ConfiguresImportTests;
 *
 * class MyImportTest extends TestCase
 * {
 *     use ConfiguresImportTests;
 *
 *     public function test_my_import(): void
 *     {
 *         $this->configureImportTest();
 *         Storage::fake('s3');
 *         // ... rest of test
 *     }
 * }
 * ```
 */
trait ConfiguresImportTests
{
    /**
     * Configure the test environment for import tests
     *
     * This method restores the original configuration that was in place
     * before .env.testing was changed, ensuring imports work correctly.
     */
    protected function configureImportTest(): void
    {
        // CRITICAL SAFETY: Force testing database to prevent production data deletion
        // Laravel sometimes loads .env instead of .env.testing, so we FORCE it here
        config(['database.connections.mysql.database' => 'testing']);

        // Queue must run synchronously for tests
        config(['queue.default' => 'sync']);

        // Filesystem must be S3 (for Storage::fake('s3') to work)
        config(['filesystems.default' => 's3']);

        // Excel must use S3 as remote disk (as it was before EXCEL_REMOTE_DISK=null)
        config(['excel.temporary_files.remote_disk' => 's3']);
    }
}
