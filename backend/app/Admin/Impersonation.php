<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Http\Request;

/** "View as user" state, kept in the admin's own (server-side) session. */
class Impersonation
{
    public const KEY = 'impersonator_id';

    public static function impersonatorId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }
        $id = $request->session()->get(self::KEY);

        return is_int($id) ? $id : null;
    }

    public static function impersonator(Request $request): ?User
    {
        $id = self::impersonatorId($request);

        return $id ? User::find($id) : null;
    }
}
