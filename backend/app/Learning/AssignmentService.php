<?php

namespace App\Learning;

use App\Enums\SubmissionStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\Learning\SubmissionGradedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(private readonly ProgressService $progress, private readonly GamificationService $gamification) {}

    public function dueAt(User $user, Assignment $assignment): ?Carbon
    {
        if ($assignment->due_days === null) {
            return null;
        }
        $start = Enrollment::where('user_id', $user->id)->where('course_id', $assignment->lesson->course_id)->value('started_at');

        return $start ? Carbon::parse((string) $start)->addDays($assignment->due_days)->endOfDay() : null;
    }

    /**
     * New submission or resubmission (allowed until graded, or when returned for changes).
     *
     * @param  list<UploadedFile>  $files
     */
    public function submit(User $user, Assignment $assignment, ?string $text, array $files): AssignmentSubmission
    {
        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', $user->id)->first();
        if ($existing && $existing->status === SubmissionStatus::Graded) {
            throw ValidationException::withMessages(['submission' => 'This assignment has already been graded.']);
        }
        if (trim(strip_tags((string) $text)) === '' && $files === []) {
            throw ValidationException::withMessages(['submission' => 'Add some text or attach at least one file.']);
        }

        $disk = (string) config('video.resources_disk');
        $stored = array_map(fn (UploadedFile $f) => [
            'path' => (string) $f->storeAs("submissions/{$assignment->uuid}/{$user->uuid}", Str::random(20).'.'.strtolower($f->getClientOriginalExtension()), $disk),
            'name' => Str::limit($f->getClientOriginalName(), 150, ''),
            'size' => (int) $f->getSize(),
            'mime' => $f->getMimeType(),
        ], $files);

        if ($existing && $stored !== []) { // new uploads replace the previous set; a text-only revision keeps them
            foreach ($existing->files ?? [] as $old) {
                Storage::disk($disk)->delete($old['path']);
            }
        }

        $submission = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $user->id],
            [
                'text' => $text,
                'files' => $stored ?: ($existing->files ?? null),
                'status' => SubmissionStatus::Submitted,
                'submitted_at' => now(),
                'revision' => ($existing->revision ?? 0) + 1,
                'score' => null,
                'feedback' => $existing?->feedback,
            ],
        );

        $this->gamification->award($user, 'assignment_submitted', 'assignment:'.$assignment->id);
        $this->gamification->recordActivity($user);

        return $submission;
    }

    /** Grade (final) or return for changes. Grading at/above the pass score completes the lesson. */
    public function grade(AssignmentSubmission $submission, User $grader, ?int $score, ?string $feedback, SubmissionStatus $status): AssignmentSubmission
    {
        $assignment = $submission->assignment;
        if ($status === SubmissionStatus::Graded && ($score === null || $score > $assignment->max_score)) {
            throw ValidationException::withMessages(['score' => "Score must be between 0 and {$assignment->max_score}."]);
        }

        $submission->forceFill([
            'status' => $status,
            'score' => $status === SubmissionStatus::Graded ? $score : null,
            'feedback' => $feedback,
            'graded_by' => $grader->id,
            'graded_at' => now(),
        ])->save();

        if ($status === SubmissionStatus::Graded && ($assignment->pass_score === null || $score >= $assignment->pass_score)) {
            $this->progress->complete($submission->user, $assignment->lesson);
        }

        $submission->user->notify(new SubmissionGradedNotification($submission));

        return $submission;
    }
}
