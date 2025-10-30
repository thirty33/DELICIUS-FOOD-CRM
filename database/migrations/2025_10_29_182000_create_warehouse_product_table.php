<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warehouse_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('stock')->default(0);
            $table->string('unit_of_measure')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
        });

        // Get default warehouse
        $defaultWarehouse = DB::table('warehouses')->where('is_default', true)->first();

        if ($defaultWarehouse) {
            // Get all existing products
            $products = DB::table('products')->get();

            // Create warehouse_product record for each product
            $warehouseProducts = [];
            foreach ($products as $product) {
                $warehouseProducts[] = [
                    'warehouse_id' => $defaultWarehouse->id,
                    'product_id' => $product->id,
                    'stock' => 0,
                    'unit_of_measure' => 'UND',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($warehouseProducts)) {
                DB::table('warehouse_product')->insert($warehouseProducts);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_product');
    }
};
