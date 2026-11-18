<?php

namespace App\Services;

use App\Affiliates\Identity;
use App\Enums\LoginEvent;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoginActivityRecorder
{
    public function __construct(private readonly Request $request) {}

    public function record(LoginEvent $event, ?User $user = null, ?string $email = null, ?string $channel = null): LoginActivity
    {
        return LoginActivity::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'event' => $event,
            'channel' => $channel ?? ($this->request->filled('device_name') ? 'token' : 'web'),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'device_hash' => Identity::deviceFromRequest(),
        ]);
    }
}
