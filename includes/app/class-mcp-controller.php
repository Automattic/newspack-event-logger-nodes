<?php
/**
 * MCP_Controller: an MCP server over the verbs the dashboards already drive.
 *
 * The originating ask was an "Ask AI" button that points you at the problem and,
 * where the plugins allow, at the P2s, Linear issues and repos that explain it.
 * An in-plugin LLM call would have shipped faster and been the wrong shape: it
 * buys a dashboard that summarises itself to one model behind one proxy that
 * publishers cannot reach, and it cannot see a Linear issue at all. Exposing
 * the data lets an agent that ALREADY holds those context providers do the
 * correlation, which is the thing actually wanted.
 *
 * It adds no runtime surface. One tool per verb; arguments pass through
 * `Command_Args`; replies come back verbatim.
 *
 * Authorization has two halves and needs both. A scoped session says how much
 * of the surface is reachable, and the session's MINTING USER says whose
 * authority is being spent — so the scope is a CEILING, never a grant: a
 * manage-scoped session minted by someone who can do nothing still does
 * nothing. `check_permission()` installs both for the request.
 *
 * Nothing here assumes an agent will act on instructions found in a page. The
 * server is wired up by a deliberate act of the operator, and the tool
 * descriptions carry the measurement caveat because a model handed a
 * profiled/duration ratio without one will invent a cause for the difference.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Auth;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class MCP_Controller {

	public const REST_NAMESPACE = 'newspack-event-logger-nodes/v1';
	public const ROUTE          = '/mcp';

	/** The MCP revision this server speaks. */
	public const PROTOCOL_VERSION = '2025-06-18';

	/** JSON-RPC: the method does not exist. */
	private const METHOD_NOT_FOUND = -32601;

	/** JSON-RPC: the request was not valid JSON-RPC. */
	private const INVALID_REQUEST = -32600;

	/**
	 * Calls one session may make per RATE_LIMIT_WINDOW_S.
	 *
	 * MCP does not go through `/command`, so the substrate's per-user cap does
	 * not bound it — and the tools behind it are not cheap: `request_grep`
	 * walks every partition's index, `overview` and `ask` rebuild the
	 * leaderboard out of memcache. A looping agent, or a leaked bearer, would
	 * otherwise have an unmetered amplification path. Generous enough that a
	 * conversational agent never grazes it.
	 */
	public const RATE_LIMIT_BURST = 20;

	/** Rate-limit window, in seconds. */
	public const RATE_LIMIT_WINDOW_S = 10;

	/**
	 * Tool name → the CI node and verb behind it, with the role that verb
	 * declares. The role is repeated here so `tools/list` can offer a session
	 * only what its scope covers — an agent should not be shown a tool that
	 * will refuse it.
	 *
	 * @var array<string,array{node:string,verb:string,role:string,summary:string,args:array<string,string>}>
	 */
	private const TOOLS = [
		'performance_overview'     => [
			'node'    => 'performance',
			'verb'    => 'overview',
			'role'    => Capabilities::READ,
			'summary' => 'Site-wide performance: slowest URLs, most requested, totals and the time series.',
			'args'    => [ 'server' => 'Optional server name to scope to.', 'breakdown' => 'Comma-separated dimensions.' ],
		],
		'performance_urls'         => [
			'node'    => 'performance',
			'verb'    => 'urls',
			'role'    => Capabilities::READ,
			'summary' => 'The URL leaderboard, sortable and paginated.',
			'args'    => [ 'sort' => 'count|url|avg_ms|p95_ms|…', 'limit' => 'Rows to return.', 'search' => 'Substring filter.' ],
		],
		'performance_url_detail'   => [
			'node'    => 'performance',
			'verb'    => 'url_detail',
			'role'    => Capabilities::READ,
			'summary' => 'One URL: stats, aggregate flame data and its recent requests.',
			'args'    => [ 'hash' => 'The 12-char URL hash (required).' ],
		],
		'performance_request_search' => [
			'node'    => 'performance',
			'verb'    => 'request_search',
			'role'    => Capabilities::READ,
			'summary' => 'Locate a request by id; returns {rid, partition, url_hash}.',
			'args'    => [ 'rid' => 'The request id (required).' ],
		],
		'performance_request_detail' => [
			'node'    => 'performance',
			'verb'    => 'request_detail',
			'role'    => Capabilities::READ,
			'summary' => 'One request in full, with its flame data and computed findings.',
			'args'    => [ 'rid' => 'The request id (required).', 'partition' => 'Its partition.' ],
		],
		'performance_request_grep' => [
			'node'    => 'performance',
			'verb'    => 'request_grep',
			'role'    => Capabilities::READ,
			'summary' => 'Pattern-search recent traffic; returns matching requests, not lines.',
			'args'    => [ 'pattern' => 'Case-insensitive pattern (required).', 'limit' => 'Max matches.' ],
		],
		'performance_ask'          => [
			'node'    => 'performance',
			'verb'    => 'ask',
			'role'    => Capabilities::READ,
			'summary' => 'The brief for one thing: `url:<hash>`, `request:<rid>:<partition>`, `span:<name>`, `entry:<n>` or `category:<name>`. A span or an entry also needs its `request:` descriptor as a second argument.',
			'args'    => [ 'descriptor' => 'What to ask about (required).', 'context' => 'The containing descriptor, if any.' ],
		],
		'rules_list'               => [
			'node'    => 'rules',
			'verb'    => 'list',
			'role'    => Capabilities::READ,
			'summary' => 'The per-URL logging ruleset. The finest grain a rule has is a URL pattern.',
			'args'    => [],
		],
		'rules_upsert'             => [
			'node'    => 'rules',
			'verb'    => 'upsert',
			'role'    => Capabilities::TUNE,
			'summary' => 'Create or replace one rule. Enabling hooks costs overhead on every request the rule matches, so narrow it again once the question is answered.',
			'args'    => [ 'rule' => 'The rule as JSON (required).' ],
		],
		'rules_delete'             => [
			'node'    => 'rules',
			'verb'    => 'delete',
			'role'    => Capabilities::TUNE,
			'summary' => 'Delete one rule by id.',
			'args'    => [ 'id' => 'The rule id (required).' ],
		],
	];

	/**
	 * Gate: a Bearer `<handle>.<key>` naming a live session. On success this
	 * BECOMES that session's minting user and installs its scope as the
	 * request's ceiling, which is what makes the scope subtractive.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return true|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $req ) {
		// Network-global fleet: a subsite must not reach the main site's.
		$gate = Bootstrap::fleet_gate();
		if ( null !== $gate ) {
			return $gate;
		}
		$header = Core::as_string( $req->get_header( 'authorization' ) ?? '' );
		if ( ! \preg_match( '/^Bearer\s+([0-9a-f]{32})\.([0-9a-f]{64})$/i', \trim( $header ), $m ) ) {
			return new \WP_Error( 'mcp_unauthorized', 'A Bearer <handle>.<key> session credential is required.', [ 'status' => 401 ] );
		}
		$record = Command_Auth::load_session_record( $m[1] );
		if ( null === $record || ! \hash_equals( $record['key'], $m[2] ) ) {
			return new \WP_Error( 'mcp_unauthorized', 'That session is unknown or expired.', [ 'status' => 401 ] );
		}
		if ( \function_exists( 'wp_set_current_user' ) ) {
			// Whose authority is being spent. The scope only ever narrows it.
			\wp_set_current_user( $record['user'] );
		}
		Capabilities::$session_scope = $record['scope'];
		return self::check_rate_limit( $m[1] );
	}

	/**
	 * Per-session rolling-window rate limit, keyed by handle. Checked AFTER the
	 * credential, so an unauthenticated flood cannot poison the transient
	 * table — the ordering `Spawn_Controller` and `HTTP_In_Node` both use.
	 *
	 * @return true|\WP_Error
	 */
	private static function check_rate_limit( string $handle ) {
		if ( ! \function_exists( 'get_transient' ) || ! \function_exists( 'set_transient' ) ) {
			return true;
		}
		$bucket = (int) \floor( \time() / self::RATE_LIMIT_WINDOW_S );
		$key    = "newspack_eln_mcp_rl:{$handle}:{$bucket}";
		$count  = Core::as_int( \get_transient( $key ) );
		if ( $count >= self::RATE_LIMIT_BURST ) {
			return new \WP_Error( 'rate_limited', 'Too many MCP calls; please slow down.', [ 'status' => 429 ] );
		}
		\set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW_S * 2 );
		return true;
	}

	/**
	 * The JSON-RPC entry point.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return array<string,mixed>|null A JSON-RPC response, or null for a notification.
	 */
	public function dispatch( \WP_REST_Request $req ): ?array {
		$body = \json_decode( $req->get_body(), true );
		if ( ! \is_array( $body ) || ! isset( $body['method'] ) ) {
			return self::error( null, self::INVALID_REQUEST, 'Not a JSON-RPC request.' );
		}
		$id     = $body['id'] ?? null;
		$method = Core::as_string( $body['method'] );
		$params = \is_array( $body['params'] ?? null ) ? $body['params'] : [];

		switch ( $method ) {
			case 'initialize':
				return self::result( $id, [
					'protocolVersion' => self::PROTOCOL_VERSION,
					'capabilities'    => [ 'tools' => new \stdClass() ],
					'serverInfo'      => [
						'name'    => 'newspack-event-logger-nodes',
						'version' => \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' )
							? \NEWSPACK_EVENT_LOGGER_NODES_VERSION
							: '0.0.0',
					],
					'instructions'    => Findings::caveat(),
				] );
			case 'notifications/initialized':
				// JSON-RPC forbids answering a notification (it has no id).
				return null;
			case 'tools/list':
				return self::result( $id, [ 'tools' => self::visible_tools() ] );
			case 'tools/call':
				return self::call_tool( $id, $params );
		}
		return self::error( $id, self::METHOD_NOT_FOUND, "Unknown method: {$method}" );
	}

	/**
	 * Run one tool. A verb refusal comes back as an MCP tool error rather than
	 * a transport error — the call reached the server and was answered.
	 *
	 * @param mixed                $id     JSON-RPC id.
	 * @param array<array-key,mixed> $params The `tools/call` params.
	 * @return array<string,mixed>
	 */
	private static function call_tool( mixed $id, array $params ): array {
		$name = Core::as_string( $params['name'] ?? '' );
		$tool = self::TOOLS[ $name ] ?? null;
		if ( null === $tool || ! Capabilities::can( $tool['role'] ) ) {
			return self::error( $id, self::METHOD_NOT_FOUND, "Unknown tool: {$name}" );
		}

		// `/command` is not the only door; build the graph here too.
		Bootstrap::mount_request_graph();
		$node = Core::node( $tool['node'] );
		if ( ! $node instanceof Command_Interpreter_Node ) {
			return self::error( $id, self::INVALID_REQUEST, "The {$tool['node']} interpreter is not mounted." );
		}

		try {
			$reply = $node->dispatch( $tool['verb'], self::tokens( $params['arguments'] ?? [] ) );
		} catch ( \Throwable $e ) {
			return self::result( $id, [
				'isError' => true,
				'content' => [ [ 'type' => 'text', 'text' => \html_entity_decode( $e->getMessage(), \ENT_QUOTES ) ] ],
			] );
		}

		return self::result( $id, [
			'content' => [
				[
					'type' => 'text',
					'text' => \is_string( $reply ) ? $reply : Core::as_string( \wp_json_encode( $reply ) ),
				],
			],
		] );
	}

	/**
	 * A JSON-RPC success.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result payload.
	 * @return array<string,mixed>
	 */
	private static function result( mixed $id, mixed $result ): array {
		return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
	}

	/**
	 * MCP hands arguments as a named object; the command protocol takes a flat
	 * token array. `descriptor` / `context` and a bare `hash`/`rid`/`pattern`
	 * are POSITIONAL, and everything else becomes `--key=value` — which is what
	 * `Command_Args::parse()` reassembles on the other side.
	 *
	 * @param mixed $arguments The tool's arguments object.
	 * @return list<string>
	 */
	private static function tokens( mixed $arguments ): array {
		if ( ! \is_array( $arguments ) ) {
			return [];
		}
		$positional = [];
		$options    = [];
		foreach ( [ 'descriptor', 'hash', 'rid', 'pattern', 'rule', 'id', 'context' ] as $key ) {
			if ( isset( $arguments[ $key ] ) && \is_scalar( $arguments[ $key ] ) ) {
				$positional[] = Core::as_string( $arguments[ $key ] );
			}
		}
		foreach ( $arguments as $key => $value ) {
			if ( \in_array( $key, [ 'descriptor', 'hash', 'rid', 'pattern', 'rule', 'id', 'context' ], true ) ) {
				continue;
			}
			if ( \is_scalar( $value ) ) {
				$options[] = '--' . Core::as_string( $key ) . '=' . Core::as_string( $value );
			}
		}
		return [ ...$positional, ...$options ];
	}

	/**
	 * A JSON-RPC failure.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Human-readable reason.
	 * @return array<string,mixed>
	 */
	private static function error( mixed $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [ 'code' => $code, 'message' => $message ],
		];
	}

	/**
	 * The tools this session's scope actually covers. Offering one it will be
	 * refused for wastes a round trip and reads to an agent as a broken server.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function visible_tools(): array {
		$out = [];
		foreach ( self::TOOLS as $name => $tool ) {
			// BOTH halves: scope covers it AND the minting user holds it.
			if ( ! Capabilities::can( $tool['role'] ) ) {
				continue;
			}
			$properties = [];
			foreach ( $tool['args'] as $arg => $description ) {
				$properties[ $arg ] = [ 'type' => 'string', 'description' => $description ];
			}
			$out[] = [
				'name'        => $name,
				// The caveat rides on EVERY tool, not just the first read.
				'description' => $tool['summary'] . ' — ' . Findings::caveat(),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => empty( $properties ) ? new \stdClass() : $properties,
				],
			];
		}
		return $out;
	}

	/** @api Wired from the plugin bootstrap on rest_api_init. */
	public function register_routes(): void {
		\register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'dispatch' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}
}
