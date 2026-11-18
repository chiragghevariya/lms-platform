<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 9: site settings, CMS content, analytics snapshots, moderation columns. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('status_reason', 500)->nullable()->after('status');
            $t->timestamp('status_changed_at')->nullable()->after('status_reason');
        });

        Schema::table('course_reviews', function (Blueprint $t) {
            $t->timestamp('hidden_at')->nullable()->index();
            $t->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('hidden_reason', 500)->nullable();
        });

        // key = "group.name", e.g. "branding.name", "payments.stripe_secret". Secrets are encrypted.
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value')->nullable();
            $t->boolean('is_secret')->default(false);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // One row per day × metric × dimension (e.g. mrr/INR, members/tier:2). Written nightly.
        Schema::create('metric_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('day');
            $t->string('metric', 40);
            $t->string('dimension', 40)->default('');
            $t->bigInteger('value');
            $t->timestamps();
            $t->unique(['day', 'metric', 'dimension']);
            $t->index(['metric', 'day']);
        });

        // Homepage sections: fixed keys (hero, features, how_it_works, cta…), structured JSON content.
        Schema::create('cms_sections', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->json('content');
            $t->boolean('is_visible')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('question', 300);
            $t->text('answer');
            $t->string('category', 40)->default('general')->index(); // general | billing | courses | live | affiliates
            $t->boolean('is_published')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name', 120);
            $t->string('role', 160)->nullable(); // "Freelance designer, Pune"
            $t->text('quote');
            $t->unsignedTinyInteger('rating')->nullable();
            $t->string('avatar_url', 500)->nullable();
            $t->boolean('is_published')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('slug', 160)->unique();
            $t->string('title', 200);
            $t->string('excerpt', 500)->nullable();
            $t->longText('body');                 // purified HTML (TipTap)
            $t->string('cover_url', 500)->nullable();
            $t->string('status', 12)->default('draft')->index(); // draft | published
            $t->timestamp('published_at')->nullable()->index();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('seo_title', 200)->nullable();
            $t->string('seo_description', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('banners', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('message', 300);
            $t->string('link_text', 60)->nullable();
            $t->string('link_url', 500)->nullable();
            $t->string('variant', 12)->default('info');   // info | promo | warning
            $t->string('audience', 12)->default('all');   // all | guests | members | free
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Legal & static pages: terms, privacy, refund, cookies… (slug is the URL).
        Schema::create('pages', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->string('title', 200);
            $t->longText('body');
            $t->boolean('is_published')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pages', 'banners', 'posts', 'testimonials', 'faqs', 'cms_sections', 'metric_snapshots', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('course_reviews', function (Blueprint $t) {
            $t->dropConstrainedForeignId('hidden_by');
            $t->dropColumn(['hidden_at', 'hidden_reason']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['status_reason', 'status_changed_at']));
    }
};
