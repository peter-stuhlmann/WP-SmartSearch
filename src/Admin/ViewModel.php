<?php
/**
 * Data for the settings page views.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Frontend\VisitorText;
use PrstSearch\Index\Eligibility;
use PrstSearch\Index\IndexVersion;
use PrstSearch\Index\IndexReport;
use PrstSearch\Index\IndexStatus;
use PrstSearch\Index\IndexStatusStore;
use PrstSearch\Provider\ProviderCatalog;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\UsageLedger;
use PrstSearch\Search\Recommendations;
use PrstSearch\Security\SecretStore;
use PrstSearch\Settings\Settings;

/**
 * Collects what the views show, so the view files contain markup only.
 */
final class ViewModel {

	private const LIST_LIMIT = 20;

	private const PRICE_KEYS = array( 'price_embedding', 'price_input', 'price_output' );

	/**
	 * Creates the view model.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly IndexReport $report,
		private readonly ChunkStore $chunks,
		private readonly IndexStatusStore $status,
		private readonly Eligibility $eligibility,
		private readonly ProviderConfig $config,
		private readonly SecretStore $secrets,
		private readonly UsageLedger $ledger,
		private readonly IndexVersion $version,
		private readonly Recommendations $recommendations,
		private readonly VisitorText $texts
	) {}

	/**
	 * Returns the rows of the list of recommended results: the stored rules and one empty row to add another.
	 *
	 * @param string $suggested_terms Search terms to start the empty row with, e.g. a search that found nothing.
	 * @return array<int, array{index: string, terms: string, page: string}>
	 */
	public function recommendation_rows( string $suggested_terms = '' ): array {
		$rows = array();

		foreach ( $this->recommendations->all() as $index => $rule ) {
			$rows[] = array(
				'index' => (string) $index,
				'terms' => implode( ', ', $rule['terms'] ),
				// The ID at the end is what is stored; the title is there for the person reading it.
				'page'  => trim( wp_strip_all_tags( get_the_title( $rule['post_id'] ) ) . ' #' . $rule['post_id'] ),
			);
		}

		$rows[] = array(
			'index' => 'new',
			'terms' => $suggested_terms,
			'page'  => '',
		);

		return $rows;
	}

	/**
	 * Returns the data of the page header.
	 *
	 * @return array{status: string, status_label: string, indexed: int, passages: int, updated: string, overview: array<string, mixed>}
	 */
	public function hero(): array {
		$overview = $this->report->overview();
		$changed  = $this->version->changed_at();

		list( $status, $label ) = match ( true ) {
			0 === $overview['indexed']           => array( IndexStatus::Excluded, __( 'Index is empty', 'smart-search' ) ),
			$overview['errors'] > 0              => array(
				IndexStatus::Error,
				/* translators: %s: number of items. */
				sprintf( _n( '%s item with an error', '%s items with errors', $overview['errors'], 'smart-search' ), number_format_i18n( $overview['errors'] ) ),
			),
			$overview['pending_embeddings'] > 0,
			$overview['indexed'] < $overview['searchable'] => array( IndexStatus::Stale, __( 'Index is being updated', 'smart-search' ) ),
			default                              => array( IndexStatus::Indexed, __( 'Index is up to date', 'smart-search' ) ),
		};

		return array(
			'status'       => $status->value,
			'status_label' => $label,
			'indexed'      => $overview['indexed'],
			'passages'     => $overview['passages'],
			'updated'      => $changed > 0
				/* translators: %s: human-readable time difference, e.g. "2 minutes". */
				? sprintf( __( '%s ago', 'smart-search' ), human_time_diff( $changed ) )
				: '–',
			'overview'     => $overview,
		);
	}

	/**
	 * Returns the data of the sidebar cards.
	 *
	 * @return array{types: array<int, array{label: string, count: int, share: float}>, total: int, provider: array<string, mixed>}
	 */
	public function sidebar(): array {
		$by_type = $this->chunks->posts_by_type();
		$total   = array_sum( $by_type );
		$types   = array();

		foreach ( $by_type as $post_type => $count ) {
			$object  = get_post_type_object( $post_type );
			$types[] = array(
				'label' => null === $object ? $post_type : $object->labels->name,
				'count' => $count,
				'share' => $total > 0 ? $count / $total : 0.0,
			);
		}

		$definition = $this->config->definition();
		$budget     = $this->settings->float( 'monthly_budget' );
		$cost       = $this->ledger->month_cost();

		return array(
			'types'    => $types,
			'total'    => $total,
			'provider' => array(
				'name'         => ProviderCatalog::CUSTOM === $definition->id ? __( 'Custom endpoint', 'smart-search' ) : $definition->name,
				'is_ready'     => $this->config->can_embed() || $this->config->can_chat(),
				'is_used'      => 'keyword' !== $this->settings->string( 'mode' ),
				'month_cost'   => $cost,
				'budget'       => $budget,
				'budget_share' => $budget > 0 ? min( 1.0, $cost / $budget ) : 0.0,
				'is_exhausted' => $this->ledger->is_budget_exhausted(),
			),
		);
	}

