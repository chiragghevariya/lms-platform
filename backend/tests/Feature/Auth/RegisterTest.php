<?php

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;

$payload = fn (array $o = []) => [
    'name' => 'Asha Learner',
    'email' => 'Asha@Example.com',
    'password' => 'secret123',
    'password_confirmation' => 'secret123',
    ...$o,
];

it('registers a student and issues a mobile token', function () use ($payload) {
    Notification::fake();

    $res = $this->postJson(api('auth/register'), $payload(['device_name' => 'iPhone']))
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'asha@example.com')
        ->assertJsonPath('data.user.roles', ['student'])
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.token_type', 'Bearer');

    expect($res->json('data.token'))->toBeString()
        ->and($res->json('data.user.id'))->toBeUuid();

    $user = User::whereEmail('asha@example.com')->firstOrFail();
    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});

it('registers via SPA session without returning a token', function () use ($payload) {
    Notification::fake();

    $this->withHeader('Origin', 'http://localhost:5180')
        ->postJson(api('auth/register'), $payload())
        ->assertCreated()
        ->assertJsonPath('data.token', null);

    $this->assertAuthenticated('web');
});

it('validates registration input', function () use ($payload) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson(api('auth/register'), $payload(['email' => 'taken@example.com', 'password_confirmation' => 'nope', 'phone' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'phone']);
});

it('rejects weak passwords', function () use ($payload) {
    $this->postJson(api('auth/register'), $payload(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertJsonValidationErrors('password');
});

it('accepts legacy browser timezones like Asia/Calcutta and ignores unknown ones', function () use ($payload) {
    Notification::fake();

    $this->postJson(api('auth/register'), $payload(['timezone' => 'Asia/Calcutta', 'device_name' => 'x']))
        ->assertCreated()->assertJsonPath('data.user.timezone', 'Asia/Calcutta');

    $this->postJson(api('auth/register'), $payload(['email' => 'other@example.com', 'timezone' => 'Mars/Olympus', 'device_name' => 'x']))
        ->assertCreated()->assertJsonPath('data.user.timezone', 'UTC');
});
