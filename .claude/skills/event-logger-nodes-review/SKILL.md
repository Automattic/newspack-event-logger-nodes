---
name: event-logger-nodes-review
description: Code review checklist for newspack-event-logger-nodes (the application). Use whenever reviewing a diff that touches anything under newspack-event-logger-nodes/ — application Node subclasses, service CI verbs, dashboards, topologies, the logging ruleset, the memcache schema, hub/spoke routing.
argument-hint: "[file or class]"
---

# Event Logger Nodes Review Checklist

The application gates. Substrate concerns — PIPE_BUF discipline, lazy init, FROM stamping, TM_STRUCT typing — live in newspack-nodes' `nodes-review` skill and apply transitively here.

## When to Use

After any diff touching files under `newspack-event-logger-nodes/`. Run BEFORE pushing or merging.

## Gates (high-impact first)

### 1. Job_Intake for >4KB, firehose for ≤4KB

`Log_Manager::message()` fits every firehose line under `MAX_DATA_SIZE = 3840` bytes, measured on the encoded ENTRY rather than on the caller's data, because the cap has to bound what the wire carries. That is headroom under PIPE_BUF (4096), which is what keeps a lock-free append atomic against every other writer on the multi-writer log. It is a size budget for atomicity, not tidiness.

`fit_data()` handles an oversize map by setting `truncated => true` and trimming the `m` value in a loop — starting at the cap and taking 90% of the length each pass, **re-encoding every step**, because JSON escaping expands bytes and a byte-count subtraction can still land over the cap. When no string length fits, `m` is dropped outright and every other key survives, `l` and `caller` included. Only the floor drops them, reached when those keys alone still exceed the cap: it keeps `n`, `k`, `ts` and `truncated` beside a 1000-byte slice of the encoded entry. The category `k` is never renamed at any step, because a `" (truncated)"` suffix breaks `Flame_Tree::PATTERN_START` and a trimmed span would never open at all.

Large payloads belong on the substrate's `\Newspack_Nodes\Job_Intake::queue( $handler, $id, $parameters, $key = null, … )`. It auto-locks and writes through an `allow_large_writes()` Partition up to `Job_Intake::MAX_JOB_SIZE`, 32MB.

A new producer of potentially-large jobs routed through `Log_Manager` is broken: the handler never sees a parseable payload, and nothing but the entry's own `truncated` flag says so. Check whether `wp_json_encode( $payload )` can exceed 4KB; if it can, it must use `Job_Intake`.

**`Job_Router_Node` holds neither a size gate nor an age gate**, and a diff adding one there is a fourth number to keep in step with three that already bind: `Job_Intake::MAX_JOB_SIZE` at the write, `MAX_DATA_SIZE` on the firehose entry, and the Partition where the line lands. `HANDLER_NAME_PATTERN` is its only rejection of a body's own claim, and it is what stops an aggregated spoke string from reaching jobs.log as a dispatch key. Staleness belongs to the `Age_Sieve` the topology wires downstream. What the router MUST forward is every `Job_Intake::DISPATCH_FIELDS` key the body carries — `retries`, `attempt`, `batch`, `key` — because `Job_Worker_Node` reads them back to decide a retry and settle a batch, so dropping one disables both silently.

### 2. The firehose wire contract is shared with a Perl producer

`Log_Manager` and `Gyrobase::Log` (`services/gyrobase/sources/newspack-gyrobase/Gyrobase/Log.pm`) write the SAME `environment_v3` line into one firehose, so a single parser decodes both. Four values must agree, each a hand-maintained copy in its own standalone repo, and all four in `includes/class-log-manager.php`:

| Value | What must match |
|---|---|
| `ENV_ALLOWLIST` | 34 `$_SERVER` keys, membership AND order |
| `ENV_VALUE_MAX` | 256 bytes per value |
| The elision marker `log_environment()` appends to a capped value | U+2026 on both sides |
| `URL_REDACT_PATTERN` | 21 redacted query parameters |

Neither plugin can host the check — only the dndocker tree sees both producers. `tools/check-firehose-parity.py` is it, and ELN's `pre-push` runs it whenever this tree is the checkout. It also refuses an allowlisted key that reads as a secret.

Those four values are the whole of what it compares. `MAX_DATA_SIZE` (3840) is not among them and the script never reads it, because the Perl side bounds a different thing: `$MAX_LINE_SIZE` is PIPE_BUF itself, 4096, applied to the PACKED LINE rather than to the encoded entry, and its overflow path replaces `m` with a `(truncated, original N bytes)` stub instead of trimming it in a loop. Moving 3840 therefore draws no mechanical warning: reason the two producers against `Log.pm` by hand.

**Cap AFTER redaction.** Reversing the two would let a truncation expose the tail of a secret the redaction covered. A diff editing either side alone ships two producers writing different lines.

### 3. Stats fail soft, SSE slots fail closed

Two cache uses with deliberately opposite behavior:

- **`Stats_Store`**, and anything reading stats for a dashboard: every method returns `null` / `[]` / `false` when the backend is unreachable, and never throws. Dashboards display "no data" rather than erroring.
- **The SSE slot pool**: fail CLOSED. A backend outage means new connections get HTTP 429, because the pool IS the rate limit. `SSE_Slot_Pool` is a substrate class (`newspack-nodes/includes/class-sse-slot-pool.php`); the ELN-side gate is to leave its invariant alone.

Code that throws from the stats path, or swallows errors in the slot path, has them inverted, and a diff unifying the two into one error-handling style breaks whichever half it converts.

### 4. Cached state goes through the substrate's `Table_Node`

`Stats_Store` builds one `Table_Node` per retention ROLE — `aggregate`, `url`, `url_fine` — over the namespace `evlog:p{N}`, so the substrate owns key scoping and the backend handle. Reads and writes fail soft because `table()` returns null behind a `Cache_Backend::shared_first()` check rather than constructing without a store. `Rule_Set` reaches its pointer-tier hooks mirror the same way, through `Table_Node::table( TABLE_HOOKS, TABLE_TTL )`.

