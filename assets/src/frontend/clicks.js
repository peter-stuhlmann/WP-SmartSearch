/**
 * Reports which search result was opened, if click tracking is enabled.
 */
import { routeUrl, settings } from './api';

/**
 * Parts of a page that link to results by coincidence: menus, sidebars, the
 * site's header and footer. A click there says nothing about the search.
 */
const NAVIGATION =
	'nav, aside, [role="navigation"], [role="complementary"], [role="banner"], [role="contentinfo"]';
const CONTENT = 'article, main, [role="main"]';

/**
 * Watches one region of the page for clicks on the results of one search.
 *
 * Each region has its own tracker, so results of an earlier search are
 * forgotten as soon as the region shows a new one.
 *
 * @param {HTMLElement} root      Region the results are shown in.
 * @param {string}      [ignored] Selector of parts inside the region that belong to another tracker.
 * @return {{replace: Function, add: Function}} Functions to set and extend the tracked results.
 */
export function createClickTracker( root, ignored = '' ) {
	/**
	 * The results of the current search, keyed by their URL.
	 *
	 * @type {Map<string, {token: string, postId: number}>}
	 */
	let results = new Map();

	const add = ( token, posts ) => {
		if ( ! token ) {
			return;
		}

		Object.entries( posts ).forEach( ( [ url, postId ] ) =>
			results.set( normalize( url ), { token, postId } )
		);
	};

	const replace = ( token, posts ) => {
		results = new Map();
		add( token, posts );
	};

	if ( settings().trackClicks ) {
		// Capturing, so the click is seen even if a theme script stops it from bubbling.
		root.addEventListener(
			'click',
			( event ) => {
				const link =
					event.target instanceof Element
						? event.target.closest( 'a[href]' )
						: null;

				if ( ! link || isBesideTheResults( link, ignored ) ) {
					return;
				}

				const result = results.get( normalize( link.href ) );

				if ( result ) {
					report( result );
				}
			},
			true
		);
	}

	return { replace, add };
}

/**
 * Tells whether a link sits in a part of the page that does not show results.
 *
 * @param {Element} link    The clicked link.
 * @param {string}  ignored Selector of parts that belong to another tracker.
 * @return {boolean} Whether the click should be ignored.
 */
function isBesideTheResults( link, ignored ) {
	if (
		link.closest( NAVIGATION ) ||
		( ignored && link.closest( ignored ) )
	) {
		return true;
	}

	// Themes wrap result titles in a header inside the article. Only the site's own header and footer are meant.
	const landmark = link.closest( 'header, footer' );

	return landmark !== null && landmark.closest( CONTENT ) === null;
}

/**
 * Sends the click to the server.
 *
 * @param {{token: string, postId: number}} result The opened result.
 */
function report( result ) {
	// sendBeacon survives the navigation that follows the click.
	navigator.sendBeacon(
		routeUrl( '/click' ),
		new Blob(
			[
				JSON.stringify( {
					token: result.token,
					post_id: result.postId,
				} ),
			],
			{ type: 'application/json' }
		)
	);
}

/**
 * Makes URLs comparable: no fragment, no trailing slash.
 *
 * @param {string} url A URL.
 * @return {string} The normalised URL.
 */
function normalize( url ) {
	return url.split( '#' )[ 0 ].replace( /\/$/, '' );
}
