<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 40)->unique();
            $table->string('type', 16); // percent | fixed
            $table->unsignedBigInteger('value'); // percent (1-100) or minor units
            $table->char('currency', 3)->nullable(); // required for fixed
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('stripe_coupon_id')->nullable();
            $table->string('razorpay_offer_id')->nullable(); // Razorpay offers are created in its dashboard
            $table->timestamps();
            $table->softDeletes();
        });

        // Plan scope: no rows = valid for every plan.
        Schema::create('coupon_plan', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_plan');
        Schema::dropIfExists('coupons');
    }
};
