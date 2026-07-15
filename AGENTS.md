# AGENTS.md — Newspack Event Logger Nodes

WordPress plugin: the application layer of the new event-logger. It replaced the legacy `newspack-event-logger-plugins` monorepo with two plugins — this one (application) and `newspack-nodes` (runtime substrate). High-throughput request-lifecycle logging, real-time SSE streaming, flame-graph generation, hub/spoke aggregation — expressed as Nodes.

This plugin owns: `Log_Manager`, `Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Stats_Store`, the per-URL logging ruleset (`Rule` / `Rule_Set` / `Rule_Matcher`), the `App\*_CI_Node` service CIs (`performance`, `events`, `logger`, `discovery`, `rules`), React dashboards, topology files. (Hub fan-in is the self-sufficient substrate `\Newspack_Nodes\Remote_Source_Node`; the old ELN `Stream_Merger_Node` / `Remote_Source_Node` were deleted in the pull-side cutover.) (`Job_Worker_Node` moved to the `newspack-nodes` substrate; this plugin keeps only the job request-context glue — `Log_Manager::begin/end_job_context`, hooked onto the substrate's `newspack_nodes/job_worker/{before,after}_job` actions.) (`Job_Intake` — the large-write job ingress — also moved to the substrate as `\Newspack_Nodes\Job_Intake`; a one-release `class_alias` keeps the old `Newspack_Event_Logger_Nodes\Job_Intake` FQN loadable. `job-router.tsl`/`combined.tsl` keep their own jobintake→jobs legs unchanged; the substrate's stock `job-intake` topology is a standalone SUBSET for substrate-only installs — the conflict gate refuses co-activating it with either.) (Node subclasses carry a `_Node` suffix; helpers like `Log_Manager` / `Stats_Store` don't.) Depends on `newspack-nodes` for everything substrate-level. Caching is the single shared `\Newspack_Nodes\Core::$memd` handle (a raw `\Memcached` built once by the substrate's `\Newspack_Nodes\Bootstrap::init_memcached()` from `memcache_servers`); this plugin only reads it — there is no plugin-local cache class.

## Plugin Load Order

WordPress loads plugins alphabetically. `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes` (`-event-` < `-nodes`), so the runtime's `\Newspack_Nodes\Node` class is NOT available at this plugin's file-load time.

Workaround: `newspack-event-logger-nodes.php` defers the wiring (CommandInterpreter namespace + `Topology_Registry` mounts + `App\Core` init) via a closure run on `plugins_loaded` priority 11 (when both plugins are loaded). The deferred bootstrap is gated on a `class_exists( '\Newspack_Nodes\Node' )` presence check — it wires ELN when the substrate is present, no-ops otherwise (`Requires Plugins: newspack-nodes` keeps it active on WP 6.5+). Priority 11 is intentional; don't lower it. Tests bypass this — they require the runtime explicitly in `tests/bootstrap.php`.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent dispatched via the Agent tool — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Before every commit, main Claude runs `/code-review`** (replaces `superpowers:simplify`). It spawns its own review agents, so subagents CANNOT run it and do NOT commit; main Claude always runs it after a subagent finishes, then commits.
3. **Make regressions loud** — two layers, both learned the hard way (green suites hid real bugs; `?? default` reads decoupled config silently for weeks):
   - *Tests* must fail on the OLD code, using values **distinct from every default/fallback**. A test that seeds a value equal to the default (or only exercises the trivial case) still passes when the change is silently ignored — it proves nothing. Distinct values, watched failing first.
   - *Runtime* — required config/inputs fail LOUD, never `?? default` a key you actually depend on. A renamed or typo'd key should throw at the boundary, not limp on a default. Read via the fail-loud `Config::value()` accessor (this plugin's own, validated against the shared substrate registry), not `$config['key'] ?? default`.

Subagent prompts MUST include the literal phrase:
> "Invoke `superpowers:test-driven-development` via the Skill tool BEFORE writing any code — mandatory, no exceptions; the failing test must use values distinct from every default/fallback. Do NOT commit: implement, run your tests, and report; main Claude runs `/code-review` and commits."

