# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-05-11

### Added

- `Hub::is_active()` — shared helper for "is this site acting as a hub right now?" Strict `enable_workers === true` AND `enable_aggregator` toggle. `SettingsSync` and `AutoTuner` both use it so the polarity decisions stay lock-step. Diverging is how the legacy 2.4.42 silent-fan-out bug crept in.
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
  - `ServerRegistry` + `RemoteManager` + `SettingsSync` — hub-side configuration of remote spokes; fail-closed `enable_workers === true` polarity for settings fan-out.
  - `Memcached_Cache` + `FakeMemcached` (test) both implement `Cache_Interface`.
  - React dashboards: event aggregator status, raw logs viewer, worker status, performance dashboards, gyroscope (request timeline), request log, performance settings.
  - REST controllers under `/newspack-nodes/v1/` (logger, perf-config, perf-hooks, perf-settings, servers, settings, …).
  - Topologies for `firehose-workers`, `request-workers`, `job-workers`, `aggregator`.
  - `wp nodes reqgrep` — application-aware firehose filter (recent / follow / pattern modes).
  - Admin UI: Performance Workers, Auto-Tune thresholds, Enable Workers / Enable Jobs / Enable Aggregator toggles, Remote Servers table (test / toggle / remove / add).
