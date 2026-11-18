<?php

namespace App\Enums;

enum LiveSessionType: string
{
    case Class_ = 'class';
    case Webinar = 'webinar';
    case Workshop = 'workshop';
    case Mentorship = 'mentorship_1on1';

    public function label(): string
    {
        return match ($this) {
            self::Class_ => 'Live class',
            self::Webinar => 'Webinar',
            self::Workshop => 'Workshop',
            self::Mentorship => '1:1 mentorship',
        };
    }
}