Subagents have no memory of conversation conventions; omission is a workflow violation. See `~/.claude/rules/workflow-discipline.md`.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist`):
- `snake_case` for functions and variables
- Yoda conditions
- `[]` arrays, arrow functions, spread operator allowed
- Tab indentation, spaces inside parentheses
- PHP 8.2+; constructor property promotion where it shortens
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

4. **Settings fan-out is a node graph; no-consumer = silent no-op.** Settings propagation is the substrate `Settings_Sync_Node` graph in the `hub-control` topology. An option change always records a settings event (substrate `Settings_Event_Writer` → `settings.p0`), and `Auto_Tuner_Node` mutates the specific rule carried in the message (`rule_id`) and persists the whole ruleset via `Rule_Set::save()` (no `remote_manager` job, no `suppress_sync`; each write records a settings event like any admin edit). Nothing fans the change out unless `hub-control` is active and per-spoke `HTTP_Out` nodes are wired — on a spoke/standalone site the event is tailed and dropped. That IS the structural gate. Both `enable_workers` (retired v0.5.0) and `enable_aggregator` (retired) are gone; there is no operator hub toggle — hub-mode is derived from whether the `aggregator` / `hub-control` topologies are active.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt stored in `newspack_event_logger_nodes_stats_salt`. All existing keys orphan instantly (TTL handles cleanup). Schema bumps and emergency flushes share the mechanism. Long-running workers cache `prefix` at construction; restart workers after `flush_all()` for immediate effect.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff on dashboards. Implemented in both real and Fake memcached.

7. **Job_Intake for >4KB payloads, firehose for ≤4KB.** Runtime jobs (small) go through firehose with `k:"job"`; `Job_Router_Node` extracts and routes them. Large jobs (>4KB) go directly to `jobintake.log` via `Job_Intake::queue()` which acquires the auto-lock around large writes. Using the wrong path loses jobs: `Log_Manager` truncates data over `MAX_DATA_SIZE` (3840B) to a 1000-char excerpt with `" (truncated)"` appended to the category, so the handler never sees a parseable payload.

8. **Hub vs spoke topology.** Hub = the `aggregator` (+ `hub-control`) topologies active — no `enable_aggregator` toggle. Every node runs its own `Job_Worker_Node` that dispatches `k='job'` entries against `newspack_nodes/job_handlers`. The hub additionally runs the `aggregator` topology: per-spoke substrate `Remote_Source_Node`s pull spoke firehoses, and ELN's `Remote_Job_Rewrite_Node` (wired between the sources and the firehose `Topic`) rewrites incoming `k:"job"` lines to `k:"remote_job"`; the hub's `Job_Worker_Node` dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent registrations — a job type can register under either or both depending on where it wants to run (locally on every node, only on the hub after aggregation, or in both places when local + aggregated work need different handling).

9. **CRC32 + 31-bit-mask partition routing.** Inherited from `Partition::hash_to_partition()` in the runtime. Used by Topic, `Job_Intake`-keyed mode, and the URL-routing in `Log_Manager`. Same key → same partition across all producers.

## Layout

| Path | What |
|------|------|
| `newspack-event-logger-nodes.php` | Plugin entry; deferred loader (`class_exists` substrate-presence-gated); `Topology_Registry::register_plugin()` registers the top-level `Newspack_Event_Logger_Nodes\` prefix for node-class resolution + the stock-topology dir; `register_namespace` registers only the `App\` service-CI sub-namespace; service-CI mount on `request_graph_ready` |
| `includes/class-settings-schema.php` | `Settings_Schema` — the single declarative Field/Schema source (one `Field` per setting) from which Config overlay keys, the admin register/render loop, and worker-restart classification all derive (replaced three parallel hand-maintained arrays in v0.13.0). Only the global fields remain (`enable_logging`, `log_memory`, `flush_every_line`, + overlay-only `allowed_users` / `rules` / `hook_start_priority`) — the seven per-URL/per-hook logging + auto-tune settings became per-rule (see the ruleset rows below) |
| `includes/class-{rule,rule-set,rule-matcher}.php` | Per-URL logging ruleset (v0.26.0, replaced the seven global `log_urls`/`skip_urls`/`log_events`/`custom_events`/`significant_events`/`auto_disable_threshold`/`auto_protect_time_threshold` options): `Rule` (immutable value object, rule id = pattern's `url_hash`), `Rule_Set` (durable load/save + two-tier hook storage — inline in the autoloaded list up to `INLINE_HOOK_LIMIT`=100, else a per-rule non-autoloaded durable option mirrored into memcache; activation-time `migrate_from_legacy()`, `SCHEMA_VERSION=2`), `Rule_Matcher` (longest-prefix-wins, case-insensitive; no match ⇒ skip). `Log_Manager` resolves the governing rule per request via the matcher |
| `newspack-event-logger-nodes-config.php` | Application config: the per-URL logging `rules` seed, debug toggles (`log_memory` / `flush_every_line`), `stats_mirror_node`, `custom_colors`, `recommended_log_events`, `allowed_users`. The active `.tsl` topology list moved to the substrate's `topologies` key in v0.5.0; remote-pull segment geometry moved to the substrate's `newspack_nodes_*` config |
| `includes/class-config.php` | Application config loader (substrate keys live in `newspack-nodes`) |
| `includes/class-log-manager.php` | `Log_Manager` — per-request firehose writer; redacts URL secrets; refuses root |
| `includes/class-{request-builder,request-flight,flame-builder,auto-tuner}.php` | Node subclasses: `Request_Builder_Node` (assembly) + `Request_Flight_Node` (hidden in-flight snapshot sibling) + `Flame_Builder_Node` (flame + stats fan-out) + `Auto_Tuner_Node` (applies auto-tune decisions) |
| `includes/class-job-router-node.php` | Job pipeline: `Job_Router_Node` Node subclass (extracts firehose `k:"job"`/`k:"remote_job"` entries → jobs.log). The `Job_Intake` large-write ingress helper AND the `Job_Worker_Node` executor both live in the `newspack-nodes` substrate; `Log_Manager::begin/end_job_context` (hooked onto `newspack_nodes/job_worker/{before,after}_job`) supplies the worker's per-job request context. Job Router owns no duplicate size alias or gate: producers use `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE`, and the downstream Partition enforces writes. |
| `includes/class-remote-job-rewrite-node.php` | `Remote_Job_Rewrite_Node` — hub-side `k:"job"` → `k:"remote_job"` rewrite on aggregated firehose entries (the substrate `\Newspack_Nodes\Remote_Source_Node` does the SSE pull) |
| `includes/class-stats-store.php` | `Stats_Store` — 9-namespace memcache schema producer + reader (production lives in `Flame_Builder_Node`) |
| `includes/class-discovery-collector-node.php` | `Discovery_Collector_Node` — hub-side periodic discovery fan-out (`hub-control` topology). The settings-sync push side (`Settings_Sync_Node` + `Settings_Event_Writer`) moved to the substrate; the old ELN `Server_Registry`/`Settings_Sync`/`Remote_Manager`/`Health_Check_*` were deleted — credentials live in the substrate `Vault` |
| `includes/class-{lru-cache,hook-categorizer}.php` | Helpers: `LRU_Cache`, `Hook_Categorizer` |
| `includes/app/class-core.php` | WP-lifecycle hook instrumentation |
| `includes/app/class-{discovery,logger,events,performance,rules}-ci-node.php` | Five service CIs (`*_CI_Node`) mounted on `request_graph_ready`; the command-protocol REST surface. `Rules_CI_Node` (`rules` CI) exposes the ruleset editor's `list`/`save`/`upsert`/`delete` verbs, all routed through `Rule_Set` so the inline↔pointer tiering + orphan reconcile invariants can't be bypassed. `status`/`settings`/`aggregator` are now substrate-owned CIs, and the `servers` CI was replaced by the substrate `vault` CI |
| `includes/admin/class-admin.php` | Application settings UI (the per-URL ruleset editor — `src/rules/RulesAdmin` — mounts into the settings page's "Logging Rules" section, not a separate submenu) |
| `includes/class-current-request-overlay.php` | `Current_Request_Overlay` — registers the `current-request` bundle on the substrate's `newspack_nodes/devtools_tab_bundles` filter (loads wherever the debug overlay does) and injects THIS request's id into a JS global the tab reads |
| `includes/cli/class-{reqgrep,ruleset-bench}-command.php` | `Reqgrep_Command` (`wp nodes reqgrep` application-aware firehose filter) + `Ruleset_Bench_Command` (ruleset matcher micro-benchmark) |
| `includes/uninstall-cleanup.php` | Shared uninstall/cleanup routine (options, ruleset hook options, transients) |
| `topologies/` | Per-partition node graphs as declarative `.tsl` files; topology name = filename (no `name:` frontmatter): `aggregator`, `hub-control`, `combined`, `performance`, `request-builder`, `job-router`, `flame-builder`. (Worker-restart classification is by CONSUMER NODE TYPE: each `Settings_Schema` field's `restart:` key holds node-class tokens — e.g. `['Flame_Builder']`, `['Partition','Topic','Log']` — or `'all'` / `'supervisor_only'` / `[]`, which `Restart_Planner` resolves to the live topologies that run a matching node. They are NOT topology-name labels.) |
| `mu-plugins/` | Drop-in shipped alongside the plugin: `00-newspack-profiler.php` (standalone profiler — also copied to `release/` and attached to the GitHub release). (The refresh-ahead cache warmer moved to its own `newspack-cache-cozy` plugin in v0.15.0.) |
| `scripts/` | `build.mjs` (esbuild dashboard builder invoked by `npm run build`); `pre-push` |
| `src/` | React dashboard trees (`overview`, `error-log`, `gyroscope`, `settings`, `requests`) + the `current-request` overlay-tab bundle + the `rules` ruleset-editor tree (`RulesAdmin` / `RuleEditModal` / `useRulesGraph`, mounted into the settings page by `src/settings`). Shared dashboard SCSS lives in `src/styles/` (`base.scss` + `_tokens.scss`); each dashboard's `styles/base.scss` `@use`s it (the byte-for-byte `_dashboard-mixins.scss` duplicate was deleted in v0.26.0 — dashboards now `@forward` the substrate's `@newspack-nodes/shared/styles/mixins`) |
| `tests/` | PHPUnit suite (unit + integration + Rest); config at `tests/phpunit.xml` |

## Common Pitfalls

These are mistakes that have actually happened. Pay attention.

- **The seven global logging/auto-tune options are gone — logging is per-URL-rule now (v0.26.0).** `log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`, `auto_disable_threshold`, `auto_protect_time_threshold` were folded into a per-URL **ruleset** (`Rule` / `Rule_Set` / `Rule_Matcher`). Each rule is a URL pattern (`/prefix` or exact `/x?`) with a `log`/`skip` action and — for `log` rules — its own hooks, custom events, significant events, and auto-tune thresholds. Matching is longest-prefix-wins + case-insensitive; **no match ⇒ skip, and empty means empty (no implicit log-all baseline)** — declare a `/` log rule to log everything (the shipped config does, plus baseline skips for the substrate's worker IPC/SSE/spawn endpoints + `/wp-cron.php`). The v0→v1→v2 `Rule_Set::migrate_from_legacy()` runs on activation (deploy deactivates then re-installs), rule id = pattern's `Log_Manager::url_hash()`. Discovery *stages* spoke-reported hooks into a non-autoloaded `discovered_hooks` option (surfaced in the editor's hook picker) — it no longer writes the ruleset; the editor is the only rule writer.
- **`enable_workers` AND `enable_aggregator` were retired.** There is no operator hub toggle (`tests/unit/RetiredConfigKeysTest.php` guards both keys). Hub-mode is derived from whether the `aggregator` topology is in the substrate's `topologies` list. Settings fan-out is the substrate `Settings_Sync_Node` graph (`hub-control` topology) and is structurally ungated — without `hub-control` active and per-spoke `HTTP_Out` nodes wired, a recorded settings event is tailed and dropped.
- **PIPE_BUF (4096 bytes) for firehose writes.** `Log_Manager::message()` enforces `MAX_DATA_SIZE = 3840` on the JSON-encoded data: oversize payloads get an `error_log` notice, `" (truncated)"` appended to the category, and the data replaced with a 1000-char excerpt (`['m' => substr(...) . '...']`) — the payload is lost, not chunked. Anything that might exceed (job payloads with serialized option blobs, image-handler args, large serialized arrays) MUST use `Job_Intake::queue()` (locked, `MAX_JOB_SIZE` 32MB).
- **Hub vs spoke routing.** Nodes only ever write `k:"job"`. Every node's `Job_Worker_Node` dispatches its own `job` entries against `newspack_nodes/job_handlers`. On the hub, the `aggregator` topology's per-spoke substrate `Remote_Source_Node`s pull spoke firehoses and ELN's `Remote_Job_Rewrite_Node` rewrites the incoming lines to `k:"remote_job"`; the hub's `Job_Worker_Node` dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent — a job type registers under whichever side(s) should handle it (local on every node, hub-aggregated, or both).
- **One shared `Core::$memd` handle — read it, don't build your own.** Caching is the single `\Newspack_Nodes\Core::$memd` (`\Memcached`), built once at boot by the substrate's `\Newspack_Nodes\Bootstrap::init_memcached()` from its `memcache_servers` config (defaults to `127.0.0.1:11211`). Consumers (`Stats_Store`, the service CIs) read `Core::$memd` directly with null-safe `Core::$memd?->...`; don't hardcode server lists or new up a second connection.
- **Tests set `Core::$memd` to an in-memory `\Memcached` double.** There is no `Cache_Interface` / `Memcached_Cache` to inject — the substrate ships an in-memory `\Memcached` double (`tests/Helpers/InMemoryMemcached.php`) that tests assign to `Core::$memd` in setUp. New cache call-sites read `Core::$memd`; nothing should type-hint a cache interface.
- **Salt rotation requires worker restart.** `Stats_Store::flush_all()` orphans keys, but workers cache `prefix` at construction. Restart workers after rotation for immediate effect.
- **Stats fail-soft, SSE slots fail-closed.** Don't unify them. Dashboards must show "no data" on memcache failure. SSE connections must reject (HTTP 429) — the slot pool IS the rate limit.
- **Application Node classes resolve by namespace prefix — no per-class registration.** `newspack-event-logger-nodes.php` calls `\Newspack_Nodes\Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` (registers the top-level prefix + stock-topology dir in one shot) and `\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` for the service CIs. `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`, and the palette scans the classmap. A new application node just needs `class Foo_Node extends \Newspack_Nodes\Node` under that namespace + `composer dump-autoload -o`; nothing to register.
- **Type flags**: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE gate on TM_STRUCT.
- **Don't restore `class_exists()` guards for in-plugin classes.** The deferred-loader pattern (require_once chain run on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them, so defensive `class_exists()` checks for classes that ship in the same plugin are dead branches. Removing them was a deliberate cleanup; re-adding them is dead weight.
- **`wp nodes cli {reader}` requires the worker to exist.** As of substrate update, `attach_to_worker` checks for `{base}/locks/{reader}.lock.d/`. Typo'd reader ids fail fast with "no worker '<id>' (run `wp nodes status` to list active workers)" — no silent ghost-IPC creation. If you script `wp nodes cli`, surface that error.
- **Substrate now passes all message types through Partition/Topic.** Previously TM_REQUEST/TM_ERROR/TM_EOF were silently dropped — that broke attached-mode error responses (TM_COMMAND|TM_ERROR from a throwing verb on the worker), `request_node` cli verbs, and the stdin-close drain. Application data partitions (firehose.log, jobintake.log, requests.log, flames.log, jobs.log) only emit TM_BYTESTREAM / TM_STRUCT in practice, so the broader contract is a no-op for the application's write path — but you can now use Partitions as ad-hoc message transports if needed.
- **Piped `wp nodes cli` sessions drain cleanly.** Substrate now does a TM_EOF round-trip on stdin close (cli emits TM_EOF, worker bounces, cli exits after the echo). Scripted reqgrep/cli pipelines no longer need a trailing `sleep N`.
- **`App\Core` hook instrumentation has two non-obvious correctness mechanisms — don't strip them.** (1) `wrap_callbacks()` skips any callback whose reflection says it takes a by-reference param (`callback_has_ref_param()`); wrapping those would break the WordPress by-reference contract. (2) A sacrificial `hook_spacer` is registered at `PHP_INT_MAX - 2` (`SPACER_PRIORITY`) so a self-removing complete-hook at `PHP_INT_MAX - 1` still fires (v0.13.1). `wrap_callbacks` treats everything at/above the spacer priority as ours and leaves it alone.

## Local Skills

`.claude/skills/` has application-specific skills:
- `event-logger-nodes-workflow` — implementation workflow (handlers, REST, dashboards, topologies)
- `event-logger-nodes-debugging` — dashboards, memcache schema, hub/spoke routing, SSE
- `event-logger-nodes-review` — application contract checklist

## References

- **Architecture**: `docs/architecture-guide.md` (application design, topologies, hub/spoke flow, memcache schema)
- **API**: `docs/API.md` (REST endpoint reference)
- **Migration**: this plugin replaced the legacy `newspack-event-logger-plugins` monorepo wholesale (summarized in `README.md`). That monorepo has since been removed from the tree ("the museum") — there is no live coexisting stack; this plugin is the sole event-logger application and defaults its storage to `/tmp/newspack-nodes`.
- **Runtime**: `../newspack-nodes/` — substrate this plugin depends on
- **Config System** (v0.13.0 migration): the settings layer now builds on the substrate's `Newspack_Nodes\Config_System\{Field, Schema, Settings_Renderer, Options_Overlay, Reset_Gate}` classes — `Settings_Schema` declares the Fields; the renderer + overlay + reset-gate are substrate-owned. The former local `src/admin-field-reset/` JS module and its `sync-shared.sh` copy were removed in favor of the substrate's `@newspack-nodes/shared` build aliases (resolved in `scripts/build.mjs`).
