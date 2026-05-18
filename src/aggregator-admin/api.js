/**
 * Aggregator-admin api wrappers.
 *
 * Thin promises around the 4 `servers` service-CI verbs that the admin
 * "Configured Servers" UI calls. Each wrapper takes a `CommandClient`
 * instance as its first arg so tests can pass a fake; production code in
 * `index.js` injects the shared `getCommandClient()` singleton.
 *
 * Response shape mirrors the legacy controller's REST responses verbatim —
 * see `Servers_CI::public_shape()` in `includes/app/class-servers-ci.php`.
 */

import unwrapCommandResponse from '../shared/utils/unwrapCommandResponse';

/**
 * Dispatch a verb against the `servers` CI and return the unwrapped payload.
 *
 * @param {Object} client CommandClient instance with a `send()` method.
 * @param {string} verb   `servers` verb name (add / update / delete / test).
 * @param {Object} args   Verb arguments.
 * @return {Promise<*>} Parsed verb payload.
 */
async function dispatchServers( client, verb, args ) {
	const message = await client.send( { to: 'servers', verb, args } );
	return unwrapCommandResponse( message );
}

/**
 * Add a new server.
 *
 * @param {Object} client               CommandClient instance.
 * @param {Object} fields               Server config.
 * @param {string} fields.id            Server id (a-z, 0-9, _-).
 * @param {string} fields.url           HTTPS URL of the spoke.
 * @param {string} fields.auth_username Basic-auth username.
 * @param {string} fields.auth_password Basic-auth password (Application Password).
 * @return {Promise<{id: string}>} Newly-added server id.
 */
export function addServer( client, fields ) {
	return dispatchServers( client, 'add', {
		id: fields.id,
		url: fields.url,
		auth_username: fields.auth_username,
		auth_password: fields.auth_password,
		enabled: true,
	} );
}

/**
 * Partial-update an existing server.
 *
 * @param {Object} client  CommandClient instance.
 * @param {string} id      Server id.
 * @param {Object} partial Subset of {url, auth_username, auth_password, enabled, logs}.
 * @return {Promise<{id: string}>} Updated server id.
 */
export function updateServer( client, id, partial ) {
	return dispatchServers( client, 'update', { id, ...partial } );
}

/**
 * Remove a server.
 *
 * @param {Object} client CommandClient instance.
 * @param {string} id     Server id.
 * @return {Promise<{id: string}>} Removed server id.
 */
export function removeServer( client, id ) {
	return dispatchServers( client, 'delete', { id } );
}

/**
 * Probe a server's /discovery endpoint with its stored Basic Auth.
 *
 * @param {Object} client CommandClient instance.
 * @param {string} id     Server id.
 * @return {Promise<{id: string, status: string, response: Object}>} Probe result.
 */
export function testServer( client, id ) {
	return dispatchServers( client, 'test', { id } );
}
