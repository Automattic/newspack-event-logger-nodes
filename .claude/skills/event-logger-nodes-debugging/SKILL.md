---
name: event-logger-nodes-debugging
description: Debugging the event-logger-nodes application — dashboards, the stats Ledgers, hub/spoke routing, SSE slot pool, reqgrep, and the request-lifecycle pipeline. Use when something visible to users is wrong (stats not showing, dashboards stuck, SSE drops, requests not being assembled, jobs not running).
argument-hint: "[symptom]"
---

# Event Logger Nodes Debugging

Application-side debugging. For substrate-level questions — workers stuck, REPL semantics, the log layout under `{base_dir}/` — see the `nodes-debugging` skill in newspack-nodes.

## When to Use

- Dashboard panels show "no data" or stale data.
- A request's timeline isn't assembling correctly.
- SSE connections drop or fail.
- A job handler doesn't fire.
- Hub/spoke aggregation misbehaves: the hub sees too few or too many entries from a spoke.

## Filter the firehose: `wp nodes reqgrep`

The application-aware view of the substrate's firehose. It decodes the 7-field positional `Message` envelope — the entry hash at `Message::VALUE`, the request id at `Message::KEY` — and renders one request at a time with timestamps, indentation, and `(start)`/`(complete)` bracketing.

```bash
# Most-recent segment forward (fast): the second-to-last segment and newer.
wp nodes reqgrep --recent

# All segments (slow but thorough).
wp nodes reqgrep

# Filter to a pattern (rid, URL, or any text found in the packed envelope).
wp nodes reqgrep /admin-ajax

# Specific request id.
wp nodes reqgrep abc123def

# Live tail.
wp nodes reqgrep --follow

# Only requests that reached neither `process (complete)` nor `process (aborted)`.
wp nodes reqgrep --incomplete

# Raw JSONL instead of the formatted tree.
wp nodes reqgrep --raw
```

The pattern is a literal, not a regex: `Reqgrep_Core` `preg_quote`s it and matches case-insensitively anywhere in the packed envelope, so metacharacters stand for themselves. A pattern equal to a rid short-circuits to that request.

Two more flags size the history ring, which holds the lines of rids that have not matched yet so a match on a late line still yields the request from its first: `--bucket-size=<size>` (default 250, clamped 1–10000) and `--num-buckets=<count>` (default 10, clamped 1–100). A `Couldn't find request start in history` warning means the ring rotated those earlier lines away; raise either flag. `--firehose=<path>` overrides the base directory and is validated first — it must resolve inside `Config::get_logs_directory()`.

The in-flight cache is separate and fixed at 100 requests × 3 buckets rotating every 60 seconds; a request evicted from it prints with a trailing `[incomplete]` line. `Reqgrep_Core` also clips one request at `MAX_LINES_PER_REQUEST` (20,000) or `MAX_BYTES_PER_REQUEST` (10 MB). The CLI prints a clipped request with no marker saying so; the `grep_requests` verb reports the same clip as `truncated` on its reply.

The firehose `Partition` writes in batches: it accumulates records and flushes when the next one would carry the batch past `MAX_LINE_SIZE` (4096, the PIPE_BUF ceiling that keeps a lock-free append atomic), and `Log_Manager::finish()` flushes the residue with the terminal line. So `--follow` lags by up to one batch, and a process killed before shutdown loses whatever that batch still held; the `flush_every_line` setting flushes after every line instead, which is what survives an OOM kill.

Stdin wins over every other source: piping packed envelopes in makes `--follow` and `--recent` inert. Lines that are not packed envelopes are skipped, so a capture from an older wire format yields nothing.

Two producers write this firehose, and reqgrep decodes both: `Log_Manager` in PHP, and `Gyrobase::Log` in Perl, which writes `firehose.p0` straight from a gyrate render. `--raw` tells them apart, because the envelope's FROM field is the producer: Perl stamps `gyrobase` there and PHP leaves it empty. The two share one wire contract — the 34-key `ENV_ALLOWLIST` behind the `environment_v3` entry, `ENV_VALUE_MAX` (256), the elision marker and the URL redaction pattern — and dndocker's `tools/check-firehose-parity.py` is the only thing holding the two repos to it. This plugin's `pre-push` runs it whenever the checkout sits inside dndocker.

Two markers in the output stand for entries `Request_Builder_Node` removed, and no interval either side of one is measurable: `entries (lost)` (discarded on overflow) and `entries (aggregated)` (merged by the pressure fold, and present in the flame tree instead). A record that ends abnormally carries an `error_status` from `Request_Builder_Node::ERROR_STATUSES` — `F` fatal, `T` timed out, `A` aborted, `I` incomplete.

## Inspecting via the REPL

Pivot into a worker to see node state. Worker ids are `<topology>.p<N>`, and `wp nodes status` lists every catalog topology with its live state — that listing, not a hardcoded name, is what you attach to. `wp nodes types` reports the narrower thing: the active topology groups the fleet spawns.

```bash
wp nodes status                # every catalog topology + live workers + consumer lag
wp nodes cli performance.p0    # attach to the topology carrying Request_Builder + Flame_Builder
```

Topology names come from the `.tsl` filename, with no `name:` frontmatter. This plugin ships `request-builder`, `flame-builder`, `performance` (both of those), `job-router`, `job-feed`, `job-hub`, `job-spoke`, `complete` (performance + job-hub), `aggregator` and `hub-control`; the substrate adds the stock `job-intake`, `job-worker`, `settings-sync` and `topic-probe`. Which are active depends on the deployment's substrate `topologies` config list, whose shipped default is empty. A deployment may also register renamed groups — eve runs `aggregator-hub` and `job-spoke` beside `performance` — so read the list rather than assuming.

