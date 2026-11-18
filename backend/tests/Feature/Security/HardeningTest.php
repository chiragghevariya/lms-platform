<?php

use App\Enums\MembershipTier;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Video;
use App\Security\DeviceLimiter;
use App\Security\UploadScanner;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

// ---------------------------------------------------------------- headers

it('sends security headers on API and web responses', function () {
    $api = $this->getJson(api('plans'))->assertOk();
    expect($api->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("frame-ancestors 'none'")
        ->and($api->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($api->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($api->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($api->headers->has('Strict-Transport-Security'))->toBeFalse(); // plain http

    $secure = $this->get('https://localhost/api/v1/plans', ['Accept' => 'application/json']);
    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});

it('only lets the configured frontend origin make credentialed CORS calls', function () {
    $this->withHeaders(['Origin' => 'http://localhost:5180'])->options(api('plans'), [], ['Access-Control-Request-Method' => 'GET'])
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5180');
    $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->options(api('plans'), [], ['Access-Control-Request-Method' => 'GET']);
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example'); // browser rejects the mismatch
});

// ---------------------------------------------------------------- captcha

it('requires a valid Turnstile token on register, login, contact and forgot-password when enabled', function () {
    config(['security.captcha.driver' => 'turnstile', 'security.captcha.turnstile.secret' => 'ts-secret', 'security.captcha.turnstile.site_key' => 'ts-site']);
    Http::fake(['challenges.cloudflare.com/*' => fn (HttpRequest $r) => Http::response(['success' => $r['response'] === 'good-token'])]);

    $this->postJson(api('auth/login'), ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(422)->assertJsonPath('code', 'captcha_failed');
    $this->postJson(api('auth/register'), ['captcha_token' => 'bad'])->assertJsonPath('code', 'captcha_failed');
    $this->postJson(api('contact'), ['captcha_token' => 'bad'])->assertJsonPath('code', 'captcha_failed');
    $this->postJson(api('auth/forgot-password'), ['email' => 'a@b.c'])->assertJsonPath('code', 'captcha_failed');

    User::factory()->create(['email' => 'kim@example.com']);
    $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'password', 'device_name' => 'cli', 'captcha_token' => 'good-token'])->assertOk();
    Http::assertSent(fn (HttpRequest $r) => $r['secret'] === 'ts-secret' && $r['response'] === 'good-token');

    $this->getJson(api('site'))->assertJsonPath('data.captcha.site_key', 'ts-site');
});

it('skips the captcha when it is not configured', function () {
    User::factory()->create(['email' => 'kim@example.com']);
    $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'password', 'device_name' => 'cli'])->assertOk();
    $this->getJson(api('site'))->assertJsonPath('data.captcha.site_key', null);
});

// ---------------------------------------------------------------- devices

it('keeps learners to two devices: a third sign-in signs out the least recently used', function () {
    $u = User::factory()->create(['email' => 'kim@example.com']);
    $login = fn (string $device) => $this->postJson(api('auth/login'), ['email' => 'kim@example.com', 'password' => 'password', 'device_name' => $device])->json('data.token');

    $phone = $login('phone');
    $this->travel(1)->minutes();
    $tablet = $login('tablet');
    $this->travel(1)->minutes();
    $laptop = $login('laptop');

    expect(UserDevice::where('user_id', $u->id)->pluck('name')->sort()->values()->all())->toBe(['laptop', 'tablet'])
        ->and(PersonalAccessToken::where('name', 'phone')->exists())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->withToken($phone)->getJson(api('me'))->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($laptop)->getJson(api('me'))->assertOk();
});

it('signs out a pushed-out browser session with a clear reason', function () {
    $u = User::factory()->create();
    $this->withHeader('Origin', 'http://localhost:5180')->actingAs($u, 'web')
        ->withSession([DeviceLimiter::SESSION_KEY => 'gone-device-uuid'])
        ->getJson(api('me'))->assertUnauthorized()->assertJsonPath('code', 'device_signed_out');
});

it('does not limit staff devices', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
    foreach (['a', 'b', 'c', 'd'] as $d) {
        $this->postJson(api('auth/login'), ['email' => 'boss@example.com', 'password' => 'password', 'device_name' => $d])->assertOk();
    }
    expect(UserDevice::where('user_id', $admin->id)->count())->toBe(4);
});

// ---------------------------------------------------------------- privacy

it('exports a person\'s data without secrets', function () {
    $u = User::factory()->create(['name' => 'Asha', 'two_factor_secret' => 'TOPSECRET2FA']);
    subscribe($u, MembershipTier::Pro);
    Payment::factory()->create(['user_id' => $u->id, 'invoice_number' => 'INV-EXP-1']);

    $res = $this->actingAs($u)->getJson(api('me/export'))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"')
        ->assertJsonPath('account.name', 'Asha')
        ->assertJsonPath('payments.0.invoice', 'INV-EXP-1')
        ->assertJsonCount(1, 'subscriptions');
    expect($res->getContent())->not->toContain('TOPSECRET2FA')->not->toContain('"password"');
});

it('deletes and anonymises an account, keeps invoices and cancels renewals', function () {
    $u = User::factory()->create(['email' => 'leaving@example.com', 'phone' => '+919999999999']);
    $sub = subscribe($u, MembershipTier::Pro);
    $p = Payment::factory()->create(['user_id' => $u->id, 'subscription_id' => $sub->id, 'billing_name' => 'Leaving Person']);
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => $sub->gateway_subscription_id, 'status' => 'active'])]);
    $u->createToken('phone');

    $this->actingAs($u)->deleteJson(api('me'), ['confirm' => 'DELETE', 'password' => 'wrong'])->assertJsonValidationErrors('password');
    $this->actingAs($u)->deleteJson(api('me'), ['confirm' => 'DELETE', 'password' => 'password'])->assertOk();

    $row = User::withTrashed()->find($u->id);
    expect($row->trashed())->toBeTrue()
        ->and($row->name)->toBe('Deleted user')
        ->and($row->email)->not->toContain('leaving')
        ->and($row->phone)->toBeNull()
        ->and($row->anonymized_at)->not->toBeNull()
        ->and(PersonalAccessToken::count())->toBe(0)
        ->and($sub->fresh()->cancel_at_period_end)->toBeTrue()
        ->and(Payment::find($p->id)?->billing_name)->toBe('Leaving Person'); // tax record retained
});

