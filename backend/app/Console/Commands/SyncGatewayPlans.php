<?php

namespace App\Console\Commands;

use App\Billing\Gateways\RazorpayClient;
use App\Models\PlanPrice;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;

/**
 * Creates missing Stripe Prices / Razorpay Plans for local plan_prices (use with test keys
 * to set up a sandbox quickly). Existing gateway ids are left untouched.
 */
class SyncGatewayPlans extends Command
{
    protected $signature = 'billing:sync-gateway-plans {--dry-run : Only list what would be created}';

    protected $description = 'Create Stripe prices (non-INR) and Razorpay plans (INR) for plan prices missing gateway ids';

    public function handle(RazorpayClient $razorpay): int
    {
        $prices = PlanPrice::with('plan')->active()->get();

        foreach ($prices as $price) {
            $label = "{$price->plan->name} {$price->interval->value} {$price->currency}";
            $isInr = $price->currency === 'INR';

            if ($isInr && ! $price->isLifetime() && ! $price->razorpay_plan_id) {
                $this->line("Razorpay plan: {$label}");
                if (! $this->option('dry-run')) {
                    $plan = $razorpay->post('/plans', [
                        'period' => $price->interval->value === 'year' ? 'yearly' : 'monthly',
                        'interval' => 1,
                        'item' => ['name' => $price->plan->name.' ('.$price->interval->value.')', 'amount' => $price->amount, 'currency' => 'INR'],
                        'notes' => ['plan_price_uuid' => $price->uuid],
                    ]);
                    $price->update(['razorpay_plan_id' => $plan['id']]);
                }
            }

            if (! $isInr && ! $price->stripe_price_id) {
                $this->line("Stripe price: {$label}");
                if (! $this->option('dry-run')) {
                    $stripe = Cashier::stripe();
                    $product = $stripe->products->create(['name' => $price->plan->name, 'metadata' => ['plan_uuid' => $price->plan->uuid]]);
                    $params = ['product' => $product->id, 'unit_amount' => $price->amount, 'currency' => strtolower($price->currency), 'metadata' => ['plan_price_uuid' => $price->uuid]];
                    if (! $price->isLifetime()) {
                        $params['recurring'] = ['interval' => $price->interval->value];
                    }
                    $price->update(['stripe_price_id' => $stripe->prices->create($params)->id]);
                }
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
