<?php

namespace Database\Seeders;

use App\Enums\LessonType;
use App\Enums\QuestionType;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Seeder;

/** Demo data (non-production): a quiz and an assignment in each seeded course's "Core skills" section. Idempotent. */
class LearningDemoSeeder extends Seeder
{
    public function run(): void
    {
        Course::with('sections')->get()->each(function (Course $course) {
            $section = $course->sections->firstWhere('title', 'Core skills');
            if (! $section || Lesson::where('section_id', $section->id)->whereIn('type', [LessonType::Quiz, LessonType::Assignment])->exists()) {
                return;
            }
            $sort = (int) Lesson::where('section_id', $section->id)->max('sort');

            $quizLesson = Lesson::create([
                'course_id' => $course->id, 'section_id' => $section->id, 'title' => 'Core skills: check your understanding',
                'type' => LessonType::Quiz, 'content' => '<p>A quick check before you move on.</p>', 'duration_seconds' => 300, 'sort' => $sort + 1,
            ]);
            $quiz = $quizLesson->quiz()->create(['pass_mark' => 60, 'time_limit_seconds' => 600, 'attempts_allowed' => 3, 'shuffle' => false, 'show_answers' => true]);
            $questions = [
                [QuestionType::McqSingle, 'What should you define before starting any client project?', 'A clear scope protects you from endless revisions.', [['A written scope and deliverables', true], ['Your hourly rate only', false], ['The logo colours', false]]],
                [QuestionType::McqMulti, 'Which of these help you get paid on time?', 'Advance payments and clear invoices both reduce late payments.', [['Taking a 50% advance', true], ['Sending a clear invoice with due date', true], ['Starting work without agreement', false]]],
                [QuestionType::TrueFalse, 'You should share final files before receiving payment.', 'Share watermarked previews; release finals after payment.', [['True', false], ['False', true]]],
                [QuestionType::ShortText, 'What does the "C" in CTA stand for?', 'CTA = Call To Action.', []],
            ];
            foreach ($questions as $i => [$type, $prompt, $explanation, $options]) {
                $q = $quiz->questions()->create([
                    'type' => $type, 'prompt' => $prompt, 'points' => $type === QuestionType::McqMulti ? 2 : 1, 'explanation' => $explanation, 'sort' => $i,
                    'accepted_answers' => $type === QuestionType::ShortText ? ['Call'] : null,
                ]);
                foreach ($options as $j => [$text, $correct]) {
                    $q->options()->create(['text' => $text, 'is_correct' => $correct, 'sort' => $j]);
                }
            }

            $assignmentLesson = Lesson::create([
                'course_id' => $course->id, 'section_id' => $section->id, 'title' => 'Core skills: practical assignment',
                'type' => LessonType::Assignment, 'duration_seconds' => 1800, 'sort' => $sort + 2,
            ]);
            $assignmentLesson->assignment()->create([
                'instructions' => '<p>Apply what you learned: create a one-page proposal for a sample client and upload it as a PDF, or paste a link to it.</p>',
                'due_days' => 7, 'max_score' => 100, 'pass_score' => 60, 'file_types' => ['pdf', 'docx', 'zip'], 'max_file_mb' => 10, 'allow_text' => true,
            ]);

            $course->refreshStats();
        });
    }
}
