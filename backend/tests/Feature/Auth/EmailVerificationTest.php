<?php

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function verificationPath(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), [
        'id' => $user->uuid, 'hash' => $hash ?? sha1($user->email),
    ], absolute: false);
}

it('resends the verification email with an SPA link', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson(api('auth/email/verification-notification'))->assertAccepted();

    Notification::assertSentTo($user, QueuedVerifyEmail::class, function ($n) use ($user) {
        return str_starts_with($n->toMail($user)->actionUrl, config('lms.frontend_url').'/verify-email?url=');
    });
});

it('does not resend when already verified', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(api('auth/email/verification-notification'))
        ->assertOk();
});

it('verifies the email via a signed link', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->getJson(verificationPath($user))
        ->assertOk()->assertJsonPath('data.email_verified', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered or foreign verification link', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $this->actingAs($user)->getJson(verificationPath($user, sha1('x')))->assertForbidden();
    $this->actingAs($user)->getJson(verificationPath($other))->assertForbidden();
    $this->actingAs($user)->getJson(verificationPath($user).'tampered')->assertForbidden();
});
