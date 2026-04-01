<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('customer_id')->constrained('appointments')->nullOnDelete();
            $table->string('status')->default('pending')->after('comment');
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();

            $table->unique('appointment_id');
            $table->index(['salon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['salon_id', 'status']);
            $table->dropUnique(['appointment_id']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropColumn('status');
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};

