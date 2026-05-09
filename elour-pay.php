<?php
/**
 * Plugin Name: Élour Pay
 * Plugin URI:  https://elour.pk
 * Description: Branded payment gateway — bank account + OTP checkout powered by Safepay.
 * Version:     2.0.0
 * Author:      ELOURA PERSONAL CARE PVT LTD
 * License:     Proprietary
 * Text Domain: elour-pay
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ELOUR_PAY_VERSION',  '2.0.0' );
define( 'ELOUR_PAY_PATH',     plugin_dir_path( __FILE__ ) );
define( 'ELOUR_PAY_URL',      plugin_dir_url( __FILE__ ) );
define( 'ELOUR_PAY_BASENAME', plugin_basename( __FILE__ ) );

// HPOS compatibility
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Bootstrap
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>Élour Pay</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }

    require_once ELOUR_PAY_PATH . 'includes/class-elour-pay-gateway.php';
    require_once ELOUR_PAY_PATH . 'includes/class-elour-pay-safepay-api.php';
    require_once ELOUR_PAY_PATH . 'includes/class-elour-pay-ajax.php';
    require_once ELOUR_PAY_PATH . 'includes/class-elour-pay-sync.php';

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'Elour_Pay_Gateway';
        return $gateways;
    } );

    add_action( 'woocommerce_init', function () {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['elour_pay'] ) ) {
            new Elour_Pay_Ajax( $gateways['elour_pay'] );
        }
    } );
}, 11 );

// Settings shortcut in plugin list
add_filter( 'plugin_action_links_' . ELOUR_PAY_BASENAME, function ( $links ) {
    array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=elour_pay' ) . '">Settings</a>' );
    return $links;
} );
