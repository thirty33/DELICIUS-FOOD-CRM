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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->boolean('active')->default(true);
            $table->string('title');
            $table->text('description');
            $table->timestamps();

            $table->unique(['start_date', 'end_date']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
