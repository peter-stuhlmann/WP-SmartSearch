import { enhanceLiveSearch } from '../../assets/src/frontend/live-search';

/**
 * Builds the response of the search route.
 *
 * @param {string} title   Title of the single result.
 * @param {Object} [extra] Fields that differ from the defaults.
 * @return {Object} The response body.
 */
function response( title, extra = {} ) {
	return {
		token: 'token-1',
		page: 1,
		pages: 2,
		summary: `1 result for ${ title }`,
		results: [ { id: 1, url: `https://example.org/${ title }/` } ],
		answer_html: '',
		html: `<ol data-prstsearch-list><li><a href="https://example.org/${ title }/">${ title }</a></li></ol>
			<a href="https://example.org/?s=${ title }&paged=2" data-prstsearch-more data-page="2">More</a>`,
		items_html: `<li><a href="https://example.org/${ title }/">${ title }</a></li>`,
		...extra,
	};
}

/**
 * Renders the markup of a live search and enhances it.
 *
 * @param {string} [query] Query the server rendered the page for.
 * @return {{form: HTMLFormElement, input: HTMLInputElement, output: HTMLElement, status: HTMLElement}} The parts of the search.
 */
function renderSearch( query = '' ) {
	document.body.innerHTML = `
		<div data-prstsearch-live>
			<form data-prstsearch-form><input type="search" name="s" value="${ query }" /></form>
			<p role="status" data-prstsearch-live-status></p>
			<div data-prstsearch-output></div>
		</div>`;

	enhanceLiveSearch( document.querySelector( '[data-prstsearch-live]' ) );

	return {
		form: document.querySelector( 'form' ),
		input: document.querySelector( 'input' ),
		output: document.querySelector( '[data-prstsearch-output]' ),
		status: document.querySelector( '[role="status"]' ),
	};
}

/**
 * Makes fetch answer each request only when the test says so.
 *
 * @return {Array<{url: URL, signal: AbortSignal, resolve: Function, reject: Function}>} The requests, in the order they were made.
 */
function holdRequests() {
	const requests = [];

	global.fetch = jest.fn(
		( url, { signal } ) =>
			new Promise( ( resolve, reject ) => {
				signal.addEventListener( 'abort', () =>
					reject( new DOMException( 'Aborted', 'AbortError' ) )
				);

				requests.push( {
					url,
					signal,
					resolve: ( body ) =>
						resolve( {
							ok: true,
							json: () => Promise.resolve( body ),
						} ),
					reject,
				} );
			} )
	);

	return requests;
}

/**
 * Submits the form with a query.
 *
 * @param {{form: HTMLFormElement, input: HTMLInputElement}} search The search.
 * @param {string}                                           query  Text to search for.
 */
function submit( { form, input }, query ) {
	input.value = query;
	form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
}

/**
 * Lets pending promise callbacks run.
 */
async function settle() {
	for ( let turn = 0; turn < 10; turn++ ) {
		await Promise.resolve();
	}
}