Worker-restart classification is a separate vocabulary: each `Settings_Schema` field's `restart:` key holds CONSUMER NODE TYPES, and `Restart_Planner` resolves them to the live topologies running a node of a matching class, ancestry included — so `Log` satisfies a `Partition` declaration. The substrate's segment-geometry fields declare `[ 'Partition', 'Topic', 'Log' ]`, this plugin's three rendered fields declare `'all'`, and a field needing no recycle declares `[]`.

From the prompt:

```
status                                     # shell status lines, printed locally; no message leaves
ls -alst                                   # every node with COUNT/SINK/TARGET columns
dump request-builder                       # every property of Request_Builder_Node (alias of dump_node)
dump flame-builder                         # start here when flame or stats writes are missing
dump completed:tee                         # the Tee's target list
ls -a request-builder                      # every node whose NAME matches that glob
command_node flame-stats:url:config \
    get <hash>                             # a verb at <cwd>/<path>, cwd unchanged (aliases: command, cmd)
request flame-stats:url MGET <hash>        # the url Table answers its protocol: GET, MGET, TOUCH, RM
request request-builder GET_CACHE          # in-flight depth (alias of request_node)
request flame-builder GET_STATS            # stats accumulator + pending buckets
cmd request-builder:config purge           # drop every in-flight request, reporting the count
cd request-builder:config                  # send later verbs TO that interpreter; `cd /` resets
```

Valid `ls` flags are `-a`, `-c`, `-l`, `-s` and `-t`, combinable as `-alst`. There is no `-o` flag: the connection model is `sink`/`target`, with no `owner`. That is also why `-a` is the form to reach for — without it the argument scopes by SINK, and every node in these graphs sinks into `_command_interpreter` and steers with `target`, so `ls request-builder` prints nothing at all.

A stats Ledger answers `SUM`, `TOP` and `MEMBERS` to a structured query the prompt does not spell, so read one through its file with `sqlite3` (Stats schema, below). `GET_CACHE` and `GET_STATS` are the two TM_REQUEST verbs worth knowing, because they answer the questions the dashboards cannot:

| Verb | Node | Reply |
|---|---|---|
| `GET_CACHE` | `request-builder` | `{ pending_count, oldest_rid, oldest_age_s, sample, line_counter }` |
| `GET_STATS` | `flame-builder` | `{ stats_count, pending_url_count, intern_count, pending_buckets, last_settle_age_s, auto_tune_pending_count, is_hub, significant_events_count, narration }` |

