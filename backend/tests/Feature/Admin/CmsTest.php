<?php

use App\Enums\MembershipTier;
use App\Models\Banner;
use App\Models\Faq;
use App\Models\Post;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('serves default homepage sections until an admin edits them', function () {
    $this->getJson(api('cms/home'))->assertOk()
        ->assertJsonPath('data.sections.0.key', 'hero')
        ->assertJsonPath('data.sections.0.content.highlight', 'Build Income.')
        ->assertJsonCount(8, 'data.sections');

    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/hero'), ['content' => [
        'title' => 'Master a skill.', 'highlight' => 'Get paid.', 'primary_cta' => ['label' => 'Join', 'url' => 'javascript:alert(1)'],
    ]])->assertJsonValidationErrors('content.primary_cta.url');

    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/hero'), ['content' => [
        'title' => 'Master a skill.', 'highlight' => 'Get paid.', 'primary_cta' => ['label' => 'Join', 'url' => '/register'], 'unknown' => 'dropped',
    ]])->assertOk()->assertJsonPath('data.customised', true)->assertJsonPath('data.content.title', 'Master a skill.')
        ->assertJsonMissingPath('data.content.unknown');

    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/faq'), ['content' => ['title' => 'FAQ'], 'is_visible' => false])->assertOk();
    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/nope'), ['content' => ['title' => 'x']])->assertNotFound();

    $home = $this->getJson(api('cms/home'))->assertJsonCount(7, 'data.sections');
    expect($home->json('data.sections.0.content.subtitle'))->toContain('One membership'); // untouched fields keep defaults
});

