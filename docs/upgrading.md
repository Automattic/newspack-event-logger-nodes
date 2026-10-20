# Upgrading

Breaking changes that affect a consumer of this plugin — a dashboard built on its service CIs, an MCP client, a topology, a sibling plugin logging through `Log_Manager` — with the fix beside each. Start at your installed version and apply everything above it. Internal refactors and fixes are not listed; [CHANGELOG.md](../CHANGELOG.md) has the full story per release.

**Maintenance rule:** a release that changes any consumer-facing contract adds its entry here in the same commit as its CHANGELOG entry. No entry means nothing to do.

## Unreleased

- **`urls` and the MCP `performance_urls` tool list pages, not URLs.** A
  page is a URL's lowercased host and path, every query key folded, and a
  row is one page on one server, folding every URL of it. A row carries
  `page_hash` (`Log_Manager::url_hash()` of the page), `page`, `server`,
  `path` (the page under its server), `shapes` (the URLs folded) and `url`,
  then the measures a URL row carried. `url` is now the name the row shows,
  not one URL's: a one-shape page's URL as its own row names it, a page of
  more `https://` and the page, so never read a page row's `url` as a URL
  `dump_url` takes; it is a display name. `hash` is the
  URL's hash only where the page folds one shape; on a page of more it is
  null, so key a row by `page_hash` and `server`, and never pass a
  `page_hash` where a URL hash goes (`dump_url`, `?url=`, `ask url:`, `url=`
  filters). A page's `worker` is true where any shape is a worker's. `rows`
  counts the pages matching, `totals.pages` is new beside `totals.urls`, the
  `url` sort orders by `page`, and `slowest` lists pages. For URL rows as
  `urls` answered them before, ask `--page=<page hash>`: it answers
  that page's URLs, each with `hash`, `url` and the measures, and `rows`
  counts them.

- **A URL's hash folds case and scheme, and a server's name is lowercase.**
  A URL row is hashed by `Url_Parts::hash()`, `Log_Manager::url_hash()` of
  `Url_Parts::identity()`, its key with any worker mark lowercased and its
  `http://` read as `https://`, so a URL's case and scheme variants are one
  row. The key itself, the record's `url` and every display keep the
  spelling and scheme the request was sent with. Each partition's row keeps
  the first it filed, and a read across partitions shows the byte-wise least
  of them, an https URL before an http one. A server is stored as its host
  lowercased (`Url_Parts::server_name()`), and every reader folds a server
  it is given the same way, so `--server=Example.com` matches; a `?server=`
  link or a script comparing server names takes them lowercased. A `?url=`
  link, a `url:` descriptor or a `url=` filter carrying a hash from 0.147.0
  or earlier names a URL spelled with capitals or over http that no row
  carries any more; open the URL from the table again for its new hash. A
  page that hashes a stored URL itself takes `urlHash()`, as the request
  stream, the in-flight view and the error log do. `Log_Manager::url_hash()`
  is unchanged, so rule ids keep their hashes.

- **An `overview:` brief lists pages as `pages`, not URLs as `urls`.** The
  `ask` verb and the MCP `performance_ask` tool answer `pages`, each with
  `page_hash`, `page`, `server`, `shapes`, `busiest_hash`, `count`,
  `avg_ms`, `max_ms` and `errors`, and its `stats` gain `pages`. It carries
  `as_of`, the snapshot's build where the snapshot answered it. An entry
  carries no `url` and no `hash`: pass `busiest_hash`, the URL hash of the
  page's one shape or of its busiest, where `hash` went, as a `url:`
  descriptor or to `dump_url`.

- **This release needs newspack-nodes 2.112.0.** The overview-prefetch job
  publishes its URL snapshot through `Row_Table_Node::build()` and the verbs
  read it through `open_snapshot()`; below 2.112.0 the plugin goes dormant
  behind its admin notice. Install the substrate first.

