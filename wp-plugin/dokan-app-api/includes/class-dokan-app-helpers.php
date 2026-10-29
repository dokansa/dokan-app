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
			// v1.1 — smart search / assistant.
			'claude_model'         => 'claude-haiku-5-5',
			'assistant_enabled'    => false,
			'popular_searches'     => '',
			'delivery_note_extra'  => 'لا يوجد توصيل يوم الجمعة.',
			// v1.2 — English variants, OTP channels, policies.
			'wa_language_en'          => '',
			'sms_message_en'          => 'Your Dokan app verification code: {code}',
			'delivery_note_riyadh_en' => 'Same-day delivery inside Riyadh for orders placed before 3 PM',
			'delivery_note_ksa_en'    => 'Delivery to the rest of Saudi Arabia within 2-7 working days',
			'delivery_note_extra_en'  => 'No deliveries on Fridays.',
			'popular_searches_en'     => '',
			'policy_privacy'          => 0, // 0 = auto-detect, -1 = none, >0 = page id.
			'policy_returns'          => 0,
			'policy_shipping'         => 0,
			'policy_terms'            => 0,
			'complaints_ar'           => 'شكاوى «لم يصل الطلب» و«منتج تالف» تحتاج رقم الطلب وصورة للطلب. شكاوى التلف تُقبل خلال ٢٤ ساعة من الاستلام. يمكن إلغاء الطلب فقط وهو «قيد التنفيذ»؛ بعد الشحن أو إصدار بوليصة الشحن لا يمكن الإلغاء، وطلب الإلغاء يوقف الطلب إلى أن يُلغى.',
			'complaints_en'           => 'Complaints about an order that did not arrive or a damaged product need the order number and a photo of the order. Damage complaints are accepted within 24 hours of delivery. An order can only be cancelled while it is "Processing"; once it has shipped or a shipping waybill has been issued it cannot be cancelled, and a cancellation request puts the order on hold until it is cancelled.',
		);
	}

	/* ------------------------------------------------------------------
	 * Language (v1.2)
	 * ------------------------------------------------------------------ */

	const META_LANG = '_dokan_app_lang';

	/**
	 * Language forced for the current operation (e.g. a push to one customer).
	 *
	 * @var string|null
	 */
	private static $lang_override = null;

	/**
	 * Supported languages.
	 *
	 * @return string[]
	 */
	public static function languages() {
		return array( 'ar', 'en' );
	}

	/**
	 * Language of the current request: `lang` query param, else Accept-Language, else ar.
	 *
	 * @return string ar|en
	 */
	public static function lang() {
		if ( null !== self::$lang_override ) {
			return self::$lang_override;
		}
		static $detected = null;
		if ( null !== $detected ) {
			return $detected;
		}
		$lang = '';
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) {
			$lang = strtolower( substr( sanitize_key( wp_unslash( $_GET['lang'] ) ), 0, 2 ) );
		} elseif ( isset( $_POST['lang'] ) && is_string( $_POST['lang'] ) ) {
			$lang = strtolower( substr( sanitize_key( wp_unslash( $_POST['lang'] ) ), 0, 2 ) );
		}
		// phpcs:enable
		if ( ! in_array( $lang, self::languages(), true ) ) {
			$lang   = '';
			$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) ) : '';
			if ( '' !== $accept ) {
				$first = substr( trim( $accept ), 0, 2 );
				if ( in_array( $first, self::languages(), true ) ) {
					$lang = $first;
				}
			}
		}
		if ( '' === $lang ) {
			$lang = 'ar';
		}
		$detected = (string) apply_filters( 'dokan_app_request_lang', $lang );
		if ( ! in_array( $detected, self::languages(), true ) ) {
			$detected = 'ar';
		}
		return $detected;
	}

	/**
	 * Was a language explicitly sent with this request?
	 *
	 * @return bool
	 */
	public static function lang_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_GET['lang'] ) || isset( $_POST['lang'] ) || ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
	}

	/**
	 * Is the current language English?
	 *
	 * @return bool
	 */
	public static function is_en() {
		return 'en' === self::lang();
	}

	/**
	 * Pick the Arabic or English string for the current language.
	 *
	 * @param string $ar Arabic.
	 * @param string $en English.
	 * @return string
	 */
	public static function t( $ar, $en ) {
		return self::is_en() ? $en : $ar;
	}

	/**
	 * Run a callback with a forced language (e.g. the customer's stored language).
	 *
	 * @param string   $lang Lang.
	 * @param callable $fn   Callback.
	 * @return mixed
	 */
	public static function with_lang( $lang, $fn ) {
		$prev                = self::$lang_override;
		self::$lang_override = in_array( $lang, self::languages(), true ) ? $lang : 'ar';
		try {
			return call_user_func( $fn );
		} finally {
			self::$lang_override = $prev;
		}
	}

	/**
	 * Customer's last used app language.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function user_lang( $user_id ) {
		$l = (string) get_user_meta( (int) $user_id, self::META_LANG, true );
		return in_array( $l, self::languages(), true ) ? $l : 'ar';
	}

	/**
	 * Remember the request language for the logged-in app customer (for pushes).
	 *
	 * @param int $user_id User.
	 */
	public static function remember_lang( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! self::lang_from_request() ) {
			return;
		}
		$lang = self::lang();
		if ( get_user_meta( $user_id, self::META_LANG, true ) !== $lang ) {
			update_user_meta( $user_id, self::META_LANG, $lang );
		}
	}

	/**
	 * A setting in the current language: `<key>_en` when English and non-empty.
	 *
	 * @param string $key Base key (Arabic value).
	 * @return mixed
	 */
	public static function setting_l( $key ) {
		if ( self::is_en() ) {
			$en = self::setting( $key . '_en' );
			if ( is_string( $en ) && '' !== trim( $en ) ) {
				return $en;
			}
		}
		return self::setting( $key );
	}

	/**
	 * English versions of the Arabic error messages (keyed by the Arabic text).
	 *
	 * @return array
	 */
	private static function error_en() {
		return array(
			'لا يمكن طلب إلغاء هذا الطلب. الإلغاء متاح فقط عندما يكون الطلب قيد التنفيذ وقبل شحنه.' => 'This order can\'t be cancelled. Cancellation is only possible while the order is processing and before it ships.',
			'انتهت صلاحية رمز التحقق، يرجى طلب رمز جديد.'         => 'The verification code has expired. Please request a new one.',
			'البريد الإلكتروني مستخدم في حساب آخر.'                => 'This email is already used by another account.',
			'لا يمكن استخدام هذا الحساب عبر التطبيق.'              => 'This account can\'t be used in the app.',
			'لا يمكن تسجيل الدخول بهذا الحساب عبر التطبيق.'        => 'This account can\'t sign in to the app.',
			'رمز التحقق يجب أن يتكون من 6 أرقام.'                  => 'The verification code must be 6 digits.',
			'رمز الجهاز غير صحيح.'                                 => 'Invalid device token.',
			'البريد الإلكتروني غير صحيح.'                          => 'Invalid email address.',
			'رقم الجوال غير صحيح.'                                 => 'Invalid mobile number.',
			'رقم الجوال غير صحيح. أدخل رقمًا سعوديًا يبدأ بـ 05.'  => 'Invalid mobile number. Enter a Saudi number starting with 05.',
			'نوع الجهاز غير صحيح.'                                 => 'Invalid platform.',
			'بيانات الدخول غير صحيحة.'                             => 'Incorrect login details.',
			'يرجى إدخال اسم المستخدم وكلمة المرور.'                => 'Please enter your username and password.',
			'هذا الرقم غير مسجل. يرجى إدخال الاسم لإنشاء حساب جديد.' => 'This number isn\'t registered. Please enter your name to create an account.',
			'خدمة إرسال رمز التحقق غير مفعّلة حاليًا.'             => 'Sending verification codes is currently unavailable.',
			'خدمة إرسال رمز التحقق غير مفعّلة حاليًا. يرجى التواصل مع المتجر أو تسجيل الدخول بالبريد وكلمة المرور.' => 'Sending verification codes is currently unavailable. Please contact the store or sign in with email and password.',
			'الطلب غير موجود.'                                     => 'Order not found.',
			'تاريخ الميلاد غير صحيح.'                              => 'Invalid birth date.',
			'نوع الطعام غير صحيح.'                                 => 'Invalid food type.',
			'نوع الحيوان غير صحيح.'                                => 'Invalid pet type.',
			'وصلت للحد الأقصى من الحيوانات الأليفة.'               => 'You\'ve reached the maximum number of pets.',
			'يرجى إدخال اسم الحيوان الأليف.'                       => 'Please enter your pet\'s name.',
			'الحيوان الأليف غير موجود.'                            => 'Pet not found.',
			'رابط الصورة غير صحيح.'                                => 'Invalid photo URL.',
			'الوزن غير صحيح.'                                      => 'Invalid weight.',
			'تجاوزت الحد المسموح من المحاولات. حاول مرة أخرى لاحقًا.' => 'Too many attempts. Please try again later.',
			'محاولات كثيرة. حاول مرة أخرى بعد قليل.'               => 'Too many attempts. Please try again shortly.',
			'يرجى الانتظار دقيقة قبل طلب رمز جديد.'                => 'Please wait a minute before requesting a new code.',
			'تعذر إنشاء الحساب، يرجى المحاولة لاحقًا.'             => 'Couldn\'t create the account. Please try again later.',
			'منتجات هذا الطلب غير متوفرة حاليًا.'                  => 'The products in this order are currently unavailable.',
			'تعذر إرسال رمز التحقق عبر واتساب. تأكد أن الرقم مسجل في واتساب.' => 'Couldn\'t send the code via WhatsApp. Make sure the number is registered on WhatsApp.',
			'تعذر إرسال رمز التحقق، يرجى المحاولة لاحقًا.'         => 'Couldn\'t send the verification code. Please try again later.',
			'حدث خطأ غير متوقع، يرجى المحاولة لاحقًا.'             => 'Something went wrong. Please try again later.',
			'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى.'     => 'Your session has expired. Please sign in again.',
			'تجاوزت عدد المحاولات المسموح. يرجى طلب رمز جديد.'     => 'Too many attempts. Please request a new code.',
			'يرجى تسجيل الدخول أولاً.'                             => 'Please sign in first.',
			'الخدمة غير متاحة حاليًا.'                             => 'The service is currently unavailable.',
			'تعذر تحديث البيانات.'                                 => 'Couldn\'t update your details.',
			'رمز التحقق غير صحيح.'                                 => 'Incorrect verification code.',
			// v1.1 / v1.2.
			'يرجى كتابة رسالتك.'                                   => 'Please type your message.',
			'وصلت للحد المسموح من الرسائل، حاول بعد شوي.'          => 'You\'ve reached the message limit. Please try again a bit later.',
			'الصفحة غير موجودة.'                                   => 'Page not found.',
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
	public static function error( $code, $message, $status = 400, $message_en = '' ) {
		if ( self::is_en() ) {
			if ( '' === $message_en ) {
				$map        = self::error_en();
				$message_en = isset( $map[ $message ] ) ? $map[ $message ] : '';
			}
			if ( '' !== $message_en ) {
				$message = $message_en;
			}
		}
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