describe( 'enhanceLiveSearch', () => {
	beforeEach( () => {
		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1',
			trackClicks: false,
			strings: {
				searching: 'Searching …',
				error: 'The search is not available.',
				moreError: 'More results could not be loaded.',
				refining: 'Looking for further matching pages …',
			},
		};
	} );

	it( 'shows the results and announces the summary', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );

		expect( search.output.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( search.status.textContent ).toBe( 'Searching …' );

		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		expect( search.output.querySelector( 'a' ).textContent ).toBe(
			'shipping'
		);
		expect( search.status.textContent ).toBe( '1 result for shipping' );
		expect( search.output.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	describe( 'when the language model is still to be asked', () => {
		const NOTHING = {
			pending: true,
			token: 'token-1',
			total: 0,
			summary: 'No results for poultry.',
			results: [],
			answer_html: '',
			html: '<p data-first>No results.</p>',
			items_html: '',
		};

		it( 'shows what was found at once and says that it is still looking', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();

			expect(
				search.output.querySelector( '[data-first]' )
			).not.toBeNull();
			expect( search.output.hasAttribute( 'aria-busy' ) ).toBe( false );
			expect(
				search.output.querySelector( '.prstsearch__pending' )
					.textContent
			).toBe( 'Looking for further matching pages …' );

			const { searchParams } = requests[ 1 ].url;

			expect( searchParams.get( 'q' ) ).toBe( 'poultry' );
			expect( searchParams.get( 'refine' ) ).toBe( '1' );
			expect( searchParams.get( 'token' ) ).toBe( 'token-1' );
		} );

		it( 'says through the status region that it is still looking', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();

			// The region that was on the page from the start. A note inserted with its text would not be read out.
			expect( search.status.textContent ).toBe(
				'Looking for further matching pages …'
			);
			expect(
				search.output
					.querySelector( '.prstsearch__pending' )
					.getAttribute( 'aria-hidden' )
			).toBe( 'true' );

			requests[ 1 ].resolve( { ...NOTHING, pending: false } );
			await settle();

			expect( search.status.textContent ).toBe(
				'No results for poultry.'
			);
		} );

		it( 'keeps focus on the same control when the results are replaced', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( {
				...response( 'weak' ),
				pending: true,
			} );
			await settle();

			search.output.querySelector( '[data-prstsearch-more]' ).focus();
			requests[ 1 ].resolve( response( 'chicken' ) );
			await settle();

			expect( document.activeElement ).toBe(
				search.output.querySelector( '[data-prstsearch-more]' )
			);
		} );

		it( 'replaces the results when the model found more', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();
			requests[ 1 ].resolve( response( 'chicken', { total: 1 } ) );
			await settle();

			expect( search.output.querySelector( 'a' ).textContent ).toBe(
				'chicken'
			);
			expect(
				search.output.querySelector( '.prstsearch__pending' )
			).toBeNull();
			expect( search.status.textContent ).toBe( '1 result for chicken' );
		} );

		it( 'leaves the page alone when the model found nothing new', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();

			const shown = search.output.querySelector( '[data-first]' );

			requests[ 1 ].resolve( { ...NOTHING, pending: false } );
			await settle();

			expect( search.output.querySelector( '[data-first]' ) ).toBe(
				shown
			);
			expect(
				search.output.querySelector( '.prstsearch__pending' )
			).toBeNull();
		} );

		it( 'keeps what was found when the model cannot be asked', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();
			requests[ 1 ].reject( new Error( 'offline' ) );
			await settle();

			expect(
				search.output.querySelector( '[data-first]' )
			).not.toBeNull();
			expect(
				search.output.querySelector( '.prstsearch__pending' )
			).toBeNull();
			expect(
				search.output.querySelector( '[role="alert"]' )
			).toBeNull();
		} );

		it( 'gives up on the model when the visitor searches for something else', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'poultry' );
			requests[ 0 ].resolve( NOTHING );
			await settle();
			submit( search, 'shipping' );

			expect( requests[ 1 ].signal.aborted ).toBe( true );

			requests[ 2 ].resolve( response( 'shipping' ) );
			await settle();
			requests[ 1 ].resolve( response( 'chicken' ) );
			await settle();

			expect( search.output.querySelector( 'a' ).textContent ).toBe(
				'shipping'
			);
		} );
	} );

	describe( 'the filter by content type', () => {
		const FILTER =
			'<nav><a href="https://example.org/?s=shipping&post_type=post" data-prstsearch-type="post">Posts</a></nav>';

		it( 'leaves the link to the browser when it is opened in a new tab', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'shipping' );
			requests[ 0 ].resolve(
				response( 'shipping', {
					html: FILTER + response( 'shipping' ).html,
				} )
			);
			await settle();

			const click = new MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
				ctrlKey: true,
			} );

			search.output
				.querySelector( '[data-prstsearch-type]' )
				.dispatchEvent( click );

			expect( click.defaultPrevented ).toBe( false );
			expect( requests ).toHaveLength( 1 );
		} );

		it( 'keeps keyboard focus in the results after a type was chosen', async () => {
			const requests = holdRequests();
			const search = renderSearch();
			const chosen =
				'<nav><a href="#" data-prstsearch-type="post" aria-current="true">Posts</a></nav>';

			submit( search, 'shipping' );
			requests[ 0 ].resolve(
				response( 'shipping', {
					html: FILTER + response( 'shipping' ).html,
				} )
			);
			await settle();

			const link = search.output.querySelector(
				'[data-prstsearch-type]'
			);

			link.focus();
			link.click();
			requests[ 1 ].resolve(
				response( 'shipping', {
					html: chosen + response( 'shipping' ).html,
				} )
			);
			await settle();

			expect( document.activeElement ).toBe(
				search.output.querySelector( '[aria-current="true"]' )
			);
		} );

		it( 'continues a list the server already limited to a type', () => {
			const requests = holdRequests();

			document.body.innerHTML = `
				<div data-prstsearch-live>
					<form data-prstsearch-form><input type="search" name="s" value="shipping" /></form>
					<div data-prstsearch-output>
						<nav><a href="#" data-prstsearch-type="post" aria-current="true">Posts</a></nav>
						${ response( 'shipping' ).html }
					</div>
				</div>`;

			enhanceLiveSearch(
				document.querySelector( '[data-prstsearch-live]' )
			);
			document.querySelector( '[data-prstsearch-more]' ).click();

			expect( requests[ 0 ].url.searchParams.get( 'type' ) ).toBe(
				'post'
			);
		} );

		it( 'does not ask for the answer again, which is the same for every type', async () => {
			const requests = holdRequests();
			const search = renderSearch();
			const placeholder =
				'<section data-prstsearch-answer data-query="shipping" data-loaded="true"><p>The answer.</p></section>';

			submit( search, 'shipping' );
			requests[ 0 ].resolve(
				response( 'shipping', {
					query: 'shipping',
					answer_html: placeholder,
					html: FILTER + response( 'shipping' ).html,
				} )
			);
			await settle();

			const answer = search.output.querySelector(
				'[data-prstsearch-answer]'
			);

			search.output.querySelector( '[data-prstsearch-type]' ).click();
			requests[ 1 ].resolve(
				response( 'shipping', {
					query: 'shipping',
					answer_html: placeholder.replace(
						' data-loaded="true"',
						''
					),
				} )
			);
			await settle();

			// The very element, not a new placeholder that would load the answer once more.
			expect(
				search.output.querySelector( '[data-prstsearch-answer]' )
			).toBe( answer );
			expect( requests ).toHaveLength( 2 );
		} );

		it( 'searches again for the chosen type instead of leaving the page', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'shipping' );
			requests[ 0 ].resolve(
				response( 'shipping', {
					html: FILTER + response( 'shipping' ).html,
				} )
			);
			await settle();

			const click = new MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );

			search.output
				.querySelector( '[data-prstsearch-type]' )
				.dispatchEvent( click );

			expect( click.defaultPrevented ).toBe( true );
			expect( requests[ 1 ].url.searchParams.get( 'q' ) ).toBe(
				'shipping'
			);
			expect( requests[ 1 ].url.searchParams.get( 'type' ) ).toBe(
				'post'
			);

			requests[ 1 ].resolve( response( 'shipping' ) );
			await settle();
			search.output.querySelector( '[data-prstsearch-more]' ).click();

			expect( requests[ 2 ].url.searchParams.get( 'type' ) ).toBe(
				'post'
			);
			expect( requests[ 2 ].url.searchParams.get( 'page' ) ).toBe( '2' );
		} );
	} );

	describe( 'while a new search is on its way', () => {
		it( 'does not let a control of the old list cancel it', async () => {
			const requests = holdRequests();
			const search = renderSearch();

			submit( search, 'first' );
			requests[ 0 ].resolve( response( 'first' ) );
			await settle();

			submit( search, 'second' );
			search.output.querySelector( '[data-prstsearch-more]' ).click();

			expect( requests ).toHaveLength( 2 );
			expect( requests[ 1 ].signal.aborted ).toBe( false );

			requests[ 1 ].resolve( response( 'second' ) );
			await settle();

			expect( search.output.querySelector( 'a' ).textContent ).toBe(
				'second'
			);
			expect( search.status.textContent ).toBe( '1 result for second' );
		} );
	} );

	it( 'reports a response that is not from the search as a failed search', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		// What a proxy or a security plugin answers in place of the website, with status 200.
		requests[ 0 ].resolve( { code: 'blocked' } );
		await settle();

		expect(
			search.output.querySelector( '[role="alert"]' ).textContent
		).toBe( 'The search is not available.' );
		expect( search.output.textContent ).not.toContain( 'undefined' );
	} );

	it( 'keeps the list as it is when a further page comes back as something else', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		const more = search.output.querySelector( '[data-prstsearch-more]' );

		more.click();
		requests[ 1 ].resolve( { code: 'blocked' } );
		await settle();

		expect(
			search.output.querySelectorAll( '[data-prstsearch-list] a' )
		).toHaveLength( 1 );
		expect( more.isConnected ).toBe( true );
		expect(
			search.output.querySelector( '[role="alert"]' ).textContent
		).toBe( 'More results could not be loaded.' );
	} );

	it( 'does not search for an empty query', () => {
		const requests = holdRequests();

		submit( renderSearch(), '   ' );

		expect( requests ).toHaveLength( 0 );
	} );

	it( 'never lets a slow response overwrite a newer search', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'first' );
		submit( search, 'second' );

		expect( requests[ 0 ].signal.aborted ).toBe( true );

		requests[ 1 ].resolve( response( 'second' ) );
		await settle();
		// The cancelled request answering late must change nothing.
		requests[ 0 ].resolve( response( 'first' ) );
		await settle();

		expect( search.output.querySelector( 'a' ).textContent ).toBe(
			'second'
		);
		expect( search.status.textContent ).toBe( '1 result for second' );
		expect( search.output.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	it( 'shows an error that screen readers announce', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].reject( new Error( 'offline' ) );
		await settle();

		const alert = search.output.querySelector( '[role="alert"]' );

		expect( alert.textContent ).toBe( 'The search is not available.' );
		expect( search.status.textContent ).toBe( '' );
		expect( search.output.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	it( 'loads further pages of the search that is shown, not of what is typed', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		search.input.value = 'something else';
		search.output.querySelector( '[data-prstsearch-more]' ).click();

		expect( requests[ 1 ].url.searchParams.get( 'q' ) ).toBe( 'shipping' );
		expect( requests[ 1 ].url.searchParams.get( 'page' ) ).toBe( '2' );
	} );

	it( 'loads further pages of the results the server rendered', () => {
		const requests = holdRequests();
		const search = renderSearch( 'returns' );

		search.output.innerHTML = response( 'returns' ).html;
		search.output.querySelector( '[data-prstsearch-more]' ).click();

		expect( requests[ 0 ].url.searchParams.get( 'q' ) ).toBe( 'returns' );
	} );

	it( 'appends the new results, focuses the first and removes the button on the last page', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		search.output.querySelector( '[data-prstsearch-more]' ).click();
		requests[ 1 ].resolve( response( 'returns', { page: 2 } ) );
		await settle();

		const links = search.output.querySelectorAll( 'a' );

		expect( links ).toHaveLength( 2 );
		expect( document.activeElement ).toBe( links[ 1 ] );
		expect(
			search.output.querySelector( '[data-prstsearch-more]' )
		).toBeNull();
	} );

	it( 'ignores a second press while a page is loading', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		const button = search.output.querySelector( '[data-prstsearch-more]' );

		button.click();
		button.click();

		expect( requests ).toHaveLength( 2 );
	} );

	it( 'says so when a further page cannot be loaded, and lets the visitor retry', async () => {
		const requests = holdRequests();
		const search = renderSearch();

		submit( search, 'shipping' );
		requests[ 0 ].resolve( response( 'shipping' ) );
		await settle();

		const button = search.output.querySelector( '[data-prstsearch-more]' );

		button.click();
		requests[ 1 ].reject( new Error( 'offline' ) );
		await settle();

		expect(
			search.output.querySelector( '[role="alert"]' ).textContent
		).toBe( 'More results could not be loaded.' );
		expect( button.hasAttribute( 'aria-disabled' ) ).toBe( false );

		button.click();

		expect( search.output.querySelector( '[role="alert"]' ) ).toBeNull();
		expect( requests ).toHaveLength( 3 );
	} );
} );
