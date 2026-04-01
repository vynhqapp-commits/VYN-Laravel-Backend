<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('url');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['salon_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_photos');
    }
};

