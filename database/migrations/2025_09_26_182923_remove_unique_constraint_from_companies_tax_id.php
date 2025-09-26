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
        Schema::table('companies', function (Blueprint $table) {
            // Remove unique constraint from tax_id field
            $table->dropUnique(['tax_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove duplicate tax_id records before restoring unique constraint
        DB::statement("
            DELETE c1 FROM companies c1
            INNER JOIN companies c2
            WHERE c1.id > c2.id
            AND c1.tax_id = c2.tax_id
            AND c1.tax_id IS NOT NULL
        ");

        Schema::table('companies', function (Blueprint $table) {
            // Restore unique constraint on tax_id field
            $table->unique('tax_id', 'companies_tax_id_unique');
        });
    }
};
