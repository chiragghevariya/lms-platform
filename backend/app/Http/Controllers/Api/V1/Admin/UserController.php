<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Admin\UserQuery;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Certificate;
use App\Models\CourseProgress;
use App\Models\Enrollment;
use App\Models\LoginActivity;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate(UserQuery::rules());

        return AdminUserResource::collection(
            UserQuery::build($filters)->with(['roles', 'liveSubscriptions.plan'])
                ->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    /** Everything support needs on one screen (the detail drawer). */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);
        $user->load(['roles', 'liveSubscriptions.plan', 'profile']);

        $spent = Payment::where('user_id', $user->id)->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Refunded])
            ->groupBy('currency')->select('currency', DB::raw('SUM(amount - refunded_amount) as total'))->pluck('total', 'currency')
            ->map(fn ($v) => (int) $v);

        $activity = Activity::query()->with('causer')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id))
                ->orWhere(fn ($q) => $q->where('causer_type', $user->getMorphClass())->where('causer_id', $user->id)))
            ->latest('id')->limit(20)->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id, 'event' => $a->event, 'description' => $a->description,
                'causer' => $a->causer instanceof User ? $a->causer->name : null, 'created_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [
            ...(new AdminUserResource($user))->resolve(),
            'bio' => $user->bio,
            'timezone' => $user->timezone,
            'phone_verified' => $user->phone_verified_at !== null,
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'status_changed_at' => $user->status_changed_at?->toIso8601String(),
            'is_staff' => $user->isStaff(),
            'subscriptions' => SubscriptionResource::collection($user->subscriptions()->with('plan', 'planPrice', 'scheduledPrice.plan')->latest('id')->get()),
            'payments' => PaymentResource::collection($user->payments()->latest('id')->limit(10)->get()),
            'stats' => [
                'enrollments' => Enrollment::where('user_id', $user->id)->count(),
                'completed_courses' => CourseProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count(),
                'certificates' => Certificate::where('user_id', $user->id)->count(),
                'watch_seconds' => (int) DB::table('course_watch_daily')->where('user_id', $user->id)->sum('seconds'),
                'lifetime_value' => $spent,
            ],
            'logins' => LoginActivity::where('user_id', $user->id)->latest('id')->limit(5)->get()
                ->map(fn (LoginActivity $l) => ['event' => $l->event->value, 'ip' => $l->ip_address, 'at' => $l->created_at->toIso8601String()]),
            'activity' => $activity,
        ]]);
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        /** @var list<string> $roles */
        $roles = $request->validated('roles');
        $before = $user->getRoleNames()->all();
        $user->syncRoles($roles);

        activity('admin')->causedBy($request->user())->performedOn($user)->event('user.roles')
            ->withProperties(['old' => $before, 'new' => $roles])->log('Changed roles');

        return new UserResource($user->fresh());
    }

    public function updateStatus(Request $request, User $user): AdminUserResource
    {
        $this->authorize('manage', $user);
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => [Rule::requiredIf($request->input('status') !== UserStatus::Active->value), 'nullable', 'string', 'max:500'],
        ]);

        $this->setStatus($user, UserStatus::from($data['status']), $data['reason'] ?? null, $request->user());

        return new AdminUserResource($user->load('roles', 'liveSubscriptions.plan'));
    }

    /** Suspend / activate many at once; staff and yourself are skipped. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:suspend,activate'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'reason' => ['required_if:action,suspend', 'nullable', 'string', 'max:500'],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $status = $data['action'] === 'suspend' ? UserStatus::Suspended : UserStatus::Active;

        $done = 0;
        $skipped = 0;
        User::whereIn('uuid', $data['ids'])->with('roles')->get()->each(function (User $u) use ($admin, $status, $data, &$done, &$skipped) {
            if (! $admin->can('manage', $u)) {
                $skipped++;

                return;
            }
            $this->setStatus($u, $status, $data['reason'] ?? null, $admin);
            $done++;
        });

        return response()->json(['data' => ['updated' => $done, 'skipped' => $skipped]]);
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        activity('admin')->causedBy($request->user())->performedOn($user)->event('user.password_reset')->log('Sent password reset link');

        return response()->json([
            'message' => $status === Password::RESET_LINK_SENT ? 'Password reset link sent.' : 'A reset link was sent recently; try again in a minute.',
        ], $status === Password::RESET_LINK_SENT ? 200 : 429);
    }

    private function setStatus(User $user, UserStatus $status, ?string $reason, ?User $admin): void
    {
        $old = $user->status;
        $user->forceFill([
            'status' => $status,
            'status_reason' => $status === UserStatus::Active ? null : $reason,
            'status_changed_at' => now(),
        ])->save();

        if (! $status->canSignIn()) {
            // Sign them out everywhere: API tokens and database sessions.
            PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->delete();
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        }

        activity('admin')->causedBy($admin)->performedOn($user)->event('user.status')
            ->withProperties(['old' => $old->value, 'new' => $status->value, 'reason' => $reason])
            ->log($status === UserStatus::Active ? 'Reactivated account' : ucfirst($status->value).' account');
    }
}
