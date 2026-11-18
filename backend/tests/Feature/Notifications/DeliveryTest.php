<?php

use App\Enums\MembershipTier;
use App\Models\Certificate;
use App\Models\NotificationLog;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Learning\CertificateIssuedNotification;
use App\Notifications\Platform\RetryNotification;
use App\Notify\Channels\FcmChannel;
use App\Notify\Channels\WhatsAppChannel;
use App\Notify\NotificationRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    enableChannels();
    $this->user = reachableUser(['name' => 'Asha Learner']);
    $course = courseWith(['text']);
    $this->cert = Certificate::create(['user_id' => $this->user->id, 'course_id' => $course->id, 'verification_code' => 'ABCDE23456',
        'holder_name' => 'Asha Learner', 'course_title' => 'Meta Ads from Zero', 'instructor_name' => 'Meera', 'issued_at' => now()]);
});

function waStatus(string $id, string $status, array $extra = []): TestResponse
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'],
        'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '919876543210', ...$extra]],
    ]]]]]]);

    return test()->call('POST', api('webhooks/whatsapp'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa-secret'),
    ], $body);
}

/*
 * ACCEPTANCE: WhatsApp template sends (Cloud API "sandbox" = Meta test number; the HTTP call is faked here)
 * and logs show delivery status moving sent → delivered → read from webhooks.
 */
it('sends a WhatsApp template through the Cloud API and tracks delivery and read receipts', function () {
    Http::fake([
        'graph.facebook.com/v26.0/1098765432/messages' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '919876543210']], 'messages' => [['id' => 'wamid.HBgM123']]]),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fcm', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/lms-test/messages/0:123']),
    ]);
    Mail::fake();

    $this->user->notify(new CertificateIssuedNotification($this->cert));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r['messaging_product'] === 'whatsapp'
        && $r['to'] === '919876543210' && $r['type'] === 'template' && $r['template']['name'] === 'certificate_issued'
        && $r['template']['language']['code'] === 'en'
        && $r['template']['components'][0]['parameters'] === [
            ['type' => 'text', 'text' => 'Asha'], ['type' => 'text', 'text' => 'Meta Ads from Zero'], ['type' => 'text', 'text' => config('lms.frontend_url').'/app/certificates'],
        ]
        && $r->header('Authorization')[0] === 'Bearer wa-token');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/lms-test/messages:send')
        && $r['message']['token'] === 'fcm-token-'.$this->user->id && $r['message']['notification']['title'] === 'Certificate issued 🎉');

    $logs = NotificationLog::where('user_id', $this->user->id)->get()->keyBy('channel');
    expect($logs->keys()->sort()->values()->all())->toBe(['database', 'mail', 'push', 'whatsapp'])
        ->and($logs['whatsapp'])->status->toBe('sent')->provider->toBe('whatsapp')->provider_message_id->toBe('wamid.HBgM123')->recipient->toBe('+91••••••3210')
        ->and($logs['database']->status)->toBe('delivered')
        ->and($logs['push'])->status->toBe('sent')->provider_message_id->toBe('projects/lms-test/messages/0:123')
        ->and($logs['mail']->status)->toBe('sent');

    waStatus('wamid.HBgM123', 'delivered')->assertOk();
    waStatus('wamid.HBgM123', 'read')->assertOk();
    waStatus('wamid.HBgM123', 'delivered')->assertOk(); // late/out-of-order: never moves backwards
    expect($logs['whatsapp']->fresh())->status->toBe('read')->delivered_at->not->toBeNull()->read_at->not->toBeNull();

    // The in-app bell has it too.
    $this->actingAs($this->user)->getJson(api('me/notifications'))->assertJsonPath('data.0.title', 'Certificate issued 🎉')
        ->assertJsonPath('meta.unread', 1);
});

it('records failures from the provider and falls back to SMS for events that allow it', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001]], 400)]);
    Mail::fake();
    PushToken::query()->delete();
    $sub = subscribe($this->user, MembershipTier::Pro);

    $this->user->notify(new PaymentFailedNotification($sub));

    $wa = NotificationLog::where('channel', 'whatsapp')->firstOrFail();
    expect($wa)->status->toBe('failed')->error->toContain('132001');
    expect(NotificationLog::where('channel', 'sms')->where('error', 'fallback_from_whatsapp')->first())->status->toBe('sent')->provider->toBe('log');
});

