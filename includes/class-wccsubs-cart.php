<?php
/**
 * Cart functionality for converting cart items to subscriptions.
 *
 * @class 	WCCSubs_Cart
 * @version 1.0.0
 */

class WCCSubs_Cart {

	public static function init() {

		// Allow subs to recognize a cart item of any product type as a subscription
		add_filter( 'woocommerce_is_subscription', __CLASS__ . '::is_converted_to_sub', 10, 3 );

		// Add convert-to-sub configuration data to cart items that can be converted
		add_filter( 'woocommerce_add_cart_item', __CLASS__ . '::add_cart_item_convert_to_sub_data', 10, 2 );

		// Load convert-to-sub cart item session data
		add_filter( 'woocommerce_get_cart_item_from_session', __CLASS__ . '::load_convert_to_sub_session_data', 5, 2 );

		// Finalize covert-to-sub product-level/cart-level session data
		add_filter( 'woocommerce_cart_loaded_from_session', __CLASS__ . '::apply_convert_to_sub_session_data', 5 );

		// Save the convert to sub radio button setting when clicking the 'update cart' button
		add_filter( 'woocommerce_update_cart_action_cart_updated', __CLASS__ . '::update_convert_to_sub_options', 10 );

		// Save the convert to sub cart-level setting via ajax
		if ( WCCSubs_Core_Compatibility::is_wc_version_gte_2_4() ) {
			add_action( 'wc_ajax_wccsubs_update_cart_option', __CLASS__ . '::update_convert_to_sub_cart_options' );
		} else {
			add_action( 'wp_ajax_wccsubs_update_cart_option', __CLASS__ . '::update_convert_to_sub_cart_options' );
			add_action( 'wp_ajax_nopriv_wccsubs_update_cart_option', __CLASS__ . '::update_convert_to_sub_cart_options' );
		}
	}

	/**
	 * Ajax handler for saving cart-level "subscribe to cart" preferences.
	 *
	 * @return void
	 */
	public static function update_convert_to_sub_cart_options() {

		check_ajax_referer( 'wccsubs_update_cart_option', 'security' );

		if ( ! defined('WOOCOMMERCE_CART') ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$selected_scheme = '0';

		if ( isset( $_POST[ 'selected_scheme' ] ) ) {
			$selected_scheme = wc_clean( $_POST[ 'selected_scheme' ] );
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! empty( $cart_item[ 'wccsub_data' ] ) ) {
				$cart_item[ 'wccsub_data' ][ 'active_subscription_scheme_id' ] = $selected_scheme;
				WC()->cart->cart_contents[ $cart_item_key ] = self::convert_to_sub( $cart_item );
			}
		}

		WC()->session->set( 'wccsubs-active-scheme-id', $selected_scheme );

		WC()->cart->calculate_totals();

		woocommerce_cart_totals();

		die();
	}

	/**
	 * Updates the convert-to-sub status of a cart item based on the cart item option.
	 *
	 * @param  boolean $updated
	 * @return boolean
	 */
	public static function update_convert_to_sub_options( $updated ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( ! empty( $cart_item[ 'wccsub_data' ] ) && isset( $_POST[ 'cart' ][ $cart_item_key ][ 'convert_to_sub' ] ) ) {

				WC()->cart->cart_contents[ $cart_item_key ][ 'wccsub_data' ][ 'active_subscription_scheme_id' ] = $_POST[ 'cart' ][ $cart_item_key ][ 'convert_to_sub' ];

				$updated = true;
			}
		}

		return $updated;
	}

	/**
	 * Add convert-to-sub subscription data to cart items that can be converted.
	 *
	 * @param array $cart_item
	 * @param int   $product_id
	 */
	public static function add_cart_item_convert_to_sub_data( $cart_item, $product_id ) {

		if ( self::is_convertible_to_sub( $cart_item ) ) {

			$cart_item[ 'wccsub_data' ] = array(
				'active_subscription_scheme_id' => false,
			);
		}

		return $cart_item;
	}

	/**
	 * Load stored convert-to-sub session data.
	 * Cart items are converted to subscriptions here, then Subs code does all the magic.
	 *
	 * @param  array  $cart_item
	 * @param  array  $item_session_values
	 * @return array
	 */
	public static function load_convert_to_sub_session_data( $cart_item, $item_session_values ) {

		if ( isset( $item_session_values[ 'wccsub_data' ] ) ) {
			$cart_item[ 'wccsub_data' ] = $item_session_values[ 'wccsub_data' ];
		}

		return $cart_item;
	}

	/**
	 * Converts a cart item to a subscription.
	 *
	 * @param  array   $cart_item
	 * @return array
	 */
	public static function convert_to_sub( $cart_item ) {

		if ( $active_subscription_scheme = WCCSubs_Schemes::get_active_subscription_scheme( $cart_item ) ) {

			$cart_item[ 'data' ]->is_converted_to_sub = 'yes';

			$cart_item[ 'data' ]->subscription_period          = $active_subscription_scheme[ 'subscription_period' ];
			$cart_item[ 'data' ]->subscription_period_interval = $active_subscription_scheme[ 'subscription_period_interval' ];
			$cart_item[ 'data' ]->subscription_length          = $active_subscription_scheme[ 'subscription_length' ];

		} else {

			$cart_item[ 'data' ]->is_converted_to_sub = 'no';
		}

		return $cart_item;
	}

	/**
	 * Cart items are converted to subscriptions here, then Subs code does all the magic.
	 *
	 * @param  WC_Cart $cart
	 * @return void
	 */
	public static function apply_convert_to_sub_session_data( $cart ) {

		$cart_level_schemes = WCCSubs_Schemes::get_cart_subscription_schemes();

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( isset( $cart_item[ 'wccsub_data' ] ) ) {

				// Initialize subscription scheme data
				$cart_item[ 'wccsub_data' ][ 'active_subscription_scheme_id' ] = WCCSubs_Schemes::set_subscription_scheme_id( $cart_item, $cart_level_schemes );

				// Convert the cart item to a subscription, if needed
				WC()->cart->cart_contents[ $cart_item_key ] = self::convert_to_sub( $cart_item );
			}
		}
	}

	/**
	 * Hooks onto 'woocommerce_is_subscription' to trick Subs into thinking it is dealing with a subscription.
	 * The necessary subscription properties are added to the product in 'load_convert_to_sub_session_data()'.
	 *
	 * @param  boolean    $is
	 * @param  int        $product_id
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function is_converted_to_sub( $is, $product_id, $product ) {

		if ( ! $product ) {
			return $is;
		}

		if ( isset( $product->is_converted_to_sub ) && $product->is_converted_to_sub === 'yes' ) {
			$is = true;
		}

		return $is;
	}

	/**
	 * True if a cart item can be converted from a one-shot purchase to a subscription and vice-versa.
	 * Subscription product types can't be converted to non-sub items.
	 *
	 * @param  array  $cart_item
	 * @return boolean
	 */
	public static function is_convertible_to_sub( $cart_item ) {

		$product_id     = $cart_item[ 'product_id' ];
		$is_convertible = true;

		if ( WC_Subscriptions_Product::is_subscription( $product_id ) ) {
			$is_convertible = false;
		}

		return $is_convertible;
	}
}

WCCSubs_Cart::init();