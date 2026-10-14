<?php
/**
 * Content of a generated AI answer.
 *
 * Override by copying this file to smart-search/answer.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{paragraphs: string[], sources: array<int, array{title: string, url: string}>, show_sources: bool, note: string} $args
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_allowed = array(
	'a' => array(
		'class'      => array(),
		'href'       => array(),
		'aria-label' => array(),
	),
);
?>
<div class="prstsearch-answer__text">
	<?php foreach ( $args['paragraphs'] as $prstsearch_paragraph ) : ?>
		<p><?php echo wp_kses( $prstsearch_paragraph, $prstsearch_allowed ); ?></p>
	<?php endforeach; ?>
</div>

<?php if ( $args['show_sources'] && array() !== $args['sources'] ) : ?>
	<div class="prstsearch-answer__sources">
		<h3 class="prstsearch-answer__sources-title"><?php esc_html_e( 'Sources', 'smart-search' ); ?></h3>
		<ol class="prstsearch-answer__source-list">
			<?php foreach ( $args['sources'] as $prstsearch_source ) : ?>
				<li><a href="<?php echo esc_url( $prstsearch_source['url'] ); ?>"><?php echo esc_html( $prstsearch_source['title'] ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</div>
<?php endif; ?>

<p class="prstsearch-answer__note"><?php echo esc_html( $args['note'] ); ?></p>
