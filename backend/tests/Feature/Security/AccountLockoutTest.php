<?php

use App\Enums\LoginEvent;
use App\Models\LoginActivity;
use App\Models\User;

beforeEach(fn () => $this->user = User::factory()->student()->create(['email' => 'lock@example.com']));

$fail = fn () => test()->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'wrong-pass1']);

it('locks the account after 5 consecutive failures', function () use ($fail) {
    foreach (range(1, 4) as $_) {
        $fail()->assertUnprocessable();
    }

    $fail()->assertStatus(423)->assertJsonPath('code', 'account_locked')->assertJsonPath('retry_after', 900);

    expect($this->user->fresh()->isLocked())->toBeTrue()
        ->and(LoginActivity::where('event', LoginEvent::LockedOut)->count())->toBe(1);
});

it('rejects even the correct password while locked, then allows it after the lockout', function () {
    $this->user->forceFill(['locked_until' => now()->addMinutes(15)])->save();

    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'password'])
        ->assertStatus(423)->assertJsonPath('code', 'account_locked');

    $this->travel(16)->minutes();

    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'password', 'device_name' => 't'])
        ->assertOk();
});

it('resets the failure counter on a successful login', function () use ($fail) {
    $fail();
    $fail();
    expect($this->user->fresh()->failed_login_attempts)->toBe(2);

    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'password'])->assertOk();

    expect($this->user->fresh()->failed_login_attempts)->toBe(0);
});

it('clears a lock when the password is reset', function () {
    $this->user->forceFill(['locked_until' => now()->addMinutes(15)])->save();
    $token = Password::createToken($this->user);

    $this->postJson(api('auth/reset-password'), [
        'token' => $token, 'email' => 'lock@example.com', 'password' => 'fresh-pass1', 'password_confirmation' => 'fresh-pass1',
    ])->assertOk();

    expect($this->user->fresh()->isLocked())->toBeFalse();
});

it('blocks suspended accounts at login and on existing tokens', function () {
    $token = $this->user->createToken('phone')->plainTextToken;
    $this->user->forceFill(['status' => 'suspended'])->save();

    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');

    $this->withToken($token)->getJson(api('me'))->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('records login activity and lists only my own', function () {
    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'password', 'device_name' => 'pixel'])->assertOk();
    $this->postJson(api('auth/login'), ['email' => 'lock@example.com', 'password' => 'nope-nope1']);
    LoginActivity::create(['user_id' => User::factory()->create()->id, 'event' => LoginEvent::Success]);

    $this->actingAs($this->user)->getJson(api('me/login-activity'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.event', 'login_failed')
        ->assertJsonPath('data.1.event', 'login_success')
        ->assertJsonPath('data.1.channel', 'token');
});
