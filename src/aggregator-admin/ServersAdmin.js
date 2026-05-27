/**
 * <ServersAdmin> — the thin React view over the Configured-Servers node graph.
 *
 * Full-React replacement for the old PHP-rendered `<table>` + jQuery IIFE. The
 * graph (useAggregatorAdminGraph) owns all data + the CRUD transport; this
 * component reads the published view model via `useNodeState('servers:view','view')`
 * and renders the same markup the PHP `configured_servers_callback` emitted — same
 * class names + ids (`wp-list-table`, `.event-aggregator-test/-toggle/-remove`,
 * `#new-server-id`, …) so it inherits WordPress's core admin styling unchanged.
 *
 * The single behaviour change vs the jQuery version: a successful add / toggle /
 * remove no longer `window.location.reload()`s — the hook re-`list()`s and the
 * table re-renders from the fresh model. Test status + the add-form validation
 * messages are local component state (per-row test status; the add-form status
 * line). Mirrors AggregatorStatus (thin view over its graph).
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useNodeState } from '@newspack-nodes/runtime';
import { useAggregatorAdminGraph } from './hooks/useAggregatorAdminGraph';

// The view model before the first list publishes one — drives the loading gate.
const EMPTY_MODEL = {
	servers: null,
	loading: true,
	error: null,
};

/**
 * Pull a human-readable message off a thrown CommandClient error.
 *
 * @param {Error} err Thrown error from a CRUD callback.
 * @return {string} Display message.
 */
function errorMessage( err ) {
	return (
		( err && err.message ) || __( 'Error', 'newspack-event-logger-nodes' )
	);
}

/**
 * A single server row — id / url / status + Test / Toggle / Remove actions. Owns
 * its own per-row test-status string (set on Test). Mirrors the PHP <tr> markup.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.server   Public server shape from the view model.
 * @param {Function} props.onToggle Toggle-enabled callback (id, nextEnabled).
 * @param {Function} props.onRemove Remove callback (id).
 * @param {Function} props.onTest   Test callback (id) → probe promise.
 * @return {import('react').ReactElement} The rendered row.
 */
function ServerRow( { server, onToggle, onRemove, onTest } ) {
	const { id, url, enabled } = server;
	const [ testStatus, setTestStatus ] = useState( { text: '', color: '' } );
	const [ busy, setBusy ] = useState( false );

	const handleTest = async () => {
		setBusy( true );
		setTestStatus( {
			text: __( 'Testing…', 'newspack-event-logger-nodes' ),
			color: '',
		} );
		try {
			await onTest( id );
			setTestStatus( {
				text: __( 'Connected!', 'newspack-event-logger-nodes' ),
				color: 'green',
			} );
		} catch ( err ) {
			setTestStatus( {
				text: sprintf(
					// translators: %s: connection error message.
					__( 'Failed: %s', 'newspack-event-logger-nodes' ),
					errorMessage( err )
				),
				color: 'red',
			} );
		} finally {
			setBusy( false );
		}
	};

	const handleToggle = async () => {
		setBusy( true );
		try {
			await onToggle( id, ! enabled );
		} finally {
			setBusy( false );
		}
	};

	const handleRemove = async () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Are you sure you want to remove this server?',
				'newspack-event-logger-nodes'
			)
		);
		if ( ! confirmed ) {
			return;
		}
		setBusy( true );
		try {
			await onRemove( id );
		} finally {
			setBusy( false );
		}
	};

	return (
		<tr data-server-id={ id }>
			<td>
				<code>{ id }</code>
			</td>
			<td>{ url }</td>
			<td>
				{ enabled ? (
					<span
						className="dashicons dashicons-yes-alt"
						style={ { color: 'green' } }
						title={ __( 'Enabled', 'newspack-event-logger-nodes' ) }
					/>
				) : (
					<span
						className="dashicons dashicons-no"
						style={ { color: 'gray' } }
						title={ __(
							'Disabled',
							'newspack-event-logger-nodes'
						) }
					/>
				) }
				<span
					className="test-status"
					style={ { color: testStatus.color } }
				>
					{ testStatus.text }
				</span>
			</td>
			<td>
				<button
					type="button"
					className="button button-small event-aggregator-test"
					data-server-id={ id }
					disabled={ busy }
					onClick={ handleTest }
				>
					{ __( 'Test', 'newspack-event-logger-nodes' ) }
				</button>{ ' ' }
				<button
					type="button"
					className="button button-small event-aggregator-toggle"
					data-server-id={ id }
					data-enabled={ enabled ? 1 : 0 }
					disabled={ busy }
					onClick={ handleToggle }
				>
					{ enabled
						? __( 'Disable', 'newspack-event-logger-nodes' )
						: __( 'Enable', 'newspack-event-logger-nodes' ) }
				</button>{ ' ' }
				<button
					type="button"
					className="button button-small button-link-delete event-aggregator-remove"
					data-server-id={ id }
					disabled={ busy }
					onClick={ handleRemove }
				>
					{ __( 'Remove', 'newspack-event-logger-nodes' ) }
				</button>
			</td>
		</tr>
	);
}

