#Requires -Version 5.1
<#
.SYNOPSIS
  Register a Windows server's existing scheduled tasks in Cronmon.

.DESCRIPTION
  Reads this server's scheduled tasks (Get-ScheduledTask), maps each one's
  schedule to a cron expression, shows a preview of the jobs it would create,
  asks once for confirmation, then registers each via the Cronmon API and prints
  a check-in line for you to paste into your own scripts.

  It NEVER edits your scheduled tasks. This is a one-shot onboarding aid, not a
  sync agent -- re-run it (or use the web UI) when a schedule changes.

  Not every task can be monitored. Tasks that fire on an event (logon, boot,
  on-idle, on-event) have no recurring schedule a watchdog can represent, and a
  few recurrence shapes have no clean cron equivalent. Those are skipped and
  reported -- never guessed. Expect less than 100% coverage of a box; that is a
  property of watchdogs, not a bug.

  ALWAYS run with -DryRun first and read the preview. The "When" column (what we
  think the schedule means) and the "Next run" column (Windows' own next run
  time) let you confirm each mapping is right BEFORE anything is created.

  See ENROLLMENT.md for the full design and the matching bash importer
  (cronmon-import.sh) for the Unix-cron equivalent.

.PARAMETER ApiUrl
  Base URL of your Cronmon install (env: CRONMON_URL).

.PARAMETER Token
  API token with the jobs:write ability (env: CRONMON_TOKEN). Mint one from the
  My Settings page.

.PARAMETER Team
  Assign the imported jobs to a team you belong to, by id. Omit to keep them on
  your own account.

.PARAMETER GraceHours
  Grace period in hours before a job is counted overdue (default 24).

.PARAMETER TaskExport
  Read tasks from a task export instead of the live machine. Create one on a
  Windows box with:

      Get-ScheduledTask | Export-Clixml -Depth 4 tasks.xml

  Pass -Depth so the nested trigger details survive serialisation (the default
  depth can drop them). This is how the importer is exercised on a non-Windows
  box (pwsh on macOS/Linux): the schedule mapping can be checked against a real
  export with -DryRun. Windows' "next run time" is not available from an export.

.PARAMETER DryRun
  Show the preview and exit without creating anything.

.PARAMETER Yes
  Skip the confirmation prompt (for non-interactive use).

.PARAMETER Insecure
  Skip TLS certificate verification. Dev / internal-CA hosts only -- .NET's trust
  store is stricter than curl's, so a local Cronmon may need this.

.EXAMPLE
  # See what it would do, without touching anything:
  .\cronmon-import.ps1 -ApiUrl https://cronmon.example -Token <token> -DryRun

.EXAMPLE
  # Test the mapping on a Mac against a real export from a Windows box:
  .\cronmon-import.ps1 -TaskExport tasks.xml -DryRun
#>

[CmdletBinding()]
param(
    [string]$ApiUrl = $env:CRONMON_URL,
    [string]$Token = $env:CRONMON_TOKEN,
    [string]$Team,
    [int]$GraceHours = 24,
    [string]$TaskExport,
    [switch]$DryRun,
    [switch]$Yes,
    [switch]$Insecure
)

function Die([string]$Message) {
    [Console]::Error.WriteLine("Error: $Message")
    exit 1
}

# --- TLS handling ---------------------------------------------------------

# Windows PowerShell 5.1 ships on every Windows box but defaults to old TLS and
# has no -SkipCertificateCheck switch, so we handle both eras here.
if ($PSVersionTable.PSVersion.Major -lt 6) {
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    if ($Insecure) {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    }
}
elseif ($Insecure) {
    $PSDefaultParameterValues['Invoke-RestMethod:SkipCertificateCheck'] = $true
}

