<?php

/**
 * Class SearchWP_Metrics
 */
class SearchWP_Metrics {

	private $db_prefix = 'swpext_metrics_';
	private $db_tables = [
		'ids',
		'searches',
		'queries',
		'clicks',
		'meta',
	];

	private $options_keys = [
		'blocklists',
		'clear_data_on_uninstall',
		'click_tracking_buoy',
	];

	private $uid = '';
	private $cookie_name = 'swpext86386';
	private $capability = 'publish_posts';
	private $capability_settings = 'manage_options';
	private $override_searchwp_stats = true;

	private $utilities;
	private $dashboard_widget;
	private $i18n;

	// Events
	private $clicks;

	/**
	 * SearchWP_Metrics constructor.
	 */
	function __construct() {
		require_once SEARCHWP_METRICS_PLUGIN_DIR . '/vendor/autoload.php';

		$this->clicks = new \SearchWP_Metrics\Events\Clicks();
	}

	/**
	 * Initialize
	 */
	public function init() {
		add_action( 'admin_menu',                   [ $this, 'admin_menu' ], 900 );
		add_action( 'wp_before_admin_bar_render',   [ $this, 'admin_bar_menu' ], 100 );
		add_filter( 'searchwp\statistics\log',      [ $this, 'searchwp_log_search' ], 9999, 2 );
		add_filter( 'searchwp_log_search',          [ $this, 'searchwp_log_search' ], 9999, 4 );

		add_action( 'admin_enqueue_scripts',        [ $this, 'assets' ], 999 );

		$this->capability          = apply_filters( 'searchwp_metrics_capability', $this->capability );
		$this->capability_settings = apply_filters( 'searchwp_metrics_capability_settings', $this->capability_settings );

		$this->override_searchwp_stats = (bool) apply_filters( 'searchwp_metrics_override_stats', $this->override_searchwp_stats );

		new \SearchWP_Metrics\Upgrade( SEARCHWP_METRICS_VERSION );

		$this->i18n = new SearchWP_Metrics_i18n();
		$this->i18n->init();

		$this->utilities = new \SearchWP_Metrics\Utilities();
		$this->utilities->init();

		$this->dashboard_widget = new \SearchWP_Metrics\DashboardWidget();
		$this->dashboard_widget->init();

		if ( empty( $this->override_searchwp_stats ) ) {
			$this->dashboard_widget->prevent_searchwp_stats_override();
		} else {
			add_filter( 'searchwp\options\dashboard_stats_link', '__return_false', 99 );
			add_filter( 'searchwp\settings\nav\statistics', '__return_false', 99 );
			add_filter( 'searchwp\admin_bar\statistics', '__return_false', 99 );
		}

		add_action( 'searchwp\options\submenu_pages', [ $this, 'register_admin_submenu' ] );
		add_action( 'searchwp\settings\header\before', [ $this, 'register_admin_submenu_nav_tab' ] );

		add_action( 'searchwp\settings\page\title', [ $this, 'dashboard_page_title' ] );

        $this->init_click_buoy();
        $this->init_delete_metrics_data();
	}

	/**
	 * Getter for options keys
	 */
	function get_options_keys() {
		return $this->options_keys;
	}

	/**
	 * Saves an option to the database after ensuring the key is expected. Automatically prefixes option name.
	 */
	function save_option( $option, $value ) {
		if ( ! in_array( $option, $this->options_keys, true ) ) {
			return false;
		}

		return update_option( $this->db_prefix . $option, $value, 'no' );
	}

	/**
	 * Handler to save booleans in the way we expect
	 */
	function save_boolean_option( $option, $value ) {
		$value = 'false' === (string) $value ? false : true;

		// Translate boolean to string (wp_options storage)
		$value = empty( $value ) ? 'no' : 'yes';

		return $this->save_option( $option, $value );
	}

	/**
	 * Retrieves an option from the database after confirming the key is expected. Automatically prefixes option name.
	 */
	function get_option( $option ) {
		if ( ! in_array( $option, $this->options_keys, true ) ) {
			return new WP_Error( 'invalid_option', __( 'Invalid option', 'searchwp-metrics' ) );
		}

		return get_option( $this->db_prefix . $option );
	}

	/**
	 * Handler for boolean option specifically
	 */
	function get_boolean_option( $option ) {
		return 'yes' === $this->get_option( $option ) ? true : false;
	}

