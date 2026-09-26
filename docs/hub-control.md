# Hub control

The substrate's [hub and spoke](https://github.com/Automattic/newspack-nodes/blob/main/docs/hub-and-spoke.md) chapter covers the connection itself: how a Remote_Source pulls one spoke's firehose over HTTPS, the Vault that holds each spoke's credentials, the hub user, and the reply gate. This chapter covers what the Event Logger runs over that connection.

Every site writes its own firehose. One site, the hub, copies every other site's firehose into its own; the others are spokes. The live hub pulls from 24 of them, and no spoke knows about any other. The hub is the HTTPS client in both directions, firehose in and signed commands out, so a spoke opens no connection of its own. Two things ride the outbound direction, the logger's settings and a discovery probe, and one thing rides inbound that the hub must not treat as its own: a spoke's job entries.

![Flow diagram of the hub's control plane: an option change reaching every spoke as a signed set command, the discovery collector's get and its TO=FROM reply, and the rewrite node relabeling inbound job entries, with a table of where each handler registration runs](img/d07c.png)

## Settings out

The [`hub-control`](../topologies/hub-control.tsl) topology is single-instance, whatever the data partition count, and it includes the substrate's [`settings-sync`](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/settings-sync.tsl) topology before adding the logger's pieces:

```tsl
var num_partitions = 1;

include settings-sync

make_node Discovery_Collector discovery-collector 300

cmd settings-sync:config add_setting newspack_event_logger_nodes_rules performance newspack_event_logger_nodes_rules
cmd settings-sync:config add_setting newspack_event_logger_nodes_log_memory performance newspack_event_logger_nodes_log_memory
cmd settings-sync:config add_setting newspack_event_logger_nodes_flush_every_line performance newspack_event_logger_nodes_flush_every_line
secure
```

A change to any `newspack_` option on the hub appends its name to a settings log; [Settings_Sync](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-settings-sync-node.php) tails it, reads the current value, and mints one `set` command per spoke. Every 300 seconds it re-pushes every registered option, so an offline or newly connected spoke converges. The substrate's own map sends the partition count and the six log-geometry settings, `segment_size`, `min_segments`, `num_segments`, `max_segments`, `min_lifetime` and `lifetime`, to the spoke's settings interpreter; the three `add_setting` lines above add the rules, log memory and flush-every-line options for the spoke's performance interpreter. The ruleset ships inlined whole: a rule on the hub may point at a hook table the hub holds, and the resolver expands every pointer rule's hooks before the push, so a spoke never receives a rule pointing at a table it does not hold. No Tee fans this out: a signature verifies only at the spoke it is minted for, so each minter signs once per spoke.

## Discovery

[Discovery_Collector](../includes/class-discovery-collector-node.php) runs on the same 300-second cadence, minting one `get` command per spoke, addressed to that spoke's [discovery interpreter](API.md#discovery--spoke-side-hook-and-event-roster). The reply is addressed TO=FROM, so it lands in the collector, which merges each spoke's registered hooks and custom event names into two staging options on the hub, sanitized and capped at 10,000 each. The rule editor's hook picker reads them; nothing here writes a rule.

## Jobs from a spoke

Every site's job worker runs the job entries in its own firehose, and the hub must not run a spoke's as its own. [Remote_Job_Rewrite](../includes/class-remote-job-rewrite-node.php), between the sources and the hub's firehose Topic, changes each entry's kind from `job` to `remote_job` and passes everything else through. The [job worker](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-job-worker-node.php) picks its handler map by that kind alone, local jobs from one WordPress filter and remote jobs from another. The spoke cannot label its own entries, because its own worker reads the same line.

