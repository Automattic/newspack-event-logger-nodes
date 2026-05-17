# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **9 service `CommandInterpreter` subclasses replace ~20 legacy WP REST controllers.** `Workers_CI`, `Discovery_CI`, `Status_CI`, `Settings_CI`, `Logger_CI`, `Events_CI`, `Servers_CI`, `Aggregator_CI`, `Performance_CI` — all reachable via `POST /wp-json/newspack-nodes/v1/command` with a TM_COMMAND envelope (`{type, to, from, id, value: json_encode({name, arguments, payload})}`). Performance_CI carries the heavy lift: 19 verbs replacing the entire `perf-*` controller family plus the non-streaming methods on firehose/gyroscope/request-log controllers. SSE controllers stay as REST endpoints — the command path doesn't stream. See `MIGRATION.md` for the per-CI verb reference and the M5 deletion list.
- **`VerbHarness` test fixture for unit-testing CI verbs in isolation.** Wraps `CommandInterpreter::interpret()` so each verb test reads as `harness->call('verb', $args)` instead of hand-assembling Message envelopes. Used by every M2 CI unit test.
- **M2 integration tests: `M2BootstrapTest` (substrate-hook registration) and `M2CommandDispatchE2ETest` (end-to-end `/command` dispatch).** The bootstrap test fires `newspack_nodes/request_graph_ready` and asserts all 9 CIs register under their short names. The E2E test drives an HTTP request through `Command_Controller::dispatch` for every CI's read-only smoke verb and asserts the response shape.
- **9 service CI classes registered via `CommandInterpreter::register_class()`** so `make_node()` can construct them by shell-name from the `newspack_nodes/request_graph_ready` hook.
- **M4 dashboard cutover #1 (Aggregator Status).** `AggregatorStatus.js` now dispatches `aggregator.status` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes-aggregator/v1/status')`. Same response shape (verified by the M3 schema-parity audit), same 1–10s refresh cadence, same error UX. Adds `unwrapCommandResponse` shared helper that peels the substrate's 7-field Message tuple down to the verb's payload (double-JSON-parse: outer `VALUE` → `{name, payload}`, inner `payload` → data object); throws on TM_ERROR with the payload as the error message. Browser smoke-test confirmed the partition grid renders correctly via the CI verb path.
- **M4 dashboard cutover #2 (Performance Logger).** `HookSelectorModal.js` (in the `performance-logger` tree) now dispatches `performance.hooks_registered` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes/v1/performance/registered-hooks')`. Reuses the `unwrapCommandResponse` helper introduced in cutover #1. Browser smoke-test confirmed the hook-selector modal populates all 19 categories with correct counts (Lifecycle 17/25, REST API 3/21, etc.) via the new CommandClient path. Rewrite landed in commit `08e7a34`.
- **M4 dashboard cutover #3 (Performance Gyroscope) — audit-only, no JS rewrite.** The `performance-gyroscope` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'gyroscope'})` (SSE, which stays as REST). The legacy `/gyroscope/timeline` JSON route was fully orphan — no dashboard caller in any `src/` tree. `Performance_CI.gyroscope_timeline` (added in M2 Task 12) covers the equivalent surface with 5 unit tests for any future caller. Deletion landed without a rewrite step.
- **M4 dashboard cutover #4 (Performance Request Log) — audit-only, no JS rewrite.** The `performance-request-log` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'requests'})` (SSE, which stays as REST). The legacy `/request-log/list` + `/request-log/detail/{id}` JSON routes were fully orphan — no dashboard caller in any `src/` tree. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` (added in M2 Task 12) cover the equivalent surface for any future caller. Deletion landed without a rewrite step.
- **Webpack alias `@newspack-nodes/runtime` → substrate's `src/runtime/index.js`** so dashboards can import `CommandClient`, `useNodeState`, `sse_connector` etc. with a single stable specifier. wp-scripts inlines the runtime into each dashboard bundle, so the released zip ships nothing extra. Mirror aliases added to `jest.config.js` (moduleNameMapper) and `.eslintrc.js` (import/core-modules) so tests + lint don't trip on the bare specifier.
- **`getCommandClient()` singleton factory at `src/shared/utils/commandClient.js`** — pulls `restUrl` + `nonce` from `window.NewspackNodesData` (already localized in the plugin file) and returns one CommandClient per page. Future M4 dashboards reuse this helper.

### Removed

- **Legacy `AggregatorStatusController`** (`includes/rest/class-aggregator-status-controller.php`) deleted. The event-aggregator dashboard cut over in M4 #1; the schema-parity audit found zero gaps; the browser smoke-test confirmed `Aggregator_CI.status` is byte-equivalent. The 3-route stub `AggregatorController` (`/status` + `/servers` + `/health`) stays alive for now — its `get_status()` body is inlined (no longer a delegate) and will be removed when a follow-up M4 cutover handles its sibling routes.
- **Legacy `LoggerController`** (`includes/rest/class-logger-controller.php`) deleted. The `/logger/config` and `/logger/hooks` routes had no remaining dashboard callers — JS audit across every `src/` tree found zero `apiFetch` hits to either endpoint. `Logger_CI` verbs cover the equivalent surface for any future caller.
- **Legacy `PerfHooksController`** (`includes/rest/class-perf-hooks-controller.php`) deleted. The `/performance/registered-hooks` route was the only one a dashboard hit (the M4 #2 cutover replaces that call with `performance.hooks_registered`); the sibling `/performance/hook-categories` route had no dashboard caller in any `src/` tree. `Performance_CI.hooks_registered` and `Performance_CI.hooks_categories` verbs replace both.
- **Legacy `GyroscopeController`** (`includes/rest/class-gyroscope-controller.php`) deleted. The non-streaming `/gyroscope/timeline` route was fully orphan — the `performance-gyroscope` dashboard only uses SSE (`useFirehoseConnection({endpoint:'gyroscope'})`) and never hit the JSON route. `Performance_CI.gyroscope_timeline` covers the equivalent surface. The streaming sibling `GyroscopeStreamController` (`/gyroscope/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.
- **Legacy `RequestLogController`** (`includes/rest/class-request-log-controller.php`) deleted. The `/request-log/list` + `/request-log/detail/{id}` routes were fully orphan — the `performance-request-log` dashboard only uses SSE (`useFirehoseConnection({endpoint:'requests'})`) and never hit either JSON route. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` cover the equivalent surface. The streaming sibling `RequestsStreamController` (`/requests/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.

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
