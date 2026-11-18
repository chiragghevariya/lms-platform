<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Jobs\SendOtpMessage;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phone verification OTP: 6 digits, stored hashed, 5-minute expiry, max 5 guesses,
 * single use. Issuing a new code invalidates previous ones.
 */
class PhoneOtpService
{
    public const PURPOSE_VERIFY = 'verify_phone';

    public function issue(User $user): PhoneOtp
    {
        if ($user->phone === null || $user->phone === '') {
            throw new AuthFlowException('Add a phone number to your profile first.', 'phone_missing');
        }

        $length = (int) config('lms.otp.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $otp = DB::transaction(function () use ($user, $code) {
            PhoneOtp::where('user_id', $user->id)->where('purpose', self::PURPOSE_VERIFY)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return PhoneOtp::create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'purpose' => self::PURPOSE_VERIFY,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('lms.otp.ttl_minutes')),
            ]);
        });

        $brand = config('lms.brand.name');
        SendOtpMessage::dispatch($user->phone, "{$code} is your {$brand} verification code. It expires in ".config('lms.otp.ttl_minutes').' minutes.');

        return $otp;
    }

    public function verify(User $user, string $code): void
    {
        $otp = PhoneOtp::where('user_id', $user->id)->where('purpose', self::PURPOSE_VERIFY)
            ->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->phone !== $user->phone) {
            throw new AuthFlowException('This code has expired. Request a new one.', 'otp_expired');
        }

        if ($otp->attempts >= (int) config('lms.otp.max_attempts')) {
            throw new AuthFlowException('Too many incorrect attempts. Request a new code.', 'otp_exhausted');
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw new AuthFlowException('That code is incorrect.', 'otp_invalid');
        }

        DB::transaction(function () use ($otp, $user) {
            $otp->forceFill(['consumed_at' => now()])->save();
            $user->forceFill(['phone_verified_at' => now()])->save();
        });
    }
}
