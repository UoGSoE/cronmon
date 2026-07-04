<?php

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('removes a user from a team', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $team->users()->attach([$alice->id, $bob->id]);
    Sanctum::actingAs($admin, ['admin:write']);

    expect($team->users()->count())->toBe(2);

    $this->deleteJson("/api/v1/admin/teams/{$team->id}/members/{$alice->id}")
        ->assertNoContent();

    $remaining = $team->users()->pluck('users.id')->all();
    expect($remaining)->not->toContain($alice->id)
        ->and($remaining)->toContain($bob->id);
});

it('adds a user to a team', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $alice = User::factory()->create();
    Sanctum::actingAs($admin, ['admin:write']);

    $this->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => $alice->id])
        ->assertCreated();

    expect($team->users()->pluck('users.id')->all())->toContain($alice->id);
});
