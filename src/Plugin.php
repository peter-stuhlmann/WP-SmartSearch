<?php
/**
 * Plugin bootstrap.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch;

use PrstSearch\Admin\AdminPage;
use PrstSearch\Admin\Fields;
use PrstSearch\Admin\Format;
use PrstSearch\Admin\FormActions;
use PrstSearch\Admin\HelpTabs;
use PrstSearch\Admin\PostIndicators;
use PrstSearch\Admin\StatsViewModel;
use PrstSearch\Admin\ViewModel;
use PrstSearch\Database\ChunkRepository;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Database\Installer;
use PrstSearch\Database\SearchLog;
use PrstSearch\Database\SearchLogRepository;
use PrstSearch\Frontend\AnswerInjector;
use PrstSearch\Frontend\Blocks;
use PrstSearch\Frontend\FrontendAssets;
use PrstSearch\Frontend\LanguageFilter;
use PrstSearch\Frontend\QueryIntegration;
use PrstSearch\Frontend\SearchUi;
use PrstSearch\Frontend\TemplateLoader;
use PrstSearch\Index\BackgroundJobs;
use PrstSearch\Index\IndexHooks;
use PrstSearch\Index\Scheduler;
use PrstSearch\Privacy\PrivacyPolicy;
use PrstSearch\Provider\HttpTransport;
use PrstSearch\Provider\WpHttpTransport;
use PrstSearch\Rest\IndexRoutes;
use PrstSearch\Rest\ProviderRoutes;
use PrstSearch\Rest\SearchRoutes;
use PrstSearch\Security\Capabilities;
use PrstSearch\Stats\LogCleaner;
use PrstSearch\Support\Assets;
use PrstSearch\Support\Container;
use wpdb;

/**
 * Wires the services together and registers their hooks.
 */
final class Plugin {

	/**
	 * Services that attach themselves to WordPress hooks on every request.
	 */
	private const HOOKED_SERVICES = array(
		Capabilities::class,
		IndexHooks::class,
		BackgroundJobs::class,
		LogCleaner::class,
		QueryIntegration::class,
		LanguageFilter::class,
		FrontendAssets::class,
		AnswerInjector::class,
		Blocks::class,
		SearchRoutes::class,
		IndexRoutes::class,
		ProviderRoutes::class,
		PostIndicators::class,
	);

	/**
	 * Services that are only needed in the admin area.
	 */
	private const ADMIN_SERVICES = array(
		AdminPage::class,
		FormActions::class,
		PrivacyPolicy::class,
	);

	/**
	 * The running plugin.
	 */
	private static ?self $instance = null;

	/**
	 * Creates the plugin.
	 */
	private function __construct( private readonly Container $container ) {}

	/**
	 * Returns the running plugin.
	 */
	public static function instance(): self {
		return self::$instance ??= new self( self::build_container() );
	}

	/**
	 * Starts the plugin. Hooked to plugins_loaded.
	 */
	public static function boot(): void {
		$plugin = self::instance();

		// An upgrade can leave the stored passages one step behind. The first part is caught up
		// with right away, so a small website never notices; the rest follows in the background.
		if ( $plugin->get( Installer::class )->maybe_install() ) {
			$plugin->get( BackgroundJobs::class )->complete_passages();
		}

		add_action( 'init', array( self::class, 'load_textdomain' ) );

		$services = is_admin() ? array_merge( self::HOOKED_SERVICES, self::ADMIN_SERVICES ) : self::HOOKED_SERVICES;

		foreach ( $services as $service ) {
			$plugin->get( $service )->register();
		}
	}

	/**
	 * Returns a service.
	 *
	 * @template T of object
	 * @param class-string<T> $id Class name of the service.
	 * @return T
	 */
	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	/**
	 * Loads the translations shipped with the plugin.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'smart-search', false, dirname( plugin_basename( PRSTSEARCH_FILE ) ) . '/languages' );
	}

	/**
	 * Creates the tables and queues the first indexing run.
	 */
	public static function activate(): void {
		$plugin = self::instance();

		$plugin->get( Installer::class )->install();
		$plugin->get( Scheduler::class )->backfill();
	}

	/**
	 * Removes the scheduled events. Data is kept until the plugin is deleted.
	 */
	public static function deactivate(): void {
		self::instance()->get( Scheduler::class )->clear();
	}

	/**
	 * Registers the services that need more than other services to be created.
	 */
	private static function build_container(): Container {
		$container = new Container();

		$container->set( wpdb::class, static fn (): wpdb => $GLOBALS['wpdb'] );
		$container->set( HttpTransport::class, static fn (): HttpTransport => new WpHttpTransport() );

		$container->set(
			ChunkStore::class,
			static fn ( Container $c ): ChunkStore => new ChunkRepository( $c->get( wpdb::class ), $c->get( Installer::class )->chunks_table() )
		);

		$container->set(
			SearchLog::class,
			static fn ( Container $c ): SearchLog => new SearchLogRepository( $c->get( wpdb::class ), $c->get( Installer::class )->log_table() )
		);

		$container->set(
			TemplateLoader::class,
			static fn (): TemplateLoader => new TemplateLoader( PRSTSEARCH_DIR . 'templates/' )
		);

		$container->set(
			Assets::class,
			static fn (): Assets => new Assets( PRSTSEARCH_DIR . 'build/', PRSTSEARCH_URL . 'build/', PRSTSEARCH_DIR . 'languages' )
		);

		$container->set(
			Blocks::class,
			static fn ( Container $c ): Blocks => new Blocks( $c->get( SearchUi::class ), PRSTSEARCH_DIR . 'build/blocks/search' )
		);

		$container->set(
			AdminPage::class,
			static fn ( Container $c ): AdminPage => new AdminPage(
				$c->get( Assets::class ),
				$c->get( ViewModel::class ),
				$c->get( StatsViewModel::class ),
				$c->get( Fields::class ),
				$c->get( Format::class ),
				$c->get( HelpTabs::class ),
				PRSTSEARCH_DIR . 'views/admin/'
			)
		);

		return $container;
	}
}
