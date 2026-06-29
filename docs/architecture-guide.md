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
|  substrate Remote_Source spoke-1  --SSE pull-->  spoke 1 firehose        |
|  substrate Remote_Source spoke-2  --SSE pull-->  spoke 2 firehose        |
|  substrate Remote_Source spoke-N  --SSE pull-->  spoke N firehose        |
|     |  (operator-wired on the console, one per spoke/partition)          |
|     v                                                                    |
|  Remote_Job_Rewrite_Node (ELN node):                                     |
|     k:"job"  ->  k:"remote_job"   (separate handler map on the hub)      |
|     |                                                                    |
|     v                                                                    |
|  Topic(local firehose.log)  -- KEY-routed ->  Partition pN               |
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

Each worker group is one declarative `.tsl` file in `topologies/`. A TSL file is a line-oriented script the substrate's topology loader interprets per partition: `make_node <Type> <name> [ctor args…]` instantiates a Node, `connect_node <from> <to>` wires a sink, `cmd <node>:config <verb> [args…]` runs a config verb on the node, and `var <key> = <value>` declares frontmatter the supervisor reads via `Topology_Registry::frontmatter()`. Tokens like `<partition>`, `<config:logs_dir>`, `<config:num_partitions>` are interpolated at load time against the substrate Config; `<eln:auto_disable_threshold>`, `<eln:is_hub>`, etc., resolve against the application Config (the v0.4.0 namespace split). The `make_node` first argument is a **shell name** that the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the single-word substrate types (`Consumer`, `Partition`, `Tee`, `Topic`) resolve under the substrate's own prefix. Seven `.tsl` files ship: `combined`, `performance`, `request-builder`, `flame-builder`, `job-router`, `aggregator`, and `hub-control`. Job *dispatch* (`Job_Worker_Node` tailing `jobs.log`) is NOT a file shipped here — it comes from the substrate's stock `job-worker` topology (the local `job-worker.tsl` was deleted in v0.12.0 when `Job_Worker_Node` moved to the substrate).

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

Hub-side ingest. Per-spoke substrate `Remote_Source` nodes (operator-wired on the console canvas) pull each spoke's firehose via SSE; the ELN `Remote_Job_Rewrite` node flips aggregated `k:"job"` entries to `k:"remote_job"`; the multi-partition `Topic` KEY-routes by URL hash so each downstream firehose-consuming partition sees its own slice.

```tsl
make_node Remote_Job_Rewrite remote-job-rewrite
make_node Topic firehose:topic <config:logs_dir>/firehose.p{partition} <config:num_partitions> <config:segment_size> <config:num_segments> <config:max_lifespan>
connect_node remote-job-rewrite firehose:topic
```

Per-spoke `Remote_Source` nodes are NOT in the stock topology — the operator adds them on the canvas, one per spoke/partition:

```tsl
make_node Remote_Source spoke-<id> <vault-id> firehose <partition>
connect_node spoke-<id> remote-job-rewrite
```

Each substrate `Remote_Source_Node` is self-sufficient: it patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`. SSL/HTTPS policy comes from the substrate `vault_verify_ssl` / `vault_require_https` config, not a per-topology setter; spoke credentials are looked up from the substrate **Vault** by `<vault-id>`. The `k:"job"` → `k:"remote_job"` rewrite is the `Remote_Job_Rewrite_Node` graph node (see [Remote_Job_Rewrite_Node](#remote_job_rewrite_node)) — not a filter, not a TSL setter; non-`job` entries pass through. Hub-mode is derived from whether `aggregator` is in the substrate's Topologies multi-select — there's no `enable_aggregator` config key (it was retired). Operators stand a hub up by selecting the `aggregator` and `hub-control` topologies.

### `topologies/hub-control.tsl`

Single-instance hub control plane (`var num_partitions = 1` — runs ONCE regardless of the data `num_partitions`). The settings-sync + discovery push side: a `Consumer` tails the `settings.p0` log (the substrate's `Settings_Event_Writer` appends option-name-only events to it on watched WP-option changes), `Settings_Sync` reads each option's current value and emits one `set` command per registered option (re-pushing every registered option on its 300s tick), and `Discovery_Collector` fans `discovery.get` to every spoke on its own 300s tick, union-merging the replies into the hub options. Both feed a shared `Tee`; the pipeline is inert until the operator wires per-spoke `HTTP_Out remote:<id>` nodes from the console and connects `spokes:tee` to them.

```tsl
var num_partitions = 1;

