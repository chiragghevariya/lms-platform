<?php

namespace App\Http\Requests\Billing;

use App\Models\PlanPrice;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_price_id' => ['required', 'uuid', 'exists:plan_prices,uuid'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function price(): PlanPrice
    {
        return PlanPrice::where('uuid', $this->validated('plan_price_id'))->with('plan')->firstOrFail();
    }
}
