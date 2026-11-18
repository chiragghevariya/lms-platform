<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->student()->create(['email' => 'kim@example.com']);
});

it('logs in with a token for mobile clients', function () {
    $res = $this->postJson(api('auth/login'), [
        'email' => 'KIM@example.com', 'password' => 'password', 'device_name' => 'pixel',
    ])->assertOk()->assertJsonPath('data.user.id', $this->user->uuid);

    expect($res->json('data.token'))->toBeString();
    expect($this->user->fresh()->last_login_at)->not->toBeNull();

    $this->withToken($res->json('data.token'))->getJson(api('me'))
        ->assertOk()->assertJsonPath('data.email', 'kim@example.com');
});

it('logs in with a session for the SPA', function () {
    $this->withHeader('Origin', 'http://localhost:5180')
        ->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.token', null);

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('rejects bad credentials', function () {
    $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'wrong']);
    }

    $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();
});

it('revokes the current token on logout', function () {
    $token = $this->user->createToken('phone')->plainTextToken;

    $this->withToken($token)->postJson(api('auth/logout'))->assertNoContent();

    expect($this->user->tokens()->count())->toBe(0);
});

it('ends the web session on logout', function () {
    $this->actingAs($this->user, 'web')
        ->withHeader('Origin', 'http://localhost:5180')
        ->postJson(api('auth/logout'))
        ->assertNoContent();

    $this->assertGuest('web');
});

it('requires authentication for me', function () {
    $this->getJson(api('me'))->assertUnauthorized();
});

it('returns 401 JSON (never a redirect) for guests without an Accept header', function () {
    $this->get(api('me'))->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});
