import { createClickTracker } from '../../assets/src/frontend/clicks';

const RESULT_URL = 'https://example.org/shipping/';

/**
 * Builds a page with a menu, a site header and a result list that all link to the same page.
 *
 * @return {HTMLElement} The region showing the results.
 */
function renderPage() {
	// A new root for every test, so the listeners of earlier trackers are gone with it.
	document.body.innerHTML = `<div id="page">
		<header><a id="logo" href="${ RESULT_URL }">Logo</a></header>
		<nav><a id="menu" href="${ RESULT_URL }">Shipping</a></nav>
		<main>
			<article>
				<header><h2><a id="result" href="${ RESULT_URL }#top">Shipping</a></h2></header>
			</article>
			<a id="unrelated" href="https://example.org/contact/">Contact</a>
			<div data-other><a id="other" href="${ RESULT_URL }">Shipping</a></div>
		</main></div>`;

	return document.getElementById( 'page' );
}

/**
 * Clicks a link and returns what was reported to the server.
 *
 * @param {string} id ID of the link.
 * @return {?Object} The reported click, null if nothing was sent.
 */
function click( id ) {
	navigator.sendBeacon.mockClear();
	document.getElementById( id ).click();

	if ( navigator.sendBeacon.mock.calls.length === 0 ) {
		return null;
	}

	return JSON.parse( navigator.sendBeacon.mock.calls[ 0 ][ 1 ].parts[ 0 ] );
}

describe( 'createClickTracker', () => {
	beforeEach( () => {
		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1',
			trackClicks: true,
		};

		navigator.sendBeacon = jest.fn();

		// jsdom's Blob cannot be read back, so one that keeps its parts stands in.
		global.Blob = class {
			constructor( parts ) {
				this.parts = parts;
			}
		};

		// jsdom reports "not implemented" for navigation; the clicks themselves are what is tested.
		document.addEventListener( 'click', ( event ) =>
			event.preventDefault()
		);
	} );

	it( 'reports a click on a result with the token of its search', () => {
		createClickTracker( renderPage() ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		expect( click( 'result' ) ).toEqual( {
			token: 'token-1',
			post_id: 7,
		} );
	} );

	it( 'ignores links that are not results', () => {
		createClickTracker( renderPage() ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		expect( click( 'unrelated' ) ).toBeNull();
	} );

	it( 'ignores the same link in the menu and in the site header', () => {
		createClickTracker( renderPage() ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		expect( click( 'menu' ) ).toBeNull();
		expect( click( 'logo' ) ).toBeNull();
	} );

	it( 'leaves a region that belongs to another tracker alone', () => {
		createClickTracker( renderPage(), '[data-other]' ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		expect( click( 'other' ) ).toBeNull();
	} );

	it( 'counts results of a search that itself sits in a header, a sidebar or a menu', () => {
		for ( const wrapper of [ 'header', 'aside', 'nav' ] ) {
			document.body.innerHTML = `<${ wrapper }><div id="live"><a id="hit" href="${ RESULT_URL }">Shipping</a></div></${ wrapper }>`;

			createClickTracker( document.getElementById( 'live' ) ).replace(
				'token-1',
				{ [ RESULT_URL ]: 7 }
			);

			expect( click( 'hit' ) ).toEqual( {
				token: 'token-1',
				post_id: 7,
			} );
		}
	} );

	it( 'counts a result opened in a new tab with the middle button', () => {
		createClickTracker( renderPage() ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		navigator.sendBeacon.mockClear();
		document
			.getElementById( 'result' )
			.dispatchEvent(
				new MouseEvent( 'auxclick', { bubbles: true, button: 1 } )
			);

		expect( navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'forgets the results of the previous search', () => {
		const tracker = createClickTracker( renderPage() );

		tracker.replace( 'token-1', { [ RESULT_URL ]: 7 } );
		tracker.replace( 'token-2', { 'https://example.org/returns/': 8 } );

		expect( click( 'result' ) ).toBeNull();
	} );

	it( 'keeps earlier pages when more results are added', () => {
		const tracker = createClickTracker( renderPage() );

		tracker.replace( 'token-1', { 'https://example.org/returns/': 8 } );
		tracker.add( 'token-1', { [ RESULT_URL ]: 7 } );

		expect( click( 'result' ) ).toEqual( {
			token: 'token-1',
			post_id: 7,
		} );
	} );

	it( 'reports nothing for a search that was not logged', () => {
		createClickTracker( renderPage() ).replace( '', { [ RESULT_URL ]: 7 } );

		expect( click( 'result' ) ).toBeNull();
	} );

	it( 'reports nothing when click tracking is off', () => {
		window.prstsearchSettings.trackClicks = false;

		createClickTracker( renderPage() ).replace( 'token-1', {
			[ RESULT_URL ]: 7,
		} );

		expect( click( 'result' ) ).toBeNull();
	} );
} );
