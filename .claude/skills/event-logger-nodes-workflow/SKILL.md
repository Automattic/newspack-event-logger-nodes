---
name: event-logger-nodes-workflow
description: Implementation workflow for the newspack-event-logger-nodes application — adding job handlers, service CI verbs, MCP tools, dashboard React trees, topology files, WP-CLI verbs, and application Node subclasses. Use whenever the change lives under newspack-event-logger-nodes/ rather than the substrate runtime.
argument-hint: "[handler / verb / dashboard / node]"
---

# Event Logger Nodes Workflow

The application built on the newspack-nodes runtime. For substrate changes (Node, Router, Topic, Partition, Worker, Fleet, REPL, Tee, Tail, Consumer), use the `nodes-workflow` skill in newspack-nodes instead.

AGENTS.md carries the architecture decisions and key files; this skill is the procedural companion.

## When to Use

- Adding or changing a job handler (anything filtered onto `newspack_nodes/job_handlers` or `newspack_nodes/remote_job_handlers`)
- Adding a verb to one of the three service CIs (`App\Performance_CI_Node`, `App\Rules_CI_Node`, `App\Discovery_CI_Node`)
- Adding or changing a config field in `Settings_Schema`
- Exposing an existing verb to agents as an MCP tool (`App\MCP_Controller`)
- Adding a `wp nodes` verb under `includes/cli/`
- Touching the React dashboards (any tree under `src/`)
- Adding an application Node subclass (a `Request_Builder_Node`-style processor)
- Modifying topology files under `topologies/`
- Touching the profiler drop-in, `mu-plugins/00-newspack-profiler.php`
- Changing `Log_Manager`, `App\Core` (the hook, outbound-HTTP and query instrumentation), `Flame_Builder_Node`, `Stats_Store`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`, `Auto_Tuner_Node`, `Reqgrep_Core`, the brief pair behind the `ask` verb (`App\Findings` / `App\Ask_Assembler`), or the ruleset trio (`Rule` / `Rule_Set` / `Rule_Matcher`)

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
4. **Size discipline**: `Log_Manager::message()` fits the whole entry — `n`, the category as `k`, your data, a `ts` — inside `MAX_DATA_SIZE`, 3840 bytes, trimming or dropping `m` above it. A payload that can approach that goes through `\Newspack_Nodes\Job_Intake::queue( $handler, $id, $parameters, $key )` instead: `$id` is the optional per-job identity jobstats keys on, `$parameters` passes through to the handler, and `$key` is the optional partition-routing key. `queue()` is the auto-locked large-write path onto `jobintake`. `Job_Intake::feed()` takes the same four arguments onto the unlocked, PIPE_BUF-bounded `jobfeed` log, which `job-feed` and `job-spoke` tail in place of the whole firehose.

This plugin registers no handler of its own on either filter, and `Job_Router_Node` reads neither — it only normalizes job-shaped entries onto `jobs.log` for the worker to dispatch. For a timer-driven local handler, read the sibling `newspack-cache-cozy` plugin's `Cache_Cozy_Tick_Node`: a `Timer_Node` that hitchhikes the `_router` heartbeat, enqueues a job every interval, and registers its handler on `newspack_nodes/job_handlers`. This plugin's own three `Timer_Node` subclasses queue nothing on a tick: `Request_Builder_Node` rotates its in-flight cache, `Request_Flight_Node` emits the in-flight snapshot, and `Discovery_Collector_Node` mints one signed probe per spoke.

#### Adding a service CI verb

Endpoints are verbs on a service CI. The substrate's `/wp-json/newspack-nodes/v1/command` route is the door every verb comes through — POST one or more envelopes per request, each dispatched against the node its TO addresses, and one refusal in a batch answers 401 for the whole POST. `/wp-json/newspack-nodes/v1/messages/stream` is the GET SSE a streaming dashboard subscribes on; it carries frames, never verbs. The one REST route this plugin registers itself is the MCP server (below).

1. Pick the right service CI class (`includes/app/class-*-ci-node.php`). This plugin owns THREE, all mounted by `newspack_event_logger_nodes_mount_service_cis()` on `newspack_nodes/request_graph_ready`:

   | Class | Mount name | Verbs |
   |---|---|---|
   | `Performance_CI_Node` | `performance` | `overview`, `urls`, `url_detail`, `url_breakdown`, `request_search`, `request_grep`, `request_detail`, `ask`, `hooks_registered`, `set` |
   | `Rules_CI_Node` | `rules` | `list`, `save`, `upsert`, `delete`, `reset` |
   | `Discovery_CI_Node` | `discovery` | `get` |

   The substrate owns ten more (`Status_CI_Node`, `Settings_CI_Node`, `Aggregator_CI_Node`, `Vault_CI_Node`, `Workers_CI_Node`, `Raw_Logs_CI_Node`, `Topologies_CI_Node`, `Sessions_CI_Node`, `Classes_CI_Node`, `Layouts_CI_Node`) — add those verbs in newspack-nodes, not here.
2. Add a verb entry in that CI's `node_schema()['commands']` array: `name`, `capability`, `description`, `args` (per-arg `name` / `type` / `required`, plus an optional `default` or `description`), and an inline `handler` closure.
3. **`capability` is the whole gate.** `Service_CI_Node::commands()` wraps every handler in `Capabilities::require()` for the role the schema names, on install rather than at dispatch, so a table replaced after construction is gated too. Declare `Capabilities::READ` for a dashboard slice, `Capabilities::TUNE` for a ruleset or settings write. An undeclared verb defaults to `MANAGE` — strictest, not loosest. Never re-gate inside a handler: a hard-coded check silently outranks the declaration. `tests/unit/VerbRoleDeclarationsTest.php` pins a declared-role map across all three CIs — every `rules` and `discovery` verb, and every `performance` verb but `url_breakdown` and `ask` — and fails the build on a `require_manage_options()` left anywhere in `Performance_CI_Node`. Add your verb to that map.
4. Handler signature is `static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array`. `$args` is a **token array** (`list<string>` argv), not a space-joined string. Parse positional and option args via `Command_Args::parse( self::arg_strings( $args ) )`; `arg_strings` is the base `Command_Interpreter_Node` helper normalizing the token array to `list<string>`. A verb wanting the payload as one blob reads `self::arg_strings( $args )[0]` — how `rules` `save` and `upsert` take their JSON argument. `node_schema()` is static, so state arrives through `$self`.
5. Throw freely — the substrate wraps a thrown error as `TM_COMMAND|TM_ERROR` along the FROM trail. Reserve `return 'error: ...'` for canonical-OK-shaped argument validation.
6. Keep a stats read fail-soft — `null`, `[]` or `false`, never a throw. Slot-pool ops are the deliberate opposite; see Patterns below.

#### Adding a config field

Declare one `Field` in `Settings_Schema` (`includes/class-settings-schema.php`), the single declarative source from which the Config overlay keys, the admin register/render loop and the worker-restart classification all derive. Nine keys are declared there, each WITH its default: three rendered checkboxes (`enable_logging`, `log_memory`, `flush_every_line`) and six overlay-only (`allowed_users`, `rules`, `hook_start_priority`, `custom_colors`, `stats_mirror_node`, `recommended_log_events`). Per-URL logging settings are not fields here — they live in the ruleset.

A field's `restart:` key holds `[]` for nothing, `'all'` for every active topology, or CONSUMER NODE-TYPE tokens — the substrate's own schema uses `['Partition','Topic','Log']` — which `Restart_Planner` matches by ancestry and resolves to the live topologies running such a node; they are NEVER topology names, which drift. All three checkboxes declare `'all'`, because `Log_Manager` caches them in its singleton for the process.

Mirror the new key and its default as a commented entry in `newspack-event-logger-nodes-config.php`, the deployment override ledger: `tests/unit/ConfigSchemaTest.php` parses those comments back into an array and holds them to `Settings_Schema::get()->defaults()`. A key the hub must also push to its spokes takes one `cmd settings-sync:config add_setting <local_option> performance <remote_option>` line in `hub-control.tsl` — `rules`, `log_memory` and `flush_every_line` are the three pushed today — and `newspack_event_logger_nodes_resolve_settings_sync_value()` is where a value needs transforming on the way out, as the ruleset does to inline its pointer hooks. Read the key through `Config::value()`, which throws `unknown config key` rather than falling back.

#### Touching the per-URL logging ruleset

Logging is per-URL, never global: there is no `log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`, `auto_disable_threshold` or `auto_protect_time_threshold` setting, and `tests/unit/RetiredConfigKeysTest.php` fails the build on any of the seven reappearing in the schema or a config fixture. What governs instead is an ordered list of `Rule`s (`includes/class-rule.php`), each a URL pattern with a `log` or `skip` action and — for `log` rules — its own hooks, custom events, significant events, auto-tune thresholds, and four diagnostic flags: `log_http` (default **true**, so absent means on), `log_queries` (false; needs `SAVEQUERIES` and costs two entries per query), `trace_hooks` (false; one shallow backtrace per firing, naming the caller on the flame's aggregation label) and `trace_callers` (0 = off; a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT`, 20).

