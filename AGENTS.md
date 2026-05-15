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
# Tests (require newspack-nodes to also be installed/activated). Always
# pass `--enforce-time-limit` so a hung test (readline without a TTY,
# infinite drain loop, etc.) aborts at the per-test budget instead of
# stalling the whole suite. Tests that legitimately sleep through
# production code mark their class `#[Medium]` to raise the limit.
cd tests && phpunit --enforce-time-limit

# Coverage HTML/Clover.
tests/run-coverage.sh

# Lint.
npm run lint:php
npm run lint:js

# Build dashboards.
npm run build
```

The plugin is shipped as a standard WordPress plugin; how it's deployed (containers, bind mounts, rsync, etc.) is environment-specific and lives outside this repo.

## Versioning & Release

The version appears in three places: the `Version:` header in `newspack-event-logger-nodes.php`, the `NEWSPACK_EVENT_LOGGER_NODES_VERSION` PHP constant in the same file, and the `"version"` field in `package.json`. Do NOT edit these by hand — `tools/bump-event-logger-nodes-version.sh` (in `dndocker/`) rewrites all three atomically and refuses to bump to a version that's already current.

```bash
# Bump version (from dndocker root).
dndocker/tools/bump-event-logger-nodes-version.sh <version>

# Release workflow:
# 1. Update CHANGELOG.md with new version and changes (use Keep-a-Changelog format).
# 2. Bump version across plugin header + constant + package.json:
dndocker/tools/bump-event-logger-nodes-version.sh <version>
# 3. Commit the changelog entry + version bump together (e.g. `chore: release v<version>`).
# 4. Build the release zip:
./build-release.sh           # outputs to release/newspack-event-logger-nodes.zip
# 5. Tag, push, and create GitHub release with the zip:
git tag v<version>
git push origin main --tags
gh release create v<version> release/newspack-event-logger-nodes.zip --title "v<version>" --notes "changelog here"
```

`build-release.sh` runs `composer install --no-dev --optimize-autoloader`, then `npm run build` (the React dashboards must ship pre-built), then rsyncs the plugin into `release/newspack-event-logger-nodes/` minus development artifacts (`src/`, `tests/`, `node_modules/`, `composer.{json,lock}`, `package*.json`, `phpcs.xml.dist`, `build-release.sh`, AppleDouble sidecars, etc.) and zips it. The zip contains the plugin directory at root so `wp plugin install --force --activate <url>.zip` works as-is.

**Note on coupling**: this plugin's version is not tied to `newspack-nodes`'s — they release independently. If a release depends on a specific runtime version, call it out in the CHANGELOG entry; consider bumping the `newspack-nodes` requirement in the plugin header (`Requires Plugins:` once we use it).

**Why three locations?** Plugin header is what WordPress shows in the admin; the PHP constant is what the runtime + dashboards' cache-busting read; `package.json` is what npm tooling reads. The bump script is the single source of truth — drift between any two of them is a real bug we've shipped before.

## Architecture Decisions

These are intentional. Don't "fix" them.

1. **9-namespace memcache schema.** Stats live in memcache only — never on disk. Per-partition prefix `evlog[:salt]:p{N}:`, then namespace: `hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`, `url_dim`, `categories`, `url_cat`. Per-URL flame stats: TTL `max(3600, max_lifespan/24)`. All others: full `max_lifespan`. Caps prevent value-explosion against memcache's 1MB/value limit: `MAX_DIM_VALUES=20`, `MAX_URL_DIM_VALUES=10`, `MAX_CAT_VALUES=50`. Overflow rolls into a synthetic "Other" bucket; the `total` pseudo-category is preserved before capping.

2. **Sums-not-means storage.** Leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket / cross-partition merge is exact addition. Display layer computes means at read time. Do NOT regress to running-mean storage — that introduced an EMA-clamp bug we explicitly fixed.

3. **Fail-soft Stats_Store, fail-closed SSE slots.** Memcache failure asymmetry, deliberate. Stats path: every method returns `null` / `[]` / `false` when memcache is unreachable; never throws. Dashboards show "no data" instead of erroring. SSE slots fail-CLOSED at the caller — memcache down means new connections get HTTP 429 (preserves rate-limit invariant).

4. **Fail-closed SettingsSync polarity.** `enable_workers` MUST be strict `=== true`. Anything else (missing, false, 1, "1") means "not a hub, do not fan out." This polarity was a real silent-fan-out bug fixed in legacy 2.4.42 — guarding via `?? false` or `!! ` is what *caused* the bug. Keep `! isset || true !== ...`.

5. **Salt-rotation schema migration.** `Stats_Store::flush_all()` rotates an 8-char salt stored in `newspack_nodes_stats_salt`. All existing keys orphan instantly (TTL handles cleanup). Schema bumps and emergency flushes share the mechanism. Long-running workers cache `prefix` at construction; restart workers after `flush_all()` for immediate effect.

6. **`get_multi` batching is essential.** Reader paths multi-get across all retention buckets per partition in one round-trip. Per-key `get` is a latency cliff on dashboards. Implemented in both real and Fake memcached.

7. **JobIntake for >4KB payloads, firehose for ≤4KB.** Runtime jobs (small) go through firehose with `k:"job"`; JobRouter extracts and routes them. Large jobs (>4KB) go directly to `jobintake.log` via `JobIntake::queue()` which acquires the auto-lock around large writes. Using the wrong path silently loses jobs (LogManager truncates >4KB to `{"truncated": true}`).

8. **Hub vs spoke topology.** Hub = `enable_aggregator` toggled on (the single operator switch for cross-site activity). Every node runs its own JobWorker that dispatches `type='job'` entries against `newspack_nodes/job_handlers`. The hub additionally runs a StreamMerger that pulls spoke firehoses and rewrites incoming `k:"job"` lines to `k:"remote_job"` via the `newspack_nodes/aggregator_ingest_line` filter; the hub's JobWorker dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent registrations — a job type can register under either or both depending on where it wants to run (locally on every node, only on the hub after aggregation, or in both places when local + aggregated work need different handling).

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
| `tests/` | PHPUnit suite (unit + integration + Rest) |

## Common Pitfalls

These are mistakes that have actually happened. Pay attention.

- **Fail-closed polarity in SettingsSync.** Use `! isset( $config['enable_workers'] ) || true !== $config['enable_workers']`. Don't write `! ( $config['enable_workers'] ?? false )` — that's `1`/`"1"`-truthy, which silently turns spokes into hubs. Real bug fixed in legacy 2.4.42.
- **PIPE_BUF (4096 bytes) for firehose writes.** LogManager truncates >4KB to `{"truncated": true}`. Anything that might exceed (job payloads with serialized option blobs, image-handler args, full SettingsSync sweeps) MUST use `JobIntake::queue()`.
- **`outputs` (plural), not `output`.** Log reader registration uses `outputs` array. Singular is silent failure.
- **Hub vs spoke routing.** Nodes only ever write `k:"job"`. Every node's JobWorker dispatches its own `job` entries against `newspack_nodes/job_handlers`. On the hub, StreamMerger's `newspack_nodes/aggregator_ingest_line` filter rewrites incoming spoke lines to `k:"remote_job"`; the hub's JobWorker dispatches those against `newspack_nodes/remote_job_handlers`. The two filters are independent — a job type registers under whichever side(s) should handle it (local on every node, hub-aggregated, or both).
- **`Memcached_Cache::DEFAULT_SERVERS` constant.** Don't hardcode `127.0.0.1:11211`; use the constant.
- **`Cache_Interface` for FakeMemcached test parity.** Both `Memcached_Cache` and the test `FakeMemcached` implement `Cache_Interface`. New cache call-sites should accept `Cache_Interface`, not `Memcached_Cache`.
- **Salt rotation requires worker restart.** `Stats_Store::flush_all()` orphans keys, but workers cache `prefix` at construction. Restart workers after rotation for immediate effect.
- **Stats fail-soft, SSE slots fail-closed.** Don't unify them. Dashboards must show "no data" on memcache failure. SSE connections must reject (HTTP 429) — the slot pool IS the rate limit.
- **Application classes register with CommandInterpreter.** `newspack-event-logger-nodes.php` calls `\Newspack_Nodes\CommandInterpreter::register_class('FlameBuilder', ...)` etc. for FlameBuilder, JobRouter, JobWorker, RequestBuilder, StreamMerger. New application Node subclasses must register too.
- **Type flags**: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE gate on TM_STRUCT.
- **Don't restore `class_exists()` guards for in-plugin classes.** The deferred-loader pattern (require_once chain run on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them, so defensive `class_exists()` checks for classes that ship in the same plugin are dead branches. Removing them was a deliberate cleanup; re-adding them is dead weight.
- **`wp nodes cli {reader}` requires the worker to exist.** As of substrate update, `attach_to_worker` checks for `{base}/locks/{reader}.lock.d/`. Typo'd reader ids fail fast with "no worker '<id>' (run `wp nodes ls` to list active workers)" — no silent ghost-IPC creation. If you script `wp nodes cli`, surface that error.
- **Substrate now passes all message types through Partition/Topic.** Previously TM_REQUEST/TM_ERROR/TM_EOF were silently dropped — that broke pivoted-mode error responses (TM_COMMAND|TM_ERROR from a throwing verb on the worker), `request_node` cli verbs, and the stdin-close drain. Application data partitions (firehose.log, jobintake.log, requests.log, flames.log, jobs.log) only emit TM_BYTESTREAM / TM_STRUCT in practice, so the broader contract is a no-op for the application's write path — but you can now use Partitions as ad-hoc message transports if needed.
- **Piped `wp nodes cli` sessions drain cleanly.** Substrate now does a TM_EOF round-trip on stdin close (cli emits TM_EOF, worker bounces, cli exits after the echo). Scripted reqgrep/cli pipelines no longer need a trailing `sleep N`.

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
