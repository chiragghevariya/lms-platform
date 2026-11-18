<?php

namespace App\Security;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Account-sharing guard: a learner can be signed in on `security.max_devices` devices.
 * A new sign-in pushes out the least recently used device (its token is deleted, or its
 * browser session is rejected on the next request by EnsureDeviceActive). Staff are exempt.
 */
class DeviceLimiter
{
    public const SESSION_KEY = 'device_id';

    public function registerSession(Request $request, User $user): ?UserDevice
    {
        if (! $request->hasSession()) {
            return null;
        }
        $device = $this->create($user, 'session', null, $request);
        $request->session()->put(self::SESSION_KEY, $device->uuid);

        return $device;
    }

    public function registerToken(Request $request, User $user, int $tokenId, string $name): UserDevice
    {
        return $this->create($user, 'token', $tokenId, $request, $name);
    }

    /** Is this browser session's device still signed in? (cached; bust on revoke) */
    public function sessionActive(string $uuid): bool
    {
        return (bool) Cache::remember('device:'.$uuid, 300, fn () => UserDevice::where('uuid', $uuid)->exists());
    }

    public function touch(string $uuid): void
    {
        // At most once every 5 minutes per device.
        if (Cache::add('device-seen:'.$uuid, 1, 300)) {
            UserDevice::where('uuid', $uuid)->update(['last_seen_at' => now()]);
        }
    }

    public function forget(?string $uuid): void
    {
        if ($uuid) {
            UserDevice::where('uuid', $uuid)->delete();
            Cache::forget('device:'.$uuid);
        }
    }

    public function forgetToken(int $tokenId): void
    {
        // Callers must have verified the token belongs to the acting user.
        UserDevice::where('token_id', $tokenId)->get()->each(fn (UserDevice $d) => $this->forget($d->uuid));
    }

    private function create(User $user, string $kind, ?int $tokenId, Request $request, ?string $name = null): UserDevice
    {
        return DB::transaction(function () use ($user, $kind, $tokenId, $request, $name) {
            $device = UserDevice::create([
                'user_id' => $user->id,
                'kind' => $kind,
                'token_id' => $tokenId,
                'name' => $name ?? mb_substr((string) $request->userAgent(), 0, 120),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ]);

            if (! $user->isStaff()) {
                $this->enforce($user, $device);
            }

            return $device;
        });
    }

    private function enforce(User $user, UserDevice $keep): void
    {
        $max = max(1, (int) config('security.max_devices', 2));
        $excess = UserDevice::where('user_id', $user->id)->whereKeyNot($keep->id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->skip($max - 1)->take(PHP_INT_MAX)->get();

        foreach ($excess as $old) {
            if ($old->token_id) {
                PersonalAccessToken::whereKey($old->token_id)->delete();
            }
            $this->forget($old->uuid);
        }
    }
}