make_node Consumer settings:consumer <config:logs_dir>/settings.p0 <config:offsets_dir>/settings.settings-sync.p0
make_node Settings_Sync settings-sync 300
make_node Tee spokes:tee
make_node Discovery_Collector discovery-collector 300

cmd settings-sync:config add_setting <option> <ci> <remote-option>   # one per registered option

connect_node settings:consumer settings-sync
connect_node settings-sync spokes:tee
connect_node discovery-collector spokes:tee
```

`Settings_Sync` and `Settings_Event_Writer` live in the substrate (`\Newspack_Nodes\Settings_Sync_Node`, `\Newspack_Nodes\Settings_Event_Writer`); `Discovery_Collector_Node` is this plugin's (see [Discovery_Collector_Node](#discovery_collector_node)).

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

Receives Flame_Builder_Node's tuning decisions as `TM_STRUCT` messages and applies them as ordinary local option writes. There is no `remote_manager` job and no `suppress_sync` guard — `persist()` is a plain `update_option`, and the change reaches spokes the same way any admin edit does: the substrate's `Settings_Event_Writer` records the option change to `settings.p0`, which the `hub-control` topology's `Settings_Sync` node fans out (see [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)).

```
Flame_Builder_Node.apply_auto_tune()  ──TM_STRUCT msg──→  Auto_Tuner_Node.fill()
       TO=auto-tuner                                  │
       KEY=disable_hooks /                            └─→ apply_*() ─→ persist()
           disable_custom_events /                          = update_option( $option,
           add_significant_events                                       $value,
       VALUE={ items: string[], context: {…} }                         Config::autoload_for( $option ) )
```

The KEY discriminates the option being tuned; Auto_Tuner_Node dispatches by KEY inside `fill()` to the matching `apply_*` method, each a read-modify-write on a WP option:

| KEY | Updates |
|-----|---------|
| `disable_hooks` | `newspack_event_logger_nodes_log_events` minus the items (significant events preserved) |
| `disable_custom_events` | `newspack_event_logger_nodes_custom_events` minus the items (significant events preserved) |
| `add_significant_events` | `newspack_event_logger_nodes_significant_events` ∪ items |

Replaces the legacy `AutoTuneHandlers` six-listener pattern (hub @ priority 5 + standalone @ priority 10 × three events). Both sides used to wire on WP actions; both sides ran in the same flame-builder worker process; the action plumbing was intra-process IPC dressed up as hooks. Expressing it as a Node with `fill()` dispatch is one straight path through the same logic.

**Distributed lock**: Flame_Builder_Node still holds a 5s `evlog:auto_disable_lock` memcache lock across the three `fill()` calls. Without it, two Flame_Builder_Node workers (different partitions, both flushing at the same instant) would emit overlapping decisions twice.

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

### Remote_Job_Rewrite_Node

Hub-side fan-in is now the self-sufficient substrate `\Newspack_Nodes\Remote_Source_Node` — one per spoke/partition, operator-wired on the topology console. Each substrate `Remote_Source_Node` patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, pulls `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume, looks up its spoke credentials from the substrate **Vault**, and publishes a status snapshot to `np:remote:<node-name>:p<partition>`. The old ELN `Stream_Merger_Node` and ELN-local `Remote_Source_Node` were deleted in the pull-side cutover, along with `Health_Check_Extensions` and the `newspack_nodes/aggregator_ingest_line` filter.

