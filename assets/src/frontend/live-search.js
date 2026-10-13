/**
 * Search that shows its results on the same page.
 *
 * Progressive enhancement: without this script the form submits to the
 * regular search page, and the filter links lead there as well.
 */
import { getJson, settings } from './api';
import { loadAnswers } from './answer';
import { createClickTracker } from './clicks';

/**
 * Turns a container with a search form and an output region into a live search.
 *
 * @param {HTMLElement}                                     container Element with the data-prstsearch-live attribute.
 * @param {?{token: string, posts: Object<string, number>}} rendered  Results the server already printed into the output region.
 */
export function enhanceLiveSearch( container, rendered = null ) {
	const form = container.querySelector( '[data-prstsearch-form]' );
	const input = form?.querySelector( 'input[name="s"]' );
	const output = container.querySelector( '[data-prstsearch-output]' );

	if ( ! form || ! input || ! output ) {
		return;
	}

	const status = container.querySelector( '[data-prstsearch-live-status]' );
	const showAnswer = container.dataset.showAnswer !== 'false';
	const tracker = createClickTracker( output );

	/*
	 * The search the output region currently shows. Further pages and the
	 * filter belong to it, whatever has been typed into the field since:
	 * defaultValue is what the server printed, not what the visitor is typing.
	 */
	let shown = {
		query: input.defaultValue.trim(),
		type: '',
		token: rendered?.token ?? '',
	};

	/**
	 * The request in flight. A newer one cancels it, so a slow response can
	 * never overwrite the results of a later search.
	 *
	 * @type {?AbortController}
	 */
	let request = null;

	if ( rendered ) {
		tracker.replace( rendered.token, rendered.posts );
	}

	const announce = ( text ) => {
		if ( status ) {
			status.textContent = text ?? '';
		}
	};

	const begin = () => {
		request?.abort();
		request = new AbortController();

		return request;
	};

	const end = ( current ) => {
		if ( request === current ) {
			request = null;
			output.removeAttribute( 'aria-busy' );
		}
	};

	const show = ( data ) => {
		// The markup comes from the plugin's own, escaped PHP templates.
		output.innerHTML = ( showAnswer ? data.answer_html : '' ) + data.html;

		loadAnswers( output );
		tracker.replace( data.token, postsOf( data ) );
		announce( data.summary );
	};

	/**
	 * Asks the language model for pages the search missed, while the visitor already reads what it found.
	 *
	 * @param {Object} first Response of the search that is shown.
	 */
	const refine = async ( first ) => {
		const current = begin();
		const note = pendingNote( settings().strings.refining );

		output.prepend( note );
		// A further page would belong to the list that is about to change.
		output
			.querySelector( '[data-prstsearch-more]' )
			?.setAttribute( 'aria-disabled', 'true' );

		try {
			const data = await getJson(
				'/search',
				{
					q: shown.query,
					type: shown.type,
					token: first.token,
					refine: '1',
				},
				current.signal
			);

			if ( sameResults( first, data ) ) {
				return;
			}

			const hadFocus = output.contains(
				output.ownerDocument.activeElement
			);

			show( data );

			// Replacing the list removes the element that had focus. It must not fall back to the top of the page.
			if ( hadFocus ) {
				output.querySelector( '[data-prstsearch-list] a' )?.focus();
			}
		} catch {
			// The results the search found by itself stay. Nothing is lost, so nothing is reported.
		} finally {
			if ( ! current.signal.aborted ) {
				note.remove();
				output
					.querySelector( '[data-prstsearch-more]' )
					?.removeAttribute( 'aria-disabled' );
			}

			end( current );
		}
	};

	const search = async ( query, type = '' ) => {
		const current = begin();

		output.setAttribute( 'aria-busy', 'true' );
		announce( settings().strings.searching );

		try {
			const data = await getJson(
				'/search',
				{ q: query, type },
				current.signal
			);

			shown = { query, type, token: data.token };
			show( data );
			end( current );

			if ( data.pending ) {
				refine( data );
			}
		} catch {
			if ( current.signal.aborted ) {
				return;
			}

			// The alert is announced by itself.
			announce( '' );
			output.replaceChildren( errorMessage( settings().strings.error ) );
			end( current );
		}
	};

	const loadMore = async ( button ) => {
		const current = begin();

		// aria-disabled instead of disabled keeps the button focusable while loading.
		button.setAttribute( 'aria-disabled', 'true' );
		output.querySelector( '[data-prstsearch-error]' )?.remove();

		try {
			const data = await getJson(
				'/search',
				{ q: shown.query, type: shown.type, page: button.dataset.page },
				current.signal
			);

			appendResults( output, button, data );

			// Only the first page is logged, so further pages count towards its entry.
			tracker.add( shown.token, postsOf( data ) );
		} catch {
			if ( current.signal.aborted ) {
				return;
			}

			button.removeAttribute( 'aria-disabled' );
			button.after( errorMessage( settings().strings.moreError ) );
		} finally {
			end( current );
		}
	};

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();

		const query = input.value.trim();

		if ( query ) {
			search( query );
		}
	} );

	output.addEventListener( 'click', ( event ) => {
		const target = event.target instanceof Element ? event.target : null;
		const button = target?.closest( '[data-prstsearch-more]' );
		const filter = target?.closest( '[data-prstsearch-type]' );

		if ( button && button.getAttribute( 'aria-disabled' ) !== 'true' ) {
			loadMore( button );
		}

		// Without a query to filter, the link goes to the search page as it would without this script.
		if ( filter && shown.query ) {
			event.preventDefault();
			search( shown.query, filter.dataset.prstsearchType );
		}
	} );
}

