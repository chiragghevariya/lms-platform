<?php

use App\Billing\TaxCalculator;
use App\Enums\MembershipTier;
use App\Events\PaymentSucceeded;
use App\Exports\PaymentsExport;
use App\Exports\UsersExport;
use App\Jobs\SendServerConversion;
use App\Models\Category;
use App\Models\Commission;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Settings\SiteSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->super = User::factory()->superAdmin()->create();
});

// ---------------------------------------------------------------- settings

it('lets admins edit branding and SEO, which the site and config pick up', function () {
    $this->actingAs($this->admin)->putJson(api('admin/settings/branding'), [
        'name' => 'SkillForge', 'tagline' => 'Learn. Earn.', 'primary_color' => '#4F46E5', 'support_email' => 'help@skillforge.test',
    ])->assertOk()->assertJsonPath('data.name', 'SkillForge')->assertJsonPath('meta.changed', ['branding.name', 'branding.tagline', 'branding.primary_color', 'branding.support_email']);

    expect(config('lms.brand.name'))->toBe('SkillForge')->and(config('lms.contact_email'))->toBe('help@skillforge.test');

    $this->actingAs($this->admin)->putJson(api('admin/settings/seo'), ['twitter_handle' => 'skillforge', 'allow_indexing' => false])->assertOk();
    $this->getJson(api('site'))->assertOk()
        ->assertJsonPath('data.brand.name', 'SkillForge')
        ->assertJsonPath('data.seo.twitter_handle', '@skillforge')
        ->assertJsonPath('data.seo.allow_indexing', false);

    $this->actingAs($this->admin)->putJson(api('admin/settings/branding'), ['primary_color' => 'blue'])->assertJsonValidationErrors('primary_color');
    expect(Activity::where('event', 'settings.updated')->count())->toBe(2);
});

it('keeps gateway keys to super admins, encrypted and never echoed back', function () {
    $this->actingAs($this->admin)->putJson(api('admin/settings/payments'), ['stripe_secret' => 'sk_live_123456789'])->assertForbidden();
    $this->actingAs($this->admin)->getJson(api('admin/settings'))->assertOk()->assertJsonMissingPath('data.values.payments')->assertJsonPath('data.can_manage_secrets', false);
    $this->actingAs($this->admin)->putJson(api('admin/settings/tracking'), ['meta_capi_token' => 'EAAB-secret'])->assertForbidden();
    $this->actingAs($this->admin)->putJson(api('admin/settings/tracking'), ['ga4_measurement_id' => 'G-ABC1234', 'meta_capi_token' => ''])->assertOk();

    $this->actingAs($this->super)->putJson(api('admin/settings/payments'), ['stripe_secret' => 'sk_live_123456789', 'stripe_key' => 'pk_live_abc'])
        ->assertOk()->assertJsonPath('data.stripe_secret.set', true)->assertJsonPath('data.stripe_secret.last4', '6789')
        ->assertJsonPath('data.stripe_secret.source', 'admin')->assertJsonPath('data.stripe_key', 'pk_live_abc');

    $row = Setting::find('payments.stripe_secret');
    expect($row->is_secret)->toBeTrue()->and($row->value)->not->toContain('sk_live')
        ->and(config('cashier.secret'))->toBe('sk_live_123456789');
    $log = Activity::where('event', 'settings.updated')->latest('id')->first();
    expect(json_encode($log->properties))->not->toContain('sk_live');

    // Blank keeps the secret; "clear" falls back to the .env value.
    $this->actingAs($this->super)->putJson(api('admin/settings/payments'), ['stripe_secret' => ''])->assertOk()->assertJsonPath('meta.changed', []);
    $this->actingAs($this->super)->putJson(api('admin/settings/payments'), ['clear' => ['stripe_secret']])->assertOk()->assertJsonPath('data.stripe_secret.source', 'env');
    expect(config('cashier.secret'))->toBe('sk_test_fake');
    $this->actingAs($this->super)->putJson(api('admin/settings/payments'), ['clear' => ['stripe_key']])->assertJsonValidationErrors('clear');
});