`Log_Manager` builds a `Rule_Matcher` (`includes/class-rule-matcher.php`) per request and resolves the ONE governing rule. Ranking is by SPECIFICITY, not length: query-bearing patterns (`/jobs/x?job-work`) outrank exact paths (`/about?`), which outrank prefixes (`/blog`); length breaks ties only WITHIN a rank, so this is **not** longest-prefix-wins. Matching is case-insensitive, a prefix matches by string rather than path segment (`/wp-cron` governs `/wp-cron.php`), and no match means skip: zero hooks bound and nothing written to the firehose, so no record and no stats key ever follow. Empty means empty; there is no implicit `/` log-all baseline.

- Durable state lives in `Rule_Set` (`includes/class-rule-set.php`). The rule LIST always rides the autoloaded `newspack_event_logger_nodes_rules` option; a heavy rule's hooks (past `INLINE_HOOK_LIMIT` = 100) tier out to a non-autoloaded `newspack_event_logger_nodes_rule_hooks_<id>` option, warm-mirrored through a substrate `Table_Node` on `'eln-rule-hooks'` with a 3600s TTL, read through to the option by `backed_by()`. The Table scopes keys per install, so two sites sharing one memcached cannot hand each other the same pattern's hooks. Every write MUST go through `Rule_Set::save()`, which re-tiers, reconciles orphans and signals a worker reload — never raw `update_option`.
- A rule's id is `Rule_Set::id_for( $pattern )`, the pattern's `Log_Manager::url_hash()`. The stored id is always minted from the pattern — `Rule_Set::rekey_by_pattern()` runs on the config seed, the `save` verb and `apply_synced()` off the wire — so one pattern has exactly one id. `upsert` reads an incoming id for one purpose only: finding the entry a renamed pattern replaces.
- `Rule_Set::hooks_for()` is static and stateless. `Log_Manager` already loaded the ruleset this request, so never `load()` a second `Rule_Set` to reach a rule's hooks.
- Editing goes through the `Rules_CI_Node` service CI. The full rules-editor UI (`src/rules/RulesAdmin`) mounts from `src/settings/index.js` into the settings page's `#event-logger-rules-editor` "Logging Rules" container, not a separate submenu. The performance dashboard (`src/overview/PerformanceDashboard.js`) reuses `src/rules/RuleEditModal` for the inline "Log this URL" editor on the URL-detail view, driving the same CI's `list`, `upsert` and `delete`.
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
2. The plugin's main file owns both halves of a page. In the `admin_menu` closure `add_menu_page` places the top-level Performance page and a matching `add_submenu_page` repeats it as the first submenu, then the `$dashboards` map adds the other three and prints each mount div; the `admin_enqueue_scripts` closure's `$page_to_tree` map then turns `?page=<slug>` into a `build/{tree}` bundle, and an unlisted slug enqueues nothing. The whole menu sits behind `Admin::current_user_allowed()` — `manage_options`, narrowed to the logins `allowed_users` names when that key is non-empty — so a new dashboard is invisible to anyone that list excludes. A page whose tree renders `<DebugOverlay>` also needs its slug added to `Current_Request_Overlay`'s `OVERLAY_PAGES` list, which carries the four that do; the substrate's `devtools_tab_bundles` filter covers only the Nodes hub page, so an unlisted slug renders the overlay without the Request tab. `current-request` is the exception among the trees — it is a devtools tab rather than a page, registered through that same `Current_Request_Overlay`.
3. Import from the substrate, never a local copy. Use `@wordpress/element` (not direct React) and `@newspack-nodes/runtime` for substrate JS primitives; shared hooks, helpers, nodes and components come from `@newspack-nodes/shared/...`, and there is no local `src/shared/` and no sync step. Styles work the same way: `src/styles/base.scss` `@forward`s the substrate's `tokens` and `mixins`, each tree's own `styles/base.scss` `@use`s that one file, and a local `_tokens.scss` would be a byte-for-byte copy. `lint-styles.mjs` then fails a component that gives a canonical control its own appearance, with `styles-ok:` and a reason as the narrow opt-out. The two ELN-owned test helpers are the exception, in `src/test-helpers/`. `renderHook.js` exports four things. `renderComponent( element )` mounts any element into a real DOM node and hands back the container, so assertions run on plain DOM queries; it is what most of the suite uses. `renderHook( useHook, { initialProps } )` mounts a bare hook inside a throwaway wrapper that renders null and reassigns the hook's return value onto `result.current` on every render — destructure it once and you hold the first render's value while the hook moves on. `waitFor( check )` polls an assertion, because a command rides the router tick rather than resolving on a microtask. `cleanupMounts()` unmounts every root `renderHook` opened, which a suite whose components own timers or SSE pollers calls in its own `afterEach`; it tracks nothing `renderComponent` mounted, so such a test still owns its `unmount()`. `cascade.js` compiles a stylesheet and resolves which declaration wins on a rendered element — the question the SCSS source cannot answer — but it skips every rule sitting inside a `@media` block, because jsdom reports no viewport and honouring the query would invent a win no browser agrees with. Assert a breakpoint through bare `specificity()` and `compareSpecificity()` comparisons instead, as `src/__tests__/layout-cascade-contract.test.js` does against WordPress core's mobile and desktop button geometry.
4. A new `window.*` payload PHP prints needs a declaration in `types/globals.d.ts`, or every read of it is absent from `Window` and `npm run lint:types` fails. The alternative is an inline cast at the single reader, which is what `eventLoggerDashboards` and `eventLoggerCustomColors` take.
5. Pick the graph shape by how the dashboard gets data:
   - **Polled / command-fanout** — `useBatchedPoll( { build, timerName, teeName, intervalMs } )`. It owns the exospine mount, `_shell` (the observe-only Tap), `_http` (the POST `/command` egress), the fan-out `Tee`, the router-hitchhiking `Timer` and the page-visibility gate. The `build( { interpreter, tee } )` callback adds ONLY the dashboard's own nodes, typically one `addSliceFetcher` per slice. Reference: `src/overview/hooks/usePerformanceGraph.js`; the smallest example is `src/settings/hooks/useHookCatalogGraph.js`.
   - **Live stream** — the substrate's `useStreamGraph`, which mounts `<prefix>:link` (a `RemoteLink` composing SSE ingress, `_http` and the `_heartbeat` slot keep-alive), `<prefix>:stream` (a pass-through Tee) and `<prefix>:view`. `src/hooks/useGlobStreamGraph.js` wraps it for the two glob dashboards; the gyroscope calls it directly.
   - **One-shot verbs** — `useCommandOnce`, which parks arguments in a Fetcher's outbox and rides the same tick. Reference: `src/rules/useRulesGraph.js`, one scope per mutating verb.
