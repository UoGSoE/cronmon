#!/usr/bin/env bash
#
# cronmon-import.sh — register a server's existing cron jobs in Cronmon.
#
# Reads this server's crontab (or a file, or piped input), shows a preview of
# the jobs it would create, asks once for confirmation, then registers each
# one via the Cronmon API and prints a check-in line for you to paste into
# your own scripts.
#
# It NEVER edits your crontab. This is a one-shot onboarding aid, not a sync
# agent — re-run it (or use the web UI) when a schedule changes.
#
# See ENROLLMENT.md for the full design.

set -euo pipefail

API_URL="${CRONMON_URL:-}"
AUTH_TOKEN="${CRONMON_TOKEN:-}"
TEAM_ID=""
GRACE_HOURS=24
CRONFILE=""
DRY_RUN=0
ASSUME_YES=0

usage() {
    cat <<'USAGE'
Usage: cronmon-import.sh [options]

  --api-url URL      Base URL of your Cronmon install (env: CRONMON_URL)
  --token TOKEN      API token with the jobs:write ability (env: CRONMON_TOKEN)
  --team ID          Assign the imported jobs to a team you belong to
  --grace-hours N    Grace period in hours before a job is overdue (default: 24)
  --cronfile PATH    Read the schedule from a file instead of `crontab -l`
  --dry-run          Show the preview and exit without creating anything
  --yes, -y          Skip the confirmation prompt (for non-interactive use)
  -h, --help         Show this help

Input precedence: piped stdin > --cronfile > `crontab -l`.
USAGE
}

die() {
    echo "Error: $*" >&2
    exit 1
}

# --- parse arguments ------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --api-url)     API_URL=$2; shift 2 ;;
        --token)       AUTH_TOKEN=$2; shift 2 ;;
        --team)        TEAM_ID=$2; shift 2 ;;
        --grace-hours) GRACE_HOURS=$2; shift 2 ;;
        --cronfile)    CRONFILE=$2; shift 2 ;;
        --dry-run)     DRY_RUN=1; shift ;;
        --yes|-y)      ASSUME_YES=1; shift ;;
        -h|--help)     usage; exit 0 ;;
        *)             die "unknown option: $1 (try --help)" ;;
    esac
done

# Credentials are only needed once we actually create jobs, so a --dry-run
# preview works offline with no token (handy for trying it against a fixture).
if [ "$DRY_RUN" -eq 0 ]; then
    [ -n "$API_URL" ]    || die "no API URL set (use --api-url or CRONMON_URL)"
    [ -n "$AUTH_TOKEN" ] || die "no API token set (use --token or CRONMON_TOKEN)"
fi
API_URL=${API_URL%/}

# --- read the schedule source --------------------------------------------

read_source() {
    if [ ! -t 0 ]; then
        cat
    elif [ -n "$CRONFILE" ]; then
        [ -f "$CRONFILE" ] || die "cron file not found: $CRONFILE"
        cat "$CRONFILE"
    else
        crontab -l 2>/dev/null || true
    fi
}

SCHEDULE=$(read_source)

# --- parse into parallel arrays ------------------------------------------

names=()
schedules=()
commands=()
skipped=()

