<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthSessionService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $register, AuthSessionService $sessions): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, phone?: string|null, timezone?: string|null} $data */
        $data = $request->validated();
        $user = $register->handle($data);
        $token = $sessions->start($request, $user);

        return response()->json(['data' => [
            'user' => new UserResource($user->fresh()),
            'token' => $token,
            'token_type' => $token ? 'Bearer' : null,
        ]], 201);
    }
}
