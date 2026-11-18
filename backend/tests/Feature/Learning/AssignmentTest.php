<?php

use App\Enums\MembershipTier;
use App\Models\LessonProgress;
use App\Models\User;
use App\Notifications\Learning\SubmissionGradedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWith(['assignment']);
    $this->course->update(['instructor_id' => $this->instructor->id]);
    $this->lesson = $this->course->sections[0]->lessons[0];
    $this->learner = User::factory()->student()->create();
    subscribe($this->learner, MembershipTier::Starter);

    $this->actingAs($this->instructor)->putJson(api("instructor/lessons/{$this->lesson->uuid}/assignment"), [
        'instructions' => '<p>Build a landing page</p>', 'due_days' => 7, 'max_score' => 100, 'pass_score' => 60,
        'file_types' => ['pdf', 'zip'], 'max_file_mb' => 5, 'allow_text' => true,
    ])->assertOk();
    $this->submitUrl = api("learn/lessons/{$this->lesson->uuid}/assignment/submission");
});

it('lets the instructor read back the settings', function () {
    $this->actingAs($this->instructor)->getJson(api("instructor/lessons/{$this->lesson->uuid}/assignment"))
        ->assertOk()->assertJsonPath('data.max_score', 100)->assertJsonPath('data.file_types', ['pdf', 'zip']);
    $this->actingAs($this->learner)->getJson(api("instructor/lessons/{$this->lesson->uuid}/assignment"))->assertForbidden();
});

it('shows instructions with a due date relative to enrolment', function () {
    $this->actingAs($this->learner)->getJson(api("learn/lessons/{$this->lesson->uuid}/assignment"))
        ->assertOk()->assertJsonPath('data.max_score', 100)->assertJsonPath('data.file_types', ['pdf', 'zip'])
        ->assertJsonPath('data.submission', null)
        ->assertJson(fn ($j) => $j->where('data.due_at', fn ($d) => $d !== null)->etc());
});

it('accepts uploads, enforces file types and allows resubmission until graded', function () {
    $this->actingAs($this->learner)->post($this->submitUrl, ['files' => [UploadedFile::fake()->create('virus.exe', 10)]], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('files.0');
    $this->actingAs($this->learner)->postJson($this->submitUrl, [])->assertJsonValidationErrors('submission');

    $first = $this->actingAs($this->learner)->post($this->submitUrl, ['text' => 'v1', 'files' => [UploadedFile::fake()->create('work.pdf', 20, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.files.0.name', 'work.pdf')->json('data');

    $this->actingAs($this->learner)->post($this->submitUrl, ['text' => 'v2'], ['Accept' => 'application/json'])
        ->assertJsonPath('data.revision', 2)->assertJsonPath('data.text', 'v2');

    // Learner and course instructor can download; strangers can't.
    $this->actingAs($this->learner)->get(api("learn/submissions/{$first['id']}/files/0"))->assertOk();
    $this->actingAs($this->instructor)->get(api("learn/submissions/{$first['id']}/files/0"))->assertOk();
    $this->actingAs(User::factory()->create())->get(api("learn/submissions/{$first['id']}/files/0"), ['Accept' => 'application/json'])->assertNotFound();
});

it('lets the instructor grade; passing completes the lesson and notifies the learner', function () {
    $sub = $this->actingAs($this->learner)->postJson($this->submitUrl, ['text' => 'my work'])->json('data');

    $queue = $this->actingAs($this->instructor)->getJson(api('instructor/submissions?status=submitted'))->assertOk()->json('data');
    expect($queue)->toHaveCount(1)->and($queue[0]['id'])->toBe($sub['id']);

    $this->actingAs($this->instructor)->patchJson(api("instructor/submissions/{$sub['id']}"), ['status' => 'graded', 'score' => 101])->assertJsonValidationErrors('score');
    $this->actingAs($this->instructor)->patchJson(api("instructor/submissions/{$sub['id']}"), ['status' => 'graded', 'score' => 80, 'feedback' => 'Nice work'])
        ->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 80);

    Notification::assertSentTo($this->learner, SubmissionGradedNotification::class);
    expect(LessonProgress::where('user_id', $this->learner->id)->whereNotNull('completed_at')->exists())->toBeTrue();
    $this->actingAs($this->learner)->getJson(api("learn/lessons/{$this->lesson->uuid}/assignment"))->assertJsonPath('data.submission.feedback', 'Nice work');
    $this->actingAs($this->learner)->postJson($this->submitUrl, ['text' => 'again'])->assertJsonValidationErrors('submission');
});

it('returns work for revision without completing the lesson', function () {
    $sub = $this->actingAs($this->learner)->postJson($this->submitUrl, ['text' => 'draft'])->json('data');
    $this->actingAs($this->instructor)->patchJson(api("instructor/submissions/{$sub['id']}"), ['status' => 'returned', 'feedback' => 'Add a hero section'])
        ->assertJsonPath('data.status', 'returned');

    expect(LessonProgress::whereNotNull('completed_at')->exists())->toBeFalse();
    $this->actingAs($this->learner)->getJson(api('me/dashboard'))->assertJsonPath('data.pending_assignments.0.status', 'returned');
    $this->actingAs($this->learner)->postJson($this->submitUrl, ['text' => 'with hero'])->assertJsonPath('data.status', 'submitted');
});

it('keeps other instructors out of the grading queue', function () {
    $sub = $this->actingAs($this->learner)->postJson($this->submitUrl, ['text' => 'x'])->json('data');
    $other = User::factory()->instructor()->create();

    $this->actingAs($other)->getJson(api('instructor/submissions'))->assertJsonCount(0, 'data');
    $this->actingAs($other)->patchJson(api("instructor/submissions/{$sub['id']}"), ['status' => 'graded', 'score' => 1])->assertForbidden();
    $this->actingAs($this->learner)->getJson(api('instructor/submissions'))->assertForbidden();
});
