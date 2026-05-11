# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](../newspack-nodes/) runtime. Replaces the 10-plugin `newspack-event-logger-plugins` monorepo: high-throughput WordPress request lifecycle logging, real-time SSE streaming, flame graph generation, and hub/spoke aggregation across multiple sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL — lives in [`newspack-nodes`](../newspack-nodes/). Both plugins must be installed and active.

Application classes (`RequestBuilder`, `FlameBuilder`, `JobRouter`, `JobWorker`, `StreamMerger`, `StatsAggregator`) are plain `Newspack_Nodes\Node` subclasses with their own `fill()` bodies. The runtime owns the wiring; this plugin owns the data-processing logic.

## Quick Start

Install both plugins into a WordPress site (this plugin depends on `newspack-nodes`).

```bash
# Activate. Order matters: runtime first.
wp plugin activate newspack-nodes newspack-event-logger-nodes

# Verify the runtime sees this plugin's worker topology.
wp nodes ls
```

Configuration is read via the `newspack_nodes/config` filter. Defaults live in `PerformanceControllerBase::load_config()`:

```php
add_filter( 'newspack_nodes/config', static function ( $config ) {
    $config['base_directory']    = '/volumes/pyrobase/tmp/newspack-nodes';
    $config['num_partitions']    = 4;
    $config['memcache_servers']  = [ '127.0.0.1:11211' ];
    $config['enable_workers']    = true;   // hub mode (strict === true)
    $config['aggregator_servers'] = [
        'spoke-01' => [ 'url' => 'https://...', 'token' => '...' ],
    ];
    return $config;
} );
```

## Features

Nine dashboards backed by the application graph:

| Dashboard | Endpoint family | Source |
|-----------|----------------|--------|
| **Performance** | `/performance/dashboard`, `/performance/timing` | `RequestBuilder` + `FlameBuilder` + `StatsAggregator` |
| **URL detail / Flame graph** | `/performance/urls/{hash}` (planned) | Per-URL flame stats from `Stats_Store` |
| **Request profile** | `/performance/requests/{rid}` (planned) | Direct partition scan via `.idx` |
| **Gyroscope** | `/gyroscope/timeline` (SSE planned) | `RequestBuilder` in-flight cache |
| **Request Log** | `/request-log/list`, `/request-log/detail/{id}` (SSE planned) | Tail of `requests.log` |
| **Raw Logs** | `/events/recent`, `/events/stats` (SSE planned) | Direct firehose tail |
| **Worker Status** | `/performance/workers` (planned) | Lock-dir scan + offsetlog cursors |
| **Settings** | `/logger/config`, `/logger/hooks` | WP options |
| **Aggregator Status** | `/newspack-nodes-aggregator/v1/{status,servers,health}` | `StreamMerger` per-remote state |

For the full endpoint list and request/response shapes, see [API.md](API.md).

## Migration from Newspack Event Logger Plugins

The 10-plugin monorepo (`newspack-event-logger-plugins`) is being replaced wholesale. There's no shadow mode or dual emission — clean cutover. See [MIGRATION.md](MIGRATION.md) for the React-tree namespace rewrites and [`services/pyrobase/sources/.specs/2026-05-06-newspack-nodes-design.md`](../../../.specs/2026-05-06-newspack-nodes-design.md) section 5 for the production cutover plan.

## License

GPL-2.0-or-later

## Status

Prototype. The runtime substrate is drawer-stable; the application port is in progress. Not yet in production.
