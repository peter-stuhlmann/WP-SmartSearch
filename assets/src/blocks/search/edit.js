/**
 * Editor view of the search block.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Shows a non-interactive preview of the form and the block's settings.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Updates block attributes.
 * @return {JSX.Element} The editor view.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { label, placeholder, buttonLabel, showResults, showAnswer } =
		attributes;
	const defaultLabel = __( 'Search', 'smart-search' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Texts', 'smart-search' ) }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Label', 'smart-search' ) }
						help={ __(
							'Names the field for all visitors, including screen reader users.',
							'smart-search'
						) }
						value={ label }
						placeholder={ defaultLabel }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Placeholder', 'smart-search' ) }
						value={ placeholder }
						onChange={ ( value ) =>
							setAttributes( { placeholder: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Button text', 'smart-search' ) }
						value={ buttonLabel }
						placeholder={ defaultLabel }
						onChange={ ( value ) =>
							setAttributes( { buttonLabel: value } )
						}
					/>
				</PanelBody>

				<PanelBody title={ __( 'Results', 'smart-search' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show results on this page',
							'smart-search'
						) }
						help={
							showResults
								? __(
										'Results appear below the field without leaving the page.',
										'smart-search'
								  )
								: __(
										'The search opens the results page of the theme.',
										'smart-search'
								  )
						}
						checked={ showResults }
						onChange={ ( value ) =>
							setAttributes( { showResults: value } )
						}
					/>
					{ showResults && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show the AI answer', 'smart-search' ) }
							help={ __(
								'Only appears if the search mode “AI answer” is active.',
								'smart-search'
							) }
							checked={ showAnswer }
							onChange={ ( value ) =>
								setAttributes( { showAnswer: value } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...useBlockProps() }>
				{ /* inert: the preview looks like the form but cannot be focused or used in the editor. */ }
				<div className="prstsearch-form" inert="">
					<span className="prstsearch-form__label">
						{ label || defaultLabel }
					</span>
					<div className="prstsearch-form__row">
						<div className="prstsearch-form__field">
							<input
								className="prstsearch-form__input"
								type="search"
								placeholder={ placeholder }
								readOnly
							/>
						</div>
						<button
							type="button"
							className="prstsearch-form__submit wp-element-button"
						>
							{ buttonLabel || defaultLabel }
						</button>
					</div>
				</div>
			</div>
		</>
	);
}
