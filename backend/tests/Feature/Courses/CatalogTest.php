<?php

use App\Enums\CourseStatus;
use App\Enums\MembershipTier;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;

beforeEach(function () {
    $this->dev = Category::factory()->create(['slug' => 'development', 'name' => 'Development']);
    $this->mkt = Category::factory()->create(['slug' => 'marketing', 'name' => 'Marketing']);
    $this->laravel = Course::factory()->published()->create(['title' => 'Laravel APIs for clients', 'category_id' => $this->dev->id, 'level' => 'intermediate', 'required_tier' => 2, 'duration_minutes' => 400, 'rating_avg' => 4.8, 'enrollments_count' => 50, 'published_at' => now()->subDays(10)]);
    $this->ads = Course::factory()->published()->create(['title' => 'Meta Ads from zero', 'category_id' => $this->mkt->id, 'level' => 'beginner', 'required_tier' => 1, 'duration_minutes' => 45, 'rating_avg' => 4.2, 'enrollments_count' => 200, 'published_at' => now()->subDay()]);
    $this->free = Course::factory()->published()->create(['title' => 'Canva basics', 'category_id' => $this->mkt->id, 'level' => 'beginner', 'required_tier' => null, 'duration_minutes' => 120, 'rating_avg' => 3.9, 'enrollments_count' => 5, 'published_at' => now()->subDays(30)]);
    Course::factory()->create(['title' => 'Secret draft']); // draft — never listed
});

$slugs = fn ($res) => collect($res->json('data'))->pluck('title')->all();

it('lists only published courses, most popular first', function () use ($slugs) {
    expect($slugs($this->getJson(api('courses'))->assertOk()))->toBe(['Meta Ads from zero', 'Laravel APIs for clients', 'Canva basics']);
});

it('filters by category, level, tier, duration and rating', function () use ($slugs) {
    expect($slugs($this->getJson(api('courses?category=marketing'))))->toBe(['Meta Ads from zero', 'Canva basics'])
        ->and($slugs($this->getJson(api('courses?level[]=intermediate'))))->toBe(['Laravel APIs for clients'])
        ->and($slugs($this->getJson(api('courses?tier[]=free'))))->toBe(['Canva basics'])
        ->and($slugs($this->getJson(api('courses?tier[]=1&tier[]=free'))))->toBe(['Meta Ads from zero', 'Canva basics'])
        ->and($slugs($this->getJson(api('courses?duration[]=short'))))->toBe(['Meta Ads from zero'])
        ->and($slugs($this->getJson(api('courses?duration[]=long'))))->toBe(['Laravel APIs for clients'])
        ->and($slugs($this->getJson(api('courses?rating=4.5'))))->toBe(['Laravel APIs for clients']);
});

it('sorts by newest, rating and duration', function () use ($slugs) {
    expect($slugs($this->getJson(api('courses?sort=rating'))))->toBe(['Laravel APIs for clients', 'Meta Ads from zero', 'Canva basics'])
        ->and($slugs($this->getJson(api('courses?sort=duration_asc'))))->toBe(['Meta Ads from zero', 'Canva basics', 'Laravel APIs for clients'])
        ->and($slugs($this->getJson(api('courses?sort=newest')))[0])->toBe('Meta Ads from zero');
});

it('searches full text through Scout and never leaks drafts', function () use ($slugs) {
    expect($slugs($this->getJson(api('courses?q=laravel'))))->toBe(['Laravel APIs for clients'])
        ->and($slugs($this->getJson(api('courses?q=secret'))))->toBe([]);
});

it('paginates and validates filters', function () {
    $this->getJson(api('courses?per_page=2'))->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    $this->getJson(api('courses?level[]=wizard&sort=random'))->assertJsonValidationErrors(['level.0', 'sort']);
});

it('flags which courses the signed-in user can open', function () {
    $user = User::factory()->create();
    subscribe($user, MembershipTier::Starter);

    $res = collect($this->actingAs($user)->getJson(api('courses'))->json('data'))->keyBy('title');
    expect($res['Meta Ads from zero']['has_access'])->toBeTrue()
        ->and($res['Laravel APIs for clients']['has_access'])->toBeFalse();
});

it('lists categories with published course counts', function () {
    $this->getJson(api('categories'))->assertOk()->assertJsonFragment(['slug' => 'marketing', 'courses_count' => 2]);
});

it('shows a draft course only to its instructor and staff', function () {
    $draft = Course::where('title', 'Secret draft')->firstOrFail();
    $this->getJson(api("courses/{$draft->slug}"))->assertNotFound();
    $this->actingAs($draft->instructor)->getJson(api("courses/{$draft->slug}"))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->getJson(api("courses/{$draft->slug}"))->assertOk();
    expect($draft->status)->toBe(CourseStatus::Draft);
});
