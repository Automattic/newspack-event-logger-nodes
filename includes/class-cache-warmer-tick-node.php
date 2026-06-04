<?php
/**
 * Cache Warmer Tick Node
 *
 * Queues a `cache_warmer` JobWorker job every INTERVAL_SECONDS from inside a
 * long-lived worker, replacing the unreliable wp-cron trigger (which competes
 * with the supervisor and every other minute-cron for a slot). The tick
 * hitchhikes `_router`'s ~5s TIMER heartbeat — the worker's own drain loop, not
 * wp-cron — and debounces to the interval, so cadence is immune to cron
 * contention.
 *
 * Why enqueue rather than fire the loopback here: the warm render blocks for
 * seconds; the JobWorker isolates it (its own request_id, GC cycle, cache-flush
 * cadence, stale-timeout headroom) and keeps this worker's drain loop moving.
 * The job handler (handle_job) reuses the drop-in's single-flight loopback.
 *
 * Add to a topology with a single line — the timer self-starts in arguments():
 *   make_node Cache_Warmer_Tick cache-warmer:tick
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Cache_Warmer_Tick_Node extends Timer_Node {

	/** Tick cadence AND handler max-age: a warm job older than one full cycle is dropped (the next tick warms). */
	public const INTERVAL_SECONDS = 60;

	/** Job handler name — shared by the enqueue (fire) and the registration so they can't drift. */
	public const JOB_HANDLER = 'cache_warmer';

	/** Unix timestamp of the last enqueue (0 = never). */
	protected int $last_enqueue = 0;

	/** Static guard so init() is idempotent across the worker-runtime bootstrap. */
	private static bool $registered = false;

	/**
	 * Register the `cache_warmer` job handler on the standard JobWorker filter
	 * (plugin load). Named init() not register() — Node::register() is the
	 * instance-level event-subscription API and can't be overridden static.
	 */
	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		if ( \function_exists( 'add_filter' ) ) {
			\add_filter( 'newspack_nodes/job_handlers', [ self::class, 'register_handler' ] );
		}
	}

	/**
	 * Add the `cache_warmer` handler to the JobWorker's local-handler map.
	 *
	 * @param mixed $handlers Existing handlers (filter boundary — coerced to array).
	 * @return array<string, mixed>
	 */
	public static function register_handler( $handlers ): array {
		// Filter-boundary value; handler maps are string-keyed by design.
		/** @var array<string, mixed> $handlers */
		$handlers = \is_array( $handlers ) ? $handlers : [];
		$handlers[ self::JOB_HANDLER ] = [ self::class, 'handle_job' ];
		return $handlers;
	}

	/**
	 * Self-start the periodic tick when the node is named (make_node lifecycle),
	 * so a topology needs only the bare `make_node` line — no start verb.
	 *
	 * @param string|null $name
	 * @return string
	 */
	public function name( ?string $name = null ): string {
		// Getter passthrough: forwarding null as an explicit arg would trip the
		// base's "non-empty name required" guard, so only delegate the setter.
		if ( 0 === \func_num_args() ) {
			return parent::name();
		}
		$result = parent::name( $name );
		return $result;
	}

	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->arguments = $args;
		if ( '' === $args ) {
			$this->set_timer();
		} elseif ( preg_match( '/^[0-9]+$/', $args ) ) {
			$this->set_timer( (int) $args );
		} else {
			throw new \InvalidArgumentException( 'Bad arguments for Cache_Warmer_Tick' );
		}
		return $this->arguments;
	}

	/**
	 * fire (Timer_Node override): enqueue a `cache_warmer` job once per interval.
	 * Cheap and non-blocking — the blocking warm render happens later in the
	 * JobWorker via handle_job(). Debounced so the ~5s Router heartbeat only
	 * enqueues every INTERVAL_SECONDS.
	 */
	public function fire(): void {
		$now = \time();
		if ( $now - $this->last_enqueue < self::INTERVAL_SECONDS ) {
			return;
		}
		$this->last_enqueue = $now;

		$message = Message::new_message();
		$message[ Message::TYPE  ] = Message::TM_STRUCT;
		$message[ Message::FROM  ] = $this->name;
		$message[ Message::TO    ] = $this->target;
		$message[ Message::VALUE ] = [
			'type'       => 'job',
			'handler'    => self::JOB_HANDLER,
			'parameters' => [ 'queued_at' => $now ],
		];
		parent::fill( $message );
	}

	/**
	 * JobWorker handler: fire the single-flight warm loopback, unless the job has
	 * been queued for >= one full interval (a newer tick is already coming, so
	 * skip the stale one). There is no uniform stale-drop in JobWorker — each
	 * handler enforces its own age (cf. RemoteManager::STALE_THRESHOLD = 600s).
	 *
	 * @param array<string, mixed> $parameters Job parameters (`queued_at`).
	 */
	public static function handle_job( array $parameters ): void {
		/** @var int|float|string|bool|null $raw_queued_at */
		$raw_queued_at = $parameters['queued_at'] ?? 0;
		$queued_at     = (int) $raw_queued_at;
		if ( $queued_at > 0 && ( \time() - $queued_at ) >= self::INTERVAL_SECONDS ) {
			Core::print_less_often( 'CacheWarmerTick: dropping stale warm job (age >= ' . self::INTERVAL_SECONDS . 's)' );
			return;
		}
		if ( ! \class_exists( '\\Newspack_Cache_Warmer\\Cache_Warmer' ) ) {
			Core::print_less_often( 'CacheWarmerTick: drop-in not installed; cannot warm' );
			return;
		}
		\Newspack_Cache_Warmer\Cache_Warmer::run_tick();
	}

	public static function node_schema(): array {
		return [
			'category'    => 'Control',
			'description' => 'Queues a cache_warmer JobWorker job every 60s by default; self-starts in arguments() (optional numeric arg = interval seconds).',
			'arguments'   => [],
			'commands'    => [],
		];
	}
}