6. Target the egress path with `egressPath( ci )`, which spells `_shell/_http/<ci>`. Addressing `_http/<ci>` directly delivers just as well, which is what makes skipping the Tap silent — `connect _shell` stops seeing traffic that no longer passes through it.
7. **Never correlate a reply.** The server echoes `TO = FROM`, so a reply lands on the node that minted it, and its VALUE carries the verb and arguments it answered. A pending Map keyed by `message[ID]`, a resolver pair, a minted op-id or a KEY demux each re-derive that by hand; `scripts/lint-contract.mjs` fails the build on all of them (ADR-7). Sending several verbs in one tick means more nodes, never one node telling replies apart.
8. Declare view nodes rather than writing them. `registerSliceViews` (from `@newspack-nodes/shared/nodes/slice-view-node`) takes `{ empty, parse, json, description }` per view and returns the classes; the dashboard's node module exports them as `views` and merges them into `CommandInterpreterNode.includeNodes`, which is what lets the console palette resolve `make_node <Type>` by name. Set `json` only for a verb answering a JSON string — every `performance` and `rules` verb answers a PHP array, so no declaration here sets it. Hand `makeNode` the CLASS, never the name: `includeNodes` is a per-bundle static, and another bundle's interpreter cannot resolve a name registered here (ADR-16). `SliceViewNode` already handles the failure modes — a TM_ERROR keeps the slice on screen and adds `error` while clearing `loading`, and an unparseable payload leaves the prior slice untouched. Subclass only for a view that owns more than its slice: its own `fill()`, a timer, a teardown. Nothing here subclasses it — the two glob dashboards subclass the substrate's `LogStreamViewNode`, the gyroscope writes a plain `Node`, and `src/overview/nodes/url-detail-merge-node.js` is a transform on the edge INTO a view rather than a view. A written class reaches the palette through `CommandInterpreterNode.registerNodeClasses`, the same merge `registerSliceViews` makes: its return IS the module's `views` in the three stream dashboards, and is spread beside the declared classes in `src/overview/nodes/register.js`.
9. Drive loading, clear and error states through the view's `controlFrom`, never by payload shape: a control is recognised by WHO SENT IT, so a reply carrying an `action` field is still a reply.
10. Keep REPL mounts (`_output`, `_completion`, `_uptime`, `_cwd`) off a production dashboard tree — they belong to the console tree and collide with the debug overlay's REPL.

