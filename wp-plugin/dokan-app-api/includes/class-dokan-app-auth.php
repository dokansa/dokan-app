<?php
/**
 * App token authentication.
 *
 * Token = base64url(json{uid,iat,exp,v}) . "." . base64url(hmac_sha256(<encoded payload>, secret))
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Auth {

	const META_VERSION = '_dokan_app_token_v';
	const LIFETIME     = 15552000; // 180 days.

	/**
	 * User id authenticated by an app token in this request (0 = none).
	 *
	 * @var int
	 */
	private static $token_user_id = 0;

	/**
	 * Whether a token was present but invalid.
	 *
	 * @var bool
	 */
	private static $token_invalid = false;

	/**
	 * Whether we've already evaluated the token this request.
	 *
	 * @var bool
	 */
	private static $evaluated = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 20 );
	}

	/**
	 * Is this a REST API request (works before REST_REQUEST is defined)?
	 *
	 * @return bool
	 */
	public static function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri    = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return false !== strpos( (string) $uri, '/' . trim( $prefix, '/' ) . '/' );
	}

	/**
	 * Extract the raw app token from headers.
	 *
	 * @return string
	 */
	public static function get_request_token() {
		$auth = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$auth = trim( wp_unslash( $_SERVER[ $k ] ) ); // phpcs:ignore
				break;
			}
		}
		if ( '' === $auth && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
						$auth = trim( (string) $value );
						break;
					}
				}
			}
		}
		if ( '' !== $auth && 0 === stripos( $auth, 'Bearer ' ) ) {
			$tok = trim( substr( $auth, 7 ) );
			if ( self::looks_like_app_token( $tok ) ) {
				return $tok;
			}
		}
		$x = Dokan_App_Helpers::header( 'X-Dokan-App-Token' );
		if ( '' !== $x && self::looks_like_app_token( $x ) ) {
			return $x;
		}
		return '';
	}

	/**
	 * Cheap structural check so we don't hijack other plugins' Bearer tokens (e.g. JWT with 3 parts).
	 *
	 * @param string $tok Token.
	 * @return bool
	 */
	private static function looks_like_app_token( $tok ) {
		return (bool) preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $tok ) && strlen( $tok ) < 2048;
	}

	/**
	 * determine_current_user filter.
	 *
	 * @param int|false $user_id Current determination.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( self::$evaluated ) {
			return self::$token_user_id ? self::$token_user_id : $user_id;
		}
		if ( ! self::is_rest_request() ) {
			return $user_id;
		}
		$token = self::get_request_token();
		if ( '' === $token ) {
			return $user_id;
		}
		self::$evaluated = true;
		$uid             = self::validate_token( $token );
		if ( $uid ) {
			self::$token_user_id = $uid;
			return $uid;
		}
		self::$token_invalid = true;
		return $user_id;
	}

	/**
	 * Was the current user authenticated via our token?
	 *
	 * @return bool
	 */
	public static function is_token_authenticated() {
		return self::$token_user_id > 0 && get_current_user_id() === self::$token_user_id;
	}

	/**
	 * Token was sent but rejected.
	 *
	 * @return bool
	 */
	public static function token_was_invalid() {
		return self::$token_invalid;
	}

	/**
	 * May this user use app tokens? (customers only, never staff)
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function user_allowed( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_shop_orders' ) ) {
			return false;
		}
		if ( get_user_meta( $user->ID, '_dokan_app_deletion_requested', true ) ) {
			return false;
		}
		return (bool) apply_filters( 'dokan_app_user_allowed', true, $user );
	}

	/**
	 * Issue a token.
	 *
	 * @param int $user_id User.
	 * @return string|WP_Error
	 */
	public static function issue_token( $user_id ) {
		if ( ! self::user_allowed( $user_id ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_forbidden_account', 'لا يمكن تسجيل الدخول بهذا الحساب عبر التطبيق.', 403 );
		}
		$v = (int) get_user_meta( $user_id, self::META_VERSION, true );
		if ( $v < 1 ) {
			$v = 1;
			update_user_meta( $user_id, self::META_VERSION, $v );
		}
		$now     = time();
		$payload = array(
			'uid' => (int) $user_id,
			'iat' => $now,
			'exp' => $now + self::LIFETIME,
			'v'   => $v,
		);
		$enc     = Dokan_App_Helpers::b64url_encode( wp_json_encode( $payload ) );
		$sig     = Dokan_App_Helpers::b64url_encode( hash_hmac( 'sha256', $enc, Dokan_App_Helpers::get_secret(), true ) );
		return $enc . '.' . $sig;
	}

	/**
	 * Validate a token; returns user id or 0.
	 *
	 * @param string $token Token.
	 * @return int
	 */
	public static function validate_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) ) {
			return 0;
		}
		list( $enc, $sig ) = $parts;
		$secret = get_option( Dokan_App_Helpers::OPT_SECRET );
		if ( ! is_string( $secret ) || '' === $secret ) {
			return 0;
		}
		$expected = Dokan_App_Helpers::b64url_encode( hash_hmac( 'sha256', $enc, $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return 0;
		}
		$json = Dokan_App_Helpers::b64url_decode( $enc );
		if ( false === $json ) {
			return 0;
		}
		$p = json_decode( $json, true );
		if ( ! is_array( $p ) || empty( $p['uid'] ) || empty( $p['exp'] ) || ! isset( $p['v'] ) ) {
			return 0;
		}
		if ( (int) $p['exp'] < time() ) {
			return 0;
		}
		$uid = (int) $p['uid'];
		$cur = (int) get_user_meta( $uid, self::META_VERSION, true );
		if ( $cur < 1 || (int) $p['v'] !== $cur ) {
			return 0;
		}
		if ( ! self::user_allowed( $uid ) ) {
			return 0;
		}
		return $uid;
	}

	/**
	 * Invalidate all tokens for a user.
	 *
	 * @param int $user_id User.
	 */
	public static function bump_version( $user_id ) {
		$v = (int) get_user_meta( $user_id, self::META_VERSION, true );
		update_user_meta( $user_id, self::META_VERSION, max( 1, $v ) + 1 );
	}

	/**
	 * REST permission callback: logged-in customer via app token (or cookie for non-staff).
	 *
	 * @return true|WP_Error
	 */
	public static function permission_user() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			if ( self::$token_invalid ) {
				return Dokan_App_Helpers::error( 'dokan_app_token_invalid', 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى.', 401 );
			}
			return Dokan_App_Helpers::error( 'dokan_app_unauthorized', 'يرجى تسجيل الدخول أولاً.', 401 );
		}
		if ( ! self::user_allowed( $uid ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_forbidden_account', 'لا يمكن استخدام هذا الحساب عبر التطبيق.', 403 );
		}
		return true;
	}
}
