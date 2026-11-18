<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\LoginEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\LoginActivityRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordResetController extends Controller
{
    /** Always responds 200 to avoid leaking which emails are registered. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => strtolower($request->string('email')->value())]);

        return response()->json(['message' => __('If that email is registered, a reset link has been sent.')]);
    }

    public function reset(ResetPasswordRequest $request, LoginActivityRecorder $activity): JsonResponse
    {
        $status = Password::reset(
            [...$request->only('password', 'password_confirmation', 'token'), 'email' => strtolower($request->string('email')->value())],
            function (User $user, string $password) use ($activity) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->delete();
                $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
                $activity->record(LoginEvent::PasswordReset, $user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
