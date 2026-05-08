# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](../newspack-nodes/) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL), see `../newspack-nodes/ARCHITECTURE.md`.

The canonical design document is [`services/pyrobase/sources/.specs/2026-05-06-newspack-nodes-design.md`](../../../.specs/2026-05-06-newspack-nodes-design.md). For migration context (what this plugin replaces), see [MIGRATION.md](MIGRATION.md).

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
|                       READ PATH (Worker, ~595s)                          |
|                                                                          |
|  topology firehose-workers.pN:                                           |
|                                                                          |
|    Tail(firehose.log)  ----+                                             |
|                            |                                             |
|                            v                                             |
|                          Tee  ----> RequestBuilder  ----> requests.log   |
|                            |                  +--------> errors.log     |
|                            |                                             |
|                            +-------> JobRouter      ----> jobs.log       |
|                                          ^                               |
|    Tail(jobintake.log) -------+----------'                               |
|                                                                          |
|  topology request-workers.pN:                                            |
|                                                                          |
|    Tail(requests.log)  ----> FlameBuilder  ----> flames.log              |
|                                              +-> StatsAggregator -> mc   |
|                                                                          |
|  topology job-workers.pN:                                                |
|                                                                          |
|    Tail(jobs.log)  ----> JobWorker  --> registered handlers              |
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

Each worker group is one PHP file in `topologies/` returning a closure that wires the graph. The runtime calls the closure with `( CommandInterpreter $ci, int $partition )`; the closure instantiates Node subclasses and returns them.

### `topologies/firehose-workers.php`

Per-partition firehose worker. Reads `firehose.log` and `jobintake.log`; fans out to RequestBuilder + JobRouter; routes jobs to JobWorker.

```php
return static function ( CommandInterpreter $ci, int $partition ): array {
    $firehose_in     = new Tail( "{$logs}/firehose.log",  'line-buffered' );
    $jobintake_in    = new Tail( "{$logs}/jobintake.log", 'line-buffered' );
    $firehose_fanout = new Tee();
    $request_builder = new RequestBuilder();
    $job_router      = new JobRouter();
    $job_worker      = new JobWorker();

    $firehose_in->sink( $firehose_fanout );
    $firehose_fanout->connect_node( $request_builder->name() );
    $firehose_fanout->connect_node( $job_router->name() );
    $jobintake_in->sink( $job_router );        // single target -> direct sink, no Tee
    $job_router->sink( $job_worker );

    return [ /* nodes for tests */ ];
};
```

The Tee is only on the firehose side because that source has multiple targets. The jobintake side has one target (JobRouter) and connects directly. **A Consumer's `sink()` goes to a Tee only when the source has more than one target.** Single-target inputs sink directly to the consumer node. Number of Tees = number of source-fan-outs, not number of sources.

### `topologies/aggregator.php`

Hub-side. One StreamMerger pulls from configured spokes; sinks into a multi-partition Topic.

```php
return static function ( CommandInterpreter $ci, int $partition ): array {
    $firehose_topic = new Topic( "{$logs}/firehose.log", $num_partitions, ... );
    $stream_merger  = new StreamMerger();
    $stream_merger->sink( $firehose_topic );
    return [ ... ];
};
```

Always single-partition for the topology itself (the StreamMerger is a fan-in; partition count comes from the destination Topic, not the merger).

### Topology resolution

Application plugin registers via filter at bootstrap:

```php
add_filter( 'newspack_nodes/topologies', function ( $topologies ) {
    $topologies['firehose-workers'] = [
        'num_partitions' => 4,
        'topology'       => __DIR__ . '/topologies/firehose-workers.php',
    ];
    $topologies['aggregator'] = [
        'num_partitions' => 1,
        'topology'       => __DIR__ . '/topologies/aggregator.php',
    ];
    return $topologies;
} );
```

Cost on regular WP requests is one hash insert per `add_filter` — the closure body and the topology PHP file aren't loaded yet. Actual filter resolution happens in three places, none on the page-render hot path: supervisor's `check_config()` tick (every 15s), worker bootstrap (once per spawn, ~595s cadence), REST workers/dashboard reads.

## Application Nodes

### RequestBuilder

Assembles requests from firehose entries via 3-bucket LRU cache. Bucket key = `floor(now / 200)`; rotation evicts oldest bucket; orphans emit as timed-out (`timeout: true`). Full retention window 3 × 200s = 600s.

```php
class RequestBuilder extends Node {
    public const BUCKET_INTERVAL_S      = 200;
    public const NUM_BUCKETS            = 3;
    public const DEFAULT_MAX_PER_BUCKET = 100;

    private array $buckets = [];          // bucket_key -> rid -> request
    private array $rid_to_bucket = [];    // rid -> bucket_key

    public function fill( array &$message ): void {
        // Parse firehose JSONL line; assemble start/complete pairs into request.
        // On rotation: evict timed-out bucket (sets timeout: true on each orphan).
        // On overflow: evict oldest entry from oldest bucket.
        // On request complete: emit JSON-encoded record via $this->sink.
    }
}
```

Eviction emits via `$this->sink->fill( $synthetic_message )`, NOT direct file writes. This is load-bearing for composability: timed-out requests must flow through Tee so errors.log gets a copy, hooks can observe, tests can capture. Don't write to files from inside an eviction callback.

### FlameBuilder

Aggregates completed-request events into flame-graph stats. Receives JSON-encoded completed requests from RequestBuilder. Currently aggregates `count` + `sum_time` per event-name; 5×1000 stats_cache rotation is deferred until the production port lands.

