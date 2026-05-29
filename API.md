# Newspack Event Logger Nodes API

The application has **two** REST endpoints. Everything else is a verb on a service Command_Interpreter (CI) node, addressed by name through the command endpoint.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-nodes/v1/command` | substrate (`HTTP_In_Node`) | Routes a TM_COMMAND envelope to a named CI node and returns the reply. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate (`SSE_Out_Node`) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. |

Both endpoints are owned by the **substrate** (`newspack-nodes`). The application contributes the verbs each CI exposes; nothing in this plugin registers its own REST routes.

See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for the wire shape of both endpoints (TM_COMMAND envelope layout, SSE event format, slot-pool 429 semantics, HMAC for the worker spawn endpoint).

## Authentication and Rate Limiting

The substrate enforces `current_user_can( 'manage_options' )` on `/command` and `/messages/stream`; insufficient permissions return `403 Forbidden`.

Fixed-window rate limiting (default 600 req / 60s) is provided by `Performance_Controller_Base::check_rate_limit()` for the verbs that opt in (all application CIs do). Identity key: logged-in users key on user id; anonymous fall back to a hashed `REMOTE_ADDR`. Returns `429 Too Many Requests` (`rate_limit_exceeded`) when exceeded. **Fail-open** if memcache is unreachable — the system stays reachable rather than blocking on a degraded sidecar.

SSE rate-limiting is independent and **fail-closed**: the `Sse_Slot_Pool` gates new connections; memcache down means HTTP 429. The slot pool IS the rate limit and cannot fall through silently.

## Sending a Command

```http
POST /wp-json/newspack-nodes/v1/command HTTP/1.1
Content-Type: application/json

