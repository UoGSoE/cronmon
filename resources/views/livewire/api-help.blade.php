<div class="max-w-4xl">
    <flux:heading size="xl">API examples</flux:heading>
    <flux:text class="mt-2">
        Common things you can do from a terminal or a script, with a personal access token from your
        <flux:link :href="route('settings')" wire:navigate>settings page</flux:link>.
    </flux:text>

    <flux:callout class="mt-6" icon="key" variant="secondary">
        <flux:callout.heading>Set your token once</flux:callout.heading>
        <flux:callout.text>
            Examples below use the environment variable <code>CRONMON_API_TOKEN</code>. Put this in your shell rc file so every example below works as-is:
        </flux:callout.text>
        <flux:callout.text>
            <pre class="mt-2 text-sm">export CRONMON_API_TOKEN=&quot;paste-the-token-from-settings-here&quot;</pre>
        </flux:callout.text>
    </flux:callout>

    <flux:tab.group class="mt-6">
        <flux:tabs>
            <flux:tab name="curl">curl</flux:tab>
            <flux:tab name="python">python</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="curl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="sm">Am I authenticated?</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  {{ $baseUrl }}/api/v1/me</pre>
                </div>

                <div>
                    <flux:heading size="sm">List my personal jobs</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  "{{ $baseUrl }}/api/v1/jobs?scope=mine"</pre>
                </div>

                <div>
                    <flux:heading size="sm">List team jobs, filter by name, sort newest-checked-in first</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  "{{ $baseUrl }}/api/v1/jobs?scope=teams&filter[name]=backup&sort=-last_checked_in_at"</pre>
                </div>

                <div>
                    <flux:heading size="sm">Create a personal interval job (every day, 30 min grace)</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{
    "name": "Nightly backup",
    "schedule_interval": "daily",
    "schedule_frequency": 1,
    "grace_value": 30,
    "grace_units": "minutes"
  }' \
  {{ $baseUrl }}/api/v1/jobs</pre>
                </div>

                <div>
                    <flux:heading size="sm">Create a team-owned cron job (team_id 1)</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{
    "name": "Replication healthcheck",
    "team_id": 1,
    "cron_expression": "*/5 * * * *",
    "grace_value": 2,
    "grace_units": "minutes"
  }' \
  {{ $baseUrl }}/api/v1/jobs</pre>
                </div>

                <div>
                    <flux:heading size="sm">Silence a job until tomorrow</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{"silenced_until": "{{ now()->addDay()->toIso8601String() }}", "silence_reason": "Investigating"}' \
  {{ $baseUrl }}/api/v1/jobs/42/silence</pre>
                </div>

                <div>
                    <flux:heading size="sm">Get a job's check-in history</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  "{{ $baseUrl }}/api/v1/jobs/42/check-ins"</pre>
                </div>

                <div>
                    <flux:heading size="sm">Delete a job</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -H "Authorization: Bearer $CRONMON_API_TOKEN" \
  -X DELETE \
  {{ $baseUrl }}/api/v1/jobs/42</pre>
                </div>
            </div>
        </flux:tab.panel>

        <flux:tab.panel name="python">
            <div class="space-y-6">
                <flux:text size="sm">Examples use <code>requests</code>. Install with <code>pip install requests</code>.</flux:text>

                <div>
                    <flux:heading size="sm">Shared setup</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">import os, requests

BASE = "{{ $baseUrl }}/api/v1"
HEADERS = {"Authorization": f"Bearer {os.environ['CRONMON_API_TOKEN']}"}</pre>
                </div>

                <div>
                    <flux:heading size="sm">Am I authenticated?</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.get(f"{BASE}/me", headers=HEADERS)
r.raise_for_status()
print(r.json()["user"]["full_name"])</pre>
                </div>

                <div>
                    <flux:heading size="sm">List my personal jobs</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.get(f"{BASE}/jobs", headers=HEADERS, params={"scope": "mine"})
r.raise_for_status()
for job in r.json()["jobs"]["data"]:
    print(job["name"], job["is_overdue"])</pre>
                </div>

                <div>
                    <flux:heading size="sm">List team jobs, filter by name, sort newest-checked-in first</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.get(f"{BASE}/jobs", headers=HEADERS, params={
    "scope": "teams",
    "filter[name]": "backup",
    "sort": "-last_checked_in_at",
})
r.raise_for_status()</pre>
                </div>

                <div>
                    <flux:heading size="sm">Create a personal interval job (every day, 30 min grace)</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.post(f"{BASE}/jobs", headers=HEADERS, json={
    "name": "Nightly backup",
    "schedule_interval": "daily",
    "schedule_frequency": 1,
    "grace_value": 30,
    "grace_units": "minutes",
})
r.raise_for_status()
job = r.json()["data"]
print("Check-in URL:", f"{BASE.replace('/api/v1', '')}/check-in/{job['check_in_token']}")</pre>
                </div>

                <div>
                    <flux:heading size="sm">Create a team-owned cron job (team_id 1)</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.post(f"{BASE}/jobs", headers=HEADERS, json={
    "name": "Replication healthcheck",
    "team_id": 1,
    "cron_expression": "*/5 * * * *",
    "grace_value": 2,
    "grace_units": "minutes",
})
r.raise_for_status()</pre>
                </div>

                <div>
                    <flux:heading size="sm">Silence a job until tomorrow</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">from datetime import datetime, timedelta, timezone

