# Security model

This document describes the trust boundaries of newspack-event-logger-nodes as they stand: the actors it treats as adversaries, what the logger captures and what crosses to the hub, what each boundary admits and refuses, the code that enforces it with file and line references, the tradeoffs chosen and the reason for each, and what has not been examined. The substrate the logger runs on describes the hub/spoke trust boundary, the reply gate, the shared-memcache salt, the `FROM` ceiling, the operator's terminal, the Vault and the JavaScript surface in [its security model](https://github.com/Automattic/newspack-nodes/blob/main/docs/security-model.md); a boundary both plugins touch is described there when the substrate enforces it and here when the logger does.

## Deployment shape

The `newspack-nodes` **hub** pulls log data over HTTPS from 24 **spokes** (23 publications and a dev site) into one `aggregator-hub` worker. `newspack-event-logger-nodes` writes each request's performance to the firehose, which the hub copies raw. [Write Path: Log_Manager](architecture-guide.md#write-path-log_manager) covers the firehose.

## Attacker model

The logger defends against these actors:

- **An anonymous visitor.** A request whose URL, query string, `User-Agent` or other header carries bytes chosen to land in a log, to be read back by the operator, or to collide with another request's id.
- **A holder of a `read` session.** A dashboard user, or an agent holding a read-scoped session, with the lowest capability the substrate grants; `read` alone opens the `dump_request` and `grep_requests` commands and the `performance_ask` MCP tool.
- **The hub.** A peer that pulls the firehose as the `newspack_nodes_hub` role, `read` and `tune` only, and so sees every line the spoke writes.
- **A compromised spoke.** An authenticated peer whose `remote_job` entries the hub runs, and whose discovery replies land in the hub-wide lists. The substrate's security model describes what else such a spoke can reach.
- **A neighbour on the shared cache pool.** Another site on the same memcached pool; the substrate's per-install salt is the defence, and the logger's `Stats_Store` is one of the readers that trust it.
- **The operator's terminal.** The display that renders whatever a log holds, including bytes a visitor chose; the substrate's `Core::terminal_safe()` is the defence, and [its security model](https://github.com/Automattic/newspack-nodes/blob/main/docs/security-model.md#what-reaches-the-operators-terminal) describes the chain from a visitor's request to the terminal.
- **A hostile page a `read` user visits.** A page that opens a stream in that user's name.
- **An AI assistant.** The recipient of an Ask brief, reached by paste or by the "Ask Claude" query string.

## What the logger captures, and what crosses to the hub

![Capture, replication and the three egresses](img/2026-09-08-firehose-capture.png)

The PHP logger and the Perl template engine both write the firehose; the logger records each request's URL in full, query string included. The hub pulls as the `newspack_nodes_hub` role, `read` and `tune` only (substrate [`includes/class-roles.php:114-117`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-roles.php#L114-L117)), and `read` alone opens the [`dump_request`](API.md#performance--the-omnibus-dashboard-ci) and `grep_requests` commands, so the hub sees them all. The substrate's [reply gate](https://github.com/Automattic/newspack-nodes/blob/main/docs/security-model.md#the-hubspoke-trust-boundary) decides what the hub delivers back to the spoke.

### Redaction: three models for three kinds of data

**Code:** [`includes/class-log-manager.php`](../includes/class-log-manager.php): [`URL_REDACT_PATTERN`](../includes/class-log-manager.php#L161), [`message()`](../includes/class-log-manager.php#L1165); `Gyrobase/Log.pm`: [`_redact_url`](https://github.a8c.com/Automattic/newspack-gyrobase/blob/1180c49/Gyrobase/Log.pm#L395), [`_write_entry`](https://github.a8c.com/Automattic/newspack-gyrobase/blob/1180c49/Gyrobase/Log.pm#L1119); [`includes/app/class-core.php`](../includes/app/class-core.php): [`HOOK_ARG_KEEP`](../includes/app/class-core.php#L134), [`shaped_argument()`](../includes/app/class-core.php#L325), [`without_literals()`](../includes/app/class-core.php#L818); `newspack-pyrobase/includes/runtime/class-log.php`: [`sql_shape()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-log.php#L534), [`signature_shape()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-log.php#L574); `tools/check-firehose-parity.py`; `tools/survey-firehose-keys.php`.

`Log_Manager::message()` and `Gyrobase::Log::_write_entry` run `URL_REDACT_PATTERN` over every string message. `check-firehose-parity.py` asserts all three properties: the two pattern bodies are byte-identical, each applies where the message is assembled, and neither touches the queued job body, which is what the hub runs the job from. The pattern matches a parameter by the SHAPE of its name, so a credential wrapped in a vendor's prefix — `consumer_secret`, `api-key` — is covered without anyone having to add the name; a credential under a name carrying none of its tokens is not. [URL-secret redaction](architecture-guide.md#url-secret-redaction) covers both tiers, the two names they over-redact, and the pattern's place in the write path.

Three models redact:

- **SQL.** Every quoted literal becomes `?`, comments are dropped, an `IN (…)` list collapses to one placeholder, and identifiers stay, since SQL has no keys to allowlist. This covers the `sql` span, any hook argument opening with a statement keyword, and the query signature, where `signature_shape()` blanks attribute values and keeps class and options.
- **Structured hook arguments.** As JSON, they keep every key and blank every leaf whose key is not in `HOOK_ARG_KEEP`. Its 104 names come from a census of two live hosts (243 and 254 distinct keys) and admit only structure: `is_*` conditionals, counters, pagination, behaviour flags, enumerated states, and the request-shape half of `http_request_args`. Across the census it blanks `meta_value` on 35,096 entries, `post_password` on 10,837, `token` on 546, `user_pass` and `user_activation_key` on every `WP_User` row, and `comment_author_email` and `comment_author_IP` on every comment row.
- **URL query parameters.** A denylist matching the SHAPE of a parameter name governs these: the censuses show 96 and 62 distinct parameters, most diagnostic (`page`, `action`, `rest_route`, `oid`, `film`, `sort`, `utm_*`), and the performance dashboard groups by URL, so an allowlist does not fit. The census surfaces `client`, `sig`, `signature` and `appid` carrying credential-shaped values, and one `user_email`; the pattern names `client`, `sig` and `signature`, and `appid` and `user_email` pass.
- **The SinglePlatform client's signed URL.** [`_sanitize_url`](https://github.a8c.com/Automattic/newspack-gyrobase/blob/1180c49/Gyrobase/Engine/singleplatformapi.pm#L122) in the Perl engine and [`sanitize_url_for_error()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-external-services.php#L1113) in pyrobase replace `client`, `apiKey` and `sig` before the URL reaches the `singleplatformapi` span's start line, the error entry and the errors a template renders on the page. The central pattern covers all three names again on the log path; the local copy is for the page, which no log redactor reaches.

### The request id

**Code:** [`includes/class-log-manager.php`](../includes/class-log-manager.php), `init_firehose()`: `UNIQUE_ID` capped at 64 bytes, else a generated id.

The request id groups a request's firehose lines, and no request header is ever a source. It comes from `UNIQUE_ID`, which Apache's mod_unique_id sets and a client cannot, because a non-`HTTP_` server variable carries no header of its own. Absent that, the id is generated and published back into `$_SERVER['UNIQUE_ID']` so a subprocess inherits the same identity. The id is every firehose line's Message KEY, the identity `Request_Builder_Node` groups by and the input to `Partition_Node::hash_to_partition()`, so a client-suppliable one would let a visitor file their lines under another request's id and pick their partition. The edge's own id stays available for correlation as the allowlisted `HTTP_X_A8C_REQUEST_ID` value in the `environment_v3` entry, which is what `wp nodes reqgrep <edge id>` finds the request through.

### The Ask brief

**Code:** [`includes/app/class-ask-assembler.php`](../includes/app/class-ask-assembler.php): [`entry_shape()`](../includes/app/class-ask-assembler.php#L373), [`for_request()`](../includes/app/class-ask-assembler.php#L82), [`ENV_ALLOWLIST`](../includes/app/class-ask-assembler.php#L73); [`includes/app/class-performance-ci-node.php`](../includes/app/class-performance-ci-node.php): `ask_request()`; [`includes/app/class-mcp-controller.php`](../includes/app/class-mcp-controller.php): [`INSTRUCTIONS`](../includes/app/class-mcp-controller.php#L79), [`fence()`](../includes/app/class-mcp-controller.php#L309); [`src/overview/askBrief.js`](../src/overview/askBrief.js): [`siteData()`](../src/overview/askBrief.js#L43), the `entry:` case ([305](../src/overview/askBrief.js#L305)) and [`askClaudeUrl()`](../src/overview/askBrief.js#L67).

An Ask brief summarizes one request, URL or log entry for pasting into an AI assistant. Its `env` field passes a six-key allowlist (`method`, `request_method`, `status_code`, `worker_type`, `partition`, `server_name`). Its `entries` field holds the same line: `for_request()` copies up to `MAX_ENTRIES` (60) entries through `entry_shape()`, and the `environment_v3` entry — the curated map of 34 server variables, the visitor's headers among them — ships with its category and no body, so the user agent, the referer, the forwarded-for chain and the JA3 and JA4 TLS fingerprints never reach a brief. Every other entry's `m` is copied verbatim. This bounds the brief alone: `dump_request` ships the record whole, inside the MCP fence. The brief leaves by three doors — the `performance_ask` MCP tool, a single-entry brief rendered in full, and the "Ask Claude" query string — and at each of them the values a log record wrote leave inside a `<site-data>` fence ([decision 25](architecture-decisions.md#decision-25-read-tools-and-tune-scoped-write-tools-share-one-mcp-session-and-every-tool-result-is-fenced)). [The environment allowlist](architecture-guide.md#the-environment-allowlist) covers what the writer admits into `environment_v3`.

## The remote-job rewrite

**Code:** [`includes/class-remote-job-rewrite-node.php`](../includes/class-remote-job-rewrite-node.php); substrate [`Job_Worker_Node::fill()`](https://github.com/Automattic/newspack-nodes/blob/v2.57.0/includes/class-job-worker-node.php#L207-L218); pyrobase `includes/runtime/class-evtemplate.php`: [`decline_foreign_job()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-evtemplate.php#L79), [`job_handler()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-evtemplate.php#L109), [`is_this_server()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/runtime/class-evtemplate.php#L291), hooked by [`Config::register_worker_job_filters()`](https://github.com/datapoke/newspack-pyrobase/blob/v0.27.1/includes/class-config.php#L176); nuclear-gyrobase [`includes/cron/class-evtemplate.php`](https://github.a8c.com/Automattic/newspack-nuclear-gyrobase/blob/v1.14.2/includes/cron/class-evtemplate.php#L269), the same check for its own jobs.

**A spoke's job runs on the hub. That is a requirement: foundation community sites depend on the hub running their jobs.** The hub runs a spoke's `remote_job` entries through whatever `remote_job_handlers` registers: neither this plugin nor the substrate registers anything, and pyrobase's deployed hub config registers `evtemplate`, so a spoke can have the hub render one of the hub's own templates with the spoke's parameters as the request. What confines that is the registration, not the host check. `is_this_server()` exists for routing: a queued template id carries its host (`https://hub/Tools/UpdateSite.html`) because every worker on the topic sees every job, and only the named host renders it. It is asked twice, by `decline_foreign_job()` on `newspack_nodes/job_worker/before_job` at priority 5, ahead of the logger's context opener so a declined job writes no record, and again inside `job_handler()`. It answers yes for a relative id, for an empty `SERVER_NAME` (a worker started with `wp nodes run`, which claims every host), for an exact match, for a match once a `www.` or `admin.` prefix is dropped from either side, and for a bare host equal to the `publication` config value. A worker spawned over HTTP carries the host the substrate posted to, `rest_url()`'s, so on a hub the check keeps a job addressed to another publication off this one; a spoke that queues a relative id passes it everywhere. [Remote_Job_Rewrite_Node](architecture-guide.md#remote_job_rewrite_node) and [Job ingress and routing](architecture-guide.md#job-ingress-and-routing) cover the rewrite.

## The other doors

**Code:** [`includes/app/class-mcp-controller.php`](../includes/app/class-mcp-controller.php) ([181-193](../includes/app/class-mcp-controller.php#L181-L193)); [`mu-plugins/00-newspack-profiler.php`](../mu-plugins/00-newspack-profiler.php) ([173-180](../mu-plugins/00-newspack-profiler.php#L173-L180)); [`includes/admin/class-admin.php`](../includes/admin/class-admin.php).

The substrate's security model tables its own doors: the auth, spawn, health-cache and command endpoints, the two event streams, the settings page, the `manage` and `tune` verbs and the WP-CLI verbs. The logger adds three, and each checks what it should:

| Door | Who opens it | What it checks |
|---|---|---|
| The MCP server | A bearer credential naming a live session, 20 calls per ten seconds | Strict shape; constant-time key compare; scope can only subtract from the session's. Two tools write; both need `tune`. Every success result leaves inside a `<site-data>` fence the `initialize` instructions explain, hex-escaped so no payload can close it. |
| The `admin_post` handler, reset settings (the substrate registers two more, its own reset and flush cache) | `manage` with a nonce | Nonce, then capability. |
| The profiler mu-plugin | Every request | Per-plugin load timings only, once the logger has started. |

[MCP: one route, ten tools](architecture-guide.md#mcp-one-route-ten-tools) covers the MCP server; [The profiler drop-in](architecture-guide.md#the-profiler-drop-in) covers the mu-plugin.

Two substrate doors bear on the logger's data. `read` reaches the raw firehose and every registered log source, so any `read` holder can pull the last 64 KB of the PHP error log; that is by design. A `manage_options` holder who adds `wp-config.php` as a log source hands every `read` account the salts the command-signing secret and the Vault key derive from, a mistake rather than an escalation. Both are the substrate's, and [its security model](https://github.com/Automattic/newspack-nodes/blob/main/docs/security-model.md#the-other-doors) describes them.

## Tradeoffs

Each choice below is made and reasoned; where the question stays open, the paragraph says so.

- **The URL surface is a denylist plus a periodic census**, because the performance dashboard groups by URL and most parameters are diagnostic. Whether that is acceptable is open.
- **`user_email` and `email` pass the URL pattern.** They are personal data, not credentials, and sometimes the thing being debugged. Whether to redact them is open.
- **The request id comes from `UNIQUE_ID` or is generated, never from a header.** Nobody can establish whether WP Cloud overwrites `X-A8C-Request-Id` at the edge, so the id depends on neither answer; the header value is kept for correlation only.
- **`entry_shape()` ships the environment entry with no body, and copies every other entry verbatim.** The brief's `env` field already carries the allowlisted facts, and a second list over the map would drift from the writer's. What a brief does carry leaves fenced, so a reader can tell recorded data from the brief around it ([decision 25](architecture-decisions.md#decision-25-read-tools-and-tune-scoped-write-tools-share-one-mcp-session-and-every-tool-result-is-fenced)).
- **The hub runs a spoke's jobs.** That is a requirement, not a choice; `is_this_server()` is the check on the template host, and it belongs to pyrobase.
- **Stats fail soft** ([decision 3](architecture-decisions.md#decision-3-stats-fail-soft)). A cache failure shows "no data"; the substrate's slot pool refuses the stream instead ([Real-time path](architecture-guide.md#real-time-path-messagesstream--slot-pool)).
- **A schema change is migrated by the flush alone** ([decision 5](architecture-decisions.md#decision-5-a-memcache-schema-change-is-migrated-by-the-flush-and-by-nothing-in-this-code); [Stats_Store](architecture-guide.md#stats_store-sums-not-means--salt-rotation)). Keys carry no version component and readers probe no shape; skipping the rotation on a deploy costs one retention window of garbage.
- **The `@wordpress/*` `wp-7.0` pin.** Any advisory reachable only past the pin is dismissed, with a written reason.

## Dependencies and the release path

A [GitHub Actions workflow](../.github/workflows/release.yml) builds each release from exactly the packages the committed lockfile names; two of its four third-party actions are pinned to a commit and two to a major version the owner can move, and the workflow checks the substrate out by a movable version tag. `newspack-event-logger-nodes` has two open advisories, `uuid` and `colord`, dismissed under the pin rule above, since each arrives only through `@wordpress/components`, which the build replaces with the copy WordPress loads, the zip omits `node_modules`, and `colord` cannot move under the pin because the `wp-7.0` `rich-text` requires 2.9.3. The two high findings `npm audit` adds, `js-yaml` and `nanoid`, are build-time tools that never ship. Composer requires only PHP, and `vendor/` stays out of the zip.

The GitHub Release is a record, not the artifact anyone installs. Sites run a zip the deploy script builds from `main` and installs over SSH beside the site's config file, which carries no credential.

## Not examined

- **Everything outside the two plugins.** This model reads other code only where it writes the firehose or calls into the two trees, and follows nothing past that boundary.
