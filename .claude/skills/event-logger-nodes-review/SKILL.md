---
name: event-logger-nodes-review
description: Code review checklist for newspack-event-logger-nodes (the application). Use whenever reviewing a diff that touches anything under newspack-event-logger-nodes/ — application Node subclasses, service CI verbs, dashboards, topologies, job handlers, memcache schema, hub/spoke routing.
argument-hint: "[file or class]"
---

# Event Logger Nodes Review Checklist

The application gates. Substrate concerns (PIPE_BUF discipline, lazy init, FROM stamping, TM_STRUCT typing) live in newspack-nodes' `nodes-review` skill and apply transitively here.

## When to Use

After any diff touching files under `newspack-event-logger-nodes/`. Run BEFORE pushing or merging.

## Gates (high-impact first)

### 1. Remote-activity gate

No operator hub toggle exists. `enable_workers` (v0.5.0) and `enable_aggregator` are retired, and `tests/unit/RetiredConfigKeysTest.php` guards both keys. Hub-mode derives from whether the `aggregator` topology — plus `hub-control` for settings/discovery fan-out — sits in the substrate's active `topologies` list. Fresh installs are spokes or standalone.

Settings fan-out is the substrate `Settings_Sync_Node` graph in the `hub-control` topology; `Auto_Tuner_Node::persist` is a plain `update_option`, no `remote_manager` job, no `suppress_sync`. An option change always records a settings event, but nothing fans it out unless `hub-control` is active and per-spoke `HTTP_Out` nodes are wired — missing consumers ARE the structural gate.

Push back on any diff introducing a hub flag (`enable_aggregator` / `enable_workers` checks, a `Hub::is_active()` helper, a `MINIMUM`-style gate). A polarity check around the settings/discovery fan-out regresses to the legacy design.

### 2. Stats fail-soft, SSE fail-closed

Two memcache uses with deliberately opposite behavior:

- **Stats_Store** (the ELN-side callsite, and anything reading stats for dashboards): every method returns `null` / `[]` / `false` on memcache failure, and never throws. Dashboards display "no data" rather than erroring.
- **SSE slot pool**: fail CLOSED. Memcache down means new connections get HTTP 429. The pool IS the rate limit; falling through silently breaks that invariant. `Sse_Slot_Pool` (the 429 callsite) is a SUBSTRATE component (`newspack-nodes/includes/class-sse-slot-pool.php`), not an ELN class — it enforces fail-closed, and the ELN-side gate is to not break the invariant.

A diff unifying these into one error-handling style is wrong. Code that throws from the stats path, or swallows errors in the slot path, has them inverted.

### 3. JobIntake for >4KB, firehose for ≤4KB

LogManager writes to the firehose under a `MAX_DATA_SIZE = 3840` byte cap, under PIPE_BUF's 4096. Anything larger is silently clipped: the category gets ` (truncated)` appended and the data becomes a single 1000-char `m` string (`['m' => substr(json,0,1000).'...']`, plus an `error_log`), destroying the job payload.

Large jobs belong on `\Newspack_Nodes\Job_Intake::queue($handler, $payload)`, a substrate class. It auto-locks via Lock and uses an `allow_large_writes()` Partition (`Job_Intake::MAX_JOB_SIZE` — the canonical 32MB cap `Job_Router_Node` derives from).

A new producer of potentially-large jobs routed through LogManager is silently broken. Check whether `wp_json_encode($payload)` can exceed 4KB; if it can, it must use JobIntake.

### 4. Read the shared `Core::$memd`, don't inject a cache

Caching is the single shared `\Newspack_Nodes\Core::$memd` handle, a raw `\Memcached`. No `Cache_Interface` / `Memcached_Cache` survives to type-hint or inject. New code that adds a cache constructor param, type-hints a cache interface, or revives a per-class cache field fights the gut; consumers (`Stats_Store`, the service CIs) read `Core::$memd` directly with null-safe `Core::$memd?->...`. Tests set `Core::$memd` in setUp to the substrate's in-memory `\Memcached` double (`../newspack-nodes/tests/Helpers/InMemoryMemcached.php`, required via `tests/bootstrap.php`; there is no local `tests/Helpers/`). The `set_cache()` injection seam is gone.

### 5. One connection — don't build your own or hardcode servers