# --- schedule mapping helpers --------------------------------------------
#
# Windows scheduled-task triggers are CIM objects. Each trigger kind exposes
# different properties; the day/month selections are bitmasks. These helpers
# turn a single trigger into a 5-field cron expression, or report why it can't
# be monitored. They read only properties (no methods), so they also work on the
# deserialized objects you get back from Import-Clixml -- which is what makes the
# -TaskExport test path possible on a non-Windows machine.

function New-MapResult {
    param([bool]$Ok, [string]$Cron, [string]$Description, [string]$Reason)

    return [pscustomobject]@{ Ok = $Ok; Cron = $Cron; Description = $Description; Reason = $Reason }
}

function Get-TriggerKind($Trigger) {
    # On a live machine the CIM class name is the reliable signal. Export-Clixml
    # may drop it, so fall back to which properties the trigger carries.
    $class = $null
    try { $class = [string]$Trigger.CimClass.CimClassName } catch { $class = $null }
    if ($class) { return $class }

    $props = $Trigger.PSObject.Properties.Name
    if ($props -contains 'DaysInterval') { return 'MSFT_TaskDailyTrigger' }
    if ($props -contains 'WeeksInterval') { return 'MSFT_TaskWeeklyTrigger' }
    if ($props -contains 'WeeksOfMonth') { return 'MSFT_TaskMonthlyDOWTrigger' }
    if ($props -contains 'DaysOfMonth') { return 'MSFT_TaskMonthlyTrigger' }
    if ($props -contains 'StartBoundary') { return 'MSFT_TaskTimeTrigger' }

    return 'unknown'
}

function Get-TriggerTimeOfDay($Trigger) {
    # StartBoundary is an ISO-8601 string like "2026-06-22T02:00:00". Read the
    # literal HH:MM out of it rather than parsing to a DateTime, which would risk
    # a timezone shift -- the boundary time is already the local run time.
    $boundary = [string]$Trigger.StartBoundary
    if ($boundary -match 'T(\d{2}):(\d{2})') {
        return [pscustomobject]@{ Hour = [int]$Matches[1]; Minute = [int]$Matches[2] }
    }

    return $null
}

function Get-RepetitionInterval($Trigger) {
    # A trigger can repeat (e.g. "every 15 minutes") via a Repetition pattern.
    $repetition = $Trigger.Repetition
    if (-not $repetition) { return $null }

    $interval = [string]$repetition.Interval
    if ([string]::IsNullOrEmpty($interval)) { return $null }

    return $interval
}

function ConvertFrom-DaysOfWeekMask([int]$Mask) {
    # Weekly trigger mask: Sun=1 Mon=2 Tue=4 Wed=8 Thu=16 Fri=32 Sat=64.
    # cron day-of-week: Sun=0 .. Sat=6.
    $days = @(
        @{ Bit = 1; Cron = 0; Name = 'Sun' },
        @{ Bit = 2; Cron = 1; Name = 'Mon' },
        @{ Bit = 4; Cron = 2; Name = 'Tue' },
        @{ Bit = 8; Cron = 3; Name = 'Wed' },
        @{ Bit = 16; Cron = 4; Name = 'Thu' },
        @{ Bit = 32; Cron = 5; Name = 'Fri' },
        @{ Bit = 64; Cron = 6; Name = 'Sat' }
    )

    $crons = @()
    $names = @()
    foreach ($day in $days) {
        if ($Mask -band $day.Bit) {
            $crons += $day.Cron
            $names += $day.Name
        }
    }

    return [pscustomobject]@{ Cron = ($crons -join ','); Names = ($names -join ', ') }
}

function ConvertFrom-DaysOfMonthMask([long]$Mask) {
    # Bit 0 (value 1) is day 1, bit 30 (value 2^30) is day 31.
    $selected = @()
    for ($i = 0; $i -lt 31; $i++) {
        if ($Mask -band ([long]1 -shl $i)) { $selected += ($i + 1) }
    }

    return $selected
}

