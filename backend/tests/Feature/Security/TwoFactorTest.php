<?php

use App\Models\User;
use App\Services\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

function totp(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp($user->fresh()->two_factor_secret);
}

function enableTwoFactor(User $user): User
{
    app(TwoFactorService::class)->beginEnrollment($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

it('lets an admin enrol and confirm 2FA', function () {
    $admin = User::factory()->admin()->create();

    $res = $this->actingAs($admin)->postJson(api('me/two-factor'), ['password' => 'password'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['qr_svg', 'otpauth_url', 'secret', 'recovery_codes']]);

    expect($res->json('data.recovery_codes'))->toHaveCount(8)
        ->and($res->json('data.qr_svg'))->toContain('<svg');

    $this->actingAs($admin)->getJson(api('me/two-factor'))->assertJsonPath('data.pending_confirmation', true);
    $this->actingAs($admin)->postJson(api('me/two-factor/confirm'), ['code' => '000000'])->assertJsonValidationErrors('code');
    $this->actingAs($admin)->postJson(api('me/two-factor/confirm'), ['code' => totp($admin)])->assertOk();

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('is not offered to students', function () {
    $this->actingAs(User::factory()->student()->create())
        ->postJson(api('me/two-factor'), ['password' => 'password'])->assertForbidden();
});

it('requires the current password to enrol or disable', function () {
    $admin = enableTwoFactor(User::factory()->admin()->create());

    $this->actingAs($admin)->deleteJson(api('me/two-factor'), ['password' => 'wrong'])->assertJsonValidationErrors('password');
    $this->actingAs($admin)->deleteJson(api('me/two-factor'), ['password' => 'password'])->assertNoContent();

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('challenges 2FA users at login and completes with a TOTP code', function () {
    $admin = enableTwoFactor(User::factory()->admin()->create(['email' => 'boss@example.com']));

    $challenge = $this->postJson(api('auth/login'), ['email' => 'boss@example.com', 'password' => 'password', 'device_name' => 'ipad'])
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonPath('data.token', null)
        ->json('data.challenge_token');

    $this->assertGuest('web');

    $res = $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $challenge, 'code' => totp($admin)])
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', false)
        ->assertJsonPath('data.user.email', 'boss@example.com');

    expect($res->json('data.token'))->toBeString();

    // Challenge is single-use.
    $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $challenge, 'code' => totp($admin)])
        ->assertStatus(410);
});

it('accepts a recovery code exactly once', function () {
    $admin = enableTwoFactor(User::factory()->admin()->create(['email' => 'boss@example.com']));
    $code = $admin->two_factor_recovery_codes[0];

    $login = fn () => $this->postJson(api('auth/login'), ['email' => 'boss@example.com', 'password' => 'password'])->json('data.challenge_token');

    $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $login(), 'recovery_code' => $code])->assertOk();
    $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $login(), 'recovery_code' => $code])
        ->assertUnprocessable()->assertJsonPath('code', 'two_factor_invalid');

    expect($admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('expires the challenge after 5 wrong codes', function () {
    enableTwoFactor(User::factory()->admin()->create(['email' => 'boss@example.com']));
    $challenge = $this->postJson(api('auth/login'), ['email' => 'boss@example.com', 'password' => 'password'])->json('data.challenge_token');

    foreach (range(1, 4) as $_) {
        $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $challenge, 'code' => '000000'])
            ->assertJsonPath('code', 'two_factor_invalid');
    }

    $this->postJson(api('auth/two-factor-challenge'), ['challenge_token' => $challenge, 'code' => '000000'])
        ->assertStatus(410)->assertJsonPath('code', 'two_factor_expired');
});

it('regenerates recovery codes', function () {
    $admin = enableTwoFactor(User::factory()->admin()->create());
    $old = $admin->two_factor_recovery_codes;

    $new = $this->actingAs($admin)->postJson(api('me/two-factor/recovery-codes'), ['password' => 'password'])
        ->assertOk()->json('data.recovery_codes');

    expect($new)->toHaveCount(8)->not->toBe($old);
});
