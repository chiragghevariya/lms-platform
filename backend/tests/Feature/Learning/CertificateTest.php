<?php

use App\Enums\MembershipTier;
use App\Learning\CertificateService;
use App\Models\Certificate;
use App\Models\User;
use App\Notifications\Learning\CertificateIssuedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->user = User::factory()->student()->create(['name' => 'Asha Learner']);
    subscribe($this->user, MembershipTier::Starter);
    $this->course = courseWith(['video', 'text']);
    [$this->video, $this->text] = $this->course->sections[0]->lessons->all();
});

it('issues a verifiable certificate when the course is completed', function () {
    watch($this->user, $this->video, 60);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true])->assertOk();

    $cert = Certificate::firstOrFail();
    expect($cert)->holder_name->toBe('Asha Learner')->course_title->toBe($this->course->title)
        ->verification_code->toMatch('/^[A-HJ-NP-Z2-9]{10}$/')
        ->pdf_path->not->toBeNull();
    Storage::disk('local')->assertExists($cert->pdf_path);
    Notification::assertSentTo($this->user, CertificateIssuedNotification::class);

    // Public verification (no auth) …
    $this->getJson(api("certificates/verify/{$cert->verification_code}"))
        ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.holder_name', 'Asha Learner')
        ->assertJsonPath('data.verify_url', config('lms.frontend_url').'/verify/'.$cert->verification_code)
        ->assertJsonMissingPath('data.linkedin_url');
    $this->getJson(api('certificates/verify/'.strtolower($cert->verification_code)))->assertJsonPath('data.valid', true);
    $this->getJson(api('certificates/verify/NOTREAL123'))->assertNotFound()->assertJsonPath('data.valid', false);

    // … and the owner's view with LinkedIn prefill + PDF.
    $mine = $this->actingAs($this->user)->getJson(api('me/certificates'))->assertJsonCount(1, 'data')->json('data.0');
    parse_str((string) parse_url($mine['linkedin_url'], PHP_URL_QUERY), $q);
    expect($mine['linkedin_url'])->toStartWith('https://www.linkedin.com/profile/add?')
        ->and($q)->toMatchArray(['startTask' => 'CERTIFICATION_NAME', 'name' => $this->course->title, 'certId' => $cert->verification_code]);

    $this->actingAs($this->user)->get(api("me/certificates/{$cert->uuid}/pdf"))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs(User::factory()->create())->get(api("me/certificates/{$cert->uuid}/pdf"))->assertNotFound();
});

it('issues only one certificate even if completion is recomputed', function () {
    watch($this->user, $this->video, 60);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true]);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => false]);
    $this->actingAs($this->user)->putJson(api("learn/lessons/{$this->text->uuid}/progress"), ['completed' => true]);

    expect(Certificate::count())->toBe(1);
    Notification::assertSentToTimes($this->user, CertificateIssuedNotification::class, 1);
    $this->actingAs($this->user)->getJson(api("courses/{$this->course->slug}"))->assertJsonPath('data.access.certificate_id', Certificate::first()->uuid);
});

it('does not issue a certificate for a partially completed course', function () {
    watch($this->user, $this->video, 60);
    expect(Certificate::count())->toBe(0);
});

it('marks revoked certificates invalid', function () {
    $cert = app(CertificateService::class)->issue($this->user, $this->course);
    $cert->forceFill(['revoked_at' => now()])->save();

    $this->getJson(api("certificates/verify/{$cert->verification_code}"))->assertJsonPath('data.valid', false);
});
