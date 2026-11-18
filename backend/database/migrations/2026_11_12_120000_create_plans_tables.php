<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('tier_level')->index(); // 1 Starter, 2 Pro, 3 Elite
            $table->text('description')->nullable();
            $table->json('features')->nullable(); // [{key, label, value: bool|string}]
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('interval', 16); // month | year | lifetime
            $table->unsignedBigInteger('amount'); // minor units
            $table->char('currency', 3);
            $table->string('stripe_price_id')->nullable()->unique();
            $table->string('razorpay_plan_id')->nullable()->unique();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_id', 'interval', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
