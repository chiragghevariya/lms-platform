<?php

use App\Models\Course;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Billing\PaymentReceiptNotification;
use App\Notifications\Platform\NewCourseNotification;
use App\Notify\PlatformNotification;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

it('renders branded mail with unsubscribe link + one-click headers (not for transactional mail)', function () {
    $user = User::factory()->create(['name' => 'Asha Learner']);
    $course = Course::factory()->create(['title' => 'Meta Ads from Zero']);
    $n = new NewCourseNotification($course);
    $n->id = (string) Str::uuid();

    $mail = $n->toMail($user);
    $html = (string) $mail->render();
    expect($mail->subject)->toBe('New course: Meta Ads from Zero')
        ->and($html)->toContain('Meta Ads from Zero')->toContain(config('lms.brand.name'))->toContain('Unsubscribe from these emails')
        ->toContain('/app/notification-settings')->not->toContain('laravel.com/img');
    $email = new Email;
    foreach ($mail->callbacks as $cb) {
        $cb($email);
    }
    expect($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and(json_decode((string) $email->getHeaders()->get('X-Mailgun-Variables')?->getBodyAsString(), true))->toBe(['notification_id' => $n->id]);

    $receipt = new PaymentReceiptNotification(Payment::factory()->create(['user_id' => $user->id]));
    $receipt->id = (string) Str::uuid();
    expect($receipt->toMail($user)->viewData['unsubscribeUrl'])->toBeNull(); // invoices can't be unsubscribed
});

it('uses an admin-customised template (escaping user values in Markdown)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->putJson(api('admin/notification-templates/new_course/mail'), [
        'subject' => 'Fresh: {{course}}', 'body' => "Hi {{name}}, **{{course}}** is live.\n\n{{nope}}",
    ])->assertStatus(422)->assertJsonPath('errors.body.0', 'Unknown variables: nope');
    $this->actingAs($admin)->putJson(api('admin/notification-templates/new_course/mail'), [
        'subject' => 'Fresh: {{course}}', 'body' => 'Hi {{name}}, **{{course}}** is live.', 'action_text' => 'Take a look',
    ])->assertOk()->assertJsonPath('data.subject', 'Fresh: {{course}}');

    $user = User::factory()->create(['name' => '[Click](https://evil.test)']);
    $n = new NewCourseNotification(Course::factory()->create(['title' => 'Ads 101']));
    $n->id = (string) Str::uuid();
    $mail = $n->toMail($user);
    $html = (string) $mail->render();
    expect($mail->subject)->toBe('Fresh: Ads 101')->and($html)->toContain('Take a look')->not->toContain('href="https://evil.test"');

    $this->actingAs($admin)->postJson(api('admin/notification-templates/new_course/preview'), ['channel' => 'mail', 'body' => 'Hi {{name}}, {{course}}'])
        ->assertJsonPath('data.body', "<p>Hi Asha, Meta Ads from Zero</p>\n");
    $this->actingAs($admin)->deleteJson(api('admin/notification-templates/new_course/mail'))->assertJsonPath('data.subject', 'New course: {{course}}');
    $this->actingAs($user)->getJson(api('admin/notification-templates'))->assertForbidden();
});

it('unsubscribes through the signed link (page and RFC 8058 one-click)', function () {
    $user = User::factory()->create();
    $url = PlatformNotification::unsubscribeUrl($user, 'new_course');
    $this->get($url)->assertOk()->assertSee('Unsubscribe?');
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
    expect(NotificationPreference::where(['user_id' => $user->id, 'event' => 'new_course', 'channel' => 'mail'])->value('enabled'))->toBeFalse();

    $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    $this->post(URL::signedRoute('notifications.unsubscribe.store', ['user' => $user->uuid, 'event' => 'invoice']))->assertNotFound(); // mandatory
});

