import { loadAnswers } from '../../assets/src/frontend/answer';

const FINISHED =
	'<div class="prstsearch-answer__text"><p>We ship worldwide.</p></div>';

/**
 * Stands in for the browser's EventSource. Tests send the events themselves.
 */
class FakeEventSource {
	static instances = [];

	constructor( url ) {
		this.url = url;
		this.isClosed = false;
		this.listeners = {};

		FakeEventSource.instances.push( this );
	}

	addEventListener( name, listener ) {
		this.listeners[ name ] = listener;
	}

	close() {
		this.isClosed = true;
	}

	/**
	 * Delivers an event the way the server sends it: its data as JSON.
	 *
	 * @param {string} name   Name of the event.
	 * @param {*}      [data] Its data.
	 */
	emit( name, data ) {
		this.listeners[ name ]?.( { data: JSON.stringify( data ) } );
	}
}

/**
 * Renders the placeholder the server prints and starts loading its answer.
 *
 * @return {{region: HTMLElement, body: HTMLElement}} The parts of the placeholder.
 */
function renderPlaceholder() {
	document.body.innerHTML = `
		<section data-prstsearch-answer data-query="shipping" data-token="7.abc" hidden>
			<div aria-live="polite" aria-busy="true" data-prstsearch-answer-body>
				<p>Writing an answer …</p>
			</div>
		</section>`;

	loadAnswers();

	return {
		region: document.querySelector( '[data-prstsearch-answer]' ),
		body: document.querySelector( '[data-prstsearch-answer-body]' ),
	};
}

/**
 * Makes the ordinary answer route respond with the given markup.
 *
 * @param {string} html Markup of the answer.
 */
function answerWith( html ) {
	global.fetch = jest.fn( () =>
		Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( { html } ),
		} )
	);
}

/**
 * Lets pending promise callbacks run.
 */
async function settle() {
	for ( let turn = 0; turn < 10; turn++ ) {
		await Promise.resolve();
	}
}

describe( 'loadAnswers', () => {
	beforeEach( () => {
		FakeEventSource.instances = [];
		global.EventSource = FakeEventSource;

		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1',
			streamAnswers: false,
		};

		answerWith( FINISHED );
	} );

	it( 'reveals the region and loads the answer in one piece by default', async () => {
		const { region, body } = renderPlaceholder();

		expect( region.hidden ).toBe( false );

		await settle();

		expect( FakeEventSource.instances ).toHaveLength( 0 );
		expect( body.innerHTML ).toBe( FINISHED );
		expect( body.getAttribute( 'aria-busy' ) ).toBe( 'false' );
	} );

	it( 'removes the region when there is no answer', async () => {
		answerWith( '' );
		renderPlaceholder();

		await settle();

		expect(
			document.querySelector( '[data-prstsearch-answer]' )
		).toBeNull();
	} );

	it( 'requests every answer only once', async () => {
		renderPlaceholder();
		loadAnswers();

		await settle();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	describe( 'while the answer is written', () => {
		beforeEach( () => {
			window.prstsearchSettings.streamAnswers = true;
		} );

		it( 'asks the stream for the query of the placeholder', () => {
			renderPlaceholder();

			const { url } = FakeEventSource.instances[ 0 ];

			expect( url.pathname ).toBe(
				'/wp-json/prstsearch/v1/answer/stream'
			);
			expect( url.searchParams.get( 'q' ) ).toBe( 'shipping' );
			expect( url.searchParams.get( 'token' ) ).toBe( '7.abc' );
		} );

		it( 'shows the text as it arrives and keeps screen readers waiting', async () => {
			const { body } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			source.emit( 'text', 'We ship ' );
			source.emit( 'text', 'worldwide.' );
			await settle();

			expect( body.textContent ).toBe( 'We ship worldwide.' );
			expect( body.getAttribute( 'aria-busy' ) ).toBe( 'true' );
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'never treats the text of the model as markup', () => {
			const { body } = renderPlaceholder();

			FakeEventSource.instances[ 0 ].emit(
				'text',
				'<img src=x onerror=alert(1)>'
			);

			expect( body.querySelector( 'img' ) ).toBeNull();
			expect( body.textContent ).toBe( '<img src=x onerror=alert(1)>' );
		} );

		it( 'replaces the text with the finished answer and closes the stream', async () => {
			const { body } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			source.emit( 'text', 'We ship worldwide [1].' );
			source.emit( 'done', { html: FINISHED } );
			await settle();

			expect( body.innerHTML ).toBe( FINISHED );
			expect( body.getAttribute( 'aria-busy' ) ).toBe( 'false' );
			expect( source.isClosed ).toBe( true );
		} );

		it( 'removes the region when the stream ends without an answer', async () => {
			renderPlaceholder();

			FakeEventSource.instances[ 0 ].emit( 'done', { html: '' } );
			await settle();

			expect(
				document.querySelector( '[data-prstsearch-answer]' )
			).toBeNull();
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'uses the ordinary route when the stream fails before anything arrived', async () => {
			const { body } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			source.emit( 'error' );
			await settle();

			expect( source.isClosed ).toBe( true );
			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
			expect( body.innerHTML ).toBe( FINISHED );
		} );

		it( 'keeps what was written and asks for no second answer when the stream breaks off', async () => {
			const { region, body } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			source.emit( 'text', 'We ship worldwide' );
			source.emit( 'error' );
			await settle();

			expect( source.isClosed ).toBe( true );
			expect( global.fetch ).not.toHaveBeenCalled();
			expect( region.isConnected ).toBe( true );
			expect( body.textContent ).toBe( 'We ship worldwide' );
			expect( body.getAttribute( 'aria-busy' ) ).toBe( 'false' );
		} );

		it( 'treats data it cannot read as a failed stream instead of waiting for ever', async () => {
			const { body } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			source.listeners.done( { data: 'not json' } );
			await settle();

			expect( source.isClosed ).toBe( true );
			expect( body.innerHTML ).toBe( FINISHED );
		} );

		it( 'keeps the growing text away from screen readers, which read the finished answer', () => {
			const { body } = renderPlaceholder();

			FakeEventSource.instances[ 0 ].emit( 'text', 'We ship' );

			expect( body.firstElementChild.getAttribute( 'aria-hidden' ) ).toBe(
				'true'
			);
		} );

		it( 'stops listening when a new search has replaced the region', async () => {
			const { region } = renderPlaceholder();
			const [ source ] = FakeEventSource.instances;

			region.remove();
			source.emit( 'text', 'We ship' );
			await settle();

			expect( source.isClosed ).toBe( true );
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'uses the ordinary route in browsers without event streams', async () => {
			delete global.EventSource;

			const { body } = renderPlaceholder();

			await settle();

			expect( body.innerHTML ).toBe( FINISHED );
		} );
	} );
} );
