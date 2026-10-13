<?php
/**
 * Frame of the settings page: header, tabs, active tab.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\AdminPage $this
 * @var array{tabs: array<string, string>, current: string, hero: array<string, mixed>, notice: array{type: string, message: string}|null, tab_data: array<string, mixed>} $data
 */

use PrstSearch\Admin\AdminPage;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_hero        = $data['hero'];
$prstsearch_has_sidebar = ! in_array( $data['current'], array( SettingsSchema::TAB_STATS, SettingsSchema::TAB_TOOLS ), true );
?>
<div class="wrap prstsearch-admin">
	<hr class="wp-header-end" />

	<div class="prstsearch-hero">
		<div class="prstsearch-hero__intro">
			<p class="prstsearch-pill prstsearch-pill--<?php echo esc_attr( $prstsearch_hero['status'] ); ?>">
				<span class="prstsearch-pill__dot" aria-hidden="true"></span>
				<?php echo esc_html( $prstsearch_hero['status_label'] ); ?>
			</p>
			<h1 class="prstsearch-hero__title"><?php esc_html_e( 'Smart Search', 'smart-search' ); ?></h1>
			<p class="prstsearch-hero__tagline"><?php esc_html_e( 'The search of your website – by keyword, by meaning or with an AI answer.', 'smart-search' ); ?></p>
		</div>

		<dl class="prstsearch-hero__stats">
			<div class="prstsearch-hero__stat">
				<dt><?php esc_html_e( 'Items in the index', 'smart-search' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $prstsearch_hero['indexed'] ) ); ?></dd>
			</div>
			<div class="prstsearch-hero__stat">
				<dt><?php esc_html_e( 'Text passages', 'smart-search' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $prstsearch_hero['passages'] ) ); ?></dd>
			</div>
			<div class="prstsearch-hero__stat">
				<dt><?php esc_html_e( 'Last updated', 'smart-search' ); ?></dt>
				<dd><?php echo esc_html( $prstsearch_hero['updated'] ); ?></dd>
			</div>
		</dl>
	</div>

	<?php if ( null !== $data['notice'] ) : ?>
		<?php
		// A message that is on the page from the start is not read out by itself. The script announces it.
		?>
		<div class="prstsearch-notice prstsearch-notice--<?php echo esc_attr( $data['notice']['type'] ); ?>" data-prstsearch-notice="<?php echo esc_attr( $data['notice']['type'] ); ?>">
			<?php echo esc_html( $data['notice']['message'] ); ?>
		</div>
	<?php endif; ?>

	<nav class="prstsearch-tabs" aria-label="<?php esc_attr_e( 'Smart Search sections', 'smart-search' ); ?>">
		<ul class="prstsearch-tabs__list">
			<?php foreach ( $data['tabs'] as $prstsearch_slug => $prstsearch_label ) : ?>
				<li>
					<a
						class="prstsearch-tabs__link"
						href="<?php echo esc_url( AdminPage::url( $prstsearch_slug ) ); ?>"
						<?php echo $prstsearch_slug === $data['current'] ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( $prstsearch_label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="prstsearch-layout<?php echo $prstsearch_has_sidebar ? ' prstsearch-layout--with-sidebar' : ''; ?>">
		<?php
		// No main element: WordPress already wraps the admin screen in one.
		?>
		<div class="prstsearch-layout__main">
			<?php $this->include_view( 'tab-' . $data['current'], $data['tab_data'] ); ?>
		</div>

		<?php if ( $prstsearch_has_sidebar ) : ?>
			<aside class="prstsearch-layout__sidebar" aria-label="<?php esc_attr_e( 'Overview', 'smart-search' ); ?>">
				<?php $this->include_view( 'sidebar', array( 'hero' => $prstsearch_hero ) ); ?>
			</aside>
		<?php endif; ?>
	</div>

	<dialog class="prstsearch-dialog" aria-labelledby="prstsearch-dialog-title" aria-describedby="prstsearch-dialog-text" data-prstsearch-dialog>
		<form method="dialog" class="prstsearch-dialog__form">
			<h2 class="prstsearch-dialog__title" id="prstsearch-dialog-title"></h2>
			<p class="prstsearch-dialog__text" id="prstsearch-dialog-text"></p>
			<div class="prstsearch-dialog__actions">
				<button type="submit" class="prstsearch-button prstsearch-button--secondary" value="cancel"><?php esc_html_e( 'Cancel', 'smart-search' ); ?></button>
				<button type="submit" class="prstsearch-button prstsearch-button--danger" value="confirm" data-prstsearch-dialog-confirm></button>
			</div>
		</form>
	</dialog>
</div>
