<?php

namespace App\Learning;

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attempts are timed and graded on the server. Questions are served without correct
 * answers/explanations; those are only revealed after submission (if show_answers).
 */
class QuizService
{
    public function __construct(private readonly ProgressService $progress, private readonly GamificationService $gamification) {}

    /** Resumes an open attempt, otherwise starts a new one (respecting attempts_allowed). */
    public function start(User $user, Quiz $quiz): QuizAttempt
    {
        $open = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->where('status', AttemptStatus::InProgress)->latest('id')->first();
        if ($open) {
            if (! $open->isExpired()) {
                return $open;
            }
            $this->submit($open, []); // time ran out while away: grade what was saved
        }

        $used = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->count();
        if ($quiz->attempts_allowed !== null && $used >= $quiz->attempts_allowed) {
            throw ValidationException::withMessages(['quiz' => 'You have used all your attempts for this quiz.']);
        }

        $ids = $quiz->questions()->pluck('id');
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['quiz' => 'This quiz has no questions yet.']);
        }

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'status' => AttemptStatus::InProgress,
            'question_order' => ($quiz->shuffle ? $ids->shuffle() : $ids)->values()->all(),
            'started_at' => now(),
            'expires_at' => $quiz->time_limit_seconds ? now()->addSeconds($quiz->time_limit_seconds) : null,
            'max_score' => (int) $quiz->questions()->sum('points'),
        ]);
    }

    /**
     * Autosave. Ignored once the attempt is closed or past its deadline.
     *
     * @param  array<string, array{option_ids?: list<string>, text?: string|null}>  $answers  keyed by question uuid
     */
    public function save(QuizAttempt $attempt, array $answers): void
    {
        if ($attempt->status !== AttemptStatus::InProgress || $attempt->isExpired()) {
            return;
        }

        $questions = $this->questions($attempt)->keyBy('uuid');

        DB::transaction(function () use ($attempt, $answers, $questions) {
            foreach ($answers as $questionUuid => $answer) {
                /** @var QuizQuestion|null $q */
                $q = $questions->get($questionUuid);
                if (! $q) {
                    continue;
                }
                $optionIds = $q->options->whereIn('uuid', $answer['option_ids'] ?? [])->pluck('id')->values()->all();
                QuizAnswer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $q->id],
                    ['selected_option_ids' => $optionIds, 'text_answer' => isset($answer['text']) ? Str::limit((string) $answer['text'], 1000, '') : null],
                );
            }
        });
    }

    /**
     * Grades and closes the attempt. Answers sent after the deadline are discarded.
     *
     * @param  array<string, array{option_ids?: list<string>, text?: string|null}>  $finalAnswers
     */
    public function submit(QuizAttempt $attempt, array $finalAnswers): QuizAttempt
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            return $attempt;
        }

        $expired = $attempt->isExpired();
        if (! $expired) {
            $this->save($attempt, $finalAnswers);
        }

        $attempt = DB::transaction(function () use ($attempt, $expired) {
            $saved = QuizAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');
            $score = 0;

            foreach ($this->questions($attempt) as $q) {
                /** @var QuizAnswer $answer */
                $answer = $saved->get($q->id) ?? new QuizAnswer(['attempt_id' => $attempt->id, 'question_id' => $q->id]);
                $correct = $this->isCorrect($q, $answer);
                $answer->is_correct = $correct;
                $answer->points_awarded = $correct ? $q->points : 0;
                $answer->save();
                $score += $answer->points_awarded;
            }

            $quiz = $attempt->quiz;
            $percent = $attempt->max_score > 0 ? (int) floor($score / $attempt->max_score * 100) : 0;
            $attempt->forceFill([
                'status' => $expired ? AttemptStatus::Expired : AttemptStatus::Submitted,
                'submitted_at' => now(),
                'score' => $score,
                'percent' => $percent,
                'passed' => $percent >= $quiz->pass_mark,
            ])->save();

            return $attempt;
        });

        $user = User::findOrFail($attempt->user_id);
        $this->gamification->recordActivity($user);
        if ($attempt->passed) {
            $this->gamification->award($user, 'quiz_passed', 'quiz:'.$attempt->quiz_id);
            $this->progress->complete($user, $attempt->quiz->lesson);
        }
        if ($attempt->percent === 100) {
            $this->gamification->award($user, 'quiz_perfect', 'quiz-perfect:'.$attempt->quiz_id);
            $this->gamification->grantBadge($user, 'quiz_ace');
        }

        return $attempt;
    }

    public function isCorrect(QuizQuestion $q, QuizAnswer $answer): bool
    {
        $selected = collect($answer->selected_option_ids ?? [])->map(fn ($id) => (int) $id)->sort()->values();
        $correct = $q->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

        return match ($q->type) {
            // single / true-false: exactly the one right option; multi: exactly the right set (all-or-nothing)
            QuestionType::McqSingle, QuestionType::TrueFalse, QuestionType::McqMulti => $selected->isNotEmpty() && $selected->all() === $correct->all(),
            QuestionType::ShortText => $this->matchesText((string) $answer->text_answer, $q->accepted_answers ?? []),
        };
    }

    /** @param list<string> $accepted */
    private function matchesText(string $given, array $accepted): bool
    {
        $norm = fn (string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim($s))) ?? '';
        $g = $norm($given);

        return $g !== '' && collect($accepted)->contains(fn ($a) => $norm((string) $a) === $g);
    }

    /**
     * Questions of an attempt in the attempt's (possibly shuffled) order.
     *
     * @return Collection<int, QuizQuestion>
     */
    public function questions(QuizAttempt $attempt): Collection
    {
        $byId = QuizQuestion::with('options')->whereIn('id', $attempt->question_order)->get()->keyBy('id');

        return collect($attempt->question_order)->map(fn ($id) => $byId->get($id))->filter()->values();
    }

    /**
     * Learner-facing payload. Correctness/explanations only after submission when the quiz allows it.
     *
     * @return array<string, mixed>
     */
    public function present(QuizAttempt $attempt): array
    {
        $quiz = $attempt->quiz;
        $closed = $attempt->status !== AttemptStatus::InProgress;
        $reveal = $closed && $quiz->show_answers;
        $saved = QuizAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        return [
            'id' => $attempt->uuid,
            'status' => $attempt->status->value,
            'started_at' => $attempt->started_at->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'seconds_left' => $attempt->expires_at && ! $closed ? max(0, (int) now()->diffInSeconds($attempt->expires_at, false)) : null,
            'score' => $closed ? $attempt->score : null,
            'max_score' => $attempt->max_score,
            'percent' => $closed ? $attempt->percent : null,
            'passed' => $closed ? $attempt->passed : null,
            'pass_mark' => $quiz->pass_mark,
            'questions' => $this->questions($attempt)->map(function (QuizQuestion $q) use ($saved, $reveal, $closed) {
                /** @var QuizAnswer|null $a */
                $a = $saved->get($q->id);
                $selected = collect($a->selected_option_ids ?? [])->map(fn ($id) => (int) $id);

                return [
                    'id' => $q->uuid,
                    'type' => $q->type->value,
                    'prompt' => $q->prompt,
                    'points' => $q->points,
                    'options' => $q->options->map(fn ($o) => array_filter([
                        'id' => $o->uuid,
                        'text' => $o->text,
                        'is_correct' => $reveal ? $o->is_correct : null,
                    ], fn ($v) => $v !== null))->values(),
                    'answer' => ['option_ids' => $q->options->whereIn('id', $selected)->pluck('uuid')->values(), 'text' => $a?->text_answer],
                    'is_correct' => $closed ? (bool) $a?->is_correct : null,
                    'points_awarded' => $closed ? (int) ($a->points_awarded ?? 0) : null,
                    'explanation' => $reveal ? $q->explanation : null,
                    'accepted_answers' => $reveal && $q->type === QuestionType::ShortText ? $q->accepted_answers : null,
                ];
            })->values(),
        ];
    }
}
