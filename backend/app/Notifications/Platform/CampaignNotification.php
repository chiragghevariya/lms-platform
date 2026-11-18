<?php

namespace App\Notifications\Platform;

use App\Models\Campaign;
use App\Notify\PlatformNotification;
use App\Notify\Templates;
use App\Notify\WhatsAppMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/** Admin broadcast: content comes from the campaign; channels limited to what the campaign chose. */
class CampaignNotification extends PlatformNotification
{
    public function __construct(public Campaign $campaign)
    {
        $this->campaignId = $campaign->id;
    }

    public function event(): string
    {
        return 'admin_announcement';
    }

    /** @return list<string> */
    public function onlyChannels(): array
    {
        return array_values(array_intersect($this->campaign->channels, ['mail', 'database', 'whatsapp', 'push']));
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['subject' => $this->campaign->subject, 'url' => $this->campaign->action_url ?: config('lms.frontend_url')];
    }

    protected function preferDefaultMail(): bool
    {
        return true;
    }

    protected function defaultMail(object $notifiable): MailMessage
    {
        $vars = $this->allVars($notifiable);

        return (new MailMessage)
            ->subject(Templates::render($this->campaign->subject, $vars))
            ->markdown('notifications.template', [
                'content' => Templates::render($this->campaign->body, $vars, markdown: true),
                'actionText' => $this->campaign->action_text,
                'actionUrl' => $this->campaign->action_url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $vars = $this->allVars($notifiable);

        return [
            'event' => 'admin_announcement',
            'title' => Templates::render($this->campaign->subject, $vars),
            // In-app text is plain: render the Markdown, then strip it (no "**bold**" asterisks in the bell).
            'body' => mb_substr(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(Str::markdown(
                Templates::render($this->campaign->body, $vars), ['html_input' => 'strip'],
            ))))), 0, 300),
            'url' => $this->campaign->action_url,
        ];
    }

    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        if (! $this->campaign->whatsapp_template) {
            return null;
        }
        $vars = $this->allVars($notifiable);

        return new WhatsAppMessage($this->campaign->whatsapp_template, $this->campaign->whatsapp_language ?: (string) config('notify.whatsapp.language'),
            array_map(fn (string $p) => Templates::render($p, $vars), $this->campaign->whatsapp_params ?? []));
    }
}
