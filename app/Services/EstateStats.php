<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * The single source of truth for the cronmon estate summary, shared by the
 * management dashboard and (later) the Prometheus /metrics endpoint. Reuses the
 * job-health logic on the Job model rather than recomputing it — see ADR
 * cronmon-C8ggs and epic cronmon-lmiGA.
 */
class EstateStats
{
    /** @var Collection<int, Job>|null */
    private ?Collection $jobs = null;

    /**
     * The whole monitored estate, loaded once with owners eager-loaded. Every
     * job belongs to exactly one of a team or a user, so this is the complete
     * set — there is no decommissioned or triage population to exclude.
     *
     * @return Collection<int, Job>
     */
    public function jobs(): Collection
    {
        return $this->jobs ??= Job::query()->with(['team', 'user'])->get();
    }

    public function totalCount(): int
    {
        return $this->jobs()->count();
    }

    /**
     * The alerting jobs for the dashboard's problem list, longest-alerting
     * first (oldest alerting_since), so the most overdue work sits at the top.
     *
     * @return Collection<int, Job>
     */
    public function alertingJobs(): Collection
    {
        return $this->jobs()
            ->filter(fn (Job $job) => $job->isAlerting())
            ->sortBy(fn (Job $job) => $job->alerting_since->timestamp)
            ->values();
    }

    /**
     * Jobs flagged as alerting — raw alerting_since, the same definition the
     * home page and the evaluator use. Deliberately NOT reduced by silencing:
     * a silenced-but-alerting job stays counted so management can still see it.
     */
    public function alertingCount(): int
    {
        return $this->jobs()
            ->filter(fn (Job $job) => $job->isAlerting())
            ->count();
    }

    public function silencedCount(): int
    {
        return $this->jobs()
            ->filter(fn (Job $job) => $job->isCurrentlySilenced())
            ->count();
    }

    public function neverCheckedInCount(): int
    {
        return $this->jobs()
            ->filter(fn (Job $job) => $job->hasNeverCheckedIn())
            ->count();
    }

    /**
     * Per-team breakdown rows plus one estate-wide personal bucket, for the
     * dashboard's "by team" table. Teams are ordered by name and only appear if
     * they own at least one job; the personal bucket — all user-owned jobs
     * lumped together — comes last. Worst-in-column highlighting is left to the
     * caller, as it depends on the dashboard's absolute/percentage view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function breakdownRows(): Collection
    {
        $jobs = $this->jobs();

        $rows = Team::query()
            ->orderBy('name')
            ->get()
            ->map(function (Team $team) use ($jobs) {
                $teamJobs = $jobs->where('team_id', $team->id);

                return $teamJobs->isEmpty() ? null : $this->rowFor($team->name, $teamJobs);
            })
            ->filter()
            ->values();

        $personalJobs = $jobs->whereNotNull('user_id');

        if ($personalJobs->isNotEmpty()) {
            $rows->push($this->rowFor('Personal', $personalJobs));
        }

        return $rows->values();
    }

    /**
     * Build one breakdown row — the four counts and their percentages — for a
     * named bucket of jobs.
     *
     * @param  Collection<int, Job>  $jobs
     * @return array<string, mixed>
     */
    private function rowFor(string $label, Collection $jobs): array
    {
        $total = $jobs->count();
        $alerting = $jobs->filter(fn (Job $job) => $job->isAlerting())->count();
        $silenced = $jobs->filter(fn (Job $job) => $job->isCurrentlySilenced())->count();
        $neverCheckedIn = $jobs->filter(fn (Job $job) => $job->hasNeverCheckedIn())->count();

        return [
            'label' => $label,
            'total' => $total,
            'alerting' => $alerting,
            'silenced' => $silenced,
            'never_checked_in' => $neverCheckedIn,
            'alerting_pct' => $this->pct($alerting, $total),
            'silenced_pct' => $this->pct($silenced, $total),
            'never_checked_in_pct' => $this->pct($neverCheckedIn, $total),
        ];
    }

    private function pct(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole * 100, 1);
    }
}
