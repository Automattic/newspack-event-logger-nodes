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

## M2 service CIs — verb reference

M2 collapses ~20 legacy WP REST controllers into 9 service-shaped `CommandInterpreter` subclasses, dispatched through a single REST endpoint (`POST /wp-json/newspack-nodes/v1/command`). Each CI mounts in the request-scope node graph; verbs are JSON-in, JSON-out closures that share the substrate's routing + error machinery instead of re-implementing it per-controller.

### Overview

Each request to `/command` arrives carrying a TM_COMMAND envelope. The substrate's `Command_Controller::dispatch()`:

1. Lazy-builds the request-scope graph (`_router`, `_command_interpreter`, `_http`) if no earlier entry point already did.
2. Fires `do_action( 'newspack_nodes/request_graph_ready', $base_ci )` (added to the substrate alongside M2; substrate commit `24921f5`).
3. Stamps `FROM=_http` on the message if the caller left it empty.
4. Routes the message through `_router`. The router peels the head off `TO` (e.g. `performance/overview` → look up `performance`, hand it the remainder) and forwards.
5. After the CI's reply walks back along `TO=FROM` to `_http`, `HTTP_Out::fill()` writes the packed Message to the HTTP response body and the controller `exit()`s.
6. If no synchronous reply landed (async/IPC case), the controller emits a 202 ack with `{queued:true, id}`.

The 9 service CIs hook `newspack_nodes/request_graph_ready` and mount themselves via `$base_ci->make_node( $type, $name, ...$ctor_args )`:

```php
function newspack_event_logger_nodes_mount_service_cis( \Newspack_Nodes\CommandInterpreter $base_ci ): void {
    $cli      = new \Newspack_Nodes\Cli( \Newspack_Nodes\Bootstrap::base_dir() );
    $registry = \Newspack_Event_Logger_Nodes\ServerRegistry::get_instance();
    $cache    = \Newspack_Event_Logger_Nodes\Memcached_Cache::from_substrate_config();

    $base_ci->make_node( 'Workers_CI',     'workers',     $cli, $cache );
    $base_ci->make_node( 'Discovery_CI',   'discovery' );
    $base_ci->make_node( 'Status_CI',      'status',      $cache );
    $base_ci->make_node( 'Settings_CI',    'settings' );
    $base_ci->make_node( 'Logger_CI',      'logger' );
    $base_ci->make_node( 'Events_CI',      'events',      $cache );
    $base_ci->make_node( 'Servers_CI',     'servers',     $registry );
    $base_ci->make_node( 'Aggregator_CI',  'aggregator',  $registry, $cache );
    $base_ci->make_node( 'Performance_CI', 'performance', $cache );
}
\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );
```

`make_node()` does three things atomically: instantiates the FQCN registered under the shell name, calls `$node->name($name)` (so the router can find it), and `$node->sink($this)` (so its reply walks back through the base CI → router → `_http`). Skipping the sink wiring — as the original M2 land did when it just called `register_class()` at `rest_api_init` priority 11 — leaves the CI registered but unwired; every reply silently drops on the floor because there's no path back to `HTTP_Out`. That was the substrate fix in commit `24921f5`.

### Browser dispatch shape

The browser POSTs a TM_COMMAND envelope as JSON. The fields are the substrate's 7-slot `Message` array, named:

```js
fetch('/wp-json/newspack-nodes/v1/command', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': nonce,
  },
  body: JSON.stringify({
    type: 8, // TM_COMMAND
    to: 'performance',
    from: '_http',
    id: 'unique-id',
    value: JSON.stringify({
      name: 'overview',
      arguments: '{}',
      payload: '',
    }),
  }),
});
```

- `type` — bitmask. `8` = TM_COMMAND. Verb dispatch routes through CommandInterpreter only when `TM_COMMAND` is set and the head of `to` matches a registered CI.
- `to` — CI name (e.g. `performance`, `workers`). The router peels the head off; the verb dispatch happens inside the CI.
- `from` — reply path. `_http` is the default response sink; pivoted IPC commands set `_http/<ssePid>` so the reply walks back to an SSE process.
- `id` — caller-chosen string used to correlate request/response pairs. The CI's reply carries the same `id`.
- `value` — the inner CommandInterpreter envelope: `{name, arguments, payload}`. `name` is the verb; `arguments` is the JSON-encoded argument blob each verb decodes; `payload` is currently unused.

