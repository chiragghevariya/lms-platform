<?php

namespace App\Notify;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Base for every user-facing notification: channels come from NotificationRouter (defaults + preferences +
 * reachability), copy comes from Templates (admin-editable), every send is queued, rate limited per channel
 * and logged in notification_logs.
 */
abstract class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public ?int $campaignId = null;

    abstract public function event(): string;

    /** @return array<string, mixed> template variables (include "url" for the call to action) */
    abstract public function vars(object $notifiable): array;

    /** Richer built-in mail (attachments, tables…) used until an admin customises the mail template. */
    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return null;
    }

    /** Campaigns carry their own content, which always wins over the event template. */
    protected function preferDefaultMail(): bool
    {
        return false;
    }

    /**
     * Restrict to these channel keys (campaigns); null = the event's configuration.
     *
     * @return list<string>|null
     */
    public function onlyChannels(): ?array
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return app(NotificationRouter::class)->channels($notifiable, $this);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return array_fill_keys(array_values(Channels::DRIVERS), 'notifications') + ['mail' => 'mail'];
    }

    /** @return list<object> */
    public function middleware(object $notifiable, string $channel): array
    {
        return [new RateLimited('notify:'.Channels::key($channel))];
    }

    /** @return array<string, mixed> */
    public function allVars(object $notifiable): array
    {
        // The notification's own values win; defaults only fill gaps.
        return array_filter($this->vars($notifiable), fn ($v) => $v !== null)
            + ['name' => $notifiable instanceof User ? explode(' ', $notifiable->name)[0] : '', 'url' => config('lms.frontend_url')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tpl = app(Templates::class)->get($this->event(), 'mail');
        $vars = $this->allVars($notifiable);
        $message = (! $tpl['customised'] || $this->preferDefaultMail()) && ($custom = $this->defaultMail($notifiable)) ? $custom : (new MailMessage)
            ->subject(Templates::render($tpl['subject'], $vars))
            ->markdown('notifications.template', [
                'content' => Templates::render($tpl['body'] ?? $tpl['subject'], $vars, markdown: true),
                'actionText' => $tpl['action_text'],
                'actionUrl' => $vars['url'] ?? null,
            ]);

        $message->viewData['preferencesUrl'] = config('lms.frontend_url').'/app/notification-settings';
        $mandatory = in_array('mail', (array) config('notify.events.'.$this->event().'.mandatory', []), true);
        $unsubscribe = $notifiable instanceof User && ! $mandatory ? self::unsubscribeUrl($notifiable, $this->event()) : null;
        $message->viewData['unsubscribeUrl'] = $unsubscribe;
        $id = (string) $this->id;

        return $message->withSymfonyMessage(function (Email $email) use ($unsubscribe, $id) {
            $h = $email->getHeaders();
            // Mailgun echoes these back in delivery webhooks → we match events to this log row.
            $h->addTextHeader('X-Mailgun-Variables', (string) json_encode(['notification_id' => $id]));
            if ($unsubscribe) {
                $h->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'); // RFC 8058
            }
        });
    }

    /**
     * In-app bell.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $tpl = app(Templates::class)->get($this->event(), 'database');
        $vars = $this->allVars($notifiable);

        return [
            'event' => $this->event(),
            'title' => Templates::render($tpl['subject'], $vars),
            'body' => Templates::render($tpl['body'], $vars),
            'url' => $vars['url'] ?? null,
        ];
    }

    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        $tpl = app(Templates::class)->get($this->event(), 'whatsapp');
        if (! $tpl['whatsapp_template']) {
            return null;
        }
        $vars = $this->allVars($notifiable);

        return new WhatsAppMessage(
            (string) $tpl['whatsapp_template'],
            (string) $tpl['whatsapp_language'],
            array_map(fn (string $p) => Templates::render(str_contains($p, '{{') ? $p : '{{'.$p.'}}', $vars), $tpl['whatsapp_params']),
            Templates::render(DefaultTemplates::for($this->event(), 'sms')['body'] ?? null, $vars),
        );
    }

    public function toSms(object $notifiable): string
    {
        $tpl = app(Templates::class)->get($this->event(), 'sms');

        return mb_substr(Templates::render($tpl['body'], $this->allVars($notifiable)), 0, 480);
    }

    public function toPush(object $notifiable): PushMessage
    {
        $tpl = app(Templates::class)->get($this->event(), 'push');
        $vars = $this->allVars($notifiable);

        return new PushMessage(Templates::render($tpl['subject'], $vars), Templates::render($tpl['body'], $vars),
            array_filter(['event' => $this->event(), 'url' => (string) ($vars['url'] ?? '')]));
    }

    /** The queued job for one channel gave up (all retries failed). */
    public function failed(Throwable $e): void
    {
        app(NotificationLogger::class)->failedNotification((string) $this->id, $e->getMessage());
    }

    public static function unsubscribeUrl(User $user, string $event): string
    {
        return URL::signedRoute('notifications.unsubscribe', ['user' => $user->uuid, 'event' => $event]);
    }
}
