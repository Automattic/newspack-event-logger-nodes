# Newspack Event Logger Nodes API

The plugin registers exactly **one** REST route of its own — the MCP server. Every other
call a client makes over HTTP is a verb on a service Command_Interpreter (CI) node,
addressed by name through the substrate's command endpoint. Beside that wire surface the
plugin exposes two WP-CLI verbs, the `Log_Manager` PHP API sibling plugins log through,
and the WordPress hooks it fires and consumes.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-event-logger-nodes/v1/mcp` | this plugin (`App\MCP_Controller`) | JSON-RPC MCP server over ten verbs the dashboards already drive. |
| `POST /wp-json/newspack-nodes/v1/command` | substrate (`Rest\HTTP_In_Node`) | Routes a batch of packed command Messages to named CI nodes and writes the replies back. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate (`Rest\SSE_Out_Node`) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. |
| `GET /wp-json/newspack-nodes/v1/log/stream` | substrate (`Rest\Log_Stream_Out_Node`) | The same stream over a named log-registry source rather than a partition. |
| `POST /wp-json/newspack-nodes/v1/auth` | substrate (`Rest\Auth_Controller`) | Mints the scoped command session an MCP bearer credential names. |
| `POST /wp-json/newspack-nodes/v1/workers/spawn` | substrate (`Rest\Spawn_Controller`) | HMAC-validated worker bootstrap. Not for public callers. |
| `POST /wp-json/newspack-nodes/v1/health/cache` | substrate (`Rest\Health_Cache_Controller`) | Token-gated cache probe the state doctor calls. |

This plugin contributes the verbs its three CIs expose; it registers no route under the
`newspack-nodes/v1` namespace. See [`../../newspack-nodes/docs/API.md`](../../newspack-nodes/docs/API.md)
for the substrate's own wire shapes.

## Authentication and rate limiting

Every door runs `Bootstrap::fleet_gate()` first: the fleet is network-global, so it runs on
the main site alone and a multisite subsite gets `403 Forbidden`.

`/command` and `/messages/stream` then gate on the substrate's lowest role,
`Capabilities::READ`. That role resolves to `manage_options` on a stock install and to
`newspack_nodes_read` once `wp nodes caps install` swaps in the granular capabilities;
`newspack_nodes/capability_map` overrides either. The door demands the least any verb
behind it needs, and authority is decided per verb: `Service_CI_Node` wraps each handler in
`Capabilities::require()` for the role its schema declares, and a verb declaring none takes
MANAGE. **No handler in this plugin re-checks a capability** — one that did would outrank
its own declaration without saying so.

`/command` also carries a per-user burst limit (`HTTP_In_Node::check_rate_limit`):
`RATE_LIMIT_BURST = 30` POSTs per `RATE_LIMIT_WINDOW_S = 1` second, bucketed by
clock-second and transient-backed, answering `429 Too Many Requests` on overflow. The
budget is tunable through the `newspack_nodes/command_rate_limit` filter, clamped to a
minimum of 1. The capability is verified before the limit, so an unauthenticated flood
cannot poison the transient table.

The MCP route meters itself: `RATE_LIMIT_BURST = 20` calls per `RATE_LIMIT_WINDOW_S = 10`
seconds, keyed by session handle. MCP does not go through `/command`, so the substrate's
per-user cap does not bound it.

SSE rate-limiting is independent and **fail-closed**: `SSE_Out_Node` consults
`\Newspack_Nodes\SSE_Slot_Pool` before opening headers, and memcache down means HTTP 429.
The slot pool IS the rate limit and cannot fall through silently.

## Sending a command

The `/command` body is **JSONL: one packed Message per line**, each a 7-element positional
JSON array in `Message` field order — `[TYPE, TIMESTAMP, FROM, TO, ID, KEY, VALUE]`. TYPE
is `TM_COMMAND` (8), TO names the CI node, and VALUE carries `{ name, arguments }`:

```http
POST /wp-json/newspack-nodes/v1/command HTTP/1.1
Content-Type: application/json

