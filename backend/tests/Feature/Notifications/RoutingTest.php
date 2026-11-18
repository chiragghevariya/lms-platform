<?php

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notify\Channels;
use App\Notify\NotificationRouter;

beforeEach(fn () => enableChannels());

/*
 * ACCEPTANCE: each event fires on the right channels, respecting preferences.
 * For every event: a fully reachable user gets exactly the configured default channels; turning a channel off
 * removes it unless it's mandatory; unreachable channels are skipped (and logged with the reason).
 */
it('routes every event to its configured channels and honours preferences', function () {
    $router = app(NotificationRouter::class);
    foreach ((array) config('notify.events') as $event => $cfg) {
        $user = reachableUser();
        $n = eventNotification($event);
        $expected = collect(Channels::ALL)->filter(fn ($ch) => $cfg['channels'][$ch] ?? false)
            ->filter(fn ($ch) => $ch !== 'whatsapp' || $n->toWhatsApp($user) !== null) // events without a WhatsApp template
            ->map(fn ($ch) => Channels::driver($ch))->values()->all();
        expect($router->channels($user, $n))->toBe($expected, "defaults for {$event}");

        foreach (Channels::ALL as $ch) {
            NotificationPreference::create(['user_id' => $user->id, 'event' => $event, 'channel' => $ch, 'enabled' => false]);
        }
        $locked = collect($cfg['mandatory'])->map(fn ($ch) => Channels::driver($ch))->values()->all();
        expect($router->channels($user, eventNotification($event)))->toBe($locked, "all off for {$event} keeps only mandatory");
    }
});

it('skips unreachable channels with a reason and falls back to SMS where allowed', function () {
    $user = User::factory()->student()->create(['phone' => '+919876543210', 'phone_verified_at' => now()]); // no WhatsApp consent, no device
    $n = eventNotification('payment_failed');

    expect(app(NotificationRouter::class)->channels($user, $n))->toBe(['mail', 'database', Channels::driver('sms')]);
    expect(NotificationLog::where('notification_id', $n->id)->pluck('error', 'channel')->all())
        ->toBe(['whatsapp' => 'no_whatsapp_consent', 'push' => 'no_device']);

    // Opting out after opting in counts as no consent.
    $user->forceFill(['whatsapp_opt_in_at' => now()->subDays(2), 'whatsapp_opt_out_at' => now()->subDay()])->save();
    expect(app(NotificationRouter::class)->channels($user, eventNotification('certificate_issued')))->not->toContain(Channels::driver('whatsapp'));
});

it('logs a skipped row when a preference turns a channel off', function () {
    $user = reachableUser();
    NotificationPreference::create(['user_id' => $user->id, 'event' => 'new_course', 'channel' => 'mail', 'enabled' => false]);
    $n = eventNotification('new_course');
    app(NotificationRouter::class)->channels($user, $n);
    expect(NotificationLog::where('notification_id', $n->id)->where('channel', 'mail')->first())->status->toBe('skipped')->error->toBe('preference_off');
});

it('does not use WhatsApp or push when they are not configured', function () {
    config(['notify.whatsapp.token' => null, 'notify.fcm.project_id' => null]);
    expect(app(NotificationRouter::class)->channels(reachableUser(), eventNotification('certificate_issued')))->toBe(['mail', 'database']);
});
