<?php
namespace StockAdaptixPricing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use StockAdaptixPricing\Services\PricingService;

/**
 * Unit tests for the pure-PHP pricing math.
 *
 * Only PricingService::compute_price() and ::normalize_rules() are exercised here —
 * everything else hooks into WooCommerce and is covered by manual integration testing.
 */
class PricingServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `default_settings()` calls __() — stub it to identity.
		Functions\stubs( array( '__', 'esc_html__' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'enable_plugin'      => 1,
				'rules'              => array(),
				'price_floor'        => 0,
				'price_ceiling'      => 0,
				'rounding_mode'      => 'none',
				'include_variations' => 1,
			),
			$overrides
		);
	}

	public function test_disabled_plugin_returns_original_price(): void {
		$settings = $this->settings(
			array(
				'enable_plugin' => 0,
				'rules'         => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 50 ),
				),
			)
		);
		$this->assertSame( 100.0, PricingService::compute_price( 100, 1, $settings ) );
	}

	public function test_unmanaged_stock_returns_original_price(): void {
		$settings = $this->settings(
			array(
				'rules' => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 50 ),
				),
			)
		);
		$this->assertSame( 100.0, PricingService::compute_price( 100, null, $settings ) );
	}

	public function test_first_matching_rule_wins(): void {
		$settings = $this->settings(
			array(
				'rules' => array(
					array( 'comparator' => 'lte', 'threshold' => 5,  'direction' => 'increase', 'percent' => 40 ),
					array( 'comparator' => 'lte', 'threshold' => 20, 'direction' => 'increase', 'percent' => 20 ),
				),
			)
		);
		// stock=3 matches the first rule (≤5) — +40%, not +20%.
		$this->assertEquals( 140.0, PricingService::compute_price( 100, 3, $settings ) );
		// stock=10 matches the second rule (≤20) — +20%.
		$this->assertEquals( 120.0, PricingService::compute_price( 100, 10, $settings ) );
		// stock=50 matches no rule — unchanged.
		$this->assertEquals( 100.0, PricingService::compute_price( 100, 50, $settings ) );
	}

	public function test_decrease_direction(): void {
		$settings = $this->settings(
			array(
				'rules' => array(
					array( 'comparator' => 'gte', 'threshold' => 100, 'direction' => 'decrease', 'percent' => 15 ),
				),
			)
		);
		$this->assertEquals( 85.0, PricingService::compute_price( 100, 200, $settings ) );
	}

	public function test_price_floor_clamps_low_results(): void {
		$settings = $this->settings(
			array(
				'price_floor' => 90,
				'rules'       => array(
					array( 'comparator' => 'gte', 'threshold' => 50, 'direction' => 'decrease', 'percent' => 50 ),
				),
			)
		);
		// 100 - 50% = 50, clamped up to floor 90.
		$this->assertEquals( 90.0, PricingService::compute_price( 100, 100, $settings ) );
	}

	public function test_price_ceiling_clamps_high_results(): void {
		$settings = $this->settings(
			array(
				'price_ceiling' => 120,
				'rules'         => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 50 ),
				),
			)
		);
		// 100 + 50% = 150, clamped down to ceiling 120.
		$this->assertEquals( 120.0, PricingService::compute_price( 100, 1, $settings ) );
	}

	public function test_charm_99_rounding(): void {
		$settings = $this->settings(
			array(
				'rounding_mode' => 'charm_99',
				'rules'         => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 33 ),
				),
			)
		);
		// 100 * 1.33 = 133 → round → 133 - 0.01 = 132.99
		$this->assertEquals( 132.99, PricingService::compute_price( 100, 1, $settings ) );
	}

	public function test_nearest_rounding(): void {
		$settings = $this->settings(
			array(
				'rounding_mode' => 'nearest',
				'rules'         => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 33 ),
				),
			)
		);
		// 100 * 1.33 = 133 → 133 (already integer)
		$this->assertEquals( 133.0, PricingService::compute_price( 100, 1, $settings ) );
		// 100 * 1.335 = 133.5 → 134
		$settings['rules'][0]['percent'] = 33.5;
		$this->assertEquals( 134.0, PricingService::compute_price( 100, 1, $settings ) );
	}

	public function test_negative_base_price_returns_zero(): void {
		$this->assertSame( 0.0, PricingService::compute_price( -10, 5, $this->settings() ) );
	}

	public function test_no_rule_match_returns_original(): void {
		$settings = $this->settings(
			array(
				'rules' => array(
					array( 'comparator' => 'lte', 'threshold' => 5, 'direction' => 'increase', 'percent' => 40 ),
				),
			)
		);
		$this->assertEquals( 100.0, PricingService::compute_price( 100, 50, $settings ) );
	}

	public function test_normalize_rules_drops_invalid_entries(): void {
		$result = PricingService::normalize_rules(
			array(
				'not-an-array',
				array( 'comparator' => 'gte', 'threshold' => 100, 'direction' => 'decrease', 'percent' => 10 ),
			)
		);
		$this->assertCount( 1, $result );
	}

	public function test_normalize_rules_coerces_invalid_values(): void {
		$result = PricingService::normalize_rules(
			array(
				array( 'comparator' => 'bogus', 'threshold' => -5, 'direction' => 'sideways', 'percent' => -3 ),
			)
		);
		$this->assertSame( 'lte', $result[0]['comparator'] );
		$this->assertSame( 'increase', $result[0]['direction'] );
		$this->assertSame( 0, $result[0]['threshold'] );
		$this->assertEquals( 0, $result[0]['percent'] );
	}
}
