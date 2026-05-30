# Newspack Event Logger Nodes API

The application has **two** REST endpoints. Everything else is a verb on a service Command_Interpreter (CI) node, addressed by name through the command endpoint.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-nodes/v1/command` | substrate (`HTTP_In_Node`) | Routes a TM_COMMAND envelope to a named CI node and returns the reply. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate (`SSE_Out_Node`) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. |

Both endpoints are owned by the **substrate** (`newspack-nodes`). The application contributes the verbs each CI exposes; nothing in this plugin registers its own REST routes.

See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for the wire shape of both endpoints (TM_COMMAND envelope layout, SSE event format, slot-pool 429 semantics, HMAC for the worker spawn endpoint).

## Authentication and Rate Limiting

The substrate enforces `current_user_can( 'manage_options' )` on `/command` and `/messages/stream`; insufficient permissions return `403 Forbidden`. Each verb handler that needs a capability check also calls `self::require_manage_options()` at the top, so a misconfigured substrate-side gate still rejects writes.

There is no rate-limit gate on `/command` itself; the per-verb cost (memcache reads, partition index walks, filesystem scans) is the budget. (`Performance_Controller_Base::check_rate_limit()` survives in `includes/rest/` as an orphaned helper with no callers outside its own tests — slated for review/deletion. Don't reach for it.)

SSE rate-limiting is independent and **fail-closed**: the substrate's `SSE_Out_Node` consults the substrate's `\Newspack_Nodes\SSE_Slot_Pool` before opening headers, and memcache down means HTTP 429. The slot pool IS the rate limit and cannot fall through silently.

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

Each subsection below lists the verbs the corresponding `includes/app/class-<name>-ci.php` exposes. The schema-driven CIs (`events`, `settings`, `servers`, `aggregator`, `performance`) declare verbs in a static `node_schema()['commands']` array (name + args + handler); the legacy-shape CIs (`status`, `discovery`, `logger`) build the same commands table directly in their constructor via `$this->commands([…])`. Either way: **TO=`<ci-name>`, KEY=`<verb>`** addresses a verb.

### `discovery` — spoke-side hook + event roster

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | `{ registered_hooks: string[], custom_events: string[] }` — `log_events` ∪ filter-supplied keys, with `custom_events` filtered out of `registered_hooks`. |

Read by hub aggregators probing each spoke. The CI handler itself doesn't call `require_manage_options()`; the substrate `/command` endpoint still gates the route on `current_user_can('manage_options')`, which the spoke's stored Basic Auth credentials satisfy.

### `status` — health probe

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | `{ status: 'ok', version, runtime_version, num_partitions, topologies: string[], cache_available: bool, timestamp }` |

`topologies` lists the substrate's active topology set (`array_keys( Bootstrap::get_topologies() )`).

### `settings` — substrate-owned integer settings (4-key whitelist)

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | Snapshot of `{ num_partitions, num_segments, segment_size, max_lifespan }`. |
| `update` | `{ num_partitions?, num_segments?, segment_size?, max_lifespan? }` (all int, optional) | Post-update snapshot. Requires `manage_options`. Writes via `update_option('newspack_nodes_<key>', …, autoload=true)` then `AppConfig::reset()`. Bounds: three keys `1..2^30`, `max_lifespan` `0..2^30`. Unknown keys / out-of-range values throw TM_ERROR. |

Used by hub-side aggregator fan-out (Remote_Manager) when pushing core settings down to spokes; also the admin UI. Note: `settings.update` does NOT wrap writes in `Settings_Sync::suppress_sync` — the suppression-around-`update_option` guard is on the application-level `performance.settings_update` verb, not this substrate-key writer.

### `logger` — performance-logger settings read

| Verb | Args | Returns |
|------|------|---------|
| `config` | — | Full filterable config (`Config::load_config()` result). |
| `hooks` | — | `{ hooks: [{name, category}], categories: {…} }` flattened via `Hook_Categorizer`. |

Read-only — settings WRITES live on the `performance` CI (`config_update`, `settings_update`, `hooks_configure`).

### `events` — hourly-stats surface

| Verb | Args | Returns |
|------|------|---------|
| `stats` | — | `{ data: { time_series: [{hour, count, sum_ms, sum_peak_mb}, ...] }, meta: {} }` — per-partition hourly buckets read from `Stats_Store` and merged. Fail-soft on memcache outage (empty `time_series`). Note the envelope shape differs from `performance.timing`, which returns `{ time_series: […] }` flat. |

### `servers` — remote-spoke registry CRUD

| Verb | Args | Returns / Behavior |
|------|------|--------------------|
| `list` | — | All servers keyed by id. Each value: `{ id, url, enabled, logs, has_credentials, is_config }` — credentials stripped. |
| `get` | `{ id }` | A single server record in the same public shape as `list`. Throws TM_ERROR `server not found` for unknown ids. |
| `add` | `{ id, url, auth_username?, auth_password?, enabled? = true, logs? = ['firehose.log'] }` | Add a new server (`manage_options`). IDs match `[a-zA-Z0-9_-]{1,64}`; URLs must be HTTPS. Returns `{ id }`. On success, queues a targeted full-settings sync for the new spoke and trips the supervisor restart flag (both best-effort, swallowed Throwables). |
| `update` | `{ id, url?, auth_username?, auth_password?, enabled?, logs? }` | Partial update of an existing server (`manage_options`). Returns `{ id }`. Config-file overlay servers can only toggle `enabled` (enforced in `Server_Registry::update`). A `false → true` `enabled` flip queues a targeted settings sync; both paths request a supervisor restart. |
| `delete` | `{ id }` | Remove a server (`manage_options`). Returns `{ id }`. Throws TM_ERROR `delete failed` for config-supplied entries (registry refuses removal). Requests a supervisor restart on success. |
| `test` | `{ id }` | Probe the remote's `/command` endpoint with a `discovery.get` envelope (5s timeout, stored Basic Auth, `aggregator_verify_ssl` honored). Returns `{ id, status: 'connected', response: { registered_hooks, custom_events, lag } }` — whitelisted fields; never proxies arbitrary JSON. Throws TM_ERROR on connect failure / non-200 / malformed envelope. |

Storage is the `newspack_event_logger_nodes_aggregator_servers` option (merged with any `aggregator_servers` keys arriving via the `newspack_event_logger_nodes/config` filter); `auth_password` is sodium-secretbox encrypted at rest with a key derived from `wp_salt('auth')`. Cap: 100 servers.

### `aggregator` — hub-side status

| Verb | Args | Returns |
|------|------|---------|
| `status` | — | Map keyed by server id: `{ <id>: { id, url, enabled, partitions: { 0: {...}, 1: {...}, ... } } }`. Each partition payload is the `aggregator_status:{id}:p{N}` memcache value (empty array on miss). `num_partitions` is clamped to `1..16`. Requires `manage_options`. |
| `health` | — | `{ healthy: true, cache: bool, timestamp }`. `cache` is `null !== Core::$memd`. Requires `manage_options`. |
| `servers` | — | Sequential array (NOT a map) of registered servers, each `{ id, url, enabled, logs, has_credentials, is_config }`. The sequential shape is a legacy contract the React aggregator tree depends on; don't switch to a keyed map. Requires `manage_options`. |

These are read-only; spoke CRUD lives on the `servers` CI.

### `performance` — the omnibus dashboard CI

The largest CI; every Performance-tree dashboard verb lives here.

| Verb | Args | Returns |
|------|------|---------|
| `overview` | `{ server?, breakdown?, categories?: bool }` | High-level performance stats across all partitions. Comma-separated `breakdown`: single-dim returns `breakdown_time_series` flat; multi-dim returns `breakdowns` keyed by dim. `categories=true` adds `category_time_series`. Requires `manage_options`. |
| `urls` | `{ sort? = 'count', order? = 'desc', limit?: int = 50 (1..1000), offset?: int = 0 (0..10000), search?, server? }` | `{ data: [...], total, limit, offset }` — paginated/sortable URL leaderboard. Sort whitelisted against `URL_SORTS`; unknown sort falls back to `count`. Requires `manage_options`. |
| `url_detail` | `{ hash (required, `[a-f0-9]{8,64}`), breakdown?, categories?: bool }` | `{ stats, requests, aggregate_flame, aggregate_profiles, last_modified[, breakdown_time_series, category_time_series] }`. Throws TM_ERROR `URL not found` for unknown hashes. Requires `manage_options`. |
| `request_search` | `{ rid (required, `[a-zA-Z0-9_-]{1,128}`) }` | `{ rid, partition, url_hash }` so the dashboard can deep-link without scanning every partition. Requires `manage_options`. |
| `request_detail` | `{ rid (required), partition?: int = 0 }` | Full request body + merged flame data. Throws TM_ERROR `invalid partition` for out-of-range partition; `Request not found` for unknown rid. Requires `manage_options`. |
| `timing` | — | `{ time_series: [...] }` — merged hourly timing buckets across partitions. Requires `manage_options`. |
| `dashboard` | — | `{ overview, urls }` — the overview payload plus the full URL index from a single shared `load_index()` call (the heavy memcache fan-out). Requires `manage_options`. |
| `hooks_registered` | — | `{ total_hooks, categories, hooks_by_category }`. Requires `manage_options`. |
| `hooks_categories` | — | `{ categories, config }`. Requires `manage_options`. |
| `hooks_available` | — | `{ hooks: [...] }` — every fired (`$wp_actions`) or registered (`$wp_filter`) hook, minus this plugin's internals and the operator's custom-events list. Requires `manage_options`. |
| `hooks_configure` | `{ hooks?: json, custom_events?: json }` | `{ success, hooks_configured }`. Persists selected hooks / custom events; calls `AppConfig::reset()`. Requires `manage_options`. |
| `config_get` | — | `{ config: { log_events, custom_events, log_urls, skip_urls, auto_disable_threshold, auto_protect_time_threshold, significant_events, log_memory, flush_every_line } }` — the 9 performance-tuning options. Requires `manage_options`. |
| `config_update` | (partial 9-option payload) | `{ success, updated: [names...] }`. Writes any subset in one round-trip; absent / null keys skipped; unknown keys silently ignored. Requires `manage_options`. |
| `settings_update` | `{ option (required), value (required) }` | `{ option, updated: bool }`. Single-option writer for the same 9-option set; **wraps the `update_option` call in `Settings_Sync::suppress_sync(true)` / try-finally** so a remotely-synced setting applied on a spoke doesn't bounce back as a re-sync. Requires `manage_options`. |
| `request_log_list` | `{ limit?: int = 100 (1..1000) }` | `{ data: entries[], meta: { limit, scanned } }` — newest-first walk of the requests index across all partitions. Each entry summary carries `rid`, `url_hash`, `timestamp`, `duration_ms`, `status_code`, `peak_mb`, `method`, `error_status`, `partition`. Requires `manage_options`. |
| `request_log_detail` | `{ id (required) }` | `{ data: { request_id, entries: [...] }, meta: { scanned } }` for known rids. Empty id throws TM_ERROR (`id required`); a non-empty id that doesn't resolve returns the same envelope with an empty `entries` array (legacy stub-compatible — the React tree polls these and "expected to exist soon" is a normal state). Requires `manage_options`. |

## Substrate verbs the dashboards use

The dashboards also call into substrate-owned CIs over the same `/command` endpoint. They're listed here for orientation — see `../newspack-nodes/API.md` for full schemas.

| TO | Verb examples | Used by |
|----|---------------|---------|
| `workers` | `list`, `restart`, `heartbeat`, `dump_metadata`, `cleanup_status` | Workers dashboard, performance dashboards' restart action, every SSE dashboard's `_heartbeat` keep-alive |
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
