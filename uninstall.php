<?php
/**
 * Uninstall script for StockMatrix Pricing for WooCommerce
 *
 * @package StockMatrix_Pricing
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options from the database
delete_option( 'stockmatrix_pricing_settings' );

// Optionally delete any other data like custom tables (none in this plugin)
// delete_option( 'another_option_name' );