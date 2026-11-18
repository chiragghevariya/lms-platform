<?php

namespace App\Instructors;

use App\Models\Course;
use App\Models\CourseAnswer;
use App\Models\CourseQuestion;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\Instructors\QuestionAnsweredNotification;
use Illuminate\Support\Facades\DB;

/** Course Q&A: learners with access ask (per lesson); anyone with access can reply; instructor replies mark it answered. */
class QuestionService
{
    public function ask(User $user, Course $course, ?Lesson $lesson, string $title, string $body): CourseQuestion
    {
        return CourseQuestion::create([
            'course_id' => $course->id, 'lesson_id' => $lesson?->id, 'user_id' => $user->id,
            'title' => trim($title), 'body' => trim($body),
        ]);
    }

    public function answer(User $user, CourseQuestion $question, string $body): CourseAnswer
    {
        $isInstructor = $question->course->isTaughtBy($user);

        $answer = DB::transaction(function () use ($user, $question, $body, $isInstructor) {
            $answer = CourseAnswer::create(['question_id' => $question->id, 'user_id' => $user->id, 'body' => trim($body), 'is_instructor' => $isInstructor]);
            $question->increment('answers_count');
            if ($isInstructor && ! $question->instructor_answered_at) {
                $question->forceFill(['instructor_answered_at' => now()])->save();
            }

            return $answer;
        });

        if ($question->user_id !== $user->id) {
            $question->user->notify(new QuestionAnsweredNotification($question));
        }

        return $answer;
    }

    /** @return array<string, mixed> */
    public function present(CourseQuestion $q, ?User $viewer, bool $withAnswers = false): array
    {
        return [
            'id' => $q->uuid,
            'title' => $q->title,
            'body' => $q->body,
            'author' => ['name' => $q->user->name],
            'is_mine' => $viewer?->id === $q->user_id,
            'lesson' => $q->lesson ? ['id' => $q->lesson->uuid, 'title' => $q->lesson->title] : null,
            'course' => ['id' => $q->course->uuid, 'slug' => $q->course->slug, 'title' => $q->course->title],
            'answers_count' => $q->answers_count,
            'instructor_answered' => $q->instructor_answered_at !== null,
            'created_at' => $q->created_at?->toIso8601String(),
            'answers' => $withAnswers ? $q->answers()->with('user')->oldest()->get()->map(fn (CourseAnswer $a) => [
                'id' => $a->uuid,
                'body' => $a->body,
                'author' => ['name' => $a->user->name],
                'is_instructor' => $a->is_instructor,
                'is_mine' => $viewer?->id === $a->user_id,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values() : null,
        ];
    }
}
