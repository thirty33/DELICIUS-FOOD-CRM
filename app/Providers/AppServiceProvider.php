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
use App\Contracts\PlatedDishRepositoryInterface;
use App\Repositories\PlatedDishRepository;
use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Repositories\HorecaLabelDataRepository;
use App\Contracts\HorecaInformationRepositoryInterface;
use App\Repositories\HorecaInformationRepository;
use App\Contracts\ImportServiceInterface;
use App\Services\ImportService;
use App\Contracts\DeletionServiceInterface;
use App\Services\NutritionalInformationDeletionService;
use App\Jobs\DeleteNutritionalInformationJob;
use App\Contracts\Labels\LabelGeneratorInterface;
use App\Services\Labels\Generators\NutritionalLabelGenerator;
use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Services\Labels\NutritionalLabelDataPreparer;
use App\Contracts\ColumnDataProviderInterface;
use App\Services\Reports\BranchColumnDataProvider;
use App\Services\Reports\GrouperColumnDataProvider;
use App\Models\Product;
use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use App\Models\Order;
use App\Models\OrderLine;
use App\Observers\ProductObserver;
use App\Observers\OrderDeletionObserver;
use App\Observers\AdvanceOrderObserver;
use App\Observers\AdvanceOrderProductObserver;
use App\Observers\AdvanceOrderProductionStatusObserver;
use App\Observers\AdvanceOrderProductProductionStatusObserver;
use App\Observers\OrderLineProductionStatusObserver;
use App\Observers\OrderLineAdvanceOrderRecalculationObserver;
use App\Events\AdvanceOrderExecuted;
use App\Events\AdvanceOrderCancelled;
use App\Events\AdvanceOrderCreated;
use App\Events\AdvanceOrderDatesUpdated;
use App\Events\AdvanceOrderProductChanged;
use App\Events\AdvanceOrderProductsBulkLoaded;
use App\Events\OrderLineQuantityReducedBelowProduced;
use App\Listeners\CreateWarehouseTransactionForAdvanceOrder;
use App\Listeners\CancelWarehouseTransactionForAdvanceOrder;
use App\Listeners\SyncAdvanceOrderPivotsListener;
use App\Listeners\CreateSurplusWarehouseTransaction;
use Maatwebsite\Excel\QueuedWriter;
use App\Excel\ChunkAwareQueuedWriter;

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

        // Bind plated dish repository
        $this->app->bind(
            PlatedDishRepositoryInterface::class,
            PlatedDishRepository::class
        );

        // Bind HORECA label data repository
        $this->app->bind(
            HorecaLabelDataRepositoryInterface::class,
            HorecaLabelDataRepository::class
        );

        // Bind HORECA information repository
        $this->app->bind(
            HorecaInformationRepositoryInterface::class,
            HorecaInformationRepository::class
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

        // Bind nutritional label data preparer
        // Used by NutritionalLabelService and GenerateProductLabels command
        $this->app->bind(
            NutritionalLabelDataPreparerInterface::class,
            NutritionalLabelDataPreparer::class
        );

        // Bind column data provider for Consolidado Emplatado report
        // PHASE 1: BranchColumnDataProvider (branch-based columns) - COMMENTED OUT
        // $this->app->bind(
        //     ColumnDataProviderInterface::class,
        //     BranchColumnDataProvider::class
        // );

        // PHASE 2: GrouperColumnDataProvider (grouper-based columns)
        // Groupers allow N companies to be grouped into 1 column
        $this->app->bind(
            ColumnDataProviderInterface::class,
            GrouperColumnDataProvider::class
        );

        // Override Laravel Excel's QueuedWriter to use ChunkAwareQueuedWriter
        // This allows exports to know which chunk they're processing
        // and load only the relevant IDs from S3 instead of all IDs
        // @see App\Excel\ChunkAwareQueuedWriter
        $this->app->bind(
            QueuedWriter::class,
            ChunkAwareQueuedWriter::class
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
        OrderLine::observe(OrderLineAdvanceOrderRecalculationObserver::class);
        Order::observe(OrderDeletionObserver::class);

        // Register event listeners for warehouse transactions
        Event::listen(
            AdvanceOrderExecuted::class,
            CreateWarehouseTransactionForAdvanceOrder::class,
        );

        Event::listen(
            AdvanceOrderCancelled::class,
            CancelWarehouseTransactionForAdvanceOrder::class,
        );

        // Register event listener for surplus when order line quantity is reduced
        Event::listen(
            OrderLineQuantityReducedBelowProduced::class,
            CreateSurplusWarehouseTransaction::class,
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
