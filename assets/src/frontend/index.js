/**
 * Front-end entry: enhances what the server rendered.
 */
import './frontend.scss';

import { settings } from './api';
import { loadAnswers } from './answer';
import { createClickTracker } from './clicks';
import { enhanceCombobox } from './combobox';
import { enhanceLiveSearch } from './live-search';

const LIVE_OUTPUT = '[data-prstsearch-output]';

function init() {
	const { clicks } = settings();

	document
		.querySelectorAll( '[data-prstsearch-combobox]' )
		.forEach( enhanceCombobox );
	document
		.querySelectorAll( '[data-prstsearch-live]' )
		.forEach( ( container ) => enhanceLiveSearch( container, clicks ) );

	loadAnswers();

	if ( clicks ) {
		// Results the theme printed itself. Live searches look after their own region.
		const content =
			document.querySelector( 'main, [role="main"]' ) ?? document.body;

		createClickTracker( content, LIVE_OUTPUT ).replace(
			clicks.token,
			clicks.posts
		);
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
