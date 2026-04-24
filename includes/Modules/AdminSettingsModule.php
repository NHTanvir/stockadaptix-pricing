<?php
namespace StockAdaptixPricing\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Module
 *
 * Renders an empty mount point for the React settings app and enqueues the
 * compiled bundle. All settings I/O happens via the REST API.
 */
class AdminSettingsModule {

	const MENU_SLUG = 'stockadaptix-pricing';
	const SCRIPT_HANDLE = 'stockadaptix-pricing-admin';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add admin menu under WooCommerce
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'StockAdaptix Pricing', 'stockadaptix-pricing' ),
			__( 'Stock Pricing', 'stockadaptix-pricing' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the React mount point
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<div id="stockadaptix-root"></div>
			<noscript>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'StockAdaptix Pricing requires JavaScript to be enabled.', 'stockadaptix-pricing' ); ?>
					</p>
				</div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Enqueue the compiled React bundle on the settings page only
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$build_dir = STOCKADAPTIX_PRICING_PLUGIN_PATH . 'build/';
		$build_url = STOCKADAPTIX_PRICING_PLUGIN_URL . 'build/';
		$asset_php = $build_dir . 'index.asset.php';

		// `wp-scripts build` writes index.asset.php with declared dependencies + version.
		// If it's missing, the build step hasn't run — surface a notice instead of failing silently.
		if ( ! file_exists( $asset_php ) ) {
			add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
			return;
		}

		$asset = include $asset_php;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'stockadaptix-pricing',
			STOCKADAPTIX_PRICING_PLUGIN_PATH . 'languages'
		);

		// wp-scripts emits SCSS as style-index.css (and an -rtl variant).
		$css_file = is_rtl() ? 'style-index-rtl.css' : 'style-index.css';
		if ( file_exists( $build_dir . $css_file ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				$build_url . $css_file,
				array( 'wp-components' ),
				$asset['version']
			);
		}

		// Make sure WP component styles are loaded.
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Notice rendered when the React build is missing
	 */
	public function missing_build_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'StockAdaptix Pricing: the admin UI bundle is missing. Run "npm install && npm run build" inside the plugin directory.',
			'stockadaptix-pricing'
		);
		echo '</p></div>';
	}
}
