<?php

use App\Billing\InvoiceService;
use App\Enums\MembershipTier;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('lists my payments only', function () {
    $user = User::factory()->create();
    Payment::factory()->count(2)->for($user)->create();
    Payment::factory()->create(); // someone else's

    $this->actingAs($user)->getJson(api('billing/invoices'))->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.tax.name', 'GST');
});

it('downloads my invoice as PDF (generated on demand) but not other people\'s', function () {
    $user = User::factory()->create(['country' => 'IN', 'tax_id' => '27AAPFU0939F1ZV']);
    $sub = subscribe($user, MembershipTier::Pro);
    $payment = Payment::factory()->for($user)->create(['subscription_id' => $sub->id, 'plan_price_id' => $sub->plan_price_id, 'billing_tax_id' => '27AAPFU0939F1ZV']);

    $res = $this->actingAs($user)->get(api("billing/invoices/{$payment->uuid}/pdf"))->assertOk();
    expect($res->headers->get('content-type'))->toBe('application/pdf')
        ->and($payment->fresh()->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($payment->fresh()->pdf_path);

    $this->actingAs(User::factory()->create())->get(api("billing/invoices/{$payment->uuid}/pdf"))->assertForbidden();
});

it('numbers invoices sequentially per financial year', function () {
    $this->travelTo(now()->setDate(2026, 11, 12));
    $svc = app(InvoiceService::class);

    expect($svc->nextNumber())->toBe('INV-2026-27-000001')
        ->and($svc->nextNumber())->toBe('INV-2026-27-000002');

    $this->travelTo(now()->setDate(2027, 4, 1));
    expect($svc->nextNumber())->toBe('INV-2027-28-000001');
});

it('saves GST/VAT billing details', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(api('billing/details'), [
        'billing_name' => 'Acme Pvt Ltd', 'tax_id' => '27aapfu0939f1zv',
        'billing_address' => ['line1' => '1 MG Road', 'city' => 'Pune', 'state' => 'MH', 'postal_code' => '411001', 'country' => 'IN'],
    ])->assertOk();

    expect($user->fresh())->tax_id->toBe('27AAPFU0939F1ZV')->billing_name->toBe('Acme Pvt Ltd');
});
