# AGENTS.md — Newspack Event Logger Nodes

WordPress plugin: the application layer of the new event-logger. Replaces the legacy 10-plugin `newspack-event-logger-plugins` monorepo with two plugins — this one (application) and `newspack-nodes` (runtime substrate). High-throughput request-lifecycle logging, real-time SSE streaming, flame-graph generation, hub/spoke aggregation — expressed as Nodes.

This plugin owns: `LogManager`, `RequestBuilder`, `FlameBuilder`, `JobRouter`, `JobWorker`, `JobIntake`, `StreamMerger`, `StatsAggregator`, `Stats_Store`, `ServerRegistry`, `SettingsSync`, `RemoteManager`, `Memcached_Cache`, REST controllers, React dashboards, topology files. Depends on `newspack-nodes` for everything substrate-level.

## Plugin Load Order

WordPress loads plugins alphabetically. `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes` (`-event-` < `-nodes`), so the runtime's `\Newspack_Nodes\Node` class is NOT available at this plugin's file-load time.

Workaround: `newspack-event-logger-nodes.php` defers the `require_once` block via a closure run on `plugins_loaded` priority 11 (when both plugins are loaded). Tests bypass this — they require the runtime explicitly in `tests/bootstrap.php`.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist`):
- `snake_case` for functions and variables
- Yoda conditions
- `[]` arrays, arrow functions, spread operator allowed
- Tab indentation, spaces inside parentheses
- PHP 8.0+ typed properties; constructor property promotion where it shortens
- Conventional commits

## Build / Test

```bash
# Deploy.
docker exec eve-pyrobase1-1 /services/pyrobase/setup/newspack-event-logger-nodes.sh

# Tests (require both plugins deployed).
docker exec -u bend eve-pyrobase1-1 \
    bash -c 'cd /usr/src/newspack-event-logger-nodes/tests && phpunit'

# Coverage.
docker exec -u bend eve-pyrobase1-1 \
    /usr/src/newspack-event-logger-nodes/tests/run-coverage.sh
# → /volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/

# Lint.
npm run lint:php

# Build dashboards.
npm run build
```

## Architecture Decisions

These are intentional. Don't "fix" them.

1. **9-namespace memcache schema.** Stats live in memcache only — never on disk. Per-partition prefix `evlog[:salt]:p{N}:`, then namespace: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`. Per-URL flame stats: TTL `max(3600, max_lifespan/24)`. All others: full `max_lifespan`. Caps prevent value-explosion against memcache's 1MB/value limit: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category is preserved before capping.

2. **Sums-not-means storage.** Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket / cross-partition merge is exact addition. Display layer computes means at read time. Do NOT regress to running-mean storage — that introduced an EMA-clamp bug we explicitly fixed.

3. **Fail-soft Stats_Store, fail-closed SSE slots.** Memcache failure asymmetry, deliberate. Stats path: every method returns `null` / `[]` / `false` when memcache is unreachable; never throws. Dashboards show "no data" instead of erroring. SSE slots fail-CLOSED at the caller — memcache down means new connections get HTTP 429 (preserves rate-limit invariant).

4. **Fail-closed SettingsSync polarity.** `enable_workers` MUST be strict `=== true`. Anything else (missing, false, 1, "1") means "not a hub, do not fan out." This polarity was a real silent-fan-out bug fixed in legacy 2.4.42 — guarding via `?? false` or `!! ` is what *caused* the bug. Keep `! isset || true !== ...`.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt stored in `newspack_nodes_stats_salt`. All existing keys orphan instantly (TTL handles cleanup). Schema bumps and emergency flushes share the mechanism. Long-running workers cache `prefix` at construction; restart workers after `flush_all()` for immediate effect.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff on dashboards. Implemented in both real and Fake memcached.

7. **JobIntake for >4KB payloads, firehose for ≤4KB.** Runtime jobs (small) go through firehose with `k:"job"`; JobRouter extracts and routes them. Large jobs (>4KB) go directly to `jobintake.log` via `JobIntake::queue()` which acquires the auto-lock around large writes. Using the wrong path silently loses jobs (LogManager truncates >4KB to `{"truncated": true}`).

8. **Hub vs spoke topology.** Hub = `enable_workers === true` (strict). Hub pulls from configured spokes via StreamMerger SSE; rewrites `k:"job"` to `k:"remote_job"` via `newspack_nodes/aggregator_ingest_line` filter so spoke-job handlers don't double-execute on the hub. Hub-only handlers register via `newspack_nodes/remote_job_handlers`; spoke + hub handlers register via `newspack_nodes/job_handlers`.

9. **CRC32 + 31-bit-mask partition routing.** Inherited from `Partition::hash_to_partition()` in the runtime. Used by Topic, JobIntake-keyed mode, and the URL-routing in LogManager. Same key → same partition across all producers.

