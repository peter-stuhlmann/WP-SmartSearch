<?php
/**
 * Tab "AI provider": provider, credentials, models, costs.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\ViewModel $view
 * @var \PrstSearch\Admin\Fields    $fields
 * @var \PrstSearch\Admin\Format    $format
 * @var array{is_admin: bool}      $data
 */

use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_provider = $view->provider();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off" data-prstsearch-provider-form>
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_PROVIDER ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-provider-choice-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-provider-choice-title"><?php esc_html_e( 'Provider', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'The provider handles semantic search and AI answers. After a change the index is recalculated.', 'smart-search' ); ?></p>

		<?php if ( ! $data['is_admin'] ) : ?>
			<div class="prstsearch-notice prstsearch-notice--info" role="note">
				<?php esc_html_e( 'Provider, endpoint address and API key can only be changed by administrators. Your other changes on this page are saved.', 'smart-search' ); ?>
			</div>
		<?php endif; ?>

		<?php $fields->cards( 'provider', __( 'Provider', 'smart-search' ), $prstsearch_provider['cards'] ); ?>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-access-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-access-title"><?php esc_html_e( 'Access and costs', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Credentials of the chosen provider and protection against unexpected costs.', 'smart-search' ); ?></p>

		<?php if ( $prstsearch_provider['key_unreadable'] ) : ?>
			<div class="prstsearch-notice prstsearch-notice--error" role="alert">
				<?php esc_html_e( 'The stored API key can no longer be decrypted, probably because the security keys of the website changed. Please enter it again.', 'smart-search' ); ?>
			</div>
		<?php endif; ?>

		<div class="prstsearch-rows">
			<?php
			$fields->input_row(
				'base_url',
				__( 'Address of the endpoint', 'smart-search' ),
				__( 'Root of the OpenAI-compatible API, for example http://localhost:11434/v1 for Ollama.', 'smart-search' ),
				array(
					'type'         => 'url',
					'placeholder'  => 'https://example.com/v1',
					'row_class'    => 'prstsearch-row--custom-only',
					// Browsers put the user name into the field before a password field.
					'autocomplete' => 'off',
				)
			);
			?>

			<div class="prstsearch-row">
				<div class="prstsearch-row__text">
					<label class="prstsearch-row__label" for="prstsearch-api-key"><?php esc_html_e( 'API key', 'smart-search' ); ?></label>
					<p class="prstsearch-row__description" id="prstsearch-api-key-description">
						<?php
						if ( $prstsearch_provider['key_in_config'] ) {
							esc_html_e( 'Set as PRSTSEARCH_API_KEY in wp-config.php. It cannot be changed here.', 'smart-search' );
						} elseif ( '' !== $prstsearch_provider['key_masked'] ) {
							/* translators: %s: masked API key. */
							echo esc_html( sprintf( __( 'Stored encrypted: %s. Leave the field empty to keep it.', 'smart-search' ), $prstsearch_provider['key_masked'] ) );
						} else {
							esc_html_e( 'Stored encrypted. Alternatively set PRSTSEARCH_API_KEY in wp-config.php.', 'smart-search' );
						}
						?>
					</p>
					<?php if ( '' !== $prstsearch_provider['key_masked'] && ! $prstsearch_provider['key_in_config'] ) : ?>
						<label class="prstsearch-check">
							<input type="checkbox" name="prstsearch_remove_api_key" value="1" />
							<?php esc_html_e( 'Remove the stored key', 'smart-search' ); ?>
						</label>
					<?php endif; ?>
				</div>
				<input
					class="prstsearch-input"
					type="password"
					id="prstsearch-api-key"
					name="prstsearch_api_key"
					autocomplete="new-password"
					spellcheck="false"
					aria-describedby="prstsearch-api-key-description"
					<?php disabled( $prstsearch_provider['key_in_config'] ); ?>
				/>
			</div>

			<?php
			$fields->input_row(
				'embedding_model',
				__( 'Model for semantic search', 'smart-search' ),
				__( 'Turns content and queries into vectors. A change recalculates the index.', 'smart-search' ),
				array(
					'data-prstsearch-default' => 'embedding_model',
					'data-prstsearch-models'  => 'embedding_models',
				)
			);

			$fields->input_row(
				'chat_model',
				__( 'Model for the AI answer', 'smart-search' ),
				__( 'Writes the answer from the results. Can be changed at any time.', 'smart-search' ),
				array(
					'data-prstsearch-default' => 'chat_model',
					'data-prstsearch-models'  => 'chat_models',
				)
			);

			$fields->input_row(
				'monthly_budget',
				__( 'Monthly cost limit', 'smart-search' ),
				__( 'When the limit is reached, the search continues as a keyword search. 0 means no limit.', 'smart-search' ),
				array(
					'type'      => 'number',
					'min'       => 0,
					'step'      => '0.01',
					'row_class' => 'prstsearch-row--narrow-input',
				)
			);

			$fields->switch_row(
				'cache_enabled',
				__( 'Cache queries', 'smart-search' ),
				__( 'A repeated query is not sent to the provider again: its search for an hour, its AI answer for a day. That saves costs and time.', 'smart-search' )
			);
			?>

			<?php
			$fields->textarea_row(
				'answer_instructions',
				__( 'Additional instructions for the AI answer', 'smart-search' ),
				__( 'Optional, for example the tone of voice: “Address visitors informally.” The AI always answers from your content only.', 'smart-search' )
			);
			?>
		</div>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-prices-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-prices-title"><?php esc_html_e( 'Prices for the cost estimate', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description">
			<?php echo esc_html( $format->price_note() ); ?>
			<?php esc_html_e( 'The list prices belong to the recommended models. If you choose another model, enter its prices here.', 'smart-search' ); ?>
		</p>

		<div class="prstsearch-rows">
			<?php
			$fields->select_row(
				'currency',
				__( 'Currency', 'smart-search' ),
				__( 'The preset prices are the providers’ list prices in US dollars. If you choose euros, please enter your own prices.', 'smart-search' ),
				array(
					'USD' => __( 'US dollar ($)', 'smart-search' ),
					'EUR' => __( 'Euro (€)', 'smart-search' ),
				)
			);

			$prstsearch_price_rows = array(
				'price_embedding' => __( 'Embeddings, per 1 million tokens', 'smart-search' ),
				'price_input'     => __( 'Input, per 1 million tokens', 'smart-search' ),
				'price_output'    => __( 'Output, per 1 million tokens', 'smart-search' ),
			);

			foreach ( $prstsearch_price_rows as $prstsearch_key => $prstsearch_label ) {
				$fields->input_row(
					$prstsearch_key,
					$prstsearch_label,
					__( 'Leave empty to use the provider’s list price.', 'smart-search' ),
					array(
						'type'                    => 'number',
						'min'                     => 0,
						'step'                    => '0.0001',
						'value'                   => $prstsearch_provider['price_values'][ $prstsearch_key ],
						'row_class'               => 'prstsearch-row--narrow-input',
						'data-prstsearch-default' => $prstsearch_key,
					)
				);
			}
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
			<button type="button" class="prstsearch-button prstsearch-button--secondary" data-prstsearch-provider-test><?php esc_html_e( 'Test connection', 'smart-search' ); ?></button>
			<p class="prstsearch-actions__note" role="status" data-prstsearch-provider-status><?php esc_html_e( 'The test uses the saved settings.', 'smart-search' ); ?></p>
		</div>
	</section>
</form>
