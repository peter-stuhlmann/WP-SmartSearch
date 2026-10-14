<?php
/**
 * Protection of the search against misuse.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Security;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Provider\RequestThrottle;
use PrstSearch\Settings\Settings;
use WP_Query;

/**
 * Limits how often a visitor may search, and lays a trap for bots.
 *
 * A search costs the server far more than an ordinary page view, and in the
 * AI modes it costs money. A script that searches in a loop can slow a site
 * down or fill its statistics. The limit applies to every search on the
 * front end, through the search field of the theme as well as through the
 * plugin's block, and whether or not the plugin replaces the WordPress search.
 *
 * The trap is a field no person sees. Bots that fill in every field of a
 * form fill in this one too, and get an empty result for it.
 */
final class SearchGuard implements Hookable {

	/**
	 * Name of the hidden field.
	 */
	public const TRAP = 'prstsearch_hp';

	/**
	 * Seconds each choice of the setting "per" stands for.
	 */
	private const PERIODS = array(
		'minute' => MINUTE_IN_SECONDS,
		'hour'   => HOUR_IN_SECONDS,
		'day'    => DAY_IN_SECONDS,
	);

	/**
	 * Whether the main search of this request was refused.
	 */
	private bool $is_refused = false;

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly RequestThrottle $throttle
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Before the plugin answers the search itself, so a refused search is neither run nor logged.
		add_filter( 'posts_pre_query', array( $this, 'guard_main_search' ), 5, 2 );
		add_action( 'template_redirect', array( $this, 'refuse' ), 1 );

		add_filter( 'get_search_form', array( $this, 'with_trap' ) );
		add_filter( 'render_block_core/search', array( $this, 'with_trap' ) );
	}

	/**
	 * Stops the main search of a front-end request if the visitor fell into the trap or searches too often.
	 *
	 * @param mixed    $posts Posts set by another filter, or null.
	 * @param WP_Query $query The query.
	 * @return mixed An empty list to stop the search, otherwise what came in.
	 */
	public function guard_main_search( $posts, WP_Query $query ) {
		if ( null !== $posts || is_admin() || ! $query->is_main_query() || ! $query->is_search() || '' === trim( (string) $query->get( 's' ) ) ) {
			return $posts;
		}

		// A bot gets what an unsuccessful search looks like, and learns nothing.
		if ( $this->is_trap_filled( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public search form has no nonce.
			return array();
		}

		// Paging through the results of a search is not another search.
		if ( (int) $query->get( 'paged' ) > 1 || $this->allows_search() ) {
			return $posts;
		}

		$this->is_refused = true;

		return array();
	}

	/**
	 * Answers a refused search with "429 Too Many Requests" instead of the results page.
	 */
	public function refuse(): void {
		if ( ! $this->is_refused ) {
			return;
		}

		header( 'Retry-After: ' . $this->period() );

		wp_die(
			esc_html( self::message() ),
			esc_html__( 'Too many searches', 'smart-search' ),
			array(
				'response'  => 429,
				'back_link' => true,
			)
		);
	}

	/**
	 * Counts a search and tells whether the current visitor may start it.
	 *
	 * Those who may manage the plugin are never limited: they test it.
	 */
	public function allows_search(): bool {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return $this->throttle->allow_searches(
			$this->settings->int( 'search_limit' ),
			$this->period(),
			$this->settings->int( 'search_limit_site' )
		);
	}

	/**
	 * Tells whether the hidden field of a submitted form was filled in.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 */
	public function is_trap_filled( array $input ): bool {
		$value = $input[ self::TRAP ] ?? '';

		return $this->settings->bool( 'honeypot' ) && ( ! is_string( $value ) || '' !== trim( $value ) );
	}

	/**
	 * Adds the hidden field to a search form, if the trap is switched on.
	 *
	 * Works on any markup with a form in it: the form of the theme, the
	 * search block of WordPress, and the form of this plugin.
	 *
	 * @param mixed $html Markup of the form.
	 * @return mixed The markup with the field, or what came in.
	 */
	public function with_trap( $html ) {
		if ( ! is_string( $html ) || ! $this->settings->bool( 'honeypot' ) || str_contains( $html, self::TRAP ) ) {
			return $html;
		}

		$end = strripos( $html, '</form>' );

		return false === $end ? $html : substr( $html, 0, $end ) . self::trap() . substr( $html, $end );
	}

	/**
	 * Returns the message for a visitor who searched too often.
	 */
	public static function message(): string {
		return __( 'You have searched very often in a short time. Please wait a moment and try again.', 'smart-search' );
	}

	/**
	 * Returns the length of the period the limit counts in, in seconds.
	 */
	private function period(): int {
		return self::PERIODS[ $this->settings->string( 'search_limit_window' ) ] ?? MINUTE_IN_SECONDS;
	}

	/**
	 * Returns the markup of the hidden field.
	 *
	 * It is moved out of sight rather than hidden with "display: none", which
	 * some bots recognise. It cannot be reached with the keyboard, and screen
	 * readers skip it. Its label is what a bot reads: a field it wants to fill.
	 * The small script keeps the empty field out of the address of the results.
	 */
	private static function trap(): string {
		return sprintf(
			'<div aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
			. '<label>%1$s <input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off" /></label>'
			. '</div>'
			. '<script>(function(f){if(f){f.addEventListener("submit",function(){var i=f.querySelector("[name=%2$s]");if(i&&!i.value){i.disabled=true;}});}})(document.currentScript&&document.currentScript.closest("form"));</script>',
			esc_html__( 'Website', 'smart-search' ),
			esc_attr( self::TRAP )
		);
	}
}
