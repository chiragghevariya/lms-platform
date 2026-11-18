<?php

namespace App\Account;

use App\Billing\BillingService;
use App\Billing\GatewayException;
use App\Enums\CourseStatus;
use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * GDPR Art. 17 · DPDP s.12 erasure. Personal data is deleted or anonymised; records we must
 * keep by law (tax invoices: payments rows incl. billing name/address — 8 years under Indian
 * GST rules) stay, linked to an anonymised account. Public contributions (reviews, Q&A)
 * remain but show "Deleted user".
 */
class AccountDeletion
{
    public function __construct(private readonly BillingService $billing, private readonly AvatarService $avatars) {}

    /** @return list<string> reasons the account can't be deleted yet (empty = OK) */
    public function blockers(User $user): array
    {
        $out = [];
        if ($user->isStaff()) {
            $out[] = 'Staff accounts must be demoted by a super admin before they can be deleted.';
        }
        if (Course::where('instructor_id', $user->id)->where('status', CourseStatus::Published)->exists()) {
            $out[] = 'You have published courses. Contact support to transfer or unpublish them first.';
        }
        $balance = DB::table('commissions')->join('affiliates', 'affiliates.id', '=', 'commissions.affiliate_id')
            ->where('affiliates.user_id', $user->id)->where('commissions.status', 'approved')->whereNull('commissions.payout_id')->exists();
        if ($balance) {
            $out[] = 'You have affiliate earnings waiting to be paid out. Delete your account after the next payout.';
        }

        return $out;
    }

    public function delete(User $user): void
    {
        if ($blockers = $this->blockers($user)) {
            throw ValidationException::withMessages(['account' => $blockers]);
        }

        // Stop future charges first: if the gateway refuses, nothing is deleted.
        Subscription::where('user_id', $user->id)->whereIn('status', SubscriptionStatus::live())
            ->where('gateway', '!=', Gateway::Manual)->where('is_lifetime', false)->where('cancel_at_period_end', false)
            ->get()->each(function (Subscription $s) {
                try {
                    $this->billing->cancel($s);
                } catch (GatewayException $e) {
                    throw ValidationException::withMessages(['account' => 'We could not cancel your membership with the payment provider. Please try again or contact support.']);
                }
            });

        $this->avatars->remove($user);

        DB::transaction(function () use ($user) {
            $u = $user->id;
            foreach (['lesson_notes', 'bookmarks', 'push_tokens', 'notification_preferences', 'user_devices', 'phone_otps', 'user_profiles', 'referral_clicks'] as $table) {
                if (DB::getSchemaBuilder()->hasColumn($table, 'user_id')) {
                    DB::table($table)->where('user_id', $u)->delete();
                }
            }
            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $u)->delete();
            DB::table('login_activities')->where('user_id', $u)->update(['ip_address' => null, 'user_agent' => null, 'email' => null]);
            DB::table('instructor_profiles')->where('user_id', $u)->update(['payout_details' => null, 'tax_id' => null]);
            DB::table('affiliates')->where('user_id', $u)->update(['payout_details' => null, 'status' => 'suspended']);
            DB::table('certificates')->where('user_id', $u)->update(['holder_name' => 'Deleted user']);
            PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->delete();
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $u)->delete();
            }

            $user->syncRoles([]);
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'deleted+'.$user->uuid.'@invalid.local',
                'phone' => null, 'phone_verified_at' => null, 'country' => null, 'bio' => null,
                'google_id' => null, 'password' => Str::random(64), 'remember_token' => null,
                'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
                'whatsapp_opt_in_at' => null, 'billing_name' => null, 'billing_address' => null, 'tax_id' => null, 'affiliate_code' => null, 'is_affiliate' => false,
                'status' => UserStatus::Banned, 'status_reason' => 'Account deleted by the user',
                'deletion_requested_at' => now(), 'anonymized_at' => now(),
            ])->save();
            $user->delete(); // soft delete keeps foreign keys (invoices) intact
        });

        activity('privacy')->performedOn($user)->event('account.deleted')->log('Account deleted and anonymised at the user\'s request');
    }
}
