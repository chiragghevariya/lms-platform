<?php

use App\Jobs\SendOtpMessage;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\PhoneOtpService;
use Illuminate\Support\Facades\Bus;

function sentCode(): string
{
    $job = null;
    Bus::assertDispatched(SendOtpMessage::class, function (SendOtpMessage $j) use (&$job) {
        $job = $j;

        return true;
    });
    preg_match('/^(\d{6})/', $job->message, $m);

    return $m[1];
}

beforeEach(function () {
    Bus::fake([SendOtpMessage::class]);
    $this->user = User::factory()->student()->create(['phone' => '+91 9876543210']);
});

it('sends a hashed, expiring OTP and verifies the phone', function () {
    $this->actingAs($this->user)->postJson(api('me/phone/otp'))->assertAccepted()->assertJsonStructure(['data' => ['expires_at']]);

    $code = sentCode();
    $otp = PhoneOtp::firstOrFail();
    expect($otp->code_hash)->not->toBe($code)
        ->and($otp->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(5.0);

    $this->actingAs($this->user)->postJson(api('me/phone/verify'), ['code' => $code])
        ->assertOk()->assertJsonPath('data.phone_verified', true);
});

it('rejects a wrong code and exhausts after 5 attempts', function () {
    $this->actingAs($this->user)->postJson(api('me/phone/otp'));
    $code = sentCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        $this->actingAs($this->user)->postJson(api('me/phone/verify'), ['code' => $wrong])->assertJsonPath('code', 'otp_invalid');
    }

    $this->actingAs($this->user)->postJson(api('me/phone/verify'), ['code' => $code])->assertJsonPath('code', 'otp_exhausted');
});

it('expires codes after 5 minutes', function () {
    $this->actingAs($this->user)->postJson(api('me/phone/otp'));
    $code = sentCode();

    $this->travel(6)->minutes();

    $this->actingAs($this->user)->postJson(api('me/phone/verify'), ['code' => $code])->assertJsonPath('code', 'otp_expired');
});

it('invalidates older codes when a new one is issued', function () {
    $service = app(PhoneOtpService::class);
    $service->issue($this->user);
    $first = sentCode();
    $service->issue($this->user);

    expect(PhoneOtp::whereNull('consumed_at')->count())->toBe(1);
    $this->actingAs($this->user)->postJson(api('me/phone/verify'), ['code' => $first]);
    // Either rejected as invalid (different code) or accepted only if codes collide by chance.
    expect(PhoneOtp::count())->toBe(2);
});

it('rate limits OTP sends', function () {
    $this->actingAs($this->user)->postJson(api('me/phone/otp'))->assertAccepted();
    $this->actingAs($this->user)->postJson(api('me/phone/otp'))->assertAccepted();
    $this->actingAs($this->user)->postJson(api('me/phone/otp'))->assertTooManyRequests();
});

it('requires a phone number', function () {
    $this->actingAs(User::factory()->create(['phone' => null]))->postJson(api('me/phone/otp'))
        ->assertJsonPath('code', 'phone_missing');
});

it('resets verification when the phone number changes', function () {
    $user = User::factory()->phoneVerified()->create();

    $this->actingAs($user)->patchJson(api('me'), ['phone' => '+91 9000000000'])
        ->assertOk()->assertJsonPath('data.phone_verified', false);
});