Push back on a diff that hardcodes a server list, constructs a second `\Memcached`, adds a cache constructor parameter, type-hints a cache interface, or adds a per-class cache field. There is no `Cache_Interface` and no `Memcached_Cache` to inject.

The one raw-handle read is the cross-worker auto-tune lock in `Flame_Builder_Node`, which needs `Memcached::add()`'s atomic claim — a primitive the Table does not expose. Anything else reaching for `Core::$memd` should be a Table.

### 5. Flushing is the substrate's one button

`Cache_Backend::rotate_salt()` moves the install scope for every Newspack plugin at once; `Stats_Store` keeps no salt of its own, and there is no `flush_all()`. `wp nodes memcache flush` and the admin's "Flush Caches" button are the two callers.

The scope is memoized per process, so a live worker keeps writing the OLD prefix until it respawns. Both callers therefore restart the workers after rotating, best-effort — a failure only delays the new scope to the next spawn. A diff that rotates and expects immediate effect without a restart is wrong.

**The rotation IS the schema migration.** Nothing compensates for skipping it: no reader or writer carries a shape probe, a version key component or a row-level legacy test. Each of those is a second migration mechanism to maintain against every future row shape, so reject one.

### 6. Memcache value caps

Per-namespace caps prevent value explosion against memcache's 1MB-per-value limit:

| Constant | Value | Scope |
|---|---|---|
| `Stats_Store::MAX_CAT_VALUES` | 50 | Distinct categories per bucket |
| `Stats_Store::MAX_DIM_VALUES` | 20 | Distinct values per global dimension bucket |
| `Stats_Store::MAX_URL_DIM_VALUES` | 10 | Distinct values per per-URL dimension bucket |
| `Stats_Store::MAX_SERVER_VALUES` | 128 | The `server` axis everywhere it is stored |
| `Flame_Builder_Node::MAX_URLS_PER_SHARD` | 2000 | Rows per `urls` shard key, overflow rows included |

The `server` axis takes 128 rather than 20 because `server_name` is `$_SERVER['SERVER_NAME']`, which under Apache's default `UseCanonicalName Off` is the CLIENT's Host header — visitor input with no ceiling. It sits far above any fleet in evidence because a cap that binds on a real fleet drops a spoke out of the picker AND out of every scoped read. `Stats_Store::dim_cap()` substitutes it.

Every cap FOLDS its tail rather than dropping it, ranked by traffic, so totals stay exact. The four bucket caps run through `cap_bucket()` into one synthetic `Stats_Store::OTHER_KEY` (`'Other'`); only the category cap passes a reserved row, holding the `total` pseudo-category clear of the ranking and restoring it after. The URL-index cap runs through `cap_url_rows()` into TWO overflow rows, `Stats_Store::other_key( $worker )` minting `OTHER_KEY` or `OTHER_WORKER_KEY`, because one row cannot answer a filter about a mixed set.

Raise a cap only against the value size it implies, and never remove the `Other` fold: memcache silently rejects a value over 1MB.

### 7. Sums, not means — and nothing measured reads a flame `value`

The leaderboard stores `count` and `sum_req_time` (`LB_SUMS`), its per-category entries `samples`, `sum_time` and `sum_count` (`LB_CAT_SUMS`), and a URL row `count`, `timed_count`, `sum_ms`, `sum_peak_mb` and the four status-class counts. Every one of them is a raw sum, so cross-bucket and cross-partition merge is exact addition. The display layer divides at read time. A diff introducing running-mean storage reopens the EMA-clamp bug raw sums exist to prevent. Aggregator code MUST sum.

The same rule bans a stored percentile: a percentile does not merge, so a fold over N buckets cannot produce the window's. `min_ms` and `max_ms` stay because they fold from `duration_ms` exactly.

**A stat times the request; a flame value is a rendering artifact.** `Flame_Tree::cover_children()`, `Flame_Fold::flatten()` and `Flame_Builder_Node::fill()` all RAISE a node's `value` so a parent covers its positioned children — correct for drawing, wrong for counting. Every stat therefore reads `$request['duration_ms']`. A diff computing an aggregate off a flame `value` reproduces the defect where a 356-second request reached `avg_ms` as 725 seconds.

### 8. A stored URL row is positional

The URL index row is a plain array read through `Stats_Store::ROW_*` — `ROW_COUNT` 0 through `ROW_SRV` 13 — because `serialize()` writes every key name into every row and this is the one namespace whose rows are counted in tens of thousands. **A bare integer literal is forbidden**; the constants are what buy back the readability the shape spends.

Three invariants a diff must keep:

- **The eight fields that ADD come first, in `URL_SRV_SUMS` order**, so one map describes both the row's summed half and its per-server split. A ninth summed field appended past index 7 falsifies that; `StatsStoreTest` pins the key sets.
- **`Performance_CI_Node::fold_index_row()` is the one storage/display boundary.** Above it the row is positional; below it the display row is named because it crosses the wire as JSON. A second copy of the translation is how the table and the modal come to disagree about one URL.
- **Every merge expands the sole-server collapse before summing.** A split of one server that served every request stores the host against `null`; `sum_fields()` skips a non-array value, so an unexpanded null deletes that host from the merge rather than erroring. `Stats_Store::collapse_sole_server()` / `expand_sole_server()` are the pair, and reader AND writer both call it.

### 9. No operator hub toggle

There is no `enable_workers` and no `enable_aggregator`; `tests/unit/RetiredConfigKeysTest.php` guards both names. Hub-mode derives from whether the `aggregator` topology — plus `hub-control` for settings and discovery fan-out — sits in the substrate's active `topologies` list. Fresh installs are spokes or standalone.

Settings fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology. `Auto_Tuner_Node` mutates the one rule its message names (`rule_id`) and persists the whole list through `Rule_Set::save()`, which records a settings event like any admin edit — no `remote_manager` job, no `suppress_sync`. Its write is gated on `authorized()`: the `NEWSPACK_NODES_WORKER_TYPE` env a worker carries, or `manage_options`. A visitor holds neither, and neither does a `wp nodes run` worker started without `--user` — its decisions are dropped. Nothing fans the settings event out unless `hub-control` is active and per-spoke `HTTP_Out` nodes are wired; **missing consumers ARE the structural gate**.

