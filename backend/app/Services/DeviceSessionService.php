<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use App\Security\DeviceLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lists and revokes where a user is signed in: browser sessions (database session
 * driver) and mobile personal access tokens. Public ids never expose raw session ids.
 */
class DeviceSessionService
{
    public function __construct(private readonly AuthSessionService $auth) {}

    /** @return Collection<int, array{id: string, type: string, name: string, ip_address: string|null, user_agent: string|null, last_active_at: string|null, is_current: bool}> */
    public function list(Request $request, User $user): Collection
    {
        $currentToken = $this->auth->bearerToken($request);
        $currentSession = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = $this->usesDatabaseSessions()
            ? DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
                ->map(fn (object $s) => [
                    'id' => 'session_'.$this->publicSessionId((string) $s->id),
                    'type' => 'browser',
                    'name' => $this->describeAgent((string) ($s->user_agent ?? '')),
                    'ip_address' => $s->ip_address,
                    'user_agent' => $s->user_agent,
                    'last_active_at' => now()->setTimestamp((int) $s->last_activity)->toIso8601String(),
                    'is_current' => $currentSession !== null && hash_equals((string) $s->id, $currentSession),
                ])
            : collect();

        $tokens = PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->latest('id')->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => 'token_'.$t->getKey(),
                'type' => 'app',
                'name' => $t->name,
                'ip_address' => null,
                'user_agent' => null,
                'last_active_at' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
                'is_current' => $currentToken !== null && $currentToken->is($t),
            ]);

        return $sessions->concat($tokens)->values();
    }

    /** Revokes one entry; returns false if it doesn't belong to the user. */
    public function revoke(User $user, string $publicId): bool
    {
        if (Str::startsWith($publicId, 'token_')) {
            $id = (int) Str::after($publicId, 'token_');
            // Ownership first: only the caller's own token (and then its device slot) is removed.
            $deleted = PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->whereKey($id)->delete() > 0;
            if ($deleted) {
                app(DeviceLimiter::class)->forgetToken($id);
            }

            return $deleted;
        }

        if (Str::startsWith($publicId, 'session_') && $this->usesDatabaseSessions()) {
            $hash = Str::after($publicId, 'session_');
            $match = DB::table('sessions')->where('user_id', $user->id)->pluck('id')
                ->first(fn ($id) => hash_equals($this->publicSessionId((string) $id), $hash));

            return $match !== null && DB::table('sessions')->where('id', $match)->delete() > 0;
        }

        return false;
    }

    /** Signs out every other browser session and app token; keeps the current one. */
    public function revokeOthers(Request $request, User $user): int
    {
        $count = 0;
        $currentToken = $this->auth->bearerToken($request);

        $count += PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)
            ->when($currentToken, fn ($q, PersonalAccessToken $t) => $q->whereKeyNot($t->getKey()))
            ->delete();

        if ($this->usesDatabaseSessions()) {
            $count += DB::table('sessions')->where('user_id', $user->id)
                ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
                ->delete();
        }

        // Their device slots are free again (keep only the device making this request).
        $current = $request->hasSession() ? $request->session()->get(DeviceLimiter::SESSION_KEY) : null;
        UserDevice::where('user_id', $user->id)
            ->when($currentToken, fn ($q, PersonalAccessToken $t) => $q->where(fn ($q) => $q->whereNull('token_id')->orWhere('token_id', '!=', $t->getKey())))
            ->when(is_string($current), fn ($q) => $q->where('uuid', '!=', $current))
            ->get()->each(fn (UserDevice $d) => app(DeviceLimiter::class)->forget($d->uuid));

        // Invalidate "remember me" cookies held by other browsers.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return $count;
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    private function publicSessionId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    private function describeAgent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return "{$browser} on {$os}";
    }
}
