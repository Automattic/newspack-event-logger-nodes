# React Tree Migration

This document records the React-side cutover from `newspack-event-logger-plugins` into this plugin (`newspack-event-logger-nodes`).

## Scope

JS source only. PHP controllers and runtime have been built out separately under `includes/`. Build infrastructure (webpack config, `package.json`) is intentionally out of scope here — to be wired up at deployment-build time.

## Source -> Target Mapping

All sources copied from `services/pyrobase/sources/newspack-event-logger-plugins/`.

| Source path                                     | Target path under `src/`     | Files |
| ----------------------------------------------- | ---------------------------- | ----- |
| `src/shared/`                                   | `src/shared/`                | 6     |
| `newspack-event-aggregator/src/`                | `src/event-aggregator/`      | 8     |
| `newspack-event-dashboards/src/`                | `src/event-dashboards/`      | 12    |
| `newspack-performance-dashboards/src/`          | `src/performance-dashboards/`| 29    |
| `newspack-performance-gyroscope/src/`           | `src/performance-gyroscope/` | 12    |
| `newspack-performance-logger/src/`              | `src/performance-logger/`    | 8     |
| `newspack-performance-request-log/src/`         | `src/performance-request-log/`| 11   |

Total: 86 files across 7 trees.

Excluded by `rsync` filters (defensive): `node_modules/`, `build/`, `vendor/`.

## REST Namespace Rewrites

The cutover replaces three REST namespace prefixes inline in JS source. 23 occurrences across 11 files were rewritten:

| Old namespace          | New namespace                  |
| ---------------------- | ------------------------------ |
| `event-logger/v1`      | `newspack-nodes/v1`            |
| `event-aggregator/v1`  | `newspack-nodes-aggregator/v1` |
| `perf-logger/v1`       | `newspack-nodes/v1/performance` (defensive — no occurrences in JS) |

In practice the JS only contained `event-logger/v1/*` (most calls — including `event-logger/v1/performance/...`) and one `event-aggregator/v1/status` call. Performance routes that previously lived under `event-logger/v1/performance/` map cleanly to `newspack-nodes/v1/performance/`, satisfying the spec target for `perf-logger/v1` rewrites.

### Files rewritten

- `src/event-aggregator/AggregatorStatus.js`
- `src/event-dashboards/RawLogs.js`
- `src/event-dashboards/WorkerStatus.js`
- `src/event-dashboards/shared/hooks/useFirehoseConnection.js`
- `src/performance-dashboards/PerformanceDashboard.js`
- `src/performance-dashboards/hooks/usePerformanceApi.js`
- `src/performance-dashboards/shared/hooks/useFirehoseConnection.js`
- `src/performance-gyroscope/shared/hooks/useFirehoseConnection.js`
- `src/performance-logger/settings/HookSelectorModal.js`
- `src/performance-request-log/shared/hooks/useFirehoseConnection.js`
- `src/shared/hooks/useFirehoseConnection.js`

## Audit

Post-rewrite grep for old namespaces returns zero hits:

```
grep -rn 'event-logger/v1\|event-aggregator/v1\|perf-logger/v1' src/
# (no matches)
```

## Notes

- Shared hooks/utils live in `src/shared/`. There used to be per-tree copies (`src/event-dashboards/shared/`, etc.) carried over from the legacy multi-plugin monorepo's `sync-shared.sh` pattern, but those were collapsed once we became a single-plugin layout — every tree now imports directly from `../shared/`.
- No PHP rewrites done in this pass: the runtime + application PHP namespaces in this repo are already authored against `newspack-nodes/v1` (see `includes/rest/class-status-controller.php`).
- No `npm run build` was run; build outputs (`build/`) are not part of this commit.

## Compact-summary schema (M1)

As of v<NEXT_VERSION> (placeholder — set by `tools/bump-event-logger-nodes-version.sh` at release time), RequestBuilder writes two new outputs in addition to its existing primary `requests.log`:

- `completed.log` — one compact JSON line per completed request. Drives the request log dashboard. Schema (HTTP-access-log style; matches legacy `requests-stream-controller::transform_line` per the M1 schema-parity audit):
  - `rid` — request id
  - `method` — HTTP method
  - `url` — URL, clipped to 2000 chars (`...` suffix when clipped)
  - `start_time` — unix timestamp (seconds; passed through from request envelope without type coercion)
  - `end_time` — unix timestamp (seconds; `start_time + duration_ms/1000`)
  - `duration_ms` — request duration
  - `status_code` — HTTP status
  - `state` — literal `complete`
  - `error_status` — `-` for none
  - `remote_addr` — client IP
  - `user_agent` — UA, clipped to 500 chars (`...` suffix when clipped)
- `gyroscope.log` — same compact `complete` rows (via Tee fan-out from `completed:tee`) PLUS periodic active-state rows emitted by `RequestBuilder`'s hidden `:flight` sibling (every `set_inflight_interval` ms, default 1000). Schema for active rows (12 fields, matches legacy `InflightTracker::get_active` per the M1 audit):
  - `rid` — request id
  - `method` — HTTP method
  - `url` — URL
  - `state` — top-of-stack hook name (`render`, `wp_head hook`, etc.; falls back to `process` when the stack has unwound)
  - `what` — current activity description (hook label, or override message)
  - `time_ms` — elapsed processing time
  - `est_ms` — estimated total (time_ms + age since last log)
  - `start_time` — request-bind timestamp (when `request` keyword arrived)
  - `last_log_ts` — last activity log timestamp
  - `lag_ms` — gap between `tracker_ts` and `last_log_ts`
  - `remote_addr` — client IP
  - `user_agent` — UA

Runaway requests (those exceeding `MAX_STACK_DEPTH`) stay visible in `inflight_snapshot` for the gyroscope dashboard's benefit — matches the Perl Gyroscope behavior. When LRU rotation eventually evicts them, the existing `evict_request` path fires with `error_status='T'` so they still land in the completed pipeline.

`requests.log` is unchanged — full request envelopes still land there for `wp nodes reqgrep`, hub StreamMerger fan-out, and any other forensic / aggregation consumers.

### Field mapping vs legacy SSE controllers

| Legacy controller | Legacy field shape | New location |
|-------------------|--------------------|--------------|
| `requests-stream-controller` | 11 `transform_line` fields | `completed.log` rows (byte-for-byte equivalent — same field list, same field values, same JSON types) |
| `gyroscope-stream-controller` (`complete_batch` event) | raw request envelopes from `InflightTracker::get_completed()` | `gyroscope.log` `complete` rows — the 11-field compact shape (same as `completed.log`). Legacy shipped untransformed envelopes; the new feed ships the canonical compact shape, which is a strict subset of what the dashboard actually consumed. |
| `gyroscope-stream-controller` (`inflight` event) | 12 `InflightTracker::get_active` fields | `gyroscope.log` `active` rows emitted by `:flight` sibling (byte-for-byte equivalent for static fields; wall-clock-derived fields within tolerance) |
| `errors-stream-controller` | unchanged | `errors.log` (unchanged) |
| `rawlogs-controller` | raw lines | any subscription tails raw lines |

The `SchemaParityAuditTest` (`tests/integration/SchemaParityAuditTest.php`) is the regression gate: it asserts every legacy field has a new-feed equivalent AND that the values match per-field (byte-for-byte for static fields, within 50ms tolerance for wall-clock-derived `time_ms`/`est_ms`/`lag_ms`). Deleting any of the legacy SSE controllers in M5 requires that test to keep passing.
