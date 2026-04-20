<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('status', 24);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->string('billing_provider', 24)->nullable();
            $table->string('billing_customer_id', 128)->nullable();
            $table->jsonb('billing_metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('billing_customer_id');
            $table->index('billing_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
    }
};
