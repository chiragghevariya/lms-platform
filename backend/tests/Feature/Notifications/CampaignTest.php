<?php

use App\Enums\MembershipTier;
use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    enableChannels();
    Mail::fake();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.camp']]])]);
    $this->admin = User::factory()->admin()->create();
    $this->pro = reachableUser(['country' => 'IN']);
    subscribe($this->pro, MembershipTier::Pro);
    $this->proNoWa = User::factory()->student()->create(['country' => 'IN']);
    subscribe($this->proNoWa, MembershipTier::Pro);
    $this->starter = User::factory()->student()->create(['country' => 'US']);
    subscribe($this->starter, MembershipTier::Starter);
    $this->free = User::factory()->student()->create(['country' => 'IN']);
    $this->body = [
        'name' => 'Diwali offer', 'channels' => ['mail', 'database', 'whatsapp'],
        'subject' => 'Diwali: 30% off Elite, {{name}}', 'body' => "Upgrade this week and save.\n\n**Ends Sunday.**",
        'action_text' => 'Upgrade', 'action_url' => 'https://brand.example/pricing',
        'whatsapp_template' => 'diwali_offer', 'whatsapp_params' => ['{{name}}', '30%'],
        'segment' => ['tiers' => ['pro'], 'countries' => ['IN']],
    ];
});

it('builds segments by tier, status, course, inactivity and country', function () {
    $count = fn (array $s) => $this->actingAs($this->admin)->postJson(api('admin/campaigns/audience'), ['segment' => $s])->json('data.count');
    expect($count(['tiers' => ['pro'], 'countries' => ['IN']]))->toBe(2)
        ->and($count(['tiers' => ['free']]))->toBe(2) // the free learner + the admin
        ->and($count(['tiers' => ['starter', 'pro']]))->toBe(3)
        ->and($count(['status' => ['active'], 'countries' => ['US']]))->toBe(1);
    $this->actingAs($this->admin)->postJson(api('admin/campaigns/audience'), ['segment' => ['tiers' => ['pro']]])->assertJsonPath('data.whatsapp_reachable', 1);

    $course = courseWith(['text']);
    Enrollment::create(['user_id' => $this->starter->id, 'course_id' => $course->id, 'source' => 'membership', 'started_at' => now()]);
    expect($count(['course_id' => $course->uuid]))->toBe(1);
    DB::table('learning_days')->insert(['user_id' => $this->pro->id, 'day' => now()->toDateString(), 'seconds' => 60]);
    expect($count(['tiers' => ['pro'], 'inactive_days' => 7]))->toBe(1);
});

it('sends a campaign on every chosen channel, respecting preferences and WhatsApp consent, with per-channel stats', function () {
    NotificationPreference::create(['user_id' => $this->proNoWa->id, 'event' => 'admin_announcement', 'channel' => 'mail', 'enabled' => false]);
    $id = $this->actingAs($this->admin)->postJson(api('admin/campaigns'), $this->body)->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->postJson(api("admin/campaigns/{$id}/schedule"))->assertOk(); // send now

    $c = Campaign::where('uuid', $id)->firstOrFail();
    expect($c)->status->toBe('sent')->audience_count->toBe(2);
    Http::assertSent(fn ($r) => $r['template']['name'] === 'diwali_offer' && $r['template']['components'][0]['parameters'][1]['text'] === '30%');
    Mail::assertSentCount(0); // notifications don't use Mailables; check the logs instead
    $logs = NotificationLog::where('campaign_id', $c->id)->get();
    expect($logs->where('user_id', $this->pro->id)->pluck('status', 'channel')->sortKeys()->all())->toBe(['database' => 'delivered', 'mail' => 'sent', 'whatsapp' => 'sent'])
        ->and($logs->where('user_id', $this->proNoWa->id)->pluck('status', 'channel')->sortKeys()->all())
        ->toBe(['database' => 'delivered', 'mail' => 'skipped', 'whatsapp' => 'skipped']); // mail off by preference, no WhatsApp consent

    $this->actingAs($this->admin)->getJson(api("admin/campaigns/{$id}"))
        ->assertJsonPath('data.stats.whatsapp.sent', 1)->assertJsonPath('data.stats.mail.skipped', 1)->assertJsonPath('data.stats.database.delivered', 2);
    $this->pro->refresh();
    expect($this->pro->notifications()->first()->data['title'])->toBe('Diwali: 30% off Elite, '.explode(' ', $this->pro->name)[0]);
    expect($this->pro->notifications()->first()->data['body'])->toBe('Upgrade this week and save. Ends Sunday.');
});

it('schedules for later, runs from the scheduler, and can be cancelled', function () {
    $id = $this->actingAs($this->admin)->postJson(api('admin/campaigns'), $this->body)->json('data.id');
    $this->actingAs($this->admin)->postJson(api("admin/campaigns/{$id}/schedule"), ['scheduled_at' => now()->addHour()->toIso8601String()])->assertJsonPath('data.status', 'scheduled');
    $this->artisan('notify:scheduled');
    expect(NotificationLog::whereNotNull('campaign_id')->count())->toBe(0);
    $this->travel(61)->minutes();
    $this->artisan('notify:scheduled');
    expect(Campaign::first()->status)->toBe('sent');

    $other = $this->actingAs($this->admin)->postJson(api('admin/campaigns'), $this->body)->json('data.id');
    $this->actingAs($this->admin)->postJson(api("admin/campaigns/{$other}/schedule"), ['scheduled_at' => now()->addDay()->toIso8601String()]);
    $this->actingAs($this->admin)->postJson(api("admin/campaigns/{$other}/cancel"))->assertJsonPath('data.status', 'cancelled');
    $this->travel(2)->days();
    $this->artisan('notify:scheduled');
    expect(Campaign::where('uuid', $other)->value('status'))->toBe('cancelled');
});

it('test-sends to the admin only and validates content', function () {
    $this->actingAs($this->admin)->postJson(api('admin/campaigns'), [...$this->body, 'whatsapp_template' => null])->assertJsonValidationErrors('whatsapp_template');
    $id = $this->actingAs($this->admin)->postJson(api('admin/campaigns'), $this->body)->json('data.id');
    $this->actingAs($this->admin)->postJson(api("admin/campaigns/{$id}/test"))->assertJsonPath('data.sent_to', $this->admin->email);
    expect($this->admin->notifications()->count())->toBe(1)->and($this->pro->notifications()->count())->toBe(0)
        ->and(NotificationLog::whereNotNull('campaign_id')->count())->toBe(0);
    $this->actingAs($this->pro)->postJson(api('admin/campaigns'), $this->body)->assertForbidden();
});
