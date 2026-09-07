# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime: high-throughput WordPress request-lifecycle logging, live SSE dashboards, flame-graph generation, and hub/spoke aggregation across sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Fleet, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes), and both plugins must be installed and active. The plugin header declares `Requires Plugins: newspack-nodes`, which keeps the runtime active on WordPress 6.5 and later, but WordPress orders neither plugin loading nor plugin updates: this plugin sorts alphabetically ahead of the substrate, so its wiring waits on a `plugins_loaded` priority 11 closure gated on the substrate being present *and* at 2.46.0 or newer. Below that floor the plugin goes dormant behind an admin notice naming both versions rather than fataling on an API that is not there.

Application classes (`Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`) extend `Newspack_Nodes\Node`, and all but `Request_Flight_Node` carry their own `fill()` body. Three reach that base through the substrate's `Timer_Node` and also do periodic work in `fire()`: `Request_Builder_Node` rotates its in-flight cache so a stalled request still times out, `Request_Flight_Node` emits one row per in-flight request, and `Discovery_Collector_Node` probes every spoke on a 300-second tick. Two of the seven are owned siblings a patron mounts rather than nodes a topology places: `Request_Builder_Node` owns `Request_Flight_Node`, and `Flame_Builder_Node` owns `Auto_Tuner_Node`. Node subclasses carry a `_Node` suffix; helpers (`Log_Manager`, `Stats_Store`, `Rule_Set`) do not. Three pieces live in the substrate and have no alias here: the generic `Job_Worker_Node` executor, the `Job_Intake` large-write ingress, and the hub-side `Remote_Source_Node`. This plugin contributes the per-job request context (`Log_Manager::begin_job_context_filter` / `end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`). The runtime owns the wiring; this plugin owns the data processing.

Registration is by namespace prefix, never per class. `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` resolves node classes and supplies the stock topologies, and `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` resolves the service CIs, so a topology's `make_node Flame_Builder` finds `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`. Two smaller registrations go by name instead: `Core::register_config_namespace( 'eln', … )` resolves the `<eln:is_hub>`, `<eln:stats_mirror_node>` and `<eln:stats_mirror_lifetime>` tokens the `.tsl` files carry — substrate keys use `<config:KEY>` — and `Formatters::register` supplies the `request-index`, `flame-index` and `stats-index` callables their `with_index` lines name, because TSL has no closures.

The vocabulary here is the substrate's. Its [documentation map](https://github.com/Automattic/newspack-nodes/blob/main/docs/README.md#glossary) glosses every runtime term in a bullet — node, message, sink, target, topology, TSL, CI, Consumer, worker and Vault among them — and [getting-started.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/getting-started.md) takes a reader who has never seen a node graph to a running pipeline in about five minutes. Read those before this one.

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.5 |
| PHP | 8.2 |
| `newspack-nodes` | 2.46.0, installed and active |
| A cache backend | Memcached, or APCu |

The substrate's `Cache_Backend` prefers the shared `Memcached` handle `Bootstrap` builds from `memcache_servers` and falls back to APCu. Either one alone brings the runtime up. With neither, the substrate cannot claim a command's single-use nonce, so verification fails closed and no dashboard verb answers; `Stats_Store::table()` returns null besides, and every statistics read counts as a miss.

## Quick Start

```bash
# Install both plugins from their latest GitHub releases.
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-nodes/releases/latest/download/newspack-nodes.zip
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-event-logger-nodes/releases/latest/download/newspack-event-logger-nodes.zip

# Verify the runtime sees this plugin's worker topologies. A fresh install lists every one as inactive.
wp nodes status

# Spawn the everything-in-one graph: request assembly, flames, stats and jobs in one worker.
wp nodes activate complete
```

The substrate's active set defaults to empty, so installing the two plugins registers their topologies and spawns no worker; every dashboard stays empty until one is activated. `wp nodes activate` writes the `topologies` option, invalidates the config cache and spawns the fleet at once, and the Nodes admin page's Overview tab carries the same toggle beside every topology, active and stopped alike. `wp nodes deactivate` is its mirror.

