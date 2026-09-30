# Upgrading

Breaking changes that affect a consumer of this plugin — a dashboard built on its service CIs, an MCP client, a topology, a sibling plugin logging through `Log_Manager` — with the fix beside each. Start at your installed version and apply everything above it. Internal refactors and fixes are not listed; [CHANGELOG.md](../CHANGELOG.md) has the full story per release.

**Maintenance rule:** a release that changes any consumer-facing contract adds its entry here in the same commit as its CHANGELOG entry. No entry means nothing to do.

## Unreleased

- **The stats move to Ledgers: flush them after deploying, and read
  `urls` without `ranked` or `estimated`.** This release needs
  newspack-nodes 2.80.0 for its Ledgers; below it the plugin stays dormant
  behind its admin notice. Nothing migrates the memcache-era keys: run
  `wp nodes tables flush stats:totals stats:dims stats:categories stats:leaderboard stats:url-rows stats:url-dims stats:url-cats stats:names stats:search flame-stats:url`
  (decision 5). The `flame-stats:aggregate.p{N}` and
  `flame-stats:url-fine.p{N}` SQLite files, and their `-wal` and `-shm`,
  under the runtime's `tables/` directory are declared and read by nothing:
  delete them once the workers have restarted. The dashboards fill again
  from the first settle. A `urls` client drops `ranked` and
  `estimated`, which the reply no longer carries, and reads `provisional`
  as a stats read that went unanswered. An untimed URL's `min_ms` and
  `max_ms` are null, where they were 0, in a `urls` or `dump_url` row and in
  every `ask` brief; a client renders null as unmeasured. Such a URL ranks
  last on `avg_ms`, `min_ms` and `max_ms` in either order, searched or not.
  A `flame-builder` topology of your own names each Ledger it writes with
  `add_ledger_target`, as the shipped one does, or `configure_stats`
  refuses it.

- **`errors_only` counts a URL only in the key and bucket in which it
  errored.** A key is one server's reader or worker traffic, so with
  `include_workers` a URL that errs as a reader and runs clean as a worker
  in the same bucket counts its reader traffic alone, where it counted both.
  A `urls` page, the `performance_urls` tool and an `overview:` brief under
  the filter read lower for such a URL; a client wanting its whole traffic
  in that bucket reads the page unfiltered.

- **`dump_url` tails by `--after`, and `--since` is gone.** A caller sending
  `--since=<epoch>` is refused `unknown option --since`. Send
  `--after`, the reply's new `positions` object sent back unchanged, or no
  `--after` for the whole window. A partition absent from `positions` keeps
  the position the caller sent. Do not build the cursor from the rows: a walk
  the budget cut returns its newest rows and leaves older lines unread. `requests` is the 500 that finished last
  across every partition, sorted by completion rather than start, and
  `scan_stopped_early` is true whenever a partition held more than 500 in the
  window, so a busy URL carries it from its first read. `aggregate_flame`,
  `aggregate_profiles` and `last_modified` describe every partition's traffic
  summed, where they were partition 0's; a client deduplicating on
  `last_modified` alone drops replies carrying new requests.

- **Flush the stats Tables after deploying: the URL row gained a field.**
  `Stats_Store::ROW_ERRORS` sits at index 8, so `ROW_MIN_MS` through
  `ROW_PATH` moved one along, to 9 through 14, and a row written before
  reads misaligned. Run
  `wp nodes tables flush flame-stats:aggregate flame-stats:url flame-stats:url-fine`
  (decision 5); the URL table and its ranked lists refill from the next
  flushes. Header records moved to `HDR_VERSION` 5, `urlhdr:{bucket}:v5p14:…`,
  so a v4 record reads as missing. A PHP caller reading a stored row
  indexes it through the `ROW_*` constants, never a literal.

- **`errors` counts timeouts and fatals.** Every `urls` row carries it; the
  totals carry it under `errors_only`, as before. It was the requests no
  status class counted, which left out every fatal (a 500) and counted every
  CLI worker request (status 0); a client comparing `errors` across the
  upgrade sees fatal-heavy URLs rise and worker URLs fall.

- **This release needs newspack-nodes 2.79.1.** The request builder times
  requests out on its stream clock through the owner clock
  `LRU_Cache::with_timed_rotation()` takes there; below it the plugin stays
  dormant behind its admin notice. Update the substrate first, then this
  plugin, then restart the workers. A request timed out mid-replay is now
  measured to the stream: its `duration_ms` is the newest entry stamp the
  builder had read less its start, where it was the wall less its start, and
  it files among the replayed stamps rather than in the wall's bucket.

