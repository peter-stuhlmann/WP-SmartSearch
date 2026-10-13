<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Filters;
use PrstSearch\Search\Relevance;
use PrstSearch\Search\SearchHit;
use PrstSearch\Search\SearchMode;
use PrstSearch\Search\SearchResults;
use PrstSearch\Search\SearchService;

/**
 * The search from query to result list, with its safety nets.
 */
final class SearchServiceTest extends SearchTestCase {

	/**
	 * Embeddings in a space of two meanings: food and law.
	 */
	private const ABOUT_FOOD = array( 1.0, 0.0 );
	private const ABOUT_LAW  = array( 0.0, 1.0 );

	public function test_pages_are_found_by_their_words(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Imprint', 'Who runs this website.' );

		$results = $this->service()->search( 'shipping' );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( SearchMode::Keyword, $results->ranking->mode );
		self::assertFalse( $results->ranking->degraded, 'Keyword search was asked for, so nothing is missing.' );
		self::assertTrue( $results->hits[0]->in_title );
	}

	public function test_an_empty_query_finds_nothing_and_is_not_logged(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$results = $this->service()->search( "  \t " );

		self::assertSame( 0, $results->total );
		self::assertSame( '', $results->log_token );
		self::assertSame( array(), $this->log->entries );
	}

	public function test_a_misspelled_word_is_searched_again_in_the_spelling_of_the_website(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$results = $this->service()->search( 'shpping' );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( 'shipping', $results->ranking->corrected_query );
	}

	public function test_a_word_the_website_contains_is_never_corrected(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Shopping', 'Everything about the shop.' );

		$results = $this->service()->search( 'shopping' );

		self::assertSame( array( 20 ), $results->post_ids() );
		self::assertSame( '', $results->ranking->corrected_query );
	}

	public function test_a_correction_that_finds_nothing_either_is_not_reported(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$results = $this->service()->search( 'zzzzzzzz' );

		self::assertSame( 0, $results->total );
		self::assertSame( '', $results->ranking->corrected_query );
	}

	public function test_posts_that_are_no_longer_public_are_not_found_although_they_are_still_indexed(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Shipping draft', 'Not ready yet.', null, array( 'post_status' => 'draft' ) );
		$this->add_page( 30, 'Shipping for members', 'Secret.', null, array( 'post_password' => 'secret' ) );
		$this->add_page( 40, 'Shipping form', 'A content type that is not searched.', null, array( 'post_type' => 'form' ) );
		$this->add_page( 50, 'Shipping, deleted', 'Gone.' );
		$this->delete_page( 50 );

		self::assertSame( array( 10 ), $this->service()->search( 'shipping' )->post_ids() );
	}

	public function test_other_code_can_remove_hits(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Shipping abroad', 'Customs and duties.' );

		Filters\expectApplied( 'prstsearch_hits' )->andReturnUsing(
			static fn ( array $hits ): array => array_filter( $hits, static fn ( SearchHit $hit ): bool => 20 !== $hit->post_id )
		);

		self::assertSame( array( 10 ), $this->service()->search( 'shipping' )->post_ids() );
	}

	public function test_results_can_be_limited_to_content_types(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Shipping news', 'A blog post.', null, array( 'post_type' => 'post' ) );

		$results = $this->service()->search( 'shipping', 1, null, array( 'post' ) );

		self::assertSame( array( 20 ), $results->post_ids() );
		self::assertSame( 1, $results->total );
	}

	public function test_results_are_cut_into_pages(): void {
		foreach ( range( 1, 5 ) as $number ) {
			$this->add_page( $number, 'Shipping ' . $number, 'Text.' );
		}

		$service = $this->service();
		$first   = $service->search( 'shipping', 1, 2 );
		$last    = $service->search( 'shipping', 3, 2 );

		self::assertSame( 5, $first->total );
		self::assertSame( 3, $first->page_count() );
		self::assertCount( 2, $first->hits );
		self::assertCount( 1, $last->hits );
		self::assertSame( array(), array_intersect( $first->post_ids(), $last->post_ids() ) );
	}

	public function test_a_search_is_logged_once_however_often_and_far_it_is_shown(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$service = $this->service();
		$first   = $service->search( 'shipping' );
		$again   = $service->search( '  shipping ' );
		$paged   = $service->search( 'shipping', 2 );

		self::assertCount( 1, $this->log->entries );
		self::assertNotSame( '', $first->log_token );
		self::assertSame( $first->log_token, $again->log_token );
		self::assertSame( $first->log_token, $paged->log_token, 'Clicks on a later page belong to the same search.' );
		self::assertSame( 'shipping', $this->log->entries[1]['query'] );
		self::assertSame( 1, $this->log->entries[1]['results'] );
	}