- **`urls`, `dump_url`, `ask` and their MCP tools refuse `errors_only`.**
  Each takes `--filter=field=value` in its place, repeatable and spelled as
  `query` spells it. `--filter=error=T --filter=error=F` keeps exactly the
  timeouts and fatals `errors_only` kept; `--filter=status_class=5` keeps
  the 5xx responses, most fatals among them, and
  `--filter=status_class=Unknown` the requests that answered no status:
  timeouts, and CLI or cron runs where workers count. A filter narrows
  `dump_url`'s stats as well as its list, so its `requests_capped` counts
  against the narrowed `stats.count`. One argument owns each field, so a
  token naming an owned field is refused, naming its owner: `server=` on all
  three, so name the server with `--server`; `bucket=` on all three, so name
  the buckets with `--bucket`; and `url=` on `dump_url` and an `ask url:`,
  whose hash names the URL. `urls` keeps `url=`, and `query` keeps all
  three, `bucket=<key>` included. `urls` and `ask` echo `filter` (the
  tokens) where they echoed `errors_only`. Every `urls` reply's `totals`
  carries `errors`, and so do an `overview:` brief's `stats` and URL rows.
  `ask` answers no `error_summary`: a `url:` brief always carries the count
  and the means of the requests its filter leaves.

- **This release needs newspack-nodes 2.111.0, and each site rebuilds its
  stats.** The stats move from the three keyed `flame-stats:*` Tables to the
  `flame-facts` Row_Table, which `flame-builder.tsl` declares with
  `make_node Row_Table flame-facts Flame_Facts`; below 2.111.0 the plugin
  stays dormant. Release the substrate first, then this plugin, then rebuild
  on each site:

  ```bash
  wp nodes stop && wp nodes deactivate performance && wp nodes gc --force && wp nodes memcache flush && wp nodes activate performance && wp nodes start
  ```

  On a site running `complete`, or another topology that includes
  `flame-builder`, name that topology in place of `performance`.
  `gc --force` deletes the old `flame-stats:*` files, which nothing declares
  once this release loads, and the reactivated readers rebuild the rows from
  the retained firehose. Every host running the flame builder needs
  `pdo_sqlite` with FTS5. Every fleet runs at most 8 partitions, down from
  16, because the substrate's `Spawn_Coordinator::MAX_PARTITIONS` clamps
  every topology, so one stats reader's attachments stay under SQLite's ten;
  a site configured for 9 to 16 partitions runs 8 and loses the workers for
  `p8` and above.

- **A copy of `flame-builder.tsl` takes the Row_Table.** Replace its three
  `make_node Table flame-stats:…` lines with `make_node Row_Table flame-facts
  Flame_Facts`, and its `set_aggregate_target`, `set_url_target`,
  `set_url_fine_target`, `configure_stats` and `set_is_hub` lines with
  `command_node flame-builder:config set_facts_target flame-facts`. The
  `<eln:stats_ttl>`, `<eln:stats_url_ttl>` and `<eln:stats_url_fine_ttl>`
  tokens are gone; `<eln:>` resolves `is_hub` alone. A flame builder's
  `GET_STATS` answers `buffered_requests` and `buffered_rows` in place of
  `stats_count`, `pending_url_count`, `intern_count`, `pending_buckets` and
  `is_hub`.

- **`overview` answers `{ totals, slots, servers, leaderboard }`.** `totals`
  carries `requests`, `timed`, `avg_ms`, `avg_peak_mb`, `errors` and `urls`
  over the chart's 288 buckets; `leaderboard` keeps its display shape. The
  `breakdown` and `categories` arguments are refused, and `total_requests`,
  `global_avg_ms`, `global_avg_peak_mb`, `global_leaderboard`, `breakdowns`
  and `category_time_series` are gone: ask `query` for a chart series.

