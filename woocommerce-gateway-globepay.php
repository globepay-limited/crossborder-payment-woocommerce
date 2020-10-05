<?php
/*
 * Plugin Name: GlobePay - WeChat Pay, Alipay & UnionPay for WooCommerce (英国微信支付，支付宝，银联支付)
 * Description: GlobePay - Accept WeChat Pay, Alipay & UnionPay in WooCommerce, 支持微信、支付宝、银联支付，快捷支付，退款，支付人民币商家收英镑
 * Version: 1.5
 * Author: GlobePay
 * Author URI: https://www.globepay.co
 * Text Domain: GlobePay - WeChat Pay for WooCommerce
 */
if (! defined ( 'ABSPATH' )) exit (); // Exit if accessed directly


// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action( 'plugins_loaded', 'globepay_init', 0 );

function globepay_init() {
	// If the parent WC_Payment_Gateway class doesn't exist
	// it means WooCommerce is not installed on the site
	// so do nothing

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;


	define('GLOBEPAY_FILE',__FILE__);
	define('GLOBEPAY_URL',rtrim(plugin_dir_url(GLOBEPAY_FILE),'/'));

	// If we made it this far, then include our Gateway Class
	include_once('includes/class-wc-globepay-api.php');
	include_once('includes/class-wc-globepay-gateway.php');
	include_once('includes/class-wc-globepay-gateway-alipay.php');


	global $GlobePay;
	$GlobePay= new WC_GlobePay();

	add_action ( 'woocommerce_receipt_'.$GlobePay->id, array ($GlobePay,'wc_receipt'),10,1);
	// add_action('init', array($GlobePay,'notify'),10);


	global $GlobePayAli;
	$GlobePayAli= new WC_GlobePay_Alipay();


	// Now that we have successfully included our class,
	// Lets add it too WooCommerce
	add_filter( 'woocommerce_payment_gateways', 'add_globepay_gateway' );
	function add_globepay_gateway( $methods ) {
		$methods[] = 'WC_GlobePay';
		$methods[] = 'WC_GlobePay_Alipay';

		return $methods;
	}


	// Add custom action links
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'globepay_action_links' );
	function globepay_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=globepay' ) . '">' .  'Settings' . '</a>',
		);

		// Merge our new link with the default ones
		return array_merge( $plugin_links, $links );
	}

	//Show pay type in edit order page for admin.
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'wc_globepay_custom_display_admin', 10, 1 );
	function wc_globepay_custom_display_admin($order){
		$method = get_post_meta( $order->get_id(), '_payment_method', true );
		if($method != 'globepay' && $method != 'globepay_alipay'){
			return;
		}
		$channel = get_post_meta( $order->get_id(), 'channel', true );
		$globepay_order_id = get_post_meta( $order->get_id(), 'globepay_order_id', true );
		echo '<p><strong>'.__( 'Pay Type' ).': </strong> ' . $channel . '</p>';
		echo '<p><strong>'.__( 'GlobePay Order Id' ).':</strong> ' . $globepay_order_id . '</p>';
	}

}



?>
