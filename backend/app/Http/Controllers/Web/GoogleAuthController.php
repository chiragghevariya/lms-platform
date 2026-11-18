<?php

namespace App\Http\Controllers\Web;

use App\Actions\Auth\BeginSession;
use App\Actions\Auth\ResolveGoogleUser;
use App\Exceptions\AuthFlowException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Web SPA Google login. Lives in routes/web.php because the OAuth round-trip is a
 * top-level browser navigation that needs the session (state + login).
 */
class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, ResolveGoogleUser $resolve, BeginSession $begin): RedirectResponse
    {
        $frontend = (string) config('lms.frontend_url');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->away($frontend.'/login?error=google_failed');
        }

        try {
            $user = $resolve->handle($googleUser);
        } catch (AuthFlowException $e) {
            return redirect()->away($frontend.'/login?error='.$e->errorCode);
        }

        $result = $begin->handle($request, $user, true, null, 'google');

        if ($result['two_factor_required']) {
            return redirect()->away($frontend.'/two-factor?challenge='.$result['challenge_token']);
        }

        return redirect()->away($frontend.'/auth/callback');
    }
}
