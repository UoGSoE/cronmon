<?php

namespace App\Http\Controllers;

use App\Services\EstateStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    /**
     * Expose the estate figures in Prometheus text exposition format. Guarded by
     * a single static bearer token from config (CRONMON_METRICS_TOKEN); the token
     * being unset disables the endpoint entirely. No SSO, no per-user auth — one
     * trusted scraper, one credential.
     */
    public function __invoke(Request $request): Response
    {
        $token = config('cronmon.metrics.token');

        if ($token === null || $token === '') {
            return response('Metrics endpoint not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return response($this->exposition(new EstateStats))
            ->header('Content-Type', 'text/plain; version=0.0.4');
    }

    /**
     * Build the exposition by hand — the format is trivial and the estate is
     * small, so a Prometheus client library would be more moving parts than
     * payload. Every metric is a gauge labelled by bucket (team name, or
     * "Personal" for the shared personal bucket); one line per bucket, zeros
     * included. The numbers come from the shared EstateStats service so a scrape
     * and the dashboard can never disagree.
     */
    private function exposition(EstateStats $stats): string
    {
        $metrics = [
            ['cronmon_jobs_total', 'Monitored jobs per team.', 'total'],
            ['cronmon_jobs_alerting', 'Jobs currently alerting (raw alerting_since).', 'alerting'],
            ['cronmon_jobs_silenced', 'Jobs currently silenced (job or owner).', 'silenced'],
            ['cronmon_jobs_never_checked_in', 'Jobs that have never reported a check-in.', 'never_checked_in'],
        ];

        $rows = $stats->breakdownRows();
        $lines = [];

        foreach ($metrics as [$name, $help, $key]) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} gauge";

            foreach ($rows as $row) {
                $lines[] = sprintf('%s{team="%s"} %d', $name, $this->escapeLabel($row['label']), $row[$key]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Escape a Prometheus label value: backslash, double-quote and newline.
     * Team names are free-form (max 255, no escaping at input), so this must not
     * be skipped — an unescaped quote would emit a malformed exposition.
     */
    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
