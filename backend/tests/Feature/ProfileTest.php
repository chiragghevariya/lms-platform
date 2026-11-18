<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns the current user with roles and permissions', function () {
    $user = User::factory()->instructor()->create();

    $this->actingAs($user)->getJson(api('me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->uuid)
        ->assertJsonPath('data.roles', ['instructor'])
        ->assertJsonPath('data.permissions', ['courses.manage-own', 'live.manage-own'])
        ->assertJsonPath('data.membership.tier', null);
});

it('updates the profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson(api('me'), ['name' => 'New Name', 'timezone' => 'Asia/Kolkata'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.timezone', 'Asia/Kolkata');
});

it('rejects an invalid timezone', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson(api('me'), ['timezone' => 'Mars/Base'])
        ->assertJsonValidationErrors('timezone');
});

it('changes password with the current password', function () {
    $user = User::factory()->create();
    $user->createToken('other-device');

    $this->actingAs($user)->putJson(api('me/password'), [
        'current_password' => 'password', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
    ])->assertOk();

    expect(Hash::check('brandnew99', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('requires the correct current password', function () {
    $this->actingAs(User::factory()->create())->putJson(api('me/password'), [
        'current_password' => 'wrong', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
    ])->assertJsonValidationErrors('current_password');
});

it('reports affiliate status from the affiliate programme (Phase 7 replaced one-click activation)', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson(api('me'))->assertJsonPath('data.affiliate.active', false)->assertJsonPath('data.affiliate.code', null);

    $a = makeAffiliate([], $user);
    $this->actingAs($user)->getJson(api('me'))->assertJsonPath('data.affiliate.active', true)->assertJsonPath('data.affiliate.code', $a->code);
});

it('keeps the calling token but revokes others on password change', function () {
    $user = User::factory()->create();
    $current = $user->createToken('this-phone')->plainTextToken;
    $user->createToken('old-tablet');

    $this->withToken($current)->putJson(api('me/password'), [
        'current_password' => 'password', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
    ])->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['this-phone']);
});

it('answers the session probe with 200 for guests and the user when signed in', function () {
    $this->getJson(api('auth/session'))->assertOk()->assertJsonPath('data', null);
    $u = User::factory()->create();
    $this->actingAs($u)->getJson(api('auth/session'))->assertOk()->assertJsonPath('data.id', $u->uuid)->assertJsonPath('data.permissions', []);
});
