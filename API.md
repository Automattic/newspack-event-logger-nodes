# Newspack Event Logger Nodes REST API

REST endpoints registered by the application plugin. The runtime substrate (`newspack-nodes`) only ships the worker spawn endpoint — see [`../newspack-nodes/API.md`](../newspack-nodes/API.md).

Two namespaces:

- `newspack-nodes/v1/*` — core dashboards, status, performance, events, gyroscope, logger, request-log, request CRUD, workers, firehose admin/SSE, servers, settings, discovery.
- `newspack-nodes-aggregator/v1/*` — hub-side aggregator status, server registry, health.

## Authentication

All endpoints inherit `PerformanceControllerBase::read_permissions_check()` which requires `current_user_can( 'manage_options' )`. Returns `403 Forbidden` (`rest_forbidden`) on insufficient permissions.

## Rate Limiting

`PerformanceControllerBase::check_rate_limit()` provides fixed-window rate limiting backed by memcache:

- Default: 600 requests per 60-second window.
- Window edges floor `time()` to the window length, so all callers in the same wall-clock window share a counter.
- Identity key: logged-in users key on user id; anonymous fall back to a hashed `REMOTE_ADDR`.
- Returns `429 Too Many Requests` (`rate_limit_exceeded`) with `Retry-After`-style hint when exceeded.
- **Fail-open** if memcache is unreachable — the system stays reachable rather than blocking on a degraded sidecar.

## Status

```
GET  /wp-json/newspack-nodes/v1/status
```

Health probe. Returns `200` with the active plugin version, runtime version, partition count, hub flag, and cache reachability.

### Response

```json
{
  "status": "ok",
  "version": "0.1.0",
  "runtime_version": "0.1.0",
  "num_partitions": 4,
  "enable_workers": false,
  "cache_available": true,
  "timestamp": 1715300000
}
```

## Performance

```
GET  /wp-json/newspack-nodes/v1/performance/dashboard
GET  /wp-json/newspack-nodes/v1/performance/timing
GET  /wp-json/newspack-nodes/v1/performance/overview
GET  /wp-json/newspack-nodes/v1/performance/urls
GET  /wp-json/newspack-nodes/v1/performance/urls/{hash}
GET  /wp-json/newspack-nodes/v1/performance/requests/{rid}?partition=...
GET  /wp-json/newspack-nodes/v1/performance/requests/search/{rid}
GET  /wp-json/newspack-nodes/v1/performance/workers
POST /wp-json/newspack-nodes/v1/performance/workers/restart
GET  /wp-json/newspack-nodes/v1/performance/registered-hooks
GET  /wp-json/newspack-nodes/v1/performance/hook-categories
GET  /wp-json/newspack-nodes/v1/performance/hooks/available
POST /wp-json/newspack-nodes/v1/performance/hooks/configure
GET  /wp-json/newspack-nodes/v1/performance/config
POST /wp-json/newspack-nodes/v1/performance/config
POST /wp-json/newspack-nodes/v1/performance/settings
```

Performance-dashboards tree, served by the dedicated overview/urls/requests/workers/hooks controllers. `/performance/dashboard` and `/performance/timing` are kept as the backward-compatible composite shape consumed by older dashboard code.

### `/performance/dashboard` response

Composed from `PerfOverviewController` (overview) + `PerfUrlsController` (top URLs).

```json
{
  "data": {
    "overview": { /* PerfOverviewController body */ },
    "urls":     [ /* PerfUrlsController data[] */ ]
  },
  "meta": []
}
```

### `/performance/timing` response

Hourly time series merged across partitions.

```json
{
  "data": {
    "time_series": [
      { "hour": "2026-05-08-12", "count": 12345, "sum_ms": 67890.5, "sum_peak_mb": 1234.5 }
    ]
  },
  "meta": []
}
```

### `/performance/overview` request

| Param | Type | Default |
|-------|------|---------|
| `breakdown` | string (comma-separated `status,method,server,country,from,ua,ja4`) | — |
| `server` | string | — |
| `categories` | bool | false |

