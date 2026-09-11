# Dashboards

The substrate's [browser runtime](https://github.com/Automattic/newspack-nodes/blob/main/docs/browser-runtime.md) chapter covers the graph every page mounts, the command POST, the record stream and the slot pool. This chapter covers what the Event Logger builds on it: five dashboards, one overlay tab, six build entries.

Every dashboard is a React page in wp-admin, and none runs a fetch loop. Each is a topology in the browser: a Timer ticks on the page's heartbeat, a Fetcher per verb sends its command, and the reply lands on a view node that one widget draws. A page reaches a worker two ways, both the substrate's: a verb answered on `POST /wp-json/newspack-nodes/v1/command`, and a subscription on `GET /wp-json/newspack-nodes/v1/messages/stream`.

## The five dashboards

| Dashboard | Reads | Through |
|---|---|---|
| **Performance** | The statistics Flame_Builder_Node folds into memcache and the requests index: site totals, the URL leaderboard, one URL's aggregate flame, one request's trace with its findings | The `performance` verbs, plus the `rules` verbs behind "Log this URL" |
| **Gyroscope** | The in-flight snapshot Request_Flight_Node writes to `gyroscope` on the router's one-second tick | A subscription on `gyroscope.*` |
| **Request Log** | `completed.p0`, one row per finished request | The substrate's `raw-logs` verbs and a subscription on `completed.*` |
| **Errors** | `errors.p0`: Request_Builder_Node's error and warning lines, and the runtime's own diagnostics the bridge carries in | The substrate's `raw-logs` verbs and a subscription on `errors.*` |
| **Settings** | The three switches, the effective configuration and the ruleset | The `rules` verbs and `performance.hooks_registered`; a rule change applies at once, with no Save |

The map in [the Event Logger](the-event-logger.md#assembling-a-request) draws which partition each one reads. Two shapes of reading follow from the table. Performance and Settings ask verbs on the page's tick and draw the reply. Gyroscope, Request Log and Errors subscribe to a partition, and their rows are read off the view node each frame rather than becoming React state, so a busy stream never re-renders React per message.

`performance.hooks_registered` answers two dashboards because one editor serves both: "Log this URL" on Performance opens the same rule editor, and the same hook picker beneath it, that the Settings ruleset table opens.

## The current-request overlay

The sixth bundle is a tab in the substrate's debug overlay, which four of the five dashboards mount, Settings excepted, and `?nodes-debug=1` turns on. The page localizes its own request id and partition, and the tab polls `performance.request_detail` for that record each tick: the workers assemble it after the request ends, so a just-loaded page reads as still processing for a beat, then shows its duration, status, errors and peak memory, and links out to the full trace on Performance. It is registered on the substrate's `newspack_nodes/devtools_tab_bundles` filter for the Nodes page and enqueued directly on those four dashboards.

## Six build entries

`scripts/build.mjs` names six entries and hands them to the substrate's build kit: `overview` (Performance), `error-log`, `gyroscope`, `requests` (Request Log) and `settings`, which the substrate's `enqueue_react_page()` mounts by directory name, and `current-request`, the overlay tab. Each entry emits its bundle, its stylesheet and RTL companion, and the `.asset.php` manifest PHP reads for the script handles and the version. The `rules` editor is not a seventh entry; it is a React root the `settings` tree mounts into the settings page's "Logging Rules" section. The kit inlines the shared hooks, the view-node base and the overlay into every bundle, so a substrate edit reaches a page only when this plugin rebuilds.

## Read more

- [architecture-guide.md](architecture-guide.md), the sections [REST + React](architecture-guide.md#rest--react) and [React trees](architecture-guide.md#react-trees)
- [API.md](API.md), the service-CI verb tables and the TM_COMMAND envelope
- [newspack-nodes/docs/writing-a-dashboard.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/writing-a-dashboard.md)
