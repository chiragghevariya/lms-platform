<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        // Video library: one row per asset at the provider (Bunny Stream / demo).
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 16);
            $table->string('provider_video_id')->nullable();
            $table->string('title');
            $table->string('status', 16);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_video_id']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('slug', 180)->unique();
            $table->string('subtitle', 255)->nullable();
            $table->longText('description')->nullable();   // sanitized HTML
            $table->string('level', 20)->default('all_levels');
            $table->string('language', 10)->default('en');
            $table->string('thumbnail_path')->nullable();
            $table->foreignId('promo_video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->unsignedTinyInteger('required_tier')->nullable(); // null = free for members
            $table->string('status', 16)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedInteger('lessons_count')->default(0);
            $table->unsignedBigInteger('price_amount')->nullable(); // optional one-time purchase
            $table->char('currency', 3)->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('enrollments_count')->default(0);
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['status', 'required_tier']);
            $table->index(['status', 'is_featured']);
        });

        Schema::create('course_instructors', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['course_id', 'user_id']);
        });

        Schema::create('course_tag', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'tag_id']);
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('course_sections')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('type', 16);
            $table->longText('content')->nullable();          // sanitized HTML
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attachment_path')->nullable();     // pdf lessons
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_preview')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedSmallInteger('drip_days')->default(0);
            $table->timestamps();

            $table->index(['section_id', 'sort']);
        });

        Schema::create('lesson_resources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('course_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
        });

        // Learning state
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16); // tier | purchase | staff | preview
            $table->timestamp('started_at');  // drip clock starts here
            $table->foreignId('last_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'course_id']);
        });

        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'lesson_id']);
        });

        Schema::create('course_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('purchased_at');
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('plan_price_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('course_id'));
        foreach (['course_purchases', 'lesson_notes', 'lesson_progress', 'enrollments', 'course_reviews', 'lesson_resources', 'lessons', 'course_sections', 'course_tag', 'course_instructors', 'courses', 'videos', 'tags', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
