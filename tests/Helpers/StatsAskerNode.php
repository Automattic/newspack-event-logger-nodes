<?php
/**
 * The harness nodes that ask stats Tables and record what was asked.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Table_Client;

/**
 * Asks the harness Tables, the way the flame builder asks its own: a request
 * leaves through the sink and the reply comes back TO this node's name.
 */
final class Stats_Asker_Node extends Node {

	/** The client every harness store of one test shares. */
	public Table_Client $client;

	public function __construct() {
		parent::__construct();
		$this->client = new Table_Client( $this );
	}

	/**
	 * A Table's reply; anything else reaching the asker is a harness fault.
	 *
	 * @param array<int,mixed> $message The reply.
	 */
	public function fill( array $message ): void {
		if ( ! $this->client->accepts( $message ) ) {
			throw new \LogicException( 'stats-asker received a message no ask awaited' );
		}
	}
}

/**
 * The sink every harness asker and every CI under test sends through: it
 * records each request, then forwards everything on to `_command_interpreter`.
 */
final class Stats_Ask_Recorder_Node extends Node {

	/** @var list<array{to: string, value: string|array<array-key,mixed>}> Every request that passed, in order: whom it asked, and what. */
	public array $asked = [];

	/**
	 * While set, an `MGET` asking a key that carries this (matched against
	 * `:{key}`) is answered here with the TM_ERROR its Table sends when the
	 * read fails, and never reaches the Table: a batch that went unanswered.
	 */
	public string $refuse = '';

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
			if ( $this->refuses( $value ) ) {
				$error                   = Message::new_message();
				$error[ Message::TYPE ]  = Message::TM_ERROR;
				$error[ Message::FROM ]  = $message[ Message::TO ];
				$error[ Message::TO ]    = $message[ Message::FROM ];
				$error[ Message::ID ]    = $message[ Message::ID ];
				$error[ Message::VALUE ] = "MGET: backend read failed\n";
				parent::fill( $error );
				return;
			}
		}
		parent::fill( $message );
	}

	/**
	 * Whether a request is an `MGET` asking a key `$refuse` names.
	 *
	 * @param mixed $value The request's VALUE.
	 */
	private function refuses( mixed $value ): bool {
		$words = '' !== $this->refuse && \is_string( $value ) ? ( \preg_split( '/\s+/', \trim( $value ), -1, \PREG_SPLIT_NO_EMPTY ) ?: [] ) : [];
		if ( 'MGET' !== \array_shift( $words ) ) {
			return false;
		}
		foreach ( $words as $key ) {
			if ( \str_contains( ':' . $key, $this->refuse ) ) {
				return true;
			}
		}
		return false;
	}
}
