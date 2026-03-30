<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('plan')->nullable();

            $table->date('start_date');
            $table->date('renewal_date');
            $table->unsignedInteger('interval_months')->default(1);

            // How many service units (sessions/credits) are granted on each renewal.
            $table->unsignedInteger('service_credits_per_renewal')->default(0);

            // Remaining credits that can be consumed before the next renewal (or until exhausted).
            $table->unsignedInteger('remaining_services')->default(0);

            $table->string('status')->default('active'); // active|expired|exhausted

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_memberships');
    }
};

