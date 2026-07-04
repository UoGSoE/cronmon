<?php

use App\Models\Job;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns 404 when patching a job the user cannot see', function () {
    $alice = User::factory()->create(['is_admin' => false]);
    $bob = User::factory()->create();
    $bobsJob = Job::factory()->forUser($bob)->create(['name' => 'Hands off']);
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$bobsJob->id}", ['name' => 'Mine now'])->assertStatus(404);

    expect($bobsJob->fresh()->name)->toBe('Hands off');
});

it('patches a job the user owns', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create([
        'name' => 'Old name',
        'description' => 'Old desc',
        'grace_value' => 5,
        'grace_units' => 'minutes',
    ]);
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$job->id}", [
        'name' => 'New name',
        'grace_value' => 30,
    ])->assertOk();

    $fresh = $job->fresh();
    expect($fresh->name)->toBe('New name')
        ->and($fresh->description)->toBe('Old desc')
        ->and($fresh->grace_value)->toBe(30);
});

it('rejects patching a job with an unparseable cron expression', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->withCron('0 2 * * *')->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$job->id}", [
        'cron_expression' => 'every tuesday plz',
    ])->assertStatus(422);

    expect($job->fresh()->cron_expression)->toBe('0 2 * * *');
});

it('updates a job location and can clear it via the API', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create(['location' => 'Rankine']);
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$job->id}", ['location' => 'Joseph Black'])
        ->assertOk()
        ->assertJsonPath('data.location', 'Joseph Black');
    expect($job->fresh()->location)->toBe('Joseph Black');

    $this->patchJson("/api/v1/jobs/{$job->id}", ['location' => null])
        ->assertOk()
        ->assertJsonPath('data.location', null);
    expect($job->fresh()->location)->toBeNull();
});

it('rejects a patch that would leave a job with no owner', function () {
    $alice = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($alice);
    $job = Job::factory()->forTeam($team)->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$job->id}", ['team_id' => null])->assertStatus(422);

    expect($job->fresh()->team_id)->toBe($team->id)
        ->and($job->fresh()->user_id)->toBeNull();
});

it('rejects a patch that would give a personal job a second owner', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create();
    $team = Team::factory()->create();
    $team->users()->attach($alice);
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->patchJson("/api/v1/jobs/{$job->id}", ['team_id' => $team->id])->assertStatus(422);

    expect($job->fresh()->user_id)->toBe($alice->id)
        ->and($job->fresh()->team_id)->toBeNull();
});