- **With newspack-nodes 2.79.1 a `:config` verb on `Request_Builder` or
  `Flame_Builder` demands MANAGE.** A script or hub that sends one to a worker
  under a READ or TUNE session is refused `permission denied: manage capability
  required`; send it under a manage session or the site's own signature. A
  service CI verb refused by role no longer appears as a dispatch span.

- **With newspack-nodes 2.79.0 the flame stats start empty once, and a
  salt rotation no longer resets them.** A durable Table's key carries no
  salt any more: it stores `{namespace}:{key}`. The rows written under the
  old salted key are orphaned, read by nothing, and the Router's purge
  reclaims them once their TTL passes, so the dashboards fill again from
  the first flush after the upgrade. From then on `wp nodes memcache flush`
  reaches memcached and APCu alone. A stats reset, and the migration of a
  stats schema change, is
  `wp nodes tables flush flame-stats:aggregate flame-stats:url flame-stats:url-fine`, which
  replaces each partition's SQLite files however many rows they hold.
  `wp nodes tables list` names every declared Table. Command sessions moved
  to a wpdb Table in the same substrate release, so a salt rotation no
  longer revokes them either; flushing `nodes-sessions` by name does.

- **The search index moves to six-hour buckets; nothing migrates.**
  `urltoken:{server_key}:{word}` became
  `urltoken:{bucket}:{server_key}:{word}`. The unbucketed member rows are
  read by nothing and age out on their own lifetime, the retention window
  from their last add, and the Table's purge reclaims them. A URL is
  searchable again once a flush has filed it under the new keys: the first
  flush that sees it after the upgrade, since a blob written before carries
  no `filed` stamp and reads as never filed. A client reading the sets
  directly with `SMEMBERS` names the bucket, `Y-m-d-H` at 00, 06, 12 or 18
  UTC, after the namespace.

- **Release newspack-nodes 2.77.0, this plugin's 0.111.0 and
  newspack-intelligence 0.12.0 together, and deploy them together.** The
  substrate now binds every verb's arguments against the `args` its schema
  declares and hands the handler them by name, and every handler here reads
  them by name, so no order of updating one plugin at a time works:
  - nodes alone — 0.110.0's `performance` verbs (`overview`, `urls`,
    `dump_url`, `url_breakdown`, `search_requests`, `grep_requests`,
    `dump_request`, `ask`, `set`) and `rules delete` answer
    `Call to undefined method Newspack_Nodes\Command_Args::parse()` or
    `Service_CI_Node::require_option_int()` as a TM_ERROR, and
    `request-builder:config set_inflight_target` reads no argument, so the
    gyroscope's in-flight snapshots stop.
  - this plugin alone — its 2.77.0 substrate floor fails
    against nodes 2.76.0, and it stays dormant behind its admin notice, its
    verbs and workers down.

  Put all three zips on the host, then restart the workers once.

- **Every verb argument binds by position or by name, and the binder
  refuses what the verb does not declare.** A client may name any arg,
  `--hash=<h>` as well as `<h>`. An option a verb does not declare, a token
  past the last arg, a missing or blank required arg and a `bool` outside
  `1/true/yes/on/0/false/no/off` are refused where they were ignored or
  coerced, as `unknown option --<name>; this verb takes --<a>, --<b>`,
  `too many arguments: <n> given, <m> accepted`, `missing required
  argument: <name>` and `<name> wants a bool: …`; a malformed `--limit`,
  `--partition` or `--since` reads `<name> wants a whole number, got
  '<token>'`. `rid required`, `pattern required` for a missing pattern,
  `descriptor required`, `option required`, `id required` and `usage:
  configure_stats` are gone; match the binder's wording. An MCP tool call
  naming an argument its verb does not declare answers a tool error.

- **`ask` declares `context` second.** Its args are `descriptor`, `context`
  (every descriptor after the first, or `--context=` repeated), `server`,
  `search`, `errors_only` and `include_workers`; a caller that named its
  scope, as the dashboard and the MCP door do, needs nothing. `set` takes
  its `value` optional, so a blank value turns a bool option off, which is
  how a hub pushes `false`; a bool option reads `1/true/yes/on/0/false/no/off`
  and refuses any other word as `invalid value for option`, where every
  word but `0` turned it on, and `set <option>` with no value at all is
  refused, `value required`. The four `request-builder:config` target
  setters take their target optional, and a blank still stops the output;
  `set_inflight_target` answers `ok` with a newline, as its siblings do.
  An MCP argument given as a list rides as one `--<name>=` token a member,
  and a map or a null answers a tool error where it was dropped.

