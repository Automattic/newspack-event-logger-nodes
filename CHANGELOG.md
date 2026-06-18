# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`Settings_Event_Writer`** — the producer end of the settings-sync node graph (Slice A1). On a watched WP-option change (`update_option`/`add_option` for any `newspack_*` option) it appends an option-NAME-only `TM_STRUCT` event (`VALUE = ['option' => $name]`, no value) to `settings.p0` via a transient `settings:writer` Partition torn down after each write (so two updates in one request don't collide in the Core registry). The event is name-only so it is always ≤PIPE_BUF → an atomic lockless append. Also owns the `Settings_Event_Writer::suppress( bool )` re-entrancy guard the spoke-side `set` verbs raise while applying a synced setting so it doesn't bounce back.
- **`App\Settings_CI_Node` `set <option> <value>` verb** (Slice A1) — normalized positional spoke-side receiver: one substrate setting per command, addressed by its full `newspack_nodes_*` option name (the bare short-name is also accepted), validated against the same whitelist/bounds as `update`, int-sanitized, written with `autoload=true`. The apply (`update_option` + `Config::reset()`) is wrapped in `Settings_Event_Writer::suppress(true)` / `finally suppress(false)` so applying a synced setting does NOT re-fire the spoke's own settings event and bounce the change back out. The existing `update` verb is unchanged (removed in a later slice once `set` is proven).
- **`App\Performance_CI_Node` `set <option> <value>` verb** (Slice A1) — the perf-settings sibling of the `Settings_CI` `set` verb: normalized positional spoke-side receiver for the nine perf-tuning options (`newspack_event_logger_nodes_*`), one setting per command, validated against the same `SETTINGS_OPTIONS` whitelist and array/int/float/bool sanitization as `settings_update`. The apply (`update_option` with `Config::autoload_for` + `Config::reset()`) is wrapped in `Settings_Event_Writer::suppress(true)` / `finally suppress(false)` so a synced apply doesn't bounce back out as a fresh settings event. The existing `settings_update` verb is unchanged (removed in a later slice once `set` is proven).
- **`Discovery_Collector_Node`** — hub-side periodic discovery node (Slice A1), replacing `Remote_Manager`'s poll-based discovery. A `Timer_Node` whose `fire()` fans one `discovery.get` `TM_COMMAND` to the connected Tee (`<target>/discovery`, broadcast to every spoke's `Discovery_CI`); `arguments( <seconds> )` arms the recurring timer (default 300s legacy cadence). Each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks`/`custom_events` into the hub's `log_events`/`discovered_events` options — porting `Health_Check_Extensions::process_discovery`/`merge_hooks`/`merge_events` verbatim (remote-string sanitization, `MAX_EVENTS=10000` cap, custom-events excluded from `log_events`, option-cache invalidation before the read-modify-write), wrapping the `update_option` writes in `Settings_Event_Writer::suppress(true)`/`finally suppress(false)` so the merge doesn't bounce. The merge folds each reply incrementally + idempotently (no barrier), so out-of-order/partial replies converge to the same union.
- **`hub-control.tsl` topology + bootstrap wiring** (Slice A1) — the single-instance control topology (`var num_partitions = 1`) that mounts the settings-sync + discovery pipeline: a `Consumer` tailing `settings.p0`, the substrate `Settings_Sync_Node` (armed with the 13 `add_setting` mappings — 4 substrate-remap → `settings` CI, 9 perf → `performance` CI, each addressing the spoke `set` verb by full option name), a shared `spokes:tee`, and `Discovery_Collector_Node`, both periodic nodes on a 300s tick. No per-spoke `HTTP_Out` nodes ship in the file — operators add `make_node HTTP_Out remote:<id>` + `connect_node spokes:tee remote:<id>` from the topology console; the pipeline is correctly inert until a spoke is wired. The ELN deferred bootstrap now also calls `Settings_Event_Writer::init()` and registers the `newspack_nodes/settings_sync/value` value-resolver filter (`newspack_event_logger_nodes_resolve_settings_sync_value`), which resolves a blank/absent synced option to its file-backed default by stripping the `newspack_event_logger_nodes_`/`newspack_nodes_` then `remote_` prefixes and looking the canonical key up in `Config::load_config_defaults()` — porting the old `Settings_Sync::maybe_queue_static_sync` empty→default logic. Enabling `hub-control` is an operator config step (add it to the substrate `topologies` list), not automatic. Runs in parallel with the legacy `Settings_Sync`/`Remote_Manager` push path until Phase 6.

### Changed

- **Remote-spoke credentials now live in the substrate Vault.** `Stream_Merger_Node`, `Remote_Manager`, `Health_Check_Tick_Node`, and `Aggregator_CI_Node` read `\Newspack_Nodes\Vault::get_instance()` instead of the local server registry. The aggregator side-effects that used to live in the servers CI's verbs (full settings-sync fan-out + supervisor restart) now run from a listener on the substrate's `newspack_nodes/vault/changed` action.

### Fixed

- Performance Dashboard: the page title now uses the standard WordPress admin heading size (23px / 400) instead of the unstyled browser default (~2em bold), so it matches the rest of wp-admin.
- **Select Hooks button restored** on the Event Logger Settings page. It had vanished because the page hosted two exospine React graphs (the hook-catalog selector and the aggregator-admin Configured Servers app) that collided registering the reserved `_http` node; moving the server UI to the Nodes hub Vault tab leaves a single graph and clears the collision.

### Removed

- **Server registry + aggregator-admin UI** — `Server_Registry`, the `servers` CI, the Configured Servers settings field, and the `aggregator-admin` React app are gone; remote-server credentials are owned by the substrate `\Newspack_Nodes\Vault` and managed from the Nodes hub **Vault** tab.
- **`Config::kill_readers()` delegate** — `kill_readers` moved to the substrate `Supervisor`; call `\Newspack_Nodes\Bootstrap::supervisor()->kill_readers( $groups )` (or the topology-activate REST path that wraps it). The `ConfigTest` cases that exercised the substrate's `kill_readers` and `Config_Utils::sanitize_option()` through the now-removed delegates were dropped — that coverage is substrate-owned (nodes' `SupervisorTest` / config-system tests).

### Internal

- **PHPStan now includes the ShipMonk dead-code detector.** `npm run lint:phpstan` runs through `phpstan-deadcode.neon`, so dead-code findings stay in the normal PHPStan/lint-staged/pre-push gate instead of being an opt-in sweep. `npm run lint:deadcode` remains as an alias for the same gate. Same substrate caveat — most findings on an application built atop the runtime are WP/CLI entrypoints, hooks, or wire constants, not genuinely dead; verify call paths before deleting.

## [0.18.0] - 2026-06-17

### Changed

- **Clarified the in-line state-logging format in `Flame_Builder_Node`, `Job_Router_Node`, and `Remote_Source_Node`** for readability; no behavior change.
- **`Remote_Source`, `Request_Builder`, and `Stream_Merger` `arguments()` now delegate to the substrate's centralized `parse_schema_args()`** (no per-node empty-string short-circuit), following the newspack-nodes ADR-11 revision where a missing token takes the arg's schema `default` or throws if `required`. Behavior is unchanged for these nodes — they construct with their declared tokens (`Remote_Source`'s `server_id`/`url` stay `required`).

- **Register the firehose/jobintake producers via the substrate's new `newspack_nodes/registered_log_producers` filter.** Replaces the removed `newspack_nodes/expected_log_basenames` filter: ELN now contributes its request-scope producer basenames (`firehose`, `jobintake`) to the producer set the rewritten log GC expands (× config `num_partitions`) into protected `logs/{producer}.p{N}/` dirs, keeping those request-scope logs (declared in no `.tsl`) safe from garbage collection. Requires newspack-nodes with the segmented-I/O P3 log-GC rewrite.
- **Adopt the newspack-nodes flat partition-in-name layout** (`logs/firehose.p<partition>/{seg}.log` instead of `logs/firehose.log/p<partition>/{seg}.log`; offsetlogs flattened, no inner `/p0`). All six topologies are rewritten, plus the production constructors that build substrate nodes directly: the `Log_Manager` firehose Topic (`firehose.p{partition}` template — without the `{partition}` token the firehose would write to the wrong path and the pipeline would read nothing), `Job_Intake` partitions (`jobintake.p{partition}`), the `Stream_Merger` offsetlog (flat), and the `Performance_CI` + `reqgrep` readers (now read the live flat data). Requires newspack-nodes with the segmented-I/O P0 arg-signature change. Existing on-disk data is in the old layout and should be cleared — no automatic migration.
- **View nodes adopt the substrate's shared `@newspack-nodes/shared/pendingReplies`.** `servers-view-node` and `hook-catalog-view-node` replace their inline `pending` Map + local `_errorMessage` with `this.replies = new PendingReplies()` (settle via the boolean return; `servers-view-node`'s teardown uses `replies.rejectAll(...)`); `performance-view-node` keeps its richer pending Map — its sibling `performance-command-node` writes `{slice, initial}` / `{resolveOnly, transform}` entries through it — and adopts only the shared `errorMessage`. Their hooks (`useAggregatorAdminGraph`, `useHookCatalogGraph`) call `view.replies.add(...)`. No behavior change.
- **Dashboard build runs through the substrate's shared `buildDashboards()` build-kit.** `scripts/build.mjs` is now a thin shell that injects this plugin's `esbuild`/`sass`/`rtlcss` into the kit (resolved from the sibling `newspack-nodes` checkout, overridable via `NEWSPACK_NODES_BUILD_KIT`) and declares only its aliases + 7 entries; build output is byte-identical. `npm run watch` now cleans `build/` first, matching `npm run build`. `jest.config.js` likewise delegates to the kit's `createJestConfig()` (passing this plugin's React/d3 pins + the d3 ESM transform allowlist), so the shared moduleNameMapper ordering is owned in one place.
- **Jest suite fails on an unexpected `console.warn` / `console.error`.** `jest.setup.js` now records every non-substrate `console.warn` and every `console.error` (React `act(...)` warnings, deprecations, genuine errors) and re-throws it in `afterEach` — failing the test instead of printing it (mirrors the substrate setup).
- **Dashboard asset enqueue routes through the substrate's `Admin::enqueue_react_page()` registrar.** The `admin_enqueue_scripts` dispatcher now delegates the script + `index.css` + `NewspackNodesData` localize to the shared registrar (passing its complete localize payload — `restUrl`, `aggregatorRestUrl`, `nonce`, `restartNonce`, `tree`, `version`), keeping every per-tree extra (the `performance-logger` settings CSS, the `eventLoggerDashboards`/recommended-hooks inline scripts, and the `aggregator-admin` secondary bundle) anchored on the returned handle. As a side effect dashboards now cache-bust on the wp-scripts manifest hash (deps from `index.asset.php`) rather than `filemtime`, and `index-rtl.css` is activated when present.

## [0.17.1] - 2026-06-12

### Changed

- **Deprecated `isSmall` Button prop migrated to `size="small"`.** The two errors-only toggle Buttons (URL table + URL detail view) still passed `isSmall`, deprecated in WP 6.2; both now use the documented `size="small"` replacement. A `react/forbid-component-props` eslint rule bans `isSmall` at the JSX-attribute level so it can't creep back in, and the plugin header now declares `Requires at least: 6.5` — the dashboards run on core's `window.wp.components`, and the Button `size` prop needs WP 6.4+.

- **Admin dashboard form controls opt into the WordPress 40px default size + no-bottom-margin styles.** Added `__next40pxDefaultSize` to every `TextControl` / `SelectControl` / `SearchControl` and `__nextHasNoMarginBottom` to the `SearchControl` / `CheckboxControl` that lacked it (across the performance dashboards, URL detail, overview, and the hook/custom-event selector modals). Clears the `@wordpress/components` 6.7/6.8 deprecation notices; those controls now render at the 40px height that becomes the WordPress default in 7.1.
- **The Performance Dashboard + Settings entry points mount via React 18 `createRoot` instead of the deprecated `render()`.** `performance-dashboards` (AdminApp + ErrorLogPage) and `performance-logger` (tag-input fields) now `createRoot( container ).render( … )`, matching the other dashboard entry points and removing the "ReactDOM.render is no longer supported in React 18" warning.

## [0.17.0] - 2026-06-12

### Changed

- **Worker requests get their own `{base_url}?{worker_type}` per-URL stats row, with timing, and are fully excluded from global aggregates.** `Request_Builder_Node` now captures the `NEWSPACK_NODES_WORKER_TYPE` value (sanitized `[a-z0-9_-]`) into `worker_type`, and `emit_request()` rewrites `$request->url` to `{base}?{worker_type}` once (so the index line, compact summary, and stats all read the same effective URL) instead of colliding worker hits onto the real URL. `url_hash()` no longer strips at `?` (callers already strip the real query upstream; the only surviving `?` is the intentional worker marker), so the synthetic row hashes distinctly. `Flame_Builder_Node::accumulate_all_stats()` splits its single timing gate into `$record_timing` (per-URL flame / url_stats / url_dim / cat_by_url keep worker timing) and `$count_global` (hourly, dimensional global + per-server, leaderboard, categories drop workers entirely).

### Fixed

- **Workers no longer leak into global hourly peak memory, global dimensional count/peak, or the global hook auto-tune signal.** The prior single gate excluded workers from global *timing* but still bumped global hourly `sum_peak_mb` and global dimensional `c`/`m` unconditionally, and worker profiles could still drive plugin-wide `hooks_to_disable` / `custom_events_to_disable` (noisy-hook detection); the two-gate split closes all three leaks.
- **The cron-backstop supervisor's stats URL is `/jobs/newspack-nodes?supervisor`, not `/jobs/newspack-nodes/supervisor?supervisor`.** The job-context handler dropped the redundant `/supervisor` path segment (the `?supervisor` suffix already comes from `worker_type`).

### Removed

- **The orphaned `worker_type` firehose-keyword state callback in `Request_Builder_Node`.** It was a museum-era workaround (the old plugins emitted an explicit `message('worker_type', …)` because the env var was set *after* the environment block was logged); the substrate now sets `NEWSPACK_NODES_WORKER_TYPE` before the environment block, so worker detection flows solely through the `environment_v2` env-var line and nothing produces a `k='worker_type'` entry anymore.

## [0.16.2] - 2026-06-12

### Changed

- **SSE dashboard "Xs ago" staleness now reflects connection liveness, not row arrivals.** The Request Log and Error Log dashboards (and the Gyroscope's in-flight view) source their "Xs ago" indicator from the shared `_sse` connector's `lastEventTime` instead of their own view node's. The connector stamps `lastEventTime` on every inbound frame AND on the server's idle heartbeats, so an idle-but-healthy stream resets "Xs ago" to ~0 instead of showing a climbing counter that looks like a dead connection; a real drop (no heartbeats) leaves it frozen and "ago" climbs as the intended warning. Side effect: Clear no longer hides "Xs ago" — clearing the displayed rows leaves the live connection untouched, so the staleness persists. Requires `newspack-nodes` with the `_sse` connector exposing a public `lastEventTime`.

## [0.16.1] - 2026-06-12

### Changed

- **`Job_Router_Node` emits the job kind under `k`, not `type`.** The normalized jobs.log entry is now `{ k, handler, parameters, ts }`, matching what `Job_Intake` already writes and what the substrate `Job_Worker_Node` dispatches on — so the kind field is the same `k` from firehose category → jobs.log → worker, with no rename at any hop. Requires `newspack-nodes` with the `k`-reading `Job_Worker_Node`.

### Fixed

- **Jobs queued via `Job_Intake` (large/`jobintake.log` payloads) are no longer silently dropped.** `Job_Intake` writes entries keyed by `k`, but the substrate executor read `type`; in topologies that wire `jobintake:consumer` straight to `jobs:partition` (e.g. `combined`), every jobintake-sourced job — third-party event imports (film times, Ticketmaster, …) and any other `write_job()` caller — was read off jobs.log and discarded before reaching its handler. Normalizing the kind field to `k` end-to-end restores dispatch.

## [0.16.0] - 2026-06-11

### Added

- **Debug overlay renders registration edges.** The bundled debug overlay — which inlines the substrate's `parseMetadata` / `SchematicCanvas` via the `@newspack-nodes/debug-overlay` alias — now draws node-name event registrations as dashed, informational edges between visible nodes (dotted, event-name hover tooltip, not click-deletable). Requires `newspack-nodes` with the `registrations` `dump_metadata` field.

### Changed

- **`Reqgrep_Command` carries its own `READ_CHUNK_BYTES` for the cat/follow reader.** The CLI firehose reader chunked its `read_at` loop by the substrate's `Consumer_Node::MAX_POLL_BYTES`; that constant was removed when `Consumer_Node` moved to one-block-per-poll reads, so reqgrep now owns a `READ_CHUNK_BYTES` (10 MB) constant for bounding CLI memory — a separate concern from the node's per-poll event-loop yield. Requires `newspack-nodes` with the new Consumer read path.
- **Worker-output partitions drop the cross-process write lock for `void_warranty`.** The `requests` / `flames` / `jobs` partitions (large request records exceed PIPE_BUF) now opt into the substrate's lock-free `void_warranty` instead of `allow_large_writes`. Each is written by exactly one worker fleet, and the substrate now refuses to enable or spawn a topology set where two fleets would write the same partition (write-conflict detection at the admin sanitizer + supervisor), so the per-partition exclusivity lock is redundant — its sole job was guarding against a second writer that enforcement now prevents. Requires `newspack-nodes` with `void_warranty` + write-conflict enforcement.

### Fixed

- **Stalled in-flight requests now time out on idle / low-traffic partitions.** `Request_Builder_Node` is now a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`) and rotates its in-flight cache on each tick, so a request that never completes is evicted and written to `requests.log` with `error_status='T'` even when no further firehose lines arrive to drive rotation via `fill()`. The timed-out request is also emitted to `completed:tee`, so the gyroscope reaps it. `Request_Flight_Node` likewise fires via the Router hitchhike — its `fire()` replaces the old `fire_cb()` override, and setting its in-flight target now *is* what enables snapshots (a non-empty target starts the hitchhike, an empty one stops it); the snapshot cadence is the Router's tick. Previously an idle request-builder never rotated, so a stalled request sat invisible: absent from `requests.log`, unclickable, and missing from "Show Errors". (The separate worker-respawn-boundary loss is addressed by the offsetlog snapshot below.)
- **In-flight requests now survive the ~10-min worker respawn.** The firehose Consumer snapshots `Request_Builder_Node`'s in-flight cache into its offsetlog alongside the read cursor (`cmd firehose:consumer:config set_snapshot_node request-builder`), so a request whose head was read before the recycle completes on the new worker instead of vanishing. The broken-out `request-builder` and `job-router` topologies now read the firehose with distinct offsetlog paths (`firehose.request-builder.p{N}` / `firehose.job-router.p{N}`) so they can run together without clobbering each other's cursor.

### Removed

- **The `set_inflight_interval` config verb is gone.** With the in-flight snapshot hitchhiking the Router's 1s TIMER, a per-node interval no longer means anything — the argument was silently ignored. Enabling snapshots is now solely `set_inflight_target` (non-empty target → start, empty → stop), and topologies drop their `set_inflight_interval 1000` line. `Request_Flight_Node::set_interval()` / `interval()` and the cosmetic `inflight_interval_ms` field are removed.
- **Performance flame graphs no longer prune small frames that should be visible.** `pruneFlameGraph` previously applied the 0.1%-of-total cutoff unconditionally, so even a small flame graph lost every frame under 0.1% — frames vanished purely for being small, well before any node-count limit. A frame is now kept if it is among the largest `softMaxNodes` (1000) frames **OR** is at least 0.1% of the total, so nothing is stripped while the graph is under the soft cap and sub-0.1% frames stay visible past it only once they're also ranked out. A new `hardMaxNodes` (5000) absolute ceiling replaces the old 1000-node hard cap. The `pruneFlameGraph` option `maxNodes` is replaced by `softMaxNodes` / `hardMaxNodes`.

## [0.15.0] - 2026-06-11

### Changed

- **`Substrate_Guard` is gone; the deferred bootstrap is gated on a plain `class_exists` substrate-presence check.** The guard's version floor + API probe + admin notice solved a non-problem: `Requires Plugins: newspack-nodes` keeps the runtime active on WP 6.5+, and the two plugins deploy together, so a present-but-too-old substrate isn't a real case. The `plugins_loaded` priority-11 bootstrap now simply no-ops when `\Newspack_Nodes\Node` isn't loaded. `includes/class-substrate-guard.php` + its test are removed.
- Raise the declared PHP floor to 8.2, matching the `newspack-nodes` substrate this plugin depends on.

### Removed

- **The refresh-ahead cache warmer moved to its own plugin, `newspack-cache-cozy`.** `Cache_Warmer_Tick_Node`, the `mu-plugins/01-newspack-cache-warmer.php` drop-in (`Newspack_Cache_Warmer\Cache_Warmer` + `Cold_Read_Object_Cache`), the `scripts/schedule-cache-warmer.sh` / `unschedule-cache-warmer.sh` operator scripts, and the three cache-warmer test files are gone from this plugin. The `Cache_Warmer_Tick_Node::init()` call is dropped from the worker-runtime bootstrap, and the drop-in is no longer copied into `release/` or attached to the GitHub release. Cache warming is now a focused, independently-released plugin that builds on the same substrate. **Migration:** install `newspack-cache-cozy` (plugin zip + its own `01-newspack-cache-cozy.php` mu-plugin drop-in) to keep warming; remove the stale `01-newspack-cache-warmer.php` drop-in. The new plugin uses its own option/cron keys (`newspack_cache_cozy_*`), so the old `eln_cache_warmer_*` options orphan harmlessly.

## [0.14.0] - 2026-06-10

### Changed

- **Application nodes adopt the substrate's new `Schema_Reflection` trait.** The substrate moved positional-arg parsing + `:config` interpreter auto-wiring off the base `Node` into an opt-in `Schema_Reflection` trait. `Request_Builder_Node`, `Stream_Merger_Node`, `Flame_Builder_Node` (auto-wire a `:config` sibling) and `Remote_Source_Node` (parses positional args) now `use \Newspack_Nodes\Schema_Reflection`, call `$this->parse_schema_args()` in their `arguments()` override, and call `$this->auto_wire_interpreter()` in their ctor. `Cache_Warmer_Tick_Node` / `Health_Check_Tick_Node` parse their single arg inline and carry no trait. Requires `newspack-nodes` with the `Schema_Reflection` trait. Behavior unchanged.
- **`Remote_Source_Node`'s bespoke `dump_node()` secret-redaction override is removed; the substrate now redacts credentials for every node.** The substrate's `Node::dump_node()` redacts any secret-named property (`auth_password`/`auth_token` included) by default, so the per-node override here was redundant — and would have left any *other* credential-bearing node unprotected. Behavior is unchanged: `dump_node my_remote` from the REPL still shows the credential slots as `[REDACTED]`. Requires `newspack-nodes` with base-`dump_node` redaction.
- **Substrate floor raised to `newspack-nodes` 0.15.0, with a `Schema_Reflection` trait probe.** The `Schema_Reflection` trait the nodes above `use` ships in nodes 0.15.0, so an older substrate would satisfy the guard (floor was 0.13.0) and then fatal at class-load on the missing trait. `Substrate_Guard::MINIMUM_NODES_VERSION` is now `0.15.0`, and `required_apis_present()` probes `trait_exists( '\Newspack_Nodes\Schema_Reflection' )` (new `REQUIRED_TRAITS` set) so an absent-but-versioned trait flips the guard to its admin notice instead of a fatal.

### Fixed

- **`AdminTest::reset_default_provider` data provider is now static.** PHPUnit 10 deprecates non-static `@dataProvider` methods (a hard error in PHPUnit 11); the suite ran clean except for this one deprecation. Test-only.
- **Application test temp dirs no longer collide with the substrate's under parallel coverage.** ELN inherited the substrate `make_temp_dir()`, which defaulted to the `newspack-nodes-test-` prefix — so under `run-coverage` (nodes + ELN run in parallel) each suite's `run-coverage.sh` `rm -rf`'d the *other's* live temp dirs. ELN's `TestCase` now defaults to its own `newspack-event-logger-nodes-test-` prefix and `run-coverage.sh` purges only that. Test-only.

## [0.13.1] - 2026-06-09

### Fixed

- **Self-removing filters no longer swallow a hook's `(complete)` entry.** es-wp-query's `ES_WP_Query_Shoehorn` registers run-once `found_posts_query`/`found_posts` filters at priority 1000 that `remove_filter()` themselves mid-run. WordPress core's `WP_Hook::resort_active_iterations()` then parks the iteration pointer on the next surviving priority and `apply_filters()`' `next()` skips it — which was our `hook_complete` at `PHP_INT_MAX - 1`. Every ES-backed query therefore logged a `(start)` with no in-place `(complete)`; the spans dangled until Log_Manager's end-of-request sweep, and the flame builder nested each successive query one level deeper (a 21-query homepage stretch rendered as a bogus 47-level staircase — the same request that exposed the decode-depth bug below). `App\Core` now registers a sacrificial no-op `hook_spacer` at `PHP_INT_MAX - 2` on every instrumented hook: the pointer-skip consumes the spacer instead of the complete (verified against real `WP_Hook`, including multiple self-removals in one invocation). The spacer priority is also excluded from significant-event callback wrapping.

- **Deep flame graphs (>~32 span levels) no longer vanish from the request detail view.** `Flame_Builder` allows flame trees up to `MAX_STACK_DEPTH` (50) span levels — ~2 JSON nesting levels per span — but both the write-side index formatter (`Flame_Builder_Node::format_index_entry`) and the read-side lookup (`Performance_CI_Node::find_flame_for_rid`) decoded packed flame lines with `json_decode( …, 64 )`. A flame deeper than ~32 spans (typical of slow page renders with many nested ES-backed queries) failed both decodes: the blob was written to `flames.log` but never indexed and never returned, so the dashboard silently showed no flame graph. Both sites now decode with the new `Flame_Builder_Node::FLAME_JSON_DEPTH` (`2 × MAX_STACK_DEPTH + 8`), keeping the decode budget tied to what the builder can emit. Note: deep flames written before this fix have no index entries and stay unfindable; only newly assembled requests benefit.

## [0.13.0] - 2026-06-09

### Changed

- **Settings admin + config migrated onto the shared `newspack-nodes` Config System.** The three parallel arrays `Config` and `Admin` hand-maintained in lockstep (`Config::$option_schema`, `Admin::$option_names`, `Admin::$delete_on_blank_options`) collapse into one declarative `Settings_Schema`; the overlay key-list, register/reset surface, section+field render loop, and the worker-restart classification all derive from it. `maybe_request_worker_restart()` now reads each field's restart class via `Schema::restart_for()` — only the genuine non-field cases stay hand-coded (`enable_aggregator`'s single-lock kick and the rotated-by-flush `stats_salt`); the retired `enable_jobs` entry is dropped. The bespoke `auto_tune` + `configured_servers` controls are unchanged, and field markup routes through the shared `Settings_Renderer` (`checkbox`/`number`/`react_mount`). Behavior is byte-identical — overlay keys, registered options, delete-on-blank set, reset gating, restart classification, and the rendered markup are unchanged (verified against the pre-migration literals). Two latent divergences are resolved along the way: `enable_aggregator` is now typed once (a single `bool` Field, no separate inline closure), and the bool→int default coercion is single-sourced in `bool_to_int()`.
- **Substrate floor raised to `newspack-nodes` 0.13.0.** The settings layer now depends on the substrate's `Config_System\Field` / `Schema` / `Settings_Renderer` classes (added in nodes 0.13.0). `Substrate_Guard` requires `>= 0.13.0` and probes those three classes, so an older substrate shows the guard notice instead of fataling on class-not-found when the plugins are released independently.

