<?php

use App\Models\Course;
use App\Models\User;
use App\Notifications\Courses\CourseReviewedNotification;
use App\Notifications\Courses\CourseSubmittedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->instructor()->create();
    $this->course = Course::factory()->for($this->owner, 'instructor')->withLessons(2)->create();
    $this->course->sections()->create(['title' => 'Second', 'sort' => 1]);
});

it('lets students not use the studio and instructors only edit their own courses', function () {
    $this->actingAs(User::factory()->student()->create())->getJson(api('instructor/courses'))->assertForbidden();
    $other = User::factory()->instructor()->create();
    $this->actingAs($other)->patchJson(api("instructor/courses/{$this->course->uuid}"), ['title' => 'Hijack attempt'])->assertForbidden();

    $this->course->coInstructors()->attach($other);
    $this->actingAs($other)->patchJson(api("instructor/courses/{$this->course->uuid}"), ['title' => 'Co-taught course'])->assertOk();
});

it('reorders sections and moves lessons across sections', function () {
    $c = $this->actingAs($this->owner)->getJson(api("instructor/courses/{$this->course->uuid}"))->json('data');
    [$s1, $s2] = [$c['sections'][0], $c['sections'][1]];
    [$l1, $l2] = array_column($s1['lessons'], 'id');

    $this->actingAs($this->owner)->putJson(api("instructor/courses/{$this->course->uuid}/curriculum"), ['sections' => [
        ['id' => $s2['id'], 'lessons' => [$l2]],
        ['id' => $s1['id'], 'lessons' => [$l1]],
    ]])->assertOk()
        ->assertJsonPath('data.sections.0.id', $s2['id'])
        ->assertJsonPath('data.sections.0.lessons.0.id', $l2)
        ->assertJsonPath('data.sections.1.lessons.0.id', $l1);
});

it('rejects layouts that drop or duplicate items', function () {
    $c = $this->actingAs($this->owner)->getJson(api("instructor/courses/{$this->course->uuid}"))->json('data');
    $l1 = $c['sections'][0]['lessons'][0]['id'];

    $this->actingAs($this->owner)->putJson(api("instructor/courses/{$this->course->uuid}/curriculum"), ['sections' => [
        ['id' => $c['sections'][0]['id'], 'lessons' => [$l1, $l1]],
        ['id' => $c['sections'][1]['id'], 'lessons' => []],
    ]])->assertJsonValidationErrors('sections');
});

it('sanitizes lesson rich text', function () {
    $section = $this->course->sections()->first();
    $this->actingAs($this->owner)->postJson(api("instructor/sections/{$section->uuid}/lessons"), [
        'title' => 'Read me', 'type' => 'text',
        'content' => '<h2>Hi</h2><p onclick="steal()">Body <a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)></p>',
    ])->assertOk();

    $html = $this->course->lessons()->where('title', 'Read me')->value('content');
    expect($html)->toContain('<h2>Hi</h2>')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('<img');
});

it('lists readiness problems and blocks submission until fixed', function () {
    $this->course->update(['thumbnail_path' => null, 'description' => null]);

    $this->actingAs($this->owner)->postJson(api("instructor/courses/{$this->course->uuid}/submit"))
        ->assertJsonValidationErrors('course')
        ->assertJsonFragment(['Upload a thumbnail image.'])
        ->assertJsonFragment(['Add a course description.']);
});

it('runs submit → reject (with notes) → resubmit → approve, notifying both sides', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($this->owner)->postJson(api("instructor/courses/{$this->course->uuid}/submit"))->assertOk();
    Notification::assertSentTo($admin, CourseSubmittedNotification::class);

    $this->actingAs($admin)->postJson(api("admin/courses/{$this->course->uuid}/reject"), ['notes' => 'Please add captions to lesson 2.'])
        ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review_notes', 'Please add captions to lesson 2.');
    Notification::assertSentTo($this->owner, CourseReviewedNotification::class, fn ($n) => ! $n->approved);

    $this->actingAs($this->owner)->postJson(api("instructor/courses/{$this->course->uuid}/submit"))->assertOk();
    $this->actingAs($admin)->postJson(api("admin/courses/{$this->course->uuid}/approve"))->assertOk();
    Notification::assertSentTo($this->owner, CourseReviewedNotification::class, fn ($n) => $n->approved);

    $this->actingAs($this->owner)->postJson(api("admin/courses/{$this->course->uuid}/approve"))->assertForbidden();
});

it('lets admins feature and unpublish courses', function () {
    $admin = User::factory()->admin()->create();
    $course = publishedCourse();

    $this->actingAs($admin)->patchJson(api("admin/courses/{$course->uuid}"), ['is_featured' => true])->assertJsonPath('data.is_featured', true);
    $this->actingAs($admin)->patchJson(api("admin/courses/{$course->uuid}"), ['unpublish' => true])->assertJsonPath('data.status', 'draft');
    $this->actingAs(User::factory()->create())->getJson(api("courses/{$course->slug}"))->assertNotFound();
});
