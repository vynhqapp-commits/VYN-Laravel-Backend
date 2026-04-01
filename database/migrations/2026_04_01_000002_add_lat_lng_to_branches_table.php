<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'lat')) {
                $table->float('lat')->nullable()->after('gender_preference');
            }
            if (!Schema::hasColumn('branches', 'lng')) {
                $table->float('lng')->nullable()->after('lat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'lng')) {
                $table->dropColumn('lng');
            }
            if (Schema::hasColumn('branches', 'lat')) {
                $table->dropColumn('lat');
            }
        });
    }
};