[8,1756900000,"","performance","","",{"name":"urls","arguments":["--limit=25","--server=example.com"],"auth":{"nonce":"…","sig":"…"}}]
```

- `TO` is the service CI name. This plugin owns `performance`, `discovery` and `rules`. A
  sub-path addresses a child node; most callers name the CI alone.
- `FROM` is the sender's own node name, and the door prepends `_output` to it on the way
  in. A caller with no graph of its own leaves it empty, and the reply lands in the
  response body.
- `name` is the verb.
- `arguments` is a **flat token array**, the one grammar `Command_Args::parse()` (PHP) and
  `parseCommandArgs()` (browser) both read: required values ride positionally in the order
  the verb declares, optional ones are `--key=value`, a boolean flag is a bare `--key`
  (`--key=false` to turn one off), and a list is comma-separated inside one value. The
  verb tables below say which of its arguments each verb reads positionally.
- `auth` is the HMAC envelope `Command_Auth::sign()` stamps: a nonce and a signature over
  `[ts, name, arguments, nonce]`, plus a `handle` when the key is a session key. An
  in-process (LOCAL) command needs none; anything arriving over the wire is refused
  without one.

The reply is a `TM_COMMAND|TM_RESPONSE` envelope sent back via TO=FROM, carrying the verb's
return value in VALUE. A verb that throws answers `TM_COMMAND|TM_ERROR` instead; the
dashboard view's pending-Map handler converts the structured `{ message }` payload into a
rejected Promise (see architecture-guide.md → "Canonical view contract").

The HTTP status is decided once, when the body opens. The first reply written sends 200, or
401 if a command had already been refused by then. A batch that writes nothing back answers
401 when any command was refused and 202 otherwise — the work routed onward, and its replies
are due on the caller's own SSE stream.

## Service CIs

Each subsection below lists the verbs the corresponding `includes/app/class-<name>-ci-node.php`
(`<Name>_CI_Node`) exposes. All three declare their verbs in a static
`node_schema()['commands']` array — name, capability, args and handler — and the inherited
`Service_CI_Node` constructor builds the dispatch table from that declaration, so none
defines a per-class constructor. **TO=`<ci-name>`, `name`=`<verb>`** addresses a verb.

The handlers live in this plugin's `Newspack_Event_Logger_Nodes\App\` namespace:
`newspack-event-logger-nodes.php` registers it with
`Command_Interpreter_Node::register_namespace()`, and the CIs mount on the substrate's
`newspack_nodes/request_graph_ready` action.

### `discovery` — spoke-side hook and event roster

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `get` | READ | — | `{ registered_hooks: string[], custom_events: string[] }` — the union across every LOG rule of its instrumented hooks and custom events (`Rule_Set::instrumented_union()`), with `custom_events` filtered out of `registered_hooks` so the picker's two catalogs stay disjoint. |

Two callers ask it: the hub's `Discovery_Collector_Node`, which union-merges every spoke's
reply into the `discovered_hooks` / `discovered_events` staging options behind the rules
editor's hook picker, and the substrate `vault` CI's `test` verb, which probes it to check
one spoke's connection. It reports the ruleset and never writes it — the editor is the only
rules writer. A spoke's stored credentials (Basic application password, or Bearer) carry a
user holding the READ role, which is what satisfies the gate on the far end.

### `rules` — per-URL logging ruleset CRUD

Backs the "Logging Rules" editor on the settings page. All five verbs route through
`Rule_Set`, so the inline↔pointer hook-tiering and orphan-reconcile invariants can never be
bypassed by a raw `update_option()`. A rule's id is the pattern's hash — `Rule_Set::id_for()`
runs the pattern through `Log_Manager::url_hash()` — so the pattern is the identity and the
ruleset can never hold two differently-configured rules for one URL. `upsert` reads a
client-supplied id to find the entry an edit is moving, but what it stores is always
re-derived from the pattern.

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `list` | READ | — | `{ rules: [...] }` — every rule, with a pointer-tier rule's hooks resolved to the full list (`hooks_for`) and `hooks_in` normalized to `'inline'`. The storage tier is a `Rule_Set` decision the editor never makes. |
| `save` | TUNE | `rules` (required, raw JSON array as the first token) | `{ saved: int }` — whole-list replace. Each entry decodes through `Rule::from_array()`, ids are re-derived from patterns, and duplicate patterns collapse to one. One unrepresentable entry throws before anything is stored, so a replace is all or nothing. |
| `upsert` | TUNE | `rule` (required, raw JSON object as the first token) | `{ rule: {...} }` — single add/replace keyed by pattern. A same-pattern rule is replaced in place; an edit carrying the old id and a changed pattern rekeys and drops the old-pattern entry. This is the performance dashboard's "log this URL" path. |
| `delete` | TUNE | `id` (required, positional) | `{ deleted: bool }` — drop the matching rule and re-save. |
| `reset` | TUNE | — | `{ reset: int }` — DELETE the stored ruleset option so the file config seeds again, and report the seeded rule count. Storing `[]` instead would pin an explicit "log nothing" over the config seed; only an absent row reseeds. Sweeps every pointer rule's durable hooks option on the way out. |

`save` and `upsert` read their blob as the raw first token, not through `Command_Args` — a
JSON blob carries its own structure and there is nothing to classify. Both refuse a payload
over `MAX_JSON_BYTES` (65536) before decoding, decode at `MAX_JSON_DEPTH` (12), and refuse
anything that does not come back an array.

#### The rule wire shape

`Rule::to_array()` is what `list` returns, what `save` and `upsert` accept, and what the
hub syncs to spokes:

| Field | Type | Meaning |
|-------|------|---------|
| `id` | string | The pattern's `Log_Manager::url_hash()`, re-derived on every write. A new rule sends `''`; an edit sends the id it already has, which is how `upsert` finds the entry whose pattern moved. |
| `pattern` | string | `/prefix`, exact `/path?`, or exact path plus query prefix `/path?query`. Required. |
| `action` | string | `log` or `skip`. Anything but `log` reads as `skip`. |
| `auto_disable_threshold` | int | Per-request occurrence count above which auto-tune proposes disabling a hook or custom event; 0 is off. |
| `auto_protect_time_threshold` | float | Average ms per call at or above which auto-tune promotes a hook to significant; 0.0 is off. |
| `significant_events` | string[] | Hooks that get per-callback profiling and are exempt from auto-disable. |
| `custom_events` | string[] | Categories the application logs itself; never bound as `do_action` hooks. |
| `hooks` | string[] \| null | The inline list, or null for the pointer tier. |
| `hooks_in` | string | `inline` or `mc`. Must agree with `hooks`, or the constructor throws. |
| `log_queries` | bool | Time every SQL query as its own span. Needs `SAVEQUERIES` and costs two entries per query. Default false. |
| `log_http` | bool | Time every outbound HTTP request as a span, between `pre_http_request` and `http_api_debug`. **Absent means ON** — only an explicit false retires a live span. |
| `trace_hooks` | bool | Name the calling frame on each hook entry's aggregation label, so one hook firing sixteen times splits into a flame node per caller. Default false. |
| `trace_callers` | int | Deep caller chains one hook may record per request, on its start entry's `caller` field; 0 is off, and a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT` (20). |

