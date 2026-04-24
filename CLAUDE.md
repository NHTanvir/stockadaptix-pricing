# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

WordPress/WooCommerce plugin that dynamically adjusts displayed product prices based on live stock quantity. It does **not** modify `_regular_price` in the database — it filters WooCommerce's price getters at runtime. Distribution target is the WordPress.org plugin directory.

- Requires: PHP 7.4+, WP 5.0+, WooCommerce 5.0+
- HPOS-compatible (declared via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`)
- Entry point: `stockadaptix-pricing.php` → singleton `StockAdaptix_Pricing_For_WC`

## Commands

Dev tooling is Composer-based; there is no test suite and no JS build step.

```bash
composer install        # install dev dependencies (WPCS, PHPCompatibility)
composer phpcs          # lint all PHP with WordPress coding standards
composer phpcbf         # auto-fix style violations where possible
```

On every `composer install` / `update`, `post-install-cmd` registers WPCS as an installed PHPCS standard — expected, not an error.

## Architecture

Bootstrapping is linear and explicit — there is **no PSR-4 autoloader at runtime**. `composer.json` is used only for dev tooling. Classes are loaded by manual `require_once` inside `StockAdaptix_Pricing_For_WC::init_services()` (stockadaptix-pricing.php:94-109), which runs on `plugins_loaded` only if `class_exists('WooCommerce')`. When adding a new class under `includes/`, add a corresponding `require_once` here or it will not load.

Namespaces: `StockAdaptixPricing\Services` for `includes/Services/`, `StockAdaptixPricing\Modules` for `includes/Modules/`.

Four services/modules are instantiated at boot:

- **`Services\PricingService`** — the core. Hooks WooCommerce price filters (`woocommerce_product_get_price`, `..._regular_price`, `..._sale_price`, `woocommerce_get_price_html`, cart/total hooks). All adjustments go through `calculate_adjusted_price()`.
- **`Services\CompatibilityService`** — verifies WC version and acts as a hook point for product-type gating.
- **`Modules\AdminSettingsModule`** — settings page at **WooCommerce → Stock Pricing**. All settings are stored in a single option `stockadaptix_pricing_settings` (constant `OPTIONS_KEY`). Menu slug: `stockadaptix-pricing`. `sanitize_settings()` enforces per-field min/max bounds.
- **`Modules\CustomerMessagingModule`** — renders the "price adjusted" notice on product pages and in cart item data.

Settings option: `stockadaptix_pricing_settings` (deleted on uninstall via `uninstall.php`). Text domain: `stockadaptix-pricing`.

## Non-obvious invariants

Read these before editing `PricingService`:

1. **No compounding.** Adjustment methods read the base price via `get_post_meta( $product_id, '_regular_price', true )`, not `$product->get_price()` or the `$price` argument, because those may already be filtered. Preserve this pattern when adding new adjustment paths.
2. **No "sale" appearance.** `adjust_price_html()` returns a single `<span class="woocommerce-Price-amount amount">` rather than calling `wc_format_sale_price()` — this is deliberate. Do not switch to sale-price filters to achieve an adjustment; that would render a strikethrough.
3. **Admin / AJAX / order-processing guard.** Price filters are only attached when **not** `is_admin()`, **not** `DOING_AJAX`, and not inside `is_order_processing_context()` (checks `woocommerce_new_order`, `woocommerce_update_order`, `woocommerce_save_order`, `woocommerce_rest_insert_shop_order_object`, `woocommerce_gzd_shipment_created`). Additionally, per-filter callbacks re-check these conditions. This is load-bearing for HPOS — adjusting stored order prices during order creation would corrupt historical totals. Any new hook that touches price must keep this guard.
4. **Email contexts strip filters.** `setup_email_compatibility()` removes the price filters on `woocommerce_email` and `woocommerce_order_status_changed` so emails show the price actually charged, not the current dynamic price.
5. **Cart items must not merge across adjustments.** `add_cart_item_data()` writes a unique `dsp_unique_key` (md5 of microtime+rand) when an adjustment applies, so two adds of the same product at different adjusted prices stay as separate cart lines. `dsp_adjusted_price` / `dsp_original_price` are also propagated to order item meta (`_dsp_adjusted_price`, `_dsp_original_price`) via `woocommerce_checkout_create_order_line_item`.
6. **Supported product scope.** Only `simple` products with `managing_stock() === true` and a non-null, non-negative `get_stock_quantity()` are adjusted. Variable products are explicitly out of scope (noted in README FAQ).

## Pricing rule (reference)

Evaluated in order against `stock_quantity` in `PricingService::calculate_adjusted_price()`:

1. `stock ≤ low_stock_threshold` → increase by `low_stock_price_increase` %
2. else if `stock ≤ medium_stock_threshold` → increase by `medium_stock_price_increase` %
3. else if `stock ≥ high_stock_threshold` → decrease by `high_stock_price_decrease` %
4. otherwise → unchanged

Result is clamped to `max(0, adjusted_price)`.
