<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'source')) {
                $table->string('source')->default('dashboard')->after('status');
            }
        });

        Schema::table('cash_drawer_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_drawer_sessions', 'approval_required')) {
                $table->boolean('approval_required')->default(false)->after('discrepancy');
            }
            if (!Schema::hasColumn('cash_drawer_sessions', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('cash_drawer_sessions', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('closed_at');
            }
            if (!Schema::hasColumn('cash_drawer_sessions', 'approval_notes')) {
                $table->string('approval_notes')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'source')) {
                $table->dropColumn('source');
            }
        });

        Schema::table('cash_drawer_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('cash_drawer_sessions', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }
            if (Schema::hasColumn('cash_drawer_sessions', 'approval_required')) {
                $table->dropColumn('approval_required');
            }
            if (Schema::hasColumn('cash_drawer_sessions', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('cash_drawer_sessions', 'approval_notes')) {
                $table->dropColumn('approval_notes');
            }
        });
    }
};
