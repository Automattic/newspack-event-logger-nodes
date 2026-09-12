# Newspack Event Logger Nodes API

The plugin registers exactly **one** REST route of its own — the MCP server. Every other
call a client makes over HTTP is a verb on a service Command_Interpreter (CI) node,
addressed by name through the substrate's command endpoint. Beside that wire surface the
plugin exposes two WP-CLI verbs, the [`Log_Manager`](../includes/class-log-manager.php) PHP API sibling plugins log through,
and the WordPress hooks it fires and consumes.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-event-logger-nodes/v1/mcp` | this plugin ([`App\MCP_Controller`](../includes/app/class-mcp-controller.php)) | JSON-RPC MCP server over ten verbs the dashboards already drive. |
| `POST /wp-json/newspack-nodes/v1/command` | substrate ([`Rest\HTTP_In_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-http-in-node.php)) | Routes a batch of packed command Messages to named CI nodes and writes the replies back. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate ([`Rest\SSE_Out_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-sse-out-node.php)) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. |
| `GET /wp-json/newspack-nodes/v1/log/stream` | substrate ([`Rest\Log_Stream_Out_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-log-stream-out-node.php)) | The same stream over a named log-registry source rather than a partition. |
| `POST /wp-json/newspack-nodes/v1/auth` | substrate ([`Rest\Auth_Controller`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-auth-controller.php)) | Mints the scoped command session an MCP bearer credential names. |
| `POST /wp-json/newspack-nodes/v1/workers/spawn` | substrate ([`Rest\Spawn_Controller`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-spawn-controller.php)) | HMAC-validated worker bootstrap. Not for public callers. |
| `POST /wp-json/newspack-nodes/v1/health/cache` | substrate ([`Rest\Health_Cache_Controller`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/rest/class-health-cache-controller.php)) | Token-gated cache probe the state doctor calls. |

This plugin contributes the verbs its three CIs expose; it registers no route under the
`newspack-nodes/v1` namespace. See [`../../newspack-nodes/docs/API.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md)
for the substrate's own wire shapes.

## Authentication and rate limiting

Every door runs [`Bootstrap::fleet_gate()`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-bootstrap.php) first: the fleet is network-global, so it runs on
the main site alone and a multisite subsite gets `403 Forbidden`.

`/command` and `/messages/stream` then gate on the substrate's lowest role,
[`Capabilities::READ`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-capabilities.php). That role resolves to `manage_options` on a stock install and to
`newspack_nodes_read` once `wp nodes caps install` swaps in the granular capabilities;
`newspack_nodes/capability_map` overrides either. The door demands the least any verb
behind it needs, and authority is decided per verb: [`Service_CI_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-service-ci-node.php) wraps each handler in
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
[`\Newspack_Nodes\SSE_Slot_Pool`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-sse-slot-pool.php) before opening headers, and memcache down means HTTP 429.
The slot pool IS the rate limit and cannot fall through silently.

## Sending a command

The `/command` body is **JSONL: one packed Message per line**, each a 7-element positional
JSON array in `Message` field order: `[TYPE, TIMESTAMP, FROM, TO, ID, KEY, VALUE]`.

```http
POST /wp-json/newspack-nodes/v1/command HTTP/1.1
Content-Type: text/plain; charset=UTF-8

[8,1756900000,"","performance","","",{"name":"urls","arguments":["--limit=25","--server=example.com"],"auth":{"nonce":"…","sig":"…"}}]
```

![The /command wire format and its status decision. A slot row shows the seven positional fields of one body line: TYPE 8 (TM_COMMAND), a TIMESTAMP, an empty FROM that the door prefixes with _output so the reply lands in the body, TO naming the performance, discovery or rules CI (most callers name the CI alone; a sub-path names a child), empty ID and KEY, and a VALUE of name, arguments and auth. Three cards explain the flat token grammar of arguments, the Command_Auth::sign() envelope (a nonce and a signature over ts, name, arguments and nonce, plus a handle for a session key; LOCAL commands need none), and why the body must be text/plain rather than application/json. Below, the gates in order, grouped by owner: HTTP_In_Node::check_permission() answers 403 from the fleet gate, 401 or 403 without Capabilities::READ, and 429 past 30 POSTs per user per second; dispatch() answers 500 when the request graph lacks _router or _output; and Service_CI_Node enforces each verb's own declared capability. Beside them the status, decided once: 200 when a reply opens the body, 401 if a command had already been refused, and when nothing is written back, 202 or 401. The reply is TM_COMMAND|TM_RESPONSE carrying name, arguments and payload, or TM_COMMAND|TM_ERROR carrying the throw's message.](img/api-command-envelope.png)

