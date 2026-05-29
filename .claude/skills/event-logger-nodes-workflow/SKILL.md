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
- Changes to LogManager, JobIntake, StreamMerger, StatsAggregator, SettingsSync, RemoteManager

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
3. Validate inputs at the handler boundary; the substrate rate-limits you on size (10MB cap per job) but doesn't validate content.
4. **Size discipline**: if the payload could exceed 4KB, write via `JobIntake::queue($handler_name, $payload)` instead of LogManager. JobIntake is the auto-locked large-write path.

#### Adding a service CI verb

Endpoints are now declared as verbs on a Service CI (`App\*_CI_Node`). The substrate's command-protocol REST surface exposes them at `/wp-json/newspack-nodes/v1/messages` — one envelope per request, dispatched against the addressed node. There are no per-plugin REST controllers anymore (the only one left is the `Performance_Controller_Base` helper).

1. Pick the right service CI: `Discovery_CI`, `Status_CI`, `Settings_CI`, `Logger_CI`, `Events_CI`, `Servers_CI`, `Aggregator_CI`, `Performance_CI` (under `includes/app/`).
2. Add a new verb entry in that CI's `node_schema()['commands']` array — `name`, `description`, `args`, optional `permission_callback`.
3. Implement the verb method on the same class; signature follows the substrate's `(Command_Interpreter_Node $self, string $args, array $envelope = [])` convention.
4. Capability gate defaults to `manage_options`; rate-limit and worker-tag exclusion are inherited from `Performance_Controller_Base` if your CI uses it.
5. Throw freely — the substrate wraps thrown errors as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument validation.
6. Stats reads are fail-soft (`null` / `[]` / `false`). Slot-pool ops are fail-closed (HTTP 429 if memcache is down — slot pool IS the rate limit).

#### Adding a React dashboard / page

The v0.8.0 substrate-canonical pattern: every dashboard mounts the substrate's exospine, builds its node graph from substrate JS primitives, and exposes a view node that React subscribes to.

1. Source under `src/{tree-name}/`. Build via wp-scripts (`npm run build`).
2. The plugin's main file maps `?page=<slug>` to a React tree; add the slug to the `page_to_tree` map.
3. Use `@wordpress/element` (not direct React) and `@newspack-nodes/runtime` for substrate JS nodes (`mountExospine`, `SseIn`, `HttpOut`, `Heartbeat`).
4. Hook layout per dashboard:
   - `const { ci, router, teardown: teardownSpine } = mountExospine();` — exospine returns the request-scope CI, the `_router`, and a teardown.
   - Build the substrate boundary nodes once: `_sse` (`SseIn` — EventSource ingress), `_http` (`HttpOut` — POST /command boundary; `.client = CommandClient`), `_heartbeat` (`Heartbeat` — slot keep-alive; `target = '_http/workers'`).
   - All nodes set `sink = ci`. Flow direction is steered with `target` / `TO` — no bespoke `nodeA.sink = nodeB` chains.
   - The view node owns the React render-state and a `pending` Map for command Promises. Reply handler matches incoming envelopes against `pending` by ID, resolves/rejects, then partial-spreads onto state.
5. View contract (canonical, enforced in `event-logger-nodes-review`):
   - `pending` is a `Map` keyed by command ID for Promise settlement.
   - TM_ERROR envelopes route through a single `_errorMessage()` helper — never thrown inline.
   - State updates use id-wins partial spread (`{ ...prev, [id]: { ...prev[id], ...patch } }`); never `{ ...prev, [id]: patch }`.
   - No dead REPL mounts on a production dashboard tree (the CommandInterpreter mount is for the console tree only).
6. Reference implementations: `src/performance-request-log/hooks/useRequestLogGraph.js`, `src/event-aggregator/hooks/useAggregatorStatusGraph.js`, `src/aggregator-admin/hooks/useAggregatorAdminGraph.js`.
7. Shared hooks live in `src/shared/` (synced copies; canonical source is `newspack-nodes/src/shared/`). Import via `../shared/hooks/...`.

#### Adding an application Node subclass

1. Create `includes/class-{name}.php` with `class Foo_Node extends \Newspack_Nodes\Node` (every node class ends in `_Node`; shell-name = class minus `_Node`). Override `fill()`.
2. **No registration** — the plugin registers the `Newspack_Event_Logger_Nodes\` namespace once, so `make_node('Foo')` resolves `\Newspack_Event_Logger_Nodes\Foo_Node` and the palette scans the classmap. Just `composer dump-autoload -o` after adding the file.
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
# Always pass --enforce-time-limit so a hung test (readline without a TTY,
# infinite drain loop) aborts at the per-test budget instead of stalling the
# whole suite. Tests that legitimately sleep mark their class `#[Medium]`.
cd tests && ../vendor/bin/phpunit --enforce-time-limit

# Filter to a specific test file or method.
cd tests && ../vendor/bin/phpunit --enforce-time-limit --filter RequestBuilderTest

# Coverage HTML/Clover.
tests/run-coverage.sh
```

### Phase 4: Reload running workers

Workers cache loaded classes for the duration of their process lifetime (~595s default). After deploying new code, restart the relevant worker groups so the new bytecode lands:

```bash
# Restart all worker types in one shot.
wp nodes restart all --all-partitions

# Or per topology (run `wp nodes types` first to discover what's live).
wp nodes restart firehose-workers-and-jobs --all-partitions
wp nodes restart request-workers           --all-partitions
wp nodes restart job-workers               --all-partitions
wp nodes restart aggregator                --all-partitions   # hub-only; no-op on spokes
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

- **Hub vs spoke**: `enable_aggregator` is the single operator switch for the admin-visible side of hub-mode. Strict polarity: read as `true === ( Config::load_config()['enable_aggregator'] ?? false )`. Default OFF — fresh installs are spokes/standalone; hubs opt in explicitly by checking the box in Event Logger Settings → Remote Servers. Push-side fanout (`Settings_Sync`, `Auto_Tuner_Node`) is ungated; missing consumers are the structural gate. The legacy `enable_workers` toggle was retired in v0.5.0.
- **`outputs` (plural) for log reader registration**, not `output` (singular). Easy typo, silent failure.
- **Memcache is required** for the application — Stats_Store, slot rate limiting, stats aggregator, and worker-position publishing all use it. If running locally without memcache, the stats path goes fail-soft (no data on dashboards).
- **Salt rotation orphans keys but doesn't flush them** — workers keep writing to the OLD salt until they respawn. After `Stats_Store::flush_all()`, restart workers to take effect immediately.
- **Application nodes resolve by namespace prefix** (no registry): `make_node('Flame_Builder')` → `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` via the registered `Newspack_Event_Logger_Nodes\` prefix. Topology files use `$interpreter->make_node(...)`; the `.tsl` shell-name is the class minus `_Node` (`make_node Flame_Builder`, `Job_Router`, `Request_Builder`, …).

## After You Land

- Update AGENTS.md if the change altered an architecture decision or key file
- If you added a job handler that crosses the hub/spoke boundary, document which side is intended (`job_handlers` vs `remote_job_handlers`)
- Push to GitHub via the plugin's own remote (this is its own git repo)

## Related Skills

- `event-logger-nodes-debugging` — dashboards, log paths, memcache schema, hub/spoke routing
- `event-logger-nodes-review` — application contract checklist
- `nodes-workflow` (in newspack-nodes) — for substrate-level changes
