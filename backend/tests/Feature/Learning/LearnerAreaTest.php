<?php

use App\Enums\MembershipTier;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->student()->create();
    subscribe($this->user, MembershipTier::Starter);
    $this->course = courseWith(['text', 'text']);
    [$this->l1, $this->l2] = $this->course->sections[0]->lessons->all();
});

it('builds the dashboard: continue learning, stats, recommendations within tier', function () {
    $starter = courseWith(['text']);
    $pro = courseWith(['text'], 2);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->l1->uuid}/progress"), ['completed' => true]);

    $d = $this->actingAs($this->user)->getJson(api('me/dashboard'))->assertOk()->json('data');
    expect($d['continue_learning'][0])->percent->toBe(50)->next_lesson_id->toBe($this->l1->uuid)
        ->and($d['stats'])->points->toBe(10)->courses_completed->toBe(0)
        ->and($d['stats']['streak']['current'])->toBe(1)
        ->and($d['upcoming_live_classes'])->toBe([])
        ->and(array_column(array_column($d['recommended'], null), 'slug'))->toContain($starter->slug)->not->toContain($pro->slug)->not->toContain($this->course->slug)
        ->and($d['recent_badges'][0]['key'])->toBe('first_lesson');
});

it('lists my learning with in-progress and completed courses', function () {
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->l1->uuid}/progress"), ['completed' => true]);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->l2->uuid}/progress"), ['completed' => true]);

    $this->actingAs($this->user)->getJson(api('learn/enrollments'))
        ->assertJsonPath('data.0.progress_percent', 100)
        ->assertJson(fn ($j) => $j->where('data.0.completed_at', fn ($v) => $v !== null)->etc());
});

it('bookmarks courses and lessons idempotently, with toggle state in payloads', function () {
    $this->actingAs($this->user)->postJson(api('me/bookmarks'), ['course_id' => $this->course->uuid])->assertCreated();
    $this->actingAs($this->user)->postJson(api('me/bookmarks'), ['course_id' => $this->course->uuid])->assertOk();
    $id = $this->actingAs($this->user)->postJson(api('me/bookmarks'), ['lesson_id' => $this->l2->uuid, 'position_seconds' => 12, 'label' => 'Revisit'])->json('data.id');

    $this->actingAs($this->user)->getJson(api('me/bookmarks'))->assertJsonCount(2, 'data');
    $this->actingAs($this->user)->getJson(api("courses/{$this->course->slug}"))->assertJsonPath('data.access.bookmarked', true);
    $this->actingAs($this->user)->getJson(api("learn/lessons/{$this->l2->uuid}"))->assertJsonPath('data.bookmarked', true);

    $this->actingAs(User::factory()->create())->deleteJson(api("me/bookmarks/{$id}"))->assertNotFound();
    $this->actingAs($this->user)->deleteJson(api("me/bookmarks/{$id}"))->assertNoContent();
    $this->actingAs($this->user)->deleteJson(api('me/bookmarks'), ['course_id' => $this->course->uuid])->assertNoContent();
    $this->actingAs($this->user)->getJson(api('me/bookmarks'))->assertJsonCount(0, 'data');
});

it('cannot bookmark a lesson without access', function () {
    $pro = courseWith(['text'], 2);
    $this->actingAs($this->user)->postJson(api('me/bookmarks'), ['lesson_id' => $pro->sections[0]->lessons[0]->uuid])->assertStatus(402);
});

it('lists all my notes across courses with search', function () {
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->l1->uuid}/notes"), ['body' => 'Flexbox gotcha', 'position_seconds' => 3]);
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->l2->uuid}/notes"), ['body' => 'Grid areas']);
    $this->actingAs(User::factory()->create())->getJson(api('me/notes'))->assertJsonCount(0, 'data');

    $this->actingAs($this->user)->getJson(api('me/notes'))->assertJsonCount(2, 'data');
    $this->actingAs($this->user)->getJson(api('me/notes?q=flex'))->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lesson.id', $this->l1->uuid)->assertJsonPath('data.0.course.slug', $this->course->slug);
});

it('manages the learning profile and exposes a public page only when opted in', function () {
    $this->actingAs($this->user)->patchJson(api('me/learning-profile'), ['is_public' => true])->assertJsonValidationErrors('public_handle');
    $this->actingAs($this->user)->patchJson(api('me/learning-profile'), ['public_handle' => 'Bad Handle!'])->assertJsonValidationErrors('public_handle');

    $this->actingAs($this->user)->patchJson(api('me/learning-profile'), [
        'skills' => ['React', 'SEO'], 'learning_goals' => 'Land a freelance client', 'public_handle' => 'asha', 'is_public' => false,
    ])->assertOk()->assertJsonPath('data.skills', ['React', 'SEO']);
    $this->getJson(api('learners/asha'))->assertNotFound();

    $this->actingAs($this->user)->patchJson(api('me/learning-profile'), ['is_public' => true])->assertOk();
    $this->getJson(api('learners/asha'))->assertOk()->assertJsonPath('data.name', $this->user->name)
        ->assertJsonPath('data.skills', ['React', 'SEO'])->assertJsonMissingPath('data.email');

    $other = User::factory()->create();
    $this->actingAs($other)->patchJson(api('me/learning-profile'), ['public_handle' => 'asha'])->assertJsonValidationErrors('public_handle');
});

it('requires quiz questions and assignment settings before review', function () {
    $instructor = User::factory()->instructor()->create();
    $course = courseWith(['text', 'quiz', 'assignment']);
    $course->forceFill(['instructor_id' => $instructor->id, 'status' => 'draft', 'published_at' => null])->save();

    $res = $this->actingAs($instructor)->postJson(api("instructor/courses/{$course->uuid}/submit"))->assertUnprocessable();
    expect(json_encode($res->json()))->toContain('Quiz')->toContain('Assignment');
});
