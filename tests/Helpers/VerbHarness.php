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
 * Lifecycle: fire() mounts the request-scope graph once per test through
 * `Bootstrap::mount_request_graph()`, as `/command` does, then names the
 * supplied interpreter and a fresh `_http` onto it; the accompanying reset()
 * (called from tearDown) clears Core's registry for the next test.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Rest\HTTP_In_Node;
use Newspack_Nodes\Message;

class VerbHarness {

	/** The recorder's node name. */
	private const ASK_RECORDER = 'stats-ask-recorder';

	/**
	 * The user a REST request carries: `HTTP_In_Node` admits no caller who is
	 * not logged in, so a verb reached through the request graph always has one
	 * current, and the substrate's `dispatch()` asks that user's capabilities.
	 */
	public const REQUEST_USER = 5129;
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
		$GLOBALS['_current_user_id'] ??= self::REQUEST_USER;
		$arg_tokens = \is_array( $args ) ? \array_values( $args ) : ( '' === $args ? [] : \preg_split( '/\s+/', $args ) );
		// The request graph mounts this plugin's CIs; the one under test stands in for its namesake.
		$mounted = Core::node( $name );
		if ( null !== $mounted && $mounted !== $interpreter ) {
			$mounted->remove_node();
		}
		$interpreter->name( $name );
		$interpreter->sink( self::ask_recorder() );
		Core::node( Node_Names::HTTP )?->remove_node();

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

		return \Newspack_Nodes\Tests\Helpers\VerbHarness::payload( $interpreter, $message );
	}

	/**
	 * This request's `_command_interpreter`, sinking into `_router`: the one
	 * `Bootstrap::mount_request_graph()` builds, mounted on the first ask so a
	 * second ask in one test cannot mount this plugin's CIs twice.
	 */
	public static function request_graph(): Command_Interpreter_Node {
		$GLOBALS['_current_user_id'] ??= self::REQUEST_USER;
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		return $interpreter instanceof Command_Interpreter_Node ? $interpreter : Bootstrap::mount_request_graph();
	}

	/**
	 * The node a test's askers send through on their way into the request
	 * graph, recording every Table request that passes.
	 */
	public static function ask_recorder(): Stats_Ask_Recorder_Node {
		$recorder = Core::node( self::ASK_RECORDER );
		if ( $recorder instanceof Stats_Ask_Recorder_Node ) {
			return $recorder;
		}
		$recorder = new Stats_Ask_Recorder_Node();
		$recorder->name( self::ASK_RECORDER );
		$recorder->sink( self::request_graph() );
		return $recorder;
	}

	/**
	 * Reset the request-scope graph, keeping the tick: a test's replies and
	 * its seeds all date from the one `Core::$now` its setUp stamped, and a
	 * reset that re-read the wall would split them across a bucket boundary.
	 */
	public static function reset(): void {
		$tick = Core::$now;
		Core::reset();
		Core::$now = $tick;
	}
}