Push back on any diff introducing a hub flag — an `enable_aggregator` / `enable_workers` check, a `Hub::is_active()` helper, a polarity check around the fan-out. Each puts an operator toggle back in front of a decision the active topology set already makes.

### 10. The substrate floor is a version, not a presence check

The deferred bootstrap on `plugins_loaded` priority 11 checks `class_exists( '\Newspack_Nodes\Bootstrap' )` AND `Bootstrap::version_at_least( '2.46.0', 'Newspack Event Logger Nodes' )`. Below the floor the plugin goes dormant behind an admin notice naming both versions rather than fataling on an API that is not there; a missing substrate returns silently. `Requires Plugins: newspack-nodes` keeps the substrate active on WP 6.5+ but does not guarantee the floor, and WordPress does not order plugin updates.

A diff calling a newer substrate API must raise the floor. `scripts/check-substrate-floor.sh` audits the declared floor against every substrate API PHPStan resolves this plugin as calling, and `lint-docs.sh` rule 6 holds the prose to the loader — **a floor set too LOW is worse than none**, because the handshake passes and the plugin fatals later. Don't lower priority 11.

The floor and the release pin are different numbers: the floor says what the plugin refuses to run against, `release.yml`'s `ref:` says what CI builds against.

### 11. Don't reimplement what lives in the substrate

Each of these lives in the substrate and has no compatibility alias here. Call the substrate class:

| Concern | Substrate class | ELN keeps |
|---|---|---|
| Job execution | `Job_Worker_Node` | `Log_Manager::begin/end_job_context`, on `newspack_nodes/job_worker/{before,after}_job` |
| Large-write ingress | `Job_Intake` | The legs draining `jobintake` into `jobs`, in its own topologies |
| Hub fan-in | `Remote_Source_Node` | `Remote_Job_Rewrite_Node`, between the sources and the firehose Topic |
| Spoke credentials | `Vault`, via the substrate `vault` CI | Nothing |
| SSE rate limiting | `SSE_Slot_Pool` | Nothing |
| Bucket LRU | `LRU_Cache` | Callers that pass one in, e.g. `Reqgrep_Core` |
| Settings render / overlay / reset | `Config_System\{Settings_Renderer, Options_Overlay, Reset_Gate}` | `Settings_Schema`, the Field declarations |

Push back on a diff that adds a `Server_Registry`, an `aggregator_servers` option, a `Remote_Manager` job handler, numeric server ids, an ELN `Stream_Merger_Node`, or a second `Job_Worker_Node`.

### 12. Application Node subclasses resolve by namespace prefix

The plugin's main file registers the top-level node-class prefix through `\Newspack_Nodes\Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )`; `Command_Interpreter_Node::register_namespace` covers only the `App\` service-CI sub-namespace. `make_node Flame_Builder` then resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix, with no per-class registration to keep in sync.

A new node subclass inherits that registration free if it lives under `Newspack_Event_Logger_Nodes\` (or `App\`) and a `composer dump-autoload -o` runs. Otherwise a `.tsl` `make_node Foo foo` cannot resolve the class.

### 13. No `class_exists()` guards for in-plugin classes

`newspack-event-logger-nodes.php` requires `vendor/autoload.php` at file scope, and that composer classmap resolves every class under `includes/` on demand, so a guard around an in-plugin class can only ever be true. Push back when a diff adds one as "defensive". A class that really is missing means a stale classmap, which `composer dump-autoload -o` fixes.

Cross-deploy-unit guards stay, because the other side can be absent. The loader and `Config` guard the substrate's `Bootstrap`, `Config` and `Config_Utils`; `Current_Request_Overlay` guards the substrate's `\Newspack_Nodes\Admin\Admin`; `mu-plugins/00-newspack-profiler.php` guards `Log_Manager`, because it ships as a separate asset; and WP-CLI registration guards on `defined( 'WP_CLI' )`. Ask "is this class guaranteed loaded by our own bootstrap?" If yes, drop the guard.

### 14. Injection seams for blocking work

CLI commands and other blocking-work classes take a seam instead of calling `STDIN` / `microtime` / `sleep` inline — and the seam differs by method rather than being a uniform "resource plus cap" pair. `Reqgrep_Command::process_stdin( $stream = null )` takes only the I/O resource, defaulting to `STDIN` and draining a `Stdin_Node` to EOF with no cap; `Reqgrep_Command::follow_mode( int $max_iterations = \PHP_INT_MAX )` takes only an iteration cap. Production uses the defaults; tests pass `php://memory` streams and small caps to drive loops deterministically.

Flag a new blocking command that bakes the stream in with no injection seam and no iteration cap. It is hard to test, and the fix costs one parameter.

### 15. Type flags

Inherited from the substrate: array VALUE → `TM_STRUCT`, string VALUE → `TM_BYTESTREAM`. A consumer reading array VALUE gates on `TM_STRUCT`. Mixing them makes a consumer's own gate drop its own data.

`Log_Manager`, `Request_Builder_Node` (`emit_request` / `emit_entry` / `emit_compact_summary`), `Flame_Builder_Node` and `Job_Intake` all use `TM_STRUCT`. Hub fan-in is the substrate `Remote_Source_Node`, which forwards the remote envelope's TYPE; `Remote_Job_Rewrite_Node` reads array VALUE, gated on it being an array, and forwards in place. TM_INFO and firehose-log VALUEs stay flat strings; only the command envelope is a token array.

### 16. Index formatters receive the unpacked message array

Three named formatters are registered because TSL has no closures: `request-index` → `Request_Builder_Node::format_index_entry`, `flame-index` → `Flame_Builder_Node::format_index_entry`, `stats-index` → `Flame_Builder_Node::format_stats_index_entry`. All three take `( array $message, array $position ): ?string` and read `$message[ Message::VALUE ]` directly.

Push back on a `json_decode` inside a formatter, or a `string $line` / by-ref `$data` signature. The substrate passes the unpacked array, so a decode there operates on the wrong type. A new index leg needs a `Formatters::register()` call in the bootstrap, not an inline callable in the `.tsl`.

### 17. Per-URL logging ruleset — one write path, pattern-hash ids, empty means empty

