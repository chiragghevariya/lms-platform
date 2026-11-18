<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 10: signed-in devices (account-sharing limit) and account deletion bookkeeping. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();             // stored in the browser session / maps to a token
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);                  // session | token
            $t->unsignedBigInteger('token_id')->nullable()->index();
            $t->string('name', 120)->nullable();    // "Chrome on macOS" / app device name
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('last_seen_at')->index();
            $t->timestamps();
            $t->index(['user_id', 'last_seen_at']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('deletion_requested_at')->nullable();
            $t->timestamp('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['deletion_requested_at', 'anonymized_at']));
    }
};
