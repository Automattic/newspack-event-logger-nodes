# The Performance dashboard, for TAMs

This guide takes a slow-site report to a named URL and a named cause. It assumes you have never read a flame graph. The reference behind it is [Dashboards](dashboards.md).

## Where it is

wp-admin → **Event Logger** → **Performance**. The menu needs the logger's **manage** capability, and an `allowed_users` list, where a site sets one, admits only the logins it names. If the menu is missing, ask the site's operator to add you. The logging rules live under **Settings** → **Event Logger**, in the **Logging Rules** section.

On a **hub**, the dashboard collects many publishers' sites. Each site is one **server**, named by its host.

The address bar carries what you are looking at: the server, the chart's metric, breakdown, Then by and filter chips, and the table's search, sort, **Include Workers** switch, time, page number and the pages opened to their URLs. An open URL's window rides along too, with its own filter chips, **Time**, chart and **Recent Requests** sort. Copy the link, and a colleague opens the same view.

## From a report to a cause

### 1. Scope to the site

If the report names one publisher and you are on a hub, pick that site's host in the **Server** selector, the first control above the chart; **All Servers** clears it. Every number below it then describes that site alone: the headline stats, the table of pages, the charts and the **Time Breakdown**. The selector appears once two or more servers have reported.

If the report carries a request ID, paste it into **Request ID or /url pattern…** at the top of the page and press Enter: the request opens directly. Anything else typed there searches recent traffic and lists what it finds under "Matches in recent traffic".

The charts cover the site's **Min Lifetime**, the setting under **Settings → Nodes Runtime** that also governs how long its log files are kept: 86,400 seconds, a day, unless someone changed it. They step in five-minute buckets, labelled in your own time zone, and the main chart's title names their span in hours, minutes or both. **Metric** picks what the main chart plots: **Request Volume**, **Avg Response Time**, **Cumulative Response Time** or **Avg Peak Memory**. **Breakdown** splits it into series by server, status, method, country, user agent and more, and **Then by** splits each series again. Click a series in the legend to draw it alone. A chart's corner button doubles its height.

### 2. Find the slow URL

The table **Pages by Request Count** lists every page that a logging rule recorded over the charts' span, busiest first, 100 at a time. A page is a URL's host and path in lower case, whatever its query: `https://example.com/Cincinnati/MovieTimes?film=?` and `https://example.com/cincinnati/movietimes` are one page, `example.com/cincinnati/movietimes`, and its row adds up both. So a REST call such as `/?_embed=?&rest_route=/wp/v2/posts` is one of the URLs under the home page, `example.com/`, and each admin-ajax action one of the URLs under `admin-ajax.php`. A page reached one way only is that URL, and clicking it opens it. A URL with a single request in the table's figures opens straight on that request, but only when its window, asked afresh, still counts exactly one; otherwise its URL window opens instead. While it is being asked the row shows a spinner, and Escape cancels the click and takes the URL back out of the address bar. A page reached several ways shows a ▾ caret: click the row to list its URLs beneath it, each with its own numbers in the same columns and in the table's order, and click one to open it. Every URL of a page is listed, more loading as you scroll down to them; past a page's first 11,000 URLs, which is as far as the server lists, a last row says how many more it has. A URL asked in other capitals, or over `http://` rather than `https://`, is the same URL: its row counts every such request and shows one of the spellings it was asked with, an `https://` one where there is one. A server is the same server in any capitals. The address bar remembers which pages you opened, so a reload or a shared link shows them open. If a URL named in the report is missing even when you search for it, with no chips, Time selection or server chosen, then either it had no traffic over that span or no rule logs it: see step 5.

| Column | What it means |
|---|---|
| **Reqs** | Requests over the charts' span, or in the buckets you chose, that the search, the server and the chart's filter chips keep. A page's row counts the requests to all its URLs. |
| **URL** | On the row of a page reached one way, that URL, as its own row shows it. On the row of a page reached several ways, `https://` and the page, its host and path in lower case, even where some of its URLs were asked over `http://`. On a URL's row, the host, the path, and the names of its query with each value shown as `?`: `/?_embed=?&rest_route=/wp/v2/posts` is a REST call, not the homepage. A `rest_route`, `action`, `wc-ajax` or `feed` value is kept, its numbers shown as `?`, and campaign tags such as `utm_source`, `gclid` or `fbclid` are left out. The shaded bar compares the row with the pages listed, on whatever the chart's **Metric** measures. |
| **2xx 3xx 4xx 5xx** | The share of the row's requests, a page's or a URL's, in each status class, as a percent. A request that never returned a status code is not counted in any of the four, so the percentages need not add up to 100. |
| **Avg**, **Min**, **Max** | Response time in milliseconds: mean, fastest, slowest. A dash means none of the row's requests was timed. |
| **Mem** | Mean peak memory, in MB. |
| **Last seen** | How long ago the newest request finished. |

