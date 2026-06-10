---
name: event-logger-nodes-review
description: Code review checklist for newspack-event-logger-nodes (the application). Use whenever reviewing a diff that touches anything under newspack-event-logger-nodes/ — application Node subclasses, service CI verbs, dashboards, topologies, job handlers, memcache schema, hub/spoke routing.
argument-hint: "[file or class]"
---

# Event Logger Nodes Review Checklist

Application-specific review pass. For substrate concerns (PIPE_BUF discipline, lazy init, FROM stamping, TM_STRUCT typing), see the `nodes-review` skill in newspack-nodes — those gates apply transitively to anything in this plugin too. This skill adds the application-specific gates.

## When to Use

After any diff touching files under `newspack-event-logger-nodes/`. Run BEFORE pushing or merging.

## Gates (high-impact first)

### 1. Remote-activity gate

`enable_aggregator` is the single operator switch for the admin-visible side of hub-mode. Declared as a typed `bool` `Field` in `Settings_Schema` (the v0.13.0 declarative schema), persisted by `register_setting` as `0`/`1`, default OFF; hubs opt in explicitly. Fresh installs are spokes / standalone. Read with a truthy check (`! empty( $cfg['enable_aggregator'] )` — what the admin-menu gate uses), NOT a coerced-string `=== true` match.

Push-side fanout (`Settings_Sync::maybe_queue_static_sync`, `Auto_Tuner_Node::persist`) is INTENTIONALLY ungated — both always queue a `remote_manager` job when a synced option changes. Without an aggregator topology running and remotes registered, the queued job has no consumer and silently drops; missing consumers ARE the structural gate. A diff that adds a polarity check around the push-side fanout regresses to the legacy `enable_workers` design — push back. Pull-side activation (`Stream_Merger_Node`) and admin submenu visibility ARE gated on `enable_aggregator`.

If a diff introduces a separate hub flag (`enable_workers === true` checks, a `Hub::is_active()` helper) or duplicates the gate in a new call site, push back — the whole point of the v0.5.0 consolidation is one switch, no polarity drift. The legacy `enable_workers` toggle was retired.

### 2. Stats fail-soft, SSE fail-closed

Two memcache uses with deliberately opposite behavior:

- **Stats_Store** (the ELN-side callsite, and anything reading stats for dashboards): every method returns `null` / `[]` / `false` on memcache failure. Never throw. Dashboards display "no data" rather than erroring.
- **SSE slot pool**: fail CLOSED. Memcache down → reject new connections with HTTP 429. The slot pool IS the rate limit; falling through silently breaks the rate-limit invariant. Note the slot pool (`Sse_Slot_Pool`, the 429 callsite) is a SUBSTRATE component (`newspack-nodes/includes/class-sse-slot-pool.php`), not an ELN class — the fail-closed behavior is enforced there; the ELN-side gate is just to not break the invariant.

A diff that unifies these into one error-handling style is wrong. If you see new code that throws from the stats path, or that swallows errors in the slot path, those are inverted.

### 3. JobIntake for >4KB, firehose for ≤4KB