#### Adding an application Node subclass

1. Create `includes/class-{name}.php` with `class Foo_Node extends \Newspack_Nodes\Node`, overriding `fill()`. A node that also runs on a cadence extends `Timer_Node` and overrides `fire()` as well, as `Request_Builder_Node`, `Request_Flight_Node` and `Discovery_Collector_Node` do. Every node class ends in `_Node`, and the shell name is the class minus `_Node`.
2. **No registration.** `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', … )` registers the namespace once, so `make_node Foo` resolves `\Newspack_Event_Logger_Nodes\Foo_Node` and the palette scans the classmap. Run `composer dump-autoload -o` (or `composer build:autoloaders`) after adding the file.
3. Declaring your own `node_schema()` opts the class into the console catalog, and `tests/unit/AppNodeSchemaCoverageTest.php` then demands a non-empty `category` and a `description` on every constructor argument — each argument becomes a field in the topology console, and a missing description is a blank tooltip.
4. Order the members newspaper-style: the trait-`use` block, the fields, then `__construct`, `arguments`, `fill`, `fire_cb`, `fire`, the call-graph middle in topological order, and `node_schema` last. `pre-commit` runs `php scripts/reorder-node-methods.php --check` on every staged PHP file and fails the commit on a class out of order; `--write` applies the moves, after which run `phpcbf`. `scripts/reorder-node-methods.js` is the twin gating `src/`. Both tools reorder EVERY top-level class in a staged file, not only Node subclasses, and both classify by NAME rather than by real inheritance: a class called `Node`, a class suffixed `_Node` (the JS twin matches any name ending in `Node`), or a class extending either one takes the order above. Every other class takes the generic policy — the constructor first, then a topological order of the class's own call graph, so no method prints above something it calls, with public methods emitted ahead of `_`-prefixed helpers among the ones free at the same moment. Name a plain helper class `*_Node` and the node order applies to it whatever it inherits.