Replies arrive as the same Message shape, packed back into the HTTP response body. The browser's `CommandClient` unpacks and resolves the Promise associated with the matching `id`.

### Error contract

Verb handlers `throw new \RuntimeException( $msg )` on validation/auth/lookup failures. The substrate's `CommandInterpreter::interpret()` catches and converts to a `TM_COMMAND | TM_ERROR` message with the exception's `getMessage()` payload in `VALUE`. The browser's `CommandClient` sees the `TM_ERROR` bit set and surfaces as a rejected Promise — the error message string is exactly what the verb threw.

There is no per-verb `try/catch`. Adding one breaks the contract; the central catch in `interpret()` is the single source of truth for error wrapping.

### Auth gating

- **Reads typically require `manage_options`.** Legacy parity: every `PerformanceControllerBase::read_permissions_check` route enforced the capability.
- **Mutating verbs always require `manage_options`.** Same parity for `admin_permissions_check`.
- A few verbs (Discovery_CI's `get`, Logger_CI's `config` / `hooks`) intentionally have no auth check on the CI side — the legacy controllers gated those on `read_permissions_check` purely for rate-limiting; rate-limiting moved to the transport layer.
- The REST route itself also gates on `current_user_can('manage_options')` at the `permission_callback` level, so a logged-out caller never reaches the CI dispatch.

### Verb reference

#### `workers` — Workers_CI (`includes/app/class-workers-ci.php`)

Replaces: `class-workers-controller.php`, the `heartbeat` JSON method on `class-firehose-controller.php`. Constructor takes `(object $cli, ?object $cache = null)`; production passes `\Newspack_Nodes\Cli` + `Memcached_Cache`.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `list` | `""` (empty) | JSON list of workers `[{type, partition, position, ...}]`. `position` back-filled via `Cli::live_position()` per worker. | No auth check on the CI; REST route gates `manage_options`. |
| `restart` | `{types: string[], partition?: int}` (default partition `-1` = all) | `{restarted: <Cli::restart_workers return>}` | No auth check on the CI; REST route gates `manage_options`. |
| `heartbeat` | `{slot: int, ttl?: int=10, partition?: int=-1}` | `{success: bool, slot: int}` | Touches SSE slot via `Memcached_Cache::touch_sse_slot()`. Throws if cache wasn't injected (`cache not configured`) or `slot < 0` (`slot required`). |

#### `discovery` — Discovery_CI (`includes/app/class-discovery-ci.php`)

Replaces: `class-discovery-controller.php`. No constructor dependencies — reads the substrate Config directly.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `get` | `""` | `{registered_hooks: string[], custom_events: string[]}` | Custom event names are filtered OUT of `registered_hooks` to prevent cross-contamination (legacy parity). |

#### `status` — Status_CI (`includes/app/class-status-ci.php`)

Replaces: `class-status-controller.php`. Constructor takes `(?object $cache = null)`.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `get` | `""` | `{status: 'ok', version, runtime_version, num_partitions, topologies, cache_available, timestamp}` | Cache probe wrapped in Throwable catch — a cache outage reports `cache_available=false`, never 500. |

#### `settings` — Settings_CI (`includes/app/class-settings-ci.php`)

Replaces: `class-settings-controller.php`. The substrate-level integer settings — `num_partitions`, `num_segments`, `segment_size`, `max_lifespan`. No constructor dependencies.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `get` | `""` | `{num_partitions: int, num_segments: int, segment_size: int, max_lifespan: int}` | Additive verb — legacy controller only exposed update; the matching getter is what dashboards diff against. |
| `update` | `{<any subset of the four keys>: int}` | Post-update snapshot (same shape as `get`) | Requires `manage_options`. Bounds: `1..2^30` for the first three keys; `0..2^30` for `max_lifespan` (legacy parity). Writes to `newspack_nodes_<key>` with `autoload=false`. Calls `AppConfig::reset()` so the rebuild reads fresh values. |

#### `logger` — Logger_CI (`includes/app/class-logger-ci.php`)

Replaces: `class-logger-controller.php`. No constructor dependencies — reads the substrate Config and `HookCategorizer` directly.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `config` | `""` | The full filterable substrate Config blob (value-equivalent to the legacy `/logger/config` body sans the `{data, meta}` REST envelope). | Read-only, no auth gate on the CI side. |
| `hooks` | `""` | `{hooks: [{name, category}, ...], categories: {...}}` | Flattened view of `HookCategorizer::get_registered_hooks_by_category()` joined with `get_categories()`. Internal hooks are filtered by the categorizer. |

