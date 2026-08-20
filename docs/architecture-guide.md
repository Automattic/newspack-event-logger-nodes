# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Fleet, REPL), see `../../newspack-nodes/docs/architecture-guide.md`.

This plugin replaced the legacy `newspack-event-logger-plugins` monorepo wholesale. That monorepo has since been removed from the tree ("the museum"); this plugin plus the `newspack-nodes` substrate is the sole event-logger stack, writing to `/tmp/newspack-nodes` by default.

**Substrate presence.** The deferred bootstrap (run on `plugins_loaded` priority 11) returns early unless `\Newspack_Nodes\Bootstrap` exists AND `Bootstrap::version_at_least( '2.35.0', 'Newspack Event Logger Nodes' )` passes: it wires the event logger against a new-enough substrate and lies dormant otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WordPress 6.5+; the version handshake is the graceful fallback. One thing is wired outside the deferred closure — `Config::register_config_keys()` hooks `newspack_nodes/declare_config_keys` at file load, because the substrate pulls that declaration from inside any config read, ahead of `plugins_loaded`.

## Table of Contents

- [Overview](#overview)
- [Write Path: Log_Manager](#write-path-log_manager)
- [Per-URL logging ruleset](#per-url-logging-ruleset)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Memcache Schema (9 Namespaces)](#memcache-schema-9-namespaces)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)
- [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)
- [Job_Intake vs Firehose Routing](#job_intake-vs-firehose-routing)
- [Configuration](#configuration)
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
|   \Newspack_Nodes\Job_Intake::queue()                                    |
|       |  (pinned / keyed / round-robin partition select)                 |
|       v                                                                  |
|   Partition.fill() with allow_large  =>  /logs/jobintake.pN/             |
|                                 (auto-locked, up to 32 MiB)              |
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
|    Consumer(requests.pN)  -------> Flame_Builder ----> flames.pN                |
|                                       +---> Stats_Store  -> memcache            |
|                                       +---> flame-stats.pN (opt-in mirror)      |
|                                       +---> flame-builder:auto-tuner (sibling)  |
|                                                                                 |
|  topology flame-builder.pN:                                                     |
|                                                                                 |
|    Consumer(requests.pN) ----> Flame_Builder ----> flames.pN                    |
|                                       +---> Stats_Store  -> memcache            |
|                                                                                 |
|  substrate stock job-worker.pN topology (not shipped here):                     |
|                                                                                 |
|    Consumer(jobs.pN) ----> Job_Worker ----> registered handlers                 |
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
    private const MAX_DATA_SIZE   = 3840;   // 4096 - JSON envelope overhead
    private const MAX_LOG_LINES   = 40000;  // start/complete mute threshold
    private const MAX_TIMER_DEPTH = 100;    // start/complete nesting cap
    private const ENV_VALUE_MAX   = 256;    // per-value cap in environment_v3

    public function start( string $label, array $data = [] ): void;
    public function complete( string $label, array $data = [] ): void;
    public function message( string $category, array $data = [] ): bool;
    public function error( string $message ): bool;
    public function warning( string $message ): bool;
    public function info( string $message ): bool;
    public function alert( string $message ): bool;
    public function flush(): void;
    public function finish(): void;
}
```

**Wire shape.** Each firehose line is a packed 7-field substrate `Message` envelope, not bare JSONL. The request id rides `Message::KEY`, the entry map rides `Message::VALUE`, and `Message::TYPE` is `TM_STRUCT`:

```json
{"n":<line>,"k":"<category>","m":<payload>,"l":"<label>","ts":<epoch_float>}
```

- `n` — per-request line number, starting at 1. `Request_Builder_Node` validates the sequence, so a gap or a rewind is reported rather than silently assembled.
- `k` — category. `App\Core` writes hook spans as `"<hook> hook (start)"` / `"<hook> hook (complete)"`; the ` (start)` / ` (complete)` suffix is what pairs a span. Anything else is a one-shot entry (`request`, `environment_v3`, `resources`, `memory`, `job`, `error`, `warning`, `info`, `alert`, `stderr`, custom event).
- `m` — payload (string or nested array, JSON-encoded on the wire). `Flame_Builder_Node` reads it for per-event detail.
- `l` — optional stable label. Aggregation groups on `l`; `m` is the volatile message.
- `ts` — wall-clock epoch seconds as a float, stamped once per line and threaded into both the entry and `Message::TIMESTAMP`.
- The request id is NOT in the entry. `message()` unsets any caller-supplied `rid` key, so a caller cannot forge one.

**Request id.** `HTTP_X_A8C_REQUEST_ID` wins, then `UNIQUE_ID` (each clipped to 64 chars); absent both, `generate_request_id()` mints a 32-char base-36 string and stamps it into `$_SERVER['UNIQUE_ID']`. The id also picks the partition: `Partition_Node::hash_to_partition( $request_id, $num_partitions )`, so every line of one request lands in one `firehose.pN`.

### URL-secret redaction

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`. Query-string values for `api_key`, `token`, `secret`, `bearer`, `authorization`, `session`, and a dozen more become `=[REDACTED]`. The list intentionally errs broad — anything that looks like a credential gets blanked. `log_environment()` redacts before it caps a value at `ENV_VALUE_MAX`, so truncation can never hide a secret's boundary.

### Refuse-root

`Log_Manager::__construct()` calls `\posix_getuid()` early. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction returns before the matcher is built, so nothing ever starts — `is_started()` stays false and every `message()` no-ops for the rest of the request.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. Two things keep that traffic out of the stats. First, the shipped config seeds SKIP rules for the substrate worker endpoints (`/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}`) and `/wp-cron.php`, so the matcher resolves those URLs to `skip` and the firehose never sees them. Second, for any worker request that IS logged, `NEWSPACK_NODES_WORKER_TYPE` rides the `environment_v3` entry; `Request_Builder_Node` reads it, sets `is_worker`, and appends `?<worker_type>` to the request URL so worker traffic gets its own URL rows instead of polluting the real ones. Without that exclusion, the fleet's spawn cycle would dominate every leaderboard.

### PIPE_BUF and truncation

Per-line writes go to `Topic::fill()` → `Partition::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the envelope around the entry. Anything larger is truncated: a rate-limited `Core::print_less_often()` notice fires, the category gets `" (truncated)"` appended, and the data is replaced with a 1000-char excerpt (`['m' => substr($data_json, 0, 1000) . '...']`). Oversize payloads belong in `\Newspack_Nodes\Job_Intake::queue()`, which goes through `Partition::allow_large_writes()` and the per-partition write lock. Truncation never throws — the firehose is fire-and-forget — so size discipline is the caller's responsibility.

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

`Log_Manager::instance()` constructs on first use and registers its `shutdown` hook. The shipped start priority is `-10000` (`hook_start_priority` in `newspack-event-logger-nodes-config.php`); `App\Core` falls back to `1` only when the key resolves to a non-integer. The `hook_spacer` at `PHP_INT_MAX-2` (`App\Core::SPACER_PRIORITY`) is a sacrificial no-op registered on every instrumented hook so a self-removing filter (e.g. es-wp-query) that unhooks itself mid-run can't shift the WP filter pointer past `hook_complete` and skip the matching close.

`App\Core` binds ONLY the hooks the current request's governing rule names — a skip rule or no match binds zero hooks, which is the hot-path win. `newspack_event_logger_nodes_scope_changed` (fired by `begin_job_context` / `end_job_context`) triggers `rebind_for_current_scope()`, so a job's synthetic `/jobs/{handler}/{id}` request gets its own rule's hooks.

Orphaned `start`s (callback threw, exit called, fatal error before `complete`) get a synthetic `"<label> (complete)"` at `finish()` time carrying `m: "(orphaned)"` plus the measured duration. `finish()` then emits `process (complete)` with the HTTP status; a fatal in `error_get_last()` adds `fatal_error` / `fatal_file` / `fatal_line` / `fatal_type` / `fatal_plugin` and `error_status='F'`. A request whose `process (complete)` never arrives at all is closed by `Request_Builder_Node`'s bucket eviction with `error_status='T'`.

## Per-URL logging ruleset

**What decides whether a request is logged, and which hooks/events it instruments, is a per-URL ruleset** (v0.26.0) — not the seven global options (`log_urls` / `skip_urls` / `log_events` / `custom_events` / `significant_events` / `auto_disable_threshold` / `auto_protect_time_threshold`) it replaced. `Log_Manager::matches_url_filter()` resolves the governing rule via a `Rule_Matcher` and logs only when that rule's action is `log`; the rule's own hooks, custom events, significant events, and auto-tune thresholds then drive the rest of the request.

**`Rule`** (`includes/class-rule.php`) is an immutable value object: `pattern`, `action` (`log`/`skip`), and — for LOG rules — `hooks`, `custom_events`, `significant_events`, `auto_disable_threshold`, `auto_protect_time_threshold`. Its `id` is a pure function of its pattern (`Rule_Set::id_for` = `Log_Manager::url_hash( $pattern )`), so there is exactly one rule per URL; a client-supplied or config-supplied id is ignored.

**`Rule_Matcher`** (`includes/class-rule-matcher.php`) picks the governing rule by specificity, not by list order. Three pattern forms rank ahead of one another, and pattern length breaks ties within a rank:

| Rank | Form | Example | Matches |
|------|------|---------|---------|
| 0 | exact path + query prefix | `/jobs/x?job-work` | path equal, query starts with the pattern's query |
| 1 | exact path | `/about?` | path equal, query ignored |
| 2 | path prefix | `/blog` | path starts with the pattern, query ignored |

Matching is case-insensitive (target and pattern are compared lowercased) and cached per normalized URL. **No rule matches ⇒ skip.** There is no implicit log-all baseline — to log everything, declare a `/` LOG rule. The shipped config does exactly that, plus baseline skips for `/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}` and `/wp-cron.php`, so the logger's own worker IPC, SSE, and spawn traffic never logs.

**`Rule_Set`** (`includes/class-rule-set.php`) is the durable store. The rule LIST rides one autoloaded option (`newspack_event_logger_nodes_rules`); a heavy rule's hooks (past `INLINE_HOOK_LIMIT = 100`) move to a **non-autoloaded** per-rule durable option (`newspack_event_logger_nodes_rule_hooks_<id>`, the system of record) mirrored into memcache with a 1-hour TTL (warmed on miss) so the common small-rule path never pays a memcache hop and heavy rules don't bloat autoload. `save()` applies the inline↔pointer threshold, warms/writes the durable option, reconciles orphaned hook options, and re-tiers the in-memory list so `rules() == load()`.

**Loading.** `Rule_Set::load()` falls back to `seed_from_config()` when the option is absent or corrupt, building the ruleset from the config file's `rules` key (non-persisting) until the editor first writes it. Empty means empty: a config with no `rules` key yields a zero-rule set, which logs nothing.

**Transport.** `hydrate_array()` inlines every pointer rule's hooks so a synced ruleset reaches a spoke hook-complete; `apply_synced()` re-tiers it locally on arrival, writing heavy hooks back out to the spoke's own durable option. The settings-sync value filter calls `hydrate_array()` on the way out.

**Editing.** The `rules` service CI (`Rules_CI_Node`, verbs `list`/`save`/`upsert`/`delete`/`reset`) backs the "Logging Rules" editor mounted on the settings page (`src/rules/RulesAdmin`, mounted by `src/settings`). Every verb routes through `Rule_Set` so the tiering/reconcile invariants are never bypassed. `instrumented_union()` (the union across all LOG rules of hooks + custom events) feeds Discovery's spoke payload and the editor's selected-set browse modal.

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

`<partition>` and `<topology>` are bound by `Topology_Loader`; `<config:logs_dir>`, `<config:num_segments>`, and friends resolve against the substrate Config; `<eln:stats_mirror_node>` and `<eln:is_hub>` resolve against the application Config (the v0.4.0 namespace split). `<topology>` names the FLEET, which is why every offsetlog and dead-letter path carries it: an offsetlog is a reader's cursor and the reader is the fleet, so a `request-builder` fleet and a `complete` fleet tailing the same `firehose.pN` keep separate cursors instead of stealing each other's position. That is also what lets several topologies share one byte-identical Consumer line.

Two things about `include` are easy to trip over. Frontmatter is read from the TOP-LEVEL file only, so a `var` inside an included file is skipped. And the Shell tracks included files, so a file pulled in twice by two different parents runs once — which is what lets `performance` and `complete` each include several files that all include `topic-probe`. A `secure` line inside an included file is skipped too; only the top-level one ratchets.

The `make_node` first argument is a **shell name** the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the single-word substrate types (`Consumer`, `Partition`, `Tee`, `Topic`, `Age_Sieve`, `Remote_Source`) resolve under the substrate's own prefix.

Ten `.tsl` files ship: `request-builder`, `flame-builder`, `job-router`, `job-feed`, `job-spoke`, `job-hub`, `performance`, `complete`, `aggregator`, and `hub-control`. The last five are composed from the others plus substrate stock topologies, so the primitive files are the ones to read. Job *dispatch* (`Job_Worker_Node` tailing `jobs.log`) is NOT shipped here — it comes from the substrate's stock `job-worker` topology (the local `job-worker.tsl` was deleted in v0.12.0 when `Job_Worker_Node` moved to the substrate).

Every file ends with `secure`, and every one pulls in the substrate's stock `topic-probe` — directly, or through a file it includes. That probe mounts a 15s `Topic_Probe` sweep writing per-worker Consumer stats to `topicprobe.p0`.

### `topologies/request-builder.tsl`

The assembly branch. Tails `firehose.pN`; `Request_Builder` writes `requests.pN`, forwards errors to `errors.p0` and alerts to `alerts.p0`, and fans completed summaries through `completed:tee` to `completed.p0` + `gyroscope.p0`.

```tsl
include topic-probe

make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/<topology>.firehose.p<partition> <config:deadletter_dir>/<topology>.firehose.p<partition>
make_node Partition alerts:partition <config:logs_dir>/alerts.p0
make_node Partition errors:partition <config:logs_dir>/errors.p0
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.p0 1048576 <config:min_segments> <config:num_segments> 0 0
make_node Partition completed:partition <config:logs_dir>/completed.p0 1048576 <config:min_segments> <config:num_segments> 0 0
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

**Partition arguments are positional and optional.** The full signature is `make_node Partition <name> <dir> [segment_size] [min_segments] [num_segments] [max_segments] [min_lifetime] [lifetime]`. `Partition::node_schema()` defaults each retention argument to its matching `<config:…>` token, so `alerts:partition`, `errors:partition`, and `requests:partition` — which pass a directory and nothing else — resolve to exactly the values an explicit tail used to spell out. `gyroscope:partition` and `completed:partition` DO carry a tail because they pin a non-default 1 MiB segment size and disable both lifetime rules.

**Four of the five output partitions are `.p0`, not `.p<partition>`.** Every partition's request-builder appends to the same `alerts.p0`, `errors.p0`, `gyroscope.p0`, and `completed.p0`. That is safe precisely because none of them lifts the PIPE_BUF cap: each write stays a single atomic append, which is what makes a shared multi-writer log correct. `requests:partition` is the one per-partition output, and it is the one that runs `void_warranty`.

**`void_warranty` on `requests:partition`** lifts the per-message cap to 32 MiB (`Partition_Node::MAX_LARGE_LINE_SIZE`) *without* a per-Partition lock — that partition is written by exactly one worker fleet, and the substrate refuses to spawn a topology set where two fleets write the same partition, so the exclusivity lock `allow_large_writes` carries is redundant here (v0.16.0). Full `Request_Builder` request documents regularly exceed the 4 KB PIPE_BUF ceiling on pages with many timed hooks. Everything that must fit in PIPE_BUF instead routes through `Line_Fitter::fit()`, which halves the listed VALUE fields (`url`, `user_agent`, `m`) until the packed line fits and drops the line loudly when nothing is left to cut.

**`add_snapshot_node` + `set_multi_writer`.** The first wires the consumer's offsetlog to checkpoint `Request_Builder`'s in-flight cache, so in-flight requests survive a worker respawn (v0.16.0). The second tells the firehose Consumer to expect concurrent producers, since many FPM workers append to `firehose.pN`.

### `topologies/flame-builder.tsl`

Per-partition flame builder. Tails `requests.pN`; `Flame_Builder` emits `flames.pN` and bumps the 9-namespace memcache schema via `Stats_Store`.

```tsl
include topic-probe

make_node Consumer requests:consumer <config:logs_dir>/requests.p<partition> <config:offsets_dir>/<topology>.requests.p<partition> <config:deadletter_dir>/<topology>.requests.p<partition>
make_node Flame_Builder flame-builder
make_node Partition flames:partition <config:logs_dir>/flames.p<partition>
make_node Partition flame-stats:partition <config:logs_dir>/flame-stats.p<partition>
cmd requests:consumer:config add_snapshot_node flame-builder
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_stats_target <eln:stats_mirror_node>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flames:partition:config void_warranty
cmd flames:partition:config with_index flame-index
cmd flame-stats:partition:config void_warranty
connect_node requests:consumer flame-builder
connect_node flame-builder flames:partition
secure
```

`configure_stats <partition>` constructs the per-partition `Stats_Store`, taking its retention window from `Config::stats_retention_seconds()`, the substrate's `min_lifetime` (default 43200) floored at 3600. Auto-tune thresholds are no longer topology tokens — they moved onto each LOG rule (v0.26.0), so `set_auto_tune` / `set_significant_events` are gone; `Flame_Builder_Node` reads the governing rule's thresholds per completed request (see [Flame_Builder_Node](#flame_builder_node) and [Auto_Tuner_Node](#auto_tuner_node)). `set_stats_target <eln:stats_mirror_node>` mirrors the memcache stats into the durable `flame-stats:partition`, which a memcache miss reads back through a `stats-index` companion index; set `stats_mirror_node` to `''` to disable it. The mirror keeps full aggregates plus a bounded top-N per URL (100 dimensional, 100 category, and flame profiles only when `set_flame_topn` raises the default 0).

### `topologies/job-router.tsl`

The job-routing half. Tails `firehose.pN` and, via the included substrate `job-intake` topology, `jobintake.pN`; `Job_Router` normalizes both sources and writes `jobs.pN`. Dispatch of those `jobs.pN` lines is the substrate stock `job-worker` topology, not this file.

```tsl
include topic-probe
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

`include job-intake` brings in the substrate's `jobintake:consumer` and `jobs:partition` (already under `void_warranty`) wired straight to each other; the `disconnect_node` + `connect_node` pair then re-routes the consumer through `job-router`. Because `job-intake` declares `jobs:partition` with the warranty voided, the substrate's conflict gate refuses to co-activate the stock `job-intake` topology alongside `job-router`.

Both Consumers feed `job-router`. It selects a nested array under `m` when present and otherwise treats the entry itself as the job body, so firehose and Job Intake records take one normalization path; routing does not depend on the Consumer name in `Message::FROM`. `Job_Router` takes no arguments: staleness is the downstream `Age_Sieve`'s job, and it drops messages whose envelope `TIMESTAMP` age exceeds `max_age` — 900 seconds here, with the drop warning enabled.

### `topologies/performance.tsl`

`request-builder` + `flame-builder`, nothing else:

```tsl
include request-builder
include flame-builder
secure
```

Both included files include `topic-probe`, so the composition mounts one probe and two Consumers. Use it where job dispatch lives in a separate fleet (or the substrate stock `job-worker`) and you want request assembly plus flame stats in one worker group.

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

Order matters. `job-router` is included last and wires `firehose:consumer → job-router` directly; the five lines below it then re-route that single edge through `firehose:tee` so both `request-builder` and `job-router` feed from the Tee. **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** `jobintake:consumer` still has one target, so it stays wired directly to `job-router`. Number of Tees equals number of source fan-outs, not number of sources.

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
```

`Remote_Source` reads like the Consumer it is: `<vault-id>` names the spoke, `firehose.p<partition>` names the remote partition to subscribe to, and the last two arguments are its offsetlog and dead-letter directories. Both are optional in the schema and both should be passed — omitting them means no cursor and no quarantine. Scope the offsetlog with `<topology>` so two hubs pulling one spoke partition never share a cursor.

Each substrate `Remote_Source_Node` is self-sufficient: it patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`. SSL/HTTPS policy comes from the substrate `vault_verify_ssl` / `vault_require_https` config, not a per-topology setter; spoke credentials are looked up from the substrate **Vault** by `<vault-id>`. The `k:"job"` → `k:"remote_job"` rewrite is the `Remote_Job_Rewrite_Node` graph node (see [Remote_Job_Rewrite_Node](#remote_job_rewrite_node)) — not a filter, not a TSL setter; non-`job` entries pass through. Hub-mode is derived from whether `aggregator` is in the substrate's Topologies multi-select, directly or through an active topology that includes it — there is no `enable_aggregator` config key (it was retired). Operators stand a hub up by selecting the `aggregator` and `hub-control` topologies.

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

The included `settings-sync` supplies `settings:consumer` (tailing the `settings.p0` log that the substrate's `Settings_Event_Writer` appends option-name-only events to on watched WP-option changes), the `Settings_Sync` node itself on a 300s tick, and the substrate's own `add_setting` registrations for `num_partitions` and the six-axis `remote_*` segment geometry. The three lines above add the application options. This file adds `Discovery_Collector`, which fans `discovery.get` to every spoke on its own 300s tick and union-merges the replies into the hub's staging options.

**Neither fan-out goes through a Tee.** Each spoke's command is signed under that spoke's session key, and re-addressing a signed command after the mint makes it verify nowhere — so `Settings_Sync` and `Discovery_Collector` each iterate their own live targets and mint one signed command per spoke. The pipeline stays correctly inert until an operator wires per-spoke `HTTP_Out <name> <vault-id>` nodes from the console and connects the two nodes to them.

`Settings_Sync` and `Settings_Event_Writer` live in the substrate (`\Newspack_Nodes\Settings_Sync_Node`, `\Newspack_Nodes\Settings_Event_Writer`); `Discovery_Collector_Node` is this plugin's (see [Discovery_Collector_Node](#discovery_collector_node)).

### Topology resolution

As of v0.5.0, the `topologies` key lives on the substrate — `newspack-event-logger-nodes-config.php` no longer owns it. The plugin only **publishes its catalog**: at boot, it calls `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' )` — one call that registers the application namespace prefix (for `make_node` short-name resolution) AND the stock-topology dir together — so anyone calling `Topology_Registry::resolve()` (admin, REST, tests, CLI, workers) finds the stock `.tsl` files. Which catalog entries actually spawn workers is decided downstream by the substrate's Topologies multi-select option (`newspack_nodes_topologies`) — this plugin only publishes the "what topologies exist" set; the substrate filters it by what the operator has checked. `num_partitions` defaults also come from the substrate config, so one setting drives both `Log_Manager` (write side) and the worker fleet (read side); hardcoding diverges them.

Cost on regular WP requests is one array append at boot — the `.tsl` files themselves aren't parsed yet. Actual resolution and parsing happen in three places, none on the page-render hot path: the fleet's config-check tick (every 15s), worker bootstrap (once per spawn), and REST workers/dashboard reads.

## Application Nodes

### Request_Builder_Node

Assembles requests from firehose entries via the `LRU_Cache` 3-bucket timed-rotation cache. Bucket rotation every 200s; full retention window 3 × 200s = 600s (10 min). Orphans evicted from the oldest bucket emit as timed-out (`error_status: 'T'`). As of v0.16.0 it's a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`); `fire()` rotates the in-flight cache on each tick, so a stalled request still times out and gets written even when no further firehose lines arrive to drive rotation via `fill()`.

```php
class Request_Builder_Node extends Timer_Node {
    public const DEFAULT_BUCKET_SIZE = 100;
    public const DEFAULT_NUM_BUCKETS = 3;

    private const BUCKET_ROTATION_S        = 200;   // 3 × 200s = 600s retention
    private const MAX_STACK_DEPTH          = 50;    // runaway cutoff
    private const MAX_ENTRIES_PER_REQUEST  = 50000;
    private const MAX_ENTRY_MESSAGE_LENGTH = 1024;

    public function fill( array $message ): void {
        // Validate the per-request sequence `n`; assemble (start)/(complete)
        // pairs into nested spans; fan error/warning/stderr to errors_target
        // and alert to alerts_target.
        // On rotation: evict timed-out bucket (sets error_status='T' on each orphan).
        // On overflow: evict oldest entry from oldest bucket.
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

Two behaviours are easy to miss. A `gyrobase (start)` entry pushes the current sequence counter onto a stack and restarts numbering at 1, so a nested `proc_open` render under the same request id doesn't read as a sequence break; the matching `gyrobase (complete)` pops it back. And a request whose hook stack passes `MAX_STACK_DEPTH` is flagged `is_runaway`: it stays visible (Perl gyroscope parity) but stops accumulating entries, and is still evicted and bounded.

The node also owns the `request-index` formatter (`format_index_entry`), a fixed-width 97-byte line — rid, url_hash, timestamp, duration, status, segment, offset, length, peak MB, a one-char method code, and a one-char error status — that `Partition::with_index()` writes beside `requests.pN` and `Performance_CI_Node` seeks against.

### Request_Flight_Node

Backs the Gyroscope dashboard's live in-flight view. It's a hidden `Timer_Node` sibling that `Request_Builder_Node` owns: on each timer tick it snapshots the patron's in-progress request map and emits **one TM_STRUCT message per in-flight request** (KEY = rid) to the gyroscope partition, so the page can render a live treemap of what's running right now plus a fading trail of recent completions. A single batched list was the earlier shape and it crossed the 4 KB cap under load.

```php
class Request_Flight_Node extends Timer_Node {
    protected function fire(): void;          // one row per in-flight request, on the Router's 1s TIMER
    public function inflight_snapshot(): array;
    public function set_delta( bool $on ): void;
}
```

It has no `set_interval()` / `interval()` / `fire_cb()` — those were removed in v0.16.0. Snapshots hitchhike the Router's 1s TIMER via `fire()` (the `Timer_Node` contract); a non-empty `target()` is the sole enable switch, and clearing it stops the timer. The node is hidden from the topology console by the substrate's patron filter in `dump_metadata`, and from the palette by its own `node_schema()` category. Its configuration surfaces on the patron's `:config` interpreter as `set_inflight_target` and `set_inflight_delta` — the `request-builder` topology (and therefore `performance` and `complete`) wires it with `cmd request-builder:config set_inflight_target gyroscope:partition`. Delta mode is off by default, so every tick re-emits every row and a fresh subscriber sees the whole cache in one tick; turning it on emits only rows whose activity advanced since the previous fire.

Because the snapshot rides `Request_Builder_Node`'s own in-flight map (the same `LRU_Cache` buckets it already maintains for request assembly), there's no second tracker to keep coherent — the previous standalone `InflightTracker` class (deleted in the M6 consolidation, along with the legacy per-stream SSE controllers) was a separate in-memory copy of state the builder already held.

The gyroscope partition therefore carries two interleaved record shapes: per-request `complete` rows (via the `completed:tee` fan-out) and the periodic active-state rows `Request_Flight_Node` emits. The browser dispatches them client-side; there's no server-side `GyroscopeStreamController`.

### Flame_Builder_Node

Aggregates completed-request events into flame-graph stats and writes flame data to `flames.pN`. It reads the completed-request documents `Request_Builder_Node` wrote to `requests.pN`, one TM_STRUCT message each. Holds a 5×1000 LRU `stats_cache` (`STATS_CACHE_NUM_BUCKETS × STATS_CACHE_BUCKET_SIZE`) of per-URL accumulators and rotates buckets on overflow. Emits into the hourly, leaderboard, per-server leaderboard, URL, dimensional, and category memcache namespaces via `Stats_Store`.

The auto-tune thresholds are **per-LOG-rule** now (`auto_disable_threshold` / `auto_protect_time_threshold` on the governing `Rule`, no longer topology tokens). When a rule sets a non-zero threshold, `Flame_Builder_Node` runs noisy / significant event detection for that rule's traffic during its 5s flush window: hooks that fire more than `auto_disable_threshold` times are candidates for disable; hooks whose mean event time exceeds `auto_protect_time_threshold` are candidates for "significant" status (protected from auto-disable). Decisions emit downstream as `TM_STRUCT` messages addressed to the owned `{name}:auto-tuner` sibling and tagged with the `rule_id` to mutate — see [Auto_Tuner_Node](#auto_tuner_node) for the dispatch and fan-out.

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
    private const AGGREGATE_EXPIRY_SEC = 3600;  // unseen aggregate children expire
    private const MAX_URLS_PER_BUCKET  = 500;   // top-N per hourly URL-index bucket
}
```

Flush every 5s via the Router-hitchhike Timer; flush on shutdown via the TM_EOF handler.

The flame-tree algorithm itself lives in `Flame_Tree` (`includes/class-flame-tree.php`), a stateless helper: LIFO span matching, duplicate-sibling numbering so aggregation doesn't collapse them, incremental sums-not-means merging across requests, and a finalize pass that converts sums to averages and normalizes parent ≥ children. It caps both stack depth and recursion depth at 50.

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

This replaces the legacy `AutoTuneHandlers` six-listener pattern (hub at priority 5 plus standalone at priority 10, across three events). Both sides used to wire on WP actions; both sides ran in the same flame-builder worker process; the action plumbing was intra-process IPC dressed up as hooks. Expressing it as a Node with `fill()` dispatch is one straight path through the same logic.

**Distributed lock**: `Flame_Builder_Node` holds a 5s `evlog:auto_disable_lock` memcache lock across the emit batch. Without it, two `Flame_Builder_Node` workers (different partitions, both flushing at the same instant) would emit overlapping decisions twice. With no memcache handle, or no `Stats_Store` (unit tests), it fires unlocked.

### Job_Router_Node

Multi-input routing. Reads firehose AND jobintake without interpreting `Message::FROM`: an array under `entry['m']` is the body, otherwise the entry itself is the body. Both shapes normalize into `{ k, handler, parameters, ts }` — plus `id` when the body carries one — before forwarding to `jobs.pN` for `Job_Worker_Node` to dispatch. The job kind is carried under `k` (not `type`) end-to-end, and both `job` and `remote_job` are preserved for either body shape.

| Entry shape | Selected body | Allowed kinds |
|-------------|---------------|---------------|
| Nested array under `m` | `entry['m']` | `job` or `remote_job` |
| No nested array | `entry` | `job` or `remote_job` |

The kind is read from the ENTRY's `k`, never the body's, so the hub's `job` → `remote_job` rewrite wins over whatever the spoke wrote inside the body.

Validation:

- Handler name must match `HANDLER_NAME_PATTERN` = `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- `parameters` must be an array when present; omission normalizes to `[]`
- Staleness is not Job_Router's concern: the topology's `Age_Sieve` (between `job-router` and `jobs:partition`) drops messages whose envelope `TIMESTAMP` age exceeds 900s; body `ts` values pass through as data

Invalid handler names and non-array parameters emit a drop audit. Router-level size rejection is not duplicated here: producers use `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE` when they need the canonical intake limit, and the downstream Partition enforces writes. `Job_Worker_Node` handles valid-but-unregistered handler names after reading `jobs.pN`.

`Job_Worker_Node` (downstream) reads `jobs.pN` and looks up the handler in `newspack_nodes/job_handlers` (kind=job) or `newspack_nodes/remote_job_handlers` (kind=remote_job). Registration is filter-only; there are no programmatic setter methods.

### Job_Worker_Node

> **Lives in the `newspack-nodes` substrate** (`\Newspack_Nodes\Job_Worker_Node`), not this plugin. Generic async-job dispatch — per-job try/catch isolation, the `gc_collect_cycles()`-after-every-job discipline, the object-cache flush cadence, and the memory-watermark self-restart — is runtime plumbing; see its row in [`../../newspack-nodes/AGENTS.md`](../../newspack-nodes/AGENTS.md). Two things are THIS plugin's concern:

**Per-job request context.** The worker fires `newspack_nodes/job_worker/{before,after}_job` around each handler, passing `( $handler, $id )` on the before-action; the after-action runs even on throw. ELN hooks `Log_Manager::begin_job_context` / `end_job_context` onto them to suspend the request logger and stand up a synthetic `/jobs/{handler}/{id}` `$_SERVER` (plain `/jobs/{handler}` when the id is empty), so a job's own logging never bleeds into the request that enqueued it. Both are stack-based: `begin_job_context()` snapshots `$_SERVER` onto a LIFO before touching anything, so an unpaired or throwing begin still leaves `end_job_context()` a snapshot to restore.

**Handler registration + `k`-routing.** The worker reads each `jobs.pN` entry's kind under `k` — `'job'` or `'remote_job'`, carried end-to-end (never `type`) — and dispatches against the matching filter: `k:"job"` → `newspack_nodes/job_handlers`, `k:"remote_job"` → `newspack_nodes/remote_job_handlers`. Registration is filter-only (no programmatic setters); a job type registers under whichever side(s) should handle it — see [Hub vs Spoke Topology](#hub-vs-spoke-topology).

### Remote_Job_Rewrite_Node

Hub-side fan-in is the self-sufficient substrate `\Newspack_Nodes\Remote_Source_Node` — one per spoke/partition, operator-wired on the topology console. Each one patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, pulls `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume, looks up its spoke credentials from the substrate **Vault**, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`. The old ELN `Stream_Merger_Node` and ELN-local `Remote_Source_Node` were deleted in the pull-side cutover, along with `Health_Check_Extensions` and the `newspack_nodes/aggregator_ingest_line` filter.

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

Relocating the rewrite from the deleted Stream_Merger's `aggregator_ingest_line` filter to a graph node keeps the substrate `Remote_Source` / `SSE_In` application-agnostic: the rewrite is a node the hub topology wires in, not a filter the substrate has to call. The rewrite grows the line by the 7-byte `job` → `remote_job` delta; the downstream `Topic` enforces its own per-message cap.

### Discovery_Collector_Node

Hub-side periodic discovery fan-out. A `Timer_Node` the `hub-control` topology mounts (`make_node Discovery_Collector discovery-collector 300`): `arguments()` arms `set_timer()` at the given interval, defaulting to 300s when the token is blank. On each `fire()` it walks its live targets, mints one signed `discovery.get` TM_COMMAND per spoke (addressed to that spoke's `discovery` CI), and skips — after asking the egress for a handshake — any target with no established session. Each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks` and `custom_events` (under `VALUE['payload']`), folded one reply at a time so out-of-order or partial replies converge. Remote strings are sanitized and both catalogs are capped at `MAX_EVENTS = 10000`.

**It stages, it does not instrument.** As of v0.28.0 the merge no longer writes the ruleset (the old implicit propagating log-all that fought "empty means empty"); a shared `stage_discovered()` helper writes hooks into the non-autoloaded `discovered_hooks` option and custom events into `discovered_events`, and a name that arrived as a custom event never also stages as a hook. Those surface in the rules editor's hook picker for the operator to add — the editor is the only writer of rules. It replaces the legacy poll-based discovery sweep that `Remote_Manager` / `Health_Check_Extensions` used to drive.

### Stats production (owned by Flame_Builder_Node)

There is no separate stats Node. The standalone `StatsAggregator` Node — the variant for topologies that wanted memcache stats without flame data — was removed in the M6 consolidation; `Flame_Builder_Node` is the single stats producer, owning flame generation AND the 9-namespace memcache fan-out via its injected `Stats_Store`. The `flame-builder` topology (and therefore `performance` and `complete`) wires the store with `cmd flame-builder:config configure_stats <partition>`.

Each completed request is folded into `Flame_Builder_Node`'s in-memory pending stats across the same dimension set the reader paths whitelist — `DIM_FIELDS`, listed under [Flame_Builder_Node](#flame_builder_node).

On flush, `Flame_Builder_Node` writes each touched bucket through the explicit per-bucket `set_*` methods (`set_hourly_bucket`, `set_url_bucket`, `set_leaderboard_bucket`, `set_dimensional_bucket`, `set_category_bucket`, …). The value written is the whole window held in `pending`, not a delta, so the write is an overwrite and reads nothing back — a replay rebuilds a bucket instead of stacking on it. `Stats_Store` is storage-only; it does not own per-request aggregation logic. Its one extension point is the `$mirror` closure, invoked after each successful memcache write so the durable `flame-stats` partition can shadow the same data, which a memcache miss then reads back.

When no `Stats_Store` is configured (`configure_stats` never called — e.g. in unit tests), the builder still emits flame data without touching memcache.

## Memcache Schema (9 Namespaces)

Per-key prefix: `evlog[:salt]:p{N}:{namespace}:...`

The retention window comes from the substrate's `min_lifetime` (default 43200), floored at 3600.

| Namespace | Use | TTL |
|-----------|-----|-----|
| `hourly` | 5-min buckets, keyed per bucket, count + sum_ms + sum_peak_mb | `min_lifetime` (default 43200) |
| `lb` | 5-min global leaderboard buckets, sums-not-means | `min_lifetime` |
| `lb_s` | per-server leaderboard, keyed by server | `min_lifetime` |
| `urls` | 5-min URL index, keyed by URL -> {count, sum_req_time, samples} | `min_lifetime` |
| `url` | per-URL flame/profile blob | `max(3600, min_lifetime/24)` |
| `dim` | dimensional time series, keyed per bucket | `min_lifetime` |
| `url_dim` | per-URL dimensional series, keyed per bucket, every dimension in the value | `min_lifetime` |
| `categories` | category time series, keyed per bucket, `$server` scopes it | `min_lifetime` |
| `url_cat` | per-URL category series, keyed per bucket | `min_lifetime` |

**Caps prevent value-explosion** against memcache's 1MB/value limit:

- `MAX_DIM_VALUES = 20`
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`
- `MAX_DURATIONS_PER_BUCKET = 100`

Overflow rolls into a synthetic `Other` bucket. The `total` pseudo-category is preserved before sorting — see the existing `Flame_Builder_Node` implementation; do not regress when porting.

**`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip (`Stats_Store::get_url_buckets()`). Per-key `get` is a latency cliff. The shared `Core::$memd` (`\Memcached`) provides `getMulti` natively; the in-memory test double mirrors it.

## Stats_Store: Sums-Not-Means + Salt Rotation

**Sums-not-means storage**: Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket and cross-partition merge is exact addition. The display layer computes means at read time (`sums_to_display`).

This fixes an EMA-clamp bug the old running-mean storage had — do NOT regress to incremental averages. They look mathematically equivalent but break under merge.

**Schema migration via salt rotation**:

```php
public function flush_all(): bool {
    $salt = \bin2hex( \random_bytes( 4 ) );      // 8 hex chars
    \update_option( 'newspack_event_logger_nodes_stats_salt', $salt );
    $this->prefix = self::PREFIX_BASE . ':' . $salt;
    // All existing keys orphan instantly; they expire via TTL.
    // No scrubber walks; no large memcache scan.
    return true;
}
```

The rotating instance re-derives its own prefix, so it starts writing under the new salt immediately. Every OTHER long-running worker cached `prefix` at construction and keeps writing to the old salt until it respawns; readers see "no data" for the retention window after the rotation. Trigger a worker restart via `Lock_Node::request_restart_at()` if you need immediate effect.

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

**Hub identification**: a site acts as a hub when the `aggregator` topology is active — either selected directly in the substrate's Topologies multi-select, or pulled in by an active topology that `include`s it — and, for settings/discovery fan-out, `hub-control` too. `Config::resolve_eln_token( 'is_hub' )` computes exactly that, walking the active topologies and their include trees. There is no operator hub toggle: `enable_aggregator` (like `enable_workers` before it) was retired, and hub-mode is derived purely from topology membership. Push-side fan-out (the `hub-control` topology's `Settings_Sync` + `Discovery_Collector` nodes) is structurally gated the same way: without `hub-control` active and per-spoke `HTTP_Out` nodes wired, the settings/discovery pipeline is inert.

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

The hub's outbound side is no longer a set of static helper classes. `Server_Registry`, `Remote_Manager`, `Health_Check_Extensions`, and the ELN `Settings_Sync` were all deleted in the node-graph cutover. Three things took their place:

- **Spoke credentials live in the substrate Vault.** The substrate `\Newspack_Nodes\Vault` (managed through the substrate `vault` CI) stores each spoke's URL + credentials, encrypted at rest under `wp_salt('auth')`. A `Remote_Source` node references its spoke by `<vault-id>`; SSL/HTTPS enforcement comes from the substrate `vault_verify_ssl` / `vault_require_https` config. There is no `aggregator_servers` option and no `Server_Registry` CRUD anymore. Reacting to a credential change is the substrate's job, not this plugin's: `Bootstrap` restarts every active topology whose graph declares a `Remote_Link` / `Remote_Source` node, so whichever worker holds those credentials picks the change up without waiting out its ~10-minute respawn.

- **Settings fan-out is a node graph (`hub-control` topology).** The substrate's `Settings_Event_Writer` records watched WP-option changes to the `settings.p0` log (option name only). The `hub-control` topology's `Consumer` tails it, and the substrate `\Newspack_Nodes\Settings_Sync_Node` reads each option's current value at consume time and emits one `set` command per registered option per spoke, re-pushing the full registered set on its 300s tick. Each command is signed for its own spoke, so the node fans out itself rather than through a Tee. ELN supplies the value-resolver via the `newspack_nodes/settings_sync/value` filter (`newspack_event_logger_nodes_resolve_settings_sync_value`), which maps a `newspack_event_logger_nodes_*` / `newspack_nodes_*` option name to its live value, substitutes the owning config's default for a blank or absent value, and runs the ruleset through `Rule_Set::hydrate_array()` so pointer rules ship hook-complete.

- **Discovery is `Discovery_Collector_Node`** (also in `hub-control`) — see [Discovery_Collector_Node](#discovery_collector_node). It fans `discovery.get` to every spoke on its 300s tick and union-merges the replies into the hub's staging options, replacing the old `Remote_Manager` discovery sweep + `Health_Check_Extensions` merge.

## Settings Sync: No Operator Gate

Settings fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology (see [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)). It is **ungated** in the same structural sense as before: an option change always records a settings event (via the substrate `Settings_Event_Writer`), but nothing fans it out unless the `hub-control` topology is active and per-spoke `HTTP_Out` nodes are wired. On a spoke or standalone site there is no consumer, so the event is tailed and dropped.

`Auto_Tuner_Node` is ungated in the same sense: it persists through `Rule_Set::save()` with no `remote_manager` job and no `suppress_sync` flag. The write records a settings event like any admin edit; if `hub-control` is running, `Settings_Sync_Node` picks the change up at consume time. There is no operator hub toggle — the legacy `enable_workers` polarity dance was retired in v0.5.0, and `enable_aggregator` was retired too; hub-mode is derived from whether the `aggregator` / `hub-control` topologies are active. Letting the no-consumer drop happen at the node-graph level is cheaper and harder to misconfigure than a per-listener `get_option` gate.

Ungated is not unauthorized. `Auto_Tuner_Node::authorized()` still requires a worker context or `manage_options` before it will mutate a rule — the structural gate governs *where the change propagates*, the capability check governs *who may make it*.

**Value resolved at consume time, not at write time.** `Settings_Event_Writer` records only the option *name*; `Settings_Sync_Node` reads the current value when it consumes the event (and on its 300s tick re-reads and re-pushes every registered option). So a burst of writes to one option collapses to a single current-value push, and there's no stale-value race. ELN supplies the name→value mapping through the `newspack_nodes/settings_sync/value` filter.

## Job_Intake vs Firehose Routing

(`Job_Intake` itself lives in the newspack-nodes substrate as `\Newspack_Nodes\Job_Intake`; substrate-only installs drain it via the stock `job-intake` topology, which ELN's `job-router.tsl` `include`s and re-routes. Activating both as separate topologies is refused by the conflict gate, since both declare `jobs:partition` with the warranty voided.)

Two write paths into the job queue:

```
+--------------------------+         +---------------------------+
|  Log_Manager             |         |  Job_Intake::queue()      |
|  per-request, <4KB jobs  |         |  large jobs >4KB          |
|  k:"job" in firehose     |         |  written to jobintake.log |
+--------+-----------------+         +-------------+-------------+
         |                                         |
         v                                         v
  /logs/firehose.pN/                 /logs/jobintake.pN/
  (atomic append, PIPE_BUF)          (auto-locked >4KB writes)
         |                                         |
         +--------+--------------------------------+
                  v
              Job_Router_Node (reads BOTH)
                  |
                  v
              Age_Sieve (drops > 900s)
                  |
                  v
           jobs.log per partition
                  |
                  v
              Job_Worker_Node
                  |
                  v
           registered handlers
```

**Use firehose** (`Log_Manager->message('job', $payload)`) when:

- Payload fits in 4KB (PIPE_BUF atomic append).
- Job needs to be aggregated from spokes to hub (only firehose entries flow through the hub's `Remote_Source` pull).

**Use Job_Intake** (`Job_Intake::queue($handler, $params)`) when:

- Payload exceeds 4KB (serialized option blobs, image-handler args, large arrays).
- Job is local-only (jobintake never aggregates; entries stay on the originating site).

Using the wrong path loses jobs. `Log_Manager` truncates data over `MAX_DATA_SIZE` (3840B) to a 1000-char excerpt with `" (truncated)"` appended to the category, so the handler never sees a parseable payload. Job_Intake never aggregates, so spoke jobs there never reach the hub.

Job_Intake has three partition-selection modes:

- **pinned** — caller specifies partition index directly via `$intake->partition( $i )`.
- **keyed** — `Partition_Node::hash_to_partition( $key, $num_partitions )` (URL-style, identical to firehose).
- **round-robin** — static counter modulo `PHP_INT_MAX` for callers without a meaningful key.

A job with a `not_before` in the future skips all three and parks in `jobdelay.p0` until it comes due.

Lock-holding is per-Partition (no host-wide intake lock — that was removed). `Partition::allow_large_writes()` acquires the partition's write lock at construction and holds it for the partition's lifetime, admitting writes up to `MAX_JOB_SIZE` (32 MiB). One-off callers (`Job_Intake::queue()`) construct a one-shot Job_Intake, write, and let the destructor release the lock; batch callers reuse the same `Job_Intake` instance across many `queue_many()` calls so the lock acquisition cost amortizes.

## Configuration

Two storage tiers, layered in this order (later wins):

1. **File-based defaults** in `newspack-event-logger-nodes-config.php` (returns an array; loaded by `Config::load_config_defaults()`). For shared / per-environment defaults that ship with deployment. Never edited via the admin UI. `LOCAL_NEWSPACK_NODES_CONF` may name a second, canonical-path-validated file layered on top.
2. **WordPress options** under the `newspack_event_logger_nodes_*` prefix (application) and `newspack_nodes_*` prefix (substrate). Each key follows its owning plugin: ELN applies its option overlay to ELN-owned keys, while effective substrate values arrive after the substrate applies its own overlay. Edited via the admin UI; persisted via `update_option`. The overlay is presence-based, so a stored `''` / `[]` / `false` / `0` beats the file default.

```php
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // every schema key, file + WP option overlay + substrate merge
$config = \Newspack_Event_Logger_Nodes\Config::load_config_defaults(); // file-only (no WP option overlay, no substrate merge)
$value  = \Newspack_Event_Logger_Nodes\Config::value( 'log_memory' );  // fail-loud single-key read
```

`load_config()` is the single zero-arg entry point — every key in the `Settings_Schema` whitelist is loaded on every call, including the `rules` overlay key and the substrate keys merged in from `RuntimeConfig::load_config()`. The result is cached in a static for the rest of the process, so the cost is one round of `get_option` per schema key on first call; `Config::reset()` (or the substrate's `newspack_nodes/config_reset` action) clears it. `load_config_defaults()` is the file-only escape hatch for callers that need the shipped defaults without the per-request layering.

**Read single keys through `Config::value()`.** It throws on a key no registered schema declares, so a renamed or typo'd key fails at the boundary instead of limping on a `?? default`. `Config::register_config_keys()` publishes this plugin's keys (schema overlay keys ∪ config-file default keys) to the shared substrate registry, hooked to `newspack_nodes/declare_config_keys` so the substrate re-pulls them after every `Config::reset()`.

### Settings_Schema: one Field per setting

`Settings_Schema` (`includes/class-settings-schema.php`) is the single declaration both `Config` and `Admin` derive from — one `\Newspack_Nodes\Config_System\Field` per setting, collected into a `Schema`. It replaced the three parallel arrays `Config` and `Admin` used to hand-maintain in lockstep (`Config::$option_schema`, `Admin::$option_names`, `Admin::$delete_on_blank_options`). `Config` reads it for the overlay key-list; `Admin` reads it to drive the `register_setting` / `add_settings_field` loops, the reset list, and the delete-on-blank classification. Labels and section titles are lazy `fn(): string` thunks, so building the Schema for `overlay_keys()` on a frontend request never calls a translation function.

Substrate keys (`base_directory`, partitioning, `memcache_servers`, `topologies`, and the `remote_*` spoke geometry) are owned by the substrate's own Settings_Schema under `newspack_nodes_*`; ELN imports their effective values only after removing ELN-owned names, so each plugin's option namespace remains authoritative. Substrate keys are never declared here. Spoke credentials are no longer an application option at all — they live in the substrate **Vault**.

### Application option keys

| Option | Type | Loaded by | Default | Use |
|--------|------|-----------|---------|-----|
| `enable_logging` | bool | `load_config()` | `true` | Master switch for the firehose write path |
| `rules` | array | `load_config()` (overlay-only, `ui:false`) | five baseline skips + a `/` log (from config file) | The per-URL logging **ruleset** — see [Per-URL logging ruleset](#per-url-logging-ruleset). Seeds `Rule_Set` until the editor writes the option. Absorbed the seven retired global options (`log_urls` / `skip_urls` / `log_events` / `custom_events` / `significant_events` / `auto_disable_threshold` / `auto_protect_time_threshold`) |
| `log_memory` | bool | `load_config()` | `false` | Debug: append peak_mb to every complete entry |
| `flush_every_line` | bool | `load_config()` | `false` | Debug: flush buffer after every line (survives OOM, slower) |
| `allowed_users` | array of strings | `load_config()` (overlay-only) | `[]` | Deployment override: restrict admin UI to these usernames |
| `hook_start_priority` | int | `load_config()` (overlay-only) | `-10000` | Action priority for the `hook_start` instrumentation hook |
| `stats_mirror_node` | string | file-only | `flame-stats:partition` | Node name of the durable stats-mirror partition; a memcache miss reads the frame back from it. `''` disables the mirror |
| `custom_colors` | array | file-only | `[]` | Hook-categorization color overrides |
| `recommended_log_events` | array of strings | file-only | curated list | Hook names the admin "Select Recommended" button offers |
| `discovered_hooks` | array of strings | option-only (non-autoloaded) | `[]` | Hooks spoke-reported via Discovery, staged for the editor's hook picker (not auto-instrumented) |
| `discovered_events` | array of strings | option-only (non-autoloaded) | `[]` | Custom-event names spoke-reported via Discovery, merged into the admin color map |

`enable_logging`, `log_memory`, and `flush_every_line` all carry `restart: 'all'` — `Log_Manager` caches each in its per-process singleton, so a change needs every worker restarted before it takes effect.

The former per-URL/per-hook logging + auto-tune settings are now **per-rule** — each LOG rule carries its own `hooks`, `custom_events`, `significant_events`, `auto_disable_threshold`, and `auto_protect_time_threshold`. The seven retired global options are no longer read.

Spoke credentials and SSL/HTTPS policy are no longer application config keys — they live in the substrate **Vault** (`vault_verify_ssl` / `vault_require_https` on the substrate side). The remote-pull segment geometry (`remote_segment_size` / `remote_min_segments` / `remote_num_segments` / `remote_min_lifetime` / `remote_lifetime` / `remote_max_segments`) moved to the substrate's `newspack_nodes_*` namespace too. The retired `enable_aggregator`, `aggregator_servers`, `aggregator_verify_ssl`, and `aggregator_require_https` keys are gone.

### Substrate option keys (read but not owned here)

`Config::load_config()` also reads the substrate's `newspack_nodes_*` namespace for keys that affect application behaviour:

| Substrate option | Use here |
|------------------|----------|
| `base_directory` | Root for `logs/`, `offsets/`, `locks/`, `ipc/` |
| `num_partitions` | Topic / Partition fan-out, must match the topology fleet count |
| `segment_size`, `min_segments`, `num_segments`, `max_segments` | Partition geometry and count-based retention |
| `min_lifetime`, `lifetime` | Age-based retention; `min_lifetime` also sets the `Stats_Store` TTL window |
| `memcache_servers` | Stats_Store + SSE slot pool backend |

Per-key documentation lives in the substrate; this plugin treats them as read-only.

### Hot reload

Most config keys read through `Config::load_config()`, whose static cache lasts one process. A frontend request therefore picks a change up on its next request; a long-running worker does not. Restart is required for:

- `enable_logging`, `log_memory`, `flush_every_line` — cached in `Log_Manager`'s per-process singleton (`restart: 'all'`).
- `num_partitions` — wired into Topic/Partition construction at worker bootstrap; changing it requires `wp nodes restart` of all topology workers.
- `memcache_servers` — the shared `Core::$memd` holds the one connection, built once at boot.
- Salt rotation (`Stats_Store::flush_all()`) — other workers cache `prefix` at construction.

## REST + React

There is no per-endpoint controller hierarchy. The dashboards and admin tooling reach the application through the substrate's **command protocol**: one `POST /wp-json/newspack-nodes/v1/command` endpoint that routes a TM_COMMAND envelope to a named service CI node. This plugin owns three CIs — `performance`, `discovery`, and `rules` — mounted on `newspack_nodes/request_graph_ready`; `status`, `settings`, `topologies`, `workers`, `aggregator`, and the `vault` CI that replaced `servers` are substrate-owned. The former `logger` and `events` CIs were removed, superseded by `performance.hooks_registered` / `performance.overview`. Each verb's request/response shape is documented in [API.md](API.md).

The whole `includes/rest/` directory — including the orphaned `Performance_Controller_Base` helper class — has been deleted. The command-protocol CIs are the only REST surface now; nothing extends a per-endpoint controller base. `tests/integration/M2BootstrapTest.php` carries a regression guard so a future revert that reintroduces `includes/rest/` would fail.

### Real-time path: `/messages/stream` + slot pool

SSE is a single substrate surface: the substrate's `\Newspack_Nodes\Rest\SSE_Out_Node` doubles as the `GET /wp-json/newspack-nodes/v1/messages/stream` controller. A client subscribes to one or more partitions or globs (`?subscribe=errors.*`), `SSE_Out_Node` runs the drain loop, and emits a 7-field message envelope per line plus an idle `heartbeat` event when no data flows. The per-stream SSE controllers and their `SSEControllerBase` parent were all deleted in the M6 consolidation; the browser dashboards and the hub-side `Remote_Source_Node` cross-server pull all consume `/messages/stream` directly.

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

**Slot pool ownership**: the substrate owns the slot pool (`\Newspack_Nodes\SSE_Slot_Pool`); `SSE_Out_Node` calls it before headers and returns HTTP 429 when the pool is full or memcache is unreachable. The slot pool IS the rate limit; **memcache failure fails CLOSED** (429), the asymmetric flip side of the stats path's fail-soft behavior. Stats can degrade to "no data" gracefully because the dashboard is read-only; SSE streams ARE the live workload, and dropping the rate limit would let one runaway client saturate the worker pool. Hub-side aggregator connections (`Remote_Source_Node` cURL pulls) get a longer slot TTL than browsers, since a cURL handle can stall briefly under load. The per-client heartbeat refresh is the invariant: only the client refreshes a slot's TTL; the server-side slot check is check-only and never refreshes on check, so each client's TTL must outlive its own heartbeat interval. Don't reintroduce server-side refresh-on-check.

### React trees

Five dashboards ride the substrate's node-graph runtime: `overview` (Performance), `error-log`, `gyroscope`, `requests` (Request Log), and `settings`. Two more bundles ship beside them — the `current-request` debug-overlay tab, registered on the substrate's `newspack_nodes/devtools_tab_bundles` filter, and the `rules` ruleset editor, a React root the `settings` tree mounts into the settings page's "Logging Rules" section.

The three streaming dashboards (`error-log`, `gyroscope`, `requests`) share one shape. Each mounts a substrate `RemoteLink` node, which composes and registers three children of its own — an `SseIn` ingress, an `HttpOut` `/command` boundary, and a `Heartbeat` slot keep-alive — and wires the `connected → slot` bridge internally. The link takes the subscribe glob as its only constructor token (`errors.*`, `gyroscope.*`, `completed.*`), targets a pass-through `Tee`, and the Tee copies each frame to a single view-model node that shapes envelopes into rows inline. The substrate's `useStreamGraph` declares that whole graph and owns its connection lifecycle: it mounts via `mountExospine` (snapshotting Core so soft nodes tear down and rebuild on "Reset Graph"), closes the stream while the tab is hidden or paused, reconnects from the last seen offset on refocus, and records the reopen target so a selection made while paused cannot revive a closed stream. `useGlobBrowse` adds what a GLOB needs on top: the partition pick — rows for the shared toolbar's picker, the empty one widening back to the whole glob — over the substrate's `useSegmentBrowse` rail.

The command-driven surfaces (`overview`, `settings`, `rules`) mint each awaited verb FROM its own `Request` node and let the reply route back `TO = FROM`. There is no correlator: the addressing IS the correlation, so `message[ID]` stays empty.

Shared hooks, utils, and components are NOT copied into this plugin — there is no local `src/shared/` tree. They're imported via the `@newspack-nodes/shared/*` path alias (e.g. `import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility'`), which esbuild and jest resolve to the `newspack-nodes` sibling checkout's `src/shared`. The old synced-copy mechanism (`sync-shared.sh`) was retired in v0.12.0.

### Canonical view contract (v0.8.0)

Every command-driven dashboard view follows the same contract:

- **Pending-Map gate.** The view owns a `pending` Map keyed by `message[ID]`. The hook stashes `{ resolve, reject }` under a monotonic id before filling the TM_COMMAND, and the view's `fill()` looks up the id on every reply, settles the stashed Promise, and short-circuits the rest of the handler. This is what lets `_http`-routed replies land back on the right caller without polluting global view state.
- **TM_ERROR isolation.** A pending-matched TM_ERROR rejects the Promise **without** writing to a global `view.error` field. Per-call error surface is the caller's catch (a row-level snackbar, an in-form notice — never a table-wide banner). An UNMATCHED TM_ERROR is the one that raises the banner, and it leaves the previously-loaded rows intact.
- **Reject on teardown.** A view removed while calls are in flight rejects every pending Promise first, so no caller strands on a Promise that can never settle.
- **Id-wins partial spread.** `(id, partial)` verbs spread the partial FIRST so the positional id wins: `{ ...partial, id }`. This prevents a caller accidentally overwriting the addressed id from inside the partial.
- **No dead REPL mounts.** The shared graph factory used to mount a REPL node behind every dashboard; the dashboards never used it, so it's gone. Mount what the dashboard actually needs — the link, the Tee, and the view.

See the v0.7.0 / v0.8.0 entries in `CHANGELOG.md` for the dashboard cutover history.

## CLI: wp nodes reqgrep

Application-side firehose filter. The substrate ships `wp nodes status` / `wp nodes cli` (worker introspection); this plugin adds `wp nodes reqgrep` for searching the live firehose by URL pattern, request ID, or arbitrary content match, plus `wp nodes ruleset-bench` for benchmarking the rule matcher.

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

# Show requests that never reached `process (complete)`.
wp nodes reqgrep pattern --incomplete

# Widen the history buffer (default 250 lines × 10 buckets).
wp nodes reqgrep pattern --bucket-size=1000 --num-buckets=20

# Read a specific firehose dir (must sit inside the configured logs dir).
wp nodes reqgrep pattern --firehose=/tmp/newspack-nodes/logs/firehose.p0

# Pipe stdin instead of firehose files.
cat archived.log | wp nodes reqgrep pattern
```

Implementation lives in `includes/cli/class-reqgrep-command.php`. It reads the firehose through the substrate's `Consumer_Node` rather than hand-rolling segment reads: one Consumer per partition sinking into a `Callback_Node`, drained synchronously to EOF in cat mode and under the `Event_Framework` drain loop in `--follow`. Piped input goes through `Stdin_Node`; all output goes through a swappable `Stdout_Node` that writes straight to STDOUT, bypassing PHP output buffers. Matching collects every entry sharing a request id once any line for that rid matches, then prints them in chronological order with indentation reflecting the `(start)` / `(complete)` tree. A 300-slot in-flight cache (100 × 3 buckets, 60-second rotation) prints anything that falls out as `[incomplete]`. The command requires `manage_options`.

`--recent` is the fast path: it starts the scan at the second-to-last segment instead of walking every segment from the beginning. Use it for "what's the firehose doing right now?" introspection.

## See also

- [AGENTS.md](../AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- `CHANGELOG.md` — version-by-version history (M2–M6 controller→CI migration, dashboard cutover, substrate Tachikoma alignment).
- [Runtime architecture guide](../../newspack-nodes/docs/architecture-guide.md) — substrate this plugin depends on.
