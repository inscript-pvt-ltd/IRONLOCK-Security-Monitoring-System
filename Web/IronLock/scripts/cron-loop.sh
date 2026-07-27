#!/bin/bash
#
# cron-loop.sh — drive Laravel's scheduler every 60s from a slow crontab entry.
#
# WHY THIS EXISTS
# ---------------
# IronLock's minute-jobs (photo + wakefulness dispatchers, timeout sweeps) assume
# `schedule:run` fires once a minute. They are not catch-up jobs: a dispatcher
# SKIPS any schedule mark older than config('ironlock.catchup_staleness_seconds')
# (180s) and only ever fires the LATEST due-unfired mark per run. So an interval
# crontab does not DELAY a missed check — it DELETES it. At */22 the vast
# majority of due checks are silently dropped.
#
# The production host (cPanel shared hosting) rewrites the crontab back to an
# interval — observed as */24, */21, */16, */17 and */22 across separate weeks,
# sometimes staggered between the two lines, sometimes not. Nothing in this
# repository writes the crontab. Re-editing it by hand has been reverted every
# time.
#
# Rather than fight for `* * * * *`, this script inverts the problem: cron
# launches it at WHATEVER interval the host allows, and the script itself ticks
# the scheduler every 60 seconds from the inside. The host can pick any interval
# it likes; the scheduler still runs once a minute.
#
# INSTALL (production crontab — the interval is deliberately irrelevant)
#
#   */5 * * * * /home/ayrcujte/public_html/scripts/cron-loop.sh >> /home/ayrcujte/public_html/storage/logs/cron-loop.log 2>&1
#
# Then REMOVE BOTH old lines (`artisan schedule:run` AND `artisan queue:work`) —
# this script runs both itself. Leaving the old schedule:run is harmless (flock
# serialises it away), but a second queue:work would compete for jobs.
#
#   chmod +x scripts/cron-loop.sh
#
# SAFETY
#   - flock: only one instance ever runs. If the host re-launches us while a
#     loop is still alive, the new one exits immediately instead of doubling up
#     every scheduled job.
#   - MAX_RUNTIME: the loop exits well short of forever, so a wedged process
#     cannot linger. Cron relaunching us is the restart mechanism, which also
#     means a crash self-heals within one cron interval.
#   - Each tick is wrapped so a failing job can never kill the loop.

set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
LOCK_FILE="${APP_DIR}/storage/framework/cron-loop.lock"

# Exit before the next cron launch would overlap in the common case, but stay
# long enough to cover a slow interval. 1800s = 30 min comfortably exceeds every
# interval the host has picked so far, so the scheduler never goes idle.
MAX_RUNTIME="${MAX_RUNTIME:-1800}"
TICK_SECONDS="${TICK_SECONDS:-60}"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    # Another loop owns the lock and is already ticking. Nothing to do.
    exit 0
fi

cd "$APP_DIR" || exit 1

started_at=$(date +%s)
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] cron-loop started (pid $$, max ${MAX_RUNTIME}s, tick ${TICK_SECONDS}s)"

while true; do
    tick_start=$(date +%s)

    # The scheduler itself. Failures are logged by Laravel; never abort the loop.
    "$PHP_BIN" artisan schedule:run >/dev/null 2>&1 || \
        echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] schedule:run exited non-zero"

    # Drain queued work in the same tick. --stop-when-empty returns immediately
    # when idle, so this costs nothing on a quiet minute. Bounded so it can never
    # eat into the next tick.
    "$PHP_BIN" artisan queue:work --stop-when-empty --max-time=45 --tries=3 >/dev/null 2>&1 || \
        echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] queue:work exited non-zero"

    now=$(date +%s)
    [ $(( now - started_at )) -ge "$MAX_RUNTIME" ] && break

    # Sleep only the remainder of the tick, so a slow run doesn't push the
    # scheduler past the next minute boundary and skip a mark.
    elapsed=$(( now - tick_start ))
    remaining=$(( TICK_SECONDS - elapsed ))
    [ "$remaining" -gt 0 ] && sleep "$remaining"
done

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] cron-loop exiting after $(( $(date +%s) - started_at ))s"
