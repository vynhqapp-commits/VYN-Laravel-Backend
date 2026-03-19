<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan')->default('basic'); // basic, pro, enterprise
            $table->string('status')->default('active'); // active, suspended, trial, cancelled
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

