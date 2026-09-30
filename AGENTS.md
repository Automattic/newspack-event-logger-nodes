# AGENTS.md — Newspack Event Logger Nodes

The application layer of the event logger, built on `newspack-nodes` (the runtime substrate). Request-lifecycle logging, flame-graph generation and hub/spoke aggregation all run as Nodes, and the dashboards stream live over the substrate's SSE endpoints, whose server side this plugin does not own.

This plugin owns `Log_Manager`, `Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`, `Stats_Store`, the per-URL logging ruleset (`Rule` / `Rule_Set` / `Rule_Matcher`), the `App\*_CI_Node` service CIs (`performance`, `discovery`, `rules`), the React dashboards and the topology files. Node subclasses carry a `_Node` suffix; helpers like `Log_Manager` and `Stats_Store` don't.

Three things live in the substrate and have no alias here — call the substrate class directly:

- **Hub fan-in** is `\Newspack_Nodes\Remote_Source_Node`.
- **`Job_Worker_Node`**. This plugin keeps only the request-context glue, `Log_Manager::begin/end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`.
- **`Job_Intake`** is `\Newspack_Nodes\Job_Intake`. `job-router.tsl` and `job-feed.tsl` each `include job-intake` and add their own router leg, so the substrate's conflict gate refuses co-activating its stock `job-intake` with either.

This plugin builds no cache of its own. Keyed stores use the substrate's `Table_Node`, whose keys are scoped per install. The flame builder's stats live in three SQLite Tables `flame-builder.tsl` declares, reached by message through `Table_Client`; `Rule_Set`'s large hook lists and the `urls` verb's page cache keep an `auto` `Table_Node::table()`, memcached else APCu. `Flame_Builder_Node::apply_auto_tune()`'s cross-worker lock is an `add()` on `Cache_Backend::shared_first()`, the same tier.

## Plugin Load Order

WordPress loads plugins alphabetically, and `newspack-event-logger-nodes` sorts BEFORE `newspack-nodes` (`-event-` < `-nodes`), so `\Newspack_Nodes\Node` is NOT available at this plugin's file-load time.

So `newspack-event-logger-nodes.php` defers everything that touches a substrate class — the two WP-CLI commands, the `Config::RESET_ACTION` cache-reset listener, the job-context hooks, the verb-span seam (`Command_Interpreter_Node::$around_dispatch`, which `Diagnostics_Bridge::install()` wraps around any wrapper already there), the `Topology_Registry` mount, the `App\` CommandInterpreter namespace, the `<eln:>` config-token resolver, the two named TSL formatters, the `newspack_nodes/settings_sync/value` resolver, the MCP route, `App\Core`, and in admin the settings page and the current-request overlay — to a closure on `plugins_loaded` priority 11. That bootstrap is version-gated, not merely presence-gated: it checks `class_exists( '\Newspack_Nodes\Bootstrap' )` AND `Bootstrap::version_at_least( '2.77.0', … )`. A substrate below the floor leaves an admin notice naming both versions and the plugin goes dormant; a missing substrate, or one predating `version_at_least()` itself, returns silently. `Requires Plugins: newspack-nodes` keeps the substrate active on WP 6.5+ but says nothing about its version, and WordPress does not order plugin updates, so this plugin really can land ahead of the substrate it needs.

2.73.0 is `Table_Client`, `Bootstrap::mount_table()` and the SQLite Table arm the stats live on; below it the builder and the dashboards fatal. It is also the TTL and backend arguments `flame-builder.tsl` gives each Table, which `check-substrate-floor.sh` cannot see, because it reads PHP calls and not topologies. 2.74.0 is `Table_Unavailable`, the typed refusal of a Table whose backend cannot open on this host, and the public `Durable_Arm::serializer()`. 2.75.0 is the read-only mount the `performance` verbs read through, which lives for the rest of the request, opens only a file its worker wrote and refuses a process running as root. 2.76.0 is `SADD` and `SMEMBERS` on a durable Table, with `Table_Client::add_members()` and `members()`, which the search index files and reads its words through. 2.77.0 is `Command_Interpreter_Node::dispatch()` binding each verb's declared args and handing the handler them by name, which every handler here reads; it names no new method, so `check-substrate-floor.sh` cannot see it either. Why each earlier floor was raised is in the CHANGELOG entry that raised it.

Raise the floor by hand whenever a new hard requirement appears — `bump-version.sh` repins `release.yml`, not this — and two gates cover the two ways it drifts. `scripts/lint-docs.sh` rule 6 holds every `version_at_least` mention in `README.md`, `AGENTS.md`, `docs/` and `.claude/skills/` to the 2.77.0 the loader enforces, line by line. `scripts/check-substrate-floor.sh` resolves each substrate API PHPStan sees this plugin call to its DECLARING class, binary-searches the substrate's tags for the first one carrying it, and takes the maximum — a floor set too LOW is the failure it exists for, because the handshake then passes and the plugin fatals later. Priority 11 is intentional; don't lower it. Tests bypass this and require the runtime explicitly in `tests/bootstrap.php`.

## Workflow discipline

`~/.claude/rules/workflow-discipline.md` governs every code-writing turn: TDD first, the gauntlet, no subagent commits, and the literal subagent phrase. Here, "runtime config fails loud" means reading every key through this plugin's `Config::value()`, which throws on a key the shared substrate registry does not declare.

## Code Style

WordPress VIP Go, enforced by `phpcs.xml.dist`: `snake_case`; Yoda conditions; `[]` arrays, arrow functions and spread allowed; tab indent, spaces inside parens; PHP 8.2+ with constructor property promotion where it shortens. Conventional commits.

## Build / Test

The substrate checkout must sit at `../newspack-nodes`, because the build kit and shared JS resolve through it. A fresh clone runs `npm install`, `composer install` (which also sets `core.hooksPath`) and `npm run build` once. After adding or renaming a Node class, regenerate the classmap that `make_node` and the console palette read with `composer dump-autoload -o`.

```bash
# PHPUnit, as a NON-ROOT user (Log_Manager refuses root), with newspack-nodes active.
# Use the vendored binary; the one-second per-test budget fails the run on a breach.
cd tests && ../vendor/bin/phpunit --enforce-time-limit
tests/run-coverage.sh

