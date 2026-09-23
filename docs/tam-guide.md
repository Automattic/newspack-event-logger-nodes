# The Performance dashboard, for TAMs

This guide takes a slow-site report to a named URL and a named cause. It assumes no flame graphs. The reference behind it is [Dashboards](dashboards.md).

## Where it is

wp-admin → **Event Logger** → **Performance**. The menu needs the logger's **manage** capability, and an `allowed_users` list, where a site sets one, admits only the logins it names. If the menu is missing, ask the site's operator to add you.

On a **hub**, the dashboard collects many publishers' sites. Each site is one **server**, named by its host.

## From a report to a cause

### 1. Scope to the site

If the report names one publisher and you are on a hub, pick that site's host in the **Server** selector above the chart. Every number below it then describes that site alone: the headline stats, the URL table, the charts and the "Time Breakdown". The selector appears only once two or more servers have reported.

### 2. Find the slow URL

The table **URLs by Request Count** lists every URL the site served in the retention window, 24 hours unless the site sets another, busiest first.

| Column | What it means |
|---|---|
| **Reqs** | Requests in the window. |
| **URL** | The path. The shaded bar compares the row with the rest of the page. |
| **2xx 3xx 4xx 5xx** | The share of the URL's requests in each status class, as a percent. |
| **Avg**, **Min**, **Max** | Response time in milliseconds: mean, fastest, slowest. |
| **Mem** | Mean peak memory, in MB. |
| **Last seen** | How long ago the newest request finished. |

To find the culprit:

- **Sort by Avg** for a URL that is slow every time. **Sort by Max** for one that is slow now and then.
- **A URL the report names:** type a word from its path into **Search by URL word or prefix…**. `sports` finds `/blog/sports-news`; each word you type must begin a word of the path.
- **Errors Only** keeps the URLs where requests timed out or died. The first column then counts those errors. A 5xx is a response, not an error here.
- Cron, WP-CLI and job traffic is hidden until you press **Include Workers**.
- The row **traffic from URLs beyond the per-shard cap** is many quiet URLs folded together, not one URL. It cannot be opened.

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

**Errors Only** here keeps just those four letters. If the note "The request index scan stopped early" appears, the list may be missing some of the URL's requests.

### 4. Name the cause

Open a slow request by clicking its row, then press **Ask AI** and click the request's summary. The panel that opens lists the **findings**: what is wrong with this request, worst first, each with the number that shows it. The request page itself does not show them.

| Finding | What it tells you |
|---|---|
| "The request died in the … plugin" | A fatal error, with the plugin, the file and the line. That is the cause. |
| "… holds N% of the profiled time" | One hook, query, HTTP call or event took most of the measured time. Name it. |
| "… fired N times in one request" | One thing ran 50 or more times. Often one database query per item on the page. |
| "Loading N plugins took …" | Starting the plugins took a quarter of the request or more. No logging change will fix it. |
| "… passed between … and … with nothing logged" | A silent gap of a quarter second or more. Something ran that nothing is timing. |
| "… of … went unmeasured" | Most of the request ran outside anything timed. The cause is not visible yet: see step 5. |
| "No rule governs this URL…" and other "nothing is measured" findings | The logger records only that the request happened. See step 5. |
| "Rule … governed this request, and this ruleset does not hold it" | The rule that logged it has since changed or come from another site. Ask the operator. |
| "This record was folded under memory pressure" | Parts of the log were merged, so a missing entry proves nothing. |

"Nothing stands out in the numbers here" means no finding fired. Try a slower request, or a request from **Errors Only**.

### 5. When nothing is measured

A logger sees only what the URL's **rule** tells it to time. If the findings say nothing is measured, or most time is unmeasured, the URL needs a rule that times more. In the URL's window press **Log this URL**, or **Edit logging rule** if one exists:

- Leave **URL pattern** as filled in: the trailing `?` makes the rule cover this one URL only.
- Under **Hooks**, pick the hooks the finding proposes. The panel names only the kind of change, `add_hooks`; the brief lists the hooks themselves. For a request nothing times, the proposal is six that split it into phases: `plugins_loaded`, `init`, `wp_loaded`, `template_redirect`, `wp_head` and `shutdown`.
- Tick **Log HTTP requests** when you suspect a slow outside service, and **Log database queries** when you suspect the database. Query logging makes a query-heavy request much slower, so turn it off once you have your answer.
- Press **Save rule**. It applies at once, but only to requests that arrive after it, so wait for new ones before you look again.

Saving a rule needs the **tune** capability. Without it, send the operator the finding and its proposal.

## Sending the brief to an assistant

The Ask panel also builds a **brief**: a plain-text summary of whatever you clicked, with its numbers, its rule and its findings. Press **Ask AI**, then click what you want to ask about — a URL row, a request, a flame-graph bar, a category, a log line, or the page's background for the whole site. Cmd-click (Ctrl-click) adds more things to one brief, and Escape cancels.

- **Copy brief** puts it on your clipboard, to paste into any assistant or ticket.
- **Ask Claude** opens a new claude.ai conversation carrying the brief, and copies it too.

A brief carries URLs and request details, so treat it like any other customer data. Ask Claude puts the brief in the link itself, where your browser history keeps it; Copy brief does not.

Every brief ends with the same caution, and it matters when you read an assistant's answer: the logger times only what the rule names, so **time it did not measure is unknown, not idle.** An assistant that blames the part of a request nobody timed is guessing.

## Connecting Claude to the site

A brief is one snapshot. Connected to the site's MCP server instead, Claude can ask the dashboard's own questions itself, follow a slow URL to its requests and their findings, and come back later without being fed anything.

### 1. Issue a session

A session is a credential Claude uses in the name of whoever issued it, limited to the scope and the lifetime they chose.

1. wp-admin → **Nodes** → the **Sessions** tab → **+ Issue Session**.
2. **Label:** who it is for and why, such as `Jane — Claude, slow homepage`. The label is how you find it again to revoke it.
3. **Scope:** **read** for everything in this guide. Choose **tune** only if Claude should also change logging rules itself.
4. **Lifetime (seconds):** at most 86,400, one day. The session then stops working, and you issue another.
5. Press **Issue Session** and copy the key it shows, two long codes joined by a dot. It is shown once and cannot be recovered.

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

A session sees only its scope's tools, and makes at most 20 calls per 10 seconds. Every answer reaches Claude marked as site data, not instructions, because visitors wrote parts of it: a URL or a user agent can say anything.

When you are done, revoke the session under **Sessions** rather than waiting for it to expire.

## What it cannot tell you

- **Anything the rule does not time.** Step 5 is how you widen it.
- **Anything below PHP**: the web server, the edge cache, the network before WordPress starts. A request that is fast here but slow for the reader is slow somewhere this dashboard cannot see.
- **Anything older than the retention window**, 24 hours by default.
