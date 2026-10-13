/**
 * Access to the public REST routes of the search.
 */

/**
 * Settings printed by the server.
 *
 * @return {{restUrl: string, trackClicks: boolean, clicks: ?{token: string, posts: Object<string, number>}, strings: Object<string, string>}} Settings.
 */
export function settings() {
	return (
		window.prstsearchSettings ?? {
			restUrl: '',
			trackClicks: false,
			clicks: null,
			strings: {},
		}
	);
}

/**
 * Builds the URL of a route. Works with pretty and plain permalinks.
 *
 * @param {string}                 route  Route below the namespace, e.g. "/search".
 * @param {Object<string, string>} params Query parameters.
 * @return {URL} The request URL.
 */
export function routeUrl( route, params = {} ) {
	const url = new URL( settings().restUrl, window.location.origin );

	if ( url.searchParams.has( 'rest_route' ) ) {
		url.searchParams.set(
			'rest_route',
			url.searchParams.get( 'rest_route' ) + route
		);
	} else {
		url.pathname = url.pathname.replace( /\/$/, '' ) + route;
	}

	// Lets multilingual sites return results in the language of the page.
	if ( document.documentElement.lang ) {
		url.searchParams.set( 'lang', document.documentElement.lang );
	}

	Object.entries( params ).forEach( ( [ name, value ] ) =>
		url.searchParams.set( name, value )
	);

	return url;
}

/**
 * Requests JSON from a route.
 *
 * @param {string}                 route    Route below the namespace.
 * @param {Object<string, string>} params   Query parameters.
 * @param {AbortSignal}            [signal] Lets the caller cancel the request.
 * @return {Promise<any>} The decoded response.
 */
export async function getJson( route, params, signal ) {
	const response = await fetch( routeUrl( route, params ), {
		headers: { Accept: 'application/json' },
		signal,
	} );

	if ( ! response.ok ) {
		throw new Error( `Request failed with status ${ response.status }` );
	}

	return response.json();
}
