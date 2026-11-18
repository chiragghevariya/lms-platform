<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SiteSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        $secrets = $request->user()?->can(Permission::SettingsSecrets->value) ?? false;

        return response()->json(['data' => [
            'values' => $this->settings->forAdmin($secrets),
            'can_manage_secrets' => $secrets,
            'webhooks' => [
                'stripe' => url('/api/v1/webhooks/stripe'),
                'razorpay' => url('/api/v1/webhooks/razorpay'),
            ],
        ]]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        abort_unless(array_key_exists($group, SiteSettings::SCHEMA), 404);
        /** @var User $user */
        $user = $request->user();
        $hasSecrets = collect(SiteSettings::SCHEMA[$group])->contains(fn ($d) => $d['secret'] ?? false);
        abort_if(
            (in_array($group, SiteSettings::SECRET_GROUPS, true) || ($hasSecrets && (collect($this->secretFields($group))->contains(fn ($f) => $request->filled($f)) || $request->filled('clear'))))
                && ! $user->can(Permission::SettingsSecrets->value),
            403,
            'Only a super admin can change keys and secrets.',
        );

        $request->validate(['clear' => ['sometimes', 'array'], 'clear.*' => ['string']]);
        /** @var list<string> $clear */
        $clear = (array) $request->input('clear', []);
        $changed = $this->settings->update($group, $request->except('clear'), $user, $clear);

        return response()->json([
            'data' => $this->settings->forAdmin($user->can(Permission::SettingsSecrets->value))[$group] ?? [],
            'meta' => ['changed' => $changed],
        ]);
    }

    /** @return list<string> */
    private function secretFields(string $group): array
    {
        return array_keys(array_filter(SiteSettings::SCHEMA[$group], fn ($d) => $d['secret'] ?? false));
    }
}
