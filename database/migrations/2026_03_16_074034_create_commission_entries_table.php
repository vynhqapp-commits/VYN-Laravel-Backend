<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('base_amount', 10, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('tip_amount', 10, 2)->default(0);
            $table->string('status')->default('pending'); // pending, paid, reversed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