Single-dim `breakdown` returns `breakdown_time_series` flat; multi-dim returns `breakdowns` keyed by dim.

### `/performance/overview` response (shape)

```json
{
  "total_urls": 200,
  "total_requests": 12345,
  "global_avg_ms": 45.2,
  "global_avg_peak_mb": 8.1,
  "slowest_urls": [ /* top 10 by p95_ms */ ],
  "most_requested": [ /* top 10 by count */ ],
  "aggregate_time_series": [ /* hourly across partitions */ ],
  "global_leaderboard": { /* category sums */ }
}
```

### `/performance/urls/{hash}` response

Path param: `hash` matches `[a-f0-9]{8,64}`. Returns per-URL flame/profile data plus dimensional breakdowns when `?categories=1` or `?breakdown=...` is set.

### `/performance/requests/{rid}` response

Path param: `rid` matches `[a-zA-Z0-9_-]{1,128}`. Required query: `partition` (must be valid for the configured `num_partitions`). Scans the requests index for the rid, reads the full request body, merges flame data when found.

`/performance/requests/search/{rid}` returns just `{rid, partition, url_hash}` so the dashboard can deep-link without scanning every partition.

### `/performance/workers` response

Per-worker status from `Bootstrap::expand_workers()` plus live cursor positions (memcache `np:pos:{path}:p{N}` → on-disk offsetlog fallback). Includes segments, total size, bytes-behind, started_at, heartbeat age, restart_pending flag, and per-input / per-output segment status. The supervisor (a singleton, not a partition fleet) is returned in its own top-level `supervisor` field.

### `/performance/workers/restart` request

POST with `manage_options` capability + valid `nonce` (action `newspack_nodes_restart_worker`). Trips `Lock::request_restart_at()` on the target lock dir — workers see the flag on their next 250ms tick and exit cleanly.

| Param | Type | Default |
|-------|------|---------|
| `type` | string | `all` |
| `partition` | int | 0 |
| `all_partitions` | bool | false |
| `nonce` | string (required) | — |

### `/performance/config` (GET / POST)

GET returns the 9 performance-tuning options as a flat `config` block. POST writes any subset of them in one round-trip.

### `/performance/settings` (POST)

Single-option writer for the same 9-option set. Suppresses `SettingsSync` fan-out around the underlying `update_option()` so applying a remotely-synced setting on a spoke doesn't bounce back as a re-sync.

### `/performance/hooks/available` (GET)

Sweeps `$wp_actions` + `$wp_filter` and returns the categorized hook list (via `HookCategorizer`). Custom events are filtered out of the list since they live on a separate config key.

### `/performance/hooks/configure` (POST)

Persists `log_events` and `custom_events` in one call. Triggers `Config::reset()` so the next read sees the new values.

## Events

```
GET  /wp-json/newspack-nodes/v1/events/recent
GET  /wp-json/newspack-nodes/v1/events/stats
```

Event-dashboards tree (Raw Logs viewer).

### `/events/recent` request

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `limit` | int | 100 | 1..1000 |

Walks the firehose `.idx` newest-first across all partitions, capped at `MAX_INDEX_ENTRIES = 100000` so a missing-rid scan can't escalate into a partition-wide segment walk.

### `/events/recent` response

```json
{
  "data": [
    { /* entry hash from VALUE; "_partition" key added per entry */ }
  ],
  "meta": { "limit": 100, "scanned": 4321 }
}
```

### `/events/stats` response

Hourly time series merged across partitions (same shape as `/performance/timing`).

## Firehose

```
GET  /wp-json/newspack-nodes/v1/firehose/logs
GET  /wp-json/newspack-nodes/v1/firehose/status?log=...
POST /wp-json/newspack-nodes/v1/firehose/heartbeat
GET  /wp-json/newspack-nodes/v1/firehose/stream            (SSE)
GET  /wp-json/newspack-nodes/v1/firehose/rawlogs           (SSE)
GET  /wp-json/newspack-nodes/v1/firehose/errors            (SSE)
GET  /wp-json/newspack-nodes/v1/firehose/gyroscope         (SSE)
GET  /wp-json/newspack-nodes/v1/firehose/requests          (SSE)
```

