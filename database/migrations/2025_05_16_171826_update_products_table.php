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
        Schema::table('products', function (Blueprint $table) {
            $table->text('cloudfront_signed_url')->nullable()->after('id');
            $table->timestamp('signed_url_expiration')->nullable()->after('cloudfront_signed_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cloudfront_signed_url');
            $table->dropColumn('signed_url_expiration');
        });
    }
};