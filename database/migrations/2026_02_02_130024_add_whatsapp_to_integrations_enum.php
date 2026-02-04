<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE integrations MODIFY COLUMN `name` VARCHAR(50) NOT NULL");
        DB::statement("ALTER TABLE integrations MODIFY COLUMN `type` VARCHAR(50) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE integrations MODIFY COLUMN `name` ENUM('defontana', 'facturacion_cl') NOT NULL");
        DB::statement("ALTER TABLE integrations MODIFY COLUMN `type` ENUM('billing', 'payment_gateway') NOT NULL");
    }
};