<?php

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

function googleUser(string $email = 'gina@gmail.com', bool $verified = true, string $id = 'g-123'): GoogleUser
{
    return (new GoogleUser)
        ->setRaw(['email_verified' => $verified])
        ->map(['id' => $id, 'name' => 'Gina Google', 'email' => $email]);
}

function fakeGoogle(GoogleUser $user): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($user);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('userFromToken')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('redirects to Google', function () {
    config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'sec']);

    $this->get('/auth/google/redirect')->assertRedirectContains('accounts.google.com');
});

it('creates a verified student on first Google login and starts a session', function () {
    fakeGoogle(googleUser());

    $this->get('/auth/google/callback')->assertRedirect(config('lms.frontend_url').'/auth/callback');

    $user = User::whereEmail('gina@gmail.com')->firstOrFail();
    expect($user->google_id)->toBe('g-123')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasRole('student'))->toBeTrue();
    $this->assertAuthenticatedAs($user, 'web');
});

it('links Google to an existing account with the same email', function () {
    $existing = User::factory()->instructor()->unverified()->create(['email' => 'gina@gmail.com']);
    fakeGoogle(googleUser());

    $this->get('/auth/google/callback')->assertRedirect(config('lms.frontend_url').'/auth/callback');

    expect($existing->fresh()->google_id)->toBe('g-123')
        ->and($existing->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(User::count())->toBe(1);
});

it('refuses unverified Google emails', function () {
    fakeGoogle(googleUser(verified: false));
    $this->get('/auth/google/callback')->assertRedirect(config('lms.frontend_url').'/login?error=google_email_unverified');
    expect(User::count())->toBe(0);
});

it('refuses suspended accounts', function () {
    User::factory()->suspended()->create(['email' => 'gina@gmail.com']);
    fakeGoogle(googleUser());
    $this->get('/auth/google/callback')->assertRedirect(config('lms.frontend_url').'/login?error=account_suspended');
    $this->assertGuest('web');
});

it('sends 2FA users to the challenge page', function () {
    $admin = User::factory()->admin()->create(['email' => 'gina@gmail.com']);
    app(TwoFactorService::class)->beginEnrollment($admin);
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    fakeGoogle(googleUser());

    $this->get('/auth/google/callback')->assertRedirectContains(config('lms.frontend_url').'/two-factor?challenge=');
    $this->assertGuest('web');
});

it('handles Google errors gracefully', function () {
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andThrow(new RuntimeException('state mismatch'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect(config('lms.frontend_url').'/login?error=google_failed');
});

it('exchanges a Google token for an API token (mobile)', function () {
    fakeGoogle(googleUser());
    config(['services.google.mobile_client_ids' => ['android-client.apps.googleusercontent.com']]);
    Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => 'android-client.apps.googleusercontent.com', 'azp' => 'android-client.apps.googleusercontent.com'])]);

    $res = $this->postJson(api('auth/google/token'), ['access_token' => 'ya29.x', 'device_name' => 'Android'])
        ->assertOk()->assertJsonPath('data.user.email', 'gina@gmail.com');

    expect($res->json('data.token'))->toBeString();
});

it('rejects Google tokens issued to another app (no account takeover via token replay)', function () {
    fakeGoogle(googleUser());
    config(['services.google.client_id' => 'web-client', 'services.google.mobile_client_ids' => ['android-client']]);
    Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => 'some-other-app', 'azp' => 'some-other-app'])]);

    $this->postJson(api('auth/google/token'), ['access_token' => 'ya29.stolen', 'device_name' => 'Android'])
        ->assertJsonValidationErrors(['access_token' => 'not issued for this app']);
    expect(PersonalAccessToken::count())->toBe(0);
});
