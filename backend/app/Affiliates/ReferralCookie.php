<?php

namespace App\Affiliates;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * HMAC-signed (not encrypted) attribution cookies, so they work the same for SPA and API requests.
 * lms_ref    = affiliate click {c: code, k: click id, t: unix time}
 * lms_invite = student invite {c: invite code, t: unix time}
 */
class ReferralCookie
{
    public const AFFILIATE = 'lms_ref';

    public const INVITE = 'lms_invite';

    /** @param array<string, mixed> $payload */
    public static function make(string $name, array $payload): Cookie
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $value = $body.'.'.substr(hash_hmac('sha256', $name.'|'.$body, (string) config('app.key')), 0, 32);

        return cookie($name, $value, (int) config('affiliates.cookie.days') * 24 * 60, '/', config('session.domain'), config('session.secure'), true, false, 'lax');
    }

    /** @return array<string, mixed>|null */
    public static function read(string $name): ?array
    {
        $raw = (string) request()->cookie($name, '');
        if (! str_contains($raw, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $raw, 2);
        if (! hash_equals(substr(hash_hmac('sha256', $name.'|'.$body, (string) config('app.key')), 0, 32), $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($data) || (int) ($data['t'] ?? 0) < now()->subDays((int) config('affiliates.cookie.days'))->getTimestamp()) {
            return null;
        }

        return $data;
    }
}
