<?php

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A charge attempt; once succeeded it is also the invoice (invoice_number, pdf_path).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $subscription_id
 * @property int|null $plan_price_id
 * @property int|null $coupon_id
 * @property int|null $course_id
 * @property Gateway $gateway
 * @property string|null $gateway_payment_id
 * @property string|null $gateway_invoice_id
 * @property PaymentStatus $status
 * @property string $currency
 * @property int $subtotal
 * @property int $discount_amount
 * @property int $tax_amount
 * @property string $tax_rate
 * @property string|null $tax_name
 * @property int $amount
 * @property string|null $description
 * @property string|null $invoice_number
 * @property string|null $billing_name
 * @property string|null $billing_email
 * @property array<string, string>|null $billing_address
 * @property string|null $billing_tax_id
 * @property string|null $billing_country
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $disputed_at
 * @property int $refunded_amount
 * @property string|null $gateway_reference
 * @property string|null $payment_fingerprint
 * @property string|null $failure_reason
 * @property string|null $pdf_path
 * @property User $user
 * @property Subscription|null $subscription
 * @property PlanPrice|null $planPrice
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'user_id', 'subscription_id', 'plan_price_id', 'course_id', 'coupon_id', 'gateway', 'gateway_payment_id',
        'gateway_invoice_id', 'gateway_reference', 'payment_fingerprint', 'refunded_amount', 'status', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'tax_rate',
        'tax_name', 'amount', 'description', 'invoice_number', 'billing_name', 'billing_email',
        'billing_address', 'billing_tax_id', 'billing_country', 'period_start', 'period_end', 'paid_at',
        'failure_reason', 'pdf_path',
    ];

    protected $attributes = [
        'subscription_id' => null, 'plan_price_id' => null, 'course_id' => null, 'coupon_id' => null, 'gateway_payment_id' => null,
        'gateway_invoice_id' => null, 'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'tax_rate' => '0',
        'tax_name' => null, 'description' => null, 'invoice_number' => null, 'billing_name' => null,
        'billing_email' => null, 'billing_address' => null, 'billing_tax_id' => null, 'billing_country' => null,
        'period_start' => null, 'period_end' => null, 'paid_at' => null, 'refunded_amount' => 0, 'failure_reason' => null, 'pdf_path' => null,
    ];

    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'status' => PaymentStatus::class,
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'tax_rate' => 'decimal:2',
            'amount' => 'integer',
            'billing_address' => 'encrypted:array', // PII at rest (invoice copy)
            'billing_tax_id' => 'encrypted',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<PlanPrice, $this> */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class)->withTrashed();
    }

    public function money(int $amount): Money
    {
        return Money::of($amount, $this->currency);
    }
}
