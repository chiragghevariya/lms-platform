<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\BeginSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUser $authenticate, BeginSession $begin): JsonResponse
    {
        $user = $authenticate->handle($request->string('email')->value(), $request->string('password')->value());
        $device = $request->string('device_name')->trim()->value();

        return response()->json(['data' => $begin->handle(
            $request, $user, $request->boolean('remember'), $device, $device !== '' ? 'token' : 'web',
        )]);
    }
}