Avg, Min and Max count only the requests that were timed. A request that timed out or was aborted has no duration, so a URL whose slowest requests time out can show a low **Max**; look for it under **Total Errors** or a **Status Class = Unknown** chip instead.

To find the culprit:

- **Sort by Avg** for a page that is slow every time. **Sort by Max** for one that is slow now and then.
- **A URL the report names:** type a word from it into **Search by whole URL word…**, or press `/` to jump there. Each word you type must be a whole word of the URL, and every word must match: `sports` finds `/blog/sports-news` and `sport` does not. The host is not searched.
- **A time the report names:** convert it to your own time zone, then click that moment on any chart; cmd-click (ctrl-click) adds or removes another five-minute bucket, and dragging across a chart takes a range. Or type it in your local time into the **Time** field beside the search box, as `16:55`, `4:55 PM` where your clock is 12-hour, or a range such as `16:55-17:30`, which stops at 17:30 rather than taking in the five minutes after it, or pick a bucket from its list. A time off the five-minute grid, such as `16:57`, is refused. The table narrows to the buckets you chose, shaded on the charts and shown in the field as local spans such as `6:35–6:40 AM`, each with its own × to remove it.
- **Errors:** add a filter chip in the **Filter** box above the chart. Type `status_class=5xx` for **Status Class = 5xx**, the 5xx responses, most fatal errors among them, or `status_class=unknown` for **Status Class = Unknown**, the requests that never returned a status code, as a timed-out request does. `error=F` keeps the fatals and `error=T` the timeouts exactly; a fatal error that still returned 200 is counted as 2xx, not as 5xx or Unknown. Type `!=` to leave something out instead: `status_class!=2xx` keeps every request but the 2xx responses, shown as **Status Class ≠ 2xx**, and `country!=US` every request from outside the US, unknown countries included. A chip on the server is refused: the **Server** selector chooses the site.
- The chips narrow the chart, the table and the headline stats above it, so every count then describes those requests alone. They leave the three category charts and the **Time Breakdown** describing every request. **Total Errors** in the headline stats counts the timeouts and fatals among the requests shown.
- The site's own background traffic is hidden until you press **Include Workers**: WP-Cron, the logger's worker processes and their jobs, and the platform's own REST calls. The button changes the table and the headline stats; the charts leave that traffic out unless you add a `worker` chip.

Every count and average in the table is exact. **Avg** is the mean over the row's timed requests, and **Mem** over those whose peak memory was sampled. The URL's own window counts its worker traffic, which the table hides until **Include Workers**.

### 3. Open the URL

Click the row. The header gives its request rate, errors, average time and memory; **errors** counts timeouts and fatals only. Below, **Recent Requests** lists up to 500 of its requests, newest first:

- **Sort by Duration** to put the slowest first, or click a dot in **Response Times (Recent Requests)**. Click a status class in that chart's legend to keep only its dots.
- **Status** shows the HTTP code, or a letter when the request never finished normally. Hover the letter for its full label.

| Letter | Meaning |
|---|---|
| **F** | Fatal error: PHP crashed. |
| **T** | Timed out: the request's end never arrived. |
| **A** | Aborted: the worker stopped mid-request. |
| **I** | Incomplete: part of its log is missing. |

A **T** or **A** request has no measured duration. In the list its Duration reads `—`, it has no dot on the chart, and it sorts last either way.

Above the list, the **Aggregate Flame Graph** draws where the URL's newest 100 requests spent their time, the **Time Breakdown** averages each category's share of a request, and three category charts show the time and events by category over the day. The breakdown and the charts count each request's 100 most expensive categories. On a URL whose rule times thousands of hooks, the rest are a long tail, about 5% of the time on the URL where this was measured, so the breakdown's total reads that much below the sum of its requests. A cheap hook that ranks in only some requests' top 100 also shows fewer calls per request than it made. Each request's own **Time Breakdown** still lists every category.

