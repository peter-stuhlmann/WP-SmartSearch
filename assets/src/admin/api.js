/**
 * Access to the plugin's REST routes from the admin area.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Calls a route of the plugin. Authentication is handled by apiFetch.
 *
 * @param {string} route   Route below the namespace, e.g. "/index/rebuild".
 * @param {Object} options Options for apiFetch, e.g. method and data.
 * @return {Promise<any>} The decoded response.
 */
export function request( route, options = {} ) {
	return apiFetch( {
		path: window.prstsearchAdmin.restPath + route,
		...options,
	} );
}

/**
 * Returns a readable message for a failed request.
 *
 * @param {unknown} error    What apiFetch rejected with.
 * @param {string}  fallback Message if the error carries none.
 * @return {string} The message.
 */
export function errorMessage( error, fallback ) {
	return error &&
		typeof error === 'object' &&
		typeof error.message === 'string'
		? error.message
		: fallback;
}