#### Adding or changing a topology

A `.tsl` under `topologies/` is the wiring: `make_node Foo foo` instantiates the node, `connect_node foo next-step` wires the sink, `disconnect_node foo` drops a sink an `include` already set — how `complete` re-points its firehose Consumer at a Tee — `cmd foo:config <verb> [args…]` runs a config verb, `include <name>` composes another topology, and `secure` closes every stock file by climbing the interpreter's security level — a level that never descends, each step dropping more management verbs. The substrate's `Topology_Loader` interprets the script once per partition. The topology name is the FILENAME — there is no `name:` key — and `var` frontmatter is read from the top-level file only, which is why `hub-control` pins `num_partitions = 1` in its own file rather than in the `settings-sync` it includes. `<eln:KEY>` tokens resolve through `Config::resolve_eln_token()`, which owns three — `is_hub`, `stats_mirror_node` and `stats_mirror_lifetime` — while `<config:KEY>` resolves through the substrate's own namespace.

A `with_index <name>` names a formatter the substrate's `Formatters` registry must already hold, and TSL carries no closures, so this plugin registers its three in the deferred bootstrap of `newspack-event-logger-nodes.php`: `request-index`, `flame-index` and `stats-index`. A fourth index shape takes a fourth `Formatters::register()` call there.