`/firehose/logs` lists the registered log catalog (firehose / jobs / jobintake / requests / errors / flames; extensible via the `newspack_nodes/firehose_logs` filter). `/firehose/status` returns per-partition segment metadata for one log. `/firehose/heartbeat` is the client-to-server keepalive POST that refreshes an SSE slot.

### SSE operational discipline

- 10 memcache slots per stream type. New connections fail with **HTTP 429** when full (not 503).
- TWO heartbeats: server-to-client SSE `heartbeat` events every 5s in-stream; client-to-server keepalive POSTs to refresh the slot (`SLOT_TTL_BROWSER=10s`, `SLOT_TTL_AGGREGATOR=30s`).
- `flush_if_needed()` before sleeps, NOT per-event. Per-event flushing tanks throughput on TLS/proxy paths.
- 1-hour `MAX_RUNTIME` per connection (client reconnects after timeout).

## Gyroscope

```
GET  /wp-json/newspack-nodes/v1/gyroscope/timeline?request_id=...
```

Performance-gyroscope tree. Synchronous timeline-snapshot fetch (used when a client wants the current state of an explicit request id). The SSE streaming counterpart lives at `/firehose/gyroscope`.

### Request

| Param | Type | Required |
|-------|------|----------|
| `request_id` | string | no |

### Response

When `request_id` provided, walks the requests index for the rid, reads the body, returns its `events` field:

```json
{
  "data": {
    "request_id": "abc123",
    "events": [ /* lifecycle events for this rid */ ]
  },
  "meta": { "scanned": 42 }
}
```

When not provided (empty initial shape):

```json
{
  "data": { "events": [] },
  "meta": []
}
```

## Logger

```
GET  /wp-json/newspack-nodes/v1/logger/config
GET  /wp-json/newspack-nodes/v1/logger/hooks
```

Performance-logger settings tree. `/logger/config` returns the full filterable config (via `newspack_nodes/config`); `/logger/hooks` returns a flat list of categorized hooks via `HookCategorizer`. Settings POST + hook configuration live on `/performance/config`, `/performance/settings`, and `/performance/hooks/configure`.

### `/logger/config` response

Echoes `PerformanceControllerBase::load_config()` (the result of the `newspack_nodes/config` filter):

```json
{
  "data": {
    "num_partitions": 1,
    "num_segments": 8,
    "segment_size": 16777216,
    "max_lifespan": 86400,
    "memcache_servers": ["127.0.0.1:11211"],
    "base_directory": "/tmp/newspack-nodes",
    "enable_workers": false,
    "aggregator_servers": []
  },
  "meta": []
}
```

### `/logger/hooks` response

```json
{
  "data": {
    "hooks": [
      { "name": "wp_loaded", "category": "core" }
    ],
    "categories": { /* category metadata */ }
  },
  "meta": []
}
```

## Request Log

```
GET  /wp-json/newspack-nodes/v1/request-log/list
GET  /wp-json/newspack-nodes/v1/request-log/detail/{id}
```

Performance-request-log tree.

### `/request-log/list` request

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `limit` | int | 100 | 1..1000 |

Walks the requests index across all partitions, returns each entry's summary fields (`rid`, `url_hash`, `timestamp`, `duration_ms`, `status_code`, `peak_mb`, `method`, `error_status`, `partition`). Capped at `MAX_INDEX_ENTRIES = 100000`.

### `/request-log/list` response

```json
{
  "data": [ /* entries */ ],
  "meta": { "limit": 100, "scanned": 4321 }
}
```

### `/request-log/detail/{id}` response

Path param: `id` matches `[A-Za-z0-9_-]+`. Walks the index for the rid; on hit, reads the request body and returns its events.

```json
{
  "data": {
    "request_id": "abc123",
    "entries": [ /* lifecycle events */ ]
  },
  "meta": { "scanned": 42 }
}
```

Empty `id` returns `404 Not Found` via `not_found_error()`:

```json
{
  "code": "rest_not_found",
  "message": "Not found: request id missing",
  "data": { "status": 404 }
}
```

