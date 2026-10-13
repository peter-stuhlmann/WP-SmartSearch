/**
 * Search that shows its results on the same page.
 *
 * Progressive enhancement: without this script the form submits to the
 * regular search page, and the filter and "more" links lead there as well.
 */
import { getJson, settings } from './api';
import { loadAnswers } from './answer';
import { createClickTracker } from './clicks';

const MORE = '[data-prstsearch-more]';
const FILTER = '[data-prstsearch-type]';
const CURRENT_FILTER = `${ FILTER }[aria-current="true"]`;
const ANSWER = '[data-prstsearch-answer]';
const RESULT_LINK = '[data-prstsearch-list] a';

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
		// A list the server printed can already be limited to a content type.
		type:
			output.querySelector( CURRENT_FILTER )?.dataset.prstsearchType ??
			'',
		token: rendered?.token ?? '',
	};

	/**
	 * The request in flight. A newer one cancels it, so a slow response can
	 * never overwrite the results of a later search.
	 *
	 * @type {?AbortController}
	 */
	let request = null;

	/**
	 * Whether a new search is on its way. Until it arrives, the list on
	 * screen is on its way out, and its controls no longer do anything:
	 * acting on them would cancel the search the visitor just started.
	 */
	let isSearching = false;

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
			isSearching = false;
			output.removeAttribute( 'aria-busy' );
		}
	};

	const show = ( data ) => {
		// An answer that is already there, or being written, is the answer to this query whatever the filter.
		const answer = output.querySelector( ANSWER );
		const keeps = answer?.dataset.query === data.query;

		// The markup comes from the plugin's own, escaped PHP templates.
		output.innerHTML = ( showAnswer ? data.answer_html : '' ) + data.html;

		if ( keeps ) {
			const placeholder = output.querySelector( ANSWER );

			if ( placeholder ) {
				placeholder.replaceWith( answer );
			} else {
				output.prepend( answer );
			}
		}

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
		// Said through the region that was there from the start; a note inserted with its text would stay silent.
		announce( settings().strings.refining );
		// A further page would belong to the list that is about to change.
		output.querySelector( MORE )?.setAttribute( 'aria-disabled', 'true' );

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

			assertResults( data );

			if ( sameResults( first, data ) && ! data.answer_html ) {
				announce( first.summary );
				return;
			}

			const focused = describeFocus( output );

			show( data );
			restoreFocus( output, focused );
		} catch {
			// The results the search found by itself stay. Nothing is lost, so nothing is reported.
			if ( ! current.signal.aborted ) {
				announce( first.summary );
			}
		} finally {
			if ( ! current.signal.aborted ) {
				note.remove();
				output
					.querySelector( MORE )
					?.removeAttribute( 'aria-disabled' );
			}

			end( current );
		}
	};

	/**
	 * Searches and shows the results.
	 *
	 * @param {string}  query        The search phrase.
	 * @param {string}  [type]       Content type to limit the results to.
	 * @param {boolean} [keepsFocus] Whether the search was started from inside the results, so focus has to stay there.
	 */
	const search = async ( query, type = '', keepsFocus = false ) => {
		const current = begin();

		isSearching = true;
		output.setAttribute( 'aria-busy', 'true' );
		announce( settings().strings.searching );

		try {
			const data = await getJson(
				'/search',
				{ q: query, type },
				current.signal
			);

			assertResults( data );

			shown = { query, type, token: data.token };
			show( data );

			// The filter that was chosen is gone with the old list. Focus goes to its successor.
			if ( keepsFocus ) {
				output.querySelector( CURRENT_FILTER )?.focus();
			}

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

	const loadMore = async ( link ) => {
		const current = begin();

		// aria-disabled keeps the link focusable while loading.
		link.setAttribute( 'aria-disabled', 'true' );
		output.querySelector( '[data-prstsearch-error]' )?.remove();

		try {
			const data = await getJson(
				'/search',
				{ q: shown.query, type: shown.type, page: link.dataset.page },
				current.signal
			);

			assertResults( data );
			appendResults( output, link, data );

			// Only the first page is logged, so further pages count towards its entry.
			tracker.add( shown.token, postsOf( data ) );
		} catch {
			if ( current.signal.aborted ) {
				return;
			}

			link.removeAttribute( 'aria-disabled' );
			link.after( errorMessage( settings().strings.moreError ) );
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
		const more = target?.closest( MORE );
		const filter = target?.closest( FILTER );

		// Somebody opening a link in a new tab wants the link, not this script. And without a query
		// to work with, the link does what it would do without the script: go to the search page.
		if ( ( ! more && ! filter ) || isModified( event ) || ! shown.query ) {
			return;
		}

		event.preventDefault();

		if ( isSearching ) {
			return;
		}

		if ( more && more.getAttribute( 'aria-disabled' ) !== 'true' ) {
			loadMore( more );
		} else if ( filter ) {
			search( shown.query, filter.dataset.prstsearchType, true );
		}
	} );
}

/**
 * Adds a further page of results to the list and moves focus to the first new one.
 *
 * @param {HTMLElement} output Output region.
 * @param {HTMLElement} link   The "more" link that was activated.
 * @param {Object}      data   Response of the search route.
 */
function appendResults( output, link, data ) {
	const list = output.querySelector( '[data-prstsearch-list]' );

	if ( ! list ) {
		return;
	}

	const previousCount = list.children.length;

	list.insertAdjacentHTML( 'beforeend', data.items_html );

	// Keyboard users continue where the new results begin, not at the link that may be gone.
	const firstNewLink = list.children[ previousCount ]?.querySelector( 'a' );

	if ( data.page < data.pages ) {
		link.dataset.page = String( data.page + 1 );
		link.removeAttribute( 'aria-disabled' );

		// Opened in a new tab, the link has to lead to the page that comes next, too.
		const address = new URL( link.href, window.location.href );

		address.searchParams.set( 'paged', link.dataset.page );
		link.href = address.toString();
	} else {
		link.remove();
	}

	firstNewLink?.focus();
}

/**
 * Makes sure a response is what the search route sends.
 *
 * A proxy or a security plugin can answer in its place, with status 200 and
 * something else entirely. That is a failed search, not an empty one.
 *
 * @param {*} data Decoded response.
 */
function assertResults( data ) {
	const isValid =
		data &&
		Array.isArray( data.results ) &&
		typeof data.html === 'string' &&
		typeof data.items_html === 'string';

	if ( ! isValid ) {
		throw new Error( 'Unexpected response of the search.' );
	}
}

/**
 * Tells whether a click was made with a key or a button that asks the browser for something else.
 *
 * @param {MouseEvent} event The click.
 * @return {boolean} Whether to leave the click to the browser.
 */
function isModified( event ) {
	return (
		event.button > 0 ||
		event.ctrlKey ||
		event.metaKey ||
		event.shiftKey ||
		event.altKey
	);
}

/**
 * Notes what has focus inside the output region, in a way that survives the region being rebuilt.
 *
 * @param {HTMLElement} output Output region.
 * @return {?{selector: string, href: string}} What to look for afterwards, null if focus is elsewhere.
 */
function describeFocus( output ) {
	const focused = output.ownerDocument.activeElement;

	if ( ! focused || ! output.contains( focused ) ) {
		return null;
	}

	if ( focused.matches( MORE ) ) {
		return { selector: MORE, href: '' };
	}

	if ( focused.matches( FILTER ) ) {
		return { selector: CURRENT_FILTER, href: '' };
	}

	return {
		selector: RESULT_LINK,
		href: focused.getAttribute( 'href' ) ?? '',
	};
}

/**
 * Puts focus back where it was after the output region was rebuilt.
 *
 * On the same control if it still exists, otherwise on the first result:
 * never on nothing, which would send a keyboard user back to the top of the page.
 *
 * @param {HTMLElement}                       output  Output region.
 * @param {?{selector: string, href: string}} focused What had focus, see describeFocus().
 */
function restoreFocus( output, focused ) {
	if ( ! focused ) {
		return;
	}

	const candidates = [ ...output.querySelectorAll( focused.selector ) ];
	const same = focused.href
		? candidates.find(
				( link ) => link.getAttribute( 'href' ) === focused.href
		  )
		: candidates[ 0 ];

	( same ?? output.querySelector( RESULT_LINK ) )?.focus();
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
 * Builds the visible line that says the search is still looking.
 *
 * @param {string} [text] The message.
 * @return {HTMLElement} The line.
 */
function pendingNote( text ) {
	const note = document.createElement( 'p' );

	note.className = 'prstsearch__pending';
	// Screen readers hear it from the status region; twice would be once too often.
	note.setAttribute( 'aria-hidden', 'true' );
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
