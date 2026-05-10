<?php
/**
 * Job Router
 *
 * Routes job entries from firehose.log and jobintake.log to registered handlers.
 *
 * Job sources (disambiguated via Message::KEY):
 * - firehose: Extracts entries with k='job' or k='remote_job' from request lifecycle
 * - jobintake: Direct job entries from JobIntake API
 *
 * SECURITY NOTES:
 * - Handler names validated against strict pattern
 * - Parameters size limited to prevent DoS
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Router class.
 */
class JobRouter extends Node {
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';
	public const MAX_JOB_SIZE         = 10485760;

	public const SOURCE_FIREHOSE  = 'firehose';
	public const SOURCE_JOBINTAKE = 'jobintake';

	public const KIND_JOB        = 'job';
	public const KIND_REMOTE_JOB = 'remote_job';

	/** @var array<string,callable> */
	private array $local_handlers = [];
	/** @var array<string,callable> */
	private array $remote_handlers = [];

	/**
	 * Register a local handler. Local handlers run on every node and receive
	 * lines tagged firehose:job (i.e., produced by LogManager and ingested back
	 * via the local firehose) plus all jobintake lines.
	 */
	public function set_local_handler( string $name, callable $cb ): void {
		$this->validate_handler_name( $name );
		$this->local_handlers[ $name ] = $cb;
	}

	/**
	 * Register a remote handler. Remote handlers run only on the hub and receive
	 * lines tagged firehose:remote_job (i.e., StreamMerger has rewritten k:"job"
	 * to k:"remote_job" while ingesting from a remote spoke).
	 */
	public function set_remote_handler( string $name, callable $cb ): void {
		$this->validate_handler_name( $name );
		$this->remote_handlers[ $name ] = $cb;
	}

	/**
	 * Backward-compatible alias for set_local_handler. Pre-multi-input callers
	 * registered everything as a single handler set; preserve them.
	 */
	public function register_handler( string $name, callable $cb ): void {
		$this->set_local_handler( $name, $cb );
	}

	public function has_handler( string $name ): bool {
		return isset( $this->local_handlers[ $name ] ) || isset( $this->remote_handlers[ $name ] );
	}

	public function has_local_handler( string $name ): bool {
		return isset( $this->local_handlers[ $name ] );
	}

	public function has_remote_handler( string $name ): bool {
		return isset( $this->remote_handlers[ $name ] );
	}

	private function validate_handler_name( string $name ): void {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( "invalid handler name: $name" );
		}
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		if ( ! \is_array( $entry ) ) {
			return;
		}
		// Defense-in-depth: drop entries whose on-wire size exceeds the per-job
		// cap. Partition's MAX_LARGE_LINE_SIZE catches this when reaching disk,
		// but tests construct Messages directly without going through Partition
		// — keep the explicit gate so producers can't bypass it. Size matches
		// the actual wire form (`Message::packed` is JSON).
		$encoded = \wp_json_encode( $entry );
		if ( false !== $encoded && \strlen( $encoded ) > self::MAX_JOB_SIZE ) {
			Core::print_less_often( 'JobRouter: oversized entry, skipping' );
			return;
		}
		$kind = $entry['k'] ?? '';
		if ( $kind !== self::KIND_JOB && $kind !== self::KIND_REMOTE_JOB ) {
			return;
		}

		// Resolve source via KEY tag set by upstream Tail/Consumer. Format
		// "{source}:{kind}". Missing/malformed KEY falls through to JSON-only routing.
		[ $source, $key_kind ] = $this->parse_key( (string) ( $message[ Message::KEY ] ?? '' ) );

		// Sanity: when KEY carries a kind, prefer it over the JSON k. They should
		// match in production; if they diverge the upstream-stamped value wins
		// (it's the trusted source-of-truth tag).
		if ( '' !== $key_kind ) {
			$kind = $key_kind;
		}

		// jobintake is always local (k:"remote_job" should never appear there;
		// if it does, drop into local — never escalate to remote dispatch).
		$is_remote = ( $source === self::SOURCE_FIREHOSE && $kind === self::KIND_REMOTE_JOB );

		$handler_name = $entry['handler'] ?? '';
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler_name ) ) {
			Core::print_less_often( "JobRouter: invalid handler name: $handler_name" );
			return;
		}

		$handlers = $is_remote ? $this->remote_handlers : $this->local_handlers;
		if ( ! isset( $handlers[ $handler_name ] ) ) {
			$bucket = $is_remote ? 'remote' : 'local';
			Core::print_less_often( "JobRouter: unknown $bucket handler: $handler_name" );
			return;
		}

		try {
			( $handlers[ $handler_name ] )( $entry['payload'] ?? null );
		} catch ( \Throwable $e ) {
			Core::print_less_often( "JobRouter: handler $handler_name threw: " . $e->getMessage() );
		}
	}

	/**
	 * Parse a "{source}:{kind}" KEY tag. Returns ['', ''] if KEY does not match
	 * (so callers can fall back to JSON-derived routing).
	 *
	 * @return array{0:string,1:string}
	 */
	private function parse_key( string $key ): array {
		if ( '' === $key ) {
			return [ '', '' ];
		}
		$parts = \explode( ':', $key, 2 );
		if ( \count( $parts ) !== 2 ) {
			return [ '', '' ];
		}
		[ $source, $kind ] = $parts;
		if ( $source !== self::SOURCE_FIREHOSE && $source !== self::SOURCE_JOBINTAKE ) {
			return [ '', '' ];
		}
		return [ $source, $kind ];
	}
}
