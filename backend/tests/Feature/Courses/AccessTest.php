<?php

use App\Enums\MembershipTier;
use App\Models\CoursePurchase;
use App\Models\User;
use App\Models\Video;

beforeEach(function () {
    $this->course = publishedCourse(2, 3);
    [$this->preview, $this->second, $this->third] = $this->course->sections[0]->lessons->all();
});

it('lets anyone, including guests, play preview lessons', function () {
    $this->getJson(api("lessons/{$this->preview->uuid}/playback"))->assertOk();
    $this->getJson(api("lessons/{$this->second->uuid}/playback"))->assertUnauthorized()->assertJsonPath('code', 'login_required');
});

it('shows lock states on the course page for a guest', function () {
    $this->getJson(api("courses/{$this->course->slug}"))
        ->assertJsonPath('data.curriculum.0.lessons.0.unlocked', true)
        ->assertJsonPath('data.curriculum.0.lessons.1.unlocked', false)
        ->assertJsonPath('data.curriculum.0.lessons.1.lock_reason', 'login_required');
});

it('grants access by tier with no manual enrolment, and enrols on first watch', function () {
    $elite = User::factory()->create();
    subscribe($elite, MembershipTier::Elite);

    $this->actingAs($elite)->getJson(api("lessons/{$this->second->uuid}/playback"))->assertOk();

    expect($elite->fresh()->id)->toBeInt();
    $this->assertDatabaseHas('enrollments', ['user_id' => $elite->id, 'course_id' => $this->course->id, 'source' => 'tier']);
});

it('grants access to buyers of a single course', function () {
    $buyer = User::factory()->create();
    CoursePurchase::create(['user_id' => $buyer->id, 'course_id' => $this->course->id, 'purchased_at' => now()]);

    $this->actingAs($buyer)->getJson(api("lessons/{$this->second->uuid}/playback"))->assertOk();
    $this->actingAs($buyer)->getJson(api("courses/{$this->course->slug}"))->assertJsonPath('data.access.reason', 'purchase');
});

it('drips lessons by days since the learner started', function () {
    $this->third->update(['drip_days' => 7]);
    $pro = User::factory()->create();
    subscribe($pro, MembershipTier::Pro);

    $this->actingAs($pro)->getJson(api("lessons/{$this->second->uuid}/playback"))->assertOk(); // starts the clock
    $this->actingAs($pro)->getJson(api("lessons/{$this->third->uuid}/playback"))
        ->assertForbidden()->assertJsonPath('code', 'drip_locked');

    $this->travelTo(now()->addDays(7)->addMinute());
    $this->actingAs($pro)->getJson(api("lessons/{$this->third->uuid}/playback"))->assertOk();
});

it('lets the course instructor and staff bypass tier and drip', function () {
    $this->third->update(['drip_days' => 30]);

    $this->actingAs($this->course->instructor)->getJson(api("lessons/{$this->third->uuid}/playback"))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->getJson(api("lessons/{$this->third->uuid}/playback"))->assertOk();
});

it('refuses playback for unpublished courses and unprocessed videos', function () {
    $pro = User::factory()->create();
    subscribe($pro, MembershipTier::Pro);
    Video::whereKey($this->second->video_id)->update(['status' => 'processing']);

    $this->actingAs($pro)->getJson(api("lessons/{$this->second->uuid}/playback"))->assertStatus(409)->assertJsonPath('code', 'video_not_ready');

    $this->course->forceFill(['status' => 'draft'])->save();
    $this->actingAs($pro)->getJson(api("lessons/{$this->preview->uuid}/playback"))->assertNotFound();
});