- **Every stats dashboard starts empty after the upgrade.** Every
  time-keyed stats key moved: the bucket or hour now follows the namespace
  (`urls:{bucket}:{server_key}:{shard}`, `url_dim_h:{Y-m-d-H}:{hash}`), and
  nothing reads the keys earlier releases wrote. The charts, including a
  URL's per-dimension charts, the leaderboards and the URL table refill from
  the next flushes: a chart over the following 24 hours, the URL table over
  the retention window. The old rows age out on their Table's TTL. Until
  each flame builder's roll-up has folded the window's earlier hours, two a
  flush, a ranked `urls` page and its header read `provisional` and go
  uncached; that takes seven flushes at the default 12-hour window,
  under a minute on a builder with traffic. A PHP
  caller composing a key by hand spells it through
  `Stats_Store::key_at( $parts, $bucket )`, never `key( ...$parts, $bucket )`.

- **`Stats_Store::url_dim_parts()` takes the hash alone.** A URL-hour is one
  row holding every dimension's slots under the dimension's name. Read one
  dimension with `get_slots( Stats_Store::url_dim_parts( $hash ), $hours,
  $dimension )`.

- **A header record's URL sketch is stored deflated.** Its layout is
  `HDR_VERSION` 4, which also carries `HDR_ERRORS`, and a record in the v3
  layout reads as missing. A PHP caller of `Url_Sketch` gets the deflated
  form from `of()` and `union()`: compare sketches as returned, and test a
  stored value with `Url_Sketch::is_sketch()`, never by its length.

- **`errors_only` counts the buckets where a URL errored, not its window.**
  A `urls` page, the `performance_urls` tool and an `overview:` brief under
  the filter show, for each URL, the traffic of the five-minute buckets
  (and, behind the current hour, the hours) in which it had a timeout or
  fatal. A URL's `count`, `avg_ms` and the totals no longer include its clean
  traffic, so they read lower than before for a URL that errs now and then;
  `errors` is unchanged. A client that took `count` under the filter for
  the URL's whole traffic reads it unfiltered instead.

- **A row with no timed request ranks at 0 on `avg_ms`, `min_ms` and
  `max_ms`** on a ranked page, as a folded page always ranked it. A client
  expecting a ranked timing sort to omit timeout-only URLs filters on
  `timed_count` itself.

- **`Stats_Store`'s ranking API changed.** `ranked_writes()` takes
  `server => shard => rows`, not `server => rows`: pass each server's shard
  maps as the index stores them. `url_rank_parts()` and `url_header_parts()`
  take ONE list set, one of `Stats_Store::RANK_SETS`, as their last key
  argument; the default, `[]`, is the reader set. `url_rank_window()` and
  `url_headers()` take a LIST of sets, `Stats_Store::rank_sets()`'s shape,
  defaulting to `[ [] ]`, and read them all in one exchange:
  `url_rank_window()` still answers `[ key, entries ]` pairs, each key's sets
  end to end, while `url_headers()` answers `key => set position => record`
  where it answered `key => record`. Pass `[ [ 'w' ] ]`, never `[ 'w' ]`, for
  the worker set alone. `Performance_CI_Node::$load_index` and
  `load_index_default()` take a fifth argument, `bool $errored`: a
  replacement seam forwards it.

## 0.110.0

- **The substrate floor is newspack-nodes 2.76.0, and search starts from an
  empty index.** Deploy the substrate first, then restart the workers, so
  each flame builder's SQLite file gains its members table. The word sets
  earlier releases wrote are no longer read and age out on
  `<eln:stats_ttl>`. A restarted flame builder files every URL it sees
  afresh, so a URL with traffic is searchable after its first flush; one
  with no traffic since the upgrade is found once its name is filed again.

- **The search index's PHP API changed.** `Stats_Store::merge_token_set()`,
  `holds_expired()` and `TOKEN_SATURATED` are gone; file words through
  `Stats_Store::add_url_tokens( [ [ server_key, word, hashes ] ], $now )`.
  `url_token_sets()` reads at most `Stats_Store::SEARCH_WORDS_READ` (3)
  words and throws a `LogicException` past it: read a term through
  `Stats_Store::search_groups()`, one group at a time.

- **`Flame_Builder_Node::roll_up_hours()` takes the hours it may fold.** Its
  third parameter is a list of hour keys where it took the tick: pass
  `$plan['hours']` to fold every hour of the read plan, as an idle builder
  does.

