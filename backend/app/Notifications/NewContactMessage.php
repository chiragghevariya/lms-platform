<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class NewContactMessage extends PlatformNotification
{
    use Queueable;

    public function __construct(public ContactMessage $contact)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'contact_message';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['name' => $this->contact->name, 'url' => config('lms.frontend_url').'/admin'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return (new MailMessage)
            ->subject('Contact form: '.$this->contact->subject)
            ->replyTo($this->contact->email, $this->contact->name)
            ->line("From: {$this->contact->name} <{$this->contact->email}>".($this->contact->phone ? " · {$this->contact->phone}" : ''))
            ->line($this->contact->message);
    }
}