it('manages FAQs and testimonials with ordering and publish state', function () {
    $a = $this->actingAs($this->admin)->postJson(api('admin/cms/faqs'), ['question' => 'Refunds?', 'answer' => 'Within 7 days.', 'category' => 'billing'])->assertCreated()->json('data.id');
    $b = $this->actingAs($this->admin)->postJson(api('admin/cms/faqs'), ['question' => 'Certificates?', 'answer' => 'Yes.'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->postJson(api('admin/cms/faqs'), ['question' => 'Draft?', 'answer' => 'Hidden.', 'is_published' => false])->assertCreated();

    $this->actingAs($this->admin)->putJson(api('admin/cms/faqs/order'), ['ids' => [$b, $a]])->assertOk()->assertJsonPath('data.0.id', $b);
    $this->getJson(api('cms/home'))->assertJsonCount(2, 'data.faqs')->assertJsonPath('data.faqs.0.question', 'Certificates?');

    $this->actingAs($this->admin)->patchJson(api("admin/cms/faqs/{$a}"), ['answer' => 'Within 14 days.'])->assertOk()->assertJsonPath('data.answer', 'Within 14 days.');
    $this->actingAs($this->admin)->deleteJson(api("admin/cms/faqs/{$b}"))->assertNoContent();

    $this->actingAs($this->admin)->postJson(api('admin/cms/testimonials'), ['name' => 'Asha', 'quote' => 'Got my first client.', 'rating' => 5, 'avatar_url' => 'http://insecure.example/a.png'])
        ->assertJsonValidationErrors('avatar_url');
    $this->actingAs($this->admin)->postJson(api('admin/cms/testimonials'), ['name' => 'Asha', 'role' => 'Designer', 'quote' => 'Got my first client.', 'rating' => 5])->assertCreated();
    $this->getJson(api('cms/home'))->assertJsonPath('data.testimonials.0.name', 'Asha');

    expect(Activity::where('subject_type', (new Faq)->getMorphClass())->where('event', 'updated')->first()->changes()['attributes'])
        ->toBe(['answer' => 'Within 14 days.']);
});

it('shows the newest live banner for the right audience', function () {
    Banner::create(['message' => 'Old promo', 'audience' => 'all', 'ends_at' => now()->subDay()]);
    $guests = Banner::create(['message' => 'Join free today', 'audience' => 'guests']);
    Banner::create(['message' => 'Upgrade to Pro', 'audience' => 'free']);
    Banner::create(['message' => 'Members: live Q&A Friday', 'audience' => 'members', 'link_url' => '/app/live', 'link_text' => 'See schedule']);

    $this->getJson(api('site'))->assertOk()->assertJsonPath('data.banner.message', 'Join free today');
    $this->app['auth']->forgetGuards();
    $this->actingAs(User::factory()->create())->getJson(api('site'))->assertJsonPath('data.banner.message', 'Upgrade to Pro');
    $member = User::factory()->create();
    subscribe($member, MembershipTier::Pro);
    $this->app['auth']->forgetGuards(); // sanctum guard memoises the previous request's user
    $this->actingAs($member)->getJson(api('site'))->assertJsonPath('data.banner.link_url', '/app/live');

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin)->patchJson(api("admin/cms/banners/{$guests->uuid}"), ['link_url' => 'https://evil.example', 'link_text' => 'x'])->assertOk();
    $this->actingAs($this->admin)->patchJson(api("admin/cms/banners/{$guests->uuid}"), ['link_url' => 'javascript:x', 'link_text' => 'x'])->assertJsonValidationErrors('link_url');
    $this->actingAs($this->admin)->getJson(api('admin/cms/banners'))->assertJsonCount(4, 'data')->assertJsonPath('data.3.is_live', false);
});

it('publishes legal pages with sanitised HTML', function () {
    $this->actingAs($this->admin)->getJson(api('admin/cms/pages'))->assertOk()->assertJsonPath('data.0.slug', 'terms')->assertJsonPath('data.0.customised', false);
    $this->getJson(api('cms/pages/terms'))->assertNotFound(); // SPA falls back to its built-in page

    $this->actingAs($this->admin)->putJson(api('admin/cms/pages/terms'), [
        'title' => 'Terms of Service', 'body' => '<h2>1. Agreement</h2><p onclick="steal()">Be nice.</p><script>alert(1)</script>',
    ])->assertOk();

    $body = $this->getJson(api('cms/pages/terms'))->assertOk()->json('data.body');
    expect($body)->toContain('<h2>1. Agreement</h2>')->not->toContain('script')->not->toContain('onclick');

    $this->actingAs($this->admin)->putJson(api('admin/cms/pages/refund-policy'), ['title' => 'Refunds', 'body' => '<p>7 days</p>', 'is_published' => false])->assertOk();
    $this->getJson(api('cms/pages/refund-policy'))->assertNotFound();
});

it('runs a blog with drafts, scheduled and published posts', function () {
    $id = $this->actingAs($this->admin)->postJson(api('admin/cms/posts'), ['title' => 'How to land your first client', 'body' => '<p>Start small.</p>'])
        ->assertCreated()->assertJsonPath('data.slug', 'how-to-land-your-first-client')->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->getJson(api('blog/how-to-land-your-first-client'))->assertNotFound();

    $this->actingAs($this->admin)->patchJson(api("admin/cms/posts/{$id}"), ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
    $this->actingAs($this->admin)->postJson(api('admin/cms/posts'), ['title' => 'How to land your first client', 'body' => '<p>Dup</p>', 'status' => 'published', 'published_at' => now()->addWeek()->toIso8601String()])
        ->assertCreated()->assertJsonPath('data.slug', 'how-to-land-your-first-client-2');

    $this->getJson(api('blog'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reading_minutes', 1)->assertJsonMissingPath('data.0.body');
    $this->getJson(api('blog/how-to-land-your-first-client'))->assertOk()->assertJsonPath('data.body', '<p>Start small.</p>')->assertJsonPath('data.author.name', $this->admin->name);
    $this->getJson(api('blog/how-to-land-your-first-client-2'))->assertNotFound(); // scheduled

    $this->actingAs($this->admin)->deleteJson(api("admin/cms/posts/{$id}"))->assertNoContent();
    expect(Post::withTrashed()->count())->toBe(2);
});

it('keeps CMS editing to staff with cms.manage', function () {
    $this->actingAs(User::factory()->instructor()->create())->postJson(api('admin/cms/faqs'), ['question' => 'x', 'answer' => 'y'])->assertForbidden();
});

it('reorders or hides sections without customising their copy, and keeps a live post live', function () {
    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/faq'), ['sort' => 0])->assertOk()->assertJsonPath('data.customised', false);
    $this->actingAs($this->admin)->putJson(api('admin/cms/sections/hero'), ['sort' => 9])->assertOk();
    $this->getJson(api('cms/home'))->assertJsonPath('data.sections.0.key', 'faq');

    $id = $this->actingAs($this->admin)->postJson(api('admin/cms/posts'), ['title' => 'Live post', 'body' => '<p>x</p>', 'status' => 'published'])->json('data.id');
    $this->actingAs($this->admin)->patchJson(api("admin/cms/posts/{$id}"), ['published_at' => null, 'title' => 'Live post!'])->assertOk();
    $this->getJson(api('blog/live-post'))->assertOk()->assertJsonPath('data.title', 'Live post!');
});
