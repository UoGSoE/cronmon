<?php

use App\Models\User;

it('forbids a non-staff user from the application', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($student)->get(route('home'))->assertForbidden();
});

it('allows a staff user into the application', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('home'))->assertOk();
});
