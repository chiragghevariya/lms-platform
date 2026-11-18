<?php

use App\Enums\MembershipTier;
use App\Models\LessonProgress;
use App\Models\User;

beforeEach(function () {
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWith(['quiz'], 1);
    $this->course->update(['instructor_id' => $this->instructor->id]);
    $this->lesson = $this->course->sections[0]->lessons[0];
    $this->learner = User::factory()->student()->create();
    subscribe($this->learner, MembershipTier::Starter);

    $this->quizBody = fn (array $o = []) => [
        'pass_mark' => 70, 'time_limit_seconds' => 300, 'attempts_allowed' => 2, 'shuffle' => false, 'show_answers' => true,
        'questions' => [
            ['type' => 'mcq_single', 'prompt' => 'Capital of India?', 'points' => 2, 'explanation' => 'New Delhi is the capital.',
                'options' => [['text' => 'Mumbai', 'is_correct' => false], ['text' => 'New Delhi', 'is_correct' => true]]],
            ['type' => 'mcq_multi', 'prompt' => 'Pick the primes', 'points' => 3,
                'options' => [['text' => '2', 'is_correct' => true], ['text' => '3', 'is_correct' => true], ['text' => '4', 'is_correct' => false]]],
            ['type' => 'true_false', 'prompt' => 'The earth is flat', 'points' => 1,
                'options' => [['text' => 'True', 'is_correct' => false], ['text' => 'False', 'is_correct' => true]]],
            ['type' => 'short_text', 'prompt' => 'HTML stands for HyperText ___ Language', 'points' => 4, 'accepted_answers' => ['Markup']],
        ],
        ...$o,
    ];
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), ($this->quizBody)())->assertOk();
});

function answerAll(array $attempt, array $correctness): array
{
    $answers = [];
    foreach ($attempt['questions'] as $i => $q) {
        $right = $correctness[$i];
        $answers[$q['id']] = match ($q['type']) {
            'mcq_single' => ['option_ids' => [$q['options'][$right ? 1 : 0]['id']]],
            'mcq_multi' => ['option_ids' => $right ? [$q['options'][0]['id'], $q['options'][1]['id']] : [$q['options'][0]['id']]],
            'true_false' => ['option_ids' => [$q['options'][$right ? 1 : 0]['id']]],
            'short_text' => ['text' => $right ? '  markup ' : 'Machine'],
        };
    }

    return $answers;
}

it('never sends correct answers or explanations before submission', function () {
    $attempt = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->assertCreated()->json('data');

    expect(json_encode($attempt))->not->toContain('is_correct":true')->not->toContain('New Delhi is the capital')->not->toContain('accepted_answers":["Markup"')
        ->and($attempt['seconds_left'])->toBeLessThanOrEqual(300)
        ->and($attempt['score'])->toBeNull();
});

it('auto-grades every question type, passes the lesson and reveals explanations', function () {
    $attempt = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');

    $result = $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$attempt['id']}/submit"), [
        'answers' => answerAll($attempt, [true, true, false, true]), // 2+3+0+4 = 9/10
    ])->assertOk()->json('data');

    expect($result)->status->toBe('submitted')->score->toBe(9)->max_score->toBe(10)->percent->toBe(90)->passed->toBeTrue()
        ->and($result['questions'][0]['explanation'])->toBe('New Delhi is the capital.')
        ->and($result['questions'][2]['is_correct'])->toBeFalse()
        ->and($result['questions'][3]['is_correct'])->toBeTrue(); // case/whitespace-insensitive short text

    expect(LessonProgress::where('user_id', $this->learner->id)->whereNotNull('completed_at')->count())->toBe(1);
});

it('gives no partial credit on multi-select', function () {
    $attempt = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
    $r = $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$attempt['id']}/submit"), ['answers' => answerAll($attempt, [true, false, true, true])])->json('data');

    expect($r['questions'][1]['points_awarded'])->toBe(0)->and($r['passed'])->toBeTrue(); // 7/10 = 70%
});

