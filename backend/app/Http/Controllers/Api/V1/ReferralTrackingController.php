<?php

namespace App\Http\Controllers\Api\V1;

use App\Affiliates\ReferralTracker;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The SPA calls this when a page loads with ?ref=CODE or ?invite=CODE. Sets the attribution cookie(s). */
class ReferralTrackingController extends Controller
{
    public function __invoke(Request $request, ReferralTracker $tracker): Response
    {
        $data = $request->validate([
            'ref' => ['nullable', 'string', 'max:32', 'alpha_num'],
            'invite' => ['nullable', 'string', 'max:16', 'alpha_num'],
            'landing_url' => ['nullable', 'string', 'max:1000'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'utm' => ['nullable', 'array'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
        ]);
        $response = response()->noContent();
        if ($request->user('sanctum')) {
            return $response; // existing members aren't re-attributed
        }
        if (! empty($data['ref']) && ($cookie = $tracker->click($data['ref'], [
            'landing_url' => $data['landing_url'] ?? null, 'referrer' => $data['referrer'] ?? null,
            'utm' => ReferralTracker::utmFrom((array) ($data['utm'] ?? [])) ?: null, 'visitor_id' => $data['visitor_id'] ?? null,
        ]))) {
            $response->withCookie($cookie);
        }
        if (! empty($data['invite']) && ($cookie = $tracker->invite($data['invite']))) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
