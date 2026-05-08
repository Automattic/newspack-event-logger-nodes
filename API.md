# Newspack Event Logger Nodes REST API

REST endpoints registered by the application plugin. The runtime substrate (`newspack-nodes`) only ships the worker spawn endpoint — see [`../newspack-nodes/API.md`](../newspack-nodes/API.md).

Two namespaces:

- `newspack-nodes/v1/*` — core dashboards, status, performance, events, gyroscope, logger, request-log.
- `newspack-nodes-aggregator/v1/*` — hub-side aggregator status, server registry, health.

## Authentication

All endpoints inherit `PerformanceControllerBase::read_permissions_check()` which requires `current_user_can( 'manage_options' )`. Returns `403 Forbidden` (`rest_forbidden`) on insufficient permissions.

## Rate Limiting

`PerformanceControllerBase::check_rate_limit()` provides fixed-window rate limiting backed by memcache:

- Default: 60 requests per 60-second window.
- Window edges floor `time()` to the window length, so all callers in the same wall-clock window share a counter.
- Identity key: logged-in users key on user id; anonymous fall back to a hashed `REMOTE_ADDR`.
- Returns `429 Too Many Requests` (`rate_limit_exceeded`) with `Retry-After`-style hint when exceeded.
- **Fail-open** if memcache is unreachable — the system stays reachable rather than blocking on a degraded sidecar.

## Status

```
GET  /wp-json/newspack-nodes/v1/status
```

Health probe. Returns `200` with the active plugin version.

### Response

```json
{
  "status": "ok",
  "version": "0.1.0"
}
```

## Performance

```
GET  /wp-json/newspack-nodes/v1/performance/dashboard
GET  /wp-json/newspack-nodes/v1/performance/timing
```

Performance-dashboards tree. Currently returns stub bodies (real reads land when `RequestBuilder` + `FlameBuilder` + `StatsAggregator` integrate end-to-end). Shapes preserved so the React tree can mount without 404s.

### `/performance/dashboard` response (stub)

```json
{
  "data": {
    "overview": [],
    "urls": []
  },
  "meta": { "stub": true }
}
```

### `/performance/timing` response (stub)

```json
{
  "data": { "time_series": [] },
  "meta": { "stub": true }
}
```

### Planned shape (from legacy `event-logger/v1/performance/overview`)

The legacy `/event-logger/v1/performance/overview` returned hourly + leaderboard data; the new endpoint will follow the same shape:

```json
{
  "data": {
    "overview": {
      "hourly": [
        { "bucket": "2026-05-08-12", "count": 12345, "sum_ms": 67890.5, "sum_peak_mb": 1234.5 }
      ],
      "leaderboard": [
        { "url": "/example", "count": 100, "mean_req_time": 45.2, "categories": { ... } }
      ],
      "total_requests": 12345
    },
    "urls": [ /* flat URL list with breakdowns when ?breakdown=... query present */ ]
  },
  "meta": { "stub": false }
}
```

TODO: confirm shape after `StatsAggregator` integration lands.

### Planned: per-URL detail (`/performance/urls/{hash}`)

The React tree calls `/newspack-nodes/v1/performance/urls/{hash}?categories=1`. Not yet registered as a route; PerformanceController will add it alongside the StatsAggregator integration.

TODO: confirm shape — expected to mirror legacy `event-logger/v1/performance/urls/{hash}` (per-URL flame data + profiles + dimensional breakdowns).

### Planned: per-request detail (`/performance/requests/{rid}`, `/performance/requests/search/{rid}`)

The React tree calls `/newspack-nodes/v1/performance/requests/{rid}?partition=0`. Not yet registered. Will scan the per-partition `requests.log/.idx` companion index for the rid, return the full request body.

TODO: confirm shape after `RequestBuilder` index integration lands.

### Planned: workers (`/performance/workers`, `/performance/workers/restart`)

The React tree calls `/newspack-nodes/v1/performance/workers` (GET) and `/newspack-nodes/v1/performance/workers/restart` (POST). Not yet registered.

GET response will list active workers with lock state and offsetlog cursors. POST will use `Lock::request_restart()` to signal a graceful exit on the next 250ms tick.

TODO: confirm shape.

## Events

```
GET  /wp-json/newspack-nodes/v1/events/recent
GET  /wp-json/newspack-nodes/v1/events/stats
```

Event-dashboards tree (Raw Logs viewer). Currently stub.

### `/events/recent` request

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `limit` | int | 100 | 1..1000 |

### `/events/recent` response (stub)

```json
{
  "data": [],
  "meta": { "stub": true, "limit": 100 }
}
```

### `/events/stats` response (stub)

```json
{
  "data": { "time_series": [] },
  "meta": { "stub": true }
}
```

### Planned: SSE streams (`/firehose/stream`, `/firehose/rawlogs`, `/firehose/heartbeat`, `/firehose/logs`)