while IFS= read -r line || [ -n "$line" ]; do
    trimmed=${line#"${line%%[![:space:]]*}"}

    [ -z "$trimmed" ] && continue                       # blank line
    case "$trimmed" in \#*) continue ;; esac            # comment

    # environment assignment (FOO=bar) — not a schedule line
    if [[ "$trimmed" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
        continue
    fi

    # @reboot has no schedule a watchdog can monitor
    case "$trimmed" in
        @reboot*) skipped+=("@reboot has no monitorable schedule: $trimmed"); continue ;;
    esac

    if [[ "$trimmed" == @* ]]; then
        # macro line, e.g. "@daily /path/to/backup.sh"
        schedule=${trimmed%%[[:space:]]*}
        command=${trimmed#*[[:space:]]}
    else
        # standard line: first five fields are the expression, the rest the command
        schedule=$(echo "$trimmed" | awk '{print $1, $2, $3, $4, $5}')
        command=$(echo "$trimmed" | awk '{for (i = 6; i <= NF; i++) printf "%s%s", $i, (i < NF ? " " : "")}')
    fi

    command=${command#"${command%%[![:space:]]*}"}     # trim leading whitespace

    if [ -z "$command" ]; then
        skipped+=("no command found: $trimmed")
        continue
    fi

    first_token=${command%%[[:space:]]*}
    names+=("$(basename "$first_token")")
    schedules+=("$schedule")
    commands+=("$command")
done <<< "$SCHEDULE"

# --- preview --------------------------------------------------------------

owner_label="your own account"
[ -n "$TEAM_ID" ] && owner_label="team #$TEAM_ID"

echo
echo "Cronmon import preview"
echo "  Target:  ${API_URL:-<not set>}"
echo "  Owner:   $owner_label"
echo "  Grace:   ${GRACE_HOURS}h"
echo

if [ ${#names[@]} -eq 0 ]; then
    echo "  No monitorable jobs found in the schedule."
else
    printf "  %-24s %-18s %s\n" "NAME" "SCHEDULE" "COMMAND"
    for i in "${!names[@]}"; do
        printf "  %-24s %-18s %s\n" "${names[$i]}" "${schedules[$i]}" "${commands[$i]}"
    done
fi

if [ ${#skipped[@]} -gt 0 ]; then
    echo
    echo "  Skipped:"
    for entry in "${skipped[@]}"; do
        echo "    - $entry"
    done
fi
echo

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Dry run — nothing was created."
    exit 0
fi

[ ${#names[@]} -eq 0 ] && exit 0

# --- confirm --------------------------------------------------------------

if [ "$ASSUME_YES" -ne 1 ]; then
    if [ ! -e /dev/tty ]; then
        die "no terminal available to confirm; re-run with --yes to proceed"
    fi
    printf "Create these %d job(s) in Cronmon? [y/N] " "${#names[@]}" > /dev/tty
    read -r answer < /dev/tty
    case "$answer" in
        [yY]|[yY][eE][sS]) ;;
        *) echo "Aborted."; exit 0 ;;
    esac
fi

# --- create ---------------------------------------------------------------

json_escape() {
    local s=$1
    s=${s//\\/\\\\}
    s=${s//\"/\\\"}
    printf '%s' "$s"
}

create_job() {
    local name=$1 schedule=$2 team_field=""
    [ -n "$TEAM_ID" ] && team_field=",\"team_id\":$TEAM_ID"

    local payload
    payload=$(printf '{"name":"%s","cron_expression":"%s","grace_value":%s,"grace_units":"hours"%s}' \
        "$(json_escape "$name")" "$(json_escape "$schedule")" "$GRACE_HOURS" "$team_field")

    curl -fsS -X POST "$API_URL/api/v1/jobs" \
        -H "Authorization: Bearer $AUTH_TOKEN" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        -d "$payload"
}

echo
created=0
for i in "${!names[@]}"; do
    name=${names[$i]}

    if ! response=$(create_job "$name" "${schedules[$i]}"); then
        echo "  ✗ $name — failed to create (check the URL, token and team membership)" >&2
        continue
    fi

    # The API wraps the job in a "data" key; grepping the raw body finds the
    # token wherever it sits, so we don't need a JSON parser here.
    checkin_token=$(printf '%s' "$response" | grep -oE '"check_in_token":"[^"]+"' | head -n1 | sed -E 's/.*:"([^"]+)"/\1/' || true)

    if [ -z "$checkin_token" ]; then
        echo "  ✗ $name — created, but no check-in token was returned" >&2
        continue
    fi

    checkin_url="$API_URL/check-in/$checkin_token"

    # An initial check-in resets the overdue clock so a batch import doesn't
    # alert straight away, and proves this server can reach Cronmon. A failure
    # here is a warning, not a fatal error.
    if ! curl -fsS "$checkin_url" >/dev/null 2>&1; then
        echo "  ⚠ $name — created, but the initial check-in failed (is $API_URL reachable from here?)" >&2
    fi

    created=$((created + 1))
    echo "  ✓ $name"
    echo "      paste into your job's script:  curl -fsS $checkin_url"
done

echo
echo "Done — created $created of ${#names[@]} job(s)."
