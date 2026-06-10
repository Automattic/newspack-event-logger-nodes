# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime. Replaces the 10-plugin `newspack-event-logger-plugins` monorepo: high-throughput WordPress request lifecycle logging, real-time SSE streaming, flame graph generation, and hub/spoke aggregation across multiple sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes). Both plugins must be installed and active; the runtime must load first. The plugin header declares `Requires Plugins: newspack-nodes`, and `Substrate_Guard` enforces a minimum substrate version (`MINIMUM_NODES_VERSION`, currently `0.13.0`): when the installed runtime is too old or missing the required Config System APIs, the plugin shows an admin notice and bails instead of fataling.

Application classes (`Request_Builder_Node`, `Flame_Builder_Node`, `Job_Router_Node`, `Stream_Merger_Node`, `Auto_Tuner_Node`, …) are plain `Newspack_Nodes\Node` subclasses with their own `fill()` bodies — Node subclasses carry a `_Node` suffix; helpers (`Log_Manager`, `Stats_Store`, `Server_Registry`, `Settings_Sync`, `Remote_Manager`) don't. The generic `Job_Worker_Node` executor moved to the `newspack-nodes` substrate (`\Newspack_Nodes\Job_Worker_Node`); this plugin contributes only the per-job request-context glue (`Log_Manager::begin/end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`). The runtime owns the wiring; this plugin owns the data-processing logic. The plugin registers its class namespace with the substrate (`Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` for application nodes + `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` for service CIs), so a topology's `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix — no per-class registration.

## Quick Start

```bash
# Install both plugins from their latest GitHub releases.
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-nodes/releases/latest/download/newspack-nodes.zip
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-event-logger-nodes/releases/latest/download/newspack-event-logger-nodes.zip

# Verify the runtime sees this plugin's worker topologies.
wp nodes ls
```

Or download the zips from the [Releases](https://github.com/Automattic/newspack-event-logger-nodes/releases) page and upload via the WordPress admin.

There is no config *filter*. Config is loaded from a PHP config file layered with a WordPress-options overlay.

Substrate-level settings (base directory, partitions, memcache, active topologies) are read by the substrate's `Newspack_Nodes\Config::load_config()` from `newspack-nodes-config.php` (in the `newspack-nodes` plugin) merged with the substrate's WP-options overlay. Application-level settings (URL filters, hooks to instrument, `enable_aggregator` for hub-mode, remote-spoke registry) are read by this plugin's `Newspack_Event_Logger_Nodes\Config::load_config()` from `newspack-event-logger-nodes-config.php` merged with the `Settings_Schema` WP-options overlay — most are editable from the WP admin.

```php
// Substrate keys live in newspack-nodes-config.php (the newspack-nodes plugin).
return [
    'base_directory'   => '/tmp/newspack-nodes',
    'num_partitions'   => 4,
    'memcache_servers' => [ '127.0.0.1:11211' ],
];

