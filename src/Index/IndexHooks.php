<?php
/**
 * Keeps the index current when content or settings change.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Admin\AdminPage;
use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Security\SecretStore;
use PrstSearch\Settings\Settings;
use PrstSearch\Settings\SettingsSchema;
use WP_Comment;
use WP_Post;

/**
 * Connects WordPress events to the indexer.
 */
final class IndexHooks implements Hookable {

	/**
	 * Settings that decide which content types are searched.
	 */
	private const POST_TYPE_SETTINGS = array( 'post_types', 'auto_post_types', 'known_post_types' );

	/**
	 * Settings that decide whether and with which model embeddings are created.
	 */
	private const EMBEDDING_SETTINGS = array( 'mode', 'provider', 'base_url', 'embedding_model' );

	/**
	 * Settings that change the text stored for a post.
	 */
	private const CONTENT_SETTINGS = array( 'index_terms', 'index_excerpt', 'index_comments', 'custom_fields', 'fetch_fallback' );

	/**
	 * How many failed posts are looked at when errors may have become obsolete.
	 */
	private const ERROR_REVIEW_LIMIT = 1000;

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly Indexer $indexer,
		private readonly ChunkStore $chunks,
		private readonly IndexStatusStore $status,
		private readonly Eligibility $eligibility,
		private readonly ProviderConfig $config,
		private readonly Settings $settings,
		private readonly Scheduler $scheduler
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Fires after meta and terms are saved, for the block editor, classic editor and imports alike.
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 2 );
		add_action( 'before_delete_post', array( $this->indexer, 'remove_post' ) );

		// A comment does not save its post, so comment changes are followed separately.
		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_edited' ), 10, 1 );
		add_action( 'deleted_comment', array( $this, 'on_comment_deleted' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status_changed' ), 10, 3 );

		// Opening the settings page catches up on content types that were registered since.
		add_action( 'load-settings_page_' . AdminPage::SLUG, array( $this->scheduler, 'backfill' ) );

		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );

		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . SecretStore::OPTION, array( $this, 'reconcile_embeddings' ), 10, 0 );
		}
	}

	/**
	 * Indexes a post after it was saved.
	 */
	public function on_post_saved( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		// A post that is in the index must be looked at even if its type is no longer searched:
		// it may just have been moved to another content type and has to leave the index.
		if ( ! $this->eligibility->is_enabled_post_type( $post->post_type ) && ! $this->status->has_state( $post_id ) ) {
			return;
		}

		$this->indexer->index_post( $post_id );
	}

	/**
	 * Indexes the post of a new comment, if the comment is visible right away.
	 *
	 * @param int|string $comment_id Comment ID.
	 * @param WP_Comment $comment    The comment.
	 */
	public function on_comment_inserted( $comment_id, WP_Comment $comment ): void {
		if ( self::is_approved( $comment ) ) {
			$this->index_post_of( $comment );
		}
	}

	/**
	 * Indexes the post of an edited comment, if the comment is visible.
	 *
	 * @param int|string $comment_id Comment ID.
	 */
	public function on_comment_edited( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );

		if ( $comment instanceof WP_Comment && self::is_approved( $comment ) ) {
			$this->index_post_of( $comment );
		}
	}

	/**
	 * Indexes the post of a deleted comment, if the comment was visible.
	 *
	 * @param int|string $comment_id Comment ID.
	 * @param WP_Comment $comment    The deleted comment.
	 */
	public function on_comment_deleted( $comment_id, WP_Comment $comment ): void {
		if ( self::is_approved( $comment ) ) {
			$this->index_post_of( $comment );
		}
	}

	/**
	 * Indexes the post of a comment that became visible or stopped being visible.
	 *
	 * Pending and spam comments are not part of the index. Reacting to them
	 * would let anyone who can submit a comment trigger work on the server
	 * and throw away every cached answer.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Previous status.
	 * @param WP_Comment $comment    The comment.
	 */
	public function on_comment_status_changed( string $new_status, string $old_status, WP_Comment $comment ): void {
		if ( $new_status !== $old_status && in_array( 'approved', array( $new_status, $old_status ), true ) ) {
			$this->index_post_of( $comment );
		}
	}

	/**
	 * Handles the first save of the settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings.
	 */
	public function on_settings_added( string $option, mixed $value ): void {
		// Until now the defaults were in effect, so that is what the new values are compared with.
		$this->on_settings_updated( SettingsSchema::defaults(), $value );
	}

	/**
	 * Adjusts the index to changed settings.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 */
	public function on_settings_updated( mixed $old_value, mixed $new_value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		$this->settings->forget();

		if ( self::differs( $old_value, $new_value, self::POST_TYPE_SETTINGS ) ) {
			$post_types = $this->eligibility->enabled_post_types();

			// Text and status go together: a type that comes back later must count as never indexed.
			$this->chunks->delete_other_post_types( $post_types );
			$this->status->clear_other_post_types( $post_types );
			$this->restart_backfill();
		}

		if ( self::differs( $old_value, $new_value, self::CONTENT_SETTINGS ) ) {
			// Every post has to be read again; until then it counts as outdated.
			$this->status->clear_all();
			$this->restart_backfill();
		}

		if ( self::differs( $old_value, $new_value, self::EMBEDDING_SETTINGS ) ) {
			$this->reconcile_embeddings();
		}
	}

	/**
	 * Brings the status of all posts in line with the embeddings they need now.
	 */
	public function reconcile_embeddings(): void {
		if ( ! $this->config->embeddings_enabled() ) {
			$this->status->mark_all_indexed();
			$this->forgive_embedding_errors();
			return;
		}

		$post_ids = $this->chunks->posts_missing_embeddings( $this->config->embedding_model() );

		foreach ( $post_ids as $post_id ) {
			$this->status->mark_pending( $post_id );
		}

		if ( array() !== $post_ids ) {
			$this->scheduler->embeddings();
		}
	}

	/**
	 * Clears errors that only concerned embeddings, now that none are needed.
	 *
	 * A post whose text is in the index is completely searchable by keyword.
	 * Posts that failed to render have no text and keep their error.
	 */
	private function forgive_embedding_errors(): void {
		foreach ( $this->status->error_post_ids( self::ERROR_REVIEW_LIMIT ) as $post_id ) {
			if ( $this->chunks->has_chunks( $post_id ) ) {
				$this->status->mark_indexed( $post_id );
			}
		}
	}

	/**
	 * Starts the walk through all posts from the beginning.
	 */
	private function restart_backfill(): void {
		delete_option( BackgroundJobs::BACKFILL_CURSOR_OPTION );
		$this->scheduler->backfill();
	}

	/**
	 * Indexes the post a comment belongs to, if comments are searched.
	 */
	private function index_post_of( WP_Comment $comment ): void {
		if ( ! $this->settings->bool( 'index_comments' ) ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( $post instanceof WP_Post && $this->eligibility->is_enabled_post_type( $post->post_type ) ) {
			$this->indexer->index_post( $post->ID );
		}
	}

	/**
	 * Tells whether a comment is publicly visible.
	 */
	private static function is_approved( WP_Comment $comment ): bool {
		return '1' === (string) $comment->comment_approved;
	}

	/**
	 * Tells whether any of the given keys has a different value in the two arrays.
	 *
	 * @param array<string, mixed> $old_value Previous settings.
	 * @param array<string, mixed> $new_value New settings.
	 * @param string[]             $keys      Keys to compare.
	 */
	private static function differs( array $old_value, array $new_value, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ( $old_value[ $key ] ?? null ) !== ( $new_value[ $key ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}
}
