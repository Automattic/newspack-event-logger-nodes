---
name: event-logger-nodes-debugging
description: Debugging the event-logger-nodes application — dashboards, memcache stats schema, hub/spoke routing, SSE controllers, reqgrep, and the request-lifecycle pipeline. Use when something visible to users is wrong (stats not showing, dashboards stuck, SSE drops, requests not being assembled, jobs not running).
argument-hint: "[symptom]"
---

# Event Logger Nodes Debugging

Application-side debugging. For substrate-level questions (workers stuck, REPL semantics, log layout under `{base}/`), see the `nodes-debugging` skill in newspack-nodes.

## When to Use

- Dashboard panels show "no data" or stale data
- A specific request's timeline isn't assembling correctly
- SSE connections drop or fail
- A job handler doesn't appear to fire
- Hub/spoke aggregation is misbehaving (replicas seeing too few or too many entries)

## Filter the firehose: `wp nodes reqgrep`

The application-aware view of the substrate's firehose. Decodes the Message envelope, unwraps `Message::VALUE`, and renders one request at a time with timestamps, indentation, and process (start)/(complete) bracketing.

```bash
# Most-recent segment forward (fast).
docker exec eve-pyrobase1-1 wp nodes reqgrep --recent \
    --allow-root --path=/var/www/html

# All segments (slow but thorough).
docker exec eve-pyrobase1-1 wp nodes reqgrep \
    --allow-root --path=/var/www/html

# Filter to a pattern (regex against the formatted line).
docker exec eve-pyrobase1-1 wp nodes reqgrep /admin-ajax \
    --allow-root --path=/var/www/html

# Specific request id.
docker exec eve-pyrobase1-1 wp nodes reqgrep abc123def \
    --allow-root --path=/var/www/html

# Live tail.
docker exec -t eve-pyrobase1-1 wp nodes reqgrep --follow \
    --allow-root --path=/var/www/html
```

The output handles multiple wire formats — both the new positional Message envelope (live) and legacy entry-shape JSON (archives, stdin pipes), so feeding it old captures still works.

## Inspecting via the REPL

Pivot into a worker to see node state:

```bash
docker exec -it eve-pyrobase1-1 wp nodes cli firehose-workers.p0 \
    --allow-root --path=/var/www/html
```

From the prompt:

```
ls -alos                                # all nodes with sinks/owners/counters
dump request-builder                    # state of RequestBuilder, including LRU cache stats
dump firehose:tee                       # see the Tee's targets
ls -a request-builder                   # all nodes that sink into request-builder
```

For job-workers / request-workers / aggregator pivots, use the matching reader id (`job-workers.p0`, etc.).

## Memcache stats schema

Stats live in memcache only — never on disk. Per-partition prefix is `evlog[:salt]:p{N}:`, then 9 namespaces: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`.

```bash
# Connect to memcache (default 127.0.0.1:11211).
docker exec -it eve-memcache1-1 sh -c 'echo "stats slabs" | nc localhost 11211'

# Find the active salt.
docker exec eve-pyrobase1-1 wp option get newspack_nodes_stats_salt \
    --allow-root --path=/var/www/html

# Force a flush (rotates salt, all old keys orphan via TTL).
docker exec eve-pyrobase1-1 wp option update newspack_nodes_stats_salt $(openssl rand -hex 4) \
    --allow-root --path=/var/www/html
docker exec eve-pyrobase1-1 wp nodes restart firehose-workers --all-partitions \
    --allow-root --path=/var/www/html  # pick up new salt
