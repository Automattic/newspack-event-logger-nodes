# AGENTS.md — Newspack Event Logger Nodes

WordPress plugin: the application layer of the new event-logger. Replaces the legacy 10-plugin `newspack-event-logger-plugins` monorepo with two plugins — this one (application) and `newspack-nodes` (runtime substrate). High-throughput request-lifecycle logging, real-time SSE streaming, flame-graph generation, hub/spoke aggregation — expressed as Nodes.

This plugin owns: `Log_Manager`, `Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Job_Intake`, `Stream_Merger_Node`, `Remote_Source_Node`, `Stats_Store`, `Server_Registry`, `Settings_Sync`, `Remote_Manager`, the `App\*_CI_Node` service CIs, React dashboards, topology files. (`Job_Worker_Node` moved to the `newspack-nodes` substrate; this plugin keeps only the job request-context glue — `Log_Manager::begin/end_job_context`, hooked onto the substrate's `newspack_nodes/job_worker/{before,after}_job` actions.) (Node subclasses carry a `_Node` suffix; helpers like `Log_Manager` / `Stats_Store` / `Server_Registry` don't.) Depends on `newspack-nodes` for everything substrate-level. Caching is the single shared `\Newspack_Nodes\Core::$memd` handle (a raw `\Memcached` built once by this plugin's bootstrap) — there is no plugin-local cache class.

## Plugin Load Order

WordPress loads plugins alphabetically. `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes` (`-event-` < `-nodes`), so the runtime's `\Newspack_Nodes\Node` class is NOT available at this plugin's file-load time.

Workaround: `newspack-event-logger-nodes.php` defers the `require_once` block via a closure run on `plugins_loaded` priority 11 (when both plugins are loaded). Tests bypass this — they require the runtime explicitly in `tests/bootstrap.php`.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent dispatched via the Agent tool — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Before every commit, main Claude runs `/code-review`** (replaces `superpowers:simplify`). It spawns its own review agents, so subagents CANNOT run it and do NOT commit; main Claude always runs it after a subagent finishes, then commits.

Subagent prompts MUST include the literal phrase:
> "Invoke `superpowers:test-driven-development` via the Skill tool BEFORE writing any code — mandatory, no exceptions. Do NOT commit: implement, run your tests, and report; main Claude runs `/code-review` and commits."

Subagents have no memory of conversation conventions; omission is a workflow violation. See `~/.claude/rules/workflow-discipline.md`.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist`):
- `snake_case` for functions and variables
- Yoda conditions
- `[]` arrays, arrow functions, spread operator allowed
- Tab indentation, spaces inside parentheses
- PHP 8.0+ typed properties; constructor property promotion where it shortens
- Conventional commits

## Build / Test

```bash
# Tests (require newspack-nodes to also be installed/activated). Always
# pass `--enforce-time-limit` so a hung test (readline without a TTY,
# infinite drain loop, etc.) aborts at the per-test budget instead of
# stalling the whole suite. Tests that legitimately sleep through
# production code mark their class `#[Medium]` to raise the limit.
cd tests && phpunit --enforce-time-limit

# Coverage HTML/Clover.
tests/run-coverage.sh

# Lint.
npm run lint:php
npm run lint:js

# Build dashboards.
npm run build
```

The plugin is shipped as a standard WordPress plugin; how it's deployed (containers, bind mounts, rsync, etc.) is environment-specific and lives outside this repo.

## Versioning & Release

The version appears in three places: the `Version:` header in `newspack-event-logger-nodes.php`, the `NEWSPACK_EVENT_LOGGER_NODES_VERSION` PHP constant in the same file, and the `"version"` field in `package.json`. Do NOT edit these by hand — `tools/bump-event-logger-nodes-version.sh` (in `dndocker/`) rewrites all three atomically and refuses to bump to a version that's already current.

Releases are **automated by GitHub Actions** (`.github/workflows/release.yml`): pushing a `v<major>.<minor>.<patch>` tag builds the archive and publishes the GitHub Release with BOTH artifacts. You only bump, changelog, commit, and tag:

```bash
# 1. Update CHANGELOG.md: rename `## [Unreleased]` → `## [<version>] - <date>`,
#    then add a fresh empty `## [Unreleased]` above it (Keep-a-Changelog format).
# 2. Bump version across plugin header + constant + package.json (from dndocker root):
dndocker/tools/bump-event-logger-nodes-version.sh <version>
# 3. Commit the changelog + bump together (e.g. `chore(release): <version>`).
# 4. Tag and push — the workflow does the rest:
git tag v<version>
git push origin main
git push origin v<version>
```

On the tag push, the **Release** workflow validates the tag shape, runs
`npm run release:archive` (= `build-release.sh`: build dashboards, rsync via
`.distignore`, `composer install --no-dev`, zip, + emit the standalone profiler
drop-in), extracts the matching `CHANGELOG.md` section as the release notes, and
publishes the GitHub Release with **both** `release/newspack-event-logger-nodes.zip`
and `release/00-newspack-profiler.php` attached. No manual `gh release create`.

`build-release.sh` remains the single source of truth for archive contents and
is what the workflow invokes; run `npm run release:archive` locally to build the
same artifacts for testing. It rsyncs the plugin minus development artifacts
(`src/`, `tests/`, `node_modules/`, `.github`, `composer.{json,lock}`,
`package*.json`, etc.) so the zip holds the plugin directory at root — `wp plugin
install --force --activate <url>.zip` works as-is. It ALSO copies
`00-newspack-profiler.php` into `release/` as a standalone asset (it ships as an
`mu-plugins/` drop-in, separate from the plugin zip). The workflow attaches both
files to the release; `fail_on_unmatched_files` guards against a missing one.

**Note on coupling**: this plugin's version is not tied to `newspack-nodes`'s — they release independently. If a release depends on a specific runtime version, call it out in the CHANGELOG entry; consider bumping the `newspack-nodes` requirement in the plugin header (`Requires Plugins:` once we use it).

**Why three locations?** Plugin header is what WordPress shows in the admin; the PHP constant is what the runtime + dashboards' cache-busting read; `package.json` is what npm tooling reads. The bump script is the single source of truth — drift between any two of them is a real bug we've shipped before.

## Architecture Decisions

These are intentional. Don't "fix" them.

1. **9-namespace memcache schema.** Stats live in memcache only — never on disk. Per-partition prefix `evlog[:salt]:p{N}:`, then namespace: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`. Per-URL flame stats: TTL `max(3600, max_lifespan/24)`. All others: full `max_lifespan`. Caps prevent value-explosion against memcache's 1MB/value limit: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category is preserved before capping.

2. **Sums-not-means storage.** Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket / cross-partition merge is exact addition. Display layer computes means at read time. Do NOT regress to running-mean storage — that introduced an EMA-clamp bug we explicitly fixed.

3. **Fail-soft Stats_Store, fail-closed SSE slots.** Memcache failure asymmetry, deliberate. Stats path: every method returns `null` / `[]` / `false` when memcache is unreachable; never throws. Dashboards show "no data" instead of erroring. SSE slots fail-CLOSED at the caller — memcache down means new connections get HTTP 429 (preserves rate-limit invariant).

4. **Push-side fanout is ungated; no-consumer = silent no-op.** `Settings_Sync::maybe_queue_static_sync` and `Auto_Tuner_Node::persist` both always queue a `remote_manager` job when a synced option changes. Without an aggregator topology running and remotes registered, the queued job has no consumer and silently drops — that IS the structural gate. The historical `enable_workers` polarity check was retired in v0.5.0; hub-mode is now a single operator switch (`enable_aggregator`, typed bool, default OFF) that controls the admin submenu visibility and the Stream_Merger pull-side activation, but does not gate the push-side listeners.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt stored in `newspack_event_logger_nodes_stats_salt`. All existing keys orphan instantly (TTL handles cleanup). Schema bumps and emergency flushes share the mechanism. Long-running workers cache `prefix` at construction; restart workers after `flush_all()` for immediate effect.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff on dashboards. Implemented in both real and Fake memcached.

7. **Job_Intake for >4KB payloads, firehose for ≤4KB.** Runtime jobs (small) go through firehose with `k:"job"`; `Job_Router_Node` extracts and routes them. Large jobs (>4KB) go directly to `jobintake.log` via `Job_Intake::queue()` which acquires the auto-lock around large writes. Using the wrong path silently loses jobs (`Log_Manager` truncates >4KB to `{"truncated": true}`).

8. **Hub vs spoke topology.** Hub = `enable_aggregator` toggled on (the single operator switch for cross-site activity). Every node runs its own `Job_Worker_Node` that dispatches `type='job'` entries against `newspack_nodes/job_handlers`. The hub additionally runs a `Stream_Merger_Node` that pulls spoke firehoses and rewrites incoming `k:"job"` lines to `k:"remote_job"` via the `newspack_nodes/aggregator_ingest_line` filter; the hub's `Job_Worker_Node` dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent registrations — a job type can register under either or both depending on where it wants to run (locally on every node, only on the hub after aggregation, or in both places when local + aggregated work need different handling).

9. **CRC32 + 31-bit-mask partition routing.** Inherited from `Partition::hash_to_partition()` in the runtime. Used by Topic, `Job_Intake`-keyed mode, and the URL-routing in `Log_Manager`. Same key → same partition across all producers.

## Layout

| Path | What |
|------|------|
| `newspack-event-logger-nodes.php` | Plugin entry; deferred loader; `register_namespace` for node-class resolution; memcache bootstrap; service-CI mount on `request_graph_ready`; stock-topology dir registration |
| `newspack-event-logger-nodes-config.php` | Application config (log filters, hooks to instrument, hub/spoke settings). The active `.tsl` topology list moved to the substrate's `topologies` key in v0.5.0 |
| `includes/class-config.php` | Application config loader (substrate keys live in `newspack-nodes`) |
| `includes/class-log-manager.php` | `Log_Manager` — per-request firehose writer; redacts URL secrets; refuses root |
| `includes/class-{request-builder,request-flight,flame-builder,auto-tuner}.php` | Node subclasses: `Request_Builder_Node` (assembly) + `Request_Flight_Node` (hidden in-flight snapshot sibling) + `Flame_Builder_Node` (flame + stats fan-out) + `Auto_Tuner_Node` (applies auto-tune decisions) |
| `includes/class-{job-router,job-intake}.php` | Job pipeline: `Job_Router_Node` Node subclass + `Job_Intake` helper (firehose + jobintake → handlers). The `Job_Worker_Node` executor lives in the `newspack-nodes` substrate; `Log_Manager::begin/end_job_context` (hooked onto `newspack_nodes/job_worker/{before,after}_job`) supplies its per-job request context |
| `includes/class-{stream-merger,remote-source}.php` | `Stream_Merger_Node` (hub fan-in) + its `Remote_Source_Node` children — pull remote firehoses via SSE (cURL multi) |
| `includes/class-stats-store.php` | `Stats_Store` — 9-namespace memcache schema producer + reader (production lives in `Flame_Builder_Node`) |
| `includes/class-{server-registry,settings-sync,remote-manager,health-check-extensions,health-check-tick}.php` | Hub-side fanout + remote-spoke management: `Server_Registry`, `Settings_Sync`, `Remote_Manager`, `Health_Check_Extensions` helpers + `Health_Check_Tick_Node` |
| `includes/class-{lru-cache,hook-categorizer}.php` | Helpers: `LRU_Cache`, `Hook_Categorizer` |
| `includes/app/class-core.php` | WP-lifecycle hook instrumentation |
| `includes/app/class-{discovery,status,settings,logger,events,servers,aggregator,performance}-ci.php` | Service CIs (`*_CI_Node`) mounted on `request_graph_ready`; the command-protocol REST surface |
| `includes/admin/class-admin.php` | Application settings UI |
| `includes/cli/class-reqgrep-command.php` | `Reqgrep_Command` — `wp nodes reqgrep` application-aware firehose filter |
| `topologies/` | Per-partition node graphs as declarative `.tsl` files (firehose-workers-and-jobs, firehose-workers-only, firehose-jobs-only, request-workers, job-workers, aggregator) |
| `src/` | React dashboard trees (`aggregator-admin`, `event-aggregator`, `performance-dashboards`, `performance-gyroscope`, `performance-logger`, `performance-request-log`, plus `shared` helpers) |
| `tests/` | PHPUnit suite (unit + integration + Rest) |

## Common Pitfalls

These are mistakes that have actually happened. Pay attention.

- **`enable_workers` was retired.** The aggregator-mode gate is `enable_aggregator` (typed bool via Config sanitization, default OFF). Settings_Sync itself is ungated — without an aggregator topology + remotes, the queued `remote_manager` job is a silent no-op.
- **PIPE_BUF (4096 bytes) for firehose writes.** `Log_Manager` truncates >4KB to `{"truncated": true}`. Anything that might exceed (job payloads with serialized option blobs, image-handler args, full `Settings_Sync` sweeps) MUST use `Job_Intake::queue()`.
- **`outputs` (plural), not `output`.** Log reader registration uses `outputs` array. Singular is silent failure.
- **Hub vs spoke routing.** Nodes only ever write `k:"job"`. Every node's `Job_Worker_Node` dispatches its own `job` entries against `newspack_nodes/job_handlers`. On the hub, `Stream_Merger_Node`'s `newspack_nodes/aggregator_ingest_line` filter rewrites incoming spoke lines to `k:"remote_job"`; the hub's `Job_Worker_Node` dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent — a job type registers under whichever side(s) should handle it (local on every node, hub-aggregated, or both).
- **One shared `Core::$memd` handle — read it, don't build your own.** Caching is the single `\Newspack_Nodes\Core::$memd` (`\Memcached`), built once at boot by `newspack_event_logger_nodes_init_memcached()` from the substrate's `memcache_servers` config (defaults to `127.0.0.1:11211`). Consumers (`Stats_Store`, the service CIs) read `Core::$memd` directly with null-safe `Core::$memd?->...`; don't hardcode server lists or new up a second connection.
- **Tests set `Core::$memd` to an in-memory `\Memcached` double.** There is no `Cache_Interface` / `Memcached_Cache` to inject — the substrate ships an in-memory `\Memcached` double (`tests/Helpers/InMemoryMemcached.php`) that tests assign to `Core::$memd` in setUp. New cache call-sites read `Core::$memd`; nothing should type-hint a cache interface.
- **Salt rotation requires worker restart.** `Stats_Store::flush_all()` orphans keys, but workers cache `prefix` at construction. Restart workers after rotation for immediate effect.
- **Stats fail-soft, SSE slots fail-closed.** Don't unify them. Dashboards must show "no data" on memcache failure. SSE connections must reject (HTTP 429) — the slot pool IS the rate limit.
- **Application Node classes resolve by namespace prefix — no per-class registration.** `newspack-event-logger-nodes.php` calls `\Newspack_Nodes\Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` (registers the top-level prefix + stock-topology dir in one shot) and `\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` for the service CIs. `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`, and the palette scans the classmap. A new application node just needs `class Foo_Node extends \Newspack_Nodes\Node` under that namespace + `composer dump-autoload -o`; nothing to register.
- **Type flags**: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE gate on TM_STRUCT.
- **Don't restore `class_exists()` guards for in-plugin classes.** The deferred-loader pattern (require_once chain run on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them, so defensive `class_exists()` checks for classes that ship in the same plugin are dead branches. Removing them was a deliberate cleanup; re-adding them is dead weight.
- **`wp nodes cli {reader}` requires the worker to exist.** As of substrate update, `attach_to_worker` checks for `{base}/locks/{reader}.lock.d/`. Typo'd reader ids fail fast with "no worker '<id>' (run `wp nodes ls` to list active workers)" — no silent ghost-IPC creation. If you script `wp nodes cli`, surface that error.
- **Substrate now passes all message types through Partition/Topic.** Previously TM_REQUEST/TM_ERROR/TM_EOF were silently dropped — that broke pivoted-mode error responses (TM_COMMAND|TM_ERROR from a throwing verb on the worker), `request_node` cli verbs, and the stdin-close drain. Application data partitions (firehose.log, jobintake.log, requests.log, flames.log, jobs.log) only emit TM_BYTESTREAM / TM_STRUCT in practice, so the broader contract is a no-op for the application's write path — but you can now use Partitions as ad-hoc message transports if needed.
- **Piped `wp nodes cli` sessions drain cleanly.** Substrate now does a TM_EOF round-trip on stdin close (cli emits TM_EOF, worker bounces, cli exits after the echo). Scripted reqgrep/cli pipelines no longer need a trailing `sleep N`.

## Local Skills

`.claude/skills/` has application-specific skills:
- `event-logger-nodes-workflow` — implementation workflow (handlers, REST, dashboards, topologies)
- `event-logger-nodes-debugging` — dashboards, memcache schema, hub/spoke routing, SSE
- `event-logger-nodes-review` — application contract checklist

## References

- **Architecture**: `ARCHITECTURE.md` (application design, topologies, hub/spoke flow, memcache schema)
- **API**: `API.md` (REST endpoint reference)
- **Migration**: cutover from the legacy `newspack-event-logger-plugins` monorepo is summarized in `README.md`; the legacy stack writes to `/volumes/pyrobase/tmp/event-logger`, this plugin defaults to `/tmp/newspack-nodes`, and the WP-CLI verbs are distinct (`wp eventlog reqgrep` vs `wp nodes reqgrep`), so the two coexist by isolating their storage.
- **Runtime**: `../newspack-nodes/` — substrate this plugin depends on
