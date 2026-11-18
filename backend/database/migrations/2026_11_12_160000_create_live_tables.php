<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional lessons (e.g. live replays) never block course completion.
        Schema::table('lessons', function (Blueprint $t) {
            $t->boolean('is_optional')->default(false)->after('is_preview');
        });

        // A weekly series: occurrences are materialised as live_sessions so each has its own meeting, roster and recording.
        Schema::create('live_series', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('instructor_id')->constrained('users');
            $t->json('weekdays');                 // ISO 1 (Mon) … 7 (Sun)
            $t->string('local_time', 5);          // "19:30" in `timezone`
            $t->string('timezone', 64);
            $t->date('starts_on');
            $t->unsignedSmallInteger('occurrences');
            $t->timestamps();
        });

        Schema::create('live_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('series_id')->nullable()->constrained('live_series')->nullOnDelete();
            $t->foreignId('instructor_id')->constrained('users');
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('type', 24);                        // class | webinar | workshop | mentorship_1on1
            $t->unsignedTinyInteger('required_tier')->nullable(); // 1-3; null = any signed-in member
            $t->timestamp('starts_at')->index();
            $t->unsignedSmallInteger('duration');           // minutes
            $t->string('timezone', 64);                     // display + calendar timezone chosen by the instructor
            $t->unsignedInteger('capacity')->nullable();
            $t->string('provider', 16);                     // zoom | agora | 100ms | external_link
            $t->string('meeting_id')->nullable()->index();
            $t->text('meeting_password')->nullable();       // encrypted
            $t->text('join_url')->nullable();               // encrypted (embeds the passcode)
            $t->text('host_url')->nullable();               // encrypted (Zoom start_url; refreshed on demand)
            $t->text('recording_url')->nullable();
            $t->foreignId('recording_video_id')->nullable()->constrained('videos')->nullOnDelete();
            $t->foreignId('replay_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $t->string('status', 16)->default('scheduled')->index(); // scheduled | live | ended | cancelled
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('attendance_synced_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('live_session_registrations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('attended')->default(false);
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('attended_seconds')->default(0);
            $t->timestamp('reminded_24h_at')->nullable();
            $t->timestamp('reminded_15m_at')->nullable();
            $t->timestamps();
            $t->unique(['live_session_id', 'user_id']);
            $t->index(['user_id', 'live_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_session_registrations');
        Schema::dropIfExists('live_sessions');
        Schema::dropIfExists('live_series');
        Schema::table('lessons', fn (Blueprint $t) => $t->dropColumn('is_optional'));
    }
};
