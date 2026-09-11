# The Event Logger: per-request performance logging on Newspack Nodes

The Event Logger records what a logged request does: its method and redacted URL, its environment, every hook and outbound HTTP call its rule binds as a timed span, and the terminal that closes it, with any fatal error. A rule per URL decides whether the request is logged at all. Inside the request one writer, `Log_Manager`, appends the lines to the firehose, an append-only log. It holds each entry to 3,840 bytes so the whole line stays under the 4,096-byte PIPE_BUF, and every append lands whole with no lock, however many requests share the partition. In the workers, `Request_Builder_Node` folds the lines into a record per request, and `Flame_Builder_Node` turns each record into a flame tree and folds it into five-minute statistics. Four dashboards read the results, and a fifth, Settings, edits the rules. A hub site pulls every spoke's firehose over HTTPS and pushes settings back, so one operator reads many sites in one place.

The four parts of the ten-part series that cover the Event Logger are their own posts, linked below, each with its own diagrams; Parts 1 to 5 and 9 cover the runtime underneath, Newspack Nodes, and are indexed from its post, which defines every term used here.

| Part | Title | What it covers |
|---|---|---|
| 6 | [The Event Logger](06-the-event-logger.md) | The firehose line, the rule, request assembly, flames and statistics, the durable mirror, jobs on the firehose, the five dashboards |
| 7 | [Hub and spoke](07-hub-and-spoke.md) | Pulling a spoke's firehose, the Vault and the hub user, the reply gate, settings sync and discovery, jobs from a spoke, the live hub |
| 8 | [Dashboards and the browser runtime](08-dashboards-and-the-browser-runtime.md) | The node graph in the browser, the command POST and the record stream, the slot pool, the hub tabs and the five dashboards, the shared build |
| 10 | [What we asked SecOps to review](10-what-we-asked-secops-to-review.md) | The review request: the hub and spoke trust boundary, shared-memcache keys and the inbound FROM, what the logger captures and what crosses to the hub, the operator's terminal, the Vault's cryptography, the JavaScript surface |

Read Part 6 first. The code is `newspack-event-logger-nodes`, and its `docs/architecture-guide.md` holds the detail Parts 6 and 7 point at.