A URL opened from the table starts with the table's filter chips and its **Time** selection. Its chart's chips narrow the header and the list as well as that chart, so **Status Class = 5xx** lists the URL's newest 500 5xx responses, past the clean requests that would bury them. Add or remove a chip here and the page's own chips stay as they were. A note above the chart says what is narrowed. The category charts and the **Time Breakdown** still describe every request to the URL, though the breakdown's **% of Total** divides by the narrowed average. No flame graph is drawn while a chip or a **Time** selection narrows the list.

This window has its own **Time** field, beside the list's heading. A chart click or a typed time narrows the header and the list to those five-minute buckets, as it does the table.

### 4. Name the cause

Open a slow request by clicking its row. Its summary gives the URL, time, duration, memory and status; for a **T** or **A** request the duration is how long it ran before it was given up, not a measured time. Under the summary, **Findings** lists what is wrong with it, worst first, each with the number that shows it and, where a logging change would help, the change to make. The request's log ends with the spans it left open, then the rows its logging rule asks for: **request_context** under **Log request context**, **objectcache** under **Log object cache counts**, and `resources` under **Log resource usage**, before `process (complete)`, which always carries the request's peak memory. A web request's **request_context** row names the template file WordPress included, the rewrite rule and query the URL matched, the conditionals the main query answered true (`is_single`, `is_archive`, …) and the main query's variables, any value read as `?` unless it is a structural one such as `post_type` or `paged`; one marked `trimmed` outgrew one log line and lost its last query variables first. The **objectcache** row counts what the request asked of the object cache. Under WordPress's own object cache it reads `hit` and `miss`. Under the memcached object cache WP Cloud runs it reads `local`, the lookups answered from the request's own copy (which include lookups in groups that never reach memcached), `get`, the memcached gets, `hit` and `miss`, how many of those found the key, and `multi`, the keys fetched in batches, whose hits it cannot tell apart. Any other object cache writes no row.

| Finding | What it tells you |
|---|---|
| "The request died in the … plugin" | A fatal error, with the plugin, the file and the line. That is the cause. "The request died outside any plugin" is the same finding when no plugin can be named. |
| "The request stopped logging … in, after …" | The log ends before the request did: it timed out, or its worker stopped it. The finding names the last entry and what was still open there. "…and where it stopped is unknown" means nothing past the request's first lines was logged. |
| "… holds N% of the request" | One hook, query, HTTP call or event took 60% or more of the request's time. The detail follows that time down, "Inside it, … holds N% of the request", to the work that explains it. Name the deepest. |
| "… fired N times in one request, holding …" | One caller ran the same hook, query, HTTP call or event 50 or more times for at least 5% of the request, or twice or more for a quarter of it. Often one database query per item on the page. |
| "Loading N plugins took …" | Starting the plugins took a quarter of the request or more. No logging change will fix it. |
| "… passed between … and … with nothing logged" | A silent gap of a quarter second or more. Some code ran during the gap, and no rule times it; where the record allows, the finding gives the least time the process spent on CPU and the least it spent waiting. |
| "The governing rule registers no hooks, so nothing inside the request is measured" | The logger records only that the request happened. See step 5. |
| "None of the rule's hooks ran in this request" | The rule times hooks, but the request ended before any of them fired. |
| "Rule … governed this request, and this ruleset does not hold it" | The rule that logged it has since changed or been deleted, or came from a ruleset this hub never pushed. Its findings carry no proposals. Ask the operator. |
| "This record was folded under memory pressure" | Parts of the log were merged or lost, so a missing entry proves nothing. |

A request under 50 ms, and one that timed out or was aborted, never gets a "holds", "fired" or "Loading" finding.

"Nothing stands out in the numbers here." means no finding fired. Try a slower request, or one listed under **Status Class = 5xx** or **Status Class = Unknown**.

Below the findings, **Request Trace** draws the request's own flame graph, with its peak memory beneath when the rule ticks **Log peak memory on every span**, the **Time Breakdown** gives its time by category, and **Log Entries** lists every line it logged, with a search box. A database query's row shows how many rows it returned or changed, or in red the error it failed with: its MySQL error number and its message up to the first quote, where MySQL starts quoting the values it failed on; an outbound HTTP call's row shows its method and, in red, the error a failed call returned. Code instrumented for Query Monitor appears too, on any logged request: a timer is a `qm` row named after it, its laps and the timers stopped inside it nested within; one that started before the hook it ended in is drawn from that hook's start, and its row says how long it really ran, and a log line is a `qm (error)`, `qm (warning)` or other level row, the warning and more severe levels also reaching the Error Log. "Timer not started" and "Timer not stopped" are Query Monitor's own complaints about code that stopped a timer it never started, or never stopped one. The **←** button returns to the URL's window.

