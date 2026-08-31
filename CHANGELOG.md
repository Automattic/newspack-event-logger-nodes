# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **A fine URL bucket is kept for its read window, not the retention one.** The read plan asks for thirteen five-minute buckets plus the rest of their hour, and `urls_h` answers for everything behind that — so 264 of every 288 buckets a shard were held for the whole window with nothing that reads them, and the fine tier is the largest thing this schema puts in a 512MB cache. They now expire at `Stats_Store::FINE_TTL_SECONDS` (4h), leaving the readers two hours of margin and `roll_up_hours()` three. A longer outage than that costs the last partial hour before it, which no reader had folded yet. Writes group by ROLE so the coarse tier keeps the window in the same batch.
- **Worker traffic is its own shard family.** Cron, WP-CLI and job requests are a separate population, not a predicate over one: the table excludes them by default, so a shared index made every ordinary read carry rows it then discarded, and — worse — a URL a job also visited was ONE row flagged `worker`, which took its reader requests out of the default view with it. The shard token now carries the population (`urls:w3:{bucket}`), which every key builder below it treats as opaque, so the split needs no namespace and no second read plan. Each family caps and folds its own tail, which is what decision 14's two overflow keys were reserving slots for all along.

## [0.78.0] - 2026-08-31

### Changed

- **A URL row stores the hash; the name lives once in `urlmap`.** The URL string is 101 of a minimal row's 166 bytes, and the schema wrote it again in every bucket that URL appeared in — a retention window held up to 288 copies of one name, sharded by a hash of the same string. The name is now a key of its own (`Stats_Store::NS_URLMAP`), written once per URL and re-written only after half its TTL, batched into the flush's existing round trip. `Performance_CI_Node::resolve_urls()` reads names for the rows a response actually shows: one page, or every candidate when a search term or a `url` sort needs them to answer at all. Measured against a 1MB `item_size_max`: the widest row the schema writes goes 811 -> 699 bytes, and the common sole-server row 290 -> 189. **The stored shape changed, so run `wp nodes memcache flush` on deploy** — decision 5's salt rotation is the migration; without it a row written before this reads its name where `min_ms` is and renders with no URL until it ages out.
- **`MAX_URLS_PER_SHARD` is 2,000, up from 500**, which the freed bytes pay for: the client stores 4,000 rows of the widest shape and refuses 5,000, so 2,000 keeps a fleet twice that wide inside the ceiling. Sixteen shards put a bucket's ceiling at 32,000 distinct URLs rather than 8,000. The constant is public, and the tests that had 500 written into their seeds derive it.

## [0.77.1] - 2026-08-31

### Fixed

- **The origin frame reaches the table, not only the flame.** `formatMessage` read `m || l`, so the label naming who called a hook appeared only when the hook had no value to preview — and every hook worth tracing is a filter with a value. On `the_content` the caller was captured, aggregated into the flame, and then dropped from the row a reader is actually looking at. `l` and the deeper `caller` chain now lead the cell as their own lines, OUTSIDE the value clamp, so a kilobyte of block markup can never push them out of view.
- **One log entry can no longer own the whole table.** `hook_start` records up to 1024 bytes of the filter value as the span's `m`, which for `the_content` is sixty lines of block markup rendered into a single row — the surrounding entries are pushed off screen by the one you are least likely to be reading. The message now renders inside a six-line clamp, with duration, peak memory and the fold's child badge OUTSIDE it, so clipping a value never clips the numbers. Collapsing that distinction let `renderEntryMessage` lose its complete-vs-start branch entirely. `Core`'s two bare `1024` literals became `HOOK_VALUE_PREVIEW_MAX`, beside the `CALLER_PREVIEW_MAX` and `SQL_PREVIEW_MAX` they sit with.
- **The backtrace count sits on the caller-tracing row, and is named for what it counts.** It shipped as a full-width field under its own all-caps header, labelled *caller trace depth* — which reads as how far up the stack to walk. It is not: the frame window is fixed at `CALLER_FRAMES` (20), and `trace_callers` counts how many FIRINGS of each hook record a full backtrace. It is now a small number beside the checkbox reading `20 backtraces per hook`, shown only while the box is ticked, and unticking the box zeroes it — a hidden count kept spending twenty backtraces per hook with nothing on screen saying so.

## [0.77.0] - 2026-08-31

### Added

- **The caller trace depth is now a field in the rules editor.** `trace_callers` shipped in 0.76.0 as an integer the editor preserved but never showed, so the only way to set it was `rules_upsert` over the API — and a rule carrying a leftover budget of 20 read as untraced in the UI while still paying for twenty backtraces per hook. It is a number input beside the two auto-tune thresholds it matches in shape, `0 = off`, so both tiers of decision 24 are reachable from the same modal.

## [0.76.0] - 2026-08-31

### Added

- **`trace_hooks` labels every span with who called it, and the flame splits itself.** The entry's `l` is already the flame's aggregation label — `Flame_Tree` and `Flame_Fold` both build a node as `"{base}: {label}"` — which is how gyrobase gets `include: /Macros/Global.html` as its own node. Putting the caller's origin frame there turns one merged `the_content ×25` into one node per caller, with MEASURED durations and no change to the flame code at all. On a BDN editor load that is `the_content: WP_REST_Revisions_Controller->prepare_item_for_response ×11 (25.8s)` beside `the_content: wp_trim_excerpt ×7 (8ms)` — the difference between a hook that looks uniformly slow and one whose cost has an owner.

### Changed

- **The origin frame is taken directly, not through `wp_debug_backtrace_summary()`.** That helper walks and formats the entire stack to build a string of which all but one frame is discarded: **12.7us measured, against 0.9us** for `debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 )`. The depth limit is the lever — `IGNORE_ARGS` saves only 0.8us, because arguments are refcounted rather than copied even when one is a multi-megabyte `the_content`. At 0.9us the label runs on every firing (2,601 `render_block` calls cost 2.3ms), so the flame splits completely instead of only for the firings a budget covered.
- **`trace_callers` now buys only the deep chains and defaults to 0.** Turn on `trace_hooks` first and read the split; raise `trace_callers` when a split node raises a question the one frame cannot answer. The rules editor's checkbox is the cheap switch, and an edit no longer zeroes a deep budget set through the API.

## [0.75.0] - 2026-08-31

### Changed

- **`trace_callers` is a COUNT, and the frame window no longer truncates the answer.** Both caps were guesses and both cost a real diagnosis: 20 traces per hook left 21% of a `wp-admin/post.php` record's time unattributed, and an 8-frame window cut its stack at `rest_do_request` — one frame short of `rest_preload_api_request`, which is what explained the entire 41s request. The number now belongs to the rule: `trace_callers` is how many backtraces one hook may spend, with `true` still decoding to `Rule::TRACE_CALLERS_DEFAULT` (20) so rules written before this still read. `CALLER_FRAMES` is 20 and `CALLER_PREVIEW_MAX` 1024, against a 3840-byte `MAX_DATA_SIZE`, so the byte cap is the real bound rather than an arbitrary frame count. The rules editor keeps a tuned count instead of lowering it to the default when the box is re-ticked.

## [0.74.1] - 2026-08-31

### Fixed

- **The overflow rows collapse across shards again.** `Stats_Store::other_key()` is `Other` / `Other:worker` for EVERY shard, so the whole-index fold merged all sixteen for free — decision 14 says it outright: "a merge keyed on the url_hash would collapse sixteen of them into one". v0.74.0's per-shard fold lost that implicit collapse and the URL table showed fourteen identical `other URLs beyond the per-bucket cap` rows. The overflow is now accumulated across shards while raw and projected once at the end, so its means divide a whole row, and it passes through the same filters and totals as before. `merge_overflow_rows()` mirrors `fold_index_row()`'s accumulation over two merged rows: sums add, extremes take the extreme, `last_updated` takes the later, the split sums per server. Only the overflow needs it — every other hash lives in exactly one shard.

## [0.74.0] - 2026-08-31

### Changed

- **The URL index is folded ONE SHARD at a time, and the whole-index memo is gone.** The stored buckets are capped at `MAX_URLS_PER_SHARD`; their MERGE is not, so `$result` grew to the count of distinct URLs in the retention window and a production hub exhausted 512MB inside the fold itself — `empty_index_row()`, `fold_index_row()` and the display loop — with the unscoped `urls` verb returning HTTP 500. Three earlier releases removed three real duplicate copies (v0.71.3, v0.72.1, v0.73.3); none of them was the volume. A url_hash's shard is its first hex digit, so shards are disjoint and a shard's fold is complete for every URL it holds. `url_page()` now folds each shard, keeps only that shard's best `$offset + $limit` by the sort key and best `SLOWEST_ROWS` by mean, and drops the rest — the union of the per-shard top-N is exactly the global top-N, and `rows` and `totals` accumulate across shards and stay site-wide. Measured over a 20,000-row index spread across the shards: **3.6MB allocated, against 29.2MB before this work and 7.8MB after v0.72.1**. Decision 14's memo is deliberately retired: `raw_row()` point-reads the one shard its hash names, which was already the tested fallback whenever the memo was unset, and its test is replaced by one asserting a modal costs one shard rather than the table's fan-out. `raw_index()`, `row_from_index()`, `index_has_split()` and `project_at()` are deleted. Spec: `docs/superpowers/specs/2026-08-31-url-index-per-shard-fold.md`.

## [0.73.3] - 2026-08-31

### Fixed

- **The URL-index read streams its buckets instead of holding the window.** `url_row_sources()` issues one `lookup_multi` across all sixteen shards, so asking it for the whole retention window returned every bucket's unserialized rows at once — at `MAX_URLS_PER_SHARD` per key, hundreds of MB — resident before the first fold and beside the index being built. A production hub exhausted its 512MB there (`class-performance-ci-node.php:815`, inside `empty_index_row()`), the third address for the same fatal: v0.71.3 removed the fold's duplicate copies and v0.72.1 removed the projection's, and neither touched the volume. `load_index_default()` now folds one `INDEX_READ_CHUNK` (12 buckets) at a time and drops it before reading the next, hour tier included. Decision 6's batching is kept — still one round trip for many keys, just bounded. The "all shards or none" rule for a folded hour stays exact because an hour's shards are read together within a chunk. Every returned value is unchanged; the existing index assertions pin that.

## [0.73.2] - 2026-08-30

### Fixed

- **A traced hook's caller now reaches the record at all.** `Request_Builder_Node` rebuilds every stored entry from an ALLOWLIST — `n, ts, k, m, l, duration_ms, peak_mb, keep` — so `caller` was written to the firehose and dropped on the way into the request. Measured on a local run: 1,919 callers in the firehose segment, zero in the assembled records, which is why a traced hook showed nothing in any dashboard however the rule was set.
- **The trace keeps the NEAREST frames.** `wp_debug_backtrace_summary()` returns the stack nearest-first as an array and outermost-first as a string — the pretty form is that array reversed. Capping the string's head kept eight frames of `require_once('wp-settings.php')` bootstrap and cut the caller, which is the entire answer. `App\Core` now takes the array form and keeps `CALLER_FRAMES` (8) from the near end. The test shim modelled only the string, so it could not have caught this; it now models both halves of core's contract.

### Note

Assembly runs in a worker, so `wp nodes restart` is required after deploying a change to what a record carries; the old class survives in the running process otherwise.

## [0.73.1] - 2026-08-30

### Fixed

- **A traced hook's caller is now visible.** v0.73.0 captured it onto the entry and nothing read it: `LogEntriesTable` renders `entry.m` and no other field, so the whole point of the feature was written to the log and dropped on the floor. The field is also renamed `c` → `caller`, because `c` already means COUNT everywhere else in this schema (`DIM_SUMS`, the flame's per-node counts, the aggregate stats). A traced entry now leads with `called by …` above its value preview, which is the part that was already visible.

## [0.73.0] - 2026-08-30

### Added

- **Per-rule hook caller traces (`trace_callers`).** A span says how long a hook took and nothing about who asked for it, so a hook that fires sixteen times reads as sixteen identical mysteries. On a BDN `wp-admin/post.php` record, `the_content` fired sixteen times at ~2.4s each — 93% of a 40.6s request — and no amount of timing said why. `App\Core` now records `wp_debug_backtrace_summary()` onto the hook's own start entry as `c`, ignoring its own class so the top frame is the caller rather than the instrumentation. Capped at `CALLER_TRACE_LIMIT` (20) per HOOK per request, because the same question asked of `render_block` on that record would be 2,601 backtraces. Off by default and per-rule, like query spans. Recorded as decision 24.

## [0.72.2] - 2026-08-30

### Fixed

- **The rules editor's query-span control is a checkbox, like every other boolean in this UI.** It shipped as a `ToggleControl` — the only one in the tree. `HookSelectorModal` and `CustomEventSelectorModal`, the two modals this editor opens, both use `CheckboxControl`, and the settings page renders its booleans as checkboxes too. Nothing styles `.components-form-toggle` here, so the toggle inherited the modal's `.components-base-control` layout and rendered as a stray pill. The help text also now carries the measured cost rather than naming `SAVEQUERIES`, which is an implementation detail to an operator.

## [0.72.1] - 2026-08-30

### Fixed

- **The `urls` verb no longer materialises a second complete URL index.** `raw_index()` is memoized for the request — decision 14 requires it, so a modal in the same command batch answers from the read the table already paid for — and `index()` projected a display row for every URL beside it, leaving two full indexes resident. That is what exhausted 512MB on a production hub once v0.71.3 removed the copies one layer down; the fatal simply moved from `class-performance-ci-node.php:773` to `class-stats-store.php:1295`, a `unset()` on a by-value row that happened to be the allocation standing when the heap filled. The verb needs a WHOLE row only for the page it returns and the ten slowest: the filters, the totals and both rankings read values that are summed, compared and discarded. `url_page()` now walks the raw index once keeping a position, the sort value and `avg_ms`, accumulates the totals as it goes, and projects only the page and the slowest by position. Measured over a 20,000-row index the verb allocated **29.2MB before and 7.8MB after**, against an index of 20.6MB. Every returned byte is unchanged — the existing `urls` assertions pin that — and `sum_rows()` and `index()`, whose only callers this replaced, are deleted. Spec: `docs/superpowers/specs/2026-08-30-url-index-projection-peak.md`.

## [0.72.0] - 2026-08-30

### Added

- **Per-rule query spans.** A log rule can now set `log_queries` and get every database query as its own flame span, named `sql: <OP> <table>` so identical lookups merge into one node with a count, with the SQL text on the entry. Same bracket as the outbound-HTTP pair — `query` before, `log_query_custom_data` after — but opt-in, because the close only fires under `SAVEQUERIES` and each query costs two log entries. The constant's price was measured rather than assumed: 3,000 queries took 1.117s with it against 1.254s without, so the per-query backtrace is noise beside a 0.4ms round trip; the real cost is the 217 bytes per query `wpdb` retains, which `query_end()` drains. Recorded as decision 23, and it answers decision 21's own reopen condition. The rules editor grows a "Log database queries" toggle.

## [0.71.3] - 2026-08-30

### Fixed

- **The URL-index fold no longer materialises the index twice.** `load_index_default()` merged every bucket into `$result`, then copied it row by row into a second list, mutating `min_ms` on each — which forces a real copy of every row, so both arrays were fully resident at once. It also passed the whole index BY VALUE into `fold_bucket()` and assigned the return back, once per bucket source, and the caller holding a reference across that call makes the callee's first write copy the lot. A production hub exhausted its 512MB limit at that copy every fifteen seconds (`Allowed memory size of 536870912 bytes exhausted`, `class-performance-ci-node.php` line 773), taking the whole command response with it — the `urls`, `url_detail` and overview URL surfaces all read through this, so they rendered nothing. The fold now mutates by reference and the display shape mutates in place and returns `array_values()`, so rows are refcounted rather than duplicated. Measured on a 40,000-row shape, the display loop alone peaked at +15.3MB copying versus +2.2MB by reference. Every returned value is unchanged; the existing row assertions pin that.

## [0.71.2] - 2026-08-29

### Fixed

- **The fold no longer drops the close of a span the kept head left open.** A span opened in the head frames every row after it, so losing its `(complete)` costs the record its shape rather than one line. On a `community.thecoast.ca` job the gyrobase subprocess closed after 535.8s of a 536.2s request, and the PHP parent's ten post-subprocess rows filled the rolling tail and evicted it — the flame still counted the span (`gyrobase` count 1, 535829.753ms) while the entry list left it open, and three display defects followed from the view reconstructing framing the record no longer stated. The fold now records what the head left open and routes those completes into the unbounded `keep` bucket, spliced between the marker and the tail. The request's own frame is exempt, so `process (complete)` still ends the record. Recorded as decision 22.

## [0.71.1] - 2026-08-29

### Fixed

- **A row the record already shows is no longer redrawn inside a span it never ran in.** `keptPairCounts()` suppresses a merged row whose whole count is on screen, keyed by the PATH the pair ran at — "a `sql` inside `gyrobase` must not cancel out a `sql` beside it". But a span the fold left unclosed still frames the rows after it in the raw entry stream, so the tail's `query hook` read as `process/gyrobase/query hook` while the tree has it as `process/query hook`: the key missed, and the fold drew a synthetic "2 merged" copy of two rows already visible below — inside a Perl subprocess no PHP hook can enter. Kept paths now drop the frames the record never closes, which is where the tree does not put them either and where the render already prunes them. Finding the never-closed set needs the `outlive` unwind: the truncating one pops a child with its parent, so the unclosed frame vanishes at the request's own close.

## [0.71.0] - 2026-08-29

### Added

- **Outbound HTTP is timed.** Every brief carried the same caveat — the logger sees no SQL and no outbound HTTP — so a request that spent its time below PHP userland reported as unmeasured, the one answer a reader cannot act on. A BDN `wp-admin/post.php` record made the cost concrete: 41.7s wall against 12.2s CPU, with `Image_CDN::filter_the_content @999999` holding 29.4s of self time and no interior, leaving the 29.5s off-CPU remainder with no name. `App\Core` now binds `pre_http_request` / `http_api_debug` for any log rule and emits one span per remote call, named for the HOST (`http: i0.wp.com`) so the flame merges every call to one endpoint into a node with its count. The redacted URL rides as the value; the status code closes it. A short-circuited request opens NOTHING — `WP_Http::request()` returns it with a bare `return $pre;` and never fires `http_api_debug`, so a span opened there would never close and would adopt every row after it, and a short-circuit is a cache hit with no I/O to time anyway. Recorded as decision 21; the measurement caveat on every brief was corrected to match.

### Fixed

- **A folded span no longer loses its interior when the fold ate its close.** `pruneSeveredSpans()` drops spans the record never closes so they cannot adopt the rows after the break — right for the tail, wrong for the folded interior spliced in AT the marker, which is exactly what the tree puts INSIDE that span. On a `community.thecoast.ca` job whose gyrobase subprocess exited 0, the subprocess's own `(complete)` was merged away with the other 343,760 entries, so the pruner severed `gyrobase` and rendered its entire 535s interior — `parse_template`, every `include:`, `change`, `singleplatformapi` — as its SIBLINGS, with the span itself collapsing to the four entries of its kept head. The identical shape whose drain happened to survive the fold nested correctly, which is why it took a second record to see. The prune now waits for the first row the record itself resumes with, so the interior nests and the resumed tail (`nuclear_gyrobase`, the query hooks) still does not.
- **Instrumentation no longer constructs the logger it reports into.** `Log_Manager::instance()` lazily creates one, so a binding that outlived its request built the logger inside the callback and stamped `process (start)` with that moment rather than the mu-profiler's `request_ts`. New `Log_Manager::has_instance()` answers without creating, and both HTTP callbacks ask it first.

## [0.70.3] - 2026-08-29

### Fixed

- **A rule that instruments by significant events is no longer called hookless.** `cold_start()` suppressed its finding only when the governing rule's HOOK list was non-empty, but a rule instruments an interior just as well by naming significant events — and the flame proves it did. A BDN `wp-admin/post.php` record carrying 295 spans and a five-deep tree reported at severity high that "the governing rule registers no hooks, so nothing inside the request is measured", in the same finding whose own numbers line read `spans=295`. Significant and custom events now count as instrumentation, so the finding fires only when the rule declares nothing that could measure the interior.
- **One record can no longer propose more and less visibility at once.** The record above asked for `add_hooks` from `cold_start()` and `trim_hooks` from `truncation()` in one brief — the contradiction decision 13 records for `entry_gap`, arriving by a second route. A folded record has an interior by construction, so the corrected gate retires it.

## [0.70.2] - 2026-08-29

### Fixed

- **An orphaned `(complete)` now closes the innermost open span of its name, not the last one to start.** Spans close in the reverse of the order they opened, so the instance a trailing orphaned complete belongs to is the innermost still-open one — the deepest frame carrying the name in the merged tree. The fold picked it by `t` instead, which finds the last span to START; whenever the drained chain began earlier than a sibling that has since closed, the orphan matched nothing, closed nothing, and rendered at its parent's indent. On a real 536s record that tied `macro (complete)` and `include (complete)` at the same level, breaking the one-level-out-per-close the chain should show. It now reads 6, 5, 4, 3, 2.
- **A span the kept head left open is no longer drawn twice.** `foldedSpanEntries()` skips the instance that straddles the fold — "that row exists, and its tail `(complete)` was already deducted" — but it decided that from `owns`, which counts only completed PAIRS the kept rows show. A span left OPEN at the boundary has no pair, so `owns` was 0, `merged` kept the on-screen instance in its count, and the guard's `merged < 1` never fired. On a real 536s record that drew `gyrobase (start)` twice: once as entry 1 with its four real children, once as a synthetic parent carrying all 670 merged ones, reported as "1 merged". The claimed instance is now deducted, so the frame is skipped and its merged children hang under the real row. The existing coverage missed it by keying assertions on the keyword in a `Map`, where a duplicate collapses.


## [0.70.1] - 2026-08-29

### Changed

- **Substrate pin moves to `v2.46.2`**, which registers the substrate's own `topologies/` dir at plugin load instead of inside the lazy runtime wiring. A frontend page view reaches neither wiring tier, so its shutdown wake could not resolve a consumer topology's stock includes.


## [0.70.0] - 2026-08-29

### Added

- **A fatal names the plugin, file and line that killed the request.** `Log_Manager::write_terminal()` has always resolved `fatal_error`, `fatal_file`, `fatal_line`, `fatal_type` and `fatal_plugin` from `error_get_last()` — the one moment PHP still knows them — and nothing anywhere read them back: a grep for those keys returned the write site alone. `Request_Builder_Node` lifted `error_status` off the terminal entry and dropped the rest, so a dead request reported `F` and not one word about where it died. The record now carries all five, and `Findings` raises a `fatal` finding ahead of every other, titled with the plugin and detailed with the message plus `file:line`. It proposes nothing: no rule edit fixes a fatal. Found on a live wp-admin fatal that took a server-log grep to identify as `user-switching.php:1585`, which the logger had already recorded and thrown away.

### Fixed

- **A `span:` brief reports the parent that holds the time.** `Ask_Assembler::for_span()` located the span with a depth-first search and folded only that parent's copies, so a name appearing under several parents was answered for whichever one the walk reached first. On a 3.3s bangordailynews.com render, `span:pre_get_posts hook` returned 9.16ms across 12 calls under `process` while the sixteen under `do_blocks` held 2266ms — the brief pointed away from 69% of the request. It now groups every parent holding that name, reports the heaviest group, and carries `elsewhere` (`ms`, `count`, `parents`) so the chosen group can never read as the tree's total — rendered as its own line in the dashboard's ask panel, and omitted entirely when every copy sits under one parent. `locate_span()` is deleted.


## [0.69.3] - 2026-08-28

### Fixed

- **`request_detail` resolves any rid `request_search` can find.** Given no `--partition` the verb searched p0 alone and answered a flat `Request not found` — indistinguishable from an unknown rid — for records `request_search` locates immediately, and for every `ask` brief, whose `fetch` pointer emits the rid by itself. The partition is a hint now, as it already was for `ask`: `load_request()` searches it first and the rest after, so the two verbs agree on what exists. An out-of-range partition still fails loud as `invalid partition`.


## [0.69.2] - 2026-08-28

### Fixed

- **A span that closed before the abort no longer swallows a deeper span's drained complete.** A record's trailing `(orphaned)` completes name the spans still open when the producer drained, innermost first. `owedToTree()` keys that debt by BARE base, so the first same-base node in tree order took it — on the real record, `include: /Macros/Global.html`, a sibling that had closed normally early on and whose own complete the fold ate. It then skipped emitting one and stayed open across everything after it. Only a node on the chain the drain actually closed may spend the debt now: the tree's deepest path by `t`, which on that record is `change > save > validation > include: /Validation/Location.html > macro: processvideos` — matching the producer's drain order exactly. A tree with no timings cannot say, so the gate stands down rather than refusing every debt.


## [0.69.1] - 2026-08-28

### Changed

- **Substrate pin moves to `v2.46.1`**, which names a taken session lease `revoked` rather than `expired` and says that `wp nodes memcache flush` signs every session out.

### Fixed

- **An aborted request's log entries indent correctly.** Three things were wrong on a killed nuclear-gyrobase render, all in `computeIndentedEntries()`. A span's argument lives in the entry's `l` field and the flame fuses the two into one node name, so the spliced `include: /Macros/Global.html (start)` and the record's own `include (complete)` name one span in two spellings and could never pair — even though `foldedSpanEntries()` deliberately defers to that complete rather than emitting its own. Pairing and the severed-span budget now both match on the BASE name. A frame the record never closes is dropped when its parent closes, instead of adopting every row after it — the same budget `pruneSeveredSpans()` uses at a break, applied at a close. And the request's terminal carries whatever suffix ended it (`aborted`), so it matched no `(complete)` and drew one level deeper than the `(start)` it ends; the outermost pair now closes on its terminal. Verified against the real 498-node record: the tail returned from indent 3 to 1, and `process (aborted)` from 1 to 0.


## [0.69.0] - 2026-08-28

### Changed

- **The substrate floor rises to 2.46.0**, which is where `Table_Node::store_multi()` lands. A floor below what the code calls does not degrade: the plugin activates and then fatals on the missing method.

### Removed

- **Sixteen `Stats_Store` per-namespace accessors, and `bucket_get()` with them.** Batching the flush's writes left them with no production caller and 160 test call sites — test scaffolding living in a production class. They move to `tests/Helpers/TestCase.php`, where their callers already are, so a test keeps readable named access over the parts the batch pair takes. Gone from the store: `set_hourly_bucket`, `get_hourly_bucket`, `get_url_hour`, the category and dimensional getter/setter pairs (scoped and per-URL), the leaderboard pair, `get_url_shard` and `set_url_shard`. The plural readers the dashboards use are untouched, as is `set_url_hour`, which the roll-up still calls.

### Changed

- **The stats flush batches its writes, so its cost stops scaling with URL cardinality.** `persist_aggregate_stats()` issued one read-modify-write per KEY and two of its loops are per URL, so a full-window replay started at 171K messages/s and decayed as the retention window refilled — roughly 490 round trips per bucket at 20 distinct URLs against ~2,400 at the 500 cap. It is now collect, one multi-get, merge, one multi-set, chunked at 500 keys. Measured on the pre-batch code, 12 URLs cost 72 single sets and 48 cost 151 — 2.2 per URL, exactly the two per-URL loops. The read path has batched since it was written (`lookup_bucket_sets()`); this is the write half, and it is `tachikoma.md`'s first rule applied to the side that never got it.
- **Every URL-index key shape has one named owner.** `hourly_parts()`, `url_shard_parts()`, `url_hour_parts()`, `url_dim_parts()` and `url_cat_parts()` join the three that existed, so the batched writer spells no prefix itself and a shape cannot drift between the single-key setter and the batch.
- **Chunking is deliberate, not incidental.** Batching trades round trips for held memory in a system where a full-window read already peaks near 160MB. One chunk is at most one shard's worth of rows — the largest value the schema writes. Thousands of round trips becoming a handful is the prize; becoming one is not worth an unbounded peak.


## [0.68.1] - 2026-08-28

### Fixed

- **A replayed request now lands where the dashboard reads it.** A record is filed in the bucket it FINISHED in rather than the one it started in (completion is `timestamp + duration_ms`, and for an aborted request that is its abort moment), and a write whose hour this process has already folded merges into the coarse hour key instead of a fine bucket the reader will never look at again. Before this, `wp nodes deactivate complete; wp nodes gc --force; wp nodes activate complete` — the ordinary way to reprocess a window — replayed every record under its original timestamp, straight into folded hours: written, then unreadable. The URL table showed a fraction of the traffic while `Total Profiled`, which walks the fine buckets directly, showed all of it. Steady state never hit this; measured on a hub, records reach the builder about 14 seconds old against a 95-minute fine tail. `flush()` also rolls up before it persists, so the first flush after a respawn probes the coarse tier before placing a row — one partition has one worker, so a respawn is the only way that memo goes stale. The completion is clamped to now, so a skewed spoke clock or a bogus duration cannot file into a future bucket — which readers, walking backwards from now, would never see. See decision 20.
- **`Stats_Store::swap_url_server_sums()` is under test again.** Retiring the two `url_detail` scope-guard tests in 0.68.0 removed the only coverage of decision 14's scoped read; the class kept enough margin that the gate stayed green. Three tests now pin it: a scoped read swapping in that server's own sums, a row that never saw the server scoping to null rather than zero, and an unscoped read returning the row without its split.


## [0.68.0] - 2026-08-28

### Removed

- **Every row-level legacy probe in the URL-index writers, and `Stats_Store::ROW_PAST_END` with them.** Decision 5 already names the salt rotation as the migration: a schema change orphans the old keys the instant the salt moves. A second mechanism that sniffs each row for a missing `ROW_COUNT` or an index past the end had to be re-taught every future row shape, and it bought nothing the rotation does not already buy. Gone from `persist_url_shard()`, `fold_hour()`, and the `url_detail` scope guard that answered "this index predates the split" — an unscopeable row is now simply not found. Skipping the rotation costs one retention window of garbage and has a one-command fix, `wp nodes memcache flush`; it is not a case for the runtime to detect.

### Changed

- **Substrate pin moves to `v2.45.1`**, which fixes a config loader that truncated the whole configuration when an operator's config file assigned `$config`.

### Fixed

- **The URL table's Mem column sat in the wrong grid track.** Retiring the per-URL p95 in 0.67.0 removed a column from `UrlTable`'s `COLUMNS` and left `tables.scss` declaring eleven tracks for ten columns, so `Mem` slid into p95's 55px track and its own 60px stranded past the last cell. The track list is no longer written by hand: each column carries its `width` and the template comes from the shared `gridTemplate()` — the same one `ErrorLog` already uses — so a deleted column cannot leave a stray track behind.


## [0.67.1] - 2026-08-28

### Fixed

- **The Request Log's segment rail scrolls again.** It is the substrate's shared `LogBrowser`, whose `flex-shrink: 0` protected its 200px width while it was a direct child of the row-flexed body; the column-flexed `.newspack-nodes-rail-dock` re-aimed that declaration at the HEIGHT and pinned the rail to its content. `completed.p0` carries two dozen segments here, so the rail ran off the bottom of the viewport with no way to reach the older ones. Fixed in newspack-nodes 2.45.0 and inlined by this build.

### Changed

- **Substrate pin moves to `v2.45.0`.** No ELN source changed; the pin is what carries the shared `LogBrowser` fix into the bundled CSS.

## [0.67.0] - 2026-08-28

### Removed

- **The per-URL p50/p95/p99 columns, and the duration reservoir that computed them.** A percentile does not merge, so a fold over many buckets cannot produce the window's: `fold_index_row()` took ONE bucket's and the column was labelled as the whole retention window. A mergeable fixed-bin histogram was costed — it would have merged through `sum_entry()` with no new machinery — and rejected on price: 71-167 B/row for 1-9% accuracy, against 38 B for the fields it replaced, on a row just cut from 672 to 290. Nobody sorts by the column, so it goes.
- Retired with it: `ROW_P50_MS` / `ROW_P95_MS` / `ROW_P99_MS`, `ROW_DURATIONS`, `Stats_Store::apply_percentiles()`, `MAX_DURATIONS_PER_BUCKET`, and the whole `url_dur` namespace — up to 100 floats per row per bucket off the WRITE path, plus its mirror carve-out and one `lookup_multi` per shard per hour fold. The stored row is 15 fields, contiguous `0..14`.
- **`min_ms` and `max_ms` stay, and stay exact** — they fold from `duration_ms` directly. The `urls` verb's slowest-ten now ranks by `avg_ms`, and `Findings` / `Ask_Assembler` report `max_ms` where they reported `p95_ms`; both are exact and both were already stored.

## [0.66.0] - 2026-08-28

### Changed

- **A per-server split of ONE host that served every request the row counted collapses to the host name against `null`.** A stored `url` is absolute, so on a fleet of disjoint sites every URL has exactly one host — this is the common row, not the rare one, and `srv` was over half the whole index read. Measured on the hub's own row shape: **369 -> 290 B/row, a further 21.4%** — and 56.8% off the 672 B/row the named form measured at before 0.65.0; the hub's read projects from 36.1 MB to roughly 16. Count alone decides it: every `URL_SRV_SUMS` field is summed into the row and the split from the same increment, so a matching count means the rest match by construction.
- The collapse turns ABSENCE into MEANING, so both adjacent states are pinned: `null` resolves to the row's own numbers, while a host simply missing from the split still drops the row from a scoped read. Every merge — reader AND writer — expands through `Stats_Store::expand_sole_server()` BEFORE summing — `sum_fields()` skips a non-array value, so an unexpanded null would take that server's whole history out of a scoped read with no error anywhere.

## [0.65.0] - 2026-08-27

### Changed

- **A stored URL row is POSITIONAL, indexed by `Stats_Store::ROW_*`** — the shape `Message` uses and for the same reason. `serialize()` writes every key NAME into every row, so `s:11:"timed_count";` costs 18 bytes to say what `i:1;` says in 4, and a read paid that once per row per field: on the hub, 58,411 rows × eighteen fields plus eight more inside `srv`. Measured on live rows through the real pipeline: **672 B/row named against 398 positional, 40.9%**, which takes the hub's 36.1 MB read to roughly 21 — on top of the 7x key cut 0.64.0 already made.
- The eight fields that ADD take indexes 0-7, in `URL_SRV_SUMS` order, so ONE map describes both the row's summed half and its per-server split values — the split being that row restricted to one server, on the same indexes.
- **`fold_index_row()` is the one storage/display boundary for the ROW.** Everything above it is positional; the reader's row keeps its names because it crosses the wire as JSON, and `project_row()` and the browser are untouched. `ROW_FIELD_NAMES` is the only place an index becomes a name, read by that boundary, by dndocker's `tools/stats-shard-fields.php`, and reversed by a test helper.
- **The per-server split never crosses that boundary, so it is no longer named at it.** Every projection strips the split — `swap_url_server_sums()` unsets it, `project_row()` unsets `srv_recent` — so naming it in the fold translated every server of every stored row of every bucket source into display names for two call sites that consume the result and discard it. It now stays positional through the fold and is named once per scoped read, for the one selected server, by `swap_url_server_sums()`. Measured at **23-37% of the whole fold pass, 34-98ms per `urls` poll**, and it deletes `display_srv_sums()`, `named_split()` and 41 lines.
- **A skipped salt rotation drops legacy rows at the READ, in BOTH writers.** The shard blob is written back whole, so testing each row the flush touched left every row it did NOT touch to ride the read-modify-write back out — reappearing as a url-less, count-less ghost row that `totals.urls` still counted. `fold_hour()` needed the same guard and needed it more: a folded hour's fine buckets are never read again, so a ghost written into the coarse tier is what the dashboard shows for that whole hour and outlives the buckets it came from.
- **No migration.** Deactivate the topology, `wp nodes gc --force` (which clears the offsetlogs, so the whole retained firehose replays), Flush Caches (which rotates the cache salt and restarts the workers), reactivate. Old rows are unreachable from the moment the salt turns — and a SKIPPED rotation now degrades the same way instead of silently: `persist_url_shard()` discards a row that has no `ROW_COUNT` rather than merging into it. Half-merging was invisible and lasted a whole retention window, since the legacy row survived the fallback, its own counts vanished, its string keys rode into the stored row, and its `durations` went back onto the read path 0.64.0 took them off.
- **The fold reads through the VALIDATED coercion family.** `Core`'s own rule reserves `num_int` / `num_float` for arithmetic paths — bools and `'12abc'` take the default — and `fold_index_row()` was summing through the lenient `as_int` / `as_float`, which fold `true` as 1. A stored row carries a bool at `ROW_WORKER`, so under indexes any drift puts one where a count is read and the number is silently wrong; under names it took a writer spelling `'count' => true`. The two `hourly` and `lb` accumulators beside it moved with it — same rule, same file.
- **The per-server cap ranks the split by `Stats_Store::ROW_COUNT`.** Moving the split's fields to indexes left `cap_servers()` ranking by the field NAME `count`, so `cap_bucket()`'s sort compared nulls, tied every pair, and degraded to insertion order — past `MAX_SERVER_VALUES` it would have folded the busiest hosts into `Other` and kept whichever arrived first, exactly the Host-header-spray case the cap exists for. Introduced and fixed inside this unreleased change — 0.64.0 stored the split under names and ranked it correctly, so no release ever shipped it. The existing spray test could not see it: 300 servers at one request each have no ranking to get wrong. `test_the_server_cap_keeps_the_busiest_not_the_first_seen` pins it.
- `persist_url_shard()`'s legacy `durations` fold is deleted with it — it existed only to migrate rows written before the reservoir moved out in 0.64.0.

## [0.64.0] - 2026-08-27

### Changed

- **`url_detail` point-reads the one shard its hash names.** `Stats_Store::url_shard()` is the first hex digit of the hash, so a single URL lives in a single shard — but `row()` reached it through the whole merged index and paid the URL TABLE's fan-out to answer about one row. On a four-partition staging hub that is 18,432 keys and 54 MB per request; the point read is 1,152. `url_row_sources()` takes an optional `$shard` (null keeps every shard, so an older consumer is untouched), and both readers now fold through ONE `fold_index_row()` — written twice, the table and the modal would disagree about the same URL.
- **The NEWEST bucket supplies a URL's percentiles, not the oldest.** Percentiles do not merge, so the fold takes one bucket's — and sources arrive newest-first, so a last-wins read handed the display the oldest hour in the window. A URL whose p95 was 120ms a day ago and is 4s now read 120ms, and would have gone on reading it. First-wins now.
- **A refused duration-reservoir write is logged.** The reservoir is a standalone item since it moved out of the rows — up to `MAX_URLS_PER_SHARD` rows of `MAX_DURATIONS_PER_BUCKET` floats against memcached's ceiling — and its return was discarded, so a refusal left `apply_percentiles()` computing from one flush's samples with no diagnostic anywhere. The sibling index write has logged for exactly this reason since it was written.
- **The roll-up's memo re-probes, and forgets on a store swap.** It records what THIS process folded, which is not the same as what is still there: the coarse tier is excluded from the mirror, so an evicted hour can never be rehydrated and would have stayed believed-folded for the life of the worker — leaving the reader on the fallback the memo exists to escape. It empties every `REPROBE_EVERY_FLUSHES`, and `set_stats_store()` clears it, because a `configure_stats <n>` repoint would otherwise leave the new partition's coarse tier never written.
- **A partly folded hour is read from its fine buckets, not half from each.** `roll_up_hours()` treats an hour as done only when every shard carries a key; the reader treated ONE key anywhere as the whole hour. A fold that died between shards — or a shard whose write was refused — therefore made the reader skip the fallback for the shards that had none, and their traffic left the window: for a refused write, permanently.
- **A worker no longer probes hours it folded itself.** Decision 17 puts the fold on the flush path because one partition has one writer, which makes that worker the authority on what it has folded. The probe reads presence, but `getMulti` fetches and unserializes the VALUES — so probing the settled hours pulled the whole coarse tier off memcache twelve times a minute, on the writer's own path, to learn there was nothing to do. Steady state now reads nothing; a restart pays one probe. `set_url_hour()`'s return is checked too: a refused shard leaves the hour unfolded rather than believed-folded.
- **`fold_hour()` reads two keys per shard-hour instead of 24.** `get_url_duration_buckets()` is the batched reservoir read. A miss on `NS_URL_DUR` can never be rehydrated — it is excluded from the mirror — so it walks the index to its last line before giving up, and twelve of those now share one walk.
- **`read_plan()` was dropping up to 55 minutes of the window.** The fine tail stopped mid-hour, and that hour was then excluded from the coarse tier on the ground that the tail covered it — but the tail covers only the part it reaches, so the rest of that hour was read at NEITHER resolution. Nothing to zero at :00, eleven buckets of it at :59, so the URL table's counts breathed with the minute hand. `FINE_BUCKETS` is a floor now: the tail runs to the end of the hour it lands in. The test is a property — the plan must cover every bucket in the window — because the assertion it replaced had encoded the arithmetic of the defect.
- **The coarse tier is no longer mirrored.** It is derived from `urls`, which IS mirrored in full, and a missing hour is answered from that hour's fine buckets and re-folded within two flushes — so the durable copy stored the same information twice, on the axis decision 11 watches, with an hour frame the largest thing that could ride the held set at a checkpoint.
- **The roll-up's skip probe is one round trip, not one per hour.** Steady state is 23 folded hours and nothing to do; asking per hour paid 23 `lookup_multi` trips every flush to learn that, against an API that takes a list (decision 6: per-key `get` is a latency cliff).
- **The `$load_index` test seam takes the shard.** Given a seam that could not express a point read, `raw_row()` had to branch on the seam's PRESENCE — so under any test that installed it, the narrowing the seam exists to measure never ran.
- **One URL-row merge, in `Stats_Store::merge_url_row()`.** The rule — sums add, extremes take the larger, `min_ms` folds from TIMED buckets only, whichever side names the `url` wins — was written twice in `Flame_Builder_Node`, once per tier, and the second copy had already dropped a field: `sum_entry()` folds the eight scalars of `URL_SRV_SUMS` and NOT `srv`, so a folded hour carried an empty per-server split. Because a folded hour's fine buckets are deliberately not read, every server-scoped `urls` read silently lost any URL not hit in the last hour, and its totals with it — `swap_url_server_sums()` answers null for a row missing the server asked for (decision 14). `apply_percentiles()` is the same collapse for the sort-and-index-three-ways that sat beside it.
- **A folded hour takes the same row cap as a bucket.** `cap_url_rows()` is now shared, and the hour needs it more than the bucket does: it folds twelve buckets' URL SETS into one key, so its row count is the union of twelve capped sets. Uncapped, an hour could exceed memcached's item limit, and `roll_up_hours()` discards the write's return — a refused hour reads as unfolded and is re-folded on every flush, burning the budget later hours need. The tail FOLDS into the overflow rows, so totals stay exact.
- **The URL index reads two tiers: five-minute buckets for the last hour, one key per hour behind them.** `load_index_default()` enumerated 288 five-minute buckets and made exactly ONE per-bucket distinction in the whole loop — `isset( $recent[ $bucket ] )` — summing everything else. The readers need two resolutions, the whole retention window and the last complete hour, so the five-minute width bought precision at the window's edge and nothing else. A read is now `FINE_BUCKETS + hours` = 36 keys per shard against 288: on a four-partition hub, 2,304 keys against 18,432 and 54 MB.
- **A closed hour is folded ONCE, on flush, and overwrites.** `Flame_Builder_Node::roll_up_hours()` folds any closed hour in the window that has no coarse key yet, bounded to `ROLLUP_HOURS_PER_FLUSH` so a fresh deploy backfills over several flushes rather than stalling one. Adding into an hour incrementally would double-count every re-flush; the fold reads the twelve fine buckets and replaces. Percentiles come from the union of those buckets' reservoirs, so each covers an hour of samples instead of five minutes — which does not make the read-time merge correct (percentiles do not average) but is strictly closer to it.
- An hour with no coarse key falls back to its twelve fine buckets, which is what makes a fresh deploy and an hour a worker was down for self-healing rather than a hole. An hour with no traffic is still WRITTEN, empty, or it would look unfolded and pay that fallback forever. A folded hour's fine buckets are not read — they outlive the fold, and reading both would count the hour twice.
- **The coarse tier is mirrored to `flame-stats`; the duration reservoir is not.** An evicted hour restores through the same read-through as any index key, which it has to: everything behind the recent hour resolves to it, and the fine buckets it was folded from are a fallback rather than the source of truth. The reservoir is excluded (`STATS_MIRROR_TOPN[ NS_URL_DUR ] = 0`) — mirroring it would put the exact payload this change took OFF the read path back onto the durable write path, on the checkpoint budget decision 11 watches. Losing one costs precision on one open bucket's percentiles, because every figure derived from it is already stored.
- `Stats_Store::read_plan()` takes the WINDOW rather than a retention and a clock. Re-enumerating it meant a second `time()` on a path whose memo exists precisely so two panels of one response cannot answer for different windows.
- The coarse tier makes the window's far edge hour-granular, and it rounds OUTWARD: a 24-hour read may carry up to 59 extra minutes rather than drop traffic inside its own window. Retention is a floor, and five-minute precision at that edge answered no question.
- **The raw duration reservoir left the index rows for its own `url_dur` key.** Its only use is the WRITER's: recomputing p50/p95/p99 when a later flush folds more requests into the same bucket. No reader touches it — `load_index_default()` takes the three percentiles and nothing else — so of the 288 buckets in a read window exactly one, the open one, had any use for up to `MAX_DURATIONS_PER_BUCKET` floats per row, and the other 287 handed them to every poll. Migration is the fold itself: a stored row that still carries the field has its samples folded into the new key and the field dropped, and an untouched shard ages out on its own TTL.
- The narrowing is CONDITIONAL, which is what keeps decision 14's memo whole: `raw_row()` point-reads only while the merged index is unset, so a request that renders the table and then opens the modal answers the second question from the first read rather than paying a second fan-out.

- **The URL-detail refresh Tee fans the reply back to its Fetcher.** Every `addSliceFetcher` slice gets that edge from the substrate; this one is hand-wired, so it needed adding by hand. Without it the auto-refresh asks once and then holds an ask nothing ever settles, and the next command waits out the Fetcher's 15-second fail-open window.

### Fixed

- **The URL-detail request list is tailed, not re-read.** Every auto-refresh walked the whole retained window of every partition index to rebuild a list the browser already had. `url_detail` takes a `since` watermark now — the newest request the modal holds — and the reverse scan stops below it, so a poll costs the entries since the last one.
- **The stop reads COMPLETION, not start.** The request index is appended when a request ENDS, so a reverse walk is completion-descending and the start column is not monotone along it — which is why `Request_Builder_Node::index_completion_columns()` exists and why the retention floor beside this one already obeyed it. Comparing start would end the partition at the first long-running request and drop everything that finished after it, permanently, because the watermark then advances past them. On a URL that IS the long-running one — an import, a cron endpoint — that is every poll.
- **The watermark carries no slack; the comparison does.** The stop is exclusive, so a request sharing the watermark's second is still returned and the client sends exactly the newest it holds. The unit of that slack is a property of the index's timestamp resolution, which a browser cannot know — encoding a second there would be guessing at it, and the merge dedups the overlap by rid regardless.
- **A watermark stops ONE partition, never the fan-out.** Partition logs are independent, so reaching known ground in p0 says nothing about p1, and a plain stop would drop every later partition's requests silently. `scan_index_entries` gained a third callback outcome for that — `false` still ends the whole fan-out, which is what the 500-request cap wants.
- Only the refresh tick carries a watermark. Opening the modal, and changing the server scope, clear the merge first — so there is nothing held, and the whole window is exactly what they should ask for.

## [0.63.8] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.3**, which stops a Router bounce crossing the wire. Unfixed, an unroutable error had the two ends POSTing `NOT_AVAILABLE` at each other about twenty times a second for as long as the tab stayed open. The runtime is INLINED into these bundles, so the fix only reaches a browser through a release here.

## [0.63.7] - 2026-08-27

### Fixed

- **The flame graph colors frames by what they ARE again, not by how deep they sit.** Depth shading drew every frame in one ramp of the theme accent, so a validation frame and a database frame at the same depth were indistinguishable — and depth is the one thing the graph already shows, on its y-axis. Frames read `getStateColor()` once more: the hook patterns, the `plugin` and system colors, and the `custom_colors` setting, which is the same palette the Time Breakdown bar beneath it, the Inflight badges and the log rows use. One span is now the same color everywhere a reader meets it. The depth shader and the theme-token reader that fed it are deleted; `flameColors` keeps only the label-contrast helpers, which measure whatever fill the palette produced.

## [0.63.6] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.2**, so the debug overlay's REPL echoes each line with the prompt it was typed at rather than a fixed `/`. The overlay is INLINED into this plugin's bundles, not loaded from the substrate at runtime, so the fix only reaches a reader through a release here.

## [0.63.5] - 2026-08-27

### Fixed

- **The root refusal asks for the EFFECTIVE uid.** `Log_Manager` and the test bootstrap both read `posix_getuid()`, which is the real uid — but what decides who OWNS a file the process creates, and therefore who can rewrite it next, is the effective one. Under any setuid or `runuser` arrangement the two differ, and the check was answering the wrong question in exactly the case it exists for. Both now read `posix_geteuid()`, so the guard and the thing it guards agree.

## [0.63.4] - 2026-08-27

### Fixed

- **The floor is 2.40.0, not 2.38.0 — 0.63.3 raised it by hand and still got it wrong.** `Config` reads `Config_System\Schema::defaults()`, which the substrate added in 2.40.0. The by-hand audit passed it because `function defaults(` DID exist at the floor being tested — on `Roles`, an unrelated class. That is the whole case against matching method names, and it is why the floor is now derived by `check-substrate-floor.sh`, which asks PHPStan what class actually declares each call. Wired into `pre-push`, so the number cannot drift again unnoticed.

## [0.63.3] - 2026-08-27

### Fixed

- **The substrate floor was three minors too low, which turns a graceful dormancy into a fatal.** The gate read `2.35.0` while this plugin calls `Node::publish_sibling()` and `Node::sibling_name()` (2.37.0), `Schema_Reflection::dump_setters()` (2.37.0) and `Partition_Node::index_mtimes()` (2.38.0). On a substrate between the two the handshake PASSES, the plugin wires itself up, and `Request_Builder` then fatals on an undefined method the first time it publishes its `flight` sibling — which is every logged request. The whole point of the floor is that too-old means dormant, so a floor set too low is worse than no floor at all. Nothing caught it: `lint-docs.sh` rule 6 holds the prose to the loader, and both agreed on the wrong number.

## [0.63.2] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.1**, which stamps a log line in the reader's own zone rather than UTC, names a failing command's own node on the ERRORS tile, and puts the inbound FROM stamp behind `stamp_message`'s guards. The pin moved from v2.43.0; a release that leaves it behind ships the older shared code and still goes green, so the pin moves with the release.
- **The console-warning gate matches that prefix in any zone.** It stripped a hardcoded ` UTC ` before comparing a declared `expectConsoleWarn`, so every declaration stopped matching the moment the substrate moved. The zone token is constrained to the shapes `Intl` emits — `UTC`, `GMT±H[:MM]`, a 2–5 letter abbreviation — never a bare `\S+`, which would match any `<date> <time> <word> <word>: ` warning text and strip it: the gate swallowing the very lines it exists to report.

## [0.63.1] - 2026-08-26

### Fixed

- **A duration axis rescales to the unit its data needs.** `Avg Response Time (ms)` ticked to five digits on a slow site — `140000ms` — wider than the axis title beside it, so the two overlapped. Both duration axes build their formatter from the domain via the substrate's `axisDuration`, so the ticks read `140s` and the title drops its `(ms)`: the ticks carry the unit, and it moves with the data. That removed two of the three duration formatters here; `CategoryTimeChart` keeps its own, which needs the microseconds and compaction the others do not. `ResponseTimeChart`'s `avg:` label, drawn on the plot and hardcoded to `%dms`, uses the same formatter instance — a chart that disagrees with itself is worse than one that reads long.
- **The Ask button wears no styles of its own.** It carried `event-logger-ask__trigger` beside `button`, a name that exists to be painted — and something did, so it read ink beside a "Log this URL" that read cyan: two identical controls in one header wearing different colours. The cause was in the substrate's modal styles and is fixed there; what is fixed here is the invitation. The class's only rule was `margin-left: auto`, which is placement rather than identity, so that keys on position now and no control is named for it. `useAskPicker` has always found the trigger by its data attribute, which is unchanged.

## [0.63.0] - 2026-08-26

### Fixed

- **A killed request closes what it was inside, so the log nests it correctly.** A render past the worker's lease window left `gyrobase` open at the `entries (aggregated)` marker with no `(complete)` anywhere, so the break prune read it as severed by the fold and reparented the whole tail — merged spans included — onto the request, one level shallow. The reading was right: a producer drains its open stack as `(orphaned)` completes before writing any terminal, so a span still open really was cut by the fold. What was wrong was the record. `Log_Manager::finish()` already drained; the engine's `abort_process` did not, and wrote a `process` terminal for a scope it had not opened, which preempted the parent's and took the true duration and status code with it. Both are fixed in `newspack-gyrobase`, and the indent rule here is unchanged from 0.62.1.
- **`finish()` cannot lose the terminal.** The cooperative stop lands on a WRITE, and every line before the terminal is one — the orphan drain, the memory line, the resources line. A stop on any of them skipped `complete()` while `finished` stayed latched, so nothing retried and the request stranded in flight until eviction, on any job whose lock went away mid-request. The writes now sit inside a guard that marks the request aborted, writes the terminal, and re-raises: ADR-14's Tap carve-out is the same shape — do the thing that IS the pipeline, then signal.
- **The LIFO match rule is named once per language.** A `(complete)` matches the NEAREST open `(start)` of the same name — the rule that lets a request survive an embedded engine trace whose spans share its names. `Reqgrep_Command` wrote it out at both its call sites, the batch walk and the per-entry stepper, giving one rule two places to drift from `logEntryUtils.js`, which the whole CLI/dashboard parity rests on. It is `nearest_open()` now. The batch-walk / stepper split itself stays, because the JS has the same one.
- **A cooperative stop no longer costs the next entry its sequence number.** The line number was stamped into an entry and advanced only after `Topic::fill()` returned, so a stop raised inside that call left it un-advanced while the entry itself was durable — `Partition_Node::maybe_stop()` flushes before re-raising. The next entry reused the number, and `Request_Builder_Node` drops a duplicate outright. When that next entry was the terminal, the record stranded in flight until its trace timed out, having written a terminal nothing accepted: one production trace shows `memory` and `stderr` both at `n=6`, `INFO: duplicate message: expected #7, got #6`, and `WARNING: trace timed out after 577000ms`. The number is now consumed where it is stamped, so a throw burns one and reads as a GAP — which the builder reports, and which still lets terminals through.
- **The sequence warnings name the message, not just the request.** `duplicate message` and `missing message` reported the rid alone, which does not find the trace: the consumer stamps `Message::ID` as `segment:offset:length` — the seek back onto the log — and `FROM` names whose stream to seek in. Both now carry them, after the rid, so the existing prefixes still read the same.
- **A job context is restored even when its terminal raises.** `resume()` finished the context before popping the stack, so a stop re-raised from `finish()` left the finished job's instance current, its synthetic `$_SERVER` in place and `newspack_event_logger_nodes_scope_changed` unfired — every later request in that worker inheriting the job's identity and rule binding. Both it and `end_job_context()` now do their bookkeeping in a `finally`, and `finish()` resets its own state there too, so a failure writing the terminal cannot discard a deferred stop or leave the manager latched off while still accepting writes.
- **An aborted request reaches `request_grep` at all.** `Reqgrep_Core` fired `on_complete` only on the literal `process (complete)`, though `Request_Builder_Node::TERMINAL_KEYWORDS` has always held both terminals. A lease-killed request therefore never completed: the CLI printed it under `[incomplete]` at the end of the run instead of in stream order, and the dashboard — whose only sink is that callback — omitted it from the reply entirely, with no count and no `truncated` flag. It is the request an operator is grepping for. The gate now tests the terminal SET, and the method is `finalize_if_terminal` so its name stops asserting the old contract.
- **`wp nodes reqgrep` stops ruling time across a break.** The dashboard draws no time ruler over a sequence-break marker, and none anywhere in a folded record, because what the marker replaces was REMOVED rather than spent — decision 13. The CLI's dot rows had no such gate, so a record carrying a marker would read as minutes of idle time in the terminal and as missing detail in the browser. Reqgrep reads the firehose, where neither marker is written today — both are minted downstream onto the record — so this closes the divergence before a reader can meet it rather than after. `measurable()` is the CLI half of the rule; the fold flag is computed once where the keyword list is built, so a large record does not pay a scan per row.
- **One prune, named, on both sides.** The break rule was written out inline inside `computeIndentedEntries()`'s callback and again inside `Reqgrep_Command::indent_for()`. It is now `pruneSeveredSpans()` / `prune_severed_spans()`, each with the rule stated once in a docblock — the only two implementations that have to agree, now agreeing under one name.
- **The request's three keywords compose from the label, and the JS copy is pinned.** `Log_Manager` mints `process (start)`, `(complete)` and `(aborted)`; `Request_Builder_Node`, `Performance_CI_Node` and its own emitter each spelled them raw. They are now `REQUEST_START` / `REQUEST_COMPLETE` / `REQUEST_ABORTED`, composed from `REQUEST_LABEL`, and the marker keywords are `LOST_MARKER_KEY` / `FOLD_MARKER_KEY` with `SEQUENCE_BREAK_KEYS` built from them — including at the mint sites, which still wrote the literal and so were invisible to any guard. `logEntryUtils.js` must keep its own copies, being a separate deploy unit, so a parity test reads both sources and fails when they drift, the same guard `errorStatus` has carried since `A` and `I` shipped writable by the node and unreadable by the dashboards.
- **The fold marker says what happened without naming where it went.** `entries (aggregated)` announced entries "merged into the flame graph under memory pressure" and the matching finding repeated the phrase. The flame graph is one of several places a reader meets a folded record, so the marker now reports the merge alone.

## [0.62.1] - 2026-08-26

### Fixed

- **A span the pressure fold cut the inside out of keeps its children.** `entries (aggregated)` closed every span still open, so a `gyrobase` pair whose middle was merged away had its surviving children rendered as its siblings and its `(complete)` orphaned. A break now closes only the spans the kept rows never close, counted per name so two open frames against a single later `(complete)` sever the outer one. Folding such a pair works again too: the collapsed scan stops on a row at or above the start's own indent, which every child was.
- **`wp nodes reqgrep` reads a folded request the same way the dashboard does.** `Reqgrep_Command::indent_for()` is the CLI half of `computeIndentedEntries()` and its docblock says so — "Both must agree, or the same request reads two ways" — but it still held both of the rules replaced here, so the same record printed `query_sql` inside `gyrobase` in the browser and beside it in the terminal. The request is already in memory, so the CLI now reads the same straddle budget — same unwind, same per-name counting — and prints orphans at the same depth. It builds that keyword list only for a record that actually breaks, which a substring test settles without unpacking anything.
- **A break no longer severs the request's own frame.** `process (aborted)` is a terminal like `process (complete)` but matches no `(complete)` pattern, so a rule keeping only spans the record closes dropped the request itself and every row after an `entries (lost)` marker rendered outside it. The outermost frame is now held explicitly.
- **An orphaned `(complete)` renders where the record is, not outside it.** A `(complete)` whose `(start)` the fold merged away has nothing to close, and showing it at indent 0 put it beside `process (complete)` — outside the request still open around it. It now takes the depth of the spans still open, and `getAncestorPairIds()` no longer looks for an enclosing pair at its own level, where it matched a preceding sibling and unfolded a span the row was never inside.
- **A tail `(complete)` closes the span that encloses the merged middle, not a node inside it.** `spliceFoldedSpans()` handed the tail's completes to whichever tree node matched by name first, so a merged node repeating an open span's name took the `(complete)` belonging to the span around it. The spans the DISPLAY leaves open now take theirs first — which is the outlive reading, not the truncating path the tree is indexed by, and the two differ exactly when a child outlives its parent — and the rows the splice injects are excluded when deciding what straddles the break, so both passes read straddling from one helper and cannot disagree.
- **A merged span keeps its own frame beside the one the head left open.** The splice skipped every tree node whose base name matched the span open at that depth. `Flame_Fold` names nodes `base: label`, so `template: Home.html` and `template: Nav.html` share a base and both vanished, Nav's children rendering inside Home. A node merging instances beyond the one on screen also holds spans that began in the folded middle. The skip now claims the node by the full `base: label` name the tree paths it under — matching on the base alone handed the claim to whichever sibling came first, which on a record that closed one labelled span before opening another was the closed one — one node per depth, only when it adds nothing to what is shown, and only below the node that was itself the claim — by name alone it dissolved a `gyrobase` under an unrelated `bootstrap`, and by sibling-list state it reparented the children of every sibling after the claimed one. The chain carries on below a claimed node that does emit a frame, which otherwise re-emitted a still-open span as a duplicate.
- **Merge counts are attributed by path, and never phantom or negative.** `kept` counted complete pairs by base name, so a `sql` beside `gyrobase` cancelled out a `sql` inside it, and labelled siblings each subtracted the whole total — printing `0 merged` for a span nothing merged, then a negative. It is now keyed by the path the pair ran at — built from the same `base: label` names `Flame_Fold` paths by, not the bare keyword — spent once among siblings sharing one, and a node with nothing merged says nothing.
- **One LIFO pair walk, not three — with the unwind its readers actually differ on.** `openSpansAt()`, `keptPairCounts()` and the new `spansClosedAfter()` each re-implemented the same `(start)`/`(complete)` stack walk, differing only in what they accumulate, and had already drifted on whether spliced-in rows count. They share `walkPairs()`, which takes the bounds, the filter and an `onClose` visitor. Its `outlive` option is not a preference: `Flame_Fold::close()` pops every frame above the match, so the two readers indexed into the merged tree have to truncate or its paths and depths stop lining up, while `computeIndentedEntries()` closes the match alone and the budget feeding its stack has to count the same way. `Reqgrep_Command::spans_closed_after()` prunes a spliced stack, so it splices too, and now reads the span grammar from `Flame_Tree::PATTERN_START` / `PATTERN_COMPLETE` rather than a fourth copy of the same two regexes.

## [0.62.0] - 2026-08-26

### Changed

- **The overview chart breaks down by Server first.** `Server` moves to the top of the Breakdown menu and is what the chart opens on; `DEFAULT_CHART_BREAKDOWN` names it so the menu order and the default cannot drift apart. Selecting a single server still falls back to `Status Codes` — breaking one server out against itself charts a single series — and that existing reset now doubles as the default's fallback, so the menu's first entry and the selection agree in both states.

### Fixed

- **The stats-mirror read-through asks for the keys it wants.** `Flame_Builder_Node::rehydrate_seam()` called `Partition_Node::locate_by()` unbounded, taking a locator for every key in the flame-stats partition and discarding all but the few it needed. On a large site that allocation exhausted the request and fataled the overview dashboard. It now hashes its keys once and passes them down, so the cost scales with the query.

## [0.61.0] - 2026-08-25

### Changed

- **`Settings_Schema` is the single source of every config default, and `newspack-event-logger-nodes-config.php` is a commented ledger of the same values.** All nine application keys now carry their default in code: the six existing Fields declare `default:`, and `custom_colors`, `stats_mirror_node` and `recommended_log_events` gained `ui: false` Fields of their own — they carry no option and no settings field, but a key declared only by the config file is null on every install whose file predates it, because a deploy preserves the operator's copy. `Config::load_config_defaults()` seeds from `Settings_Schema::get()->defaults()`, then the config file, then `LOCAL_NEWSPACK_NODES_CONF`.

- **`Config::register_config_keys()` no longer derives declared keys from the config file.** The schema's `overlay_keys()` is the only source. Deriving from the file made an operator's typo self-declaring — `stats_miror_node` became a valid key while the real one quietly fell back to its default and the durable stats mirror went dark — and left an install whose file predates a key unable to read it at all.

- **An unrecognized key in the shipped config is REPORTED, never thrown.** `setup/newspack-event-logger-nodes.sh` copies the deployment's own config over the shipped path, so that file belongs to the operator. Throwing runs at `plugins_loaded:-10001` and would take down every request including wp-admin the day a key is renamed, recoverable only over SSH. `Config::unrecognized_keys()` records the stray keys and a rate-limited `Core::print_less_often()` names them on stderr; `Config::unknown_keys()` is the pure query behind it. New `tests/unit/ConfigSchemaTest.php` holds the schema, the ledger and the read sites together.

## [0.60.2] - 2026-08-25

### Fixed

- **Three Performance axes stop repeating a label.** The substrate's `drawAxes` now ticks a value axis through a `tickValues` ladder the formatter carries, and the same class of defect the byte axes had — ticks and formatter disagreeing about the unit — was here in whole-unit form: d3 steps a small domain by 0.5, and a formatter printing whole units rendered both ends of that step the same. Request Volume read `0 1 1 2 2 3 3` for a bucket of three requests and the response-time scatter read `0ms 1ms 1ms 2ms 2ms 3ms 3ms` under 4ms; both now tick in whole units (`integerTicks`). `AggregateTimeChart`'s four per-metric formatters moved out of a `useCallback` chain into one `Y_FORMATS` table, which is where the two whole-unit ones declare their ladder. Average Time per Event was the third: its milliseconds branch rounded to whole ms, so 1.5ms and 2ms both read `2ms`. `formatYValue` now rounds through the substrate's own `compactFixed` — one decimal under 10, none at or above — in every branch, which is the rule its per-second branch already used, so `1.5ms` prints and `2.0s` reads `2s`.

- **`Flame_Builder_Node` no longer names itself twice in its own log lines.** `Node::log_midfix()` already prepends `"<name>: "` to every line, and omits it only when the process name already starts with that name — so on a worker whose process name differs, the checkpoint-budget tripwire read `flame-builder: flame-builder: held stats frames over the checkpoint budget`. Four messages dropped the redundant prefix: three in `Flame_Builder_Node` and one in `Discovery_Collector_Node`. The static `Core::` log helpers add no node midfix, so their context tags (`LogManager: `, `PerformanceCI: `, `rules: `) are the only identity those lines carry and are left alone. A new `NodeLogPrefixTest` scans this plugin's node classes for the same defect.

### Changed

- **`MAX_CHECKPOINT_MIRROR_BYTES` raised from 262144 to 2097152 (2MB).** The cap was derived as a fraction of the 32MB record-drop cliff with margin, and never checked against the held size actually observed. On the production hub the checkpoint log reports held totals of ~267,000–348,000 bytes — just over the old cap — so it dropped one to thirteen frames on essentially every checkpoint, which made its own tripwire unreadable: a threshold that is always tripped reports nothing. 2MB is ~6x the observed peak held total (the Topics Cache Size chart peaks under 3.8MB across 24 hours), still 1/16th of the drop cliff, and leaves room for the O(servers) growth decision 11 describes. **The cost is disk**: the offsetlog ring holds at most 60 keyframes and each carries a whole checkpoint, so the budget bounds that ring at 60 × the cap — ~15.7MB before, ~120MB now. That ring caps keyframe COUNT and never bytes, and nothing else bounds it. Decision 11's reopen condition changes with it: "more than half the held frames drop" was written when routine firing was expected, and at 2MB routine firing stops, so any drop at all is now the signal.

## [0.60.1] - 2026-08-25

### Fixed
- **The aggregate chart never legends a series "Total" under a dropdown naming a dimension.** `chartSource()` returned the totals whenever the selected dimension carried no values, so switching Breakdown to User Agent drew `overview.aggregate_time_series` as a flat "Total" — an answer to a question nobody asked, and the same mislabel removed from the URL modal in 0.60.0. There is no "None" breakdown: `CHART_BREAKDOWN_OPTIONS` offers seven dimensions and `chartBreakdown` defaults to `status`, so a breakdown is ALWAYS selected and the totals are never the requested view. **This overturns the 0.60.0 decision recorded below** — "an empty one falls back to the totals" — on the evidence of the screenshot it produces. The resolver is now `breakdownState( breakdownData )`, answering `pending` / `empty` / `series`, and the chart draws only in `series`. `pending` and `empty` are distinct because the payload in state predates a dropdown switch by one round trip (`usePerformanceGraph` re-pokes `overview` on `chartBreakdown`), during which the dimension's key is ABSENT; the server always emits the key for every dimension it was asked for (`Performance_CI_Node`'s `overview` handler sets `$payload['breakdowns'][ $dim ]` unconditionally), so absent means in flight and present-but-valueless means genuinely empty. Calling the first "no data" is a lie that flickers. `BreakdownControls` prints "No <Dimension> data in this window." for `empty` and shows its existing "Loading…" for `pending`, and `OverviewSection` now mounts the panel unconditionally — its `serverFilter || chartSource(…)` gate is gone, so the Metric, Breakdown and Server selectors survive every state. The chart's `data` prop and `BreakdownControls`' `series` prop are removed with the fallback they existed for; `CategoryTimeChart` gates on the exported `hasBuckets` instead. `overview.aggregate_time_series` now has no client reader, but the payload key stays: `build_overview_payload()` merges those buckets anyway for `total_requests`, `global_avg_ms` and `global_avg_peak_mb`, so emitting it is free.
- **The URL modal's chart drops its held series the moment the Breakdown dropdown changes.** Its `url_breakdown` read left the previous dimension's series in state for the round trip the new one took to arrive, so switching to JA4 drew the STATUS series legended `2xx` / `4xx` under a dropdown reading "JA4 Hash" — plausible numbers under the wrong question, which reads truer than the Overview card's "Total" and is worse for it. The dimension-change effect now clears `breakdownData` before it fetches; the 300s router tick refreshing the SAME dimension does not, so a live chart never blanks. The panel then reads `pending` and shows "Loading…" until the reply lands. Clearing alone was not enough: every dimension's reply for one URL shared one SUBJECT — the URL hash — so the superseded status answer retired the JA4 ask and filled the chart under the JA4 label anyway, and the JA4 reply, arriving to an outbox that no longer held its send, was dropped for good. The read now addresses itself by the (URL, dimension) PAIR through `useCommandOnce`'s `subjectOf`, which is the whole of the correlation (ADR-7): a superseded answer matches no outstanding ask and is discarded, and the live one still arrives. The panel's `urlHash &&` mount gate and the `! urlHash` early return behind it are gone — the modal renders only inside `selectedUrl &&`, so both were dead, and a gate is the one shape decision 16 rejects. Touched `src/overview/components/UrlDetailView.js`.
- **Back then Forward to a request reopens it instead of rendering "Could not determine the partition for this request."** The popstate handler called the hook's own one-argument `selectRequest( requestId )`, which carries no partition — the dashboard's two-argument wrapper is the one that sets it — so the detail modal had a rid and no partition and refused to fetch, while reloading the very same URL worked. Reload works because a mount REPORTS the link and lets a resolver ask the server, and popstate now enters that same path: it sets the `?url=` hash and the rid as the deep-link intent and selects neither, so `request_search` answers with the partition and the existing resolver selects both. A hash the loaded page holds is still answered without a round trip, by the bootstrap this now shares. Going back to the dashboard cancels a pending intent rather than letting a stale one reopen the modal, and a rid the server cannot find still falls through to the `?url=` hash. A popstate suppresses the write-back, and that suppression is now armed by the same comparison that decides whether the selection moves at all — armed unconditionally it outlived a Forward that selected nothing, and the operator's next change, closing the modal, went unpushed, leaving the bar reading a request nobody had open. The hash the loaded page can answer is resolved IN the popstate handler rather than a commit later, so the write-back never runs on a selection that is only half the link, and a popstate to a hash this page cannot answer now clears the URL it came from instead of leaving the previous modal up under a changed address. An explicit `selectUrl` / `selectRequest` cancels a link still being resolved, and both resolvers apply a reply only while its subject is still the standing intent — a retried read answers seconds later, and an answer to a cancelled question was reopening what the operator had just left. **Behaviour change:** back/forward into a request no longer opens it immediately. It costs a `request_search` round trip, so the URL-detail pane paints first and the modal follows; under a retried read that can be seconds. The alternative is the partition-less modal this fixes. **Also behaviour, and undecided:** back or forward into a request now runs `applyFoundRequest`, which sets `setServerFilter( '' )`, so an active server filter is reset by that navigation. Touched `src/overview/hooks/useUrlNavigation.js` and `src/overview/PerformanceDashboard.js`.
- **`overview` answers about every dimension it was asked for, and refuses one it does not know.** With a single dimension the handler wrote a flat `breakdown_time_series` and no `breakdowns` map at all, so the key the client looks its dimension up by was absent — and an absent key reads as `pending`, which is now terminal. Nothing broke only because `breakdownsFor()` padded the ask back to two and because `CHART_BREAKDOWN_OPTIONS` matched `Performance_CI_Node::DIMENSIONS` by hand across two files; an eighth chart option added to one of them would have hung the panel on "Loading…" forever and taken the Server dropdown with it. The handler now emits one `breakdowns` key per dimension asked, whatever the count, and an unknown dimension is a refusal naming it rather than a silent drop — the shape `url_breakdown` already had, now one `assert_dimension()` for both. With the shape no longer count-dependent, `breakdownsFor()` stops padding: a chart on Server asks about Server alone instead of walking a second dimension's memcache buckets every poll. Touched `includes/app/class-performance-ci-node.php` and `src/overview/hooks/usePerformanceGraph.js`.

## [0.60.0] - 2026-08-25

### Fixed
- **A folded request's log drew a time ruler across a sequence it disclaims.** The gap placeholder rows spanned the merged window with timestamps and dot ticks, reading merged frames as idle time — the inference decision 13 forbids. `computeIndentedEntries()` now draws them only where the interval IS elapsed time: never across a sequence-break marker (matching `Findings::entry_gap()`, which skips a gap on either side of one), and not at all in a record carrying `entries (aggregated)`, whose rows past the marker are a `keep` selection out of the middle rather than consecutive entries. A record that merely LOST entries keeps its ruler everywhere except the one interval touching its marker; its survivors are contiguous and really are that far apart. Merged spans, their counts, fold/expand and `displayTime` on real rows are unchanged.
- **The Overview card keeps its chart panel up while a server filter is on.** Its gate is `chartSource()` finding one of its two series, and the Server selector lives INSIDE that panel — the only control that clears an active `serverFilter`, which still scopes the headline stats and the URL table beneath it. A window carrying only worker traffic empties both sources with no error at all (the aggregate series never counts workers), so the card could unmount the one way out of a filter it was still applying. Only a reload cleared it. The gate is now `serverFilter ||` that resolver, which leaves the unfiltered no-data case unheaded as before.
- **Three node classes in the performance graph are resolved as classes, not by name.** `usePerformanceGraph` handed `makeNode` the strings `'UrlDetailMerge'`, `'UrlDetailView'` and `'RequestDetailView'`; ADR-16 requires the class, because the name map is a per-bundle static and a hub tab building this graph through another bundle's interpreter resolves none of them. `views` already exports all three. `scripts/lint-contract.mjs` missed them because it `exec`s per line and prettier had wrapped those calls across lines.
- **The URL modal's breakdown panel stays mounted for as long as a URL is selected.** `UrlDetailView`'s `onDone` runs on an ERROR reply too — `useCommandOnce` treats a refusal as an answer, not a retry — so it set `breakdownData` to null and cleared `loading`, and `BreakdownControls` unmounted the whole panel INCLUDING both dropdowns. A `url_breakdown` refusal, or a valid dimension with no rows in the window, therefore took away the only control that could have picked a different dimension, while the router tick re-asked the same one every 300s and flashed the panel in and out. The gate is `urlHash`, not `breakdownData`: an error and an empty dimension both leave the data null, so only "is a URL selected" tells the mounted case from the unmounted one. `BreakdownControls` holds no gate of its own; each caller decides from facts only it holds — the Overview card waits for `chartSource()` to find one of its TWO sources rather than drawing an axis with no line, while the modal has ONE and keeps the panel up for as long as a URL is selected. The refusal now prints under the chart as `newspack-nodes-status is-error` instead of being swallowed. Touched `src/overview/components/UrlDetailView.js` and `src/overview/components/BreakdownControls.js`.
- **`scan_stopped_early` describes the list on screen, not the last walk.** `UrlDetailMergeNode::_merge()` keeps `prev.requests` when a reply carries no new rids but spreads `...data`, so the flag came from the latest walk while the list was the union of every walk since the modal opened. Both directions were wrong: one budget-spent poll hung "the scan stopped early" over a complete list, and a later complete walk cleared the note off a list that still held only what earlier truncated walks had reached. The flag unions with `||` across merged replies and resets on `clear`, alongside the retained payload it describes. This is the shape the incremental-scan spec asks for, where `requests` becomes a delta and the divergence stops being an edge case. Touched `src/overview/nodes/url-detail-merge-node.js`.
- **The two rid-lookup MCP tools say a spent scan budget is not a definite negative.** `docs/API.md` documents `request index scan budget spent before rid <rid> was reached` for both `request_search` and `request_detail`, but their `MCP_Controller::TOOLS` summaries still described only the happy path, so an agent read that refusal as "no such request" and stopped looking. Both summaries name it, and a unit test pins them — the one prose field on that table whose omission changes an answer.
- **A URL detail panel whose index scan stopped early says so, instead of reporting no requests.** `scan_index_entries()` counts every `.idx` line it walks rather than the ones that match, and ends the whole fan-out once `MAX_INDEX_ENTRIES` is spent, so a low-traffic URL sitting behind its neighbours' entries is never reached — and `find_recent_requests_for_url()` returned the empty list it had collected as though that were the answer. The modal drew "Recent Requests (0) / No requests to display" beside a header, a volume chart and a flame graph all counting traffic for the same URL. The scan now tells its two endings apart — satisfied by the caller, or out of budget — and the second travels as `scan_stopped_early` on the `url_detail` payload, on the `url:` ask brief and in the docs alike. One word, named for the WALK rather than for the slice: `worst_requests` is always cut to five and `requests` is always cut at `RECENT_REQUEST_LIMIT`, so a `_truncated` suffix shared with `entries_truncated` meant the opposite thing. The Recent Requests panel says the scan stopped early rather than that the URL is quiet, and the brief carries `- **scan:** stopped early — this list is partial` on its own line. `Ask_Assembler::for_url()` takes that flag and its server scope as REQUIRED arguments: a completeness claim that defaults to the reassuring answer is the same silent failure a defaulted config key is.

- **A rid lookup that spends the scan budget names that ending instead of reporting the rid as gone.** `find_request_index_entry()` (behind `request_search`) and `first_record()` (behind `request_detail`) both discarded `scan_index_entries()`'s bool, so a walk that ran out of entries threw `Request not found: rid=…` — a definite negative from an incomplete search, sending an operator after a retention bug that does not exist. Both branch on it now and throw `request index scan budget spent before rid <rid> was reached`. The flames half of `find_request()` deliberately still treats a miss as normal, budget or not: an unprofiled request has no flame record.
- **An `url:` brief with no worst-recent rows renders as a sentence.** The scan note was concatenated onto the joined list, so the empty list the note exists FOR emitted `- **worst recent:**  (scan stopped early)` — a label whose whole value is a parenthetical, with a doubled space, which `fields()` could not drop because the concatenation had made it non-empty. It is its own pair now, `- **scan:** stopped early — this list is partial`, and an empty list drops its label the way every other empty field does.

### Changed
- **`url_detail` and the `url:` brief name the window their request list is of.** An empty `requests` with `scan_stopped_early: false` read two ways — no traffic, or no traffic since the window opened — and only `docs/API.md` said which. Both replies carry `requests_window_start`, the walk's own floor, and `Ask_Assembler::for_url()` takes it as a REQUIRED argument beside `$server` and `$scan_stopped_early`: a narrower number that does not say so reads as the site's. The `url_detail` MCP summary says it too, and the rendered brief carries `- **requests since:**`. `docs/API.md` also stops promising the retention setting: `Stats_Store::window_start()` caps at `MAX_READ_BUCKETS` (288 five-minute buckets), so above a `min_lifetime` of ~86,100s the reach is 24h whatever the setting says.
- **`Request_Builder_Node::EVICTION_WINDOW_SEC` is `DEFAULT_EVICTION_WINDOW_SEC`, and a topology that moves it says so.** The constant multiplies `DEFAULT_NUM_BUCKETS`, but `build_cache()` arms rotation with `$this->num_buckets` — positional arg 1, declared per topology — so the name promised the live window and measured the default one. `Stats_Store::MAX_FUTURE_SKEW_SEC` borrows that magnitude and cannot follow a declaration, so the name now says which one it is and `build_cache()` warns, once per window, when a declaration moves it. The shipped topologies pass the default, so nothing warns today. The retention walk no longer reads it at all — it compares completions, which need no in-flight allowance.
- **The per-URL request walk stops at the retention edge instead of spending the whole entry budget.** `scan_index_entries()` had one ending short of `MAX_INDEX_ENTRIES`: a caller declaring itself satisfied. `find_recent_requests_for_url()` only ever declares that at `RECENT_REQUEST_LIMIT` (500) hits, which the long tail of URLs — the ones an operator actually opens — never reaches, so `url_detail` read the entire index on every poll of a modal that `urldetail:timer` re-asks every 15 seconds. At 97 bytes an entry that is ~97MB of `.idx` per tick to answer for a URL with four hits. The walk now ends each partition at `scan_floor()`, the floor of the same window every other panel in that modal is drawn from, so its cost tracks the retention window rather than the index. `MAX_INDEX_ENTRIES` becomes the backstop it reads like, and keeps the two walks that have no time to compare: a rid lookup, which cannot know how far back its one line sits, and the flame index, whose lines carry no timestamp at all. Two facts have to agree before anything ends, because either alone truncates. The LINE must have COMPLETED before the window — `timestamp` is a request's START, and a `nuclear-cron` job that runs for four minutes, or any request logging often enough to be promoted in the `LRU_Cache` and never evicted, appends a line carrying a start from hours outside the window it finished inside; completion is `timestamp + duration_ms`, both zero-padded on the same line, and needs no eviction slack. The SEGMENT must have taken no index line since the window opened, which is the one clock the reader owns: the hub sinks every spoke's `Remote_Source` into one `firehose:topic`, so its append order is ARRIVAL order across producers and a spoke reconnecting after a lag lays hours-old lines between live ones. One `stat` per segment answers that, through the new substrate accessor `Partition_Node::index_mtimes()`. A line whose time column reads 0 — a zero or non-numeric `ts` from a spoke, which `Core::as_int()` casts leniently — ends nothing either. The bound stops the walk; it does not filter the answer, so an entry the walk reaches is returned whatever its time. One consequence to know: `url_detail`'s `requests` now reaches back one retention window and no further, where before it returned whatever the index still held — and the reply says which window that is (below).
- **The disk-walking scan budget is a million index entries, not a hundred thousand, and a miss costs one column comparison instead of a parse.** The floor is a full retention window of requests rather than a round number, because a budget spent on one URL's high-traffic neighbours never reaches the URL. What made a million affordable is that `scan_index_entries()` now compares the matched field's RAW column before parsing: on a walk that spends its budget essentially every line is a miss, and a miss was building an 11-field entry — ten `substr`, two `trim`, an `array_flip` lookup, an `in_array`, an 11-key allocation — to compare one of them. Both index formats put `rid` and `url_hash` at fixed offsets, and the offsets come from the writer that laid the line out (`Request_Builder_Node::index_column()` / `Flame_Builder_Node::index_column()`, beside each format's own `format_index_entry()`), never a third hand-kept table; a field with no raw-comparable column answers `[]` and its scan falls back to the parse. The full check still runs behind the filter, so it can only skip work, never change an answer. Measured over a million lines: 2.05s of parsing becomes 0.20s. At 97 bytes an entry a million lines is ~97MB of index read one segment at a time, so peak memory is one segment's index — a few tens of MB — not the whole file. Spending the budget is now reported rather than silent, which is what makes the number a backstop instead of an invisible cut-off.
- **One resolver decides which series the aggregate chart draws, and every wrapper gates through it.** The chart resolved `breakdownData ?? data`, which does not fall back on a non-null EMPTY breakdown — and the server sets `breakdown_time_series` whenever the dimension is VALID rather than when it has rows, so `{}` reached the Overview card holding a populated series and the chart drew nothing under live dropdowns. `BreakdownControls` meanwhile gated on `! empty( series ) || ! empty( breakdownData )` and kept the panel up, so the wrapper and the chart it wraps held different opinions about what there was to draw. `chartSource( { data, breakdownData } )` is exported from `AggregateTimeChart` and answers both questions once: a dimensional series wins when it has content, an empty one falls back to the totals, and `null === source` is the one "nothing to draw" predicate — which retires the duplicate `empty()` in `BreakdownControls` and the third copy in `CategoryTimeChart`. It walks with `for…in` rather than `Object.keys().length`, because the URL modal re-renders on every scroll event and that is a key array per frame. The dimensional test is `hasDimValues`, not `hasBuckets`: a dimension key that merges to an empty array leaves `{ '<bucket>': {} }`, which has a bucket and no series — so the resolver used to report a dimensional source for something the chart drew nothing from, and the wrapper drew a headed panel around it. Emptiness is now the SAME question on both sides, which is what the invariant claims.

- **The URL modal's breakdown chart asks `url_breakdown`, a verb that reads memcache and nothing else.** It fetched `url_detail --breakdown=<dim>` and kept only `result.breakdown_time_series`, but that handler runs `row()`, `find_url_aggregate()` and `find_recent_requests_for_url()` unconditionally first — so every breakdown fetch paid a full index walk it discarded, on modal open, on every dropdown change, and every `BREAKDOWN_REFRESH_MS` the modal stayed open. Opening a modal was two full walks. `url_breakdown` takes a hash and a dimension, both REQUIRED, and returns `{ breakdown_time_series }` alone; an unknown dimension throws rather than answering an empty payload, because a chart with nothing to draw and no error spins. It is deliberately NOT added to `MCP_Controller::TOOLS`: an agent asks once and `performance_url_detail` already returns the same series alongside the context it would want next, so a second tool would only cost tool-selection accuracy and rate-limit budget for a strictly poorer answer.

### Removed
- **`Stats_Store::url_row_sources()`'s `$url_hash` parameter is gone.** `build_url_time_series()` was its only caller, and decision 14 requires the two survivors to read the window unscoped — one unscoped read serves every scope a request asks for. The single-shard read it offered went with it, along with the test that pinned it.
- **The URL modal no longer draws an undifferentiated "Total" series while it waits for its breakdown.** `url_detail` built `stats.time_series` on every open — a full shard-set scan across every bucket in the window — and the chart drew it until the breakdown reply landed and replaced it. Nothing asked for it: `CHART_BREAKDOWN_OPTIONS` has no "None", the dropdown defaults to `status`, and the breakdown read retries a missing reply on its own, so no user-reachable state wanted the undifferentiated line. It was also lossy in two ways the breakdown is not — it skipped any bucket whose row had fallen out of the capped `urls:{shard}:{bucket}` index, and any bucket the server scope could not be swapped into — which drew six hours of a 24-hour window as though that were the traffic before silently replacing it. `Performance_CI_Node::build_url_time_series()` and the `stats.time_series` key are gone; the header figures beside them are unchanged.

## [0.59.0] - 2026-08-24

### Added
- **The URL index carries a per-server split, so the URL table can be scoped by server.** Each `urls` bucket row gains `srv`: its SUMMED fields — count, timed count, sum_ms, sum_peak_mb and the four status counters — per reporting server, capped at `Stats_Store::MAX_SERVER_VALUES` by the same ranked `Other` fold the `server` dimension takes, applied once after the row cap has folded its tail — the fold rows are what mix hosts, since a stored `url` is absolute. It is not new measurement; `url_dim`'s `server` dimension already held it, keyed per URL, where scoping a 12,000-URL index by server would have cost 12,000 gets. Co-locating it with the row makes the scope readable in the multi-get the reader already issues. Extremes and percentiles are deliberately absent from the split: `min_ms`, `max_ms` and p50/p95/p99 come from a sampled reservoir and do not add, so a scoped row keeps the URL's own across every server. A `urls_s` namespace — the shape decision 1 would otherwise imply — was rejected because `MAX_URLS_PER_SHARD` applies per KEY, growing a namespace that is mirrored in FULL by O(servers × 500) rows per bucket, on the exact axis decision 11 names as the one that outgrows any fixed checkpoint budget. Recorded as architecture decision 14.

### Changed
- **`Request_Builder` and `Flame_Builder` declare their extra destinations instead of each implementing the fan-out getter.** Both overrode `target()` to widen the console's TARGET column past the primary — the errors / alerts / completed routes and the Flight sibling's target on one, the stats-mirror partition on the other — and the tail of the two overrides was the same twelve lines: normalize the primary to a list, append each extra once, return it. The substrate's `Node::display_targets()` owns that now, so each class declares only the fields it contributes through `extra_targets()` and neither spells the dedup, the empty-skip or the scalar-to-list normalization again. The union is a separate accessor from `target()`, which stays the routing contract — so `Core::str( $this->flight?->target() )` reads a scalar and cannot silently return `''` for an array. Requires newspack-nodes with `extra_targets()` and `display_targets()`.
- **In-Flight heads its table and labels its toolbar through the shared log-table module it already imports.** It drew its own `role="row"` header with per-column `columnheader` spans — a third implementation beside `logListHeader()` and the substrate's `LogListHeader` — and spelled out the count and rate spans that `countLabel()`/`rateLabel()` were extracted for, under the same classes. All three tables head themselves through one call now, and the `--stream-grid-template` custom property carries the layout on In-Flight too, so the pane dashboards and the paneless one publish their grid the same way. `countLabel()` takes the plain form first and the shown-over-held form last, because a table that filters nothing — In-Flight — names no split; `gridTemplate( columns, order )` is exported beside it, replacing the Error Log's hand-rolled width join and feeding the header. In scss, the `display:contents` columns wrapper is `log-columns()`, included by the log-stream page mixin and by In-Flight, whose bundle renders no `LogRowList` and so still carries the header's own padding and font size. The compiled CSS for all three tables is unchanged.
- **The lazy flame graph's Suspense fallback is the shared `LoadingFallback`.** It wrote its own loading div beside the component that renders exactly that canonical class, so the trace showed a bare line of text where every other loading state shows a spinner. The compact geometry that flattens the shared container's full-height centering now hangs off `.event-logger-flame-container`, and the bespoke `event-logger-detail-loading` class is gone.
- **Both time charts open their frame through the substrate's `openFrame`.** The area chart and the response-time scatter each wiped the container, measured `clientWidth || 800`, subtracted the margins and appended the sized `svg` and translated `g` by hand, as the substrate's Topics panel did — the piece the module holding `MARGIN`, `drawAxes` and `drawLegend` was missing. Each keeps its own scales, whose domains genuinely differ. The emitted markup is unchanged, pinned by a characterization test on the element order, transform and width/height attributes.
- **Both time charts draw their axes through the substrate's `drawAxes`.** The area chart and the response-time scatter each carried the same frame — time axis, 8-tick cap, rotated labels, 5-tick value axis, rotated Y title — so a spacing or format fix reached one of them. `AreaTimeChart` computes its Y scale before drawing rather than between the two axes; the marks land in the same order as before.
- **`Request_Builder` and `Flame_Builder` publish their hidden sibling instead of implementing four cascades for it.** Each carried its own name-collision pre-check, rename cascade, sink cascade and teardown cascade for one sibling — `flight` and `auto-tuner` — byte-alike apart from the suffix, and alike again with the three `Consumer_Node` runs in the substrate. Both are one `publish_sibling()` call now, made from the constructor that builds the sibling, and the substrate's `Node` runs the quartet off the map that publish writes. That ordering is the point: both build in the constructor, before the node has a name, which under the substrate's previous declare-then-publish model was the one shape a forgotten declaration left silently inert. `Flame_Builder` drops the `$auto_tuner` property with the declaration — nothing read it, and the auto-tune message is addressed through the substrate's `sibling_name( 'auto-tuner' )` rather than a hand-spelled `{name}:auto-tuner`, so a re-keyed slot cannot leave it routed at a name nothing holds. `Request_Builder` reads the Flight sibling's target through `flight_target()`, shared by `dump_config()` and the console fan-out, both of which are introspection a torn-down sibling must not fatal; it is the substrate's `Core::str()` passthrough the class already reads other nullable values with, not a second hand-written null-then-string dance. The two `set_inflight_*` verbs deliberately keep the throwing `flight()` — a verb refuses by throwing, which the substrate wraps as `TM_COMMAND|TM_ERROR`, where a refusal string would report success for a setting nothing held.
- **The index scans name the formatter they read through.** `Performance_CI_Node` handed its scratch Partitions a first-class callable, because the test harness never replayed the plugin's `Formatters::register` calls and `with_index_named()` therefore resolved nothing under test. The bootstrap replays all three — `request-index`, `flame-index`, `stats-index` — so the scan names the same formatter the `.tsl` `with_index` legs do, and a stock topology naming one nothing registered now fails a test instead of silently writing no index.
- **The Overview card and the URL modal share one set of performance panels.** Both spelled out the same breakdown-chart controls, the same three category charts and the same profile-with-caption block — 29 duplicated windows between two files, where a fix to one reached the other only if someone remembered it was there. Each panel lives beside the thing it draws: `overview/components/BreakdownControls` holds the chart and the selectors that drive it, `RequestProfile` exports the captioned wrapper `ProfileWithCaption`, and the three category views collapsed into `CategoryTimeChart` itself, which now takes a series and draws all three rather than one mode named by its caller. What differs between the two scopes is a prop — the Server selector, the loading note, the heading, the server-scoped caption — so neither carries a second copy of a panel to hang its one extra on. Every translated string stays a literal at its own call site, and the overview's chart spacing moved from an inline style to the stylesheet that already owns that card's layout.
- **The three streaming dashboards share one page shell.** Gyroscope, the Request Log and the Error Log each repeated the same `ThemedRoot` + fixed-position box + debug overlay, differing only in which axis scrolls and which storage key the overlay persists under. `components/DashboardShell` owns the chrome; `overflowY` is required rather than defaulted, because a page whose body owns its own scroller must clip and one without must scroll, and guessing wrong is a double scrollbar or a truncated page.
- **Both picker modals wear one set of chrome.** The hook picker and the custom-event picker each spelled out the same framed dialog, header search box, actions row and scrolling body, under two sets of class names whose declarations matched everywhere but the list geometry. `SelectorModal` owns the frame and its product classes now, and one `selector-modal.scss` owns the geometry: the frame carries `event-logger-selector-modal` beside its own class, and the hook picker's wider frame is a modifier on it rather than a second copy of the block. Each picker keeps what it lists, and every translated string stays at its own call site. The hook picker's dialog header picks up the padding and heading size the custom-event picker already had, so the two read alike — and `rule-edit-modal.scss` no longer declares `.event-logger-selector-modal` geometry beside its own. The rules editor opens both pickers under the dashboard skin, and that arm out-specified the shared chrome's header, so one component still drew two dialogs: a 64px header with an 18px heading from the rules editor, the picker's own 16/20 header from the settings page. One stylesheet owns the block now, and the test resolves the compiled cascade against the rendered header rather than matching source text, so a tie broken by import order fails instead of reading green.
- **The URL modal's header stats are one table, not six hand-spelled spans.** Each differed only in field, precision, unit and label, so a seventh meant copying nine lines; `headerStats()` returns `[ text, label ]` pairs and the header maps them. Memory is still dropped when nothing measured a peak.
- **Registering a dashboard's view class no longer costs its own file.** The Request Log, Error Log and Gyroscope each carried a two-line `nodes/register.js` re-exporting one class; `registerNodeClasses` returns its map, so the registration sits at the foot of the view node it registers, as the performance dashboard's already did.
- **A completed-request record no longer carries an `initialized` flag.** `Request_Builder_Node` stamped it onto every envelope at `process (start)` and nothing in the plugin, the dashboards or the tests ever read it, so it rode `(array) $request` onto the wire into `requests.p{N}` as pure weight. Removed, along with the duplication behind it: the two hand-kept HTTP-method tables on the index writer and reader are one `METHOD_CODES` constant read back through `array_flip()` — a method added to one side used to decode as a bare letter on the other — and the save/restore bucket walk, the folded keep and tail buckets, and the two sequence-break markers each collapse to one implementation.
- **The URL table's eleven columns are declared once, not spelled out twice.** The header held seven copies of a sortable button and four of a status heading; `UrlRow` then respelled the same eleven as cells. Nothing tied the two lists together, so inserting a column in one and not the other shifted every cell after it under the wrong heading — the numbers still rendered, just against the wrong label. One `COLUMNS` table now drives both marks, each carrying `data-field` so the alignment is asserted rather than assumed, and the pager renders its wrapper and row-count span unconditionally instead of duplicating them across two branches of an IIFE.
- **The response-time scatter plot draws through the shared `useTimeChart`.** Its four hand-rolled effects — build-once skeleton, keyed enter/update/exit join, a resize handler repeating the whole update, and an unmount teardown — became one memoized render function, and it picks up container-resize refitting, which a window listener misses when only the detail panel moves. The join preserved nothing: no transition was ever configured, and every `<title>` was removed and re-appended on each pass anyway. Its private margins, `%H:%M` tick format and circle legend give way to the shared `MARGIN`, `formatXTick` and `drawLegend`, so it lines up with the two charts beside it. Individual requests on a continuous time axis, click-to-open and status-class colouring are unchanged. It also renders once data arrives rather than only when mounted with data already present.
- **One request summary and one flame-trace section, not three of each.** The URL/Time/Duration/Memory/Status rows and the lazily-imported flame graph were written out separately in the performance dashboard's request detail, its URL detail and the current-request overlay, and had already drifted: only the overlay guarded a missing timestamp, so a record without one dated the request to 1970, and only it marked an errored status row. `components/RequestSummary` and `overview/components/RequestTrace` now own both, with wording that differs per caller (`statusNote`, `errorRow`, `title`) passed in already translated. The overlay's private `eln-current-request__flame` / `__chart-loading` classes are gone; the trace ships its own stylesheet, so every bundle rendering one carries the compact loading geometry rather than each redeclaring it.
- **The profile breakdown's callback sub-table is its own component, and its layout is CSS.** Nested twelve levels deep inside `RequestProfile`, it put each style key and even a bare `3` on its own line. It is a top-level `ProfileEntries` now, and the column widths, cell padding, name truncation and right alignment moved into `request-profile.scss` — where RTL also flips them, which inline styles never did.
- **Both Performance time-series charts draw on one `AreaTimeChart` frame.** Axes, areas, tooltip and legend were three near-identical copies — `AggregateTimeChart`'s stacked branch, its overlaid branch and `CategoryTimeChart` — differing only in how the series were derived, the formatter, the y-label, the height and the color source. Each chart keeps its own sampling and its own literal `__()` labels and passes them in; the frame carries no wording. Four small visuals move with it: the overlaid axis pads its peak by 1.1 rather than 1.2, an all-zero window tops out at 1 instead of 10 or 100, the memory tooltip drops a trailing `.0` to match its axis, and the aggregate wrapper reserves 280px instead of 284px before first paint.
- **The three log dashboards draw their table from one module.** In-Flight, the Request Log and the Error Log each carried their own copy of four mechanisms: the column dictionary (`remote_addr` and `user_agent` declared identically in two, `rid` and `time` in three), the seven shared cells, the `renderCell` fallthrough guard, the grid-template header wrapper, and the count/rate toolbar labels. `src/log-table/logTable.js` owns all of them — not `src/components`, because it exports no component but the cell primitive, and it sits beside the three dashboards it serves as a declared bottom layer in the `import/no-restricted-paths` zones. Each dashboard now declares only its OWN columns, its own cases and its own nouns: In-Flight's state, what, age and lag; the Error Log's keyword and message. The cell helpers take explicit values rather than a row, because the three spell the same quantity differently (`est_ms`/`time_ms` vs `duration_ms`, `ts` vs `timestamp`) and In-Flight hashes the URL client-side where the other two read a hash their view node stamped. The fallthrough guard is now structural: `cellRenderer()` draws a placeholder for a declared column with no case, so a dashboard cannot omit the branch and shift every later column one slot left. `_n()` still takes literals — the count and rate labels arrive already translated from each call site — and the Error Log's rate label becomes translatable in passing. Visible consequences: the Request Log's `status` column is keyed `status_code` now, matching the field every row already carried and every other shared key, so a saved column selection naming `status` drops it once; the Request Log and Error Log URL cells gain the full URL as a tooltip and the click-to-URL-stats wording In-Flight already had; the request-id tooltip reads the same on all three; and both request tables leave the URL cell empty rather than drawing a bare method beside an empty link when a row carries no URL.
- **The URL table hides worker traffic by default, with an Include Workers toggle beside Errors.** `$count_global` has always kept workers out of every site-wide aggregate — "one long-running worker would dominate the site-wide averages" — but the per-URL row deliberately keeps their timing, so a header summed from that index inherited them. On a staging hub that meant `227ms Avg Response` measuring the job queue against `3ms` of reader traffic. The table now excludes them and the header agrees, which is the whole point of the header describing the set below it; the toggle opts IN, the mirror of `errors_only`, which opts in to narrow. Worker-ness is RECORDED on the row rather than derived from the `?worker_type` marker in the URL text: the producer knows it directly, the marker exists to keep worker traffic off the visitor's URL row rather than to be parsed back out, and a row is the only place a per-row filter can read. The capped tail folds into two overflow rows, because one cannot answer a filter about a set that mixes both.
- **The URL index is sharded: `urls:{shard}:{bucket}`, sixteen shards by the first hex digit of the url_hash.** One blob per bucket was what every cap in this schema was defending — row count, duration reservoirs and per-server splits all competed for a single memcached item's budget, and the answer to each was another ceiling. Sharding puts that cardinality in the KEYSPACE, which is what decision 1 says every other scope does and what memcached is for. Concretely: no single item approaches the 1MB limit (a refused write loses a sixteenth of a bucket, not all of it), the per-shard cap admits sixteen times the URLs before it binds, a point read for one URL — `url_detail`, `ask`, the per-URL chart — costs one shard instead of the whole index, and the durable mirror carries sixteen smaller frames per bucket instead of one oversized one. What it does NOT buy is write amplification: with sixteen shards a flush carrying *k* distinct hashes touches `16 × (1 - (15/16)^k)` of them — 7.6 at k=10, 16 at k=100 — so at any real flush width every shard is written and the total bytes are unchanged. The bucket stays the LAST key component, so `is_open_bucket()`, expiry and the flame-stats read-through are untouched, and every shard plus the legacy shape still arrives in ONE `lookup_multi` per partition, as decisions 1 and 6 require.
- **The per-URL server split takes the same ceiling as the `server` dimension.** Capping it at `MAX_URL_DIM_VALUES` (10) recreated, one layer down, exactly the defect this release removes from the `server` dimension: past the cap a real server folded into the synthetic `Other`, a scoped read matched nothing, and the URL left the filtered table and its totals silently. Same reasoning, same answer — one ceiling, `MAX_SERVER_VALUES`, wherever the axis is stored, set far above any fleet in evidence so no real server folds, with the per-shard row cap and the refused-write log still bounding bytes. One ceiling, not three.
- **The URL index's per-shard cap no longer costs the totals anything.** Past `MAX_URLS_PER_SHARD` (500 rows per shard, so 8,000 per 5-minute bucket across the sixteen) the tail was DROPPED, so every figure summed from the index was a lower bound by however much traffic fell off — which mattered the moment the Overview header started summing it. The tail now folds into one synthetic `Other` row carrying only the fields that add, per-server splits included, so the header is exact site-wide and under a server filter alike. The row is marked `aggregate` and renders un-clickable: it stands for many URLs, its key is not a url_hash, and `url_detail` cannot answer for it. It is excluded from a `search` by that flag, not by its blank `url`, because a folded row cannot be known to match a term.
- **The mean is no longer stored on every URL row.** `persist_url_index()` wrote an `avg_ms` nothing read — the reader owns that division, and it divides by `timed_count` — so the write side was a second copy of the untimed-fraction rule with a different fallback trigger, on a field occupying every row of the largest blob the schema keeps.
- **The `urls` reply separates the pager's count from the headline's.** They answer different questions: the folded row is a row the server will slice but is not a unique URL, so paginating on the semantic count left the last row unreachable on any set whose row count landed just past a page boundary. `rows` is the sliceable count; `totals.urls` stays the semantic one.
- **The folded row carries no stored label.** A string authored at the write side is untranslated, persisted for a retention window, and live data — the search filter matches on `url`, so a term like "other" would have pulled the synthetic row, and its entire folded tail, into a filtered total. The client names it from the `aggregate` flag.
- **The URL table's page follows the set down.** Narrowing a filter could leave it on an offset past the end, where below one page the pager is not rendered at all and no control remained to escape an empty body. The page is clamped on read rather than synced through an effect, so a shrinking set cannot strand it.
- **The Ask brief reads the echoed scope, not live state.** `pageFacts` already used the filters the server reported applying; the picker used React state, so changing the select and asking before the poll returned assembled a brief for one server beside a facts block naming another.
- **Every URL-set number now comes from the one verb that applies the filters.** Unique URLs, Total Requests, Avg Response, Req/s, Avg Peak Memory, the slowest ten and the busiest ten all come off the `urls` reply, summed from the rows its own server and search filters left — so the header, the table beneath it and the Ask brief cannot disagree, and a filter matching nothing reads as an empty set instead of as zero traffic. This replaces v0.58.4's "Unique URLs (all servers)", which was the right answer to the wrong question: a URL row carried no server, so that count stood apart from every stat beside it. It carries one now. `overview` sheds `total_urls`, `slowest_urls` and `most_requested` — the last of which was always the `urls` default page under another name — and keeps only what the URL index cannot answer. The client-side per-server summation and the `globalRequestsPerSecond` memo are deleted; `total` on the `urls` reply is gone, and `totals.urls` is the same number under one name. Recorded as architecture decision 15.
- **The URL modal was showing site-wide averages under a server-scoped row.** `url_detail` never took the scope, so clicking a filtered row opened a panel whose count, mean and percentiles were every server's — the same defect one click in, and worse, because the two numbers sat on surfaces too far apart to notice the disagreement. It takes `server` now, like the table it opens from.
- **The modal's Req/s and the header's Req/s were computing different things.** Both are labelled "req/s", but the client divided this URL's traffic by the buckets that happened to carry any, while the header divides by the fixed hour — so a URL seen in two of the last twelve buckets read six times its rate. One owner now: `RECENT_BUCKETS` × `Stats_Store::BUCKET_SECONDS`, dropping the bucket still filling, for every rate on the page. On an install with less than an hour of history this reads lower than before, which is what "last hour" means.
- **The `urls` verb honours `--server` for real.** v0.58.4 removed the option because it had been substring-matching the SERVER NAME against the URL TEXT, which emptied the table for every site whose URLs do not contain their own host name. It is back, scoping through `srv` as a projection over the merged row — so one unscoped read serves every scope a request asks for, where scoping inside the loader made a filtered poll pay the whole retention-window fan-out twice. The reply also echoes the `filters` it applied — server, search and the errors flag — and the Ask brief carries them on every surface, so a narrower number never reads as the site's. A legacy row written before this release has no `srv` and drops out under a server filter rather than being counted as unscoped.
- **The Time Breakdown's denominator is now a prop of its own, not a leftover field on the headline stats.** Its categories come from `build_leaderboard( server )`, so it divides by that server's average — the site's when unfiltered. Unchanged behaviour, but it no longer rides on an object whose other fields moved to a different scope.
- **Decision 14's reopen condition asked the refused-write log a question it cannot answer.** It said to revisit when that log "names a `urls` shard rather than a per-server leaderboard" — but the log lives inside the URL-index write path and can name nothing else, so it was satisfied by its own first line, which decision 11 in the same file calls fatal: a tripwire that is always tripped tells you nothing. It now reads on the one thing the log CAN distinguish: a refusal at a row count well UNDER `MAX_URLS_PER_SHARD`, which is bytes per row — rows × fleet — rather than row count doing its job. The answer is still a byte-derived row cap, not a value count.

### Removed
- **The shared clock moved to the substrate, and `src/utils` went with it.** `formatTime` was one function in a directory of its own, imported by one file — which already imported `formatDuration` and `getDurationClass` from `@newspack-nodes/shared/utils/formatUtils` on the line above. It lives there now, beside `formatLocalDateTime`, which is the one to reach for when a record is hours old rather than arriving. Deleting the zone also removes the `import/no-restricted-paths` block that existed to admit it and the `./utils` exception in the two remaining bottom layers.
- **The seven shared log cells spell the table's a11y contract once.** Each was its own five-line `role="cell"` wrapper around one expression, so the base class and role had seven places to drift; one local `Cell` takes the modifier and the content.
- **Both picker modals name their frame class through one prop.** `modalClass` and `className` were concatenated adjacently into the same slot and both call sites passed both. The tests behind them asserted on source text and broke on a rename that changed no rendering; the two that could became render assertions.
- **`derive_hub_topology()` is a method again.** Inlining it into `has_hub_topology()`'s `try` block left a `foreach` a tab short of its own body, two `return true` rewritten as a static assignment plus `break` and `break 2` through a nested `try`, and five levels of nesting to say "either signal fires".
- **`marker_entry()`, `url_dim_cap()` and two dead `first_record()` parameters.** The first was twelve lines of method and docblock wrapping a four-key array literal its two callers already supplied in full, and naming nothing at the call site; the second differed from `dim_cap()` only in which constant it passed, so the server axis's special case lived in two places; `first_record()`'s `$field`/`$match` pair took `'rid'` and the rid at both call sites.
- **Two write-only fields left the completed-request record.** `process_id` and `host` were parsed off the `process (start)` line by a `preg_match` on every request and read by nothing in any of the six plugins — they only ever rode the wire record into `requests.p{N}`. An operator reading that JSON raw will no longer find them.
- **The Request Log's `urlHash` keyed the display clip, not the URL.** A URL past the 2000-character clip hashed to something the Overview has never stored, so its deep link resolved to nothing. It hashes the full string now, which is what PHP's `Log_Manager::url_hash()` and the Error Log's view node already did — the Error Log's docblock even said so, while the Request Log's code did the opposite.
- **Every legacy, back-compatibility and migration path.** The unsharded `urls` read-through; the pre-`frames` checkpoint adapter; the row-shape migration on restore; `Log_Manager::$enabled` (the pre-0.46.0 profiler drop-in mirror — both shipped drop-ins gate on `is_started()`); the `sum_req_time` seconds fallback; the untimed-row denominator fallback; and `parse_request_index`'s v1–v4 width tolerance, which now reads one width and refuses a short line as the truncated record it is. **That last one is a data-compat break:** an `.idx` segment still on disk in the shorter v1–v3 layout no longer decodes, so those requests drop out of the rid search and the per-URL recent list until their segments roll.
  **Upgrading costs one retention window of URL history.** Sharding renamed the keyspace from `urls:{bucket}` to `urls:{shard}:{bucket}`, and with the read-through gone the old keys are unreachable in both memcache and the durable mirror. The URL table, its header totals and the per-URL charts read empty until the window rolls (`min_lifetime`, 12h by default). Site-wide totals, the leaderboard and the Time Breakdown are unaffected — they live in other namespaces. This is the schema bump decision 5 describes: `Cache_Backend::rotate_salt()` is the one button, and it moves every plugin's scope at once, so reach for it deliberately rather than to shorten this window.

### Fixed
- **Decision 1 and the architecture guide still said the `server` axis is uncapped.** The canonical caps list is where an engineer adding a dimension looks, and it documented the pre-cap contract — enough to read `cap_servers()` as contradicting the design and delete it. Both now record why the exemption existed and why it failed (`server_name` is `$_SERVER['SERVER_NAME']`, the client's Host header under Apache's default `UseCanonicalName Off`) and why the ceiling is 128 rather than `MAX_DIM_VALUES`' 20 (20 folded real spokes out of a 24-spoke fleet's own picker). One tripwire for the ceiling, stated in decision 14; decision 1 points at it. `dim_cap()`'s own docblock, both `.claude` skill cap lists and two contradictory entries in this section carried the same claim and are corrected with it.
- **`set_inflight_delta false` disables delta mode instead of enabling it, and the setting survives its sibling.** The verb declared a `bool` argument and then parsed it by hand as "any non-empty string that is not `0`", so `false`, `off` and `no` all read as ON — the opposite of what an operator typed and of what the substrate's `truthy()` accepts everywhere else. It is a declared `toggle` now, so the substrate synthesizes both the handler and the dump line, and the flag lives on `Request_Builder` rather than on the hidden Flight sibling it configures: `dump_config()` replays the node's own field, where before it queried derived state a teardown takes with it. Flight keeps no copy — it reads the toggle live at fire time, exactly as it already reads the in-flight map — so there is no second store to fall out of step when the sibling is rebuilt. Two visible consequences: the verb returns `ok\n` like every other declared setter, and the dump emits `set_inflight_delta true` rather than `1` (both replay identically, so an existing topology file needs no edit).
- **The `server` axis was unbounded, and overflowing it discarded the whole bucket.** `dim_cap()` returned null for `server` and the URL row's `srv` split was written uncapped, both resting on "the axis is the fleet, so there is no cardinality to guard". It is not: `server_name` is `SERVER_NAME`, which under Apache's default `UseCanonicalName Off` is the CLIENT'S Host header, so a domain-mapped multisite or any catch-all vhost taking Host-header spray grows the global `dim:server` item and the two synthetic overflow rows' splits without limit — toward the >1MB memcached item the shard split was introduced to escape, where `set_url_shard()` returning false discards the entire merged shard-bucket and the URL table zeroes out for that window. Both take `Stats_Store::MAX_SERVER_VALUES` now, through the traffic-ranked `Other` fold every other axis already uses, so a tail folds instead of dropping and every total stays exact. The ceiling sits far above any fleet in evidence — five times the largest hub, three times the widest per-URL split any test asserts — because a cap that binds on a real server is the defect this axis was left uncapped to avoid. Decision 14 is amended, its justification and its reopen condition both.
- **The Request Log's Status column vanished on upgrade, permanently.** The column key was renamed `status` to `status_code` when the table moved to the shared column set, while `event-logger-stream-columns` stayed put — so `restore()` kept the four keys it still recognised out of every existing user's stored six, and the write effect persisted the loss. It maps the retired key through `useColumnPicker`'s `aliases`, which keeps the rest of a customized selection rather than resetting it the way a storage-key bump would.
- **The checkpoint-budget tripwire dropped the field both its reopen conditions are stated in.** Decisions 11 and 14 both ask whether the largest dropped frame is a per-server leaderboard or a `urls` shard — a question about the frame's NAMESPACE — and since sharding both are opaque keys. `mirror_frames()` carries `ns` on every record and the log printed only `key`, so neither condition was readable from the log that states them. It now prints `ns/key`.
- **Teardown left `Request_Builder` pointing at its own dead Flight sibling.** The base cascade tears the sibling down but cannot null the subclass's property, so `flight()` went on handing back a node whose name, sink and patron were cleared — periodic in-flight snapshots emitted into nothing, addressable from nothing. It clears the slot after the cascade, as `Consumer_Node`, `Remote_Source_Node` and `Partition_Node` already do in the substrate, and `dump_config()` skips its two `set_inflight_*` lines when the slot is empty — the sibling whose config they round-trip is gone, and reading it through `flight()` threw `flight sibling not constructed` at any post-teardown introspection call.
- **The firehose Topic and the scan's scratch Partitions took their patron AFTER their name.** Both registered a `{name}:config` interpreter and then destroyed it — `Log_Manager::init_topic()` once per process, `Performance_CI_Node::name_scratch_partition()` once per partition scanned. The substrate refuses that order now; both set the patron first.
- **A request whose firehose had a hole was invisible in every dashboard.** `Request_Builder_Node` stamps `I` over a nominal completion whenever `gap_after > 0`, and `ERROR_STATUSES` has named it since — but the four JS readers each kept their own list of `F`, `T`, `A`: the URL modal's status cell rendered EMPTY, its "Errors Only" filter HID the request, the request detail drew no error row, and the overlay printed the bare letter `I`. `components/errorStatus` owns the table now — label and status tone per code — and all four sites read it, so the code the node writes is the code they render. Its test parses `Request_Builder_Node::ERROR_STATUSES` out of the PHP and fails when the two lists disagree, which is what shipped `A` and then `I` writable but unreadable. The labels are one wording across all four surfaces: the overlay's status note reads "Fatal error" rather than "fatal error", the URL modal's tooltips carry the detail badge's fuller text, and an unrecognized code still shows itself rather than being flattened into "ok".
- **The flame graph refitted once too often, and not at all where `ResizeObserver` is absent.** Its effect bailed on a missing observer, so a webview without one got a chart frozen at its mount width; and it acted on the observation `observe()` delivers immediately, costing a full d3 rebuild plus a restamp of every frame on mount and on each poll. It draws through the substrate's `useContainerRefit` now, which owns both.
- **The Ask brief's env block named a field the record no longer carries.** Dropping the `process (start)` parse took `host` with it, and `ENV_ALLOWLIST` still asked for it — so every brief silently lost the machine that served the request, on the one surface whose job is to be the number an agent quotes. It names `server_name` now: the same axis the URL table, the Time Breakdown and the brief's own totals are scoped by, so an agent reading `env` and reading `server` sees one fleet identity rather than two.
- **A response-time dot outside the HTTP status range was painted 5xx and left out of the legend.** The scatter plot recomputed its own status class from `Math.floor( status / 100 )` while every dot took its colour from the shared `getStatusCategory`, and the two disagreed above 599. It reads the shared one now, so the swatch list matches the dots it describes.
- **One unknown key threw away the whole saved column layout.** The In-Flight table and the Request Log each validated their persisted selection with `every()`, so removing or renaming a single column silently reset every operator's layout to the default six. Both now run on the substrate's `useColumnPicker`, which keeps the keys that still exist and re-imposes the declared order. The hand-rolled picker markup, its persistence effect and its toggle went with them, along with the four unguarded `setItem` calls that threw mid-render in a private window; the two refresh-interval dropdowns read through the substrate's `usePersistedChoice`, which owns the same read-validate-fall-back-persist machine one cardinality down.
- **The aggregate chart's legend sat 60px right and 20px down from the category chart's, on charts of identical width.** It was drawn into the translated inner group while being positioned in outer-SVG coordinates, so `MARGIN.left`/`MARGIN.top` were applied twice and the last legend entries ran off the right edge of the SVG. Both charts draw one legend now, anchored to the outer SVG, and a real-d3 test asserts the offset for each.
- **The worker marker double-appended on every URL that had no query string of its own.** Making the marker land on already-queried URLs traded one idempotency case for the other: the separator was recomputed from the URL being tested, so a URL first marked `?cron` was next tested for `&cron`, missed, and re-marked — `?cron&cron`, a different `url_hash`, splitting one worker's traffic across two rows and breaking the rule that the completed record and the gyroscope row agree. The marker is matched against either separator now, and the test covers both directions rather than the half that worked.
- **A single fresh row silenced the pre-upgrade scope guard.** The guard asked whether the INDEX carried a per-server split, and the first post-deploy flush answers yes while every older bucket stays split-less for the rest of the retention window — so the "URL not found" it was added to prevent came back for nearly the whole transition instead of none of it. Each row answers for itself now.
- **A checkpoint written before a row field existed crashed the first request into that bucket.** `restore_state()` merges the BUCKET over `empty_bucket()` and stops there, so per-URL rows come back in whatever shape the release that wrote them used. The accumulator now merges every row it touches over `empty_url_row()` rather than only seeding an absent one, so a row of any shape is widened before anything indexes it — `count( null )` is a fatal, not a warning.
- **The per-shard cap could ship two rows over its own ceiling.** It counted the real rows, but the two overflow rows are re-added afterwards either way, so a shard sitting exactly at the cap was written at `MAX + 2`. It counts what it writes.
- **A JSON `false` over MCP turned its filter ON.** `tokens()` renders a scalar with `Core::as_string()`, which makes `false` the empty string, and `--include_workers=` reads as true at the verb — so an agent explicitly excluding worker traffic got it included. Booleans render as `true`/`false`. Latent until this release, which put the first boolean on the tool table.
- **A worker hitting a URL that already had a query string took that URL's visitor traffic off the dashboard.** The worker-type marker that normally gives worker traffic its own URL row was appended only when the URL carried no query string of its own, so `/feed/?post_type=event` under a warmer landed on the visitor's row. Worker-ness is one flag per row, so with the new default that row left the table AND every header number — a far wider exclusion than the worker's own requests. The marker is appended with the right separator now, and re-emitting an already-marked URL is idempotent on the MARKER rather than on the presence of any query, which is what dropped it.
- **Only half the overflow rows were flagged as aggregates.** The fold emits `Other` and `Other:worker`, but the reader tested the first key alone: with workers included, the worker half rendered as an ordinary clickable row with an empty URL, counted as a unique URL in the header, and answered a click with "invalid hash format". One accessor on `Stats_Store` now owns both keys.
- **A URL with no timing at all dragged the set's average down.** `sum_rows()` fell back to a row's request count whenever `timed_count` was zero — the legacy-row rule — but a row where every request timed out has the same shape and no milliseconds to divide, so its requests joined the denominator alone. That is the untimed-fraction defect this release fixes at the row level, reintroduced across the set. It divides by `timed_count` alone; a row without one joins no average.
- **A scoped read of a pre-upgrade index reported the URL as missing.** For one retention window after deploy the index carries no per-server split, so `performance_url_detail` and `performance_ask` with `--server=` threw "URL not found" for a URL plainly on screen — and both tools advertise `server`, so an agent following the schema hit it. The single-row read now names the unanswerable scope, the guard the `urls` verb already put on its totals.
- **The pager and the header counted the same table two ways.** The pager pages over ROWS, which on a capped set includes the synthetic overflow rows, and labelled that count "URLs" — reading "502 URLs" beside a header reading 500. It names rows now; the two counts legitimately differ, so the label has to say which one it is.
- **The page-facts block dropped `include_workers`.** It hand-listed three of the four filters the `urls` reply echoes, and the missing one is the only filter whose DEFAULT removes data — so an agent got worker-excluded totals with nothing marking them as narrowed. It publishes the reply's echoed `filters` verbatim now, so a filter added to the verb reaches the brief by existing. Not a client-side default table either: ADR-15 gives that verb every URL-set fact, and before its first reply the brief reports the filters as absent rather than asserting a state nothing has confirmed — the same reason its totals stopped reading as zero.
- **Four of a 24-spoke fleet were unselectable, and the ones that were selectable were undercounted.** `MAX_DIM_VALUES` (20) capped every dimension including `server` — the axis the dashboard's server picker is built from — so on a fleet larger than twenty, spokes past the cap rolled into the synthetic `Other`. Because the cap applies per BUCKET by request count, *which* spokes rolled up varied bucket to bucket: a site could be missing from the picker entirely, or present and silently missing the buckets it fell out of. A cap is a cardinality guard, and 20 is a cardinality this axis legitimately exceeds. It therefore takes `MAX_SERVER_VALUES` (128) — far above any fleet in evidence, so no real spoke folds — rather than being left uncapped, which is the form that shipped first and which the entry above reverses: `SERVER_NAME` is the client's Host header under Apache's default `UseCanonicalName Off`, so the axis has no ceiling of its own. Without this the per-server filtering in this release does not work for a sixth of a 24-spoke production fleet.
- **The Ask brief reported every average as zero.** Moving the means onto the projection left `ask_url()` reading `load_index_default()` raw — the loader emits sums and leaves the division to the reader — so `Ask_Assembler::for_url()` and every `Findings` computation downstream of it got `0` for `avg_ms`. That reached the Ask panel and the MCP `performance_ask` tool, on the one surface whose whole job is to be the number an agent quotes. It reads a display row now, through the same single-row projection `url_detail` uses, which also stops both of them building a second full copy of the index to read one row out of it.
- **"Avg Response" read low by the untimed fraction.** Moving the headline onto the URL index changed its denominator: `hourly`, where it used to come from, increments its count ONLY for a request that recorded timing, while the URL row counts every request and sums milliseconds for the timed ones alone. A site where a tenth of requests time out or report zero duration therefore showed a mean about a tenth under the truth. It divides by `timed_count` now, carried through the merge so the row shape no longer differs by scope.
- **Selecting the `Unknown` server emptied the URL table.** WP-CLI and cron requests carry no `SERVER_NAME`, and the `server` dimension keys those under the literal `Unknown` — which is where the dashboard's picker gets its options. The URL row's split wrote nothing for a nameless producer, so choosing `Unknown` matched no row and rendered an empty table with em dashes for every headline stat, beside a Time Breakdown still showing that traffic. The two axes agree on the name now.
- **A refused URL-index write was silent.** That blob is the largest the schema writes and the only one carrying a per-server split on every row; memcached refuses an item over its limit, and the discarded return meant the whole bucket's URL rows were lost for that partition — and again on every later merge into it — with nothing said. It now reports the row count and the size, rate-limited.
- **The `ask` verb dropped a `--context=` it declares.** The declaration was added so the MCP tool table and the verb agreed, but the handler read context only from the positionals — so a caller following the schema got "missing context" for doing exactly what it described. It reads the option too.
- **`server_name` was coerced two different ways.** The URL row's split read it with `Core::str` (empty for any non-string) while the `server` dimension the picker is built from read it with `Core::as_string` (stringifies scalars), so a non-string scalar could be offered in the dropdown and match no row — the `Unknown` defect by another route. Both axes read it the same way now.
- **The category brief answered site-wide while its card was scoped.** The Time Breakdown an operator clicks Ask from renders `build_leaderboard( server )`, but the brief behind it read the unscoped board. Scoping was threaded for `url:` only; it reaches `category:` now, and the brief names the set its means were taken over ("recent window on edge-01") rather than leaving a scoped figure to read as the site's.
- **The Ask brief's re-fetch pointer dropped the scope its numbers were computed in.** A brief assembled under a server filter reported that server's count and mean, then handed an agent a `performance_url_detail` pointer with no `server` — so following the brief's own instruction returned the site's numbers for the same hash, with nothing to reconcile the two. The brief states its `server` now and the pointer carries it.
- **Searching a request id while a server filter was active could answer "URL not found" for a URL on screen.** A rid names one request; the filter is a browsing scope, and once `url_detail` honoured that scope a deep link landing outside it asked for a row the scope excluded. The search now clears the filter, so the navigation wins and the select visibly resets rather than erroring.
- **The page-facts block published zeros before the first reply.** `pageFacts` floored every absent total to `0`, so an agent reading the JSON during first paint saw an idle site while the header beside it correctly rendered em dashes — the same plausible-zero shape that hid the original defect. Absent reads as `null` there now.
- **The URL modal's chart was drawn from every server while its stats were one server's.** `url_detail` scoped its counts, means and percentiles but still built `time_series` from the whole index, so the panel rendered a scoped count above a chart summing traffic the count excluded. The series is scoped per bucket now, through the same swap the index uses.
- **A rescoped `url_detail` reply was being discarded.** The merge node drops any reply whose `last_modified` matches the one it holds, and that stamp is the URL's flame mtime — identical across scopes. Changing server therefore refetched and then threw the answer away, leaving the previous scope's numbers on screen. The refetch clears the merge first. Latent today, because the modal overlay covers the server select; live the moment anything else changes the scope while a modal is open.
- **The Ask brief answered site-wide while claiming a server.** `pageFacts` stamps the active filters onto every surface, so an unscoped brief handed an agent the site's numbers labelled as one server's — worse than not scoping, because the label makes them quotable. `ask` takes `server` now, the picker sends it, and the MCP tool advertises it.
- **`swap_url_server_sums()` widened a row instead of dropping it when its split was not an array.** `sum_fields()` skips a non-array value, so an `isset` guard left the swap empty and `array_merge` handed back the site's counts under one server's name. The index merge already normalizes, so this was reachable only by a direct caller — which is what the method is.
- **The MCP tool table had drifted from the verbs it fronts.** `performance_overview` still advertised "slowest URLs, most requested" — neither of which it returns any more — and neither `performance_urls` nor `performance_url_detail` mentioned the new `server` scope, so the whole point of this change was undiscoverable to an agent. All three entries corrected, and a test now holds every tool's declared arguments to arguments its verb actually declares. That gate immediately found one more: `performance_ask` had been offering a `--context` argument the `ask` verb never declared, even though its handler reads exactly that. The verb declares it now. The summaries themselves still have no gate, which the test says out loud.

## [0.58.5] - 2026-08-22

### Changed
- **The checkpoint-budget tripwire now says what did not fit.** ADR-11 calls this log "the only tripwire that can tell you" the carry budget is set wrong, and it reported a bare count — so a hub firing it seventeen times in three hours still could not say which frame overflowed, how big it was, or how far over the budget it sat. It now names the largest dropped frame, its size, the budget and the held total — the two numbers a budget is actually set from. The count moved into `print_less_often`'s `$extra`, which is printed but not keyed: with it in the message text, "1 over" and "2 over" were two separate rate-limit timers, so the alternating counts minted two timers where one was intended. The observed volume was well inside the allowance either way; the fix stands on correctness, not on noise.
- **ADR-11's reopen condition was permanently satisfied, which is the same as having none.** It said to revisit when this log fires routinely. But the largest frames are the per-server leaderboards and that axis grows with an operator input, so on any multi-spoke hub it fires as a matter of course — a staging hub with ~25 spokes trips it a few times an hour, dropping one or two frames, and a dropped frame is re-merged from memcache on the next write anyway. Decision 11 now records that dropping the biggest IS the design, and states a reopen condition the enriched log can actually answer: when the held total runs at a MULTIPLE of the budget rather than just past it, or when the largest dropped frame is not a per-server leaderboard. `mirror_held_bytes` / `mirror_held_frames` join the GET_STATS payload so the total is readable BEFORE it trips — a threshold you can only observe after the fact is not a signal. The budget stays at 262144 until a measurement moves it, and decision 11 now also records what actually bounds it from above (the offsetlog ring, not the record cliff) and that the real lever is upstream: the per-server namespaces have no rank cap, so the held set grows O(servers) and any fixed budget eventually loses to fleet size.

## [0.58.4] - 2026-08-22

### Fixed
- **"Unique URLs" counted the URL table's filter, not the site.** The Overview panel read `urlsSlice.total` — the count the `urls` verb returns AFTER applying the table's search, server and errors filters — and rendered it beside "Total Requests", which sums the global `hourly` namespace. A filter matching nothing therefore showed `0 Unique URLs` next to 33,049 requests. `build_overview_payload()` had computed the unfiltered `total_urls` all along, and `pageFacts` was already reading it for the Ask brief, so the brief and the panel disagreed on the same page. The panel now reads it too; the table keeps the filtered total, which is what its pagination has to page through. An absent field renders an em dash rather than a plausible zero — the zero is exactly how this hid — and under a server filter the tile says "Unique URLs (all servers)", because a URL row carries no server to group by while every stat beside it is scoped.
- **The Time Breakdown divided a server's categories by the whole site's average.** Same defect, same card: the heading reads `Time Breakdown (edge-01)` and the categories come from `build_leaderboard( $server )`, but `RequestProfile`'s denominator was `overview.global_avg_ms`. Every bar and row percentage was computed against the wrong wall clock and could exceed 100% with nothing on screen to show it — one panel rendered `317ms Avg Response` for the server beside a breakdown divided by 42. It now uses the scoped `filteredStats.globalAvgMs`, which was already a prop on the component. Unfiltered the two are the same number, so the change is confined to the filtered case.

- **Selecting a server emptied the URL table.** The `urls` verb honoured a `--server` option by substring-matching the SERVER NAME against the URL TEXT, and the index is keyed by `url_hash` with no server dimension at all — which is why the tile above it now says "(all servers)". Picking `edge-01` therefore matched no URL and returned `total: 0` with an empty page, for every site whose URLs do not happen to contain their own host name. That zero was also what the Overview tile was reading. The option is gone from the verb and the client stops sending it; `search` and `errors_only`, which the row does carry the data for, are unaffected. Two tests asserted the removed behaviour — one declared `server` among the verb's args, the other piggybacked an unmatched server filter to prove a sort fallback — and both were corrected.
- **A pre-existing test asserted the first bug** — it expected the table's count in the Overview stat — and was corrected: a server filter scopes the request totals it has a breakdown for, it does not make the site smaller.

## [0.58.3] - 2026-08-21

### Changed
- **Substrate pin moved to v2.36.1**, which drops the hardcoded 17px scrollbar inset from the shared log header. The shared stylesheet is INLINED at build time, so 0.58.2 — pinned to v2.36.0 — still carries the inset in its `error-log` and `requests` bundles, and only a rebuild against the newer pin removes it. Caught by diffing the published `build/` against a local one: four CSS files differed by exactly that one declaration.

## [0.58.2] - 2026-08-21

### Fixed
- **A folded flame read the gap between two runs as work, and the root outran the request by three minutes.** `Flame_Fold::flatten()` reused `Flame_Tree::cover_children()`'s rule — raise a parent to the EXTENT its positioned children span — on nodes that had merged many spans into one. The rule needs `t` and `value` to describe a single span; a merged node's `t` is its EARLIEST instance's start while `value` totals them all, so extent-minus-start measures from one instance to another instance's child. On a 319s `periodical-cron` (rid `9zb6vy…`, 226,907 entries): `function: readOnlyMode` under `/Validation/Administrative.html`, **2 calls, slowest 2.6ms**, shipped `value: 133506.4` — exactly its child's earliest start (318,892.047ms) minus its own (185,385.69ms); `function: relatedLinkPatch`, 310 calls, slowest 45.3ms, shipped 124,520.9ms. The two carried 258s of the 358s that made `save` look like 71% of the request, and the root `process` reported **503,875.7ms against a 318,979.0ms wall**. A merged node now keeps the SUM, which is what `cover_children()`'s own docblock already prescribes for trees that merged many requests. Replaying the same 226,906 entries through the fix: `process` 318,979.0ms (**+0.0** against the wall), `readOnlyMode` 2.8ms, `relatedLinkPatch` 522.0ms, `save` 124,933.7ms inclusive.
- **The discriminator is STARTS, not completions.** `count` rises only on `(complete)`, and `close()` splices off every frame above the one it matches — so a span outliving its parent is left open, never reaches `count`, and its path is free to be opened again. Two starts and one complete read as a single instance while `t` already held the first start's offset. `record()` now counts opens into `starts`, and `merged()` reads that; closes can never exceed starts on a path, so it subsumes the old test exactly. Not observed in the 226,906-entry record — zero paths there have `starts > 1` with `count <= 1` — but the mechanism is reachable past `MAX_STACK_DEPTH`, where a span is created and never pushed.
- **Both ends move together, which is why the earlier attempt was backed out.** Removing the raise alone shrank the parent to its sum while `FlameGraph.js` kept sizing spacers by `t + value`, and children diced at ~20,000× the parent's width. `placeChildren()` now declines a merged family, so `collectGaps` and `insertSpacers` stop fabricating gaps — a two-child fixture produced a 3,065ms spacer at an offset no span ever occupied. It reads a `merged` flag `Flame_Fold` emits rather than re-deriving the rule from `count`: the fold knows, and one owner beats two spellings.

### Changed
- **The analyzer ranks and describes by what a span SPENDS, not by what it contains or how often it happens.** `flatten()` now carries `self_ms` — a node's value less its children's — and summed by name it reproduces `profiles` exactly, checked against a 155s cron category by category, so the flame answers self-time without a second source. Three findings change with it. `dominant_span()` carries `self_ms` and `self_share` and leads with them: the live brief said "pyrobase holds 100% of the profiled time" about the engine wrapper, which spends **9.5%** in its own body — true, already significant, and no use to anyone. It no longer publishes the flame's `count`, which `Flame_Tree` never writes: on an unfolded record it read 1 for a span that fired hundreds of times, and a brief could carry `count=1` and `count=340` for one span from two findings at once.
- **`repetition()` could not fire on an ordinary request at all, and ranked the wrong thing when it did.** It read the flame's `count`, which only `Flame_Fold` ever writes — `Flame_Tree` writes none, so every node of an UNFOLDED record defaulted to 1 and the `>= 50` gate never opened. In a 4,000-record sample, 3,997 were unfolded; the finding fired only on the rare shape. It also ranked by count, crowning `query hook` at 15,109 fires holding **171ms — 0.1% of the request** — over the 99 outbound calls holding **31.7s**. It now reads the record's own `profiles`, where `Request_Builder_Node` already subtracted each state's children from its time: present on 3,997 of those 3,997 unfolded records, exclusive by construction, and ranked by what the repeat SPENDS. Its `measured` says `profiles` rather than `flame`, and the metric publishes the `self_ms` that decided the winner instead of a container total no reader could reproduce the ranking from.
- **One classifier for span kind, not two prose ladders.** `interior_detail()` and `visibility_proposal()` each branched over hook/custom/listener in their own order, which is how both came to credit a custom event with listeners; they now share `span_kind()` and arm in the same order, and `is_hook()`/`is_custom_event()` are gone. `repetition()` also declines a non-positive repeat — exclusive time can go negative where a record's spans do not add up, and 1 of 20,844 live profile states is, at −336,176ms across 11,349 calls.
- **A share below a tenth rendered as `0.0` in the brief.** `askBrief.js`'s `num()` used `toFixed( 1 )`, so a 4% self share printed as `0.0` directly beneath a sentence saying 4%, and a real 0.0113ms-per-call printed as `0.0` too. Values under 1 now keep three decimals.
- **Marking a CUSTOM event significant does nothing but keep it from being auto-disabled.** Both already-significant branches said "its listeners are logged — read those next" for any span, so a brief about `pyrobase` sent the reader after listeners that do not exist. `interior_detail()` and `visibility_proposal()` now split by kind, matching what the not-yet-significant branch has always said correctly — that significant events reach hooks only.
- **Two new fields cross a serialization boundary.** A folded flame node carries `merged` on the wire — the verdict the browser reads instead of re-deriving it — and the fold state carries a per-node `starts` through the Consumer's checkpoint frame. Both are one value per DISTINCT PATH, not per message, so they cost nothing the fold was built to save: the 226,907-entry record has 343 paths. `Fold_Frame` lost its `t` in the same pass — the node already keeps the earliest, nothing read the frame's copy, and a frame rides every checkpoint for as long as its span stays open. A state checkpointed before this release has no `starts` at all, and a missing key reading as 0 would claim one span and hand every restored node straight back to the extent rule, so `merged()` takes COMPLETIONS as the floor: a path can never close more often than it opened, which makes the max the identity on anything this version wrote. Two starts and a single completion stays wrong across such a restore until that path completes again or opens twice more — one further open leaves both counters at 1.

### Known
- **Records folded before this release keep the values they were stored with.** The inflation was baked into the stored tree, and nothing rewrites history; they age out with `flames` retention. `Findings`' flame-derived readings — `dominant_span`'s share and `self_ms`, and `unattributed` — read those stored values while they last. No STAT was ever affected — `accumulate_all_stats()` reads the record's own `duration_ms` and `profiles`, both correct throughout, which is why the 0.58.1 stats fix holds and percentiles, hourly, categories and the leaderboards never carried this.

## [0.58.1] - 2026-08-20

### Fixed
- **A dashboard read a rendering width as a measurement, and doubled every stat on a covered request.** `accumulate_all_stats()` took its duration from `$flame_data['value']` — which `fill()` deliberately RAISES to `max( duration, flame value )` so a parent covers its positioned children, because the browser prunes on one cutoff and `treemapDice` scales children by the parent's value. Raising is right for drawing and wrong for counting. Measured on staging: a 356,312ms cron reached `avg_ms` as **724,662.87ms**, and the same inflation fed `hourly` and the leaderboards. Every stat now reads the record's own `duration_ms`; nothing measured reads a flame value. The flame the detail view renders is untouched.
- **A folded record reported its merged window as idle time, and proposed the opposite of what the same record already advised.** `Findings::entry_gap()` measured across the `entries (aggregated)` marker and called 355.9s "nothing logged" — those 87,074 merged entries ARE that window — then proposed `add_hooks` while `truncation()` proposed `trim_hooks`. A gap either side of a sequence-break marker is now skipped; a genuine gap elsewhere in a folded record still reports.

### Changed
- **`Request_Builder_Node::SEQUENCE_BREAK_KEYS` owns the marker vocabulary**, replacing three hand-maintained PHP copies under two names. The node that mints the markers owns them, and `Findings` and `Reqgrep_Command` reference that constant directly — no local alias, since a constant whose body only forwards buys a name and costs the reader a second hop. The JS copy stays, being a separate deploy unit. Recorded as decision 13; the render-vs-measurement rule the stats fix turns on is decision 12.

### Known
- **A merged flame node's `value` is still the extent its children span, not what it measured.** A live record shows `include` at `count: 3, max: 164ms` carrying `value: 355176ms` — the extent of a child whose only occurrence ran 355 seconds later. Merging same-name siblings destroys the "children lie inside one occurrence" invariant, so extent-minus-start stops being a bound. Removing the raise was tried and BACKED OUT: `Flame_Fold::flatten()` still emits `t` on merged nodes, and `FlameGraph.js`'s `placeChildren()` declines only on a missing or negative `t`, so the spacers stay extent-sized while the parent shrinks to the sum — children then dice at ~20,000× the parent's width, painting over every sibling. Two coherent fixes: drop `t` from a merged subtree (costs the detail view its clock stamps for merged spans), or guard `placeChildren()` on `count > 1`. Note also that `count` increments only on `(complete)`, so it under-detects a parent opened three times and closed once. Nothing measured reads this value now that stats read `duration_ms`.

## [0.58.0] - 2026-08-19

### Changed
- **The reader stopped hand-rolling the writer's arithmetic.** `Performance_CI_Node` summed the dimensional and category series with `Core::as_float`/`as_int` — the PERMISSIVE family that casts any scalar — against values `Flame_Builder_Node` stored through the refusing `num_*` family, and with `c` as a float where `CAT_SUMS` declares it a whole count. Both hand-rolled merges collapse into one `merge_buckets_into()` over `Stats_Store::sum_fields()` and the same `DIM_SUMS` / `CAT_SUMS` tables the writer uses. The same release introduced those tables and converted only `merge_hourly_across_partitions()`; leaving two copies beside a new shared constant is how the two spellings drifted in the first place.
- **One capping function, not two.** `cap_dim_bucket()` and `cap_single_bucket()` were the same body — rank, keep the head, roll the tail into `Other` — differing in sort field, field table and whether the `total` row is lifted clear of the ranking. Those are arguments: `cap_bucket()` takes them, and `cap_dim()` / `cap_categories()` name the two configurations. The docblock claiming the two were unrelated was mine and was wrong.
- **The `total` rollup folds once per request, not once per category.** It was three 4-argument calls per category per request — 90 for a request with 30 profiled categories — each allocating a 3-key array to add one float. The call count now accumulates in the loop and folds after it, which also retires `add_cat()`'s `$sample` flag and the seed-then-index-without-a-guard pairing it needed.
- **Frame rank is no longer stored.** It is a pure function of the data, and `evict_lowest_rank()` — its only reader, running once per overflow — derives it. That deletes the strip on the way into a checkpoint and the recompute on the way out.
- **The per-URL row stops being copied on every request.** `accumulate_url_stats()` copied a 14-field row plus up to 100 sampled durations per timed request, then wrote it back; it binds by reference like every other accumulate site now does.
- **`read_window()` is memoized while its bucket is current.** One `overview` called it eleven times, each rebuilding up to 288 keys with a `gmdate()` apiece, and each re-reading the clock — so two panels of one response could straddle a boundary and answer for different windows.
- **The open bucket is no longer written to `flame-stats`.** The mirror buffered every memcache write and drained the whole buffer at each `save_state()` checkpoint, so the bucket being accumulated into was written roughly ten times over its five-minute life — and the partition keeps only the last frame for a key, so nine of those copies were a value that had since grown. `flush_stats_mirror()` now writes a bucket's frames at the first checkpoint after that bucket CLOSES and holds the open one until then, so each bucket reaches the durable log once, whole. The open bucket is not left undurable: the held frames ride `save_state()` into the OFFSETLOG and come back through `restore_state()`, so a respawned worker writes them when the bucket closes — and the worker's `rehydrate` seam consults them FIRST on a read miss, so a mid-bucket eviction is repaired instead of restarting the bucket from zero (nine requests, an eviction, one more, read back as **one** before that fix). The carry is capped at `MAX_CHECKPOINT_MIRROR_BYTES` (256KB), smallest frame first, and logs what it drops: the offsetlog bounds keyframe COUNT and not bytes, and the per-server aggregates grow with the spoke count — one server's leaderboard bucket measures ~27KB. That is the whole trade — the offsetlog is a bounded ring (`OFFSETLOG_SEGMENT_SIZE = 1`, at most 60 keyframes, pruned at 5/15 minutes, and `add_snapshot_node` already lifts its PIPE_BUF cap), so the open window costs FIXED disk there, while `flame-stats` retains every frame for `stats_mirror_lifetime` — twice the stats window. Frame ranks are not carried; `mirror_traffic_rank()` re-derives them on the way back in rather than persisting state that is computed. Measured on a local `flame-stats.p0` before any of this: 4,279,767 of 5,531,418 bytes (77.4%) were frames a later frame for the same key had already superseded. The per-URL top-N is now ranked on a whole bucket's traffic rather than one checkpoint's, so the URLs kept are the ones actually busiest.
- **`Stats_Store::is_open_bucket()`** decides that from the key alone, which is what ADR-1's bucket-LAST rule buys: a suffix test, and the unbucketed `url` namespace never matches one.
- **The ten per-namespace flush arrays are gone**, and with them `rotate_pending_bucket()`, `promote_pending_bucket()` and `reset_pending()`. They existed only to carry one bucket's fragment from promotion to `persist_aggregate_stats()` within the same call; keyed by bucket, `$pending` is what persist iterates. The `[hash][bucket][dim]` transposition goes too — the accumulator is already inside its bucket — and the category cap now runs once over the whole bucket at write time rather than per 5-second fragment, which is what top-N was supposed to mean. Each accumulate site takes its bucket accumulator by reference instead of reaching into `$this->pending`.
- **The checkpoint frame is bucket-keyed.** `save_state()` returns `{ pending: { <bucket>: … } }` and no longer carries `pending_bucket`; a frame written by an older worker — one flat accumulator beside its `pending_bucket` — is restored under that bucket. GET_STATS reports `pending_buckets` (the keys) in place of `pending_bucket`, and `pending_url_count` sums across them.
- **Every bucketed namespace is keyed per bucket now, not just `hourly`.** `dim`, `dim`-by-server, `categories`, `categories`-by-server, `url_dim` and `url_cat` each held a whole bucket-keyed map under one key, so a checkpoint rewrote the entire retention window to record the one bucket that changed — measured on a real `flame-stats` log, 88.8% of the `url_dim` bytes, 87% of `url_cat`, and 68% each of `dim` and `categories` were buckets whose value had not moved. One rule everywhere: the bucket is the LAST key component and the value is whatever sat under it, so all eight namespaces read through the same `lookup_multi` batch and a single eviction costs one bucket instead of a window.
- **`url_dim` is stored bucket-major.** Its accumulator was transposed from `[hash][dim][bucket]` to `[hash][bucket][dim]`; keying the dimension as well would have made a 7-by-288 cross-product per URL, where bucket-last keeps it at 288 with every dimension in the value.
- **Both manual retention-prune passes are gone.** `merge_and_cap_dimensional()` and `merge_and_cap_categories()` each walked the whole series unsetting buckets older than a cutoff derived from the store's own TTL. Per-bucket keys let memcache expire them, so the two functions collapse to one `sum_fields()` — the merge they only ever differed in by field name — plus a per-scope `persist_dimension()` / `persist_categories()`.
- **`mirror_traffic_rank()` reads the new shapes.** It ranked URLs for the mirror's top-N eviction by walking the old nesting; against one-bucket values both branches would have summed nothing and kept an arbitrary 100 rather than the busiest 100.
- The whole-series accessors (`get_dimensional`, `set_dimensional`, `get_url_dimensional`, `set_url_dimensional`, `get_categories`, `set_categories`, `get_server_categories`, `set_server_categories`, `get_url_categories`, `set_url_categories`) are gone, replaced by singular/plural per-bucket pairs. Per-server categories fold into a `$server` scope on the global pair, matching the leaderboard.
- **`Other` no longer clobbers itself.** Both value caps assigned the current pass's overflow over the `Other` entry — which, because it sums the evicted tail, sorts high and survives into the kept slice. Every re-cap of an overflowing bucket therefore discarded the previous overflow: a seeded `Other` of 640 came back as 202. It folds now, through the same `sum_fields()` the dimensional cap uses.
- **`''` no longer means a server on any per-server write path.** v0.57.5 fixed this for the leaderboard; `dim`-by-server and `categories`-by-server had the same hole, and now that `''` selects the GLOBAL key rather than an inert `dim:...:`/`categories:` one, a checkpoint carrying a nameless server would have summed that server's totals on top of the global series that already counted the same requests.
- **The read is wider than it was.** A breakdown or category chart now issues a `lookup_multi` of up to `MAX_READ_BUCKETS` keys per partition per dimension where it issued one `get`, which is the trade ADR-6 already makes for `hourly`, `lb` and `urls`. Round-trip count is unchanged. Above 24h retention the dim and category charts truncate to 24h, matching the three namespaces that already did.
- **Same deploy caveat as `hourly`:** the old whole-window blobs are not migrated, so the breakdown and category charts start empty and refill over one retention window. Restart the flame-builder workers.

### Fixed
- **A bucket revisited before the next flush lost its earlier half.** `promote_pending_bucket()` ASSIGNED each namespace's fragment into its flush array (`$this->hourly_stats[ $bk ] = …`), and promotion ran on every bucket CHANGE, not only at flush. A bucket key comes from a request's START time while the record arrives at COMPLETION, so around every 5-minute boundary the builder alternates between the closing bucket and the new one — and the second promotion of the same bucket inside one 5-second flush window discarded the first. Only the two leaderboards merged instead of assigning. `$pending` is a MAP keyed by bucket now: a record folds into its own bucket, nothing rotates and nothing resets, so three requests arriving A, B, A count two in A. A timed-out trace, evicted up to 600s stale, was the widest case.

### Known
- **A replay double-counts a bucket.** `persist_aggregate_stats()` adds each flush's delta to what memcache already holds, so any replay re-adds requests memcache already counted. A cold start (cursor segment 0, replaying the retained log) is the widest case, but an unclean death is enough: `flush()` writes the deltas between checkpoints, and a respawn replays from the cursor that last committed, so those deltas land twice. Reproduced: two requests replayed against a warm bucket of two read back as four. Fixing it means writing the bucket's total rather than a delta. `pending` is keyed by bucket as of this cycle, which was half the requirement; what is still missing is a per-key adoption of whatever a previous process left, so a restored worker resumes a bucket instead of overwriting it. Attempted and backed out in this cycle: the naive form destroyed data, wiping both a closed and an open bucket to a single request and corrupting the durable mirror frame along with it.

## [0.57.5] - 2026-08-19

### Changed
- **`hourly` is keyed per bucket, like the leaderboard already was.** One key held the whole retention window, so every checkpoint re-mirrored all ~144 buckets to record the one that changed: measured on a real `flame-stats` log, 93% of the hourly bytes were buckets whose value had not moved, and 59% of its frames were byte-identical to the frame before. It is now `hourly:<bucket>`, read through the same `lookup_multi` batch the dashboards already use for `lb` and `urls` — so a write carries one bucket, and a single eviction costs one bucket instead of the window. `persist_aggregate_stats()` no longer reads, merges, prunes and rewrites the series on every flush; the retention cutoff is the key's own TTL. `get_hourly()` / `set_hourly()` are gone. **The old whole-window blob is not migrated:** nothing reads `evlog:p{N}:hourly` any more, so on deploy the Performance overview's `aggregate_time_series` starts empty and refills as new buckets accumulate, full again after one retention window. During a rolling deploy an un-restarted worker keeps writing the blob nobody reads — restart the flame-builder workers.
- **Bucket geometry belongs to the key schema.** `Stats_Store::bucket_key()` and `retention_buckets()` replace six hand-rolled copies of the `Y-m-d-H-<floor 5>` derivation across the flame builder, the performance CI and the tests. The reader's window is derived from the store's own retention rather than fixed at 24h — a shorter retention no longer asks memcache for 288 keys to read the 24 that exist — still capped at `MAX_READ_BUCKETS` so one `get_multi` stays bounded.
- **One leaderboard getter/setter pair with a `$server` scope**, matching the `get_leaderboard_buckets()` sibling that already took one, and one node-side merge replacing two byte-identical blocks. `''` now means the global series on the write path, so `promote_pending_bucket()` refuses a nameless server: a restored checkpoint carrying one used to write an inert `lb_s::<bucket>` key and would otherwise have merged that server's sums into the global leaderboard.
- `Stats_Store::add_totals()` holds the `{count, sum_ms, sum_peak_mb}` arithmetic beside `sums_to_display()`, which already owns the read-time division. Fields outside the triple ride through, so the bucket stays extensible.
- `get_leaderboard_buckets()` and `get_url_buckets()` now share one `lookup_buckets()` body with the new `get_hourly_buckets()`, replacing three near-identical multi-get implementations.

## [0.57.4] - 2026-08-18

### Fixed
- **Picks up newspack-nodes 2.35.2.** The dashboards inline the substrate runtime, so two browser-side fixes only reach them through a rebuild. An unauthenticated send left `_http` locked, silently buffering every later direct `fill()` until the next Router tick POSTed the pile; and a send during a backbone rebuild threw on an unguarded `_http` lookup, unwinding out of `notifyTimer()` and killing the rest of that tick's pollers. The release.yml pin moves 2.35.0 → 2.35.2, two releases rather than one: 0.57.3 shipped against a stale pin.

## [0.57.3] - 2026-08-18

### Fixed
- **Stats evicted from memcache never came back, though the mirror held them.** `flame-stats:partition` has shadowed every stats write for months, but the only path back into memcache was a cold-boot replay gated on `! empty( $store->get_hourly() )` — and `hourly` is a SINGLE key holding the whole window, rewritten with a fresh TTL on every flush by any traffic at all, worker or not (`accumulate_hourly()` seeds its bucket either way). So the sentinel chosen to detect "memcache lost everything" was the one key that never goes, while the keys that actually get evicted — the hundreds of `urls:<bucket>`, `lb:<bucket>` and per-URL entries — vanished one at a time with nothing watching. On a busy host a URL simply dropped out of the leaderboard hours before its retention window closed, and only clearing the derived partitions and offsetlogs and reprocessing the firehose brought it back. A miss now reads the mirror: `flame-stats:partition` carries a `stats-index` companion (`key_hash(12) segment(6) offset(10) length(8)`), `Stats_Store` gained a `$rehydrate` seam beside `$mirror`, and a key found there is restored under a TTL decayed by the frame's age — so an eviction is repaired and a genuine expiry stays expired, `restore()` having always refused a frame older than its own TTL. **Only frames written after this deploy are indexed; earlier ones age out within the retention window.**
- **The worker's own merge undercounted after an eviction.** `persist_aggregate_stats()` reads each bucket, adds this flush's delta and writes the sum, so an evicted bucket read as empty and the prior counts were silently dropped from the total. It reads through the mirror now.
- **The dashboard could not reach the mirror at all.** `Performance_CI_Node` builds its stores in a web request, where no `Flame_Builder` exists to arm the seam; `Flame_Builder_Node::arm_stats_reader()` arms them from the topology instead, resolving the partition's concrete dir through `Bootstrap::node_dirs()` rather than rebuilding a path template.

### Changed
- **`stats_mirror_node` ships as `flame-stats:partition`.** It defaulted to `''`, which left the mirror — and therefore the recovery above — off on every install that had not hand-edited its config file. The topology already builds the node.
- **The mirror's retention derives from the stats window.** `flame-stats:partition` took the 64 MiB default and no lifetime, so it kept frames for days past the point `restore()` could use them. It now takes `<eln:stats_mirror_lifetime>`, twice `Config::stats_retention_seconds()` — widening `min_lifetime` widens the mirror with it, rather than silently truncating it against a constant.

### Fixed
- **The stats read-through went silently un-armed on any install that shortened retention.** `Stats_Store` memoized its two tables by TTL *value*, and the two TTLs coincide at exactly 3600: `ttl()` is the retention window, `ttl_url_stats()` is a twenty-fourth of it floored at `PREFIX_FLOOR`, and `Config::stats_retention_seconds()` floors the window at the same number. So `min_lifetime <= 3600` — a legal operator setting — collapsed both roles onto ONE table, and the surviving one is the per-URL table, which is deliberately unbacked. The mirror kept recording frames nothing would ever read back, with no error anywhere. Tables are keyed by ROLE now, each resolving its own TTL, so a TTL value is no longer an identity.
- **The dashboard could not reach the mirror on an APCu-only host.** `stats_stores()` gated on `Core::$memd` while every other participant resolves through `Cache_Backend::shared_first()` (memcached, else APCu). That is the one posture the durable read-back exists for — the web pool's APCu segment is not the worker's, so the mirror is the only path to the data — and the gate closed it.

### Changed
- **Read-through moved into the substrate, and the second copy of it in this plugin went with it.** `Stats_Store::recover()` / `restore()` and `Rule_Set::hooks_for()`'s cache branch were the same mechanism — table miss, durable source of record, store back — written twice. Both now hand `Table_Node::backed_by()` a closure and read one API; the stats mirror backs the aggregate tables, the hooks option backs the ruleset table. That deletes the hand-rolled miss loop in `get_url_buckets()`, the backend-key round trip on both read paths, and `restore()`'s `str_starts_with` scope guard — the Table applies its own namespace, so a backing cannot file a value outside this partition's keyspace by construction. **Requires substrate 2.35.0**; the loader floor moved with it.

### Removed
- **`reload_stats_from_partition()` and its `$stats_reloaded` latch.** Read-through repairs holes as they are found, so a once-per-process bulk replay of the whole partition is cost without benefit. Its last-wins and TTL-decay behaviour is unchanged, now exercised through the miss path.

## [0.57.2] - 2026-08-17

### Removed
- **`SegmentBrowseSidebar`, a second copy of the toolbar picker every other stream dashboard already had.** Its `<select>` was `LogStreamViewer`'s picker rewritten — same class, same map to `<option>`, same change handler — while the substrate's own picker slot sat unused beside it, because both dashboards passed `pickerOptions={ null }`. `useGlobBrowse` returns the rows now and the Error Log and Request Log hand them to the toolbar, which puts the partition pick where the Partition Viewer and Log Viewer have always put theirs. The component, its stylesheet and its test are gone — three files. `useGlobBrowse` also keeps its own two-level rule now rather than restating it at both call sites: without a selected dir there is nothing to browse WITHIN, so it returns no rail and no offset jump, and `useGlobStreamGraph` withholds the step. A sole partition loses its bespoke static label and becomes the picker's only row, auto-selected before paint so the control never shows a value matching no row. A catalog row now renders its `label` verbatim rather than falling back to its `key` — the server always sends one, so the fallback only masked a malformed row; with no partitions at all there is still no picker, as before.
- **The glob dashboards' third copy of the stream lifecycle, and the segment rail beside the substrate's.** `useGlobStreamGraph` held its own pause state, `isActiveNow` and `setPausedRef` plumbing; `useGlobBrowse` held its own `reposition`, `reopenStream`, single-step read, rail-refresh timer, stale-segment refetch, seek handlers and a private `viewControl()` duplicate of `controlMsg`. All of it is the substrate's `useStreamGraph`, `useSegmentBrowse`, `useLogStatusSegments` and `useLogCatalog` now, so what is left here is what a GLOB actually adds: the partition pick.The Error Log and Request Log each drop the twelve-line offset-jump closure they had both written out for `useSegmentBrowse.jump`. 372 production lines.
- **The Error Log and Request Log stream graphs are one hook.** They were byte-identical in executable code apart from a node-name prefix, a partition glob and a view class; those three are what each dashboard now declares to `useGlobStreamGraph`. 261 production lines.
- **`TagInputField`'s two selector modes, its hidden-carrier effect and `defaultValues`.** `RuleEditModal` is the only caller and passes `initialValues`, `onChange` and `horizontal`, so the hook and custom-event selector branches, the `${fieldName}_json` carrier the pre-v0.26.0 settings form read, and the default-vs-custom token styling were all unreachable; `fieldName` itself existed only for the carrier. 134 production lines, and the component's `newspack-nodes-status` entry in the appearance-ownership contract goes with the selector rows.
- **`RulesViewNode` and `HookCatalogViewNode`.** Both hand-rolled what `sliceView()` declares — an empty model and a guard-then-map parse — and `RulesViewNode` re-implemented the base's TM_ERROR handling verbatim on top of `Node`. They are declarations in their bundles' `register.js` now; two files, ~160 lines.
- **`Log_Manager::is_sensitive_key()` and its 18-key and 14-substring lists.** `log_environment()` iterates `ENV_ALLOWLIST` and nothing else, and the intersection of that curated 34-entry list with the sensitive patterns is empty — proven mechanically, not by eye — so the `true` branch could not fire. The idea was still worth something, because the allowlist is hand-edited; the code was not, because a hit would have dropped the key in silence. The invariant moved to `LogManagerTest::test_no_allowlisted_environment_key_looks_like_a_secret`, where adding `HTTP_X_AUTH_TOKEN` to the allowlist fails the build and names the offender (verified by mutation). 60 lines. `Gyrobase/Log.pm`'s identical vestigial `%skippable` went with it — same shape, same allowlist-only key source.
- **The URL index's second hash algorithm.** `load_index_default()` carried a fallback for buckets keyed by URL string, and it derived the row's hash with `substr( sha256( url ), 0, 12 )` — a different algorithm from the `Log_Manager::url_hash()` FNV-1a every record, rule id and lookup uses. Both emit 12 hex chars, so nothing downstream could tell them apart and `url_detail`'s own guard accepted either; a row that entered through it was indexed under a hash no rid lookup could ever produce. Nothing has written that shape since the legacy monorepo was retired — `Flame_Builder_Node` is the sole writer and keys by hash — so the branch and the test pinning it are both gone. The bucket key IS the hash.

### Fixed
- **Collapsing the browse rail hid the partition picker, and remembered it.** The picker lived inside the rail, which `LogStreamViewer` renders as `railOpen && sidebar` — and `railOpen` persists per dashboard in `localStorage`. So a user who collapsed the rail on the Error Log or Request Log lost the ability to pick a partition entirely, across reloads, with no way back but reopening a rail they had deliberately closed. The picker rides the toolbar now, which is always visible.
- **The toolbar picker had no accessible name.** All four stream dashboards rendered a bare combo box in a toolbar where every sibling button carries a title, so a screen reader announced it as unnamed. `LogStreamViewer` takes a `pickerLabel` and sets `aria-label` and `title` from it.
- **The Error Log and Request Log pickers never recovered from a refused catalog, and never saw a partition that appeared after they loaded.** Both fetched `list_logs` ONCE from a mount effect. The retry that read as covering it does not: it re-asks a request that went MISSING, and a refusal is an answer — so a session that expired while the tab slept left the partition picker empty until a reload, and a repartition that added `errors.p4` never listed it. They read the substrate's polled catalog now, the same one the Partition Viewer and Log Viewer have always used.
- **The dashboards' retention window was derived four ways, each seeded with the wrong key's default.** Two `Stats_Store` constructions, the constructor's own parameter default and the admin entry point each spelled it out, all with a literal `86400` — which is the substrate default for `lifetime`. `min_lifetime`, the key they actually read, defaults to **43200**, so a fallback did not stand in for the value, it doubled it. The entry point additionally read the key straight off the config array with `??`, bypassing the fail-loud accessor entirely — the one reader the `[2131]` migration missed. One `Config::stats_retention_seconds()` now owns the derivation, floored at `Stats_Store::PREFIX_FLOOR`, and the constructor's parameter default is gone (every call site already passed one, so it was unreachable).
- **`performance request_detail <rid> --partition=abc` answered for partition 0.** The `(int)` cast made the typo select a real partition, so the verb either returned a DIFFERENT request's full body or — where the rid lives in p3 — said "Request not found: rid=…", blaming the id for a bad flag. `request_grep --limit=abc` collapsed the same way through `max( 1, (int) … )`, returning one result and calling it the whole match set. Both verbs are reachable over REST, WP-CLI and the MCP tools (`performance_request_detail`, `performance_request_grep`), where the argument often comes from a model rather than a human, so a refusal matters more, not less. Both now read through the substrate's `Service_CI_Node::require_option_int()`, which throws naming the flag and the value. Requires newspack-nodes with that helper.
- **A rule map the system cannot represent is now refused, not aliased.** `Rule::from_array()` coerced a missing `pattern` to `/`, so `rules upsert {"action":"skip"}` — reachable over REST, WP-CLI and MCP — took the log-all baseline's id and turned site-wide logging off. It also decoded `hooks` and `hooks_in` independently, making `(hooks=[], hooks_in=mc)` and `(hooks=null, hooks_in=inline)` constructible; both slipped past `Rule_Set::save()`'s rehydrate guard and then deleted that rule's durable hooks option. The first shape is manufactured in-repo, by `sanitize_settings_array()` dropping the wire's `"hooks": null` on the hub→spoke `performance set` path. `Rule`'s constructor now holds both invariants and throws. Write paths (the `rules` verbs, `apply_synced`) let it throw, so a bad push is rejected whole and the last-good ruleset stands; read paths (the stored option, the config seed) skip the row with a rate-limited notice, because one hand-edited option must not fatal every request. Only an EXPLICIT `null` names the pointer tier: a scalar `hooks` — `""` from a hand edit, or any producer that encoded an empty array as one — is a rule with no hooks, so it survives as `inline` with `[]` instead of reading as unresolved and taking the rule down with it.
- **A garbled discovery interval free-spun the drain.** `make_node Discovery_Collector discovery-collector abc` cast to 0 with no notice — as did `0` and `-1` — and a 0 ms timer takes an own Event_Framework slot that is due on every iteration, so the hub minted a signed `discovery.get` per spoke as fast as the loop turned. The node declared `interval_seconds` in its own `node_schema()` and then parsed the token again by hand; it no longer does. The substrate's `parse_schema_args()` walk refuses a non-numeric or negative token naming the node and the argument, and `Timer_Node::cadence_ms()` applies the shared `MIN_INTERVAL_S` floor — this plugin's byte-identical private copy of that constant is gone, since the hitchhike boundary it names belongs to the base class. Requires newspack-nodes with both.
- **A settings sweep re-saved every spoke's whole ruleset every five minutes.** The hub re-pushes each synced option on its periodic sweep whether or not it moved — that is what makes a freshly-connected spoke converge — and `performance set` gates the other options on a value comparison. The `rules` branch returned before that gate, so each sweep ran `Rule_Set::apply_synced()` unconditionally: every pointer rule's durable hooks option rewritten, orphans reconciled, and `request_reloads()` telling every worker to re-parse every `.tsl` for an answer that had not changed. `apply_synced()` now compares the incoming ruleset against the stored one and returns whether it moved, and `set` reports that as `updated`. The comparison resolves each rule's hooks and drops its TIER: which side stores a rule inline and which stores a pointer is a local decision, so comparing the raw shapes would have called it a change forever.
- **A heavy rule could never sync at all.** `sanitize_settings_array()` dropped every null on the way in, and a pointer-tier rule carries `"hooks": null` as the thing that NAMES the tier — so the hub's push arrived as a map `Rule::from_array()` refuses, the whole ruleset was rejected, and the spoke kept its last-good copy forever while the hub logged a success. Null is inert; it survives the sanitizer now. Objects are still dropped.
- **A found request in the overlay's Request tab flipped back to "still processing", and its flame graph never arrived.** Finding the record disabled the poll through `enabled`, which is the MOUNT gate — its teardown removed `currentrequest:view`, the node that owned the answer, so the next re-render for any reason (switching browser tabs, dragging or resizing the overlay) read an undefined model and rendered the processing state. Settling now `paused`s instead, so the record stays where it is published and the tab renders straight off it — no mirror in component state, no latch defending the mirror. Settling on the first reply also forfeited the trace: `Flame_Builder_Node` consumes `requests.log` AFTER `Request_Builder_Node` writes the record, so `request_detail` merges `flame_data` a beat later and "Request Trace" appeared only after a reload. The ask now outlives the record by five ticks — until the flame lands, whichever comes first — which is bounded, because a site running no `flame-builder` topology has no flame coming.
- **A URL row whose newest bucket omitted `url` rendered blank forever.** Buckets merge newest-first and the URL was read at first sight only, so an older bucket carrying the real URL could never supply it. Whichever bucket names the URL now wins.
- **Finding a request by id showed nothing, and then showed itself on the wrong URL.** The detail modal was gated on the `url_detail` slice, so a rid whose URL has no stats to answer with — a job POST, say — resolved, wrote `?url=&request=` and rendered nothing at all. The rid then stayed selected, so the next URL opened from the catalog rendered that stale request instead of itself. The modal now opens on the SELECTION and each pane owns its empty state, and opening a URL closes whatever request was open inside the previous one.

## [0.57.1] - 2026-08-16

### Fixed
- **A global `category:` brief reported zeros.** `Stats_Store::sums_to_display()` names its per-request means `time` and `count`; the assembler read `avg_time` and `avg_count`, so every field — the category's own time, its share, and every competitor — came back 0 while the dashboard beside it showed 90ms. Its unit test passed because the fixture invented the same wrong keys rather than using the producer's shape. The competitor list is capped like every other list now.
- The substrate pin follows `newspack-nodes` v2.33.1: an armed toggle in a modal header keeps its own text colour, and WordPress's header `Spacer` no longer opens a gap beside the close button.

### Removed
- **The "nothing to ask about there" notice.** A click on nothing askable is not a failure, and the dashboard's error channel rendered it as a red banner. The picker stays armed and the `?` cursor says so.

## [0.57.0] - 2026-08-16

### Changed
- **The Ask brief spends its budget on signal, and its pointer on the rest.** The picker's payload is now also an MCP tool result, so the payload IS the product — but it was shaped for a renderer that caps its own lists. The curation moved into `Ask_Assembler`: same-name spans fold into one row (a request with four `query hook` children spent four of its six slots on four identical `0.0ms×1` rows), every list sorts by time and caps at `TOP_SPANS`, and a span brief folds the span it is ABOUT — it previously reported the first occurrence's `10ms×1` while the request brief reported the same span as `30ms×3`, leaving the missing time accounted for nowhere.
- **A brief carries the tool call that fetches what it trimmed.** `url_detail` for the per-minute series, `request_detail` for the capped entry list, `ask` for a span with its request context. The markdown names the site's MCP endpoint once, "Copy brief" hands over that whole agent-ready document, and an "Ask Claude" link opens a chat with the brief prefilled (and on the clipboard, since past the URL budget the link can only ask for a paste).
- **The brief modal waits for the selection to finish.** A Cmd-click queued another ask AND opened the panel, putting the brief in front of the next thing being picked. The selection now belongs to one picker session — arming starts it, a modified click continues it, the plain click that disarms the picker ends it — so `open` is derived from the picker rather than kept as its own flag, and Escape discards what was queued.

- The substrate pin follows `newspack-nodes` v2.33.0, whose picker reports a miss instead of disarming and exports `ASK_TRIGGER_ATTR` — which this plugin's Ask button now carries.

### Removed
- **The URL brief's dimensional breakdown and the rule's custom-event roster.** Neither was rendered by any consumer: the breakdown was an unbounded per-minute series (23 buckets on a quiet local install, growing with traffic) and the roster ran to 68 names. The rule now carries `custom_event_count` beside `hook_count`, and dropping the breakdown parameter deletes a `merge_url_dim` memcache read per ask. Worst-request rows carry what names them — rid, partition, duration, status, error — rather than segment/offset/length storage coordinates.

### Fixed
- **A flame-graph span could not be picked after a zoom or a resize.** `data-ask` is stamped onto the frames after each render, but d3 rebuilds them on zoom and on the container resize, and the stamp ran before the re-zoom on the refresh path — so the `?` picker found nothing where a span plainly was. It now stamps after the re-zoom and after the resize re-render.
- **A pick that answered with no brief vanished.** `useAsk` returned silently on a non-object reply; it reports it through `onError` like any other failure.

## [0.56.1] - 2026-08-16

### Changed
- The substrate pin follows `newspack-nodes` v2.32.1, which puts `GRID_PHASE_MS` on the runtime surface — the phase the segment-rail test pins its clock to.
- **The Ask trigger sits where the question forms.** It left its lone spot under the dashboard title for the three headers a question actually starts from — beside the overview search, beside the URL's rule control, beside the request-detail back button. The picker is still ONE mode: `useAsk` holds it for the whole dashboard and `<AskButton>` is only a door, because two pickers would fight over the same body class and the same capture-phase click. The request-detail back button moved into the modal's `headerActions` with it, so it is a header item rather than an absolutely-positioned overlay.
- **Every dashboard command rides the router tick.** The substrate retired `useRequestNode` and `useReconcile`, and the seven consumers here moved with them: the hook catalog and the partition/source catalogs are POLLED slices, the ruleset's four writes and the Ask verb are one-shots, and the awaited reads a sequence genuinely needs (`resolveRequest`, `resolveUrlHash`, `fetchUrlBreakdown`, `requestGrep`, the rules verbs) go through `useAwaitableCommand` — still one node per verb, still addressed rather than correlated, but batched with everything else that tick.
- **The `?` picker no longer drops a brief when you pick several.** The ask was declared a retried read, and a retry supersedes — so a second Cmd-click replaced the first ask and its answer was discarded silently. Each ask queues and goes once; clearing belongs to the pick, since a flag read at reply time is the LAST pick's, not the one being answered.
- **Every dashboard view class is handed to `makeNode`, not named.** `OverviewView`, `UrlsView`, `HookCatalogView`, `CurrentRequestView`, `GyroscopeView`, `PerfErrorsView`, `RequestLogView` and `RulesView` were resolved by name through the interpreter's class map, which is a per-bundle static — so a devtools-hub tab building its graph through another bundle's interpreter cannot resolve one its own bundle registered ([ADR-16](../newspack-nodes/docs/architecture-decisions.md)). The Current Request tab IS such a tab. Each `register.js` exports the map it registers, and the substrate's contract gate now catches a name handed one hop away through a hook option.
- **`useGlobBrowse`'s three catalog reads answer through their own nodes.** `list_logs`, `log_status` and `read_message` were promise-returning awaits wrapped in `.then`/`.catch`; each is a `useCommandOnce` now whose `onDone` reads the dir the reply is ABOUT straight off its echoed arguments. That deletes the `fetchCatalog` verb-to-node map, the `applySegments` cancellation reasoning (a slow answer for a partition the user has left names that partition, so it is recognised rather than guessed at), and the promise `step()` and `jumpTo()` returned to callers that never awaited it.
- **A rules write names the rule it is about in its reply address.** The substrate carries the subject a command is about in the reply path, defaulting to the first token — and `save` and `upsert` send a whole DOCUMENT as their first token. `upsert` names the rule id the document carries, so the answer says which rule it applied to; `save` is about the ruleset as a whole and names nothing.
- **A mutation's outcome arrives as an answer, not a rejected promise.** `useRulesGraph` takes an `onMutation( { verb, error } )` and `RulesAdmin` closes the surface the verb names — the editor on an upsert, the dialog on a delete or a reset — so a refusal leaves the draft intact with the banner filled rather than a closed dialog and no trace. `useTopologyManager`'s refusals reach `onError` the same way, which is why `TopologyControls` has nothing left to await or swallow.
- The Current Request tab polls for its own record instead of reconciling toward it, so a record written a second after the page rendered and a session that expired overnight converge through the same path.
- The deep-link resolver's convergence rides the ROUTER tick rather than a private backoff, so a `?url=` intent still resolves on a dashboard whose catalog never moves.

### Fixed
- **A listener registered at a NEGATIVE priority read as a custom event.** `App\Core` labels a wrapped callback `<callable> @<priority>` and WordPress priorities may be negative, but the pattern that recognises one omitted the sign — so `Image_CDN::filter_the_content @-10` fell through to the custom-event branch and the brief advised enabling application logging for a WordPress callback. The shape now lives on the class that mints it (`Core::LISTENER_PATTERN` / `is_listener_span()`), beside `HOOK_SUFFIX`, and accepts the sign. Two older consumers still carry their own spelling — `Request_Builder_Node::is_callback_state()` and `RequestProfile.js` — and changing those moves flame attribution, so they are left for their own change.
- **The Ask brief said things about the logger that are not true.** Its caveat claimed the logger "hooks `all`", which told a model everything was instrumented and turned every absence into evidence; it binds only the hooks the URL's governing rule names, plus whatever custom events the application logs itself. And every dominant or repeated span was offered as a `mark_significant` candidate — but a rule's significant events reach HOOKS only (`App\Core::bind_current_scope()` leaves one naming a custom event unbound), so a brief about `include: /Responsive/Grids/Resp-One-Col-1.html` was advice to change a setting that could not do anything. A span is a hook when it is named `<hook> hook`, a listener when it is named `<callable> @<priority>`, and a custom event otherwise; each now gets the proposal that is true for it. The already-significant check normalizes the ` hook` suffix too, since a rule may spell it either way.
- **The Ask brief opened UNDER the modal that summoned it.** It renders through the plain-DOM shared modal while the URL and request detail views use a `@wordpress/components` one, which portals to the body — same z-index, later in the document, so the brief lost every time. The backdrop is where that layer lives, so it is raised there (`backdropClassName`, one line of SCSS) rather than by moving the dialog onto WordPress's modal, which cost it its width, its padding, and closed the detail view underneath it.
- **A reopened stream came back tailing the log.** The gyroscope and the two log browsers each recomputed a reconnect's seek from what the stream had READ — and for a stream refused before its first frame (the SSE slot pool's 429 after a fast tab switch) that is the tail, so a browser asking to replay came back live. Each reopens through the substrate's `RemoteLink::reconnect()` now, which states no seek: the stream resumes past what it read and keeps the seek it opened with where it read nothing.

- **The `?url=` / `?request=` deep link asks once at a time, and slower each miss.** Moving off `useReconcile` dropped both its in-flight guard and its backoff, so an intent that never resolves — a hash that has paged out, a request id from another environment — sent a command every second for the life of the page, each one queueing another waiter behind a reply that was never coming. Backoff runs 1s to 30s and resets when the intent resolves.
- **`url_detail undefined` no longer reaches the server.** `make_node Timer` with no interval arms on the router immediately, so the auto-refresh Fetcher could fire before the effect that owns its arming had disarmed it. Its fire-time getter returns `null` — nothing to ask — unless it holds a valid hash.

### Added
- **`Findings`** — the problem area, computed rather than inferred. Dominant span, repetition, unattributed time, entry gaps and truncation are all arithmetic over data already on disk, and `request_detail` now carries them, so the detail view can name what is wrong with no model involved at all. Each finding says WHERE it was measured (`flame` and `subtraction` warrant different confidence) and what rule edit would act on it.
- **"Insufficient instrumentation" is a first-class finding.** The common first ask is about a slow URL that no rule covers, where there is no flame graph and no entries — only a total. The useful answer there is not an explanation but WHICH INSTRUMENTATION TO SWITCH ON, so the proposal is a coarse lifecycle bracket (six hooks) and the next round subdivides only the phase that held the time. Every proposal that adds instrumentation names what removes it again.
- **The `?` picker.** One "Ask AI" button; the cursor becomes a `?` and the next click decides everything — a slow URL, a request, a flame span, a log line, a breakdown row. THE TARGET IS THE SCOPE, so there is no per-surface branching: any element becomes askable by carrying one `data-ask` attribute, and DOM nesting supplies the context. Cmd/Ctrl-click adds to the selection, matching the modifier already shipping on those same elements. Keyboard-reachable; a picker click is not consent to send, and the assembled brief is shown before anything is copied.
- **`ask` verb + `Ask_Assembler`** — the brief for one thing, and nothing else: a span brief is a subtree, its siblings and its parent's total, not 47 entries in the hope the model finds the part you were looking at. URLs go through the firehose's own `redact_url()`, the environment is allowlisted rather than filtered, and the measurement caveat rides on every payload.
- **An MCP server** at `POST /newspack-event-logger-nodes/v1/mcp`, wrapping verbs that already exist. Authenticated by a scoped session presented as a Bearer credential: the request becomes the session's minting user and the scope is applied as a CEILING, so `tools/list` offers only what that session can actually reach. An in-plugin LLM call would have shipped faster and bought a dashboard that summarises itself to one model behind one proxy publishers cannot reach — and that could not see a Linear issue at all.

### Changed
- **Requires substrate 2.31.0.** Every verb here declares a capability role, and the MCP controller builds its request graph with `Bootstrap::mount_request_graph()` — below that version the `tune` verbs throw "unknown capability role" and every MCP call fatals on an undefined method.
- Every application verb now DECLARES its capability role instead of defaulting to manage by omission: the dashboard slices are `read`, the ruleset writes and the settings receiver are `tune`. The hard-coded `require_manage_options()` at the top of every performance handler is gone — it silently overrode the declaration — and a test asserts it stays gone.
- `Log_Manager::redact_url()` is public: it is the ONE redaction path, and anything sending a URL somewhere it was not already written goes through it rather than a second pattern that would drift.

### Fixed
- **The findings detector read the wrong key.** A LOADED request carries its flame tree at `flame_data` — `Performance_CI` merges the flames partition in under that name — while only a FOLDED record ever carries `flame`. Reading `flame` alone made every ordinary request report "nothing is measured" plus a bogus "N of N went unmeasured", and made every flame-span click a dead end. `Findings::flame_of()` is the one reader.
- **The governing rule was re-derived, and could never match.** A stored record's `url` is absolute and query-stripped (`https://host/path`) while rules are path patterns, so not even a catch-all `/` matched: every brief said "no rule governs this URL" and proposed creating one whose pattern could never match anything. The record already carries the answer the request itself resolved (`rule_id`); the `url:` brief, which has no record, matches on the PATH.
- **The page-facts block could be escaped out of.** `JSON.stringify` does not escape `<`, so a `</script>` in a logged URL or entry payload ended the element and the rest parsed as HTML — in wp-admin, from attacker-controlled input. `factsJson()` escapes `<`, U+2028 and U+2029.
- A category clicked inside a request answered from the site-wide leaderboard, describing something else entirely and dead-clicking on any category absent from the recent global window. The context chain decides which board answers, and the brief says which (`scope`).
- "Insufficient instrumentation" claimed "the governing rule registers no hooks" for a well-instrumented rule whose request simply produced no spans — a factual falsehood at severity `high` that proposed adding hooks the rule already had.
- The MCP door lacked `Bootstrap::fleet_gate()` (a subsite reached the main site's fleet) and any rate limit (MCP bypasses `/command`, and `request_grep` walks every partition's index). Both added; the listing now checks the minting user's capability, not the scope alone.
- A JSON-RPC notification got a response, which the spec forbids. Byte-wise `substr` on an entry payload could split a codepoint, making `wp_json_encode` return false and the whole MCP reply come back empty rather than as an error.
- A request-detail lookup that missed re-walked the caller's partition, costing N+1 index scans rather than N.

## [0.55.1] - 2026-08-14

### Fixed
- Repinned to newspack-nodes v2.30.1, which stops the log viewers double-compensating their scroll position: scrolled into history, new rows shifted the list by more than the rows that arrived. Request Log and Error Log inherit the fix through the shared `LogRowList`.

## [0.55.0] - 2026-08-14

### Fixed
- **One number answers "how many firehose partitions?": the config's `num_partitions`.** `firehose_dirs()` unioned that with whatever partition count the active topologies declared, so a topology's `var num_partitions` — its WORKER count, and legitimately pinned to 1 by `hub-control` — argued with the writer about a layout it does not own. `init_firehose()` has always hashed the rid over the config count alone, so that is exactly the span that can hold data; readers now span it and nothing else. A hub fanning in more spokes raises `num_partitions`, in one place. Readers also stop parsing TSL to ask, which takes the topology parse off every dashboard query.

- **A deep link carrying both `?url=` and `?request=` opened an empty modal.** Resolution branched on the URL hash first and returned, so the rid — the more specific key, which answers both the URL hash AND the partition — was never resolved. The partition was then reconstructed from `urlDetailData.requests`, a page of RECENT requests, which cannot answer for an older rid; `request_detail` was never sent, and both modal sections gate on `selectedRequest`, so neither rendered. One resolver now runs for any deep link, keyed on the rid when present.
- The hand-rolled retry (an in-flight ref re-running on every `urls` tick) is replaced by `useReconcile`: while unsettled it keeps attempting, on success it stops. Graph not built, no command session, a reply that lost its race, a hash outside the loaded page — five silent failure paths became one retry with backoff. A rid the index cannot find falls through to the URL hash instead of retrying forever.
- **The partition travels WITH the selection** from every entry point — deep link, request row, scatter-plot dot — instead of being reconstructed downstream. `onSelectRequest` now receives `( rid, partition )`.
- A selected request whose detail has not arrived renders a pending or error state instead of a blank panel, and the Back button survives a request that failed to load. An unresolvable partition reports an error rather than returning before even the loading state.
- Request Log and Error Log inherit the substrate's ingest-gate filtering; their match predicates moved onto their view nodes as `matchesFilter()` overrides.

## [0.54.0] - 2026-08-14

### Fixed
- A folded request keeps every entry its producer marked `keep`, however far from the end it landed. Measured on a live 124k-entry render, pyrobase's stats summaries sit 11-15 entries from the end — behind `pyrobase (complete)` and six WordPress shutdown hooks — so the bounded tail dropped all of them, silently, and they are the only place a request's cache hit rates appear. The mark travels on the log line rather than in config, because the builder runs in a worker and the producer in a web request; the keep bucket is unbounded, since capping it would reintroduce the loss the mark exists to prevent. `FOLD_KEEP_TAIL` is unchanged at 10.

### Changed
- `Stats_Store` reads and writes through `Table_Node` instead of the raw memcache handle, closing the last non-goal of the 2026-08-10 two-tier-cache spec. Two Tables over one `evlog:p{N}` namespace carry the two TTLs, so no per-call TTL was needed; the mirror seam stays here and now gates on `Table_Node::store()`'s new boolean. **Stats keys change shape** (`…:evlog:p0:hourly` → `…:table:evlog:p0:hourly`), so existing stats and any pre-port mirror frames orphan on deploy — one cold window, cleaned up by TTL, exactly as a salt rotation does.
- `Flame_Builder_Node` drops its own `LRU_Cache` and accumulates through the store's Table instead (`Stats_Store::accumulate_url_stats()` / `accumulated_url_stats()` / `accumulating_url_stats()` / `reset_url_stats()`). It was already an in-memory tier in front of a Table lookup, hand-rolled one layer up; there is one tier now. Geometry (1000 × 5) moved to `Stats_Store`, and `STATS_CACHE_BUCKET_SIZE` / `STATS_CACHE_NUM_BUCKETS` are gone.
- With no `Stats_Store` wired, per-URL aggregates are no longer accumulated at all. They never could be persisted — `mirror_url_stats()` returns early without a store — so this only stops the wasted memory.

## [0.53.0] - 2026-08-14

### Fixed
- `end_job_context()` no longer tears down the enclosing request context when a job was DECLINED before any context was opened. `resume()` ran unconditionally — the empty-stack guard only covered the `$_SERVER` restore — so on a spoke, every foreign template both marked the worker's own request aborted and finished it. The `$job_server_stack` depth is now the pairing record. The arity discriminator also moved from `>= 2` to `>= 3` with the inserted `$id`.

### Changed
- `Log_Manager::end_job_context()` takes `( $handler, $id, $outcome )`, following the substrate's reordered `after_job` action; it is registered with `accepted_args` 3. A bare `end_job_context()` is still a plain context restore — the outcome remains the last parameter, so arity stays the discriminator.

### Added
- `Log_Manager::begin_job_context_filter()`, the `newspack_nodes/job_worker/before_job` listener for the substrate's new filter contract (requires newspack-nodes with the filter). It opens the job context unless an earlier listener declined, and passes the decision through untouched — whether a job belongs to this host is the owning plugin's question.

### Fixed
- A job id that is an absolute URL no longer nests a second scheme and host inside the synthetic request URI. `/jobs/evtemplate/https://hub/Tools/UpdateSite.html` — which read as though the executing host served it — is now `/jobs/evtemplate/Tools/UpdateSite.html`, with the real host still supplied by `SERVER_NAME`.

## [0.52.4] - 2026-08-14

### Fixed

- **The `#` rule in `wp nodes reqgrep` marks the boundary between requests.** It only ever fired
  from the entry-number rewind heuristic — and since requests are grouped by rid before they are
  formatted, and the number counter resets per request, a rewind could never happen at a genuine
  boundary. The rule marked the one place that was never one, and never marked the places that
  were. It now comes from the rid changing, with no leading rule before the first request.

## [0.52.3] - 2026-08-14

### Fixed

- **`wp nodes reqgrep` reads a nested engine trace the way the dashboard does.** A request that
  renders through nuclear-gyrobase carries a second source under the same rid, numbering its
  entries from 1. reqgrep read that rewind as a new request and split one request in two behind
  a `#` separator, resetting the indent and orphaning every span still open across the trace.
  Requests are grouped by rid before they are ever formatted, so a rewind inside a group can
  only be a second source — never a boundary. Indentation now matches each `(complete)` to the
  nearest open `(start)` of the same NAME, which is what carries the enclosing spans across the
  nested trace: the same rule the request-detail dashboard's `computeIndentedEntries()` already
  used, so the two surfaces no longer read the same request differently.

## [0.52.2] - 2026-08-14

### Fixed

- **Bundles substrate 2.26.1, which stops an aggregator re-delivering the last record it
  read.** A `Remote_Source` committed its cursor at each record's START rather than past it,
  so every resume — a worker recycle, a reconnect, a restart after a checkpoint — replayed
  that record. The hub saw one duplicate per resume, adjacent to its twin and carrying an
  identical breadcrumb, while the spoke it pulled from showed none.

## [0.52.1] - 2026-08-13

### Documentation

- **`aggregator.tsl`'s per-spoke recipe carries `cmd spoke-<id>:config
  set_multi_writer true`.** Every firehose spoke wants it: the log is appended by
  every request process there, and the verb (new on the substrate's
  `Remote_Source_Node`) asks the SPOKE's reader to hold a superseded segment for the
  seal grace. Without it a
  straggler's last line — typically the request's terminal `process (complete)` — is
  orphaned, and the request never finalizes on the hub.

## [0.52.0] - 2026-08-12

### Fixed

- **`request_detail` mints from its own receiver, not from the view.** Its
  command went out `FROM requestdetail:view`, so that one node was both the
  minter and the reply sink — carrying its own controls and a command reply on
  the same `fill()`. Every other slice mints from a receiver Tee and forwards to
  its view; `requestdetail:in` now does the same, leaving the view a view.

### Changed

- **Performance-dashboard node names follow `<slice>:<role>`.** The polled
  fetchers and receiver Tees were the only nodes in the graph named another way —
  `fetch-overview` / `overviewIn` beside `overview:view`, `urldetail:merge` and
  `urldetail:timer`. They become `overview:fetch` / `overview:in`,
  `urls:fetch` / `urls:in` and `urldetail:fetch` / `urldetail:in`, and the two
  that were inline strings gain constants like the rest. Canvas layouts persisted
  against the old names will not match and re-lay-out once.

### Changed

- **`Line_Fitter` is the substrate's, and the floor rises to 2.25.0.** The PIPE_BUF fitting loop was this
  plugin's, but the substrate hand-rolled the same measure-and-halve in two of
  its own nodes. It now lives at `\Newspack_Nodes\Line_Fitter`; the three call
  sites here are unchanged apart from the import.

### Fixed

- **Clear sends the view's control instead of relying on a fallback.** The Error Log
  and Request Stream passed no `onClear`, so the shared `LogStreamViewer` reached
  past the graph and assigned `node.lines = []` directly. Both now pass the `clear`
  their graph hooks already returned, and the substrate's fallback is deleted.

- **`dump_config` quotes every name it emits.** Each `command_node <name>:config`
  line was built by interpolating the node name raw, so a node whose name held a
  space emitted a line with one token too many and replayed as a different graph.
  The lines now go through the substrate's `Node::config_line()`, which serializes
  the whole token list (substrate v-next). Dump output uses the canonical
  `command_node` verb; `cmd` remains a valid input alias.

### Changed

- **Segment sizes render through the shared formatter ladder.** `SegmentBrowseSidebar`
  imported the substrate's one-decimal `formatBytes` shim, which capped at MB; it now
  imports `{ formatBytes }` from `shared/utils/formatters`, which guards both ends and
  carries GB/TB. Visible change: a size on an exact rung loses its trailing `.0`
  (`2.0 KB` → `2 KB`), and a GB-scale segment no longer renders as thousands of MB.

- **The three dashboard graph hooks share the substrate's `controlMsg`.** Each carried
  a private copy that closed over its own `VIEW` constant; the shared one reads
  `view.controlFrom` and throws when a view declares none.

## [0.51.4] - 2026-08-11

### Fixed

- **The URL detail chart's filter row sat flush against the header above it.**
  Its wrapper carries no top margin, so the Metric / breakdown selects had
  nothing separating them. The aggregate chart's matching row takes the same
  `margin: 12px 0`, though there it changes nothing — that wrapper already has
  `marginTop: 20px` — so the two rows now read as one rule rather than two
  spacings that happened to agree.

## [0.51.3] - 2026-08-11

### Removed

- **`topologies/combined.tsl` and `topologies/jobs.tsl`.** Both were dead
  duplicates: `complete.tsl` replaced `combined` when the topologies were
  reorganized (v0.44.12), and it includes `job-hub` rather than `job-router`,
  so it dispatches jobs as well as routing them. An install still naming
  `combined` or `jobs` in the substrate's `topologies` config key must switch
  to `complete`. The docs kept describing `combined` after the reorganize —
  the architecture guide's read-path diagram, its per-topology section and the
  README topology list all say `complete` now.

### Changed

- **Substrate pin moves to newspack-nodes v2.24.0.** The browser Shell now has
  one entry point, `fill( message )`, and the debug overlay this plugin embeds
  mounts a `_stdout` node for builtin output. Nothing here called the retired
  `ShellNode.sendCommand()` / `parse()`+`dispatch()` pair, so no source change
  was needed — but the inlined overlay is only rebuilt against the new
  substrate by a release, which is what this one is for.

## [0.51.2] - 2026-08-11

### Fixed

- **Rebuilt against substrate v2.23.0, whose SSE client fixes the resume.**
  Every dashboard stream here inlines `SseInNode`, which used to depend on the
  browser's `Last-Event-ID` header — a header a freshly-constructed
  `EventSource` never sends. So switching back to a tab of viewers tail-seeked
  past exactly the window you returned to read. The client now owns its
  reconnect and carries its own `positions`.

## [0.51.1] - 2026-08-10

### Fixed

- **A hub whose topology isn't named `aggregator` now counts as a hub.**
  `<eln:is_hub>` matched the active topology's NAME, so a deployment that
  forks stock `aggregator.tsl` to change one argument — and therefore renames
  it — read as a spoke. `Flame_Builder` gates every per-server namespace write
  on that flag while the global `server` dimension is not gated, so the
  Performance Dashboard listed servers it then had no data for: the volume
  chart flat, the breakdown "across 0 requests", for every server picked.
  The derivation now also walks each active topology's graph for a
  `Remote_Source` node — the same question the Aggregator dashboard already
  answers correctly. Both signals are kept: stock `aggregator.tsl` ships no
  readers (the operator wires them on the canvas), so it is recognisable only
  by name, and a fork is recognisable only by its wired readers. This fixes
  new traffic; already-recorded requests need replaying to backfill.

## [0.51.0] - 2026-08-10

### Removed

- **The `MAX_LOG_LINES` line-limiter is gone.** Past 40000 lines a request's
  `start()` pushed a MUTED timer frame — still timing the operation, emitting
  neither its start nor its complete line — and `complete()` and the orphan
  drain skipped it. Folding bounds a request's entries now (`entry_budget`
  and `max_entries_per_request`), so a second volume guard that silently
  holed the span stream earned nothing. `line_limited`, the frames' `muted`
  flag and the four tests over them go with it.

> **Requires a substrate newer than 2.21.0** — the `commandClient` parameter on
> `useVisibilityGatedLink`. Tag `newspack-nodes` FIRST, then bump here so
> `bump-version.sh` repins `release.yml`; releasing against the current
> `ref: v2.21.0` ships a hook that ignores the option, and the workflow stays
> green while the seam is silently dead. (`reservedNames` and `LIVE` both
> exist at 2.21.0, so the other edits below are pin-safe.)

### Changed

- **The `commandClient` seam is gone.** Every dashboard hook that accepted it
  — Request Log, Error Log, Performance, Rules, Hook Catalog — no longer does,
  and `PerformanceDashboard` drops the pass-through prop. Injecting a
  transport replaced the whole subsystem, so a hook test never ran HttpOut,
  pack/unpack, the router or the interpreter; the suites now seam at `fetch`
  via the substrate's `installFakeCommandWire`, which four of them already
  used. Five tests whose SUBJECT was the injection now assert the graph
  reaches the wire with nothing injected.

  The two link hooks had also been stamping `_http` by hardcoded name,
  unguarded — writing `undefined` over the backbone in production and
  surviving only because HttpOut re-defaults. That goes with the seam. The
  ruleset graph's local `const HTTP = '_http'` becomes `reservedNames.HTTP`,
  and `useGlobBrowse`'s `?? 'live'` fallback reads the substrate's `LIVE`.

## [0.50.0] - 2026-08-10

### Changed

- **`LRU_Cache` moved to the substrate** as `Newspack_Nodes\LRU_Cache`.
  `Table_Node` uses it as an L1, and the substrate cannot depend on a
  consumer. Behaviour is unchanged and every holder here — the in-flight
  request maps in `Request_Builder_Node` and `Reqgrep_Command`, the per-URL
  accumulator in `Flame_Builder_Node` — keeps promotion on, which is what
  makes eviction mean "this request never completed".

- **`Rule_Set` reaches the hooks table through an instance**, following the
  substrate's `lookup()` / `store()` / `forget()` becoming instance methods.
  The table is memoized per process and is null on a host with no cache
  backend at all — then there is no warm mirror and the durable option
  answers alone, which is exactly what every read here already fell back to.
  It keeps its read-through semantics: no L1, because a ruleset saved from
  wp-admin must reach a worker on its next read, not at the end of a window.

- **A folded request keeps its head and tail, and its merged spans read as log
  rows.** Folding used to reclaim the whole entry list, which took the request
  line, the environment map and the closing stats block along with the
  repetitive middle — the ten rows at each end that are worth most. Both ends
  survive now, rejoined around an `entries (aggregated)` marker, and the merged
  tree is spliced in between them as nested `(start)`/`(complete)` pairs rather
  than tabled separately: the log already renders a tree, and a flat table
  stringified the nesting into `a / b / c`. Each merged row carries the
  instances it stands for, a real timestamp derived from the node's own start
  offset, and an INCLUSIVE duration like every other duration in the log.
  - Spans straddling either seam are not shown twice. One still open when the
    head ended is skipped whole; one the tail closes but never opened is
    opened by the tree and closed by the tail's own row.
  - Instances already visible in the kept ends are subtracted from a merged
    row's count, so nothing appears once for real and again as a ghost.
  - `entries (lost)` and `entries (aggregated)` now close every span open
    across them, except the request itself. Left open, a severed span adopted
    every row after it and folding it swallowed the end of the request.
- **`max_entries_per_request` folds instead of truncating.** Past the cap it
  stopped appending and set a `truncated` flag nothing read, losing every entry
  after it silently. It now folds that one request — a faster trigger than the
  pool budget for a single runaway rather than a worse one — and is a
  positional argument so it can be sized per deployment.
- **`Flame_Fold` nodes carry `t`, and `spans` is gone.** Each merged node holds
  the offset its earliest instance started at, which is what lets the log stamp
  its rows and the graph position the frame. That made the parallel path-keyed
  `spans` map redundant; it held nothing the tree did not.

### Fixed

- **The gyroscope and the completed record disagreed about a worker's URL.**
  `Request_Flight_Node` read the raw `url` while completed records went through
  `Request_Builder_Node::resolved_request_url()`, which appends the worker
  type. A job's execution and the request that enqueued it — both logging
  `/jobs/{handler}/{id}` — collapsed onto one URL row, so the gyroscope link
  resolved to whichever came first. Both now use the one resolver.
- **Folded trees overflowed their own frames.** `Flame_Fold::flatten()` sized a
  parent to `max( own, Σchildren )` while positions make the browser fill gaps
  with spacers, so children reach the EXTENT, not their sum. It now mirrors
  `Flame_Tree::cover_children()`. `Flame_Builder_Node` no longer lets the
  request duration overwrite a root that covering had already raised, which an
  aborted request's truncated duration could undercut.
- **The `entries (lost)` marker landed in a folded request's kept HEAD**, filing
  the loss chronologically ahead of the middle it announces. It goes to the
  tail, where the request actually stopped, and no longer skews the count of
  what was merged away.

### Added

- **Request_Builder bounds the memory it holds across ALL in-flight requests.**
  `MAX_ENTRIES_PER_REQUEST` capped the wrong quantity: one envelope at 50,000
  entries measures ~18MB, which is fine once and fatal twenty times over — and
  twenty concurrent is what a long template render produces. A new
  `entry_budget` argument (default 50,000, ~18MB) bounds the sum; crossing it
  folds the LARGEST envelope through `Flame_Fold`, the merging variant of the
  flame stack machine, and drops its raw entry list. Cost becomes O(distinct
  paths) instead of O(messages), so a fifty-minute request costs what a
  five-minute one does. The per-request cap stays as a secondary guard.
  - **Folding is a pressure valve, not the normal path.** Under budget the
    cost is one integer compare per entry and the record is byte-for-byte what
    shipped before — full chronology, raw entries, no flag.
  - **A folded request stays folded**, merging later entries straight into its
    path map, and cannot un-fold.
  - **Pair balance is inherent**, because the fold is the same stack machine.
    That is what disqualified dropping every Nth entry: an orphaned complete or
    an unclosed span yields a plausible-looking, quietly wrong graph.
  - **Records carry either shape.** `Flame_Builder_Node` accepts raw `entries`
    or a pre-built `flame`, so nothing already on disk needs rewriting and
    there is no dual-write window.
  - **What folding costs is the sequence.** A folded record keeps its first and
    last ten entries verbatim — the block that identifies a request and the
    block that ends it — rejoined around an `entries (aggregated)` marker, and
    the merged tree is spliced in between them as ordinary nested
    `(start)`/`(complete)` rows carrying each path's instance count. The detail
    view says the request was aggregated rather than leaving an empty table
    that reads as "nothing happened".
  - **The stats a request contributes are unchanged by folding** — leaderboard,
    categories, hourly and per-URL are identical either way, and a test asserts
    it. The per-URL aggregate FLAME is the exception and cannot be otherwise:
    unfolded, forty `save` siblings merge in as forty numbered nodes; folded,
    as one node holding their total. Same time attributed, different shape.
  - `Reqgrep_Core` holds the same shape of state and is deliberately left
    alone — its product IS the raw line stream, so merging would leave it
    nothing to print.
- **The flame graph's x-axis is now chronological.** Frames were positioned
  left to right in alphabetical order, so the graph looked like a trace and was
  not one: widths were honest, positions were not. `Flame_Tree` now stamps each
  span with `t`, its start in milliseconds from the request's own, and
  `FlameGraph` orders and positions frames by it. Time no frame accounts for
  renders as empty space instead of being smeared into the spans either side —
  which is the thing you are hunting in a ten-minute request.

### Changed

- **`Flame_Tree` stamps `t` in place of the old per-node `ts`.** That field held
  each span's completion time, put through `Core::num_int()` — which truncated
  a microsecond-resolution `microtime()` reading to whole seconds. Nothing
  displayed it (`finalize_flame_node()` stripped it before display; its only
  reader was the aggregate's hour-granularity expiry cutoff), so nothing was
  visibly wrong — but it could not have carried an axis, which is why the
  feature above needed a new field rather than the existing one.
  `build_flame_data()` sheds the `$now_ts` parameter that fed its fallback, and
  aggregate nodes take their expiry timestamp from the merge clock, which is
  what it always meant.
- **An unclosed span is covered by its children's time extent**, not their sum,
  so a gap between two of them no longer vanishes into the spans either side.
  The sum stays the floor: where two spans overlap, the extent is the smaller
  of the two and covering by it alone would paint them past the parent's edge.
  Aggregates, which carry no positions, are unchanged.
- **A pointer rule's hooks mirror rides the substrate's `Table`** rather than a
  hand-rolled memcache key, through `Table_Node::store()` / `lookup()` /
  `forget()`. Requires newspack-nodes **2.21.0**, and the loader's floor moves
  with it. Existing `evlog:rules:hooks:*` entries are orphaned and expire on
  their own hour TTL; the durable option is the record and rewarms the table on
  the next read, so no rule loses its hooks.

### Fixed

- **Two installs sharing one memcached could read each other's rule hooks.** A
  rule's id is the `url_hash` of its pattern, so every install that logs `/`
  derives the same id — and the old mirror key carried no install scope, only a
  fixed `evlog:rules:hooks:` prefix. `Table_Node::entry_key()` goes through
  `Cache_Backend::site_key()`, which scopes by database, table prefix and salt.
  Reachable wherever co-tenant installs share a memcached, which the local dev
  stack does.

## [0.49.2] - 2026-08-10

### Changed

- **Dependencies updated within range** — phpstan 2.2.8, vipwpcs 3.1.0,
  dead-code-detector 1.3.3, phpunit 10.5.64, esbuild, knip, babel presets.
- **The `@wordpress/*` packages now follow the `wp-7.0` dist tag** rather than
  npm `latest`: they are build externals mapped to `window.wp.*`, so core
  supplies the code and the npm copy is only the API contract we compile
  against. `react`/`react-dom` are pinned to the major WP 7.0 bundles, which
  also resolves a duplicate React that broke every hook under test.
- Plugin header now carries the same fields, in the same order, as its siblings.

## [0.49.1] - 2026-08-09

### Changed

- **`jobs` and `job-hub` declare `stale_timeout = 600`**, matching `job-spoke`.
  They sat at 60 on the assumption that a blocking handler pumps the
  continue-predicate at its fetch point — true of the handlers that do, and
  silently underwritten by the substrate's SIGALRM for every handler that does
  not. With that alarm removed (newspack-nodes), a handler working past the
  window without reaching `should_continue()` stops heartbeating and a peer
  steals its lock mid-job, replaying the job to die again.

## [0.49.0] - 2026-08-09

### Added

- A request running in job context logs a `message` line naming the record that
  caused it — FROM, ID and KEY off the job message the substrate now hands to
  `before_job`. ID is the `segment:offset:length` the Consumer stamped, so a
  trace seeks straight onto the log instead of leaving you to find it.

  The message is stashed unstacked on purpose: a nested context — evTemplate
  rendering inside a job — passes none and inherits the enclosing job's, which
  is what makes the causing record reachable from the innermost trace.
  `begin_job_context()` takes it as its third parameter, ahead of `$server`, so
  the action's positional arguments line up; the one caller that passed
  `$server` positionally now names it.

### Changed

- **The firehose registers the dir template `Log_Manager` actually writes.**
  `newspack_nodes/registered_log_producers` now carries a path template rather
  than a basename, so `Log_Manager::firehose_dir_template()` — already "the one
  place its layout is written" for the Topic — is what the substrate's log GC
  and the Workers catalog expand. The plugin no longer registers `jobintake`:
  that is `Job_Intake`, substrate code, which registers itself. Requires a
  newspack-nodes carrying the producer-template contract — the two deploy
  together, so the filter's old bare-basename form is gone rather than shimmed.

- `reqgrep`'s default partition dir comes from `Log_Manager::firehose_dirs()`
  rather than a second hand-spelled `firehose.p0`, so the firehose layout is
  written in one place as its docblock claims.

## [0.48.0] - 2026-08-09

### Changed

- Substrate pin moves to newspack-nodes v2.16.1 → v2.17.1.

- One spelling for a generic type in docblocks: `array<string,mixed>`, no space
  after the comma. Both parse identically; only that one highlights as a single
  type in the editor. `lint-comments.php` carries the rule now, so it holds.

### Fixed

- A request whose entry sequence broke now closes on `process (complete)`
  instead of being held back. It used to sit in the LRU until a bucket rotated
  it out — minutes of memory for a render that had already finished — and then
  surface as `T`, telling the reader it timed out when it had not. One
  unparseable line cost a 12-minute phantom request in production.

  It closes flagged, not clean. The hole is a line in the trace — an
  `entries (lost)` row in the missing entry's own slot, naming both the entry
  that broke the sequence and the last one that arrived in order, which is
  where a re-read of the firehose starts — with that entry's
  `segment:offset:length`, the ID `Consumer` stamps, so the line that broke the
  sequence is one seek away. It does not claim the rest went
  unsent: they generally did arrive and were discarded for being out of
  sequence, which is the difference between re-reading the log and hunting for
  a writer that died. With no resync there is exactly one hole and it is always
  at the tail, so the row sits between the last good entry and the terminal
  one. The list view, having no trace to put a line in, carries a new `I` error
  status.

  Resyncing past the hole is deliberately not done. The entries behind it are
  out of order, so the trace and its flame graph are unusable regardless, and
  `Job_Router` never reads `n` — it forwards every job request either way.

## [0.47.1] - 2026-08-09

### Fixed

- **Substrate pin moves to newspack-nodes v2.16.1.** The bundled runtime and
  debug overlay carry its fixes: a log stream that resumes at EOF closes on the
  first tick instead of holding a worker for the whole idle window, the
  browser's SSE node is named `<link>:sse-in` so `trace` can reach it, a
  `set_state` transition is traced like the PHP twin's, the heartbeat's
  expected `slot_released` no longer counts as an error, a CONNECTED state no
  longer publishes the lease owner into the transcript, and "reset stats"
  clears the overlay's message list rather than leaving it on screen.

## [0.47.0] - 2026-08-08

### Added

- **`job-feed`, `job-spoke` and `job-hub` topologies — a spoke stops waking for
  every web request.** `job-router` tails the firehose to find `k:"job"`
  entries, so on a spoke every logged request woke the worker pool. `job-feed`
  is the same graph reading `jobfeed.p<N>` instead, the substrate's small-job
  log (`Job_Intake::feed()`), and `job-spoke` composes it with `job-worker`.
  `job-hub` keeps the firehose leg, because that is where an aggregator's
  rewritten `remote_job` entries arrive. `complete` now includes `job-hub`;
  `jobs` stays until the hub is migrated.

### Changed

- `gyroscope:partition` and `completed:partition` take their segment size and
  let every retention argument default, instead of restating `min_segments` /
  `num_segments` and pinning the rest to `0`.

## [0.46.0] - 2026-08-08

### Fixed

- **Every job logged as `POST /jobs/{handler}`, whatever it actually ran.**
  `begin_job_context()` writes the synthetic `$_SERVER` and then fires
  `newspack_event_logger_nodes_scope_changed`; `App\Core` answers by calling
  `Log_Manager::instance()`, whose constructor picks the governing rule off
  `REQUEST_URI` and writes the `request` line from `REQUEST_METHOD`. So a
  caller describing a different request — pyrobase rendering a template as
  `GET /Admin/Foo.html?a=1` — assigned `$_SERVER` one statement too late and
  was silently ignored: 22,939 job request lines locally, not one of them
  `GET`. The query string was lost outright, since it is omitted from
  `ENV_ALLOWLIST` on the grounds that the `request` line carries it, and that
  line carries only `REQUEST_URI`. `begin_job_context()` now takes an optional
  `$server` overlay applied before the action fires; the hook binding keeps its
  two `accepted_args`.

### Changed

- **`Log_Manager::$enabled` and `ensure_started()` are gone; `is_started()`
  replaces them.** Since matched requests start eagerly at construction,
  `ensure_started()` could only ever report the state the constructor had
  already decided, and `enabled` duplicated `started` — except after
  `finish()`, where `enabled` stayed true and `started` went false. Callers
  that instrument their own work (`App\Core::hook_start`, the profiler
  drop-in) gate on `is_started()` now, so a hook firing during shutdown no
  longer gets wrapped for a log nobody will write.
- **Update `00-newspack-profiler.php` with the plugin.** `$enabled` was public,
  and the drop-in ships as a separate release asset installed into
  `mu-plugins/` on its own — so a site that updates one and not the other
  leaves the old drop-in reading a property that no longer exists. It resolves
  to null, reads as "not enabled", and the profiler stops contributing its
  request-start readings without erroring. Nothing else in the tree read
  `$enabled`.

## [0.45.1] - 2026-08-08

### Fixed

- **The LRU's catch-up never ran in the restart path, which is the only path
  that matters.** v0.45.0 put the rotation boundary on an absolute grid so a
  restored cache keeps its predecessor's phase, and had `rotate_if_due()` repay
  every elapsed window — but `next_window` stayed out of `get_state()`, so each
  generation re-derived it from its own start and skipped every window the gap
  covered. With 30s generations against a 200s window that is most of them, and
  the repayment loop was dead code. The boundary now rides in the snapshot; a
  snapshot without one predates this and keeps the fresh grid.

- **`get()` / `delete()` / `iterate()` scanned the cache's whole index
  history.** Bucket indices are monotonic and persisted, so `current` only ever
  climbs while `num_buckets` buckets exist; counting down from it meant a MISS
  — one per firehose line that opens a request — walked every dead index. A
  live worker was at 2053, which measures ~13µs per miss; the defect is that it
  grows without bound (~630µs at 100k). They now walk the live bucket keys, so
  the cost is `num_buckets`.

- **`purge` was declared as a configuration verb**, so the topology editor
  offered it as a checkbox whose tick serializes into the `.tsl` and re-runs on
  every worker boot, silently discarding in-flight requests. It is now marked
  `'action' => true` (newspack-nodes ≥ 2.14.6) — still a verb, still invocable
  on a live node, no longer persistable as a setting.

## [0.45.0] - 2026-08-08

### Fixed

- **In-flight requests never aged out, so nothing ever timed out.** `LRU_Cache`
  anchored its rotation clock at construction and left it out of `get_state()`,
  so every worker generation restarted the 200s window from zero. With
  `on_demand_idle` at 30 the worker recycles long before a window closes, and a
  stalled request sat in the cache indefinitely — the Gyroscope dashboard showed
  requests still in `process` at 7871s, and the offsetlog re-serialized their
  entry lists into every commit (7.6–15 MB segments). Capacity rotation still
  fired under load, so the timeout worked when the partition was busy and failed
  when it was quiet, which is exactly when a request stalls.

  Both halves of the fix come from Tachikoma's `Table.pm`. The window boundary
  now sits on an ABSOLUTE grid derived from the wall clock, so a cache restored
  into a fresh process keeps its predecessor's phase and nothing has to be
  persisted; and `rotate_if_due()` rolls once per ELAPSED window rather than
  once per call, capped at `num_buckets` (which already empties the cache), so a
  gap is repaid in a single pass.

### Added

- **`purge` verb on `request-builder`.** Operator recovery for a wedged cache:
  drops every in-flight request and reports the count. The requests are
  discarded, NOT emitted as timed out — a cache needing this can hold thousands
  of entries carrying up to `MAX_ENTRIES_PER_REQUEST` lines each, and answering
  a stuck fleet with that write storm is worse than losing docs for requests
  already known dead. Ordinary ageing still runs through eviction, which emits.

- **Reset to defaults for the Logging Rules section.** Every other setting on
  the page carries a `↺` toggle; the ruleset — the one field the editor writes
  through the `rules` CI instead of the settings form — had none, so backing out
  of a bad ruleset meant `wp option delete newspack_event_logger_nodes_rules`.
  The section now carries the same glyph and the same stock secondary button,
  behind a confirm. It applies at once rather than marking-then-Save, because
  this editor has no form submission to defer a mark to.
- **`rules.reset` verb.** DELETES the stored ruleset option so the file config
  seeds again, sweeps every pointer rule's durable hooks option, invalidates the
  memoized config, signals the fleet to re-read, and reports the seeded rule
  count. Backed by `Rule_Set::reset()`, so it is the fifth write path through
  `Rule_Set` and cannot bypass the tiering and orphan-reconcile invariants.
  Deleting the row is the point: presence is the override, so a stored `[]`
  would pin an explicit "log nothing" over the config seed forever. Two ordering
  details are load-bearing — the row is deleted BEFORE the pointer sweep, so a
  failure between them leaves harmless orphan hook options rather than a live
  ruleset whose heavy rules instrument nothing; and `Config::reset()` runs
  before the read-back, because the per-process config memo has the stored
  option folded in and would otherwise answer with the ruleset just discarded.

## [0.44.23] - 2026-08-07

### Fixed

- **One unfinished job marked every later request in that process aborted.**
  `Log_Manager`'s `aborted` flag is per-request, but `finish()` never cleared
  it, so once a job ended without an outcome the shared instance stayed latched
  and every subsequent render in that worker logged `process (aborted)` — some
  of them while plainly logging "It works!". `finish()` now resets it alongside
  `started`.
- **A bare `end_job_context()` read as an abort.** The null outcome that means
  "the job did not finish" is also the parameter default, and the reconcile
  bridge restores context with no arguments around every WP-Cron pass — so the
  whole process was marked aborted once a minute. Arity is now the
  discriminator: `func_num_args()` distinguishes a caller that reported no
  outcome from one that reported none, and the `after_job` hook is registered
  with `accepted_args` 2, so it always reports.

## [0.44.22] - 2026-08-07

### Fixed

- **The URL table's "Show Errors" button filtered nothing.**
  `handleUrlParamsChange` early-returned unless `search`, `sort`, `order` or
  `offset` changed, and `errorsOnly` was not among them — so toggling the
  filter, which changes nothing else, returned before sending a command. The
  button flipped to "Showing Errors" and the table never refetched.
  `urlsArgs()` had been building `--errors_only=1` correctly the whole time; it
  was never called. The URL DETAIL view's button was unaffected because it
  filters client-side and never crosses that path.

- **Aborted requests were invisible: `error_status = 'A'` did not survive the
  request index.** The fixed-width index writer emitted `A`, but the reader
  accepted only `F` and `T`, so every aborted request came back with no
  `error_status` at all — excluded from the errors filter, no badge, and
  indistinguishable from a clean request. Three independent `F`/`T` lists (the
  `process (complete)` validator, the index reader, the JS filter) are now one
  `Request_Builder_Node::ERROR_STATUSES` constant plus its JS mirror, which is
  what let them drift. `A` also gains a badge and the warning row style a
  timeout gets — a truncated duration, not a failure the request caused.

- **Substrate pin moves to newspack-nodes v2.14.3**, which stops an on-demand
  worker respawning itself at exit. This plugin's `jobs` topology is the shape
  that triggered it: `job-router` writes `jobs.p0` and `jobs:consumer` tails
  it, so the worker marked a wake for itself on every run and came back on an
  exact `on_demand_idle` cadence instead of scaling to zero.

## [0.44.21] - 2026-08-07

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.2**, which stops an on-demand
  job worker being pinned awake by a source log that has never been written.
  This plugin's `jobs` topology mounts `jobintake:consumer` against
  `jobintake.p0`, and a site that has never taken a large-ingress job has no
  such log — so its job worker ran full 10-minute lifetimes and respawned
  instead of scaling to zero, on every spoke.

## [0.44.20] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.1**, a performance release on
  paths this plugin sits squarely on: `Router_Node::fill()` (~1427ns → ~1020ns
  per routed message), `Message::packed()` (~754ns → ~593ns, on every firehose
  write), and `SSE_In_Node`'s read parser, which was quadratic in the line count
  and is now flat — the aggregator's inbound streams are the heaviest user of
  it. All figures measured in the arm64 dev container; treat them as ratios.

## [0.44.19] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.0**, which adds the
  `wp nodes stop` / `wp nodes start` deploy hold. That is the fix for the torn
  deploys that were quarantining good `requests:consumer` and
  `firehose:consumer` records as poison — a plugin update swapping `includes/`
  under a live worker makes its autoloader fail on this plugin's own classes.
  Take the fleet down around the deploy:
  `wp nodes stop && ./deploy.sh && wp nodes start`.

  The same substrate release reworks `dl_requeue` to redeliver a quarantined
  record to the reader's sink instead of appending it back into the source log,
  so the Triage modal's REQUEUE button now works on the oversized records a
  torn deploy stranded.

## [0.44.18] - 2026-08-07

## [0.44.17] - 2026-08-07

### Fixed

- **A request killed mid-flight is closed out as `process (aborted)`, not left
  for the LRU.** The jobs most likely to be cut off are the longest and
  chattiest, which are also the ones holding the most of `Request_Builder`'s
  cache — and a cut-off left the half-built request sitting there until the
  timed bucket rotated it, while the successor had already restarted the same
  job and was building a second entry for it.

  `Log_Manager::end_job_context()` now reads the outcome the `after_job` action
  already passes: `Job_Worker_Node` rethrows a cooperative stop without ever
  classifying one, so a null outcome means the job did not finish. That context
  emits `process (aborted)` with `error_status` `A` instead of the usual
  completion line — which it WAS writing, marking a killed job as clean.

  `Request_Builder_Node` treats the new keyword as terminal exactly like
  `process (complete)`, so the entry is emitted and evicted at once.
  `Flame_Builder` excludes `A` from timing samples alongside `T`: an abort's
  duration is a fragment of the real one and would drag every percentile down.
  The current-request tab labels it `aborted` rather than printing the raw code.

- **Re-pinned to newspack-nodes 2.13.1** for the shared log-browser header
  alignment. `LogRowList` is inlined at build time, so 0.44.16 shipped the
  pre-fix copy in the error-log and requests bundles even though the substrate
  fix existed — a re-pin is the only way a consumer picks it up.

## [0.44.16] - 2026-08-07

### Changed

- **Blank-line runs are collapsed on commit.** `scripts/fix-blank-lines.php`
  joins the shared tooling and runs in `lint-staged` after the comment gate. It
  is token-aware: heredoc and string bodies keep their blank lines.

## [0.44.15] - 2026-08-06

### Fixed

- **Gyroscope's request-stream columns sat left of their own headers.** The
  header and the rows are two separate grids sharing one
  `grid-template-columns`, so identical tracks only line up if the boxes around
  them do — and their horizontal padding had drifted to `8px` vs `12px`, with the
  gap diverging again below 1200px. Both metrics now come from one pair of
  variables, and the breakpoint moves both grids or neither.

## [0.44.14] - 2026-08-06

### Fixed

- **A `performance set` that changed nothing still reloaded the whole fleet.**
  The verb wrote and then signalled unconditionally, so a hub's settings-sync
  sweep — which re-pushes every synced option on its interval whether or not it
  moved — fired `Config::RESET_ACTION` on every worker once per sweep, each one
  re-globbing and re-parsing every `.tsl` to reach the answer it already had. It
  now returns `updated: false` and signals nothing when the stored value matches.
  Same fix as the substrate's `Settings_CI::cmd_set`. `Rule_Set::save()` still
  signals a reload — never a restart — on every write, including one that changed
  nothing: it has side effects beyond the rules option (durable hook options, the
  memcache mirror, orphan reconciliation) that can move while the option does
  not, so gating it needs more than a value comparison. The cost is the wasted
  re-parse, not a process recycle.

- **Documentation named a supervisor that has not existed since substrate
  2.11.0.** The architecture guide claimed the job-context pair also bracketed
  the reconcile pass via `newspack_nodes/{before,after}_supervisor_run` — hooks
  renamed to `{before,after}_reconcile` and, in this plugin, no longer wired at
  all. Removed the stale claim and the remaining supervisor-era vocabulary from
  the guide, the admin docblock's restart-classification list (the
  `'supervisor_only'` token is gone from the substrate), and the worker-type
  fixtures, which now use the `reconcile` label the substrate actually emits.

## [0.44.13] - 2026-08-06

### Changed

- Dependencies: `fast-uri` 3.1.4 -> 3.1.5 (the high-severity advisory) and the
  `softprops/action-gh-release` pin 3.0.1 -> 3.0.2. Substrate pin moves to
  `v2.11.1`.

## [0.44.12] - 2026-08-06

### Changed

- **The shared comment gate is now `scripts/lint-comments.{php,mjs}`** (was
  `lint-comment-length`), because the PHP half no longer checks only length: at
  class-body level the only comment allowed is a docblock immediately preceding
  its declaration. Section headers, `//` notes where a docblock belongs, and
  docblocks whose method was deleted are all rejected. Comments inside a
  class-level initializer annotate their entry and stay exempt. Existing
  violations in this plugin are cleaned up here; no behavior changes.

### Fixed

- **A settings save now asks every live worker to re-read.** The settings page
  called `Restart_Planner::request_restarts()` alone, and a restart
  classification only says which workers must RECYCLE — every worker alive holds
  an option cache frozen at boot. A field classified `restart: []` (`log_urls`,
  `skip_urls`) therefore took a full ~595s worker lifetime to land instead of
  ≤15s. It now also calls the substrate's `Restart_Planner::request_reloads()`,
  which touches the reload watermark in every partition of every active
  topology. Requires newspack-nodes 2.11.0.
- **The other two config writers now signal too.** The settings page was the
  only one, and it only hooks `updated_option` under `is_admin()` — so neither
  of these reached a live worker. `Rule_Set::save()` signals a reload after
  persisting the ruleset: it is the single origin every ruleset write passes
  through (`Rules_CI_Node`, `Auto_Tuner_Node`, and the synced `apply_synced()`
  receive path), so no caller can forget it. The `performance` CI's `set` verb
  — the hub→spoke settings-sync receive path, which runs over REST and never
  under `is_admin()` — signals after its `update_option`; its ruleset branch
  inherits the signal from `Rule_Set::save()`. Both are best-effort: an
  unresolvable locks directory leaves the option write and the verb's response
  untouched.

### Removed

- **The `newspack_nodes/vault/changed` listener moved to the substrate.**
  `newspack_event_logger_nodes_on_vault_changed` restarted the `hub-control`
  fleet so its workers would reload remotes from the Vault. Every part of that
  belongs to newspack-nodes — the `Vault`, the action, and the `Remote_Link` /
  `Remote_Source` nodes that actually read the credentials — and naming an ELN
  topology in the listener meant every future consumer would grow its own copy.
  The substrate now signals a config RELOAD to the active topologies whose graph
  declares a vault-consuming node — derived rather than named, and a re-read
  rather than a process recycle — so this plugin holds no vault-driven restart
  logic at all.

## [0.44.11] - 2026-08-05

### Changed

- **Re-pinned to newspack-nodes v2.10.0.** No change of its own — the substrate
  turned every command-interpreter refusal into a TM_ERROR reply, and the shared
  runtime is inlined into this plugin's bundles at build time. Left on the old
  pin, these admin pages would keep shipping the browser interpreter that
  answers a refusal with a plain string while the site's PHP raises it.

## [0.44.10] - 2026-08-04

### Fixed

- **A control is recognised by WHO SENT IT, never by what its payload looks
  like.** Every view node here classified control-versus-record by sniffing the
  VALUE for an `action` field, so a record shaped that way ran the view's verbs
  instead of rendering — in `GyroscopeViewNode` an inbound `{action:'clear'}`
  emptied the whole in-flight request map. Each node that takes local controls
  now declares `controlFrom`, the FROM its dashboard mints them under, and
  applies a control only on that origin; `action` picks the verb once inside,
  where the message is already known to be a control. Covers
  `GyroscopeViewNode`, `DecodedSliceViewNode` (Overview, Urls, Url Detail,
  Request Detail) and `UrlDetailMergeNode`, plus the Request Log and Error Log
  views through the substrate's `LogStreamViewNode`. The graph hooks and
  `useGlobBrowse` stamp the FROM their views expect, and `sendControl` throws
  rather than mint a control with no origin — an origin that matches nothing
  falls through to the reply branch and blanks the slice in silence.

- **A url_detail reply carrying no payload blanked the widget.**
  `DecodedSliceViewNode`'s docblock promises that transport garbage keeps the
  slice already on screen, but the guard only caught a non-object VALUE: an
  object with no `payload` key reached `storeResult( undefined )` and emptied
  the panel. The test that should have caught it asserted `not.toBeNull()`,
  which `undefined` satisfies.

- **The hook picker's category descriptions were a stale JS copy of a
  server-owned taxonomy.** `CATEGORY_META` hand-listed 24 categories while
  `hook_categories.json` declares 63 — and a user can add more — so
  `CATEGORY_META[ category ] || {}` silently rendered nothing for the other 39.
  The descriptions moved into `hook_categories.json` beside the colors they
  describe, merge with user config the same way, and ride the
  `hooks_registered` payload as `category_descriptions`.

- **The hook-picker modal declared its width twice.** An inline
  `style={{ width: '800px' }}` beat `hook-selector.scss`'s `1100px`, so the
  stylesheet's copy — which also sets the height, max-height and flex layout —
  was dead for the one property it shared. Geometry belongs to the stylesheet.

- **The ruleset's sole writer swallowed its own write failures.** `useRulesGraph`
  documents that a mutation REJECTS rather than filling the `error` banner, which
  carries `list` failures only — and `RulesAdmin` owned no catch. Delete was the
  worse of the two: it closed the confirm dialog BEFORE awaiting `remove()`, so a
  rejection left the dialog closed, the row present, the banner clean, and an
  unhandled promise rejection as the only trace. Both handlers now report the
  failure and keep their context open.

- **`RuleEditModal` took a required layout class through an optional prop.**
  `rule-edit-modal.scss` gates a whole layout block on `.newspack-nodes-skin-root`
  — flex column, 600px, scrolling content — but the modal hardcoded only the
  other three skin classes and left that one to `className`. The Performance
  dashboard passed it and the rules admin did not, so the same modal rendered two
  different layouts. The modal owns all four now, and `className` is extras only.

- **Firehose Topic args follow the substrate's new retention order.**
  `max_segments` moved ahead of `min_lifetime` in Partition/Log/Topic's
  positional constructor, so `Log_Manager` and the `gyroscope`/`completed`
  partitions in `request-builder.tsl` were updated in lockstep.

- **The dashboards' retention window is owned here now, not in the substrate.**
  `newspack-nodes`' shared `useTimeChart` used to read
  `window.eventLoggerDashboards.retentionSeconds` itself. `src/overview/retention.js`
  reads this plugin's own global and passes it to `buildTimeSlots()`.

- **`UrlTable` and the `urls` verb both filtered, sorted and paginated the same
  rows.** The server already applied `search`, `sort`/`order` and the page slice,
  and the client re-applied all three to the returned page. Three costs: the URL
  comparators disagreed (PHP `<=>` byte order server-side, `localeCompare`
  client-side), so a page cut under one ordering was re-ordered under another and
  rows could skip or repeat across pages; between a keystroke and the reply the
  STALE page was filtered by the NEW term, transiently showing "No URLs match"
  for data that does; and `errorsOnly` existed only on the client, so the footer
  read the server's unfiltered total — "1-100 of 5,000" above three rows. The
  server is now the sole authority: `urls` takes `errors_only`, and the table
  renders the page it is given.

- **The "outermost pair never folds" rule had five non-equivalent spellings.**
  `logEntryUtils` and `LogEntriesTable` each re-derived it, and they disagreed on
  real input: `startsWith( 'process ' )` also matched `process queue (start)`, so
  "Unfold All" and search-ancestor expansion skipped a row that the table still
  drew with a disclosure triangle and a pointer cursor and still toggled on
  click. The rule now has one owner, `isFoldablePairStart()`, and all five sites
  read through it.

- **`GET_CACHE` always answered "nothing is stalled".** Its `oldest_rid` and
  `oldest_age_s` were computed inside an `is_array( $request )` branch, but the
  in-flight cache only ever holds `stdClass` — `fill()` stores one and
  `restore_state()` casts every persisted entry back to one. So the branch never
  ran: `oldest_rid` was permanently null and `oldest_age_s` permanently 0, the
  exact reading an operator takes as "nothing is stuck", which is the one
  question the verb exists to answer. The dead branch was stale twice over,
  reading `process.ts_start`/`ts` keys this node never writes. It now reads
  `$request->timestamp`, matching `Request_Flight_Node::inflight_snapshot()`.

- **A per-request flame graph could silently delete the frames a viewer opened
  it to see.** `FlameGraph.js` prunes on a single value cutoff and drops a
  node's whole subtree with it, which is only safe because a child never exceeds
  its parent — a guarantee its docblock attributes to `Flame_Tree`. Only the
  AGGREGATE path enforced it. `build_flame_data()` stamps each span's value from
  its own `(complete)` entry, so a span whose complete never arrived — request
  died, log truncated, entry dropped — kept a 0 while its finished children kept
  real durations. Past 1000 nodes that 0 fell below the cutoff and took the
  populated subtree with it, rendering a normal-looking but shorter graph. The
  invariant now has one owner, `cover_children()`, applied on both paths.

- **`GET_STATS` reported a constant 10 for `pending_url_count`.** It counted
  `$pending` itself — ten fixed accumulator keys — instead of the URL-keyed map
  one level down at `$pending['url_stats']`. The one introspection verb the node
  exposes told every operator debugging bucket growth the same number.

- **The intern-table cap was not applied at the two highest-cardinality sites.**
  `INTERN_TABLE_LIMIT` is documented as freezing the table, and the dimension and
  category sites honored it — but the two ENTRY-name sites, which see by far the
  most distinct strings, interned with no freeze check at all. So the one table
  the cap exists to bound was the one that grew without limit in a long-running
  worker. All four sites now go through a single `intern()` helper that owns the
  rule, and `GET_STATS` reports `intern_count`.

- **A category named `total` corrupted the rollup row it collided with.** The
  category time series reserves `total` for the per-bucket rollup — `n` requests,
  `t` their summed wall time, `c` every category's summed calls — and
  `mirror_traffic_rank()` sums `n` to decide which record to evict when the
  mirror buffer overflows. Category names come from the ruleset, where a custom
  event may be named anything, so a colliding name inflated `n` by one per sample
  and skewed eviction. The key is now the reserved `TOTAL_KEY`, and a colliding
  category is stored as `total (event)`.

- **An unparseable refresh setting no longer polls at 1Hz.** `usePerformanceGraph`
  derived its cadence with `parseInt( refreshInterval, 10 ) || 0`, and 0 meant
  "every router tick" to `useBatchedPoll` — so a bad setting silently produced
  the most expensive poll available while looking configured. It now falls back
  to the declared 15s default, which the parameter default also references
  instead of repeating the literal.

- **Job retry and batch fan-in work again.** `Job_Router_Node` normalized an
  entry by REBUILDING a fixed record — `k`, `handler`, `parameters`, `ts`, and
  `id` — instead of overlaying onto the body, so it silently dropped every
  field it had not heard of. Four of those are load-bearing:
  `Job_Worker_Node::schedule_retry()` reads `retries`/`attempt` to decide a
  retry, `settle_batch()` reads `batch`, and `key` re-hashes the partition on
  requeue. `Job_Intake::write_job()` writes all four, and this plugin owns the
  jobintake → jobs.log leg wherever it is active — so a job that opted into
  retries re-entered with `retries` absent, read as 0, and went to the poison
  path on its next throw. Every configured retry budget was capped at one
  attempt, and a batch never settled. The router now carries
  `Job_Intake::DISPATCH_FIELDS`, the substrate's canonical list, rather than a
  fifth hand-maintained copy of it.

  Requires newspack-nodes with `Job_Intake::DISPATCH_FIELDS` (unreleased at
  time of writing) — bump the `release.yml` substrate pin before releasing.

### Changed

- **`AGGREGATE_EXPIRY_SEC` had two private copies held equal by a comment.**
  `Flame_Tree` expires merged flame children on it and `Flame_Builder_Node`
  expired that same aggregate's profile categories on its own copy, so changing
  one gave a dashboard whose halves aged out on different clocks with nothing
  failing loudly. `Flame_Tree` now owns it publicly and the builder reads it,
  matching the norm AGENTS.md states for `Job_Intake::MAX_JOB_SIZE`.

- **`accumulate_all_stats()` was 470 lines of hand-numbered sections.** Its
  banners (`--- 1.`, `--- 2b.`, `--- 3b.` …) were load-bearing navigation rather
  than structure, and the same accumulate shapes were written out twelve times —
  the `c/s/m` triple three times, `t/c/n` six times, the leaderboard-category
  block twice, the entries-then-trim loop twice. Each copy had to re-decide the
  `$count_global` / `$record_timing` gate pair correctly, which the method's own
  docblock calls "the classic bug here". The five repeated shapes now have one
  owner each (`add_dim`, `add_cat`, `add_category`, `add_entry`, `trim_entries`,
  all pure), and every banner became a method: `accumulate_url_aggregate`,
  `rotate_pending_bucket`, `accumulate_url_stats`, `accumulate_hourly`,
  `accumulate_dimensions`, `accumulate_profiles`. `status_category()` also
  replaces the two independent derivations of the `Nxx` bucket — one of which
  reached the other by mutating `$request` across sections.

- **`set_is_hub` uses the substrate's bool parse.** Its handler re-spelled the
  rule locally as `'true' === $arg || '1' === $arg`, so it accepted `true`/`1`
  and rejected `yes`/`on` for an argument its own schema declares
  `'type' => 'bool'`. One of four implementations of that parse; it now calls
  `Schema_Reflection::truthy()`, which this class already had the trait for.

- **`Log_Manager` reads the partition count through the substrate accessor.**
  It clamped `num_partitions` with `max( 1, … )` and `> 0 ? … : 1` — two more
  spellings, neither with an upper bound — so an option above `MAX_PARTITIONS`
  had the firehose writing `firehose.p16`+ that no worker consumes and
  `Log_Cleaner` then swept as orphans. Both sites now call
  `Bootstrap::global_num_partitions()`. Requires a substrate that defines it;
  bump the `release.yml` pin before releasing.

- **The overview dashboard stops re-implementing the poll tick.** Three sites
  rebuilt the router's own `_http` lock/flush bracket by hand — each re-finding
  `_http` through a locally redeclared `const HTTP`. Two of them bracketed a
  SINGLE command, which coalesces nothing; the third hand-sent copies of the
  very verbs the tick already fans to, so it is now `pollNow()` from the
  substrate. The `flushed()` helper is gone too: `_http` is locked only inside
  a synchronous bracket, so an awaited handler can never observe it locked.
  `armTimer` is gone with them — TimerNode hitchhikes at `>= 1000` and the
  cadence floor is enforced upstream, so arming is a plain `setTimer()`.

- **`useGlobBrowse` takes its replay boundary from the substrate's
  `browseControl()`** instead of re-deriving `end?.segment ?? null` /
  `end?.offset ?? 0` from the half-boundary `endPosition()` returned. One of
  three copies of that mapping; the substrate now owns the whole control. An
  empty source consequently FOLLOWS rather than entering a replay that can
  never catch up. Requires newspack-nodes with `browseControl` — bump the
  `release.yml` substrate pin before releasing.

## [0.44.9] - 2026-08-04

### Fixed

- **A foreign option is no longer judged against this plugin's defaults.**
  `skip_default_writes` is a `pre_update_option` filter, so it sees every
  option WordPress writes, and it derived the short key by stripping 28
  characters rather than matching the prefix. Another plugin's option whose
  tail past that point equalled one of ours — and whose value equalled our
  default — had its row deleted. It matches with `str_starts_with` now.

### Added

- **Four lint gates, matching the substrate.** `npm run lint:types` runs
  `tsc --noEmit` over `src/` through the JSDoc already in the source, and
  lint-staged blocks a commit on it alongside three eslint rules:
  `react-hooks/exhaustive-deps`, `jsdoc/require-jsdoc`, and
  `import/no-restricted-paths`. Baselines were measured before gating rather
  than assumed — two rules were already at zero, `require-jsdoc` was 23, and
  the type check was 105. All four now sit at zero.
- `typescript` and the two `@types` packages are declared devDependencies
  instead of resolving transitively, so the gate cannot drift silently.

### Changed

- Documentation refresh across the PHP and JS sources — docblocks brought back
  in line with what the code does. No behavior change.
- `.distignore` excludes `tsconfig.check.json` and `types/`, which were
  shipping inside the release zip.
- The newspaper-order method gate (`reorder-node-methods --check`) now runs in
  lint-staged for both PHP and JS, matching the substrate.

## [0.44.8] - 2026-08-03

### Fixed

- **A `?url=` deep link titled the modal with the hash instead of the URL** —
  but only when the hash was outside the loaded page, which is why it looked
  intermittent. The in-page path selects a full index entry and has the URL;
  the resolver path returned `url_detail`'s raw payload, which nests the URL
  under `stats`, so the caller's `data.url` was undefined and it fell back to
  the hash. `resolveUrlHash` now returns the documented `{ url }`, and returns
  `null` when the reply carries no URL so the intent is held for the next
  refresh rather than settled on a wrong title. An empty URL is an *answer*,
  not a miss — retrying it would re-issue `url_detail` every tick forever and
  never open the modal.
- **A `?request=` deep link titled the modal "Unknown URL"** whenever the
  request's URL was outside the loaded page — the same defect one path over.
  `request_search` answers `{rid, partition, url_hash}` and never a URL, so the
  fallback it was reaching for could not have fired. It now resolves the hash
  through `url_detail`. The sentinel is kept when that fails rather than
  falling back to the hash: `canLogUrl` compares against it, and a hash-titled
  modal would offer to write a logging rule keyed on a hash.

- **The dashboard search box had the same defect as both deep links.** Typing a
  rid whose URL sat outside the loaded page titled the modal "Unknown URL" too —
  it was the third copy of one block, and only two were fixed. All three now go
  through a single `urlObjForHash`, and the unknown-URL sentinel is applied in
  one place and compared in one place. `useUrlNavigation` no longer falls back
  to the hash: a hash title passed `canLogUrl` and offered to write a logging
  rule keyed on `<hash>?`.

- **A `?url=` deep link to a hash that had aged out of the index polled
  forever.** `url_detail` throws for an unindexed hash, which landed in the
  catch, returned null, and held the intent — so the effect re-issued the
  command every refresh tick and never opened the modal. The rule is now
  simply *a reply settles, no reply holds*: a payload settles even when it
  names no URL, a TM_ERROR settles because it is a final answer, and only a
  timeout or a torn-down graph keeps the intent alive. Requires Newspack Nodes
  with `fromServer` on `RequestNode` rejections.

### Changed

- The `?url=` hash resolver no longer requests the per-URL category series it
  discarded. Selecting the URL refetches with the full argument set a moment
  later, so every deep link was building that payload twice.

## [0.44.7] - 2026-08-03

### Fixed

- **`?url=` and `?request=` deep links never opened their modal.** Both fired
  exactly once, discarded the intent before the resolve settled, and failed
  silently. `?url=` resolved its hash by searching `urls` — one page of the
  catalog — so a deep link to a low-traffic URL, which is what deep links are
  FOR, found nothing; `?request=` fired before the command graph was
  necessarily connected, and one miss was permanent. The intent is now held
  until a resolve settles, so the existing refresh interval retries, and a hash
  outside the loaded page is resolved through `url_detail` rather than a list
  lookup.

## [0.44.6] - 2026-08-03

### Fixed

- **The performance dashboard read one partition of four.** Every disk-walking
  verb — `request_search`, `request_detail`, `request_grep`, the recent-requests
  URL walk — and the memcache stats fan-out looped to the global
  `num_partitions`, which is 1 on the hub while the topology runs four workers.
  Roughly three of every four rid links 404'd, and the overview showed a quarter
  of the fleet's stats. They now resolve their partitions from the topology's
  own declaration via `Bootstrap::node_dirs()` / `node_partitions()`, so nothing
  in this plugin builds a `.p{N}` path or assumes where the partition token
  sits. The firehose span is the UNION of the declaration and the global count,
  never one replacing the other — `init_firehose()` still hashes over the global
  on every request, so a topology pinning a LOWER count must widen the reader,
  not narrow it.
- **`wp nodes reqgrep --firehose` now validates before it resolves.** The
  logs-dir containment check runs first, and the canonicalized path is what the
  command opens.
- **`request_detail` reported "invalid partition" for an unfindable rid** when no
  active topology declares `requests:partition` — there is no partition set to
  be outside of, so the accurate answer is "not found".
- **`wp nodes reqgrep` followed one partition of four**, the same bug on the CLI
  surface. Partition dirs now come from `Log_Manager::firehose_dirs()`, the one
  owner of the firehose layout; `--firehose` overrides route through it too.

### Changed

- **Requires newspack-nodes 2.5.0.** The partition resolution above calls
  `Bootstrap::node_dirs()` / `node_partitions()` unguarded, so an older
  substrate would fatal rather than degrade; the plugin stays dormant with an
  admin notice instead.
- **`request_search` tries the rid's own partition first.** A rid rides one hash
  the whole way — the firehose Topic routes it by KEY, the worker on that
  partition consumes it, Request_Builder writes it to the request partition of
  the same index — so the hash names the partition outright and the remaining
  fan-out is only a fallback for a reader whose count lags the writer's across a
  re-partition.

## [0.44.5] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.6.** Dashboards stop polling while
  their tab is hidden — the router tick every poller hitchhikes is now gated on
  page visibility — and the SSE slot poke drops from every 5s to every 15s
  against the server's 60s lease TTL.

## [0.44.4] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.5.** A node whose schema declares
  `hidden` is now hidden on the topology console canvas, not just in the palette.

## [0.44.3] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.4.** Brings the Topic partition-dir
  declaration fix: a Topic that re-partitions beyond its topology's worker count
  now declares every directory it writes, so `Log_Cleaner` stops treating them as
  orphans and worker status shows them all.

## [0.44.2] - 2026-08-02

### Changed

- **Substrate pinned to newspack-nodes v2.4.3.** Brings the per-site SSE slot
  namespace — the pool keyed on `gethostname()`, which on Atomic is the shared
  pool host, so co-located sites collapsed onto a single 10-slot budget and the
  surplus was refused with a permanent HTTP 429. Also brings the staggered
  Remote_Link connects, the phased session request, and the topology console's
  auto-layout and stored-coordinate fixes.

## [0.44.1] - 2026-08-02

### Fixed

- **Rebuilt against substrate v2.4.2**, which keeps a `Request` node alive while
  any hook still holds it. `useRequestNode` is inlined into these bundles, so
  0.44.0 shipped the copy that let the console's topology seed and the
  canonical-node read remove each other's `topologies:get` — a red
  `is not mounted` / `was removed` toast on entering edit mode. No source change
  here; the pin is the fix.

## [0.44.0] - 2026-08-02

### Changed

- **The two one-shot `CommandClient.send()` calls became `Request` nodes.**
  `CurrentRequestTab`'s `request_detail` and `usePerformanceGraph`'s rid-search
  fallback each mint from their own node now, and their replies route back
  `TO = FROM` — no correlation, and the command rides the graph's own egress
  instead of a POST outside it. The substrate deleted `send()`; these were its
  last callers here. `CurrentRequestTab`'s `commandClient` prop is gone: tests
  answer the wire (`installFakeCommandWire`) rather than replacing the transport.

  The rid-search fallback no longer answers after the hook unmounts — its node
  goes with the graph. It still covers the case it was written for, a missing
  request-detail view on a live graph.

- **Every op-id in the dashboards is gone.** `useGlobBrowse`, `useHookCatalogGraph`,
  `useRulesGraph` and `usePerformanceGraph` stamped a correlator into
  `message[ID]` and had a view node settle the match. Each awaited verb is now
  its own `Request` node, and its reply is addressed back to it — a listing, a
  status and a single-record read can be in flight together with nothing to tell
  apart. `hookcatalog:view` went with it: the awaited catalog IS the model, so
  the hook holds it rather than publishing into a node it then reads back.

- **`CommandClient` is gone from the substrate**, folded into `HttpOut` as a
  `commandTransport` closure. The `_http.client` seam is unchanged and
  duck-typed, so the fakes here still work; what changed is that production no
  longer constructs one — HttpOut defaults it on the first POST, so these hooks
  only assign a client when a test injects one.

### Removed

- **`CurrentRequestTab`'s mounted-ref guard.** Unmounting removes the Request
  node, and a removed node REJECTS its outstanding request rather than resolving
  it, so the `if ( ! mountedRef.current )` after the await had become
  unreachable — deleting it left every test in the file green, which is the
  definition of dead code.

## [0.43.13] - 2026-08-02

### Fixed

- **`useGlobBrowse`'s 10s-cadence test no longer leaves a real router interval
  running.** It re-mounted the backbone under fake timers precisely to avoid
  that, but `Core.reset()` only swaps the node map — the router armed in
  `beforeEach` kept its real 1s interval and ticked on beside the frozen clock
  for the rest of the test. Stopping it first is the one line that was missing.
  Caught by the substrate's new arm-then-fake guard.

### Added

- **`npm run lint:deadcode:js` — the JS half of the dead-code audit.** Mirrors
  the substrate's `knip.json`: `__tests__` out of `project`, knip's jest plugin
  off so a module reachable only from its own test reads as dead (the rule
  `phpstan-deadcode` already applies to `tests/`), and the `@newspack-nodes/*`
  aliases resolved through the sibling checkout. Opt-in, not in the push gate.

  knip cannot parse JSX in a `.js` file, which silently drops that file's
  `import()` expressions — so `ErrorLog`, `PerformanceDashboard`, and
  `FlameGraph` are listed as `entry`. Without that, each read as an unused file
  and took its whole import subtree with it (36 false positives in pyrobase,
  15 here).

### Changed

- **`makeOpId` comes from `@newspack-nodes/shared/utils/makeOpId`.** All four
  hooks here (`useGlobBrowse`, `usePerformanceGraph`, `useRulesGraph`,
  `useHookCatalogGraph`) carried a private `let nextOpId = 0` plus a copy of the
  function, identical to the shared one bar the prefix string — which is exactly
  what the shared version takes as an argument. With the substrate's three, that
  takes the util from 2-of-9 adoption to 9-of-9.

### Removed

- **`@xyflow/react`.** Declared but imported nowhere in this plugin or the
  substrate, and absent from every built bundle. Found by knip.

### Changed

- **The build takes ONE substrate override, `NEWSPACK_NODES_SRC`.** It replaces
  four independent ones — `NEWSPACK_NODES_RUNTIME`, `_DEBUG_OVERLAY`, `_SHARED`,
  `_BUILD_KIT` — which all named paths inside the same directory. Every alias and
  the build-kit path now derive from that one base via the substrate's
  `build-kit/alias-map.js`, so a new alias needs no workflow change.

  That enumeration is what made `ERR_MODULE_NOT_FOUND` releases possible: omit
  any single variable and the build fell back to a nonexistent sibling path.
  Setting a retired name now fails immediately and names it, rather than being
  silently ignored — a stale override that does nothing is how a release builds
  against the wrong checkout and still goes green. `release.yml` updated to match.

  Build output is byte-identical, verified by rebuilding with only `build.mjs`
  varying.

- **Pollers ride the Router heartbeat.** The URL-detail breakdown reload and the
  segment-rail catalog each owned a private `setInterval`; they now use the
  substrate's `useRouterTick`, so a dashboard page runs the Router's one 1s slot
  instead of several competing heartbeats. They also pause while the tab is
  hidden, matching the SSE gating.

  The gyroscope in-flight display rides it too, including its 100ms and 500ms
  options: `TimerNode.setTimer` gives a sub-second interval its own slot rather
  than rounding it to the Router tick, so the refresh dropdown keeps meaning what
  it says while the timer becomes a graph node like the rest.

- **The segment rail no longer accepts a reply for a partition you already
  left.** Its in-flight guard was a single shared flag, which React un-sets on
  the very effect re-run that should cancel it, so a slow `log_status` could
  repopulate the rail after the selection changed. Replies are now matched to the
  partition that asked, and the rotation refetch — which had no guard at all —
  goes through the same check.

### Fixed

- **The hook catalog and Current Request tab converge instead of loading once.** A
  refused hook catalog routed a synthetic empty reply and stopped, so the picker
  showed "no hooks" — indistinguishable from a site that genuinely has none — until
  the modal was reopened. The Current Request tab collapsed a refused command and a
  not-yet-written request into the same permanent "still processing", so an expired
  session looked exactly like a slow write. Both reconcile now.

## [0.43.12] - 2026-07-31

### Security

- **Dev-dependency advisories cleared where a fix exists.** `js-yaml` to 4.3.1
  and `brace-expansion` to 1.1.18 / 2.1.4, closing GHSA-52cp-r559-cp3m and
  GHSA-3jxr-9vmj-r5cp. All are development scope — `build-release.sh` excludes
  `node_modules` and PHP installs `--no-dev`, so none of this ships.

  **GHSA-mh99-v99m-4gvg (brace-expansion) is deliberately left open.** It covers
  every version `<= 5.0.7`, so the fix is 5.0.8 — and 5.0.8 changed the CommonJS
  export from a bare function to `{ EXPANSION_MAX, EXPANSION_MAX_LENGTH, expand }`,
  which every `minimatch` below 10 calls as a function. An override to 5.0.8 or
  5.0.9 breaks eslint with `TypeError: expand is not a function`. `npm audit fix
  --force` clears it only by taking eslint 10 AND downgrading jest 30 to 25 and
  babel-jest 30 to 23. The residual risk is a DoS reachable only by feeding a
  hostile glob to our own build tooling, which a hostile PR could not do without
  already being able to run code in CI.

### Fixed

- **A synced ruleset is rekeyed by pattern, like every other write path.**
  `Rule_Set::apply_synced()` — the hub→spoke receiver, and the only rule-write
  path that crosses a trust boundary — took the id off the wire verbatim while
  the config seed and the editor's save/upsert both re-derived it. Two entries
  could then persist for one pattern and race in the matcher, or share an id and
  alias one durable hooks option, where the inline entry's `delete_option` wiped
  the pointer entry's list: 107 hooks gone behind one rate-limited notice.

  The three copies of "rekey by pattern, collapse duplicates" are now one
  `Rule_Set::rekey_by_pattern()` that all three callers use, so the invariant
  can't hold in two places and lapse in the third again. A heavy synced rule's
  durable option is keyed by the derived id, so a spoke that received one under
  a wire-supplied id re-tiers it on the next sync.

### Changed

- **Documentation accuracy pass.** `docs/API.md` claimed there is no rate-limit
  gate on `/command` (there is), named `dump_metadata` as a `workers` verb (it is
  `dump_graph`), and asserted a longer SSE slot TTL for hub connections than for
  browsers (`SSE_Slot_Pool` is a flat 60s for every caller). `docs/architecture-guide.md`
  documented `alerts`/`errors`/`gyroscope`/`completed` as per-partition when all
  four are `.p0`, put `void_warranty`'s cap at 10 MB rather than 32 MiB, and
  described a third config storage tier that does not exist. `README.md` read
  0.35.2 against a shipped 0.43.11.
- **`LRU_Cache` records its lineage.** It is a variant of Tachikoma's bucket LRU
  (`Nodes/Table.pm` `lru_lookup`) in the shape our DN `ReqGrep.pm` uses it. This
  repo is public and the DN tree is not, so the citation says which half a reader
  can follow. The reqgrep comments also spelled the class `LruCache`.
- **Dropped the four `InstrumentalityGrail` / `InstrumentalityFlight.pm` citations**
  from `Request_Flight_Node`, its two tests, and the architecture guide. Every
  behavioral description stays; only the unfollowable pointer goes. The substrate's
  `docs/tachikoma-lineage.md` names both modules and what they resemble.

### Removed

- **Dead `Core` and `Partition_Node` imports** in `Remote_Job_Rewrite_Node`, left
  behind when the oversize guard was removed in `a4741aa`.

## [0.43.11] - 2026-07-31

### Changed

- The architecture guide now says WHY the Partition lines carry no retention
  tail: `node_schema()` defaults each retention arg to its `<config:…>` token,
  so an omitted tail is not an unset one.

## [0.43.10] - 2026-07-31

### Fixed

- **Three `make_node` examples froze at a superseded retention spelling.**
  `flames:partition`, `flame-stats:partition`, and `firehose:topic` in the
  architecture guide passed `<config:segment_size> <config:num_segments>
  <config:max_lifespan>`. That tail was correct when written, then was
  superseded twice: `max_lifespan` was renamed, and `node_schema()` learned to
  default each retention arg to its `<config:…>` token, so the topologies
  dropped the tail entirely. The examples now match the shipped TSL.

### Changed

- **`lint-docs.sh` is a shared pre-push gate.** The doc-drift lint was
  substrate-only; it now ships to every plugin via `sync-shared-scripts.sh` and
  runs from each `pre-push`. It caught three `make_node` examples in
  event-logger-nodes documenting a retention arg list the shipped topology never
  passes.

## [0.43.9] - 2026-07-31

### Fixed

- **Selecting a server blanked every chart on the Performance dashboard.**
  `is_hub` tested the ACTIVE topology names for a literal `aggregator`, so a hub
  running the stock topology through a wrapper (`aggregator-eve`, which is just
  `include aggregator`) read as a spoke. Flame Builder then wrote no
  `dim_by_server` / `cat_by_server` / `leaderboard_by_server` bucket at all, and
  every server-scoped read came back empty while the global ones stayed full.
  A hub is now a site that RUNS `aggregator`, directly or through a wrapper.
  To backfill, delete the derived partitions and their offsetlogs and let the
  Consumer re-read the firehose from the start — the raw records were never
  lost, only the derived stats. `jobs:sieve` (Age_Sieve, 900s) is what keeps
  that replay from re-firing historical jobs.

### Changed

- **The vendored `reorder-node-methods` tooling now passes the comment-length
  gate.** Function-level prose moved into docblocks, inline prose condensed to
  one line; four algorithm notes that genuinely need the length carry
  `@longform`. No behavior change — the tool's own test still passes 38/38.

## [0.43.8] - 2026-07-31

### Added

- **Vendored copies of the substrate's shared tooling** (`scripts/bump-version.sh`
  + `scripts/lib/`, `reorder-node-methods`, the coverage and comment-length
  gates, `pre-commit`, `commit-msg`), so a standalone clone works without a
  sibling checkout. `scripts/sync-shared-scripts.sh` refreshes them from
  `../newspack-nodes/scripts/` on each `pre-commit` when that sibling exists —
  edit shared scripts THERE, not here.
- **`scripts/commit-msg`** — the conventional-commit gate, now a tracked hook.
  It skips cleanly where commitlint isn't installed.

### Changed

- **Git hooks come from `core.hooksPath`, not `.git/hooks`.** `composer install`
  now points git at `scripts/`, so the hooks are version controlled and reviewed
  with the code they gate. A clone that has never run `composer install` has no
  hooks at all.
- `scripts/bump-version.sh` replaces dndocker's `tools/bump-event-logger-nodes-version.sh`.
  Behavior is unchanged; the shared flow lives in `scripts/lib/bump-version.sh`
  and the wrapper is only the per-plugin knobs.

### Removed

- `brainmaestro/composer-git-hooks` — `core.hooksPath` does the job with no
  dependency, and cghooks-installed `.git/hooks` files are now dead files git
  ignores.

## [0.43.7] - 2026-07-31

### Fixed

- **A dev build and a CI build now emit the same bytes.** Shared substrate
  source importing a bare dependency (`d3`, `@noble/hashes`) resolved it from
  the substrate's own tree first. CI checks the substrate out without
  `node_modules`, so resolution fell through to this plugin's copy; a developer
  checkout HAS `node_modules`, so esbuild bundled a second copy under a
  different absolute path — 88KB of duplicate d3 in the overview bundle. Every
  dependency this plugin owns is now pinned to its own copy. Verified by
  building against a substrate checkout carrying `node_modules` and diffing the
  result against the published release: byte-identical.

## [0.43.6] - 2026-07-31

### Fixed

- **The request-detail back arrow drops its button chrome.** It sat in the
  modal header beside the bare close glyph, but carried `button button-small`,
  so a bordered, filled box appeared next to an unadorned `×`. It now carries
  the canonical `is-plain` role — no fill, no border, surrounding ink, and none
  of `button-link`'s link color or hover underline; the component stylesheet
  keeps only its geometry.

## [0.43.5] - 2026-07-30

### Fixed

- **Bundles newspack-nodes v2.2.10.** The release workflow still pinned the
  v2.2.9 substrate, so v0.43.4 shipped its dashboards built against the
  pre-normalization shared stylesheet — the canonical UI layer that release
  announced was absent from the published bundles.

## [0.43.4] - 2026-07-30

### Changed

- **Event Logger dashboards adopt the canonical newspack-nodes UI layer.**
  Controls, modals, focus rings, status colors, and theme skins now share the
  substrate implementation, including audited contrast for every action state
  and semantic status surface.

### Removed

- **Removed the in-flight dashboard's seconds-since-last-SSE indicator.**
  Request rate, reconnect handling, and the stream watchdog are unchanged.
- **Removed completed one-time migrations.** The autoload-correction sweep,
  pre-v0.28 ruleset upgrader, activation hooks, public migration constants,
  and obsolete tests are gone.

## [0.43.3] - 2026-07-29

### Changed
- **Bundles newspack-nodes v2.2.9.** Dashboard first polls now survive
  navigation-time authentication and visibility races, so request details load
  without requiring a page reload.

## [0.43.2] - 2026-07-28

### Changed
- **Adopts newspack-nodes v2.2.5.** Release bundles now carry the canonical
  seven-check Site Health/doctor report, APCu-capable command sessions, and
  base-dir-independent diagnostics while preserving full attached-console
  access to normal workers.

## [0.43.1] - 2026-07-28

### Changed
- **Adopts newspack-nodes v2.2.4.** Release bundles now carry ownership-fenced
  SSE slot leases and the matching heartbeat/disconnect diagnostics, so a stale
  connection cannot release its successor's slot and a future disconnect names
  whether the endpoint reported lease loss or the stream ended without a reason.

## [0.43.0] - 2026-07-27

### Changed
- **Adopts newspack-nodes v2.2.3** (was v2.1.0). The bundled console/runtime
  therefore changes behavior: the REPL's `echo` is now `print` and appends no
  newline, `var` follows Shell3 semantics (bare `var` lists, `var <name>` reads
  and autovivifies, `var <name> =` deletes, the full operator set applies),
  double-quoted strings expand `\n` and friends while single quotes and
  backticks stay literal, an unquoted `#` comments to end-of-line anywhere, and
  the message composer can set FROM / TIMESTAMP / ID / KEY. It also carries two
  aggregation fixes that matter here: a reconnecting `Remote_Source` no longer
  resumes inside a record (which was dead-lettering one firehose record per
  reconnect), and the SSE slot survives a re-auth round trip.
- **`jobs:sieve` age guard raised from 60s to 900s.** A reconnect costs ~45s of
  stream downtime and the checkpoint interval adds up to 30 more, which
  straddled the old 60s guard and dropped queued jobs as a side effect of a
  reconnect rather than of genuine staleness.
- **Stats `min_lifetime` raised to 43200.**

## [0.42.4] - 2026-07-26

### Fixed
- **`Discovery_Collector` asks for the handshake it is waiting on.** It skipped
  every spoke without a session, and a probe is its only traffic to one — so
  nothing ever triggered `/auth` and the pair sat there. It now resolves the
  egress by the target's HEAD (a target may be a path, `spoke/discovery`, which
  the old full-path lookup missed entirely) and calls `ensure_session()` on the
  skip path. Same deadlock, same fix as `Settings_Sync` in newspack-nodes 2.1.1.

## [0.42.3] - 2026-07-26

### Security
- **Every stock topology now declares `secure` (level 1).** A worker whose
  graph never declares a secure level ran armed-but-undeclared and logged
  `no secure level declared` on every boot. Level 1 disables the verb class
  that mints commands on the caller's behalf (`make_node`, `slurp`, `config`,
  `env`, `var`, `func`, `reply_to`) once the graph is built. Requires
  newspack-nodes 2.1.0, which introduces the `secure` / `insecure` verbs — the
  release pin moves with it.

### Fixed
- **Tests seed their scratch config through one helper.** Three integration
  tests each carried their own `<config:*>` resolver map, and all three omitted
  `deadletter_dir` — which resolved empty and built a Consumer against
  `/combined.firehose.p0`. `TestCase::use_scratch_config()` repoints only the
  directory tokens and defers the rest to the substrate resolver, so a key it
  cannot answer throws instead of resolving empty.
- Scratch paths in the reqgrep, flame-builder, and sibling-node tests now sit
  under the configured base directory rather than a hardcoded `/tmp` sibling,
  which the substrate's storage-containment check refuses.

## [0.42.2] - 2026-07-26

### Changed
- **Release builds against newspack-nodes 2.0.0**, and declares
  `@noble/hashes`. The workflow pinned an older substrate tag, so the archive
  compiled against a runtime predating the command-session exports; and the
  substrate runtime this plugin inlines imports `@noble/hashes` for its
  synchronous HMAC, which resolved locally only by walking up into the
  substrate's own `node_modules`. CI checks the substrate out without its
  dependencies, so it has to be declared here.

### Fixed
- **Pause → Replay → Step now steps** in the Request Log and Error Log.
  `useGlobBrowse`'s step carried its own copy of the substrate's cursor logic,
  including the object-only guard that silently dropped Replay's magic `start`
  token. It now uses the shared `stepPosition()` resolver. Requires the matching
  newspack-nodes change.
- **Dashboard commands mint through `Node.command()`.** The hand-built builders
  called `markLocal()`, which marks LOCAL but declines to sign with no session —
  passing the browser's gate and earning a server refusal. Minting through the
  receiver node gates on the session instead. Requires the matching
  newspack-nodes change.
- **Dashboard commands hold for the session instead of racing it.** A graph is
  built synchronously; the session lands a round trip later. `useRulesGraph`,
  `useGlobBrowse` and `useHookCatalogGraph` fired their first fetch immediately,
  minting UNSIGNED — which is why these pages looked half-alive, working only
  once a later user action happened to run after auth. Each now fires on
  `ensureSession()`, guarded against a teardown mid-round-trip.
- **`usePerformanceGraph` sends nothing while unauthenticated.** `sendCommand`
  gates on `readyToMint()`, which also asks for a session — so a poke that lands
  before auth is dropped rather than refused, and the next one works. Requires
  the matching newspack-nodes change.

### Changed
- **The dashboards' own command mints complete.** `usePerformanceGraph`,
  `useRulesGraph`, `useHookCatalogGraph` and `useGlobBrowse` each built commands
  without marking them, so every first poll shipped unsigned and was refused —
  self-healing on the next tick, but noisy and wasteful. Found by running the
  substrate's mint audit against THIS plugin's source, which the earlier passes
  never did.
- **Test harness authenticates and polyfills `TextEncoder`.** The substrate's
  emitters hold until a command session exists, and its signer needs
  `TextEncoder` — which jsdom lacks. Both were added to the substrate's harness
  when it gained the signer; this one needed the same. Requires the matching
  newspack-nodes change.
- **The E2E dispatch harness signs its commands.** `HTTP_In` no longer signs on
  ingress, so a request must arrive signed like a real client's. Requires the
  matching newspack-nodes change.
- **Dashboards inline the substrate's synchronous command signer.** Bundles
  rebuilt; the jest transform opts `@noble/hashes` out of the `node_modules`
  skip the same way d3 already was, since both ship ESM-only. Requires the
  matching newspack-nodes change.
- **`Discovery_Collector` fans out itself, one signed probe per spoke.** A
  signature under one spoke's key verifies only there, so a `Tee` re-addressing
  the probe after the mint would produce something no spoke can verify.
  `spokes:tee` is deleted from `hub-control.tsl`; connect `discovery-collector`
  and `settings-sync` directly to each `HTTP_Out`. A spoke with no live session
  is skipped rather than sent something that will be refused. Requires
  newspack-nodes with `Fanout_Targets` and `HTTP_Out_Node::vault_id()`.

## [0.42.1] - 2026-07-25

### Fixed
- **Rebuilt against newspack-nodes v1.6.0** (release workflow pin bump) to
  pick up the inlined shared fixes: the browse rail highlights the clicked
  segment immediately, the hub/console header subtitle yields width instead
  of wrapping, and the console Inspector's Connect toggle sizes to its label.

## [0.42.0] - 2026-07-25

### Added
- **Time travel in the Request Log and Error Log** (parity with the
  substrate viewers): selecting a past segment pauses the stream, the
  step button walks it one record at a time over the command channel
  (the substrate's `read_message` verb — the stream stays offline while
  paused), and the offset input jumps to a pasted message ID
  (`seg:off:len`) or bare offset and steps exactly that record. Play
  resumes streaming from wherever you stepped to; a live-delivered seek
  is single-use, so a later pause/play resumes the tail instead of
  re-running the old seek.

### Fixed
- **The Recent Requests header sits at the same height as every other
  table header**: a vestigial seventh header cell (a leftover 30px
  actions column) wrapped onto an implicit 8px-gapped grid row under the
  six-track template, inflating the header. The cell is gone.
- **The segment rail stays current** (parity with the substrate viewers):
  the selected partition's segment list refreshes on a 10s cadence and
  refetches immediately when a record arrives from a segment the rail
  doesn't know, once per unknown segment.
- **Debug rows render as columns** — the column-grid override no longer
  applies to debug rows, which use the shared flex cells; and both
  dashboards keep the debug KEY column, which carries the request id.

### Changed
- **Request Log and Error Log rebased onto the substrate's log-stream
  common base** (requires newspack-nodes v1.4.0+ for
  `LogStreamViewNode` / `LogStreamViewer`). The view nodes extend the
  shared `LogStreamViewNode` (ring, paused belt + step budget, decaying
  rate, seek tracking, reply settling, shared control verbs) and keep
  only their entry enrichment; the components are thin wrappers over the
  shared `LogStreamViewer` chrome (toolbar, filter, counts, staleness,
  pause, Debug rows, Clear, connection banner) with their column grids,
  column picker, and `SegmentBrowseSidebar` plugged into its extension
  points. Both dashboards inherit the whole viewer feature line for
  free: ring virtualization with smooth scrolling and flood hardening,
  Debug mode (ID · KEY · VALUE — KEY carries the request id), the
  decaying rate readout, clear-on-rewind, and the shared log-area
  styles. Net −2,000 lines: the bespoke rAF loops, `useVirtualization`,
  smooth-scroll machinery, and duplicated ring/RPS/seek code are gone.
  View-node-side URL filtering moved to the chrome's client-side match
  (changing the filter no longer discards buffered history), and paused
  rows no longer count toward the rate.

## [0.41.3] - 2026-07-25

### Fixed
- `ConfigTest` builds its fixture base through the shared realpathed temp-dir
  helper, so the suite passes on macOS hosts where `/tmp` symlinks to
  `/private/tmp` (the raw `/tmp/...` expectation diverged from `Config`'s
  realpathed directories).

## [0.41.2] - 2026-07-24

### Changed
- Adopted the substrate's shared `formatBytes`
  (`@newspack-nodes/shared/utils/formatBytes`) in `SegmentBrowseSidebar`,
  dropping the local byte-identical copy; the release workflow's substrate
  checkout pin moves to v1.1.0 (which ships that module).
- `TopologyShapeTest` matches the statement front-end's canonical
  `command_node` verb (substrate v1.1.0+ canonicalizes `cmd` to it).

## [0.41.1] - 2026-07-24

### Changed
- Dropped `ReflectionProperty`/`ReflectionMethod::setAccessible()` calls from
  the test suite — deprecated in PHP 8.5, a no-op since PHP 8.1.

## [0.41.0] - 2026-07-24

### Fixed
- Request-details search counts a start/complete pair as one finding: a
  `(complete)` row whose only hit is the keyword its `(start)` already matched
  is no longer a separate `n`/`p` stop, so a childless pair takes one press,
  not two. A complete still matches on its own when the query hits its
  message/payload, or hits only the complete keyword itself.

### Added
- Substrate version handshake at boot: on a substrate older than 0.54.0 the
  plugin goes dormant with an admin notice (via the substrate's
  `Bootstrap::version_at_least()`) instead of fataling on a missing API.
  A substrate predating the handshake API (nodes < 0.54.0) also parks the
  plugin — the stack deploys as a unit, so a missing API means too old.

### Changed
- The release workflow pins the newspack-nodes checkout to the `v0.54.0` tag
  instead of tracking `main` — bump the pin when adopting a newer substrate.

## [0.40.2] - 2026-07-24

### Removed
- **The emission gate.** v0.38.0's `category_allowed()` filtered every
  non-structural category through the governing rule's selections — an
  unrequested second filter. Rule selections already drive instrumentation
  at the producers (`App\Core` only wraps `Rule_Set::hooks_for()` hooks),
  so the gate's sole real effect was dropping producer categories the rules
  UI never lists: pyrobase's per-request stats summaries (`metadatacache`,
  `combinedcache`, `requestcache`, `memcached`) vanished from the firehose.
  Removed outright; any category an active producer emits is written.

## [0.40.1] - 2026-07-24

### Fixed
- **Matched requests always reach the firehose.** The Log_Manager called
  `matches_url_filter()` at construction and discarded the result; a request
  the ruleset classified as logged but which emitted no explicit events never
  started — no process line, no memory summary, no finish. A URL match now
  starts logging eagerly at construction.

## [0.40.0] - 2026-07-24

### Changed
- **Cached per-tick clock everywhere; `\Newspack_Nodes\Core::right_now()` is the
  one `\microtime(true)` site.** Ported the substrate's clock treatment into the
  application: every `\microtime(true)` in `includes/` now reads either the cached
  `Core::$now` (or the `Core::$now ?: Core::right_now()` warming idiom where
  request-scope entry is possible) inside drain-driven node paths, or routes
  through `Core::right_now()` for genuinely fresh needs and cold paths (which also
  warms the cache). `Log_Manager::message()` — the per-request firehose write, the
  hottest ELN surface — drops from two clock reads per line (one bare
  `\microtime`, one `Core::$now`) to one `Core::right_now()` threaded into both the
  entry `ts` and the message `TIMESTAMP`, which also makes those two timestamps
  agree per line instead of the message stamp freezing at request-load time.
  Behavior is otherwise unchanged; no payload sizes change.
- **`hub-control.tsl` is now a thin overlay over the substrate `settings-sync`
  topology.** It `include settings-sync`s the substrate's settings-sync + spokes
  plane (which itself now pushes the full six-axis `remote_*` geometry) and keeps
  only ELN's own pieces: the `Discovery_Collector_Node` and the three app-key
  pushes (`rules`, `log_memory`, `flush_every_line`). The substrate and ELN stop
  maintaining the same settings-sync graph in two files. The topology NAME stays
  `hub-control` (worker type, locks, `<topology>`-scoped cursors unchanged — no
  cursor reset on ELN installs). Tracks the substrate's `remote_*` rename
  (`remote_max_segments` → `remote_num_segments`, `remote_max_lifetime` →
  `remote_lifetime`) and its new remote hard-cap knob (`remote_max_segments`,
  `0` = spoke derives `2 × num_segments`), which the hub can now fan out to cap a
  spoke's disk unconditionally.
- Track the substrate's retention rename: the count target is now `num_segments`,
  the age rule is `lifetime`, and `max_segments` is a trailing hard-cap token
  (0 = derive 2× num_segments). `Log_Manager::init_firehose()` reads
  `num_segments` / `lifetime` / `max_segments` and passes the firehose Topic the
  new tail order (`… segment_size min_segments num_segments min_lifetime lifetime
  max_segments`). `request-builder.tsl` swaps `<config:max_segments>` →
  `<config:num_segments>`; the settings-sync geometry seeds (now in the substrate
  `settings-sync` topology ELN includes) carry `newspack_nodes_lifetime` in place
  of the retired `newspack_nodes_max_lifetime`. Test configs rename
  `max_segments`→`num_segments` and `max_lifetime`→`lifetime`.

## [0.39.0] - 2026-07-23

**Pairs with newspack-nodes ≥ 0.51.0** (the `add_snapshot_node` verb and the
substrate's `Age_Sieve`).

### Changed
- Staleness moved out of `Job_Router` into the substrate's new `Age_Sieve`:
  `job-router.tsl` wires `job-router → jobs:sieve (Age_Sieve 60 1) →
  jobs:partition`, and Job_Router's `stale_timeout` argument, guard, and
  the body-ts (`ts`) age check are deleted — the envelope TIMESTAMP is the
  age criterion now.
- `flame-builder.tsl` and `request-builder.tsl` migrate `set_snapshot_node`
  (deleted upstream) to `add_snapshot_node`; a Consumer can now co-commit
  multiple nodes' states, keyed by name, in one offsetlog frame.

## [0.38.0] - 2026-07-23

**Pairs with newspack-nodes ≥ 0.50.0** (raw-span TSL parsing, the stock
`topic-probe` include, and the re-keyed consumer cursors — see that
release's BREAKING note; this release's topologies use the new
`{topology}.{source}.pN` offsetlog layout).

### Added
- **Rule patterns match worker URLs.** A third pattern form, `path?query`
  (e.g. `/jobs/render?job-worker`), matches exact path + query prefix and
  outranks exact and prefix rules; the rule editor's helper text documents it.
- **Delete button in the performance dashboard's rule editor.** Editing an
  existing rule from "Log this URL" exposes a two-click Delete (arm, then
  confirm) that removes the rule in place — no round-trip to admin settings.
  The settings modal keeps save/cancel only (its rows already delete).

### Changed
- **0 hooks means 0 hooks: the emission gate.** `Log_Manager::message()` only
  writes a non-structural category when the governing rule selects it — as a
  custom event, significant event, or hook (`{hook} hook` matches with or
  without the suffix). Structural lifecycle categories (request, process,
  error, alert, job, …) always pass. Previously producer-side emissions
  bypassed the rule entirely. This includes the standalone profiler
  drop-in's `{plugin} plugin (start/complete)` rows and pyrobase's profile
  events — select them on a rule (custom events) to keep those waterfalls.
- **Admin settings list rules sorted by action, then pattern.**
- **Dashboards skip the partition dropdown when only one partition exists** —
  the sole partition auto-selects, a label replaces the select, and the
  segment list loads immediately.
- **`errors`/`gyroscope`/`completed` hardwired to `.p0`** in
  `request-builder.tsl` (single-partition journals); topologies adopt the
  substrate's stock `topic-probe` include instead of private probe wiring.
- **`configure_stats` fails loud on a non-numeric partition.** An unresolved
  `<partition>` token returns the usage error instead of silently configuring
  partition 0; the stale joined-args `preg_split` is gone.

## [0.37.0] - 2026-07-23

### Changed
- **The completed and gyroscope streams no longer duplicate the rid in VALUE
  — it rides `Message::KEY` alone.** `build_compact_summary()` and
  `inflight_snapshot()` (now a rid-keyed map) dropped the `rid` field; the
  gyroscope and request-log view nodes read the KEY and discriminate records
  on the server-built `state` field. Old on-disk segments (rid in both
  places) replay fine; a dashboard tab left open across the deploy should be
  refreshed so its bundle reads the new shape.

### Added

- **Request Log + Error Log gain Kafka-UI-style browsing on the substrate's shared browse core.** A new `useGlobBrowse` hook (one wiring, both dashboards) catalogs the glob's partition dirs via the substrate's `raw-logs` CI and composes the shared `@newspack-nodes/shared` `LogBrowser` + `useLogPositions`; a new shared `SegmentBrowseSidebar` component renders the partition picker + segment rail. Default view is unchanged (all partitions, live tail — pinned byte-exact); picking a partition narrows the SSE subscription to that dir and offers Live / Replay / per-segment seeks over the existing `positions` transport. No server changes; no shared component forked.
- **Request Log + Error Log gain the Partition Viewer's seek feedback (Replay→Live flip + last-received-segment highlight).** Both view nodes now compose the substrate's shared `SeekTracker`: while browsing a single partition dir the segment rail highlights the segment records actually arrive from, and the control flips from Replay back to Live the moment a replayed record reaches the seek-time head boundary (captured from `log_status`) — both derived from the stream's `segment:offset:length` breadcrumbs, not the click. `useGlobBrowse` now wires the captured end into the view on seek and surfaces the view-derived mode + last-received segment; `SegmentBrowseSidebar` forwards both to `LogBrowser` (its `activeKey` wins the highlight over the clicked segment). Tracking is armed only for a single-dir browse — the glob / All-partitions live tail mixes per-dir segment numbering — and resets on partition switch.
- **Performance dashboard: server-side pattern search across recent firehose traffic.** Non-rid input in the Overview search box now runs a new `request_grep` verb on the performance CI: it drains the recent window of every firehose partition through ephemeral request-scope Consumers and groups matches by request id with the SAME engine `wp nodes reqgrep` uses — the shared `Reqgrep_Core` class extracted from the CLI (grouping state machine, history buckets, byte/line caps, and the single `compile()` source of the match regex, so CLI and dashboard counts agree). The reply is a bounded summary (url, method, time, match count, first-match excerpt; result cap 50, global scan budget, every cap — including the engine's per-request clip — reported in `truncated`); clicking a result drives the existing request-detail deep link. Rid-shaped input keeps the exact lookup, and its miss message now hints at the `/pattern` syntax.
- **`Request_Builder` `set_alerts_target` verb — `alert` entries route to the fleet-alert journal.** `alert` firehose entries now forward to the alerts journal (`alerts:partition` → `alerts.p0`, the same dir the substrate's `Alerts::emit()` writes) instead of the errors target; `emit_error` became `emit_entry` with an explicit target. Wired in `request-builder.tsl`. Want alerts in the errors family too? Wire an `alerts:consumer → errors:partition` leg in topology — fan-out is graph wiring, not PHP.
- **`Log_Manager::alert()` + bridges for the substrate's diagnostic seams.** A new `Diagnostics_Bridge` listens on the substrate's `newspack_nodes/stderr` and `newspack_nodes/alert` actions: stderr/`print_less_often` lines join the active request's log as `k:'stderr'` entries (and forward to the Error Log; dropped when no logger is started — they already reach `error_log`), and fleet-health alerts reach the Error Log — via `Log_Manager::alert()` when a request logger is started, else written DIRECTLY into the errors family: an anonymous `errors.p{partition}` Topic with `KEY='fleet'` hash-routes to one `errors.p{N}` dir — the same dirs the request-builder writes and the dashboard's existing `errors.*` subscription already covers (zero UI change). The write is throw-safe so a failing write can never unwind the supervisor tick, and packed-byte-fitted under PIPE_BUF (character caps are a proxy) so it co-writes the multi-writer errors dir atomically. Error Log rows gain a distinct accent for `alert` and a muted one for `stderr`.

### Changed

- **Request Log + Error Log: pause = disconnect is now pinned uniform with the substrate viewers, and staleness reads "Paused" while paused.** Pausing already closed the SSE stream — freeing the bounded server slot — and resumed at the paused offset on Play; that behavior is now guarded against a fork: a visibility refocus never auto-resumes a user-paused stream (pause outranks visibility, both combined into one `isActive` gate), and a paused replay resumes mid-replay and still flips to Live at the seek boundary (the view node survives the pause, so the seek tracker's mode persists). A since-GC'd resume cursor is sent verbatim and degrades via the existing server-side resume validation — the client never clamps it or crashes the UI. The "Ns ago" staleness clock now reads a steady **"Paused"** while paused (previously it just vanished when the stream closed), via the substrate's new shared `StalenessIndicator` (`@newspack-nodes/shared/components`); the Gyroscope dashboard (no pause control) adopts the same component so the staleness decision no longer forks per dashboard. The client-side row-discard in the view nodes is kept as a one-line belt for the frame or two that can land in the pause-click→async-close window.
- **Adopted the substrate's first-class job identity; dropped the `parameters['job_id']` smuggling convention.** `Job_Router_Node` now forwards a job entry's top-level `id` verbatim into the written `jobs.log` record (pinned red-first; `Remote_Job_Rewrite_Node`'s whole-map k-rewrite already preserved it, now regression-pinned), and `Log_Manager::begin_job_context()` takes the action-provided `$id` — building the `/jobs/{handler}/{id}` request scope the dashboards render (plain `/jobs/{handler}` when empty) instead of a caller-baked compound handler string. The `before_job` listener now binds with `accepted_args=2` to receive `( $handler, $id )`. No transition fallback.
- **The errors, completed, and gyroscope partitions are now uniformly ≤PIPE_BUF atomic; `void_warranty` was dropped from all three** (`requests` keeps it — full request traces are legitimately large, likewise `flames` / `flame-stats` / `jobs`). Every producer fits its line under PIPE_BUF **at the source** through ONE shared `Line_Fitter::fit()` helper — halve an ordered list of trimmable VALUE fields until the packed line fits, mb-aware (packed bytes, not chars, since a multibyte char JSON-escapes to up to 6 bytes), dropping loud if unfittable. It replaced two near-duplicate private fits and now backs FOUR emits: `Diagnostics_Bridge` alerts (`m`), `Request_Builder::emit_error` (`m`, `url`), the completed-request compact summary (`url`, `user_agent`), and the per-request in-flight snapshot (`url`, `user_agent`). With the fit at the source, no partition needs the >4KB single-writer warranty; removing it enforces the atomic-append cap (an oversize record drops whole, never tearing a peer's write on the multi-writer dirs). The dashboards already clip long fields for display and the full line stays in the firehose record, so the source fit loses nothing an operator can currently see.
- **Gyroscope emits one message per in-flight request instead of one batched list per tick.** `Request_Flight_Node` serialized the ENTIRE in-flight LRU into a single `KEY='inflight'` message each tick (~420 B/row → past the 4 KB cap at ~10 concurrent requests, ~126 KB in the 300-row worst case), which the now-removed gyroscope `void_warranty` masked; the rows also wrote `url` / `user_agent` unclipped. It now emits one TM_STRUCT per request (`KEY='inflight'`, rid in VALUE), url/user_agent clipped 2000/500 to match the completed path. A new `delta` knob (`cmd request-builder:config set_inflight_delta 1`, **default OFF** — the stock topology does not set it) emits only rows whose activity advanced since the last tick (the Tachikoma `InstrumentalityFlight` watermark) for high-volume aggregation; OFF re-emits every row each tick so a fresh subscriber sees the whole cache within one tick. The gyroscope view node was reworked to the per-record shape (all batched-VALUE parsing deleted) and stays correct under BOTH modes with no mode awareness — rows keyed by rid, refreshed on each record, retired when the completed summary arrives on the same stream, and aged out on a 15-minute staleness backstop for crashes/evictions.

### Fixed

- **`Log_Manager::message()`'s size guard no longer misses invalid-UTF8 data.** The check now encodes with `JSON_INVALID_UTF8_SUBSTITUTE`, so oversized payloads containing invalid bytes hit the truncation branch instead of skipping it (`wp_json_encode` returning `false`) and being silently dropped by the Partition after substitution inflated them past the line cap.

### Removed

- `Diagnostics_Bridge::on_alert` and the `newspack_nodes/alert` listener — the
  substrate now journals fleet alerts itself into `alerts.p0` (requires
  newspack-nodes with the alerts-journal change). The bridge carries only the
  stderr seam now.

## [0.36.1] - 2026-07-16

### Removed

- **Deleted the orphaned `Logger_CI_Node` (`logger` CI) and `Events_CI_Node` (`events` CI).** Both were mounted REST CIs with no production callers: the settings hook picker reads `performance.hooks_registered` and the Overview hourly chart reads `performance.overview`, which reproduces the `events.stats` sum-merge exactly; `logger.config` had no JS consumer (the settings page renders config server-side). Removed the two classes, their mount lines, `LoggerCITest`/`EventsCITest`, the `M2Bootstrap`/`M2CommandDispatchE2E`/`ServiceCiHandlerGuard` entries, and the `docs/API.md` sections.

### Fixed

- **Error Log columns stay vertically aligned across rows with different URL and message lengths.** URL and Message now use deterministic fractional tracks instead of two independently content-sized `auto` tracks, and the header uses the same horizontal inset as its cells.

- **`Flame_Builder_Node`'s `set_is_hub` schema default used the wrong token namespace.** It read `<config:is_hub>`, but `is_hub` is owned by the `eln` namespace, not the substrate `config` resolver — so it silently resolved to `''` → `false`, disabling hub mode whenever the schema default was used. Corrected to `<eln:is_hub>` (matching the shipped `flame-builder.tsl`). A new `ElnConfigTokenTest` guard walks the node schema and fails loud if any `<ns:key>` token default isn't owned by its namespace.

### Changed

- **`Performance_CI` now rejects a non-JSON synced array-option value explicitly.** `Settings_Sync_Node::scalarize()` JSON-encodes arrays unconditionally, so the wire form is always JSON; the old comma-list "legacy senders" fallback in `decode_array_value()` was unreachable. A non-JSON value is now treated as a contract violation — rejected to `[]` with a rate-limited notice — instead of being silently comma-split. The orphaned `csv()` helper was removed.
- **The admin-asset enqueue reads `recommended_log_events` through the fail-loud `Config::value()` accessor** instead of `$cfg['recommended_log_events'] ?? []`, so a renamed/typo'd key throws at the boundary rather than silently yielding an empty recommended-hooks list.

### Removed

- **Dropped the Flame Builder EMA→sums legacy-shape read migrations.** Two blocks in `accumulate_all_stats()` that rewrote pre-fix (EMA running-mean) persisted aggregates lacking `sum_value`/`sum_req_time` into the empty sums shape are gone; no pre-fix values survive, and the live current-shape (sums) read path — including the `flame_raw` raw-for-merge restore — is unchanged.

## [0.36.0] - 2026-07-16

### Changed

- **Migrated to the newspack-nodes token-array command contract.** TM_COMMAND `arguments` and node-constructor `arguments` are now a token array (`list<string>` argv) rather than a joined string, matching the substrate change. The App service CIs (`performance`, `events`, `logger`, `discovery`, `rules`) and the Node subclasses (`Request_Builder`, `Flame_Builder`, `Job_Router`, `Discovery_Collector`) read their verb/constructor arguments as tokens; the `rules` `save`/`upsert` verbs read the whole JSON blob as a single argument token; the `reqgrep` CLI, `Log_Manager`'s firehose Topic construction, and the dashboard hooks (`useRulesGraph`, `usePerformanceGraph`, `useRequestLogGraph`, `useErrorLogGraph`, `useGyroscopeGraph`, `useHookCatalogGraph`) all produce and consume token arrays. This fixes a latent wire bug in `useHookCatalogGraph`, which sent an empty-string `arguments` to the array-typed PHP command handler.

### Added

- **Error Log now shows the request method and URL for each error.** Request Builder copies authoritative URL context from the active request onto error messages, including the same worker-type marker used by completed requests. Error Log renders the same URL-detail link as Request Log and admits URL matches through its pre-buffer search filter. The errors partition opts into sole-writer large-record writes so the enriched records are retained beyond the atomic-append size warranty.

## [0.35.2] - 2026-07-15

### Fixed

- **Long-lived dashboard command channels now share the substrate's renewable WordPress credentials.** Request Log, Error Log, Gyroscope, hook-catalog, and rules clients use the page-local `CommandClient.fromGlobal()` path, so an expired REST nonce is renewed and the failed command retried once without replacing explicitly configured remote credentials. Requires the accompanying Newspack Nodes release that provides bounded nonce renewal.

- **Request Log and Error Log search now filters before the ring buffer.** Changing the search term clears and rebases the view node's ring, then only matching incoming rows consume buffer slots; React no longer repeatedly filters stored history. Accepted rows receive consecutive stable sequence numbers, so stripes remain alternating without changing color under live prepends or scrolling. Filter changes also reset the old ring's scroll animation, and render snapshots now include view-node identity so a rebuilt graph cannot leave equal-shaped stale rows on screen.

- **Server-filtered performance overview cards now use the selected server's data.** The overview keeps its global server routing dimension while scoping the other breakdowns, matching Flame Builder's intentionally non-redundant storage. Total requests, average response, request rate, and average peak memory now come from the same selected-server series instead of mixing zeroed scoped lookups with global memory.

## [0.35.1] - 2026-07-15

### Fixed

- **`wp nodes reqgrep` and `wp nodes ruleset-bench` no longer shadow WP-CLI global arguments.** Both declared options whose names collide with WP-CLI globals, so WP-CLI warned at registration. `ruleset-bench`'s `[--path]` was a pure redeclaration of the global WordPress `--path` (never read) and is removed. `reqgrep`'s firehose-base-directory override moved from `[--path]` to `[--firehose]` — the old name collided with the global `--path` (which WP-CLI consumes before the command runs), so the override could never actually receive a value.

## [0.35.0] - 2026-07-15

### Changed

- **Local config overrides now honor the operator's explicit file selection.** `LOCAL_NEWSPACK_NODES_CONF` accepts any canonical, readable regular `.php` file instead of restricting overrides to the active plugin and WordPress roots; an explicitly invalid path or returned value tree throws instead of silently falling back to bundled defaults. The merged application view preserves effective substrate values after their `newspack_nodes_*` WordPress option overlay for substrate-owned keys, while overlapping application-owned keys such as `allowed_users` remain under the independent `newspack_event_logger_nodes_*` option/config contract. Requires the accompanying Newspack Nodes release with the relaxed shared `Config_Utils` contract.

- **The Flame Builder stats-mirror command and PHP setter are now `set_stats_target`.** The schema declares its optional argument as a `node_name`, matching the other graph-producing config commands. Together with token-aware substrate graph folding, a configured mirror now renders `flame-stats` beneath `flame-builder`; an empty `<eln:stats_mirror_node>` still disables the mirror. Requires the accompanying Newspack Nodes release that provides config-target token folding through `resolved_config_edges`.

- **Job Router now runs nested firehose jobs and flat Job Intake jobs through one source-agnostic normalization path.** Both `job` and `remote_job` kinds are preserved without gating on `Message::FROM`, and the stock jobintake Consumer feeds Job Router before `jobs:partition`. A new positional `stale_timeout` argument controls the maximum accepted job age (default `60` seconds; the exact boundary is accepted); it fails loudly unless numeric, finite, and non-negative. Missing, non-numeric, non-finite, or older timestamps are audited and dropped. The unused public `Job_Router_Node::MAX_JOB_SIZE` alias is removed with the duplicated router size gate; callers that need the producer limit use `\Newspack_Nodes\Job_Intake::MAX_JOB_SIZE`, while the downstream Partition enforces writes.

### Fixed

- **Row striping broke under a search filter in the Request Log and Error Log.** Each row baked in an `isEven` flag at INGEST time (`entryCounter % 2`), so it reflected the row's position in the full stream, not the filtered view. Filtering out rows left the survivors with a stream-parity that no longer alternated — adjacent visible rows shared a shade. Both dashboards now stripe from the rendered (filtered, virtualization-absolute) index `startIndex + i`, the way the Gyroscope Inflight table already did. The dead `isEven` field is gone from both view nodes.

## [0.34.2] - 2026-07-14

### Fixed

- **Every Consumer now declares a dead-letter dir.** `firehose:consumer` (request-builder, job-router), `requests:consumer` (flame-builder) and `settings:consumer` (hub-control) all omitted it, so poison was logged and dropped instead of quarantined — the substrate disables the DLQ when the dir is empty. The aggregator spoke gets one too, now that `Remote_Source` no longer derives an implicit path.

## [0.34.1] - 2026-07-13

### Changed

- **`combined` composes the performance stack through the nested `performance` include** instead of pulling `request-builder` and `flame-builder` in directly. `performance.tsl` is exactly those two includes, so the graph is unchanged — but it now says so structurally, and the substrate's nested-hull work ([148], newspack-nodes 0.43.0) renders the nesting rather than flattening it into one undifferentiated group.

- **`aggregator.tsl` documents `Remote_Source`'s offsetlog + deadletter arguments** ([147], newspack-nodes 0.43.0). The dirs are ARGUMENTS now, not hardcoded config reads, so the cursor path can carry `<topology>` and two hubs pulling the same spoke partition no longer share one offsetlog. Omitting them falls back to the legacy derived paths, so existing topologies are unaffected.

## [0.34.0] - 2026-07-13

### Changed

- **Every Consumer offsetlog is `<topology>`-scoped.** An offsetlog is a reader's *cursor*, and the reader is the fleet. `request-builder` and `job-router` both tail the firehose, and `combined` wants ONE consumer feeding the Tee — which `include` only gives you if their `make_node` lines are byte-identical, i.e. if they name the same offsetlog. That made the two unsafe to run side by side (two fleets, one cursor). With the substrate's new `<topology>` token the declarations stay identical *and* each fleet gets its own cursor: `firehose.combined.p0` when composed, `firehose.request-builder.p0` / `firehose.job-router.p0` when standalone. The decomposed set co-runs conflict-free again, and `combined` still conflicts with each broken-out topology — they share a *log*, and only the cursor is fleet-scoped.

  **Cursors move**, so each fleet's Consumer starts from offset 0 once and replays its log backlog; the old offsetlog dirs are reclaimed by the GC.

- **The topology stack composes with `include` instead of copy-pasting.** `combined.tsl` is now `include request-builder` + `include flame-builder` + `include job-router` plus the lines that are genuinely its own: it makes `firehose:tee`, disconnects `firehose:consumer`, and routes it through the Tee out to both `request-builder` and `job-router`. That rewiring belongs to whoever composes both sides, which is `combined` — `performance.tsl` used to own it while referencing `job-router` without including it, so it could not stand alone and silently depended on being included in the right order. `performance.tsl` is now what its name says: `request-builder` + `flame-builder`.

  **Requires newspack-nodes ≥ 0.42.0** for TSL `include` and the `<topology>` token.

### Fixed

- **Gyroscope in-flight state badges are legible again.** The badge hardcoded white text (`.event-logger-state-badge { color: $white }`) while its background came from the hook-category palette, most of which is pale — an `option_home hook` chip (`#CDDC39`) or a `pre_kses hook` chip (`#BDBDBD`) rendered white-on-pale at ~1.5:1 contrast. Every badge (row state, category legend, process/complete legend) now takes its ink from the substrate's new `getTextColor()`, which chooses dark or white per background luminance. Requires newspack-nodes with `getTextColor()`.

## [0.33.2] - 2026-07-13

### Fixed

- **Config keys are declared via the substrate's `DECLARE_ACTION` pull, so an early read can't fatal.** Key registration was wired only into the deferred `plugins_loaded:11` loader, but the profiler drop-in flushes its first log line at `plugins_loaded:-10001` — `Log_Manager::message()` → `ensure_started()` → `init_firehose()` — some 10k priorities earlier. Reads of app keys therefore raced the loader, and reads of SUBSTRATE keys (`num_partitions` et al., which a frontend request never declared) threw `RuntimeException: unknown config key 'num_partitions'` and 500'd the request. `register_config_keys()` is now hooked to `\Newspack_Nodes\Config::DECLARE_ACTION` at plugin-file scope (the action name is a literal there — this plugin sorts before `newspack-nodes`, so the substrate class isn't loadable yet to read the constant), and the substrate pulls it whenever it derives its declared set. The `plugins_loaded:11` declaration and the `value()`-time self-registration are both gone; there is one declaration path. Requires newspack-nodes >= 0.40.1.

## [0.33.1] - 2026-07-13

### Fixed

- **`print_less_often()` call sites split throttle-key from varying payload (consumes the substrate's variadic port).** Migrated the ELN sites that baked per-call-varying data into the single throttled string — so the rate-limiter minted a fresh key every call and never suppressed, flooding worst exactly when the event was most frequent. Each now passes a STABLE category prefix as the first arg (the throttle key) and the varying values as trailing `...$extra` args (printed on first sight, never keyed): the headline `Request_Builder_Node` `duplicate message` / `missing message` / `multiple requests with ID` / `trace timed out` warnings (per-message `seq_n`/`rid`/`url`/`duration_ms`), plus `Job_Router_Node` `invalid handler name`, `Hook_Categorizer`'s two pattern-rejected warnings, `Rule_Set`'s `hooks missing for pointer rule`, and the admin `restart_workers failed` notice. `Log_Manager`'s `data truncated for category` notice keeps the **category IN the key** (with only the varying size in payload) so each category whose payload is being dropped surfaces its own line rather than collapsing to whichever truncated first — the category is a bounded, meaningful discriminator for a data-loss warning. Emitted text is byte-for-byte unchanged — only the split point moved. Left unchanged: `Job_Router_Node`'s two `$handler`-leading messages (no stable leading prefix; splitting would reword the text), `Remote_Job_Rewrite_Node`'s constant-only message (fully static), and `Flame_Builder_Node`'s `stats_partition` message (per-node-stable).

## [0.33.0] - 2026-07-12

### Changed

- **`hub-control.tsl` settings-sync map follows the substrate's renamed remote-geometry keys.** ELN ships its own `hub-control` topology that shadows the substrate's, so its `add_setting` lines must track the substrate rename: `newspack_nodes_remote_num_segments` → `newspack_nodes_remote_max_segments` and `newspack_nodes_remote_max_lifespan` → `newspack_nodes_remote_min_lifetime` on both the hub→spoke remap (`→ settings newspack_nodes_max_segments` / `newspack_nodes_min_lifetime`) and the spoke's own remote_* self-map; `remote_segment_size` is unchanged. Stale duplicate substrate keys (`num_segments`/`max_lifespan`) were also dropped from the test config fixtures now that the substrate exposes `max_segments`/`min_lifetime`.
- **Topology retention geometry migrated to the substrate's split min/max schema.** Every `make_node Partition`/`Topic` line in the `.tsl` files now emits `… segment_size min_segments max_segments min_lifetime max_lifetime` (the old single `num_segments` / `max_lifespan` pair became a four-token count-plus-lifetime window), matching the migrated `Partition_Node`/`Topic_Node`/`Log_Node` argument order and the config keys `Log_Manager` already reads. New `min_segments`/`max_segments`/`min_lifetime`/`max_lifetime` config keys are read for the firehose Topic and every data partition.
- **The gyroscope, request-log, and error-log dashboards subscribe by glob** (`gyroscope.*`, `completed.*`, `errors.*`) instead of a bare feed name, matching the substrate's new layout-agnostic SSE resolver — the server no longer expands a bare `{feed}` into `.p{N}` partitions; the client names its convention with `{feed}.*`. **Requires newspack-nodes with the glob `open_subscription`** (the resolver change ships together).

### Fixed

- **Stats-retention window re-coupled to the substrate's renamed `min_lifetime` config key.** Five readers (`newspack-event-logger-nodes.php` dashboard `retentionSeconds`, `Performance_CI_Node::stats_stores`, `Events_CI_Node`'s `stats` verb, `Flame_Builder_Node`, and the admin flush-stats handler) sourced ELN's `Stats_Store` retention window from the substrate config key `max_lifespan`, which [138] renamed to `min_lifetime`. After the rename every reader silently fell back to the hardcoded 86400 default, decoupling the stats window from the operator's configured retention. Each now reads `$config['min_lifetime'] ?? 86400` (the new name for the same min-retention age); `Stats_Store`'s own `max_lifespan` constructor parameter/property is unchanged (the value still flows into `Stats_Store( max_lifespan: … )`).

### Added

- **Fail-loud config reads: `Config::value()` over the merged config (phase 2 of the substrate config sweep).** ELN gains its own `Config::value( $key )` that reads THIS plugin's merged config (substrate values layered under ELN defaults + option overlay) but validates the key against the SHARED substrate registry via the new `\Newspack_Nodes\Config::is_declared()` — an undeclared key throws a `\RuntimeException("unknown config key '…'")` instead of limping on a `?? default`. ELN registers its own keys (`Settings_Schema` overlay keys ∪ `array_keys()` of its config-file defaults) with the shared registry in the deferred `plugins_loaded` pri-11 bootstrap, guarded on the substrate being present. Every top-level `$config['key'] ?? default` read was migrated to `Config::value()`, dropping the redundant fallback: the firehose retention geometry (`num_partitions`/`segment_size`/`min_segments`/`max_segments`/`min_lifetime`/`max_lifetime` in `Log_Manager::init_firehose`), `min_lifetime` in `Flame_Builder_Node` / `Events_CI_Node` / `Performance_CI_Node` / the admin flush-stats handler, `num_partitions` across the `Performance_CI_Node` disk-walkers, `hook_start_priority` in `App\Core`, `allowed_users` in `Admin`, `rules` in `Rule_Set::seed_from_config`, and `custom_colors` in `Config::get_custom_colors`. Every migrated key is a substrate- or ELN-config-file default, so `value()` never returns null where the old `?? default` used to stand in. Whole-array `load_config()` uses (incl. `Log_Manager`'s held `$this->config` flag reads) and the dynamic-key `resolve_eln_token` tail are unchanged.
- **Argument tooltips: app node `node_schema` constructor arguments now carry descriptions** — `Discovery_Collector`'s `interval_seconds` and `Request_Builder`'s `bucket_size` / `num_buckets` — so the topology console shows a tooltip for each. A new `AppNodeSchemaCoverageTest` gate fails if any app node_schema argument lacks a description. (Consumes the substrate's `CtorField` tooltip wiring in newspack-nodes.)

### Removed

- **The one-release `Job_Intake` BC `class_alias` is gone.** `Newspack_Event_Logger_Nodes\Job_Intake` (aliased to the substrate `\Newspack_Nodes\Job_Intake` when the class moved in 0.29.0, "remove next release") is no longer registered — use the substrate FQN directly. Its pinning `JobIntakeAliasTest` is removed with it; no in-tree code referenced the old name.

## [0.32.0] - 2026-07-11

### Changed

- **Extracted the pure flame-graph algorithm into a `Flame_Tree` helper**, thinning `Flame_Builder_Node` by ~283 lines. The five stateless methods (`build_flame_data` — now taking `now_ts` as a param instead of reading the node clock —, `number_duplicate_siblings`, `strip_name_suffixes`, `merge_flame_children_incremental`, `finalize_flame_node`) plus their parsing constants move verbatim to `Flame_Tree`; `Flame_Builder_Node` calls `Flame_Tree::…`. Behavior-identical (full suite green); the now-standalone algorithm gains a focused `FlameTreeTest`. `AGGREGATE_EXPIRY_SEC` is a self-contained copy in each class (keep-in-sync note) rather than coupling the pure helper back to the node.
- **Pre-push now runs the coverage suite and gates every class at ≥ 90% statement coverage** via a self-contained `scripts/coverage-gate.py` (clover parse borrowed from dndocker's `tools/coverage-summary.py`; `scripts/test-coverage-gate.sh` covers it). Skips gracefully off-dndocker.

## [0.31.0] - 2026-07-11

### Fixed

- **`Request_Builder` and `Flame_Builder` finish their per-message bookkeeping before a cooperative stop unwinds, so a clean recycle no longer replays + dedups the last line.** When a downstream partition write triggers the stop (the substrate now writes durably, then signals it), each snapshot node defers the `Worker_Should_Stop` around its downstream forwards (Tee-style, first one wins), completes the message — Request_Builder evicts the completed request from its in-flight cache; Flame_Builder still accumulates the request's stats — then re-raises `Worker_Should_Stop_Clean` so the Consumer commits past the line. Pairs with the substrate's precise-checkpoint change (newspack-nodes ≥ 0.37.0); previously the completing line replayed and was deduped, dropping the assembled request doc / double-nothing the stats. (`Digest_Builder` is a pure accumulator — no downstream forward — and needs no change.)
- **`Flame_Builder::save_state()` now co-commits the current per-URL flame trees with the cursor, not just the `pending` aggregates.** `accumulate_all_stats()` writes both the aggregate sums (`pending`, saved in the offsetlog frame) and the per-URL flame trees + profiles (`stats_cache`), but `stats_cache` only reached durability via the periodic `flush()` (every `FLUSH_INTERVAL_SEC`). A clean recycle advanced the cursor past messages whose flame trees were only in RAM → up to a flush window of per-URL flame data silently lost on every recycle. `save_state()` now drains the current `stats_cache` to the store (shared `mirror_url_stats()` helper, `set_url_stats` overwrites so it's idempotent with the next `flush()`) before writing the mirror, so the flame trees commit at the same cursor as `pending`.
- **The snapshot nodes' deferred-stop no longer leaks across messages.** `pending_stop` is a per-message deferral; if a non-`Worker_Should_Stop` throwable escaped a `fill()` after a `guarded()` catch (the Consumer dead-letters it and the worker survives), the stale deferral stranded into the next message and clean-stopped that innocent, RAM-only line — dropped on the replay-less resume. Both `Request_Builder` and `Flame_Builder` now clear `pending_stop` at `fill()` entry.

## [0.30.0] - 2026-07-11

### Changed

- **Dropped the now-redundant `delete_on_blank: false` from the three bool settings** (`enable_logging`, `log_memory`, `flush_every_line`). The substrate's `Config_System\Field` now derives blank-delete from type (`'bool' !== $type`), so a checkbox opts out automatically. Behavior-identical.
- **Diagnostics route through `Core::` logging instead of raw `error_log()`.** The per-tick "hooks missing for pointer rule" flood and the "corrupt rules option" load error (`Rule_Set`), `Log_Manager`'s data-truncation notice, and `Hook_Categorizer`'s two pattern-rejection warnings now use `Core::print_less_often()` (rate-limited — emit once, suppress repeats) or `Core::stderr()` — matching the substrate convention, so the high-frequency messages stop flooding the log and gain the node stderr chain + REPL visibility.

### Fixed

- **Hub→spoke settings-sync now carries a pointer rule's hooks, ending the perpetual "hooks missing" flood on spokes.** A heavy LOG rule (>100 hooks) stores its hooks in a separate non-autoloaded durable option, and settings-sync shipped only `OPTION_RULES` — so the hooks never reached the spoke and every request logged `hooks missing for pointer rule`. The sync value now hydrates the ruleset (inlining each pointer's hooks via `Rule_Set::hydrate_array()`) on the way out, and the spoke re-tiers it locally via `Rule_Set::apply_synced()` → `save()` (writing its own durable option, keeping `OPTION_RULES` small). An unresolvable pointer on the hub (its own durable option lost) stays a pointer instead of inlining `[]`, so a transient hub miss can't wipe a spoke's good hooks.
- **Logging-rules editor buttons now use the canonical stock `.button`** instead of `@wordpress/components` `<Button>` (`.components-button`), so they render as standard rounded WordPress buttons themed by the shared `wp-reskin`/`skin-adaptive-buttons` mixins — matching the rest of the dashboards. Affects the "Add rule" modal (Select Hooks / Select Events / Cancel / Save rule) and the hook / custom-event selector modals it opens. Also deletes the redundant `.custom-event-btn`/`.custom-event-apply` SCSS that re-declared button appearance (radius/padding/font/hover) already owned by the shared source.
- **More dashboard buttons normalized to the canonical stock `.button`**: the rules table's "+ Add Rule" (`button button-primary`, keeping its `rules-admin__add` layout hook), the per-row Edit (`button button-small`) and Delete (`button button-small button-link-delete`), the delete-confirm dialog's Cancel (`button`) and Delete (`button button-link-delete`), the TagInputField "Select Hooks"/"Select Events" selector buttons (`button`), and the URL-table Prev/Next pagination controls (`button button-small`). Destructive actions now keep their red via the canonical `button-link-delete` class rather than `<Button isDestructive>`. Trimmed the pagination-btn SCSS to just its disabled dim and the `.event-logger-tag-remove` icon-button SCSS to just its pill-fit sizing — dropping the border/background/radius/color chrome already provided by the components icon button and the shared source.

## [0.29.0] - 2026-07-10

**Requires newspack-nodes ≥ 0.34.0** (the release carrying `\Newspack_Nodes\Job_Intake`, the substrate class this release delegates to).

### Removed

- **`Job_Intake` moved to the newspack-nodes substrate** (`\Newspack_Nodes\Job_Intake`). A one-release `class_alias` keeps the old `Newspack_Event_Logger_Nodes\Job_Intake` FQN loadable for out-of-tree callers — removed next release. Topologies are UNCHANGED: `job-router.tsl` and `combined.tsl` keep their jobintake legs (the substrate's new stock `job-intake` topology is a standalone subset for substrate-only installs, conflict-gated against both). `Job_Router_Node::MAX_JOB_SIZE` now derives from the substrate's canonical `Job_Intake::MAX_JOB_SIZE`.

### Changed

- **`Job_Intake`'s constructor drops its half-dead `class_exists( '\Newspack_Nodes\Config' )` guard** — the next line already called the substrate Config unconditionally, and the plugin hard-requires newspack-nodes. Behavior-neutral. Test infra: the WP Settings-API / escaping / i18n stubs consolidated from per-test-file copies into `tests/bootstrap.php` (with a shared `RedirectException` helper), so every suite — including ones run in isolation — sees one set of definitions.

- **Coercions now use the substrate's canonical `Core` helper family** (`as_*` lenient cast, `num_*` strict numeric, `str`/`arr`/`int` passthrough — all take an optional default). ~90 inline `\is_*( $v ) ? [(cast)] $v : <fallback>` sites fold onto them across the builders, stats store, rules, hook categorizer, config, CI nodes, and reqgrep, and the per-class wrappers (`to_int`/`to_float`/`to_string`/`to_str` in `Rule`, `Rule_Set`, `Log_Manager`, `Performance_CI`, reqgrep; `Stats_Store::num_*`) are deleted with callers repointed. Strictness is preserved exactly per site — the `is_numeric` arithmetic paths still zero out bools and partial-numeric strings. Flame-builder also hoists one `now_ts()` clock read out of its entries loop (batch-consistent timestamps). Sites whose fallback is a call, `: null`, or rides a compound guard stay inline by design.

- **Behavior-preserving elegance pass (writing-elegant-code forces), verified against the architecture decisions.** Highlights: the flame-builder's duplicated inline category-cap block now calls the existing `cap_single_bucket()`; magic numbers named across both builders (`INTERN_TABLE_LIMIT`, `AGGREGATE_EXPIRY_SEC`, `MAX_URLS_PER_BUCKET`, `INTERN_MAX_KEY_LENGTH`, `MAX_PROFILE_ENTRY_LABELS`) and the 32 MB job cap rewritten as `32 * 1024 * 1024` in intake + router; `Stats_Store`'s repeated numeric coercions collapsed into `num_int()`/`num_float()` (sums-not-means math pinned by new characterization tests); duplicated blocks extracted in `Performance_CI` (`merge_dim_buckets_into`), reqgrep (`finalize_if_complete`), and Admin (`verify_admin_post`); dead locals removed from `Log_Manager::begin_job_context()`. PIPE_BUF audit passed — no change touches `Log_Manager::message()`'s truncation contract or grows any encoded payload.

## [0.28.1] - 2026-07-09

### Changed

- **Rebuild the dashboard bundles against the updated `newspack-nodes` shared runtime.** The substrate's SSE rework (newspack-nodes 0.32.x) made the `connected` handshake its own SSE `event:` type — the shared `SseInNode` now routes it via `addEventListener( 'connected' )` and snoops slot/pid from it, instead of KEY-peeking every `msg`. Rebuilding the bundles ships that runtime so the live-dashboard slot keep-alive bridge reads the typed `connected` frame in production. The requests / error-log / gyroscope hook tests were updated to dispatch the handshake as a `connected` event to match.

## [0.28.0] - 2026-07-09

### Added

- **`$config['rules']` seeds the per-URL ruleset when no rules option is stored.** `rules` is now a `Settings_Schema` overlay-only field (`ui: false`), so a deployment can declare its rules in `newspack-event-logger-nodes-config.php` and `Rule_Set::load()` builds the ruleset from them (read-time, non-persisting) until the rules editor writes the option — the same role the old `Rule::minimal( '/' )` fallback filled, now config-driven. Config rules omit `id` entirely — see below.
- **Discovery no longer writes the ruleset — it stages hooks like it stages events.** `Discovery_Collector_Node::merge_hooks()` previously union-merged spoke-reported `registered_hooks` into the baseline `/` LOG rule (minting one if absent) and saved the ruleset — an implicit, propagating log-all that fought "empty means empty." It now stages them in a non-autoloaded `discovered_hooks` option alongside `discovered_events` (a shared `stage_discovered()` helper writes both; custom-event names stage only as events, never as hooks); the editor is the only writer of rules. Discovered hooks surface in the rules editor's hook picker via `Hook_Categorizer::get_registered_hooks()` (symmetric with `discovered_events` feeding `Config::get_custom_colors()`), available for the operator to add — not auto-instrumented. The now-unused `Rule::minimal()` factory is removed.
- **Empty means empty — there is no implicit log-all baseline.** Empty or absent config `rules` (like a stored `[]`, reachable by deleting every rule in the editor) yields a zero-rule set that logs nothing, rather than the former synthetic `/` log-all fallback. `seed_from_config()` no longer coerces an empty list to `Rule::minimal( '/' )`. Deployments that want to log everything declare a `/` log rule explicitly (the shipped default config does); if you declare no rules, nothing is logged.
- **The shipped default config seeds baseline skip rules.** `newspack-event-logger-nodes-config.php` now ships a `rules` list that skips the substrate's worker endpoints (`/wp-json/newspack-nodes/v1/{command,messages/stream,workers/spawn}`) and `/wp-cron.php`, and logs everything else (`/`) — so a fresh install doesn't log the logger's own worker IPC/SSE/spawn traffic. Only seeds when no rules option exists; existing installs (which have a stored/migrated option) are unaffected.

### Changed

- **A rule's id is now the pattern's `url_hash`, not a positional token.** `Rule_Set::id_for( $pattern )` derives every rule id from its URL pattern via the shared `Log_Manager::url_hash()`, replacing the positional `generate_rule_id()`/`gen_id()` (`substr( md5( 'eln_rule_' . $n ), 0, 8 )`). The pattern is the identity: there is exactly one id per pattern, so the ruleset can never hold two differently-configured rules for the same URL. All minting sites — `migrate_from_legacy()` (which now dedupes a URL appearing in both `skip_urls` and `log_urls`, skip winning), the config seed, and the `rules` CI (`save` derives + dedupes, `upsert` matches by pattern so it replaces rather than duplicating and drops the old-pattern entry on a rename) — route through it; a client-supplied id is ignored. `Rule_Set::load()` mints an id for any stored rule that lacks one (e.g. a settings-synced config default) while trusting a non-empty legacy id, so no rule ever collides on an empty-string key. Rule edits still never lose a pointer-tier rule's hooks because the editor round-trips the full resolved hook list on every save.
- **`url_hash()` + `fnv1a32()` moved from `Request_Builder_Node` to `Log_Manager`** as the single shared URL-identity primitive (firehose per-URL key, per-URL rule id, and stats bucket all agree). `Flame_Builder_Node` no longer reaches across to `Request_Builder_Node` for it.
- **The ruleset migration now runs solely on activation, and rekeys existing installs to the pattern-hash id scheme (schema v2).** `Rule_Set::migrate_from_legacy()` is version-gated (`SCHEMA_VERSION = 2`): v0→v1 folds the seven legacy options into a ruleset (as before), v1→v2 rekeys every stored rule's id to `id_for(pattern)`, resolving a pointer-tier rule's hooks under its old id first and re-tiering under the new one so no hooks are lost, and reconciling the orphaned durable option away. A same-pattern collision during the rekey (two stored rules for one URL) collapses to a single rule with skip winning, regardless of stored order — matching the v0→v1 fold's precedence. It fires from a `register_activation_hook`; the deploy deactivates then re-installs+activates (a genuine inactive→active transition), so stored rules normalize at deploy time and no request path re-checks a version gate. The former admin/CLI safety-net call (and its skip/log overlap admin notice) is removed.

## [0.27.0] - 2026-07-07

### Changed

- **`fill()` takes the message by value** (`array $message`, was `array &$message`), matching the substrate's by-value contract: every Node subclass (and `Log_Manager::relay_topic_to_ci`) owns the message it is given and forwards a value to its sink — nobody reaches back across a `fill()`. Requires newspack-nodes ≥ 0.29.0.
- **`wp nodes reqgrep` reads stdin and writes output through the substrate's `Stdin_Node` / `Stdout_Node`.** The hand-rolled `while ( fgets )` stdin loop is replaced by a `Stdin_Node` (eof-deadline 0) driving a `Callback_Node`, and every `echo` now routes through a swappable `$stdout` node (a `Stdout_Node` in production, fwriting straight to STDOUT). This removes the old output-buffer-drain hack (`drain_output_buffers()` / the `ob_end_clean` loop) entirely, since `Stdout_Node` bypasses PHP output buffers. Byte-for-byte output is preserved.

### Fixed

- **Modal close (×) buttons are visible again under dark skins (CRT, etc.).** WordPress `<Modal>`s portal to `document.body`, so the dashboards re-apply the skin classes to the modal frame — that themes the surface, but the WP close button still inherits its near-black default `currentColor` (`rgb(30,30,30)`), invisible on a dark CRT background. The performance-dashboard URL/request modal and the Hook/Custom-Event selector pickers never coloured that button (only `rule-edit-modal.scss` did); they now paint `.components-modal__header button` with `var(--ink)` (dashboard-only modal) / `var(--ink, var(--np-text))` (the selectors, which also open on the light settings page). The `×` glyph inherits the colour via the SVG's `currentColor` fill.

## [0.26.1] - 2026-07-07

### Changed

- **The request-details `environment_v3` map renders alpha-sorted by key.** `LogEntriesTable` now sorts the object/map keys before pretty-printing (the producer emits them in allowlist order), so the ~33-key environment block is scannable. Display-only — the stored firehose payload and `wp nodes reqgrep` output are unchanged.
- **Searching the log entries no longer splits an empty pair open just to land on it.** When a search match is a start/complete of an empty pair (start immediately followed by its complete — the same pairs Unfold All leaves merged), navigating to it now keeps it a single merged row and scrolls to that row, instead of unfolding it into two lines.

### Fixed

- **Log search can now jump to a nested leaf entry (e.g. `include (error)`).** `getAncestorPairIds` computed the wrong indent level for leaf entries (error/info children), so it never added their containing pair to the expand set — the parent stayed folded and the row never rendered, leaving the match unreachable ("1 match" but `n` wouldn't move to it). A leaf sits one indent level deeper than the start that contains it (a complete is normalized to its start's level); the walk now looks one level up for leaves, so the parent pair expands and the match scrolls into view.

## [0.26.0] - 2026-07-07

### Changed

- **The per-URL flame-profile mirror top-N is a configurable field, not a const.** A `cmd <flame-node>:config set_flame_topn <n>` verb (round-tripped by `dump_config`, exposed as `Flame_Builder_Node::set_flame_topn()`) sets the NS_URL mirror cap; production keeps the default of `0` (flame profiles stay unmirrored to memcache for perf — unchanged), but a non-zero cap mirrors the top-N profiled URLs by traffic, which tests use to exercise the persisted-profile shape (the profiling-detail gate, the top-N eviction, large-write void-warranty). This fixes three stale FlameBuilder tests that still asserted the pre-`aab0390` mirror behavior after that commit set the cap to 0.
- **Dashboards adopt the substrate's global `<html>` skin class.** `ThemedRoot` no longer stamps a `theme-<slug>` class on its own wrapper — the skin moved to a single `<html>.theme-<slug>` class in newspack-nodes 0.28.2, so a `set_skin` from the debug overlay now re-skins the dashboard behind it too (no more CRT scanlines bleeding onto a differently-skinned overlay). `ThemedRoot` applies the persisted skin on mount via `initSkin()` and repaints the WP-admin gutters live on the substrate's `SKIN_EVENT`.
- **The request environment is logged as ONE curated `environment_v3` message instead of ~one `environment_v2` line per `$_SERVER` key.** `Log_Manager::log_environment()` now emits a single entry whose `m` is a `{ KEY => value }` map of a fixed allowlist of ~33 diagnostically-useful `$_SERVER` keys (REMOTE_ADDR, HTTP_X_FORWARDED_FOR, HTTP_USER_AGENT, SERVER_NAME, GEOIP_COUNTRY_CODE, HTTP_FROM, the JA3/JA4 fingerprint headers, the A8C edge/proxy diagnostics, and the worker-identity keys NEWSPACK_NODES_WORKER_TYPE / NEWSPACK_NODES_WORKER_PARTITION so worker requests stay tagged, etc.), dropping the request-line duplicates (REQUEST_METHOD / REQUEST_URI / QUERY_STRING). Per-value sanitization is preserved — control-char stripping, `redact_url()` on HTTP_REFERER, and `is_sensitive_key()` filtering — while quote-safety is now handled by JSON encoding (manual `"`-escaping was dropped as it would double-escape the map). Typical encoded payload ≈ 0.6 KB, worst-case ≈ 2.1 KB — comfortably under the 3840-byte firehose cap that dumping all of `$_SERVER` in one message would blow past. `Request_Builder_Node`'s consumer reads every field back from the single entry. The Perl producer (`Gyrobase::Log`) mirrors the same allowlist and shape. **Category bumped `environment_v2` → `environment_v3`**; the deferred Perl `InstrumentalityGrail.pm` consumer still keys on `environment_v2` (no longer emitted), so it degrades to no-environment rather than misparsing the new shape.

### Fixed

- **`environment_v3`: one oversized value no longer drops the whole environment map.** `Log_Manager::log_environment()` (and the Perl `Gyrobase::Log` producer) now cap each allowlisted value at 256 bytes (ellipsis-elided) *before* building the map. Previously a single long client-controllable value (a 2 KB `HTTP_REFERER`, a long `HTTP_USER_AGENT`, a many-hop `HTTP_X_FORWARDED_FOR`) could push the encoded map past `MAX_DATA_SIZE` (3840 B) and get the entire map truncated away — losing *every* environment field. The full curated allowlist, even with several oversized values, now stays comfortably under the cap.
- **`environment_v3`: URL-secret redaction restored to a catch-all.** `redact_url()` is now applied to *any* allowlisted value that carries a URL query (contains `?`), not just `HTTP_REFERER`. When the environment collapsed from per-key `environment_v2` lines to the single `environment_v3` map, the old per-line "`m` contains `?`" redaction stopped covering non-referer values (e.g. `A8C_PROXIED_REQUEST`), which could log a query secret in the clear. Mirrored in the Perl producer.
- **Perl `Gyrobase::Log::_write_entry`: size/truncation and encode-failure fallbacks operate on the encoded form.** The over-`PIPE_BUF` truncation stub and the JSON-encode-failure sanitizer treated the message payload as a string; for `environment_v3` the payload is a hashref, so they stringified it to `HASH(0x…)` (losing the whole map) and reported a bogus byte count. Both paths now JSON-encode the value before measuring/sanitizing and never run a regex over a ref.
- **Request-details dashboard pretty-prints object/map entry values.** The "Log Entries" table (`LogEntriesTable`) now renders an entry whose `m` is a JSON object/map (`environment_v3`) as indented multi-line JSON in the `pre-wrap` message cell, matching `wp nodes reqgrep`'s `JSON_PRETTY_PRINT` output, instead of a single-line blob. String values render unchanged.
- **Editing a rule's URL pattern no longer orphans the old pattern.** [125] The `upsert` verb (`Rules_CI_Node`) matched an incoming rule by pattern, so changing an existing rule's pattern appended a fresh-id rule for the new pattern and left the old-pattern rule behind. It now matches by `id` when the incoming rule carries one (an edit → replace in place), falling back to pattern-match only for the id-less Add / "Log this URL" flow.
- **Request Log & Gyroscope deep-link nodes/ELN URLs correctly; "Log this URL" doesn't double the `?`.** [124] The JS `urlHash` (Request Log + Gyroscope) stripped the URL at `?`, but PHP `Request_Builder_Node::url_hash` hashes the FULL string — the real query is stripped upstream, so the only `?` left is the intentional `?worker_type` marker on nodes/ELN URLs (e.g. `/jobs/newspack-nodes?supervisor`). Dropping it produced a hash that didn't match the URL's row, breaking the deep-link into URL details; both `urlHash`s now hash the full URL to match PHP. Separately, the "Log this URL" affordance (and its already-logged check) appended the exact-match sentinel `?` unconditionally, doubling it on a URL that already carries one — it now only appends when the URL has no `?`.

### Security

- **Direct-access guard on the first-party PHP files that lacked it.** Added `\defined( 'ABSPATH' ) || exit;` so no plugin PHP file runs on a direct web hit. (`uninstall.php` keeps its stricter `WP_UNINSTALL_PLUGIN` guard.)

### Changed

- **Dashboard toolbars adopt the shared canonical control classes** (from newspack-nodes `_inputs.scss`): the request-stream, error-log, and gyroscope toolbars drop their bespoke `event-logger-*-{search,controls,select,btn}` selectors for the shared `.newspack-nodes-toolbar` / `.newspack-nodes-search-input` / `.button` / `.newspack-nodes-select` / `.newspack-nodes-column-picker` / `.newspack-nodes-toolbar-stats` set, so buttons render the native WordPress/Newspack `.button` look (the shared `wp-reskin` no longer overrides `.button`, only adds the `.is-active`/`.is-paused` toolbar states) and every toolbar of the same kind shares one class. Orphaned `event-logger-refresh-select` rules removed. The byte-for-byte `src/styles/_dashboard-mixins.scss` duplicate of the substrate mixins is deleted — the dashboards now `@forward` the shared `@newspack-nodes/shared/styles/mixins` directly (the retired toolbar mixins dropped from the forward list).

### Added

- **Per-URL logging ruleset** ([54]) — replaces the four global logging settings (`log_urls` / `skip_urls` / `log_events` / `custom_events`) and the three global auto-tune settings with a per-URL **ruleset**: an ordered list of rules, each a URL pattern (prefix `/x` or exact `/x?`) with a `log`/`skip` action and — for `log` rules — its own hooks, custom events, significant events, and auto-tune thresholds. Matching is **longest-prefix wins** (an exact `/x?` beats an equal-length prefix) and **case-insensitive** (preserving the old `log_urls`/`skip_urls` behavior); no rule matches ⇒ skip. A rule's hooks ride inline in the autoloaded rule list when small, or — past `Rule_Set::INLINE_HOOK_LIMIT` — behind a per-rule non-autoloaded durable option mirrored into memcache (warmed on miss), so the standard hook set never pays a memcache hop and heavy rules don't bloat autoload. New classes: `Rule`, `Rule_Matcher`, `Rule_Set`.
  - **Per-request hot-path win:** `Log_Manager` resolves the one governing rule per request; a `skip` rule or no match binds **zero** hooks and touches no memcache, and `App\Core` binds only the governing rule's hooks — a "cheap" URL is finally cheap (a `log`-with-no-hooks rule is a pure access-log line: process start / URL / complete).
  - **Per-rule auto-tune:** the governing rule id is stamped into the process-start firehose frame; the worker's `Flame_Builder_Node` applies **that rule's** thresholds and `Auto_Tuner_Node` writes disabled hooks / promoted significant events back into **that rule**, not the retired global options.
  - **Job scope:** a JobWorker's `/jobs/{handler}` scope re-resolves its own rule and rebinds hooks (via a `newspack_event_logger_nodes_scope_changed` action), restoring the parent scope on exit.
  - **Migration:** a one-time, idempotent, behavior-preserving migration synthesizes an equivalent rule set from existing config (skip patterns → skip rules; empty `log_urls` → a `/` log rule carrying the global bundle; non-empty `log_urls` → one log rule per pattern with no `/` baseline), deletes the old option rows, and raises an admin notice when a skip/log prefix overlap means the new most-specific-wins model changed behavior for some URLs.
- **Rules editor (admin UI)** for the [54] ruleset — a "Logging Rules" section on the Event Logger settings page: a table of rules with **add / edit / delete**, each rule editing its URL pattern, log/skip action, hooks (reusing the hook picker), custom events, significant events, and auto-tune thresholds. Backed by a new `Rules_CI_Node` service CI (`list` / `save` / `upsert` / `delete` at `_http/rules`) that persists through `Rule_Set::save()` so the inline↔pointer hook tiering and orphan reconcile are always honored.
- **"Log this URL" from the performance dashboard** — the URL-details modal gains a button that adds (or edits) an **exact-match** logging rule (`<url>?`) for that URL without leaving the modal, via the same `upsert` path.
- **`wp nodes ruleset-bench`** — Phase-0 measurement command: sweeps hook-count × rule-count and reports the median/p95 cost of autoloaded-inline vs memcache-pointer hook storage, used to fix `INLINE_HOOK_LIMIT` empirically. Dev tooling; off the request hot path.

### Removed

- **The seven global logging settings** `log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`, `auto_disable_threshold`, `auto_protect_time_threshold` ([54]) — absorbed into per-rule fields on the ruleset, edited through the new rules editor; `enable_logging`, `log_memory`, `flush_every_line`, `allowed_users`, `hook_start_priority` stay global. Discovery / `Hook_Categorizer` now advertise the union of hooks/custom-events across all log rules.

### Changed

- **Rule-editor / picker modal frames follow the substrate elevation-token refactor.** The rule-edit, hook-selector, and custom-event modal frames move from `--paper` to `--paper-2` (panel role), so their inputs — now the shared `--paper` well — read as lighter fields that pop off the frame. Pairs with the newspack-nodes `--paper` ramp reconciliation + new `--canvas` token (inlined via the shared `@newspack-nodes/*` build aliases; rebuild required).
- **Dashboard surfaces follow the elevation model.** The dashboard wrap (`.topology-app > .newspack-nodes-theme`) + the WP-admin `<body>` (via `ThemedRoot`) and every page-root (requests / gyroscope / error-log) move to the `--paper-3` base; the URL-detail modal frame + the log-entries table to `--paper-2`; the Find / Errors-Only buttons to the `--paper` well; the settings-page rule modal gains a width cap so a long tag row can't stretch it edge-to-edge.
- **Dashboards read skin CSS vars directly; settings page is now skin-aware.** The pre-skin `$dark-*` / `$light-*` / `$term-*` SCSS alias layer and the `_dashboard-mixins` config-param (`@use ... with (…)`) indirection were deleted — component rules read `var(--paper*/--ink*/--cyan*/--hover)` directly. The settings page (and the shared refresh-select / headers it feeds Gyroscope / Request-Stream / Worker-Status) de-darkens from hardcoded dark hexes to skin-aware chrome; the modal / `@wordpress` buttons pull the shared `wp-reskin` mixin from the substrate.

### Fixed

- **Settings de-darkening follow-ups.** White-on-dark text stranded on the now-light surfaces is restored to readable `var(--ink)`; HTTP status inks go back to full strength (the `color-mix`-toward-white variants tuned for the retired dark pane were illegible on light chrome); the dead `dark-button` / `dark-column-picker` local mixins were removed; and the refresh-select / toolbar-button hover restings move off `--paper-3` to `--paper-2` so the `--hover` step is visible in light skins.

## [0.25.1] - 2026-07-04

### Fixed

- **Stats mirror never flushed on the `combined` topology.** The durable stats mirror (0.25.0) drains its in-memory buffer to the partition once per `Flame_Builder_Node::save_state()`, which only runs when the feeding `Consumer` names it as its `snapshot_node`. `combined.tsl` wired `firehose:consumer`'s snapshot but was missing `cmd requests:consumer:config set_snapshot_node flame-builder` — so on the topology most deployments run, `save_state()` never fired: the mirror never flushed (`flame-stats:partition` stayed empty) and flame-builder's in-flight leaderboard `pending` state never resumed on respawn. Added the wire (`flame-builder` / `performance` already had it).
- **`set_stats_partition` late-binds by node name.** It stored the resolved `Partition_Node` object and failed — silently, at boot with `want_reply=false` — when the config verb ran before the partition's `make_node`, the ordering a console-serialized topology override produces; that disabled the mirror. It now stores the node *name* and resolves it via `Core::node()` lazily at flush/reload (like `set_snapshot_node`), so graph construction is order-independent (and cycle-safe). The 4KB PIPE_BUF cap-lift moved from the setter into each topology (`cmd flame-stats:partition:config void_warranty`, beside the partition's `make_node`). The mirror also re-arms on a `configure_stats` re-run — `set_stats_store()` arms it too, so store and partition can be configured in either order.

### Added

- **`TopologyShapeTest` structural guard suite** over the stock topologies: every `Consumer` snapshots the stateful node it feeds (and at most one); the stats-mirror partition carries `void_warranty`; `configure_stats` precedes `set_stats_partition`; `set_stats_partition` uses the config token; request-builder output targets are set; no directive references an undeclared node; and each snapshot target matches its consumer's offset-filename convention.
- **Flame_Builder exposes its stats-mirror partition as a console target**, so the `flame-builder → flame-stats:partition` edge renders on the topology console — the mirror writes bypass the sink and would otherwise leave the partition drawn as disconnected. Display only; it does not affect what gets mirrored.

## [0.25.0] - 2026-07-04

### Added

- **Durable stats mirror + cold-boot reload.** Stats live only in memcache; where memcache is volatile (a local/docker box, or an Atomic server reboot) a restart loses every aggregate. `Stats_Store` gains a `mirror` closure seam (each `set_*` routes through a private `store()` that shadows the successful write, tagged with its namespace) plus a `restore()` that replays a value into memcache under a decayed TTL (guarded on `ttl > 0` and the current salt prefix). `Flame_Builder_Node::set_stats_partition()` (a `set_stats_partition <node>` config verb, round-tripped by `dump_config`) wires the mirror to a durable `Partition_Node` (lifting its 4KB cap in-method) and, on cold boot (memcache hourly empty), replays every committed frame oldest→newest with age-decayed TTLs (a torn frame is skipped, not fatal). **Snapshot-per-checkpoint, not per-write:** mirrored writes buffer in memory and flush to the partition once per `save_state()` — the requests-Consumer's own checkpoint — so recovery counts each request once: the un-committed buffer since the last checkpoint dies with the crash (never written) and the Consumer reprocesses that window on resume — only a sub-millisecond crash between the flush and the offset commit can re-count that last window. **Per-URL bounded so the mirror stays small:** the seven aggregate namespaces mirror in full (small on a single site — the per-server keyspace is unbounded only on a many-spoke hub, which runs durable memcache and keeps the seam off); the three per-URL namespaces keep a bounded top-N by traffic — top 100 `url_dim` / top 100 `url_cat`, and top 10 `url` flame profiles admitted only among requests that carry profiling detail (a `has_profiling_detail()` seam, forward-compatible with selective per-URL profiling: an un-profiled hot URL falls out of the flame top-10 automatically). **Off by default** — the seam stays null (zero overhead); a deployment opts in by setting the `stats_mirror_node` config to the mirror partition's name, which each Flame_Builder topology (`combined` / `performance` / `flame-builder`) wires as a durable `flame-stats:partition` and passes via the `<eln:stats_mirror_node>` token (empty = disabled).

## [0.24.0] - 2026-07-04

### Changed

- **`wp nodes reqgrep` consumes Messages through `Consumer_Node::drain()`** instead of hand-rolling `read_at` + `json_decode` + segment/follow bookkeeping. cat/recent modes drain synchronously; follow rides the event loop; stdin unpacks each line via `Message::unpacked`. Drops the dual-shape "legacy entry-hash" stdin path (there is no legacy entry-hash format) and the private `read_at`/`stream_segment_lines`/`follow_tick`/`seed_follow_cursors`/`get_partition` machinery — a non-envelope stdin line is now skipped rather than decoded.
- **Index-formatter cutover to the Message array.** `Flame_Builder_Node` / `Request_Builder_Node` `format_index_entry()` and `Performance_CI_Node`'s four `with_index` closures now receive the unpacked Message + `{segment, offset, length}` position (the substrate's new `Partition_Node` formatter contract) and read `Message::VALUE` directly — no `json_decode`, and the flame path no longer needs its own decode-depth constant. Requires newspack-nodes with `read_message_at` + the new formatter contract.

### Fixed

- **Index-write path re-synced to the substrate's `segment` position key.** `Flame_Builder_Node` / `Request_Builder_Node` `format_index_entry()` and `Performance_CI_Node`'s index readers read `$position['segment_id']`, but newspack-nodes' `Partition_Node` now passes `['segment' => …]` — so the live hub/spoke index write was reading a missing key (ELN's tests passed only because they fed their own `segment_id` fixtures). Renamed to `segment`, matching the substrate's whole-word position keys. The `useRequestLogGraph` / `useGyroscopeGraph` / `useErrorLogGraph` hook fixtures also move to the 3-part `segment:offset:length` breadcrumb (they had carried stale 2-part IDs since the substrate's breadcrumb change).

## [0.23.0] - 2026-07-02

### Fixed

- **Firehose reader opts into the substrate seal-grace.** The `firehose:consumer` in the `combined`, `request-builder`, `performance`, and `job-router` topologies now sets `set_multi_writer true`, closing the segment-rotation race that orphaned a request's terminal `process (complete)` line — which left the request languishing in the in-flight LRU until it aged out (~10 min) as a timed-out partial. Requires newspack-nodes with `Consumer_Node` seal-grace. Only the firehose is multi-writer; the `requests`/`jobintake` consumers read single-writer logs and are unchanged.

### Added

- **Request_Builder sequence validation** (ported from Tachikoma `InstrumentalityGrail`): per-request `n`-sequence checks surface orphaned mid-stream lines (`WARNING: missing message`), re-delivery/reorder (`INFO: duplicate message`), and request-id reuse (`WARNING: multiple requests with ID`) instead of silently corrupting assembly — the correctness guard complementing the seal-grace fix. Out-of-sequence lines are skipped (rate-limited). Nested-subprocess sequences are handled: nuclear-gyrobase shells out to the Perl engine (`proc_open`), whose child emits its own `n`-sequence (restarting at 1) inside the parent request's stream under the same rid — `gyrobase (start)` stashes the parent's expected `n` and `gyrobase (complete)` restores it (a stack, so sequential/nested renders both work), so neither the nested restart nor the parent's resume reads as a gap/dup.
- **Timed-out-trace log signal.** `evict_request` now emits one rate-limited `WARNING: trace timed out on {rid} ({url})` when an in-flight request ages out of the LRU without completing — a log-level complement to the performance dashboard's "show errors" for the trailing-loss case the sequence check can't see.

## [0.22.5] - 2026-07-02

### Added

- **Uninstall cleanup.** Deleting the plugin now removes every `newspack_event_logger_nodes_` option row it created (settings + runtime state) and their transient variants, via a prefix-based `uninstall.php`. It runs only on delete (`WP_UNINSTALL_PLUGIN`), never on deactivate, so a deactivate/reactivate keeps all settings; previously these options were orphaned in the database on uninstall. Prefix-based so it stays complete as options come and go and catches `autoload=off` rows a hardcoded list would miss.

## [0.22.4] - 2026-06-30

### Changed

- **Removed the dead `lastEventTime` stamping from the three stream view nodes.** Since staleness is now read off the RemoteLink's `lastEventTime()`, the `RequestLogView` / `PerfErrorsView` / `GyroscopeView` nodes no longer stamp (nor, for `PerfErrorsView`, publish) their own `lastEventTime` — it had no reader. No behavior change; the "Xs ago" indicator is sourced entirely from the link.
- **The Request Log / Error Log / Gyroscope dashboards now share the substrate's new `useVisibilityGatedLink` hook** instead of each hand-rolling the mount + visibility-gated SSE-connection lifecycle. Behavior is unchanged (same subscribe, view, pause, and resume-on-refocus); the ~15-line resume guard that had been copy-pasted into all three now lives once in `newspack-nodes/src/shared/hooks`, consumed via the `@newspack-nodes/shared` alias.

### Fixed

- **The Request Log / Error Log / Gyroscope "Xs ago" stream-staleness indicator works again.** All three drove staleness off `Core.node('_sse').lastEventTime`, but `_sse` is not a registered browser node (the substrate's `RemoteLink` composes its `SseIn` unnamed), so the lookup was always `undefined` and the indicator was silently dead. They now read the substrate's new `RemoteLink.lastEventTime()` passthrough off the link's own name (`requestlog:link` / `perferrors:link` / `gyroscope:link`). Requires newspack-nodes with that passthrough.
- **SSE dashboards (Request Log, Error Log, Gyroscope) now resume from their last offset when the tab regains visibility, instead of restarting from the live end.** Each dashboard's page-visibility effect closed the SSE stream while the tab was hidden and reconnected with a bare `link.connect()` on refocus — no position seed — so the substrate tail-seeked and everything that streamed while hidden was dropped. They now mirror the substrate's own Raw Logs / Topic Probe dashboards: the FIRST connect of a link live-follows, but a RECONNECT of the same link (hide→show, or unpause) passes `link.resumePositions()` so the hidden gap streams in. A `connectedLinkRef` guard keeps a redundant re-render from tearing a live seek down into a tail reconnect (and, for Gyroscope, from re-clearing the in-flight map mid-stream).

## [0.22.3] - 2026-06-29

### Changed

- **Rebuilt against newspack-nodes 0.24.1**, refreshing the inlined `@newspack-nodes/runtime` + `debug-overlay`: the bundled debug-overlay/console no-node stats header now reads wire-accurate IoTelemetry for browser graphs and no longer spikes its rate sparklines to the cumulative total on a fresh load / shift-reload / worker-switch; `dump_config` takes an optional regex-glob name filter; and the `HttpOut` bytesRead / `RemoteLink` write-byte tallies are corrected.

## [0.22.2] - 2026-06-28

### Fixed

- **Performance dashboard: detail modals no longer poll their hidden parent view in the background.** Opening a URL-detail modal left `perf:timer` polling the offscreen overview/urls every interval, and the url_detail auto-refresh was a raw `setInterval` (no Timer/Fetcher node) that kept firing once a request-detail modal was opened over it. Now `usePerformanceGraph` suspends `perf:timer` (via `useBatchedPoll`'s `paused`) whenever any detail modal is open, and the url_detail auto-refresh is a real node-graph `urldetail:timer` (Timer) → `fetch-urldetail` (Fetcher, live-hash `argsFn`) armed only while the URL detail is the visible view — a URL is selected, no request detail is drilled in, and the tab is visible. Opening request detail or hiding the tab stops it; backing out / returning to visible re-arms it. Closing the last modal immediately re-fetches the now-visible overview/urls (the paused poll would otherwise leave it stale for up to one interval).

## [0.22.1] - 2026-06-28

### Fixed

- **Hook Catalog modal: reuse the substrate's backbone `_http` instead of minting a second one.** newspack-nodes 0.22.1 makes `_http`/`_heartbeat` permanent backbone fixtures of `mountExospine`, so `useHookCatalogGraph`'s `makeNode( 'HttpOut', '_http' )` now collides on the reserved name (`node name collision: _http already registered`) and crashed the modal once rebuilt against the new substrate. It now reuses the backbone `_http` (like the other dashboards). Reinit tests aligned: the backbone `_http` is preserved across Reset Graph, not rebuilt.

## [0.22.0] - 2026-06-27

### Fixed

- **Aligned the SSE dashboard tests with the substrate's reworked `connected` handshake.** The Error Log / Gyroscope / Request Log slot keep-alive bridge tests built the old object envelope (`VALUE = { pid, slot, partition }`); the substrate's SSE rework made the handshake a flat `KEY VALUE` string (`PID n SLOT n …`) and removed `partition` end-to-end. The fixtures now emit the flat string and assert slot only — production was already correct (it delegates the bridge to the substrate `RemoteLink`). Requires newspack-nodes ≥ 0.22.0.

- **The overlay console's primary modal buttons (e.g. ADD TEE / SEND BYTES) are no longer invisible.** Same inline-overlay bleed: the reskin's `button { color: inherit }` (specificity 0,2,1) reached the overlay and out-specified the substrate's dark primary modal button (`.topology-modal__btn--primary`, light `--paper` text on an `--ink` fill, 0,1,0), forcing the text to `--ink` → dark-on-dark. The button-colour rule now excludes the overlay subtree (`button:not(.nodes-debug *)`), like the form controls, so the substrate's own light button text shows.

- **The overlay console REPL input is no longer white-on-white under light skins.** The standalone-dashboard form-control reskin (`ThemedRoot.scss`) painted every `input[type="text"]` with a light `--paper-3` background, and the substrate debug overlay renders INLINE inside `ThemedRoot` (not portaled), so the rule reached the console's REPL input (a `type="text"` descendant) and overpainted its transparent background while `-webkit-text-fill-color: var(--repl-fg)` kept the text light. The reskin now excludes the overlay subtree (`input[type="text"]:not(.nodes-debug *)`, etc.), so the REPL input keeps its transparent background over the dark bar. Dashboard/modal inputs are unaffected.

### Changed

- **The Error Log is now its own dashboard bundle (`src/error-log/`), split out of `overview`.** It used to ride along as a second React root inside `overview/index.js` (mounted on `#event-logger-errors` next to the perf dashboard on `#event-logger-admin`), which is why `overview/styles/base.scss` had to scope both roots — but the error log is structurally the request log for errors, not part of the performance overview. `ErrorLog.js`, its `useErrorLogGraph` hook, the `PerfErrorsView` view-node, `error-log.scss`, and their tests move to `src/error-log/`, with their own `index.js` (mounts `#event-logger-errors`) and `nodes/register.js` (registers only `PerfErrorsView`). `scripts/build.mjs` gains an `error-log` entry and the admin page→bundle map routes `event-logger-errors` to `build/error-log`, so the error log ships its own slimmer bundle instead of the full overview tree.

- **Shared dashboard SCSS lifted to `src/styles/base.scss` + `src/styles/_tokens.scss` (DRY).** The overview / error-log / gyroscope / request-log `base.scss` files were near-verbatim copies — the 17-token `@forward "dashboard-mixins" … with(…)` preamble plus the `.event-logger-*` / `.entry-*` component rules. They now `@forward` + `@use` one shared `base.scss` (carrying `scoped-reset($root)` + `stats-grid` mixins) and add only their scoped reset + the few rules that actually differ (overview's space-between flex stats row + padded admin-wrap). The byte-identical per-dashboard `_tokens.scss` is lifted to one `src/styles/_tokens.scss`; `settings` keeps its bespoke base (divergent tokens + local button/column-picker). Compiled CSS is unchanged save one harmless extra selector (`.event-logger-error-log-header` joins the shared header union). The shared `LoadingFallback` component is lifted to `src/components/` too.

## [0.21.0] - 2026-06-27

### Changed

- **The overview / gyroscope / requests dashboards are now genuinely theme-responsive on PURE skin tokens (supersedes the `var(--universal, var(--np-*))` chain entry below for those three).** A `ThemedRoot` `display:contents` provider wraps each standalone dashboard root, reads the console-selected skin once (`@newspack-nodes/shared/theme`) and carries `.topology-app.theme-<slug>`, so the universal tokens (`--paper`/`--ink`/`--cyan`/`--chart-*`/`--font-mono`) are always in scope — each `_tokens.scss` maps straight onto the bare tokens (no `--np-*` fallback, no `color-mix`, no light/dark/`term` split). ThemedRoot also drops its `fontFamily` so the skin's `--font-mono` cascades (terminal under decorative skins, Newspack sans by default), and paints `document.body` with the resolved `--paper` so the WP-admin gutters (left/right/footer) don't leak the light body background. The detail Modal (portaled to `<body>`) gets the skin classes + a flex-layout reset (the `.topology-app` grid was squishing it); `@wordpress/components` Card/InputControl, section headings (incl. the `h1` modal title), sortable column-header buttons, zebra striping + row/button hover, and the disabled Find button are all pulled onto the surface system. The reskin block is scoped to the dashboard root + modal frame, NOT the bare `.newspack-nodes-theme` — which the substrate debug-overlay console also carries — so the dashboard CSS never paints the console's own REPL / settings-panel inputs unreadable. `settings` stays deliberately Newspack-fixed (still chains `--np-*`).

- **gyroscope / requests / settings dashboard tokens now chain `var(--universal, var(--np-*))`, matching overview.** Each chrome token leads with the universal theme token and falls back to its `--np-*` equivalent — so the dashboards render the same Newspack look standalone (where only `--np-*` are defined) and are ready to reskin wherever a `.theme-<slug>` context is present. The intentionally-dark `$term-*` log panes are unchanged; settings keeps its dark `$dark-*` block and floors `$term-accent` to `var(--cyan, #003da5)` to match the others. (overview/gyroscope/requests `_tokens.scss` are now byte-identical — a DRY-share follow-up.)

- **Renamed the four dashboard trees + their admin page slugs, dropping the legacy `performance-` monorepo prefix.** The `src/`/`build/` dirs `performance-dashboards` / `performance-gyroscope` / `performance-request-log` / `performance-logger` → `overview` / `gyroscope` / `requests` / `settings`, and the admin page slugs rebranded from the substrate-style `newspack-nodes-*` to ELN's own `event-logger-*`: `newspack-nodes-performance` → `event-logger-overview`, `newspack-nodes-errors` → `event-logger-errors`, `newspack-nodes-gyroscope` → `event-logger-gyroscope`, `newspack-nodes-stream` → `event-logger-requests`. **The dashboard admin URLs change accordingly** (e.g. `admin.php?page=event-logger-overview`). The Settings page slug (`newspack-event-logger-nodes`) is unchanged — only its tree/build dir → `settings`. Internal div mount IDs (`event-logger-errors` / `-gyroscope` / `-stream`) are unchanged. Pure rename, behavior identical: 922 PHP + 547 jest green, zero straggler references. (The earlier `[Unreleased]` entries below predate this rename and still name the old dirs; this entry is the old→new map.)

- **The four performance-dashboard `base.scss` files no longer each re-declare the shared SCSS mixins (restructure C1, ELN side — pure dedup).** `performance-dashboards`, `performance-gyroscope`, `performance-request-log`, and `performance-logger` previously inlined the same ~14 dark-theme/table mixins (`dark-button`, `dark-search-input`, `table-row`, `widefat-themed`, …) verbatim, four copies drifting in lockstep. The mixin BODIES now live once in `src/styles/_dashboard-mixins.scss`; each dashboard's `base.scss` extracts its token block into a co-located `_tokens.scss` and `@forward`s the shared mixins configured (`@forward … show … with (…)`) with THAT dashboard's own token values. Because the COLOR tokens still diverge per dashboard (the deferred `--np-*`-vs-universal theme decision — universal `--paper/--ink` on `performance-dashboards`, `--np-*` on the others, hardcoded `$term-*` dark hex on `performance-logger`), those values stay local and ride in as `@use … with` config — that config block is the themify residue made explicit, and collapses once the token-vocabulary decision lands. `performance-logger` keeps its OWN `dark-button`/`dark-column-picker` (the white-on-dark hover/active variant whose bodies genuinely diverge), excluded from the forward. No token VALUE, class name, or rule changed: every dashboard's compiled CSS (LTR + RTL) is byte-identical to before — verified by a per-dashboard build diff. Net ~365 fewer lines of SCSS, and the shared mixins are maintained once instead of four times.
- **The Performance Dashboard is rebuilt as a GENUINE per-slice node graph on the substrate batched-poll toolkit (dashboard-restructure D1b, client half).** The single `performance:command` god command-builder + the 4-slice `performance:view` god view (both deleted) are replaced by independent per-slice graph paths. `usePerformanceGraph` now mounts on `useBatchedPoll` + `addSliceFetcher`: the `overview` and `urls` slices are POLLED on the owned Timer/Tee, each Fetcher given an `argsFn` fire-time getter that reads the CURRENT React UI state (serverFilter / chartBreakdown / sort / order / search / offset) so each batched tick emits live args without re-wiring — a serverFilter/breakdown change also fires an immediate poke. `url_detail` and `request_detail` are ON-DEMAND (modal-open → fetch), NOT on the Timer; the `url_detail` reply rides through `UrlDetailMergeNode` (incremental merge + last_modified/500-cap dedup) on the receiver→view graph EDGE instead of inside the view. `resolveRequest` (request_search navigation) and `fetchUrlBreakdown` (url_detail breakdown) are awaited Promises settled via the relevant view's `PendingReplies`. Each slice is a focused `DecodedSliceViewNode` subclass (`OverviewView` / `UrlsView` / `UrlDetailView` / `RequestDetailView`) publishing its own `{ data, loading, error }` for one `useNodeState`; `PerformanceDashboard` reads the four slices independently (no god view). Behavior-preserving — the dashboard renders the same data with the same filters/sort, and all UI state stays React-local. Requires `newspack-nodes` ≥ the `Fetcher` fire-time-getter / `addSliceFetcher` `argsFn` affordance.
- **`Performance_CI_Node`'s index-reading verbs now share one memoized URL-index read per request (dashboard-restructure D1, server half).** The four verbs that each derive from the merged URL index (`overview`, `urls`, `url_detail`, `dashboard`) previously called `load_index()` independently, re-fanning the per-partition memcache `get_multi` once per slice — and `dashboard` (overview + urls in one round-trip) read it twice. They now resolve a per-request memoized `index()` (the former `load_index()` is `load_index_default()`, reachable through a lazily-defaulted `Performance_CI_Node::$load_index` read seam), so a single dispatch fans out at most once. Every verb's JSON output shape is byte-identical — behavior-preserving; all existing `PerformanceCITest` assertions pass unchanged. The verbs intentionally keep returning live arrays rather than adopting `Service_CI_Node::slice_verb()` (whose JSON-string return would change the wire payload and break both the PHP contract tests and the current client). The client-side de-god (slice views + `useBatchedPoll`) is a separate deliverable.
- **The performance-dashboards now read the substrate's universal theme tokens, so the whole dashboard family re-skins under every hub theme.** The Performance Dashboard chrome (Time Breakdown table, request-detail card, flame container, modal, charts) and the Error Log toolbar previously consumed the fixed Newspack-only `--np-*` tokens, which never re-skin — so the headers/tabs went CRT/dark while the body stayed Newspack-light. The `$dark-*`/`$light-*`/status/semantic SCSS token layer in `performance-dashboards/styles/base.scss` + `tables.scss` (and the four inline `--np-primary` color uses in `LogEntriesTable.js`) now map onto the universal tokens (`--np-surface*`→`--paper*`, `--np-text*`→`--ink*`, `--np-border*`→`--paper-shadow`/`--ink-3`, `--np-primary*`→`--cyan*`, `--np-success*`→`--sage*`, `--np-error*`→`--oxide*`, `--np-warning*`→`--brass*`), matching the `event-dashboards`/`event-aggregator`/`debug-overlay` migrations in newspack-nodes. The intentionally-dark `$term-*` log/terminal surfaces are unchanged (the scrolling Error Log pane stays dark, like the console REPL), as are the structural `--np-radius-*` / `--np-font` tokens.
- **The settings page uses stock WP buttons.** Dropped the custom `.submit .button-primary` re-skin (custom radius, hover-lift transform, box-shadow) from the Event Aggregator and Performance Logger `settings.scss`, so the standalone `add_options_page` settings form's submit button renders as a plain WP core-ui primary button.
- **The Time Breakdown and Log Entries `widefat` tables now re-skin under the hub's decorative themes.** WP-core's `.widefat .striped` hardcodes a light surface that ignores the universal theme tokens, so those tables stayed Newspack-light while their surrounding chrome went CRT/dark. `RequestProfile.js` now co-locates a `request-profile.scss` (imported by the component so it rides into every bundle — including the hub current-request tab, whose bundle imports only `current-request.scss`) that re-skins the table's color properties via a shared `widefat-themed` SCSS mixin (`--paper`/`--ink`/`--paper-shadow`), and `tables.scss` applies the same mixin to `.event-logger-log-entries`. The six inline-styled surfaces in `RequestProfile.js` (summary bar, expand caret, callback sub-row, "and N more" cell, "Show N more" link, Total Profiled footer) and the four inline `var(--cyan)` accents in `LogEntriesTable.js` now use the `var(--token, <light-fallback>)` form. Every token carries the WP-core/Newspack-light fallback, so the STANDALONE Performance Dashboard — where the universal tokens are undefined — is pixel-identical to before.

### Removed

- **Deleted the vestigial `event-aggregator` settings stylesheet bundle.** `src/event-aggregator/` (`settings.js` + `styles/settings.scss` + `styles/base.scss`) and its `build/event-aggregator-settings` output were a stale, truncated SUBSET of `src/performance-logger/styles/settings.scss` — which `performance-logger/index.js` already imports into its own bundle — redundantly enqueued on the settings page on top of performance-logger's own superset. Removed the dir, its `scripts/build.mjs` entry, and the `if ( 'performance-logger' === $tree )` block that enqueued `build/event-aggregator-settings/settings.css`; the settings page keeps its full styling from `performance-logger`'s own `settings.scss`. This reverses the v0.20.0 "the aggregator-settings page… all stay" note — the bundle was redundant, not load-bearing. Also dropped the dead `aggregatorRestUrl` localize + its `EnqueueDashboardsTest` assertion (the retired `newspack-nodes-aggregator/v1` namespace has no JS consumer in ELN or `newspack-nodes`).

- **Pruned 8 dead verbs from the `Performance_CI` service node** (`timing`, `dashboard`, `hooks_categories`, `hooks_available`, `hooks_configure`, `config_get`, `request_log_list`, `request_log_detail`) — none had any caller across the four sibling plugins or any topology after the performance-dashboard de-god. The live surfaces drive only `overview` / `urls` / `url_detail` / `request_search` / `request_detail` (perf dashboard + current-request tab), `hooks_registered` (hook-catalog), and `set` (the `Settings_Sync` hub→spoke receiver). Also removed the three private helpers and two consts that only those verbs used.
- **The unused `newspack_event_logger_nodes_reset_options` filter.** It let plugins extend the reset-to-defaults option list, but had no consumers in any plugin (YAGNI). `handle_reset_settings()` now resets exactly the schema's `setting_option_names()`. Also drops a now-redundant `is_string()` guard (the list is already `array<int,string>`).

### Added

- **The overview, gyroscope, and requests dashboards now reskin with the console-selected theme.** A new `ThemedRoot` (`src/components/ThemedRoot.js`) wraps each dashboard root in a `display:contents` `.topology-app.theme-<slug>` token provider, reading the persisted skin via the shared `@newspack-nodes/shared/theme` `getStoredTheme`. The dashboards' `var(--universal, var(--np-*))` chains then pick up the skin's tokens and reskin (falling back to Newspack where no theme context is present). `display:contents` keeps the wrapper boxless so no console layout reaches the dashboard, and `fontFamily: inherit` neutralizes `.topology-app`'s monospace so the product dashboards keep their sans typography — only the tokens + color cascade through. They inherit whatever skin you select in the topology console (applied on reload; no per-page picker yet). The settings page (PHP-rendered form) and the current-request overlay tab (already themed by the overlay) are intentionally unchanged. Requires the `newspack-nodes` `@newspack-nodes/shared/theme` helper move.

- **The Gyroscope, Request Log, and Error Log SSE dashboards now route their stream through a named pass-through `Tee`** (`gyroscope:stream`, `requestlog:stream`, `perferrors:stream`) on the edge between the stream source and its view, so the live stream can be watched via `connect <tee>` in the debug overlay without disturbing the dashboard. The Tee has a single target (the view), so the view receives exactly what it did before.
- **Per-slice Performance Dashboard view nodes + a url_detail merge transform node (dashboard-restructure D1b building blocks).** Three net-new, independently-tested graph nodes that begin de-godding the Performance Dashboard's 4-slice `performance:view`: `OverviewViewNode` / `UrlsViewNode` (focused custom slice views over a small `DecodedSliceViewNode` base — the D4 "decoded-object" pattern, NOT the substrate's JSON-string `SliceViewNode`, because the D1 server half keeps live-array verb returns), each owning ONE `{ data, loading, error }` slice published via `setState('view', …)`; and `UrlDetailMergeNode`, the `addSliceFetcher` `transform`-slot node that hosts the `url_detail` incremental-merge + `last_modified`/500-cap dedup (lifted verbatim from the old view's `_mergeUrlDetail`) on the receiver-Tee→view graph edge instead of in view state. All three are registered for `make_node` and fully unit-tested; they are not yet wired into `usePerformanceGraph` — the `useBatchedPoll`/`addSliceFetcher` rewire (incl. the dynamic-Fetcher-args + selection-gated on-demand fetches + awaited-Promise paths) and the orchestrator widget split are the follow-up integration deliverable. Behavior-preserving: the existing 342 performance-dashboard jest tests are untouched and green.
- **Read-only "Effective Configuration" panel on the Event Logger settings page.** Below the settings form, a `widefat` table now reports, per application setting: the stored option value (or "— (file default)" when unset), the value the next worker will load (overlay-resolved `Config::load_config()`), any active overlay override, and the live restart impact — e.g. `significant_events` (classified `all`) shows "Restarts: <every active topology>" (or "(no active consumer)" when none are active). It delegates to the shared substrate `\Newspack_Nodes\Config_System\Settings_Renderer::render_effective_config_section()` (passing ELN's own `Settings_Schema`, option prefix, and `Config::load_config()`), so the panel is the same one implementation `newspack-nodes` uses — no duplicated logic to drift. Hooked to ELN's existing `newspack_event_logger_nodes/settings_after_form` action.

### Changed

- **The flame graph re-skins per hub theme with a depth-shaded palette.** Frames were colored by category via the shared `getStateColor` (a fixed Newspack palette dominated by flat `#9e9e9e` gray), which looked washed-out and out-of-place on the decorative hub themes (CRT etc.). `FlameGraph.js` now colors each frame a shade of the *active theme's* accent graduated by stack depth (read off the container: `--cyan`/`--np-primary` accent mixed toward `--paper`/`--np-surface`, capped so deep frames never collapse into the background), with per-frame label contrast so labels stay readable on every shade. The pure ramp/contrast helpers live in a co-located `flameColors.js`. This is flame-graph-only — the shared `getStateColor` (table category swatches, the Time-Breakdown bar, the event dashboards) is unchanged.

### Fixed

- **The standalone Error Log no longer renders on a gray background.** Its chrome lives in the `overview` tree, whose `$dark-*`/`$light-*`/status tokens mapped to bare universal `var(--paper)`/`var(--ink)`/`var(--cyan)` — undefined on a standalone ELN page (`.newspack-nodes-theme` defines only `--np-*`), so they resolved to unset and the chrome fell back to WP-admin gray. Each universal token in `overview/styles/_tokens.scss` now chains to its canonical `--np-*` equivalent (`var(--paper, var(--np-surface))`, …) — the exact palette the gyroscope/requests SSE pages render — so the Error Log matches the other SSE dashboards standalone while still reskinning under the hub's decorative themes (where the universal token wins). The dark `$term-*` log pane was already correct. Guarded by a `request-profile-theme-tokens` test asserting no fallback-less universal token remains.

- **The Current-Request overlay stylesheet cache-busts on its own content hash** (`md5_file(index.css)`) rather than the JS bundle version, mirroring the substrate `Admin::enqueue_react_page` fix — a SCSS-only rebuild no longer needs a hard-refresh to land.
- **The Request Log and Gyroscope request-id link is no longer invisible.** The cascade-winning `.entry-rid` is the shared substrate log-entry component (fixed in `newspack-nodes` `_components.scss`), which used an un-lightened `var(--cyan)` with no fallback; `--cyan` is undefined on standalone dashboard pages (only the topology-console theme defines it), so the link inherited the `#1e1e1e` log background. The substrate fix lightens + Cobalt-floors it; on the ELN side the terminal `$term-accent` tokens for `performance-request-log`, `performance-gyroscope`, and `performance-dashboards` / `current-request` are likewise floored (`var(--cyan, #003da5)`) so whichever `.entry-rid` rule wins the cascade renders a readable light-Cobalt on every theme. Requires the `newspack-nodes` shared `.entry-rid` fix.
- **The Performance Dashboard refresh-rate selector now actually changes the poll cadence.** It was inert; `usePerformanceGraph` now passes the selected interval to `useBatchedPoll` as `intervalMs`, so the poll Timer re-arms (hitchhike + throttle) on change. The immediate poke on a filter/breakdown change is preserved. (Requires the `newspack-nodes` Timer hitchhike-throttle + `useBatchedPoll` `intervalMs` affordance.)
- **`Log_Manager` defers wiring its Topic to the command interpreter when the CI isn't built yet at wire time.** It installs a `Callback_Node` sink that, on the first message, looks up the now-built `command_interpreter`, rewires the Topic's sink to it, and forwards the message — closing a load-order gap where a Topic wired before the CI existed had no interpreter sink.
- **The "Request" overlay tab now appears on every page that embeds the debug overlay, not just the hub + ELN's own pages.** The current-request tab is its own bundle, separately enqueued; previously `Current_Request_Overlay` only loaded it on the hub (via `devtools_tab_bundles`) and a hardcoded list of ELN's four pages, so a sibling plugin's overlay (e.g. the AI Newsletter's Publisher Insights page) showed only Overview + Console. `is_overlay_page()` now unions ELN's own `OVERLAY_PAGES` with the substrate's `\Newspack_Nodes\Admin\Admin::devtools_overlay_pages()` registry (the `newspack_nodes/devtools_overlay_pages` filter), so any plugin that declares its overlay page gets the Request tab.
- **The current-request overlay tab's headings are legible under dark hub themes.** WordPress-admin paints `h2`/`h3` a fixed `#1d2327`, which the reused dashboard pieces inherited — so the "Request: \<rid\>" title and the "Request Trace" / "Time Breakdown" section headings rendered dark-on-near-black (unreadable) under decorative hub themes like CRT. `current-request.scss` now sets the tab's `h2`/`h3` color to `var(--ink, #1d2327)`, so the headings follow the theme ink (CRT green) while the WP heading color stays the standalone fallback.
- **Application-setting saves now restart the right workers.** `Admin::maybe_request_worker_restart()` touched dead worker-group lock dirs (`request-workers`/`job-workers`.p{N}.lock.d) that match no live topology — so every settings save silently restarted nothing. It now classifies each setting by its **consumer node type** (`Settings_Schema` `restart:` keys) and resolves that to the live topologies whose graphs instantiate that node via the substrate `\Newspack_Nodes\Config_System\Restart_Planner`, touching each real topology's per-partition lock dir. Reclassified: `enable_logging` / `log_memory` / `flush_every_line` → `all` (cached in the per-process `Log_Manager` singleton every worker holds); `log_events` / `custom_events` / `significant_events` → `all` (`App\Core`, constructed in EVERY worker, binds the instrumented hook set from these three lists via `add_filter` at construction, so the set is frozen per-process and a change needs a worker restart — `App\Core` is not a Node and can't be targeted by node type); `auto_disable_threshold` / `auto_protect_time_threshold` → `Flame_Builder`; `stats_salt` (flush handler) → `Flame_Builder`; `log_urls` / `skip_urls` stay `[]` (read per-request in the web process).

## [0.20.0] - 2026-06-24

### Changed

- **`hub-control` now seeds each spoke's own `remote_*` settings so they propagate down a chain.** Each `remote_*` geometry setting (`remote_num_segments` / `remote_segment_size` / `remote_max_lifespan`) is added to `settings-sync` twice — once mapping to the spoke's stripped option (its actual config) and once to the spoke's own `remote_*` copy — so a spoke acting as a hub forwards the value onward to ITS spokes. Relies on the substrate's repeatable `add_setting`.

### Fixed

- **The flame graph re-fits when its container resizes (e.g. resizing the debug overlay).** It only listened for the browser `window` `resize`, so a panel resize left the chart stuck at its old width. It now observes its own container via a debounced `ResizeObserver` and re-fits once the resize settles.

### Added

- **Current-Request overlay tab** — a Debugbar/Telescope-style "Request" tab in the debug overlay that summarizes the page's own request (URL, duration, status, result, peak memory, timestamp), renders its **flame graph + profile breakdown** (reusing the performance dashboard's `FlameGraph` / `RequestProfile`), and deep-links to its full performance trace. The tab leads with a `Request: <rid>` heading and the request-detail info block (URL / Time / Duration / Memory / Status) with the trace button pinned top-right, carries the `newspack-nodes-theme` class so the reused profile/flame resolve the same `--np-*` tokens as the dashboard, and resets the overlay's monospace `--font-mono` typography to the admin sans so they read consistently. ELN owns it (it owns the request lifecycle): a `current-request` bundle registers an `overlay`-scope devtools tab via the substrate's `newspack_nodes/devtools_tab_bundles` filter, and `Current_Request_Overlay` injects the request id + partition into a distinct `window.NewspackEventLoggerNodes` global (not the shared, clobber-prone `NewspackNodesData`). The tab fetches the summary from the `performance` CI's `request_detail` verb by `{rid, partition}` (both from `Log_Manager`, which gains a `get_partition()` getter), with a "still processing" retry state for the request-builder's async lag. The bundle loads on the hub (via the filter) AND on the ELN performance pages that embed the overlay (performance / errors / gyroscope / stream), so the tab appears wherever the overlay mounts.

## [0.19.0] - 2026-06-23

### Added

- **`Remote_Job_Rewrite_Node`** — hub-side `k:"job"` → `k:"remote_job"` rewrite as a graph node, relocating the rewrite off the deleted `Stream_Merger`'s `newspack_nodes/aggregator_ingest_line` filter so the substrate `Remote_Source`/`SSE_In` stay application-agnostic. `fill()` flips the kind on the aggregated firehose entry's array `VALUE` in place (`($value['k'] ?? null) === 'job'`), guards the post-rewrite packed size against the `Partition_Node::MAX_LINE_SIZE` PIPE_BUF cap (oversize → drop + `Core::print_less_often`), and otherwise forwards via the base `Node::fill` (stamps `TO=target`). Non-`job` entries and non-array VALUEs pass through unchanged. `node_schema` category `Transform`, `has_target` true.

- **`Settings_Event_Writer`** — the producer end of the settings-sync node graph (Slice A1). On a watched WP-option change (`update_option`/`add_option` for any `newspack_*` option) it appends an option-NAME-only `TM_STRUCT` event (`VALUE = ['option' => $name]`, no value) to `settings.p0` via a transient `settings:writer` Partition torn down after each write (so two updates in one request don't collide in the Core registry). The event is name-only so it is always ≤PIPE_BUF → an atomic lockless append.
- **`App\Settings_CI_Node` `set <option> <value>` verb** (Slice A1) — normalized positional spoke-side receiver: one substrate setting per command, addressed by its full `newspack_nodes_*` option name (the bare short-name is also accepted), validated against the same whitelist/bounds as `update`, int-sanitized, written with `autoload=true`. The apply is `update_option` + `Config::reset()`; the write emits a settings event like any other option change. The existing `update` verb is unchanged (removed in a later slice once `set` is proven).
- **`App\Performance_CI_Node` `set <option> <value>` verb** (Slice A1) — the perf-settings sibling of the `Settings_CI` `set` verb: normalized positional spoke-side receiver for the nine perf-tuning options (`newspack_event_logger_nodes_*`), one setting per command, validated against the same `SETTINGS_OPTIONS` whitelist and array/int/float/bool sanitization as `settings_update`. The apply is `update_option` (with `Config::autoload_for`) + `Config::reset()`; the write emits a settings event like any other option change. The existing `settings_update` verb is unchanged (removed in a later slice once `set` is proven).
- **`Discovery_Collector_Node`** — hub-side periodic discovery node (Slice A1), replacing `Remote_Manager`'s poll-based discovery. A `Timer_Node` whose `fire()` fans one `discovery.get` `TM_COMMAND` to the connected Tee (`<target>/discovery`, broadcast to every spoke's `Discovery_CI`); `arguments( <seconds> )` arms the recurring timer (default 300s legacy cadence). Each spoke's reply self-routes back (TO=FROM) into `fill()`, which monotonically union-merges the reply's `registered_hooks`/`custom_events` into the hub's `log_events`/`discovered_events` options — porting `Health_Check_Extensions::process_discovery`/`merge_hooks`/`merge_events` verbatim (remote-string sanitization, `MAX_EVENTS=10000` cap, custom-events excluded from `log_events`, option-cache invalidation before the read-modify-write). The merge's `update_option` writes emit a settings event like any other option change. The merge folds each reply incrementally + idempotently (no barrier), so out-of-order/partial replies converge to the same union.
- **`hub-control.tsl` topology + bootstrap wiring** (Slice A1) — the single-instance control topology (`var num_partitions = 1`) that mounts the settings-sync + discovery pipeline: a `Consumer` tailing `settings.p0`, the substrate `Settings_Sync_Node` (armed with the 13 `add_setting` mappings — 4 substrate-remap → `settings` CI, 9 perf → `performance` CI, each addressing the spoke `set` verb by full option name), a shared `spokes:tee`, and `Discovery_Collector_Node`, both periodic nodes on a 300s tick. No per-spoke `HTTP_Out` nodes ship in the file — operators add `make_node HTTP_Out remote:<id>` + `connect_node spokes:tee remote:<id>` from the topology console; the pipeline is correctly inert until a spoke is wired. The ELN deferred bootstrap now also calls `Settings_Event_Writer::init()` and registers the `newspack_nodes/settings_sync/value` value-resolver filter (`newspack_event_logger_nodes_resolve_settings_sync_value`), which resolves a blank/absent synced option to its file-backed default by stripping the `newspack_event_logger_nodes_`/`newspack_nodes_` then `remote_` prefixes and looking the canonical key up in `Config::load_config_defaults()` — porting the old `Settings_Sync::maybe_queue_static_sync` empty→default logic. Enabling `hub-control` is an operator config step (add it to the substrate `topologies` list), not automatic. Runs in parallel with the legacy `Settings_Sync`/`Remote_Manager` push path until Phase 6.

### Changed

- **Consumers write role-suffixed offsetlogs.** Each Consumer's offsetlog is now named by its owning role rather than the source log it tails — `firehose:consumer` → `firehose.request-builder.p<partition>` (combined/request-builder), `requests:consumer` → `requests.flame-builder.p<partition>`, `jobintake:consumer` → `jobintake.jobs.p<partition>`, `settings:consumer` → `settings.settings-sync.p0`. The old bare names (`firehose.p0`, `requests.p0`, …) were indistinguishable from the source log, so `wp nodes status` and the dashboard rendered a consumer as "a log reading itself." The old bare offsetlog dirs orphan and are reclaimed by the GC.
- **Trimmed the synthetic `$_SERVER` set up for the job request context** (`Log_Manager`): drops `SCRIPT_NAME` / `SCRIPT_URL` / `SCRIPT_URI` / `SCRIPT_FILENAME` and blanks `PATH_INFO` for worker-dispatched jobs — they aren't web requests, and nothing downstream reads those for a job.
- **All dashboard page titles use the shared Newspack page-heading primitive.** The Performance Dashboard, Request Log, Gyroscope, and Error Log titles now carry the substrate's `.newspack-dashboard-title` class (the standard WP admin heading look — 23px / 400) instead of each tree's own copy of the rule; the Performance Dashboard's local `h1` block is dropped in favor of it. One definition, shipped in the `newspack-nodes-theme` handle.
- **The Request Log, Gyroscope, and Error Log get the Raw Logs treatment — light chrome above a dark log pane.** They were fully dark (chrome and content); now the header, filter/search, pause/Clear/Cols controls, column-picker, and stats are light Newspack, while the scrolling data area (the request table / in-flight stream / error entries, with its column-header row capping it) stays a dark `$term-*` pane with 8px corners — matching the substrate's Raw Logs view. (Each tree's `base.scss` `$dark-*` block flips to the light `--np-*` tokens; only the genuine pane classes keep `$term-*`. In `performance-dashboards` only the Error Log consumes `$dark-*`, so the shared base is safe for the already-light charts/tables/modal/flame.)

- **The dashboards are reskinned to the Newspack theme (light Cobalt-on-white chrome + dark data/log surfaces), matching the substrate's event-dashboards reskin.** The Performance Dashboard chrome (summary cards, URL/request tables, the flame container, the modal) now reads as Newspack — the light product look mapped onto the `var(--np-*)` tokens — while the genuine LIVE LOG STREAMS (the Request Log, the Error Log, and the Gyroscope in-flight view) stay DARK terminal surfaces, exactly as the Topology Console's Newspack skin keeps a dark REPL. Each tree's `base.scss` flips its historical Sass tokens onto the canonical tokens (names retained, values flipped): `$light-*` → the light `--np-*` neutrals/Cobalt, and — because in this plugin the `$dark-*` tokens back the log panes rather than chrome — `$dark-*` map onto a local dark `$term-*` set instead of going light. The Material-blue accent (`#64b5f6`) and the ad-hoc dark/Material palette are gone; Cobalt leads (lightened to read on the dark panes), status is functional (success/warning/error), and the settings page + selector modals pick up Cobalt focus rings and the `--np-primary-subtle` custom-value chips. Each dashboard React root and the settings-page wrap now carry `.newspack-nodes-theme` (the substrate ships the token sheet as the `newspack-nodes-theme` style handle, registered early and defaulted into `Admin::enqueue_react_page`'s style deps; the extra settings stylesheet now declares it as a dependency) so `var(--np-*)` resolves.

- **ELN now ships the full `aggregator.tsl` (relocated from the substrate).** The aggregator topology's only node beyond the firehose `Topic` is the ELN `Remote_Job_Rewrite_Node`, so it's an ELN topology, not a substrate one — unlike `hub-control.tsl` (substrate base + ELN override), there's nothing substrate-worthy left to keep a nodes base. The substrate dropped its stock `aggregator.tsl`; `make_node Remote_Source` / `Aggregator_CI`'s `graph_for('aggregator')` resolve this ELN copy via the registered stock dir.
- **`Settings_CI` + `Status_CI` + `Settings_Event_Writer` moved to the `newspack-nodes` substrate.** All three operate purely on substrate concerns: `Settings_CI` reads/writes the four `newspack_nodes_*` integer settings, `Status_CI` reports the runtime version + active topologies + cache reachability, and `Settings_Event_Writer` appends option-name-only events to `settings.p0` on watched option changes. They now ship and mount/init in the substrate (`Newspack_Nodes\Rest\Settings_CI_Node`, `Newspack_Nodes\Rest\Status_CI_Node`, `Newspack_Nodes\Settings_Event_Writer`); ELN's `App\Settings_CI_Node`/`App\Status_CI_Node`/`Settings_Event_Writer`, their mounts in `newspack_event_logger_nodes_mount_service_cis()`, and the `Settings_Event_Writer::init()` call are removed. The `settings`/`status` service CIs are still mounted (now by the substrate), so dashboard `commandClient.send('settings'|'status', …)` calls are unaffected. `Status_CI.get` no longer returns the ELN `version` field (it had no consumer); `runtime_version` is unchanged.
- **`Aggregator_CI` moved to the `newspack-nodes` substrate.** The hub-side aggregator `status`/`health`/`servers` CI operates purely on substrate concerns (the `Vault`, the `aggregator` topology, `Remote_Source` `np:remote:*` snapshots), so it now ships and mounts in the substrate as `Newspack_Nodes\Rest\Aggregator_CI_Node`; ELN's `App\Aggregator_CI_Node` and its mount in `newspack_event_logger_nodes_mount_service_cis()` are removed. The `aggregator` service CI is still mounted (now by the substrate), so the dashboard's `commandClient.send('aggregator', …)` calls are unaffected.
- **Performance dashboards (Request Log, Gyroscope, Error Log) now wire through one substrate `RemoteLink` node** instead of hand-wiring `SseIn` (`_sse`) + `HttpOut` (`_http`) + `Heartbeat` (`_heartbeat`) + a `connected→slot` bridge each. Every hook makes a single `make_node RemoteLink` (subscribe `completed`/`gyroscope`/`errors`), drives `connect`/`close` on visibility/pause, and tears down with a single `link.removeNode()`; the composed children (`{view}:link:sse-in`/`:http`/`:heartbeat`) and the slot bridge now live inside the node. Behavior preserved (live stream, slot keepalive, pause/visibility gating, Reset Graph). Mirrors the Raw Logs dashboard migration in `newspack-nodes`; requires the `RemoteLink` runtime node (`newspack-nodes`).
- **Remote-spoke credentials now live in the substrate Vault.** `Stream_Merger_Node`, `Remote_Manager`, `Health_Check_Tick_Node`, and `Aggregator_CI_Node` read `\Newspack_Nodes\Vault::get_instance()` instead of the local server registry. The aggregator side-effects that used to live in the servers CI's verbs (full settings-sync fan-out + supervisor restart) now run from a listener on the substrate's `newspack_nodes/vault/changed` action.
- **Vault `enabled` flag removed — every present spoke is pulled.** Following the substrate's drop of the stored `enabled` boolean (presence = enabled), `newspack_event_logger_nodes_on_vault_changed` is now the 2-arg `( string $id, string $action )` signature (registered with `accepted_args` 2), `Stream_Merger_Node` no longer skips `enabled === false` entries (it pulls every spoke present in the Vault), and `Aggregator_CI_Node`'s `status`/`servers` verbs no longer emit an `enabled` field. Restart-on-any-Vault-change behavior is unchanged.
- **`Aggregator_CI.status` re-pointed to the pull graph.** Instead of enumerating Vault servers and reading `aggregator_status:{id}:p{N}`, the verb now discovers each `Remote_Source` wired into the active `aggregator` topology (`Topology_Registry::graph_for( 'aggregator' )`, filtered on node `type === 'Remote_Source'`), keys the result by the wired NODE NAME, and reads each node's substrate status snapshot from `np:remote:<node-name>:p<partition>` (the `<vault-id>`/`<partition>` come from the make_node args; the spoke URL is resolved from the Vault by the node's vault-id). The dashboard-facing shape stays `{ [node]: { id, url, partitions } }` (now also carrying `vault_id`).

### Removed

- **Aggregator Status dashboard moved to the substrate (now a DevTools hub tab).** The standalone "Aggregator" admin submenu page and its React tree (`AggregatorStatus`, `AggregatorStatusPage`, `useAggregatorStatusGraph`, `AggregatorViewNode`/`register`, `event-aggregator/index.js`, `aggregator-status.scss`) are removed from this plugin; the dashboard now ships in `newspack-nodes` as a `host:'hub'` DevTools tab (Nodes → Aggregator). The `newspack-nodes-aggregator` submenu registration and its `page_to_tree` entry are dropped, and the `src/event-aggregator/index.js` → `build/event-aggregator` build entry is removed. The aggregator-**settings** page is unchanged: `settings.js`, `styles/settings.scss`, `styles/base.scss`, and the `build/event-aggregator-settings` CSS enqueue all stay. Behavior is otherwise preserved — only the dashboard's host plugin changed.
- **Remote Servers settings moved to the substrate.** The three remote-spoke storage-geometry settings (`remote_num_segments`, `remote_segment_size`, `remote_max_lifespan`) and their "Remote Servers" section moved out of this plugin and into the `newspack-nodes` substrate's Nodes Runtime settings page (`newspack_nodes_remote_*` option names) — they configure substrate storage geometry pushed to spokes. The ELN `Settings_Schema` Fields, `Admin` sanitizers/renderers/section callback, and the helper methods (`render_number_field`, `number_default`) are removed; `hub-control.tsl` now reads the substrate `newspack_nodes_remote_*` options. A one-time substrate `admin_init` migration renames any set old-name option to the new name. The blank→default resolver (`newspack_event_logger_nodes_resolve_settings_sync_value`) already prefix-routes `newspack_nodes_*` to the substrate defaults, so it resolves the renamed options with no change.
- **`Stream_Merger_Node` + the old ELN `Remote_Source_Node` deleted (atomic pull-side cutover).** The hub fan-in is now the self-sufficient substrate `\Newspack_Nodes\Remote_Source_Node` (it patrons its own `SSE_In`/`HTTP_Out`, owns its offsetlog, and publishes a per-node status snapshot to `np:remote:<node-name>:p<partition>`); `make_node Remote_Source` resolves the substrate class with no first-registered-wins ambiguity. `aggregator.tsl` is rewritten to `Remote_Job_Rewrite → firehose:topic`; per-spoke `Remote_Source` nodes are operator-wired on the console canvas (`make_node Remote_Source spoke-<id> <vault-id> firehose <partition>` + `connect_node spoke-<id> remote-job-rewrite`). The `StreamMergerTest` / `RemoteSourceTest` suites are deleted.
- **`aggregator_require_https` / `aggregator_verify_ssl` config keys retired.** They fed the deleted `Stream_Merger`; the substrate's `vault_require_https` / `vault_verify_ssl` (read by the substrate `Remote_Source`) replace them. Removed from the config-file defaults and the `eln` topology-token namespace (the `<eln:aggregator_*_ssl>` tokens no longer resolve).
- **`newspack_nodes/aggregator_ingest_line` filter retired.** `Stream_Merger_Node::register_remote_job_rewrite_filter()`, its `newspack_nodes/before_worker_spawn` bootstrap closure, and the associated test are gone — the `k:"job"` → `k:"remote_job"` rewrite is now the `Remote_Job_Rewrite_Node` graph node (above) instead of a filter applied inside the substrate transport.
- **Legacy hub→spoke push path retired** (Slice A1) — `Settings_Sync` (the `update_option`→`remote_manager`-job static fan-out), `Remote_Manager` (the entire push path: `sync_setting`/`health_check`/`queue_sync_all_settings`/discovery/`post_to_server`), `Health_Check_Tick_Node`, and `Health_Check_Extensions` are deleted, along with the now-superseded `App\Settings_CI_Node` `update` and `App\Performance_CI_Node` `settings_update` verbs (replaced by the normalized `set <option> <value>` verbs). Settings propagation + discovery now run entirely through the `hub-control.tsl` node graph (`Settings_Event_Writer` → `settings.p0` → `Settings_Sync_Node` → `spokes:tee` → `HTTP_Out`; `Discovery_Collector_Node`). `Auto_Tuner_Node` no longer queues a `remote_manager` job and no longer suppresses its own write — its tuning change propagates immediately via the option-change hook, exactly as an admin edit does. `on_vault_changed` keeps only its best-effort supervisor-restart side-effect (the enable-flip settings fan-out is now the node graph's job).
- **Server registry + aggregator-admin UI** — `Server_Registry`, the `servers` CI, the Configured Servers settings field, and the `aggregator-admin` React app are gone; remote-server credentials are owned by the substrate `\Newspack_Nodes\Vault` and managed from the Nodes hub **Vault** tab.
- **`Config::kill_readers()` delegate** — `kill_readers` moved to the substrate `Supervisor`; call `\Newspack_Nodes\Bootstrap::supervisor()->kill_readers( $groups )` (or the topology-activate REST path that wraps it). The `ConfigTest` cases that exercised the substrate's `kill_readers` and `Config_Utils::sanitize_option()` through the now-removed delegates were dropped — that coverage is substrate-owned (nodes' `SupervisorTest` / config-system tests).

### Fixed

- **The `performance` `set` receiver no longer corrupts associative-array options synced from the hub.** `custom_events` is stored as `{event_name => true}`; the substrate settings-sync now ships array options as JSON (it previously comma-flattened them, dropping the keys → a meaningless `1,1,1,…`). `Performance_CI_Node`'s `set` verb decodes array-typed options through the new `decode_array_value()` — `json_decode` first (preserving the map's keys + values), falling back to the existing comma-split for legacy/CSV senders — instead of always csv-splitting, which mangled the synced value into a junk list (`['1','1',…]`, all custom-event enable state lost). Pairs with the substrate `Settings_Sync_Node` JSON change.

- **Remote spoke geometry syncs its own default, not the hub's.** The `settings_sync/value` resolver stripped a leading `remote_` from the canonical key, so a blank `newspack_nodes_remote_max_lifespan` resolved to the hub's `max_lifespan` default (86400) instead of the remote setting's own default (3600) — the spoke got the wrong retention. Now that `remote_*` are first-class substrate settings with their own defaults, the resolver no longer strips `remote_`; `newspack_nodes_remote_max_lifespan` resolves to `remote_max_lifespan` (3600), matching the value shown on the Nodes Runtime page.
- **Resetting a synced setting to its default now propagates to spokes** (two halves). (1) `Settings_Event_Writer` watched only `update_option`/`add_option`, but returning a setting to its default *deletes* the option row (`Reset_Gate` short-circuits `pre_update_option` with `delete_option`), so the reset fired `delete_option` — unwatched — and no event was emitted; it now also watches `delete_option`. (2) The `newspack_nodes/settings_sync/value` resolver then looked the canonical default key up only in *ELN's* `load_config_defaults()`, but substrate keys (`newspack_nodes_num_partitions`, …) live in `\Newspack_Nodes\Config` — so the lookup missed, returned the raw `false`, and shipped blank; it now routes `newspack_nodes_`-prefixed options to the substrate defaults. Net: dropping `num_partitions` 2→1 now resets the remote to 1.
- Performance Dashboard: the page title now uses the standard WordPress admin heading size (23px / 400) instead of the unstyled browser default (~2em bold), so it matches the rest of wp-admin.
- **Select Hooks button restored** on the Event Logger Settings page. It had vanished because the page hosted two exospine React graphs (the hook-catalog selector and the aggregator-admin Configured Servers app) that collided registering the reserved `_http` node; moving the server UI to the Nodes hub Vault tab leaves a single graph and clears the collision.

### Internal

- **PHPStan now includes the ShipMonk dead-code detector.** `npm run lint:phpstan` runs through `phpstan-deadcode.neon`, so dead-code findings stay in the normal PHPStan/lint-staged/pre-push gate instead of being an opt-in sweep. `npm run lint:deadcode` remains as an alias for the same gate. Same substrate caveat — most findings on an application built atop the runtime are WP/CLI entrypoints, hooks, or wire constants, not genuinely dead; verify call paths before deleting.

## [0.18.0] - 2026-06-17

### Changed

- **Clarified the in-line state-logging format in `Flame_Builder_Node`, `Job_Router_Node`, and `Remote_Source_Node`** for readability; no behavior change.
- **`Remote_Source`, `Request_Builder`, and `Stream_Merger` `arguments()` now delegate to the substrate's centralized `parse_schema_args()`** (no per-node empty-string short-circuit), following the newspack-nodes ADR-11 revision where a missing token takes the arg's schema `default` or throws if `required`. Behavior is unchanged for these nodes — they construct with their declared tokens (`Remote_Source`'s `server_id`/`url` stay `required`).

- **Register the firehose/jobintake producers via the substrate's new `newspack_nodes/registered_log_producers` filter.** Replaces the removed `newspack_nodes/expected_log_basenames` filter: ELN now contributes its request-scope producer basenames (`firehose`, `jobintake`) to the producer set the rewritten log GC expands (× config `num_partitions`) into protected `logs/{producer}.p{N}/` dirs, keeping those request-scope logs (declared in no `.tsl`) safe from garbage collection. Requires newspack-nodes with the segmented-I/O P3 log-GC rewrite.
- **Adopt the newspack-nodes flat partition-in-name layout** (`logs/firehose.p<partition>/{seg}.log` instead of `logs/firehose.log/p<partition>/{seg}.log`; offsetlogs flattened, no inner `/p0`). All six topologies are rewritten, plus the production constructors that build substrate nodes directly: the `Log_Manager` firehose Topic (`firehose.p{partition}` template — without the `{partition}` token the firehose would write to the wrong path and the pipeline would read nothing), `Job_Intake` partitions (`jobintake.p{partition}`), the `Stream_Merger` offsetlog (flat), and the `Performance_CI` + `reqgrep` readers (now read the live flat data). Requires newspack-nodes with the segmented-I/O P0 arg-signature change. Existing on-disk data is in the old layout and should be cleared — no automatic migration.
- **View nodes adopt the substrate's shared `@newspack-nodes/shared/pendingReplies`.** `servers-view-node` and `hook-catalog-view-node` replace their inline `pending` Map + local `_errorMessage` with `this.replies = new PendingReplies()` (settle via the boolean return; `servers-view-node`'s teardown uses `replies.rejectAll(...)`); `performance-view-node` keeps its richer pending Map — its sibling `performance-command-node` writes `{slice, initial}` / `{resolveOnly, transform}` entries through it — and adopts only the shared `errorMessage`. Their hooks (`useAggregatorAdminGraph`, `useHookCatalogGraph`) call `view.replies.add(...)`. No behavior change.
- **Dashboard build runs through the substrate's shared `buildDashboards()` build-kit.** `scripts/build.mjs` is now a thin shell that injects this plugin's `esbuild`/`sass`/`rtlcss` into the kit (resolved from the sibling `newspack-nodes` checkout, overridable via `NEWSPACK_NODES_BUILD_KIT`) and declares only its aliases + 7 entries; build output is byte-identical. `npm run watch` now cleans `build/` first, matching `npm run build`. `jest.config.js` likewise delegates to the kit's `createJestConfig()` (passing this plugin's React/d3 pins + the d3 ESM transform allowlist), so the shared moduleNameMapper ordering is owned in one place.
- **Jest suite fails on an unexpected `console.warn` / `console.error`.** `jest.setup.js` now records every non-substrate `console.warn` and every `console.error` (React `act(...)` warnings, deprecations, genuine errors) and re-throws it in `afterEach` — failing the test instead of printing it (mirrors the substrate setup).
- **Dashboard asset enqueue routes through the substrate's `Admin::enqueue_react_page()` registrar.** The `admin_enqueue_scripts` dispatcher now delegates the script + `index.css` + `NewspackNodesData` localize to the shared registrar (passing its complete localize payload — `restUrl`, `aggregatorRestUrl`, `nonce`, `restartNonce`, `tree`, `version`), keeping every per-tree extra (the `performance-logger` settings CSS, the `eventLoggerDashboards`/recommended-hooks inline scripts, and the `aggregator-admin` secondary bundle) anchored on the returned handle. As a side effect dashboards now cache-bust on the wp-scripts manifest hash (deps from `index.asset.php`) rather than `filemtime`, and `index-rtl.css` is activated when present.

## [0.17.1] - 2026-06-12

### Changed

- **Deprecated `isSmall` Button prop migrated to `size="small"`.** The two errors-only toggle Buttons (URL table + URL detail view) still passed `isSmall`, deprecated in WP 6.2; both now use the documented `size="small"` replacement. A `react/forbid-component-props` eslint rule bans `isSmall` at the JSX-attribute level so it can't creep back in, and the plugin header now declares `Requires at least: 6.5` — the dashboards run on core's `window.wp.components`, and the Button `size` prop needs WP 6.4+.

- **Admin dashboard form controls opt into the WordPress 40px default size + no-bottom-margin styles.** Added `__next40pxDefaultSize` to every `TextControl` / `SelectControl` / `SearchControl` and `__nextHasNoMarginBottom` to the `SearchControl` / `CheckboxControl` that lacked it (across the performance dashboards, URL detail, overview, and the hook/custom-event selector modals). Clears the `@wordpress/components` 6.7/6.8 deprecation notices; those controls now render at the 40px height that becomes the WordPress default in 7.1.
- **The Performance Dashboard + Settings entry points mount via React 18 `createRoot` instead of the deprecated `render()`.** `performance-dashboards` (AdminApp + ErrorLogPage) and `performance-logger` (tag-input fields) now `createRoot( container ).render( … )`, matching the other dashboard entry points and removing the "ReactDOM.render is no longer supported in React 18" warning.

## [0.17.0] - 2026-06-12

### Changed

- **Worker requests get their own `{base_url}?{worker_type}` per-URL stats row, with timing, and are fully excluded from global aggregates.** `Request_Builder_Node` now captures the `NEWSPACK_NODES_WORKER_TYPE` value (sanitized `[a-z0-9_-]`) into `worker_type`, and `emit_request()` rewrites `$request->url` to `{base}?{worker_type}` once (so the index line, compact summary, and stats all read the same effective URL) instead of colliding worker hits onto the real URL. `url_hash()` no longer strips at `?` (callers already strip the real query upstream; the only surviving `?` is the intentional worker marker), so the synthetic row hashes distinctly. `Flame_Builder_Node::accumulate_all_stats()` splits its single timing gate into `$record_timing` (per-URL flame / url_stats / url_dim / cat_by_url keep worker timing) and `$count_global` (hourly, dimensional global + per-server, leaderboard, categories drop workers entirely).

### Fixed

- **Workers no longer leak into global hourly peak memory, global dimensional count/peak, or the global hook auto-tune signal.** The prior single gate excluded workers from global *timing* but still bumped global hourly `sum_peak_mb` and global dimensional `c`/`m` unconditionally, and worker profiles could still drive plugin-wide `hooks_to_disable` / `custom_events_to_disable` (noisy-hook detection); the two-gate split closes all three leaks.
- **The cron-backstop supervisor's stats URL is `/jobs/newspack-nodes?supervisor`, not `/jobs/newspack-nodes/supervisor?supervisor`.** The job-context handler dropped the redundant `/supervisor` path segment (the `?supervisor` suffix already comes from `worker_type`).

### Removed

- **The orphaned `worker_type` firehose-keyword state callback in `Request_Builder_Node`.** It was a museum-era workaround (the old plugins emitted an explicit `message('worker_type', …)` because the env var was set *after* the environment block was logged); the substrate now sets `NEWSPACK_NODES_WORKER_TYPE` before the environment block, so worker detection flows solely through the `environment_v2` env-var line and nothing produces a `k='worker_type'` entry anymore.

## [0.16.2] - 2026-06-12

### Changed

- **SSE dashboard "Xs ago" staleness now reflects connection liveness, not row arrivals.** The Request Log and Error Log dashboards (and the Gyroscope's in-flight view) source their "Xs ago" indicator from the shared `_sse` connector's `lastEventTime` instead of their own view node's. The connector stamps `lastEventTime` on every inbound frame AND on the server's idle heartbeats, so an idle-but-healthy stream resets "Xs ago" to ~0 instead of showing a climbing counter that looks like a dead connection; a real drop (no heartbeats) leaves it frozen and "ago" climbs as the intended warning. Side effect: Clear no longer hides "Xs ago" — clearing the displayed rows leaves the live connection untouched, so the staleness persists. Requires `newspack-nodes` with the `_sse` connector exposing a public `lastEventTime`.

## [0.16.1] - 2026-06-12

### Changed

- **`Job_Router_Node` emits the job kind under `k`, not `type`.** The normalized jobs.log entry is now `{ k, handler, parameters, ts }`, matching what `Job_Intake` already writes and what the substrate `Job_Worker_Node` dispatches on — so the kind field is the same `k` from firehose category → jobs.log → worker, with no rename at any hop. Requires `newspack-nodes` with the `k`-reading `Job_Worker_Node`.

### Fixed

- **Jobs queued via `Job_Intake` (large/`jobintake.log` payloads) are no longer silently dropped.** `Job_Intake` writes entries keyed by `k`, but the substrate executor read `type`; in topologies that wire `jobintake:consumer` straight to `jobs:partition` (e.g. `combined`), every jobintake-sourced job — third-party event imports (film times, Ticketmaster, …) and any other `write_job()` caller — was read off jobs.log and discarded before reaching its handler. Normalizing the kind field to `k` end-to-end restores dispatch.

## [0.16.0] - 2026-06-11

### Added

- **Debug overlay renders registration edges.** The bundled debug overlay — which inlines the substrate's `parseMetadata` / `SchematicCanvas` via the `@newspack-nodes/debug-overlay` alias — now draws node-name event registrations as dashed, informational edges between visible nodes (dotted, event-name hover tooltip, not click-deletable). Requires `newspack-nodes` with the `registrations` `dump_metadata` field.

### Changed

- **`Reqgrep_Command` carries its own `READ_CHUNK_BYTES` for the cat/follow reader.** The CLI firehose reader chunked its `read_at` loop by the substrate's `Consumer_Node::MAX_POLL_BYTES`; that constant was removed when `Consumer_Node` moved to one-block-per-poll reads, so reqgrep now owns a `READ_CHUNK_BYTES` (10 MB) constant for bounding CLI memory — a separate concern from the node's per-poll event-loop yield. Requires `newspack-nodes` with the new Consumer read path.
- **Worker-output partitions drop the cross-process write lock for `void_warranty`.** The `requests` / `flames` / `jobs` partitions (large request records exceed PIPE_BUF) now opt into the substrate's lock-free `void_warranty` instead of `allow_large_writes`. Each is written by exactly one worker fleet, and the substrate now refuses to enable or spawn a topology set where two fleets would write the same partition (write-conflict detection at the admin sanitizer + supervisor), so the per-partition exclusivity lock is redundant — its sole job was guarding against a second writer that enforcement now prevents. Requires `newspack-nodes` with `void_warranty` + write-conflict enforcement.

### Fixed

- **Stalled in-flight requests now time out on idle / low-traffic partitions.** `Request_Builder_Node` is now a `Timer_Node` that hitchhikes the Router's 1s TIMER (registered in `arguments()`) and rotates its in-flight cache on each tick, so a request that never completes is evicted and written to `requests.log` with `error_status='T'` even when no further firehose lines arrive to drive rotation via `fill()`. The timed-out request is also emitted to `completed:tee`, so the gyroscope reaps it. `Request_Flight_Node` likewise fires via the Router hitchhike — its `fire()` replaces the old `fire_cb()` override, and setting its in-flight target now *is* what enables snapshots (a non-empty target starts the hitchhike, an empty one stops it); the snapshot cadence is the Router's tick. Previously an idle request-builder never rotated, so a stalled request sat invisible: absent from `requests.log`, unclickable, and missing from "Show Errors". (The separate worker-respawn-boundary loss is addressed by the offsetlog snapshot below.)
- **In-flight requests now survive the ~10-min worker respawn.** The firehose Consumer snapshots `Request_Builder_Node`'s in-flight cache into its offsetlog alongside the read cursor (`cmd firehose:consumer:config set_snapshot_node request-builder`), so a request whose head was read before the recycle completes on the new worker instead of vanishing. The broken-out `request-builder` and `job-router` topologies now read the firehose with distinct offsetlog paths (`firehose.request-builder.p{N}` / `firehose.job-router.p{N}`) so they can run together without clobbering each other's cursor.

### Removed

- **The `set_inflight_interval` config verb is gone.** With the in-flight snapshot hitchhiking the Router's 1s TIMER, a per-node interval no longer means anything — the argument was silently ignored. Enabling snapshots is now solely `set_inflight_target` (non-empty target → start, empty → stop), and topologies drop their `set_inflight_interval 1000` line. `Request_Flight_Node::set_interval()` / `interval()` and the cosmetic `inflight_interval_ms` field are removed.
- **Performance flame graphs no longer prune small frames that should be visible.** `pruneFlameGraph` previously applied the 0.1%-of-total cutoff unconditionally, so even a small flame graph lost every frame under 0.1% — frames vanished purely for being small, well before any node-count limit. A frame is now kept if it is among the largest `softMaxNodes` (1000) frames **OR** is at least 0.1% of the total, so nothing is stripped while the graph is under the soft cap and sub-0.1% frames stay visible past it only once they're also ranked out. A new `hardMaxNodes` (5000) absolute ceiling replaces the old 1000-node hard cap. The `pruneFlameGraph` option `maxNodes` is replaced by `softMaxNodes` / `hardMaxNodes`.

## [0.15.0] - 2026-06-11

### Changed

- **`Substrate_Guard` is gone; the deferred bootstrap is gated on a plain `class_exists` substrate-presence check.** The guard's version floor + API probe + admin notice solved a non-problem: `Requires Plugins: newspack-nodes` keeps the runtime active on WP 6.5+, and the two plugins deploy together, so a present-but-too-old substrate isn't a real case. The `plugins_loaded` priority-11 bootstrap now simply no-ops when `\Newspack_Nodes\Node` isn't loaded. `includes/class-substrate-guard.php` + its test are removed.
- Raise the declared PHP floor to 8.2, matching the `newspack-nodes` substrate this plugin depends on.

### Removed

- **The refresh-ahead cache warmer moved to its own plugin, `newspack-cache-cozy`.** `Cache_Warmer_Tick_Node`, the `mu-plugins/01-newspack-cache-warmer.php` drop-in (`Newspack_Cache_Warmer\Cache_Warmer` + `Cold_Read_Object_Cache`), the `scripts/schedule-cache-warmer.sh` / `unschedule-cache-warmer.sh` operator scripts, and the three cache-warmer test files are gone from this plugin. The `Cache_Warmer_Tick_Node::init()` call is dropped from the worker-runtime bootstrap, and the drop-in is no longer copied into `release/` or attached to the GitHub release. Cache warming is now a focused, independently-released plugin that builds on the same substrate. **Migration:** install `newspack-cache-cozy` (plugin zip + its own `01-newspack-cache-cozy.php` mu-plugin drop-in) to keep warming; remove the stale `01-newspack-cache-warmer.php` drop-in. The new plugin uses its own option/cron keys (`newspack_cache_cozy_*`), so the old `eln_cache_warmer_*` options orphan harmlessly.

## [0.14.0] - 2026-06-10

### Changed

- **Application nodes adopt the substrate's new `Schema_Reflection` trait.** The substrate moved positional-arg parsing + `:config` interpreter auto-wiring off the base `Node` into an opt-in `Schema_Reflection` trait. `Request_Builder_Node`, `Stream_Merger_Node`, `Flame_Builder_Node` (auto-wire a `:config` sibling) and `Remote_Source_Node` (parses positional args) now `use \Newspack_Nodes\Schema_Reflection`, call `$this->parse_schema_args()` in their `arguments()` override, and call `$this->auto_wire_interpreter()` in their ctor. `Cache_Warmer_Tick_Node` / `Health_Check_Tick_Node` parse their single arg inline and carry no trait. Requires `newspack-nodes` with the `Schema_Reflection` trait. Behavior unchanged.
- **`Remote_Source_Node`'s bespoke `dump_node()` secret-redaction override is removed; the substrate now redacts credentials for every node.** The substrate's `Node::dump_node()` redacts any secret-named property (`auth_password`/`auth_token` included) by default, so the per-node override here was redundant — and would have left any *other* credential-bearing node unprotected. Behavior is unchanged: `dump_node my_remote` from the REPL still shows the credential slots as `[REDACTED]`. Requires `newspack-nodes` with base-`dump_node` redaction.
- **Substrate floor raised to `newspack-nodes` 0.15.0, with a `Schema_Reflection` trait probe.** The `Schema_Reflection` trait the nodes above `use` ships in nodes 0.15.0, so an older substrate would satisfy the guard (floor was 0.13.0) and then fatal at class-load on the missing trait. `Substrate_Guard::MINIMUM_NODES_VERSION` is now `0.15.0`, and `required_apis_present()` probes `trait_exists( '\Newspack_Nodes\Schema_Reflection' )` (new `REQUIRED_TRAITS` set) so an absent-but-versioned trait flips the guard to its admin notice instead of a fatal.

### Fixed

- **`AdminTest::reset_default_provider` data provider is now static.** PHPUnit 10 deprecates non-static `@dataProvider` methods (a hard error in PHPUnit 11); the suite ran clean except for this one deprecation. Test-only.
- **Application test temp dirs no longer collide with the substrate's under parallel coverage.** ELN inherited the substrate `make_temp_dir()`, which defaulted to the `newspack-nodes-test-` prefix — so under `run-coverage` (nodes + ELN run in parallel) each suite's `run-coverage.sh` `rm -rf`'d the *other's* live temp dirs. ELN's `TestCase` now defaults to its own `newspack-event-logger-nodes-test-` prefix and `run-coverage.sh` purges only that. Test-only.

## [0.13.1] - 2026-06-09

### Fixed

- **Self-removing filters no longer swallow a hook's `(complete)` entry.** es-wp-query's `ES_WP_Query_Shoehorn` registers run-once `found_posts_query`/`found_posts` filters at priority 1000 that `remove_filter()` themselves mid-run. WordPress core's `WP_Hook::resort_active_iterations()` then parks the iteration pointer on the next surviving priority and `apply_filters()`' `next()` skips it — which was our `hook_complete` at `PHP_INT_MAX - 1`. Every ES-backed query therefore logged a `(start)` with no in-place `(complete)`; the spans dangled until Log_Manager's end-of-request sweep, and the flame builder nested each successive query one level deeper (a 21-query homepage stretch rendered as a bogus 47-level staircase — the same request that exposed the decode-depth bug below). `App\Core` now registers a sacrificial no-op `hook_spacer` at `PHP_INT_MAX - 2` on every instrumented hook: the pointer-skip consumes the spacer instead of the complete (verified against real `WP_Hook`, including multiple self-removals in one invocation). The spacer priority is also excluded from significant-event callback wrapping.

- **Deep flame graphs (>~32 span levels) no longer vanish from the request detail view.** `Flame_Builder` allows flame trees up to `MAX_STACK_DEPTH` (50) span levels — ~2 JSON nesting levels per span — but both the write-side index formatter (`Flame_Builder_Node::format_index_entry`) and the read-side lookup (`Performance_CI_Node::find_flame_for_rid`) decoded packed flame lines with `json_decode( …, 64 )`. A flame deeper than ~32 spans (typical of slow page renders with many nested ES-backed queries) failed both decodes: the blob was written to `flames.log` but never indexed and never returned, so the dashboard silently showed no flame graph. Both sites now decode with the new `Flame_Builder_Node::FLAME_JSON_DEPTH` (`2 × MAX_STACK_DEPTH + 8`), keeping the decode budget tied to what the builder can emit. Note: deep flames written before this fix have no index entries and stay unfindable; only newly assembled requests benefit.

## [0.13.0] - 2026-06-09

### Changed

- **Settings admin + config migrated onto the shared `newspack-nodes` Config System.** The three parallel arrays `Config` and `Admin` hand-maintained in lockstep (`Config::$option_schema`, `Admin::$option_names`, `Admin::$delete_on_blank_options`) collapse into one declarative `Settings_Schema`; the overlay key-list, register/reset surface, section+field render loop, and the worker-restart classification all derive from it. `maybe_request_worker_restart()` now reads each field's restart class via `Schema::restart_for()` — only the genuine non-field cases stay hand-coded (`enable_aggregator`'s single-lock kick and the rotated-by-flush `stats_salt`); the retired `enable_jobs` entry is dropped. The bespoke `auto_tune` + `configured_servers` controls are unchanged, and field markup routes through the shared `Settings_Renderer` (`checkbox`/`number`/`react_mount`). Behavior is byte-identical — overlay keys, registered options, delete-on-blank set, reset gating, restart classification, and the rendered markup are unchanged (verified against the pre-migration literals). Two latent divergences are resolved along the way: `enable_aggregator` is now typed once (a single `bool` Field, no separate inline closure), and the bool→int default coercion is single-sourced in `bool_to_int()`.
- **Substrate floor raised to `newspack-nodes` 0.13.0.** The settings layer now depends on the substrate's `Config_System\Field` / `Schema` / `Settings_Renderer` classes (added in nodes 0.13.0). `Substrate_Guard` requires `>= 0.13.0` and probes those three classes, so an older substrate shows the guard notice instead of fataling on class-not-found when the plugins are released independently.

### Fixed

- **Editing a tag-input setting after arming its reset no longer discards the edit on Save.** The shared per-field reset module only auto-drops a pending `↺` mark when a non-hidden control is edited; a React tag-input field (`TagInputField`: Log/Skip URLs, Log/Custom Events, Significant Events) posts through a hidden JSON carrier the module ignores, so marking-then-editing left the marker in place and `Reset_Gate` deleted the option on Save — reverting to the file default and dropping the operator's edit. `TagInputField` now clears the pending mark on its wrapper whenever its value changes (skipping the initial mount), matching the module's drop-on-edit behavior. Pre-existing; surfaced during the Config System migration.

## [0.12.1] - 2026-06-09

### Fixed

- **Per-field reset toggle now previews a settings checkbox's real default.** Arming a reset (`↺`) cleared every checkbox to unchecked regardless of its default, so a default-enabled box (e.g. "Enable event logging") looked like it would be disabled while the reset was pending (Save was always correct). Each checkbox now carries a `data-nn-reset-default` attribute — the file-config default, via the new `bool_file_default()` helper extracted from `bool_option_with_file_default()` — so the shared reset JS previews the correct state.

## [0.12.0] - 2026-06-09

### Changed

- **Scoped `box-sizing: border-box` baseline per dashboard.** Each React dashboard bundle (performance dashboards, gyroscope, request-log, logger, aggregator) now applies a `border-box` reset scoped to its own mount root, so the rule can't leak into the rest of wp-admin.
- **`Job_Worker_Node` moved to the `newspack-nodes` substrate.** Generic async-job dispatch is runtime plumbing, so the executor now ships with the substrate (`\Newspack_Nodes\Job_Worker_Node`). This plugin keeps only the app-specific job *request context*: `Log_Manager::begin_job_context()` / `end_job_context()` (relocated here from the old node, now stack-based and reusing `Log_Manager::generate_request_id()`) are hooked onto the substrate's new `newspack_nodes/job_worker/before_job` / `after_job` actions, so a dispatched handler still runs under a synthetic `/jobs/{handler}` `$_SERVER` scope with the parent logger suspended. The supervisor-tick wrap and `Health_Check_Tick_Node` now call `Log_Manager::begin/end_job_context` directly. `topologies/job-worker.tsl` is deleted — the `job-worker` topology is now a substrate stock topology. No behavior change for job dispatch or logging; full PHPUnit suite green.
- **`Remote_Manager` no longer carries its own `begin/end_job_context` copy.** `handle_job` now wraps each remote action through the canonical `Log_Manager::begin/end_job_context` instead of a private duplicate that rewrote `$_SERVER` but never suspended the logger (so its promised per-action request_id never reached a fresh `Log_Manager`). The nested wrap now correctly scopes each remote action to its own `/jobs/remote_manager/{action}` request with a fresh request_id — the correlation the old docblock claimed but didn't deliver. Removes ~50 lines of duplicated context code.

- **Shared React hooks/utils are now aliased from `newspack-nodes`, not copied.** Imports of `../shared/*` are now `@newspack-nodes/shared/*` (resolved by esbuild + jest to the sibling's canonical `src/shared`, mirroring `@newspack-nodes/runtime`), and the local `src/shared/` synced copies are removed. The one ELN-owned test helper that lived under `src/shared` (`renderHook`) moved to `src/test-helpers/`. Retires the `sync-shared.sh` copy mechanism — one source of truth in newspack-nodes.

- **Config-system code is now the shared `Newspack_Nodes\Config_System\*` (no ELN copies).** `Config::load_config()` replaces its own presence-based sentinel-overlay loop with `Config_System\Options_Overlay::apply()` (the substrate-merge layering is preserved). The admin's per-field reset/delete-on-blank gate — formerly the in-class `reset_or_blank` + `is_reset_marked` + `is_blank_text_like` methods and a manual `pre_update_option_{$option}` `add_filter` loop — is now a single `Config_System\Reset_Gate::register( RESET_MARK_FIELD, <full option list>, <text-like subset> )` call; the per-field marker name comes from `Reset_Gate::mark_name()`; and the settings page enqueues the shared `Config_System\Field_Reset_Assets::enqueue()` bundle (the nodes-built `newspack-nodes-field-reset` module) + `Field_Reset_Assets::highlight_style()` instead of the ELN-local copy. Every settings-form field — booleans (`enable_logging`, `enable_aggregator`, `log_memory`, `flush_every_line`) and multi-selects (`log_urls`, `skip_urls`, `log_events`, `custom_events`, `significant_events`) included — carries the per-field reset toggle, so a reset clears it; the text-like blank-delete subset stays the `auto_*` / `remote_*` numeric fields. `aggregator_servers` is excluded from the reset set — it's managed by the ServerRegistry REST CRUD, not the settings form, so there's nothing to reset there. The now-dead ELN-local `src/admin-field-reset/` JS module + its build entry are removed (ELN hard-depends on newspack-nodes and references its bundle by URL). Behavior-preserving; full PHPUnit suite green (1703), jest/phpcs/phpstan clean.

### Fixed

- **Request Log and Error Log no longer freeze once the buffer hits its cap.** The live-stream views' change-detection keyed off `buffer.length`, which is pinned the moment the view buffer saturates `maxEntries` (1000 for the request log, 5000 for the error log) and starts rotating (newest unshifted, oldest dropped). With the length constant, `setEntries` stopped firing, so the rendered list — and the "Xs ago" staleness — froze at the cap while events kept flowing. Change-detection now keys off the monotonic newest `seq`, which keeps climbing as the buffer rotates, so the log keeps scrolling the most-recent N indefinitely. Staleness reads the *unfiltered* buffer's newest seq, so "Xs ago" reflects every arrival regardless of an active filter (the request log had also stopped updating staleness whenever the filter hid the newest rows) and keeps ticking past the cap. Clear now also resets the staleness so a freshly-emptied log no longer shows the pre-clear age.
- **Request Log and Error Log no longer flicker a row down-then-up as new entries arrive.** The smooth-scroll offset that holds the existing rows in place while a new row slides in was applied in the requestAnimationFrame loop, one channel out of step with React's commit of the new row — so the row could paint a frame before its compensating offset, jumping the list down a row and snapping back the next tick. The compensation now runs in a `useLayoutEffect` keyed on the committed entries (synchronously after the rows mount, before paint), so the offset and the new row land in the same frame. The first row in an empty list, and the first row after Clear, no longer slide. (A separate, pre-existing whole-row judder under sustained heavy load — the continuous transform vs the discrete virtualization spacer — is unchanged and tracked separately. The substrate's canvas-rendered Raw Logs view draws the offset and rows together each frame and has neither bug.)
- **Topology debug overlay no longer appears on the settings page.** The `aggregator-admin` bundle — mounted into the Configured Servers section of the operator-facing settings page — rendered the sticky `?nodes-debug=1` dev HUD (the `◉` floating button + topology console). So once the flag was set on any technical dashboard, the overlay followed the operator onto settings. Removed `<DebugOverlay>` from that one settings entry; every technical dashboard (Workers, Raw Logs, Gyroscope, Request Stream, Performance, the standalone Aggregator status page) keeps its overlay.
- **Request Log, Error Log, and Gyroscope views no longer degrade at high event rates.** All three view nodes did O(n)-per-event work, mirroring the substrate Raw Logs fix. `RequestLogViewNode` and `PerfErrorsViewNode` did `entries.unshift()` (re-indexing the whole buffer — cap 1,000 / 5,000 — on every row) plus a per-row `completedHistory` filter+reduce over the full 10s window; their buffers are now fixed **ring buffers** (O(1) append + cap-drop, no shift/concat/truncation), exposing `entriesCount` + `entryAt(i)` for windowed reads, and requests/errors-per-second is tracked with bounded per-second buckets + a running total. `GyroscopeViewNode`'s per-message path was already O(1) (a Map upsert), but its `snapshot()`-tick RPS used the same per-tick `completedHistory` filter+reduce; it now uses the same bucketed window, while still expiring every tick (including idle ticks with no completions) so `rps` decays to 0 once completions stop. Newest-first order, caps, pause/clear, and the rps/rps-decay values are unchanged.

## [0.11.0] - 2026-06-07

### Added

- **Cache warmer runs as a JobWorker job, not wp-cron.** `Cache_Warmer_Tick_Node` (a `Timer_Node`) self-starts its timer in `arguments()` (so `make_node Cache_Warmer_Tick cache-warmer:tick` — or `… <interval>` for a custom period — arms it) and, hitchhiking `_router`'s ~5s heartbeat inside a long-lived worker (immune to wp-cron contention), enqueues a `cache_warmer` job every `INTERVAL_SECONDS` (60) by emitting a `TM_STRUCT` job Message to its `target` through the normal node-graph pipeline rather than writing to `Log_Manager` directly. The `cache_warmer` handler — registered on `newspack_nodes/job_handlers` via `Cache_Warmer_Tick_Node::init()` from the worker-runtime bootstrap — runs the drop-in's single-flight loopback (`Cache_Warmer::run_tick()`) inside the JobWorker, so the blocking warm render is isolated from the tick's loop. The handler drops any job that sat in the queue `>= INTERVAL_SECONDS` (a newer tick is already coming); there is no uniform JobWorker stale-drop — each handler enforces its own age, like `Remote_Manager::STALE_THRESHOLD`. Add to a topology with one line (`make_node Cache_Warmer_Tick cache-warmer:tick`); no `wp cron` scheduling.
- **Refresh-ahead cache warmer.** A self-contained mu-plugin drop-in (`mu-plugins/01-newspack-cache-warmer.php`, namespace `Newspack_Cache_Warmer`, shipped as a release asset alongside the profiler) keeps the homepage's caches hot out-of-band so no visitor pays the cold render. Its `eln_cache_warmer_tick` cron — which you schedule manually where you want it to run (`wp cron event schedule eln_cache_warmer_tick now eln_cache_warmer_minute`); the drop-in registers both the handler (so the event is runnable) and its own `eln_cache_warmer_minute` 60s recurrence via `cron_schedules` (so scheduling never depends on another plugin's interval being loaded) — fires a secret-gated loopback at the homepage. On that loopback the drop-in swaps `$GLOBALS['wp_object_cache']` for a cold-read / write-through decorator over the configured groups (`newspack_blocks`, `transient`, `site-transient`; filterable via `eln_cache_warmer_cold_groups`), forcing the block-HTML cache (and the Jetpack top-posts transient) to rebuild fresh into live memcached — so visitors are served warm block HTML and never trigger the per-block Elasticsearch queries that cause the 5–6s spikes (there is no ES result cache; the block-HTML cache is the sole gate on ES). The warm render tags itself (`$_SERVER['NEWSPACK_NODES_WORKER_TYPE']='cache-warmer'`) so it's excluded from timing stats, holds a single-flight lock, and disables the Password Protected plugin for its own (secret-gated) render so the loopback reaches the real homepage instead of the login page (real unauthenticated visitors are still gated before any cached HTML is served). The loopback verifies TLS by default (opt out for self-signed dev certs via `eln_cache_warmer_sslverify`). No host gate and no auto-scheduling — the drop-in does nothing until its cron is scheduled and a request carries the matching secret. Two operator scripts ship in `scripts/` (zip-excluded): `schedule-cache-warmer.sh` (idempotently schedules the tick at the minute recurrence) and `unschedule-cache-warmer.sh` (deletes the event and removes the secret option + lock transient); both pass extra args through to `wp`.

### Changed

- **Internal elegance refactor (behavior-preserving).** Named `BYTES_PER_MB`/`MAX_BUCKETS` and reused `MAX_PAYLOAD_SCAN_LENGTH` for bare literals, extracted `Log_Manager::emit_orphaned_complete()` (deduped two identical orphan-drain loops), and routed `message()` URL redaction through the existing `redact_url()` helper. No behavior change; full PHPUnit suite green (1686), PHPStan + PHPCS clean.

- **PHPStan raised to level 10** (`phpstan.neon.dist` `level` 9 → 10), all 274 errors cleared. Level 10 stops treating `mixed` leniently; fixes narrow each previously-untyped value with real types, `is_*` guards, and coercion casts. One shared stub was added — `.phpstan/stubs/getrusage.stub.php` declares PHP's documented `getrusage()` return shape (the keys are optional so the caller's `$r['ru_…'] ?? 0` reads stay valid) — clearing the `Log_Manager::log_resources()` arithmetic/`sprintf` errors at the root. A multi-agent review (adversarial verify) caught that the fan-out had relabelled `Server_Registry::get_all()`'s return key type as `array<string,…>` (a lie — PHP coerces numeric-string array keys to int, so integer keys genuinely occur) and deleted the `is_string( $server_id )` guards in `Remote_Manager`; the key type is restored to the honest `array-key` (on `get_all()`/`get_servers()`/`get_enabled()`) and the `Remote_Manager` health-check / settings-sync loops normalize the key with `(string)` (behind an `is_scalar` guard where the source value is `mixed`) so a server's real identity reaches `get()`/HMAC/logging. Tracking that down surfaced a real latent bug, fixed below. A subsequent xhigh `/code-review` also caught two copy-on-write performance regressions the narrowing had introduced — `Request_Builder_Node::push_stack`/`pop_stack` and `Reqgrep_Command::append_to_state` were refactored from in-place mutation to copy-into-local + write-back, which COW-duplicated the entire stack/profiles/lines array on every push/pop/append (O(n²) on the profiler and firehose-tail hot paths); restored to in-place mutation via property references. Behavior-preserving for string ids; full PHPUnit suite green (1691), no new `@phpstan-ignore`.
- **PHPStan raised to level 9 + phpstan-strict-rules adopted** (`level` 8 → 9; strict-rules added as a dev dependency, the full set EXCEPT the WordPress/node-runtime idiom-fighters — `empty()`, truthy conditionals, short ternary, `noVariableVariables` (the cache-warmer decorator's dynamic delegation to `WP_Object_Cache`), and `checkDynamicProperties`). All mixed-access + strict violations cleared with behavior-preserving narrowing plus redundant-cast removal and explicit `array_filter` callbacks. `Server_Registry::get_all()`'s return key type is corrected to `array-key` (PHP coerces numeric server-id keys to int, so a `is_string()` guard on the keys is meaningful, not dead). Behavior-preserving; full PHPUnit suite green (1690).
- **PHPStan raised to level 8** (`phpstan.neon.dist` `level` 7 → 8), all nullsafety errors cleared. `Servers_CI_Node::public_shape()` got `array<string, mixed>` PHPDoc; `Remote_Source_Node::ensure_multi()`/`remove_node()` capture `curl_multi_init()`/`$this->multi` into a local before the intervening `$this`-passing `register_curl_handle`/`unregister_curl_handle` calls (PHPStan invalidates property-narrowing across those); and `Request_Builder_Node::$flight` + `Reqgrep_Command::$inflight` — both unconditionally set in the constructor / run-setup before any guarded method runs — gained `require_*`-style assert-non-null getters (matching the existing `require_registry()` pattern) routed through their call sites. No runtime-path changes; the new throws are unreachable in normal operation. Behavior-preserving; full PHPUnit suite green (1690), phpcs clean.
- **Flame graphs prune negligible frames before rendering.** `FlameGraph` now runs the incoming tree through `pruneFlameGraph` (a pure, exported helper) before handing it to d3-flame-graph: frames smaller than 0.1% of the total (root) time are dropped — since a child's value never exceeds its parent's, a frame below the cutoff takes its whole subtree with it — and if the result still exceeds 1000 nodes the cutoff is raised to keep only the largest 1000. This clears the long tail of sub-pixel slivers that cluttered deep graphs and bounds DOM/render cost. The root is always kept and the input tree is not mutated.
- **Helper-created sibling nodes follow the same name + patron + interpreter-sink discipline (Phase 2–3, PHP).** The plumbing nodes that application helpers/nodes create — `Job_Intake`'s write Partition, `Stream_Merger_Node`'s offsetlog Partition, `Request_Builder_Node`'s `Flight` sibling, `Flame_Builder_Node`'s `Auto_Tuner`, the `Performance_CI` request-scope scratch Partitions, and `Reqgrep_Command`'s read Partition — are now named, `patron()`-linked so `dump_metadata` hides them from the canvas (self-patron where the owner isn't a `Node`), and sunk into the in-scope `_command_interpreter` when they have no specific sink of their own (skipped when none is in scope). `Log_Manager` now uses the canonical bare name `firehose:topic` and REUSES an existing one when present — the aggregator topology's `make_node Topic firehose:topic`, or a concurrently-suspended parent job context's — so there is exactly one firehose Topic per process (every context shares the N-partition writer, which self-routes by `KEY=request_id`); this also collapses the aggregator's prior two-Topics-over-one-`firehose.log` into one. `Stream_Merger_Node::remove_node()` now tears down its named offsetlog (+ its `:config`) so a removed merger doesn't leak it in `Core`. The dead `create*` view-node factories across all dashboards are removed (built via `make_node` / `registerNodeClasses`; node-unit tests construct the classes directly). Behavior-preserving; full suite green.
- **Dashboard graph nodes are created through the interpreter's `make_node`, not bare `new` (matches the `newspack-nodes` Phase 1 sweep).** Every dashboard graph-build hook (`performance-dashboards`, `performance-request-log`, `performance-gyroscope`, `performance-logger`, `event-aggregator`, `aggregator-admin`) now builds `SseIn`/`HttpOut`/`Heartbeat` and its own view/command nodes via `interpreter.makeNode( 'ShellName', name, args )` (which names + sinks them). The dashboard-specific classes (`Performance*`, `PerfErrorsView`, `RequestLogView`, `GyroscopeView`, `HookCatalogView`, `AggregatorView`, `ServersView`) are registered into the interpreter's `includeNodes` via `registerNodeClasses` in a per-dashboard `nodes/register.js` (imported by the hook + bundle entry). Mount timing unchanged (the hooks keep their `useEffect` `mountExospine`). The `viewName` (and `maxEntries`) defaults moved into the node constructors so a no-arg `make_node` construction preserves them. Behavior-preserving; all wiring preserved. Full JS suite green.
- **`log_urls` and `skip_urls` now share one matching scheme — a prefix match against the request path with a `?` terminator.** The query string is stripped and a `?` is appended to the path, then patterns prefix-match it; because the path can't otherwise contain `?`, a pattern ending in `?` becomes an EXACT match while one without is a plain PREFIX. So `log_urls => ['/?']` logs only the home page, `['/news?']` logs only `/news`, and `['/news']` logs anything under `/news` — and `skip_urls` behaves identically. Both filters compile through one helper (`Log_Manager::compile_url_filter`) to a start-anchored, grouped `/^(?:a|b)/i`. This replaces the previous split behavior (`skip_urls` matched as a substring anywhere; `log_urls` was an exact path match). Note `skip_urls` is now anchored to the start of the path rather than matched anywhere. Six `LogManagerTest` cases pin the prefix, trailing-`?` exact, home-page, query-string stripping, per-alternative grouping, and the shared skip/log scheme.
- **Service interpreters take normal commands with arguments — the request-side command `payload` field is gone (matches the `newspack-nodes` substrate).** Every `App\*_CI` verb parses its input from the `arguments` string via the substrate's `Command_Args` grammar (positional required args, `--key=value` options, bare `--key` flags, comma-lists, quoted values) instead of a structured `payload` slot: `Performance` (overview / urls / url_detail / request_search / request_detail / hooks_configure / config_update / settings_update / request_log_*), `Servers` (`get`/`delete`/`test <id>`, `add`/`update <id> --url=… --enabled=… --logs=…`), `Settings.update` (`--<key>=<int>` partial), and the nullary read verbs. The hub→spoke forwarder (`Remote_Manager` / `Remote_Source_Node`) builds the same argument string the spoke verb parses — settings-sync → `--<short>=<value>`, perf → `--option=<opt> --value=<v>`, heartbeat → positional `<slot> <ttl> <partition>` — via `Command_Args::format`. JS dashboard callers build their args with `formatCommandArgs`. Behavior-preserving; full suite green. Requires a `newspack-nodes` substrate that ships `Command_Args`.
- **PHPStan raised to level 7.** Builds on the level-6 value-type work: the few unguarded builtin returns are made safe — `filemtime()` enqueue versions cast to `string`, `wp_json_encode()` results feeding `esc_attr()`/`strpos()`/`explode()` coalesce to `''`, `current_filter()` coalesces to `''`, `Hook_Categorizer` guards a failed config read (behind a testable file-read seam) and returns its documented default, and `LRU_Cache` restore casts its bucket index to `int`. Behavior-preserving; a new test covers the unreadable-config path. Full suite green.
- **PHPStan raised to level 6.** The static-analysis gate now enforces value types on every iterable (`array<…>`); all method/parameter/return/property arrays carry explicit shapes. Substrate-wide this is PHPDoc-only (the 7-field positional Message as `array<int, mixed>`, stats/flame accumulator maps as `array<string, mixed>`, hook-name lists as `array<int, int|string>`). No runtime behavior changes.

### Removed

- **Confirmed-dead code surfaced by a reachability audit** (verified against the service-CI verb tables, REST/CLI, WP hooks, dynamic dispatch, and the sibling plugins). No behavior change; full suite green (1675):
  - `Flame_Builder_Node::stats_count()` and `Request_Builder_Node::cache_size()` — public accessors with no production caller (the `GET_STATS` / `GET_CACHE` verbs inline their own count loop). The tests that used them now assert through those production verbs.
  - `Admin::sanitize_aggregator_servers()` — no longer a `register_setting` sanitize callback; `aggregator_servers` is written only by `Server_Registry` CRUD.
  - `Hook_Categorizer::get_color()` / `categorize_many()` — no caller (production uses `categorize()` / `get_categories()`).
  - `LRU_Cache::is_empty()` and `Job_Intake::get_partition()` — unused accessors.
  - The duplicate `DASHBOARD_REFRESH_OPTIONS` export in `src/performance-gyroscope/constants.js` (and its test) — a verbatim copy of the live `performance-dashboards` constant; gyroscope imports only `INFLIGHT_REFRESH_OPTIONS`.
  - `Config::coerce_option_value()` and the read-time overlay coercion — the application's `array_strings` options are stored in their typed array shape at write time, so the per-request read no longer re-coerces (the substrate `newspack-nodes` makes the matching write-time fix for `memcache_servers`).

### Fixed

- **`Cache_Warmer_Tick_Node`'s numeric arg now sets the warm-enqueue interval in seconds.** It was a
  double no-op: `arguments()` passed the value to `set_timer( (int) $args )`, but that takes
  MILLISECONDS, so `'30'` armed a 30ms timer (and silently swapped the efficient `_router` heartbeat
  hitchhike for a busy `Event_Framework` slot); and `fire()` debounced on the hardcoded const
  (`INTERVAL_SECONDS = 60`), so the arg could never change the warm cadence. The numeric branch now
  keeps the router hitchhike and sets a per-instance `interval_seconds`, which drives both the
  `fire()` debounce and the value threaded into the job (`parameters['interval']`). `handle_job()`
  reads that threaded interval for its stale-drop (const fallback for old/in-flight jobs), fixing
  the real bug where an interval > 60 would wrongly drop valid jobs.
- **Numeric server ids are no longer silently destroyed at registration.** `Server_Registry::get_all()` merged config-file defaults with the WP-option servers via `array_merge()`, which **renumbers integer array keys** — and a purely-numeric server id (accepted by `is_valid_id()`'s `[a-zA-Z0-9_-]` pattern, and stored by PHP as an int key) is exactly such a key, so a server registered as e.g. `5` was silently reindexed to a positional `0`: its id was lost and it could collide with other numeric ids. Switched to the key-preserving union (`$option + $config_defaults`, identical option-wins precedence) so every id survives intact, and `Remote_Manager`'s health-check / settings-sync loops now `(string)`-normalize the key (instead of skipping non-string ones) so numeric-id servers are actually health-checked and synced under their real identity. Regression test added (`ServerRegistryTest::test_numeric_server_id_survives_get_all`).
- **Cache warmer renders anonymously so the warm-up actually populates the cache.** The authenticated loopback (so the edge cache forwards to PHP) made the warm render *logged-in*; Newspack only attaches its block-cache **write** filter for non-editors (`Newspack_Blocks_Caching::setup_block_caching` gates on `! is_user_logged_in() || ! current_user_can('edit_posts')`), so the warm render rebuilt the homepage but cached nothing — real visitors still hit cold seconds later. The warm request now forces `determine_current_user` to `0`: the `Authorization` header still gets it past the edge, but in WP it's anonymous, so block caching stays enabled and populates the anonymous cache real visitors read.
- **Cache warmer actually bypasses the caches now (cold-group prefix match + authenticated loopback).** Two reasons it was hitting caches instead of forcing a rebuild: (1) the cold-read decorator matched cache groups exactly, but Newspack's block cache splits into per-page/feed variants (`newspack_blocks-post-{ID}` for a static-Page homepage, `newspack_blocks-feed`), so cooling `newspack_blocks` missed the homepage's real group — now matched by **prefix** (`{group}` cools `{group}` and `{group}-…`). (2) The loopback was anonymous, so an edge/page cache served it a cached homepage and the render never reached PHP — it now sends an `Authorization: Basic` application-password header so the proxy forwards to PHP. The credential is read **in the job-worker process** (never written to `jobs.log`) from the `eln_cache_warmer_auth` option, **encrypted at rest** with libsodium keyed off `wp_salt('auth')` (mirrors `Server_Registry`; DB-only access can't decrypt), or from the `NEWSPACK_CACHE_WARMER_AUTH` wp-config constant (wins). The `schedule-cache-warmer.sh` script prompts for it (silently, via `wp eval` stdin → never a CLI arg) and `unschedule-cache-warmer.sh` clears it.
- **Hook instrumentation no longer wraps by-reference callbacks (fixes a PHP warning + a silently-lost mutation).** `App\Core::wrap_callbacks` replaces each hook callback with a timing closure that reads its args via `func_get_args()` + `call_user_func_array()` — both of which copy, so a callback declaring a by-reference parameter (e.g. VIP's `vip_es_disable_advanced_post_cache( &$query )` on `pre_get_posts`) was handed a value: `Argument #1 ($query) must be passed by reference, value given`, and the callback's mutation of `$query` was dropped. A new `callback_has_ref_param()` reflects each callback (handling every `$wp_filter` callback form — bare function, `Class::method` string, `[obj, method]`, `[Class, staticMethod]`, Closure, invokable) and `wrap_callbacks` now skips any that declares a `&` parameter, leaving it un-instrumented but correct; a reflection failure falls back to the prior wrap behavior. Adds an `AppCoreTest` case.
- The debug overlay / shared Newspack Nodes header now reports the **runtime** version instead of this plugin's. `NewspackNodesData.version` (read by the shared `Header` / debug overlay, which are part of the `newspack-nodes` runtime) is localized to `NEWSPACK_NODES_VERSION`, not `NEWSPACK_EVENT_LOGGER_NODES_VERSION` — so the overlay and the topology console no longer disagree (e.g. `0.10.0` vs `0.10.2`). No fallback: ELN loads after `newspack-nodes` defines the constant, and if it's absent the runtime ELN depends on isn't loaded. The script cache-bust version stays this plugin's.
- Added the missing `Author: Automattic` plugin header so the Plugins screen shows "by Automattic" (matching Newspack Nodes).
- The three remote-aggregation sanitize callbacks (`sanitize_remote_num_segments` / `_segment_size` / `_max_lifespan`) again accept `null`: their parameter is typed `int|string|null` and the `null === $value` guard is restored, so a WordPress `sanitize_callback` invoked with `null` (unset option) returns `''` instead of raising a `TypeError`.

## [0.10.0] - 2026-05-31

### Changed

- The command interpreter is spelled `interpreter` throughout (variables, comments, docs); the `mountExospine()` return key is `interpreter`; service-CI `*_CI_Node` identifiers keep `CI`. Node subclasses carry a `_Node` suffix with `class-*-node.php` / `*-node.js` filenames.
- JS view/command node classes declare `accepts_fill` / `has_target` in `nodeSchema()` (view nodes `has_target: false`; the performance command node `accepts_fill: false`) so the canvas draws the right ports.
- **Removed every `eslint-disable` directive from the JS; the code now lints clean without suppressions.** Same config posture as the `newspack-nodes` sibling (kept in lockstep): `no-bitwise` off (the Message `TYPE` is a bitmask), `no-console` allows `warn`/`error`, `no-unused-vars` honors `^_`, and a `scripts/**/*.mjs` override (Node globals, console, jsdoc). Declared `react`/`react-dom` as devDependencies — the test renderer imports them directly (they're transitive deps of `@wordpress/element`), which clears the `import/no-extraneous-dependencies` suppressions across the test suite.

### Fixed

- **Aggregator hub crash: `Stream_Merger_Node` and `Health_Check_Tick_Node` are now `Timer_Node` subclasses.** The `newspack-nodes` v0.10.0 substrate changed `Router::notify_timer()` to call each TIMER registrant's `fire_cb()` DIRECTLY (no TM_INFO message). These two nodes still hand-registered on the Router TIMER (`$router->register('TIMER', …)`) and ran their tick from a `fill()` TM_INFO/`KEY=TIMER` branch — so on every heartbeat the worker drain loop fatally errored `Call to undefined method …::fire_cb()`, taking the whole aggregator worker down. They now `extends Timer_Node`, register via `set_timer()` (router-hitchhike, with automatic TIMER-unregister on `remove_node()`), and run their periodic work in a `fire()` override — `Stream_Merger`'s `tick()` and `Health_Check_Tick`'s `maybe_enqueue()` renamed to `fire()`, the dead `fill()`-TIMER branches removed. The JS dashboard hooks' `HeartbeatNode` was migrated the same way (`router.register('TIMER', …closure)` → `heartbeat.setTimer()`). Requires `newspack-nodes` >= 0.10.0.
- **The debug overlay's "Reset Graph" now actually rebuilds the graph on all six dashboards.** The six dashboard graph hooks (`useRequestLogGraph`, `useErrorLogGraph`, `useGyroscopeGraph`, `useAggregatorStatusGraph`, `useAggregatorAdminGraph`, `usePerformanceGraph`) called `mountExospine()` bare, so `Core.reinit` stayed null and the overlay's Reset Graph silently no-op'd. They now build through `mountExospine( build )`: the substrate snapshots `Core` around the build callback so `reinit()` tears down + rebuilds exactly the build-registered nodes (the `_command_interpreter`/`_router` backbone survives), and a monotonic `buildCount` bump re-subscribes each dashboard's `useNodeState` to the freshly-registered view node. Matches the `newspack-nodes` Raw Logs / Worker Status template. (`useHookCatalogGraph` is intentionally excluded — the performance-logger page renders no overlay.)
- **Reset Graph no longer strands an in-flight request.** Removing a view node that holds a `pending` Promise map (`servers:view`, `performance:view`) left the awaiting Promise unsettled, so a Reset-Graph (or unmount) mid-reply hung the caller — stuck "busy" buttons in the Configured-Servers admin, a stuck spinner on the Performance dashboard. `servers:view.removeNode()` now rejects its pending CRUD promises (so the caller learns the mutation didn't complete); `performance:view.removeNode()` resolves its read-only `resolveOnly` promises with `null` (the methods' canonical "no data" return, so no spurious error banner).
- **Reset Graph while paused no longer desyncs the stream indicator.** On the Request Log / Error Log dashboards the hook's `isPaused` survives a reinit, but the rebuilt view's constructor defaults `paused: false` — so the UI showed "live" while the connection effect (correctly) kept the SSE stream closed. The hooks now re-apply the surviving pause to the freshly-built view.
- **`ServersAdmin` "Remove server" uses an in-app confirm modal instead of `window.confirm`.** Built from the stable `@wordpress/components` `Modal` + `Button` (the idiom the settings modals already use), not the experimental `__experimentalConfirmDialog`. Confirm-to-remove / cancel-aborts behavior is preserved; removes the `no-alert` suppression.
- **`FlameGraph`'s D3 container is `role="presentation"`** (it owns only auxiliary mouse handlers; D3 builds the interactive SVG inside), dropping its `jsx-a11y/no-static-element-interactions` suppression.

### Removed

- **`Events_CI::recent` verb removed (dead code).** No consumer ever called it — no dashboard JS, no topology, no other PHP caller; `wp nodes reqgrep --recent` walks firehose segments directly via `get_segments()`/`read_at()`, not the verb. It was also the only consumer of the substrate Partition's default-binary `.idx`, which `newspack-nodes` removed in the same change. The `stats` verb (the live event-dashboards surface) is unchanged.

## [0.9.1] - 2026-05-29

### Fixed

- **Performance URL table "Min" column no longer renders `9,223,372,036,854,775,807` (PHP_INT_MAX) for untimed-only URLs.** URLs whose requests carry no timing samples (worker requests, timed-out requests: `count > 0`, `timed_count === 0`) end their per-URL bucket with the `min_ms = PHP_INT_MAX` sentinel. Two mismatched sentinels (pending side `PHP_INT_MAX`, persisted side `0`) let the sentinel poison the persisted hourly URL index in memcache, and the dashboard surfaced it verbatim. Fixed at two layers, both keyed off `timed_count`: the flame-builder URL-index merge folds `min_ms` only from buckets with `timed_count > 0` (keeping the persisted value at 0 for untimed-only URLs and never letting the sentinel reach memcache); and the performance-CI read path now mirrors the write path — it folds `min_ms` only from buckets with `timed_count > 0`, so an untimed-only bucket (carrying `min_ms` 0 or a poisoned `PHP_INT_MAX`) never clamps the merged minimum across partitions/buckets and any already-poisoned memcache entries heal to 0 at display immediately, before TTL/salt-rotation clears them. Mixed timed + untimed URLs still report the real timed minimum.

## [0.9.0] - 2026-05-29

### Fixed

- **`<eln:is_hub>` and `<eln:significant_events_csv>` no longer resolve to null.** The `<eln:…>` resolver closure listed both keys as owned but resolved them via `Config::load_config()[$key] ?? null` — neither is a real Config key. `$config['is_hub']` was always null, so `cmd flame-builder:config set_is_hub <eln:is_hub>` in `topologies/request-workers.tsl` always set the empty string, and the flame builder's CSV-of-significant-events was always empty. Extracted the resolver to a public static `Config::resolve_eln_token($key)` shared by the production bootstrap AND `tests/bootstrap.php` (no drift): `is_hub` now derives `(bool) ! empty( $config['enable_aggregator'] )`; `significant_events_csv` imploded from `$config['significant_events']` (with an `is_array` guard against malformed values).

### Changed

- **All three SSE dashboards' chains collapsed to `_sse → <dash>:view`.** `requestlog`, `gyroscope`, and `perferrors` each had a `_sse → :route → :transform → :view` chain. The route nodes were dead in every one — they checked `KEY === 'connection'` but the substrate's `SseConnector` uses `KEY === 'connected'` AND snoops it off before routing, so the control-target branch was unreachable. The transforms did real shape-mapping (URL/UA clipping for requestlog; KEY-shape multiplexing for gyroscope's `inflight` vs `complete`; rid promotion + m-clipping for perferrors), but the info was all in the envelope and could be inlined into each view's `fill()`. Eighteen files deleted across the three dashboards (6 per dashboard: route + transform + transform-line helper + each one's test). Each hook now mounts just `_sse + _http + _heartbeat + <dash>:view`. Same architectural mistake I made in the v0.7.0/v0.8.0 substrate-I/O migrations (not "inherited from a template"); the sibling Raw Logs dashboard in `newspack-nodes` collapsed in lockstep.

### Tests

- **Three smoke-shaped `SettingsSyncTest` tests strengthened** from `$this->assertTrue( true )` to full queued-job envelope assertions. Each now isolates the write via `make_temp_dir()` + `use_base_dir()`, walks `{$base_dir}/logs/jobintake.log/p*/*.log` via a new `read_jobintake_envelopes()` helper (mirrors `JobIntakeTest::read_all_jobintake_lines()`), unpacks the Tachikoma Messages, and asserts on the full envelope: exactly-one-queued, `k='job'` + `handler='remote_manager'`, `parameters.action='sync_setting'`, the resolved option name (REMAP applied for `SYNCED_OPTIONS` vs verbatim for `PERF_TUNING_OPTIONS`), `parameters.endpoint===Settings_Sync::ENDPOINT` vs `PERF_ENDPOINT` based on which list the option lives in, `parameters.value` matches defaults-substitution-resolved value vs raw forwarded value, `parameters.queued_at` is int. Verified the assertions are real via a sanity-check mutation. The three tests had been correctly minimal under the v0.5.0-retired `enable_workers` gate; the gate's removal made them no longer assert anything meaningful.

### Removed

- **`Performance_Controller_Base` + its test deleted.** Defined but used only by its own test — no production CI ever extended it. The entire `includes/rest/` directory is now gone. `composer dump-autoload -o` regenerates the classmap. `tests/integration/M2BootstrapTest.php` gains a regression guard so a future revert would fail.
- **Stale `enable_workers` references stripped from test fixtures.** The option was retired in v0.5.0; 7 test config fixtures + 3 `SettingsSyncTest` setup blocks (which set `$GLOBALS['_wp_options']['newspack_nodes_enable_workers'] = '1'` expecting it to gate the dispatch path) were dead. A new `tests/unit/RetiredConfigKeysTest.php` asserts no fixture references retired keys (extensible as more get retired).
- **Stale doc-comments fixed**: `class-health-check-tick.php`'s `maybe_enqueue()` docblock claimed it gates on `enable_aggregator` strictly true (the body has no such check); `class-auto-tuner.php` referenced `PerfSettingsController` which was deleted in the service-CI cutover (updated to point at the `Performance_CI_Node::settings_update` verb).

### Changed (legacy CIs migrated to schema-driven dispatch)

- **`Discovery_CI_Node`, `Logger_CI_Node`, `Status_CI_Node`** migrated from `extends Command_Interpreter_Node` + in-constructor `$this->commands([...])` to `extends Service_CI_Node` + `node_schema()['commands'][]` with inline `'handler' => static fn ...` closures. Handler bodies are byte-for-byte the legacy closures — only dispatch wiring changed. Read-only verbs keep their no-auth status. `AppNodeSchemaCoverageTest` now covers all 8 application CIs uniformly (the three migrated ones declare their own `node_schema()` and are auto-scooped by the classmap walk).

### Docs

- **Three rounds of doc audit** against the v0.5 → v0.8 application + dashboard cutover (`enable_workers` retirement, `SSEControllerBase` deletion, every dashboard onto substrate `_http`/`_sse`/`_heartbeat` with the canonical pending-Map view contract). AGENTS.md / API.md / ARCHITECTURE.md / README.md and all three `.claude/skills/*/SKILL.md` files audited against current code. The biggest catches: `event-logger-nodes-workflow/SKILL.md`'s `enable_aggregator` polarity was REVERSED (it said "Default ON; OFF only when explicitly 0" — actual default is OFF, hubs opt in); `event-logger-nodes-debugging/SKILL.md`'s SSE section described `SSEControllerBase` as live (it was deleted in M6.10); API.md's per-CI verb tables had specific shape errors (`raw-logs` verbs misnamed, `workers` 5th verb misnamed, `topologies` missing `connect_worker_input`, `Performance_Controller_Base` "shared helper" framing in multiple files when no CI uses it).

## [0.8.1] - 2026-05-28

### Tests

- **Two under-80% files lifted above the coverage floor.** `aggregator-admin/index.js` (0% → 100%) via mount-entrypoint tests in the shared `mount-entrypoints` suite (verifies mount-on-container + no-op-without-container). `performance-request-log/RequestStream.js` (78.3% → 97.5%) via 7 real-branch tests covering `formatTime` falsy-timestamp, `toggleColumn` add/remove, rAF `lastEventTime` propagation, `handleScroll` save/restore branches, the rAF scroll-position maintain branch, and the rAF offset snap-to-zero threshold. All exercise observable DOM/state changes through real scroll/click events — no mock-only assertions. Two architecturally-unreachable defensive branches left uncovered (StreamRow switch default; handleScroll `isAdjustingScrollRef` early-return — both flagged with rationales).

### Notes

- CI workflow infrastructure changes only — no runtime behavior change in this plugin's own code. The debug overlay it inlines from `@newspack-nodes/runtime` picks up that runtime's v0.8.1 fixes (atomic `sync-shared`, z-index bump, debug overlay drops dead `_uptime`).

## [0.8.0] - 2026-05-28

### Changed

- **All four remaining dashboards migrated onto the substrate I/O backbone.** Closes the dashboard-migration sweep started in v0.7.0: every dashboard now rides the substrate's `_http` / `_sse` / `_heartbeat` pattern. The legacy `getCommandClient` / `unwrapCommandResponse` / per-dashboard `*Command` / `*Stream` Node pattern is fully retired in this plugin.
- **`useAggregatorAdminGraph` (Configured Servers admin)** migrated to the substrate `_http` pattern. Drops `serversCommand`. Hook mounts spine + `_http` + `servers:view`. CRUD callbacks build TM_COMMANDs (TO=`_http/servers`, unique `message[ID]`) and fill into the CI; the view's `fill()` matches `message[ID]` against `pending` to resolve/reject the hook's Promise. Mutations chain a fire-and-forget re-list on success (replaces the legacy `window.location.reload()`). Also drops the now-dead `api.js` + its tests.
- **`useHookCatalogGraph` (Performance Logger settings)** migrated to the substrate `_http` pattern. Drops `hookCatalogCommand`. Hook fires one `hooks_registered` TM_COMMAND on `isOpen` transition. On rejection the hook routes a synthetic empty-catalog reply THROUGH the interpreter (canonical path — router peels TO=`hookcatalog:view` and delivers) so the substrate boundary stays clean.
- **`useGyroscopeGraph`** migrated to the substrate `_sse` pattern. Drops `gyroscopeStream`. Hook mounts spine + `_sse` + `_http` + `_heartbeat` + the existing `gyroscope:route`/`transform`/`view` chain. `_sse` subscribes to `gyroscope`; `_heartbeat.target = '_http/workers'`. Page-visibility drives `sse.start()` / `sse.close()` + `heartbeat.clearSlot()` on close. The connection-status banner (`gyroscopeView.connectionError`) stays dormant — `_sse` / SseConnector emits `connected` on open but has no parallel error signal, matching the v0.7.0 request-log dashboard behavior.
- **`usePerformanceGraph` (Performance Dashboards)** migrated to the substrate `_http` pattern. The `performance:command` Node is kept as the **slice-tagging command-builder** (emits `{action:'loading', slice}` controls before each TM_COMMAND so the view treats different verb slices independently); it no longer owns the transport. TM_COMMANDs go through the CI (TO=`_http/performance`, FROM=`performance:view`); the reply pivots TO=FROM; the view's `fill()` matches `message[ID]` against `pending` to apply the result to its slice (or resolve a `resolveOnly` Promise for ad-hoc lookups). `performance:view` gains the canonical pending-Map gate + `_errorMessage()` helper. A pre-mount `client.send` fallback for `request_search` is retained for `?request=` deep-link timing (the `useUrlNavigation` mount fires before `usePerformanceGraph`'s mount populates the command ref).
- **`useErrorLogGraph` (Performance Error Log)** migrated to the substrate `_sse` pattern. Drops `perfErrorsStream`. Hook mounts spine + `_sse` + `_http` + `_heartbeat` + the existing `perferrors:route`/`transform`/`view` chain.
- **The canonical view contract from `servers:view` is now consistent across all command-driven dashboards:** pending-matched TM_ERROR rejects the Promise without polluting global view.error (per-call surface is the caller's catch — a row-level snackbar or in-form notice, not a table-wide banner); `_errorMessage()` helper handles both string and structured `{ message }` TM_ERROR payloads; `updateServer` and similar id+partial verbs spread the partial FIRST so the positional id wins (`{ ...partial, id }`).

## [0.7.0] - 2026-05-28

### Changed

- **Request Log dashboard piloted onto the substrate's `HttpOut` + `SseIn` + `Heartbeat` triad.** The bespoke `requestlog:stream` Node is deleted; `useRequestLogGraph` mounts the runtime spine (`_sse`, `_http`, `_heartbeat`, `_completion`, `_metadata`, `_uptime`, `_cwd`, `_output`) + `requestlog:route` / `requestlog:transform` / `requestlog:view`. `_sse` subscribes to `completed`; `_heartbeat` keeps the slot alive against `_http/workers` (request-scope path that bypasses the SSE demux); `requestlog:route` classifies on the connection-status marker. The pattern is Tachikoma rule #2: every node sinks into `_command_interpreter → _router` and steers flow through `target`. This is the first of seven dashboards to migrate; remaining dashboards (aggregator-admin, performance-dashboards, performance-gyroscope, performance-logger, and the Nodes-side useRawLogsGraph / useWorkerStatusGraph) are planned for the next release.
- **Event Aggregator dashboard migrated to the same pattern (poll-only shape).** The bespoke `aggregator:poll` Node is deleted; `useAggregatorStatusGraph` mounts spine + `_http` + `aggregator:view`. Hook owns the `setInterval` that fills a `TM_COMMAND` (FROM=`aggregator:view`, TO=`_http/aggregator`) into the interpreter; HttpOut POSTs; the server's reply routes back via the TO=FROM pivot. No SSE since there's no subscription. Template for the remaining poll-only dashboards.

### Fixed

- **`tests/run-coverage.sh` guards against running as root.** `Log_Manager` refuses to run as root (Atomic permission contract); the suite was silently producing 37 false `LogManagerTest` failures when invoked via `docker exec` (default root). The script now hard-fails with a usage hint (`docker exec -u bend …`) when `id -u` is 0, and cleans the actual test artifact dir `/tmp/event-logger-nodes-test` (the old line cleaned the wrong path).

## [0.6.0] - 2026-05-27

### Changed

- **`Aggregator_CI_Node` and `Servers_CI_Node` migrated to no-arg ctor + public-property dep injection.** Their `Server_Registry $registry` ctor dependency becomes a public nullable property assigned by the bootstrap (`$ci->registry = $registry;`) immediately after `make_node` returns the constructed instance. Last two app CIs with programmatic-dep positional ctors — they were the reason the substrate's `make_node` carried a ctor-param-count conditional through Tasks 7-10. With this change, every `make_node` call across both repos goes through the uniform Tachikoma sequence and the conditional is gone (substrate side).
- **App Node classes migrated to the Tachikoma idiom** (no-arg ctor + schema-driven `arguments()`): `Job_Worker_Node`, `Stream_Merger_Node`, `Remote_Source_Node`, `Request_Builder_Node`. Each declares `node_schema()['arguments']` with REAL defaults (class constants — `CACHE_FLUSH_INTERVAL`, `DEFAULT_BUCKET_SIZE`, etc., never placeholder strings) and overrides `arguments()` to chain `parent::arguments()` + re-normalize + re-derive, with the standard empty-string short-circuit. `Request_Builder_Node`'s `LRU_Cache` construction (which depends on `bucket_size`/`num_buckets`) moved into the override; `Stream_Merger_Node`'s owned `Health_Check_Tick_Node` sibling stays in the ctor (no positional-arg dependency). `Remote_Source_Node` adds a `configure()` setter for the production `Stream_Merger::add_remote()` callsite — middle-empty auth-token positional args can't survive whitespace tokenization, so production sets all 7 fields directly via `configure()` and writes a redacted summary to `$this->arguments` for `dump_config()`; the `arguments()` schema-walker path stays for tests / fully-populated TSL lines. Trivial cases (`Job_Router_Node`, `Request_Flight_Node`, `Auto_Tuner_Node`, `Health_Check_Tick_Node`, `Flame_Builder_Node`, `Events_CI_Node`, `Settings_CI_Node`, `Performance_CI_Node`) confirmed already aligned. `Aggregator_CI_Node` and `Servers_CI_Node` keep their positional `Server_Registry` ctor dependency (programmatic, not config) — they stay `category=Service` per `ServiceCiHandlerGuardTest`'s contract; the substrate's `make_node` ctor-count branch surfaces TSL-construction attempts as a clean `TypeError` ("can't TSL this"). Substrate's Task 8 closed Topic/Consumer/Tail/Log/Hook in lockstep.
- **Migrated to the substrate's Tachikoma-idiom `Topic_Node` / `Consumer_Node`** — `Log_Manager`'s Topic construction and the integration-test Consumer/Topic constructions now use `new Topic_Node(); $t->arguments("...")` / `new Consumer_Node(); $c->arguments("...")`. Mirror of the substrate's Task 8 change.
- **Migrated to the substrate's Tachikoma-idiom `Partition_Node` (no-arg ctor + `->arguments(...)`)** — every `new Partition_Node($base_dir, $partition, ...)` callsite in the app (Stream_Merger, JobIntake, Events_CI, Performance_CI's 6 chained-with-`->with_index()` forms, Reqgrep, plus tests) is now `new Partition_Node(); $p->arguments("...")`. Mirrors the substrate's Task 7 change. No behavior change to running graphs.
- **`node_schema()` field renamed `'ctor'` → `'arguments'`** to match the substrate's Tachikoma-parity rename. Every app Node (`Job_Worker`, `Stream_Merger`, `Request_Builder`, `Flame_Builder`, `Auto_Tuner`, `Job_Router`, `Job_Worker`, `Remote_Source`, `Health_Check_Tick`) and every app CI updated. Wire format unchanged.
- **`node_schema()` field renamed `'verbs'` → `'commands'`** to match the substrate's Tachikoma-parity rename. Every app Node (`Job_Worker`, `Stream_Merger`, `Request_Builder`, `Flame_Builder`, `Auto_Tuner`, `Job_Router`, `Remote_Source`, `Health_Check_Tick`) and every app CI (`Events_CI`, `Aggregator_CI`, `Servers_CI`, `Performance_CI`, `Settings_CI`) updated. Test fixtures updated. Wire format unchanged; same semantics, the misnomer dies.
- **Nodes migrated off the substrate's removed `mark_verb_invoked` recorder.** `Flame_Builder`, `Request_Builder`, and `Stream_Merger` now override `dump_config()` to emit their `cmd {node}:config set_X <value>` lines **from their own setter state** (only when non-default), matching the substrate's new pattern. The two verbs that were one-shot *actions* rather than config — `Stream_Merger`'s `load_remotes_from_registry` and `Health_Check_Tick`'s `start_periodic_tick` — are no longer verbs: they fire unconditionally from a lifecycle hook (`Stream_Merger::connect_node()` loads remotes once the sink+target are wired; the periodic tick rides the existing `name()` cascade). The corresponding `cmd …:config` lines were removed from `aggregator.tsl`.
- **Dashboards are being wired onto the newspack-nodes "exospine" (rule #2).** Each dashboard's JS-Node graph now clips onto the shared `mountExospine()` backbone (`_command_interpreter → _router`) imported from `@newspack-nodes/runtime`: every node sinks into the interpreter and steers flow purely with `target`/`TO` through the router — no bespoke `x.sink = <node>` chains, no `controlSink` side-channels. Node names moved from `name/leaf` to `name:leaf` (the router peels TO on `/`). Dashboards render identically; substrate-conformance only.
  - **Aggregator Status** (`aggregator:poll` → `aggregator:view`): poll-driven, no route node.
  - **Configured Servers** (`servers:command` → `servers:view`): command-driven, no route node.
  - **Hook Catalog** (`hookcatalog:command` → `hookcatalog:view`): command-driven, no route node.
  - **Gyroscope** (`gyroscope:stream` → `gyroscope:route` → `gyroscope:transform`/`gyroscope:view`): SSE-driven; the `controlSink` is replaced by a `gyroscope:route` classifier keyed on the stream-set `KEY='connection'` marker (data → transform, connection-status → view).
  - **Request Log** (`requestlog:stream` → `requestlog:route` → `requestlog:transform`/`requestlog:view`): SSE-driven; same `requestlog:route` classifier on the `KEY='connection'` marker. `pause`/`clear` stay hook-direct to the view.
  - **Error Log** (`perferrors:stream` → `perferrors:route` → `perferrors:transform`/`perferrors:view`): SSE-driven; `perferrors:route` classifier on the `KEY='connection'` marker; `pause`/`clear` hook-direct.
  - **Performance** (`performance:command` → `performance:view`): command-driven, no route node. (Error Log and Performance are separate admin pages; each mounts its own exospine.)

## [0.5.0] - 2026-05-27

### Fixed

- **`Stream_Merger_Node::remove_node()` now cascades its owned `Health_Check_Tick` sibling's `:config` CI.** It did a bare `Core::unregister_node()` on the child's name, leaving the child's auto-wired `{name}:health-check:config` interpreter orphaned in `Core` — a same-process name-recycle (in-process topology rebuild) would then collide on the orphaned name. Now calls `$this->health_check->remove_node()`, which cascade-unregisters the sibling CI. (Pre-existing leak — the child always had a CI — surfaced while reviewing the node_schema-handler migration.)

### Changed

- Migrated `Job_Worker_Node`, `Stream_Merger_Node`, `Request_Builder_Node`, `Flame_Builder_Node`, and `Health_Check_Tick_Node` to the substrate's node_schema-handler auto-wire pattern: each node's `:config` verb handlers now live in `node_schema()['verbs']` (one `handler` closure per entry) instead of a separate `config_verbs()` table + four lines of manual CI wiring (`new Command_Interpreter_Node` / `patron` / `commands` / `attach_interpreter`). The base `Node::__construct()` builds the sibling `{node}:config` CI from the handler-bearing verbs. `Job_Worker_Node` declares no config verbs, so it no longer gets a sibling CI (it never had operator-facing verbs). No behavior change to any existing `:config` verb.
- **Dropped plugin-owned topology curation.** `register_plugin()` is now called with just namespace + dir (no `names:` curation — the substrate catalogs every shipped `.tsl`). Removed the now-substrate-owned `topologies` key from the bundled config; `Status_CI` reports the substrate active set (`Bootstrap::get_topologies()`) instead of an ELN-config key. Per-deployment active topologies now live in the substrate (`newspack-nodes`) config's `topologies` key.
- **i18n infrastructure** (foundation for translating the dashboard UI): declared `@wordpress/i18n` as a dependency, enabled the `@wordpress/eslint-plugin` i18n ruleset (pinned to the `newspack-event-logger-nodes` text domain), added the `Text Domain` / `Domain Path` plugin headers and a `make-pot` script + `languages/` dir, and deleted the orphaned `eventAggregatorAdmin` `wp_localize_script` block (7 i18n strings with no JS reader — `ServersAdmin.js` hardcodes them inline; the live `NewspackNodesData` localize on the same handle is kept). (`build.mjs` already externalized `@wordpress/i18n → wp.i18n`.)
- Wrapped the dashboard UI strings for translation (`@wordpress/i18n` `__()`/`_n()`/`sprintf`, domain `newspack-event-logger-nodes`) across every dashboard — the Tier-1 streams/tables/modal (`LogEntriesTable`, `Inflight`, `RequestStream`, `ErrorLog`, `HookSelectorModal`) plus `ServersAdmin` (its inline status strings — formerly the deleted localize), the Performance dashboard and its Overview/UrlTable/UrlDetail/RequestDetail/chart components, `AggregatorStatus`, the settings modals, and the `constants.js` metric/breakdown option labels. `ConnectionBanner` consumers pass a translated `message`; `COLUMNS` label/tooltip values, category descriptions, and chart axis/legend labels are wrapped, while object keys, comparison/map values, and unit/glyph tokens stay raw. The translation template `languages/newspack-event-logger-nodes.pot` (314 strings) is generated by `npm run make-pot`.
- Adopted the shared `ConnectionBanner` (synced from newspack-nodes) across every dashboard. Error Log + Aggregator Status swapped their bespoke banners onto it; Request Log + Gyroscope gained the SSE reconnect surface (`onStatus`→`controlSink`→`connectionError`, mirroring Error Log) and render it. Removed the orphaned `.event-logger-error-log-error` and `.aggregator-status-error` SCSS rules. Every dashboard's connection/reconnect banner is now identical.
- Removed the silent `?? '/tmp/newspack-nodes'` fallbacks for `base_directory` in the app CIs (`events`, `performance`, `servers`) + `Job_Intake`. Every reader now resolves through the strict `Config::get_base_directory()`, which throws when `base_directory` is unconfigured — so a misconfigured base fails loudly instead of split-braining the firehose (writer on the real dir, readers silently defaulting to `/tmp/newspack-nodes`).
- **Performance Dashboard rebuilt on the `performance/command` → `performance/view` node graph, completing the dashboards-as-JS-node-graph rollout.** A new `usePerformanceGraph` hook mounts the graph and owns all data fetching (overview, URLs, URL detail, request detail, the refresh-interval cadence, the server-filter/breakdown re-fetch, the debounced URL search, and the selection-driven detail fetches); `PerformanceDashboard` now reads the published view model via `useNodeState('performance/view','view')` and derives its render-time slices (breakdown fan-out, sticky server names, sorted requests, req/s, filtered stats) instead of holding data state. Removed `usePerformanceApi` — its verbs + validators now live in `performanceCommand`, which gained an `onError` seam (preserving the global error toast), a returning `fetchUrlBreakdown`, and a loading emit gated to the initial fetch so background URL-detail refreshes stay silent. `?request=` deep links resolve through the command client directly so they still open on first paint. No user-visible behavior change.
- Added the `performance/command` (multi-verb command-out) + `performance/view` (sliced data model with the `urlDetail` incremental merge) nodes — the Performance Dashboard data layer. Not yet wired in; the orchestrator rework that reads from `performance/view` follows in a later change.
- Error Log reimplemented as a `perferrors/stream → transform → view` JS-node SSE graph (`useErrorLogGraph`); `ErrorLog` is now a thin consumer; the reconnect banner is preserved via a connection-status surface on the view node. No behavior change.
- **Performance Logger hook-catalog fetch reimplemented as a `hookcatalog/command → hookcatalog/view`
  JS-node graph (`useHookCatalogGraph`); `HookSelectorModal` is now a thin consumer.** The settings
  page is a form (almost all local UI state), so only its single networked surface — the
  `{to:'performance', verb:'hooks_registered'}` fetch the modal fired on open — was nodified:
  `hookcatalog/command` (command-out — emits a synchronous `loading` then a `catalog` with the
  unwrapped `hooks_by_category`, falling back to an empty map on failure; injectable command seam +
  `close()` cancel guard) → `hookcatalog/view` (`setState('view',{hooksByCategory,loading})`) — wired
  by `useHookCatalogGraph`, which fires the fetch on open (not on an interval) and reads the model on
  the modal's behalf. The modal's search / expand / select-all / recommended / category-toggle / Apply
  logic and all SCSS are unchanged. The form-state components (`TagInputField`,
  `CustomEventSelectorModal`, `index.js`) are untouched. No behavior change.
- **Configured Servers (aggregator-admin) rebuilt as a React node-graph view, replacing the
  PHP-rendered table + jQuery IIFE.** The only converted dashboard that wasn't already React — a
  full rewrite: `servers/command` (CRUD command-out — `list`/`add`/`update`/`delete`/`test` on the
  `servers` CI, injectable seam + `close()` guard) → `servers/view`
  (`setState('view',{servers,loading,error})`) — wired by `useAggregatorAdminGraph` (list on mount,
  each mutation awaits then re-`list()`s, `setViewReady`). `index.js` now `createRoot`-mounts a React
  `<ServersAdmin>` into the `#event-aggregator-servers` div that `configured_servers_callback` emits
  (the PHP no longer renders the rows). The table / add-form / validation / test-status reuse the
  exact `wp-list-table` markup + class names; the post-mutation `window.location.reload()` is
  replaced by a re-`list()`. Capability gating (page-level `manage_options` + the per-verb
  `require_manage_options` in `Servers_CI`) is unchanged. (Known project-wide gap, not specific to
  this change: the React dashboards hardcode English; an `@wordpress/i18n` sweep across all of them —
  plus deleting the now-orphaned `eventAggregatorAdmin` localize — is tracked separately.)
- **Aggregator Status dashboard reimplemented as a JS-Node graph + thin React view.** First
  command-poll conversion in ELN (mirrors Worker Status, not the SSE dashboards): `aggregator/poll`
  (command-out — sends `{to:'aggregator', verb:'status'}` on the interval, captures the reply's
  server `TIMESTAMP`, injectable command seam, `close()` cancel guard) → `aggregator/view` (reshapes
  the per-server status map → array + connected/total counts, `setState('view',…)`) — wired by
  `useAggregatorStatusGraph` (owns the poll interval — no page-visibility gating, as before — plus
  the `setViewReady` re-render trigger). `AggregatorStatus.js` is now a thin view:
  `useNodeState('aggregator/view','view')` + the 1s "ago" tick; the ServerCard / PartitionStatus
  render, refresh-interval select, error banner, and all SCSS are preserved.
- **Gyroscope (in-flight requests) dashboard reimplemented as a JS-Node graph + thin React
  view.** Same SSE pattern as Request Log: `gyroscope/stream` (SSE-in, `subscribe=gyroscope`,
  replacing the shared `useMessageStream`), `gyroscope/transform` (`transformGyroscopeLine`),
  `gyroscope/view` (the rid-keyed in-flight model — upsert with a complete-wins guard, the
  one-refresh-tick-then-reap expiry, and the 10s requests/sec window, all ported verbatim) —
  wired by `useGyroscopeGraph` (page-visibility pause, reset-on-reconnect, `setViewReady`).
  `Inflight.js` is now a thin view: `useNodeState('gyroscope/view','view')`, and the refresh
  tick reads the model's `snapshot(maxRows)` / `rps` off the node. The visualization (renderCell
  age/lag geometry, legend, column picker, 0-9 refresh shortcuts, "Xs ago" ticker) + SCSS are
  preserved.
- **Request Log dashboard reimplemented as a JS-Node graph + thin React view.** Following the
  Raw Logs / Worker Status reference (nodes repo), the live request-stream data flow moved out
  of React effects into a graph that imports the substrate runtime via `@newspack-nodes/runtime`
  — `requestlog/stream` (SSE-in: EventSource + slot-heartbeat poke + reconnect, replacing the
  shared `useMessageStream`), `requestlog/transform` (`transformCompletedLine`), `requestlog/view`
  (the row buffer + requests/sec model) — wired by `useRequestLogGraph` (page-visibility pause +
  the `setViewReady` re-render trigger). `RequestStream.js` is now a thin view: `useNodeState(
  'requestlog/view','view')` for the low-freq model, and the virtualized list reads the high-freq
  buffer (`node.entries`/`.rps`) off the node each rAF — preserving DOM, SCSS, columns,
  virtualization, smooth-scroll, filter, RPS, "Xs ago", and Clear. `jest.config.js` gains a
  `moduleNameMapper` deduping React / `@wordpress/element` to ELN's copy (the runtime's React
  hooks resolve from the sibling checkout; mirrors the production WP-global externalization).

### Added

- **Runtime guard for the `newspack-nodes` dependency (`Substrate_Guard`).** ELN now
  verifies — deferred to `plugins_loaded` (via `Substrate_Guard::boot()`), once the
  substrate has loaded — that it is present and at/above its minimum version (`0.4.0`)
  with the APIs it calls (`register_plugin`, `register_config_namespace`,
  `Service_CI_Node`, `Command_Interpreter_Node`) before touching any substrate symbol.
  The check MUST run deferred, not at ELN's file-load: ELN sorts alphabetically before
  `newspack-nodes`, so the runtime isn't loaded yet when ELN's plugin file executes —
  checking then always saw it "not active" and disabled the whole plugin (no request
  logging, dead dashboards, command auth failing closed on a null `Core::$memd`).
  When the substrate is genuinely missing or too old, ELN shows an admin notice and
  bails gracefully — instead of fatal-ing at the first missing-symbol call or binding
  silently against a stale runtime. This complements the existing
  `Requires Plugins: newspack-nodes` header (which WP <6.5 doesn't enforce and which
  can't catch a present-but-outdated substrate). The plugin's own autoloader stays
  registered so the `00-newspack-profiler` mu-plugin can still resolve `Log_Manager`.

### Changed

- **App service CIs declare their verbs via `node_schema()` (schema-driven dispatch).**
  The five application service CIs (`Events_CI`, `Settings_CI`, `Performance_CI`,
  `Aggregator_CI`, `Servers_CI`) migrated to the substrate's schema-driven command
  mechanism: each verb is declared once in `node_schema()['verbs']` (name/description/args
  + a `handler` closure), and `Service_CI_Node`'s constructor builds the dispatch table
  from it — so their bespoke `verb_table()`/`__construct()` command wiring is gone. The two
  registry-backed CIs (`Aggregator_CI`, `Servers_CI`) keep a constructor only to store the
  injected `Server_Registry`, which their handlers reach via `$self->registry`. Because
  they now publish a `Service`-category schema, the five CIs also appear in the topology
  console's class catalog and their verbs are Inspector-invokable (they were uncategorized
  and catalog-invisible before). Each arg-bearing verb declares its `args` (name/type/
  required/default, derived from what the handler reads) so the Inspector renders the right
  input fields — e.g. `servers add` requires `id`+`url`, `servers update` is an all-optional
  partial-update (no defaults that would silently re-write a field). Behavior-preserving for
  dispatch — requires the matching `newspack-nodes` build.

- **Reduced onto the substrate's `register_plugin` + namespaced config tokens.** The
  substrate stopped feeding topology `<config:…>` tokens from a single merged
  `Core::$config` array, so this plugin no longer merges substrate + app config to spawn
  workers. Its 6 app-specific tokens moved to an `<eln:…>` namespace
  (`is_hub`, `auto_disable_threshold`, `auto_protect_time_threshold`,
  `aggregator_require_https`, `aggregator_verify_ssl`, `significant_events_csv`),
  resolved by a small `eln` resolver registered with
  `Core::register_config_namespace()`; substrate-owned tokens (`logs_dir`, `offsets_dir`,
  `num_partitions`, `segment_size`, `num_segments`, `max_lifespan`) keep the `<config:…>`
  namespace. The bespoke `newspack_nodes/topologies` filter and `newspack_nodes/spawn_worker`
  handler are deleted in favor of `Topology_Registry::register_plugin( …, names: <curated
  list> )`; worker-runtime init (StreamMerger rewrite filter + RemoteManager) now runs from a
  `newspack_nodes/before_worker_spawn` listener. Behavior-preserving — requires the matching
  `newspack-nodes` build.

### Fixed

- **Restored read-time type coercion in the WP-option overlay (`Config::load_config`).** A prior simplification removed the per-key coercion, so an `array_strings` option stored as a newline string overlaid the config as a raw string (breaking the `foreach` consumers that need a list) and `int`/`float` options stored as numeric strings overlaid as strings. A minimal `coerce_option_value()` now splits the array type into a trimmed list and casts `int`/`float` (non-numeric → falls back to the file default), restoring the shape consumers expect. Per-element `sanitize_text_field` stays at write time (off the per-request read path), where it moved.
- **Profiler mu-plugin was silently inert after the `LogManager` → `Log_Manager` rename.**
  `mu-plugins/00-newspack-profiler.php` gated its `plugins_loaded` flush on
  `class_exists( '…\LogManager' )` and called `LogManager::instance()`, but the class is
  `Log_Manager` — so the guard was always false and the deferred plugin-load events
  (`{plugin} (start)` / `(complete)`, the firehose `process (start)` timing) were never
  flushed. Corrected the class references; a new test fires the profiler's hook and asserts
  it resolves `Log_Manager` so the rename can't silently break it again.

## [0.4.0] - 2026-05-23

### Changed

- **Registers its node namespace instead of per-class `register_class`; `.tsl`
  shell-names normalized.** The plugin now calls
  `Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\' )`
  (+ the `App\` sub-namespace, since `request_graph_ready` `make_node`'s the
  service CIs by short name) in place of its 17 `register_class()` calls. The
  topology `.tsl` files use the normalized shell-names (`make_node FlameBuilder`
  → `make_node Flame_Builder`, `JobRouter` → `Job_Router`, `JobWorker` →
  `Job_Worker`, `RequestBuilder` → `Request_Builder`, `StreamMerger` →
  `Stream_Merger`). Requires the matching `newspack-nodes` build (prefix
  resolution lives in the substrate).
- **Internal: class names normalized to `Word_Word` + `_Node` (lockstep with
  newspack-nodes).** ELN's own classes are now `Word_Word` with ALL-CAPS acronyms
  and a `_Node` suffix on Node subclasses (`FlameBuilder` → `Flame_Builder_Node`,
  `StreamMerger` → `Stream_Merger_Node`, `LogManager` → `Log_Manager`, `LruCache`
  → `LRU_Cache`); every reference to the renamed substrate classes was updated to
  match (`extends Service_CI` → `Service_CI_Node`, `\Newspack_Nodes\CommandInterpreter`
  → `Command_Interpreter_Node`, …). Behavior-neutral: `register_class` shell-names
  + `.tsl` topologies unchanged. Requires the matching `newspack-nodes` build
  deployed alongside (the substrate classes are renamed there).
- **Synced shared `useMessageStream` hook: `workers/heartbeat` sends its slot
  args positionally.** Mirrors the canonical hook in `newspack-nodes`, whose
  `Workers_CI heartbeat` verb now reads positional `arguments` instead of a
  structured `payload`. Requires the matching `newspack-nodes` build deployed
  alongside (the heartbeat verb is substrate-side).

## [0.3.0] - 2026-05-22

### Changed

- **Caching gutted down to the single shared `Core::$memd` handle.** Deleted the
  plugin-local `Memcached_Cache` class, the `Cache_Interface` abstraction, and
  the `FakeMemcached` test class. Caching is now `\Newspack_Nodes\Core::$memd` (a
  raw `\Memcached`), built once at boot by
  `newspack_event_logger_nodes_init_memcached()` from the substrate's
  `memcache_servers` config and stashed on the substrate `Core`. `Stats_Store`
  and the memcache-backed service CIs (`Status_CI`, `Events_CI`, `Aggregator_CI`,
  `Performance_CI`) dropped their injected-cache constructor params and read
  `Core::$memd` directly (null-safe). `Sse_Slot_Pool::wire()` and the
  `newspack_nodes/workers_cache` filter both feed the substrate off `Core::$memd`.
  Tests set `Core::$memd` to the substrate's in-memory `\Memcached` double
  (`tests/Helpers/InMemoryMemcached.php`) instead of injecting a cache.
- **CI verbs return structured data, matching the substrate's de-double-encoded
  command protocol** (newspack-nodes ≥ Unreleased). Verbs return live PHP
  structures instead of `wp_json_encode`'d strings, and the synced
  `unwrapCommandResponse` reads the structured payload directly (single decode).
  Dashboards consume the structured response unchanged.
- **Cross-spoke `/command` senders speak the new wire.** `RemoteManager`,
  `RemoteSource`, and `Servers_CI` post a packed Message (structured `VALUE`) as
  `text/plain` and read the response payload with one decode. Hoisted
  `RemoteManager::COMMAND_CONTENT_TYPE` + `command_message_body()` to public so
  the same-plugin callers share one definition.
- **Adapted to the substrate In/Out rename + `TM_*` renumber.** Follows
  `Command_Controller`/`HTTP_Out` → `HTTP_In` and `Messages_Stream_Controller` →
  `SSE_Out`; reserved node names use `Node_Names`; the reply-convention tests
  assert `TM_STRUCT|TM_RESPONSE` (no `TM_REQUEST` echo).
- Moved `00-newspack-profiler.php` under `mu-plugins/`; synced shared JS.
- **`RequestFlight`** — hidden Timer-sibling of `RequestBuilder` that snapshots the
  in-flight request map and emits a compact batch to the gyroscope partition.
- Removed dead `StatsAggregator`.

## [0.2.43] - 2026-05-21

### Fixed

- **SSE slot TTL is refreshed ONLY by the client heartbeat — never server-side.**
  `Sse_Slot_Pool::$check_slot` was doing refresh-on-check (touching the TTL every
  drain iteration), so a zombie/abandoned connection held its slot forever and the
  pool stopped being a rate limit. It is now check-only — when the client stops
  heart-beating, the TTL lapses and the stream terminates. `RemoteSource` (the
  aggregator client) now sends a keepalive ttl of `HEARTBEAT_INTERVAL * 4` (60s,
  was 10s) so its slot survives the 15s gap between its heartbeats now that the
  server no longer refreshes it.

## [0.2.42] - 2026-05-20

### Changed

- **The shared `useMessageStream` hook no longer sends an `interval` query
  param** (synced from `newspack-nodes` ≥ 0.2.8). 0.2.41 dropped `intervalMs`
  from the ELN-owned dashboard callers, but the shared hook is synced from
  newspack-nodes and kept sending `interval` until the canonical was fixed —
  this release ships the corrected synced copy.

## [0.2.41] - 2026-05-20

### Changed

- **Dropped `intervalMs` from `useMessageStream` and its callers.** The hook no
  longer sends an `interval` query param; the server owns the heartbeat cadence
  (hardcoded 2s, requires `newspack-nodes` with the `interval` param removed).
  Removes the post-flush-fix-redundant client cadences (gyroscope 100ms,
  request-log 500ms, errors 1000ms) that only generated idle keepalive traffic.
- **Renamed the stats-salt option** `newspack_nodes_stats_salt` →
  `newspack_event_logger_nodes_stats_salt` (ELN naming convention). This also
  aligns it with the worker-restart dispatch's short-key prefix, which already
  assumed the ELN prefix — so rotating the salt now triggers the request-worker
  restart it always should have. One-time effect on deploy: existing stats
  orphan once (equivalent to a Flush Cache) and rebuild.

## [0.2.40] - 2026-05-20

### Changed

- **Removed the "Refreshing…" flash from the Aggregator dashboard.** At a 1s
  refresh it flickered every second; the status indicator now just shows
  "Updated …" / "Loading…". Dropped the `refreshing` state and the
  initial-vs-refresh fetch distinction along with it.

## [0.2.39] - 2026-05-20

### Fixed

- **Aggregator dashboard "ago" values now reflect the aggregator's snapshot
  time, not the browser clock.** Server HB / Client HB / Connected ages were
  computed against `Date.now()` and re-ticked every second, so at a 10s refresh
  a heartbeat the aggregator saw as "0s ago" displayed up to "9s ago" and
  climbed on its own between fetches. They now compute against the response
  Message's `TIMESTAMP` (the hub's clock when it built the snapshot), so each
  value is what the aggregator actually saw and stays fixed until the next
  refresh.

## [0.2.38] - 2026-05-20

### Fixed

- **Aggregator dashboard now shows "Server HB" (remote SSE heartbeats).**
  `RemoteSource::dispatch_event()` silently dropped the spoke's `heartbeat` SSE
  events, so `last_sse_heartbeat` was never recorded and the dashboard always
  showed "–". It now records the receipt time on each heartbeat. Requires
  `newspack-nodes >= 0.2.4`, whose SSE flush fix ensures the spoke's heartbeats
  actually reach the hub.

## [0.2.37] - 2026-05-20

### Fixed

- **The Aggregator status dashboard now appears in the Event Logger admin menu
  when "Enable Aggregator" is checked.** `enable_aggregator` was missing from
  the config option schema, so `Config::load_config()` — which the menu gate
  reads — never overlaid the WP option the checkbox writes; it returned only
  the config-file default (off). Added `enable_aggregator` to the schema so the
  checkbox takes effect.

## [0.2.36] - 2026-05-20

### Fixed

- **`StreamMerger` no longer aborts the merge on an unparseable offsetlog
  entry.** It catches the `InvalidArgumentException` now thrown by
  `Message::unpacked()` and skips restoring that remote's position (with a
  rate-limited log) instead of letting the exception propagate. Requires the
  matching strict-`unpacked()` change in `newspack-nodes`.

## [0.2.35] - 2026-05-19

### Changed

- **`expected_log_basenames` filter callback collapses to a one-line runtime-basename append.** Substrate v0.2.1 inverted the contract: `Log_Cleaner` now computes the topology-derived expected set itself and passes it to the filter as input. The app callback's job is just appending the runtime-pinned basenames it manages outside the topology graph (`firehose` from LogManager, `jobintake` from JobIntake). All the substrate-state introspection (`Bootstrap::get_topologies()`, `Cli::ls_workers()`, `Topology_Registry::basenames_for()`) that previously lived here is gone — substrate owns substrate facts. Requires `newspack-nodes >= 0.2.1`.

## [0.2.34] - 2026-05-19

### Fixed

- **`expected_log_basenames` filter now honors the substrate's operator-overlay active topology set.** Previously it read `$config['topologies']` from the app's merged Config, which is the app's full FILE-DEFAULT list (every topology the plugin knows about). The operator's actual active subset lives in the substrate option `newspack_nodes_topologies` (admin-UI checkboxes), exposed via `Bootstrap::get_topologies()`. The mismatch meant inactive topologies' basenames stayed "expected" forever — `Log_Cleaner` saw zero orphans and never cleaned the on-disk `*.log/` dirs the operator had toggled off. Datapoke staging hit this: app file defaults are `firehose-workers-only` + `request-workers`, operator selected `firehose-jobs-only` + `job-workers`. The filter unioned both lists, declared all 8 basenames expected, and 5 orphan log dirs (`completed`, `errors`, `flames`, `gyroscope`, `requests`) survived every supervisor cleanup tick. Filter now starts from `Bootstrap::get_topologies()` keys instead. The active-workers-on-disk overlay (which keeps a deactivated topology's basenames expected until its workers actually exit) is unchanged.

## [0.2.33] - 2026-05-18

### Changed

- **TM_COMMAND wire format follow-on to the substrate's cleanup.** Substrate now treats `arguments` as a literal CLI tail (Tachikoma contract) and `payload` as the structured-data slot — the previous `arguments: JSON.stringify(args)` triple-encoding is gone. Application side updates: `RemoteManager::build_command_envelope()` puts the settings-update map in `payload` (was JSON-encoded into `arguments`); `Servers_CI`, `Settings_CI`, `Performance_CI`, `Events_CI`, `Logger_CI`, `Aggregator_CI`, `Status_CI`, `Discovery_CI` all migrated to read structured data from `$payload` directly (verb closures gained `mixed $payload` as the 4th positional); JS dashboard callers (`PerformanceDashboard.request_search`) pass `payload: { ... }` where they used to pass `args: { ... }`. 1598 jest+phpunit tests pass; admin dashboards smoke clean on the new wire.

- **JS build toolchain: replaced `@wordpress/scripts` with esbuild + standalone tooling.** Same shape as the matching change in `newspack-nodes` (commit `e207993`): `npm run build` now runs `scripts/build.mjs` (esbuild + sass + rtlcss, ~220 lines), with `wp-scripts test-unit-js` → `jest`, `wp-scripts lint-js` → `eslint`, `wp-scripts lint-style` → `stylelint`, `wp-scripts format` → `prettier@npm:wp-prettier`. The build script extends the substrate's version with esbuild's context API for incremental rebuilds (`npm run watch`) and per-entry output basenames so `src/event-aggregator/settings.js` emits `settings.{js,css,asset.php}` — matching the existing PHP enqueue at `build/event-aggregator-settings/settings.css`. Cross-plugin alias points the build + jest at `../newspack-nodes/src/runtime/index.js`. `webpack.config.js` deleted, `concurrently` removed (esbuild's built-in `--watch` replaces the parallel-watch fanout). Scoped npm overrides pin `@typescript-eslint/typescript-estree` → `minimatch ^9.0.7` and `@babel/runtime` → `^7.26.10` so audit stays clean. Results: `package-lock.json` 2024 → 1148 packages; `npm audit` 14 alerts (4 mod, 10 high) → **0**; jest tests still pass; all five admin dashboards (Performance, Logger, Gyroscope, Request Log, Errors, Aggregator) render and stream data identically to wp-scripts; performance-dashboards bundle is 270KB single file (was 5 split chunks totaling 380KB — esbuild's IIFE bundle inlines what webpack lazy-chunked, smaller total bytes because no webpack runtime overhead).

- **Workers + Raw Logs dashboards moved to `newspack-nodes`.** Both surface substrate state — worker fleets, raw on-disk log segments — so they belong in the substrate, not the application layer. Deleted `includes/app/class-workers-ci.php`, `src/event-dashboards/`, `tests/unit/WorkersCITest.php`. Stripped `firehose_logs` + `firehose_status` verbs (and their `discover_logs` / `resolve_log_key` helpers + `DEFAULT_LOG_KEY` const) out of `Performance_CI`. Dropped the Workers + Raw Logs entries from the top-level "Event Logger" admin menu + page-to-bundle mapping; they now live as submenus under the substrate's "Nodes" top-level menu, served by substrate's own event-dashboards bundle. Added a `newspack_nodes/workers_cache` filter that supplies the substrate Workers_CI mount with this plugin's `Memcached_Cache` (for live-position lookups + the SSE-slot heartbeat verb).
- **`src/shared/` is now a synced copy from the substrate's `src/shared/`.** Canonical source lives in `newspack-nodes/src/shared/`; the substrate's `sync-shared.sh` copies hooks + utils here on every `npm run build` (via the substrate's `npm run sync`). Each file in `src/shared/` here gets a `// Synced from src/shared/` header that the PreToolUse hook uses to block accidental hand-edits — edit the substrate canonical, not the local copy.

### Removed

- **`RemoteSource::update_sse_heartbeat()` and its two callers' tests.** Method was unreferenced; SSE heartbeat tracking lives elsewhere now. PHPStan caught it.

### Fixed

- **Aggregator settings checkbox now reflects the file-config default when the WP option row is missing.** `skip_default_writes` deliberately deletes the WP option whenever the user saves a value that matches the file-config default — so for any site where `enable_aggregator => true` (or `enable_logging`, `log_memory`, `flush_every_line`) is set in the deployed config, the steady state is "option row absent." The form callbacks then resolved the value via `get_option( $key, 0 )` — hard-coded fallback of 0, ignoring the file default — so the box rendered unchecked, and checking it just got deleted again on the next save. Behavior was a saturated loop: file default true → option row deleted → checkbox shows unchecked → user re-checks → option row deleted. Added a `bool_option_with_file_default` helper that resolves the file default via `Config::load_config_defaults()` before falling back to a hard-coded value, and routed the four boolean toggle callbacks through it.

- **Discovery probe (cron health check + admin "Test" button) dispatches via `/command` instead of the deleted `/discovery` route.** The M5 migration replaced the legacy `GET /wp-json/newspack-nodes/v1/discovery` REST controller with a substrate-side `Discovery_CI` verb at `POST /wp-json/newspack-nodes/v1/command` with `{to: 'discovery', verb: 'get'}`, but `RemoteManager::check_server()` and `Servers_CI::probe_remote()` were never updated. Both call sites silently 404'd on every tick — health-check log filled with `HTTP 404` errors, server "Test" buttons returned `HTTP 404 response from server` to the admin. Both now build a TM_COMMAND envelope and unwrap the wrapped response (mirrors what JS `unwrapCommandResponse` does — outer Message → inner `{name, payload}` → verb's JSON return). Added `RemoteManager::discover_from_server()` as the shared dispatch helper.

- **`Performance_CI` URL-bucket aggregator: PHPStan dead-branch warnings on `min_ms` sentinel and `$count > 0` divides.** The `??=`-initialized array uses literal types (PHPStan sees `min_ms` as `0.0` and `count` as `0` regardless of accumulation through the reference variable). Switched the `min_ms` sentinel from `0.0` to `null` (cleaner anyway — distinguishes "no data" from "0ms min"), and replaced `$count > 0 ? sum/count : 0.0` with `sum / max(1, count)` for the two avg fields. Behavior unchanged: when no data accumulated, count is 0 and sum is 0, so 0/1 = 0.

### Changed

- **`reqgrep` chunk-size constant moved to `Consumer::MAX_POLL_BYTES`.** Was referencing `Partition::MAX_READ_SIZE`, which the substrate just deleted (the constant was conflating a buffer-cap-that-broke-large-offsetlog-reads with a per-record DoS guard). The 10MB chunk size itself is unchanged — just sourced from `Newspack_Nodes\Consumer::MAX_POLL_BYTES` where it belongs (it's the same poll-budget Consumer's main read loop applies).

### Fixed

- **`inflight_snapshot.start_time` now reflects the real PHP-request start, not LogManager-emit time.** With the profiler MU-plugin live, `$newspack_profiler['request_ts']` carries a wall-clock microtime captured before any regular plugin runs (alongside the existing `request_time` hrtime). LogManager reads it on construction and stamps the firehose `process (start)` entry with that ts, so RequestBuilder's `$request->timestamp` becomes the actual earliest point PHP began handling the request — not the deep-in-WP-bootstrap moment when LogManager fired its first message. `inflight_snapshot` now uses `$r['timestamp']` directly; the URL-bind `request_start_ts` it preferred before is gone (along with the corresponding callback assignment) because its only consumer was this snapshot path.

- **Profiler MU-plugin: plugin-load events now actually flush into the firehose.** The flush hook fires at `plugins_loaded` priority `-10001` (deliberately, so plugin-load events appear before any `plugins_loaded` callbacks). Problem: this plugin's composer autoloader was being registered inside the deferred-init closure that runs at `plugins_loaded` priority 11 — so at -10001 `LogManager` wasn't autoload-resolvable and `class_exists( …, false )` returned false, exiting the flush early. Pulled the `require_once vendor/autoload.php` out to plugin-file load time (where it only registers the spl callback; actual class loading stays lazy). The runtime-dependent setup (`CommandInterpreter` registrations, `Topology_Registry` mounts, `App\Core` init) stays deferred to priority 11 since those depend on `\Newspack_Nodes\Node` being loaded. Also dropped the `false` second-arg on `class_exists` in the mu-plugin so autoload is allowed to run.

- **Topology console live view now shows the `request-builder → gyroscope:partition` edge.** The override of `RequestBuilder::target()` walked the conditional `errors_target` / `completed_target` but missed the flight sibling's target — the `gyroscope:partition` destination the `set_inflight_target` verb stores on the hidden `RequestFlight` sibling. Live view rendered `gyroscope:partition` with a non-zero rate but no inbound edge from request-builder; edit view (which reads the topology source rather than the live graph) showed the edge correctly. Union the flight sibling's target into the extras list alongside the existing two, with the same dedup gate.

### Changed

- **Default `skip_urls` updated for M6 endpoint shape.** Drops `/wp-json/newspack-nodes/v1/firehose` (no live routes under that prefix after M6) and `/wp-json/newspack-nodes/v1/topology` (the topology console route was renamed in M4). Adds `/wp-json/newspack-nodes/v1/command` (the unified `/command` dispatch endpoint — every dashboard verb call lands there) and `/wp-json/newspack-nodes/v1/messages/stream` (the unified SSE endpoint). Without these entries the request-builder would log every dashboard click + every SSE connection as a regular request, polluting global timing stats with admin-only traffic. `workers/spawn` stays.

### Removed

- **`NEWSPACK_EVENT_LOGGER_NODES_TOPOLOGY_BASENAMES` const + the `newspack_nodes/num_logs` filter callback.** Both were hand-maintained catalogs that drifted every time a topology added a Partition — pre-M6 they silently omitted `completed.log` and `gyroscope.log` for months. Replaced by substrate primitives: `Topology_Registry::basenames_for()` (parses each TSL's `make_node Partition` lines) for per-topology basenames, and `Log_Discovery::on_disk()` (readdir `{base}/logs/*.log/`) for the storage-widget count. The `expected_log_basenames` callback now derives its per-topology data from the substrate; the TSL is the single source of truth. App still declares two always-on basenames (`firehose`, `jobintake`) for runtime code that writes outside any topology — LogManager + JobIntake use `Partition::fill()` directly from request code.

### Changed

- **`RemoteSource` is generic cross-server transport — no more peeking inside Message VALUE.** Earlier M6.7 work added an `isset($value['k'])` gate in `dispatch_msg_envelope` and back-filled `$value['rid']` from envelope KEY, baking firehose-shape assumptions into what's meant to be a transport node (the hub uses it to pull any log a spoke publishes — firehose today, could be gyroscope.pN or completed.pN tomorrow). Replaced `forward_entry(array $data)` with `forward_envelope(array $envelope)`: forwards every non-`connected` envelope verbatim, preserving TYPE / KEY / VALUE from the spoke. Shape-aware behavior survives only inside the array-VALUE branch (the `_source` hub-attribution stamp + the `aggregator_ingest_line` filter chain that StreamMerger uses for `k:"job"` → `k:"remote_job"` rewrites); scalar payloads bypass that path and reach the sink unchanged. `forward_entry`'s old `k`/`ts` validation gates removed — those drops belong downstream where shape decisions live, not in the transport layer.

- **Raw Logs dashboard now discovers logs from disk instead of a hardcoded catalog.** `Performance_CI::firehose_logs` verb scans `{base}/logs/*.log/` directories every call and returns the sorted result. Replaces the static `AVAILABLE_LOGS` constant, which silently omitted `completed.log` and `gyroscope.log` after the M6 topology added them — so the Raw Logs picker is now complete (8 entries: completed, errors, firehose, flames, gyroscope, jobintake, jobs, requests). `firehose_status` + `resolve_log_key` switched to the same discovery; `DEFAULT_LOG_KEY = 'firehose'` is the only constant left, used as the fallback when the operator's `log` arg is missing or unknown.

### Removed

- **M6 cleanup — legacy SSE wire-format dispatch in `RemoteSource::dispatch_event`.** The `event: entry` / `event: heartbeat` / `event: connected` branches that handled the now-deleted `FirehoseStreamController`'s wire format are gone; everything routes through `dispatch_msg_envelope` for the unified `msg`-envelope shape. Migrated ~26 test sites across `StreamMergerTest` (and ~15 in `RemoteSourceTest`) to the new wire via three helpers (`entry_frame`, `connected_frame`, `position_frame`); deleted `test_skips_non_data_lines` (its `event: heartbeat` assertion is meaningless under the new wire and the `id:` field assertion is already covered by `test_unknown_sse_field_ignored`). Substrate's `Messages_Stream_Controller` is now the ONLY SSE wire format any production code consumes or any test exercises.

- **M6.9b / M6.10 — `FirehoseStreamController` + `SSEControllerBase` + `Partition_Reader` + their tests.** The last three legacy SSE classes after M6.7 moved RemoteSource onto the unified endpoint. `rest_api_init` no longer registers any per-feed SSE controllers — the substrate's `Messages_Stream_Controller` is the only SSE surface now. Eight new gate tests in `M2BootstrapTest` (`test_legacy_*_class_is_gone`) pin every deleted class so an accidental re-introduction (autoload regression, partial revert) trips here instead of silently double-handling SSE traffic. The legacy `event: entry` / `event: heartbeat` / `event: connected` dispatch branches in `RemoteSource::dispatch_event` are kept alive to support the existing test corpus (~33 tests across `RemoteSourceTest` + `StreamMergerTest` drive them); they're dead in production after M6.7 and can be cleaned up the next time someone touches that test corpus.

### Changed

- **M6.7 — `RemoteSource` (StreamMerger's cross-server SSE consumer) migrated to the substrate's `/messages/stream` endpoint.** URL switches from `/wp-json/newspack-nodes/v1/firehose/stream?partition=N&aggregator=1` to `/wp-json/newspack-nodes/v1/messages/stream?subscribe=firehose.pN`. Position resume now rides a JSON `positions` param keyed by subscription → partition → `{seg, off}` (substrate `parse_positions` shape). The per-partition aggregator slot pool (60s TTL) engages automatically because `Messages_Stream_Controller::subscription_partition()` extracts N from the `firehose.pN` shape; no `aggregator=1` flag needed. New `dispatch_msg_envelope` private method handles the unified `event: msg` wire format: 7-field envelope JSON-decoded, position parsed from envelope `ID = "seg:off"` (Consumer stamps at emit), `connected` envelope captures slot, entry envelope (`VALUE` is a dict with `k`) back-fills rid from `KEY` and forwards through the existing `forward_entry` pipeline. Eight new unit tests in `RemoteSourceTest` cover the URL contract, position round-trip, msg-envelope dispatch (entry / connected / position-from-ID), and silent drop of malformed VALUE. Legacy `event: entry` / `event: heartbeat` / `event: connected` handlers remain in `dispatch_event` (and their 21 tests stay green) until M6.9b deletes them alongside `FirehoseStreamController`.

### Removed

- **M6.8 / M6.9 — InflightTracker + 4 legacy SSE controllers + their test suites.** ~2150 LOC of code removed: `includes/class-inflight-tracker.php` (240 LOC), `RawlogsController` (122), `ErrorsStreamController` (84), `RequestsStreamController` (85), `GyroscopeStreamController` (171), plus their PHPUnit suites and the M5-acceptance-gate `SchemaParityAuditTest` (278). Bootstrap registration of the four browser controllers removed from `newspack-event-logger-nodes.php`. The legacy `useFirehoseConnection` JS hook (no remaining consumers after M6.3–M6.6) is also gone. `FirehoseStreamController` stays alive until M6.7 (StreamMerger cross-server migration) — see MIGRATION.md.

### Deferred

- **M6.7 — StreamMerger / RemoteSource migration to `/messages/stream`** — deferred to a focused follow-up milestone. Out of scope for the current branch: 918 LOC of cross-server SSE consumer, wire-format change cascading through `forward_entry` → sink → `JobRouter`, 21 existing tests on the `event: entry` wire shape, and smoke-testing properly requires a multi-spoke topology not available in this dev environment. `FirehoseStreamController` + `SSEControllerBase` + `Partition_Reader` therefore remain alive in the codebase.

### Changed

- **M6.6 — Gyroscope dashboard migrated to the unified `/messages/stream` endpoint, source `gyroscope.log`.** Subscribes via `useMessageStream({subscriptions:['gyroscope']})`; dispatches the two record types client-side via `src/performance-gyroscope/transformGyroscopeLine.js` — `KEY='inflight'` + array VALUE → inflight snapshot (upsert by rid, skip completed); object VALUE with rid → completion (merge + mark `state:complete`). This client-side dispatch is exactly what the legacy `GyroscopeStreamController` did server-side via `InflightTracker`, so M6.8 (next) deletes both. Browser-verified rendering live in-flight requests with state pills (lifecycle / query & posts / content rendering / theme / scripts & styles / rest api / process / complete). `GyroscopeStreamController` stays alive until M6.9.

- **M6.5 — Requests dashboard migrated to the unified `/messages/stream` endpoint, source `completed.log`.** Subscribes via `useMessageStream({subscriptions:['completed']})`; runs the URL (2000 char) / user-agent (500 char) clip client-side via `src/performance-request-log/transformCompletedLine.js`. The source-log change is intentional: the legacy `RequestsStreamController` tailed `requests.log` (live + completed mixed) and inferred completion from payload shape; the new subscription consumes `completed.log` (filtered upstream by the topology's `completed:tee` node) so the transform stays a pure shape mapper with no completion guard. Browser-verified rendering 267 live requests with TIME / REQUEST ID / URL / STATUS / IP / DURATION columns intact. `RequestsStreamController` stays alive until M6.9.

- **M6.4 — Errors dashboard migrated to the unified `/messages/stream` endpoint.** Subscribes via `useMessageStream({subscriptions:['errors']})`; runs the rid-required + 1000-char `m`-clip filter client-side via `src/performance-dashboards/transformErrorLine.js`. Browser-verified rendering 305 live entries with TIME / REQUEST ID / KEYWORD / MESSAGE columns intact. `ErrorsStreamController` stays alive until M6.9.

- **M6.3 — RawLogs dashboard migrated to the unified `/messages/stream` endpoint.** First of the four dashboard cutovers in M6. The dashboard now subscribes via the new `useMessageStream` hook (in `src/shared/hooks/`) and runs the per-line transform client-side via `src/event-dashboards/transformLogLine.js` — mirrors the legacy `RawlogsController::transform_line()` (KEY prefix, JSON-render of array VALUE, 1000-char clip). Partition number comes from the Message FROM field (`{sub}.pN`), matching the new substrate stamp.
- **`Sse_Slot_Pool` `check_slot` closure now refreshes TTL via `touch_sse_slot`.** Without this, the slot expires every `$ttl_browser` seconds (30s default) and the dashboard cycles disconnect → reacquire — visible as a "Reconnecting in 2s..." banner every 30 seconds even on a healthy stream. Refresh-on-check matches the legacy `SSEControllerBase` heartbeat semantic but without a separate client-side ping.

### Added

- **M6.2 — `Sse_Slot_Pool::wire()` installs the substrate's slot-pool seams.** Three closures bound at plugin boot to `\Newspack_Nodes\Rest\Messages_Stream_Controller`'s static `$acquire_slot` / `$release_slot` / `$check_slot` properties, each delegating to a shared `Memcached_Cache` via `Cache_Interface`. Same `MAX_SSE_SLOTS = 8` cap the legacy `SSEControllerBase` used; TTL is 30s for the shared browser pool (partition `-1`) and 60s for per-partition aggregator pools. The substrate computes the partition number from the subscription shape, so dashboards subscribing to `firehose` (multi-partition log) land in the browser pool and a future `firehose.p3` aggregator subscription (M6.7) lands in a per-partition pool. Cache instance is overridable via `Sse_Slot_Pool::$cache` for tests; five new unit tests in `tests/unit/SseSlotPoolTest.php` pin the contract. Ready for M6.3+ dashboard migrations onto `/messages/stream` without losing the rate-limiting the per-feed SSE controllers had.

- **9 service `CommandInterpreter` subclasses replace ~20 legacy WP REST controllers.** `Workers_CI`, `Discovery_CI`, `Status_CI`, `Settings_CI`, `Logger_CI`, `Events_CI`, `Servers_CI`, `Aggregator_CI`, `Performance_CI` — all reachable via `POST /wp-json/newspack-nodes/v1/command` with a TM_COMMAND envelope (`{type, to, from, id, value: json_encode({name, arguments, payload})}`). Performance_CI carries the heavy lift: 19 verbs replacing the entire `perf-*` controller family plus the non-streaming methods on firehose/gyroscope/request-log controllers. SSE controllers stay as REST endpoints — the command path doesn't stream. See `MIGRATION.md` for the per-CI verb reference and the M5 deletion list.
- **`VerbHarness` test fixture for unit-testing CI verbs in isolation.** Wraps `CommandInterpreter::interpret()` so each verb test reads as `harness->call('verb', $args)` instead of hand-assembling Message envelopes. Used by every M2 CI unit test.
- **M2 integration tests: `M2BootstrapTest` (substrate-hook registration) and `M2CommandDispatchE2ETest` (end-to-end `/command` dispatch).** The bootstrap test fires `newspack_nodes/request_graph_ready` and asserts all 9 CIs register under their short names. The E2E test drives an HTTP request through `Command_Controller::dispatch` for every CI's read-only smoke verb and asserts the response shape.
- **9 service CI classes registered via `CommandInterpreter::register_class()`** so `make_node()` can construct them by shell-name from the `newspack_nodes/request_graph_ready` hook.
- **M4 dashboard cutover #1 (Aggregator Status).** `AggregatorStatus.js` now dispatches `aggregator.status` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes-aggregator/v1/status')`. Same response shape (verified by the M3 schema-parity audit), same 1–10s refresh cadence, same error UX. Adds `unwrapCommandResponse` shared helper that peels the substrate's 7-field Message tuple down to the verb's payload (double-JSON-parse: outer `VALUE` → `{name, payload}`, inner `payload` → data object); throws on TM_ERROR with the payload as the error message. Browser smoke-test confirmed the partition grid renders correctly via the CI verb path.
- **M4 dashboard cutover #2 (Performance Logger).** `HookSelectorModal.js` (in the `performance-logger` tree) now dispatches `performance.hooks_registered` via `getCommandClient().send()` instead of `apiFetch('/newspack-nodes/v1/performance/registered-hooks')`. Reuses the `unwrapCommandResponse` helper introduced in cutover #1. Browser smoke-test confirmed the hook-selector modal populates all 19 categories with correct counts (Lifecycle 17/25, REST API 3/21, etc.) via the new CommandClient path. Rewrite landed in commit `08e7a34`.
- **M4 dashboard cutover #3 (Performance Gyroscope) — audit-only, no JS rewrite.** The `performance-gyroscope` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'gyroscope'})` (SSE, which stays as REST). The legacy `/gyroscope/timeline` JSON route was fully orphan — no dashboard caller in any `src/` tree. Deletion landed without a rewrite step.
- **M4 dashboard cutover #4 (Performance Request Log) — audit-only, no JS rewrite.** The `performance-request-log` dashboard tree contains zero `apiFetch` calls; all its data flows through `useFirehoseConnection({endpoint:'requests'})` (SSE, which stays as REST). The legacy `/request-log/list` + `/request-log/detail/{id}` JSON routes were fully orphan — no dashboard caller in any `src/` tree. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` (added in M2 Task 12) cover the equivalent surface for any future caller. Deletion landed without a rewrite step.
- **M4 dashboard cutover #5 (Event Dashboards).** `WorkerStatus.js` and `RawLogs.js` (in the `event-dashboards` tree) now dispatch their non-SSE work over `getCommandClient().send()` instead of `apiFetch`. WorkerStatus reads the operator-grade payload via `workers.dump_metadata` (one CI call replaces the legacy `/performance/workers` JSON route + 1s polling cadence); RawLogs reads the log picker via `performance.firehose_logs` (replaces `/firehose/logs`). The shared `useFirehoseHeartbeat` hook (driven by `RawLogs`'s SSE connection and also reused by `RequestStream` / `ErrorLog` / `FirehoseStream`) cuts over to `workers.heartbeat` (replaces `/firehose/heartbeat`). `Workers_CI.dump_metadata` introduced in commit `739ac13` as a single fat verb — it replaces `WorkersController::get_workers()` field-for-field including the `logs[]` enumeration and the `inputs_status` / `outputs_status` per-Consumer arrays. Rewrite landed in commit `a4ca852`; browser smoke-test confirmed both panels render (Workers Status: full pipeline visible with live cursors; RawLogs: 552 lines/s streaming with heartbeat firing on `/command` returning 200s).
- **M4 dashboard cutover #6 (Performance Dashboards) — the biggest cutover.** `usePerformanceApi.js` and `PerformanceDashboard.js` (in the `performance-dashboards` tree) now dispatch all 9 `apiFetch` calls over `getCommandClient().send()`. Mapping: `fetchOverview` / `fetchBreakdown` → `performance.overview` (with `categories` / `breakdown` / `server` args); `fetchUrls` → `performance.urls`; `fetchUrlDetail` / `fetchUrlBreakdown` / `fetchUrlCategories` → `performance.url_detail` (with `categories` / `breakdown` args); `fetchRequestDetail` → `performance.request_detail`; both `request_search` callsites in `PerformanceDashboard.js` → `performance.request_search`. **Schema-parity audit found real gaps** that had to be filled before the cutover: the M2 `Performance_CI.overview` + `Performance_CI.url_detail` verbs were missing the optional `server` / `breakdown` / `categories` args plus the response fields that ride along (`global_leaderboard`, `category_time_series`, `breakdown_time_series`, `breakdowns: {dim => series}` on overview; `stats.time_series`, `breakdown_time_series`, `category_time_series` on url_detail). 11 new tests in `PerformanceCITest.php` cover the gap (failed first, then implemented). Public API of the `usePerformanceApi` hook unchanged — component consumers keep their existing call shape; only the transport differs.
- **Shared `useFirehoseHeartbeat` cut over to `workers.heartbeat`.** The retro-completion side effect: dashboards #3 (Performance Gyroscope), #4 (Performance Request Log), and #6 (Errors Stream) all use SSE controllers that drive their slot heartbeats through this shared hook. None of them needed a JS rewrite of their own data path (their non-SSE work was already audit-only orphan), but their heartbeat call now also flows through `/command` as a side effect of the hook cutover. The SSE controllers themselves stay as REST endpoints (CommandInterpreter dispatch is request/response only and doesn't stream).
- **Webpack alias `@newspack-nodes/runtime` → substrate's `src/runtime/index.js`** so dashboards can import `CommandClient`, `useNodeState`, `sse_connector` etc. with a single stable specifier. wp-scripts inlines the runtime into each dashboard bundle, so the released zip ships nothing extra. Mirror aliases added to `jest.config.js` (moduleNameMapper) and `.eslintrc.js` (import/core-modules) so tests + lint don't trip on the bare specifier.
- **`getCommandClient()` singleton factory at `src/shared/utils/commandClient.js`** — pulls `restUrl` + `nonce` from `window.NewspackNodesData` (already localized in the plugin file) and returns one CommandClient per page. Future M4 dashboards reuse this helper.
- **`src/aggregator-admin/` wp-scripts entry (M5.2a).** Replaces the raw jQuery `assets/aggregator-admin.js` script with a tiny wp-scripts bundle so the WP admin "Configured Servers" UI's 4 CRUD verbs dispatch through `getCommandClient()` against the `servers` CI. `api.js` is the pure dispatch path (jQuery-free, 5 Jest tests); `index.js` is the jQuery DOM glue. Built to `build/aggregator-admin/index.js` and enqueued only on the Event Logger Settings page.
- **"Enable Aggregator" checkbox in Event Logger Settings → Remote Servers.** The `newspack_event_logger_nodes_enable_aggregator` option has always been load-bearing (menu visibility, supervisor worker-restart on toggle, push-side fan-out gates in `SettingsSync` + `AutoTuner`) but had no admin UI — flipping it required `wp option update` or editing the config file directly. The checkbox renders above Configured Servers in the existing Remote Servers section; defaults to OFF (fresh installs aren't hubs); browser-verified end-to-end (checked → save → option=1 → submenu appears → dashboard loads). Also aligned the menu gate's `get_option` default from `1` to `0` so the runtime matches the documented "default OFF" posture.

### Changed

- **Topology `gated_by` mechanism removed from docs** (the code mechanism has been gone for several releases; ARCHITECTURE.md still described an `'gated_by' => 'newspack_event_logger_nodes_enable_aggregator'` entry on the `aggregator` topology that hasn't shipped in a long time). The current shape: topology activation is the substrate's Topologies multi-select (`newspack_nodes_topologies`); hub-mode operator gates (admin submenu visibility, push-side fan-out short-circuits) live on `newspack_event_logger_nodes_enable_aggregator`. Two independent operator choices, two surfaces — both intentional, neither implies the other. Earlier CHANGELOG entries that describe `gated_by` stay as historical record of what shipped at the time.
- **M5 substantively complete.** 9 legacy controllers deleted across M5.1 (6 orphan-cleanup) + M5.2 (3 server-held after SettingsSync + admin-JS transport migration). Combined with M4's 14 dashboard-cutover deletions, the rebuild trims ~24 legacy WP REST controllers; ~30 `apiFetch` calls now flow through `/command`. M5.3 audit (SSE controllers + `Partition_Reader` + `SSEControllerBase`) found zero deletions safe: all five SSE controllers have live consumers (four browser dashboards + one hub-side cross-server pull). The full SSE consolidation onto the substrate's `Messages_Stream_Controller` is real M6 work — Basic Auth in cross-server SSE, slot-pool semantics, per-partition subscriptions, four browser-side transform rewrites. Documented in `MIGRATION.md`'s "M5.3 — SSE controller audit" section.

### Removed

- **Legacy `AggregatorStatusController`** (`includes/rest/class-aggregator-status-controller.php`) deleted. The event-aggregator dashboard cut over in M4 #1; the schema-parity audit found zero gaps; the browser smoke-test confirmed `Aggregator_CI.status` is byte-equivalent. The 3-route stub `AggregatorController` (`/status` + `/servers` + `/health`) stays alive for now — its `get_status()` body is inlined (no longer a delegate) and will be removed when a follow-up M4 cutover handles its sibling routes.
- **Legacy `LoggerController`** (`includes/rest/class-logger-controller.php`) deleted. The `/logger/config` and `/logger/hooks` routes had no remaining dashboard callers — JS audit across every `src/` tree found zero `apiFetch` hits to either endpoint. `Logger_CI` verbs cover the equivalent surface for any future caller.
- **Legacy `PerfHooksController`** (`includes/rest/class-perf-hooks-controller.php`) deleted. The `/performance/registered-hooks` route was the only one a dashboard hit (the M4 #2 cutover replaces that call with `performance.hooks_registered`); the sibling `/performance/hook-categories` route had no dashboard caller in any `src/` tree. `Performance_CI.hooks_registered` and `Performance_CI.hooks_categories` verbs replace both.
- **Legacy `GyroscopeController`** (`includes/rest/class-gyroscope-controller.php`) deleted. The non-streaming `/gyroscope/timeline` route was fully orphan — the `performance-gyroscope` dashboard only uses SSE (`useFirehoseConnection({endpoint:'gyroscope'})`) and never hit the JSON route. The streaming sibling `GyroscopeStreamController` (`/gyroscope/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.
- **Legacy `RequestLogController`** (`includes/rest/class-request-log-controller.php`) deleted. The `/request-log/list` + `/request-log/detail/{id}` routes were fully orphan — the `performance-request-log` dashboard only uses SSE (`useFirehoseConnection({endpoint:'requests'})`) and never hit either JSON route. `Performance_CI.request_log_list` + `Performance_CI.request_log_detail` cover the equivalent surface. The streaming sibling `RequestsStreamController` (`/requests/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it.
- **Legacy `WorkersController`** (`includes/rest/class-workers-controller.php`) deleted. The `/performance/workers` JSON route and `/performance/workers/restart` POST route both cut over to `Workers_CI.dump_metadata` + `Workers_CI.restart` in M4 cutover #5. Browser smoke-test confirmed the Worker Status panel renders the full pipeline (cursors, heartbeats, segment data) via the CI verbs; `WorkersControllerRealShapeTest` deleted alongside since `WorkersCITest` already covers the same contract (envelope keys, rich descriptor fields, live-position from memcache, offsetlog fallback) with broader scope.
- **Legacy `FirehoseController`** (`includes/rest/class-firehose-controller.php`) deleted. All three non-SSE routes cut over: `/firehose/logs` → `Performance_CI.firehose_logs`; `/firehose/heartbeat` → `Workers_CI.heartbeat`; `/firehose/status` → `Performance_CI.firehose_status` (verified zero JS callers in any `src/` tree before deletion). The streaming sibling `FirehoseStreamController` (`/firehose/stream`) stays alive — CommandInterpreter dispatch is request/response only and the dashboard's live data path runs through it. `RawlogsController` previously called `FirehoseController::get_available_logs/get_default_log` statically for `sanitize_log_param`; inlined the 6-entry catalog as a `private const AVAILABLE_LOGS` (mirrors Performance_CI's identical private const) so the SSE controller doesn't depend on a deleted sibling.
- **Legacy `PerfOverviewController` + `PerfUrlsController` + `PerfRequestsController` + `PerformanceController`** (4 controllers, deleted in commit `b96c320`) — the biggest single deletion in M4 to date. The `performance-dashboards` rewrite (M4 cutover #6, commit `343ec01`) cut over 9 `apiFetch` calls to `Performance_CI` verbs across `usePerformanceApi.js` + `PerformanceDashboard.js`; the schema-parity audit caught 4 missing args/fields in `overview` and 4 in `url_detail` that were filled before the rewrite landed (`PerformanceCITest` grew from 99 → 110 tests covering the gap). Mapping: `PerfOverviewController` → `Performance_CI.overview`; `PerfUrlsController` → `Performance_CI.urls` + `.url_detail`; `PerfRequestsController` → `Performance_CI.request_search` + `.request_detail`. `PerformanceController` had zero JS callers for its `/performance/dashboard` and `/performance/timing` routes but instantiated `PerfOverviewController` + `PerfUrlsController` to delegate, so it had to come along in the same batch — leaving it behind would have been a fatal at any request through its routes. The abstract base `PerformanceControllerBase` stays alive (shared by the surviving `PerfSettingsController` / `PerfConfigController` / `PerfHooksAvailableController`, which are pending their own future cutovers).
- **M5.1 orphan-controller cleanup: 4 legacy controllers deleted.** Pure-orphan sweep after M4 dashboard cutovers — all four had zero `apiFetch`/`fetch` callers in any `src/` tree AND zero static dependents in PHP. `class-aggregator-controller.php` (3-route stub `/status` + `/servers` + `/health`) → `Aggregator_CI` verbs (`status`/`health`/`servers`); `class-discovery-controller.php` → `Discovery_CI.get`; `class-events-controller.php` → `Events_CI.recent` + `.stats`; `class-status-controller.php` → `Status_CI.get`. The matching test files (`tests/unit/Rest/{Aggregator,Discovery,Events}ControllerTest.php` + `tests/unit/StatusControllerTest.php`) deleted alongside — the M2 `*CITest` files already cover the same surface with broader scope. 4 new gate tests in `M2BootstrapTest` (`class_exists` asserts mirroring the M4 pattern) prevent autoloader-cache resurrection across deploys. **`ServersController` was originally batched here but stayed alive** — `assets/aggregator-admin.js` (enqueued by `Admin::configured_servers_callback` in the WP admin "Configured Servers" UI) still POST/PUT/DELETEs to its `/servers` and `/servers/{id}/test` routes via jQuery AJAX. The original orphan grep used `apiFetch`/`fetch` only and missed the jQuery `$.ajax` call sites. The Servers migration belongs with the `SettingsSync` transport cutover in M5.2 (the admin JS rewrite to `/command` lands there alongside the option-fanout migration).
- **M5.2: 3 legacy controllers deleted (Servers + Settings + PerfSettings).** Two-step migration unblocked deletion of the last user-facing routes that still had live callers.
  - **M5.2a — aggregator-admin.js cutover.** The WP admin "Configured Servers" UI (`Admin::configured_servers_callback`) ran its Test/Toggle/Remove/Add buttons through 4 jQuery `$.ajax` calls against `/servers` REST routes. Rewritten as a wp-scripts entry at `src/aggregator-admin/` so the `@newspack-nodes/runtime` alias resolves and the 4 verbs dispatch through the shared `getCommandClient()` singleton against `Servers_CI.add` / `.update` / `.delete` / `.test`. jQuery stays for DOM glue but the transport path lives in `src/aggregator-admin/api.js`, which is unit-tested independent of the DOM (5 new Jest cases covering each verb's arg composition + the unwrap shape). PHP enqueue updated to load from `build/aggregator-admin/index.js` instead of the legacy `assets/aggregator-admin.js`; legacy file deleted. The matching commit is `3645293`.
  - **M5.2b — SettingsSync transport migration.** `RemoteManager::post_to_server()` previously POSTed `{option, value}` to spokes' legacy `/settings` (substrate keys) or `/performance/settings` (perf-tuning keys) routes. Now wraps the verb args in a TM_COMMAND envelope routed to `Settings_CI.update` or `Performance_CI.settings_update` and POSTs to the unified `/wp-json/newspack-nodes/v1/command` endpoint. The `SettingsSync::ENDPOINT` / `PERF_ENDPOINT` constants stay — they're now category tags that select the verb, not URLs themselves. Basic Auth (the spoke-side authentication mechanism) is independent of the dispatch path and is preserved unchanged. The substrate-key path also strips the `newspack_nodes_` prefix on the wire so the verb's short-name whitelist (num_partitions / num_segments / segment_size / max_lifespan) accepts the args without needing the prefix history. 5 new tests cover envelope shape, URL routing, verb selection per endpoint, basic-auth preservation, and prefix-strip. 17 existing tests updated to assert the new URL + envelope shape. The matching commit is `f17bd69`.
  - **M5.2c — controller deletion.** With zero callers remaining, the three controllers + their test files were deleted: `class-servers-controller.php` (+ `ServersControllerTest.php` + `ServersControllerCrudTest.php`); `class-settings-controller.php` (+ `SettingsControllerTest.php`); `class-perf-settings-controller.php` (+ `PerfSettingsControllerTest.php`). Three route registrations removed from `newspack-event-logger-nodes.php`. Three new gate tests in `M2BootstrapTest` (`class_exists` asserts mirroring the M4/M5.1 pattern) prevent autoloader-cache resurrection. Final suite: 1831 PHP tests (down from 1894 pre-deletion as the 63 dedicated controller tests are gone; 5 new tests are in the count via the M5.2b commit).

### Fixed

- **Service CIs now mount via `$base_ci->make_node(...)` on the substrate's new `newspack_nodes/request_graph_ready` hook.** The previous `rest_api_init` priority-11 path registered CIs by name but didn't wire sinks — verb responses (which route back via `TO=FROM`) had no path to `HTTP_Out` and silently dropped on the floor. `make_node()` atomically instantiates + names + sinks each CI in one step. Requires `newspack-nodes` substrate commit `24921f5`.

### Notes

- Legacy WP REST controllers remain alive in `includes/rest/` through M2. M4 dashboard rebuilds cut over to `/command`; once a dashboard migrates, its legacy controller becomes deletable in M5. See `MIGRATION.md` for the full deletion list.
- SSE controllers (`firehose-stream`, `gyroscope-stream`, `errors-stream`, `requests-stream`, `rawlogs`) stay as REST endpoints. CommandInterpreter dispatch is request/response only.
- Requires `newspack-nodes` substrate with the `newspack_nodes/request_graph_ready` hook (substrate commit `24921f5`).

### Added

- New `completed.log` Partition (compact one-line summary per completed request, 11 fields matching legacy `requests-stream-controller::transform_line` per the M1 schema-parity audit).
- New `gyroscope.log` Partition (compact summaries via Tee fan-out from `completed:tee` + periodic inflight snapshots from the hidden `RequestFlight` sibling, 12 fields per inflight row matching legacy `InflightTracker::get_active`).
- `RequestFlight` sibling Node attached to `RequestBuilder` (Timer-based, hidden via patron filter; mirrors Perl `InstrumentalityFlight.pm`). Default 1000ms interval; `set_interval( $ms )` reschedules. Sink propagates from RequestBuilder via the patron pattern. Fail-safe when sink/target/patron aren't wired yet.
- `set_completed_target`, `set_inflight_target`, `set_inflight_interval` verbs on `request-builder:config` (empty arg clears target; non-numeric interval rejected).
- `request_start_ts` slot on per-request state for the `inflight_snapshot.start_time` field; stamped by the `request` state-callback when the URL keyword arrives. Process-start ts stays on `$request->timestamp` for the `format_index_entry` / `evict_request` paths that still need it.
- `SchemaParityAuditTest` (M1 acceptance gate, `#[Group('parity')]`) — drives BOTH legacy and new emitters through the same input and asserts byte-for-byte field-by-field parity (not just field presence). Tests: `test_compact_summary_value_equivalence_against_legacy_transform_line` (wire-roundtripped envelope → both emitters; every field `assertSame`), `test_inflight_snapshot_value_equivalence_against_legacy_get_active` (same firehose lifecycle through `InflightTracker::process` and `RequestBuilder::fill`; every field `assertSame` except `time_ms` / `est_ms` / `lag_ms` within 50ms tolerance for wall-clock skew), `test_inflight_snapshot_surfaces_runaway_requests_like_legacy` (60-frame stack overflow; both emitters must still expose the request). Deletion of the legacy SSE controllers in M5 requires this test to keep passing.
- `TopologyCompactSummaryTest` integration test — loads each compact-summary-bearing TSL (`firehose-workers-and-jobs`, `firehose-workers-only`) in-process against a real CommandInterpreter + Router and asserts both Partitions register, the Tee fans out to both, and the three `:config` verbs land on RequestBuilder.
- `newspack_nodes/expected_log_basenames` filter — lets substrate's `Log_Cleaner` (≥ v0.1.32) auto-delete entire log directories whose basename no longer belongs to any active topology (e.g. disabling `request-workers` means `flames.log` is no longer produced or read). Filter returns the union of (a) topologies in the application's `topologies` config, and (b) topologies whose worker lock dirs are still on disk via `Cli::ls_workers()`. Per-topology basename map lives in the module-level `NEWSPACK_EVENT_LOGGER_NODES_TOPOLOGY_BASENAMES` constant; update it when topologies are added or their inputs/outputs change.
- Workers dashboard unifies per-log slot rendering across consumed and unconsumed logs. `WorkersController::enumerate_logs()` (was `enumerate_terminal_logs`) walks every `{base}/logs/*.log/` directory and emits per-partition slot entries covering `max(num_partitions, max-p-index-on-disk + 1)` slots per log. The frontend (`WorkerStatus.js`) uses this single source of truth, with cursor data overlaid from `workers[]` per partition where the log has a Consumer. Result: orphan-from-shrink slots render alongside live slots until cleaned; configured-but-empty logs render N empty slots so operators see the configured shape. `setWorkers` / `setStandalone` / `setLogsCatalog` guard with a `JSON.stringify` change-detection compare so steady-state fetches don't trigger a `buildRenderPlan` re-render.

### Changed

- `RequestBuilder` now emits a compact summary on every completion (in addition to its existing full envelope on the primary target). Existing `requests.log` semantics unchanged.
- `RequestBuilder::build_compact_summary` passes through `timestamp` / `duration_ms` / `status_code` / `error_status` without coercion (was force-casting to `(float)`/`(int)`/`(string)`); legacy `transform_line` never cast, so JSON-encoded int-valued floats round-tripped as int through the wire and the new code's `start_time: 1747401234.0` did not match the legacy `start_time: 1747401234`. Removed the casts.
- `RequestBuilder::inflight_snapshot`'s `state` field now carries the top-of-stack hook name (e.g. `render`, `wp_head hook`, or `process` when the stack is unwound) instead of the hardcoded `'active'` literal. Matches legacy `InflightTracker::get_active` semantics.
- Runaway requests (over `MAX_STACK_DEPTH`) now stay visible in `inflight_snapshot` until LRU eviction (matches Perl Gyroscope behavior); previously `fill()` evicted `is_runaway` entries from the cache, hiding them from the dashboard. Memory stays bounded because `push_stack` now caps the stack at `MAX_STACK_DEPTH` (early return at cap) instead of overshooting by one frame before flagging. The `evict_request` path still fires with `error_status='T'` when LRU rotation eventually drops them, so they still land in the completed pipeline.

### Notes

- Topology files `firehose-workers-and-jobs.tsl` and `firehose-workers-only.tsl` now wire `completed:tee` → `completed.log` + `gyroscope.log` and configure `request-builder:config` with the three new verbs. No action required for installations using the bundled topology files.
- Downstream consumers that previously relied on the silent runaway-eviction behavior may now see runaway entries in inflight snapshots. The pipeline still emits the completion event with `error_status='T'`; the behavioral change is visibility, not delivery.

### Tests

- Round-3 coverage push: 93.9% → 94.2% (8197/8701 stmts across 52 classes). 2162 lines of new test assertions across `Admin` (skip_default_writes, sanitize_array_strings/aggregator_servers/custom_events, field-callback placeholder + stored-value renderings, settings-registration shape), `LogManager` (filter normalization + URL routing edge cases), `RemoteManager` (handle_job non-callable/non-array filter returns, request_args header merging for basic-auth/bearer, calculate_lag with missing cursor or unknown segment, sync_setting WP_Error path, queue_sync_all_settings happy path), `Rest\FirehoseStreamController` (additional segment/partition selection branches), `SettingsSync` (fail-closed `enable_workers` polarity coverage), `StreamMerger` (cache/require_https/verify_ssl propagation to children, namespaced_remote_name default, unknown-verb error reply, name-setter sibling propagation + idempotence).

## [0.2.32] - 2026-05-15

**Requires:** [newspack-nodes ≥ v0.1.29](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.29) — for `Node::dump_node()` overridable hook and `serializeTsl` schema-default expansion.

### Fixed

- **Application nodes are visible in the topology editor again.** v0.2.30's plugin-load defer pushed the 8 `CommandInterpreter::register_class()` and 2 `Formatters::register()` calls into the `spawn_worker` action handler. That handler doesn't fire for admin/REST requests, so the topology console's REST schema endpoint saw an empty class registry — every application node (RequestBuilder, FlameBuilder, JobRouter, JobWorker, RemoteSource, StreamMerger, AutoTuner, HealthCheckTick) disappeared from the palette, and selecting an existing one in the editor showed "No constructor arguments. No verbs registered." These calls are `::class` constants (compile-time FQCN strings) into a static hashmap — virtually free at boot. Moved back to plugin load. The genuinely expensive deferred work (`StreamMerger::register_remote_job_rewrite_filter`, `RemoteManager::init`) stays in the `spawn_worker` closure.
- **`Topology_Registry::user_dir()` is populated in admin + REST contexts.** v0.2.30's defer left `set_user_dir()` registered only via the `newspack_nodes/topologies` filter callback and `spawn_worker` action — neither fires for an admin user saving a topology. `TopologiesController` POST then hit `user_dir()` directly and got `''`, returning 500 *"Topology_Registry has no writable user dir."* Wired the existing idempotent closure to `rest_api_init` + `admin_init`.
- **`RemoteSource::dump_node()` redacts auth credentials.** Default `Node::dump_node` reflects every property; `dump_node my_remote` from the REPL printed raw `auth_password` / `auth_token`. Override scrubs both slots to `[REDACTED]` while leaving empty credentials empty. Requires substrate v0.1.29 (override mechanism lives there).
- **`StreamMerger::process_sse_chunk()` no longer calls deleted `drain_test_queue()`.** The synthetic `__test__` RemoteSource test entrypoint had an orphaned method call left over from the queue removal; any caller would have crashed with "Call to undefined method".

### Added

- **TSL-substitution defaults on application verb args.** Mirror the active topology TSL invocations as schema defaults so the editor pre-fills the same tokens the live workers run with: FlameBuilder's `set_is_hub`/`set_auto_tune`/`set_significant_events`/`configure_stats` and StreamMerger's `set_verify_ssl`/`set_require_https`. Adding a FlameBuilder via the palette gets a working out-of-the-box config without re-typing what's already in the TSL files.

### Build

- **`00-newspack-profiler.php` ships as a standalone release asset, not bundled inside the plugin zip.** The mu-plugin file used to be in both places; the in-zip copy showed up in `wp plugin list` as a phantom `newspack-event-logger-nodes/00-newspack-profiler` and risked double-load. Now `.distignore` + `build-release.sh` exclude it from the zip and produce a standalone `release/00-newspack-profiler.php` for the Atomic-side deploy script.

### Tests

- **Coverage push: every class in `includes/` is now ≥80%** (lowest is `FlameBuilder` at 80.6%; total moved from 83.1% → 91.1% across 52 classes). New: `RemoteSourceTest` (110 methods). Existing extended: Admin, HealthCheckTick, JobWorker, StreamMerger, PartitionReader (timing-race fix), `tests/Helpers/TestCase.php` (helper now whitelists per-test temp dir in app Config too — without this, `$extras` overrides were silently rejected and tests saw bundled defaults instead).
- 8 process_sse_chunk tests + 5 drain_test_queue tests refactored or removed (RemoteSource no longer keeps an event_queue / drain_test_queue seam — refactored to assert via CaptureSink on the real production path). Same treatment applied to StreamMergerTest's SSE-parser tests via a new `entry_frame()` helper.

## [0.2.31] - 2026-05-15

### Fixed

- **`LogManager::__construct()` no longer infinite-loops via re-entrant `instance()`.** Production stack: `Core::hook_start` → `LogManager::instance()` → `__construct` → `Config::load_config` → `get_option` → `wpdb->get_results` → `apply_filters( 'query', ... )` → `Core::hook_start` (re-entry) → `LogManager::instance()` (sees null `$instance`) → new `__construct` → … 512 frames, killed by Xdebug. Triggered when an operator includes `query` in `log_events` (very reasonable for SQL profiling) and `alloptions` isn't cached yet so the first option fetch round-trips through `wpdb`. Fix mirrors the existing partial-instance pattern already in `ensure_started()`: assign `self::$instance = $this` at the top of `__construct`, before any code that can fire filters. The re-entrant `instance()` then returns the partial `$this`; `$enabled` defaults to `false` (only set true by `matches_url_filter` at the end of `__construct`), so `hook_start` short-circuits at its existing `! $lm->enabled` guard.

### Tests

- **Regression coverage for the re-entrancy bug.** `LogManagerTest::test_construct_blocks_reentrant_instance` uses a new `$_test_get_option_hook` seam in `tests/bootstrap.php`'s `get_option` stub to simulate the production wpdb→`query`-filter→`hook_start` chain deterministically. Verified failing without the fix ("two variables reference the same object"), passing with it.
- **`MemcachedCacheTest` marked class-level `#[Medium]`.** `test_live_ttl_expires_value` legitimately sleeps 2s to verify a 1s TTL elapses; the default 1s per-test budget under `--enforce-time-limit` was aborting it as risky. With `Medium` raising the budget to 10s the suite now runs 1346/1346 clean with no risky, no skipped.

### Pre-push

- **`scripts/pre-push` is now installed at `.git/hooks/pre-push` in this clone.** Composer's cghooks install had never run here, so prior pushes weren't gated by lint + container deploy + phpunit. The script itself was already in the repo; this is local-clone hygiene.

## [0.2.30] - 2026-05-15

**Requires:** [newspack-nodes ≥ v0.1.28](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.28) — for the `Config::RESET_ACTION` broadcast and the `Supervisor::$curl_exec` test seam.

### Fixed

- **App `Config` cache invalidates alongside substrate `Config`** — the actual root cause behind "only aggregator spawns when `num_partitions` changes". Both classes maintain merged-result static caches, but the supervisor's per-tick `check_config()` only ran the substrate's `Config::reset()`. The app's filter callback in `newspack_nodes/topologies` therefore kept publishing the catalog with a stale `num_partitions`, while the substrate's `synthesize_entry` for `aggregator` (operator-overlay only) saw the fresh value — producing 7 (1 + 3×2) worker descriptors instead of 8. Listener on the substrate's new `Config::RESET_ACTION` invalidates `Newspack_Event_Logger_Nodes\Config::reset_local_cache()` whenever the substrate resets; the listener does NOT call back into substrate reset (would recurse).
- **`StreamMerger::ensure_offsetlog()` was constructing `new Partition( $dir )` with only one arg.** Partition requires `($base_dir, $partition)` — this would have fataled the moment `aggregator_servers` was non-empty. Pinned the inner partition to `0` (Consumer pattern: outer `{source}.p{N}/` dir name encodes the spoke partition; inner Partition is always p0).
- **`WorkersController::read_offsetlog_position()` constructed `Partition( $dir, $partition )` looking at `firehose.p1/p1/`** when files actually live at `firehose.p1/p0/`. Same offsetlog-inner-axis-is-always-0 fix already applied to `read_offsetlog_latest_entry()` last release; method now delegates to that helper.
- **`reqgrep` and dashboards no longer reach a different memcache than workers do.** `PerformanceControllerBase::cache()` (the REST-side request-path helper) called the now-deleted `self::load_config()`; after the substrate dropped its `'full'`-mode toggle and folded `memcache_servers` into the always-loaded core schema, every Memcached_Cache build site reaches the same overlay. See `Memcached_Cache::from_substrate_config()` below.

### Changed

- **REST controllers and SSE/Remote infrastructure migrated from `self::load_config()` → `\Newspack_Nodes\Config::load_config()`** (substrate-only). Affects 14+ controllers under `includes/rest/` plus `RemoteSource`, `SSEControllerBase`, `FirehoseStreamController`. `PerformanceControllerBase::load_config()` no longer exists; subclasses that need app-merged keys call `\Newspack_Event_Logger_Nodes\Config::load_config()` explicitly (only `ServersController::test_connection` needed that path).
- **Plugin-load defer refactor: worker-only initializers moved to the `newspack_nodes/spawn_worker` action handler.** Stops every admin / REST / front-end request from paying for setup it never uses. Deferred work:
  - 8× `CommandInterpreter::register_class()` for app Node subclasses.
  - 2× `Formatters::register()` (`request-index`, `flame-index`).
  - `StreamMerger::register_remote_job_rewrite_filter()` (autoloads StreamMerger — the most expensive of the bunch).
  - `RemoteManager::init()` — verified worker-internal (hooks `job_handlers` filter consulted by JobRouter, plus an action fired from inside a job handler running in the aggregator worker; no wp-cron involvement).
  - `Topology_Registry::set_user_dir()` (needs `Bootstrap::base_dir()` which hits Config) is moved to an idempotent closure invoked from both the `newspack_nodes/topologies` filter callback and the `spawn_worker` action handler.
  - `Topology_Registry::register_stock_dir()` stays at plugin load — single array append, zero config dependency, needed by `Topology_Registry::resolve()` from any context (tests, admin, REST, CLI).
- **`Config` mode dispatching deleted** — mirrors substrate. `$option_schema_extended` (only contained `aggregator_servers`) folded into a single `$option_schema`; dual cache collapsed to single `$config`; `$mode` parameter dropped. `load_config('full')` callers throughout the plugin migrated to `load_config()`.

### Added

- **`Memcached_Cache::from_substrate_config()` factory** consolidates three near-identical `$servers = $config['memcache_servers'] ?? DEFAULT_SERVERS; if ( ! is_array( $servers ) ) ...; new Memcached_Cache( $servers )` blocks across `PerformanceControllerBase::cache()`, `SSEControllerBase::cache()`, and `RemoteSource::cache()`.

### Removed (continuation of pre-release "tree chopping")

- **`StreamMerger::set_logs_dir()` / `$logs_dir` field / `resolve_logs_dir()` helper.** Offsetlog placement now flows from `\Newspack_Nodes\Config::get_offsets_directory()` directly. Tests redirect via `$_wp_options['newspack_nodes_base_directory']` + `Config::reset()` instead of the deleted setter.

### Tests

- **`Supervisor::$curl_exec` test seam** (from substrate v0.1.28) routed through `tests/bootstrap.php`: real curl handle goes through `curl_init` + `curl_setopt_array` + errno classification unmocked; only the actual network call is swapped, with URL captured via `curl_getinfo` and POST body passed in directly as a 2nd seam arg (POSTFIELDS isn't recoverable via getinfo).
- **`Cli_Stdin_Reader::$readline_handler_install` / `$readline_read_char` seams** (from substrate v0.1.28) no-op'd in `tests/bootstrap.php` so phpunit-in-a-terminal stays clean and `fire()` doesn't block on real stdin.
- Stale `remote_firehose.log` test paths updated to the new `{offsets}/aggregator.p{N}/p0/{seg}.log` shape.
- `test_set_logs_dir_resets_offsetlog` deleted (the method it tested was the user's chop).

### Removed

- **`Config::get_option_schema_core/extended()` filter hooks.** The `newspack_event_logger_nodes_option_schema_core` / `…_extended` filter hooks were extension points no plugin used. Replaced with inline `private static $option_schema_core/extended` arrays.
- **`Config::invalidate_cache()` and `Config::register_cache_invalidation()`.** The substrate's `wp_cache_delete( 'alloptions', 'options' )` + `Config::reset()` pair (in `Supervisor::check_config` and the substrate's `Newspack_Nodes\Config`) handles option-snapshot staleness for the only consumer that needed the one-shot reset.
- **`class_exists()` guards in the plugin loader for `Topology_Registry`, `Bootstrap`, `Formatters`, and the `newspack_nodes/topologies` filter callback.** With the deferred-loader pattern (the loader closure runs on `plugins_loaded` priority 11, after the substrate plugin has fully loaded), substrate classes are always present by the time these run; the guards were dead branches.

### Changed

- **Substrate-missing branch in the plugin loader reverts to bare `\error_log(...)`.** Calling `\Newspack_Nodes\Core::print_less_often` in this branch would fatal-error — the branch only fires when `\Newspack_Nodes\Node` doesn't exist, which means `\Newspack_Nodes\Core` doesn't exist either. PHP's `error_log` is the right last-resort for this exact failure mode.

## [0.2.28] - 2026-05-14

### Fixed

- **Workers dashboard renders P1+ partition rows under their parent log.** When `num_partitions` flipped from 1 to 2, P1 worker rows came back from `WorkersController` with empty `handler` / `source` / `input_log` metadata and rendered as orphans floating outside the log groups. Root cause: `read_offsetlog_latest_entry()` was constructing the offsetlog Partition with the worker's partition number (`new Partition($dir, $partition)`), but each Consumer's offsetlog is itself a single-partition Partition with files at `{source}.p{N}/p0/0.log` regardless of which spoke partition it tracks (Consumer constructs it as `new Partition($dir, 0)`). Pinning the read-side Partition to 0 lets the controller find the offsetlog entries for any spoke partition and emit full per-row metadata.
- **Aggregator pulls every spoke partition, not just p0.** `aggregator.tsl` had `var num_partitions = 1` plus a comment claiming "always single-partition by design" — that was a default I'd written into the file with no architectural basis, then cited as if it were the design. With the frontmatter dropped and the StreamMerger ctor switched from `0` to `<partition>`, the supervisor now spawns one StreamMerger worker per partition, each pulling its own slice from each spoke. Previously partitions 1+ on a multi-partition spoke were never aggregated.

### Changed

- **Workers dashboard handler labels show node names, not PHP class names.** `request-builder` / `job-router` / `flame-builder` instead of `RequestBuilder` / `JobRouter` / `FlameBuilder`. Matches what the topology console renders for the same nodes. `WorkersController` no longer emits `target_class`; the React tree title-cases the node name for display (`Request Builder`).
- **`JobIntake` oversized-payload warnings route through `Core::stderr()`.** Drops the bare `\error_log()` call so the diagnostic actually surfaces in the cli session of a pivoted-mode operator.
- **`LogManager` truncation now stores a 1KB preview** (`['m' => substr($data_json, 0, 1000) . '...']` plus a `(truncated)` category suffix) instead of replacing the entire payload with `['truncated' => true]`. Keeps debugging context for oversize entries that LogManager has to drop. The category-suffix delimitation makes truncated rows easy to filter for in `wp nodes reqgrep`.
- **`ServerRegistry::decrypt()` no longer falls back to legacy plaintext.** The pre-encryption fallback path (return the stored value as-is + emit a one-time "legacy plaintext credential detected" warning) is gone. Stored credentials must now be encrypted (with `ENCRYPTED_PREFIX`); anything else returns empty. Operators with legacy plaintext rows get a `has_credentials: false` from the REST endpoint until they re-save the spoke through the admin form (which encrypts on save). Two test cases for the old fallback path were dropped.

**Requires:** [newspack-nodes ≥ v0.1.26](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.26) — for `Core::stderr()`.

## [0.2.27] - 2026-05-14

### Changed

- **`newspack_nodes/topologies` filter callback delegates to `Topology_Registry::synthesize_entry()`.** Drops the inline `resolve` + `frontmatter` + entry-shape dance in favor of the substrate's new shared helper, so both the app's catalog-publishing path and the substrate's admin-overlay fallback build entries from a single source of truth.
- Drops six `class_exists()` guards for same-plugin classes (`Config` × 3, `JobWorker` × 2, `StatusController`, `ServerRegistry`). With the classmap autoloader, these defended against load-order races that can't happen.

**Requires:** [newspack-nodes ≥ v0.1.23](https://github.com/Automattic/newspack-nodes/releases/tag/v0.1.23) — for `Topology_Registry::synthesize_entry()`.

## [0.2.26] - 2026-05-14

### Added

- **Flush Cache button under Settings → Event Logger Nodes → Maintenance.** Mirrors the legacy `newspack-performance-dashboards` "Clear Memcache Stats" button, now backed by the new substrate. Clicking it confirms, then rotates `Stats_Store`'s 8-char salt (every existing `evlog[:salt]:p{N}:…` key orphans instantly; TTL handles cleanup) and requests a graceful restart for every active worker via `Bootstrap::expand_workers()` + `Cli::restart_workers()` — no hardcoded topology names, so future Stats_Store consumers and operator-customized topologies are picked up automatically. The settings page surfaces a `notice-success` reporting how many worker restarts were requested.

## [0.2.25] - 2026-05-14

### Changed

- **`RemoteSource` is now a real Node class — one per enabled spoke in `ServerRegistry`.** Each `RemoteSource` owns its own cURL multi handle (registered with the substrate's `EventFramework`), one cURL easy handle, one SSE connection, and its own `{segment_id, offset}` cursor; `fill()` is a no-op (it's a source like `Tail`), and `dispatch_event()` applies the `newspack_nodes/aggregator_ingest_line` rewrite filter then sinks straight to `firehose:topic`. The class is visible in the topology console (no patron link) — operators see every spoke as `{merger}:remote:{server_id}` instead of an opaque internal map. `StreamMerger` keeps a one-way ref list to its `RemoteSource` children, drives their periodic ticks, and owns the single shared offsetlog Partition — its `commit_all()` walks the ref list and writes one combined JSONL line covering every spoke. `add_remote()` is now an orchestrator method that instantiates a `RemoteSource`, restores its position from the offsetlog, and registers it in `Core`. ~1000 lines of per-remote SSE / cURL / heartbeat / status logic moved out of `StreamMerger` into the new class; the old `$this->remotes[server_id]` flat-array layout is gone.
- **`start_periodic_tick` is no longer a `StreamMerger` verb** — it fires automatically from `name()` on first name set, same pattern `FlameBuilder` uses for its `AutoTuner` sibling. Mandatory + zero-arg + always needed in the aggregator topology, so the verb was pure boilerplate; the `cmd stream-merger:config start_periodic_tick` line is dropped from `aggregator.tsl`.
- **`add_remote` is no longer a `StreamMerger` verb** — the single-arg shape was registry-driven (only `server_id` survived to TSL while url/creds came from `ServerRegistry`), confusing in the Inspector, and redundant with `load_remotes_from_registry` for production hubs. The PHP method stays so `load_remotes_from_registry` can call it.

## [0.2.24] - 2026-05-14

### Fixed

- **Workers dashboard collapsed handlers fed by multiple Consumers into one row.** JobRouter has two upstreams in the firehose-workers-and-jobs topology — `firehose:consumer` (via Tee fan-out) and `jobintake:consumer` (direct, for >4KB jobs) — so the controller emits one row per (Consumer, target) pair. The React side keyed both the render group (`buildRenderPlan`) and the rate cache (`workerKey` for `readRates` / `prevPositionsRef`) by `(type, handler) + partition` only, which (a) packed both rows under one header showing two P0 chips side-by-side and (b) overwrote the firehose JobRouter's cursor delta with the jobintake JobRouter's (which is caught up at the tip), making the firehose row display as "R 0 B/s — stalled" while RequestBuilder on the same Consumer correctly showed ~40 KB/s. Both keys now include `worker.source`, so each (Consumer, target) renders as its own row with its own rate.

### Changed

- **`newspack_nodes/topologies` filter callback publishes the file-default catalog only.** Reads `Config::load_config_defaults()` (not `load_config('full')`), so no `get_option` lookups happen on the application side — the substrate's `Bootstrap::get_topologies()` now owns the `newspack_nodes_topologies` operator overlay. The filter is the catalog; the option is the overlay; the substrate composes them. Drops the brief `newspack_nodes/topologies_defaults` filter that lived here for the admin's `↺` chip.

## [0.2.23] - 2026-05-14

### Added

- **Remote Server Settings admin section** — ported from the legacy `newspack-event-aggregator` plugin. Three int fields under Settings → Event Logger Nodes: Remote Segment Count (2-16), Remote Segment Size (1MB-256MB), Remote Min Retention (60s-7d). Blank fields fall through to the config-file default; SettingsSync's hub→spoke fan-out remaps them to the substrate's `newspack_nodes_{num_segments,segment_size,max_lifespan}` keys on the remote. Previously these were declared in `newspack-event-logger-nodes-config.php` and consumed by `SettingsSync::SYNCED_OPTIONS`, but with no UI + no schema entry there was no way for an operator to actually set them — the sync wiring pushed nothing.
- **JobWorker eager-loads handlers in the constructor.** `load_handlers` was a TSL verb that took no args and just ran the `newspack_nodes/{job,remote_job}_handlers` filter chains — a JobWorker without handlers loaded is dead weight, so it doesn't belong as a config knob. By the time a worker's TSL is evaluated, `plugins_loaded` has fired and every registered handler filter is in place. Drops the verb from `config_verbs()` + `node_schema()`, drops `cmd job-worker:config load_handlers` from `job-workers.tsl`.

### Changed

- **`HealthCheckTick` is now an owned sibling of `StreamMerger`, not a standalone TSL node.** Same Router-TIMER hitchhike pattern FlameBuilder uses to own its AutoTuner sibling — `StreamMerger::__construct()` instantiates a HealthCheckTick, patrons it, names it `{stream-merger-name}:health-check`, and cascades through `name()` + `remove_node()`. A single `start_periodic_tick` verb on StreamMerger kicks off both timers. HealthCheckTick + AutoTuner both flip to `category: 'Hidden'` so the topology console doesn't surface them as buildable nodes (the class stays directly instantiable for tests).
- **`aggregator.tsl` drops the HealthCheckTick lines** in favor of the owned-sibling pattern. The aggregator topology is now just `StreamMerger → Topic`, with HCT implicit.
- **Config layer DRY:** all the sanitize / validate / path-guard primitives that used to be duplicated between the substrate Config and the application Config now live in `Newspack_Nodes\Config_Utils`. The application's `Config::sanitize_option` is an 18-line wrapper that handles the application-specific `aggregator_servers` case locally and delegates everything else; `load_config_file`, `validate_config_values`, `is_within`, `sanitize_string` are gone. Cuts ~210 lines from `includes/class-config.php` (623→412). Requires `newspack-nodes` 0.1.19.
- **Topology TSL files reference `<config:offsets_dir>` instead of building `<config:base_directory>/offsets/...` inline.** Substrate now derives `offsets_dir` from `base_directory` in `WorkerCliCommand` so each Consumer line in the topologies stays terse.
- **`recommended_log_events` defaults trimmed:** dropped a handful of site-specific plugin-deactivation hooks (jetpack-boost, pwa, woocommerce*, wordpress-seo, googlesitekit, wpseo_deactivate) that don't belong in a generic recommended set. Existing installs that already selected those events are unaffected — this only changes what the "Select Recommended" button populates.

### Fixed

- **Substrate `topologies` option is now honored.** The application's `newspack_nodes/topologies` filter callback was reading `$config['topologies']` from its own merged config — but because the app's `load_config` does `array_merge($substrate, $appDefaults)`, the app's file default always shadowed the substrate WP option. Checking boxes in the Topologies admin UI silently had no effect. The callback now reads `newspack_nodes_topologies` directly via `get_option`, using `false` as a sentinel for "operator has never saved" so the app file default still seeds fresh installs.

## [0.2.22] - 2026-05-13

### Added

- **More `set_state()` coverage** across the application Node subclasses so `debug_state <node> 1` surfaces meaningful events for tracing:
  - **`FlameBuilder::emit_auto_tune`** — `AUTO_TUNE_FIRED` with key + count per tune decision. Rare events that operators want to know about whenever they fire.
  - **`JobRouter::fill`** — three `DROPPED` flavors (`invalid_handler`, `non_array_params`, `oversize`) with the handler name and size where applicable. Previously these only emitted rate-limited stderr; debug_state observers see the same event with structured context.
  - **`JobWorker::run`** — `CACHE_FLUSH` on each `wp_cache_flush()` interval trip and `MEMORY_PRESSURE` on the first watermark cross (latched, doesn't re-fire each tick).
  - **`StreamMerger`** — `DROPPED` with `reason=oversize` per remote entry that exceeds `MAX_LINE_BYTES`, and `DISCONNECTED` with server + error + http code on every remote completion (cURL failure, non-200, clean close).

## [0.2.21] - 2026-05-13

### Added

- **Drag nodes to reposition, with snap-to-grid + localStorage persistence.** When the auto-layout makes a placement choice the operator doesn't like, they can drag any node to a new spot. Snaps to half-steps of the auto-layout grid (`X_STEP/2 = 120px`, `Y_STEP/2 = 55px`), anchored at the same `X_PAD`/`Y_PAD` origin the algorithm uses — every other slot is a real auto-layout slot; odd slots are half-step nudges between columns/rows. Persisted via localStorage, keyed by `newspack-nodes:topology:{topology}.p{partition}:positions` so overrides scope per worker fleet. `autoLayout` still runs every poll, so new nodes get sensible defaults and only the user-pinned ones survive. Negative grid indices allowed — a node dragged past the auto-layout origin keeps its dragged position; the viewport math handles any coordinate range. The Reset Layout chip appears in the top-right corner of the canvas (gap below LIVE, above the corner reticle) once at least one override exists; clicking clears every override for this topology+partition.

- **Pan and zoom on the canvas.** Wheel zooms with cursor as anchor (the world point under the cursor stays under the cursor), clamped to a 4x / 0.25x range. Drag on empty canvas pans the viewBox — node drags still work because pointer-down on a node `stopPropagation`s so only background drags reach the pan handler.

- **Click on empty canvas = fit-to-content.** Single click without dragging past a 3px threshold snaps the viewport to the tight bounding box of nodes + a small pad, and deselects any selected node. Replaces the Center button that briefly existed — the gesture is just "click the canvas, it does the right thing." A `dragInOnceCount` ref tracks whether nodes have moved so the fit excludes stale extreme positions on reload.

- **Per-node message rate in the bottom-left of each node card.** Replaces the former "live" label, which was redundant (LIVE in the header already says the topology is alive). Reads from the same `rateRef` the Inspector uses, driven by `rateVersion` for re-render coordination. Formats to two significant figures (e.g. `6664 /s`, `8.15 /s`, `0.42 /s`) so the text fits inside the 196px node width. Hidden below a small threshold so quiet nodes don't fill the canvas with `0 /s` noise.

- **FlameBuilder declares its full fan-out via `target()` override.** FlameBuilder writes to two destinations at runtime that don't flow through `Node::target` — `flames_sink` (injected Partition reference for flame JSONL bulk writes) and `auto-tuner` (hardcoded TO on every `emit_auto_tune` Message). `dump_metadata` only reads `$this->target`, so the topology console couldn't draw those edges. The `target()` override unions the base value with both — same pattern `RequestBuilder::target()` uses for its conditional `errors_target`. Runtime paths unchanged; `flame-builder` now shows both edges in `ls -al` and renders fan-out properly on the topology canvas.

### Fixed

- **Topology endpoint added to `skip_urls`** so the SSE stream and its companion POST don't get logged as long-running requests (the stream is a ~10-minute SSE session) polluting per-URL dashboards. Same mechanism the firehose stream already uses. A `worker_type` stamp follow-up is queued — that fix needs the LogManager-restart dance to land the env var before process_data is captured.

- **Auto-layout grid constants exported** (`X_STEP`, `Y_STEP`, `X_PAD`, `Y_PAD`) so the snap logic uses the canonical values from `utils/autoLayout.js`. Keeps "where can a node sit?" in one place.

## [0.2.20] - 2026-05-13

### Added

- **Auto-layout: no more crossing streams.** Three refinements to the layered graph drawer in `utils/autoLayout.js`:

  1. **Push-forward depth pass.** Walks nodes in decreasing-depth order and advances each to `min(target_depths) - 1` when that shifts it right. A node with no incoming but a far-away target used to sit in col 0 with a long forward edge cutting across intermediate columns (`jobintake:consumer` → `job-router` curving under `firehose:tee`). Now it sits one column before its earliest target.

  2. **Straightness tiebreaker in deconflict.** When two column-mates both want the same row, the one with more edges (in + out) whose other endpoint is at that row keeps it; the other bumps. Previously alphabetical tiebreaking would give an unrelated leaf the row and break a linear chain — `job-router → jobs:tee → jobs:partition` zigzagged because `errors:partition` alphabetically beat `jobs:tee` at row 0.

  3. **Barycenter snap (not min) in pass 2.** Pass 2 was using `Math.min(...targetRows)` to pull producers to the topmost target row, which collapsed everything to row 0 and forced pass 3 to bump column-mates in a way that re-introduced crossings. Switched to `Math.round(mean)` — a producer with one target at row 0 and one at row 1 now snaps to row 0 or 1 instead of always row 0, giving the natural barycenter ordering room to win.

  End result on a representative `firehose-workers.p0` graph: zero edge crossings, two parallel spines (`jobintake:consumer → job-router → jobs:tee → jobs:partition` on row 0, `firehose:consumer → firehose:tee → request-builder → errors:partition` on row 1), and `firehose:tee`'s fan-out to `job-router` arcs cleanly upward without crossing.

- **Inspector collapses when nothing is selected; palette hidden in v1.** The permanent "Select a node to inspect" empty state was 308px of dead pixels. The palette is a v2 edit-mode affordance (drag node types onto canvas) that's scaffolded but inert in v1. Removing both reclaims the full window width for the canvas; selecting a node re-opens the inspector via `is-inspector-open` on `.topology-app`.

- **Identity section renders real `arguments`.** The Inspector's Identity section was hardcoded to "—". `parseMetadata` was already extracting `arguments` from `dump_metadata`'s payload — now displayed with a smaller-mono variant so long Partition paths (`/tmp/.../requests.log 0 67108864 2 86400`) wrap cleanly inside the 308px column. The `MAKE_NODE` meta tag now earns its label — the section literally renders `make_node <class> <name> <arguments>`.

### Fixed

- **`stylelint` no-descending-specificity escape.** `.topology-repl__bar .topology-repl__toggle` (nested inside `&__bar`) had higher specificity than the bare `&__toggle` rule but appeared first in source order. Reordered the source so the less-specific `&__toggle, &__clear` block precedes `&__bar`. CSS output is unchanged.

### Chores

- **Locked `brainmaestro/composer-git-hooks` in `composer.lock`** for the same cghooks-installer setup determinism as newspack-nodes v0.1.17.

## [0.2.19] - 2026-05-13

### Added

- **Topology Console v2 — single-poll architecture + authoritative button state.** Replaces v1's `ls -als` (initial) + `ls -ct` (per-second) text-table polling with a single `dump_metadata` JSON poll per tick. One verb returns class, counter, sink, target(s), debug_state, arguments, **and** the new per-node counters (largest_msg_sent, bytes_read, bytes_written) in one envelope. Inspector button states — TRACE active when `debugState > 0`, CONNECT active when our session's `_repl/_output/{pid}` is in the Tee's target list — are now derived from authoritative server data on every poll, eliminating the drift risk of client-side bookkeeping.

- **Inspector Throughput section adds `lgst_msg` / `read` / `written` rows** with K/M/G byte-suffix formatting. Logic nodes show 0 (their fill overrides don't run base tracking); I/O nodes (Partition) show real numbers — `requests:partition` ~500KB written, `request-builder` ~74KB largest message on a representative install.

- **Worker uptime in the LIVE button.** Visible immediately without selecting a node — "alive" + "for how long" in one glance. Fixed 48px right-aligned slot reserves space so the LIVE button doesn't widen tick-to-tick (zero-padded seconds/minutes via the substrate verb keep the value steady at character level too). Em-dash placeholder until the first `gui:uptime` poll lands (~5s after connect). Also rendered in a dedicated Process section in the Inspector for in-context reference.

- **Process panel hooks** — SSE controller fires `uptime` every UPTIME_INTERVAL_S (5s) with KEY=`gui:uptime` alongside the per-second `dump_metadata` poll; TopologyConsole routes `gui:uptime` responses to a `uptime` state bucket via a regex match on the verb's text response.

- **Per-topology partition count** in the partition dropdown — `NewspackNodesData.topologyPartitions` is now injected from the `newspack_nodes/topologies` filter results, the same authoritative map the supervisor uses to spawn workers. Switching to a topology with fewer partitions snaps the selector back to p0 to avoid streaming from a non-existent worker.

- **Selected-node bold edge highlight** — selecting a node now applies the same `is-touched` highlight to its connected edges that hovering does, but without the `is-dimmed` fade on unconnected edges. The rest of the graph stays at full intensity so context is still readable. Hover still owns full focus mode (bold + dim) when active.

### Fixed

- **WP admin chrome wasn't being hidden.** The page's actual body class is `event-logger_page_newspack-nodes-topology`; none of the three hand-enumerated selectors matched, so #wpcontent kept its 20px padding-left, #wpbody-content kept its 65px padding-bottom, and #wpfooter stayed visible. Replaced with `body[class*="_page_newspack-nodes-topology"]` so the rule survives parent-slug renames. Console now spans flush against the collapsed admin sidebar and the right viewport edge.

- **CONNECT button toggle never flipped.** The worker's input Partition is named `_repl`, so it stamps `_repl/` onto every incoming command's FROM before CommandInterpreter sees it. `connect_node <tee>` from this SSE session therefore lands in the tee's target list as `_repl/_output/{sse_pid}`, not the bare `_output/{sse_pid}` Inspector was checking for. Now matches the stamped form.

### Changed

- **`parseLsOutput` → `parseMetadata`** for the canvas parse layer. JSON object input instead of pseudo-`ls -al` text parsing; exposes `lgstMsg` / `bytesRead` / `bytesWritten` (camelCase JS, substrate keys stay snake_case on the wire) on each node entry. 8/8 tests passing.

## [0.2.18] - 2026-05-13

### Added

- **Topology Console.** New admin page at `admin.php?page=newspack-nodes-topology` that renders any live worker's node graph as an engineering-schematic SVG canvas, fed by a long-lived SSE stream that's effectively a pivoted-REPL session over HTTP. Auto-fired `ls -als` (initial) + `ls -ct` (every second) keep the canvas in sync; the worker's CommandInterpreter response carries `KEY='gui:auto'` so frontend routes them silently to the canvas-parse path. User-typed commands carry no KEY and their responses surface in a collapsible cli-faithful transcript pane.

  Architecture: backend `TopologyStreamController` extends `SSEControllerBase`; on connect it opens the worker's input + output partitions (same paths `wp nodes cli` uses), writes a TM_COMMAND for `ls -als` and forwards every reply the worker emits to `_output/{sse_pid}` as an SSE `msg` event. Frontend uses `useTopologyStream` (callback pattern — every message handled synchronously so React state batching can't drop responses under broadcast pressure) and a Dumper-style renderer that unwraps TM_COMMAND envelope payloads, prefixes `ERROR:` on TM_ERROR variants, computes RTT for TM_PING bounces, etc.

  REPL has full cli vocabulary via a `POST .../command` companion endpoint that accepts `{type, name, arguments, to, sse_pid}` and builds the right Message envelope: `ping`/`tell`/`send`/`send_eof`/`request`/`cmd`/`<verb>` all work, plus local builtins `clear` and `debug_level`. Typing `help` prepends a `### SHELL BUILTINS ###` blurb (mirroring Perl Tachikoma's `CommandInterpreter::help` shell-then-server concatenation) before forwarding to the worker's authoritative help verb.

  Drafting-room aesthetic: paper background with 24px+96px grid overlay, ink outlines, oxide/brass/sage/cyan accents, corner alignment reticles, bottom-right title block, JetBrains Mono body + Major Mono Display brand + Workbench display fonts. Three-pane layout (palette / canvas / inspector) plus header and REPL footer. Canvas auto-layout: barycenter row ordering + snap-to-first-target second pass + deconflict pass; edges are cubic beziers with hover-highlight neighborhood (point at any node and its connected edges light up in oxide, the rest dim). Inspector surfaces `target →`, `also →`, `sink ↦`, `← from` distinctly, with `sink` populated from the new `ls -als` SINK column.

- **`RequestBuilder::target()` advertises both `errors_target` and the primary target.** Mirrors the Perl Tachikoma `RegexTee::owner` pattern — `ls -al`'s TARGET column now reflects request-builder's full fan-out (`requests:partition, errors:partition`) instead of orphaning `errors:partition` as a node with no inbound edges.

### Changed

- **Reqgrep spool stores raw lines verbatim instead of decode-mutate-re-encode.** `process_line` previously decoded each firehose envelope, extracted `VALUE` into an `$entry` array, mutated `$entry['rid']` from `Message::KEY`, re-encoded as entry-shape JSON, and stored that. Raw mode then echoed the re-encoded entry (presenting it as "raw"); formatted mode decoded again at output. That's 1 decode + 1 encode + 1 decode per line where 1 decode is enough. Now `process_line` decodes once for control flow (rid, k) and spools `$line` as-is; `output_request` decodes per line in formatted mode and unwraps envelope vs. entry shape on the fly. Raw mode now shows what was actually on disk (wire envelope for disk reads, entry-shape JSON for stdin pipes), which is what `--raw` should mean. `output_request` takes `$rid` as a parameter from callers (all four sites already have it in scope), eliminating the per-output scan-for-rid loop.

### Removed

- **`ReqgrepCommand::truncate_line_message` and `MAX_ENTRY_MESSAGE_LENGTH = 1024`.** The `m`-field truncation existed as defense-in-depth, but `LogManager` already PIPE_BUF-caps lines at 4KB and `RequestBuilder` already truncates `m` to 1KB at source for `requests.log`, so on the canonical disk path the function was a no-op for almost every line. It only ever fired on stdin-fed lines from non-canonical producers. The per-request byte cap (`MAX_BYTES_PER_REQUEST`) is the real memory-blowup defense and remains; raised it from 1MB to 10MB to give stdin-fed inputs more room. Three tests removed: `test_truncate_oversized_m_field`, `test_truncate_line_message_does_nothing_when_under_cap`, `test_truncate_line_message_skips_when_m_is_not_string`.

- **`BC-rid-in-key` back-fill fallback dropped from every wire-format consumer.** All seven marker sites (`RequestBuilder::fill`, `InflightTracker::process_line`, the firehose / errors / events SSE controllers, `reqgrep_command::ingest_line`, and the `LogManagerTest` extract helper) now read `rid` from `Message::KEY` unconditionally. Pre-v0.2.17 segments that embedded `rid` inside the inner entry are no longer recognized — the `??=` / two-branch fallback was load-bearing only while pre-cutover segments still lived on disk, and those have rolled off retention by now. `RequestBuilder::fill` simplifies from a two-branch read to a single assignment from `Message::KEY`; the other six sites change from `$entry['rid'] ??= …` to `$entry['rid'] = …` (canonical back-fill, no longer conditional). Companion comment cleanups in `LogManager::message`, `StreamMerger::forward_entry`, and `StreamMergerTest::test_forward_entry_uses_rid_as_key` drop the "old vs new segments" framing — there's only one shape now.

## [0.2.17] - 2026-05-12

### Changed

- **Wire-format KEY now carries the request id, not the URL.** `LogManager::message()` writes `Message::KEY = request_id`, drops the redundant `rid` field from the inner entry, and routes partitions by `CRC32(request_id) mod N` instead of `CRC32(request_url) mod N`. Resolves a long-standing producer asymmetry: `LogManager` had been using the bare REQUEST_URI as KEY while `Gyrobase::Log` (Perl, gyrate's writer) used the full `scheme://host/path`, so WP-side and gyrate-side entries for the same request hashed to *different* partitions and `RequestBuilder` (per-partition) never reconciled them. After this change every entry for a single request — WP lifecycle, nested gyrate render, jobs spawned from it, errors and flames emitted downstream — co-locates in one partition by construction. As a side effect, on-disk entries drop ~40 bytes per line (the duplicated `"rid":"…"`) and the partition-routing input becomes opaque (32-char base36) rather than user-influenced URL bytes.

  Co-locators (`RequestBuilder::emit_request`, `RequestBuilder::emit_error`, `FlameBuilder`'s flame-emit) now stamp `Message::KEY = rid` too, so the requests/errors/flames partitions stay co-located with their firehose partition and StreamMerger's hub-side partition write inherits the rid as well (was incorrectly `$data['url']` which never existed in our entry shape).

- **Raw logs dashboard prefixes each line with `<KEY>: ` when wire KEY is non-empty.** The dashboard already dropped the wire-format envelope and rendered just the entry JSON; surfacing the KEY makes the partition-routing key (rid for firehose / requests / errors / flames, handler for jobintake, etc.) visible without forcing the JS to decode the envelope itself.

### Compatibility

- **Pre-cutover segments keep working uniformly.** Every wire-format consumer (`RequestBuilder`, `InflightTracker`, firehose / errors SSE, raw-events listing, reqgrep, StreamMerger's hub-side ingest) back-fills `entry['rid']` from `Message::KEY` when the inner entry lacks rid — and leaves legacy entries' embedded rid alone via `$entry['rid'] ??= …`. All seven back-fill sites carry a `BC-rid-in-key` marker comment so they're easy to grep and remove once pre-cutover segments have rolled off retention. `LogManager::message()` also defensively `unset()`s any caller-supplied `rid` in `$data` so misuse (or hostile `message($k, $_POST)`) can't smuggle a fake request id — previously the explicit `rid =>` slot on the left of the `+` union overwrote user values; now the slot is empty so we strip explicitly.

  Companion change in `newspack-gyrobase` (Perl): `Gyrobase::Log::_pack_message()` writes `KEY = $requestid`, `queue_job` / `queue_whack_a_cache` / `_write_entry` stop emitting the embedded `"rid":` field. Required for the producer-side change to land coherently on hosts that run both LogManager and gyrate against the same firehose directory.

## [0.2.16] - 2026-05-12

### Fixed

- **StreamMerger rewrite of `k:"job"` → `k:"remote_job"` was silently undone for entries that carry a redundant `m.type`.** Producers that wrote `m.type='job'` alongside the entry-level `k:"job"` (PHP LogManager whack-cdn, pyrobase cron-manager, evtemplate; nuclear-gyrobase whack-a-cache + nuclear-cron) doubled up the dispatch field. StreamMerger's rewrite filter mutated only `k`, leaving the inner `m.type` stale. JobRouter then resolved the normalized `type` from `m['type'] ?? entry['k']` (line 82-84), so the stale `m.type='job'` won and the entry dispatched against the LOCAL `newspack_nodes/job_handlers` instead of `newspack_nodes/remote_job_handlers` — exactly the bug the rewrite filter exists to prevent. Aggregator hubs saw "first job rewrites, all subsequent ones revert." JobRouter now treats `entry['k']` as the canonical dispatch field uniformly across firehose and jobintake branches; the redundant producer-set `m.type` is no longer consulted (and was independently stripped from producer sites in companion releases of newspack-pyrobase, newspack-nuclear-gyrobase, and newspack-gyrobase).
- **`ServerRegistry::get_all()` resurrected just-deleted servers until `plugins_loaded`.** It read `aggregator_servers` via `Config::load_config('full')`, which caches the merged file+WP-option view at first read. Since `OPTION_KEY === 'newspack_event_logger_nodes_aggregator_servers'` ALSO lives in the option schema, the cached `aggregator_servers` already held the merged value at create-time. After a subsequent delete, the WP option went empty but the cache held the pre-delete merged view; `array_merge(stale-cache, empty-option)` returned the deleted entry. Admin UI showed the server back until the next page-level `plugins_loaded` cache reset. Switched to `Config::load_config_defaults()` (file-only, no WP-option layering) — mirrors what `is_config_server()` already does for the same reason.

### Tests

- **SettingsSyncTest aligned with `enable_aggregator` gate.** The sync gate moved from `enable_workers === true` to `enable_aggregator === true` in commit `9368e73` (intentional refactor — single operator switch for cross-site activity), but the tests still configured `enable_workers`. Tests that expected dispatch silently broke (they passed for the wrong reason on the skip-cases since both keys were missing); fail-cases that expected NO dispatch coincidentally passed. Renamed methods + config keys to match production. Also relaxed the `register_synced_settings` endpoint assertion to accept both `/v1/settings` (core options) and `/v1/performance/settings` (perf-tuning options) — the dual-endpoint shape that already shipped alongside the perf settings split.

## [0.2.15] - 2026-05-12

### Fixed

- **Request Log dashboard scroll jumped / lost place when two arriving entries shared the same `rid`.** The virtualized list keyed each `StreamRow` on `${rid}-${timestamp}` — if a worker reset and rebuilt within the same second, or an aggregator pulled a colliding rid from a spoke, two entries collapsed into one React key and the virtualizer reused a single DOM node for both rows. Subsequent unshift-then-truncate cycles then shifted entries between keyed positions, breaking smooth scroll. There's already a monotonic `entryCounterRef.current` advanced once per SSE-received entry (it was driving the even/odd row stripe); stash that value on the entry as `seq` at receive time and key the row on `entry.seq`. Guaranteed unique per mount regardless of rid/timestamp collisions. Mirrors the URL Detail view fix (which avoids the problem upstream by deduping requests by rid in the URLs controller before they reach React).

## [0.2.14] - 2026-05-12

### Fixed

- **Cron-backstop supervisor run was logging itself as a 595s `/wp-cron.php` request with no `worker_type`, counting toward global averages.** Order of operations: WP-Cron fires `newspack_nodes/supervisor` inside a `/wp-cron.php` request → LogManager initializes for that request and captures `process (start)` (no `worker_type` env var set yet) → substrate's `run_supervisor_tick` invokes `Supervisor::run()` which only sets `$_SERVER['NEWSPACK_NODES_WORKER_TYPE']='supervisor'` after LogManager has already buffered process_data → 595s tick runs to completion → LogManager finalizes a `/wp-cron.php` request that's missing `worker_type` → RequestBuilder doesn't recognize it as a worker and includes it in global stats. The self-respawn path doesn't have this bug (its REQUEST_URI matches `skip_urls` so LogManager is disabled for the parent process). Wrapped the substrate's new `newspack_nodes/before_supervisor_run` / `after_supervisor_run` actions (substrate 0.1.6) with `JobWorker::begin_job_context('newspack-nodes/supervisor')` / `end_job_context()`, mirroring the HealthCheckTick fix: a fresh LogManager builds under `/jobs/newspack-nodes/supervisor` with `worker_type='supervisor'` captured at init (the substrate sets the env var BEFORE firing the wrapping action). Requires `newspack-nodes` 0.1.6.

## [0.2.13] - 2026-05-12

### Fixed

- **`skip_default_writes` was a no-op for bool-defaulted options after the 0.2.12 strict-comparison fix.** Config-file defaults for `enable_logging`, `enable_workers`, `enable_jobs`, `enable_aggregator` are PHP `bool true/false`, but the bool sanitize_callback (`absint`) produces `int 0/1` — strict `!==` between an int value and a bool default never matched, so the filter never trimmed the options-table row even when the user's value was equivalent to the file default. Normalize a `bool` default to `int` before the strict compare; other types (int, string, array) are compared as-is. Net result: setting a bool option to its file-default value now drops the row, so subsequent file-side changes to the default actually take effect.

## [0.2.12] - 2026-05-12

### Fixed

- **Bool settings (`enable_logging`, `enable_workers`, `enable_jobs`, `enable_aggregator`) could get stuck "off" — checking the box and saving didn't take effect.** `skip_default_writes`'s `$value != $defaults[$key]` used loose comparison, so a user-saved `int 1` matched a config-file `bool true` default (`1 == true` is true loosely) → the filter called `delete_option()` and returned `$old_value` to WP, which then early-exited with "no change" → option remained at its previously-stored `0`. Switched to strict `!==` so int-vs-bool no longer collides, and changed the cleanup-side check to `$value !== $old_value` so the filter only touches the row when the value is actually changing. The filter still does its job for array-defaulted options (`log_events`, etc.); for bool-defaulted options it becomes a no-op (the options table stores `1`/`0` redundantly, which is the legacy behavior anyway).

## [0.2.11] - 2026-05-12

### Fixed

- **URL table's status-code columns showed `-` and "Errors Only" button didn't filter anything.** Both were the same bug: `PerfOverviewController::load_index()` aggregated `count_2xx/3xx/4xx/5xx` correctly from each bucket but dropped them when constructing the display shape that gets sent to React. Every URL row arrived at the table with those four fields undefined → `pct()` returned "-" for every status column, and `classified = 0 + 0 + 0 + 0` so `classified < count` was true for every URL → clicking "Errors Only" appeared to do nothing because every URL "matched". Carried the four counters through the result initializer, the per-bucket accumulator, and the final `$out[]` shape that the REST response ships.

## [0.2.10] - 2026-05-12

### Fixed

- **Aggregator hub was dispatching spoke-originated jobs against local handlers.** `StreamMerger::register_remote_job_rewrite_filter()` was defined but never called from anywhere. Without the filter active, spoke-sourced `k:"job"` lines passed through to JobRouter / JobWorker untouched and got dispatched against `newspack_nodes/job_handlers` — meaning every spoke's pyrobase-cron tick ran locally on the hub, the hub's batcache-purge for `whack-a-cache` fired against the wrong site, and the AGENTS.md "hub vs spoke routing" contract was effectively broken. Wired the registration from the aggregator topology so it runs once per spawn.

### Changed

- **`LogManager::message()` now returns `false` when `ensure_started()` fails**, with the early-return placed at the top of the function so every write path (`error`, `warning`, `info`, `start`, `complete`) inherits it from the single chokepoint. `start()`'s redundant pre-check was removed, and `start()` now examines `message()`'s return value before pushing to `$this->times` — so when the LM is disabled (skip_urls match) or shutting down, the timer stack doesn't accumulate orphan bookkeeping for a `(start)` emit that never landed.

## [0.2.9] - 2026-05-12

### Fixed

- **`HealthCheckTick` enqueues silently dropped.** The aggregator topology worker runs with `REQUEST_URI=/wp-json/newspack-nodes/v1/workers/spawn` (in the default `skip_urls` so the spawn endpoint doesn't pollute the firehose with its own request lifecycle). LogManager's URL filter therefore set `enabled=false` at construction, and the singleton's `ensure_started()` never ran `init_firehose()` — so every subsequent `message('job', …)` from HealthCheckTick returned false without writing. Wrapped the enqueue in `JobWorker::begin_job_context('health-check-tick')` so a fresh LogManager is built under `REQUEST_URI=/jobs/health-check-tick`, which clears `skip_urls` and lets the periodic sweep land in firehose.log. `end_job_context` restores the suspended parent.

### Other

- Companion fix in `newspack-pyrobase` `CronManager::run_job_now` and `newspack-nuclear-gyrobase` `CronManager::run_job_now` — same skip_urls-disables-LogManager symptom, same `begin_job_context` wrap. Those plugins ship independently; without their commits the hub's pyrobase-cron and nuclear-cron periodic ticks stay invisible too.

## [0.2.8] - 2026-05-12

### Fixed

- **`flush_every_line` debug toggle was dead.** `LogManager::__construct` read the flag from Config but `message()` never branched on it, so flipping the Debugging-section toggle had no effect. Now calls `$this->topic->flush()` after each message when set — matches legacy `LogManager::message()`'s behavior.
- **`RemoteManager::sync_all_settings()` and `queue_sync_all_settings()` silently skipped `newspack_nodes_num_partitions`.** The prefix-strip only handled `newspack_event_logger_nodes_`; substrate-prefixed keys never matched a config slot and were quietly dropped. Both methods now use the same two-prefix `foreach + break` strip as `SettingsSync::maybe_queue_static_sync` — single source of truth across all three call sites.
- **`SettingsSync::on_option_update()` (instance-mode dispatch) gated on `enable_workers`, the static path gated on `enable_aggregator`.** Split polarity meant a hub configured for aggregator fan-out could skip the instance-mode path while still firing the static one. Both paths now use `enable_aggregator === true`, the documented single operator switch.
- **REST settings controllers wrote options with autoload defaulting to `true`.** Legacy passed `false` explicitly. Restored — keeps the options-cache footprint bounded as `log_events` / `significant_events` etc. grow.

## [0.2.7] - 2026-05-12

### Fixed

- **Unchecking "Enable Performance Workers" (and "Enable Aggregator") didn't stick.** Two settings registered their `sanitize_callback` as `fn ($v) => (bool) (int) $v`, storing bool `false` in the options table. `get_option()` returns `false` for BOTH missing-option AND stored-false, so `Config::load_config()`'s `false !== $value` guard treated stored-false as "absent" and the file default (`true`) shadowed the unchecked state on the next page load. Switched both registrations to `'sanitize_callback' => 'absint'`, matching every other bool option in the plugin and the legacy `newspack-event-logger-plugins` flow.

## [0.2.6] - 2026-05-12

### Fixed

- **Worker Status "Restart" buttons returned `Invalid or missing security nonce`.** The plugin localized only `NewspackNodesData.nonce` (action `wp_rest`) but the restart endpoint checks against action `newspack_nodes_restart_worker`, and the React tree reads `NewspackNodesData.restartNonce` — neither side matched the other. Added a second `restartNonce` to `wp_localize_script` keyed to the right action.

## [0.2.5] - 2026-05-12

### Fixed

- **Periodic settings sweep silently never reached spokes for most options.** HealthCheckTick fired, `health_check` job dispatched, `RemoteManager::health_check()` ran, but `sync_all_settings()` did per-option HTTP POSTs inline from the JobWorker — and the long-running worker's cached `Config` + `ServerRegistry` singletons hid post-spawn option saves and registry mutations from the sweep. Two changes:
  - `RemoteManager::health_check()` now calls `queue_sync_all_settings($enabled_server_ids)` instead of `sync_all_settings()` inline. Each setting becomes its own `sync_setting` job (via JobIntake → jobintake.log), visible in jobs.log and dispatched independently by JobWorker. Matches the legacy `newspack-event-aggregator`'s flow.
  - `HealthCheckTick::maybe_enqueue()`, `RemoteManager::sync_all_settings()`, and `RemoteManager::queue_sync_all_settings()` now call `Config::reset()` + `ServerRegistry::get_instance()->reset_cache()` before reading their gates. Matches the explicit `reset_cache()` legacy plugin had at the top of `sync_all_settings` for exactly this scenario.

## [0.2.4] - 2026-05-12

### Fixed

- **Hub-to-spoke settings sync returned `HTTP 400` for every performance-tuning option** (`log_events`, `custom_events`, `significant_events`, `log_urls`, `skip_urls`, `auto_disable_threshold`, `auto_protect_time_threshold`, `log_memory`, `flush_every_line`). `SettingsSync` was POSTing all options to `/wp-json/newspack-nodes/v1/settings`, but that endpoint's `SettingsController` only whitelists the 4 substrate keys (`num_partitions`, `num_segments`, `segment_size`, `max_lifespan`). The 9 perf-tuning options are owned by `PerfSettingsController` at `/wp-json/newspack-nodes/v1/performance/settings`. Added `SettingsSync::PERF_ENDPOINT` and updated three call sites (`register_synced_settings`, `maybe_queue_static_sync`, `AutoTuner::persist`) to pick the right endpoint per option type.

## [0.2.3] - 2026-05-12

### Added

- **`HealthCheckTick` Node** in the aggregator topology — drives `RemoteManager::health_check()` (discovery sweep + `sync_all_settings` fan-out) on a 5-min debounce. Hitchhikes on `_router`'s TIMER like StreamMerger, enqueues a `remote_manager` health_check job through LogManager so the JobWorker handles request_id correlation and STALE_THRESHOLD drops uniformly. The cutover from `newspack-event-logger-plugins` ported StreamMerger's tick but dropped the legacy `newspack_event_logger_supervisor_periodic` listener that drove the periodic sweep — without this node, freshly-enabled aggregators never push settings to spokes.
- **Targeted full-settings sweep when a spoke is created or re-enabled.** `ServersController::create_item` (with `enabled=true`) and `update_item` (when `enabled` flips false→true) now call `RemoteManager::queue_sync_all_settings([$id])` so the new/re-enabled spoke catches up immediately instead of waiting for the next HealthCheckTick.

## [0.2.2] - 2026-05-12

### Added

- **`LogManager::flush()`** — public method that drains every materialized `Partition`'s in-memory batch to disk. The 4KB write buffer didn't disappear during the TM_STRUCT cutover; it moved down to `Partition::$batch`, and `Topic::flush()` became the drain API. But that API was only being called internally by `LogManager::suspend()` and `LogManager::finish()` — external callers (nuclear-gyrobase's request/pipe paths, pyrobase's template execution) that hand off to a subprocess writing to the same firehose had no way to drain the parent's batch before `proc_open`, so messages could land out-of-order on disk between the parent's accumulated entries and the child's appends. `flush()` is the rename-equivalent of legacy `LogManager::flush_buffer()` and restores that contract.

## [0.2.1] - 2026-05-12

### Fixed

- **Substrate "Total Log Storage" estimate was 0 MB** because nothing registered with the `newspack_nodes/num_logs` filter, so the substrate multiplied `segment_size × num_segments × num_partitions × 0`. The plugin now declares its 6 log streams (`firehose`, `jobintake`, `requests`, `errors`, `jobs`, `flames`) — each obeys the same per-partition segment geometry, so the count alone is enough for the arithmetic.

## [0.2.0] - 2026-05-11

### Removed

- **Five dead extension filters.** Each had zero registrants — they were "for extensibility" placeholders that the topology-based architecture made redundant:
  - `newspack_event_logger_nodes/log_readers` — Workers/Discovery used to look up reader I/O paths; now they read the static `WorkersController::WORKER_INPUTS` map directly. Topologies own the wiring.
  - `newspack_event_logger_nodes/log_reader_positions` — paired with `log_readers`; positions now come from the memcache live-position lookup or the on-disk offsetlog.
  - `newspack_event_logger_nodes/worker_restart_groups` — the Admin's restart map is static.
  - `newspack_nodes/firehose_logs` — `FirehoseController::get_available_logs()` returns the static topology output set directly.
  - `newspack_nodes/config` — `PerformanceControllerBase::load_config()` now returns `\Newspack_Nodes\Config::load_config('full')` merged over its documented defaults, no filter wrapper.
- **DiscoveryController's `calculate_lag` / `calculate_position_difference`.** Both depended on the deleted reader filters; lag is no longer reported in the discovery response.

### Added

- **`TestCase::use_base_dir($dir, $extras = [])` helper** replaces the per-test `add_filter('newspack_nodes/base_dir', ...)` pattern. Writes a tmp config file and points `LOCAL_NEWSPACK_NODES_CONF` at it. `$extras` lets the test add other config keys (e.g. `num_partitions`, `log_events`).
- **Permanent test config baseline** at `tests/newspack-event-logger-nodes-test-config.php`, wired via `LOCAL_NEWSPACK_NODES_CONF` in both `phpunit.xml` and `tests/bootstrap.php`.

### Changed

- **`get_live_position` cache key now includes hostname**, matching the substrate's Consumer-side change. Required for shared-memcache deployments where render1/render2/hub all write the same on-disk `{base_dir}` path; otherwise their live-cursor entries collide.

### Fixed

- **`StreamMerger::forward_entry()` writes `TM_STRUCT` with a parsed array now**, not `TM_BYTESTREAM` with a JSON string. `RequestBuilder::fill()` gates on `TM_STRUCT` (line 163 — `if (!($type & TM_STRUCT)) return`), so every spoke entry was being silently dropped: render1/render2 traffic landed in the hub's `firehose.log` but `RequestBuilder` saw it and immediately returned, so no completed-request rows were written to `requests.log`, no stats reached memcache, and the Performance Dashboard never showed admin.tucsonweekly.com URLs at all. Now matches the shape `LogManager::message()` writes locally — `TM_STRUCT`, `KEY=url`, `VALUE=parsed array`.
- **`StreamMerger::forward_entry()` now stamps `Message::TO` before sinking.** Without it, every entry message hit `_command_interpreter → _router` with an empty `TO` and the router dropped it (sent to its own null sink). Result: `stream-merger` counter advanced on every received `entry` event but `firehose:topic` counter stayed at 0 — spoke traffic looked dead from the hub's perspective even when SSE was healthy. Tail and Consumer go through `parent::fill()` which stamps via `Node::fill`; `forward_entry` was calling `$this->sink->fill()` directly and bypassed the stamping.
- **Settings save no longer clobbers the aggregator server list.** `aggregator_servers` was registered as a settings-form option but had no form input — so options.php's whitelist iteration passed `null` to `sanitize_aggregator_servers` on every save, returning `[]` and wiping the list (including config-file-defined defaults flowing through `ServerRegistry::get_all`). Match the legacy `newspack-event-aggregator` pattern: only `add_settings_field` (for display); REST CRUD owns the writes.
- **Settings save no longer creates dead WP option rows for values that already equal the config-file default.** A single `pre_update_option` filter short-circuits the write (and `delete_option`s any stale row) when the sanitized new value matches `Config::load_config_defaults()`. Keeps the options table clean and lets later changes to the config file actually take effect instead of being shadowed by a stale stored copy of the old default.
- **StreamMerger periodic tick was unwired.** `tick()` (which drives `maybe_send_heartbeat()` — the POST to the spoke's `/firehose/heartbeat` that refreshes its aggregator-slot TTL — plus `check_stale()` and `maybe_commit()`) had no scheduler attached. The docstring promised `register('TIMER', ...) or an explicit Timer node` but neither happened. So the spoke's slot TTL (30s) silently expired, the spoke's `check_sse_slot()` returned false, and the spoke closed the SSE connection. The hub saw it as `Connection closed by server` (clean cURL CURLE_OK + HTTP 200), bumped backoff, reconnected, repeat. StreamMerger now registers with `_router`'s TIMER event in a new `start_periodic_tick()` method (Router-hitchhike pattern, same as `Timer::set_timer()` with no args), called from the aggregator topology.
- **StreamMerger was hitting the legacy REST namespace.** Two hard-coded URLs in `class-stream-merger.php` (`/firehose/stream` and `/firehose/heartbeat`) still pointed at `/wp-json/event-logger/v1/...` — the legacy `newspack-event-logger-plugins` namespace — but the new plugin mounts everything under `/wp-json/newspack-nodes/v1/...`. Every SSE connect attempt and heartbeat got HTTP 404 / `rest_no_route` back, regardless of TLS / auth posture. Stale carryover from the port; fixed both URLs.
- **Aggregator topology now actually registers remotes.** `StreamMerger::add_remote()` is registry-driven (single-arg shape reads url/auth/enabled from `ServerRegistry`), but nothing in production called it — so the live worker always reported `remotes: []` and the dashboard showed every configured spoke as `disconnected / pending` regardless of what the operator put in the Servers UI. The aggregator topology now iterates `ServerRegistry::get_enabled()` after building the StreamMerger and registers each entry. Topology re-runs on supervisor restart (already triggered on every server add/update/delete via `ServersController::request_supervisor_restart`), so the in-memory remote set tracks the operator-visible list automatically.

### Changed

- **`aggregator_allow_http` renamed to `aggregator_require_https` (polarity flipped).** Both aggregator flags now share fail-closed-to-safe semantics: default `true` keeps the safe behavior on (verify SSL, require HTTPS); operators have to set the flag to literal `false` to lift the guard. The topology read becomes uniform — `true === ( $config[X] ?? true )` for both — and the StreamMerger setter is now `set_require_https( bool $require )`. Plugin not yet deployed beyond two local dev environments; no migration shim. The legacy `newspack-event-logger-plugins` keeps its `aggregator_allow_http` name unchanged.

### Fixed

- **`aggregator_verify_ssl` and `aggregator_allow_http` now actually reach the SSL handshake.** The application config-file keys were invisible to `ServersController::test_connection` (Settings UI "Test" button) and `topologies/aggregator.php` (the live StreamMerger). Both called `PerformanceControllerBase::load_config()`, which only sees substrate config + the `newspack_nodes/config` filter — it never reads `newspack-event-logger-nodes-config.php`. Switching both call sites to `\Newspack_Event_Logger_Nodes\Config::load_config('full')` (the application loader, which merges file defaults beneath WP options) closes the gap. The topology additionally calls `set_verify_ssl()` and `set_allow_http()` on the StreamMerger so the SSE pull cURL handle honors the same policy as the synchronous probes.

### Changed

- **`HookCategorizer::is_internal()` is the single source of truth for "is this our hook".** Two callers (`Core::__construct` instrumentation, `HookCategorizer::get_registered_hooks_by_category` for the registered-hooks endpoint) and the `PerfHooksAvailableController::get_available_hooks` picker now all share the same prefix check. Critically, the list now covers slash-style names (`newspack_nodes/spawn_worker`, `newspack_event_logger_nodes/log_readers`) in addition to underscore-style — slash names were leaking into the "Select Hooks to Log" modal because the inline checks only tested the underscore prefix.
- **`gated_by` accepts an array of config keys (any-of).** Single-string form still works for the common single-gate case. Spawning still requires strict `=== true` on at least one key. `firehose-workers` now uses `[ 'enable_workers', 'enable_jobs' ]` — the topology stays alive if either is set, and the topology PHP itself inspects the same flags to wire its graph one of three ways: both branches with a Tee fan-out + jobintake consumer (default), workers-only (firehose → RequestBuilder, no Tee, no jobs partition, no jobintake), or jobs-only (firehose + jobintake → JobRouter, no Tee, no RequestBuilder/requests/errors partitions).

## [0.1.1] - 2026-05-11

### Added

- `enable_aggregator` is now the single operator gate for remote-server activity — both the StreamMerger pull-side (via the `aggregator` topology's `gated_by` entry) and the `remote_manager` push-side (`SettingsSync::maybe_queue_static_sync` + `AutoTuner::persist` short-circuit when off). One switch. Strict polarity (`=== true`), default OFF — fresh installs are not hubs; operators opt in explicitly. Stored as a real PHP bool (admin sanitize callback returns `(bool) (int)` not `absint`). `Hub::is_active()` helper deleted — wasn't doing anything `enable_aggregator` couldn't do alone.
- ARCHITECTURE.md gap-fill: `Write Path: LogManager`, `InflightTracker`, `AutoTuner`, `Hub-Side Helpers: ServerRegistry / RemoteManager / Discovery`, `Configuration`, expanded `REST + React` with the SSE slot pool + heartbeat protocol, and a `CLI: wp nodes reqgrep` section.

### Changed

- **Auto-tune fanout is now a Node.** `AutoTuneHandlers` (six WP-action listeners — hub @ priority 5 + standalone @ priority 10 across three event types) is replaced with an `AutoTuner` Node that receives FlameBuilder's tuning decisions as `TM_STRUCT` messages routed via `TO=auto-tuner`. Both sides ran in the same request-workers process; the action plumbing was intra-process IPC dressed up as hooks. Now it's a straight `fill()` dispatch by `Message::KEY`. Net hook count dropped: 63 → 51 call sites; 23 add_action listeners → 14.
- **Topology fleet declared in config.** The four topologies this plugin contributes (`firehose-workers`, `request-workers`, `job-workers`, `aggregator`) used to be hardcoded in the `newspack_nodes/topologies` filter; they're now declared as data under a `topologies` key in `newspack-event-logger-nodes-config.php`. Per-site overrides can add, remove, or retarget entries without patching plugin code. Each entry supports `topology` (relative → plugin-rooted, absolute → as-is), `num_partitions` (omit to inherit substrate), `stale_timeout`, and an optional `gated_by` WP-option name for operator toggles.
- **`job-workers` stale_timeout bumped to 600s** to match the legacy `newspack-event-jobs` reader config. Job handlers (evtemplate runs, importers) can block for minutes; the 60s default would force-respawn workers mid-handler.
- **`RemoteManager::health_check()` calls `HealthCheckExtensions::process_discovery()` directly** instead of routing through the in-process `newspack_event_logger_nodes/health_check_discovery` action. The action is still fired alongside for external plugin listeners (pyrobase). `HealthCheckExtensions::init()` is gone.
- **Plugin entry uses Composer classmap autoloader.** `composer.json` declares `"autoload": { "classmap": [ "includes/" ] }`; the deferred-loader closure swaps a 50-line `require_once` chain for `vendor/autoload.php`. Classes load on first reference; admin requests no longer pay for 28 REST controller files, REST requests no longer pay for admin code.

### Fixed

- Two test fixture helpers (`PerfUrlsControllerTest::write_request_to_partition`, `RequestLogControllerTest::write_request`) emitted `mkdir(): File exists` warnings on the second fixture write into the same per-partition segment dir. Guarded with `is_dir()`.

### Removed

- `ReqgrepCommandTest::test_inflight_lru_evicts_stale_rid_as_incomplete` — had been `markTestSkipped`'d since it was written (timing-driven, `usleep( 5000 )` + `with_timed_rotation( 0.001 )`). The wiring it exercised is a 10-line closure; breakage surfaces immediately as missing `[incomplete]` markers in `wp nodes reqgrep --incomplete`. Maintenance cost of a permanently-skipped test outweighs its insurance value.

## [0.1.0] - 2026-05-10

### Added

- Initial public release. Application layer of the new event-logger, built on `newspack-nodes`.
  Replaces the legacy 10-plugin `newspack-event-logger-plugins` monorepo with a single application
  plugin plus its substrate.
  - `LogManager` — per-request firehose writer (PIPE_BUF-bound, URL-secret redaction, refuses root).
  - `RequestBuilder` — assembles complete requests from firehose lines.
  - `FlameBuilder` — flame-graph aggregator with auto-tuning (noisy / significant event detection).
  - `JobIntake` + `JobRouter` + `JobWorker` — small-job firehose pipeline + large-job intake pipeline (>4KB).
  - `StreamMerger` — pulls remote firehoses via SSE (cURL multi); hub-side `k:"job"` → `k:"remote_job"` rewrite.
  - `Stats_Store` + `StatsAggregator` — 9-namespace memcache schema with salt-rotation flush. Fail-soft on memcache failure; SSE slots fail-closed.
  - `ServerRegistry` + `RemoteManager` + `SettingsSync` — hub-side configuration of remote spokes; settings fan-out + auto-tune fan-out gated by the `enable_aggregator` operator toggle.
  - `Memcached_Cache` + `FakeMemcached` (test) both implement `Cache_Interface`.
  - React dashboards: event aggregator status, raw logs viewer, worker status, performance dashboards, gyroscope (request timeline), request log, performance settings.
  - REST controllers under `/newspack-nodes/v1/` (logger, perf-config, perf-hooks, perf-settings, servers, settings, …).
  - Topologies for `firehose-workers`, `request-workers`, `job-workers`, `aggregator`.
  - `wp nodes reqgrep` — application-aware firehose filter (recent / follow / pattern modes).
  - Admin UI: Performance Workers, Auto-Tune thresholds, Enable Workers / Enable Jobs / Enable Aggregator toggles, Remote Servers table (test / toggle / remove / add).