LogManager writes to the firehose under a `MAX_DATA_SIZE = 3840` byte cap (kept under PIPE_BUF's 4096). Anything larger is silently clipped: the category gets ` (truncated)` appended and the data is replaced with a single 1000-char `m` string (`['m' => substr(json,0,1000).'...']`, plus an `error_log`) — destroys the job payload.

The right path for large jobs is `JobIntake::queue($handler, $payload)`. JobIntake auto-locks via Lock and uses an `allow_large_writes()` Partition (10MB cap).

A diff that introduces a new producer of potentially-large jobs and routes them through LogManager is silently broken. Reviewer must check: does `wp_json_encode($payload)` ever exceed 4KB? If yes, must use JobIntake.

### 4. `outputs` (plural) for log reader registration

The filter-based registration uses `outputs` (array), not `output` (string). Singular is a typo that registers a log reader with bogus output and never writes — a silent failure mode.

Anywhere you see `'output' => '...'` in a `newspack_nodes/log_readers` filter result, that's wrong.

### 5. Read the shared `Core::$memd`, don't inject a cache

Caching is the single shared `\Newspack_Nodes\Core::$memd` handle (a raw `\Memcached`). There is no `Cache_Interface` / `Memcached_Cache` to type-hint or inject — those were deleted. New code that adds a cache constructor param, type-hints a cache interface, or revives a per-class cache field is fighting the gut; consumers (`Stats_Store`, the service CIs) read `Core::$memd` directly with null-safe `Core::$memd?->...`. Tests set `Core::$memd` to the substrate's in-memory `\Memcached` double (`../newspack-nodes/tests/Helpers/InMemoryMemcached.php`, required via `tests/bootstrap.php` — there is no local `tests/Helpers/`) in setUp — no `set_cache()` injection seam anymore.

### 6. One connection — don't build your own or hardcode servers

`Core::$memd` is built once at boot by the substrate's `\Newspack_Nodes\Bootstrap::init_memcached()` from the `memcache_servers` config (defaults to `127.0.0.1:11211`); ELN production code never assigns `Core::$memd` itself. A diff that hardcodes a server list or news up a second `\Memcached` connection is wrong — read the shared handle. (The old `Memcached_Cache::DEFAULT_SERVERS` constant is gone with the class.)

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

The plugin's main file calls `\Newspack_Nodes\Command_Interpreter_Node::register_namespace('Newspack_Event_Logger_Nodes\\')` (+ the `App\` sub-namespace). `make_node Flame_Builder` then resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix; there's no per-class registration to keep in sync.

A new application node subclass picks up the existing namespace registration for free as long as it lives under `Newspack_Event_Logger_Nodes\\` (or `App\\`) and a `composer dump-autoload -o` runs; otherwise a `.tsl` `make_node Foo foo` can't resolve the class.

### 11. No `class_exists()` guards for in-plugin classes

The deferred-loader pattern (require_once chain on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them. `class_exists()` guards around an in-plugin class are dead branches; they were deliberately removed in a cleanup pass. A diff that adds one back as "defensive" is dead weight — push back.

Optional-dependency guards stay: `class_exists( 'Memcached' )` (PHP extension), `class_exists( 'WP_REST_Controller' )` (test bootstrap context), `class_exists( 'WP_CLI' )` (CLI-only paths). The rule is "is this class guaranteed loaded by our own bootstrap?" If yes, drop the guard.

### 12. Stream-injection + iteration-cap pattern for testability

CLI commands and other blocking-work classes use seams rather than calling `STDIN` / `microtime` / `sleep` inline — but the seam differs by method, not a uniform "resource + cap" pair: `Reqgrep_Command::process_stdin( $stream = null )` takes only the I/O resource (defaults to `STDIN`, loops `fgets` to EOF, no cap); `Reqgrep_Command::follow_mode( int $max_iterations = \PHP_INT_MAX )` takes only an iteration cap; the substrate's `CLI_Stdin_Reader_Node::fire` is its own seam. Production uses defaults; tests pass `php://memory` streams and small caps to exercise loops deterministically.

A diff that introduces a new blocking command and bakes the streams in (no injection seam, no iteration cap) is hard to test — flag it. The cost is low: one extra ctor / method parameter.

### 13. Type flags

Inherited from substrate: array VALUE → `TM_STRUCT`. String VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE must gate on `TM_STRUCT`. Mixing is a known-buggy pattern; don't regress.

LogManager, RequestBuilder (`emit_request` / `emit_error`), FlameBuilder, JobIntake all use TM_STRUCT. `Stream_Merger_Node`'s own messages are TM_STRUCT; the raw-remote-SSE-chunk TM_BYTESTREAM path is in `Remote_Source_Node`, which forwards the remote envelope's TYPE (string VALUE).

### 14. Flame JSON decode-depth invariant (v0.13.1)

`Flame_Builder_Node::FLAME_JSON_DEPTH` (= `2 * MAX_STACK_DEPTH + 8`) MUST be the `json_decode` depth for packed flame lines on BOTH sides — write (`Flame_Builder_Node`) and read (`Performance_CI_Node::find_flame_for_rid`). A diff that hardcodes a literal `64` (or any other depth) for flame-line decode silently drops deep flames — that was a real shipped bug. Use the constant.

### 16. Numeric server-id key-preservation (v0.11.0)

`Server_Registry::get_all()` MUST union with the key-preserving `+` operator (`$option + $config_defaults`), NEVER `array_merge()` — `array_merge` renumbers integer keys and destroys numeric server ids. `Remote_Manager` loops `(string)`-normalize the key. A diff that reintroduces `array_merge` for the registry union is a known regression with a dedicated test; flag it alongside the salt/stats gates.

## Service CI specifics

Per-plugin REST controllers are gone — endpoints are now declared as verbs on `App\*_CI_Node` service CIs (`Discovery_CI_Node`, `Status_CI_Node`, `Settings_CI_Node`, `Logger_CI_Node`, `Events_CI_Node`, `Servers_CI_Node`, `Aggregator_CI_Node`, `Performance_CI_Node`). The substrate's command-protocol REST surface dispatches commands at `/wp-json/newspack-nodes/v1/command` (POST) and SSE at `/wp-json/newspack-nodes/v1/messages/stream` (GET). `Performance_Controller_Base` and the entire `includes/rest/` directory were DELETED in v0.9.0; an `M2BootstrapTest` regression guard asserts the class stays gone. A diff that reintroduces `Performance_Controller_Base`, adds `extends Performance_Controller_Base`, or revives `includes/rest/` reverts that v0.9.0 deletion; push back.

- Verb declaration: in `node_schema()['commands']` — `name`, `description`, `args` (per-arg `name`/`type`/`required`/optional `default`), and an inline `handler` closure. There is no per-schema `permission_callback` field.
- Per-verb capability gate: every handler calls `self::require_manage_options()` (the `Service_CI_Node` static helper) at the top; worker requests are excluded via the `NEWSPACK_NODES_WORKER_TYPE` env tag set pre-dispatch.
- Per-verb rate limit: none. Service-CI verbs are not rate-limited at the CI layer; the SSE slot pool's connection cap is the structural backpressure on dashboards.
- Error returns: throw freely — the substrate wraps as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument-validation paths.
- Output escaping: `esc_html()` / `esc_attr()` / `esc_url()` for any string going into HTML; `wp_json_encode()` (not raw `json_encode`) for arrays sent over the wire.
- Handler signature: `static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array`. `$self` is typed to the base `Command_Interpreter_Node` (not the concrete CI). There is no `payload` slot — the v0.11.0 cutover removed the request-side command `payload`; handlers parse positional/option args out of the `$args` string via the `Command_Args` grammar.

### 17. Canonical view contract (v0.8.0)

Every dashboard hook (`useRequestLogGraph`, `useAggregatorStatusGraph`, `useAggregatorAdminGraph`, `usePerformanceGraph`, …) MUST follow the same view contract — the gut of the dashboard rewrite:

- Command-fanout views (any view that issues a TM_COMMAND it expects a reply for) own a `pending` Map keyed by `message[ID]`. The hook stashes `{ resolve, reject }` resolvers under the ID before filling the message; the view's `fill()` matches by ID, settles, then updates the render model. Pure-SSE views (no outbound commands) don't need `pending`.
- TM_ERROR envelopes route through an internal `_errorMessage(payload)` helper that coerces string / `{message}` / fallback payloads to a human-readable string before stashing in the view model's `error` field or rejecting the pending Promise. Never throw inline from `fill()` — that crashes React.
- View-model updates preserve prior data on partial replies — per-slice last-modified dedup in `performanceView`; list-replaces-table in `serversView`; complete-wins upsert in `gyroscopeView`. A diff that wholesale-replaces the model on every reply (clobbering sibling slices / prior rows) breaks drilldown — flag it.
- No dead REPL mounts (`_output` / `_completion` / `_uptime` / `_cwd`) on production dashboards. The CommandInterpreter mount is for the console tree only; copy-pasting it into a Performance dashboard adds dead nodes that compete for `_router` traffic and collide with the debug-overlay's REPL.
- All mounted nodes (`_sse`, `_http`, `_heartbeat`, view, transform, route) `sink = interpreter`; flow is steered via `target` / `TO`, not bespoke `nodeA.sink = nodeB` chains.
- Mount only what the dashboard needs: CRUD-on-demand (aggregator-admin) gets `_http` + view only; live-stream dashboards (request log, gyroscope, error log) get `_sse` + `_heartbeat` + transform + view. Mounting unused boundary nodes is dead weight.

A diff that lands a new dashboard or hook without these is a regression to the pre-canonical pattern. Flag it.

### 18. v0.6.0 schema field rename

`node_schema()` uses `'arguments'` (not `'ctor'`) for positional ctor args and `'commands'` (not `'verbs'`) for verb declarations. A diff that reads or writes `'ctor'` / `'verbs'` is a stale port — the rename landed in substrate v0.6.0 and both repos shipped it.

### 19. Settings go through Settings_Schema (v0.13.0)

Settings live in ONE declarative `Settings_Schema` (`includes/class-settings-schema.php`) — one `Field` per setting — from which the Config overlay keys, the admin register/render loop, and worker-restart classification (`Schema::restart_for()`) all derive. The parallel `Config::$option_schema` + `Admin::$option_names` arrays were collapsed into it in v0.13.0; reset / delete-on-blank is handled by the substrate's `Config_System\Reset_Gate`. A diff that adds a new setting should add a `Field` to `Settings_Schema` (with its `restart:` keys), NOT reintroduce a parallel `Admin` array. Reset/blank-delete behavior belongs to `Reset_Gate`, not hand-rolled.

### 20. Substrate presence gate

The deferred bootstrap (`plugins_loaded` priority 11) is gated on `class_exists( '\Newspack_Nodes\Node' )` — it wires ELN when the substrate is present, no-ops otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WP 6.5+; the plugins deploy together, so there is no version floor (a present-but-too-old substrate isn't a real case). A diff that re-introduces a `MINIMUM_NODES_VERSION` floor / admin-notice guard is re-adding machinery we deliberately removed — flag it.

### 21. Hook-instrumentation invariants (v0.11.0 / v0.13.1)

`App\Core::wrap_callbacks` skips any callback declaring a `&` (by-reference) parameter (`callback_has_ref_param()`) so wrapping doesn't break by-reference WordPress filters; and it registers a sacrificial `hook_spacer` at `SPACER_PRIORITY` (`PHP_INT_MAX - 2`) to survive self-removing filters (v0.13.1). A hook-instrumentation diff that wraps a by-ref callback, or removes the spacer, regresses a real shipped fix.

### 22. Job_Worker_Node lives in the substrate

The job executor (`Job_Worker_Node`) moved to `newspack-nodes` in v0.12.0. This plugin keeps ONLY the job request-context glue — `Log_Manager::begin/end_job_context`, hooked onto the substrate's `newspack_nodes/job_worker/{before,after}_job` actions. Don't expect (or add) a `Job_Worker_Node` in this plugin; a diff that reintroduces one here is duplicating the substrate.

## React / dashboard nits

- `@wordpress/element` for React, `@wordpress/api-fetch` for REST.
- Bundle build is wp-scripts based; `npm run build` produces `build/` artifacts.
- Shared hooks/utils are imported from `@newspack-nodes/shared/*` (the substrate's canonical `src/shared`, resolved by esbuild + jest, mirroring `@newspack-nodes/runtime`). There is no local `src/shared/` — it was removed in v0.12.0. A tree importing from another dashboard tree (not from the shared alias) is still a layering smell.
- `restUrl` localized as bare `/wp-json/`, not pre-namespaced. Components add the namespace per call.

## Tests

- Unit tests under `tests/unit/` — mostly flat (Service CI tests sit alongside other unit tests, e.g. `AggregatorCITest.php`, `PerformanceCITest.php`, `SettingsCITest.php`); the only subdirs are `tests/unit/Admin/` and `tests/unit/Cli/`. Integration tests under `tests/integration/`. There is no `tests/unit/Rest/` subdirectory; per-plugin REST controllers were retired with the Service CI cutover.
- Coverage report under `/volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/` after running `tests/run-coverage.sh`. New code should add tests so coverage doesn't regress.
- Test fixtures use `Message::TM_STRUCT` for array-VALUE messages (was `TM_BYTESTREAM` pre-rename; if you see TM_BYTESTREAM in a fixture with array VALUE, that's a stale test that needs updating).
- New Service CI verbs should have a happy-path test, an unauthorized-request test (verifying `require_manage_options` throws for non-admins), and a memcache-failure test (where the handler reads `Core::$memd`). Rate-limit tests aren't applicable — Service CI verbs aren't rate-limited at the CI layer; the SSE slot pool is the only structural backpressure.

## Common review nits that aren't bugs

- The 9-namespace memcache schema looks redundant at first glance (why not collapse?). It's deliberate — different access patterns benefit from different keys, and the `get_multi` batching across all namespaces is essential for dashboard latency.
- Reading `\Newspack_Nodes\Core::$memd` (the raw `\Memcached`) directly from many classes looks like a missing abstraction, but it's the intended shape post-gut — one shared handle, null-safe access, no `Cache_Interface` indirection.
- Application classes register with the substrate's CommandInterpreter — that's fine and intentional, even though they're not technically substrate.

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-debugging` — runtime debugging
- `nodes-review` (in newspack-nodes) — substrate gates that apply transitively
