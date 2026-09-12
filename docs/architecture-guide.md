# Newspack Event Logger Nodes Architecture

Event-logger application built on the [`newspack-nodes`](https://github.com/Automattic/newspack-nodes) runtime substrate. This document describes the *application* graph: which Nodes, what they do, how they wire together. For the underlying substrate (Node, Message, Router, Topic, Partition, Worker, Fleet, REPL), see the [substrate architecture guide](https://github.com/Automattic/newspack-nodes/blob/main/docs/architecture-guide.md).

Everything it writes hangs from the substrate's `base_directory`, `/tmp/newspack-nodes` by default.

**Substrate presence.** The [deferred bootstrap](../newspack-event-logger-nodes.php) (run on [`plugins_loaded`](https://developer.wordpress.org/reference/hooks/plugins_loaded/) priority 11) returns early unless [`\Newspack_Nodes\Bootstrap`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-bootstrap.php) exists AND `Bootstrap::version_at_least( '2.56.0', 'Newspack Event Logger Nodes' )` passes: it wires the event logger against a new-enough substrate and lies dormant otherwise. `Requires Plugins: newspack-nodes` keeps the runtime active on WordPress 6.5+; the version handshake is the graceful fallback. One thing is wired outside the deferred closure — [`Config::register_config_keys()`](../includes/class-config.php) hooks `newspack_nodes/declare_config_keys` at file load, because the substrate pulls that declaration from inside any config read, ahead of `plugins_loaded`.

## Table of Contents

- [Overview](#overview)
- [Write Path: Log_Manager](#write-path-log_manager)
- [Per-URL logging ruleset](#per-url-logging-ruleset)
- [Topologies](#topologies)
- [Application Nodes](#application-nodes)
- [Supporting classes](#supporting-classes)
- [Memcache Schema](#memcache-schema)
- [Stats_Store: Sums-Not-Means + Salt Rotation](#stats_store-sums-not-means--salt-rotation)
- [Hub vs Spoke Topology](#hub-vs-spoke-topology)
- [Hub-Side Settings Sync, Discovery, and Vault](#hub-side-settings-sync-discovery-and-vault)
- [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)
- [Job ingress and routing](#job-ingress-and-routing)
- [Configuration](#configuration)
- [Hooks and filters](#hooks-and-filters)
- [REST + React](#rest--react)
- [CLI: wp nodes reqgrep and ruleset-bench](#cli-wp-nodes-reqgrep-and-ruleset-bench)

## Overview

The logger has two paths, and no class sits on both. The write path runs inside a WordPress request: [`Log_Manager`](../includes/class-log-manager.php) appends one line per event to `firehose.pN`, and the substrate's [`Job_Intake`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-intake.php) takes the jobs that must not ride it. The read path runs in the worker fleet, where the substrate recycles each worker after about ten minutes.

![The write path: Log_Manager's three gates (enable_logging, not root, a log rule), the entry it stamps with n, k and ts around the caller's data and holds to 3,840 bytes, the 7-field Message envelope carrying the request id as KEY, and the hash that picks firehose.pN; beneath, the four logs a line or a job can land in: firehose.pN, jobfeed.pN, jobintake.pN and jobdelay.p0.](img/ag-write-path.png)

![The read path, one partition of the complete worker: a firehose Consumer feeds a Tee; one branch runs Request_Builder, writing requests.pN, errors.p0, alerts.p0 and the completed Tee to completed.p0 and gyroscope.p0; the other runs Job_Router and the 900-second Age_Sieve into jobs.pN, joined by a jobintake Consumer; a jobs Consumer drives Job_Worker and the registered handlers; a requests Consumer drives Flame_Builder into flames.pN, memcache, the flame-stats.pN mirror and the hidden auto-tuner. Four cards name the variants: performance, flame-builder alone, job-spoke, and a hub's aggregator.](img/ag-read-path.png)

A hub adds one more ingest: substrate [`Remote_Source`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-remote-source-node.php) nodes pull each spoke's firehose over SSE, and [`Remote_Job_Rewrite_Node`](../includes/class-remote-job-rewrite-node.php) flips `k:"job"` to `k:"remote_job"` before a Topic routes the lines into the hub's own `firehose.pN`. [Hub vs Spoke Topology](#hub-vs-spoke-topology) draws it.

## Write Path: Log_Manager

[`Log_Manager`](../includes/class-log-manager.php) is the per-request firehose writer, and the only class here that appends to a log during a WordPress request. Everything that reads those logs back runs in the worker fleet.

```php
class Log_Manager {
    public const REQUEST_LABEL    = 'process';
    public const REQUEST_START    = 'process (start)';
    public const REQUEST_COMPLETE = 'process (complete)';
    public const REQUEST_ABORTED  = 'process (aborted)';

    private const MAX_DATA_SIZE   = 3840;   // headroom under PIPE_BUF (4096)
    private const MAX_TIMER_DEPTH = 100;    // start/complete nesting cap
    private const ENV_VALUE_MAX   = 256;    // per-value cap in environment_v3

    public function start( string $label, array $data = [] ): void;
    public function complete( string $label, array $data = [], string $suffix = 'complete' ): void;
    public function message( string $category, array $data = [] ): bool;
    public function error( string $message ): bool;
    public function warning( string $message ): bool;
    public function info( string $message ): bool;
    public function alert( string $message ): bool;
    public function flush(): void;
    public function finish(): void;
    public function is_started(): bool;
    public function matches_url_filter( string $url ): bool;
    public function governing_rule(): ?Rule;
    public function governing_rule_id(): string;
    public function get_request_id(): string;
    public function get_partition(): int;

    public static function instance(): self;
    public static function has_instance(): bool;
    public static function started_instance(): ?self;
    public static function redact_url( string $url ): string;
    public static function url_hash( string $url ): string;
    public static function generate_request_id(): string;
    public static function firehose_dirs( string $log_path = '' ): array;
    public static function firehose_dir_template( string $logs_dir = '<config:logs_dir>' ): string;
    public static function begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void;
    public static function begin_job_context_filter( mixed $run, string $handler, string $id = '', array $message = [] ): mixed;
    public static function end_job_context( string $handler = '', string $id = '', ?array $outcome = null ): void;
    public static function suspend(): void;
    public static function resume(): void;
}
```

**Wire shape.** Each firehose line is a packed 7-field substrate [`Message`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-message.php) envelope, not bare JSONL. The request id rides `Message::KEY`, the entry map rides `Message::VALUE`, and `Message::TYPE` is `TM_STRUCT`:

```json
{"n":<line>,"k":"<category>","m":<payload>,"l":"<label>","ts":<epoch_float>}
```

- `n` — per-request line number, starting at 1. [`Request_Builder_Node`](../includes/class-request-builder-node.php) validates the sequence, so a gap or a rewind is reported rather than silently assembled. The number is stamped and consumed together: burning one reads as a GAP, which the builder reports while still letting terminals through, where reusing one reads as a duplicate and strands the record in flight until its trace times out.
- `k` — category. The ` (start)` / ` (complete)` suffix is what pairs a span, and four span shapes share the field with the one-shot entries:
  - A **hook span**, `"<hook> hook (start)"` / `"<hook> hook (complete)"`, which [`App\Core`](../includes/app/class-core.php) writes around each bound hook. `App\Core::HOOK_SUFFIX` mints the ` hook` word, so that class owns it.
  - A **listener span**, `"<callable> @<priority> (start)"` / `"<callable> @<priority> (complete)"`, which `App\Core::wrap_callbacks()` mints around every callback on a hook the governing rule names a significant event. The callable is `short_name()`'s shortened form — `Class::method`, or `{closure}:file.php:12`. `App\Core::LISTENER_PATTERN` (`/ @-?\d+$/`) is the public seam a reader classifies one with, through `App\Core::is_listener_span()`, and the `-` is load-bearing: a callback registered at a negative priority reads as a custom event without it. [`Findings`](../includes/app/class-findings.php) runs every span through that seam, which is what stops a listener being labelled `custom`.
  - A **plugin span**, `"<slug> plugin (start)"` / `"<slug> plugin (complete)"`, one pair per site-activated plugin the `00-newspack-profiler.php` drop-in timed.
  - A **transport span**, `http` or `sql`, whose state name is fixed. The redacted URL or the whole SQL rides `m` and the calling frame rides `l`, because spelling either into `k` would mint a profile category per host and per table — the axis [`Stats_Store::MAX_CAT_VALUES`](../includes/class-stats-store.php) bounds.
  - Anything else is a **one-shot entry**: `request`, `environment_v3`, `resources`, `memory`, `message`, `job`, `error`, `warning`, `info`, `alert`, `stderr`, or a custom event. `message` carries the FROM, ID and KEY of the record that opened a job context, so a reader can seek back onto the log that enqueued it.
- `m` — payload (string or nested array, JSON-encoded on the wire). [`Flame_Builder_Node`](../includes/class-flame-builder-node.php) reads it for per-event detail.
- `l` — optional stable label. Aggregation groups on `l`; `m` is the volatile message. A rule with `trace_hooks` puts the calling frame here, which is what splits one hook's sixteen firings into a flame node per caller.
- `ts` — wall-clock epoch seconds as a float. `message()` stamps it from the clock it also writes to `Message::TIMESTAMP`, unless the caller supplies its own, as `process (start)` does with the profiler's `request_ts` and each plugin span with its measured start.
- The request id is not one of the entry's fields. It rides `Message::KEY`, stamped from the writer's own `request_id`, so a reader groups a request's lines by the envelope rather than by anything a caller passed in.

**Request id.** `UNIQUE_ID` is the only server variable read, clipped to 64 chars; absent it, `generate_request_id()` mints a 32-char base-36 string and stamps it into `$_SERVER['UNIQUE_ID']`, so a subprocess inherits the same identity. No request header is a source — `UNIQUE_ID` is set by Apache's mod_unique_id, which a client cannot reach, where a header a client sends would let a visitor choose another request's id and its partition. The edge's `X-A8C-Request-Id` is kept for correlation as the allowlisted `HTTP_X_A8C_REQUEST_ID` value in the `environment_v3` entry, so `wp nodes reqgrep <edge id>` still finds the request. The id also picks the partition: [`Partition_Node::hash_to_partition()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-partition-node.php) hashes `$request_id` over `Bootstrap::global_num_partitions()`, so every line of one request lands in one `firehose.pN`. The Topic itself, `_firehose:topic`, is built once per process and adopted by every later context.

### The environment allowlist

`log_environment()` writes an `environment_v3` entry carrying the 34 `$_SERVER` keys of `ENV_ALLOWLIST` and nothing else — an allowlist rather than a filter, so a header nobody anticipated is absent by construction. Each value is capped at `ENV_VALUE_MAX` (256 bytes) with an elision marker, which keeps the whole curated map under `MAX_DATA_SIZE` even when several values run long.

The Perl reference producer, `Gyrobase::Log`, keeps hand-maintained copies of the same list, cap, marker and redaction pattern in a separate repository. dndocker's `tools/check-firehose-parity.py` is the only thing that sees both, and holds them equal — membership, order and the numbers alike; it also refuses an allowlisted key that reads as a secret. This plugin's `pre-push` runs it whenever that tree is the checkout.

### URL-secret redaction

Every URL written to the firehose (REQUEST_URI, HTTP_REFERER, redirect targets) passes through `URL_REDACT_PATTERN`, which matches a query parameter by the SHAPE of its name rather than by whole name, so `consumer_key` and `consumer_secret` are redacted without anyone having listed them.

Two tiers, because a short token collides with ordinary words. A name **containing** a credential token (`secret`, `token`, `nonce`, `session`, `hmac`, `signature`, `bearer`, `credential`, the password spellings, `apikey`, `cache_cozy_warm`, `authorization`, `auth`) is redacted wherever the token sits, which is what covers `consumer_secret` and `_wpnonce`; `auth` spares `author`, WordPress's own query var. A name carrying `key`, `sig`, `code`, `pass`, `pin`, `otp` or `client` as a whole **segment** — bounded by the name's start or end, or by `_`, `-` or `.` — is redacted too, so `consumer_key`, `api-key` and `subscription-Key` go while `keyword`, `postcode` and `design` stay.

The accepted cost is that the segment rule cannot tell a postal code from an OAuth one, nor a public OAuth identifier from the credential beside it: `country_code` and `client_id` are redacted though neither is a secret. `LogManagerTest` pins both rather than leaving them to be rediscovered. This stays a DENYLIST — a credential under a name carrying none of these tokens still reaches the log — because an allowlist is the wrong trade for a URL log, where `?p=`, `?s=`, `?page=` and the `utm_*` family are what an operator reads it for.

`log_environment()` redacts before it caps a value at `ENV_VALUE_MAX`, so truncation can never hide a secret's boundary. `Log_Manager::redact_url()` is public because it is the ONE redaction path: anything that sends a URL somewhere it was not already written — the Ask brief, an agent surface — goes through it rather than a second pattern that would drift. `message()` redacts a string `m` alone: the array-valued messages are the environment map, redacted where it is built, and a job body, which is the transport `Job_Router_Node` dispatches from.

### Refuse-root

`Log_Manager::__construct()` calls [`\posix_geteuid()`](https://www.php.net/manual/en/function.posix-geteuid.php) early, right after the `enable_logging` master switch. UID 0 means the request is running as root — almost certainly a misconfigured wp-cli invocation — and writes would create files with root ownership that PHP-FPM (running as `bend` / `www-data` / etc.) couldn't subsequently append to. Construction returns before the matcher is built, so nothing ever starts — `is_started()` stays false and every `message()` no-ops for the rest of the request.

### Worker-traffic exclusion

When workers spawn via the HMAC endpoint, the substrate sets `NEWSPACK_NODES_WORKER_TYPE=<worker>` in `$_SERVER`. Two things keep that traffic out of the stats. First, the shipped config seeds SKIP rules for the substrate worker endpoints (`/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}`) and `/wp-cron.php`, so the matcher resolves those URLs to `skip` and the firehose never sees them. Second, for any worker request that IS logged, `NEWSPACK_NODES_WORKER_TYPE` rides both the `process (start)` line and the `environment_v3` entry; [`Request_Builder_Node`](../includes/class-request-builder-node.php) reads it, sets `is_worker`, and appends the worker type to the request URL through `resolved_request_url()` — `?<worker_type>` where the URL carries no query yet, `&<worker_type>` where it does — so worker traffic gets its own URL rows instead of polluting the real ones. Without that exclusion, the fleet's spawn cycle would dominate every leaderboard. [`Request_Flight_Node`](../includes/class-request-flight-node.php) resolves its in-flight gyroscope rows through that same method, and the two must agree: a job's execution and the request that enqueued it share one `/jobs/{handler}/{id}` URI, so a second spelling collapses them onto one URL row and undoes the split silently.

### PIPE_BUF and truncation

Per-line writes reach `Partition::fill()` through `Topic::fill()`, which appends via `fwrite(O_APPEND)`. POSIX guarantees atomic appends only when the payload fits in `PIPE_BUF` (4096 bytes on Linux). `MAX_DATA_SIZE = 3840` leaves headroom for the envelope around the entry, and the cap measures the ENTRY, not the caller's `$data`: `message()` stamps `n`, `k` and `ts` first and encodes that map, so a payload that clears the cap on its own still ships an oversized entry without it, which is the case the regression test seeds with 3,800 bytes of `m`.

![The fit ladder: a 4,096-byte PIPE_BUF bar split into the 3,840-byte encoded entry and 256 bytes of envelope; then message()'s measurement against the cap and fit_data()'s three descending stages: trim a string m ten percent a step, re-encoding each time (3840, 3456, 3110, … 62 rungs to 1), drop m whole, and the floor of n, k, ts, truncated and a 1,000-byte excerpt; beside them what each stage keeps, with k never renamed, and the Perl producer's different bound, 4,096 on the packed line with no truncated key.](img/ag-entry-fit.png)

Truncation is silent apart from the `truncated` flag and never throws, so size discipline is the caller's responsibility: a log entry that can approach the cap is shaped at the producer or split across entries, and a JOB whose parameters exceed a line queues through [`\Newspack_Nodes\Job_Intake::queue()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-intake.php), the locked large-write path.

`tools/check-firehose-parity.py` does not compare the PHP and Perl truncation paths at all: it holds `ENV_ALLOWLIST` membership and order, `ENV_VALUE_MAX`, the elision marker and the URL-redaction pattern equal, and holds both producers to applying that pattern where the message is assembled and to leaving a queued job body alone.

### The profiler drop-in

[`mu-plugins/00-newspack-profiler.php`](../mu-plugins/00-newspack-profiler.php) is a standalone drop-in rather than part of the plugin: it depends on nothing here, ships beside the release zip as its own asset, and is copied into `wp-content/mu-plugins/` by hand or by the deploy. The `00-` prefix sorts it ahead of every other mu-plugin, which is what lets it read [`hrtime()`](https://www.php.net/manual/en/function.hrtime.php) and `microtime()` before WordPress has loaded a plugin. `Log_Manager`'s constructor consumes and unsets both readings, so `process (start)` is stamped where PHP began rather than where the logger emitted its first line, and a nested job context cannot claim them twice.

It measures each plugin's file load by differencing one [`plugin_loaded`](https://developer.wordpress.org/reference/hooks/plugin_loaded/) firing against the next — duration, new classes, new files — for site-activated plugins alone, since must-use and network-activated ones announce themselves on `mu_plugin_loaded` and `network_plugin_loaded`, which it does not bind. At `plugins_loaded` priority `-10001`, one below the shipped `hook_start_priority`, it writes each row as a `(start)` / `(complete)` pair carrying its own measured `ts`, so the plugin-loading phase spreads across the flame instead of collapsing onto the flush moment.

### Per-request lifecycle

![The request timeline: the 00- profiler drop-in reads the clock as PHP begins; plugin spans land at plugins_loaded −10001; App\Core constructs at priority 11 and binds the governing rule's hooks; each bound hook is bracketed by hook_start at hook_start_priority (−10000), the wrapped callbacks, the sacrificial hook_spacer at PHP_INT_MAX − 2 and hook_complete at PHP_INT_MAX − 1; the HTTP pair binds pre_http_request at PHP_INT_MAX and http_api_debug at PHP_INT_MIN, and the SQL pair query at the start priority and log_query_custom_data at PHP_INT_MIN; PHP's shutdown runs finish(), which closes orphaned starts and writes the terminal, and the four error codes F, A, T and I.](img/ag-request-lifecycle.png)

`Log_Manager::instance()` constructs on first use and hands `finish()` to PHP's [`register_shutdown_function()`](https://www.php.net/manual/en/function.register-shutdown-function.php), not WordPress's `shutdown` action, which is what makes it run after a fatal. The start priority is the `hook_start_priority` config key, which [`Settings_Schema`](../includes/class-settings-schema.php) defaults to `-10000`: `hook_start` and `query_start` both bind at it, since a `Rule` carries no priority of its own, and [`App\Core`](../includes/app/class-core.php) falls back to `1` only when the key resolves to a non-integer. [`Hook_Categorizer::is_internal()`](../includes/class-hook-categorizer.php) refuses to bind four prefixes: `newspack_nodes_`, `newspack_nodes/`, `newspack_event_logger_nodes_` and `newspack_event_logger_nodes/`.

Three details of the transport pairs are easy to get wrong. [`WP_Http::request()`](https://developer.wordpress.org/reference/classes/wp_http/request/) returns a short-circuit with a bare `return $pre;` and never fires the closing action, so a span opened on a non-false `$preempt` would never close and would adopt every row after it. `http_end` closes the label it OPENED rather than one re-derived from the URL, which a redirect would change. And enabling `log_queries` defines `SAVEQUERIES` for the life of the process, since a constant cannot be withdrawn.

The `origin_frame()` walk climbs past the transport class, up to `TRANSPORT_ORIGIN_DEPTH` (16) frames, to the code that asked, because "which of my code paths makes this call, how many times" is the reader's question. It climbs whatever the rule's `trace_callers` says: that budget attaches `caller` to hook start entries alone, so `l` is the only field on a transport span that names who asked.

The four terminal codes, `F`, `A`, `T` and `I`, are `Request_Builder_Node::ERROR_STATUSES`.

## Per-URL logging ruleset

**A per-URL ruleset decides whether a request is logged and which hooks and events it instruments.** `Log_Manager::matches_url_filter()` resolves the governing rule through a `Rule_Matcher` and logs only when that rule's action is `log`; the rule's own hooks, custom events, significant events, diagnostic switches and auto-tune thresholds then drive the rest of the request. The governing rule's id rides the `process (start)` line as `rule`, which is how a reader attributes a request to the rule that admitted it.

**`Rule`** ([`includes/class-rule.php`](../includes/class-rule.php)) is an immutable value object — every property `readonly`, `with()` deriving a changed copy. Its `id` is a pure function of its pattern (`Rule_Set::id_for` = `Log_Manager::url_hash( $pattern )`), so there is exactly one rule per URL; a client-supplied or config-supplied id is ignored.

| Property | Default | What it does |
|---|---|---|
| `pattern` | required | `/prefix`, exact `/path?`, or exact path plus query prefix `/path?query` |
| `action` | required | `log` or `skip`; every action but `log` skips |
| `hooks` | `[]` | Hook names this rule instruments; `null` when `hooks_in` is `mc` |
| `hooks_in` | `inline` | Storage tier: `inline` in the rule list, or `mc` behind a pointer |
| `custom_events` | `[]` | Categories the application logs itself; never bound as `do_action` hooks |
| `significant_events` | `[]` | Hooks that get per-callback profiling and are exempt from auto-disable |
| `auto_disable_threshold` | `0` | Per-request occurrence count above which auto-tune proposes a disable; 0 is off |
| `auto_protect_time_threshold` | `0.0` | Mean ms per call at or above which auto-tune promotes a hook to significant; 0.0 is off |
| `log_http` | `true` | Time every outbound HTTP request as a span |
| `log_queries` | `false` | Time every SQL query as a span; defines `SAVEQUERIES` and costs two entries per query |
| `trace_hooks` | `false` | Name the calling frame on each hook entry's `l`, splitting one hook into a flame node per caller |
| `trace_callers` | `0` | Deep caller chains one hook may record per request, on its start entry's `caller` field; a stored `true` decodes to `Rule::TRACE_CALLERS_DEFAULT` (20) |

The constructor throws when the pattern is empty, and when `hooks` and `hooks_in` contradict each other — a null hook list means the pointer tier and a list means inline, with no third reading.

**`Rule_Matcher`** ([`includes/class-rule-matcher.php`](../includes/class-rule-matcher.php)) picks the governing rule by specificity, not by list order, and **`Rule_Set`** ([`includes/class-rule-set.php`](../includes/class-rule-set.php)) is the durable store.

![Rule matching and storage: three ranks, exact path plus query prefix over exact path over path prefix, with length breaking ties only within a rank; a worked walk of /wp-json/newspack-nodes/v1/command?x=1 down the sorted seed ruleset, where the exact skip governs and the /wp-json and / log rules sit below every exact rule; and the four storage tiers, the autoloaded rule list, a heavy rule's non-autoloaded hooks option past INLINE_HOOK_LIMIT 100, its Table mirror eln-rule-hooks at TTL 3600, and hydrate_array() for the wire.](img/ag-rule-matching.png)

Patterns are written as site paths, so a caller holding an absolute URL passes its path. The shipped default, [`Settings_Schema`](../includes/class-settings-schema.php)'s `rules`, which `Rule_Set::seed_from_config()` applies until the editor writes the option, is the `/` LOG rule plus the five EXACT skips for `/wp-json/newspack-nodes/v1/{command,log/stream,messages/stream,workers/spawn}` and `/wp-cron.php`.

**Loading.** When the option is absent, `Rule_Set::load()` builds the ruleset through `seed_from_config()`, without persisting it, until the editor first writes the option. The seed reads `Config::load_config_defaults()`, the layers beneath the option: `Settings_Schema`'s seed, then the shipped config file and `LOCAL_NEWSPACK_NODES_CONF`. A config that omits the key therefore takes the schema's seed. Empty means empty: an explicit `rules => []` in the config, like a stored `[]`, yields a zero-rule set, which logs nothing. A CORRUPT option, one that is not an array, takes the same seed as an absent one: `load()` reports it to stderr as `Newspack ELN: rules option holds type <type>, not an array; seeding from the config file and schema default.` and seeds from those layers, never from the corrupt value the option overlay would hand back. Both read paths skip an unrepresentable rule with a rate-limited notice rather than throwing — one hand-edited option must not fatal every request that resolves a rule — while a WRITE caller lets the throw stand and rejects the whole push, leaving the last-good ruleset in place.

**One rule per URL.** `rekey_by_pattern()` re-derives every rule's id from its pattern and collapses duplicate patterns, last entry winning. Every write path that accepts outside rules runs through it: the config seed, the editor's `save` verb, and `apply_synced()` off the wire. Two entries keeping differing ids for one pattern would both persist and race in the matcher; two sharing an id would alias one durable hooks option, and the inline one's `delete_option` would wipe the pointer one's list.

**Transport.** `hydrate_array()` inlines every pointer rule's hooks so a synced ruleset reaches a spoke hook-complete; `apply_synced()` re-tiers it locally on arrival, writing heavy hooks back out to the spoke's own durable option. The settings-sync value filter calls `hydrate_array()` on the way out.

**Editing.** The `rules` service CI ([`Rules_CI_Node`](../includes/app/class-rules-ci-node.php), verbs `dump`/`save`/`upsert`/`delete`/`reset`) backs the "Logging Rules" editor mounted on the settings page ([`src/rules/RulesAdmin`](../src/rules/RulesAdmin.js), mounted by `src/settings`). Every verb routes through `Rule_Set` so the tiering/reconcile invariants are never bypassed. `instrumented_union()` (the union across all LOG rules of hooks + custom events) feeds Discovery's spoke payload and the editor's selected-set browse modal.

The editor's table is sorted for SCANNING — log rules first, then alphabetically by pattern — which is deliberately not the order `Rule_Matcher` evaluates in. A row's position says nothing about which rule governs a given URL; only the three ranks above do.

**Calibration.** [`wp nodes ruleset-bench`](API.md#wp-nodes-ruleset-bench---iterationsn) measures the two hook-storage tiers directly — a grid of `[50, 65, 100, 250, 500, 1000, 2500, 5000]` hooks per rule against `[1, 10, 50]` rules, reporting median autoload, inline and pointer cost in microseconds. `INLINE_HOOK_LIMIT` is what that table sets, and 65 is its floor: the constant's docblock and the command's own printed guidance both say so, and nothing at runtime checks a hand-edited value against it.

## Topologies

Each worker group is one declarative `.tsl` file in [`topologies/`](../topologies). A TSL file is a line-oriented script the substrate's [`Shell_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-shell-node.php) interprets per partition:

| Directive | Effect |
|-----------|--------|
| `make_node <Type> <name> [args…]` | Instantiate a Node and register it under `<name>`. |
| `connect_node <from> <to>` | Wire `<from>`'s sink (or add a fan-out target on a Tee). |
| `disconnect_node <from> [to]` | Drop an edge an included topology declared. |
| `cmd <node>:config <verb> [args…]` | Run a config verb on the node's `:config` interpreter. |
| `include <topology>` | Splice another registered `.tsl` in at this point, once per file per load. |
| `var <key> = <value>;` | Declare frontmatter the runtime reads via `Topology_Analyzer::frontmatter()`. |
| `secure` | Climb the interpreter's secure ratchet one level, retiring management verbs. |

`<partition>` and `<topology>` are bound by [`Topology_Loader`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-topology-loader.php); `<config:logs_dir>`, `<config:num_segments>` and friends resolve against the substrate Config. Five tokens resolve against the application Config, through [`Config::resolve_eln_token()`](../includes/class-config.php): `<eln:is_hub>`, `<eln:stats_mirror_node>`, `<eln:stats_mirror_lifetime>`, `<eln:stats_mirror_segment_size>` and `<eln:stats_mirror_num_segments>`. The lifetime is derived rather than configured, at twice `Config::stats_retention_seconds()`, so widening the stats window widens the mirror with it; the two geometry tokens read their own config keys and fall back to the substrate's `segment_size` and `num_segments` when those keys are 0.

`<topology>` names the FLEET, which is why every offsetlog and dead-letter path carries it: an offsetlog is a reader's cursor and the reader is the fleet, so a `request-builder` fleet and a `job-router` fleet tailing the same `firehose.pN` keep separate cursors instead of stealing each other's position. That is also what lets several topologies share one byte-identical Consumer line — composing them with `include` then collapses that to a single reader.

Two things about `include` are easy to trip over. Frontmatter is read from the TOP-LEVEL file only, so a `var` inside an included file is skipped — which is why `hub-control`, `job-hub` and `job-spoke` each restate the frontmatter of a file they include. And the Shell tracks included files, so a file pulled in twice by two different parents runs once — which is what lets `performance` and `complete` each include several files that all include [`topic-probe`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/topic-probe.tsl). A `secure` line inside an included file is skipped too; only the top-level one ratchets.

The `make_node` first argument is a **shell name** the substrate resolves to a fully-qualified class by scanning the registered namespace prefixes (`make_node Request_Builder` → `\Newspack_Event_Logger_Nodes\Request_Builder_Node`); the unqualified substrate types (`Consumer`, `Partition`, `Tee`, `Topic`, `Age_Sieve`, `Remote_Source`) resolve under the substrate's own prefix.

Ten `.tsl` files ship. Five are primitives that declare their own nodes — [`request-builder`](../topologies/request-builder.tsl), [`flame-builder`](../topologies/flame-builder.tsl), [`job-router`](../topologies/job-router.tsl), [`job-feed`](../topologies/job-feed.tsl), [`aggregator`](../topologies/aggregator.tsl) — and five compose those with substrate stock topologies: [`performance`](../topologies/performance.tsl), [`job-hub`](../topologies/job-hub.tsl), [`job-spoke`](../topologies/job-spoke.tsl), [`complete`](../topologies/complete.tsl), [`hub-control`](../topologies/hub-control.tsl). Job *dispatch* (`Job_Worker_Node` tailing `jobs.pN`) is the substrate's stock [`job-worker`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/job-worker.tsl) topology, which `job-hub` and `job-spoke` include.

![The ten topologies as compositions: four substrate stock files on the left (topic-probe, job-intake, job-worker, settings-sync) with what each reads and writes; the five primitives in the middle, each naming its includes and its logs; the five compositions on the right, each a list of includes, with the frontmatter it restates and the Tee complete adds; beneath, the fleet name scoping every cursor, the five eln tokens, and shell-name resolution.](img/ag-topologies.png)

### `topologies/request-builder.tsl`

The assembly branch. Tails `firehose.pN`; `Request_Builder` writes `requests.pN`, forwards errors to `errors.p0` and alerts to `alerts.p0`, and fans completed summaries through `completed:tee` to `completed.p0` + `gyroscope.p0`.

```tsl
include topic-probe

make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/<topology>.firehose.p<partition> <config:deadletter_dir>/<topology>.firehose.p<partition>
make_node Partition alerts:partition <config:logs_dir>/alerts.p0
make_node Partition errors:partition <config:logs_dir>/errors.p0
make_node Partition gyroscope:partition <config:logs_dir>/gyroscope.p0 1048576
make_node Partition completed:partition <config:logs_dir>/completed.p0 1048576
make_node Partition requests:partition <config:logs_dir>/requests.p<partition>
make_node Request_Builder request-builder 100 3
make_node Tee completed:tee
cmd firehose:consumer:config add_snapshot_node request-builder
cmd firehose:consumer:config set_multi_writer true
cmd request-builder:config set_alerts_target alerts:partition
cmd request-builder:config set_errors_target errors:partition
cmd request-builder:config set_inflight_target gyroscope:partition
cmd request-builder:config set_completed_target completed:tee
cmd requests:partition:config void_warranty
cmd requests:partition:config with_index request-index
connect_node completed:tee completed:partition
connect_node completed:tee gyroscope:partition
connect_node firehose:consumer request-builder
connect_node request-builder requests:partition
secure
```

Four things in that file are load-bearing.

**Partition arguments are positional and optional.** The full signature is `make_node Partition <name> <dir> [segment_size] [min_segments] [num_segments] [max_segments] [min_lifetime] [lifetime]`. [`Partition_Node::node_schema()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-partition-node.php) defaults each retention argument to its matching `<config:…>` token, so `alerts:partition`, `errors:partition` and `requests:partition` — which pass a directory and nothing else — resolve to exactly the values an explicit tail would spell out. `gyroscope:partition` and `completed:partition` pass one further argument each, pinning a 1 MiB segment size against the configured `segment_size` (64 MiB by default): both are high-rate `.p0` logs whose readers only ever want the recent tail, so small segments keep retention cheap.

**Four of the five output partitions are `.p0`, not `.p<partition>`.** Every partition's request-builder appends to the same `alerts.p0`, `errors.p0`, `gyroscope.p0` and `completed.p0`. That is safe precisely because none of them lifts the PIPE_BUF cap: each write stays a single atomic append, which is what makes a shared multi-writer log correct. `requests:partition` is the one per-partition output, and it is the one that runs `void_warranty`.

**`void_warranty` on `requests:partition`** lifts the per-message cap to 32 MiB (`Partition_Node::MAX_LARGE_LINE_SIZE`) *without* a per-Partition lock — that partition is written by exactly one worker fleet, and the substrate refuses to spawn a topology set where two fleets write the same partition, so the exclusivity lock `allow_large_writes` carries is redundant here. Full `Request_Builder` request documents regularly exceed the 4 KB PIPE_BUF ceiling on pages with many timed hooks. Everything that must fit in PIPE_BUF instead routes through [`\Newspack_Nodes\Line_Fitter::fit()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-line-fitter.php), which halves the listed VALUE fields until the packed line fits and drops the line loudly when nothing is left to cut — `m` and `url` for the error and alert entries `Request_Builder_Node` fans out, `url` and `user_agent` for its compact completed summaries and for the in-flight rows `Request_Flight_Node` ships.

**`add_snapshot_node` + `set_multi_writer`.** The first wires the consumer's offsetlog to checkpoint `Request_Builder`'s in-flight cache, so in-flight requests survive a worker respawn. The second tells the firehose Consumer to expect concurrent producers, since many FPM workers append to `firehose.pN`.

### `topologies/flame-builder.tsl`

Per-partition flame builder. Tails `requests.pN`; `Flame_Builder` emits `flames.pN` and bumps the memcache schema via [`Stats_Store`](../includes/class-stats-store.php).

```tsl
include topic-probe

make_node Consumer requests:consumer <config:logs_dir>/requests.p<partition> <config:offsets_dir>/<topology>.requests.p<partition> <config:deadletter_dir>/<topology>.requests.p<partition>
make_node Flame_Builder flame-builder
make_node Partition flames:partition <config:logs_dir>/flames.p<partition>
make_node Partition flame-stats:partition <config:logs_dir>/flame-stats.p<partition> <eln:stats_mirror_segment_size> <config:min_segments> <eln:stats_mirror_num_segments> <config:max_segments> <config:min_lifetime> <eln:stats_mirror_lifetime>
cmd requests:consumer:config add_snapshot_node flame-builder
cmd flame-builder:config configure_stats <partition>
cmd flame-builder:config set_stats_target <eln:stats_mirror_node>
cmd flame-builder:config set_is_hub <eln:is_hub>
cmd flames:partition:config void_warranty
cmd flames:partition:config with_index flame-index
cmd flame-stats:partition:config void_warranty
cmd flame-stats:partition:config with_index stats-index
connect_node requests:consumer flame-builder
connect_node flame-builder flames:partition
secure
```

`configure_stats <partition>` constructs the per-partition `Stats_Store`, taking its retention window from `Config::stats_retention_seconds()` — the substrate's `min_lifetime` (default 43200), floored at `Stats_Store::PREFIX_FLOOR` (3600). Auto-tune thresholds live on each LOG rule, not on a topology token; `Flame_Builder_Node` reads the governing rule's thresholds per completed request (see [Flame_Builder_Node](#flame_builder_node) and [Auto_Tuner_Node](#auto_tuner_node)).

`set_stats_target <eln:stats_mirror_node>` mirrors the memcache stats into the durable `flame-stats:partition`, which a memcache miss reads back through its `stats-index` companion index; set `stats_mirror_node` to `''` to disable it. The partition takes its own ring geometry from `<eln:stats_mirror_segment_size>` and `<eln:stats_mirror_num_segments>`, because `flame-stats` holds every per-URL frame and the substrate knobs also size `requests`, `flames` and `jobs`.

![One five-minute bucket on four tiers: requests file into $pending by the bucket they finished in; flush() sums them into memcache at most every five seconds; every landed write refreshes a held frame in RAM, carried in the offsetlog under a 2 MiB cap until the bucket closes; flame-stats.pN receives the frame once, at the first checkpoint after the close, and keeps it for twice the stats window. Four cards cover what is mirrored and the 10,000-frame backstop, a respawn and the 32 MiB cliff behind its carry, the read-back through locate_by(), and the mirror's own key version.](img/ag-stats-mirror.png)

Reading it back is BUDGETED, and only for the dashboard. [`Partition_Node::locate_by()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-partition-node.php) cannot stop early on a key that is absent from the index, so every batch that misses on memcache costs a full pass over the mirror's `.idx` — ~192ms over 150,000 lines — and one cold `urls` poll issues thousands of them, sixteen shards by four partitions by twenty-five bucket chunks. So [`Performance_CI_Node::dispatch()`](../includes/app/class-performance-ci-node.php) starts each VERB with a fresh `stats_mirror_read_budget_ms` (default 1500, `0` off), `Flame_Builder_Node::arm_stats_reader()` stops consulting the mirror once it is spent, and the reader answers from memcache alone for the rest of that verb — one poll batches `overview` and `urls` into a single POST, so a response can spend the budget twice — the "no data" degradation the stats readers already take. `rehydrate_seam()` also skips any namespace `STATS_MIRROR_TOPN` caps at zero, since a read of one can only ever walk and find nothing. The WORKER's own seam, armed by `set_stats_target`, is unbudgeted: it is restoring its own state, not answering a poll.

`set_is_hub <eln:is_hub>` turns on the three per-server AGGREGATES — the `lb_s` leaderboard, the per-server dimensional series and the per-server category series — because only an aggregating hub has more than one reporting server to spread across them. The URL row's `srv` split is deliberately NOT gated by it: a scoped read of a row carrying no split returns nothing, so gating it would empty the URL table on every spoke rather than save one write.

### `topologies/job-router.tsl`

The job-routing half, reading jobs out of the firehose. Tails `firehose.pN` and, via the included substrate `job-intake` topology, `jobintake.pN`; `Job_Router` normalizes both sources and writes `jobs.pN`. Dispatch of those lines is a separate leg — the substrate's stock `job-worker` topology, which `job-hub` adds.

```tsl
include job-intake

make_node Consumer firehose:consumer <config:logs_dir>/firehose.p<partition> <config:offsets_dir>/<topology>.firehose.p<partition> <config:deadletter_dir>/<topology>.firehose.p<partition>
make_node Job_Router job-router
make_node Age_Sieve jobs:sieve 900 1
cmd firehose:consumer:config set_multi_writer true
disconnect_node jobintake:consumer
connect_node jobintake:consumer job-router
connect_node firehose:consumer job-router
connect_node job-router jobs:sieve
connect_node jobs:sieve jobs:partition
secure
```

`include job-intake` brings in the substrate's [`job-intake`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/job-intake.tsl) nodes, `jobintake:consumer` wired straight to `jobs:partition`, the partition already under `void_warranty`, plus `topic-probe`, which this file therefore does not name itself. The `disconnect_node` + `connect_node` pair then re-routes the consumer through `job-router`. Because `job-intake` declares `jobs:partition` with the warranty voided, the substrate's conflict gate refuses to co-activate the stock `job-intake` topology alongside `job-router`.

Both Consumers feed `job-router`. It selects a nested array under `m` when present and otherwise treats the entry itself as the job body, so firehose and Job Intake records take one normalization path; routing does not depend on the Consumer name in `Message::FROM`. `Job_Router` takes no arguments: staleness is the downstream [`Age_Sieve`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-age-sieve-node.php)'s job, and it drops messages whose envelope `TIMESTAMP` age exceeds `max_age` — 900 seconds here, with the drop warning enabled.

**That age is measured from ENQUEUE, never from the read.** [`Durable_Reader::forward_line()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/trait-durable-reader.php) rebuilds each record with `Message::unpacked()`, which restores the TIMESTAMP the producer stamped when the line was written. So when the fleet stops draining the firehose, `jobfeed` and `jobintake` — a standing `wp nodes stop` hold, a long deploy, a crashed worker, a restored partition — every job enqueued more than fifteen minutes before the router catches up is discarded as it is read: the Consumer's cursor advances, nothing is dead-lettered, and no retry is scheduled. A job carrying a `delay` or a `not_before` is the exception, because `Job_Delay::sweep()` re-writes it through `Job_Intake::write_job()` at delivery and it arrives with a fresh envelope. Where the site's jobs are not disposable, raise `max_age` on the `make_node` line or drain the backlog before lifting the hold.

### `topologies/job-feed.tsl`

`job-router` with one substitution: it tails `jobfeed.pN` instead of the firehose. Everything else — the included `job-intake` leg, the `Age_Sieve`, the re-route — is identical.

Reading `jobfeed` costs a fraction of reading the firehose, because the feed carries jobs alone where the firehose carries every log entry of every request. A site whose producers call `Job_Intake::feed()` should run this one; a site whose jobs ride the firehose as `k:"job"` needs `job-router`, since nothing writes `jobfeed` for it. The two are alternatives rather than a pair: both write `jobs.pN` under `void_warranty`, so the conflict gate refuses to activate them together.

### `topologies/job-hub.tsl` and `topologies/job-spoke.tsl`

Routing plus dispatch in one process:

```tsl
var stale_timeout = 600
include job-router     # job-spoke.tsl includes job-feed instead
include job-worker
secure
```

[`job-worker`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/job-worker.tsl) is the substrate's stock dispatch topology: a `Consumer` on `jobs.pN` in line mode, a `Job_Worker` node, and a 15s `Job_Probe` writing per-worker job stats to `jobstats.p0`. A throwing handler propagates back to that Consumer, which quarantines the job to its dead-letter sibling rather than dropping it.

The `var stale_timeout = 600` restates `job-worker`'s own frontmatter, which the include skips. It has to: a handler only heartbeats where it reaches `should_continue()`, so one doing CPU-bound work, a long query or a `proc_open` render does not beat at all, and at the default a peer would steal its lock mid-job and replay the job to die again.

### `topologies/performance.tsl`

`request-builder` + `flame-builder`, nothing else:

```tsl
include request-builder
include flame-builder
secure
```

Both included files include `topic-probe`, so the composition mounts one probe and two Consumers. Use it where job dispatch lives in a separate fleet and you want request assembly plus flame stats in one worker group.

### `topologies/complete.tsl`

The everything-in-one worker: request assembly, flame stats, job routing and job dispatch in one process.

```tsl
include performance
include job-hub
make_node Tee firehose:tee
disconnect_node firehose:consumer
connect_node firehose:consumer firehose:tee
connect_node firehose:tee request-builder
connect_node firehose:tee job-router
secure
```

Order matters. `job-hub` is included last and, through `job-router`, wires `firehose:consumer → job-router` directly; the five lines below it then re-route that single edge through `firehose:tee` so both `request-builder` and `job-router` feed from the Tee. **A Consumer's `connect_node()` goes to a Tee only when the source has more than one target.** `jobintake:consumer` still has one target, so it stays wired directly to `job-router`. Number of Tees equals number of source fan-outs, not number of sources.

`complete` restates no frontmatter, so the `var stale_timeout = 600` that `job-hub` restates is skipped here, and `complete`'s `Job_Worker` runs at the substrate's 60-second [`Lock_Node::STALE_TIMEOUT`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-lock-node.php) — the mid-job lock steal that restatement exists to prevent.

### `topologies/aggregator.tsl`

Hub-side ingest. Per-spoke substrate `Remote_Source` nodes (operator-wired on the console canvas) pull each spoke's firehose via SSE; the ELN `Remote_Job_Rewrite` node flips aggregated `k:"job"` entries to `k:"remote_job"`; the multi-partition `Topic` KEY-routes by request-id hash so each downstream firehose-consuming partition sees its own slice.

```tsl
include topic-probe
make_node Remote_Job_Rewrite remote-job-rewrite
make_node Topic firehose:topic <config:logs_dir>/firehose.p{partition}
connect_node remote-job-rewrite firehose:topic
secure
```

Per-spoke `Remote_Source` nodes are NOT in the stock topology — the operator adds them on the canvas, one per spoke/partition:

```tsl
make_node Remote_Source spoke-<id> <vault-id> firehose.p<partition> \
    <config:offsets_dir>/spoke-<id>.<topology>.p<partition> \
    <config:deadletter_dir>/spoke-<id>.p<partition>
connect_node spoke-<id> remote-job-rewrite
cmd spoke-<id>:config set_multi_writer true
```

[`Remote_Source`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-remote-source-node.php) reads like the Consumer it is: `<vault-id>` names the spoke, `firehose.p<partition>` names the remote partition to subscribe to, and the last two arguments are its offsetlog and dead-letter directories. Both are optional in the schema and both should be passed — omitting them means no cursor and no quarantine. Scope the offsetlog with `<topology>` so two hubs pulling one spoke partition never share a cursor.

`set_multi_writer` belongs on every firehose spoke, because every request process there appends to that log. It asks the SPOKE's reader to hold a superseded segment for the seal grace; without it a straggler's last line — typically the request's terminal `process (complete)` — is orphaned, and the request never finalizes on the hub.

What a `Remote_Source` owns — its hidden siblings, its offsetlog, reconnect and backoff, its status snapshot, the Vault lookup and the TLS posture — is the substrate's, in [Hub and spoke](https://github.com/Automattic/newspack-nodes/blob/main/docs/hub-and-spoke.md). The `k:"job"` → `k:"remote_job"` rewrite is the `Remote_Job_Rewrite_Node` graph node (see [Remote_Job_Rewrite_Node](#remote_job_rewrite_node)) — not a filter, not a TSL setter; non-`job` entries pass through. Hub-mode is derived rather than configured — there is no `enable_aggregator` config key, and [Hub vs Spoke Topology](#hub-vs-spoke-topology) carries the two signals that settle it. Operators stand a hub up by selecting the `aggregator` and `hub-control` topologies.

### `topologies/hub-control.tsl`

Single-instance hub control plane. It is an ELN overlay over the substrate's stock [`settings-sync`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/settings-sync.tsl) topology, and it pins `var num_partitions = 1` so the fleet runs ONCE regardless of the data `num_partitions` — the pin must live in this file, since frontmatter is read from the top-level file only.

```tsl
var num_partitions = 1;

include settings-sync

make_node Discovery_Collector discovery-collector 300

cmd settings-sync:config add_setting newspack_event_logger_nodes_rules performance newspack_event_logger_nodes_rules
cmd settings-sync:config add_setting newspack_event_logger_nodes_log_memory performance newspack_event_logger_nodes_log_memory
cmd settings-sync:config add_setting newspack_event_logger_nodes_flush_every_line performance newspack_event_logger_nodes_flush_every_line
secure
```

The included `settings-sync` is the substrate's control plane — the settings log, the `Settings_Sync` tick and its own `add_setting` registrations for `num_partitions` and the six-axis `remote_*` geometry — described in the substrate's [Hub and spoke](https://github.com/Automattic/newspack-nodes/blob/main/docs/hub-and-spoke.md) chapter.

The three lines above add the application options, and they must stay in step with `Performance_CI_Node::SETTINGS_OPTIONS`: a hub push naming anything outside that whitelist comes back as "unknown option". This file also adds `Discovery_Collector`, which fans `discovery.get` to every spoke on its own 300s tick and union-merges the replies into the hub's staging options.

**Neither fan-out goes through a Tee.** Each spoke's command is signed under that spoke's session key, and re-addressing a signed command after the mint makes it verify nowhere — so `Settings_Sync` and `Discovery_Collector` each iterate their own live targets and mint one signed command per spoke. The pipeline stays correctly inert until an operator wires per-spoke `HTTP_Out <name> <vault-id>` nodes from the console and connects the two nodes to them.

`Settings_Sync` and `Settings_Event_Writer` live in the substrate ([`\Newspack_Nodes\Settings_Sync_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-settings-sync-node.php), [`\Newspack_Nodes\Settings_Event_Writer`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-settings-event-writer.php)); `Discovery_Collector_Node` is this plugin's (see [Discovery_Collector_Node](#discovery_collector_node)).

### Topology resolution

The `topologies` key lives on the substrate. The plugin only **publishes its catalog**: at boot, it calls [`Topology_Registry::register_plugin()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-topology-registry.php) with `'Newspack_Event_Logger_Nodes\\'` and `NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'` — one call that registers the application namespace prefix (for `make_node` short-name resolution) AND the stock-topology dir together — so anyone calling `Topology_Registry::resolve()` (admin, REST, tests, CLI, workers) finds the stock `.tsl` files. Which catalog entries actually spawn workers is decided downstream by the substrate's active-topology option (`newspack_nodes_topologies`, which `Topology_Registry::activate()` and `deactivate()` write) — this plugin only publishes the "what topologies exist" set; the substrate filters it by what the operator activated. `num_partitions` defaults also come from the substrate config, so one setting drives both `Log_Manager` (write side) and the worker fleet (read side); hardcoding diverges them.

Cost on regular WP requests is one array append at boot — the `.tsl` files themselves aren't parsed yet. Actual resolution and parsing happen in three places, none on the page-render hot path: the fleet's config-check tick (every 15s), worker bootstrap (once per spawn), and REST workers/dashboard reads.

## Application Nodes

### Request_Builder_Node

[`Request_Builder_Node`](../includes/class-request-builder-node.php) assembles requests from firehose entries via the substrate's [`LRU_Cache`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-lru-cache.php), a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`) so `fire()` rotates the in-flight cache even when no firehose line arrives to drive rotation via `fill()`.

![Request assembly: the per-line sequence gate (a duplicate n is dropped, a matching n is stored, a gap records gap_after and drops all but a terminal, which lands and leaves an entries (lost) marker); the three-bucket LRU rotating every 200 seconds, where every get() promotes and the oldest bucket evicts with T, 400 to 600 seconds after a record's last line; the five outputs, sink, completed_target with its ten-key summary, errors_target, alerts_target and inflight_target; and the fold, which keeps ten head and ten tail entries around an entries (aggregated) marker and the kept closes, with the middle merged into a Flame_Fold tree, under max_entries_per_request 20,000 for one record and entry_budget 50,000 across all.](img/ag-request-assembly.png)

```php
class Request_Builder_Node extends Timer_Node {
    public const DEFAULT_BUCKET_SIZE             = 100;   // positional arg 0
    public const DEFAULT_NUM_BUCKETS             = 3;     // positional arg 1
    public const DEFAULT_ENTRY_BUDGET            = 50000; // positional arg 2
    public const DEFAULT_MAX_ENTRIES_PER_REQUEST = 20000; // positional arg 3
    public const DEFAULT_EVICTION_WINDOW_SEC     = 600;   // DEFAULT_NUM_BUCKETS × BUCKET_ROTATION_S

    public const LOST_MARKER_KEY     = 'entries (lost)';
    public const FOLD_MARKER_KEY     = 'entries (aggregated)';
    public const SEQUENCE_BREAK_KEYS = [ self::LOST_MARKER_KEY, self::FOLD_MARKER_KEY ];
    public const ERROR_STATUSES      = [ 'F', 'T', 'A', 'I' ];
    public const TERMINAL_KEYWORDS   = [ 'process (complete)' => true, 'process (aborted)' => true ];

    private const BUCKET_ROTATION_S = 200;
    private const MAX_STACK_DEPTH   = 50;   // runaway cutoff
    private const FOLD_KEEP_HEAD    = 10;
    private const FOLD_KEEP_TAIL    = 10;
    private const INDEX_LINE_BYTES  = 97;

    public function fill( array $message ): void {
        // Validate the per-request sequence `n`; assemble (start)/(complete)
        // pairs into nested spans; fan `alert` to alerts_target and
        // `error` / `warning` / `stderr` — plus any keyword ending
        // `(error)` or `(warning)` — to errors_target.
        // On rotation: evict timed-out bucket (sets error_status='T' on each orphan).
        // Under entry pressure: fold the largest envelope, or this one.
        // On request complete: emit the full doc via $this->sink, plus the
        // compact summary to completed_target.
    }

    protected function fire(): void {
        // Router-TIMER tick (1s): rotate the in-flight cache so stalled
        // requests time out even on idle / low-traffic partitions.
    }
}
```

Eviction emits through the graph rather than writing files, so the completed fan-out gets a copy, hooks can observe, and tests can capture. A spoke authors the wire fields the summary's arithmetic reads, so every envelope is coerced at its two entrances — the state callbacks that build it and `restore_state()`, which rehydrates a checkpoint — and the substrate's `LRU_Cache` offers every envelope in a bucket to the callback before raising the one throwable it kept, with the bucket already detached so nothing rides the checkpoint into the next worker. **The compact completed summary is a fixed wire contract**: `build_compact_summary()` emits the ten keys the diagram lists, with `url` in `resolved_request_url()`'s form, worker suffix and all, and `end_time` as the start plus the duration in seconds, which is the timestamp a Request Log row carries. The Request Log's `shapeRow()` reads seven of the ten — everything but `start_time`, `state` and `error_status` — and drops a summary carrying no `url` outright. `state` is what `GyroscopeView` tells a completion from an in-flight row by, so it is load-bearing on the other branch of the same Tee; `error_status` reaches neither, the Error Log taking its detail from the separate `errors.p0` stream.

**Every `fill()` is bracketed by [`Deferred_Clean_Stop`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/trait-deferred-clean-stop.php)**, the substrate trait this node and `Flame_Builder_Node` both use: `clear_pending_stop()` at entry, `raise_pending_stop()` at every exit. A downstream Partition write raising a cooperative stop has already flushed the record to disk, so the stop is HELD rather than allowed to unwind mid-message — the node finishes that message's own bookkeeping, evicting the completed request here and folding the record into the stats accumulators there — and is then re-raised as `Worker_Should_Stop_Clean`, on which the firehose Consumer commits PAST the line instead of replaying it. Drop the bracket and the successor replays a line the restored snapshot has already counted: `duplicate message: expected #N, got #N-1`, and an assembled request document lost. A third snapshot node needs the same pair.

**No consumer may reason across a marker.** The interval one covers is missing DETAIL, not idle time. [`Findings::entry_gap()`](../includes/app/class-findings.php) skips a gap on either side of one, `Reqgrep_Command` breaks its sequence there, and [`logEntryUtils`](../src/overview/utils/logEntryUtils.js) splices the merged spans back at it. What a consumer MAY read across one is the record's own ending — whether anything closes the outermost pair — which decides framing and leaves the interval exactly as unmeasurable as it was. The fold keeps the CLOSE of any span the kept head left open because it chose that head, so it knows which frames it left open, and a span opened in the head frames every row after it.

**A profile's `time` is SELF time.** The assembled document carries a `profiles` map beside its entries, one record per category name — `{ entries, count, time, ts }`, where `entries` splits the category by the origin label `App\Core` put on `l`, as `[ time, count ]` per origin and capped at `MAX_PROFILE_ENTRY_LABELS`. Closing a span credits its duration to that record's `time` and then subtracts the same duration from every open ancestor up to the first non-callback one, stopping at the request's own frame, so a hook's number is its own work rather than its children's. Callback states — the labels ending ` @<priority>` — are exempt in both directions: they neither trigger the subtraction nor stop it, because a callback's time already belongs to the hook that dispatched it. `Flame_Builder_Node` aggregates `profiles` into the leaderboards `Performance_CI_Node`'s `dump_url` and the dashboard's [`RequestProfile`](../src/overview/RequestProfile.js) render, so a reader taking those figures for wall time reads a hook's own cost as its whole subtree's. Exclusive time is also what lets that panel draw the categories as a tiling bar rather than a nest, and it is why two of its numbers deliberately disagree: the bar and the "Total Profiled" footer drop the wrapped listeners, whose time already sits inside a hook the bar has drawn, while the table keeps their rows — a hook's slowest callback is exactly what a reader opens it for — so the percentage column does not sum to the footer. Every percentage divides by the request's wall clock, so the bar's unfilled remainder is the time nothing instrumented accounts for.

One TM_REQUEST verb answers for it. `GET_CACHE` replies with `pending_count`, the oldest pending rid and its age in seconds, a five-rid `sample` and the running `line_counter`, which is what a REPL attach reads to tell an assembling partition from a stalled one; an unknown verb still gets a reply, carrying an `error` payload.

The node also owns the `request-index` formatter (`format_index_entry`), a fixed-width 97-byte line — rid, url_hash, timestamp, duration, status, segment, offset, length, peak MB, a one-char method code, and a one-char error status — that `Partition::with_index()` writes beside `requests.pN` and `Performance_CI_Node` seeks against.

Two static helpers publish that layout to the readers. `index_column( $field )` answers the offset and length of a column whose trimmed raw bytes equal the parsed value, which only `rid` and `url_hash` do — a zero-padded or coded column such as `segment` or `method` answers `[]`, because raw bytes cannot settle an equality question there. `index_completion_columns()` answers the start-time and duration-in-ms columns a time-bounded walk orders itself by; it names completion rather than start because a request that ran for hours carries a start from outside any recent window. Together they let an index scan reject a line with one `substr` and `trim` instead of parsing it, which is what makes a `MAX_INDEX_ENTRIES` walk affordable. `Flame_Builder_Node` exposes the same pair for the flame index, where `index_completion_columns()` answers `[]`: those lines carry no time to compare, so a walk over them keeps the entry budget as its only bound.

### Request_Flight_Node

Backs the Gyroscope dashboard's live in-flight view. It's a hidden [`Timer_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-timer-node.php) sibling that `Request_Builder_Node` owns: on each timer tick it snapshots the patron's in-progress request map and emits **one TM_STRUCT message per in-flight request** (KEY = rid) to the gyroscope partition, so the page can render a live table of what is running right now, each completion flashing in it for one refresh tick. One message per row, because a single batched list crosses the 4 KB cap under load.

![The in-flight view in three columns. On the left, Request_Flight_Node reads Request_Builder_Node's in-flight LRU_Cache and its delta toggle on every 1-second Router tick and emits one TM_STRUCT row per request, KEY = rid: every row by default, or in delta mode only rows active since the fire watermark; Line_Fitter halves url, then user_agent, to fit 4,096 bytes, or drops the row with a rate-limited warning; a finished or timed-out request sends its ten-key complete summary through completed:tee. In the middle, gyroscope.p0 interleaves the two record shapes. On the right, GyroscopeView folds records by state alone, recording a completion even for a rid never seen in flight and dropping an in-flight row for a rid it holds as complete; Inflight calls snapshot() on the operator's 100 ms to 10 s cadence, and snapshot() shows each completion once, reaps in-flight rows unrefreshed for 15 minutes, sorts by est_ms descending and caps, while rps averages reaped completions over 10 seconds.](img/ag-inflight-rows.png)

```php
class Request_Flight_Node extends Timer_Node {
    protected function fire(): void;          // one row per in-flight request, on the Router's 1s TIMER
    public function inflight_snapshot(): array;
    public function target( $value = null );  // the sole enable switch
}
```

[`Request_Flight_Node`](../includes/class-request-flight-node.php) has no `set_interval()` / `interval()` / `fire_cb()`: snapshots hitchhike the Router's 1s TIMER through `fire()`, a non-empty `target()` is the sole enable switch, and clearing it stops the timer. The node is hidden from the topology console by the substrate's patron filter in `dump_metadata`, and from the palette by its own `node_schema()` category. Its configuration surfaces on the patron's `:config` interpreter as `set_inflight_target` and `set_inflight_delta` — the `request-builder` topology (and therefore `performance` and `complete`) wires it with `cmd request-builder:config set_inflight_target gyroscope:partition`. The delta flag lives on the patron as a declared `toggle`, and Flight keeps no copy of it or of the in-flight map, so there is no second tracker to keep coherent.

The `rps` arithmetic is [`GyroscopeView`](../src/gyroscope/nodes/gyroscope-view-node.js)'s own; the Request Log and the Error Log take their lines-per-second from the substrate's `RateSmoother`, which puts an EMA over the same windowed average.

### Flame_Builder_Node

[`Flame_Builder_Node`](../includes/class-flame-builder-node.php) aggregates completed-request events into flame-graph stats and writes flame data to `flames.pN`. It reads the completed-request documents `Request_Builder_Node` wrote to `requests.pN`, one TM_STRUCT message each. It emits into every memcache namespace via [`Stats_Store`](../includes/class-stats-store.php) — hourly, leaderboard, per-server leaderboard, URL index and its coarse hourly tier, the URL name table, per-URL, dimensional and category — and `Stats_Store` holds the per-URL accumulator itself as a rotating `Table_Node` accumulator (5 buckets × 1000 entries).

![The flame-tree pipeline Flame_Builder_Node runs on every record, in four stages: build_flame_data() matches each (complete) to the nearest open span of the same base name, LIFO, names a node <base>: <l> with its offset t, attaches but never pushes past MAX_STACK_DEPTH 50, numbers duplicate siblings and raises each parent over its children with cover_children(); a worked per-request tree shows the result; merge_flame_children_incremental() sums each node into the URL's aggregate by name and drops one unseen for 3,600 seconds; finalize_flame_node() divides by the aggregate's own request count. Three cards cover Flame_Fold, the resumable merging twin; exclusive time, which lives in the profiles map rather than the flame; and the browser's pruning and time-spacer passes.](img/ag-flame-tree.png)

The auto-tune thresholds are **per-LOG-rule** (`auto_disable_threshold` / `auto_protect_time_threshold` on the governing `Rule`). `Flame_Builder_Node` judges each completed request against them and, at the 5s flush, addresses its candidates — hooks to disable, and hooks to promote to "significant" status, which protects them from auto-disable — to the owned `{name}:auto-tuner` sibling, tagged with the `rule_id` to mutate. [Auto_Tuner_Node](#auto_tuner_node) draws both tests and the dispatch.

```php
class Flame_Builder_Node extends Node {
    const FLUSH_INTERVAL_SEC       = 5;
    const ENTRY_LIMIT_GLOBAL_UPPER = 100;   // hysteresis: trim at upper, down to lower
    const ENTRY_LIMIT_GLOBAL_LOWER = 50;
    const ENTRY_LIMIT_URL_UPPER    = 40;
    const ENTRY_LIMIT_URL_LOWER    = 20;
    const DIM_FIELDS               = [
        'status'  => 'status_category',
        'method'  => 'request_method',
        'server'  => 'server_name',
        'country' => 'country_code',
        'from'    => 'http_from',
        'ua'      => 'user_agent',
        'ja4'     => 'ja4_hash',
    ];
    public  const MAX_URLS_PER_SHARD = 2000;    // top-N per URL-index SHARD (x16)

    private const MAX_CHECKPOINT_MIRROR_BYTES = 2097152; // held-frame carry budget
    private const WRITE_BATCH_KEYS            = 500;     // keys per flush batch
    private const ROLLUP_HOURS_PER_FLUSH      = 2;       // coarse-tier backfill bound
    private const REPROBE_EVERY_FLUSHES       = 60;      // re-probe the folded-hours memo
    protected const INTERN_TABLE_LIMIT        = 50000;   // per-process string intern cap
    private const NAMED_URL_BUCKET_SIZE       = 2000;    // urlmap write-once held set
    private const NAMED_URL_BUCKETS           = 4;
}
```

The flush is a throttle inside `fill()`, not a timer: `fill()` runs `flush()` at most once per `FLUSH_INTERVAL_SEC`, and only when a record arrives, so an idle partition holds its accumulators until the next one does, and the Consumer's checkpoint carries them meanwhile. `flush()` rolls the coarse hourly tier up BEFORE it persists, because the roll-up is what probes that tier — so the first flush after a respawn has an accurate `folded_hours` memo before a single row is placed. The memo is per-process, which is safe because one partition has one writer; `REPROBE_EVERY_FLUSHES` covers the other direction, where an hour key has been evicted and would otherwise stay believed-folded for the life of the worker.

Three constants bound the write path's memory rather than its output. `WRITE_BATCH_KEYS` holds one read/write batch to at most one shard's worth of rows. `INTERN_TABLE_LIMIT` caps the per-process string-intern table every dimension value, category and entry name is looked up in, so repeated names share one zval instead of one per `json_decode`; past the cap the table freezes rather than growing. And the named-URL held set (`NAMED_URL_BUCKET_SIZE` × `NAMED_URL_BUCKETS`) remembers which hashes already have a `urlmap` name, so a name is written once per URL instead of once per flush.

One TM_REQUEST verb answers for it. `GET_STATS` replies with the URL-accumulator depth, the pending per-URL count, the pending bucket keys, the intern-table size, the age of the last flush, the auto-tune queue depth, `is_hub`, the significant-event count, and `mirror_held_frames` / `mirror_held_bytes` — the widest namespace's held count, against `MAX_HELD_FRAMES`, and the held total in bytes, whose trend says how much headroom `MAX_CHECKPOINT_MIRROR_BYTES` still has. An unknown verb gets an `error` payload rather than a throw.

The node also owns two index formatters the topology names: `flame-index` beside `flames.pN`, and `stats-index` beside the durable `flame-stats.pN` mirror. A mirror frame is `TM_STRUCT` with the memcache key in `Message::KEY` and `{ data, ttl }` as its VALUE — the key ONCE, since `format_stats_index_entry()` hashes it off `Message::KEY` and `rehydrate_seam()`'s collision check compares against the same field.

### Auto_Tuner_Node

An owned, hidden sibling of `Flame_Builder_Node` (`{name}:auto-tuner`, patron-linked, never built in TSL). [`Auto_Tuner_Node`](../includes/class-auto-tuner-node.php) receives the builder's tuning decisions as `TM_STRUCT` messages and applies each by **mutating the one rule** its `rule_id` names, persisting the whole ruleset through `Rule_Set::save()`. The change reaches spokes the way any admin edit does: the write records a settings event that the `hub-control` topology's `Settings_Sync` node fans out (see [Settings Sync: No Operator Gate](#settings-sync-no-operator-gate)).

![The auto-tune sequence across four lanes, Flame_Builder_Node, Auto_Tuner_Node, Rule_Set and hub-control: the builder judges each completed request against the governing rule's auto_disable_threshold and auto_protect_time_threshold, holds the 5-second evlog:auto_disable_lock across its flush, and sends one TM_STRUCT per list to tune, keyed disable_hooks, disable_custom_events or add_significant_events with { rule_id, items } as the value; the tuner's authorized() check; mutate_rule()'s cache invalidation, apply_* and unchanged() no-op test; and Rule_Set::save(), whose settings event Settings_Sync pushes to every spoke.](img/ag-auto-tune.png)

Expressing it as a Node with `fill()` dispatch keeps the logic one straight path, in the flame-builder worker that owns it.

### Job_Router_Node

Multi-input routing. It reads the firehose, `jobfeed` and `jobintake` without interpreting `Message::FROM`: an array under `entry['m']` is the body, otherwise the entry itself is the body. Both shapes normalize into `{ k, handler, parameters, ts }` — plus `id` when the body carries one, plus whichever of [`\Newspack_Nodes\Job_Intake::DISPATCH_FIELDS`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-intake.php) (`retries`, `attempt`, `batch`, `key`) it carries — before forwarding to `jobs.pN` for [`Job_Worker_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-worker-node.php) to dispatch. Those dispatch fields are load-bearing rather than decoration: `Job_Worker` reads them back to decide a retry and to settle a batch, so dropping them disables both silently. The job kind is carried under `k` (not `type`) end-to-end, and both `job` and `remote_job` are preserved for either body shape.

| Entry shape | Selected body | Allowed kinds |
|-------------|---------------|---------------|
| Nested array under `m` | `entry['m']` | `job` or `remote_job` |
| No nested array | `entry` | `job` or `remote_job` |

The kind is read from the ENTRY's `k`, never the body's, so the hub's `job` → `remote_job` rewrite wins over whatever the spoke wrote inside the body. `ts` falls back to the entry's timestamp only when the body carries none, and is copied verbatim rather than coerced — a garbage `ts` travels through as data for the downstream reader to zero out.

Five shapes are rejected and only two warn. An invalid handler name and non-array parameters go through `drop_message()`, which prints a rate-limited warning, because each is a malformed job:

- Handler names must match [`HANDLER_NAME_PATTERN`](../includes/class-job-router-node.php) = `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D`, whose `D` refuses a trailing newline
- `parameters` must be an array when present; omission normalizes to `[]`

Wrong type flags, a non-array VALUE and a kind other than `job`/`remote_job` return silently: the firehose carries every log entry and a drain ends on a terminal TM_EOF, so those three are ordinary traffic, and warning on them would bury the two that are faults.

Two gates this node deliberately does not hold. Size belongs to the producers and the Partition — `Job_Intake::MAX_JOB_SIZE` caps an entry where it is written, `Log_Manager::MAX_DATA_SIZE` strips an oversize firehose job's `m` before this node sees it (so it arrives here as an invalid-handler warning), and the Partition refuses an oversize line where it lands. Staleness belongs to the `Age_Sieve` the topology wires downstream, which is why `Job_Router` takes no arguments at all. `Job_Worker_Node` handles valid-but-unregistered handler names after reading `jobs.pN`.

### Job_Worker_Node

> **Lives in the `newspack-nodes` substrate** ([`\Newspack_Nodes\Job_Worker_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-worker-node.php)), not this plugin. Generic async-job dispatch — per-job try/catch isolation, the `gc_collect_cycles()`-after-every-job discipline, the object-cache flush cadence, and the memory-watermark self-restart — is runtime plumbing; see its row in the substrate's [`AGENTS.md`](https://github.com/Automattic/newspack-nodes/blob/main/AGENTS.md). Two things are THIS plugin's concern:

**Per-job request context.** The worker fires `newspack_nodes/job_worker/{before,after}_job` around each handler; the before-hook is a filter that can veto the job, and the after-action runs even on throw. ELN hooks `Log_Manager::begin_job_context_filter` / `end_job_context` onto them to suspend the request logger and stand up a synthetic `/jobs/{handler}/{id}` `$_SERVER` (plain `/jobs/{handler}` when the id is empty), so a job's own logging never bleeds into the request that enqueued it. An id that is itself an absolute URL — a cross-host job such as nuclear-gyrobase's `evtemplate`, addressed at a spoke — contributes its PATH alone, so `https://hub/Tools/UpdateSite.html` yields `/jobs/evtemplate/Tools/UpdateSite.html` rather than a URI nesting a second scheme and host, and an id naming no path collapses to the bare `/jobs/{handler}`. A rule pattern written for such a handler has to match that path-only form. Both are stack-based: `begin_job_context()` snapshots `$_SERVER` onto a LIFO before touching anything, so an unpaired or throwing begin still leaves `end_job_context()` a snapshot to restore. The same pair brackets the substrate's minute-cadence reconciliation (`newspack_nodes/before_reconcile` / `after_reconcile`), so spawn, backlog wake, lock reconcile, retention, orphan-IPC reaping and every `newspack_nodes/periodic` subscriber land in a `/jobs/newspack-nodes` request rather than bleeding into whatever WP-Cron request hosted them.

**Handler registration + `k`-routing.** The worker reads each `jobs.pN` entry's kind under `k` — `'job'` or `'remote_job'`, carried end-to-end (never `type`) — and dispatches against the matching filter: `k:"job"` → `newspack_nodes/job_handlers`, `k:"remote_job"` → `newspack_nodes/remote_job_handlers`. Registration is filter-only (no programmatic setters); a job type registers under whichever side(s) should handle it — see [Hub vs Spoke Topology](#hub-vs-spoke-topology).

### Remote_Job_Rewrite_Node

Hub-side fan-in is the self-sufficient substrate [`\Newspack_Nodes\Remote_Source_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-remote-source-node.php) — one per spoke/partition, operator-wired on the topology console. Each one patrons its own `SSE_In` + `HTTP_Out`, owns its offsetlog and reconnect/backoff, pulls `/messages/stream?subscribe=firehose.pN` with JSON `positions` resume, looks up its spoke credentials from the substrate **Vault**, and publishes the status snapshot the `aggregator` CI reads (see [`aggregator.tsl`](#topologiesaggregatortsl)).

The one application-specific piece is [`Remote_Job_Rewrite_Node`](../includes/class-remote-job-rewrite-node.php) — a pass-through transform the `aggregator` topology wires between the substrate `Remote_Source` sources and the firehose `Topic`. It flips aggregated `k:"job"` entries to `k:"remote_job"` so they dispatch centrally on the hub via `newspack_nodes/remote_job_handlers` rather than locally; non-`job` entries and non-array VALUEs pass through untouched.

```php
// includes/class-remote-job-rewrite-node.php
class Remote_Job_Rewrite_Node extends Node {
    public function fill( array $message ): void {
        $value = $message[ Message::VALUE ];
        if ( \is_array( $value ) && 'job' === ( $value['k'] ?? null ) ) {
            $value['k']                = 'remote_job';
            $message[ Message::VALUE ] = $value;
        }
        parent::fill( $message );
    }
}
```

Keeping the rewrite in a graph node keeps the substrate `Remote_Source` / `SSE_In` application-agnostic: the rewrite is a node the hub topology wires in, not a filter the substrate has to call. The rewrite grows the line by the 7-byte `job` → `remote_job` delta; the downstream `Topic` enforces its own per-message cap.

### Discovery_Collector_Node

Hub-side periodic discovery fan-out. [`Discovery_Collector_Node`](../includes/class-discovery-collector-node.php) is a [`Timer_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-timer-node.php) the `hub-control` topology mounts (`make_node Discovery_Collector discovery-collector 300`): `arguments()` arms `set_timer()` at the given interval, defaulting to 300s when the token is blank. On each `fire()` it walks its live targets, mints one signed `discovery.get` TM_COMMAND per spoke (addressed to that spoke's `discovery` CI), and skips — after asking the egress for a handshake — any target with no established session. Each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks` and `custom_events` (under `VALUE['payload']`), folded one reply at a time so out-of-order or partial replies converge. Remote strings are sanitized and both catalogs are capped at `MAX_EVENTS = 10000`.

**It stages, it does not instrument.** The merge never writes the ruleset; a shared `stage_discovered()` helper writes hooks into the non-autoloaded `discovered_hooks` option and custom events into `discovered_events`. A name one reply reports as a custom event never also stages as a hook from that reply, but two spokes that disagree about a name stage it in both. Those surface in the rules editor's hook picker for the operator to add — the editor is the only writer of rules.

### Stats production (owned by Flame_Builder_Node)

There is no separate stats Node. `Flame_Builder_Node` is the single stats producer, owning flame generation AND the memcache fan-out via its injected [`Stats_Store`](../includes/class-stats-store.php). The `flame-builder` topology (and therefore `performance` and `complete`) wires the store with `cmd flame-builder:config configure_stats <partition>`.

Each completed request is folded into `Flame_Builder_Node`'s `$pending` map across the same dimension set the reader paths whitelist — `DIM_FIELDS`, listed under [Flame_Builder_Node](#flame_builder_node). `$pending` holds one accumulator per 5-minute bucket, keyed by the bucket the request FINISHED in: `timestamp + duration_ms`, clamped to now. A record reaches the builder at completion, so filing it under its START would put a long request in a bucket that may already have closed, and a skewed spoke clock filing into a future bucket is the same written-then-unreadable failure. For an aborted request that completion is its abort moment, because `Request_Builder` sets `duration_ms = now - start` at eviction.

On flush, `Flame_Builder_Node` turns each pending bucket's accumulators into write intents — a key's parts, its bucket, and a merge closure that adds this worker's sums to whatever is stored and re-applies the namespace's cap where the schema sets one — and hands them to `flush_writes()`. That method chunks the intents at `WRITE_BATCH_KEYS` (500), reads a chunk with one `Stats_Store::bucket_get_multi()`, applies each intent's closure, writes the chunk back with one `bucket_set_multi()`, and calls an intent's `refused` callback for a key that did not land. Batching the writes is the same one-round-trip-per-batch discipline [decision 6](architecture-decisions.md#decision-6-get_multi-batching-is-essential) asks of the read paths. `flush()` then drops the accumulators it wrote; whatever has accumulated since rides `save_state()`'s checkpoint frame beside the read cursor, so a respawned worker resumes a half-accumulated bucket rather than losing it. Merging by addition is what lets several partitions write the same bucket without coordination; retention is the key's own TTL, so nothing prunes buckets by hand. A write into an hour this process has already folded goes to the coarse hour key instead of a fine bucket nothing would ever read again.

`Stats_Store` is storage-only; it does not own per-request aggregation logic. It carries two seams, a write and its read counterpart. It calls `$mirror` after each landed memcache write, so the durable `flame-stats` partition shadows the same data. It hands `$rehydrate` to its two BACKED Tables — the aggregate one and the fine `urls` one — so a read that misses falls through to the mirror and lands back in memcache without the reading caller knowing. The per-URL table takes the rotating accumulator instead and no backing at all: `flame_topn` is 0 unless `set_flame_topn` raises it, so backing that table would pay an index scan per cold URL for a frame nothing ever wrote. `Flame_Builder_Node::arm_stats_mirror()` arms both in the worker — from the store and the `set_stats_target` name, in whichever order they were configured — and folds the frames it is still holding in front of the partition read, since a bucket that has not closed is in no durable log. A dashboard request has no `Flame_Builder` to arm anything, so `Performance_CI_Node` calls the public `Flame_Builder_Node::arm_stats_reader()`, which arms `$rehydrate` alone from the `stats_mirror_node` config key. `mirror_partition()` gives that seam the live node where one exists and otherwise a detached handle over the directory `Bootstrap::node_dirs()` names for it, which is safe precisely because the seam is read-only: the write path resolves the live node alone and never falls back. Frames buffer until their bucket closes (see [`flame-builder.tsl`](#topologiesflame-buildertsl)).

When no `Stats_Store` is configured (`configure_stats` never called — e.g. in unit tests), the builder still emits flame data without touching memcache.

## Supporting classes

Not every application class is a Node. These carry the algorithms and the read-side vocabulary.

| Class | File | What it owns |
|---|---|---|
| `Flame_Tree` | [`includes/class-flame-tree.php`](../includes/class-flame-tree.php) | The pure flame-graph algorithm: stateless tree construction from one request's entries (LIFO span matching), duplicate-sibling numbering so aggregation does not collapse them, incremental sums-not-means merging across requests, and a finalize pass converting sums to averages and normalizing parent ≥ children. `MAX_STACK_DEPTH` and `MAX_RECURSION_DEPTH` are both 50; an aggregate child unseen for `AGGREGATE_EXPIRY_SEC` (3600) is dropped. Each span carries `t`, its start in ms from the request's own, which the browser's `withTimeSpacers` pass turns into a position on the x-axis |
| `Flame_Fold` | [`includes/class-flame-fold.php`](../includes/class-flame-fold.php) | The merging, RESUMABLE variant of that stack machine — static over a plain-array state, because an in-flight envelope rides the Consumer's checkpoint frame and an object would not come back. Same-name siblings under a parent collapse into one node with `count` / summed `value` / `max`, so a folded envelope costs O(distinct paths) rather than O(messages). A collapsed node ships `merged`, which is what stops the browser laying it out from a `t` that belongs to only the earliest of the spans inside it |
| `Findings` | [`includes/app/class-findings.php`](../includes/app/class-findings.php) | What is wrong with one stored record, COMPUTED rather than inferred: a fatal, "insufficient instrumentation" as a first-class finding, unattributed time (`UNATTRIBUTED_SHARE` 0.5), dominant span (`DOMINANT_SHARE` 0.6), repetition (`REPETITION_COUNT` 50), entry gap (`GAP_MS` 250) and truncation, returned worst-first. The fatal is the one finding that needs no arithmetic and carries no proposal — PHP knew the message, file, line and offending plugin at the moment it stopped, and no rule edit fixes a crash. `MIN_DURATION_MS` (50.0) floors both share-based tests, which is the whole explanation for a fast request answering with nothing: 90% of 3ms is not a finding. Every finding names where it was measured and what rule edit would act on it, and the instrumentation proposal names that edit concretely — `LIFECYCLE_BRACKET`, the six hooks `plugins_loaded`, `init`, `wp_loaded`, `template_redirect`, `wp_head` and `shutdown` — a coarse bisect whose next round subdivides only the phase that held the time, which is what keeps a proposal from being forty hooks. A proposal's `direction` is as often `more` as `less`. `for_url()` is the cold start rather than a scaled-down `for_request()`: handed a URL's aggregate stats it runs none of the seven record-level detectors, answering `[]` when the governing rule already registers hooks and otherwise the single `insufficient_instrumentation` finding, whose proposal is the lifecycle bracket or a rule to create. A plain class, not a Node: a pure function of a record, so the picker and an agent produce identical evidence |
| `Ask_Assembler` | [`includes/app/class-ask-assembler.php`](../includes/app/class-ask-assembler.php) | The `?` picker's descriptor vocabulary — `url:`, `request:`, `span:`, `entry:`, `category:` — shaped into a brief, bounded by `MAX_ENTRIES` 60, `NEIGHBOURS` 2, `WORST_REQUESTS` 5 and `TOP_SPANS` 6. Shaping only; loading belongs to `Performance_CI_Node`. URLs go through `Log_Manager::redact_url()`, the environment is allowlisted rather than filtered, and `Findings::caveat()` rides every brief |
| `Reqgrep_Core` | [`includes/class-reqgrep-core.php`](../includes/class-reqgrep-core.php) | The rid-grouping / pattern-matching engine `wp nodes reqgrep` and the `performance` CI's `grep_requests` verb share, so both agree byte-for-byte on which lines belong to which request and when it is complete. Bounds one request at `MAX_BYTES_PER_REQUEST` (10 MiB) and `MAX_LINES_PER_REQUEST` (20000), or 10000 lines while it is only history |
| `Hook_Categorizer` | [`includes/class-hook-categorizer.php`](../includes/class-hook-categorizer.php) | Hook-to-category mapping from `hook_categories.json` plus operator overrides, the category colours the dashboards render, and `is_internal()` — the list `App\Core` refuses to instrument, because binding one re-enters the `Log_Manager` bootstrap |
| `Diagnostics_Bridge` | [`includes/class-diagnostics-bridge.php`](../includes/class-diagnostics-bridge.php) | One static listener on `newspack_nodes/stderr`. Every line the substrate emits through `Core::_stderr()` is logged to the ACTIVE request or job context as a `stderr` entry, which `Request_Builder_Node` routes to its errors target and therefore into the Error Log. With no started logger the line is dropped, since the substrate's default handler already `error_log()`s it. Fleet alerts do not come through here — the substrate journals those into `alerts.p0` |
| `Current_Request_Overlay` | [`includes/class-current-request-overlay.php`](../includes/class-current-request-overlay.php) | Registers the `current-request` bundle on the substrate's `newspack_nodes/station_tab_bundles` filter for the station, enqueues it itself on every overlay page — its own four dashboards that mount `<DebugOverlay>`, unioned with the slugs other plugins contribute through the substrate's `overlay_pages` registry — and injects THIS request's id into a JS global at `admin_enqueue_scripts` priority 20. The settings tree mounts no overlay, so its page is absent. The descriptor deliberately carries no `lazy` flag, the substrate's knob for shipping a station tab on first click instead: `enqueue_inline_data()` no-ops unless `wp_script_is( HANDLE, 'enqueued' )` already answers true at priority 20, so marking the bundle lazy — which reads as a free size win, and is how the substrate's own tabs ship — mounts the tab with no `currentRequest` global and strands it on its empty state with nothing logged |
| `Admin\Admin` | [`includes/admin/class-admin.php`](../includes/admin/class-admin.php) | The application settings page under Settings, derived entirely from `Settings_Schema`: the `register_setting` / `add_settings_field` loops, the three rendered checkboxes, the reset list — "Reset to Defaults" deletes the options of `ui: true` Fields alone, so the three checkboxes go and the six overlay-only keys stay, a saved ruleset among them — the `pre_update_option` filter that DELETES a row rather than storing the file default in it (presence decides the overlay, so a stored default would pin that value past a later config edit), and per-option worker-restart classification on `added_option` / `updated_option` through the substrate's `Restart_Planner`. `\Newspack_Nodes\Capabilities::can( MANAGE )` is the one gate both this page and the dashboard menu ask, and `cap_for( MANAGE )` is the capability each menu registers under — so a site that ran `wp nodes caps install` admits a holder of `newspack_nodes_manage`, and the substrate's `allowed_users` list narrows it from inside `can()`. It renders the ruleset editor's mount div and never writes a rule itself |
| `uninstall-cleanup.php` | [`includes/uninstall-cleanup.php`](../includes/uninstall-cleanup.php) | The uninstall sweep `uninstall.php` requires by hand, since the autoloader is gone by then. It deletes every option under the `newspack_event_logger_nodes_` prefix on every site of a multisite — selected BY PREFIX, so the ruleset's non-autoloaded `rule_hooks_*` rows and the two transient stubs go too. The on-disk tree belongs to the substrate's uninstall, and memcache stats expire on their own TTLs |

## Memcache Schema

Per-key prefix: `evlog:p{N}:{namespace}:…`, written inside the install scope the substrate's [`Cache_Backend`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-cache-backend.php) supplies. That scope is where the salt lives — [`Stats_Store`](../includes/class-stats-store.php) keeps none of its own, so one rotation orphans every Newspack plugin's keys at once.

The retention window comes from the substrate's `min_lifetime` (default 43200), floored at 3600.

| Namespace | Use | TTL |
|-----------|-----|-----|
| `hourly` | 5-min buckets, keyed per bucket, count + sum_ms + sum_peak_mb | `min_lifetime` (default 43200) |
| `lb` | 5-min global leaderboard buckets, sums-not-means | `min_lifetime` |
| `lb_h` | the GLOBAL leaderboard's coarse tier, `lb_h:{Y-m-d-H}`, folded by the same `roll_up_hours()` pass that folds `urls_h` and holding the same shape a fine bucket holds, so ONE `build_leaderboard()` fold serves both tiers. This is the heaviest read the dashboard makes — a category per hook, callback and plugin the site fires, 1,198 of them on a production hub, each with its own entry map, across 288 buckets and four partitions — so it takes [decision 17](architecture-decisions.md#decision-17-the-url-index-is-stored-at-two-resolutions-and-the-coarse-one-is-derived)'s two resolutions for [decision 17](architecture-decisions.md#decision-17-the-url-index-is-stored-at-two-resolutions-and-the-coarse-one-is-derived)'s reason. An hour the coarse tier cannot answer for has not been folded yet, and only the GRACE hour — the one immediately behind the fine tail — is answered from its fine buckets | `min_lifetime` |
| `lb_s` | per-server leaderboard, keyed by server. NO coarse tier: a shard count is a constant this schema chooses, but the servers present in an hour cannot be enumerated from the keyspace, so a server-scoped board still walks the window | `min_lifetime` |
| `urls` | 5-min URL index, SHARDED `urls:{shard}:{bucket}` by the first hex digit of the url_hash — and by POPULATION, a `w` prefix on the token (`urls:w3:...`) naming worker traffic, which the table excludes by default. Held only for `Stats_Store::FINE_TTL_SECONDS` (2h), against `min_lifetime` for the coarse tier that outlives it: the read plan asks for `FINE_BUCKETS` (13) plus the rest of their hour — just under two at the worst minute — and `urls_h` answers for everything behind that. The tier has exactly two consumers, `RECENT_BUCKETS`' last-hour rate and the fold, and two hours covers both; at that width it costs 24 buckets a shard, as many keys as the coarse tier holds at a 24-hour retention and twice its twelve at the default. Keyed by url_hash to a POSITIONAL row indexed by `Stats_Store::ROW_*`. `serialize()` writes every key NAME into every row, so the shape `Message` uses is the shape this takes: measured on live rows at 672 bytes a row named against 398 positional. The eight fields that ADD occupy indexes 0-7 in `URL_SRV_SUMS` order, so one map describes the row's summed half AND its per-server `srv` split, whose values take the same indexes. `ROW_FIELD_NAMES` is the one index-to-name map; `Performance_CI_Node::fold_index_row()` is the one place a stored row becomes the named display row. The row carries NO URL string — the name lives once in `urlmap` and readers resolve only the hashes they show. A split of ONE server whose count is the row's own stores the host name against `null` (`Stats_Store::collapse_sole_server()`), the row restated in ~33 bytes rather than ~112; readers expand it before merging | `min( min_lifetime, 7200 )` |
| `urlmap` | URL name table, `urlmap:{hash}` -> `[ path, origin ]`. The name is 101 bytes of a 166-byte minimal row, so keeping it on the row would put one copy in every bucket the URL appears in — up to 288 copies of one name across a retention window. Here it is written once per URL and re-written only once half its own TTL has passed. It answers about ONE hash, which is what a returned page and a point read want; a SEARCH asks about a whole shard and reads `urlnames` instead. `Performance_CI_Node::resolve_urls()` is the one place a row gets its DISPLAY name, joining the two halves back | `min_lifetime` |
| `urlnames` | The searchable name index, `urlnames:{shard}:{bucket}` -> `{ hash => path }` — the same shard, the same two tiers and the same `read_plan()` as `urls`, so a search is ~40 keys per shard however many URLs it holds. Answering it through `urlmap` costs one key per URL, and on a hub that is 668,918 of them a poll: measured at 200-290s and 350-510MB per `urls` request, which the browser gave up on. PATHS only. The origin is already the `ROW_SRV` split's KEY and already the `server` dimension the picker is built from, so storing it a third time would also make one search box ask the dropdown's question. UNCAPPED, because every URL must be searchable and a cap drops exactly the URL being looked for. Not bounded by `MAX_URLS_PER_SHARD` either — that runs inside the ROW intent's merge, after this map is built — so the backstop is `url_name_intent()`'s refusal log, the same one the rows carry | `min( min_lifetime, 7200 )` |
| `urlnames_h` | The name index's COARSE tier, `urlnames_h:{shard}:{Y-m-d-H}`, folded by the same `roll_up_hours()` pass in the same `bucket_set_multi()` as `urls_h`. The two tiers must agree about which hours they answer for: a folded hour's fine buckets are never read again, so a name left behind in them is a URL the search can no longer find. A union, not a sum — a name never changes | `min_lifetime` |
| `url` | per-URL flame/profile blob | `max(3600, min_lifetime/24)` |
| `dim` | dimensional time series, keyed per bucket, one namespace per dimension and `$server` scopes it (`Stats_Store::dim_parts()`) | `min_lifetime` |
| `url_dim` | per-URL dimensional series, keyed per bucket, every dimension in the value | `min_lifetime` |
| `categories` | category time series, keyed per bucket, `$server` scopes it (`Stats_Store::cat_parts()`). Each category in a bucket is a POSITIONAL `CAT_SUMS` triple ([decision 18](architecture-decisions.md#decision-18-a-stored-value-may-be-positional-and-then-its-indexes-are-named-constants)), indexed by `Stats_Store::CAT_MS`, `CAT_CALLS` and `CAT_REQUESTS` — milliseconds of wall time rounded to `CAT_MS_DECIMALS` places, events fired, requests the category appeared in — which is NOT the still-named `{ c, s, m }` of the `dim` namespaces beside it, where `c` counts requests rather than events. The bucket also carries a synthetic `total` category holding the request's own wall time, which every ranking has to hold out — `Flame_Builder_Node::cap_bucket()` does it before the cap and `CategoryTimeChart` before the palette, since as a band it swamps every real category | `min_lifetime` |
| `url_cat` | per-URL category series, keyed per bucket, in the same positional `CAT_SUMS` shape | `min_lifetime` |
| `urls_h` | the URL index's COARSE tier, `urls_h:{shard}:{Y-m-d-H}` — one hour of merged rows in the same positional shape a `urls` bucket holds. DERIVED: `Flame_Builder_Node::roll_up_hours()` folds a closed hour once. Only the GRACE hour, the newest the read plan takes coarse, is answered from its twelve `urls` buckets when its key is missing; an older missing hour reads as empty. Not mirrored, for the same reason | `min_lifetime` |

![The memcache key layout: an install scope the substrate's salt lives in, then evlog, the partition p{N}, one of fourteen namespaces, the scope parts and the bucket LAST; a table of every namespace's key shape, value and TTL; a timeline of what one URL-index read asks for at the default 12-hour retention, eleven coarse urls_h hour keys behind thirteen fine urls buckets and the rest of their hour; and the URL row's fourteen positional indexes, the eight that add, the two extremes, and the per-server srv split.](img/ag-memcache-keys.png)

Every scope (server, URL, dimension) is a pure key prefix, so one `lookup_multi` serves them all through `Stats_Store::lookup_buckets()`, retention is the key's own TTL rather than a hand-rolled cutoff pass, and `Stats_Store::is_open_bucket()` decides from the key alone whether its bucket is still open.

**Caps prevent value-explosion** against memcache's 1MB/value limit:

- `MAX_DIM_VALUES = 20` — the `server` axis takes `MAX_SERVER_VALUES = 128`
  instead (`Stats_Store::dim_cap()`): `server_name` is `SERVER_NAME`, which under
  Apache's default `UseCanonicalName Off` is the client's Host header, so it needs
  a ceiling. It sits far above `MAX_DIM_VALUES` because 20 folded real spokes out
  of a 24-spoke fleet's own picker. See architecture [decision 14](architecture-decisions.md#decision-14-a-server-scope-the-key-cannot-carry-rides-inside-the-value-and-is-applied-as-a-projection) for when to raise
  it
- `MAX_URL_DIM_VALUES = 10`
- `MAX_CAT_VALUES = 50`
- `Flame_Builder_Node::MAX_URLS_PER_SHARD = 2000` — sixteen shards, so a bucket's
  ceiling is 32,000 URLs, sized against a 1MB `item_size_max` as the diagram shows

`srv` holds one window-merged scalar per server, co-located with the row so one `lookup_multi` scopes the whole index instead of one get per URL. It is NOT `url_dim`'s `server` axis, which is a per-bucket `{c,s,m}` time series; neither can produce the other ([decision 14](architecture-decisions.md#decision-14-a-server-scope-the-key-cannot-carry-rides-inside-the-value-and-is-applied-as-a-projection)). `URL_SRV_SUMS` and `sum_entry()` only ADD, so a server-scoped row keeps the URL's own `min_ms` and `max_ms` across every server, and `Stats_Store::swap_url_server_sums()`, the projection, is also where the split's indexes become names, for that one server, since no reader ever displays the split itself.

Overflow rolls into a synthetic `Other` value rather than dropping, so a total summed from a capped namespace is still exact. The URL index folds its `MAX_URLS_PER_SHARD` tail the same way, into TWO overflow rows (`Stats_Store::other_key()`): `Other` and `Other:worker`, one per population, so the two shard families never fold their tails into each other. `Flame_Builder_Node::cap_bucket()` holds the `total` pseudo-category out of the ranking sort and puts it back afterwards, so a wide bucket can never fold the total into `Other`.

Those two rows are the one thing on the leaderboard a reader cannot open. `Performance_CI_Node` stamps each row `aggregate` from `Stats_Store::is_other_key()`, and [`UrlTable`](../src/overview/UrlTable.js) draws such a row with the fixed label "traffic from URLs beyond the per-shard cap" in place of a URL and none of the row interactions: its key is no url_hash, so there is nothing for `dump_url` to answer about and no `url:` descriptor for the Ask picker to stamp.

One cap does NOT fold, and it is a payload bound rather than a storage one. `Stats_Store::sums_to_display()` divides a category's summed entries into per-appearance averages at read time, and past a hundred entries it keeps the fifty slowest by average exclusive time and discards the rest; an entry with no samples is dropped rather than shown as zero. Nothing is lost from memcache, but a leaderboard modal missing an entry has no `Other` row to look for it in.

**`get_multi` batching is essential.** Reader paths multi-get across the retention buckets in one round-trip (`Stats_Store::lookup_buckets()`) rather than one `get` per key, which is a latency cliff. The URL index is chunked on top of that: [`Performance_CI_Node::load_index_default()`](../includes/app/class-performance-ci-node.php) folds `INDEX_READ_CHUNK` buckets (12, an hour of fine ones) at a time and drops each chunk before reading the next, so hundreds of MB of rows are never resident beside the index being built. The shared `Core::$memd` (`\Memcached`) provides `getMulti` natively; the in-memory test double mirrors it.

## Stats_Store: Sums-Not-Means + Salt Rotation

**Sums-not-means storage**: leaderboard buckets hold raw `count`, `sum_req_time`, `samples`, `sum_time`, `sum_count`. Cross-bucket and cross-partition merge is exact addition. The display layer computes means at read time.

Do NOT regress to incremental averages: they look mathematically equivalent but break under merge. Nothing stores a percentile for the same reason — a percentile does not merge, so a fold over N buckets cannot produce the window's. `min_ms` and `max_ms` stay and stay exact, because they fold from `duration_ms` directly.

**Schema migration IS salt rotation, and it lives in the substrate.** [`\Newspack_Nodes\Cache_Backend::rotate_salt()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-cache-backend.php) writes a fresh salt into `newspack_nodes_cache_salt`, which moves the install scope every Newspack plugin's keys hang from. Old keys orphan instantly and expire on their own TTL: no scrubber walk, no large memcache scan. Plugins deliberately keep no salt of their own — every plugin reading `Cache_Backend` shares this one, so a rotation orphans them together instead of leaving the ones nobody flushed serving stale values.

```bash
wp nodes memcache flush     # rotate the salt, then restart the workers
```

Nothing in the code compensates for skipping it: no reader or writer carries a shape probe, a version component or a row-level legacy test. The durable stats mirror in `flame-stats.p{N}` is the one tier the rotation does not reach, on purpose, so its key carries `Stats_Store::MIRROR_KEY_VERSION` instead, bumped on a frame-shape change. The scope is memoized per process, so a long-running worker keeps writing the OLD prefix until it respawns — which is why the CLI restarts workers after rotating (best-effort, warning on failure), and why the admin "Flush Caches" button does the same. Skip the rotation and the dashboard reads garbage for one retention window; that is an operator error with a one-command fix. The rotation also takes every issued session, so reissue any you were using.

**Memcache failure asymmetry**, deliberate on both sides. Do not unify them:

- **Stats path: fail-SOFT.** `Stats_Store` never throws when memcache is unreachable: a read answers `null` / `[]` / `false`, so a dashboard shows "no data" instead of erroring.
- **SSE slot path: fail-CLOSED.** The substrate's [`SSE_Slot_Pool`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-sse-slot-pool.php) answers a new connection with HTTP 429 when the identity holds its share, every slot is live, or no store answers; [SSE host budget](https://github.com/Automattic/newspack-nodes/blob/main/docs/sse-host-budget.md#what-the-client-sees-on-a-refusal-or-a-lost-slot) has the cases. Triage a burst of 429s as "is memcached up?" before "is the pool full?". Losing the pool cannot fall through silently, because the pool IS the rate limit.

## Hub vs Spoke Topology

Aggregation runs hub-and-spoke across several WordPress sites, each running this plugin.

![Hub and spoke: three spokes, each writing its own firehose.pN and dispatching its own k:"job" entries locally; one Remote_Source per spoke and partition on the hub, pulling over SSE with set_multi_writer on and a topology-scoped offsetlog; the Remote_Job_Rewrite_Node flipping k:"job" to k:"remote_job"; the hub's multi-partition Topic KEY-routing into its own firehose.pN; and the hub's complete workers dispatching remote_job against remote_job_handlers. Beneath, a table of where a handler runs when it registers under job_handlers, remote_job_handlers or both, and how hub-mode is derived from topology membership.](img/ag-hub-spoke.png)

**Hub identification.** [`Config::resolve_eln_token( 'is_hub' )`](../includes/class-config.php) answers on two signals because neither alone covers both shapes of hub. Matching the name alone would read a renamed fork as a spoke and turn its per-server stats off; matching a wired reader alone would miss the stock `aggregator`, which ships none. Settings and discovery fan-out additionally want `hub-control`. The re-entrancy guard exists because a topology naming `<eln:is_hub>` in a `set_*_target` line resolves its tokens through the same walk, which would otherwise recurse until PHP died.

**`k:"job"` vs `k:"remote_job"`.** Every site writes `k:"job"` to its own firehose; nothing distinguishes a spoke from a hub at write time. The distinction emerges at dispatch, and a job type registers under whichever filter should run it — both, where a job needs local and centralized handling under one name, and then the two implementations may differ (one filtering by local attributes, the other dispatching differently). The rewrite is NOT in a spoke's graph, so a spoke's own `k:"job"` entries dispatch locally, exactly as spokes want.

## Hub-Side Settings Sync, Discovery, and Vault

The hub's outbound side has three parts: spoke credentials in the substrate [`Vault`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-vault.php), managed through the substrate `vault` CI; the settings fan-out the `hub-control` topology runs through the substrate [`Settings_Sync_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-settings-sync-node.php); and [`Discovery_Collector_Node`](#discovery_collector_node), in the same topology.

![hub-control's two fan-outs side by side. Settings out: an admin edit, an auto-tune decision or a performance set writes a newspack_ option; Settings_Event_Writer records the option name to settings.p0, with value excerpts only for allowlisted options; Settings_Sync reads the current value at consume time and on its 300-second tick, through ELN's value filter; one signed set per registered option goes to each live spoke, the substrate's keys to its settings CI and ELN's three to its performance CI, which accepts the three SETTINGS_OPTIONS names and routes the ruleset to apply_synced(). Discovery in: Discovery_Collector fires every 300 seconds, mints one signed discovery.get per live spoke, and union-merges each reply into the staging options discovered_hooks and discovered_events, capped at 10,000. Two cards state that the fan-out is ungated but still authorized, and that credentials are the substrate Vault's.](img/ag-hub-control.png)

A `Remote_Source` node references its spoke by `<vault-id>`, and the reload channel carries a credential change to whichever worker holds it without waiting out that worker's ~10-minute respawn. ELN's settings-sync resolver is `newspack_event_logger_nodes_resolve_settings_sync_value`; it resolves `newspack_nodes_*` keys against the substrate's defaults because their `remote_*` geometry differs from the hub's, and every other key against this plugin's.

## Settings Sync: No Operator Gate

The fan-out above is **ungated** in the structural sense: a watched option change always records a settings event, and nothing fans it out unless `hub-control` is active and per-spoke `HTTP_Out` nodes are wired. On a spoke or standalone site there is no consumer, so the event is tailed and dropped. Letting that drop happen at the node-graph level is cheaper and harder to misconfigure than a per-listener `get_option` gate. Watched means `newspack_`-prefixed: `Settings_Event_Writer::maybe_emit()` returns on the name of any other option, a consumer plugin's own differently-prefixed setting included, so that change records nothing at all.

Recording the option NAME and resolving its value at consume time is what makes the ungated shape safe: a burst of writes collapses to one current-value push, and no stale value can race a fresher one onto a spoke. An auto-tune decision travels the same road (see [Auto_Tuner_Node](#auto_tuner_node)).

Ungated is not unauthorized. `Auto_Tuner_Node::authorized()` still requires a worker context or the TUNE capability (`manage_options` until `wp nodes caps install` moves it) before it will mutate a rule. The structural gate governs *where the change propagates*; the capability check governs *who may make it*.

## Job ingress and routing

`Job_Intake` lives in the newspack-nodes substrate as [`\Newspack_Nodes\Job_Intake`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-intake.php). Substrate-only installs drain it via the stock `job-intake` topology, which ELN's [`job-router.tsl`](../topologies/job-router.tsl) and [`job-feed.tsl`](../topologies/job-feed.tsl) both `include` and re-route. Activating the stock topology beside either is refused by the conflict gate, since all three write `jobs.pN` under `void_warranty`.

![Job ingress: three producers inside a request, Log_Manager's k:"job" line, Job_Intake::feed() and Job_Intake::queue(), writing firehose.pN, jobfeed.pN and jobintake.pN, with a future not_before parking in jobdelay.p0 until Job_Delay::sweep() re-writes it; Job_Router_Node normalizing every body onto { k, handler, parameters, ts } plus id and the dispatch fields; the Age_Sieve dropping an envelope older than 900 seconds counted from enqueue; and the substrate's Job_Worker dispatching jobs.pN by kind. Four cards give the three partition-selection modes, the write_job() options, the per-partition lock, and which rejections warn.](img/ag-job-ingress.png)

Two questions pick the producer: does the payload fit in PIPE_BUF, and must the job reach a hub? Only firehose entries cross a spoke's `Remote_Source` pull, so a job the hub must aggregate rides the firehose (`Log_Manager->message( 'job', $payload )`) and stays under 4KB. A local job that fits takes `Job_Intake::feed( $handler, $id, $parameters )`, which costs no lock and lets the site run `job-feed` instead of `job-router`. A local job over 4KB — serialized option blobs, image-handler args, large arrays — takes `Job_Intake::queue( $handler, $id, $parameters )`; neither intake log aggregates.

Using the wrong path loses jobs. `Log_Manager` trims `m` on any entry over `MAX_DATA_SIZE` (3840B), so an oversized firehose job arrives at the router carrying no handler and reads there as an invalid-handler warning.

## Configuration

Four layers, weakest first — later wins:

1. **Schema defaults**, declared in code by [`Settings_Schema`](../includes/class-settings-schema.php), one per `Field`.
2. **The shipped config file**, [`newspack-event-logger-nodes-config.php`](../newspack-event-logger-nodes-config.php). A commented ledger returning `[]`, each of the nine keys listed beside the default the schema declares. It is an OVERRIDE surface, not the definition, and a deploy copies the operator's own copy over this path. A key the schema does not declare is reported to stderr and ignored — never registered, never thrown, because the profiler drop-in reads config at `plugins_loaded:-10001` and a throw there would take wp-admin down with everything else. Report-never-throw covers the KEY alone: both files go through [`Newspack_Nodes\Config_Utils::load_config_file()`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-config-utils.php), which throws at that same hook when the file returns anything but a tree of scalars, nulls and arrays, or nests arrays more than ten deep. A file yielding no usable tree is the one case the rule does not reach.
3. **`LOCAL_NEWSPACK_NODES_CONF`**, a second file named by that environment variable and canonical-path-validated before it is required. An unusable path throws rather than silently leaving the site on defaults.
4. **WordPress options** under the `newspack_event_logger_nodes_*` prefix (application) and `newspack_nodes_*` prefix (substrate). Each key follows its owning plugin: ELN applies its option overlay to ELN-owned keys, while effective substrate values arrive after the substrate applies its own overlay. The overlay is presence-based, so a stored `''` / `[]` / `false` / `0` beats both files.

```php
$config = \Newspack_Event_Logger_Nodes\Config::load_config();          // every schema key, files + WP option overlay + substrate merge
$config = \Newspack_Event_Logger_Nodes\Config::load_config_defaults(); // files only (no WP option overlay, no substrate merge)
$value  = \Newspack_Event_Logger_Nodes\Config::value( 'log_memory' );  // fail-loud single-key read
```

`load_config()` is the single zero-arg entry point — every key in the `Settings_Schema` whitelist is loaded on every call, including the `rules` overlay key and the substrate keys merged in from the substrate's own `Config::load_config()`. The result is memoized for the process, so the cost is one round of `get_option` per schema key on first call; `Config::reset()` (or the substrate's `newspack_nodes/config_reset` action, which `reset_local_cache()` listens on) clears it. It returns an UNMEMOIZED empty array when the substrate is absent, because this plugin loads first and a caller reading too early must get the real map on its next read.

**Read single keys through `Config::value()`.** It throws on a key no registered schema declares, so a renamed or typo'd key fails at the boundary instead of limping on a `?? default`. `Config::register_config_keys()` publishes this plugin's overlay keys to the shared substrate registry, hooked to `newspack_nodes/declare_config_keys` so the substrate re-pulls them after every `Config::reset()`.

### Settings_Schema: one Field per setting

`Settings_Schema` ([`includes/class-settings-schema.php`](../includes/class-settings-schema.php)) is the single declaration both `Config` and `Admin` derive from — one [`\Newspack_Nodes\Config_System\Field`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/config-system/class-field.php) per setting, collected into a `Schema`. `Config` reads it for the overlay key-list; `Admin` reads it to drive the `register_setting` / `add_settings_field` loops, the reset list, and the delete-on-blank classification. Labels and section titles are lazy `fn(): string` thunks, so building the Schema for `overlay_keys()` on a frontend request never calls a translation function.

Substrate keys (`base_directory`, partitioning, `memcache_servers`, `topologies`, and the `remote_*` spoke geometry) are owned by the substrate's own Settings_Schema under `newspack_nodes_*`; ELN imports their effective values only after removing ELN-owned names, so each plugin's option namespace remains authoritative. Substrate keys are never declared here. Spoke credentials live in the substrate **Vault**.

### Application option keys

The ten schema keys all take an option overlay under `newspack_event_logger_nodes_`. Three render as checkboxes on the settings page; the rest are `ui: false`, pinned from a config file or written by a service CI.

| Option | Type | Surface | Default | Use |
|--------|------|---------|---------|-----|
| `enable_logging` | bool | checkbox | `true` | Master switch for the firehose write path. Off, the workers keep running — this gates the request-side writer, not the topologies |
| `log_memory` | bool | checkbox | `false` | Append `peak_mb` to every `(complete)` and `(aborted)` entry |
| `flush_every_line` | bool | checkbox | `false` | Flush the firehose Topic after every entry — survives a crash, costs one write per entry |
| `rules` | array | `ui:false`, written by the `rules` CI | five baseline skips + a `/` log | The per-URL logging **ruleset** — see [Per-URL logging ruleset](#per-url-logging-ruleset). Seeds `Rule_Set` until the editor writes the option |
| `hook_start_priority` | int | `ui:false` | `-10000` | Priority `App\Core` binds `hook_start` at. `hook_complete` is always `PHP_INT_MAX - 1`, so a lower number widens the measured span |
| `stats_mirror_node` | string | `ui:false` | `flame-stats:partition` | Node name of the durable stats-mirror partition; a memcache miss reads the frame back from it. `''` disables the mirror |
| `stats_mirror_read_budget_ms` | int | `ui:false` | `1500` | Milliseconds ONE dashboard verb may spend reading that mirror. `locate_by()` has no early stop for an absent key, so each batch that misses costs a full pass over the mirror's index; past the budget the verb answers from memcache alone. `0` turns the reader's mirror read off. The worker's own read is never budgeted |
| `custom_colors` | array | `ui:false` | `[]` | Custom-event name → hex swatch, filtered through `newspack_event_logger_nodes_custom_colors` and merged with the events spokes reported. Hook-category colours are a different thing entirely — they come from `hook_categories.json` |
| `recommended_log_events` | array of strings | `ui:false` | curated list | Hook names the settings picker stars; its "Recommended" button REPLACES the current selection with them. A menu, not an instruction — nothing here binds anything |

Three further options sit outside the schema entirely. `discovered_hooks` and `discovered_events` are non-autoloaded staging options `Discovery_Collector_Node` writes and the editor's hook picker reads; `load_config()` never touches them, and `Config::autoload_for()` is the single place that says so — their values grow with the fleet, so keeping them out of `alloptions` is what stops every frontend request paying for them.

`newspack_event_logger_nodes_hook_customizations` (`Hook_Categorizer::OPTION_NAME`) is the third, and it is operator-only: nothing in the plugin writes it, and a site that wants categories, colours, descriptions, patterns or per-hook assignments of its own stores that row by hand. `get_user_customizations()` fills it out to four keys — `patterns`, `overrides`, `colors`, `descriptions` — and `get_merged_config()` merges it over [`hook_categories.json`](../hook_categories.json) for the taxonomy every dashboard and the settings picker render from. A user colour or description WINS; a user pattern APPENDS to its category and displaces nothing, so a base pattern in an earlier category still beats a user pattern in a later one and `overrides` is the way to pin one hook. The prefix uninstall sweeps it like every other row. An operator pattern is untrusted input, so `categorize()` skips one longer than `MAX_PATTERN_LENGTH` (100) whole rather than truncating it — half a regex is not the operator's intent — rejects nested quantifiers with a rate-limited notice, and lowers `pcre.backtrack_limit` to 10000 while the scan runs.

`enable_logging`, `log_memory` and `flush_every_line` all carry `restart: 'all'` — `Log_Manager` caches each in its per-process singleton, so a change needs every worker restarted before it takes effect.

The remote-pull segment geometry (`remote_segment_size` / `remote_min_segments` / `remote_num_segments` / `remote_min_lifetime` / `remote_lifetime` / `remote_max_segments`) is the substrate's too, under `newspack_nodes_*`, and `hub-control` pushes each axis to every spoke.

### Substrate option keys (read but not owned here)

`Config::load_config()` also reads the substrate's `newspack_nodes_*` namespace for keys that affect application behaviour:

| Substrate option | Use here |
|------------------|----------|
| `base_directory` | Root for `logs/`, `offsets/`, `locks/`, `ipc/` |
| `num_partitions` | Topic / Partition fan-out, read as `Bootstrap::global_num_partitions()` on the write side and the read side alike. A topology's own `var num_partitions` is its WORKER count and governs nothing else — `hub-control` pins 1 |
| `segment_size`, `min_segments`, `num_segments`, `max_segments` | Partition geometry and count-based retention |
| `min_lifetime`, `lifetime` | Age-based retention; `min_lifetime` also sets the `Stats_Store` TTL window |
| `memcache_servers` | Stats_Store + SSE slot pool backend |
| `sse_slot_ttl`, `sse_max_streams`, `sse_max_slots`, `sse_reserved_slots` | The SSE slot-pool budget |

Per-key documentation lives in the substrate; this plugin treats them as read-only.

### Hot reload

Most config keys read through `Config::load_config()`, whose static cache lasts one process. A frontend request therefore picks a change up on its next request; a long-running worker does not. Restart is required for:

- `enable_logging`, `log_memory`, `flush_every_line` — cached in `Log_Manager`'s per-process singleton (`restart: 'all'`).
- `num_partitions` — wired into Topic/Partition construction at worker bootstrap; changing it requires `wp nodes restart` of all topology workers.
- `memcache_servers` — the shared `Core::$memd` holds the one connection, built once at boot.
- Salt rotation — each worker memoizes the install scope at boot, which is why `wp nodes memcache flush` restarts them.

## Hooks and filters

**Fired by this plugin:**

| Hook | Type | Fired where |
|---|---|---|
| `newspack_event_logger_nodes_scope_changed` | action | Both ends of a job context (`begin_job_context` / `end_job_context`); `App\Core::rebind_for_current_scope()` listens |
| `newspack_event_logger_nodes/settings_after_form` | action | Below the settings form; the rules editor mounts at priority 5, then the effective-config and maintenance sections |
| `newspack_event_logger_nodes_custom_colors` | filter | `Config::get_custom_colors()`, applied lazily so a plugin loading after this one can still register its events |

**Consumed from the substrate:**

| Hook | Callback |
|---|---|
| `newspack_nodes/declare_config_keys` | `Config::register_config_keys` — registered at file load, ahead of the deferred bootstrap |
| `newspack_nodes/config_reset` | `Config::reset_local_cache` (not `reset()`, which would re-enter the substrate) |
| `newspack_nodes/job_worker/before_job` (filter) | `Log_Manager::begin_job_context_filter` |
| `newspack_nodes/job_worker/after_job` (action) | `Log_Manager::end_job_context` |
| `newspack_nodes/before_reconcile` / `after_reconcile` | An anonymous pair sharing an `$entered` flag, so an unmatched `after` never pops a snapshot it did not push |
| `newspack_nodes/settings_sync/value` (filter) | `newspack_event_logger_nodes_resolve_settings_sync_value` |
| `newspack_nodes/registered_log_producers` (filter) | `newspack_event_logger_nodes_register_log_producers` — adds the firehose dir template, so the log GC declares and the Workers dashboard catalogs the dirs `Log_Manager` writes with no topology Partition behind them |
| `newspack_nodes/stderr` | `Diagnostics_Bridge::on_stderr` |
| `newspack_nodes/request_graph_ready` | `newspack_event_logger_nodes_mount_service_cis` |
| `newspack_nodes/station_tab_bundles` (filter) | `Current_Request_Overlay::register_bundle` |

**Read but never bound here:** `newspack_nodes/job_handlers` and `newspack_nodes/remote_job_handlers` are the substrate `Job_Worker_Node`'s registries — `Job_Router_Node` never reads either. `newspack_nodes/periodic` is the substrate's minute-cadence pass, which this plugin's job contexts bracket but do not subscribe to.

**WordPress hooks consumed:** [`plugins_loaded`](https://developer.wordpress.org/reference/hooks/plugins_loaded/) (11) for the deferred bootstrap, `rest_api_init` for the MCP route, `admin_menu` and `admin_enqueue_scripts` for the dashboards, `admin_init` and `admin_post_newspack_event_logger_nodes_reset_settings` for the settings page, `updated_option` / `added_option` / `pre_update_option` for restart classification, and — per governing rule — `pre_http_request`, `http_api_debug`, `query` and `log_query_custom_data` in `App\Core`.

## REST + React

The dashboards and admin tooling reach the application through the substrate's **command protocol**: one `POST /wp-json/newspack-nodes/v1/command` endpoint that routes a TM_COMMAND envelope to a named service CI node. This plugin owns three CIs — `performance`, `discovery` and `rules` — mounted on `newspack_nodes/request_graph_ready`; `status`, `settings`, `topologies`, `workers`, `aggregator`, `vault`, `sessions`, `classes`, `layouts` and `raw-logs` are substrate-owned. [`Service_CI_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-service-ci-node.php) wraps each handler in `Capabilities::require()` for the role the verb declares, so no handler re-gates itself — a hard-coded gate would silently outrank its own declaration. Each verb's request/response shape is documented in [API.md](API.md).

There is no per-endpoint controller hierarchy and no `includes/rest/` directory: the two REST surfaces are the substrate's `/command` endpoint and this plugin's MCP route, and everything else is a CI verb. [`tests/integration/M2BootstrapTest.php`](../tests/integration/M2BootstrapTest.php) builds the request-scope graph the way `HTTP_In::dispatch` does and asserts that firing `newspack_nodes/request_graph_ready` registers this plugin's three CIs and four substrate ones under their short names, so a CI that stops mounting fails there.

**`performance`** — the dashboards' read surface, plus one write:

| Verb | Role | Answers |
|---|---|---|
| `overview` | READ | Site-wide totals and the aggregate time series, from the `hourly` namespace alone. It reads no URL-index key, which is what holds a filtered poll to one fan-out; `urls` answers every URL-set question |
| `urls` | READ | The URL leaderboard — filters, page, totals, averages, rate and the slowest ten, so the header and the table beneath it cannot disagree. Worker traffic is excluded unless `include_workers` opts in |
| `dump_url` | READ | One URL: stats, aggregate flame data, breakdown and category series, and its recent requests |
| `url_breakdown` | READ | One URL, one dimension — what the modal's dropdown switch asks for |
| `search_requests` | READ | Locate a request by id: `{ rid, partition, url_hash }` |
| `grep_requests` | READ | Pattern-search recent traffic, returning matching requests rather than lines |
| `dump_request` | READ | One request in full, with its flame data and computed `Findings` |
| `ask` | READ | The brief for one descriptor — `url:`, `request:`, `span:`, `entry:` or `category:` |
| `list_hooks` | READ | The hook catalog behind the settings picker |
| `set` | TUNE | Write one of the three `SETTINGS_OPTIONS` names; `Settings_Sync_Node` fans it out. `Rule_Set::OPTION_RULES` routes to `Rule_Set::apply_synced()` instead, which re-tiers and holds its own no-op gate |

Six bounds shape a reply: `MAX_INDEX_ENTRIES` 1,000,000, `GREP_MAX_SCAN_LINES` 200,000, `RECENT_REQUEST_LIMIT` 500, `RECENT_BUCKETS` 12 (the thirteenth is still filling and is dropped), `SLOWEST_ROWS` 10 and `INDEX_READ_CHUNK` 12. `DIMENSIONS` is `status, method, server, country, from, ua, ja4`; `URL_SORTS` is `count, url, avg_ms, min_ms, max_ms, avg_peak_mb, last_updated`.

**`rules`** — `dump` (READ), and `save` / `upsert` / `delete` / `reset` (TUNE), all routed through `Rule_Set`. A JSON argument is bounded at `MAX_JSON_BYTES` 65536 and `MAX_JSON_DEPTH` 12.

**`discovery`** — one verb, `get` (READ), returning `{ registered_hooks, custom_events }`: the union across every LOG rule, with custom-event names filtered out of `registered_hooks` so the picker's two catalogs stay disjoint. It reports the ruleset and never writes it. One caller reaches it: the hub's `Discovery_Collector_Node`.

### MCP: one route, ten tools

[`MCP_Controller`](../includes/app/class-mcp-controller.php) mounts `POST /wp-json/newspack-event-logger-nodes/v1/mcp`, a [JSON-RPC](https://www.jsonrpc.org/specification) [MCP](https://modelcontextprotocol.io/) server (protocol `2025-06-18`) wrapping verbs that already exist. It adds none: one tool per verb, arguments through `Command_Args`, replies through one fence. Every JSON-RPC method rides that one POST — `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.

The point is to hand an agent the data rather than a summary. An in-plugin LLM call would ship faster and buy a dashboard that summarises itself to one model behind one proxy publishers cannot reach, and it could not see a Linear issue at all; exposing the data lets an agent that already holds those context providers do the correlation. The dashboard's own "Ask AI" button is the `?` picker rather than this route (see [React trees](#react-trees)), and the brief it assembles names this endpoint, so an agent connected here can pull the detail the brief trimmed.

Authorization has two halves and needs both. A `Bearer <handle>.<secret>` names a live session; on success the controller BECOMES that session's minting user and installs its scope as the request's ceiling. The scope is subtractive, never a grant — a manage-scoped session minted by someone who can do nothing still does nothing — and `tools/list` offers only what the scope covers. `Bootstrap::fleet_gate()` runs first, so a subsite cannot reach the main site's fleet. Because MCP does not go through `/command`, the substrate's per-user cap does not bound it: `RATE_LIMIT_BURST` 20 per `RATE_LIMIT_WINDOW_S` 10 does, checked after the credential so an unauthenticated flood cannot poison the store.

| Tool | Verb | Role |
|---|---|---|
| `performance_overview` | `performance.overview` | READ |
| `performance_urls` | `performance.urls` | READ |
| `dump_url` | `performance.dump_url` | READ |
| `search_requests` | `performance.search_requests` | READ |
| `dump_request` | `performance.dump_request` | READ |
| `grep_requests` | `performance.grep_requests` | READ |
| `performance_ask` | `performance.ask` | READ |
| `dump_rules` | `rules.dump` | READ |
| `rules_upsert` | `rules.upsert` | TUNE |
| `rules_delete` | `rules.delete` | TUNE |

Six verbs are deliberately absent and stay reachable over `/command` alone: `performance.list_hooks`, `performance.url_breakdown`, `performance.set`, `rules.save`, `rules.reset` and `discovery.get` — the `discovery` CI's only verb, which answers a hub collector and a connection probe rather than a reader. `Findings::caveat()` rides EVERY tool description, not just the first read, because a model handed a profiled/duration ratio without one will invent a cause for the difference.

A tool answers with recorded site traffic, and a visitor writes part of it, so **every success result leaves inside a `<site-data>` fence**: `MCP_Controller::fence()` encodes the reply with `wp_json_encode( $reply, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )` and wraps it, and `initialize`'s `instructions` carry the standing sentence that whatever sits inside that tag is data and never a direction to follow, ahead of the same caveat. `JSON_HEX_TAG` is what makes the fence unbreakable: a visitor string cannot carry a literal `</site-data>` when every `<` and `>` encodes as `\u003C`/`\u003E`. An `isError` result is the site's own message and stays unfenced. The same marking rides the browser's own exit, `askBrief.js`, over the subject URL and an entry's message ([decision 26](architecture-decisions.md#decision-26-read-tools-and-tune-scoped-write-tools-share-one-mcp-session-and-every-tool-result-is-fenced)).

### Real-time path: `/messages/stream` + slot pool

[SSE](https://html.spec.whatwg.org/multipage/server-sent-events.html) is a single substrate surface: `GET /wp-json/newspack-nodes/v1/messages/stream`, served by the substrate's `SSE_Out_Node` under the slot pool, which the browser dashboards and a hub's `Remote_Source_Node` pull both consume. The stream's lifecycle, its slot arithmetic and its lease rules are the substrate's, in its [API](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md#sse-stream) and [SSE host budget](https://github.com/Automattic/newspack-nodes/blob/main/docs/sse-host-budget.md).

The pool fails CLOSED where the stats path fails soft because the two carry different loads: a dashboard is read-only and can show "no data", while SSE streams ARE the live workload. Only the client refreshes a lease, and the server's slot check never does; don't reintroduce server-side refresh-on-check.

### React trees

Six bundles ship out of `src/`. Five are admin pages the substrate's `enqueue_react_page()` mounts by directory name — `overview` (Performance), `error-log`, `gyroscope`, `requests` (Request Log) and `settings` — and the sixth is the `current-request` debug-overlay tab, registered on the substrate's `newspack_nodes/station_tab_bundles` filter for the station and enqueued directly on the four ELN dashboards that mount `<DebugOverlay>` themselves. The `rules` editor is not a seventh bundle: it is a React root the `settings` tree mounts into the settings page's "Logging Rules" section.

![The six React bundles and their two transports. By command, a POST to /wp-json/newspack-nodes/v1/command minted from the dashboard's own Request node and answered TO = FROM; by stream, a GET of /messages/stream through a RemoteLink, a Tee and one view node. Six cards give each bundle's root, data hook, glob or verbs, and view classes — overview, error-log, gyroscope, requests, settings and current-request — and a table lists the six page globals, what each carries and which pages receive it.](img/ag-dashboard-sources.png)

The entry point defines three constants and hands all three to every `enqueue_react_page()` call. `NEWSPACK_EVENT_LOGGER_NODES_DIR` and `NEWSPACK_EVENT_LOGGER_NODES_URL` locate one bundle's build directory on disk and over HTTP, and `NEWSPACK_EVENT_LOGGER_NODES_VERSION` is the cache-busting version a bundle whose asset manifest is missing falls back to.

**Each bundle's root, and the hook that feeds it.** Every tree separates the chrome from the data the same way: the root component renders, and one hook declares the whole node graph behind it.

| Bundle | Root | Data layer |
|---|---|---|
| `overview` | [`PerformanceDashboard`](../src/overview/PerformanceDashboard.js), lazily imported behind the entry's `<Suspense>` boundary and wrapped in `ThemedRoot` | `usePerformanceGraph` |
| `error-log` | [`ErrorLog`](../src/error-log/ErrorLog.js), lazily imported inside the entry's own `DashboardShell` | `useErrorLogGraph` |
| `gyroscope` | [`GyroscopePage`](../src/gyroscope/GyroscopePage.js), the shell around `Inflight` | `useGyroscopeGraph` |
| `requests` | [`RequestStreamPage`](../src/requests/RequestStreamPage.js), the shell around `RequestStream` | `useRequestLogGraph` |
| `settings` | [`RulesAdmin`](../src/rules/RulesAdmin.js), rooted into the mount div the settings page prints | `useRulesGraph`, plus `useHookCatalogGraph` behind the hook picker |
| `current-request` | [`CurrentRequestTab`](../src/current-request/CurrentRequestTab.js), registered through `registerTab` | its own batched-poll slice on `dump_request` |

`ErrorLog` and `RequestStream` are thin wrappers over the substrate's `LogStreamViewer` chrome, supplying only the columns, the row and header renderers, the count and rate labels, and the partition picker; the rows themselves are read off the view node each frame and never become React state. `Inflight` samples `gyroscope:view.snapshot()` on the operator's chosen cadence for the same reason — a busy stream re-renders React at that cadence rather than per message.

**Page data.** Six browser globals carry what the server already knows, before the first command runs. Every payload binds to a script handle and ships only when that bundle did — the first five to the handle `enqueue_react_page()` returns, the overlay's to the `current-request` bundle's own — so a page whose bundle is missing ships no globals rather than half a page.

| Global | Carries | Pages |
|---|---|---|
| `eventLoggerDashboards` | `restUrl`, `nonce` and `retentionSeconds`. `src/overview/retention.js` freezes the last at module evaluation, so a settings change reaches the charts on the next page load; `AggregateTimeChart` and `CategoryTimeChart` size their axes from it through `buildTimeSlots()`, and `AggregateTimeChart` prints it as the chart's retention label, hours at or above 3600 seconds and minutes below. Every falsy reading — an absent global, an absent or non-numeric key, a literal 0 — takes the substrate's `DEFAULT_RETENTION_SECONDS` of 24 hours, a constant unrelated to `Config::stats_retention_seconds()` and its 3600-second floor, so a bundle whose inline script failed to ship draws a full day of axis over a shorter window and reports nothing | all five |
| `eventLoggerHookCategories` | `hook_categories.json` whole, `_colors` and `_patterns` included. The substrate's shared `formatUtils` compiles those patterns once into the hook colours every span takes, and the Gyroscope legend reads `_colors` directly. Only the SWATCH is dynamic there: the legend's contents are a fixed array in `Inflight.js` — six of the categories `Hook_Categorizer` ships (Lifecycle, Query & Posts, Content Rendering, Theme, Scripts & Styles, REST API) plus the two request states `process` and `complete` — so a category added to or renamed in `hook_categories.json` never joins the legend without that array being edited too, and a name the global does not carry draws grey | all five |
| `eventLoggerCustomColors` | `Config::get_custom_colors()` — custom-event name to swatch. `formatUtils`' `getStateColor()` resolves it behind the ` hook` and ` plugin` suffixes and ahead of `SYSTEM_COLORS`, so an install recolours `sql` or `process` without flattening the per-hook categorization | all five |
| `newspackNodesRecommendedHooks` | `recommended_log_events`, the set `HookSelectorModal`'s "Recommended" button selects | settings, overview |
| `newspackNodesCustomColors` | that same custom-colour map, which `CustomEventSelectorModal` renders its swatches from | settings, overview |
| `NewspackEventLoggerNodes.currentRequest` | `{ rid, partition, perfUrl }` for THIS request, written by `Current_Request_Overlay::inline_data_js()` at `admin_enqueue_scripts` priority 20 | wherever the overlay tab loads: the four dashboards that mount `<DebugOverlay>`, the station, and anything another plugin contributes through `overlay_pages` |

The overlay tab keeps a global of its own rather than joining the substrate's `NewspackNodesData`, which `enqueue_react_page()` localizes per bundle and would overwrite at render time; its `Object.assign` merge preserves whatever sibling key another ELN bundle already put there.

**The facts block.** The Performance page carries a seventh payload, in the DOM rather than a global: `PerformanceDashboard` renders `<script type="application/json" id="newspack-nodes-page-facts">`, filled by `factsJson( pageFacts( … ) )` from [`src/overview/pageFacts.js`](../src/overview/pageFacts.js), so anything reading the page — a browser assistant, an operator's script — takes named values instead of scraping the rendered table. `surface` discriminates the block by what the operator has open, innermost selection first: `request`, `url` or `overview`. All three echo the `filters` their numbers are of, because a narrowed number that does not say what narrowed it reads as the site's. Each then carries its own selection's measurements, off the replies the panels already render — `duration_ms`, `status_code`, `findings` and `caveat` for a request; `count`, `avg_ms`, `max_ms` and `max_peak_mb` for a URL; the filtered totals and the slowest ten otherwise. An absent overview total reads as `null` rather than `0`, so a reader arriving during first paint does not report an idle site.

It is facts only: no instructions, and the environment, the remote address and the user agent stay out of it, because a discovery block is a convenience for a reader rather than a second export path. `factsJson()` escapes `<`, U+2028 and U+2029: `JSON.stringify` leaves `<` alone, so a logged request to `/</script>…` would otherwise end the element and parse as HTML inside wp-admin, and the two separators are valid JSON that is not valid JavaScript.

**Registered node names.** Every bundle merges its view classes into `CommandInterpreterNode.includeNodes` — the name surface the debug console's `make_node <Type>` and `help <Type>` resolve against, and what the palette lists — by one of two routes. A declared slice goes through `registerSliceViews` in a `nodes/register.js`: `overview`, `settings`, `current-request` and `rules`, whose module `src/settings/index.js` imports for the side effect. The three streaming dashboards register beside the class instead, each view-node module exporting `views = CommandInterpreterNode.registerNodeClasses( … )` — [`requests/nodes/request-log-view-node.js`](../src/requests/nodes/request-log-view-node.js), [`gyroscope/nodes/gyroscope-view-node.js`](../src/gyroscope/nodes/gyroscope-view-node.js) and [`error-log/nodes/perf-errors-view-node.js`](../src/error-log/nodes/perf-errors-view-node.js). `registerSliceViews` calls that same method, so the two routes fill one table, and `overview/nodes/register.js` takes both because `UrlDetailMerge` forwards a message rather than publishing a slice.

| Bundle | Registered names | What they own |
|---|---|---|
| `overview` | `OverviewView`, `UrlsView`, `UrlDetailView`, `RequestDetailView`, `UrlDetailMerge` | The two polled slices — site overview and URL leaderboard — the two on-demand modal slices, and the merge transform on the `url-detail:in` Tee → `url-detail:view` edge, which owns the incremental request-list merge, the `last_modified` dedup and the 500-request cap |
| `settings` | `HookCatalogView`, `RulesView` | The hook taxonomy behind the picker, and the ruleset editor's table. `RulesView` is declared under `src/rules/` and reaches this bundle because `src/settings/index.js` imports it for the side effect |
| `requests` | `RequestLogView` | The Request Log's rows, mapped from `completed.*` envelopes |
| `gyroscope` | `GyroscopeView` | The In-Flight Requests model, folded from the two record shapes `gyroscope.*` interleaves |
| `error-log` | `PerfErrorsView` | The Error Log's rows, mapped from `errors.*` envelopes |
| `current-request` | `CurrentRequestView` | This request's own stored record, for the overlay tab |

The node graph is one per-page singleton every inlined bundle shares, where `includeNodes` is a static PER BUNDLE — so a name registered in one bundle resolves in no other. Every graph therefore hands `makeNode` the CLASS, through the module's `views` export; the names are for the console, never for wiring.

The three streaming dashboards (`error-log`, `gyroscope`, `requests`) share one shape. Each mounts a substrate `RemoteLink` node, which owns one child of its own — the `<name>:sse-in` EventSource ingress — and configures the two process-wide singletons every link on the page shares, `_http` (the outbound `/command` POST boundary) and `_heartbeat` (the slot keep-alive). `ensureChildren()` wires the bridge between them, carrying the slot and lease owner the SseIn's `connected` handshake delivers to the Heartbeat that has to keep them alive. The link takes the subscribe glob as its only constructor token (`errors.*`, `gyroscope.*`, `completed.*`), targets a pass-through `Tee`, and the Tee copies each frame to a single view-model node that shapes envelopes into rows inline.

`useErrorLogGraph`, `useGyroscopeGraph` and `useRequestLogGraph` each declare one of those graphs, and each declares nothing beyond what makes it that dashboard's: the node-name prefix, the glob and the view class. Two of the three reach the substrate through [`useGlobStreamGraph`](../src/hooks/useGlobStreamGraph.js), which adds the paused single-step and the two-level partition pick. The Gyroscope hook mounts the substrate's `useStreamGraph` directly, because that page offers no pause, step or filter and clears its rows whenever the stream reopens — a row that predates a connection gap is stale.

**The Performance dashboard's own modules.** `PerformanceDashboard` reads the four slices `usePerformanceGraph` mounts and hands them down; nothing below it fetches, save the commands that live beside the state their reply sets. [`OverviewSection`](../src/overview/components/OverviewSection.js) is the top card — the Ask trigger, the search box, the refresh picker, the headline stat grid, the aggregate chart with its metric, breakdown and server selectors, the three category charts and the time breakdown — and it renders nothing until the `overview` slice carries data. [`AreaTimeChart`](../src/overview/components/AreaTimeChart.js) is the one frame both slot-bucketed series draw on: `AggregateTimeChart` samples request metrics onto it, stacking `volume` and `cumulative` and overlaying `avg` and `memory` because averages do not add up, while `CategoryTimeChart` draws profile-category timings as overlaid bands with no total row, because a callback's time counts inside its hook's and summing the bands would double-count it. That one is three panels rather than one — Time by Category, Events by Category and Average Time per Event, the three questions the single `category_time_series` payload answers — and they share one category ranking, which fixes palette index and legend order. The ranking runs over the MODE's own field, summed `c` for the count panel and summed `t` for the other two, so the average panel's top band is the category holding the most total time rather than the slowest mean, and one outlier cannot take it; the count panel ranks by its own field, which is why a category can wear a different colour there than in the other two. `ResponseTimeChart` shares that frame with neither — it is a scatter of one dot per request on a continuous axis, mounted by the URL detail view, with a trend line and a mean line behind the dots. A dot needs both a timestamp and a nonzero duration, and the truthiness test that guards the missing field also drops a `duration_ms` of exactly 0, so an instant response is silently absent from the scatter and the dot count falls short of the URL's request count. `MemoryTrack` plots `peak_mb` as a staircase on the flame graph's own millisecond axis, held in register by a stretched viewBox rather than by measuring the flame, and draws nothing when `log_memory` left no readings. Which caller supplies the readings is the other half of that gate: only `RequestDetailView` passes `entries` to `RequestTrace`, so the URL modal's aggregate flame and the current-request overlay show no memory track however the setting stands.

[`BreakdownControls`](../src/overview/components/BreakdownControls.js) is that chart and the selects above it as one panel, because the Metric and Breakdown values the chart reads are exactly the ones those selects set. Both callers — the Overview card and the URL modal — mount it unconditionally, since the selects are the only way out of a dimension with no rows, a read still in flight or a refused reply; what differs between the two arrives as a prop. `AggregateTimeChart` exports the resolver that decides which of those the panel has, `breakdownState`, the one architecture [decision 16](architecture-decisions.md#decision-16-the-breakdown-panel-is-always-mounted-and-says-which-kind-of-nothing-it-has) rests on: the chart reads it too, so the blank frame and the line beneath it cannot disagree about whether the dimension is `pending`, `empty` or `series`. Only `series` draws. A coarser resolver ships beside it, `hasBuckets`, which `CategoryTimeChart` imports to decide whether to draw its three profile-category charts at all: that payload carries no per-dimension pending state to tell apart, so the only question there is whether any bucket arrived. One helper for both, so the two charts cannot hold different opinions about what an empty source is.

**Two cadences, and the modal stops one of them.** `usePerformanceGraph` runs the site overview and the URL leaderboard off one batched Timer, and gives the URL detail modal a second Timer of its own. Opening either modal — URL or request — PAUSES the first: the numbers behind the modal are frozen rather than stale, and closing the last one fires an immediate poll with both slices showing loading rather than waiting out the cadence. The URL modal's own Timer is armed only while that modal is the innermost selection, no request modal is open and the tab is visible, and it re-issues `dump_url` on the same interval the main poll uses, so the modal keeps refreshing on its own schedule; `UrlDetailMerge`'s `last_modified` dedup is what keeps an unchanged reply from repainting it.

**The search box routes on shape.** A term matching the request-id alphabet (`/^[a-zA-Z0-9_-]+$/`) fires `search_requests`, an exact rid lookup answering `{ rid, partition, url_hash }`; anything else fires `grep_requests`, a pattern search over recent traffic capped at `GREP_RESULT_LIMIT`. That character class also matches an ordinary path segment, so a plain word like `admin-ajax` is taken for a rid and comes back "not found" rather than as the pattern search the operator meant — the failure text's "prefix with / to search recent traffic" is the only hint of the rule. Opening a grep result re-runs the exact-rid path, because a grep row carries the rid alone and the modal needs the partition and hash only `search_requests` returns.

**The URL detail modal.** [`UrlDetailView`](../src/overview/components/UrlDetailView.js) draws the URL's whole story in six sections — the breakdown panel, the three category charts, the response-time scatter, the aggregate flame, the averaged profile breakdown and the recent-requests table — and it owns less of that than the list suggests. Sorting lives upstream: `PerformanceDashboard` sorts and hands back `sortedRequests`, and `requestSort` only tells the headers which arrow to draw. The "Errors Only" toggle is the exception, local state that narrows the row list, the "Recent Requests (%d)" count and the bar-scaling maximum together, and that the parent never sees. The requests table virtualizes against the ancestor `.components-modal__content`, so the view must be mounted inside a WordPress `Modal`, as the dashboard mounts it; anywhere else the hook's `closest()` finds nothing and the first measurement throws, which is why its own tests mock the hook rather than render it.

**The request-detail view model.** [`src/overview/utils/logEntryUtils.js`](../src/overview/utils/logEntryUtils.js) turns one stored record's flat entry list into the nested, foldable, time-ruled rows `LogEntriesTable` draws, in three passes. `spliceFoldedSpans()` puts a folded record's merged tree back where its entries were, as ordinary `(start)` / `(complete)` rows, so everything below reads one list whether the record was folded or not. `computeIndentedEntries()` derives an indent level and a `pairId` for every entry from its `(start)` / `(complete)` keyword, matching each close to the innermost open span of the same base name, so an improperly nested span still pairs, and spans time gaps with placeholder rows. `computeVisibleEntries()` applies the fold state, replacing each collapsed pair with one merged row, then rewrites the placeholder runs and the `displayTime` column over the rows that survived. The split is what keeps folding cheap: `PerformanceDashboard` memoizes the first two on the record, and `LogEntriesTable` memoizes the last on the fold set, which changes with every click.

`displayTime` is a ruler, not a per-row clock. A row carries a full `HH:MM:SS.cc` timestamp at a 100ms mark, at the first row and after a jump of more than nine ticks; a dot per 10ms tick since the row above otherwise; and nothing at all inside one tick. Scanning the column therefore reads elapsed time down the request. The gap rows that carry the ruler across an interval are drawn only where the interval IS elapsed time — never across a sequence-break marker, and never at all in a folded record, whose middle was selected out rather than kept consecutive. What the module does read across a marker is framing: `pruneSeveredSpans()` drops from the open-frame stack every span the break severed, keeping the ones a later `(complete)` still closes and the record's own outermost pair, so a span the fold cut short cannot adopt the rows after it. `Reqgrep_Command::prune_severed_spans()` is that same rule on the PHP side of a deploy boundary, and the parity test in [`logEntryUtils.test.js`](../src/overview/utils/__tests__/logEntryUtils.test.js) parses `Request_Builder_Node::SEQUENCE_BREAK_KEYS` out of the PHP and fails when the JS vocabulary disagrees.

**The flame graph and that table talk through one ref.** [`RequestDetailView`](../src/overview/components/RequestDetailView.js) holds a `revealRef`, `LogEntriesTable` fills it with its own `revealPath()` on mount, and the view passes `onRevealEntry` down through `RequestTrace` to `FlameGraph`. A Cmd/Ctrl+click on a frame therefore hands `revealPath()` the frame's root-first path, which resolves it to a `pairId`, unfolds it where it was collapsed and scrolls the table to the matching row — the one channel between the two components, and one that a change to either component's click handling or fold-state model breaks silently. The other two callers of `RequestTrace`, the URL modal's aggregate flame and the current-request overlay tab, pass no callback, so a modified click does nothing there.

**The address bar is a hook.** [`useUrlNavigation`](../src/overview/hooks/useUrlNavigation.js) holds the Performance dashboard's selection — `selectedUrl` and `selectedRequest` — and keeps it in step with the `?url=` and `?request=` query parameters, so every view is a shareable link and Back/Forward walks the views the operator visited. It reads `?search=` once on mount and never writes it, leaving that key to its owner. A `?request=` rid is validated against the id alphabet before anything selects it, because the value is echoed back into the address bar and sent to the server. A selection the address bar itself dictated writes no history entry, and the suppression is armed by the same comparison that decides whether the selection moves at all, so the flush it belongs to is the one that spends it. A hash outside the loaded page of the catalog, and any rid — whose partition only the server knows — are reported as an unanswered `deepLink` for the caller to resolve, since asking is a command and commands belong beside the state their replies set.

The hash in a `?url=` link is FNV-1a over the URL, computed by `Log_Manager::url_hash()` on the writing side and by the shared `fnv1a()` in the browser. The two ports agree on ASCII and only on ASCII: PHP hashes UTF-8 bytes where `charCodeAt` reads UTF-16 code units, so a URL carrying a non-ASCII character keys one bucket in the stats and a different one in the link the dashboard generates for it. The row renders normally and its own link answers with nothing.

**Dropdown vocabularies.** Four exported lists and one blank draft carry what the controls offer. [`src/overview/constants.js`](../src/overview/constants.js) holds `DASHBOARD_REFRESH_OPTIONS`, the refresh cadences, each a millisecond count held as a string because `SelectControl` compares option values as strings; `CHART_METRIC_OPTIONS`, whose `volume`, `avg`, `cumulative` and `memory` decide which of a bucket's `c` / `s` / `m` totals the chart reduces and whether the series stack — and, past the chart, which field the URL leaderboard's per-row background bar and its p95 scale read, `avg_peak_mb` for memory, `count` for volume and `avg_ms` for the other two, a mapping `UrlTable` spells out twice for itself rather than importing, so a metric added or renamed here needs it updated there; `CHART_BREAKDOWN_OPTIONS`, hand-matched against `Performance_CI_Node::DIMENSIONS` and `Flame_Builder_Node::DIM_FIELDS` (architecture [decision 16](architecture-decisions.md#decision-16-the-breakdown-panel-is-always-mounted-and-says-which-kind-of-nothing-it-has)); and `DEFAULT_CHART_BREAKDOWN`, `server`, which the dashboard resolves to `status` whenever it cannot break down by server — a server filter is applied, or it knows of only one. That fallback is derived rather than written back over the state, so a hub whose second server reports late gets the axis back. [`src/gyroscope/constants.js`](../src/gyroscope/constants.js) holds `INFLIGHT_REFRESH_OPTIONS`, seconds rather than milliseconds, setting how often `Inflight` samples the view node. [`src/rules/constants.js`](../src/rules/constants.js) holds `BLANK_RULE`, the one fresh draft both entry points into `RuleEditModal` seed from — a second copy is how a field added to `Rule` reaches one entry point and not the other.

**Two of those lists double as validation allowlists.** `usePersistedChoice` discards a stored value matching no option, so `DASHBOARD_REFRESH_OPTIONS` gates `event-logger-refresh-interval` and `INFLIGHT_REFRESH_OPTIONS` gates `event-logger-inflight-refresh`. Dropping an entry either list can still select — the in-flight view's `2` default and its 0–9 keyboard map included — leaves that dashboard defaulting to a value its own dropdown cannot render.

**Cross-dashboard modules.** Four directories under `src/` belong to no single bundle.

[`src/components/`](../src/components) holds the chrome a standalone page puts around its tree. [`DashboardShell`](../src/components/DashboardShell.js) is the fixed full-viewport box the Gyroscope, Request Log and Error Log share. WordPress lays an admin page out in a padded max-width column, which cramps a wide monitoring surface, so the box is positioned rather than flowed: `top` clears the admin bar and `left` tracks the admin menu's live width through the substrate's `useAdminMenuWidth`, so folding the menu slides the page instead of reflowing it. `overflowY` is a required prop rather than a default — a dashboard whose body owns its own scroller must clip here, one without an inner scroller must scroll here, and getting it wrong is a double scrollbar or a truncated page. `overflowX` is not the caller's to choose: the box always clips it, so anything wider scrolls in its own container. `ThemedRoot` wraps that box rather than sitting inside it, because `DebugOverlay` mounts a skin provider of its own only when it finds no `.newspack-nodes-ui` ancestor, and the wrapper is that ancestor. The Performance page skips the shell and mounts `ThemedRoot` and `DebugOverlay` itself, since its own layout keeps the padded column.

[`ThemedRoot`](../src/components/ThemedRoot.js) is a `display: contents` token provider with no box of its own, and its three classes are the whole contract: `newspack-nodes-skin-root` and `newspack-nodes-theme` are the selector the substrate's skin sheet emits every `--paper-*` / `--ink-*` token under, `newspack-nodes-ui` opts into the shared component appearance, and dropping `topology-app` is what keeps the graph layout out. It applies the console-selected skin at mount, then paints `document.body` with that skin's `--paper-3`, read by a throwaway probe parented INSIDE the wrapper because the token resolves nowhere else — so the WP-admin gutters around a dashboard take the skin rather than showing the light body background as stray strips. It repaints on `SKIN_EVENT`, the same-tab skin-change signal a `storage` event never delivers, and restores the original background on unmount.

Three smaller modules sit beside them. `LoadingFallback` is the spinner every `<Suspense>` boundary paints — the overview and error-log page shells, and the flame graph inside `RequestTrace` — and it names the substrate's canonical `newspack-nodes-performance-loading` class rather than styling itself. `RequestSummary` draws one request's URL, time, duration, peak memory and status as bare `<p>` rows with no wrapper, so the detail modal and the current-request overlay tab each keep the container that positions them. [`errorStatus`](../src/components/errorStatus.js) maps the terminal marker `Request_Builder_Node` stamps — `F`, `T`, `A`, `I` — to a label and a tone, and only a fatal takes `is-error`: a timeout, an abort and a hole in the log each mean the trace is partial, not that the request failed. That tone reaches one surface. `RequestDetailView` renders `status.tone` on its own badge, while `RequestSummary`'s status pill knows nothing of tones and paints `is-error` for any non-empty `statusNote` — and `CurrentRequestTab` passes a note for every code but a clean finish, so on the overlay tab a timeout, an abort and a log gap look exactly like a fatal. It duplicates `Request_Builder_Node::ERROR_STATUSES` deliberately, across a deploy boundary, and [`errorStatus.test.js`](../src/components/__tests__/errorStatus.test.js) parses the PHP constant and fails when the two lists disagree.

One more constant crosses that boundary with no test behind it. `GREP_RESULT_LIMIT` in `usePerformanceGraph` is 20, the same number as `Performance_CI_Node::GREP_RESULT_LIMIT_DEFAULT`, so the client sends the verb's own default rather than relying on it. The server holds a second constant the client never sees, `GREP_RESULT_LIMIT_MAX` of 50, which clamps whatever limit arrives — so the two drifting apart costs a differently-sized page, never an unbounded reply.

[`src/log-table/logTable.js`](../src/log-table/logTable.js) owns what the In-Flight, Request Log and Error Log tables have in common; each dashboard declares only its own columns, cases and nouns. `logColumns()` merges a dashboard's `label`, `tooltip` and `width` over the six shared declarations — `time`, `rid`, `url`, `status_code`, `remote_addr`, `user_agent` — preserving declaration order, because that is the table order `useColumnPicker` reads back off the result. Five of the six carry a shared meaning; `time` carries only a shared label and width. The Request Log and the Error Log head a wall clock with it and In-Flight a server-side duration, which is the same split the cell helpers below already answer for, one level up at the declaration. Only the Request Log and In-Flight mount that hook; the Error Log's `COLUMNS` and `DEFAULT_COLUMNS` are fixed constants and it offers no picker at all. `Cell` spells the base class and the `role="cell"` contract once, and `ridCell`, `urlCell`, `statusCell`, `ipCell`, `uaCell`, `durationCell` and `timeCell` draw over it. Each takes explicit values rather than a row, because the three dashboards spell one quantity three ways (`est_ms` / `time_ms` against `duration_ms`, `ts` against `timestamp`) and In-Flight hashes the URL client-side where the other two read a hash their view node stamped. Two of those cells carry a cross-page contract: `ridCell` links to `admin.php?page=event-logger-overview&request=<rid>` and `urlCell` to the same page with `&url=<hash>`, which the Performance dashboard's `useUrlNavigation` is the only reader of. Rename that page slug or either parameter and every deep link out of the three log tables goes quiet. `cellRenderer()` builds a row's renderer from a dashboard's per-column cases and draws a placeholder for a declared column with no case, since a cell that draws nothing shifts every column after it a slot left. `logListHeader()` publishes the grid template as the custom property the scss applies, over the substrate's `LogListHeader`. `countLabel()` shows rows shown over rows held while a filter hides some and the plain total otherwise — In-Flight filters nothing and passes no split form — and `rateLabel()` builds the toolbar's lines-per-second label.

In-Flight declares five columns of its own beside those six. `state` is the colour-coded badge, which shortens `include template` to `template` because the badge clips at 90px while its colour still resolves from the full state name; `what` is the free-form detail the producer sent — a query, a template, a hook name; and `est` falls back `est_ms` to `time_ms` to zero, so a row nothing has estimated yet shows what its logs do account for rather than a dash. The last two are two different delays and are easy to read as one: `age` is DISPLAY delay, how far behind the server the browser's own clock puts the row's last line, and warns past five seconds, while `lag` is the SERVER's processing delay and warns past one — a fleet falling behind moves `lag`, a tab left open moves `age`.

[`src/styles/base.scss`](../src/styles/base.scss) is the fourth. It `@forward`s the substrate's `tokens` and `mixins`, so one `@use` from a dashboard's own `styles/base.scss` reaches both, and it holds `log-stream-page()` — the padded flex column, its header, the browse body/main split and the column grid the three log-stream dashboards lay out identically. Only the toolbar-stats overrides differ, which is why they are the mixin's argument; the keyword and duration inks stay with each dashboard, because that is what differs between them.

Three hooks in `src/hooks/` sit on top of the substrate's:

- **`useGlobStreamGraph`** declares one dashboard's whole graph. The backbone, the pause/visibility gate, the recorded reopen target and the paused single-step are the substrate's `useStreamGraph` and `useSteppedRead` (which asks the substrate `raw-logs` CI's `read_message` for a paused Step); `useStreamGraph` mounts via `mountExospine`, snapshotting Core so soft nodes tear down and rebuild on "Reset Graph", closes the stream while the tab is hidden or paused, and reconnects from the last seen offset on refocus.
- **[`useGlobBrowse`](../src/hooks/useGlobBrowse.js)** is the two-level pick a glob needs: an empty selection is the whole glob tailed live, and a concrete dir such as `errors.p3` narrows to one partition and brings the segment rail with it — the substrate's `useSegmentBrowse`. A sole partition auto-selects, since one dir already IS the glob. That selection runs in a `useLayoutEffect` so it lands before the picker can paint a value matching no row, which leans on the catalog never arriving populated on a first render — it fills only from a polled command reply — so hoisting or sharing that fetch above this dashboard breaks the sole-partition case with nothing to show for it. Seeks ride the existing `positions` transport, keyed by partition directory, so no new server verb is needed; the substrate's `raw-logs` CI answers both catalog questions.
- **[`useMultiSelect`](../src/hooks/useMultiSelect.js)** holds the pending selection for the settings selector modals — the hook picker and the custom-event picker differ only in what they list, so the chosen Set, the reset on open and the apply live here.

**The pickers' shared chrome.** [`SelectorModal`](../src/settings/settings/SelectorModal.js) is the framed dialog both pickers render through: a header search box over an actions row, a scrolling body and an optional footer. It portals to `document.body`, outside the themed dashboard root, so the frame carries the modal, theme and UI classes itself — a frame missing them resolves its design tokens to nothing. `HookSelectorModal` and `CustomEventSelectorModal` supply what each is selecting and every translated string, because the translation extractor reads only literal `__()` arguments and cannot follow a string handed in as a prop. They share `useMultiSelect` and diverge on one control: the hook picker's Clear All scopes to the search, mirroring its Select All and relabelling itself "Clear Matches" while a filter is on, where the custom-event picker's empties the whole pending selection whatever the search shows. Only its footer counts and its Select All are search-aware. `TagInputField` sits in the same directory and serves the rules editor rather than the settings page: it edits a rule field holding an array of strings, adding a tag on Enter or blur, refusing blanks and duplicates, and reporting the whole array through `onChange`. [`RuleEditModal`](../src/rules/RuleEditModal.js) is the one editor every rule an operator writes comes through — the ruleset table and the dashboard's "Log this URL" both open it — and it owns the draft and nothing else, handing it back in `Rule::to_array()`'s wire shape for the parent to write through the `rules` CI's `upsert`. The dashboard's button stays DISABLED until `rules dump` has answered, because an id-less `upsert` matches an existing rule by PATTERN: opening a blank draft for a URL that already has a rule and saving it before the ruleset arrives would replace that rule's hooks and auto-tune thresholds with defaults instead of editing it. Once the ruleset is in hand the button derives its label — "Edit logging rule" against "Log this URL" — from the rule it found, on every render rather than from a copy in state.

The command-driven surfaces (`overview`, `settings`, `rules`) mint each awaited verb FROM its own `Request` node and let the reply route back `TO = FROM`.

**The flame graph's client transforms.** [`FlameGraph`](../src/overview/FlameGraph.js) wraps `d3-flame-graph`, and two passes reshape the tree before d3 sees it. `pruneFlameGraph()` caps the rendered frame count so a pathological aggregate cannot lock up the browser: at or under `PRUNE_SOFT_MAX_NODES` (1000) frames it keeps everything, and past that it keeps a frame ranked within the soft cap OR worth at least `PRUNE_MIN_FRACTION` (0.001) of the root's time, raising the cutoff once more when the survivors still exceed `PRUNE_HARD_MAX_NODES` (5000). The root always survives, and one value cutoff prunes the whole tree consistently because a child's value never exceeds its parent's — the coverage `Flame_Tree::cover_children()` guarantees by RAISING a parent over its positioned children, which is why a flame `value` is a layout width and every stat reads `duration_ms` instead.

`withTimeSpacers()` then turns each span's `t` into a real chronological position. d3-hierarchy's `partition` layout dices each depth, scaling a parent's children by `parentWidth / parent.value` and packing them flush from the left, so a frame's width is already honest and its start is not: the dead time before or between two spans smears into the frames either side. The pass hands that time over as a transparent spacer frame of its own, and d3-flame-graph's `reappraiseNode` recomputes a parent from its children, so the spacers cancel and no node's value changes. It runs AFTER pruning, deliberately — the cutoff and the node count must be computed on real frames — and takes what pruning left under the hard cap as its spacer budget, spending it on the widest gaps. Three shapes decline a family, which then packs flush as proportional widths: a parent or child with no `t`, which is the whole aggregate view; a `merged` node, whose `t` is the earliest folded span's start while its `value` totals them all, so `t + value` is no span's end; and children that OVERLAP, which have no side-by-side arrangement at all. `ABUT_TOLERANCE_MS` (0.01) keeps rounding from reading as an overlap — `Flame_Tree::offset_ms()` rounds `t` to three decimals — because one false pair costs its whole family their positions. Siblings sort by `t` where both carry one and by displayed name where neither does, tiered rather than mixed, since mixing the two keys is intransitive.

Colour comes from elsewhere on purpose. `createColorMapper()` paints a spacer `transparent` and defers every other frame to the shared `getStateColor`, so a span reads the same colour here as in the Time Breakdown and the log rows. [`src/overview/flameColors.js`](../src/overview/flameColors.js) holds no palette either: it measures the fill d3 wrote onto each `<rect>` and picks one of two fixed inks against it — `pickLabelColor`, returning `DARK_TEXT` above a 0.5 luminance threshold and `LIGHT_TEXT` at or below — while `isColorParseable` screens out what it cannot read, a spacer's `transparent` or whatever a `custom_colors` entry put in the deployment config. Its `relativeLuminance` skips the linearization WCAG's relative luminance specifies, because cheap and monotonic is all a threshold needs; the number backs no contrast-ratio claim. `restamp()` re-applies the label contrast and the frame's `span:` descriptor together, since d3 rebuilds the frames and re-runs the colour mapper in one step — applying half leaves a label at the default colour on a shade chosen to need the other.

Two more things survive that rebuild, one of them only partly. `createTooltip()` implements d3-flame-graph's tooltip contract: it positions the tip beside the cursor, flipping above it and clamping at each viewport edge rather than running off the bottom or the top where nothing can scroll it back, and it caps a frame's raw detail — a whole SQL statement, a block of rendered HTML — at `TOOLTIP_MAX_LINES` (30) with a marker naming what was trimmed. Because an auto-refresh destroys and rebuilds the frame under the pointer, `show()` records what it drew so `restore()` can put the tip straight back instead of having it vanish mid-read; `clearState()` runs on mouseleave, so a later refresh cannot resurrect a tip nobody is pointing at. Zoom is the partial one: a click records the clicked frame's root-to-node path so an in-place update can re-zoom to it, but `getNodePath()` prefers a frame's `detail` for each segment — the form `revealPath()` matches on — while `findNodeByPath()` walks on `data.name` alone. Zoom therefore restores only for detail-less frames, and a zoom into a query or a content frame resets to the root on the next refresh.

**The Ask picker.** `useAsk` ([`src/overview/components/AskPanel.js`](../src/overview/components/AskPanel.js)) holds ONE document-level `?` mode for the whole Performance dashboard, over the substrate's shared `useAskPicker`. Arming it marks the body, gives every `[data-ask]` element a tabindex, and intercepts clicks and Enter/Space in the capture phase so the target's own handler never also fires — modified clicks included, since Cmd/Ctrl already means something on those same elements: on a flame frame it is the reveal that jumps the log table to the matching row, which the capture-phase intercept has to reach first. A modified pick adds to the selection and keeps the picker armed; a click landing on nothing askable keeps it armed too, rather than handing the next click to whatever is under it; Escape gives the selection up.

**The target is the scope.** The picker collects the descriptor chain from the clicked element outward, innermost first, so DOM nesting supplies the containment and the descriptor vocabulary needs no second attribute for scope. Five surfaces stamp it: `url:` on a `UrlTable` row, `request:` on a `UrlDetailView` row and on the `RequestDetailView` root, `span:` on each `FlameGraph` frame (re-applied after every render, since d3 rebuilds the frames), `entry:` on a `LogEntriesTable` row, and `category:` in `RequestProfile`. Each pick sends its chain plus the dashboard's server filter to the `performance` CI's `ask` verb, and every reply appends rather than superseding — a multi-select has several asks in flight at once, and the earlier answer is one the operator asked for. Three `<AskButton>`s open the mode, at the overview header and the two modal headers, and `<AskPanel>` renders what arrived.

**A pick is not consent to send.** The panel shows the findings, and behind a disclosure the markdown `briefToMarkdown()` renders; copying it or following the link is a separate act. Both that renderer and `askClaudeUrl()` live in [`src/overview/askBrief.js`](../src/overview/askBrief.js), and the second is this plugin's one outbound third-party link — `https://claude.ai/new?q=<percent-encoded prompt>`. Under `PROMPT_MAX`, 6000 percent-encoded characters, the prompt is `PROMPT_INTRO` plus the whole brief; past it the link carries `PROMPT_TOO_LONG`, an ask to paste the brief next, and the brief itself stays out of the URL. The anchor copies the markdown either way, so that paste is ready. URLs in a brief have already been through `Log_Manager::redact_url()` and `Findings::caveat()` rides every one; the document names this site's MCP endpoint once at the end, so the `fetch` lines it lists are addressable — a link cannot connect an MCP server, which is why the brief carries the numbers itself.

Shared hooks, utils and components are NOT copied into this plugin — there is no local `src/shared/` tree. Three build aliases resolve to the `newspack-nodes` sibling checkout, through the one `src/build-kit/alias-map.cjs` esbuild and jest share: `@newspack-nodes/runtime`, `@newspack-nodes/shared` (with subpaths, e.g. `@newspack-nodes/shared/hooks/usePageVisibility`), and `@newspack-nodes/debug-overlay`. The resolver refuses to guess a base — a wrong-but-plausible default is how a build silently resolves the substrate to the wrong checkout.

### Canonical view contract

Every command-driven dashboard view follows the same contract, and the whole of it rests on one fact: **the addressing IS the correlation.**

- **No correlator.** Each awaited verb is minted FROM its own receiver node, and the server echoes `TO = FROM`, so a reply lands on exactly the node that asked. `message[ID]` stays empty. There is no id, no `replies` map, no promise registry and nothing keyed by one — sending several verbs in one tick means more nodes, never one node telling replies apart. Both interpreters also echo the verb and its arguments into the response VALUE, so a reply says which command it answers.
- **One send per `run()`.** `useCommandOnce` parks arguments in the Fetcher's outbox — the same outbox a polled slice throttles itself with — and the next fan-out sends them. `onDone` registers on the result node rather than reading rendered state, so two replies arriving in one batch are two notifications even though one re-render carries only the last.
- **Retry is for READS.** An idempotent read re-asks a request that went MISSING — never a refusal, which is an answer — and a second `run()` supersedes rather than queueing, because nobody wants the first answer once a second question is open. A write neither retries nor supersedes: an unanswered write may already have applied, and two rows deleted in the same second are two commands that both have to go.
- **A superseded ask fills nothing.** Where a reply carries no key to look it up by, the SUBJECT is the address: `subjectOf` makes the URL modal's breakdown subject the (URL, dimension) pair, so a dimension the operator has already switched away from matches no outstanding ask, fills nothing and retires nothing.
- **The server owns the set.** A table's search box, sort headers, filter toggles and pager are local state that reports itself upward and nothing else; the verb's reply is the population. Re-filtering or re-paginating those rows in the browser makes the footer's total describe a different set than the rows above it, and re-sorting them with `localeCompare` re-orders a page PHP already cut with byte-order `<=>`, so rows skip and repeat across pages.
- **Refusals are per-call, never a table-wide banner.** A refusal reaching one caller surfaces where that caller can act on it — a row-level snackbar, an in-form notice — and leaves the previously-loaded rows intact.
- **Every mutation re-reads.** A successful `save` / `upsert` / `delete` / `reset` re-`dump`s, so the table repaints from the server rather than from a locally patched copy; a refusal leaves the server unchanged, so it repaints nothing.
- **Three states, not two.** A panel driven by a fetch distinguishes `pending` (no series in hand), `empty` (one arrived carrying no values) and `series`. Only `series` draws. Collapsing the first two is how "No User Agent data" flickers during a fetch, and falling back to the totals answers a question nobody asked.
- **No dead REPL mounts.** Mount what the dashboard needs — the link, the Tee, and the view.

## CLI: wp nodes reqgrep and ruleset-bench

The substrate ships `wp nodes status` / `wp nodes cli` (worker introspection); this plugin adds two commands under the same namespace. `wp nodes reqgrep` is the application-side firehose filter, searching by URL pattern, request ID or arbitrary content match. `wp nodes ruleset-bench` measures the ruleset's two hook-storage tiers — inline against Table-pointer — and is what `Rule_Set::INLINE_HOOK_LIMIT` is calibrated from.

```bash
# Every segment, no filter.
wp nodes reqgrep

# Pattern match (URL substring, request ID, or category).
wp nodes reqgrep /calendar
wp nodes reqgrep 5f8e3a1c2

# Only the second-to-last segment and newer (fast lookup).
wp nodes reqgrep /calendar --recent

# Follow mode: tail all partitions continuously.
wp nodes reqgrep --follow

# Combine: follow + filter.
wp nodes reqgrep pyrobase --follow

# Raw JSONL instead of formatted output.
wp nodes reqgrep /calendar --raw

# Show requests that reached neither `process (complete)` nor `process (aborted)`.
wp nodes reqgrep pattern --incomplete

# Widen the history buffer (default 250 lines × 10 buckets; clamped to 1-10000 and 1-100).
wp nodes reqgrep pattern --bucket-size=1000 --num-buckets=20

# Read a specific firehose dir (must sit inside the configured logs dir).
wp nodes reqgrep pattern --firehose=/tmp/newspack-nodes/logs/firehose.p0

# Pipe stdin instead of firehose files. Stdin wins: --follow and --recent are ignored.
cat archived.log | wp nodes reqgrep pattern

# Measure the two hook-storage tiers (default 200 timed iterations per grid cell).
wp nodes ruleset-bench --iterations=500
```

Implementation lives in [`includes/cli/class-reqgrep-command.php`](../includes/cli/class-reqgrep-command.php). It reads the firehose through the substrate's [`Consumer_Node`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-consumer-node.php) rather than hand-rolling segment reads: one Consumer per partition sinking into a `Callback_Node`, drained synchronously to EOF in cat mode and under the `Event_Framework` drain loop in `--follow`. Piped input goes through `Stdin_Node`; all output goes through a swappable `Stdout_Node` that writes straight to STDOUT, bypassing PHP output buffers. `Reqgrep_Core` does the rid grouping — collecting every entry sharing a request id once any line for that rid matches — and the command prints them in chronological order with indentation reflecting the `(start)` / `(complete)` tree. A 300-slot in-flight cache (100 × 3 buckets, 60-second rotation, so a 180s idle ceiling) prints anything that falls out as `[incomplete]`. It carries no capability check — shell access to `wp` is the gate — but `--firehose` is canonical-path-validated and refused outside the configured logs directory.

The line rendering is its own, not a port of the dashboard's. `format_entry()` prints the timestamp column only where it advances at 0.1-second resolution, so same-tick entries read as a block; it draws a gap of whole seconds as dot rows at escalating intervals — every second, then every ten, then every hundred, stepping up after ten rows — so a multi-day gap costs rows in proportion to its logarithm; it pretty-prints an array `m` as JSON with every continuation line padded to the message column; and it trails `duration_ms` and `peak_mb` on the entries carrying them. The dashboard's `displayTime` ruler answers the same question with different arithmetic, so neither is the other's reference.

`--recent` is the fast path: it starts the scan at the second-to-last segment instead of walking every segment from the beginning. Use it for "what's the firehose doing right now?" introspection.

## See also

- [AGENTS.md](../AGENTS.md) — application contracts and invariants.
- [API.md](API.md) — REST endpoint reference.
- [`CHANGELOG.md`](../CHANGELOG.md) — version-by-version history.
- [Runtime architecture guide](https://github.com/Automattic/newspack-nodes/blob/main/docs/architecture-guide.md) — substrate this plugin depends on.