function ConvertFrom-MonthsMask([int]$Mask) {
    # Bit 0 is January .. bit 11 is December. All twelve set means "every month".
    if ($Mask -eq 0 -or $Mask -eq 4095) { return '*' }

    $months = @()
    for ($i = 0; $i -lt 12; $i++) {
        if ($Mask -band (1 -shl $i)) { $months += ($i + 1) }
    }

    return ($months -join ',')
}

function ConvertTo-CronFromTrigger($Trigger) {
    $kind = Get-TriggerKind $Trigger
    $repetition = Get-RepetitionInterval $Trigger

    # A base schedule (daily/weekly/monthly) that also repeats within the day is
    # two schedules in one; cron can't say "daily at 02:00 then every 15 min", so
    # we report it rather than silently pick one.
    $repetitionOnBase = $repetition -and ($kind -notlike '*TimeTrigger')
    if ($repetitionOnBase) {
        return New-MapResult $false '' '' 'has a base schedule plus a repeat-every; add it in the UI'
    }

    switch -Wildcard ($kind) {
        '*DailyTrigger' {
            $interval = [int]$Trigger.DaysInterval
            if ($interval -gt 1) {
                return New-MapResult $false '' '' "runs every $interval days; cron can't express that cleanly"
            }
            $time = Get-TriggerTimeOfDay $Trigger
            if (-not $time) { return New-MapResult $false '' '' 'no start time on the daily trigger' }

            $cron = '{0} {1} * * *' -f $time.Minute, $time.Hour
            $desc = 'every day at {0:00}:{1:00}' -f $time.Hour, $time.Minute
            return New-MapResult $true $cron $desc ''
        }

        '*WeeklyTrigger' {
            $interval = [int]$Trigger.WeeksInterval
            if ($interval -gt 1) {
                return New-MapResult $false '' '' "runs every $interval weeks; cron can't express that cleanly"
            }
            $time = Get-TriggerTimeOfDay $Trigger
            if (-not $time) { return New-MapResult $false '' '' 'no start time on the weekly trigger' }

            $week = ConvertFrom-DaysOfWeekMask ([int]$Trigger.DaysOfWeek)
            if (-not $week.Cron) { return New-MapResult $false '' '' 'no weekdays set on the weekly trigger' }

            $cron = '{0} {1} * * {2}' -f $time.Minute, $time.Hour, $week.Cron
            $desc = 'every {0} at {1:00}:{2:00}' -f $week.Names, $time.Hour, $time.Minute
            return New-MapResult $true $cron $desc ''
        }

        '*MonthlyDOWTrigger' {
            return New-MapResult $false '' '' "monthly by weekday (e.g. 'first Monday') has no cron equivalent; add it in the UI"
        }

        '*MonthlyTrigger' {
            if ($Trigger.RunOnLastDayOfMonth) {
                return New-MapResult $false '' '' 'runs on the last day of the month; no cron equivalent'
            }
            $time = Get-TriggerTimeOfDay $Trigger
            if (-not $time) { return New-MapResult $false '' '' 'no start time on the monthly trigger' }

            $days = ConvertFrom-DaysOfMonthMask ([long]$Trigger.DaysOfMonth)
            if (-not $days) { return New-MapResult $false '' '' 'no day-of-month set on the monthly trigger' }
            $months = ConvertFrom-MonthsMask ([int]$Trigger.MonthsOfYear)

            $cron = '{0} {1} {2} {3} *' -f $time.Minute, $time.Hour, ($days -join ','), $months
            $monthText = if ($months -eq '*') { 'every month' } else { "months $months" }
            $desc = 'on day {0} of {1} at {2:00}:{3:00}' -f ($days -join ','), $monthText, $time.Hour, $time.Minute
            return New-MapResult $true $cron $desc ''
        }

        '*TimeTrigger' {
            # A one-time trigger only repeats if it carries a Repetition pattern.
            if (-not $repetition) {
                return New-MapResult $false '' '' 'one-time schedule; nothing recurring to monitor'
            }
            if ($repetition -match '^PT(\d+)M$') {
                $minutes = [int]$Matches[1]
                if ($minutes -ge 1 -and $minutes -le 59 -and (60 % $minutes) -eq 0) {
                    return New-MapResult $true "*/$minutes * * * *" "every $minutes minutes" ''
                }
                return New-MapResult $false '' '' "repeats every $minutes minutes, which doesn't divide the hour evenly; add it in the UI"
            }
            if ($repetition -match '^PT(\d+)H$') {
                $hours = [int]$Matches[1]
                if ($hours -ge 1 -and $hours -le 23 -and (24 % $hours) -eq 0) {
                    return New-MapResult $true "0 */$hours * * *" "every $hours hours" ''
                }
                return New-MapResult $false '' '' "repeats every $hours hours, which doesn't divide the day evenly; add it in the UI"
            }
            return New-MapResult $false '' '' "repeat interval '$repetition' has no clean cron equivalent; add it in the UI"
        }

        default {
            return New-MapResult $false '' '' "triggers on an event, not a schedule; nothing for a watchdog to monitor"
        }
    }
}

