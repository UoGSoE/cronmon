<div class="max-w-5xl">
    <flux:heading size="xl">Admin</flux:heading>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <a href="{{ route('admin.teams.index') }}" wire:navigate class="h-full">
            <flux:card class="h-full">
                <flux:heading size="sm">Teams</flux:heading>
                <flux:text size="sm" class="mt-1">Create teams, manage membership, silence as a group.</flux:text>
            </flux:card>
        </a>

        <a href="{{ route('admin.users.index') }}" wire:navigate class="h-full">
            <flux:card class="h-full">
                <flux:heading size="sm">Users</flux:heading>
                <flux:text size="sm" class="mt-1">Edit, promote and remove user accounts.</flux:text>
            </flux:card>
        </a>

        <a href="{{ route('admin.api-tokens.index') }}" wire:navigate class="h-full">
            <flux:card class="h-full">
                <flux:heading size="sm">API tokens</flux:heading>
                <flux:text size="sm" class="mt-1">Audit and revoke API tokens across every user.</flux:text>
            </flux:card>
        </a>
    </div>

    <flux:separator class="my-8" />

    <flux:heading size="lg">Estate health</flux:heading>
    <flux:text class="mt-2">A quick read on where the estate is.</flux:text>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
            <div class="flex h-8 items-center text-sm font-medium text-zinc-600 dark:text-zinc-400">Total jobs</div>
            <div class="text-4xl font-bold text-zinc-900 dark:text-zinc-100">{{ $totalCount }}</div>
        </div>

        <div class="rounded-lg bg-red-100 p-4 dark:bg-red-900/40">
            <div class="flex h-8 items-center text-sm font-medium text-red-800 dark:text-red-300">Alerting</div>
            <div class="text-4xl font-bold text-red-900 dark:text-red-100">{{ $alertingCount }}</div>
        </div>

        <div class="rounded-lg bg-amber-100 p-4 dark:bg-amber-900/40">
            <div class="flex h-8 items-center text-sm font-medium text-amber-800 dark:text-amber-300">Silenced</div>
            <div class="text-4xl font-bold text-amber-900 dark:text-amber-100">{{ $silencedCount }}</div>
        </div>

        <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
            <div class="flex h-8 items-center gap-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                Never checked in
                <flux:tooltip toggleable>
                    <flux:button icon="information-circle" size="sm" variant="ghost" />
                    <flux:tooltip.content class="max-w-[20rem]">
                        Jobs that exist in Cronmon but have never reported a check-in — usually a job set up but not yet wired to its check-in URL.
                    </flux:tooltip.content>
                </flux:tooltip>
            </div>
            <div class="text-4xl font-bold text-zinc-900 dark:text-zinc-100">{{ $neverCheckedInCount }}</div>
        </div>
    </div>

    <div class="mt-8">
        <flux:heading size="lg">Alerting jobs</flux:heading>

        @if ($alertingJobs->isEmpty())
            <flux:callout variant="success" class="mt-4">
                <flux:callout.text>Nothing alerting. Every job has checked in within its window.</flux:callout.text>
            </flux:callout>
        @else
            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>Job</flux:table.column>
                    <flux:table.column>Owner</flux:table.column>
                    <flux:table.column>Alerting since</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($alertingJobs as $job)
                        <flux:table.row wire:key="alerting-{{ $job->id }}">
                            <flux:table.cell>
                                <flux:link :href="route('jobs.show', $job)" wire:navigate>{{ $job->name }}</flux:link>
                                @if ($job->isCurrentlySilenced())
                                    <flux:badge color="amber" size="sm" class="ml-2">Silenced</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $job->team?->name ?? $job->user?->full_name }}</flux:table.cell>
                            <flux:table.cell>{{ $job->alerting_since->format('j M Y, H:i') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    @if ($breakdownRows->isNotEmpty())
        <div class="mt-8">
            <div class="flex items-center gap-1">
                <flux:heading size="lg">By team</flux:heading>
                <flux:tooltip toggleable>
                    <flux:button icon="information-circle" size="sm" variant="ghost" />
                    <flux:tooltip.content class="max-w-[20rem]">
                        A job that is alerting but currently silenced is counted in both columns, so the columns can add up to more than the total.
                    </flux:tooltip.content>
                </flux:tooltip>
            </div>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>Team</flux:table.column>
                    <flux:table.column align="end">Alerting</flux:table.column>
                    <flux:table.column align="end">Silenced</flux:table.column>
                    <flux:table.column align="end">Never checked in</flux:table.column>
                    <flux:table.column align="end">Total</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($breakdownRows as $row)
                        <flux:table.row wire:key="breakdown-{{ $loop->index }}">
                            <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['alerting'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['silenced'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['never_checked_in'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['total'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
