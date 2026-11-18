<?php

namespace App\Video;

/** What the browser needs to upload a file straight to the video provider. */
final class UploadTicket
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $protocol,  // 'tus' (Bunny) | 'http' (demo)
        public readonly string $endpoint,
        public readonly array $headers,
        public readonly int $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['protocol' => $this->protocol, 'endpoint' => $this->endpoint, 'headers' => $this->headers, 'expires_at' => $this->expiresAt];
    }
}
