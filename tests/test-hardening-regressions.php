<?php
/**
 * Standalone regression checks for security and calculator hardening.
 *
 * Run: php tests/test-hardening-regressions.php
 */

$pass = 0;
$fail = 0;

function check( string $label, bool $condition, string $detail = '' ): void {
    global $pass, $fail;
    if ( $condition ) {
        echo "[PASS] $label\n";
        $pass++;
    } else {
        echo "[FAIL] $label" . ( $detail ? " - $detail" : '' ) . "\n";
        $fail++;
    }
}

function source_file( string $relative_path ): string {
    $path = dirname( __DIR__ ) . '/' . $relative_path;
    return file_exists( $path ) ? file_get_contents( $path ) : '';
}

$cart_php      = source_file( 'frontend/class-cart.php' );
$admin_js      = source_file( 'assets/js/admin.js' );
$calculator_js = source_file( 'assets/js/calculator.js' );
$btw_checkout_js = source_file( 'assets/js/btw-checkout.js' );
$btw_checkout_php = source_file( 'includes/btw/class-btw-checkout.php' );
$woopages_js   = source_file( 'assets/js/woopages.js' );
$pdf_editor    = source_file( 'includes/pdf/class-pdf-template-editor.php' );
$pdf_generator = source_file( 'includes/pdf/class-pdf-generator.php' );
$woopages_php  = source_file( 'includes/woopages/class-woopages-loader.php' );
$woopages_helper = source_file( 'includes/woopages/class-woopages-helper.php' );
$woopages_cart_template = source_file( 'templates/woopages/cart.php' );
$woopages_checkout_template = source_file( 'templates/woopages/checkout.php' );
$woopages_totals_template = source_file( 'templates/woopages/parts/cart-totals.php' );
$btw_module    = source_file( 'includes/btw/class-btw-module.php' );
$shipping_module = source_file( 'includes/shipping/class-shipping-module.php' );
$boost_shipping_method = source_file( 'includes/shipping/class-boost-shipping-method.php' );
$vies_php      = source_file( 'includes/btw/class-vies-validator.php' );
$plugin_php    = source_file( 'bossier-calculator-builder.php' );

