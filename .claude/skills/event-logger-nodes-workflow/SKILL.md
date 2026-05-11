---
name: event-logger-nodes-workflow
description: Implementation workflow for the newspack-event-logger-nodes application — adding job handlers, REST controllers, dashboard React trees, topology files, and application Node subclasses. Use whenever the change lives under newspack-event-logger-nodes/ rather than the substrate runtime.
argument-hint: "[handler / endpoint / dashboard / node]"
---

# Event Logger Nodes Workflow

The application built on the newspack-nodes runtime. If your change is to the substrate (Node, Router, Topic, Partition, Worker, Supervisor, REPL, Tee, Tail, Consumer), use the `nodes-workflow` skill in the newspack-nodes plugin instead.

Read AGENTS.md for the application's architecture decisions and key files; this skill is the procedural companion.

## When to Use

- Adding or changing a job handler (anything filtered onto `newspack_nodes/job_handlers` or `newspack_nodes/remote_job_handlers`)
- Adding a REST controller / endpoint
- Touching the React dashboards (any tree under `src/`)
- Adding an application Node subclass (RequestBuilder-style processor)
- Modifying topology files under `topologies/`
- Changes to LogManager, JobIntake, StreamMerger, StatsAggregator, SettingsSync, RemoteManager

## Phases

### Phase 1: Confirm the layer

Application code is allowed to know about requests, jobs, flames, hubs, spokes, dashboards. If your work is *generic* enough that any future plugin built on the substrate would benefit, escalate to newspack-nodes instead — that's substrate work.

Quick test: would a non-event-logger consumer of newspack-nodes ever want this? If yes → substrate. If no → here.

### Phase 2: Implement

#### Adding a job handler

1. Define the callable. Per-job try/catch is handled by JobWorker; you don't need to wrap.
2. Filter onto the right list:
   - `newspack_nodes/job_handlers` — runs locally on every node (spoke and hub). Use for spoke-side work.
   - `newspack_nodes/remote_job_handlers` — runs only on the hub, after StreamMerger rewrites `k:"job"` → `k:"remote_job"` for ingested-from-spoke lines.
3. Validate inputs at the handler boundary; the substrate rate-limits you on size (10MB cap per job) but doesn't validate content.
4. **Size discipline**: if the payload could exceed 4KB, write via `JobIntake::queue($handler_name, $payload)` instead of LogManager. JobIntake is the auto-locked large-write path.

#### Adding a REST controller

1. Extend `PerformanceControllerBase` if you need rate-limiting, capability checks, partition-validation, and the standard `not_found_error()` helper.
2. Capability gate is `manage_options` by default. Cron-spawned worker requests are tagged via `NEWSPACK_NODES_WORKER_TYPE` env and excluded from the rate limit.
3. Register routes in `register_routes()` per WordPress REST conventions.
4. Return `WP_Error` for failures so the framework formats responses consistently.
5. If the endpoint reads memcache stats: it's fail-soft (return `null` / `[]` / `false` rather than throwing). If the endpoint manages SSE slots: it's fail-closed (HTTP 429 if memcache is down — slot pool IS the rate limit).

#### Adding a React dashboard / page

1. Source under `src/{tree-name}/`. Build via wp-scripts (`npm run build`).
2. The plugin's main file maps `?page=<slug>` to a React tree; add the slug to the menu hookup.
3. Use `@wordpress/element` (not direct React import) and `@wordpress/api-fetch` (not `fetch`).
4. Shared hooks live in `src/shared/`. Import directly from `../shared/hooks/...` (one level up from the tree). One canonical copy; no per-tree duplication.

#### Adding an application Node subclass

1. Create `includes/class-{name}.php` extending `\Newspack_Nodes\Node`. Override `fill()`.
2. Register in `newspack-event-logger-nodes.php` via `\Newspack_Nodes\CommandInterpreter::register_class('Foo', \Newspack_Event_Logger_Nodes\Foo::class)` so topology PHP can construct via `$interpreter->make_node('Foo', 'foo')`.
3. Topology PHP wires it: `$interpreter->make_node('Foo', 'foo'); $foo->connect_node('next-step');`.

