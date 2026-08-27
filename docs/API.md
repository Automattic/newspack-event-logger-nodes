# Newspack Event Logger Nodes API

The application has **two** REST endpoints. Everything else is a verb on a service Command_Interpreter (CI) node, addressed by name through the command endpoint.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-nodes/v1/command` | substrate (`HTTP_In_Node`) | Routes a TM_COMMAND envelope to a named CI node and returns the reply. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate (`SSE_Out_Node`) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. |

Both endpoints are owned by the **substrate** (`newspack-nodes`). The application contributes the verbs each CI exposes; nothing in this plugin registers its own REST routes.

See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for the wire shape of both endpoints (TM_COMMAND envelope layout, per-command HMAC signing, SSE event format, slot-pool 429 semantics, and HMAC for the worker spawn endpoint).

## Authentication and Rate Limiting

The substrate enforces `current_user_can( 'manage_options' )` on `/command` and `/messages/stream`; insufficient permissions return `403 Forbidden`. Each verb handler that needs a capability check also calls `self::require_manage_options()` at the top, so a misconfigured substrate-side gate still rejects writes.

`/command` also carries its own per-user burst limit (`HTTP_In_Node::check_permission`): `RATE_LIMIT_BURST = 30` POSTs per `RATE_LIMIT_WINDOW_S = 1` second per user, bucketed by clock-second and transient-backed, returning `429 Too Many Requests` on overflow. The budget is tunable via the `newspack_nodes/command_rate_limit` filter (clamped to a minimum of 1).

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

- `TO` is the service CI name. This plugin owns `performance`, `discovery`, `rules`. The `status`, `settings`, and `aggregator` CIs are now substrate-owned (newspack-nodes); the old `servers` CI was replaced by the substrate `vault` CI. (The former `logger` and `events` CIs were removed — `performance.hooks_registered` and `performance.overview` supersede them.) Sub-paths address a child node (rare in this plugin); most callers just name the CI.
- `KEY` is the verb name on that CI.
- `VALUE` is the verb arguments (JSON object). The substrate validates each argument against the CI's `node_schema()['commands'][*]['args']` declaration before dispatching.

The reply is a TM_COMMAND-shaped envelope sent back via `TO=FROM`, carrying the verb's return value in `VALUE`. A verb that throws sends back a TM_ERROR envelope; the dashboard view's pending-Map handler converts the structured `{ message }` payload into a rejected Promise (see architecture-guide.md → "Canonical view contract").

## Service CIs

Each subsection below lists the verbs the corresponding `includes/app/class-<name>-ci-node.php` (`<Name>_CI_Node`, e.g. `Discovery_CI_Node`) exposes. All three CIs declare their verbs in a static `node_schema()['commands']` array (name + args + handler); the inherited `Service_CI_Node` constructor builds the commands table from the schema, so none define a per-class constructor. **TO=`<ci-name>`, KEY=`<verb>`** addresses a verb.

The verb handlers come from this plugin's `Newspack_Event_Logger_Nodes\App\` namespace: `newspack-event-logger-nodes.php` registers it via `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )`, and the CIs mount on the substrate's `newspack_nodes/request_graph_ready` action.

### `discovery` — spoke-side hook + event roster

| Verb | Args | Returns |
|------|------|---------|
| `get` | — | `{ registered_hooks: string[], custom_events: string[] }` — the union across every LOG rule of its instrumented hooks and custom events (`Rule_Set::instrumented_union()`), with `custom_events` filtered out of `registered_hooks`. |

Read by hub aggregators probing each spoke. The CI handler itself doesn't call `require_manage_options()`; the substrate `/command` endpoint still gates the route on `current_user_can('manage_options')`, which the spoke's stored Basic Auth credentials satisfy.

### `status` — health probe (moved to the substrate)

Moved to the substrate `status` CI (newspack-nodes). `status.get` returns the substrate `runtime_version`, `num_partitions`, active `topologies`, `cache_available`, and a `timestamp` — it carries no application version field. See `../newspack-nodes/API.md`.

### `settings` — substrate integer settings (moved to the substrate)

Moved to the substrate `settings` CI (newspack-nodes). It owns a seven-key integer whitelist (`num_partitions`, `segment_size`, `min_segments`, `num_segments`, `min_lifetime`, `lifetime`, `max_segments`) — the old single `num_segments`/`max_lifespan` pair split into this dual-rule retention scheme. See `../newspack-nodes/API.md`.

### `rules` — per-URL logging ruleset CRUD

Backs the "Logging Rules" editor on the settings page. All five verbs route through `Rule_Set` so the inline↔pointer hook-tiering and orphan-reconcile invariants are never bypassed by a raw `update_option`. A rule's id is derived from its URL pattern (`Rule_Set::id_for` = the pattern's `url_hash`) — the pattern is the identity, so the ruleset can never hold two differently-configured rules for one URL; a client-supplied id is ignored.

| Verb | Args | Returns |
|------|------|---------|
| `list` | — | `{ rules: [...] }` — every rule, with a pointer-tier rule's hooks resolved to the full list (`hooks_for`) and `hooks_in` normalized to `'inline'` (the storage tier is a `Rule_Set` implementation detail the editor never sees). |
| `save` | `{ rules (required, JSON array of rule objects) }` | `{ saved: int }` — whole-list replace. Each entry decodes via `Rule::from_array`; ids are re-derived from patterns and duplicate patterns collapse to one. Payload capped at 64KB, decode depth 12. |
| `upsert` | `{ rule (required, JSON rule object) }` | `{ rule: {...} }` — single add/replace keyed by pattern. A same-pattern rule is replaced in place (preserving its id); an edit that carries the old id and changes the pattern rekeys and drops the old-pattern entry. This is the performance-dashboard "log this URL" path. |
| `delete` | `{ id (required) }` | `{ deleted: bool }` — drop the matching rule and re-save. |
| `reset` | — | `{ reset: int }` — DELETE the stored ruleset option so the file config seeds again, and report the seeded rule count. Storing `[]` instead would pin an explicit "log nothing" over the config seed; only an absent row reseeds. Sweeps every pointer rule's durable hooks option on the way out. |

### `servers` — remote-spoke registry CRUD (replaced by the substrate `vault` CI)

The old `servers` CI and its `Server_Registry` backing store were deleted. Remote-spoke credentials now live in the substrate **Vault**, managed through the substrate `vault` CI. See `../newspack-nodes/API.md`.

### `aggregator` — hub-side status (moved to the substrate)

Moved to the substrate `aggregator` CI (newspack-nodes), which reports per-spoke `Remote_Source_Node` status. See `../newspack-nodes/API.md`.

### `performance` — the omnibus dashboard CI

The largest CI; every Performance-tree dashboard verb lives here. Its stats verbs build one `Stats_Store` per partition, reading `num_partitions` straight from substrate config — **unclamped**.

| Verb | Args | Returns |
|------|------|---------|
| `overview` | `{ server?, breakdown?, categories?: bool }` | High-level performance stats across all partitions. Comma-separated `breakdown`: single-dim returns `breakdown_time_series` flat; multi-dim returns `breakdowns` keyed by dim. `categories=true` adds `category_time_series`. Requires `manage_options`. |
| `urls` | `{ sort? = 'count', order? = 'desc', limit?: int = 50 (1..1000), offset?: int = 0 (0..10000), search?, server? }` | `{ data: [...], total, limit, offset }` — paginated/sortable URL leaderboard. Sort whitelisted against `URL_SORTS`; unknown sort falls back to `count`. Requires `manage_options`. |
| `url_detail` | `{ hash (required, `[a-f0-9]{8,64}`), server?, breakdown?, categories?: bool, since?: int = 0 }` | `{ stats, requests, scan_stopped_early, requests_window_start, aggregate_flame, aggregate_profiles, last_modified[, breakdown_time_series, category_time_series] }`. `requests` reaches back to `requests_window_start` and no further — the floor of the same window the modal's charts are drawn from, which is the stats-retention setting capped at the 288 five-minute buckets a reader enumerates (24h), so above a `min_lifetime` of ~86,100s the reach stays 24h whatever the setting says. A request that completed before it is absent by design, not by truncation. `since` TAILS that list: the walk stops below that epoch, so `requests` then reaches back only to the watermark while `requests_window_start` still names the window's floor — the caller is expected to be accumulating, and the dashboard's merge does. It is compared against a request's COMPLETION, not its start, and the stop is exclusive, so a request sharing the watermark's second is still returned. `scan_stopped_early` is true when the walk instead spent `MAX_INDEX_ENTRIES` before the caller was satisfied, so an empty `requests` is not an idle URL. Throws TM_ERROR `URL not found` for unknown hashes. Requires `manage_options`. |
| `url_breakdown` | `{ hash (required, `[a-f0-9]{8,64}`), breakdown (required, one of `DIMENSIONS`) }` | `{ breakdown_time_series }` and nothing else — memcache only, no index walk, for the chart that polls one dimension while the URL modal is open. Throws TM_ERROR `invalid hash format` / `invalid breakdown dimension`. Requires `manage_options`. |
| `request_search` | `{ rid (required, non-empty) }` | `{ rid, partition, url_hash }` so the dashboard can deep-link without scanning every partition. Throws TM_ERROR `Request not found` for an unknown rid, and `request index scan budget spent before rid <rid> was reached` when the walk ended before reaching it — an incomplete search is not a definite negative. Requires `manage_options`. |
| `request_grep` | `{ pattern (required), limit?: int = 20 (1..50) }` | `{ pattern, scope, scanned_partitions, results: [...], truncated, result_count }` — pattern-search across the recent firehose window, grouped by request. Shares its matching/grouping engine with `wp nodes reqgrep` (`Reqgrep_Core`) so both agree on what matches. Requires `manage_options`. |
| `request_detail` | `{ rid (required), partition?: int = 0 }` | Full request body + merged flame data. Throws TM_ERROR `invalid partition` for out-of-range partition; `Request not found` for unknown rid; the `budget spent` message above when the walk ended first. Requires `manage_options`. |
| `hooks_registered` | — | `{ total_hooks, categories, hooks_by_category }`. Requires `manage_options`. |
| `set` | `{ option (required, string), value (required, string) }` | `{ option, updated: bool }`. Normalized positional single-option writer (`set <option> <value>`) for a 3-option whitelist (`newspack_event_logger_nodes_rules` → array, `_log_memory` → bool, `_flush_every_line` → bool); array-typed options carry their value as JSON. Autoload follows `AppConfig::autoload_for`; the write emits a settings event that `Settings_Sync_Node` fans out to spokes. Requires `manage_options`. |

## Substrate verbs the dashboards use

The dashboards also call into substrate-owned CIs over the same `/command` endpoint. They're listed here for orientation — see `../newspack-nodes/API.md` for full schemas.

| TO | Verb examples | Used by |
|----|---------------|---------|
| `workers` | `list`, `restart`, `heartbeat`, `dump_graph`, `cleanup_status` | Workers dashboard, performance dashboards' restart action, every SSE dashboard's `_heartbeat` keep-alive |
| `_http/<ci-name>` | (transport wrapper) | All dashboards — the React graphs target `_http/<ci-name>` so the substrate's `Http_Out_Node` routes the reply back to FROM |

`_http/workers` is the canonical heartbeat target used by every SSE dashboard's `_heartbeat` node to keep the slot alive.

## SSE: `/messages/stream`

The substrate's single SSE surface. A client subscribes to one or more `<log>.p<N>` partitions; the server emits a 7-field message envelope per data line plus an idle `heartbeat` event.

```
GET /wp-json/newspack-nodes/v1/messages/stream?subscribe=<log>.p<N>[,<log>.p<N>...][&positions=...]
```

Per-line transforms live in the browser, inside each dashboard's view node (e.g. `RequestLogViewNode`, `GyroscopeViewNode`, `PerfErrorsViewNode`); the browser consumes the stream through the runtime `_sse` node (`SseInNode`) inside each dashboard's node graph. The slot TTL (60s) is the same for every caller; hub-side aggregator connections (`Remote_Source_Node` cURL pulls) send `workers.heartbeat` every 10s, browsers every 5s.

Operational discipline:
- Memcache slot pool gates connections; new connections fail with **HTTP 429** when the pool is full or memcache is unreachable (fail-closed).
- Two heartbeats: server→client SSE `heartbeat` events when no data flows; client→server keepalive that refreshes the slot. **Only the client refreshes a slot's TTL** — the server-side check is check-only. Each client's TTL must outlive its own heartbeat interval.
- Flush before the framework sleeps, NOT per-event. Per-event flushing tanks throughput on TLS/proxy paths.
- No application-level connection timeout — the stream disables PHP's execution time limit. A connection ends only when the slot lease is lost or the client disconnects; infrastructure limits (PHP-FPM, a proxy) can still cap it from outside the application.

See [`../newspack-nodes/API.md`](../newspack-nodes/API.md) for full request/response shape and subscription syntax.

## Worker Spawn

The runtime substrate's HMAC-validated worker bootstrap endpoint, listed for orientation:

```
POST /wp-json/newspack-nodes/v1/workers/spawn
```

Not for public callers. This path is owned/defined by the **substrate** (`newspack-nodes`); nothing in this plugin registers or constrains it. Treat [`../newspack-nodes/API.md`](../newspack-nodes/API.md) as authoritative for its exact request/response shape and HMAC authentication details.

## MCP

```
POST /wp-json/newspack-event-logger-nodes/v1/mcp
```

A JSON-RPC MCP server over verbs this plugin already answers — `initialize`,
`tools/list`, `tools/call`, one tool per verb, replies returned verbatim. It
adds no runtime surface: `tools/call` mounts the same request graph `/command`
does and dispatches through the same interpreter.

**Permission**: `Authorization: Bearer <handle>.<key>`, naming a live command
session (issue one under Nodes → Sessions, or `POST /newspack-nodes/v1/auth`).
The request then BECOMES that session's minting user and applies its scope as a
ceiling — so authority is the user's and the scope only ever subtracts, and
`tools/list` offers only the tools that scope covers. A `read` session sees the
performance tools and `rules list`; `tune` additionally sees `rules upsert` and
`rules delete`.

Every tool description carries the measurement caveat, because a model handed
`175.6ms profiled / 420000ms duration` with nothing saying what is unmeasured
will invent a cause for the difference — and the invented cause reads exactly
like a finding. The same caveat is `initialize`'s `instructions`.

A verb refusal comes back as an MCP tool error (`result.isError`), not a
transport error: the call reached the server and was answered.

Nothing here assumes an agent will act on instructions found in a page. Wiring
a client up is a deliberate act by the operator; the endpoint advertises itself
in prose aimed at a human, and refuses everything without a credential.
