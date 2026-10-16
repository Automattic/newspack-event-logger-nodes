<?php
/**
 * Overview Prefetch Tick
 *
 * Queues the overview-prefetch job once an interval, riding the Router's
 * tick, from the fleet's one copy of the line. Decision 37 says where the
 * job travels and which worker runs it.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Event_Logger_Nodes\App\Overview_Prefetch;
use Newspack_Nodes\Core;
use Newspack_Nodes\Job_Intake;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overview_Prefetch_Tick node — `make_node Overview_Prefetch_Tick <name> [interval_s]`.
 */
class Overview_Prefetch_Tick_Node extends Timer_Node {
	/** Seconds between jobs when the line names none. */
	public const INTERVAL_S = 120;

	/** Seconds between jobs. */
	protected int $interval_s = self::INTERVAL_S;

	/**
	 * Read the interval and ride the Router's tick, which throttles the
	 * hitchhike to it. A fresh process counts its first interval from now, so
	 * a respawn queues no second job inside the one its predecessor queued.
	 *
	 * @api Called by the substrate during make_node construction.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->parse_schema_args( $args );
		$this->set_timer( $this->cadence_ms( $this->interval_s ) );
		$this->last_fire_time = Core::$now;
		return $this->arguments;
	}

	/**
	 * Queue the job, where this copy owns it. A leg that did not go, the feed
	 * `Job_Intake` refused or a firehose no log rule governs, is said once a
	 * window, each leg's miss on its own.
	 *
	 * @api Driven by the substrate Timer (fire_cb).
	 */
	protected function fire(): void {
		// The line names no partition, so the source it asks about is empty.
		if ( ! Core::owns( '', Core::bound_partition() ) ) {
			return;
		}
		$parameters = [
			'queued_at' => (int) Core::$now,
			'interval'  => $this->interval_s,
		];
		$borrowed   = null === Log_Manager::started_instance();
		if ( $borrowed ) {
			Log_Manager::begin_job_context( Overview_Prefetch::HANDLER );
		}
		try {
			$log = Log_Manager::instance();
			if ( ! $log->message( 'job', [ 'm' => [ 'handler' => Overview_Prefetch::HANDLER, 'id' => '', 'parameters' => $parameters ] ] ) ) {
				$this->print_less_often( "WARNING: " . Overview_Prefetch::HANDLER . ' not logged to the firehose' );
			}
			if ( ! Job_Intake::feed( Overview_Prefetch::HANDLER, null, $parameters, null, null, 1 ) ) {
				$this->print_less_often( "WARNING: " . Overview_Prefetch::HANDLER . ' not fed to jobfeed.p0' );
			}
			$log->flush();
		} finally {
			if ( $borrowed ) {
				Log_Manager::end_job_context();
			}
		}
	}

	/**
	 * Palette entry and argument form.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'     => 'Control',
			'description'  => 'Queues the overview-prefetch job once an interval, to the firehose and jobfeed.p0, from the fleet\'s one copy of the line.',
			'arguments'    => [
				[ 'name' => 'interval_s', 'type' => 'int', 'default' => self::INTERVAL_S, 'description' => 'Seconds between jobs; each snapshot serves two of them.' ],
			],
			'commands'     => [],
			'accepts_fill' => false,
		];
	}
}
