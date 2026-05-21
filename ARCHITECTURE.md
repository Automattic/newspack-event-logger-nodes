# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL), see `../newspack-nodes/ARCHITECTURE.md`.

For migration context (what this plugin replaces), see [MIGRATION.md](MIGRATION.md).

## Table of Contents

- [Overview](#overview)
- [Write Path: LogManager](#write-path-logmanager)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Memcache Schema (9 Namespaces)](#memcache-schema-9-namespaces)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [Hub-Side Helpers: ServerRegistry / RemoteManager / Discovery](#hub-side-helpers-serverregistry--remotemanager--discovery)
- [SettingsSync: Fail-Closed Polarity](#settingssync-fail-closed-polarity)
- [JobIntake vs Firehose Routing](#jobintake-vs-firehose-routing)
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
|   LogManager  --produce()-->  Topic(firehose.log)                        |
|                                  |                                       |
|                                  v                                       |
|                         Partition.write()  =>  /logs/firehose.log/pN/    |
|                         (4KB PIPE_BUF atomic)                            |
|                                                                          |
|   JobIntake::queue()  --produce()-->  Topic(jobintake.log)               |
|                                          |                               |
|                                          v                               |
|                                 Partition.write() with allow_large       |
|                                 (auto-locked, up to 10MB)                |
+--------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       READ PATH (Worker, ~595s lifespan)                 |
|                                                                          |
|  topology firehose-workers.pN:                                           |
|                                                                          |
|    Consumer(firehose.log)  ----+                                         |
|                                |                                         |
|                                v                                         |
|                              Tee  ----> RequestBuilder ----> requests.log|
|                                |                       +---> errors.log  |
|                                |                                         |
|                                +-------> JobRouter     ----> jobs.log    |
|                                              ^                           |
|    Consumer(jobintake.log) -------+----------'                           |
|                                                                          |
|  topology request-workers.pN:                                            |
|                                                                          |
|    Consumer(requests.log) ----> FlameBuilder ----> flames.log            |
|                                              +---> Stats_Store -> mc     |
|                                                                          |
|  topology job-workers.pN:                                                |
|                                                                          |
|    Consumer(jobs.log) ----> JobWorker ----> registered handlers          |
+--------------------------------------------------------------------------+

+--------------------------------------------------------------------------+
|                       AGGREGATOR HUB (one per site)                      |
|                                                                          |
|  StreamMerger (cURL multi)                                               |
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

## Write Path: LogManager

`LogManager` is the per-request firehose writer. It's the only thing in the plugin that runs *during* a WordPress request — everything else runs in the worker fleet.

```php
class LogManager {
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
- `k` — keyword. `start` and `complete` form matched pairs that RequestBuilder reconstructs into nested spans. Anything else is a one-shot entry (`job`, `error`, `warning`, `info`, custom event).
- `m` — payload (nested array, JSON-encoded). FlameBuilder reads this for per-event durations.
- `l` — optional label/free-text.

### URL-secret redaction

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`. Query-string values for `api_key`, `token`, `secret`, `bearer`, etc. become `=REDACTED`. The list intentionally errs broad — anything that looks like a credential gets blanked. The pattern is compiled once at file load; redaction is a single `preg_replace` per URL.

### Refuse-root

`LogManager::__construct()` calls `\posix_geteuid()` early. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction silently no-ops and `enable_logging` flips to false for the rest of the request. The error log gets one rate-limited line so the operator notices.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. `LogManager::matches_url_filter()` short-circuits true for worker requests so spawn round-trips don't pollute the firehose, but `RequestBuilder` checks the same env var when stamping `is_worker` on the completed request so dashboards can filter worker traffic OUT of global stats. Without that exclusion, the supervisor's per-15s spawn cycle would dominate every leaderboard.

### PIPE_BUF and truncation

Per-line writes go to `Topic::fill()` → `Partition::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the JSON envelope around `m`. Anything larger gets replaced with `{"truncated":true}` and a `error_log` line directing the caller to `JobIntake::queue()` (which goes through `Partition::allow_large_writes()` and the per-partition write lock). Truncation is silent at the firehose level — it has to be, because the firehose is fire-and-forget — so size discipline is the caller's responsibility.

### Per-request lifecycle

```
plugins_loaded         LogManager::instance() — constructs, registers shutdown hook
hook_start (priority 1)  ::start( hook_name )
hook callback runs
hook_start (priority MAX-1)  ::complete( hook_name )
…
shutdown               ::finish()   — closes orphaned start entries, emits (process complete)
```

Orphaned `start`s (callback threw, exit called, fatal error before `complete`) get a synthetic `complete` at `finish()` time with `error_status='I'` so RequestBuilder can render the request as incomplete in the dashboards instead of waiting for a `complete` that will never arrive.

## Topologies

Each worker group is one PHP file in `topologies/` returning a closure that wires the graph. The runtime calls the closure with `( CommandInterpreter $ci, int $partition )`; the closure instantiates Node subclasses via `$interpreter->make_node( ... )` and returns them. Four topologies ship:

### `topologies/firehose-workers.php`

Per-partition fanout worker. Tails `firehose.log` + `jobintake.log`; fans out to RequestBuilder + JobRouter; writes `requests.log`, `errors.log`, `jobs.log`.

```php
return static function ( CommandInterpreter $interpreter, int $partition ): array {
    $requests_log = $interpreter->make_node( 'Partition', 'requests:partition', ... );
    $requests_log->allow_large_writes();
    $errors_log   = $interpreter->make_node( 'Partition', 'errors:partition',   ... );
    $errors_log->allow_large_writes();
    $jobs_log     = $interpreter->make_node( 'Partition', 'jobs:partition',     ... );
    $jobs_log->allow_large_writes();

    $request_builder = $interpreter->make_node( 'RequestBuilder', 'request-builder' );
    $request_builder->connect_node( 'requests:partition' );
    $request_builder->set_errors_target( 'errors:partition' );

    $job_router = $interpreter->make_node( 'JobRouter', 'job-router' );
    $job_router->connect_node( 'jobs:partition' );

    $firehose_fanout = $interpreter->make_node( 'Tee', 'firehose:tee' );
    $firehose_fanout->connect_node( 'request-builder' );
    $firehose_fanout->connect_node( 'job-router' );

    $firehose_in  = $interpreter->make_node( 'Consumer', 'firehose:consumer',  ... );
    $firehose_in->connect_node( 'firehose:tee' );
    $jobintake_in = $interpreter->make_node( 'Consumer', 'jobintake:consumer', ... );
    $jobintake_in->connect_node( 'job-router' );

    return [ /* nodes for tests */ ];
};
```

The Tee is only on the firehose side because that source has multiple targets. The jobintake side has one target (JobRouter) and connects directly. **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** Single-target inputs connect directly to the consumer node. Number of Tees = number of source-fan-outs, not number of sources.

`allow_large_writes()` on the output Partitions lifts the per-message cap to 10MB and acquires a per-Partition lock (PIPE_BUF 4KB is otherwise the atomic-append ceiling). RequestBuilder JSON regularly exceeds 4KB on pages with many timed hooks.

### `topologies/request-workers.php`

Per-partition flame builder. Tails `requests.log`; FlameBuilder emits `flames.log` and bumps the 9-namespace memcache schema via `Stats_Store`. Loads its config via `Newspack_Event_Logger_Nodes\Config::load_config('full')` so application-only keys (`auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`) are visible — `PerformanceControllerBase::load_config()` only layers in substrate options, so calling it would return 0 for the thresholds even when the operator set them via the Settings UI.

```php
return static function ( CommandInterpreter $interpreter, int $partition ): array {
    $flames_log = $interpreter->make_node( 'Partition', 'flames:partition', ... );
    $flames_log->allow_large_writes();
    $flames_log->with_index( /* {rid, url_hash, segment_id, offset, length} */ );

    $flame_builder = $interpreter->make_node( 'FlameBuilder', 'flame-builder' );
    $flame_builder->set_stats_store( new Stats_Store( ... ) );
    $flame_builder->set_flames_sink( $flames_log );
    $flame_builder->set_is_hub( ! empty( $config['aggregator_servers'] ) );
    $flame_builder->set_auto_tune( ... );

    $requests_in = $interpreter->make_node( 'Consumer', 'requests:consumer', ... );
    $requests_in->connect_node( 'flame-builder' );

    return [ /* nodes for tests */ ];
};
```

### `topologies/job-workers.php`

Per-partition job dispatcher. Tails `jobs.log`; JobWorker dispatches to registered `newspack_nodes/job_handlers` (and `newspack_nodes/remote_job_handlers` on the hub).

```php
return static function ( CommandInterpreter $interpreter, int $partition ): array {
    $job_worker = $interpreter->make_node( 'JobWorker', 'job-worker' );
    $job_worker->load_handlers_from_filters();

    $jobs_in = $interpreter->make_node( 'Consumer', 'jobs:consumer', ... );
    $jobs_in->connect_node( 'job-worker' );

    return [ /* nodes for tests */ ];
};
```

`load_handlers_from_filters()` runs AFTER `make_node` and BEFORE drain so the maps are populated when the first jobs.log line arrives.

### `topologies/aggregator.php`

Hub-side ingest. One StreamMerger pulls from configured spokes via SSE; sinks into a multi-partition Topic that KEY-routes by URL hash so each downstream firehose-workers partition sees its own slice.

```php
return static function ( CommandInterpreter $interpreter, int $partition ): array {
    $firehose_topic = $interpreter->make_node( 'Topic', 'firehose:topic', $firehose_dir, $num_partitions, ... );
    $stream_merger  = $interpreter->make_node( 'StreamMerger', 'stream-merger' );
    $stream_merger->connect_node( 'firehose:topic' );
    return [ /* nodes for tests */ ];
};
```

Always single-partition for the topology itself (the StreamMerger is a fan-in; partition count comes from the destination Topic, not the merger). Aggregator is **gated** by the `enable_aggregator` config key (strict `=== true`, default OFF): if not explicitly enabled, the topology filter does not register the entry, so the supervisor never spawns the worker and the admin "Aggregator" submenu is hidden. Hubs opt in by setting `'enable_aggregator' => true` in their `newspack-event-logger-nodes-config.php` overlay or via the admin checkbox.

### Topology resolution

Topology fleet is declared as data in `newspack-event-logger-nodes-config.php` so per-site overrides can add or remove entries without patching plugin code:

```php
// newspack-event-logger-nodes-config.php (excerpt)
return [
    // …
    'topologies' => [
        'firehose-workers' => [
            'topology'      => 'topologies/firehose-workers.php',
            'stale_timeout' => 60,
        ],
        'request-workers'  => [
            'topology'      => 'topologies/request-workers.php',
            'stale_timeout' => 60,
        ],
        'job-workers'      => [
            'topology'      => 'topologies/job-workers.php',
            'stale_timeout' => 60,
        ],
        'aggregator'       => [
            'topology'       => 'topologies/aggregator.php',
            'num_partitions' => 1,
            'stale_timeout'  => 60,
        ],
    ],
];
```

The plugin's `newspack_nodes/topologies` filter reads these entries, resolves the path (relative → plugin-rooted, absolute → as-is so a site override can ship its own file), and applies `num_partitions` defaults from the substrate config (so one setting drives both LogManager — write side — and the worker fleet — read side; hardcoding diverges them). Which topologies actually spawn workers is decided downstream by the substrate's Topologies multi-select option (`newspack_nodes_topologies`) — the catalog this filter publishes is the "what topologies exist" list; the substrate filters it by what the operator has checked.

Cost on regular WP requests is one hash insert per `add_filter` — the closure body, the config file's array, and the topology PHP files aren't loaded yet. Actual filter resolution happens in three places, none on the page-render hot path: supervisor's `check_config()` tick (every 15s), worker bootstrap (once per spawn), REST workers/dashboard reads.

## Application Nodes

### RequestBuilder

Assembles requests from firehose entries via the `LruCache` 3-bucket timed-rotation cache. Bucket rotation every 200s; full retention window 3 × 200s = 600s (10 min). Orphans evicted from the oldest bucket emit as timed-out (`error_status: 'T'`).

```php
class RequestBuilder extends Node {
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

### InflightTracker

Reconstructs the live in-flight set from the firehose. Tracks open start/complete pairs per request; when a `complete` arrives, the request graduates from "in-flight" to "recently completed." Backs the Gyroscope dashboard's SSE stream — the page renders a live treemap of what's running right now, plus a fading trail of recent completions.

```php
class InflightTracker {
    private const MAX_REQUESTS    = 10000;   // active in-flight cap
    private const MAX_COMPLETED   = 5000;    // recently-completed retention
    private const MAX_STACK_DEPTH = 100;     // start/complete nesting cap per request
    private const STALE_TIMEOUT   = 300;     // 5min — orphans evicted after this

    public function process( array $entry ): void;
    public function process_line( string $line ): void;
}
```

Unlike RequestBuilder (which sees `requests.log` AFTER per-request reconstruction completes), InflightTracker reads raw firehose entries and maintains its own state per request. It's a pure in-memory tracker — no `Topic`/`Partition` output, no sink. The SSE controller (`GyroscopeStreamController`) constructs one InflightTracker per partition, feeds it firehose lines as they arrive, and serializes the live set into `inflight` events at the configured frame rate.

The 5-minute `STALE_TIMEOUT` is what prevents a partial request (FPM died mid-flight, no `complete` ever written) from haunting the gyroscope indefinitely. Stale entries get evicted on every `process()` tick — cheap because the in-flight map is small.

### FlameBuilder

Aggregates completed-request events into flame-graph stats and writes flame data to `flames.log`. Receives JSON-encoded completed requests from RequestBuilder. Holds a 5×1000 LRU `stats_cache` (`STATS_CACHE_NUM_BUCKETS × STATS_CACHE_BUCKET_SIZE`) of per-URL accumulators; rotates buckets on overflow. Emits flame data into hourly, leaderboard, per-server leaderboard, URL, dimensional, and category memcache namespaces via `Stats_Store`.

When `auto_disable_threshold` and/or `auto_protect_time_threshold` are configured, FlameBuilder also runs noisy / significant event detection during its 5s flush window. Hooks that fire more than `auto_disable_threshold` times in the window are candidates for disable; hooks whose mean event time exceeds `auto_protect_time_threshold` are candidates for "significant" status (protected from auto-disable). Decisions emit downstream as `TM_STRUCT` messages routed to the `auto-tuner` Node — see [AutoTuner](#autotuner) for the dispatch + fan-out.

```php
class FlameBuilder extends Node {
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

### AutoTuner

Receives FlameBuilder's tuning decisions as `TM_STRUCT` messages and applies them locally; when the aggregator is enabled, also queues a `remote_manager` sync_setting job via JobIntake so every enabled spoke picks up the change.

```
FlameBuilder.apply_auto_tune()  ──TM_STRUCT msg──→  AutoTuner.fill()
       TO=auto-tuner                                  │
       KEY=disable_hooks /                            ├─→ enable_aggregator on?
           disable_custom_events /                    │       └─→ SettingsSync::queue_job('remote_manager', ...)
           add_significant_events                     │           (writes to jobintake.log for spoke fanout)
       VALUE={ items: string[], context: {…} }        │
                                                      └─→ update_option(...)   (suppress_sync wrapped)
```

The KEY discriminates the option being tuned; AutoTuner dispatches by KEY inside `fill()`:

| KEY | Updates | Hub-side fanout |
|-----|---------|-----------------|
| `disable_hooks` | `newspack_event_logger_nodes_log_events` minus the items (significant events preserved) | spoke receives the same updated array |
| `disable_custom_events` | `newspack_event_logger_nodes_custom_events` minus the items (significant events preserved) | same |
| `add_significant_events` | `newspack_event_logger_nodes_significant_events` ∪ items | same |

Replaces the legacy `AutoTuneHandlers` six-listener pattern (hub @ priority 5 + standalone @ priority 10 × three events). Both sides used to wire on WP actions; both sides ran in the same request-workers process; the action plumbing was intra-process IPC dressed up as hooks. Expressing it as a Node with `fill()` dispatch is one straight path through the same logic.

**Local-write suppression**: AutoTuner wraps every `update_option` in `SettingsSync::suppress_sync(true)` / `suppress_sync(false)` so the local write doesn't re-trigger SettingsSync's `update_option` listener (which would re-queue the same remote sync that AutoTuner just queued). The hub fan-out happens *before* the local write so a failed fan-out (memcache down, JobIntake stale) doesn't block the local update.

**Distributed lock**: FlameBuilder still holds a 5s `evlog:auto_disable_lock` memcache lock across the three `fill()` calls. Without it, two FlameBuilder workers (different partitions, both flushing at the same instant) would fan out twice for overlapping decisions.

### JobRouter

Multi-input routing. Reads firehose AND jobintake; disambiguates source via Message FROM — Consumer stamps FROM with its own node name (`firehose:consumer`, `jobintake:consumer`), and JobRouter inspects the string to know which input the line came from. The body schema differs slightly between the two sources (firehose wraps under `m`; jobintake is flat), so JobRouter normalizes both into a `{type, handler, parameters, ts}` shape before forwarding to `jobs.log` for JobWorker to dispatch.

| FROM contains | Body key | Allowed kinds |
|---------------|----------|---------------|
| `firehose:consumer` | `entry['m']` (nested) | `job` or `remote_job` |
| `jobintake:consumer` | `entry` (flat) | `job` only — `remote_job` rewritten to `job` |

Validation:

- Handler name pattern `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- `MAX_JOB_SIZE = 10485760` (10MB)

Unknown handlers, oversized lines, and invalid handler names log via `Core::print_less_often` (rate-limited).

JobWorker (downstream) reads `jobs.log` and looks up the handler in `newspack_nodes/job_handlers` (kind=job) or `newspack_nodes/remote_job_handlers` (kind=remote_job) via `load_handlers_from_filters()` at topology bootstrap. Handlers can also be registered programmatically with `JobWorker::set_local_handler()` / `set_remote_handler()`.

### JobWorker

Executes registered job handlers. Per-job try/catch isolates failures. Calls `gc_collect_cycles()` after every job; flushes the WP object cache every `CACHE_FLUSH_INTERVAL` (default 50) jobs; latches a `memory_pressure` flag at 80% of `memory_limit` so the topology drain predicate exits cleanly and the supervisor respawns into a fresh process.

```php
class JobWorker extends Node {
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

### StreamMerger

Pulls remote firehoses via SSE. One cURL handle per remote driven from a shared multi-handle (registered with `EventFramework`). Per-handle WRITEFUNCTION callbacks feed bytes into `process_sse_chunk()` for SSE-line parsing. Reconnect uses exponential backoff (1s initial, 30s max), reset to initial on the first successful event receipt.

```php
class StreamMerger extends Node {
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

StreamMerger does NOT perform the `k:"job"` -> `k:"remote_job"` rewrite itself. The rewrite is a `newspack_nodes/aggregator_ingest_line` filter (renamed from `newspack_event_aggregator_ingest_line`) registered by the application plugin's hub-side bootstrap; StreamMerger applies the filter chain. Plugins that don't load the rewrite filter (because they're spokes, not hubs) get raw `k:"job"` entries through.

### StatsAggregator

Reusable Node that bumps the 9-namespace memcache schema on every completed request. Optional `Stats_Store` injection: when null, falls back to in-memory mode for tests; when wired, every `fill()` pushes through the full schema. In the shipped topology graph the FlameBuilder is the active stats producer (it owns flame generation AND stats fan-out via its own `Stats_Store`); StatsAggregator is the standalone variant for topologies that want stats without flame data.

```php
class StatsAggregator extends Node {
    private const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

    public function fill( array &$message ): void {
        $entry = json_decode( $message[ Message::VALUE ], true );
        $url      = $entry['url'];
        $req_time = (float) $entry['req_time'];
        $this->store?->bump_url(           $url, $req_time );
        $this->store?->bump_leaderboard(   $req_time, $entry['categories'] ?? [] );
        $this->store?->bump_hourly(        $req_time, $entry['peak_mb'] ?? 0 );
        // server, dimensions, url_hash...
    }
}
```

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

Overflow rolls into a synthetic `Other` bucket. The `total` pseudo-category is preserved before sorting — see the existing FlameBuilder implementation; do not regress when porting.

**`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff. Both `Memcached_Cache` and `FakeMemcached` (test double) implement multi-get.

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
              |  StreamMerger pulls from    |
              |  configured spokes          |
              |                             |
              |  RemoteManager handles      |
              |  remote_job dispatch        |
              |                             |
              |  SettingsSync fans out      |
              |  options to spokes          |
              +-----------------------------+
```

**Hub identification**: a site is acting as a hub when `enable_aggregator` is strictly `=== true` AND it has at least one spoke registered. The toggle is a single operator switch — strict polarity, default OFF (fresh installs are not hubs). It gates the Aggregator admin submenu visibility and the push-side fan-out paths (`SettingsSync` and `AutoTuner` short-circuit when off). Pull-side activation (whether the StreamMerger worker actually spawns) is decoupled — that's driven by whether `aggregator` is in the substrate's Topologies multi-select.

**`k:"job"` vs `k:"remote_job"`**:

Nodes only ever write `k:"job"` to their own firehose — there's no "spoke vs hub" distinction at write time. The distinction emerges at dispatch:

- Every node's JobWorker tails its own `jobs.log` and dispatches `k:"job"` entries against the `newspack_nodes/job_handlers` filter. This runs locally on every node, hub or spoke.
- The hub additionally runs StreamMerger, which pulls each enabled spoke's firehose. StreamMerger applies the `newspack_nodes/aggregator_ingest_line` filter, which rewrites the ingested `k:"job"` lines to `k:"remote_job"` before they reach the hub's firehose. The hub's JobWorker then dispatches those entries against `newspack_nodes/remote_job_handlers`.
- The two filters are independent registrations — a job type registers under whichever side(s) should run it:
  - **`job_handlers` only** → runs locally on every node. The hub's view of spoke-aggregated copies (as `remote_job`) is ignored.
  - **`remote_job_handlers` only** → only the hub acts. Spokes write the entries but don't act on them; the hub does the centralized work after aggregation.
  - **Both** → handler runs locally on every node, AND runs on the hub for entries aggregated from spokes. Useful when a job needs local + centralized handling under the same name (the two handler implementations can differ — e.g., one filters by local attributes, the other dispatches differently).

The rewrite filter is NOT auto-loaded. Plugins that don't register the filter (because they're spokes, not hubs) get raw `k:"job"` entries through and dispatch locally — exactly what spokes want.

## Hub-Side Helpers: ServerRegistry / RemoteManager / Discovery

Three static-mode classes own the hub's outbound side. They don't run in the worker fleet — they run in admin requests (Remote Servers UI), inside JobWorker contexts when handling `remote_manager` jobs, and on the hub's periodic health-check tick.

### Remote activity gate: `enable_aggregator`

A single config flag, `enable_aggregator` (default OFF), gates the operator-visible side of hub-mode:

- **Admin submenu** — the `Aggregator` submenu under Performance is hidden when off. The "Enable Aggregator" checkbox in Event Logger Settings → Remote Servers is the operator-facing toggle.
- **Push side** — `SettingsSync::maybe_queue_static_sync` and `AutoTuner::persist` short-circuit when the option is off, so option changes and auto-tune decisions don't queue `remote_manager` fan-out jobs.

Pull-side worker activation is independent: the StreamMerger spawns whenever `aggregator` is in the substrate's Topologies multi-select. Two operator choices, two surfaces — checking "Enable Aggregator" without also checking the `aggregator` topology gives you a visible dashboard with no live data; checking the topology without the checkbox gives you a running worker but no UI to see it. The intentional design is that operators do both when standing up a hub; either alone is a partial state.

### `ServerRegistry`

CRUD for the spoke list. Storage is `newspack_event_logger_nodes_aggregator_servers` (a single autoloaded option) merged with whatever `aggregator_servers` keys arrive via `newspack_event_logger_nodes/config` filter. Filter-supplied entries are read-only at the registry level — `remove()` no-ops on a config-supplied server (`is_config_server()` returns true) so an operator click can't disable a server the config file declares.

```php
class ServerRegistry {
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

### `RemoteManager`

Outbound HTTP to spokes plus a registered `remote_manager` job handler. Two distinct roles:

1. **Discovery sweep** (periodic): walks every enabled server, calls `/wp-json/newspack-nodes/v1/discovery` on each one, collects per-server payloads, then calls `HealthCheckExtensions::process_discovery()` directly (and also fires `newspack_event_logger_nodes/health_check_discovery` for external listeners), then calls `sync_all_settings()` to push every operator-configured option to every enabled spoke.

2. **`remote_manager` job handler**: registered via `newspack_nodes/job_handlers`. The handler dispatches by `action`:
   - `sync_setting` → POST `{option, value}` to every enabled spoke under the configured endpoint. The settings endpoint must be in `ALLOWED_ENDPOINT_PREFIXES` (`newspack-nodes` or `newspack-nodes-aggregator` namespaces only).
   - `health_check` → idempotent shortcut for dispatchers that prefer queueing over `add_action`.
   - Anything else → looked up in `newspack_event_logger_nodes/remote_actions` filter for plugin extension. Unknown actions log via `print_less_often` and drop.

Stale-job protection: every `remote_manager` job carries `queued_at`. Jobs older than `STALE_THRESHOLD` (~300s, just over the discovery interval) drop with a rate-limited error log. Prevents a stuck cron tick from blasting through dozens of obsolete sync requests in a row.

Caps: `MAX_SERVERS = 100`, `MAX_SETTINGS = 50` per sync. Prevents a filter-abuse from triggering an unbounded fan-out.

### `HealthCheckExtensions`

Merges discovered hooks and custom events from spokes into the hub's local settings. Called directly by `RemoteManager::health_check()` (no WP action indirection — the in-plugin coupling is one method call). The action is *also* fired alongside, so external plugins (pyrobase, etc.) can still subscribe.

`process_discovery( array $all_discovery )` does two merges:

- Discovered **registered hooks** from each spoke → `newspack_event_logger_nodes_log_events`. New names get added; existing names stay; the local "checked / unchecked" state is preserved (discovered hooks land unchecked by default).
- Discovered **custom events** from each spoke → `newspack_event_logger_nodes_discovered_events`. New events get a `true` flag; existing entries are left alone.

Both merges suppress `SettingsSync` first (`SettingsSync::suppress_sync()` / `finally`) so the local `update_option` write doesn't get fanned BACK out to the spokes that just contributed it. Without the suppression, every discovery sweep would echo every spoke's hooks to every other spoke. Cap on accumulated entries: `MAX_EVENTS = 10000`.

## SettingsSync: Aggregator Gate

`SettingsSync::maybe_queue_static_sync` short-circuits when the operator-facing aggregator toggle is off — same gate the StreamMerger pull-side uses, so one switch stops both directions of remote-server activity:

```php
// In SettingsSync::maybe_queue_static_sync(...)
if ( ! (int) \get_option( 'newspack_event_logger_nodes_enable_aggregator', 1 ) ) {
    return;
}
```

`AutoTuner::persist` carries the same check around its `remote_manager` queue. No separate hub flag — the legacy `enable_workers`-as-hub-designation never actually drove behavior on its own (it composed with `enable_aggregator` to mean "really fan out"), so dropping the strict-`=== true` polarity dance and going with the single operator toggle eliminates a whole category of polarity-drift bugs.

**Re-entrancy guard via `$syncing`**: HealthCheckExtensions calls `update_option` in response to a sync; without the guard, that triggers another sync, ad infinitum. The `suppress_sync(true)` API is called before the update, restored after.

**JobIntake for >4KB payloads**: options like `log_events` (50+ hook names) routinely exceed 4KB. SettingsSync uses `JobIntake::queue('remote_manager', ...)` with the option name as the partition key (so one sync per option at a time across the queue).

**Endpoint allowlist**: `ALLOWED_ENDPOINT_PREFIXES = ['/wp-json/newspack-nodes/', '/wp-json/newspack-nodes-aggregator/']` + handler-name parameter sanitization. Security boundary; port verbatim with renamed prefixes.

## JobIntake vs Firehose Routing

Two write paths into the job queue:

```
+--------------------------+         +---------------------------+
|  LogManager              |         |  JobIntake::queue()       |
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
              JobRouter (reads BOTH)
                  |
                  v
           jobs.log per partition
                  |
                  v
              JobWorker
                  |
                  v
           registered handlers
```

**Use firehose** (`LogManager->message('job', $payload)`) when:

- Payload fits in 4KB (PIPE_BUF atomic append).
- Job needs to be aggregated from spokes to hub (only firehose entries flow through StreamMerger).

**Use JobIntake** (`JobIntake::queue($handler, $params)`) when:

- Payload exceeds 4KB (serialized option blobs, image-handler args, large arrays).
- Job is local-only (jobintake never aggregates; entries stay on the originating site).

Using the wrong path silently loses jobs. LogManager truncates anything >4KB to `['truncated' => true]`. JobIntake never aggregates so spoke jobs there never reach the hub.

JobIntake has three partition-selection modes:

- **pinned** — caller specifies partition index directly via `$intake->partition( $i )`.
- **keyed** — `Partition::hash_to_partition( $key, $num_partitions )` (URL-style, identical to firehose).
- **round-robin** — static counter modulo `PHP_INT_MAX` for callers without a meaningful key.

Lock-holding is per-Partition (no host-wide intake lock — that was removed). `Partition::allow_large_writes()` acquires the partition's write lock at construction and holds it for the partition's lifetime. One-off callers (`JobIntake::queue()`) construct a one-shot JobIntake, write, and let the destructor release the lock; batch callers reuse the same `JobIntake` instance across many `queue_many()` calls so the lock acquisition cost amortizes.

## Configuration

Three storage tiers, layered in this order (later wins):

1. **File-based defaults** in `newspack-event-logger-nodes-config.php` (returns an array; loaded by `Config::load_config()` if present alongside the plugin). For shared / per-environment defaults that ship with deployment. Never edited via the admin UI.
2. **WordPress options** under the `newspack_event_logger_nodes_*` prefix (application) and `newspack_nodes_*` prefix (substrate, owned by the other plugin but read here via the substrate `Config`). Edited via the admin UI; persisted via `update_option`.
3. **Filter `newspack_event_logger_nodes/config`**: last-call override. Plugins can layer in computed values (per-server tuning, dynamic feature flags) that win over both files and options.

```php
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // core keys only
$config = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );  // includes performance + tuning keys
```

The `'full'` mode loads every key including `auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`, `aggregator_servers` (admin-side tuning that workers don't all need). The `request-workers` topology calls `load_config('full')` because FlameBuilder needs the auto-tune thresholds; other call sites use the cheaper default load.

### Application option keys

| Option | Type | Mode | Default | Use |
|--------|------|------|---------|-----|
| `enable_logging` | bool | core | `true` | Master switch for the firehose write path |
| `enable_workers` | bool | core | inherits from substrate | Strict `=== true` enables hub mode |
| `enable_aggregator` | int (0/1) | core | `1` | Gates the aggregator topology + admin submenu + push fan-out |
| `enable_jobs` | bool | core | `true` | When false, JobWorker drains but doesn't dispatch |
| `log_urls` | array of strings | core | `[]` | URL substring allowlist (empty = log everything) |
| `skip_urls` | array of strings | core | `[]` | URL substring denylist; wins over `log_urls` |
| `log_events` | array of strings | core | `[]` | Hook names to instrument (start/complete pairs at priority 1 / MAX-1) |
| `custom_events` | array of strings | core | `[]` | Custom event names to log |
| `discovered_events` | array of strings | extended | `[]` | Custom events discovered from spokes — merged in via `HealthCheckExtensions` |
| `aggregator_servers` | array | extended | `[]` | Spoke registry (`ServerRegistry` storage); per-server `{url, auth_username, auth_password, enabled}` |
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
- `memcache_servers` — Memcached_Cache caches its connection; restart required.
- Salt rotation (`Stats_Store::flush_all()`) — workers cache `prefix` at construction; restart for immediate effect.

## REST + React

REST namespaces:

- `newspack-nodes/v1/*` — core. Status, performance (overview / urls / requests / dashboard / timing / workers / settings / config / hooks), events, gyroscope, logger, request-log, firehose (admin + SSE), servers, settings, discovery, workers/spawn.
- `newspack-nodes-aggregator/v1/*` — hub-side aggregator. Status, servers, health.

Two base classes back the controller hierarchy:

- **`PerformanceControllerBase`** — capability check, partition validation, fixed-window rate limit (600req/60s default; fail-open if memcache down), `not_found_error()` shape, `load_config()` via `newspack_nodes/config` filter.
- **`SSEControllerBase`** — slot pool, heartbeat protocol, runtime cap, flush padding. See below.

For the full endpoint list, see [API.md](API.md).

### Real-time path: slot pool + heartbeat

Every SSE controller (`FirehoseStreamController`, `RequestsStreamController`, `GyroscopeStreamController`, `ErrorsStreamController`, `RawlogsController`) inherits from `SSEControllerBase`. The base handles the pieces that are easy to get wrong if every controller rolls its own.

```
+-----------------+      acquire_sse_slot()      +-----------------+
|  Browser opens  | ----------------------------> |  memcache slot  |
|  EventSource    |    SLOT_TTL_BROWSER = 10s     |  pool (max 10)  |
+-----------------+                                +-----------------+
        |                                                  |
        | < emit `connected` / `config` event              |
        |                                                  |
        v                                                  |
  loop @ 5Hz:                                              |
    poll source (firehose tail, in-flight tracker, ...)    |
    emit `complete_batch` / `inflight` events              |
    if no traffic in HEARTBEAT_INTERVAL (5s):              |
      emit `heartbeat` event   <----------- keeps EventSource alive
    if SLOT_CHECK_INTERVAL (5s) elapsed:                   |
      verify slot still in pool ----------- (fail-CLOSED: if gone, exit 0)
    if MAX_RUNTIME (1h) elapsed:                           |
      emit `timeout` event, exit -------- (client reconnects)
                                                           |
                                                           |
  Meanwhile, browser POSTs /firehose/heartbeat every 5s --+
  to refresh the slot's TTL. If the tab dies, the slot expires
  in 10s and the controller exits 0 on its next slot check.
```

**Slot pool**:

| Constant | Value | Use |
|----------|-------|-----|
| `MAX_SSE_SLOTS` | 10 | per `user_id:remote_addr` pair |
| `SLOT_TTL_BROWSER` | 10 s | how long a slot survives without a heartbeat refresh |
| `SLOT_TTL_AGGREGATOR` | 30 s | longer TTL for hub-side aggregator connections (StreamMerger cURL handles can stall briefly under load) |
| `SLOT_CHECK_INTERVAL` | 5 s | how often the controller re-validates its slot mid-stream |
| `HEARTBEAT_INTERVAL` | 5 s | min interval between server-to-client `heartbeat` events |
| `MAX_RUNTIME` | 3600 s | absolute cap; controller emits `timeout` and exits |

Slot acquisition is atomic via `Memcached::add()` — race-free against itself, no `get-then-set` window. The slot pool IS the rate limit; **memcache failure fails CLOSED** (HTTP 429 if `add()` errors). This is the asymmetric flip side of the stats path's fail-soft behavior. Stats can degrade to "no data" gracefully because the dashboard is read-only; SSE streams ARE the live workload and dropping the rate limit would let one runaway client saturate the worker pool.

**Browser heartbeat protocol**: clients POST `/wp-json/newspack-nodes/v1/firehose/heartbeat` every 5s with the slot number returned in the `connected` event. Server refreshes the slot's TTL on each hit. If the browser tab is closed (or its network drops), heartbeats stop, the slot expires within `SLOT_TTL_BROWSER`, and the next `SLOT_CHECK_INTERVAL` tick exits the controller cleanly (rate-limit slot returned to the pool for the next reconnect).

**Server-to-client heartbeats** (independent from the browser heartbeat above): every `HEARTBEAT_INTERVAL` the controller emits an `event: heartbeat` payload if no real data flowed. Keeps the `EventSource` alive across proxies that idle-close silent connections (cloudflare, varnish, etc.). The 4096-byte flush padding lives in the same path — every emit `flush_if_needed()`s a 4KB null-padded write to defeat upstream gzip buffering.

### React trees

6 dashboards (`event-aggregator`, `event-dashboards`, `performance-dashboards`, `performance-gyroscope`, `performance-logger`, `performance-request-log`) plus a `shared` helpers tree consume the renamed REST namespaces. Source-of-truth shared hooks live in `src/shared/`; every tree imports directly from there (no per-tree duplication after the single-plugin consolidation). Audit grep-based before merge — missed reference is a silent 404 in browser. See [MIGRATION.md](MIGRATION.md) for the namespace-rewrite audit.

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
- [MIGRATION.md](MIGRATION.md) — React tree cutover from `newspack-event-logger-plugins`.
- [Runtime ARCHITECTURE.md](../newspack-nodes/ARCHITECTURE.md) — substrate this plugin depends on.
