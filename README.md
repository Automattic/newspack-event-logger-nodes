# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime: high-throughput WordPress request-lifecycle logging, live SSE dashboards, flame-graph generation, and hub/spoke aggregation across sites. It replaced the legacy `newspack-event-logger-plugins` monorepo wholesale, and that monorepo is gone from the tree: there is no coexisting stack.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Fleet, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes), and both plugins must be installed and active. The plugin header declares `Requires Plugins: newspack-nodes`, which keeps the runtime active on WordPress 6.5 and later, but WordPress orders neither plugin loading nor plugin updates: this plugin sorts alphabetically ahead of the substrate, so its wiring waits on a `plugins_loaded` priority 11 closure gated on the substrate being present *and* at 2.46.0 or newer. Below that floor the plugin goes dormant behind an admin notice naming both versions rather than fataling on an API that is not there.

Application classes (`Request_Builder_Node`, `Request_Flight_Node`, `Flame_Builder_Node`, `Auto_Tuner_Node`, `Job_Router_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`) extend `Newspack_Nodes\Node`, and all but `Request_Flight_Node` carry their own `fill()` body. Three of them extend it through the substrate's `Timer_Node` and also do periodic work in `fire()`: `Request_Builder_Node` rotates its in-flight cache so a stalled request still times out, `Request_Flight_Node` puts the in-flight map on the wire, and `Discovery_Collector_Node` probes every spoke on a 300-second tick. Node subclasses carry a `_Node` suffix; helpers (`Log_Manager`, `Stats_Store`, `Rule_Set`) do not. Three pieces live in the substrate and have no alias here: the generic `Job_Worker_Node` executor, the `Job_Intake` large-write ingress, and the hub-side `Remote_Source_Node`. This plugin contributes the per-job request context (`Log_Manager::begin_job_context_filter` / `end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`). The runtime owns the wiring; this plugin owns the data processing.

Registration is by namespace prefix, never per class. `Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` resolves node classes and supplies the stock topologies, and `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` resolves the service CIs, so a topology's `make_node Flame_Builder` finds `\Newspack_Event_Logger_Nodes\Flame_Builder_Node`.

The vocabulary here is the substrate's. Its [documentation map](https://github.com/Automattic/newspack-nodes/blob/main/docs/README.md#glossary) defines node, message, sink, target, topology, TSL, CI, Consumer, worker and Vault in a line each, and [getting-started.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/getting-started.md) takes a reader who has never seen a node graph to a running pipeline in about five minutes. Read those before this one.

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.5 |
| PHP | 8.2 |
| `newspack-nodes` | 2.46.0, installed and active |
| A cache backend | Memcached, or APCu |

The substrate's `Cache_Backend` prefers the shared `Memcached` handle `Bootstrap` builds from `memcache_servers` and falls back to APCu. The performance statistics are served from that cache; with neither backend every `Stats_Store` read fails soft and the dashboards read "no data".

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

The substrate's active set defaults to empty, so installing the two plugins registers their topologies and spawns no worker; every dashboard reads "no data" until one is activated. `wp nodes activate` writes the `topologies` option, invalidates the config cache and spawns the fleet at once, and the Nodes admin page's Overview tab carries the same toggle beside every topology, active and stopped alike. `wp nodes deactivate` is its mirror.

Or download the zips from the [Releases](https://github.com/Automattic/newspack-event-logger-nodes/releases) page and upload them through the WordPress admin.

## Configuration

Two config files, one per plugin, each layered under a WordPress-options overlay. There is no config *filter*.

Substrate settings — the base directory (default `/tmp/newspack-nodes`), the partition count, the memcache servers, the active topologies — are read by `Newspack_Nodes\Config::load_config()` from `newspack-nodes-config.php`, in the `newspack-nodes` plugin. This plugin's nine application keys are declared once in `Settings_Schema`, and a deployment overrides them in `newspack-event-logger-nodes-config.php`, a ledger that ships with every key commented out beside its default. Four layers, weakest first: the schema default, that file, the file named by `LOCAL_NEWSPACK_NODES_CONF`, and a stored `newspack_event_logger_nodes_<key>` option. Presence decides the option layer rather than truthiness, so a stored `''`, `[]` or `false` beats both files, and `Config::value()` throws on a key no `Field` declares rather than limping on a default.

```php
// Substrate keys live in newspack-nodes-config.php (the newspack-nodes plugin).
return [
    'base_directory'   => '/tmp/newspack-nodes',
    'num_partitions'   => 4,
    'memcache_servers' => [ '127.0.0.1:11211' ],
    'topologies'       => [ 'complete' ],
];

// Application keys live in newspack-event-logger-nodes-config.php (this plugin).
return [
    'enable_logging' => true,
    // Per-URL logging ruleset seed (the editor owns it once saved).
    'rules'          => [
        [ 'pattern' => '/wp-cron.php?', 'action' => 'skip' ],
        [ 'pattern' => '/', 'action' => 'log' ],
    ],
];
```

The settings layer is the substrate's shared Config System (`Newspack_Nodes\Config_System\Field` / `Schema` / `Settings_Renderer` / `Options_Overlay` / `Reset_Gate`); this plugin declares only the `Settings_Schema` those read, which is what gives its settings page the shared per-field reset control, the "Effective Configuration" panel and the delete-on-blank gate. Three keys render as checkboxes on the settings page — `enable_logging`, `log_memory` and `flush_every_line`. `rules` has its own editor on that page. The remaining five — `allowed_users`, `hook_start_priority`, `custom_colors`, `stats_mirror_node`, `recommended_log_events` — are overlay-only.

Hub-mode is derived, never toggled: an active `aggregator` topology, by name or by include, or any active graph carrying a `Remote_Source` node. `enable_workers` and `enable_aggregator` are retired, and `tests/unit/RetiredConfigKeysTest.php` guards both names. Remote-spoke credentials live in the substrate's **Vault** (the substrate's `vault` CI); the per-spoke `Remote_Source` nodes are wired on the topology console canvas.

