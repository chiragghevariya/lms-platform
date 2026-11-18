<?php

namespace App\Exports;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<User> */
class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param Builder<User> $query */
    public function __construct(private readonly Builder $query) {}

    /** @return Builder<User> */
    public function query(): Builder
    {
        return $this->query->with('roles', 'liveSubscriptions.plan');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['ID', 'Name', 'Email', 'Phone', 'Country', 'Roles', 'Status', 'Email verified', 'Plan', 'Tier', 'Membership status', 'Complimentary', 'Renews / ends', 'Joined', 'Last login'];
    }

    /**
     * @param  User  $row
     * @return list<string|int|null>
     */
    public function map($row): array
    {
        /** @var Subscription|null $sub */
        $sub = $row->activeSubscription();

        return [
            $row->uuid, $row->name, $row->email, $row->phone, $row->country,
            $row->getRoleNames()->implode(', '), $row->status->value, $row->email_verified_at ? 'yes' : 'no',
            $sub?->plan->name, $sub?->plan->tier_level, $sub?->status->value, $sub ? ($sub->isManual() ? 'yes' : 'no') : null,
            $sub?->current_period_end?->toDateString(), $row->created_at?->toDateTimeString(), $row->last_login_at?->toDateTimeString(),
        ];
    }
}
