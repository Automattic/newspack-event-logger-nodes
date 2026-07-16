<?php
/**
 * VerbHarness: test fixture for service-CommandInterpreter (interpreter) verbs.
 *
 * Every M2 interpreter test uses this to fire a TM_COMMAND envelope through the
 * substrate's normal dispatch path (interpreter → base interpreter → Router → HTTP_In) and
 * pull the verb's return value back out as a decoded PHP value. Tests
 * therefore exercise the same plumbing the live REST controller does —
 * no special "for tests" shortcut — but assert on the verb's logical
 * result rather than parsing the on-wire Message themselves.
 *
 * Lifecycle: each fire() call builds a fresh request-scope graph
 * (_router / _command_interpreter / _http) plus the supplied interpreter; the
 * accompanying reset() (called from tearDown) clears Core's registry so
 * the next test's graph construction doesn't collide on names.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Rest\HTTP_In_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;

class VerbHarness {
	/**
	 * Build a request-scope graph and fire a verb against the supplied interpreter.
	 * Returns the verb's payload from the captured TM_RESPONSE.
	 *
	 * Per the command protocol, the response Message's VALUE is a live PHP
	 * array `['name'=>'<verb>','payload'=><result>]` — it rides through
	 * packed()/unpacked() as a nested object, so there is nothing to
	 * json_decode. The verb's `payload` is returned directly: a structure
	 * for verbs that return arrays/scalars, or the error-message string for
	 * a TM_COMMAND|TM_ERROR response (since `interpret()` puts the thrown
	 * message into `payload`).
	 *
	 * @param Command_Interpreter_Node $interpreter interpreter under test (already constructed).
	 * @param string             $name Name to register the interpreter under (e.g. 'workers').
	 * @param string             $verb Verb to invoke (e.g. 'list').
	 * @param array<int,string>|string $args Argument tokens (the `arguments` argv). A
	 *                                 convenience string is whitespace-split into tokens;
	 *                                 pass an explicit array when a token contains spaces
	 *                                 (e.g. a JSON blob). Empty for nullary verbs.
	 * @param string             $key  Optional KEY field for the inbound message.
	 * @return mixed The verb's payload (structure for success verbs; error-message string for TM_ERROR).
	 */
	public static function fire( Command_Interpreter_Node $interpreter, string $name, string $verb, array|string $args = [], string $key = '' ): mixed {
		$arg_tokens = \is_array( $args ) ? \array_values( $args ) : ( '' === $args ? [] : \preg_split( '/\s+/', $args ) );
		$router = new Router_Node(); $router->name( Node_Names::ROUTER );
		$base   = new Command_Interpreter_Node(); $base->name( Node_Names::COMMAND_INTERPRETER ); $base->sink( $router );
		$interpreter->name( $name );
		$interpreter->sink( $base );

		// status_header seam is unused — tests assert on the verb's return
		// value, not which HTTP status code HTTP_In emitted. The closure
		// is a no-op so HTTP_In's fill() path runs without trying to call
		// the real \status_header() (which isn't defined in tests).
		$http_in = new HTTP_In_Node( static fn ( int $c ) => null );
		$http_in->name( Node_Names::HTTP );

		$message = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_COMMAND;
		$message[ Message::FROM ]  = Node_Names::HTTP;
		$message[ Message::TO ]    = '';  // empty TO triggers dispatch in Command_Interpreter_Node::fill
		$message[ Message::ID ]    = 'test-' . \bin2hex( \random_bytes( 4 ) );
		$message[ Message::KEY ]   = $key;
		// VALUE is the command struct as a live PHP array — never separately
		// json-encoded; only the envelope/wire (HTTP_In's packed Message) is JSON.
		$message[ Message::VALUE ] = [
			'name'      => $verb,
			'arguments' => $arg_tokens,
		];
		// Exercises verb LOGIC, not authorization. Mark the command as in-process
		// so the substrate's client-tier authorize gate (Message::LOCAL) passes.
		$message[ Message::LOCAL ] = true;

		\ob_start();
		$interpreter->fill( $message );
		$body = \ob_get_clean();

		if ( '' === $body ) {
			throw new \RuntimeException( "verb '{$verb}' on interpreter '{$name}' produced no response" );
		}
		// HTTP_In packs the whole response Message; unpacked() restores VALUE
		// as the live `['name'=>,'payload'=>]` array. The verb's payload is
		// returned directly — a structure for success verbs, or the
		// error-message string for a TM_COMMAND|TM_ERROR response.
		$reply   = Message::unpacked( $body );
		$command = $reply[ Message::VALUE ];
		if ( ! \is_array( $command ) || ! \array_key_exists( 'payload', $command ) ) {
			throw new \RuntimeException( 'response missing payload field' );
		}
		return $command['payload'];
	}

	/** Reset the request-scope graph between tests. */
	public static function reset(): void {
		Core::reset();
	}
}
