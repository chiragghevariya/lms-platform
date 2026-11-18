<?php

namespace App\Http\Requests\Admin;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;

class GrantSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Subscription::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid', 'exists:plans,uuid'],
            'ends_at' => ['nullable', 'date', 'after:now'], // null = until revoked
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