A non-empty `id` that doesn't resolve returns an empty `entries` array (200) for backward compatibility with the legacy stub.

## Servers

```
GET    /wp-json/newspack-nodes/v1/servers
POST   /wp-json/newspack-nodes/v1/servers
GET    /wp-json/newspack-nodes/v1/servers/{id}
PUT    /wp-json/newspack-nodes/v1/servers/{id}
DELETE /wp-json/newspack-nodes/v1/servers/{id}
POST   /wp-json/newspack-nodes/v1/servers/{id}/test
```

CRUD for remote spokes registered via `ServerRegistry`. IDs match `[a-zA-Z0-9_-]{1,64}`, URLs must be HTTPS, credentials cap at 256 bytes, log filenames must match `[a-zA-Z0-9_.-]+\.log$`. Storage is sodium-secretbox encrypted at rest (keyed on `wp_salt('auth')`); config-file overlay servers can only toggle `enabled` via PUT.

`POST /servers/{id}/test` probes `/wp-json/newspack-nodes/v1/discovery` on the remote with the stored Basic Auth credentials, returning the remote's registered hooks / custom events / lag fields (whitelisted; never proxies arbitrary JSON).

## Discovery

```
GET  /wp-json/newspack-nodes/v1/discovery
```

Spoke-side endpoint that hub aggregators probe. Returns `registered_hooks`, `custom_events`, and (when readers are registered) `lag` in bytes — max reader-lag across registered `log_readers`.

## Settings

```
POST  /wp-json/newspack-nodes/v1/settings
```

Whitelisted single-option writer for the four substrate-level integer options: `newspack_nodes_num_partitions`, `newspack_nodes_num_segments`, `newspack_nodes_segment_size`, `newspack_nodes_max_lifespan`. Used by hub-side aggregator fan-out (RemoteManager) when pushing core settings down to spokes. Triggers `Config::reset()` after a successful write so the next request sees the new value.

## Aggregator

```
GET  /wp-json/newspack-nodes-aggregator/v1/status
GET  /wp-json/newspack-nodes-aggregator/v1/servers
GET  /wp-json/newspack-nodes-aggregator/v1/health
```

Hub-side aggregator endpoints. The `/status` route delegates to `AggregatorStatusController` for the per-server / per-partition memcache-backed status; `/servers` lists registered remote spokes from `ServerRegistry`; `/health` reports cache reachability.

### `/status` response

Keyed by server id; per server, per-partition status pulled from `aggregator_status:{id}:p{N}` in memcache:

```json
{
  "spoke-id-1": {
    "id": "spoke-id-1",
    "url": "https://spoke.example/",
    "enabled": true,
    "partitions": {
      "0": { /* StreamMerger state for this spoke / partition */ }
    }
  }
}
```

### `/servers` response

```json
[
  {
    "id": "spoke-id-1",
    "url": "https://spoke.example/",
    "enabled": true,
    "logs": [ "firehose.log" ],
    "has_credentials": true,
    "is_config": false
  }
]
```

### `/health` response

Always `200`:

```json
{
  "healthy": true,
  "cache": true,
  "timestamp": 1715300000
}
```

## Worker Spawn

The runtime endpoint, listed here for completeness:

```
POST  /wp-json/newspack-nodes/v1/workers/spawn
```

HMAC-validated; not for public callers. See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for the full request/response shape and authentication details.

## Permissions Check (shared)

All non-spawn endpoints share `PerformanceControllerBase::read_permissions_check()`:

```php
public function read_permissions_check(): bool|\WP_Error {
    if ( ! \current_user_can( 'manage_options' ) ) {
        return new \WP_Error( 'rest_forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
    }
    return true;
}
```

Write endpoints (`POST /performance/settings`, `POST /performance/config`, `POST /performance/hooks/configure`, `POST /settings`, `POST /servers`, `PUT /servers/{id}`, `DELETE /servers/{id}`, `POST /performance/workers/restart`) use `manage_options` plus, for the restart route, a CSRF nonce (`newspack_nodes_restart_worker`).
