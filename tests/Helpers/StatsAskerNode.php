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

	/** The reads `$refuse` refuses: a Table's and a Ledger's. */
	private const READS = [ 'MGET', 'SMEMBERS', 'SUM', 'TOP', 'MEMBERS' ];

	/** @var list<array{to: string, value: string|array<array-key,mixed>}> Every request that passed, in order: whom it asked, and what. */
	public array $asked = [];

	/**
	 * While set, a pattern: a read TO a node it matches is answered here
	 * with the TM_ERROR its store sends when the read fails, and never
	 * reaches the store.
	 */
	public string $refuse = '';

	/** @var list<string> Every node a refused read was TO, in order. */
	public array $refused = [];

	/**
	 * @param array<int,mixed> $message Any message on its way into the graph.
	 */
	public function fill( array $message ): void {
		if ( 0 !== ( Core::num_int( $message[ Message::TYPE ] ) & Message::TM_REQUEST ) ) {
			$value         = $message[ Message::VALUE ];
			$to            = Core::as_string( $message[ Message::TO ], '' );
			$this->asked[] = [
				'to'    => $to,
				'value' => \is_array( $value ) || \is_string( $value ) ? $value : '',
			];
			$verb = \is_array( $value ) ? (string) \array_key_first( $value ) : (string) \strtok( Core::as_string( $value, '' ), " \n" );
			if ( '' !== $this->refuse && \in_array( $verb, self::READS, true ) && 1 === \preg_match( $this->refuse, $to ) ) {
				$this->refused[]         = $to;
				$error                   = Message::new_message();
				$error[ Message::TYPE ]  = Message::TM_ERROR;
				$error[ Message::FROM ]  = $to;
				$error[ Message::TO ]    = $message[ Message::FROM ];
				$error[ Message::ID ]    = $message[ Message::ID ];
				$error[ Message::VALUE ] = "{$verb}: backend read failed\n";
				parent::fill( $error );
				return;
			}
		}
		parent::fill( $message );
	}
}