### Fixed

- **Editing a tag-input setting after arming its reset no longer discards the edit on Save.** The shared per-field reset module only auto-drops a pending `↺` mark when a non-hidden control is edited; a React tag-input field (`TagInputField`: Log/Skip URLs, Log/Custom Events, Significant Events) posts through a hidden JSON carrier the module ignores, so marking-then-editing left the marker in place and `Reset_Gate` deleted the option on Save — reverting to the file default and dropping the operator's edit. `TagInputField` now clears the pending mark on its wrapper whenever its value changes (skipping the initial mount), matching the module's drop-on-edit behavior. Pre-existing; surfaced during the Config System migration.

## [0.12.1] - 2026-06-09

### Fixed

- **Per-field reset toggle now previews a settings checkbox's real default.** Arming a reset (`↺`) cleared every checkbox to unchecked regardless of its default, so a default-enabled box (e.g. "Enable event logging") looked like it would be disabled while the reset was pending (Save was always correct). Each checkbox now carries a `data-nn-reset-default` attribute — the file-config default, via the new `bool_file_default()` helper extracted from `bool_option_with_file_default()` — so the shared reset JS previews the correct state.

## [0.12.0] - 2026-06-09

### Changed

- **Scoped `box-sizing: border-box` baseline per dashboard.** Each React dashboard bundle (performance dashboards, gyroscope, request-log, logger, aggregator) now applies a `border-box` reset scoped to its own mount root, so the rule can't leak into the rest of wp-admin.
- **`Job_Worker_Node` moved to the `newspack-nodes` substrate.** Generic async-job dispatch is runtime plumbing, so the executor now ships with the substrate (`\Newspack_Nodes\Job_Worker_Node`). This plugin keeps only the app-specific job *request context*: `Log_Manager::begin_job_context()` / `end_job_context()` (relocated here from the old node, now stack-based and reusing `Log_Manager::generate_request_id()`) are hooked onto the substrate's new `newspack_nodes/job_worker/before_job` / `after_job` actions, so a dispatched handler still runs under a synthetic `/jobs/{handler}` `$_SERVER` scope with the parent logger suspended. The supervisor-tick wrap and `Health_Check_Tick_Node` now call `Log_Manager::begin/end_job_context` directly. `topologies/job-worker.tsl` is deleted — the `job-worker` topology is now a substrate stock topology. No behavior change for job dispatch or logging; full PHPUnit suite green.
- **`Remote_Manager` no longer carries its own `begin/end_job_context` copy.** `handle_job` now wraps each remote action through the canonical `Log_Manager::begin/end_job_context` instead of a private duplicate that rewrote `$_SERVER` but never suspended the logger (so its promised per-action request_id never reached a fresh `Log_Manager`). The nested wrap now correctly scopes each remote action to its own `/jobs/remote_manager/{action}` request with a fresh request_id — the correlation the old docblock claimed but didn't deliver. Removes ~50 lines of duplicated context code.

- **Shared React hooks/utils are now aliased from `newspack-nodes`, not copied.** Imports of `../shared/*` are now `@newspack-nodes/shared/*` (resolved by esbuild + jest to the sibling's canonical `src/shared`, mirroring `@newspack-nodes/runtime`), and the local `src/shared/` synced copies are removed. The one ELN-owned test helper that lived under `src/shared` (`renderHook`) moved to `src/test-helpers/`. Retires the `sync-shared.sh` copy mechanism — one source of truth in newspack-nodes.

- **Config-system code is now the shared `Newspack_Nodes\Config_System\*` (no ELN copies).** `Config::load_config()` replaces its own presence-based sentinel-overlay loop with `Config_System\Options_Overlay::apply()` (the substrate-merge layering is preserved). The admin's per-field reset/delete-on-blank gate — formerly the in-class `reset_or_blank` + `is_reset_marked` + `is_blank_text_like` methods and a manual `pre_update_option_{$option}` `add_filter` loop — is now a single `Config_System\Reset_Gate::register( RESET_MARK_FIELD, <full option list>, <text-like subset> )` call; the per-field marker name comes from `Reset_Gate::mark_name()`; and the settings page enqueues the shared `Config_System\Field_Reset_Assets::enqueue()` bundle (the nodes-built `newspack-nodes-field-reset` module) + `Field_Reset_Assets::highlight_style()` instead of the ELN-local copy. Every settings-form field — booleans (`enable_logging`, `enable_aggregator`, `log_memory`, `flush_every_line`) and multi-selects (`log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`) included — carries the per-field reset toggle, so a reset clears it; the text-like blank-delete subset stays the `auto_*` / `remote_*` numeric fields. `aggregator_servers` is excluded from the reset set — it's managed by the ServerRegistry REST CRUD, not the settings form, so there's nothing to reset there. The now-dead ELN-local `src/admin-field-reset/` JS module + its build entry are removed (ELN hard-depends on newspack-nodes and references its bundle by URL). Behavior-preserving; full PHPUnit suite green (1703), jest/phpcs/phpstan clean.

### Fixed

- **Request Log and Error Log no longer freeze once the buffer hits its cap.** The live-stream views' change-detection keyed off `buffer.length`, which is pinned the moment the view buffer saturates `maxEntries` (1000 for the request log, 5000 for the error log) and starts rotating (newest unshifted, oldest dropped). With the length constant, `setEntries` stopped firing, so the rendered list — and the "Xs ago" staleness — froze at the cap while events kept flowing. Change-detection now keys off the monotonic newest `seq`, which keeps climbing as the buffer rotates, so the log keeps scrolling the most-recent N indefinitely. Staleness reads the *unfiltered* buffer's newest seq, so "Xs ago" reflects every arrival regardless of an active filter (the request log had also stopped updating staleness whenever the filter hid the newest rows) and keeps ticking past the cap. Clear now also resets the staleness so a freshly-emptied log no longer shows the pre-clear age.
- **Request Log and Error Log no longer flicker a row down-then-up as new entries arrive.** The smooth-scroll offset that holds the existing rows in place while a new row slides in was applied in the requestAnimationFrame loop, one channel out of step with React's commit of the new row — so the row could paint a frame before its compensating offset, jumping the list down a row and snapping back the next tick. The compensation now runs in a `useLayoutEffect` keyed on the committed entries (synchronously after the rows mount, before paint), so the offset and the new row land in the same frame. The first row in an empty list, and the first row after Clear, no longer slide. (A separate, pre-existing whole-row judder under sustained heavy load — the continuous transform vs the discrete virtualization spacer — is unchanged and tracked separately. The substrate's canvas-rendered Raw Logs view draws the offset and rows together each frame and has neither bug.)
- **Topology debug overlay no longer appears on the settings page.** The `aggregator-admin` bundle — mounted into the Configured Servers section of the operator-facing settings page — rendered the sticky `?nodes-debug=1` dev HUD (the `◉` floating button + topology console). So once the flag was set on any technical dashboard, the overlay followed the operator onto settings. Removed `<DebugOverlay>` from that one settings entry; every technical dashboard (Workers, Raw Logs, Gyroscope, Request Stream, Performance, the standalone Aggregator status page) keeps its overlay.
- **Request Log, Error Log, and Gyroscope views no longer degrade at high event rates.** All three view nodes did O(n)-per-event work, mirroring the substrate Raw Logs fix. `RequestLogViewNode` and `PerfErrorsViewNode` did `entries.unshift()` (re-indexing the whole buffer — cap 1,000 / 5,000 — on every row) plus a per-row `completedHistory` filter+reduce over the full 10s window; their buffers are now fixed **ring buffers** (O(1) append + cap-drop, no shift/concat/truncation), exposing `entriesCount` + `entryAt(i)` for windowed reads, and requests/errors-per-second is tracked with bounded per-second buckets + a running total. `GyroscopeViewNode`'s per-message path was already O(1) (a Map upsert), but its `snapshot()`-tick RPS used the same per-tick `completedHistory` filter+reduce; it now uses the same bucketed window, while still expiring every tick (including idle ticks with no completions) so `rps` decays to 0 once completions stop. Newest-first order, caps, pause/clear, and the rps/rps-decay values are unchanged.

## [0.11.0] - 2026-06-07

### Added

- **Cache warmer runs as a JobWorker job, not wp-cron.** `Cache_Warmer_Tick_Node` (a `Timer_Node`) self-starts its timer in `arguments()` (so `make_node Cache_Warmer_Tick cache-warmer:tick` — or `… <interval>` for a custom period — arms it) and, hitchhiking `_router`'s ~5s heartbeat inside a long-lived worker (immune to wp-cron contention), enqueues a `cache_warmer` job every `INTERVAL_SECONDS` (60) by emitting a `TM_STRUCT` job Message to its `target` through the normal node-graph pipeline rather than writing to `Log_Manager` directly. The `cache_warmer` handler — registered on `newspack_nodes/job_handlers` via `Cache_Warmer_Tick_Node::init()` from the worker-runtime bootstrap — runs the drop-in's single-flight loopback (`Cache_Warmer::run_tick()`) inside the JobWorker, so the blocking warm render is isolated from the tick's loop. The handler drops any job that sat in the queue `>= INTERVAL_SECONDS` (a newer tick is already coming); there is no uniform JobWorker stale-drop — each handler enforces its own age, like `Remote_Manager::STALE_THRESHOLD`. Add to a topology with one line (`make_node Cache_Warmer_Tick cache-warmer:tick`); no `wp cron` scheduling.
- **Refresh-ahead cache warmer.** A self-contained mu-plugin drop-in (`mu-plugins/01-newspack-cache-warmer.php`, namespace `Newspack_Cache_Warmer`, shipped as a release asset alongside the profiler) keeps the homepage's caches hot out-of-band so no visitor pays the cold render. Its `eln_cache_warmer_tick` cron — which you schedule manually where you want it to run (`wp cron event schedule eln_cache_warmer_tick now eln_cache_warmer_minute`); the drop-in registers both the handler (so the event is runnable) and its own `eln_cache_warmer_minute` 60s recurrence via `cron_schedules` (so scheduling never depends on another plugin's interval being loaded) — fires a secret-gated loopback at the homepage. On that loopback the drop-in swaps `$GLOBALS['wp_object_cache']` for a cold-read / write-through decorator over the configured groups (`newspack_blocks`, `transient`, `site-transient`; filterable via `eln_cache_warmer_cold_groups`), forcing the block-HTML cache (and the Jetpack top-posts transient) to rebuild fresh into live memcached — so visitors are served warm block HTML and never trigger the per-block Elasticsearch queries that cause the 5–6s spikes (there is no ES result cache; the block-HTML cache is the sole gate on ES). The warm render tags itself (`$_SERVER['NEWSPACK_NODES_WORKER_TYPE']='cache-warmer'`) so it's excluded from timing stats, holds a single-flight lock, and disables the Password Protected plugin for its own (secret-gated) render so the loopback reaches the real homepage instead of the login page (real unauthenticated visitors are still gated before any cached HTML is served). The loopback verifies TLS by default (opt out for self-signed dev certs via `eln_cache_warmer_sslverify`). No host gate and no auto-scheduling — the drop-in does nothing until its cron is scheduled and a request carries the matching secret. Two operator scripts ship in `scripts/` (zip-excluded): `schedule-cache-warmer.sh` (idempotently schedules the tick at the minute recurrence) and `unschedule-cache-warmer.sh` (deletes the event and removes the secret option + lock transient); both pass extra args through to `wp`.

### Changed

- **Internal elegance refactor (behavior-preserving).** Named `BYTES_PER_MB`/`MAX_BUCKETS` and reused `MAX_PAYLOAD_SCAN_LENGTH` for bare literals, extracted `Log_Manager::emit_orphaned_complete()` (deduped two identical orphan-drain loops), and routed `message()` URL redaction through the existing `redact_url()` helper. No behavior change; full PHPUnit suite green (1686), PHPStan + PHPCS clean.