Or download the zips from the [Releases](https://github.com/Automattic/newspack-event-logger-nodes/releases) page and upload them through the WordPress admin.

## Configuration

Two config files, one per plugin, each layered under a WordPress-options overlay. There is no config *filter*.

Substrate settings — the base directory (default `/tmp/newspack-nodes`), the partition count, the memcache servers, the active topologies — are read by `Newspack_Nodes\Config::load_config()` from `newspack-nodes-config.php`, in the `newspack-nodes` plugin. This plugin's nine application keys are declared once in `Settings_Schema`, and a deployment overrides them in `newspack-event-logger-nodes-config.php`, a ledger that ships with every key commented out beside its default. Four layers, weakest first: the schema default, that file, the file named by `LOCAL_NEWSPACK_NODES_CONF`, and a stored `newspack_event_logger_nodes_<key>` option. Presence decides the option layer rather than truthiness, so a stored `''`, `[]` or `false` beats both files, and `Config::value()` throws on a key no `Field` declares rather than limping on a default. Deleting the plugin sweeps every `newspack_event_logger_nodes_` option row, on every site of a multisite network; the on-disk runtime tree belongs to the substrate's uninstall, and the memcache stats expire on their own TTLs.

```php
// Substrate keys are overridden in newspack-nodes-config.php (the newspack-nodes plugin).
return [
    'base_directory'   => '/tmp/newspack-nodes',
    'num_partitions'   => 4,
    'memcache_servers' => [ '127.0.0.1:11211' ],
    'topologies'       => [ 'complete' ],
];

// Application keys are overridden in newspack-event-logger-nodes-config.php (this plugin).
return [
    'enable_logging' => true,
    // Per-URL logging ruleset seed (the editor owns it once saved).
    'rules'          => [
        [ 'pattern' => '/wp-cron.php?', 'action' => 'skip' ],
        [ 'pattern' => '/', 'action' => 'log' ],
    ],
];
```

The settings layer is the substrate's shared Config System (`Newspack_Nodes\Config_System\Field` / `Schema` / `Settings_Renderer` / `Options_Overlay` / `Reset_Gate` / `Field_Reset_Assets` / `Restart_Planner`); this plugin declares only the `Settings_Schema` those read, which is what gives its settings page the shared per-field reset control, the "Effective Configuration" panel and the delete-on-blank gate. Each `Field` also names the node types its value reaches — `all`, a list of them, or nothing — so a save recycles only the workers that consume it, then tells every live worker to re-read the config it froze at boot.

Three keys render as checkboxes on the settings page — `enable_logging`, `log_memory` and `flush_every_line`, each classed `all`, so changing one recycles the whole fleet. `rules` has its own editor on that page. The remaining five are overlay-only: `allowed_users` (a `user_login` allowlist narrowing the `manage_options` gate on the dashboards), `hook_start_priority` (where `App\Core` binds `hook_start`, default `-10000`, against a `hook_complete` fixed at `PHP_INT_MAX - 1`), `custom_colors` (event name to hex swatch, for the event pickers), `stats_mirror_node` (the durable Partition shadowing the memcache stats, `flame-stats:partition`, empty to turn it off) and `recommended_log_events` (the hook picker's "Recommended" menu, which binds nothing itself).

Hub-mode is derived, never toggled: an active `aggregator` topology, by name or by include, or any active graph carrying a `Remote_Source` node. Remote-spoke credentials live in the substrate's **Vault** (the substrate's `vault` CI); the per-spoke `Remote_Source` nodes are wired on the topology console canvas.

### Logging rules

Which URLs and hooks get logged is a per-URL ruleset, not a global setting. Each rule pairs a URL pattern with a `log` or `skip` action and, for a `log` rule, its own hooks, custom events, significant events, auto-tune thresholds and per-rule diagnostics (`log_queries`, `log_http`, `trace_hooks`, `trace_callers`).

Matching is most-specific-first: a query-bearing pattern (`/jobs/x?job-work`) outranks an exact path (`/about?`), which outranks a prefix (`/blog`). Length breaks ties only *within* a rank, so this is not longest-prefix-wins, and list order never decides the outcome. Comparison is case-insensitive. No rule matched means skip — there is no implicit log-all baseline, so a site that logs everything declares a `/` log rule, as the shipped seed does alongside exact skips for `/wp-cron.php?` and the substrate's own command, SSE and worker-spawn endpoints.

A rule's id is its pattern's `Log_Manager::url_hash()`, so the pattern is the identity and one URL can never carry two differently-configured rules. Rules are edited in the "Logging Rules" editor on the settings page, backed by the `rules` service CI, and seeded from the config file's `rules` key until that editor first writes the option. The `↺` beside "+ Add Rule" resets the whole section: behind a confirmation it discards the stored ruleset outright, so the config file's seed governs again. Where every other setting marks its field and waits for a Save, this one applies at once, because the editor writes through the CI and has no form submission to defer a mark to. The classes are `Rule`, `Rule_Set` and `Rule_Matcher`.

## Development

A fresh clone needs `npm install && composer install && npm run build`. The `newspack-nodes` checkout must sit beside this repo — the build kit and the `@newspack-nodes/*` aliases resolve through `../newspack-nodes/src`, and `NEWSPACK_NODES_SRC` moves that for the esbuild build alone; `jest.config.js` passes the sibling path outright. Adding a Node class means regenerating the classmap with `composer build:autoloaders`. See `AGENTS.md`.

## Dashboards

A dashboard reaches the server two ways, both of them the substrate's: a TM_COMMAND envelope to a service CI through `POST /wp-json/newspack-nodes/v1/command`, and a subscription on `GET /wp-json/newspack-nodes/v1/messages/stream`. Most surfaces use one or the other. The verbs column lists what each one calls; the live-data column lists what it subscribes to.

| Surface | Service CI verbs | Live data | Source |
|---------|------------------|-----------|--------|
| **Performance** (Event Logger → Performance) | `performance.{overview, urls, url_detail, url_breakdown, request_search, request_detail, request_grep, ask, hooks_registered}`, `rules.{list, upsert, delete}` | — | `Request_Builder_Node` + `Flame_Builder_Node` + `Stats_Store` + `Reqgrep_Core`, over the `requests` partition index |
| **Gyroscope** (Event Logger → Gyroscope) | — | `subscribe=gyroscope.*` | `Request_Flight_Node` in-flight snapshots plus the `completed:tee` fan-out |
| **Request Log** (Event Logger → Request Log) | substrate `raw-logs.{list_logs, log_status, read_message}` | `subscribe=completed.*` | `completed.p0`, the finished requests `completed:tee` fans out |
| **Errors** (Event Logger → Errors) | substrate `raw-logs.{list_logs, log_status, read_message}` | `subscribe=errors.*` | `errors.p0`, written by `Request_Builder_Node`: its own error and warning records, and the `stderr` lines `Diagnostics_Bridge` carries in from the substrate |
| **Settings** (Settings → Event Logger) | `performance.hooks_registered`, `rules.{list, save, upsert, delete, reset}` | — | The hook taxonomy and `Rule_Set` |
| **Request** (a debug-overlay tab) | `performance.request_detail` | — | This request's own record |

`performance.hooks_registered` answers two surfaces because one editor serves both: the Performance dashboard's "Log this URL" opens the same `RuleEditModal`, and the same hook picker beneath it, that the settings page's ruleset table opens.

Two verbs answer no dashboard. `performance.set` is the write a hub's settings sync pushes at a spoke, and `discovery.get` reports a spoke's hook and custom-event roster — to the hub's `Discovery_Collector_Node`, and to the substrate's `vault` CI when it probes one spoke's connection. Substrate-owned surfaces live on the substrate's own **Nodes** admin page, as its tabs: Overview, Jobs, Console, Partition Viewer, Log Viewer, Config Audit, Vault, Sessions and Aggregator.

For the full per-CI verb tables and the TM_COMMAND envelope shape, see [docs/API.md](docs/API.md).

## Command line

Both commands mount under the substrate's `nodes` namespace:

```bash
wp nodes reqgrep [<pattern>]   # application-aware firehose filter: groups matching lines by request
wp nodes ruleset-bench         # measures the ruleset's two hook-storage tiers, inline vs Table-pointer
```

`reqgrep` matches every request when the pattern is omitted, and takes `--follow`, `--recent`, `--raw`, `--incomplete`, `--bucket-size`, `--num-buckets` and `--firehose`; `ruleset-bench` takes `--iterations`.

## MCP

MCP re-serves ten of the service-CI verbs as JSON-RPC tools, so an agent can read the performance data and edit the ruleset without a dashboard. It adds no verbs of its own: `tools/call` mounts the same request graph `/command` mounts and dispatches through the same interpreter.

```
POST /wp-json/newspack-event-logger-nodes/v1/mcp
```

Authentication is a scoped command session (issue one under Nodes → Sessions), passed as `Authorization: Bearer <handle>.<key>`. The request becomes that session's minting user and applies the scope as a ceiling, so a scope can only ever subtract and `tools/list` offers only what it covers: a `read` session sees the seven performance tools and `rules_list`, and `tune` adds `rules_upsert` and `rules_delete`.

Register it with a client — `<ID>` is the local name the client files it under:

```
claude mcp add --transport http <ID> https://<DOMAIN>/wp-json/newspack-event-logger-nodes/v1/mcp --header "Authorization: Bearer <HANDLE>.<KEY>"
```

See [docs/API.md](docs/API.md) for the tool list, the measurement caveat every tool description carries, and refusal semantics.

## Topologies

Per-partition node graphs ship as declarative `.tsl` files in `topologies/`: `request-builder.tsl`, `flame-builder.tsl`, `performance.tsl`, `complete.tsl`, `job-router.tsl`, `job-feed.tsl`, `job-hub.tsl`, `job-spoke.tsl`, `aggregator.tsl` and `hub-control.tsl`. They include the substrate's own `job-intake`, `job-worker`, `settings-sync` and `topic-probe` graphs. Which topologies run is the substrate's `topologies` config key, empty by default and written by `wp nodes activate` and `wp nodes deactivate`.

Hub and spoke differ by topology membership rather than by a toggle. A spoke runs the request, job and flame graphs locally; a hub additionally runs `aggregator.tsl`, where the substrate `Remote_Source` nodes an operator wires feed this plugin's `Remote_Job_Rewrite_Node`, and the single-partition `hub-control.tsl`, which pushes the synced settings and the discovery probe out to every spoke. See [docs/architecture-guide.md](docs/architecture-guide.md) for the per-topology breakdown and the hub/spoke flow.

## Bundled assets

`mu-plugins/00-newspack-profiler.php` is a standalone drop-in that times each active plugin's load and records the moment PHP began the request. It measures unconditionally and writes only where this plugin is active and the governing rule said `log`: it flushes each plugin's load through `Log_Manager` as a `(start)` / `(complete)` span pair, and `Log_Manager` stamps the request-start reading onto `process (start)`, so a record begins where PHP began rather than where the logger emitted its first line. Every release attaches the drop-in beside the plugin zip, for installation under `wp-content/mu-plugins/`.

`hook_categories.json` at the plugin root holds the hook taxonomy: a color for each of its 63 categories, the regular expressions that assign hooks to 62 of them — `Other` is the fallback and needs none — and a one-line description for 24. `Hook_Categorizer` reads it for the settings page's hook picker, and the plugin publishes it whole on `window.eventLoggerHookCategories`, where the Gyroscope legend takes its colors. A site adds to or overrides any of it through the `newspack_event_logger_nodes_hook_customizations` option, which nothing in the plugin writes.

## Documentation

- [docs/architecture-guide.md](docs/architecture-guide.md) — write path, topologies, application nodes, memcache schema, hub/spoke flow
- [docs/API.md](docs/API.md) — service-CI verbs, the command endpoint, SSE, MCP, the WP-CLI verbs, the PHP API sibling plugins log through, and the hooks this plugin fires and consumes
- [AGENTS.md](AGENTS.md) — architecture decisions, layout, build and release
- [CHANGELOG.md](CHANGELOG.md) — version-by-version history
- [newspack-nodes docs/README.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/README.md) — the substrate's documentation map and glossary
- [newspack-nodes docs/getting-started.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/getting-started.md) — the substrate from zero to a running pipeline

## License

GPL-2.0-or-later

## Status

This plugin releases independently of the substrate: it declares a minimum runtime version, not a matching one, and the plugin header and `CHANGELOG.md` carry its own. The dashboards clip onto the substrate's browser backbone — `_router`, `_command_interpreter`, `_shell`, `_http` and `_heartbeat` — and its canonical UI layer. The `status.get` verb — substrate-owned — reports the runtime version, the partition count, the active topologies and cache reachability; it carries no separate application version.
