# AGENTS.md — Newspack Event Logger Nodes

The application layer of the event logger. It replaced the legacy `newspack-event-logger-plugins` monorepo with two plugins: this one and `newspack-nodes` (the runtime substrate). High-throughput request-lifecycle logging, real-time SSE streaming, flame-graph generation and hub/spoke aggregation, all expressed as Nodes.

This plugin owns `Log_Manager`, `Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Stats_Store`, the per-URL logging ruleset (`Rule` / `Rule_Set` / `Rule_Matcher`), the `App\*_CI_Node` service CIs (`performance`, `discovery`, `rules`), the React dashboards and the topology files. Node subclasses carry a `_Node` suffix; helpers like `Log_Manager` and `Stats_Store` don't.

Three things moved to the substrate and have no compatibility alias here — call the substrate class directly:

- **Hub fan-in** is `\Newspack_Nodes\Remote_Source_Node`. The old ELN `Stream_Merger_Node` / `Remote_Source_Node` were deleted in the pull-side cutover.
- **`Job_Worker_Node`**. This plugin keeps only the request-context glue, `Log_Manager::begin/end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`.
- **`Job_Intake`**, the large-write ingress, now `\Newspack_Nodes\Job_Intake` — there is no compatibility `class_alias` for the old `Newspack_Event_Logger_Nodes\Job_Intake` FQN. `job-router.tsl` and `combined.tsl` keep their own jobintake→jobs legs unchanged; the substrate's stock `job-intake` topology is a standalone SUBSET for substrate-only installs, and the conflict gate refuses co-activating it with either.

Caching is the single shared `\Newspack_Nodes\Core::$memd` handle — a raw `\Memcached` built once by `\Newspack_Nodes\Bootstrap::init_memcached()` from `memcache_servers`. This plugin only reads it; there is no plugin-local cache class. Keyed stores go through the substrate's `Table_Node` (`store()` / `lookup()` / `forget()`) rather than the raw handle, because its keys are scoped per install — `Stats_Store` is the exception, and earns it with `getMulti` batching, value caps and salt rotation the Table has no equivalent for.

## Plugin Load Order

WordPress loads plugins alphabetically, and `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes` (`-event-` < `-nodes`), so `\Newspack_Nodes\Node` is NOT available at this plugin's file-load time.

The workaround: `newspack-event-logger-nodes.php` defers its wiring (CommandInterpreter namespace, `Topology_Registry` mounts, `App\Core` init) to a closure on `plugins_loaded` priority 11. That bootstrap is version-gated, not merely presence-gated — it checks `class_exists( '\Newspack_Nodes\Bootstrap' )` AND `Bootstrap::version_at_least( '2.21.0', … )`, staying dormant with no notice on a missing or too-old substrate. `Requires Plugins: newspack-nodes` keeps the substrate active on WP 6.5+ but does not guarantee the floor, and WordPress does not order plugin updates — this plugin really can land ahead of the substrate it needs. 2.21.0 is `Table_Node::store()` / `forget()`, which `Rule_Set` writes its pointer-tier hooks mirror through — below it every heavy rule's save fatals on an undefined method. **This floor is now STALE and must rise before the next release**: the unreleased substrate makes those methods non-static and moves `LRU_Cache` into `Newspack_Nodes`, so against 2.21.0 a heavy rule's save fatals on a static call and any `Request_Builder_Node` fatals on a missing class. Raise the floor by hand whenever a new hard requirement appears — `bump-version.sh` repins `release.yml`, not this — and `lint-docs.sh` rule 6 gates the prose against the loader. Priority 11 is intentional; don't lower it. Tests bypass this and require the runtime explicitly in `tests/bootstrap.php`.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Main Claude runs `/code-review` before every commit** (it replaced `superpowers:simplify`). It spawns its own review agents, so subagents cannot run it and do NOT commit. Run it after every subagent finishes, then commit.
3. **Make regressions loud**, in two layers — green suites hid real bugs, and `?? default` reads decoupled config silently for weeks:
   - *Tests* must fail on the OLD code, seeded with values **distinct from every default and fallback**. A test seeded with the default still passes when the change is ignored, so it proves nothing.
   - *Runtime* config fails LOUD. Never `?? default` a key you depend on, i.e. never `$config['key'] ?? default` — a renamed key should throw at the boundary. Read through this plugin's fail-loud `Config::value()`, validated against the shared substrate registry.

