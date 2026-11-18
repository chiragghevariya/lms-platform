<?php

namespace App\Notify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Firebase Cloud Messaging HTTP v1, authenticated with a service-account JWT (no SDK needed). */
class FcmClient
{
    /** @return array{ok: bool, id: string|null, unregistered: bool, error: string|null, transient: bool} */
    public function send(string $token, PushMessage $msg): array
    {
        $res = Http::withToken($this->accessToken())->acceptJson()->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('notify.fcm.project_id').'/messages:send', ['message' => [
                'token' => $token,
                'notification' => ['title' => $msg->title, 'body' => $msg->body],
                'data' => $msg->data,
                'android' => ['priority' => 'high'],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);
        if ($res->successful()) {
            return ['ok' => true, 'id' => (string) $res->json('name'), 'unregistered' => false, 'error' => null, 'transient' => false];
        }
        $code = (string) ($res->json('error.details.0.errorCode') ?? $res->json('error.status'));

        return [
            'ok' => false, 'id' => null,
            'unregistered' => $res->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true),
            'error' => $code.': '.$res->json('error.message'),
            'transient' => $res->serverError() || $res->status() === 429 || $code === 'UNAVAILABLE',
        ];
    }

    private function accessToken(): string
    {
        /** @var string */
        return Cache::remember('fcm:access-token', now()->addMinutes(50), function () {
            $path = (string) config('notify.fcm.credentials');
            $sa = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
                throw new RuntimeException('FCM service-account credentials are missing or invalid (FCM_CREDENTIALS).');
            }
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode([
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign($unsigned, $sig, (string) $sa['private_key'], 'sha256WithRSAEncryption');
            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$b64((string) $sig),
            ]);
            $token = $res->json('access_token');
            if (! is_string($token)) {
                throw new RuntimeException('FCM authentication failed: '.$res->body());
            }

            return $token;
        });
    }
}
