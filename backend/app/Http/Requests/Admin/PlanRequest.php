<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) or partially update (PATCH) a plan. */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan
            ? ($this->user()?->can('update', $plan) ?? false)
            : ($this->user()?->can('create', Plan::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $req = $plan instanceof Plan ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'slug' => [$req, 'string', 'max:120', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan instanceof Plan ? $plan->id : null)],
            'tier_level' => [$req, 'integer', 'between:1,3'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'features' => ['sometimes', 'nullable', 'array', 'max:40'],
            'features.*.key' => ['required', 'string', 'max:60', 'alpha_dash'],
            'features.*.label' => ['required', 'string', 'max:160'],
            'features.*.value' => ['present'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