- **`urls` answers `{ data, rows, totals, slowest, filters, limit, offset }`.**
  `provisional`, `estimated`, `ranked` and `as_of` are gone, and so are the
  `Other` rows marked `aggregate`. Every count is exact and every mean
  request-weighted, `totals.urls` and `rows` count distinct URLs, and
  `requests_per_second` divides by the seconds the selected buckets have run.

- **`dump_url` answers `{ stats, requests, requests_capped, aggregate_flame,
  flame_cut, aggregate_profiles }`.** The `breakdown`, `categories` and `after`
  arguments are refused, and `scan_stopped_early`, `requests_window_start`,
  `positions`, `last_modified`, `slots`, `breakdown_time_series` and
  `category_time_series` are gone. A listed request's `timestamp` is its
  start. A caller tailing the list with `--after` re-asks it whole instead.
  `aggregate_flame` is null under a filter or a selection, and when
  `MAX_SCAN_S` cut the rebuild, which alone answers `flame_cut` true.

- **`dump_request` reads a request through its row.** A rid no request row
  names answers `Request not found`: one aged past the seven days the rows
  keep, though its record is still in `requests.p{N}`, and one the flame
  builder has not written yet, for up to its five-second flush after the
  request finishes. The flame is read from the row's partition alone, and
  `flame_cut` true says `MAX_SCAN_S` cut that lookup, so an absent
  `flame_data` says nothing of whether the request was profiled. A
  `request:` ask reads it the same way.

- **`search_requests` answers `{ rid, url_hash }` from the request's row.**
  `partition` is gone from its answer, and a rid no row names answers
  `Request not found`, as `dump_request` does. Rows that will not open answer
  `no request <rid>: the stats rows did not open` from both. `requests.p{N}`
  keeps no index, so a copy of `request-builder.tsl` drops its
  `command_node requests:partition:config with_index request-index` line:
  the formatter is gone, and the line no longer resolves.

- **A request is named by its rid alone.** `dump_request` refuses
  `--partition` (`unknown option --partition`), and its MCP tool drops the
  argument. The `ask` verb's request descriptor is `request:<rid>`: the old
  `request:<rid>:<partition>` answers `unknown descriptor`, as the target and
  as a context. `dump_url`'s request rows and a `url:` brief's
  `worst_requests` carry no `partition`. The current-request overlay's
  `NewspackEventLoggerNodes.currentRequest` global is `{ rid, perfUrl }`, and
  `Log_Manager::get_partition()` and `Current_Request_Overlay::inline_data_js()`'s
  partition argument are gone. A client drops the partition wherever it
  sent one.

- **`url_breakdown` is gone.** Ask `query` grouped by `bucket` and the
  dimension, filtered `url=<hash>`, for the four sums `url_breakdown`
  carried, which are the dashboard's `SERIES_MEASURES`:

  ```
  query requests bucket status_class --filter=url=<hash> --measure=count --measure=sum_ms --measure=sum_peak_mb --measure=timed --top=20 --top_by=count
  ```

  `--top` keeps every bucket of the 20 busiest values. Without it the answer
  stops at 1,000 rows, ordered by its groups, so a dimension with many values
  loses its newest buckets without a word. The MCP tool `performance_query`
  fronts `query`; an MCP client counting tools sees eleven.

- **The dashboard's breakdown names are the query fields.** A link carrying
  `?breakdown=status` now breaks down by exact status code; the status
  classes are `?breakdown=status_class`.

