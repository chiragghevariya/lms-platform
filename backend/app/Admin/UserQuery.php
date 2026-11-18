<?php

namespace App\Admin;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Filters shared by the admin users table, bulk actions and the CSV/Excel export. */
class UserQuery
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'tier' => ['nullable', 'in:none,1,2,3'],
            'country' => ['nullable', 'string', 'size:2'],
            'verified' => ['nullable', 'boolean'],
            'joined_from' => ['nullable', 'date'],
            'joined_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:created_at,-created_at,name,-name,last_login_at,-last_login_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<User>
     */
    public static function build(array $f): Builder
    {
        $live = fn ($q) => $q->whereIn('status', SubscriptionStatus::live())
            ->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));

        $sort = (string) ($f['sort'] ?? '-created_at');
        $column = ltrim($sort, '-');

        return User::query()
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes((string) $s, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('uuid', $s));
            })
            ->when($f['role'] ?? null, fn ($q, $r) => $q->role($r))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['country'] ?? null, fn ($q, $c) => $q->where('country', strtoupper((string) $c)))
            ->when(isset($f['verified']), fn ($q) => filter_var($f['verified'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at'))
            ->when($f['joined_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['joined_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse((string) $d)->addDay()))
            ->when(($f['tier'] ?? null) === 'none', fn ($q) => $q->whereDoesntHave('subscriptions', $live))
            ->when(in_array($f['tier'] ?? null, ['1', '2', '3'], true), fn ($q) => $q->whereHas('subscriptions', fn ($q) => $live($q)->whereHas('plan', fn ($q) => $q->where('tier_level', (int) $f['tier']))))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id');
    }
}