Logging is governed per URL by a **ruleset**: an ordered list of `Rule`s, each a URL pattern with a `log` or `skip` action and — for `log` rules — its own hooks, custom events, significant events, auto-tune thresholds and diagnostic knobs. `Log_Manager` resolves ONE governing rule per request through `Rule_Matcher`. Gates:

- **Matching is by SPECIFICITY, not longest prefix.** Query-bearing patterns (`/jobs/x?job-work`) outrank ALL exact patterns (`/about?`), which outrank ALL prefixes (`/blog`); length only breaks ties within a rank. Comparison is case-insensitive, and a prefix matches by string rather than path segment, so `/wp-cron` governs `/wp-cron.php`. A diff reordering the list to change an outcome misreads the matcher — list order never sways it.
- **Every write goes through `Rule_Set::save()`** — never a raw `update_option` on `newspack_event_logger_nodes_rules`. `save()` maintains the inline↔pointer hook tiering (`INLINE_HOOK_LIMIT` = 100, the crossover `wp nodes ruleset-bench` measures; a heavier list tiers to a non-autoloaded per-rule option mirrored into the substrate `Table`) and the orphan reconcile. Bypassing it corrupts the two-tier storage. `Rule_Set::reset()` is the one path that does not save: it DELETES the option so the schema seed governs again, reconciles the orphans and signals the worker reloads itself.
- **A rule's id is `Rule_Set::id_for( $pattern )`**, the pattern's `Log_Manager::url_hash()` — one id per pattern, and a client-supplied id is ignored. Positional id minting (`generate_rule_id`, `gen_id`) breaks that, and so does letting two rules share one pattern.
- **Empty means empty.** There is no implicit `/` log-all baseline; an empty or absent ruleset logs nothing. A diff synthesizing a minimal `/` rule makes that impossible to express. A deployment wanting log-all declares a `/` log rule explicitly, as `Settings_Schema`'s seed does alongside five exact skips: the substrate's `command`, `log/stream`, `messages/stream` and `workers/spawn` routes, and `/wp-cron.php`.
- **Seven names are retired as globals, and `RetiredConfigKeysTest` guards all seven.** Declaring `log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`, `auto_disable_threshold` or `auto_protect_time_threshold` as a `Settings_Schema` Field or an option row puts one answer where the ruleset gives a URL its own. Four are `Rule` properties: `custom_events`, `significant_events`, `auto_disable_threshold` and `auto_protect_time_threshold`. The other three have no per-rule counterpart either — the pattern replaces `log_urls` and `skip_urls`, and the rule's `hooks` list replaces `log_events`. (`enable_logging`, `log_memory`, `flush_every_line`, `allowed_users` and `hook_start_priority` stay global.)
- **Discovery stages hooks, it does not write rules.** `Discovery_Collector_Node` stages spoke-reported names into the non-autoloaded `discovered_hooks` and `discovered_events` options, surfaced in the editor's picker; it never writes a rule. Three callers do, and every one goes through `save()`: the `rules` `save` / `upsert` / `delete` verbs, `Auto_Tuner_Node`, and `Rule_Set::apply_synced()` on the settings-sync receive path. A diff having discovery union-merge into a `/` rule and save the ruleset breaks "empty means empty".
- **`upsert` drops by id OR by pattern, then appends under the pattern-derived id.** `Rules_CI_Node::upsert` removes the entry whose `id` matches the incoming rule's — an edit that renames the pattern carries its OLD id, so the stale entry goes with it — and the entry whose pattern matches, which is the id-less "Log this URL" add. Collapsing that to pattern-only orphans every renamed rule.

### 18. Per-rule diagnostic knobs, and what each costs

Four flags on `Rule` gate instrumentation, and their defaults differ because their prices do:

| Flag | Default | Cost |
|---|---|---|
| `log_http` | on (absent means on) | Two `add_filter` calls per request; two entries per outbound call |
| `log_queries` | off | Two entries per QUERY, and it turns `SAVEQUERIES` on |
| `trace_hooks` | off | One shallow backtrace per hook firing, ~0.9µs |
| `trace_callers` | 0 | A formatted stack per hook firing, capped per HOOK at the number the rule names; a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT` (20) |

Three invariants a diff must keep. **The HTTP pair is deliberately unbalanced**: `WP_Http::request()` short-circuits with a bare `return $pre;` and never fires `http_api_debug`, so `http_start` binds at `PHP_INT_MAX` and opens nothing when `$preempt` is not false. **`query_end()` drains `$wpdb->queries`**, which is why `log_queries` cannot be always-on — anything else reading that array would find it empty. **Instrumentation never CONSTRUCTS the logger**: all four callbacks ask `Log_Manager::has_instance()` first, or a binding that outlives its request builds a logger inside the callback and stamps `process (start)` with the wrong moment.

A span is named for its CALLER, never for its host or its table. Both pairs set `l` from `origin_frame( 0 === $this->trace_callers )`: a true argument climbs past EVERY frame of the class that applied the filter, at `TRANSPORT_ORIGIN_DEPTH` (16) against the `ORIGIN_DEPTH` (8) a hook's own origin takes, because `wpdb::get_row()` reaches the `query` filter through `wpdb::query()` and a fixed skip would land inside `wpdb`. So they climb only while no deep chain is being recorded, and a rule buying the full backtrace pays the shallow walk instead. And a field nothing renders does not exist: `LogEntriesTable` renders `m`, the origin frame `l` and `caller`, so a new traced field needs its own reader there.

### 19. Hook-instrumentation invariants

`App\Core::wrap_callbacks()` skips any callback declaring a by-reference parameter (`callback_has_ref_param()`), so wrapping never breaks a by-reference WordPress filter. It also registers a sacrificial `hook_spacer` at `SPACER_PRIORITY` (`PHP_INT_MAX - 2`) so `hook_complete` at `PHP_INT_MAX - 1` still fires when a callback removes itself mid-run and shifts WP's pointer past it, and treats everything at or above the spacer priority as its own. A diff that wraps a by-ref callback breaks the filter it wrapped; one that removes the spacer loses the `(complete)` on every hook whose callback unhooks itself, leaving that span open for the rest of the record.

### 20. A hook-category pattern is untrusted input

`Hook_Categorizer` merges `hook_categories.json` with the `newspack_event_logger_nodes_hook_customizations` option, so a pattern can arrive from the database. `categorize()` therefore treats every one as hostile: it skips a pattern over `MAX_PATTERN_LENGTH` (100), skips and logs one carrying a nested quantifier, skips one PCRE refuses to compile, wraps each in an `\x01` delimiter so no pattern can inject its own, and drops `pcre.backtrack_limit` to 10000 for the scan, restoring it in a `finally` that survives a throw. Strip any of the five and an operator-supplied pattern becomes a ReDoS on every request that categorizes a hook.

`is_internal()` is the other half. `App\Core::bind_current_scope()` calls it before binding `hook_start` / `hook_complete`, because instrumenting our own hooks answers no "where is time going" question and binding one of the substrate's filters can loop through `Config::load_config()` while `Log_Manager` is still booting.

### 21. A sequence-break marker is missing detail, not idle time

`entries (lost)` and `entries (aggregated)` stand in for entries `Request_Builder_Node` removed — discarded on overflow, or merged by the fold. It mints them, so it owns the vocabulary as `Request_Builder_Node::SEQUENCE_BREAK_KEYS`; consumers reference that constant rather than spelling the keywords again, and the JS keeps a deliberate copy because it is a separate deploy unit.

**No consumer may reason across a marker.** `Findings::entry_gap()` skips a gap on either side of one, `Reqgrep_Command` breaks its sequence there, and `logEntryUtils` splices the merged spans back at it. What a consumer MAY read across one is whether anything closes the outermost pair — that decides framing, not duration. `logEntryUtils`' `pruneSeveredSpans()` and `Reqgrep_Command::prune_severed_spans()` are one rule in two deploy units, and a parity test pins the vocabulary they share. A diff changing one must change both.

**The fold keeps the CLOSE of any span its head left open.** `open_in_head()` records the base names the `FOLD_KEEP_HEAD` (10) entries leave open, and `closes_head_span()` routes each matching `(complete)` into the unbounded `keep` bucket instead of the rolling `FOLD_KEEP_TAIL` (10). A span opened in the head frames every row after it, so its close is structure rather than detail, and the fold knows exactly which frames it left open — unlike `is_kept()`, which refuses to guess and honours the producer's own `keep` mark. The request's own frame is exempt: `process (complete)` has to END the record, and `keep` splices in ahead of the tail.

### 22. Settings go through `Settings_Schema`

Settings live in ONE declarative `Settings_Schema` (`includes/class-settings-schema.php`), one `Field` per setting, from which the Config overlay keys, the admin register-and-render loop, and worker-restart classification (`restart_for()`) all derive. Nine application keys are declared there with their defaults: three rendered checkboxes (`enable_logging`, `log_memory`, `flush_every_line`) and six overlay-only (`allowed_users`, `rules`, `hook_start_priority`, `custom_colors`, `stats_mirror_node`, `recommended_log_events`).

A diff adding a setting adds a `Field` with its `restart:` key, never a parallel `Admin` array, and leaves reset and delete-on-blank to the substrate's `Config_System\Reset_Gate`.

**The shipped config file is a LEDGER, not a definition.** `newspack-event-logger-nodes-config.php` returns `[]` with each of the nine keys commented out beside the default the schema declares, and `ConfigSchemaTest` parses those comments back into an array and holds them to `Settings_Schema::get()->defaults()`. A `Field` added without its commented entry fails the suite. Reads resolve four layers, weakest first: the schema default, that file, the file `LOCAL_NEWSPACK_NODES_CONF` names, and a stored `newspack_event_logger_nodes_<key>` option — where PRESENCE decides, so a stored `''`, `[]` or `false` beats both files. A key the schema does not declare is reported to stderr and ignored, never thrown, because a deploy copies the operator's own file over this path and throwing at `plugins_loaded:-10001` would take wp-admin down with it.

**A `restart:` key holds node-class tokens**, never topology names: `'all'`, `[]`, or a list like `['Flame_Builder']` or `['Partition','Topic','Log']`, which `Restart_Planner` resolves to the live topologies running a matching node. The nine application Fields take only `'all'` and `[]`. `Admin::maybe_request_worker_restart()` routes every saved option through `restart_for()` with one inline exception, `stats_salt`, which is no Field and classifies against `Flame_Builder` — the node its `Stats_Store` runs in.

**Config reads fail loud.** `Config::value()` throws on a key the substrate registry does not declare. Never `?? default` a key you depend on; a renamed key should throw at the boundary.

**A `.tsl` reads config through `<eln:KEY>`**, resolved by `Config::resolve_eln_token()`; substrate keys keep `<config:KEY>`. It owns exactly three names — `is_hub`, `stats_mirror_node`, and `stats_mirror_lifetime`, DERIVED as twice `Config::stats_retention_seconds()` rather than stored, so widening the stats window widens the mirror with it. Null is the resolver's "not mine", and `Core::resolve_config_token()` reads it as unresolvable: a topology argument reaching for a fourth name becomes `''` with a rate-limited warning, and a `node_schema` argument DEFAULT reaching for one throws, since that path resolves strict. Neither hands the node a null — add the key to the resolver's own list.

### 23. Schema field names, and the palette category

`node_schema()` uses `'arguments'` for positional constructor args and `'commands'` for verb declarations. A diff reading or writing `'ctor'` or `'verbs'` is a stale port.

Every node also declares a `'category'`, and the substrate's `Classes_CI_Node` refuses three shapes when it builds the console palette: a `'Hidden'` category, an empty one, and a truthy `'hidden'` flag. The three service CIs take `'Service'`; `Auto_Tuner_Node` and `Request_Flight_Node` take `'Hidden'`, because neither is composed by hand — the auto-tuner is wired by its topology, and the flight node is a `Timer_Node` sibling the `dump_metadata` patron filter also drops from the live canvas, so its two knobs (`set_inflight_target`, `set_inflight_delta`) surface on the patron's `:config` interpreter instead. Giving either a listed category offers an operator a node they cannot usefully place.

### 24. Option names carry the prefix; the hook seams are a fixed set

Uninstall selects BY PREFIX. `includes/uninstall-cleanup.php` deletes every `newspack_event_logger_nodes_` option row and its two transient stubs, on every site of a multisite, which is what carries the ruleset's non-autoloaded `rule_hooks_*` rows off with it. An option stored under any other name survives a delete.

The plugin fires three extension points — `newspack_event_logger_nodes/settings_after_form`, `newspack_event_logger_nodes_scope_changed` and the `newspack_event_logger_nodes_custom_colors` filter — and binds ELEVEN substrate ones. `docs/API.md`'s "Consumed from the substrate" table is the list of record, with the callback each one carries:

| Substrate hook | What it carries |
|---|---|
| `newspack_nodes/declare_config_keys` | `Config::register_config_keys`, at file scope under a literal name because this plugin loads first |
| `Newspack_Nodes\Config::RESET_ACTION` | `Config::reset_local_cache` — not `reset()`, which would re-enter the substrate |
| `newspack_nodes/job_worker/before_job` (filter, four args) | The job request context, opened |
| `newspack_nodes/job_worker/after_job` (action, three args) | The job request context, closed |
| `newspack_nodes/settings_sync/value` (filter, two args) | A blank or absent value resolved to the OWNING config's default, and a pointer rule's hooks hydrated |
| `newspack_nodes/registered_log_producers` (filter) | `Log_Manager::firehose_dir_template()`, so the log GC declares the dirs the writer writes |
| `newspack_nodes/before_reconcile` / `after_reconcile` | The reconcile pass's own `/jobs/newspack-nodes` context, a shared `$entered` flag keeping the pair honest |
| `newspack_nodes/stderr` | `Diagnostics_Bridge`, feeding the Error Log |
| `newspack_nodes/request_graph_ready` | The three service CIs |
| `newspack_nodes/devtools_tab_bundles` (filter) | `Current_Request_Overlay`'s `current-request` tab bundle |

Preserve each `accepted_args` exactly when re-registering one: inflating it hands arguments to callbacks never written to receive them.

### 25. Topologies compose the substrate's stock four

Ten `.tsl` files ship in `topologies/`, and a topology's name is its filename — there is no `name:` frontmatter to write. Five are primitives (`request-builder`, `flame-builder`, `job-router`, `job-feed`, `aggregator`) and five compose them (`performance`, `job-hub`, `job-spoke`, `complete`, `hub-control`). Four substrate stock topologies arrive by `include`, and the primitives pull two: `topic-probe` into `request-builder`, `flame-builder` and `aggregator`, and `job-intake` into `job-router` and `job-feed`. The composites pull the other two — `job-worker` into `job-hub` and `job-spoke`, and `settings-sync` into `hub-control`. Four gates:

- **Job DISPATCH is never declared here.** `Job_Worker_Node` arrives with the stock `job-worker`, which `job-hub` and `job-spoke` include. A `make_node Job_Worker` in an ELN file is a second executor on the same queue.
- **Frontmatter is read from the TOP-LEVEL file only** (`Topology_Analyzer::frontmatter()` parses that file's own `var` statements and expands no `include`), which is why `hub-control` pins `num_partitions = 1` in its own body rather than in the `settings-sync` it includes. A `var` moved into an included file is silently skipped, and the topology spawns at the fleet default.
- **`job-router` and `job-feed` each `include job-intake`**, so both write `jobs:partition`. The substrate's conflict gate refuses activating the stock `job-intake` alongside either; that stock topology is the substrate-only subset, never a companion.
- **`aggregator` ships no `Remote_Source` node.** The operator wires one per spoke and partition on the console canvas, with `set_multi_writer true` and an offsetlog scoped by `<topology>`. A diff hardcoding spokes into the file turns a per-deployment fleet into a shipped constant.

### 26. A snapshot node's `fill()` brackets the deferred clean stop

`Request_Builder_Node` and `Flame_Builder_Node` both `use \Newspack_Nodes\Deferred_Clean_Stop` and forward downstream through `guarded()` — into a sink, into the stats flush, into a Partition that writes the record to disk before it honours a cooperative stop. A diff touching either `fill()` keeps `clear_pending_stop()` as its first act and `raise_pending_stop()` on every exit that can follow a forward. The deferral is per message: a stop held over from an earlier message raises at a LATER message's exit and commits the reader past a record whose write nothing forced to disk, and a dropped raise loses the clean stop altogether, so the successor replays a message the restored snapshot has already counted and logs `duplicate message: expected #N, got #N-1`. The trait is the substrate's and `nodes-review` gates it there, but these two nodes are the application's, so this checklist is the one that sees the diff.