r = requests.post(f"{BASE}/jobs/42/silence", headers=HEADERS, json={
    "silenced_until": (datetime.now(timezone.utc) + timedelta(days=1)).isoformat(),
    "silence_reason": "Investigating",
})
r.raise_for_status()</pre>
                </div>

                <div>
                    <flux:heading size="sm">Get a job's check-in history</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.get(f"{BASE}/jobs/42/check-ins", headers=HEADERS)
r.raise_for_status()
for ci in r.json()["check_ins"]["data"]:
    print(ci["checked_in_at"], ci["source_ip"])</pre>
                </div>

                <div>
                    <flux:heading size="sm">Delete a job</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">r = requests.delete(f"{BASE}/jobs/42", headers=HEADERS)
r.raise_for_status()</pre>
                </div>
            </div>
        </flux:tab.panel>
    </flux:tab.group>

    <flux:separator class="my-8" />

    <flux:heading size="lg">Check-in metadata</flux:heading>
    <flux:text class="mt-2">
        A check-in can carry a few numbers or short notes about the run, such as how many files a backup copied.
        Add them to the job's check-in URL as a query string. Numbers are stored as numbers, so they can be graphed,
        and they appear as extra columns in the job's recent check-ins and in its CSV download.
    </flux:text>
    <pre class="mt-4 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">curl -fsS "{{ $baseUrl }}/check-in/your-check-in-token?files=1234&amp;bytes=5678901"</pre>
    <flux:text class="mt-4">
        Up to 20 keys, key names up to 50 characters and values up to 255 characters. Anything over those limits
        is refused with a <code>422</code> and the check-in is not recorded, so a broken script shows up as a missed
        check-in rather than silently losing data.
    </flux:text>
    <flux:text class="mt-2">
        The query string ends up in web server and proxy access logs, so never put passwords, tokens or personal
        information in it.
    </flux:text>

    <flux:separator class="my-8" />

    <flux:heading size="lg">Prometheus metrics</flux:heading>
    <flux:text class="mt-2">
        Current estate figures are exposed at <code>{{ $baseUrl }}/metrics</code> in Prometheus text format, for
        scraping into Grafana. The endpoint needs a static bearer token: set <code>CRONMON_METRICS_TOKEN</code> in the
        environment and have Prometheus present it as the scrape credential. Until a token is set the endpoint returns
        <code>503</code>; a missing or wrong token gets <code>403</code>.
    </flux:text>

    <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
        <div>
            <flux:heading size="sm">Prometheus scrape config</flux:heading>
            <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">scrape_configs:
  - job_name: cronmon
    scheme: {{ $metricsScheme }}
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials: "your-CRONMON_METRICS_TOKEN"
    static_configs:
      - targets: ["{{ $metricsHost }}"]</pre>
        </div>

        <div>
            <flux:heading size="sm">Metrics exposed</flux:heading>
            <flux:text size="sm" class="mt-1">
                All gauges, each labelled by <code>team</code> — the shared personal bucket appears as
                <code>team="Personal"</code>. Sum a metric across teams in Grafana for an estate total.
            </flux:text>
            <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">cronmon_jobs_total{team="…"}             # monitored jobs
cronmon_jobs_alerting{team="…"}          # currently alerting
cronmon_jobs_silenced{team="…"}          # silenced (job or owner)
cronmon_jobs_never_checked_in{team="…"}  # never reported a check-in</pre>
        </div>
    </div>

    <flux:callout class="mt-8" icon="book-open" variant="secondary">
        <flux:callout.heading>Looking for the full reference?</flux:callout.heading>
        <flux:callout.text>
            <flux:link :href="$docsUrl" external>Auto-generated OpenAPI docs ↗</flux:link> list every endpoint and field.
            Note: those docs don't currently document the <code>filter[…]</code> query parameters or the <code>scope</code> filter on the jobs list endpoint — see the examples above for those.
        </flux:callout.text>
    </flux:callout>
</div>
