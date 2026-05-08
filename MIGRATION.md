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

- The canonical `src/shared/` tree should remain the single edit point for shared hooks/utils, paralleling the `sync-shared.sh` pattern from `newspack-event-logger-plugins`. Plugin-local copies (e.g. `src/event-dashboards/shared/`) were carried over from the source repo's pre-sync state and should be kept in sync from `src/shared/` going forward.
- No PHP rewrites done in this pass: the runtime + application PHP namespaces in this repo are already authored against `newspack-nodes/v1` (see `includes/rest/class-status-controller.php`).
- No `npm run build` was run; build outputs (`build/`) are not part of this commit.