The substrate's `\Newspack_Nodes\Bootstrap::init_memcached()` builds `Core::$memd` once at boot from the `memcache_servers` config (default `127.0.0.1:11211`); ELN production code never assigns it. A diff that hardcodes a server list or news up a second `\Memcached` connection is wrong — read the shared handle. (`Memcached_Cache::DEFAULT_SERVERS` went away with the class.)

### 6. Salt rotation behavior

`Stats_Store::flush_all()` rotates the 8-char salt in `newspack_event_logger_nodes_stats_salt`. Existing keys orphan instantly; TTL cleans up, and there is no scrubber.

Long-running workers cache the prefix at construction, so they keep writing to the OLD salt until they respawn. A diff calling `flush_all()` and expecting immediate effect is wrong — call sites must tolerate the stale-write window or trigger a worker restart.

### 7. Memcache value caps

Per-namespace caps prevent value-explosion against memcache's 1MB/value limit:

- `MAX_DIM_VALUES = 20`
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`

Overflow rolls into a synthetic "Other" bucket. The `total` pseudo-category is preserved before capping, so totals stay accurate even after dimension keys get capped.

Raising a cap without weighing the value size, or removing the "Other" rollup, is risky: memcache silently truncates or rejects values over 1MB.

### 8. Sums-not-means storage

Leaderboard buckets store raw sums — `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count` — so cross-bucket and cross-partition merge is exact addition. The display layer computes means at read time.

A diff introducing running-mean storage (the EMA we explicitly fixed) regresses to a known buggy state. Aggregator code MUST sum, not average.

### 9. Application Node subclasses resolve by namespace prefix

The plugin's main file registers the top-level node-class prefix via `\Newspack_Nodes\Topology_Registry::register_plugin('Newspack_Event_Logger_Nodes\\', …/topologies)`; `register_namespace` covers only the `App\` service-CI sub-namespace. `make_node Flame_Builder` then resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix, with no per-class registration to keep in sync.

A new node subclass inherits that registration free if it lives under `Newspack_Event_Logger_Nodes\\` (or `App\\`) and a `composer dump-autoload -o` runs. Otherwise a `.tsl` `make_node Foo foo` cannot resolve the class.

### 10. No `class_exists()` guards for in-plugin classes

The deferred loader (require_once chain on `plugins_loaded` priority 11) loads every class in this plugin before anything constructs them, so `class_exists()` guards around an in-plugin class are dead branches, deliberately removed in a cleanup pass. Push back when a diff adds one back as "defensive".

Optional-dependency guards stay: `class_exists( 'Memcached' )` (PHP extension), `class_exists( 'WP_REST_Controller' )` (test bootstrap context), `class_exists( 'WP_CLI' )` (CLI-only paths). Ask "is this class guaranteed loaded by our own bootstrap?" If yes, drop the guard.

### 11. Stream-injection + iteration-cap pattern for testability

CLI commands and other blocking-work classes use seams instead of calling `STDIN` / `microtime` / `sleep` inline — but the seam differs by method, not a uniform "resource + cap" pair. `Reqgrep_Command::process_stdin( $stream = null )` takes only the I/O resource (defaulting to `STDIN`, looping `fgets` to EOF, no cap); `Reqgrep_Command::follow_mode( int $max_iterations = \PHP_INT_MAX )` takes only an iteration cap; the substrate's `CLI_Stdin_Reader_Node::fire` is its own seam. Production uses the defaults; tests pass `php://memory` streams and small caps to drive loops deterministically.

Flag a new blocking command that bakes the streams in with no injection seam and no iteration cap. It is hard to test, and the fix costs one parameter.

### 12. Type flags

Inherited from the substrate: array VALUE → `TM_STRUCT`, string VALUE → `TM_BYTESTREAM`. Consumers reading array VALUE must gate on `TM_STRUCT`. Mixing is a known-buggy pattern; don't regress.

LogManager, RequestBuilder (`emit_request` / `emit_error`), FlameBuilder and JobIntake all use TM_STRUCT. Hub fan-in is the substrate `\Newspack_Nodes\Remote_Source_Node`, which forwards the remote envelope's TYPE; ELN's `Remote_Job_Rewrite_Node` reads array VALUE, gated on it being an array, and forwards in place. The old ELN `Stream_Merger_Node` / `Remote_Source_Node` were deleted in the pull-side cutover.

### 13. Index formatters receive the unpacked message array — they never json_decode

