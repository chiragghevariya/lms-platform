<?php

namespace App\Http\Controllers\Api\V1;

use App\Account\AccountDataExport;
use App\Account\AccountDeletion;
use App\Admin\Impersonation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Privacy rights: download my data, delete my account. */
class AccountController extends Controller
{
    public function export(Request $request, AccountDataExport $export): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        activity('privacy')->causedBy($user)->performedOn($user)->event('account.exported')->log('Downloaded personal data');

        return response()->json($export->build($user), 200, [
            'Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** What would stop a deletion right now (shown before the user confirms). */
    public function deletionCheck(Request $request, AccountDeletion $deletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'blockers' => $deletion->blockers($user),
            'requires_password' => $user->google_id === null, // Google-only accounts confirm by typing DELETE
        ]]);
    }

    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if(Impersonation::impersonatorId($request) !== null, 403, 'Accounts cannot be deleted while impersonating.');

        $data = $request->validate([
            'password' => ['nullable', 'string'],
            'confirm' => ['required', 'in:DELETE'],
        ]);
        if (! $user->google_id || filled($data['password'] ?? null)) {
            if (! Hash::check((string) ($data['password'] ?? ''), $user->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
            }
        }

        $deletion->delete($user);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Your account has been deleted. Invoices are kept for tax purposes, without access to your account.']);
    }
}
