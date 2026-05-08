# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](../newspack-nodes/) runtime. Replaces the 10-plugin `newspack-event-logger-plugins` monorepo: high-throughput WordPress request-lifecycle logging, real-time SSE streaming, flame-graph generation, and hub/spoke aggregation — expressed as Nodes.

This plugin owns the application: `RequestBuilder`, `FlameBuilder`, `JobRouter`, `JobWorker`, `StreamMerger`, `StatsAggregator`, `Stats_Store`, `ServerRegistry`, `SettingsSync`, `Memcached_Cache`. It depends on the runtime for everything substrate-level (Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL).

## Relation to Newspack Nodes

```
                  newspack-event-logger-nodes  (this plugin)
                              │
                              │  application Node subclasses
                              │  + REST controllers
                              │  + topologies/
                              ▼
                     newspack-nodes  (runtime substrate)
                       Node / Message / Router / Topic /
                       Partition / Worker / Supervisor / REPL
```

Application nodes are plain `Newspack_Nodes\Node` subclasses (`extends \Newspack_Nodes\Node`) with their own `fill()` implementation. The runtime owns the wiring; the application ports the existing event-logger handler logic into Nodes.

## Plugin Load Order

WordPress loads plugins alphabetically. `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes`, so the runtime's `\Newspack_Nodes\Node` class is NOT available at this plugin's file-load time.

The main plugin file (`newspack-event-logger-nodes.php`) handles this with a deferred loader:

```php
$_loader = static function (): void {
    if ( ! class_exists( '\Newspack_Nodes\Node' ) ) {
        \Newspack_Nodes\Core::print_less_often(
            'newspack-event-logger-nodes: \Newspack_Nodes\Node missing — newspack-nodes inactive?'
        );
        return;
    }
    require_once ... // application classes
};
if ( class_exists( '\Newspack_Nodes\Node' ) ) {
    $_loader();
} else {
    add_action( 'plugins_loaded', $_loader, 11 );
}
```

Tests bypass this — they require the runtime explicitly in `tests/bootstrap.php`.

## Commands

```bash
# Deploy to dndocker container.
docker exec eve-pyrobase1-1 /services/pyrobase/setup/event-logger-nodes.sh

# Run tests inside the container (requires both plugins deployed).
docker exec -u bend eve-pyrobase1-1 bash -c 'cd /usr/src/newspack-event-logger-nodes/tests && phpunit'

# Lint.
npm run lint:php

# Build dashboards (when src/ is wired into wp-scripts).
npm run build
```

## Architecture Decisions

These are intentional. Don't "fix" them.

