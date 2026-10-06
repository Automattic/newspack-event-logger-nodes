# The Performance dashboard, for TAMs

This guide takes a slow-site report to a named URL and a named cause. It assumes no flame graphs. The reference behind it is [Dashboards](dashboards.md).

## Where it is

wp-admin → **Event Logger** → **Performance**. The menu needs the logger's **manage** capability, and an `allowed_users` list, where a site sets one, admits only the logins it names. If the menu is missing, ask the site's operator to add you.

On a **hub**, the dashboard collects many publishers' sites. Each site is one **server**, named by its host.

The address bar carries what you are looking at: the server, the chart's metric and breakdown, the table's search, sort, filters, time and page. Copy the link, and a colleague opens the same view.

## From a report to a cause

### 1. Scope to the site

If the report names one publisher and you are on a hub, pick that site's host in the **Server** selector above the chart. Every number below it then describes that site alone: the headline stats, the URL table, the charts and the "Time Breakdown". The selector appears only once two or more servers have reported.

The charts always cover the last 24 hours, in five-minute steps, labelled in your own time zone. A chart's corner button, or a shift+click on it, doubles its height.

### 2. Find the slow URL

The table **URLs by Request Count** lists every URL the site served in the retention window, 12 hours unless the site sets another, busiest first, 100 to a page.

| Column | What it means |
|---|---|
| **Reqs** | Requests in the window. Under **Errors Only** the column reads **Errors** and counts those instead. |
| **URL** | The path. The shaded bar compares the row with the rest of the page. |
| **2xx 3xx 4xx 5xx** | The share of the URL's requests in each status class, as a percent. |
| **Avg**, **Min**, **Max** | Response time in milliseconds: mean, fastest, slowest. A dash means no request to the URL was timed. |
| **Mem** | Mean peak memory, in MB. |
| **Last seen** | How long ago the newest request finished. |

To find the culprit:

- **Sort by Avg** for a URL that is slow every time. **Sort by Max** for one that is slow now and then.
- **A URL the report names:** type a word from its path into **Search by whole URL word…**. Each word you type must be a whole word of the path, two characters or more: `sports` finds `/blog/sports-news` and `sport` does not. A word in nearly every URL narrows nothing, and a search made only of such words is refused; add a rarer word.
- **A time the report names:** convert it to your own time zone, then click that moment on any chart; cmd-click (ctrl-click) adds or removes another five-minute bucket. Or type it in your local time into the **Time** field beside **Errors Only**, as `16:55`, `4:55 PM` where your clock is 12-hour, or a range such as `16:55-17:30`. The table narrows to the buckets you chose, shaded on the charts and shown in the field as local spans such as `6:35–6:40 AM`, each with its own × to remove it.
- **Errors Only** keeps the URLs that had a timeout or a fatal error, and counts only their traffic in the five-minute buckets where they had one. A 5xx is a response, not an error here, and neither is an aborted request.
- Cron, WP-CLI and job traffic is hidden until you press **Include Workers**.
- The row **traffic from URLs beyond the per-shard cap** is many quiet URLs folded together, not one URL. It cannot be opened.

Under the table, the note **"Ranked per bucket; Avg and Mem are means of bucket averages"** means the page was built from each five-minute bucket's own ranking. A page hit rarely but slowly then ranks by how slow it is, not by how rarely. Sorts by URL, Max and Last seen are exact; the others can differ slightly from the URL's own figures, so open the URL before you quote a number. The table runs up to about two minutes behind live traffic.

### 3. Open the URL

Click the row. The header gives its request rate, average time and memory. Below, **Recent Requests** lists up to 500 of its requests, newest first:

- **Sort by Duration** to put the slowest first, or click a dot in **Response Times (Recent Requests)**.
- **Status** shows the HTTP code, or a letter when the request never finished normally:

| Letter | Meaning |
|---|---|
| **F** | Fatal error: PHP crashed. |
| **T** | Timed out: the request's end never arrived. |
| **A** | Aborted: the worker stopped mid-request. |
| **I** | Incomplete: part of its log is missing. |

A **T** or **A** request has no measured duration. Its Duration reads `—`, it has no dot on the chart, and it sorts last either way.

**Errors Only** here keeps the timeouts (**T**) and fatals (**F**), reaching back past the newest 500 requests to find older ones. The header then counts errors, timeouts and fatals, and the fatals' average and slowest time. The charts, the flame graph and the profile still describe every request to the URL, and a note above the list says so.

This window has its own **Time** field, between the list's heading and **Errors Only**. A chart click or a typed time narrows the header and the list to those five-minute buckets, as it does the table.

If the note "The request index scan stopped early, so requests for this URL may be missing." appears, the search ran out of time before it reached the start of the window, so an empty or short list does not mean the URL was idle.

### 4. Name the cause

Open a slow request by clicking its row. Under its summary, **Findings** lists what is wrong with it, worst first, each with the number that shows it and, where a logging change would help, the change to make.

| Finding | What it tells you |
|---|---|
| "The request died in the … plugin" | A fatal error, with the plugin, the file and the line. That is the cause. |
| "The request stopped logging … in, after …" | The log ends before the request did: it timed out, or its worker stopped it. The finding names the last entry and what was still open there. |
| "… holds N% of the request" | One hook, query, HTTP call or event took 60% or more of the request's time. The detail follows that time down, "Inside it, … holds N% of the request", to the work that explains it. Name the deepest. |
| "… fired N times in one request, holding …" | One caller ran the same hook, query, HTTP call or event 50 or more times for at least 5% of the request, or a few times for a quarter of it. Often one database query per item on the page. |
| "Loading N plugins took …" | Starting the plugins took a quarter of the request or more. No logging change will fix it. |
| "… passed between … and … with nothing logged" | A silent gap of a quarter second or more. Something ran that nothing is timing; where the record allows, the finding says how much of it the process spent on CPU and how much waiting. |
| "No rule governs this URL…" and other "nothing is measured" findings | The logger records only that the request happened. See step 5. |
| "Rule … governed this request, and this ruleset does not hold it" | The rule that logged it has since changed or been deleted, or came from a ruleset this hub never pushed. Its findings carry no proposals. Ask the operator. |
| "This record was folded under memory pressure" | Parts of the log were merged, so a missing entry proves nothing. |

