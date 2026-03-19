<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_branch_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            $table->boolean('is_closed')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'service_id', 'branch_id', 'date'], 'sbao_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_branch_availability_overrides');
    }
};