If you hold the logger's read role, every logged page you load while signed in carries a `Server-Timing` header: the browser's network panel, under **Timing**, shows the time so far, the five most expensive categories and the request id, which the `search_requests` tool or `wp nodes reqgrep <id>` finds. A page's numbers stop where its headers were sent, before the page itself rendered.

### 5. When nothing is measured

A logger sees only what the URL's **rule** tells it to time. If the findings say nothing is measured, the URL needs a rule that times more. In the URL's window press **Log this URL**, or **Edit logging rule** if a rule for exactly this path, ending in `?`, already exists. A URL covered only by a broader rule, such as `/blog`, shows **Log this URL**, which opens **Add rule** on a blank rule for this URL alone. That rule outranks `/blog` for this URL, so add back any hooks that `/blog` was timing and that you still want.

- Leave **URL pattern** as filled in: it is the URL's path, and the trailing `?` makes the rule cover that path alone, whatever query a request adds to it.
- Each proposal names its change. For hooks, press **Select Hooks**, search in **Select Hooks to Log**, tick the hooks named after `add_hooks` or `create_rule`, and press **Apply**. For a request in which nothing is timed, the proposal is seven hooks that split it into phases: `plugins_loaded`, `setup_theme`, `init`, `wp_loaded`, `template_redirect`, `wp_head` and `shutdown`. An `add_hooks` proposal is addressed to the rule that governed the request, which may be the broader one; adding the hooks here puts them on this URL's own rule instead.
- `mark_significant` names a hook to add under **Significant events**: type it and press Enter. The logger then times each of the hook's listeners separately, which is the only way to see which one holds the time, and never retires the hook as noise.
- Tick **Log HTTP requests** when you suspect a slow outside service, and **Log database queries** when you suspect the database; a `log_transport` proposal names `log_http` or `log_queries`. Query logging adds two lines to the request's log for every query, so a request that runs hundreds of queries leaves a large record, which may be folded under memory pressure. Turn it off once you have your answer.
- Tick **Log resource usage** when a finding reports a gap and you want to know whether the process was working through it: the gap then carries how much CPU it could have spent. **Log request context** names the template and query WordPress settled on, **Log object cache counts** the request's cache hits and misses, and **Log peak memory on every span** shows where memory grew. Each adds lines to every request the rule logs, so turn it off once you have your answer.
- `trim_hooks`, proposed on a folded record, asks for fewer hooks on the rule, so the next request of its kind is logged whole.
- Press **Save rule**. It applies at once, but only to requests that arrive after it, so wait for new ones before you look again.

To log a URL that is missing from the table, go to **Settings** → **Event Logger** → **Logging Rules** and press **+ Add Rule**. It opens the same editor with an empty **URL pattern**: type the URL's path followed by `?`, such as `/blog/sports-news?`. Because no request to the URL has been logged yet, no finding proposes hooks for it, so start with the seven phase hooks above.

Saving a rule needs the **tune** capability. Without it the save fails with "permission denied: tune capability required" and the draft is lost, so send the operator the finding and its proposal instead.

## Sending the brief to an assistant

The Ask panel builds a **brief**: a Markdown summary of whatever you clicked, with its numbers, its rule and its findings. Press **Ask AI**, then click what you want to ask about: the row of a page reached one way, a URL listed under a page, the open URL's window, a request, a flame-graph bar, a **Time Breakdown** row, a log line, or the page's background for the whole site. Cmd-click (Ctrl-click) adds more things to one brief, or removes one already added, and Escape cancels. A URL or site brief picked under a filter chip or a **Time** selection says so. A site brief lists the six busiest pages, each with the hash of its busiest URL, so an assistant can ask about that URL next.

- **The brief that leaves this page** opens to show exactly what will be sent.
- **Copy brief** puts it on your clipboard, to paste into any assistant or ticket.
- **Ask Claude** copies the brief and opens a new claude.ai conversation. A brief of up to about 6,000 characters rides in the link itself; a longer one does not fit, so the conversation asks you to paste it from the clipboard.

A brief carries URLs and request details, so treat it like any other customer data. When Ask Claude puts the brief in the link, your browser history and any proxy that logs URLs keep it; Copy brief does not.

Each brief carries the same caution, and it matters when you read an assistant's answer: the logger times only what the rule names, so **"Unlogged time is unmeasured, not idle."** An assistant that blames the part of a request that nobody timed is guessing.

## Connecting Claude to the site

