<?php

use App\Affiliates\Payouts\PayoutService;
use App\Courses\CourseWorkflow;
use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Events\PaymentSucceeded;
use App\Models\AffiliatePayout;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\LiveSession;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Live\LiveSessionReminderNotification;
use App\Notifications\Platform\CommissionNotification;
use App\Notifications\Platform\InactivityNotification;
use App\Notifications\Platform\NewCourseNotification;
use App\Notifications\Platform\SubscriptionExpiringNotification;
use App\Notifications\Platform\SubscriptionRenewedNotification;
use App\Notifications\Platform\WelcomeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Notification::fake());

it('welcomes new sign-ups', function () {
    $this->postJson(api('auth/register'), ['name' => 'Nia', 'email' => 'nia@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123'])->assertCreated();
    Notification::assertSentTo(User::where('email', 'nia@example.com')->first(), WelcomeNotification::class);
});

it('sends "renewed" for every subscription payment after the first', function () {
    $user = User::factory()->create();
    $sub = subscribe($user, MembershipTier::Pro);
    $first = Payment::factory()->create(['user_id' => $user->id, 'subscription_id' => $sub->id]);
    event(new PaymentSucceeded($first));
    Notification::assertNotSentTo($user, SubscriptionRenewedNotification::class);

    $second = Payment::factory()->create(['user_id' => $user->id, 'subscription_id' => $sub->id]);
    event(new PaymentSucceeded($second));
    Notification::assertSentTo($user, SubscriptionRenewedNotification::class);
});

it('warns 7 days and 1 day before a non-renewing membership ends, once each', function () {
    $user = User::factory()->create();
    $sub = subscribe($user, MembershipTier::Pro);
    $sub->forceFill(['cancel_at_period_end' => true, 'current_period_end' => now()->addDays(7)->subHour()])->save();
    $renewing = subscribe(User::factory()->create(), MembershipTier::Pro);
    $renewing->forceFill(['current_period_end' => now()->addDays(7)->subHour()])->save();

    $this->artisan('notify:scheduled');
    $this->artisan('notify:scheduled');
    Notification::assertSentToTimes($user, SubscriptionExpiringNotification::class, 1);
    Notification::assertSentTo($user, SubscriptionExpiringNotification::class, fn ($n) => $n->event() === 'subscription_expiring_7d');
    Notification::assertNotSentTo($renewing->user, SubscriptionExpiringNotification::class); // auto-renews: no "ending" mail

    $this->travel(6)->days();
    $this->artisan('notify:scheduled');
    Notification::assertSentTo($user, SubscriptionExpiringNotification::class, fn ($n) => $n->event() === 'subscription_expiring_1d');
});

it('nudges members after 7 and 14 days without learning, pointing at their last lesson', function () {
    $user = User::factory()->create();
    subscribe($user, MembershipTier::Starter);
    $course = courseWith(['text', 'text']);
    CourseProgress::create(['user_id' => $user->id, 'course_id' => $course->id, 'percent' => 50, 'last_lesson_id' => $course->sections[0]->lessons[1]->id, 'last_activity_at' => now()->subDays(7)]);
    DB::table('learning_days')->insert(['user_id' => $user->id, 'day' => now()->subDays(7)->toDateString(), 'seconds' => 600]);
    $lapsed = User::factory()->create(); // not a member → no nudge
    DB::table('learning_days')->insert(['user_id' => $lapsed->id, 'day' => now()->subDays(7)->toDateString(), 'seconds' => 600]);

    $this->artisan('notify:scheduled');
    $this->artisan('notify:scheduled');
    Notification::assertSentToTimes($user, InactivityNotification::class, 1);
    Notification::assertSentTo($user, InactivityNotification::class, fn ($n) => $n->event() === 'inactivity_7d'
        && str_ends_with((string) $n->vars($user)['url'], '/'.$course->sections[0]->lessons[1]->uuid));
    Notification::assertNotSentTo($lapsed, InactivityNotification::class);

    $this->travel(7)->days();
    $this->artisan('notify:scheduled');
    Notification::assertSentTo($user, InactivityNotification::class, fn ($n) => $n->event() === 'inactivity_14d');
});

it('announces a newly published course to members of that tier only, once', function () {
    $pro = User::factory()->create();
    subscribe($pro, MembershipTier::Pro);
    $elite = User::factory()->create();
    subscribe($elite, MembershipTier::Elite);
    $starter = User::factory()->create();
    subscribe($starter, MembershipTier::Starter);
    $course = Course::factory()->create(['required_tier' => 2]);
    $course->forceFill(['status' => 'review', 'published_at' => null])->save();
    $admin = User::factory()->admin()->create();

    app(CourseWorkflow::class)->approve($course, $admin);
    Notification::assertSentTo([$pro, $elite], NewCourseNotification::class);
    Notification::assertNotSentTo($starter, NewCourseNotification::class);

    $course->forceFill(['status' => 'review'])->save();
    app(CourseWorkflow::class)->approve($course->fresh(), $admin);
    Notification::assertSentToTimes($pro, NewCourseNotification::class, 1);
});

it('tells affiliates about earned and paid commissions', function () {
    Storage::fake('local');
    $affiliate = makeAffiliate(['payout_method' => 'upi', 'payout_details' => ['upi_id' => 'a@okaxis']]);
    $price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $customer = signUp('c@example.com', clickRef($affiliate->code));
    razorpayCharge($customer, $price, 'pay_1');
    Notification::assertSentTo($affiliate->user, CommissionNotification::class, fn ($n) => $n->event() === 'commission_earned' && $n->vars($affiliate->user)['amount'] === '₹169.32');

    config(['affiliates.payouts.minimum.INR' => 1000]);
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    app(PayoutService::class)->batch('2026-12');
    app(PayoutService::class)->markPaid(AffiliatePayout::first(), User::factory()->admin()->create(), 'UTR9');
    Notification::assertSentTo($affiliate->user, CommissionNotification::class, fn ($n) => $n->event() === 'commission_paid' && $n->vars($affiliate->user)['reference'] === 'UTR9');
});

it('maps live reminders to the 24 h and 15 min events', function () {
    $s = LiveSession::factory()->create();
    expect((new LiveSessionReminderNotification($s, 1440))->event())->toBe('live_reminder_24h')
        ->and((new LiveSessionReminderNotification($s, 15))->event())->toBe('live_reminder_15m');
});
