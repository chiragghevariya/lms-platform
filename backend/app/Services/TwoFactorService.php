<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor auth (RFC 6238) + single-use recovery codes, and the short-lived
 * login "challenge" that sits between password check and session/token issue.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $engine) {}

    /** Starts (or restarts) enrolment; 2FA is not active until confirmed with a valid code. */
    public function beginEnrollment(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->engine->generateSecretKey(32),
            'two_factor_recovery_codes' => $this->newRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function confirm(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null || ! $this->verifyTotp($user, $code)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function otpauthUrl(User $user): string
    {
        return $this->engine->getQRCodeUrl((string) config('lms.brand.name'), $user->email, (string) $user->two_factor_secret);
    }

    public function qrSvg(User $user): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user));
    }

    /** Accepts a 6-digit TOTP code or an unused recovery code (which is then burned). */
    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verifyTotp($user, $code);
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $codes = collect($user->two_factor_recovery_codes ?? []);
            $match = $codes->first(fn (string $c) => hash_equals($c, trim($recoveryCode)));

            if ($match === null) {
                return false;
            }

            $user->forceFill(['two_factor_recovery_codes' => $codes->reject(fn ($c) => $c === $match)->values()->all()])->save();

            return true;
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    // ---- Login challenge ----------------------------------------------

    /** @param  array{remember: bool, device_name: string|null, channel: string}  $context */
    public function createChallenge(User $user, array $context): string
    {
        $token = Str::random(64);

        Cache::put($this->challengeKey($token), [
            'user_id' => $user->id, 'attempts' => 0, ...$context,
        ], now()->addMinutes((int) config('lms.security.two_factor_challenge_minutes')));

        return $token;
    }

    /** @return array{user_id: int, attempts: int, remember: bool, device_name: string|null, channel: string}|null */
    public function challenge(string $token): ?array
    {
        /** @var array{user_id: int, attempts: int, remember: bool, device_name: string|null, channel: string}|null */
        return Cache::get($this->challengeKey($token));
    }

    /** Counts a failed attempt; returns false once the challenge is exhausted (and deleted). */
    public function failChallenge(string $token): bool
    {
        $data = $this->challenge($token);
        if ($data === null) {
            return false;
        }

        $data['attempts']++;
        if ($data['attempts'] >= (int) config('lms.security.two_factor_max_attempts')) {
            $this->forgetChallenge($token);

            return false;
        }

        Cache::put($this->challengeKey($token), $data, now()->addMinutes((int) config('lms.security.two_factor_challenge_minutes')));

        return true;
    }

    public function forgetChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        return 'two-factor-challenge:'.hash('sha256', $token);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return $user->two_factor_secret !== null
            && preg_match('/^\d{6}$/', $code) === 1
            && $this->engine->verifyKey($user->two_factor_secret, $code, 1) !== false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        /** @var Collection<int, string> $codes */
        $codes = Collection::times(8, fn () => Str::lower(Str::random(5).'-'.Str::random(5)));

        return $codes->values()->all();
    }
}