### `performance` — the omnibus dashboard CI

The largest CI; every Performance-tree dashboard verb lives here. Its stats verbs build one
`Stats_Store` per FLAME-BUILDER WORKER, over the indices
`Bootstrap::node_partitions( 'flame-builder' )` reports across every active topology that
declares the node — the stats live in memcache alone, so the index space is the declaring
topology's worker count and not a partition-dir listing. With no shared cache backend the
list is empty, and every stats reader degrades to an empty or zeroed shape. Disk-walking
verbs work regardless. Every handler throws on bad input and the interpreter wraps the throw
as a TM_ERROR reply, so no handler returns an error shape.

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `overview` | READ | `--server`, `--breakdown` (comma-separated), `--categories` | `{ total_requests, global_avg_ms, global_avg_peak_mb, aggregate_time_series, global_leaderboard }`, plus `breakdowns` keyed by dimension when `breakdown` is given and `category_time_series` when `categories` is. The site totals come from the global `hourly` namespace, which has no server dimension; `server` scopes the leaderboard and the breakdowns only. A dimension outside `DIMENSIONS` throws `invalid breakdown dimension` rather than answering about the rest. |
| `urls` | READ | `--sort` (default `count`), `--order` (`desc`), `--limit` (50, clamped 1–1000), `--offset` (0, clamped 0–10000), `--search`, `--server`, `--errors_only`, `--include_workers` | `{ data, rows, totals, slowest, filters, limit, offset }` — the paginated, sortable URL leaderboard plus the totals and the slowest ten for whatever the filters left. An unknown `sort` falls back to `count`, an unknown `order` to `desc`. Worker traffic is excluded until `include_workers` opts it in. `totals` is null for a server scope the stored rows carry no split for, because 0 would read as idle; `filters` echoes what was applied, so a narrower number never reads as the site's. |
| `url_detail` | READ | `hash` (required, positional, `[a-f0-9]{8,64}`), `--server`, `--breakdown`, `--categories`, `--since` | `{ stats, requests, scan_stopped_early, requests_window_start, aggregate_flame, aggregate_profiles, last_modified }`, plus `breakdown_time_series` and `category_time_series` when asked for. Throws `URL not found` for an unknown hash and `invalid hash format` for a malformed one. |
| `url_breakdown` | READ | `hash` (required, positional), `--breakdown` (required) | `{ breakdown_time_series }` and nothing else — memcache only, no index walk, for the chart that polls one dimension while the URL modal is open. Throws `invalid hash format` / `invalid breakdown dimension`. |
| `request_search` | READ | `rid` (required, positional) | `{ rid, partition, url_hash }`, so the dashboard can deep-link without scanning every partition. Throws `Request not found` for an unknown rid, and `request index scan budget spent before rid <rid> was reached` when the walk ended first — an incomplete search is not a definite negative. |
| `request_grep` | READ | `pattern` (required, positional), `--limit` (default 20, max 50) | `{ pattern, scope, scanned_partitions, results, truncated, result_count }` — literal, case-insensitive search across the recent firehose window, grouped by request. `scope` is always `recent`: every partition's walk starts at the second-to-last segment, and `truncated` says it spent `GREP_MAX_SCAN_LINES` before finishing. Each result carries `rid`, `url`, `method`, `ts`, `match_count` and `first_match_excerpt`. Shares its matching and grouping engine with `wp nodes reqgrep` (`Reqgrep_Core`), so both agree on what matched. |
| `request_detail` | READ | `rid` (required, positional), `--partition` (default 0) | The full request body and merged flame data, plus computed `findings` and the measurement `caveat`. `partition` is a hint: searched first, then the rest, so any rid `request_search` locates resolves here too. Throws `invalid partition` for an out-of-range partition, `Request not found` for an unknown rid, and the `budget spent` message above. |
| `ask` | READ | `descriptor` (required, positional; further context descriptors follow it, outermost last), `--server`, `--context` | The brief for one picker descriptor. |
| `hooks_registered` | READ | — | `{ total_hooks, categories, category_descriptions, hooks_by_category }`. |
| `set` | TUNE | `option` and `value` (both required, positional) | `{ option, updated: bool }`. |

