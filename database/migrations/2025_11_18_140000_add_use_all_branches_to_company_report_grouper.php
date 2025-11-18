<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_report_grouper', function (Blueprint $table) {
            $table->boolean('use_all_branches')
                ->default(true)
                ->after('report_grouper_id')
                ->comment('If true, includes all branches of the company. If false, only specific branches.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_report_grouper', function (Blueprint $table) {
            $table->dropColumn('use_all_branches');
        });
    }
};
