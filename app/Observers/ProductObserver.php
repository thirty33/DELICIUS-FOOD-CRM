<?php

namespace App\Observers;

use App\Models\Product;
use App\Facades\ImageSigner;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        // Check if the image field was changed
        if ($product->wasChanged('image')) {
            Log::info('Product image changed, invalidating CloudFront URL', [
                'product_id' => $product->id,
                'old_image' => $product->getOriginal('image'),
                'new_image' => $product->image
            ]);

            // Invalidate the existing CloudFront signed URL
            $product->cloudfront_signed_url = null;
            $product->signed_url_expiration = null;
            
            // Save without triggering events to avoid recursion
            $product->saveQuietly();

            // Optionally generate new signed URL immediately
            if ($product->image) {
                try {
                    $signedUrlData = ImageSigner::getSignedUrl($product->image, 365);
                    
                    // The decorator will handle updating the product with the new URL
                    Log::info('Generated new CloudFront URL for updated image', [
                        'product_id' => $product->id,
                        'new_url' => $signedUrlData['signed_url'] ?? 'not generated'
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to generate CloudFront URL after image update', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Handle the Product "creating" event.
     */
    public function creating(Product $product): void
    {
        // Ensure CloudFront fields are null when creating a new product
        $product->cloudfront_signed_url = null;
        $product->signed_url_expiration = null;
    }

    /**
     * Handle the Product "deleting" event.
     */
    public function deleting(Product $product): void
    {
        // Log when a product with an image is being deleted
        if ($product->image) {
            Log::info('Deleting product with image', [
                'product_id' => $product->id,
                'image' => $product->image
            ]);
        }
    }
}