- **Removed PHP:** `Stats_Store`, `Url_Sketch`,
  `Config::stats_retention_seconds()`, `Config::MIN_RETENTION_SECONDS`,
  `Flame_Builder_Node::merge_url_flames()`,
  `Flame_Builder_Node::index_completion_columns()` and `WRITE_BATCH_KEYS`,
  `Request_Builder_Node::index_completion_columns()` and
  `index_position_columns()`, `Flame_Facts_Row_Schema::TABLE`,
  `Flame_Builder_Node::index_column()`, `Flame_Tree::AGGREGATE_EXPIRY_SEC`,
  and the `Flame_Tree` span names
  `URL_PAGE_CACHE`, `URL_HEADER_CACHE`, `URL_FOLD`, `URL_RANK_LISTS`,
  `STATS_RANK_CLOSE`, `STATS_PROBE`, `STATS_FOLD` and `STATS_RE_RANK`. A
  reader of the stats asks `App\Stats_Query::run()` instead.
  `Ask_Assembler::for_url()` takes `( $stats, $requests, $requests_capped,
  $rule, $server, $filters )`, and `for_url_span()` and `for_url_category()`
  each take the server last. `Flame_Tree::prune_lightest( $root, $max_nodes )`
  takes a node count, and `merge_flame_children_incremental( $existing,
  $incoming )` takes no clock and stamps no `ts`. A reader of the Flame_Facts
  rows reads it as `Row_Table_Node::read( Flame_Facts_Row_Schema::class, … )`,
  so a topology may declare it under any name. `delete_stats_tables( $topologies_dir )` takes the plugin's shipped
  topologies.

- **This release needs newspack-nodes 2.110.0, which takes a broker's
  sources by `add_source`.** `aggregator.tsl` now builds its `spokes` group
  with `make_node Vault_Group spokes Remote_Source spoke <offsets root>
  <deadletter root>` and gives it each source as a line of its own,
  `command_node spokes:config add_source firehose.p{partition}
  remote-job-rewrite` and `command_node spokes:config add_source sources:php
  php-errors:partition`, where the `make_node` line carried them as
  `<source>=<target>` pairs. A copy of that topology, or a hand-written
  broker, takes the same rewrite: move each pair to an `add_source <source>
  <target>` line. The bundled stream hooks attach each view to the page
  link with `attachView( stamp, target )`. An older substrate refuses both, so the
  plugin stays dormant below 2.110.0. Deploy the two together: deploying
  the substrate first is not enough this time, because the previous release
  (0.132.x, floor 2.109.0) passes 2.110.0's handshake and then breaks on
  it — its `aggregator.tsl` still carries pairs, which the substrate reads
  as unreadable, so the hub stops pulling its spokes, and its bundled stream
  hooks call `addPairs()`, which the page's `_stream` link no longer has.

- **This release needs newspack-nodes 2.109.0, which spells a log stamp with
  `:` and a broker pair with `=`.** `aggregator.tsl` now writes
  `firehose.p{partition}=remote-job-rewrite sources:php=php-errors:partition`
  where it wrote `firehose.p{partition}:remote-job-rewrite
  sources/php:php-errors:partition`, and its `php-errors:tail` follows
  `sources:php` where it followed `sources/php`. A copy of that topology
  carrying its own pairs takes the same rewrite. An older substrate refuses
  both spellings, so the plugin stays dormant below 2.109.0. Deploy the
  substrate first.

- **This release needs newspack-nodes 2.107.0.** Its bundled Triage view
  removes a dead-letter record with `dl_remove`, which an older substrate
  refuses as an unknown verb, so the plugin stays dormant below 2.107.0.
  Deploy the substrate first.

- **This release needs newspack-nodes 2.106.0.** Its bundled topology
  console asks for an owner's sidecars through `dump_metadata -o`, which an
  older substrate answers with an empty map, so the plugin stays dormant
  below 2.106.0. Deploy the substrate first.

