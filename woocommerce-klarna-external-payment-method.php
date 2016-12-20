<?php
/*
Plugin Name: WooCommerce Klarna External Payment Method
Plugin URI: http://krokedil.com
Description: Adds PayPal as an extra payment method in the KCO checkout iframe.
Version: 1.0
Author: Krokedil
Author URI: http://krokedil.com
*/

// Add settings
add_filter( 'klarna_checkout_form_fields', 'wkemp_klarna_checkout_form_fields' );

function wkemp_klarna_checkout_form_fields( $settings ) {
	$settings['epm_paypal_settings_title'] = array(
		'title' => __( 'External Payment Method - PayPal', 'woocommerce-gateway-klarna' ),
		'type'  => 'title',
	);
	$settings['epm_paypal_name'] = array(
		'title'       => __( 'Name', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'Title for PayPal payment method. This controls the title which the user sees in the checkout form.', 'woocommerce-gateway-klarna' ),
		'default'     => __( 'PayPal', 'woocommerce-gateway-klarna' )
	);
	$settings['epm_paypal_description'] = array(
		'title'       => __( 'Description', 'woocommerce-gateway-klarna' ),
		'type'        => 'textarea',
		'description' => __( 'Description for PayPal payment method. This controls the description which the user sees in the checkout form.', 'woocommerce-gateway-klarna' ),
		'default'     => ''
	);
	$settings['epm_paypal_img_url'] = array(
		'title'       => __( 'Image url', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'The url to the PayPal payment Icon.', 'woocommerce-gateway-klarna' ),
		'default'     => 'https://www.paypalobjects.com/webstatic/mktg/Logo/pp-logo-100px.png'
	);
	$settings['epm_paypal_fee'] = array(
		'title'       => __( 'Fee', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'Fee added to the order.', 'woocommerce-gateway-klarna' ),
		'default'     => ''
	);
	
	return $settings;
}

// New order ($create)
add_filter('kco_create_order', 'krokedil_kco_create_order_paypal');
function krokedil_kco_create_order_paypal( $create ) {
	$klarna_checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
	$name = $klarna_checkout_settings['epm_paypal_name'];
	$image_url = $klarna_checkout_settings['epm_paypal_img_url'];
	$fee = $klarna_checkout_settings['epm_paypal_fee'];
	$description = $klarna_checkout_settings['epm_paypal_description'];
	$klarna_external_payment = array(
		'name' 			=> $name,
		'redirect_url' 	=> esc_url( add_query_arg( 'kco-external-payment', 'paypal', get_site_url() ) ),
		'image_url' 	=> $image_url,
		'fee' 			=> $fee,
		'description' 	=> $description,
	);
	$klarna_external_payment = array( $klarna_external_payment );
		
	$create['external_payment_methods'] = $klarna_external_payment;
	
	return $create;
}

// Redirect to PayPal
add_action('init', 'kco_redirect_to_paypal' );
function kco_redirect_to_paypal() {
	if( isset ( $_GET['kco-external-payment'] ) && 'paypal' == $_GET['kco-external-payment'] ) {
		$paypal_gateway = new WC_Gateway_Paypal();
  		include_once( ABSPATH . 'wp-content/plugins/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-request.php' );
		$order          = wc_get_order( WC()->session->get( 'ongoing_klarna_order' ) );
		if( $order ) {
			$paypal_request = new WC_Gateway_Paypal_Request( $paypal_gateway );
			$available_gateways = WC()->payment_gateways->payment_gateways();
			$payment_method     = $available_gateways['paypal'];
			$order->set_payment_method( $payment_method );
			$order->update_status( 'pending' );
			wp_redirect( $paypal_request->get_request_url( $order, $paypal_gateway->testmode ) );
			exit;
		}
		
	}
}