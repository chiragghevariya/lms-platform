<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Models\InstructorProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Public teaching profile + private payout details (encrypted; only masked values are returned). */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->user($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'expertise' => ['sometimes', 'array', 'max:10'],
            'expertise.*' => ['string', 'max:40'],
            'website' => ['sometimes', 'nullable', 'url:https', 'max:300'],
            'social_links' => ['sometimes', 'array'],
            'social_links.*' => ['nullable', 'url:https', 'max:300'],
            'payout_method' => ['sometimes', 'nullable', Rule::in(['bank', 'upi', 'paypal'])],
            'payout_details' => ['sometimes', 'nullable', 'array'],
            'payout_details.account_name' => ['required_if:payout_method,bank', 'nullable', 'string', 'max:120'],
            'payout_details.account_number' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^\d{6,18}$/'],
            'payout_details.ifsc' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'payout_details.upi_id' => ['required_if:payout_method,upi', 'nullable', 'string', 'regex:/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/'],
            'payout_details.paypal_email' => ['required_if:payout_method,paypal', 'nullable', 'email', 'max:254'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $profile = $user->profileOrNew();
        $profile->fill(array_intersect_key($data, array_flip(['headline', 'expertise', 'website', 'social_links'])))->save();
        if (array_key_exists('bio', $data)) {
            $user->forceFill(['bio' => $data['bio']])->save();
        }

        $instructor = InstructorProfile::firstOrNew(['user_id' => $user->id]);
        if (array_key_exists('payout_method', $data)) {
            $method = $data['payout_method'];
            $keys = ['bank' => ['account_name', 'account_number', 'ifsc'], 'upi' => ['upi_id'], 'paypal' => ['paypal_email']][$method] ?? [];
            $instructor->payout_method = $method;
            $instructor->payout_details = $method ? array_intersect_key((array) ($data['payout_details'] ?? []), array_flip($keys)) : null;
        }
        if (array_key_exists('tax_id', $data)) {
            $instructor->tax_id = $data['tax_id'] ? strtoupper(trim($data['tax_id'])) : null;
        }
        $instructor->save();

        return response()->json(['data' => $this->payload($user->fresh() ?? $user)]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $profile = $user->profileOrNew();
        $instructor = InstructorProfile::firstOrNew(['user_id' => $user->id]);

        return [
            'headline' => $profile->headline,
            'bio' => $user->bio,
            'expertise' => $profile->expertise ?? [],
            'website' => $profile->website,
            'social_links' => (object) ($profile->social_links ?? []),
            'revenue_share_percent' => $instructor->sharePercent(),
            'pool_percent' => (int) config('earnings.pool_percent'),
            'payout' => [
                'method' => $instructor->payout_method,
                'summary' => self::maskedPayout($instructor),
                'tax_id' => $instructor->tax_id ? Str::mask($instructor->tax_id, '•', 0, max(0, strlen($instructor->tax_id) - 4)) : null,
                'complete' => $instructor->payout_method !== null && ! empty($instructor->payout_details),
            ],
        ];
    }

    public static function maskedPayout(InstructorProfile $p): ?string
    {
        $d = $p->payout_details ?? [];

        return match ($p->payout_method) {
            'bank' => ($d['account_name'] ?? '').' · A/C ••••'.substr((string) ($d['account_number'] ?? ''), -4).' · '.($d['ifsc'] ?? ''),
            'upi' => preg_replace('/^(.{2}).*(@.*)$/', '$1•••$2', (string) ($d['upi_id'] ?? '')),
            'paypal' => preg_replace('/^(.{2}).*(@.*)$/', '$1•••$2', (string) ($d['paypal_email'] ?? '')),
            default => null,
        };
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
