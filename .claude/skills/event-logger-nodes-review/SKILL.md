---
name: event-logger-nodes-review
description: Code review checklist for newspack-event-logger-nodes (the application). Use whenever reviewing a diff that touches anything under newspack-event-logger-nodes/ — application Node subclasses, REST controllers, dashboards, topologies, job handlers, memcache schema, hub/spoke routing.
argument-hint: "[file or class]"
---

# Event Logger Nodes Review Checklist

Application-specific review pass. For substrate concerns (PIPE_BUF discipline, lazy init, FROM stamping, TM_STRUCT typing), see the `nodes-review` skill in newspack-nodes — those gates apply transitively to anything in this plugin too. This skill adds the application-specific gates.

## When to Use

After any diff touching files under `newspack-event-logger-nodes/`. Run BEFORE pushing or merging.

## Gates (high-impact first)

### 1. Remote-activity gate

Cross-site activity (`remote_manager` job queueing on the push side, StreamMerger spawn on the pull side) is gated by a single config flag: `enable_aggregator`. Strict polarity — read with `true === ( Config::load_config()['enable_aggregator'] ?? false )`. Default OFF; hubs opt in explicitly. Fresh installs are spokes / standalone.

If a diff introduces a separate hub flag (`enable_workers === true` checks, a `Hub::is_active()` helper) or duplicates the gate in a new call site, push back — the whole point of the consolidation is one switch, no polarity drift. `enable_workers` is purely the `request-workers` topology gate now (FlameBuilder spawn); it does NOT participate in remote-activity decisions.

### 2. Stats fail-soft, SSE fail-closed

Two memcache callsites with deliberately opposite behavior:

- **Stats_Store** (and anything reading stats for dashboards): every method returns `null` / `[]` / `false` on memcache failure. Never throw. Dashboards display "no data" rather than erroring.
- **SSE slot pool**: fail CLOSED. Memcache down → reject new connections with HTTP 429. The slot pool IS the rate limit; falling through silently breaks the rate-limit invariant.

A diff that unifies these into one error-handling style is wrong. If you see new code that throws from the stats path, or that swallows errors in the slot path, those are inverted.

### 3. JobIntake for >4KB, firehose for ≤4KB

LogManager writes to the firehose with a 4KB cap (PIPE_BUF). Anything larger silently turns into `{"truncated": true}` — destroys the job payload.

The right path for large jobs is `JobIntake::queue($handler, $payload)`. JobIntake auto-locks via Lock and uses an `allow_large_writes()` Partition (10MB cap).

A diff that introduces a new producer of potentially-large jobs and routes them through LogManager is silently broken. Reviewer must check: does `wp_json_encode($payload)` ever exceed 4KB? If yes, must use JobIntake.

### 4. `outputs` (plural) for log reader registration

The filter-based registration uses `outputs` (array), not `output` (string). Singular is a typo that registers a log reader with bogus output and never writes — a silent failure mode.

Anywhere you see `'output' => '...'` in a `newspack_nodes/log_readers` filter result, that's wrong.

### 5. Cache_Interface, not Memcached_Cache

Tests inject a FakeMemcached via `PerformanceControllerBase::set_cache()`. New code that accepts `Memcached_Cache` directly (rather than the `Cache_Interface` it implements) breaks injection.

Same rule for any new component that holds a memcache reference: type-hint the interface, not the concrete class.

### 6. Memcached_Cache::DEFAULT_SERVERS

Don't hardcode `127.0.0.1:11211` in callers. Use the constant. Single source of truth for the default server list.

### 7. Salt rotation behavior

`Stats_Store::flush_all()` rotates the 8-char salt stored in `newspack_event_logger_nodes_stats_salt`. Existing keys orphan instantly (TTL handles cleanup; no scrubber).

Long-running workers cache the prefix at construction, so they keep writing to the OLD salt until they respawn. A diff that uses `flush_all()` and expects immediate effect is wrong — the call sites need to either tolerate the stale-write window or trigger a worker restart.

### 8. Memcache value caps

Per-namespace caps prevent value-explosion against the 1MB/value memcache limit:

- `MAX_DIM_VALUES = 20`
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`

Overflow rolls into a synthetic "Other" bucket. The `total` pseudo-category is preserved before capping (so totals are always accurate even after dimension keys get capped).

A diff that raises a cap without thinking about the value-size implication, or removes the "Other" rollup, is risky. Memcache will silently truncate or reject values >1MB.

### 9. Sums-not-means storage

Leaderboard buckets store raw sums: `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket and cross-partition merge is exact addition. Display layer computes means at read time.

