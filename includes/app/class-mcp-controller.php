<?php
/**
 * MCP_Controller: an MCP server over the verbs the dashboards already drive.
 *
 * The goal is an "Ask AI" button that points a reader at the problem and,
 * where the plugins allow, at the P2s, Linear issues and repos that explain it.
 * An in-plugin LLM call ships faster and is the wrong shape: it buys a
 * dashboard that summarises itself to one model behind one proxy publishers
 * cannot reach, and it cannot see a Linear issue at all. Exposing the data lets
 * an agent that ALREADY holds those context providers do the correlation, which
 * is the thing actually wanted.
 *
 * The door is a declaration on the substrate's `Mcp_Door`, which owns the
 * protocol, the session ceiling, the meter and the `<site-data>` fence. This
 * class names the tools, the glossary and the measurement caveat, because a
 * model handed a profiled/duration ratio without one will invent a cause for
 * the difference.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Mcp_Door;

\defined( 'ABSPATH' ) || exit;

/**
 * Declares ELN's MCP door: its namespace, tools, glossary and caveat.
 */
class MCP_Controller extends Mcp_Door {

	/** The glossary `initialize` serves between the fence note and the caveat. */
	private const INSTRUCTIONS = 'The ruleset tools change what the site logs, so use '
		. 'them only on the operator\'s own request. A URL, also called a shape, is one host and path '
		. 'and the query keys it was asked with, sorted, each value masked as `?`, which is why URLs '
		. 'read `?oid=?`; a routing key such as `rest_route` keeps its value with digit segments '
		. 'masked, tracking keys such as `utm_*` and `fbclid` are dropped, and a worker\'s request '
		. 'carries its worker as a bare key, so each worker is a URL of its own. Case and scheme do '
		. 'not part a URL: its hash reads it lowercased and as `https://`. A page is a URL\'s host '
		. 'and path lowercased, its scheme, query and worker mark dropped, so it folds every shape. An error '
		. 'counts timeouts and fatals only; a 5xx response is `status_class=5`. An `owner` is the '
		. 'code that ran: a plugin by its slug, `mu:<slug>`, `theme:<slug>`, `dropin`, `platform` '
		. 'for the host\'s own code, or `core`, which also holds the dispatch inside a top-level '
		. 'hook whose listeners are timed; '
		. '`(unowned)` is time no owned frame encloses, and `(unmeasured)` the duration no span '
		. 'covers, which the owner lists leave out and performance_query\'s `owners` source answers '
		. 'as a row. Profile categories overlap, since a listener\'s time is also its hook\'s, so '
		. 'they do not sum to the request. A bucket is a five-minute `Y-m-d-H-i` UTC slot.';

