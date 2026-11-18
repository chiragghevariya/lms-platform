<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a reset link that points at the SPA', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson(api('auth/forgot-password'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, QueuedResetPassword::class, function ($n) use ($user) {
        $url = $n->toMail($user)->actionUrl;

        return str_starts_with($url, config('lms.frontend_url').'/reset-password?token=');
    });
});

it('does not reveal unknown emails', function () {
    $this->postJson(api('auth/forgot-password'), ['email' => 'nobody@example.com'])->assertOk();
});

it('resets the password and revokes tokens', function () {
    $user = User::factory()->create();
    $user->createToken('old');
    $token = Password::createToken($user);

    $this->postJson(api('auth/reset-password'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ])->assertOk();

    expect(Hash::check('newpass123', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson(api('auth/reset-password'), [
        'token' => 'bogus', 'email' => $user->email,
        'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ])->assertJsonValidationErrors('email');
});
