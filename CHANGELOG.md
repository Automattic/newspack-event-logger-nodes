# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
