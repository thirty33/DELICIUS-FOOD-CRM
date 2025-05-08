<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getSignedUrl(string $filePath, int $expiryDays = 1)
 * @method static array getMultipleSignedUrls(array $filePaths, int $expiryDays = 1)
 * @method static array getProductImageUrl(string $fileName, int $expiryDays = 1)
 * 
 * @see \App\Services\ImageSignerService
 */
class ImageSigner extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'image-signer';
    }
}