Subagent prompts MUST carry this literal phrase; subagents have no memory of conventions, and omitting it is a workflow violation:

> "Invoke `superpowers:test-driven-development` via the Skill tool BEFORE writing any code — mandatory, no exceptions; the failing test must use values distinct from every default/fallback. Do NOT commit: implement, run your tests, and report; main Claude runs `/code-review` and commits."

Full rule: `~/.claude/rules/workflow-discipline.md`.

## Code Style

WordPress VIP Go, enforced by `phpcs.xml.dist`: `snake_case`; Yoda conditions; `[]` arrays, arrow functions and spread allowed; tab indent, spaces inside parens; PHP 8.2+ with constructor property promotion where it shortens. Conventional commits.

## Build / Test

Fresh clone, once. The substrate checkout must sit at `../newspack-nodes` — the build kit and shared JS resolve through it.

```bash
npm install                  # JS toolchain (esbuild, jest, eslint, lint-staged)
composer install             # PHP deps + the classmap autoloader
npm run build                # compile the dashboard bundles into build/
```

After adding or renaming a Node class, regenerate the classmap that `make_node` and the console palette read: `composer build:autoloaders` (= `composer install --optimize-autoloader`) or `composer dump-autoload -o`. Use `composer update` only when you mean to move dependency versions.

```bash
# Tests need newspack-nodes installed and activated, and must run as a NON-ROOT user —
# Log_Manager refuses to run as root, which fails the whole suite. Use the vendored
# binary, not a bare `phpunit`: the container's system phpunit is a newer major version
# than composer pins and crashes the bootstrap with
# `DispatchingEmitter::exportsObjects`. Always pass `--enforce-time-limit` so a hung
# test (readline without a TTY, infinite drain loop) aborts at the per-test budget;
# tests that legitimately sleep through production code mark their class `#[Medium]`.
cd tests && ../vendor/bin/phpunit --enforce-time-limit

tests/run-coverage.sh        # coverage HTML/Clover
npm run lint:php
npm run lint:js
npm run build

