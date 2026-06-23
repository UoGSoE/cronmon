<?php

use App\Models\User;

it('redirects guests away from /api/help', function () {
    $this->get(route('api.help'))->assertRedirect();
});

it('renders /api/help for an authenticated user', function () {
    $alice = User::factory()->create();

    $this->actingAs($alice)
        ->get(route('api.help'))
        ->assertOk()
        ->assertSee('CRONMON_API_TOKEN')
        ->assertSee('curl');
});

it('documents the prometheus metrics endpoint on /api/help', function () {
    $alice = User::factory()->create();

    $this->actingAs($alice)
        ->get(route('api.help'))
        ->assertOk()
        ->assertSee('Prometheus')
        ->assertSee('/metrics')
        ->assertSee('CRONMON_METRICS_TOKEN')
        ->assertSee('scrape_configs')
        ->assertSee('cronmon_jobs_alerting');
});