/**
 * Adds a further page of results to the list and moves focus to the first new one.
 *
 * @param {HTMLElement} output Output region.
 * @param {HTMLElement} button The "more" button that was pressed.
 * @param {Object}      data   Response of the search route.
 */
function appendResults( output, button, data ) {
	const list = output.querySelector( '[data-prstsearch-list]' );

	if ( ! list ) {
		return;
	}

	const previousCount = list.children.length;

	list.insertAdjacentHTML( 'beforeend', data.items_html );

	// Keyboard users continue where the new results begin, not at the button that may be gone.
	const firstNewLink = list.children[ previousCount ]?.querySelector( 'a' );

	if ( data.page < data.pages ) {
		button.dataset.page = String( data.page + 1 );
		button.removeAttribute( 'aria-disabled' );
	} else {
		button.remove();
	}

	firstNewLink?.focus();
}

/**
 * Returns the post IDs of a response, keyed by result URL.
 *
 * @param {Object} data Response of the search route.
 * @return {Object<string, number>} Post IDs keyed by URL.
 */
function postsOf( data ) {
	return Object.fromEntries(
		data.results.map( ( result ) => [ result.url, result.id ] )
	);
}

/**
 * Tells whether two responses list the same results in the same order.
 *
 * @param {Object} a Response of the search route.
 * @param {Object} b Another one.
 * @return {boolean} Whether nothing changed for the visitor.
 */
function sameResults( a, b ) {
	const ids = ( data ) =>
		data.results.map( ( result ) => result.id ).join( ',' );

	return a.total === b.total && ids( a ) === ids( b );
}

/**
 * Builds the line that says the search is still looking.
 *
 * @param {string} [text] The message.
 * @return {HTMLElement} The line.
 */
function pendingNote( text ) {
	const note = document.createElement( 'p' );

	note.className = 'prstsearch__pending';
	// Read once, without interrupting the results the visitor is reading.
	note.setAttribute( 'role', 'status' );
	note.textContent = text ?? '';

	return note;
}

/**
 * Builds a message that screen readers announce as soon as it is inserted.
 *
 * @param {string} [text] The message.
 * @return {HTMLElement} The message element.
 */
function errorMessage( text ) {
	const message = document.createElement( 'p' );

	message.className = 'prstsearch__error';
	message.setAttribute( 'role', 'alert' );
	message.dataset.prstsearchError = '';
	message.textContent = text ?? '';

	return message;
}
