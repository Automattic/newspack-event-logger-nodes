# Newspack Event Logger Nodes API

The plugin registers exactly **one** REST route of its own — the MCP server. Every other
call a client makes over HTTP is a verb on a service Command_Interpreter (CI) node,
addressed by name through the substrate's command endpoint. Beside that wire surface the
plugin exposes two WP-CLI verbs, the [`Log_Manager`](../includes/class-log-manager.php) PHP API sibling plugins log through,
and the WordPress hooks it fires and consumes.

| Endpoint | Owner | Purpose |
|----------|-------|---------|
| `POST /wp-json/newspack-event-logger-nodes/v1/mcp` | this plugin ([`App\MCP_Controller`](../includes/app/class-mcp-controller.php)) | JSON-RPC MCP server over ten verbs the dashboards already drive. |
| `POST /wp-json/newspack-nodes/v1/command` | substrate ([`Rest\HTTP_In_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/rest/class-http-in-node.php)) | Routes a batch of packed command Messages to named CI nodes and writes the replies back. |
| `GET /wp-json/newspack-nodes/v1/messages/stream` | substrate ([`Rest\SSE_Out_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/rest/class-sse-out-node.php)) | Subscribes to one or more `<log>.pN` partitions and emits 7-field message envelopes as SSE events. A log-registry source subscribes as `sources:<name>`. |
| `POST /wp-json/newspack-nodes/v1/auth` | substrate ([`Rest\Auth_Controller`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/rest/class-auth-controller.php)) | Mints the scoped command session an MCP bearer credential names. |
| `POST /wp-json/newspack-nodes/v1/workers/spawn` | substrate ([`Rest\Spawn_Controller`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/rest/class-spawn-controller.php)) | HMAC-validated worker bootstrap. Not for public callers. |
| `POST /wp-json/newspack-nodes/v1/health/runtime` | substrate ([`Rest\Health_Runtime_Controller`](https://github.com/Automattic/newspack-nodes/blob/main/includes/rest/class-health-runtime-controller.php)) | Token-gated runtime probe `wp nodes doctor` calls: the web runtime's cache backend and log sources. |

This plugin contributes the verbs its three CIs expose; it registers no route under the
`newspack-nodes/v1` namespace. See [`../../newspack-nodes/docs/API.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md)
for the substrate's own wire shapes.

## Authentication and rate limiting

Every door runs [`Bootstrap::fleet_gate()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-bootstrap.php) first: the fleet is network-global, so it runs on
the main site alone and a multisite subsite gets `403 Forbidden`.

`/command` and `/messages/stream` then gate on the substrate's lowest role,
[`Capabilities::READ`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-capabilities.php). That role resolves to `manage_options` on a stock install and to
`newspack_nodes_read` once `wp nodes caps install` swaps in the granular capabilities;
`newspack_nodes/capability_map` overrides either. The door demands the least any verb
behind it needs, and authority is decided per verb:
[`Command_Interpreter_Node::dispatch()`](https://github.com/Automattic/newspack-nodes/blob/v2.79.1/includes/class-command-interpreter-node.php)
refuses a caller below the role its schema declares (ADR-26), and a verb declaring none takes
MANAGE. **No handler in this plugin re-checks a capability** — one that did would outrank
its own declaration without saying so.

Both rate limits below are the substrate's `\Newspack_Nodes\Rate_Limit`: each
admitted call claims one of the budget's slots through the shared cache's atomic `add()` —
memcached, else APCu — under a TTL of one window, so concurrent requests cannot both take
the last slot. The cache counts that TTL in whole seconds: memcached frees a slot one
window less up to a second after its claim, APCu one window to a window and a second.
Over budget a door answers `429 rate_limited`. Each door checks its credential first, so
an unauthenticated flood spends no slots.

`/command` carries a per-user limit: `HTTP_In_Node::RATE_LIMIT_BURST = 30` POSTs per
`RATE_LIMIT_WINDOW_S = 1` second, tunable through the `newspack_nodes/command_rate_limit`
filter, clamped to 1 through `Rate_Limit::MAX_BURST` (256). At one second on memcached the
budget is close to one bucket per cache tick; the limiter's gain there is the atomic claim.
With neither cache, or a slot read that fails, `/command` admits and logs one rate-limited
warning, because every dashboard and hub push rides it.

The MCP route meters itself: `MCP_Controller::RATE_LIMIT_BURST = 20` calls per
`RATE_LIMIT_WINDOW_S = 10` seconds, keyed by session handle. MCP does not go through
`/command`, so the substrate's per-user cap does not bound it, and with no cache to meter
in it answers `503 rate_limit_unavailable` rather than run unmetered.

SSE rate-limiting is independent and **fail-closed**: `SSE_Out_Node` consults
[`\Newspack_Nodes\SSE_Slot_Pool`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-sse-slot-pool.php) before opening headers, and memcache down means HTTP 429.
The slot pool IS the rate limit and cannot fall through silently.

## Sending a command

The `/command` body is **JSONL: one packed Message per line**, each a 7-element positional
JSON array in `Message` field order: `[TYPE, TIMESTAMP, FROM, TO, ID, KEY, VALUE]`.

```http
POST /wp-json/newspack-nodes/v1/command HTTP/1.1
Content-Type: text/plain; charset=UTF-8

[8,1756900000,"","performance","","",{"name":"urls","arguments":["--limit=25","--server=example.com"],"auth":{"nonce":"…","sig":"…"}}]
```

![The /command wire format and its status decision. A slot row shows the seven positional fields of one body line: TYPE 8 (TM_COMMAND), a TIMESTAMP, an empty FROM that the door prefixes with _output so the reply lands in the body, TO naming the performance, discovery or rules CI (most callers name the CI alone; a sub-path names a child), empty ID and KEY, and a VALUE of name, arguments and auth. Three cards explain the flat token grammar of arguments, the Command_Auth::sign() envelope (a nonce and a signature over ts, name, arguments and nonce, plus a handle for a session key; LOCAL commands need none), and why the body must be text/plain rather than application/json. Below, the gates in order, grouped by owner: HTTP_In_Node::check_permission() answers 403 from the fleet gate, 401 or 403 without Capabilities::READ, and 429 past 30 POSTs per user per second; dispatch() answers 500 when the request graph lacks _router or _output; and Command_Interpreter_Node::dispatch() refuses a caller below each verb's declared role. Beside them the status, decided once: 200 when a reply opens the body, 401 if a command had already been refused, and when nothing is written back, 202 or 401. The reply is TM_COMMAND|TM_RESPONSE carrying name, arguments and payload, or TM_COMMAND|TM_ERROR carrying the throw's message.](img/api-command-envelope.png)

The substrate binds each verb's tokens against the args it declares before its handler runs:
an arg rides by position, in the order the tables below list them, or as `--name=value`, and
the two mix. A `bool` is a bare `--name` or one of `1/true/yes/on/0/false/no/off`; an absent
arg takes its default. The binder refuses a missing required arg, a blank for one, an option
the verb does not declare and a surplus token, and a refused command never reaches the
handler or the verb span. A refusal lands
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
[`Command_Interpreter_Node::register_namespace()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-command-interpreter-node.php), and the CIs mount on the substrate's
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
| `save` | TUNE | `rules` (required, one JSON array token) | `{ saved: int }` — whole-list replace. Each entry decodes through `Rule::from_array()`, ids are re-derived from patterns, and duplicate patterns collapse to one. One unrepresentable entry throws before anything is stored, so a replace is all or nothing. |
| `upsert` | TUNE | `rule` (required, one JSON object token) | `{ rule: {...} }` — single add/replace keyed by pattern. A same-pattern rule is replaced in place; an edit carrying the old id and a changed pattern rekeys and drops the old-pattern entry. This is the performance dashboard's "log this URL" path. |
| `delete` | TUNE | `id` (required) | `{ deleted: bool }` — drop the matching rule and re-save. |
| `reset` | TUNE | — | `{ reset: int }` — DELETE the stored ruleset option so the file config seeds again, and report the seeded rule count. Storing `[]` instead would pin an explicit "log nothing" over the config seed; only an absent row reseeds. Sweeps every pointer rule's durable hooks option on the way out. |

Every write verb ends by asking the live workers to re-read. When the locks directory does not resolve, the verb answers with that error, the rule list already stored — `save`, `upsert` and `delete` write it, `reset` deletes it.

`save` and `upsert` take their blob as one token, which the handler decodes: a JSON blob
carries its own structure.

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
| `significant_events` | string[] | Hooks that get per-callback profiling and are exempt from auto-disable. `sql` and `http` name the `query` and `pre_http_request` filters their spans cover, and take effect under `log_queries` and `log_http`. |
| `custom_events` | string[] | Categories the application logs itself; never bound as `do_action` hooks. |
| `hooks` | string[] \| null | The inline list, or null for the pointer tier. |
| `hooks_in` | string | `inline` or `mc`. Must agree with `hooks`, or the constructor throws. |
| `log_queries` | bool | Time every SQL query as its own span. Needs `SAVEQUERIES` and costs two entries per query. Default false. |
| `log_http` | bool | Time every outbound HTTP request as a span, between `pre_http_request` and `http_api_debug`. Off unless set, as every diagnostic is. |
| `log_plugin_loads` | bool | Write the profiler mu-plugin's per-plugin load timings as spans. Off unless set. The mu-plugin measures either way; this decides whether the record carries two entries per site-activated plugin. |
| `trace_hooks` | bool | Name the calling frame on each hook entry's aggregation label, so one hook firing sixteen times splits into a flame node per caller. Default false. |
| `trace_callers` | int | Deep caller chains recorded per request on a span's start entry as `caller`, budgeted per CALLER of each hook, query statement shape and outbound URL; 0 is off, and a stored `true` is a count of 1. |

### `performance` — the omnibus dashboard CI

The largest CI; every Performance-tree dashboard verb lives here. Its stats verbs read the
`flame-facts` rows through [`App\Stats_Query`](../includes/app/class-stats-query.php), which
attaches every partition's file read-only and answers in one statement
([decision 36](architecture-decisions.md#decision-36-the-query-vocabulary-is-fixed-no-sql-from-outside-a-picker-chooses-the-rollup)); a partition whose builder has written no file yet holds no
rows. When the files will not open — `pdo_sqlite` missing, a file SQLite cannot attach, no
active topology declaring `flame-facts` — the substrate raises `Table_Unavailable`, the
verb logs `performance: stats rows did not open` once a window, and every stats reader
answers an empty or zeroed shape, `query` with `available` false
([decision 3](architecture-decisions.md#decision-3-stats-reads-fail-soft-a-stats-write-raises)); any other failure fails the verb, a reader running as root
among them. Disk-walking verbs work regardless. Every handler throws on bad input and the
interpreter wraps the throw as a TM_ERROR reply, so no handler returns an error shape.

A `--bucket` selection is `Bucket::selection()`'s spelling: comma-separated runs of
`Y-m-d-H-i` UTC keys, each one bucket or `start..end` inclusive, none later than the
current one. Empty means the last 288, the chart's 24 hours. `urls`, `dump_url` and `ask`
take at most 288 buckets; `query` takes as many as its source keeps, 2,016 for `requests`
(seven days) and 300 for `timings` (25 hours), `Stats_Query::retention()`. Requests are
kept seven days, so an older bucket holds no rows rather than raising.

`overview`, `urls` and `query` answer from the URL snapshot the overview-prefetch job
publishes every two minutes
([decision 37](architecture-decisions.md#decision-37-the-overview-reads-a-jobs-snapshot-for-two-intervals)),
which serves for two intervals. `urls` with no `--search`, no `--bucket` and no
`--filter` answers off it for any sort, order, `--server`, `--include_workers`,
`--limit` and `--offset`. `overview` with no `--server`, the request chart by `server`
and by `status_class` — the dashboard's default breakdown, and what it falls back to over
one server — and the category chart, each over the site's non-worker traffic, are stored
in it as `includes/app/stored-answers.json` spells their tokens, and a verb asked the same
bound arguments serves the stored answer; the job stores only an answer the stats could
open for. Every other question, a snapshot past its two intervals, and a file that is not
there yet or will not open are answered live. Each of the three verbs answers `as_of`
beside the fields below: the epoch second its answer was computed, or the snapshot built.

| Verb | Role | Args | Returns |
|------|------|------|---------|
| `overview` | READ | `--server` | `{ as_of, totals, slots, servers, leaderboard, available }` over the chart's 288 buckets. `totals` counts the non-worker requests there: `requests`, `timed`, `avg_ms` (the timed mean, null with no timed request), `avg_peak_mb`, `errors` and `urls`, the exact count of distinct URLs. `slots` names the 288 bucket keys, newest first. `servers` names every server with traffic there, worker traffic counted, so a server serving only cron or job requests is offered too, busiest first, and ignores `--server`, which scopes the totals and the leaderboard. Stats that will not open answer `available` false and `totals` null, never zeroes. `leaderboard` is the chart's 24 hours of `category_window`, or of `timings_site` where a partition's window has not caught up or `--server` scopes it: `count` is the profiled non-worker requests, `total_time` their mean profiled time with a listener's time counted once inside its hook, `avg_ms` the timed mean of every non-worker request over those hours, and `categories` each category's per-request `time` and `count`, its `samples` and its `entries`, per-appearance means, a category past 100 entries keeping its 50 slowest. Chart series come from `query`. |
| `urls` | READ | `--sort` (default `count`), `--order` (`desc`), `--limit` (50, clamped 1–1000), `--offset` (0, clamped 0–10000), `--search`, `--server`, `--filter=field=value` (repeatable), `--include_workers`, `--bucket` | `{ as_of, data, rows, totals, slowest, filters, limit, offset, available }` — the paginated, sortable URL leaderboard plus the totals and the slowest ten for whatever the filters left, `totals` null and `available` false when the stats will not open. Every count is exact and every mean request-weighted: a row's `avg_ms` is the mean over its timed requests and its `avg_peak_mb` over every request, never a mean of bucket means. `rows` and `totals.urls` count the distinct URLs matching, which the pager reads. A row carries `hash`, `url`, `count`, `timed_count`, `count_2xx` … `count_5xx`, `errors`, `sum_ms`, `min_ms`, `max_ms`, `sum_peak_mb`, `max_peak_mb`, `worker`, `last_updated` (its newest completion), `avg_ms` and `avg_peak_mb`; `avg_ms`, `min_ms` and `max_ms` are null where no timed request reached the row, which ranks last on those three sorts in either order. `totals.requests_per_second` divides the requests by the seconds the selected buckets have run, the current one only so far. A search matches whole words of the URL path through FTS5: quotes, a minus and other punctuation only separate words, and a term with no word is refused. An unknown `sort` falls back to `count`, an unknown `order` to `desc`. Worker traffic is excluded until `include_workers` opts it in. `--filter` narrows the rows, the totals and the slowest ten together, each token one value spelled and refused as `query` takes it, several naming one field matching any of them, and a filter on `worker` replaces that scope; a filter cannot name `server`, which `--server` owns, or `bucket`, which `--bucket` owns, and a token naming either is refused, naming its owner; `url=` narrows to one URL by its hash: `status_class=5` keeps the 5xx responses, most fatals among them, and `status_class=Unknown` the requests that answered no status: timeouts, and CLI or cron runs where workers count. `--filter=error=T --filter=error=F` keeps the timeouts and fatals exactly, as `errors_only` did. `totals.errors` counts the timeouts and fatals among the requests counted. `filters` echoes `server`, `search`, `filter` (the tokens), `include_workers` and `bucket`, the selection's canonical spelling or `''`. A question setting no argument but `sort`, `order`, `limit`, `offset`, `server` and `include_workers` reads the job's snapshot, its ties broken by hash in the sort's direction as a live page breaks them; any other argument off its default, one added later included, answers live without opening the snapshot. |
| `query` | READ | `source` (required, `requests` or `timings`), `--group` (repeatable), `--filter=field=value` (repeatable), `--measure` (repeatable, at least one), `--bucket`, `--search`, `--sort`, `--order` (default `asc`), `--limit`, `--offset`, `--top`, `--top_by` | `{ as_of, source, table, columns, rows, groups, available, filters }`: one question in `Stats_Query`'s vocabulary ([decision 36](architecture-decisions.md#decision-36-the-query-vocabulary-is-fixed-no-sql-from-outside-a-picker-chooses-the-rollup)). `table` is the table the picker read: `timings_site` unless a URL is grouped, filtered or searched; `requests_hourly` when every field and measure is one the rollup keeps, no search is named and the buckets cover whole hours. Each row is positional in `columns` order, a `url` group adding `url_name` beside the hash and a bucket spelled as its key. Each `--filter` carries one value, commas included; several naming one field match any of them, and a value is spelled as a group answers it (`country=Unknown`, `status_class=5`, `url=<hash>`, `bucket=<key>`). A `requests` query also filters by `rid`, a key it never groups by. Worker requests count unless filtered `worker=0`. An absent `limit` answers up to 1,000 rows; `offset` is at most 10,000. Under `top` (1–20) and `top_by`, a measure that adds across buckets, the rows are every time row of the top tuples of the non-time groups, in rank order, and `groups` counts the tuples the selection holds; without `top`, `groups` is null. `available` is false where the stats rows did not open. `filters` echoes the filters, the canonical bucket spelling and the search. The fields, the measures and every refusal are listed in the architecture guide's [Stats Rows](architecture-guide.md#stats-rows). |
| `dump_url` | READ | `hash` (required, `[a-f0-9]{8,64}`), `--server`, `--filter=field=value` (repeatable), `--bucket` | `{ stats, requests, requests_capped, aggregate_flame, flame_cut, aggregate_profiles }` from the URL's request rows, worker traffic counted. `stats` covers the last 288 buckets or the `--bucket` selection, scoped by `--server` as the list and the profile are: `hash`, `url`, `count`, the exact `errors`, the request-weighted `avg_ms` and `avg_peak_mb`, `min_ms`, `max_ms`, `max_peak_mb`, `last_updated` and `requests_per_second`. A URL with no request in the selection, or none the filter keeps, answers zero when a request row of the last seven days names it on the `--server`, and throws `URL not found` otherwise. `requests` lists the newest `RECENT_REQUEST_LIMIT` (500) by `finished_at`, across every partition: each row carries `rid`, `timestamp` (the start), `duration_ms` (null where no sample was taken, [decision 24](architecture-decisions.md#decision-24-a-duration-nobody-measured-is-not-a-timing-sample)), `finished_at`, `status_code`, `peak_mb`, `method`, and `error_status` (`-` where there is none); `dump_request` is asked with its `rid` alone. `requests_capped` is true when the list holds fewer requests than `stats.count`. `--filter` narrows the stats and the list together, as `urls` narrows its rows, and refuses a token naming `server`, which `--server` owns, `bucket`, which `--bucket` owns, or `url`, which the hash owns, naming its owner. `aggregate_flame` is rebuilt from the listed requests' stored flames every time, pruned to `MAX_URL_FLAME_NODES` (1,730) nodes, and is null under a filter, a selection, or a lookup `MAX_SCAN_S` cut short, which alone answers `flame_cut` true. Each partition its listed requests sit in is asked once for their flames, through `Partition_Node::locate_by()` and `read_many()`, a walk that stops once it holds every listed rid, or at the first segment whose index took its last line (`Partition_Node::index_mtimes()`) before the partition's oldest listed request finished, since the builder appends a flame no earlier than the tick its row's `finished_at` is clamped to; the budget bounds the rest. `aggregate_profiles` is the URL's 25-hour category profile in the leaderboard's shape, null over no profiled request. Stats that will not open answer zero stats, an empty list and a null flame and profile. Series come from `query` filtered `url=<hash>`. Throws `invalid hash format`. |
| `search_requests` | READ | `rid` (required) | `{ rid, url_hash }`, read from the rid's `requests` row, so the dashboard can open the request's URL. Throws `Request not found` where no row names the rid, as `dump_request` does, and names the outage where the rows will not open. |
| `grep_requests` | READ | `pattern` (required), `--limit` (default 20, max 50) | `{ pattern, scope, scanned_partitions, results, truncated, result_count, unparseable_lines }` — literal, case-insensitive search across the recent firehose window, grouped by request. `scope` is always `recent`: every partition's walk starts at the second-to-last segment. `truncated` reports any of the three bounds — the result `limit`, the grouping engine's per-request byte and line caps, or `MAX_SCAN_S`, the same 10 seconds of reading the index walks spend, after which the walk stops reading and leaves any later partition unread. `scanned_partitions` counts the partitions the walk entered, so beside `truncated: true` the last of them may have been read only in part. `unparseable_lines` counts the torn lines the walk skipped: it keeps no cursor to replay one from, so a line that will not unpack is counted and passed over rather than failing every search until its segment rotates. Each result carries `rid`, `url`, `method`, `ts`, `match_count` and `first_match_excerpt`. Shares its matching and grouping engine with `wp nodes reqgrep` (`Reqgrep_Core`), so both agree on what matched. Where the CLI hangs a history-miss callback on that engine, this verb wires none: a match on a late line whose earlier lines have already rotated out of the fixed 250-entry × 10-bucket history ring answers with `url` and `method` empty and `truncated` still false, so nothing in the reply says the request was reassembled from its tail alone. |
| `dump_request` | READ | `rid` (required) | The full request body and merged flame data, plus computed `findings` and the measurement `caveat`. The body's `url` is the per-path key its URL row aggregates under; `request_url` is the URL the request fetched, query kept; `url_hash` is the hash its request row names. The rid's `requests` row names where its record sits, which is read once; the flame is located on that row's partition by rid, no further back than the segment closed before the row's `finished_at`, within `MAX_SCAN_S`, and a lookup it cuts leaves `flame_data` out and answers `flame_cut` true, false otherwise. No row means `Request not found`: a request retention took, or one the flame builder has not written yet, which a just-finished request is for up to its five-second flush. Throws `Request not found` for a rid no row names or whose place holds no record keyed by it, and `no request <rid>: the stats rows did not open` when the rows will not open. |
| `ask` | READ | `descriptor` (required), `context` (every descriptor after the first, outermost last, or `--context=` repeated), `--server`, `--search`, `--filter=field=value` (repeatable), `--include_workers`, `--bucket` | The brief for one picker descriptor. A `span:` or `category:` resolves in its container: under a `request:` from that request, under a `url:` a span resolves in the flame rebuilt from the URL's newest requests over the last 288 buckets, saying so when that rebuild ran out of time, and a category answers from the URL's 25-hour profile, or from the leaderboard scoped as `--server` scopes it when the URL has none. Over stats that will not open, a `url:` brief briefs zero stats and no requests, and a span or category under a URL refuses naming the outage; under a URL no request row of the last seven days names, both refuse `URL not found`. An `overview:` answers for the page as it is being read, so the four filter options narrow it exactly as they narrow `urls`, and its `filters` and `performance_urls` pointer carry each, `bucket` included in its canonical spelling. `--filter` narrows an `overview:` or `url:` brief as `urls` and `dump_url` narrow theirs, and cannot name `server`, which `--server` owns, or `bucket`, which `--bucket` owns, and under a `url:` brief cannot name `url`, which the hash owns: a token naming one is refused, naming its owner; it narrows a `url:` brief as `dump_url --filter` does: `filter` echoes the tokens, `stats` and `worst_requests` are of the requests it leaves, and the `dump_url` pointer carries it. `--bucket` narrows a `url:` brief as `dump_url --bucket` does: `bucket` is the selection's canonical spelling, `stats` count the requests completing inside it, zero where the URL has none, `worst_requests` come from the requests completing inside it, and the `dump_url` pointer carries it. A `url:` brief's `stats.max_ms`, like `dump_url`'s, is null where nothing timed reached the URL, and so are its finding's `metric.max_ms` and the `max_ms` of an `overview:` brief's URL row. An `overview:` or `url:` brief refuses a selection `urls` refuses; every other descriptor neither reads nor checks it. Either way `worst_requests` ranks by measured duration ([decision 24](architecture-decisions.md#decision-24-a-duration-nobody-measured-is-not-a-timing-sample)): a timeout's duration is the builder's eviction wait and an abort's ends at the stop, so they and a duration of 0 rank after every measured request with a null `duration_ms`. Every other descriptor ignores the filter options. |
| `list_hooks` | READ | — | `{ total_hooks, categories, category_descriptions, hooks_by_category }`. |
| `set` | TUNE | `option` (required), `value` (a bool option takes `1/true/yes/on/0/false/no/off`, or a blank for false, which is how a hub pushes one; absent, it is refused as `value required`) | `{ option, updated: bool }`. A write that could not signal the workers to re-read, because the locks directory does not resolve, answers with that error, the option already written. |

Notable bounds: [`Performance_CI_Node`](../includes/app/class-performance-ci-node.php)'s
`MAX_SCAN_S` 10 seconds, which `grep_requests` and each flame lookup spend, a lookup it cuts
answering no flame, `RECENT_REQUEST_LIMIT` 500, `SLOWEST_ROWS` 10 and
`MAX_URL_FLAME_NODES` 1,730; and `Stats_Query`'s `MAX_LIMIT` 1,000, `MAX_OFFSET` 10,000 and
`MAX_TOP` 20. `URL_SORTS` is `count, url, avg_ms, min_ms, max_ms, avg_peak_mb,
last_updated`.

For example, these `query` tokens ask for the five busiest status classes by bucket over one
hour:

```
query requests bucket status_class --measure=count --top=5 --top_by=count --bucket=2026-10-15-14-00..2026-10-15-14-55
```

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
the decode matters most: an array-option value that is not a JSON array is refused with
`synced array-option value is not a JSON array` before anything is written, because read as
an empty array, `apply_synced()` would SAVE it — clearing the spoke's rules and pinning the
explicit "log nothing" that `reset` exists to avoid storing. Autoload follows `Config::autoload_for()`, and the write emits a settings
event that [`Settings_Sync_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-settings-sync-node.php) fans out to spokes.

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
| `aggregator` | `summary`, `list_servers`, `probe` | Per-spoke [`Remote_Source_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-remote-source-node.php) status on the hub. |

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

The stream's lifecycle — the `retry` and `connected` envelopes, heartbeats, `sse_idle_timeout`, the slot lease and its keepalive — is the substrate's, drawn in its [API](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md#sse-stream).

`subscribe` is required; `positions` carries the resume cursors; `multi_writer` is the
client's assertion that more than one process appends the subscribed logs, which buys the
reader a grace window and costs nothing but that when wrong. Per-line transforms live in the
browser, inside each dashboard's view node (`RequestLogViewNode`, `GyroscopeViewNode`,
`PerfErrorsViewNode`); the browser consumes the stream through `_stream:sse-in`
(`SseInNode`), owned by the page's one `RemoteLink`, `_stream`, which every stream graph on
the page rides by adding sources, and which hands each record to its stamp's Tee,
`_stream:<stamp>`.

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
and one POST carries every method: `initialize`, `notifications/initialized`, `tools/list` and
`tools/call`. A message with no `id` member is a notification, whatever its method, and gets a
bodiless `202 Accepted`, because JSON-RPC forbids replying to one; a request whose `id` is
`null` is still a request, and is answered. A body without `"jsonrpc": "2.0"`, or whose
`method` is not a string, answers `-32600` with a null `id`. It adds no runtime surface: `tools/call` mounts the same request graph
`/command` does, through `Bootstrap::mount_request_graph()`, and dispatches through the same
interpreter. Every argument rides by name, a list as one `--<name>=` token a member, and the
verb binds it: an argument the verb does not declare answers `isError` with
`unknown option --<name>`, which each tool's `inputSchema` announces as
`additionalProperties: false`, and a map or a null answers `isError` before anything is sent.
A tool's visibility follows its verb's declared `capability`, and each property's type and
the schema's `required` list come from the verb's own declared args: an `int` is an `integer`, a `float` a `number`, a `bool` a `boolean`, a variadic arg
such as `ask`'s `context` an array of its type, and every other type a `string`.

![The MCP round trip across four lanes: the agent, the permission gate MCP_Controller::check_permission(), the JSON-RPC dispatcher and the verb's interpreter. One JSON-RPC request per POST carries a Bearer handle.secret credential, 32 hex, a dot and 64 hex. The gate answers 403 from the fleet gate on a multisite subsite, 401 for a malformed header, and 401 when Command_Auth::load_session_record() finds no live session or the key fails hash_equals; it then makes the request the session's minting user with the scope installed as a ceiling, and last answers 429 while all 20 of the handle's slots are held, each claimed by an atomic cache add() and freed one 10-second window after its call, or 503 rate_limit_unavailable when neither memcached nor APCu answers, or the slot read fails. Dispatch answers initialize with protocol 2025-06-18, capabilities, serverInfo and instructions carrying the <site-data> rule ahead of the measurement caveat, answers nothing to notifications/initialized, lists only the tools the scope and the user both allow, and returns -32601 for any other method and -32600 for a body that is not JSON-RPC. tools/call refuses an unknown tool and an uncovered one alike, -32601 Unknown tool; turns every named argument into a --key=value token, which the verb binds by name; and dispatches on the same CI /command reaches. A return becomes one text block: the reply JSON-encoded with JSON_HEX_TAG and wrapped in <site-data>, so no payload can close the fence; a throw becomes result.isError with the message unfenced, a tool error rather than a transport error. Below: a read session sees nine tools, a tune session two more, and five verbs have no tool at all: performance.list_hooks and set, rules.save and reset, and discovery.get.](img/api-mcp-round-trip.png)

**Permission**: an `Authorization: Bearer <handle>.<secret>` header naming a live command
session, issued from the station's Sessions tab or from `POST /wp-json/newspack-nodes/v1/auth`.
Authority is the minting user's, and the session's scope only ever subtracts from it: a
manage-scoped session minted by someone who can do nothing still does nothing.

Eleven tools, one per verb:

| Tool | Node.verb | Role |
|------|-----------|------|
| `performance_overview` | `performance.overview` | READ |
| `performance_urls` | `performance.urls` | READ |
| `dump_url` | `performance.dump_url` | READ |
| `performance_query` | `performance.query` | READ |
| `search_requests` | `performance.search_requests` | READ |
| `dump_request` | `performance.dump_request` | READ |
| `grep_requests` | `performance.grep_requests` | READ |
| `performance_ask` | `performance.ask` | READ |
| `dump_rules` | `rules.dump` | READ |
| `rules_upsert` | `rules.upsert` | TUNE |
| `rules_delete` | `rules.delete` | TUNE |

`performance_urls`, `dump_url`, `performance_query` and `performance_ask` each take `bucket`, a
selection of five-minute buckets, and narrow as their verbs' `--bucket` does: up to 288
buckets, or for `performance_query` up to its source's retention, 2,016 for `requests` and
300 for `timings`.
`performance_query` takes `group`, `filter` and `measure` as arrays, beside `top` and `top_by`.

**Connecting a client**. Register the endpoint with the handle and key that session issued.
`<ID>` is the local name the client files it under:

```
claude mcp add --transport http <ID> https://<DOMAIN>/wp-json/newspack-event-logger-nodes/v1/mcp --header "Authorization: Bearer <HANDLE>.<KEY>"
```

From a shell with WP-CLI, one line issues the session and registers it. `wp nodes session issue
<label> [<role>] [<ttl>]` prints `<handle>.<secret>` and nothing else on stdout, acts as the
`--user` login, and lists the session under its label in the Sessions tab, where it can be
revoked:

```
claude mcp add --transport http hub https://<DOMAIN>/wp-json/newspack-event-logger-nodes/v1/mcp \
  --header "Authorization: Bearer $(wp nodes session issue <label> tune 86400 --user=<login>)"
```

Every tool description carries the measurement caveat, because a model handed
`175.6ms profiled / 420000ms duration` with nothing saying what is unmeasured will invent a
cause for the difference — and the invented cause reads exactly like a finding. That caveat
is the tail of `initialize`'s `instructions`.

**Every success result is fenced, and the head of `instructions` says why.** A tool returns
recorded site traffic, part of it written by the site's visitors: `dump_request` carries the `environment_v3` entry, whose values are request headers. So the
`text` block is `wp_json_encode( $reply, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )` wrapped in
`<site-data>` … `</site-data>`, and the instructions tell the reader that whatever sits
inside that tag is data and never a direction to follow. `JSON_HEX_TAG` is what makes the
fence unbreakable: every `<` and `>` in the payload becomes `\u003C` or `\u003E`, so no
payload can carry a literal `</site-data>`. **A client that decodes the `text` must strip
the fence first.** An `isError` result is the site's own message and is not fenced. See
[decision 25](architecture-decisions.md#decision-25-read-tools-and-tune-scoped-write-tools-share-one-mcp-session-and-every-tool-result-is-fenced).

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
| `static has_instance(): bool` | Whether one exists, without constructing. Tests ask it to prove instrumentation built none. |
| `static started_instance(): ?self` | The instance IFF it has started — the seam for "is there somewhere to log this line?". |
| `static reset(): void` | Finish and drop the instance. |
| `message( string $category, array $data = [], bool $shaped = false ): bool` | The one write path. Returns true when the line was written. |
| `error/warning/info/alert( string ): bool` | One-line writes under those categories. `error` and `warning` reach the Error Log; `alert` routes to `Request_Builder_Node`'s `alerts_target`, the fleet journal, and nowhere else. |
| `start( string $label, array $data = [], bool $shaped = false ): void` | Open a timed span and push its frame. `$shaped` marks `m` as a query shape, whose literals the producer already replaced, so it is not redacted as a URL would be. Drops the frame at `MAX_TIMER_DEPTH` (100), or when the start line could not be written. |
| `complete( string $label, array $data = [], string $suffix = 'complete', bool $shaped = false ): void` | Close the innermost frame carrying that label; frames above it drain as `(orphaned)`. An unknown label matches nothing. |
| `finish(): void` | Idempotent. Drain the timer stack, then write the terminal whatever the drain threw, and raise what either threw through `Worker_Should_Stop::raise()`. Registered as a shutdown function, so it runs after a fatal too. |
| `flush(): void` | Drain every materialized Partition batch. Nuclear Gyrobase calls it before `proc_open` and after a `job` entry. |
| `is_started(): bool` | The rule said `log` and `finish()` has not run. |
| `governing_rule(): ?Rule` / `governing_rule_id(): string` | The rule admitting this request. The id rides `process (start)` as `rule`. |
| `matches_url_filter( string ): bool` | Resolve and keep the governing rule for a URL. |
| `get_request_id(): string` | An empty rid means an unlogged request. |
| `refresh_firehose(): void` | Re-read the firehose segment state from disk, after a subprocess that may have written to or rotated it. |
| `relay_topic_to_ci( array ): void` | Lazy Topic→interpreter relay for an early-wired Topic. |
| `static suspend(): void` / `static resume(): void` | LIFO context stack. `suspend()` flushes the shared Topic and saves `UNIQUE_ID`; `resume()` restores in a `finally`, because `finish()` raises what its writes threw, a cooperative stop included. |
| `static begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void` | Snapshot `$_SERVER`, suspend, then rewrite to a synthetic `/jobs/{handler}/{id}`. Fires `newspack_event_logger_nodes_scope_changed`. |
| `static begin_job_context_filter( mixed $run, string $handler, string $id = '', array $message = [] ): mixed` | The `newspack_nodes/job_worker/before_job` shape: opens the context unless an earlier listener declined, passing the decision through untouched. |
| `static end_job_context( string $handler = '', string $id = '', ?array $outcome = null ): void` | The symmetric restore; the `$_SERVER` stack IS the pairing record, so an empty stack no-ops. Arity is the abort discriminator: `func_num_args() >= 3` with a null outcome marks the context aborted. |
| `static firehose_dirs( string $log_path = '' ): array` | Partition index → directory. The count is `Bootstrap::global_num_partitions()`; a topology's `var num_partitions` is its WORKER count and says nothing about the firehose. |
| `static firehose_dir_template( string $logs_dir = '<config:logs_dir>' ): string` | `{logs_dir}/firehose.p{partition}`. The `{partition}` spelling is load-bearing — `Topic_Node` substitutes only that one. |
| `static url_hash( string ): string` | 12-char FNV-1a. The shared URL identity primitive, also behind `Rule_Set::id_for()`. The two hash different inputs — a rule id hashes a PATTERN, a stats bucket a concrete URL — so don't join them. |
| `static fnv1a32( string, int $seed = 2166136261 ): int` | The hash underneath it. |
| `static generate_request_id(): string` | 32 base-36 characters over 20 random bytes, eight a five-byte chunk. |
| `static encode_request_id( string $bytes ): string` | The spelling `generate_request_id()` uses: eight zero-padded base-36 digits per five bytes. |
| `static redact_url( string ): string` | The ONE redaction path; public for that reason. Replaces the value of any query parameter whose NAME, as sent or as PHP reads it, is credential-shaped with `[REDACTED]`, keeping the name as sent; [URL-secret redaction](architecture-guide.md#url-secret-redaction) has the rules. |
| `static parse_request_line( mixed ): ?array` | `[ request_method, request_url, url ]` from a `request` entry's `m`: any method token, the absolute URL with its query, and that URL less its query; null when `m` holds no method and `scheme://` URL, the overflow stub `(truncated, original N bytes)` among them. The one parse of that line, for readers that show a request and readers that aggregate per URL alike. |

Public constants: `FATAL_TYPES`, and the four request keywords `REQUEST_LABEL` (`process`),
`REQUEST_START`, `REQUEST_COMPLETE` and `REQUEST_ABORTED`. The last two together are
`Request_Builder_Node::TERMINAL_KEYWORDS`, the pair that closes a record; without one it
strands in flight until eviction.

**Payload size.** `message()` holds each encoded entry to 3,840 bytes, the headroom under
PIPE_BUF (4,096) that keeps a lock-free append atomic against every other writer on this
multi-writer log. The constant is private, so a producer sizing its own payloads keeps its
own copy, as Pyrobase's `Runtime\Log` does.

![One firehose line. A slot row shows the 7-field message: TYPE TM_STRUCT (16), TIMESTAMP the cached clock, empty FROM, TO and ID, KEY the request id, so one request lands in one partition, and VALUE the entry. The entry is ['n', 'k'] + $data + ['ts'] in that precedence: n, the line number the builder gap-checks; k, the category that opens, closes or brackets a span and is never renamed; the caller's keys, m (URL-redacted first when it is a string the producer has not marked shaped, which a query shape is), l, duration_ms, caller and rule, where a caller's own ts wins over the stamp; ts; and truncated, present only when the fit ran. A ladder shows fit_data(): an encoding at or under 3,840 bytes is untouched; over it, a string m is cut ten percent a step from 3,840 bytes, re-encoding each step, with every other key surviving; when no step fits or m is not a string, m is dropped; and when the rest still exceeds the cap, the floor keeps n, k, ts and truncated, with the first 1,000 bytes of the oversized encoding plus a literal ... as m. Cards say why the category is never renamed (Flame_Tree::PATTERN_START and Request_Builder_Node's ' (start)' test would never open the span), that nothing is chunked or sent to error_log, and that anything larger belongs in \Newspack_Nodes\Job_Intake::queue(), which takes the auto-lock and accepts up to MAX_JOB_SIZE, 32 MiB.](img/api-firehose-entry.png)

**The second producer.** The Perl engine's `Gyrobase::Log` appends to the same firehose, and
its `@ENV_ALLOWLIST` (32 keys), `ENV_VALUE_MAX` (256 bytes), U+2026 elision marker and URL
redaction pattern are hand-maintained copies of `Log_Manager`'s. Only dndocker holds both
repositories, so its `tools/check-firehose-parity.py` is what keeps the two identical — and
holds the three copies of `sql-shape.json`, the case list this plugin's `without_literals()`,
pyrobase's `sql_shape()` and `Gyrobase::Log::sql_shape` each read, byte-identical. This
plugin's `pre-push` runs that check whenever dndocker is the checkout in hand.

The engine's start-up is one span written by two processes. Nuclear Gyrobase writes
`gyrobase init (start)` through `message()`, in its own sequence, and passes that row's `ts`
to the child as `NEWSPACK_INIT_TS`. When the variable is set, `Gyrobase::Log` writes
`gyrobase init (complete)` as the first row of its OWN sequence, `n` = 1, with
`duration_ms` measured from that stamp, and `gyrobase (start)` follows at `n` = 2.
`Request_Builder_Node` opens the nested sequence on whichever of the two arrives as `n` = 1
(`NESTED_OPENERS`) and closes it at `gyrobase (complete)`. Nothing is reserved in the
parent's sequence, so a child that dies before logging leaves `gyrobase init` open and costs
the parent no rows, and a child that dies mid-render is resumed past: the parent's next row
carries the number `seq_stack` saved for it. **The gyrobase engine deploys before nuclear.**
An engine that ignores `NEWSPACK_INIT_TS` never closes `gyrobase init`, so behind a newer
nuclear it stays open around the whole render.

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
| `newspack_nodes/worker_identified` (action, 2 args) | `Log_Manager::identify_worker` — writes `worker_type` and `worker_partition` again with the worker a spawn request validated, so its record moves to that worker's own URL row. |
| `newspack_nodes/settings_sync/value` (filter, 2 args) | `newspack_event_logger_nodes_resolve_settings_sync_value` — resolves a blank or absent value to the OWNING config's default, and hydrates a pointer rule's hooks so the ruleset ships hook-complete to spokes. |
| `newspack_nodes/registered_log_producers` | `newspack_event_logger_nodes_register_log_producers` — adds `Log_Manager::firehose_dir_template()`, so the dirs written and the dirs the log GC declares are one statement. |
| `newspack_nodes/before_reconcile` / `newspack_nodes/after_reconcile` | An anonymous pair sharing an `$entered` flag, giving the minute-cadence reconcile pass its own `/jobs/newspack-nodes` request context. |
| `newspack_nodes/stderr` | [`Diagnostics_Bridge::on_stderr`](../includes/class-diagnostics-bridge.php) — carries a substrate diagnostic into the active request or job log as a `stderr` entry, feeding the Error Log. |
| `newspack_nodes/request_graph_ready` | `newspack_event_logger_nodes_mount_service_cis` — mounts `discovery`, `performance` and `rules`. |
| `newspack_nodes/station_tab_bundles` | [`Current_Request_Overlay::register_bundle`](../includes/class-current-request-overlay.php) — adds the `current-request` bundle descriptor so the station enqueues that tab. `Current_Request_Overlay` registers two `admin_enqueue_scripts` callbacks beside it: `enqueue_on_overlay_pages` at the default priority, for the ELN pages that embed the overlay themselves, and `enqueue_inline_data` at 20, which injects this request's id into the JS global the tab reads once both enqueue paths have run. |

Named substrate callables the bootstrap registers alongside them:

- The config namespace `eln`, resolving `<eln:KEY>` tokens in `.tsl` through
  `Config::resolve_eln_token()`. It owns one key and returns null for any other:
  `is_hub`, whether an active topology declares `var is_hub = 1`.
- The `flame-index` `Formatter` the flame builder's index leg references, TSL
  having no closures.
- `Command_Interpreter_Node::$around_dispatch`, wrapped by
  [`Diagnostics_Bridge::install()`](../includes/class-diagnostics-bridge.php) around
  whatever it already held, and passing on to it, unrendered, the command-line
  closure the substrate hands each verb. The architecture guide states the verb
  span it opens and
  `Log_Manager::timed()`'s rule that every throwable propagates.

The plugin binds `newspack_nodes/periodic`, `newspack_nodes/job_handlers` and
`newspack_nodes/remote_job_handlers` nowhere; the last two are read by the substrate's
[`Job_Worker_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-worker-node.php).

### Consumed from WordPress

[`plugins_loaded`](https://developer.wordpress.org/reference/hooks/plugins_loaded/) (priority 11, the deferred bootstrap), [`rest_api_init`](https://developer.wordpress.org/reference/hooks/rest_api_init/), `admin_menu`,
`admin_enqueue_scripts`, `admin_init`, `admin_post_newspack_event_logger_nodes_reset_settings`,
`updated_option`, `added_option` and `pre_update_option` (a filter, 3 args) in the admin.

The profiler drop-in binds three more at file scope, outside this plugin's bootstrap, and
[`App\Core`](../includes/app/class-core.php) binds the rest, per governing rule:

![What fires and what listens along one logged request, as a priority timeline. The profiler drop-in's option_active_plugins filter at priority 1 takes the load baseline, a wall-clock and hrtime pair plus class and file counts, just ahead of the plugin loop; a listener short-circuiting pre_option_active_plugins stops it. plugin_loaded at priority 1 records one row per site-activated plugin; must-use and network-activated plugins announce on hooks the drop-in does not bind. plugins_loaded at -10001 builds Log_Manager, which resolves the rule for REQUEST_URI, adopts the profiler's request_time and request_ts, registers finish() and writes process (start), and the plugin rows follow as start and complete pairs under the rule's log_plugin_loads. plugins_loaded at 11 constructs App\Core, which gives each of the rule's hooks a hook_start at hook_start_priority (-10000), a sacrificial hook_spacer at PHP_INT_MAX - 2 that WP_Hook's pointer skips instead of the close, and a hook_complete at PHP_INT_MAX - 1; binds each significant_events name the list omits, with sql and http standing for the query and pre_http_request filters their spans cover, bound as no hook and taking effect only under log_queries and log_http; never binds a custom_events category, plugin_loaded, query under log_queries, or an internal newspack_nodes or newspack_event_logger_nodes hook; and adds the log_http pair (pre_http_request at PHP_INT_MAX, opening nothing for a short-circuit, and http_api_debug at PHP_INT_MIN) and the log_queries pair (query at hook_start_priority, so the span covers the filter chain, and log_query_custom_data at PHP_INT_MIN, closing on the rewritten statement and draining $wpdb->queries; SAVEQUERIES is defined if absent). While the request runs, hook_start wraps a significant hook's callbacks registered strictly between hook_start_priority and the spacer, skipping any with a by-reference parameter, each wrapper claiming accepted_args 99 and slicing back. In a worker, the before_job and after_job hooks open and restore a synthetic /jobs/{handler}/{id} context and fire newspack_event_logger_nodes_scope_changed. At shutdown finish() drains open frames as (orphaned) and writes process (complete), with error_status F after a fatal, or process (aborted) with error_status A after a cooperative stop.](img/api-request-hooks.png)

The profiler's baseline is the first [`option_active_plugins`](https://developer.wordpress.org/reference/hooks/option_option/) firing, so anything reading
`active_plugins` earlier in bootstrap moves the baseline earlier with it. Each [`plugin_loaded`](https://developer.wordpress.org/reference/hooks/plugin_loaded/)
interval also covers the loop's own per-plugin work, because WordPress offers no signal
bracketing the include alone.