# Dead-code audit, PHP then JS. BOTH gated in pre-commit on staged files. Tests are
# excluded as consumers, so an export only its test imports reads as unused — mark
# those `@testonly` in the docblock. Most findings are public API or test seams, not
# real dead code; verify every call path first. knip's jest plugin is off, so a module
# reachable only from its own test reads as dead, the same rule phpstan-deadcode
# applies. knip cannot parse JSX in a `.js` file, which drops that file's `import()`
# expressions, so each `lazy( () => import( './X' ) )` target is `entry` in knip.json.
npm run lint:deadcode
npm run lint:deadcode:js
```

It ships as a standard WordPress plugin; deployment is environment-specific and lives outside this repo.

### Git hooks

Hooks are the tracked files in `scripts/` (`pre-commit`, `commit-msg`, `pre-push`), reached via `core.hooksPath`, which `composer install` sets:

```bash
git config core.hooksPath scripts    # what composer's post-install-cmd runs
```

A clone that never ran `composer install` has no hooks at all. `pre-commit` first runs `scripts/sync-shared-scripts.sh`, refreshing this plugin's copy of the shared tooling from `../newspack-nodes/scripts/` when that sibling is checked out — **edit shared scripts there, not here.**

## Versioning & Release

The version lives in three places: the `Version:` header and the `NEWSPACK_EVENT_LOGGER_NODES_VERSION` constant in `newspack-event-logger-nodes.php`, and `"version"` in `package.json`. Never edit them by hand — `scripts/bump-version.sh` rewrites all three atomically and refuses a version that's already current. Each has a distinct consumer: the header is what WordPress shows in the admin, the constant is what the runtime and the dashboards' cache-busting read, `package.json` is what npm tooling reads. Drift between any two is a real bug we have shipped.

Releases are automated by GitHub Actions (`.github/workflows/release.yml`): pushing a `v<major>.<minor>.<patch>` tag builds the archive and publishes the Release with both artifacts. You only bump, changelog, commit and tag:

```bash
# 1. CHANGELOG.md: rename `## [Unreleased]` -> `## [<version>] - <date>`,
#    then add a fresh empty `## [Unreleased]` above it (Keep-a-Changelog).
# 2. Bump header + constant + package.json:
./scripts/bump-version.sh <version>
# 3. Commit changelog + bump together (`chore(release): <version>`).
# 4. Tag and push — the workflow does the rest:
git tag v<version>
git push origin main
git push origin v<version>
```

On the tag push the workflow validates the tag shape, runs `npm run release:archive` (= `build-release.sh`: build dashboards, rsync via `.distignore`, `composer install --no-dev`, zip, emit the profiler drop-in), extracts the matching `CHANGELOG.md` section as the notes, and publishes with **both** `release/newspack-event-logger-nodes.zip` and `release/00-newspack-profiler.php` attached. No manual `gh release create`.

`build-release.sh` is the single source of truth for archive contents and what the workflow invokes; run `npm run release:archive` locally to build the same artifacts. It rsyncs the plugin minus development artifacts (`src/`, `tests/`, `node_modules/`, `.github`, `composer.{json,lock}`, `package*.json`) so the zip holds the plugin directory at root, and copies `00-newspack-profiler.php` into `release/` as a standalone asset — it ships as an `mu-plugins/` drop-in, separate from the zip. `fail_on_unmatched_files` guards against a missing one.

**Coupling**: this plugin's version is not tied to the substrate's; they release independently. If a release depends on a specific runtime version, say so in the CHANGELOG entry.

## Architecture Decisions

Intentional. Don't "fix" them.

1. **9-namespace memcache schema.** Stats live in memcache only, never on disk. Per-partition prefix `evlog[:salt]:p{N}:`, then namespace: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`. Per-URL flame stats get TTL `max(3600, max_lifespan/24)`; everything else the full `max_lifespan` — a `Stats_Store` constructor property seeded from the substrate's `min_lifetime` key (default 86400) and floored at `Stats_Store::PREFIX_FLOOR` = 3600. `max_lifespan` is not itself a config key on either side. Caps prevent value-explosion against memcache's 1MB limit: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`, `MAX_DURATIONS_PER_BUCKET=100` (reservoir-samples the raw durations `Flame_Builder_Node` keeps per bucket for percentiles — Algorithm R once capped). Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category is preserved before capping.

2. **Sums, not means.** Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`, so cross-bucket and cross-partition merges are exact addition and the display layer computes means at read time. Do NOT regress to running-mean storage — that introduced an EMA-clamp bug we explicitly fixed.

3. **Fail-soft stats, fail-closed SSE slots.** The memcache failure asymmetry is deliberate. Every `Stats_Store` method returns `null` / `[]` / `false` when memcache is unreachable and never throws, so dashboards show "no data" instead of erroring. SSE slots fail CLOSED at the caller — memcache down means new connections get HTTP 429, preserving the rate-limit invariant.

