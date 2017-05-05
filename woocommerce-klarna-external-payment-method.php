<?php
/*
Plugin Name: WooCommerce Klarna External Payment Method
Plugin URI: http://krokedil.com
Description: Adds PayPal as an extra payment method in the KCO checkout iframe.
Version: 1.1
Author: Krokedil
Author URI: http://krokedil.com
*/

/**
 * Extends KCO settings with External Payment Method - PayPal settings.
 */
add_filter( 'klarna_checkout_form_fields', 'wkemp_klarna_checkout_form_fields' );
function wkemp_klarna_checkout_form_fields( $settings ) {
	$settings['epm_paypal_settings_title'] = array(
		'title' => __( 'External Payment Method - PayPal', 'woocommerce-gateway-klarna' ),
		'type'  => 'title',
	);
	$settings['epm_paypal_name']           = array(
		'title'       => __( 'Name', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'Title for PayPal payment method. This controls the title which the user sees in the checkout form.', 'woocommerce-gateway-klarna' ),
		'default'     => __( 'PayPal', 'woocommerce-gateway-klarna' )
	);
	$settings['epm_paypal_description']    = array(
		'title'       => __( 'Description', 'woocommerce-gateway-klarna' ),
		'type'        => 'textarea',
		'description' => __( 'Description for PayPal payment method. This controls the description which the user sees in the checkout form.', 'woocommerce-gateway-klarna' ),
		'default'     => ''
	);
	$settings['epm_paypal_img_url']        = array(
		'title'       => __( 'Image url', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'The url to the PayPal payment Icon.', 'woocommerce-gateway-klarna' ),
		'default'     => 'https://www.paypalobjects.com/webstatic/mktg/Logo/pp-logo-100px.png'
	);
	$settings['epm_paypal_fee']            = array(
		'title'       => __( 'Fee', 'woocommerce-gateway-klarna' ),
		'type'        => 'text',
		'description' => __( 'Fee added to the order.', 'woocommerce-gateway-klarna' ),
		'default'     => ''
	);

	return $settings;
}

/**
 * Add PayPal as Payment Method to the KCO iframe.
 */
add_filter( 'kco_create_order', 'krokedil_kco_create_order_paypal' );
function krokedil_kco_create_order_paypal( $create ) {
	$klarna_checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
	$name                     = ( isset( $klarna_checkout_settings['epm_paypal_name'] ) ) ? $klarna_checkout_settings['epm_paypal_name'] : '';
	$image_url                = ( isset( $klarna_checkout_settings['epm_paypal_img_url'] ) ) ? $klarna_checkout_settings['epm_paypal_img_url'] : '';
	$fee                      = ( isset( $klarna_checkout_settings['epm_paypal_fee'] ) ) ? $klarna_checkout_settings['epm_paypal_fee'] : '';
	$description              = ( isset( $klarna_checkout_settings['epm_paypal_description'] ) ) ? $klarna_checkout_settings['epm_paypal_description'] : '';
	$klarna_external_payment  = array(
		'name'         => $name,
		'image_url'    => $image_url,
		'fee'          => $fee,
		'description'  => $description,
	);

	if ( array_key_exists( 'merchant_urls', $create ) ) { // V3
		$klarna_external_payment['redirect_url'] = esc_url( add_query_arg( 'kco-external-payment', 'paypal', get_site_url() ) );
	} else { // V2
		$klarna_external_payment['redirect_uri'] = esc_url( add_query_arg( 'kco-external-payment', 'paypal', get_site_url() ) );
	}

	$klarna_external_payment  = array( $klarna_external_payment );

	$create['external_payment_methods'] = $klarna_external_payment;

	return $create;
}

/**
 * Redirect to PayPal when the Proceed to PayPal button in KCO iframe is clicked.
 */
add_action( 'init', 'kco_redirect_to_paypal' );
function kco_redirect_to_paypal() {
	if ( isset ( $_GET['kco-external-payment'] ) && 'paypal' == $_GET['kco-external-payment'] ) {
		$paypal_gateway = new WC_Gateway_Paypal();
		include_once( ABSPATH . 'wp-content/plugins/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-request.php' );
		$order = wc_get_order( WC()->session->get( 'ongoing_klarna_order' ) );
		if ( $order ) {
			$paypal_request     = new WC_Gateway_Paypal_Request( $paypal_gateway );
			$available_gateways = WC()->payment_gateways->payment_gateways();
			$payment_method     = $available_gateways['paypal'];
			$order->set_payment_method( $payment_method );
			$order->update_status( 'pending' );
			wp_redirect( $paypal_request->get_request_url( $order, $paypal_gateway->testmode ) );
			exit;
		}

	}
}

/**
 * Add customer data to order (in thank you page) if customer exist or if KCO settings say that we should create customer on order completion.
 *
 * Also clear KCO sessions if this was a PayPal purchase that was initiated from the KCO iframe.
 */
