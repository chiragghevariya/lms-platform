<?php

namespace App\Enums;

enum LessonType: string
{
    case Video = 'video';
    case Text = 'text';
    case Pdf = 'pdf';
    case Quiz = 'quiz';
    case Assignment = 'assignment';
    case Live = 'live';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