# Host-side gates. PHPStan cannot run through `docker exec`: /services is read-only there.
npm run lint:php            # phpcs, comment gate
npm run lint:js             # eslint, comment gate, contract lint
npm run lint:scss           # stylelint, appearance-ownership gate
npm run lint:types          # tsc over the JSDoc types
npm run lint:shell          # shellcheck
npm run test:js             # jest
npm run lint:phpstan        # level 10, strict rules and dead code (= lint:deadcode)
npm run lint:deadcode:js    # knip
```

A test must not wait in real time: it pins `Core::$now` here, or the substrate's `Core::$clock` and `Event_Framework::$sleep`, and never buys time with `#[Medium]` or `#[Large]`. Read dead-code findings rather than obeying them — most are live by hook, reflection, `.tsl` or JS — and apply the test-only rule in `~/.claude/rules/test-seams.md`. The `event-logger-nodes-workflow` skill, Phase 3, carries the rest.

`scripts/pre-push` is the gate, so don't repeat it by hand. It runs jest with its per-file 90% gate on every push and scopes the rest to the file types pushed; PHP takes phpcs, a container deploy, the PHPUnit coverage suite and a per-class 90% gate. Four checks run on every push, docs-only included: `scripts/lint-docs.sh`, `scripts/lint-eln-docs.sh` (every `Stats_Store::NS_*` constant has its row in the architecture guide's stats schema table, and vice versa), `scripts/check-substrate-floor.sh`, and dndocker's `tools/check-firehose-parity.py`. The last two skip when the checkout they need is absent.

### Git hooks

Hooks are the tracked `scripts/pre-commit`, `commit-msg` and `pre-push`, reached through the `core.hooksPath` that `composer install` sets; a clone that never ran it has no hooks. `pre-commit` first runs `sync-shared-scripts.sh`, which refreshes the vendored tooling from `../newspack-nodes/scripts/`, so **edit shared scripts there, not here.** Only `build.mjs`, `pre-push`, `lint-eln-docs.sh`, `render-diagram.sh` and `bump-version.sh` belong to this plugin. `pre-commit` then runs `lint-staged`, which scopes each gate to the staged file's type and runs `reorder-node-methods --check` on every staged PHP and JS file. Its `fix-blank-lines.php` step is the one gate that REWRITES what you staged: it collapses runs of blank lines and still exits 0.

