<?php

namespace App\Affiliates;

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Application → approval / rejection → suspension. Affiliate codes are unique, readable and immutable. */
class AffiliateProgram
{
    /** @param array{website?: string|null, promotion_plan: string} $data */
    public function apply(User $user, array $data): Affiliate
    {
        $existing = Affiliate::where('user_id', $user->id)->first();
        if ($existing && $existing->status !== 'rejected') {
            throw ValidationException::withMessages(['affiliate' => $existing->status === 'pending' ? 'Your application is being reviewed.' : 'You already have an affiliate account.']);
        }

        return DB::transaction(function () use ($user, $data, $existing) {
            $affiliate = $existing ?? new Affiliate(['user_id' => $user->id, 'code' => $this->code($user)]);
            $affiliate->fill(['website' => $data['website'] ?? null, 'promotion_plan' => $data['promotion_plan'], 'status' => 'pending'])->save();

            return $affiliate;
        });
    }

    public function approve(Affiliate $affiliate, ?string $notes = null): Affiliate
    {
        $affiliate->forceFill(['status' => 'approved', 'approved_at' => $affiliate->approved_at ?? now(), 'suspended_at' => null, 'review_notes' => $notes])->save();
        $affiliate->user->forceFill(['is_affiliate' => true, 'affiliate_code' => $affiliate->code, 'affiliate_activated_at' => $affiliate->user->affiliate_activated_at ?? now()])->save();

        return $affiliate;
    }

    public function reject(Affiliate $affiliate, string $notes): Affiliate
    {
        $affiliate->forceFill(['status' => 'rejected', 'review_notes' => $notes])->save();

        return $affiliate;
    }

    /** Suspended affiliates get no new attributions or commissions; existing pending ones stay held for review. */
    public function suspend(Affiliate $affiliate, string $notes): Affiliate
    {
        DB::transaction(function () use ($affiliate, $notes) {
            $affiliate->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'review_notes' => $notes])->save();
            $affiliate->user->forceFill(['is_affiliate' => false])->save();
            $affiliate->commissions()->where('status', 'pending')->update(['needs_review' => true]);
        });

        return $affiliate;
    }

    private function code(User $user): string
    {
        $base = strtoupper(Str::limit(preg_replace('/[^A-Za-z]/', '', Str::ascii($user->name)) ?: 'REF', 6, ''));
        do {
            $code = $base.random_int(100, 999);
        } while (Affiliate::where('code', $code)->exists() || User::where('affiliate_code', $code)->exists());

        return $code;
    }
}
