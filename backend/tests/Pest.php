<?php

use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\Affiliate;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PushToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Video;
use App\Notify\PlatformNotification;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BadgeSeeder::class);
        config([
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
            'billing.stripe.webhook_secret' => 'whsec_test_secret',
            'billing.razorpay.key_id' => 'rzp_test_key',
            'billing.razorpay.key_secret' => 'rzp_test_secret',
            'billing.razorpay.webhook_secret' => 'rzp_webhook_secret',
            'video.driver' => 'bunny',
            'video.bunny.library_id' => '4242',
            'video.bunny.api_key' => 'bunny-api-key',
            'video.bunny.read_only_api_key' => 'bunny-read-only-key',
            'video.bunny.cdn_hostname' => 'vz-test.b-cdn.net',
            'video.bunny.token_security_key' => 'bunny-token-key',
            'live.zoom.account_id' => 'zoom-acct',
            'live.zoom.client_id' => 'zoom-client',
            'live.zoom.client_secret' => 'zoom-secret',
            'live.zoom.sdk_key' => 'sdk-key',
            'live.zoom.sdk_secret' => 'sdk-secret',
            'live.zoom.webhook_secret' => 'zoom-webhook-secret',
        ]);
    })
    ->afterEach(fn () => ApiRequestor::setHttpClient(null))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Base path for versioned API calls. */
function api(string $path): string
{
    return '/api/v1/'.ltrim($path, '/');
}

/** A price on a plan of the given tier (creates the plan). INR → Razorpay, others → Stripe. */
function planFor(MembershipTier $tier, BillingInterval $interval = BillingInterval::Month, string $currency = 'INR', array $attrs = []): PlanPrice
{
    $plan = Plan::factory()->tier($tier)->create();
    $factory = PlanPrice::factory()->for($plan)->interval($interval);
    $factory = $currency === 'INR' ? $factory->razorpay() : $factory->stripe();

    return $factory->create(['currency' => $currency, ...$attrs])->load('plan');
}

/** An active (gateway) subscription at the given tier. */
function subscribe(User $user, MembershipTier $tier, array $attrs = []): Subscription
{
    return Subscription::factory()->for($user)->forPrice(planFor($tier))->create($attrs);
}

function fakeStripe(): FakeStripeClient
{
    $client = new FakeStripeClient;
    ApiRequestor::setHttpClient($client);

    return $client;
}

/** Loads a webhook fixture, substituting {{placeholders}}. */
function webhookFixture(string $name, array $vars = []): string
{
    $json = file_get_contents(__DIR__.'/Fixtures/'.$name);
    foreach ($vars as $k => $v) {
        $json = str_replace('{{'.$k.'}}', is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $json);
    }
    expect(json_decode($json, true))->toBeArray('Invalid fixture JSON after substitution: '.$name);

    return $json;
}

function postStripeWebhook(string $payload, ?string $secret = null): TestResponse
{
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, $secret ?? 'whsec_test_secret');

    return test()->call('POST', api('webhooks/stripe'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload);
}

function postRazorpayWebhook(string $payload, ?string $eventId = null, ?string $secret = null): TestResponse
{
    return test()->call('POST', api('webhooks/razorpay'), [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, $secret ?? 'rzp_webhook_secret'),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? 'evt_'.bin2hex(random_bytes(6)),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload);
}

