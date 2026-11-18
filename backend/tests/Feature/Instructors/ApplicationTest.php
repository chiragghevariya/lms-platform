<?php

use App\Models\InstructorApplication;
use App\Models\User;
use App\Notifications\Instructors\InstructorApplicationDecidedNotification;
use App\Notifications\Instructors\InstructorApplicationReceivedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->learner = User::factory()->student()->create();
    $this->body = [
        'headline' => 'Performance marketer teaching Meta Ads',
        'expertise' => ['Meta Ads', 'Copywriting'],
        'bio' => str_repeat('I have run ad campaigns for D2C brands for six years. ', 2),
        'motivation' => str_repeat('I want to help freelancers land their first paid ad clients. ', 2),
        'links' => ['https://youtube.com/@asha'],
    ];
});

it('lets a learner apply and an admin approve, granting the instructor role and profile', function () {
    $this->actingAs($this->learner)->postJson(api('me/instructor-application'), $this->body)->assertCreated()->assertJsonPath('data.status', 'pending');
    Notification::assertSentTo($this->admin, InstructorApplicationReceivedNotification::class);
    $this->actingAs($this->learner)->postJson(api('me/instructor-application'), $this->body)->assertJsonValidationErrors('application');
    $this->actingAs($this->learner)->getJson(api('instructor/courses'))->assertForbidden();

    $app = InstructorApplication::firstOrFail();
    $this->actingAs($this->admin)->getJson(api('admin/instructor-applications'))->assertJsonPath('data.0.applicant.email', $this->learner->email);
    $this->actingAs($this->admin)->postJson(api("admin/instructor-applications/{$app->uuid}/approve"))->assertJsonPath('data.status', 'approved');

    $this->learner->refresh();
    expect($this->learner->hasRole('instructor'))->toBeTrue()
        ->and($this->learner->profile->headline)->toBe('Performance marketer teaching Meta Ads')
        ->and($this->learner->instructorProfile->approved_at)->not->toBeNull();
    Notification::assertSentTo($this->learner, InstructorApplicationDecidedNotification::class);
    $this->actingAs($this->learner)->getJson(api('instructor/courses'))->assertOk();
    $this->actingAs($this->learner)->getJson(api('me/instructor-application'))->assertJsonPath('data.is_instructor', true);
    $this->actingAs($this->admin)->postJson(api("admin/instructor-applications/{$app->uuid}/approve"))->assertJsonValidationErrors('application');
});

it('rejects with notes and allows a new application', function () {
    $this->actingAs($this->learner)->postJson(api('me/instructor-application'), $this->body);
    $app = InstructorApplication::firstOrFail();
    $this->actingAs($this->admin)->postJson(api("admin/instructor-applications/{$app->uuid}/reject"), ['notes' => 'x'])->assertJsonValidationErrors('notes');
    $this->actingAs($this->admin)->postJson(api("admin/instructor-applications/{$app->uuid}/reject"), ['notes' => 'Please add a sample lesson video.'])
        ->assertJsonPath('data.status', 'rejected');

    $this->actingAs($this->learner)->getJson(api('me/instructor-application'))->assertJsonPath('data.application.review_notes', 'Please add a sample lesson video.');
    $this->actingAs($this->learner)->postJson(api('me/instructor-application'), $this->body)->assertCreated();
    expect($this->learner->fresh()->hasRole('instructor'))->toBeFalse();
});

it('validates the application and blocks non-admins from reviewing', function () {
    $this->actingAs($this->learner)->postJson(api('me/instructor-application'), ['headline' => 'x', 'links' => ['http://insecure.example']])
        ->assertJsonValidationErrors(['headline', 'expertise', 'bio', 'motivation', 'links.0']);
    $this->actingAs($this->learner)->getJson(api('admin/instructor-applications'))->assertForbidden();
    $this->actingAs(User::factory()->instructor()->create())->postJson(api('me/instructor-application'), $this->body)->assertJsonValidationErrors('application');
});