$plugin_header_version = '';
$asset_version         = '';
if ( preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $plugin_php, $matches ) ) {
    $plugin_header_version = $matches[1];
}
if ( preg_match( "/define\\(\\s*'BOSSIER_CALC_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin_php, $matches ) ) {
    $asset_version = $matches[1];
}

check(
    'Plugin header version matches the frontend asset cache-buster version',
    '' !== $plugin_header_version
        && $plugin_header_version === $asset_version,
    'Header version: ' . $plugin_header_version . ', asset version: ' . $asset_version
);

check(
    'Product fee names are made unique per configuration so WooCommerce does not reject duplicate fee IDs',
    false !== strpos( $cart_php, 'private function build_unique_product_fee_name' )
        && false !== strpos( $cart_php, 'private function build_product_fee_group_key' )
        && false !== strpos( $cart_php, '$this->build_unique_product_fee_name( $fee_data, $used_fee_ids )' ),
    'Behavior is covered by tests/test-product-fee-grouping.php; WC fee ID is sanitize_title( name ).'
);

check(
    'Cart total recalculation sets calculator price in WooCommerce tax input mode',
    false !== strpos( $cart_php, 'private function get_woocommerce_price( $inclusive_price )' )
        && false !== strpos( $cart_php, 'private function should_set_inclusive_price()' )
        && false !== strpos( $cart_php, 'wc_prices_include_tax()' )
        && false !== strpos( $cart_php, 'BTW_Module::should_apply_reverse_charge()' )
        && false !== strpos( $cart_php, '$cart_item[\'data\']->set_price( $this->get_woocommerce_price( $calculated_price ) );' )
        && false === strpos( $cart_php, '$cart_item[\'data\']->set_price( $this->get_exclusive_price( $calculated_price ) );' )
        && false === strpos( $cart_php, '$taxes = \WC_Tax::calc_inclusive_tax' )
        && false === strpos( $cart_php, '$cart_item[\'data\']->set_price($calculated_price);' )
        && false === strpos( $cart_php, '$cart_item[\'data\']->set_price($inclusive_price);' ),
    'Calculator set_price() must respect WooCommerce prices_include_tax setting.'
);

check(
    'WooPages cart summary uses WooCommerce cart total as the payment source of truth',
    false !== strpos( $woopages_helper, '$total     = (float) $cart->get_total( \'edit\' );' )
        && false !== strpos( $woopages_helper, '$tax_total = (float) $cart->get_total_tax();' )
        && false !== strpos( $woopages_helper, '$total_excl_tax = max( 0, $total - $tax_total );' ),
    'WooPages totals should not reconstruct the payable total manually.'
);

check(
    'Reverse-charge checkout state includes business flag, billing country, and dynamic tax label',
    false !== strpos( $btw_module, '$has_business_state = isset( $_POST[\'is_business\'] );' )
        && false !== strpos( $btw_module, 'set_billing_country( $billing_country )' )
        && false !== strpos( $btw_module, 'boost_vat_status' )
        && false !== strpos( $btw_module, 'boost_vat_validated_number' )
        && false !== strpos( $btw_module, 'boost_vat_billing_country' )
        && false !== strpos( $btw_module, 'boost_vat_is_business' )
        && false !== strpos( $woopages_js, "is_business: $('#boost_is_business').is(':checked') ? 1 : 0" )
        && false !== strpos( $woopages_js, "billing_country: $('#billing_country').val() || ''" )
        && false !== strpos( $btw_checkout_js, "billing_country: $('#billing_country').val() || ''" )
        && false !== strpos( $woopages_helper, "__( 'BTW (0% - Verlegd)', 'bossier-calculator' )" ),
    'BTW-verlegd can fail if checkout AJAX does not persist business/country context.'
);

check(
    'VIES validation is single-owner and race-proof on WooPages checkout',
    false !== strpos( $btw_checkout_php, 'Modules_Settings::is_woopages_enabled() && ! is_order_received_page()' )
        && false !== strpos( $btw_checkout_js, "$('body').hasClass('boost-woopages-checkout')" )
        && false !== strpos( $woopages_js, 'vatValidationRequest: null' )
        && false !== strpos( $woopages_js, 'vatValidationSeq: 0' )
        && false !== strpos( $woopages_js, 'requestSeq !== self.vatValidationSeq' )
        && false !== strpos( $woopages_js, 'this.vatValidationRequest.abort();' )
        && false !== strpos( $woopages_js, 'service_unavailable' )
        && false !== strpos( $btw_module, 'PRESERVED VALID DURING SERVICE OUTAGE' ),
    'WooPages checkout should not run two VAT validators or allow stale VIES responses to overwrite checkout state.'
);

check(
    'WooPages VAT note renders wc_price markup safely instead of escaping it as text',
    false !== strpos( $woopages_cart_template, "wp_kses_post( \$cart_summary['tax_note']" )
        && false !== strpos( $woopages_checkout_template, "wp_kses_post( \$cart_summary['tax_note']" )
        && false !== strpos( $woopages_totals_template, "wp_kses_post( \$cart_summary['tax_note']" )
        && false === strpos( $woopages_cart_template, "esc_html( \$cart_summary['tax_note']" )
        && false === strpos( $woopages_checkout_template, "esc_html( \$cart_summary['tax_note']" )
        && false === strpos( $woopages_totals_template, "esc_html( \$cart_summary['tax_note']" ),
    'Escaping tax_note with esc_html shows wc_price span markup literally.'
);

check(
    'WooPages VIES validation clears stale UI and refreshes totals directly',
    false !== strpos( $woopages_js, 'triggerCheckoutUpdate: function(refreshDelay' )
        && false !== strpos( $woopages_js, 'refreshTotals: function()' )
        && false !== strpos( $woopages_js, "action: 'boost_woopages_refresh_totals'" )
        && false !== strpos( $woopages_js, 'self.hideVatResult();' )
        && false !== strpos( $woopages_js, "$('#boost_vat_number').removeClass('validated woocommerce-validated')" )
        && false !== strpos( $btw_module, 'Do not call VIES from this hook' )
        && false !== strpos( $btw_module, '$this->clear_vat_validation_state( $vat_number, $billing_country, $is_business );' ),
    'VIES input changes should not leave an old valid message or wait only for updated_checkout to refresh totals.'
);

check(
    'Reverse-charge shipping netting is idempotent and based on original inclusive shipping cost',
    false !== strpos( $btw_module, 'get_exclusive_shipping_cost( $inclusive_cost )' )
        && false !== strpos( $btw_module, 'boost_shipping_inclusive_cost' )
        && false === strpos( $btw_module, '$rate->set_cost( $cost / 1.21 );' )
        && false !== strpos( $shipping_module, "'boost_shipping_inclusive_cost'" )
        && false !== strpos( $boost_shipping_method, "'boost_shipping_inclusive_cost'" ),
    'Reverse-charge shipping should not repeatedly divide the current rate cost by a hardcoded 1.21.'
);

check(
    'Admin option counters use an undefined guard so zero is a valid counter value',
    false !== strpos( $admin_js, "typeof this.optionCounters[fieldId] === 'undefined'" ),
    'getNextOptionIndex should not use a falsy check.'
);

check(
    'Color option counter derives the next index from existing colors inputs',
    false !== strpos( $admin_js, "collectionName = 'colors'" )
        && false !== strpos( $admin_js, 'getNextOptionIndex(fieldId + \'_color\', $fieldItem)' ),
    'Color counters should continue after existing saved color indices.'
);

check(
    'Option counters reconcile with the current DOM before every new row is added',
    false !== strpos( $admin_js, 'nextExistingIndex' )
        && false !== strpos( $admin_js, 'this.optionCounters[fieldId] < nextExistingIndex' ),
    'A stale option counter can reuse an existing color index.'
);

check(
    'Option counters read indexes from the clicked field context instead of only a global data-field-id lookup',
    false !== strpos( $admin_js, 'getNextOptionIndex: function(fieldId, $fieldContext)' )
        && false !== strpos( $admin_js, '$fieldContext && $fieldContext.length' )
        && false !== strpos( $admin_js, 'getNextOptionIndex(fieldId + \'_color\', $fieldItem)' )
        && false !== strpos( $admin_js, 'getNextOptionIndex(fieldId + \'_length\', $fieldItem)' )
        && false !== strpos( $admin_js, 'getNextOptionIndex(fieldId + \'_custom\', $fieldItem)' ),
    'If fieldId is missing or stale, new rows can still reuse colors[0]/colors[1].'
);

check(
    'Color option add appends directly into the color table and keeps optional admin dependencies guarded',
    false !== strpos( $admin_js, ".closest('.bossier-options-list')" )
        && false !== strpos( $admin_js, ".find('.bossier-color-options-table tbody')" )
        && false !== strpos( $admin_js, 'window.bossierCalculatorAdmin || {}' )
        && false !== strpos( $admin_js, '$.fn.wpColorPicker' )
        && false === strpos( $admin_js, '$button.prev(\'table\').find(\'tbody\').append($row)' ),
    'The color add flow should not depend on a previous sibling table or unguarded globals.'
);

check(
    'Admin submit normalizes option indexes before validation so duplicate names cannot overwrite rows',
    false !== strpos( $admin_js, 'normalizeOptionIndexes: function()' )
        && false !== strpos( $admin_js, 'self.normalizeOptionIndexes();' )
        && false !== strpos( $admin_js, 'normalizeCollectionIndexes($fieldItem, \'colors\'' )
        && false !== strpos( $admin_js, 'normalizeCollectionIndexes($fieldItem, \'fixed_options\'' )
        && false !== strpos( $admin_js, 'normalizeCollectionIndexes($fieldItem, \'custom_options\'' )
        && false !== strpos( $admin_js, 'normalizeMitreGroupIndexes($fieldItem)' )
        && false !== strpos( $admin_js, 'replace(collectionPattern, \'[\' + collectionName + \'][\' + index + \']\')' ),
    'Duplicate option indexes in the submitted form can still collapse earlier rows.'
);

check(
    'Show-when admin dropdowns use real option row classes',
    false !== strpos( $admin_js, '.bossier-color-option-row' )
        && false !== strpos( $admin_js, '.bossier-angle-option-row' )
        && false === strpos( $admin_js, '.bossier-color-item' )
        && false === strpos( $admin_js, '.bossier-custom-option-item' )
        && false === strpos( $admin_js, '.bossier-mitre-angle-item, .bossier-angle-item' ),
    'Stale show_when selectors still present.'
);

check(
    'PDF template editor requires the high-trust template capability helper',
    false !== strpos( $pdf_editor, 'current_user_can_edit_templates' )
        && false === strpos( $pdf_editor, "current_user_can( 'manage_woocommerce' )" )
        && false !== strpos( $pdf_generator, "current_user_can( 'manage_options' )" ),
    'PDF template execution should not be available to manage_woocommerce-only users.'
);

check(
    'WooPages shipping selection validates posted method against available package rates',
    false !== strpos( $woopages_php, 'isset( $package[\'rates\'][ $method_id ] )' )
        && false !== strpos( $woopages_php, '$valid_method' ),
    'Shipping method is still accepted without rate validation.'
);

check(
    'VAT logging masks customer VAT numbers and avoids company names',
        false !== strpos( $btw_module, 'mask_vat_number_for_log' )
        && false === strpos( $btw_module, '\'VAT AJAX: \' . $vat_number' )
        && false === strpos( $btw_module, 'company: ' )
        && false !== strpos( $vies_php, 'mask_vat_number_for_log' )
        && false === strpos( $vies_php, 'VAT validation failed for "%s"' )
        && false === strpos( $vies_php, 'VIES validated %s%s' ),
    'VAT log output still exposes raw VAT or company details.'
);

check(
    'Frontend calculator debug logging is removed',
    false === strpos( $calculator_js, "console.log('Updating display with result:', result);" ),
    'Debug console.log still present.'
);

echo "\n$pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
