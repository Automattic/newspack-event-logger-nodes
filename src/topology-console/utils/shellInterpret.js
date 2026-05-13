/**
 * Frontend Shell layer — parses a user-typed line into either a local
 * builtin action or a typed POST descriptor.
 *
 * Mirrors the verb dispatch in newspack-nodes/includes/class-shell.php
 * so the GUI accepts the same vocabulary as `wp nodes cli`:
 *
 *   Local builtins (no POST):
 *     clear                         — wipe transcript
 *     debug_level [0|1|2]           — toggle local Dumper verbosity
 *     help                          — list available verbs
 *
 *   Typed-message verbs (POST with type=…):
 *     ping [<path>]                 — TM_PING
 *     tell|tell_node <path> <bytes> — TM_INFO
 *     send|send_node <path> <bytes> — TM_BYTESTREAM
 *     send_eof <path>               — TM_EOF
 *     request|request_node <path> <args> — TM_REQUEST
 *     cmd|command|command_node <path> <verb> [<args>] — TM_COMMAND at <path>
 *
 *   Default (POST type=command):
 *     <verb> [<args>]               — TM_COMMAND at _command_interpreter
 *
 * Returns one of:
 *   { kind: 'local', name: 'clear' }
 *   { kind: 'local', name: 'debug_level', level: 0|1|2 }
 *   { kind: 'local', name: 'help' }
 *   { kind: 'post', body: { type, name?, arguments?, to? } }
 *   { kind: 'error', text: 'usage: …' }
 *   null                            — empty input
 */

const LOCAL_BUILTINS = new Set( [ 'clear', 'debug_level', 'help' ] );

const HELP_TEXT = [
	'Built-ins (run locally):',
	'  clear                          — wipe the transcript',
	'  debug_level [0|1|2]            — local Dumper verbosity',
	'  help                           — show this list',
	'',
	'Typed verbs (sent to the worker):',
	'  ping [<path>]                  — round-trip a TM_PING',
	'  tell <path> <bytes>            — TM_INFO',
	'  send <path> <bytes>            — TM_BYTESTREAM',
	'  send_eof <path>                — TM_EOF',
	'  request <path> <args>          — TM_REQUEST',
	'  cmd <path> <verb> [<args>]     — TM_COMMAND at <path>',
	'  <verb> [<args>]                — TM_COMMAND at _command_interpreter',
].join( '\n' );

function splitFirst( s ) {
	const idx = s.search( /\s/ );
	if ( idx === -1 ) {
		return [ s, '' ];
	}
	return [ s.slice( 0, idx ), s.slice( idx + 1 ).trim() ];
}

export function shellInterpret( line ) {
	const trimmed = ( line || '' ).trim();
	if ( ! trimmed ) {
		return null;
	}
	const parts = splitFirst( trimmed );
	const verb = parts[ 0 ];
	const rest = parts[ 1 ];

	if ( verb === 'clear' ) {
		return { kind: 'local', name: 'clear' };
	}
	if ( verb === 'help' ) {
		return { kind: 'local', name: 'help', text: HELP_TEXT };
	}
	if ( verb === 'debug_level' ) {
		const level = rest === '' ? null : parseInt( rest, 10 );
		if (
			level !== null &&
			( Number.isNaN( level ) || level < 0 || level > 2 )
		) {
			return { kind: 'error', text: 'usage: debug_level [0|1|2]' };
		}
		return { kind: 'local', name: 'debug_level', level };
	}

	if ( verb === 'ping' ) {
		// `ping [<path>]` — empty path = ping the worker's _command_interpreter.
		return {
			kind: 'post',
			body: {
				type: 'ping',
				to: rest, // empty string handled server-side
			},
		};
	}
	if ( verb === 'tell' || verb === 'tell_node' ) {
		const [ to, body ] = splitFirst( rest );
		if ( ! to ) {
			return { kind: 'error', text: 'usage: tell <path> <bytes>' };
		}
		return {
			kind: 'post',
			body: { type: 'info', to, arguments: body },
		};
	}
	if ( verb === 'send' || verb === 'send_node' ) {
		const [ to, body ] = splitFirst( rest );
		if ( ! to ) {
			return { kind: 'error', text: 'usage: send <path> <bytes>' };
		}
		return {
			kind: 'post',
			body: { type: 'bytestream', to, arguments: body },
		};
	}
	if ( verb === 'send_eof' ) {
		const to = rest;
		if ( ! to ) {
			return { kind: 'error', text: 'usage: send_eof <path>' };
		}
		return { kind: 'post', body: { type: 'eof', to } };
	}
	if ( verb === 'request' || verb === 'request_node' ) {
		const [ to, body ] = splitFirst( rest );
		if ( ! to ) {
			return { kind: 'error', text: 'usage: request <path> <args>' };
		}
		return {
			kind: 'post',
			body: { type: 'request', to, arguments: body },
		};
	}
	if ( verb === 'cmd' || verb === 'command' || verb === 'command_node' ) {
		const [ to, after ] = splitFirst( rest );
		const [ name, args ] = splitFirst( after );
		if ( ! to || ! name ) {
			return { kind: 'error', text: 'usage: cmd <path> <verb> [<args>]' };
		}
		return {
			kind: 'post',
			body: { type: 'command', to, name, arguments: args },
		};
	}

	return {
		kind: 'post',
		body: { type: 'command', name: verb, arguments: rest },
	};
}

export { LOCAL_BUILTINS, HELP_TEXT };
