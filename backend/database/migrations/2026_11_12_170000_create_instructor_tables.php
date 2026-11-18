<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_applications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('headline', 160);
            $t->json('expertise');                 // ["Meta Ads", "Copywriting"]
            $t->text('bio');
            $t->text('motivation');               // what they want to teach and why
            $t->json('links')->nullable();         // portfolio / YouTube / LinkedIn
            $t->string('status', 16)->default('pending')->index(); // pending | approved | rejected
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->timestamps();
        });

        Schema::create('instructor_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('revenue_share_percent')->nullable(); // null = config default
            $t->string('payout_method', 16)->nullable();   // bank | upi | paypal
            $t->text('payout_details')->nullable();         // encrypted JSON
            $t->text('tax_id')->nullable();                 // encrypted (PAN / tax number)
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        // Watch time per course per day (UTC). `seconds` = all learners (analytics);
        // `pool_seconds` = the part that counts for the subscription pool (paying members, membership access, capped).
        Schema::create('course_watch_daily', function (Blueprint $t) {
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedInteger('seconds')->default(0);
            $t->unsignedInteger('pool_seconds')->default(0);
            $t->primary(['course_id', 'user_id', 'day']);
            $t->index(['day', 'course_id']);
        });

        Schema::create('course_questions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('body');
            $t->unsignedInteger('answers_count')->default(0);
            $t->timestamp('instructor_answered_at')->nullable()->index(); // null = waiting for the instructor
            $t->timestamps();
            $t->softDeletes();
            $t->index(['course_id', 'created_at']);
        });

        Schema::create('course_answers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('question_id')->constrained('course_questions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->boolean('is_instructor')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('course_announcements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();      // author
            $t->string('title', 200);
            $t->text('body');                              // sanitised HTML
            $t->unsignedInteger('recipients_count')->default(0);
            $t->timestamps();
        });

        Schema::create('payout_statements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('instructor_id')->constrained('users');
            $t->char('period', 7);                       // "2026-10"
            $t->char('currency', 3);
            $t->unsignedTinyInteger('pool_percent');      // snapshots for audit
            $t->unsignedTinyInteger('revenue_share_percent');
            $t->unsignedBigInteger('subscription_revenue'); // platform-wide net, minor units
            $t->unsignedBigInteger('pool_amount');
            $t->unsignedBigInteger('watch_seconds');       // this instructor
            $t->unsignedBigInteger('total_watch_seconds'); // all instructors
            $t->bigInteger('subscription_earnings');
            $t->bigInteger('sales_earnings');
            $t->bigInteger('total');
            $t->string('status', 16)->default('draft')->index(); // draft | finalized | paid
            $t->timestamp('finalized_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('paid_reference', 120)->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['instructor_id', 'period', 'currency']);
            $t->index(['period', 'status']);
        });

        Schema::create('payout_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('statement_id')->constrained('payout_statements')->cascadeOnDelete();
            $t->string('type', 24);                       // subscription_pool | course_sale
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 255);
            $t->unsignedBigInteger('watch_seconds')->default(0);
            $t->bigInteger('gross');                       // net-of-tax revenue the line is based on
            $t->bigInteger('amount');                      // instructor's earning
        });
    }

    public function down(): void
    {
        foreach (['payout_statement_lines', 'payout_statements', 'course_announcements', 'course_answers', 'course_questions',
            'course_watch_daily', 'instructor_profiles', 'instructor_applications'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
