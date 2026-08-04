---
name: event-logger-nodes-debugging
description: Debugging the event-logger-nodes application — dashboards, memcache stats schema, hub/spoke routing, SSE slot pool, reqgrep, and the request-lifecycle pipeline. Use when something visible to users is wrong (stats not showing, dashboards stuck, SSE drops, requests not being assembled, jobs not running).
argument-hint: "[symptom]"
---

# Event Logger Nodes Debugging

Application-side debugging. For substrate-level questions (workers stuck, REPL semantics, log layout under `{base}/`), see the `nodes-debugging` skill in newspack-nodes.

## When to Use

- Dashboard panels show "no data" or stale data
- A request's timeline isn't assembling correctly
- SSE connections drop or fail
- A job handler doesn't fire
- Hub/spoke aggregation misbehaves (replicas seeing too few or too many entries)

## Filter the firehose: `wp nodes reqgrep`

The application-aware view of the substrate's firehose. It decodes the Message envelope, unwraps `Message::VALUE`, and renders one request at a time with timestamps, indentation, and process (start)/(complete) bracketing.

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

It handles both wire formats — the new positional Message envelope (live) and legacy entry-shape JSON (archives, stdin pipes) — so old captures still work.

## Inspecting via the REPL

Pivot into a worker to see node state. Worker ids follow `<topology>.p<N>`; the default combined Request_Builder + Flame_Builder + Job_Router topology is `combined` (the `topologies/combined.tsl` file), so:

```bash
wp nodes cli combined.p0
```

