<?php
namespace StockAdaptixPricing\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Product;

/**
 * Pricing Service
 * Handles the core logic for dynamic stock-based pricing
 */
class PricingService {

	const OPTION_KEY = 'stockadaptix_pricing_settings';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Apply pricing adjustment via a method that doesn't cause sale display
		// Only apply to frontend to avoid issues with HPOS and order processing
		if ( ! is_admin() && ! defined( 'DOING_AJAX' ) && ! $this->is_order_processing_context() ) {
			add_filter( 'woocommerce_product_get_price', array( $this, 'adjust_price' ), 10, 2 );
			add_filter( 'woocommerce_product_get_regular_price', array( $this, 'adjust_price' ), 10, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( $this, 'adjust_price' ), 10, 2 );

			// Variable product variation prices (used for individual variation pricing).
			add_filter( 'woocommerce_product_variation_get_price', array( $this, 'adjust_price' ), 10, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'adjust_price' ), 10, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'adjust_price' ), 10, 2 );

			// Variable product cached min/max prices used in archive listings.
			add_filter( 'woocommerce_variation_prices_price', array( $this, 'adjust_variation_cached_price' ), 10, 3 );
			add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'adjust_variation_cached_price' ), 10, 3 );
			add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'adjust_variation_cached_price' ), 10, 3 );

			// Bust the variation prices cache so dynamic prices are not stuck.
			add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_prices_hash' ), 10, 3 );
		}

		// Targeted display filters to avoid sale appearance.
		add_filter( 'woocommerce_get_price_html', array( $this, 'adjust_price_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_product_price', array( $this, 'adjust_cart_product_price_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'adjust_cart_item_price_html' ), 20, 3 );

		// Cart pricing.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 20, 2 );
		add_action( 'woocommerce_calculate_totals', array( $this, 'calculate_totals' ), 20 );

		// Cart/checkout totals - frontend only.
		if ( ! is_admin() && ! defined( 'DOING_AJAX' ) && ! $this->is_order_processing_context() ) {
			add_action( 'woocommerce_before_calculate_totals', array( $this, 'before_calculate_totals' ), 20 );
		}

		// HPOS / order processing compatibility.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'adjust_order_item_totals' ), 10, 3 );

		// Email compatibility.
		add_action( 'woocommerce_email', array( $this, 'setup_email_compatibility' ), 5 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'setup_email_compatibility' ), 5 );
	}

	/**
	 * Default settings shape
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'enable_plugin'            => 1,
			'rules'                    => array(
				array(
					'comparator' => 'lte',
					'threshold'  => 5,
					'direction'  => 'increase',
					'percent'    => 40,
				),
				array(
					'comparator' => 'lte',
					'threshold'  => 20,
					'direction'  => 'increase',
					'percent'    => 20,
				),
				array(
					'comparator' => 'gte',
					'threshold'  => 100,
					'direction'  => 'decrease',
					'percent'    => 15,
				),
			),
			'price_floor'              => 0,
			'price_ceiling'            => 0,
			'rounding_mode'            => 'none',
			'include_variations'       => 1,
			'customer_message_enabled' => 1,
			'customer_message'         => __( 'High demand – price adjusted based on availability', 'stockadaptix-pricing' ),
		);
	}

	/**
	 * Get plugin settings, migrating from legacy schema if needed
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved    = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $saved, self::default_settings() );

		// Migrate legacy schema (low/medium/high fields) into rules array.
		if ( empty( $saved['rules'] ) && self::has_legacy_fields( $saved ) ) {
			$settings['rules'] = self::build_rules_from_legacy( $saved );
		}

		// Ensure rules is always an array of normalized rule objects.
		$settings['rules'] = self::normalize_rules( $settings['rules'] );

		return $settings;
	}

	/**
	 * Whether the saved option has any legacy field
	 *
	 * @param array $saved Saved option.
	 * @return bool
	 */
	private static function has_legacy_fields( $saved ) {
		$legacy_keys = array(
			'low_stock_threshold',
			'low_stock_price_increase',
			'medium_stock_threshold',
			'medium_stock_price_increase',
			'high_stock_threshold',
			'high_stock_price_decrease',
		);
		foreach ( $legacy_keys as $key ) {
			if ( isset( $saved[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Translate the legacy three-tier schema into the new rules array
	 *
	 * @param array $saved Saved option.
	 * @return array
	 */
	private static function build_rules_from_legacy( $saved ) {
		$rules = array();
		if ( isset( $saved['low_stock_threshold'] ) || isset( $saved['low_stock_price_increase'] ) ) {
			$rules[] = array(
				'comparator' => 'lte',
				'threshold'  => isset( $saved['low_stock_threshold'] ) ? intval( $saved['low_stock_threshold'] ) : 5,
				'direction'  => 'increase',
				'percent'    => isset( $saved['low_stock_price_increase'] ) ? floatval( $saved['low_stock_price_increase'] ) : 40,
			);
		}
		if ( isset( $saved['medium_stock_threshold'] ) || isset( $saved['medium_stock_price_increase'] ) ) {
			$rules[] = array(
				'comparator' => 'lte',
				'threshold'  => isset( $saved['medium_stock_threshold'] ) ? intval( $saved['medium_stock_threshold'] ) : 20,
				'direction'  => 'increase',
				'percent'    => isset( $saved['medium_stock_price_increase'] ) ? floatval( $saved['medium_stock_price_increase'] ) : 20,
			);
		}
		if ( isset( $saved['high_stock_threshold'] ) || isset( $saved['high_stock_price_decrease'] ) ) {
			$rules[] = array(
				'comparator' => 'gte',
				'threshold'  => isset( $saved['high_stock_threshold'] ) ? intval( $saved['high_stock_threshold'] ) : 100,
				'direction'  => 'decrease',
				'percent'    => isset( $saved['high_stock_price_decrease'] ) ? floatval( $saved['high_stock_price_decrease'] ) : 15,
			);
		}
		return $rules;
	}

	/**
	 * Normalize a rules array — coerce types, clamp values, drop invalid entries
	 *
	 * @param mixed $rules Raw rules input.
	 * @return array
	 */
	public static function normalize_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return array();
		}
		$out = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$comparator = isset( $rule['comparator'] ) && 'gte' === $rule['comparator'] ? 'gte' : 'lte';
			$direction  = isset( $rule['direction'] ) && 'decrease' === $rule['direction'] ? 'decrease' : 'increase';
			$threshold  = isset( $rule['threshold'] ) ? max( 0, intval( $rule['threshold'] ) ) : 0;
			$percent    = isset( $rule['percent'] ) ? max( 0, floatval( $rule['percent'] ) ) : 0;
			$out[]      = array(
				'comparator' => $comparator,
				'threshold'  => $threshold,
				'direction'  => $direction,
				'percent'    => $percent,
			);
		}
		return $out;
	}

	/**
	 * Check if plugin is enabled
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$settings = self::get_settings();
		return (bool) $settings['enable_plugin'];
	}

	/**
	 * Calculate adjusted price based on stock and configured rules
	 *
	 * Pure function — no WC product lookup. Useful for tests and the preview endpoint.
	 *
	 * @param float    $original_price Base price.
	 * @param int|null $stock_quantity Stock quantity, or null for unmanaged.
	 * @param array    $settings Settings array (uses get_settings() when null).
	 * @return float
	 */
	public static function compute_price( $original_price, $stock_quantity, $settings = null ) {
		$original_price = floatval( $original_price );
		if ( $original_price < 0 ) {
			return 0.0;
		}
		if ( null === $settings ) {
			$settings = self::get_settings();
		}
		if ( empty( $settings['enable_plugin'] ) ) {
			return $original_price;
		}
		if ( null === $stock_quantity || $stock_quantity < 0 ) {
			return $original_price;
		}

		$adjustment_factor = 0.0;
		foreach ( $settings['rules'] as $rule ) {
			$matches = ( 'lte' === $rule['comparator'] && $stock_quantity <= $rule['threshold'] )
				|| ( 'gte' === $rule['comparator'] && $stock_quantity >= $rule['threshold'] );
			if ( $matches ) {
				$pct               = $rule['percent'] / 100;
				$adjustment_factor = 'decrease' === $rule['direction'] ? -$pct : $pct;
				break;
			}
		}

		$adjusted = $original_price * ( 1 + $adjustment_factor );

		// Floor / ceiling caps (0 means disabled).
		$floor   = isset( $settings['price_floor'] ) ? floatval( $settings['price_floor'] ) : 0;
		$ceiling = isset( $settings['price_ceiling'] ) ? floatval( $settings['price_ceiling'] ) : 0;
		if ( $floor > 0 ) {
			$adjusted = max( $adjusted, $floor );
		}
		if ( $ceiling > 0 ) {
			$adjusted = min( $adjusted, $ceiling );
		}

		// Rounding.
		$mode = isset( $settings['rounding_mode'] ) ? $settings['rounding_mode'] : 'none';
		if ( 'charm_99' === $mode && $adjusted >= 1 ) {
			$adjusted = round( $adjusted ) - 0.01;
		} elseif ( 'nearest' === $mode ) {
			$adjusted = round( $adjusted );
		}

		return max( 0, $adjusted );
	}

	/**
	 * Calculate adjusted price for a product ID
	 *
	 * @param int   $product_id ID of the product.
	 * @param float $original_price Original price of the product.
	 * @return float
	 */
	public function calculate_adjusted_price( $product_id, $original_price ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return floatval( $original_price );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $this->is_supported_product( $product ) ) {
			return floatval( $original_price );
		}

		$stock = $this->resolve_stock_quantity( $product );

		return self::compute_price( $original_price, $stock, self::get_settings() );
	}

	/**
	 * Whether a product is in scope for adjustment
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_supported_product( $product ) {
		if ( ! is_object( $product ) ) {
			return false;
		}
		$type     = $product->get_type();
		$settings = self::get_settings();

		if ( 'simple' === $type ) {
			return $product->managing_stock();
		}
		if ( 'variation' === $type ) {
			if ( empty( $settings['include_variations'] ) ) {
				return false;
			}
			// Variations may inherit stock from the parent.
			return $product->managing_stock() || $this->parent_manages_stock( $product );
		}
		return false;
	}

	/**
	 * Whether the variation's parent product manages stock
	 *
	 * @param WC_Product $variation Variation product.
	 * @return bool
	 */
	private function parent_manages_stock( $variation ) {
		$parent_id = $variation->get_parent_id();
		if ( ! $parent_id ) {
			return false;
		}
		$parent = wc_get_product( $parent_id );
		return $parent && $parent->managing_stock();
	}

	/**
	 * Resolve the effective stock quantity for a product (variations inherit)
	 *
	 * @param WC_Product $product Product.
	 * @return int|null
	 */
	private function resolve_stock_quantity( $product ) {
		$stock = $product->get_stock_quantity();
		if ( null === $stock && 'variation' === $product->get_type() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$stock = $parent->get_stock_quantity();
			}
		}
		return $stock;
	}

	/**
	 * Read the original (unadjusted) base price from post meta
	 *
	 * @param WC_Product $product Product.
	 * @param float      $fallback Fallback when meta is missing.
	 * @return float
	 */
	private function get_base_price( $product, $fallback ) {
		$id            = $product->get_id();
		$regular_price = floatval( get_post_meta( $id, '_regular_price', true ) );
		if ( $regular_price > 0 ) {
			return $regular_price;
		}
		return floatval( $fallback );
	}

	/**
	 * Adjust product price based on stock
	 *
	 * @param float      $price Current price.
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	public function adjust_price( $price, $product ) {
		if ( ! $this->is_enabled() || ! $product instanceof WC_Product ) {
			return $price;
		}
		if ( $this->in_safe_context() ) {
			return $price;
		}
		if ( ! $this->is_supported_product( $product ) ) {
			return $price;
		}

		$base  = $this->get_base_price( $product, $price );
		$stock = $this->resolve_stock_quantity( $product );

		return self::compute_price( $base, $stock, self::get_settings() );
	}

	/**
	 * Adjust the cached variation price array entries
	 *
	 * @param string|float $price Price.
	 * @param object       $variation Variation product.
	 * @param object       $product Parent product.
	 * @return float
	 */
	public function adjust_variation_cached_price( $price, $variation, $product ) {
		if ( ! $this->is_enabled() || $this->in_safe_context() ) {
			return $price;
		}
		if ( ! $variation instanceof WC_Product || ! $this->is_supported_product( $variation ) ) {
			return $price;
		}
		$base  = $this->get_base_price( $variation, $price );
		$stock = $this->resolve_stock_quantity( $variation );
		return self::compute_price( $base, $stock, self::get_settings() );
	}

	/**
	 * Bust variation prices cache when our settings change
	 *
	 * @param array  $hash Existing hash parts.
	 * @param object $product Product.
	 * @param bool   $for_display Display context.
	 * @return array
	 */
	public function variation_prices_hash( $hash, $product, $for_display ) {
		$settings        = self::get_settings();
		$hash['stockadaptix'] = md5( wp_json_encode( $settings ) );
		return $hash;
	}

	/**
	 * Adjust price HTML display to show only the adjusted price without sale indicators
	 *
	 * @param string     $price_html Original price HTML.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function adjust_price_html( $price_html, $product ) {
		if ( ! $this->is_enabled() || ! is_object( $product ) || $this->in_safe_context() ) {
			return $price_html;
		}
		if ( ! $this->is_supported_product( $product ) ) {
			return $price_html;
		}

		$base     = $this->get_base_price( $product, $product->get_price() );
		$stock    = $this->resolve_stock_quantity( $product );
		$adjusted = self::compute_price( $base, $stock, self::get_settings() );

		return '<span class="woocommerce-Price-amount amount">' . wc_price( $adjusted ) . '</span>';
	}

	/**
	 * Adjust cart product price display
	 *
	 * @param string $price_html Original price HTML.
	 * @param array  $cart_item Cart item array.
	 * @return string
	 */
	public function adjust_cart_product_price_html( $price_html, $cart_item ) {
		if ( is_object( $cart_item ) && $cart_item instanceof WC_Product ) {
			$product = $cart_item;
		} elseif ( is_array( $cart_item ) && isset( $cart_item['data'] ) ) {
			$product = $cart_item['data'];
		} else {
			return $price_html;
		}

		if ( ! $this->is_enabled() || ! is_object( $product ) || ! $this->is_supported_product( $product ) ) {
			return $price_html;
		}

		$base     = $this->get_base_price( $product, $product->get_price() );
		$stock    = $this->resolve_stock_quantity( $product );
		$adjusted = self::compute_price( $base, $stock, self::get_settings() );

		return wc_price( $adjusted );
	}

	/**
	 * Adjust cart item price display
	 *
	 * @param string $product_price Original product price.
	 * @param array  $cart_item Cart item array.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function adjust_cart_item_price_html( $product_price, $cart_item, $cart_item_key ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! $this->is_enabled() || ! is_object( $product ) || ! $this->is_supported_product( $product ) ) {
			return $product_price;
		}

		$base     = $this->get_base_price( $product, $product->get_price() );
		$stock    = $this->resolve_stock_quantity( $product );
		$adjusted = self::compute_price( $base, $stock, self::get_settings() );

		return wc_price( $adjusted );
	}

	/**
	 * Add custom data to cart item
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id Product ID.
	 * @param int   $variation_id Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! $this->is_enabled() ) {
			return $cart_item_data;
		}

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		if ( $product_id <= 0 ) {
			return $cart_item_data;
		}

		$the_product_id = $variation_id > 0 ? $variation_id : $product_id;
		$product        = wc_get_product( $the_product_id );

		if ( $product && $this->is_supported_product( $product ) ) {
			$base     = $this->get_base_price( $product, $product->get_price() );
			$stock    = $this->resolve_stock_quantity( $product );
			$adjusted = self::compute_price( $base, $stock, self::get_settings() );

			if ( $adjusted !== $base ) {
				$cart_item_data['dsp_adjusted_price'] = $adjusted;
				$cart_item_data['dsp_original_price'] = $base;
				// Unique hash to prevent merging of items with different prices.
				$cart_item_data['dsp_unique_key'] = md5( microtime() . wp_rand() );
			}
		}

		return $cart_item_data;
	}

	/**
	 * Get cart item from session with adjusted price
	 *
	 * @param array $cart_item Cart item array.
	 * @param array $values Values from session.
	 * @return array
	 */
	public function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['dsp_adjusted_price'] ) ) {
			$adjusted_price = floatval( $values['dsp_adjusted_price'] );
			$original_price = isset( $values['dsp_original_price'] ) ? floatval( $values['dsp_original_price'] ) : 0;
			if ( $adjusted_price >= 0 ) {
				$cart_item['data']->set_price( $adjusted_price );
				$cart_item['dsp_adjusted_price'] = $adjusted_price;
				$cart_item['dsp_original_price'] = $original_price;
			}
		}
		return $cart_item;
	}

	/**
	 * Recalculate totals to account for adjusted prices
	 *
	 * @param object $cart Cart object.
	 */
	public function calculate_totals( $cart ) {
		// Prices are already adjusted via the product objects; nothing to do here.
	}

	/**
	 * Adjust prices before totals are calculated (for cart/checkout)
	 *
	 * @param object $cart Cart object.
	 */
	public function before_calculate_totals( $cart ) {
		if ( ! $this->is_enabled() || $this->in_safe_context() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $this->is_supported_product( $product ) ) {
				continue;
			}

			$base     = $this->get_base_price( $product, $product->get_price() );
			$stock    = $this->resolve_stock_quantity( $product );
			$adjusted = self::compute_price( $base, $stock, self::get_settings() );

			if ( $adjusted !== $base && $base > 0 ) {
				$product->set_price( $adjusted );
				$product->set_regular_price( $adjusted );
			}
		}
	}

	/**
	 * Get adjustment info for a product (used for customer messaging / debug)
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_adjustment_info( $product_id ) {
		$product_id = absint( $product_id );
		$default    = array(
			'has_adjustment'        => false,
			'adjustment_percentage' => 0,
			'message'               => '',
		);
		if ( $product_id <= 0 ) {
			return $default;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $this->is_supported_product( $product ) ) {
			return $default;
		}
		$stock = $this->resolve_stock_quantity( $product );
		if ( null === $stock || $stock < 0 ) {
			return $default;
		}
		$settings = self::get_settings();
		foreach ( $settings['rules'] as $rule ) {
			$matches = ( 'lte' === $rule['comparator'] && $stock <= $rule['threshold'] )
				|| ( 'gte' === $rule['comparator'] && $stock >= $rule['threshold'] );
			if ( $matches ) {
				$signed_pct = 'decrease' === $rule['direction'] ? -$rule['percent'] : $rule['percent'];
				$message    = 'increase' === $rule['direction']
					/* translators: %d: percentage */
					? sprintf( __( 'Price increased by %d%% based on current stock', 'stockadaptix-pricing' ), $rule['percent'] )
					/* translators: %d: percentage */
					: sprintf( __( 'Price decreased by %d%% based on current stock', 'stockadaptix-pricing' ), $rule['percent'] );
				return array(
					'has_adjustment'        => 0 !== (int) $rule['percent'],
					'adjustment_percentage' => $signed_pct,
					'message'               => $message,
				);
			}
		}
		return $default;
	}

	/**
	 * Add adjusted price as order item meta for HPOS compatibility
	 *
	 * @param object $item The order item object.
	 * @param string $cart_item_key The cart item key.
	 * @param array  $values The cart item values.
	 * @param object $order The order object.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['dsp_adjusted_price'] ) ) {
			$item->add_meta_data( '_dsp_adjusted_price', $values['dsp_adjusted_price'], true );
			$item->add_meta_data( '_dsp_original_price', $values['dsp_original_price'], true );
		}
	}

	/**
	 * Adjust order item totals display
	 *
	 * @param array  $total_rows Order item totals.
	 * @param object $order Order object.
	 * @param bool   $tax_display Tax display setting.
	 * @return array
	 */
	public function adjust_order_item_totals( $total_rows, $order, $tax_display ) {
		return $total_rows;
	}

	/**
	 * Whether the current execution context should never be price-adjusted
	 *
	 * @return bool
	 */
	private function in_safe_context() {
		return is_admin()
			|| defined( 'DOING_AJAX' )
			|| $this->is_order_processing_context();
	}

	/**
	 * Check if we're inside an order create/update/save action
	 *
	 * @return bool
	 */
	private function is_order_processing_context() {
		return doing_action( 'woocommerce_new_order' ) ||
			doing_action( 'woocommerce_update_order' ) ||
			doing_action( 'woocommerce_save_order' ) ||
			doing_action( 'woocommerce_rest_insert_shop_order_object' ) ||
			doing_action( 'woocommerce_gzd_shipment_created' );
	}

	/**
	 * Setup email compatibility when email is being sent
	 */
	public function setup_email_compatibility() {
		// In email contexts, show prices actually charged rather than dynamic ones.
		remove_filter( 'woocommerce_product_get_price', array( $this, 'adjust_price' ), 10 );
		remove_filter( 'woocommerce_product_get_regular_price', array( $this, 'adjust_price' ), 10 );
		remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'adjust_price' ), 10 );
		remove_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'adjust_price' ), 10 );
		remove_filter( 'woocommerce_get_price_html', array( $this, 'adjust_price_html' ), 20 );
	}
}
