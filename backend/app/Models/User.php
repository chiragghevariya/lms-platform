<?php

namespace App\Models;

use App\Enums\MembershipTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Services\MembershipService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property string|null $country
 * @property string|null $bio
 * @property UserStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property string|null $google_id
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $phone_verified_at
 * @property string|null $stripe_id
 * @property string|null $razorpay_customer_id
 * @property string|null $billing_name
 * @property array<string, string>|null $billing_address
 * @property string|null $tax_id
 * @property string $timezone
 * @property bool $is_affiliate
 * @property Carbon|null $whatsapp_opt_in_at
 * @property Carbon|null $whatsapp_opt_out_at
 * @property string|null $affiliate_code
 * @property Carbon|null $affiliate_activated_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /**
     * Token is a PersonalAccessToken (mobile) or a TransientToken (SPA cookie session).
     *
     * @use HasApiTokens<HasAbilities>
     */
    use Billable, HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicUuid, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'phone', 'country', 'bio', 'password', 'timezone', 'avatar_path'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** Mirrors DB defaults so freshly-created models are complete under strict mode. */
    protected $attributes = [
        'phone' => null,
        'country' => null,
        'bio' => null,
        'status' => 'active',
        'status_reason' => null,
        'status_changed_at' => null,
        'google_id' => null,
        'failed_login_attempts' => 0,
        'locked_until' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'phone_verified_at' => null,
        'avatar_path' => null,
        'timezone' => 'UTC',
        'is_affiliate' => false,
        'affiliate_code' => null,
        'affiliate_activated_at' => null,
        'whatsapp_opt_in_at' => null,
        'whatsapp_opt_out_at' => null,
        'last_login_at' => null,
        'stripe_id' => null,
        'pm_type' => null,
        'pm_last_four' => null,
        'trial_ends_at' => null,
        'razorpay_customer_id' => null,
        'billing_name' => null,
        'billing_address' => null,
        'tax_id' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'status' => UserStatus::class,
            'status_changed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'billing_address' => 'encrypted:array', // PII at rest
            'tax_id' => 'encrypted',
            'affiliate_activated_at' => 'datetime',
            'whatsapp_opt_in_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
            'is_affiliate' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** Profile row, created lazily so every user has one. */
    /** @return HasOne<InstructorProfile, $this> */
    public function instructorProfile(): HasOne
    {
        return $this->hasOne(InstructorProfile::class);
    }

    public function profileOrNew(): UserProfile
    {
        return $this->profile ?? $this->profile()->make();
    }

    /** @return HasMany<LoginActivity, $this> */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** 2FA is offered to staff accounts. */
    public function canUseTwoFactor(): bool
    {
        return $this->isStaff();
    }

    /**
     * Our gateway-agnostic subscriptions. Intentionally overrides Cashier's Billable::subscriptions():
     * Cashier is used for Stripe customers, Checkout and the Billing Portal only; its own
     * subscription tables are not installed. See docs/ARCHITECTURE.md (Billing).
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Subscriptions that may grant access (status-wise). Whether each one actually does
     * (period, grace) is decided by Subscription::grantsAccess().
     *
     * @return HasMany<Subscription, $this>
     */
    public function liveSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->whereIn('status', SubscriptionStatus::live());
    }

    /** The access-granting subscription with the highest tier (relation loaded once per request). */
    public function activeSubscription(): ?Subscription
    {
        $this->loadMissing('liveSubscriptions.plan');

        return $this->liveSubscriptions
            ->filter(fn (Subscription $s) => $s->grantsAccess())
            ->sortByDesc(fn (Subscription $s) => [$s->plan->tier_level, $s->current_period_end?->getTimestamp() ?? PHP_INT_MAX])
            ->first();
    }

    public function currentTier(): ?MembershipTier
    {
        $sub = $this->activeSubscription();

        return $sub ? MembershipTier::from($sub->plan->tier_level) : null;
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole([Role::SuperAdmin->value, Role::Admin->value]);
    }

    /** Staff always have access; everyone else needs a tier >= the required one. */
    public function canAccessTier(?MembershipTier $required): bool
    {
        return app(MembershipService::class)->canAccess($this, $required);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }
}