4. **Settings fan-out is a node graph; no consumer means a silent no-op.** Propagation is the substrate `Settings_Sync_Node` graph in the `hub-control` topology. An option change always records a settings event (`Settings_Event_Writer` → `settings.p0`), and `Auto_Tuner_Node` mutates the specific rule named in the message (`rule_id`) and persists the whole ruleset via `Rule_Set::save()` — no `remote_manager` job, no `suppress_sync`; each write records a settings event like any admin edit. Nothing fans the change out unless `hub-control` is active and per-spoke `HTTP_Out` nodes are wired; on a spoke or standalone site the event is tailed and dropped. That IS the structural gate. `enable_workers` (retired v0.5.0) and `enable_aggregator` are both gone — hub-mode derives from which topologies are active.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt in `newspack_event_logger_nodes_stats_salt`, orphaning every existing key instantly and letting TTL clean up. Schema bumps and emergency flushes share the mechanism. Long-running workers cache `prefix` at construction, so restart them for immediate effect.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip; per-key `get` is a latency cliff on dashboards. Implemented in both the real and Fake memcached.

7. **Job_Intake for >4KB payloads, firehose for ≤4KB.** Small runtime jobs go through the firehose with `k:"job"` and `Job_Router_Node` extracts them. Large jobs go straight to `jobintake.log` via `Job_Intake::queue()`, which takes the auto-lock around large writes. The wrong path loses jobs: `Log_Manager` truncates data over `MAX_DATA_SIZE` (3840B) to a 1000-char excerpt with `" (truncated)"` on the category, so the handler never sees a parseable payload.

8. **Hub vs spoke topology.** Hub = the `aggregator` (+ `hub-control`) topologies active; there is no `enable_aggregator` toggle. Every node runs its own `Job_Worker_Node` dispatching `k='job'` against `newspack_nodes/job_handlers`. The hub additionally runs `aggregator`: per-spoke substrate `Remote_Source_Node`s pull spoke firehoses, and ELN's `Remote_Job_Rewrite_Node` — wired between the sources and the firehose `Topic` — rewrites incoming `k:"job"` to `k:"remote_job"`, which the hub's `Job_Worker_Node` dispatches against `newspack_nodes/remote_job_handlers`. The two filters are independent registrations, so a job type registers under either or both depending on where it should run.

9. **CRC32 + 31-bit-mask partition routing**, inherited from `Partition::hash_to_partition()`. Used by Topic, `Job_Intake`-keyed mode and `Log_Manager`'s URL routing. Same key → same partition across all producers.

## Layout