## Service CI specifics

Every dashboard endpoint is a verb on an `App\*_CI_Node` service CI, mounted on `newspack_nodes/request_graph_ready`. The MCP server below is this plugin's only `register_rest_route` call, so a second one needs a reason a service-CI verb cannot serve. This plugin owns THREE CIs:

| CI | Verbs |
|---|---|
| `performance` | `overview`, `urls`, `url_detail`, `url_breakdown`, `request_search`, `request_grep`, `request_detail`, `ask`, `hooks_registered`, `set` |
| `rules` | `list`, `save`, `upsert`, `delete`, `reset` |
| `discovery` | `get` |

Ten more CIs are substrate-owned, mounted on the same hook by `newspack_nodes_mount_substrate_cis()`: `classes`, `layouts`, `topologies`, `raw-logs`, `vault`, `aggregator`, `settings`, `status`, `sessions` and `workers`. Spoke credentials answer on `vault`, the hook inventory on `performance.hooks_registered`, and the site-wide totals — `total_requests` and the two averages, off the `hourly` namespace — on `performance.overview`. The substrate's command protocol dispatches at `POST /wp-json/newspack-nodes/v1/command`, with SSE at `GET /wp-json/newspack-nodes/v1/messages/stream`.

- **Verb declaration**: in `node_schema()['commands']` — `name`, `capability`, `description`, `args` (per-arg `name` / `type` / `required` / optional `default`), and an inline `handler` closure. There is no `permission_callback` field.
- **The capability gate is the `capability` key, and nothing else.** `Service_CI_Node` wraps each handler in `Capabilities::require()` for the declared role — `READ` for every read verb, `rules list` and `discovery get` included; `TUNE` for the four ruleset writes and `performance set`. **No handler re-gates itself**: a hard-coded check would silently outrank its own declaration. An undeclared verb defaults to MANAGE, so leaving one off makes a hub hold an administrator credential on every spoke just to pull logs. `tests/unit/VerbRoleDeclarationsTest.php` pins every verb's role.
- **Rate limiting is at the transport, not the verb.** The substrate's `/command` endpoint applies a per-user burst over one-second buckets (`HTTP_In_Node::check_rate_limit()`, tunable through the `newspack_nodes/command_rate_limit` filter). MCP has its own per-handle burst. Don't add a per-verb limiter.
- **Error returns**: throw freely — the substrate wraps as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: …'` for argument-validation paths that must keep the canonical OK shape.
- **Output escaping**: `esc_html()` / `esc_attr()` / `esc_url()` for any string reaching HTML; `wp_json_encode()`, never raw `json_encode`, for arrays going over the wire.
- **Handler signature**: `static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array`. `$self` is typed to the base class, not the concrete CI. `$args` is a **token array** (`list<string>` argv), NOT a space-joined string: handlers parse through `Command_Args::parse( self::arg_strings( $args ) )`, or read the whole payload as one token (`self::arg_strings( $args )[0]`) for the `rules` `save` and `upsert` JSON blobs. A handler still typed `string $args`, or splitting a joined string, is a stale port.
- **A new CI verb must stay catalog-visible.** `ServiceCiHandlerGuardTest` asserts every schema verb installs as a command, that no verb warns "no callable handler" at construction, and that each CI appears in the substrate class catalog with `category === 'Service'`.