	/**
	 * Returns the cards for choosing the searchable content types.
	 *
	 * @return array<string, array{title: string, description: string, note: string, icon: string}>
	 */
	public function post_type_cards(): array {
		$indexed = $this->chunks->posts_by_type();
		$cards   = array();

		foreach ( $this->eligibility->available_post_types() as $post_type => $label ) {
			$published = (int) ( wp_count_posts( $post_type )->publish ?? 0 );
			$is_active = $this->eligibility->is_enabled_post_type( $post_type );

			$cards[ $post_type ] = array(
				'title'       => $label,
				'description' => sprintf(
					/* translators: 1: number of published items, 2: number of items in the index. */
					__( '%1$s published, %2$s in the index.', 'smart-search' ),
					number_format_i18n( $published ),
					number_format_i18n( $indexed[ $post_type ] ?? 0 )
				),
				'note'        => $is_active ? __( 'Is searched', 'smart-search' ) : __( 'Not searched', 'smart-search' ),
				'icon'        => Icons::for_post_type( $post_type ),
			);
		}

		return $cards;
	}

	/**
	 * Returns the content types that are searched right now, including automatically added ones.
	 *
	 * @return string[]
	 */
	public function enabled_post_types(): array {
		return $this->eligibility->enabled_post_types();
	}

	/**
	 * Returns the posts that were excluded from the search by hand.
	 *
	 * @return array<int, array{id: int, title: string, type: string, edit_url: string}>
	 */
	public function excluded_posts(): array {
		return $this->post_rows( $this->status->manually_excluded_post_ids( self::LIST_LIMIT ) );
	}

