<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('entity_type'); // e.g. appointment, sale_refund
            $table->unsignedBigInteger('entity_id'); // ID in the entity table
            $table->string('requested_action'); // e.g. delete, refund

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, expired

            $table->dateTime('expires_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['expires_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};

