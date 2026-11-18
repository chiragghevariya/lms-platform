<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Email unsubscribe links (signed). GET shows a confirmation with a button; POST unsubscribes —
 * also what mail clients call for RFC 8058 one-click (List-Unsubscribe-Post).
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, string $user, string $event): View
    {
        $label = (string) config("notify.events.{$event}.label", 'these emails');
        abort_unless(User::where('uuid', $user)->exists() && config("notify.events.{$event}"), 404);

        return view('notifications.unsubscribe', ['label' => $label, 'action' => $request->fullUrl(), 'done' => false]);
    }

    public function store(Request $request, string $user, string $event): View|Response
    {
        $u = User::where('uuid', $user)->firstOrFail();
        abort_unless(config("notify.events.{$event}") && ! in_array('mail', (array) config("notify.events.{$event}.mandatory", []), true), 404);
        NotificationPreference::updateOrCreate(['user_id' => $u->id, 'event' => $event, 'channel' => 'mail'], ['enabled' => false]);

        return $request->has('List-Unsubscribe') || $request->input('List-Unsubscribe') === 'One-Click'
            ? response()->noContent()
            : view('notifications.unsubscribe', ['label' => (string) config("notify.events.{$event}.label"), 'action' => null, 'done' => true]);
    }
}
