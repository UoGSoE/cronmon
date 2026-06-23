<?php

use App\Models\Job;
use App\Models\Team;
use App\Models\User;

it('forbids non-admins from the admin dashboard', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
});

it('shows the admin dashboard to admin users', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
});

it('shows the estate health overview to an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create(['name' => 'Platform']);
    $owner = User::factory()->create();

    Job::factory()->forTeam($team)->create([
        'name' => 'Nightly backup',
        'alerting_since' => now()->subHours(2),
        'last_checked_in_at' => now()->subHours(5),
    ]);
    Job::factory()->forUser($owner)->create(['alerting_since' => now()->subHour()]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Nightly backup')   // the alerting problem list
        ->assertSee('Platform')          // the by-team breakdown
        ->assertSee('Personal')          // the shared personal bucket
        ->assertSee('Teams');            // existing Manage nav cards remain
});

it('shows a reassuring empty state when nothing is alerting', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    Job::factory()->forTeam($team)->create(['last_checked_in_at' => now()->subMinutes(5)]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Nothing alerting')
        ->assertDontSee('Alerting since'); // the problem-list table is not rendered
});
