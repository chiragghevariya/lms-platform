<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaign;
use App\Models\Campaign;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Platform\CampaignNotification;
use App\Notifications\Platform\RetryNotification;
use App\Notify\Channels;
use App\Notify\Segment;
use App\Notify\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Templates, campaigns and delivery reports. */
class NotificationAdminController extends Controller
{
    // ---- Templates ------------------------------------------------------

    public function templates(Templates $templates): JsonResponse
    {
        $out = [];
        foreach ((array) config('notify.events') as $event => $e) {
            $channels = array_values(array_filter(Channels::ALL, fn ($ch) => ($e['channels'][$ch] ?? false) || in_array($ch, $e['mandatory'] ?? [], true)));
            $out[] = [
                'event' => $event, 'label' => $e['label'], 'category' => $e['category'], 'vars' => $e['vars'] ?? [],
                'channels' => array_map(function (string $ch) use ($event, $templates) {
                    $t = $templates->get($event, $ch);

                    return ['channel' => $ch, 'customised' => NotificationTemplate::where(['event' => $event, 'channel' => $ch])->exists()] + $t;
                }, $channels),
            ];
        }

        return response()->json(['data' => $out]);
    }

    public function updateTemplate(Request $request, Templates $templates, string $event, string $channel): JsonResponse
    {
        abort_unless(config("notify.events.{$event}") && in_array($channel, Channels::ALL, true), 404);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'action_text' => ['nullable', 'string', 'max:60'],
            'whatsapp_template' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'whatsapp_language' => ['nullable', 'string', 'max:12'],
            'whatsapp_params' => ['nullable', 'array', 'max:10'],
            'whatsapp_params.*' => ['string', 'max:60'],
        ]);
        $unknown = $this->unknownVars($event, implode(' ', array_filter([$data['subject'] ?? '', $data['body'] ?? '', implode(' ', $data['whatsapp_params'] ?? [])])));
        if ($unknown) {
            return response()->json(['message' => 'Unknown variables: '.implode(', ', $unknown), 'errors' => ['body' => ['Unknown variables: '.implode(', ', $unknown)]]], 422);
        }
        /** @var User $admin */
        $admin = $request->user();
        NotificationTemplate::updateOrCreate(['event' => $event, 'channel' => $channel], $data + ['is_active' => true, 'updated_by' => $admin->id]);
        $templates->forget();

        return response()->json(['data' => ['channel' => $channel] + $templates->get($event, $channel)]);
    }

    public function resetTemplate(Templates $templates, string $event, string $channel): JsonResponse
    {
        NotificationTemplate::where(['event' => $event, 'channel' => $channel])->delete();
        $templates->forget();

        return response()->json(['data' => ['channel' => $channel] + $templates->get($event, $channel)]);
    }

    /** Renders a subject/body with sample values (the editor's live preview). */
    public function preview(Request $request, string $event): JsonResponse
    {
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:20000'], 'channel' => ['required', Rule::in(Channels::ALL)]]);
        $sample = collect((array) config("notify.events.{$event}.vars", []))->mapWithKeys(fn ($v) => [$v => match ($v) {
            'name' => 'Asha', 'url' => config('lms.frontend_url').'/app', 'amount' => '₹999', 'plan' => 'Pro', 'course' => 'Meta Ads from Zero',
            'date' => now()->addDays(7)->format('j M Y'), 'when' => now()->addDay()->format('D j M, g:i A'), 'code' => '482913', 'minutes' => '10',
            default => Str::headline($v),
        }])->all();
        $md = $data['channel'] === 'mail';

        return response()->json(['data' => [
            'subject' => Templates::render($data['subject'] ?? '', $sample),
            'body' => $md ? Str::markdown(Templates::render($data['body'] ?? '', $sample, true), ['html_input' => 'escape', 'allow_unsafe_links' => false]) : Templates::render($data['body'] ?? '', $sample),
            'html' => $md,
            'unknown_vars' => $this->unknownVars($event, ($data['subject'] ?? '').' '.($data['body'] ?? '')),
        ]]);
    }

    // ---- Campaigns ------------------------------------------------------

    public function campaigns(): JsonResponse
    {
        $rows = Campaign::with('creator')->latest('id')->limit(100)->get();
        $stats = $this->campaignStats($rows->pluck('id')->all());

        return response()->json(['data' => $rows->map(fn (Campaign $c) => $this->campaignPayload($c, $stats[$c->id] ?? []))->values()]);
    }

    public function audience(Request $request): JsonResponse
    {
        $segment = $this->validSegment($request);
        $q = Segment::query($segment);

        return response()->json(['data' => [
            'count' => (clone $q)->count(),
            'whatsapp_reachable' => (clone $q)->whereNotNull('phone_verified_at')->whereNotNull('whatsapp_opt_in_at')
                ->where(fn ($w) => $w->whereNull('whatsapp_opt_out_at')->orWhereColumn('whatsapp_opt_out_at', '<', 'whatsapp_opt_in_at'))->count(),
            'sample' => (clone $q)->limit(5)->get(['name', 'email'])->map(fn (User $u) => ['name' => $u->name, 'email' => Channels::mask($u->email)]),
        ]]);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $c = Campaign::create($this->validCampaign($request) + ['created_by' => $admin->id, 'status' => 'draft']);

        return response()->json(['data' => $this->campaignPayload($c->load('creator'), [])], 201);
    }

    public function updateCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->status === 'draft', 409, 'Only drafts can be edited.');
        $campaign->fill($this->validCampaign($request))->save();

        return response()->json(['data' => $this->campaignPayload($campaign->load('creator'), [])]);
    }

    /** Send now, or schedule (scheduled_at). */
    public function schedule(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless(in_array($campaign->status, ['draft', 'scheduled'], true), 409, 'This campaign was already sent.');
        $data = $request->validate(['scheduled_at' => ['nullable', 'date', 'after:now']]);
        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $data['scheduled_at'] ?? now(), 'audience_count' => Segment::query($campaign->segment)->count()])->save();
        if (! isset($data['scheduled_at'])) {
            SendCampaign::dispatch($campaign->id);
        }

        return response()->json(['data' => $this->campaignPayload($campaign->fresh('creator') ?? $campaign, [])]);
    }

    public function cancel(Campaign $campaign): JsonResponse
    {
        abort_unless(in_array($campaign->status, ['draft', 'scheduled', 'sending'], true), 409, 'Nothing to cancel.');
        $campaign->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['data' => ['status' => 'cancelled']]);
    }

    /** Sends the campaign to the admin only (all chosen channels they can receive). */
    public function test(Request $request, Campaign $campaign): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $n = new CampaignNotification($campaign);
        $n->campaignId = null; // test sends don't count in the campaign's stats
        $admin->notify($n);

        return response()->json(['data' => ['sent_to' => $admin->email]]);
    }

    public function showCampaign(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->campaignPayload($campaign->load('creator'), $this->campaignStats([$campaign->id])[$campaign->id] ?? [])]);
    }

    // ---- Delivery reports -------------------------------------------------

    public function logs(Request $request): JsonResponse
    {
        $f = $request->validate([
            'channel' => ['nullable', Rule::in(Channels::ALL)], 'status' => ['nullable', Rule::in(array_keys(NotificationLog::RANK))],
            'event' => ['nullable', 'string', 'max:48'], 'campaign' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'], 'days' => ['nullable', 'integer', 'between:1,90'],
        ]);
        $base = NotificationLog::query()->where('created_at', '>=', now()->subDays((int) ($f['days'] ?? 7)))
            ->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($f['campaign'] ?? null, fn ($q, $v) => $q->whereIn('campaign_id', Campaign::where('uuid', $v)->select('id')))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")));
        $rows = (clone $base)->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->with('user')->latest('id')->paginate(50);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (NotificationLog $l) => [
                'id' => $l->uuid, 'event' => $l->event, 'channel' => $l->channel, 'status' => $l->status, 'provider' => $l->provider,
                'recipient' => $l->recipient, 'user' => $l->user ? ['name' => $l->user->name, 'email' => Channels::mask($l->user->email)] : null,
                'error' => $l->error, 'attempts' => $l->attempts, 'retryable' => $l->status === 'failed' && $l->vars !== null && $l->user_id !== null,
                'created_at' => $l->created_at?->toIso8601String(), 'sent_at' => $l->sent_at?->toIso8601String(),
                'delivered_at' => $l->delivered_at?->toIso8601String(), 'read_at' => $l->read_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'summary' => (clone $base)->groupBy('channel', 'status')->selectRaw('channel, status, COUNT(*) as n')->get()
                    ->groupBy('channel')->map(fn ($g) => $g->pluck('n', 'status')->map(fn ($n) => (int) $n)),
            ],
        ]);
    }

    public function retry(NotificationLog $log): JsonResponse
    {
        abort_unless($log->status === 'failed' && $log->vars !== null && $log->user_id, 409, 'Only failed sends with stored content can be retried.');
        $user = User::findOrFail($log->user_id);
        $user->notify(new RetryNotification($log->event, $log->vars, $log->channel));
        $log->forceFill(['error' => Str::limit('retried '.now()->toDateTimeString().' · '.$log->error, 480)])->save();

        return response()->json(['data' => ['queued' => true]], 202);
    }

    // ---- helpers ----------------------------------------------------------

    /** @return list<string> */
    private function unknownVars(string $event, string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $m);
        $allowed = [...(array) config("notify.events.{$event}.vars", []), 'name', 'url', 'subject'];

        return array_values(array_diff(array_unique($m[1]), $allowed));
    }

    /** @return array<string, mixed> */
    private function validSegment(Request $request): array
    {
        $v = $request->validate([
            'segment' => ['nullable', 'array'],
            'segment.tiers' => ['nullable', 'array'], 'segment.tiers.*' => [Rule::in(['free', 'starter', 'pro', 'elite'])],
            'segment.status' => ['nullable', 'array'], 'segment.status.*' => [Rule::in(['active', 'past_due', 'cancelled', 'none'])],
            'segment.course_id' => ['nullable', 'uuid'],
            'segment.inactive_days' => ['nullable', 'integer', 'between:1,365'],
            'segment.countries' => ['nullable', 'array'], 'segment.countries.*' => ['string', 'size:2'],
            'segment.roles' => ['nullable', 'array'], 'segment.roles.*' => [Rule::in(['student', 'instructor'])],
        ]);

        return (array) Arr::get($v, 'segment', []);
    }

    /** @return array<string, mixed> */
    private function validCampaign(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => [Rule::in(['mail', 'database', 'whatsapp', 'push'])],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'action_text' => ['nullable', 'string', 'max:60'],
            'action_url' => ['nullable', 'url:https,http', 'max:500'],
            'whatsapp_template' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'whatsapp_language' => ['nullable', 'string', 'max:12'],
            'whatsapp_params' => ['nullable', 'array', 'max:10'], 'whatsapp_params.*' => ['string', 'max:200'],
        ]);

        // Each chosen channel needs its content (required_if can't look inside the channels array).
        $missing = array_filter([
            'body' => in_array('mail', $data['channels'], true) && blank($data['body'] ?? null) ? 'Email campaigns need a message.' : null,
            'whatsapp_template' => in_array('whatsapp', $data['channels'], true) && blank($data['whatsapp_template'] ?? null) ? 'Pick an approved WhatsApp template.' : null,
        ]);
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        return $data + ['segment' => $this->validSegment($request)];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, array<string, int>>>
     */
    private function campaignStats(array $ids): array
    {
        $out = [];
        DB::table('notification_logs')->whereIn('campaign_id', $ids)->groupBy('campaign_id', 'channel', 'status')
            ->selectRaw('campaign_id, channel, status, COUNT(*) as n')->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->campaign_id][(string) $r->channel][(string) $r->status] = (int) $r->n;
            });

        return $out;
    }

    /**
     * @param  array<string, array<string, int>>  $stats
     * @return array<string, mixed>
     */
    private function campaignPayload(Campaign $c, array $stats): array
    {
        return [
            'id' => $c->uuid, 'name' => $c->name, 'channels' => $c->channels, 'segment' => $c->segment, 'status' => $c->status,
            'subject' => $c->subject, 'body' => $c->body, 'action_text' => $c->action_text, 'action_url' => $c->action_url,
            'whatsapp_template' => $c->whatsapp_template, 'whatsapp_language' => $c->whatsapp_language, 'whatsapp_params' => $c->whatsapp_params ?? [],
            'scheduled_at' => $c->scheduled_at?->toIso8601String(), 'started_at' => $c->started_at?->toIso8601String(), 'finished_at' => $c->finished_at?->toIso8601String(),
            'audience_count' => $c->audience_count, 'created_by' => $c->creator->name, 'created_at' => $c->created_at?->toIso8601String(),
            'stats' => (object) $stats,
        ];
    }
}
