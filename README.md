# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime. Replaces the 10-plugin `newspack-event-logger-plugins` monorepo: high-throughput WordPress request lifecycle logging, real-time SSE streaming, flame graph generation, and hub/spoke aggregation across multiple sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes). Both plugins must be installed and active; the runtime must load first.

Application classes (`Request_Builder_Node`, `Flame_Builder_Node`, `Job_Router_Node`, `Job_Worker_Node`, `Stream_Merger_Node`, `Auto_Tuner_Node`, …) are plain `Newspack_Nodes\Node` subclasses with their own `fill()` bodies — Node subclasses carry a `_Node` suffix; helpers (`Log_Manager`, `Stats_Store`, `Server_Registry`, `Settings_Sync`, `Remote_Manager`) don't. The runtime owns the wiring; this plugin owns the data-processing logic. The plugin registers its class namespace with the substrate (`Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\' )`), so a topology's `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix — no per-class registration.

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

Substrate-level settings (base directory, partitions, memcache, active topologies) live on the `newspack_nodes/config` filter. Application-level settings (URL filters, hooks to instrument, `enable_aggregator` for hub-mode, remote-spoke registry) live on the `newspack_event_logger_nodes/config` filter and are also editable from the WP admin.

```php
// Substrate keys (read by both plugins).
add_filter( 'newspack_nodes/config', static function ( $config ) {
    $config['base_directory']   = '/tmp/newspack-nodes';
    $config['num_partitions']   = 4;
    $config['memcache_servers'] = [ '127.0.0.1:11211' ];
    return $config;
} );

// Application keys (this plugin only).
add_filter( 'newspack_event_logger_nodes/config', static function ( $config ) {
    $config['enable_aggregator'] = true;   // hub mode (strict === true, default OFF)
    return $config;
} );
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
| **Event Aggregator (Raw Logs)** | `events.{recent, stats}` | `subscribe=firehose.pN` | Direct firehose tail |
| **Errors** | — | `subscribe=errors.pN` | Tail of `errors.log` |
| **Workers** (substrate-owned) | `workers.{list, restart, …}` | — | Substrate's `Workers_CI` (lock-dir scan + offsetlog cursors) |
| **Performance Logger settings** | `logger.{config, hooks}`, `performance.{config_get, config_update, settings_update, hooks_registered, hooks_categories, hooks_available, hooks_configure}` | — | WP options |
| **Aggregator Admin (hub-only)** | `aggregator.{status, health, servers}`, `servers.{list, get, add, update, delete, test}` | — | `Server_Registry` + `Stream_Merger_Node` per-remote state |
| **Status probe** | `status.get` | — | Version, partitions, active topologies, cache reachability |

For the full per-CI verb tables and TM_COMMAND envelope shape, see [API.md](API.md).

## Migration from Newspack Event Logger Plugins

The 10-plugin monorepo (`newspack-event-logger-plugins`) is being replaced wholesale — no shadow mode or dual emission. The two stacks can coexist on the same site during cutover: legacy writes to `/volumes/pyrobase/tmp/event-logger`, this plugin defaults to `/tmp/newspack-nodes`, and the WP-CLI verbs are distinct (`wp eventlog reqgrep` vs `wp nodes reqgrep`).

## License

GPL-2.0-or-later

## Status

v0.8.x. The M6 dashboard consolidation (per-dashboard SSE controllers → single substrate `/messages/stream`) and the M2–M5 controller→CI migration are complete; all dashboards ride the substrate's `_http` / `_sse` / `_heartbeat` spine with a canonical view contract (pending-Map gate, TM_ERROR isolation, `_errorMessage()` helper). See `CHANGELOG.md` for the version-by-version history.
