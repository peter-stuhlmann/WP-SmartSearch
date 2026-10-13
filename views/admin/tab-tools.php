<?php
/**
 * Tab "Tools": transfer settings, index problems, index tools, access and data.
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

$prstsearch_failed = $view->failed_posts();
$prstsearch_roles  = $view->roles();
?>
<section class="prstsearch-panel" aria-labelledby="prstsearch-transfer-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-transfer-title"><?php esc_html_e( 'Transfer settings', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'Take a proven configuration to another project.', 'smart-search' ); ?></p>

	<div class="prstsearch-rows">
		<div class="prstsearch-row">
			<div class="prstsearch-row__text">
				<p class="prstsearch-row__label"><?php esc_html_e( 'Export', 'smart-search' ); ?></p>
				<p class="prstsearch-row__description"><?php esc_html_e( 'Downloads all settings and the recommended results as a file. API keys are not included.', 'smart-search' ); ?></p>
			</div>
			<a class="prstsearch-button prstsearch-button--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . FormActions::EXPORT ), FormActions::EXPORT ) ); ?>">
				<?php esc_html_e( 'Export', 'smart-search' ); ?>
			</a>
		</div>

		<?php
		// An import can replace every setting, including those reserved for administrators.
		?>
		<?php if ( $data['is_admin'] ) : ?>
		<form class="prstsearch-row" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::IMPORT ); ?>" />
			<?php wp_nonce_field( FormActions::IMPORT ); ?>
			<div class="prstsearch-row__text">
				<label class="prstsearch-row__label" for="prstsearch-import-file"><?php esc_html_e( 'Import', 'smart-search' ); ?></label>
				<p class="prstsearch-row__description" id="prstsearch-import-description"><?php esc_html_e( 'Takes settings and recommended results from an export file. Existing values are overwritten.', 'smart-search' ); ?></p>
			</div>
			<div class="prstsearch-row__controls">
				<input type="file" id="prstsearch-import-file" name="prstsearch_file" accept="application/json,.json" required aria-describedby="prstsearch-import-description" />
				<button
					type="submit"
					class="prstsearch-button prstsearch-button--secondary"
					data-prstsearch-confirm="<?php esc_attr_e( 'All current settings are replaced by those from the file.', 'smart-search' ); ?>"
					data-prstsearch-confirm-title="<?php esc_attr_e( 'Import settings?', 'smart-search' ); ?>"
					data-prstsearch-confirm-label="<?php esc_attr_e( 'Import', 'smart-search' ); ?>"
				>
					<?php esc_html_e( 'Import', 'smart-search' ); ?>
				</button>
			</div>
		</form>
		<?php endif; ?>
	</div>
</section>

<section class="prstsearch-panel" aria-labelledby="prstsearch-problems-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-problems-title"><?php esc_html_e( 'Problems while indexing', 'smart-search' ); ?></h2>

	<?php if ( array() === $prstsearch_failed ) : ?>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'All content was read without problems.', 'smart-search' ); ?></p>
	<?php else : ?>
		<p class="prstsearch-panel__description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of items. */
					_n( '%s item could not be read.', '%s items could not be read.', $data['hero']['overview']['errors'], 'smart-search' ),
					number_format_i18n( $data['hero']['overview']['errors'] )
				)
			);
			?>
		</p>

		<ul class="prstsearch-rows">
			<?php foreach ( $prstsearch_failed as $prstsearch_post ) : ?>
				<li class="prstsearch-row">
					<div class="prstsearch-row__text">
						<p class="prstsearch-row__label"><?php echo esc_html( $prstsearch_post['title'] ); ?></p>
						<p class="prstsearch-row__description"><?php echo esc_html( $prstsearch_post['type'] . ' · ' . $prstsearch_post['detail'] ); ?></p>
					</div>
					<a class="prstsearch-button prstsearch-button--neutral" href="<?php echo esc_url( $prstsearch_post['edit_url'] ); ?>">
						<?php esc_html_e( 'Open', 'smart-search' ); ?>
						<span class="screen-reader-text">: <?php echo esc_html( $prstsearch_post['title'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="prstsearch-panel" aria-labelledby="prstsearch-index-tools-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-index-tools-title"><?php esc_html_e( 'Index', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'Normally the index looks after itself. These tools help after a migration or when something looks wrong.', 'smart-search' ); ?></p>

	<div class="prstsearch-rows">
		<div class="prstsearch-row" data-prstsearch-rebuild>
			<div class="prstsearch-row__text">
				<p class="prstsearch-row__label"><?php esc_html_e( 'Rebuild the index', 'smart-search' ); ?></p>
				<p class="prstsearch-row__description"><?php esc_html_e( 'Reads all content again. Text that did not change is not sent to the AI provider again.', 'smart-search' ); ?></p>
				<progress class="prstsearch-rebuild__progress" max="100" value="0" aria-label="<?php esc_attr_e( 'Progress of the index rebuild', 'smart-search' ); ?>" hidden data-prstsearch-rebuild-progress></progress>
				<p class="prstsearch-rebuild__status" data-prstsearch-rebuild-status></p>
			</div>
			<button type="button" class="prstsearch-button prstsearch-button--secondary" data-prstsearch-rebuild-start><?php esc_html_e( 'Rebuild index', 'smart-search' ); ?></button>
		</div>

		<div class="prstsearch-row">
			<div class="prstsearch-row__text">
				<p class="prstsearch-row__label"><?php esc_html_e( 'Empty the index', 'smart-search' ); ?></p>
				<p class="prstsearch-row__description"><?php esc_html_e( 'Removes everything from the index, including what the AI provider calculated. Until it is rebuilt, WordPress searches the old way.', 'smart-search' ); ?></p>
			</div>
			<button
				type="button"
				class="prstsearch-button prstsearch-button--danger-outline"
				data-prstsearch-clear-index
				data-prstsearch-confirm="<?php esc_attr_e( 'The index is emptied. Rebuilding it with semantic search costs tokens again.', 'smart-search' ); ?>"
				data-prstsearch-confirm-title="<?php esc_attr_e( 'Empty the index?', 'smart-search' ); ?>"
				data-prstsearch-confirm-label="<?php esc_attr_e( 'Empty index', 'smart-search' ); ?>"
			>
				<?php esc_html_e( 'Empty index', 'smart-search' ); ?>
			</button>
		</div>
	</div>
</section>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_TOOLS ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-access-data-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-access-data-title"><?php esc_html_e( 'Access and data', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Who may use the plugin and what happens when it is removed. Administrators always have full access.', 'smart-search' ); ?></p>

		<?php if ( ! $data['is_admin'] ) : ?>
			<div class="prstsearch-notice prstsearch-notice--info" role="note">
				<?php esc_html_e( 'Who may use the plugin can only be changed by administrators.', 'smart-search' ); ?>
			</div>
		<?php endif; ?>

		<div class="prstsearch-rows">
			<?php
			$fields->checkbox_row(
				'roles_stats',
				__( 'View statistics', 'smart-search' ),
				__( 'These roles see the tab “Statistics”.', 'smart-search' ),
				$prstsearch_roles,
				array( 'administrator' )
			);

			$fields->checkbox_row(
				'roles_manage',
				__( 'Change settings', 'smart-search' ),
				__( 'These roles may edit the settings. Provider, API key, import and this list of roles stay reserved for administrators.', 'smart-search' ),
				$prstsearch_roles,
				array( 'administrator' )
			);

			$fields->switch_row(
				'delete_data_on_uninstall',
				__( 'Remove data when the plugin is deleted', 'smart-search' ),
				__( 'Index, statistics and settings are deleted when the plugin is deleted. Deactivating keeps everything.', 'smart-search' )
			);
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
		</div>
	</section>
</form>
