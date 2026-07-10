# Newspack Event Logger Nodes

Application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime. It replaced the legacy `newspack-event-logger-plugins` monorepo: high-throughput WordPress request lifecycle logging, real-time SSE streaming, flame graph generation, and hub/spoke aggregation across multiple sites.

## Relation to newspack-nodes

This plugin is the *application*. The *runtime* — Node, Message, Router, Topic, Partition, Worker, Supervisor, REPL — lives in [`newspack-nodes`](https://github.com/Automattic/newspack-nodes). Both plugins must be installed and active; the runtime must load first. The plugin header declares `Requires Plugins: newspack-nodes` (WordPress 6.5+ keeps the runtime active), and the deferred bootstrap is gated on a `class_exists` substrate-presence check so it no-ops gracefully if the runtime isn't loaded.

Application classes (`Request_Builder_Node`, `Flame_Builder_Node`, `Job_Router_Node`, `Auto_Tuner_Node`, `Remote_Job_Rewrite_Node`, `Discovery_Collector_Node`, …) are plain `Newspack_Nodes\Node` subclasses with their own `fill()` bodies — Node subclasses carry a `_Node` suffix; helpers (`Log_Manager`, `Stats_Store`) don't. The generic `Job_Worker_Node` executor moved to the `newspack-nodes` substrate (`\Newspack_Nodes\Job_Worker_Node`); this plugin contributes only the per-job request-context glue (`Log_Manager::begin/end_job_context`, hooked onto `newspack_nodes/job_worker/{before,after}_job`). The runtime owns the wiring; this plugin owns the data-processing logic. The plugin registers its class namespace with the substrate (`Topology_Registry::register_plugin( 'Newspack_Event_Logger_Nodes\\', …/topologies )` for application nodes + `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' )` for service CIs), so a topology's `make_node Flame_Builder` resolves `\Newspack_Event_Logger_Nodes\Flame_Builder_Node` by prefix — no per-class registration.

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

Substrate-level settings (base directory, partitions, memcache, active topologies) are read by the substrate's `Newspack_Nodes\Config::load_config()` from `newspack-nodes-config.php` (in the `newspack-nodes` plugin) merged with the substrate's WP-options overlay. Application-level settings (the per-URL logging ruleset, debug toggles, admin allowlist) are read by this plugin's `Newspack_Event_Logger_Nodes\Config::load_config()` from `newspack-event-logger-nodes-config.php` merged with the `Settings_Schema` WP-options overlay — most are editable from the WP admin. (Remote-pull segment sizing moved to the substrate's `newspack_nodes_*` config.)

```php
// Substrate keys live in newspack-nodes-config.php (the newspack-nodes plugin).
return [
    'base_directory'   => '/tmp/newspack-nodes',
    'num_partitions'   => 4,
    'memcache_servers' => [ '127.0.0.1:11211' ],
];

