/**
 * Index status of the post being edited.
 */
import apiFetch from '@wordpress/api-fetch';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * How long to wait before asking again while the index is catching up.
 */
const POLL_MS = 5000;

/**
 * Loads the index status and keeps it current.
 *
 * The status is refreshed after every save and, while it is "stale", every
 * few seconds until the background job is done.
 *
 * @return {{status: ?Object, isBusy: boolean, error: string, update: Function}} State and an action to reindex or change the exclusion.
 */
export function useIndexStatus() {
	const { postId, isSaving } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );

	const [ status, setStatus ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	// Counts the answers, so the poll below starts again after each of them, changed or not.
	const [ answers, setAnswers ] = useState( 0 );
	const wasSaving = useRef( false );

	const path = `${ window.prstsearchEditor.restPath }/index/posts/${ postId }`;

	const call = useCallback(
		async ( options = {} ) => {
			try {
				setStatus( await apiFetch( { path, ...options } ) );
				setError( '' );
			} catch ( requestError ) {
				// Never empty: an error without text would look like no error at all.
				setError(
					requestError?.message ||
						__(
							'The search status could not be loaded.',
							'smart-search'
						)
				);
			} finally {
				setAnswers( ( count ) => count + 1 );
			}
		},
		[ path ]
	);

	// Initial load.
	useEffect( () => {
		if ( postId ) {
			call();
		}
	}, [ postId, call ] );

	// Reload when a save has finished.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			call();
		}

		wasSaving.current = isSaving;
	}, [ isSaving, call ] );

	// Poll while the background job is still working on this post.
	useEffect( () => {
		if ( status?.status !== 'stale' ) {
			return undefined;
		}

		const timer = window.setTimeout( call, POLL_MS );

		return () => window.clearTimeout( timer );
	}, [ status, answers, call ] );

	const update = useCallback(
		async ( data = {} ) => {
			setIsBusy( true );

			try {
				await call( { method: 'POST', data } );
			} finally {
				setIsBusy( false );
			}
		},
		[ call ]
	);

	return { status, isBusy, error, update };
}
