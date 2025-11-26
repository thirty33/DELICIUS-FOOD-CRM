<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Contracts\API\Auth\AuthServiceInterface;
use App\Services\API\V1\AuthSanctumService;
use App\Contracts\ReportColumnDataProviderInterface;
use App\Services\Reports\ReportGrouperColumnProvider;
use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Repositories\NutritionalInformationRepository;
use App\Contracts\ImportServiceInterface;
use App\Services\ImportService;
use App\Contracts\DeletionServiceInterface;
use App\Services\NutritionalInformationDeletionService;
use App\Jobs\DeleteNutritionalInformationJob;
use App\Contracts\Labels\LabelGeneratorInterface;
use App\Services\Labels\Generators\NutritionalLabelGenerator;
use App\Models\Product;
use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use App\Models\OrderLine;
use App\Observers\ProductObserver;
use App\Observers\AdvanceOrderObserver;
use App\Observers\AdvanceOrderProductObserver;
use App\Observers\AdvanceOrderProductionStatusObserver;
use App\Observers\AdvanceOrderProductProductionStatusObserver;
use App\Observers\OrderLineProductionStatusObserver;
use App\Events\AdvanceOrderExecuted;
use App\Events\AdvanceOrderCancelled;
use App\Events\AdvanceOrderCreated;
use App\Events\AdvanceOrderDatesUpdated;
use App\Events\AdvanceOrderProductChanged;
use App\Events\AdvanceOrderProductsBulkLoaded;
use App\Listeners\CreateWarehouseTransactionForAdvanceOrder;
use App\Listeners\CancelWarehouseTransactionForAdvanceOrder;
use App\Listeners\SyncAdvanceOrderPivotsListener;

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

        // Bind report column data provider
        // Using ReportGrouperColumnProvider for grouper-based reporting
        $this->app->bind(
            ReportColumnDataProviderInterface::class,
            ReportGrouperColumnProvider::class
        );

        // Bind nutritional information repository
        $this->app->bind(
            NutritionalInformationRepositoryInterface::class,
            NutritionalInformationRepository::class
        );

        // Bind import service
        $this->app->bind(
            ImportServiceInterface::class,
            ImportService::class
        );

        // Bind label generator
        $this->app->bind(
            LabelGeneratorInterface::class,
            NutritionalLabelGenerator::class
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
        // Register Observers
        Product::observe(ProductObserver::class);
        AdvanceOrder::observe(AdvanceOrderObserver::class);
        AdvanceOrderProduct::observe(AdvanceOrderProductObserver::class);

        // Register Observers for production status updates
        AdvanceOrder::observe(AdvanceOrderProductionStatusObserver::class);
        AdvanceOrderProduct::observe(AdvanceOrderProductProductionStatusObserver::class);
        OrderLine::observe(OrderLineProductionStatusObserver::class);

        // Register event listeners for warehouse transactions
        Event::listen(
            AdvanceOrderExecuted::class,
            CreateWarehouseTransactionForAdvanceOrder::class,
        );

        Event::listen(
            AdvanceOrderCancelled::class,
            CancelWarehouseTransactionForAdvanceOrder::class,
        );

        // Register event listeners for pivot synchronization
        // CRITICAL: Pivots are synced ONLY at creation and NEVER change afterward
        Event::listen(
            AdvanceOrderCreated::class,
            [SyncAdvanceOrderPivotsListener::class, 'handleAdvanceOrderCreated']
        );

        // COMMENTED OUT: Pivots are IMMUTABLE after creation
        // Changing dates should NOT re-sync pivots
        // Event::listen(
        //     AdvanceOrderDatesUpdated::class,
        //     [SyncAdvanceOrderPivotsListener::class, 'handleAdvanceOrderDatesUpdated']
        // );

        // COMMENTED OUT: Bulk loading products should NOT re-sync pivots
        // Event::listen(
        //     AdvanceOrderProductsBulkLoaded::class,
        //     [SyncAdvanceOrderPivotsListener::class, 'handleAdvanceOrderProductsBulkLoaded']
        // );

        // Register listener for manual product addition (via Filament RelationManager)
        Event::listen(
            AdvanceOrderProductChanged::class,
            [SyncAdvanceOrderPivotsListener::class, 'handleAdvanceOrderProductChanged']
        );
    }
}