The verb tables below say which of each verb's arguments ride positionally. A refusal lands
a tick later on the node that asked, and the view surfaces it beside that caller (see
[architecture-guide.md → "Canonical view contract"](architecture-guide.md#canonical-view-contract)).

## Service CIs

Each subsection below lists the verbs the corresponding `includes/app/class-<name>-ci-node.php`
(`<Name>_CI_Node`) exposes. All three declare their verbs in a static
`node_schema()['commands']` array — name, capability, args and handler — and the inherited
`Service_CI_Node` constructor builds the dispatch table from that declaration, so none
defines a per-class constructor. **TO=`<ci-name>`, `name`=`<verb>`** addresses a verb.

The handlers live in this plugin's `Newspack_Event_Logger_Nodes\App\` namespace:
[`newspack-event-logger-nodes.php`](../newspack-event-logger-nodes.php) registers it with
[`Command_Interpreter_Node::register_namespace()`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-command-interpreter-node.php), and the CIs mount on the substrate's
`newspack_nodes/request_graph_ready` action.

### `discovery` — spoke-side hook and event roster

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `get` | READ | — | `{ registered_hooks: string[], custom_events: string[] }` — the union across every LOG rule of its `hooks` (either tier, resolved through `hooks_for`) and its `custom_events` (`Rule_Set::instrumented_union()`), with `custom_events` filtered out of `registered_hooks` so the picker's two catalogs stay disjoint. A `significant_events` name outside the `hooks` list is not in it, though `App\Core` binds one. |

Two callers ask it: the hub's [`Discovery_Collector_Node`](../includes/class-discovery-collector-node.php), which union-merges every spoke's
reply into the `discovered_hooks` / `discovered_events` staging options behind the rules
editor's hook picker, and the substrate `vault` CI's `test` verb, which probes it to check
one spoke's connection. It reports the ruleset and never writes it — the editor is the only
rules writer. A spoke's stored credentials (Basic application password, or Bearer) carry a
user holding the READ role, which is what satisfies the gate on the far end.

### `rules` — per-URL logging ruleset CRUD

Backs the "Logging Rules" editor on the settings page. All five verbs route through
[`Rule_Set`](../includes/class-rule-set.php), which owns the hook tiering, the orphan sweep and the pattern-hash identity:

![The rules CI and the two storage tiers of a rule's hook list. Five verb cards: dump (READ) returns every rule with pointer hooks resolved and hooks_in normalized to inline; save, upsert, delete and reset (all TUNE) replace the whole list, add or replace one rule keyed by pattern, drop one by id, and delete the stored option so the file config seeds again. Below, Rule_Set::save() tiers each log rule's hook list: up to INLINE_HOOK_LIMIT (100) hooks ride inline in the autoloaded rules option; past it the rule stores hooks null and hooks_in mc, with the list in a non-autoloaded newspack_event_logger_nodes_rule_hooks_<id> option mirrored into the substrate Table eln-rule-hooks for 3,600 seconds, and a read with both absent returns an empty list and a rate-limited notice. Side cards give the two size gates (MAX_JSON_BYTES 65,536 and MAX_JSON_DEPTH 12), why the crossover is 100 (the ruleset-bench measurement, never below 65), what crosses to a spoke (hooks hydrated before the push, re-tiered by apply_synced()), and the identity rule: a rule's id is Log_Manager::url_hash() of its pattern, re-derived on every write.](img/api-rules-tiers.png)

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `dump` | READ | — | `{ rules: [...] }` — every rule, with a pointer-tier rule's hooks resolved to the full list (`hooks_for`) and `hooks_in` normalized to `'inline'`. The storage tier is a `Rule_Set` decision the editor never makes. |
| `save` | TUNE | `rules` (required, raw JSON array as the first token) | `{ saved: int }` — whole-list replace. Each entry decodes through `Rule::from_array()`, ids are re-derived from patterns, and duplicate patterns collapse to one. One unrepresentable entry throws before anything is stored, so a replace is all or nothing. |
| `upsert` | TUNE | `rule` (required, raw JSON object as the first token) | `{ rule: {...} }` — single add/replace keyed by pattern. A same-pattern rule is replaced in place; an edit carrying the old id and a changed pattern rekeys and drops the old-pattern entry. This is the performance dashboard's "log this URL" path. |
| `delete` | TUNE | `id` (required, positional) | `{ deleted: bool }` — drop the matching rule and re-save. |
| `reset` | TUNE | — | `{ reset: int }` — DELETE the stored ruleset option so the file config seeds again, and report the seeded rule count. Storing `[]` instead would pin an explicit "log nothing" over the config seed; only an absent row reseeds. Sweeps every pointer rule's durable hooks option on the way out. |

`save` and `upsert` read their blob as the raw first token, not through `Command_Args`: a
JSON blob carries its own structure and there is nothing to classify.

#### The rule wire shape

[`Rule::to_array()`](../includes/class-rule.php) is what `dump` returns, what `save` and `upsert` accept, and what the
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
[`Stats_Store`](../includes/class-stats-store.php) per FLAME-BUILDER WORKER, over the indices
`Bootstrap::node_partitions( 'flame-builder' )` reports across every active topology that
declares the node — a store is keyed by the worker index that wrote it rather than by a
partition directory, so the index space is the declaring topology's worker count. With no
shared cache backend the list is empty, and every stats reader degrades to an empty or
zeroed shape. Disk-walking verbs work regardless. Every handler throws on bad input and the
interpreter wraps the throw as a TM_ERROR reply, so no handler returns an error shape.

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `overview` | READ | `--server`, `--breakdown` (comma-separated), `--categories` | `{ total_requests, global_avg_ms, global_avg_peak_mb, aggregate_time_series, global_leaderboard }`, plus `breakdowns` keyed by dimension when `breakdown` is given and `category_time_series` when `categories` is. The site totals come from the global `hourly` namespace, which has no server dimension; `server` scopes the leaderboard and the breakdowns only. A dimension outside `DIMENSIONS` throws `invalid breakdown dimension` rather than answering about the rest. `category_time_series` is `{ names, buckets }` — a name TABLE plus, per bucket, positional `[ nameIndex, t, c, n ]` rows. A category is one hook, callback or plugin, so its name would otherwise be spelled once per bucket, 288 times across a retention window: 838KB of an ~1.1MB reply. Nothing is capped or ranked — each bucket still holds every category the STORE kept for it — and `t` is rounded to four decimal places, a tenth of a nanosecond on a millisecond sum. |
| `urls` | READ | `--sort` (default `count`), `--order` (`desc`), `--limit` (50, clamped 1–1000), `--offset` (0, clamped 0–10000), `--search`, `--server`, `--errors_only`, `--include_workers` | `{ data, rows, totals, slowest, filters, limit, offset }` — the paginated, sortable URL leaderboard plus the totals and the slowest ten for whatever the filters left. An unknown `sort` falls back to `count`, an unknown `order` to `desc`. Worker traffic is excluded until `include_workers` opts it in. `errors_only` keeps the rows whose status buckets fail to account for every request — the timeouts and fatals that never reached a bucket at all — so a 5xx-heavy URL is excluded, a 5xx being a real response. `rows` is the pager's count, every row the filters left including the folded `Other` and `Other:worker` overflow rows, while `totals.urls` counts the distinct URLs among them; an overflow row is one row standing for many URLs, so the two are never expected to agree. `totals` is null for a server scope the stored rows carry no split for, because 0 would read as idle; `filters` echoes what was applied, so a narrower number never reads as the site's. |
| `dump_url` | READ | `hash` (required, positional, `[a-f0-9]{8,64}`), `--server`, `--breakdown`, `--categories`, `--since` | `{ stats, requests, scan_stopped_early, requests_window_start, aggregate_flame, aggregate_profiles, last_modified }`, plus `breakdown_time_series` and `category_time_series` when asked for — the latter in the same `{ names, buckets }` shape `overview` uses, so one encoder and one chart serve both. Throws `URL not found` for an unknown hash and `invalid hash format` for a malformed one. |
| `url_breakdown` | READ | `hash` (required, positional), `--breakdown` (required) | `{ breakdown_time_series }` and nothing else — memcache only, no index walk, for the chart that polls one dimension while the URL modal is open. Throws `invalid hash format` / `invalid breakdown dimension`. |
| `search_requests` | READ | `rid` (required, positional) | `{ rid, partition, url_hash }`, so the dashboard can deep-link without scanning every partition. Throws `Request not found` for an unknown rid, and `request index scan budget spent before rid <rid> was reached` when the walk ended first — an incomplete search is not a definite negative. |
| `grep_requests` | READ | `pattern` (required, positional), `--limit` (default 20, max 50) | `{ pattern, scope, scanned_partitions, results, truncated, result_count }` — literal, case-insensitive search across the recent firehose window, grouped by request. `scope` is always `recent`: every partition's walk starts at the second-to-last segment. `truncated` reports any of the three bounds — the result `limit`, the grouping engine's per-request byte and line caps, or `GREP_MAX_SCAN_LINES`. Each result carries `rid`, `url`, `method`, `ts`, `match_count` and `first_match_excerpt`. Shares its matching and grouping engine with `wp nodes reqgrep` (`Reqgrep_Core`), so both agree on what matched. Where the CLI hangs a history-miss callback on that engine, this verb wires none: a match on a late line whose earlier lines have already rotated out of the fixed 250-entry × 10-bucket history ring answers with `url` and `method` empty and `truncated` still false, so nothing in the reply says the request was reassembled from its tail alone. |
| `dump_request` | READ | `rid` (required, positional), `--partition` (default 0) | The full request body and merged flame data, plus computed `findings` and the measurement `caveat`. `partition` is a hint: searched first, then the rest, so any rid `search_requests` locates resolves here too. Throws `invalid partition` for an out-of-range partition, `Request not found` for an unknown rid, and the `budget spent` message above. |
| `ask` | READ | `descriptor` (required, positional; further context descriptors follow it, outermost last), `--server`, `--context` | The brief for one picker descriptor. |
| `list_hooks` | READ | — | `{ total_hooks, categories, category_descriptions, hooks_by_category }`. |
| `set` | TUNE | `option` and `value` (both required, positional) | `{ option, updated: bool }`. |

Notable bounds, all [`Performance_CI_Node`](../includes/app/class-performance-ci-node.php) constants: `MAX_INDEX_ENTRIES` 1,000,000,
`RECENT_REQUEST_LIMIT` 500, `GREP_MAX_SCAN_LINES` 200,000, `SLOWEST_ROWS` 10,
`RECENT_BUCKETS` 12, `INDEX_READ_CHUNK` 12. `DIMENSIONS` is `status, method, server,
country, from, ua, ja4`; `URL_SORTS` is `count, url, avg_ms, min_ms, max_ms, avg_peak_mb,
last_updated`.

Read three answers closely before trusting them: how far back `dump_url`'s `requests`
reach, which board an `ask` brief answers from, and where each `findings` record measured
its number.

![Three answers from the performance CI, and what to know before trusting each. First, a timeline of dump_url's request list: requests reaches back to requests_window_start and no further, a floor Stats_Store::window_start() computes from min_lifetime, floored at PREFIX_FLOOR (3,600 seconds), rounded up to a whole five-minute bucket and capped at 288 buckets, so the floor sits 3,600 seconds back at or below a min_lifetime of 3,600, tracks the setting between, and stops 86,100 seconds back above 85,800; a since watermark, compared against each request's completion and exclusive, makes a tailing caller's list shorter while requests_window_start still names the floor; scan_stopped_early is true when the walk spent MAX_INDEX_ENTRIES (1,000,000) first, and says nothing about the 500-row RECENT_REQUEST_LIMIT. Second, a table of the five ask descriptors (url, request with an optional partition hint, span, entry and category), what context each needs, which board it answers from, and its fetch pointer; a category brief answers from the request's own profile with scope request, or else from the recent-window leaderboard, and anything else throws unknown descriptor. Third, the fields of one findings record, listed worst first by severity: kind, title and detail, metric, rule_id, measured (where the number came from), and a proposal carrying action, direction, why, undo and whatever the action needs; every kind but fatal carries one, and a direction is as often more as less. Every brief carries the measurement caveat.](img/api-performance-ask.png)

**`set`** is the normalized positional single-option writer (`set <option> <value>`) over a
three-option whitelist: `newspack_event_logger_nodes_rules` (array),
`newspack_event_logger_nodes_log_memory` (bool) and
`newspack_event_logger_nodes_flush_every_line` (bool). An option absent from it is refused
as `unknown option`, so the whitelist and [`hub-control.tsl`](../topologies/hub-control.tsl)'s `add_setting` lines must stay
in step. Array-typed options carry their value as JSON, and the decoded array is sanitized
before it is stored: string keys and string values pass through `sanitize_text_field`, an
object is dropped, and the whole option is refused as `invalid value for option` — a
different refusal from `unknown option` — when it nests more than `SETTINGS_ARRAY_DEPTH` (5)
levels below the top or any one level holds more than `SETTINGS_ARRAY_MAX` (10,000)
elements. Nothing is truncated to fit. A set to the value already in place answers
`updated: false` without saving, because the hub re-pushes every synced option on its
sweep whether or not it moved and a reload fires `Config::RESET_ACTION` on every worker,
which re-parses every `.tsl` for the same answer. The ruleset routes to
`Rule_Set::apply_synced()` instead, which re-tiers and holds its own gate, and that is where
the decode's one footgun lands: a `rules` value that fails to parse as JSON is taken as an
empty array rather than refused, and `apply_synced()` SAVES it — so a malformed hub push
clears the spoke's rules and pins the explicit "log nothing" that `reset` exists to avoid
storing. A rate-limited `PerformanceCI: rejected non-JSON synced array-option value` notice
is the only sign. Autoload follows `Config::autoload_for()`, and the write emits a settings
event that [`Settings_Sync_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-settings-sync-node.php) fans out to spokes.

## Substrate verbs the dashboards use

The dashboards call into substrate-owned CIs over the same `/command` endpoint. Ten mount
on `newspack_nodes/request_graph_ready`: `classes`, `layouts`, `topologies`, `raw-logs`,
`vault`, `aggregator`, `settings`, `status`, `sessions` and `workers`. Five matter to this
plugin's operators.

| TO | Verbs | What it answers |
|----|-------|-----------------|
| `workers` | `list`, `dump_graph`, `dump_cleanup`, `restart`, `heartbeat` | The fleet, and the SSE slot keep-alive every dashboard pokes. |
| `status` | `get` | A literal `status: ok`, the `runtime_version`, `num_partitions`, the active `topologies`, `cache_available` and a `timestamp`. It carries no application version field. |
| `settings` | `get`, `set` | `get` answers a snapshot of the seven substrate-owned storage settings: `num_partitions`, `segment_size`, `min_segments`, `num_segments`, `min_lifetime`, `lifetime` and `max_segments`. `set` reaches further — every `int` Field declaring a minimum, which adds the six `remote_*` spoke-geometry keys, the three `alert_*` thresholds and the four bounded `sse_*` limits — and answers with that same seven-key snapshot whatever it wrote. The wider reach is how `Settings_Sync_Node` pushes a hub's `remote_*` geometry out to its spokes. |
| `vault` | `list`, `get`, `add`, `update`, `delete`, `test` | Remote-spoke credentials. This is where a spoke's URL and Authorization header live. |
| `aggregator` | `summary`, `list_servers`, `probe` | Per-spoke [`Remote_Source_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-remote-source-node.php) status on the hub. |

The React graphs address `_http/<ci-name>`, so the browser runtime's `HttpOut` node POSTs
the command and routes the reply back by the TO the server echoed off the sender's FROM.
`_http/workers` is the heartbeat target: `mountExospine` wires the shared `_heartbeat` node
to it, and that is fixed wiring rather than a per-dashboard choice.

See [`../../newspack-nodes/docs/API.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md) for full schemas.

## SSE: `/messages/stream`

A client subscribes to one or more `<log>.p<N>` partitions, and every frame the server sends
is a 7-field message envelope.

```
GET /wp-json/newspack-nodes/v1/messages/stream?subscribe=<log>.p<N>[,<log>.p<N>...][&positions=...][&multi_writer=1]
```

![One subscription over a minute of an idle log, on four lanes. The server lane opens each stream with a retry event naming the reopen delay and a connected envelope carrying the session pid, the heartbeat cadence and each subscription's starting cursor, then sends one msg per data line and a heartbeat every 2 seconds while none flows; after sse_idle_timeout (15 seconds, 0 never closes) with no data it closes cleanly with no terminal frame, and the client reopens after sse_retry_ms (5,000 ms) from its positions, so an idle stream cycles close and reopen. The client lane POSTs workers.heartbeat every 15 seconds, as a hub's Remote_Source_Node does; two slot-lease lanes follow. On the idle stream each open acquires its own lease before any header and the drain's finally releases it at each idle close, so a poke refreshes only the lease of a stream still open, and a poke for a released lease is refused. On a stream that carries data the one lease stays held, and each poke resets its expiry to sse_slot_ttl from the poke: one value for every caller, 60 seconds by default, floored at 45 (three poke intervals) by SSE_Slot_Pool::ttl(). A TTL must outlive the client's poke interval, and at three intervals one lost poke still leaves a refresh before expiry. Cards list the five event types, what ends a stream (idle, a lost lease sending disconnect, and nothing else inside PHP, since the stream runs set_time_limit(0)), and why opening fails closed: the slot is acquired before any header, a full pool or a cache that will not take the lease answers HTTP 429, only the client refreshes a TTL, and the server flushes before it sleeps, never per event.](img/api-sse-lifecycle.png)

`subscribe` is required; `positions` carries the resume cursors; `multi_writer` is the
client's assertion that more than one process appends the subscribed logs, which buys the
reader a grace window and costs nothing but that when wrong. Per-line transforms live in the
browser, inside each dashboard's view node (`RequestLogViewNode`, `GyroscopeViewNode`,
`PerfErrorsViewNode`); the browser consumes the stream through the `<link>:sse-in` node
(`SseInNode`) each `RemoteLink` owns.

## Worker spawn

```
POST /wp-json/newspack-nodes/v1/workers/spawn
```

The substrate's HMAC-validated worker bootstrap, listed for orientation. Not for public
callers. Nothing in this plugin registers or constrains it; treat
[`../../newspack-nodes/docs/API.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md) as authoritative for
its request shape and HMAC authentication.

## MCP

```
POST /wp-json/newspack-event-logger-nodes/v1/mcp
```

An [MCP](https://modelcontextprotocol.io/) server over verbs this plugin already answers. It
speaks protocol revision `2025-06-18` as [JSON-RPC](https://www.jsonrpc.org/specification),
and one POST carries every method: `initialize`, `notifications/initialized`
(answered with nothing — JSON-RPC forbids replying to a notification), `tools/list` and
`tools/call`. It adds no runtime surface: `tools/call` mounts the same request graph
`/command` does, through `Bootstrap::mount_request_graph()`, and dispatches through the same
interpreter.

![The MCP round trip across four lanes: the agent, the permission gate MCP_Controller::check_permission(), the JSON-RPC dispatcher and the verb's interpreter. One JSON-RPC request per POST carries a Bearer handle.key credential, 32 hex, a dot and 64 hex. The gate answers 403 from the fleet gate on a multisite subsite, 401 for a malformed header, and 401 when Command_Auth::load_session_record() finds no live session or the key fails hash_equals; it then makes the request the session's minting user with the scope installed as a ceiling, and last answers 429 on a handle's 21st call inside one 10-second bucket. Dispatch answers initialize with protocol 2025-06-18, capabilities, serverInfo and the measurement caveat as instructions, answers nothing to notifications/initialized, lists only the tools the scope and the user both allow, and returns -32601 for any other method and -32600 for a body that is not JSON-RPC. tools/call refuses an unknown tool and an uncovered one alike, -32601 Unknown tool; turns named arguments into a flat token list, where POSITIONAL_ARGS (descriptor, hash, rid, pattern, rule, id, context) ride bare in that order and the rest become --key=value; and dispatches on the same CI /command reaches. A return becomes one text block, JSON-encoded unless already a string; a throw becomes result.isError, a tool error rather than a transport error. Below: a read session sees eight tools, a tune session two more, and six verbs have no tool at all: performance.url_breakdown, list_hooks and set, rules.save and reset, and discovery.get.](img/api-mcp-round-trip.png)

**Permission**: an `Authorization: Bearer <handle>.<key>` header naming a live command
session, issued from the station's Sessions tab or from `POST /wp-json/newspack-nodes/v1/auth`.
Authority is the minting user's, and the session's scope only ever subtracts from it: a
manage-scoped session minted by someone who can do nothing still does nothing.

Ten tools, one per verb:

| Tool | Node.verb | Role |
|------|-----------|------|
| `performance_overview` | `performance.overview` | READ |
| `performance_urls` | `performance.urls` | READ |
| `dump_url` | `performance.dump_url` | READ |
| `search_requests` | `performance.search_requests` | READ |
| `dump_request` | `performance.dump_request` | READ |
| `grep_requests` | `performance.grep_requests` | READ |
| `performance_ask` | `performance.ask` | READ |
| `dump_rules` | `rules.dump` | READ |
| `rules_upsert` | `rules.upsert` | TUNE |
| `rules_delete` | `rules.delete` | TUNE |

**Connecting a client**. Register the endpoint with the handle and key that session issued.
`<ID>` is the local name the client files it under:

```
claude mcp add --transport http <ID> https://<DOMAIN>/wp-json/newspack-event-logger-nodes/v1/mcp --header "Authorization: Bearer <HANDLE>.<KEY>"
```

Every tool description carries the measurement caveat, because a model handed
`175.6ms profiled / 420000ms duration` with nothing saying what is unmeasured will invent a
cause for the difference — and the invented cause reads exactly like a finding. The same
caveat is `initialize`'s `instructions`.

Nothing here assumes an agent will act on instructions found in a page. Wiring a client up
is a deliberate act by the operator; the endpoint advertises itself in prose aimed at a
human, and refuses everything without a credential.

## WP-CLI

Both verbs register under the substrate's `nodes` namespace, in the deferred bootstrap, and
both run `@when after_wp_load`.

![The two WP-CLI verbs. wp nodes reqgrep in three steps. First it picks the source: stdin, detected by fstat, wins and ignores --follow and --recent; --follow runs one Consumer per partition from the tail under the event loop; otherwise one Consumer per partition reads from the first segment, or from the second-to-last with --recent; and --firehose must resolve inside Config::get_logs_directory(). Second, Reqgrep_Core matches each line when its rid equals the pattern or the preg_quoted pattern appears case-insensitively, no pattern meaning a dot; unmatched lines wait in a history ring of --num-buckets (10, clamped 1 to 100) buckets of --bucket-size (250, clamped 1 to 10,000), and a matched rid enters an in-flight LRU of 100 items by 3 buckets rotating every 60 seconds, capped at 20,000 lines and 10 MiB a request. Third, it prints each request as it reaches process (complete) or process (aborted), and anything evicted or still open prints as [incomplete]. wp nodes ruleset-bench times three paths, autoload, inline and pointer, over a grid of 50 to 5,000 hooks per rule by 1, 10 and 50 rules, --iterations times (default 200, minimum 1), and prints the rule for picking INLINE_HOOK_LIMIT: the largest K whose inline cost stays below the pointer floor while the autoload tax stays negligible, never below 65. With no cache backend the pointer column is the bind loop alone.](img/api-cli-flows.png)

### `wp nodes reqgrep [<pattern>]`

Filter the firehose by request id, URL or any text, and print each matching request as an
indented lifecycle tree: every entry sharing a request id, once any line for that rid matches.

| Flag | Meaning |
|------|---------|
| `<pattern>` | Search pattern — a rid, a URL, or any text. `Reqgrep_Core::compile()` `preg_quote`s it, so it matches as a LITERAL, case-insensitively, and regex metacharacters carry no meaning. Omitting it takes the default `.`, which every packed line's float timestamp contains. |
| `--follow` | Tail mode: keep reading and printing new requests as they finish. |
| `--recent` | Scan only the second-to-last segment and newer, for a fast lookup. |
| `--raw` | Emit raw JSONL instead of the formatted tree. |
| `--incomplete` | Show only requests that reached neither `process (complete)` nor `process (aborted)`. |
| `--bucket-size=<size>` | History bucket capacity, counting request ids plus lines. Default 250, clamped 1–10000. |
| `--num-buckets=<count>` | History buckets retained. Default 10, clamped 1–100. |
| `--firehose=<path>` | Override the firehose base directory, validated before any dir is opened: it must resolve inside `Config::get_logs_directory()`. A path already naming a partition (`.p<N>`) reads that partition alone, under the index it names. |

[`Reqgrep_Core`](../includes/class-reqgrep-core.php) does the grouping, so this command and `performance.grep_requests` agree byte
for byte on what belongs to which request.

### `wp nodes ruleset-bench [--iterations=<n>]`

Measurement only, off the request hot path: it times the ruleset's two hook-storage tiers,
prints the table [`Rule_Set::INLINE_HOOK_LIMIT`](../includes/class-rule-set.php) (100) is calibrated from, and closes
with the rule for reading it. A higher `--iterations` buys a steadier median at the cost of
runtime.

## PHP API

[`Log_Manager`](../includes/class-log-manager.php) is the class other plugins log through. Pyrobase and Nuclear Gyrobase both
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
| `static redact_url( string ): string` | The ONE redaction path; public for that reason. Replaces the value of each of 21 sensitive query parameters with `[REDACTED]`, keeping the parameter itself. |

Public constants: `FATAL_TYPES`, and the four request keywords `REQUEST_LABEL` (`process`),
`REQUEST_START`, `REQUEST_COMPLETE` and `REQUEST_ABORTED`. The last two together are
`Request_Builder_Node::TERMINAL_KEYWORDS`, the pair that closes a record; without one it
strands in flight until eviction.

**Payload size.** `message()` holds each encoded entry to 3,840 bytes, the headroom under
PIPE_BUF (4,096) that keeps a lock-free append atomic against every other writer on this
multi-writer log. The constant is private, so a producer sizing its own payloads keeps its
own copy, as Pyrobase's `Runtime\Log` does.

![One firehose line. A slot row shows the 7-field message: TYPE TM_STRUCT (16), TIMESTAMP the cached clock, empty FROM, TO and ID, KEY the request id, so one request lands in one partition, and VALUE the entry. The entry is ['n', 'k'] + $data + ['ts'] in that precedence: n, the line number the builder gap-checks; k, the category that opens, closes or brackets a span and is never renamed; the caller's keys, m (URL-redacted first when it is a string carrying a question mark), l, duration_ms, caller and rule, where a caller's own ts wins over the stamp; ts; and truncated, present only when the fit ran. A ladder shows fit_data(): an encoding at or under 3,840 bytes is untouched; over it, a string m is cut ten percent a step from 3,840 bytes, re-encoding each step, with every other key surviving; when no step fits or m is not a string, m is dropped; and when the rest still exceeds the cap, the floor keeps n, k, ts and truncated, with the first 1,000 bytes of the oversized encoding plus a literal ... as m. Cards say why the category is never renamed (Flame_Tree::PATTERN_START and Request_Builder_Node's ' (start)' test would never open the span), that nothing is chunked or sent to error_log, and that anything larger belongs in \Newspack_Nodes\Job_Intake::queue(), which takes the auto-lock and accepts up to MAX_JOB_SIZE, 32 MiB.](img/api-firehose-entry.png)

**The second producer.** The Perl engine's `Gyrobase::Log` appends to the same firehose, and
its `@ENV_ALLOWLIST` (34 keys), `ENV_VALUE_MAX` (256 bytes), U+2026 elision marker and URL
redaction pattern are hand-maintained copies of `Log_Manager`'s. Only dndocker holds both
repositories, so its `tools/check-firehose-parity.py` is what keeps the two identical, and
this plugin's `pre-push` runs that check whenever dndocker is the checkout in hand.

**The profiler drop-in.** [`mu-plugins/00-newspack-profiler.php`](../mu-plugins/00-newspack-profiler.php) publishes a `$newspack_profiler`
global carrying `request_time` (monotonic nanoseconds), `request_ts` (the matching wall
clock) and one `plugins` row per timed plugin. `Log_Manager`'s constructor adopts and unsets
the first two, which is what stamps `process (start)` with the moment PHP began the request
rather than the moment the logger emitted its first line, and stops a nested job context from
claiming them again. The drop-in depends on nothing; with the plugin inactive it goes unread.

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
| `newspack_nodes/stderr` | [`Diagnostics_Bridge::on_stderr`](../includes/class-diagnostics-bridge.php) — carries a substrate diagnostic into the active request or job log as a `stderr` entry, feeding the Error Log. |
| `newspack_nodes/request_graph_ready` | `newspack_event_logger_nodes_mount_service_cis` — mounts `discovery`, `performance` and `rules`. |
| `newspack_nodes/station_tab_bundles` | [`Current_Request_Overlay::register_bundle`](../includes/class-current-request-overlay.php) — adds the `current-request` bundle descriptor so the station enqueues that tab. `Current_Request_Overlay` registers two `admin_enqueue_scripts` callbacks beside it: `enqueue_on_overlay_pages` at the default priority, for the ELN pages that embed the overlay themselves, and `enqueue_inline_data` at 20, which injects this request's id into the JS global the tab reads once both enqueue paths have run. |

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
[`Job_Worker_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.55.3/includes/class-job-worker-node.php).

### Consumed from WordPress

[`plugins_loaded`](https://developer.wordpress.org/reference/hooks/plugins_loaded/) (priority 11, the deferred bootstrap), [`rest_api_init`](https://developer.wordpress.org/reference/hooks/rest_api_init/), `admin_menu`,
`admin_enqueue_scripts`, `admin_init`, `admin_post_newspack_event_logger_nodes_reset_settings`,
`updated_option`, `added_option` and `pre_update_option` (a filter, 3 args) in the admin.

The profiler drop-in binds three more at file scope, outside this plugin's bootstrap, and
[`App\Core`](../includes/app/class-core.php) binds the rest, per governing rule:

![What fires and what listens along one logged request, as a priority timeline. The profiler drop-in's option_active_plugins filter at priority 1 takes the load baseline, a wall-clock and hrtime pair plus class and file counts, just ahead of the plugin loop; a listener short-circuiting pre_option_active_plugins stops it. plugin_loaded at priority 1 records one row per site-activated plugin; must-use and network-activated plugins announce on hooks the drop-in does not bind. plugins_loaded at -10001 builds Log_Manager, which resolves the rule for REQUEST_URI, adopts the profiler's request_time and request_ts, registers finish() and writes process (start), and the plugin rows follow as start and complete pairs. plugins_loaded at 11 constructs App\Core, which gives each of the rule's hooks a hook_start at hook_start_priority (-10000), a sacrificial hook_spacer at PHP_INT_MAX - 2 that WP_Hook's pointer skips instead of the close, and a hook_complete at PHP_INT_MAX - 1; binds significant_events names once a trailing ' hook' is stripped; never binds a custom_events category, plugin_loaded, query under log_queries, or an internal newspack_nodes or newspack_event_logger_nodes hook; and adds the log_http pair (pre_http_request at PHP_INT_MAX, opening nothing for a short-circuit, and http_api_debug at PHP_INT_MIN) and the log_queries pair (query at hook_start_priority, so the span covers the filter chain, and log_query_custom_data at PHP_INT_MIN, closing on the rewritten statement and draining $wpdb->queries; SAVEQUERIES is defined if absent). While the request runs, hook_start wraps a significant hook's callbacks registered strictly between hook_start_priority and the spacer, skipping any with a by-reference parameter, each wrapper claiming accepted_args 99 and slicing back. In a worker, the before_job and after_job hooks open and restore a synthetic /jobs/{handler}/{id} context and fire newspack_event_logger_nodes_scope_changed. At shutdown finish() drains open frames as (orphaned) and writes process (complete), with error_status F after a fatal, or process (aborted) with error_status A after a cooperative stop.](img/api-request-hooks.png)

The profiler's baseline is the first [`option_active_plugins`](https://developer.wordpress.org/reference/hooks/option_option/) firing, so anything reading
`active_plugins` earlier in bootstrap moves the baseline earlier with it. Each [`plugin_loaded`](https://developer.wordpress.org/reference/hooks/plugin_loaded/)
interval also covers the loop's own per-plugin work, because WordPress offers no signal
bracketing the include alone.
