---
name: event-logger-nodes-debugging
description: Debugging the event-logger-nodes application — dashboards, memcache stats schema, hub/spoke routing, SSE slot pool, reqgrep, and the request-lifecycle pipeline. Use when something visible to users is wrong (stats not showing, dashboards stuck, SSE drops, requests not being assembled, jobs not running).
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
wp nodes reqgrep --recent

# All segments (slow but thorough).
wp nodes reqgrep

# Filter to a pattern (regex against the formatted line).
wp nodes reqgrep /admin-ajax

# Specific request id.
wp nodes reqgrep abc123def

# Live tail.
wp nodes reqgrep --follow
```

The output handles multiple wire formats — both the new positional Message envelope (live) and legacy entry-shape JSON (archives, stdin pipes), so feeding it old captures still works.

## Inspecting via the REPL

Pivot into a worker to see node state. Worker ids follow `<topology>.p<N>`; the default combined Request_Builder + Flame_Builder + Job_Router topology is `combined` (the `topologies/combined.tsl` file), so:

```bash
wp nodes cli combined.p0
```

Topology names come from the `.tsl` filename (no `name:` frontmatter): `combined`, `request-builder`, `job-router`, `flame-builder`, `performance`, `aggregator`, plus the substrate stock `job-worker`. Which of these are actually live depends on the deployment's substrate `topologies` config list (the default ships just `combined`) — only spawned topologies show in `wp nodes ls`. Don't hardcode names; `wp nodes types` is the authoritative list of what the deployment cataloged. (Worker-restart classification is unrelated to topology names: each `Settings_Schema` field's `restart:` key holds CONSUMER NODE TYPES — e.g. `Flame_Builder`, `Job_Worker`, `Partition` — or `'all'`, which `Restart_Planner` maps to the live topologies running a matching node.)

From the prompt:

```
status                                  # local mode summary (no command sent to worker)
ls -alst                                # all nodes with COUNT/SINK/TARGET columns (-a=all, -l=count+target, -s=sink, -t=target)
dump request-builder                    # state of Request_Builder_Node, including LRU cache stats (alias of dump_node)
dump flame-builder                      # Flame_Builder_Node — start here when flame/stats memcache fan-out is missing
dump firehose:tee                       # Tee's target list
ls -a request-builder                   # all nodes that sink into request-builder
cd request-builder                      # change cwd so subsequent verbs route there
command_node "" ping                    # dispatch a verb at the cwd without typing the path (aliases: command, cmd)
```

Valid `ls` column flags are `-a`, `-c`, `-l`, `-s`, `-t` (combinable, e.g. `-alst`). There is no `-o` flag — substrate moved off the legacy `sink/owner` model to the rule-#2 `sink/target` model.

For other pivots, use the matching reader id — the topology name plus partition, e.g. `job-worker.p0`, `request-builder.p0`, `aggregator.p0`. Run `wp nodes types` to see the topologies the substrate cataloged and `wp nodes ls` to see what's actually live — those are authoritative; topology names vary per-deployment via the substrate `topologies` config.

Typo a reader id and the cli fails fast: `Error: no worker '<id>' (run 'wp nodes ls' to list active workers)` — no silent ghost-IPC creation. Run `wp nodes ls` to see what's actually live.

Scripted pivot sessions (`echo cmd | wp nodes cli foo.p0`) drain cleanly without a trailing `sleep` — substrate sends a TM_EOF on stdin close and waits for the worker's echo before exiting, so any in-flight response lands first.

## Memcache stats schema

Stats live in memcache only — never on disk. Per-partition prefix is `evlog[:salt]:p{N}:`, then 9 namespaces: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`.

```bash
# Connect to memcache directly to inspect slabs.
echo "stats slabs" | nc <memcache-host> 11211

# Find the active salt.
wp option get newspack_event_logger_nodes_stats_salt

# Force a flush (rotates salt, all old keys orphan via TTL).
wp option update newspack_event_logger_nodes_stats_salt $(openssl rand -hex 4)
wp nodes restart combined --all-partitions   # pick up new salt (or `restart all`)
```

**Caps to remember**: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category survives capping.

**Per-URL flame stats TTL** is `max(3600, max_lifespan/24)`. All other namespaces use the full `max_lifespan`. If a URL hasn't been seen in over an hour, its flame data is gone even if other stats remain.

## Dashboards

Page slugs owned by this plugin (URL path: `/wp-admin/admin.php?page=<slug>`):

