<?php
/**
 * Plugin Name:       JazzyPay
 * Plugin URI:        https://wordpress.org/plugins/jazzypay
 * Description:       Accepts payments from your WooCommerce store via JazzyPay.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.2
 * Author:            JazzyPay
 * Author URI:        https://jazzypay.com
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'woocommerce_jazzypay_add_gateway_class' );
function woocommerce_jazzypay_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Jazzypay_Gateway';
	return $gateways;
}

/**
 * Add plugin action links.
 */
function woocommerce_jazzypay_settings_link($links) { 
	$settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=jazzypay">Settings</a>'; 
	array_unshift($links, $settings_link); 
	return $links; 
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woocommerce_jazzypay_settings_link' );

add_action( 'plugins_loaded', 'woocommerce_jazzypay_init' );

function woocommerce_jazzypay_init() {

	class WC_Jazzypay_Gateway extends WC_Payment_Gateway {

		public function __construct() {

			$this->id = 'jazzypay';
			$this->icon = '';
			$this->has_fields = false; 
			$this->method_title = 'JazzyPay';
			$this->method_description = 'Accept payments via JazzyPay';
			$this->supports = array(
				'products'
			);

			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );
			$this->client_id = $this->testmode ? $this->get_option( 'test_client_id' ) : $this->get_option( 'client_id' );
			$this->client_secret = $this->testmode ? $this->get_option( 'test_client_secret' ) : $this->get_option( 'client_secret' );
			$this->base_path = $this->testmode ? $this->get_option( 'test_base_path' ) : $this->get_option( 'base_path' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_jazzypay', array( $this, 'payment_callback' ) );
		}

		public function init_form_fields(){

			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable JazzyPay Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'JazzyPay',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay using JazzyPay payment gateway.',
				),
				'testmode' => array(
					'title'       => 'Test mode',
					'label'       => 'Enable Test Mode',
					'type'        => 'checkbox',
					'description' => 'Place the payment gateway in test mode using test API keys.',
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'test_base_path'  => array(
					'title'		  => 'Test Base Path',
					'type'		  => 'text'
				),
				'test_client_id'  => array(
					'title'       => 'Test Client ID',
					'type'        => 'text'
				),
				'test_client_secret' => array(
					'title'       => 'Test Client Secret Key',
					'type'        => 'password',
				),
				'base_path'  => array(
					'title'		  => 'Live Base Path',
					'type'		  => 'text'
				),
				'client_id' => array(
					'title'       => 'Live Client ID',
					'type'        => 'text'
				),
				'client_secret' => array(
					'title'       => 'Live Client Secret Key',
					'type'        => 'password'
				)
			);
		}

		public function process_payment( $order_id ) {
		 
			$order = wc_get_order( $order_id );

			$currency = $order->data['currency'];
			if ($currency!="PHP") {
				wc_add_notice(  'This transaction cannot be processed due to an unsupported currency.', 'error' );
				return;
			}

			$cancel_url = $order->get_cancel_order_url();
			$callback_url = get_site_url() . '/?wc-api=jazzypay&order_id=' . $order_id;

			$phone = preg_replace('/[^0-9]/', '',  $order->data['billing']['phone']);
			$phone_code = $this->get_phone_code($phone);
			$phone_number = $this->get_phone_number($phone, $phone_code);
			
			$args = array(
				'method' => 'POST',
				'sslverify' => false,
				'headers' => array(
					'Content-Type' => 'application/json',
					'client-id' => $this->client_id,
					'client-secret' => $this->client_secret
				),
				'body' =>json_encode( array(
					'firstName' => $order->data['billing']['first_name'],
					'lastName' => $order->data['billing']['last_name'], 
					'email' => $order->get_billing_email(), 
					'phoneCode' => $phone_code,
					'phoneNumber' => $phone_number,
					'amount' => $order->get_total(),
					'description' => '',
					'traceNo' => $order_id,
					'origin' => 'woocommerce',
					'successUrl' => $callback_url,
					'cancelUrl' => $cancel_url
				))
			);

			$api_initialize_url = $this->base_path . '/api/payment/initialize';
			$response = wp_remote_post($api_initialize_url, $args );
		 
			if( !is_wp_error( $response ) ) {
				$body = json_decode( $response['body'], true );
				if ($body['status'] == 'Success') {
					$order->update_status('pending', __( 'Awaiting payment via JazzyPay', 'woocommerce' ));
					$redirect_url = $body['redirectUrl'];
					return array(
						'result' => 'success',
						'redirect' => $redirect_url
					);
				} else {
					wc_add_notice(  'Please try again.', 'error' );
					return;
				}
			} else {
				wc_add_notice(  'Connection error.', 'error' );
				return;
			}
		 
		}

		private function get_phone_code($phone) {
			$length = strlen($phone);
			$ph_code = '63';
			if ((strpos($phone, '639') === 0 && $length == 12) 
				|| (strpos($phone, '09') === 0 && $length == 11) 
				|| (strpos($phone, '9') === 0 && $length == 10)) {
				return $ph_code;
			} else {
				return '';
			}
		}

		private function get_phone_number($phone, $phone_code) {
			$length = strlen($phone);
			if ($phone_code!='' && $length>=10 && $length<=12) {
				return substr($phone, -10);
			} else {
				return '';
			}
		}

		public function payment_callback() {
			global $woocommerce;

			if(!empty($_GET['order_id']) && !empty($_GET['status'])){
				$order = wc_get_order( sanitize_text_field( $_GET['order_id'] ) );
				$status = sanitize_text_field( $_GET['status'] );

				if ($status == 'Success') { 
					$order->payment_complete();
					$order->reduce_order_stock();
					$url = $this->get_return_url( $order );
					wp_redirect( $url );
				}

				if ($status == 'Failed') {
					wc_add_notice( 'Payment failed. Please try again or select a different payment method.', 'notice' );
					$order->add_order_note('Payment failed.', 'woocommerce' );
					wp_redirect( WC()->cart->get_cart_url() );
				}
			}

			exit;
		}

	}
}