	/**
	 * Tool name → the CI node and verb behind it. `args` names the verb args a
	 * tool offers, each with the description an agent reads. The capability,
	 * and each arg's type, requiredness and variadic-ness, come from the verb's
	 * own declaration on `class`. `{window}` and `{kept}` in a description are
	 * the stats span and the buckets the rows keep, filled in at listing, and
	 * `{declared}` in an arg's is the verb's own description of that arg.
	 * A constant rather than inline in `tools()`, because the tests reflect on it.
	 *
	 * @var array<string,array{node:string,class:class-string<Command_Interpreter_Node>,verb:string,summary:string,args:array<string,string>}>
	 */
	private const TOOLS = [
		'performance_overview'     => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'overview',
			'summary' => 'The site over the last {window} five-minute buckets, worker traffic (cron, WP-CLI, jobs) left out. `slots` names those buckets as `Y-m-d-H-i` UTC keys, newest first. `totals` counts the requests: `requests`, `timed` (those with a measured duration), `avg_ms` over the timed, `avg_peak_mb` over every one, `errors` (timeouts and fatals) and `urls`, the exact count of distinct URLs. `servers` names every server with traffic in those buckets, worker traffic counted, busiest first. Stats that will not open answer `available` false and `totals` null. `leaderboard` covers the same buckets: `count` is the profiled requests, `total_time` their mean profiled time with a listener or Query Monitor timer counted once inside the span it overlays, `avg_ms` the timed mean of every request over those buckets, and each category\'s `time` and `count` its mean per profiled request, `samples` the requests it appeared in and `entries` each `[ avg_ms, avg_calls, samples ]` per appearance. Means are null where nothing was measured. `owners` is each owner\'s time over the same buckets, worker traffic left out: `ms_per_request` and `calls_per_request` are its summed self time and calls over the requests the `(unmeasured)` row counts (every timed request), `share` its part of all the time folded, the time no span covers included and listed under no owner, dearest per request first and empty over no such request. Chart series come from performance_query; per-page facts from performance_urls. Next: performance_urls for the pages, then dump_url (`url` or `page`) for one, then dump_request with a `rid` from its `requests` for one request.',
			'args'    => [ 'server' => 'Optional server name; scopes `totals`, `leaderboard` and `owners`, never `servers`.' ],
		],
		'performance_urls'         => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'urls',
			'summary' => 'The page table, sortable and paginated, with totals and the slowest ten for whatever the filters leave, over the last {window} five-minute buckets or a selection. A page is a URL\'s lowercased host and path, every query key folded, so a `/?rest_route=` REST call is a URL of the home page; a row is one page on one server, folding every URL of it: `page_hash`, `page`, `server`, `shapes` (the URLs folded), `hash`, the URL hash where the row folds one shape and null where it folds more, and `url`, the name the row shows: the one shape\'s own URL, its case, query and scheme kept, or `https://` and the page where it folds more; then the measures. A row\'s `worker` is true where any shape it folds is a worker\'s. A `page_hash` is never a URL hash: `dump_url`\'s `url`, `url=` and a `url:` brief find nothing under one. It is taken by `page` here, a `page=` filter, `dump_url`\'s `page`, which answers that page\'s own detail across its shapes, and a `page:` brief. `page` answers that page\'s URL rows instead, each with its `hash`, `url` and the same measures, and a `page=<page hash>` filter keeps that one page row. `totals.urls` and `totals.pages` are the exact counts of URLs and pages matching, a page counted once for each server, and `rows` the exact count of rows: the pages, or under `page` the URLs of that page. The `url` sort orders pages by `page`. Every mean is request-weighted: `avg_ms` over the timed requests, `avg_peak_mb` over every one. Nothing weights the `avg_ms` sort by `count`, so a URL seen once can lead it, and `slowest`, the ten highest `avg_ms` whatever `sort` says, the same way: read `count` beside it, or sort by `count` to find the busy slow pages. `requests_per_second` divides the requests by the seconds the buckets have run. Worker traffic is excluded unless asked for. Stats that will not open answer `available` false and `totals` null. Where no timed request reached a row, its `avg_ms`, `min_ms` and `max_ms` are null, and it ranks last on those three sorts in either order.',
			'args'    => [ 'sort' => 'count|url|avg_ms|min_ms|max_ms|avg_peak_mb|last_updated', 'limit' => 'Rows to return.', 'page' => 'A page\'s `page_hash`: lists that page\'s URL rows in place of pages. Never a URL hash.', 'search' => 'Whole words of the URL path, never the host: a URL matches when every word of the term is a whole word of its path (`wombat` finds /wombat-7731, not /wombats). Quotes, a minus and other punctuation only separate words; none is an operator. A term with no word is refused.', 'server' => 'Optional server name to scope every row and total to.', 'filter' => '{declared} A filter on `worker` replaces that scope. `url=<hash>` keeps one URL, and `page=<page hash>` keeps that one page row, as any filter narrows the pages. Narrows every row, the totals and the slowest ten together: `status_class=5` keeps the 5xx responses, most fatals among them, and `status_class=Unknown` the requests that answered no status: timeouts, and CLI or cron runs where workers count. `error=T` and `error=F` together keep the timeouts and fatals exactly. `totals.errors` counts the timeouts and fatals among what is left; a timeout carries no duration, so it counts toward `count` and `errors` alone.', 'include_workers' => 'Cron, WP-CLI and job traffic is excluded by default; set to include it.', 'bucket' => 'A selection of five-minute buckets, each `Y-m-d-H-i` in UTC, written as comma-separated runs, each one bucket or `start..end`, the buckets from `start` up to the key that closes it, `end` not among them, ascending with adjacent buckets merged: `2026-10-05-16-55..2026-10-05-17-15,2026-10-05-18-30` is the four adjacent buckets 16:55 through 17:10 and one apart. Any order and overlap is accepted, and `filters.bucket` answers the canonical spelling. A selection holds at most {window} buckets, none later than the current one; requests are kept {kept} buckets, so an older bucket holds none. Narrows every row, the totals and the slowest ten to the requests completing in the selected buckets. A key off the five-minute grid, a run not ending after it starts, a bucket in the future and more than {window} buckets are refused, each with an error naming the key or the limit.' ],
		],
		'dump_url'                 => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'dump_url',
			'summary' => 'One URL, by `url`, or every shape of one page, by `page`, from its stored request rows, worker traffic counted; name exactly one of the two, since both or neither is refused. A page answers the same shape as a URL, every read keyed on its page instead: `stats.hash` is the `page_hash` and `stats.url` the name performance_urls shows a page of several shapes by, `https://` and the page, and its stats, list, flame, profile and owners mix every shape by its traffic. `stats` covers the last {window} five-minute buckets or a selection, narrowed by any `filter`: `count`, `stats.errors` (the exact count of timeouts and fatals among the requests left, which the list cap never shortens), request-weighted means, `last_updated` and `requests_per_second`; `stats.avg_ms`, `min_ms`, `max_ms` and `avg_peak_mb` are null where nothing was measured; `stats` also carries the nearest-rank `p50_ms`, `p95_ms` and `p99_ms`, null where no request was timed, and `error_rate`, `errors` over `count`, null over no requests. `requests` lists its newest 500 by completion, `finished_at`, each with its start, `timestamp`, and a null `duration_ms` where none was measured: a timeout\'s eviction wait, an abort\'s stop, a zero. `requests_capped` true means the list holds fewer requests than `stats.count`: the newest 500 only. A stats file that will not open answers zero stats, an empty list and a null flame and profile. `aggregate_flame` is folded from the stored flames of the newest 100 of them, is null under a `filter` or a `bucket`, and is null with `flame_cut` true when the walk ran out of time; `aggregate_profiles` is its category profile over the `bucket` selection, or with none over the {kept} buckets timings are kept, null over no profiled request; `aggregate_owners` is each owner\'s time on the URL over the same selection, or with none over the buckets the owner rows keep, in the overview\'s `owners` shape: `ms_per_request` and `calls_per_request` divide by the requests the `(unmeasured)` row counts, and the list is empty over none. Breakdowns and chart series come from performance_query with `filter` `url=<hash>`, or for a page `page=<page hash>`.',
			'args'    => [ 'url' => 'The 12-char URL hash; never a `page_hash`. Refused beside `page`.', 'page' => 'A page\'s `page_hash`, as performance_urls lists it: answers every shape of that page in place of one URL. Refused beside `url`.', 'server' => 'Optional server name; scopes the stats, the list and the profile the way performance_urls scopes the row.', 'filter' => 'Narrows `stats` and the list as performance_urls narrows its rows. {declared} `status_class=5` lists the 5xx responses, most fatals among them, reaching past the clean requests that bury them in the full list, and `status_class=Unknown` the requests that answered no status: timeouts, and CLI or cron runs. `error=T` and `error=F` together list the timeouts and fatals exactly. No flame is rebuilt from a filtered list: `aggregate_flame` is null.', 'bucket' => 'A selection of five-minute buckets, as performance_urls takes it: comma-separated runs of `Y-m-d-H-i` UTC keys, each one bucket or `start..end`, the buckets from `start` up to the key that closes it, `end` not among them (`2026-10-05-16-55..2026-10-05-17-15,2026-10-05-18-30`), at most {window} buckets. `stats` and the list become the requests completing inside any of them. A URL with no traffic in the selection answers zero `count` and `errors`, null means, `min_ms` and `max_ms`, and an empty list; `URL not found` means no request row in the {kept} buckets the rows keep names the hash, and `Page not found` the same of a `page_hash`. No flame is rebuilt from a selection: `aggregate_flame` is null.' ],
		],
		'performance_query'        => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'query',
			'summary' => 'An ad-hoc question over the stored rows: group by fields, filter on them, and answer measures over a selection of five-minute buckets, every partition at once. Worker traffic (cron, WP-CLI, jobs) counts unless filtered `worker=0`, which the dashboards pass. `source` `requests` holds one row per completed request, {kept} buckets of them; its fields are `bucket`, `hour`, `day`, `server`, `url`, `page` (the URL\'s lowercased host and path, every case and query shape of it), `status`, `status_class`, `method`, `country`, `ua`, `ja4`, `from`, `worker`, `profiled` (requests whose profile filed timing rows) and `error`, and its measures `count`, `timed` (requests with a measured duration), `sum_ms`, `avg_ms` (over the timed, request-weighted), `max_ms`, `min_ms`, `p50_ms`, `p95_ms` and `p99_ms` (nearest rank over the timed), `sum_peak_mb`, `avg_peak_mb`, `max_peak_mb`, `errors` (timeouts and fatals), `error_rate` (`errors` over `count`, null over no requests), `urls` (distinct URLs), `pages` (distinct pages, a page counted once for each server it was served under), `last_seen` (the latest completion, epoch seconds), `count_2xx`, `count_3xx`, `count_4xx` and `count_5xx` (requests in each status class) and `worker` (1 where a worker served any of them); a name cannot be both grouped and measured. `source` `timings` holds per-category and per-entry hook time, {kept} buckets of it; its fields are `bucket`, `hour`, `server`, `url`, `page`, `worker`, `category`, `entry` and `listener`, and its measures `ms`, `calls`, `requests` (requests the row timed), `avg_ms` and `avg_calls` (each per timed request). A timing row whose `entry` is empty is its category\'s own total, so filter `entry=` for category totals; `worker` cannot combine with `url` or `page` there. `source` `owners` holds hook time summed by the code that owns it (a plugin, a theme, a drop-in, the host\'s platform or core), {kept} buckets of it; its fields are `bucket`, `hour`, `server`, `url`, `page`, `worker` and `owner`, and its measures `ms`, `calls` and `requests`; `worker` cannot combine with `url` or `page` there either. Answers `{ source, table, columns, rows, groups, available, filters }`, `available`, false when the stats file will not open, beside no rows: each row lists its values in `columns` order, a `url` group adds its `url_name` beside the hash, and may sort by `url_name`; a `page` group, filtered as `page=<page hash>`, adds its `page_name` beside the hash, and may sort by `page_name`; `bucket` is spelled `Y-m-d-H-i` in UTC, `hour` counts hours and `day` UTC days since the epoch, an absent status, country, agent, JA4 or From reads `Unknown`, and a request with no error reads `-`. Means are null where nothing was timed. A null sorts last in either order. `top` answers a chart: the tuples of the non-time group fields rank by `top_by` over the whole selection, and every `bucket`, `hour` or `day` row of the top ones answers, in rank order; `groups` counts every tuple the selection holds, so a chart can say it shows N of them, and is null with no `top`. An unknown source, field, measure or sort is refused, naming it.',
			'args'    => [ 'source' => '`requests`, `timings` or `owners`.', 'group' => 'Fields to group by, in column order.', 'filter' => 'Each `field=value` holds one value, spelled as the answer spells it (`country=Unknown`, `status_class=5`, which `5xx` also reads as, `url=<hash>`, `bucket=<key>`, a `ua` holding commas whole); several naming one field match any of them, and `field!=value` keeps the rows matching none of its values (`country!=US`, `status_class!=2`; an `Unknown` value counts as a value). A field matches in any case, and a repeated token counts once.', 'measure' => 'Measures to answer, in column order; at least one.', 'bucket' => 'A selection of five-minute buckets, each `Y-m-d-H-i` in UTC, written as comma-separated runs, each one bucket or `start..end`, the buckets from `start` up to the key that closes it, `end` not among them: `2026-10-05-16-55..2026-10-05-17-15,2026-10-05-18-30`. At most as many buckets as the source keeps, {kept} for any source, none after the current one, so a run may close at the end of the current bucket. Omitted, the last {window}.', 'search' => 'Whole words of a URL\'s path, every one of them, each word matched as written: no operator or wildcard acts as one.', 'sort' => 'A column of the answer to sort by; the groups break ties. Refused with `top`, whose rows come in rank order.', 'order' => '`asc` or `desc`.', 'limit' => 'Rows to return, at most 1000; refused with `top`.', 'offset' => 'Rows to skip, at most 10000; refused with `top`.', 'top' => 'Tuples of the non-time group fields to answer, at most 20, ranked by `top_by`; the group needs a time field (`bucket`, `hour` or `day`) and a non-time field.', 'top_by' => 'The measure `top` ranks by, summed over the selection, highest first; ties go to the lower group values. Only a measure that adds across buckets ranks: for `requests` one of `count`, `timed`, `sum_ms`, `sum_peak_mb`, `errors`, `count_2xx`, `count_3xx`, `count_4xx` and `count_5xx`, for `timings` and `owners` one of `ms`, `calls` and `requests`.' ],
		],
		'search_requests'          => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'search_requests',
			'summary' => 'Locate a request by id; returns {rid, url_hash} from its request row. A `Request not found` error means no row names that rid: the request is older than the rows keep, or finished in the last few seconds and is not written yet.',
			'args'    => [ 'rid' => 'The request id.' ],
		],
		'dump_request'             => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'dump_request',
			'summary' => 'One request in full, with its flame data and computed findings, read where its request row says it sits. `owners` lists each owner\'s self time (`ms`), `share` of the request and `calls`; `(unowned)` is time no owned frame encloses, and the time no span covers is listed under no owner, so the shares add up to the part measured. `flame_cut` true means the flame lookup ran out of time before it found `flame_data`, so its absence says nothing of whether the request was profiled; ask again. A `Request not found` error means either no row of the last {kept} buckets names that rid (the request is older, or finished in the last few seconds and is not written yet), or the row exists but its record has aged out of the raw logs, which keep it at least `min_lifetime` unless `max_segments` prunes it sooner; such a request still counts in every aggregate.',
			'args'    => [ 'rid' => 'The request id.' ],
		],
		'grep_requests'            => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'grep_requests',
			'summary' => 'Pattern-search recent traffic; returns matching requests, not lines.',
			'args'    => [ 'pattern' => 'Case-insensitive pattern.', 'limit' => 'Max matches.' ],
		],
		'performance_ask'          => [
			'node'    => 'performance',
			'class'   => Performance_CI_Node::class,
			'verb'    => 'ask',
			'summary' => 'The brief for one thing: `overview:site` (the dashboard as scoped), `url:<hash>`, `page:<page hash>` (every shape of a page, a `url:` brief\'s shape), `request:<rid>`, `span:<name>`, `entry:<i>` (an entry\'s `i`, its position in the request) or `category:<name>`. A `url:` or `page:` brief, and a span under either, names the rule the newest request of its busiest URL was stamped with, the URL itself or the page\'s URL with the most requests under the brief\'s filter, else the rule matching its path. A span or an entry also needs its `request:` descriptor as a second argument; a span or a category given a `url:` or `page:` descriptor instead answers from that URL\'s or page\'s aggregate. A `url:` brief\'s worst requests rank by measured duration: a timeout or an abort, whose duration is a wait and not a timing, and a duration of 0, ranks after every measured one with a null `duration_ms`. An `overview:` brief lists the busiest page rows, each naming its `shapes` and `busiest_hash`, the URL hash of its one shape or of its busiest by count. It reads the snapshot where `performance_urls` would, and carries `as_of`, when what answered it was read, as `performance_urls` does. Where no timed request reached a URL or a page, its `max_ms` is null, in a `url:` brief\'s `stats` and its finding\'s `metric` and on an `overview:` brief\'s page rows, as its `avg_ms` is.',
			'args'    => [ 'descriptor' => 'What to ask about.', 'context' => 'The containing descriptor, if any.', 'search' => 'The search an `overview:` brief answers under: whole words from the search index, matched and refused as performance_urls matches and refuses them; ignored by every other descriptor.','include_workers' => 'Worker traffic an `overview:` brief counts; excluded by default, as performance_urls excludes it.', 'filter' => 'Narrows an `overview:` brief as performance_urls narrows it, and a `url:` or `page:` brief as dump_url does. {declared} `status_class=5` for the 5xx responses, most fatals among them, `status_class=Unknown` for the requests that answered no status: timeouts, and CLI or cron runs where workers count. `error=T` and `error=F` together narrow to the timeouts and fatals exactly. Each brief carries the tokens and fetches under them. Ignored, and never refused, by every other descriptor.', 'server' => 'Optional server name; scopes an overview: brief, a url: or page: brief and a category: brief from the leaderboard the way performance_urls scopes its rows. A span or category under a url: or page: answers from that URL\'s or page\'s aggregate on that server, or every server\'s without one.', 'bucket' => 'A selection of five-minute buckets, as performance_urls takes it: comma-separated runs of `Y-m-d-H-i` UTC keys, each one bucket or `start..end`, the buckets from `start` up to the key that closes it, `end` not among them (`2026-10-05-16-55..2026-10-05-17-15,2026-10-05-18-30`), at most {window} buckets. Narrows an `overview:` brief as performance_urls narrows it, and a `url:` or `page:` brief as dump_url does, to the selected buckets\' numbers and the requests completing inside them; each brief carries the canonical spelling. Ignored, and never refused, by every other descriptor.' ],
		],
		'dump_rules'               => [
			'node'    => 'rules',
			'class'   => Rules_CI_Node::class,
			'verb'    => 'dump',
			'summary' => 'The per-URL logging ruleset. The finest grain a rule has is a URL pattern.',
			'args'    => [],
		],
		'rules_upsert'             => [
			'node'    => 'rules',
			'class'   => Rules_CI_Node::class,
			'verb'    => 'upsert',
			'summary' => 'Create or replace one rule. Enabling hooks costs overhead on every request the rule matches, so narrow it again once the question is answered.',
			'args'    => [ 'rule' => 'The rule as JSON.' ],
		],
		'rules_delete'             => [
			'node'    => 'rules',
			'class'   => Rules_CI_Node::class,
			'verb'    => 'delete',
			'summary' => 'Delete one rule by id.',
			'args'    => [ 'id' => 'The rule id.' ],
		],
	];

	/** @api Read by the substrate's Mcp_Door. */
	protected function rest_namespace(): string {
		return 'newspack-event-logger-nodes/v1';
	}

	/** @api Read by the substrate's Mcp_Door. */
	protected function tools(): array {
		return self::TOOLS;
	}

	/** @api Read by the substrate's Mcp_Door. */
	protected function instructions(): string {
		return self::INSTRUCTIONS;
	}

	/** @api Read by the substrate's Mcp_Door. */
	protected function caveat(): string {
		return Findings::caveat();
	}

	/** @api Read by the substrate's Mcp_Door; the span `min_lifetime` sets. */
	protected function fills(): array {
		$words = Bucket::span_words();
		return [
			'{window}' => $words['window'],
			'{kept}'   => $words['kept'],
		];
	}

	/** @api Read by the substrate's Mcp_Door. */
	protected function server_info(): array {
		return [
			'name'    => 'newspack-event-logger-nodes',
			'version' => \NEWSPACK_EVENT_LOGGER_NODES_VERSION,
		];
	}
}
