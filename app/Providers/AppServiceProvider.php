<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Contracts\API\Auth\AuthServiceInterface;
use App\Services\API\V1\AuthSanctumService;
use App\Models\Product;
use App\Models\AdvanceOrderProduct;
use App\Observers\ProductObserver;
use App\Observers\AdvanceOrderProductObserver;
use App\Events\AdvanceOrderExecuted;
use App\Events\AdvanceOrderCancelled;
use App\Listeners\CreateWarehouseTransactionForAdvanceOrder;
use App\Listeners\CancelWarehouseTransactionForAdvanceOrder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            AuthServiceInterface::class,
            AuthSanctumService::class,
        );

        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
        
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Product Observer
        Product::observe(ProductObserver::class);

        // Register the AdvanceOrderProduct Observer
        AdvanceOrderProduct::observe(AdvanceOrderProductObserver::class);

        // Register event listeners
        Event::listen(
            AdvanceOrderExecuted::class,
            CreateWarehouseTransactionForAdvanceOrder::class,
        );

        Event::listen(
            AdvanceOrderCancelled::class,
            CancelWarehouseTransactionForAdvanceOrder::class,
        );
    }
}
