<?php
/**
 * Workers_CI: command-dispatch for worker-lifecycle verbs.
 *
 * Replaces legacy class-workers-controller.php + the heartbeat method on
 * class-firehose-controller.php with a CommandInterpreter that mounts at
 * priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   list      — enumerate workers + their live cursor positions.
 *   restart   — request restart for one or more worker types.
 *   heartbeat — refresh an SSE slot for the current user.
 *
 * Dependencies are injected via the constructor so tests can stub Cli
 * and Cache without touching the substrate's request-scope graph; this
 * mirrors the dependency-injection pattern other M2 CIs will adopt.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\CommandInterpreter;

\defined( 'ABSPATH' ) || exit;

class Workers_CI extends CommandInterpreter {

	/**
	 * Build a Workers_CI bound to the supplied Cli + Cache.
	 *
	 * @param object      $cli   Anything exposing `ls_workers()`, `live_position(?$cache, $type, $partition)`,
	 *                            and `restart_workers(array $workers, array $filter, int $partition)`.
	 *                            Production passes \Newspack_Nodes\Cli; tests pass anon classes that
	 *                            duck-type the same surface.
	 * @param object|null $cache Anything exposing `touch_sse_slot($user_id, $ip_hash, $slot, $ttl, $partition)`.
	 *                            Production passes \Newspack_Event_Logger_Nodes\Memcached_Cache; tests
	 *                            pass FakeMemcached or an anon stub. Null disables the heartbeat verb.
	 */
	public function __construct( object $cli, ?object $cache = null ) {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors RequestBuilder /
		// FlameBuilder, which extend Node and also skip the parent call.
		$this->commands( $this->verb_table( $cli, $cache ) );
	}

	private function verb_table( object $cli, ?object $cache ): array {
		return [
			'list' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cli, $cache ): string {
				$workers = $cli->ls_workers();
				foreach ( $workers as &$w ) {
					$w['position'] = $cli->live_position( $cache, $w['type'], $w['partition'] );
				}
				unset( $w );
				return (string) \wp_json_encode( $workers );
			},
			'restart' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cli ): string {
				$decoded   = '' === $args ? [] : ( \json_decode( $args, true ) ?? [] );
				$types     = (array) ( $decoded['types']     ?? [] );
				$partition = (int)   ( $decoded['partition'] ?? -1 );
				$filter    = [];
				foreach ( $types as $t ) {
					$filter[ (string) $t ] = true;
				}
				$workers   = $cli->ls_workers();
				$restarted = $cli->restart_workers( $workers, $filter, $partition );
				return (string) \wp_json_encode( [ 'restarted' => $restarted ] );
			},
			'heartbeat' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				if ( null === $cache ) {
					throw new \RuntimeException( 'cache not configured' );
				}
				$decoded = '' === $args ? [] : ( \json_decode( $args, true ) ?? [] );
				$slot    = (int) ( $decoded['slot'] ?? -1 );
				if ( $slot < 0 ) {
					throw new \RuntimeException( 'slot required' );
				}
				$ttl       = (int) ( $decoded['ttl']       ?? 10 );
				$partition = (int) ( $decoded['partition'] ?? -1 );
				$user_id   = \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
				// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
				$ip_hash   = \substr( \md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 8 );
				$success   = (bool) $cache->touch_sse_slot( $user_id, $ip_hash, $slot, $ttl, $partition );
				return (string) \wp_json_encode( [ 'success' => $success, 'slot' => $slot ] );
			},
		];
	}
}