	/**
	 * Returns the posts that could not be indexed, with the reason.
	 *
	 * @return array<int, array{id: int, title: string, type: string, edit_url: string, detail: string}>
	 */
	public function failed_posts(): array {
		$rows = $this->post_rows( $this->status->error_post_ids( self::LIST_LIMIT ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['detail'] = $this->status->error_message( $row['id'] );
		}

		return $rows;
	}

	/**
	 * Returns the data of the provider form.
	 *
	 * @return array{cards: array<string, array{title: string, description: string, note: string, icon: string}>, defaults: array<string, array<string, mixed>>, key_masked: string, key_in_config: bool, key_unreadable: bool, price_values: array<string, string>}
	 */
	public function provider(): array {
		$texts = array(
			'openai'  => array( __( 'US provider with a large choice of models.', 'smart-search' ), 'cloud' ),
			'gemini'  => array( __( 'The models by Google.', 'smart-search' ), 'cloud' ),
			'mistral' => array( __( 'European provider with servers in the EU.', 'smart-search' ), 'shield' ),
			'custom'  => array( __( 'Any service that follows the OpenAI standard, such as Ollama on your own server.', 'smart-search' ), 'server' ),
		);

		$selected = $this->config->definition()->id;
		$cards    = array();
		$defaults = array();

		foreach ( ProviderCatalog::all() as $id => $definition ) {
			$is_selected = $id === $selected;

			$cards[ $id ] = array(
				'title'       => ProviderCatalog::CUSTOM === $id ? __( 'Custom endpoint', 'smart-search' ) : $definition->name,
				'description' => $texts[ $id ][0] ?? '',
				'note'        => match ( true ) {
					$is_selected && $this->config->can_embed() => __( 'Configured', 'smart-search' ),
					ProviderCatalog::CUSTOM === $id            => __( 'Address of your choice', 'smart-search' ),
					default                                    => __( 'Not configured', 'smart-search' ),
				},
				'icon'        => $texts[ $id ][1] ?? 'cloud',
			);

			$defaults[ $id ] = array(
				'embedding_model'  => $definition->embedding_model,
				'chat_model'       => $definition->chat_model,
				'embedding_models' => $definition->embedding_models,
				'chat_models'      => $definition->chat_models,
				'price_embedding'  => $definition->prices->embedding,
				'price_input'      => $definition->prices->input,
				'price_output'     => $definition->prices->output,
			);
		}

		return array(
			'cards'          => $cards,
			'defaults'       => $defaults,
			'key_masked'     => $this->secrets->masked(),
			'key_in_config'  => $this->secrets->is_defined_in_config(),
			'key_unreadable' => $this->secrets->is_unreadable(),
			'price_values'   => array_map(
				// A negative stored price means "use the list price", which the form shows as an empty field.
				fn ( string $key ): string => $this->settings->float( $key ) < 0 ? '' : (string) $this->settings->float( $key ),
				array_combine( self::PRICE_KEYS, self::PRICE_KEYS )
			),
		);
	}

	/**
	 * Returns all roles of the site. Administrators always have access; the view shows them as fixed.
	 *
	 * @return array<string, string> Role names keyed by slug.
	 */
	public function roles(): array {
		return array_map( 'translate_user_role', wp_roles()->get_names() );
	}

	/**
	 * Returns the standard message for too many searches, in the address the website uses.
	 */
	public function standard_limit_message(): string {
		return $this->texts->get( 'too_many' );
	}

	/**
	 * Returns what the tab "Security" reports about the API key and the connection to the provider.
	 *
	 * @return array<int, array{state: string, title: string, text: string}> State "ok", "info" or "warning".
	 */
	public function security_findings(): array {
		$findings = array();

		$findings[] = match ( $this->secrets->source() ) {
			'config'   => array(
				'state' => 'ok',
				'title' => __( 'The API key is defined in wp-config.php', 'smart-search' ),
				'text'  => __( 'It is not stored in the database, so a copy of the database does not contain it.', 'smart-search' ),
			),
			'database' => array(
				'state' => 'ok',
				'title' => __( 'The API key is stored encrypted', 'smart-search' ),
				'text'  => __( 'Encrypted with XSalsa20-Poly1305 under a key derived from the salts of WordPress. A copy of the database alone does not reveal it. Who can also read wp-config.php can decrypt it – that is unavoidable for a key the server has to send. Defining it as PRSTSEARCH_API_KEY in wp-config.php keeps it out of the database entirely.', 'smart-search' ),
			),
			default    => array(
				'state' => 'info',
				'title' => __( 'No API key is stored', 'smart-search' ),
				'text'  => __( 'The keyword search needs none. A key entered for the AI modes is stored encrypted.', 'smart-search' ),
			),
		};

		if ( 'database' === $this->secrets->source() ) {
			$findings[] = SecretStore::salts_are_in_config()
				? array(
					'state' => 'ok',
					'title' => __( 'The salts of WordPress are in wp-config.php', 'smart-search' ),
					'text'  => __( 'The key that encrypts the API key is derived from them and is not in the database.', 'smart-search' ),
				)
				: array(
					'state' => 'warning',
					'title' => __( 'The salts of WordPress are kept in the database', 'smart-search' ),
					'text'  => __( 'AUTH_KEY and AUTH_SALT are missing from wp-config.php, so WordPress generated them and stores them in the database – next to the encrypted API key. A copy of the database is then enough to decrypt it. Add the two lines to wp-config.php; the API key has to be entered again afterwards.', 'smart-search' ),
				);
		}

		$address = $this->config->base_url();
		$scheme  = (string) wp_parse_url( $address, PHP_URL_SCHEME );
		$host    = (string) wp_parse_url( $address, PHP_URL_HOST );

		if ( '' !== $address && 'https' === $scheme ) {
			$findings[] = array(
				'state' => 'ok',
				'title' => __( 'The provider is reached over HTTPS', 'smart-search' ),
				'text'  => __( 'API key, search queries and content travel encrypted. Redirects are not followed, so the key cannot be passed on to another server.', 'smart-search' ),
			);
		} elseif ( '' !== $address && in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			$findings[] = array(
				'state' => 'info',
				'title' => __( 'The provider runs on this server', 'smart-search' ),
				'text'  => __( 'The connection does not leave the server, so it needs no encryption.', 'smart-search' ),
			);
		} elseif ( '' !== $address ) {
			$findings[] = array(
				'state' => 'warning',
				'title' => __( 'The provider is reached without encryption', 'smart-search' ),
				'text'  => __( 'The address starts with http://. API key, search queries and content travel readable through the network. Use an https:// address if the provider offers one.', 'smart-search' ),
			);
		}

		return $findings;
	}

	/**
	 * Builds list rows for posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array{id: int, title: string, type: string, edit_url: string}>
	 */
	private function post_rows( array $post_ids ): array {
		$rows = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( null === $post ) {
				continue;
			}

			$type   = get_post_type_object( $post->post_type );
			$rows[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'type'     => null === $type ? $post->post_type : $type->labels->singular_name,
				'edit_url' => (string) get_edit_post_link( $post, 'raw' ),
			);
		}

		return $rows;
	}
}
