<?php
/**
 * OTP login: request, verify, customer lookup/creation, senders.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_OTP {

	const TTL          = 300;
	const MAX_ATTEMPTS = 5;

	/**
	 * Transient key for a phone.
	 *
	 * @param string $phone Normalised phone.
	 * @param string $kind  Kind.
	 * @return string
	 */
	private static function key( $phone, $kind ) {
		return 'dokan_app_' . $kind . '_' . substr( hash_hmac( 'sha256', $phone, Dokan_App_Helpers::get_secret() ), 0, 32 );
	}

	/**
	 * Hash a code bound to a phone.
	 *
	 * @param string $phone Phone.
	 * @param string $code  Code.
	 * @return string
	 */
	private static function hash_code( $phone, $code ) {
		return hash_hmac( 'sha256', $phone . '|' . $code, Dokan_App_Helpers::get_secret() );
	}

	/**
	 * Request an OTP.
	 *
	 * @param string $raw_phone Raw phone.
	 * @return array|WP_Error
	 */
	const TEST_CODE = '123456';

	/**
	 * True only on a staging / development copy, never on the live store.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$on   = 0 === strpos( $host, 'staging.' ) || in_array( $env, array( 'staging', 'development', 'local' ), true );
		return (bool) apply_filters( 'dokan_app_otp_test_mode', $on );
	}

	/**
	 * Configured channels, preferred first (the "otp_sender" setting is the preference).
	 *
	 * @return string[] Subset of whatsapp|sms.
	 */
	public static function channels() {
		$ok = array();
		if ( '' !== trim( (string) Dokan_App_Helpers::setting( 'wa_phone_number_id' ) ) && '' !== trim( (string) Dokan_App_Helpers::setting( 'wa_access_token' ) ) && '' !== trim( (string) Dokan_App_Helpers::setting( 'wa_template_name' ) ) ) {
			$ok[] = 'whatsapp';
		}
		if ( '' !== trim( (string) Dokan_App_Helpers::setting( 'sms_url' ) ) ) {
			$ok[] = 'sms';
		}
		if ( 'sms' === Dokan_App_Helpers::setting( 'otp_sender' ) && 2 === count( $ok ) ) {
			$ok = array( 'sms', 'whatsapp' );
		}
		return array_values( (array) apply_filters( 'dokan_app_otp_channels', $ok ) );
	}

	/**
	 * Request an OTP.
	 *
	 * @param string $raw_phone Raw phone.
	 * @param string $channel   Preferred channel (whatsapp|sms|'').
	 * @return array|WP_Error
	 */
	public static function request( $raw_phone, $channel = '' ) {
		$channel = in_array( $channel, array( 'whatsapp', 'sms' ), true ) ? $channel : '';
		$phone = Dokan_App_Helpers::normalize_phone( $raw_phone );
		if ( '' === $phone ) {
			return Dokan_App_Helpers::error( 'dokan_app_invalid_phone', 'رقم الجوال غير صحيح. أدخل رقمًا سعوديًا يبدأ بـ 05.', 400 );
		}

		// Rate limit per phone: 1 / 60 s.
		$phone_rl = self::key( $phone, 'otprl' );
		if ( get_transient( $phone_rl ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_rate_limited', 'يرجى الانتظار دقيقة قبل طلب رمز جديد.', 429 );
		}

		// Rate limit per IP: 5 / hour (fixed window).
		$ip     = Dokan_App_Helpers::client_ip();
		$ip_key = 'dokan_app_otpip_' . md5( $ip );
		$ip_rec = get_transient( $ip_key );
		if ( ! is_array( $ip_rec ) || empty( $ip_rec['start'] ) || ( time() - (int) $ip_rec['start'] ) >= HOUR_IN_SECONDS ) {
			$ip_rec = array(
				'start' => time(),
				'n'     => 0,
			);
		}
		if ( (int) $ip_rec['n'] >= (int) apply_filters( 'dokan_app_otp_ip_limit', 5 ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_rate_limited', 'تجاوزت الحد المسموح من المحاولات. حاول مرة أخرى لاحقًا.', 429 );
		}

		if ( self::test_mode() ) {
			// Staging only: nothing can be sent from there, so a fixed code is used.
			$code = self::TEST_CODE;
			$chs  = self::channels();
			$used = '' !== $channel ? $channel : ( $chs ? $chs[0] : 'whatsapp' );
		} else {
			$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$used = self::send( $phone, $code, $channel );
			if ( is_wp_error( $used ) ) {
				return $used;
			}
		}

		$ip_rec['n'] = (int) $ip_rec['n'] + 1;
		$remaining   = HOUR_IN_SECONDS - ( time() - (int) $ip_rec['start'] );
		set_transient( $ip_key, $ip_rec, max( 60, $remaining ) );
		set_transient( $phone_rl, 1, 60 );
		set_transient(
			self::key( $phone, 'otp' ),
			array(
				'h'        => self::hash_code( $phone, $code ),
				'attempts' => 0,
				'exp'      => time() + self::TTL,
			),
			self::TTL
		);

		return array(
			'ok'      => true,
			'ttl'     => self::TTL,
			'channel' => $used,
		);
	}

	/**
	 * Verify an OTP and log in / register.
	 *
	 * @param string $raw_phone Phone.
	 * @param string $code      Code.
	 * @param string $name      Optional name for new accounts.
	 * @return array|WP_Error
	 */
	public static function verify( $raw_phone, $code, $name = '' ) {
		$phone = Dokan_App_Helpers::normalize_phone( $raw_phone );
		$code  = preg_replace( '/\D+/', '', strtr( (string) $code, array( '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) ) );
		if ( '' === $phone ) {
			return Dokan_App_Helpers::error( 'dokan_app_invalid_phone', 'رقم الجوال غير صحيح.', 400 );
		}
		if ( 6 !== strlen( $code ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_invalid_code', 'رمز التحقق يجب أن يتكون من 6 أرقام.', 400 );
		}
		$key = self::key( $phone, 'otp' );
		$rec = get_transient( $key );
		if ( ! is_array( $rec ) || empty( $rec['h'] ) || ( isset( $rec['exp'] ) && (int) $rec['exp'] < time() ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_code_expired', 'انتهت صلاحية رمز التحقق، يرجى طلب رمز جديد.', 400 );
		}
		if ( (int) $rec['attempts'] >= self::MAX_ATTEMPTS ) {
			delete_transient( $key );
			return Dokan_App_Helpers::error( 'dokan_app_too_many_attempts', 'تجاوزت عدد المحاولات المسموح. يرجى طلب رمز جديد.', 429 );
		}
		if ( ! hash_equals( (string) $rec['h'], self::hash_code( $phone, $code ) ) ) {
			$rec['attempts'] = (int) $rec['attempts'] + 1;
			$left            = max( 1, (int) $rec['exp'] - time() );
			if ( $rec['attempts'] >= self::MAX_ATTEMPTS ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $rec, $left );
			}
			return Dokan_App_Helpers::error( 'dokan_app_wrong_code', 'رمز التحقق غير صحيح.', 400 );
		}

		$user_id = self::find_user_by_phone( $phone );
		if ( ! $user_id ) {
			$name = trim( sanitize_text_field( (string) $name ) );
			if ( '' === $name ) {
				// Keep the code valid so the app can resend with the name.
				return Dokan_App_Helpers::error( 'dokan_app_name_required', 'هذا الرقم غير مسجل. يرجى إدخال الاسم لإنشاء حساب جديد.', 422 );
			}
			$user_id = self::create_customer( $phone, $name );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		if ( ! Dokan_App_Auth::user_allowed( $user_id ) ) {
			delete_transient( $key );
			return Dokan_App_Helpers::error( 'dokan_app_forbidden_account', 'لا يمكن تسجيل الدخول بهذا الحساب عبر التطبيق.', 403 );
		}

		delete_transient( $key );

		if ( ! get_user_meta( $user_id, 'billing_phone', true ) ) {
			update_user_meta( $user_id, 'billing_phone', '0' . substr( $phone, 3 ) );
		}
		update_user_meta( $user_id, '_dokan_app_phone_verified', $phone );

		$token = Dokan_App_Auth::issue_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$linked = 0;
		try {
			$linked = Dokan_App_Orders::link_guest_orders( $user_id, $phone );
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'link guest orders error: ' . $e->getMessage() );
		}
		Dokan_App_Helpers::remember_lang( $user_id );
		return array(
			'token'         => $token,
			'user'          => Dokan_App_Helpers::user_shape( $user_id ),
			'linked_orders' => $linked,
		);
	}

	/**
	 * Find an existing user by phone (last 9 digits across known meta keys).
	 *
	 * @param string $phone Normalised phone.
	 * @return int
	 */
	public static function find_user_by_phone( $phone ) {
		global $wpdb;
		$last9 = Dokan_App_Helpers::last9( $phone );
		if ( '' === $last9 ) {
			return 0;
		}
		$keys = Dokan_App_Helpers::phone_meta_keys();
		if ( empty( $keys ) ) {
			return 0;
		}
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$args = array_merge( $keys, array( '%' . $wpdb->esc_like( $last9 ) ) );
		// LIKE '%<last9>' narrows candidates; PHP then compares normalised forms exactly.
		$sql  = "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($in) AND meta_value LIKE %s ORDER BY user_id ASC LIMIT 50";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

		$candidates = array();
		foreach ( (array) $rows as $row ) {
			if ( Dokan_App_Helpers::last9( $row->meta_value ) === $last9 ) {
				$candidates[] = (int) $row->user_id;
			}
		}
		// Also catch formatted values like "055 123 4567" that LIKE missed.
		if ( empty( $candidates ) ) {
			$loose = '%' . $wpdb->esc_like( substr( $last9, -4 ) );
			$args2 = array_merge( $keys, array( $loose ) );
			$rows2 = $wpdb->get_results( $wpdb->prepare( $sql, $args2 ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows2 as $row ) {
				if ( Dokan_App_Helpers::last9( $row->meta_value ) === $last9 ) {
					$candidates[] = (int) $row->user_id;
				}
			}
		}
		$candidates = array_values( array_unique( $candidates ) );
		if ( empty( $candidates ) ) {
			return 0;
		}
		// Prefer a customer account the app is allowed to use.
		foreach ( $candidates as $uid ) {
			if ( Dokan_App_Auth::user_allowed( $uid ) ) {
				return (int) apply_filters( 'dokan_app_find_user_by_phone', $uid, $phone, $candidates );
			}
		}
		return (int) apply_filters( 'dokan_app_find_user_by_phone', $candidates[0], $phone, $candidates );
	}

	/**
	 * Create a new WooCommerce customer for a phone.
	 *
	 * @param string $phone Normalised phone.
	 * @param string $name  Full name.
	 * @return int|WP_Error
	 */
	private static function create_customer( $phone, $name ) {
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_unavailable', 'الخدمة غير متاحة حاليًا.', 503 );
		}
		$username = 'app_' . $phone;
		if ( username_exists( $username ) ) {
			$username .= '_' . wp_rand( 100, 999 );
		}
		$email = $username . '@' . Dokan_App_Helpers::placeholder_domain();

		// Don't send the "new account" email to a placeholder address.
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
		$uid = wc_create_new_customer( $email, $username, wp_generate_password( 24, true, true ) );
		remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );

		if ( is_wp_error( $uid ) ) {
			Dokan_App_Helpers::log( 'Customer create failed: ' . $uid->get_error_code() );
			return Dokan_App_Helpers::error( 'dokan_app_register_failed', 'تعذر إنشاء الحساب، يرجى المحاولة لاحقًا.', 500 );
		}
		$parts = preg_split( '/\s+/u', $name, 2 );
		$first = isset( $parts[0] ) ? $parts[0] : '';
		$last  = isset( $parts[1] ) ? $parts[1] : '';
		wp_update_user(
			array(
				'ID'           => $uid,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $name,
				'nickname'     => $name,
			)
		);
		$local = '0' . substr( $phone, 3 );
		update_user_meta( $uid, 'billing_phone', $local );
		update_user_meta( $uid, 'billing_first_name', $first );
		update_user_meta( $uid, 'billing_last_name', $last );
		update_user_meta( $uid, 'billing_country', 'SA' );
		update_user_meta( $uid, 'shipping_first_name', $first );
		update_user_meta( $uid, 'shipping_last_name', $last );
		update_user_meta( $uid, 'shipping_country', 'SA' );
		update_user_meta( $uid, '_dokan_app_registered', time() );
		do_action( 'dokan_app_customer_created', $uid, $phone );
		return (int) $uid;
	}

	/**
	 * Send a code via filter or built-in sender.
	 *
	 * Tries the chosen channel, then the other configured one.
	 *
	 * @param string $phone   Normalised phone (9665XXXXXXXX).
	 * @param string $code    Code.
	 * @param string $channel Preferred channel ('' = store preference).
	 * @return string|WP_Error Channel used.
	 */
	private static function send( $phone, $code, $channel = '' ) {
		$configured = self::channels();
		$order      = $configured;
		if ( '' !== $channel ) {
			$order = array_values( array_unique( array_merge( array( $channel ), $configured ) ) );
		}
		$handled = apply_filters( 'dokan_app_send_otp', null, $phone, $code, $channel, Dokan_App_Helpers::lang() );
		if ( is_wp_error( $handled ) ) {
			return Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.', 502 );
		}
		if ( null !== $handled && false !== $handled ) {
			$ch = is_string( $handled ) && in_array( $handled, array( 'whatsapp', 'sms' ), true ) ? $handled : ( $order ? $order[0] : 'sms' );
			return $ch;
		}
		$last = null;
		foreach ( $order as $ch ) {
			if ( ! in_array( $ch, $configured, true ) ) {
				continue; // Chosen but not configured: fall back.
			}
			try {
				$r = 'whatsapp' === $ch ? self::send_whatsapp( $phone, $code ) : self::send_sms( $phone, $code );
			} catch ( \Throwable $e ) {
				Dokan_App_Helpers::log( 'OTP send exception (' . $ch . '): ' . $e->getMessage() );
				$r = Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.', 502 );
			}
			if ( true === $r ) {
				return $ch;
			}
			$last = $r;
		}
		if ( is_wp_error( $last ) ) {
			return $last;
		}
		return Dokan_App_Helpers::error( 'dokan_app_no_sender', 'خدمة إرسال رمز التحقق غير مفعّلة حاليًا. يرجى التواصل مع المتجر أو تسجيل الدخول بالبريد وكلمة المرور.', 503 );
	}

	/**
	 * WhatsApp Cloud API authentication template.
	 *
	 * @param string $phone Phone.
	 * @param string $code  Code.
	 * @return true|WP_Error
	 */
	private static function send_whatsapp( $phone, $code ) {
		$pnid  = trim( (string) Dokan_App_Helpers::setting( 'wa_phone_number_id' ) );
		$token = trim( (string) Dokan_App_Helpers::setting( 'wa_access_token' ) );
		$tpl   = trim( (string) Dokan_App_Helpers::setting( 'wa_template_name' ) );
		$lang  = trim( (string) Dokan_App_Helpers::setting( 'wa_language' ) );
		// English template language when the app is in English and an English template is set.
		$lang_en = trim( (string) Dokan_App_Helpers::setting( 'wa_language_en' ) );
		if ( Dokan_App_Helpers::is_en() && '' !== $lang_en ) {
			$lang = $lang_en;
		}
		if ( '' === $pnid || '' === $token || '' === $tpl ) {
			return Dokan_App_Helpers::error( 'dokan_app_no_sender', 'خدمة إرسال رمز التحقق غير مفعّلة حاليًا.', 503 );
		}
		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $phone,
			'type'              => 'template',
			'template'          => array(
				'name'       => $tpl,
				'language'   => array( 'code' => '' !== $lang ? $lang : 'ar' ),
				'components' => array(
					array(
						'type'       => 'body',
						'parameters' => array(
							array(
								'type' => 'text',
								'text' => $code,
							),
						),
					),
					array(
						'type'       => 'button',
						'sub_type'   => 'url',
						'index'      => '0',
						'parameters' => array(
							array(
								'type' => 'text',
								'text' => $code,
							),
						),
					),
				),
			),
		);
		$res  = wp_remote_post(
			'https://graph.facebook.com/v21.0/' . rawurlencode( $pnid ) . '/messages',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Dokan_App_Helpers::log( 'WhatsApp OTP transport error: ' . $res->get_error_message() );
			return Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.', 502 );
		}
		$code_http = (int) wp_remote_retrieve_response_code( $res );
		if ( $code_http < 200 || $code_http >= 300 ) {
			Dokan_App_Helpers::log( 'WhatsApp OTP HTTP ' . $code_http . ': ' . substr( wp_remote_retrieve_body( $res ), 0, 500 ) );
			return Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق عبر واتساب. تأكد أن الرقم مسجل في واتساب.', 502 );
		}
		return true;
	}

	/**
	 * Generic SMS HTTP gateway.
	 *
	 * @param string $phone Phone.
	 * @param string $code  Code.
	 * @return true|WP_Error
	 */
	private static function send_sms( $phone, $code ) {
		$tpl_url = trim( (string) Dokan_App_Helpers::setting( 'sms_url' ) );
		$method  = strtoupper( (string) Dokan_App_Helpers::setting( 'sms_method' ) );
		$msg_tpl = (string) Dokan_App_Helpers::setting_l( 'sms_message' );
		if ( '' === $tpl_url ) {
			return Dokan_App_Helpers::error( 'dokan_app_no_sender', 'خدمة إرسال رمز التحقق غير مفعّلة حاليًا.', 503 );
		}
		if ( false === strpos( $msg_tpl, '{code}' ) ) {
			$msg_tpl = Dokan_App_Helpers::t( 'رمز التحقق: {code}', 'Verification code: {code}' );
		}
		$message = str_replace( '{code}', $code, $msg_tpl );
		$url     = str_replace(
			array( '{phone}', '{message}', '{code}' ),
			array( rawurlencode( $phone ), rawurlencode( $message ), rawurlencode( $code ) ),
			$tpl_url
		);
		if ( 'POST' === $method ) {
			$parts = wp_parse_url( $url );
			$base  = strtok( $url, '?' );
			$query = array();
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
			}
			$res = wp_remote_post(
				$base,
				array(
					'timeout' => 10,
					'body'    => $query,
				)
			);
		} else {
			$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		}
		if ( is_wp_error( $res ) ) {
			Dokan_App_Helpers::log( 'SMS OTP transport error: ' . $res->get_error_message() );
			return Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.', 502 );
		}
		$http = (int) wp_remote_retrieve_response_code( $res );
		if ( $http < 200 || $http >= 300 ) {
			Dokan_App_Helpers::log( 'SMS OTP HTTP ' . $http );
			return Dokan_App_Helpers::error( 'dokan_app_send_failed', 'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.', 502 );
		}
		return true;
	}
}
