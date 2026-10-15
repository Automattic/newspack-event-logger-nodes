<?php
/**
 * The harness node that records what a test's nodes asked of their Tables.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

/**
 * The sink every CI under test and every recorded builder sends through: it
 * records each request, then forwards everything on to `_command_interpreter`.
 */
final class Stats_Ask_Recorder_Node extends Node {

	/** @var list<array{to: string, value: string|array<array-key,mixed>}> Every request that passed, in order: whom it asked, and what. */
	public array $asked = [];

	/**
	 * @param array<int,mixed> $message Any message on its way into the graph.
	 */
	public function fill( array $message ): void {
		if ( 0 !== ( Core::num_int( $message[ Message::TYPE ] ) & Message::TM_REQUEST ) ) {
			$value         = $message[ Message::VALUE ];
			$this->asked[] = [
				'to'    => Core::as_string( $message[ Message::TO ], '' ),
				'value' => \is_array( $value ) || \is_string( $value ) ? $value : '',
			];
		}
		parent::fill( $message );
	}
}