{
  "TYPE": "TM_COMMAND",
  "TO":   "performance",
  "KEY":  "overview",
  "VALUE": { "categories": true }
}
```

- `TO` is the service CI name (`performance`, `events`, `status`, `logger`, `settings`, `servers`, `aggregator`, `discovery`). Sub-paths address a child node (rare in this plugin); most callers just name the CI.
- `KEY` is the verb name on that CI.
- `VALUE` is the verb arguments (JSON object). The substrate validates each argument against the CI's `node_schema()['commands'][*]['args']` declaration before dispatching.

The reply is a TM_COMMAND-shaped envelope routed back via `TO=FROM` pivot, with the verb's return value in `VALUE`. A verb that throws sends back a TM_ERROR envelope; the dashboard view's pending-Map handler converts the structured `{ message }` payload into a rejected Promise (see ARCHITECTURE.md → "Canonical view contract").

## Service CIs

Each subsection below lists the verbs in the corresponding `includes/app/class-<name>-ci.php` file's `node_schema()['commands']` declaration. **TO=`<ci-name>`, KEY=`<verb>`** addresses a verb.

### `discovery` — spoke-side hook + event roster

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | `{ registered_hooks: string[], custom_events: string[] }` — `log_events` ∪ filter-supplied keys, with `custom_events` filtered out of `registered_hooks`. |

Read by hub aggregators probing each spoke; no auth check (rate-limited at transport).

### `status` — health probe

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | `{ status: 'ok', version, runtime_version, num_partitions, topologies: string[], cache_available: bool, timestamp }` |

`topologies` lists the substrate's active topology set (`array_keys( Bootstrap::get_topologies() )`).

### `settings` — substrate-owned integer settings (4-key whitelist)

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | Snapshot of `{ num_partitions, num_segments, segment_size, max_lifespan }`. |
| `update` | `{ num_partitions?, num_segments?, segment_size?, max_lifespan? }` (all int, optional) | Post-update snapshot. Suppresses Settings_Sync fan-out around each `update_option` so applying a remotely-synced setting on a spoke doesn't bounce back as a re-sync. |

Used by hub-side aggregator fan-out (Remote_Manager) when pushing core settings down to spokes; also the admin UI.

### `logger` — performance-logger settings read

| Verb | Args | Returns |
|------|------|---------|
| `config` | — | Full filterable config (`Config::load_config()` result). |
| `hooks` | — | `{ hooks: [{name, category}], categories: {…} }` flattened via `Hook_Categorizer`. |

Read-only — settings WRITES live on the `performance` CI (`config_update`, `settings_update`, `hooks_configure`).

### `events` — raw firehose viewer

| Verb | Args | Returns |
|------|------|---------|
| `recent` | `{ limit?: int = 100 (1..1000) }` | `{ data: entries[], meta: { limit, scanned } }` — newest-first walk of the firehose `.idx` across all partitions; capped at `MAX_INDEX_ENTRIES = 100000`. |
| `stats` | — | Merged hourly time series across partitions (same shape as `performance.timing`). |

### `servers` — remote-spoke registry CRUD

| Verb | Args | Returns / Behavior |
|------|------|--------------------|
| `list` | — | All servers keyed by id. |
| `get` | `{ id }` | A single server record. |
| `add` | `{ id, url, auth_username?, auth_password?, enabled?, logs? }` | Add a new server (manage_options). IDs match `[a-zA-Z0-9_-]{1,64}`; URLs must be HTTPS; logs default to `[ 'firehose.log' ]`. |
| `update` | `{ id, url?, auth_username?, auth_password?, enabled?, logs? }` | Partial update of an existing server (manage_options). Config-file overlay servers can only toggle `enabled`. |
| `delete` | `{ id }` | Remove a server (manage_options). No-op on config-supplied entries. |
| `test` | `{ id }` | Probe `/discovery` on the remote with stored Basic Auth; returns the remote's registered hooks / custom events / lag fields (whitelisted; never proxies arbitrary JSON). |

Storage is sodium-secretbox encrypted at rest (keyed on `wp_salt('auth')`).

### `aggregator` — hub-side status

| Verb | Args | Returns |
|------|------|---------|
| `status` | — | Per-server per-partition snapshot pulled from `aggregator_status:{id}:p{N}` in memcache. Keyed by server id. |
| `health` | — | `{ healthy: bool, cache: bool, timestamp }`. |
| `servers` | — | Registered servers as a sequential array (legacy aggregator-tree contract). |

These are read-only; spoke CRUD lives on the `servers` CI.

### `performance` — the omnibus dashboard CI

The largest CI; every Performance-tree dashboard verb lives here.

| Verb | Args | Returns |
|------|------|---------|
| `overview` | `{ server?, breakdown?, categories?: bool }` | High-level performance stats across all partitions. Single-dim `breakdown` returns `breakdown_time_series` flat; multi-dim returns `breakdowns` keyed by dim. |
| `urls` | `{ sort? = 'count', order? = 'desc', limit?: int = 50, offset?: int = 0, search?, server? }` | Paginated / sortable URL leaderboard. |
| `url_detail` | `{ hash (required, `[a-f0-9]{8,64}`), breakdown?, categories?: bool }` | Single-URL detail incl. aggregate flame data. |
| `request_search` | `{ rid (required, `[a-zA-Z0-9_-]{1,128}`) }` | `{ rid, partition, url_hash }` so the dashboard can deep-link without scanning every partition. |
| `request_detail` | `{ rid (required), partition?: int = 0 }` | Full request body + merged flame data. |
| `timing` | — | Merged hourly timing buckets across partitions. |
| `dashboard` | — | Composite of `overview` + `urls` in one round-trip. |
| `hooks_registered` | — | Registered hooks grouped by category. |
| `hooks_categories` | — | Hook categories + merged config. |
| `hooks_available` | — | All runtime hooks for the picker UI. |
| `hooks_configure` | `{ hooks?: json, custom_events?: json }` | Persist selected hooks / custom events; triggers `Config::reset()`. |
| `config_get` | — | The 9 performance-tuning options as a flat `config` block. |
| `config_update` | (partial 9-option payload) | Writes any subset in one round-trip. |
| `settings_update` | (single-option payload) | Single-option writer for the same 9-option set; suppresses `Settings_Sync` fan-out around `update_option`. |
| `request_log_list` | `{ limit?: int = 100 (1..1000) }` | Walks the requests index across all partitions; returns per-entry summary fields (`rid`, `url_hash`, `timestamp`, `duration_ms`, `status_code`, `peak_mb`, `method`, `error_status`, `partition`). |
| `request_log_detail` | `{ id (required) }` | `{ request_id, entries: [] }` — events for the rid. Empty id is `404 Not Found`; a non-empty id that doesn't resolve returns an empty `entries` array (200) for legacy parity. |

## Substrate verbs the dashboards use

The dashboards also call into substrate-owned CIs over the same `/command` endpoint. They're listed here for orientation — see `../newspack-nodes/API.md` for full schemas.

| TO | Verb examples | Used by |
|----|---------------|---------|
| `workers` | `list`, `restart`, `spawn` | Workers dashboard, performance dashboards' restart action |
| `_http/<ci-name>` | (transport wrapper) | All dashboards — the React graphs target `_http/<ci-name>` so the substrate's `Http_Out_Node` pivots the reply back to FROM |

`_http/workers` is the canonical heartbeat target used by every SSE dashboard's `_heartbeat` node to keep the slot alive.

## SSE: `/messages/stream`

The substrate's single SSE surface. A client subscribes to one or more `<log>.p<N>` partitions; the server emits a 7-field message envelope per data line plus an idle `heartbeat` event.

```
GET /wp-json/newspack-nodes/v1/messages/stream?subscribe=<log>.p<N>[,<log>.p<N>...][&positions=...]
```

Per-line transforms live in the browser (`transformCompletedLine`, `transformGyroscopeLine`, `transformErrorLine`, …); the shared browser hook is `useMessageStream`. Hub-side aggregator connections (`Remote_Source_Node` cURL pulls) get a longer slot TTL than browsers.

Operational discipline:
- Memcache slot pool gates connections; new connections fail with **HTTP 429** when the pool is full or memcache is unreachable (fail-closed).
- Two heartbeats: server→client SSE `heartbeat` events when no data flows; client→server keepalive that refreshes the slot. **Only the client refreshes a slot's TTL** — the server-side check is check-only. Each client's TTL must outlive its own heartbeat interval.
- Flush before the framework sleeps, NOT per-event. Per-event flushing tanks throughput on TLS/proxy paths.
- A bounded per-connection runtime cap; the client reconnects after timeout.

See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for full request/response shape and subscription syntax.

## Worker Spawn

The runtime substrate's HMAC-validated worker bootstrap endpoint, listed for orientation:

```
POST /wp-json/newspack-nodes/v1/workers/spawn
```

Not for public callers. See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for the full request/response shape and authentication details.
