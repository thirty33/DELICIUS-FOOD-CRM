<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            
            $table->date('publication_date');

            $table->unsignedBigInteger('role_id')->nullable();
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')->onDelete('cascade');

            $table->unsignedBigInteger('permissions_id')->nullable();
            $table->foreign('permissions_id')
                ->references('id')
                ->on('permissions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {

            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
            $table->dropForeign(['permissions_id']);
            $table->dropColumn('permissions_id');

            $table->dropColumn('publication_date');

        });
    }
};
