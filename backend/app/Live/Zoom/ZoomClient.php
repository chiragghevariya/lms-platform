<?php

namespace App\Live\Zoom;

use App\Live\LiveProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Zoom REST client using Server-to-Server OAuth (account_credentials grant; token cached until shortly before expiry). */
class ZoomClient
{
    public function configured(): bool
    {
        return filled(config('live.zoom.account_id')) && filled(config('live.zoom.client_id')) && filled(config('live.zoom.client_secret'));
    }

    public function api(): PendingRequest
    {
        return Http::baseUrl((string) config('live.zoom.api_base'))
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }

    public function token(): string
    {
        if (! $this->configured()) {
            throw new LiveProviderException('Zoom is not configured (ZOOM_ACCOUNT_ID / ZOOM_CLIENT_ID / ZOOM_CLIENT_SECRET).');
        }

        /** @var string */
        return Cache::remember('zoom:s2s-token:'.config('live.zoom.client_id'), now()->addMinutes(50), function () {
            $res = Http::asForm()
                ->withBasicAuth((string) config('live.zoom.client_id'), (string) config('live.zoom.client_secret'))
                ->post((string) config('live.zoom.oauth_url'), ['grant_type' => 'account_credentials', 'account_id' => config('live.zoom.account_id')]);

            $token = $res->json('access_token');
            if (! $res->successful() || ! is_string($token)) {
                throw new LiveProviderException('Zoom authentication failed: '.$res->json('reason', $res->status()));
            }

            return $token;
        });
    }
}
