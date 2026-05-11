# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL), see `../newspack-nodes/ARCHITECTURE.md`.

For migration context (what this plugin replaces), see [MIGRATION.md](MIGRATION.md).

## Table of Contents

- [Overview](#overview)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Memcache Schema (9 Namespaces)](#memcache-schema-9-namespaces)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [SettingsSync: Fail-Closed Polarity](#settingssync-fail-closed-polarity)
- [JobIntake vs Firehose Routing](#jobintake-vs-firehose-routing)
- [REST + React](#rest--react)

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
|     k:"job"  ->  k:"remote_job"   (so spoke jobs do not double-execute)  |
+--------------------------------------------------------------------------+
```

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

Always single-partition for the topology itself (the StreamMerger is a fan-in; partition count comes from the destination Topic, not the merger). Aggregator is **gated** by the `newspack_event_logger_nodes_enable_aggregator` option (defaults ON): if disabled, the topology filter does not register the entry, so the supervisor never spawns the worker and the admin "Aggregator" submenu is hidden.

### Topology resolution

Application plugin registers via filter at bootstrap:

```php
add_filter( 'newspack_nodes/topologies', function ( $topologies ) {
    $num_partitions = max( 1, min( 16, (int) ( Config::load_config( 'full' )['num_partitions'] ?? 1 ) ) );
    $topologies['firehose-workers'] = [ 'topology' => '...', 'num_partitions' => $num_partitions, 'stale_timeout' => 60 ];
    $topologies['request-workers']  = [ 'topology' => '...', 'num_partitions' => $num_partitions, 'stale_timeout' => 60 ];
    $topologies['job-workers']      = [ 'topology' => '...', 'num_partitions' => $num_partitions, 'stale_timeout' => 60 ];
    if ( (int) get_option( 'newspack_event_logger_nodes_enable_aggregator', 1 ) ) {
        $topologies['aggregator']  = [ 'topology' => '...', 'num_partitions' => 1,                'stale_timeout' => 60 ];
    }
    return $topologies;
} );
```

`num_partitions` reads from the substrate config so one setting drives both LogManager (write side) and the worker fleet (read side). Hardcoding diverges the two — e.g. config=1 + topology=4 spawns 3 workers that never see any traffic.

Cost on regular WP requests is one hash insert per `add_filter` — the closure body and the topology PHP file aren't loaded yet. Actual filter resolution happens in three places, none on the page-render hot path: supervisor's `check_config()` tick (every 15s), worker bootstrap (once per spawn), REST workers/dashboard reads.

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

### FlameBuilder

Aggregates completed-request events into flame-graph stats and writes flame data to `flames.log`. Receives JSON-encoded completed requests from RequestBuilder. Holds a 5×1000 LRU `stats_cache` (`STATS_CACHE_NUM_BUCKETS × STATS_CACHE_BUCKET_SIZE`) of per-URL accumulators; rotates buckets on overflow. Emits flame data into hourly, leaderboard, per-server leaderboard, URL, dimensional, and category memcache namespaces via `Stats_Store`. Hub mode also feeds `auto_disable_threshold` / `auto_protect_time_threshold` machinery (`AutoTuneHandlers`) for noisy / significant event detection.

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
    update_option( 'newspack_nodes_stats_salt', $salt );
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
|  enable_workers|   |  enable_workers|   |  enable_workers|
|     != true    |   |     != true    |   |     != true    |
+--------+-------+   +--------+-------+   +--------+-------+
         | SSE              | SSE                | SSE
         +------------------+--------------------+
                            v
              +-----------------------------+
              |  Hub                        |
              |  newspack-event-logger-     |
              |  nodes (hub)                |
              |  enable_workers === true    |
              |    (strict)                 |
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

**Hub identification**: `enable_workers === true` (strict). Anything else (missing, false, 1, "1") means "not a hub, do not fan out."

**`k:"job"` vs `k:"remote_job"`**:

- Spokes write `k:"job"`. Local JobWorker dispatches via `newspack_nodes/job_handlers` filter on the spoke itself.
- Hub's StreamMerger applies `newspack_nodes/aggregator_ingest_line` filter to ingested lines; the filter rewrites `k:"job"` -> `k:"remote_job"` for entries from spokes.
- Hub's JobWorker dispatches `k:"remote_job"` via `newspack_nodes/remote_job_handlers` (a SEPARATE filter from `job_handlers`).
- Hub-only handlers (Cloudflare CDN purges, cross-publication coordination) register on `remote_job_handlers` only.
- Spoke-side handlers register on `job_handlers`. They run on the spoke; the rewrite + separate filter prevents the hub from re-executing them.

The rewrite filter is NOT auto-loaded. Plugins that don't register the filter (because they're spokes, not hubs) get raw `k:"job"` entries through and dispatch locally — exactly what spokes want.

## SettingsSync: Fail-Closed Polarity

**Critical invariant**: `enable_workers` MUST be strict `=== true`. Anything else (missing, false, 1, "1") means "not a hub, do not fan out."

```php
// In SettingsSync::on_option_update(...)
if ( ! isset( $this->config['enable_workers'] ) || true !== $this->config['enable_workers'] ) {
    return;   // Fail closed.
}
```

Diverging from this was a real silent-fan-out bug fixed in legacy 2.4.42. Do not regress. The opposite polarity (`?? false` or `!! `) silently turns spoke instances into hubs and you don't notice until two sites start fighting over option ownership.

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

## REST + React

REST namespaces:

- `newspack-nodes/v1/*` — core. Status, performance (overview / urls / requests / dashboard / timing / workers / settings / config / hooks), events, gyroscope, logger, request-log, firehose (admin + SSE), servers, settings, discovery, workers/spawn.
- `newspack-nodes-aggregator/v1/*` — hub-side aggregator. Status, servers, health.

Two base classes back the controller hierarchy:

- **`PerformanceControllerBase`** — capability check, partition validation, fixed-window rate limit (600req/60s default; fail-open if memcache down), `not_found_error()` shape, `load_config()` via `newspack_nodes/config` filter.
- **`SSEControllerBase`** — 10-slot memcache rate limit, 5s server-to-client heartbeat, 1h `MAX_RUNTIME`, `SLOT_TTL_BROWSER=10s`, `SLOT_TTL_AGGREGATOR=30s`, 4096-byte flush padding. Used by `FirehoseStreamController`, `RequestsStreamController`, `GyroscopeStreamController`, `ErrorsStreamController`, `RawlogsController`.

For the full endpoint list, see [API.md](API.md).

7 React trees (`event-aggregator`, `event-dashboards`, `performance-dashboards`, `performance-gyroscope`, `performance-logger`, `performance-request-log`, `shared`) consume the renamed REST namespaces. Source-of-truth shared hooks live in `src/shared/`; every tree imports directly from there (no per-tree duplication after the single-plugin consolidation). Audit grep-based before merge — missed reference is a silent 404 in browser. See [MIGRATION.md](MIGRATION.md) for the namespace-rewrite audit.

## See also

- [AGENTS.md](AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- [MIGRATION.md](MIGRATION.md) — React tree cutover from `newspack-event-logger-plugins`.
- [Runtime ARCHITECTURE.md](../newspack-nodes/ARCHITECTURE.md) — substrate this plugin depends on.
