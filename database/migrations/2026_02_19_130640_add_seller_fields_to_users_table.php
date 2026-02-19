<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_seller')->default(false)->after('billing_code');
            $table->foreignId('seller_id')->nullable()->after('is_seller')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropColumn(['is_seller', 'seller_id']);
        });
    }
};
