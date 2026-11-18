<?php

namespace App\Courses;

use Stevebauman\Purify\Facades\Purify;

/** Server-side sanitising of instructor-authored HTML (the SPA also sanitises before rendering). */
final class RichText
{
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = (string) Purify::config('rich_text')->clean($html);

        return trim(strip_tags($clean)) === '' ? null : $clean;
    }
}