The one application-specific piece is `Remote_Job_Rewrite_Node` — a pass-through transform the `aggregator` topology wires between the substrate `Remote_Source` sources and the firehose `Topic`. It flips aggregated `k:"job"` entries to `k:"remote_job"` so they dispatch centrally on the hub via `newspack_nodes/remote_job_handlers` rather than locally; non-`job` entries and non-array VALUEs pass through untouched.

```php
// includes/class-remote-job-rewrite-node.php
class Remote_Job_Rewrite_Node extends Node {
    public function fill( array &$message ): void {
        $value = $message[ Message::VALUE ];
        if ( is_array( $value ) && 'job' === ( $value['k'] ?? null ) ) {
            $value['k']                = 'remote_job';
            $message[ Message::VALUE ] = $value;
            // The rewrite only grows the line by the `job` -> `remote_job` delta;
            // still guard against the PIPE_BUF cap a downstream Partition enforces.
            if ( Message::packed_size( $message ) > Partition_Node::MAX_LINE_SIZE ) {
                Core::print_less_often( 'Remote_Job_Rewrite: dropping entry > MAX_LINE_SIZE bytes after rewrite' );
                return;
            }
        }
        parent::fill( $message );
    }
}
```

Relocating the rewrite from the deleted Stream_Merger's `aggregator_ingest_line` filter to a graph node keeps the substrate `Remote_Source` / `SSE_In` application-agnostic: the rewrite is a node the hub topology wires in, not a filter the substrate has to call. An oversized post-rewrite line is dropped (rate-limited) rather than forwarded to corrupt the segment.

### Discovery_Collector_Node

Hub-side periodic discovery fan-out. A `Timer_Node` the `hub-control` topology mounts (`make_node Discovery_Collector discovery-collector 300`): `arguments()` arms `set_timer()` at the given interval (300s default). On each `fire()` it mints a `discovery.get` TM_COMMAND to every connected spoke's `Discovery_CI`; each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks` / `custom_events` (under `VALUE['payload']`) into the hub's local options — folded one reply at a time, so out-of-order / partial replies converge (cap `MAX_EVENTS = 10000`). It replaces the legacy poll-based discovery sweep that `Remote_Manager` / `Health_Check_Extensions` used to drive.

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

**Hub identification**: a site acts as a hub when the `aggregator` topology is in the substrate's Topologies multi-select (and, for settings/discovery fan-out, `hub-control` too). There is no operator hub toggle — `enable_aggregator` (like `enable_workers` before it) was retired; hub-mode is derived purely from topology membership. Push-side fan-out (the `hub-control` topology's `Settings_Sync` + `Discovery_Collector` nodes) is structurally gated the same way: without `hub-control` active and per-spoke `HTTP_Out` nodes wired, the settings/discovery pipeline is inert.

**`k:"job"` vs `k:"remote_job"`**:

Nodes only ever write `k:"job"` to their own firehose — there's no "spoke vs hub" distinction at write time. The distinction emerges at dispatch:

- Every node's Job_Worker_Node tails its own `jobs.log` and dispatches `k:"job"` entries against the `newspack_nodes/job_handlers` filter. This runs locally on every node, hub or spoke.
- The hub additionally runs the `aggregator` topology: per-spoke substrate `Remote_Source_Node`s pull each spoke's firehose, and ELN's `Remote_Job_Rewrite_Node` (wired between the sources and the firehose `Topic`) rewrites the ingested `k:"job"` lines to `k:"remote_job"` before they reach the hub's firehose. The hub's Job_Worker_Node then dispatches those entries against `newspack_nodes/remote_job_handlers`.
- The two filters are independent registrations — a job type registers under whichever side(s) should run it:
  - **`job_handlers` only** → runs locally on every node. The hub's view of spoke-aggregated copies (as `remote_job`) is ignored.
  - **`remote_job_handlers` only** → only the hub acts. Spokes write the entries but don't act on them; the hub does the centralized work after aggregation.
  - **Both** → handler runs locally on every node, AND runs on the hub for entries aggregated from spokes. Useful when a job needs local + centralized handling under the same name (the two handler implementations can differ — e.g., one filters by local attributes, the other dispatches differently).

