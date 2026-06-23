<?php

use App\Models\Job;
use App\Models\Team;
use App\Models\User;
use App\Services\EstateStats;

it('counts the estate by total, alerting, silenced and never-checked-in', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();

    // Healthy: checked in recently, not alerting, not silenced.
    Job::factory()->forTeam($team)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Alerting team job (has checked in before, now overdue and flagged).
    Job::factory()->forTeam($team)->alerting()->create(['last_checked_in_at' => now()->subHours(3)]);

    // Silenced team job (checked in, silenced by its own window).
    Job::factory()->forTeam($team)->silenced()->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Never checked in (data quality) — factory leaves last_checked_in_at null.
    Job::factory()->forTeam($team)->create();

    // Alerting personal job — counts towards the estate-wide totals too.
    Job::factory()->forUser($owner)->alerting()->create(['last_checked_in_at' => now()->subHours(3)]);

    $stats = new EstateStats;

    expect($stats->totalCount())->toBe(5)
        ->and($stats->alertingCount())->toBe(2)
        ->and($stats->silencedCount())->toBe(1)
        ->and($stats->neverCheckedInCount())->toBe(1);
});

it('lists alerting jobs longest-alerting first, across teams and personal', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();

    $longest = Job::factory()->forTeam($team)->create(['alerting_since' => now()->subHours(5)]);
    $newest = Job::factory()->forUser($owner)->create(['alerting_since' => now()->subHours(1)]);
    $middle = Job::factory()->forTeam($team)->create(['alerting_since' => now()->subHours(3)]);

    // Not alerting — must not appear.
    Job::factory()->forTeam($team)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    $alerting = (new EstateStats)->alertingJobs();

    expect($alerting->pluck('id')->all())->toBe([$longest->id, $middle->id, $newest->id]);
});

it('breaks the estate down by team, ordered by name, with one shared personal bucket last', function () {
    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $beta = Team::factory()->create(['name' => 'Beta']);
    Team::factory()->create(['name' => 'Gamma']); // no jobs — must not appear

    $ownerOne = User::factory()->create();
    $ownerTwo = User::factory()->create();

    // Alpha: one alerting, one healthy.
    Job::factory()->forTeam($alpha)->create(['alerting_since' => now()->subHour(), 'last_checked_in_at' => now()->subHours(3)]);
    Job::factory()->forTeam($alpha)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Beta: one silenced.
    Job::factory()->forTeam($beta)->silenced()->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Personal bucket lumps both owners' jobs together (decision: one bucket, not per-user).
    Job::factory()->forUser($ownerOne)->create(['alerting_since' => now()->subHour()]); // alerting + never-checked-in
    Job::factory()->forUser($ownerTwo)->create(['last_checked_in_at' => now()->subMinutes(5)]); // healthy

    $rows = (new EstateStats)->breakdownRows();

    expect($rows->pluck('label')->all())->toBe(['Alpha', 'Beta', 'Personal']);

    $alphaRow = $rows->firstWhere('label', 'Alpha');
    expect($alphaRow['total'])->toBe(2)
        ->and($alphaRow['alerting'])->toBe(1)
        ->and($alphaRow['silenced'])->toBe(0)
        ->and($alphaRow['never_checked_in'])->toBe(0)
        ->and($alphaRow['alerting_pct'])->toBe(50.0);

    $personalRow = $rows->firstWhere('label', 'Personal');
    expect($personalRow['total'])->toBe(2)
        ->and($personalRow['alerting'])->toBe(1)
        ->and($personalRow['silenced'])->toBe(0)
        ->and($personalRow['never_checked_in'])->toBe(1);
});
