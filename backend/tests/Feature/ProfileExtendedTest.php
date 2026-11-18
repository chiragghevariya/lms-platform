<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('updates country, bio and extended profile fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson(api('me'), [
        'country' => 'in',
        'bio' => 'Learning to freelance.',
        'profile' => [
            'headline' => 'Aspiring designer',
            'city' => 'Pune',
            'social_links' => ['linkedin' => 'https://linkedin.com/in/me'],
            'interests' => ['design', 'video'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.country', 'IN')
        ->assertJsonPath('data.bio', 'Learning to freelance.')
        ->assertJsonPath('data.profile.headline', 'Aspiring designer')
        ->assertJsonPath('data.profile.interests', ['design', 'video']);

    expect($user->profile()->count())->toBe(1);
});

it('validates profile fields', function () {
    $this->actingAs(User::factory()->create())->patchJson(api('me'), [
        'country' => 'India',
        'profile' => ['website' => 'not-a-url', 'social_links' => ['myspace' => 'https://x.y'], 'date_of_birth' => now()->subYears(5)->toDateString()],
    ])->assertJsonValidationErrors(['country', 'profile.website', 'profile.social_links', 'profile.date_of_birth']);
});

it('uploads, replaces and removes an avatar', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $first = $this->actingAs($user)->post(api('me/avatar'), ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.avatar_url');
    $firstPath = $user->fresh()->avatar_path;
    Storage::disk('public')->assertExists($firstPath);
    expect($first)->toContain('avatars/'.$user->uuid);

    $this->actingAs($user)->post(api('me/avatar'), ['avatar' => UploadedFile::fake()->image('b.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('public')->assertMissing($firstPath);

    $this->actingAs($user)->deleteJson(api('me/avatar'))->assertOk()->assertJsonPath('data.avatar_url', null);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects non-images and oversized avatars', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)->post(api('me/avatar'), ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('avatar');
    $this->actingAs($user)->post(api('me/avatar'), ['avatar' => UploadedFile::fake()->image('big.jpg', 500, 500)->size(3000)], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('avatar');
});
