<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\PushToken;
use App\Models\User;
use App\Notify\Channels;
use App\Notify\NotificationRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/** In-app bell, preferences, WhatsApp consent, push device tokens. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = $user->notifications()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id, 'event' => $n->data['event'] ?? null, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'unread' => $user->unreadNotifications()->count()],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->user($request)->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $id): Response
    {
        $this->user($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    public function markAllRead(Request $request): Response
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }

    /** Matrix of events × channels with what's on, what's locked and what's reachable. */
    public function preferences(Request $request, NotificationRouter $router): JsonResponse
    {
        $user = $this->user($request);
        $isStaff = $user->isStaff() || $user->hasRole('instructor');
        $available = [
            'mail' => true, 'database' => true,
            'whatsapp' => Channels::whatsappConfigured(),
            'push' => Channels::pushConfigured(),
            'sms' => (bool) $user->phone_verified_at,
        ];
        $groups = [];
        foreach ((array) config('notify.events') as $key => $e) {
            if ($key === 'otp' || (in_array($e['category'], ['admin', 'teaching'], true) && ! $isStaff)) {
                continue;
            }
            $groups[$e['category']][] = [
                'event' => $key, 'label' => $e['label'],
                'channels' => collect(Channels::ALL)->filter(fn ($ch) => ($e['channels'][$ch] ?? false) || in_array($ch, $e['mandatory'] ?? [], true))
                    ->mapWithKeys(fn ($ch) => [$ch => [
                        'enabled' => $router->enabled($user, $key, $ch),
                        'locked' => in_array($ch, $e['mandatory'] ?? [], true),
                    ]])->all(),
            ];
        }

        return response()->json(['data' => [
            'categories' => collect((array) config('notify.categories'))->filter(fn ($l, $k) => isset($groups[$k]))
                ->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'events' => $groups[$k]])->values(),
            'channels' => $available,
            'whatsapp' => [
                'opted_in' => $user->whatsapp_opt_in_at !== null && ($user->whatsapp_opt_out_at === null || $user->whatsapp_opt_out_at->lt($user->whatsapp_opt_in_at)),
                'opted_in_at' => $user->whatsapp_opt_in_at?->toIso8601String(),
                'phone' => $user->phone ? Channels::mask($user->phone) : null,
                'phone_verified' => $user->phone_verified_at !== null,
            ],
            'push_devices' => PushToken::where('user_id', $user->id)->count(),
        ]]);
    }

    public function updatePreferences(Request $request, NotificationRouter $router): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:200'],
            'changes.*.event' => ['required', Rule::in(array_keys((array) config('notify.events')))],
            'changes.*.channel' => ['required', Rule::in(Channels::ALL)],
            'changes.*.enabled' => ['required', 'boolean'],
        ]);
        $user = $this->user($request);
        foreach ($data['changes'] as $c) {
            if (in_array($c['channel'], (array) config("notify.events.{$c['event']}.mandatory", []), true)) {
                continue; // can't switch off transactional mail / security codes
            }
            NotificationPreference::updateOrCreate(['user_id' => $user->id, 'event' => $c['event'], 'channel' => $c['channel']], ['enabled' => $c['enabled']]);
        }

        return $this->preferences($request, $router);
    }

    /** WhatsApp is opt-in: consent is timestamped; opting out (or replying STOP) records the time too. */
    public function whatsapp(Request $request): JsonResponse
    {
        $data = $request->validate(['opt_in' => ['required', 'boolean'], 'consent' => ['required_if:opt_in,true', 'accepted_if:opt_in,true']]);
        $user = $this->user($request);
        abort_if($data['opt_in'] && ! $user->phone_verified_at, 422, 'Verify your phone number first.');
        $user->forceFill($data['opt_in'] ? ['whatsapp_opt_in_at' => now(), 'whatsapp_opt_out_at' => null] : ['whatsapp_opt_out_at' => now()])->save();

        return response()->json(['data' => ['opted_in' => (bool) $data['opt_in'], 'at' => now()->toIso8601String()]]);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255'], 'platform' => ['required', Rule::in(['android', 'ios', 'web'])]]);
        PushToken::updateOrCreate(['token' => $data['token']], ['user_id' => $this->user($request)->id, 'platform' => $data['platform'], 'last_used_at' => now()]);

        return response()->json(['data' => ['registered' => true]], 201);
    }

    public function removePushToken(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        PushToken::where('user_id', $this->user($request)->id)->where('token', $data['token'])->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