#### `events` — Events_CI (`includes/app/class-events-ci.php`)

Replaces: `class-events-controller.php`. Powers the `event-dashboards` React tree. Constructor takes `(?Cache_Interface $cache = null)`.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `recent` | `{limit?: int=100}` (clamped to `1..1000`) | `{data: entries[], meta: {limit, scanned}}` | Newest-first walk of the firehose index across all partitions. Each entry gets `rid` (from `Message::KEY`) and `_partition` back-filled. Walk caps at `MAX_INDEX_ENTRIES = 100000`. |
| `stats` | `""` | `{data: {time_series: [{hour, count, sum_ms, sum_peak_mb}, ...]}, meta: {}}` | Merge of per-partition hourly buckets via `Stats_Store::get_hourly()`. Fail-soft: memcache outage returns empty `time_series`. |

#### `servers` — Servers_CI (`includes/app/class-servers-ci.php`)

Replaces: `class-servers-controller.php`. Hub-side server registry. Constructor takes `(ServerRegistry $registry)`. Test seam: `Servers_CI::$http_call` is a `?\Closure` defaulting to `\wp_remote_get` (see `~/.claude/rules/test-seams.md`).

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `list` | `""` | Map keyed by id of `{id, url, enabled, logs, has_credentials, is_config}` | No auth gate on the CI; REST route gates `manage_options`. |
| `get` | `{id: string}` | Single public-shape server record. Throws if not found. | No auth gate on the CI. |
| `add` | `{id, url, auth_username?, auth_password?, enabled?=true, logs?=['firehose.log']}` | `{id}` | Requires `manage_options`. Throws on bad id format (`ServerRegistry::is_valid_id`), duplicates, non-HTTPS URL, or `MAX_SERVERS` overflow. Triggers supervisor-restart flag write; queues full settings sync if enabled. |
| `update` | `{id, url?, auth_username?, auth_password?, enabled?, logs?}` | `{id}` | Requires `manage_options`. Partial update — missing keys untouched. Targeted full-settings sync when `enabled` flips `false → true`. |
| `delete` | `{id: string}` | `{id}` | Requires `manage_options`. Throws on config-file servers (not deletable via API). Triggers supervisor restart. |
| `test` | `{id: string}` | `{id, status: 'connected', response: {registered_hooks, custom_events, lag}}` | Requires `manage_options`. Synchronous HTTP probe of the remote's `/wp-json/newspack-nodes/v1/discovery` with stored Basic Auth (5s timeout). Whitelists response fields — never proxies arbitrary remote JSON. |

#### `aggregator` — Aggregator_CI (`includes/app/class-aggregator-ci.php`)

Replaces: `class-aggregator-controller.php`, `class-aggregator-status-controller.php`. The legacy `AggregatorStatusController` was the canonical `/status` implementation; the stub in `AggregatorController::get_status()` was a thin delegator. Constructor takes `(ServerRegistry $registry, ?object $cache = null)`.

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `status` | `""` | Map keyed by server id of `{id, url, enabled, partitions: {0: {...}, 1: {...}, ...}}`. Per-partition memcache lookup of `aggregator_status:{id}:p{N}`; cache misses fall back to `[]`. | Requires `manage_options`. Partition count clamped to `1..16`. |
| `health` | `""` | `{healthy: true, cache: bool, timestamp: int}` | Requires `manage_options`. Cache probe wrapped in Throwable catch — `cache=false` on outage, never 500. |
| `servers` | `""` | **Sequential array** of `{id, url, enabled, logs, has_credentials, is_config}`. | Requires `manage_options`. Returns a list (not a map) — the React aggregator tree depends on the sequential shape; don't switch to a keyed map. Same field set as `servers/list` otherwise. |

#### `performance` — Performance_CI (`includes/app/class-performance-ci.php`)

Replaces: `class-perf-overview-controller.php`, `class-perf-urls-controller.php`, `class-perf-requests-controller.php`, `class-performance-controller.php`, `class-perf-hooks-controller.php`, `class-perf-hooks-available-controller.php`, `class-perf-config-controller.php`, `class-perf-settings-controller.php`, the `logs`+`status` JSON methods on `class-firehose-controller.php`, the timeline JSON method on `class-gyroscope-controller.php`, and all of `class-request-log-controller.php`. Constructor takes `(?Cache_Interface $cache = null)`.