// Application keys live in newspack-event-logger-nodes-config.php (this plugin).
return [
    'enable_logging' => true,
    // Per-URL logging ruleset seed (the editor owns it once saved).
    'rules'          => [
        [ 'pattern' => '/wp-cron.php', 'action' => 'skip' ],
        [ 'pattern' => '/', 'action' => 'log' ],
    ],
];
```

There is no operator hub toggle — `enable_aggregator` (like `enable_workers` before it) was retired. Hub-mode is derived from whether the `aggregator` topology is in the substrate's `topologies` list. Remote-spoke credentials (when this site is the hub) live in the substrate **Vault** (the substrate's `vault` CI); the per-spoke `Remote_Source` nodes are wired on the topology console canvas.

## Features

Application graph backed by `newspack-nodes` partitions, surfaced as dashboards and SSE streams:

Every dashboard sends TM_COMMAND envelopes to a service CI via the substrate's single `POST /wp-json/newspack-nodes/v1/command` endpoint, and consumes live data through the substrate's single `/wp-json/newspack-nodes/v1/messages/stream` SSE endpoint. The "verbs" column below lists the CI verb each dashboard calls; the live-data column lists the substrate stream it subscribes to.

| Dashboard | Service CI verbs | Live data | Source |
|-----------|------------------|-----------|--------|
| **Performance Dashboards** | `performance.{overview, urls, url_detail}` | — | `Request_Builder_Node` + `Flame_Builder_Node` + `Stats_Store` |
| **Request profile** | `performance.{request_search, request_detail}` | — | Partition scan via `.idx` |
| **Performance Gyroscope** | — | `subscribe=gyroscope.pN` | `Request_Flight_Node` snapshots + `completed:tee` fan-out |
| **Request Log** | — | `subscribe=completed.pN` | Requests index + `requests.log` |
| **Event Aggregator (Raw Logs)** | `events.stats` | `subscribe=firehose.pN` | Direct firehose tail |
| **Errors** | — | `subscribe=errors.pN` | Tail of `errors.log` |
| **Workers** (substrate-owned) | `workers.{list, restart, …}` | — | Substrate's `Workers_CI` (lock-dir scan + offsetlog cursors) |
| **Performance Logger settings** | `logger.{config, hooks}`, `performance.{hooks_registered, set}`, `discovery.get` | — | WP options |
| **Logging Rules editor** (on the settings page) | `rules.{list, save, upsert, delete}` | — | `Rule_Set` (autoloaded ruleset option + per-rule hook options) |
| **Aggregator Admin (hub-only, substrate-owned)** | substrate `aggregator.*` + `vault.*` CIs | — | Substrate `Remote_Source_Node` per-spoke state + Vault |
| **Status probe** (substrate-owned) | substrate `status.get` | — | Version, partitions, active topologies, cache reachability |

For the full per-CI verb tables and TM_COMMAND envelope shape, see [docs/API.md](docs/API.md).

## Topologies

Per-partition node graphs ship as declarative `.tsl` files in `topologies/`: `aggregator.tsl`, `hub-control.tsl`, `request-builder.tsl`, `job-router.tsl`, `flame-builder.tsl`, `combined.tsl`, and `performance.tsl` (plus the substrate's runtime-only `firehose` / `jobintake` basenames). Which topologies are active is the substrate's `topologies` config key. Hub vs spoke is derived from topology membership (no operator toggle): a spoke runs the request/job/flame graphs locally, while a hub additionally runs `aggregator.tsl` (the substrate `Remote_Source_Node` pull-side feeding ELN's `Remote_Job_Rewrite_Node`) and the single-instance `hub-control.tsl` (settings-sync + discovery fan-out). See [docs/architecture-guide.md](docs/architecture-guide.md) for the full per-topology breakdown and hub/spoke flow.

## Cache Warmer (moved)

The refresh-ahead cache warmer that used to ship here was extracted into its own plugin, **newspack-cache-cozy**, in v0.15.0. The standalone profiler asset `00-newspack-profiler.php` still ships as an mu-plugin drop-in alongside this plugin.

## Configuration / Settings

As of 0.13.0 the settings layer is built on the substrate's shared Config System (`Newspack_Nodes\Config_System\Field` / `Schema` / `Settings_Renderer`). This plugin declares a single declarative `Settings_Schema` (`includes/class-settings-schema.php`); the WP-options overlay that `Config::load_config()` applies, the per-field reset (↺) UI, and the delete-on-blank gate are all the shared `Newspack_Nodes\Config_System` machinery (`Options_Overlay`, `Reset_Gate`).

**Which URLs and hooks get logged is a per-URL ruleset, not a global setting (v0.26.0).** The seven old global options (`log_urls` / `skip_urls` / `log_events` / `custom_events` / `significant_events` / `auto_disable_threshold` / `auto_protect_time_threshold`) were replaced by an ordered list of **rules** — each a URL pattern (prefix `/x` or exact `/x?`) with a `log`/`skip` action and, for `log` rules, its own hooks, custom events, significant events, and auto-tune thresholds. Matching is longest-prefix-wins and case-insensitive; no rule matches ⇒ skip, and there is no implicit log-all baseline (declare a `/` log rule to log everything). Rules are edited in the "Logging Rules" editor on the settings page (the `rules` CI backs it) and seeded from `newspack-event-logger-nodes-config.php`'s `rules` key until the editor first writes the option. New classes: `Rule`, `Rule_Set`, `Rule_Matcher`.

## Migration from Newspack Event Logger Plugins

The legacy `newspack-event-logger-plugins` monorepo was replaced wholesale by this plugin (application) plus `newspack-nodes` (substrate). That monorepo has since been removed from the tree ("the museum") — there is no live coexisting stack. This plugin defaults its storage to `/tmp/newspack-nodes` and exposes `wp nodes reqgrep` for firehose searching.

## License

GPL-2.0-or-later

## Status

0.28.1. The dashboard consolidation (per-dashboard SSE controllers → single substrate `/messages/stream`) and the controller→CI migration are complete; all dashboards ride the substrate's `_http` / `_sse` / `_heartbeat` spine with a canonical view contract (pending-Map gate, TM_ERROR isolation, `_errorMessage()` helper). Post-0.8 milestones: `Job_Worker_Node` executor moving to the substrate (0.12.0), migration onto the substrate's shared Config System (0.13.0), the refresh-ahead cache warmer extracted to the standalone `newspack-cache-cozy` plugin (0.15.0), `Request_Builder_Node` becoming a `Timer_Node` plus `void_warranty` lock-free output partitions (0.16.0), the jobs-log kind field normalized to `k` end-to-end (0.16.1), per-worker-URL stats rows fully excluded from global aggregates (0.17.0), adoption of the substrate's flat partition-in-name layout plus `parse_schema_args` delegation (0.18.0), and — most significantly — the **per-URL logging ruleset** that replaced the seven global logging/auto-tune options with an ordered `Rule`/`Rule_Set`/`Rule_Matcher` set (0.26.0), whose rule ids are the pattern's `url_hash`, whose activation-time migration is version-gated at `SCHEMA_VERSION=2` (0.28.0), and whose editor is a fifth `rules` service CI on the settings page. The `status.get` verb reports both the application `version` and the substrate `runtime_version`. See `CHANGELOG.md` for the version-by-version history.
