# Documentation

The Event Logger is an application on the newspack-nodes runtime. Its vocabulary, node, message, topology, partition and the rest, is the substrate's; read the substrate's map first if any of those words is new.

## Understand it

Three chapters, in reading order.

- [The Event Logger](the-event-logger.md): read when you want to know what one request writes, how the workers assemble it, and where the flames and statistics come from.
- [Hub control](hub-control.md): read when you run a hub, or need to know what a hub pushes at a spoke and what it does with a spoke's jobs.
- [Dashboards](dashboards.md): read when you want to know what each of the five dashboards reads, or which bundle a page is.

## Reference

- [architecture-guide.md](architecture-guide.md): the write path, the per-URL ruleset, every topology, the application nodes, the memcache schema, hub and spoke, configuration, hooks, REST and CLI.
- [architecture-decisions.md](architecture-decisions.md): the decisions the design rests on, each with what it forbids.
- [security-model.md](security-model.md): what the logger captures, what crosses to the hub, the remote-job rewrite and the tradeoffs the logger chooses; the substrate's security-model.md carries the boundaries it enforces.
- [API.md](API.md): the service-CI verbs, the command endpoint, SSE, MCP, the WP-CLI verbs, the PHP API sibling plugins log through, and the hooks this plugin fires and consumes.

## The substrate

- [Documentation map](https://github.com/Automattic/newspack-nodes/blob/main/docs/README.md), with the glossary.
- [Getting started](https://github.com/Automattic/newspack-nodes/blob/main/docs/getting-started.md): from zero to a running pipeline.
- [Hub and spoke](https://github.com/Automattic/newspack-nodes/blob/main/docs/hub-and-spoke.md): the connection the hub control chapter here runs over.

Every diagram is an HTML sheet under `docs/img/` beside the PNG it renders to; `docs/img/render.sh` re-renders them all through headless Chrome.