## Versioning & Release

`/release-event-logger` is the runbook. Never edit the version by hand: `./scripts/bump-version.sh <version>` rewrites the `Version:` header, the `NEWSPACK_EVENT_LOGGER_NODES_VERSION` constant and `package.json`, syncs `package-lock.json`, and repins the substrate `ref:` in `release.yml`, refusing a substrate version with no local tag. Pushing a `v<major>.<minor>.<patch>` tag runs the Release workflow, which builds through `build-release.sh` and attaches `newspack-event-logger-nodes.zip` and `00-newspack-profiler.php`.

A stale pin still builds, so **a green Release workflow proves nothing about which substrate got bundled**. Release the substrate first and this plugin second, then verify the published asset: `gh release download v<version> -R Automattic/newspack-event-logger-nodes -p '*.zip'`, unzip, and `diff -rq` its `build/` against the local one. A release that needs a newer substrate at RUNTIME raises the loader floor instead (see Plugin Load Order).

## Architecture Decisions

Each is intentional, stated in full in [`docs/architecture-decisions.md`](docs/architecture-decisions.md) under `## Decision N: <title>`, and cited in code, docblocks and the guide as "decision N".

| # | Decision |
|---|----------|
| 1 | Namespaced memcache schema |
| 2 | Sums, not means |
| 3 | Stats fail soft |
| 4 | Settings fan-out is a node graph; no consumer means a silent no-op |
| 5 | A stats schema change is migrated by the tables flush, and by nothing in this code |
| 6 | `get_multi` batching is essential |
| 7 | Job_Intake for >4KB jobs, the firehose for ≤4KB |
| 8 | A spoke's job is re-keyed `remote_job` when the hub pulls it |
| 9 | A request's partition is its id, hashed by the substrate |
| 10 | The durable mirror writes a bucket once, when it closes, under a key that carries no install scope — superseded by decision 34 |
| 11 | The open bucket is durable in the OFFSETLOG until it closes, and the carry is capped — superseded by decision 34 |
| 12 | A stat times the request; a flame value is a rendering artifact |
| 13 | A sequence-break marker is missing detail, not idle time |
| 14 | A server scope the key cannot carry rides inside the value, and is applied as a PROJECTION — superseded by decision 30 |
| 15 | The `urls` verb owns every URL-set fact; `overview` owns the site |
| 16 | The breakdown panel is always mounted, and says which kind of nothing it has |
| 17 | The URL index is stored at TWO resolutions, and the coarse one is DERIVED |
| 18 | A stored value may be POSITIONAL, and then its indexes are named constants |
| 19 | A request is filed in the bucket it FINISHED in, and a write into a folded hour reaches its fine bucket and the hour key |
| 20 | Outbound HTTP is timed as a span, and a short-circuited request opens nothing |
| 21 | The fold keeps the CLOSE of any span the kept head left open |
| 22 | Query spans are the same pair as decision 20, but PER-RULE |
| 23 | A span says how long, never who asked. Two knobs answer the second question, at two prices |
| 24 | A duration nobody measured is not a timing sample |
| 25 | Read tools and TUNE-scoped write tools share one MCP session, and every tool result is fenced |
| 26 | A merged transport node keeps the statements it ran |
| 27 | The platform's requests to itself are worker traffic, named by path |
| 28 | The writer ranks each bucket and indexes each name, and the page reads those rather than the index |
| 29 | Every reader dates from the tick, and a reply reads it once |
| 30 | Every memcache value is one server's, carries nothing its key implies, and fits one item |
| 31 | A snapshot node's replay is idempotent by the last folded crumb |
| 32 | A builder tells its upkeep on its own worker record, and every narration write is a guarded forward |
| 33 | No dashboard reads the mirror; the flame builder sweeps it back into memcache — superseded by decision 34 |
| 34 | Stats live in SQLite Tables, and nothing repairs a loss |
| 35 | A chart hour is one key holding its twelve five-minute slots |

## Layout

[`docs/architecture-guide.md`](docs/architecture-guide.md) describes every class in full: the nodes under "Application Nodes", the rest under "Supporting classes".

