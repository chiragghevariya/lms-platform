<?php

namespace App\Courses;

use App\Enums\CourseStatus;
use App\Enums\LessonType;
use App\Enums\Permission;
use App\Jobs\AnnounceNewCourse;
use App\Models\Course;
use App\Models\User;
use App\Notifications\Courses\CourseReviewedNotification;
use App\Notifications\Courses\CourseSubmittedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** draft → review (instructor submits) → published (admin approves) | back to draft with notes (rejected). */
class CourseWorkflow
{
    public function submit(Course $course): Course
    {
        if ($course->status !== CourseStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only draft courses can be submitted for review.']);
        }

        $problems = $this->readinessProblems($course);
        if ($problems) {
            throw ValidationException::withMessages(['course' => $problems]);
        }

        $course->forceFill(['status' => CourseStatus::Review, 'submitted_at' => now(), 'review_notes' => null])->save();

        $admins = User::permission(Permission::CoursesManageAny->value)->get()->merge(User::role('super_admin')->get())->unique('id');
        Notification::send($admins, new CourseSubmittedNotification($course));

        return $course;
    }

    public function approve(Course $course, User $admin): Course
    {
        if ($course->status !== CourseStatus::Review) {
            throw ValidationException::withMessages(['status' => 'Only courses in review can be published.']);
        }

        $firstPublish = $course->published_at === null;
        $course->forceFill(['status' => CourseStatus::Published, 'published_at' => $course->published_at ?? now(), 'review_notes' => null])->save();
        $course->searchable();
        if ($firstPublish) {
            AnnounceNewCourse::dispatch($course->id); // re-approvals after edits don't re-announce
        }
        $course->instructor->notify(new CourseReviewedNotification($course, approved: true));

        return $course;
    }

    public function reject(Course $course, string $notes): Course
    {
        if ($course->status !== CourseStatus::Review) {
            throw ValidationException::withMessages(['status' => 'Only courses in review can be rejected.']);
        }

        $course->forceFill(['status' => CourseStatus::Draft, 'review_notes' => $notes])->save();
        $course->instructor->notify(new CourseReviewedNotification($course, approved: false));

        return $course;
    }

    /** Unpublish (admin) — keeps enrollments/progress. */
    public function unpublish(Course $course): Course
    {
        $course->forceFill(['status' => CourseStatus::Draft])->save();
        $course->unsearchable();

        return $course;
    }

    /** @return list<string> */
    public function readinessProblems(Course $course): array
    {
        $course->loadMissing('sections.lessons.video');
        $problems = [];

        if (! $course->description) {
            $problems[] = 'Add a course description.';
        }
        if (! $course->category_id) {
            $problems[] = 'Choose a category.';
        }
        if (! $course->thumbnail_path) {
            $problems[] = 'Upload a thumbnail image.';
        }

        $lessons = $course->sections->flatMap->lessons;
        if ($lessons->isEmpty()) {
            $problems[] = 'Add at least one lesson.';
        }
        foreach ($lessons as $lesson) {
            if ($lesson->type === LessonType::Video && ! $lesson->video?->isReady()) {
                $problems[] = "Lesson “{$lesson->title}” needs a processed video.";
            }
            if ($lesson->type === LessonType::Quiz && ! $lesson->quiz()->whereHas('questions')->exists()) {
                $problems[] = "Quiz “{$lesson->title}” needs at least one question.";
            }
            if ($lesson->type === LessonType::Assignment && ! $lesson->assignment()->exists()) {
                $problems[] = "Assignment “{$lesson->title}” needs its settings.";
            }
            if ($lesson->type === LessonType::Pdf && ! $lesson->attachment_path) {
                $problems[] = "Lesson “{$lesson->title}” needs a PDF.";
            }
        }

        return $problems;
    }
}