Notable bounds, all `Performance_CI_Node` constants: `MAX_INDEX_ENTRIES` 1,000,000,
`RECENT_REQUEST_LIMIT` 500, `GREP_MAX_SCAN_LINES` 200,000, `SLOWEST_ROWS` 10,
`RECENT_BUCKETS` 12, `INDEX_READ_CHUNK` 12. `DIMENSIONS` is `status, method, server,
country, from, ua, ja4`; `URL_SORTS` is `count, url, avg_ms, min_ms, max_ms, avg_peak_mb,
last_updated`.

**`url_detail`'s request window.** `requests` reaches back to `requests_window_start` and no
further — the floor of the same window the modal's charts are drawn from, which is the
stats-retention setting capped at the 288 five-minute buckets a reader enumerates (24h), so
above a `min_lifetime` of ~86,100s the reach stays 24h whatever the setting says. A request
that completed before it is absent by design, not by truncation. `since` TAILS that list:
the walk stops below that epoch, so `requests` then reaches back only to the watermark while
`requests_window_start` still names the window's floor — the caller is expected to be
accumulating, and the dashboard's merge does. It is compared against a request's COMPLETION,
not its start, and the stop is exclusive, so a request sharing the watermark's second is
still returned. `scan_stopped_early` is true when the walk instead spent `MAX_INDEX_ENTRIES`
before the caller was satisfied, so an empty `requests` is not an idle URL.

**`ask` descriptors** are `url:<hash>`, `request:<rid>[:<partition>]`, `span:<name>`,
`entry:<n>` and `category:<name>`. A `span:` or `entry:` brief also needs its `request:`
descriptor as context, outermost last; on `request:` the partition is a hint, not a filter.
Every brief carries the measurement `caveat`, and the `request:`, `span:` and `url:` briefs
carry a `fetch` pointer naming the MCP tool that returns the same subject in full. A `span:`
brief answers for the parent whose copies of that name hold the most time, and carries
`elsewhere` (`ms`, `count`, `parents`) for the copies under other parents — omitted when
every copy sits under one parent. `server` scopes a `url:` brief the way `urls` scopes its
rows. An unparseable descriptor throws `unknown descriptor`.

**`set`** is the normalized positional single-option writer (`set <option> <value>`) over a
three-option whitelist: `newspack_event_logger_nodes_rules` (array),
`newspack_event_logger_nodes_log_memory` (bool) and
`newspack_event_logger_nodes_flush_every_line` (bool). An option absent from it is refused
as `unknown option`, so the whitelist and `hub-control.tsl`'s `add_setting` lines must stay
in step. Array-typed options carry their value as JSON. A set to the value already in place
answers `updated: false` without saving, because the hub re-pushes every synced option on its
sweep whether or not it moved and a reload fires `Config::RESET_ACTION` on every worker,
which re-parses every `.tsl` for the same answer. The ruleset routes to
`Rule_Set::apply_synced()` instead, which re-tiers and holds its own gate. Autoload follows
`Config::autoload_for()`, and the write emits a settings event that `Settings_Sync_Node`
fans out to spokes.

## Substrate verbs the dashboards use

The dashboards call into substrate-owned CIs over the same `/command` endpoint. Ten mount
on `newspack_nodes/request_graph_ready`: `classes`, `layouts`, `topologies`, `raw-logs`,
`vault`, `aggregator`, `settings`, `status`, `sessions` and `workers`. Five matter to this
plugin's operators.

| TO | Verbs | What it answers |
|----|-------|-----------------|
| `workers` | `list`, `dump_graph`, `cleanup_status`, `restart`, `heartbeat` | The fleet, and the SSE slot keep-alive every dashboard pokes. |
| `status` | `get` | A literal `status: ok`, the `runtime_version`, `num_partitions`, the active `topologies`, `cache_available` and a `timestamp`. It carries no application version field. |
| `settings` | `get`, `set` | The seven substrate-owned integer settings: `num_partitions`, `segment_size`, `min_segments`, `num_segments`, `min_lifetime`, `lifetime`, `max_segments`. |
| `vault` | `list`, `get`, `add`, `update`, `delete`, `test` | Remote-spoke credentials. This is where a spoke's URL and Authorization header live. |
| `aggregator` | `summary`, `servers_status`, `probe` | Per-spoke `Remote_Source_Node` status on the hub. |

The React graphs address `_http/<ci-name>`, so the browser runtime's `HttpOut` node POSTs
the command and routes the reply back by the TO the server echoed off the sender's FROM.
`_http/workers` is the heartbeat target: `mountExospine` wires the shared `_heartbeat` node
to it, and that is fixed wiring rather than a per-dashboard choice.

Three CI names a stale client may still address resolve to nothing: `servers`, `logger` and
`events`. Remote-spoke credentials live in the substrate `vault` CI, and
`performance.hooks_registered` and `performance.overview` answer what the other two did.

See [`../../newspack-nodes/docs/API.md`](../../newspack-nodes/docs/API.md) for full schemas.

## SSE: `/messages/stream`

A client subscribes to one or more `<log>.p<N>` partitions; the server emits a 7-field
message envelope per data line plus an idle `heartbeat` event.