| Path | What |
|------|------|
| `newspack-event-logger-nodes.php` | Entry point and deferred loader (see Plugin Load Order); also registers the admin menu, the dashboard enqueues and the substrate-only hooks at file scope |
| `newspack-event-logger-nodes-config.php` | Deployment OVERRIDES: a commented ledger returning `[]`, held to the schema defaults by `ConfigSchemaTest` |
| `includes/class-settings-schema.php` | The one `Field`-per-setting declaration that Config, the admin page and worker-restart classification all derive from |
| `includes/class-config.php` | Application config loader and the `<eln:>` token resolver; substrate keys live in `newspack-nodes` |
| `includes/class-{rule,rule-set,rule-matcher}.php` | The per-URL logging ruleset: value object, durable two-tier storage, matcher |
| `includes/class-log-manager.php` | Per-request firehose writer; redacts URL secrets; refuses root |
| `includes/class-{request-builder,request-flight,flame-builder,auto-tuner}-node.php` | Request assembly, the in-flight snapshot sibling, flame and stats fan-out, auto-tune |
| `includes/class-{job-router,remote-job-rewrite,discovery-collector}-node.php` | Job normalization onto jobs.log, the hub's `job` → `remote_job` rewrite, the hub's discovery fan-out |
| `includes/class-{flame-tree,flame-fold,stats-store,url-sketch}.php` | Flame algorithms, the stats key schema over the three Tables, the URL-count HyperLogLog |
| `includes/class-{diagnostics-bridge,hook-categorizer,reqgrep-core,current-request-overlay}.php`, `trait-narration.php` | Verb spans and the stderr seam, hook categories, the reqgrep engine, the station's Request tab, builder narration |
| `includes/app/` | `App\Core` (hook, HTTP and query instrumentation), the three service CIs, `Findings`, `Ask_Assembler`, `MCP_Controller` |
| `includes/admin/`, `includes/cli/` | The settings page, which hosts the rules editor; `wp nodes reqgrep` and `wp nodes ruleset-bench` |
| `includes/uninstall-cleanup.php`, `uninstall.php` | The option sweep by prefix and the stats Tables' files; `uninstall.php` requires it and the Composer autoloader by hand because the main file does not run on DELETE |
| `topologies/` | Eleven `.tsl` graphs, named by filename: five primitives and six compositions |
| `mu-plugins/00-newspack-profiler.php` | The standalone profiler drop-in, shipped as its own release asset |
| `scripts/` | This plugin's `build.mjs`, `pre-push`, `lint-eln-docs.sh`, `render-diagram.sh` (a `docs/img/*.html` sheet to PNG; `CHROME=` overrides the browser path) and `bump-version.sh`; everything else is vendored |
| `src/` | Six esbuild entries: five dashboards and the `current-request` tab. Shared SCSS forwards the substrate's tokens and mixins, so never create a local `_tokens.scss` or `_mixins.scss` |
| `types/` | Ambient declarations for `lint:types`; a new `window.*` global needs one in `globals.d.ts` |
| `tests/` | PHPUnit `unit/` and `integration/`; config at `tests/phpunit.xml` |
| `docs/` | `README.md` maps three chapters and the reference set |
| `build-release.sh` | The single source of truth for archive contents |
| `hook_categories.json` | Hook-name patterns to display categories and colors |

## Common Pitfalls

