<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** interval/currency are fixed once created (subscriptions reference the price). */
class PlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $price = $this->route('price');
        $creating = ! $price instanceof PlanPrice;
        $ignore = $creating ? null : $price->id;

        return [
            'interval' => [$creating ? 'required' : 'prohibited', Rule::enum(BillingInterval::class)],
            'currency' => [$creating ? 'required' : 'prohibited', Rule::in(config('billing.supported_currencies'))],
            'amount' => [$creating ? 'required' : 'sometimes', 'integer', 'min:100'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
            'stripe_price_id' => ['sometimes', 'nullable', 'string', 'max:120', 'starts_with:price_', Rule::unique('plan_prices')->ignore($ignore)],
            'razorpay_plan_id' => ['sometimes', 'nullable', 'string', 'max:120', 'starts_with:plan_', Rule::unique('plan_prices')->ignore($ignore)],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Plan $plan */
            $plan = $this->route('plan');
            $price = $this->route('price');
            $interval = $price instanceof PlanPrice ? $price->interval->value : $this->input('interval');
            $currency = $price instanceof PlanPrice ? $price->currency : $this->input('currency');
            $activating = $this->boolean('is_active', $price instanceof PlanPrice ? $price->is_active : true);

            if ($activating && $plan->prices()->active()->where('interval', $interval)->where('currency', $currency)
                ->when($price instanceof PlanPrice, fn ($q) => $q->whereKeyNot($price->id))->exists()) {
                $v->errors()->add('interval', 'An active price for this interval and currency already exists. Deactivate it first.');
            }
            if ($interval === BillingInterval::Lifetime->value && (int) $this->input('trial_days', 0) > 0) {
                $v->errors()->add('trial_days', 'Lifetime prices cannot have a trial.');
            }
        }];
    }
}