	public function test_a_later_page_alone_is_not_logged(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		self::assertSame( '', $this->service()->search( 'shipping', 2 )->log_token );
		self::assertSame( array(), $this->log->entries );
	}

	public function test_test_searches_and_switched_off_statistics_log_nothing(): void {
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		self::assertSame( '', $this->service()->preview( 'shipping' )->log_token );

		$this->configure( array( 'tracking_enabled' => false ) );

		self::assertSame( '', $this->service()->search( 'shipping' )->log_token );
		self::assertSame( array(), $this->log->entries );
	}

	public function test_other_forms_of_a_word_and_compounds_are_found(): void {
		$this->add_page( 10, 'Unsere Rezepte', 'Alles aus der Küche.' );
		$this->add_page( 20, 'Lieferung', 'Die Versandkosten hängen vom Gewicht ab.' );
		$this->add_page( 30, 'Impressum', 'Wer diese Website betreibt.' );

		$service = $this->service();

		self::assertSame( array( 10 ), $service->search( 'Rezept' )->post_ids() );
		self::assertSame( Relevance::High, $service->search( 'Rezepten' )->hits[0]->relevance );

		$compound = $service->search( 'Kosten' );

		self::assertSame( array( 20 ), $compound->post_ids() );
		self::assertSame( Relevance::Medium, $compound->hits[0]->relevance, 'The last part of a compound is a real match.' );
		self::assertSame( '', $compound->ranking->corrected_query, 'Nothing had to be corrected to find it.' );
	}

	public function test_a_phrase_in_quotation_marks_must_appear_word_for_word(): void {
		$this->add_page( 10, 'Dinner', 'A quick chicken recipe for two.' );
		$this->add_page( 20, 'Lunch', 'Chicken, and further down a recipe for bread.' );

		$service = $this->service();

		self::assertSame( array( 10, 20 ), $service->search( 'chicken recipe' )->post_ids() );
		self::assertSame( array( 10 ), $service->search( '"chicken recipe"' )->post_ids() );
		self::assertSame( array( 10 ), $service->search( '„Chicken   Recipe“ quick' )->post_ids() );
	}

	public function test_a_word_with_a_minus_excludes_pages_that_have_it_in_any_form(): void {
		$this->add_page( 10, 'Dinner', 'A chicken recipe.' );
		$this->add_page( 20, 'Lunch', 'Vegane Rezepte: a recipe without meat.' );

		$service = $this->service();

		self::assertCount( 2, $service->search( 'recipe' )->hits );
		self::assertSame( array( 10 ), $service->search( 'recipe -vegan' )->post_ids() );
	}

	public function test_a_phrase_that_makes_demands_is_neither_corrected_nor_handed_to_the_model(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1';

		$results = $this->service()->search( 'poultry -fish' );

		self::assertSame( 0, $results->total );
		self::assertSame( array(), $this->provider->chat_requests );
		self::assertSame( array( 'poultry' ), $this->provider->embedded, 'The meaning is that of the words, without the notation.' );
	}

	public function test_a_quick_search_returns_at_once_and_says_that_the_model_is_still_to_be_asked(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1';

		$service = $this->service();
		$quick   = $service->search( 'poultry', 1, null, array(), true );

		self::assertSame( 0, $quick->total );
		self::assertTrue( $quick->is_provisional );
		self::assertSame( array(), $this->provider->chat_requests, 'Nobody waited for the model.' );

		// The second step, in a request of its own.
		$refined = $this->service()->refined( 'poultry', $quick->log_token );

		self::assertSame( array( 10 ), $refined->post_ids() );
		self::assertFalse( $refined->is_provisional );
		self::assertCount( 1, $this->log->entries, 'Still one search.' );
		self::assertSame( 200, $this->log->entries[1]['input_tokens'], 'What the model cost is added to it.' );
	}

	public function test_a_quick_search_with_relevant_results_is_final(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['dinner'] = self::ABOUT_FOOD;

		self::assertFalse( $this->service()->search( 'dinner', 1, null, array(), true )->is_provisional );
		self::assertFalse( $this->service()->search( 'chicken', 1, null, array(), true )->is_provisional, 'Found by its words.' );
	}

	public function test_a_large_index_is_searched_through_a_first_selection(): void {
		$this->use_ai();

		// More passages than are compared one by one. All but one are about something else.
		for ( $post_id = 1; $post_id <= 2010; $post_id++ ) {
			$this->add_page( $post_id, 'Paragraph ' . $post_id, 'Legal text.', 777 === $post_id ? self::ABOUT_FOOD : self::ABOUT_LAW );
		}

		$this->provider->vectors['dinner'] = self::ABOUT_FOOD;

		$results = $this->service()->search( 'dinner' );

		self::assertSame( 777, $results->post_ids()[0] );
		self::assertContains( 'nearest', $this->store->calls );
		self::assertContains( 'embeddings_of', $this->store->calls );
	}

