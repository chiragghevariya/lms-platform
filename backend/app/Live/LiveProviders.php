<?php

namespace App\Live;

use App\Enums\LiveProviderType;
use App\Live\Zoom\ZoomClient;
use App\Live\Zoom\ZoomProvider;

/** Resolves the adapter for a session's provider. Agora / 100ms are reserved values without an adapter yet. */
class LiveProviders
{
    public function __construct(private readonly ZoomClient $zoom) {}

    public function for(LiveProviderType $type): LiveProvider
    {
        return match ($type) {
            LiveProviderType::Zoom => new ZoomProvider($this->zoom),
            LiveProviderType::ExternalLink => new ExternalLinkProvider,
            default => throw new LiveProviderException("The {$type->value} provider isn't set up yet. Use Zoom or an external link."),
        };
    }

    /**
     * Providers the scheduling form may offer right now.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter([
            $this->zoom->configured() ? LiveProviderType::Zoom->value : null,
            LiveProviderType::ExternalLink->value,
        ]));
    }
}
