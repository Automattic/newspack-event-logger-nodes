# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime. Replaces the 10-plugin `newspack-event-logger-plugins` monorepo: high-throughput WordPress request lifecycle logging, real-time SSE streaming, flame graph generation, and hub/spoke aggregation across multiple sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes). Both plugins must be installed and active; the runtime must load first.

Application classes (`RequestBuilder`, `FlameBuilder`, `JobRouter`, `JobWorker`, `StreamMerger`, `StatsAggregator`) are plain `Newspack_Nodes\Node` subclasses with their own `fill()` bodies. The runtime owns the wiring; this plugin owns the data-processing logic.

## Quick Start

```bash
# Install both plugins from their GitHub releases.
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-nodes/releases/download/v0.1.0/newspack-nodes.zip
wp plugin install --force --activate \
  https://github.com/Automattic/newspack-event-logger-nodes/releases/download/v0.1.0/newspack-event-logger-nodes.zip

# Verify the runtime sees this plugin's worker topology.
wp nodes ls
```

Or download the zips from the [Releases](https://github.com/Automattic/newspack-event-logger-nodes/releases) page and upload via the WordPress admin.

Configuration is read via the `newspack_nodes/config` filter; site-specific settings (Enable Workers, Enable Jobs, Enable Aggregator, Remote Servers) are also editable from the **Performance Workers** admin page.

```php
add_filter( 'newspack_nodes/config', static function ( $config ) {
    $config['base_directory']   = '/tmp/newspack-nodes';
    $config['num_partitions']   = 4;
    $config['memcache_servers'] = [ '127.0.0.1:11211' ];
    $config['enable_workers']   = true;   // hub mode (strict === true)
    return $config;
} );
```

Remote spokes (when this site is the hub) are managed at **WP Admin → Performance Workers → Remote Servers**, or programmatically via the `ServerRegistry` class.

## Features

Application graph backed by `newspack-nodes` partitions, surfaced as dashboards and SSE streams:

| Dashboard | Endpoint family | Source |
|-----------|----------------|--------|
| **Performance** | `/perf/overview`, `/perf/requests`, `/perf/urls`, `/perf/settings` | `RequestBuilder` + `FlameBuilder` + `StatsAggregator` |
| **URL detail / Flame graph** | `/perf/urls/{hash}` | Per-URL flame stats from `Stats_Store` |
| **Request profile** | `/perf/requests/{rid}` | Partition scan via `.idx` |
| **Gyroscope** | `/gyroscope`, `/gyroscope/stream` (SSE) | `RequestBuilder` in-flight cache |
| **Request Log** | `/request-log`, `/requests/stream` (SSE) | Tail of `requests.log` |
| **Raw Logs** | `/rawlogs`, `/firehose/stream` (SSE) | Direct firehose tail |
| **Errors** | `/errors/stream` (SSE) | Tail of `errors.log` |
| **Worker Status** | `/workers` | Lock-dir scan + offsetlog cursors |
| **Settings** | `/logger`, `/perf/hooks`, `/perf/hooks-available`, `/settings` | WP options |
| **Aggregator** | `/aggregator`, `/aggregator/status`, `/servers` | `StreamMerger` per-remote state |

All endpoints sit under `/wp-json/newspack-nodes/v1/`. For request/response shapes, see [API.md](API.md).

## Migration from Newspack Event Logger Plugins

The 10-plugin monorepo (`newspack-event-logger-plugins`) is being replaced wholesale. There is no shadow mode or dual emission — clean cutover. See [MIGRATION.md](MIGRATION.md) for the React-tree namespace rewrites and the legacy → new endpoint mapping.

## License

GPL-2.0-or-later

## Status

v0.1.0 — initial public release. Working prototype, not yet in production. The runtime substrate is drawer-stable; this plugin's application port is feature-complete for the dashboards listed above, with the cutover happening on `bendsource.com` and other newspack sites.
