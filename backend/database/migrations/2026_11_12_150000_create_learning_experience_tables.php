<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->unsignedInteger('watched_seconds')->default(0)->after('position_seconds');
            $table->timestamp('last_heartbeat_at')->nullable()->after('watched_seconds');
        });

        // Single source of truth for course progress (enrollments keep the drip clock only).
        Schema::create('course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('percent')->default(0);
            $table->unsignedSmallInteger('completed_lessons')->default(0);
            $table->unsignedSmallInteger('total_lessons')->default(0);
            $table->foreignId('last_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index(['user_id', 'last_activity_at']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_lesson_id');
            $table->dropColumn('completed_at');
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole course
            $table->unsignedInteger('position_seconds')->nullable();
            $table->string('label', 160)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'lesson_id']);
        });

        // ---- Quizzes ---------------------------------------------------
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('pass_mark')->default(70);          // percent
            $table->unsignedInteger('time_limit_seconds')->nullable();      // null = untimed
            $table->unsignedSmallInteger('attempts_allowed')->nullable();   // null = unlimited
            $table->boolean('shuffle')->default(false);
            $table->boolean('show_answers')->default(true);                 // reveal answers/explanations after submit
            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->text('prompt');
            $table->unsignedSmallInteger('points')->default(1);
            $table->text('explanation')->nullable();
            $table->json('accepted_answers')->nullable(); // short_text
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('question_id')->constrained('quiz_questions')->cascadeOnDelete();
            $table->string('text', 500);
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->json('question_order');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('max_score')->default(0);
            $table->unsignedTinyInteger('percent')->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamps();

            $table->index(['quiz_id', 'user_id']);
        });

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('quiz_questions')->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->string('text_answer', 1000)->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('points_awarded')->default(0);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        // ---- Assignments -----------------------------------------------
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('instructions')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable(); // days after the learner started the course
            $table->unsignedSmallInteger('max_score')->default(100);
            $table->unsignedSmallInteger('pass_score')->nullable();
            $table->json('file_types')->nullable();   // ["pdf","zip",...]; null/[] = no files
            $table->unsignedSmallInteger('max_file_mb')->default(20);
            $table->boolean('allow_text')->default(true);
            $table->timestamps();
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->json('files')->nullable();        // [{path, name, size, mime}]
            $table->string('status', 16);
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamp('submitted_at');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['assignment_id', 'user_id']);
            $table->index(['status', 'submitted_at']);
        });

        // ---- Certificates ---------------------------------------------
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('verification_code', 16)->unique();
            $table->string('holder_name');        // snapshot at issue time
            $table->string('course_title');
            $table->string('instructor_name');
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        // ---- Gamification ----------------------------------------------
        Schema::create('points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('points');
            $table->string('reason', 40);
            $table->string('source_key', 80); // e.g. "lesson:12" — unique per user ⇒ never double-awarded
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'source_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255);
            $table->string('icon', 40);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');

            $table->unique(['user_id', 'badge_id']);
        });

        // One row per user per active day (in the user's timezone) → streaks.
        Schema::create('learning_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('seconds')->default(0);

            $table->unique(['user_id', 'day']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->json('skills')->nullable()->after('expertise');
            $table->text('learning_goals')->nullable()->after('skills');
            $table->boolean('is_public')->default(false)->after('learning_goals');
            $table->string('public_handle', 40)->nullable()->unique()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', fn (Blueprint $t) => $t->dropColumn(['skills', 'learning_goals', 'is_public', 'public_handle']));
        foreach (['learning_days', 'user_badges', 'badges', 'points_ledger', 'certificates', 'assignment_submissions', 'assignments', 'quiz_answers', 'quiz_attempts', 'quiz_options', 'quiz_questions', 'quizzes', 'bookmarks', 'course_progress'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('enrollments', function (Blueprint $t) {
            $t->foreignId('last_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
        });
        Schema::table('lesson_progress', fn (Blueprint $t) => $t->dropColumn(['watched_seconds', 'last_heartbeat_at']));
    }
};
