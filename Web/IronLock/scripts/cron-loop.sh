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
# interval — observed as */24, */21, */16, */17, */15 and */22 across separate
# weeks, sometimes staggered between the two lines, sometimes not. Nothing in
# this repository writes the crontab. Re-editing it by hand has been reverted
# every time.
#
# Rather than fight for `* * * * *`, this script inverts the problem: cron
# launches it at WHATEVER interval the host allows, and the script itself ticks
# the scheduler every 60 seconds from the inside. The host can pick any interval
# it likes; the scheduler still runs once a minute.
#
# WHY THE LOOP DOES NOT EXIT ON A TIMER
# -------------------------------------
# An earlier revision recycled every 30 minutes (MAX_RUNTIME=1800) and let cron
# relaunch it. That reintroduced the exact bug it was meant to fix. With a host
# interval I, the dead window between the loop exiting and the next cron launch
# is `ceil(1800/I)*I - 1800`:
#
#     */16 →   2 min gap   (survivable)
#     */15 →   0           (exit races the relaunch for the flock; loser exits,
#                           so the scheduler stops for a FULL interval)
#     */21 →  12 min gap
#     */22 →  14 min gap
#
# */21 and */22 are both intervals this host has actually chosen. So the loop now
# runs indefinitely by default (MAX_RUNTIME=0) and cron is demoted to pure crash
# recovery. A bash loop that sleeps and shells out does not accumulate state, so
# periodic recycling bought nothing and cost a recurring outage.
#
# The case that timed recycling *did* cover — a wedged process that is alive but
# no longer ticking — is now covered properly by the heartbeat takeover below.
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
#   - flock: only one instance ever ticks. A relaunch while a healthy loop is
#     alive exits immediately instead of doubling up every scheduled job.
#   - Heartbeat takeover: the running loop stamps a heartbeat every tick. A new
#     instance that cannot get the lock reads it — if the heartbeat is stale the
#     holder is wedged, so the newcomer kills it and takes over. This is what
#     makes an indefinite loop safe: a hung process self-heals within one cron
#     interval, rather than blocking the scheduler forever.
#   - Each tick is wrapped so a failing job can never kill the loop.
#   - Ticks are aligned to the wall-clock minute, so exactly one schedule:run
#     lands per clock minute — no drift into double-fires or skipped minutes.
#   - If the host reaps long-running user processes, cron relaunch restores the
#     loop within one interval. `grep started storage/logs/cron-loop.log` shows
#     how often that is happening.
#   - A healthy tick writes NOTHING to the log — only starts, takeovers and
#     non-zero exits are logged — so the log stays tiny despite never rotating.
#     If it ever grows, that itself is the signal something is wrong.

set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
LOCK_FILE="${APP_DIR}/storage/framework/cron-loop.lock"
HEARTBEAT_FILE="${APP_DIR}/storage/framework/cron-loop.heartbeat"

# 0 = run indefinitely (the default; see the rationale above). Set a positive
# number of seconds only if you have a specific reason to bound a run — and be
# aware it reopens the exit/relaunch gap described above.
MAX_RUNTIME="${MAX_RUNTIME:-0}"
TICK_SECONDS="${TICK_SECONDS:-60}"

# A heartbeat older than this means the holder is wedged, not working. Must stay
# comfortably above TICK_SECONDS (a slow tick is not a wedge) and at or below
# catchup_staleness_seconds (180s), so a wedge is displaced before the
# dispatchers would start discarding marks.
STALE_AFTER="${STALE_AFTER:-180}"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

if ! command -v flock >/dev/null 2>&1; then
    log "FATAL: flock not found — refusing to run without a singleton guard"
    exit 1
fi

mkdir -p "$(dirname "$LOCK_FILE")" 2>/dev/null

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    # Someone holds the lock. Healthy and ticking, or wedged?
    hb_ts=0
    hb_pid=""
    if [ -f "$HEARTBEAT_FILE" ]; then
        read -r hb_ts hb_pid < "$HEARTBEAT_FILE" 2>/dev/null || { hb_ts=0; hb_pid=""; }
    fi
    case "$hb_ts" in ''|*[!0-9]*) hb_ts=0 ;; esac

    if [ "$hb_ts" -eq 0 ]; then
        # Lock held but no readable heartbeat: an older revision of this script
        # (which never wrote one), or the file was removed. Either way the holder
        # is not provably ticking, so take the lock rather than trust it.
        log "lock held but no valid heartbeat — taking over from pid ${hb_pid:-unknown}"
    else
        age=$(( $(date +%s) - hb_ts ))
        if [ "$age" -le "$STALE_AFTER" ]; then
            # A healthy loop owns the tick. Nothing to do.
            exit 0
        fi
        log "heartbeat stale (${age}s > ${STALE_AFTER}s) — taking over from pid ${hb_pid:-unknown}"
    fi

    if [ -n "$hb_pid" ] && kill -0 "$hb_pid" 2>/dev/null; then
        kill -TERM "$hb_pid" 2>/dev/null
        for _ in 1 2 3 4 5; do
            kill -0 "$hb_pid" 2>/dev/null || break
            sleep 1
        done
        kill -0 "$hb_pid" 2>/dev/null && kill -KILL "$hb_pid" 2>/dev/null
    fi

    if ! flock -w 15 9; then
        log "could not acquire lock after takeover attempt — leaving the incumbent alone"
        exit 0
    fi
    log "took over the lock"
fi

cd "$APP_DIR" || exit 1

started_at=$(date +%s)
if [ "$MAX_RUNTIME" -gt 0 ]; then
    log "cron-loop started (pid $$, max ${MAX_RUNTIME}s, tick ${TICK_SECONDS}s)"
else
    log "cron-loop started (pid $$, runs indefinitely, tick ${TICK_SECONDS}s)"
fi

while true; do
    printf '%s %s\n' "$(date +%s)" "$$" > "$HEARTBEAT_FILE"

    # The scheduler itself. Failures are logged by Laravel; never abort the loop.
    "$PHP_BIN" artisan schedule:run >/dev/null 2>&1 || \
        log "schedule:run exited non-zero"

    # Drain queued work in the same tick. --stop-when-empty returns immediately
    # when idle, so this costs nothing on a quiet minute. Bounded so it can never
    # eat into the next tick.
    "$PHP_BIN" artisan queue:work --stop-when-empty --max-time=45 --tries=3 >/dev/null 2>&1 || \
        log "queue:work exited non-zero"

    now=$(date +%s)
    if [ "$MAX_RUNTIME" -gt 0 ] && [ $(( now - started_at )) -ge "$MAX_RUNTIME" ]; then
        break
    fi

    # Sleep to the next wall-clock minute boundary rather than a flat 60s. A tick
    # that takes 20s must not push the next one to :20, then :40, then skip a
    # minute entirely — schedule:run is evaluated per clock minute.
    if [ "$TICK_SECONDS" -eq 60 ]; then
        remaining=$(( 60 - 10#$(date +%S) ))
    else
        remaining=$(( TICK_SECONDS - (now - started_at) % TICK_SECONDS ))
    fi
    [ "$remaining" -le 0 ] && remaining=1
    sleep "$remaining"
done

log "cron-loop exiting after $(( $(date +%s) - started_at ))s"
