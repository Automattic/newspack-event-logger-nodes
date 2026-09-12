# Upgrading

Breaking changes that affect a consumer of this plugin — a dashboard built on its service CIs, an MCP client, a topology, a sibling plugin logging through `Log_Manager` — with the fix beside each. Start at your installed version and apply everything above it. Internal refactors and fixes are not listed; [CHANGELOG.md](../CHANGELOG.md) has the full story per release.

**Maintenance rule:** a release that changes any consumer-facing contract adds its entry here in the same commit as its CHANGELOG entry. No entry means nothing to do.

## Unreleased

- **The substrate floor RISES with this release.** The dashboards send the
  substrate's renamed verbs (`raw-logs dump_log`, `workers dump_cleanup`,
  `aggregator list_servers`, `topologies dump`), so this plugin needs the
  substrate release that carries them; [the loader's version floor](../newspack-event-logger-nodes.php)
  names it, and below it the plugin goes dormant behind an admin notice rather
  than rendering rails that every fetch refuses. Fix: update `newspack-nodes`
  first.

- **`rules list` is RENAMED to [`rules dump`](API.md#rules--per-url-logging-ruleset-crud), and the MCP tool `rules_list` to
  `dump_rules`.** The substrate's own vocabulary is the rule: `list_nodes`
  prints one row per node and `dump_node` prints a node's whole structure, and
  every rule in the reply carries its resolved hooks, so the read is a dump.
  The old verb is refused as `unknown command: list`, with no alias, and the
  old tool name is absent from `tools/list`. A caller sending
  `command( 'list', [] )` to the `rules` CI, or
  `useCommandOnce( { ci: 'rules', command: 'list' } )`, changes the verb to
  `'dump'`; [`useRulesGraph`](../src/rules/useRulesGraph.js) returns the re-read callback as `dump()` rather
  than `list()`; an MCP client calls `dump_rules`.

- **Five `performance` verbs are RENAMED verb first: `request_search` is
  `search_requests`, `request_grep` is `grep_requests`, `request_detail` is
  `dump_request`, `url_detail` is `dump_url`, and `hooks_registered` is
  `list_hooks`.** The substrate's builtins set the grammar (`list_nodes`,
  `dump_node`, `make_node`, `set_sink`), and a query with no verb is a plain
  noun (`overview`, `urls`, `summary`); a name with the noun first, or two
  nouns and no verb, reads as neither. Each old name is refused as
  `unknown command: <name>`, with no alias. An `addSliceFetcher` or
  `useCommandOnce` aimed at the [`performance`](API.md#performance--the-omnibus-dashboard-ci) CI changes its `command` to the
  new spelling, and a `scope` named after the verb follows it; a
  `formatCommandArgs` call naming the verb changes the same way. The four MCP
  tools wrapping the first four take the verb's name — `search_requests`,
  `grep_requests`, `dump_request` and `dump_url` replace
  `performance_request_search`, `performance_request_grep`,
  `performance_request_detail` and `performance_url_detail` — and the old
  names are absent from `tools/list`. `performance_overview`,
  `performance_urls` and `performance_ask` keep the interpreter prefix,
  because their verbs are plain nouns. The substrate renames `workers
  cleanup_status`, `aggregator servers_status` and `raw-logs log_status` in the
  same pass; its [`docs/upgrading.md`](https://github.com/Automattic/newspack-nodes/blob/main/docs/upgrading.md) lists them.
