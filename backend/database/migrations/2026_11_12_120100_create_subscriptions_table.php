<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 16); // stripe | razorpay | manual
            $table->string('gateway_subscription_id')->nullable(); // Stripe sub / Razorpay sub / order id (lifetime)
            $table->string('gateway_customer_id')->nullable();
            $table->string('status', 16);
            $table->boolean('is_lifetime')->default(false);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable(); // null = no end (lifetime / open complimentary)
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('past_due_since')->nullable();
            $table->foreignId('scheduled_plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable();
            $table->timestamp('gateway_cancel_requested_at')->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gateway', 'gateway_subscription_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'past_due_since']);
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
