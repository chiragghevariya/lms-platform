<?php

namespace App\Security;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Human check for public forms. Cloudflare Turnstile (free) when configured, otherwise a no-op.
 * The SPA sends the widget token as `captcha_token` (or the X-Captcha-Token header).
 */
class Captcha
{
    public function enabled(): bool
    {
        return config('security.captcha.driver') === 'turnstile' && filled(config('security.captcha.turnstile.secret'));
    }

    public function siteKey(): ?string
    {
        return $this->enabled() ? config('security.captcha.turnstile.site_key') : null;
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if (blank($token) || strlen((string) $token) > 2048) {
            return false;
        }

        try {
            $res = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => config('security.captcha.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $res->ok() && $res->json('success') === true;
        } catch (Throwable $e) {
            report($e);

            return false; // fail closed: a broken captcha shouldn't open the door to bots
        }
    }
}