function ConvertTo-CronFromTask($Task) {
    $triggers = @($Task.Triggers)
    if ($triggers.Count -eq 0) {
        return New-MapResult $false '' '' 'no triggers (started manually)'
    }

    $mapped = @()
    $reasons = @()
    foreach ($trigger in $triggers) {
        $result = ConvertTo-CronFromTrigger $trigger
        if ($result.Ok) { $mapped += $result } else { $reasons += $result.Reason }
    }

    if ($mapped.Count -eq 0) {
        return New-MapResult $false '' '' ($reasons | Select-Object -First 1)
    }
    if ($mapped.Count -gt 1) {
        return New-MapResult $false '' '' 'has several schedules; add it in the UI so you can split it into jobs'
    }

    return $mapped[0]
}

# --- reading the task list ------------------------------------------------

function Get-TaskList {
    if ($TaskExport) {
        if (-not (Test-Path -LiteralPath $TaskExport)) { Die "task export not found: $TaskExport" }
        return @(Import-Clixml -LiteralPath $TaskExport)
    }

    if (-not (Get-Command Get-ScheduledTask -ErrorAction SilentlyContinue)) {
        Die "Get-ScheduledTask is not available. Run this on Windows, or pass -TaskExport <file> (a 'Get-ScheduledTask | Export-Clixml' dump)."
    }

    return @(Get-ScheduledTask)
}

function Get-NextRun($Task) {
    # Windows' own opinion of when the task next fires -- the cross-check that
    # lets you confirm the cron mapping is right. Not available from an export.
    if ($TaskExport) { return '' }

    try {
        $info = Get-ScheduledTaskInfo -TaskName $Task.TaskName -TaskPath $Task.TaskPath -ErrorAction Stop
        if ($info.NextRunTime) { return '{0:yyyy-MM-dd HH:mm}' -f $info.NextRunTime }
    }
    catch { }

    return ''
}

# --- the API calls --------------------------------------------------------

function New-CronmonJob($Name, $Cron) {
    $payload = @{
        name            = $Name
        cron_expression = $Cron
        grace_value     = $GraceHours
        grace_units     = 'hours'
    }
    if ($Team) { $payload.team_id = [int]$Team }

    $headers = @{ Authorization = "Bearer $Token"; Accept = 'application/json' }

    return Invoke-RestMethod -Method Post -Uri "$ApiUrl/api/v1/jobs" `
        -Headers $headers -ContentType 'application/json' `
        -Body ($payload | ConvertTo-Json -Compress)
}

# --- validate inputs ------------------------------------------------------

