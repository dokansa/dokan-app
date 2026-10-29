<?php
/**
 * Staging only: lets MyFatoorah run in TEST mode through the staging guard, so app payments can be
 * tried end to end with test cards. Nothing here runs on the live site (host must start with
 * "staging."), and only MyFatoorah's test server is let through — never the live one.
 *
 * @package DokanApp
 */

defined( 'ABSPATH' ) || exit;

class Dokan_App_Staging_Pay {
	const TEST_HOST = 'apitest.myfatoorah.com';

	public static function init() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 0 !== strpos( $host, 'staging.' ) ) {
			return;
		}
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( __CLASS__, 'http' ), PHP_INT_MAX, 3 );
	}

	private static function mf_test_mode() {
		$s = get_option( 'woocommerce_myfatoorah_v2_settings', array() );
		return is_array( $s ) && isset( $s['testMode'], $s['apiKey'] ) && 'yes' === $s['testMode'] && 0 === strpos( (string) $s['apiKey'], 'SK_KWT_vVZlnnAqu8' );
	}

	public static function gateways( $available ) {
		if ( ! self::mf_test_mode() || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $available;
		}
		$all = WC()->payment_gateways()->payment_gateways();
		if ( isset( $all['myfatoorah_v2'] ) && 'yes' === $all['myfatoorah_v2']->enabled ) {
			$available = (array) $available;
			$available['myfatoorah_v2'] = $all['myfatoorah_v2'];
		}
		return $available;
	}

	public static function http( $pre, $args, $url ) {
		if ( is_wp_error( $pre ) && self::TEST_HOST === wp_parse_url( (string) $url, PHP_URL_HOST ) && self::mf_test_mode() ) {
			return false; // Let the request to MyFatoorah's TEST server go through.
		}
		return $pre;
	}
}
Dokan_App_Staging_Pay::init();