```
GET /wp-json/newspack-nodes/v1/messages/stream?subscribe=<log>.p<N>[,<log>.p<N>...][&positions=...][&multi_writer=1]
```

`subscribe` is required and takes a comma-separated list; `positions` carries the resume
cursors; `multi_writer` is the client's assertion that more than one process appends the
subscribed logs, which buys the reader a grace window and costs nothing but that when wrong.

Per-line transforms live in the browser, inside each dashboard's view node
(`RequestLogViewNode`, `GyroscopeViewNode`, `PerfErrorsViewNode`); the browser consumes the
stream through the `<link>:sse-in` node (`SseInNode`) each `RemoteLink` owns. The slot TTL is
the same for every caller — the `sse_slot_ttl` setting, default 60s, floored at 45s by
`SSE_Slot_Pool::ttl()` — and both a browser and a hub-side `Remote_Source_Node` poke
`workers.heartbeat` every 15 seconds, so one lost poke still leaves a refresh before expiry.

Operational discipline:

- The memcache slot pool gates connections; a new connection fails with **HTTP 429** when
  the pool is full or memcache is unreachable (fail-closed).
- Two heartbeats: server→client SSE `heartbeat` events when no data flows, and client→server
  keep-alive that refreshes the slot. **Only the client refreshes a slot's TTL** — the
  server-side check is check-only. Each client's TTL must outlive its own poke interval.
- Flush before the framework sleeps, NOT per event. Per-event flushing tanks throughput on
  TLS and proxy paths.
- No application-level connection timeout — the stream disables PHP's execution time limit.
  A connection ends when the slot lease is lost or the client disconnects; infrastructure
  limits (PHP-FPM, a proxy) can still cap it from outside the application.

## Worker spawn

```
POST /wp-json/newspack-nodes/v1/workers/spawn
```

The substrate's HMAC-validated worker bootstrap, listed for orientation. Not for public
callers. Nothing in this plugin registers or constrains it; treat
[`../../newspack-nodes/docs/API.md`](../../newspack-nodes/docs/API.md) as authoritative for
its request shape and HMAC authentication.

## MCP

```
POST /wp-json/newspack-event-logger-nodes/v1/mcp
```

A JSON-RPC MCP server over verbs this plugin already answers, speaking protocol revision
`2025-06-18`. One POST carries every method: `initialize`, `notifications/initialized`
(answered with nothing — JSON-RPC forbids replying to a notification), `tools/list` and
`tools/call`. It adds no runtime surface: `tools/call` mounts the same request graph
`/command` does, through `Bootstrap::mount_request_graph()`, and dispatches through the same
interpreter.

**Permission**: `Authorization: Bearer <handle>.<key>` — 32 hex, a dot, 64 hex — naming a
live command session (issue one from the Nodes hub's Sessions tab, or from
`POST /wp-json/newspack-nodes/v1/auth`). The request then BECOMES that session's minting
user and installs its scope as a ceiling, so authority is the user's and the scope only ever
subtracts: a manage-scoped session minted by someone who can do nothing still does nothing.
`tools/list` offers only the tools the scope covers.

Ten tools, one per verb. Each tool's named arguments go through `Command_Args` on the way in,
and the verb's reply comes back verbatim:

| Tool | Node.verb | Role |
|------|-----------|------|
| `performance_overview` | `performance.overview` | READ |
| `performance_urls` | `performance.urls` | READ |
| `performance_url_detail` | `performance.url_detail` | READ |
| `performance_request_search` | `performance.request_search` | READ |
| `performance_request_detail` | `performance.request_detail` | READ |
| `performance_request_grep` | `performance.request_grep` | READ |
| `performance_ask` | `performance.ask` | READ |
| `rules_list` | `rules.list` | READ |
| `rules_upsert` | `rules.upsert` | TUNE |
| `rules_delete` | `rules.delete` | TUNE |

So a `read` session sees the seven performance tools and `rules_list`; `tune` additionally
sees `rules_upsert` and `rules_delete`. Six verbs have no tool at all and are reachable only
over `/command`: `performance.url_breakdown`, `performance.hooks_registered`,
`performance.set`, `rules.save`, `rules.reset` and `discovery.get`. `POSITIONAL_ARGS` —
`descriptor, hash, rid, pattern, rule, id, context` — is the order bare tokens are emitted
in; every other argument becomes `--key=value`.

**Connecting a client**. Register the endpoint with the handle and key that session issued.
`<ID>` is the local name the client files it under:

```
claude mcp add --transport http <ID> https://<DOMAIN>/wp-json/newspack-event-logger-nodes/v1/mcp --header "Authorization: Bearer <HANDLE>.<KEY>"
```

Every tool description carries the measurement caveat, because a model handed
`175.6ms profiled / 420000ms duration` with nothing saying what is unmeasured will invent a
cause for the difference — and the invented cause reads exactly like a finding. The same
caveat is `initialize`'s `instructions`.

A verb refusal comes back as an MCP tool error (`result.isError`), not a transport error:
the call reached the server and was answered.

Nothing here assumes an agent will act on instructions found in a page. Wiring a client up
is a deliberate act by the operator; the endpoint advertises itself in prose aimed at a
human, and refuses everything without a credential.