A request under 50 ms, and one that timed out or was aborted, gets no "holds", "fired" or "Loading" finding.

"Nothing stands out in the numbers here" means no finding fired. Try a slower request, or a request from **Errors Only**.

### 5. When nothing is measured

A logger sees only what the URL's **rule** tells it to time. If the findings say nothing is measured, the URL needs a rule that times more. In the URL's window press **Log this URL**, or **Edit logging rule** if this URL has its own rule. A URL covered only by a broader rule, such as `/blog`, shows **Log this URL**, which adds a rule for it alone.

- Leave **URL pattern** as filled in: the trailing `?` makes the rule cover this one URL only.
- Each proposal names its change. Under **Hooks**, pick the hooks named after `add_hooks` or, for a URL no rule governs yet, `create_rule`. For a request nothing times, it proposes six that split it into phases: `setup_theme`, `init`, `wp_loaded`, `template_redirect`, `wp_head` and `shutdown`. `mark_significant` names a hook to add under **Significant events**, so the logger never retires it as noise.
- Tick **Log HTTP requests** when you suspect a slow outside service, and **Log database queries** when you suspect the database; a `log_transport` proposal names which. Query logging makes a query-heavy request much slower, so turn it off once you have your answer.
- Press **Save rule**. It applies at once, but only to requests that arrive after it, so wait for new ones before you look again.

Saving a rule needs the **tune** capability. Without it, send the operator the finding and its proposal.

## Sending the brief to an assistant

The Ask panel also builds a **brief**: a plain-text summary of whatever you clicked, with its numbers, its rule and its findings. Press **Ask AI**, then click what you want to ask about: a URL row or the open URL's window, a request, a flame-graph bar, a profile row, a log line, or the page's background for the whole site. Cmd-click (Ctrl-click) adds more things to one brief, and Escape cancels. A brief picked under **Errors Only** or a **Time** selection says so.

- **Copy brief** puts it on your clipboard, to paste into any assistant or ticket.
- **Ask Claude** copies the brief and opens a new claude.ai conversation. A short brief rides in the link itself; a long one does not fit, so the conversation asks you to paste it.

A brief carries URLs and request details, so treat it like any other customer data. When Ask Claude puts the brief in the link, your browser history keeps it; Copy brief does not.

Each brief carries the same caution, and it matters when you read an assistant's answer: the logger times only what the rule names, so **"Unlogged time is unmeasured, not idle."** An assistant that blames the part of a request nobody timed is guessing.

## Connecting Claude to the site

A brief is one snapshot. Connected to the site's MCP server instead, Claude can ask the dashboard's own questions itself, follow a slow URL to its requests and their findings, and come back later without being fed anything.

### 1. Issue a session

A session is a credential Claude uses in the name of whoever issued it, limited to the scope and the lifetime they chose.

1. wp-admin → **Nodes** → the **Sessions** tab → **+ Issue Session**.
2. **Label:** who it is for and why, such as `Jane — Claude, slow homepage`. The label is how you find it again to revoke it.
3. **Scope:** **read** for everything in this guide. Choose **tune** only if Claude should also change logging rules itself.
4. **Lifetime (seconds):** at most 86,400, one day. The session then stops working, and you issue another.
5. Press **Issue Session** and copy the key it shows, two long codes joined by a dot. It is shown once and cannot be recovered.

With WP-CLI on the server, one command issues the session and registers it. `wp nodes session issue <label> [<role>] [<ttl>]` prints only the key, so the shell can hand it straight to `claude mcp add`:

```sh
claude mcp add --transport http slow-site https://<site>/wp-json/newspack-event-logger-nodes/v1/mcp \
  --header "Authorization: Bearer $(wp nodes session issue <label> read 86400 --user=<login>)"
```

Issuing a session needs the **manage** capability. Without it, ask the site's operator to issue one for you and to send the key privately; Claude then acts in the operator's name, within the scope they chose. The key is a password: never paste it into a ticket, a chat or a brief.

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
| read | `performance_overview` and `performance_urls` (the page and its URL table), `dump_url` and `dump_request` (one URL, one request with its findings), `search_requests` and `grep_requests` (find a request by id or by text), `performance_ask` (the same briefs as the panel), `dump_rules` (the logging rules) |
| tune | the above, plus `rules_upsert` and `rules_delete` (change or remove a logging rule) |

`performance_urls`, `dump_url` and `performance_ask` also take five-minute buckets in UTC, so Claude can look at the moment a publisher reported. A session sees only its scope's tools, and makes at most 20 calls per 10 seconds. Every answer reaches Claude marked as site data, not instructions, because visitors wrote parts of it: a URL or a user agent can say anything.

When you are done, revoke the session under **Sessions** rather than waiting for it to expire.

## What it cannot tell you

- **Anything the rule does not time.** Step 5 is how you widen it.
- **Anything below PHP**: the web server, the edge cache, the network before WordPress starts. A request that is fast here but slow for the reader is slow somewhere this dashboard cannot see.
- **Anything older than the retention window**, 12 hours by default, or than 24 hours on the charts.
