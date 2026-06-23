<?php

use App\Models\Job;
use App\Models\Team;
use App\Models\User;

it('returns 503 when no metrics token is configured', function () {
    config(['cronmon.metrics.token' => null]);

    $this->get(route('metrics'))->assertStatus(503);

    // Still 503 even if a caller presents a token — the feature is simply off.
    $this->withToken('anything')->get(route('metrics'))->assertStatus(503);
});

it('returns 403 when the token is missing or wrong', function () {
    config(['cronmon.metrics.token' => 'super-secret']);

    $this->get(route('metrics'))->assertForbidden();                       // none presented
    $this->withToken('wrong')->get(route('metrics'))->assertForbidden();   // wrong one
});

it('serves the estate as a prometheus exposition with a valid token', function () {
    config(['cronmon.metrics.token' => 'super-secret']);

    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $beta = Team::factory()->create(['name' => 'Beta "prod"']); // a quote, to prove escaping
    $ownerOne = User::factory()->create();
    $ownerTwo = User::factory()->create();

    // Alpha: one alerting, one healthy.
    Job::factory()->forTeam($alpha)->create(['alerting_since' => now()->subHour(), 'last_checked_in_at' => now()->subHours(3)]);
    Job::factory()->forTeam($alpha)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Beta "prod": one silenced (and checked in).
    Job::factory()->forTeam($beta)->silenced()->create(['last_checked_in_at' => now()->subMinutes(5)]);

    // Personal bucket lumps both owners: one alerting+never-checked-in, one healthy.
    Job::factory()->forUser($ownerOne)->create(['alerting_since' => now()->subHour()]);
    Job::factory()->forUser($ownerTwo)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    $response = $this->withToken('super-secret')->get(route('metrics'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->getContent())->toBe(file_get_contents(base_path('tests/fixtures/metrics.txt')));
});

it('leaves an ampersand in a team name as a literal, not an HTML entity', function () {
    config(['cronmon.metrics.token' => 'super-secret']);

    $team = Team::factory()->create(['name' => 'Resilience & Business Continuity']);
    Job::factory()->forTeam($team)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    $body = $this->withToken('super-secret')->get(route('metrics'))->getContent();

    expect($body)->toContain('team="Resilience & Business Continuity"')
        ->and($body)->not->toContain('&amp;');
});