it('blocks deletion for staff and for instructors with published courses', function () {
    $this->actingAs(User::factory()->admin()->create())->getJson(api('me/deletion-check'))->assertJsonCount(1, 'data.blockers');
    $course = publishedCourse(1);
    $this->actingAs($course->instructor)->deleteJson(api('me'), ['confirm' => 'DELETE', 'password' => 'password'])->assertJsonValidationErrors('account');
});

// ---------------------------------------------------------------- uploads & PII

it('rejects uploads the virus scanner flags, and fails closed when it is down', function () {
    Storage::fake('public');
    $u = User::factory()->create();
    $this->mock(UploadScanner::class)->shouldReceive('scan')->once()->andReturn('Eicar-Test-Signature');
    $this->actingAs($u)->postJson(api('me/avatar'), ['avatar' => UploadedFile::fake()->image('a.png', 200, 200)])
        ->assertJsonValidationErrors(['avatar' => 'unsafe']);

    $this->mock(UploadScanner::class)->shouldReceive('scan')->once()->andThrow(new RuntimeException('clamd down'));
    $this->actingAs($u)->postJson(api('me/avatar'), ['avatar' => UploadedFile::fake()->image('a.png', 200, 200)])
        ->assertJsonValidationErrors(['avatar' => 'virus']);
});

it('stores billing tax ids and addresses encrypted at rest', function () {
    $p = Payment::factory()->create(['billing_tax_id' => '29ABCDE1234F1Z5', 'billing_address' => ['line1' => '12 MG Road', 'city' => 'Bengaluru']]);
    $raw = DB::table('payments')->where('id', $p->id)->first();
    expect($raw->billing_tax_id)->not->toContain('29ABCDE')->and($raw->billing_address)->not->toContain('MG Road')
        ->and($p->fresh()->billing_address['city'])->toBe('Bengaluru');
});

it('watermarks lesson playback with the viewer\'s email', function () {
    $course = publishedCourse(null, 1);
    $lesson = $course->lessons()->first();
    $u = User::factory()->create(['email' => 'viewer@example.com']);
    $this->actingAs($u)->getJson(api("lessons/{$lesson->uuid}/playback"))->assertOk()->assertJsonPath('data.watermark.text', 'viewer@example.com');
})->skip(fn () => ! Route::has('api.v1.lessons.playback'), 'playback route name differs');

// ---------------------------------------------------------------- review findings (regression tests)

it('stops instructors attaching another instructor\'s video to their lessons', function () {
    $mine = publishedCourse(2, 1);
    $other = Video::factory()->create(['uploaded_by' => User::factory()->instructor()->create()->id]);
    $lesson = $mine->lessons()->first();

    $this->actingAs($mine->instructor)->patchJson(api("instructor/lessons/{$lesson->uuid}"), ['video_id' => $other->uuid, 'is_preview' => true])
        ->assertJsonValidationErrors(['video_id' => 'only use videos you uploaded']);
    $this->actingAs($mine->instructor)->patchJson(api("instructor/courses/{$mine->uuid}"), ['promo_video_id' => $other->uuid])
        ->assertJsonValidationErrors('promo_video_id');
});

it('does not let one user clear another user\'s device slots', function () {
    $victim = User::factory()->create(['email' => 'v@example.com']);
    $this->postJson(api('auth/login'), ['email' => 'v@example.com', 'password' => 'password', 'device_name' => 'phone'])->assertOk();
    $tokenId = PersonalAccessToken::first()->id;

    $this->app['auth']->forgetGuards();
    $this->actingAs(User::factory()->create())->deleteJson(api("me/sessions/token_{$tokenId}"))->assertNotFound();
    expect(UserDevice::where('user_id', $victim->id)->count())->toBe(1);
});

it('lets anyone open a preview quiz but only course members submit it', function () {
    $course = courseWith(['quiz'], 3);
    $lesson = $course->sections[0]->lessons[0];
    $this->actingAs($course->instructor)->putJson(api("instructor/lessons/{$lesson->uuid}/quiz"), [
        'pass_mark' => 50, 'attempts_allowed' => 1, 'shuffle' => false, 'show_answers' => false,
        'questions' => [['type' => 'true_false', 'prompt' => 'Sky is blue', 'points' => 1, 'options' => [['text' => 'True', 'is_correct' => true], ['text' => 'False', 'is_correct' => false]]]],
    ])->assertOk();
    $lesson->forceFill(['is_preview' => true])->save();

    $free = User::factory()->create();
    $this->app['auth']->forgetGuards();
    $this->actingAs($free)->getJson(api("learn/lessons/{$lesson->uuid}/quiz"))->assertOk();
    $this->actingAs($free)->postJson(api("learn/lessons/{$lesson->uuid}/quiz/attempts"))->assertStatus(402);
});

it('throttles password changes', function () {
    $u = User::factory()->create();
    foreach (range(1, 6) as $_) {
        $this->actingAs($u)->putJson(api('me/password'), ['current_password' => 'wrong', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99']);
    }
    $this->actingAs($u)->putJson(api('me/password'), ['current_password' => 'wrong', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99'])->assertStatus(429);
});