- **`@wordpress/*` is pinned exactly to the `wp-7.0` dist tag; never bump it to close an advisory.** The build externalizes the runtime packages to the `wp.*` globals, so raising one ships no new code; it only moves the API you compile against ahead of the one the browser runs. `scripts/lint-wp-pin.mjs` fails the push on a range or a lock that drifts. `@wordpress/icons` is the exception: WordPress registers no `wp-icons` handle, so it is bundled. Move the whole family only when the WordPress target moves, after checking `npm view @wordpress/<pkg> dist-tags --json` against `wp core version`. Dismiss an advisory reachable only past the pin; npm `overrides` cannot scope around it, because it matches by package name anywhere in the tree.
- **Logging is per-URL rule; there is no global logging or auto-tune option.** Each rule is a URL pattern with a `log` or `skip` action, and a `log` rule carries its own hooks, events, auto-tune thresholds and diagnostic knobs, every knob off unless set. Query-bearing patterns outrank ALL exact patterns, which outrank ALL prefixes; length breaks ties only within a rank, case-insensitively. **No match means skip, and there is no implicit log-all baseline** — declare a `/` log rule to log everything. The platform's own routes need no skip rule, because `Log_Manager` names them as workers. A rule's id is its pattern's `Log_Manager::url_hash()`. Discovery only stages what spokes report; the editor is the only rule writer.
- **There is no operator hub toggle** (`enable_workers` and `enable_aggregator` are gone, and `RetiredConfigKeysTest` guards them). Hub mode derives from an active `aggregator` topology, by name or by include, or from any active graph carrying a `Remote_Source`. It is memoized per process, so a renamed fork reads as a spoke until its worker restarts. Settings fan-out is ungated: without `hub-control` active and at least one Vault entry in group `spoke`, a recorded settings event is tailed and dropped.
- **Both request bounds FOLD.** `entry_budget` caps the entries in flight and `max_entries_per_request` caps one envelope; both are `Request_Builder_Node` arguments, not config keys. Never build a cap that merely stops appending and sets a flag — it loses every entry past it.
- **Firehose writes stay under PIPE_BUF.** `Log_Manager::message()` fits the whole entry to `MAX_DATA_SIZE` (3840) by trimming `m`, and never renames the category `k`: `Flame_Tree::PATTERN_START` opens a span on it. Shape a large value at the producer or split it; a large job goes through `Job_Intake` (decision 7). The architecture guide's "PIPE_BUF and truncation" section carries the full algorithm.
- **Reach the cache through `Cache_Backend`; never build your own.** `Cache_Backend::shared_first()` is memcached, else APCu, and null with neither. Tests assign the substrate's `InMemoryMemcached` double to `Core::$memd`, since no cache interface exists to inject. Rotating the salt orphans every memcached and APCu key but not the SQLite stats rows, whose keys carry no salt (`wp nodes tables flush` empties them), and `Cache_Backend::rotate_salt()` itself asks every live worker to restart, because each memoizes the install scope at boot.
- **Stats fail soft, SSE slots fail closed. Don't unify them.** Dashboards show "no data" when a stats Table cannot open or answer; SSE connections get a 429, because the slot pool IS the rate limit.
- **Application Node classes resolve by namespace prefix.** `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`; a new node needs only the class under that namespace and `composer dump-autoload -o`.
- **Command and constructor `arguments` are a token array** (`list<string>`), never a space-joined string. The substrate binds a verb's tokens against the `args` its schema declares before the handler runs, so a handler reads `$args['<name>']`, typed and defaulted, and parses nothing; each arg arrives by position or as `--name=value`. Declare what the callers send: the dashboards build args with `formatCommandArgs(...)`, the MCP door names every arg, and an undeclared option is refused.
- **Type flags**: an array VALUE takes `TM_STRUCT`, a string VALUE `TM_BYTESTREAM`. Consumers reading an array VALUE gate on `TM_STRUCT`.
- **Don't add `class_exists()` guards for in-plugin classes.** The composer classmap resolves everything under `includes/`, so a missing class means a stale classmap.
- **A topology name is its `.tsl` filename.** `complete` runs request-builder, flame-builder, job-router and job-worker in one worker; no `combined.tsl` or `jobs.tsl` exists. Read `wp nodes status` for what is active, since stored options outrank the config file.
- **`App\Core` carries two correctness mechanisms; don't strip them.** `wrap_callbacks()` skips any callback with a by-reference parameter, because wrapping it breaks WordPress's contract. A sacrificial `hook_spacer` at `PHP_INT_MAX - 2` keeps `hook_complete` firing when a filter unhooks itself mid-run.

## Local Skills

`.claude/skills/` (`.agents` is a symlink to `.claude`):

- `event-logger-nodes-workflow` — handlers, REST, dashboards, topologies
- `event-logger-nodes-debugging` — dashboards, the stats Tables, hub/spoke routing, SSE
- `event-logger-nodes-review` — application contract checklist

## References

- `docs/README.md` — the documentation map
- `docs/architecture-guide.md` — application design, topologies, hub/spoke flow, stats schema
- `docs/architecture-decisions.md` — the 35 decisions
- `docs/security-model.md` — what the logger captures and what crosses to the hub
- `docs/API.md` — the MCP route, every service-CI verb, the WP-CLI verbs, the PHP API and the hooks
- `README.md` — requirements, quick start, configuration, dashboards
- `../newspack-nodes/` — the substrate. The settings layer uses its `Config_System` classes; never vendor a local copy of either their PHP or their React half
