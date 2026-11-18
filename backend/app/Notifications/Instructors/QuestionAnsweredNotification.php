<?php

namespace App\Notifications\Instructors;

use App\Models\CourseQuestion;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class QuestionAnsweredNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public CourseQuestion $question)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'question_answered';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['question' => $this->question->title, 'course' => $this->question->course->title, 'url' => config('lms.frontend_url').($this->question->lesson ? '/learn/'.$this->question->course->slug.'/'.$this->question->lesson->uuid.'?tab=qa' : '/courses/'.$this->question->course->slug)];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $q = $this->question;
        $url = config('lms.frontend_url').($q->lesson ? "/learn/{$q->course->slug}/{$q->lesson->uuid}?tab=qa" : "/courses/{$q->course->slug}");

        return (new MailMessage)
            ->subject('Your question got an answer: '.$q->title)
            ->line('The instructor answered your question in “'.$q->course->title.'”.')
            ->action('Read the answer', $url);
    }
}
