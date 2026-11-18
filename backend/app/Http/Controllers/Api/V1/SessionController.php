<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoginEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DeviceSessionService;
use App\Services\LoginActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    public function __construct(private readonly DeviceSessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->sessions->list($request, $user)]);
    }

    public function destroy(Request $request, string $session): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->sessions->revoke($user, $session), 404);

        return response()->noContent();
    }

    public function destroyOthers(Request $request, LoginActivityRecorder $activity): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();
        $count = $this->sessions->revokeOthers($request, $user);
        $activity->record(LoginEvent::LogoutOthers, $user);

        return response()->json(['message' => 'Signed out of other devices.', 'data' => ['revoked' => $count]]);
    }
}
