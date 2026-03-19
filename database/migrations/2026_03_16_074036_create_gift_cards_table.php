<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->decimal('initial_balance', 10, 2);
            $table->decimal('remaining_balance', 10, 2);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('active'); // active, exhausted, expired, void
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