it('fails below the pass mark and limits attempts', function () {
    foreach ([1, 2] as $n) {
        $attempt = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->assertCreated()->json('data');
        $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$attempt['id']}/submit"), ['answers' => answerAll($attempt, [false, false, false, false])])
            ->assertJsonPath('data.passed', false);
    }

    $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->assertJsonValidationErrors('quiz');
    $this->actingAs($this->learner)->getJson(api("learn/lessons/{$this->lesson->uuid}/quiz"))
        ->assertJsonPath('data.attempts_used', 2)->assertJsonPath('data.passed', false);
});

it('resumes the open attempt and autosaves answers', function () {
    $a = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
    $q = $a['questions'][0];
    $this->actingAs($this->learner)->putJson(api("learn/quiz-attempts/{$a['id']}/answers"), ['answers' => [$q['id'] => ['option_ids' => [$q['options'][1]['id']]]]])->assertOk();

    $again = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
    expect($again['id'])->toBe($a['id'])->and($again['questions'][0]['answer']['option_ids'])->toBe([$q['options'][1]['id']]);
});

it('enforces the time limit on the server: late answers are discarded', function () {
    $a = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
    $q = $a['questions'][0];
    $this->actingAs($this->learner)->putJson(api("learn/quiz-attempts/{$a['id']}/answers"), ['answers' => [$q['id'] => ['option_ids' => [$q['options'][1]['id']]]]]);

    $this->travel(6)->minutes();
    $r = $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$a['id']}/submit"), ['answers' => answerAll($a, [true, true, true, true])])->json('data');

    expect($r['status'])->toBe('expired')->and($r['score'])->toBe(2); // only the answer saved in time counts
});

it('keeps attempts private and hides answers when show_answers is off', function () {
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), ($this->quizBody)(['show_answers' => false]))->assertOk();
    $a = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');

    $this->actingAs(User::factory()->create())->getJson(api("learn/quiz-attempts/{$a['id']}"))->assertNotFound();
    $r = $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$a['id']}/submit"), ['answers' => answerAll($a, [true, true, true, true])])->json('data');
    expect($r['questions'][0]['explanation'])->toBeNull()->and(json_encode($r))->not->toContain('is_correct":true}');
});

it('shuffles question order per attempt when enabled', function () {
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), ($this->quizBody)(['shuffle' => true, 'attempts_allowed' => null]))->assertOk();
    $orders = collect(range(1, 8))->map(function () {
        $a = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
        $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$a['id']}/submit"));

        return implode(',', array_column($a['questions'], 'prompt'));
    });

    expect($orders->unique()->count())->toBeGreaterThan(1);
});

it('validates the quiz builder and protects answered questions', function () {
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), ($this->quizBody)(['questions' => [
        ['type' => 'mcq_single', 'prompt' => 'Bad', 'points' => 1, 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true]]],
        ['type' => 'short_text', 'prompt' => 'No answers', 'points' => 1],
    ]]))->assertJsonValidationErrors(['questions.0', 'questions.1']);

    $a = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->lesson->uuid}/quiz/attempts"))->json('data');
    $this->actingAs($this->learner)->postJson(api("learn/quiz-attempts/{$a['id']}/submit"), ['answers' => answerAll($a, [true, true, true, true])]);

    $current = $this->actingAs($this->instructor)->getJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"))->json('data');

    $trimmed = ($this->quizBody)(['questions' => [array_merge($current['questions'][0], ['prompt' => 'Capital of India (edited)?'])]]);
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), $trimmed)
        ->assertJsonValidationErrors('questions');

    // Editing in place keeps the learner's graded attempt intact.
    $all = ($this->quizBody)(['questions' => array_map(fn ($q) => $q, $current['questions'])]);
    $all['questions'][0]['prompt'] = 'Capital of India (edited)?';
    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/quiz"), $all)->assertOk();
    $this->actingAs($this->learner)->getJson(api("learn/quiz-attempts/{$a['id']}"))
        ->assertJsonPath('data.score', 10)->assertJsonPath('data.questions.0.prompt', 'Capital of India (edited)?');
});
