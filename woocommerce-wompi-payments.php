<?php
/*
Plugin Name: Woocommerce Wompi Payments
Description: Cobra en tu tienda con los metodos de pago Wompi
Version: 1.0.0
Author: Juan Fernando Dorado
Author URI: mailto://juanfdp@gmail.com
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
WC tested up to: 6.5
WC requires at least: 3.6
*/

if (!defined( 'ABSPATH' )) exit;

if(!defined('WOOCOMMERCE_WOMPI_PAYMENTS_VERSION')){
    define('WOOCOMMERCE_WOMPI_PAYMENTS_VERSION', '1.0.0');
}

if(!defined('WOOCOMMERCE_WOMPI_PAYMENTS_NAME')){
    define('WOOCOMMERCE_WOMPI_PAYMENTS_NAME', 'Woocommerce Wompi Payments');
}

add_action('plugins_loaded',function (){

    if (!requeriments_woocommerce_wompi_payments()) {
        return;
    }

    wompi_payments_plugin_init()->run_woocommerce_wompi_payments();

},0);

function wompi_payments_plugin_init(){

    static $plugin;
    if (!isset($plugin)){
        require_once('includes/class-woocommerce-wompi-payments-plugin.php');
        $plugin = new Woocommerce_Wompi_Payments_Plugin(__FILE__, WOOCOMMERCE_WOMPI_PAYMENTS_VERSION, WOOCOMMERCE_WOMPI_PAYMENTS_NAME);
    }
    return $plugin;
}

function requeriments_woocommerce_wompi_payments(){

    $openssl_warning = 'Wompi Payments: Requires OpenSSL >= 1.0.1 to be installed on your server';

    if ( ! defined( 'OPENSSL_VERSION_TEXT' ) ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            add_action('admin_notices', function() use($openssl_warning) {
                wompi_payments_admin_notices($openssl_warning);
            });
        }
        return false;
    }

    preg_match( '/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches );
    if ( empty( $matches[1] ) ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            add_action('admin_notices', function() use($openssl_warning) {
                wompi_payments_admin_notices($openssl_warning);
            });
        }
        return false;
    }

    if ( ! version_compare( $matches[1], '1.0.1', '>=' ) ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            add_action('admin_notices', function() use($openssl_warning) {
                wompi_payments_admin_notices($openssl_warning);
            });
        }
        return false;
    }

    if ( !in_array(
        'woocommerce/woocommerce.php',
        apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
        true
    ) ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            $woo = 'Wompi Payments: El plugin de woocommerce debe de estar activo';
            add_action('admin_notices', function() use($woo) {
                wompi_payments_admin_notices($woo);
            });
        }
        return false;
    }

    if (!in_array(get_woocommerce_currency(), array('COP'))){
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            $currency = 'Wompi Payments: Requires COP currencie '
                . sprintf('%s', '<a href="' . admin_url()
                    . 'admin.php?page=wc-settings&tab=general#s2id_woocommerce_currency">'
                    . 'Click here to configure' . '</a>' );
            add_action('admin_notices', function() use($currency) {
                wompi_payments_admin_notices($currency);
            });
        }
        return false;
    }

    $woo_countries = new WC_Countries();
    $default_country = $woo_countries->get_base_country();
    if (!in_array($default_country, array('CO'))){
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            $country = 'Wompi Payments: It requires that the country of the store be in Colombia '
                . sprintf('%s', '<a href="' . admin_url()
                    . 'admin.php?page=wc-settings&tab=general#s2id_woocommerce_currency">'
                    .  'Click here to configure' . '</a>' );
            add_action('admin_notices', function() use($country) {
                wompi_payments_admin_notices($country);
            });
        }
        return false;
    }

    return true;
}

function wompi_payments_admin_notices( $notice ) {
    ?>
    <div class="error notice">
        <p><?php echo $notice; ?></p>
    </div>
    <?php
}