- **Reclaim the stats Tables with newspack-nodes 2.105.0.** A stats
  schema change is migrated by
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`,
  for each active topology that includes `flame-builder`, such as `performance` or `complete`. `wp nodes tables flush` is gone, and on
  2.104.1 or earlier `gc --force` leaves the Table files in place while it
  deletes the cursors, so the replay below folds into the old Tables and
  counts every retained request twice. The
  topology's firehose cursor goes with them, so its reactivated request builder reads the retained firehose from the start and refills the Tables with that history in the new shape; on `complete` its job router re-reads `jobintake` the same way and runs every retained job younger than the `jobs:sieve` window, 900 seconds, again.

- **This release needs newspack-nodes 2.105.0, deployed with it.** A
  logged request builds its firehose Topic with `without_config()`
  (2.105.0). The dashboards ride the substrate's shared stream link by declared pairs
  (`addPairs()`, 2.104.0) and its group Taps, and share the page global
  `Core` with the substrate's bundles, whose shape 2.102.0 changes. Below the floor the plugin stays dormant behind
  its admin notice; deploy the substrate and this plugin together, then
  restart the workers.

- **A topology writing `<partition>` fails to load; write `{partition}`.**
  The substrate resolves `{partition}` in each argument a node's schema
  marks, at the worker's partition, and the Shell refuses `<partition>`
  anywhere in a line. A custom topology built on this plugin's, such as a
  fork of `request-builder` or `flame-builder`, rewrites
  `<config:logs_dir>/requests.p<partition>` as
  `<config:logs_dir>/requests.p{partition}` and the stats Tables'
  `evlog:p<partition>` as `evlog:p{partition}`. `<topology>` is unchanged.

- **A hub is a topology declaring `var is_hub = 1`.** `<eln:is_hub>` reads
  each active topology's own top-level frontmatter, and the stock `hub` and
  `aggregator` declare it. A topology's name and the logs its
  `Remote_Source` readers pull no longer count, and an included file's
  frontmatter is skipped, so a custom hub topology that includes `hub` or
  `aggregator`, or forks either, reads as a spoke and turns its per-server
  stats off until it adds `var is_hub = 1` to its own file. Any value but
  `1` or `0` fails the flame builder's load, naming the topology. A PHP
  caller of `Log_Manager::names_firehose()` has nothing to call: the method
  is removed.

- **The hub pulls each spoke's PHP error log beside its firehose, on one
  connection.** The `firehose` group is `spokes`; each spoke's firehose
  and `sources/php` readers keep cursors under `<topology>.<id>/`, and
  `php-errors.p0` collects every spoke's PHP errors and the hub's own. Hub
  cursors under `<topology>.firehose.<id>.p<n>` are not carried over: each
  spoke's firehose reader starts from the spoke's tail. The firehose pair
  is written `firehose.p{partition}`, so on a hub activating `aggregator`
  with more than one partition each worker pulls its own firehose
  partition, while `sources/php` and the hub's own error log are read by
  worker p0 alone.

- **Every `sources/<name>` `aggregator` names must resolve where it is read.**
  The hub's `php-errors:tail` fails the load when the hub has no `php`
  source, and a spoke without one refuses the hub's subscription, so the hub
  loses that spoke's whole stream, firehose included. The built-in `php`
  needs PHP's `error_log` set to an absolute file path. Read
  `wp nodes doctor`'s `log-sources` row on each site.

- **This release needs newspack-nodes 2.98.0.** The Ask picker marks
  each element a pick stands on, and the substrate's `newspack-nodes-ui`
  stylesheet, which 2.98.0 extends, is what paints that mark. Below the
  floor the plugin stays dormant behind its admin notice. Update the
  substrate first, then this plugin, then restart the workers.

- **This release needs newspack-nodes 2.97.0.** The MCP door meters each
  session through the substrate's `Rate_Limit`, which 2.97.0 adds. Below
  the floor the plugin stays dormant behind its admin notice. Update the
  substrate first, then this plugin, then restart the workers.

- **This release needs newspack-nodes 2.94.0.** A bucket selection reads
  every URL its sets name through the substrate's
  `Table_Client::all_members()` and its `SSCAN` verb, which read a set of
  any size page by page, and no selection is refused for naming too many
  URLs. Below the floor the plugin stays dormant behind its admin notice.
  Update the substrate first, then this plugin, then restart the workers.

- **`--bucket` takes a selection, and its refusals name the key.** `urls`,
  `dump_url`, `ask` and their MCP tools read `bucket` as comma-separated
  runs of `Y-m-d-H-i` keys, `start..end` inclusive, so a single key reads
  as before. A malformed or off-grid key is refused as
  `bucket <key> must be a five-minute key, Y-m-d-H-i in UTC`, where the
  old message, `bucket must be a five-minute key, Y-m-d-H-i in UTC`, named
  no key. A client matching the old text whole no longer matches; match
  from `must be` on, which both carry. `filters.bucket` and both briefs
  echo the canonical spelling, which can differ from what was sent. A PHP
  caller of `Stats_Store::url_bucket_members()` passes a list of buckets
  where it passed one; each hash maps to server key => the hours whose buckets named it, where
  it mapped to a list of server keys.

- **`Flame_Builder_Node::roll_up_hours()` takes the tick.** Its fourth
  parameter is the flush's one read of `Core::$now`, which the hour fold
  values its bucket filings by: pass the tick the caller planned at.

- **This release needs newspack-nodes 2.93.0.** A tag token's remove ×
  takes its ink from the `--wp-components-color-*` tokens the substrate's
  `newspack-nodes-ui` style handle sets on each skin, and keeps no colour of
  its own; below 2.93.0 it vanishes on a dark skin. Below the floor the
  plugin stays dormant behind its admin notice. Update the substrate first,
  then this plugin, then restart the workers.

- **This release needs newspack-nodes 2.90.0.** Hub mode reads the
  substrate's `Topology_Analyzer::nodes_of_type()` and the `remote_partition`
  its graph names on each `Remote_Source`. Below the floor the
  plugin stays dormant behind its admin notice. Update the substrate first,
  then this plugin, then restart the workers.

- **A `Remote_Source` reading anything but the firehose no longer makes a
  site a hub.** A topology that wired one to pull another log from a peer
  was read as a hub and ran per-server stats; it now reads as a spoke. A
  hub forked from `aggregator` keeps its `firehose.p<partition>` readers
  and needs nothing.

- **This release needs newspack-nodes 2.85.0.** The flame builder's topology
  includes the substrate's `table-probe`, so `flame-stats:aggregate`,
  `flame-stats:url` and `flame-stats:url-fine` report to the station's Tables
  tab. Below the floor the plugin stays dormant behind its admin notice.
  Update the substrate first, then this plugin, then restart the workers.

- **Reclaim the stats Tables after deploying: a URL sort orders the whole
  URL.** A site `url` list (`urlrank_s`, `urlrank_sh`) stores each entry's
  whole URL where it stored the path, and a page reading a list written
  before the upgrade answers an error, `Stats_Store: no server to join
  '<path>' to`, rather than show a URL with no host. Run
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`,
  for each active topology that includes `flame-builder`, such as `performance` or `complete`
  (decision 5).

