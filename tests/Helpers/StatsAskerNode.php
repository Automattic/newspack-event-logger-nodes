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
	 * While set, a pattern: an `MGET` or `SMEMBERS` asking a key it matches is
	 * answered here with the TM_ERROR its Table sends when the read fails, and
	 * never reaches the Table: a batch that went unanswered.
	 */
	public string $refuse = '';

	/** @var list<string> Every key the pattern matched in a refused request, in order. */
	public array $refused = [];

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
			$verb = $this->refuses( $value );
			if ( null !== $verb ) {
				$error                   = Message::new_message();
				$error[ Message::TYPE ]  = Message::TM_ERROR;
				$error[ Message::FROM ]  = $message[ Message::TO ];
				$error[ Message::TO ]    = $message[ Message::FROM ];
				$error[ Message::ID ]    = $message[ Message::ID ];
				$error[ Message::VALUE ] = "{$verb}: backend read failed\n";
				parent::fill( $error );
				return;
			}
		}
		parent::fill( $message );
	}

	/**
	 * The read verb of a request asking a key `$refuse` matches, or null;
	 * each key it matches joins `$refused`.
	 *
	 * @param mixed $value The request's VALUE.
	 */
	private function refuses( mixed $value ): ?string {
		$words = '' !== $this->refuse && \is_string( $value ) ? ( \preg_split( '/\s+/', \trim( $value ), -1, \PREG_SPLIT_NO_EMPTY ) ?: [] ) : [];
		$verb  = \array_shift( $words );
		if ( 'SMEMBERS' === $verb ) {
			// Its limit comes before the keys.
			\array_shift( $words );
		} elseif ( 'MGET' !== $verb ) {
			return null;
		}
		$matched = \preg_grep( $this->refuse, $words ) ?: [];
		if ( [] === $matched ) {
			return null;
		}
		\array_push( $this->refused, ...\array_values( $matched ) );
		return $verb;
	}
}
