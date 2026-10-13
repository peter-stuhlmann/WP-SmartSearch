/**
 * Entry of the settings page.
 */
import './admin.scss';

import domReady from '@wordpress/dom-ready';

import { initConfirmations } from './confirm';
import { initIndexTools } from './rebuild';
import { initProviderForm } from './provider';
import { initRecommendations } from './recommendations';
import { initTestSearch } from './test-search';

domReady( () => {
	initConfirmations();
	initIndexTools();
	initProviderForm();
	initRecommendations();
	initTestSearch();
} );
