<?php
namespace StockAdaptixPricing\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Service
 *
 * Exposes settings and a price-preview endpoint for the React admin UI.
 */
class RestApiService {

	const NAMESPACE_ROOT = 'stockadaptix/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_price' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'base_price' => array(
						'type'     => 'number',
						'required' => true,
					),
					'stock'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'settings'   => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Capability check
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /settings
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( PricingService::get_settings() );
	}

	/**
	 * POST /settings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$body      = $request->get_json_params();
		$sanitized = $this->sanitize( is_array( $body ) ? $body : array() );
		update_option( PricingService::OPTION_KEY, $sanitized );
		return rest_ensure_response( PricingService::get_settings() );
	}

	/**
	 * POST /preview — compute the price for a hypothetical (base_price, stock) pair
	 * using either the saved settings or a settings payload supplied in-flight
	 * (so the admin can preview unsaved changes).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview_price( $request ) {
		$base  = floatval( $request->get_param( 'base_price' ) );
		$stock = intval( $request->get_param( 'stock' ) );
		$draft = $request->get_param( 'settings' );

		if ( is_array( $draft ) ) {
			$settings = $this->sanitize( $draft );
		} else {
			$settings = PricingService::get_settings();
		}

		$adjusted = PricingService::compute_price( $base, $stock, $settings );

		return rest_ensure_response(
			array(
				'base_price'     => $base,
				'stock'          => $stock,
				'adjusted_price' => $adjusted,
				'delta'          => $adjusted - $base,
				'delta_percent'  => $base > 0 ? ( ( $adjusted - $base ) / $base ) * 100 : 0,
			)
		);
	}

	/**
	 * Sanitize a settings payload coming from the REST API
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = PricingService::default_settings();
		$out      = array();

		$out['enable_plugin']            = ! empty( $input['enable_plugin'] ) ? 1 : 0;
		$out['include_variations']       = ! empty( $input['include_variations'] ) ? 1 : 0;
		$out['customer_message_enabled'] = ! empty( $input['customer_message_enabled'] ) ? 1 : 0;

		$out['customer_message'] = isset( $input['customer_message'] )
			? sanitize_text_field( $input['customer_message'] )
			: $defaults['customer_message'];

		$out['price_floor']   = isset( $input['price_floor'] )   ? max( 0, floatval( $input['price_floor'] ) )   : 0;
		$out['price_ceiling'] = isset( $input['price_ceiling'] ) ? max( 0, floatval( $input['price_ceiling'] ) ) : 0;

		$valid_modes           = array( 'none', 'charm_99', 'nearest' );
		$mode                  = isset( $input['rounding_mode'] ) ? $input['rounding_mode'] : 'none';
		$out['rounding_mode']  = in_array( $mode, $valid_modes, true ) ? $mode : 'none';

		$out['rules'] = PricingService::normalize_rules( isset( $input['rules'] ) ? $input['rules'] : array() );

		return $out;
	}
}