1. **9-namespace memcache schema.** Stats live in memcache only — never on disk. Per-partition prefix `evlog[:salt]:p{N}:`, then namespace: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`. Per-URL flame stats: TTL `max(3600, max_lifespan/24)`. All others: full `max_lifespan`. Caps prevent value-explosion against memcache's 1MB/value limit: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category is preserved before capping.

2. **Sums-not-means storage.** Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket / cross-partition merge is exact addition. Display layer computes means at read time. Do NOT regress to running-mean storage — that introduced an EMA-clamp bug we explicitly fixed.

3. **Fail-soft Stats_Store, fail-closed SSE slots.** Memcache failure asymmetry, deliberate. Stats path: every method returns `null` / `[]` / `false` when memcache is unreachable; never throws. Dashboards show "no data" instead of erroring. SSE slots fail-CLOSED at the caller — memcache down means new connections get HTTP 429 (preserves rate-limit invariant).

4. **Fail-closed SettingsSync polarity.** `enable_workers` MUST be strict `=== true`. Anything else (missing, false, 1, "1") means "not a hub, do not fan out." This polarity was a real silent-fan-out bug fixed in the legacy plugin's 2.4.42 — guarding via `?? false` or `!! ` is what *caused* the bug. Keep `! isset || true !== ...`.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt stored in `newspack_nodes_stats_salt` option. All existing keys orphan instantly (they expire via TTL); no scrubber needed. Schema bumps and emergency flushes share the mechanism.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff on dashboards. Implemented in both real and Fake memcached.

7. **JobIntake for >4KB payloads, firehose for ≤4KB.** Runtime jobs (small) go through firehose with `k:"job"`; JobRouter extracts and routes them. Large jobs (>4KB) go directly to `jobintake.log` via `JobIntake::queue()` which acquires the auto-lock around large writes. Using the wrong path silently loses jobs.

8. **Hub vs spoke topology.** Hub = `enable_workers === true` (strict). Hub pulls from configured spokes via StreamMerger SSE; rewrites `k:"job"` to `k:"remote_job"` via `newspack_nodes/aggregator_ingest_line` filter so spoke-job handlers don't double-execute on the hub. Hub-only handlers register via `newspack_nodes/remote_job_handlers`; spoke and hub handlers register via `newspack_nodes/job_handlers`.

9. **CRC32 + 31-bit-mask partition routing.** Inherited from `Partition::hash_to_partition()` in the runtime. Used by Topic, JobIntake-keyed mode, and the firehose URL-routing in LogManager. Same key → same partition across all producers. URL strings strip query (`explode('?', $key, 2)`) before hashing.

## Key Files

### Application Node subclasses (`includes/`)

| File | Purpose |
|------|---------|
| `class-request-builder.php` | Assembles requests from firehose entries via 3-bucket LRU cache. Bucket key = `floor(now / 200)`; rotation evicts oldest bucket; orphans emit as timed-out. Full retention 3 × 200s = 600s. |
| `class-flame-builder.php` | Aggregates completed-request events into flame-graph stats. Receives JSON-encoded completed requests from RequestBuilder. Currently aggregates count + sum_time per event-name; 5x1000 stats_cache rotation deferred. |
| `class-job-router.php` | Routes `k:"job"` and `k:"remote_job"` lines to handlers. Multi-input (firehose + jobintake). KEY-based source disambiguation (`firehose:job`, `jobintake:job`, etc.). Handler-name pattern + 10MB size validation. |
| `class-job-worker.php` | Executes registered job handlers. Per-job try/catch; between-jobs callback fires every N (default 50) for `gc_collect_cycles + wp_cache_flush` discipline. |
| `class-stream-merger.php` | Pulls remote firehoses via SSE. One cURL handle per remote driven from a shared multi-handle (registered with EventFramework). Per-handle WRITEFUNCTION feeds `process_sse_chunk`. Reconnect with 5s backoff. |
| `class-stats-aggregator.php` | Bumps the 9-namespace memcache schema on every completed request. Optional Stats_Store: when null, falls back to in-memory legacy mode for tests. |
| `class-stats-store.php` | 9-namespace memcache wrapper. Sums-not-means storage. Salt-rotation flush. Fail-soft semantics. |
| `class-server-registry.php` | Encrypted-credential storage for remote spokes. Sodium-secretbox; key derived from `wp_salt('auth')`. |
| `class-settings-sync.php` | Hub-side fan-out of WP option updates to remote spokes. Strict `=== true` enable_workers check. Re-entrancy guard via `$syncing`. JobIntake for >4KB option payloads. |
| `class-memcached-cache.php` | Cache_Interface implementation over PHP's bundled `\Memcached` / `\Memcache` extensions. Renamed from `Memcached` to avoid colliding with the bundled class name. Single-instance lifetime; idempotent connect. |

### REST controllers (`includes/rest/`)

| File | Purpose |
|------|---------|
| `class-performance-controller-base.php` | Shared base. Capability check, partition validation, fixed-window rate limit (60req/60s default; fail-open if memcache down), `not_found_error()`, `load_config()` via `newspack_nodes/config` filter. |
| `class-status-controller.php` | GET `/newspack-nodes/v1/status` — health/version probe. |
| `class-aggregator-controller.php` | `/newspack-nodes-aggregator/v1/{status,servers,health}` — hub-side aggregator endpoints. Stub bodies; real wiring lands when StreamMerger + ServerRegistry integrate. |
| `class-events-controller.php` | `/newspack-nodes/v1/events/{recent,stats}` — event-dashboards tree. |
| `class-gyroscope-controller.php` | `/newspack-nodes/v1/gyroscope/timeline?request_id=...` — request timeline snapshot. SSE infrastructure deferred. |
| `class-logger-controller.php` | `/newspack-nodes/v1/logger/{config,hooks}` — performance-logger settings tree. |
| `class-performance-controller.php` | `/newspack-nodes/v1/performance/{dashboard,timing}` — performance-dashboards tree. |
| `class-request-log-controller.php` | `/newspack-nodes/v1/request-log/{list,detail/{id}}` — performance-request-log tree. |

### Topologies (`topologies/`)

| File | Purpose |
|------|---------|
| `firehose-workers.php` | Per-partition graph: Tail(firehose.log) → Tee → RequestBuilder + JobRouter → JobWorker; Tail(jobintake.log) → JobRouter. |
| `aggregator.php` | Hub-side: StreamMerger → Topic(firehose.log). |

### React trees (`src/`)

86 files across 7 trees, all rewritten to call new namespaces (`newspack-nodes/v1/*` and `newspack-nodes-aggregator/v1/*`). Source-of-truth shared hooks live in `src/shared/`; copies in plugin-specific subtrees should be synced from the canonical source (sync mechanism TBD when build is wired up).

| Tree | Replaces |
|------|----------|
| `src/event-aggregator/` | `newspack-event-aggregator/src/` (legacy) |
| `src/event-dashboards/` | `newspack-event-dashboards/src/` |
| `src/performance-dashboards/` | `newspack-performance-dashboards/src/` |
| `src/performance-gyroscope/` | `newspack-performance-gyroscope/src/` |
| `src/performance-logger/` | `newspack-performance-logger/src/` |
| `src/performance-request-log/` | `newspack-performance-request-log/src/` |
| `src/shared/` | `newspack-event-logger-plugins/src/shared/` |

## Common Pitfalls

These are mistakes that have actually happened. Pay attention.

- **Fail-closed polarity in SettingsSync.** Use `! isset( $config['enable_workers'] ) || true !== $config['enable_workers']` for the skip check. Don't write `! ( $config['enable_workers'] ?? false )` — that's `1`/`"1"`-truthy, which silently turns spoke instances into hubs. This was a real bug fixed in legacy 2.4.42; do not regress.

- **PIPE_BUF (4096 bytes) for firehose writes.** LogManager's per-message writes MUST stay under 4KB so atomic-append holds. Anything that might exceed 4KB (job payloads with serialized option blobs, image-handler args, full SettingsSync sweeps with 50+ hooks) MUST go through `JobIntake::queue()`, which uses an auto-locked Partition with `allow_large_writes()` set.

- **`outputs` (array), not `output` (string).** Log reader registration uses `outputs` plural in the filter array — not the singular `output`. Easy typo; silent failure (log reader registers with bogus output, never writes).

- **Hub vs spoke routing.** `k:"job"` runs locally on every node via `event_logger_job_handlers` (`newspack_nodes/job_handlers` post-rename). `k:"remote_job"` runs only on the hub via the separate remote-handler filter. The hub-side rewrite happens in StreamMerger via `newspack_nodes/aggregator_ingest_line` — spokes that don't load the rewrite filter (correctly!) get raw `k:"job"` and dispatch locally. Don't assume all nodes are hubs.

- **`Memcached_Cache::DEFAULT_SERVERS` constant.** Don't hardcode `127.0.0.1:11211` in callers; use `Memcached_Cache::DEFAULT_SERVERS` so the default servers list is one place to change.

- **Cache_Interface for FakeMemcached test parity.** Both `Memcached_Cache` and the test `FakeMemcached` implement `Cache_Interface`. Tests inject a fake via `PerformanceControllerBase::set_cache()`. New cache callsites should accept `Cache_Interface`, not `Memcached_Cache` directly.

- **Salt rotation requires worker restart.** `Stats_Store::flush_all()` rotates the salt option, which orphans the old keys. Long-running workers cache `prefix` at construction. They'll keep writing to keys with the OLD salt until they respawn; readers see "no data" for `max_lifespan` after the rotation. Trigger a worker restart after `flush_all()` if you need immediate effect.

- **Stats path is fail-soft, SSE slot path is fail-closed.** Don't unify them. Dashboards must show "no data" on memcache failure, not error. SSE connections must reject (HTTP 429) on memcache failure, not fall through silently — the slot pool IS the rate limit.

- **Application classes aren't registered in `CommandInterpreter::$class_map`.** Topology files instantiate application Node subclasses directly with `new` rather than via the shell `make_node` path. CI is still passed for parity with the runtime topology contract.

## References

- **Architecture**: `ARCHITECTURE.md` (application design, topologies, hub/spoke flow, memcache schema).
- **API**: `API.md` (REST endpoint reference).
- **Migration**: `MIGRATION.md` (cutover from `newspack-event-logger-plugins`, namespace rewrites).
- **Runtime**: `../newspack-nodes/` — substrate this plugin depends on.
- **Spec**: `services/pyrobase/sources/.specs/2026-05-06-newspack-nodes-design.md` in dndocker.
