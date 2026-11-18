<?php

namespace App\Enums;

enum LiveProviderType: string
{
    case Zoom = 'zoom';
    case Agora = 'agora';
    case HundredMs = '100ms';
    case ExternalLink = 'external_link';
}
