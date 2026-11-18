<?php

namespace App\Live;

use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Http\Request;

/** Adapter for live-meeting services (Zoom today; Agora/100ms/external links behind the same contract). */
interface LiveProvider
{
    /** Creates the remote meeting and fills meeting_id / join_url / host_url / meeting_password (not saved). */
    public function create(LiveSession $session): void;

    /** Pushes title/time/duration changes to the remote meeting. */
    public function update(LiveSession $session): void;

    public function delete(LiveSession $session): void;

    /** A fresh host link (Zoom start URLs expire after ~2 hours). */
    public function hostUrl(LiveSession $session): string;

    /**
     * What the browser needs to join. Embedded providers return SDK credentials; others a URL to open.
     *
     * @return array<string, mixed>
     */
    public function joinPayload(LiveSession $session, User $user, LiveRegistration $registration): array;

    /**
     * Who attended, from the provider's reports. Null when the report isn't available (yet).
     *
     * @return list<array{customer_key: string|null, email: string|null, join_time: string, leave_time: string|null, duration: int}>|null
     */
    public function participants(LiveSession $session): ?array;

    /** Verifies and normalises a webhook. Null = invalid signature. */
    public function parseWebhook(Request $request): ?LiveWebhook;
}