A diff that introduces running-mean storage (the EMA we explicitly fixed) regresses to a known buggy state. Aggregator code MUST sum, not average.

### 10. Application Node subclasses register with CommandInterpreter

The plugin's main file calls `\Newspack_Nodes\CommandInterpreter::register_class('FlameBuilder', \Newspack_Event_Logger_Nodes\FlameBuilder::class)` etc. for FlameBuilder, JobRouter, JobWorker, RequestBuilder, StreamMerger.

A new application node subclass MUST register itself the same way; otherwise topology PHP can't construct it via `$interpreter->make_node('Foo', 'foo')`.

### 11. No `class_exists()` guards for in-plugin classes

The deferred-loader pattern (require_once chain on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them. `class_exists()` guards around an in-plugin class are dead branches; they were deliberately removed in a cleanup pass. A diff that adds one back as "defensive" is dead weight — push back.

Optional-dependency guards stay: `class_exists( 'Memcached' )` (PHP extension), `class_exists( 'WP_REST_Controller' )` (test bootstrap context), `class_exists( 'WP_CLI' )` (CLI-only paths). The rule is "is this class guaranteed loaded by our own bootstrap?" If yes, drop the guard.

### 12. Stream-injection + iteration-cap pattern for testability

CLI commands and other blocking-work classes (`ReqgrepCommand::process_stdin`, `follow_mode`, `Cli_Stdin_Reader::drain_fh`) accept the I/O resource and an iteration cap as parameters rather than calling `STDIN` / `microtime` / `sleep` inline. Production uses defaults; tests pass `php://memory` streams and small caps to exercise loops deterministically.

A diff that introduces a new blocking command and bakes the streams in (no injection seam, no iteration cap) is hard to test — flag it. The cost is low: one extra ctor / method parameter.

### 13. Type flags

Inherited from substrate: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE must gate on `TM_STRUCT`. Mixing is a known-buggy pattern; don't regress.

LogManager, RequestBuilder (`emit_request` / `emit_error`), FlameBuilder, JobIntake all use TM_STRUCT. StreamMerger uses TM_BYTESTREAM for raw remote SSE chunks (string VALUE).

## REST controller specifics

- Capability gate: `manage_options` by default (worker requests excluded via `NEWSPACK_NODES_WORKER_TYPE` env tag).
- Rate limit: 600 req/60s per controller; fail-open if memcache is down (so dashboard fanout doesn't break when memcache hiccups).
- Error responses: `WP_Error`, not raw arrays.
- Output escaping: `esc_html()` / `esc_attr()` / `esc_url()` for any string going into HTML; `wp_json_encode()` (not raw `json_encode`) for arrays sent over the wire.

## React / dashboard nits

- `@wordpress/element` for React, `@wordpress/api-fetch` for REST.
- Bundle build is wp-scripts based; `npm run build` produces `build/` artifacts.
- Shared hooks in `src/shared/`. If a tree imports from `src/shared/`, fine; if it imports from another tree, that's a layering smell.
- `restUrl` localized as bare `/wp-json/`, not pre-namespaced. Components add the namespace per call.

## Tests

- Unit tests under `tests/unit/`, integration under `tests/integration/`, REST controllers under `tests/unit/Rest/`.
- Coverage report under `/volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/` after running `tests/run-coverage.sh`. New code should add tests so coverage doesn't regress.
- Test fixtures use `Message::TM_STRUCT` for array-VALUE messages (was `TM_BYTESTREAM` pre-rename; if you see TM_BYTESTREAM in a fixture with array VALUE, that's a stale test that needs updating).
- New REST controllers should have a happy-path test, an unauthorized-request test, a rate-limit test, and a memcache-failure test.

## Common review nits that aren't bugs

- The 9-namespace memcache schema looks redundant at first glance (why not collapse?). It's deliberate — different access patterns benefit from different keys, and the `get_multi` batching across all namespaces is essential for dashboard latency.
- The `Memcached_Cache` rename (from `Memcached`) avoids colliding with PHP's bundled `\Memcached` class — don't ask why it has the awkward name.
- Application classes register with the substrate's CommandInterpreter — that's fine and intentional, even though they're not technically substrate.

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-debugging` — runtime debugging
- `nodes-review` (in newspack-nodes) — substrate gates that apply transitively
