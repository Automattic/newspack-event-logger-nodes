# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL), see `../newspack-nodes/ARCHITECTURE.md`.

This plugin replaces the legacy 10-plugin `newspack-event-logger-plugins` monorepo wholesale. There's no shadow mode or dual emission — the legacy plugins write to `/volumes/pyrobase/tmp/event-logger`, this one defaults to `/tmp/newspack-nodes`, so they coexist by isolating their storage.

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
|   Log_Manager  --produce()-->  Topic(firehose.log)                        |
|                                  |                                       |
|                                  v                                       |
|                         Partition.write()  =>  /logs/firehose.log/pN/    |
|                         (4KB PIPE_BUF atomic)                            |
|                                                                          |
|   Job_Intake::queue()  --produce()-->  Topic(jobintake.log)               |
|                                          |                               |
|                                          v                               |
|                                 Partition.write() with allow_large       |
|                                 (auto-locked, up to 10MB)                |
+--------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       READ PATH (Worker, ~595s lifespan)                 |
|                                                                          |
|  topology firehose-workers-and-jobs.pN:                                  |
|                                                                          |
|    Consumer(firehose.log)  ----+                                         |
|                                |                                         |
|                                v                                         |
|                              Tee  ----> Request_Builder_Node ----> requests.log|
|                                |                       +---> errors.log  |
|                                |                       +---> completed.log/   |
|                                |                       +---> gyroscope.log    |
|                                |                                         |
|                                +-------> Job_Router_Node     ----> jobs.log    |
|                                              ^                           |
|    Consumer(jobintake.log) -------+----------'                           |
|                                                                          |
|  topology request-workers.pN:                                            |
|                                                                          |
|    Consumer(requests.log) ----> Flame_Builder_Node ----> flames.log            |
|                                              +---> Stats_Store -> mc     |
|                                                                          |
|  topology job-workers.pN:                                                |
|                                                                          |
|    Consumer(jobs.log) ----> Job_Worker_Node ----> registered handlers          |
+--------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       AGGREGATOR HUB (one per site)                      |
|                                                                          |
|  Stream_Merger_Node (cURL multi)                                               |
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

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`. Query-string values for `api_key`, `token`, `secret`, `bearer`, etc. become `=REDACTED`. The list intentionally errs broad — anything that looks like a credential gets blanked. The pattern is compiled once at file load; redaction is a single `preg_replace` per URL.

### Refuse-root

`Log_Manager::__construct()` calls `\posix_geteuid()` early. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction silently no-ops and `enable_logging` flips to false for the rest of the request. The error log gets one rate-limited line so the operator notices.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. `Log_Manager::matches_url_filter()` short-circuits true for worker requests so spawn round-trips don't pollute the firehose, but `Request_Builder_Node` checks the same env var when stamping `is_worker` on the completed request so dashboards can filter worker traffic OUT of global stats. Without that exclusion, the supervisor's per-15s spawn cycle would dominate every leaderboard.

### PIPE_BUF and truncation

Per-line writes go to `Topic::fill()` → `Partition::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the JSON envelope around `m`. Anything larger gets replaced with `{"truncated":true}` and a `error_log` line directing the caller to `Job_Intake::queue()` (which goes through `Partition::allow_large_writes()` and the per-partition write lock). Truncation is silent at the firehose level — it has to be, because the firehose is fire-and-forget — so size discipline is the caller's responsibility.

### Per-request lifecycle

```
plugins_loaded         Log_Manager::instance() — constructs, registers shutdown hook
hook_start (priority 1)  ::start( hook_name )
hook callback runs
hook_start (priority MAX-1)  ::complete( hook_name )
…
shutdown               ::finish()   — closes orphaned start entries, emits (process complete)
```

Orphaned `start`s (callback threw, exit called, fatal error before `complete`) get a synthetic `complete` at `finish()` time with `error_status='I'` so Request_Builder_Node can render the request as incomplete in the dashboards instead of waiting for a `complete` that will never arrive.

## Topologies

Each worker group is one declarative `.tsl` file in `topologies/`. A TSL file is a line-oriented script the substrate's topology loader interprets per partition: `make_node <Type> <name> [ctor args…]` instantiates a Node, `connect_node <from> <to>` wires a sink, `cmd <node>:config <verb> [args…]` runs a config verb on the node, and `var <key> = <value>` declares frontmatter the supervisor reads via `Topology_Registry::frontmatter()`. Tokens like `<partition>`, `<config:logs_dir>`, `<config:num_partitions>` are interpolated at load time against the substrate Config; `<eln:auto_disable_threshold>`, `<eln:is_hub>`, etc., resolve against the application Config (the v0.4.0 namespace split). The `make_node` first argument is a **shell name** that the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the single-word substrate types (`Consumer`, `Partition`, `Tee`, `Topic`) resolve under the substrate's own prefix. Six topologies ship.

