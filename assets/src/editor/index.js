/**
 * Shows the index status in the block editor.
 *
 * The status sits in the editor header next to the publish button, where it
 * is visible without opening anything. Selecting it opens a sidebar with the
 * details and the actions.
 */
import './editor.scss';

import {
	Button,
	Notice,
	PanelBody,
	ToggleControl,
} from '@wordpress/components';
import { PluginSidebar } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import { useIndexStatus } from './use-index-status';

const SIDEBAR_NAME = 'prstsearch-index-status';

/**
 * The dot-and-label shown in the editor header.
 *
 * @param {Object}  props
 * @param {?Object} props.status Status from the REST API, null while loading.
 * @return {JSX.Element} The pill.
 */
function StatusPill( { status } ) {
	return (
		<span
			className={ `prstsearch-editor-pill prstsearch-editor-pill--${
				status?.status ?? 'excluded'
			}` }
		>
			<span className="prstsearch-editor-pill__dot" aria-hidden="true" />
			{ status?.label ?? __( 'Search', 'smart-search' ) }
		</span>
	);
}

/**
 * Sidebar with the explanation of the status and the actions.
 *
 * @return {JSX.Element} The sidebar.
 */
function IndexStatusSidebar() {
	const { status, isBusy, error, update } = useIndexStatus();

	const title = status
		? sprintf(
				/* translators: %s: index status, e.g. "Indexed". */
				__( 'Search: %s', 'smart-search' ),
				status.label
		  )
		: __( 'Search', 'smart-search' );

	// Content that is excluded for another reason, e.g. a draft, cannot be included from here.
	const canToggleExclusion =
		status && ( status.status !== 'excluded' || status.excluded_manually );

	return (
		<PluginSidebar
			name={ SIDEBAR_NAME }
			title={ title }
			icon={ <StatusPill status={ status } /> }
		>
			<PanelBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ status && (
					<div className="prstsearch-editor-panel">
						<StatusPill status={ status } />
						<p className="prstsearch-editor-panel__description">
							{ status.description }
						</p>
						{ status.detail && (
							<p className="prstsearch-editor-panel__detail">
								{ status.detail }
							</p>
						) }
						{ status.indexed_at && (
							<p className="prstsearch-editor-panel__detail">
								{ status.indexed_at }
							</p>
						) }

						<div className="prstsearch-editor-panel__actions">
							{ canToggleExclusion && (
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Exclude from the search',
										'smart-search'
									) }
									help={ __(
										'Applies immediately, without saving.',
										'smart-search'
									) }
									checked={ status.excluded_manually }
									disabled={ isBusy }
									onChange={ ( exclude ) =>
										update( { exclude } )
									}
								/>
							) }

							{ status.status !== 'excluded' && (
								<Button
									variant="secondary"
									isBusy={ isBusy }
									aria-disabled={ isBusy }
									onClick={ () => ! isBusy && update() }
								>
									{ __( 'Update now', 'smart-search' ) }
								</Button>
							) }
						</div>
					</div>
				) }
			</PanelBody>
		</PluginSidebar>
	);
}

registerPlugin( SIDEBAR_NAME, { render: IndexStatusSidebar } );
