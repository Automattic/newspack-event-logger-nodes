# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Fleet, REPL), see `../../newspack-nodes/docs/architecture-guide.md`.

This plugin plus the `newspack-nodes` substrate is the sole event-logger stack, writing to `/tmp/newspack-nodes` by default.

**Substrate presence.** The deferred bootstrap (run on `plugins_loaded` priority 11) returns early unless `\Newspack_Nodes\Bootstrap` exists AND `Bootstrap::version_at_least( '2.46.0', 'Newspack Event Logger Nodes' )` passes: it wires the event logger against a new-enough substrate and lies dormant otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WordPress 6.5+; the version handshake is the graceful fallback. One thing is wired outside the deferred closure — `Config::register_config_keys()` hooks `newspack_nodes/declare_config_keys` at file load, because the substrate pulls that declaration from inside any config read, ahead of `plugins_loaded`.

## Table of Contents

- [Overview](#overview)
- [Write Path: Log_Manager](#write-path-log_manager)
- [Per-URL logging ruleset](#per-url-logging-ruleset)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Supporting classes](#supporting-classes)
- [Memcache Schema](#memcache-schema)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)
- [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)
- [Job ingress and routing](#job-ingress-and-routing)
- [Configuration](#configuration)
- [Hooks and filters](#hooks-and-filters)
- [REST + React](#rest--react)
- [CLI: wp nodes reqgrep](#cli-wp-nodes-reqgrep)

## Overview

```
+--------------------------------------------------------------------------+
|                     WRITE PATH (Runtime, per-request)                    |
|                                                                          |
|   WordPress request                                                      |
|       |                                                                  |
|       v                                                                  |
|   Log_Manager  --fill()-->  Topic(_firehose:topic)                       |
|                               |  (KEY = request id -> partition)         |
|                               v                                          |
|                      Partition.fill()  =>  /logs/firehose.pN/            |
|                      (4KB PIPE_BUF atomic append)                        |
|                                                                          |
|   \Newspack_Nodes\Job_Intake::feed()   =>  /logs/jobfeed.pN/             |
|       (unlocked, PIPE_BUF-bounded)                                       |
|   \Newspack_Nodes\Job_Intake::queue()  =>  /logs/jobintake.pN/           |
|       (auto-locked, up to 32 MiB)                                        |
|   a job with a future not_before       =>  /logs/jobdelay.p0/            |
+--------------------------------------------------------------------------+

+---------------------------------------------------------------------------------+
|                READ PATH (Worker, ~10 min substrate-controlled lifespan)        |
|                                                                                 |
|  topology complete.pN (= request-builder + flame-builder + job-hub):            |
|                                                                                 |
|    Consumer(firehose.pN)  ----> Tee ----> Request_Builder ----> requests.pN     |
|                                  |               +---> errors.p0                |
|                                  |               +---> alerts.p0                |
|                                  |               +---> completed:tee -+         |
|                                  |                                    |         |
|                                  |                    completed.p0 <--+         |
|                                  |                   gyroscope.p0 <---+         |
|                                  |                                              |
|                                  +-------> Job_Router --> Age_Sieve --> jobs.pN |
|                                                 ^                               |
|    Consumer(jobintake.pN) ----------------------+                               |
|    Consumer(jobs.pN)      ----> Job_Worker ----> registered handlers            |
|    Consumer(requests.pN)  ----> Flame_Builder --> flames.pN                     |
|                                       +---> Stats_Store  -> memcache            |
|                                       +---> flame-stats.pN (durable mirror)     |
|                                       +---> flame-builder:auto-tuner (sibling)  |
|                                                                                 |
|  topology flame-builder.pN:                                                     |
|                                                                                 |
|    Consumer(requests.pN) ----> Flame_Builder ----> flames.pN                    |
|                                       +---> Stats_Store  -> memcache            |
|                                                                                 |
|  topology job-spoke.pN (jobfeed in place of the firehose):                      |
|                                                                                 |
|    Consumer(jobfeed.pN)   ----> Job_Router --> Age_Sieve --> jobs.pN            |
|    Consumer(jobintake.pN) ----^                                                 |
|    Consumer(jobs.pN)      ----> Job_Worker ----> registered handlers            |
+---------------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       AGGREGATOR HUB (one per site)                      |
|                                                                          |
|  substrate Remote_Source spoke-1  --SSE pull-->  spoke 1 firehose        |
|  substrate Remote_Source spoke-2  --SSE pull-->  spoke 2 firehose        |
|  substrate Remote_Source spoke-N  --SSE pull-->  spoke N firehose        |
|     |  (operator-wired on the console, one per spoke/partition)          |
|     v                                                                    |
|  Remote_Job_Rewrite_Node (ELN node):                                     |
|     k:"job"  ->  k:"remote_job"   (separate handler map on the hub)      |
|     |                                                                    |
|     v                                                                    |
|  Topic(local firehose)  -- KEY-routed ->  Partition pN                   |
+--------------------------------------------------------------------------+
```

## Write Path: Log_Manager

`Log_Manager` is the per-request firehose writer. It's the only thing in the plugin that runs *during* a WordPress request — everything else runs in the worker fleet.

```php
class Log_Manager {
    public const REQUEST_LABEL    = 'process';
    public const REQUEST_START    = 'process (start)';
    public const REQUEST_COMPLETE = 'process (complete)';
    public const REQUEST_ABORTED  = 'process (aborted)';

    private const MAX_DATA_SIZE   = 3840;   // headroom under PIPE_BUF (4096)
    private const MAX_TIMER_DEPTH = 100;    // start/complete nesting cap
    private const ENV_VALUE_MAX   = 256;    // per-value cap in environment_v3

    public function start( string $label, array $data = [] ): void;
    public function complete( string $label, array $data = [], string $suffix = 'complete' ): void;
    public function message( string $category, array $data = [] ): bool;
    public function error( string $message ): bool;
    public function warning( string $message ): bool;
    public function info( string $message ): bool;
    public function alert( string $message ): bool;
    public function flush(): void;
    public function finish(): void;
    public function is_started(): bool;
    public function governing_rule(): ?Rule;
    public function governing_rule_id(): string;

    public static function instance(): self;
    public static function has_instance(): bool;
    public static function started_instance(): ?self;
    public static function redact_url( string $url ): string;
    public static function url_hash( string $url ): string;
    public static function firehose_dirs( string $log_path = '' ): array;
    public static function begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void;
    public static function end_job_context( string $handler = '', string $id = '', ?array $outcome = null ): void;
    public static function suspend(): void;
    public static function resume(): void;
}
```

**Wire shape.** Each firehose line is a packed 7-field substrate `Message` envelope, not bare JSONL. The request id rides `Message::KEY`, the entry map rides `Message::VALUE`, and `Message::TYPE` is `TM_STRUCT`:

```json
{"n":<line>,"k":"<category>","m":<payload>,"l":"<label>","ts":<epoch_float>}
```

- `n` — per-request line number, starting at 1. `Request_Builder_Node` validates the sequence, so a gap or a rewind is reported rather than silently assembled. The number is stamped and consumed together: burning one reads as a GAP, which the builder reports while still letting terminals through, where reusing one would read as a duplicate and strand the record in flight until its trace timed out.
- `k` — category. `App\Core` writes hook spans as `"<hook> hook (start)"` / `"<hook> hook (complete)"`; the ` (start)` / ` (complete)` suffix is what pairs a span. Anything else is a one-shot entry (`request`, `environment_v3`, `resources`, `memory`, `job`, `error`, `warning`, `info`, `alert`, `stderr`, custom event).
- `m` — payload (string or nested array, JSON-encoded on the wire). `Flame_Builder_Node` reads it for per-event detail.
- `l` — optional stable label. Aggregation groups on `l`; `m` is the volatile message. A rule with `trace_hooks` puts the calling frame here, which is what splits one hook's sixteen firings into a flame node per caller.
- `ts` — wall-clock epoch seconds as a float, stamped once per line and threaded into both the entry and `Message::TIMESTAMP`.
- The request id is NOT in the entry. `message()` unsets any caller-supplied `rid` key, so a caller cannot forge one.

**Request id.** `HTTP_X_A8C_REQUEST_ID` wins, then `UNIQUE_ID` (each clipped to 64 chars); absent both, `generate_request_id()` mints a 32-char base-36 string and stamps it into `$_SERVER['UNIQUE_ID']`, so a subprocess inherits the same identity. The id also picks the partition: `Partition_Node::hash_to_partition( $request_id, Bootstrap::global_num_partitions() )`, so every line of one request lands in one `firehose.pN`. The Topic itself, `_firehose:topic`, is built once per process and adopted by every later context.

### The environment allowlist

`log_environment()` writes an `environment_v3` entry carrying the 34 `$_SERVER` keys of `ENV_ALLOWLIST` and nothing else — an allowlist rather than a filter, so a header nobody anticipated is absent by construction. Each value is capped at `ENV_VALUE_MAX` (256 bytes) with an elision marker, which keeps the whole curated map under `MAX_DATA_SIZE` even when several values run long.

The Perl reference producer, `Gyrobase::Log`, keeps hand-maintained copies of the same list, cap, marker and redaction pattern in a separate repository. dndocker's `tools/check-firehose-parity.py` is the only thing that sees both, and holds them equal — membership, order and the numbers alike; it also refuses an allowlisted key that reads as a secret. This plugin's `pre-push` runs it whenever that tree is the checkout.

### URL-secret redaction

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`. Query-string values for `api_key`, `token`, `secret`, `bearer`, `authorization`, `session` and eighteen more become `=[REDACTED]`. The list intentionally errs broad — anything that looks like a credential gets blanked. `log_environment()` redacts before it caps a value at `ENV_VALUE_MAX`, so truncation can never hide a secret's boundary. `Log_Manager::redact_url()` is public because it is the ONE redaction path: anything that sends a URL somewhere it was not already written — the Ask brief, an agent surface — goes through it rather than a second pattern that would drift.

### Refuse-root

`Log_Manager::__construct()` calls `\posix_geteuid()` early, right after the `enable_logging` master switch. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction returns before the matcher is built, so nothing ever starts — `is_started()` stays false and every `message()` no-ops for the rest of the request.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. Two things keep that traffic out of the stats. First, the shipped config seeds SKIP rules for the substrate worker endpoints (`/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}`) and `/wp-cron.php`, so the matcher resolves those URLs to `skip` and the firehose never sees them. Second, for any worker request that IS logged, `NEWSPACK_NODES_WORKER_TYPE` rides both the `process (start)` line and the `environment_v3` entry; `Request_Builder_Node` reads it, sets `is_worker`, and appends `?<worker_type>` to the request URL so worker traffic gets its own URL rows instead of polluting the real ones. Without that exclusion, the fleet's spawn cycle would dominate every leaderboard.

### PIPE_BUF and truncation

Per-line writes go to `Topic::fill()` → `Partition::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the envelope around the entry.

Anything larger goes through `fit_data()`, which marks the entry `truncated: true` and then shrinks it: a string `m` is trimmed 10% at a time, re-encoding at each step because JSON escaping expands bytes and a byte-count subtraction can still land over the cap; an array `m` is dropped whole. Every other key survives — `l` and `caller` included, which is what lets the reader still open the span and merge it in the flame — and the category is never renamed, because renaming it breaks `Flame_Tree::PATTERN_START` and the span never opens at all. The floor, reached only by an entry nothing else explains, is a 1000-character excerpt of the encoded map. Truncation is silent apart from that flag and never throws — the firehose is fire-and-forget — so size discipline is the caller's responsibility. Oversize payloads belong in `\Newspack_Nodes\Job_Intake::queue()`, which goes through `Partition::allow_large_writes()` and the per-partition write lock.

### Per-request lifecycle

```
plugins_loaded (11)            App\Core ctor — binds the governing rule's hooks
hook_start (priority -10000)   App\Core::hook_start( hook_name )
hook callbacks run
hook_spacer (priority MAX-2)   App\Core::hook_spacer — sacrificial no-op
hook_complete (priority MAX-1) App\Core::hook_complete( hook_name )
…
shutdown                       ::finish() — closes orphaned starts, emits process (complete)
```

`Log_Manager::instance()` constructs on first use and registers its `shutdown` hook. The shipped start priority is `-10000` (`hook_start_priority` in `newspack-event-logger-nodes-config.php`); `App\Core` falls back to `1` only when the key resolves to a non-integer. The `hook_spacer` at `PHP_INT_MAX-2` (`App\Core::SPACER_PRIORITY`) is a sacrificial no-op registered on every instrumented hook so a self-removing filter (e.g. es-wp-query) that unhooks itself mid-run can't shift the WP filter pointer past `hook_complete` and skip the matching close. `wrap_callbacks()` treats everything at or above the spacer priority as ours and leaves it alone, and it skips any callback whose reflection reports a by-reference parameter, because wrapping those breaks WordPress's by-reference contract.

`App\Core` binds ONLY the hooks the current request's governing rule names — a skip rule or no match binds zero hooks, which is the hot-path win. Three names are skipped even when a rule lists them: `plugin_loaded`, because the `00-newspack-profiler.php` mu-plugin drop-in times plugin loads instead, and anything `Hook_Categorizer::is_internal()` recognises, because instrumenting one re-enters the `Log_Manager` bootstrap. `newspack_event_logger_nodes_scope_changed` (fired by `begin_job_context` / `end_job_context`) triggers `rebind_for_current_scope()`, so a job's synthetic `/jobs/{handler}/{id}` request gets its own rule's hooks.

Two more span pairs bind per rule, neither of them a hook the request declares:

- **Outbound HTTP** (`log_http`, on by default). `http_start` binds `pre_http_request` at `PHP_INT_MAX` — after every short-circuiting filter has voted — and opens NOTHING when `$preempt` is not false, because `WP_Http::request()` returns a short-circuit with a bare `return $pre;` and never fires the closing action; a span opened there would never close and would adopt every row after it. `http_end` binds `http_api_debug` at `PHP_INT_MIN`, so the span covers the request rather than the other listeners on that action, and closes the label it OPENED rather than one re-derived from the URL, which a redirect would have changed.
- **SQL queries** (`log_queries`, off by default). `query_start` binds the `query` filter at `PHP_INT_MAX`, after every filter that rewrites the SQL, so the span names what the database is actually asked; `query_end` binds `log_query_custom_data` at `PHP_INT_MIN`. That second hook fires only under `SAVEQUERIES`, so enabling the flag defines the constant — for the life of the process, since a constant cannot be withdrawn. `query_end()` therefore drains `$wpdb->queries`, which is also why this cannot be always-on: anything else reading that array (Query Monitor) would find it empty.

Both spans are named for their CALLER — `l` is `origin_frame( 1 )`, one frame beyond the class that applied the filter — because "which of my code paths makes this call, how many times" is the question a reader has.

Orphaned `start`s (callback threw, exit called, fatal error before `complete`) get a synthetic `"<label> (complete)"` at `finish()` time carrying `m: "(orphaned)"` plus the measured duration. `finish()` then emits `process (complete)` with the HTTP status; a fatal in `error_get_last()` adds `fatal_error` / `fatal_file` / `fatal_line` / `fatal_type` / `fatal_plugin` and `error_status='F'`. A request whose terminal never arrives at all is closed by `Request_Builder_Node`'s bucket eviction with `error_status='T'`.

## Per-URL logging ruleset

**What decides whether a request is logged, and which hooks/events it instruments, is a per-URL ruleset.** `Log_Manager::matches_url_filter()` resolves the governing rule via a `Rule_Matcher` and logs only when that rule's action is `log`; the rule's own hooks, custom events, significant events, diagnostic switches and auto-tune thresholds then drive the rest of the request. The governing rule's id rides the `process (start)` line as `rule`, which is how a reader attributes a request to the rule that admitted it.

**`Rule`** (`includes/class-rule.php`) is an immutable value object — every property `readonly`, `with()` deriving a changed copy. Its `id` is a pure function of its pattern (`Rule_Set::id_for` = `Log_Manager::url_hash( $pattern )`), so there is exactly one rule per URL; a client-supplied or config-supplied id is ignored.

| Property | Default | What it does |
|---|---|---|
| `pattern` | required | `/prefix`, exact `/path?`, or exact path plus query prefix `/path?query` |
| `action` | required | `log` or `skip`; every action but `log` skips |
| `hooks` | `[]` | Hook names this rule instruments; `null` when `hooks_in` is `mc` |
| `hooks_in` | `inline` | Storage tier: `inline` in the rule list, or `mc` behind a pointer |
| `custom_events` | `[]` | Categories the application logs itself; never bound as `do_action` hooks |
| `significant_events` | `[]` | Hooks that get per-callback profiling and are exempt from auto-disable |
| `auto_disable_threshold` | `0` | Per-request occurrence count above which auto-tune proposes a disable; 0 is off |
| `auto_protect_time_threshold` | `0.0` | Mean ms per call at or above which auto-tune promotes a hook to significant; 0.0 is off |
| `log_http` | `true` | Time every outbound HTTP request as a span |
| `log_queries` | `false` | Time every SQL query as a span; defines `SAVEQUERIES` and costs two entries per query |
| `trace_hooks` | `false` | Name the calling frame on each hook entry's `l`, splitting one hook into a flame node per caller |
| `trace_callers` | `0` | Deep caller chains one hook may record per request, on its start entry's `caller` field; a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT` (20) |

The constructor throws when the pattern is empty, and when `hooks` and `hooks_in` contradict each other — a null hook list means the pointer tier and a list means inline, with no third reading.

**`Rule_Matcher`** (`includes/class-rule-matcher.php`) picks the governing rule by specificity, not by list order. Three pattern forms rank ahead of one another, and pattern length breaks ties within a rank:

| Rank | Form | Example | Matches |
|------|------|---------|---------|
| 0 | exact path + query prefix | `/jobs/x?job-work` | path equal, query starts with the pattern's query |
| 1 | exact path | `/about?` | path equal, query ignored |
| 2 | path prefix | `/blog` | path starts with the pattern, query ignored |

Matching is case-insensitive (target and pattern are compared lowercased) and cached per normalized URL, misses included. A prefix matches by string rather than by path segment, so `/wp-cron` governs `/wp-cron.php` and `/shop` governs `/shopping`; a pattern's query is a raw string prefix too, so `/jobs/x?job-worker` governs `/jobs/x?job-worker&n=1` and not the same two parameters reversed. Patterns are written as site paths, so a caller holding an absolute URL passes its path.

**No rule matches ⇒ skip.** There is no implicit log-all baseline — to log everything, declare a `/` LOG rule. The shipped config does exactly that, plus baseline skips for `/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}` and `/wp-cron.php`, so the logger's own worker IPC, SSE and spawn traffic never logs.

**`Rule_Set`** (`includes/class-rule-set.php`) is the durable store. The rule LIST rides one autoloaded option (`newspack_event_logger_nodes_rules`); a heavy rule's hooks (past `INLINE_HOOK_LIMIT = 100`) move to a **non-autoloaded** per-rule durable option (`newspack_event_logger_nodes_rule_hooks_<id>`, the system of record) mirrored into the substrate's `Table_Node` (`TABLE_HOOKS = 'eln-rule-hooks'`, `TABLE_TTL = 3600`) so the common small-rule path never pays a cache hop and heavy rules don't bloat autoload. The Table accessor is nullable — a host with no cache backend simply has no warm mirror. `save()` applies the inline↔pointer threshold, warms/writes the durable option, reconciles orphaned hook options, and re-tiers the in-memory list so `rules() == load()`.

**Loading.** `Rule_Set::load()` falls back to `seed_from_config()` when the option is absent or corrupt, building the ruleset from the config file's `rules` key (non-persisting) until the editor first writes it. Empty means empty: a config with no `rules` key yields a zero-rule set, which logs nothing. Both read paths skip an unrepresentable rule with a rate-limited notice rather than throwing — one hand-edited option must not fatal every request that resolves a rule — while a WRITE caller lets the throw stand and rejects the whole push, leaving the last-good ruleset in place.

**One rule per URL.** `rekey_by_pattern()` re-derives every rule's id from its pattern and collapses duplicate patterns, last entry winning. Every write path that accepts outside rules runs through it: the config seed, the editor's `save` verb, and `apply_synced()` off the wire. Two entries keeping differing ids for one pattern would both persist and race in the matcher; two sharing an id would alias one durable hooks option, and the inline one's `delete_option` would wipe the pointer one's list.

**Transport.** `hydrate_array()` inlines every pointer rule's hooks so a synced ruleset reaches a spoke hook-complete; `apply_synced()` re-tiers it locally on arrival, writing heavy hooks back out to the spoke's own durable option. The settings-sync value filter calls `hydrate_array()` on the way out.

**Editing.** The `rules` service CI (`Rules_CI_Node`, verbs `list`/`save`/`upsert`/`delete`/`reset`) backs the "Logging Rules" editor mounted on the settings page (`src/rules/RulesAdmin`, mounted by `src/settings`). Every verb routes through `Rule_Set` so the tiering/reconcile invariants are never bypassed. `instrumented_union()` (the union across all LOG rules of hooks + custom events) feeds Discovery's spoke payload and the editor's selected-set browse modal.

**Calibration.** `wp nodes ruleset-bench` measures the two hook-storage tiers directly — a grid of `[50, 65, 100, 250, 500, 1000, 2500, 5000]` hooks per rule against `[1, 10, 50]` rules, reporting median autoload, inline and pointer cost in microseconds. `INLINE_HOOK_LIMIT` is what that table sets.

## Topologies

Each worker group is one declarative `.tsl` file in `topologies/`. A TSL file is a line-oriented script the substrate's `Shell_Node` interprets per partition:

| Directive | Effect |
|-----------|--------|
| `make_node <Type> <name> [args…]` | Instantiate a Node and register it under `<name>`. |
| `connect_node <from> <to>` | Wire `<from>`'s sink (or add a fan-out target on a Tee). |
| `disconnect_node <from> [to]` | Drop an edge an included topology declared. |
| `cmd <node>:config <verb> [args…]` | Run a config verb on the node's `:config` interpreter. |
| `include <topology>` | Splice another registered `.tsl` in at this point, once per file per load. |
| `var <key> = <value>;` | Declare frontmatter the runtime reads via `Topology_Registry::frontmatter()`. |
| `secure` | Climb the interpreter's secure ratchet one level, retiring management verbs. |

`<partition>` and `<topology>` are bound by `Topology_Loader`; `<config:logs_dir>`, `<config:num_segments>` and friends resolve against the substrate Config. Three tokens resolve against the application Config, through `Config::resolve_eln_token()`: `<eln:is_hub>`, `<eln:stats_mirror_node>` and `<eln:stats_mirror_lifetime>` — the last derived rather than configured, at twice `Config::stats_retention_seconds()`, so widening the stats window widens the mirror with it.

`<topology>` names the FLEET, which is why every offsetlog and dead-letter path carries it: an offsetlog is a reader's cursor and the reader is the fleet, so a `request-builder` fleet and a `complete` fleet tailing the same `firehose.pN` keep separate cursors instead of stealing each other's position. That is also what lets several topologies share one byte-identical Consumer line.

Two things about `include` are easy to trip over. Frontmatter is read from the TOP-LEVEL file only, so a `var` inside an included file is skipped — which is why `hub-control`, `job-hub` and `job-spoke` each restate the frontmatter of a file they include. And the Shell tracks included files, so a file pulled in twice by two different parents runs once — which is what lets `performance` and `complete` each include several files that all include `topic-probe`. A `secure` line inside an included file is skipped too; only the top-level one ratchets.

The `make_node` first argument is a **shell name** the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the single-word substrate types (`Consumer`, `Partition`, `Tee`, `Topic`, `Age_Sieve`, `Remote_Source`) resolve under the substrate's own prefix.

Ten `.tsl` files ship. Four are primitives — `request-builder`, `flame-builder`, `job-router`, `job-feed` — and six compose those plus substrate stock topologies: `job-hub`, `job-spoke`, `performance`, `complete`, `aggregator`, `hub-control`. Job *dispatch* (`Job_Worker_Node` tailing `jobs.pN`) is the substrate's stock `job-worker` topology, which `job-hub` and `job-spoke` include.

Every file ends with `secure`, and every one pulls in the substrate's stock `topic-probe` — directly, or through a file it includes. That probe mounts a 15s `Topic_Probe` sweep writing per-worker Consumer stats to `topicprobe.p0`.

### `topologies/request-builder.tsl`

The assembly branch. Tails `firehose.pN`; `Request_Builder` writes `requests.pN`, forwards errors to `errors.p0` and alerts to `alerts.p0`, and fans completed summaries through `completed:tee` to `completed.p0` + `gyroscope.p0`.

```tsl
include topic-probe

make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/<topology>.firehose.p<partition> <config:deadletter_dir>/<topology>.firehose.p<partition>
make_node Partition alerts:partition <config:logs_dir>/alerts.p0
make_node Partition errors:partition <config:logs_dir>/errors.p0
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.p0 1048576
make_node Partition completed:partition <config:logs_dir>/completed.p0 1048576
make_node Partition requests:partition <config:logs_dir>/requests.p<partition>
make_node Request_Builder request-builder 100 3
make_node Tee completed:tee
cmd firehose:consumer:config add_snapshot_node request-builder
cmd firehose:consumer:config set_multi_writer true
cmd request-builder:config set_alerts_target alerts:partition
cmd request-builder:config set_errors_target errors:partition
cmd request-builder:config set_inflight_target gyroscope:partition
cmd request-builder:config set_completed_target completed:tee
cmd requests:partition:config void_warranty
cmd requests:partition:config with_index request-index
connect_node completed:tee completed:partition
connect_node completed:tee gyroscope:partition
connect_node firehose:consumer request-builder
connect_node request-builder requests:partition
secure
```

Four things in that file are load-bearing.

**Partition arguments are positional and optional.** The full signature is `make_node Partition <name> <dir> [segment_size] [min_segments] [num_segments] [max_segments] [min_lifetime] [lifetime]`. `Partition::node_schema()` defaults each retention argument to its matching `<config:…>` token, so `alerts:partition`, `errors:partition` and `requests:partition` — which pass a directory and nothing else — resolve to exactly the values an explicit tail would spell out. `gyroscope:partition` and `completed:partition` pass one further argument each, pinning a 1 MiB segment size against the configured `segment_size` (64 MiB by default): both are high-rate `.p0` logs whose readers only ever want the recent tail, so small segments keep retention cheap.

**Four of the five output partitions are `.p0`, not `.p<partition>`.** Every partition's request-builder appends to the same `alerts.p0`, `errors.p0`, `gyroscope.p0` and `completed.p0`. That is safe precisely because none of them lifts the PIPE_BUF cap: each write stays a single atomic append, which is what makes a shared multi-writer log correct. `requests:partition` is the one per-partition output, and it is the one that runs `void_warranty`.

**`void_warranty` on `requests:partition`** lifts the per-message cap to 32 MiB (`Partition_Node::MAX_LARGE_LINE_SIZE`) *without* a per-Partition lock — that partition is written by exactly one worker fleet, and the substrate refuses to spawn a topology set where two fleets write the same partition, so the exclusivity lock `allow_large_writes` carries is redundant here. Full `Request_Builder` request documents regularly exceed the 4 KB PIPE_BUF ceiling on pages with many timed hooks. Everything that must fit in PIPE_BUF instead routes through `\Newspack_Nodes\Line_Fitter::fit()`, which halves the listed VALUE fields until the packed line fits and drops the line loudly when nothing is left to cut — `m` and `url` for `Request_Builder_Node`'s own emits, `url` and `user_agent` for the in-flight rows `Request_Flight_Node` ships.

**`add_snapshot_node` + `set_multi_writer`.** The first wires the consumer's offsetlog to checkpoint `Request_Builder`'s in-flight cache, so in-flight requests survive a worker respawn. The second tells the firehose Consumer to expect concurrent producers, since many FPM workers append to `firehose.pN`.

### `topologies/flame-builder.tsl`

Per-partition flame builder. Tails `requests.pN`; `Flame_Builder` emits `flames.pN` and bumps the memcache schema via `Stats_Store`.

```tsl
include topic-probe

make_node Consumer requests:consumer <config:logs_dir>/requests.p<partition> <config:offsets_dir>/<topology>.requests.p<partition> <config:deadletter_dir>/<topology>.requests.p<partition>
make_node Flame_Builder flame-builder
make_node Partition flames:partition <config:logs_dir>/flames.p<partition>
make_node Partition flame-stats:partition <config:logs_dir>/flame-stats.p<partition> <config:segment_size> <config:min_segments> <config:num_segments> <config:max_segments> <config:min_lifetime> <eln:stats_mirror_lifetime>
cmd requests:consumer:config add_snapshot_node flame-builder
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_stats_target <eln:stats_mirror_node>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flames:partition:config void_warranty
cmd flames:partition:config with_index flame-index
cmd flame-stats:partition:config void_warranty
cmd flame-stats:partition:config with_index stats-index
connect_node requests:consumer flame-builder
connect_node flame-builder flames:partition
secure
```

`configure_stats <partition>` constructs the per-partition `Stats_Store`, taking its retention window from `Config::stats_retention_seconds()` — the substrate's `min_lifetime` (default 43200), floored at `Stats_Store::PREFIX_FLOOR` (3600). Auto-tune thresholds live on each LOG rule, not on a topology token; `Flame_Builder_Node` reads the governing rule's thresholds per completed request (see [Flame_Builder_Node](#flame_builder_node) and [Auto_Tuner_Node](#auto_tuner_node)).

`set_stats_target <eln:stats_mirror_node>` mirrors the memcache stats into the durable `flame-stats:partition`, which a memcache miss reads back through its `stats-index` companion index; set `stats_mirror_node` to `''` to disable it. The mirror keeps full aggregates plus a bounded top-N per URL (`STATS_MIRROR_TOPN`: 100 dimensional, 100 category, and flame profiles only when `set_flame_topn` raises the default 0). The coarse hourly URL tier is excluded, because it is derived from a `urls` index the mirror already keeps in full.

A bucket's frames are written once, at the first checkpoint after that bucket CLOSES — the partition keeps only the last frame for a key, so mirroring the bucket still being accumulated into would be nine redundant copies of a growing value. Until it closes the open bucket is held in memory and backed by the OFFSETLOG: `save_state()` carries the held frames, `restore_state()` takes them on, and a respawned worker writes them when the bucket closes. That is the point of the split — the offsetlog is a bounded ring, so the open window costs fixed disk there, where `flame-stats` retains every frame for `stats_mirror_lifetime`, twice the stats window. The carry is capped at `MAX_CHECKPOINT_MIRROR_BYTES` (2 MiB), smallest-first, because `Partition_Node` drops an oversize record whole — cursor included — and a dropped frame is re-merged from memcache by the next write to its bucket.

### `topologies/job-router.tsl`

The job-routing half, reading jobs out of the firehose. Tails `firehose.pN` and, via the included substrate `job-intake` topology, `jobintake.pN`; `Job_Router` normalizes both sources and writes `jobs.pN`. Dispatch of those lines is a separate leg — the substrate's stock `job-worker` topology, which `job-hub` adds.

```tsl
include job-intake

make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/<topology>.firehose.p<partition> <config:deadletter_dir>/<topology>.firehose.p<partition>
make_node Job_Router job-router
make_node Age_Sieve jobs:sieve 900 1
cmd firehose:consumer:config set_multi_writer true
disconnect_node jobintake:consumer
connect_node jobintake:consumer job-router
connect_node firehose:consumer job-router
connect_node job-router jobs:sieve
connect_node jobs:sieve jobs:partition
secure
```

`include job-intake` brings in the substrate's `jobintake:consumer` and `jobs:partition` (already under `void_warranty`) wired straight to each other, plus `topic-probe`, which this file therefore does not name itself. The `disconnect_node` + `connect_node` pair then re-routes the consumer through `job-router`. Because `job-intake` declares `jobs:partition` with the warranty voided, the substrate's conflict gate refuses to co-activate the stock `job-intake` topology alongside `job-router`.

Both Consumers feed `job-router`. It selects a nested array under `m` when present and otherwise treats the entry itself as the job body, so firehose and Job Intake records take one normalization path; routing does not depend on the Consumer name in `Message::FROM`. `Job_Router` takes no arguments: staleness is the downstream `Age_Sieve`'s job, and it drops messages whose envelope `TIMESTAMP` age exceeds `max_age` — 900 seconds here, with the drop warning enabled.

### `topologies/job-feed.tsl`

`job-router` with one substitution: it tails `jobfeed.pN` instead of the firehose. Everything else — the included `job-intake` leg, the `Age_Sieve`, the re-route — is identical.

Reading `jobfeed` costs a fraction of reading the firehose, because the feed carries jobs alone where the firehose carries every log entry of every request. A site whose producers call `Job_Intake::feed()` should run this one; a site whose jobs ride the firehose as `k:"job"` needs `job-router`, since nothing writes `jobfeed` for it. The two are alternatives rather than a pair: both declare `job-router`, `jobs:sieve` and `jobs:partition`.

### `topologies/job-hub.tsl` and `topologies/job-spoke.tsl`

Routing plus dispatch in one process:

```tsl
var stale_timeout = 600
include job-router     # job-spoke.tsl includes job-feed instead
include job-worker
secure
```

`job-worker` is the substrate's stock dispatch topology: a `Consumer` on `jobs.pN` in line mode, a `Job_Worker` node, and a 15s `Job_Probe` writing per-worker job stats to `jobstats.p0`. A throwing handler propagates back to that Consumer, which quarantines the job to its dead-letter sibling rather than dropping it.

The `var stale_timeout = 600` restates `job-worker`'s own frontmatter, which the include skips. It has to: a handler only heartbeats where it reaches `should_continue()`, so one doing CPU-bound work, a long query or a `proc_open` render does not beat at all, and at the default a peer would steal its lock mid-job and replay the job to die again.

### `topologies/performance.tsl`

`request-builder` + `flame-builder`, nothing else:

```tsl
include request-builder
include flame-builder
secure
```

Both included files include `topic-probe`, so the composition mounts one probe and two Consumers. Use it where job dispatch lives in a separate fleet and you want request assembly plus flame stats in one worker group.

### `topologies/complete.tsl`

The everything-in-one worker: request assembly, flame stats, job routing and job dispatch in one process.

```tsl
include performance
include job-hub
make_node Tee firehose:tee
disconnect_node firehose:consumer
connect_node firehose:consumer firehose:tee
connect_node firehose:tee request-builder
connect_node firehose:tee job-router
secure
```

Order matters. `job-hub` is included last and, through `job-router`, wires `firehose:consumer → job-router` directly; the five lines below it then re-route that single edge through `firehose:tee` so both `request-builder` and `job-router` feed from the Tee. **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** `jobintake:consumer` still has one target, so it stays wired directly to `job-router`. Number of Tees equals number of source fan-outs, not number of sources.

### `topologies/aggregator.tsl`

Hub-side ingest. Per-spoke substrate `Remote_Source` nodes (operator-wired on the console canvas) pull each spoke's firehose via SSE; the ELN `Remote_Job_Rewrite` node flips aggregated `k:"job"` entries to `k:"remote_job"`; the multi-partition `Topic` KEY-routes by request-id hash so each downstream firehose-consuming partition sees its own slice.

```tsl
include topic-probe
make_node Remote_Job_Rewrite remote-job-rewrite
make_node Topic firehose:topic <config:logs_dir>/firehose.p{partition}
connect_node remote-job-rewrite firehose:topic
secure
```

Per-spoke `Remote_Source` nodes are NOT in the stock topology — the operator adds them on the canvas, one per spoke/partition:

```tsl
make_node Remote_Source spoke-<id> <vault-id> firehose.p<partition> \
    <config:offsets_dir>/spoke-<id>.<topology>.p<partition> \
    <config:deadletter_dir>/spoke-<id>.p<partition>
connect_node spoke-<id> remote-job-rewrite
cmd spoke-<id>:config set_multi_writer true
```

`Remote_Source` reads like the Consumer it is: `<vault-id>` names the spoke, `firehose.p<partition>` names the remote partition to subscribe to, and the last two arguments are its offsetlog and dead-letter directories. Both are optional in the schema and both should be passed — omitting them means no cursor and no quarantine. Scope the offsetlog with `<topology>` so two hubs pulling one spoke partition never share a cursor.

`set_multi_writer` belongs on every firehose spoke, because every request process there appends to that log. It asks the SPOKE's reader to hold a superseded segment for the seal grace; without it a straggler's last line — typically the request's terminal `process (complete)` — is orphaned, and the request never finalizes on the hub.

Each substrate `Remote_Source_Node` is self-sufficient: it patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`. SSL/HTTPS policy comes from the substrate `vault_verify_ssl` / `vault_require_https` config, not a per-topology setter; spoke credentials are looked up from the substrate **Vault** by `<vault-id>`. The `k:"job"` → `k:"remote_job"` rewrite is the `Remote_Job_Rewrite_Node` graph node (see [Remote_Job_Rewrite_Node](#remote_job_rewrite_node)) — not a filter, not a TSL setter; non-`job` entries pass through. Hub-mode is derived from whether `aggregator` is in the substrate's Topologies multi-select, directly or through an active topology that includes it — there is no `enable_aggregator` config key. Operators stand a hub up by selecting the `aggregator` and `hub-control` topologies.

### `topologies/hub-control.tsl`

Single-instance hub control plane. It is an ELN overlay over the substrate's stock `settings-sync` topology, and it pins `var num_partitions = 1` so the fleet runs ONCE regardless of the data `num_partitions` — the pin must live in this file, since frontmatter is read from the top-level file only.

```tsl
var num_partitions = 1;

include settings-sync

make_node Discovery_Collector discovery-collector 300

cmd settings-sync:config add_setting newspack_event_logger_nodes_rules performance newspack_event_logger_nodes_rules
cmd settings-sync:config add_setting newspack_event_logger_nodes_log_memory performance newspack_event_logger_nodes_log_memory
cmd settings-sync:config add_setting newspack_event_logger_nodes_flush_every_line performance newspack_event_logger_nodes_flush_every_line
secure
```

The included `settings-sync` supplies `settings:consumer` (tailing the `settings.p0` log that the substrate's `Settings_Event_Writer` appends option-name-only events to on watched WP-option changes), the `Settings_Sync` node itself on a 300s tick, and the substrate's own `add_setting` registrations for `num_partitions` and the six-axis `remote_*` segment geometry — each of those registered twice, once to push the hub's value onto a spoke's own geometry and once to seed the spoke's `remote_*` copy so it propagates onward to ITS spokes.

The three lines above add the application options, and they must stay in step with `Performance_CI_Node::SETTINGS_OPTIONS`: a hub push naming anything outside that whitelist comes back as "unknown option". This file also adds `Discovery_Collector`, which fans `discovery.get` to every spoke on its own 300s tick and union-merges the replies into the hub's staging options.

**Neither fan-out goes through a Tee.** Each spoke's command is signed under that spoke's session key, and re-addressing a signed command after the mint makes it verify nowhere — so `Settings_Sync` and `Discovery_Collector` each iterate their own live targets and mint one signed command per spoke. The pipeline stays correctly inert until an operator wires per-spoke `HTTP_Out <name> <vault-id>` nodes from the console and connects the two nodes to them.

`Settings_Sync` and `Settings_Event_Writer` live in the substrate (`\Newspack_Nodes\Settings_Sync_Node`, `\Newspack_Nodes\Settings_Event_Writer`); `Discovery_Collector_Node` is this plugin's (see [Discovery_Collector_Node](#discovery_collector_node)).

### Topology resolution

The `topologies` key lives on the substrate. The plugin only **publishes its catalog**: at boot, it calls `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' )` — one call that registers the application namespace prefix (for `make_node` short-name resolution) AND the stock-topology dir together — so anyone calling `Topology_Registry::resolve()` (admin, REST, tests, CLI, workers) finds the stock `.tsl` files. Which catalog entries actually spawn workers is decided downstream by the substrate's Topologies multi-select option (`newspack_nodes_topologies`) — this plugin only publishes the "what topologies exist" set; the substrate filters it by what the operator has checked. `num_partitions` defaults also come from the substrate config, so one setting drives both `Log_Manager` (write side) and the worker fleet (read side); hardcoding diverges them.

Cost on regular WP requests is one array append at boot — the `.tsl` files themselves aren't parsed yet. Actual resolution and parsing happen in three places, none on the page-render hot path: the fleet's config-check tick (every 15s), worker bootstrap (once per spawn), and REST workers/dashboard reads.

## Application Nodes

### Request_Builder_Node

Assembles requests from firehose entries via the `LRU_Cache` 3-bucket timed-rotation cache. Bucket rotation every 200s; full retention window 3 × 200s = 600s (`DEFAULT_EVICTION_WINDOW_SEC`). Orphans evicted from the oldest bucket emit as timed-out (`error_status: 'T'`). It's a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`); `fire()` rotates the in-flight cache on each tick, so a stalled request still times out and gets written even when no further firehose lines arrive to drive rotation via `fill()`.

```php
class Request_Builder_Node extends Timer_Node {
    public const DEFAULT_BUCKET_SIZE             = 100;   // positional arg 0
    public const DEFAULT_NUM_BUCKETS             = 3;     // positional arg 1
    public const DEFAULT_ENTRY_BUDGET            = 50000; // positional arg 2
    public const DEFAULT_MAX_ENTRIES_PER_REQUEST = 20000;
    public const DEFAULT_EVICTION_WINDOW_SEC     = 600;

    public const LOST_MARKER_KEY     = 'entries (lost)';
    public const FOLD_MARKER_KEY     = 'entries (aggregated)';
    public const SEQUENCE_BREAK_KEYS = [ self::LOST_MARKER_KEY, self::FOLD_MARKER_KEY ];
    public const ERROR_STATUSES      = [ 'F', 'T', 'A', 'I' ];
    public const TERMINAL_KEYWORDS   = [ 'process (complete)' => true, 'process (aborted)' => true ];

    private const BUCKET_ROTATION_S = 200;
    private const MAX_STACK_DEPTH   = 50;   // runaway cutoff
    private const FOLD_KEEP_HEAD    = 10;
    private const FOLD_KEEP_TAIL    = 10;
    private const INDEX_LINE_BYTES  = 97;

    public function fill( array $message ): void {
        // Validate the per-request sequence `n`; assemble (start)/(complete)
        // pairs into nested spans; fan error/warning/stderr to errors_target
        // and alert to alerts_target.
        // On rotation: evict timed-out bucket (sets error_status='T' on each orphan).
        // Under entry pressure: fold the largest envelope, or this one.
        // On request complete: emit the full doc via $this->sink, plus the
        // compact summary to completed_target.
    }

    protected function fire(): void {
        // Router-TIMER tick (1s): rotate the in-flight cache so stalled
        // requests time out even on idle / low-traffic partitions.
    }
}
```

Eviction emits via `$this->sink->fill( $synthetic_message )`, NOT direct file writes. This matters for composability: timed-out requests must flow through the graph so the completed fan-out gets a copy, hooks can observe, and tests can capture. Don't write to files from inside an eviction callback.

**Two bounds, and both FOLD.** `entry_budget` constrains the sum of entries across everything in flight and folds the largest envelope when crossed; `max_entries_per_request` constrains one envelope and folds that one, a faster trigger for a single runaway. A folded record keeps `FOLD_KEEP_HEAD` raw entries — how a request identifies itself: process start, request line, environment — and `FOLD_KEEP_TAIL` — how it ends: stats flush, memory, terminal — rejoined around a `FOLD_MARKER_KEY` marker, with the repetitive middle merged into the flame tree instead. A discard on overflow leaves `LOST_MARKER_KEY` in the same place. The earlier behaviour, stopping at the cap and setting a flag nothing surfaced, lost every entry past it; don't reintroduce it.

**No consumer may reason across a marker.** The interval one covers is missing DETAIL, not idle time. `Findings::entry_gap()` skips a gap on either side of one, `Reqgrep_Command` breaks its sequence there, and `logEntryUtils` splices the merged spans back at it. What a consumer MAY read across one is the record's own ending — whether anything closes the outermost pair — which decides framing and leaves the interval exactly as unmeasurable as it was. The fold also keeps the CLOSE of any span the kept head left open: it just chose the head, so it knows which frames it left open, and a span opened in the head frames every row after it.

Two more behaviours are easy to miss. A `gyrobase (start)` entry pushes the current sequence counter onto a stack and restarts numbering at 1, so a nested `proc_open` render under the same request id doesn't read as a sequence break; the matching `gyrobase (complete)` pops it back. And a request whose hook stack passes `MAX_STACK_DEPTH` is flagged `is_runaway`: it stays visible (Perl gyroscope parity) but stops accumulating entries, and leaves on the ordinary bucket rotation like any other.

The node also owns the `request-index` formatter (`format_index_entry`), a fixed-width 97-byte line — rid, url_hash, timestamp, duration, status, segment, offset, length, peak MB, a one-char method code, and a one-char error status — that `Partition::with_index()` writes beside `requests.pN` and `Performance_CI_Node` seeks against.

### Request_Flight_Node

Backs the Gyroscope dashboard's live in-flight view. It's a hidden `Timer_Node` sibling that `Request_Builder_Node` owns: on each timer tick it snapshots the patron's in-progress request map and emits **one TM_STRUCT message per in-flight request** (KEY = rid) to the gyroscope partition, so the page can render a live treemap of what's running right now plus a fading trail of recent completions. A single batched list was the earlier shape and it crossed the 4 KB cap under load.

```php
class Request_Flight_Node extends Timer_Node {
    protected function fire(): void;          // one row per in-flight request, on the Router's 1s TIMER
    public function inflight_snapshot(): array;
    public function target( $value = null );  // the sole enable switch
}
```

It has no `set_interval()` / `interval()` / `fire_cb()`. Snapshots hitchhike the Router's 1s TIMER via `fire()` (the `Timer_Node` contract); a non-empty `target()` is the sole enable switch, and clearing it stops the timer. The node is hidden from the topology console by the substrate's patron filter in `dump_metadata`, and from the palette by its own `node_schema()` category. Its configuration surfaces on the patron's `:config` interpreter as `set_inflight_target` and `set_inflight_delta` — the `request-builder` topology (and therefore `performance` and `complete`) wires it with `cmd request-builder:config set_inflight_target gyroscope:partition`. Delta mode is off by default, so every tick re-emits every row and a fresh subscriber sees the whole cache in one tick; turning it on emits only rows whose activity advanced since the previous fire. The delta flag itself lives on the patron as a declared `toggle`, read live at fire time — Flight keeps no copy, exactly as it keeps no copy of the in-flight map. A row still over the PIPE_BUF cap after `Line_Fitter` halves `url` and `user_agent` is dropped with a rate-limited warning, where `Partition_Node` would drop it silently.

Because the snapshot rides `Request_Builder_Node`'s own in-flight map (the same `LRU_Cache` buckets it already maintains for request assembly), there's no second tracker to keep coherent.

The gyroscope partition therefore carries two interleaved record shapes: per-request `complete` rows (via the `completed:tee` fan-out) and the periodic active-state rows `Request_Flight_Node` emits. The browser dispatches them client-side; there's no server-side `GyroscopeStreamController`.

### Flame_Builder_Node

Aggregates completed-request events into flame-graph stats and writes flame data to `flames.pN`. It reads the completed-request documents `Request_Builder_Node` wrote to `requests.pN`, one TM_STRUCT message each. It emits into the hourly, leaderboard, per-server leaderboard, URL-index, per-URL, dimensional and category memcache namespaces via `Stats_Store`, which holds the per-URL accumulator itself as a rotating `Table_Node` accumulator (5 buckets × 1000 entries).

The auto-tune thresholds are **per-LOG-rule** (`auto_disable_threshold` / `auto_protect_time_threshold` on the governing `Rule`). When a rule sets a non-zero threshold, `Flame_Builder_Node` runs noisy / significant event detection for that rule's traffic during its 5s flush window: hooks that fire more than `auto_disable_threshold` times are candidates for disable; hooks whose mean event time exceeds `auto_protect_time_threshold` are candidates for "significant" status (protected from auto-disable). Decisions emit downstream as `TM_STRUCT` messages addressed to the owned `{name}:auto-tuner` sibling and tagged with the `rule_id` to mutate — see [Auto_Tuner_Node](#auto_tuner_node) for the dispatch and fan-out.

```php
class Flame_Builder_Node extends Node {
    const FLUSH_INTERVAL_SEC       = 5;
    const ENTRY_LIMIT_GLOBAL_UPPER = 100;   // hysteresis: trim at upper, down to lower
    const ENTRY_LIMIT_GLOBAL_LOWER = 50;
    const ENTRY_LIMIT_URL_UPPER    = 40;
    const ENTRY_LIMIT_URL_LOWER    = 20;
    const DIM_FIELDS               = [
        'status'  => 'status_category',
        'method'  => 'request_method',
        'server'  => 'server_name',
        'country' => 'country_code',
        'from'    => 'http_from',
        'ua'      => 'user_agent',
        'ja4'     => 'ja4_hash',
    ];
    public  const MAX_URLS_PER_SHARD = 2000;    // top-N per URL-index SHARD (x16)

    private const MAX_CHECKPOINT_MIRROR_BYTES = 2097152; // held-frame carry budget
    private const WRITE_BATCH_KEYS            = 500;     // keys per flush batch
    private const ROLLUP_HOURS_PER_FLUSH      = 2;       // coarse-tier backfill bound
    private const REPROBE_EVERY_FLUSHES       = 60;      // re-probe the folded-hours memo
    private const INTERN_TABLE_LIMIT          = 50000;   // per-process string intern cap
    private const NAMED_URL_BUCKET_SIZE       = 2000;    // urlmap write-once held set
    private const NAMED_URL_BUCKETS           = 4;
}
```

Flush every 5s via the Router-hitchhike Timer; flush on shutdown via the TM_EOF handler. `flush()` rolls the coarse hourly tier up BEFORE it persists, because the roll-up is what probes that tier — so the first flush after a respawn has an accurate `folded_hours` memo before a single row is placed. The memo is per-process, which is safe because one partition has one writer; `REPROBE_EVERY_FLUSHES` covers the other direction, where an hour key has been evicted and would otherwise stay believed-folded for the life of the worker.

Three constants bound the write path's memory rather than its output. `WRITE_BATCH_KEYS` holds one read/write batch to at most one shard's worth of rows. `INTERN_TABLE_LIMIT` caps the per-process string-intern table every dimension value, category and entry name is looked up in, so repeated names share one zval instead of one per `json_decode`; past the cap the table freezes rather than growing. And the named-URL held set (`NAMED_URL_BUCKET_SIZE` × `NAMED_URL_BUCKETS`) remembers which hashes already have a `urlmap` name, so a name is written once per URL instead of once per flush.

The node also owns two index formatters the topology names: `flame-index` beside `flames.pN`, and `stats-index` beside the durable `flame-stats.pN` mirror.

### Auto_Tuner_Node

An owned, hidden sibling of `Flame_Builder_Node` (`{name}:auto-tuner`, patron-linked, never built in TSL). It receives the builder's tuning decisions as `TM_STRUCT` messages and applies them by **mutating the specific rule** the message names (`rule_id`), persisting the whole ruleset via `Rule_Set::save()`. There is no `remote_manager` job and no `suppress_sync` guard — the change reaches spokes the same way any admin edit does: the `Rule_Set::save()` write records a settings event that the `hub-control` topology's `Settings_Sync` node fans out (see [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)).

```
Flame_Builder_Node  ──TM_STRUCT msg──→  Auto_Tuner_Node.fill()
   TO=…:auto-tuner                     │
   KEY=disable_hooks /                 └─→ apply_*( items, rule_id ) ─→ Rule_Set::save()
       disable_custom_events /               (read the rule by id, mutate its
       add_significant_events                 hooks / custom_events / significant_events,
   VALUE={ rule_id, items }                   re-tier + persist the ruleset)
```

The KEY discriminates which per-rule list is tuned; `Auto_Tuner_Node` dispatches by KEY inside `fill()` to the matching `apply_*` method, each a read-modify-write on the named rule:

| KEY | Updates the named rule's… |
|-----|---------------------------|
| `disable_hooks` | `hooks` minus the items (the rule's significant events are preserved) |
| `disable_custom_events` | `custom_events` minus the items (significant events preserved) |
| `add_significant_events` | `significant_events` ∪ items |

Three guards keep the write honest. `authorized()` requires either `NEWSPACK_NODES_WORKER_TYPE` in `$_SERVER` (the flame-builder worker) or `manage_options` (an admin request), so a wire-arrived message can't tune the ruleset. `mutate_rule()` calls `\Newspack_Nodes\Config::invalidate_options_cache()` first, so the read-modify-write starts from a fresh snapshot rather than clobbering a concurrent worker. And `unchanged()` compares both rules with their hook lists resolved to concrete form — a pointer-tier rule stores `hooks=null`, so a raw comparison would always differ — which lets a no-op decision skip the write entirely.

Expressing it as a Node with `fill()` dispatch is one straight path through the logic, in the flame-builder worker that owns it.

**Distributed lock**: `Flame_Builder_Node` holds a 5s `evlog:auto_disable_lock` memcache lock across the emit batch, released only while the value it wrote is still there. Without it, two `Flame_Builder_Node` workers (different partitions, both flushing at the same instant) would emit overlapping decisions twice. With no shared cache handle, or no `Stats_Store` (unit tests), it fires unlocked.

### Job_Router_Node

Multi-input routing. It reads the firehose, `jobfeed` and `jobintake` without interpreting `Message::FROM`: an array under `entry['m']` is the body, otherwise the entry itself is the body. Both shapes normalize into `{ k, handler, parameters, ts }` — plus `id` when the body carries one, plus whichever of `\Newspack_Nodes\Job_Intake::DISPATCH_FIELDS` (`retries`, `attempt`, `batch`, `key`) it carries — before forwarding to `jobs.pN` for `Job_Worker_Node` to dispatch. Those dispatch fields are load-bearing rather than decoration: `Job_Worker` reads them back to decide a retry and to settle a batch, so dropping them disables both silently. The job kind is carried under `k` (not `type`) end-to-end, and both `job` and `remote_job` are preserved for either body shape.

| Entry shape | Selected body | Allowed kinds |
|-------------|---------------|---------------|
| Nested array under `m` | `entry['m']` | `job` or `remote_job` |
| No nested array | `entry` | `job` or `remote_job` |

The kind is read from the ENTRY's `k`, never the body's, so the hub's `job` → `remote_job` rewrite wins over whatever the spoke wrote inside the body. `ts` falls back to the entry's timestamp only when the body carries none, and is copied verbatim rather than coerced — a garbage `ts` travels through as data for the downstream reader to zero out.

Five shapes are rejected and only two warn. An invalid handler name and non-array parameters go through `drop_message()`, which prints a rate-limited warning, because each is a malformed job:

- Handler names must match `HANDLER_NAME_PATTERN` = `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- `parameters` must be an array when present; omission normalizes to `[]`

Wrong type flags, a non-array VALUE and a kind other than `job`/`remote_job` return silently: the firehose carries every log entry and a drain ends on a terminal TM_EOF, so those three are ordinary traffic, and warning on them would bury the two that are faults.

Two gates this node deliberately does not hold. Size belongs to the producers and the Partition — `Job_Intake::MAX_JOB_SIZE` caps an entry where it is written, `Log_Manager::MAX_DATA_SIZE` strips an oversize firehose job's `m` before this node sees it (so it arrives here as an invalid-handler warning), and the Partition refuses an oversize line where it lands. Staleness belongs to the `Age_Sieve` the topology wires downstream, which is why `Job_Router` takes no arguments at all. `Job_Worker_Node` handles valid-but-unregistered handler names after reading `jobs.pN`.

### Job_Worker_Node

> **Lives in the `newspack-nodes` substrate** (`\Newspack_Nodes\Job_Worker_Node`), not this plugin. Generic async-job dispatch — per-job try/catch isolation, the `gc_collect_cycles()`-after-every-job discipline, the object-cache flush cadence, and the memory-watermark self-restart — is runtime plumbing; see its row in [`../../newspack-nodes/AGENTS.md`](../../newspack-nodes/AGENTS.md). Two things are THIS plugin's concern:

**Per-job request context.** The worker fires `newspack_nodes/job_worker/{before,after}_job` around each handler; the before-hook is a filter that can veto the job, and the after-action runs even on throw. ELN hooks `Log_Manager::begin_job_context_filter` / `end_job_context` onto them to suspend the request logger and stand up a synthetic `/jobs/{handler}/{id}` `$_SERVER` (plain `/jobs/{handler}` when the id is empty), so a job's own logging never bleeds into the request that enqueued it. Both are stack-based: `begin_job_context()` snapshots `$_SERVER` onto a LIFO before touching anything, so an unpaired or throwing begin still leaves `end_job_context()` a snapshot to restore. The same pair brackets the substrate's minute-cadence reconciliation (`newspack_nodes/before_reconcile` / `after_reconcile`), so spawn, backlog wake, lock reconcile, retention, orphan-IPC reaping and every `newspack_nodes/periodic` subscriber land in a `/jobs/newspack-nodes` request rather than bleeding into whatever WP-Cron request hosted them.

**Handler registration + `k`-routing.** The worker reads each `jobs.pN` entry's kind under `k` — `'job'` or `'remote_job'`, carried end-to-end (never `type`) — and dispatches against the matching filter: `k:"job"` → `newspack_nodes/job_handlers`, `k:"remote_job"` → `newspack_nodes/remote_job_handlers`. Registration is filter-only (no programmatic setters); a job type registers under whichever side(s) should handle it — see [Hub vs Spoke Topology](#hub-vs-spoke-topology).

### Remote_Job_Rewrite_Node

Hub-side fan-in is the self-sufficient substrate `\Newspack_Nodes\Remote_Source_Node` — one per spoke/partition, operator-wired on the topology console. Each one patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, pulls `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume, looks up its spoke credentials from the substrate **Vault**, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`.

The one application-specific piece is `Remote_Job_Rewrite_Node` — a pass-through transform the `aggregator` topology wires between the substrate `Remote_Source` sources and the firehose `Topic`. It flips aggregated `k:"job"` entries to `k:"remote_job"` so they dispatch centrally on the hub via `newspack_nodes/remote_job_handlers` rather than locally; non-`job` entries and non-array VALUEs pass through untouched.

```php
// includes/class-remote-job-rewrite-node.php
class Remote_Job_Rewrite_Node extends Node {
    public function fill( array $message ): void {
        $value = $message[ Message::VALUE ];
        if ( \is_array( $value ) && 'job' === ( $value['k'] ?? null ) ) {
            $value['k']                = 'remote_job';
            $message[ Message::VALUE ] = $value;
        }
        parent::fill( $message );
    }
}
```

Keeping the rewrite in a graph node keeps the substrate `Remote_Source` / `SSE_In` application-agnostic: the rewrite is a node the hub topology wires in, not a filter the substrate has to call. The rewrite grows the line by the 7-byte `job` → `remote_job` delta; the downstream `Topic` enforces its own per-message cap.

### Discovery_Collector_Node

Hub-side periodic discovery fan-out. A `Timer_Node` the `hub-control` topology mounts (`make_node Discovery_Collector discovery-collector 300`): `arguments()` arms `set_timer()` at the given interval, defaulting to 300s when the token is blank. On each `fire()` it walks its live targets, mints one signed `discovery.get` TM_COMMAND per spoke (addressed to that spoke's `discovery` CI), and skips — after asking the egress for a handshake — any target with no established session. Each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks` and `custom_events` (under `VALUE['payload']`), folded one reply at a time so out-of-order or partial replies converge. Remote strings are sanitized and both catalogs are capped at `MAX_EVENTS = 10000`.

**It stages, it does not instrument.** The merge never writes the ruleset; a shared `stage_discovered()` helper writes hooks into the non-autoloaded `discovered_hooks` option and custom events into `discovered_events`, and a name that arrived as a custom event never also stages as a hook. Those surface in the rules editor's hook picker for the operator to add — the editor is the only writer of rules.

### Stats production (owned by Flame_Builder_Node)

There is no separate stats Node. `Flame_Builder_Node` is the single stats producer, owning flame generation AND the memcache fan-out via its injected `Stats_Store`. The `flame-builder` topology (and therefore `performance` and `complete`) wires the store with `cmd flame-builder:config configure_stats <partition>`.

Each completed request is folded into `Flame_Builder_Node`'s `$pending` map across the same dimension set the reader paths whitelist — `DIM_FIELDS`, listed under [Flame_Builder_Node](#flame_builder_node). `$pending` holds one accumulator per 5-minute bucket, keyed by the bucket the request FINISHED in: `timestamp + duration_ms`, clamped to now. A record reaches the builder at completion, so filing it under its START would put a long request in a bucket that may already have closed, and a skewed spoke clock filing into a future bucket is the same written-then-unreadable failure. For an aborted request that completion is its abort moment, because `Request_Builder` sets `duration_ms = now - start` at eviction.

On flush, `Flame_Builder_Node` reads each pending bucket's stored value, adds the accumulator to it, caps, and writes it back through the explicit per-bucket `set_*` methods (`set_hourly_bucket`, `set_url_bucket`, `set_leaderboard_bucket`, `set_dimensional_bucket`, `set_category_bucket`, …), then drops the accumulators. Merging by addition is what lets several partitions write the same bucket without coordination; retention is the key's own TTL, so nothing prunes buckets by hand. A write into an hour this process has already folded goes to the coarse hour key instead of a fine bucket nothing would ever read again.

`Stats_Store` is storage-only; it does not own per-request aggregation logic. Its one extension point is the `$mirror` closure, invoked after each successful memcache write so the durable `flame-stats` partition can shadow the same data, which a memcache miss then reads back. Frames buffer until their bucket closes (see [`flame-builder.tsl`](#topologiesflame-buildertsl)).

When no `Stats_Store` is configured (`configure_stats` never called — e.g. in unit tests), the builder still emits flame data without touching memcache.

## Supporting classes

Not every application class is a Node. These carry the algorithms and the read-side vocabulary.

| Class | File | What it owns |
|---|---|---|
| `Flame_Tree` | `includes/class-flame-tree.php` | The pure flame-graph algorithm: stateless tree construction from one request's entries (LIFO span matching), duplicate-sibling numbering so aggregation does not collapse them, incremental sums-not-means merging across requests, and a finalize pass converting sums to averages and normalizing parent ≥ children. `MAX_STACK_DEPTH` and `MAX_RECURSION_DEPTH` are both 50; an aggregate child unseen for `AGGREGATE_EXPIRY_SEC` (3600) is dropped. Each span carries `t`, its start in ms from the request's own, which is what positions it on the x-axis |
| `Flame_Fold` | `includes/class-flame-fold.php` | The merging, RESUMABLE variant of that stack machine — static over a plain-array state, because an in-flight envelope rides the Consumer's checkpoint frame and an object would not come back. Same-name siblings under a parent collapse into one node with `count` / summed `value` / `max`, so a folded envelope costs O(distinct paths) rather than O(messages) |
| `Findings` | `includes/app/class-findings.php` | What is wrong with one stored record, COMPUTED rather than inferred: dominant span (`DOMINANT_SHARE` 0.6), repetition (`REPETITION_COUNT` 50), unattributed time (`UNATTRIBUTED_SHARE` 0.5), entry gap (`GAP_MS` 250), truncation, and "insufficient instrumentation" as a first-class finding. Every finding names where it was measured and what rule edit would act on it, and a proposal's `direction` is as often `more` as `less`. A plain class, not a Node: a pure function of a record, so the picker and an agent produce identical evidence |
| `Ask_Assembler` | `includes/app/class-ask-assembler.php` | The `?` picker's descriptor vocabulary — `url:`, `request:`, `span:`, `entry:`, `category:` — shaped into a brief, bounded by `MAX_ENTRIES` 60, `NEIGHBOURS` 2, `WORST_REQUESTS` 5 and `TOP_SPANS` 6. Shaping only; loading belongs to `Performance_CI_Node`. URLs go through `Log_Manager::redact_url()`, the environment is allowlisted rather than filtered, and `Findings::caveat()` rides every brief |
| `Reqgrep_Core` | `includes/class-reqgrep-core.php` | The rid-grouping / pattern-matching engine `wp nodes reqgrep` and the `performance` CI's `request_grep` verb share, so both agree byte-for-byte on which lines belong to which request and when it is complete. Bounds one request at `MAX_BYTES_PER_REQUEST` (10 MiB) and `MAX_LINES_PER_REQUEST` (20000), or 10000 lines while it is only history |
| `Hook_Categorizer` | `includes/class-hook-categorizer.php` | Hook-to-category mapping from `hook_categories.json` plus operator overrides, the category colours the dashboards render, and `is_internal()` — the list `App\Core` refuses to instrument, because binding one re-enters the `Log_Manager` bootstrap |
| `Diagnostics_Bridge` | `includes/class-diagnostics-bridge.php` | One static listener on `newspack_nodes/stderr`. Every line the substrate emits through `Core::_stderr()` is logged to the ACTIVE request or job context as a `stderr` entry, which `Request_Builder_Node` routes to its errors target and therefore into the Error Log. With no started logger the line is dropped, since the substrate's default handler already `error_log()`s it. Fleet alerts do not come through here — the substrate journals those into `alerts.p0` |
| `Current_Request_Overlay` | `includes/class-current-request-overlay.php` | Registers the `current-request` bundle on the substrate's `newspack_nodes/devtools_tab_bundles` filter for the Nodes hub page, enqueues it itself on the four ELN dashboards that mount `<DebugOverlay>` of their own, and injects THIS request's id into a JS global at `admin_enqueue_scripts` priority 20 |

## Memcache Schema

Per-key prefix: `evlog:p{N}:{namespace}:…`, written inside the install scope the substrate's `Cache_Backend` supplies. That scope is where the salt lives — `Stats_Store` keeps none of its own, so one rotation orphans every Newspack plugin's keys at once.

The retention window comes from the substrate's `min_lifetime` (default 43200), floored at 3600.

| Namespace | Use | TTL |
|-----------|-----|-----|
| `hourly` | 5-min buckets, keyed per bucket, count + sum_ms + sum_peak_mb | `min_lifetime` (default 43200) |
| `lb` | 5-min global leaderboard buckets, sums-not-means | `min_lifetime` |
| `lb_s` | per-server leaderboard, keyed by server | `min_lifetime` |
| `urls` | 5-min URL index, SHARDED `urls:{shard}:{bucket}` by the first hex digit of the url_hash — and by POPULATION, a `w` prefix on the token (`urls:w3:...`) naming worker traffic, which the table excludes by default. Held only for `Stats_Store::FINE_TTL_SECONDS` (4h): the read plan asks for thirteen of these plus the rest of their hour and `urls_h` answers for everything behind that, so the other 264 buckets a shard were the largest thing in the cache with nothing to read them. Keyed by url_hash to a POSITIONAL row indexed by `Stats_Store::ROW_*`. `serialize()` writes every key NAME into every row, so the shape `Message` uses is the shape this takes: measured 672 -> 398 B/row. The eight fields that ADD occupy indexes 0-7 in `URL_SRV_SUMS` order, so one map describes the row's summed half AND its per-server `srv` split, whose values take the same indexes. `ROW_FIELD_NAMES` is the one index-to-name map; `Performance_CI_Node::fold_index_row()` is the one place a stored row becomes the named display row. The row carries NO URL string — the name lives once in `urlmap` and readers resolve only the hashes they show. A split of ONE server whose count is the row's own stores the host name against `null` (`Stats_Store::collapse_sole_server()`), the row restated in ~33 bytes rather than ~112; readers expand it before merging | `min( min_lifetime, 14400 )` |
| `urlmap` | URL name table, `urlmap:{hash}` -> `[ url ]`. The name is 101 of a 166-byte minimal row and was written again in every bucket the URL appeared in, so a retention window held up to 288 copies of one name; here it is written once per URL and re-written only when half its own TTL has passed. `Performance_CI_Node::resolve_urls()` reads it for the rows a response shows — a page, or every candidate when a search term or a url-sort needs the names to answer at all | `min_lifetime` |
| `url` | per-URL flame/profile blob | `max(3600, min_lifetime/24)` |
| `dim` | dimensional time series, keyed per bucket | `min_lifetime` |
| `url_dim` | per-URL dimensional series, keyed per bucket, every dimension in the value | `min_lifetime` |
| `categories` | category time series, keyed per bucket, `$server` scopes it | `min_lifetime` |
| `url_cat` | per-URL category series, keyed per bucket | `min_lifetime` |
| `urls_h` | the URL index's COARSE tier, `urls_h:{shard}:{Y-m-d-H}` — one hour of merged rows in the same positional shape a `urls` bucket holds. DERIVED: `Flame_Builder_Node::roll_up_hours()` folds a closed hour once, and a missing key is answered from that hour's twelve `urls` buckets. Not mirrored, for the same reason | `min_lifetime` |

**All but `url` are bucketed, and the bucket key is always the LAST component.** Every scope (server, URL, dimension) is therefore a pure key prefix, one `lookup_multi` serves them all through `Stats_Store::lookup_buckets()`, retention is the key's own TTL rather than a hand-rolled cutoff pass, and whether a key's bucket is still open is decided from the key alone (`Stats_Store::is_open_bucket()`).

**Caps prevent value-explosion** against memcache's 1MB/value limit:

- `MAX_DIM_VALUES = 20` — the `server` axis takes `MAX_SERVER_VALUES = 128`
  instead (`Stats_Store::dim_cap()`): `server_name` is `SERVER_NAME`, which under
  Apache's default `UseCanonicalName Off` is the client's Host header, so it needs
  a ceiling. It sits far above `MAX_DIM_VALUES` because 20 folded real spokes out
  of a 24-spoke fleet's own picker. See architecture decision 14 for when to raise
  it
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`
- `Flame_Builder_Node::MAX_URLS_PER_SHARD = 2000` — sixteen shards, so a bucket's
  ceiling is 32,000 URLs. Measured against a 1MB `item_size_max` with the widest
  row the schema writes: the client stores 4,000 rows (2.80MB raw) and REFUSES
  5,000 (3.49MB), losing the whole shard

`srv` splits a URL row's SUMMED fields by reporting server and is capped by
`MAX_SERVER_VALUES`, the same ceiling the `server` dimension takes — one
window-merged scalar per server, co-located with the row so one `lookup_multi`
scopes the whole index instead of one get per URL. It is NOT `url_dim`'s
`server` axis, which is a per-bucket `{c,s,m}` time series; neither can produce
the other (decision 14). Extremes are absent from
it: `URL_SRV_SUMS` and `sum_entry()` only ADD, so a server-scoped row keeps the
URL's own `min_ms` and `max_ms` across every server. Because every split
field adds, the scope is applied as a projection over the merged row
(`Stats_Store::swap_url_server_sums()`), which is what lets one unscoped read serve
every scope a request asks for — and is also where the split's indexes become
names, for that one server, since no reader ever displays it. See architecture
decision 14.

Overflow rolls into a synthetic `Other` bucket — including the URL index itself, whose `MAX_URLS_PER_SHARD` tail folds into an `Other` ROW rather than dropping, so a total summed from the index is exact. The `total` pseudo-category is preserved before sorting — see the existing `Flame_Builder_Node` implementation; do not regress when porting.

**`get_multi` batching is essential.** Reader paths multi-get across the retention buckets in one round-trip (`Stats_Store::lookup_buckets()`), bounded to `Performance_CI_Node::INDEX_READ_CHUNK` (12, an hour of fine buckets) at a time so the whole window is never resident at once. Per-key `get` is a latency cliff. The shared `Core::$memd` (`\Memcached`) provides `getMulti` natively; the in-memory test double mirrors it.

## Stats_Store: Sums-Not-Means + Salt Rotation

**Sums-not-means storage**: leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket and cross-partition merge is exact addition. The display layer computes means at read time.

Do NOT regress to incremental averages: they look mathematically equivalent but break under merge. Nothing stores a percentile for the same reason — a percentile does not merge, so a fold over N buckets cannot produce the window's. `min_ms` and `max_ms` stay and stay exact, because they fold from `duration_ms` directly.

**Schema migration IS salt rotation, and it lives in the substrate.** `\Newspack_Nodes\Cache_Backend::rotate_salt()` writes a fresh salt into `newspack_nodes_cache_salt`, which moves the install scope every Newspack plugin's keys hang from. Old keys orphan instantly and expire on their own TTL: no scrubber walk, no large memcache scan. Plugins deliberately keep no salt of their own — with three independent rotations, flushing one would leave the other two serving stale values.

```bash
wp nodes memcache flush     # rotate the salt, then restart the workers
```

Nothing in the code compensates for skipping it: no reader or writer carries a shape probe, a version component or a row-level legacy test. The scope is memoized per process, so a long-running worker keeps writing the OLD prefix until it respawns — which is why the CLI restarts workers after rotating (best-effort, warning on failure), and why the admin "Flush Caches" button does the same. Skip the rotation and the dashboard reads garbage for one retention window; that is an operator error with a one-command fix. The rotation also takes every issued session, so reissue any you were using.

**Memcache failure asymmetry** (deliberate; preserve verbatim):

- **Stats path: fail-SOFT.** Every `Stats_Store` method returns `null` / `[]` / `false` when memcache is unreachable; never throws. Dashboards show "no data" instead of erroring.
- **SSE slot path: fail-CLOSED.** Caller's policy. Memcache down -> new connections get HTTP 429 (preserves rate-limit invariant). The slot pool IS the rate limit — losing it cannot fall through silently.

## Hub vs Spoke Topology

Aggregator runs hub-and-spoke across multiple WordPress sites:

```
+----------------+   +----------------+   +----------------+
|  Spoke 1       |   |  Spoke 2       |   |  Spoke N       |
|  newspack-     |   |  newspack-     |   |  newspack-     |
|  event-logger- |   |  event-logger- |   |  event-logger- |
|  nodes (spoke) |   |  nodes (spoke) |   |  nodes (spoke) |
+--------+-------+   +--------+-------+   +--------+-------+
         | SSE              | SSE                | SSE
         +------------------+--------------------+
                            v
              +-----------------------------+
              |  Hub                        |
              |  newspack-event-logger-     |
              |  nodes (hub)                |
              |  aggregator + hub-control   |
              |  topologies active          |
              |                             |
              |  substrate Remote_Source    |
              |  nodes pull each spoke      |
              |                             |
              |  Remote_Job_Rewrite_Node    |
              |  flips job -> remote_job    |
              |                             |
              |  hub-control: Settings_Sync |
              |  + Discovery_Collector      |
              +-----------------------------+
```

**Hub identification**: a site acts as a hub when the `aggregator` topology is active — either selected directly in the substrate's Topologies multi-select, or pulled in by an active topology that `include`s it — and, for settings/discovery fan-out, `hub-control` too. `Config::resolve_eln_token( 'is_hub' )` computes exactly that, walking the active topologies and their include trees. The answer is memoized, and the derivation is re-entrancy-guarded: a topology naming `<eln:is_hub>` in a `set_*_target` line resolves its tokens through here, so an unguarded walk would recurse until PHP died. There is no operator hub toggle: hub-mode is derived purely from topology membership. Push-side fan-out (the `hub-control` topology's `Settings_Sync` + `Discovery_Collector` nodes) is structurally gated the same way: without `hub-control` active and per-spoke `HTTP_Out` nodes wired, the settings/discovery pipeline is inert.

**`k:"job"` vs `k:"remote_job"`**:

Nodes only ever write `k:"job"` to their own firehose — there's no "spoke vs hub" distinction at write time. The distinction emerges at dispatch:

- Every node's Job_Worker_Node tails its own `jobs.pN` and dispatches `k:"job"` entries against the `newspack_nodes/job_handlers` filter. This runs locally on every node, hub or spoke.
- The hub additionally runs the `aggregator` topology: per-spoke substrate `Remote_Source_Node`s pull each spoke's firehose, and ELN's `Remote_Job_Rewrite_Node` (wired between the sources and the firehose `Topic`) rewrites the ingested `k:"job"` lines to `k:"remote_job"` before they reach the hub's firehose. The hub's Job_Worker_Node then dispatches those entries against `newspack_nodes/remote_job_handlers`.
- The two filters are independent registrations — a job type registers under whichever side(s) should run it:
  - **`job_handlers` only** → runs locally on every node. The hub's view of spoke-aggregated copies (as `remote_job`) is ignored.
  - **`remote_job_handlers` only** → only the hub acts. Spokes write the entries but don't act on them; the hub does the centralized work after aggregation.
  - **Both** → handler runs locally on every node, AND runs on the hub for entries aggregated from spokes. Useful when a job needs local + centralized handling under the same name (the two handler implementations can differ — e.g., one filters by local attributes, the other dispatches differently).

The rewrite is NOT in a spoke's graph. A spoke runs neither the `aggregator` topology nor `Remote_Job_Rewrite_Node`, so its own `k:"job"` entries dispatch locally — exactly what spokes want.

## Hub-Side Settings Sync, Discovery, and Vault

The hub's outbound side is three nodes:

- **Spoke credentials live in the substrate Vault.** The substrate `\Newspack_Nodes\Vault` (managed through the substrate `vault` CI) stores each spoke's URL + credentials, encrypted at rest under `wp_salt('auth')`. A `Remote_Source` node references its spoke by `<vault-id>`; SSL/HTTPS enforcement comes from the substrate `vault_verify_ssl` / `vault_require_https` config. There is no `aggregator_servers` option and no `Server_Registry` CRUD anymore. Reacting to a credential change is the substrate's job, not this plugin's: `Bootstrap` restarts every active topology whose graph declares a `Remote_Link` / `Remote_Source` node, so whichever worker holds those credentials picks the change up without waiting out its ~10-minute respawn.

- **Settings fan-out is a node graph (`hub-control` topology).** The substrate's `Settings_Event_Writer` records watched WP-option changes to the `settings.p0` log (option name only). The `hub-control` topology's `Consumer` tails it, and the substrate `\Newspack_Nodes\Settings_Sync_Node` reads each option's current value at consume time and emits one `set` command per registered option per spoke, re-pushing the full registered set on its 300s tick. Each command is signed for its own spoke, so the node fans out itself rather than through a Tee. ELN supplies the value-resolver via the `newspack_nodes/settings_sync/value` filter (`newspack_event_logger_nodes_resolve_settings_sync_value`), which substitutes the OWNING config's default for a blank or absent value — `newspack_nodes_*` keys resolve against the substrate's defaults, whose `remote_*` geometry differs from the hub's, and everything else against this plugin's — and runs the ruleset through `Rule_Set::hydrate_array()` so pointer rules ship hook-complete.

- **Discovery is `Discovery_Collector_Node`** (also in `hub-control`) — see [Discovery_Collector_Node](#discovery_collector_node). It fans `discovery.get` to every spoke on its 300s tick and union-merges the replies into the hub's staging options.

## Settings Sync: No Operator Gate

Settings fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology (see [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)). It is **ungated** in the structural sense: an option change always records a settings event (via the substrate `Settings_Event_Writer`), but nothing fans it out unless the `hub-control` topology is active and per-spoke `HTTP_Out` nodes are wired. On a spoke or standalone site there is no consumer, so the event is tailed and dropped.

`Auto_Tuner_Node` is ungated in the same sense: it persists through `Rule_Set::save()` with no `remote_manager` job and no `suppress_sync` flag. The write records a settings event like any admin edit; if `hub-control` is running, `Settings_Sync_Node` picks the change up at consume time. There is no operator hub toggle: hub-mode is derived from whether the `aggregator` / `hub-control` topologies are active. Letting the no-consumer drop happen at the node-graph level is cheaper and harder to misconfigure than a per-listener `get_option` gate.

Ungated is not unauthorized. `Auto_Tuner_Node::authorized()` still requires a worker context or `manage_options` before it will mutate a rule — the structural gate governs *where the change propagates*, the capability check governs *who may make it*.

**Value resolved at consume time, not at write time.** `Settings_Event_Writer` records only the option *name*; `Settings_Sync_Node` reads the current value when it consumes the event (and on its 300s tick re-reads and re-pushes every registered option). So a burst of writes to one option collapses to a single current-value push, and there's no stale-value race. ELN supplies the name→value mapping through the `newspack_nodes/settings_sync/value` filter.

## Job ingress and routing

`Job_Intake` lives in the newspack-nodes substrate as `\Newspack_Nodes\Job_Intake`. Substrate-only installs drain it via the stock `job-intake` topology, which ELN's `job-router.tsl` and `job-feed.tsl` both `include` and re-route. Activating the stock topology beside either is refused by the conflict gate, since all three declare `jobs:partition` with the warranty voided.

Three producers write to four ingress logs:

```
+---------------------+  +---------------------+  +---------------------+
| Log_Manager         |  | Job_Intake::feed()  |  | Job_Intake::queue() |
| k:"job" in firehose |  | small jobs, no lock |  | large jobs, locked  |
| per-request, <4KB   |  | <4KB                |  | up to 32 MiB        |
+----------+----------+  +----------+----------+  +----------+----------+
           |                        |                        |
           v                        v                        v
   /logs/firehose.pN/       /logs/jobfeed.pN/        /logs/jobintake.pN/
        (atomic append, PIPE_BUF)                    (auto-locked writes)
           |                        |                        |
           |                        |    a future not_before parks in
           |                        |    /logs/jobdelay.p0/ until due
           |                        |                        |
           +-----------+------------+------------------------+
                       v
                Job_Router_Node
              (job-router reads the firehose;
               job-feed reads jobfeed; both read jobintake)
                       |
                       v
                Age_Sieve (drops > 900s)
                       |
                       v
                jobs.pN per partition
                       |
                       v
                Job_Worker_Node
                       |
                       v
                registered handlers
```

**Use the firehose** (`Log_Manager->message( 'job', $payload )`) when:

- The payload fits in 4KB (PIPE_BUF atomic append).
- The job must be aggregated from spokes to the hub — only firehose entries flow through the hub's `Remote_Source` pull.

**Use `Job_Intake::feed( $handler, $id, $parameters )`** when the payload fits in PIPE_BUF and the job is local-only. It costs no lock, and it lets the site run `job-feed` instead of `job-router`, so the job reader tails jobs alone rather than every log entry of every request.

**Use `Job_Intake::queue( $handler, $id, $parameters )`** when:

- The payload exceeds 4KB (serialized option blobs, image-handler args, large arrays).
- The job is local-only — neither intake log aggregates; entries stay on the originating site.

Using the wrong path loses jobs. `Log_Manager` trims `m` on data over `MAX_DATA_SIZE` (3840B), so a stripped body arrives carrying no handler and reads at the router as an invalid-handler warning. Neither intake log aggregates, so a spoke job written there never reaches the hub.

Job_Intake has three partition-selection modes:

- **pinned** — the caller names the partition through `$intake->partition( $i )`.
- **keyed** — `Partition_Node::hash_to_partition( $key, $num_partitions )` (URL-style, identical to the firehose).
- **round-robin** — a static counter modulo `PHP_INT_MAX`, for callers with no meaningful key.

A job with a `not_before` (or a `delay`) in the future skips all three and parks in `jobdelay.p0` until `Job_Delay::sweep()` circulates it. Beyond scheduling, `write_job()` accepts `retries` and `attempt` (Job_Worker backoff), `batch` (batch settlement), and `unique` + `unique_ttl` (enqueue dedup within a window, which needs a cache backend). An unknown option key throws rather than being ignored, so a typo stays loud.

Lock-holding is per-Partition; there is no host-wide intake lock. `Partition::allow_large_writes()` acquires the partition's write lock at construction and holds it for the partition's lifetime, admitting writes up to `MAX_JOB_SIZE` (32 MiB); it waits 15s and throws when a live writer still holds it, which `queue()` reports as `false`. One-off callers construct a one-shot `Job_Intake`, write, and let the destructor release the lock; batch callers reuse one instance across many `queue_many()` calls so the acquisition cost amortizes.

## Configuration

Four layers, weakest first — later wins:

1. **Schema defaults**, declared in code by `Settings_Schema`, one per `Field`.
2. **The shipped config file**, `newspack-event-logger-nodes-config.php`. A commented ledger returning `[]`, each of the nine keys listed beside the default the schema declares. It is an OVERRIDE surface, not the definition, and a deploy copies the operator's own copy over this path. A key the schema does not declare is reported to stderr and ignored — never registered, never thrown, because the profiler drop-in reads config at `plugins_loaded:-10001` and a throw there would take wp-admin down with everything else.
3. **`LOCAL_NEWSPACK_NODES_CONF`**, a second file named by that environment variable and canonical-path-validated before it is required. An unusable path throws rather than silently leaving the site on defaults.
4. **WordPress options** under the `newspack_event_logger_nodes_*` prefix (application) and `newspack_nodes_*` prefix (substrate). Each key follows its owning plugin: ELN applies its option overlay to ELN-owned keys, while effective substrate values arrive after the substrate applies its own overlay. The overlay is presence-based, so a stored `''` / `[]` / `false` / `0` beats both files.

```php
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // every schema key, files + WP option overlay + substrate merge
$config = \Newspack_Event_Logger_Nodes\Config::load_config_defaults(); // files only (no WP option overlay, no substrate merge)
$value  = \Newspack_Event_Logger_Nodes\Config::value( 'log_memory' );  // fail-loud single-key read
```

`load_config()` is the single zero-arg entry point — every key in the `Settings_Schema` whitelist is loaded on every call, including the `rules` overlay key and the substrate keys merged in from the substrate's own `Config::load_config()`. The result is memoized for the process, so the cost is one round of `get_option` per schema key on first call; `Config::reset()` (or the substrate's `newspack_nodes/config_reset` action, which `reset_local_cache()` listens on) clears it. It returns an UNMEMOIZED empty array when the substrate is absent, because this plugin loads first and a caller reading too early must get the real map on its next read.

**Read single keys through `Config::value()`.** It throws on a key no registered schema declares, so a renamed or typo'd key fails at the boundary instead of limping on a `?? default`. `Config::register_config_keys()` publishes this plugin's overlay keys to the shared substrate registry, hooked to `newspack_nodes/declare_config_keys` so the substrate re-pulls them after every `Config::reset()`.

### Settings_Schema: one Field per setting

`Settings_Schema` (`includes/class-settings-schema.php`) is the single declaration both `Config` and `Admin` derive from — one `\Newspack_Nodes\Config_System\Field` per setting, collected into a `Schema`. `Config` reads it for the overlay key-list; `Admin` reads it to drive the `register_setting` / `add_settings_field` loops, the reset list, and the delete-on-blank classification. Labels and section titles are lazy `fn(): string` thunks, so building the Schema for `overlay_keys()` on a frontend request never calls a translation function.

Substrate keys (`base_directory`, partitioning, `memcache_servers`, `topologies`, and the `remote_*` spoke geometry) are owned by the substrate's own Settings_Schema under `newspack_nodes_*`; ELN imports their effective values only after removing ELN-owned names, so each plugin's option namespace remains authoritative. Substrate keys are never declared here. Spoke credentials live in the substrate **Vault**.

### Application option keys

The nine schema keys all take an option overlay under `newspack_event_logger_nodes_`. Three render as checkboxes on the settings page; the rest are `ui: false`, pinned from a config file or written by a service CI.

| Option | Type | Surface | Default | Use |
|--------|------|---------|---------|-----|
| `enable_logging` | bool | checkbox | `true` | Master switch for the firehose write path. Off, the workers keep running — this gates the request-side writer, not the topologies |
| `log_memory` | bool | checkbox | `false` | Append `peak_mb` to every `(complete)` and `(aborted)` entry |
| `flush_every_line` | bool | checkbox | `false` | Flush the firehose Topic after every entry — survives a crash, costs one write per entry |
| `rules` | array | `ui:false`, written by the `rules` CI | five baseline skips + a `/` log | The per-URL logging **ruleset** — see [Per-URL logging ruleset](#per-url-logging-ruleset). Seeds `Rule_Set` until the editor writes the option |
| `allowed_users` | array of strings | `ui:false` | `[]` | A `user_login` allowlist OVER the `manage_options` gate; empty admits every user who holds the capability |
| `hook_start_priority` | int | `ui:false` | `-10000` | Priority `App\Core` binds `hook_start` at. `hook_complete` is always `PHP_INT_MAX - 1`, so a lower number widens the measured span |
| `stats_mirror_node` | string | `ui:false` | `flame-stats:partition` | Node name of the durable stats-mirror partition; a memcache miss reads the frame back from it. `''` disables the mirror |
| `custom_colors` | array | `ui:false` | `[]` | Custom-event name → hex swatch, filtered through `newspack_event_logger_nodes_custom_colors` and merged with the events spokes reported. Hook-category colours are a different thing entirely — they come from `hook_categories.json` |
| `recommended_log_events` | array of strings | `ui:false` | curated list | Hook names the settings picker stars; its "Recommended" button REPLACES the current selection with them. A menu, not an instruction — nothing here binds anything |

Two further options sit outside the schema entirely. `discovered_hooks` and `discovered_events` are non-autoloaded staging options `Discovery_Collector_Node` writes and the editor's hook picker reads; `load_config()` never touches them, and `Config::autoload_for()` is the single place that says so — their values grow with the fleet, so keeping them out of `alloptions` is what stops every frontend request paying for them.

`enable_logging`, `log_memory` and `flush_every_line` all carry `restart: 'all'` — `Log_Manager` caches each in its per-process singleton, so a change needs every worker restarted before it takes effect.

Per-URL/per-hook logging and auto-tune settings are **per-rule** — each LOG rule carries its own `hooks`, `custom_events`, `significant_events`, thresholds and diagnostic switches.

Spoke credentials and SSL/HTTPS policy live in the substrate **Vault** (`vault_verify_ssl` / `vault_require_https` on the substrate side). The remote-pull segment geometry (`remote_segment_size` / `remote_min_segments` / `remote_num_segments` / `remote_min_lifetime` / `remote_lifetime` / `remote_max_segments`) moved to the substrate's `newspack_nodes_*` namespace too.

### Substrate option keys (read but not owned here)

`Config::load_config()` also reads the substrate's `newspack_nodes_*` namespace for keys that affect application behaviour:

| Substrate option | Use here |
|------------------|----------|
| `base_directory` | Root for `logs/`, `offsets/`, `locks/`, `ipc/` |
| `num_partitions` | Topic / Partition fan-out, must match the topology fleet count |
| `segment_size`, `min_segments`, `num_segments`, `max_segments` | Partition geometry and count-based retention |
| `min_lifetime`, `lifetime` | Age-based retention; `min_lifetime` also sets the `Stats_Store` TTL window |
| `memcache_servers` | Stats_Store + SSE slot pool backend |
| `sse_slot_ttl`, `sse_max_streams`, `sse_max_slots`, `sse_reserved_slots` | The SSE slot-pool budget |

Per-key documentation lives in the substrate; this plugin treats them as read-only.

### Hot reload

Most config keys read through `Config::load_config()`, whose static cache lasts one process. A frontend request therefore picks a change up on its next request; a long-running worker does not. Restart is required for:

- `enable_logging`, `log_memory`, `flush_every_line` — cached in `Log_Manager`'s per-process singleton (`restart: 'all'`).
- `num_partitions` — wired into Topic/Partition construction at worker bootstrap; changing it requires `wp nodes restart` of all topology workers.
- `memcache_servers` — the shared `Core::$memd` holds the one connection, built once at boot.
- Salt rotation — each worker memoizes the install scope at boot, which is why `wp nodes memcache flush` restarts them.

## Hooks and filters

**Fired by this plugin:**

| Hook | Type | Fired where |
|---|---|---|
| `newspack_event_logger_nodes_scope_changed` | action | Both ends of a job context (`begin_job_context` / `end_job_context`); `App\Core::rebind_for_current_scope()` listens |
| `newspack_event_logger_nodes/settings_after_form` | action | Below the settings form; the rules editor mounts at priority 5, then the effective-config and maintenance sections |
| `newspack_event_logger_nodes_custom_colors` | filter | `Config::get_custom_colors()`, applied lazily so a plugin loading after this one can still register its events |

**Consumed from the substrate:**

| Hook | Callback |
|---|---|
| `newspack_nodes/declare_config_keys` | `Config::register_config_keys` — registered at file load, ahead of the deferred bootstrap |
| `newspack_nodes/config_reset` | `Config::reset_local_cache` (not `reset()`, which would re-enter the substrate) |
| `newspack_nodes/job_worker/before_job` (filter) | `Log_Manager::begin_job_context_filter` |
| `newspack_nodes/job_worker/after_job` (action) | `Log_Manager::end_job_context` |
| `newspack_nodes/before_reconcile` / `after_reconcile` | An anonymous pair sharing an `$entered` flag, so an unmatched `after` never pops a snapshot it did not push |
| `newspack_nodes/settings_sync/value` (filter) | `newspack_event_logger_nodes_resolve_settings_sync_value` |
| `newspack_nodes/registered_log_producers` (filter) | `newspack_event_logger_nodes_register_log_producers` — adds the firehose dir template, so the log GC declares and the Workers dashboard catalogs the dirs `Log_Manager` writes with no topology Partition behind them |
| `newspack_nodes/stderr` | `Diagnostics_Bridge::on_stderr` |
| `newspack_nodes/request_graph_ready` | `newspack_event_logger_nodes_mount_service_cis` |
| `newspack_nodes/devtools_tab_bundles` (filter) | `Current_Request_Overlay::register_bundle` |

**Read but never bound here:** `newspack_nodes/job_handlers` and `newspack_nodes/remote_job_handlers` are the substrate `Job_Worker_Node`'s registries — `Job_Router_Node` never reads either. `newspack_nodes/periodic` is the substrate's minute-cadence pass, which this plugin's job contexts bracket but do not subscribe to.

**WordPress hooks consumed:** `plugins_loaded` (11) for the deferred bootstrap, `rest_api_init` for the MCP route, `admin_menu` and `admin_enqueue_scripts` for the dashboards, `admin_init` and `admin_post_newspack_event_logger_nodes_reset_settings` for the settings page, `updated_option` / `added_option` / `pre_update_option` for restart classification, and — per governing rule — `pre_http_request`, `http_api_debug`, `query` and `log_query_custom_data` in `App\Core`.

## REST + React

There are two REST surfaces, and one of them is a single route.

The dashboards and admin tooling reach the application through the substrate's **command protocol**: one `POST /wp-json/newspack-nodes/v1/command` endpoint that routes a TM_COMMAND envelope to a named service CI node. This plugin owns three CIs — `performance`, `discovery` and `rules` — mounted on `newspack_nodes/request_graph_ready`; `status`, `settings`, `topologies`, `workers`, `aggregator`, `vault`, `sessions`, `classes`, `layouts` and `raw-logs` are substrate-owned. `Service_CI_Node` wraps each handler in `Capabilities::require()` for the role the verb declares, so no handler re-gates itself — a hard-coded gate would silently outrank its own declaration. Each verb's request/response shape is documented in [API.md](API.md).

There is no per-endpoint controller hierarchy. The whole `includes/rest/` directory was deleted, and `tests/integration/M2BootstrapTest.php` carries a regression guard so a revert that reintroduced it would fail.

**`performance`** — the dashboards' read surface, plus one write:

| Verb | Role | Answers |
|---|---|---|
| `overview` | READ | Site-wide totals and the aggregate time series, from the `hourly` namespace. It no longer touches the URL index at all |
| `urls` | READ | The URL leaderboard — filters, page, totals, averages, rate and the slowest ten, so the header and the table beneath it cannot disagree. Worker traffic is excluded unless `include_workers` opts in |
| `url_detail` | READ | One URL: stats, aggregate flame data, breakdown and category series, and its recent requests |
| `url_breakdown` | READ | One URL, one dimension — what the modal's dropdown switch asks for |
| `request_search` | READ | Locate a request by id: `{ rid, partition, url_hash }` |
| `request_grep` | READ | Pattern-search recent traffic, returning matching requests rather than lines |
| `request_detail` | READ | One request in full, with its flame data and computed `Findings` |
| `ask` | READ | The brief for one descriptor — `url:`, `request:`, `span:`, `entry:` or `category:` |
| `hooks_registered` | READ | The hook catalog behind the settings picker |
| `set` | TUNE | Write one whitelisted option; `Settings_Sync_Node` fans it out. `Rule_Set::OPTION_RULES` routes to `Rule_Set::apply_synced()` instead, which re-tiers and holds its own gate |

Its bounds are worth knowing before reading a reply: `MAX_INDEX_ENTRIES` 1,000,000, `GREP_MAX_SCAN_LINES` 200,000, `RECENT_REQUEST_LIMIT` 500, `RECENT_BUCKETS` 12 (the thirteenth is still filling and is dropped), `SLOWEST_ROWS` 10, `INDEX_READ_CHUNK` 12. `DIMENSIONS` is `status, method, server, country, from, ua, ja4`; `URL_SORTS` is `count, url, avg_ms, min_ms, max_ms, avg_peak_mb, last_updated`.

**`rules`** — `list` (READ), and `save` / `upsert` / `delete` / `reset` (TUNE), all routed through `Rule_Set`. A JSON argument is bounded at `MAX_JSON_BYTES` 65536 and `MAX_JSON_DEPTH` 12.

**`discovery`** — one verb, `get` (READ), returning `{ registered_hooks, custom_events }`: the union across every LOG rule, with custom-event names filtered out of `registered_hooks` so the picker's two catalogs stay disjoint. It reports the ruleset and never writes it. Two callers reach it — the hub's `Discovery_Collector_Node`, and the substrate's `vault` CI probing one spoke's connection.

### MCP: one route, ten tools

`MCP_Controller` mounts `POST /wp-json/newspack-event-logger-nodes/v1/mcp`, a JSON-RPC MCP server (protocol `2025-06-18`) wrapping verbs that already exist. It adds none: one tool per verb, arguments through `Command_Args`, replies verbatim. Every JSON-RPC method rides that one POST — `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.

The point is an "Ask AI" button that hands an agent the data rather than a summary. An in-plugin LLM call would ship faster and buy a dashboard that summarises itself to one model behind one proxy publishers cannot reach, and it could not see a Linear issue at all; exposing the data lets an agent that already holds those context providers do the correlation.

Authorization has two halves and needs both. A `Bearer <handle>.<key>` names a live session; on success the controller BECOMES that session's minting user and installs its scope as the request's ceiling. The scope is subtractive, never a grant — a manage-scoped session minted by someone who can do nothing still does nothing — and `tools/list` offers only what the scope covers. `Bootstrap::fleet_gate()` runs first, so a subsite cannot reach the main site's fleet. Because MCP does not go through `/command`, the substrate's per-user cap does not bound it: `RATE_LIMIT_BURST` 20 per `RATE_LIMIT_WINDOW_S` 10 does, checked after the credential so an unauthenticated flood cannot poison the store.

| Tool | Verb | Role |
|---|---|---|
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

`hooks_registered`, `url_breakdown`, `performance.set` and the `rules` `save` / `reset` verbs are deliberately absent. `Findings::caveat()` rides EVERY tool description, not just the first read, because a model handed a profiled/duration ratio without one will invent a cause for the difference.

### Real-time path: `/messages/stream` + slot pool

SSE is a single substrate surface: the substrate's `\Newspack_Nodes\Rest\SSE_Out_Node` doubles as the `GET /wp-json/newspack-nodes/v1/messages/stream` controller. A client subscribes to one or more partitions or globs (`?subscribe=errors.*`), `SSE_Out_Node` runs the drain loop, and emits a 7-field message envelope per line plus an idle `heartbeat` event when no data flows. The browser dashboards and the hub-side `Remote_Source_Node` cross-server pull all consume `/messages/stream` directly.

```
+-----------------+   GET /messages/stream?subscribe=errors.*      +-----------------+
|  Browser opens  | ---------------------------------------------> |  SSE_Slot_Pool  |
|  EventSource    |        (acquire slot; fail-CLOSED 429)         |  (substrate, mc)|
|  (RemoteLink's SseIn child)                                     +-----------------+
+-----------------+                                                       |
        |                                                                 |
        | < emit `connected` envelope (carries slot id)                   |
        v                                                                 |
  SSE_Out_Node drain loop (substrate):                                    |
    flush each partition's new messages as 7-field envelopes              |
    emit idle `heartbeat` when no traffic                                 |
    flush before the framework sleeps (per-tick, not per-event)           |
        |                                                                 |
  RemoteLink's Heartbeat child POSTs the keepalive to refresh its slot ---+
  If the tab dies, the slot expires and the next slot check drops it.
```

**Slot pool ownership**: the substrate owns the slot pool (`\Newspack_Nodes\SSE_Slot_Pool`); `SSE_Out_Node` calls it before headers and returns HTTP 429 when the pool is full or memcache is unreachable. The slot pool IS the rate limit; **memcache failure fails CLOSED** (429), the asymmetric flip side of the stats path's fail-soft behavior. Stats can degrade to "no data" gracefully because the dashboard is read-only; SSE streams ARE the live workload, and dropping the rate limit would let one runaway client saturate the worker pool.

Every lease takes the same TTL — `sse_slot_ttl`, floored at three `Remote_Link_Node::HEARTBEAT_INTERVAL`s (45 seconds), because only an owner-matched heartbeat refreshes a lease and a client that loses its session stops heartbeating for the whole re-auth round trip. What differs between callers is headroom, not lifetime: a browser leaves `sse_reserved_slots` of the host cap untouched, while a machine pull (`Remote_Source_Node`, which marks itself with the `X-Newspack-Nodes-Pull` header) may take the whole pool. The per-client heartbeat refresh is the invariant: only the client refreshes a slot's TTL; the server-side slot check is check-only and never refreshes on check, so each client's TTL must outlive its own heartbeat interval. Don't reintroduce server-side refresh-on-check.

### React trees

Six bundles ship out of `src/`. Five are admin pages the substrate's `enqueue_react_page()` mounts by directory name — `overview` (Performance), `error-log`, `gyroscope`, `requests` (Request Log) and `settings` — and the sixth is the `current-request` debug-overlay tab, registered on the substrate's `newspack_nodes/devtools_tab_bundles` filter for the Nodes hub page and enqueued directly on the four ELN dashboards that mount `<DebugOverlay>` themselves. The `rules` editor is not a seventh bundle: it is a React root the `settings` tree mounts into the settings page's "Logging Rules" section.

The three streaming dashboards (`error-log`, `gyroscope`, `requests`) share one shape. Each mounts a substrate `RemoteLink` node, which composes and registers three children of its own — an `SseIn` ingress, an `HttpOut` `/command` boundary, and a `Heartbeat` slot keep-alive — and wires the `connected → slot` bridge internally. The link takes the subscribe glob as its only constructor token (`errors.*`, `gyroscope.*`, `completed.*`), targets a pass-through `Tee`, and the Tee copies each frame to a single view-model node that shapes envelopes into rows inline.

Three hooks in `src/hooks/` sit on top of the substrate's:

- **`useGlobStreamGraph`** declares one dashboard's whole graph. The backbone, the pause/visibility gate, the recorded reopen target and the paused single-step are the substrate's `useStreamGraph` and `useSteppedRead` (which asks the substrate `raw-logs` CI's `read_message` for a paused Step); `useStreamGraph` mounts via `mountExospine`, snapshotting Core so soft nodes tear down and rebuild on "Reset Graph", closes the stream while the tab is hidden or paused, and reconnects from the last seen offset on refocus.
- **`useGlobBrowse`** is the two-level pick a glob needs: an empty selection is the whole glob tailed live, and a concrete dir such as `errors.p3` narrows to one partition and brings the segment rail with it — the substrate's `useSegmentBrowse`. A sole partition auto-selects, since one dir already IS the glob. Seeks ride the existing `positions` transport, keyed by partition directory, so no new server verb is needed; the substrate's `raw-logs` CI answers both catalog questions.
- **`useMultiSelect`** holds the pending selection for the settings selector modals — the hook picker and the custom-event picker differ only in what they list, so the chosen Set, the reset on open and the apply live here.

The command-driven surfaces (`overview`, `settings`, `rules`) mint each awaited verb FROM its own `Request` node and let the reply route back `TO = FROM`.

Shared hooks, utils and components are NOT copied into this plugin — there is no local `src/shared/` tree. Three build aliases resolve to the `newspack-nodes` sibling checkout, through the one `src/build-kit/alias-map.cjs` esbuild and jest share: `@newspack-nodes/runtime`, `@newspack-nodes/shared` (with subpaths, e.g. `@newspack-nodes/shared/hooks/usePageVisibility`), and `@newspack-nodes/debug-overlay`. The resolver refuses to guess a base — a wrong-but-plausible default is how a build silently resolves the substrate to the wrong checkout.

### Canonical view contract

Every command-driven dashboard view follows the same contract, and the whole of it rests on one fact: **the addressing IS the correlation.**

- **No correlator.** Each awaited verb is minted FROM its own receiver node, and the server echoes `TO = FROM`, so a reply lands on exactly the node that asked. `message[ID]` stays empty. There is no id, no `replies` map, no promise registry and nothing keyed by one — sending several verbs in one tick means more nodes, never one node telling replies apart. Both interpreters also echo the verb and its arguments into the response VALUE, so a reply says which command it answers.
- **One send per `run()`.** `useCommandOnce` parks arguments in the Fetcher's outbox — the same outbox a polled slice throttles itself with — and the next fan-out sends them. `onDone` registers on the result node rather than reading rendered state, so two replies arriving in one batch are two notifications even though one re-render carries only the last.
- **Retry is for READS.** An idempotent read re-asks a request that went MISSING — never a refusal, which is an answer — and a second `run()` supersedes rather than queueing, because nobody wants the first answer once a second question is open. A write neither retries nor supersedes: an unanswered write may already have applied, and two rows deleted in the same second are two commands that both have to go.
- **A superseded ask fills nothing.** Where a reply carries no key to look it up by, the SUBJECT is the address: `subjectOf` makes the URL modal's breakdown subject the (URL, dimension) pair, so a dimension the operator has already switched away from matches no outstanding ask, fills nothing and retires nothing.
- **Refusals are per-call, never a table-wide banner.** A refusal reaching one caller surfaces where that caller can act on it — a row-level snackbar, an in-form notice — and leaves the previously-loaded rows intact.
- **Every mutation re-reads.** A successful `save` / `upsert` / `delete` / `reset` re-`list`s, so the table repaints from the server rather than from a locally patched copy; a refusal leaves the server unchanged, so it repaints nothing.
- **Three states, not two.** A panel driven by a fetch distinguishes `pending` (no series in hand), `empty` (one arrived carrying no values) and `series`. Only `series` draws. Collapsing the first two is how "No User Agent data" flickers during a fetch, and falling back to the totals answers a question nobody asked.
- **No dead REPL mounts.** Mount what the dashboard actually needs — the link, the Tee, and the view.

## CLI: wp nodes reqgrep

Application-side firehose filter. The substrate ships `wp nodes status` / `wp nodes cli` (worker introspection); this plugin adds two commands under the same namespace: `wp nodes reqgrep`, for searching the live firehose by URL pattern, request ID or arbitrary content match, and `wp nodes ruleset-bench`, which measures the ruleset's two hook-storage tiers — inline against Table-pointer — and is what `Rule_Set::INLINE_HOOK_LIMIT` is calibrated from.

```bash
# Every segment, no filter.
wp nodes reqgrep

# Pattern match (URL substring, request ID, or category).
wp nodes reqgrep /calendar
wp nodes reqgrep 5f8e3a1c2

# Only the second-to-last segment and newer (fast lookup).
wp nodes reqgrep /calendar --recent

# Follow mode: tail all partitions continuously.
wp nodes reqgrep --follow

# Combine: follow + filter.
wp nodes reqgrep pyrobase --follow

# Raw JSONL instead of formatted output.
wp nodes reqgrep /calendar --raw

# Show requests that reached neither `process (complete)` nor `process (aborted)`.
wp nodes reqgrep pattern --incomplete

# Widen the history buffer (default 250 lines × 10 buckets; clamped to 1-10000 and 1-100).
wp nodes reqgrep pattern --bucket-size=1000 --num-buckets=20

# Read a specific firehose dir (must sit inside the configured logs dir).
wp nodes reqgrep pattern --firehose=/tmp/newspack-nodes/logs/firehose.p0

# Pipe stdin instead of firehose files. Stdin wins: --follow and --recent are ignored.
cat archived.log | wp nodes reqgrep pattern

# Measure the two hook-storage tiers (default 200 timed iterations per grid cell).
wp nodes ruleset-bench --iterations=500
```

Implementation lives in `includes/cli/class-reqgrep-command.php`. It reads the firehose through the substrate's `Consumer_Node` rather than hand-rolling segment reads: one Consumer per partition sinking into a `Callback_Node`, drained synchronously to EOF in cat mode and under the `Event_Framework` drain loop in `--follow`. Piped input goes through `Stdin_Node`; all output goes through a swappable `Stdout_Node` that writes straight to STDOUT, bypassing PHP output buffers. `Reqgrep_Core` does the rid grouping — collecting every entry sharing a request id once any line for that rid matches — and the command prints them in chronological order with indentation reflecting the `(start)` / `(complete)` tree. A 300-slot in-flight cache (100 × 3 buckets, 60-second rotation, so a 180s idle ceiling) prints anything that falls out as `[incomplete]`. The command requires `manage_options`.

`--recent` is the fast path: it starts the scan at the second-to-last segment instead of walking every segment from the beginning. Use it for "what's the firehose doing right now?" introspection.

## See also

- [AGENTS.md](../AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- `CHANGELOG.md` — version-by-version history.
- [Runtime architecture guide](../../newspack-nodes/docs/architecture-guide.md) — substrate this plugin depends on.
