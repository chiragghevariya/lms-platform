<?php

namespace App\Affiliates;

use App\Models\User;

/** Keyed hashes of IPs / device ids (never stored raw in referral data) and email normalisation. */
class Identity
{
    public static function hash(?string $value): ?string
    {
        return $value ? hash_hmac('sha256', strtolower(trim($value)), (string) config('app.key')) : null;
    }

    /** Device id the SPA sends in X-Device-Id (random, stored in localStorage). */
    public static function deviceFromRequest(): ?string
    {
        $id = (string) request()->header('X-Device-Id', '');

        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $id) ? self::hash($id) : null;
    }

    public static function ipFromRequest(): ?string
    {
        return self::hash(request()->ip());
    }

    /** "a.b+promo@gmail.com" → "ab@gmail.com" (catches the same inbox registered twice). */
    public static function canonicalEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
        $local = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /** @return list<string> keyed hashes of IPs the user logged in from recently */
    public static function userIpHashes(User $user, int $days): array
    {
        return $user->loginActivities()->where('created_at', '>=', now()->subDays($days))->whereNotNull('ip_address')
            ->distinct()->pluck('ip_address')->map(fn ($ip) => (string) self::hash((string) $ip))->values()->all();
    }

    /** @return list<string> */
    public static function userDeviceHashes(User $user, int $days): array
    {
        return $user->loginActivities()->where('created_at', '>=', now()->subDays($days))->whereNotNull('device_hash')
            ->distinct()->pluck('device_hash')->map(fn ($h) => (string) $h)->values()->all();
    }
}
