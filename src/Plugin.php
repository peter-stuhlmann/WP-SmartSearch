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
use PrstSearch\Security\SearchGuard;
use PrstSearch\Stats\LogCleaner;
use PrstSearch\Support\Assets;
use PrstSearch\Support\Container;
use PrstSearch\Support\Lock;
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
		SearchGuard::class,
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

	private const UPGRADE_LOCK = 'upgrade';

	/**
	 * Seconds the request that upgrades the tables spends on bringing passages up to date.
	 */
	private const UPGRADE_SECONDS = 3;

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

		$plugin->upgrade();

		add_action( 'init', array( self::class, 'load_textdomain' ) );

		$services = is_admin() ? array_merge( self::HOOKED_SERVICES, self::ADMIN_SERVICES ) : self::HOOKED_SERVICES;

		foreach ( $services as $service ) {
			$plugin->get( $service )->register();
		}
	}

	/**
	 * Creates or upgrades the tables if this version of the plugin needs another schema.
	 *
	 * Changing a large table takes a while, and until it is done every request
	 * finds the old version. A lock makes sure only the first of them does the
	 * work; the others carry on with what is there.
	 */
	private function upgrade(): void {
		$installer = $this->get( Installer::class );

		if ( ! $installer->is_outdated() || ! $this->get( Lock::class )->acquire( self::UPGRADE_LOCK, 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		try {
			$installer->install();

			// An upgrade can leave the stored passages one step behind. A small website is caught up with
			// right here and never notices; on a large one the background job takes over after a moment.
			$this->get( BackgroundJobs::class )->complete_passages( self::UPGRADE_SECONDS );
		} finally {
			$this->get( Lock::class )->release( self::UPGRADE_LOCK );
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
