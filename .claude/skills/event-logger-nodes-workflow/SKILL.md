---
name: event-logger-nodes-workflow
description: Implementation workflow for the newspack-event-logger-nodes application — adding job handlers, service CI verbs, MCP tools, dashboard React trees, topology files, and application Node subclasses. Use whenever the change lives under newspack-event-logger-nodes/ rather than the substrate runtime.
argument-hint: "[handler / verb / dashboard / node]"
---

# Event Logger Nodes Workflow

The application built on the newspack-nodes runtime. For substrate changes (Node, Router, Topic, Partition, Worker, Fleet, REPL, Tee, Tail, Consumer), use the `nodes-workflow` skill in newspack-nodes instead.

AGENTS.md carries the architecture decisions and key files; this skill is the procedural companion.

## When to Use

- Adding or changing a job handler (anything filtered onto `newspack_nodes/job_handlers` or `newspack_nodes/remote_job_handlers`)
- Adding a verb to one of the three service CIs (`App\Performance_CI_Node`, `App\Rules_CI_Node`, `App\Discovery_CI_Node`)
- Exposing an existing verb to agents as an MCP tool (`App\MCP_Controller`)
- Touching the React dashboards (any tree under `src/`)
- Adding an application Node subclass (a `Request_Builder_Node`-style processor)
- Modifying topology files under `topologies/`
- Touching the profiler drop-in, `mu-plugins/00-newspack-profiler.php`
- Changing `Log_Manager`, `App\Core` (the hook, outbound-HTTP and query instrumentation), `Flame_Builder_Node`, `Stats_Store`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`, or the ruleset trio (`Rule` / `Rule_Set` / `Rule_Matcher`)

## Phases

### Phase 0: Build once

The substrate checkout must sit at `../newspack-nodes`. `scripts/build.mjs` resolves every `@newspack-nodes/*` alias through it (via the substrate's `src/build-kit/alias-map.cjs`), and jest resolves the same map, so a missing sibling fails the build by name rather than deep inside esbuild.

```bash
composer install   # PHP classmap autoloader; its post-install also sets core.hooksPath
npm install        # esbuild, jest, eslint
npm run build      # compile the six dashboard bundles into build/
```

`npm run build` is esbuild through `scripts/build.mjs`, not wp-scripts.

### Phase 1: Confirm the layer

Application code may know about requests, jobs, flames, hubs, spokes and dashboards. The test: would a consumer of newspack-nodes that is not the event logger ever want this? If it would, the change belongs in the substrate; if not, it belongs here.

### Phase 2: Implement

#### Adding a job handler

1. Define the callable. `Job_Worker_Node` handles per-job try/catch; don't wrap.
2. Filter onto the right list (a handler can register on either or both):
   - `newspack_nodes/job_handlers` — dispatched for `k:"job"` entries on every node's own `Job_Worker_Node`. Use when the work runs locally on the node that produced the entry.
   - `newspack_nodes/remote_job_handlers` — dispatched on the hub for `k:"remote_job"` entries, the rewritten product of spoke-aggregated `k:"job"` lines. Use when the work runs centrally on the hub after aggregation.

   Register under both when local and aggregated copies need the same name but different logic — say, a local handler that runs unconditionally beside a hub handler that filters by a per-entry attribute.
3. Validate inputs at the handler boundary. The substrate limits size (32MB per job, `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE`) but never content.
4. **Size discipline**: a payload that could exceed 4KB goes through `\Newspack_Nodes\Job_Intake::queue( $handler, $id, $parameters, $key )` rather than `Log_Manager`. `$id` is the optional per-job identity jobstats keys on, `$parameters` passes through to the handler, and `$key` is the optional partition-routing key. Job_Intake is the auto-locked large-write path.

This plugin registers no handler of its own on either filter, and `Job_Router_Node` reads neither — it only normalizes job-shaped entries onto `jobs.log` for the worker to dispatch. For a timer-driven local handler, read the sibling `newspack-cache-cozy` plugin's `Cache_Cozy_Tick_Node`: a `Timer_Node` that hitchhikes the `_router` heartbeat, enqueues a job every interval, and registers its handler on `newspack_nodes/job_handlers`. This plugin's own `Timer_Node` subclasses — `Request_Builder_Node`, `Request_Flight_Node`, `Discovery_Collector_Node` — rotate caches or mint commands instead.

#### Adding a service CI verb

Endpoints are verbs on a service CI. The substrate's command-protocol REST surface exposes them at `/wp-json/newspack-nodes/v1/command` (POST one or more envelopes per request, dispatched against the addressed node) and `/wp-json/newspack-nodes/v1/messages/stream` (GET SSE). The one REST route this plugin registers itself is the MCP server (below).

1. Pick the right service CI class (`includes/app/class-*-ci-node.php`). This plugin owns THREE, all mounted by `newspack_event_logger_nodes_mount_service_cis()` on `newspack_nodes/request_graph_ready`:

   | Class | Mount name | Verbs |
   |---|---|---|
   | `Performance_CI_Node` | `performance` | `overview`, `urls`, `url_detail`, `url_breakdown`, `request_search`, `request_grep`, `request_detail`, `ask`, `hooks_registered`, `set` |
   | `Rules_CI_Node` | `rules` | `list`, `save`, `upsert`, `delete`, `reset` |
   | `Discovery_CI_Node` | `discovery` | `get` |

   The substrate owns ten more (`Status_CI_Node`, `Settings_CI_Node`, `Aggregator_CI_Node`, `Vault_CI_Node`, `Workers_CI_Node`, `Raw_Logs_CI_Node`, `Topologies_CI_Node`, `Sessions_CI_Node`, `Classes_CI_Node`, `Layouts_CI_Node`) — add those verbs in newspack-nodes, not here.
2. Add a verb entry in that CI's `node_schema()['commands']` array: `name`, `capability`, `description`, `args` (per-arg `name` / `type` / `required` / optional `default`), and an inline `handler` closure.
3. **`capability` is the whole gate.** `Service_CI_Node::commands()` wraps every handler in `Capabilities::require()` for the role the schema names, on install rather than at dispatch, so a table replaced after construction is gated too. Declare `Capabilities::READ` for a dashboard slice, `Capabilities::TUNE` for a ruleset or settings write. An undeclared verb defaults to `MANAGE` — strictest, not loosest. Never re-gate inside a handler: a hard-coded check silently outranks the declaration. `tests/unit/VerbRoleDeclarationsTest.php` asserts the declared role of every verb on all three CIs, and fails the build on a `require_manage_options()` left anywhere in `Performance_CI_Node`.
4. Handler signature is `static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array`. `$args` is a **token array** (`list<string>` argv), not a space-joined string. Parse positional and option args via `Command_Args::parse( self::arg_strings( $args ) )`; `arg_strings` is the base `Command_Interpreter_Node` helper normalizing the token array to `list<string>`. A verb wanting the payload as one blob reads `self::arg_strings( $args )[0]` — how `rules` `save` and `upsert` take their JSON argument. `node_schema()` is static, so state arrives through `$self`.
5. Throw freely — the substrate wraps a thrown error as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument validation.
6. Stats reads are fail-soft (`null` / `[]` / `false`). Slot-pool ops are fail-closed (HTTP 429 when memcache is down — the slot pool IS the rate limit).
7. Adding a *config field* rather than a verb? Declare one `Field` in `Settings_Schema` (`includes/class-settings-schema.php`), the single declarative source from which the Config overlay keys, the admin register/render loop and the worker-restart classification all derive. Parallel hand-maintained option arrays are retired. Nine keys are declared there, each WITH its default: three rendered checkboxes (`enable_logging`, `log_memory`, `flush_every_line`) and six overlay-only (`allowed_users`, `rules`, `hook_start_priority`, `custom_colors`, `stats_mirror_node`, `recommended_log_events`). A field's `restart:` key holds CONSUMER NODE-TYPE tokens — `['Flame_Builder']`, `['Partition','Topic']` — or `'all'`, which `Restart_Planner` resolves to the live topologies running a matching node; they are NEVER topology names, which drift. Per-URL logging settings are not fields here — they live in the ruleset.

#### Touching the per-URL logging ruleset

A per-URL **ruleset** replaced the seven global logging settings (`log_urls` / `skip_urls` / `log_events` / `custom_events` / `significant_events` / `auto_disable_threshold` / `auto_protect_time_threshold`): an ordered list of `Rule`s (`includes/class-rule.php`), each a URL pattern with a `log` or `skip` action and — for `log` rules — its own hooks, custom events, significant events, auto-tune thresholds, and four diagnostic flags: `log_http` (default **true**, so absent means on), `log_queries` (false; needs `SAVEQUERIES` and costs two entries per query), `trace_hooks` (false; one shallow backtrace per firing, naming the caller on the flame's aggregation label) and `trace_callers` (0 = off; a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT`, 20).

`Log_Manager` builds a `Rule_Matcher` (`includes/class-rule-matcher.php`) per request and resolves the ONE governing rule. Ranking is by SPECIFICITY, not length: query-bearing patterns (`/jobs/x?job-work`) outrank exact paths (`/about?`), which outrank prefixes (`/blog`); length breaks ties only WITHIN a rank, so this is **not** longest-prefix-wins. Matching is case-insensitive, a prefix matches by string rather than path segment (`/wp-cron` governs `/wp-cron.php`), and no match means skip — zero hooks bound, no memcache. Empty means empty; there is no implicit `/` log-all baseline.

- Durable state lives in `Rule_Set` (`includes/class-rule-set.php`). The rule LIST always rides the autoloaded `newspack_event_logger_nodes_rules` option; a heavy rule's hooks (past `INLINE_HOOK_LIMIT` = 100) tier out to a non-autoloaded `newspack_event_logger_nodes_rule_hooks_<id>` option, warm-mirrored through a substrate `Table_Node` on `'eln-rule-hooks'` with a 3600s TTL, read through to the option by `backed_by()`. The Table scopes keys per install, so two sites sharing one memcached cannot hand each other the same pattern's hooks. Every write MUST go through `Rule_Set::save()`, which re-tiers, reconciles orphans and signals a worker reload — never raw `update_option`.
- A rule's id is `Rule_Set::id_for( $pattern )`, the pattern's `Log_Manager::url_hash()`. The stored id is always minted from the pattern — `Rule_Set::rekey_by_pattern()` runs on the config seed, the `save` verb and `apply_synced()` off the wire — so one pattern has exactly one id. `upsert` reads an incoming id for one purpose only: finding the entry a renamed pattern replaces.
- `Rule_Set::hooks_for()` is static and stateless. `Log_Manager` already loaded the ruleset this request, so never `load()` a second `Rule_Set` to reach a rule's hooks.
- Editing goes through the `Rules_CI_Node` service CI. The full rules-editor UI (`src/rules/RulesAdmin`) mounts from `src/settings/index.js` into the settings page's `#event-logger-rules-editor` "Logging Rules" container, not a separate submenu. The performance dashboard (`src/overview/PerformanceDashboard.js`) reuses `src/rules/RuleEditModal` for an inline "Log this URL" quick-add on the URL-detail view, through the same `upsert` verb.
- `Auto_Tuner_Node` is the other writer: it mutates the rule its message names (`rule_id`) and persists the whole list through `Rule_Set::save()` like any admin edit.

#### Adding an MCP tool

`App\MCP_Controller` registers this plugin's one REST route, `POST /wp-json/newspack-event-logger-nodes/v1/mcp`, a JSON-RPC server (protocol `2025-06-18`) wrapping verbs that already exist. Ten tools ship, declared in the private `TOOLS` map as `{ node, verb, role, summary, args }`: seven `performance` reads (`overview`, `urls`, `url_detail`, `request_search`, `request_detail`, `request_grep`, `ask`) plus `rules_list`, `rules_upsert` and `rules_delete`.

1. The verb must exist first, with its own `capability`. A tool is a wrapper, never a second implementation.
2. Add one `TOOLS` entry. `role` must match the verb's declared capability: `tools/list` shows only what the caller's session scope covers, and offering a tool that will refuse is worse than not offering it.
3. Write the `summary` for an agent that cannot see the code. `Findings::caveat()` rides every tool description, so the measurement caveat is already carried — say what the tool answers and what an error means.
4. Arguments pass through `Command_Args`. `POSITIONAL_ARGS` fixes the order bare tokens are emitted in (`descriptor`, `hash`, `rid`, `pattern`, `rule`, `id`, `context`); everything else becomes `--key=value`.

Authorization takes a `Bearer <handle>.<key>` scoped session: the controller becomes that session's minting user and installs the scope as a ceiling, so a scope can only ever subtract. Rate limit is `RATE_LIMIT_BURST` 20 per `RATE_LIMIT_WINDOW_S` 10, keyed by handle and checked after the credential.

#### Adding a React dashboard / page

Every dashboard is a node graph on substrate JS primitives, exposing view nodes React subscribes to through `useNodeState`. The substrate hooks own the mount: `mountExospine` is called by `useBatchedPoll` and `useStreamGraph`, and directly by only one hook here (`src/rules/useRulesGraph.js`).

1. Source under `src/{tree-name}/`, with an entry added to `scripts/build.mjs`, which declares six: `overview`, `error-log`, `gyroscope`, `requests`, `settings` and `current-request`.
2. The plugin's main file maps `?page=<slug>` to a bundle; add the slug to the `page_to_tree` map. `current-request` is the exception — it is a devtools tab, not a page, registered through `Current_Request_Overlay` on `newspack_nodes/devtools_tab_bundles`.
3. Use `@wordpress/element` (not direct React) and `@newspack-nodes/runtime` for substrate JS primitives. Shared hooks, helpers, nodes and components come from `@newspack-nodes/shared/...`; there is no local `src/shared/` and no sync step. Two ELN-owned test helpers live in `src/test-helpers/`: `renderHook` mounts a hook or a component into a real DOM node, and `cascade` compiles a stylesheet and resolves which declaration wins on a rendered element — the question the SCSS source cannot answer.
4. Pick the graph shape by how the dashboard gets data:
   - **Polled / command-fanout** — `useBatchedPoll( { build, timerName, teeName, intervalMs } )`. It owns the exospine mount, `_shell` (the observe-only Tap), `_http` (the POST `/command` egress), the fan-out `Tee`, the router-hitchhiking `Timer` and the page-visibility gate. The `build( { interpreter, tee } )` callback adds ONLY the dashboard's own nodes, typically one `addSliceFetcher` per slice. Reference: `src/overview/hooks/usePerformanceGraph.js`; the smallest example is `src/settings/hooks/useHookCatalogGraph.js`.
   - **Live stream** — the substrate's `useStreamGraph`, which mounts `<prefix>:link` (a `RemoteLink` composing SSE ingress, `_http` and the `_heartbeat` slot keep-alive), `<prefix>:stream` (a pass-through Tee) and `<prefix>:view`. `src/hooks/useGlobStreamGraph.js` wraps it for the two glob dashboards; the gyroscope calls it directly.
   - **One-shot verbs** — `useCommandOnce`, which parks arguments in a Fetcher's outbox and rides the same tick. Reference: `src/rules/useRulesGraph.js`, one scope per mutating verb.
5. Target the egress path with `egressPath( ci )`, which spells `_shell/_http/<ci>`. Addressing `_http/<ci>` directly delivers just as well, which is what makes skipping the Tap silent — `connect _shell` stops seeing traffic that no longer passes through it.
6. **Never correlate a reply.** The server echoes `TO = FROM`, so a reply lands on the node that minted it, and its VALUE carries the verb and arguments it answered. A pending Map keyed by `message[ID]`, a resolver pair, a minted op-id or a KEY demux each re-derive that by hand; `scripts/lint-contract.mjs` fails the build on all of them (ADR-7). Sending several verbs in one tick means more nodes, never one node telling replies apart.
7. Declare view nodes rather than writing them. `registerSliceViews` (from `@newspack-nodes/shared/nodes/slice-view-node`) takes `{ description, empty, parse }` per view and returns the classes; each dashboard's `nodes/register.js` exports them as `views` and merges them into `CommandInterpreterNode.includeNodes`, which is what lets the console palette resolve `make_node <Type>` by name. Hand `makeNode` the CLASS, never the name: `includeNodes` is a per-bundle static, and another bundle's interpreter cannot resolve a name registered here (ADR-16). `SliceViewNode` already handles the failure modes — a TM_ERROR keeps the slice on screen and adds `error` while clearing `loading`, and an unparseable payload leaves the prior slice untouched. Subclass only for a view that owns more than its slice: its own `fill()`, a timer, a teardown (`src/overview/nodes/url-detail-merge-node.js` is the one transform here).
8. Drive loading, clear and error states through the view's `controlFrom`, never by payload shape: a control is recognised by WHO SENT IT, so a reply carrying an `action` field is still a reply.
9. Keep REPL mounts (`_output`, `_completion`, `_uptime`, `_cwd`) off a production dashboard tree — they belong to the console tree and collide with the debug overlay's REPL.

#### Adding an application Node subclass

1. Create `includes/class-{name}.php` with `class Foo_Node extends \Newspack_Nodes\Node` (every node class ends in `_Node`; the shell name is the class minus `_Node`). Override `fill()`.
2. **No registration.** `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', … )` registers the namespace once, so `make_node Foo` resolves `\Newspack_Event_Logger_Nodes\Foo_Node` and the palette scans the classmap. Run `composer dump-autoload -o` (or `composer build:autoloaders`) after adding the file.
3. A `.tsl` topology wires it: `make_node Foo foo` instantiates the node, `connect_node foo next-step` wires the sink, `cmd foo:config <verb> [args…]` runs a config verb. The substrate's Topology_Loader interprets the script per partition. `<eln:KEY>` tokens resolve through `Config::resolve_eln_token()`, which owns three: `is_hub`, `stats_mirror_node` and `stats_mirror_lifetime`.
4. Order the members newspaper-style: the trait-`use` block, the fields, then `__construct`, `arguments`, `fill`, `fire_cb`, `fire`, the call-graph middle in topological order, and `node_schema` last. `pre-commit` runs `php scripts/reorder-node-methods.php --check` on every staged PHP file and fails the commit on a class out of order; `--write` applies the moves, after which run `phpcbf`. `scripts/reorder-node-methods.js` is the twin gating `src/`.

#### Adding a CLI command (`wp nodes <verb>`)

1. Live under `includes/cli/class-<verb>-command.php`. Register in the `WP_CLI` block of the deferred bootstrap in `newspack-event-logger-nodes.php`.
2. Validate inputs at the boundary, and refuse rather than coerce — `Command_Args::option_int()` returns null for a malformed flag so each layer reports it in its own voice.
3. **Make blocking work injectable.** A command that reads stdin in a loop, sleeps between iterations, or polls a file takes the resource or the iteration count as a parameter so tests can drive it deterministically. Two distinct seams in `Reqgrep_Command`:
   - `process_stdin( $stream = null )` — stream-injection seam; defaults to null (resolving STDIN inside), tests pass a `php://memory` resource.
   - `follow_mode( int $max_iterations = PHP_INT_MAX )` — iteration-cap seam; production passes the default, tests pass a small number.

   Keep them separate; don't fold the cap into the stdin reader.

#### Touching the profiler drop-in

`mu-plugins/00-newspack-profiler.php` is a standalone mu-plugin, not autoloaded plugin code: the release attaches it beside the zip, an operator copies it to `wp-content/mu-plugins/`, and deleting the file removes it whole — it writes no option and no durable state. Only an mu-plugin runs early enough for the two facts it captures: WordPress times no plugin's load, and by the time `Log_Manager` writes its first line the request's real start is long past. So the drop-in takes `hrtime()` and `microtime()` at file scope into the `$newspack_profiler` global and times each site-activated plugin off `plugin_loaded` at priority 1. Must-use and network-activated plugins announce on hooks it deliberately does not bind.

Two contracts tie it to this plugin, and both fail quietly when they move:

- `Log_Manager`'s constructor adopts `request_time` and `request_ts` from that global and unsets them, which stamps `process (start)` with the moment PHP began rather than the moment the logger emitted, and stops a nested job context claiming them twice.
- The flush runs on `plugins_loaded` at priority **-10001**, one below `hook_start_priority`'s -10000 default, so the plugin-load spans precede what `App\Core` writes for `plugins_loaded`. Each row goes out as a `(start)` / `(complete)` pair carrying its OWN `ts`, because the flush moment would collapse the whole loading phase onto one instant. Reaching `Log_Manager` that early depends on this plugin requiring its Composer autoloader at file scope, ahead of its own deferred `plugins_loaded` 11 bootstrap.

#### Removing dead code

`composer.json` maps `includes/` as a classmap, so the autoloader resolves every in-plugin class on demand and a `class_exists()` guard around one is always true. Delete any you find while editing; don't leave them as "defensive". The same applies to in-substrate `class_exists()` guards inside newspack-nodes. Out-of-plugin and optional-dependency guards stay — `class_exists( '\Newspack_Nodes\Bootstrap' )` in the bootstrap is the load-order gate, and `class_exists( 'Memcached' )` tests for a PHP extension.

#### Type flags

- An array VALUE (entry hash, request object, flame data) carries `TYPE = TM_STRUCT`.
- A string VALUE (raw line, formatted text) carries `TYPE = TM_BYTESTREAM`.
- Producer and consumer must agree. A consumer reading an array VALUE gates on `TM_STRUCT`.

### Phase 3: Test

```bash
# Unit + integration tests (newspack-nodes must be activated too). Run as a
# NON-ROOT user: Log_Manager refuses root, and the suite instantiates it.
# Always pass --enforce-time-limit so a hung test (readline without a TTY,
# infinite drain loop) aborts at the per-test budget instead of stalling the
# suite. Tests that legitimately sleep mark their class `#[Medium]`.
cd tests && ../vendor/bin/phpunit --enforce-time-limit

# Filter to one test file or method.
cd tests && ../vendor/bin/phpunit --enforce-time-limit --filter RequestBuilderTest

# Coverage HTML/Clover; refuses to run as root for the same reason.
# Invoke via `docker exec -u bend <container> bash tests/run-coverage.sh`.
tests/run-coverage.sh
```

Only PHPUnit runs in the container. The static gates run on the HOST, from the plugin directory:

```bash
npm run lint:php     # phpcs + the 80-column comment gate
npm run lint:js      # eslint + comment gate + lint-contract.mjs (the ADR gates)
npm run lint:scss    # stylelint + lint-styles.mjs
npm run lint:types   # tsc against tsconfig.check.json
npm run test:js      # jest
npm run lint:deadcode     # phpstan-deadcode over the PHP
npm run lint:deadcode:js  # knip over the JS
bash scripts/lint-docs.sh              # doc-vs-runtime drift, prose included
bash scripts/check-substrate-floor.sh  # is the declared floor high enough?
```

Both dead-code audits gate `pre-commit` on staged files, and both exclude tests as consumers, so an export only its own test imports reads as dead. Mark a deliberate one `@testonly` in its docblock, and verify the call path before deleting anything either tool names — most findings are public API or test seams. knip cannot parse JSX in a `.js` file, which drops that file's `import()` expressions, so every `lazy( () => import( './X' ) )` target is an `entry` in `knip.json`.

`pre-push` runs the right subset for the file types in the push range, so pushing is the gate — don't duplicate it by hand.

### Phase 4: Reload running workers

Workers cache loaded classes for their process lifetime, which the substrate sets at `Cooperative_Stop::DEFAULT_MAX_RUNTIME` — 595 seconds. After deploying, restart the relevant worker groups so the new bytecode lands. A restart target is an ACTIVE topology name, validated against the live worker rows, so `wp nodes types` and `wp nodes status` are the source of truth — never a hardcoded list.

```bash
# Every worker type in one shot.
wp nodes restart all

# Or one topology. Ten ship here: complete, performance, request-builder,
# flame-builder, job-router, job-feed, job-hub, job-spoke, aggregator,
# hub-control. `complete` is the all-in-one graph: `include performance`
# plus `include job-hub`, with a Tee fanning the firehose to both.
wp nodes restart complete
wp nodes restart request-builder
wp nodes restart flame-builder
wp nodes restart aggregator      # hub-only; errors out where it isn't active
wp nodes restart hub-control     # hub-only settings-sync + discovery control plane

# Restrict to one partition when you mean to; the default restarts the fleet,
# because restarting one of six leaves five running the old code.
wp nodes restart complete --partition=0
```

The **substrate** registers every runtime verb under `wp nodes` — `types`, `run`, `start`, `stop`, `restart`, `status` (aliased `ls`), `activate`, `deactivate`, `gc`, `doctor`, `ingest`, `scaffold`, `memcache get|flush`, `caps`, `hub-user` and the attached `cli` REPL. This plugin registers two, in the `WP_CLI` block of `newspack-event-logger-nodes.php`: `wp nodes reqgrep` (`Reqgrep_Command`, the application-aware firehose filter) and `wp nodes ruleset-bench` (`Ruleset_Bench_Command`, the dev-only measurement that sets `Rule_Set::INLINE_HOOK_LIMIT`, off the request hot path).

### Phase 5: Live-verify

For changes touching the request-logging pipeline:

```bash
# Hit any URL on the site to generate firehose entries.
curl -sk "<site>/" -o /dev/null -w "HTTP %{http_code}\n"

# reqgrep with --recent reads the second-to-last segment forward.
wp nodes reqgrep --recent | head -10

# reqgrep can also follow live (Ctrl-C to stop).
wp nodes reqgrep --follow

# Requests that reached neither `process (complete)` nor `(aborted)`.
wp nodes reqgrep --incomplete
```

For dashboard changes: open the page and verify the panels render; the browser DevTools network tab shows the `/command` traffic. Telemetry dashboards land at `/wp-admin/admin.php?page=event-logger-*` (`event-logger-overview`, `event-logger-errors`, `event-logger-gyroscope`, `event-logger-requests`); the settings tree, which carries the rules editor and the hook catalog, is served at `page=newspack-event-logger-nodes`. The substrate's own console is the top-level "Nodes" menu, whose panels are devtools tabs — this plugin contributes `current-request` there.

For job handler changes: queue a job through the legitimate caller, wait, check `wp nodes status` for the job-worker heartbeat, and reqgrep for the rid.

## Patterns That Trip People Up

- **Hub vs spoke**: there is no operator hub toggle — both `enable_workers` and `enable_aggregator` were retired, and `tests/unit/RetiredConfigKeysTest.php` guards them. Hub-mode derives from whether the `aggregator` topology is in the substrate's `topologies` list, plus `hub-control` for the settings and discovery fan-out. Settings fan-out is the substrate's `Settings_Sync_Node` graph inside `hub-control`. Missing consumers are the structural gate: with no `hub-control` and no per-spoke `HTTP_Out` wired, a recorded settings event is tailed and dropped.
- **Memcache is required.** `Stats_Store` (driven by `Flame_Builder_Node`), SSE slot rate limiting and worker-position publishing all use it. Without memcache the stats path goes fail-soft (dashboards show no data) and the SSE slot pool fails closed (429). Don't unify the two: the asymmetry is deliberate.
- **Flushing the cache is one button, and it restarts workers itself.** `wp nodes memcache flush` rotates the install's cache salt through `Cache_Backend::rotate_salt()`, orphaning every Newspack plugin's keys at once, then restarts workers because each memoizes the install scope per process. A failed restart is a warning, not a failure — the new scope then waits for the next spawn. `Stats_Store` keeps no salt of its own.
- **Application nodes resolve by namespace prefix**, with no registry: `make_node Flame_Builder` in a `.tsl` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`. The shell name is the class minus `_Node`.
- **The substrate floor and the CI pin are different numbers.** The deferred bootstrap goes dormant below `Bootstrap::version_at_least( '2.46.0', … )` — what the plugin refuses to RUN against — while `.github/workflows/release.yml` pins the tag CI BUILDS against. Raise the floor by hand whenever you call a newer substrate API; `scripts/check-substrate-floor.sh` audits it against every substrate call PHPStan resolves, and `lint-docs.sh` holds the prose (this file included) to whatever the loader enforces.

## After You Land

- Update AGENTS.md if the change altered an architecture decision or a key file.
- If you added a job handler that crosses the hub/spoke boundary, document which side is intended (`job_handlers` vs `remote_job_handlers`).
- If you called a substrate API newer than 2.46.0, raise the floor in `newspack-event-logger-nodes.php` and say why in the comment beside it.
- Add the CHANGELOG entry, then `./scripts/bump-version.sh <version>` — it rewrites the header, the `NEWSPACK_EVENT_LOGGER_NODES_VERSION` constant, `package.json` and the substrate pin in `release.yml` together, and refuses to bump when the sibling substrate's version is not tagged locally. Never hand-edit any of the four.
- Push to this plugin's own remote, `Automattic/newspack-event-logger-nodes`. Pushing a `v<major>.<minor>.<patch>` tag runs the Release workflow, which builds the archive and publishes the zip beside the standalone `00-newspack-profiler.php` drop-in.

## Related Skills

- `event-logger-nodes-debugging` — dashboards, log paths, memcache schema, hub/spoke routing
- `event-logger-nodes-review` — application contract checklist
- `nodes-workflow` (in newspack-nodes) — for substrate-level changes
- `nodes-dashboards` (in newspack-nodes) — the graph shapes, view-node contract and mount primitives every dashboard here builds on