`Request_Builder_Node::format_index_entry` and `Flame_Builder_Node::format_index_entry` (the `Partition::with_index()` formatters) receive the unpacked message ARRAY, not the serialized JSONL line: `format_index_entry( array $message, array $position )`, reading `$message[ Message::VALUE ]` directly. Push back on a diff reintroducing a `json_decode` inside a formatter (with a `FLAME_JSON_DEPTH`, `64`, or any depth), or restoring the old `string $line` / by-ref `$data` signature — the substrate no longer passes a line, so a decode there operates on the wrong type.

### 14. Spoke credentials live in the substrate Vault

`Server_Registry`, `Remote_Manager` and `Health_Check_Extensions` were deleted; spoke credentials and the registry live in the substrate **Vault** (`\Newspack_Nodes\Vault`, managed via the substrate `vault` CI). Push back on a diff reintroducing a `Server_Registry` / `aggregator_servers` option, a `Remote_Manager` job handler, or any numeric-server-id storage in ELN — that concern belongs to newspack-nodes' Vault.

## Service CI specifics

Per-plugin REST controllers are gone; endpoints are verbs on `App\*_CI_Node` service CIs. This plugin owns FIVE: `Discovery_CI_Node`, `Logger_CI_Node`, `Events_CI_Node`, `Performance_CI_Node`, `Rules_CI_Node` (the last is the v0.28.0 per-URL-ruleset editor — verbs `list`/`save`/`upsert`/`delete`/`reset`). `status`/`settings`/`aggregator` are substrate-owned CIs, and the substrate `vault` CI replaced the old `servers` CI. The substrate's command-protocol REST surface dispatches commands at `/wp-json/newspack-nodes/v1/command` (POST) and SSE at `/wp-json/newspack-nodes/v1/messages/stream` (GET). `Performance_Controller_Base` and the entire `includes/rest/` directory were DELETED in v0.9.0, and an `M2BootstrapTest` regression guard asserts the class stays gone. Push back on a diff reintroducing `Performance_Controller_Base`, adding `extends Performance_Controller_Base`, or reviving `includes/rest/`.

- Verb declaration: in `node_schema()['commands']` — `name`, `description`, `args` (per-arg `name`/`type`/`required`/optional `default`), and an inline `handler` closure. There is no per-schema `permission_callback` field.
- Per-verb capability gate: every handler calls `self::require_manage_options()` (the `Service_CI_Node` static helper) first; worker requests are excluded via the `NEWSPACK_NODES_WORKER_TYPE` env tag set pre-dispatch.
- Per-verb rate limit: none. Service-CI verbs are not rate-limited at the CI layer; the SSE slot pool's connection cap is the structural backpressure on dashboards.
- Error returns: throw freely — the substrate wraps as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument-validation paths.
- Output escaping: `esc_html()` / `esc_attr()` / `esc_url()` for any string going into HTML; `wp_json_encode()` (not raw `json_encode`) for arrays sent over the wire.
- Handler signature: `static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array`. `$self` is typed to the base `Command_Interpreter_Node`, not the concrete CI. There is no `payload` slot — the v0.11.0 cutover removed the request-side command `payload`. `$args` is a **token array** (`list<string>` argv), NOT a space-joined string: the substrate migrated the `{name, arguments}` command envelope to the token-array contract, so handlers parse via `Command_Args::parse( self::arg_strings( $args ) )`, or read the whole payload as one token (`self::arg_strings( $args )[0]`) for the `rules` `save`/`upsert` JSON blob. Flag a handler still typed `string $args` or splitting a joined string as a stale port.

### 15. Canonical view contract (v0.8.0)

Every dashboard hook (`useRequestLogGraph`, `usePerformanceGraph`, `useGyroscopeGraph`, `useErrorLogGraph`, `useHookCatalogGraph`) MUST follow the same view contract — the gut of the dashboard rewrite:

- Command-fanout views (any view issuing a TM_COMMAND it expects a reply for) own a `pending` Map keyed by `message[ID]`. The hook stashes `{ resolve, reject }` resolvers under the ID before filling the message; the view's `fill()` matches by ID, settles, then updates the render model. Pure-SSE views need no `pending`.
- TM_ERROR envelopes route through an internal `_errorMessage(payload)` helper that coerces string / `{message}` / fallback payloads to a readable string before stashing in the view model's `error` field or rejecting the pending Promise. Never throw inline from `fill()` — that crashes React.
- View-model updates preserve prior data on partial replies: per-slice last-modified dedup in `overview-view-node`; list-replaces-table in `urls-view-node` (`storeResult` swaps the whole `data` rows array); complete-wins upsert-by-rid in `gyroscope-view-node`, never overwriting an entry already marked complete. Flag a diff that wholesale-replaces the model on every reply, clobbering sibling slices or prior rows — it breaks drilldown.
- No dead REPL mounts (`_output` / `_completion` / `_uptime` / `_cwd`) on production dashboards. The CommandInterpreter mount serves the console tree only; copy-pasting it into a Performance dashboard adds dead nodes that compete for `_router` traffic and collide with the debug-overlay's REPL.
- All mounted nodes (`_sse`, `_http`, `_heartbeat`, view, transform, route) set `sink = interpreter`; steer flow via `target` / `TO`, not bespoke `nodeA.sink = nodeB` chains.
- Mount only what the dashboard needs: a command/reply dashboard with no live stream (`overview`/performance — poll plus on-demand commands) gets `_http` + view only; live-stream dashboards (request log, gyroscope, error log) get `_sse` + `_heartbeat` + transform + view. Unused boundary nodes are dead weight.

Flag a new dashboard or hook that lands without these; it regresses to the pre-canonical pattern.

### 16. v0.6.0 schema field rename

`node_schema()` uses `'arguments'` (not `'ctor'`) for positional ctor args and `'commands'` (not `'verbs'`) for verb declarations. A diff reading or writing `'ctor'` / `'verbs'` is a stale port — the rename landed in substrate v0.6.0 and both repos shipped it.

### 17. Settings go through Settings_Schema (v0.13.0)

Settings live in ONE declarative `Settings_Schema` (`includes/class-settings-schema.php`), one `Field` per setting, from which the Config overlay keys, the admin register/render loop, and worker-restart classification (`Schema::restart_for()`) all derive. The parallel `Config::$option_schema` + `Admin::$option_names` arrays collapsed into it in v0.13.0. A diff adding a setting should add a `Field` with its `restart:` keys, NOT reintroduce a parallel `Admin` array, and must leave reset and delete-on-blank to the substrate's `Config_System\Reset_Gate` rather than hand-rolling it.

### 18. Substrate presence gate

The deferred bootstrap (`plugins_loaded` priority 11) is gated on `class_exists( '\Newspack_Nodes\Node' )`: it wires ELN when the substrate is present and no-ops otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WP 6.5+, and the plugins deploy together, so there is no version floor — a present-but-too-old substrate isn't a real case. Flag a diff re-introducing a `MINIMUM_NODES_VERSION` floor or admin-notice guard; that machinery was deliberately removed.

### 19. Hook-instrumentation invariants (v0.11.0 / v0.13.1)

`App\Core::wrap_callbacks` skips any callback declaring a `&` (by-reference) parameter (`callback_has_ref_param()`), so wrapping doesn't break by-reference WordPress filters, and it registers a sacrificial `hook_spacer` at `SPACER_PRIORITY` (`PHP_INT_MAX - 2`) to survive self-removing filters (v0.13.1). A hook-instrumentation diff that wraps a by-ref callback, or removes the spacer, regresses a real shipped fix.

### 20. Job_Worker_Node lives in the substrate

The job executor (`Job_Worker_Node`) moved to `newspack-nodes` in v0.12.0. This plugin keeps ONLY the job request-context glue — `Log_Manager::begin/end_job_context`, hooked onto the substrate's `newspack_nodes/job_worker/{before,after}_job` actions. A diff reintroducing a `Job_Worker_Node` here duplicates the substrate.

### 21. Per-URL logging ruleset (v0.28.0) — one writer, pattern-hash ids, empty-means-empty

The seven global logging settings (`log_urls`/`skip_urls`/`log_events`/`custom_events`/`significant_events`/`auto_disable_threshold`/`auto_protect_time_threshold`) were absorbed into a per-URL **ruleset**: an ordered list of `Rule`s, each a URL pattern (prefix `/x` or exact `/x?`) with a `log`/`skip` action and — for `log` rules — its own hooks, custom events, significant events and auto-tune thresholds. `Log_Manager` resolves ONE governing rule per request: longest-prefix-wins, case-insensitive, and no match means skip. Gates:

- **Every write goes through `Rule_Set::save()`** — never a raw `update_option` on `newspack_event_logger_nodes_rules`. `save()` maintains the inline↔pointer hook tiering (`INLINE_HOOK_LIMIT = 100`; heavy hooks tier to a non-autoloaded `..._rule_hooks_<id>` option mirrored into `evlog:rules:hooks:<id>`) and orphan reconcile. Bypassing it corrupts the two-tier storage.
- **A rule's id is `Rule_Set::id_for($pattern)`**, the pattern's `Log_Manager::url_hash()` — one id per pattern, and a client-supplied id is ignored. A diff reintroducing positional id minting (`generate_rule_id`/`gen_id`), or letting two rules share a pattern, is a regression.
- **Empty means empty.** There is NO implicit `/` log-all baseline; an empty or absent ruleset logs nothing. A diff re-adding a synthetic `Rule::minimal('/')` fallback (that factory was removed) regresses the fixed behavior. Deployments wanting log-all declare a `/` log rule explicitly, as the shipped default config does.
- **Don't revive the global logging options.** Reintroducing `log_urls`/`skip_urls`/`log_events`/`custom_events`/etc. as `Settings_Schema` fields or option rows revives retired machinery — those are per-rule now. (`enable_logging`, `log_memory`, `flush_every_line`, `allowed_users`, `hook_start_priority` stay global.)
- **Discovery stages hooks, it doesn't write rules.** `Discovery_Collector_Node` stages spoke-reported hooks into a non-autoloaded `discovered_hooks` option, surfaced in the editor's picker; the editor is the only writer of rules. A diff having discovery union-merge into a `/` rule and save the ruleset regresses the "empty means empty" fix.
- **`upsert` edits match by id, add matches by pattern.** `Rules_CI_Node::upsert` replaces in place by `id` when the incoming rule carries one, so an edit that renames doesn't orphan the old pattern; it falls back to pattern-match only for the id-less "Log this URL" add. Don't collapse them back to pattern-only.
## React / dashboard nits

- `@wordpress/element` for React, `@wordpress/api-fetch` for REST.
- Bundle build is wp-scripts based; `npm run build` produces `build/` artifacts.
- Shared hooks/utils import from `@newspack-nodes/shared/*`, the substrate's canonical `src/shared`, resolved by esbuild + jest and mirroring `@newspack-nodes/runtime`. There is no local `src/shared/`; it was removed in v0.12.0. A tree importing from another dashboard tree rather than the shared alias remains a layering smell.
- `restUrl` localized as bare `/wp-json/`, not pre-namespaced. Components add the namespace per call.

## Tests

- Unit tests under `tests/unit/`, mostly flat. ELN owns five Service-CI test files: `DiscoveryCITest.php`, `LoggerCITest.php`, `EventsCITest.php`, `PerformanceCITest.php`, `RulesCITest.php` (`AggregatorCITest` / `SettingsCITest` are substrate tests in newspack-nodes). The ruleset engine has its own suite: `RuleTest.php`, `RuleSetTest.php`, `RuleMatcherTest.php`. The only subdirs are `tests/unit/Admin/` and `tests/unit/Cli/`; integration tests live under `tests/integration/`. There is no `tests/unit/Rest/` subdirectory — per-plugin REST controllers retired with the Service CI cutover.
- Coverage report lands under `/volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/` after running `tests/run-coverage.sh`. New code should add tests so coverage doesn't regress.
- Test fixtures use `Message::TM_STRUCT` for array-VALUE messages (it was `TM_BYTESTREAM` pre-rename; TM_BYTESTREAM in a fixture with array VALUE is a stale test needing an update).
- New Service CI verbs need a happy-path test, an unauthorized-request test verifying `require_manage_options` throws for non-admins, and a memcache-failure test where the handler reads `Core::$memd`. Rate-limit tests don't apply — the SSE slot pool is the only structural backpressure.

## Common review nits that aren't bugs

- The 9-namespace memcache schema looks redundant at first glance. It's deliberate: different access patterns benefit from different keys, and the `get_multi` batching across all namespaces is essential for dashboard latency.
- Reading `\Newspack_Nodes\Core::$memd` (the raw `\Memcached`) directly from many classes looks like a missing abstraction, but it's the intended shape post-gut — one shared handle, null-safe access, no `Cache_Interface` indirection.
- Application classes register with the substrate's CommandInterpreter. That's intentional, even though they aren't technically substrate.

## Related Skills

- `event-logger-nodes-workflow` — implementation workflow
- `event-logger-nodes-debugging` — runtime debugging
- `nodes-review` (in newspack-nodes) — substrate gates that apply transitively
