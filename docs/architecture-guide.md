# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL), see `../newspack-nodes/docs/architecture-guide.md`.

This plugin replaced the legacy `newspack-event-logger-plugins` monorepo wholesale. That monorepo has since been removed from the tree ("the museum"); this plugin (plus the `newspack-nodes` substrate) is the sole event-logger stack, writing to `/tmp/newspack-nodes` by default.

**Substrate presence.** The entire deferred bootstrap (run on `plugins_loaded` priority 11) is gated on a `class_exists( '\Newspack_Nodes\Node' )` presence check: it wires the event logger when the substrate is loaded, and no-ops otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WordPress 6.5+; the class check is the graceful fallback. (The plugins deploy together, so a present-but-mismatched substrate isn't a case worth designing around — there's no version floor.)

## Table of Contents

- [Overview](#overview)
- [Write Path: Log_Manager](#write-path-log_manager)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Memcache Schema (9 Namespaces)](#memcache-schema-9-namespaces)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [Hub-Side Helpers: Server_Registry / Remote_Manager / Discovery](#hub-side-helpers-server_registry--remote_manager--discovery)
- [Settings_Sync: No Operator Gate](#settings_sync-no-operator-gate)
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
|   Log_Manager  --produce()-->  Topic(firehose.p<partition>)              |
|                                  |                                       |
|                                  v                                       |
|                         Partition.write()  =>  /logs/firehose.pN/        |
|                         (4KB PIPE_BUF atomic)                            |
|                                                                          |
|   Job_Intake::queue()  --produce()-->  Topic(jobintake.log)              |
|                                          |                               |
|                                          v                               |
|                                 Partition.write() with allow_large       |
|                                 (auto-locked, up to 10MB)                |
+--------------------------------------------------------------------------+

+---------------------------------------------------------------------------------+
|                READ PATH (Worker, ~10 min substrate-controlled lifespan)        |
|                                                                                 |
|  topology combined.pN:                                                          |
|                                                                                 |
|    Consumer(firehose.log)  ----+                                                |
|                                |                                                |
|                                v                                                |
|                              Tee  ----> Request_Builder_Node ----> requests.log |
|                                |                       +---> errors.log         |
|                                |                       +---> completed.log/     |
|                                |                       +---> gyroscope.log      |
|                                |                                                |
|                                +-------> Job_Router_Node     ----> jobs.log     |
|    Consumer(jobintake.log) -------------> jobs.log (direct)                     |
|    Consumer(requests.log)  -------------> Flame_Builder_Node -> flames.log      |
|                                              +---> Stats_Store -> mc            |
|                                                                                 |
|  topology flame-builder.pN:                                                     |
|                                                                                 |
|    Consumer(requests.log) ----> Flame_Builder_Node ----> flames.log             |
|                                              +---> Stats_Store -> mc            |
|                                                                                 |
|  substrate stock job-worker.pN topology (not shipped here):                     |
|                                                                                 |
|    Consumer(jobs.log) ----> Job_Worker_Node ----> registered handlers           |
+---------------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       AGGREGATOR HUB (one per site)                      |
|                                                                          |
|  Stream_Merger_Node (cURL multi)                                         |
|     |                                                                    |
|     +-- SSE pull -->  remote spoke 1 firehose                            |
|     +-- SSE pull -->  remote spoke 2 firehose                            |
|     +-- SSE pull -->  remote spoke N firehose                            |
|     |                                                                    |
|     v                                                                    |
|  Topic(local firehose.log)  -- KEY-routed ->  Partition pN               |
|                                                                          |
|  filter newspack_nodes/aggregator_ingest_line:                           |
|     k:"job"  ->  k:"remote_job"   (separate handler map on the hub)      |
+--------------------------------------------------------------------------+
```

## Write Path: Log_Manager

`Log_Manager` is the per-request firehose writer. It's the only thing in the plugin that runs *during* a WordPress request — everything else runs in the worker fleet.

```php
class Log_Manager {
    private const MAX_DATA_SIZE  = 3840;      // 4096 - JSON envelope overhead
    private const MAX_LOG_LINES  = 40000;     // per-request soft cap
    private const MAX_TIMER_DEPTH = 100;      // start/complete nesting cap

    public function start( string $label, array $data = [] ): void;
    public function complete( string $label, array $data = [] ): void;
    public function message( string $category, array $data = [] ): bool;
    public function error( string $message ): bool;
    public function warning( string $message ): bool;
    public function info( string $message ): bool;
    public function finish(): void;
}
```

**Wire shape** (one line of `firehose.log`, JSONL):

```json
{"r":"<request_id>","t":<ts_ms>,"c":"<category>","k":"start|complete|<keyword>","m":{...},"l":"..."}
```

- `r` — request_id, 16 hex chars + ":<pid>" suffix to disambiguate concurrent requests on the same FPM worker.
- `t` — wall-clock millis, monotonic per request.
- `c` — category (`http`, `query`, `cache`, `image_squisher`, …).
- `k` — keyword. `start` and `complete` form matched pairs that Request_Builder_Node reconstructs into nested spans. Anything else is a one-shot entry (`job`, `error`, `warning`, `info`, custom event).
- `m` — payload (nested array, JSON-encoded). Flame_Builder_Node reads this for per-event durations.
- `l` — optional label/free-text.

### URL-secret redaction

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`. Query-string values for `api_key`, `token`, `secret`, `bearer`, etc. become `=[REDACTED]`. The list intentionally errs broad — anything that looks like a credential gets blanked. The pattern is compiled once at file load; redaction is a single `preg_replace` per URL.

### Refuse-root

`Log_Manager::__construct()` calls `\posix_geteuid()` early. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction silently no-ops and `enable_logging` flips to false for the rest of the request. The error log gets one rate-limited line so the operator notices.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. `Log_Manager::matches_url_filter()` short-circuits true for worker requests so spawn round-trips don't pollute the firehose, but `Request_Builder_Node` checks the same env var when stamping `is_worker` on the completed request so dashboards can filter worker traffic OUT of global stats. Without that exclusion, the supervisor's per-15s spawn cycle would dominate every leaderboard.

### PIPE_BUF and truncation

Per-line writes go to `Topic::fill()` → `Partition::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the JSON envelope around `m`. Anything larger is truncated: an `error_log` notice fires, the category gets `" (truncated)"` appended, and the data is replaced with a 1000-char excerpt (`['m' => substr($data_json, 0, 1000) . '...']`). Oversize payloads belong in `Job_Intake::queue()` (which goes through `Partition::allow_large_writes()` and the per-partition write lock). Truncation never throws — the firehose is fire-and-forget — so size discipline is the caller's responsibility.

### Per-request lifecycle

```
plugins_loaded             Log_Manager::instance() — constructs, registers shutdown hook
hook_start (priority -10000)   App\Core::hook_start( hook_name )
hook callbacks run
hook_spacer (priority MAX-2)   App\Core::hook_spacer — sacrificial no-op
hook_complete (priority MAX-1) App\Core::hook_complete( hook_name )
…
shutdown                   ::finish()   — closes orphaned start entries, emits (process complete)
```

The shipped default start priority is `-10000` (`hook_start_priority` in `newspack-event-logger-nodes-config.php`); `App\Core` falls back to `1` only when the key is absent. The `hook_spacer` at `PHP_INT_MAX-2` (`App\Core::SPACER_PRIORITY`) is a sacrificial no-op registered on every instrumented hook so a self-removing filter (e.g. es-wp-query) that unhooks itself mid-run can't shift the WP filter pointer past `hook_complete` and skip the matching close.

Orphaned `start`s (callback threw, exit called, fatal error before `complete`) get a synthetic `complete` at `finish()` time with `error_status='I'` so Request_Builder_Node can render the request as incomplete in the dashboards instead of waiting for a `complete` that will never arrive.

## Topologies

Each worker group is one declarative `.tsl` file in `topologies/`. A TSL file is a line-oriented script the substrate's topology loader interprets per partition: `make_node <Type> <name> [ctor args…]` instantiates a Node, `connect_node <from> <to>` wires a sink, `cmd <node>:config <verb> [args…]` runs a config verb on the node, and `var <key> = <value>` declares frontmatter the supervisor reads via `Topology_Registry::frontmatter()`. Tokens like `<partition>`, `<config:logs_dir>`, `<config:num_partitions>` are interpolated at load time against the substrate Config; `<eln:auto_disable_threshold>`, `<eln:is_hub>`, etc., resolve against the application Config (the v0.4.0 namespace split). The `make_node` first argument is a **shell name** that the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the single-word substrate types (`Consumer`, `Partition`, `Tee`, `Topic`) resolve under the substrate's own prefix. Six `.tsl` files ship: `combined`, `performance`, `request-builder`, `flame-builder`, `job-router`, and `aggregator`. Job *dispatch* (`Job_Worker_Node` tailing `jobs.log`) is NOT a file shipped here — it comes from the substrate's stock `job-worker` topology (the local `job-worker.tsl` was deleted in v0.12.0 when `Job_Worker_Node` moved to the substrate).

### `topologies/combined.tsl`

The everything-in-one worker. Tails `firehose.log` + `requests.log` + `jobintake.log`; runs `Request_Builder` + `Flame_Builder` + `Job_Router`; writes `requests.log`, `errors.log`, `completed.log`, `gyroscope.log`, `flames.log`, `jobs.log`.

```tsl
make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/firehose.p<partition>
make_node Consumer requests:consumer <config:logs_dir>/requests.p<partition> <config:offsets_dir>/requests.p<partition>
make_node Consumer jobintake:consumer <config:logs_dir>/jobintake.p<partition> <config:offsets_dir>/jobintake.p<partition>
make_node Request_Builder request-builder 100 3
make_node Flame_Builder flame-builder
make_node Job_Router job-router
make_node Partition completed:partition <config:logs_dir>/completed.p<partition> 1048576 <config:num_segments> 0
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.p<partition> 1048576 <config:num_segments> 0
make_node Partition errors:partition <config:logs_dir>/errors.p<partition> ...
make_node Partition requests:partition <config:logs_dir>/requests.p<partition> ...
make_node Partition flames:partition <config:logs_dir>/flames.p<partition> ...
make_node Partition jobs:partition <config:logs_dir>/jobs.p<partition> ...
make_node Tee completed:tee
make_node Tee firehose:tee
cmd request-builder:config set_completed_target completed:tee
cmd request-builder:config set_errors_target errors:partition
cmd request-builder:config set_inflight_target gyroscope:partition
cmd firehose:consumer:config set_snapshot_node request-builder
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_auto_tune <eln:auto_disable_threshold> <eln:auto_protect_time_threshold>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flame-builder:config set_significant_events <eln:significant_events_csv>
cmd requests:partition:config void_warranty
cmd requests:partition:config with_index request-index
cmd flames:partition:config void_warranty
cmd flames:partition:config with_index flame-index
cmd jobs:partition:config void_warranty
connect_node firehose:consumer firehose:tee
connect_node firehose:tee request-builder
connect_node firehose:tee job-router
connect_node request-builder requests:partition
connect_node completed:tee completed:partition
connect_node completed:tee gyroscope:partition
connect_node requests:consumer flame-builder
connect_node flame-builder flames:partition
connect_node job-router jobs:partition
connect_node jobintake:consumer jobs:partition
```

The Tee is on the firehose side because that source fans out to two targets (`request-builder` + `job-router`). The jobintake Consumer here connects directly to `jobs:partition` (the intake side is already routed at write time, so it bypasses `Job_Router` in this topology). **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** Single-target inputs connect directly. Number of Tees = number of source-fan-outs, not number of sources.

`cmd <partition>:config void_warranty` on the output Partitions lifts the per-message cap to 10MB *without* a per-Partition lock — each is written by exactly one worker fleet, and the substrate refuses to spawn a topology set where two fleets write the same partition, so the exclusivity lock that `allow_large_writes` carries is redundant here (v0.16.0). `Request_Builder` JSON regularly exceeds the 4KB PIPE_BUF atomic-append ceiling on pages with many timed hooks. The `completed:tee` fan-out carries the per-request compact summary `Request_Builder` emits at request-complete time; `gyroscope:partition` additionally receives the periodic in-flight snapshots from the hidden `Request_Flight_Node` sibling, which fire on the Router's 1s TIMER (enabled by a non-empty `set_inflight_target`, with no separate interval knob). The `cmd firehose:consumer:config set_snapshot_node request-builder` line wires the consumer's offsetlog to checkpoint `Request_Builder`'s in-flight cache, so in-flight requests survive a worker respawn (v0.16.0).

### `topologies/performance.tsl`

`combined` minus the job branch — `Request_Builder` + `Flame_Builder` only. Tails `firehose.log` + `requests.log`; writes `requests.log`, `errors.log`, `completed.log`, `gyroscope.log`, `flames.log`. The firehose Consumer connects straight to `request-builder` (single target, no Tee). Use it where job dispatch lives in a separate fleet (or the substrate stock `job-worker`) and you want request assembly + flame stats in one worker group.

### `topologies/request-builder.tsl`

Just the assembly branch. Tails `firehose.log`; `Request_Builder` writes `requests.log`, `errors.log`, and the `completed:tee` fan-out to `completed.log` + `gyroscope.log`. No Flame_Builder, no jobs — this is the `firehose → requests` half on its own.

```tsl
make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/firehose.p<partition>
make_node Partition completed:partition <config:logs_dir>/completed.p<partition> 1048576 ...
make_node Partition errors:partition <config:logs_dir>/errors.p<partition> ...
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.p<partition> 1048576 ...
make_node Partition requests:partition <config:logs_dir>/requests.p<partition> ...
make_node Request_Builder request-builder
make_node Tee       completed:tee
cmd request-builder:config set_completed_target  completed:tee
cmd request-builder:config set_errors_target     errors:partition
cmd request-builder:config set_inflight_target   gyroscope:partition
cmd firehose:consumer:config set_snapshot_node   request-builder
cmd requests:partition:config void_warranty
cmd requests:partition:config with_index request-index
connect_node completed:tee completed:partition
connect_node completed:tee gyroscope:partition
connect_node firehose:consumer request-builder
connect_node request-builder requests:partition
```

### `topologies/flame-builder.tsl`

Per-partition flame builder. Tails `requests.log`; `Flame_Builder` emits `flames.log` and bumps the 9-namespace memcache schema via `Stats_Store`.

```tsl
make_node Consumer requests:consumer <config:logs_dir>/requests.p<partition> <config:offsets_dir>/requests.p<partition>
make_node Flame_Builder flame-builder
make_node Partition flames:partition <config:logs_dir>/flames.p<partition> ...
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_auto_tune <eln:auto_disable_threshold> <eln:auto_protect_time_threshold>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flame-builder:config set_significant_events <eln:significant_events_csv>
cmd flames:partition:config void_warranty
cmd flames:partition:config with_index flame-index
connect_node requests:consumer flame-builder
connect_node flame-builder flames:partition
```

`configure_stats <partition>` constructs the per-partition `Stats_Store`; the auto-tune verbs feed the noisy/significant-event detection (see [Flame_Builder_Node](#flame_builder_node) and [Auto_Tuner_Node](#auto_tuner_node)). The `<config:…>` tokens resolve against the substrate Config (`logs_dir`, `num_partitions`, `segment_size`, etc.); the `<eln:…>` tokens resolve against the application Config (`auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events_csv`, `is_hub`, `aggregator_*`) — the v0.4.0 split that gave app-owned values their own token namespace so substrate-only resolvers can't accidentally swallow them.

### `topologies/job-router.tsl`

The job-routing half. Tails `firehose.log` + `jobintake.log`; `Job_Router` normalizes both sources and writes `jobs.log`. Dispatch of those `jobs.log` lines is the substrate stock `job-worker` topology, not this file.

```tsl
make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/firehose.p<partition>
make_node Consumer jobintake:consumer <config:logs_dir>/jobintake.p<partition> <config:offsets_dir>/jobintake.p<partition>
make_node Job_Router job-router
make_node Partition jobs:partition <config:logs_dir>/jobs.p<partition> ...
cmd jobs:partition:config void_warranty
connect_node firehose:consumer job-router
connect_node job-router jobs:partition
connect_node jobintake:consumer job-router
```

Both Consumers feed `job-router` directly — each has a single target, so neither needs a Tee. `Job_Router` disambiguates the two sources by the Consumer name stamped on the Message FROM (see [Job_Router_Node](#job_router_node)).

### `topologies/aggregator.tsl`

Hub-side ingest. One `Stream_Merger` pulls from configured spokes via SSE; sinks into a multi-partition Topic that KEY-routes by URL hash so each downstream firehose-consuming partition sees its own slice.

```tsl
make_node Topic firehose:topic <config:logs_dir>/firehose.p<partition> <config:num_partitions> ...

make_node Stream_Merger stream-merger firehose <partition>
cmd stream-merger:config set_verify_ssl <eln:aggregator_verify_ssl>
cmd stream-merger:config set_require_https <eln:aggregator_require_https>
connect_node stream-merger firehose:topic
```

`Stream_Merger` is a fan-in (partition count comes from the destination Topic, not the merger), and it owns a hidden `Health_Check_Tick` sibling (`stream-merger:health-check`) that hitchhikes the same Router TIMER heartbeat to run the periodic discovery + sync sweep. Registry remotes load automatically when `connect_node` wires the target (lifecycle hook on `Stream_Merger::connect_node`) — there's no separate `load_remotes_from_registry` verb. SSL/HTTPS setters run BEFORE `connect_node` so the `Remote_Source` children created during the load inherit current policy. The `k:"job"` → `k:"remote_job"` rewrite filter is registered statically at plugin load (`Stream_Merger_Node::register_remote_job_rewrite_filter`), not from TSL. Aggregator is **gated** by the `enable_aggregator` config key (typed bool, default OFF): if not explicitly enabled, the topology isn't activated, the supervisor never spawns the worker, and the admin "Aggregator" submenu is hidden. Hubs opt in by setting `'enable_aggregator' => true` in their `newspack-event-logger-nodes-config.php` overlay or via the admin checkbox.

### Topology resolution

As of v0.5.0, the `topologies` key lives on the substrate — `newspack-event-logger-nodes-config.php` no longer owns it. The plugin only **publishes its catalog**: at boot, it calls `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' )` — one call that registers the application namespace prefix (for `make_node` short-name resolution) AND the stock-topology dir together — so anyone calling `Topology_Registry::resolve()` (admin, REST, tests, CLI, supervisor) finds the stock `.tsl` files. Which catalog entries actually spawn workers is decided downstream by the substrate's Topologies multi-select option (`newspack_nodes_topologies`) — this plugin only publishes the "what topologies exist" set; the substrate filters it by what the operator has checked. `num_partitions` defaults also come from the substrate config, so one setting drives both `Log_Manager` (write side) and the worker fleet (read side); hardcoding diverges them.

Cost on regular WP requests is one array append at boot — the `.tsl` files themselves aren't parsed yet. Actual resolution + parsing happens in three places, none on the page-render hot path: supervisor's `check_config()` tick (every 15s), worker bootstrap (once per spawn), REST workers/dashboard reads.

## Application Nodes

### Request_Builder_Node

Assembles requests from firehose entries via the `LRU_Cache` 3-bucket timed-rotation cache. Bucket rotation every 200s; full retention window 3 × 200s = 600s (10 min). Orphans evicted from the oldest bucket emit as timed-out (`error_status: 'T'`). As of v0.16.0 it's a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`); `fire()` rotates the in-flight cache on each tick, so a stalled request still times out and gets written even when no further firehose lines arrive to drive rotation via `fill()`.

```php
class Request_Builder_Node extends Timer_Node {
    private const BUCKET_ROTATION_S   = 200;   // 3 × 200s = 600s retention
    private const DEFAULT_BUCKET_SIZE = 100;
    private const DEFAULT_NUM_BUCKETS = 3;

    public function fill( array &$message ): void {
        // Parse firehose JSONL line; assemble start/complete pairs into request.
        // On rotation: evict timed-out bucket (sets error_status='T' on each orphan).
        // On overflow: evict oldest entry from oldest bucket.
        // On request complete: emit JSON-encoded record via $this->sink.
    }

    protected function fire(): void {
        // Router-TIMER tick (1s): rotate the in-flight cache so stalled
        // requests time out even on idle / low-traffic partitions.
    }
}
```

Eviction emits via `$this->sink->fill( $synthetic_message )`, NOT direct file writes. This matters for composability: timed-out requests must flow through Tee so errors.log gets a copy, hooks can observe, tests can capture. Don't write to files from inside an eviction callback.

### Request_Flight_Node

Backs the Gyroscope dashboard's live in-flight view. It's a hidden `Timer_Node` sibling that `Request_Builder_Node` owns (modeled on Perl Tachikoma's `InstrumentalityFlight.pm`): on each timer tick it snapshots the patron's in-progress request map and emits a compact-summary batch downstream to the gyroscope partition, so the page can render a live treemap of what's running right now plus a fading trail of recent completions.

```php
class Request_Flight_Node extends Timer_Node {
    protected function fire(): void;   // inflight_snapshot() -> emit batch, on the Router's 1s TIMER
}
```

It has no `set_interval()` / `interval()` / `fire_cb()` — those were removed in v0.16.0. Snapshots hitchhike the Router's 1s TIMER via `fire()` (the `Timer_Node` contract); a non-empty `set_inflight_target` is the sole enable switch (an empty one stops them). The node is hidden from the topology console by the substrate's patron filter in `dump_metadata`. Its configuration surfaces on the patron's `:config` interpreter as `set_inflight_target` — the `combined` / `performance` / `request-builder` topologies wire it with `cmd request-builder:config set_inflight_target gyroscope:partition`. Because the snapshot rides `Request_Builder_Node`'s own in-flight map (the same `LRU_Cache` buckets it already maintains for request assembly), there's no second tracker to keep coherent — the previous standalone `InflightTracker` class (deleted in the M6 consolidation, along with the legacy per-stream SSE controllers) was a separate in-memory copy of state the builder already held.

The gyroscope partition therefore carries two interleaved record shapes: per-request `complete` rows (via the `completed:tee` fan-out) and the periodic active-state rows `Request_Flight_Node` emits. The browser dispatches them client-side (`transformGyroscopeLine`); there's no longer a server-side `GyroscopeStreamController`.

### Flame_Builder_Node

Aggregates completed-request events into flame-graph stats and writes flame data to `flames.log`. Receives JSON-encoded completed requests from Request_Builder_Node. Holds a 5×1000 LRU `stats_cache` (`STATS_CACHE_NUM_BUCKETS × STATS_CACHE_BUCKET_SIZE`) of per-URL accumulators; rotates buckets on overflow. Emits flame data into hourly, leaderboard, per-server leaderboard, URL, dimensional, and category memcache namespaces via `Stats_Store`.

When `auto_disable_threshold` and/or `auto_protect_time_threshold` are configured, Flame_Builder_Node also runs noisy / significant event detection during its 5s flush window. Hooks that fire more than `auto_disable_threshold` times in the window are candidates for disable; hooks whose mean event time exceeds `auto_protect_time_threshold` are candidates for "significant" status (protected from auto-disable). Decisions emit downstream as `TM_STRUCT` messages routed to the `auto-tuner` Node — see [Auto_Tuner_Node](#auto_tuner_node) for the dispatch + fan-out.

```php
class Flame_Builder_Node extends Node {
    const EMA_SAMPLE_LIMIT      = 1000;
    const FLUSH_INTERVAL_SEC    = 5;
    const ENTRY_LIMIT_URL_UPPER = 40;
    const ENTRY_LIMIT_URL_LOWER = 20;
    private const MAX_STACK_DEPTH = 50;                          // flame-tree depth cap
    public const FLAME_JSON_DEPTH = 2 * self::MAX_STACK_DEPTH + 8; // json_decode budget (2 levels/span)
    const DIM_FIELDS            = [
        'status'  => 'status_category',
        'method'  => 'request_method',
        'server'  => 'server_name',
        'country' => 'country_code',
        'from'    => 'http_from',
        'ua'      => 'user_agent',
        'ja4'     => 'ja4_hash',
    ];
}
```

Flush every 5s via Router-hitchhike Timer; flush on shutdown via `cleanup` (TM_EOF handler).

`MAX_STACK_DEPTH = 50` bounds flame-tree depth; `FLAME_JSON_DEPTH = 2 × MAX_STACK_DEPTH + 8` is the `json_decode` depth used on both write (`format_index_entry`) and read (`Performance_CI_Node::find_flame_for_rid`) so a legitimately deep flame tree round-trips instead of decoding to `null`.

### Auto_Tuner_Node

Receives Flame_Builder_Node's tuning decisions as `TM_STRUCT` messages and applies them locally; also unconditionally queues a `remote_manager` sync_setting job via Job_Intake — when a hub aggregator topology is running, the registered `remote_manager` handler picks it up and fans the change out to every enabled spoke; on non-hub sites, the queued job has no consumer and silently drops.

```
Flame_Builder_Node.apply_auto_tune()  ──TM_STRUCT msg──→  Auto_Tuner_Node.fill()
       TO=auto-tuner                                  │
       KEY=disable_hooks /                            ├─→ Settings_Sync::queue_job('remote_manager', ...)
           disable_custom_events /                    │   (writes to jobintake.log; no-op on non-hubs)
           add_significant_events                     │
       VALUE={ items: string[], context: {…} }        │
                                                      └─→ update_option(...)   (suppress_sync wrapped)
```

The KEY discriminates the option being tuned; Auto_Tuner_Node dispatches by KEY inside `fill()`:

| KEY | Updates | Hub-side fanout |
|-----|---------|-----------------|
| `disable_hooks` | `newspack_event_logger_nodes_log_events` minus the items (significant events preserved) | spoke receives the same updated array |
| `disable_custom_events` | `newspack_event_logger_nodes_custom_events` minus the items (significant events preserved) | same |
| `add_significant_events` | `newspack_event_logger_nodes_significant_events` ∪ items | same |

Replaces the legacy `AutoTuneHandlers` six-listener pattern (hub @ priority 5 + standalone @ priority 10 × three events). Both sides used to wire on WP actions; both sides ran in the same flame-builder worker process; the action plumbing was intra-process IPC dressed up as hooks. Expressing it as a Node with `fill()` dispatch is one straight path through the same logic.

**Local-write suppression**: Auto_Tuner_Node wraps every `update_option` in `Settings_Sync::suppress_sync(true)` / `suppress_sync(false)` so the local write doesn't re-trigger Settings_Sync's `update_option` listener (which would re-queue the same remote sync that Auto_Tuner_Node just queued). The hub fan-out happens *before* the local write so a failed fan-out (memcache down, Job_Intake stale) doesn't block the local update.

**Distributed lock**: Flame_Builder_Node still holds a 5s `evlog:auto_disable_lock` memcache lock across the three `fill()` calls. Without it, two Flame_Builder_Node workers (different partitions, both flushing at the same instant) would fan out twice for overlapping decisions.

### Job_Router_Node

Multi-input routing. Reads firehose AND jobintake; disambiguates source via Message FROM — Consumer stamps FROM with its own node name (`firehose:consumer`, `jobintake:consumer`), and Job_Router_Node inspects the string to know which input the line came from. The body schema differs slightly between the two sources (firehose wraps under `m`; jobintake is flat), so Job_Router_Node normalizes both into a `{ k, handler, parameters, ts }` shape before forwarding to `jobs.log` for Job_Worker_Node to dispatch. The job kind is carried under `k` (not `type`) end-to-end as of v0.16.1 — the same `k` the firehose category, `Job_Intake`, and the substrate `Job_Worker_Node` all read, so there's no rename at any hop.

| FROM contains | Body key | Allowed kinds |
|---------------|----------|---------------|
| `firehose:consumer` | `entry['m']` (nested) | `job` or `remote_job` |
| `jobintake:consumer` | `entry` (flat) | `job` only — `remote_job` rewritten to `job` |

Validation:

- Handler name pattern `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- `MAX_JOB_SIZE = 33554432` (32MB)

Unknown handlers, oversized lines, and invalid handler names log via `Core::print_less_often` (rate-limited).

Job_Worker_Node (downstream) reads `jobs.log` and looks up the handler in `newspack_nodes/job_handlers` (kind=job) or `newspack_nodes/remote_job_handlers` (kind=remote_job) via `load_handlers_from_filters()`, which the worker calls in its constructor at topology bootstrap. Registration is filter-only; there are no programmatic setter methods.

### Job_Worker_Node

> **Lives in the `newspack-nodes` substrate** (`\Newspack_Nodes\Job_Worker_Node`), not this plugin — generic async-job dispatch is runtime plumbing. The per-job *request context* (logger suspend, synthetic `/jobs/{handler}` `$_SERVER`) is this plugin's concern: it's supplied by `Log_Manager::begin/end_job_context`, hooked onto the substrate's `newspack_nodes/job_worker/{before,after}_job` actions.

Executes registered job handlers. Per-job try/catch isolates failures. Fires `before_job`/`after_job` actions around each handler (the after-action runs even on throw). Calls `gc_collect_cycles()` after every job; flushes the WP object cache every `CACHE_FLUSH_INTERVAL` (default 50) jobs; latches a `memory_pressure` flag at 80% of `memory_limit` so the topology drain predicate exits cleanly and the supervisor respawns into a fresh process.

```php
// \Newspack_Nodes\Job_Worker_Node — abridged
public function fill( array &$message ): void {
    $entry    = $message[ Message::VALUE ];
    $kind     = $entry['type'] ?? '';                          // 'job' or 'remote_job'
    $handler  = $entry['handler'] ?? '';
    $handlers = ( 'remote_job' === $kind ) ? $this->remote_handlers : $this->local_handlers;
    try {
        \do_action( 'newspack_nodes/job_worker/before_job', $handler );  // ELN hooks Log_Manager::begin_job_context
        ( $handlers[ $handler ] )( $entry['parameters'] ?? [] );
    } catch ( \Throwable $e ) { Core::print_less_often( /* ... */ ); }
    finally { \do_action( 'newspack_nodes/job_worker/after_job', $handler ); } // → Log_Manager::end_job_context
    ++$this->jobs_executed;
    \gc_collect_cycles();
    if ( ++$this->jobs_since_cache_flush >= self::CACHE_FLUSH_INTERVAL ) {
        \wp_cache_flush();
        $this->jobs_since_cache_flush = 0;
    }
}
```

Image-handler circular refs (`wp_generate_attachment_metadata` loading full-resolution images into GD) are the documented reason for the discipline. Preserve in any successor.

### Stream_Merger_Node

Hub-side fan-in over remote spokes. `Stream_Merger_Node` is now a coordinator: it instantiates one `Remote_Source_Node` child per `Server_Registry::get_enabled()` entry (each owns its own cURL easy handle on a shared multi-handle registered with `Event_Framework`, plus its own SSE parser and cursor), owns one shared offsetlog `Partition_Node` (`$offsetlog`) for the whole hub, and periodically walks its children to commit positions. The offsetlog is a named sibling registered in `Core`; `Stream_Merger_Node::remove_node()` tears it down explicitly (`$this->offsetlog?->remove_node()`) along with each `Remote_Source_Node` child and the `Health_Check_Tick` sibling so a removed merger doesn't leak nodes in `Core`. The per-remote SSE pull, reconnect/backoff, and envelope parsing live in `Remote_Source_Node` (M6.7 migrated its feed to the substrate's `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume). `Stream_Merger_Node::MAX_BACKOFF` / `INITIAL_BACKOFF` are aliases of the `Remote_Source_Node` constants. `Stream_Merger_Node`'s own `process_sse_chunk()` is a **test-only delegate** — it lazily spins up a synthetic `__test__` `Remote_Source_Node` and forwards the chunk to it so prototype-era fixtures keep working; production never calls it. The real parsing path below lives in `Remote_Source_Node`.

```php
// includes/class-remote-source-node.php — the production SSE-parse path.
class Remote_Source_Node extends Node {
    public const MAX_BACKOFF     = 30;
    public const INITIAL_BACKOFF = 1;

    public function process_sse_chunk( string $chunk ): void {
        $this->buffer .= $chunk;
        while ( ( $pos = strpos( $this->buffer, "\n\n" ) ) !== false ) {
            $event        = substr( $this->buffer, 0, $pos );
            $this->buffer = substr( $this->buffer, $pos + 2 );
            // Extract data: lines, apply newspack_nodes/aggregator_ingest_line filter
            // (the k:"job" -> k:"remote_job" rewrite for hub topologies),
            // emit TM_BYTESTREAM with VALUE = filtered payload into the shared Topic sink.
        }
    }
}
```

Position advances on successful local-Topic write OR on intentional drop by an ingest filter; failed writes leave the remote position unchanged so the next iteration re-fetches. Per-remote reconnect uses `current_backoff` (starts at `INITIAL_BACKOFF`, capped at `MAX_BACKOFF`).

Stream_Merger_Node does NOT perform the `k:"job"` -> `k:"remote_job"` rewrite itself. The rewrite is a `newspack_nodes/aggregator_ingest_line` filter (renamed from `newspack_event_aggregator_ingest_line`) registered by the application plugin's hub-side bootstrap; Stream_Merger_Node applies the filter chain. Plugins that don't load the rewrite filter (because they're spokes, not hubs) get raw `k:"job"` entries through.

### Stats production (owned by Flame_Builder_Node)

There is no longer a separate stats Node. The standalone `StatsAggregator` Node — the variant for topologies that wanted memcache stats without flame data — was removed in the M6 consolidation; `Flame_Builder_Node` is the single stats producer, owning flame generation AND the 9-namespace memcache fan-out via its injected `Stats_Store`. The `flame-builder` (and `performance` / `combined`) topologies wire the store with `cmd flame-builder:config configure_stats <partition>`.

Each completed request is folded into Flame_Builder_Node's in-memory pending stats across the same dimension set the reader paths whitelist:

```php
// Inside Flame_Builder_Node, per completed-request accumulation:
private const DIM_FIELDS = [
    'status' => 'status_category', 'method' => 'request_method',
    'server' => 'server_name',     'country' => 'country_code',
    'from'   => 'http_from',       'ua'     => 'user_agent',
    'ja4'    => 'ja4_hash',
];
```

On flush, Flame_Builder_Node reads the existing Stats_Store buckets, merges the pending request data into those maps, applies the caps/pruning rules, and writes the whole updated bucket/map back through the explicit `set_*` methods (`set_hourly`, `set_url_index_hourly`, `set_leaderboard_bucket`, `set_dimensional`, etc.). Stats_Store is storage-only; it does not own per-request aggregation logic.

When no `Stats_Store` is configured (`configure_stats` never called — e.g. in unit tests), the builder still emits flame data without touching memcache.

## Memcache Schema (9 Namespaces)

Per-key prefix: `evlog[:salt]:p{N}:{namespace}:...`

| Namespace | Use | TTL |
|-----------|-----|-----|
| `hourly` | `Y-m-d-H` buckets, count + sum_ms + sum_peak_mb | `max_lifespan` (default 86400) |
| `lb` | 5-min global leaderboard buckets, sums-not-means | `max_lifespan` |
| `lb_s` | per-server leaderboard, keyed by server | `max_lifespan` |
| `urls` | 5-min URL index, keyed by URL -> {count, sum_req_time, samples} | `max_lifespan` |
| `url` | per-URL flame/profile blob | `max(3600, max_lifespan/24)` |
| `dim` | dimensional time series (status/method/server/...) | `max_lifespan` |
| `url_dim` | per-URL dimensional time series | `max_lifespan` |
| `categories` | global category time series | `max_lifespan` |
| `url_cat` | per-URL category time series | `max_lifespan` |

**Caps prevent value-explosion** against memcache's 1MB/value limit:

- `MAX_DIM_VALUES = 20`
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`

Overflow rolls into a synthetic `Other` bucket. The `total` pseudo-category is preserved before sorting — see the existing Flame_Builder_Node implementation; do not regress when porting.

**`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff. The shared `Core::$memd` (`\Memcached`) provides `getMulti` natively; the in-memory test double mirrors it.

## Stats_Store: Sums-Not-Means + Salt Rotation

**Sums-not-means storage**: Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket and cross-partition merge is exact addition. Display layer computes means at read time (`sums_to_display`).

This fixes an EMA-clamp bug the old running-mean storage had — do NOT regress to incremental averages. They look mathematically equivalent but break under merge.

**Schema migration via salt rotation**:

```php
public function flush_all(): bool {
    $salt = bin2hex( random_bytes( 4 ) );
    update_option( 'newspack_event_logger_nodes_stats_salt', $salt );
    // All existing keys orphan instantly; they expire via TTL.
    // No scrubber walks; no large memcache scan.
    return true;
}
```

Long-running workers cache `prefix` at construction. After `flush_all()`, they keep writing to keys with the OLD salt until they respawn; readers see "no data" for `max_lifespan` after the rotation. Trigger a worker restart via `Lock::request_restart()` if you need immediate effect.

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
              |  enable_aggregator on       |
              |                             |
              |  Stream_Merger_Node pulls   |
              |  from configured spokes     |
              |                             |
              |  Remote_Manager handles     |
              |  remote_job dispatch        |
              |                             |
              |  Settings_Sync fans out     |
              |  options to spokes          |
              +-----------------------------+
```

**Hub identification**: a site is acting as a hub when `enable_aggregator` is truthy AND it has at least one spoke registered. The toggle is a single operator switch — typed bool, default OFF (fresh installs are not hubs); persisted by `register_setting` as `0`/`1` with a truthy admin-side read (`! empty( $cfg['enable_aggregator'] )`). It gates the Aggregator admin submenu visibility. Push-side fan-out listeners (`Settings_Sync`, `Auto_Tuner_Node`) are themselves ungated — they always queue a `remote_manager` job; without an aggregator topology running there's no consumer, and the queued job silently no-ops. Pull-side activation (whether the Stream_Merger_Node worker actually spawns) is decoupled — driven by whether `aggregator` is in the substrate's Topologies multi-select.

**`k:"job"` vs `k:"remote_job"`**:

Nodes only ever write `k:"job"` to their own firehose — there's no "spoke vs hub" distinction at write time. The distinction emerges at dispatch:

- Every node's Job_Worker_Node tails its own `jobs.log` and dispatches `k:"job"` entries against the `newspack_nodes/job_handlers` filter. This runs locally on every node, hub or spoke.
- The hub additionally runs Stream_Merger_Node, which pulls each enabled spoke's firehose. Stream_Merger_Node applies the `newspack_nodes/aggregator_ingest_line` filter, which rewrites the ingested `k:"job"` lines to `k:"remote_job"` before they reach the hub's firehose. The hub's Job_Worker_Node then dispatches those entries against `newspack_nodes/remote_job_handlers`.
- The two filters are independent registrations — a job type registers under whichever side(s) should run it:
  - **`job_handlers` only** → runs locally on every node. The hub's view of spoke-aggregated copies (as `remote_job`) is ignored.
  - **`remote_job_handlers` only** → only the hub acts. Spokes write the entries but don't act on them; the hub does the centralized work after aggregation.
  - **Both** → handler runs locally on every node, AND runs on the hub for entries aggregated from spokes. Useful when a job needs local + centralized handling under the same name (the two handler implementations can differ — e.g., one filters by local attributes, the other dispatches differently).

The rewrite filter is NOT auto-loaded. Plugins that don't register the filter (because they're spokes, not hubs) get raw `k:"job"` entries through and dispatch locally — exactly what spokes want.

## Hub-Side Helpers: Server_Registry / Remote_Manager / Discovery

Three static-mode classes own the hub's outbound side. They don't run in the worker fleet — they run in admin requests (Remote Servers UI), inside Job_Worker_Node contexts when handling `remote_manager` jobs, and on the hub's periodic health-check tick.

### Remote activity gate: `enable_aggregator`

A single config flag, `enable_aggregator` (default OFF), gates the operator-visible side of hub-mode:

- **Admin submenu** — the `Aggregator` submenu under Performance is hidden when off. The "Enable Aggregator" checkbox in Event Logger Settings → Remote Servers is the operator-facing toggle.
- **Push side** — `Settings_Sync::maybe_queue_static_sync` and `Auto_Tuner_Node::persist` always queue a `remote_manager` job. The structural gate is the consumer: without an aggregator topology running there's no `remote_manager` handler registered, so the queued jobs silently no-op. The admin checkbox is the operator-visible part of the gate (Aggregator submenu + topology selection); the dispatch layer takes care of the rest.

Pull-side worker activation is independent: the Stream_Merger_Node spawns whenever `aggregator` is in the substrate's Topologies multi-select. Two operator choices, two surfaces — checking "Enable Aggregator" without also checking the `aggregator` topology gives you a visible dashboard with no live data; checking the topology without the checkbox gives you a running worker but no UI to see it. The intentional design is that operators do both when standing up a hub; either alone is a partial state.

### `Server_Registry`

CRUD for the spoke list. Storage is `newspack_event_logger_nodes_aggregator_servers` (a single autoloaded option) merged with whatever `aggregator_servers` keys arrive via `newspack_event_logger_nodes/config` filter. Filter-supplied entries are read-only at the registry level — `remove()` no-ops on a config-supplied server (`is_config_server()` returns true) so an operator click can't disable a server the config file declares.

```php
class Server_Registry {
    public const MAX_SERVERS = 100;

    public function get_all(): array;                   // merged option + filter
    public function get_enabled(): array;               // get_all() filtered by enabled=true
    public function get( string $id ): ?array;
    public function add( string $id, array $config ): bool;
    public function update( string $id, array $partial ): bool;
    public function remove( string $id ): bool;        // no-op on config-supplied entries
    public function is_config_server( string $id ): bool;
}
```

Validation at write time: ID matches `/^[a-zA-Z0-9_-]{1,64}$/`, URL is a valid HTTPS URL on the `ALLOWED_ENDPOINT_PREFIXES` whitelist (no smuggling arbitrary endpoints through the form), `auth_username` is a WordPress username (sanitized), `auth_password` is sodium-secretbox encrypted at rest (key derived from `wp_salt('auth')` via `sodium_crypto_generichash`); legacy plaintext rows still decode through the same accessor. Cap of 100 servers per registry to bound the periodic health-check sweep.

### `Remote_Manager`

Outbound HTTP to spokes plus a registered `remote_manager` job handler. Two distinct roles:

1. **Discovery sweep** (periodic): walks every enabled server, dispatching a `discovery.get` TM_COMMAND to each spoke's `/wp-json/newspack-nodes/v1/command` endpoint (the legacy `/wp-json/newspack-nodes/v1/discovery` REST route was deleted in M5 in favor of the unified command path), collects per-server payloads, then calls `Health_Check_Extensions::process_discovery()` directly (and also fires `newspack_event_logger_nodes/health_check_discovery` for external listeners), then calls `sync_all_settings()` to push every operator-configured option to every enabled spoke.

2. **`remote_manager` job handler**: registered via `newspack_nodes/job_handlers`. The handler dispatches by `action`:
   - `sync_setting` → POST `{option, value}` to every enabled spoke under the configured endpoint. The settings endpoint must be in `ALLOWED_ENDPOINT_PREFIXES` (`newspack-nodes` or `newspack-nodes-aggregator` namespaces only).
   - `health_check` → idempotent shortcut for dispatchers that prefer queueing over `add_action`.
   - Anything else → looked up in `newspack_event_logger_nodes/remote_actions` filter for plugin extension. Unknown actions log via `print_less_often` and drop.

Stale-job protection: every `remote_manager` job carries `queued_at`. Jobs older than `STALE_THRESHOLD` (600s) drop with a rate-limited error log. Prevents a stuck cron tick from blasting through dozens of obsolete sync requests in a row.

Caps: `MAX_SERVERS = 100`, `MAX_SETTINGS = 50` per sync. Prevents a filter-abuse from triggering an unbounded fan-out.

### `Health_Check_Extensions`

Merges discovered hooks and custom events from spokes into the hub's local settings. Called directly by `Remote_Manager::health_check()` (no WP action indirection — the in-plugin coupling is one method call). The action is *also* fired alongside, so external plugins (pyrobase, etc.) can still subscribe.

`process_discovery( array $all_discovery )` does two merges:

- Discovered **registered hooks** from each spoke → `newspack_event_logger_nodes_log_events`. New names get added; existing names stay; the local "checked / unchecked" state is preserved (discovered hooks land unchecked by default).
- Discovered **custom events** from each spoke → `newspack_event_logger_nodes_discovered_events`. New events get a `true` flag; existing entries are left alone.

Both merges suppress `Settings_Sync` first (`Settings_Sync::suppress_sync()` / `finally`) so the local `update_option` write doesn't get fanned BACK out to the spokes that just contributed it. Without the suppression, every discovery sweep would echo every spoke's hooks to every other spoke. Cap on accumulated entries: `MAX_EVENTS = 10000`.

## Settings_Sync: No Operator Gate

`Settings_Sync::maybe_queue_static_sync` is **ungated** — it always queues a `remote_manager` job into JobIntake when a synced option changes. The gate is structural: without an aggregator topology running and remotes registered, the queued job has no consumer and silently no-ops (the `Job_Worker_Node` finds no `remote_manager` handler in `newspack_nodes/job_handlers` and drops the line). Any topology that DOES dispatch `remote_manager` jobs picks them up automatically.

`Auto_Tuner_Node::persist` is also ungated — it always queues the `remote_manager` sync_setting job before the local `update_option` write, for the same no-consumer-equals-no-op reason. This was a deliberate simplification: the legacy `enable_workers`-as-hub-designation polarity dance was retired in v0.5.0. The remaining operator-facing toggle is `enable_aggregator` (typed bool, default OFF), which gates the Aggregator admin submenu visibility and the Stream_Merger_Node pull-side activation; push-side fan-out listeners (Settings_Sync, Auto_Tuner) just queue, and let dispatch silently drop on non-hubs. Letting the no-consumer drop happen at dispatch time is cheaper and harder to misconfigure than a per-listener `get_option` gate.

**Re-entrancy guard via `$syncing`**: Health_Check_Extensions calls `update_option` in response to a sync; without the guard, that triggers another sync, ad infinitum. The `suppress_sync(true)` API is called before the update, restored after.

**Job_Intake for >4KB payloads**: options like `log_events` (50+ hook names) routinely exceed 4KB. Settings_Sync uses `Job_Intake::queue('remote_manager', ...)` with the option name as the partition key (so one sync per option at a time across the queue).

**Endpoint allowlist**: `ALLOWED_ENDPOINT_PREFIXES = ['/wp-json/newspack-nodes/', '/wp-json/newspack-nodes-aggregator/']` + handler-name parameter sanitization. Security boundary; port verbatim with renamed prefixes.

## Job_Intake vs Firehose Routing

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
- Job needs to be aggregated from spokes to hub (only firehose entries flow through Stream_Merger_Node).

**Use Job_Intake** (`Job_Intake::queue($handler, $params)`) when:

- Payload exceeds 4KB (serialized option blobs, image-handler args, large arrays).
- Job is local-only (jobintake never aggregates; entries stay on the originating site).

Using the wrong path loses jobs. Log_Manager truncates data over `MAX_DATA_SIZE` (3840B) to a 1000-char excerpt with `" (truncated)"` appended to the category, so the handler never sees a parseable payload. Job_Intake never aggregates so spoke jobs there never reach the hub.

Job_Intake has three partition-selection modes:

- **pinned** — caller specifies partition index directly via `$intake->partition( $i )`.
- **keyed** — `Partition::hash_to_partition( $key, $num_partitions )` (URL-style, identical to firehose).
- **round-robin** — static counter modulo `PHP_INT_MAX` for callers without a meaningful key.

Lock-holding is per-Partition (no host-wide intake lock — that was removed). `Partition::allow_large_writes()` acquires the partition's write lock at construction and holds it for the partition's lifetime. One-off callers (`Job_Intake::queue()`) construct a one-shot Job_Intake, write, and let the destructor release the lock; batch callers reuse the same `Job_Intake` instance across many `queue_many()` calls so the lock acquisition cost amortizes.

## Configuration

Three storage tiers, layered in this order (later wins):

1. **File-based defaults** in `newspack-event-logger-nodes-config.php` (returns an array; loaded by `Config::load_config()` if present alongside the plugin). For shared / per-environment defaults that ship with deployment. Never edited via the admin UI.
2. **WordPress options** under the `newspack_event_logger_nodes_*` prefix (application) and `newspack_nodes_*` prefix (substrate, owned by the other plugin but read here via the substrate `Config`). Edited via the admin UI; persisted via `update_option`.
3. **Filter `newspack_event_logger_nodes/config`**: last-call override. Plugins can layer in computed values (per-server tuning, dynamic feature flags) that win over both files and options.

```php
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // every schema key, file + WP option overlay + substrate merge
$config = \Newspack_Event_Logger_Nodes\Config::load_config_defaults(); // file-only (no WP option overlay, no substrate merge)
```

`load_config()` is the single zero-arg entry point — every key in the `$option_schema` whitelist is loaded on every call, including `auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`, and the substrate keys merged in from `RuntimeConfig::load_config()`. The result is cached in a static for the rest of the request, so the cost is one round of `get_option` per schema key on first call. `load_config_defaults()` is the file-only escape hatch used by `Server_Registry` to read `aggregator_servers` defaults without the circular WP-option merge (`aggregator_servers` is intentionally not in the per-request schema — admin/hub-only, read lazily).

### Settings_Schema: one Field per setting

`Settings_Schema` (`includes/class-settings-schema.php`) is the single declaration both `Config` and `Admin` derive from — one `\Newspack_Nodes\Config_System\Field` per setting, collected into a `Schema`. It replaced the three parallel arrays `Config` and `Admin` used to hand-maintain in lockstep (`Config::$option_schema`, `Admin::$option_names`, `Admin::$delete_on_blank_options`). `Config` reads it for the overlay key-list + autoload sweep; `Admin` reads it to drive the `register_setting` / `add_settings_field` loops, the reset list, and the delete-on-blank classification. Substrate keys (`base_directory`, partitioning, `memcache_servers`, `topologies`) are owned by the substrate's own Settings_Schema under `newspack_nodes_*` and reach this plugin via the `array_merge(RuntimeConfig::load_config(), …)` layering — never declared here. `aggregator_servers` is the one carve-out: it has an option name and file default but is neither an overlay key nor a form field (encrypted spoke credentials, read lazily by `Server_Registry`), so it's absent from the Field set. The schema layer builds on the shared `\Newspack_Nodes\Config_System` (`Field`, `Schema`, plus the substrate's renderer / overlay / reset-gate machinery).

### Application option keys

| Option | Type | Loaded by | Default | Use |
|--------|------|-----------|---------|-----|
| `enable_logging` | bool | `load_config()` | `true` | Master switch for the firehose write path |
| `enable_aggregator` | bool | `load_config()` | `false` | Gates the Aggregator admin submenu (operator-visible side of hub-mode); push-side fanout listeners are themselves ungated and rely on no-consumer-as-no-op |
| `log_urls` | array of strings | `load_config()` | `[]` | URL substring allowlist (empty = log everything) |
| `skip_urls` | array of strings | `load_config()` | substrate-command paths | URL substring denylist; wins over `log_urls` |
| `log_events` | array of strings | `load_config()` | `[]` | Hook names to instrument (start at `hook_start_priority`, default -10000; complete at MAX-1) |
| `custom_events` | array of strings | `load_config()` | `[]` | Custom event names to log |
| `auto_disable_threshold` | int | `load_config()` | `0` (off) | Per-hook count threshold for auto-disable |
| `auto_protect_time_threshold` | float | `load_config()` | `0.0` (off) | Per-hook mean-time threshold (seconds) for auto-promote-to-significant |
| `significant_events` | array of strings | `load_config()` | `[]` | Events protected from auto-disable |
| `flush_every_line` | bool | `load_config()` | `false` | Debug: flush buffer after every line (survives OOM, slower) |
| `log_memory` | bool | `load_config()` | `false` | Debug: append peak_mb to every complete entry |
| `allowed_users` | array of strings | `load_config()` | `[]` | Deployment override: restrict admin UI to these usernames |
| `hook_start_priority` | int | `load_config()` | `-10000` | Action priority for the `hook_start` instrumentation hook |
| `discovered_events` | array of strings | lazy (admin/health-check only) | `[]` | Custom events discovered from spokes — merged via `Health_Check_Extensions`. Not in the per-request schema; non-autoloaded |
| `aggregator_servers` | array | lazy (`Server_Registry`) | `[]` | Spoke registry storage; per-server `{url, auth_username, auth_password, enabled}`. Not in the per-request schema — encrypted-credential blob read lazily |
| `aggregator_verify_ssl` | bool | file-only | `true` | Hub-side cURL `CURLOPT_SSL_VERIFYPEER` for the SSE pull. File-only — no WP-option overlay, no admin form |
| `aggregator_require_https` | bool | file-only | `true` | Hub-side scheme enforcement on registered spoke URLs. File-only |
| `remote_num_segments` | int | file-only | `2` | Hub-side default segment count for remote-pull partitions |
| `remote_segment_size` | int | file-only | `33554432` (32MB) | Hub-side default segment size for remote-pull partitions |
| `remote_max_lifespan` | int | file-only | `3600` | Minimum spoke-side retention the hub expects to be able to seek into |

### Substrate option keys (read but not owned here)

`Config::load_config()` also reads the substrate's `newspack_nodes_*` namespace for keys that affect application behaviour:

| Substrate option | Use here |
|------------------|----------|
| `base_directory` | Root for `logs/`, `offsets/`, `locks/`, `ipc/` |
| `num_partitions` | Topic / Partition fan-out, must match the topology fleet count |
| `num_segments`, `segment_size`, `max_lifespan` | Partition retention |
| `memcache_servers` | Stats_Store + SSE slot pool backend |
| `salt` | Filename-segment salt for memcache key disambiguation |

Per-key documentation lives in the substrate; this plugin treats them as read-only.

### Hot reload

Most config keys read through `Config::load_config()` which has a 5s in-process cache — a worker restart isn't needed for most changes. Exceptions:
- `num_partitions` — wired into Topic/Partition construction at worker bootstrap; changing it requires `wp nodes restart` of all topology workers.
- `memcache_servers` — the shared `Core::$memd` holds the one connection, built once at boot; restart required to pick up a server change.
- Salt rotation (`Stats_Store::flush_all()`) — workers cache `prefix` at construction; restart for immediate effect.

## REST + React

There is no per-endpoint controller hierarchy anymore. The dashboards and admin tooling reach the application through the substrate's **command protocol**: one `POST /wp-json/newspack-nodes/v1/command` endpoint that routes a TM_COMMAND envelope to a named service CI node (`performance`, `events`, `status`, `logger`, `settings`, `servers`, `aggregator`, `discovery`). Each verb's request/response shape is documented in [API.md](API.md) (still expressed under the legacy `newspack-nodes/v1/*` and `newspack-nodes-aggregator/v1/*` paths for the reader's mental model — those are the verbs each CI exposes, not standalone routes). The CIs mount on `newspack_nodes/request_graph_ready`.

The whole `includes/rest/` directory — including the orphaned `Performance_Controller_Base` helper class — has been deleted. The command-protocol CIs are the only REST surface now; nothing extends a per-endpoint controller base. `tests/integration/M2BootstrapTest.php` carries a regression guard so a future revert that reintroduces `includes/rest/` would fail.

### Real-time path: `/messages/stream` + slot pool

SSE is now a single substrate surface: the substrate's `SSE_Out_Node` doubles as the `GET /wp-json/newspack-nodes/v1/messages/stream` controller. A client subscribes to one or more partitions (`?subscribe=firehose.pN`), `SSE_Out_Node` runs the drain loop, and emits a 7-field message envelope per line plus an idle `heartbeat` event when no data flows. The per-stream SSE controllers and their `SSEControllerBase` parent were all deleted in the M6 consolidation (`includes/rest/` is gone entirely); the browser dashboards and the hub-side `Remote_Source_Node` cross-server pull all consume `/messages/stream` directly.

```
+-----------------+   GET /messages/stream?subscribe=firehose.pN   +-----------------+
|  Browser opens  | ---------------------------------------------> |  Sse_Slot_Pool  |
|  EventSource    |        (acquire slot; fail-CLOSED 429)         |  (memcache, app)|
|  (_sse SseIn node)                                              +-----------------+
+-----------------+                                                       |
        |                                                                 |
        | < emit `connected` envelope (carries slot id)                   |
        v                                                                 |
  SSE_Out_Node drain loop (substrate):                                    |
    flush each partition's new messages as 7-field envelopes              |
    emit idle `heartbeat` when no traffic                                 |
    flush before the framework sleeps (per-tick, not per-event)           |
        |                                                                 |
  Browser POSTs the keepalive heartbeat to refresh its slot --------------+
  If the tab dies, the slot expires and the next slot check drops it.
```

**Slot pool ownership**: the substrate owns the slot pool (`\Newspack_Nodes\SSE_Slot_Pool`); `SSE_Out_Node` calls it before headers and returns HTTP 429 when the pool is full or memcache is unreachable. The slot pool IS the rate limit; **memcache failure fails CLOSED** (429), the asymmetric flip side of the stats path's fail-soft behavior. Stats can degrade to "no data" gracefully because the dashboard is read-only; SSE streams ARE the live workload and dropping the rate limit would let one runaway client saturate the worker pool. Hub-side aggregator connections (`Remote_Source_Node` cURL pulls) get a longer slot TTL than browsers, since a cURL handle can stall briefly under load. The per-client heartbeat refresh is the invariant: only the client refreshes a slot's TTL; the server-side slot check is check-only and never refreshes on check, so each client's TTL must outlive its own heartbeat interval. Don't reintroduce server-side refresh-on-check.

### React trees

4 dashboards (`performance-dashboards`, `performance-gyroscope`, `performance-logger`, `performance-request-log`) ride the substrate's `_http` / `_sse` / `_heartbeat` spine: each dashboard mounts a graph that builds TM_COMMANDs (TO=`_http/<ci-name>`) and resolves replies via a pending-Map keyed on `message[ID]`; SSE-driven dashboards additionally mount `_sse` (subscribing to the relevant `<log>.pN` feeds) and a `_heartbeat` Node that keeps the slot alive against `_http/workers`. Shared hooks/utils/components are NOT copied into this plugin — there is no local `src/shared/` tree. They're imported via the `@newspack-nodes/shared/*` path alias (e.g. `import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth'`), which esbuild + jest resolve to the `newspack-nodes` sibling checkout's `src/shared`. The old synced-copy mechanism (`sync-shared.sh`) was retired in v0.12.0. The four per-dashboard line transforms (`transformCompletedLine`, `transformGyroscopeLine`, `transformErrorLine`, …) live per-tree and turn raw envelope VALUEs into the shape each dashboard renders.

### Canonical view contract (v0.8.0)

Every command-driven dashboard view follows the same contract — established on `servers:view` during the aggregator-admin migration and propagated across the rest of the dashboards in v0.8.0:

- **Pending-Map gate.** The view owns a `this.pending = new Map()` keyed by `message[ID]`. The hook stashes `{ resolve, reject }` under the id before filling the TM_COMMAND, and the view's `fill()` looks up the id on every reply, resolves/rejects the stashed Promise, and short-circuits the rest of the handler. This is what lets `_http`-pivoted replies land back on the right caller without polluting global view state.
- **TM_ERROR isolation.** A pending-matched TM_ERROR rejects the Promise **without** writing to a global `view.error` field. Per-call error surface is the caller's catch (a row-level snackbar, an in-form notice — never a table-wide banner).
- **`_errorMessage()` helper.** A small per-view helper that accepts either a string payload or a structured `{ message }` TM_ERROR payload and returns the human-readable string, so the pending Promise's `reject(new Error(...))` carries a usable message regardless of the error shape the CI emitted.
- **Id-wins partial spread.** `updateServer` and similar `(id, partial)` verbs spread the partial FIRST so the positional id wins: `{ ...partial, id }`. Prevents a caller accidentally overwriting the addressed id from inside the partial.
- **No dead REPL mounts.** The shared graph factory used to mount a REPL node behind every dashboard; the dashboards never used it, so it's gone. Mount what the dashboard actually needs — spine + `_http` + (optionally) `_sse` / `_heartbeat` / the dashboard's route/transform/view chain.

See the v0.7.0 / v0.8.0 entries in `CHANGELOG.md` for the dashboard cutover history.

## CLI: wp nodes reqgrep

Application-side firehose filter. The substrate ships `wp nodes ls` / `wp nodes cli` (worker introspection); this plugin adds `wp nodes reqgrep` for searching the live firehose by URL pattern, request ID, or arbitrary content match.

```bash
# Most recent N entries across all partitions.
wp nodes reqgrep --recent

# Pattern match (URL substring, request ID, or category).
wp nodes reqgrep /calendar
wp nodes reqgrep 5f8e3a1c2

# Follow mode: tail all partitions continuously, newest-last.
wp nodes reqgrep --follow

# Combine: follow + filter.
wp nodes reqgrep pyrobase --follow

# Raw JSONL instead of formatted output.
wp nodes reqgrep /calendar --raw

# Show incomplete requests (no matching `complete`, no `finish()` synthetic).
wp nodes reqgrep pattern --incomplete

# Pipe stdin instead of firehose files.
cat archived.log | wp nodes reqgrep pattern
```

Implementation lives in `includes/cli/class-reqgrep-command.php`. Reads the firehose's `.idx` companion to skip ahead to the matching segment+offset range; falls back to a sequential scan if the pattern doesn't match an indexed field. `--follow` keeps a `Tail` open per partition and multiplexes the streams newest-last so the output reads like a live console.

`--recent` (no pattern) is the fast path — reads the index in reverse and emits the last N entries without ever opening the data segments. Use it for "what's the firehose doing right now?" introspection.

## See also

- [AGENTS.md](AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- `CHANGELOG.md` — version-by-version history (M2–M6 controller→CI migration, dashboard cutover, substrate Tachikoma alignment).
- [Runtime architecture guide](../newspack-nodes/docs/architecture-guide.md) — substrate this plugin depends on.
