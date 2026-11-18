<?php

namespace App\Affiliates\Payouts;

use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\User;
use App\Notifications\Platform\CommissionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Monthly batch of approved balances above the minimum, CSV export, mark paid / failed. */
class PayoutService
{
    public function __construct(private readonly PayoutGateway $gateway) {}

    /** @return array<string, int> balance per currency of approved, unbatched commissions (incl. clawbacks) */
    public function balances(Affiliate $affiliate): array
    {
        return Commission::where('affiliate_id', $affiliate->id)->where('status', 'approved')->whereNull('payout_id')
            ->groupBy('currency')->selectRaw('currency, SUM(amount - reversed_amount) as total')->pluck('total', 'currency')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** @return int payouts created */
    public function batch(string $period): int
    {
        $created = 0;
        Affiliate::where('status', 'approved')->whereNotNull('payout_method')->chunkById(100, function ($affiliates) use ($period, &$created) {
            foreach ($affiliates as $affiliate) {
                foreach ($this->balances($affiliate) as $currency => $balance) {
                    $minimum = (int) (config('affiliates.payouts.minimum')[$currency] ?? PHP_INT_MAX);
                    if ($balance < $minimum || AffiliatePayout::where(['affiliate_id' => $affiliate->id, 'period' => $period, 'currency' => $currency])->exists()) {
                        continue;
                    }
                    DB::transaction(function () use ($affiliate, $currency, $period, &$created) {
                        $ids = Commission::where('affiliate_id', $affiliate->id)->where('status', 'approved')->whereNull('payout_id')
                            ->where('currency', $currency)->lockForUpdate()->pluck('id');
                        $payout = AffiliatePayout::create([
                            'affiliate_id' => $affiliate->id, 'period' => $period, 'currency' => $currency, 'amount' => 0,
                            'commissions_count' => $ids->count(), 'method' => $affiliate->payout_method, 'gateway' => $this->gateway->name(),
                        ]);
                        Commission::whereIn('id', $ids)->update(['payout_id' => $payout->id]);
                        $this->recalculate($payout);
                        $reference = $this->gateway->send($payout);
                        if ($reference) {
                            $payout->forceFill(['reference' => $reference])->save();
                        }
                        $created++;
                    });
                }
            }
        });

        return $created;
    }

    public function recalculate(AffiliatePayout $payout): void
    {
        $payout->forceFill([
            'amount' => (int) Commission::where('payout_id', $payout->id)->where('status', '!=', 'reversed')->sum(DB::raw('amount - reversed_amount')),
            'commissions_count' => Commission::where('payout_id', $payout->id)->where('status', '!=', 'reversed')->count(),
        ])->save();
    }

    public function markPaid(AffiliatePayout $payout, User $admin, string $reference): AffiliatePayout
    {
        if ($payout->status !== 'pending') {
            throw ValidationException::withMessages(['payout' => 'Only pending payouts can be marked paid.']);
        }
        DB::transaction(function () use ($payout, $admin, $reference) {
            $payout->forceFill(['status' => 'paid', 'reference' => $reference, 'paid_at' => now(), 'paid_by' => $admin->id])->save();
            Commission::where('payout_id', $payout->id)->where('status', 'approved')->update(['status' => 'paid']);
        });
        $payout->loadMissing('affiliate.user');
        $payout->affiliate->user->notify(new CommissionNotification('paid', $payout->amount, $payout->currency, $reference));

        return $payout;
    }

    /** The transfer bounced: release the commissions back into the balance. */
    public function markFailed(AffiliatePayout $payout): AffiliatePayout
    {
        if ($payout->status !== 'pending') {
            throw ValidationException::withMessages(['payout' => 'Only pending payouts can be marked failed.']);
        }
        DB::transaction(function () use ($payout) {
            Commission::where('payout_id', $payout->id)->update(['payout_id' => null]);
            $payout->forceFill(['status' => 'failed'])->save();
        });

        return $payout;
    }

    /** CSV for the finance team (contains decrypted payout details — admin only). */
    public function csv(string $period): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['payout_id', 'affiliate', 'email', 'currency', 'amount', 'method', 'account_name', 'account_number', 'ifsc', 'upi_id', 'paypal_email', 'status', 'reference'], escape: '\\');
        AffiliatePayout::with('affiliate.user')->where('period', $period)->orderBy('id')->each(function (AffiliatePayout $p) use ($out) {
            $d = $p->affiliate->payout_details ?? [];
            fputcsv($out, [
                $p->uuid, self::safe($p->affiliate->user->name), $p->affiliate->user->email, $p->currency, number_format($p->amount / 100, 2, '.', ''),
                $p->method, self::safe($d['account_name'] ?? ''), $d['account_number'] ?? '', $d['ifsc'] ?? '', $d['upi_id'] ?? '', $d['paypal_email'] ?? '',
                $p->status, $p->reference,
            ], escape: '\\');
        });
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Neutralises spreadsheet formula injection ("=HYPERLINK(...)" in a name). */
    private static function safe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
