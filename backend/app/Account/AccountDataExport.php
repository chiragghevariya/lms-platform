<?php

namespace App\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GDPR Art. 15/20 · DPDP s.11: everything we hold about a person, as one JSON document.
 * Internal ids are replaced by public ones where they exist; secrets (password hash,
 * 2FA secret, tokens) are never included.
 */
class AccountDataExport
{
    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $u = $user->id;
        $rows = fn (string $table, array $cols, string $fk = 'user_id') => DB::table($table)->where($fk, $u)->get($cols)->map(fn ($r) => (array) $r)->all();
        $courseTitle = fn () => DB::table('courses')->pluck('title', 'id');
        $titles = $courseTitle();
        $withCourse = fn (array $list) => array_map(fn ($r) => ['course' => $titles[$r['course_id']] ?? null, ...array_diff_key($r, ['course_id' => 1])], $list);

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone' => $user->phone,
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'country' => $user->country,
                'timezone' => $user->timezone,
                'bio' => $user->bio,
                'roles' => $user->getRoleNames()->values()->all(),
                'status' => $user->status->value,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'google_linked' => $user->google_id !== null,
                'whatsapp_opt_in_at' => $user->whatsapp_opt_in_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'profile' => $user->profile?->only(['headline', 'website', 'city', 'state', 'occupation', 'date_of_birth', 'social_links', 'interests', 'expertise']),
            'billing_details' => ['name' => $user->getAttribute('billing_name'), 'address' => $user->getAttribute('billing_address'), 'tax_id' => $user->getAttribute('tax_id')],
            'subscriptions' => $user->subscriptions()->with('plan:id,name')->get()->map(fn ($s) => [
                'plan' => $s->plan->name, 'status' => $s->status->value, 'gateway' => $s->gateway->value,
                'started' => $s->created_at?->toIso8601String(), 'current_period_end' => $s->current_period_end?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
            ])->all(),
            'payments' => $user->payments()->get()->map(fn ($p) => [
                'id' => $p->uuid, 'invoice' => $p->invoice_number, 'status' => $p->status->value, 'currency' => $p->currency,
                'amount' => $p->amount, 'tax' => $p->tax_amount, 'refunded' => $p->refunded_amount, 'description' => $p->description,
                'paid_at' => $p->paid_at?->toIso8601String(), 'billing_country' => $p->billing_country,
            ])->all(),
            'learning' => [
                'enrollments' => $withCourse($rows('enrollments', ['course_id', 'source', 'started_at', 'completed_at'])),
                'progress' => $withCourse($rows('course_progress', ['course_id', 'percent', 'completed_at', 'last_activity_at'])),
                'notes' => $rows('lesson_notes', ['lesson_id', 'position_seconds', 'body', 'created_at']),
                'bookmarks' => $withCourse($rows('bookmarks', ['course_id', 'lesson_id', 'position_seconds', 'label', 'created_at'])),
                'reviews' => $withCourse($rows('course_reviews', ['course_id', 'rating', 'comment', 'created_at'])),
                'questions' => $withCourse($rows('course_questions', ['course_id', 'title', 'body', 'created_at'])),
                'answers' => $rows('course_answers', ['body', 'created_at']),
                'assignment_submissions' => $rows('assignment_submissions', ['text', 'status', 'score', 'feedback', 'submitted_at']),
                'quiz_attempts' => $rows('quiz_attempts', ['status', 'score', 'max_score', 'percent', 'passed', 'submitted_at']),
                'certificates' => $rows('certificates', ['verification_code', 'holder_name', 'course_title', 'issued_at']),
                'live_registrations' => $rows('live_session_registrations', ['attended', 'joined_at', 'left_at', 'attended_seconds', 'created_at']),
            ],
            'affiliate' => DB::table('affiliates')->where('user_id', $u)->first(['code', 'status', 'tier', 'approved_at', 'created_at']),
            'notifications' => [
                'preferences' => $rows('notification_preferences', ['event', 'channel', 'enabled']),
                'recent' => DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $u)
                    ->latest('created_at')->limit(500)->get(['type', 'data', 'read_at', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            ],
            'security' => [
                'signed_in_devices' => $rows('user_devices', ['kind', 'name', 'ip_address', 'last_seen_at']),
                'login_activity' => DB::table('login_activities')->where('user_id', $u)->latest('id')->limit(200)->get(['event', 'channel', 'ip_address', 'user_agent', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            ],
        ];
    }
}