- **PHPStan raised to level 10** (`phpstan.neon.dist` `level` 9 → 10), all 274 errors cleared. Level 10 stops treating `mixed` leniently; fixes narrow each previously-untyped value with real types, `is_*` guards, and coercion casts. One shared stub was added — `.phpstan/stubs/getrusage.stub.php` declares PHP's documented `getrusage()` return shape (the keys are optional so the caller's `$r['ru_…'] ?? 0` reads stay valid) — clearing the `Log_Manager::log_resources()` arithmetic/`sprintf` errors at the root. A multi-agent review (adversarial verify) caught that the fan-out had relabelled `Server_Registry::get_all()`'s return key type as `array<string,…>` (a lie — PHP coerces numeric-string array keys to int, so integer keys genuinely occur) and deleted the `is_string( $server_id )` guards in `Remote_Manager`; the key type is restored to the honest `array-key` (on `get_all()`/`get_servers()`/`get_enabled()`) and the `Remote_Manager` health-check / settings-sync loops normalize the key with `(string)` (behind an `is_scalar` guard where the source value is `mixed`) so a server's real identity reaches `get()`/HMAC/logging. Tracking that down surfaced a real latent bug, fixed below. A subsequent xhigh `/code-review` also caught two copy-on-write performance regressions the narrowing had introduced — `Request_Builder_Node::push_stack`/`pop_stack` and `Reqgrep_Command::append_to_state` were refactored from in-place mutation to copy-into-local + write-back, which COW-duplicated the entire stack/profiles/lines array on every push/pop/append (O(n²) on the profiler and firehose-tail hot paths); restored to in-place mutation via property references. Behavior-preserving for string ids; full PHPUnit suite green (1691), no new `@phpstan-ignore`.
- **PHPStan raised to level 9 + phpstan-strict-rules adopted** (`level` 8 → 9; strict-rules added as a dev dependency, the full set EXCEPT the WordPress/node-runtime idiom-fighters — `empty()`, truthy conditionals, short ternary, `noVariableVariables` (the cache-warmer decorator's dynamic delegation to `WP_Object_Cache`), and `checkDynamicProperties`). All mixed-access + strict violations cleared with behavior-preserving narrowing plus redundant-cast removal and explicit `array_filter` callbacks. `Server_Registry::get_all()`'s return key type is corrected to `array-key` (PHP coerces numeric server-id keys to int, so a `is_string()` guard on the keys is meaningful, not dead). Behavior-preserving; full PHPUnit suite green (1690).
- **PHPStan raised to level 8** (`phpstan.neon.dist` `level` 7 → 8), all nullsafety errors cleared. `Servers_CI_Node::public_shape()` got `array<string, mixed>` PHPDoc; `Remote_Source_Node::ensure_multi()`/`remove_node()` capture `curl_multi_init()`/`$this->multi` into a local before the intervening `$this`-passing `register_curl_handle`/`unregister_curl_handle` calls (PHPStan invalidates property-narrowing across those); and `Request_Builder_Node::$flight` + `Reqgrep_Command::$inflight` — both unconditionally set in the constructor / run-setup before any guarded method runs — gained `require_*`-style assert-non-null getters (matching the existing `require_registry()` pattern) routed through their call sites. No runtime-path changes; the new throws are unreachable in normal operation. Behavior-preserving; full PHPUnit suite green (1690), phpcs clean.
- **Flame graphs prune negligible frames before rendering.** `FlameGraph` now runs the incoming tree through `pruneFlameGraph` (a pure, exported helper) before handing it to d3-flame-graph: frames smaller than 0.1% of the total (root) time are dropped — since a child's value never exceeds its parent's, a frame below the cutoff takes its whole subtree with it — and if the result still exceeds 1000 nodes the cutoff is raised to keep only the largest 1000. This clears the long tail of sub-pixel slivers that cluttered deep graphs and bounds DOM/render cost. The root is always kept and the input tree is not mutated.
- **Helper-created sibling nodes follow the same name + patron + interpreter-sink discipline (Phase 2–3, PHP).** The plumbing nodes that application helpers/nodes create — `Job_Intake`'s write Partition, `Stream_Merger_Node`'s offsetlog Partition, `Request_Builder_Node`'s `Flight` sibling, `Flame_Builder_Node`'s `Auto_Tuner`, the `Performance_CI` request-scope scratch Partitions, and `Reqgrep_Command`'s read Partition — are now named, `patron()`-linked so `dump_metadata` hides them from the canvas (self-patron where the owner isn't a `Node`), and sunk into the in-scope `_command_interpreter` when they have no specific sink of their own (skipped when none is in scope). `Log_Manager` now uses the canonical bare name `firehose:topic` and REUSES an existing one when present — the aggregator topology's `make_node Topic firehose:topic`, or a concurrently-suspended parent job context's — so there is exactly one firehose Topic per process (every context shares the N-partition writer, which self-routes by `KEY=request_id`); this also collapses the aggregator's prior two-Topics-over-one-`firehose.log` into one. `Stream_Merger_Node::remove_node()` now tears down its named offsetlog (+ its `:config`) so a removed merger doesn't leak it in `Core`. The dead `create*` view-node factories across all dashboards are removed (built via `make_node` / `registerNodeClasses`; node-unit tests construct the classes directly). Behavior-preserving; full suite green.
- **Dashboard graph nodes are created through the interpreter's `make_node`, not bare `new` (matches the `newspack-nodes` Phase 1 sweep).** Every dashboard graph-build hook (`performance-dashboards`, `performance-request-log`, `performance-gyroscope`, `performance-logger`, `event-aggregator`, `aggregator-admin`) now builds `SseIn`/`HttpOut`/`Heartbeat` and its own view/command nodes via `interpreter.makeNode( 'ShellName', name, args )` (which names + sinks them). The dashboard-specific classes (`Performance*`, `PerfErrorsView`, `RequestLogView`, `GyroscopeView`, `HookCatalogView`, `AggregatorView`, `ServersView`) are registered into the interpreter's `includeNodes` via `registerNodeClasses` in a per-dashboard `nodes/register.js` (imported by the hook + bundle entry). Mount timing unchanged (the hooks keep their `useEffect` `mountExospine`). The `viewName` (and `maxEntries`) defaults moved into the node constructors so a no-arg `make_node` construction preserves them. Behavior-preserving; all wiring preserved. Full JS suite green.
- **`log_urls` and `skip_urls` now share one matching scheme — a prefix match against the request path with a `?` terminator.** The query string is stripped and a `?` is appended to the path, then patterns prefix-match it; because the path can't otherwise contain `?`, a pattern ending in `?` becomes an EXACT match while one without is a plain PREFIX. So `log_urls => ['/?']` logs only the home page, `['/news?']` logs only `/news`, and `['/news']` logs anything under `/news` — and `skip_urls` behaves identically. Both filters compile through one helper (`Log_Manager::compile_url_filter`) to a start-anchored, grouped `/^(?:a|b)/i`. This replaces the previous split behavior (`skip_urls` matched as a substring anywhere; `log_urls` was an exact path match). Note `skip_urls` is now anchored to the start of the path rather than matched anywhere. Six `LogManagerTest` cases pin the prefix, trailing-`?` exact, home-page, query-string stripping, per-alternative grouping, and the shared skip/log scheme.
- **Service interpreters take normal commands with arguments — the request-side command `payload` field is gone (matches the `newspack-nodes` substrate).** Every `App\*_CI` verb parses its input from the `arguments` string via the substrate's `Command_Args` grammar (positional required args, `--key=value` options, bare `--key` flags, comma-lists, quoted values) instead of a structured `payload` slot: `Performance` (overview / urls / url_detail / request_search / request_detail / hooks_configure / config_update / settings_update / request_log_*), `Servers` (`get`/`delete`/`test <id>`, `add`/`update <id> --url=… --enabled=… --logs=…`), `Settings.update` (`--<key>=<int>` partial), and the nullary read verbs. The hub→spoke forwarder (`Remote_Manager` / `Remote_Source_Node`) builds the same argument string the spoke verb parses — settings-sync → `--<short>=<value>`, perf → `--option=<opt> --value=<v>`, heartbeat → positional `<slot> <ttl> <partition>` — via `Command_Args::format`. JS dashboard callers build their args with `formatCommandArgs`. Behavior-preserving; full suite green. Requires a `newspack-nodes` substrate that ships `Command_Args`.
- **PHPStan raised to level 7.** Builds on the level-6 value-type work: the few unguarded builtin returns are made safe — `filemtime()` enqueue versions cast to `string`, `wp_json_encode()` results feeding `esc_attr()`/`strpos()`/`explode()` coalesce to `''`, `current_filter()` coalesces to `''`, `Hook_Categorizer` guards a failed config read (behind a testable file-read seam) and returns its documented default, and `LRU_Cache` restore casts its bucket index to `int`. Behavior-preserving; a new test covers the unreadable-config path. Full suite green.
- **PHPStan raised to level 6.** The static-analysis gate now enforces value types on every iterable (`array<…>`); all method/parameter/return/property arrays carry explicit shapes. Substrate-wide this is PHPDoc-only (the 7-field positional Message as `array<int, mixed>`, stats/flame accumulator maps as `array<string, mixed>`, hook-name lists as `array<int, int|string>`). No runtime behavior changes.

### Removed

- **Confirmed-dead code surfaced by a reachability audit** (verified against the service-CI verb tables, REST/CLI, WP hooks, dynamic dispatch, and the sibling plugins). No behavior change; full suite green (1675):
  - `Flame_Builder_Node::stats_count()` and `Request_Builder_Node::cache_size()` — public accessors with no production caller (the `GET_STATS` / `GET_CACHE` verbs inline their own count loop). The tests that used them now assert through those production verbs.
  - `Admin::sanitize_aggregator_servers()` — no longer a `register_setting` sanitize callback; `aggregator_servers` is written only by `Server_Registry` CRUD.
  - `Hook_Categorizer::get_color()` / `categorize_many()` — no caller (production uses `categorize()` / `get_categories()`).
  - `LRU_Cache::is_empty()` and `Job_Intake::get_partition()` — unused accessors.
  - The duplicate `DASHBOARD_REFRESH_OPTIONS` export in `src/performance-gyroscope/constants.js` (and its test) — a verbatim copy of the live `performance-dashboards` constant; gyroscope imports only `INFLIGHT_REFRESH_OPTIONS`.
  - `Config::coerce_option_value()` and the read-time overlay coercion — the application's `array_strings` options are stored in their typed array shape at write time, so the per-request read no longer re-coerces (the substrate `newspack-nodes` makes the matching write-time fix for `memcache_servers`).

### Fixed

- **`Cache_Warmer_Tick_Node`'s numeric arg now sets the warm-enqueue interval in seconds.** It was a
  double no-op: `arguments()` passed the value to `set_timer( (int) $args )`, but that takes
  MILLISECONDS, so `'30'` armed a 30ms timer (and silently swapped the efficient `_router` heartbeat
  hitchhike for a busy `Event_Framework` slot); and `fire()` debounced on the hardcoded const
  (`INTERVAL_SECONDS = 60`), so the arg could never change the warm cadence. The numeric branch now
  keeps the router hitchhike and sets a per-instance `interval_seconds`, which drives both the
  `fire()` debounce and the value threaded into the job (`parameters['interval']`). `handle_job()`
  reads that threaded interval for its stale-drop (const fallback for old/in-flight jobs), fixing
  the real bug where an interval > 60 would wrongly drop valid jobs.
- **Numeric server ids are no longer silently destroyed at registration.** `Server_Registry::get_all()` merged config-file defaults with the WP-option servers via `array_merge()`, which **renumbers integer array keys** — and a purely-numeric server id (accepted by `is_valid_id()`'s `[a-zA-Z0-9_-]` pattern, and stored by PHP as an int key) is exactly such a key, so a server registered as e.g. `5` was silently reindexed to a positional `0`: its id was lost and it could collide with other numeric ids. Switched to the key-preserving union (`$option + $config_defaults`, identical option-wins precedence) so every id survives intact, and `Remote_Manager`'s health-check / settings-sync loops now `(string)`-normalize the key (instead of skipping non-string ones) so numeric-id servers are actually health-checked and synced under their real identity. Regression test added (`ServerRegistryTest::test_numeric_server_id_survives_get_all`).
- **Cache warmer renders anonymously so the warm-up actually populates the cache.** The authenticated loopback (so the edge cache forwards to PHP) made the warm render *logged-in*; Newspack only attaches its block-cache **write** filter for non-editors (`Newspack_Blocks_Caching::setup_block_caching` gates on `! is_user_logged_in() || ! current_user_can('edit_posts')`), so the warm render rebuilt the homepage but cached nothing — real visitors still hit cold seconds later. The warm request now forces `determine_current_user` to `0`: the `Authorization` header still gets it past the edge, but in WP it's anonymous, so block caching stays enabled and populates the anonymous cache real visitors read.
- **Cache warmer actually bypasses the caches now (cold-group prefix match + authenticated loopback).** Two reasons it was hitting caches instead of forcing a rebuild: (1) the cold-read decorator matched cache groups exactly, but Newspack's block cache splits into per-page/feed variants (`newspack_blocks-post-{ID}` for a static-Page homepage, `newspack_blocks-feed`), so cooling `newspack_blocks` missed the homepage's real group — now matched by **prefix** (`{group}` cools `{group}` and `{group}-…`). (2) The loopback was anonymous, so an edge/page cache served it a cached homepage and the render never reached PHP — it now sends an `Authorization: Basic` application-password header so the proxy forwards to PHP. The credential is read **in the job-worker process** (never written to `jobs.log`) from the `eln_cache_warmer_auth` option, **encrypted at rest** with libsodium keyed off `wp_salt('auth')` (mirrors `Server_Registry`; DB-only access can't decrypt), or from the `NEWSPACK_CACHE_WARMER_AUTH` wp-config constant (wins). The `schedule-cache-warmer.sh` script prompts for it (silently, via `wp eval` stdin → never a CLI arg) and `unschedule-cache-warmer.sh` clears it.
- **Hook instrumentation no longer wraps by-reference callbacks (fixes a PHP warning + a silently-lost mutation).** `App\Core::wrap_callbacks` replaces each hook callback with a timing closure that reads its args via `func_get_args()` + `call_user_func_array()` — both of which copy, so a callback declaring a by-reference parameter (e.g. VIP's `vip_es_disable_advanced_post_cache( &$query )` on `pre_get_posts`) was handed a value: `Argument #1 ($query) must be passed by reference, value given`, and the callback's mutation of `$query` was dropped. A new `callback_has_ref_param()` reflects each callback (handling every `$wp_filter` callback form — bare function, `Class::method` string, `[obj, method]`, `[Class, staticMethod]`, Closure, invokable) and `wrap_callbacks` now skips any that declares a `&` parameter, leaving it un-instrumented but correct; a reflection failure falls back to the prior wrap behavior. Adds an `AppCoreTest` case.
- The debug overlay / shared Newspack Nodes header now reports the **runtime** version instead of this plugin's. `NewspackNodesData.version` (read by the shared `Header` / debug overlay, which are part of the `newspack-nodes` runtime) is localized to `NEWSPACK_NODES_VERSION`, not `NEWSPACK_EVENT_LOGGER_NODES_VERSION` — so the overlay and the topology console no longer disagree (e.g. `0.10.0` vs `0.10.2`). No fallback: ELN loads after `newspack-nodes` defines the constant, and if it's absent the runtime ELN depends on isn't loaded. The script cache-bust version stays this plugin's.
- Added the missing `Author: Automattic` plugin header so the Plugins screen shows "by Automattic" (matching Newspack Nodes).
- The three remote-aggregation sanitize callbacks (`sanitize_remote_num_segments` / `_segment_size` / `_max_lifespan`) again accept `null`: their parameter is typed `int|string|null` and the `null === $value` guard is restored, so a WordPress `sanitize_callback` invoked with `null` (unset option) returns `''` instead of raising a `TypeError`.

## [0.10.0] - 2026-05-31

### Changed

- The command interpreter is spelled `interpreter` throughout (variables, comments, docs); the `mountExospine()` return key is `interpreter`; service-CI `*_CI_Node` identifiers keep `CI`. Node subclasses carry a `_Node` suffix with `class-*-node.php` / `*-node.js` filenames.
- JS view/command node classes declare `accepts_fill` / `has_target` in `nodeSchema()` (view nodes `has_target: false`; the performance command node `accepts_fill: false`) so the canvas draws the right ports.
- **Removed every `eslint-disable` directive from the JS; the code now lints clean without suppressions.** Same config posture as the `newspack-nodes` sibling (kept in lockstep): `no-bitwise` off (the Message `TYPE` is a bitmask), `no-console` allows `warn`/`error`, `no-unused-vars` honors `^_`, and a `scripts/**/*.mjs` override (Node globals, console, jsdoc). Declared `react`/`react-dom` as devDependencies — the test renderer imports them directly (they're transitive deps of `@wordpress/element`), which clears the `import/no-extraneous-dependencies` suppressions across the test suite.

### Fixed

- **Aggregator hub crash: `Stream_Merger_Node` and `Health_Check_Tick_Node` are now `Timer_Node` subclasses.** The `newspack-nodes` v0.10.0 substrate changed `Router::notify_timer()` to call each TIMER registrant's `fire_cb()` DIRECTLY (no TM_INFO message). These two nodes still hand-registered on the Router TIMER (`$router->register('TIMER', …)`) and ran their tick from a `fill()` TM_INFO/`KEY=TIMER` branch — so on every heartbeat the worker drain loop fatally errored `Call to undefined method …::fire_cb()`, taking the whole aggregator worker down. They now `extends Timer_Node`, register via `set_timer()` (router-hitchhike, with automatic TIMER-unregister on `remove_node()`), and run their periodic work in a `fire()` override — `Stream_Merger`'s `tick()` and `Health_Check_Tick`'s `maybe_enqueue()` renamed to `fire()`, the dead `fill()`-TIMER branches removed. The JS dashboard hooks' `HeartbeatNode` was migrated the same way (`router.register('TIMER', …closure)` → `heartbeat.setTimer()`). Requires `newspack-nodes` >= 0.10.0.
- **The debug overlay's "Reset Graph" now actually rebuilds the graph on all six dashboards.** The six dashboard graph hooks (`useRequestLogGraph`, `useErrorLogGraph`, `useGyroscopeGraph`, `useAggregatorStatusGraph`, `useAggregatorAdminGraph`, `usePerformanceGraph`) called `mountExospine()` bare, so `Core.reinit` stayed null and the overlay's Reset Graph silently no-op'd. They now build through `mountExospine( build )`: the substrate snapshots `Core` around the build callback so `reinit()` tears down + rebuilds exactly the build-registered nodes (the `_command_interpreter`/`_router` backbone survives), and a monotonic `buildCount` bump re-subscribes each dashboard's `useNodeState` to the freshly-registered view node. Matches the `newspack-nodes` Raw Logs / Worker Status template. (`useHookCatalogGraph` is intentionally excluded — the performance-logger page renders no overlay.)
- **Reset Graph no longer strands an in-flight request.** Removing a view node that holds a `pending` Promise map (`servers:view`, `performance:view`) left the awaiting Promise unsettled, so a Reset-Graph (or unmount) mid-reply hung the caller — stuck "busy" buttons in the Configured-Servers admin, a stuck spinner on the Performance dashboard. `servers:view.removeNode()` now rejects its pending CRUD promises (so the caller learns the mutation didn't complete); `performance:view.removeNode()` resolves its read-only `resolveOnly` promises with `null` (the methods' canonical "no data" return, so no spurious error banner).
- **Reset Graph while paused no longer desyncs the stream indicator.** On the Request Log / Error Log dashboards the hook's `isPaused` survives a reinit, but the rebuilt view's constructor defaults `paused: false` — so the UI showed "live" while the connection effect (correctly) kept the SSE stream closed. The hooks now re-apply the surviving pause to the freshly-built view.
- **`ServersAdmin` "Remove server" uses an in-app confirm modal instead of `window.confirm`.** Built from the stable `@wordpress/components` `Modal` + `Button` (the idiom the settings modals already use), not the experimental `__experimentalConfirmDialog`. Confirm-to-remove / cancel-aborts behavior is preserved; removes the `no-alert` suppression.
- **`FlameGraph`'s D3 container is `role="presentation"`** (it owns only auxiliary mouse handlers; D3 builds the interactive SVG inside), dropping its `jsx-a11y/no-static-element-interactions` suppression.

### Removed

- **`Events_CI::recent` verb removed (dead code).** No consumer ever called it — no dashboard JS, no topology, no other PHP caller; `wp nodes reqgrep --recent` walks firehose segments directly via `get_segments()`/`read_at()`, not the verb. It was also the only consumer of the substrate Partition's default-binary `.idx`, which `newspack-nodes` removed in the same change. The `stats` verb (the live event-dashboards surface) is unchanged.

## [0.9.1] - 2026-05-29

### Fixed

- **Performance URL table "Min" column no longer renders `9,223,372,036,854,775,807` (PHP_INT_MAX) for untimed-only URLs.** URLs whose requests carry no timing samples (worker requests, timed-out requests: `count > 0`, `timed_count === 0`) end their per-URL bucket with the `min_ms = PHP_INT_MAX` sentinel. Two mismatched sentinels (pending side `PHP_INT_MAX`, persisted side `0`) let the sentinel poison the persisted hourly URL index in memcache, and the dashboard surfaced it verbatim. Fixed at two layers, both keyed off `timed_count`: the flame-builder URL-index merge folds `min_ms` only from buckets with `timed_count > 0` (keeping the persisted value at 0 for untimed-only URLs and never letting the sentinel reach memcache); and the performance-CI read path now mirrors the write path — it folds `min_ms` only from buckets with `timed_count > 0`, so an untimed-only bucket (carrying `min_ms` 0 or a poisoned `PHP_INT_MAX`) never clamps the merged minimum across partitions/buckets and any already-poisoned memcache entries heal to 0 at display immediately, before TTL/salt-rotation clears them. Mixed timed + untimed URLs still report the real timed minimum.

## [0.9.0] - 2026-05-29

### Fixed

- **`<eln:is_hub>` and `<eln:significant_events_csv>` no longer resolve to null.** The `<eln:…>` resolver closure listed both keys as owned but resolved them via `Config::load_config()[$key] ?? null` — neither is a real Config key. `$config['is_hub']` was always null, so `cmd flame-builder:config set_is_hub <eln:is_hub>` in `topologies/request-workers.tsl` always set the empty string, and the flame builder's CSV-of-significant-events was always empty. Extracted the resolver to a public static `Config::resolve_eln_token($key)` shared by the production bootstrap AND `tests/bootstrap.php` (no drift): `is_hub` now derives `(bool) ! empty( $config['enable_aggregator'] )`; `significant_events_csv` imploded from `$config['significant_events']` (with an `is_array` guard against malformed values).

### Changed

- **All three SSE dashboards' chains collapsed to `_sse → <dash>:view`.** `requestlog`, `gyroscope`, and `perferrors` each had a `_sse → :route → :transform → :view` chain. The route nodes were dead in every one — they checked `KEY === 'connection'` but the substrate's `SseConnector` uses `KEY === 'connected'` AND snoops it off before routing, so the control-target branch was unreachable. The transforms did real shape-mapping (URL/UA clipping for requestlog; KEY-shape multiplexing for gyroscope's `inflight` vs `complete`; rid promotion + m-clipping for perferrors), but the info was all in the envelope and could be inlined into each view's `fill()`. Eighteen files deleted across the three dashboards (6 per dashboard: route + transform + transform-line helper + each one's test). Each hook now mounts just `_sse + _http + _heartbeat + <dash>:view`. Same architectural mistake I made in the v0.7.0/v0.8.0 substrate-I/O migrations (not "inherited from a template"); the sibling Raw Logs dashboard in `newspack-nodes` collapsed in lockstep.

### Tests

- **Three smoke-shaped `SettingsSyncTest` tests strengthened** from `$this->assertTrue( true )` to full queued-job envelope assertions. Each now isolates the write via `make_temp_dir()` + `use_base_dir()`, walks `{$base_dir}/logs/jobintake.log/p*/*.log` via a new `read_jobintake_envelopes()` helper (mirrors `JobIntakeTest::read_all_jobintake_lines()`), unpacks the Tachikoma Messages, and asserts on the full envelope: exactly-one-queued, `k='job'` + `handler='remote_manager'`, `parameters.action='sync_setting'`, the resolved option name (REMAP applied for `SYNCED_OPTIONS` vs verbatim for `PERF_TUNING_OPTIONS`), `parameters.endpoint===Settings_Sync::ENDPOINT` vs `PERF_ENDPOINT` based on which list the option lives in, `parameters.value` matches defaults-substitution-resolved value vs raw forwarded value, `parameters.queued_at` is int. Verified the assertions are real via a sanity-check mutation. The three tests had been correctly minimal under the v0.5.0-retired `enable_workers` gate; the gate's removal made them no longer assert anything meaningful.

### Removed

- **`Performance_Controller_Base` + its test deleted.** Defined but used only by its own test — no production CI ever extended it. The entire `includes/rest/` directory is now gone. `composer dump-autoload -o` regenerates the classmap. `tests/integration/M2BootstrapTest.php` gains a regression guard so a future revert would fail.
- **Stale `enable_workers` references stripped from test fixtures.** The option was retired in v0.5.0; 7 test config fixtures + 3 `SettingsSyncTest` setup blocks (which set `$GLOBALS['_wp_options']['newspack_nodes_enable_workers'] = '1'` expecting it to gate the dispatch path) were dead. A new `tests/unit/RetiredConfigKeysTest.php` asserts no fixture references retired keys (extensible as more get retired).
- **Stale doc-comments fixed**: `class-health-check-tick.php`'s `maybe_enqueue()` docblock claimed it gates on `enable_aggregator` strictly true (the body has no such check); `class-auto-tuner.php` referenced `PerfSettingsController` which was deleted in the service-CI cutover (updated to point at the `Performance_CI_Node::settings_update` verb).

### Changed (legacy CIs migrated to schema-driven dispatch)

- **`Discovery_CI_Node`, `Logger_CI_Node`, `Status_CI_Node`** migrated from `extends Command_Interpreter_Node` + in-constructor `$this->commands([...])` to `extends Service_CI_Node` + `node_schema()['commands'][]` with inline `'handler' => static fn ...` closures. Handler bodies are byte-for-byte the legacy closures — only dispatch wiring changed. Read-only verbs keep their no-auth status. `AppNodeSchemaCoverageTest` now covers all 8 application CIs uniformly (the three migrated ones declare their own `node_schema()` and are auto-scooped by the classmap walk).

### Docs

- **Three rounds of doc audit** against the v0.5 → v0.8 application + dashboard cutover (`enable_workers` retirement, `SSEControllerBase` deletion, every dashboard onto substrate `_http`/`_sse`/`_heartbeat` with the canonical pending-Map view contract). AGENTS.md / API.md / ARCHITECTURE.md / README.md and all three `.claude/skills/*/SKILL.md` files audited against current code. The biggest catches: `event-logger-nodes-workflow/SKILL.md`'s `enable_aggregator` polarity was REVERSED (it said "Default ON; OFF only when explicitly 0" — actual default is OFF, hubs opt in); `event-logger-nodes-debugging/SKILL.md`'s SSE section described `SSEControllerBase` as live (it was deleted in M6.10); API.md's per-CI verb tables had specific shape errors (`raw-logs` verbs misnamed, `workers` 5th verb misnamed, `topologies` missing `connect_worker_input`, `Performance_Controller_Base` "shared helper" framing in multiple files when no CI uses it).

## [0.8.1] - 2026-05-28

### Tests

- **Two under-80% files lifted above the coverage floor.** `aggregator-admin/index.js` (0% → 100%) via mount-entrypoint tests in the shared `mount-entrypoints` suite (verifies mount-on-container + no-op-without-container). `performance-request-log/RequestStream.js` (78.3% → 97.5%) via 7 real-branch tests covering `formatTime` falsy-timestamp, `toggleColumn` add/remove, rAF `lastEventTime` propagation, `handleScroll` save/restore branches, the rAF scroll-position maintain branch, and the rAF offset snap-to-zero threshold. All exercise observable DOM/state changes through real scroll/click events — no mock-only assertions. Two architecturally-unreachable defensive branches left uncovered (StreamRow switch default; handleScroll `isAdjustingScrollRef` early-return — both flagged with rationales).

### Notes

- CI workflow infrastructure changes only — no runtime behavior change in this plugin's own code. The debug overlay it inlines from `@newspack-nodes/runtime` picks up that runtime's v0.8.1 fixes (atomic `sync-shared`, z-index bump, debug overlay drops dead `_uptime`).

## [0.8.0] - 2026-05-28

### Changed

- **All four remaining dashboards migrated onto the substrate I/O backbone.** Closes the dashboard-migration sweep started in v0.7.0: every dashboard now rides the substrate's `_http` / `_sse` / `_heartbeat` pattern. The legacy `getCommandClient` / `unwrapCommandResponse` / per-dashboard `*Command` / `*Stream` Node pattern is fully retired in this plugin.
- **`useAggregatorAdminGraph` (Configured Servers admin)** migrated to the substrate `_http` pattern. Drops `serversCommand`. Hook mounts spine + `_http` + `servers:view`. CRUD callbacks build TM_COMMANDs (TO=`_http/servers`, unique `message[ID]`) and fill into the CI; the view's `fill()` matches `message[ID]` against `pending` to resolve/reject the hook's Promise. Mutations chain a fire-and-forget re-list on success (replaces the legacy `window.location.reload()`). Also drops the now-dead `api.js` + its tests.
- **`useHookCatalogGraph` (Performance Logger settings)** migrated to the substrate `_http` pattern. Drops `hookCatalogCommand`. Hook fires one `hooks_registered` TM_COMMAND on `isOpen` transition. On rejection the hook routes a synthetic empty-catalog reply THROUGH the interpreter (canonical path — router peels TO=`hookcatalog:view` and delivers) so the substrate boundary stays clean.
- **`useGyroscopeGraph`** migrated to the substrate `_sse` pattern. Drops `gyroscopeStream`. Hook mounts spine + `_sse` + `_http` + `_heartbeat` + the existing `gyroscope:route`/`transform`/`view` chain. `_sse` subscribes to `gyroscope`; `_heartbeat.target = '_http/workers'`. Page-visibility drives `sse.start()` / `sse.close()` + `heartbeat.clearSlot()` on close. The connection-status banner (`gyroscopeView.connectionError`) stays dormant — `_sse` / SseConnector emits `connected` on open but has no parallel error signal, matching the v0.7.0 request-log dashboard behavior.
- **`usePerformanceGraph` (Performance Dashboards)** migrated to the substrate `_http` pattern. The `performance:command` Node is kept as the **slice-tagging command-builder** (emits `{action:'loading', slice}` controls before each TM_COMMAND so the view treats different verb slices independently); it no longer owns the transport. TM_COMMANDs go through the CI (TO=`_http/performance`, FROM=`performance:view`); the reply pivots TO=FROM; the view's `fill()` matches `message[ID]` against `pending` to apply the result to its slice (or resolve a `resolveOnly` Promise for ad-hoc lookups). `performance:view` gains the canonical pending-Map gate + `_errorMessage()` helper. A pre-mount `client.send` fallback for `request_search` is retained for `?request=` deep-link timing (the `useUrlNavigation` mount fires before `usePerformanceGraph`'s mount populates the command ref).
- **`useErrorLogGraph` (Performance Error Log)** migrated to the substrate `_sse` pattern. Drops `perfErrorsStream`. Hook mounts spine + `_sse` + `_http` + `_heartbeat` + the existing `perferrors:route`/`transform`/`view` chain.
- **The canonical view contract from `servers:view` is now consistent across all command-driven dashboards:** pending-matched TM_ERROR rejects the Promise without polluting global view.error (per-call surface is the caller's catch — a row-level snackbar or in-form notice, not a table-wide banner); `_errorMessage()` helper handles both string and structured `{ message }` TM_ERROR payloads; `updateServer` and similar id+partial verbs spread the partial FIRST so the positional id wins (`{ ...partial, id }`).

## [0.7.0] - 2026-05-28

### Changed

- **Request Log dashboard piloted onto the substrate's `HttpOut` + `SseIn` + `Heartbeat` triad.** The bespoke `requestlog:stream` Node is deleted; `useRequestLogGraph` mounts the runtime spine (`_sse`, `_http`, `_heartbeat`, `_completion`, `_metadata`, `_uptime`, `_cwd`, `_output`) + `requestlog:route` / `requestlog:transform` / `requestlog:view`. `_sse` subscribes to `completed`; `_heartbeat` keeps the slot alive against `_http/workers` (request-scope path that bypasses the SSE demux); `requestlog:route` classifies on the connection-status marker. The pattern is Tachikoma rule #2: every node sinks into `_command_interpreter → _router` and steers flow through `target`. This is the first of seven dashboards to migrate; remaining dashboards (aggregator-admin, performance-dashboards, performance-gyroscope, performance-logger, and the Nodes-side useRawLogsGraph / useWorkerStatusGraph) are planned for the next release.
- **Event Aggregator dashboard migrated to the same pattern (poll-only shape).** The bespoke `aggregator:poll` Node is deleted; `useAggregatorStatusGraph` mounts spine + `_http` + `aggregator:view`. Hook owns the `setInterval` that fills a `TM_COMMAND` (FROM=`aggregator:view`, TO=`_http/aggregator`) into the interpreter; HttpOut POSTs; the server's reply routes back via the TO=FROM pivot. No SSE since there's no subscription. Template for the remaining poll-only dashboards.

### Fixed

- **`tests/run-coverage.sh` guards against running as root.** `Log_Manager` refuses to run as root (Atomic permission contract); the suite was silently producing 37 false `LogManagerTest` failures when invoked via `docker exec` (default root). The script now hard-fails with a usage hint (`docker exec -u bend …`) when `id -u` is 0, and cleans the actual test artifact dir `/tmp/event-logger-nodes-test` (the old line cleaned the wrong path).

## [0.6.0] - 2026-05-27

### Changed

- **`Aggregator_CI_Node` and `Servers_CI_Node` migrated to no-arg ctor + public-property dep injection.** Their `Server_Registry $registry` ctor dependency becomes a public nullable property assigned by the bootstrap (`$ci->registry = $registry;`) immediately after `make_node` returns the constructed instance. Last two app CIs with programmatic-dep positional ctors — they were the reason the substrate's `make_node` carried a ctor-param-count conditional through Tasks 7-10. With this change, every `make_node` call across both repos goes through the uniform Tachikoma sequence and the conditional is gone (substrate side).
- **App Node classes migrated to the Tachikoma idiom** (no-arg ctor + schema-driven `arguments()`): `Job_Worker_Node`, `Stream_Merger_Node`, `Remote_Source_Node`, `Request_Builder_Node`. Each declares `node_schema()['arguments']` with REAL defaults (class constants — `CACHE_FLUSH_INTERVAL`, `DEFAULT_BUCKET_SIZE`, etc., never placeholder strings) and overrides `arguments()` to chain `parent::arguments()` + re-normalize + re-derive, with the standard empty-string short-circuit. `Request_Builder_Node`'s `LRU_Cache` construction (which depends on `bucket_size`/`num_buckets`) moved into the override; `Stream_Merger_Node`'s owned `Health_Check_Tick_Node` sibling stays in the ctor (no positional-arg dependency). `Remote_Source_Node` adds a `configure()` setter for the production `Stream_Merger::add_remote()` callsite — middle-empty auth-token positional args can't survive whitespace tokenization, so production sets all 7 fields directly via `configure()` and writes a redacted summary to `$this->arguments` for `dump_config()`; the `arguments()` schema-walker path stays for tests / fully-populated TSL lines. Trivial cases (`Job_Router_Node`, `Request_Flight_Node`, `Auto_Tuner_Node`, `Health_Check_Tick_Node`, `Flame_Builder_Node`, `Events_CI_Node`, `Settings_CI_Node`, `Performance_CI_Node`) confirmed already aligned. `Aggregator_CI_Node` and `Servers_CI_Node` keep their positional `Server_Registry` ctor dependency (programmatic, not config) — they stay `category=Service` per `ServiceCiHandlerGuardTest`'s contract; the substrate's `make_node` ctor-count branch surfaces TSL-construction attempts as a clean `TypeError` ("can't TSL this"). Substrate's Task 8 closed Topic/Consumer/Tail/Log/Hook in lockstep.
- **Migrated to the substrate's Tachikoma-idiom `Topic_Node` / `Consumer_Node`** — `Log_Manager`'s Topic construction and the integration-test Consumer/Topic constructions now use `new Topic_Node(); $t->arguments("...")` / `new Consumer_Node(); $c->arguments("...")`. Mirror of the substrate's Task 8 change.
- **Migrated to the substrate's Tachikoma-idiom `Partition_Node` (no-arg ctor + `->arguments(...)`)** — every `new Partition_Node($base_dir, $partition, ...)` callsite in the app (Stream_Merger, JobIntake, Events_CI, Performance_CI's 6 chained-with-`->with_index()` forms, Reqgrep, plus tests) is now `new Partition_Node(); $p->arguments("...")`. Mirrors the substrate's Task 7 change. No behavior change to running graphs.
- **`node_schema()` field renamed `'ctor'` → `'arguments'`** to match the substrate's Tachikoma-parity rename. Every app Node (`Job_Worker`, `Stream_Merger`, `Request_Builder`, `Flame_Builder`, `Auto_Tuner`, `Job_Router`, `Job_Worker`, `Remote_Source`, `Health_Check_Tick`) and every app CI updated. Wire format unchanged.
- **`node_schema()` field renamed `'verbs'` → `'commands'`** to match the substrate's Tachikoma-parity rename. Every app Node (`Job_Worker`, `Stream_Merger`, `Request_Builder`, `Flame_Builder`, `Auto_Tuner`, `Job_Router`, `Remote_Source`, `Health_Check_Tick`) and every app CI (`Events_CI`, `Aggregator_CI`, `Servers_CI`, `Performance_CI`, `Settings_CI`) updated. Test fixtures updated. Wire format unchanged; same semantics, the misnomer dies.
- **Nodes migrated off the substrate's removed `mark_verb_invoked` recorder.** `Flame_Builder`, `Request_Builder`, and `Stream_Merger` now override `dump_config()` to emit their `cmd {node}:config set_X <value>` lines **from their own setter state** (only when non-default), matching the substrate's new pattern. The two verbs that were one-shot *actions* rather than config — `Stream_Merger`'s `load_remotes_from_registry` and `Health_Check_Tick`'s `start_periodic_tick` — are no longer verbs: they fire unconditionally from a lifecycle hook (`Stream_Merger::connect_node()` loads remotes once the sink+target are wired; the periodic tick rides the existing `name()` cascade). The corresponding `cmd …:config` lines were removed from `aggregator.tsl`.
- **Dashboards are being wired onto the newspack-nodes "exospine" (rule #2).** Each dashboard's JS-Node graph now clips onto the shared `mountExospine()` backbone (`_command_interpreter → _router`) imported from `@newspack-nodes/runtime`: every node sinks into the interpreter and steers flow purely with `target`/`TO` through the router — no bespoke `x.sink = <node>` chains, no `controlSink` side-channels. Node names moved from `name/leaf` to `name:leaf` (the router peels TO on `/`). Dashboards render identically; substrate-conformance only.
  - **Aggregator Status** (`aggregator:poll` → `aggregator:view`): poll-driven, no route node.
  - **Configured Servers** (`servers:command` → `servers:view`): command-driven, no route node.
  - **Hook Catalog** (`hookcatalog:command` → `hookcatalog:view`): command-driven, no route node.
  - **Gyroscope** (`gyroscope:stream` → `gyroscope:route` → `gyroscope:transform`/`gyroscope:view`): SSE-driven; the `controlSink` is replaced by a `gyroscope:route` classifier keyed on the stream-set `KEY='connection'` marker (data → transform, connection-status → view).
  - **Request Log** (`requestlog:stream` → `requestlog:route` → `requestlog:transform`/`requestlog:view`): SSE-driven; same `requestlog:route` classifier on the `KEY='connection'` marker. `pause`/`clear` stay hook-direct to the view.
  - **Error Log** (`perferrors:stream` → `perferrors:route` → `perferrors:transform`/`perferrors:view`): SSE-driven; `perferrors:route` classifier on the `KEY='connection'` marker; `pause`/`clear` hook-direct.
  - **Performance** (`performance:command` → `performance:view`): command-driven, no route node. (Error Log and Performance are separate admin pages; each mounts its own exospine.)

## [0.5.0] - 2026-05-27

### Fixed

- **`Stream_Merger_Node::remove_node()` now cascades its owned `Health_Check_Tick` sibling's `:config` CI.** It did a bare `Core::unregister_node()` on the child's name, leaving the child's auto-wired `{name}:health-check:config` interpreter orphaned in `Core` — a same-process name-recycle (in-process topology rebuild) would then collide on the orphaned name. Now calls `$this->health_check->remove_node()`, which cascade-unregisters the sibling CI. (Pre-existing leak — the child always had a CI — surfaced while reviewing the node_schema-handler migration.)

### Changed

- Migrated `Job_Worker_Node`, `Stream_Merger_Node`, `Request_Builder_Node`, `Flame_Builder_Node`, and `Health_Check_Tick_Node` to the substrate's node_schema-handler auto-wire pattern: each node's `:config` verb handlers now live in `node_schema()['verbs']` (one `handler` closure per entry) instead of a separate `config_verbs()` table + four lines of manual CI wiring (`new Command_Interpreter_Node` / `patron` / `commands` / `attach_interpreter`). The base `Node::__construct()` builds the sibling `{node}:config` CI from the handler-bearing verbs. `Job_Worker_Node` declares no config verbs, so it no longer gets a sibling CI (it never had operator-facing verbs). No behavior change to any existing `:config` verb.
- **Dropped plugin-owned topology curation.** `register_plugin()` is now called with just namespace + dir (no `names:` curation — the substrate catalogs every shipped `.tsl`). Removed the now-substrate-owned `topologies` key from the bundled config; `Status_CI` reports the substrate active set (`Bootstrap::get_topologies()`) instead of an ELN-config key. Per-deployment active topologies now live in the substrate (`newspack-nodes`) config's `topologies` key.
- **i18n infrastructure** (foundation for translating the dashboard UI): declared `@wordpress/i18n` as a dependency, enabled the `@wordpress/eslint-plugin` i18n ruleset (pinned to the `newspack-event-logger-nodes` text domain), added the `Text Domain` / `Domain Path` plugin headers and a `make-pot` script + `languages/` dir, and deleted the orphaned `eventAggregatorAdmin` `wp_localize_script` block (7 i18n strings with no JS reader — `ServersAdmin.js` hardcodes them inline; the live `NewspackNodesData` localize on the same handle is kept). (`build.mjs` already externalized `@wordpress/i18n → wp.i18n`.)
- Wrapped the dashboard UI strings for translation (`@wordpress/i18n` `__()`/`_n()`/`sprintf`, domain `newspack-event-logger-nodes`) across every dashboard — the Tier-1 streams/tables/modal (`LogEntriesTable`, `Inflight`, `RequestStream`, `ErrorLog`, `HookSelectorModal`) plus `ServersAdmin` (its inline status strings — formerly the deleted localize), the Performance dashboard and its Overview/UrlTable/UrlDetail/RequestDetail/chart components, `AggregatorStatus`, the settings modals, and the `constants.js` metric/breakdown option labels. `ConnectionBanner` consumers pass a translated `message`; `COLUMNS` label/tooltip values, category descriptions, and chart axis/legend labels are wrapped, while object keys, comparison/map values, and unit/glyph tokens stay raw. The translation template `languages/newspack-event-logger-nodes.pot` (314 strings) is generated by `npm run make-pot`.
- Adopted the shared `ConnectionBanner` (synced from newspack-nodes) across every dashboard. Error Log + Aggregator Status swapped their bespoke banners onto it; Request Log + Gyroscope gained the SSE reconnect surface (`onStatus`→`controlSink`→`connectionError`, mirroring Error Log) and render it. Removed the orphaned `.event-logger-error-log-error` and `.aggregator-status-error` SCSS rules. Every dashboard's connection/reconnect banner is now identical.
- Removed the silent `?? '/tmp/newspack-nodes'` fallbacks for `base_directory` in the app CIs (`events`, `performance`, `servers`) + `Job_Intake`. Every reader now resolves through the strict `Config::get_base_directory()`, which throws when `base_directory` is unconfigured — so a misconfigured base fails loudly instead of split-braining the firehose (writer on the real dir, readers silently defaulting to `/tmp/newspack-nodes`).
- **Performance Dashboard rebuilt on the `performance/command` → `performance/view` node graph, completing the dashboards-as-JS-node-graph rollout.** A new `usePerformanceGraph` hook mounts the graph and owns all data fetching (overview, URLs, URL detail, request detail, the refresh-interval cadence, the server-filter/breakdown re-fetch, the debounced URL search, and the selection-driven detail fetches); `PerformanceDashboard` now reads the published view model via `useNodeState('performance/view','view')` and derives its render-time slices (breakdown fan-out, sticky server names, sorted requests, req/s, filtered stats) instead of holding data state. Removed `usePerformanceApi` — its verbs + validators now live in `performanceCommand`, which gained an `onError` seam (preserving the global error toast), a returning `fetchUrlBreakdown`, and a loading emit gated to the initial fetch so background URL-detail refreshes stay silent. `?request=` deep links resolve through the command client directly so they still open on first paint. No user-visible behavior change.
- Added the `performance/command` (multi-verb command-out) + `performance/view` (sliced data model with the `urlDetail` incremental merge) nodes — the Performance Dashboard data layer. Not yet wired in; the orchestrator rework that reads from `performance/view` follows in a later change.
- Error Log reimplemented as a `perferrors/stream → transform → view` JS-node SSE graph (`useErrorLogGraph`); `ErrorLog` is now a thin consumer; the reconnect banner is preserved via a connection-status surface on the view node. No behavior change.
- **Performance Logger hook-catalog fetch reimplemented as a `hookcatalog/command → hookcatalog/view`
  JS-node graph (`useHookCatalogGraph`); `HookSelectorModal` is now a thin consumer.** The settings
  page is a form (almost all local UI state), so only its single networked surface — the
  `{to:'performance', verb:'hooks_registered'}` fetch the modal fired on open — was nodified:
  `hookcatalog/command` (command-out — emits a synchronous `loading` then a `catalog` with the
  unwrapped `hooks_by_category`, falling back to an empty map on failure; injectable command seam +
  `close()` cancel guard) → `hookcatalog/view` (`setState('view',{hooksByCategory,loading})`) — wired
  by `useHookCatalogGraph`, which fires the fetch on open (not on an interval) and reads the model on
  the modal's behalf. The modal's search / expand / select-all / recommended / category-toggle / Apply
  logic and all SCSS are unchanged. The form-state components (`TagInputField`,
  `CustomEventSelectorModal`, `index.js`) are untouched. No behavior change.
- **Configured Servers (aggregator-admin) rebuilt as a React node-graph view, replacing the
  PHP-rendered table + jQuery IIFE.** The only converted dashboard that wasn't already React — a
  full rewrite: `servers/command` (CRUD command-out — `list`/`add`/`update`/`delete`/`test` on the
  `servers` CI, injectable seam + `close()` guard) → `servers/view`
  (`setState('view',{servers,loading,error})`) — wired by `useAggregatorAdminGraph` (list on mount,
  each mutation awaits then re-`list()`s, `setViewReady`). `index.js` now `createRoot`-mounts a React
  `<ServersAdmin>` into the `#event-aggregator-servers` div that `configured_servers_callback` emits
  (the PHP no longer renders the rows). The table / add-form / validation / test-status reuse the
  exact `wp-list-table` markup + class names; the post-mutation `window.location.reload()` is
  replaced by a re-`list()`. Capability gating (page-level `manage_options` + the per-verb
  `require_manage_options` in `Servers_CI`) is unchanged. (Known project-wide gap, not specific to
  this change: the React dashboards hardcode English; an `@wordpress/i18n` sweep across all of them —
  plus deleting the now-orphaned `eventAggregatorAdmin` localize — is tracked separately.)
- **Aggregator Status dashboard reimplemented as a JS-Node graph + thin React view.** First
  command-poll conversion in ELN (mirrors Worker Status, not the SSE dashboards): `aggregator/poll`
  (command-out — sends `{to:'aggregator', verb:'status'}` on the interval, captures the reply's
  server `TIMESTAMP`, injectable command seam, `close()` cancel guard) → `aggregator/view` (reshapes
  the per-server status map → array + connected/total counts, `setState('view',…)`) — wired by
  `useAggregatorStatusGraph` (owns the poll interval — no page-visibility gating, as before — plus
  the `setViewReady` re-render trigger). `AggregatorStatus.js` is now a thin view:
  `useNodeState('aggregator/view','view')` + the 1s "ago" tick; the ServerCard / PartitionStatus
  render, refresh-interval select, error banner, and all SCSS are preserved.
- **Gyroscope (in-flight requests) dashboard reimplemented as a JS-Node graph + thin React
  view.** Same SSE pattern as Request Log: `gyroscope/stream` (SSE-in, `subscribe=gyroscope`,
  replacing the shared `useMessageStream`), `gyroscope/transform` (`transformGyroscopeLine`),
  `gyroscope/view` (the rid-keyed in-flight model — upsert with a complete-wins guard, the
  one-refresh-tick-then-reap expiry, and the 10s requests/sec window, all ported verbatim) —
  wired by `useGyroscopeGraph` (page-visibility pause, reset-on-reconnect, `setViewReady`).
  `Inflight.js` is now a thin view: `useNodeState('gyroscope/view','view')`, and the refresh
  tick reads the model's `snapshot(maxRows)` / `rps` off the node. The visualization (renderCell
  age/lag geometry, legend, column picker, 0-9 refresh shortcuts, "Xs ago" ticker) + SCSS are
  preserved.
- **Request Log dashboard reimplemented as a JS-Node graph + thin React view.** Following the
  Raw Logs / Worker Status reference (nodes repo), the live request-stream data flow moved out
  of React effects into a graph that imports the substrate runtime via `@newspack-nodes/runtime`
  — `requestlog/stream` (SSE-in: EventSource + slot-heartbeat poke + reconnect, replacing the
  shared `useMessageStream`), `requestlog/transform` (`transformCompletedLine`), `requestlog/view`
  (the row buffer + requests/sec model) — wired by `useRequestLogGraph` (page-visibility pause +
  the `setViewReady` re-render trigger). `RequestStream.js` is now a thin view: `useNodeState(
  'requestlog/view','view')` for the low-freq model, and the virtualized list reads the high-freq
  buffer (`node.entries`/`.rps`) off the node each rAF — preserving DOM, SCSS, columns,
  virtualization, smooth-scroll, filter, RPS, "Xs ago", and Clear. `jest.config.js` gains a
  `moduleNameMapper` deduping React / `@wordpress/element` to ELN's copy (the runtime's React
  hooks resolve from the sibling checkout; mirrors the production WP-global externalization).

### Added

- **Runtime guard for the `newspack-nodes` dependency (`Substrate_Guard`).** ELN now
  verifies — deferred to `plugins_loaded` (via `Substrate_Guard::boot()`), once the
  substrate has loaded — that it is present and at/above its minimum version (`0.4.0`)
  with the APIs it calls (`register_plugin`, `register_config_namespace`,
  `Service_CI_Node`, `Command_Interpreter_Node`) before touching any substrate symbol.
  The check MUST run deferred, not at ELN's file-load: ELN sorts alphabetically before
  `newspack-nodes`, so the runtime isn't loaded yet when ELN's plugin file executes —
  checking then always saw it "not active" and disabled the whole plugin (no request
  logging, dead dashboards, command auth failing closed on a null `Core::$memd`).
  When the substrate is genuinely missing or too old, ELN shows an admin notice and
  bails gracefully — instead of fatal-ing at the first missing-symbol call or binding
  silently against a stale runtime. This complements the existing
  `Requires Plugins: newspack-nodes` header (which WP <6.5 doesn't enforce and which
  can't catch a present-but-outdated substrate). The plugin's own autoloader stays
  registered so the `00-newspack-profiler` mu-plugin can still resolve `Log_Manager`.

### Changed

- **App service CIs declare their verbs via `node_schema()` (schema-driven dispatch).**
  The five application service CIs (`Events_CI`, `Settings_CI`, `Performance_CI`,
  `Aggregator_CI`, `Servers_CI`) migrated to the substrate's schema-driven command
  mechanism: each verb is declared once in `node_schema()['verbs']` (name/description/args
  + a `handler` closure), and `Service_CI_Node`'s constructor builds the dispatch table
  from it — so their bespoke `verb_table()`/`__construct()` command wiring is gone. The two
  registry-backed CIs (`Aggregator_CI`, `Servers_CI`) keep a constructor only to store the
  injected `Server_Registry`, which their handlers reach via `$self->registry`. Because
  they now publish a `Service`-category schema, the five CIs also appear in the topology
  console's class catalog and their verbs are Inspector-invokable (they were uncategorized
  and catalog-invisible before). Each arg-bearing verb declares its `args` (name/type/
  required/default, derived from what the handler reads) so the Inspector renders the right
  input fields — e.g. `servers add` requires `id`+`url`, `servers update` is an all-optional
  partial-update (no defaults that would silently re-write a field). Behavior-preserving for
  dispatch — requires the matching `newspack-nodes` build.

- **Reduced onto the substrate's `register_plugin` + namespaced config tokens.** The
  substrate stopped feeding topology `<config:…>` tokens from a single merged
  `Core::$config` array, so this plugin no longer merges substrate + app config to spawn
  workers. Its 6 app-specific tokens moved to an `<eln:…>` namespace
  (`is_hub`, `auto_disable_threshold`, `auto_protect_time_threshold`,
  `aggregator_require_https`, `aggregator_verify_ssl`, `significant_events_csv`),
  resolved by a small `eln` resolver registered with
  `Core::register_config_namespace()`; substrate-owned tokens (`logs_dir`, `offsets_dir`,
  `num_partitions`, `segment_size`, `num_segments`, `max_lifespan`) keep the `<config:…>`
  namespace. The bespoke `newspack_nodes/topologies` filter and `newspack_nodes/spawn_worker`
  handler are deleted in favor of `Topology_Registry::register_plugin( …, names: <curated
  list> )`; worker-runtime init (StreamMerger rewrite filter + RemoteManager) now runs from a
  `newspack_nodes/before_worker_spawn` listener. Behavior-preserving — requires the matching
  `newspack-nodes` build.

### Fixed

- **Restored read-time type coercion in the WP-option overlay (`Config::load_config`).** A prior simplification removed the per-key coercion, so an `array_strings` option stored as a newline string overlaid the config as a raw string (breaking the `foreach` consumers that need a list) and `int`/`float` options stored as numeric strings overlaid as strings. A minimal `coerce_option_value()` now splits the array type into a trimmed list and casts `int`/`float` (non-numeric → falls back to the file default), restoring the shape consumers expect. Per-element `sanitize_text_field` stays at write time (off the per-request read path), where it moved.
- **Profiler mu-plugin was silently inert after the `LogManager` → `Log_Manager` rename.**
  `mu-plugins/00-newspack-profiler.php` gated its `plugins_loaded` flush on
  `class_exists( '…\LogManager' )` and called `LogManager::instance()`, but the class is
  `Log_Manager` — so the guard was always false and the deferred plugin-load events
  (`{plugin} (start)` / `(complete)`, the firehose `process (start)` timing) were never
  flushed. Corrected the class references; a new test fires the profiler's hook and asserts
  it resolves `Log_Manager` so the rename can't silently break it again.

## [0.4.0] - 2026-05-23

### Changed

- **Registers its node namespace instead of per-class `register_class`; `.tsl`
  shell-names normalized.** The plugin now calls
  `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\' )`
  (+ the `App\` sub-namespace, since `request_graph_ready` `make_node`'s the
  service CIs by short name) in place of its 17 `register_class()` calls. The
  topology `.tsl` files use the normalized shell-names (`make_node FlameBuilder`
  → `make_node Flame_Builder`, `JobRouter` → `Job_Router`, `JobWorker` →
  `Job_Worker`, `RequestBuilder` → `Request_Builder`, `StreamMerger` →
  `Stream_Merger`). Requires the matching `newspack-nodes` build (prefix
  resolution lives in the substrate).
- **Internal: class names normalized to `Word_Word` + `_Node` (lockstep with
  newspack-nodes).** ELN's own classes are now `Word_Word` with ALL-CAPS acronyms
  and a `_Node` suffix on Node subclasses (`FlameBuilder` → `Flame_Builder_Node`,
  `StreamMerger` → `Stream_Merger_Node`, `LogManager` → `Log_Manager`, `LruCache`
  → `LRU_Cache`); every reference to the renamed substrate classes was updated to
  match (`extends Service_CI` → `Service_CI_Node`, `\Newspack_Nodes\CommandInterpreter`
  → `Command_Interpreter_Node`, …). Behavior-neutral: `register_class` shell-names
  + `.tsl` topologies unchanged. Requires the matching `newspack-nodes` build
  deployed alongside (the substrate classes are renamed there).
- **Synced shared `useMessageStream` hook: `workers/heartbeat` sends its slot
  args positionally.** Mirrors the canonical hook in `newspack-nodes`, whose
  `Workers_CI heartbeat` verb now reads positional `arguments` instead of a
  structured `payload`. Requires the matching `newspack-nodes` build deployed
  alongside (the heartbeat verb is substrate-side).

## [0.3.0] - 2026-05-22

### Changed

- **Caching gutted down to the single shared `Core::$memd` handle.** Deleted the
  plugin-local `Memcached_Cache` class, the `Cache_Interface` abstraction, and
  the `FakeMemcached` test class. Caching is now `\Newspack_Nodes\Core::$memd` (a
  raw `\Memcached`), built once at boot by
  `newspack_event_logger_nodes_init_memcached()` from the substrate's
  `memcache_servers` config and stashed on the substrate `Core`. `Stats_Store`
  and the memcache-backed service CIs (`Status_CI`, `Events_CI`, `Aggregator_CI`,
  `Performance_CI`) dropped their injected-cache constructor params and read
  `Core::$memd` directly (null-safe). `Sse_Slot_Pool::wire()` and the
  `newspack_nodes/workers_cache` filter both feed the substrate off `Core::$memd`.
  Tests set `Core::$memd` to the substrate's in-memory `\Memcached` double
  (`tests/Helpers/InMemoryMemcached.php`) instead of injecting a cache.
- **CI verbs return structured data, matching the substrate's de-double-encoded
  command protocol** (newspack-nodes ≥ Unreleased). Verbs return live PHP
  structures instead of `wp_json_encode`'d strings, and the synced
  `unwrapCommandResponse` reads the structured payload directly (single decode).
  Dashboards consume the structured response unchanged.
- **Cross-spoke `/command` senders speak the new wire.** `RemoteManager`,
  `RemoteSource`, and `Servers_CI` post a packed Message (structured `VALUE`) as
  `text/plain` and read the response payload with one decode. Hoisted
  `RemoteManager::COMMAND_CONTENT_TYPE` + `command_message_body()` to public so
  the same-plugin callers share one definition.
- **Adapted to the substrate In/Out rename + `TM_*` renumber.** Follows
  `Command_Controller`/`HTTP_Out` → `HTTP_In` and `Messages_Stream_Controller` →
  `SSE_Out`; reserved node names use `Node_Names`; the reply-convention tests
  assert `TM_STRUCT|TM_RESPONSE` (no `TM_REQUEST` echo).
- Moved `00-newspack-profiler.php` under `mu-plugins/`; synced shared JS.
- **`RequestFlight`** — hidden Timer-sibling of `RequestBuilder` that snapshots the
  in-flight request map and emits a compact batch to the gyroscope partition.
- Removed dead `StatsAggregator`.

## [0.2.43] - 2026-05-21

### Fixed

- **SSE slot TTL is refreshed ONLY by the client heartbeat — never server-side.**
  `Sse_Slot_Pool::$check_slot` was doing refresh-on-check (touching the TTL every
  drain iteration), so a zombie/abandoned connection held its slot forever and the
  pool stopped being a rate limit. It is now check-only — when the client stops
  heart-beating, the TTL lapses and the stream terminates. `RemoteSource` (the
  aggregator client) now sends a keepalive ttl of `HEARTBEAT_INTERVAL * 4` (60s,
  was 10s) so its slot survives the 15s gap between its heartbeats now that the
  server no longer refreshes it.

## [0.2.42] - 2026-05-20

### Changed

- **The shared `useMessageStream` hook no longer sends an `interval` query
  param** (synced from `newspack-nodes` ≥ 0.2.8). 0.2.41 dropped `intervalMs`
  from the ELN-owned dashboard callers, but the shared hook is synced from
  newspack-nodes and kept sending `interval` until the canonical was fixed —
  this release ships the corrected synced copy.

## [0.2.41] - 2026-05-20

### Changed

- **Dropped `intervalMs` from `useMessageStream` and its callers.** The hook no
  longer sends an `interval` query param; the server owns the heartbeat cadence
  (hardcoded 2s, requires `newspack-nodes` with the `interval` param removed).
  Removes the post-flush-fix-redundant client cadences (gyroscope 100ms,
  request-log 500ms, errors 1000ms) that only generated idle keepalive traffic.
- **Renamed the stats-salt option** `newspack_nodes_stats_salt` →
  `newspack_event_logger_nodes_stats_salt` (ELN naming convention). This also
  aligns it with the worker-restart dispatch's short-key prefix, which already
  assumed the ELN prefix — so rotating the salt now triggers the request-worker
  restart it always should have. One-time effect on deploy: existing stats
  orphan once (equivalent to a Flush Cache) and rebuild.

## [0.2.40] - 2026-05-20

### Changed

- **Removed the "Refreshing…" flash from the Aggregator dashboard.** At a 1s
  refresh it flickered every second; the status indicator now just shows
  "Updated …" / "Loading…". Dropped the `refreshing` state and the
  initial-vs-refresh fetch distinction along with it.

## [0.2.39] - 2026-05-20

### Fixed

- **Aggregator dashboard "ago" values now reflect the aggregator's snapshot
  time, not the browser clock.** Server HB / Client HB / Connected ages were
  computed against `Date.now()` and re-ticked every second, so at a 10s refresh
  a heartbeat the aggregator saw as "0s ago" displayed up to "9s ago" and
  climbed on its own between fetches. They now compute against the response
  Message's `TIMESTAMP` (the hub's clock when it built the snapshot), so each
  value is what the aggregator actually saw and stays fixed until the next
  refresh.

## [0.2.38] - 2026-05-20

### Fixed

- **Aggregator dashboard now shows "Server HB" (remote SSE heartbeats).**
  `RemoteSource::dispatch_event()` silently dropped the spoke's `heartbeat` SSE
  events, so `last_sse_heartbeat` was never recorded and the dashboard always
  showed "–". It now records the receipt time on each heartbeat. Requires
  `newspack-nodes >= 0.2.4`, whose SSE flush fix ensures the spoke's heartbeats
  actually reach the hub.

## [0.2.37] - 2026-05-20

### Fixed

- **The Aggregator status dashboard now appears in the Event Logger admin menu
  when "Enable Aggregator" is checked.** `enable_aggregator` was missing from
  the config option schema, so `Config::load_config()` — which the menu gate
  reads — never overlaid the WP option the checkbox writes; it returned only
  the config-file default (off). Added `enable_aggregator` to the schema so the
  checkbox takes effect.

## [0.2.36] - 2026-05-20

### Fixed

- **`StreamMerger` no longer aborts the merge on an unparseable offsetlog
  entry.** It catches the `InvalidArgumentException` now thrown by
  `Message::unpacked()` and skips restoring that remote's position (with a
  rate-limited log) instead of letting the exception propagate. Requires the
  matching strict-`unpacked()` change in `newspack-nodes`.

## [0.2.35] - 2026-05-19

### Changed

- **`expected_log_basenames` filter callback collapses to a one-line runtime-basename append.** Substrate v0.2.1 inverted the contract: `Log_Cleaner` now computes the topology-derived expected set itself and passes it to the filter as input. The app callback's job is just appending the runtime-pinned basenames it manages outside the topology graph (`firehose` from LogManager, `jobintake` from JobIntake). All the substrate-state introspection (`Bootstrap::get_topologies()`, `Cli::ls_workers()`, `Topology_Registry::basenames_for()`) that previously lived here is gone — substrate owns substrate facts. Requires `newspack-nodes >= 0.2.1`.

## [0.2.34] - 2026-05-19

### Fixed

- **`expected_log_basenames` filter now honors the substrate's operator-overlay active topology set.** Previously it read `$config['topologies']` from the app's merged Config, which is the app's full FILE-DEFAULT list (every topology the plugin knows about). The operator's actual active subset lives in the substrate option `newspack_nodes_topologies` (admin-UI checkboxes), exposed via `Bootstrap::get_topologies()`. The mismatch meant inactive topologies' basenames stayed "expected" forever — `Log_Cleaner` saw zero orphans and never cleaned the on-disk `*.log/` dirs the operator had toggled off. Datapoke staging hit this: app file defaults are `firehose-workers-only` + `request-workers`, operator selected `firehose-jobs-only` + `job-workers`. The filter unioned both lists, declared all 8 basenames expected, and 5 orphan log dirs (`completed`, `errors`, `flames`, `gyroscope`, `requests`) survived every supervisor cleanup tick. Filter now starts from `Bootstrap::get_topologies()` keys instead. The active-workers-on-disk overlay (which keeps a deactivated topology's basenames expected until its workers actually exit) is unchanged.

## [0.2.33] - 2026-05-18

### Changed

- **TM_COMMAND wire format follow-on to the substrate's cleanup.** Substrate now treats `arguments` as a literal CLI tail (Tachikoma contract) and `payload` as the structured-data slot — the previous `arguments: JSON.stringify(args)` triple-encoding is gone. Application side updates: `RemoteManager::build_command_envelope()` puts the settings-update map in `payload` (was JSON-encoded into `arguments`); `Servers_CI`, `Settings_CI`, `Performance_CI`, `Events_CI`, `Logger_CI`, `Aggregator_CI`, `Status_CI`, `Discovery_CI` all migrated to read structured data from `$payload` directly (verb closures gained `mixed $payload` as the 4th positional); JS dashboard callers (`PerformanceDashboard.request_search`) pass `payload: { ... }` where they used to pass `args: { ... }`. 1598 jest+phpunit tests pass; admin dashboards smoke clean on the new wire.

- **JS build toolchain: replaced `@wordpress/scripts` with esbuild + standalone tooling.** Same shape as the matching change in `newspack-nodes` (commit `e207993`): `npm run build` now runs `scripts/build.mjs` (esbuild + sass + rtlcss, ~220 lines), with `wp-scripts test-unit-js` → `jest`, `wp-scripts lint-js` → `eslint`, `wp-scripts lint-style` → `stylelint`, `wp-scripts format` → `prettier@npm:wp-prettier`. The build script extends the substrate's version with esbuild's context API for incremental rebuilds (`npm run watch`) and per-entry output basenames so `src/event-aggregator/settings.js` emits `settings.{js,css,asset.php}` — matching the existing PHP enqueue at `build/event-aggregator-settings/settings.css`. Cross-plugin alias points the build + jest at `../newspack-nodes/src/runtime/index.js`. `webpack.config.js` deleted, `concurrently` removed (esbuild's built-in `--watch` replaces the parallel-watch fanout). Scoped npm overrides pin `@typescript-eslint/typescript-estree` → `minimatch ^9.0.7` and `@babel/runtime` → `^7.26.10` so audit stays clean. Results: `package-lock.json` 2024 → 1148 packages; `npm audit` 14 alerts (4 mod, 10 high) → **0**; jest tests still pass; all five admin dashboards (Performance, Logger, Gyroscope, Request Log, Errors, Aggregator) render and stream data identically to wp-scripts; performance-dashboards bundle is 270KB single file (was 5 split chunks totaling 380KB — esbuild's IIFE bundle inlines what webpack lazy-chunked, smaller total bytes because no webpack runtime overhead).

- **Workers + Raw Logs dashboards moved to `newspack-nodes`.** Both surface substrate state — worker fleets, raw on-disk log segments — so they belong in the substrate, not the application layer. Deleted `includes/app/class-workers-ci.php`, `src/event-dashboards/`, `tests/unit/WorkersCITest.php`. Stripped `firehose_logs` + `firehose_status` verbs (and their `discover_logs` / `resolve_log_key` helpers + `DEFAULT_LOG_KEY` const) out of `Performance_CI`. Dropped the Workers + Raw Logs entries from the top-level "Event Logger" admin menu + page-to-bundle mapping; they now live as submenus under the substrate's "Nodes" top-level menu, served by substrate's own event-dashboards bundle. Added a `newspack_nodes/workers_cache` filter that supplies the substrate Workers_CI mount with this plugin's `Memcached_Cache` (for live-position lookups + the SSE-slot heartbeat verb).
- **`src/shared/` is now a synced copy from the substrate's `src/shared/`.** Canonical source lives in `newspack-nodes/src/shared/`; the substrate's `sync-shared.sh` copies hooks + utils here on every `npm run build` (via the substrate's `npm run sync`). Each file in `src/shared/` here gets a `// Synced from src/shared/` header that the PreToolUse hook uses to block accidental hand-edits — edit the substrate canonical, not the local copy.

### Removed

- **`RemoteSource::update_sse_heartbeat()` and its two callers' tests.** Method was unreferenced; SSE heartbeat tracking lives elsewhere now. PHPStan caught it.

### Fixed

- **Aggregator settings checkbox now reflects the file-config default when the WP option row is missing.** `skip_default_writes` deliberately deletes the WP option whenever the user saves a value that matches the file-config default — so for any site where `enable_aggregator => true` (or `enable_logging`, `log_memory`, `flush_every_line`) is set in the deployed config, the steady state is "option row absent." The form callbacks then resolved the value via `get_option( $key, 0 )` — hard-coded fallback of 0, ignoring the file default — so the box rendered unchecked, and checking it just got deleted again on the next save. Behavior was a saturated loop: file default true → option row deleted → checkbox shows unchecked → user re-checks → option row deleted. Added a `bool_option_with_file_default` helper that resolves the file default via `Config::load_config_defaults()` before falling back to a hard-coded value, and routed the four boolean toggle callbacks through it.

- **Discovery probe (cron health check + admin "Test" button) dispatches via `/command` instead of the deleted `/discovery` route.** The M5 migration replaced the legacy `GET /wp-json/newspack-nodes/v1/discovery` REST controller with a substrate-side `Discovery_CI` verb at `POST /wp-json/newspack-nodes/v1/command` with `{to: 'discovery', verb: 'get'}`, but `RemoteManager::check_server()` and `Servers_CI::probe_remote()` were never updated. Both call sites silently 404'd on every tick — health-check log filled with `HTTP 404` errors, server "Test" buttons returned `HTTP 404 response from server` to the admin. Both now build a TM_COMMAND envelope and unwrap the wrapped response (mirrors what JS `unwrapCommandResponse` does — outer Message → inner `{name, payload}` → verb's JSON return). Added `RemoteManager::discover_from_server()` as the shared dispatch helper.

- **`Performance_CI` URL-bucket aggregator: PHPStan dead-branch warnings on `min_ms` sentinel and `$count > 0` divides.** The `??=`-initialized array uses literal types (PHPStan sees `min_ms` as `0.0` and `count` as `0` regardless of accumulation through the reference variable). Switched the `min_ms` sentinel from `0.0` to `null` (cleaner anyway — distinguishes "no data" from "0ms min"), and replaced `$count > 0 ? sum/count : 0.0` with `sum / max(1, count)` for the two avg fields. Behavior unchanged: when no data accumulated, count is 0 and sum is 0, so 0/1 = 0.

### Changed

- **`reqgrep` chunk-size constant moved to `Consumer::MAX_POLL_BYTES`.** Was referencing `Partition::MAX_READ_SIZE`, which the substrate just deleted (the constant was conflating a buffer-cap-that-broke-large-offsetlog-reads with a per-record DoS guard). The 10MB chunk size itself is unchanged — just sourced from `Newspack_Nodes\Consumer::MAX_POLL_BYTES` where it belongs (it's the same poll-budget Consumer's main read loop applies).

### Fixed

- **`inflight_snapshot.start_time` now reflects the real PHP-request start, not LogManager-emit time.** With the profiler MU-plugin live, `$newspack_profiler['request_ts']` carries a wall-clock microtime captured before any regular plugin runs (alongside the existing `request_time` hrtime). LogManager reads it on construction and stamps the firehose `process (start)` entry with that ts, so RequestBuilder's `$request->timestamp` becomes the actual earliest point PHP began handling the request — not the deep-in-WP-bootstrap moment when LogManager fired its first message. `inflight_snapshot` now uses `$r['timestamp']` directly; the URL-bind `request_start_ts` it preferred before is gone (along with the corresponding callback assignment) because its only consumer was this snapshot path.

- **Profiler MU-plugin: plugin-load events now actually flush into the firehose.** The flush hook fires at `plugins_loaded` priority `-10001` (deliberately, so plugin-load events appear before any `plugins_loaded` callbacks). Problem: this plugin's composer autoloader was being registered inside the deferred-init closure that runs at `plugins_loaded` priority 11 — so at -10001 `LogManager` wasn't autoload-resolvable and `class_exists( …, false )` returned false, exiting the flush early. Pulled the `require_once vendor/autoload.php` out to plugin-file load time (where it only registers the spl callback; actual class loading stays lazy). The runtime-dependent setup (`CommandInterpreter` registrations, `Topology_Registry` mounts, `App\Core` init) stays deferred to priority 11 since those depend on `\Newspack_Nodes\Node` being loaded. Also dropped the `false` second-arg on `class_exists` in the mu-plugin so autoload is allowed to run.

- **Topology console live view now shows the `request-builder → gyroscope:partition` edge.** The override of `RequestBuilder::target()` walked the conditional `errors_target` / `completed_target` but missed the flight sibling's target — the `gyroscope:partition` destination the `set_inflight_target` verb stores on the hidden `RequestFlight` sibling. Live view rendered `gyroscope:partition` with a non-zero rate but no inbound edge from request-builder; edit view (which reads the topology source rather than the live graph) showed the edge correctly. Union the flight sibling's target into the extras list alongside the existing two, with the same dedup gate.

### Changed

- **Default `skip_urls` updated for M6 endpoint shape.** Drops `/wp-json/newspack-nodes/v1/firehose` (no live routes under that prefix after M6) and `/wp-json/newspack-nodes/v1/topology` (the topology console route was renamed in M4). Adds `/wp-json/newspack-nodes/v1/command` (the unified `/command` dispatch endpoint — every dashboard verb call lands there) and `/wp-json/newspack-nodes/v1/messages/stream` (the unified SSE endpoint). Without these entries the request-builder would log every dashboard click + every SSE connection as a regular request, polluting global timing stats with admin-only traffic. `workers/spawn` stays.

### Removed

- **`NEWSPACK_EVENT_LOGGER_NODES_TOPOLOGY_BASENAMES` const + the `newspack_nodes/num_logs` filter callback.** Both were hand-maintained catalogs that drifted every time a topology added a Partition — pre-M6 they silently omitted `completed.log` and `gyroscope.log` for months. Replaced by substrate primitives: `Topology_Registry::basenames_for()` (parses each TSL's `make_node Partition` lines) for per-topology basenames, and `Log_Discovery::on_disk()` (readdir `{base}/logs/*.log/`) for the storage-widget count. The `expected_log_basenames` callback now derives its per-topology data from the substrate; the TSL is the single source of truth. App still declares two always-on basenames (`firehose`, `jobintake`) for runtime code that writes outside any topology — LogManager + JobIntake use `Partition::fill()` directly from request code.

### Changed

- **`RemoteSource` is generic cross-server transport — no more peeking inside Message VALUE.** Earlier M6.7 work added an `isset($value['k'])` gate in `dispatch_msg_envelope` and back-filled `$value['rid']` from envelope KEY, baking firehose-shape assumptions into what's meant to be a transport node (the hub uses it to pull any log a spoke publishes — firehose today, could be gyroscope.pN or completed.pN tomorrow). Replaced `forward_entry(array $data)` with `forward_envelope(array $envelope)`: forwards every non-`connected` envelope verbatim, preserving TYPE / KEY / VALUE from the spoke. Shape-aware behavior survives only inside the array-VALUE branch (the `_source` hub-attribution stamp + the `aggregator_ingest_line` filter chain that StreamMerger uses for `k:"job"` → `k:"remote_job"` rewrites); scalar payloads bypass that path and reach the sink unchanged. `forward_entry`'s old `k`/`ts` validation gates removed — those drops belong downstream where shape decisions live, not in the transport layer.

- **Raw Logs dashboard now discovers logs from disk instead of a hardcoded catalog.** `Performance_CI::firehose_logs` verb scans `{base}/logs/*.log/` directories every call and returns the sorted result. Replaces the static `AVAILABLE_LOGS` constant, which silently omitted `completed.log` and `gyroscope.log` after the M6 topology added them — so the Raw Logs picker is now complete (8 entries: completed, errors, firehose, flames, gyroscope, jobintake, jobs, requests). `firehose_status` + `resolve_log_key` switched to the same discovery; `DEFAULT_LOG_KEY = 'firehose'` is the only constant left, used as the fallback when the operator's `log` arg is missing or unknown.

### Removed

- **M6 cleanup — legacy SSE wire-format dispatch in `RemoteSource::dispatch_event`.** The `event: entry` / `event: heartbeat` / `event: connected` branches that handled the now-deleted `FirehoseStreamController`'s wire format are gone; everything routes through `dispatch_msg_envelope` for the unified `msg`-envelope shape. Migrated ~26 test sites across `StreamMergerTest` (and ~15 in `RemoteSourceTest`) to the new wire via three helpers (`entry_frame`, `connected_frame`, `position_frame`); deleted `test_skips_non_data_lines` (its `event: heartbeat` assertion is meaningless under the new wire and the `id:` field assertion is already covered by `test_unknown_sse_field_ignored`). Substrate's `Messages_Stream_Controller` is now the ONLY SSE wire format any production code consumes or any test exercises.

- **M6.9b / M6.10 — `FirehoseStreamController` + `SSEControllerBase` + `Partition_Reader` + their tests.** The last three legacy SSE classes after M6.7 moved RemoteSource onto the unified endpoint. `rest_api_init` no longer registers any per-feed SSE controllers — the substrate's `Messages_Stream_Controller` is the only SSE surface now. Eight new gate tests in `M2BootstrapTest` (`test_legacy_*_class_is_gone`) pin every deleted class so an accidental re-introduction (autoload regression, partial revert) trips here instead of silently double-handling SSE traffic. The legacy `event: entry` / `event: heartbeat` / `event: connected` dispatch branches in `RemoteSource::dispatch_event` are kept alive to support the existing test corpus (~33 tests across `RemoteSourceTest` + `StreamMergerTest` drive them); they're dead in production after M6.7 and can be cleaned up the next time someone touches that test corpus.

### Changed

- **M6.7 — `RemoteSource` (StreamMerger's cross-server SSE consumer) migrated to the substrate's `/messages/stream` endpoint.** URL switches from `/wp-json/newspack-nodes/v1/firehose/stream?partition=N&aggregator=1` to `/wp-json/newspack-nodes/v1/messages/stream?subscribe=firehose.pN`. Position resume now rides a JSON `positions` param keyed by subscription → partition → `{seg, off}` (substrate `parse_positions` shape). The per-partition aggregator slot pool (60s TTL) engages automatically because `Messages_Stream_Controller::subscription_partition()` extracts N from the `firehose.pN` shape; no `aggregator=1` flag needed. New `dispatch_msg_envelope` private method handles the unified `event: msg` wire format: 7-field envelope JSON-decoded, position parsed from envelope `ID = "seg:off"` (Consumer stamps at emit), `connected` envelope captures slot, entry envelope (`VALUE` is a dict with `k`) back-fills rid from `KEY` and forwards through the existing `forward_entry` pipeline. Eight new unit tests in `RemoteSourceTest` cover the URL contract, position round-trip, msg-envelope dispatch (entry / connected / position-from-ID), and silent drop of malformed VALUE. Legacy `event: entry` / `event: heartbeat` / `event: connected` handlers remain in `dispatch_event` (and their 21 tests stay green) until M6.9b deletes them alongside `FirehoseStreamController`.

### Removed

- **M6.8 / M6.9 — InflightTracker + 4 legacy SSE controllers + their test suites.** ~2150 LOC of code removed: `includes/class-inflight-tracker.php` (240 LOC), `RawlogsController` (122), `ErrorsStreamController` (84), `RequestsStreamController` (85), `GyroscopeStreamController` (171), plus their PHPUnit suites and the M5-acceptance-gate `SchemaParityAuditTest` (278). Bootstrap registration of the four browser controllers removed from `newspack-event-logger-nodes.php`. The legacy `useFirehoseConnection` JS hook (no remaining consumers after M6.3–M6.6) is also gone. `FirehoseStreamController` stays alive until M6.7 (StreamMerger cross-server migration) — see MIGRATION.md.

### Deferred

- **M6.7 — StreamMerger / RemoteSource migration to `/messages/stream`** — deferred to a focused follow-up milestone. Out of scope for the current branch: 918 LOC of cross-server SSE consumer, wire-format change cascading through `forward_entry` → sink → `JobRouter`, 21 existing tests on the `event: entry` wire shape, and smoke-testing properly requires a multi-spoke topology not available in this dev environment. `FirehoseStreamController` + `SSEControllerBase` + `Partition_Reader` therefore remain alive in the codebase.

### Changed

- **M6.6 — Gyroscope dashboard migrated to the unified `/messages/stream` endpoint, source `gyroscope.log`.** Subscribes via `useMessageStream({subscriptions:['gyroscope']})`; dispatches the two record types client-side via `src/performance-gyroscope/transformGyroscopeLine.js` — `KEY='inflight'` + array VALUE → inflight snapshot (upsert by rid, skip completed); object VALUE with rid → completion (merge + mark `state:complete`). This client-side dispatch is exactly what the legacy `GyroscopeStreamController` did server-side via `InflightTracker`, so M6.8 (next) deletes both. Browser-verified rendering live in-flight requests with state pills (lifecycle / query & posts / content rendering / theme / scripts & styles / rest api / process / complete). `GyroscopeStreamController` stays alive until M6.9.

- **M6.5 — Requests dashboard migrated to the unified `/messages/stream` endpoint, source `completed.log`.** Subscribes via `useMessageStream({subscriptions:['completed']})`; runs the URL (2000 char) / user-agent (500 char) clip client-side via `src/performance-request-log/transformCompletedLine.js`. The source-log change is intentional: the legacy `RequestsStreamController` tailed `requests.log` (live + completed mixed) and inferred completion from payload shape; the new subscription consumes `completed.log` (filtered upstream by the topology's `completed:tee` node) so the transform stays a pure shape mapper with no completion guard. Browser-verified rendering 267 live requests with TIME / REQUEST ID / URL / STATUS / IP / DURATION columns intact. `RequestsStreamController` stays alive until M6.9.

- **M6.4 — Errors dashboard migrated to the unified `/messages/stream` endpoint.** Subscribes via `useMessageStream({subscriptions:['errors']})`; runs the rid-required + 1000-char `m`-clip filter client-side via `src/performance-dashboards/transformErrorLine.js`. Browser-verified rendering 305 live entries with TIME / REQUEST ID / KEYWORD / MESSAGE columns intact. `ErrorsStreamController` stays alive until M6.9.

- **M6.3 — RawLogs dashboard migrated to the unified `/messages/stream` endpoint.** First of the four dashboard cutovers in M6. The dashboard now subscribes via the new `useMessageStream` hook (in `src/shared/hooks/`) and runs the per-line transform client-side via `src/event-dashboards/transformLogLine.js` — mirrors the legacy `RawlogsController::transform_line()` (KEY prefix, JSON-render of array VALUE, 1000-char clip). Partition number comes from the Message FROM field (`{sub}.pN`), matching the new substrate stamp.
- **`Sse_Slot_Pool` `check_slot` closure now refreshes TTL via `touch_sse_slot`.** Without this, the slot expires every `$ttl_browser` seconds (30s default) and the dashboard cycles disconnect → reacquire — visible as a "Reconnecting in 2s..." banner every 30 seconds even on a healthy stream. Refresh-on-check matches the legacy `SSEControllerBase` heartbeat semantic but without a separate client-side ping.

### Added

- **M6.2 — `Sse_Slot_Pool::wire()` installs the substrate's slot-pool seams.** Three closures bound at plugin boot to `\Newspack_Nodes\Rest\Messages_Stream_Controller`'s static `$acquire_slot` / `$release_slot` / `$check_slot` properties, each delegating to a shared `Memcached_Cache` via `Cache_Interface`. Same `MAX_SSE_SLOTS = 8` cap the legacy `SSEControllerBase` used; TTL is 30s for the shared browser pool (partition `-1`) and 60s for per-partition aggregator pools. The substrate computes the partition number from the subscription shape, so dashboards subscribing to `firehose` (multi-partition log) land in the browser pool and a future `firehose.p3` aggregator subscription (M6.7) lands in a per-partition pool. Cache instance is overridable via `Sse_Slot_Pool::$cache` for tests; five new unit tests in `tests/unit/SseSlotPoolTest.php` pin the contract. Ready for M6.3+ dashboard migrations onto `/messages/stream` without losing the rate-limiting the per-feed SSE controllers had.

- **9 service `CommandInterpreter` subclasses replace ~20 legacy WP REST controllers.** `Workers_CI`, `Discovery_CI`, `Status_CI`, `Settings_CI`, `Logger_CI`, `Events_CI`, `Servers_CI`, `Aggregator_CI`, `Performance_CI` — all reachable via `POST /wp-json/newspack-nodes/v1/command` with a TM_COMMAND envelope (`{type, to, from, id, value: json_encode({name, arguments, payload})}`). Performance_CI carries the heavy lift: 19 verbs replacing the entire `perf-*` controller family plus the non-streaming methods on firehose/gyroscope/request-log controllers. SSE controllers stay as REST endpoints — the command path doesn't stream. See `MIGRATION.md` for the per-CI verb reference and the M5 deletion list.
- **`VerbHarness` test fixture for unit-testing CI verbs in isolation.** Wraps `CommandInterpreter::interpret()` so each verb test reads as `harness->call('verb', $args)` instead of hand-assembling Message envelopes. Used by every M2 CI unit test.
- **M2 integration tests: `M2BootstrapTest` (substrate-hook registration) and `M2CommandDispatchE2ETest` (end-to-end `/command` dispatch).** The bootstrap test fires `newspack_nodes/request_graph_ready` and asserts all 9 CIs register under their short names. The E2E test drives an HTTP request through `Command_Controller::dispatch` for every CI's read-only smoke verb and asserts the response shape.
- **9 service CI classes registered via `CommandInterpreter::register_class()`** so `make_node()` can construct them by shell-name from the `newspack_nodes/request_graph_ready` hook.
- **M4 dashboard cutover #1 (Aggregator Status).** `AggregatorStatus.js` now dispatches `aggregator.status` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes-aggregator/v1/status')`. Same response shape (verified by the M3 schema-parity audit), same 1–10s refresh cadence, same error UX. Adds `unwrapCommandResponse` shared helper that peels the substrate's 7-field Message tuple down to the verb's payload (double-JSON-parse: outer `VALUE` → `{name, payload}`, inner `payload` → data object); throws on TM_ERROR with the payload as the error message. Browser smoke-test confirmed the partition grid renders correctly via the CI verb path.
- **M4 dashboard cutover #2 (Performance Logger).** `HookSelectorModal.js` (in the `performance-logger` tree) now dispatches `performance.hooks_registered` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes/v1/performance/registered-hooks')`. Reuses the `unwrapCommandResponse` helper introduced in cutover #1. Browser smoke-test confirmed the hook-selector modal populates all 19 categories with correct counts (Lifecycle 17/25, REST API 3/21, etc.) via the new CommandClient path. Rewrite landed in commit `08e7a34`.
- **M4 dashboard cutover #3 (Performance Gyroscope) — audit-only, no JS rewrite.** The `performance-gyroscope` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'gyroscope'})` (SSE, which stays as REST). The legacy `/gyroscope/timeline` JSON route was fully orphan — no dashboard caller in any `src/` tree. Deletion landed without a rewrite step.
- **M4 dashboard cutover #4 (Performance Request Log) — audit-only, no JS rewrite.** The `performance-request-log` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'requests'})` (SSE, which stays as REST). The legacy `/request-log/list` + `/request-log/detail/{id}` JSON routes were fully orphan — no dashboard caller in any `src/` tree. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` (added in M2 Task 12) cover the equivalent surface for any future caller. Deletion landed without a rewrite step.
- **M4 dashboard cutover #5 (Event Dashboards).** `WorkerStatus.js` and `RawLogs.js` (in the `event-dashboards` tree) now dispatch their non-SSE work over `getCommandClient().send()` instead of `apiFetch`. WorkerStatus reads the operator-grade payload via `workers.dump_metadata` (one CI call replaces the legacy `/performance/workers` JSON route + 1s polling cadence); RawLogs reads the log picker via `performance.firehose_logs` (replaces `/firehose/logs`). The shared `useFirehoseHeartbeat` hook (driven by `RawLogs`'s SSE connection and also reused by `RequestStream` / `ErrorLog` / `FirehoseStream`) cuts over to `workers.heartbeat` (replaces `/firehose/heartbeat`). `Workers_CI.dump_metadata` introduced in commit `739ac13` as a single fat verb — it replaces `WorkersController::get_workers()` field-for-field including the `logs[]` enumeration and the `inputs_status` / `outputs_status` per-Consumer arrays. Rewrite landed in commit `a4ca852`; browser smoke-test confirmed both panels render (Workers Status: full pipeline visible with live cursors; RawLogs: 552 lines/s streaming with heartbeat firing on `/command` returning 200s).
- **M4 dashboard cutover #6 (Performance Dashboards) — the biggest cutover.** `usePerformanceApi.js` and `PerformanceDashboard.js` (in the `performance-dashboards` tree) now dispatch all 9 `apiFetch` calls over `getCommandClient().send()`. Mapping: `fetchOverview` / `fetchBreakdown` → `performance.overview` (with `categories` / `breakdown` / `server` args); `fetchUrls` → `performance.urls`; `fetchUrlDetail` / `fetchUrlBreakdown` / `fetchUrlCategories` → `performance.url_detail` (with `categories` / `breakdown` args); `fetchRequestDetail` → `performance.request_detail`; both `request_search` callsites in `PerformanceDashboard.js` → `performance.request_search`. **Schema-parity audit found real gaps** that had to be filled before the cutover: the M2 `Performance_CI.overview` + `Performance_CI.url_detail` verbs were missing the optional `server` / `breakdown` / `categories` args plus the response fields that ride along (`global_leaderboard`, `category_time_series`, `breakdown_time_series`, `breakdowns: {dim => series}` on overview; `stats.time_series`, `breakdown_time_series`, `category_time_series` on url_detail). 11 new tests in `PerformanceCITest.php` cover the gap (failed first, then implemented). Public API of the `usePerformanceApi` hook unchanged — component consumers keep their existing call shape; only the transport differs.
- **Shared `useFirehoseHeartbeat` cut over to `workers.heartbeat`.** The retro-completion side effect: dashboards #3 (Performance Gyroscope), #4 (Performance Request Log), and #6 (Errors Stream) all use SSE controllers that drive their slot heartbeats through this shared hook. None of them needed a JS rewrite of their own data path (their non-SSE work was already audit-only orphan), but their heartbeat call now also flows through `/command` as a side effect of the hook cutover. The SSE controllers themselves stay as REST endpoints (CommandInterpreter dispatch is request/response only and doesn't stream).
- **Webpack alias `@newspack-nodes/runtime` → substrate's `src/runtime/index.js`** so dashboards can import `CommandClient`, `useNodeState`, `sse_connector` etc. with a single stable specifier. wp-scripts inlines the runtime into each dashboard bundle, so the released zip ships nothing extra. Mirror aliases added to `jest.config.js` (moduleNameMapper) and `.eslintrc.js` (import/core-modules) so tests + lint don't trip on the bare specifier.
- **`getCommandClient()` singleton factory at `src/shared/utils/commandClient.js`** — pulls `restUrl` + `nonce` from `window.NewspackNodesData` (already localized in the plugin file) and returns one CommandClient per page. Future M4 dashboards reuse this helper.
- **`src/aggregator-admin/` wp-scripts entry (M5.2a).** Replaces the raw jQuery `assets/aggregator-admin.js` script with a tiny wp-scripts bundle so the WP admin "Configured Servers" UI's 4 CRUD verbs dispatch through `getCommandClient()` against the `servers` CI. `api.js` is the pure dispatch path (jQuery-free, 5 Jest tests); `index.js` is the jQuery DOM glue. Built to `build/aggregator-admin/index.js` and enqueued only on the Event Logger Settings page.
- **"Enable Aggregator" checkbox in Event Logger Settings → Remote Servers.** The `newspack_event_logger_nodes_enable_aggregator` option has always been load-bearing (menu visibility, supervisor worker-restart on toggle, push-side fan-out gates in `SettingsSync` + `AutoTuner`) but had no admin UI — flipping it required `wp option update` or editing the config file directly. The checkbox renders above Configured Servers in the existing Remote Servers section; defaults to OFF (fresh installs aren't hubs); browser-verified end-to-end (checked → save → option=1 → submenu appears → dashboard loads). Also aligned the menu gate's `get_option` default from `1` to `0` so the runtime matches the documented "default OFF" posture.

### Changed

- **Topology `gated_by` mechanism removed from docs** (the code mechanism has been gone for several releases; ARCHITECTURE.md still described an `'gated_by' => 'newspack_event_logger_nodes_enable_aggregator'` entry on the `aggregator` topology that hasn't shipped in a long time). The current shape: topology activation is the substrate's Topologies multi-select (`newspack_nodes_topologies`); hub-mode operator gates (admin submenu visibility, push-side fan-out short-circuits) live on `newspack_event_logger_nodes_enable_aggregator`. Two independent operator choices, two surfaces — both intentional, neither implies the other. Earlier CHANGELOG entries that describe `gated_by` stay as historical record of what shipped at the time.
- **M5 substantively complete.** 9 legacy controllers deleted across M5.1 (6 orphan-cleanup) + M5.2 (3 server-held after SettingsSync + admin-JS transport migration). Combined with M4's 14 dashboard-cutover deletions, the rebuild trims ~24 legacy WP REST controllers; ~30 `apiFetch` calls now flow through `/command`. M5.3 audit (SSE controllers + `Partition_Reader` + `SSEControllerBase`) found zero deletions safe: all five SSE controllers have live consumers (four browser dashboards + one hub-side cross-server pull). The full SSE consolidation onto the substrate's `Messages_Stream_Controller` is real M6 work — Basic Auth in cross-server SSE, slot-pool semantics, per-partition subscriptions, four browser-side transform rewrites. Documented in `MIGRATION.md`'s "M5.3 — SSE controller audit" section.

### Removed

- **Legacy `AggregatorStatusController`** (`includes/rest/class-aggregator-status-controller.php`) deleted. The event-aggregator dashboard cut over in M4 #1; the schema-parity audit found zero gaps; the browser smoke-test confirmed `Aggregator_CI.status` is byte-equivalent. The 3-route stub `AggregatorController` (`/status` + `/servers` + `/health`) stays alive for now — its `get_status()` body is inlined (no longer a delegate) and will be removed when a follow-up M4 cutover handles its sibling routes.
- **Legacy `LoggerController`** (`includes/rest/class-logger-controller.php`) deleted. The `/logger/config` and `/logger/hooks` routes had no remaining dashboard callers — JS audit across every `src/` tree found zero `apiFetch` hits to either endpoint. `Logger_CI` verbs cover the equivalent surface for any future caller.
- **Legacy `PerfHooksController`** (`includes/rest/class-perf-hooks-controller.php`) deleted. The `/performance/registered-hooks` route was the only one a dashboard hit (the M4 #2 cutover replaces that call with `performance.hooks_registered`); the sibling `/performance/hook-categories` route had no dashboard caller in any `src/` tree. `Performance_CI.hooks_registered` and `Performance_CI.hooks_categories` verbs replace both.
- **Legacy `GyroscopeController`** (`includes/rest/class-gyroscope-controller.php`) deleted. The non-streaming `/gyroscope/timeline` route was fully orphan — the `performance-gyroscope` dashboard only uses SSE (`useFirehoseConnection({endpoint:'gyroscope'})`) and never hit the JSON route. The streaming sibling `GyroscopeStreamController` (`/gyroscope/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.
- **Legacy `RequestLogController`** (`includes/rest/class-request-log-controller.php`) deleted. The `/request-log/list` + `/request-log/detail/{id}` routes were fully orphan — the `performance-request-log` dashboard only uses SSE (`useFirehoseConnection({endpoint:'requests'})`) and never hit either JSON route. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` cover the equivalent surface. The streaming sibling `RequestsStreamController` (`/requests/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.
- **Legacy `WorkersController`** (`includes/rest/class-workers-controller.php`) deleted. The `/performance/workers` JSON route and `/performance/workers/restart` POST route both cut over to `Workers_CI.dump_metadata` + `Workers_CI.restart` in M4 cutover #5. Browser smoke-test confirmed the Worker Status panel renders the full pipeline (cursors, heartbeats, segment data) via the CI verbs; `WorkersControllerRealShapeTest` deleted alongside since `WorkersCITest` already covers the same contract (envelope keys, rich descriptor fields, live-position from memcache, offsetlog fallback) with broader scope.
- **Legacy `FirehoseController`** (`includes/rest/class-firehose-controller.php`) deleted. All three non-SSE routes cut over: `/firehose/logs` → `Performance_CI.firehose_logs`; `/firehose/heartbeat` → `Workers_CI.heartbeat`; `/firehose/status` → `Performance_CI.firehose_status` (verified zero JS callers in any `src/` tree before deletion). The streaming sibling `FirehoseStreamController` (`/firehose/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it. `RawlogsController` previously called `FirehoseController::get_available_logs/get_default_log` statically for `sanitize_log_param`; inlined the 6-entry catalog as a `private const AVAILABLE_LOGS` (mirrors Performance_CI's identical private const) so the SSE controller doesn't depend on a deleted sibling.
- **Legacy `PerfOverviewController` + `PerfUrlsController` + `PerfRequestsController` + `PerformanceController`** (4 controllers, deleted in commit `b96c320`) — the biggest single deletion in M4 to date. The `performance-dashboards` rewrite (M4 cutover #6, commit `343ec01`) cut over 9 `apiFetch` calls to `Performance_CI` verbs across `usePerformanceApi.js` + `PerformanceDashboard.js`; the schema-parity audit caught 4 missing args/fields in `overview` and 4 in `url_detail` that were filled before the rewrite landed (`PerformanceCITest` grew from 99 → 110 tests covering the gap). Mapping: `PerfOverviewController` → `Performance_CI.overview`; `PerfUrlsController` → `Performance_CI.urls` + `.url_detail`; `PerfRequestsController` → `Performance_CI.request_search` + `.request_detail`. `PerformanceController` had zero JS callers for its `/performance/dashboard` and `/performance/timing` routes but instantiated `PerfOverviewController` + `PerfUrlsController` to delegate, so it had to come along in the same batch — leaving it behind would have been a fatal at any request through its routes. The abstract base `PerformanceControllerBase` stays alive (shared by the surviving `PerfSettingsController` / `PerfConfigController` / `PerfHooksAvailableController`, which are pending their own future cutovers).
- **M5.1 orphan-controller cleanup: 4 legacy controllers deleted.** Pure-orphan sweep after M4 dashboard cutovers — all four had zero `apiFetch`/`fetch` callers in any `src/` tree AND zero static dependents in PHP. `class-aggregator-controller.php` (3-route stub `/status` + `/servers` + `/health`) → `Aggregator_CI` verbs (`status`/`health`/`servers`); `class-discovery-controller.php` → `Discovery_CI.get`; `class-events-controller.php` → `Events_CI.recent` + `.stats`; `class-status-controller.php` → `Status_CI.get`. The matching test files (`tests/unit/Rest/{Aggregator,Discovery,Events}ControllerTest.php` + `tests/unit/StatusControllerTest.php`) deleted alongside — the M2 `*CITest` files already cover the same surface with broader scope. 4 new gate tests in `M2BootstrapTest` (`class_exists` asserts mirroring the M4 pattern) prevent autoloader-cache resurrection across deploys. **`ServersController` was originally batched here but stayed alive** — `assets/aggregator-admin.js` (enqueued by `Admin::configured_servers_callback` in the WP admin "Configured Servers" UI) still POST/PUT/DELETEs to its `/servers` and `/servers/{id}/test` routes via jQuery AJAX. The original orphan grep used `apiFetch`/`fetch` only and missed the jQuery `$.ajax` call sites. The Servers migration belongs with the `SettingsSync` transport cutover in M5.2 (the admin JS rewrite to `/command` lands there alongside the option-fanout migration).
- **M5.2: 3 legacy controllers deleted (Servers + Settings + PerfSettings).** Two-step migration unblocked deletion of the last user-facing routes that still had live callers.
  - **M5.2a — aggregator-admin.js cutover.** The WP admin "Configured Servers" UI (`Admin::configured_servers_callback`) ran its Test/Toggle/Remove/Add buttons through 4 jQuery `$.ajax` calls against `/servers` REST routes. Rewritten as a wp-scripts entry at `src/aggregator-admin/` so the `@newspack-nodes/runtime` alias resolves and the 4 verbs dispatch through the shared `getCommandClient()` singleton against `Servers_CI.add` / `.update` / `.delete` / `.test`. jQuery stays for DOM glue but the transport path lives in `src/aggregator-admin/api.js`, which is unit-tested independent of the DOM (5 new Jest cases covering each verb's arg composition + the unwrap shape). PHP enqueue updated to load from `build/aggregator-admin/index.js` instead of the legacy `assets/aggregator-admin.js`; legacy file deleted. The matching commit is `3645293`.
  - **M5.2b — SettingsSync transport migration.** `RemoteManager::post_to_server()` previously POSTed `{option, value}` to spokes' legacy `/settings` (substrate keys) or `/performance/settings` (perf-tuning keys) routes. Now wraps the verb args in a TM_COMMAND envelope routed to `Settings_CI.update` or `Performance_CI.settings_update` and POSTs to the unified `/wp-json/newspack-nodes/v1/command` endpoint. The `SettingsSync::ENDPOINT` / `PERF_ENDPOINT` constants stay — they're now category tags that select the verb, not URLs themselves. Basic Auth (the spoke-side authentication mechanism) is independent of the dispatch path and is preserved unchanged. The substrate-key path also strips the `newspack_nodes_` prefix on the wire so the verb's short-name whitelist (num_partitions / num_segments / segment_size / max_lifespan) accepts the args without needing the prefix history. 5 new tests cover envelope shape, URL routing, verb selection per endpoint, basic-auth preservation, and prefix-strip. 17 existing tests updated to assert the new URL + envelope shape. The matching commit is `f17bd69`.
  - **M5.2c — controller deletion.** With zero callers remaining, the three controllers + their test files were deleted: `class-servers-controller.php` (+ `ServersControllerTest.php` + `ServersControllerCrudTest.php`); `class-settings-controller.php` (+ `SettingsControllerTest.php`); `class-perf-settings-controller.php` (+ `PerfSettingsControllerTest.php`). Three route registrations removed from `newspack-event-logger-nodes.php`. Three new gate tests in `M2BootstrapTest` (`class_exists` asserts mirroring the M4/M5.1 pattern) prevent autoloader-cache resurrection. Final suite: 1831 PHP tests (down from 1894 pre-deletion as the 63 dedicated controller tests are gone; 5 new tests are in the count via the M5.2b commit).

### Fixed

- **Service CIs now mount via `$base_ci->make_node(...)` on the substrate's new `newspack_nodes/request_graph_ready` hook.** The previous `rest_api_init` priority-11 path registered CIs by name but didn't wire sinks — verb responses (which route back via `TO=FROM`) had no path to `HTTP_Out` and silently dropped on the floor. `make_node()` atomically instantiates + names + sinks each CI in one step. Requires `newspack-nodes` substrate commit `24921f5`.

### Notes

- Legacy WP REST controllers remain alive in `includes/rest/` through M2. M4 dashboard rebuilds cut over to `/command`; once a dashboard migrates, its legacy controller becomes deletable in M5. See `MIGRATION.md` for the full deletion list.
- SSE controllers (`firehose-stream`, `gyroscope-stream`, `errors-stream`, `requests-stream`, `rawlogs`) stay as REST endpoints. CommandInterpreter dispatch is request/response only.
- Requires `newspack-nodes` substrate with the `newspack_nodes/request_graph_ready` hook (substrate commit `24921f5`).

### Added

- New `completed.log` Partition (compact one-line summary per completed request, 11 fields matching legacy `requests-stream-controller::transform_line` per the M1 schema-parity audit).
- New `gyroscope.log` Partition (compact summaries via Tee fan-out from `completed:tee` + periodic inflight snapshots from the hidden `RequestFlight` sibling, 12 fields per inflight row matching legacy `InflightTracker::get_active`).
- `RequestFlight` sibling Node attached to `RequestBuilder` (Timer-based, hidden via patron filter; mirrors Perl `InstrumentalityFlight.pm`). Default 1000ms interval; `set_interval( $ms )` reschedules. Sink propagates from RequestBuilder via the patron pattern. Fail-safe when sink/target/patron aren't wired yet.
- `set_completed_target`, `set_inflight_target`, `set_inflight_interval` verbs on `request-builder:config` (empty arg clears target; non-numeric interval rejected).
- `request_start_ts` slot on per-request state for the `inflight_snapshot.start_time` field; stamped by the `request` state-callback when the URL keyword arrives. Process-start ts stays on `$request->timestamp` for the `format_index_entry` / `evict_request` paths that still need it.
- `SchemaParityAuditTest` (M1 acceptance gate, `#[Group('parity')]`) — drives BOTH legacy and new emitters through the same input and asserts byte-for-byte field-by-field parity (not just field presence). Tests: `test_compact_summary_value_equivalence_against_legacy_transform_line` (wire-roundtripped envelope → both emitters; every field `assertSame`), `test_inflight_snapshot_value_equivalence_against_legacy_get_active` (same firehose lifecycle through `InflightTracker::process` and `RequestBuilder::fill`; every field `assertSame` except `time_ms` / `est_ms` / `lag_ms` within 50ms tolerance for wall-clock skew), `test_inflight_snapshot_surfaces_runaway_requests_like_legacy` (60-frame stack overflow; both emitters must still expose the request). Deletion of the legacy SSE controllers in M5 requires this test to keep passing.
- `TopologyCompactSummaryTest` integration test — loads each compact-summary-bearing TSL (`firehose-workers-and-jobs`, `firehose-workers-only`) in-process against a real CommandInterpreter + Router and asserts both Partitions register, the Tee fans out to both, and the three `:config` verbs land on RequestBuilder.
- `newspack_nodes/expected_log_basenames` filter — lets substrate's `Log_Cleaner` (≥ v0.1.32) auto-delete entire log directories whose basename no longer belongs to any active topology (e.g. disabling `request-workers` means `flames.log` is no longer produced or read). Filter returns the union of (a) topologies in the application's `topologies` config, and (b) topologies whose worker lock dirs are still on disk via `Cli::ls_workers()`. Per-topology basename map lives in the module-level `NEWSPACK_EVENT_LOGGER_NODES_TOPOLOGY_BASENAMES` constant; update it when topologies are added or their inputs/outputs change.
- Workers dashboard unifies per-log slot rendering across consumed and unconsumed logs. `WorkersController::enumerate_logs()` (was `enumerate_terminal_logs`) walks every `{base}/logs/*.log/` directory and emits per-partition slot entries covering `max(num_partitions, max-p-index-on-disk + 1)` slots per log. The frontend (`WorkerStatus.js`) uses this single source of truth, with cursor data overlaid from `workers[]` per partition where the log has a Consumer. Result: orphan-from-shrink slots render alongside live slots until cleaned; configured-but-empty logs render N empty slots so operators see the configured shape. `setWorkers` / `setStandalone` / `setLogsCatalog` guard with a `JSON.stringify` change-detection compare so steady-state fetches don't trigger a `buildRenderPlan` re-render.

### Changed

- `RequestBuilder` now emits a compact summary on every completion (in addition to its existing full envelope on the primary target). Existing `requests.log` semantics unchanged.
- `RequestBuilder::build_compact_summary` passes through `timestamp` / `duration_ms` / `status_code` / `error_status` without coercion (was force-casting to `(float)`/`(int)`/`(string)`); legacy `transform_line` never cast, so JSON-encoded int-valued floats round-tripped as int through the wire and the new code's `start_time: 1747401234.0` did not match the legacy `start_time: 1747401234`. Removed the casts.
- `RequestBuilder::inflight_snapshot`'s `state` field now carries the top-of-stack hook name (e.g. `render`, `wp_head hook`, or `process` when the stack is unwound) instead of the hardcoded `'active'` literal. Matches legacy `InflightTracker::get_active` semantics.
- Runaway requests (over `MAX_STACK_DEPTH`) now stay visible in `inflight_snapshot` until LRU eviction (matches Perl Gyroscope behavior); previously `fill()` evicted `is_runaway` entries from the cache, hiding them from the dashboard. Memory stays bounded because `push_stack` now caps the stack at `MAX_STACK_DEPTH` (early return at cap) instead of overshooting by one frame before flagging. The `evict_request` path still fires with `error_status='T'` when LRU rotation eventually drops them, so they still land in the completed pipeline.

### Notes

- Topology files `firehose-workers-and-jobs.tsl` and `firehose-workers-only.tsl` now wire `completed:tee` → `completed.log` + `gyroscope.log` and configure `request-builder:config` with the three new verbs. No action required for installations using the bundled topology files.
- Downstream consumers that previously relied on the silent runaway-eviction behavior may now see runaway entries in inflight snapshots. The pipeline still emits the completion event with `error_status='T'`; the behavioral change is visibility, not delivery.

### Tests

- Round-3 coverage push: 93.9% → 94.2% (8197/8701 stmts across 52 classes). 2162 lines of new test assertions across `Admin` (skip_default_writes, sanitize_array_strings/aggregator_servers/custom_events, field-callback placeholder + stored-value renderings, settings-registration shape), `LogManager` (filter normalization + URL routing edge cases), `RemoteManager` (handle_job non-callable/non-array filter returns, request_args header merging for basic-auth/bearer, calculate_lag with missing cursor or unknown segment, sync_setting WP_Error path, queue_sync_all_settings happy path), `Rest\FirehoseStreamController` (additional segment/partition selection branches), `SettingsSync` (fail-closed `enable_workers` polarity coverage), `StreamMerger` (cache/require_https/verify_ssl propagation to children, namespaced_remote_name default, unknown-verb error reply, name-setter sibling propagation + idempotence).

## [0.2.32] - 2026-05-15

**Requires:** [newspack-nodes ≥ v0.1.29](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.29) — for `Node::dump_node()` overridable hook and `serializeTsl` schema-default expansion.

### Fixed

- **Application nodes are visible in the topology editor again.** v0.2.30's plugin-load defer pushed the 8 `CommandInterpreter::register_class()` and 2 `Formatters::register()` calls into the `spawn_worker` action handler. That handler doesn't fire for admin/REST requests, so the topology console's REST schema endpoint saw an empty class registry — every application node (RequestBuilder, FlameBuilder, JobRouter, JobWorker, RemoteSource, StreamMerger, AutoTuner, HealthCheckTick) disappeared from the palette, and selecting an existing one in the editor showed "No constructor arguments. No verbs registered." These calls are `::class` constants (compile-time FQCN strings) into a static hashmap — virtually free at boot. Moved back to plugin load. The genuinely expensive deferred work (`StreamMerger::register_remote_job_rewrite_filter`, `RemoteManager::init`) stays in the `spawn_worker` closure.
- **`Topology_Registry::user_dir()` is populated in admin + REST contexts.** v0.2.30's defer left `set_user_dir()` registered only via the `newspack_nodes/topologies` filter callback and `spawn_worker` action — neither fires for an admin user saving a topology. `TopologiesController` POST then hit `user_dir()` directly and got `''`, returning 500 *"Topology_Registry has no writable user dir."* Wired the existing idempotent closure to `rest_api_init` + `admin_init`.
- **`RemoteSource::dump_node()` redacts auth credentials.** Default `Node::dump_node` reflects every property; `dump_node my_remote` from the REPL printed raw `auth_password` / `auth_token`. Override scrubs both slots to `[REDACTED]` while leaving empty credentials empty. Requires substrate v0.1.29 (override mechanism lives there).
- **`StreamMerger::process_sse_chunk()` no longer calls deleted `drain_test_queue()`.** The synthetic `__test__` RemoteSource test entrypoint had an orphaned method call left over from the queue removal; any caller would have crashed with "Call to undefined method".

### Added

- **TSL-substitution defaults on application verb args.** Mirror the active topology TSL invocations as schema defaults so the editor pre-fills the same tokens the live workers run with: FlameBuilder's `set_is_hub`/`set_auto_tune`/`set_significant_events`/`configure_stats` and StreamMerger's `set_verify_ssl`/`set_require_https`. Adding a FlameBuilder via the palette gets a working out-of-the-box config without re-typing what's already in the TSL files.

### Build

- **`00-newspack-profiler.php` ships as a standalone release asset, not bundled inside the plugin zip.** The mu-plugin file used to be in both places; the in-zip copy showed up in `wp plugin list` as a phantom `newspack-event-logger-nodes/00-newspack-profiler` and risked double-load. Now `.distignore` + `build-release.sh` exclude it from the zip and produce a standalone `release/00-newspack-profiler.php` for the Atomic-side deploy script.

### Tests

- **Coverage push: every class in `includes/` is now ≥80%** (lowest is `FlameBuilder` at 80.6%; total moved from 83.1% → 91.1% across 52 classes). New: `RemoteSourceTest` (110 methods). Existing extended: Admin, HealthCheckTick, JobWorker, StreamMerger, PartitionReader (timing-race fix), `tests/Helpers/TestCase.php` (helper now whitelists per-test temp dir in app Config too — without this, `$extras` overrides were silently rejected and tests saw bundled defaults instead).
- 8 process_sse_chunk tests + 5 drain_test_queue tests refactored or removed (RemoteSource no longer keeps an event_queue / drain_test_queue seam — refactored to assert via CaptureSink on the real production path). Same treatment applied to StreamMergerTest's SSE-parser tests via a new `entry_frame()` helper.

## [0.2.31] - 2026-05-15

### Fixed

- **`LogManager::__construct()` no longer infinite-loops via re-entrant `instance()`.** Production stack: `Core::hook_start` → `LogManager::instance()` → `__construct` → `Config::load_config` → `get_option` → `wpdb->get_results` → `apply_filters( 'query', ... )` → `Core::hook_start` (re-entry) → `LogManager::instance()` (sees null `$instance`) → new `__construct` → … 512 frames, killed by Xdebug. Triggered when an operator includes `query` in `log_events` (very reasonable for SQL profiling) and `alloptions` isn't cached yet so the first option fetch round-trips through `wpdb`. Fix mirrors the existing partial-instance pattern already in `ensure_started()`: assign `self::$instance = $this` at the top of `__construct`, before any code that can fire filters. The re-entrant `instance()` then returns the partial `$this`; `$enabled` defaults to `false` (only set true by `matches_url_filter` at the end of `__construct`), so `hook_start` short-circuits at its existing `! $lm->enabled` guard.

### Tests

- **Regression coverage for the re-entrancy bug.** `LogManagerTest::test_construct_blocks_reentrant_instance` uses a new `$_test_get_option_hook` seam in `tests/bootstrap.php`'s `get_option` stub to simulate the production wpdb→`query`-filter→`hook_start` chain deterministically. Verified failing without the fix ("two variables reference the same object"), passing with it.
- **`MemcachedCacheTest` marked class-level `#[Medium]`.** `test_live_ttl_expires_value` legitimately sleeps 2s to verify a 1s TTL elapses; the default 1s per-test budget under `--enforce-time-limit` was aborting it as risky. With `Medium` raising the budget to 10s the suite now runs 1346/1346 clean with no risky, no skipped.

### Pre-push

- **`scripts/pre-push` is now installed at `.git/hooks/pre-push` in this clone.** Composer's cghooks install had never run here, so prior pushes weren't gated by lint + container deploy + phpunit. The script itself was already in the repo; this is local-clone hygiene.

## [0.2.30] - 2026-05-15

**Requires:** [newspack-nodes ≥ v0.1.28](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.28) — for the `Config::RESET_ACTION` broadcast and the `Supervisor::$curl_exec` test seam.

### Fixed

- **App `Config` cache invalidates alongside substrate `Config`** — the actual root cause behind "only aggregator spawns when `num_partitions` changes". Both classes maintain merged-result static caches, but the supervisor's per-tick `check_config()` only ran the substrate's `Config::reset()`. The app's filter callback in `newspack_nodes/topologies` therefore kept publishing the catalog with a stale `num_partitions`, while the substrate's `synthesize_entry` for `aggregator` (operator-overlay only) saw the fresh value — producing 7 (1 + 3×2) worker descriptors instead of 8. Listener on the substrate's new `Config::RESET_ACTION` invalidates `Newspack_Event_Logger_Nodes\Config::reset_local_cache()` whenever the substrate resets; the listener does NOT call back into substrate reset (would recurse).
- **`StreamMerger::ensure_offsetlog()` was constructing `new Partition( $dir )` with only one arg.** Partition requires `($base_dir, $partition)` — this would have fataled the moment `aggregator_servers` was non-empty. Pinned the inner partition to `0` (Consumer pattern: outer `{source}.p{N}/` dir name encodes the spoke partition; inner Partition is always p0).
- **`WorkersController::read_offsetlog_position()` constructed `Partition( $dir, $partition )` looking at `firehose.p1/p1/`** when files actually live at `firehose.p1/p0/`. Same offsetlog-inner-axis-is-always-0 fix already applied to `read_offsetlog_latest_entry()` last release; method now delegates to that helper.
- **`reqgrep` and dashboards no longer reach a different memcache than workers do.** `PerformanceControllerBase::cache()` (the REST-side request-path helper) called the now-deleted `self::load_config()`; after the substrate dropped its `'full'`-mode toggle and folded `memcache_servers` into the always-loaded core schema, every Memcached_Cache build site reaches the same overlay. See `Memcached_Cache::from_substrate_config()` below.

### Changed

- **REST controllers and SSE/Remote infrastructure migrated from `self::load_config()` → `\Newspack_Nodes\Config::load_config()`** (substrate-only). Affects 14+ controllers under `includes/rest/` plus `RemoteSource`, `SSEControllerBase`, `FirehoseStreamController`. `PerformanceControllerBase::load_config()` no longer exists; subclasses that need app-merged keys call `\Newspack_Event_Logger_Nodes\Config::load_config()` explicitly (only `ServersController::test_connection` needed that path).
- **Plugin-load defer refactor: worker-only initializers moved to the `newspack_nodes/spawn_worker` action handler.** Stops every admin / REST / front-end request from paying for setup it never uses. Deferred work:
  - 8× `CommandInterpreter::register_class()` for app Node subclasses.
  - 2× `Formatters::register()` (`request-index`, `flame-index`).
  - `StreamMerger::register_remote_job_rewrite_filter()` (autoloads StreamMerger — the most expensive of the bunch).
  - `RemoteManager::init()` — verified worker-internal (hooks `job_handlers` filter consulted by JobRouter, plus an action fired from inside a job handler running in the aggregator worker; no wp-cron involvement).
  - `Topology_Registry::set_user_dir()` (needs `Bootstrap::base_dir()` which hits Config) is moved to an idempotent closure invoked from both the `newspack_nodes/topologies` filter callback and the `spawn_worker` action handler.
  - `Topology_Registry::register_stock_dir()` stays at plugin load — single array append, zero config dependency, needed by `Topology_Registry::resolve()` from any context (tests, admin, REST, CLI).
- **`Config` mode dispatching deleted** — mirrors substrate. `$option_schema_extended` (only contained `aggregator_servers`) folded into a single `$option_schema`; dual cache collapsed to single `$config`; `$mode` parameter dropped. `load_config('full')` callers throughout the plugin migrated to `load_config()`.

### Added

- **`Memcached_Cache::from_substrate_config()` factory** consolidates three near-identical `$servers = $config['memcache_servers'] ?? DEFAULT_SERVERS; if ( ! is_array( $servers ) ) ...; new Memcached_Cache( $servers )` blocks across `PerformanceControllerBase::cache()`, `SSEControllerBase::cache()`, and `RemoteSource::cache()`.

### Removed (continuation of pre-release "tree chopping")

- **`StreamMerger::set_logs_dir()` / `$logs_dir` field / `resolve_logs_dir()` helper.** Offsetlog placement now flows from `\Newspack_Nodes\Config::get_offsets_directory()` directly. Tests redirect via `$_wp_options['newspack_nodes_base_directory']` + `Config::reset()` instead of the deleted setter.

### Tests

- **`Supervisor::$curl_exec` test seam** (from substrate v0.1.28) routed through `tests/bootstrap.php`: real curl handle goes through `curl_init` + `curl_setopt_array` + errno classification unmocked; only the actual network call is swapped, with URL captured via `curl_getinfo` and POST body passed in directly as a 2nd seam arg (POSTFIELDS isn't recoverable via getinfo).
- **`Cli_Stdin_Reader::$readline_handler_install` / `$readline_read_char` seams** (from substrate v0.1.28) no-op'd in `tests/bootstrap.php` so phpunit-in-a-terminal stays clean and `fire()` doesn't block on real stdin.
- Stale `remote_firehose.log` test paths updated to the new `{offsets}/aggregator.p{N}/p0/{seg}.log` shape.
- `test_set_logs_dir_resets_offsetlog` deleted (the method it tested was the user's chop).

### Removed

- **`Config::get_option_schema_core/extended()` filter hooks.** The `newspack_event_logger_nodes_option_schema_core` / `…_extended` filter hooks were extension points no plugin used. Replaced with inline `private static $option_schema_core/extended` arrays.
- **`Config::invalidate_cache()` and `Config::register_cache_invalidation()`.** The substrate's `wp_cache_delete( 'alloptions', 'options' )` + `Config::reset()` pair (in `Supervisor::check_config` and the substrate's `Newspack_Nodes\Config`) handles option-snapshot staleness for the only consumer that needed the one-shot reset.
- **`class_exists()` guards in the plugin loader for `Topology_Registry`, `Bootstrap`, `Formatters`, and the `newspack_nodes/topologies` filter callback.** With the deferred-loader pattern (the loader closure runs on `plugins_loaded` priority 11, after the substrate plugin has fully loaded), substrate classes are always present by the time these run; the guards were dead branches.

### Changed

- **Substrate-missing branch in the plugin loader reverts to bare `\error_log(...)`.** Calling `\Newspack_Nodes\Core::print_less_often` in this branch would fatal-error — the branch only fires when `\Newspack_Nodes\Node` doesn't exist, which means `\Newspack_Nodes\Core` doesn't exist either. PHP's `error_log` is the right last-resort for this exact failure mode.

## [0.2.28] - 2026-05-14

### Fixed

- **Workers dashboard renders P1+ partition rows under their parent log.** When `num_partitions` flipped from 1 to 2, P1 worker rows came back from `WorkersController` with empty `handler` / `source` / `input_log` metadata and rendered as orphans floating outside the log groups. Root cause: `read_offsetlog_latest_entry()` was constructing the offsetlog Partition with the worker's partition number (`new Partition($dir, $partition)`), but each Consumer's offsetlog is itself a single-partition Partition with files at `{source}.p{N}/p0/0.log` regardless of which spoke partition it tracks (Consumer constructs it as `new Partition($dir, 0)`). Pinning the read-side Partition to 0 lets the controller find the offsetlog entries for any spoke partition and emit full per-row metadata.
- **Aggregator pulls every spoke partition, not just p0.** `aggregator.tsl` had `var num_partitions = 1` plus a comment claiming "always single-partition by design" — that was a default I'd written into the file with no architectural basis, then cited as if it were the design. With the frontmatter dropped and the StreamMerger ctor switched from `0` to `<partition>`, the supervisor now spawns one StreamMerger worker per partition, each pulling its own slice from each spoke. Previously partitions 1+ on a multi-partition spoke were never aggregated.

### Changed

- **Workers dashboard handler labels show node names, not PHP class names.** `request-builder` / `job-router` / `flame-builder` instead of `RequestBuilder` / `JobRouter` / `FlameBuilder`. Matches what the topology console renders for the same nodes. `WorkersController` no longer emits `target_class`; the React tree title-cases the node name for display (`Request Builder`).
- **`JobIntake` oversized-payload warnings route through `Core::stderr()`.** Drops the bare `\error_log()` call so the diagnostic actually surfaces in the cli session of a pivoted-mode operator.
- **`LogManager` truncation now stores a 1KB preview** (`['m' => substr($data_json, 0, 1000) . '...']` plus a `(truncated)` category suffix) instead of replacing the entire payload with `['truncated' => true]`. Keeps debugging context for oversize entries that LogManager has to drop. The category-suffix delimitation makes truncated rows easy to filter for in `wp nodes reqgrep`.
- **`ServerRegistry::decrypt()` no longer falls back to legacy plaintext.** The pre-encryption fallback path (return the stored value as-is + emit a one-time "legacy plaintext credential detected" warning) is gone. Stored credentials must now be encrypted (with `ENCRYPTED_PREFIX`); anything else returns empty. Operators with legacy plaintext rows get a `has_credentials: false` from the REST endpoint until they re-save the spoke through the admin form (which encrypts on save). Two test cases for the old fallback path were dropped.

**Requires:** [newspack-nodes ≥ v0.1.26](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.26) — for `Core::stderr()`.

## [0.2.27] - 2026-05-14

### Changed

- **`newspack_nodes/topologies` filter callback delegates to `Topology_Registry::synthesize_entry()`.** Drops the inline `resolve` + `frontmatter` + entry-shape dance in favor of the substrate's new shared helper, so both the app's catalog-publishing path and the substrate's admin-overlay fallback build entries from a single source of truth.
- Drops six `class_exists()` guards for same-plugin classes (`Config` × 3, `JobWorker` × 2, `StatusController`, `ServerRegistry`). With the classmap autoloader, these defended against load-order races that can't happen.

**Requires:** [newspack-nodes ≥ v0.1.23](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.23) — for `Topology_Registry::synthesize_entry()`.

## [0.2.26] - 2026-05-14

### Added

- **Flush Cache button under Settings → Event Logger Nodes → Maintenance.** Mirrors the legacy `newspack-performance-dashboards` "Clear Memcache Stats" button, now backed by the new substrate. Clicking it confirms, then rotates `Stats_Store`'s 8-char salt (every existing `evlog[:salt]:p{N}:…` key orphans instantly; TTL handles cleanup) and requests a graceful restart for every active worker via `Bootstrap::expand_workers()` + `Cli::restart_workers()` — no hardcoded topology names, so future Stats_Store consumers and operator-customized topologies are picked up automatically. The settings page surfaces a `notice-success` reporting how many worker restarts were requested.

## [0.2.25] - 2026-05-14

### Changed

- **`RemoteSource` is now a real Node class — one per enabled spoke in `ServerRegistry`.** Each `RemoteSource` owns its own cURL multi handle (registered with the substrate's `EventFramework`), one cURL easy handle, one SSE connection, and its own `{segment_id, offset}` cursor; `fill()` is a no-op (it's a source like `Tail`), and `dispatch_event()` applies the `newspack_nodes/aggregator_ingest_line` rewrite filter then sinks straight to `firehose:topic`. The class is visible in the topology console (no patron link) — operators see every spoke as `{merger}:remote:{server_id}` instead of an opaque internal map. `StreamMerger` keeps a one-way ref list to its `RemoteSource` children, drives their periodic ticks, and owns the single shared offsetlog Partition — its `commit_all()` walks the ref list and writes one combined JSONL line covering every spoke. `add_remote()` is now an orchestrator method that instantiates a `RemoteSource`, restores its position from the offsetlog, and registers it in `Core`. ~1000 lines of per-remote SSE / cURL / heartbeat / status logic moved out of `StreamMerger` into the new class; the old `$this->remotes[server_id]` flat-array layout is gone.
- **`start_periodic_tick` is no longer a `StreamMerger` verb** — it fires automatically from `name()` on first name set, same pattern `FlameBuilder` uses for its `AutoTuner` sibling. Mandatory + zero-arg + always needed in the aggregator topology, so the verb was pure boilerplate; the `cmd stream-merger:config start_periodic_tick` line is dropped from `aggregator.tsl`.
- **`add_remote` is no longer a `StreamMerger` verb** — the single-arg shape was registry-driven (only `server_id` survived to TSL while url/creds came from `ServerRegistry`), confusing in the Inspector, and redundant with `load_remotes_from_registry` for production hubs. The PHP method stays so `load_remotes_from_registry` can call it.

## [0.2.24] - 2026-05-14

### Fixed

- **Workers dashboard collapsed handlers fed by multiple Consumers into one row.** JobRouter has two upstreams in the firehose-workers-and-jobs topology — `firehose:consumer` (via Tee fan-out) and `jobintake:consumer` (direct, for >4KB jobs) — so the controller emits one row per (Consumer, target) pair. The React side keyed both the render group (`buildRenderPlan`) and the rate cache (`workerKey` for `readRates` / `prevPositionsRef`) by `(type, handler) + partition` only, which (a) packed both rows under one header showing two P0 chips side-by-side and (b) overwrote the firehose JobRouter's cursor delta with the jobintake JobRouter's (which is caught up at the tip), making the firehose row display as "R 0 B/s — stalled" while RequestBuilder on the same Consumer correctly showed ~40 KB/s. Both keys now include `worker.source`, so each (Consumer, target) renders as its own row with its own rate.

### Changed

- **`newspack_nodes/topologies` filter callback publishes the file-default catalog only.** Reads `Config::load_config_defaults()` (not `load_config('full')`), so no `get_option` lookups happen on the application side — the substrate's `Bootstrap::get_topologies()` now owns the `newspack_nodes_topologies` operator overlay. The filter is the catalog; the option is the overlay; the substrate composes them. Drops the brief `newspack_nodes/topologies_defaults` filter that lived here for the admin's `↺` chip.

## [0.2.23] - 2026-05-14

### Added

- **Remote Server Settings admin section** — ported from the legacy `newspack-event-aggregator` plugin. Three int fields under Settings → Event Logger Nodes: Remote Segment Count (2-16), Remote Segment Size (1MB-256MB), Remote Min Retention (60s-7d). Blank fields fall through to the config-file default; SettingsSync's hub→spoke fan-out remaps them to the substrate's `newspack_nodes_{num_segments,segment_size,max_lifespan}` keys on the remote. Previously these were declared in `newspack-event-logger-nodes-config.php` and consumed by `SettingsSync::SYNCED_OPTIONS`, but with no UI + no schema entry there was no way for an operator to actually set them — the sync wiring pushed nothing.
- **JobWorker eager-loads handlers in the constructor.** `load_handlers` was a TSL verb that took no args and just ran the `newspack_nodes/{job,remote_job}_handlers` filter chains — a JobWorker without handlers loaded is dead weight, so it doesn't belong as a config knob. By the time a worker's TSL is evaluated, `plugins_loaded` has fired and every registered handler filter is in place. Drops the verb from `config_verbs()` + `node_schema()`, drops `cmd job-worker:config load_handlers` from `job-workers.tsl`.

### Changed

- **`HealthCheckTick` is now an owned sibling of `StreamMerger`, not a standalone TSL node.** Same Router-TIMER hitchhike pattern FlameBuilder uses to own its AutoTuner sibling — `StreamMerger::__construct()` instantiates a HealthCheckTick, patrons it, names it `{stream-merger-name}:health-check`, and cascades through `name()` + `remove_node()`. A single `start_periodic_tick` verb on StreamMerger kicks off both timers. HealthCheckTick + AutoTuner both flip to `category: 'Hidden'` so the topology console doesn't surface them as buildable nodes (the class stays directly instantiable for tests).
- **`aggregator.tsl` drops the HealthCheckTick lines** in favor of the owned-sibling pattern. The aggregator topology is now just `StreamMerger → Topic`, with HCT implicit.
- **Config layer DRY:** all the sanitize / validate / path-guard primitives that used to be duplicated between the substrate Config and the application Config now live in `Newspack_Nodes\Config_Utils`. The application's `Config::sanitize_option` is an 18-line wrapper that handles the application-specific `aggregator_servers` case locally and delegates everything else; `load_config_file`, `validate_config_values`, `is_within`, `sanitize_string` are gone. Cuts ~210 lines from `includes/class-config.php` (623→412). Requires `newspack-nodes` 0.1.19.
- **Topology TSL files reference `<config:offsets_dir>` instead of building `<config:base_directory>/offsets/...` inline.** Substrate now derives `offsets_dir` from `base_directory` in `WorkerCliCommand` so each Consumer line in the topologies stays terse.
- **`recommended_log_events` defaults trimmed:** dropped a handful of site-specific plugin-deactivation hooks (jetpack-boost, pwa, woocommerce*, wordpress-seo, googlesitekit, wpseo_deactivate) that don't belong in a generic recommended set. Existing installs that already selected those events are unaffected — this only changes what the "Select Recommended" button populates.

### Fixed

- **Substrate `topologies` option is now honored.** The application's `newspack_nodes/topologies` filter callback was reading `$config['topologies']` from its own merged config — but because the app's `load_config` does `array_merge($substrate, $appDefaults)`, the app's file default always shadowed the substrate WP option. Checking boxes in the Topologies admin UI silently had no effect. The callback now reads `newspack_nodes_topologies` directly via `get_option`, using `false` as a sentinel for "operator has never saved" so the app file default still seeds fresh installs.

## [0.2.22] - 2026-05-13

### Added

- **More `set_state()` coverage** across the application Node subclasses so `debug_state <node> 1` surfaces meaningful events for tracing:
  - **`FlameBuilder::emit_auto_tune`** — `AUTO_TUNE_FIRED` with key + count per tune decision. Rare events that operators want to know about whenever they fire.
  - **`JobRouter::fill`** — three `DROPPED` flavors (`invalid_handler`, `non_array_params`, `oversize`) with the handler name and size where applicable. Previously these only emitted rate-limited stderr; debug_state observers see the same event with structured context.
  - **`JobWorker::run`** — `CACHE_FLUSH` on each `wp_cache_flush()` interval trip and `MEMORY_PRESSURE` on the first watermark cross (latched, doesn't re-fire each tick).
  - **`StreamMerger`** — `DROPPED` with `reason=oversize` per remote entry that exceeds `MAX_LINE_BYTES`, and `DISCONNECTED` with server + error + http code on every remote completion (cURL failure, non-200, clean close).

## [0.2.21] - 2026-05-13

### Added

- **Drag nodes to reposition, with snap-to-grid + localStorage persistence.** When the auto-layout makes a placement choice the operator doesn't like, they can drag any node to a new spot. Snaps to half-steps of the auto-layout grid (`X_STEP/2 = 120px`, `Y_STEP/2 = 55px`), anchored at the same `X_PAD`/`Y_PAD` origin the algorithm uses — every other slot is a real auto-layout slot; odd slots are half-step nudges between columns/rows. Persisted via localStorage, keyed by `newspack-nodes:topology:{topology}.p{partition}:positions` so overrides scope per worker fleet. `autoLayout` still runs every poll, so new nodes get sensible defaults and only the user-pinned ones survive. Negative grid indices allowed — a node dragged past the auto-layout origin keeps its dragged position; the viewport math handles any coordinate range. The Reset Layout chip appears in the top-right corner of the canvas (gap below LIVE, above the corner reticle) once at least one override exists; clicking clears every override for this topology+partition.

- **Pan and zoom on the canvas.** Wheel zooms with cursor as anchor (the world point under the cursor stays under the cursor), clamped to a 4x / 0.25x range. Drag on empty canvas pans the viewBox — node drags still work because pointer-down on a node `stopPropagation`s so only background drags reach the pan handler.

- **Click on empty canvas = fit-to-content.** Single click without dragging past a 3px threshold snaps the viewport to the tight bounding box of nodes + a small pad, and deselects any selected node. Replaces the Center button that briefly existed — the gesture is just "click the canvas, it does the right thing." A `dragInOnceCount` ref tracks whether nodes have moved so the fit excludes stale extreme positions on reload.

- **Per-node message rate in the bottom-left of each node card.** Replaces the former "live" label, which was redundant (LIVE in the header already says the topology is alive). Reads from the same `rateRef` the Inspector uses, driven by `rateVersion` for re-render coordination. Formats to two significant figures (e.g. `6664 /s`, `8.15 /s`, `0.42 /s`) so the text fits inside the 196px node width. Hidden below a small threshold so quiet nodes don't fill the canvas with `0 /s` noise.

- **FlameBuilder declares its full fan-out via `target()` override.** FlameBuilder writes to two destinations at runtime that don't flow through `Node::target` — `flames_sink` (injected Partition reference for flame JSONL bulk writes) and `auto-tuner` (hardcoded TO on every `emit_auto_tune` Message). `dump_metadata` only reads `$this->target`, so the topology console couldn't draw those edges. The `target()` override unions the base value with both — same pattern `RequestBuilder::target()` uses for its conditional `errors_target`. Runtime paths unchanged; `flame-builder` now shows both edges in `ls -al` and renders fan-out properly on the topology canvas.

### Fixed

- **Topology endpoint added to `skip_urls`** so the SSE stream and its companion POST don't get logged as long-running requests (the stream is a ~10-minute SSE session) polluting per-URL dashboards. Same mechanism the firehose stream already uses. A `worker_type` stamp follow-up is queued — that fix needs the LogManager-restart dance to land the env var before process_data is captured.

- **Auto-layout grid constants exported** (`X_STEP`, `Y_STEP`, `X_PAD`, `Y_PAD`) so the snap logic uses the canonical values from `utils/autoLayout.js`. Keeps "where can a node sit?" in one place.

## [0.2.20] - 2026-05-13

### Added

- **Auto-layout: no more crossing streams.** Three refinements to the layered graph drawer in `utils/autoLayout.js`:

  1. **Push-forward depth pass.** Walks nodes in decreasing-depth order and advances each to `min(target_depths) - 1` when that shifts it right. A node with no incoming but a far-away target used to sit in col 0 with a long forward edge cutting across intermediate columns (`jobintake:consumer` → `job-router` curving under `firehose:tee`). Now it sits one column before its earliest target.

  2. **Straightness tiebreaker in deconflict.** When two column-mates both want the same row, the one with more edges (in + out) whose other endpoint is at that row keeps it; the other bumps. Previously alphabetical tiebreaking would give an unrelated leaf the row and break a linear chain — `job-router → jobs:tee → jobs:partition` zigzagged because `errors:partition` alphabetically beat `jobs:tee` at row 0.

  3. **Barycenter snap (not min) in pass 2.** Pass 2 was using `Math.min(...targetRows)` to pull producers to the topmost target row, which collapsed everything to row 0 and forced pass 3 to bump column-mates in a way that re-introduced crossings. Switched to `Math.round(mean)` — a producer with one target at row 0 and one at row 1 now snaps to row 0 or 1 instead of always row 0, giving the natural barycenter ordering room to win.

  End result on a representative `firehose-workers.p0` graph: zero edge crossings, two parallel spines (`jobintake:consumer → job-router → jobs:tee → jobs:partition` on row 0, `firehose:consumer → firehose:tee → request-builder → errors:partition` on row 1), and `firehose:tee`'s fan-out to `job-router` arcs cleanly upward without crossing.

- **Inspector collapses when nothing is selected; palette hidden in v1.** The permanent "Select a node to inspect" empty state was 308px of dead pixels. The palette is a v2 edit-mode affordance (drag node types onto canvas) that's scaffolded but inert in v1. Removing both reclaims the full window width for the canvas; selecting a node re-opens the inspector via `is-inspector-open` on `.topology-app`.

- **Identity section renders real `arguments`.** The Inspector's Identity section was hardcoded to "—". `parseMetadata` was already extracting `arguments` from `dump_metadata`'s payload — now displayed with a smaller-mono variant so long Partition paths (`/tmp/.../requests.log 0 67108864 2 86400`) wrap cleanly inside the 308px column. The `MAKE_NODE` meta tag now earns its label — the section literally renders `make_node <class> <name> <arguments>`.

### Fixed

- **`stylelint` no-descending-specificity escape.** `.topology-repl__bar .topology-repl__toggle` (nested inside `&__bar`) had higher specificity than the bare `&__toggle` rule but appeared first in source order. Reordered the source so the less-specific `&__toggle, &__clear` block precedes `&__bar`. CSS output is unchanged.

### Chores

- **Locked `brainmaestro/composer-git-hooks` in `composer.lock`** for the same cghooks-installer setup determinism as newspack-nodes v0.1.17.

## [0.2.19] - 2026-05-13

### Added

- **Topology Console v2 — single-poll architecture + authoritative button state.** Replaces v1's `ls -als` (initial) + `ls -ct` (per-second) text-table polling with a single `dump_metadata` JSON poll per tick. One verb returns class, counter, sink, target(s), debug_state, arguments, **and** the new per-node counters (largest_msg_sent, bytes_read, bytes_written) in one envelope. Inspector button states — TRACE active when `debugState > 0`, CONNECT active when our session's `_repl/_output/{pid}` is in the Tee's target list — are now derived from authoritative server data on every poll, eliminating the drift risk of client-side bookkeeping.

- **Inspector Throughput section adds `lgst_msg` / `read` / `written` rows** with K/M/G byte-suffix formatting. Logic nodes show 0 (their fill overrides don't run base tracking); I/O nodes (Partition) show real numbers — `requests:partition` ~500KB written, `request-builder` ~74KB largest message on a representative install.

- **Worker uptime in the LIVE button.** Visible immediately without selecting a node — "alive" + "for how long" in one glance. Fixed 48px right-aligned slot reserves space so the LIVE button doesn't widen tick-to-tick (zero-padded seconds/minutes via the substrate verb keep the value steady at character level too). Em-dash placeholder until the first `gui:uptime` poll lands (~5s after connect). Also rendered in a dedicated Process section in the Inspector for in-context reference.

- **Process panel hooks** — SSE controller fires `uptime` every UPTIME_INTERVAL_S (5s) with KEY=`gui:uptime` alongside the per-second `dump_metadata` poll; TopologyConsole routes `gui:uptime` responses to a `uptime` state bucket via a regex match on the verb's text response.

- **Per-topology partition count** in the partition dropdown — `NewspackNodesData.topologyPartitions` is now injected from the `newspack_nodes/topologies` filter results, the same authoritative map the supervisor uses to spawn workers. Switching to a topology with fewer partitions snaps the selector back to p0 to avoid streaming from a non-existent worker.

- **Selected-node bold edge highlight** — selecting a node now applies the same `is-touched` highlight to its connected edges that hovering does, but without the `is-dimmed` fade on unconnected edges. The rest of the graph stays at full intensity so context is still readable. Hover still owns full focus mode (bold + dim) when active.

### Fixed

- **WP admin chrome wasn't being hidden.** The page's actual body class is `event-logger_page_newspack-nodes-topology`; none of the three hand-enumerated selectors matched, so #wpcontent kept its 20px padding-left, #wpbody-content kept its 65px padding-bottom, and #wpfooter stayed visible. Replaced with `body[class*="_page_newspack-nodes-topology"]` so the rule survives parent-slug renames. Console now spans flush against the collapsed admin sidebar and the right viewport edge.

- **CONNECT button toggle never flipped.** The worker's input Partition is named `_repl`, so it stamps `_repl/` onto every incoming command's FROM before CommandInterpreter sees it. `connect_node <tee>` from this SSE session therefore lands in the tee's target list as `_repl/_output/{sse_pid}`, not the bare `_output/{sse_pid}` Inspector was checking for. Now matches the stamped form.

### Changed

- **`parseLsOutput` → `parseMetadata`** for the canvas parse layer. JSON object input instead of pseudo-`ls -al` text parsing; exposes `lgstMsg` / `bytesRead` / `bytesWritten` (camelCase JS, substrate keys stay snake_case on the wire) on each node entry. 8/8 tests passing.

## [0.2.18] - 2026-05-13

### Added

- **Topology Console.** New admin page at `admin.php?page=newspack-nodes-topology` that renders any live worker's node graph as an engineering-schematic SVG canvas, fed by a long-lived SSE stream that's effectively a pivoted-REPL session over HTTP. Auto-fired `ls -als` (initial) + `ls -ct` (every second) keep the canvas in sync; the worker's CommandInterpreter response carries `KEY='gui:auto'` so frontend routes them silently to the canvas-parse path. User-typed commands carry no KEY and their responses surface in a collapsible cli-faithful transcript pane.

  Architecture: backend `TopologyStreamController` extends `SSEControllerBase`; on connect it opens the worker's input + output partitions (same paths `wp nodes cli` uses), writes a TM_COMMAND for `ls -als` and forwards every reply the worker emits to `_output/{sse_pid}` as an SSE `msg` event. Frontend uses `useTopologyStream` (callback pattern — every message handled synchronously so React state batching can't drop responses under broadcast pressure) and a Dumper-style renderer that unwraps TM_COMMAND envelope payloads, prefixes `ERROR:` on TM_ERROR variants, computes RTT for TM_PING bounces, etc.

  REPL has full cli vocabulary via a `POST .../command` companion endpoint that accepts `{type, name, arguments, to, sse_pid}` and builds the right Message envelope: `ping`/`tell`/`send`/`send_eof`/`request`/`cmd`/`<verb>` all work, plus local builtins `clear` and `debug_level`. Typing `help` prepends a `### SHELL BUILTINS ###` blurb (mirroring Perl Tachikoma's `CommandInterpreter::help` shell-then-server concatenation) before forwarding to the worker's authoritative help verb.

  Drafting-room aesthetic: paper background with 24px+96px grid overlay, ink outlines, oxide/brass/sage/cyan accents, corner alignment reticles, bottom-right title block, JetBrains Mono body + Major Mono Display brand + Workbench display fonts. Three-pane layout (palette / canvas / inspector) plus header and REPL footer. Canvas auto-layout: barycenter row ordering + snap-to-first-target second pass + deconflict pass; edges are cubic beziers with hover-highlight neighborhood (point at any node and its connected edges light up in oxide, the rest dim). Inspector surfaces `target →`, `also →`, `sink ↦`, `← from` distinctly, with `sink` populated from the new `ls -als` SINK column.

- **`RequestBuilder::target()` advertises both `errors_target` and the primary target.** Mirrors the Perl Tachikoma `RegexTee::owner` pattern — `ls -al`'s TARGET column now reflects request-builder's full fan-out (`requests:partition, errors:partition`) instead of orphaning `errors:partition` as a node with no inbound edges.

### Changed

- **Reqgrep spool stores raw lines verbatim instead of decode-mutate-re-encode.** `process_line` previously decoded each firehose envelope, extracted `VALUE` into an `$entry` array, mutated `$entry['rid']` from `Message::KEY`, re-encoded as entry-shape JSON, and stored that. Raw mode then echoed the re-encoded entry (presenting it as "raw"); formatted mode decoded again at output. That's 1 decode + 1 encode + 1 decode per line where 1 decode is enough. Now `process_line` decodes once for control flow (rid, k) and spools `$line` as-is; `output_request` decodes per line in formatted mode and unwraps envelope vs. entry shape on the fly. Raw mode now shows what was actually on disk (wire envelope for disk reads, entry-shape JSON for stdin pipes), which is what `--raw` should mean. `output_request` takes `$rid` as a parameter from callers (all four sites already have it in scope), eliminating the per-output scan-for-rid loop.

### Removed

- **`ReqgrepCommand::truncate_line_message` and `MAX_ENTRY_MESSAGE_LENGTH = 1024`.** The `m`-field truncation existed as defense-in-depth, but `LogManager` already PIPE_BUF-caps lines at 4KB and `RequestBuilder` already truncates `m` to 1KB at source for `requests.log`, so on the canonical disk path the function was a no-op for almost every line. It only ever fired on stdin-fed lines from non-canonical producers. The per-request byte cap (`MAX_BYTES_PER_REQUEST`) is the real memory-blowup defense and remains; raised it from 1MB to 10MB to give stdin-fed inputs more room. Three tests removed: `test_truncate_oversized_m_field`, `test_truncate_line_message_does_nothing_when_under_cap`, `test_truncate_line_message_skips_when_m_is_not_string`.

- **`BC-rid-in-key` back-fill fallback dropped from every wire-format consumer.** All seven marker sites (`RequestBuilder::fill`, `InflightTracker::process_line`, the firehose / errors / events SSE controllers, `reqgrep_command::ingest_line`, and the `LogManagerTest` extract helper) now read `rid` from `Message::KEY` unconditionally. Pre-v0.2.17 segments that embedded `rid` inside the inner entry are no longer recognized — the `??=` / two-branch fallback was load-bearing only while pre-cutover segments still lived on disk, and those have rolled off retention by now. `RequestBuilder::fill` simplifies from a two-branch read to a single assignment from `Message::KEY`; the other six sites change from `$entry['rid'] ??= …` to `$entry['rid'] = …` (canonical back-fill, no longer conditional). Companion comment cleanups in `LogManager::message`, `StreamMerger::forward_entry`, and `StreamMergerTest::test_forward_entry_uses_rid_as_key` drop the "old vs new segments" framing — there's only one shape now.

## [0.2.17] - 2026-05-12

### Changed

- **Wire-format KEY now carries the request id, not the URL.** `LogManager::message()` writes `Message::KEY = request_id`, drops the redundant `rid` field from the inner entry, and routes partitions by `CRC32(request_id) mod N` instead of `CRC32(request_url) mod N`. Resolves a long-standing producer asymmetry: `LogManager` had been using the bare REQUEST_URI as KEY while `Gyrobase::Log` (Perl, gyrate's writer) used the full `scheme://host/path`, so WP-side and gyrate-side entries for the same request hashed to *different* partitions and `RequestBuilder` (per-partition) never reconciled them. After this change every entry for a single request — WP lifecycle, nested gyrate render, jobs spawned from it, errors and flames emitted downstream — co-locates in one partition by construction. As a side effect, on-disk entries drop ~40 bytes per line (the duplicated `"rid":"…"`) and the partition-routing input becomes opaque (32-char base36) rather than user-influenced URL bytes.

  Co-locators (`RequestBuilder::emit_request`, `RequestBuilder::emit_error`, `FlameBuilder`'s flame-emit) now stamp `Message::KEY = rid` too, so the requests/errors/flames partitions stay co-located with their firehose partition and StreamMerger's hub-side partition write inherits the rid as well (was incorrectly `$data['url']` which never existed in our entry shape).

- **Raw logs dashboard prefixes each line with `<KEY>: ` when wire KEY is non-empty.** The dashboard already dropped the wire-format envelope and rendered just the entry JSON; surfacing the KEY makes the partition-routing key (rid for firehose / requests / errors / flames, handler for jobintake, etc.) visible without forcing the JS to decode the envelope itself.

### Compatibility

- **Pre-cutover segments keep working uniformly.** Every wire-format consumer (`RequestBuilder`, `InflightTracker`, firehose / errors SSE, raw-events listing, reqgrep, StreamMerger's hub-side ingest) back-fills `entry['rid']` from `Message::KEY` when the inner entry lacks rid — and leaves legacy entries' embedded rid alone via `$entry['rid'] ??= …`. All seven back-fill sites carry a `BC-rid-in-key` marker comment so they're easy to grep and remove once pre-cutover segments have rolled off retention. `LogManager::message()` also defensively `unset()`s any caller-supplied `rid` in `$data` so misuse (or hostile `message($k, $_POST)`) can't smuggle a fake request id — previously the explicit `rid =>` slot on the left of the `+` union overwrote user values; now the slot is empty so we strip explicitly.

  Companion change in `newspack-gyrobase` (Perl): `Gyrobase::Log::_pack_message()` writes `KEY = $requestid`, `queue_job` / `queue_whack_a_cache` / `_write_entry` stop emitting the embedded `"rid":` field. Required for the producer-side change to land coherently on hosts that run both LogManager and gyrate against the same firehose directory.

## [0.2.16] - 2026-05-12

### Fixed

- **StreamMerger rewrite of `k:"job"` → `k:"remote_job"` was silently undone for entries that carry a redundant `m.type`.** Producers that wrote `m.type='job'` alongside the entry-level `k:"job"` (PHP LogManager whack-cdn, pyrobase cron-manager, evtemplate; nuclear-gyrobase whack-a-cache + nuclear-cron) doubled up the dispatch field. StreamMerger's rewrite filter mutated only `k`, leaving the inner `m.type` stale. JobRouter then resolved the normalized `type` from `m['type'] ?? entry['k']` (line 82-84), so the stale `m.type='job'` won and the entry dispatched against the LOCAL `newspack_nodes/job_handlers` instead of `newspack_nodes/remote_job_handlers` — exactly the bug the rewrite filter exists to prevent. Aggregator hubs saw "first job rewrites, all subsequent ones revert." JobRouter now treats `entry['k']` as the canonical dispatch field uniformly across firehose and jobintake branches; the redundant producer-set `m.type` is no longer consulted (and was independently stripped from producer sites in companion releases of newspack-pyrobase, newspack-nuclear-gyrobase, and newspack-gyrobase).
- **`ServerRegistry::get_all()` resurrected just-deleted servers until `plugins_loaded`.** It read `aggregator_servers` via `Config::load_config('full')`, which caches the merged file+WP-option view at first read. Since `OPTION_KEY === 'newspack_event_logger_nodes_aggregator_servers'` ALSO lives in the option schema, the cached `aggregator_servers` already held the merged value at create-time. After a subsequent delete, the WP option went empty but the cache held the pre-delete merged view; `array_merge(stale-cache, empty-option)` returned the deleted entry. Admin UI showed the server back until the next page-level `plugins_loaded` cache reset. Switched to `Config::load_config_defaults()` (file-only, no WP-option layering) — mirrors what `is_config_server()` already does for the same reason.

### Tests

- **SettingsSyncTest aligned with `enable_aggregator` gate.** The sync gate moved from `enable_workers === true` to `enable_aggregator === true` in commit `9368e73` (intentional refactor — single operator switch for cross-site activity), but the tests still configured `enable_workers`. Tests that expected dispatch silently broke (they passed for the wrong reason on the skip-cases since both keys were missing); fail-cases that expected NO dispatch coincidentally passed. Renamed methods + config keys to match production. Also relaxed the `register_synced_settings` endpoint assertion to accept both `/v1/settings` (core options) and `/v1/performance/settings` (perf-tuning options) — the dual-endpoint shape that already shipped alongside the perf settings split.

## [0.2.15] - 2026-05-12

### Fixed

- **Request Log dashboard scroll jumped / lost place when two arriving entries shared the same `rid`.** The virtualized list keyed each `StreamRow` on `${rid}-${timestamp}` — if a worker reset and rebuilt within the same second, or an aggregator pulled a colliding rid from a spoke, two entries collapsed into one React key and the virtualizer reused a single DOM node for both rows. Subsequent unshift-then-truncate cycles then shifted entries between keyed positions, breaking smooth scroll. There's already a monotonic `entryCounterRef.current` advanced once per SSE-received entry (it was driving the even/odd row stripe); stash that value on the entry as `seq` at receive time and key the row on `entry.seq`. Guaranteed unique per mount regardless of rid/timestamp collisions. Mirrors the URL Detail view fix (which avoids the problem upstream by deduping requests by rid in the URLs controller before they reach React).

## [0.2.14] - 2026-05-12

### Fixed

- **Cron-backstop supervisor run was logging itself as a 595s `/wp-cron.php` request with no `worker_type`, counting toward global averages.** Order of operations: WP-Cron fires `newspack_nodes/supervisor` inside a `/wp-cron.php` request → LogManager initializes for that request and captures `process (start)` (no `worker_type` env var set yet) → substrate's `run_supervisor_tick` invokes `Supervisor::run()` which only sets `$_SERVER['NEWSPACK_NODES_WORKER_TYPE']='supervisor'` after LogManager has already buffered process_data → 595s tick runs to completion → LogManager finalizes a `/wp-cron.php` request that's missing `worker_type` → RequestBuilder doesn't recognize it as a worker and includes it in global stats. The self-respawn path doesn't have this bug (its REQUEST_URI matches `skip_urls` so LogManager is disabled for the parent process). Wrapped the substrate's new `newspack_nodes/before_supervisor_run` / `after_supervisor_run` actions (substrate 0.1.6) with `JobWorker::begin_job_context('newspack-nodes/supervisor')` / `end_job_context()`, mirroring the HealthCheckTick fix: a fresh LogManager builds under `/jobs/newspack-nodes/supervisor` with `worker_type='supervisor'` captured at init (the substrate sets the env var BEFORE firing the wrapping action). Requires `newspack-nodes` 0.1.6.

## [0.2.13] - 2026-05-12

### Fixed

- **`skip_default_writes` was a no-op for bool-defaulted options after the 0.2.12 strict-comparison fix.** Config-file defaults for `enable_logging`, `enable_workers`, `enable_jobs`, `enable_aggregator` are PHP `bool true/false`, but the bool sanitize_callback (`absint`) produces `int 0/1` — strict `!==` between an int value and a bool default never matched, so the filter never trimmed the options-table row even when the user's value was equivalent to the file default. Normalize a `bool` default to `int` before the strict compare; other types (int, string, array) are compared as-is. Net result: setting a bool option to its file-default value now drops the row, so subsequent file-side changes to the default actually take effect.

## [0.2.12] - 2026-05-12

### Fixed

- **Bool settings (`enable_logging`, `enable_workers`, `enable_jobs`, `enable_aggregator`) could get stuck "off" — checking the box and saving didn't take effect.** `skip_default_writes`'s `$value != $defaults[$key]` used loose comparison, so a user-saved `int 1` matched a config-file `bool true` default (`1 == true` is true loosely) → the filter called `delete_option()` and returned `$old_value` to WP, which then early-exited with "no change" → option remained at its previously-stored `0`. Switched to strict `!==` so int-vs-bool no longer collides, and changed the cleanup-side check to `$value !== $old_value` so the filter only touches the row when the value is actually changing. The filter still does its job for array-defaulted options (`log_events`, etc.); for bool-defaulted options it becomes a no-op (the options table stores `1`/`0` redundantly, which is the legacy behavior anyway).

## [0.2.11] - 2026-05-12

### Fixed

- **URL table's status-code columns showed `-` and "Errors Only" button didn't filter anything.** Both were the same bug: `PerfOverviewController::load_index()` aggregated `count_2xx/3xx/4xx/5xx` correctly from each bucket but dropped them when constructing the display shape that gets sent to React. Every URL row arrived at the table with those four fields undefined → `pct()` returned "-" for every status column, and `classified = 0 + 0 + 0 + 0` so `classified < count` was true for every URL → clicking "Errors Only" appeared to do nothing because every URL "matched". Carried the four counters through the result initializer, the per-bucket accumulator, and the final `$out[]` shape that the REST response ships.

## [0.2.10] - 2026-05-12

### Fixed

- **Aggregator hub was dispatching spoke-originated jobs against local handlers.** `StreamMerger::register_remote_job_rewrite_filter()` was defined but never called from anywhere. Without the filter active, spoke-sourced `k:"job"` lines passed through to JobRouter / JobWorker untouched and got dispatched against `newspack_nodes/job_handlers` — meaning every spoke's pyrobase-cron tick ran locally on the hub, the hub's batcache-purge for `whack-a-cache` fired against the wrong site, and the AGENTS.md "hub vs spoke routing" contract was effectively broken. Wired the registration from the aggregator topology so it runs once per spawn.

### Changed

- **`LogManager::message()` now returns `false` when `ensure_started()` fails**, with the early-return placed at the top of the function so every write path (`error`, `warning`, `info`, `start`, `complete`) inherits it from the single chokepoint. `start()`'s redundant pre-check was removed, and `start()` now examines `message()`'s return value before pushing to `$this->times` — so when the LM is disabled (skip_urls match) or shutting down, the timer stack doesn't accumulate orphan bookkeeping for a `(start)` emit that never landed.

## [0.2.9] - 2026-05-12

### Fixed

- **`HealthCheckTick` enqueues silently dropped.** The aggregator topology worker runs with `REQUEST_URI=/wp-json/newspack-nodes/v1/workers/spawn` (in the default `skip_urls` so the spawn endpoint doesn't pollute the firehose with its own request lifecycle). LogManager's URL filter therefore set `enabled=false` at construction, and the singleton's `ensure_started()` never ran `init_firehose()` — so every subsequent `message('job', …)` from HealthCheckTick returned false without writing. Wrapped the enqueue in `JobWorker::begin_job_context('health-check-tick')` so a fresh LogManager is built under `REQUEST_URI=/jobs/health-check-tick`, which clears `skip_urls` and lets the periodic sweep land in firehose.log. `end_job_context` restores the suspended parent.

### Other

- Companion fix in `newspack-pyrobase` `CronManager::run_job_now` and `newspack-nuclear-gyrobase` `CronManager::run_job_now` — same skip_urls-disables-LogManager symptom, same `begin_job_context` wrap. Those plugins ship independently; without their commits the hub's pyrobase-cron and nuclear-cron periodic ticks stay invisible too.

## [0.2.8] - 2026-05-12

### Fixed

- **`flush_every_line` debug toggle was dead.** `LogManager::__construct` read the flag from Config but `message()` never branched on it, so flipping the Debugging-section toggle had no effect. Now calls `$this->topic->flush()` after each message when set — matches legacy `LogManager::message()`'s behavior.
- **`RemoteManager::sync_all_settings()` and `queue_sync_all_settings()` silently skipped `newspack_nodes_num_partitions`.** The prefix-strip only handled `newspack_event_logger_nodes_`; substrate-prefixed keys never matched a config slot and were quietly dropped. Both methods now use the same two-prefix `foreach + break` strip as `SettingsSync::maybe_queue_static_sync` — single source of truth across all three call sites.
- **`SettingsSync::on_option_update()` (instance-mode dispatch) gated on `enable_workers`, the static path gated on `enable_aggregator`.** Split polarity meant a hub configured for aggregator fan-out could skip the instance-mode path while still firing the static one. Both paths now use `enable_aggregator === true`, the documented single operator switch.
- **REST settings controllers wrote options with autoload defaulting to `true`.** Legacy passed `false` explicitly. Restored — keeps the options-cache footprint bounded as `log_events` / `significant_events` etc. grow.

## [0.2.7] - 2026-05-12

### Fixed

- **Unchecking "Enable Performance Workers" (and "Enable Aggregator") didn't stick.** Two settings registered their `sanitize_callback` as `fn ($v) => (bool) (int) $v`, storing bool `false` in the options table. `get_option()` returns `false` for BOTH missing-option AND stored-false, so `Config::load_config()`'s `false !== $value` guard treated stored-false as "absent" and the file default (`true`) shadowed the unchecked state on the next page load. Switched both registrations to `'sanitize_callback' => 'absint'`, matching every other bool option in the plugin and the legacy `newspack-event-logger-plugins` flow.

## [0.2.6] - 2026-05-12

### Fixed

- **Worker Status "Restart" buttons returned `Invalid or missing security nonce`.** The plugin localized only `NewspackNodesData.nonce` (action `wp_rest`) but the restart endpoint checks against action `newspack_nodes_restart_worker`, and the React tree reads `NewspackNodesData.restartNonce` — neither side matched the other. Added a second `restartNonce` to `wp_localize_script` keyed to the right action.

## [0.2.5] - 2026-05-12

### Fixed

- **Periodic settings sweep silently never reached spokes for most options.** HealthCheckTick fired, `health_check` job dispatched, `RemoteManager::health_check()` ran, but `sync_all_settings()` did per-option HTTP POSTs inline from the JobWorker — and the long-running worker's cached `Config` + `ServerRegistry` singletons hid post-spawn option saves and registry mutations from the sweep. Two changes:
  - `RemoteManager::health_check()` now calls `queue_sync_all_settings($enabled_server_ids)` instead of `sync_all_settings()` inline. Each setting becomes its own `sync_setting` job (via JobIntake → jobintake.log), visible in jobs.log and dispatched independently by JobWorker. Matches the legacy `newspack-event-aggregator`'s flow.
  - `HealthCheckTick::maybe_enqueue()`, `RemoteManager::sync_all_settings()`, and `RemoteManager::queue_sync_all_settings()` now call `Config::reset()` + `ServerRegistry::get_instance()->reset_cache()` before reading their gates. Matches the explicit `reset_cache()` legacy plugin had at the top of `sync_all_settings` for exactly this scenario.

## [0.2.4] - 2026-05-12

### Fixed

- **Hub-to-spoke settings sync returned `HTTP 400` for every performance-tuning option** (`log_events`, `custom_events`, `significant_events`, `log_urls`, `skip_urls`, `auto_disable_threshold`, `auto_protect_time_threshold`, `log_memory`, `flush_every_line`). `SettingsSync` was POSTing all options to `/wp-json/newspack-nodes/v1/settings`, but that endpoint's `SettingsController` only whitelists the 4 substrate keys (`num_partitions`, `num_segments`, `segment_size`, `max_lifespan`). The 9 perf-tuning options are owned by `PerfSettingsController` at `/wp-json/newspack-nodes/v1/performance/settings`. Added `SettingsSync::PERF_ENDPOINT` and updated three call sites (`register_synced_settings`, `maybe_queue_static_sync`, `AutoTuner::persist`) to pick the right endpoint per option type.

## [0.2.3] - 2026-05-12

### Added

- **`HealthCheckTick` Node** in the aggregator topology — drives `RemoteManager::health_check()` (discovery sweep + `sync_all_settings` fan-out) on a 5-min debounce. Hitchhikes on `_router`'s TIMER like StreamMerger, enqueues a `remote_manager` health_check job through LogManager so the JobWorker handles request_id correlation and STALE_THRESHOLD drops uniformly. The cutover from `newspack-event-logger-plugins` ported StreamMerger's tick but dropped the legacy `newspack_event_logger_supervisor_periodic` listener that drove the periodic sweep — without this node, freshly-enabled aggregators never push settings to spokes.
- **Targeted full-settings sweep when a spoke is created or re-enabled.** `ServersController::create_item` (with `enabled=true`) and `update_item` (when `enabled` flips false→true) now call `RemoteManager::queue_sync_all_settings([$id])` so the new/re-enabled spoke catches up immediately instead of waiting for the next HealthCheckTick.

## [0.2.2] - 2026-05-12

### Added

- **`LogManager::flush()`** — public method that drains every materialized `Partition`'s in-memory batch to disk. The 4KB write buffer didn't disappear during the TM_STRUCT cutover; it moved down to `Partition::$batch`, and `Topic::flush()` became the drain API. But that API was only being called internally by `LogManager::suspend()` and `LogManager::finish()` — external callers (nuclear-gyrobase's request/pipe paths, pyrobase's template execution) that hand off to a subprocess writing to the same firehose had no way to drain the parent's batch before `proc_open`, so messages could land out-of-order on disk between the parent's accumulated entries and the child's appends. `flush()` is the rename-equivalent of legacy `LogManager::flush_buffer()` and restores that contract.

## [0.2.1] - 2026-05-12

### Fixed

- **Substrate "Total Log Storage" estimate was 0 MB** because nothing registered with the `newspack_nodes/num_logs` filter, so the substrate multiplied `segment_size × num_segments × num_partitions × 0`. The plugin now declares its 6 log streams (`firehose`, `jobintake`, `requests`, `errors`, `jobs`, `flames`) — each obeys the same per-partition segment geometry, so the count alone is enough for the arithmetic.

## [0.2.0] - 2026-05-11

### Removed

- **Five dead extension filters.** Each had zero registrants — they were "for extensibility" placeholders that the topology-based architecture made redundant:
  - `newspack_event_logger_nodes/log_readers` — Workers/Discovery used to look up reader I/O paths; now they read the static `WorkersController::WORKER_INPUTS` map directly. Topologies own the wiring.
  - `newspack_event_logger_nodes/log_reader_positions` — paired with `log_readers`; positions now come from the memcache live-position lookup or the on-disk offsetlog.
  - `newspack_event_logger_nodes/worker_restart_groups` — the Admin's restart map is static.
  - `newspack_nodes/firehose_logs` — `FirehoseController::get_available_logs()` returns the static topology output set directly.
  - `newspack_nodes/config` — `PerformanceControllerBase::load_config()` now returns `\Newspack_Nodes\Config::load_config('full')` merged over its documented defaults, no filter wrapper.
- **DiscoveryController's `calculate_lag` / `calculate_position_difference`.** Both depended on the deleted reader filters; lag is no longer reported in the discovery response.

### Added

- **`TestCase::use_base_dir($dir, $extras = [])` helper** replaces the per-test `add_filter('newspack_nodes/base_dir', ...)` pattern. Writes a tmp config file and points `LOCAL_NEWSPACK_NODES_CONF` at it. `$extras` lets the test add other config keys (e.g. `num_partitions`, `log_events`).
- **Permanent test config baseline** at `tests/newspack-event-logger-nodes-test-config.php`, wired via `LOCAL_NEWSPACK_NODES_CONF` in both `phpunit.xml` and `tests/bootstrap.php`.

### Changed

- **`get_live_position` cache key now includes hostname**, matching the substrate's Consumer-side change. Required for shared-memcache deployments where render1/render2/hub all write the same on-disk `{base_dir}` path; otherwise their live-cursor entries collide.

### Fixed

- **`StreamMerger::forward_entry()` writes `TM_STRUCT` with a parsed array now**, not `TM_BYTESTREAM` with a JSON string. `RequestBuilder::fill()` gates on `TM_STRUCT` (line 163 — `if (!($type & TM_STRUCT)) return`), so every spoke entry was being silently dropped: render1/render2 traffic landed in the hub's `firehose.log` but `RequestBuilder` saw it and immediately returned, so no completed-request rows were written to `requests.log`, no stats reached memcache, and the Performance Dashboard never showed admin.tucsonweekly.com URLs at all. Now matches the shape `LogManager::message()` writes locally — `TM_STRUCT`, `KEY=url`, `VALUE=parsed array`.
- **`StreamMerger::forward_entry()` now stamps `Message::TO` before sinking.** Without it, every entry message hit `_command_interpreter → _router` with an empty `TO` and the router dropped it (sent to its own null sink). Result: `stream-merger` counter advanced on every received `entry` event but `firehose:topic` counter stayed at 0 — spoke traffic looked dead from the hub's perspective even when SSE was healthy. Tail and Consumer go through `parent::fill()` which stamps via `Node::fill`; `forward_entry` was calling `$this->sink->fill()` directly and bypassed the stamping.
- **Settings save no longer clobbers the aggregator server list.** `aggregator_servers` was registered as a settings-form option but had no form input — so options.php's whitelist iteration passed `null` to `sanitize_aggregator_servers` on every save, returning `[]` and wiping the list (including config-file-defined defaults flowing through `ServerRegistry::get_all`). Match the legacy `newspack-event-aggregator` pattern: only `add_settings_field` (for display); REST CRUD owns the writes.
- **Settings save no longer creates dead WP option rows for values that already equal the config-file default.** A single `pre_update_option` filter short-circuits the write (and `delete_option`s any stale row) when the sanitized new value matches `Config::load_config_defaults()`. Keeps the options table clean and lets later changes to the config file actually take effect instead of being shadowed by a stale stored copy of the old default.
- **StreamMerger periodic tick was unwired.** `tick()` (which drives `maybe_send_heartbeat()` — the POST to the spoke's `/firehose/heartbeat` that refreshes its aggregator-slot TTL — plus `check_stale()` and `maybe_commit()`) had no scheduler attached. The docstring promised `register('TIMER', ...) or an explicit Timer node` but neither happened. So the spoke's slot TTL (30s) silently expired, the spoke's `check_sse_slot()` returned false, and the spoke closed the SSE connection. The hub saw it as `Connection closed by server` (clean cURL CURLE_OK + HTTP 200), bumped backoff, reconnected, repeat. StreamMerger now registers with `_router`'s TIMER event in a new `start_periodic_tick()` method (Router-hitchhike pattern, same as `Timer::set_timer()` with no args), called from the aggregator topology.
- **StreamMerger was hitting the legacy REST namespace.** Two hard-coded URLs in `class-stream-merger.php` (`/firehose/stream` and `/firehose/heartbeat`) still pointed at `/wp-json/event-logger/v1/...` — the legacy `newspack-event-logger-plugins` namespace — but the new plugin mounts everything under `/wp-json/newspack-nodes/v1/...`. Every SSE connect attempt and heartbeat got HTTP 404 / `rest_no_route` back, regardless of TLS / auth posture. Stale carryover from the port; fixed both URLs.
- **Aggregator topology now actually registers remotes.** `StreamMerger::add_remote()` is registry-driven (single-arg shape reads url/auth/enabled from `ServerRegistry`), but nothing in production called it — so the live worker always reported `remotes: []` and the dashboard showed every configured spoke as `disconnected / pending` regardless of what the operator put in the Servers UI. The aggregator topology now iterates `ServerRegistry::get_enabled()` after building the StreamMerger and registers each entry. Topology re-runs on supervisor restart (already triggered on every server add/update/delete via `ServersController::request_supervisor_restart`), so the in-memory remote set tracks the operator-visible list automatically.

### Changed

- **`aggregator_allow_http` renamed to `aggregator_require_https` (polarity flipped).** Both aggregator flags now share fail-closed-to-safe semantics: default `true` keeps the safe behavior on (verify SSL, require HTTPS); operators have to set the flag to literal `false` to lift the guard. The topology read becomes uniform — `true === ( $config[X] ?? true )` for both — and the StreamMerger setter is now `set_require_https( bool $require )`. Plugin not yet deployed beyond two local dev environments; no migration shim. The legacy `newspack-event-logger-plugins` keeps its `aggregator_allow_http` name unchanged.

### Fixed

- **`aggregator_verify_ssl` and `aggregator_allow_http` now actually reach the SSL handshake.** The application config-file keys were invisible to `ServersController::test_connection` (Settings UI "Test" button) and `topologies/aggregator.php` (the live StreamMerger). Both called `PerformanceControllerBase::load_config()`, which only sees substrate config + the `newspack_nodes/config` filter — it never reads `newspack-event-logger-nodes-config.php`. Switching both call sites to `\Newspack_Event_Logger_Nodes\Config::load_config('full')` (the application loader, which merges file defaults beneath WP options) closes the gap. The topology additionally calls `set_verify_ssl()` and `set_allow_http()` on the StreamMerger so the SSE pull cURL handle honors the same policy as the synchronous probes.

### Changed

- **`HookCategorizer::is_internal()` is the single source of truth for "is this our hook".** Two callers (`Core::__construct` instrumentation, `HookCategorizer::get_registered_hooks_by_category` for the registered-hooks endpoint) and the `PerfHooksAvailableController::get_available_hooks` picker now all share the same prefix check. Critically, the list now covers slash-style names (`newspack_nodes/spawn_worker`, `newspack_event_logger_nodes/log_readers`) in addition to underscore-style — slash names were leaking into the "Select Hooks to Log" modal because the inline checks only tested the underscore prefix.
- **`gated_by` accepts an array of config keys (any-of).** Single-string form still works for the common single-gate case. Spawning still requires strict `=== true` on at least one key. `firehose-workers` now uses `[ 'enable_workers', 'enable_jobs' ]` — the topology stays alive if either is set, and the topology PHP itself inspects the same flags to wire its graph one of three ways: both branches with a Tee fan-out + jobintake consumer (default), workers-only (firehose → RequestBuilder, no Tee, no jobs partition, no jobintake), or jobs-only (firehose + jobintake → JobRouter, no Tee, no RequestBuilder/requests/errors partitions).

## [0.1.1] - 2026-05-11

### Added

- `enable_aggregator` is now the single operator gate for remote-server activity — both the StreamMerger pull-side (via the `aggregator` topology's `gated_by` entry) and the `remote_manager` push-side (`SettingsSync::maybe_queue_static_sync` + `AutoTuner::persist` short-circuit when off). One switch. Strict polarity (`=== true`), default OFF — fresh installs are not hubs; operators opt in explicitly. Stored as a real PHP bool (admin sanitize callback returns `(bool) (int)` not `absint`). `Hub::is_active()` helper deleted — wasn't doing anything `enable_aggregator` couldn't do alone.
- ARCHITECTURE.md gap-fill: `Write Path: LogManager`, `InflightTracker`, `AutoTuner`, `Hub-Side Helpers: ServerRegistry / RemoteManager / Discovery`, `Configuration`, expanded `REST + React` with the SSE slot pool + heartbeat protocol, and a `CLI: wp nodes reqgrep` section.

### Changed

- **Auto-tune fanout is now a Node.** `AutoTuneHandlers` (six WP-action listeners — hub @ priority 5 + standalone @ priority 10 across three event types) is replaced with an `AutoTuner` Node that receives FlameBuilder's tuning decisions as `TM_STRUCT` messages routed via `TO=auto-tuner`. Both sides ran in the same request-workers process; the action plumbing was intra-process IPC dressed up as hooks. Now it's a straight `fill()` dispatch by `Message::KEY`. Net hook count dropped: 63 → 51 call sites; 23 add_action listeners → 14.
- **Topology fleet declared in config.** The four topologies this plugin contributes (`firehose-workers`, `request-workers`, `job-workers`, `aggregator`) used to be hardcoded in the `newspack_nodes/topologies` filter; they're now declared as data under a `topologies` key in `newspack-event-logger-nodes-config.php`. Per-site overrides can add, remove, or retarget entries without patching plugin code. Each entry supports `topology` (relative → plugin-rooted, absolute → as-is), `num_partitions` (omit to inherit substrate), `stale_timeout`, and an optional `gated_by` WP-option name for operator toggles.
- **`job-workers` stale_timeout bumped to 600s** to match the legacy `newspack-event-jobs` reader config. Job handlers (evtemplate runs, importers) can block for minutes; the 60s default would force-respawn workers mid-handler.
- **`RemoteManager::health_check()` calls `HealthCheckExtensions::process_discovery()` directly** instead of routing through the in-process `newspack_event_logger_nodes/health_check_discovery` action. The action is still fired alongside for external plugin listeners (pyrobase). `HealthCheckExtensions::init()` is gone.
- **Plugin entry uses Composer classmap autoloader.** `composer.json` declares `"autoload": { "classmap": [ "includes/" ] }`; the deferred-loader closure swaps a 50-line `require_once` chain for `vendor/autoload.php`. Classes load on first reference; admin requests no longer pay for 28 REST controller files, REST requests no longer pay for admin code.

### Fixed

- Two test fixture helpers (`PerfUrlsControllerTest::write_request_to_partition`, `RequestLogControllerTest::write_request`) emitted `mkdir(): File exists` warnings on the second fixture write into the same per-partition segment dir. Guarded with `is_dir()`.

### Removed

- `ReqgrepCommandTest::test_inflight_lru_evicts_stale_rid_as_incomplete` — had been `markTestSkipped`'d since it was written (timing-driven, `usleep( 5000 )` + `with_timed_rotation( 0.001 )`). The wiring it exercised is a 10-line closure; breakage surfaces immediately as missing `[incomplete]` markers in `wp nodes reqgrep --incomplete`. Maintenance cost of a permanently-skipped test outweighs its insurance value.

## [0.1.0] - 2026-05-10

### Added

- Initial public release. Application layer of the new event-logger, built on `newspack-nodes`.
  Replaces the legacy 10-plugin `newspack-event-logger-plugins` monorepo with a single application
  plugin plus its substrate.
  - `LogManager` — per-request firehose writer (PIPE_BUF-bound, URL-secret redaction, refuses root).
  - `RequestBuilder` — assembles complete requests from firehose lines.
  - `FlameBuilder` — flame-graph aggregator with auto-tuning (noisy / significant event detection).
  - `JobIntake` + `JobRouter` + `JobWorker` — small-job firehose pipeline + large-job intake pipeline (>4KB).
  - `StreamMerger` — pulls remote firehoses via SSE (cURL multi); hub-side `k:"job"` → `k:"remote_job"` rewrite.
  - `Stats_Store` + `StatsAggregator` — 9-namespace memcache schema with salt-rotation flush. Fail-soft on memcache failure; SSE slots fail-closed.
  - `ServerRegistry` + `RemoteManager` + `SettingsSync` — hub-side configuration of remote spokes; settings fan-out + auto-tune fan-out gated by the `enable_aggregator` operator toggle.
  - `Memcached_Cache` + `FakeMemcached` (test) both implement `Cache_Interface`.
  - React dashboards: event aggregator status, raw logs viewer, worker status, performance dashboards, gyroscope (request timeline), request log, performance settings.
  - REST controllers under `/newspack-nodes/v1/` (logger, perf-config, perf-hooks, perf-settings, servers, settings, …).
  - Topologies for `firehose-workers`, `request-workers`, `job-workers`, `aggregator`.
  - `wp nodes reqgrep` — application-aware firehose filter (recent / follow / pattern modes).
  - Admin UI: Performance Workers, Auto-Tune thresholds, Enable Workers / Enable Jobs / Enable Aggregator toggles, Remote Servers table (test / toggle / remove / add).