Two suites hold every file in the directory to a shape:

- `tests/unit/TopologyShapeTest.php` — a Consumer feeding a stateful node must name exactly that node in `add_snapshot_node`, or the node's state dies on respawn; every Consumer offsetlog must carry `<topology>` and be unique within the file; every directive and every wiring target must reference a node the file declares; and a `with_index` name must resolve through `Formatters::resolve()`, or the partition writes no index and every index-driven read reports not-found. Five more bind a node type to its wiring: a `Flame_Builder` wires both `flames:partition` and `flame-stats:partition`; the `complete`, `performance` and `flame-builder` graphs keep `flame-stats` downstream of it, and the first two keep request-builder's `completed:tee`, `errors:partition` and `gyroscope:partition` branches; `set_stats_target` takes the `<eln:stats_mirror_node>` token rather than a hardcoded node; a `Job_Router` reaches disk through `Age_Sieve`, which owns the staleness bound; and a `Request_Builder` sets its completed, errors and inflight targets. One further test, `test_shape_guards_are_not_vacuous`, counts the Flame_Builders, the `set_stats_target` verbs, the Consumers and the consumers reaching a stateful node, so a renamed node type or verb token cannot disable a guard by matching nothing.
- `tests/unit/TopologyDurabilityTest.php` — every stock topology declares a cursor and a dead-letter quarantine, audited by the substrate's own `TopologyDurability` helper against each node's `node_schema()`.

#### Adding a CLI command (`wp nodes <verb>`)

1. Live under `includes/cli/class-<verb>-command.php`. Register in the `WP_CLI` block of the deferred bootstrap in `newspack-event-logger-nodes.php`.
2. Validate inputs at the boundary, and refuse rather than coerce — `Command_Args::option_int()` returns null for a malformed flag so each layer reports it in its own voice.
3. **Make blocking work injectable.** A command that reads stdin in a loop, sleeps between iterations, or polls a file takes the resource or the iteration count as a parameter so tests can drive it deterministically. Two distinct seams in `Reqgrep_Command`:
   - `process_stdin( $stream = null )` — stream-injection seam; defaults to null (resolving STDIN inside), tests pass a `php://memory` resource.
   - `follow_mode( int $max_iterations = PHP_INT_MAX )` — iteration-cap seam; production passes the default, tests pass a small number.

   Keep them separate; don't fold the cap into the stdin reader.
4. Reading and rendering belong to the command; grouping does not. Every read path in `Reqgrep_Command` funnels lines into `Reqgrep_Core::push()`, the same engine the `performance` CI's `request_grep` verb constructs, so the two agree line-for-line on which lines belong to which request and when it is complete. A change to the grouping goes in `Reqgrep_Core`, never in one caller.

#### Touching the profiler drop-in