add_action( 'woocommerce_thankyou', 'krokedil_kco_paypal_thankyou' );
function krokedil_kco_paypal_thankyou( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( $order && 'paypal' == $order->payment_method && WC()->session->get( 'ongoing_klarna_order' ) ) {
		// Store user id in order so the user can keep track of track it in My account.
		if ( email_exists( $order->billing_email ) ) {

			$user = get_user_by( 'email', $order->billing_email );

			$customer_id = $user->ID;
			update_post_meta( $order->id, '_customer_user', $customer_id );
		} else {
			// Get Klarna Checkout settings
			$checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
			if ( 'yes' === $checkout_settings['create_customer_account'] ) {
				$password     = '';
				$new_customer = krokedil_kco_paypal_create_new_customer( $order->billing_email, $order->billing_email, $password );

				if ( 0 === $new_customer ) { // Creation failed.
					$order->add_order_note( sprintf( __( 'Customer creation failed.', 'klarna' ) ) );
					$customer_id = 0;
				} else { // Creation succeeded.
					$order->add_order_note( sprintf( __( 'New customer created (user ID %s).', 'klarna' ), $new_customer ) );

					// Add customer name.
					update_user_meta( $new_customer, 'first_name', $order->billing_first_name );
					update_user_meta( $new_customer, 'last_name', $order->billing_last_name );

					// Add customer billing address.
					update_user_meta( $new_customer, 'billing_first_name', $order->billing_first_name );
					update_user_meta( $new_customer, 'billing_last_name', $order->billing_last_name );
					update_user_meta( $new_customer, 'billing_address_1', $order->billing_address_1 );
					update_user_meta( $new_customer, 'billing_address_2', $order->billing_address_2 );
					update_user_meta( $new_customer, 'billing_postcode', $order->billing_postcode );
					update_user_meta( $new_customer, 'billing_city', $order->billing_city );
					update_user_meta( $new_customer, 'billing_country', $order->billing_country );
					update_user_meta( $new_customer, 'billing_email', $order->billing_email );
					update_user_meta( $new_customer, 'billing_phone', $order->billing_phone );

					// Add customer shipping address.
					if ( $order->shipping_last_name ) { // Input var okay.
						update_user_meta( $new_customer, 'shipping_first_name', $order->shipping_first_name );
						update_user_meta( $new_customer, 'shipping_last_name', $order->shipping_last_name );
						update_user_meta( $new_customer, 'shipping_address_1', $order->shipping_address_1 );
						update_user_meta( $new_customer, 'shipping_address_2', $order->shipping_address_2 );
						update_user_meta( $new_customer, 'shipping_postcode', $order->shipping_postcode );
						update_user_meta( $new_customer, 'shipping_city', $order->shipping_city );
						update_user_meta( $new_customer, 'shipping_country', $order->shipping_country );
					}

					$customer_id = $new_customer;
					update_post_meta( $order->id, '_customer_user', $customer_id );
					// Test set user.
					wp_set_current_user( $customer_id );
					wc_set_customer_auth_cookie( $customer_id );
					// As we are now logged in, checkout will need to refresh to show logged in data
					WC()->session->set( 'reload_checkout', true );
				}


			}
		}

		// Clear KCO sessions
		WC()->session->__unset( 'klarna_checkout' );
		WC()->session->__unset( 'klarna_checkout_country' );
		WC()->session->__unset( 'ongoing_klarna_order' );
		WC()->session->__unset( 'klarna_order_note' );
	}
}

/**
 * Create a new customer
 *
 * @param string $email Customer email.
 * @param string $username Customer username.
 * @param string $password Customer password.
 *
 * @return mixed WP_error on failure, Int (user ID) on success
 */
function krokedil_kco_paypal_create_new_customer( $email, $username = '', $password = '' ) {
	// Check the e-mail address.
	if ( empty( $email ) || ! is_email( $email ) ) {
		return new WP_Error( 'registration-error', __( 'Please provide a valid email address.', 'woocommerce' ) );
	}

	if ( email_exists( $email ) ) {
		return new WP_Error( 'registration-error', __( 'An account is already registered with your email address. Please login.', 'woocommerce' ) );
	}

	// Handle username creation.
	$username = sanitize_user( current( explode( '@', $email ) ) );

	// Ensure username is unique.
	$append     = 1;
	$o_username = $username;
	while ( username_exists( $username ) ) {
		$username = $o_username . $append;
		$append ++;
	}

	// Handle password creation.
	$password           = wp_generate_password();
	$password_generated = true;

	// WP Validation.
	$validation_errors = new WP_Error();
	do_action( 'woocommerce_register_post', $username, $email, $validation_errors );
	$validation_errors = apply_filters( 'woocommerce_registration_errors', $validation_errors, $username, $email );

	if ( $validation_errors->get_error_code() ) {

		return 0;
	}

	$new_customer_data = apply_filters( 'woocommerce_new_customer_data', array(
		'user_login' => $username,
		'user_pass'  => $password,
		'user_email' => $email,
		'role'       => 'customer',
	) );

	$customer_id = wp_insert_user( $new_customer_data );

	if ( is_wp_error( $customer_id ) ) {
		$validation_errors->add( 'registration-error', '<strong>' . __( 'ERROR', 'woocommerce' ) . '</strong>: ' . __( 'Couldn&#8217;t register you&hellip; please contact us if you continue to have problems.', 'woocommerce' ) );

		return 0;
	}

	// Send New account creation email to customer?
	$checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );

	if ( 'yes' === $checkout_settings['send_new_account_email'] ) {
		do_action( 'woocommerce_created_customer', $customer_id, $new_customer_data, $password_generated );
	}

	return $customer_id;
}