## Layout

| Path | What |
|------|------|
| `newspack-event-logger-nodes.php` | Plugin entry; deferred loader; CommandInterpreter class registry; topology filter |
| `newspack-event-logger-nodes-config.php` | Application config (log filters, hooks to instrument, hub/spoke settings) |
| `includes/class-config.php` | Application config loader (substrate keys live in `newspack-nodes`) |
| `includes/class-log-manager.php` | Per-request firehose writer; redacts URL secrets; refuses root |
| `includes/class-{request,flame}-builder.php` | Application Node subclasses for request assembly + flame aggregation |
| `includes/class-{job-router,job-worker,job-intake}.php` | Job pipeline (firehose + jobintake → handlers) |
| `includes/class-stream-merger.php` | StreamMerger Node subclass — pulls remote firehoses via SSE (cURL multi) |
| `includes/class-stats-{aggregator,store}.php` | 9-namespace memcache schema producer + reader |
| `includes/class-{server-registry,settings-sync,remote-manager}.php` | Hub-side fanout + remote-spoke management |
| `includes/class-{memcached-cache,inflight-tracker,partition-reader,lru-cache,hook-categorizer,auto-tune-handlers,health-check-extensions}.php` | Helpers |
| `includes/app/class-core.php` | WP-lifecycle hook instrumentation |
| `includes/admin/class-admin.php` | Application settings UI |
| `includes/cli/class-reqgrep-command.php` | `wp nodes reqgrep` — application-aware firehose filter |
| `includes/rest/` | REST controllers (performance, gyroscope, request-log, errors, etc.) |
| `topologies/` | Per-partition node graphs (firehose-workers, request-workers, job-workers, aggregator) |
| `src/` | React dashboard trees (event-aggregator, event-dashboards, performance-*, performance-gyroscope, performance-request-log, shared) |
| `tests/` | PHPUnit suite (unit + integration + Rest) — 707 tests at last count |

## Common Pitfalls

These are mistakes that have actually happened. Pay attention.

- **Fail-closed polarity in SettingsSync.** Use `! isset( $config['enable_workers'] ) || true !== $config['enable_workers']`. Don't write `! ( $config['enable_workers'] ?? false )` — that's `1`/`"1"`-truthy, which silently turns spokes into hubs. Real bug fixed in legacy 2.4.42.
- **PIPE_BUF (4096 bytes) for firehose writes.** LogManager truncates >4KB to `{"truncated": true}`. Anything that might exceed (job payloads with serialized option blobs, image-handler args, full SettingsSync sweeps) MUST use `JobIntake::queue()`.
- **`outputs` (plural), not `output`.** Log reader registration uses `outputs` array. Singular is silent failure.
- **Hub vs spoke routing.** `k:"job"` = local on every node; `k:"remote_job"` = hub-only. Hub-side rewrite happens in StreamMerger via `newspack_nodes/aggregator_ingest_line`. Spokes (correctly!) don't load that filter.
- **`Memcached_Cache::DEFAULT_SERVERS` constant.** Don't hardcode `127.0.0.1:11211`; use the constant.
- **`Cache_Interface` for FakeMemcached test parity.** Both `Memcached_Cache` and the test `FakeMemcached` implement `Cache_Interface`. New cache call-sites should accept `Cache_Interface`, not `Memcached_Cache`.
- **Salt rotation requires worker restart.** `Stats_Store::flush_all()` orphans keys, but workers cache `prefix` at construction. Restart workers after rotation for immediate effect.
- **Stats fail-soft, SSE slots fail-closed.** Don't unify them. Dashboards must show "no data" on memcache failure. SSE connections must reject (HTTP 429) — the slot pool IS the rate limit.
- **Application classes register with CommandInterpreter.** `newspack-event-logger-nodes.php` calls `\Newspack_Nodes\CommandInterpreter::register_class('FlameBuilder', ...)` etc. for FlameBuilder, JobRouter, JobWorker, RequestBuilder, StreamMerger. New application Node subclasses must register too.
- **Type flags**: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE gate on TM_STRUCT.

## Local Skills

`.claude/skills/` has application-specific skills:
- `event-logger-nodes-workflow` — implementation workflow (handlers, REST, dashboards, topologies)
- `event-logger-nodes-debugging` — dashboards, memcache schema, hub/spoke routing, SSE
- `event-logger-nodes-review` — application contract checklist

## References

- **Architecture**: `ARCHITECTURE.md` (application design, topologies, hub/spoke flow, memcache schema)
- **API**: `API.md` (REST endpoint reference)
- **Migration**: `MIGRATION.md` (cutover from `newspack-event-logger-plugins`)
- **Runtime**: `../newspack-nodes/` — substrate this plugin depends on
