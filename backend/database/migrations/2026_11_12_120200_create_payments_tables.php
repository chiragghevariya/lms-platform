<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A payment doubles as the invoice (invoice_number + pdf_path once paid).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 16);
            $table->string('gateway_payment_id')->nullable(); // Stripe invoice/payment intent, Razorpay payment id
            $table->string('gateway_invoice_id')->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal')->default(0);      // before tax, after discount
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_name', 16)->nullable();               // GST / VAT
            $table->unsignedBigInteger('amount');                     // total charged
            $table->string('description')->nullable();
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('billing_tax_id', 32)->nullable();
            $table->char('billing_country', 2)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_payment_id']);
            $table->index(['user_id', 'status', 'paid_at']);
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('series', 32)->primary(); // e.g. INV-2026-27
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['coupon_id', 'user_id']); // one use per user
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 16);
            $table->string('event_id');
            $table->string('type', 80);
            $table->longText('payload');
            $table->string('status', 16)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']); // idempotency
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('payments');
    }
};
