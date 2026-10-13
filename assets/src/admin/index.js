/**
 * Entry of the settings page.
 */
import './admin.scss';

import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';

import { initConfirmations } from './confirm';
import { initIndexTools } from './rebuild';
import { initProviderForm } from './provider';
import { initRecommendations } from './recommendations';
import { initTestSearch } from './test-search';

domReady( () => {
	const notice = document.querySelector( '[data-prstsearch-notice]' );

	// The outcome of saving or importing, for those who cannot see it appear.
	if ( notice ) {
		speak(
			notice.textContent.trim(),
			notice.dataset.prstsearchNotice === 'error' ? 'assertive' : 'polite'
		);
	}

	initConfirmations();
	initIndexTools();
	initProviderForm();
	initRecommendations();
	initTestSearch();
} );
