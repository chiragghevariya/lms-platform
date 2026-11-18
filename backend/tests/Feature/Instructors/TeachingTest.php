<?php

use App\Enums\MembershipTier;
use App\Models\CourseQuestion;
use App\Models\Enrollment;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\Instructors\CourseAnnouncementNotification;
use App\Notifications\Instructors\QuestionAnsweredNotification;
use App\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWith(['video', 'text'], 1);
    $this->course->update(['instructor_id' => $this->instructor->id]);
    [$this->video, $this->text] = $this->course->sections[0]->lessons->all();
    $this->learner = User::factory()->student()->create(['name' => 'Asha Learner']);
    subscribe($this->learner, MembershipTier::Starter);
});

it('records watch time for analytics and only paid-member membership time for the pool', function () {
    watch($this->learner, $this->video, 30);
    $row = DB::table('course_watch_daily')->where('course_id', $this->course->id)->first();
    expect($row)->seconds->toBe(30)->pool_seconds->toBe(30);

    // Complimentary member: analytics yes, pool no.
    $comp = User::factory()->student()->create();
    app(MembershipService::class)->grantComplimentary($comp, Plan::where('tier_level', 1)->firstOrFail(), now()->addMonth(), $this->instructor);
    watch($comp, $this->video, 15);
    expect(DB::table('course_watch_daily')->where('user_id', $comp->id)->first())->seconds->toBe(15)->pool_seconds->toBe(0);

    // The instructor's own viewing never counts.
    watch($this->instructor, $this->video, 15);
    expect(DB::table('course_watch_daily')->where('user_id', $this->instructor->id)->exists())->toBeFalse();
});

it('caps pool seconds per member per day', function () {
    config(['earnings.max_counted_seconds_per_user_day' => 20]);
    watch($this->learner, $this->video, 45);
    expect(DB::table('course_watch_daily')->first())->seconds->toBe(45)->pool_seconds->toBe(20);
});

it('runs lesson Q&A: learners ask, instructor answers from the inbox, asker is notified', function () {
    $qid = $this->actingAs($this->learner)->postJson(api("learn/lessons/{$this->text->uuid}/questions"), [
        'title' => 'How do I price my first gig?', 'body' => 'Should I charge hourly or per project?',
    ])->assertCreated()->json('data.id');
    $this->actingAs(User::factory()->create())->postJson(api("learn/lessons/{$this->text->uuid}/questions"), ['title' => 'Hello there', 'body' => 'No access here'])->assertNotFound();

    $this->actingAs($this->instructor)->getJson(api('instructor/questions'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'How do I price my first gig?');
    $this->actingAs(User::factory()->instructor()->create())->getJson(api('instructor/questions'))->assertJsonCount(0, 'data');

    $this->actingAs($this->instructor)->postJson(api("questions/{$qid}/answers"), ['body' => 'Per project, with a clear scope.'])
        ->assertCreated()->assertJsonPath('data.instructor_answered', true)->assertJsonPath('data.answers.0.is_instructor', true);
    Notification::assertSentTo($this->learner, QuestionAnsweredNotification::class);
    $this->actingAs($this->instructor)->getJson(api('instructor/questions'))->assertJsonCount(0, 'data');
    $this->actingAs($this->learner)->getJson(api("learn/lessons/{$this->text->uuid}/questions"))->assertJsonPath('data.0.answers_count', 1);

    // Moderation: removing the only instructor answer reopens the question.
    $answerId = CourseQuestion::firstOrFail()->answers()->first()->uuid;
    $this->actingAs($this->learner)->deleteJson(api("answers/{$answerId}"))->assertNotFound();
    $this->actingAs($this->instructor)->deleteJson(api("answers/{$answerId}"))->assertNoContent();
    $this->actingAs($this->instructor)->getJson(api('instructor/questions'))->assertJsonCount(1, 'data');
});

it('sends announcements to enrolled students only', function () {
    Enrollment::create(['user_id' => $this->learner->id, 'course_id' => $this->course->id, 'source' => 'membership', 'started_at' => now()]);
    $outsider = User::factory()->create();

    $this->actingAs($this->instructor)->postJson(api("instructor/courses/{$this->course->uuid}/announcements"), [
        'title' => 'Live Q&A this Friday', 'body' => '<p>Join us <script>alert(1)</script><strong>Friday 7pm</strong></p>',
    ])->assertCreated()->assertJsonPath('data.recipients_count', 1)->assertJsonPath('data.body', '<p>Join us <strong>Friday 7pm</strong></p>');

    Notification::assertSentTo($this->learner, CourseAnnouncementNotification::class);
    Notification::assertNotSentTo($outsider, CourseAnnouncementNotification::class);
    $this->actingAs($this->learner)->getJson(api("courses/{$this->course->slug}/announcements"))->assertJsonPath('data.0.title', 'Live Q&A this Friday');
    $this->actingAs($outsider)->getJson(api("courses/{$this->course->slug}/announcements"))->assertNotFound();
    $this->actingAs(User::factory()->instructor()->create())->postJson(api("instructor/courses/{$this->course->uuid}/announcements"), ['title' => 'x', 'body' => 'y'])->assertForbidden();
});

it('lists students with progress and builds analytics + activity', function () {
    $this->actingAs($this->learner)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true]);
    watch($this->learner, $this->video, 60);
    $this->actingAs($this->learner)->postJson(api("courses/{$this->course->slug}/reviews"), ['rating' => 4, 'comment' => 'Great']);

    $this->actingAs($this->instructor)->getJson(api("instructor/courses/{$this->course->uuid}/students"))
        ->assertJsonPath('data.0.name', 'Asha Learner')->assertJsonPath('data.0.progress_percent', 100)
        ->assertJsonPath('data.0.completed_at', fn ($v) => $v !== null);
    $this->actingAs(User::factory()->instructor()->create())->getJson(api("instructor/courses/{$this->course->uuid}/students"))->assertForbidden();

    $a = $this->actingAs($this->instructor)->getJson(api('instructor/analytics?days=30'))->assertOk()->json('data');
    expect($a['kpis'])->students->toBe(1)->new_enrollments->toBe(1)->completion_rate->toBe(100)->watch_minutes->toBe(1)
        ->rating_avg->toBe(4)->rating_count->toBe(1)
        ->and($a['series']['daily'])->toHaveCount(30)
        ->and(end($a['series']['daily'])['enrollments'])->toBe(1)
        ->and($a['courses'][0]['completion_rate'])->toBe(100)
        ->and($a['series']['earnings'])->not->toBeEmpty();

    $types = array_column($this->actingAs($this->instructor)->getJson(api('instructor/activity'))->json('data'), 'type');
    expect($types)->toContain('enrollment')->toContain('review');
});
