<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_window_hours')->default(24)->after('logo');
            $table->string('cancellation_policy_mode', 10)->default('soft')->after('cancellation_window_hours');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['cancellation_window_hours', 'cancellation_policy_mode']);
        });
    }
};
