<?php
/**
 * Optional Claude integration (server side only): intent parsing for /search ai=1 and the
 * conversational /assistant. Every failure falls back to the rule-based answer.
 *
 * Privacy: only the messages (with phone numbers / emails redacted) and the pet's kind, age,
 * weight and food type are sent — never the customer's name, phone, email or address.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Claude {

	const OPT_KEY       = 'dokan_app_claude_key';
	const DOWN          = 'dokan_app_claude_down';
	const API_URL       = 'https://api.anthropic.com/v1/messages';
	const DEFAULT_MODEL = 'claude-haiku-5-5';
	const CACHE_TTL     = 604800; // 7 days.

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * API key (separate non-autoloaded option).
	 *
	 * @return string
	 */
	public static function api_key() {
		$k = get_option( self::OPT_KEY, '' );
		return is_string( $k ) ? trim( $k ) : '';
	}

	/**
	 * Save / clear the key.
	 *
	 * @param string $key Key ('' clears).
	 */
	public static function save_key( $key ) {
		delete_option( self::OPT_KEY );
		if ( '' !== $key ) {
			add_option( self::OPT_KEY, $key, '', 'no' );
		}
		delete_transient( self::DOWN );
	}

	/**
	 * Masked key for the settings page (last 4 chars only).
	 *
	 * @return string
	 */
	public static function key_hint() {
		$k = self::api_key();
		return '' === $k ? '' : '••••' . substr( $k, -4 );
	}

	/**
	 * Model id.
	 *
	 * @return string
	 */
	public static function model() {
		$m = trim( (string) Dokan_App_Helpers::setting( 'claude_model' ) );
		return '' !== $m ? $m : self::DEFAULT_MODEL;
	}

	/**
	 * Key configured and the assistant switch on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== self::api_key() && (bool) Dokan_App_Helpers::setting( 'assistant_enabled' );
	}

	/* ------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * Call the Messages API. Returns the text or WP_Error. Quiet on transport errors (e.g. the
	 * staging guard blocking outbound HTTP): just marks Claude unavailable for a few minutes.
	 *
	 * @param string $system     System prompt.
	 * @param array  $messages   Messages.
	 * @param int    $max_tokens Max tokens.
	 * @param bool   $force      Ignore the "down" flag (connection test).
	 * @return string|WP_Error
	 */
	public static function request( $system, $messages, $max_tokens = 400, $force = false ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'dokan_app_claude_off', 'no key' );
		}
		if ( ! $force && get_transient( self::DOWN ) ) {
			return new WP_Error( 'dokan_app_claude_down', 'unavailable' );
		}
		$res = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 12,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::model(),
						'max_tokens' => (int) $max_tokens,
						'system'     => $system,
						'messages'   => $messages,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			set_transient( self::DOWN, 1, 5 * MINUTE_IN_SECONDS );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			$err = json_decode( $raw, true );
			$typ = is_array( $err ) && isset( $err['error']['type'] ) ? (string) $err['error']['type'] : '';
			Dokan_App_Helpers::log( 'Claude HTTP ' . $code . ( '' !== $typ ? ' ' . $typ : '' ) );
			$pause = in_array( $code, array( 400, 401, 403, 404 ), true ) ? 30 * MINUTE_IN_SECONDS : 2 * MINUTE_IN_SECONDS;
			if ( ! $force ) {
				set_transient( self::DOWN, 1, $pause );
			}
			return new WP_Error( 'dokan_app_claude_http', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		$data = json_decode( $raw, true );
		$text = '';
		if ( is_array( $data ) && ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= (string) $block['text'];
				}
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'dokan_app_claude_empty', 'empty' );
		}
		return $text;
	}

	/**
	 * Connection test for the settings page.
	 *
	 * @return array {ok:bool, code:int}
	 */
	public static function test() {
		$r = self::request( 'Reply with the single word OK.', array( array( 'role' => 'user', 'content' => 'ping' ) ), 10, true );
		if ( is_wp_error( $r ) ) {
			$d = $r->get_error_data();
			return array(
				'ok'   => false,
				'code' => is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 0,
			);
		}
		delete_transient( self::DOWN );
		return array(
			'ok'   => true,
			'code' => 200,
		);
	}

	/**
	 * First JSON object in a text.
	 *
	 * @param string $text Text.
	 * @return array|null
	 */
	private static function extract_json( $text ) {
		$a = strpos( $text, '{' );
		$b = strrpos( $text, '}' );
		if ( false === $a || false === $b || $b <= $a ) {
			return null;
		}
		$j = json_decode( substr( $text, $a, $b - $a + 1 ), true );
		return is_array( $j ) ? $j : null;
	}

	/**
	 * Remove phone numbers and emails from customer text.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function redact( $s ) {
		$s = Dokan_App_Search::digits( (string) $s );
		$s = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $s );
		$s = preg_replace( '/(?:\+|00)?\d[\d\s\-]{7,}\d/', '[phone]', $s );
		return (string) $s;
	}

	/**
	 * Pet profile sent to Claude (no name).
	 *
	 * @param array|null $pet Pet.
	 * @return array|null
	 */
	private static function pet_profile( $pet ) {
		if ( ! is_array( $pet ) ) {
			return null;
		}
		$ctx = Dokan_App_Search::context( $pet );
		return array(
			'kind'       => $pet['kind'],
			'age_months' => $ctx['age_months'],
			'weight_kg'  => isset( $pet['weight_kg'] ) ? $pet['weight_kg'] : null,
			'food_type'  => isset( $pet['food_type'] ) ? $pet['food_type'] : null,
		);
	}

	/* ------------------------------------------------------------------
	 * Intent
	 * ------------------------------------------------------------------ */

	/**
	 * Store lists for the prompt (trimmed).
	 *
	 * @return array {categories[], brands[]}
	 */
	private static function store_lists() {
		$idx  = Dokan_App_Search::index();
		$cats = array();
		foreach ( $idx['cats'] as $ci ) {
			$cats[ $ci['n'] ] = $ci['k'];
		}
		arsort( $cats );
		$brands = array();
		foreach ( Dokan_App_Search::brand_entries_cached() as $e ) {
			$name            = Dokan_App_Helpers::t( $e['label_ar'], $e['label_en'] );
			$brands[ $name ] = $e['k'];
		}
		arsort( $brands );
		return array(
			'categories' => array_slice( array_keys( $cats ), 0, 40 ),
			'brands'     => array_slice( array_keys( $brands ), 0, 60 ),
		);
	}

	/**
	 * Ask Claude for a JSON intent; validated, cached 7 days (per query + pet kind + stage).
	 *
	 * @param string     $query   Query / last user message.
	 * @param array|null $pet     Pet.
	 * @param array      $history Previous turns [{role, content}] (assistant only; not cached).
	 * @return array|null
	 */
	public static function intent( $query, $pet, $history = array() ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$query = Dokan_App_Search::sub( self::redact( wp_strip_all_tags( (string) $query ) ), 0, 600 );
		$ctx   = Dokan_App_Search::context( $pet );
		$stage = null === $ctx['age_months'] ? '' : ( $ctx['age_months'] < 12 ? 'junior' : 'adult' );
		$cache = '';
		if ( empty( $history ) ) {
			$cache  = 'dokan_app_ai_' . md5( Dokan_App_Helpers::lang() . '|' . Dokan_App_Search::normalize( $query ) . '|' . $ctx['kind'] . '|' . $stage );
			$cached = get_transient( $cache );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$lists  = self::store_lists();
		$types  = array();
		foreach ( Dokan_App_Search::type_defs() as $k => $d ) {
			$types[] = $k . ' (' . $d['label'][0] . ' / ' . $d['label'][1] . ')';
		}
		$system = "You parse shopping queries for Dokan (dokansa.com), a Saudi pet-supplies store. Queries are Arabic (often Gulf/Saudi dialect), English or transliterated, and may contain typos.\n"
			. "Return ONLY one JSON object, no markdown, with exactly these keys:\n"
			. '{"pet_kind": "cat"|"dog"|"bird"|"fish"|"small"|null, "type": one of TYPES or null, "stage": "junior"|"adult"|"senior"|null, "health": [subset of HEALTH], "brand": exact name from BRANDS or null, "min_price": number|null, "max_price": number|null, "sort": "price_asc"|"popularity"|"date"|null, "on_sale": true|false, "keywords": [up to 4 other important product words, typos fixed, in the language of the product titles]}' . "\n"
			. "Rules: pet_kind only when the query (or conversation) itself names or clearly implies the animal — never copy it from the pet profile. small = rabbits, hamsters, turtles, guinea pigs. junior = kitten / puppy / young. Prices are SAR. Use null / [] / false when not stated. Do not repeat in keywords what the other keys already capture; ignore greetings and filler words.\n"
			. 'TYPES: ' . implode( ', ', $types ) . "\n"
			. "HEALTH: sterilised, hairball, weight, urinary, sensitive, coat\n"
			. 'STORE CATEGORIES: ' . implode( '، ', $lists['categories'] ) . "\n"
			. 'BRANDS: ' . implode( ', ', $lists['brands'] );
		$conv = array();
		foreach ( array_slice( (array) $history, -6 ) as $m ) {
			$conv[] = array(
				'role'    => $m['role'],
				'content' => Dokan_App_Search::sub( self::redact( $m['content'] ), 0, 300 ),
			);
		}
		$user = wp_json_encode(
			array(
				'query'        => $query,
				'pet_profile'  => self::pet_profile( $pet ),
				'conversation' => $conv,
			),
			JSON_UNESCAPED_UNICODE
		);
		$text = self::request( $system, array( array( 'role' => 'user', 'content' => $user ) ), 400 );
		if ( is_wp_error( $text ) ) {
			return null;
		}
		$intent = self::validate_intent( self::extract_json( $text ) );
		if ( is_array( $intent ) && '' !== $cache ) {
			set_transient( $cache, $intent, self::CACHE_TTL );
		}
		return $intent;
	}

	/**
	 * Keep only valid intent fields (anything unknown is dropped so rule-based values win).
	 *
	 * @param array|null $raw Raw JSON.
	 * @return array|null
	 */
	public static function validate_intent( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		if ( isset( $raw['pet_kind'] ) && is_string( $raw['pet_kind'] ) && in_array( $raw['pet_kind'], Dokan_App_Pets::kinds(), true ) ) {
			$out['pet'] = $raw['pet_kind'];
		}
		if ( isset( $raw['type'] ) && is_string( $raw['type'] ) && array_key_exists( $raw['type'], Dokan_App_Search::type_defs() ) ) {
			$out['type'] = $raw['type'];
		}
		if ( isset( $raw['stage'] ) && is_string( $raw['stage'] ) ) {
			$st = in_array( $raw['stage'], array( 'kitten', 'puppy', 'young' ), true ) ? 'junior' : $raw['stage'];
			if ( in_array( $st, array( 'junior', 'adult', 'senior' ), true ) ) {
				$out['stage'] = $st;
			}
		}
		if ( isset( $raw['health'] ) && is_array( $raw['health'] ) ) {
			$h = array_values( array_intersect( array_filter( $raw['health'], 'is_string' ), array( 'sterilised', 'hairball', 'weight', 'urinary', 'sensitive', 'coat' ) ) );
			if ( $h ) {
				$out['health'] = $h;
			}
		}
		if ( isset( $raw['brand'] ) && is_string( $raw['brand'] ) && '' !== trim( $raw['brand'] ) ) {
			$b = Dokan_App_Search::match_brand_name( $raw['brand'] );
			if ( null !== $b ) {
				$out['brand'] = $b;
			}
		}
		foreach ( array( 'min_price', 'max_price' ) as $k ) {
			if ( isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) && (float) $raw[ $k ] > 0 && (float) $raw[ $k ] < 100000 ) {
				$out[ $k ] = round( (float) $raw[ $k ], 2 );
			}
		}
		if ( isset( $out['min_price'], $out['max_price'] ) && $out['min_price'] > $out['max_price'] ) {
			unset( $out['min_price'] );
		}
		if ( isset( $raw['sort'] ) && is_string( $raw['sort'] ) && in_array( $raw['sort'], array( 'price_asc', 'popularity', 'date' ), true ) ) {
			$out['sort'] = $raw['sort'];
		}
		if ( ! empty( $raw['on_sale'] ) && true === $raw['on_sale'] ) {
			$out['on_sale'] = true;
		}
		if ( isset( $raw['keywords'] ) && is_array( $raw['keywords'] ) ) {
			$kw = array();
			foreach ( $raw['keywords'] as $w ) {
				if ( is_string( $w ) && '' !== trim( $w ) ) {
					$kw[] = Dokan_App_Search::sub( trim( wp_strip_all_tags( $w ) ), 0, 30 );
				}
				if ( count( $kw ) >= 5 ) {
					break;
				}
			}
			$out['keywords'] = $kw;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Assistant
	 * ------------------------------------------------------------------ */

	/**
	 * Validate the /assistant messages.
	 *
	 * @param mixed $raw Raw body messages.
	 * @return array|WP_Error
	 */
	public static function clean_messages( $raw ) {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( array_slice( array_values( $raw ), -10 ) as $m ) {
				if ( ! is_array( $m ) || ! isset( $m['role'], $m['content'] ) || ! is_string( $m['content'] ) || ! in_array( $m['role'], array( 'user', 'assistant' ), true ) ) {
					continue;
				}
				$content = trim( sanitize_textarea_field( $m['content'] ) );
				$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $content );
				$content = Dokan_App_Search::sub( (string) $content, 0, 600 );
				if ( '' !== $content ) {
					$out[] = array(
						'role'    => $m['role'],
						'content' => $content,
					);
				}
			}
		}
		if ( empty( $out ) || 'user' !== $out[ count( $out ) - 1 ]['role'] ) {
			return Dokan_App_Helpers::error( 'dokan_app_invalid_messages', 'يرجى كتابة رسالتك.', 400 );
		}
		return $out;
	}

	/**
	 * Answer /assistant.
	 *
	 * @param array      $messages Clean messages.
	 * @param array|null $pet      Pet.
	 * @return array {reply, product_ids, chips, ai}
	 */
	public static function assistant( $messages, $pet ) {
		$ctx   = Dokan_App_Search::context( $pet );
		$users = array();
		foreach ( $messages as $m ) {
			if ( 'user' === $m['role'] ) {
				$users[] = $m['content'];
			}
		}
		$last = (string) end( $users );
		$c    = Dokan_App_Search::parse( $last, null, $ctx['kind'] );
		for ( $i = count( $users ) - 2; $i >= 0; $i-- ) {
			$c = Dokan_App_Search::fill_from( $c, Dokan_App_Search::parse( $users[ $i ], null, $ctx['kind'] ) );
		}
		$on = self::enabled();
		if ( $on ) {
			$intent = self::intent( $last, $pet, array_slice( $messages, 0, -1 ) );
			if ( is_array( $intent ) ) {
				$c = Dokan_App_Search::merge_ai( $c, $intent );
			}
		}
		$r  = Dokan_App_Search::run( $c, $ctx, 15, $last );
		$fb = self::fallback( $c, $r, $ctx, $last );
		if ( $on ) {
			$ans = self::chat( $messages, $pet, self::candidates( $r['ids'] ) );
			if ( is_array( $ans ) ) {
				return $ans;
			}
		}
		return $fb;
	}

	/**
	 * Candidate products for Claude (in stock, max 15).
	 *
	 * @param int[] $ids Ids.
	 * @return array
	 */
	private static function candidates( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $p || ! $p->is_in_stock() ) {
				continue;
			}
			$row = array(
				'id'   => (int) $id,
				'name' => Dokan_App_Search::sub( wp_strip_all_tags( $p->get_name() ), 0, 120 ),
			);
			if ( $p->is_type( 'variable' ) ) {
				$row['price_from'] = (float) $p->get_variation_price( 'min', true );
				$reg               = (float) $p->get_variation_regular_price( 'min', true );
			} else {
				$row['price'] = (float) wc_get_price_to_display( $p );
				$reg          = (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) );
			}
			if ( $p->is_on_sale() && $reg > 0 ) {
				$row['regular_price'] = $reg;
			}
			$out[] = $row;
			if ( count( $out ) >= 15 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Store facts for the assistant prompt.
	 *
	 * @return string
	 */
	private static function store_facts() {
		$lines = array_filter(
			array(
				(string) Dokan_App_Helpers::setting_l( 'delivery_note_riyadh' ),
				(string) Dokan_App_Helpers::setting_l( 'delivery_note_ksa' ),
				(string) Dokan_App_Helpers::setting_l( 'delivery_note_extra' ),
			)
		);
		return '- ' . implode( "\n- ", array_map( 'trim', $lines ) );
	}

	/**
	 * Second Claude call: the friendly answer. Returns null on any problem.
	 *
	 * @param array      $messages Messages.
	 * @param array|null $pet      Pet.
	 * @param array      $cands    Candidates.
	 * @return array|null
	 */
	private static function chat( $messages, $pet, $cands ) {
		$en      = Dokan_App_Helpers::is_en();
		$profile = self::pet_profile( $pet );
		$system  = "أنت «مساعد دكان»، مساعد متجر دكان السعودية لمستلزمات الحيوانات الأليفة (dokansa.com).\n"
			. ( $en ? "- The customer writes in English: reply in friendly, simple English.\n" : "- ردّ بلهجة سعودية ودودة ومختصرة.\n" )
			. "- الرد من جملة إلى ثلاث جمل فقط.\n"
			. "- رشّح منتجات فقط من قائمة «المنتجات المتاحة» أدناه وبأرقامها (id). لا تخترع منتجات ولا أسعار ولا خصومات ولا توفّر، ولا تذكر سعرًا غير موجود في القائمة.\n"
			. "- ما فيه أكواد خصم؛ الخصومات مطبّقة مباشرة على أسعار المنتجات.\n"
			. "- معلومات التوصيل (لا تضف عليها شيئًا):\n" . self::store_facts() . "\n"
			. "- إذا سأل العميل عن أعراض أو مرض أو جرعة دواء: وضّح إنك مو طبيب بيطري وانصحه يراجع طبيب بيطري، وتقدر تقترح منتجات عناية عامة من القائمة.\n"
			. "- خلك في مواضيع الحيوانات الأليفة والمتجر فقط؛ إذا سأل عن شي ثاني اعتذر بلطف ورجّعه للموضوع.\n"
			. "- لا تطلب ولا تذكر أي بيانات شخصية.\n"
			. "- إذا القائمة فاضية أو ما فيها شي مناسب، قل كذا واطلب منه يوضّح وش يدوّر.\n"
			. "أخرج JSON فقط بدون أي نص قبله أو بعده:\n"
			. '{"reply": "...", "product_ids": [حتى 6 أرقام من القائمة، الأنسب أولًا], "chips": [حتى 4 اقتراحات متابعة قصيرة جدًا بصيغة كلام العميل' . ( $en ? ' (in English)' : '' ) . ']}' . "\n"
			. 'الحيوان الأليف: ' . ( $profile ? wp_json_encode( $profile, JSON_UNESCAPED_UNICODE ) : 'غير محدد' ) . "\n"
			. 'المنتجات المتاحة: ' . wp_json_encode( $cands, JSON_UNESCAPED_UNICODE );

		$api = array();
		foreach ( $messages as $m ) {
			$content = self::redact( $m['content'] );
			if ( empty( $api ) && 'assistant' === $m['role'] ) {
				continue; // Must start with the user.
			}
			$n = count( $api );
			if ( $n && $api[ $n - 1 ]['role'] === $m['role'] ) {
				$api[ $n - 1 ]['content'] .= "\n" . $content;
			} else {
				$api[] = array(
					'role'    => $m['role'],
					'content' => $content,
				);
			}
		}
		if ( empty( $api ) ) {
			return null;
		}
		$text = self::request( $system, $api, 400 );
		if ( is_wp_error( $text ) ) {
			return null;
		}
		$j = self::extract_json( $text );
		if ( ! is_array( $j ) || ! isset( $j['reply'] ) || ! is_string( $j['reply'] ) ) {
			return null;
		}
		$reply = Dokan_App_Search::sub( trim( wp_strip_all_tags( $j['reply'] ) ), 0, 600 );
		if ( '' === $reply ) {
			return null;
		}
		$allowed = array();
		foreach ( $cands as $cd ) {
			$allowed[ (int) $cd['id'] ] = true;
		}
		$ids = array();
		foreach ( isset( $j['product_ids'] ) && is_array( $j['product_ids'] ) ? $j['product_ids'] : array() as $x ) {
			$x = is_numeric( $x ) ? (int) $x : 0;
			if ( isset( $allowed[ $x ] ) && ! in_array( $x, $ids, true ) ) {
				$ids[] = $x;
			}
			if ( count( $ids ) >= 6 ) {
				break;
			}
		}
		$chips = array();
		foreach ( isset( $j['chips'] ) && is_array( $j['chips'] ) ? $j['chips'] : array() as $ch ) {
			if ( ! is_string( $ch ) ) {
				continue;
			}
			$ch = Dokan_App_Search::sub( trim( wp_strip_all_tags( $ch ) ), 0, 40 );
			if ( '' !== $ch && ! in_array( $ch, $chips, true ) ) {
				$chips[] = $ch;
			}
			if ( count( $chips ) >= 4 ) {
				break;
			}
		}
		return array(
			'reply'       => $reply,
			'product_ids' => $ids,
			'chips'       => $chips,
			'ai'          => true,
		);
	}

	/**
	 * Rule-based assistant answer.
	 *
	 * @param array $c   Concepts.
	 * @param array $r   Search result.
	 * @param array $ctx Context.
	 * @return array
	 */
	/**
	 * Expert tip + preferred words for common needs (no AI needed).
	 *
	 * @param string $text Customer text.
	 * @param array  $c    Concepts.
	 * @return array {tip:string, prefer:string[]}
	 */
	private static function advice( $text, $c ) {
		$n     = Dokan_App_Search::normalize( $text );
		$has   = function ( $words ) use ( $n ) {
			foreach ( $words as $w ) {
				if ( false !== strpos( $n, Dokan_App_Search::normalize( $w ) ) ) {
					return true;
				}
			}
			return false;
		};
		$types = (array) $c['types'];
		$pets  = (array) $c['pets'];
		$cat   = in_array( 'cat', $pets, true ) || empty( $pets );
		$tips  = array();
		$pref  = array();
		if ( in_array( 'litter', $types, true ) || $has( array( 'رمل', 'litter' ) ) ) {
			$smell = $has( array( 'ريحه', 'رائحه', 'ريحة', 'رائحة', 'يريح', 'smell', 'odor', 'odour' ) );
			$dust  = $has( array( 'غبار', 'dust' ) );
			if ( $smell ) {
				$tips[] = Dokan_App_Helpers::t( 'للريحة: الرمل المتكتل (بنتونايت) أو اللي فيه كربون نشط يمسك الريحة أحسن، وغيّر الرمل كامل كل أسبوعين تقريبًا.', 'For smell: clumping (bentonite) or activated-carbon litter locks odour best; replace it fully about every two weeks.' );
				$pref   = array_merge( $pref, array( 'كربون', 'charcoal', 'carbon', 'متكتل', 'clump', 'بنتونايت', 'bentonite', 'معطر', 'odor', 'odour', 'لافندر', 'lavender' ) );
			}
			if ( $dust ) {
				$tips[] = Dokan_App_Helpers::t( 'ولغبار أقل: رمل التوفو أو الكريستال تقريبًا بدون غبار ومناسب للقطط الحساسة.', 'For less dust: tofu or crystal litter is almost dust-free and kind to sensitive cats.' );
				$pref   = array_merge( $pref, array( 'توفو', 'tofu', 'كريستال', 'crystal', 'غبار', 'dust', 'سيليكا', 'silica' ) );
			}
			if ( ! $smell && ! $dust ) {
				$tips[] = Dokan_App_Helpers::t( 'الرمل المتكتل أسهل في التنظيف، والتوفو أخف وقابل للتصريف.', 'Clumping litter is easiest to clean; tofu litter is lighter and flushable.' );
			}
		}
		if ( 'kitten' === $c['stage'] || $has( array( 'صغيره', 'صغير', 'كيتن', 'kitten', 'puppy', 'جرو' ) ) ) {
			$tips[] = $cat
				? Dokan_App_Helpers::t( 'القطط الصغيرة لين عمر سنة تحتاج أكل مخصص «Kitten» غني بالبروتين، وتقدر تجمع بين الناشف والرطب، ووفّر لها ماء نظيف دايم.', 'Kittens up to a year need dedicated "Kitten" food rich in protein; mixing dry and wet is great, with fresh water always available.' )
				: Dokan_App_Helpers::t( 'الجراء تحتاج أكل «Puppy» مخصص لين عمر سنة تقريبًا.', 'Puppies need dedicated "Puppy" food until about a year old.' );
			$pref = array_merge( $pref, array( 'kitten', 'كيتن', 'صغيره', 'الصغيرة', 'puppy', 'جرو' ) );
		}
		if ( in_array( 'sterilised', (array) $c['health'], true ) || $has( array( 'تسمن', 'سمنه', 'وزن', 'سمين', 'weight', 'fat', 'light' ) ) ) {
			$tips[] = Dokan_App_Helpers::t( 'للمعقمة أو اللي تزيد وزن: اختار أكل «Sterilised» أو «Light» قليل الدهون، والتزم بالكمية المكتوبة على الكيس وقسّمها على وجبات.', 'For sterilised or overweight pets: pick "Sterilised" or "Light" low-fat food, stick to the bag\'s portion and split it into meals.' );
			$pref   = array_merge( $pref, array( 'sterilised', 'sterilized', 'معقم', 'light', 'لايت', 'weight', 'وزن' ) );
		}
		if ( $has( array( 'هديه', 'هدية', 'gift', 'present' ) ) ) {
			$tips[] = Dokan_App_Helpers::t( 'للهدية: الألعاب والخدّاشات والمكافآت دايمًا تفرّح، اخترت لك أشياء ضمن ميزانيتك.', 'For a gift: toys, scratchers and treats always delight — here are picks within your budget.' );
			$pref   = array_merge( $pref, array( 'لعبه', 'لعبة', 'toy', 'خداش', 'scratch', 'مكافا', 'treat' ) );
		}
		return array( 'tip' => implode( ' ', $tips ), 'prefer' => $pref );
	}

	/**
	 * Put products whose names match the preferred words first (keeps the rest in order).
	 */
	private static function prefer_ids( $ids, $words ) {
		if ( empty( $words ) ) {
			return $ids;
		}
		$score = array();
		foreach ( $ids as $i => $id ) {
			$name = Dokan_App_Search::normalize( (string) get_the_title( (int) $id ) );
			$s    = 0;
			foreach ( $words as $w ) {
				if ( false !== strpos( $name, Dokan_App_Search::normalize( $w ) ) ) {
					$s++;
				}
			}
			$score[ $id ] = array( -$s, $i );
		}
		uksort( $score, function ( $a, $b ) use ( $score ) {
			return $score[ $a ] <=> $score[ $b ];
		} );
		return array_keys( $score );
	}

	private static function fallback( $c, $r, $ctx, $text = '' ) {
		$t        = array( 'Dokan_App_Helpers', 't' );
		$topics   = $c['topics'];
		$has_conc = ! empty( $c['pets'] ) || ! empty( $c['types'] ) || ! empty( $c['brands'] ) || ! empty( $c['keywords'] ) || ! empty( $c['health'] ) || $c['stage'] || $c['on_sale'] || null !== $c['max_price'] || null !== $c['min_price'];
		$ids      = array_slice( $r['ids'], 0, 6 );
		if ( in_array( 'vet', $topics, true ) ) {
			$reply = call_user_func( $t, 'ما أقدر أشخّص الحالة لأني مو طبيب بيطري، والأفضل تعرضه على طبيب بيطري بأقرب وقت.', 'I\'m not a vet, so I can\'t diagnose this — please see a vet as soon as you can.' );
			if ( $ids ) {
				$reply .= ' ' . call_user_func( $t, 'وهذي منتجات عناية عامة ممكن تفيدك.', 'Here are some general care products that may help.' );
			}
		} elseif ( in_array( 'coupon', $topics, true ) ) {
			$reply = call_user_func( $t, 'ما عندنا أكواد خصم، الخصومات مطبّقة مباشرة على أسعار المنتجات.', 'We don\'t use discount codes — discounts are already applied to the product prices.' );
		} elseif ( in_array( 'delivery', $topics, true ) && ( ! $has_conc || empty( $ids ) ) ) {
			$reply = trim( implode( ' ', array_filter( array( (string) Dokan_App_Helpers::setting_l( 'delivery_note_riyadh' ), (string) Dokan_App_Helpers::setting_l( 'delivery_note_ksa' ), (string) Dokan_App_Helpers::setting_l( 'delivery_note_extra' ) ) ) ) );
			$ids   = array();
		} elseif ( ! $has_conc ) {
			$reply = call_user_func( $t, 'هلا! قولي وش تدوّر لحيوانك الأليف (أكل، رمل، ألعاب…) وأساعدك تلقاه.', 'Hi! Tell me what you\'re looking for your pet (food, litter, toys…) and I\'ll help you find it.' );
		} else {
			$adv = self::advice( $text, $c );
			if ( '' !== $adv['tip'] && $ids ) {
				$ids   = array_slice( self::prefer_ids( array_values( array_map( 'intval', $r['ids'] ) ), $adv['prefer'] ), 0, 6 );
				$reply = $adv['tip'] . ' ' . call_user_func( $t, 'وهذي أنسب اختياراتي لك من دكان:', 'Here are my best picks for you from Dokan:' );
			} elseif ( $ids ) {
				$reply = call_user_func( $t, 'أبشر! ', 'Sure! ' ) . $r['reply'] . call_user_func( $t, '. هذي أفضلها:', '. Here are the best ones:' );
			} else {
				$reply = $r['reply'] . ' ' . call_user_func( $t, 'جرّب توضح لي أكثر (نوع الحيوان، عمره، الميزانية) أو كلمنا واتساب ونساعدك.', 'Tell me a bit more (pet, age, budget) or message us on WhatsApp and we\'ll help.' );
			}
		}
		$chips = array();
		foreach ( $r['suggestions'] as $s ) {
			if ( Dokan_App_Search::len( $s ) <= 40 ) {
				$chips[] = $s;
			}
			if ( count( $chips ) >= 4 ) {
				break;
			}
		}
		if ( empty( $chips ) ) {
			$kind  = ! empty( $c['pets'] ) ? $c['pets'][0] : ( '' !== $ctx['kind'] ? $ctx['kind'] : 'cat' );
			$chips = array(
				Dokan_App_Search::type_label( 'cat' === $kind ? 'wet' : 'dry' ) . ' ' . Dokan_App_Search::pet_label( $kind ),
				'cat' === $kind ? Dokan_App_Search::type_label( 'litter' ) . ' ' . Dokan_App_Search::pet_label( 'cat' ) : Dokan_App_Search::type_label( 'treats' ),
				Dokan_App_Search::type_label( 'toys' ),
				call_user_func( $t, 'عروض', 'Offers' ),
			);
		}
		return array(
			'reply'       => (string) $reply,
			'product_ids' => array_values( array_map( 'intval', $ids ) ),
			'chips'       => array_values( $chips ),
			'ai'          => false,
		);
	}
}