`mu-plugins/00-newspack-profiler.php` is a standalone mu-plugin, not autoloaded plugin code: the release attaches it beside the zip, an operator copies it to `wp-content/mu-plugins/`, and deleting the file removes it whole — it writes no option and no durable state. Only an mu-plugin runs early enough for the two facts it captures: WordPress times no plugin's load, and by the time `Log_Manager` writes its first line the request's real start is long past. So the drop-in takes `hrtime()` and `microtime()` at file scope into the `$newspack_profiler` global and times each site-activated plugin off `plugin_loaded` at priority 1. Must-use and network-activated plugins announce on hooks it deliberately does not bind.

Two contracts tie it to this plugin, and both fail quietly when they move:

- `Log_Manager`'s constructor adopts `request_time` and `request_ts` from that global and unsets them, which stamps `process (start)` with the moment PHP began rather than the moment the logger emitted, and stops a nested job context claiming them twice.
- The flush runs on `plugins_loaded` at priority **-10001**, one below `hook_start_priority`'s -10000 default, so the plugin-load spans precede what `App\Core` writes for `plugins_loaded`. Each row goes out as a `(start)` / `(complete)` pair carrying its OWN `ts`, because the flush moment would collapse the whole loading phase onto one instant. Reaching `Log_Manager` that early depends on this plugin requiring its Composer autoloader at file scope, ahead of its own deferred `plugins_loaded` 11 bootstrap.

#### Removing dead code

`composer.json` maps `includes/` as a classmap, so the autoloader resolves every in-plugin class on demand and a `class_exists()` guard around one is always true. Delete any you find while editing; don't leave them as "defensive". The same applies to in-substrate `class_exists()` guards inside newspack-nodes. Out-of-plugin and optional-dependency guards stay — `class_exists( '\Newspack_Nodes\Bootstrap' )` in the bootstrap is the load-order gate, and the substrate's `class_exists( '\Memcached' )` tests for a PHP extension.

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
npm run lint:shell   # shellcheck over pre-push and scripts/*.sh
npm run test:js      # jest
npm run lint:deadcode     # = lint:phpstan — level 10, strict rules AND dead code
npm run lint:deadcode:js  # knip over the JS
bash scripts/lint-docs.sh              # doc-vs-runtime drift, prose included
bash scripts/check-substrate-floor.sh  # is the declared floor high enough?
```

`lint:deadcode` is an ALIAS of `lint:phpstan`, and one run is larger than either name says: `phpstan-deadcode.neon` includes `phpstan.neon.dist`, so it is PHPStan level 10 with `phpstan-strict-rules` over `newspack-event-logger-nodes.php`, `mu-plugins/` and `includes/`, plus ShipMonk's detector on top. Through `docker exec` it dies before analysing anything, because the containers mount `/services` read-only and it cannot write its `tmpDir`.

Both dead-code audits gate `pre-commit` on staged files, and both exclude tests as consumers, so an export only its own test imports reads as dead. Verify the call path before deleting anything either tool names — most findings are live by hook, by reflection, by `.tsl` topology or from JS. On the JS side mark a deliberate one `@testonly` in its docblock, which `knip.json` reads as a tag; the PHP side has no such tag, and a false positive is silenced in `phpstan-deadcode.neon`'s `ignoreErrors`. knip cannot parse JSX in a `.js` file, which drops that file's `import()` expressions, so every `lazy( () => import( './X' ) )` target is an `entry` in `knip.json`.

Five gates run on every push, docs-only included: the jest suite with coverage, its per-file 90% gate, `scripts/lint-docs.sh`, `scripts/check-substrate-floor.sh` and dndocker's `tools/check-firehose-parity.py`, which holds `Log_Manager` and the Perl `Gyrobase::Log` to one wire contract — the 34-key `ENV_ALLOWLIST`, `ENV_VALUE_MAX` (256), the U+2026 elision marker and `URL_REDACT_PATTERN` are hand-maintained copies in two repos, and only dndocker sees both. It also refuses an allowlisted key that reads as a secret. The floor check and the parity check skip cleanly when the checkout each needs is absent.

`pre-push` runs the right subset for the file types in the push range, so pushing is the gate — don't duplicate it by hand.

### Phase 4: Reload running workers

Workers cache loaded classes for their process lifetime, 595 seconds — `DEFAULT_MAX_RUNTIME` on the substrate's `Cooperative_Stop` trait, which PHP reads only through a using class such as `Worker_Base`. After deploying, restart the relevant worker groups so the new bytecode lands. A restart target is an ACTIVE topology name, validated against the live worker rows, so `wp nodes types` and `wp nodes status` are the source of truth — never a hardcoded list.

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

The **substrate** registers every runtime verb under `wp nodes` — `types`, `run`, `start`, `stop`, `restart`, `status` (aliased `ls`), `activate`, `deactivate`, `gc`, `doctor`, `ingest`, `scaffold`, `memcache get|flush`, `caps`, `hub-user` and the attached `cli` REPL. This plugin registers two, in the `WP_CLI` block of `newspack-event-logger-nodes.php`: `wp nodes reqgrep` (`Reqgrep_Command`, the application-aware firehose filter) and `wp nodes ruleset-bench` (`Ruleset_Bench_Command`, the sweep `Rule_Set::INLINE_HOOK_LIMIT` is calibrated from — measurement only, off the request hot path, and it never touches the live ruleset).

### Phase 5: Live-verify

For changes touching the request-logging pipeline:

```bash
# Hit any URL on the site to generate firehose entries.
curl -sk "<site>/" -o /dev/null -w "HTTP %{http_code}\n"

