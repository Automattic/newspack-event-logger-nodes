# The Event Logger

Newspack Event Logger Nodes is an application on the runtime: it records what a WordPress request does and how long each part takes. The runtime owns the wiring; the plugin owns the data processing.

## One line per event

The writer sits on the critical path of the request it measures. [Log_Manager](../includes/class-log-manager.php) appends one line per event to the firehose, an append-only topic, and forgets it: it reads nothing, aggregates nothing and waits on no other process.

Four gates stand before the append. `enable_logging` is the master switch. The writer refuses to run as root. A rule pairs a URL pattern with a verdict, log or skip, and specificity outranks list order: a pattern with a query beats an exact path, which beats a prefix, and length breaks ties only within a rank. No match means skip, so the seed ruleset is one log rule on `/`; cron and the runtime's own endpoints log as worker traffic rather than being skipped. A rule's id is the hash of its pattern, so one URL never carries two rules.

The fourth gate is what the log rule binds: the hooks to time, the custom events to log and the events to profile per callback. Outbound HTTP and SQL are timed only when a rule asks, through its `log_http` and `log_queries`: each remote call or query costs two entries, and a query's closing hook fires only under `SAVEQUERIES`. Where nothing is bound, the Performance dashboard's findings report insufficient instrumentation rather than guess.

![Four gates decide whether a request is logged, and a line-by-line listing of the record one request writes to the firehose, under a byte budget showing the 3,840-byte entry inside Linux's 4,096-byte atomic append](img/d06.png)

A message carries a line number, a category, a payload, an optional label and a timestamp, keyed by the request id so one request's lines land in one partition. The record opens with a process start line, a request line naming the method and the redacted URL, and an environment line. Each timed operation is a span: a start line, then a complete line carrying the duration. At shutdown the writer drains every open span as orphaned and writes the terminal, process complete or process aborted, with any fatal error's message, file, line and offending plugin.

Linux appends atomically only up to 4,096 bytes, and many PHP processes append to one partition at once, so the writer holds each encoded entry to 3,840 bytes and leaves 256 for the key and the JSON framing. Over the cap, the entry is marked truncated and its payload trimmed ten percent at a time until it fits; the category is never trimmed, because a shortened start never pairs with its close. Anything that can exceed the cap belongs in the runtime's [job intake](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-intake.php), which takes a write lock and accepts up to 32 MiB.

## Assembling a request

The firehose interleaves every request's lines. Four topologies pull them apart: [request-builder](../topologies/request-builder.tsl), [job-router](../topologies/job-router.tsl) and [flame-builder](../topologies/flame-builder.tsl) in the plugin, [job-worker](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/job-worker.tsl) in the runtime. One consumer tails one partition.

![A map of the firehose and the partitions downstream of it: two topologies split the firehose through a Tee, a third builds flames and statistics from the records, and each partition is joined by arrow to the dashboard or worker that reads it](img/d06b.png)

Assembly happens in the workers, where the request is over and nobody is waiting. [Request_Builder_Node](../includes/class-request-builder-node.php) keys an envelope per request id, folds each line in, checks the line numbers for gaps, and emits the record to the requests partition when the terminal arrives. A summary of each finished request goes to completed, error and warning lines to errors, and alert lines to alerts; [Request_Flight_Node](../includes/class-request-flight-node.php) writes an in-flight snapshot to gyroscope on the router's one-second tick. The consumer checkpoints the envelopes into its offsetlog, so a respawned worker resumes half-built requests, and a silent request is evicted as timed out after 1080 seconds at most. Four statuses mark an unclean end, F for a fatal, T for a timeout, A for an abort and I for a gap; they tell you whether a measurement is missing or the request was.

Gyroscope shows what is in flight now, Request Log a row per finished request, and Errors the error lines; a bridge carries the runtime's own diagnostics into the active request's errors. Settings holds the three switches, `enable_logging`, `log_memory` and `flush_every_line`, the effective configuration and the ruleset editor; a change applies at once through the `rules` verbs. [Dashboards](dashboards.md) covers what each one reads.

## Flames and statistics

[Flame_Builder_Node](../includes/class-flame-builder-node.php) takes one record from the requests partition at a time, writes a flame tree of nested spans, each as wide as its duration, to the flames partition, then turns the request into rows: one row for the request itself, naming its URL, server, status, method, country, user agent, worker flag, error and duration, and, when it was timed, one timing row per profile category and entry. Performance asks those rows through the [`performance`](API.md#performance--the-omnibus-dashboard-ci) verbs: site totals, the URL leaderboard, any breakdown the `query` verb can group, one URL's aggregate flame, and one request's trace with its findings.

The rows live in one SQLite file per partition, on the flame builder's host, in eight tables the `flame-facts` schema declares: `requests`, one row per request for seven days; `urls` and `agents`, each long string stored once; `timings` and `timings_site`, a category's time per five-minute bucket, per URL and per site, for 25 hours; `requests_hourly`, the requests counted per hour by server, status class, method, worker flag and country, for seven days; `category_window`, each category's and entry's sums over the last 24 hours; and `window_expiry`, which records the window's expiries so a replay subtracts nothing twice, its newest bucket the window's watermark. The timings and the hourly rollup hold sums and counts, never means, so buckets and partitions merge by addition and the reader divides. A question names its fields and measures from a fixed list, never SQL, and one statement answers it over every partition's file, so totals, distinct counts and top-N lists are exact, with no `Other` row. Where a rollup answers a question exactly, the reader reads it instead of the request rows.

## Durable statistics

The rows sit in SQLite files under the runtime's base directory, so memcache failing or restarting costs the statistics nothing, and nothing evicts a row before its table's window. Each partition's builder is its file's only writer, and writes one batch every five seconds, at every checkpoint, and at a thousand requests or 20,000 row ops, so a replay commits as it goes; a request row is inserted once, so a respawned worker's replay counts nothing twice, and a write that fails stops the worker rather than losing the batch. The dashboards read the files in place, which ties them to the flame builder's host. The rows carry no salt, so [`wp nodes memcache flush`](https://github.com/Automattic/newspack-nodes/blob/main/docs/cli.md) leaves the statistics alone; deactivating the topology and running `wp nodes gc --force`, then activating it again, rebuilds them from the retained firehose, read again from its start.

## Jobs ride the firehose too

Jobs ride the firehose because the append is already paid for, and because only firehose entries cross from a spoke to its hub ([hub control](hub-control.md#jobs-from-a-spoke)). A job is an entry in the `job` category, so the same append that logs a request can enqueue work. [Job_Router_Node](../includes/class-job-router-node.php) writes every job entry to the jobs partition, a sieve drops any job older than 900 seconds, and the runtime's job-worker topology runs the handlers. The [`complete`](../topologies/complete.tsl) topology runs assembly, flames, routing and dispatch in one worker, with a Tee splitting one firehose consumer between the builder and the router.

## Read more

- [architecture-guide.md](architecture-guide.md), the sections [Write Path: Log_Manager](architecture-guide.md#write-path-log_manager), [Per-URL logging ruleset](architecture-guide.md#per-url-logging-ruleset), [Topologies](architecture-guide.md#topologies) and [Stats Rows](architecture-guide.md#stats-rows)
- [README.md](../README.md)
- [AGENTS.md](../AGENTS.md)
- [topologies/](../topologies)
