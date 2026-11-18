<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
