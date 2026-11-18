<?php

namespace App\Admin;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Filters shared by the payments table and its export. */
class PaymentQuery
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'gateway' => ['nullable', Rule::enum(Gateway::class)],
            'type' => ['nullable', 'in:membership,course'],
            'currency' => ['nullable', 'string', 'size:3'],
            'country' => ['nullable', 'string', 'size:2'],
            'refunded' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Payment>
     */
    public static function build(array $f): Builder
    {
        return Payment::query()
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes((string) $s, '%_\\').'%';
                $q->where(fn ($q) => $q->where('invoice_number', 'like', $like)->orWhere('gateway_payment_id', $s)
                    ->orWhere('gateway_reference', $s)->orWhere('uuid', $s)->orWhere('billing_email', 'like', $like)
                    ->orWhereHas('user', fn ($q) => $q->where('email', 'like', $like)->orWhere('name', 'like', $like)));
            })
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['gateway'] ?? null, fn ($q, $v) => $q->where('gateway', $v))
            ->when(($f['type'] ?? null) === 'course', fn ($q) => $q->whereNotNull('course_id'))
            ->when(($f['type'] ?? null) === 'membership', fn ($q) => $q->whereNull('course_id'))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper((string) $v)))
            ->when($f['country'] ?? null, fn ($q, $v) => $q->where('billing_country', strtoupper((string) $v)))
            ->when(isset($f['refunded']), fn ($q) => filter_var($f['refunded'], FILTER_VALIDATE_BOOLEAN) ? $q->where('refunded_amount', '>', 0) : $q->where('refunded_amount', 0))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse((string) $d)))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse((string) $d)->addDay()))
            ->latest('id');
    }
}