- **A span that ends on a throwable carries its message.** Its `m` reads
  `Class: message` where it read the short class alone, so a client
  matching `m` against a bare class name matches the prefix before `: `
  instead. A throwable with an empty message still reads the bare class,
  and `Worker_Should_Stop` still reads `stop`.

- **`provisional` on a `urls` reply means more.** It marks a reply short of
  an hour or record the writer has yet to fold or rank, or of an index read
  that went unanswered, not only the latest buckets' ranking. A replay can
  hold many hours unfolded at once, and each adds nothing until it folds,
  so a client treating a provisional page's `totals` as the site's reads a
  replay's missing hours as no traffic. The dashboard banner says so.

## 0.109.1

- **A flame builder names its three stats Tables before `configure_stats`.**
  A user-dir or console-saved topology carrying
  `command_node flame-builder:config configure_stats` without the three
  `set_*_target` lines fails to load, `configure_stats: no Table named by
  set_aggregate_target, set_url_target, set_url_fine_target`.
  Add, ahead of it, `set_aggregate_target flame-stats:aggregate`,
  `set_url_target flame-stats:url` and `set_url_fine_target
  flame-stats:url-fine`, as `flame-builder.tsl` does. Each verb refuses any
  other name.

## 0.109.0

- **The substrate floor is newspack-nodes 2.75.0, and the flame builder's host
  needs `pdo_sqlite`.** The stats live in three SQLite Tables
  `flame-builder.tsl` declares; without the extension `flame-builder`,
  `performance` and `complete` refuse to load, naming the Table that cannot
  open, and the `performance` verbs answer no stats.

- **Stats start empty, and no flush is required.** The Tables are new
  files, so nothing carries memcache's stats over, and they fill as traffic
  arrives. Nothing reads the old memcache stats keys, and memcache reclaims
  them by TTL or eviction. `wp nodes memcache flush` deletes nothing: it
  rotates the salt, which orphans every memcache key, and restarts every
  worker. It no longer touches the stats at all; `wp nodes tables flush`
  empties them.

- **The stats mirror is gone.** A user-dir or console-saved topology that
  still names `set_stats_target` or `set_flame_topn` fails to load on the
  unknown verb, and one naming the `stats-index` formatter fails on
  `unknown formatter: stats-index`. A leftover `flame-stats:partition` node
  still loads, and so does an `<eln:stats_mirror_*>` token, which resolves to
  `''` with a rate-limited warning. Drop all of those lines. The
  `stats_mirror_*` config keys are retired, and `GET_STATS` no longer carries
  `mirror_held_frames` or `mirror_held_bytes`.

- **`configure_stats` takes no argument.** A user-dir or console-saved
  topology still carrying `command_node flame-builder:config configure_stats
  <partition>` fails to load, `usage: configure_stats`; drop the argument.
  PHP building a `Stats_Store` passes `( $max_lifespan, $client,
  $table_names )`, the map required, and reads a slotted series through
  `get_slots( $parts, $hours )`.

- **Every chart reply names 288 five-minute `slots`, and the dimensional
  series is a name table.** `overview`, `dump_url` and `url_breakdown` answer
  `slots`, the 288 `Y-m-d-H-i` bucket keys of the last 24 hours, newest
  first, where they answered `plan`; every series key falls inside them, and
  no key is an hour. `overview` no longer carries `aggregate_time_series`, or
  the `span` its rows held. `breakdowns[ $dim ]` and `breakdown_time_series`
  are `{ names, buckets }`, each row `[ nameIndex, count, sumMs, sumPeakMb,
  timed ]`, where they were `bucket => value => [ count, sumMs, sumPeakMb ]`:
  decode through `names`, and divide `sumMs` by `timed`, not `count`, for an
  average duration. `total_requests`, `global_avg_ms` and
  `global_avg_peak_mb` cover those 288 slots rather than the retention window,
  and `global_avg_peak_mb` divides by every non-worker request, timed or not.
  `global_leaderboard` gains `avg_ms`, the timed mean over its own 25 hour
  keys, which is the divisor for its categories. The `performance_overview`
  MCP tool returns the same reply.

## 0.96.1

