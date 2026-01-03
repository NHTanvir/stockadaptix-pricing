<?php
/**
 * Plugin Name: StockMatrix Pricing for WooCommerce
 * Description: Dynamically adjust product prices based on current stock quantity to reflect supply and demand in real-time.
 * Version: 1.0.0
 * Author: Naymul Hasan Tanvir
 * License: GPL v2 or later
 * Text Domain: stockmatrix-pricing-for-wc
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9
 * WC requires at least: 5.0
 * WC tested up to: 10.4.3
 * Requires Plugins: woocommerce
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Automattic\WooCommerce\Utilities\FeaturesUtil;

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// Define plugin constants
define( 'STOCKMATRIX_PRICING_VERSION', '1.0.1' );
define( 'STOCKMATRIX_PRICING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STOCKMATRIX_PRICING_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );


/**
 * Main plugin class
 */
class StockMatrix_Pricing_For_WC {

	/**
	 * Plugin instance
	 *
	 * @var object
	 */
	private static $instance = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize the plugin
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {

		// Initialize services after plugins are loaded
		add_action( 'plugins_loaded', array( $this, 'init_services' ) );

		// Enqueue scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}


	/**
	 * Initialize services
	 */
	public function init_services() {
		// Check if WooCommerce is active before initializing services
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
			return;
		}

		// Include service classes
		require_once STOCKMATRIX_PRICING_PLUGIN_PATH . 'includes/Services/PricingService.php';
		require_once STOCKMATRIX_PRICING_PLUGIN_PATH . 'includes/Services/CompatibilityService.php';
		require_once STOCKMATRIX_PRICING_PLUGIN_PATH . 'includes/Modules/AdminSettingsModule.php';
		require_once STOCKMATRIX_PRICING_PLUGIN_PATH . 'includes/Modules/CustomerMessagingModule.php';

		// Initialize the pricing service
		new \StockMatrixPricing\Services\PricingService();

		// Initialize the compatibility service
		new \StockMatrixPricing\Services\CompatibilityService();

		// Initialize admin settings module
		new \StockMatrixPricing\Modules\AdminSettingsModule();

		// Initialize customer messaging module
		new \StockMatrixPricing\Modules\CustomerMessagingModule();
	}

	/**
	 * Check if WooCommerce is active
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Display notice if WooCommerce is not active
	 */
	public function woocommerce_required_notice() {
		/* translators: %s: Plugin name */
		$message = sprintf( __( '%s requires WooCommerce to be installed and active.', 'stockmatrix-pricing-for-wc' ), __( 'StockMatrix Pricing for WooCommerce', 'stockmatrix-pricing-for-wc' ) );
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function enqueue_scripts() {
		wp_enqueue_style(
			'stockmatrix-pricing-frontend',
			STOCKMATRIX_PRICING_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			STOCKMATRIX_PRICING_VERSION
		);
	}
}

/**
 * Initialize the main plugin class
 *
 * @return object
 */
function stockmatrix_pricing_init() {
	return StockMatrix_Pricing_For_WC::get_instance();
}

// Initialize the plugin
stockmatrix_pricing_init();