<?php
namespace StockAdaptixPricing\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StockAdaptixPricing\Services\PricingService;

/**
 * Customer Messaging Module
 *
 * Displays the configured "price adjusted" notice on product pages and in cart line items
 * whenever a product would receive a non-zero price adjustment under current rules.
 */
class CustomerMessagingModule {

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
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_adjustment_message' ), 25 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_message' ), 10, 2 );
	}

	/**
	 * Get plugin settings (delegates to PricingService for a single source of truth)
	 *
	 * @return array
	 */
	private function settings() {
		return PricingService::get_settings();
	}

	/**
	 * Whether the plugin is enabled and customer messaging is on
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	private function messaging_enabled( $settings ) {
		return ! empty( $settings['enable_plugin'] ) && ! empty( $settings['customer_message_enabled'] );
	}

	/**
	 * Whether a product would receive any non-zero adjustment under current rules
	 *
	 * @param object $product WC product.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	private function product_would_be_adjusted( $product, $settings ) {
		if ( ! is_object( $product ) ) {
			return false;
		}
		$type = $product->get_type();
		if ( 'simple' !== $type && 'variation' !== $type ) {
			return false;
		}
		if ( 'variation' === $type && empty( $settings['include_variations'] ) ) {
			return false;
		}
		if ( ! $product->managing_stock() ) {
			// Variations may inherit stock from parent.
			if ( 'variation' !== $type ) {
				return false;
			}
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || ! $parent->managing_stock() ) {
				return false;
			}
		}
		$stock = $product->get_stock_quantity();
		if ( null === $stock && 'variation' === $type ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$stock = $parent->get_stock_quantity();
			}
		}
		if ( null === $stock || $stock < 0 ) {
			return false;
		}

		foreach ( $settings['rules'] as $rule ) {
			$matches = ( 'lte' === $rule['comparator'] && $stock <= $rule['threshold'] )
				|| ( 'gte' === $rule['comparator'] && $stock >= $rule['threshold'] );
			if ( $matches && (float) $rule['percent'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Display adjustment message on product page
	 */
	public function display_adjustment_message() {
		global $product;
		$settings = $this->settings();
		if ( ! $this->messaging_enabled( $settings ) ) {
			return;
		}
		if ( ! $this->product_would_be_adjusted( $product, $settings ) ) {
			return;
		}
		echo '<div class="stock-price-adjustment-message">' . esc_html( $settings['customer_message'] ) . '</div>';
	}

	/**
	 * Display message in cart
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_message( $item_data, $cart_item ) {
		$settings = $this->settings();
		if ( ! $this->messaging_enabled( $settings ) ) {
			return $item_data;
		}
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! $this->product_would_be_adjusted( $product, $settings ) ) {
			return $item_data;
		}
		$item_data[] = array(
			'key'     => __( 'Dynamic Pricing Notice', 'stockadaptix-pricing' ),
			'value'   => esc_html( $settings['customer_message'] ),
			'display' => '',
		);
		return $item_data;
	}
}
