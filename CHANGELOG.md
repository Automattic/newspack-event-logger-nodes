# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
