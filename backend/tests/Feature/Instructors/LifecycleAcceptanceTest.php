<?php

use App\Enums\MembershipTier;
use App\Models\Category;
use App\Models\Course;
use App\Models\InstructorApplication;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * ACCEPTANCE: an instructor can run their whole course lifecycle without admin help except publish approval.
 * The only admin calls below are approving the instructor application and approving the course for publishing.
 */
it('lets an instructor run the whole course lifecycle with admin approval only for publishing', function () {
    Notification::fake();
    Storage::fake('public');
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $asha = User::factory()->student()->create(['name' => 'Asha Teacher']);

    // Becoming an instructor (admin approves the application — onboarding, not part of the course lifecycle).
    $this->actingAs($asha)->postJson(api('me/instructor-application'), [
        'headline' => 'Freelance designer teaching Canva', 'expertise' => ['Design'],
        'bio' => str_repeat('Designer for ten years. ', 4), 'motivation' => str_repeat('Help beginners earn from design. ', 3),
    ])->assertCreated();
    $this->actingAs($admin)->postJson(api('admin/instructor-applications/'.InstructorApplication::first()->uuid.'/approve'))->assertOk();
    $asha->refresh();

    // Profile + payout details, all self-serve.
    $this->actingAs($asha)->putJson(api('instructor/profile'), ['bio' => 'Designer.', 'payout_method' => 'upi', 'payout_details' => ['upi_id' => 'asha@okhdfc']])
        ->assertJsonPath('data.payout.complete', true);

    // 1. Build the course: draft, section, text + quiz + assignment lessons, quiz questions, assignment settings.
    $courseId = $this->actingAs($asha)->postJson(api('instructor/courses'), [
        'title' => 'Canva for Clients', 'category_id' => $category->uuid, 'level' => 'beginner', 'required_tier' => 1,
        'description' => '<p>Design that sells.</p>',
    ])->assertCreated()->json('data.id');
    $this->actingAs($asha)->post(api("instructor/courses/{$courseId}/thumbnail"), ['thumbnail' => UploadedFile::fake()->image('cover.jpg', 1280, 720)], ['Accept' => 'application/json'])->assertOk();
    $sectionId = $this->actingAs($asha)->postJson(api("instructor/courses/{$courseId}/sections"), ['title' => 'Start here'])->json('data.sections.0.id');
    foreach ([['Welcome', 'text'], ['Check-in quiz', 'quiz'], ['First design', 'assignment']] as [$title, $type]) {
        $this->actingAs($asha)->postJson(api("instructor/sections/{$sectionId}/lessons"), ['title' => $title, 'type' => $type, 'content' => '<p>Read me</p>'])->assertOk();
    }
    $lessons = Course::where('uuid', $courseId)->firstOrFail()->lessons()->orderBy('sort')->get()->keyBy('title');
    $this->actingAs($asha)->postJson(api("instructor/courses/{$courseId}/submit"))->assertUnprocessable(); // quiz/assignment not set up yet

    $this->actingAs($asha)->putJson(api("instructor/lessons/{$lessons['Check-in quiz']->uuid}/quiz"), [
        'pass_mark' => 50, 'questions' => [['type' => 'true_false', 'prompt' => 'Canva is free to start', 'points' => 1,
            'options' => [['text' => 'True', 'is_correct' => true], ['text' => 'False', 'is_correct' => false]]]],
    ])->assertOk();
    $this->actingAs($asha)->putJson(api("instructor/lessons/{$lessons['First design']->uuid}/assignment"), [
        'instructions' => '<p>Make a poster</p>', 'max_score' => 10, 'pass_score' => 6, 'file_types' => ['png', 'pdf'], 'max_file_mb' => 5, 'allow_text' => true,
    ])->assertOk();

    // 2. Publish approval — the one admin step.
    $this->actingAs($asha)->postJson(api("instructor/courses/{$courseId}/submit"))->assertOk()->assertJsonPath('data.status', 'review');
    $this->actingAs($admin)->postJson(api("admin/courses/{$courseId}/approve"))->assertOk();
    $course = Course::where('uuid', $courseId)->firstOrFail();

    // 3. Learners arrive.
    $learner = User::factory()->student()->create(['name' => 'Ravi Learner']);
    subscribe($learner, MembershipTier::Starter);
    $this->actingAs($learner)->putJson(api("learn/lessons/{$lessons['Welcome']->uuid}/progress"), ['completed' => true])->assertOk();
    $qid = $this->actingAs($learner)->postJson(api("learn/lessons/{$lessons['Welcome']->uuid}/questions"), ['title' => 'Which template size?', 'body' => 'Instagram post or story?'])->json('data.id');
    $this->actingAs($learner)->postJson(api("learn/lessons/{$lessons['First design']->uuid}/assignment/submission"), ['text' => 'https://canva.com/my-poster'])->assertCreated();

    // 4. Instructor runs the course: students, Q&A, grading, announcement, edit after publishing, live session, analytics, earnings.
    $this->actingAs($asha)->getJson(api("instructor/courses/{$courseId}/students"))->assertJsonPath('data.0.name', 'Ravi Learner');
    $this->actingAs($asha)->postJson(api("questions/{$qid}/answers"), ['body' => 'Start with a 1080×1080 post.'])->assertCreated();
    $sub = $this->actingAs($asha)->getJson(api('instructor/submissions'))->assertJsonCount(1, 'data')->json('data.0.id');
    $this->actingAs($asha)->patchJson(api("instructor/submissions/{$sub}"), ['status' => 'graded', 'score' => 8, 'feedback' => 'Nice contrast'])->assertOk();
    $this->actingAs($asha)->postJson(api("instructor/courses/{$courseId}/announcements"), ['title' => 'New lesson soon', 'body' => '<p>Stay tuned</p>'])
        ->assertCreated()->assertJsonPath('data.recipients_count', 1);
    $this->actingAs($asha)->patchJson(api("instructor/courses/{$courseId}"), ['subtitle' => 'Now with templates'])->assertOk();
    $this->actingAs($asha)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link', 'join_url' => 'https://meet.google.com/abc', 'course_id' => $courseId, 'required_tier' => null]))->assertCreated();
    expect(LiveSession::where('course_id', $course->id)->value('required_tier'))->toBe(1);

    $kpis = $this->actingAs($asha)->getJson(api('instructor/analytics'))->assertOk()->json('data.kpis');
    expect($kpis)->students->toBe(1)->open_questions->toBe(0)->pending_submissions->toBe(0);
    $this->actingAs($asha)->getJson(api('instructor/earnings'))->assertOk()->assertJsonPath('data.revenue_share_percent', 70);
});