# reqgrep with --recent reads the second-to-last segment forward.
wp nodes reqgrep --recent | head -10

# reqgrep can also follow live (Ctrl-C to stop).
wp nodes reqgrep --follow

# Requests that reached neither `process (complete)` nor `process (aborted)`.
wp nodes reqgrep --incomplete
```

For dashboard changes: open the page and verify the panels render; the browser DevTools network tab shows the `/command` traffic. Telemetry dashboards land at `/wp-admin/admin.php?page=event-logger-*` (`event-logger-overview`, `event-logger-errors`, `event-logger-gyroscope`, `event-logger-requests`); the settings tree, which carries the rules editor and the hook catalog, is an `add_options_page` and lands at `/wp-admin/options-general.php?page=newspack-event-logger-nodes`. The substrate's own console is the top-level "Nodes" menu, whose panels are devtools tabs — this plugin contributes `current-request` there.

For job handler changes: queue a job through the legitimate caller, wait, check `wp nodes status` for the job-worker heartbeat, and reqgrep for the rid.

## Patterns That Trip People Up

- **Hub vs spoke**: there is no operator hub toggle. `enable_workers` and `enable_aggregator` are not config keys, and `tests/unit/RetiredConfigKeysTest.php` fails the build on either reappearing in a config fixture. `Config`'s `<eln:is_hub>` resolver reads the ACTIVE topology set and fires on either of two signals: a topology named `aggregator` or including one, and any active graph carrying a `Remote_Source` node. Neither covers both shapes. The stock `aggregator` ships no `Remote_Source` — the operator wires those on the console canvas — so only its name gives it away, while a renamed fork of that file answers to no name the first signal knows, so only its wired readers do. Match on the name alone and such a hub reads as a spoke. `hub-control` is separate again: it carries the settings and discovery fan-out, as the substrate's `Settings_Sync_Node` graph plus this plugin's `Discovery_Collector_Node`. Missing consumers are the structural gate: with no `hub-control` and no per-spoke `HTTP_Out` wired, a recorded settings event is tailed and dropped.
- **A cache backend is required.** `Stats_Store` (driven by `Flame_Builder_Node`), the SSE slot pool and the command sessions every dashboard and MCP verb authenticates against all reach it through `Cache_Backend::shared_first()`, which prefers the configured memcached and falls back to APCu. With NEITHER, the stats path goes fail-soft — `Stats_Store`'s private per-role `table()` accessor returns null, every method reads that as a miss, and the dashboards show no data — while the slot pool fails closed (429) and `Command_Auth::store_session()` refuses to mint at all. Don't unify the first two: the asymmetry is deliberate.
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