	/**
	 * Set UID for this request
	 */
	function set_uid() {
		$skip_uid = apply_filters( 'searchwp_metrics_skip_uid', false );
		if ( ! empty( $skip_uid ) ) {
			$this->uid = '';
			return;
		}

		$this->cookie_name = sanitize_key( apply_filters( 'searchwp_metrics_cookie_name', $this->cookie_name ) );

		$uids = new \SearchWP_Metrics\ID( 'uid' );

		// Anonymous UID is stored in a never expiring cookie
		if ( isset( $_COOKIE[ $this->cookie_name ] ) ) {

			$this->uid = $_COOKIE[ $this->cookie_name ];

			if ( ! $uids->id_exists( $this->get_uid() ) ) {
				// INVALID
				unset( $_COOKIE[ $this->cookie_name ] );
			}
		}

		$get_uid = $this->get_uid();
		if ( empty( $get_uid ) ) {
			$this->uid = $uids->generate_local();
			$expiration = apply_filters( 'searchwp_metrics_uid_expiration', time() + WEEK_IN_SECONDS );
			setcookie( $this->cookie_name, $this->uid, absint( $expiration ), '/' );
		}
	}

	/**
	 * Callback to admin_menu.
     * Controls Dashboard links for Metrics.
	 */
	public function admin_menu() {

        // Do not register a dashboard item if the user has access to SearchWP menu.
		if ( current_user_can( $this->capability_settings ) ) {
			return;
		}

		// Add link to Metrics view under Dashboard menu.
		add_dashboard_page(
			__( 'Search Metrics', 'searchwp-metrics' ),
			__( 'Search Metrics', 'searchwp-metrics' ),
			$this->capability,
			'searchwp-metrics',
			[ $this, 'view' ],
		);
	}

	/**
	 * Callback to wp_before_admin_bar_render; controls Admin Menu links for Metrics
	 */
	function admin_bar_menu() {
		global $wp_admin_bar;

		if ( ! apply_filters( 'searchwp_admin_bar', true ) ) {
			return;
		}

		// Only display if the user can actually view
		if ( ! is_admin() || ! is_admin_bar_showing() || ! current_user_can( $this->capability ) ) {
			return;
		}

		// Remove the Search Statistics link output by SearchWP
		if ( ! empty( $this->override_searchwp_stats ) && method_exists( $wp_admin_bar, 'remove_menu' ) ) {
			$wp_admin_bar->remove_menu( 'searchwp_stats' );

			// SearchWP 4 uses a hook.
		}

		$link = esc_url_raw( add_query_arg( [ 'page' => 'searchwp-metrics' ], admin_url( 'admin.php' ) ) );

		if ( function_exists( 'SWP' ) ) {
			SWP()->admin_bar_add_sub_menu(
				'Metrics',
				$link,
				'searchwp',
				'searchwp-metrics'
			);
		} else if ( class_exists( '\\SearchWP\\Utils' ) ) {
			// SearchWP 4.
			$wp_admin_bar->add_menu( [
				'parent' => \SearchWP\Utils::$slug,
				'id'     => \SearchWP\Utils::$slug . '_metrics',
				'title'  => 'Metrics',
				'href'   => $link,
			] );
		}
	}