The React trees consume `/newspack-nodes/v1/firehose/{stream,rawlogs,gyroscope,requests,errors,heartbeat,logs}` — all SSE except `/heartbeat` (POST keepalive) and `/logs` (GET list). Not yet registered; lands when `SSEControllerBase` is ported.

Operational discipline (preserve from existing event-logger):

- 10 memcache slots per stream type. New connections fail with **HTTP 429** when full (not 503).
- TWO heartbeats: server-to-client SSE `heartbeat` events every 5s in-stream; client-to-server keepalive POSTs to refresh the slot (`SLOT_TTL_BROWSER=10s`, `SLOT_TTL_AGGREGATOR=30s`).
- `flush_if_needed()` before sleeps, NOT per-event. Per-event flushing tanks throughput on TLS/proxy paths.
- 1-hour `MAX_RUNTIME` per connection (matches existing event-logger; client reconnects after timeout).

## Gyroscope

```
GET  /wp-json/newspack-nodes/v1/gyroscope/timeline?request_id=...
```

Performance-gyroscope tree. Synchronous timeline-snapshot fetch (used when a client wants the current state of an explicit request id). The legacy plugin exposed an SSE stream at the same shape; SSE infrastructure is deferred to when `SSEControllerBase` ports.

### Request

| Param | Type | Required |
|-------|------|----------|
| `request_id` | string | no |

### Response (stub)

When `request_id` provided:

```json
{
  "data": {
    "request_id": "abc123",
    "events": []
  },
  "meta": { "stub": true }
}
```

When not provided (empty initial shape):

```json
{
  "data": { "events": [] },
  "meta": { "stub": true }
}
```

## Logger

```
GET  /wp-json/newspack-nodes/v1/logger/config
GET  /wp-json/newspack-nodes/v1/logger/hooks
```

Performance-logger settings tree. Stub responses mirror the legacy `/perf-logger/v1/{config,hooks}` payloads so the settings UI can mount.

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
  "meta": { "stub": true }
}
```

### `/logger/hooks` response (stub)

```json
{
  "data": {
    "hooks": [],
    "categories": []
  },
  "meta": { "stub": true }
}
```

### Planned: settings POST + hook discovery

The legacy plugin had `POST /perf-logger/v1/settings` for HookSelectorModal-driven hook configuration, and `GET /event-logger/v1/performance/registered-hooks` / `/hook-categories` for the live `$wp_filter` enumeration + categorization. Not yet registered; lands with `HookCategorizer` port.

TODO: confirm shapes after `HookCategorizer` and `SettingsSync` REST integration.

## Request Log

```
GET  /wp-json/newspack-nodes/v1/request-log/list
GET  /wp-json/newspack-nodes/v1/request-log/detail/{id}
```

Performance-request-log tree. Stub bodies; real implementation queries the firehose `.idx` per partition.

### `/request-log/list` request

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `limit` | int | 100 | 1..1000 |

### `/request-log/list` response (stub)

```json
{
  "data": [],
  "meta": { "stub": true, "limit": 100 }
}
```

### `/request-log/detail/{id}` response

Path param: `id` matches `[A-Za-z0-9_-]+`.

When provided:

```json
{
  "data": {
    "request_id": "abc123",
    "entries": []
  },
  "meta": { "stub": true }
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

## Aggregator

```
GET  /wp-json/newspack-nodes-aggregator/v1/status
GET  /wp-json/newspack-nodes-aggregator/v1/servers
GET  /wp-json/newspack-nodes-aggregator/v1/health
```

Hub-side aggregator endpoints. Returns stub shapes so the `event-aggregator` React tree can mount and load without 404s; real data wiring lands when `StreamMerger` and `ServerRegistry` integrate end-to-end.

### `/status` response (stub)

```json
{
  "data": [],
  "meta": { "stub": true, "namespace": "newspack-nodes-aggregator/v1" }
}
```

### `/servers` response (stub)

```json
{
  "data": [],
  "meta": { "stub": true, "namespace": "newspack-nodes-aggregator/v1" }
}
```

### `/health` response

Always `200`:

```json
{
  "data": { "healthy": true },
  "meta": { "stub": true }
}
```

### Planned: server CRUD

The legacy plugin had full CRUD under `/event-aggregator/v1/servers/{id}` (GET / PUT / DELETE) plus `/event-aggregator/v1/servers/{id}/test`. Not yet registered; lands with `ServerRegistry` REST integration.

The current GET `/servers` returns the encrypted-storage list (decrypted at read via sodium-secretbox keyed on `wp_salt('auth')`); POST adds a new server with sodium-encrypted credentials; PUT updates including `enabled` field; DELETE removes; POST `/test` runs a health probe.

TODO: confirm shape after `ServerRegistry` REST integration.

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

The legacy plugin also supported an `allowed_users` whitelist (`Newspack_Event_Logger\Admin::current_user_allowed()`) for delegating dashboard access without granting `manage_options`. Not yet ported; will land alongside the admin pages.
