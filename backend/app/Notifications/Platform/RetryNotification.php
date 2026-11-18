<?php

namespace App\Notifications\Platform;

use App\Notify\PlatformNotification;

/** Re-sends a failed log entry on its channel, from the stored template variables. */
class RetryNotification extends PlatformNotification
{
    /** @param array<string, mixed> $stored */
    public function __construct(public string $eventKey, public array $stored, public string $channel) {}

    public function event(): string
    {
        return $this->eventKey;
    }

    /** @return list<string> */
    public function onlyChannels(): array
    {
        return [$this->channel];
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return $this->stored;
    }
}