```

**Caps to remember**: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category survives capping.

**Per-URL flame stats TTL** is `max(3600, max_lifespan/24)`. All other namespaces use the full `max_lifespan`. If a URL hasn't been seen in over an hour, its flame data is gone even if other stats remain.

## Dashboards

Page slugs (URL path: `/wp-admin/admin.php?page=<slug>`):

| Slug | What |
|------|------|
| `newspack-nodes` | Substrate settings (base directory, partitions, retention, memcache servers) |
| `newspack-event-logger-nodes` | Application settings (log filters, hooks to instrument, hub/spoke config) |
| `newspack-nodes-performance` | Performance overview (URL leaderboard, breakdown by server / status / category) |
| `newspack-nodes-performance&request=<rid>` | URL drilldown with the request rendered inline |
| `newspack-nodes-stream` | Live firehose tail |
| `newspack-nodes-gyroscope` | In-flight request timeline visualization |
| `newspack-nodes-rawlogs` | Browse raw log lines |
| `newspack-nodes-errors` | Error log dashboard |
| `newspack-nodes-workers` | Worker health + live position dashboard |

If a dashboard says "Connection lost", check (in this order):
1. The page enqueues its build via the page-arg map in `newspack-event-logger-nodes.php` — does the slug match?
2. `restUrl` in localized `NewspackNodesData` is bare `/wp-json/`, not pre-namespaced.
3. The relevant REST controller is hooked on `rest_api_init`.
4. Browser DevTools network tab shows the actual REST URL it tried to hit.

If panels are blank but the page renders: verify memcache is actually running (`docker ps | grep memcache`). Stats path is fail-soft — empty results, not errors, when memcache is unreachable.

## SSE controllers

All SSE endpoints share `SSEControllerBase`:
- Heartbeat every 5 seconds via `send_sse_event('heartbeat', ...)`
- `flush_if_needed()` before sleeping (avoids a per-message flush that would fight nginx buffering)
- Slot rate-limiting via memcache (fail-CLOSED — HTTP 429 if memcache is down)

If you're getting unexpected 429s, the slot pool is exhausted. Look at the SSE-slot key in memcache.

If clients reconnect every few seconds: the SSE stream might be timing out due to a missing heartbeat. Check the controller's poll loop — every iteration must emit either a real event or a heartbeat within 5s.

## Hub / spoke routing

A node is a hub if `enable_workers === true` (strict). Hubs pull remote firehoses via StreamMerger and rewrite ingested `k:"job"` lines to `k:"remote_job"` so spoke-side handlers don't double-execute on the hub.

Diagnostic flow:

```bash
# Is this node a hub?
docker exec eve-pyrobase1-1 wp option get newspack_event_logger_nodes_enable_workers \
    --allow-root --path=/var/www/html

# Aggregator status (hub-side).
curl -sk "https://www.bendsource.com/wp-json/newspack-nodes-aggregator/v1/status"

# Per-server health (hub-side).
curl -sk "https://www.bendsource.com/wp-json/newspack-nodes-aggregator/v1/health"
```

If a hub is missing entries from a spoke: check StreamMerger's reconnect log. cURL drops 5s of data on reconnect (single-segment seek); if your spoke is bouncing frequently the hub will see gaps.

## Common failure modes

**Dashboard rate-limit hit immediately.** `RATE_LIMIT_REQUESTS=600` per minute. If you're getting 429s from a single browser, multiple panels are fanning out. Check if a recent change duplicated a `/performance/overview` call (one panel = one call, ideally).

**`reqgrep --recent` shows nothing but the firehose is being written.** Two possibilities: the LogManager early-returned (e.g., running as root), so no entries are being written; OR the firehose path doesn't match (check `Newspack_Event_Logger_Nodes\Config::get_logs_directory()`).

**Worker positions are stale on the workers dashboard.** Consumer publishes its position to memcache every ~10 seconds via `np:pos:{path}:p{N}`. If the dashboard shows old positions, either memcache is down (fail-soft, falls back to disk offsetlog) or the consumer process is wedged (heartbeat would also be stale).

**Job handler appears not to fire.** Check the registration filter (`job_handlers` for spoke-side, `remote_job_handlers` for hub-only). Then check the JobRouter input — `firehose:job` (small) vs `jobintake:job` (large), distinguished by KEY tag. If the job is large and you used LogManager (firehose), it got truncated at 4KB and the handler saw `{"truncated": true}`. Use `JobIntake::queue()` instead.

**SettingsSync silently doing nothing.** The `enable_workers === true` (strict) gate means missing or `"true"` (string) values count as spoke, no fanout. Verify the option value exactly.

**`outputs` log-reader filter array.** Plural, not singular. Singular is silent failure.

## Inspecting on disk

```bash
# Application uses {base}/logs/firehose.log/ for the LogManager firehose.
# Lines are 7-element positional Message envelopes; VALUE (index 6) is the entry hash.
docker exec eve-pyrobase1-1 head -c 800 /tmp/newspack-nodes/logs/firehose.log/p0/0.log

# Job-intake (large jobs).
docker exec eve-pyrobase1-1 ls -la /tmp/newspack-nodes/logs/jobintake.log/p0/

# RequestBuilder writes assembled requests to requests.log (with .idx companion for drilldown).
docker exec eve-pyrobase1-1 ls -la /tmp/newspack-nodes/logs/requests.log/p0/

# FlameBuilder writes flame data + index to flames.log.
docker exec eve-pyrobase1-1 ls -la /tmp/newspack-nodes/logs/flames.log/p0/
```

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-review` — application contract checklist
- `nodes-debugging` (in newspack-nodes) — substrate REPL, worker health, log layout