### `topologies/firehose-workers-and-jobs.tsl` (default)

Per-partition fanout worker, both branches. Tails `firehose.log` + `jobintake.log`; fans out to `Request_Builder` + `Job_Router`; writes `requests.log`, `errors.log`, `completed.log`, `gyroscope.log`, `jobs.log`.

```tsl
make_node Partition requests:partition <config:logs_dir>/requests.log <partition> ...
cmd requests:partition:config allow_large_writes
cmd requests:partition:config with_index request-index
make_node Partition errors:partition <config:logs_dir>/errors.log <partition> ...
cmd errors:partition:config allow_large_writes

# Compact-summary fan-out: drives request-log + gyroscope dashboards.
make_node Tee       completed:tee
make_node Partition completed:partition <config:logs_dir>/completed.log <partition> 1048576 ...
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.log <partition> 1048576 ...

make_node Request_Builder request-builder
connect_node request-builder requests:partition
cmd request-builder:config set_errors_target     errors:partition
cmd request-builder:config set_completed_target  completed:tee
cmd request-builder:config set_inflight_target   gyroscope:partition
cmd request-builder:config set_inflight_interval 1000
connect_node completed:tee completed:partition
connect_node completed:tee gyroscope:partition

make_node Partition jobs:partition <config:logs_dir>/jobs.log <partition> ...
cmd jobs:partition:config allow_large_writes
make_node Job_Router job-router
connect_node job-router jobs:partition

make_node Consumer firehose:consumer <config:logs_dir>/firehose.log <partition> ...
make_node Tee firehose:tee
connect_node firehose:tee request-builder
connect_node firehose:tee job-router
connect_node firehose:consumer firehose:tee

make_node Consumer jobintake:consumer <config:logs_dir>/jobintake.log <partition> ...
connect_node jobintake:consumer job-router
```

The Tee is only on the firehose side because that source has multiple targets. The jobintake side has one target (`Job_Router`) and connects directly. **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** Single-target inputs connect directly to the consumer node. Number of Tees = number of source-fan-outs, not number of sources.

`cmd <partition>:config allow_large_writes` on the output Partitions lifts the per-message cap to 10MB and acquires a per-Partition lock (PIPE_BUF 4KB is otherwise the atomic-append ceiling). `Request_Builder` JSON regularly exceeds 4KB on pages with many timed hooks. The `completed:tee` fan-out carries the per-request compact summary `Request_Builder` emits at request-complete time; `gyroscope:partition` additionally receives the periodic in-flight snapshots from the hidden `Request_Flight_Node` sibling (interval set via `set_inflight_interval`).

### `topologies/firehose-workers-only.tsl` and `firehose-jobs-only.tsl`

Two single-branch variants of the default. `firehose-workers-only` keeps just the `Request_Builder` branch (`requests.log` / `errors.log` / `completed.log` / `gyroscope.log`) — the firehose Consumer connects straight to `request-builder` with no Tee, since it's the only target. `firehose-jobs-only` keeps just the `Job_Router` branch (`jobs.log`), with both the firehose and jobintake Consumers feeding `job-router`. The default config ships `firehose-workers-only` + `request-workers` active and lists the other two as commented alternatives.

### `topologies/request-workers.tsl`

Per-partition flame builder. Tails `requests.log`; `Flame_Builder` emits `flames.log` and bumps the 9-namespace memcache schema via `Stats_Store`.

```tsl
make_node Partition flames:partition <config:logs_dir>/flames.log <partition> ...
cmd flames:partition:config allow_large_writes
cmd flames:partition:config with_index flame-index

make_node Flame_Builder flame-builder
connect_node flame-builder flames:partition
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flame-builder:config set_auto_tune <eln:auto_disable_threshold> <eln:auto_protect_time_threshold>
cmd flame-builder:config set_significant_events <eln:significant_events_csv>

make_node Consumer requests:consumer <config:logs_dir>/requests.log <partition> ...
connect_node requests:consumer flame-builder
```