| Handler registered under | Where it runs |
|---|---|
| [`newspack_nodes/job_handlers`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md#filters) only | On every site, for the entries in its own firehose; the hub ignores the copies aggregated from spokes |
| [`newspack_nodes/remote_job_handlers`](https://github.com/Automattic/newspack-nodes/blob/main/docs/API.md#filters) only | On the hub alone, once per aggregated entry; the spoke writes the entry and does nothing with it |
| Both | On every site for its own entries, and again on the hub for each entry aggregated from a spoke |

## The live hub

The stock [`aggregator`](../topologies/aggregator.tsl) topology ships a `firehose` `Vault_Group` over Vault group `spoke`, building one [Remote_Source](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-remote-source-node.php) per spoke and following the Vault on reload; the live hub runs those same groups, reached through ELN's own [`hub`](../topologies/hub.tsl) composition, at the scale below.

![Annotated listing of the live hub's topology: the four-line top-level file naming one include, aggregator's firehose Vault_Group leg and hub-control's settings Vault_Group leg, the seven nodes one Remote_Source child costs, and the 168 + 48 + 29 = 245 name count](img/d07b.png)

The top-level topology, `aggregator-hub.tsl`, is four lines: an on-demand idle of zero, so the worker never idles out; one partition, so one instance holds every spoke; `include hub`, ELN's own composition of `aggregator` and `hub-control`; and `secure`, which freezes the graph so that no later command builds or removes a node — though a Vault_Group still builds and retracts members on the fleet's Vault RELOAD after `secure`, since membership was declared before securing and a reload replays that declaration rather than issuing a fresh `make_node`. The active topology NAME stays `aggregator-hub` rather than `hub` itself: every Remote_Source's offsetlog and dead-letter path is scoped by `<topology>`, so renaming the active file would start every spoke on a fresh cursor.

`aggregator`'s `firehose` `Vault_Group` builds one Remote_Source per Vault entry in group `spoke`: its offsetlog and dead-letter paths carry the topology name to keep two hubs off one offsetlog, and `assume_clean_shutdown` and `set_multi_writer` are recorded once on the group and replayed to every child, present now or built later, ending in a connect into the one rewrite node. `assume_clean_shutdown` belongs beside every source: each record is durable in the hub's log before the worker stops, so a cooperative stop commits past the in-flight record instead of replaying it, and without it every worker recycle delivers one record twice. `set_multi_writer` belongs on every firehose source: every request process on the spoke appends to the log, so a request can still be writing a segment after the next opens, and the verb asks the spoke's reader to hold the old segment for a grace period. Without it a straggling request's last line, the one that says it completed, stays on the spoke, and the request never finalizes on the hub.

`hub-control`'s `settings` `Vault_Group` builds one [HTTP_Out](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/includes/class-http-out-node.php) per Vault entry in group `spoke`, each reading that entry's own credentials; the two [`allow_replies_to`](https://github.com/Automattic/newspack-nodes/blob/main/docs/hub-and-spoke.md#the-reply-gate) declarations naming the settings node and the discovery node are recorded once on the group, not once per spoke, and replayed the same way to every child; and the group's own target is one shared Null, catching whatever a spoke leaves unaddressed. A spoke writes every field of its reply, TO included, and `allow_replies_to` is the list of TO paths the hub delivers; any other is dropped, and the list fails closed, so a missing declaration drops every spoke's acks silently rather than just one's. The [security model](security-model.md) argues the trade.

One Remote_Source child costs the hub seven names: the source, its config interpreter, its three hidden nodes, `:sse-in`, `:http-out` and `:null`, its offsetlog and its dead-letter partition. Twenty-four spokes make 168, plus 24 settings links and their 24 interpreters, plus 29 shared nodes — the router, the fleet, the rewrite node, the firehose Topic, the `firehose` and `settings` Vault_Group nodes themselves and their own `:config` interpreters among them: 245 names in one worker process. Adding or dropping a spoke is a Vault edit, one `make_node Vault_Group` line per leg kind rather than a line per spoke, so there is no per-spoke line to miss.

## Read more

- [architecture-guide.md](architecture-guide.md), the sections [Hub vs Spoke Topology](architecture-guide.md#hub-vs-spoke-topology), [Hub-Side Settings Sync, Discovery, and Vault](architecture-guide.md#hub-side-settings-sync-discovery-and-vault) and [Job ingress and routing](architecture-guide.md#job-ingress-and-routing)
- [topologies/aggregator.tsl](../topologies/aggregator.tsl) and [topologies/hub-control.tsl](../topologies/hub-control.tsl)
- [newspack-nodes/topologies/settings-sync.tsl](https://github.com/Automattic/newspack-nodes/blob/v2.56.0/topologies/settings-sync.tsl)
- [security-model.md](security-model.md), the review of the hub and spoke trust boundary