- **Reclaim the stats Tables after deploying: search and `dump_url` read a
  new namespace.** Each URL's row is also filed by key, as
  `url_row_h:{Y-m-d-H}:{server_key}:{hash}`, and `urls --search`,
  `dump_url` and `ask url:` read it there instead of a shard of the index.
  An hour written before the upgrade holds no such key, so those three read
  it as empty while the table beside them still counts it. Run
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`,
  for each active topology that includes `flame-builder`, such as `performance` or `complete`
  (decision 5); nothing reads the missing keys any other way.

- **A search too common to narrow is refused.** A term naming more than
  `URL_SEARCH_MAX` (5,000) URLs across every server searched, or whose
  every word read is that common, answers an error,
  `search "<term>" is too common: its URLs run past the 5000 a search reads; add a word`,
  where it answered zero rows or read the whole index. An MCP client or a
  dashboard showing `performance_urls` or an `overview:` brief under a
  search shows that error and asks for another word.

- **A search with no word is refused.** A term with no run of two
  characters or more, such as `a` or `/`, answers
  `search "<term>" names no word: a search needs a word of 2 characters or more`
  where it answered zero rows. Nothing walks URL names for a term any more.

- **A rid lookup's not-found is definite.** `search_requests`,
  `dump_request` and a `request:` ask walk the indexes with no time budget,
  so they no longer throw `request index scan budget spent before rid <rid>
  was reached`; `Request not found` means no partition holds the rid.

- **A capped request list is complete.** `dump_url`'s `scan_stopped_early`
  is true only when the walk spent its time budget; a list of 500 is the
  newest 500 exactly. The `url:` brief carries `requests_capped` for that
  case, since such a list is not every request since `requests_window_start`.

- **The 0.112.x Ledger stats are withdrawn; rebuild the stats Tables.**
  The stats are 0.111.2's Tables again. Run, for each active topology that
  includes `flame-builder`:
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`.
  Then delete `{base}/ledgers/` by hand; nothing reads it.

