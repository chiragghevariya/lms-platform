<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('code', 32)->unique();
            $t->string('status', 16)->default('pending')->index();   // pending | approved | rejected | suspended
            $t->string('tier', 24)->default('standard');               // rate tier from config('affiliates.tiers')
            $t->string('commission_type', 8)->nullable();              // override: percent | fixed (null = tier)
            $t->unsignedInteger('commission_rate')->nullable();        // percent (0-100) when type = percent
            $t->json('commission_fixed')->nullable();                  // {"INR": 20000, "USD": 300} minor units when type = fixed
            $t->string('website', 300)->nullable();
            $t->text('promotion_plan')->nullable();                    // how they'll promote (application)
            $t->string('payout_method', 16)->nullable();               // bank | upi | paypal
            $t->text('payout_details')->nullable();                    // encrypted JSON
            $t->text('review_notes')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamps();
        });

        Schema::create('referral_clicks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->string('visitor_id', 64)->nullable()->index();
            $t->char('ip_hash', 64)->index();
            $t->string('user_agent', 500)->nullable();
            $t->string('landing_url', 1000)->nullable();
            $t->string('referrer', 1000)->nullable();
            $t->json('utm')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['affiliate_id', 'created_at']);
        });

        Schema::create('referrals', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete(); // a user is attributed once
            $t->foreignId('click_id')->nullable()->constrained('referral_clicks')->nullOnDelete();
            $t->string('attribution', 12);                // first_touch | last_touch
            $t->string('status', 12)->default('pending')->index(); // pending | converted | flagged | rejected
            $t->json('flags')->nullable();                // fraud signals
            $t->char('signup_ip_hash', 64)->nullable();
            $t->char('signup_device_hash', 64)->nullable();
            $t->json('utm')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'created_at']);
        });

        Schema::create('affiliate_payouts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);                        // batch month "2026-11"
            $t->char('currency', 3);
            $t->bigInteger('amount');
            $t->unsignedInteger('commissions_count');
            $t->string('status', 12)->default('pending')->index(); // pending | paid | failed
            $t->string('method', 16)->nullable();
            $t->string('gateway', 24)->default('manual');  // manual | stripe_connect | razorpayx
            $t->string('reference', 120)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('level')->default(1);   // 1 | 2
            $t->string('kind', 12)->default('sale');          // sale | clawback
            $t->foreignId('parent_id')->nullable()->constrained('commissions')->nullOnDelete(); // clawback → original
            $t->char('currency', 3);
            $t->bigInteger('base_amount');                    // net of tax the commission is computed on
            $t->string('rate', 32);                           // snapshot: "20%" or "fixed"
            $t->bigInteger('amount');                         // may be negative for clawbacks
            $t->bigInteger('reversed_amount')->default(0);    // partial refunds of an unpaid commission
            $t->string('status', 12)->default('pending')->index(); // pending | approved | paid | reversed
            $t->boolean('needs_review')->default(false)->index();
            $t->timestamp('available_at')->index();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversal_reason', 64)->nullable();
            $t->foreignId('payout_id')->nullable()->constrained('affiliate_payouts')->nullOnDelete();
            $t->timestamps();
            $t->unique(['payment_id', 'affiliate_id', 'level', 'kind']);
        });

        // Student "invite a friend" rewards — separate from the affiliate programme.
        Schema::create('referral_rewards', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('friend_id')->unique()->constrained('users')->cascadeOnDelete(); // one reward per friend
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 8);                          // days | credit
            $t->unsignedSmallInteger('days')->nullable();
            $t->unsignedInteger('credit_amount')->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('status', 12)->default('invited')->index(); // invited | pending | banked | granted | reversed | rejected
            $t->json('flags')->nullable();
            $t->timestamp('available_at')->nullable();
            $t->timestamp('granted_at')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('invite_code', 16)->nullable()->unique()->after('affiliate_activated_at');
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->string('gateway_reference')->nullable()->after('gateway_invoice_id')->index(); // e.g. Stripe payment_intent of an invoice
            $t->string('payment_fingerprint', 128)->nullable()->after('gateway_reference')->index(); // card/UPI fingerprint (fraud checks)
            $t->unsignedBigInteger('refunded_amount')->default(0)->after('amount');
            $t->timestamp('refunded_at')->nullable()->after('paid_at');
            $t->timestamp('disputed_at')->nullable()->after('refunded_at');
        });

        Schema::table('login_activities', function (Blueprint $t) {
            $t->char('device_hash', 64)->nullable()->after('user_agent')->index();
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', fn (Blueprint $t) => $t->dropColumn('device_hash'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['gateway_reference', 'payment_fingerprint', 'refunded_amount', 'refunded_at', 'disputed_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('invite_code'));
        foreach (['referral_rewards', 'commissions', 'affiliate_payouts', 'referrals', 'referral_clicks', 'affiliates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