Topology names come from the `.tsl` filename (no `name:` frontmatter): `combined`, `request-builder`, `job-router`, `flame-builder`, `performance`, `aggregator`, `hub-control`, plus the substrate stock `job-worker`. Which are live depends on the deployment's substrate `topologies` config list (the default ships just `combined`), and only spawned topologies show in `wp nodes status`. Don't hardcode names — `wp nodes types` is the authoritative catalog. (Worker-restart classification is unrelated: each `Settings_Schema` field's `restart:` key holds CONSUMER NODE TYPES — `Flame_Builder`, `Job_Worker`, `Partition` — or `'all'`, which `Restart_Planner` maps to the live topologies running a matching node.)

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

Valid `ls` column flags are `-a`, `-c`, `-l`, `-s`, `-t`, combinable as `-alst`. There is no `-o` flag — substrate moved off the legacy `sink/owner` model to the rule-#2 `sink/target` model.

For other pivots, use the matching reader id — topology name plus partition, e.g. `job-worker.p0`, `request-builder.p0`, `aggregator.p0`.

Typo a reader id and the cli fails fast: `Error: no worker '<id>' (run 'wp nodes status' to list active workers)` — no silent ghost-IPC creation.

Scripted pivot sessions (`echo cmd | wp nodes cli foo.p0`) drain cleanly without a trailing `sleep`: substrate sends a TM_EOF on stdin close and waits for the worker's echo before exiting, so in-flight responses land first.

## Memcache stats schema

Stats live in memcache only — never on disk. Per-partition prefix is `evlog[:salt]:p{N}:`, then 9 namespaces: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`.

```bash
# Connect to memcache directly to inspect slabs.
echo "stats slabs" | nc <memcache-host> 11211

# Find the active salt.
wp option get newspack_event_logger_nodes_stats_salt

# Force a flush (rotates salt, all old keys orphan via TTL).
wp option update newspack_event_logger_nodes_stats_salt $(openssl rand -hex 4)
wp nodes restart combined   # pick up new salt (or `restart all`)
```

**Caps to remember**: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category survives capping.

**Per-URL flame stats TTL** is `max(3600, max_lifespan/24)`. Every other namespace uses the full `max_lifespan`. A URL unseen for over an hour has lost its flame data even while other stats remain.

**Separate memcache use — ruleset hooks (v0.28.0).** Heavy log-rules (hooks past `Rule_Set::INLINE_HOOK_LIMIT = 100`) tier their hook list out of the autoloaded option into `evlog:rules:hooks:<rule-id>` (TTL 3600, warmed on miss from the non-autoloaded `newspack_event_logger_nodes_rule_hooks_<id>` durable option) — separate from the stats prefix, and warm-cache rather than system-of-record. Also separate: `evlog:sse:*` (SSE slot pool) and `np:pos:*` (worker positions).

## Dashboards

Page slugs this plugin owns (URL path: `/wp-admin/admin.php?page=<slug>`):

| Slug | What |
|------|------|
| `event-logger-overview` | Performance overview (URL leaderboard, breakdown by server / status / category); also the top-level Event Logger menu landing page |
| `event-logger-overview&request=<rid>` | URL drilldown with the request rendered inline |
| `event-logger-errors` | Error log dashboard |
| `event-logger-gyroscope` | In-flight request timeline visualization |
| `event-logger-requests` | Request Log — recent completed requests plus drilldown |
| `newspack-event-logger-nodes` (Settings menu) | Application settings — registered via `add_options_page` under Settings, not under the Event Logger menu |

The hub-side aggregator status page (`newspack-nodes-aggregator`) is substrate-owned, driven by whether the `aggregator` topology is active. There is no `enable_aggregator` gate — the key was retired.

Workers + Raw Logs are substrate-owned dashboards under the "Nodes" top-level menu, registered from `newspack-nodes/includes/admin/class-admin.php::register_event_dashboard_pages`. Don't look for them here.

If a dashboard says "Connection lost", check in this order:
1. The page enqueues its build via the `page_to_tree` map in `newspack-event-logger-nodes.php` — does the slug match?
2. `restUrl` in localized `NewspackNodesData` is bare `/wp-json/`, not pre-namespaced.
3. The relevant service CI is mounted on `newspack_nodes/request_graph_ready`, not `rest_api_init` — service CIs replaced the per-plugin REST controllers.
4. Browser DevTools network tab shows the REST URL it tried to hit (commands ride the unified `/wp-json/newspack-nodes/v1/command` endpoint; SSE rides `/wp-json/newspack-nodes/v1/messages/stream`).

If panels are blank but the page renders, verify memcache is running (`docker ps | grep memcache`). The stats path is fail-soft — empty results, not errors, when memcache is unreachable.

## SSE

There is no per-plugin SSE controller layer — `SSEControllerBase` was deleted in M6.10. The substrate's `SSE_Out` node serves the unified endpoint `/wp-json/newspack-nodes/v1/messages/stream`; clients subscribe to one or more `<log>.p<N>` partitions and receive a 7-field message envelope per line plus an idle `heartbeat` event.

The SSE slot pool is substrate-internal — this plugin does NOT call `SSE_Slot_Pool::wire()`; that wiring lives entirely in newspack-nodes. The endpoint inherits the substrate's concurrency cap and fails CLOSED: memcache down means HTTP 429, because the slot pool IS the rate limit.

Client-side, every dashboard mounts the substrate's `SseIn` + `Heartbeat` JS nodes (`@newspack-nodes/runtime`); `Heartbeat.target = '_http/workers'` keeps the slot alive via the `heartbeat` verb on the Workers CI, which calls `SSE_Slot_Pool::touch`. Per-line shape-mapping moved from server PHP to the browser, inlined into each dashboard view node's `fill()` (`request-log-view-node.js`, etc.); the standalone transform helpers were deleted, so the view is the single place that knows the envelope → render-entry mapping.

Unexpected 429s mean the slot pool is exhausted. Inspect `evlog:sse:*` keys in memcache.

Clients reconnecting every few seconds mean the SSE slot is expiring. The slot TTL must outlive the client's heartbeat interval (server `check_slot` is check-only, NEVER refresh-on-check).

## Hub / spoke routing

A node is a hub when the `aggregator` topology is in the substrate's active `topologies` list; there is no operator toggle, since `enable_aggregator` was retired. Every node dispatches its own `k:"job"` entries against `newspack_nodes/job_handlers`. The hub additionally runs the `aggregator` topology: per-spoke substrate `Remote_Source_Node`s pull each spoke's firehose, and ELN's `Remote_Job_Rewrite_Node` (wired between the sources and the firehose `Topic`) rewrites those ingested `k:"job"` lines to `k:"remote_job"`, which the hub's JobWorker dispatches against the separate `newspack_nodes/remote_job_handlers` map. Spoke credentials live in the substrate **Vault**.

Diagnostic flow:

```bash
# Is this node a hub? Derived from active topologies, not an option.
wp nodes types     # cataloged topologies
wp nodes status        # what's actually live — look for aggregator.pN / hub-control.p0

# Aggregator status / health — substrate-owned now (the `aggregator` CI moved
# to newspack-nodes). Dispatch via the unified command-protocol endpoint. The
# body is a packed Message (JSONL) with the positional TYPE/TO/KEY/VALUE fields
# (see docs/API.md), NOT a {to,verb} object:
NONCE=$(wp eval 'echo wp_create_nonce("wp_rest");' --user=<admin>)
curl -sk -X POST "<site>/wp-json/newspack-nodes/v1/command" \
  -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
  -d '{"TYPE":"TM_COMMAND","TO":"aggregator","KEY":"status"}'
# Spoke credentials are managed through the substrate `vault` CI.
```

If a hub is missing entries from a spoke, check the substrate `Remote_Source_Node`'s reconnect/backoff — it publishes a status snapshot to `np:remote:<node-name>:p<partition>`. A reconnecting cURL pull can drop a brief window of data on resume, so a frequently bouncing spoke shows up at the hub as gaps.

## Common failure modes

**Dashboard rate-limit hit immediately.** Service-CI verbs are NOT rate-limited — the only 429s you should see come from the substrate's SSE slot pool (concurrent `/messages/stream` connections, not commands). A 429 on a `/command` POST means the substrate's `HTTP_In_Node` rejected it, not a per-CI throttle; the old REST controller layer and its `includes/rest/` directory were deleted in the Service-CI cutover.

**`reqgrep --recent` shows nothing but the firehose is being written.** Three possibilities: (1) the LogManager early-returned (e.g. running as root), so nothing is being written; (2) the firehose path doesn't match (check `Newspack_Event_Logger_Nodes\Config::get_logs_directory()`); or (3) **no ruleset rule matches the URL** (v0.28.0). `Log_Manager` builds a `Rule_Matcher` from `Rule_Set::load()` and resolves the one governing rule per request — no match, or a `skip` rule, writes nothing for that URL. "Empty means empty": an absent ruleset logs nothing, since there is no implicit `/` log-all baseline. Check the ruleset via the `rules` CI `list` verb or `wp option get newspack_event_logger_nodes_rules`; the shipped default config seeds a `/` log rule plus skips for the substrate's own worker IPC/SSE/spawn endpoints and `/wp-cron.php`.

**Worker positions are stale on the workers dashboard.** Consumer publishes its position to memcache every ~10 seconds via `np:pos:{host}:{source_base_dir}:p{N}`. Old positions mean either memcache is down (fail-soft, falls back to disk offsetlog) or the consumer process is wedged, in which case the heartbeat is stale too.

**Job handler appears not to fire.** Register on the right filter for what you want: `newspack_nodes/job_handlers` for local-on-every-node dispatch of `k:"job"`, `newspack_nodes/remote_job_handlers` for hub-side dispatch of spoke-aggregated entries (now `k:"remote_job"`). The wrong one is a silent miss. Then check the JobRouter input — `firehose:job` (small) vs `jobintake:job` (large), distinguished by KEY tag. A large job sent through LogManager (firehose) got truncated at `MAX_DATA_SIZE` (3840B) — category tagged `" (truncated)"`, data clipped to a 1000-char excerpt, an `error_log` notice marking it — so the handler never saw a parseable payload. Use `JobIntake::queue()` instead.

**Settings sync silently doing nothing.** Settings fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology. An option change always records a settings event (substrate `Settings_Event_Writer` → `settings.p0`); nothing fans it out unless `hub-control` is live and per-spoke `HTTP_Out` nodes are wired — that IS the structural gate. If the sync didn't fire, the producer ran fine; check `wp nodes status` for a live `hub-control.p0` and confirm the operator wired spoke `HTTP_Out` nodes. Both `enable_workers` (v0.5.0) and `enable_aggregator` were retired.

**Cache warmer.** The refresh-ahead cache warmer was extracted to its own plugin, `newspack-cache-cozy` (v0.15.0). Debug it from that plugin's repo.

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