it('retries transient WhatsApp errors, then marks the log failed', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 130429]], 429)]);
    $n = new CertificateIssuedNotification($this->cert);
    $n->id = (string) Str::uuid();
    expect(fn () => app(WhatsAppChannel::class)->send($this->user, $n))->toThrow(RuntimeException::class);

    NotificationLog::create(['notification_id' => $n->id, 'channel' => 'whatsapp', 'event' => 'certificate_issued', 'status' => 'sending', 'user_id' => $this->user->id]);
    $n->failed(new RuntimeException('WhatsApp: Rate limit hit'));
    expect(NotificationLog::where('notification_id', $n->id)->first())->status->toBe('failed')->error->toBe('WhatsApp: Rate limit hit');
});

it('verifies WhatsApp webhooks: handshake, signature, STOP opt-out', function () {
    $this->get(api('webhooks/whatsapp').'?hub.mode=subscribe&hub.verify_token=wa-verify&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get(api('webhooks/whatsapp').'?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();
    $this->call('POST', api('webhooks/whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{}')->assertUnauthorized();

    $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '919876543210', 'type' => 'text', 'text' => ['body' => 'stop']]]]]]]]]);
    $this->call('POST', api('webhooks/whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa-secret')], $body)->assertOk();
    expect($this->user->fresh()->whatsapp_opt_out_at)->not->toBeNull();
    expect(app(NotificationRouter::class)->channels($this->user->fresh(), eventNotification('certificate_issued')))
        ->not->toContain(WhatsAppChannel::class);
});

it('tracks Mailgun and Twilio delivery events (signed)', function () {
    config(['notify.mailgun.webhook_signing_key' => 'mg-key', 'notify.sms.twilio.token' => 'tw-token']);
    $mail = NotificationLog::create(['notification_id' => (string) Str::uuid(), 'channel' => 'mail', 'event' => 'welcome', 'status' => 'sent', 'provider_message_id' => '<abc@mail>']);
    $ts = (string) time();
    $sig = ['timestamp' => $ts, 'token' => 'tok', 'signature' => hash_hmac('sha256', $ts.'tok', 'mg-key')];
    $this->postJson(api('webhooks/mailgun'), ['signature' => $sig, 'event-data' => ['event' => 'delivered', 'user-variables' => ['notification_id' => $mail->notification_id], 'message' => ['headers' => ['message-id' => 'abc@mail']]]])->assertOk();
    $this->postJson(api('webhooks/mailgun'), ['signature' => $sig, 'event-data' => ['event' => 'opened', 'user-variables' => ['notification_id' => $mail->notification_id]]])->assertOk();
    expect($mail->fresh()->status)->toBe('read');
    $this->postJson(api('webhooks/mailgun'), ['signature' => ['timestamp' => $ts, 'token' => 'tok', 'signature' => 'forged'], 'event-data' => []])->assertUnauthorized();

    $sms = NotificationLog::create(['notification_id' => (string) Str::uuid(), 'channel' => 'sms', 'event' => 'otp', 'status' => 'sent', 'provider_message_id' => 'SM123']);
    $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30006'];
    ksort($params);
    $url = api('webhooks/twilio');
    $data = url($url);
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }
    $this->post($url, $params, ['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, 'tw-token', true))])->assertNoContent();
    expect($sms->fresh())->status->toBe('failed')->error->toBe('Twilio error 30006');
});

it('removes dead FCM tokens', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fcm']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    $n = eventNotification('new_course');
    $r = app(FcmChannel::class)->send($this->user, $n);
    expect($r['status'])->toBe('failed')->and(PushToken::count())->toBe(0);
});

it('lets admins retry a failed send from the stored variables', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $log = NotificationLog::create(['notification_id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'channel' => 'mail', 'event' => 'new_course',
        'status' => 'failed', 'vars' => ['course' => 'Meta Ads', 'url' => 'https://x.test'], 'error' => 'SMTP timeout']);

    $this->actingAs($admin)->getJson(api('admin/notification-logs?status=failed'))->assertJsonPath('data.0.retryable', true)->assertJsonPath('meta.summary.mail.failed', 1);
    $this->actingAs($admin)->postJson(api("admin/notification-logs/{$log->uuid}/retry"))->assertStatus(202);
    Notification::assertSentTo($this->user, RetryNotification::class, fn ($n) => $n->onlyChannels() === ['mail'] && $n->vars($this->user)['course'] === 'Meta Ads');
});
