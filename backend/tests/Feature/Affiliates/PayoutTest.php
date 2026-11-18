<?php

use App\Affiliates\CommissionEngine;
use App\Affiliates\Payouts\PayoutService;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;

function commissionFor(Affiliate $a, int $amount, string $status = 'approved', string $currency = 'INR'): Commission
{
    $ref = Referral::create(['affiliate_id' => $a->id, 'referred_user_id' => User::factory()->create()->id, 'attribution' => 'last_touch', 'status' => 'converted']);

    return Commission::create(['affiliate_id' => $a->id, 'referral_id' => $ref->id, 'currency' => $currency, 'base_amount' => $amount * 5,
        'rate' => '20%', 'amount' => $amount, 'status' => $status, 'available_at' => now()]);
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->a = makeAffiliate(['payout_method' => 'bank', 'payout_details' => ['account_name' => '=HYPERLINK("x")', 'account_number' => '123456789', 'ifsc' => 'HDFC0001234']]);
});

it('batches balances above the minimum, exports CSV, marks paid', function () {
    commissionFor($this->a, 60000);
    commissionFor($this->a, 50000);
    commissionFor($this->a, 5000, 'pending'); // not yet payable
    commissionFor($this->a, 1000, 'approved', 'USD'); // below the USD minimum
    $low = makeAffiliate(['payout_method' => 'upi', 'payout_details' => ['upi_id' => 'x@okaxis']]);
    commissionFor($low, 20000); // below ₹1,000
    $noDetails = makeAffiliate();
    commissionFor($noDetails, 500000);

    $this->actingAs($this->admin)->postJson(api('admin/affiliate-payouts/batch'), ['period' => '2026-12'])->assertJsonPath('data.created', 1);
    $p = AffiliatePayout::firstOrFail();
    expect($p)->amount->toBe(110000)->commissions_count->toBe(2)->currency->toBe('INR')->method->toBe('bank');
    $this->actingAs($this->admin)->postJson(api('admin/affiliate-payouts/batch'), ['period' => '2026-12'])->assertJsonPath('data.created', 0); // idempotent

    $csv = (string) $this->actingAs($this->admin)->get(api('admin/affiliate-payouts/export?period=2026-12'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($csv)->toContain('1100.00')->toContain('123456789')->toContain("'=HYPERLINK") // formula neutralised
        ->not->toContain(',=HYPERLINK');

    $this->actingAs($this->admin)->postJson(api("admin/affiliate-payouts/{$p->uuid}/paid"), ['reference' => 'UTR42'])->assertJsonPath('data.status', 'paid');
    expect(Commission::where('payout_id', $p->id)->pluck('status')->unique()->all())->toBe(['paid']);
    $this->actingAs($this->a->user)->getJson(api('me/affiliate/payouts'))->assertJsonPath('data.0.reference', 'UTR42');
});

it('returns commissions to the balance when a payout fails', function () {
    commissionFor($this->a, 150000);
    app(PayoutService::class)->batch('2026-12');
    $p = AffiliatePayout::firstOrFail();
    $this->actingAs($this->admin)->postJson(api("admin/affiliate-payouts/{$p->uuid}/failed"))->assertJsonPath('data.status', 'failed');

    expect(Commission::first())->payout_id->toBeNull()->status->toBe('approved');
    expect(app(PayoutService::class)->batch('2027-01'))->toBe(1);
});

it('nets clawbacks against new earnings', function () {
    commissionFor($this->a, 150000);
    commissionFor($this->a, -40000); // clawback from an earlier refund
    app(PayoutService::class)->batch('2026-12');
    expect(AffiliatePayout::first()->amount)->toBe(110000);
});

it('shrinks a pending payout when one of its commissions is refunded', function () {
    $c = commissionFor($this->a, 150000);
    commissionFor($this->a, 50000);
    $payment = Payment::factory()->create(['amount' => 750000, 'tax_amount' => 0]);
    $c->forceFill(['payment_id' => $payment->id])->save();
    app(PayoutService::class)->batch('2026-12');

    app(CommissionEngine::class)->reverse($payment, 750000, true, 'refund');
    expect(AffiliatePayout::first())->amount->toBe(50000)->commissions_count->toBe(1);
});
