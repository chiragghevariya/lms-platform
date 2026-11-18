<?php

use App\Enums\MembershipTier;
use App\Learning\ProgressService;
use App\Models\CourseProgress;
use App\Models\LessonResource;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->course = publishedCourse(1, 2);
    [$this->a, $this->b] = $this->course->sections[0]->lessons->all();
    $this->user = User::factory()->create();
    subscribe($this->user, MembershipTier::Starter);
});

it('returns lesson content with navigation, progress and notes', function () {
    $this->actingAs($this->user)->getJson(api("learn/lessons/{$this->a->uuid}"))
        ->assertOk()
        ->assertJsonPath('data.prev_id', null)
        ->assertJsonPath('data.next_id', $this->b->uuid)
        ->assertJsonPath('data.progress.position_seconds', 0)
        ->assertJsonPath('data.course.slug', $this->course->slug);

    expect(CourseProgress::where('user_id', $this->user->id)->value('last_lesson_id'))->toBe($this->a->id);
});

it('saves the resume position; seeking never counts as watching (Phase 4 rule)', function () {
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->a->uuid}/progress"), ['position_seconds' => 200])
        ->assertJsonPath('data.completed', false);
    $this->actingAs($this->user)->getJson(api("learn/lessons/{$this->a->uuid}"))->assertJsonPath('data.progress.position_seconds', 200);

    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->a->uuid}/progress"), ['position_seconds' => 590])
        ->assertJsonPath('data.completed', false)->assertJsonPath('data.watched_seconds', 0);
});

it('completes the course when every lesson is done and shows progress', function () {
    foreach ([$this->a, $this->b] as $l) {
        app(ProgressService::class)->complete($this->user, $l->load('course'));
    }

    expect(CourseProgress::where('user_id', $this->user->id)->first()->completed_at)->not->toBeNull();
    $this->actingAs($this->user)->getJson(api("courses/{$this->course->slug}"))
        ->assertJsonPath('data.access.progress_percent', 100)
        ->assertJsonPath('data.curriculum.0.lessons.1.completed', true);
    $this->actingAs($this->user)->getJson(api('learn/enrollments'))->assertJsonPath('data.0.progress_percent', 100);
});

it('adds and deletes timestamped notes (own only)', function () {
    $id = $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->a->uuid}/notes"), ['body' => 'Key idea', 'position_seconds' => 42])
        ->assertCreated()->json('data.id');
    $this->actingAs($this->user)->getJson(api("learn/lessons/{$this->a->uuid}"))->assertJsonPath('data.notes.0.position_seconds', 42);

    $this->actingAs(User::factory()->create())->deleteJson(api("learn/notes/{$id}"))->assertNotFound();
    $this->actingAs($this->user)->deleteJson(api("learn/notes/{$id}"))->assertNoContent();
});

it('downloads resources only with access', function () {
    Storage::fake('local');
    Storage::disk('local')->put('r/checklist.pdf', '%PDF-1.4 test');
    $res = LessonResource::create(['lesson_id' => $this->b->id, 'title' => 'Checklist', 'disk' => 'local', 'path' => 'r/checklist.pdf', 'mime' => 'application/pdf', 'size' => 13]);

    $this->actingAs($this->user)->get(api("learn/resources/{$res->uuid}"))->assertOk()->assertDownload('Checklist.pdf');
    $this->actingAs(User::factory()->create())->get(api("learn/resources/{$res->uuid}"), ['Accept' => 'application/json'])->assertStatus(402);
});

it('blocks learners below the tier from lesson data', function () {
    $pro = publishedCourse(2, 2);
    $this->actingAs($this->user)->getJson(api('learn/lessons/'.$pro->sections[0]->lessons[1]->uuid))
        ->assertStatus(402)->assertJsonPath('code', 'membership_required');
});