#### Adding a CLI command (`wp nodes <verb>`)

1. Live under `includes/cli/class-<verb>-command.php`. Register in `newspack-event-logger-nodes.php` inside the `WP_CLI` block.
2. Validate inputs at the boundary; long-running commands should accept an `--allow-root` flag (WP-CLI convention).
3. **Make blocking work injectable.** If the command reads stdin in a loop, calls `sleep` between iterations, or polls a file, accept the relevant resource/iteration-count as a parameter so tests can drive it deterministically. Pattern: `process_stdin( $stream = STDIN, int $max_iterations = PHP_INT_MAX )` — production passes defaults, tests pass a `php://memory` stream and a small iteration cap. See `ReqgrepCommand::process_stdin` and `follow_mode` for the canonical examples.
4. Same for the readline/non-readline split — accept the TTY flag rather than calling `posix_isatty` inline; tests pass `false` to exercise the non-blocking path.

#### Removing dead code

The deferred-loader pattern in `newspack-event-logger-nodes.php` (require_once chain run on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them. That means `class_exists()` guards around in-plugin class instantiation are dead branches. If you find one while editing, delete it (don't leave it as "defensive"). The same applies to in-substrate `class_exists()` guards inside newspack-nodes. Out-of-plugin and optional-dependency guards (e.g. `class_exists( 'Memcached' )` for the PHP extension) stay.

#### Type flags

- VALUE is an array (entry hash, request object, flame data) → set `TYPE = TM_STRUCT`.
- VALUE is a string (raw line, formatted text) → set `TYPE = TM_BYTESTREAM`.
- Producer/consumer must agree. Consumers reading array VALUE gate on `TM_STRUCT`.

### Phase 3: Test

```bash
# Unit + integration tests (require newspack-nodes activated too).
cd tests && phpunit

# Filter to a specific test file or method.
cd tests && phpunit --filter RequestBuilderTest

# Coverage HTML/Clover.
tests/run-coverage.sh
```

### Phase 4: Reload running workers

Workers cache loaded classes for the duration of their process lifetime (~10 min). After deploying new code, restart the relevant worker groups so the new bytecode lands:

```bash
wp nodes restart firehose-workers --all-partitions
wp nodes restart job-workers      --all-partitions
wp nodes restart request-workers  --all-partitions
```

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

For dashboard changes: open the relevant page and verify the panels render. Browser DevTools network tab will show REST traffic; the page slugs land at `/wp-admin/admin.php?page=newspack-nodes-*`.

For job handler changes: queue a job (via the legitimate caller), wait, check `wp nodes ls` for job-workers heartbeat, optionally reqgrep for the rid.

## Patterns That Trip People Up

- **Hub vs spoke**: `enable_workers === true` (strict) means hub. Anything else means spoke. Don't write `?? false` shortcuts — that turned spokes into hubs in legacy 2.4.42.
- **`outputs` (plural) for log reader registration**, not `output` (singular). Easy typo, silent failure.
- **Memcache is required** for the application — Stats_Store, slot rate limiting, stats aggregator, and worker-position publishing all use it. If running locally without memcache, the stats path goes fail-soft (no data on dashboards).
- **Salt rotation orphans keys but doesn't flush them** — workers keep writing to the OLD salt until they respawn. After `Stats_Store::flush_all()`, restart workers to take effect immediately.
- **Application classes ARE registered** in `CommandInterpreter::$class_map` (RequestBuilder, FlameBuilder, JobRouter, JobWorker, StreamMerger). Topology files use `$interpreter->make_node(...)` — the registry path.

## After You Land

- Update AGENTS.md if the change altered an architecture decision or key file
- If you added a job handler that crosses the hub/spoke boundary, document which side is intended (`job_handlers` vs `remote_job_handlers`)
- Push to GitHub via the plugin's own remote (this is its own git repo)

## Related Skills

- `event-logger-nodes-debugging` — dashboards, log paths, memcache schema, hub/spoke routing
- `event-logger-nodes-review` — application contract checklist
- `nodes-workflow` (in newspack-nodes) — for substrate-level changes
