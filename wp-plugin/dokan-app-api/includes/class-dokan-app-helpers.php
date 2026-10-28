<?php
/**
 * Shared helpers.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Helpers {

	const OPT_SECRET   = 'dokan_app_secret';
	const OPT_SETTINGS = 'dokan_app_settings';
	const OPT_FCM_SA   = 'dokan_app_fcm_service_account';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'otp_sender'           => 'none', // none | whatsapp | sms.
			'wa_phone_number_id'   => '',
			'wa_access_token'      => '',
			'wa_template_name'     => '',
			'wa_language'          => 'ar',
			'sms_url'              => '',
			'sms_method'           => 'GET',
			'sms_message'          => 'رمز التحقق لتطبيق دكان: {code}',
			'chatwoot_base_url'    => '',
			'chatwoot_token'       => '',
			'whatsapp'             => '',
			'riyadh_cutoff'        => '15:00',
			'delivery_note_riyadh' => 'التوصيل داخل الرياض في نفس اليوم للطلبات قبل الساعة 3 عصرًا',
			'delivery_note_ksa'    => 'التوصيل لباقي مناطق المملكة خلال 2-7 أيام عمل',
			'banners'              => array(),
			'min_ios'              => '1.0.0',
			'min_android'          => '1.0.0',
		);
	}

	/**
	 * Get a setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function setting( $key ) {
		$all = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all = wp_parse_args( $all, self::defaults() );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Token secret; generated once.
	 *
	 * @return string
	 */
	public static function get_secret() {
		$secret = get_option( self::OPT_SECRET );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPT_SECRET, $secret, false );
		}
		return $secret;
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Data.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Normalise a Saudi mobile number to 9665XXXXXXXX. Returns '' if invalid.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	public static function normalize_phone( $phone ) {
		$phone = (string) $phone;
		// Convert Arabic-Indic / Persian digits.
		$phone  = strtr(
			$phone,
			array(
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			)
		);
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( 0 === strpos( $digits, '00966' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '9660' ) ) {
			$digits = '966' . substr( $digits, 4 );
		}
		if ( 0 === strpos( $digits, '966' ) ) {
			$local = substr( $digits, 3 );
		} elseif ( 0 === strpos( $digits, '05' ) ) {
			$local = substr( $digits, 1 );
		} else {
			$local = $digits;
		}
		if ( ! preg_match( '/^5\d{8}$/', $local ) ) {
			return '';
		}
		return '966' . $local;
	}

	/**
	 * Last 9 digits of any phone string.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function last9( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $digits ) >= 9 ? substr( $digits, -9 ) : '';
	}

	/**
	 * Build a WP_Error with Arabic message.
	 *
	 * @param string $code    Code.
	 * @param string $message Arabic message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Client IP (REMOTE_ADDR; proxy headers only if filter allows).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( apply_filters( 'dokan_app_trust_proxy_headers', false ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) );
					$cand  = trim( $parts[0] );
					if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
						$ip = $cand;
						break;
					}
				}
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Read a request header from $_SERVER robustly.
	 *
	 * @param string $name Header name, e.g. 'X-Dokan-App'.
	 * @return string
	 */
	public static function header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
		}
		if ( isset( $_SERVER[ 'REDIRECT_' . $key ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER[ 'REDIRECT_' . $key ] ) ) );
		}
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $k => $v ) {
					if ( 0 === strcasecmp( $k, $name ) ) {
						return trim( sanitize_text_field( $v ) );
					}
				}
			}
		}
		return '';
	}

	/**
	 * Is this request coming from the app (X-Dokan-App header)?
	 *
	 * @return bool
	 */
	public static function is_app_request() {
		return '' !== self::header( 'X-Dokan-App' );
	}

	/**
	 * App platform from header.
	 *
	 * @return string ios|android|unknown
	 */
	public static function app_platform() {
		$p = strtolower( self::header( 'X-Dokan-App-Platform' ) );
		return in_array( $p, array( 'ios', 'android' ), true ) ? $p : 'unknown';
	}

	/**
	 * Find the user's phone (normalised) from known meta keys.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function user_phone( $user_id ) {
		foreach ( self::phone_meta_keys() as $key ) {
			$v = get_user_meta( $user_id, $key, true );
			if ( is_string( $v ) && '' !== $v ) {
				$n = self::normalize_phone( $v );
				if ( '' === $n && 'digits_phone_no' === $key ) {
					$n = self::normalize_phone( '966' . ltrim( $v, '0' ) );
				}
				if ( '' !== $n ) {
					return $n;
				}
			}
		}
		return '';
	}

	/**
	 * Meta keys used by WooCommerce and phone-login plugins.
	 *
	 * @return string[]
	 */
	public static function phone_meta_keys() {
		return apply_filters( 'dokan_app_phone_meta_keys', array( 'billing_phone', 'digits_phone', 'digits_phone_no', 'phone', 'mobile' ) );
	}

	/**
	 * Public user shape.
	 *
	 * @param WP_User|int $user User.
	 * @return array
	 */
	public static function user_shape( $user ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}
		if ( ! $user ) {
			return array();
		}
		$email = (string) $user->user_email;
		if ( self::is_placeholder_email( $email ) ) {
			$email = '';
		}
		return array(
			'id'           => (int) $user->ID,
			'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
			'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
			'display_name' => (string) $user->display_name,
			'email'        => $email,
			'phone'        => self::user_phone( $user->ID ),
		);
	}

	/**
	 * Placeholder domain for email-less app accounts.
	 *
	 * @return string
	 */
	public static function placeholder_domain() {
		return 'app.invalid';
	}

	/**
	 * Is the email one of our placeholders?
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public static function is_placeholder_email( $email ) {
		$suffix = '@' . self::placeholder_domain();
		return substr( strtolower( (string) $email ), -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Product image URL (woocommerce_thumbnail) or ''.
	 *
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	public static function product_image( $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return '';
		}
		$img_id = $product->get_image_id();
		if ( ! $img_id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$img_id = $parent ? $parent->get_image_id() : 0;
		}
		if ( ! $img_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' );
		return $url ? $url : '';
	}

	/**
	 * Safe date string (ISO 8601) from WC_DateTime.
	 *
	 * @param mixed $date WC_DateTime|null.
	 * @return string|null
	 */
	public static function iso_date( $date ) {
		if ( $date && is_object( $date ) && method_exists( $date, 'format' ) ) {
			return $date->format( 'c' );
		}
		return null;
	}

	/**
	 * Money as a numeric string with store decimals.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return number_format( (float) $amount, $dec, '.', '' );
	}

	/**
	 * Riyadh timezone.
	 *
	 * @return DateTimeZone
	 */
	public static function riyadh_tz() {
		return new DateTimeZone( 'Asia/Riyadh' );
	}

	/**
	 * Log helper (only when WP_DEBUG).
	 *
	 * @param string $msg Message.
	 */
	public static function log( $msg ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $msg, array( 'source' => 'dokan-app' ) );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[dokan-app] ' . $msg ); // phpcs:ignore
		}
	}
}
