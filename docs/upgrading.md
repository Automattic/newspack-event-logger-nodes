# Upgrading

Breaking changes that affect a consumer of this plugin — a dashboard built on its service CIs, an MCP client, a topology, a sibling plugin logging through `Log_Manager` — with the fix beside each. Start at your installed version and apply everything above it. Internal refactors and fixes are not listed; [CHANGELOG.md](../CHANGELOG.md) has the full story per release.

**Maintenance rule:** a release that changes any consumer-facing contract adds its entry here in the same commit as its CHANGELOG entry. No entry means nothing to do.

## Unreleased

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

- **A flame builder names its three stats Tables before `configure_stats`.**
  A user-dir or console-saved topology carrying
  `command_node flame-builder:config configure_stats` without the three
  `set_*_target` lines fails to load, `configure_stats: no Table named by
  set_aggregate_target, set_url_target, set_url_fine_target`.
  Add, ahead of it, `set_aggregate_target flame-stats:aggregate`,
  `set_url_target flame-stats:url` and `set_url_fine_target
  flame-stats:url-fine`, as `flame-builder.tsl` does. Each verb refuses any
  other name.

- **`provisional` on a `urls` reply means more.** It marks a reply short of
  an hour or record the writer has yet to fold or rank, or of an index read
  that went unanswered, not only the latest buckets' ranking. A replay can
  hold many hours unfolded at once, and each adds nothing until it folds,
  so a client treating a provisional page's `totals` as the site's reads a
  replay's missing hours as no traffic. The dashboard banner says so.

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
  worker.

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
