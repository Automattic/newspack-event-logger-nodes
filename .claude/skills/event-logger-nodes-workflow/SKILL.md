---
name: event-logger-nodes-workflow
description: Implementation workflow for the newspack-event-logger-nodes application — adding job handlers, service CI verbs, dashboard React trees, topology files, and application Node subclasses. Use whenever the change lives under newspack-event-logger-nodes/ rather than the substrate runtime.
argument-hint: "[handler / endpoint / dashboard / node]"
---

# Event Logger Nodes Workflow

The application built on the newspack-nodes runtime. If your change is to the substrate (Node, Router, Topic, Partition, Worker, Supervisor, REPL, Tee, Tail, Consumer), use the `nodes-workflow` skill in the newspack-nodes plugin instead.

Read AGENTS.md for the application's architecture decisions and key files; this skill is the procedural companion.

## When to Use

- Adding or changing a job handler (anything filtered onto `newspack_nodes/job_handlers` or `newspack_nodes/remote_job_handlers`)
- Adding a service CI verb / endpoint (per-namespace verbs on `App\*_CI_Node` classes — REST is now the substrate's command-protocol surface)
- Touching the React dashboards (any tree under `src/`)
- Adding an application Node subclass (RequestBuilder-style processor)
- Modifying topology files under `topologies/`
- Changes to Log_Manager, Job_Intake, Flame_Builder_Node, Stats_Store, Remote_Job_Rewrite_Node, Discovery_Collector_Node

## Phases

### Phase 1: Confirm the layer

Application code is allowed to know about requests, jobs, flames, hubs, spokes, dashboards. If your work is *generic* enough that any future plugin built on the substrate would benefit, escalate to newspack-nodes instead — that's substrate work.

Quick test: would a non-event-logger consumer of newspack-nodes ever want this? If yes → substrate. If no → here.

### Phase 2: Implement

#### Adding a job handler

1. Define the callable. Per-job try/catch is handled by JobWorker; you don't need to wrap.
2. Filter onto the right list (a handler can register on either or both):
   - `newspack_nodes/job_handlers` — dispatched for `k:"job"` entries on every node's own JobWorker. Use when the work should run locally on the node that produced the entry.
   - `newspack_nodes/remote_job_handlers` — dispatched on the hub for `k:"remote_job"` entries (the rewritten product of spoke-aggregated `k:"job"` lines). Use when the work should run centrally on the hub after aggregation.
   Registering under both is the right call when local + aggregated copies need handling under the same name but with potentially different logic (e.g. local handler runs unconditionally, hub handler filters by a per-entry attribute).
3. Validate inputs at the handler boundary; the substrate rate-limits you on size (32MB cap per job — canonical `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE`, which `Job_Router_Node` derives from) but doesn't validate content.
4. **Size discipline**: if the payload could exceed 4KB, write via `\Newspack_Nodes\Job_Intake::queue( $handler_name, $parameters )` (the class moved to the substrate) instead of Log_Manager. `$parameters` is the array passed through to the handler (the optional 3rd arg is a hash_to_partition routing key). Job_Intake is the auto-locked large-write path.

A worked example of a timer-driven local handler: `Health_Check_Tick_Node` (a `Timer_Node` that hitchhikes the `_router` heartbeat) enqueues a job every interval and registers its handler on `newspack_nodes/job_handlers`. (The sibling `newspack-cache-cozy` plugin is the same pattern as a standalone, node-only plugin — a good reference when building one from scratch.)

#### Adding a service CI verb

Endpoints are now declared as verbs on a Service CI (`App\*_CI_Node`). The substrate's command-protocol REST surface exposes them at `/wp-json/newspack-nodes/v1/command` (POST one or more envelopes per request, dispatched against the addressed node) and `/wp-json/newspack-nodes/v1/messages/stream` (GET SSE). There are no per-plugin REST controllers anymore.

1. Pick the right service CI class (files under `includes/app/class-*-ci-node.php`) — this plugin owns FIVE: `Discovery_CI_Node`, `Logger_CI_Node`, `Events_CI_Node`, `Performance_CI_Node`, `Rules_CI_Node`. (`status`/`settings`/`aggregator` are substrate-owned CIs; the old `servers` CI was replaced by the substrate `vault` CI — add those verbs in newspack-nodes, not here.) All five mount on `newspack_nodes/request_graph_ready` in `newspack-event-logger-nodes.php` (`make_node Rules_CI rules`, etc.).
2. Add a new verb entry in that CI's `node_schema()['commands']` array — `name`, `description`, `args` (per-arg `name`/`type`/`required`/optional `default`), and an inline `handler` closure. There is no per-schema `permission_callback` field; gate inside the handler instead (see step 4).
3. Handler signature is `static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array`. All four live ELN CIs type `$self` as `Command_Interpreter_Node` — there are no registry-injected CIs left. `node_schema()` is static, so anything stateful comes through `$self`.
4. Capability gate: call `self::require_manage_options()` at the top of the handler — a `protected static` helper on `Service_CI_Node` that throws a `\RuntimeException` for non-admins, which the interpreter's central catch (step 5) turns into a `TM_COMMAND|TM_ERROR` reply along the FROM trail. Worker requests are excluded via the `NEWSPACK_NODES_WORKER_TYPE` env tag the substrate sets pre-dispatch.
5. Throw freely — the substrate wraps thrown errors as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument validation.
6. Stats reads are fail-soft (`null` / `[]` / `false`). Slot-pool ops are fail-closed (HTTP 429 if memcache is down — slot pool IS the rate limit).
7. Adding a *config field* (not a verb)? Declare a single `Field` in `Settings_Schema` (`includes/class-settings-schema.php`) — the v0.13.0 declarative source from which the Config overlay keys, the admin register/render loop, and the worker-restart classification (each field's `restart:` key holds CONSUMER NODE TYPES — e.g. `Flame_Builder`, `Job_Worker`, `Partition` — or `'all'`, which `Restart_Planner` resolves to the live topologies running a matching node; NOT topology names) all derive. Don't hand-maintain parallel option arrays; that was retired. Note: per-URL LOGGING settings (which URLs to log, which hooks, custom/significant events, auto-tune thresholds) are NOT `Settings_Schema` fields — they live in the ruleset (below). `Settings_Schema` holds only the remaining globals (`enable_logging`, `log_memory`, `flush_every_line`, `allowed_users`, `hook_start_priority`, and the overlay-only `rules` seed).

#### Touching the per-URL logging ruleset (v0.28.0)

The seven global logging settings (`log_urls`/`skip_urls`/`log_events`/`custom_events`/`significant_events`/`auto_disable_threshold`/`auto_protect_time_threshold`) were replaced by a per-URL **ruleset**: an ordered list of `Rule`s (`includes/class-rule.php`), each a URL pattern (prefix `/x` or exact `/x?`) with a `log`/`skip` action and — for `log` rules — its own hooks, custom events, significant events, and auto-tune thresholds. `Log_Manager` builds a `Rule_Matcher` (`includes/class-rule-matcher.php`) per request and resolves the ONE governing rule (longest-prefix-wins, case-insensitive; no match ⇒ skip ⇒ zero hooks bound, no memcache). Empty means empty — there is no implicit `/` log-all baseline.

- Durable state lives in `Rule_Set` (`includes/class-rule-set.php`): the rule LIST rides the autoloaded `newspack_event_logger_nodes_rules` option; a heavy rule's hooks (past `INLINE_HOOK_LIMIT = 100`) tier out to a non-autoloaded `newspack_event_logger_nodes_rule_hooks_<id>` option mirrored into memcache (`evlog:rules:hooks:<id>`, TTL 3600, warmed on miss). Every write MUST go through `Rule_Set::save()` so inline↔pointer tiering + orphan reconcile hold — never raw `update_option`.
- A rule's id is `Rule_Set::id_for($pattern)` (the pattern's `Log_Manager::url_hash()`) — one id per pattern; client-supplied ids are ignored.
- Editing goes through the `Rules_CI_Node` service CI (`rules` shell-name; verbs `list`/`save`/`upsert`/`delete` at `_http/rules`). The rules-editor UI is `src/rules/`, embedded in the overview/performance dashboard (`src/overview/PerformanceDashboard.js` — the "Log this URL" modal button is the `upsert` path), NOT a standalone admin page.
- Migration is activation-only + version-gated (`Rule_Set::SCHEMA_VERSION = 2`): v0→v1 folds the legacy options, v1→v2 rekeys ids to the pattern-hash scheme. Don't add a request-path version check.

#### Adding a React dashboard / page

The v0.8.0 substrate-canonical pattern: every dashboard mounts the substrate's exospine, builds its node graph from substrate JS primitives, and exposes a view node that React subscribes to.

1. Source under `src/{tree-name}/`. Build via wp-scripts (`npm run build`).
2. The plugin's main file maps `?page=<slug>` to a React tree; add the slug to the `page_to_tree` map.
3. Use `@wordpress/element` (not direct React) and `@newspack-nodes/runtime` for substrate JS nodes (`mountExospine`, `SseIn`, `HttpOut`, `Heartbeat`, `CommandClient`).
4. Hook layout per dashboard. Two valid `mountExospine` call forms:
   - **Bare** — `const { interpreter, teardown: teardownSpine } = mountExospine();` returns the request-scope interpreter and a teardown directly. Use only when the tree has no overlay/reinit (the one current example is `useHookCatalogGraph`).
   - **Build-callback** — `const { teardown } = mountExospine( build );` where `build` receives `{ interpreter }`, wires the graph, and returns `{ teardown }`. The substrate snapshots Core and rebuilds via `Core.reinit()` on "Reset Graph". Every reinit-capable dashboard (request-log, performance, gyroscope, error-log) uses this form.
   - Mount only the substrate boundary nodes the dashboard needs:
     - `_sse` (`SseIn` — EventSource ingress) — required for live-stream dashboards (request log, gyroscope, error log).
     - `_http` (`HttpOut` — POST /command boundary; `http.client = new CommandClient({ baseUrl: data.restUrl, nonce: data.nonce })`) — required for any command-fanout dashboard.
     - `_heartbeat` (`Heartbeat` — SSE slot keep-alive; `target = '_http/workers'` — pokes the substrate's `workers/heartbeat` verb which calls `SSE_Slot_Pool::touch`). Required whenever `_sse` is mounted.
   - A command/reply (no live stream) dashboard — e.g. `overview`/performance, which polls + issues on-demand commands — needs only `_http` + the view node(s). A pure live-stream dashboard needs `_sse` + `_heartbeat` + transform/view chain.
   - All mounted nodes set `sink = interpreter`. Flow direction is steered with `target` / `TO` — no bespoke `nodeA.sink = nodeB` chains.
   - The view node owns the React render-state (published via `setState('view', model)`) and — for command-fanout views — a `pending` Map keyed by `message[ID]` for Promise settlement. Reply handler matches incoming envelopes against `pending`, resolves/rejects, then updates `this.model` and republishes.
5. View contract (canonical, enforced in `event-logger-nodes-review`):
   - Command-fanout views own a `pending` Map keyed by `message[ID]`; the hook stashes `{ resolve, reject }` resolvers before filling each TM_COMMAND. Pure-SSE views don't need `pending`.
   - TM_ERROR envelopes route through an internal `_errorMessage(payload)` helper that coerces string / `{message}` / fallback payloads to a human-readable string — never thrown inline from `fill()` (that crashes React).
   - View-model updates preserve prior data on partial replies (per-slice last-modified dedup in `overview-view-node`; list-replaces-table in `urls-view-node`, whose `storeResult` swaps the whole `data` rows array per reply). Don't wholesale-clobber the model on every reply — preserve sibling slices.
   - No dead REPL mounts (`_output` / `_completion` / `_uptime` / `_cwd`) on a production dashboard tree — they're for the console tree only and would collide with the debug-overlay's REPL.
6. Reference implementations:
   - Command-driven (poll + on-demand, per-slice de-god): `src/overview/hooks/usePerformanceGraph.js` (useBatchedPoll + addSliceFetcher) + its per-slice view nodes `src/overview/nodes/{overview,urls,url-detail,request-detail}-view-node.js`.
   - SSE-stream: `src/requests/hooks/useRequestLogGraph.js`.
   - Sliced data model with incremental merge: `src/overview/nodes/overview-view-node.js`.
7. Shared hooks/utils are imported from the substrate via the `@newspack-nodes/shared/...` alias (resolved by esbuild + jest to `newspack-nodes/src/shared`). There is no local `src/shared/` and no sync step. The one ELN-owned test helper (`renderHook`) lives in `src/test-helpers/`.

#### Adding an application Node subclass

1. Create `includes/class-{name}.php` with `class Foo_Node extends \Newspack_Nodes\Node` (every node class ends in `_Node`; shell-name = class minus `_Node`). Override `fill()`.
2. **No registration** — the plugin registers the `Newspack_Event_Logger_Nodes\` namespace once, so `make_node('Foo')` resolves `\Newspack_Event_Logger_Nodes\Foo_Node` and the palette scans the classmap. Just `composer dump-autoload -o` after adding the file.
3. A `.tsl` topology wires it: `make_node Foo foo` instantiates the node, `connect_node foo next-step` wires the sink, `cmd foo:config <verb> [args…]` runs a config verb. The substrate's Topology_Loader interprets the script per partition.

#### Adding a CLI command (`wp nodes <verb>`)

1. Live under `includes/cli/class-<verb>-command.php`. Register in `newspack-event-logger-nodes.php` inside the `WP_CLI` block.
2. Validate inputs at the boundary; long-running commands should accept an `--allow-root` flag (WP-CLI convention).
3. **Make blocking work injectable.** If the command reads stdin in a loop, calls `sleep` between iterations, or polls a file, accept the relevant resource/iteration-count as a parameter so tests can drive it deterministically. Two distinct seams in `Reqgrep_Command`:
   - `process_stdin( $stream = null )` — stream-injection seam; defaults to null (resolving STDIN inside), tests pass a `php://memory` resource.
   - `follow_mode( int $max_iterations = PHP_INT_MAX )` — iteration-cap seam; production passes the default, tests pass a small number.
   Keep them separate — don't fold the cap into the stdin reader.

#### Removing dead code

The deferred-loader pattern in `newspack-event-logger-nodes.php` (require_once chain run on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them. That means `class_exists()` guards around in-plugin class instantiation are dead branches. If you find one while editing, delete it (don't leave it as "defensive"). The same applies to in-substrate `class_exists()` guards inside newspack-nodes. Out-of-plugin and optional-dependency guards (e.g. `class_exists( 'Memcached' )` for the PHP extension) stay.

#### Type flags

- VALUE is an array (entry hash, request object, flame data) → set `TYPE = TM_STRUCT`.
- VALUE is a string (raw line, formatted text) → set `TYPE = TM_BYTESTREAM`.
- Producer/consumer must agree. Consumers reading array VALUE gate on `TM_STRUCT`.

### Phase 3: Test

```bash
# Unit + integration tests (require newspack-nodes activated too).
# Always pass --enforce-time-limit so a hung test (readline without a TTY,
# infinite drain loop) aborts at the per-test budget instead of stalling the
# whole suite. Tests that legitimately sleep mark their class `#[Medium]`.
cd tests && ../vendor/bin/phpunit --enforce-time-limit

# Filter to a specific test file or method.
cd tests && ../vendor/bin/phpunit --enforce-time-limit --filter RequestBuilderTest

# Coverage HTML/Clover. Refuses to run as root (Log_Manager refuses root;
# the suite instantiates it and would surface as 37 spurious failures).
# Invoke via `docker exec -u bend <container> bash tests/run-coverage.sh`.
tests/run-coverage.sh
```

### Phase 4: Reload running workers

Workers cache loaded classes for the duration of their process lifetime (~10 min, substrate-controlled). After deploying new code, restart the relevant worker groups so the new bytecode lands:

```bash
# Restart all worker types in one shot.
wp nodes restart all --all-partitions

# Or per topology — the basename of the `.tsl` file (run `wp nodes types`
# first to enumerate cataloged topologies). Current topologies: combined,
# request-builder, job-router, flame-builder, performance, aggregator, hub-control.
wp nodes restart combined        --all-partitions
wp nodes restart request-builder --all-partitions
wp nodes restart job-router      --all-partitions
wp nodes restart flame-builder   --all-partitions
wp nodes restart aggregator      --all-partitions   # hub-only; errors out on spokes (substrate validates against active topologies)
wp nodes restart hub-control     --all-partitions   # hub-only single-instance settings-sync + discovery control plane
```

The worker-CLI verbs (`wp nodes {types,run,restart,status}` and `{ls,cli}`) are all registered by the **substrate**. This plugin registers two CLI commands (in the `WP_CLI` block of `newspack-event-logger-nodes.php`): `wp nodes reqgrep` (`Reqgrep_Command` — application-aware firehose filter) and `wp nodes ruleset-bench` (`Ruleset_Bench_Command` — dev-only per-URL-ruleset matcher benchmark, off the request hot path).

### Phase 5: Live-verify

For changes touching the request-logging pipeline:

```bash
# Hit any URL on the site to generate firehose entries.
curl -sk "<site>/" -o /dev/null -w "HTTP %{http_code}\n"

# reqgrep with --recent shows the most-recent segment forward.
wp nodes reqgrep --recent | head -10

# reqgrep can also follow live (Ctrl-C to stop).
wp nodes reqgrep --follow
```

For dashboard changes: open the relevant page and verify the panels render. Browser DevTools network tab will show REST traffic. Telemetry dashboards land at `/wp-admin/admin.php?page=event-logger-*` (`event-logger-overview`, `event-logger-errors`, `event-logger-gyroscope`, `event-logger-requests`); the Settings / hook-catalog tree (`settings`) is served at `page=newspack-event-logger-nodes`. The aggregator admin page is now substrate-owned (newspack-nodes), not routed here.

For job handler changes: queue a job (via the legitimate caller), wait, check `wp nodes status` for job-workers heartbeat, optionally reqgrep for the rid.

## Patterns That Trip People Up

- **Hub vs spoke**: there is no operator hub toggle — both `enable_workers` (v0.5.0) and `enable_aggregator` were retired (`tests/unit/RetiredConfigKeysTest.php` guards them). Hub-mode is derived from whether the `aggregator` topology is in the substrate's `topologies` list (and `hub-control` for settings/discovery fan-out). Settings fan-out is the substrate `Settings_Sync_Node` graph in `hub-control`; `Auto_Tuner_Node::persist` is a plain `update_option`. Missing consumers (no `hub-control`, no per-spoke `HTTP_Out` wired) are the structural gate — a recorded settings event is tailed and dropped.
- **Memcache is required** for the application — Stats_Store (driven by Flame_Builder_Node), SSE slot rate limiting, and worker-position publishing all use it. If running locally without memcache, the stats path goes fail-soft (no data on dashboards); the SSE slot pool fails closed (429).
- **Salt rotation orphans keys but doesn't flush them** — workers keep writing to the OLD salt until they respawn. After `Stats_Store::flush_all()`, restart workers to take effect immediately.
- **Application nodes resolve by namespace prefix** (no registry): `make_node Flame_Builder` (in a `.tsl` topology) → `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` via the registered `Newspack_Event_Logger_Nodes\` prefix. The `.tsl` shell-name is the class minus `_Node` (`make_node Flame_Builder`, `Job_Router`, `Request_Builder`, …).

## After You Land

- Update AGENTS.md if the change altered an architecture decision or key file
- If you added a job handler that crosses the hub/spoke boundary, document which side is intended (`job_handlers` vs `remote_job_handlers`)
- Push to GitHub via the plugin's own remote (this is its own git repo)

## Related Skills

- `event-logger-nodes-debugging` — dashboards, log paths, memcache schema, hub/spoke routing
- `event-logger-nodes-review` — application contract checklist
- `nodes-workflow` (in newspack-nodes) — for substrate-level changes