- **Every logged URL carries its host.** A web request with no
  `SERVER_NAME` never starts: `Log_Manager`'s constructor throws. A WP-CLI
  process with no `SERVER_NAME` logs under `home_url()`'s scheme and host. A request record carries
  no `server_name`; read the server off its URL's host. A record already in
  `requests.pN` whose URL has no host is dead-lettered by the flame builder.
  A request brief's `env` no longer carries `server_name`.

- **Every server is filed under its own name.** The `server` axis and the
  server index name no `Other` server, and a client withholding `Other`
  from a server list can stop. `Stats_Store::MAX_SERVER_VALUES`,
  `admit_servers()`, `refile_path()` and `dim_cap()` are gone.

- **A mean nobody measured is null.** `avg_ms` is null for a URL no timed
  request reached, in a `urls` header's totals, in `overview`'s
  `global_avg_ms` and its leaderboard's `avg_ms`, and in every `ask` brief,
  where each read 0; `avg_peak_mb` is null for a scope with no request. A
  client renders null as unmeasured. Such a URL ranks last on `avg_ms`,
  `min_ms` and `max_ms` in either order, searched or not.

- **`dump_url` tails by `--after`, and `--since` is gone.** A caller sending
  `--since=<epoch>` is refused `unknown option --since`. Send
  `--after`, the reply's new `positions` object sent back unchanged, or no
  `--after` for the whole window. A partition absent from `positions` keeps
  the position the caller sent. Do not build the cursor from the rows: a walk
  the budget cut returns its newest rows and leaves older lines unread. `requests` is the 500 that finished last
  across every partition, sorted by completion rather than start, and
  `scan_stopped_early` is true only when the walk spent its time budget; a
  list of 500 is the newest 500, not the whole window. `aggregate_flame`,
  `aggregate_profiles` and `last_modified` describe every partition's traffic
  summed, where they were partition 0's; a client deduplicating on
  `last_modified` alone drops replies carrying new requests.

- **Reclaim the stats Tables after deploying: the URL row gained a field.**
  `Stats_Store::ROW_ERRORS` sits at index 8, so `ROW_MIN_MS` through
  `ROW_PATH` moved one along, to 9 through 14, and a row written before
  reads misaligned. Run
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`,
  for each active topology that includes `flame-builder`, such as `performance` or `complete`
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
  `wp nodes stop && wp nodes deactivate <topology> && wp nodes gc --force && wp nodes memcache flush && wp nodes activate <topology> && wp nodes start`,
  for each active topology that includes `flame-builder`, such as `performance` or `complete`,
  which deletes each partition's SQLite files however many rows they hold.
  `wp nodes tables list` names every declared Table. Command sessions moved
  to a wpdb Table in the same substrate release, so a salt rotation no
  longer revokes them either; `wp nodes session revoke --all` does.

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
  worker. It no longer touches the stats at all; deactivating the topology
  and running `wp nodes gc --force` deletes them.

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
  header, because the worker's own spawn request does not belong to the job.

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
