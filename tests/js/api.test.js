import { routeUrl } from '../../assets/src/frontend/api';

describe( 'routeUrl', () => {
	afterEach( () => {
		delete window.prstsearchSettings;
		document.documentElement.lang = '';
	} );

	it( 'appends the route to a pretty permalink REST URL', () => {
		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1/',
		};

		const url = routeUrl( '/search', { q: 'shipping costs' } );

		expect( url.pathname ).toBe( '/wp-json/prstsearch/v1/search' );
		expect( url.searchParams.get( 'q' ) ).toBe( 'shipping costs' );
	} );

	it( 'extends rest_route when the site uses plain permalinks', () => {
		window.prstsearchSettings = {
			restUrl: 'https://example.org/index.php?rest_route=/prstsearch/v1',
		};

		const url = routeUrl( '/answer', { q: 'returns' } );

		expect( url.pathname ).toBe( '/index.php' );
		expect( url.searchParams.get( 'rest_route' ) ).toBe(
			'/prstsearch/v1/answer'
		);
		expect( url.searchParams.get( 'q' ) ).toBe( 'returns' );
	} );

	it( 'passes the page language for multilingual sites', () => {
		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1',
		};
		document.documentElement.lang = 'de-DE';

		expect( routeUrl( '/search' ).searchParams.get( 'lang' ) ).toBe(
			'de-DE'
		);
	} );
} );
