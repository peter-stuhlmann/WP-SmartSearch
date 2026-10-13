<?php
/**
 * Links that limit the results to one content type.
 *
 * Override by copying this file to smart-search/type-filter.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{types: array<int, array{type: string, label: string, count: int, url: string, is_current: bool}>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<nav class="prstsearch-types" aria-label="<?php esc_attr_e( 'Limit the results to a content type', 'smart-search' ); ?>">
	<ul class="prstsearch-types__list">
		<?php foreach ( $args['types'] as $prstsearch_type ) : ?>
			<li>
				<a
					class="prstsearch-types__link"
					href="<?php echo esc_url( $prstsearch_type['url'] ); ?>"
					data-prstsearch-type="<?php echo esc_attr( $prstsearch_type['type'] ); ?>"
					<?php echo $prstsearch_type['is_current'] ? 'aria-current="true"' : ''; ?>
				>
					<?php echo esc_html( $prstsearch_type['label'] ); ?>
					<span class="prstsearch-types__count"><?php echo esc_html( number_format_i18n( $prstsearch_type['count'] ) ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