**Every verb requires `manage_options`** (legacy parity — every replaced controller enforced it).

| Verb | Args | Returns | Notes |
|------|------|---------|-------|
| `overview` | `""` | The legacy `/perf/overview` payload. | Stats fail-soft on memcache outage. |
| `urls` | `{sort?='count', order?='desc', limit?=50, offset?=0, search?='', server?=''}` | `{data: [...], total, limit, offset}` | `sort` whitelist: `count`, `url`, `avg_ms`, `min_ms`, `max_ms`, `p95_ms`, `avg_peak_mb`, `last_updated` (out-of-whitelist falls back to `count`). `limit` clamped `1..1000`; `offset` clamped `0..10000`. Order whitelist: `asc`, `desc`. |
| `url_detail` | `{hash: string}` (`^[a-f0-9]{8,64}$`) | `{stats, requests, aggregate_flame, aggregate_profiles, last_modified}` | Throws on bad-format hash or unknown hash. |
| `request_search` | `{rid: string}` | Index-entry shape for the matched partition. Throws if not found. | Walks all partitions until first hit; caps at `MAX_INDEX_ENTRIES = 100000`. |
| `request_detail` | `{rid: string, partition: int}` | Full request envelope. Throws if not found or partition OOB. | Pre-located via `request_search`; this verb just fetches the bytes. |
| `timing` | `""` | `{time_series: [...]}` | Merged hourly buckets across partitions (legacy `data + meta` wrapper dropped). |
| `dashboard` | `""` | `{overview: {...}, urls: [...]}` | One-shot: `load_index` runs once; both `overview` and `urls` derive from the same fan-out. |
| `hooks_registered` | `""` | `{total_hooks, categories, hooks_by_category}` | `total_hooks` is recomputed (sum across categories), not trusted from the categorizer. |
| `hooks_categories` | `""` | `{categories, config}` | Plus `HookCategorizer::get_merged_config()`. |
| `hooks_available` | `""` | `{hooks: [...]}` | Walks `$wp_actions` + `$wp_filter`, excludes internal Event Logger hooks (avoid instrumenting our own instrumentation loop) and operator-marked custom events. |
| `hooks_configure` | `{hooks?: string[], custom_events?: string[]}` | `{success: true, hooks_configured: int}` | `hooks` writes `newspack_event_logger_nodes_log_events` as a sanitized flat list; `custom_events` writes `..._custom_events` as `{event: true}` map. Calls `AppConfig::reset()` so the next verb sees the fresh state. |
| `config_get` | `""` | `{config: {log_events, custom_events, log_urls, skip_urls, auto_disable_threshold, auto_protect_time_threshold, significant_events, log_memory, flush_every_line}}` | Reads from `AppConfig::load_config()` (legacy bug fix — the legacy controller read from `RuntimeConfig` but the keys live under the app prefix). |
| `config_update` | `{<any subset of CONFIG_MAP keys>: <coerced value>}` | `{success: true, updated: string[]}` | Bulk write for the 9 perf-tuning options. Missing/null keys are no-ops (legacy parity). Type coercion per `CONFIG_MAP[key].type` (`array_assoc`, `array_bool`, `int`, `float`, `bool`). |
| `settings_update` | `{option: string, value: mixed}` | `{option: string, updated: bool}` | Single-option write — `option` must be in `SETTINGS_OPTIONS` whitelist. Bounds: int `0..2^30`, float `0..86400`, arrays max 10000 elements at depth 5. Wraps `update_option` in `SettingsSync::suppress_sync()` so a remotely-synced setting doesn't bounce back as re-sync. |
| `firehose_logs` | `""` | `[{key, label}, ...]` flat list of available log files. | Static catalog: `firehose`, `jobs`, `jobintake`, `requests`, `errors`, `flames`. SSE stream picker also reads this. |
| `firehose_status` | `{log?: string}` | `{log_id, log_file, num_partitions, partitions: [{segments, segment_count, size}, ...], total_segments, total_size}` | Unknown/missing `log` falls back to the first available (matches legacy `FirehoseController::sanitize_log_param`). |
| `gyroscope_timeline` | `{request_id?: string}` | `{data: {request_id, events}, meta: {scanned}}` | Empty `request_id` returns the canonical `{events:[]}` stub (initial-state shape for the React tree before a request is selected). Otherwise: walks `requests.log` across partitions. |
| `request_log_list` | `{limit?: int=100}` (clamped `1..1000`) | `{data: entries[], meta: {limit, scanned}}` | Fans out across partitions, sorts by `timestamp DESC`, slices to limit. |
| `request_log_detail` | `{id: string}` | `{data: {request_id, entries}, meta: {scanned}}` | Empty `id` throws `'id required'`. Unknown-but-non-empty id returns `entries: []` (NOT 404 — the React tree polls these and "expected to exist soon" is a normal state). Body with no `events` key is wrapped as a single entry. |

