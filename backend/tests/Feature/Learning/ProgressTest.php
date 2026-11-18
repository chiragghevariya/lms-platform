<?php

use App\Enums\MembershipTier;
use App\Learning\GamificationService;
use App\Models\CourseProgress;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->student()->create(['timezone' => 'Asia/Kolkata']);
    subscribe($this->user, MembershipTier::Starter);
    $this->course = courseWith(['video', 'video', 'text']);
    [$this->v1, $this->v2, $this->text] = $this->course->sections[0]->lessons->all();
});

it('survives refresh and device switch: progress lives on the server', function () {
    $phone = $this->user->createToken('phone')->plainTextToken;
    $laptop = $this->user->createToken('laptop')->plainTextToken;

    watch($this->user, $this->v1, 30, $phone)->assertOk()->assertJsonPath('data.watched_seconds', 30);

    // Another device (and any refresh) reads the same resume point and watch time.
    $this->withToken($laptop)->getJson(api("learn/lessons/{$this->v1->uuid}"))
        ->assertJsonPath('data.progress.position_seconds', 30)
        ->assertJsonPath('data.progress.watched_seconds', 30)
        ->assertJsonPath('data.progress.heartbeat_seconds', 15);
    $this->withToken($laptop)->getJson(api('learn/enrollments'))->assertJsonPath('data.0.last_lesson_id', $this->v1->uuid);
});

it('completes a video at 90% watched via heartbeats and recalculates the course percent', function () {
    watch($this->user, $this->v1, 45)->assertJsonPath('data.completed', false);
    watch($this->user, $this->v1, 15)->assertJsonPath('data.completed', true); // 60s ≥ 54s

    expect(CourseProgress::where('user_id', $this->user->id)->first())->percent->toBe(33)->completed_lessons->toBe(1);
});

it('caps credited watch time by real elapsed time (no faking 90%)', function () {
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->v1->uuid}/heartbeat"), ['position_seconds' => 59, 'watched_seconds' => 121])
        ->assertJsonValidationErrors('watched_seconds');

    $this->travel(5)->seconds();
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->v1->uuid}/heartbeat"), ['position_seconds' => 59, 'watched_seconds' => 60])
        ->assertJsonPath('data.watched_seconds', 17); // first beat: assumed 15s window + 2s slack

    $this->travel(2)->seconds();
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->v1->uuid}/heartbeat"), ['position_seconds' => 59, 'watched_seconds' => 60])
        ->assertJsonPath('data.watched_seconds', 21)->assertJsonPath('data.completed', false); // only 2s (+2 slack) passed
});

it('credits faster playback proportionally', function () {
    $this->travel(15)->seconds();
    $this->actingAs($this->user)->postJson(api("learn/lessons/{$this->v1->uuid}/heartbeat"), ['position_seconds' => 30, 'watched_seconds' => 30, 'playback_rate' => 2])
        ->assertJsonPath('data.watched_seconds', 30);
});

it('lets learners mark text lessons complete but not videos', function () {
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->v1->uuid}/progress"), ['completed' => true])->assertJsonValidationErrors('completed');
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true])->assertJsonPath('data.completed', true);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => false])->assertJsonPath('data.completed', false);
});

it('awards points once per lesson and records the daily streak', function () {
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true]);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => false]);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true]);

    expect((int) DB::table('points_ledger')->where('user_id', $this->user->id)->sum('points'))->toBe(10);
    $this->actingAs($this->user)->getJson(api('me/achievements'))
        ->assertJsonPath('data.points', 10)
        ->assertJsonPath('data.streak.current', 1)
        ->assertJsonPath('data.streak.active_today', true);
});

it('counts consecutive days in the learner\'s timezone', function () {
    $base = now()->startOfDay();
    foreach ([0, 1, 2] as $d) {
        $this->travelTo($base->copy()->addDays($d)->addHours(10));
        $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['position_seconds' => $d]);
        app(GamificationService::class)->recordActivity($this->user);
    }

    $this->actingAs($this->user)->getJson(api('me/achievements'))
        ->assertJsonPath('data.streak.current', 3)
        ->assertJsonFragment(['key' => 'streak_3', 'earned' => true]);
});

it('rejects heartbeats for locked lessons', function () {
    $pro = courseWith(['video'], 2);
    $this->actingAs($this->user)->postJson(api('learn/lessons/'.$pro->sections[0]->lessons[0]->uuid.'/heartbeat'), ['position_seconds' => 1, 'watched_seconds' => 1])
        ->assertStatus(402);
    expect(LessonProgress::count())->toBe(0);
});
