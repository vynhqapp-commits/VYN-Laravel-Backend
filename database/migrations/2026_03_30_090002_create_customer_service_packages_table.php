<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();

            $table->unsignedInteger('total_services')->default(0);
            $table->unsignedInteger('remaining_services')->default(0);

            // When this date passes, the package should no longer be consumed.
            $table->date('expires_at')->nullable();

            // active|expired|exhausted
            $table->string('status')->default('active');

            // Optional link to a membership/subscription.
            $table->foreignId('membership_id')->nullable()->constrained('customer_memberships')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_packages');
    }
};