function postBunnyWebhook(array $body, ?string $key = null): TestResponse
{
    $raw = json_encode($body);

    return test()->call('POST', api('webhooks/video'), [], [], [], [
        'HTTP_X_BUNNYSTREAM_SIGNATURE_VERSION' => 'v1',
        'HTTP_X_BUNNYSTREAM_SIGNATURE_ALGORITHM' => 'hmac-sha256',
        'HTTP_X_BUNNYSTREAM_SIGNATURE' => hash_hmac('sha256', $raw, $key ?? 'bunny-read-only-key'),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

/** A published course at a tier with N video lessons (first = preview). */
function publishedCourse(?int $tier = 2, int $lessons = 3, array $attrs = []): Course
{
    return Course::factory()->published()->tier($tier)->withLessons($lessons)->create($attrs)->load('sections.lessons');
}

/** A published course whose lessons have the given types (video lessons 60s long). */
function courseWith(array $types, ?int $tier = 1): Course
{
    $course = Course::factory()->published()->tier($tier)->create();
    $section = $course->sections()->create(['title' => 'Main', 'sort' => 0]);
    foreach ($types as $i => $type) {
        Lesson::create([
            'course_id' => $course->id, 'section_id' => $section->id, 'title' => ucfirst($type).' '.($i + 1),
            'type' => $type, 'sort' => $i, 'duration_seconds' => $type === 'video' ? 60 : 0,
            'video_id' => $type === 'video' ? Video::factory()->create(['duration_seconds' => 60])->id : null,
        ]);
    }
    $course->refreshStats();

    return $course->load('sections.lessons');
}

/** Sends player heartbeats every 15 real seconds until $seconds have been watched. */
function watch(User $user, Lesson $lesson, int $seconds, ?string $token = null): TestResponse
{
    $res = null;
    for ($t = 15; $t <= $seconds; $t += 15) {
        test()->travel(15)->seconds();
        $req = $token ? test()->withToken($token) : test()->actingAs($user);
        $res = $req->postJson(api("learn/lessons/{$lesson->uuid}/heartbeat"), ['position_seconds' => $t, 'watched_seconds' => 15]);
    }

    return $res;
}

/** Fakes Zoom's OAuth + meetings API. Returns the recorded requests via Http::recorded(). */
function fakeZoom(array $extra = []): void
{
    $n = 0;
    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-access', 'expires_in' => 3599]),
        'api.zoom.us/v2/users/me/meetings' => function () use (&$n) {
            $n++;

            return Http::response([
                'id' => 8800000000 + $n, 'join_url' => "https://zoom.us/j/88000000{$n}?pwd=abc", 'start_url' => "https://zoom.us/s/88000000{$n}?zak=host", 'password' => 'p4ss',
            ], 201);
        },
        ...$extra,
        'api.zoom.us/v2/meetings/*' => Http::response(null, 204),
    ]);
}

/** Posts a correctly signed Zoom webhook. */
function zoomWebhook(array $body, ?string $secret = 'zoom-webhook-secret'): TestResponse
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $ts = (string) time();

    return test()->call('POST', api('webhooks/zoom'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
        'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", (string) $secret),
    ], $raw);
}

/** A session body as the instructor form sends it (local wall-clock time). */
function liveBody(array $o = []): array
{
    return [
        'title' => 'Meta Ads clinic', 'description' => 'Bring your ad account questions.', 'type' => 'class', 'required_tier' => 2,
        'starts_at' => now('Asia/Kolkata')->addDays(2)->setTime(19, 30)->format('Y-m-d\TH:i'), 'timezone' => 'Asia/Kolkata',
        'duration' => 60, 'capacity' => 100, 'provider' => 'zoom', ...$o,
    ];
}

/** An approved affiliate (default: standard tier, 20 %). */
function makeAffiliate(array $attrs = [], ?User $user = null): Affiliate
{
    $user ??= User::factory()->student()->create();

    $affiliate = Affiliate::create([
        'user_id' => $user->id, 'code' => 'AFF'.strtoupper(Str::random(5)), 'promotion_plan' => 'YouTube channel',
        ...$attrs,
    ]);
    $affiliate->forceFill(['status' => $attrs['status'] ?? 'approved', 'approved_at' => now()])->save();

    return $affiliate->load('user');
}

/** Visits the site with ?ref=CODE; returns the signed attribution cookie value (or null). */
function clickRef(string $code, array $extra = [], string $ip = '203.0.113.10'): ?string
{
    $res = test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(api('referrals/track'), ['ref' => $code, 'landing_url' => '/pricing', ...$extra])->assertNoContent();

    return $res->getCookie('lms_ref', false)?->getValue();
}

