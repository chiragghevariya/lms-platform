<?php

use App\Models\User;

it('forbids students and instructors from admin endpoints', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())
        ->getJson(api('admin/users'))
        ->assertForbidden();
})->with(['student', 'instructor']);

it('lists, searches and filters users for admins', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->student()->create(['name' => 'Zara Unique']);
    User::factory()->count(3)->instructor()->create();

    $this->actingAs($admin)->getJson(api('admin/users?search=Zara'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zara Unique');

    $this->actingAs($admin)->getJson(api('admin/users?role=instructor'))
        ->assertOk()->assertJsonCount(3, 'data');

    $this->actingAs($admin)->getJson(api('admin/users?per_page=2'))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2);
});

it('shows a user by uuid', function () {
    $target = User::factory()->student()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(api("admin/users/{$target->uuid}"))
        ->assertOk()->assertJsonPath('data.id', $target->uuid)
        ->assertJsonMissingPath('data.permissions');
});

it('lets admins promote a student to instructor', function () {
    $target = User::factory()->student()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->putJson(api("admin/users/{$target->uuid}/roles"), ['roles' => ['instructor', 'student']])
        ->assertOk()->assertJsonPath('data.roles', ['instructor', 'student']);
});

it('prevents admins from granting staff roles or touching other staff', function () {
    $admin = User::factory()->admin()->create();
    $student = User::factory()->student()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson(api("admin/users/{$student->uuid}/roles"), ['roles' => ['admin']])->assertForbidden();
    $this->actingAs($admin)->putJson(api("admin/users/{$otherAdmin->uuid}/roles"), ['roles' => ['student']])->assertForbidden();
    $this->actingAs($admin)->putJson(api("admin/users/{$admin->uuid}/roles"), ['roles' => ['student']])->assertForbidden();
});

it('lets super admins grant admin', function () {
    $student = User::factory()->student()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->putJson(api("admin/users/{$student->uuid}/roles"), ['roles' => ['admin']])
        ->assertOk()->assertJsonPath('data.roles', ['admin']);
});

it('validates role names', function () {
    $student = User::factory()->student()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->putJson(api("admin/users/{$student->uuid}/roles"), ['roles' => ['wizard']])
        ->assertJsonValidationErrors('roles.0');
});
