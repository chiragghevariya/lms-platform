<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'timezone' => ['sometimes', 'required', 'timezone:all_with_bc'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'profile' => ['sometimes', 'array'],
            'profile.headline' => ['nullable', 'string', 'max:160'],
            'profile.website' => ['nullable', 'url:http,https', 'max:255'],
            'profile.city' => ['nullable', 'string', 'max:100'],
            'profile.state' => ['nullable', 'string', 'max:100'],
            'profile.occupation' => ['nullable', 'string', 'max:120'],
            'profile.date_of_birth' => ['nullable', 'date', 'before:-13 years'],
            'profile.social_links' => ['nullable', 'array:linkedin,twitter,instagram,youtube,github'],
            'profile.social_links.*' => ['nullable', 'url:http,https', 'max:255'],
            'profile.interests' => ['nullable', 'array', 'max:20'],
            'profile.interests.*' => ['string', 'max:50'],
            'profile.expertise' => ['nullable', 'array', 'max:20'],
            'profile.expertise.*' => ['string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }

    public function messages(): array
    {
        return ['profile.date_of_birth.before' => 'You must be at least 13 years old.'];
    }
}