The rewrite is NOT in a spoke's graph. A spoke runs neither the `aggregator` topology nor `Remote_Job_Rewrite_Node`, so its own `k:"job"` entries dispatch locally — exactly what spokes want.

## Hub-Side Settings Sync, Discovery, and Vault

The hub's outbound side is no longer a set of static helper classes. `Server_Registry`, `Remote_Manager`, `Health_Check_Extensions`, and the ELN `Settings_Sync` were all deleted in the node-graph cutover. Three things took their place:

- **Spoke credentials live in the substrate Vault.** The substrate `\Newspack_Nodes\Vault` (managed through the substrate `vault` CI) stores each spoke's URL + Basic Auth, encrypted at rest. A `Remote_Source` node references its spoke by `<vault-id>`; SSL/HTTPS enforcement comes from the substrate `vault_verify_ssl` / `vault_require_https` config. There is no `aggregator_servers` option and no `Server_Registry` CRUD anymore. The plugin reacts to credential changes via the `newspack_nodes/vault/changed` action.

- **Settings fan-out is a node graph (`hub-control` topology).** The substrate's `Settings_Event_Writer` records watched WP-option changes to the `settings.p0` log (option name only). The `hub-control` topology's `Consumer` tails it, the substrate `\Newspack_Nodes\Settings_Sync_Node` reads each option's current value at consume time and emits one `set` command per registered option (re-pushing the full registered set on its 300s tick), and a `Tee` fans the commands to per-spoke `HTTP_Out` nodes the operator wires on the console. ELN supplies the value-resolver via the `newspack_nodes/settings_sync/value` filter (`newspack_event_logger_nodes_resolve_settings_sync_value`), which maps a `newspack_event_logger_nodes_*` / `newspack_nodes_*` option name to its live value.