it('scopes every instructor endpoint to the instructor\'s own content', function () {
    $owner = User::factory()->instructor()->create();
    $other = User::factory()->instructor()->create();
    $course = courseWith(['text', 'quiz', 'assignment']);
    $course->update(['instructor_id' => $owner->id]);
    [$text, $quiz, $assignment] = $course->sections[0]->lessons->all();
    $session = LiveSession::factory()->create(['instructor_id' => $owner->id]);

    $this->actingAs($other);
    $this->getJson(api("instructor/courses/{$course->uuid}"))->assertForbidden();
    $this->patchJson(api("instructor/courses/{$course->uuid}"), ['title' => 'Hijack'])->assertForbidden();
    $this->putJson(api("instructor/courses/{$course->uuid}/curriculum"), ['sections' => []])->assertForbidden();
    $this->postJson(api("instructor/courses/{$course->uuid}/sections"), ['title' => 'x'])->assertForbidden();
    $this->patchJson(api("instructor/lessons/{$text->uuid}"), ['title' => 'x'])->assertForbidden();
    $this->getJson(api("instructor/lessons/{$quiz->uuid}/quiz"))->assertForbidden();
    $this->putJson(api("instructor/lessons/{$assignment->uuid}/assignment"), ['max_score' => 1])->assertForbidden();
    $this->getJson(api("instructor/courses/{$course->uuid}/students"))->assertForbidden();
    $this->getJson(api("instructor/courses/{$course->uuid}/announcements"))->assertForbidden();
    $this->postJson(api("instructor/courses/{$course->uuid}/announcements"), ['title' => 'x', 'body' => 'y'])->assertForbidden();
    $this->patchJson(api("instructor/live-sessions/{$session->uuid}"), ['title' => 'x'])->assertForbidden();
    $this->postJson(api("instructor/live-sessions/{$session->uuid}/cancel"))->assertForbidden();
    $this->getJson(api("instructor/live-sessions/{$session->uuid}/registrations"))->assertForbidden();
    $this->getJson(api('instructor/courses'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(api('instructor/live-sessions'))->assertJsonCount(0, 'data');
    $this->getJson(api('instructor/submissions'))->assertJsonCount(0, 'data');
    $this->getJson(api('instructor/analytics'))->assertJsonPath('data.kpis.students', 0);

    // Learners can't reach the instructor area at all.
    $this->actingAs(User::factory()->student()->create())->getJson(api('instructor/analytics'))->assertForbidden();
});
