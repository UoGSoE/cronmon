<?php

namespace App\Console\Commands;

use App\Models\Job;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cronmon:evaluate')]
#[Description('Evaluate every monitored job and send alert emails for those that are overdue.')]
class CronmonEvaluate extends Command
{
    public function handle(): int
    {
        Job::query()->each(function (Job $job): void {
            if ($job->isCurrentlySilenced()) {
                return;
            }

            if (! $job->isOverdue()) {
                return;
            }

            if (! $job->isAlerting()) {
                $job->markAsAlerting();
                $job->sendAlert();

                return;
            }

            $nextDue = $job->nextScheduledAfter($job->last_alerted_at)->addMinutes($job->graceMinutes());

            if (now()->greaterThanOrEqualTo($nextDue)) {
                $job->sendAlert();
            }
        });

        return self::SUCCESS;
    }
}
