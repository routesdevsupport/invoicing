<?php
/**
 * Main Invoicing class.
 *
 * @package Invoicing
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Invoicing class.
 *
 */
class WPInv_Plugin {

	/**
	 * GetPaid version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Data container.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Form elements instance.
	 *
	 * @var WPInv_Payment_Form_Elements
	 */
	public $form_elements;

	/**
	 * @param array An array of payment gateways.
	 */
	public $gateways;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
		$this->set_properties();
	}

	/**
	 * Sets a custom data property.
	 * 
	 * @param string $prop The prop to set.
	 * @param mixed $value The value to retrieve.
	 */
	public function set( $prop, $value ) {
		$this->data[ $prop ] = $value;
	}

	/**
	 * Gets a custom data property.
	 *
	 * @param string $prop The prop to set.
	 * @return mixed The value.
	 */
	public function get( $prop ) {

		if ( isset( $this->data[ $prop ] ) ) {
			return $this->data[ $prop ];
		}

		return null;
	}

	/**
	 * Define class properties.
	 */
	public function set_properties() {

		// Sessions.
		$this->set( 'session', new WPInv_Session_Handler() );
		$GLOBALS['wpi_session'] = $this->get( 'session' ); // Backwards compatibility.
		$GLOBALS['wpinv_euvat'] = new WPInv_EUVat(); // Backwards compatibility.

		// Init other objects.
		$this->set( 'session', new WPInv_Session_Handler() );
		$this->set( 'notes', new WPInv_Notes() );
		$this->set( 'api', new WPInv_API() );
		$this->set( 'post_types', new GetPaid_Post_Types() );
		$this->set( 'template', new GetPaid_Template() );
		$this->set( 'admin', new GetPaid_Admin() );
		$this->set( 'subscriptions', new WPInv_Subscriptions() );
		$this->set( 'invoice_emails', new GetPaid_Invoice_Notification_Emails() );
		$this->set( 'subscription_emails', new GetPaid_Subscription_Notification_Emails() );
		$this->set( 'daily_maintenace', new GetPaid_Daily_Maintenance() );
		$this->set( 'payment_forms', new GetPaid_Payment_Forms() );
		$this->set( 'maxmind', new GetPaid_MaxMind_Geolocation() );

	}

	 /**
	 * Define plugin constants.
	 */
	public function define_constants() {
		define( 'WPINV_PLUGIN_DIR', plugin_dir_path( WPINV_PLUGIN_FILE ) );
		define( 'WPINV_PLUGIN_URL', plugin_dir_url( WPINV_PLUGIN_FILE ) );
		$this->version = WPINV_VERSION;
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 1.0.19
	 */
	protected function init_hooks() {
		/* Internationalize the text strings used. */
		add_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ) );

		// Init the plugin after WordPress inits.
		add_action( 'init', array( $this, 'init' ), 1 );
		add_action( 'init', array( $this, 'maybe_process_ipn' ), 10 );
		add_action( 'init', array( $this, 'wpinv_actions' ) );
		add_action( 'init', array( $this, 'maybe_do_authenticated_action' ), 100 );

		if ( class_exists( 'BuddyPress' ) ) {
			add_action( 'bp_include', array( &$this, 'bp_invoicing_init' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 11 );
		add_action( 'wp_footer', array( &$this, 'wp_footer' ) );
		add_action( 'widgets_init', array( &$this, 'register_widgets' ) );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'wpseo_exclude_from_sitemap_by_post_ids' ) );
		add_filter( 'pre_get_posts', array( &$this, 'pre_get_posts' ) );

		// Fires after registering actions.
		do_action( 'wpinv_actions', $this );
		do_action( 'getpaid_actions', $this );

	}

	public function plugins_loaded() {
		/* Internationalize the text strings used. */
		$this->load_textdomain();

		do_action( 'wpinv_loaded' );

		// Fix oxygen page builder conflict
		if ( function_exists( 'ct_css_output' ) ) {
			wpinv_oxygen_fix_conflict();
		}
	}

	/**
	 * Load the translation of the plugin.
	 *
	 * @since 1.0
	 */
	public function load_textdomain( $locale = NULL ) {
		if ( empty( $locale ) ) {
			$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		}

		$locale = apply_filters( 'plugin_locale', $locale, 'invoicing' );

		unload_textdomain( 'invoicing' );
		load_textdomain( 'invoicing', WP_LANG_DIR . '/invoicing/invoicing-' . $locale . '.mo' );
		load_plugin_textdomain( 'invoicing', false, WPINV_PLUGIN_DIR . 'languages' );

		/**
		 * Define language constants.
		 */
		require_once( WPINV_PLUGIN_DIR . 'language.php' );
	}

	/**
	 * Include required core files used in admin and on the frontend.
	 */
	public function includes() {

		// Start with the settings.
		require_once( WPINV_PLUGIN_DIR . 'includes/admin/register-settings.php' );

		// Packages/libraries.
		require_once( WPINV_PLUGIN_DIR . 'vendor/autoload.php' );
		require_once( WPINV_PLUGIN_DIR . 'vendor/ayecode/wp-ayecode-ui/ayecode-ui-loader.php' );

		// Load functions.
		require_once( WPINV_PLUGIN_DIR . 'includes/deprecated-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-email-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-general-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-helper-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-tax-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-template-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-address-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/invoice-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/subscription-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-item-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-discount-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-gateway-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-payment-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/user-functions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/error-functions.php' );

		// Register autoloader.
		try {
			spl_autoload_register( array( $this, 'autoload' ), true );
		} catch ( Exception $e ) {
			wpinv_error_log( $e->getMessage(), '', __FILE__, 149, true );
		}

		require_once( WPINV_PLUGIN_DIR . 'includes/abstracts/abstract-wpinv-session.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-session-handler.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-ajax.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-api.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-cache-helper.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-db.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/admin/subscriptions.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-subscriptions-db.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-subscription.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/abstracts/abstract-wpinv-privacy.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-privacy.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/libraries/class-ayecode-addons.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-addons.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/checkout.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-history.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-receipt.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-messages.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/subscriptions.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/buy-item.php' );
		require_once( WPINV_PLUGIN_DIR . 'widgets/getpaid.php' );
		require_once( WPINV_PLUGIN_DIR . 'includes/admin/admin-pages.php' );

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			GetPaid_Post_Types_Admin::init();

			require_once( WPINV_PLUGIN_DIR . 'includes/admin/wpinv-admin-functions.php' );
			require_once( WPINV_PLUGIN_DIR . 'includes/admin/meta-boxes/class-mb-payment-form.php' );
			require_once( WPINV_PLUGIN_DIR . 'includes/admin/meta-boxes/class-mb-invoice-notes.php' );
			require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-admin-menus.php' );
			require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-users.php' );
			require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-getpaid-admin-profile.php' );
			// load the user class only on the users.php page
			global $pagenow;
			if($pagenow=='users.php'){
				new WPInv_Admin_Users();
			}
		}

		// Register cli commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-cli.php' );
			WP_CLI::add_command( 'invoicing', 'WPInv_CLI' );
		}

	}

	/**
	 * Class autoloader
	 *
	 * @param       string $class_name The name of the class to load.
	 * @access      public
	 * @since       1.0.19
	 * @return      void
	 */
	public function autoload( $class_name ) {

		// Normalize the class name...
		$class_name  = strtolower( $class_name );

		// ... and make sure it is our class.
		if ( false === strpos( $class_name, 'getpaid_' ) && false === strpos( $class_name, 'wpinv_' ) ) {
			return;
		}

		// Next, prepare the file name from the class.
		$file_name = 'class-' . str_replace( '_', '-', $class_name ) . '.php';

		// Base path of the classes.
		$plugin_path = untrailingslashit( WPINV_PLUGIN_DIR );

		// And an array of possible locations in order of importance.
		$locations = array(
			"$plugin_path/includes",
			"$plugin_path/includes/data-stores",
			"$plugin_path/includes/gateways",
			"$plugin_path/includes/payments",
			"$plugin_path/includes/geolocation",
			"$plugin_path/includes/reports",
			"$plugin_path/includes/api",
			"$plugin_path/includes/admin",
			"$plugin_path/includes/admin/meta-boxes",
		);

		foreach ( apply_filters( 'getpaid_autoload_locations', $locations ) as $location ) {

			if ( file_exists( trailingslashit( $location ) . $file_name ) ) {
				include trailingslashit( $location ) . $file_name;
				break;
			}

		}

	}

	/**
	 * Inits hooks etc.
	 */
	public function init() {

		// Fires before getpaid inits.
		do_action( 'before_getpaid_init', $this );

		// Maybe upgrade.
		$this->maybe_upgrade_database();

		// Load default gateways.
		$gateways = apply_filters(
			'getpaid_default_gateways',
			array(
				'manual'        => 'GetPaid_Manual_Gateway',
				'paypal'        => 'GetPaid_Paypal_Gateway',
				'worldpay'      => 'GetPaid_Worldpay_Gateway',
				'bank_transfer' => 'GetPaid_Bank_Transfer_Gateway',
				'authorizenet'  => 'GetPaid_Authorize_Net_Gateway',
			)
		);

		foreach ( $gateways as $id => $class ) {
			$this->gateways[ $id ] = new $class();
		}

		// Fires after getpaid inits.
		do_action( 'getpaid_init', $this );

	}

	/**
	 * Checks if this is an IPN request and processes it.
	 */
	public function maybe_process_ipn() {

		// Ensure that this is an IPN request.
		if ( empty( $_GET['wpi-listener'] ) || 'IPN' !== $_GET['wpi-listener'] || empty( $_GET['wpi-gateway'] ) ) {
			return;
		}

		$gateway = wpinv_clean( $_GET['wpi-gateway'] );

		do_action( 'wpinv_verify_payment_ipn', $gateway );
		do_action( "wpinv_verify_{$gateway}_ipn" );
		exit;

	}

	public function enqueue_scripts() {

		// Fires before adding scripts.
		do_action( 'getpaid_enqueue_scripts' );

		$version = filemtime( WPINV_PLUGIN_DIR . 'assets/css/invoice-front.css' );
		wp_register_style( 'wpinv_front_style', WPINV_PLUGIN_URL . 'assets/css/invoice-front.css', array(), $version );
		wp_enqueue_style( 'wpinv_front_style' );

		$localize                         = array();
		$localize['ajax_url']             = admin_url( 'admin-ajax.php' );
		$localize['nonce']                = wp_create_nonce( 'wpinv-nonce' );
		$localize['txtComplete']          = __( 'Continue', 'invoicing' );
		$localize['UseTaxes']             = wpinv_use_taxes();
		$localize['formNonce']            = wp_create_nonce( 'getpaid_form_nonce' );
		$localize['loading']              = __( 'Loading...', 'invoicing' );
		$localize['connectionError']      = __( 'Could not establish a connection to the server.', 'invoicing' );

		$localize = apply_filters( 'wpinv_front_js_localize', $localize );

		$version = filemtime( WPINV_PLUGIN_DIR . 'assets/js/payment-forms.js' );
		wp_enqueue_script( 'wpinv-front-script', WPINV_PLUGIN_URL . 'assets/js/payment-forms.js', array( 'jquery' ),  $version, true );
		wp_localize_script( 'wpinv-front-script', 'WPInv', $localize );
	}

	public function wpinv_actions() {
		if ( isset( $_REQUEST['wpi_action'] ) ) {
			do_action( 'wpinv_' . wpinv_sanitize_key( $_REQUEST['wpi_action'] ), $_REQUEST );
		}
	}

	/**
     * Fires an action after verifying that a user can fire them.
	 *
	 * Note: If the action is on an invoice, subscription etc, esure that the
	 * current user owns the invoice/subscription.
     */
    public function maybe_do_authenticated_action() {

		if ( isset( $_REQUEST['getpaid-action'] ) && isset( $_REQUEST['getpaid-nonce'] ) && wp_verify_nonce( $_REQUEST['getpaid-nonce'], 'getpaid-nonce' ) ) {

			$key  = sanitize_key( $_REQUEST['getpaid-action'] );
			$data = wp_unslash( $_REQUEST );
			if ( is_user_logged_in() ) {
				do_action( "getpaid_authenticated_action_$key", $data );
			}

			do_action( "getpaid_unauthenticated_action_$key", $data );

		}

    }

	public function pre_get_posts( $wp_query ) {

		if ( ! is_admin() && ! empty( $wp_query->query_vars['post_type'] ) && getpaid_is_invoice_post_type( $wp_query->query_vars['post_type'] ) && is_user_logged_in() && is_single() && $wp_query->is_main_query() ) {
			$wp_query->query_vars['post_status'] = array_keys( wpinv_get_invoice_statuses( false, false, $wp_query->query_vars['post_type'] ) );
		}

		return $wp_query;
	}

	public function bp_invoicing_init() {
		require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-bp-core.php' );
	}

	/**
	 * Register widgets
	 *
	 */
	public function register_widgets() {

		// Currently, UX Builder does not work particulaly well with SuperDuper.
		// So we disable our widgets when editing a page with UX Builder.
		if ( function_exists( 'ux_builder_is_active' ) && ux_builder_is_active() ) {
			return;
		}

		$widgets = apply_filters(
			'getpaid_widget_classes',
			array(
				'WPInv_Checkout_Widget',
				'WPInv_History_Widget',
				'WPInv_Receipt_Widget',
				'WPInv_Subscriptions_Widget',
				'WPInv_Buy_Item_Widget',
				'WPInv_Messages_Widget',
				'WPInv_GetPaid_Widget'
			)
		);

		foreach ( $widgets as $widget ) {
			register_widget( $widget );
		}
		
	}

	/**
	 * Upgrades the database.
	 *
	 * @since 2.0.2
	 */
	public function maybe_upgrade_database() {

		$wpi_version = get_option( 'wpinv_version', 0 );

		if ( $wpi_version == WPINV_VERSION ) {
			return;
		}

		$installer = new GetPaid_Installer();

		if ( empty( $wpi_version ) ) {
			return $installer->upgrade_db( 0 );
		}

		$upgrades  = array(
			'0.0.5' => '004',
			'1.0.3' => '102',
			'2.0.0' => '118',
			'2.0.8' => '207',
		);

		foreach ( $upgrades as $key => $method ) {

			if ( version_compare( $wpi_version, $key, '<' ) ) {
				return $installer->upgrade_db( $method );
			}

		}

	}

	/**
	 * Flushes the permalinks if needed.
	 *
	 * @since 2.0.8
	 */
	public function maybe_flush_permalinks() {

		$flush = get_option( 'wpinv_flush_permalinks', 0 );

		if ( ! empty( $flush ) ) {
			flush_rewrite_rules();
			delete_option( 'wpinv_flush_permalinks' );
		}

	}

	/**
	 * Remove our pages from yoast sitemaps.
	 *
	 * @since 1.0.19
	 * @param int[] $excluded_posts_ids
	 */
	public function wpseo_exclude_from_sitemap_by_post_ids( $excluded_posts_ids ){

		// Ensure that we have an array.
		if ( ! is_array( $excluded_posts_ids ) ) {
			$excluded_posts_ids = array();
		}

		// Prepare our pages.
		$our_pages = array();

		// Checkout page.
		$our_pages[] = wpinv_get_option( 'checkout_page', false );

		// Success page.
		$our_pages[] = wpinv_get_option( 'success_page', false );

		// Failure page.
		$our_pages[] = wpinv_get_option( 'failure_page', false );

		// History page.
		$our_pages[] = wpinv_get_option( 'invoice_history_page', false );

		// Subscriptions page.
		$our_pages[] = wpinv_get_option( 'invoice_subscription_page', false );

		$our_pages   = array_map( 'intval', array_filter( $our_pages ) );

		$excluded_posts_ids = $excluded_posts_ids + $our_pages;
		return array_unique( $excluded_posts_ids );

	}

	public function wp_footer() {
		echo '
			<div class="bsui">
				<div  id="getpaid-payment-modal" class="modal" tabindex="-1" role="dialog">
					<div class="modal-dialog modal-dialog-centered modal-lg" role="checkout" style="max-width: 650px;">
						<div class="modal-content">
							<div class="modal-body">
								<button type="button" class="close p-2 getpaid-payment-modal-close d-sm-none" data-dismiss="modal" aria-label="' . esc_attr__( 'Close', 'invoicing' ) . '">
									<i class="fa fa-times" aria-hidden="true"></i>
								</button>
								<div class="modal-body-wrapper"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		';
	}

}
