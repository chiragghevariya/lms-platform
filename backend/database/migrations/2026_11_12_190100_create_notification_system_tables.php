<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('whatsapp_opt_in_at')->nullable()->after('phone_verified_at');   // consent record
            $t->timestamp('whatsapp_opt_out_at')->nullable()->after('whatsapp_opt_in_at');
        });

        // Only overrides are stored; defaults come from config('notify.events').
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event', 48);
            $t->string('channel', 16);      // mail | database | whatsapp | push | sms
            $t->boolean('enabled');
            $t->timestamps();
            $t->unique(['user_id', 'event', 'channel']);
        });

        // Admin-editable copy. Missing / inactive rows fall back to the built-in defaults.
        Schema::create('notification_templates', function (Blueprint $t) {
            $t->id();
            $t->string('event', 48);
            $t->string('channel', 16);
            $t->string('subject', 200)->nullable();      // mail subject / push & in-app title
            $t->text('body')->nullable();                // markdown (mail) or plain text
            $t->string('action_text', 60)->nullable();
            $t->string('whatsapp_template', 120)->nullable(); // pre-approved Meta template name
            $t->string('whatsapp_language', 12)->nullable();
            $t->json('whatsapp_params')->nullable();     // ordered variable names for {{1}}, {{2}}…
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['event', 'channel']);
        });

        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name', 160);
            $t->json('channels');                        // ["mail","database","whatsapp"]
            $t->json('segment');                         // filters
            $t->string('subject', 200)->nullable();
            $t->text('body')->nullable();                // markdown
            $t->string('action_text', 60)->nullable();
            $t->string('action_url', 500)->nullable();
            $t->string('whatsapp_template', 120)->nullable();
            $t->string('whatsapp_language', 12)->nullable();
            $t->json('whatsapp_params')->nullable();     // literal values or {{variables}}
            $t->string('status', 16)->default('draft')->index(); // draft | scheduled | sending | sent | cancelled
            $t->timestamp('scheduled_at')->nullable()->index();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('audience_count')->default(0);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->uuid('notification_id')->nullable()->index(); // Laravel notification id (same across channels)
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 48)->index();
            $t->string('channel', 16)->index();
            $t->string('status', 12)->index(); // skipped | sending | sent | delivered | read | failed
            $t->string('provider', 24)->nullable();
            $t->string('provider_message_id', 191)->nullable()->index();
            $t->string('recipient', 120)->nullable();  // masked
            $t->string('subject', 200)->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->json('vars')->nullable();              // template variables (for retries)
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'channel']);
            $t->index(['created_at', 'channel', 'status']);
        });

        Schema::create('push_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token', 255)->unique();
            $t->string('platform', 12);   // android | ios | web
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        // One-time sends (expiring reminders, inactivity nudges…).
        Schema::create('notification_markers', function (Blueprint $t) {
            $t->string('key', 191)->primary();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['notification_markers', 'push_tokens', 'notification_logs', 'campaigns', 'notification_templates', 'notification_preferences'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['whatsapp_opt_in_at', 'whatsapp_opt_out_at']));
    }
};
