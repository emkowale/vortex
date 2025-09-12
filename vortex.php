<?php
/**
 * Plugin Name: Vortex - Shareable Carts
 * Plugin URI:  https://github.com/emkowale/vortex
 * Description: Create a short link from the current cart that pre-fills WooCommerce cart for others.
 * Version:     1.0.2
 * Author:      Eric Kowalewski
 * Author URI:  https://github.com/emkowale
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/emkowale/vortex
 * Text Domain: vortex
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* --- Cart UI: form button (front-end POST) --- */
function vortex_cart_button() {
    if ( ! is_cart() ) return;
    echo '<form method="post" style="display:inline-block">';
    wp_nonce_field( 'vortex-create', '_vortex_nonce' );
    echo '<input type="hidden" name="vortex_create" value="1" />';
    echo '<button type="submit" class="button">Create Cart Link</button>';
    echo '</form>';
}
add_action( 'woocommerce_cart_actions', 'vortex_cart_button' );

/* --- Handle form submit (runs in front-end context) --- */
function vortex_handle_create() {
    if ( empty( $_POST['vortex_create'] ) ) return;
    if ( ! isset( $_POST['_vortex_nonce'] ) || ! wp_verify_nonce( $_POST['_vortex_nonce'], 'vortex-create' ) ) wp_die( 'Invalid request.' );
    if ( ! function_exists( 'WC' ) ) wp_die( 'WooCommerce required.' );

    $cart = WC()->cart->get_cart();
    if ( empty( $cart ) ) wp_die( 'Cart is empty.' );

    $export = [];
    foreach ( $cart as $ci ) {
        $export[] = [
            'product_id'   => intval( $ci['product_id'] ),
            'variation_id' => isset( $ci['variation_id'] ) ? intval( $ci['variation_id'] ) : 0,
            'quantity'     => intval( $ci['quantity'] ),
            'variation'    => isset( $ci['variation'] ) ? $ci['variation'] : [],
        ];
    }

    $token = wp_generate_password( 8, false );
    set_transient( 'vortex_' . $token, $export, DAY_IN_SECONDS ); // 24h

    wp_safe_redirect( add_query_arg( 'vortex_token', $token, wc_get_cart_url() ) );
    exit;
}
add_action( 'init', 'vortex_handle_create' );

/* --- Show generated link on cart page with a copy button --- */
function vortex_show_link_notice() {
    if ( empty( $_GET['vortex_token'] ) ) return;
    $token = sanitize_text_field( $_GET['vortex_token'] );
    $link = esc_url( home_url( '/vortex/' . $token ) );
    wc_print_notice( 'Your cart link: <a href="' . $link . '" target="_blank">' . $link . '</a> <button id="vortex-copy" data-link="' . esc_attr( $link ) . '">Copy</button>', 'notice' );
    echo "<script>document.addEventListener('click',function(e){if(e.target && e.target.id==='vortex-copy'){navigator.clipboard.writeText(e.target.dataset.link).then(()=>alert('Link copied'))}});</script>";
}
add_action( 'woocommerce_before_cart', 'vortex_show_link_notice' );

/* --- Public endpoint to consume a token: /vortex/{token} --- */
function vortex_rewrite() { add_rewrite_rule( '^vortex/([^/]*)/?', 'index.php?vortex_token=$matches[1]', 'top' ); }
add_action( 'init', 'vortex_rewrite' );
function vortex_query_var( $vars ) { $vars[] = 'vortex_token'; return $vars; }
add_filter( 'query_vars', 'vortex_query_var' );

function vortex_load_token() {
    $token = get_query_var( 'vortex_token' );
    if ( ! $token ) return;
    $data = get_transient( 'vortex_' . $token );
    if ( ! $data ) wp_die( 'Cart link expired or invalid.' );
    if ( ! function_exists( 'WC' ) ) wp_die( 'WooCommerce required.' );

    WC()->cart->empty_cart();
    foreach ( $data as $item ) {
        WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'], $item['variation'] );
    }

    wp_safe_redirect( wc_get_checkout_url() );
    exit;
}
add_action( 'template_redirect', 'vortex_load_token' );

/* --- Activation: flush rules --- */
function vortex_activate() { vortex_rewrite(); flush_rewrite_rules(); }
function vortex_deactivate() { flush_rewrite_rules(); }
register_activation_hook( __FILE__, 'vortex_activate' );
register_deactivation_hook( __FILE__, 'vortex_deactivate' );

/* --- GitHub Updater --- */
require_once __DIR__ . '/updater.php';
new Vortex_GitHub_Updater( __FILE__ );