That's 19 verbs total for `performance`.

### Legacy controllers (deletable in M5)

The legacy REST controllers continue to register their routes alongside the CIs through M2 — both paths are live during the cutover. Once a dashboard switches over to `/command` dispatch, its corresponding legacy controller is deletable; sweep happens in M5.

| CI | Legacy controllers replaced |
|----|------------------------------|
| `workers` | `class-workers-controller.php`, `class-firehose-controller.php::heartbeat` (the JSON method only) |
| `discovery` | `class-discovery-controller.php` |
| `status` | `class-status-controller.php` |
| `settings` | `class-settings-controller.php` |
| `logger` | `class-logger-controller.php` |
| `events` | `class-events-controller.php` |
| `servers` | `class-servers-controller.php` |
| `aggregator` | `class-aggregator-controller.php`, `class-aggregator-status-controller.php` |
| `performance` | `class-perf-overview-controller.php`, `class-perf-urls-controller.php`, `class-perf-requests-controller.php`, `class-performance-controller.php`, `class-perf-hooks-controller.php`, `class-perf-hooks-available-controller.php`, `class-perf-config-controller.php`, `class-perf-settings-controller.php`, `class-firehose-controller.php` (logs + status JSON methods only), `class-gyroscope-controller.php` (timeline JSON method only), `class-request-log-controller.php` (full) |

**SSE controllers stay.** The CommandInterpreter dispatch path doesn't stream — it's request/response only. These remain as REST controllers:

- `class-firehose-stream-controller.php`
- `class-gyroscope-stream-controller.php`
- `class-errors-stream-controller.php`
- `class-requests-stream-controller.php`
- `class-rawlogs-controller.php`

`PerformanceControllerBase` and `SSEControllerBase` also stay — they're shared parents for the surviving SSE controllers. The legacy-but-shared FirehoseController and GyroscopeController files contain BOTH non-streaming methods (replaced by Performance_CI verbs) AND SSE methods (kept). Sweep order in M5: delete the non-streaming methods, leave the SSE methods, then collapse the file if everything left is SSE.

### M4 hand-off

Dashboards currently make per-resource REST calls — `/wp-json/newspack-nodes/v1/performance/overview`, `/wp-json/newspack-nodes/v1/servers`, etc. M4 cuts each over to a single unified `/command` POST. Per-dashboard hook should look like:

```js
const { data, error } = useCommand( 'performance', 'overview', {} );
// vs the legacy:
const { data, error } = useRest( 'performance/overview' );
```

SSE remains separate (`useEventSource('firehose-stream')` etc.) since the command path doesn't stream. The 5 streaming endpoints above are M4-noops.

`CommandClient` (shared) handles the envelope assembly, nonce, and Promise correlation by `id`. Dashboards do not assemble the TM_COMMAND envelope themselves — the example in the "Browser dispatch shape" section above is the wire shape, not the dashboard-facing API.

### M4 dashboard cutovers — running log

Each row records a dashboard cutover from per-resource REST to the unified `/command` endpoint. A cutover lands in three commits (rewrite → verify → delete) so any one can be reverted independently. Schema-parity audit must confirm zero gaps before the deletion commit lands.