/**
 * The inline "Add New Server" form — id / url / username / password + submit.
 * Owns the field state + the validation/status line. Mirrors the PHP form-table.
 *
 * @param {Object}   props       Component props.
 * @param {Function} props.onAdd Add callback (fields) → add promise.
 * @return {import('react').ReactElement} The rendered form.
 */
function AddServerForm( { onAdd } ) {
	const [ id, setId ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ username, setUsername ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ status, setStatus ] = useState( { text: '', color: '' } );
	const [ busy, setBusy ] = useState( false );

	const handleAdd = async () => {
		const trimmedId = id.trim();
		if ( ! trimmedId ) {
			setStatus( {
				text: __(
					'Server ID is required',
					'newspack-event-logger-nodes'
				),
				color: 'red',
			} );
			return;
		}
		const trimmedUrl = url.trim();
		if ( ! trimmedUrl ) {
			setStatus( {
				text: __(
					'Server URL is required',
					'newspack-event-logger-nodes'
				),
				color: 'red',
			} );
			return;
		}
		if ( ! trimmedUrl.startsWith( 'https://' ) ) {
			setStatus( {
				text: __(
					'URL must start with https://',
					'newspack-event-logger-nodes'
				),
				color: 'red',
			} );
			return;
		}

		setBusy( true );
		setStatus( {
			text: __( 'Adding…', 'newspack-event-logger-nodes' ),
			color: '',
		} );
		try {
			await onAdd( {
				id: trimmedId,
				url: trimmedUrl,
				auth_username: username.trim(),
				auth_password: password,
			} );
			// Success: the hook re-lists and the table re-renders (no reload).
			setStatus( {
				text: __( 'Server added!', 'newspack-event-logger-nodes' ),
				color: 'green',
			} );
			setId( '' );
			setUrl( '' );
			setUsername( '' );
			setPassword( '' );
		} catch ( err ) {
			setStatus( {
				text: sprintf(
					// translators: %s: error message.
					__( 'Error: %s', 'newspack-event-logger-nodes' ),
					errorMessage( err )
				),
				color: 'red',
			} );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<h4>{ __( 'Add New Server', 'newspack-event-logger-nodes' ) }</h4>
			<table className="form-table" style={ { maxWidth: '600px' } }>
				<tbody>
					<tr>
						<th>
							<label htmlFor="new-server-id">
								{ __(
									'Server ID',
									'newspack-event-logger-nodes'
								) }
							</label>
						</th>
						<td>
							<input
								type="text"
								id="new-server-id"
								className="regular-text"
								placeholder="prod-web-01"
								pattern="[a-zA-Z0-9_-]+"
								value={ id }
								onChange={ ( e ) => setId( e.target.value ) }
							/>
							<p className="description">
								{ __(
									'Unique identifier (alphanumeric, hyphen, underscore).',
									'newspack-event-logger-nodes'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th>
							<label htmlFor="new-server-url">
								{ __(
									'Server URL',
									'newspack-event-logger-nodes'
								) }
							</label>
						</th>
						<td>
							<input
								type="url"
								id="new-server-url"
								className="regular-text"
								placeholder="https://example.com"
								value={ url }
								onChange={ ( e ) => setUrl( e.target.value ) }
							/>
							<p className="description">
								{ __(
									'HTTPS URL of the WordPress site.',
									'newspack-event-logger-nodes'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th>
							<label htmlFor="new-server-username">
								{ __(
									'Username',
									'newspack-event-logger-nodes'
								) }
							</label>
						</th>
						<td>
							<input
								type="text"
								id="new-server-username"
								className="regular-text"
								value={ username }
								onChange={ ( e ) =>
									setUsername( e.target.value )
								}
							/>
							<p className="description">
								{ __(
									'WordPress username on the remote site.',
									'newspack-event-logger-nodes'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th>
							<label htmlFor="new-server-password">
								{ __(
									'Application Password',
									'newspack-event-logger-nodes'
								) }
							</label>
						</th>
						<td>
							<input
								type="password"
								id="new-server-password"
								className="regular-text"
								value={ password }
								onChange={ ( e ) =>
									setPassword( e.target.value )
								}
							/>
							<p className="description">
								{ __(
									'WordPress Application Password (Users → Profile → Application Passwords).',
									'newspack-event-logger-nodes'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th />
						<td>
							<button
								type="button"
								className="button button-primary"
								id="event-aggregator-add-server"
								disabled={ busy }
								onClick={ handleAdd }
							>
								{ __(
									'Add Server',
									'newspack-event-logger-nodes'
								) }
							</button>{ ' ' }
							<span
								id="add-server-status"
								style={ { color: status.color } }
							>
								{ status.text }
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</>
	);
}

/**
 * Configured-Servers admin app. Reads the view model the graph publishes and
 * renders the server table + add form. Mirrors the PHP configured_servers_callback
 * markup so the styled result matches.
 *
 * @return {import('react').ReactElement} The rendered admin app.
 */
export default function ServersAdmin() {
	// Mount the node graph; it owns the list-on-mount, the CRUD transport, and the
	// re-list-after-mutation that replaces the old window.location.reload().
	const { addServer, updateServer, removeServer, testServer } =
		useAggregatorAdminGraph();

	// The single read surface: the render model the graph publishes.
	const model = useNodeState( 'servers:view', 'view' ) ?? EMPTY_MODEL;
	const { servers, error } = model;

	const onToggle = ( id, nextEnabled ) =>
		updateServer( id, { enabled: nextEnabled } );

	return (
		<div className="event-aggregator-servers-admin">
			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }
			<table
				className="wp-list-table widefat fixed striped"
				style={ { maxWidth: '800px' } }
			>
				<thead>
					<tr>
						<th>{ __( 'ID', 'newspack-event-logger-nodes' ) }</th>
						<th>{ __( 'URL', 'newspack-event-logger-nodes' ) }</th>
						<th>
							{ __( 'Status', 'newspack-event-logger-nodes' ) }
						</th>
						<th>
							{ __( 'Actions', 'newspack-event-logger-nodes' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ servers && servers.length > 0 ? (
						servers.map( ( server ) => (
							<ServerRow
								key={ server.id }
								server={ server }
								onToggle={ onToggle }
								onRemove={ removeServer }
								onTest={ testServer }
							/>
						) )
					) : (
						<tr>
							<td colSpan="4">
								{ __(
									'No servers configured.',
									'newspack-event-logger-nodes'
								) }
							</td>
						</tr>
					) }
				</tbody>
			</table>

			<AddServerForm onAdd={ addServer } />
		</div>
	);
}
