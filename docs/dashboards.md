# Dashboards

The substrate's [browser runtime](https://github.com/Automattic/newspack-nodes/blob/main/docs/browser-runtime.md) chapter covers the graph every page mounts, the command POST, the record stream and the slot pool. This chapter covers what the Event Logger builds on it: five dashboards, one overlay tab, six build entries.

Every dashboard is a React page in wp-admin, and none runs a fetch loop. Each is a topology in the browser: a Timer ticks on the page's heartbeat, a Fetcher per verb sends its command, and the reply lands on a view node that one widget draws. A page reaches a worker two ways, both the substrate's: a verb answered on [`POST /wp-json/newspack-nodes/v1/command`](API.md#sending-a-command), and a subscription on [`GET /wp-json/newspack-nodes/v1/messages/stream`](API.md#sse-messagesstream).

## The five dashboards

| Dashboard | Reads | Through |
|---|---|---|
| **Performance** | The request and timing rows [Flame_Builder_Node](../includes/class-flame-builder-node.php) writes to the `flame-facts` Row_Table, and the requests partition: site totals, the URL leaderboard, the charts' `query` series, one URL's aggregate flame, one request's trace with its findings | The [`performance`](API.md#performance--the-omnibus-dashboard-ci) verbs, plus the [`rules`](API.md#rules--per-url-logging-ruleset-crud) verbs behind "Log this URL" |
| **Gyroscope** | The in-flight snapshot [Request_Flight_Node](../includes/class-request-flight-node.php) writes to `gyroscope` on the router's one-second tick | A subscription on `gyroscope.*` |
| **Request Log** | `completed.p0`, one row per finished request | The substrate's [`raw-logs`](API.md#substrate-verbs-the-dashboards-use) verbs and a subscription on `completed.*` |
| **Errors** | `errors.p0`: [Request_Builder_Node](../includes/class-request-builder-node.php)'s error and warning lines, and the runtime's own diagnostics the bridge carries in | The substrate's `raw-logs` verbs and a subscription on `errors.*` |
| **Settings** | The three switches, the effective configuration and the ruleset | The `rules` verbs and `performance.list_hooks`; a rule change applies at once, with no Save |

The map in [the Event Logger](the-event-logger.md#assembling-a-request) draws which partition each one reads. Two shapes of reading follow from the table. Performance and Settings ask verbs on the page's tick and draw the reply. Gyroscope, Request Log and Errors subscribe to a partition, and their rows are read off the view node each frame rather than becoming React state, so a busy stream never re-renders React per message.

Performance keeps its view in the address bar, so a shared link opens the same view: `?server=`, `?metric=`, `?breakdown=`, `?then=`, one `?chip=field=value` per Overview filter chip, `?bucket=` for the table's time selection, and `?url=` and `?request=` for the open URL and request. A chip is its own key, so a value holding a comma, such as a user agent, survives. A linked value the controls would not offer is dropped on read, a chip by the rule its editor applies to a typed one: `field=value` or `field!=value`, a field the chip box offers, in any case, and a value that is not empty. A `field!=value` chip leaves its value out and reads `Field ≠ value`. A chip already held, typed or linked, is not added twice. The filters are state, not navigation: Back and Forward keep them as they stand and walk only the `?url=` and `?request=` views. An open URL modal adds its own controls under a `url_` prefix: `?url_bucket=` for its Time field, `?url_metric=`, `?url_breakdown=` and `?url_then=` for its chart, one `?url_chip=` per chip, and `?url_sort=` and `?url_order=` for its Recent Requests table. Each is left out at its default, and every one leaves the bar once the modal closes. A link restores them under the same rules, a `url=` chip refused with the rest, since the modal is already one URL, and `?url_bucket=` passing through as `?bucket=` does, for the server to judge. A URL opened from the table starts on the table's time selection and chips, and its chart on the defaults; any other starts on the default chips, Time and chart. The Recent Requests sort carries over from one modal to the next. A Then by naming the Breakdown chosen, the Overview's or the modal's, is dropped, on a link and when a Breakdown pick makes them meet. Under a server fallback, where the Overview draws Status Class in place of Server, the chart drops a Then by that repeats it on its own, and the choice returns with the Server axis.

`performance.list_hooks` answers two dashboards because one editor serves both: "Log this URL" on Performance opens the same rule editor, and the same hook picker beneath it, that the Settings ruleset table opens.

## The current-request overlay

The sixth bundle is a tab in the substrate's debug overlay, which four of the five dashboards mount, Settings excepted, and `?nodes-debug=1` turns on. The page localizes its own request id, and the tab polls `performance.dump_request` for that record each tick: the workers assemble it after the request ends, so a just-loaded page reads as still processing for a beat, then shows its duration, status, errors and peak memory, and links out to the full trace on Performance. Where the stats rows do not open, as on a site running no `flame-builder` topology, no ask can find the record, so the tab shows that refusal and stops asking. It is registered on the substrate's [`newspack_nodes/station_tab_bundles`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md#filters) filter for the Nodes page and enqueued directly on those four dashboards.

## Six build entries

[`scripts/build.mjs`](../scripts/build.mjs) names six entries and hands them to the substrate's [build kit](https://github.com/Automattic/newspack-nodes/blob/main/docs/browser-runtime.md#one-build-kit): `overview` (Performance), `error-log`, `gyroscope`, `requests` (Request Log) and `settings`, which the substrate's [`enqueue_react_page()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/admin/class-admin.php) mounts by directory name, and `current-request`, the overlay tab. Each entry emits its bundle, its stylesheet and RTL companion, and the `.asset.php` manifest PHP reads for the script handles and the version. The `rules` editor is not a seventh entry; it is a React root the `settings` tree mounts into the settings page's "Logging Rules" section. The kit inlines the shared hooks, the view-node base and the overlay into every bundle, so a substrate edit reaches a page only when this plugin rebuilds.

## Read more

- [architecture-guide.md](architecture-guide.md), the sections [REST + React](architecture-guide.md#rest--react) and [React trees](architecture-guide.md#react-trees)
- [API.md](API.md), the service-CI verb tables and the TM_COMMAND envelope
- [newspack-nodes/docs/writing-a-dashboard.md](https://github.com/Automattic/newspack-nodes/blob/main/docs/writing-a-dashboard.md)
