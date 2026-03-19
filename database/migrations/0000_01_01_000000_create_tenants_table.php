<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique()->nullable();
            $table->string('plan')->default('basic'); // basic, pro, enterprise
            $table->string('subscription_status')->default('active'); // active, suspended, cancelled
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();
        });
    }
};
