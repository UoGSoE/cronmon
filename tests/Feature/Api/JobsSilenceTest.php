<?php

use App\Models\Job;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('unsilences a previously silenced job', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->silenced()->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->deleteJson("/api/v1/jobs/{$job->id}/silence")->assertOk();

    $fresh = $job->fresh();
    expect($fresh->silenced_until)->toBeNull()
        ->and($fresh->silence_reason)->toBeNull();
});

it('silencing twice is idempotent and overwrites with the latest values', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $first = now()->addDay()->startOfSecond();
    $second = now()->addDays(3)->startOfSecond();

    $this->postJson("/api/v1/jobs/{$job->id}/silence", [
        'silenced_until' => $first->toIso8601String(),
        'silence_reason' => 'short',
    ])->assertOk();

    $this->postJson("/api/v1/jobs/{$job->id}/silence", [
        'silenced_until' => $second->toIso8601String(),
        'silence_reason' => 'longer',
    ])->assertOk();

    $fresh = $job->fresh();
    expect($fresh->silenced_until->equalTo($second))->toBeTrue()
        ->and($fresh->silence_reason)->toBe('longer');
});

it('silences a job until a future moment', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create([
        'silenced_until' => null,
        'silence_reason' => null,
    ]);
    Sanctum::actingAs($alice, ['jobs:write']);

    $until = now()->addDay()->startOfSecond();

    $this->postJson("/api/v1/jobs/{$job->id}/silence", [
        'silenced_until' => $until->toIso8601String(),
        'silence_reason' => 'On leave',
    ])->assertOk();

    $fresh = $job->fresh();
    expect($fresh->silenced_until->equalTo($until))->toBeTrue()
        ->and($fresh->silence_reason)->toBe('On leave');
});

it('returns 404 silencing a job the user cannot see', function () {
    $alice = User::factory()->create();
    $bobsJob = Job::factory()->forUser(User::factory()->create())->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->postJson("/api/v1/jobs/{$bobsJob->id}/silence", [
        'silenced_until' => now()->addDay()->toIso8601String(),
    ])->assertStatus(404);

    expect($bobsJob->fresh()->silenced_until)->toBeNull();
});

it('returns 404 unsilencing a job the user cannot see', function () {
    $alice = User::factory()->create();
    $bobsJob = Job::factory()->forUser(User::factory()->create())->silenced()->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->deleteJson("/api/v1/jobs/{$bobsJob->id}/silence")->assertStatus(404);

    expect($bobsJob->fresh()->silenced_until)->not->toBeNull();
});

it('rejects silencing with a past silenced_until and leaves the job unsilenced', function () {
    $alice = User::factory()->create();
    $job = Job::factory()->forUser($alice)->create();
    Sanctum::actingAs($alice, ['jobs:write']);

    $this->postJson("/api/v1/jobs/{$job->id}/silence", [
        'silenced_until' => now()->subDay()->toIso8601String(),
    ])->assertStatus(422);

    expect($job->fresh()->silenced_until)->toBeNull();
});
