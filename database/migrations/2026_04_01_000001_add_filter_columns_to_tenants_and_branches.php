<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'gender_preference')) {
                $table->string('gender_preference')->nullable()->after('preferred_locale');
            }
            if (!Schema::hasColumn('tenants', 'average_rating')) {
                $table->decimal('average_rating', 3, 2)->nullable()->after('gender_preference');
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'gender_preference')) {
                $table->string('gender_preference')->nullable()->after('working_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'gender_preference')) {
                $table->dropColumn('gender_preference');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'average_rating')) {
                $table->dropColumn('average_rating');
            }
            if (Schema::hasColumn('tenants', 'gender_preference')) {
                $table->dropColumn('gender_preference');
            }
        });
    }
};

