<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class BillingDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'billing_name' => ['nullable', 'string', 'max:160'],
            'billing_address' => ['nullable', 'array:line1,line2,city,state,postal_code,country'],
            'billing_address.*' => ['nullable', 'string', 'max:160'],
            // GSTIN: 15 chars (2 digits state + PAN + entity + Z + checksum); VAT ids vary — keep permissive.
            'tax_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9\-]{5,32}$/i'],
        ];
    }
}
