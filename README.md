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
| **Performance** | `/performance/overview`, `/performance/urls`, `/performance/dashboard`, `/performance/timing` | `RequestBuilder` + `FlameBuilder` + `Stats_Store` |
| **URL detail / Flame graph** | `/performance/urls/{hash}` | Per-URL flame stats from `Stats_Store` |
| **Request profile** | `/performance/requests/{rid}`, `/performance/requests/search/{rid}` | Partition scan via `.idx` |
| **Gyroscope** | `/gyroscope/timeline`, `/firehose/gyroscope` (SSE) | `RequestBuilder` in-flight cache |
| **Request Log** | `/request-log/list`, `/request-log/detail/{id}`, `/firehose/requests` (SSE) | Requests index + `requests.log` |
| **Raw Logs** | `/events/recent`, `/events/stats`, `/firehose/rawlogs` (SSE), `/firehose/stream` (SSE) | Direct firehose tail |
| **Errors** | `/firehose/errors` (SSE) | Tail of `errors.log` |
| **Workers** | `/performance/workers`, `/performance/workers/restart` | `Bootstrap::expand_workers()` + lock-dir scan + offsetlog cursors |
| **Settings** | `/logger/config`, `/logger/hooks`, `/performance/config`, `/performance/settings`, `/performance/hooks/available`, `/performance/hooks/configure`, `/performance/registered-hooks`, `/performance/hook-categories`, `/settings` | WP options |
| **Aggregator (hub-only)** | `/newspack-nodes-aggregator/v1/status`, `/servers`, `/health`, `/servers/{id}`, `/servers/{id}/test` | `ServerRegistry` + `StreamMerger` per-remote state |

All non-aggregator endpoints sit under `/wp-json/newspack-nodes/v1/`. For request/response shapes, see [API.md](API.md).

## Migration from Newspack Event Logger Plugins

The 10-plugin monorepo (`newspack-event-logger-plugins`) is being replaced wholesale. There is no shadow mode or dual emission — clean cutover. See [MIGRATION.md](MIGRATION.md) for the React-tree namespace rewrites and the legacy → new endpoint mapping.

## License

GPL-2.0-or-later

## Status

v0.1.0 — initial public release. Working prototype. Feature-complete for the dashboards listed above; the legacy `newspack-event-logger-plugins` monorepo can coexist on the same site during cutover (different firehose paths, different worker pools).
