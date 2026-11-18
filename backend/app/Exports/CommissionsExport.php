<?php

namespace App\Exports;

use App\Models\Commission;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<Commission> */
class CommissionsExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param Builder<Commission> $query */
    public function __construct(private readonly Builder $query) {}

    /** @return Builder<Commission> */
    public function query(): Builder
    {
        return $this->query->with('affiliate.user:id,uuid,name,email', 'payment:id,uuid,invoice_number');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Commission ID', 'Created', 'Affiliate', 'Affiliate email', 'Code', 'Level', 'Kind', 'Status', 'Currency', 'Base amount', 'Rate', 'Amount', 'Reversed', 'Net', 'Available at', 'Payment', 'Invoice', 'Needs review'];
    }

    /**
     * @param  Commission  $row
     * @return list<string|int|float|null>
     */
    public function map($row): array
    {
        $m = fn (int $minor) => round($minor / 100, 2);

        return [
            $row->uuid, $row->created_at?->toDateTimeString(), $row->affiliate->user->name, $row->affiliate->user->email, $row->affiliate->code,
            $row->level, $row->kind, $row->status, $row->currency, $m($row->base_amount), $row->rate, $m($row->amount),
            $m($row->reversed_amount), $m($row->amount - $row->reversed_amount), $row->available_at->toDateTimeString(),
            $row->payment?->uuid, $row->payment?->invoice_number, $row->needs_review ? 'yes' : 'no',
        ];
    }
}
