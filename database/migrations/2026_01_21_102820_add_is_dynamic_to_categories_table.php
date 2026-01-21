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
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_dynamic')->default(false)->after('is_active');
        });

        // Create the dynamic category for best-selling products
        DB::table('categories')->insert([
            'name' => 'Productos mas vendidos',
            'description' => 'Dynamic category for best-selling products',
            'is_active' => true,
            'is_dynamic' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the dynamic category
        DB::table('categories')->where('name', 'Productos mas vendidos')->delete();

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_dynamic');
        });
    }
};
