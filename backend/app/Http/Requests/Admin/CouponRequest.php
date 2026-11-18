<?php

namespace App\Http\Requests\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Coupon::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $coupon = $this->route('coupon');
        $creating = ! $coupon instanceof Coupon;

        return [
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('coupons', 'code')],
            'type' => [$creating ? 'required' : 'prohibited', Rule::enum(CouponType::class)],
            'value' => [$creating ? 'required' : 'prohibited', 'integer', 'min:1', Rule::when($this->input('type') === 'percent', 'max:100')],
            'currency' => [Rule::requiredIf($this->input('type') === 'fixed'), 'nullable', Rule::in(config('billing.supported_currencies'))],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'is_active' => ['sometimes', 'boolean'],
            'razorpay_offer_id' => ['sometimes', 'nullable', 'string', 'max:64', 'starts_with:offer_'],
            'plan_ids' => ['sometimes', 'array'],
            'plan_ids.*' => ['uuid', 'exists:plans,uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
