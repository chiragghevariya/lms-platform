<?php

namespace App\Live;

use Symfony\Component\HttpFoundation\Response;

/** A verified provider webhook, normalised. `reply` short-circuits (e.g. Zoom URL validation). */
final class LiveWebhook
{
    /**
     * @param  'started'|'ended'|'recording_completed'|'participant_joined'|'participant_left'|'ignored'  $event
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $meetingId,
        public readonly string $eventId,
        public readonly array $data = [],
        public readonly ?Response $reply = null,
    ) {}
}