## WP-CLI

Both verbs register under the substrate's `nodes` namespace, in the deferred bootstrap, and
both run `@when after_wp_load`.

### `wp nodes reqgrep [<pattern>]`

Filter the firehose by request id, URL or any text, and print each matching request as an
indented lifecycle tree. It collects every entry sharing a request id once any line for that
rid matches. Stdin wins over every other source: with data on it, `--follow` and `--recent`
are ignored.

| Flag | Meaning |
|------|---------|
| `<pattern>` | Search pattern — a rid, a URL, or any text. `Reqgrep_Core::compile()` `preg_quote`s it, so it matches as a LITERAL, case-insensitively, and regex metacharacters carry no meaning. Omitting it takes the default `.`, which every packed line's float timestamp contains. |
| `--follow` | Tail mode: keep reading and printing new requests as they finish. |
| `--recent` | Scan only the second-to-last segment and newer, for a fast lookup. |
| `--raw` | Emit raw JSONL instead of the formatted tree. |
| `--incomplete` | Show only requests that reached neither `process (complete)` nor `process (aborted)`. |
| `--bucket-size=<size>` | History bucket capacity, counting request ids plus lines. Default 250, clamped 1–10000. |
| `--num-buckets=<count>` | History buckets retained. Default 10, clamped 1–100. |
| `--firehose=<path>` | Override the firehose base directory. Must resolve inside `Config::get_logs_directory()`, which is validated first. |

The in-flight cache holds 100 items × 3 buckets, rotating every 60 seconds; anything falling
out of the oldest bucket prints as `[incomplete]`. `Reqgrep_Core` does the grouping, so this
command and `performance.request_grep` agree byte for byte on what belongs to which request.

### `wp nodes ruleset-bench [--iterations=<n>]`

Measurement only, off the request hot path. Sweeps a grid of hooks-per-rule × rule-count and
prints the median autoload, inline and pointer cost in microseconds per cell — what
`Rule_Set::INLINE_HOOK_LIMIT` (100) is calibrated from. `--iterations` defaults to 200 and
clamps to a minimum of 1; a higher count buys a steadier median at the cost of runtime. With
no cache backend the sweep still runs but warns, because the pointer column then reports the
bind loop alone.

## PHP API

`Log_Manager` is the class other plugins log through. Pyrobase and Nuclear Gyrobase both
call `Log_Manager::instance()`; the substrate's job worker reaches it through the
`begin_job_context_filter()` / `end_job_context()` pair.

One instance governs one request context. Construction resolves the rule matching
`REQUEST_URI` and starts logging when that rule says `log`; a `skip` rule, no rule at all,
`enable_logging` off, or a root process leaves the instance inert and every write returns
false.

| Method | What it does |
|--------|--------------|
| `static instance(): self` | The active instance, constructed on first call. Construction is what may start logging. |
| `static has_instance(): bool` | Whether one exists, without constructing. Instrumentation asks this first. |
| `static started_instance(): ?self` | The instance IFF it has started — the seam for "is there somewhere to log this line?". |
| `static reset(): void` | Finish and drop the instance. |
| `message( string $category, array $data = [] ): bool` | The one write path. Returns true when the line was written. |
| `error/warning/info/alert( string ): bool` | One-line writes under those categories. `error` and `warning` reach the Error Log; `alert` routes to `Request_Builder_Node`'s `alerts_target`, the fleet journal, and nowhere else. |
| `start( string $label, array $data = [] ): void` | Open a timed span and push its frame. Drops the frame at `MAX_TIMER_DEPTH` (100), or when the start line could not be written. |
| `complete( string $label, array $data = [], string $suffix = 'complete' ): void` | Close the innermost frame carrying that label; frames above it drain as `(orphaned)`. An unknown label matches nothing. |
| `finish(): void` | Idempotent. Drain the timer stack, then write the terminal. Registered as a shutdown function, so it runs after a fatal too. |
| `flush(): void` | Drain every materialized Partition batch. Nuclear Gyrobase calls it before `proc_open` and after a `job` entry. |
| `is_started(): bool` | The rule said `log` and `finish()` has not run. |
| `governing_rule(): ?Rule` / `governing_rule_id(): string` | The rule admitting this request. The id rides `process (start)` as `rule`. |
| `matches_url_filter( string ): bool` | Resolve and keep the governing rule for a URL. |
| `get_request_id(): string` / `get_partition(): int` | An empty rid means an unlogged request. |
| `refresh_firehose(): void` | Re-read the firehose segment state from disk, after a subprocess that may have written to or rotated it. |
| `relay_topic_to_ci( array ): void` | Lazy Topic→interpreter relay for an early-wired Topic. |
| `static suspend(): void` / `static resume(): void` | LIFO context stack. `suspend()` flushes the shared Topic and saves `UNIQUE_ID`; `resume()` restores in a `finally`, because `finish()` re-raises a cooperative stop. |
| `static begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void` | Snapshot `$_SERVER`, suspend, then rewrite to a synthetic `/jobs/{handler}/{id}`. Fires `newspack_event_logger_nodes_scope_changed`. |
| `static begin_job_context_filter( mixed $run, string $handler, string $id = '', array $message = [] ): mixed` | The `newspack_nodes/job_worker/before_job` shape: opens the context unless an earlier listener declined, passing the decision through untouched. |
| `static end_job_context( string $handler = '', string $id = '', ?array $outcome = null ): void` | The symmetric restore; the `$_SERVER` stack IS the pairing record, so an empty stack no-ops. Arity is the abort discriminator: `func_num_args() >= 3` with a null outcome marks the context aborted. |
| `static firehose_dirs( string $log_path = '' ): array` | Partition index → directory. The count is `Bootstrap::global_num_partitions()`; a topology's `var num_partitions` is its WORKER count and says nothing about the firehose. |
| `static firehose_dir_template( string $logs_dir = '<config:logs_dir>' ): string` | `{logs_dir}/firehose.p{partition}`. The `{partition}` spelling is load-bearing — `Topic_Node` substitutes only that one. |
| `static url_hash( string ): string` | 12-char FNV-1a. The shared URL identity primitive, also behind `Rule_Set::id_for()`. The two hash different inputs — a rule id hashes a PATTERN, a stats bucket a concrete URL — so don't join them. |
| `static fnv1a32( string, int $seed = 2166136261 ): int` | The hash underneath it. |
| `static generate_request_id(): string` | 32 base-36 characters over 25 random bytes. |
| `static redact_url( string ): string` | The ONE redaction path; public for that reason. Strips 21 sensitive query parameters. |