- **An MCP tool result is fenced and JSON-HEX-escaped.** `tools/call` answers
  with the reply encoded as
  `wp_json_encode( $reply, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )` wrapped in
  `<site-data>` … `</site-data>`, because a tool returns recorded site traffic
  and part of it is written by the site's visitors. A client that
  `json_decode`s the `text` block strips the fence first — the first and last
  lines — and a client that read a verb's string reply as plain text reads a
  JSON string. Every `<` and `>` in the payload arrives as `\u003C` / `\u003E`,
  which is what stops a payload closing the fence. An `isError` result is the
  site's own message and is not fenced. `initialize`'s `instructions` field
  explains the tag ahead of the measurement caveat.

- **A brief's `environment_v3` entry has no body, and a request brief omits
  the row.** `Ask_Assembler::entry_shape()` returns `m` as `''` for that
  category, and `for_request()` drops the row before it counts entries, so a
  `performance_ask` reply or an "Ask Claude" payload never carries the
  visitor's headers or the peer address. A reader wanting those facts reads
  the brief's `env` field, which carries the allowlisted six, or the record
  whole through `dump_request`, inside the fence.

- **The request id comes from `UNIQUE_ID` or is generated, never from a
  request header.** A client can send `X-A8C-Request-Id`, and the id is every
  firehose line's `Message::KEY` — the identity requests are grouped by and the
  input to the partition hash — so a header-sourced id let a visitor file lines
  under another request's id and pick their partition. A caller that read the
  edge's id out of `Log_Manager::instance()->get_request_id()` reads it from the
  request's `environment_v3` entry instead, which carries
  `HTTP_X_A8C_REQUEST_ID` verbatim: `wp nodes reqgrep <edge id>` finds the
  request through that line. A job context carries no edge id: `begin_job_context()` clears the
  header, because the worker's own spawn request is not the job's.

## 0.96.0

- **The substrate floor RISES with this release.** The dashboards send the
  substrate's renamed verbs (`raw-logs dump_log`, `workers dump_cleanup`,
  `aggregator list_servers`, `topologies dump`), and the current-request tab
  registers on the substrate's `newspack_nodes/station_tab_bundles` filter and
  reads `Admin::overlay_pages()`, so this plugin needs the substrate release
  that carries them; [the loader's version floor](../newspack-event-logger-nodes.php)
  names it, and below it the plugin goes dormant behind an admin notice rather
  than rendering rails that every fetch refuses. Fix: update `newspack-nodes`
  first.

- **`rules list` is RENAMED to [`rules dump`](API.md#rules--per-url-logging-ruleset-crud), and the MCP tool `rules_list` to
  `dump_rules`.** The substrate's own vocabulary is the rule: `list_nodes`
  prints one row per node and `dump_node` prints a node's whole structure, and
  every rule in the reply carries its resolved hooks, so the read is a dump.
  The old verb is refused as `unknown command: list`, with no alias, and the
  old tool name is absent from `tools/list`. A caller sending
  `command( 'list', [] )` to the `rules` CI, or
  `useCommandOnce( { ci: 'rules', command: 'list' } )`, changes the verb to
  `'dump'`; [`useRulesGraph`](../src/rules/useRulesGraph.js) returns the re-read callback as `dump()` rather
  than `list()`; an MCP client calls `dump_rules`.

- **Five `performance` verbs are RENAMED verb first: `request_search` is
  `search_requests`, `request_grep` is `grep_requests`, `request_detail` is
  `dump_request`, `url_detail` is `dump_url`, and `hooks_registered` is
  `list_hooks`.** The substrate's builtins set the grammar (`list_nodes`,
  `dump_node`, `make_node`, `set_sink`), and a query with no verb is a plain
  noun (`overview`, `urls`, `summary`); a name with the noun first, or two
  nouns and no verb, reads as neither. Each old name is refused as
  `unknown command: <name>`, with no alias. An `addSliceFetcher` or
  `useCommandOnce` aimed at the [`performance`](API.md#performance--the-omnibus-dashboard-ci) CI changes its `command` to the
  new spelling, and a `scope` named after the verb follows it; a
  `formatCommandArgs` call naming the verb changes the same way. The four MCP
  tools wrapping the first four take the verb's name — `search_requests`,
  `grep_requests`, `dump_request` and `dump_url` replace
  `performance_request_search`, `performance_request_grep`,
  `performance_request_detail` and `performance_url_detail` — and the old
  names are absent from `tools/list`. `performance_overview`,
  `performance_urls` and `performance_ask` keep the interpreter prefix,
  because their verbs are plain nouns. The substrate renames `workers
  cleanup_status`, `aggregator servers_status` and `raw-logs log_status` in the
  same pass; its [`docs/upgrading.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/upgrading.md) lists them.
