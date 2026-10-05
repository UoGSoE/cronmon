<?php

namespace Database\Factories;

use App\Models\CheckIn;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'checked_in_at' => now(),
            'source_ip' => fake()->ipv4(),
        ];
    }

    public function withBackupMetadata(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => [
                'files' => fake()->numberBetween(10_000, 2_000_000),
                'bytes' => fake()->numberBetween(1_000_000_000, 500_000_000_000),
            ],
        ]);
    }
}
