<?php

namespace Tests\Traits;

/**
 * Trait ConfiguresNutritionalInformationTests
 *
 * Configures the test environment for nutritional information import/export tests.
 * This trait ensures tests run synchronously with proper queue and storage configuration.
 *
 * Key configurations:
 * - Queue: sync (for immediate execution in tests)
 * - Filesystem: S3 (mocked with Storage::fake('s3'))
 * - Excel remote disk: S3
 * - Database: testing (safety measure)
 *
 * Usage:
 * ```php
 * use Tests\Traits\ConfiguresNutritionalInformationTests;
 *
 * class MyNutritionalInfoTest extends TestCase
 * {
 *     use ConfiguresNutritionalInformationTests;
 *
 *     // Hook is automatically called before each test
 *     // No need to call configureNutritionalInformationTest() manually
 * }
 * ```
 */
trait ConfiguresNutritionalInformationTests
{
    /**
     * Setup hook that runs before each test
     *
     * This hook is automatically called by PHPUnit before each test method.
     * It configures the environment and mocks S3 storage.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        $this->configureNutritionalInformationTest();

        // Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');
    }

    /**
     * Configure the test environment for nutritional information tests
     *
     * This method sets up the necessary configuration for imports/exports
     * to work correctly in the test environment.
     */
    protected function configureNutritionalInformationTest(): void
    {
        // CRITICAL SAFETY: Force testing database to prevent production data deletion
        config(['database.connections.mysql.database' => 'testing']);

        // Queue must run synchronously for tests
        // This ensures ShouldQueue imports/exports execute immediately
        config(['queue.default' => 'sync']);

        // Filesystem must be S3 (for Storage::fake('s3') to work)
        config(['filesystems.default' => 's3']);

        // Excel must use S3 as remote disk
        config(['excel.temporary_files.remote_disk' => 's3']);
    }
}