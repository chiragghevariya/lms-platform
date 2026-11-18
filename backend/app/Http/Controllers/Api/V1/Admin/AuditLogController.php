<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/** Who did what, when: admin actions and changes to admin-managed records. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'causer' => ['nullable', 'string'],         // user uuid
            'subject_type' => ['nullable', 'string', 'max:40'], // e.g. "Plan", "User", "Payment"
            'event' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Activity::query()->with(['causer', 'subject'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('description', 'like', '%'.addcslashes((string) $s, '%_\\').'%'))
            ->when($f['causer'] ?? null, fn ($q, $uuid) => $q->where('causer_type', (new User)->getMorphClass())
                ->whereIn('causer_id', User::where('uuid', $uuid)->select('id')))
            ->when($f['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', 'like', '%\\'.Str::studly((string) $t)))
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse((string) $d)))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse((string) $d)->addDay()))
            ->latest('id')->paginate($f['per_page'] ?? 30)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Activity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'subject' => $a->subject_type ? [
                    'type' => class_basename($a->subject_type),
                    'id' => $a->subject instanceof Model ? ($a->subject->getAttribute('uuid') ?? $a->subject->getKey()) : $a->subject_id,
                    'label' => $this->label($a->subject),
                ] : null,
                'causer' => $a->causer instanceof User ? ['id' => $a->causer->uuid, 'name' => $a->causer->name, 'email' => $a->causer->email] : null,
                'changes' => $a->changes()->all(),
                'properties' => $a->properties->except(['attributes', 'old'])->all(),
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    private function label(mixed $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }
        foreach (['name', 'title', 'code', 'question', 'invoice_number', 'email', 'key', 'slug', 'message'] as $attr) {
            $v = $subject->getAttribute($attr);
            if (is_string($v) && $v !== '') {
                return Str::limit($v, 80);
            }
        }

        return null;
    }
}