### Logging rules

Which URLs and hooks get logged is a per-URL ruleset, not a global setting. Each rule pairs a URL pattern with a `log` or `skip` action and, for a `log` rule, its own hooks, custom events, significant events, auto-tune thresholds and per-rule diagnostics (`log_queries`, `log_http`, `trace_hooks`, `trace_callers`).

Matching is most-specific-first: a query-bearing pattern (`/jobs/x?job-work`) outranks an exact path (`/about?`), which outranks a prefix (`/blog`). Length breaks ties only *within* a rank, so this is not longest-prefix-wins, and list order never decides the outcome. Comparison is case-insensitive. No rule matched means skip — there is no implicit log-all baseline, so a site that logs everything declares a `/` log rule, as the shipped seed does alongside skips for `/wp-cron.php` and the substrate's own command, SSE and worker-spawn endpoints.

A rule's id is its pattern's `Log_Manager::url_hash()`, so every write rekeys. Rules are edited in the "Logging Rules" editor on the settings page, backed by the `rules` service CI, and seeded from the config file's `rules` key until that editor first writes the option. The classes are `Rule`, `Rule_Set` and `Rule_Matcher`.

## Development

Fresh clone: `npm install && composer install && npm run build`. The
`newspack-nodes` checkout must sit beside this repo — the build kit and
shared JS resolve through `../newspack-nodes`, unless `NEWSPACK_NODES_SRC`
names its `src` directory. Regenerate the classmap after adding a Node
class: `composer build:autoloaders`. See `AGENTS.md`.

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

Two verbs answer no dashboard. `performance.set` is the write a hub's settings sync pushes at a spoke, and `discovery.get` reports a spoke's hook and custom-event roster — to the hub's `Discovery_Collector_Node`, and to the substrate's `vault` CI when it probes one spoke's connection. Substrate-owned surfaces live on the substrate's own **Nodes** admin page, as its tabs: Overview, Jobs, Partition Viewer, Log Viewer, Config Audit, Console, Sessions, Vault and Aggregator.

For the full per-CI verb tables and the TM_COMMAND envelope shape, see [docs/API.md](docs/API.md).

## Command line

Both commands mount under the substrate's `nodes` namespace:

```bash
wp nodes reqgrep [<pattern>]   # application-aware firehose filter: groups matching lines by request
wp nodes ruleset-bench         # measures the ruleset's two hook-storage tiers, inline vs Table-pointer
```

`reqgrep` matches every request when the pattern is omitted, and takes `--follow`, `--recent`, `--raw`, `--incomplete`, `--bucket-size`, `--num-buckets` and `--firehose`; `ruleset-bench` takes `--iterations`.

## MCP

The same service-CI verbs are also served as JSON-RPC over MCP, so an agent can read the performance data and edit the ruleset without a dashboard. It adds no verbs of its own: `tools/call` mounts the same request graph `/command` mounts and dispatches through the same interpreter.

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

Hub and spoke differ by topology membership rather than by a toggle. A spoke runs the request, job and flame graphs locally; a hub additionally runs `aggregator.tsl` — the substrate `Remote_Source_Node` pull side feeding this plugin's `Remote_Job_Rewrite_Node` — and the single-partition `hub-control.tsl`, which fans settings sync and discovery out across the fleet. See [docs/architecture-guide.md](docs/architecture-guide.md) for the per-topology breakdown and the hub/spoke flow.

## Bundled assets

`mu-plugins/00-newspack-profiler.php` is a standalone drop-in that times each active plugin's load and records the moment PHP began the request. It measures unconditionally and writes only where this plugin is active: it flushes each plugin's load through `Log_Manager` as a `(start)` / `(complete)` span pair, and `Log_Manager` stamps the request-start reading onto `process (start)`, so a record begins where PHP began rather than where the logger emitted its first line. Every release attaches the drop-in beside the plugin zip, for installation under `wp-content/mu-plugins/`.

The refresh-ahead cache warmer that once shipped here is its own plugin, [`newspack-cache-cozy`](https://github.com/Automattic/newspack-cache-cozy).

## Documentation

- [docs/architecture-guide.md](docs/architecture-guide.md) — write path, topologies, application nodes, memcache schema, hub/spoke flow
- [docs/API.md](docs/API.md) — service-CI verbs, the command endpoint, SSE, MCP, the WP-CLI verbs, the PHP API sibling plugins log through, and the hooks this plugin fires and consumes
- [AGENTS.md](AGENTS.md) — architecture decisions, layout, build and release
- [CHANGELOG.md](CHANGELOG.md) — version-by-version history
- [newspack-nodes docs/README.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/README.md) — the substrate's documentation map, whose glossary defines every runtime term this plugin borrows
- [newspack-nodes docs/getting-started.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/getting-started.md) — the substrate from zero to a pipeline you can run by hand

## License

GPL-2.0-or-later

## Status

This plugin releases independently of the substrate: it declares a minimum runtime version, not a matching one, and the plugin header and `CHANGELOG.md` carry its own. The dashboards ride the substrate's `_http`, `_sse` and `_heartbeat` spine and its canonical UI layer, and every server-side read is a service-CI verb. The `status.get` verb — substrate-owned — reports the runtime version, the partition count, the active topologies and cache reachability; it carries no separate application version.