| # | Dashboard | Rewrite commit | Deletion commit | Legacy controller removed |
|---|-----------|----------------|-----------------|---------------------------|
| 1 | `event-aggregator` | `1350303` | `244eb7c` | `class-aggregator-status-controller.php` |
| 2 | `performance-logger` | `08e7a34` | `0df15ca` | `class-logger-controller.php`, `class-perf-hooks-controller.php` |
| 3 | `performance-gyroscope` | — (audit-only) | `728063e` | `class-gyroscope-controller.php` |
| 4 | `performance-request-log` | — (audit-only) | `5b8093d` | `class-request-log-controller.php` |
| 5 | `event-dashboards` | `a4ca852` | `2a0f2f7` | `class-workers-controller.php`, `class-firehose-controller.php` |
| 6 | `performance-dashboards` | `343ec01` | `b96c320` | `class-perf-overview-controller.php`, `class-perf-urls-controller.php`, `class-perf-requests-controller.php`, `class-performance-controller.php` |
| 7 | `topology-console` (substrate) | `05403b1` | `895ab89` | `class-classes-controller.php`, `class-layouts-controller.php`, `class-topologies-controller.php` |

A `—` in the "Rewrite commit" column means the dashboard required no JS rewrite — its data path was already streaming-only (SSE via `useFirehoseConnection`), and the legacy JSON route was a fully orphan sibling whose deletion needed only the schema-parity audit + a gate test. The streaming controller (`class-gyroscope-stream-controller.php`) stays alive — CommandInterpreter dispatch is request/response only.

Row 7 is the final M4 cutover and lives in the **substrate** (`newspack-nodes`), not this plugin — both rewrite + deletion commits are on the `js-nodes-m1` branch of `newspack-nodes`. The row is logged here for completeness so the M4 running log covers all 7 dashboards in one place. **M4 is COMPLETE** with this cutover: ~30 `apiFetch` calls migrated, 14 legacy REST controllers deleted (11 here + 3 in the substrate), reusable `getCommandClient()` + `unwrapCommandResponse()` helpers established in `src/shared/utils/` in both repos. The pivoted-REPL POST (`TopologyStreamController`) + 5 SSE controllers stay alive — `CommandInterpreter` dispatch is request/response only. Next: M5 — schema-parity verification + final SSE-controller deletion pass.

Cutover #6 is the largest cutover so far: 9 `apiFetch` calls cut to 5 verbs (`overview`, `urls`, `url_detail`, `request_search`, `request_detail`) across `usePerformanceApi.js` and `PerformanceDashboard.js`, and 4 controllers deleted in one batch. The M2 schema-parity audit found real gaps in `Performance_CI.overview` (missing `categories`/`breakdown`/`server` args and the response fields `global_leaderboard` / `category_time_series` / `breakdown_time_series` / `breakdowns`) and `Performance_CI.url_detail` (missing `stats.time_series` / `breakdown_time_series` / `category_time_series`); 11 new tests in `PerformanceCITest.php` covered the gap before the rewrite. `PerformanceController` had zero JS callers but delegated to two of the deleted dimension-specific controllers, so it joined the batch — leaving it behind would have been a runtime fatal.

Helpers introduced along the way and reused by subsequent cutovers:
- `src/shared/utils/commandClient.js` — `getCommandClient()` singleton factory.
- `src/shared/utils/unwrapCommandResponse.js` — peels the substrate's 7-field Message tuple to the verb's payload, throws on TM_ERROR.
- `src/shared/hooks/useFirehoseHeartbeat.js` — cut over in M4 #5's rewrite (`a4ca852`) to dispatch `workers.heartbeat` via `getCommandClient().send()` instead of `apiFetch('/firehose/heartbeat')`. This retroactively completes the SSE-side cutover for dashboards #3 (`performance-gyroscope`), #4 (`performance-request-log`), and #6 (Errors Stream) — all three drive their slot heartbeats through this shared hook, and the JS-rewrite step that would otherwise have landed in each of those cutovers happened here instead. Their SSE controllers themselves stay as REST endpoints.

`Workers_CI.dump_metadata` (commit `739ac13`) is the heavy lift behind M4 #5 — a single fat verb that replaces `WorkersController::get_workers()` field-for-field including the `logs[]` enumeration and the per-Consumer `inputs_status` / `outputs_status` arrays. The WorkersController's restart POST route maps to `Workers_CI.restart`, and `FirehoseController`'s three routes split across the two CIs (`/logs` → `Performance_CI.firehose_logs`; `/heartbeat` → `Workers_CI.heartbeat`; `/status` → `Performance_CI.firehose_status`). `RawlogsController` previously called `FirehoseController::get_available_logs/get_default_log` statically for `sanitize_log_param`; inlined the 6-entry catalog as a `private const` so the SSE controller doesn't depend on a deleted sibling.
