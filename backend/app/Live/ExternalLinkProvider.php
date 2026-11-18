<?php

namespace App\Live;

use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Http\Request;

/** Google Meet / any URL the instructor pastes. No API: no embedded join, reports or recordings (attendance = who clicked Join). */
class ExternalLinkProvider implements LiveProvider
{
    public function create(LiveSession $session): void {}

    public function update(LiveSession $session): void {}

    public function delete(LiveSession $session): void {}

    public function hostUrl(LiveSession $session): string
    {
        return (string) ($session->host_url ?: $session->join_url);
    }

    public function joinPayload(LiveSession $session, User $user, LiveRegistration $registration): array
    {
        return ['mode' => 'external', 'url' => (string) $session->join_url];
    }

    public function participants(LiveSession $session): ?array
    {
        return null;
    }

    public function parseWebhook(Request $request): ?LiveWebhook
    {
        return null;
    }
}