# A dry-run preview works offline with no credentials (handy against a fixture).
if (-not $DryRun) {
    if (-not $ApiUrl) { Die 'no API URL set (use -ApiUrl or CRONMON_URL)' }
    if (-not $Token) { Die 'no API token set (use -Token or CRONMON_TOKEN)' }
}
$ApiUrl = $ApiUrl.TrimEnd('/')

# --- build the preview ----------------------------------------------------

$tasks = Get-TaskList

$jobs = @()
$skipped = @()
$systemSkipped = 0

foreach ($task in $tasks) {
    # The estate's own \Microsoft\ maintenance tasks are noise -- nobody monitors
    # the disk defragmenter in Cronmon. Skip them quietly.
    if ($task.TaskPath -like '\Microsoft\*') {
        $systemSkipped++
        continue
    }

    $result = ConvertTo-CronFromTask $task
    if (-not $result.Ok) {
        $skipped += "$($task.TaskName): $($result.Reason)"
        continue
    }

    $jobs += [pscustomobject]@{
        Name       = $task.TaskName
        Schedule   = $result.Cron
        When       = $result.Description
        'Next run' = Get-NextRun $task
    }
}

$ownerLabel = if ($Team) { "team #$Team" } else { 'your own account' }

Write-Host ''
Write-Host 'Cronmon import preview'
Write-Host ("  Target:  {0}" -f $(if ($ApiUrl) { $ApiUrl } else { '<not set>' }))
Write-Host ("  Owner:   {0}" -f $ownerLabel)
Write-Host ("  Grace:   {0}h" -f $GraceHours)
Write-Host ''

if ($jobs.Count -eq 0) {
    Write-Host '  No monitorable scheduled tasks found.'
}
else {
    $jobs | Format-Table -AutoSize | Out-String | Write-Host
}

if ($skipped.Count -gt 0) {
    Write-Host '  Skipped:'
    foreach ($entry in $skipped) { Write-Host "    - $entry" }
    Write-Host ''
}

if ($systemSkipped -gt 0) {
    Write-Host "  Ignored $systemSkipped built-in \Microsoft\ task(s)."
    Write-Host ''
}

if ($DryRun) {
    Write-Host 'Dry run -- nothing was created.'
    exit 0
}

if ($jobs.Count -eq 0) { exit 0 }

# --- confirm --------------------------------------------------------------

if (-not $Yes) {
    $answer = Read-Host "Create these $($jobs.Count) job(s) in Cronmon? [y/N]"
    if ($answer -notmatch '^(y|yes)$') {
        Write-Host 'Aborted.'
        exit 0
    }
}

# --- create ---------------------------------------------------------------

Write-Host ''
$created = 0

foreach ($job in $jobs) {
    try {
        $response = New-CronmonJob $job.Name $job.Schedule
    }
    catch {
        [Console]::Error.WriteLine("  [fail] $($job.Name) -- failed to create (check the URL, token and team membership)")
        continue
    }

    # The API wraps a single resource in a "data" key, so the new job's token is
    # at .data.check_in_token, not .check_in_token.
    $checkinToken = $response.data.check_in_token
    if (-not $checkinToken) {
        [Console]::Error.WriteLine("  [fail] $($job.Name) -- created, but no check-in token was returned")
        continue
    }

    $checkinUrl = "$ApiUrl/check-in/$checkinToken"

    # An initial check-in resets the overdue clock so a batch import doesn't alert
    # straight away, and proves this server can reach Cronmon. A failure here is a
    # warning, not a fatal error.
    try {
        Invoke-RestMethod -Uri $checkinUrl -Method Get | Out-Null
    }
    catch {
        [Console]::Error.WriteLine("  [warn] $($job.Name) -- created, but the initial check-in failed (is $ApiUrl reachable from here?)")
    }

    $created++
    Write-Host "  [ok] $($job.Name)"
    Write-Host "      paste into your task's script:  curl -fsS $checkinUrl"
}

Write-Host ''
Write-Host "Done -- created $created of $($jobs.Count) job(s)."