`configure_stats <partition>` constructs the per-partition `Stats_Store`; the auto-tune verbs feed the noisy/significant-event detection (see [Flame_Builder_Node](#flame_builder_node) and [Auto_Tuner_Node](#auto_tuner_node)). The `<config:…>` tokens resolve against the substrate Config (`logs_dir`, `num_partitions`, `segment_size`, etc.); the `<eln:…>` tokens resolve against the application Config (`auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events_csv`, `is_hub`, `aggregator_*`) — the v0.4.0 split that gave app-owned values their own token namespace so substrate-only resolvers can't accidentally swallow them.

### `topologies/job-workers.tsl`

Per-partition job dispatcher. Tails `jobs.log`; `Job_Worker` dispatches to registered `newspack_nodes/job_handlers` (and `newspack_nodes/remote_job_handlers` on the hub).

```tsl
var stale_timeout = 600;

make_node Job_Worker job-worker
make_node Consumer jobs:consumer <config:logs_dir>/jobs.log <partition> ...
connect_node jobs:consumer job-worker
```

`Job_Worker` loads its handler maps from the filters at construction so they're populated when the first `jobs.log` line arrives. The `var stale_timeout = 600` frontmatter lifts the lock-stale timeout (job handlers run user code that can be slow) so in-flight jobs aren't killed mid-execution.

### `topologies/aggregator.tsl`

Hub-side ingest. One `Stream_Merger` pulls from configured spokes via SSE; sinks into a multi-partition Topic that KEY-routes by URL hash so each downstream firehose-workers partition sees its own slice.

```tsl
make_node Topic firehose:topic <config:logs_dir>/firehose.log <config:num_partitions> ...

make_node Stream_Merger stream-merger firehose <partition>
cmd stream-merger:config set_verify_ssl <eln:aggregator_verify_ssl>
cmd stream-merger:config set_require_https <eln:aggregator_require_https>
connect_node stream-merger firehose:topic
```

`Stream_Merger` is a fan-in (partition count comes from the destination Topic, not the merger), and it owns a hidden `Health_Check_Tick` sibling (`stream-merger:health-check`) that hitchhikes the same Router TIMER heartbeat to run the periodic discovery + sync sweep. Registry remotes load automatically when `connect_node` wires the target (lifecycle hook on `Stream_Merger::connect_node`) — there's no separate `load_remotes_from_registry` verb. SSL/HTTPS setters run BEFORE `connect_node` so the `Remote_Source` children created during the load inherit current policy. The `k:"job"` → `k:"remote_job"` rewrite filter is registered statically at plugin load (`Stream_Merger_Node::register_remote_job_rewrite_filter`), not from TSL. Aggregator is **gated** by the `enable_aggregator` config key (strict `=== true`, default OFF): if not explicitly enabled, the topology isn't activated, the supervisor never spawns the worker, and the admin "Aggregator" submenu is hidden. Hubs opt in by setting `'enable_aggregator' => true` in their `newspack-event-logger-nodes-config.php` overlay or via the admin checkbox.

### Topology resolution

As of v0.5.0, the `topologies` key lives on the substrate — `newspack-event-logger-nodes-config.php` no longer owns it. The plugin only **publishes its catalog**: at boot, it calls `register_stock_dir( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' )` on `Newspack_Nodes\Topology_Registry`, so anyone calling `Topology_Registry::resolve()` (admin, REST, tests, CLI, supervisor) finds the stock `.tsl` files. Which catalog entries actually spawn workers is decided downstream by the substrate's Topologies multi-select option (`newspack_nodes_topologies`) — this plugin only publishes the "what topologies exist" set; the substrate filters it by what the operator has checked. `num_partitions` defaults also come from the substrate config, so one setting drives both `Log_Manager` (write side) and the worker fleet (read side); hardcoding diverges them.

Cost on regular WP requests is one array append at boot — the `.tsl` files themselves aren't parsed yet. Actual resolution + parsing happens in three places, none on the page-render hot path: supervisor's `check_config()` tick (every 15s), worker bootstrap (once per spawn), REST workers/dashboard reads.

## Application Nodes

### Request_Builder_Node

Assembles requests from firehose entries via the `LRU_Cache` 3-bucket timed-rotation cache. Bucket rotation every 200s; full retention window 3 × 200s = 600s (10 min). Orphans evicted from the oldest bucket emit as timed-out (`error_status: 'T'`).

```php
class Request_Builder_Node extends Node {
    private const BUCKET_ROTATION_S   = 200;   // 3 × 200s = 600s retention
    private const DEFAULT_BUCKET_SIZE = 100;
    private const DEFAULT_NUM_BUCKETS = 3;

    public function fill( array &$message ): void {
        // Parse firehose JSONL line; assemble start/complete pairs into request.
        // On rotation: evict timed-out bucket (sets error_status='T' on each orphan).
        // On overflow: evict oldest entry from oldest bucket.
        // On request complete: emit JSON-encoded record via $this->sink.
    }
}
```

Eviction emits via `$this->sink->fill( $synthetic_message )`, NOT direct file writes. This is load-bearing for composability: timed-out requests must flow through Tee so errors.log gets a copy, hooks can observe, tests can capture. Don't write to files from inside an eviction callback.

### Request_Flight_Node

Backs the Gyroscope dashboard's live in-flight view. It's a hidden `Timer_Node` sibling that `Request_Builder_Node` owns (modeled on Perl Tachikoma's `InstrumentalityFlight.pm`): on each timer tick it snapshots the patron's in-progress request map and emits a compact-summary batch downstream to the gyroscope partition, so the page can render a live treemap of what's running right now plus a fading trail of recent completions.

```php
class Request_Flight_Node extends Timer_Node {
    private const DEFAULT_INTERVAL_MS = 1000;

    public function set_interval( int $ms ): void;   // re-arms the timer
    public function interval(): int;
    public function fire_cb(): void;                 // inflight_snapshot() -> emit batch
}
```

The node is hidden from the topology console by the substrate's patron filter in `dump_metadata`. Its configuration surfaces on the patron's `:config` CI as `set_inflight_target` / `set_inflight_interval` — the `firehose-workers-*` topologies wire those with `cmd request-builder:config set_inflight_target gyroscope:partition` + `set_inflight_interval 1000`. Because the snapshot rides `Request_Builder_Node`'s own in-flight map (the same `LRU_Cache` buckets it already maintains for request assembly), there's no second tracker to keep coherent — the previous standalone `InflightTracker` class (deleted in commit `99a8a1b`, along with the four legacy per-stream SSE controllers) was a separate in-memory copy of state the builder already held.

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

Replaces the legacy `AutoTuneHandlers` six-listener pattern (hub @ priority 5 + standalone @ priority 10 × three events). Both sides used to wire on WP actions; both sides ran in the same request-workers process; the action plumbing was intra-process IPC dressed up as hooks. Expressing it as a Node with `fill()` dispatch is one straight path through the same logic.

**Local-write suppression**: Auto_Tuner_Node wraps every `update_option` in `Settings_Sync::suppress_sync(true)` / `suppress_sync(false)` so the local write doesn't re-trigger Settings_Sync's `update_option` listener (which would re-queue the same remote sync that Auto_Tuner_Node just queued). The hub fan-out happens *before* the local write so a failed fan-out (memcache down, Job_Intake stale) doesn't block the local update.

**Distributed lock**: Flame_Builder_Node still holds a 5s `evlog:auto_disable_lock` memcache lock across the three `fill()` calls. Without it, two Flame_Builder_Node workers (different partitions, both flushing at the same instant) would fan out twice for overlapping decisions.

### Job_Router_Node

Multi-input routing. Reads firehose AND jobintake; disambiguates source via Message FROM — Consumer stamps FROM with its own node name (`firehose:consumer`, `jobintake:consumer`), and Job_Router_Node inspects the string to know which input the line came from. The body schema differs slightly between the two sources (firehose wraps under `m`; jobintake is flat), so Job_Router_Node normalizes both into a `{type, handler, parameters, ts}` shape before forwarding to `jobs.log` for Job_Worker_Node to dispatch.

| FROM contains | Body key | Allowed kinds |
|---------------|----------|---------------|
| `firehose:consumer` | `entry['m']` (nested) | `job` or `remote_job` |
| `jobintake:consumer` | `entry` (flat) | `job` only — `remote_job` rewritten to `job` |

Validation:

- Handler name pattern `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- `MAX_JOB_SIZE = 10485760` (10MB)

Unknown handlers, oversized lines, and invalid handler names log via `Core::print_less_often` (rate-limited).

Job_Worker_Node (downstream) reads `jobs.log` and looks up the handler in `newspack_nodes/job_handlers` (kind=job) or `newspack_nodes/remote_job_handlers` (kind=remote_job) via `load_handlers_from_filters()` at topology bootstrap. Handlers can also be registered programmatically with `Job_Worker_Node::set_local_handler()` / `set_remote_handler()`.

### Job_Worker_Node

Executes registered job handlers. Per-job try/catch isolates failures. Calls `gc_collect_cycles()` after every job; flushes the WP object cache every `CACHE_FLUSH_INTERVAL` (default 50) jobs; latches a `memory_pressure` flag at 80% of `memory_limit` so the topology drain predicate exits cleanly and the supervisor respawns into a fresh process.

```php
class Job_Worker_Node extends Node {
    public const MAX_JOB_SIZE             = 10485760;
    public const CACHE_FLUSH_INTERVAL     = 50;
    public const MEMORY_WATERMARK_PCT     = 0.80;

    public function fill( array &$message ): void {
        $entry   = $message[ Message::VALUE ];
        $kind    = $entry['type'] ?? '';                          // 'job' or 'remote_job'
        $handler = $entry['handler'] ?? '';
        $handlers = ( 'remote_job' === $kind ) ? $this->remote_handlers : $this->local_handlers;
        try {
            ( $handlers[ $handler ] )( $entry['parameters'] ?? [] );
        } catch ( \Throwable $e ) { Core::print_less_often( /* ... */ ); }
        ++$this->jobs_executed;
        \gc_collect_cycles();
        if ( ++$this->jobs_since_cache_flush >= self::CACHE_FLUSH_INTERVAL ) {
            \wp_cache_flush();
            $this->jobs_since_cache_flush = 0;
        }
    }
}
```

Image-handler circular refs (`wp_generate_attachment_metadata` loading full-resolution images into GD) are the documented reason for the discipline. Preserve in any successor.

### Stream_Merger_Node

Hub-side fan-in over remote spokes. `Stream_Merger_Node` is now a coordinator: it instantiates one `Remote_Source_Node` child per `Server_Registry::get_enabled()` entry (each owns its own cURL easy handle on a shared multi-handle registered with `Event_Framework`, plus its own SSE parser and cursor), holds the single shared offsetlog for the whole hub, and periodically walks its children to commit positions. The per-remote SSE pull, reconnect/backoff, and envelope parsing live in `Remote_Source_Node` (M6.7 migrated its feed to the substrate's `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume). The `process_sse_chunk` sketch below describes the SSE-line parsing each source performs.

```php
class Stream_Merger_Node extends Node {
    public const MAX_BACKOFF     = 30;
    public const INITIAL_BACKOFF = 1;

    public function process_sse_chunk( string $chunk ): void {
        $this->buffer .= $chunk;
        while ( ( $pos = strpos( $this->buffer, "\n\n" ) ) !== false ) {
            $event        = substr( $this->buffer, 0, $pos );
            $this->buffer = substr( $this->buffer, $pos + 2 );
            $this->process_event( $event );
        }
    }

    private function process_event( string $event ): void {
        // Extract data: lines, apply newspack_nodes/aggregator_ingest_line filter
        // (the k:"job" -> k:"remote_job" rewrite for hub topologies),
        // emit TM_BYTESTREAM with VALUE = filtered payload.
        $payload = apply_filters( 'newspack_nodes/aggregator_ingest_line', $payload );
        // ... build message, fill into sink (the Topic).
    }
}
```

Position advances on successful local-Topic write OR on intentional drop by an ingest filter (matches existing `class-stream-merger.php` behavior); failed writes leave the remote position unchanged so the next iteration re-fetches.

Stream_Merger_Node does NOT perform the `k:"job"` -> `k:"remote_job"` rewrite itself. The rewrite is a `newspack_nodes/aggregator_ingest_line` filter (renamed from `newspack_event_aggregator_ingest_line`) registered by the application plugin's hub-side bootstrap; Stream_Merger_Node applies the filter chain. Plugins that don't load the rewrite filter (because they're spokes, not hubs) get raw `k:"job"` entries through.

### Stats production (owned by Flame_Builder_Node)

There is no longer a separate stats Node. The standalone `StatsAggregator` Node — the variant for topologies that wanted memcache stats without flame data — was removed (commit `8a7e815`); `Flame_Builder_Node` is the single stats producer, owning flame generation AND the 9-namespace memcache fan-out via its injected `Stats_Store`. The `request-workers` topology wires the store with `cmd flame-builder:config configure_stats <partition>`.

Each completed request bumps the schema across the same dimension set the reader paths whitelist:

```php
// Inside Flame_Builder_Node, per completed-request flush:
private const DIM_FIELDS = [
    'status' => 'status_category', 'method' => 'request_method',
    'server' => 'server_name',     'country' => 'country_code',
    'from'   => 'http_from',       'ua'     => 'user_agent',
    'ja4'    => 'ja4_hash',
];

$this->store?->bump_url(         $url, $req_time );
$this->store?->bump_leaderboard( $req_time, $entry['categories'] ?? [] );
$this->store?->bump_hourly(      $req_time, $entry['peak_mb'] ?? 0 );
// server, dimensions, url_hash...
```

When no `Stats_Store` is configured (`configure_stats` never called — e.g. in unit tests), the `?->` null-safe calls no-op, so the builder still emits flame data without touching memcache.

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
              |  Stream_Merger_Node pulls from    |
              |  configured spokes          |
              |                             |
              |  Remote_Manager handles      |
              |  remote_job dispatch        |
              |                             |
              |  Settings_Sync fans out      |
              |  options to spokes          |
              +-----------------------------+
```

**Hub identification**: a site is acting as a hub when `enable_aggregator` is strictly `=== true` AND it has at least one spoke registered. The toggle is a single operator switch — strict polarity, default OFF (fresh installs are not hubs). It gates the Aggregator admin submenu visibility. Push-side fan-out listeners (`Settings_Sync`, `Auto_Tuner_Node`) are themselves ungated — they always queue a `remote_manager` job; without an aggregator topology running there's no consumer, and the queued job silently no-ops. Pull-side activation (whether the Stream_Merger_Node worker actually spawns) is decoupled — driven by whether `aggregator` is in the substrate's Topologies multi-select.

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

Validation at write time: ID matches `/^[a-zA-Z0-9_-]{1,64}$/`, URL is a valid HTTPS URL on the `ALLOWED_ENDPOINT_PREFIXES` whitelist (no smuggling arbitrary endpoints through the form), `auth_username` is a WordPress username (sanitized), `auth_password` is an Application Password (32 base64 chars + spaces — stored in plaintext, no encryption layer; the threat model assumes admin-only access). Cap of 100 servers per registry to bound the periodic health-check sweep.

### `Remote_Manager`

Outbound HTTP to spokes plus a registered `remote_manager` job handler. Two distinct roles:

1. **Discovery sweep** (periodic): walks every enabled server, calls `/wp-json/newspack-nodes/v1/discovery` on each one, collects per-server payloads, then calls `Health_Check_Extensions::process_discovery()` directly (and also fires `newspack_event_logger_nodes/health_check_discovery` for external listeners), then calls `sync_all_settings()` to push every operator-configured option to every enabled spoke.

2. **`remote_manager` job handler**: registered via `newspack_nodes/job_handlers`. The handler dispatches by `action`:
   - `sync_setting` → POST `{option, value}` to every enabled spoke under the configured endpoint. The settings endpoint must be in `ALLOWED_ENDPOINT_PREFIXES` (`newspack-nodes` or `newspack-nodes-aggregator` namespaces only).
   - `health_check` → idempotent shortcut for dispatchers that prefer queueing over `add_action`.
   - Anything else → looked up in `newspack_event_logger_nodes/remote_actions` filter for plugin extension. Unknown actions log via `print_less_often` and drop.

Stale-job protection: every `remote_manager` job carries `queued_at`. Jobs older than `STALE_THRESHOLD` (~300s, just over the discovery interval) drop with a rate-limited error log. Prevents a stuck cron tick from blasting through dozens of obsolete sync requests in a row.

Caps: `MAX_SERVERS = 100`, `MAX_SETTINGS = 50` per sync. Prevents a filter-abuse from triggering an unbounded fan-out.

### `Health_Check_Extensions`

Merges discovered hooks and custom events from spokes into the hub's local settings. Called directly by `Remote_Manager::health_check()` (no WP action indirection — the in-plugin coupling is one method call). The action is *also* fired alongside, so external plugins (pyrobase, etc.) can still subscribe.

`process_discovery( array $all_discovery )` does two merges:

- Discovered **registered hooks** from each spoke → `newspack_event_logger_nodes_log_events`. New names get added; existing names stay; the local "checked / unchecked" state is preserved (discovered hooks land unchecked by default).
- Discovered **custom events** from each spoke → `newspack_event_logger_nodes_discovered_events`. New events get a `true` flag; existing entries are left alone.

Both merges suppress `Settings_Sync` first (`Settings_Sync::suppress_sync()` / `finally`) so the local `update_option` write doesn't get fanned BACK out to the spokes that just contributed it. Without the suppression, every discovery sweep would echo every spoke's hooks to every other spoke. Cap on accumulated entries: `MAX_EVENTS = 10000`.

## Settings_Sync: No Operator Gate

`Settings_Sync::maybe_queue_static_sync` is **ungated** — it always queues a `remote_manager` job into JobIntake when a synced option changes. The gate is structural: without an aggregator topology running and remotes registered, the queued job has no consumer and silently no-ops (the `Job_Worker_Node` finds no `remote_manager` handler in `newspack_nodes/job_handlers` and drops the line). Any topology that DOES dispatch `remote_manager` jobs picks them up automatically.

`Auto_Tuner_Node::persist` is also ungated — it always queues the `remote_manager` sync_setting job before the local `update_option` write, for the same no-consumer-equals-no-op reason. This was a deliberate simplification: the legacy `enable_workers`-as-hub-designation polarity dance was retired in v0.5.0. The remaining operator-facing toggle is `enable_aggregator` (strict `=== true`, default OFF), which gates the Aggregator admin submenu visibility and the Stream_Merger_Node pull-side activation; push-side fan-out listeners (Settings_Sync, Auto_Tuner) just queue, and let dispatch silently drop on non-hubs. Letting the no-consumer drop happen at dispatch time is cheaper and harder to misconfigure than a per-listener `get_option` gate.

**Re-entrancy guard via `$syncing`**: Health_Check_Extensions calls `update_option` in response to a sync; without the guard, that triggers another sync, ad infinitum. The `suppress_sync(true)` API is called before the update, restored after.

**Job_Intake for >4KB payloads**: options like `log_events` (50+ hook names) routinely exceed 4KB. Settings_Sync uses `Job_Intake::queue('remote_manager', ...)` with the option name as the partition key (so one sync per option at a time across the queue).

**Endpoint allowlist**: `ALLOWED_ENDPOINT_PREFIXES = ['/wp-json/newspack-nodes/', '/wp-json/newspack-nodes-aggregator/']` + handler-name parameter sanitization. Security boundary; port verbatim with renamed prefixes.

## Job_Intake vs Firehose Routing

Two write paths into the job queue:

```
+--------------------------+         +---------------------------+
|  Log_Manager              |         |  Job_Intake::queue()       |
|  per-request, <4KB jobs  |         |  large jobs >4KB          |
|  k:"job" in firehose     |         |  written to jobintake.log |
+--------+-----------------+         +-------------+-------------+
         |                                         |
         v                                         v
  /logs/firehose.log/pN/             /logs/jobintake.log/pN/
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

Using the wrong path silently loses jobs. Log_Manager truncates anything >4KB to `['truncated' => true]`. Job_Intake never aggregates so spoke jobs there never reach the hub.

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
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // core keys only
$config = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );  // includes performance + tuning keys
```

The `'full'` mode loads every key including `auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`, `aggregator_servers` (admin-side tuning that workers don't all need). The `request-workers` topology calls `load_config('full')` because Flame_Builder_Node needs the auto-tune thresholds; other call sites use the cheaper default load.

### Application option keys

| Option | Type | Mode | Default | Use |
|--------|------|------|---------|-----|
| `enable_logging` | bool | core | `true` | Master switch for the firehose write path |
| `enable_aggregator` | bool | core | `false` | Gates the Aggregator admin submenu (operator-visible side of hub-mode); push-side fanout listeners are themselves ungated and rely on no-consumer-as-no-op |
| `log_urls` | array of strings | core | `[]` | URL substring allowlist (empty = log everything) |
| `skip_urls` | array of strings | core | substrate-command paths | URL substring denylist; wins over `log_urls` |
| `log_events` | array of strings | core | `[]` | Hook names to instrument (start/complete pairs at priority 1 / MAX-1) |
| `custom_events` | array of strings | core | `[]` | Custom event names to log |
| `discovered_events` | array of strings | extended | `[]` | Custom events discovered from spokes — merged in via `Health_Check_Extensions` |
| `aggregator_servers` | array | extended | `[]` | Spoke registry (`Server_Registry` storage); per-server `{url, auth_username, auth_password, enabled}` |
| `auto_disable_threshold` | int | extended | `0` (off) | Per-hook count threshold for auto-disable |
| `auto_protect_time_threshold` | float | extended | `0.0` (off) | Per-hook mean-time threshold (seconds) for auto-promote-to-significant |
| `significant_events` | array of strings | extended | `[]` | Events protected from auto-disable |
| `flush_every_line` | bool | extended | `false` | Debug: flush buffer after every line (survives OOM, slower) |
| `log_memory` | bool | extended | `false` | Debug: append peak_mb to every complete entry |

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

`Performance_Controller_Base` survives as the shared helper the service CIs lean on: capability check (`manage_options`), partition validation, fixed-window rate limit (600req/60s default; fail-open if memcache down), `not_found_error()` shape, and `load_config()` via the `newspack_nodes/config` filter.

### Real-time path: `/messages/stream` + slot pool

SSE is now a single substrate surface: the substrate's `SSE_Out` node doubles as the `GET /wp-json/newspack-nodes/v1/messages/stream` controller. A client subscribes to one or more partitions (`?subscribe=firehose.pN`), `SSE_Out` runs the drain loop, and emits a 7-field message envelope per line plus an idle `heartbeat` event when no data flows. The five per-stream controllers (`FirehoseStreamController`, `RequestsStreamController`, `GyroscopeStreamController`, `ErrorsStreamController`, `RawlogsController`) and their `SSEControllerBase` parent were all deleted in the M6 consolidation; the browser dashboards and the hub-side `Remote_Source_Node` cross-server pull all consume `/messages/stream` directly.

```
+-----------------+   GET /messages/stream?subscribe=firehose.pN   +-----------------+
|  Browser opens  | ---------------------------------------------> |  Sse_Slot_Pool  |
|  EventSource    |        (acquire slot; fail-CLOSED 429)         |  (memcache, app)|
|  (useMessageStream)                                              +-----------------+
+-----------------+                                                       |
        |                                                                 |
        | < emit `connected` envelope (carries slot id)                   |
        v                                                                 |
  SSE_Out drain loop (substrate):                                         |
    flush each partition's new messages as 7-field envelopes             |
    emit idle `heartbeat` when no traffic                                 |
    flush before the framework sleeps (per-tick, not per-event)          |
        |                                                                 |
  Browser POSTs the keepalive heartbeat to refresh its slot ------------+
  If the tab dies, the slot expires and the next slot check drops it.
```

**Slot pool ownership**: the application supplies the slot pool (`Sse_Slot_Pool`, added in M6) via the substrate's slot-check seam — `SSE_Out` calls it before headers and returns HTTP 429 when the pool is full or memcache is unreachable. The slot pool IS the rate limit; **memcache failure fails CLOSED** (429), the asymmetric flip side of the stats path's fail-soft behavior. Stats can degrade to "no data" gracefully because the dashboard is read-only; SSE streams ARE the live workload and dropping the rate limit would let one runaway client saturate the worker pool. Hub-side aggregator connections (`Remote_Source_Node` cURL pulls) get a longer slot TTL than browsers, since a cURL handle can stall briefly under load. The per-client heartbeat refresh is the invariant: only the client refreshes a slot's TTL; the server-side slot check is check-only and never refreshes on check, so each client's TTL must outlive its own heartbeat interval. Don't reintroduce server-side refresh-on-check.

### React trees

6 dashboards (`aggregator-admin`, `event-aggregator`, `performance-dashboards`, `performance-gyroscope`, `performance-logger`, `performance-request-log`) plus a `shared` helpers tree ride the substrate's `_http` / `_sse` / `_heartbeat` spine: each dashboard mounts a graph that builds TM_COMMANDs (TO=`_http/<ci-name>`) and resolves replies via a pending-Map keyed on `message[ID]`; SSE-driven dashboards additionally mount `_sse` (subscribing to the relevant `<log>.pN` feeds) and a `_heartbeat` Node that keeps the slot alive against `_http/workers`. Source-of-truth shared hooks/utils live in `src/shared/` — but the *canonical* copies live in `newspack-nodes`; this plugin's `src/shared/*` are synced copies (the nodes build re-runs `sync-shared`). Edit the canonical in `newspack-nodes`, run `sync-shared.sh`. The four per-dashboard line transforms (`transformCompletedLine`, `transformGyroscopeLine`, `transformErrorLine`, …) live per-tree and turn raw envelope VALUEs into the shape each dashboard renders.

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
- [Runtime ARCHITECTURE.md](../newspack-nodes/ARCHITECTURE.md) — substrate this plugin depends on.