### The MCP surface

`POST /wp-json/newspack-event-logger-nodes/v1/mcp` is a JSON-RPC MCP server wrapping verbs that already exist — ten tools, each one verb, arguments passed through `Command_Args`, replies verbatim.

- **The scope is a ceiling, never a grant.** A `Bearer <handle>.<key>` names a live session; the controller becomes that session's minting user and installs `Capabilities::$session_scope`, so a manage-scoped session minted by someone who can do nothing still does nothing. `Bootstrap::fleet_gate()` runs first.
- **`tools/list` offers only what the scope covers**, and `Findings::caveat()` — the measurement caveat — rides EVERY tool description, not just the first read. A new tool that drops the caveat hands a model a number it will over-read.
- **Rate limiting is per handle**, `RATE_LIMIT_BURST` 20 per `RATE_LIMIT_WINDOW_S` 10, checked AFTER the credential so an unauthenticated flood cannot poison the transient table. MCP does not route through `/command`, so the substrate's per-user cap does not bound it.
- **A brief redacts.** `Ask_Assembler` puts every URL through `Log_Manager::redact_url()`, allowlists the environment rather than filtering it, and caps entries. A new brief field bypassing that path leaks.

## Dashboards

- **Mount through the substrate toolkits, not by hand.** `mountExospine` builds the backbone — `_command_interpreter` sinking into `_router`, plus `_shell`, `_http` and `_heartbeat` — and runs the dashboard's build callback against it, so a hook registers only its own soft view nodes and returns the teardown. Polled dashboards use `useBatchedPoll` + `addSliceFetcher`, which own the Timer, the Tee and the page-visibility gate; live-stream dashboards use `useStreamGraph`. A hook constructing its own boundary nodes duplicates that, and loses the debug overlay's Reset Graph the toolkits hand it for nothing.
- **Steer with `target` / TO, never a bespoke `sink` chain.** Everything sinks into the interpreter; the router dispatches by TO while staying bare. `scripts/lint-contract.mjs` fails the build on the ADR violations review keeps passing — a hand-rolled correlation table, a minted reply id, a parked resolver pair, a pending-reply registry, a KEY demux, a wall-clock timer grid, node-class resolution by NAME. Each of them WORKS, which is why a correctness review nods it through.
- **A view node is a slice.** `registerSliceViews` maps each `make_node` name onto a `sliceView()` declaration — the `empty` model, an optional `parse`, an optional `json` for a verb answering a JSON string, and an optional `description`. The graph drives `loading` / `clear` / `error` through each node's own `controlFrom`, never by payload shape. A reply carrying no payload keeps the slice already on screen, so a verb that answers nothing never blanks an open modal.
- **Never throw from `fill()`.** A React tree has nothing to catch it. `SliceViewNode` already coerces a TM_ERROR envelope — a bare string VALUE included — through the substrate's `errorMessage()` into the slice's `error`, so a second coercion in a view node re-implements code that already ran.
- **Hand `makeNode` the CLASS, never the name.** `CommandInterpreterNode.includeNodes` is a per-bundle static, so another bundle's interpreter cannot resolve a name registered here.
- **Preserve prior data on a partial reply.** `UrlDetailMergeNode` dedups by rid and drops a reply whose `last_modified` is unchanged; `UrlsView` list-replaces the whole rows array. A diff that wholesale-replaces the model on every reply clobbers sibling slices and breaks drilldown. `MERGED_REQUEST_LIMIT` (500) caps that accumulation and must equal `Performance_CI_Node::RECENT_REQUEST_LIMIT`, so the merge retains no more rows than one reply could carry — nothing holds the two in step at build or test time, so a diff moving the PHP constant moves this one with it.
- **No dead REPL mounts on a production dashboard.** The `_output` / `_completion` / `_uptime` / `_cwd` set serves the console tree; copy-pasted elsewhere it adds nodes competing for `_router` traffic and colliding with the debug overlay's REPL.
- **Distinguish "no data yet" from "no data".** `breakdownState( breakdownData )` resolves `pending` / `empty` / `series`, and only `series` draws. A gate that can unmount the controls traps the operator, because those dropdowns are the only way out of a dimension with no rows.
- **`@wordpress/element` for React, `@wordpress/api-fetch` for REST**, both externalised to the `wp.*` globals and pinned to the `wp-7.0` dist tag. Never bump one to close an advisory.
- **The build is esbuild**, `scripts/build.mjs` over the substrate's build kit; `npm run build` emits `build/{tree}`. Every alias resolves through `alias-map.cjs` off the single `NEWSPACK_NODES_SRC` env var.
- **Shared hooks and utils import from `@newspack-nodes/shared/*`.** There is no local `src/shared/`, and a tree importing from another dashboard tree rather than the shared alias is a layering smell. Shared SCSS is one `src/styles/base.scss` forwarding the substrate's tokens and mixins.
- **`restUrl` is localized as `rest_url()`** — the site REST root, not pre-namespaced. Components add the namespace per call.
- **A new `window.*` payload needs a declaration in `types/globals.d.ts`**, or `npm run lint:types` fails every read of it: tsc sees no such key on `Window`. That file is also where a CSS custom property in `style={{ … }}` is made legal, by widening `CSSProperties`. The alternative is an inline cast at the one reader, as `src/overview/retention.js` does for `eventLoggerDashboards`.

