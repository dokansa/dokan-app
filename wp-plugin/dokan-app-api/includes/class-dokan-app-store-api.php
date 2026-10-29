<?php
/**
 * WooCommerce Store API integration: nonce bypass for app-token requests,
 * cart extension update callback (payment method), checkout extension schema (pet_id)
 * and app order tagging.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Store_API {

	const NS = 'dokan-app';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_store_api_disable_nonce_check', array( __CLASS__, 'maybe_disable_nonce' ), 10, 1 );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_extensions' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'tag_order_from_request' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_processed' ), 10, 1 );
		// Expose Store API headers to the app (and allow our custom request headers in CORS preflight).
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
	}

	/**
	 * Allow our headers through CORS (harmless for native apps; helps web debug builds).
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public static function cors_headers( $headers ) {
		$headers[] = 'X-Dokan-App';
		$headers[] = 'X-Dokan-App-Token';
		$headers[] = 'X-Dokan-App-Platform';
		$headers[] = 'Cart-Token';
		$headers[] = 'Nonce';
		return array_values( array_unique( $headers ) );
	}

	/**
	 * Skip Store API nonce only for app requests authenticated by our token.
	 *
	 * @param bool $disable Current value.
	 * @return bool
	 */
	public static function maybe_disable_nonce( $disable ) {
		if ( $disable ) {
			return $disable;
		}
		if ( Dokan_App_Helpers::is_app_request() && Dokan_App_Auth::is_token_authenticated() ) {
			return true;
		}
		return $disable;
	}

	/**
	 * Register update callback + checkout schema.
	 */
	public static function register_extensions() {
		try {
			if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
				woocommerce_store_api_register_update_callback(
					array(
						'namespace' => self::NS,
						'callback'  => array( __CLASS__, 'update_callback' ),
					)
				);
			}
			if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema' ) ) {
				woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
						'namespace'       => self::NS,
						'schema_callback' => array( __CLASS__, 'checkout_schema' ),
						'schema_type'     => ARRAY_A,
					)
				);
			}
			// Bundles (WPC Product Bundles): tell the app which lines are the contents of a bundle.
			if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
				woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
						'namespace'       => self::NS,
						'data_callback'   => array( __CLASS__, 'cart_item_data' ),
						'schema_callback' => function () {
							return array(
								'bundled_by' => array( 'type' => 'string', 'readonly' => true, 'context' => array( 'view', 'edit' ) ),
								'is_bundle'  => array( 'type' => 'boolean', 'readonly' => true, 'context' => array( 'view', 'edit' ) ),
							);
						},
						'schema_type'     => ARRAY_A,
					)
				);
			}
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'Store API extension registration failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Cart item extension data.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $item ) {
		$parent = '';
		foreach ( array( 'woosb_parent_key', 'bundled_by', 'woosg_parent_key' ) as $k ) {
			if ( ! empty( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
				$parent = $item[ $k ];
				break;
			}
		}
		return array(
			'bundled_by' => $parent,
			'is_bundle'  => ! empty( $item['woosb_ids'] ) || ! empty( $item['bundled_items'] ),
		);
	}

	/**
	 * Checkout extension schema.
	 *
	 * @return array
	 */
	public static function checkout_schema() {
		return array(
			'pet_id' => array(
				'description' => 'Pet id this order is for',
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'optional'    => true,
				'arg_options' => array(
					'sanitize_callback' => function ( $v ) {
						return null === $v ? null : Dokan_App_Pets::clean_id( $v );
					},
				),
			),
			'lat'      => array(
				'description' => 'Delivery location latitude',
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'optional'    => true,
			),
			'lng'      => array(
				'description' => 'Delivery location longitude',
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'optional'    => true,
			),
			'accuracy' => array(
				'description' => 'Location accuracy in metres',
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'optional'    => true,
			),
		);
	}

	/**
	 * Order meta keys for the customer's map location (filterable so they can also be written
	 * to the keys the website's own map picker uses). Each value may be a key or a list of keys.
	 *
	 * @return array {lat, lng, accuracy}
	 */
	public static function location_meta_keys() {
		return (array) apply_filters(
			'dokan_app_location_meta_keys',
			array(
				'lat'      => '_dokan_app_lat',
				'lng'      => '_dokan_app_lng',
				'accuracy' => '_dokan_app_loc_accuracy',
			)
		);
	}

	/**
	 * Validated location from the checkout extension data, or null.
	 *
	 * @param array $data Extension data.
	 * @return array|null
	 */
	private static function location_from( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['lat'], $data['lng'] ) || ! is_numeric( $data['lat'] ) || ! is_numeric( $data['lng'] ) ) {
			return null;
		}
		$lat = (float) $data['lat'];
		$lng = (float) $data['lng'];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		$acc = isset( $data['accuracy'] ) && is_numeric( $data['accuracy'] ) && (float) $data['accuracy'] >= 0 ? round( (float) $data['accuracy'], 1 ) : null;
		return array(
			'lat'      => round( $lat, 7 ),
			'lng'      => round( $lng, 7 ),
			'accuracy' => $acc,
		);
	}

	/**
	 * POST /cart/extensions {namespace:"dokan-app", data:{payment_method}}.
	 *
	 * @param array $data Data.
	 */
	public static function update_callback( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['payment_method'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$id = sanitize_text_field( (string) $data['payment_method'] );
		if ( '' === $id ) {
			WC()->session->set( 'chosen_payment_method', '' );
			return;
		}
		$gateways = array();
		try {
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		} catch ( \Throwable $e ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
		}
		if ( ! isset( $gateways[ $id ] ) ) {
			return;
		}
		WC()->session->set( 'chosen_payment_method', $id );
		// Some fee plugins also read the POST var during calculate_totals.
		$_POST['payment_method'] = $id; // phpcs:ignore WordPress.Security.NonceVerification
		do_action( 'dokan_app_payment_method_chosen', $id );
		// The /cart/extensions route recalculates totals after this callback returns.
	}

	/**
	 * Tag orders placed from the app.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function tag_order_from_request( $order, $request ) {
		try {
			if ( ! $order instanceof WC_Order || ! Dokan_App_Helpers::is_app_request() ) {
				return;
			}
			$platform = Dokan_App_Helpers::app_platform();
			$order->set_created_via( 'dokan-app' );
			$order->update_meta_data( '_dokan_app_order', 1 );
			$order->update_meta_data( '_dokan_app_platform', $platform );

			$ext    = $request instanceof WP_REST_Request ? $request->get_param( 'extensions' ) : null;
			$pet_id = '';
			if ( is_array( $ext ) && isset( $ext[ self::NS ] ) && is_array( $ext[ self::NS ] ) && ! empty( $ext[ self::NS ]['pet_id'] ) ) {
				$pet_id = Dokan_App_Pets::clean_id( $ext[ self::NS ]['pet_id'] );
			}
			$uid = (int) $order->get_customer_id();
			if ( '' !== $pet_id && $uid > 0 && null !== Dokan_App_Pets::get( $uid, $pet_id ) ) {
				$order->update_meta_data( '_dokan_app_pet_id', $pet_id );
			} else {
				$order->delete_meta_data( '_dokan_app_pet_id' );
			}

			// Customer's map location.
			$loc = self::location_from( is_array( $ext ) && isset( $ext[ self::NS ] ) ? $ext[ self::NS ] : null );
			if ( null !== $loc ) {
				foreach ( self::location_meta_keys() as $field => $keys ) {
					if ( ! array_key_exists( $field, $loc ) || null === $loc[ $field ] ) {
						continue;
					}
					foreach ( (array) $keys as $key ) {
						if ( is_string( $key ) && '' !== $key ) {
							$order->update_meta_data( $key, (string) $loc[ $field ] );
						}
					}
				}
				$order->update_meta_data( '_dokan_app_loc_pending_note', $loc['lat'] . ',' . $loc['lng'] );
			}

			// Logged-in app customer: store their phone as billing phone when missing.
			if ( $uid > 0 && '' === trim( (string) $order->get_billing_phone() ) ) {
				$phone = Dokan_App_Helpers::user_phone( $uid );
				if ( '' !== $phone ) {
					$order->set_billing_phone( (string) apply_filters( 'dokan_app_order_billing_phone', $phone, $order ) ); // 9665XXXXXXXX.
				}
			}
			$order->save();
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'tag order error: ' . $e->getMessage() );
		}
	}

	/**
	 * After checkout processed: make sure tagging stuck and add the note once.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_processed( $order ) {
		try {
			if ( ! $order instanceof WC_Order || ! Dokan_App_Helpers::is_app_request() ) {
				return;
			}
			$platform = (string) $order->get_meta( '_dokan_app_platform' );
			if ( '' === $platform ) {
				$platform = Dokan_App_Helpers::app_platform();
				$order->update_meta_data( '_dokan_app_platform', $platform );
			}
			if ( 'dokan-app' !== $order->get_created_via() ) {
				$order->set_created_via( 'dokan-app' );
			}
			$order->update_meta_data( '_dokan_app_order', 1 );
			if ( ! $order->get_meta( '_dokan_app_noted' ) ) {
				$label = 'ios' === $platform ? 'iOS' : ( 'android' === $platform ? 'Android' : 'iOS/Android' );
				$order->add_order_note( 'طلب من التطبيق (' . $label . ')' );
				$order->update_meta_data( '_dokan_app_noted', 1 );
			}
			$loc = (string) $order->get_meta( '_dokan_app_loc_pending_note' );
			if ( '' !== $loc && ! $order->get_meta( '_dokan_app_loc_noted' ) ) {
				$order->add_order_note( 'موقع العميل على الخريطة: https://maps.google.com/?q=' . $loc );
				$order->update_meta_data( '_dokan_app_loc_noted', 1 );
				$order->delete_meta_data( '_dokan_app_loc_pending_note' );
			}
			$order->save();
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'order processed tag error: ' . $e->getMessage() );
		}
	}
}
