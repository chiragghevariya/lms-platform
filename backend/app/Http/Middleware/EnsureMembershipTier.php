<?php

namespace App\Http\Middleware;

use App\Enums\MembershipTier;
use App\Services\MembershipService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `tier:2` (or `tier:pro`) requires that tier or higher. Staff bypass.
 * Responds 402 so clients can show an upgrade prompt.
 */
class EnsureMembershipTier
{
    public function __construct(private readonly MembershipService $membership) {}

    public function handle(Request $request, Closure $next, string $tier): Response
    {
        $required = MembershipTier::parse($tier);

        if (! $this->membership->canAccess($request->user(), $required)) {
            return response()->json([
                'message' => "This content requires the {$required->label()} membership or higher.",
                'code' => 'membership_required',
                'required_tier' => $required->value,
                'required_tier_slug' => $required->slug(),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $next($request);
    }
}
