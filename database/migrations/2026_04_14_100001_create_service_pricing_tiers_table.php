<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('tier_label', 64); // e.g. Junior, Senior, Master
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['service_id', 'tier_label']);
            $table->index('tenant_id');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->string('pricing_tier_label', 64)->nullable()->after('specialization');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('pricing_tier_label');
        });
        Schema::dropIfExists('service_pricing_tiers');
    }
};