## Tests

- Unit tests live under `tests/unit/`, mostly flat, with `Admin/` and `Cli/` the only subdirectories; integration tests live under `tests/integration/`. Helpers are split: substrate-owned ones load from `../newspack-nodes/tests/Helpers/`, this plugin's own from `tests/Helpers/`.
- JS tests live in `__tests__` directories beside the code under test, and `npm run test:js` runs them. Mounting goes through this plugin's own `src/test-helpers/renderHook.js` — `renderComponent()` for an element, `renderHook()` for a bare hook — because `@testing-library/react` is not a devDep here. A test whose component owns a live graph node, a Timer or an SSE poller, must tear it down: `cleanupMounts()` in `afterEach` covers everything `renderHook` mounted, and a `renderComponent` mount owns its own `unmount()` because `cleanupMounts()` never tracked it. A mount left standing keeps firing into the tests that follow.
- `jest.setup.js` fails a test on any `console.warn` or `console.error` it did not declare, so a test exercising a fault path declares the line with `expectConsoleWarn( '…' )` — and a declared warning that never fires fails the test too.
- Each service CI has its own test file — `DiscoveryCITest.php`, `PerformanceCITest.php`, `RulesCITest.php` — with `ServiceCiHandlerGuardTest.php` over all three. The ruleset engine has `RuleTest.php`, `RuleSetTest.php` and `RuleMatcherTest.php`. There is no `tests/unit/Rest/`: the one REST controller, `MCP_Controller`, is covered by `McpControllerTest.php` beside them.
- Run as a NON-ROOT user with the vendored binary (composer pins `^10.0`, resolved 10.5.64), and pass `--enforce-time-limit`: `cd tests && ../vendor/bin/phpunit --enforce-time-limit`. `Log_Manager` refuses to run as root, which fails the whole suite. Neither container puts a `phpunit` on PATH, so a bare invocation finds nothing, and an 11.x one would crash the bootstrap with `DispatchingEmitter::exportsObjects`.
- Coverage lands under `${TEST_TMP:-${TMPDIR:-/tmp}}/newspack-event-logger-nodes-coverage/` after `tests/run-coverage.sh`. `pre-push` holds every `includes/` class to 90% statement coverage (`scripts/coverage-gate.py`) and every `src/` file to 90% (`scripts/coverage-gate-js.mjs`), so a new class arrives with its tests or the push fails.
- Fixtures use `Message::TM_STRUCT` for array-VALUE messages. `TM_BYTESTREAM` in a fixture carrying an array VALUE is a stale test.
- Tests needing a cache backend assign the substrate's in-memory `\Memcached` double (`../newspack-nodes/tests/Helpers/InMemoryMemcached.php`) to `Core::$memd` in `setUp`. There is no cache interface to inject, and nothing should type-hint one.
- A new Service CI verb needs a happy-path test, a role test asserting `Capabilities::require()` rejects a caller without the declared role, and a cache-failure test where the handler reads through a Table. Rate-limit tests belong to the substrate.
- Seed test values **distinct from every default and fallback**. A test seeded with the default still passes when the change is ignored, so it proves nothing.

## Gates that run mechanically

Reproduce a finding against these before writing it up by hand — and never disable one to make a diff pass.

| Gate | What it holds |
|---|---|
| `scripts/lint-contract.mjs` | The ADR violations a correctness review passes |
| `scripts/check-substrate-floor.sh` | The declared floor against the substrate APIs this plugin calls |
| `scripts/lint-docs.sh` | Doc-vs-runtime drift, including the floor in prose |
| dndocker's `tools/check-firehose-parity.py` | The PHP and Perl producers writing one line |
| `scripts/lint-comments.{mjs,php}` | 80-column comments, honouring `@longform` |
| `scripts/reorder-node-methods.{js,php} --check` | Newspaper method order on every staged PHP and JS file — the JS tool exempts a class with a computed member key or a duplicate non-accessor member, printing a `!` note and passing |
| `scripts/lint-styles.mjs` | A component repainting a canonical control the shared roles already paint |
| `npm run lint:types` | Every `window.*` global and CSS custom property declared in `types/` |
| `npm run lint:deadcode` / `:deadcode:js` | phpstan-deadcode and knip; most findings are public API or test seams, so verify every call path |

## Common review nits that aren't bugs

- The eleven-namespace memcache schema looks redundant at a glance. It is deliberate: different access patterns want different keys, and one `lookup_multi` across a whole namespace is what holds dashboard latency down.
- The `hourly` namespace name misleads. Its buckets are five minutes wide, like every other bucketed namespace.
- The URL index stores at TWO resolutions and the coarse one is derived. `urls_h` is folded from `urls` on the writer's flush path, and an hour with no coarse key is answered from its twelve fine buckets — which is what makes a fresh deploy self-healing rather than a hole.
- Application classes register with the substrate's CommandInterpreter. One interpreter serves both plugins, and the `App\` namespace registration is what admits them.

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-debugging` — runtime debugging
- `nodes-review` (in newspack-nodes) — substrate gates that apply transitively
- `nodes-dashboards` (in newspack-nodes) — the toolkits and view-node contract the Dashboards section gates