Public constants: `FATAL_TYPES`, and the four request keywords `REQUEST_LABEL` (`process`),
`REQUEST_START`, `REQUEST_COMPLETE` and `REQUEST_ABORTED`. The last two together are
`Request_Builder_Node::TERMINAL_KEYWORDS`, the pair that closes a record; without one it
strands in flight until eviction.

**Payload size.** `message()` enforces an encoded-data cap of 3840 bytes — headroom under
PIPE_BUF (4096), which is what keeps a lock-free append atomic against every other writer on
this multi-writer log. An oversize map is trimmed and marked `truncated => true`: `m` is
shortened in a re-encoding loop — re-encoding per step, because JSON escaping expands bytes —
then dropped outright, and every other key survives, `l` and `caller` included. Where the
remaining keys alone still exceed the cap, the floor case keeps `n`, `k` and `ts` and puts a
1000-character slice of the encoded map in `m`, so a reader can still place the entry.
Nothing is chunked and nothing reaches `error_log`; the entry says so itself. The category
is never renamed, because a `" (truncated)"` suffix would break `Flame_Tree::PATTERN_START`
and `Request_Builder_Node`'s `' (start)'` test, so the span would never open and its
`(complete)` would orphan. The constant is private, so a producer sizing its own payloads
keeps its own copy — Pyrobase's `Runtime\Log` does. Anything that can exceed it belongs in
`\Newspack_Nodes\Job_Intake::queue()`, which takes the auto-lock around large writes.

**The profiler drop-in.** `mu-plugins/00-newspack-profiler.php` publishes a `$newspack_profiler`
global carrying `request_time` (monotonic nanoseconds), `request_ts` (the matching wall
clock) and one `plugins` row per timed plugin. `Log_Manager`'s constructor adopts and unsets
the first two, which is what stamps `process (start)` with the moment PHP began the request
rather than the moment the logger emitted its first line, and stops a nested job context from
claiming them again. The drop-in depends on nothing; with the plugin inactive it simply goes
unread.

## Hooks

### Fired by this plugin

| Hook | Type | Where | Purpose |
|------|------|-------|---------|
| `newspack_event_logger_nodes_scope_changed` | action | `Log_Manager::begin_job_context()` and `::end_job_context()` | Both ends of a job context. `App\Core` rebinds its hook instrumentation to the restored scope's rule. |
| `newspack_event_logger_nodes/settings_after_form` | action | `Admin\Admin` | Renders below the settings form. Three sections subscribe: the rules editor (priority 5), the effective config, and maintenance. |
| `newspack_event_logger_nodes_custom_colors` | filter | `Config::get_custom_colors()` | Event name → hex swatch for the event picker. A non-array return drops every configured color. |

### Consumed from the substrate