- **Discovery is `Discovery_Collector_Node`** (also in `hub-control`) — see [Discovery_Collector_Node](#discovery_collector_node). It fans `discovery.get` to every spoke on its 300s tick and union-merges the replies into the hub's options, replacing the old `Remote_Manager` discovery sweep + `Health_Check_Extensions` merge.

## Settings Sync: No Operator Gate

Settings fan-out is now the substrate `Settings_Sync_Node` graph in the `hub-control` topology (see [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)). It is **ungated** in the same structural sense as before: an option change always records a settings event (via the substrate `Settings_Event_Writer`), but nothing fans it out unless the `hub-control` topology is active and per-spoke `HTTP_Out` nodes are wired. On a spoke / standalone site there is no consumer, so the event is simply tailed and dropped.

`Auto_Tuner_Node::persist` is likewise ungated and gate-free: it's a plain `update_option(..., Config::autoload_for($option))` (no `remote_manager` job, no `suppress_sync`). The write records a settings event like any admin edit; if `hub-control` is running, `Settings_Sync_Node` picks the change up at consume time. There is no operator hub toggle — the legacy `enable_workers` polarity dance was retired in v0.5.0, and `enable_aggregator` was retired too; hub-mode is derived from whether the `aggregator` / `hub-control` topologies are active. Letting the no-consumer drop happen at the node-graph level is cheaper and harder to misconfigure than a per-listener `get_option` gate.

**Value resolved at consume time, not at write time.** `Settings_Event_Writer` records only the option *name*; `Settings_Sync_Node` reads the current value when it consumes the event (and on its 300s tick re-reads + re-pushes every registered option). So a burst of writes to one option collapses to a single current-value push, and there's no stale-value race. ELN supplies the name→value mapping through the `newspack_nodes/settings_sync/value` filter.

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
- Job needs to be aggregated from spokes to hub (only firehose entries flow through the hub's `Remote_Source` pull).

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

`load_config()` is the single zero-arg entry point — every key in the `$option_schema` whitelist is loaded on every call, including `auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`, and the substrate keys merged in from `RuntimeConfig::load_config()`. The result is cached in a static for the rest of the request, so the cost is one round of `get_option` per schema key on first call. `load_config_defaults()` is the file-only escape hatch (no WP-option overlay, no substrate merge) for callers that need the shipped defaults without the per-request layering.

### Settings_Schema: one Field per setting

`Settings_Schema` (`includes/class-settings-schema.php`) is the single declaration both `Config` and `Admin` derive from — one `\Newspack_Nodes\Config_System\Field` per setting, collected into a `Schema`. It replaced the three parallel arrays `Config` and `Admin` used to hand-maintain in lockstep (`Config::$option_schema`, `Admin::$option_names`, `Admin::$delete_on_blank_options`). `Config` reads it for the overlay key-list + autoload sweep; `Admin` reads it to drive the `register_setting` / `add_settings_field` loops, the reset list, and the delete-on-blank classification. Substrate keys (`base_directory`, partitioning, `memcache_servers`, `topologies`) are owned by the substrate's own Settings_Schema under `newspack_nodes_*` and reach this plugin via the `array_merge(RuntimeConfig::load_config(), …)` layering — never declared here. Spoke credentials are no longer an application option at all — they live in the substrate **Vault**. The schema layer builds on the shared `\Newspack_Nodes\Config_System` (`Field`, `Schema`, plus the substrate's renderer / overlay / reset-gate machinery).

### Application option keys

| Option | Type | Loaded by | Default | Use |
|--------|------|-----------|---------|-----|
| `enable_logging` | bool | `load_config()` | `true` | Master switch for the firehose write path |
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
| `remote_num_segments` | int | file-only | `2` | Hub-side default segment count for remote-pull partitions |
| `remote_segment_size` | int | file-only | `10 * 1024 * 1024` (10MB) | Hub-side default segment size for remote-pull partitions |
| `remote_max_lifespan` | int | file-only | `3600` | Minimum spoke-side retention the hub expects to be able to seek into |

Spoke credentials and SSL/HTTPS policy are no longer application config keys — they live in the substrate **Vault** (`vault_verify_ssl` / `vault_require_https` on the substrate side). The retired `enable_aggregator`, `aggregator_servers`, `aggregator_verify_ssl`, `aggregator_require_https`, and `discovered_events` keys are gone.

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

There is no per-endpoint controller hierarchy anymore. The dashboards and admin tooling reach the application through the substrate's **command protocol**: one `POST /wp-json/newspack-nodes/v1/command` endpoint that routes a TM_COMMAND envelope to a named service CI node. This plugin owns four CIs — `performance`, `events`, `logger`, `discovery`; the `status`, `settings`, and `aggregator` CIs (and the `vault` CI that replaced `servers`) are substrate-owned. Each verb's request/response shape is documented in [API.md](API.md) (still expressed under the legacy `newspack-nodes/v1/*` and `newspack-nodes-aggregator/v1/*` paths for the reader's mental model — those are the verbs each CI exposes, not standalone routes). The CIs mount on `newspack_nodes/request_graph_ready`.

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

Five dashboards (`overview`, `error-log`, `gyroscope`, `settings`, `requests`) plus the `current-request` overlay tab ride the substrate's `_http` / `_sse` / `_heartbeat` spine: each dashboard mounts a graph that builds TM_COMMANDs (TO=`_http/<ci-name>`) and resolves replies via a pending-Map keyed on `message[ID]`; SSE-driven dashboards additionally mount `_sse` (subscribing to the relevant `<log>.pN` feeds) and a `_heartbeat` Node that keeps the slot alive against `_http/workers`. Shared hooks/utils/components are NOT copied into this plugin — there is no local `src/shared/` tree. They're imported via the `@newspack-nodes/shared/*` path alias (e.g. `import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth'`), which esbuild + jest resolve to the `newspack-nodes` sibling checkout's `src/shared`. The old synced-copy mechanism (`sync-shared.sh`) was retired in v0.12.0. The four per-dashboard line transforms (`transformCompletedLine`, `transformGyroscopeLine`, `transformErrorLine`, …) live per-tree and turn raw envelope VALUEs into the shape each dashboard renders.

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
