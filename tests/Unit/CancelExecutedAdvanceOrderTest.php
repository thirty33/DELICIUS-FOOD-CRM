<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\AdvanceOrder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Warehouse;
use App\Models\WarehouseTransaction;
use App\Models\WarehouseTransactionLine;
use App\Models\ProductionArea;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Enums\AdvanceOrderStatus;
use App\Enums\WarehouseTransactionStatus;
use App\Repositories\WarehouseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Test for cancelling EXECUTED AdvanceOrders
 *
 * REQUIREMENT:
 * When an AdvanceOrder with status EXECUTED is cancelled:
 * 1. The associated WarehouseTransaction should be updated to CANCELLED status
 * 2. The stock in warehouse_product should be reverted to stock_before (undo the execution)
 * 3. The AdvanceOrderCancelled event should be fired
 *
 * CURRENT BUG:
 * When cancelling an EXECUTED AdvanceOrder, the stock is NOT reverted and the
 * warehouse transaction status is NOT updated to CANCELLED.
 */
class CancelExecutedAdvanceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product1;
    private Product $product2;
    private User $user;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseRepository = new WarehouseRepository();

        // Create user
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'is_default' => true,
        ]);

        $company = Company::create([
            'name' => 'Test Company',
            'email' => 'test@company.com',
            'tax_id' => '12345678-9',
            'company_code' => 'TEST001',
            'fantasy_name' => 'Test Company S.A.',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'shipping_address' => 'Test Address 123',
            'fantasy_name' => 'Test Branch',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'nickname' => 'TEST.USER',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // Create warehouse
        $this->warehouse = Warehouse::create([
            'name' => 'Bodega Principal Test',
            'code' => 'MAIN_TEST',
            'address' => 'Test Address 123',
            'is_default' => true,
        ]);

        // Create category
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Category for testing',
        ]);

        // Create production area
        $productionArea = ProductionArea::create([
            'name' => 'Cocina Test',
            'description' => 'Test production area',
        ]);

        // Create products
        $this->product1 = Product::create([
            'name' => 'Product 1',
            'description' => 'Test product 1',
            'code' => 'PROD1',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);
        $this->product1->productionAreas()->attach($productionArea->id);

        $this->product2 = Product::create([
            'name' => 'Product 2',
            'description' => 'Test product 2',
            'code' => 'PROD2',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);
        $this->product2->productionAreas()->attach($productionArea->id);

        // Initialize stock in warehouse_product pivot table
        DB::table('warehouse_product')->insert([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product1->id,
            'stock' => 100,
            'unit_of_measure' => 'UND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('warehouse_product')->insert([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product2->id,
            'stock' => 50,
            'unit_of_measure' => 'UND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cancelling_executed_advance_order_reverts_stock_and_cancels_transaction(): void
    {
        // STEP 1: Create an EXECUTED AdvanceOrder with warehouse transaction
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-15 08:00:00',
            'initial_dispatch_date' => '2025-11-16',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::EXECUTED,
        ]);

        // Add products to advance order
        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product1->id,
            'ordered_quantity' => 30,
            'ordered_quantity_new' => 30,
            'quantity' => 30,
        ]);

        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product2->id,
            'ordered_quantity' => 20,
            'ordered_quantity_new' => 20,
            'quantity' => 20,
        ]);

        // STEP 2: Create and EXECUTE warehouse transaction (simulating production)
        $transaction = WarehouseTransaction::create([
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
            'transaction_code' => 'OP-TEST-001',
            'status' => WarehouseTransactionStatus::EXECUTED,
            'advance_order_id' => $advanceOrder->id,
            'reason' => 'Test transaction for OP execution',
            'executed_at' => now(),
            'executed_by' => $this->user->id,
        ]);

        // Create transaction lines and UPDATE warehouse stock (simulating execution)
        $stockBeforeProduct1 = 100;
        $stockAfterProduct1 = 130; // 100 + 30
        WarehouseTransactionLine::create([
            'warehouse_transaction_id' => $transaction->id,
            'product_id' => $this->product1->id,
            'difference' => 30,
            'stock_before' => $stockBeforeProduct1,
            'stock_after' => $stockAfterProduct1,
            'unit_of_measure' => 'UND',
        ]);
        // Update actual warehouse stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id,
            $stockAfterProduct1
        );

        $stockBeforeProduct2 = 50;
        $stockAfterProduct2 = 70; // 50 + 20
        WarehouseTransactionLine::create([
            'warehouse_transaction_id' => $transaction->id,
            'product_id' => $this->product2->id,
            'difference' => 20,
            'stock_before' => $stockBeforeProduct2,
            'stock_after' => $stockAfterProduct2,
            'unit_of_measure' => 'UND',
        ]);
        // Update actual warehouse stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product2->id,
            $this->warehouse->id,
            $stockAfterProduct2
        );

        // STEP 3: Verify initial state BEFORE cancellation
        $this->assertEquals(130, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id
        ), 'Product 1 stock should be 130 after execution');

        $this->assertEquals(70, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product2->id,
            $this->warehouse->id
        ), 'Product 2 stock should be 70 after execution');

        $transaction->refresh();
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $transaction->status,
            'Transaction should be EXECUTED before cancellation');

        // STEP 4: CANCEL the AdvanceOrder (replicating FIXED Filament action code)
        // This is what Filament now does after the fix (line 402-409)
        $previousStatus = $advanceOrder->status; // Capture BEFORE update

        $advanceOrder->update([
            'status' => AdvanceOrderStatus::CANCELLED,
        ]);

        // Fire the event ONLY if previous status was EXECUTED
        if ($previousStatus === AdvanceOrderStatus::EXECUTED) {
            event(new \App\Events\AdvanceOrderCancelled($advanceOrder));
        }

        // STEP 5: VALIDATE that stock is reverted
        $this->assertEquals(100, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id
        ), 'Product 1 stock should be reverted to 100 (original stock_before)');

        $this->assertEquals(50, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product2->id,
            $this->warehouse->id
        ), 'Product 2 stock should be reverted to 50 (original stock_before)');

        // STEP 6: VALIDATE that transaction is marked as CANCELLED
        $transaction->refresh();
        $this->assertEquals(WarehouseTransactionStatus::CANCELLED, $transaction->status,
            'Transaction should be updated to CANCELLED status');
        $this->assertNotNull($transaction->cancelled_at,
            'Transaction should have cancelled_at timestamp');
        $this->assertNotNull($transaction->cancellation_reason,
            'Transaction should have cancellation reason');
    }

    public function test_cancelling_pending_advance_order_does_not_revert_stock(): void
    {
        // STEP 1: Create a PENDING AdvanceOrder (not executed yet)
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-15 08:00:00',
            'initial_dispatch_date' => '2025-11-16',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product1->id,
            'ordered_quantity' => 30,
            'ordered_quantity_new' => 30,
            'quantity' => 30,
        ]);

        // STEP 2: Create warehouse transaction in PENDING status (not executed)
        $transaction = WarehouseTransaction::create([
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
            'transaction_code' => 'OP-TEST-002',
            'status' => WarehouseTransactionStatus::PENDING,
            'advance_order_id' => $advanceOrder->id,
            'reason' => 'Test pending transaction',
        ]);

        WarehouseTransactionLine::create([
            'warehouse_transaction_id' => $transaction->id,
            'product_id' => $this->product1->id,
            'difference' => 30,
            'stock_before' => 100,
            'stock_after' => 130,
            'unit_of_measure' => 'UND',
        ]);

        // Stock remains at 100 (transaction not executed)
        $initialStock = $this->warehouseRepository->getProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id
        );
        $this->assertEquals(100, $initialStock);

        // STEP 3: CANCEL the AdvanceOrder (replicating Filament action code)
        $previousStatus = $advanceOrder->status;
        $advanceOrder->update([
            'status' => AdvanceOrderStatus::CANCELLED,
        ]);

        // Fire event if previous status was EXECUTED (in this case it's PENDING, so no event)
        if ($previousStatus === AdvanceOrderStatus::EXECUTED) {
            event(new \App\Events\AdvanceOrderCancelled($advanceOrder));
        }

        // STEP 4: VALIDATE that stock remains unchanged (no revert needed)
        $this->assertEquals(100, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id
        ), 'Stock should remain at 100 (no revert needed for PENDING transaction)');

        // STEP 5: VALIDATE that transaction remains PENDING (not cancelled because event wasn't fired)
        // This is expected behavior - Filament only fires event for EXECUTED orders
        $transaction->refresh();
        $this->assertEquals(WarehouseTransactionStatus::PENDING, $transaction->status,
            'Transaction should remain PENDING when cancelling a PENDING advance order (no event fired)');
    }

    public function test_cancelling_advance_order_without_transaction_does_not_error(): void
    {
        // STEP 1: Create AdvanceOrder without warehouse transaction
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-15 08:00:00',
            'initial_dispatch_date' => '2025-11-16',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        // STEP 2: CANCEL the AdvanceOrder (should not error)
        $advanceOrder->update([
            'status' => AdvanceOrderStatus::CANCELLED,
        ]);

        // STEP 3: VALIDATE status changed
        $this->assertEquals(AdvanceOrderStatus::CANCELLED, $advanceOrder->status);
    }

    public function test_demonstrates_filament_bug_getOriginal_after_update(): void
    {
        // This test demonstrates the BUG in AdvanceOrderResource.php line 402-405
        // The code does:
        //   $record->update(['status' => CANCELLED]);
        //   if ($record->getOriginal('status') === EXECUTED) { event(...) }
        //
        // BUT: After update(), getOriginal() returns the NEW value (CANCELLED), not old value (EXECUTED)

        // STEP 1: Create EXECUTED AdvanceOrder with transaction
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-15 08:00:00',
            'initial_dispatch_date' => '2025-11-16',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::EXECUTED,
        ]);

        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product1->id,
            'ordered_quantity' => 10,
            'ordered_quantity_new' => 10,
            'quantity' => 10,
        ]);

        $transaction = WarehouseTransaction::create([
            'warehouse_id' => $this->warehouse->id,
            'user_id' => $this->user->id,
            'transaction_code' => 'BUG-TEST-001',
            'status' => WarehouseTransactionStatus::EXECUTED,
            'advance_order_id' => $advanceOrder->id,
            'reason' => 'Test transaction',
            'executed_at' => now(),
            'executed_by' => $this->user->id,
        ]);

        WarehouseTransactionLine::create([
            'warehouse_transaction_id' => $transaction->id,
            'product_id' => $this->product1->id,
            'difference' => 10,
            'stock_before' => 100,
            'stock_after' => 110,
            'unit_of_measure' => 'UND',
        ]);

        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id,
            110
        );

        // STEP 2: Replicate BUGGY Filament code EXACTLY
        // This is what's currently in AdvanceOrderResource.php
        $advanceOrder->update(['status' => AdvanceOrderStatus::CANCELLED]);

        // THIS IS THE BUG: getOriginal() after update() returns CANCELLED, not EXECUTED
        $originalStatus = $advanceOrder->getOriginal('status');

        if ($originalStatus === AdvanceOrderStatus::EXECUTED->value) {
            event(new \App\Events\AdvanceOrderCancelled($advanceOrder));
        }

        // STEP 3: Demonstrate the bug - event was NOT fired
        $transaction->refresh();

        // Stock was NOT reverted (still 110 instead of 100)
        $this->assertEquals(110, $this->warehouseRepository->getProductStockInWarehouse(
            $this->product1->id,
            $this->warehouse->id
        ), 'BUG DEMONSTRATION: Stock was NOT reverted because event was not fired');

        // Transaction is still EXECUTED (not CANCELLED)
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $transaction->status,
            'BUG DEMONSTRATION: Transaction was NOT cancelled because event was not fired');
    }
}
