<?php

use App\Models\Payment;
use App\Models\PayoutStatement;
use App\Models\User;
use Database\Seeders\EarningsDemoSeeder;

beforeEach(function () {
    $this->seed(EarningsDemoSeeder::class);
    $this->travelTo('2026-11-05 09:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->meera = User::where('email', 'meera@example.com')->first();
    $this->ivan = User::where('email', 'instructor@example.com')->first();
});

function statementFor(User $u, string $currency): PayoutStatement
{
    return PayoutStatement::where('instructor_id', $u->id)->where('period', '2026-10')->where('currency', $currency)->firstOrFail();
}

it('produces the earnings report that matches the seeded data', function () {
    $this->artisan('earnings:generate', ['period' => '2026-10'])->assertSuccessful();

    expect(PayoutStatement::count())->toBe(4);
    expect(statementFor($this->meera, 'INR'))
        ->subscription_revenue->toBe(300000)->pool_amount->toBe(150000)
        ->watch_seconds->toBe(6000)->total_watch_seconds->toBe(9000)
        ->subscription_earnings->toBe(100000)->sales_earnings->toBe(0)->total->toBe(100000)
        ->revenue_share_percent->toBe(70)->status->toBe('draft');
    expect(statementFor($this->meera, 'USD'))->subscription_earnings->toBe(666)->sales_earnings->toBe(3430)->total->toBe(4096);
    expect(statementFor($this->ivan, 'INR'))->subscription_earnings->toBe(50000)->sales_earnings->toBe(30000)->total->toBe(80000)
        ->revenue_share_percent->toBe(60);
    expect(statementFor($this->ivan, 'USD'))->total->toBe(333);

    $lines = statementFor($this->ivan, 'INR')->lines()->orderBy('type')->get();
    expect($lines->pluck('type')->all())->toBe(['course_sale', 'subscription_pool'])
        ->and($lines[0])->gross->toBe(50000)->amount->toBe(30000)
        ->and($lines[1])->watch_seconds->toBe(3000)->amount->toBe(50000);

    // The instructor sees exactly this in their earnings page.
    $this->actingAs($this->ivan)->getJson(api('instructor/earnings'))->assertOk()
        ->assertJsonPath('data.revenue_share_percent', 60)
        ->assertJsonFragment(['period' => '2026-10', 'currency' => 'INR', 'total' => 80000, 'status' => 'draft']);
});

it('runs the admin payout workflow and never rewrites finalized statements', function () {
    $this->actingAs($this->admin)->postJson(api('admin/payouts/generate'), ['period' => '2026-10'])->assertJsonPath('data.written', 4);
    $this->actingAs($this->admin)->getJson(api('admin/payouts?period=2026-10'))
        ->assertJsonCount(4, 'data')->assertJsonPath('meta.totals.INR.total', 180000)->assertJsonPath('meta.totals.USD.total', 4429);

    $s = statementFor($this->ivan, 'INR');
    $this->actingAs($this->admin)->postJson(api("admin/payouts/{$s->uuid}/paid"), ['reference' => 'UTR123'])->assertStatus(409);
    $this->actingAs($this->admin)->postJson(api('admin/payouts/finalize'), ['period' => '2026-10'])->assertJsonPath('data.finalized', 4);
    $this->actingAs($this->admin)->postJson(api("admin/payouts/{$s->uuid}/paid"), ['reference' => 'UTR123'])
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_reference', 'UTR123');

    // A late refund after finalizing doesn't silently change what was agreed.
    Payment::where('gateway_payment_id', 'demo_earn_sale_b')->update(['status' => 'refunded']);
    $this->actingAs($this->admin)->postJson(api('admin/payouts/generate'), ['period' => '2026-10'])->assertJsonPath('data.written', 0);
    expect(statementFor($this->ivan, 'INR')->total)->toBe(80000);

    $this->actingAs($this->admin)->postJson(api('admin/payouts/generate'), ['period' => '2026-11'])->assertJsonValidationErrors('period');
    $this->actingAs($this->ivan)->getJson(api('admin/payouts'))->assertForbidden();
});

it('recomputes drafts (e.g. after a refund) and lets admins change an instructor share', function () {
    $this->artisan('earnings:generate', ['period' => '2026-10']);
    Payment::where('gateway_payment_id', 'demo_earn_sale_b')->update(['status' => 'refunded']);
    $this->actingAs($this->admin)->patchJson(api("admin/instructors/{$this->meera->uuid}/revenue-share"), ['revenue_share_percent' => 80])->assertOk();
    $this->artisan('earnings:generate', ['period' => '2026-10']);

    expect(statementFor($this->ivan, 'INR')->total)->toBe(50000)
        ->and(statementFor($this->meera, 'USD')->sales_earnings)->toBe(3920); // 4900 × 80 %
});

it('keeps instructors to their own statements and shows payout details only to admins', function () {
    $this->artisan('earnings:generate', ['period' => '2026-10']);
    $this->actingAs($this->ivan)->putJson(api('instructor/profile'), [
        'payout_method' => 'bank', 'payout_details' => ['account_name' => 'Ivan I', 'account_number' => '123456789012', 'ifsc' => 'HDFC0001234'], 'tax_id' => 'abcde1234f',
    ])->assertOk()->assertJsonPath('data.payout.summary', 'Ivan I · A/C ••••9012 · HDFC0001234')->assertJsonPath('data.payout.tax_id', '••••••234F');
    $raw = DB::table('instructor_profiles')->where('user_id', $this->ivan->id)->value('payout_details');
    expect($raw)->not->toContain('123456789012'); // encrypted at rest

    $mine = statementFor($this->ivan, 'INR');
    $this->actingAs($this->meera)->getJson(api("instructor/earnings/{$mine->uuid}"))->assertNotFound();
    $this->actingAs($this->ivan)->getJson(api("instructor/earnings/{$mine->uuid}"))->assertJsonCount(2, 'data.lines');
    $this->actingAs($this->admin)->getJson(api("admin/payouts/{$mine->uuid}"))->assertJsonPath('data.payout.details.account_number', '123456789012');

    $this->actingAs($this->ivan)->putJson(api('instructor/profile'), ['payout_method' => 'bank', 'payout_details' => ['account_number' => '12']])
        ->assertJsonValidationErrors(['payout_details.account_name', 'payout_details.account_number', 'payout_details.ifsc']);
});

it('estimates the current month on the fly', function () {
    $this->travelTo('2026-10-25 12:00:00');
    $this->actingAs($this->meera)->getJson(api('instructor/earnings'))
        ->assertJsonPath('data.current.period', '2026-10')
        ->assertJsonFragment(['currency' => 'INR', 'total' => 100000]);
});
