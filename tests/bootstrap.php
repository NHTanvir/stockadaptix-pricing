<?php
/**
 * PHPUnit bootstrap.
 *
 * Defines the WP guard constant before loading plugin source so the
 * `if ( ! defined( 'ABSPATH' ) ) exit;` headers do not bail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load only what the unit tests need — pure pricing math has no WP dependencies
// at parse time, but the file calls __() inside default_settings(). Brain Monkey
// stubs in TestCase::setUp() will provide that.
require_once dirname( __DIR__ ) . '/includes/Services/PricingService.php';