| Hook | Callback |
|------|----------|
| `newspack_nodes/declare_config_keys` | `Config::register_config_keys` — registered at file scope with a literal name, because this plugin loads first. |
| `Newspack_Nodes\Config::RESET_ACTION` | `Config::reset_local_cache` — not `reset()`, which would re-enter the substrate. |
| `newspack_nodes/job_worker/before_job` (filter, 4 args) | `Log_Manager::begin_job_context_filter` |
| `newspack_nodes/job_worker/after_job` (action, 3 args) | `Log_Manager::end_job_context` |
| `newspack_nodes/settings_sync/value` (filter, 2 args) | `newspack_event_logger_nodes_resolve_settings_sync_value` — resolves a blank or absent value to the OWNING config's default, and hydrates a pointer rule's hooks so the ruleset ships hook-complete to spokes. |
| `newspack_nodes/registered_log_producers` | `newspack_event_logger_nodes_register_log_producers` — adds `Log_Manager::firehose_dir_template()`, so the dirs written and the dirs the log GC declares are one statement. |
| `newspack_nodes/before_reconcile` / `newspack_nodes/after_reconcile` | An anonymous pair sharing an `$entered` flag, giving the minute-cadence reconcile pass its own `/jobs/newspack-nodes` request context. |
| `newspack_nodes/stderr` | `Diagnostics_Bridge::on_stderr` — carries a substrate diagnostic into the active request or job log as a `stderr` entry, feeding the Error Log. |
| `newspack_nodes/request_graph_ready` | `newspack_event_logger_nodes_mount_service_cis` — mounts `discovery`, `performance` and `rules`. |
| `newspack_nodes/devtools_tab_bundles` | `Current_Request_Overlay::register_bundle` — adds the `current-request` bundle descriptor so the hub enqueues that tab. `Current_Request_Overlay` registers two `admin_enqueue_scripts` callbacks beside it: `enqueue_on_overlay_pages` at the default priority, for the ELN pages that embed the overlay themselves, and `enqueue_inline_data` at 20, which injects this request's id into the JS global the tab reads once both enqueue paths have run. |

Named substrate callables the bootstrap registers alongside them:

- The config namespace `eln`, resolving `<eln:KEY>` tokens in `.tsl` through
  `Config::resolve_eln_token()`. It owns three keys and returns null for any other:
  `is_hub` (whether a hub topology is active), `stats_mirror_node` (the durable stats
  Partition, `''` to turn the mirror off) and `stats_mirror_lifetime`, DERIVED as twice
  `Config::stats_retention_seconds()` rather than stored, so widening the stats window
  widens the mirror with it.
- Three `Formatters` — `request-index`, `flame-index` and `stats-index` — that the topology
  index legs reference, TSL having no closures.

The plugin binds `newspack_nodes/periodic`, `newspack_nodes/job_handlers` and
`newspack_nodes/remote_job_handlers` nowhere; the last two are read by the substrate's
`Job_Worker_Node`.

### Consumed from WordPress

`plugins_loaded` (priority 11, the deferred bootstrap), `rest_api_init`, `admin_menu`,
`admin_enqueue_scripts`, `admin_init`, `admin_post_newspack_event_logger_nodes_reset_settings`,
`updated_option`, `added_option` and `pre_update_option` (a filter, 3 args) in the admin.

The profiler drop-in binds three more at file scope, outside this plugin's bootstrap.
`option_active_plugins` (a filter at priority 1) takes the load baseline on its first firing:
a wall-clock and hrtime pair, plus the declared-class and included-file counts.
`wp_get_active_and_valid_plugins()` reads that option immediately ahead of the plugin loop,
the last point still outside every plugin, so anything reading `active_plugins` earlier in
bootstrap moves the baseline earlier with it. `plugin_loaded` (priority 1) fires after each
plugin's include and records the difference since the previous firing: elapsed time, new
classes and new files. That interval also covers the loop's own per-plugin work, because
WordPress offers no signal bracketing the include alone. `plugins_loaded` (priority -10001)
writes the rows to the firehose as `(start)` / `(complete)` span pairs, and only when the
governing rule said `log`; that priority sits one below the default `hook_start_priority`, so
the rows precede whatever `App\Core` records for the same hook. A listener short-circuiting
`pre_option_active_plugins` stops `option_active_plugins` from firing, so the baseline is
never taken and `plugin_loaded` records nothing. Site-activated plugins alone are timed:
must-use plugins announce themselves on `mu_plugin_loaded` and network-activated ones on
`network_plugin_loaded`, and the drop-in binds neither.

`App\Core` binds the rest, per governing rule. Each of a rule's `hooks` gets a start callback
at `hook_start_priority` (default -10000), a sacrificial no-op spacer at `PHP_INT_MAX - 2`,
and a complete callback at `PHP_INT_MAX - 1`. The spacer is what keeps that complete firing:
when a callback removes itself mid-run, `WP_Hook::resort_active_iterations()` parks the
pointer on the next surviving priority and `apply_filters`' `next()` skips it — the spacer is
consumed instead of `hook_complete`. A rule with `log_http` — the default — also gets the
outbound-HTTP span pair, `pre_http_request` at `PHP_INT_MAX` and `http_api_debug` at
`PHP_INT_MIN`; one with `log_queries` gets the query pair, `query` at `PHP_INT_MAX` and
`log_query_custom_data` at `PHP_INT_MIN`, and `SAVEQUERIES` is defined if it is not already.
Each pair opens after every filter that could rewrite or short-circuit the call and closes
ahead of the other listeners on that action, so the span covers the work and not its
audience. A short-circuited HTTP request opens no span at all: `WP_Http::request()` returns
a non-false `$preempt` without ever firing `http_api_debug`, so a span opened for one would
never close.

Two things `App\Core` will not instrument. It wraps only the callbacks registered strictly
between `hook_start_priority` and the spacer, so everything at or above the spacer reads as
its own and is left alone. And it skips any callback whose reflection reports a by-reference
parameter, because wrapping one breaks WordPress's by-reference contract. Each wrapper it
does install claims `accepted_args = 99` and slices back to the original's count before
calling it, so the original never sees an argument it did not ask for.
