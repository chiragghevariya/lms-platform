<?php

namespace App\Exports;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Amounts in major units (e.g. rupees) with the currency in its own column, so the
 * sheet sums correctly per currency.
 *
 * @implements WithMapping<Payment>
 */
class PaymentsExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param Builder<Payment> $query */
    public function __construct(private readonly Builder $query) {}

    /** @return Builder<Payment> */
    public function query(): Builder
    {
        return $this->query->with('user:id,uuid,name,email');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Payment ID', 'Invoice', 'Date paid', 'Created', 'Status', 'Type', 'Gateway', 'Gateway payment ID', 'Customer', 'Email', 'Country', 'Currency', 'Subtotal', 'Discount', 'Tax', 'Tax name', 'Total', 'Refunded', 'Net', 'Description'];
    }

    /**
     * @param  Payment  $row
     * @return list<string|int|float|null>
     */
    public function map($row): array
    {
        $m = fn (int $minor) => round($minor / 100, 2);

        return [
            $row->uuid, $row->invoice_number, $row->paid_at?->toDateTimeString(), $row->created_at?->toDateTimeString(),
            $row->status->value, $row->course_id ? 'course' : 'membership', $row->gateway->value, $row->gateway_payment_id,
            $row->billing_name ?? $row->user->name, $row->billing_email ?? $row->user->email, $row->billing_country, $row->currency,
            $m($row->subtotal), $m($row->discount_amount), $m($row->tax_amount), $row->tax_name, $m($row->amount),
            $m($row->refunded_amount), $m($row->amount - $row->refunded_amount), $row->description,
        ];
    }
}
