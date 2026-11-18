<?php

namespace App\Notifications\Learning;

use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class SubmissionGradedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public AssignmentSubmission $submission)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'assignment_graded';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['lesson' => $this->submission->assignment->lesson->title, 'score' => $this->submission->score !== null ? $this->submission->score.'/'.$this->submission->assignment->max_score : 'changes requested', 'url' => config('lms.frontend_url').'/learn/'.$this->submission->assignment->lesson->course->slug.'/'.$this->submission->assignment->lesson->uuid];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $lesson = $this->submission->assignment->lesson;
        $mail = (new MailMessage)->subject(($this->submission->status === SubmissionStatus::Graded ? 'Graded: ' : 'Changes requested: ').$lesson->title);

        return ($this->submission->status === SubmissionStatus::Graded
            ? $mail->line("Your submission scored {$this->submission->score}/{$this->submission->assignment->max_score}.")
            : $mail->line('Your instructor asked for changes before grading.'))
            ->action('View feedback', config('lms.frontend_url').'/learn/'.$lesson->course->slug.'/'.$lesson->uuid);
    }
}
