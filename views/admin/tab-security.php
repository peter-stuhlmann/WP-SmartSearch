<?php
/**
 * Tab "Security": limits for searches, the trap for bots, and where the secrets are kept.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\ViewModel $view
 * @var \PrstSearch\Admin\Fields    $fields
 * @var array{hero: array<string, mixed>, is_admin: bool} $data
 */

use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_findings = $view->security_findings();
$prstsearch_states   = array(
	'ok'      => __( 'In order', 'smart-search' ),
	'info'    => __( 'Note', 'smart-search' ),
	'warning' => __( 'Recommendation', 'smart-search' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_SECURITY ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-limits-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-limits-title"><?php esc_html_e( 'Limit searches', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'A search costs the server more than an ordinary page, and in the AI modes it costs money. These limits stop scripts that search in a loop. They apply to every search on the website, also through the search field of your theme. Whoever may manage the plugin is never limited.', 'smart-search' ); ?></p>

		<div class="prstsearch-rows">
			<?php
			$fields->input_row(
				'search_limit',
				__( 'Searches per visitor', 'smart-search' ),
				__( 'How many searches one visitor may start in the period below. Paging through the results does not count. 0 means no limit.', 'smart-search' ),
				array(
					'type'      => 'number',
					'min'       => 0,
					'max'       => 100000,
					'step'      => 1,
					'row_class' => 'prstsearch-row--narrow-input',
				)
			);

			$fields->select_row(
				'search_limit_window',
				__( 'Period', 'smart-search' ),
				__( 'The period the searches per visitor are counted in. After it, the count starts again.', 'smart-search' ),
				array(
					'minute' => __( 'per minute', 'smart-search' ),
					'hour'   => __( 'per hour', 'smart-search' ),
					'day'    => __( 'per day', 'smart-search' ),
				)
			);

			$fields->input_row(
				'search_limit_site',
				__( 'Searches of the whole website per minute', 'smart-search' ),
				__( 'An upper limit for all visitors together, against many scripts at once. Set it well above what your website sees on its busiest days. 0 means no limit.', 'smart-search' ),
				array(
					'type'      => 'number',
					'min'       => 0,
					'max'       => 1000000,
					'step'      => 1,
					'row_class' => 'prstsearch-row--narrow-input',
				)
			);
			?>
		</div>

		<p class="prstsearch-panel__description prstsearch-panel__description--after"><?php esc_html_e( 'Whoever exceeds a limit gets the message “You have searched very often in a short time. Please wait a moment and try again.” Visitors are told apart without storing anything that identifies them: the IP address is only used to form one of about a million group numbers, which changes with every period.', 'smart-search' ); ?></p>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-bots-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-bots-title"><?php esc_html_e( 'Keep bots out', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'A trap for programs that fill in forms automatically.', 'smart-search' ); ?></p>

		<div class="prstsearch-rows">
			<?php
			$fields->switch_row(
				'honeypot',
				__( 'Hidden trap field in search forms', 'smart-search' ),
				__( 'Adds a field no person sees to every search form – the one of your theme, the search block of WordPress and the one of this plugin. A bot that fills it in gets an empty result and is not recorded in the statistics. It does not help against scripts that call the search address directly; the limits above are for those.', 'smart-search' )
			);
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
		</div>
	</section>
</form>

<section class="prstsearch-panel" aria-labelledby="prstsearch-secrets-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-secrets-title"><?php esc_html_e( 'Key and connection', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'Where the API key of your AI provider is kept and how it travels. Checked each time this page opens.', 'smart-search' ); ?></p>

	<ul class="prstsearch-findings">
		<?php foreach ( $prstsearch_findings as $prstsearch_finding ) : ?>
			<li class="prstsearch-finding prstsearch-finding--<?php echo esc_attr( $prstsearch_finding['state'] ); ?>">
				<span class="prstsearch-finding__state"><?php echo esc_html( $prstsearch_states[ $prstsearch_finding['state'] ] ); ?></span>
				<div class="prstsearch-finding__text">
					<p class="prstsearch-finding__title"><?php echo esc_html( $prstsearch_finding['title'] ); ?></p>
					<p class="prstsearch-finding__description"><?php echo esc_html( $prstsearch_finding['text'] ); ?></p>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