it('serves the preferences matrix and saves changes (mandatory channels locked)', function () {
    $user = User::factory()->student()->create(['phone' => '+919000000001']);
    $prefs = $this->actingAs($user)->getJson(api('me/notification-preferences'))->assertOk()->json('data');
    $billing = collect($prefs['categories'])->firstWhere('key', 'billing');
    $invoice = collect($billing['events'])->firstWhere('event', 'invoice');
    expect($invoice['channels']['mail'])->toBe(['enabled' => true, 'locked' => true])
        ->and(collect($prefs['categories'])->pluck('key'))->not->toContain('admin')
        ->and($prefs['whatsapp'])->toMatchArray(['opted_in' => false, 'phone_verified' => false]);

    $this->actingAs($user)->putJson(api('me/notification-preferences'), ['changes' => [
        ['event' => 'invoice', 'channel' => 'mail', 'enabled' => false],
        ['event' => 'new_course', 'channel' => 'push', 'enabled' => false],
    ]])->assertOk();
    expect(NotificationPreference::where('user_id', $user->id)->pluck('event')->all())->toBe(['new_course']);
});

it('requires a verified phone and explicit consent for WhatsApp, and timestamps both directions', function () {
    $user = User::factory()->create(['phone' => '+919000000001']);
    $this->actingAs($user)->putJson(api('me/whatsapp'), ['opt_in' => true, 'consent' => true])->assertStatus(422);
    $user->forceFill(['phone_verified_at' => now()])->save();
    $this->actingAs($user)->putJson(api('me/whatsapp'), ['opt_in' => true])->assertJsonValidationErrors('consent');
    $this->actingAs($user)->putJson(api('me/whatsapp'), ['opt_in' => true, 'consent' => true])->assertJsonPath('data.opted_in', true);
    expect($user->fresh()->whatsapp_opt_in_at)->not->toBeNull();
    $this->actingAs($user)->putJson(api('me/whatsapp'), ['opt_in' => false])->assertOk();
    expect($user->fresh()->whatsapp_opt_out_at)->not->toBeNull();
});

it('runs the in-app bell: list, unread count, mark one / all read; registers push devices', function () {
    $user = User::factory()->create();
    foreach (['new_course', 'certificate_issued'] as $e) {
        $user->notifyNow(eventNotification($e), ['database']);
    }
    $this->actingAs($user)->getJson(api('me/notifications/unread-count'))->assertJsonPath('data.unread', 2);
    $id = $this->actingAs($user)->getJson(api('me/notifications'))->assertJsonCount(2, 'data')->json('data.0.id');
    $this->actingAs($user)->postJson(api("me/notifications/{$id}/read"))->assertNoContent();
    $this->actingAs($user)->getJson(api('me/notifications?unread=1'))->assertJsonCount(1, 'data');
    $this->actingAs($user)->postJson(api('me/notifications/read-all'))->assertNoContent();
    $this->actingAs($user)->getJson(api('me/notifications/unread-count'))->assertJsonPath('data.unread', 0);
    $this->actingAs(User::factory()->create())->postJson(api("me/notifications/{$id}/read"))->assertNotFound();

    $this->actingAs($user)->postJson(api('me/push-tokens'), ['token' => 'tok-1', 'platform' => 'android'])->assertCreated();
    $this->actingAs($user)->deleteJson(api('me/push-tokens'), ['token' => 'tok-1'])->assertNoContent();
    expect(NotificationLog::where('channel', 'database')->where('status', 'delivered')->count())->toBe(2);
});

it('sends OTPs over SMS through the configured driver and logs them without the code', function () {
    config(['notify.sms.driver' => 'msg91', 'notify.sms.msg91.authkey' => 'mk', 'notify.sms.msg91.otp_template_id' => 'tpl-otp', 'lms.otp.driver' => 'msg91']);
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'req-123'])]);
    $user = User::factory()->create(['phone' => '+919876543210']);

    app(OtpSender::class)->send('+919876543210', '482913 is your Brand verification code. It expires in 10 minutes.');

    Http::assertSent(fn ($r) => $r->url() === 'https://control.msg91.com/api/v5/flow' && $r->header('authkey')[0] === 'mk'
        && $r['template_id'] === 'tpl-otp' && $r['recipients'][0] === ['mobiles' => '919876543210', 'otp' => '482913']);
    $log = NotificationLog::where('event', 'otp')->firstOrFail();
    expect($log)->channel->toBe('sms')->status->toBe('sent')->provider->toBe('msg91')->provider_message_id->toBe('req-123')->user_id->toBe($user->id)
        ->and(json_encode($log->toArray()))->not->toContain('482913');
});
