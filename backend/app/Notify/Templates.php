<?php

namespace App\Notify;

use App\Models\NotificationTemplate;

/**
 * Copy for every event × channel: admin edits (notification_templates) over built-in defaults.
 * Bodies use {{variable}} placeholders; mail bodies are Markdown.
 */
class Templates
{
    /** @var array<string, NotificationTemplate|null> */
    private array $cache = [];

    /** @return array{subject: string|null, body: string|null, action_text: string|null, whatsapp_template: string|null, whatsapp_language: string|null, whatsapp_params: list<string>, customised: bool} */
    public function get(string $event, string $channel): array
    {
        $key = "{$event}.{$channel}";
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = NotificationTemplate::where('event', $event)->where('channel', $channel)->where('is_active', true)->first();
        }
        $row = $this->cache[$key];
        $d = DefaultTemplates::for($event, $channel);
        $wa = (array) config("notify.events.{$event}.whatsapp", []);

        return [
            'subject' => ($row ? $row->subject : null) ?? $d['subject'] ?? null,
            'body' => ($row ? $row->body : null) ?? $d['body'] ?? null,
            'action_text' => ($row ? $row->action_text : null) ?? $d['action_text'] ?? null,
            'whatsapp_template' => ($row ? $row->whatsapp_template : null) ?? ($wa['template'] ?? null),
            'whatsapp_language' => ($row ? $row->whatsapp_language : null) ?? (string) config('notify.whatsapp.language'),
            'whatsapp_params' => array_values(($row ? $row->whatsapp_params : null) ?? ($wa['params'] ?? [])),
            'customised' => $row !== null && ($row->subject !== null || $row->body !== null),
        ];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @param array<string, mixed> $vars */
    public static function render(?string $text, array $vars, bool $markdown = false): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, $markdown) {
            $v = (string) ($vars[$m[1]] ?? '');

            // Escape user-supplied values so a name can't inject Markdown links/HTML into branded mail.
            return $markdown ? str_replace(['\\', '[', ']', '<', '>', '*', '_', '`'], ['\\\\', '\[', '\]', '&lt;', '&gt;', '\*', '\_', '\`'], $v) : $v;
        }, (string) $text);
    }
}
