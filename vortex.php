<?php
/**
 * Plugin Name: Vortex - Shareable Carts
 * Plugin URI:  https://github.com/emkowale/vortex
 * Description: Create a short link from the current cart that pre-fills WooCommerce cart for others.
 * 	.0.3
 * Author:      Eric Kowalewski
 * Author URI:  https://github.com/emkowale
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/emkowale/vortex
 * Text Domain: vortex
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* --- Cart UI: button (front-end AJAX) --- */
function vortex_cart_button() {
    if ( ! is_cart() ) return;
    $nonce = wp_create_nonce( 'vortex-create' );
    $ajax  = admin_url( 'admin-ajax.php' );
    echo '<button type="button" class="button vortex-create-link" data-nonce="' . esc_attr( $nonce ) . '" data-ajax-url="' . esc_url( $ajax ) . '">Create Cart Link</button>';
}
add_action( 'woocommerce_cart_actions', 'vortex_cart_button' );

/* --- Inline script: handles click, clipboard, modal alert --- */
function vortex_cart_inline_script() {
    if ( ! is_cart() ) return;
    $js = <<<JS
    (function($){
        function showNotice(message, type, link) {
            const wrapper = document.querySelector('.woocommerce-notices-wrapper') || document.body;
            if (!wrapper) {
                return;
            }
            const notice = document.createElement('div');
            notice.setAttribute('role', 'alert');
            notice.className = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
            notice.appendChild(document.createTextNode(message));
            if (link) {
                notice.appendChild(document.createTextNode(' '));
                const anchor = document.createElement('a');
                anchor.href = link;
                anchor.target = '_blank';
                anchor.rel = 'noreferrer noopener';
                anchor.textContent = 'View link';
                notice.appendChild(anchor);
            }
            wrapper.prepend(notice);
            setTimeout(() => {
                if (notice.parentNode) {
                    notice.parentNode.removeChild(notice);
                }
            }, 6000);
        }

        function bind(){
            $(document).off('click.vortex', '.vortex-create-link').on('click.vortex', '.vortex-create-link', async function(e){
                e.preventDefault();
                const btn = this;
                const original = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Creating...';
                try{
                    const res = await fetch(btn.dataset.ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'vortex_create_cart_link',
                            _vortex_nonce: btn.dataset.nonce
                        })
                    });
                    let data = {};
                    const isJson = res.headers.get('content-type') && res.headers.get('content-type').includes('application/json');
                    if(isJson){
                        data = await res.json();
                    } else {
                        throw new Error('Unexpected response from server.');
                    }
                    if(!res.ok || !data.success || !data.data || !data.data.link){
                        throw new Error((data.data && data.data.message) ? data.data.message : 'Could not create link.');
                    }
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(data.data.link);
                    } else {
                        throw new Error('Clipboard not available.');
                    }
                    showNotice('Cart link copied to clipboard.', 'success', data.data.link);
                }catch(err){
                    showNotice(err && err.message ? err.message : 'Unexpected error.', 'error');
                }finally{
                    btn.disabled = false;
                    btn.textContent = original;
                }
            });
        }
        bind();
        $(document.body).on('wc_fragments_loaded wc_fragments_refreshed updated_wc_div', bind);
    })(jQuery);
    JS;

    if ( function_exists( 'wc_enqueue_js' ) ) {
        wc_enqueue_js( $js );
    } else {
        echo '<script>' . $js . '</script>';
    }
}
add_action( 'wp_footer', 'vortex_cart_inline_script', 20 );

/* --- AJAX create cart link --- */
function vortex_ajax_create() {
    if ( ! isset( $_POST['_vortex_nonce'] ) || ! wp_verify_nonce( $_POST['_vortex_nonce'], 'vortex-create' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
    }
    if ( ! function_exists( 'WC' ) ) {
        wp_send_json_error( [ 'message' => 'WooCommerce required.' ], 400 );
    }

    $cart = WC()->cart->get_cart();
    if ( empty( $cart ) ) {
        wp_send_json_error( [ 'message' => 'Cart is empty.' ], 400 );
    }

    $export = [];
    foreach ( $cart as $ci ) {
        $export[] = [
            'product_id'     => intval( $ci['product_id'] ),
            'variation_id'   => isset( $ci['variation_id'] ) ? intval( $ci['variation_id'] ) : 0,
            'quantity'       => intval( $ci['quantity'] ),
            'variation'      => isset( $ci['variation'] ) ? $ci['variation'] : [],
            'cart_item_data' => vortex_extract_cart_item_data( $ci ),
        ];
    }

    $token = wp_generate_password( 8, false );
    set_transient( 'vortex_' . $token, $export, DAY_IN_SECONDS ); // 24h

    $link = esc_url( home_url( '/vortex/' . $token ) );
    wp_send_json_success( [ 'link' => $link ] );
}
add_action( 'wp_ajax_vortex_create_cart_link', 'vortex_ajax_create' );
add_action( 'wp_ajax_nopriv_vortex_create_cart_link', 'vortex_ajax_create' );

/* --- Extract only the custom cart item data we need to keep (mockups, engravings, etc.) --- */
function vortex_extract_cart_item_data( $item ) {
    if ( ! is_array( $item ) ) return [];

    $skip = [
        'product_id',
        'variation_id',
        'variation',
        'quantity',
        'data',
        'data_hash',
        'key',
        'line_total',
        'line_subtotal',
        'line_tax',
        'line_tax_data',
        'line_subtotal_tax',
        'stamp',
    ];

    $custom = array_diff_key( $item, array_flip( $skip ) );

    // Avoid carrying internal objects; keep scalars/arrays only.
    foreach ( $custom as $k => $v ) {
        if ( is_object( $v ) ) {
            unset( $custom[ $k ] );
        }
    }

    return $custom;
}

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
        $cart_item_data = isset( $item['cart_item_data'] ) && is_array( $item['cart_item_data'] ) ? $item['cart_item_data'] : [];
        WC()->cart->add_to_cart(
            $item['product_id'],
            $item['quantity'],
            $item['variation_id'],
            isset( $item['variation'] ) ? $item['variation'] : [],
            $cart_item_data
        );
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