| Slug | What |
|------|------|
| `event-logger-overview` | Performance overview (URL leaderboard, breakdown by server / status / category) — also the top-level Event Logger menu landing page |
| `event-logger-overview&request=<rid>` | URL drilldown with the request rendered inline |
| `event-logger-errors` | Error log dashboard |
| `event-logger-gyroscope` | In-flight request timeline visualization |
| `event-logger-requests` | Request Log — recent completed requests + drilldown |
| `newspack-nodes-aggregator` | Hub-side per-spoke status (only registered when `Config::load_config()['enable_aggregator']` is truthy) |
| `newspack-event-logger-nodes` (Settings menu) | Application settings — registered via `add_options_page` under Settings, not under the Event Logger menu |

Workers + Raw Logs are substrate-owned dashboards under the "Nodes" top-level menu — they register from `newspack-nodes/includes/admin/class-admin.php::register_event_dashboard_pages`. Don't look for them in this plugin.

If a dashboard says "Connection lost", check (in this order):
1. The page enqueues its build via the `page_to_tree` map in `newspack-event-logger-nodes.php` — does the slug match?
2. `restUrl` in localized `NewspackNodesData` is bare `/wp-json/`, not pre-namespaced.
3. The relevant service CI is mounted on `newspack_nodes/request_graph_ready` (not `rest_api_init` — service CIs replaced the per-plugin REST controllers).
4. Browser DevTools network tab shows the actual REST URL it tried to hit (commands ride the unified `/wp-json/newspack-nodes/v1/command` endpoint; SSE rides `/wp-json/newspack-nodes/v1/messages/stream`).

If panels are blank but the page renders: verify memcache is actually running (`docker ps | grep memcache`). Stats path is fail-soft — empty results, not errors, when memcache is unreachable.

## SSE

There is no per-plugin SSE controller layer anymore — `SSEControllerBase` was deleted in M6.10. The substrate's `SSE_Out` node serves the unified endpoint `/wp-json/newspack-nodes/v1/messages/stream`; clients subscribe to one or more `<log>.p<N>` partitions and receive a 7-field message envelope per line plus an idle `heartbeat` event.

This plugin wires the substrate's `SSE_Slot_Pool` onto `SSE_Out`'s 3-Closure seam at boot (`\Newspack_Nodes\SSE_Slot_Pool::wire()` in `newspack-event-logger-nodes.php`), so the unified SSE endpoint inherits the concurrency cap — fail-CLOSED (HTTP 429 if memcache is down — slot pool IS the rate limit).

On the client side, every dashboard mounts the substrate's `SseIn` + `Heartbeat` JS nodes (`@newspack-nodes/runtime`) — `Heartbeat.target = '_http/workers'` keeps the slot alive via the `heartbeat` verb on the Workers CI (which internally calls `SSE_Slot_Pool::touch`). Per-line shape-mapping moved from server PHP to the browser, inlined into each dashboard view node's `fill()` (`request-log-view-node.js`, etc.) — the standalone transform helpers were deleted, so the view is now the single place that knows the envelope → render-entry mapping.

If you're getting unexpected 429s: the slot pool is exhausted. Inspect `evlog:sse:*` keys in memcache. The `newspack_event_logger_nodes/sse_rate_limited` action fires on every reject (logged to PHP `error_log` by default).

If clients reconnect every few seconds: the SSE slot might be expiring. The slot TTL must outlive the client's heartbeat interval (server `check_slot` is check-only, NEVER refresh-on-check).

## Hub / spoke routing

A node is a hub when `enable_aggregator` is truthy in the merged Config (the single operator switch for remote-server activity — typed bool, default OFF; hubs opt in explicitly). Every node dispatches its own `k:"job"` entries against `newspack_nodes/job_handlers`. The hub additionally pulls remote firehoses via StreamMerger; the `newspack_nodes/aggregator_ingest_line` filter rewrites those ingested `k:"job"` lines to `k:"remote_job"`, which the hub's JobWorker dispatches against the separate `newspack_nodes/remote_job_handlers` map.

Diagnostic flow:

```bash
# Is this node a hub? Stored as 0/1 by register_setting; default OFF.
# The legacy enable_workers gate was retired in v0.5.0 — enable_aggregator
# is the single operator switch now.
wp option get newspack_event_logger_nodes_enable_aggregator

# Aggregator status / health / servers — there is no standalone REST
# namespace for these any more (the legacy `newspack-nodes-aggregator/v1/*`
# routes were retired in the Service-CI cutover, and the unused
# `aggregatorRestUrl` localized var was dropped with them). Dispatch via the unified
# command-protocol endpoint instead — same payload shape:
NONCE=$(wp eval 'echo wp_create_nonce("wp_rest");' --user=<admin>)
curl -sk -X POST "<site>/wp-json/newspack-nodes/v1/command" \
  -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
  -d '{"to":"aggregator","verb":"status"}'
# Swap verb for "health" or "servers" to hit the other Aggregator_CI verbs.
```

If a hub is missing entries from a spoke: check StreamMerger's reconnect log. cURL drops 5s of data on reconnect (single-segment seek); if your spoke is bouncing frequently the hub will see gaps.

## Common failure modes

**Dashboard rate-limit hit immediately.** The Service-CI verbs are NOT rate-limited at the CI layer — the only 429s you should see come from the substrate's SSE slot pool (concurrent `/messages/stream` connections, not commands). A 429 on a `/command` POST means something inside the substrate's `HTTP_In_Node` rejected it, not a per-CI throttle. There is no per-CI rate limit — the old REST controller layer (and its `includes/rest/` directory) was deleted in the Service-CI cutover.

**`reqgrep --recent` shows nothing but the firehose is being written.** Two possibilities: the LogManager early-returned (e.g., running as root), so no entries are being written; OR the firehose path doesn't match (check `Newspack_Event_Logger_Nodes\Config::get_logs_directory()`).

**Worker positions are stale on the workers dashboard.** Consumer publishes its position to memcache every ~10 seconds via `np:pos:{host}:{source_base_dir}:p{N}`. If the dashboard shows old positions, either memcache is down (fail-soft, falls back to disk offsetlog) or the consumer process is wedged (heartbeat would also be stale).

**Job handler appears not to fire.** Make sure you registered on the right filter for what you want: `newspack_nodes/job_handlers` for local-on-every-node dispatch of `k:"job"`, `newspack_nodes/remote_job_handlers` for hub-side dispatch of spoke-aggregated entries (now `k:"remote_job"`). Registering on the wrong one is a silent miss. Then check the JobRouter input — `firehose:job` (small) vs `jobintake:job` (large), distinguished by KEY tag. If the job is large and you used LogManager (firehose), it got truncated at `MAX_DATA_SIZE` (3840B) — category tagged `" (truncated)"`, data clipped to a 1000-char excerpt — so the handler never saw a parseable payload (an `error_log` notice marks it). Use `JobIntake::queue()` instead.

**SettingsSync silently doing nothing.** SettingsSync ITSELF is ungated and always queues a `remote_manager` job when a synced option changes. Without an aggregator topology running and remotes registered, the queued job has no consumer and silently drops — that IS the structural gate. If you expected the sync to fire and it didn't, the producer ran fine; check whether the hub side actually has an aggregator topology live (`enable_aggregator` truthy in the merged Config, default OFF) and remotes registered. The legacy `enable_workers` toggle was retired in v0.5.0.

**Cache warmer.** The refresh-ahead cache warmer was extracted to its own plugin, `newspack-cache-cozy` (v0.15.0). It no longer lives here — debug it from that plugin's repo.

## Inspecting on disk

```bash
# Application uses {base_dir}/logs/firehose.p0/ for the LogManager firehose
# (flat partition-in-name layout: one dir per partition, e.g. firehose.p0, firehose.p1).
# Lines are 7-element positional Message envelopes; VALUE (index 6) is the entry hash.
head -c 800 {base_dir}/logs/firehose.p0/0.log

# Job-intake (large jobs).
ls -la {base_dir}/logs/jobintake.p0/

# RequestBuilder writes assembled requests to requests.p{N}; the drilldown index
# is a sibling `<segment_id>.idx` next to each `<segment_id>.log` (with_index request-index).
ls -la {base_dir}/logs/requests.p0/

# Flames partition (Flame_Builder output; backs the flame-graph drilldown) —
# also indexed with a sibling `<segment_id>.idx` (with_index flame-index).
ls -la {base_dir}/logs/flames.p0/

# Compact per-request summaries (drives the Request Log + Gyroscope dashboards).
ls -la {base_dir}/logs/completed.p0/
ls -la {base_dir}/logs/gyroscope.p0/

# Errors partition (RequestBuilder forwards error/warning keywords here).
ls -la {base_dir}/logs/errors.p0/

# Jobs partition (job-router output).
ls -la {base_dir}/logs/jobs.p0/
```

`{base_dir}` resolves from the `newspack_nodes/config` filter (`base_directory` key). Default is `/tmp/newspack-nodes`.

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-review` — application contract checklist
- `nodes-debugging` (in newspack-nodes) — substrate REPL, worker health, log layout