	public function test_the_comparison_by_meaning_is_remembered_for_the_next_request(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['dinner'] = self::ABOUT_FOOD;

		$this->service()->search( 'dinner' );

		// The provider is gone. Somebody paging through the results does not notice.
		$this->provider->is_down = true;

		$again = $this->service()->search( 'dinner', 2 );

		self::assertFalse( $again->ranking->degraded );
		self::assertSame( 1, $again->total );
	}

	public function test_pages_are_found_by_their_meaning(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );
		$this->add_page( 20, 'Privacy policy', 'How we handle personal data.', self::ABOUT_LAW );

		$this->provider->vectors['what can I eat today'] = self::ABOUT_FOOD;

		$results = $this->service()->search( 'what can I eat today' );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( SearchMode::Semantic, $results->ranking->mode );
		self::assertSame( Relevance::High, $results->hits[0]->relevance );
		self::assertTrue( $results->hits[0]->by_meaning );
		self::assertFalse( $results->hits[0]->by_keyword );
		self::assertSame( array(), $this->provider->chat_requests, 'A clear match needs no second opinion.' );
		self::assertSame( 4, $this->log->entries[1]['embedding_tokens'] );
	}

	public function test_the_language_model_adds_pages_the_search_was_unsure_about(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );
		$this->add_page( 20, 'Privacy policy', 'How we handle personal data.', self::ABOUT_LAW );

		// "Poultry" lies between the meanings: too far from the recipe to be a result by itself.
		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1';

		$results = $this->service()->search( 'poultry' );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( Relevance::Medium, $results->hits[0]->relevance );
		self::assertTrue( $results->hits[0]->by_ai );
		self::assertCount( 1, $this->provider->chat_requests );
		self::assertSame( 200, $this->log->entries[1]['input_tokens'], 'The second opinion is part of what the search cost.' );
	}

	public function test_the_language_model_can_say_that_nothing_fits(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['tax return'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer           = 'none';

		self::assertSame( 0, $this->service()->search( 'tax return' )->total );
	}

	public function test_the_language_model_is_never_shown_pages_the_visitor_may_not_see(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );
		$this->add_page( 20, 'Unpublished duck recipe', 'Still a draft.', self::ABOUT_FOOD, array( 'post_status' => 'draft' ) );

		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1, 2';

		$results = $this->service()->search( 'poultry' );
		$prompt  = (string) json_encode( $this->provider->chat_requests );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertStringContainsString( 'Chicken with asparagus', $prompt );
		self::assertStringNotContainsString( 'Unpublished duck recipe', $prompt );
	}

	public function test_the_second_opinion_can_be_switched_off(): void {
		$this->use_ai();
		$this->configure( array( 'ai_judge' => false ) );
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1';

		self::assertSame( 0, $this->service()->search( 'poultry' )->total );
		self::assertSame( array(), $this->provider->chat_requests );
	}

	public function test_the_search_continues_by_keyword_when_the_provider_is_down(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->is_down = true;

		$results = $this->service()->search( 'chicken' );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( SearchMode::Keyword, $results->ranking->mode );
		self::assertTrue( $results->ranking->degraded );
	}

	public function test_the_same_query_is_embedded_once_per_request_and_cached_for_the_next(): void {
		$this->use_ai();
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['dinner'] = self::ABOUT_FOOD;

		$service = $this->service();
		$service->search( 'dinner' );
		$service->search( 'dinner', 2 );

		// A new request: new services, same cache.
		$this->service()->search( 'dinner' );

		self::assertSame( array( 'dinner' ), $this->provider->embedded );
	}

	public function test_weak_results_can_be_hidden(): void {
		$this->use_ai();
		$this->configure( array( 'ai_judge' => false ) );
		$this->add_page( 10, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		// Close enough to be listed, too far to be called relevant.
		$this->provider->vectors['snack'] = array( 0.55, 0.0, 0.835 );

		self::assertSame( Relevance::Low, $this->service()->search( 'snack' )->hits[0]->relevance );

		$this->configure( array( 'hide_weak_results' => true ) );

		self::assertSame( 0, $this->service()->search( 'snack' )->total );
	}

	/**
	 * Returns the search service of a new request.
	 */
	private function service(): SearchService {
		// The thresholds are fixed here, so the tests do not depend on the tuning of a provider.
		Filters\expectApplied( 'prstsearch_similarity_thresholds' )->andReturn(
			array(
				'high'   => 0.8,
				'medium' => 0.6,
			)
		);

		$container = clone $this->container;

		return $container->get( SearchService::class );
	}
}
