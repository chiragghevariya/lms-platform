<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\NewContactMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('lists instructors publicly without contact details', function () {
    $featured = User::factory()->instructor()->create(['name' => 'Alpha']);
    $featured->profile()->create(['headline' => 'Pro editor', 'is_featured' => true, 'expertise' => ['Video']]);
    User::factory()->instructor()->create(['name' => 'Beta']);
    User::factory()->instructor()->suspended()->create();
    User::factory()->student()->create();

    $this->getJson(api('instructors'))->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.email');

    $this->getJson(api('instructors?featured=1'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Pro editor')
        ->assertJsonPath('data.0.expertise', ['Video']);
});

it('stores contact messages and notifies the team', function () {
    Notification::fake();

    $this->postJson(api('contact'), [
        'name' => 'Ravi', 'email' => 'ravi@example.com', 'subject' => 'Partnership', 'message' => 'Hello there, I would like to talk.',
    ])->assertCreated();

    expect(ContactMessage::count())->toBe(1);
    Notification::assertSentTo(new AnonymousNotifiable, NewContactMessage::class);
});

it('validates contact messages and blocks the honeypot', function () {
    $this->postJson(api('contact'), ['email' => 'bad', 'message' => 'short'])
        ->assertJsonValidationErrors(['name', 'email', 'subject', 'message']);

    $this->postJson(api('contact'), [
        'name' => 'Bot', 'email' => 'b@example.com', 'subject' => 'Hi', 'message' => 'Buy cheap things now!!', 'website' => 'spam.example',
    ])->assertJsonValidationErrors('website');
});

it('serves an XML sitemap of public pages', function () {
    $res = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string($res->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $locs = array_map('strval', $xml->xpath('//s:loc'));

    expect($locs)->toContain(config('lms.frontend_url').'/', config('lms.frontend_url').'/pricing', config('lms.frontend_url').'/privacy');
});
