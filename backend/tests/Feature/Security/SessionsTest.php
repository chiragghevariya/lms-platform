<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['session.driver' => 'database']);
    $this->user = User::factory()->create();
    foreach (['sess-a' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/130', 'sess-b' => 'Mozilla/5.0 (iPhone) Safari/605'] as $id => $ua) {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1',
            'user_agent' => $ua, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
    }
});

it('lists browser sessions and app tokens without leaking session ids', function () {
    $token = $this->user->createToken('Pixel 8')->plainTextToken;

    $res = $this->withToken($token)->getJson(api('me/sessions'))->assertOk()->assertJsonCount(3, 'data');

    expect(collect($res->json('data'))->pluck('name')->all())->toContain('Chrome on macOS', 'Safari on iOS', 'Pixel 8')
        ->and($res->content())->not->toContain('sess-a')
        ->and(collect($res->json('data'))->firstWhere('name', 'Pixel 8')['is_current'])->toBeTrue();
});

it('revokes a single session or token', function () {
    $other = $this->user->createToken('old tablet');
    $token = $this->user->createToken('current')->plainTextToken;
    $list = collect($this->withToken($token)->getJson(api('me/sessions'))->json('data'));

    $browser = $list->firstWhere('name', 'Safari on iOS')['id'];
    $this->withToken($token)->deleteJson(api("me/sessions/{$browser}"))->assertNoContent();
    $this->withToken($token)->deleteJson(api("me/sessions/token_{$other->accessToken->id}"))->assertNoContent();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and($this->user->tokens()->count())->toBe(1);
});

it('cannot revoke another user\'s token', function () {
    $foreign = User::factory()->create()->createToken('theirs');

    $this->actingAs($this->user)->deleteJson(api("me/sessions/token_{$foreign->accessToken->id}"))->assertNotFound();
});

it('logs out other devices after confirming the password', function () {
    $this->user->createToken('a');
    $current = $this->user->createToken('current')->plainTextToken;

    $this->withToken($current)->postJson(api('me/sessions/logout-others'), ['password' => 'wrong'])
        ->assertJsonValidationErrors('password');

    $this->withToken($current)->postJson(api('me/sessions/logout-others'), ['password' => 'password'])
        ->assertOk()->assertJsonPath('data.revoked', 3);

    expect($this->user->tokens()->pluck('name')->all())->toBe(['current'])
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0);
});