```php
class FlameBuilder extends Node {
    private array $stats = [];   // name -> {count, sum_time}

    public function fill( array &$message ): void {
        $req = json_decode( $message[ Message::VALUE ], true );
        foreach ( $req['events'] ?? [] as $event ) {
            $this->stats[ $event['name'] ]['count']    ??= 0;
            $this->stats[ $event['name'] ]['sum_time'] ??= 0.0;
            ++$this->stats[ $event['name'] ]['count'];
            $this->stats[ $event['name'] ]['sum_time'] += (float) $event['time'];
        }
    }
}
```

Flush every 5s via Router-hitchhike Timer; flush on shutdown via `cleanup` (TM_EOF handler).

### JobRouter

Multi-input routing. Reads firehose AND jobintake; disambiguates source via Message KEY (set by upstream Tail). Format: `{source}:{kind}` where source ∈ `{firehose, jobintake}` and kind ∈ `{job, remote_job}`.

| KEY value | Routing |
|-----------|---------|
| `firehose:job` | local handler (registered via `set_local_handler`) |
| `firehose:remote_job` | remote handler (registered via `set_remote_handler`) |
| `jobintake:*` | local handler (jobintake never carries `remote_job`) |

Validation:

- Handler name pattern `/^[a-z][a-z0-9_]*$/`
- `MAX_JOB_SIZE = 10485760` (10MB)

Unknown handlers, oversized lines, and invalid handler names log via `Core::print_less_often` (rate-limited).

Fallback: if KEY is empty or doesn't match the pattern, the line is treated as local-source and the JSON `k` field decides routing (`k:"job"` -> local, `k:"remote_job"` -> remote). Keeps single-source topologies and minimal test setups working without forcing every test to set KEY.

### JobWorker

Executes registered job handlers. Per-job try/catch isolates failures. Between-jobs callback fires every N (default 50) for `gc_collect_cycles + wp_cache_flush` discipline against the 80% memory watermark.

```php
class JobWorker extends Node {
    public const MAX_JOB_SIZE = 10485760;

    public function fill( array &$message ): void {
        $entry = json_decode( $message[ Message::VALUE ], true );
        if ( ( $entry['k'] ?? '' ) !== 'job' ) return;
        $handler = $entry['handler'] ?? '';
        if ( ! isset( $this->handlers[ $handler ] ) ) {
            Core::print_less_often( "JobWorker: missing handler: $handler" );
            return;
        }
        try { ( $this->handlers[ $handler ] )( $entry['payload'] ?? null ); }
        catch ( \Throwable $e ) { Core::print_less_often( /* ... */ ); }
        ++$this->jobs_executed;
        if ( $this->jobs_executed % $this->between_jobs_every === 0 ) {
            ( $this->between_jobs_cb )();   // gc + wp_cache_flush
        }
    }
}
```

Image-handler circular refs (`wp_generate_attachment_metadata` loading full-resolution images into GD) are the documented reason for the discipline. Preserve in any successor.

### StreamMerger

Pulls remote firehoses via SSE. One cURL handle per remote driven from a shared multi-handle (registered with `EventFramework`). Per-handle WRITEFUNCTION callbacks feed bytes into `process_sse_chunk()` for SSE-line parsing. Reconnect with 5s backoff.

```php
class StreamMerger extends Node {
    public const RECONNECT_BACKOFF_S = 5;

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

Bumps the 9-namespace memcache schema on every completed request. Optional `Stats_Store` injection: when null, falls back to in-memory legacy mode for tests; when wired, every `fill()` pushes through the full schema.

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

- **pinned** — caller specifies partition index directly.
- **keyed** — `Partition::hash_to_partition( $key, $num_partitions )` (URL-style, identical to firehose).
- **round-robin** — static counter modulo `PHP_INT_MAX` for callers without a meaningful key.

Lock-holding modes are now Partition-native (see runtime ARCHITECTURE.md "Partition::allow_large_writes"). One-off callers (`JobIntake::queue()`) write per-call; batch callers wrap their writes in `Partition::with_lock( $fn )` to hold the lock once across many.

## REST + React

REST namespaces:

- `newspack-nodes/v1/*` — core. Status, performance, events, gyroscope, logger, request-log, workers, spawn.
- `newspack-nodes-aggregator/v1/*` — hub-side aggregator. Status, servers, health.

Two base classes are critical and ported in parallel with the data graph (NOT folded into it):

- **`PerformanceControllerBase`** — capability check, partition validation, fixed-window rate limit (60req/60s default; fail-open if memcache down), `not_found_error()` shape, `load_config()` via `newspack_nodes/config` filter.
- **`SSEControllerBase`** (deferred) — 10-slot memcache rate limit, 5s server-to-client heartbeat, 1h `MAX_RUNTIME`, `SLOT_TTL_BROWSER=10s`, `SLOT_TTL_AGGREGATOR=30s`, 4096-byte flush padding.

For the full endpoint list, see [API.md](API.md).

9 React trees consume the renamed REST namespaces. Source-of-truth shared hooks live in `src/shared/`; copies in plugin-specific subtrees should be synced from the canonical source. Audit grep-based before merge — missed reference is a silent 404 in browser. See [MIGRATION.md](MIGRATION.md) for the namespace-rewrite audit.

## See also

- [AGENTS.md](AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- [MIGRATION.md](MIGRATION.md) — React tree cutover from `newspack-event-logger-plugins`.
- [Runtime ARCHITECTURE.md](../newspack-nodes/ARCHITECTURE.md) — substrate this plugin depends on.
- [Spec](../../../.specs/2026-05-06-newspack-nodes-design.md) — canonical design document.
