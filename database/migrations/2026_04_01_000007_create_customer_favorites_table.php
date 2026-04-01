<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salon_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'salon_id'], 'customer_salon_unique_favorite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_favorites');
    }
};
