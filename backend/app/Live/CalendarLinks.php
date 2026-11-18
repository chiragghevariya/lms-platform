<?php

namespace App\Live;

use App\Models\LiveSession;
use Illuminate\Support\Str;

/** iCalendar (RFC 5545) files and "Add to Google Calendar" links. Times are UTC, so every client converts correctly. */
class CalendarLinks
{
    public function roomUrl(LiveSession $session): string
    {
        return config('lms.frontend_url').'/live/'.$session->uuid;
    }

    public function google(LiveSession $session): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $session->title,
            'dates' => $session->starts_at->copy()->utc()->format('Ymd\THis\Z').'/'.$session->endsAt()->utc()->format('Ymd\THis\Z'),
            'details' => trim(Str::limit(strip_tags((string) $session->description), 900)."\n\nJoin: ".$this->roomUrl($session)),
            'location' => $this->roomUrl($session),
            'ctz' => $session->timezone,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param iterable<LiveSession> $sessions */
    public function ics(iterable $sessions, string $calendarName): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.config('lms.brand.name').'//Live classes//EN',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->escape($calendarName),
        ];
        foreach ($sessions as $s) {
            $cancelled = $s->status->value === 'cancelled';
            array_push($lines,
                'BEGIN:VEVENT',
                'UID:'.$s->uuid.'@'.parse_url((string) config('lms.frontend_url'), PHP_URL_HOST),
                'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                'DTSTART:'.$s->starts_at->copy()->utc()->format('Ymd\THis\Z'),
                'DTEND:'.$s->endsAt()->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->escape($s->title),
                'DESCRIPTION:'.$this->escape(trim(Str::limit(strip_tags((string) $s->description), 900)."\n\nJoin: ".$this->roomUrl($s))),
                'URL:'.$this->roomUrl($s),
                'LOCATION:'.$this->escape($this->roomUrl($s)),
                'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
                'SEQUENCE:'.($s->updated_at?->getTimestamp() ?? 0) % 100000,
            );
            if (! $cancelled) {
                array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->escape($s->title), 'TRIGGER:-PT15M', 'END:VALARM');
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets are folded with CRLF + space (RFC 5545 §3.1). */
    private function fold(string $line): string
    {
        $out = '';
        $limit = 75; // the first line holds 75 octets; continuation lines 74 + the leading space
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--; // don't split a UTF-8 sequence
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
            $limit = 74;
        }

        return $out.$line;
    }
}