/** Signs up a new account carrying the given attribution cookies / device / IP. */
function signUp(string $email, ?string $refCookie = null, ?string $inviteCookie = null, string $device = 'device-visitor-0001', string $ip = '203.0.113.10', array $body = []): User
{
    $req = test()->withCredentials()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('X-Device-Id', $device);
    if ($refCookie) {
        $req = $req->withUnencryptedCookie('lms_ref', $refCookie);
    }
    if ($inviteCookie) {
        $req = $req->withUnencryptedCookie('lms_invite', $inviteCookie);
    }
    $req->postJson(api('auth/register'), [
        'name' => 'New Member', 'email' => $email, 'password' => 'Password123', 'password_confirmation' => 'Password123', ...$body,
    ])->assertCreated();
    test()->flushSession();
    app('auth')->forgetGuards();

    $user = User::where('email', $email)->firstOrFail();
    $user->forceFill(['country' => 'IN'])->save(); // GST-inclusive pricing in these tests

    return $user;
}

/** Razorpay subscription activated + charged for $user (Pro monthly ₹999 incl. GST). */
function razorpayCharge(User $user, PlanPrice $price, string $paymentId, array $o = []): void
{
    $vars = [
        'event' => 'subscription.activated', 'sub_id' => 'sub_'.$user->uuid, 'razorpay_plan_id' => $price->razorpay_plan_id,
        'user_uuid' => $user->uuid, 'plan_price_uuid' => $price->uuid, 'status' => 'active',
        'period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp(), 'paid_count' => 1,
        'payment_id' => $paymentId, 'amount' => $price->amount, ...$o,
    ];
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', $vars))->assertOk();
    postRazorpayWebhook(webhookFixture('razorpay/subscription.charged.json', $vars))->assertOk();
}

function razorpayRefund(string $paymentId, int $amount, int $refundedTotal, int $thisRefund): void
{
    postRazorpayWebhook(webhookFixture('razorpay/refund.processed.json', [
        'payment_id' => $paymentId, 'amount' => $amount, 'amount_refunded' => $refundedTotal, 'refund_amount' => $thisRefund, 'refund_id' => bin2hex(random_bytes(4)),
    ]))->assertOk();
}

/** Turns on WhatsApp (Cloud API) + FCM + SMS(log) for the notification tests. */
function enableChannels(): void
{
    config([
        'notify.whatsapp.token' => 'wa-token', 'notify.whatsapp.phone_number_id' => '1098765432', 'notify.whatsapp.app_secret' => 'wa-secret',
        'notify.whatsapp.verify_token' => 'wa-verify', 'notify.fcm.project_id' => 'lms-test', 'notify.fcm.credentials' => fcmCredentials(),
    ]);
}

/** A user who can receive every channel. */
function reachableUser(array $attrs = []): User
{
    $u = User::factory()->student()->create(['phone' => '+919876543210', 'phone_verified_at' => now(), 'whatsapp_opt_in_at' => now()->subDay(), ...$attrs]);
    PushToken::create(['user_id' => $u->id, 'token' => 'fcm-token-'.$u->id, 'platform' => 'android']);

    return $u;
}

/** A throwaway Google service-account JSON with a real RSA key (signed JWTs need it). */
function fcmCredentials(): string
{
    static $path = null;
    if ($path) {
        return $path;
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm').'.json';
    file_put_contents($path, json_encode(['type' => 'service_account', 'client_email' => 'push@lms-test.iam.gserviceaccount.com', 'private_key' => $pem]));

    return $path;
}

/** An instance of any event for routing tests. */
function eventNotification(string $event): PlatformNotification
{
    $n = new class($event) extends PlatformNotification
    {
        public function __construct(public string $key) {}

        public function event(): string
        {
            return $this->key;
        }

        public function vars(object $notifiable): array
        {
            return ['course' => 'Meta Ads', 'plan' => 'Pro', 'title' => 'Live Q&A', 'when' => 'Tomorrow', 'date' => '1 Dec 2026'];
        }
    };
    $n->id = (string) Str::uuid();

    return $n;
}
