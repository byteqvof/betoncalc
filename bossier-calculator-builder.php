<?php
/**
 * Plugin Name: Boost Calculator
 * Plugin URI: https://bossierbeton.nl
 * Description: Dynamic product calculator system for WooCommerce with admin builder and full cart/order integration.
 * Version: 4.3
 * Author: ByteQ
 * Author URI: https://byteq.nl
 * Text Domain: bossier-calculator
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package Bossier_Calculator_Builder
 */

namespace Bossier\Calculator;

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'BOSSIER_CALC_VERSION', '4.3' );
define( 'BOSSIER_CALC_PLUGIN_FILE', __FILE__ );
define( 'BOSSIER_CALC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOSSIER_CALC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BOSSIER_CALC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * @param string $class_name The class name to load.
 */
spl_autoload_register( function( $class_name ) {
    // Only autoload our namespace
    if ( strpos( $class_name, 'Bossier\\Calculator\\' ) !== 0 ) {
        return;
    }

    // Remove namespace prefix
    $class_name = str_replace( 'Bossier\\Calculator\\', '', $class_name );

    // Convert to file path
    $class_file = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );
    $class_file = str_replace( '_', '-', strtolower( $class_file ) );

    // Determine directory based on class name
    $directories = array(
        'Admin'    => 'admin',
        'Frontend' => 'frontend',
        'PDF'      => 'includes/pdf',
        'BTW'      => 'includes/btw',
        'Shipping' => 'includes/shipping',
        'WooPages' => 'includes/woopages',
    );

    $file_path = '';
    foreach ( $directories as $namespace => $dir ) {
        if ( strpos( $class_name, $namespace ) === 0 ) {
            $class_file = str_replace( strtolower( $namespace ) . DIRECTORY_SEPARATOR, '', $class_file );
            $file_path = BOSSIER_CALC_PLUGIN_DIR . $dir . '/class-' . $class_file . '.php';
            break;
        }
    }

    // Default to includes directory
    if ( empty( $file_path ) ) {
        $file_path = BOSSIER_CALC_PLUGIN_DIR . 'includes/class-' . $class_file . '.php';
    }

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
});

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function is_woocommerce_active() {
    return in_array(
        'woocommerce/woocommerce.php',
        apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
        true
    );
}

/**
 * Handle plugin version upgrades.
 * Clears PDF template cache when version changes so new templates are used.
 */
function maybe_upgrade_plugin() {
    $stored_version = get_option( 'bossier_calc_version', '0' );

    if ( version_compare( $stored_version, BOSSIER_CALC_VERSION, '<' ) ) {
        // Clear PDF template cache on upgrade to use latest file templates
        delete_option( 'boost_pdf_template_invoice' );
        delete_option( 'boost_pdf_template_packing_slip' );
        delete_option( 'boost_pdf_template_style' );

        // Update stored version
        update_option( 'bossier_calc_version', BOSSIER_CALC_VERSION );
    }
}

/**
 * Initialize the plugin.
 */
function init_plugin() {
    // Check WooCommerce dependency
    if ( ! is_woocommerce_active() ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Boost Calculator requires WooCommerce to be installed and active.', 'bossier-calculator' );
            echo '</p></div>';
        });
        return;
    }

    // Run version upgrade check
    maybe_upgrade_plugin();

    // Load text domain
    load_plugin_textdomain( 'bossier-calculator', false, dirname( BOSSIER_CALC_PLUGIN_BASENAME ) . '/languages' );

    // Initialize main plugin class
    Plugin::get_instance();

    // Initialize PDF system (invoices, packing slips, email attachments)
    require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/pdf/class-pdf-settings.php';
    require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/pdf/class-pdf-order-metabox.php';
    require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/pdf/class-pdf-email-attachment.php';
    require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/pdf/class-pdf-template-editor.php';
    PDF\PDF_Settings::get_instance();
    PDF\PDF_Order_Metabox::get_instance();
    PDF\PDF_Email_Attachment::get_instance();
    PDF\PDF_Template_Editor::get_instance();

    // Initialize updater (only in admin)
    if ( is_admin() ) {
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/class-updater.php';
        Updater::get_instance();
    }

    // Initialize Modules Settings (admin page with BTW & Shipping)
    require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/class-modules-settings.php';
    Modules_Settings::get_instance();

    // Initialize BTW Verlegd module if enabled
    if ( Modules_Settings::is_btw_enabled() ) {
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/btw/class-btw-module.php';
        BTW\BTW_Module::get_instance();
    }

    // Initialize Shipping module if enabled
    if ( Modules_Settings::is_shipping_enabled() ) {
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/shipping/class-shipping-module.php';
        Shipping\Shipping_Module::get_instance();
    }

    // Initialize WooPages module if enabled
    if ( Modules_Settings::is_woopages_enabled() ) {
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/woopages/class-woopages-loader.php';
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/woopages/class-woopages-helper.php';
        WooPages\WooPages_Loader::get_instance();
    }

    // Initialize Cart Icon if enabled (works independently of WooPages)
    if ( Modules_Settings::is_cart_icon_enabled() ) {
        require_once BOSSIER_CALC_PLUGIN_DIR . 'includes/woopages/class-woopages-cart-icon.php';
        WooPages\WooPages_Cart_Icon::get_instance();
    }
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_plugin' );

/**
 * Complianz GDPR compatibility - disable script blocking in admin area.
 * Admin scripts don't need cookie consent.
 *
 * @param bool $dominated Whether the script is dominated.
 * @return bool
 */
function complianz_disable_admin_blocking( $dominated ) {
	if ( is_admin() ) {
		return true; // Return true to skip blocking in admin
	}
	return $dominated;
}
add_filter( 'cmplz_script_tags_dominated', __NAMESPACE__ . '\\complianz_disable_admin_blocking' );

/**
 * Complianz GDPR compatibility - whitelist all Boost Calculator scripts.
 *
 * @param array $tags Whitelisted script tags.
 * @return array
 */
function complianz_whitelist_boost_scripts( $tags ) {
	$tags[] = 'boost-pdf';
	$tags[] = 'bossier-calculator';
	$tags[] = 'boost-calculator';
	$tags[] = 'boost-popup';
	$tags[] = 'boostPdfEditorSettings';
	return $tags;
}
add_filter( 'cmplz_whitelisted_script_tags', __NAMESPACE__ . '\\complianz_whitelist_boost_scripts' );

/**
 * Activation hook.
 */
function activate_plugin() {
    // Flush rewrite rules for custom post type
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate_plugin' );

/**
 * Deactivation hook.
 */
function deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate_plugin' );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/**
 * Global function to render the cart icon.
 *
 * Usage in theme templates:
 * <?php boost_cart_icon(); ?>
 * <?php boost_cart_icon( true ); ?> // With cart total
 * <?php boost_cart_icon( false, 'my-custom-class' ); ?> // With custom class
 *
 * Shortcode:
 * [boost_cart_icon]
 * [boost_cart_icon show_total="yes"]
 * [boost_cart_icon class="my-custom-class"]
 *
 * @param bool   $show_total  Whether to show cart total.
 * @param string $extra_class Additional CSS class.
 */
function boost_cart_icon( $show_total = false, $extra_class = '' ) {
    if ( class_exists( 'Bossier\\Calculator\\WooPages\\WooPages_Cart_Icon' ) ) {
        echo WooPages\WooPages_Cart_Icon::render( $show_total, $extra_class );
    }
}