| Path | What |
|------|------|
| `newspack-event-logger-nodes.php` | Entry; deferred loader gated on `class_exists('\Newspack_Nodes\Bootstrap')` AND `Bootstrap::version_at_least('2.21.0', …)`. `Topology_Registry::register_plugin()` registers the top-level `Newspack_Event_Logger_Nodes\` prefix for node-class resolution plus the stock-topology dir; `register_namespace` registers only the `App\` service-CI sub-namespace; service CIs mount on `request_graph_ready` |
| `includes/class-settings-schema.php` | The single declarative Field/Schema source, one `Field` per setting, from which Config overlay keys, the admin register/render loop and worker-restart classification all derive (replaced three parallel hand-maintained arrays in v0.13.0). Only global fields remain — `enable_logging`, `log_memory`, `flush_every_line`, plus overlay-only `allowed_users` / `rules` / `hook_start_priority`; the seven per-URL/per-hook settings became per-rule |
| `includes/class-{rule,rule-set,rule-matcher}.php` | The per-URL ruleset (v0.26.0). `Rule` is an immutable value object whose id is the pattern's `url_hash`; `Rule_Set` handles durable load/save plus two-tier hook storage — inline in the autoloaded list up to `INLINE_HOOK_LIMIT`=100, else a per-rule non-autoloaded option mirrored into the substrate's `Table` (a memoized `Table_Node::table( TABLE_HOOKS, TABLE_TTL )`, whose `store()` / `lookup()` scope keys per install; the accessor is nullable, and a host with no cache backend simply has no warm mirror); `Rule_Matcher` ranks query-bearing patterns above exact patterns above prefixes, with length breaking ties only WITHIN a rank, case-insensitively, and no match meaning skip. `Log_Manager` resolves the governing rule per request through it |
| `newspack-event-logger-nodes-config.php` | Application config: the `rules` seed, debug toggles (`log_memory`, `flush_every_line`), `stats_mirror_node`, `custom_colors`, `recommended_log_events`, `allowed_users`. The active `.tsl` list moved to the substrate's `topologies` key in v0.5.0; remote-pull segment geometry moved to the substrate's `newspack_nodes_*` config |
| `includes/class-config.php` | Application config loader; substrate keys live in `newspack-nodes` |
| `includes/class-log-manager.php` | Per-request firehose writer; redacts URL secrets; refuses root |
| `includes/class-diagnostics-bridge.php` | `Diagnostics_Bridge` — carries the substrate's `newspack_nodes/stderr` seam into the active request or job log as a `stderr` entry, feeding the Error Log. Fleet alerts no longer route through here — the substrate journals those into `alerts.p0` |
| `includes/class-line-fitter.php` | `Line_Fitter` — shared packed-size fit for log emits whose partition doesn't lift the PIPE_BUF cap (errors/completed/gyroscope). A character clip only approximates the byte boundary, so callers route the packed message through here first |
| `includes/class-{request-builder,request-flight,flame-builder,auto-tuner}.php` | Node subclasses: assembly, the hidden in-flight snapshot sibling, flame + stats fan-out, and applying auto-tune decisions |
| `includes/class-flame-tree.php` | `Flame_Tree` — the pure flame-graph algorithm split out of `Flame_Builder_Node`: stateless tree construction from a request's entries (LIFO span matching), duplicate-sibling numbering, incremental cross-request merge, sums→averages finalize. Each span carries `t`, its start in ms from the request's own, which is what positions it on the x-axis |
| `includes/class-flame-fold.php` | `Flame_Fold` — the MERGING, resumable variant of that stack machine, static over a plain-array state (an in-flight envelope rides the Consumer's checkpoint frame, and an object would not come back). Same-name siblings under a parent collapse into one node with `count` / summed `value` / `max`, so a folded envelope costs O(distinct paths) rather than O(messages) |
| `includes/class-job-router-node.php` | Extracts firehose `k:"job"` / `k:"remote_job"` entries into jobs.log. Both the `Job_Intake` ingress helper and the `Job_Worker_Node` executor live in the substrate; `Log_Manager::begin/end_job_context` supplies the worker's per-job request context. Job Router owns no duplicate size alias or gate — producers use `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE` and the downstream Partition enforces writes |
| `includes/class-remote-job-rewrite-node.php` | Hub-side `k:"job"` → `k:"remote_job"` rewrite on aggregated firehose entries; the substrate `Remote_Source_Node` does the SSE pull |
| `includes/class-stats-store.php` | The 9-namespace memcache schema producer + reader (production use lives in `Flame_Builder_Node`) |
| `includes/class-discovery-collector-node.php` | Hub-side periodic discovery fan-out (`hub-control`). The settings-sync push side moved to the substrate; the old ELN `Server_Registry` / `Settings_Sync` / `Remote_Manager` / `Health_Check_*` were deleted, and credentials live in the substrate `Vault` |
| `includes/class-hook-categorizer.php` | `Hook_Categorizer`. The bucket `LRU_Cache` this plugin used to own now lives in the substrate (`Newspack_Nodes\LRU_Cache`), because `Table_Node` uses it as an L1 and the substrate cannot depend on a consumer |
| `includes/app/class-core.php` | WP-lifecycle hook instrumentation |
| `includes/app/class-{discovery,performance,rules}-ci-node.php` | The three service CIs mounted on `request_graph_ready` — the command-protocol REST surface. `Rules_CI_Node` exposes the editor's `list`/`save`/`upsert`/`delete`/`reset`, all routed through `Rule_Set` so the inline↔pointer tiering and orphan reconcile invariants can't be bypassed. `status`/`settings`/`aggregator` are substrate-owned now, `servers` was replaced by the substrate `vault` CI, and `logger`/`events` were removed in favor of `performance.hooks_registered` / `performance.overview` |
| `includes/admin/class-admin.php` | Application settings UI. The ruleset editor (`src/rules/RulesAdmin`) mounts into the settings page's "Logging Rules" section, not a separate submenu |
| `includes/class-current-request-overlay.php` | `Current_Request_Overlay` — registers the `current-request` bundle on the substrate's `newspack_nodes/devtools_tab_bundles` filter and injects THIS request's id into a JS global the tab reads |
| `includes/class-reqgrep-core.php` | `Reqgrep_Core` — the rid-grouping / pattern-matching engine shared by `wp nodes reqgrep` and the `performance` CI's `request_grep` verb, so both agree byte-for-byte on which lines belong to which request and when it is complete |
| `includes/cli/class-{reqgrep,ruleset-bench}-command.php` | `Reqgrep_Command` (`wp nodes reqgrep`, the application-aware firehose filter) + `Ruleset_Bench_Command` (ruleset-matcher micro-benchmark) |
| `includes/uninstall-cleanup.php` | Shared uninstall routine (options, ruleset hook options, transients) |
| `topologies/` | Per-partition graphs as declarative `.tsl`; topology name = filename, no `name:` frontmatter: `aggregator`, `hub-control`, `combined`, `performance`, `request-builder`, `job-router`, `flame-builder`. Worker-restart classification is by CONSUMER NODE TYPE — each `Settings_Schema` field's `restart:` key holds node-class tokens (`['Flame_Builder']`, `['Partition','Topic','Log']`) or `'all'` / `[]`, which `Restart_Planner` resolves to the live topologies running a matching node. They are NOT topology-name labels |
| `mu-plugins/` | `00-newspack-profiler.php`, the standalone profiler drop-in, also copied to `release/` and attached to the release. The refresh-ahead cache warmer moved to `newspack-cache-cozy` in v0.15.0 |
| `scripts/` | `build.mjs` (the esbuild dashboard builder `npm run build` invokes) and `pre-push` |
| `src/` | React dashboard trees (`overview`, `error-log`, `gyroscope`, `settings`, `requests`), the `current-request` overlay-tab bundle, and the `rules` editor tree (`RulesAdmin` / `RuleEditModal` / `useRulesGraph`, mounted by `src/settings`). Shared dashboard SCSS is a single `src/styles/base.scss` that `@forward`s the substrate's `@newspack-nodes/shared/styles/{tokens,mixins}`; each dashboard's own `styles/base.scss` `@use`s it. The byte-for-byte local `_dashboard-mixins.scss` and `_tokens.scss` duplicates were deleted in v0.26.0 |
| `tests/` | PHPUnit — `unit/` + `integration/` (command-dispatch and REST coverage live inside `integration/`, e.g. `M2CommandDispatchE2ETest.php`); config at `tests/phpunit.xml` |

## Common Pitfalls

Mistakes that have actually happened.

- **The seven global logging/auto-tune options are gone; logging is per-URL-rule (v0.26.0).** `log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`, `auto_disable_threshold` and `auto_protect_time_threshold` are no longer read. Each rule is a URL pattern (`/prefix` or exact `/x?`) with a `log`/`skip` action and, for `log` rules, its own hooks, custom events, significant events and auto-tune thresholds. Matching ranks query-bearing patterns (`/jobs/x?job-work`) above ALL exact patterns, which rank above ALL prefixes — length only breaks ties within a rank, so this is NOT longest-prefix-wins. Case-insensitive; **no match means skip, and empty means empty — there is no implicit log-all baseline.** Declare a `/` log rule to log everything, as the shipped config does alongside baseline skips for the substrate's worker IPC/SSE/spawn endpoints and `/wp-cron.php`. Rule id = the pattern's `Log_Manager::url_hash()`. Discovery only *stages* spoke-reported hooks into a non-autoloaded `discovered_hooks` option for the editor's hook picker; the editor is the only rule writer.
- **`enable_workers` AND `enable_aggregator` were retired.** There is no operator hub toggle — `tests/unit/RetiredConfigKeysTest.php` guards both keys. Hub-mode derives from whether `aggregator` is in the substrate's `topologies` list. Settings fan-out is structurally ungated: without `hub-control` active and per-spoke `HTTP_Out` nodes wired, a recorded settings event is tailed and dropped.
- **Two bounds, and BOTH fold.** `entry_budget` constrains the SUM of entries across everything in flight (what a staging site ran out of) and folds the largest envelope when crossed; `max_entries_per_request` constrains ONE envelope and folds that one, a faster trigger for a single runaway than waiting for the next pressure check. The per-request cap used to just stop appending and set a `truncated` flag nothing surfaced, which lost every entry past it — don't reintroduce that. A folded record ships `flame` + `folded` plus its kept head and tail rejoined around an `entries (aggregated)` marker; `Flame_Builder_Node` and the detail view read both shapes, and every record already on disk carries the old one.
- **PIPE_BUF (4096 bytes) for firehose writes.** `Log_Manager::message()` enforces `MAX_DATA_SIZE = 3840` on the JSON-encoded data: oversize payloads get an `error_log` notice, `" (truncated)"` on the category, and the data replaced with a 1000-char excerpt (`['m' => substr(...) . '...']`). The payload is lost, not chunked. Anything that might exceed — job payloads with serialized option blobs, image-handler args, large serialized arrays — MUST use `Job_Intake::queue()` (locked, `MAX_JOB_SIZE` 32MB).
- **Hub vs spoke routing.** Nodes only ever write `k:"job"`. Every node's `Job_Worker_Node` dispatches its own entries against `newspack_nodes/job_handlers`. On the hub, `aggregator`'s per-spoke `Remote_Source_Node`s pull spoke firehoses and `Remote_Job_Rewrite_Node` rewrites the incoming lines to `k:"remote_job"` for `newspack_nodes/remote_job_handlers`. The two filters are independent — register a job type under whichever side should handle it.
- **One shared `Core::$memd` handle — read it, don't build your own.** It is built once at boot by `Bootstrap::init_memcached()` from `memcache_servers` (default `127.0.0.1:11211`). Consumers read `Core::$memd` directly with null-safe `Core::$memd?->...`. Never hardcode server lists or open a second connection.
- **Tests set `Core::$memd` to an in-memory `\Memcached` double.** There is no `Cache_Interface` / `Memcached_Cache` to inject — the substrate ships the double at `tests/Helpers/InMemoryMemcached.php` and tests assign it in setUp. New cache call-sites read `Core::$memd`; nothing should type-hint a cache interface.
- **Salt rotation requires a worker restart.** `flush_all()` orphans keys, but workers cache `prefix` at construction.
- **Stats fail soft, SSE slots fail closed. Don't unify them.** Dashboards must show "no data" on memcache failure; SSE connections must reject with 429, because the slot pool IS the rate limit.
- **Application Node classes resolve by namespace prefix — no per-class registration.** The entry point calls `\Newspack_Nodes\Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` and `\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` for the service CIs. `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`, and the palette scans the classmap. A new node needs only `class Foo_Node extends \Newspack_Nodes\Node` under that namespace plus `composer dump-autoload -o`.
- **Token-array command contract.** The substrate migrated the `{name, arguments}` envelope to a token array (`list<string>` argv), so TM_COMMAND `arguments` and node-constructor `arguments` are no longer space-joined strings. Here: every `App\*_CI_Node` verb handler takes `array $args` and parses via `Command_Args::parse( self::arg_strings( $args ) )` — the `rules` `save`/`upsert` verbs read the whole JSON blob as `self::arg_strings( $args )[0]`. `Request_Builder_Node` and `Discovery_Collector_Node` declare `arguments( ?array $args = null ): array`, while `Flame_Builder_Node` and `Job_Router_Node` get the same handling free via the substrate's `Schema_Reflection` trait's `parse_schema_args()`. (Substrate mirror: `../newspack-nodes/AGENTS.md`.) `Reqgrep_Command` and `Log_Manager`'s firehose `Topic` construction pass token arrays; the dashboard hooks build args with `formatCommandArgs(...)`. TM_INFO and firehose-log VALUEs stay flat strings — only the command envelope changed. A handler still typed `string $args`, or code splitting a joined args string, is a stale port.
- **Type flags**: array VALUE → `TM_STRUCT`; string VALUE → `TM_BYTESTREAM`. Consumers reading an array VALUE gate on TM_STRUCT.
- **Don't restore `class_exists()` guards for in-plugin classes.** The deferred-loader require_once chain loads every class in this plugin before anything constructs them, so those checks are dead branches. Removing them was deliberate.
- **`wp nodes cli {reader}` requires the worker to exist.** `attach_to_worker` checks for `{base}/locks/{reader}.lock.d/`, so a typo'd reader id fails fast with "no worker '<id>' (run `wp nodes status` to list active workers)" rather than silently creating ghost IPC. Surface that error if you script it.
- **The substrate passes ALL message types through Partition/Topic.** TM_REQUEST/TM_ERROR/TM_EOF used to be silently dropped, which broke attached-mode error responses, `request_node` cli verbs and the stdin-close drain. Application data partitions only emit TM_BYTESTREAM / TM_STRUCT in practice, so the broader contract is a no-op on the write path — but Partitions now work as ad-hoc message transports.
- **Piped `wp nodes cli` sessions drain cleanly.** The substrate does a TM_EOF round-trip on stdin close, so scripted pipelines no longer need a trailing `sleep N`.
- **`App\Core` hook instrumentation has two non-obvious correctness mechanisms — don't strip them.** `wrap_callbacks()` skips any callback whose reflection reports a by-reference param (`callback_has_ref_param()`), because wrapping those breaks the WordPress by-reference contract. And a sacrificial `hook_spacer` at `PHP_INT_MAX - 2` (`SPACER_PRIORITY`) keeps a self-removing complete-hook at `PHP_INT_MAX - 1` firing (v0.13.1); `wrap_callbacks` treats everything at or above the spacer priority as ours and leaves it alone.

## Local Skills

`.claude/skills/`:
- `event-logger-nodes-workflow` — handlers, REST, dashboards, topologies
- `event-logger-nodes-debugging` — dashboards, memcache schema, hub/spoke routing, SSE
- `event-logger-nodes-review` — application contract checklist

## References

- **Architecture**: `docs/architecture-guide.md` — application design, topologies, hub/spoke flow, memcache schema
- **API**: `docs/API.md` — REST endpoints
- **Runtime**: `../newspack-nodes/` — the substrate this plugin depends on
- **Migration**: this plugin replaced the legacy `newspack-event-logger-plugins` monorepo wholesale (summarized in `README.md`). That monorepo has since been removed from the tree ("the museum"); this is the sole event-logger application, defaulting storage to `/tmp/newspack-nodes`
- **Config System** (v0.13.0): the settings layer builds on the substrate's `Newspack_Nodes\Config_System\{Field, Schema, Settings_Renderer, Options_Overlay, Reset_Gate}` classes. `Settings_Schema` declares the Fields; renderer, overlay and reset-gate are substrate-owned. The former local `src/admin-field-reset/` module and its `sync-shared.sh` copy gave way to the `@newspack-nodes/shared` build aliases resolved in `scripts/build.mjs`
