<?php
/**
 * FCM HTTP v1 push notifications + device token storage.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Push {

	const META_DEVICES = '_dokan_app_devices';
	const MAX_DEVICES  = 10;
	const TOKEN_CACHE  = 'dokan_app_fcm_access_token';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/* ------------------------------------------------------------------
	 * Devices
	 * ------------------------------------------------------------------ */

	/**
	 * Devices for a user.
	 *
	 * @param int $user_id User.
	 * @return array list of {token, platform, updated}
	 */
	public static function devices( $user_id ) {
		$d = get_user_meta( $user_id, self::META_DEVICES, true );
		return is_array( $d ) ? array_values( $d ) : array();
	}

	/**
	 * Register a device token.
	 *
	 * @param int    $user_id  User.
	 * @param string $token    FCM token.
	 * @param string $platform ios|android.
	 */
	public static function add_device( $user_id, $token, $platform ) {
		// A device token belongs to one user only: remove it from others.
		self::remove_token_everywhere( $token, $user_id );
		$devices = array_filter(
			self::devices( $user_id ),
			function ( $d ) use ( $token ) {
				return isset( $d['token'] ) && $d['token'] !== $token;
			}
		);
		$devices[] = array(
			'token'    => $token,
			'platform' => $platform,
			'updated'  => time(),
		);
		usort(
			$devices,
			function ( $a, $b ) {
				return (int) $b['updated'] - (int) $a['updated'];
			}
		);
		$devices = array_slice( array_values( $devices ), 0, self::MAX_DEVICES );
		update_user_meta( $user_id, self::META_DEVICES, $devices );
	}

	/**
	 * Remove a device token from a user.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token.
	 */
	public static function remove_device( $user_id, $token ) {
		$devices = array_values(
			array_filter(
				self::devices( $user_id ),
				function ( $d ) use ( $token ) {
					return isset( $d['token'] ) && $d['token'] !== $token;
				}
			)
		);
		if ( empty( $devices ) ) {
			delete_user_meta( $user_id, self::META_DEVICES );
		} else {
			update_user_meta( $user_id, self::META_DEVICES, $devices );
		}
	}

	/**
	 * Remove a token from any other user who has it.
	 *
	 * @param string $token  Token.
	 * @param int    $except User id to skip.
	 */
	private static function remove_token_everywhere( $token, $except ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $token ) . '%';
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 20", self::META_DEVICES, $like ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $ids as $uid ) {
			if ( (int) $uid !== (int) $except ) {
				self::remove_device( (int) $uid, $token );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * FCM
	 * ------------------------------------------------------------------ */

	/**
	 * Parsed service account or null.
	 *
	 * @return array|null
	 */
	public static function service_account() {
		$raw = get_option( Dokan_App_Helpers::OPT_FCM_SA, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$sa = json_decode( $raw, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) || empty( $sa['project_id'] ) ) {
			return null;
		}
		return $sa;
	}

	/**
	 * Is push configured?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return null !== self::service_account() && function_exists( 'openssl_sign' );
	}

	/**
	 * OAuth access token (cached).
	 *
	 * @return string
	 */
	private static function access_token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$sa = self::service_account();
		if ( ! $sa || ! function_exists( 'openssl_sign' ) ) {
			return '';
		}
		$now    = time();
		$header = Dokan_App_Helpers::b64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = Dokan_App_Helpers::b64url_encode(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
					'aud'   => ! empty( $sa['token_uri'] ) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$input  = $header . '.' . $claims;
		$key    = openssl_pkey_get_private( $sa['private_key'] );
		if ( ! $key ) {
			Dokan_App_Helpers::log( 'FCM: invalid private key' );
			return '';
		}
		$sig = '';
		if ( ! openssl_sign( $input, $sig, $key, 'sha256WithRSAEncryption' ) ) {
			Dokan_App_Helpers::log( 'FCM: sign failed' );
			return '';
		}
		$jwt = $input . '.' . Dokan_App_Helpers::b64url_encode( $sig );
		$res = wp_remote_post(
			! empty( $sa['token_uri'] ) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 5,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			Dokan_App_Helpers::log( 'FCM token transport: ' . $res->get_error_message() );
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			Dokan_App_Helpers::log( 'FCM token HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return '';
		}
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3000;
		set_transient( self::TOKEN_CACHE, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/**
	 * Send one message to one token.
	 *
	 * @param string $device_token Device token.
	 * @param string $title        Title.
	 * @param string $body         Body.
	 * @param array  $data         String data.
	 * @return string ok|invalid|error
	 */
	private static function send_one( $device_token, $title, $body, $data ) {
		$sa     = self::service_account();
		$access = self::access_token();
		if ( ! $sa || '' === $access ) {
			return 'error';
		}
		$str_data = array();
		foreach ( $data as $k => $v ) {
			if ( null !== $v && '' !== $v ) {
				$str_data[ (string) $k ] = (string) $v;
			}
		}
		$msg = array(
			'message' => array(
				'token'        => $device_token,
				'notification' => array(
					'title' => $title,
					'body'  => $body,
				),
				'data'         => (object) $str_data,
				'android'      => array(
					'priority'     => 'high',
					'notification' => array( 'sound' => 'default' ),
				),
				'apns'         => array(
					'payload' => array(
						'aps' => array( 'sound' => 'default' ),
					),
				),
			),
		);
		$res = wp_remote_post(
			'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send',
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $msg ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'error';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return 'ok';
		}
		$raw = (string) wp_remote_retrieve_body( $res );
		if ( 401 === $code ) {
			delete_transient( self::TOKEN_CACHE );
		}
		if ( 404 === $code || false !== strpos( $raw, 'UNREGISTERED' ) || ( 400 === $code && false !== strpos( $raw, 'registration token' ) ) ) {
			return 'invalid';
		}
		Dokan_App_Helpers::log( 'FCM send HTTP ' . $code . ': ' . substr( $raw, 0, 300 ) );
		return 'error';
	}

	/**
	 * Send to all devices of a user. Never throws.
	 *
	 * @param int    $user_id User.
	 * @param string $title   Title.
	 * @param string $body    Body.
	 * @param array  $data    Data.
	 * @return int Number delivered.
	 */
	public static function send_to_user( $user_id, $title, $body, $data = array() ) {
		$sent = 0;
		try {
			if ( ! self::is_configured() ) {
				return 0;
			}
			foreach ( self::devices( $user_id ) as $d ) {
				if ( empty( $d['token'] ) ) {
					continue;
				}
				$r = self::send_one( $d['token'], $title, $body, $data );
				if ( 'ok' === $r ) {
					$sent++;
				} elseif ( 'invalid' === $r ) {
					self::remove_device( $user_id, $d['token'] );
				}
			}
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'push error: ' . $e->getMessage() );
		}
		return $sent;
	}

	/**
	 * Broadcast to all users with devices.
	 *
	 * @param string $title Title.
	 * @param string $body  Body.
	 * @param array  $data  Data.
	 * @param string $title_en Optional English title (sent to customers using the app in English).
	 * @param string $body_en  Optional English body.
	 * @return array {users, sent}
	 */
	public static function broadcast( $title, $body, $data = array(), $title_en = '', $body_en = '' ) {
		$users = get_users(
			array(
				'meta_key'     => self::META_DEVICES, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);
		$sent = 0;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		$has_en = '' !== $title_en && '' !== $body_en;
		foreach ( $users as $uid ) {
			$en    = $has_en && 'en' === Dokan_App_Helpers::user_lang( (int) $uid );
			$sent += self::send_to_user( (int) $uid, $en ? $title_en : $title, $en ? $body_en : $body, $data );
		}
		return array(
			'users' => count( $users ),
			'sent'  => $sent,
		);
	}

	/**
	 * Order status change → push.
	 *
	 * @param int      $order_id Order.
	 * @param string   $from     From.
	 * @param string   $to       To.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		try {
			if ( ! self::is_configured() ) {
				return;
			}
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$uid = (int) $order->get_customer_id();
			if ( $uid < 1 ) {
				return;
			}
			$is_app = (bool) $order->get_meta( '_dokan_app_order' );
			if ( ! $is_app && empty( self::devices( $uid ) ) ) {
				return;
			}
			$num  = $order->get_order_number();
			$lang = Dokan_App_Helpers::user_lang( $uid );
			$map  = 'en' === $lang ? array(
				'processing'       => array( 'Your order is being prepared', 'We received your order #' . $num . ' and are preparing it now.' ),
				'shipped'          => array( 'Your order has shipped 🚚', 'Order #' . $num . ' is on its way. You can track it in the app.' ),
				'out-for-delivery' => array( 'Out for delivery', 'The courier is on the way with your order #' . $num . '.' ),
				'completed'        => array( 'Your order was delivered ✅', 'We hope you and your pet love order #' . $num . '.' ),
				'cancelled'        => array( 'Your order was cancelled', 'Order #' . $num . ' was cancelled. Contact us if you have any questions.' ),
			) : array(
				'processing'       => array( 'طلبك قيد التجهيز', 'استلمنا طلبك رقم #' . $num . ' ونعمل على تجهيزه الآن.' ),
				'shipped'          => array( 'تم شحن طلبك 🚚', 'طلبك رقم #' . $num . ' في الطريق إليك. يمكنك تتبعه من التطبيق.' ),
				'out-for-delivery' => array( 'طلبك خرج للتوصيل', 'المندوب في طريقه إليك بطلبك رقم #' . $num . '.' ),
				'completed'        => array( 'تم توصيل طلبك ✅', 'نتمنى أن ينال طلبك رقم #' . $num . ' إعجابك وإعجاب حيوانك الأليف.' ),
				'cancelled'        => array( 'تم إلغاء طلبك', 'تم إلغاء طلبك رقم #' . $num . '. للاستفسار تواصل معنا.' ),
			);
			$map = apply_filters( 'dokan_app_status_push_messages', $map, $order, $lang );
			if ( ! isset( $map[ $to ] ) ) {
				return;
			}
			self::send_to_user(
				$uid,
				$map[ $to ][0],
				$map[ $to ][1],
				array(
					'type'     => 'order',
					'order_id' => (string) $order->get_id(),
				)
			);
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'status push error: ' . $e->getMessage() );
		}
	}
}