	/**
	 * Checks to see if the current user should be blocklisted based on stored rules
	 */
	function is_user_blocklisted() {
		$blocklists = $this->get_option( 'blocklists' );

		// If there are no blocklists, there's nothing to do
		if ( empty( $blocklists['ips'] ) && empty( $blocklists['roles'] ) ) {
			return false;
		}

		$user_ip = $_SERVER['REMOTE_ADDR'];

		if ( is_array( $blocklists['ips'] ) && count( $blocklists['ips'] ) && in_array( $user_ip, $blocklists['ips'] ) ) {
			return true;
		}

		if ( is_user_logged_in() && is_array( $blocklists['roles'] ) && count( $blocklists['roles'] ) ) {
			$blocklists['roles'] = array_map( 'strtolower', $blocklists['roles'] );
			$userdata = get_userdata( get_current_user_id() );
			$roles = $userdata->roles;

			$intersect = array_intersect( $roles, $blocklists['roles'] );
			if ( ! empty( $intersect ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Callback for SearchWP hook controlling whether the current search is logged
	 *
	 * @param bool      $log        Whether to log the search
	 * @param string    $engine     Engine in use
	 * @param string    $query      Search query
	 * @param int       $hits       Number of results for search
	 *
	 * @return bool Whether to log the search
	 */
	function searchwp_log_search( $log, $engine, $query = null, $hits = null ) {

		if ( $this->is_user_blocklisted() ) {
			// Short circuit this and Core
			return false;
		}

		if ( is_null( $query ) ) {
			// This is not SearchWP 3.x so we need to update the signature. $engine is really \SearchWP\Query.
			$log    = $log;
			$query  = $engine->get_keywords();
			$hits   = $engine->found_results;
			$engine = $engine->get_engine()->get_name();
		}

		$log_this_search = apply_filters( 'searchwp_metrics_log_search', true, $engine, $query, $hits );

		if ( empty( $log_this_search ) ) {
			return false;
		}

		// By default, if logging is disabled for this request, we don't want to log
		if ( empty( $log ) ) {
			return $log;
		}

		$this->set_uid();

		// Utilize this hook to log our own search
		$search = new SearchWP_Metrics\Search( $query, $engine, $hits );
		$search->log();

		$this->clicks->add( $search );

		return ! apply_filters( 'searchwp_metrics_prevent_core_stats_log', false );
	}

	/**
	 * Getter for Metrics db table prefix
	 *
	 * @return string
	 */
	public function get_db_prefix() {
		global $wpdb;

		return $wpdb->prefix . $this->db_prefix;
	}

	/**
	 * Get full table name (including $wpdb prefix and Metrics prefix)
	 *
	 * @param $table
	 *
	 * @return bool|string
	 */
	public function get_table_name( $table ) {
		if ( ! in_array( $table, $this->db_tables, true ) ) {
			return false;
		}

		return $this->get_db_prefix() . $table;
	}

	function get_db_tables() {
		return $this->db_tables;
	}

	/**
	 * Getter for UID
	 *
	 * @return string
	 */
	public function get_uid() {
		return $this->uid;
	}

	/**
	 * Returns whether this is a legacy version of SearchWP.
	 *
	 * @since 1.3
	 * @return bool
	 */
	function is_legacy_searchwp() {
		return ! ( defined( 'SEARCHWP_VERSION' ) && version_compare( SEARCHWP_VERSION, '3.99.0', '>=' ) );
	}

	/**
	 * Enqueues all necessary assets for the Metrics page
	 *
	 * @param $hook
	 */
	function assets( $hook ) {

		$hooks = [
			'searchwp_page_searchwp-metrics',  // Admin page hook.
			'dashboard_page_searchwp-metrics', // Standalone dashboard page hook.
		];

		// We only want our assets on our Metrics page.
        if ( ! in_array( $hook, $hooks, true ) ) {
	        return;
        }

		if ( ! function_exists( 'SWP' ) && ! defined( 'SEARCHWP_VERSION' ) ) {
			return;
		}

		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG === true ) || ( isset( $_GET['script_debug'] ) ) ? '' : '.min';
		wp_register_script(
			'searchwp_metrics',
			SEARCHWP_METRICS_PLUGIN_URL . "assets/js/dist/main${debug}.js",
			[ 'jquery' ],
			SEARCHWP_METRICS_VERSION,
			true
		);

		wp_register_style(
			'searchwp_metrics',
			SEARCHWP_METRICS_PLUGIN_URL . "assets/js/dist/main${debug}.css",
			[],
			SEARCHWP_METRICS_VERSION
		);

		$settings = new \SearchWP_Metrics\Settings();

		$last_engines_setting = $settings->get_option( 'last_engines' );

		$default_engine = [
			'name'  => 'default',
			'label' => __( 'Default', 'searchwp' ),
		];

		$engines = [ $default_engine ];

		// If the default engine was in the last engines, add it now, the rest will get picked up later
		$last_engines = is_array( $last_engines_setting ) && in_array( 'default', $last_engines_setting, true ) ? [ $default_engine ] : [];

		if ( ! $this->is_legacy_searchwp() ) {
			foreach ( \SearchWP\Settings::get_engines() as $engine => $engine_settings ) {
				if ( 'default' === $engine ) {
					continue;
				}

				$engine_model = new \SearchWP\Engine( $engine );

				$this_engine = [
					'name'  => $engine,
					'label' => $engine_model->get_label(),
				];

				// Add this engine to the engines options.
				$engines[] = $this_engine;

				// Was this engine in the last engines?
				if ( is_array( $last_engines_setting ) && ! empty( $last_engines_setting ) && in_array( $engine, $last_engines_setting, true ) ) {
					$last_engines[] = $this_engine;
				}
			}
		} else {
			foreach ( SWP()->settings['engines'] as $engine => $engine_settings ) {
				if ( 'default' === $engine ) {
					continue;
				}

				$this_engine = [
					'name'  => $engine,
					'label' => $engine_settings['searchwp_engine_label'],
				];

				// Add this engine to the engines options.
				$engines[] = $this_engine;

				// Was this engine in the last engines?
				if ( is_array( $last_engines_setting ) && ! empty( $last_engines_setting ) && in_array( $engine, $last_engines_setting, true ) ) {
					$last_engines[] = $this_engine;
				}
			}
		}

		// Prep our blocklists
		$ip_blocklist = '';
		$role_blocklist = '';

		$blocklists = $this->get_option( 'blocklists' );

		if ( ! empty( $blocklists ) ) {
			if ( is_array( $blocklists['ips'] ) ) {
				$ip_blocklist = implode( "\n", $blocklists['ips'] );
			}

			if ( is_array( $blocklists['roles'] ) ) {
				$role_blocklist = implode( "\n", $blocklists['roles'] );
			}
		}

		// General Settings
		$clear_data_on_uninstall = $this->get_boolean_option( 'clear_data_on_uninstall' );
		$click_tracking_buoy = apply_filters( 'searchwp_metrics_click_buoy', $this->get_boolean_option( 'click_tracking_buoy' ) );

		$i18n = new SearchWP_Metrics_i18n();

		// SearchWP 3.x compat.
		$buoy_applicable = function_exists( 'SWP' ) && version_compare( SWP()->version, '2.9.13', '>' ) ? true : false;

		if ( ! $buoy_applicable && ! $this->is_legacy_searchwp() ) {
			$buoy_applicable = true;
		}

		$settings = [
			'blocklists' => [
				'ips'   => $ip_blocklist,
				'roles' => $role_blocklist,
			],
			'clear_data_on_uninstall' => $clear_data_on_uninstall,
			'click_tracking_buoy' => $click_tracking_buoy,
			'click_tracking_buoy_applicable' => $buoy_applicable,
		];

		wp_localize_script(
			'searchwp_metrics',
			'_SEARCHWP_METRICS_VARS',
			[
				'nonce'             => wp_create_nonce( 'searchwp_metrics_ajax' ),
				'i18n'              => $i18n->strings,
				'options'           => $i18n->options,
				'engines'           => $engines,
				'engine_default'    => $last_engines,
				'settings'          => $settings,
				'can_edit_settings' => current_user_can( $this->capability_settings ),
			]
		);

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'searchwp_metrics' );

		wp_enqueue_style( 'searchwp_metrics' );
	}

	/**
	 * This is the view of the settings screen, Vue takes it from here
	 */
	function view() {
		?>
		<div id="searchwp-metrics"></div>
		<?php
	}

	/**
     * Register SearchWP admin menu submenu.
     *
     * @since 1.4.2
     *
	 * @param array $submenu_items SearchWP admin menu submenus data.
	 *
	 * @return array
	 */
	public function register_admin_submenu( $submenu_items ) {

		if ( $this->override_searchwp_stats ) {
			unset( $submenu_items['statistics'] );
		}

		$submenu_items['metrics'] = [
			'menu_title' => esc_html__( 'Metrics', 'searchwp-metrics' ),
			'menu_slug'  => 'searchwp-metrics',
			'position'   => 20,
			'function'   => [ $this, 'view' ],
		];

		return $submenu_items;
	}

	/**
	 * Register a nav tab in the admin submenu page.
	 *
	 * @since 1.4.2
	 */
	public function register_admin_submenu_nav_tab() {

		$current_screen = get_current_screen();

		if ( $current_screen === null ) {
			return;
		}

		// Register nav tab for the admin menu page only.
		if ( $current_screen->id !== 'searchwp_page_searchwp-metrics' ) {
			return;
		}

		new \SearchWP\Admin\NavTab(
			[
				'page'       => 'metrics',
				'tab'        => 'metrics',
				'label'      => __( 'Metrics', 'searchwp-metrics' ),
				'is_default' => true,
			]
		);
	}

	/**
	 * Metrics standalone dashboard page title.
	 *
	 * @since 1.4.2
	 */
	public function dashboard_page_title() {

		$current_screen = get_current_screen();

		if ( $current_screen === null ) {
			return;
		}

		// Set screen title for the standalone dashboard page only.
		if ( $current_screen->id !== 'dashboard_page_searchwp-metrics' ) {
			return;
		}

        // Make sure the title doesn't apply several times if added somewhere else.
		if ( has_action( 'searchwp\settings\page\title' ) ) {
            return;
        }

		?>
        <h1 class="page-title">
			<?php esc_html_e( 'SearchWP Metrics', 'searchwp-metrics' ); ?>
        </h1>
        <style>
            .searchwp-metrics__title h1 {
                display: none;
            }
        </style>
		<?php
	}

	/**
	 * Init click buoy functionality.
	 *
	 * @since 1.4.2
	 */
	private function init_click_buoy() {

		$click_tracking_buoy = $this->get_boolean_option( 'click_tracking_buoy' );
		$click_tracking_buoy = apply_filters( 'searchwp_metrics_click_buoy', $click_tracking_buoy );

		if ( ! $click_tracking_buoy ) {
			return;
		}

		$click_buoy = new \SearchWP_Metrics\ClickBuoy();
		$click_buoy->init();
	}

	/**
	 * Init delete Metrics data functionality.
	 *
	 * @since 1.4.2
	 */
	private function init_delete_metrics_data() {

        $db_prefix = $this->get_db_prefix();
        $instance  = new \SearchWP_Metrics\DeleteMetricsData( $db_prefix );

		$instance->init();
	}
}
