# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

WordPress/WooCommerce plugin that dynamically adjusts displayed product prices based on live stock quantity. It does **not** modify `_regular_price` in the database — it filters WooCommerce's price getters at runtime. Distribution target is the WordPress.org plugin directory.

- Requires: PHP 7.4+, WP 5.0+, WooCommerce 5.0+
- HPOS-compatible (declared via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`)
- Entry point: `stockadaptix-pricing.php` → singleton `StockAdaptix_Pricing_For_WC`

## Commands

PHP tooling is Composer-based; the React admin UI uses `@wordpress/scripts`.

```bash
# PHP
composer install                    # install PHPCS standards, PHPUnit, Brain Monkey
composer test                       # run PHPUnit (requires the mbstring extension at runtime)
composer phpcs                      # lint with WordPress coding standards
composer phpcbf                     # auto-fix style violations

# JavaScript / React admin UI
npm install                         # install @wordpress/scripts and React deps
npm run build                       # compile src/ → build/ (production)
npm run start                       # watch mode for development
```

The plugin **will not function** with an untouched checkout — the React bundle in `build/` is gitignored (or simply absent until you run `npm run build`). When `build/index.asset.php` is missing, `AdminSettingsModule` shows a notice telling the admin to run the build.

If your local PHP is missing `ext-mbstring`, run PHPUnit with `php -d extension=mbstring vendor/phpunit/phpunit/phpunit`. The plugin itself does not require mbstring at runtime — only the test harness does.

## Architecture

Bootstrapping is linear and explicit — there is **no PSR-4 autoloader at runtime**. `composer.json` is used only for dev tooling. Classes are loaded by manual `require_once` inside `StockAdaptix_Pricing_For_WC::init_services()`, which runs on `plugins_loaded` only if `class_exists('WooCommerce')`. When adding a new class under `includes/`, add a corresponding `require_once` here or it will not load.

Namespaces: `StockAdaptixPricing\Services` for `includes/Services/`, `StockAdaptixPricing\Modules` for `includes/Modules/`.

Five services/modules are instantiated at boot:

- **`Services\PricingService`** — the core. Hooks WooCommerce price filters for both products and variations (`woocommerce_product_get_price`, `..._regular_price`, `..._sale_price`, `woocommerce_product_variation_*`, `woocommerce_variation_prices_*`, `woocommerce_get_price_html`, cart/total hooks). All adjustments funnel through the **pure** static `compute_price( $base_price, $stock, $settings )`.
- **`Services\CompatibilityService`** — verifies WC version and acts as a hook point for product-type gating.
- **`Services\RestApiService`** — registers `GET/POST /wp-json/stockadaptix/v1/settings` and `POST /wp-json/stockadaptix/v1/preview`. The React UI talks to these.
- **`Modules\AdminSettingsModule`** — renders only an empty `<div id="stockadaptix-root">` mount point and enqueues the compiled React bundle from `build/`. All form state lives in React. Page slug: `stockadaptix-pricing` under WooCommerce.
- **`Modules\CustomerMessagingModule`** — renders the "price adjusted" notice on product pages and in cart line items.

Settings option key: `stockadaptix_pricing_settings` (constant `PricingService::OPTION_KEY`). Single source of truth for reading is `PricingService::get_settings()` — every module/service should call that, not `get_option()` directly. Deleted on uninstall via `uninstall.php`. Text domain: `stockadaptix-pricing`.

## Settings shape (1.1.0+)

```php
[
    'enable_plugin'      => 1,
    'rules'              => [
        // Evaluated in order, first match wins.
        ['comparator' => 'lte'|'gte', 'threshold' => int, 'direction' => 'increase'|'decrease', 'percent' => float],
        ...
    ],
    'price_floor'        => float,  // 0 = disabled
    'price_ceiling'      => float,  // 0 = disabled
    'rounding_mode'      => 'none'|'charm_99'|'nearest',
    'include_variations' => 1,
    'customer_message_enabled' => 1,
    'customer_message'   => '...',
]
```

Legacy schema (v1.0.x: `low_stock_threshold`, `low_stock_price_increase`, etc.) is migrated to the `rules` array on read inside `PricingService::get_settings()`. Do not write the legacy keys back — `RestApiService::sanitize()` only emits the new shape.

## React admin UI

- Entry: `src/index.js` (mounts `App` into `#stockadaptix-root`)
- `src/App.js`, `src/components/RulesEditor.js`, `src/components/PreviewSimulator.js`
- `src/api.js` is a thin `apiFetch` wrapper for the three REST endpoints
- Styling: `src/style.scss` (compiled to `build/style-index.css` + `-rtl` variant by `wp-scripts`)
- Build output: `build/index.js`, `build/index.asset.php` (declares dependencies + version), `build/style-index.css`
- All UI uses `@wordpress/components` (Card, ToggleControl, TextControl, SelectControl, Button, etc.) for native WP admin look

When editing the React app, run `npm run start` for incremental rebuilds, then reload the WP admin page. `wp-scripts` outputs an `index.asset.php` whose `dependencies` array (e.g. `wp-element`, `wp-components`) is passed verbatim to `wp_enqueue_script`.

## Non-obvious invariants

Read these before editing `PricingService`:

1. **No compounding.** Adjustment methods read the base price via `get_post_meta( $product_id, '_regular_price', true )` (`get_base_price()` helper), not `$product->get_price()` or the `$price` argument, because those may already be filtered. Preserve this pattern when adding new adjustment paths.
2. **No "sale" appearance.** `adjust_price_html()` returns a single `<span class="woocommerce-Price-amount amount">` rather than calling `wc_format_sale_price()` — this is deliberate. Do not switch to sale-price filters to achieve an adjustment; that would render a strikethrough.
3. **Admin / AJAX / order-processing guard.** Price filters are only attached when **not** `is_admin()`, **not** `DOING_AJAX`, and not inside `is_order_processing_context()` (checks `woocommerce_new_order`, `woocommerce_update_order`, `woocommerce_save_order`, `woocommerce_rest_insert_shop_order_object`, `woocommerce_gzd_shipment_created`). The shared helper is `in_safe_context()`. This is load-bearing for HPOS — adjusting stored order prices during order creation would corrupt historical totals. Any new hook that touches price must keep this guard.
4. **Email contexts strip filters.** `setup_email_compatibility()` removes the price filters on `woocommerce_email` and `woocommerce_order_status_changed` so emails show the price actually charged, not the current dynamic price.
5. **Cart items must not merge across adjustments.** `add_cart_item_data()` writes a unique `dsp_unique_key` (md5 of microtime+rand) when an adjustment applies, so two adds of the same product at different adjusted prices stay as separate cart lines. `dsp_adjusted_price` / `dsp_original_price` are also propagated to order item meta (`_dsp_adjusted_price`, `_dsp_original_price`) via `woocommerce_checkout_create_order_line_item`.
6. **Variation prices cache.** WooCommerce caches min/max prices for variable products. `variation_prices_hash()` injects an md5 of current settings into the hash so the cache busts when admins change rules — without this, the displayed range freezes until you save a product or clear caches.
7. **Variation stock fallback.** `resolve_stock_quantity()` falls back to the parent product's stock when a variation has `null` stock (variation inherits stock from parent). Mirror this when writing new code that needs a variation's effective stock.
8. **Pure pricing math is `compute_price()`.** It takes a base price, stock (or `null`), and a settings array — no WC product lookup. This is what the unit tests exercise and what the `/preview` REST endpoint calls. Never inline rule-evaluation logic elsewhere; route through `compute_price()`.

## Pricing rule evaluation (reference)

`PricingService::compute_price( $base, $stock, $settings )`:

1. If plugin disabled or `$stock` is `null`/negative, return `$base`.
2. Walk `$settings['rules']` in order; first rule where `($comparator='lte' && stock<=threshold)` or `($comparator='gte' && stock>=threshold)` wins. Apply `±percent%`.
3. Clamp to `price_floor` (if > 0) and `price_ceiling` (if > 0).
4. Apply rounding (`charm_99` → `round(p) - 0.01` for `p ≥ 1`; `nearest` → `round(p)`).
5. Return `max(0, $result)`.

## Tests

- `tests/PricingServiceTest.php` covers `compute_price()` and `normalize_rules()` — pure functions, no WP needed.
- Brain Monkey is loaded only to stub `__()` for `default_settings()`. If you add tests that exercise WP-dependent code paths, add appropriate function stubs in `setUp()`.
- The hooked WC integration code is **not** unit-tested — verify it manually by activating the plugin in a WP install with WooCommerce, configuring rules, and adding a stock-managed product to the cart.