A brief is one snapshot. Connected to the site's MCP server instead, Claude can ask the dashboard's own questions itself, follow a slow URL to its requests and their findings, and come back later without being fed anything.

### 1. Issue a session

A session is a credential Claude uses in the name of whoever issued it, limited to the scope and the lifetime they chose.

1. wp-admin → **Nodes** → the **Sessions** tab → **+ Issue Session**.
2. **Label:** who it is for and why, such as `Jane — Claude, slow homepage`. The label is how you find it again to revoke it.
3. **Scope:** **read** for everything in this guide. Choose **tune** only if Claude should also change logging rules itself. Never choose **manage** for Claude: it adds no tools here, and the same key also reaches the site's fleet controls. A scope above your own is lowered to yours, which the key panel shows.
4. **Lifetime (seconds):** from 60 to 86,400, one day; it starts at 3,600. The session then stops working, and you issue another.
5. Press **Issue Session** and copy the key it shows, two long codes joined by a dot. It is shown once and cannot be recovered.

With WP-CLI on the server, one command issues the session and registers it. `wp nodes session issue <label> [<role>] [<ttl>] --user=<login>` prints only the key, so the shell can hand it straight to `claude mcp add`. Name the role: it defaults to **manage**.

```sh
claude mcp add --transport http slow-site https://<site>/wp-json/newspack-event-logger-nodes/v1/mcp \
  --header "Authorization: Bearer $(wp nodes session issue <label> read 86400 --user=<login>)"
```

Issuing a session in wp-admin needs the **manage** capability; WP-CLI needs only the role being issued. Without either, ask the site's operator to issue one for you and to send the key privately; Claude then acts in the operator's name, within the scope they chose. The key is a password: never paste it into a ticket, a chat or a brief.

### 2. Connect Claude Code

The server lives at `https://<site>/wp-json/newspack-event-logger-nodes/v1/mcp`, and it takes the key as a bearer token. In a terminal, with the site's address and your key filled in:

```sh
claude mcp add --transport http slow-site \
  https://example.com/wp-json/newspack-event-logger-nodes/v1/mcp \
  --header "Authorization: Bearer <the key>"
```

`slow-site` is only the name Claude Code shows for this connection. Start a new session and ask, for example: *"Which URL on this site is slowest, and what is the cause?"*

The connection needs a custom `Authorization` header. Claude Code takes one; claude.ai's custom connectors authenticate through OAuth and have no field for it. From claude.ai, use **Ask Claude** on the dashboard instead.

### What Claude can do with it

| Scope | Tools |
|---|---|
| read | `performance_overview` and `performance_urls` (the dashboard's headline figures and its table of pages), `dump_url` and `dump_request` (one URL, one request with its findings), `search_requests` and `grep_requests` (find a request by id, or by text in recent traffic), `performance_query` (any count, average or top list that the request and timing rows can answer, broken down and filtered by their fields), `performance_ask` (the same briefs as the panel), `dump_rules` (the logging rules) |
| tune | the above, plus `rules_upsert` and `rules_delete` (change or remove a logging rule) |

`performance_urls`, `dump_url`, `performance_query` and `performance_ask` also take five-minute buckets in UTC, so Claude can look at the moment a publisher reported. A range is written as its start and the time it stops: `2026-10-20-11-00..2026-10-20-19-00` is 11:00 to 19:00 UTC, its last bucket the one opening at 18:55, and the address bar's `?bucket=` is written the same way. A session sees only its scope's tools, and it sees no tools at all if the site's `allowed_users` list leaves out the login that issued it. It makes at most 20 calls per 10 seconds, counting every call; past that the server answers "Too many MCP calls; please slow down." Every tool result reaches Claude marked as site data, not instructions, because visitors wrote parts of it: a URL or a user agent can say anything.

When you are done, revoke the session with its **Revoke** button under **Sessions** rather than waiting for it to expire. `wp nodes session list` and `wp nodes session revoke <handle>` do the same from WP-CLI.

## What it cannot tell you

- **Anything that the rule does not time.** Step 5 is how you widen it.
- **Anything below PHP**: the web server, the edge cache, the network before WordPress starts. A request that is fast here but slow for the reader is slow somewhere this dashboard cannot see.
- **Anything older than the charts' span plus an hour.** The stats keep that long and no longer. The charts and the table show the span unless you choose older buckets, and one selection holds no more buckets than the span.
- **One request in full after about a day.** Its log lines and flame graph are kept only as long as the site's log files, a day by default and less on a busy site. An older request still counts in the table, but opening it answers "Request not found".
