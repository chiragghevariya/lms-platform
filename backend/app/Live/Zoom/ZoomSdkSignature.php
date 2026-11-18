<?php

namespace App\Live\Zoom;

/**
 * Meeting SDK (web) JWT, HS256 with the SDK secret. Claims per Zoom's Meeting SDK auth docs:
 * appKey, sdkKey, mn (meeting number), role (0 attendee / 1 host), iat, exp, tokenExp.
 * iat is backdated 30 s for clock skew; Zoom requires exp within 48 h of iat (we use 2 h).
 */
class ZoomSdkSignature
{
    public static function make(string $sdkKey, string $sdkSecret, string $meetingNumber, int $role = 0, ?int $now = null): string
    {
        $iat = ($now ?? time()) - 30;
        $exp = $iat + 2 * 3600;
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'appKey' => $sdkKey, 'sdkKey' => $sdkKey, 'mn' => $meetingNumber, 'role' => $role,
            'iat' => $iat, 'exp' => $exp, 'tokenExp' => $exp,
        ];
        $segments = [self::b64(json_encode($header, JSON_THROW_ON_ERROR)), self::b64(json_encode($payload, JSON_THROW_ON_ERROR))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $sdkSecret, true));

        return implode('.', $segments);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
