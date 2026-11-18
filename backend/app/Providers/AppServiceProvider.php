<?php

namespace App\Providers;

use App\Affiliates\Payouts\ManualPayoutGateway;
use App\Affiliates\Payouts\PayoutGateway;
use App\Analytics\AnalyticsService;
use App\Courses\CatalogCache;
use App\Enums\Role;
use App\Listeners\AffiliateEvents;
use App\Listeners\AnalyticsEvents;
use App\Listeners\NotificationEvents;
use App\Listeners\SendBillingNotifications;
use App\Models\Banner;
use App\Models\Category;
use App\Models\CmsSection;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\CourseSection;
use App\Models\Faq;
use App\Models\Lesson;
use App\Models\Page;
use App\Models\Post;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Video;
use App\Notify\NotificationLogger;
use App\Notify\NotifyOtpSender;
use App\Notify\Sms\LogSmsSender;
use App\Notify\Sms\Msg91Sender;
use App\Notify\Sms\SmsSender;
use App\Notify\Sms\TwilioSender;
use App\Notify\Templates;
use App\Policies\GatedContentPolicy;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
use App\Settings\SiteSettings;
use App\Video\BunnyStreamProvider;
use App\Video\DemoVideoProvider;
use App\Video\VideoProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cashier is used for Stripe customers, Checkout and the Billing Portal only; we expose our own
        // signed webhook endpoint and keep subscriptions in our gateway-agnostic table.
        Cashier::ignoreRoutes();

        // Affiliate payouts are recorded manually; Stripe Connect / RazorpayX implement the same interface later.
        $this->app->bind(PayoutGateway::class, ManualPayoutGateway::class);

        $this->app->bind(VideoProvider::class, fn () => match (config('video.driver')) {
            'bunny' => new BunnyStreamProvider,
            default => new DemoVideoProvider,
        });

        // Phase 8: OTPs go through the notification stack (SMS driver from SMS_DRIVER, optional WhatsApp).
        $this->app->bind(OtpSender::class, fn ($app) => config('lms.otp.driver') === 'log' && config('notify.sms.driver') === 'log'
            ? new LogOtpSender
            : $app->make(NotifyOtpSender::class));
        $this->app->bind(SmsSender::class, fn () => match (config('notify.sms.driver')) {
            'msg91' => new Msg91Sender,
            'twilio' => new TwilioSender,
            default => new LogSmsSender,
        });
        $this->app->scoped(Templates::class);
        $this->app->singleton(SiteSettings::class);
        $this->app->singleton(AnalyticsService::class);

        // Telescope is a dev dependency: only wire it up locally.
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Admin-edited settings (branding, tax, gateway keys…) override .env-based config.
        $settings = $this->app->make(SiteSettings::class);
        $settings->apply();
        $applied = $settings->version();
        Queue::before(function () use ($settings, &$applied) {
            if ($settings->version() !== $applied) { // changed since this worker booted
                $applied = $settings->version();
                $settings->reload();
            }
        });

        Event::subscribe(AffiliateEvents::class);
        Event::subscribe(NotificationEvents::class);
        Event::subscribe(AnalyticsEvents::class);

        // Anonymous catalogue cache: any catalogue-visible change starts a new cache version.
        // (Also CMS content and banners, which the guest /site and /cms/home responses include.)
        foreach ([Course::class, Category::class, CourseReview::class, CourseSection::class, Lesson::class, Video::class,
            CmsSection::class, Faq::class, Testimonial::class, Banner::class, Page::class, Post::class] as $model) {
            $model::saved(fn () => app(CatalogCache::class)->bump());
            $model::deleted(fn () => app(CatalogCache::class)->bump());
        }
        Event::listen(NotificationSending::class, [NotificationLogger::class, 'sending']);
        Event::listen(NotificationSent::class, [NotificationLogger::class, 'sent']);

        // Per-channel send rates for queued notifications (jobs over the limit are released and retried).
        foreach ((array) config('notify.rate_limits') as $channel => $perSecond) {
            RateLimiter::for('notify:'.$channel, fn () => Limit::perSecond((int) $perSecond));
        }

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Super admins pass every gate/policy check.
        Gate::before(fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null);

        Event::subscribe(SendBillingNotifications::class);
        Gate::define('access-content', [GatedContentPolicy::class, 'access']);

        $this->configureRateLimiting();
        $this->configureAuthLinks();
    }

    private function configureRateLimiting(): void
    {
        // Default for every API route (per user, else per IP). Raise only for load-test environments.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('security.rate_limits.api', 120))->by($request->user()?->id ?: $request->ip()));
        // Admin screens fire several reports at once.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute((int) config('security.rate_limits.admin', 300))->by('admin:'.$request->user()?->id));
        // File uploads (avatars, thumbnails, resources, assignment files).
        RateLimiter::for('uploads', fn (Request $request) => [
            Limit::perMinute(10)->by('up-min:'.($request->user()?->id ?: $request->ip())),
            Limit::perHour(60)->by('up-hour:'.($request->user()?->id ?: $request->ip())),
        ]);

        // OTP sends: 2 per minute and 5 per hour per user.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinutes(1, 2)->by('otp-min:'.$request->user()?->id),
            Limit::perHour(5)->by('otp-hour:'.$request->user()?->id),
        ]);

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(10)->by('2fa:'.$request->ip()));

        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(3)->by('contact-min:'.$request->ip()),
            Limit::perDay(20)->by('contact-day:'.$request->ip()),
        ]);

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth-email:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }

    /** Email links point at the SPA, which then calls the API. */
    private function configureAuthLinks(): void
    {
        $frontend = config('lms.frontend_url');

        ResetPassword::createUrlUsing(fn (User $user, string $token) => $frontend.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        VerifyEmail::createUrlUsing(function (User $user) use ($frontend) {
            $apiPath = URL::temporarySignedRoute(
                'api.v1.verification.verify',
                now()->addMinutes(config('lms.verification_expire_minutes')),
                ['id' => $user->uuid, 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );

            return $frontend.'/verify-email?'.http_build_query(['url' => $apiPath]);
        });
    }
}