// Application keys live in newspack-event-logger-nodes-config.php (this plugin).
return [
    'enable_aggregator' => true,   // hub mode (typed bool, default OFF; also a WP option editable from the admin)
];
```

Remote spokes (when this site is the hub) are managed at **WP Admin → Event Logger → Remote Servers**, or programmatically via the `Server_Registry` class.

## Features

Application graph backed by `newspack-nodes` partitions, surfaced as dashboards and SSE streams:

Every dashboard sends TM_COMMAND envelopes to a service CI via the substrate's single `POST /wp-json/newspack-nodes/v1/command` endpoint, and consumes live data through the substrate's single `/wp-json/newspack-nodes/v1/messages/stream` SSE endpoint. The "verbs" column below lists the CI verb each dashboard calls; the live-data column lists the substrate stream it subscribes to.

| Dashboard | Service CI verbs | Live data | Source |
|-----------|------------------|-----------|--------|
| **Performance Dashboards** | `performance.{overview, urls, dashboard, timing, url_detail}` | — | `Request_Builder_Node` + `Flame_Builder_Node` + `Stats_Store` |
| **Request profile** | `performance.{request_search, request_detail}` | — | Partition scan via `.idx` |
| **Performance Gyroscope** | — | `subscribe=gyroscope.pN` | `Request_Flight_Node` snapshots + `completed:tee` fan-out |
| **Request Log** | `performance.{request_log_list, request_log_detail}` | `subscribe=completed.pN` | Requests index + `requests.log` |
| **Event Aggregator (Raw Logs)** | `events.stats` | `subscribe=firehose.pN` | Direct firehose tail |
| **Errors** | — | `subscribe=errors.pN` | Tail of `errors.log` |
| **Workers** (substrate-owned) | `workers.{list, restart, …}` | — | Substrate's `Workers_CI` (lock-dir scan + offsetlog cursors) |
| **Performance Logger settings** | `logger.{config, hooks}`, `performance.{config_get, config_update, settings_update, hooks_registered, hooks_categories, hooks_available, hooks_configure}` | — | WP options |
| **Aggregator Admin (hub-only)** | `aggregator.{status, health, servers}`, `servers.{list, get, add, update, delete, test}` | — | `Server_Registry` + `Stream_Merger_Node` per-remote state |
| **Status probe** | `status.get` | — | Version, partitions, active topologies, cache reachability |

For the full per-CI verb tables and TM_COMMAND envelope shape, see [API.md](API.md).

## Topologies

Per-partition node graphs ship as declarative `.tsl` files in `topologies/`: `aggregator.tsl`, `request-builder.tsl`, `job-router.tsl`, `flame-builder.tsl`, `combined.tsl`, and `performance.tsl` (plus the substrate's runtime-only `firehose` / `jobintake` basenames). Which topologies are active is the substrate's `topologies` config key. Hub vs spoke is the `enable_aggregator` switch: a spoke runs the request/job/flame graphs locally, while a hub additionally runs `aggregator.tsl` (the `Stream_Merger_Node` pull-side). See [ARCHITECTURE.md](ARCHITECTURE.md) for the full per-topology breakdown and hub/spoke flow.

## Cache Warmer (refresh-ahead)

The plugin ships a refresh-ahead cache warmer (added 0.11.0). `mu-plugins/01-newspack-cache-warmer.php` is an mu-plugin drop-in; `Cache_Warmer_Tick_Node` is a `Timer_Node` that enqueues a `cache_warmer` JobWorker job every interval, so warming runs inside an isolated job (its own request id and GC cycle) rather than on a page request or wp-cron. Operator scripts `scripts/schedule-cache-warmer.sh` and `scripts/unschedule-cache-warmer.sh` install/remove it. The standalone profiler asset `00-newspack-profiler.php` ships separately as another mu-plugin drop-in.

## Configuration / Settings

As of 0.13.0 the settings layer is built on the substrate's shared Config System (`Newspack_Nodes\Config_System\Field` / `Schema` / `Settings_Renderer`). This plugin declares a single declarative `Settings_Schema` (`includes/class-settings-schema.php`); the WP-options overlay that `Config::load_config()` applies, the per-field reset (↺) UI, and the delete-on-blank gate are all the shared `Newspack_Nodes\Config_System` machinery (`Options_Overlay`, `Reset_Gate`).

## Migration from Newspack Event Logger Plugins

The 10-plugin monorepo (`newspack-event-logger-plugins`) is being replaced wholesale — no shadow mode or dual emission. The two stacks can coexist on the same site during cutover: legacy writes to `/volumes/pyrobase/tmp/event-logger`, this plugin defaults to `/tmp/newspack-nodes`, and the WP-CLI verbs are distinct (`wp eventlog reqgrep` vs `wp nodes reqgrep`).

## License

GPL-2.0-or-later

## Status

v0.13.x. The dashboard consolidation (per-dashboard SSE controllers → single substrate `/messages/stream`) and the controller→CI migration are complete; all dashboards ride the substrate's `_http` / `_sse` / `_heartbeat` spine with a canonical view contract (pending-Map gate, TM_ERROR isolation, `_errorMessage()` helper). Post-0.8 work includes the refresh-ahead cache warmer (0.11.0), the `Job_Worker_Node` executor moving to the substrate (0.12.0), and the migration onto the substrate's shared Config System (0.13.0). The `status.get` verb reports both the application `version` and the substrate `runtime_version`. See `CHANGELOG.md` for the version-by-version history.
