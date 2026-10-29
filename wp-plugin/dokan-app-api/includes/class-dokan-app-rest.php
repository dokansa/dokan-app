<?php
/**
 * REST routes: /wp-json/dokan-app/v1/...
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_REST {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Woo available?
	 *
	 * @return bool
	 */
	private static function woo() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Public permission (still requires Woo).
	 *
	 * @return true|WP_Error
	 */
	public static function permission_public() {
		if ( ! self::woo() ) {
			return Dokan_App_Helpers::error( 'dokan_app_unavailable', 'الخدمة غير متاحة حاليًا.', 503 );
		}
		return true;
	}

	/**
	 * Auth permission.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_user() {
		$p = self::permission_public();
		if ( true !== $p ) {
			return $p;
		}
		$r = Dokan_App_Auth::permission_user();
		if ( true === $r ) {
			Dokan_App_Helpers::remember_lang( get_current_user_id() );
		}
		return $r;
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$ns   = DOKAN_APP_NS;
		$pub  = array( __CLASS__, 'permission_public' );
		$auth = array( __CLASS__, 'permission_user' );

		register_rest_route(
			$ns,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'config' ),
				'permission_callback' => $pub,
			)
		);
		register_rest_route(
			$ns,
			'/auth/otp/request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'otp_request' ),
				'permission_callback' => $pub,
				'args'                => array(
					'phone'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'channel' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/auth/otp/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'otp_verify' ),
				'permission_callback' => $pub,
				'args'                => array(
					'phone' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => $pub,
				'args'                => array(
					'username' => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'logout' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'me' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'PATCH, PUT, POST',
					'callback'            => array( __CLASS__, 'me_update' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'me_delete' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			$ns,
			'/pets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'pets_list' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pets_create' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			$ns,
			'/pets/(?P<id>[a-zA-Z0-9]{1,36})',
			array(
				array(
					'methods'             => 'PATCH, PUT, POST',
					'callback'            => array( __CLASS__, 'pets_update' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'pets_delete' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'pets_get' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			$ns,
			'/pets/(?P<id>[a-zA-Z0-9]{1,36})/stock',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'pets_stock' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/devices',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'devices_add' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'devices_remove' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			$ns,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders_list' ),
				'permission_callback' => $auth,
				'args'                => array(
					'page' => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders_get' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'orders_reorder' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/cancel-request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'orders_cancel' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/recommendations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'recommendations' ),
				'permission_callback' => $auth,
				'args'                => array(
					'pet_id' => array(
						'type'              => 'string',
						'sanitize_callback' => array( 'Dokan_App_Pets', 'clean_id' ),
					),
				),
			)
		);

		// v1.1.
		register_rest_route(
			$ns,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => $pub,
				'args'                => array(
					'q'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'pet_id'   => array(
						'type'              => 'string',
						'sanitize_callback' => array( 'Dokan_App_Pets', 'clean_id' ),
					),
					'ai'       => array(
						'default'           => '0',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'log'      => array(
						'default'           => '0',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'per_page' => array(
						'default'           => 40,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/assistant',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'assistant' ),
				'permission_callback' => $pub,
			)
		);
		register_rest_route(
			$ns,
			'/me/purchased',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'me_purchased' ),
				'permission_callback' => $auth,
			)
		);

		// v1.2.
		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'page' ),
				'permission_callback' => $pub,
			)
		);
	}

	/**
	 * Wrap a callback in a catch-all so internal errors never leak details.
	 *
	 * @param callable $fn Callable.
	 * @return mixed
	 */
	private static function safe( $fn ) {
		try {
			$res = call_user_func( $fn );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return rest_ensure_response( $res );
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'REST error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			return Dokan_App_Helpers::error( 'dokan_app_server_error', 'حدث خطأ غير متوقع، يرجى المحاولة لاحقًا.', 500 );
		}
	}

	/**
	 * JSON body as array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function body( $request ) {
		$b = $request->get_json_params();
		if ( ! is_array( $b ) || empty( $b ) ) {
			$b = $request->get_body_params();
		}
		return is_array( $b ) ? $b : array();
	}

	/* ------------------------------------------------------------------
	 * Config
	 * ------------------------------------------------------------------ */

	/**
	 * GET /config.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function config() {
		return self::safe(
			function () {
				$cache_key = self::config_cache_key();
				$cached    = get_transient( $cache_key );
				if ( ! is_array( $cached ) || ! isset( $cached['pet_categories'] ) ) {
					$cats   = self::config_categories();
					$names  = array();
					foreach ( $cats as $cat ) {
						$names[] = $cat['name'];
					}
					$cached = array(
						'categories'       => $cats,
						'pet_categories'   => Dokan_App_Search::pet_categories( $cats ),
						'popular_searches' => Dokan_App_Search::popular_searches( $names ),
						'policies'         => self::policies(),
					);
					set_transient( $cache_key, $cached, 10 * MINUTE_IN_SECONDS );
				}

				$banners = array();
				foreach ( (array) Dokan_App_Helpers::setting( 'banners' ) as $b ) {
					if ( ! empty( $b['image'] ) ) {
						$banners[] = array(
							'image' => esc_url_raw( $b['image'] ),
							'title' => isset( $b['title'] ) ? (string) $b['title'] : '',
							'link'  => isset( $b['link'] ) ? (string) $b['link'] : '',
						);
					}
				}

				$out = array(
					'store_name'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'currency'             => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'SAR',
					'riyadh_cutoff'        => (string) Dokan_App_Helpers::setting( 'riyadh_cutoff' ),
					'delivery_note_riyadh' => (string) Dokan_App_Helpers::setting_l( 'delivery_note_riyadh' ),
					'delivery_note_ksa'    => (string) Dokan_App_Helpers::setting_l( 'delivery_note_ksa' ),
					'whatsapp'             => (string) Dokan_App_Helpers::setting( 'whatsapp' ),
					'chatwoot'             => array(
						'base_url'      => untrailingslashit( (string) Dokan_App_Helpers::setting( 'chatwoot_base_url' ) ),
						'website_token' => (string) Dokan_App_Helpers::setting( 'chatwoot_token' ),
					),
					'payment_methods'      => self::config_payment_methods(),
					'categories'           => $cached['categories'],
					'banners'              => $banners,
					'min_app_version'      => array(
						'ios'     => (string) Dokan_App_Helpers::setting( 'min_ios' ),
						'android' => (string) Dokan_App_Helpers::setting( 'min_android' ),
					),
					// v1.1.
					'popular_searches'     => array_values( (array) $cached['popular_searches'] ),
					'pet_categories'       => (object) $cached['pet_categories'],
					'assistant_enabled'    => Dokan_App_Claude::enabled(),
					// v1.2.
					'otp_channels'         => Dokan_App_OTP::channels(),
					'policies'             => array_values( (array) $cached['policies'] ),
				);
				$res = rest_ensure_response( apply_filters( 'dokan_app_config', $out ) );
				$res->header( 'Cache-Control', 'public, max-age=300' );
				return $res;
			}
		);
	}

	/**
	 * Config cache key (per version and language).
	 *
	 * @param string|null $lang Lang.
	 * @return string
	 */
	public static function config_cache_key( $lang = null ) {
		return 'dokan_app_config_cache_' . DOKAN_APP_VERSION . '_' . ( null === $lang ? Dokan_App_Helpers::lang() : $lang );
	}

	/**
	 * Delete the cached config for every language.
	 */
	public static function flush_config_cache() {
		delete_transient( 'dokan_app_config_cache' );
		delete_transient( 'dokan_app_config_cache_' . DOKAN_APP_VERSION );
		foreach ( Dokan_App_Helpers::languages() as $l ) {
			delete_transient( self::config_cache_key( $l ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Policies (v1.2)
	 * ------------------------------------------------------------------ */

	/**
	 * Page HTML made readable in the app: the site's pages carry their own <style> blocks,
	 * icon-font tags and layout classes, which come out as raw CSS text in a phone view.
	 * Keep only the content (headings, paragraphs, lists, tables, links, emphasis).
	 *
	 * @param string $html Rendered page content.
	 * @return string
	 */
	public static function clean_page_html( $html ) {
		// Whole blocks that are never content.
		$html = preg_replace( '#<(style|script|noscript|svg|form|iframe|button|select|textarea)\b[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<link\b[^>]*>#i', '', $html );
		// Icon-font tags (<i class="fa ..."></i>) are empty boxes outside the website.
		$html = preg_replace( '#<i\b[^>]*class="[^"]*(fa|icon|dashicons)[^"]*"[^>]*>\s*</i>#i', '', $html );
		$allowed = array(
			'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
			'p' => array(), 'br' => array(), 'hr' => array(), 'div' => array(), 'span' => array(), 'section' => array(),
			'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 'small' => array(),
			'ul' => array(), 'ol' => array(), 'li' => array(),
			'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(), 'th' => array(), 'td' => array(),
			'blockquote' => array(),
			'a' => array( 'href' => true ),
			'img' => array( 'src' => true, 'alt' => true ),
		);
		$html = wp_kses( $html, $allowed ); // drops style/class/id attributes and anything else
		// Any CSS left as plain text (e.g. from a page builder) — remove "selector { prop: value; }" runs.
		$html = preg_replace( '#(^|>)([^<]*?\{[^<{}]*:[^<{}]*\}\s*)+#u', '$1', $html );
		// Tidy: drop empty wrappers and repeated breaks.
		for ( $i = 0; $i < 3; $i++ ) {
			$html = preg_replace( '#<(div|span|p|section|strong|b|em|small)>\s*(&nbsp;|\x{00A0})*\s*</\1>#u', '', $html );
		}
		$html = preg_replace( '#(<br\s*/?>\s*){3,}#i', '<br><br>', $html );
		return trim( (string) $html );
	}

	/**
	 * Policy definitions: titles, default slugs, WP option holding a page id.
	 *
	 * @return array
	 */
	public static function policy_defs() {
		return apply_filters(
			'dokan_app_policy_defs',
			array(
				'privacy'  => array(
					'title'  => array( 'سياسة الخصوصية', 'Privacy policy' ),
					'slugs'  => array( 'privacy-policy', 'privacy', 'سياسة-الخصوصية', 'الخصوصية' ),
					'option' => 'wp_page_for_privacy_policy',
					'words'  => array( 'الخصوصية', 'Privacy' ),
				),
				'returns'  => array(
					'title'  => array( 'سياسة الاسترجاع والاستبدال', 'Returns & refunds' ),
					'slugs'  => array( 'refund_returns', 'return-policy', 'returns', 'refund-policy', 'سياسة-الاسترجاع', 'سياسة-الاستبدال-والاسترجاع', 'الاسترجاع-والاستبدال', 'الاسترجاع', 'سياسة-الإرجاع-والالغاء', 'سياسة-الارجاع-والالغاء' ),
					'option' => '',
					'words'  => array( 'سياسة الإرجاع', 'سياسة الارجاع', 'الاسترجاع', 'الاستبدال', 'Return' ),
				),
				'shipping' => array(
					'title'  => array( 'سياسة الشحن والتوصيل', 'Shipping policy' ),
					'slugs'  => array( 'shipping', 'shipping-policy', 'سياسة-الشحن', 'الشحن-والتوصيل', 'الشحن-و-التوصيل', 'الشحن' ),
					'option' => '',
					'words'  => array( 'الشحن', 'التوصيل', 'Shipping' ),
				),
				'terms'    => array(
					'title'  => array( 'الشروط والأحكام', 'Terms & conditions' ),
					'slugs'  => array( 'terms', 'terms-and-conditions', 'الشروط-والأحكام', 'الشروط-والاحكام' ),
					'option' => 'woocommerce_terms_page_id',
					'words'  => array( 'الشروط', 'Terms' ),
				),
			)
		);
	}

	/**
	 * Page id for a policy (setting, else auto-detected), not yet translated. 0 = none.
	 *
	 * @param string $key Policy key.
	 * @return int
	 */
	public static function policy_page( $key ) {
		$defs = self::policy_defs();
		if ( ! isset( $defs[ $key ] ) ) {
			return 0;
		}
		$set = (int) Dokan_App_Helpers::setting( 'policy_' . $key );
		if ( $set < 0 ) {
			return 0;
		}
		if ( $set > 0 ) {
			return 'publish' === get_post_status( $set ) ? $set : 0;
		}
		if ( '' !== $defs[ $key ]['option'] ) {
			$id = (int) get_option( $defs[ $key ]['option'], 0 );
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				return $id;
			}
		}
		foreach ( $defs[ $key ]['slugs'] as $slug ) {
			$p = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $p && 'publish' === $p->post_status ) {
				return (int) $p->ID;
			}
		}
		// Last resort: a published page whose title names the policy (Arabic store titles
		// like «سياسة الإرجاع والالغاء» or «الشحن و التوصيل»), Arabic copy first.
		$words = isset( $defs[ $key ]['words'] ) ? (array) $defs[ $key ]['words'] : array();
		if ( $words ) {
			$cache_key = 'dokan_app_policy_guess_' . $key;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return 'publish' === get_post_status( (int) $cached ) ? (int) $cached : 0;
			}
			$args = array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'fields'           => 'ids',
			);
			if ( function_exists( 'pll_languages_list' ) ) {
				$args['lang'] = '';
			}
			$found = 0;
			foreach ( get_posts( $args ) as $pid ) {
				$title = (string) get_the_title( $pid );
				if ( false !== mb_strpos( $title, 'نموذج' ) || false !== stripos( $title, 'form' ) ) {
					continue; // e.g. «نموذج الارجاع» is a form, not the policy.
				}
				foreach ( $words as $w ) {
					if ( false !== mb_stripos( $title, $w ) ) {
						$found = (int) $pid;
						break 2;
					}
				}
			}
			if ( $found && function_exists( 'pll_get_post' ) ) {
				$ar = (int) pll_get_post( $found, 'ar' );
				if ( $ar > 0 ) {
					$found = $ar;
				}
			}
			set_transient( $cache_key, $found, HOUR_IN_SECONDS );
			return $found;
		}
		return 0;
	}

	/**
	 * Page in the request language (Polylang translation when it exists).
	 *
	 * @param int $id Page id.
	 * @return int
	 */
	private static function translated_page( $id ) {
		if ( $id > 0 && function_exists( 'pll_get_post' ) ) {
			$tr = (int) pll_get_post( $id, Dokan_App_Helpers::lang() );
			if ( $tr > 0 && 'publish' === get_post_status( $tr ) ) {
				return $tr;
			}
		}
		return $id;
	}

	/**
	 * Policies for /config.
	 *
	 * @return array
	 */
	private static function policies() {
		$out = array();
		foreach ( self::policy_defs() as $key => $def ) {
			$pid = self::translated_page( self::policy_page( $key ) );
			if ( $pid < 1 ) {
				continue;
			}
			$out[] = array(
				'key'          => $key,
				'title'        => Dokan_App_Helpers::t( $def['title'][0], $def['title'][1] ),
				'page_id'      => $pid,
				'content_html' => null,
			);
		}
		$text = trim( (string) Dokan_App_Helpers::setting( Dokan_App_Helpers::is_en() ? 'complaints_en' : 'complaints_ar' ) );
		if ( '' === $text ) {
			$text = trim( (string) Dokan_App_Helpers::setting( 'complaints_ar' ) );
		}
		if ( '' !== $text ) {
			$out[] = array(
				'key'          => 'complaints',
				'title'        => Dokan_App_Helpers::t( 'الشكاوى والإلغاء', 'Complaints & cancellation' ),
				'page_id'      => null,
				'content_html' => wp_kses_post( wpautop( esc_html( $text ) ) ),
			);
		}
		return apply_filters( 'dokan_app_policies', $out );
	}

	/**
	 * Is the page one of the policy pages (any language)?
	 *
	 * @param int $id Page id.
	 * @return bool
	 */
	private static function is_policy_page( $id ) {
		foreach ( array_keys( self::policy_defs() ) as $key ) {
			$pid = self::policy_page( $key );
			if ( $pid < 1 ) {
				continue;
			}
			$ids = array( $pid );
			if ( function_exists( 'pll_get_post_translations' ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) pll_get_post_translations( $pid ) ) );
			}
			if ( in_array( (int) $id, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * GET /pages/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function page( $request ) {
		return self::safe(
			function () use ( $request ) {
				$id = absint( $request['id'] );
				if ( $id < 1 || ! self::is_policy_page( $id ) ) {
					return Dokan_App_Helpers::error( 'dokan_app_page_not_found', 'الصفحة غير موجودة.', 404 );
				}
				$pid  = self::translated_page( $id );
				$post = get_post( $pid );
				if ( ! $post || 'publish' !== $post->post_status ) {
					return Dokan_App_Helpers::error( 'dokan_app_page_not_found', 'الصفحة غير موجودة.', 404 );
				}
				$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				$res     = rest_ensure_response(
					array(
						'id'           => (int) $post->ID,
						'title'        => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
						'content_html' => self::clean_page_html( (string) $content ),
					)
				);
				$res->header( 'Cache-Control', 'public, max-age=600' );
				return $res;
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Search / assistant / purchased (v1.1)
	 * ------------------------------------------------------------------ */

	/**
	 * Pet of the current user from a pet_id, or null (auth optional: guests get null).
	 *
	 * @param string $pet_id Pet id.
	 * @return array|null
	 */
	private static function request_pet( $pet_id ) {
		$pet_id = Dokan_App_Pets::clean_id( $pet_id );
		$uid    = get_current_user_id();
		if ( '' === $pet_id || ! $uid ) {
			return null;
		}
		return Dokan_App_Pets::get( $uid, $pet_id );
	}

	/**
	 * GET /search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search( $request ) {
		return self::safe(
			function () use ( $request ) {
				$per = (int) $request->get_param( 'per_page' );
				return Dokan_App_Search::search_response(
					(string) $request->get_param( 'q' ),
					self::request_pet( (string) $request->get_param( 'pet_id' ) ),
					(bool) $request->get_param( 'ai' ),
					(bool) $request->get_param( 'log' ),
					$per > 0 ? $per : 40
				);
			}
		);
	}

	/**
	 * POST /assistant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function assistant( $request ) {
		return self::safe(
			function () use ( $request ) {
				$body     = self::body( $request );
				$messages = Dokan_App_Claude::clean_messages( isset( $body['messages'] ) ? $body['messages'] : null );
				if ( is_wp_error( $messages ) ) {
					return $messages;
				}
				if ( ! Dokan_App_Search::rate_ok( 'as', 30 ) ) {
					return Dokan_App_Helpers::error( 'dokan_app_rate_limited', 'وصلت للحد المسموح من الرسائل، حاول بعد شوي.', 429 );
				}
				$pet = self::request_pet( isset( $body['pet_id'] ) && is_scalar( $body['pet_id'] ) ? (string) $body['pet_id'] : '' );
				return Dokan_App_Claude::assistant( $messages, $pet );
			}
		);
	}

	/**
	 * GET /me/purchased.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me_purchased() {
		return self::safe(
			function () {
				return Dokan_App_Orders::purchased_ids( get_current_user_id() );
			}
		);
	}

	/**
	 * Payment methods.
	 *
	 * @return array
	 */
	private static function config_payment_methods() {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $out;
		}
		// All enabled gateways. Which ones apply to a given order (amount, address) is decided
		// by the Store API cart's own payment_methods, so is_available() is not used here:
		// with no cart in this request, gateways like MyFatoorah/Tabby/Tamara report unavailable.
		$gateways = array();
		try {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $g ) {
				if ( 'yes' === $g->enabled ) {
					$gateways[ $id ] = $g;
				}
			}
		} catch ( \Throwable $e ) {
			$gateways = array();
		}
		foreach ( $gateways as $id => $g ) {
			$out[] = array(
				'id'          => (string) $id,
				'title'       => wp_strip_all_tags( (string) $g->get_title() ),
				'description' => trim( wp_strip_all_tags( (string) $g->get_description() ) ),
			);
		}
		return $out;
	}

	/**
	 * Top-level categories.
	 *
	 * @return array
	 */
	private static function config_categories() {
		$args = array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		);
		// The store runs Polylang (Arabic + English copies): the app shows the Arabic ones.
		if ( function_exists( 'pll_languages_list' ) ) {
			$args['lang'] = apply_filters( 'dokan_app_language', Dokan_App_Helpers::lang() );
		}
		$terms = get_terms( $args );
		$out   = array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		$default = (int) get_option( 'default_product_cat', 0 );
		$skip    = array( 'uncategorized', 'غير مصنف', 'بدون تصنيف' );
		foreach ( $terms as $t ) {
			$slug = urldecode( $t->slug );
			if ( (int) $t->term_id === $default || 0 === strpos( $slug, 'uncategorized' )
				|| in_array( strtolower( trim( $t->name ) ), $skip, true ) || in_array( $slug, $skip, true ) ) {
				continue;
			}
			if ( function_exists( 'pll_get_term_language' ) && isset( $args['lang'] ) ) {
				$lang = pll_get_term_language( $t->term_id );
				if ( $lang && $lang !== $args['lang'] ) {
					continue;
				}
			}
			$thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
			$img   = $thumb ? wp_get_attachment_image_url( $thumb, 'woocommerce_thumbnail' ) : '';
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => wp_specialchars_decode( $t->name, ENT_QUOTES ),
				'slug'  => urldecode( $t->slug ),
				'image' => $img ? $img : null,
				'count' => (int) $t->count,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Auth
	 * ------------------------------------------------------------------ */

	/**
	 * POST /auth/otp/request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function otp_request( $request ) {
		return self::safe(
			function () use ( $request ) {
				return Dokan_App_OTP::request( (string) $request->get_param( 'phone' ), (string) $request->get_param( 'channel' ) );
			}
		);
	}

	/**
	 * POST /auth/otp/verify.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function otp_verify( $request ) {
		return self::safe(
			function () use ( $request ) {
				return Dokan_App_OTP::verify(
					(string) $request->get_param( 'phone' ),
					(string) $request->get_param( 'code' ),
					(string) $request->get_param( 'name' )
				);
			}
		);
	}

	/**
	 * POST /auth/login.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( $request ) {
		return self::safe(
			function () use ( $request ) {
				$username = trim( sanitize_text_field( (string) $request->get_param( 'username' ) ) );
				$password = (string) $request->get_param( 'password' );
				if ( '' === $username || '' === $password ) {
					return Dokan_App_Helpers::error( 'dokan_app_login_missing', 'يرجى إدخال اسم المستخدم وكلمة المرور.', 400 );
				}

				// Simple brute-force protection: 10 failures / 15 min per IP.
				$rl_key = 'dokan_app_login_' . md5( Dokan_App_Helpers::client_ip() );
				$fails  = (int) get_transient( $rl_key );
				if ( $fails >= 10 ) {
					return Dokan_App_Helpers::error( 'dokan_app_rate_limited', 'محاولات كثيرة. حاول مرة أخرى بعد قليل.', 429 );
				}

				// Allow login by phone number too.
				$norm = Dokan_App_Helpers::normalize_phone( $username );
				if ( '' !== $norm && ! is_email( $username ) ) {
					$uid = Dokan_App_OTP::find_user_by_phone( $norm );
					if ( $uid ) {
						$u = get_userdata( $uid );
						if ( $u ) {
							$username = $u->user_login;
						}
					}
				}

				$user = wp_authenticate( $username, $password );
				if ( is_wp_error( $user ) ) {
					set_transient( $rl_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
					return Dokan_App_Helpers::error( 'dokan_app_login_failed', 'بيانات الدخول غير صحيحة.', 401 );
				}
				if ( ! Dokan_App_Auth::user_allowed( $user->ID ) ) {
					return Dokan_App_Helpers::error( 'dokan_app_forbidden_account', 'لا يمكن تسجيل الدخول بهذا الحساب عبر التطبيق.', 403 );
				}
				$token = Dokan_App_Auth::issue_token( $user->ID );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				delete_transient( $rl_key );
				$linked = 0;
				try {
					$linked = Dokan_App_Orders::link_guest_orders( $user->ID );
				} catch ( \Throwable $e ) {
					Dokan_App_Helpers::log( 'link guest orders error: ' . $e->getMessage() );
				}
				Dokan_App_Helpers::remember_lang( $user->ID );
				return array(
					'token'         => $token,
					'user'          => Dokan_App_Helpers::user_shape( $user ),
					'linked_orders' => $linked,
				);
			}
		);
	}

	/**
	 * POST /auth/logout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function logout( $request ) {
		return self::safe(
			function () use ( $request ) {
				$uid  = get_current_user_id();
				$body = self::body( $request );
				if ( ! empty( $body['device_token'] ) && is_string( $body['device_token'] ) ) {
					Dokan_App_Push::remove_device( $uid, sanitize_text_field( $body['device_token'] ) );
				}
				Dokan_App_Auth::bump_version( $uid );
				return array( 'ok' => true );
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Me
	 * ------------------------------------------------------------------ */

	/**
	 * GET /me.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me() {
		return self::safe(
			function () {
				return Dokan_App_Helpers::user_shape( get_current_user_id() );
			}
		);
	}

	/**
	 * PATCH /me.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me_update( $request ) {
		return self::safe(
			function () use ( $request ) {
				$uid    = get_current_user_id();
				$body   = self::body( $request );
				$update = array( 'ID' => $uid );
				$len    = function ( $s ) {
					return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60 ) : substr( $s, 0, 120 );
				};
				if ( array_key_exists( 'first_name', $body ) && is_scalar( $body['first_name'] ) ) {
					$update['first_name'] = $len( trim( sanitize_text_field( (string) $body['first_name'] ) ) );
					update_user_meta( $uid, 'billing_first_name', $update['first_name'] );
				}
				if ( array_key_exists( 'last_name', $body ) && is_scalar( $body['last_name'] ) ) {
					$update['last_name'] = $len( trim( sanitize_text_field( (string) $body['last_name'] ) ) );
					update_user_meta( $uid, 'billing_last_name', $update['last_name'] );
				}
				if ( array_key_exists( 'email', $body ) && is_scalar( $body['email'] ) ) {
					$email = sanitize_email( (string) $body['email'] );
					if ( '' === $email || ! is_email( $email ) ) {
						return Dokan_App_Helpers::error( 'dokan_app_invalid_email', 'البريد الإلكتروني غير صحيح.', 400 );
					}
					$other = email_exists( $email );
					if ( $other && (int) $other !== $uid ) {
						return Dokan_App_Helpers::error( 'dokan_app_email_taken', 'البريد الإلكتروني مستخدم في حساب آخر.', 409 );
					}
					$update['user_email'] = $email;
					update_user_meta( $uid, 'billing_email', $email );
				}
				if ( isset( $update['first_name'] ) || isset( $update['last_name'] ) ) {
					$first = isset( $update['first_name'] ) ? $update['first_name'] : (string) get_user_meta( $uid, 'first_name', true );
					$last  = isset( $update['last_name'] ) ? $update['last_name'] : (string) get_user_meta( $uid, 'last_name', true );
					$dn    = trim( $first . ' ' . $last );
					if ( '' !== $dn ) {
						$update['display_name'] = $dn;
					}
				}
				if ( count( $update ) > 1 ) {
					// Avoid the "email changed" notification to placeholder addresses.
					add_filter( 'send_email_change_email', '__return_false' );
					$r = wp_update_user( $update );
					remove_filter( 'send_email_change_email', '__return_false' );
					if ( is_wp_error( $r ) ) {
						return Dokan_App_Helpers::error( 'dokan_app_update_failed', 'تعذر تحديث البيانات.', 400 );
					}
				}
				return Dokan_App_Helpers::user_shape( $uid );
			}
		);
	}

	/**
	 * DELETE /me — deletion request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me_delete() {
		return self::safe(
			function () {
				$uid = get_current_user_id();
				update_user_meta( $uid, '_dokan_app_deletion_requested', time() );
				delete_user_meta( $uid, Dokan_App_Push::META_DEVICES );
				Dokan_App_Auth::bump_version( $uid );

				$admin   = get_option( 'admin_email' );
				$link    = admin_url( 'user-edit.php?user_id=' . $uid );
				$subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] طلب حذف حساب من التطبيق';
				$message = "طلب عميل حذف حسابه من خلال تطبيق دكان.\n\nرقم المستخدم: {$uid}\nرابط الحساب: {$link}\n\nتم إيقاف دخول الحساب عبر التطبيق. يرجى مراجعة الطلب وحذف البيانات حسب سياسة المتجر.";
				if ( $admin ) {
					wp_mail( $admin, $subject, $message );
				}
				do_action( 'dokan_app_account_deletion_requested', $uid );
				return array( 'ok' => true );
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Pets
	 * ------------------------------------------------------------------ */

	/**
	 * GET /pets.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_list() {
		return self::safe(
			function () {
				return Dokan_App_Pets::list_shapes( get_current_user_id() );
			}
		);
	}

	/**
	 * GET /pets/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_get( $request ) {
		return self::safe(
			function () use ( $request ) {
				$pet = Dokan_App_Pets::get( get_current_user_id(), Dokan_App_Pets::clean_id( $request['id'] ) );
				return $pet ? $pet : Dokan_App_Helpers::error( 'dokan_app_pet_not_found', 'الحيوان الأليف غير موجود.', 404 );
			}
		);
	}

	/**
	 * POST /pets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_create( $request ) {
		return self::safe(
			function () use ( $request ) {
				$r = Dokan_App_Pets::create( get_current_user_id(), self::body( $request ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$res = rest_ensure_response( $r );
				$res->set_status( 201 );
				return $res;
			}
		);
	}

	/**
	 * PATCH /pets/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_update( $request ) {
		return self::safe(
			function () use ( $request ) {
				return Dokan_App_Pets::update( get_current_user_id(), Dokan_App_Pets::clean_id( $request['id'] ), self::body( $request ) );
			}
		);
	}

	/**
	 * DELETE /pets/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_delete( $request ) {
		return self::safe(
			function () use ( $request ) {
				$r = Dokan_App_Pets::delete( get_current_user_id(), Dokan_App_Pets::clean_id( $request['id'] ) );
				return is_wp_error( $r ) ? $r : array( 'ok' => true );
			}
		);
	}

	/**
	 * GET /pets/<id>/stock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pets_stock( $request ) {
		return self::safe(
			function () use ( $request ) {
				$uid = get_current_user_id();
				$pet = Dokan_App_Pets::get( $uid, Dokan_App_Pets::clean_id( $request['id'] ) );
				if ( ! $pet ) {
					return Dokan_App_Helpers::error( 'dokan_app_pet_not_found', 'الحيوان الأليف غير موجود.', 404 );
				}
				return Dokan_App_Orders::stock_for_pet( $uid, $pet );
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Devices
	 * ------------------------------------------------------------------ */

	/**
	 * Clean an FCM token.
	 *
	 * @param mixed $t Raw.
	 * @return string
	 */
	private static function clean_fcm_token( $t ) {
		if ( ! is_string( $t ) ) {
			return '';
		}
		$t = trim( $t );
		return preg_match( '/^[A-Za-z0-9_:\-\.]{20,4096}$/', $t ) ? $t : '';
	}

	/**
	 * POST /devices.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function devices_add( $request ) {
		return self::safe(
			function () use ( $request ) {
				$body     = self::body( $request );
				$token    = self::clean_fcm_token( isset( $body['token'] ) ? $body['token'] : '' );
				$platform = isset( $body['platform'] ) && is_string( $body['platform'] ) ? strtolower( $body['platform'] ) : '';
				if ( '' === $token ) {
					return Dokan_App_Helpers::error( 'dokan_app_invalid_device', 'رمز الجهاز غير صحيح.', 400 );
				}
				if ( ! in_array( $platform, array( 'ios', 'android' ), true ) ) {
					return Dokan_App_Helpers::error( 'dokan_app_invalid_platform', 'نوع الجهاز غير صحيح.', 400 );
				}
				Dokan_App_Push::add_device( get_current_user_id(), $token, $platform );
				return array( 'ok' => true );
			}
		);
	}

	/**
	 * DELETE /devices.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function devices_remove( $request ) {
		return self::safe(
			function () use ( $request ) {
				$body = self::body( $request );
				$raw  = isset( $body['token'] ) ? $body['token'] : $request->get_param( 'token' );
				$tok  = self::clean_fcm_token( $raw );
				if ( '' !== $tok ) {
					Dokan_App_Push::remove_device( get_current_user_id(), $tok );
				}
				return array( 'ok' => true );
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * GET /orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders_list( $request ) {
		return self::safe(
			function () use ( $request ) {
				$page     = max( 1, (int) $request->get_param( 'page' ) );
				$per_page = 20;
				$result   = wc_get_orders(
					array(
						'customer_id' => get_current_user_id(),
						'status'      => Dokan_App_Orders::visible_statuses(),
						'type'        => 'shop_order',
						'limit'       => $per_page,
						'page'        => $page,
						'paginate'    => true,
						'orderby'     => 'date',
						'order'       => 'DESC',
					)
				);
				$out      = array();
				foreach ( $result->orders as $order ) {
					if ( $order instanceof WC_Order ) {
						$out[] = Dokan_App_Orders::summary( $order );
					}
				}
				$res = rest_ensure_response( $out );
				$res->header( 'X-WP-Total', (string) (int) $result->total );
				$res->header( 'X-WP-TotalPages', (string) (int) $result->max_num_pages );
				return $res;
			}
		);
	}

	/**
	 * GET /orders/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders_get( $request ) {
		return self::safe(
			function () use ( $request ) {
				$order = Dokan_App_Orders::get_user_order( absint( $request['id'] ), get_current_user_id() );
				if ( ! $order ) {
					return Dokan_App_Helpers::error( 'dokan_app_order_not_found', 'الطلب غير موجود.', 404 );
				}
				return Dokan_App_Orders::detail( $order );
			}
		);
	}

	/**
	 * POST /orders/<id>/reorder.
	 * Returns {added: 0, items:[{id, quantity, variation?}], skipped}; the app adds the items
	 * to its Store API cart via /cart/add-item (keeps cart session handling inside Store API).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders_reorder( $request ) {
		return self::safe(
			function () use ( $request ) {
				$order = Dokan_App_Orders::get_user_order( absint( $request['id'] ), get_current_user_id() );
				if ( ! $order ) {
					return Dokan_App_Helpers::error( 'dokan_app_order_not_found', 'الطلب غير موجود.', 404 );
				}
				$r = Dokan_App_Orders::reorder_items( $order );
				if ( empty( $r['items'] ) ) {
					return Dokan_App_Helpers::error( 'dokan_app_reorder_empty', 'منتجات هذا الطلب غير متوفرة حاليًا.', 409 );
				}
				return array(
					'added'   => 0,
					'items'   => $r['items'],
					'skipped' => (int) $r['skipped'],
				);
			}
		);
	}

	/**
	 * POST /orders/<id>/cancel-request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders_cancel( $request ) {
		return self::safe(
			function () use ( $request ) {
				$order = Dokan_App_Orders::get_user_order( absint( $request['id'] ), get_current_user_id() );
				if ( ! $order ) {
					return Dokan_App_Helpers::error( 'dokan_app_order_not_found', 'الطلب غير موجود.', 404 );
				}
				$body   = self::body( $request );
				$reason = isset( $body['reason'] ) && is_scalar( $body['reason'] ) ? sanitize_textarea_field( (string) $body['reason'] ) : '';
				$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 500 ) : substr( $reason, 0, 1000 );
				return Dokan_App_Orders::cancel_request( $order, $reason );
			}
		);
	}

	/**
	 * GET /recommendations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function recommendations( $request ) {
		return self::safe(
			function () use ( $request ) {
				$uid    = get_current_user_id();
				$pet_id = (string) $request->get_param( 'pet_id' );
				$pet    = null;
				if ( '' !== $pet_id ) {
					$pet = Dokan_App_Pets::get( $uid, $pet_id );
					if ( ! $pet ) {
						return Dokan_App_Helpers::error( 'dokan_app_pet_not_found', 'الحيوان الأليف غير موجود.', 404 );
					}
				}
				return Dokan_App_Orders::recommendations( $uid, $pet );
			}
		);
	}
}