A `line_counter` of 0 on a busy site means the firehose Consumer is reading nothing. A climbing `oldest_age_s` means requests are stranding in flight — `Request_Builder_Node` evicts them 720 to 1080 seconds after their last line (three buckets rotating every 360 seconds, and the 720-second floor is what clears a worker's 595-second spawn request by two minutes) and writes them out with `error_status='T'`.

Once the in-flight cache is wedged rather than merely slow, `cmd request-builder:config purge` is the escalation: it drops every in-flight request and reports how many went. Those entries are DISCARDED, never emitted as timed out — a wedged builder holds requests that will never complete, and answering that with thousands of doc writes costs more than losing records already known to be dead. Ordinary bucket eviction still emits. The verb declares `action`, so the topology editor withholds it from the persisted `.tsl` and it stays an operator verb rather than a line re-running on every worker boot.

Two owned siblings appear in `ls -a` under composite names and answer to `dump`: `request-builder:flight` (the hidden in-flight snapshot node) and `flame-builder:auto-tuner`.

Typo a worker id and the cli fails fast: ``Error: no worker '<id>' (run `wp nodes status` to list active workers)``. A missing lock dir is not by itself the answer — an on-demand worker sleeps holding none, so the attach falls through to `Spawn_Coordinator::wake_sleeping_worker()` first.

`wp nodes cli` refuses to run as root, because the workers run as an unprivileged user and root-owned IPC dirs lock them out. Always `docker exec -u <user>`.

Scripted pivot sessions (`echo cmd | wp nodes cli performance.p0`) drain cleanly without a trailing `sleep`: the substrate sends a TM_EOF on stdin close and waits for the worker's echo before exiting, so in-flight responses land first.

## Stats schema

The stats live in nine SQLite Ledgers every flame-builder partition appends to, and each URL's flame blob in one `flame-stats:url` Table per partition, all declared by `flame-builder.tsl` (decisions 1 and 36). A Ledger is one file, `{base_dir}/ledgers/{name}.sqlite`, whose `rows` table holds `( t, k, x, w, s, c0… )`: `t` the start of the five-minute bucket a request finished in, `k` the scope, `x` its member, `w` the partition that wrote the row, `s` that writer's sequence, and `c0` onward the declared columns in order. A set Ledger, `stats:names` or `stats:search`, holds `( t, k, x )` alone. The url Table is `{base_dir}/tables/flame-stats:url.p{N}.sqlite`, its `kv` table holding `key`, `value` and `expires`, a URL's blob under its bare hash. The builder asks them by message through its `Table_Client`; a `performance` verb mounts the ones it reads, read-only, for the rest of the request.

No row is updated. A settle appends one delta row per `( t, k, x )` its span touched, so a key read raw shows several rows for one bucket, one per settle and partition that saw it, and every read sums them. A row goes only when its hour-long segment passes the lifespan, or a flush empties the Ledger.

| Ledger | `k` | `x` |
|---|---|---|
| `stats:totals` | `site` | '' |
| `stats:dims` | the dimension, or `{dim}:{server_key}` on a hub | the value |
| `stats:categories` | `site` or `srv:{server_key}` | the category |
| `stats:leaderboard` | `site` or `srv:{server_key}` | '' for the requests profiled, the category, or category TAB entry |
| `stats:url-rows` | `r:{server_key}` for reader traffic, `w:{server_key}` for worker traffic | the URL |
| `stats:url-dims` | `{dim}:{url_key}` | the value |
| `stats:url-cats` | `url_key` | the category |
| `stats:names` | `url:{hash}`, `servers:r`, `servers:w` | the URL a hash names; a server that filed rows of that family |
| `stats:search` | a word of a URL's path | the URL |

`server_key` is `Stats_Store::server_key()`, the FNV-1a hash of the server name, which `stats:names` turns back into names; `url_key` is the URL with its whitespace percent-encoded. A URL row filed under `w:` is worker traffic, which the URL table leaves out unless a reader asks for it, so a scoped read that comes back empty is often the wrong family. `stats:names` and `stats:search` file at the start of the hour, so a read of either opens on the hour its window starts in.

```bash
# The Ledger files, and the url Table's per partition, beside SQLite's WAL.
ls -la {base_dir}/ledgers/ {base_dir}/tables/

# One URL's rows in one Ledger, a delta per settle; sum them to read the total.
sqlite3 -readonly '{base_dir}/ledgers/stats:url-rows.sqlite' \
  "SELECT t, k, w, s, c0 FROM rows WHERE x = 'https://example.test/about' ORDER BY t"

# What each Ledger holds, its lifespan, file size and owning worker.
wp nodes tables list

# Rotate the install salt, orphaning every Newspack plugin's memcached and APCu
# keys at once. The stats and the command sessions carry no salt and stay. The
# rotation asks every live worker to restart, because each memoizes the scope
# at boot; a restart that did not land warns beside the success line, and that
# worker takes the new scope on its next spawn. This plugin keeps no salt.
wp nodes memcache flush

# Reset the statistics, and migrate a stats schema change. Each live worker
# flushes a Ledger in place; the url Table's files are replaced.
wp nodes tables flush stats:totals stats:dims stats:categories stats:leaderboard stats:url-rows stats:url-dims stats:url-cats stats:names stats:search flame-stats:url
```

**Caps to remember**: `MAX_SERVER_VALUES=128` servers named over the last 24 hours, past which a settle files a server's rows under `Other` in every per-server key and on the `server` dimension; `SERVERS_READ_MAX=250`, past which a site `urls` page is refused and a settle files every server of its span under `Other`. A leaderboard category keeps its 50 slowest entries once it holds more than 100 in a bucket. A URL blob is capped to `Stats_Store::ITEM_BUDGET` (900,000 bytes) before it is written: its profile to half through `MAX_LB_CATEGORIES=200` and a byte estimate, each flame-tree copy to a quarter. A word filed for more than `URL_SEARCH_MAX` (5,000) URLs in the window narrows no search; `TERM_WORD_MIN=2` and `TERM_WORD_MAX=12` bound the words filed, and a longer word is filed cut. Nothing else is capped: `ua`, `ja4` and `from` keep every value.

**Retention** is `max( Stats_Store::MIN_RETENTION_SECONDS, min_lifetime )` — 3600 floor, `min_lifetime` defaulting to 43200. Each Ledger keeps `<eln:ledger_segments>` hour-long segments, the window in hours rounded up and never fewer than 25, and the Router tick drops a segment whole once it passes the lifespan; a row appended already past it drops and counts `past the lifespan` on the `stats writes` line. The url Table keeps `<eln:stats_url_ttl>`, `max( 3600, window/24 )`, so at the default window a URL unseen for over an hour has lost its flame data while its rows remain.

**What memcache still holds.** Heavy log rules — hooks past `Rule_Set::INLINE_HOOK_LIMIT` (100) — tier their hook list out of the autoloaded option into the substrate Table namespace `eln-rule-hooks` (`table:eln-rule-hooks:<rule-id>`, TTL 3600, warmed on a miss from the non-autoloaded `newspack_event_logger_nodes_rule_hooks_<id>` option). It is a warm cache, not the system of record. The `urls` verb's page and header cache (`table:eln-urls-page:…`, 60 s) and the flame builder's auto-tune lock (`evlog:auto_disable_lock`, 5 s, through `Cache_Backend::shared_first()`) sit beside it. Outside this plugin: the SSE slot pool (host-scoped `sse:{slot}`) and each `Remote_Source_Node`'s status snapshot (site-scoped `remote:{node}:{spoke partition}`).

`wp nodes ruleset-bench` is where that 100 comes from, and this plugin's only other WP-CLI verb. It sweeps hooks-per-rule against rule count and prints each cell's three median costs — the alloptions unserialize tax, an inline read plus bind, and a table fetch plus bind — over `--iterations` timed runs (default 200). It writes only its own bench-private Table keys and never reads or touches the live ruleset, so it is safe on a running site.

## Dashboards

Page slugs this plugin owns (URL path `/wp-admin/admin.php?page=<slug>`):

| Slug | What |
|---|---|
| `event-logger-overview` | Performance overview — URL leaderboard, breakdown by server / status / category; also the top-level Event Logger menu landing page |
| `event-logger-errors` | Error log dashboard |
| `event-logger-gyroscope` | In-Flight Requests — a sortable table of the requests still running, one row each, re-rendered at the cadence the dropdown or the 0-9 keys set |
| `event-logger-requests` | Request Log — recent completed requests plus drilldown |
| `newspack-event-logger-nodes` | Application settings, registered under Settings by `Admin\Admin`, not under the Event Logger menu |

The Performance dashboard's selection lives in the query string, so every view is a shareable link: `&url=<hash>` opens a URL, `&request=<rid>` opens one of its requests, and `&search=` seeds the filter once on mount.

Hook sections and their colors come from `hook_categories.json` at the plugin root, which a site overrides through the `newspack_event_logger_nodes_hook_customizations` option: `Hook_Categorizer` serves the settings page's hook picker through `performance.list_hooks`, and the entry point publishes the same file whole on `window.eventLoggerHookCategories`, where the Gyroscope legend takes its colors. Custom-event colors are a separate map, `Config::get_custom_colors()`, published over `Flame_Tree::platform_colors()` through `Config::span_colors()` on every one of the five pages and by the current-request tab as `window.eventLoggerCustomColors`, and alone, on the settings and overview trees, as `window.newspackNodesCustomColors`, which their event pickers read. Both the file and the merge over the option are memoized for the life of the process and only tests drop them, so an edited customization reaches a long-running worker on its next spawn.

The substrate's own dashboards are station TABS on its top-level "Nodes" page (`newspack-nodes-station`), contributed through the `newspack_nodes/station_tab_bundles` filter: Overview, Jobs, Console, Partition Viewer, Log Viewer, Config Audit, Vault, Sessions and Aggregator. `\Newspack_Nodes\Admin\Admin::register_event_dashboard_pages()` registers no page of its own; it is the `admin_menu` priority-11 seam a standalone dashboard would hook.

This plugin contributes one tab of its own: `eln-current-request`, an `overlay`-host tab labelled "Request" that summarizes THE request the overlay is riding — duration, status, errors and peak memory, plus its trace and profile — and deep-links to the full record in the Performance dashboard. `Current_Request_Overlay` localizes `{ rid, partition, perfUrl }` and enqueues the bundle on the station through the same filter, and directly on every page that mounts `<DebugOverlay>` itself — the four ELN dashboards plus whatever the substrate's `overlay_pages` registry adds, which is how another plugin's overlay page gets the tab too. Four states render, and the two that look broken are not: `idle` whenever `Log_Manager` left the rid empty — logging disabled, a root process, or no matching `log` rule — and `processing` for the beat before `Request_Builder_Node` writes the record. The tab polls each tick until the record and its flame land, so there is no Refresh button to hunt for.

Every panel's data comes from one of the three service CIs this plugin mounts on `newspack_nodes/request_graph_ready`: `performance` carries the dashboard slices plus `list_hooks` and the `set` writer, `rules` carries the editor's `dump`, `save`, `upsert`, `delete` and `reset`, and `discovery` carries one `get` verb reporting the ruleset's hooks and custom events. `dispatch()` refuses a caller below the role each verb declares (ADR-26) — `read` for the slices, `tune` for the writes — so a refusal comes from the declaration and never from the handler. `docs/API.md` lists every verb with its arguments.

If a dashboard says "Connection lost", check in this order:

1. The page enqueues its build through the `$page_to_tree` map in `newspack-event-logger-nodes.php` — does the slug match one of the five?
2. `\Newspack_Nodes\Admin\Admin::enqueue_react_page()` returned a handle. It returns null on the wrong page or a missing bundle, and the `window.*` payloads bind to that handle, so a missing bundle ships no globals rather than half a page.
3. `restUrl` in the localized `NewspackNodesData` is bare `/wp-json/`, not pre-namespaced.
4. The relevant service CI is mounted on `newspack_nodes/request_graph_ready`, not `rest_api_init`. Every dashboard verb is a service CI; this plugin has no `includes/rest/` directory.
5. Browser station shows the REST URL it tried. Commands ride the unified `POST /wp-json/newspack-nodes/v1/command`; SSE rides `GET /wp-json/newspack-nodes/v1/messages/stream`.

If panels are blank but the page renders, the stats path failed soft: `Stats_Store` reads a Ledger or url Table that refuses or does not answer as empty, and the `urls` reply says `provisional`. A url Table whose backend cannot open logs `performance: the url Tables did not mount` and answers no blob. A Ledger that cannot mount fails the verb instead, naming why — `pdo_sqlite` missing, a file another declaration made (`wp nodes tables flush` it), or a process running as root — so the panel shows the refusal. `php -m | grep pdo_sqlite` settles the first.

## SSE

There is no per-plugin SSE controller layer. The substrate's `SSE_Out_Node` serves the unified endpoint `/wp-json/newspack-nodes/v1/messages/stream`; clients subscribe to one or more `<log>.p<N>` partitions and receive a 7-field message envelope per line plus an idle `heartbeat` event.

The slot pool is substrate-internal — this plugin never calls `SSE_Slot_Pool::wire()`. The endpoint inherits the substrate's concurrency cap and fails CLOSED: memcache down means HTTP 429, because the slot pool IS the rate limit.

Client-side, each dashboard mounts the same backbone through `useStreamGraph`: a `RemoteLink` composing an `SseIn` ingress plus the shared `_http` (the `/command` boundary) and `_heartbeat` singletons. Gyroscope subscribes one glob (`gyroscope.*`) through that hook directly; the Request Log and the Error Log go through this plugin's `useGlobStreamGraph`, which adds the two-level pick a glob needs — the whole glob tailed live (`completed.*`, `errors.*`), or one partition dir with a segment rail stepped through the substrate's `raw-logs` CI. `_heartbeat.target` is fixed wiring, `_http/workers`, and it pokes the Workers CI's `heartbeat` verb every 15 seconds per live lease, which calls `SSE_Slot_Pool::touch`.

Per-line shape mapping lives in the dashboard's own view node, so the view is the single place that knows the envelope-to-render-entry mapping: `shapeRow()` in the two `LogStreamViewNode` subclasses (`request-log-view-node.js`, `perf-errors-view-node.js`) and `fill()` in `gyroscope-view-node.js`, which mutates a request map rather than a ring.

Unexpected 429s mean the slot pool is exhausted. The slots are HOST-scoped, so read them with the `--host` flag: `wp nodes memcache get --host sse:0`. The owner token lives at `sse:{slot}`; the identity it was issued to lives at `sse:{slot}:lease:{owner}`.

Clients reconnecting every few seconds mean the slot lease is expiring. The browser pokes every 15 seconds and `sse_slot_ttl` defaults to 60, and a configured value below 45 is raised rather than honoured — that floor is three poke intervals, sized so a client spending one of them on a re-auth round trip is not fenced. The server's `check_slot` inspects the lease on every drain iteration and NEVER refreshes it, so a stream that stops poking loses the slot at the TTL.

## Querying the stored record: the MCP endpoint

This plugin registers exactly one REST route of its own: `POST /wp-json/newspack-event-logger-nodes/v1/mcp`, a JSON-RPC MCP server (`PROTOCOL_VERSION 2025-06-18`) wrapping ten verbs that already exist — seven `performance` reads plus `rules.dump`, `rules.upsert` and `rules.delete`. It is the fastest way to interrogate one record without a browser.

| Tool | Verb | Answers |
|---|---|---|
| `grep_requests` | `performance.grep_requests` | Pattern-search recent firehose traffic; a bounded summary per match (`rid`, `url`, `method`, `ts`, `match_count`, `first_match_excerpt`) |
| `search_requests` | `performance.search_requests` | Locate one rid across every partition |
| `dump_request` | `performance.dump_request` | Full request plus flame data for a rid; the `partition` argument is a hint, not a filter — every partition is searched either way |
| `dump_url` | `performance.dump_url` | One URL's stats, its 500 newest requests across partitions, the aggregate flame summed over partitions |
| `performance_urls` | `performance.urls` | The URL table: every URL-set fact under the filters it applied |
| `performance_overview` | `performance.overview` | Site totals and breakdowns the URL index cannot answer |
| `performance_ask` | `performance.ask` | `Findings` for one picker descriptor — `overview:`, `url:`, `request:`, `span:`, `entry:` or `category:` |
| `dump_rules` / `rules_upsert` / `rules_delete` | `rules.*` | The per-URL logging ruleset |

`grep_requests` and `wp nodes reqgrep` share one engine, `Reqgrep_Core`, so they agree byte-for-byte on which lines belong to which request and when it is complete.

Three symptoms are specific to the endpoint. A 401 means the `Bearer <handle>.<secret>` credential named no live session — sessions live in a wpdb Table, so a salt rotation leaves them alone; flushing `nodes-sessions` by name (`wp nodes tables flush nodes-sessions`) revokes them all, so reissue after that. A 429 means the per-session budget: `RATE_LIMIT_BURST` 20 calls per `RATE_LIMIT_WINDOW_S` 10 seconds, checked after the credential so an unauthenticated flood cannot poison the transient table. MCP does not go through `/command`, so the substrate's per-user cap does not bound it. A 403 carrying `newspack_nodes_not_fleet_site` means the request reached a multisite SUBSITE: the fleet is network-global, so `Bootstrap::fleet_gate()` admits the main site alone, and that check runs before the credential does.

The session's scope is a ceiling, never a grant: `tools/list` offers only what the scope covers, and a manage-scoped session minted by a user who can do nothing still does nothing.

## Hub / spoke routing

A node is a hub when `Config::resolve_eln_token( 'is_hub' )` says so, and it answers on either of two signals because neither covers both shapes: an active topology named `aggregator` or including it, or any active graph carrying a `Remote_Source` node. That accessor is the only way in — the derivation behind it and its memoization wrapper are both private, so a `wp eval` aimed at either throws a PHP Error. There is no operator toggle, and `tests/unit/RetiredConfigKeysTest.php` guards `enable_aggregator` and `enable_workers` against coming back as one.

Every node dispatches its own `k:"job"` entries against `newspack_nodes/job_handlers`. The hub additionally runs `aggregator`: per-spoke substrate `Remote_Source_Node`s pull each spoke's firehose, and this plugin's `Remote_Job_Rewrite_Node` — wired between the sources and the firehose `Topic` — rewrites those ingested `k:"job"` lines to `k:"remote_job"`, which the hub's `Job_Worker_Node` dispatches against the separate `newspack_nodes/remote_job_handlers` map. The stock `aggregator.tsl` ships NO `Remote_Source` nodes; the operator wires them on the topology console canvas. Spoke credentials live in the substrate Vault.

The hub's other sweep is `Discovery_Collector_Node`, mounted by `hub-control`: it mints one signed `discovery.get` per spoke on its own tick, which that topology sets to 300 seconds, and union-merges the replies into the `discovered_hooks` and `discovered_events` staging options the rule editor's hook picker offers. It writes no rule, so an empty picker on a hub is this tick — the collector needs the same per-spoke `HTTP_Out` nodes settings-sync uses.

Diagnostic flow:

```bash
# Is this node a hub? Derived from the active graphs, not an option.
wp nodes types                 # active topology groups the fleet spawns
wp nodes status                # every catalog topology plus what is live

# The flame builder answers it directly, per partition.
echo 'request flame-builder GET_STATS' | wp nodes cli performance.p0

# One Remote_Source's own reconnect/backoff snapshot. The second token is the
# node name the operator gave it, the third the SPOKE log it pulls.
wp nodes memcache get 'remote:spoke-1:firehose.p0'
```

The aggregator's read side is the substrate `aggregator` CI, whose three verbs are `summary`, `list_servers` and `probe`. It is reachable from the Aggregator tab on the station; hand-rolling a `curl` at `/command` is not a shortcut, because the body must be JSONL of 7-element positional `Message` arrays AND every command carries an HMAC `auth` envelope that the endpoint verifies before dispatch. Spoke credentials are managed through the substrate `vault` CI.

Two ageing rules decide what that `remote:` key says. `Remote_Source_Node::publish_status()` rewrites the snapshot at most once a wall second, on `Remote_Link_Node`'s housekeeping latch, and writes it with a `STATUS_TTL` of 300 seconds — so a hub worker that dies leaves its last snapshot standing for five minutes, and the Aggregator tab keeps reporting that spoke `connected` until the key expires. Once it does, `Aggregator_CI_Node`'s `list_servers` reads an empty partition and the server falls to `down`. Separately, `publish_status()` nulls `last_heartbeat_response` and `last_heartbeat_rtt` unless the stream is connected AND the last reply landed within four `Remote_Link_Node::HEARTBEAT_INTERVAL`s (60 seconds), so the Status badge cannot latch on a stale timestamp: a null RTT on an otherwise live row is that rule rather than a failed heartbeat. `last_error` survives a successful heartbeat instead of being blanked, republished from `SSE_In::connection()`, because the command channel can answer while the stream is down.

If a hub is missing entries from a spoke, read that `remote:` snapshot — but read it for churn, not for the gap. A reconnect resumes from the cursor the last forwarded record's breadcrumb set, so a bouncing spoke costs duplicates rather than records. A real gap means the spoke reaped the segments the hub's cursor still pointed into: `Consumer_Node::normalize_cursor()` then snaps that cursor to the oldest segment left, and everything between the two is gone.

## Reading the flame builder's narration

The flame builder tells what its settles appended to the Ledgers and the url Table on the worker's own record, the `restapi` record of `/wp-json/newspack-nodes/v1/workers/spawn`: a point event once a minute, and a span at a clean stop. The architecture guide's firehose-entry section carries the table. They reach a record only where a rule covers that route. Every counter is in the `m` — a span's on its `(complete)` — a head then `<n> <name>` pairs joined by ` · `, zeros left out, because `m` is what a stored record keeps. Read them with `wp nodes reqgrep 'stats writes'`, or open a worker record in the Requests dashboard.

- **`stats writes`**, a point, about ten a lifetime, one a minute, and one at the stop: `12 settles · 4180 rows` then everything else the settles counted. A climbing `refused <ledger>` is a Ledger refusing appends — its rows are that span's loss; `past the lifespan` is rows the Ledger dropped as older than it keeps, which a replay of an old log produces; `unread url blobs` is the url Table failing a read and `refused url blobs` failing a write; `left out of the carry` is URL trees past `CARRY_URL_BYTES` that a non-interval checkpoint could not carry.
- **`stats sweep`**, a span: a clean stop's auto-tune emit, with the stop's last `stats writes` nesting inside it and `stopped · 2 buckets carried` in its `m`, the buckets the stop's frame carries unsettled for the successor to settle.

A steady worker lifetime tells about thirty lines across both builders, a span counted once. `GET_STATS`'s `narration` shows the counters the next line will carry.

The request builder narrates on the same record, through the same `Narration` trait:

- **`requests writes`**, a point once a minute and at a clean stop: `lines` assembled, request-builder narration aside, `records`, `summaries`, `errors` and `alerts` written, then each kind of line dropped and each envelope let go before it completed. `orphan lines` climbing means lines arriving for envelopes this worker never opened — a respawn mid-request, or partitions reshuffled; `gap lines` and `duplicate lines` are the sequence check refusing lines; `runaway lines` are a request past the stack-depth cap; `no-url records` completed without a `request` line and were never written. `timed out` counts envelopes the clock let go, `crowded out` those a full newest bucket pushed out — raise `bucket_size` if that climbs — and `dropped` those of either that never carried a URL and so were not written. `folded entry_budget` counts folds where the pool crossed its budget and the largest envelope paid, `folded max_entries_per_request` those where one envelope crossed its own cap, each beside the raw entries they reclaimed; frequent `entry_budget` folds on small requests mean the budget is too small for the traffic.
- **`requests checkpoint`**, a span for a checkpoint whose carry moved since the last one told — a line folded in that is not request-builder narration, an envelope let go, a purge or a restore; a quiet builder's checkpoints say nothing: envelopes, the raw entries they hold, and how many folded. Its `duration_ms` is the snapshot's cost; entries near `entry_budget` mean the next fold is close.
- **`requests restore`**, a span at a respawn: what came back.
- **`requests expire`**, a point per operator `purge`: `purged` and how many envelopes it dropped.

## Common failure modes

**Dashboard 429s immediately.** Service-CI verbs carry no throttle of their own, but `/command` does: `HTTP_In_Node::RATE_LIMIT_BURST` is 30 POSTs per `RATE_LIMIT_WINDOW_S` (1 second) per user, bucketed by clock-second, tunable through the `newspack_nodes/command_rate_limit` filter. A 429 on a `/command` POST is that budget, not a per-CI limit; a 429 on `/messages/stream` is the SSE slot pool.

**The time axis is the reply's, never the page's.** Each `overview`, `dump_url` and `url_breakdown` reply names its `slots`, the 288 five-minute buckets ending at the current one, and `buildChartSlots()` draws exactly those keys, so a browser clock off by a minute moves nothing. A chart with no series drawn before its first reply is a reply that has not named its slots yet.

**The URL modal's "The request index scan stopped early" banner will not clear.** `UrlDetailMergeNode` treats `scan_stopped_early` as a property of the accumulated request list rather than of the latest reply: `_merge()` forces the flag back ON whenever the retained payload carries it and never forces it off, because a walk that ran out of budget, or a partition holding more than `RECENT_REQUEST_LIMIT` matches, left rows missing from the accumulation and a later complete walk does not put them back. A URL busy enough to put 500 requests in one partition inside the window carries the note from its first read. Only the `clear` control drops the note, with the list it described, and `usePerformanceGraph` sends one when the modal closes and when the server scope changes — so reopening the modal is the fix. The flag `docs/API.md` describes is the server's, per single `dump_url` call, and says nothing about this accumulation.

**`reqgrep --recent` shows nothing but the firehose is being written.** Three possibilities. First, `Log_Manager` never started: `enable_logging` is off, or the process is root (`posix_geteuid() === 0` returns before the matcher is even built, because root-owned segment files the web user could never append to are worse than no logs). Second, the firehose path doesn't match — check `Newspack_Event_Logger_Nodes\Config::get_logs_directory()`. Third, no ruleset rule matches the URL: `Log_Manager` resolves one governing rule per request through `Rule_Matcher`, and no match — or a `skip` rule — writes nothing. **Empty means empty**: an absent ruleset logs nothing, because there is no implicit `/` log-all baseline. Read it through the `rules` CI `dump` verb or `wp option get newspack_event_logger_nodes_rules`. The shipped default seeds a `/` log rule alone; the substrate's own endpoints and `/wp-cron.php` log as worker traffic.

**The Error Log is empty while the substrate is printing warnings.** `Diagnostics_Bridge` listens on the substrate's `newspack_nodes/stderr` seam and logs each line to the ACTIVE request as a `stderr` entry, which `Request_Builder_Node` routes to its errors target. With no started request logger — an unlogged URL, a CLI process, a root process — the line is dropped here and only the substrate's own `error_log()` keeps it. Fleet alerts never take this path: the substrate journals those into `alerts.p0` itself.

**A record's timeline starts late and names no plugin loads.** The `00-newspack-profiler.php` mu-plugin drop-in records the moment PHP began the request and times each site-activated plugin's load; `Log_Manager`'s constructor consumes `request_ts` and `request_time` from the `$newspack_profiler` global and stamps `process (start)` with that moment. Without the drop-in installed under `wp-content/mu-plugins/`, the record begins where `Log_Manager` emitted its first line, deep in bootstrap, and carries no plugin rows at all.

**A record times PHP and nothing below it.** Four per-rule knobs decide how deep a logged request is instrumented, and every one is off unless the rule sets it.

| Knob | Default | What it adds |
|---|---|---|
| `log_http` | off | An `http` span per outbound request, between `pre_http_request` at `PHP_INT_MAX` and `http_api_debug` at `PHP_INT_MIN`. A short-circuited request opens nothing, because WordPress returns it without firing the close |
| `log_plugin_loads` | off | A `{slug} plugin` span per site-activated plugin, flushed by `00-newspack-profiler.php` on `plugins_loaded` at -10001. The mu-plugin times them either way; the knob gates only the flush, so turning it off costs nothing and measures nothing |
| `log_queries` | off | A `sql` span per query, between `query` and `log_query_custom_data`. It defines `SAVEQUERIES` for the life of the process and costs two entries per query |
| `trace_hooks` | off | The calling frame on each hook entry's `l`, so one hook firing sixteen times splits into a flame node per caller |
| `trace_callers` | off | A deep backtrace on the start entry's `caller` field, on hook, query and HTTP spans alike, budgeted per CALLER of each hook, statement shape and URL, and reset for each job — a stored `true` is a count of 1 |

Both span pairs name their CALLER, never the host or the table, because one host answers nothing when every call goes to the same one. Edit the knobs through the rules editor or the `rules` CI's `upsert`: a record carrying no `sql` rows means `log_queries` is false on its governing rule, not that the bridge broke.

**A record arrives as a head and a tail around a marker, or stops mid-flight.** Three bounds inside `Request_Builder_Node` do it, none of them a config key. Two are positional arguments — `max_entries_per_request` at index 3, `entry_budget` at index 2 — and `request-builder.tsl` passes only the first two positionals (`100 3`, the bucket size and the bucket count), so both run at their defaults. The third, `MAX_STACK_DEPTH`, is a private constant no topology can move. `max_entries_per_request` (20,000) folds the ONE envelope that crossed it, which ships `flame` and `folded` plus `FOLD_KEEP_HEAD` (10) head entries and `FOLD_KEEP_TAIL` (10) tail entries rejoined around `entries (aggregated)`. Between that marker and the tail sits the unbounded `keep` bucket — the lines a producer marked `keep`, and the `(complete)` of every span the kept head left open — so a folded record legitimately carries more than twenty rows. `entry_budget` (50,000) counts entries across everything in flight and folds the largest envelope when the pressure check crosses it. `MAX_STACK_DEPTH` (50 open spans) marks a request runaway instead: it stays visible in the in-flight view and stores no further entries, then leaves on the ordinary bucket rotation.

**`INFO: duplicate message: expected #N, got #N-1`.** `Request_Builder_Node` validates a per-request sequence number and drops any line it has already counted, so the warning names a record the reader delivered twice. Suspect the `Deferred_Clean_Stop` bracket first — a forward in `Request_Builder_Node::fill()` or `Flame_Builder_Node::fill()` running outside `deferring()`, or outside `guarded()`. Either lets a cooperative stop unwind while the record's downstream write has already landed, so the consumer's cursor commits short of it and the successor replays it. A stop carrying a failure is replayed on purpose, and the warning then says the replay reached a record this node had already counted, which is the idempotence doing its job. `guarded()` is what defers the stop; `deferring()` around it is what makes the deferral per-message.

**Rules rank by shape, not by length.** `Rule_Matcher` ranks query-bearing patterns above exact patterns above prefixes, and length breaks ties only WITHIN a rank, case-insensitively. A `/` rule never overrides an exact skip whatever the list order.

**A rule's hook list shrank on its own.** Auto-tune wrote it. `Flame_Builder_Node` decides which hooks and custom events have passed a rule's `auto_disable_threshold` (occurrences per request) or earned promotion past its `auto_protect_time_threshold` (mean ms per call), and its owned `flame-builder:auto-tuner` sibling edits the one rule named by `rule_id` and saves the whole ruleset through `Rule_Set::save()`. Both thresholds default to 0, which is off, so a rule that never set one is never edited. A hook the rule also lists under `significant_events` is protected and survives however noisy it got. `request flame-builder GET_STATS` reports `auto_tune_pending_count`, the decisions accumulated but not yet emitted.

**Worker positions or consumer lag look stale.** Positions come from the `topicprobe.p0` partition, which `Topic_Probe_Node` sweeps into on the cadence `topic-probe.tsl` declares (15 seconds) — never from memcache. A record exists only while a worker is running to write one, so `Topic_Probe_Node::stale_after_s()` (two missed sweeps, so 30 seconds) judges liveness by age, and a stale row has its lag recomputed off disk. Stale rows therefore mean the writer is gone, not that the cache is cold.

**Job handler appears not to fire.** Register on the right filter: `newspack_nodes/job_handlers` for local dispatch of `k:"job"` on every node, `newspack_nodes/remote_job_handlers` for hub-side dispatch of spoke-aggregated `k:"remote_job"`. The two are independent registrations and the wrong one is a silent miss. Then check the Job Router's ingress — `firehose:consumer` carries small jobs with the body nested under `m`, `jobintake:consumer` and `jobfeed:consumer` carry large ones flat. A large job sent through `Log_Manager` is truncated at `MAX_DATA_SIZE` (3840 bytes, headroom under PIPE_BUF so a lock-free append stays atomic): `fit_data()` marks the entry `truncated => true` and trims a string `m` or drops an array one, leaving the keyword `k` untouched so the span still opens. Nothing is written to `error_log` — the entry itself is the notice. The stripped body then reaches the router carrying no handler and reads there as an invalid-handler warning. Use `\Newspack_Nodes\Job_Intake::queue()` instead. The router itself holds no size gate and no age gate: `Age_Sieve` between it and `jobs:partition` owns staleness, and both job topologies declare it at 900 seconds with the rate-limited drop warning on. A sieve drop is invisible to every counter — `Age_Sieve_Node::fill()` returns before `parent::fill()`, which is where the count increments, so `jobs:sieve`'s `ls -c` figure counts what it FORWARDED and nothing anywhere counts what it dropped: no dead letter, no alert row. The warning is the only trace, and with `should_warn` on each drop calls `print_less_often( "WARNING: age > 900 - dropping messages" )`, whose throttle key is that fixed text — so it prints once per node per log window and carries no count. Read it back with `dmesg` in the worker's REPL, off the `newspack_nodes/stderr` action, or in `error_log()`. A `jobs:sieve` count frozen under a climbing Job Router count is a sieve dropping everything. A job queued with `delay` or `not_before` never reaches that path until it is due: `Job_Intake` parks it in `jobdelay.p0`, and only `Job_Delay::sweep()` — hooked on `newspack_nodes/periodic` — delivers the due entries into `jobintake` and circulates the rest, so a stalled cron holds every delayed job.

**Settings sync silently doing nothing.** Fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology. An option change always records a settings event: `Settings_Event_Writer` appends it to `settings.p0`, which `settings:consumer` tails. Nothing fans it out unless `hub-control` is live AND the spoke has per-spoke `HTTP_Out` egress. `hub-control` ships that egress as `settings`, a `Vault_Group` over Vault group `spoke`, whose `settings:<id>` children ARE the egress — that IS the structural gate. If the sync didn't fire, the producer ran fine: check `wp nodes status` for a live `hub-control.p0` and confirm the spoke's child exists. The quickest tell that one spoke is missing out is on the hub itself: a record from that spoke whose `rule_id` is foreign to the hub's ruleset. Compare the record's `rule_id` with the ids `rules dump` (MCP `dump_rules`) returns; a spoke still logging under a rule the hub no longer holds is not getting settings, and the usual cause is the spoke missing from Vault group `spoke`. `settings-sync` fans out itself rather than through a Tee, because re-addressing a signed command after the mint makes it verify nowhere.

**Cache warmer.** The refresh-ahead cache warmer is its own plugin, `newspack-cache-cozy`. Debug it from that repo.

## Inspecting on disk

`{base_dir}` is `\Newspack_Nodes\Config::get_base_directory()`, which resolves the substrate's `base_directory` key through the usual layers — schema default `/tmp/newspack-nodes`, then each config file, then the stored option. Neither eve install takes the schema default: `eve-pyrobase1-1` runs `/volumes/pyrobase/tmp/newspack-nodes` and the gyropyro install in `eve-gyrobase1-1` runs `/volumes/gyropyro/tmp/newspack-nodes`, so read the resolved path off `wp nodes doctor` rather than assuming one. Every partition is a directory named `<log>.p<N>` — the flat partition-in-name layout — holding numbered `<segment_id>.log` segments.

```bash
# The Log_Manager firehose. Lines are 7-element positional Message envelopes;
# VALUE (index 6) is the entry hash, KEY (index 5) the request id. Segment ids
# climb and old segments are reaped, so read the newest rather than `0.log`.
tail -c 800 "$(ls -t {base_dir}/logs/firehose.p0/*.log | head -1)"

# Request_Builder output. Assembled requests, with a sibling `<segment_id>.idx`
# next to each `<segment_id>.log` (the `request-index` formatter).
ls -la {base_dir}/logs/requests.p0/

# Its three side channels: fleet alerts, error/warning/stderr lines, and the
# Gyroscope feed. That last one carries both halves of the live view — the
# hidden Request_Flight sibling's in-flight snapshots, and each finished
# request through completed:tee, which is what retires a row.
ls -la {base_dir}/logs/alerts.p0/ {base_dir}/logs/errors.p0/ {base_dir}/logs/gyroscope.p0/

# The Tee's other leg: compact per-request summaries driving the Request Log.
ls -la {base_dir}/logs/completed.p0/

# Flame_Builder output: the flame trees backing the drilldown, indexed with a
# sibling `.idx` (`flame-index`), and the stats Tables' SQLite files.
ls -la {base_dir}/logs/flames.p0/ {base_dir}/tables/

# Job ingress, the delayed-job park, and the routed queue. jobdelay is the
# fixed single partition every enqueuer writes a not-yet-due job to.
ls -la {base_dir}/logs/jobintake.p0/ {base_dir}/logs/jobfeed.p0/ \
       {base_dir}/logs/jobdelay.p0/ {base_dir}/logs/jobs.p0/

# Substrate-owned, but read constantly while debugging this plugin: consumer
# positions, recorded settings events, and the Job_Probe's dispatch stats.
ls -la {base_dir}/logs/topicprobe.p0/ {base_dir}/logs/settings.p0/ {base_dir}/logs/jobstats.p0/
```

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow.
- `event-logger-nodes-review` — application contract checklist.
- `nodes-debugging` (in newspack-nodes) — substrate REPL, worker health, log layout.
