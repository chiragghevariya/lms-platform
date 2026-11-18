<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Laravel Cashier (Stripe) customer columns
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // Razorpay + invoice details (GST/VAT-ready)
            $table->string('razorpay_customer_id')->nullable()->index();
            $table->string('billing_name')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('tax_id', 32)->nullable(); // GSTIN / VAT number
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'razorpay_customer_id', 'billing_name', 'billing_address', 'tax_id']);
        });
    }
};
