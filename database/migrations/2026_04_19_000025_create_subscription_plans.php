<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->unsignedInteger('price_usd_cents_monthly')->nullable();
            $table->unsignedInteger('price_usd_cents_annual')->nullable();
            $table->unsignedInteger('daily_message_limit')->nullable();
            $table->unsignedInteger('memory_days')->nullable();
            $table->jsonb('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