it('applies tax rates from settings to new invoices', function () {
    $this->actingAs($this->admin)->putJson(api('admin/settings/tax'), ['rates' => [
        ['country' => 'in', 'name' => 'GST', 'rate' => 18], ['country' => 'GB', 'name' => 'VAT', 'rate' => 20],
    ]])->assertOk()->assertJsonPath('data.rates.1.country', 'GB');

    expect(app(TaxCalculator::class)->breakdown(1200, 'GB'))->toMatchArray(['subtotal' => 1000, 'tax_amount' => 200, 'tax_name' => 'VAT']);
    $this->actingAs($this->admin)->putJson(api('admin/settings/tax'), ['rates' => [['country' => 'IN', 'name' => 'GST', 'rate' => 80]]])->assertJsonValidationErrors('rates.0.rate');
});

// ---------------------------------------------------------------- reviews & categories

it('hides abusive reviews from the course page and its rating', function () {
    $course = publishedCourse(1);
    $make = fn (int $rating, string $comment) => CourseReview::create(['course_id' => $course->id, 'user_id' => User::factory()->create()->id, 'rating' => $rating, 'comment' => $comment]);
    $make(5, 'Brilliant course');
    $bad = $make(1, 'Spam spam buy my stuff');
    $course->refreshRating();
    expect((float) $course->fresh()->rating_avg)->toBe(3.0);

    $this->actingAs($this->admin)->getJson(api('admin/reviews?max_rating=2'))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->postJson(api("admin/reviews/{$bad->uuid}/hide"), ['reason' => 'Spam'])->assertOk()->assertJsonPath('data.hidden', true);

    expect((float) $course->fresh()->rating_avg)->toBe(5.0)->and($course->fresh()->rating_count)->toBe(1);
    $this->getJson(api("courses/{$course->slug}/reviews"))->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->getJson(api('admin/reviews?state=hidden'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.hidden_reason', 'Spam');

    $this->actingAs($this->admin)->postJson(api("admin/reviews/{$bad->uuid}/unhide"))->assertOk();
    expect($course->fresh()->rating_count)->toBe(2);
    $this->actingAs($this->admin)->deleteJson(api("admin/reviews/{$bad->uuid}"))->assertNoContent();
    expect($course->fresh()->rating_count)->toBe(1)->and(Activity::where('event', 'review.deleted')->value('properties')['comment'])->toBe('Spam spam buy my stuff');
});

it('manages categories and protects ones in use', function () {
    $id = $this->actingAs($this->admin)->postJson(api('admin/categories'), ['name' => 'Video Editing'])->assertCreated()->assertJsonPath('data.slug', 'video-editing')->json('data.id');
    $this->actingAs($this->admin)->postJson(api('admin/categories'), ['name' => 'Video Editing'])->assertJsonValidationErrors('name');
    $this->actingAs($this->admin)->patchJson(api("admin/categories/{$id}"), ['name' => 'Video & Motion', 'sort' => 3])->assertOk()->assertJsonPath('data.sort', 3);

    $used = publishedCourse(1)->category;
    $this->actingAs($this->admin)->deleteJson(api("admin/categories/{$used->uuid}"))->assertJsonValidationErrors('category');
    $this->actingAs($this->admin)->deleteJson(api("admin/categories/{$id}"))->assertNoContent();
    $this->actingAs($this->admin)->getJson(api('admin/categories'))->assertOk()->assertJsonPath('data.0.courses_count', 1);
    expect(Category::count())->toBe(1);
});

// ---------------------------------------------------------------- exports & audit

it('exports filtered users, payments and commissions as CSV or Excel', function () {
    Excel::fake();
    $pro = User::factory()->create(['name' => 'Exported Pro']);
    subscribe($pro, MembershipTier::Pro);
    User::factory()->count(2)->create();
    Payment::factory()->count(3)->create();

    $this->actingAs($this->admin)->get(api('admin/users/export?tier=2&format=xlsx'))->assertOk();
    Excel::assertDownloaded('users-'.now()->format('Ymd-His').'.xlsx', fn (UsersExport $e) => $e->query()->count() === 1 && $e->map($e->query()->first())[1] === 'Exported Pro');

    $this->actingAs($this->admin)->get(api('admin/payments/export'))->assertOk();
    Excel::assertDownloaded('payments-'.now()->format('Ymd-His').'.csv', fn (PaymentsExport $e) => $e->query()->count() === 3);

    $this->actingAs($this->admin)->get(api('admin/commissions/export?status=paid'))->assertOk();
    expect(Activity::where('event', 'like', 'export.%')->count())->toBe(3);
});

it('writes a real CSV with headings and amounts in major units', function () {
    Payment::factory()->create(['amount' => 99900, 'invoice_number' => 'INV-REAL-1']);
    $res = $this->actingAs($this->admin)->get(api('admin/payments/export'))->assertOk();
    $csv = file_get_contents($res->baseResponse->getFile()->getPathname());

    expect($csv)->toContain('"Payment ID","Invoice"')->toContain('"INV-REAL-1"')->toContain('"999"');
});

it('records an audit trail of admin changes with before/after values', function () {
    $plan = Plan::factory()->create(['name' => 'Pro']);
    $this->actingAs($this->admin)->patchJson(api("admin/plans/{$plan->uuid}"), ['name' => 'Pro Plus'])->assertOk();

    $this->actingAs($this->admin)->getJson(api('admin/audit-log?subject_type=plan&event=updated'))->assertOk()
        ->assertJsonPath('data.0.changes.old.name', 'Pro')
        ->assertJsonPath('data.0.changes.attributes.name', 'Pro Plus')
        ->assertJsonPath('data.0.causer.id', $this->admin->uuid)
        ->assertJsonPath('data.0.subject.label', 'Pro Plus');
    $this->actingAs($this->admin)->getJson(api('admin/audit-log?causer='.$this->super->uuid))->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------- server-side conversions

it('sends purchases to GA4 and Meta from the server only when configured', function () {
    Queue::fake();
    $p = Payment::factory()->create();
    event(new PaymentSucceeded($p));
    Queue::assertNotPushed(SendServerConversion::class);

    config(['tracking.ga4.measurement_id' => 'G-TEST123', 'tracking.ga4.api_secret' => 'ga-secret', 'tracking.meta.pixel_id' => '1234567890', 'tracking.meta.access_token' => 'meta-token']);
    event(new PaymentSucceeded($p));
    Queue::assertPushed(SendServerConversion::class);

    Http::fake();
    (new SendServerConversion($p->id))->handle();
    $user = $p->user;
    Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://www.google-analytics.com/mp/collect?measurement_id=G-TEST123&api_secret=ga-secret')
        && $r['events'][0]['name'] === 'purchase' && $r['events'][0]['params']['transaction_id'] === $p->uuid && $r['events'][0]['params']['value'] === 999.0);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v26.0/1234567890/events'
        && $r['data'][0]['event_id'] === $p->uuid
        && $r['data'][0]['user_data']['em'][0] === hash('sha256', strtolower($user->email))
        && ! str_contains($r->body(), $user->email));
});

it('exposes public tracking ids to the SPA but never the secrets', function () {
    config(['tracking.ga4.measurement_id' => 'G-TEST123', 'tracking.ga4.api_secret' => 'ga-secret', 'tracking.meta.pixel_id' => '1234567890', 'tracking.meta.access_token' => 'meta-token']);
    $body = $this->getJson(api('site'))->assertOk()->assertJsonPath('data.tracking.ga4_measurement_id', 'G-TEST123')->getContent();
    expect($body)->not->toContain('ga-secret')->not->toContain('meta-token');
    expect(app(SiteSettings::class)->public()['tracking']['meta_pixel_id'])->toBe('1234567890');
    expect(Commission::count() + Enrollment::count())->toBe(0);
});

it('audits affiliate and payout admin actions without secrets', function () {
    $a = makeAffiliate(['status' => 'pending']);
    $this->actingAs($this->admin)->postJson(api("admin/affiliates/{$a->uuid}/approve"))->assertOk();

    $log = Activity::where('event', 'admin.affiliates.decide')->first();
    expect($log)->not->toBeNull()->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($a->id)->and($log->properties['route']['action'])->toBe('approve');
});
