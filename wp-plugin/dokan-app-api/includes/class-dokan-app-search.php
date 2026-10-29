<?php
/**
 * Smart product search for the app.
 *
 * - Arabic normaliser + light stemmer (matching only; display text is kept).
 * - Concept parser: pet kind, product type, life stage, health need, brand, price, sort, on-sale,
 *   left-over keywords (Arabic / Gulf dialect / English / transliterations).
 * - In-memory product index per language built with a few direct SQL queries, cached in a
 *   non-autoloaded option, rebuilt every 6 h and after product / stock / term changes.
 * - Scoring with step-by-step relaxation, Arabic / English reply, chips, suggestions, categories.
 * - Customers' search log (owner report) and popular searches.
 *
 * Claude (optional) only refines the parsed intent; see Dokan_App_Claude.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Search {

	const OPT_INDEX    = 'dokan_app_search_index_';
	const OPT_DIRTY    = 'dokan_app_search_dirty';
	const OPT_LOG      = 'dokan_app_search_log';
	const CRON_REBUILD = 'dokan_app_search_rebuild';
	const LOCK         = 'dokan_app_search_building';
	const INDEX_TTL    = 21600; // 6 hours.
	const LOG_MAX      = 500;

	/**
	 * Loaded indexes per language.
	 *
	 * @var array
	 */
	private static $index = array();

	/**
	 * Concept dictionary: stem sequence => list of concepts.
	 *
	 * @var array|null
	 */
	private static $dict = null;

	/**
	 * Stop words (stems and folded forms).
	 *
	 * @var array|null
	 */
	private static $stop = null;

	/**
	 * Brand entries per language.
	 *
	 * @var array
	 */
	private static $brand_cache = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON_REBUILD, array( __CLASS__, 'cron_rebuild' ) );
		foreach ( array( 'save_post_product', 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ), 99, 0 );
		}
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate_post' ), 99, 1 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate_term' ), 99, 3 );
		}
	}

	/* ------------------------------------------------------------------
	 * Multibyte helpers
	 * ------------------------------------------------------------------ */

	/**
	 * UTF-8 length.
	 *
	 * @param string $s String.
	 * @return int
	 */
	public static function len( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : (int) preg_match_all( '/./us', $s );
	}

	/**
	 * UTF-8 substring.
	 *
	 * @param string   $s      String.
	 * @param int      $start  Start.
	 * @param int|null $length Length.
	 * @return string
	 */
	public static function sub( $s, $start, $length = null ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $length, 'UTF-8' );
		}
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		return implode( '', array_slice( (array) $chars, $start, $length ) );
	}

	/**
	 * Lowercase.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Translate helper (falls back to Arabic when the helpers class is not loaded).
	 *
	 * @param string $ar Arabic.
	 * @param string $en English.
	 * @return string
	 */
	private static function t( $ar, $en ) {
		return class_exists( 'Dokan_App_Helpers' ) ? Dokan_App_Helpers::t( $ar, $en ) : $ar;
	}

	/**
	 * Current language.
	 *
	 * @return string
	 */
	private static function lang() {
		return class_exists( 'Dokan_App_Helpers' ) ? Dokan_App_Helpers::lang() : 'ar';
	}

	/* ------------------------------------------------------------------
	 * Normaliser / stemmer
	 * ------------------------------------------------------------------ */

	/**
	 * Arabic-Indic / Persian digits → 0-9.
	 *
	 * @param string $s String.
	 * @return string
	 */
	public static function digits( $s ) {
		return strtr(
			(string) $s,
			array(
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٫' => '.', '٬' => ',',
			)
		);
	}

	/**
	 * 0-9 → Arabic-Indic digits (for Arabic display).
	 *
	 * @param string $s String.
	 * @return string
	 */
	public static function ar_digits( $s ) {
		return strtr( (string) $s, array( '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩' ) );
	}

	/**
	 * Clean text but keep letters as typed: strip tags, tashkeel, tatweel, convert digits,
	 * lowercase Latin, punctuation → space, split letter/digit and Arabic/Latin runs.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function clean_text( $s ) {
		$s = (string) $s;
		if ( '' === $s ) {
			return '';
		}
		$s = html_entity_decode( strip_tags( $s ), ENT_QUOTES, 'UTF-8' );
		if ( false === @preg_match( '//u', $s ) ) { // phpcs:ignore
			return '';
		}
		$s = self::digits( $s );
		$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{06D6}-\x{06ED}\x{200B}-\x{200F}\x{FEFF}]/u', '', $s );
		$s = self::lower( $s );
		$s = str_replace( array( "'", '’', '‘', '`', '´' ), '', $s );
		$s = preg_replace( '/[^\p{L}\p{N}\.]+/u', ' ', $s );
		$s = preg_replace( '/(?<!\d)\.|\.(?!\d)/u', ' ', $s );
		$s = preg_replace( '/(\p{N})(\p{L})/u', '$1 $2', $s );
		$s = preg_replace( '/(\p{L})(\p{N})/u', '$1 $2', $s );
		$s = preg_replace( '/(\p{Arabic})([a-z])/u', '$1 $2', $s );
		$s = preg_replace( '/([a-z])(\p{Arabic})/u', '$1 $2', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $s ) );
	}

	/**
	 * Fold letter variants: أإآٱ→ا، ة→ه، ى→ي، ؤ→و، ئ→ي (+ Persian letters used in the Gulf).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fold( $s ) {
		return strtr(
			(string) $s,
			array(
				'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ۀ' => 'ه', 'ى' => 'ي', 'ی' => 'ي',
				'ؤ' => 'و', 'ئ' => 'ي', 'ڤ' => 'ف', 'گ' => 'ك', 'ک' => 'ك', 'پ' => 'ب', 'چ' => 'ج',
			)
		);
	}

	/**
	 * Full normaliser (clean + fold).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function normalize( $s ) {
		return self::fold( self::clean_text( $s ) );
	}

	/**
	 * Unit synonyms → canonical unit.
	 *
	 * @return array
	 */
	private static function units() {
		return array(
			'كيلو' => 'كجم', 'كيلوجرام' => 'كجم', 'كيلوغرام' => 'كجم', 'كغ' => 'كجم', 'كجم' => 'كجم', 'كلغ' => 'كجم', 'كيلوات' => 'كجم',
			'kg' => 'كجم', 'kgs' => 'كجم', 'kilo' => 'كجم', 'kilos' => 'كجم',
			'جرام' => 'جرام', 'غرام' => 'جرام', 'جم' => 'جرام', 'غم' => 'جرام', 'جرامات' => 'جرام', 'غرامات' => 'جرام',
			'g' => 'جرام', 'gm' => 'جرام', 'gr' => 'جرام', 'gram' => 'جرام', 'grams' => 'جرام',
			'مل' => 'مل', 'ملي' => 'مل', 'ml' => 'مل', 'لتر' => 'لتر', 'ltr' => 'لتر', 'liter' => 'لتر', 'litre' => 'لتر',
			'حبه' => 'حبه', 'حبات' => 'حبه', 'pcs' => 'حبه', 'pieces' => 'حبه',
		);
	}

	/**
	 * Canonical unit tokens.
	 *
	 * @return array
	 */
	private static function unit_set() {
		return array_flip( array_values( array_unique( self::units() ) ) );
	}

	/**
	 * Strip one common Arabic prefix (ال، وال، بال، لل، فال، كال) when ≥ 3 letters remain.
	 *
	 * @param string $t Folded token.
	 * @return string
	 */
	private static function strip_prefix( $t ) {
		foreach ( array( 'وال', 'بال', 'فال', 'كال', 'لل', 'ال' ) as $p ) {
			$pl = self::len( $p );
			if ( self::sub( $t, 0, $pl ) === $p && self::len( $t ) - $pl >= 3 ) {
				$t = self::sub( $t, $pl );
				if ( 'ال' === self::sub( $t, 0, 2 ) && self::len( $t ) - 2 >= 3 ) {
					$t = self::sub( $t, 2 );
				}
				break;
			}
		}
		return $t;
	}

	/**
	 * Light stemmer for matching only: prefixes + ات / ين / ون / ه suffixes (twice at most);
	 * English plural "s"; unit synonyms.
	 *
	 * @param string $t Folded token.
	 * @return string
	 */
	public static function stem_folded( $t ) {
		$t = (string) $t;
		if ( '' === $t ) {
			return '';
		}
		$units = self::units();
		if ( isset( $units[ $t ] ) ) {
			return $units[ $t ];
		}
		if ( preg_match( '/^[a-z0-9.]+$/', $t ) ) {
			if ( strlen( $t ) > 3 && 's' === substr( $t, -1 ) && 'ss' !== substr( $t, -2 ) && ! preg_match( '/\d/', $t ) ) {
				$t = substr( $t, 0, -1 );
			}
			return $t;
		}
		if ( ! preg_match( '/\p{Arabic}/u', $t ) ) {
			return $t;
		}
		$t = self::strip_prefix( $t );
		for ( $k = 0; $k < 2; $k++ ) {
			$done = false;
			foreach ( array( 'ات', 'ين', 'ون', 'ه' ) as $suf ) {
				$sl = self::len( $suf );
				if ( self::len( $t ) - $sl >= 3 && self::sub( $t, -$sl ) === $suf ) {
					$t    = self::sub( $t, 0, self::len( $t ) - $sl );
					$done = true;
					break;
				}
			}
			if ( ! $done ) {
				break;
			}
		}
		return isset( $units[ $t ] ) ? $units[ $t ] : $t;
	}

	/**
	 * Alternative stem with a single-letter prefix (و ب ل ف ك) removed — used for dictionary
	 * and stop-word lookups ("لقطه" → "قطه", "وعندي" → "عندي").
	 *
	 * @param string $f Folded token.
	 * @return string
	 */
	private static function alt_stem( $f ) {
		if ( self::len( $f ) < 4 || ! preg_match( '/^[وبلفك]/u', $f ) ) {
			return '';
		}
		$rest = self::sub( $f, 1 );
		return self::len( $rest ) >= 3 ? self::stem_folded( $rest ) : '';
	}

	/**
	 * Tokens of a text: list of [display, stem, folded].
	 *
	 * @param string $text Text.
	 * @return array
	 */
	public static function tokens( $text ) {
		$clean = self::clean_text( $text );
		if ( '' === $clean ) {
			return array();
		}
		$out = array();
		foreach ( explode( ' ', $clean ) as $d ) {
			$f     = self::fold( $d );
			$out[] = array( $d, self::stem_folded( $f ), $f );
		}
		return $out;
	}

	/**
	 * Stem sequence of a phrase ("رويال كانين" → "رويال كان").
	 *
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function stemseq( $phrase ) {
		$s = array();
		foreach ( self::tokens( $phrase ) as $tk ) {
			if ( '' !== $tk[1] ) {
				$s[] = $tk[1];
			}
		}
		return implode( ' ', $s );
	}

	/**
	 * Flat set of stems for a list of words.
	 *
	 * @param string[] $words Words.
	 * @return array stem => 1
	 */
	private static function stems_of( $words ) {
		$out = array();
		foreach ( (array) $words as $w ) {
			foreach ( self::tokens( $w ) as $tk ) {
				if ( '' !== $tk[1] ) {
					$out[ (string) $tk[1] ] = 1;
				}
			}
		}
		return $out;
	}

	/**
	 * Map Arabic letters to single bytes so PHP's levenshtein() counts letters, not bytes.
	 *
	 * @param string $s Stem.
	 * @return string
	 */
	private static function fold_bytes( $s ) {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			for ( $cp = 0x0621; $cp <= 0x064A; $cp++ ) {
				$ch         = function_exists( 'mb_chr' ) ? mb_chr( $cp, 'UTF-8' ) : html_entity_decode( '&#' . $cp . ';', ENT_NOQUOTES, 'UTF-8' );
				$map[ $ch ] = chr( 0x80 + $cp - 0x0620 );
			}
		}
		return strtr( (string) $s, $map );
	}

	/* ------------------------------------------------------------------
	 * Concept dictionary
	 * ------------------------------------------------------------------ */

	/**
	 * Lexicon rows: [concepts, words]. Concept = [field, value(, flag)].
	 * Words may be phrases of up to 3 words. Normalised / stemmed on load.
	 *
	 * @return array
	 */
	private static function lexicon() {
		$L   = array();
		$add = function ( $concepts, $words ) use ( &$L ) {
			$L[] = array( $concepts, $words );
		};
		// Pet kinds.
		$add( array( array( 'pet', 'cat' ) ), array( 'قط', 'قطط', 'قطه', 'قطتي', 'قطي', 'قطوه', 'قطاوه', 'قطاطي', 'بسه', 'بسس', 'بسي', 'بستي', 'بسيس', 'هر', 'هره', 'هرره', 'كات', 'cat', 'cats', 'kitty', 'feline' ) );
		$add( array( array( 'pet', 'cat', 'weak' ) ), array( 'بس' ) ); // Also means "only" in Gulf dialect.
		$add( array( array( 'pet', 'dog' ) ), array( 'كلب', 'كلاب', 'كلبي', 'كلبتي', 'كلبه', 'دوق', 'دوج', 'dog', 'dogs', 'doggy' ) );
		$add( array( array( 'pet', 'bird' ) ), array( 'طير', 'طيور', 'طيري', 'طائر', 'عصفور', 'عصافير', 'ببغاء', 'ببغا', 'ببغاوات', 'بغبغان', 'كناري', 'كناريا', 'حمام', 'حمامه', 'بادجي', 'bird', 'birds', 'parrot', 'parrots', 'canary', 'budgie' ) );
		$add( array( array( 'pet', 'fish' ) ), array( 'سمك', 'سمكه', 'اسماك', 'سمكتي', 'حوض', 'احواض', 'فيش', 'fish', 'aquarium', 'goldfish' ) );
		$add( array( array( 'pet', 'small' ) ), array( 'ارنب', 'ارانب', 'ارنبي', 'هامستر', 'هامسترات', 'سلحفاه', 'سلاحف', 'سلحفتي', 'قوارض', 'خنزير غينيا', 'غينيا', 'حيوانات صغيره', 'rabbit', 'rabbits', 'hamster', 'hamsters', 'turtle', 'turtles', 'tortoise', 'guinea pig', 'small animals', 'small animal' ) );
		// Life stage (+ implied pet).
		$add( array( array( 'pet', 'cat' ), array( 'stage', 'junior' ) ), array( 'كيتن', 'kitten', 'kittens', 'قطيطه', 'قطيطات' ) );
		$add( array( array( 'pet', 'dog' ), array( 'stage', 'junior' ) ), array( 'جرو', 'جراء', 'جروه', 'جروي', 'puppy', 'puppies' ) );
		$add( array( array( 'stage', 'junior', 'weak' ) ), array( 'صغير', 'صغيره', 'صغار' ) ); // Also a size.
		$add( array( array( 'stage', 'junior' ) ), array( 'رضيع', 'رضيعه', 'رضع', 'مولود', 'مواليد', 'بيبي', 'baby', 'junior', 'جونيور', 'starter', 'young' ) );
		$add( array( array( 'stage', 'adult' ) ), array( 'بالغ', 'بالغه', 'بالغين', 'adult', 'adults', 'ادلت' ) );
		$add( array( array( 'stage', 'adult', 'weak' ) ), array( 'كبير', 'كبيره', 'كبار' ) ); // Also a size ("قفص كبير").
		$add( array( array( 'stage', 'senior' ) ), array( 'كبير السن', 'كبيره السن', 'كبار السن', 'كبير بالسن', 'كبيره بالسن', 'كبير بالعمر', 'مسن', 'مسنه', 'عجوز', 'شايب', 'senior', 'سينيور', 'mature', 'aging', 'ageing' ) );
		// Product types.
		$add( array( array( 'type', 'food' ) ), array( 'اكل', 'اكلات', 'اكله', 'طعام', 'اطعمه', 'غذاء', 'غذا', 'اغذيه', 'فود', 'وجبه', 'وجبات', 'food', 'meal', 'meals' ) );
		$add( array( array( 'type', 'dry' ) ), array( 'جاف', 'ناشف', 'دراي', 'dry', 'kibble' ) );
		$add( array( array( 'type', 'wet' ) ), array( 'رطب', 'معلب', 'معلبات', 'علب', 'علبه', 'ويت', 'جلي', 'صلصه', 'مرق', 'باتيه', 'wet', 'pouch', 'pouches', 'gravy', 'jelly', 'pate', 'canned' ) );
		$add( array( array( 'type', 'litter' ) ), array( 'رمل', 'ليتر', 'litter', 'sand' ) );
		$add( array( array( 'type', 'toys' ) ), array( 'لعبه', 'العاب', 'لعب', 'toy', 'toys' ) );
		$add( array( array( 'type', 'treats' ) ), array( 'مكافات', 'مكافاه', 'مكافئه', 'تريت', 'تريتس', 'سناك', 'سناكات', 'حلويات', 'حلاوه', 'treat', 'treats', 'snack', 'snacks' ) );
		$add( array( array( 'type', 'grooming' ) ), array( 'شامبو', 'استحمام', 'مشط', 'امشاط', 'فرشاه', 'فرشايه', 'عنايه', 'بلسم', 'مقص اظافر', 'grooming', 'shampoo', 'brush', 'comb', 'conditioner' ) );
		$add( array( array( 'type', 'health' ) ), array( 'فيتامين', 'فيتامينات', 'مكمل', 'مكملات', 'دواء', 'ادويه', 'علاج', 'قطره', 'معجون', 'vitamin', 'vitamins', 'supplement', 'supplements', 'medicine' ) );
		$add( array( array( 'type', 'accessories' ) ), array( 'طوق', 'اطواق', 'رسن', 'حزام', 'احزمه', 'سلسله', 'collar', 'collars', 'leash', 'leashes', 'harness' ) );
		$add( array( array( 'type', 'carriers' ) ), array( 'حقيبه', 'حقائب', 'شنطه', 'شنط', 'بوكس', 'بوكس نقل', 'قفص', 'اقفاص', 'نقل', 'ناقل', 'carrier', 'carriers', 'cage', 'cages', 'crate' ) );
		$add( array( array( 'type', 'beds' ) ), array( 'سرير', 'اسره', 'فرشه', 'بيت', 'بيوت', 'كنبه', 'bed', 'beds', 'house', 'cushion' ) );
		$add( array( array( 'type', 'scratchers' ) ), array( 'خدش', 'خربشه', 'مخدش', 'مخدشه', 'سكراتشر', 'سكراتش', 'scratcher', 'scratchers', 'scratching', 'scratching post' ) );
		$add( array( array( 'type', 'scratchers' ), array( 'pet', 'cat' ) ), array( 'شجره قطط', 'cat tree' ) );
		$add( array( array( 'type', 'bowls' ) ), array( 'صحن', 'صحون', 'وعاء', 'اوعيه', 'ماعون', 'مواعين', 'سقايه', 'مسقايه', 'نافوره', 'معلف', 'bowl', 'bowls', 'fountain', 'feeder', 'feeders' ) );
		$add( array( array( 'type', 'litter_box' ) ), array( 'صندوق رمل', 'صناديق رمل', 'كرسي حمام', 'تواليت', 'litter box', 'litter tray', 'toilet' ) );
		$add( array( array( 'type', 'litter_box' ), array( 'pet', 'cat' ) ), array( 'حمام قطط', 'cat toilet' ) );
		// Health needs.
		$add( array( array( 'health', 'sterilised' ) ), array( 'معقم', 'معقمه', 'تعقيم', 'مخصي', 'مخصيه', 'sterilised', 'sterilized', 'neutered', 'spayed', 'sterile' ) );
		$add( array( array( 'health', 'hairball' ) ), array( 'كرات الشعر', 'كور الشعر', 'كره الشعر', 'كرات شعر', 'كور شعر', 'شعر', 'hairball', 'hairballs', 'hair ball' ) );
		$add( array( array( 'health', 'weight' ) ), array( 'وزن', 'سمنه', 'سمين', 'سمينه', 'دايت', 'رجيم', 'تخسيس', 'تخفيف الوزن', 'لايت', 'light', 'weight', 'diet' ) );
		$add( array( array( 'health', 'urinary' ) ), array( 'بول', 'بوليه', 'مسالك', 'المسالك البوليه', 'حصوات', 'حصى', 'urinary' ) );
		$add( array( array( 'health', 'sensitive' ) ), array( 'حساسيه', 'حساس', 'حساسه', 'هضم', 'معده', 'sensitive', 'digestion', 'digestive' ) );
		$add( array( array( 'health', 'coat' ) ), array( 'جلد', 'فرو', 'لمعان', 'وبر', 'coat', 'skin', 'fur' ) );
		// Sort / sale.
		$add( array( array( 'sort', 'price_asc' ) ), array( 'رخيص', 'رخيصه', 'ارخص', 'اقتصادي', 'اقتصاديه', 'cheap', 'cheapest', 'budget' ) );
		$add( array( array( 'sort', 'popularity' ) ), array( 'افضل', 'احسن', 'الاكثر مبيعا', 'اكثر مبيعا', 'الاكثر طلبا', 'مبيعا', 'الاشهر', 'اشهر', 'best', 'popular', 'bestseller', 'best seller', 'best selling' ) );
		$add( array( array( 'sort', 'date' ) ), array( 'جديد', 'جديده', 'وصل حديثا', 'وصلت حديثا', 'حديثا', 'احدث', 'new', 'newest', 'latest', 'new arrivals' ) );
		$add( array( array( 'sale', 1 ) ), array( 'عرض', 'عروض', 'خصم', 'خصومات', 'تخفيض', 'تخفيضات', 'تنزيلات', 'sale', 'offer', 'offers', 'discount', 'discounts', 'deal', 'deals', 'on sale' ) );
		// Assistant topics (never product keywords).
		$add( array( array( 'topic', 'coupon' ) ), array( 'كود', 'كوبون', 'كوبونات', 'كود خصم', 'رمز خصم', 'كود الخصم', 'coupon', 'coupons', 'voucher', 'promo code', 'promocode', 'discount code' ) );
		$add( array( array( 'topic', 'delivery' ) ), array( 'توصيل', 'شحن', 'يوصل', 'توصلون', 'يوصلني', 'توصلني', 'مندوب', 'delivery', 'deliver', 'shipping', 'ship' ) );
		$add( array( array( 'topic', 'vet' ) ), array( 'مريض', 'مريضه', 'مرض', 'يستفرغ', 'تستفرغ', 'استفراغ', 'ترجيع', 'اسهال', 'دم', 'حراره', 'خمول', 'كحه', 'عطس', 'يعطس', 'تعطس', 'جرعه', 'جرعات', 'التهاب', 'جرح', 'تسمم', 'مسموم', 'حكه', 'ما ياكل', 'ما تاكل', 'sick', 'vomit', 'vomiting', 'diarrhea', 'diarrhoea', 'dose', 'dosage', 'ill', 'illness', 'infection', 'fever', 'symptom', 'symptoms' ) );
		return apply_filters( 'dokan_app_search_lexicon', $L );
	}

	/**
	 * Stop words.
	 *
	 * @return string[]
	 */
	private static function stop_words() {
		return apply_filters(
			'dokan_app_search_stop_words',
			array(
				'في', 'من', 'مع', 'على', 'عن', 'الى', 'او', 'و', 'ب', 'ل', 'يا', 'ابي', 'ابغى', 'ابغا', 'ابا', 'بغيت', 'ودي', 'اريد', 'احتاج', 'محتاج', 'ابحث', 'ادور',
				'عندي', 'عندها', 'عنده', 'عندكم', 'عندك', 'فيه', 'فيها', 'يوجد', 'هل', 'ممكن', 'لو', 'سمحت', 'لوسمحت', 'حق', 'حقت', 'حقه', 'حقها', 'حقي', 'حقهم', 'حقنا',
				'شي', 'شيء', 'اي', 'اللي', 'الي', 'ذا', 'هذا', 'هذي', 'هذه', 'انا', 'تبع', 'نوع', 'انواع', 'منتج', 'منتجات', 'حيوان', 'حيواني', 'اليف', 'اليفه', 'الاليفه',
				'وش', 'ايش', 'شنو', 'كيف', 'تنصحني', 'تنصح', 'انصحني', 'اقترح', 'اقترحلي', 'يناسب', 'يناسبه', 'يناسبها', 'مناسب', 'مناسبه', 'زين', 'حلو', 'اشتري', 'شراء',
				'له', 'لها', 'لي', 'لنا', 'كم', 'سعر', 'بسعر', 'بكم', 'متوفر', 'متوفره', 'موجود', 'عمر', 'عمره', 'عمرها', 'هلا', 'مرحبا', 'السلام', 'عليكم', 'شكرا', 'الله',
				'يعطيك', 'العافيه', 'اعرف', 'قول', 'قولي', 'عطني', 'عطيني', 'بدي', 'لازم', 'كذا', 'وايد', 'مره', 'جدا', 'كثير', 'ريال', 'ريالات', 'رس', 'طيب', 'تمام', 'اوكي',
				'اعطيها', 'اعطيه', 'اعطي', 'اسوي', 'يسوي', 'تاكل', 'ياكل', 'يحب', 'تحب', 'ابغاه', 'ابيه', 'ابيها',
				'وين', 'متى', 'ليش', 'اذا', 'ولا', 'بعد', 'قبل', 'كل', 'بين', 'غير', 'نفس', 'حتى', 'عشان', 'علشان', 'لان', 'يعني',
				'for', 'the', 'and', 'with', 'a', 'an', 'of', 'to', 'in', 'my', 'please', 'i', 'want', 'need', 'some', 'any', 'is', 'are', 'do', 'you', 'have', 'me',
				'what', 'which', 'how', 'can', 'could', 'should', 'get', 'buy', 'looking', 'show', 'find', 'hi', 'hello', 'thanks', 'thank', 'sar', 'riyal', 'riyals', 'sr', 'product', 'products', 'pet', 'pets', 'it', 'that', 'this', 'from', 'on', 'or',
			)
		);
	}

	/**
	 * Dictionary: stem sequence => concepts.
	 *
	 * @return array
	 */
	private static function dict() {
		if ( null !== self::$dict ) {
			return self::$dict;
		}
		$d = array();
		foreach ( self::lexicon() as $row ) {
			foreach ( (array) $row[1] as $w ) {
				$seq = self::stemseq( $w );
				if ( '' === $seq ) {
					continue;
				}
				foreach ( (array) $row[0] as $cc ) {
					$d[ $seq ][] = $cc;
				}
			}
		}
		foreach ( $d as $k => $list ) {
			$d[ $k ] = array_values( array_unique( $list, SORT_REGULAR ) );
		}
		self::$dict = $d;
		return $d;
	}

	/**
	 * Stop set.
	 *
	 * @return array
	 */
	private static function stop() {
		if ( null !== self::$stop ) {
			return self::$stop;
		}
		$s = array();
		foreach ( self::stop_words() as $w ) {
			foreach ( self::tokens( $w ) as $tk ) {
				$s[ (string) $tk[1] ] = 1;
				$s[ (string) $tk[2] ] = 1;
			}
		}
		self::$stop = $s;
		return $s;
	}

	/**
	 * Product type definitions: labels and category-name keywords.
	 *
	 * @return array
	 */
	public static function type_defs() {
		return apply_filters(
			'dokan_app_search_types',
			array(
				'food'        => array( 'label' => array( 'أكل', 'Food' ), 'cat' => array( 'طعام', 'اكل', 'غذاء', 'اغذيه', 'وجبات', 'food', 'foods' ), 'exclude' => array( 'مكافات', 'حلويات', 'treat', 'treats', 'snack', 'snacks' ) ),
				'dry'         => array( 'label' => array( 'أكل جاف', 'Dry food' ), 'cat' => array( 'جاف', 'dry', 'دراي' ), 'parent' => 'food' ),
				'wet'         => array( 'label' => array( 'أكل رطب', 'Wet food' ), 'cat' => array( 'رطب', 'معلب', 'معلبات', 'wet', 'ويت', 'canned' ), 'parent' => 'food' ),
				'litter'      => array( 'label' => array( 'رمل', 'Litter' ), 'cat' => array( 'رمل', 'litter', 'ليتر' ), 'exclude' => array( 'صندوق', 'صناديق', 'حمام', 'حمامات', 'كرسي', 'box', 'boxes', 'toilet', 'tray' ) ),
				'toys'        => array( 'label' => array( 'ألعاب', 'Toys' ), 'cat' => array( 'العاب', 'لعب', 'لعبه', 'toy', 'toys' ) ),
				'treats'      => array( 'label' => array( 'مكافآت', 'Treats' ), 'cat' => array( 'مكافات', 'مكافاه', 'حلويات', 'سناك', 'treat', 'treats', 'snack', 'snacks' ) ),
				'grooming'    => array( 'label' => array( 'عناية واستحمام', 'Grooming' ), 'cat' => array( 'عنايه', 'شامبو', 'استحمام', 'نظافه', 'grooming', 'shampoo', 'shampoos' ) ),
				'health'      => array( 'label' => array( 'فيتامينات ومكملات', 'Vitamins & health' ), 'cat' => array( 'فيتامين', 'فيتامينات', 'مكمل', 'مكملات', 'صحه', 'صحيه', 'ادويه', 'علاج', 'vitamin', 'vitamins', 'supplement', 'supplements', 'health' ) ),
				'accessories' => array( 'label' => array( 'أطواق وأحزمة', 'Collars & leashes' ), 'cat' => array( 'طوق', 'اطواق', 'رسن', 'حزام', 'احزمه', 'collar', 'collars', 'leash', 'leashes', 'harness' ) ),
				'carriers'    => array( 'label' => array( 'حقائب وأقفاص', 'Carriers & cages' ), 'cat' => array( 'حقيبه', 'حقائب', 'شنط', 'شنطه', 'قفص', 'اقفاص', 'بوكس', 'نقل', 'carrier', 'carriers', 'cage', 'cages' ) ),
				'beds'        => array( 'label' => array( 'أسرّة وبيوت', 'Beds & houses' ), 'cat' => array( 'سرير', 'اسره', 'فرشه', 'فرش', 'بيت', 'بيوت', 'bed', 'beds', 'house', 'houses' ) ),
				'scratchers'  => array( 'label' => array( 'مخدش', 'Scratchers' ), 'cat' => array( 'مخدش', 'خدش', 'خربشه', 'سكراتشر', 'scratcher', 'scratchers', 'شجره', 'اشجار' ) ),
				'bowls'       => array( 'label' => array( 'صحون ونوافير', 'Bowls & fountains' ), 'cat' => array( 'صحن', 'صحون', 'وعاء', 'اوعيه', 'ماعون', 'سقايه', 'نافوره', 'نوافير', 'معلف', 'bowl', 'bowls', 'fountain', 'fountains', 'feeder', 'feeders' ) ),
				'litter_box'  => array( 'label' => array( 'صناديق الرمل', 'Litter boxes' ), 'cat' => array( 'صندوق', 'صناديق', 'حمامات', 'كرسي', 'toilet', 'toilets', 'tray' ) ),
			)
		);
	}

	/**
	 * Title words per concept (single tokens, stemmed) for in-title matching.
	 *
	 * @return array
	 */
	private static function title_words() {
		static $tw = null;
		if ( null !== $tw ) {
			return $tw;
		}
		$tw = array(
			'type'   => array(),
			'pet'    => array(),
			'stage'  => array(),
			'health' => array(),
		);
		foreach ( self::lexicon() as $row ) {
			foreach ( (array) $row[0] as $cc ) {
				if ( ( 'type' !== $cc[0] && 'pet' !== $cc[0] ) || ( isset( $cc[2] ) && 'weak' === $cc[2] ) ) {
					continue;
				}
				foreach ( (array) $row[1] as $w ) {
					if ( false === strpos( trim( $w ), ' ' ) ) {
						$tw[ $cc[0] ][ $cc[1] ] = ( isset( $tw[ $cc[0] ][ $cc[1] ] ) ? $tw[ $cc[0] ][ $cc[1] ] : array() ) + self::stems_of( array( $w ) );
					}
				}
			}
		}
		foreach ( self::type_defs() as $k => $def ) {
			$tw['type'][ $k ] = ( isset( $tw['type'][ $k ] ) ? $tw['type'][ $k ] : array() ) + self::stems_of( $def['cat'] );
		}
		$tw['stage'] = array(
			'junior' => self::stems_of( array( 'kitten', 'kittens', 'puppy', 'puppies', 'junior', 'كيتن', 'جرو', 'جراء', 'صغير', 'صغيره', 'صغار', 'بيبي', 'baby', 'babycat', 'starter', 'رضيع', 'جونيور', 'مولود' ) ),
			'adult'  => self::stems_of( array( 'adult', 'بالغ', 'بالغه', 'بالغين', 'ادلت' ) ),
			'senior' => self::stems_of( array( 'senior', 'mature', 'ageing', 'aging', 'مسن', 'مسنه', 'سينيور', 'كبار' ) ),
		);
		$tw['health'] = array(
			'sterilised' => self::stems_of( array( 'sterilised', 'sterilized', 'sterile', 'neutered', 'spayed', 'معقم', 'معقمه', 'تعقيم', 'مخصي' ) ),
			'hairball'   => self::stems_of( array( 'hairball', 'hairballs', 'شعر' ) ),
			'weight'     => self::stems_of( array( 'light', 'weight', 'slim', 'وزن', 'سمنه', 'دايت', 'رجيم', 'لايت' ) ),
			'urinary'    => self::stems_of( array( 'urinary', 'بول', 'بولي', 'بوليه', 'مسالك' ) ),
			'sensitive'  => self::stems_of( array( 'sensitive', 'digestive', 'digestion', 'حساس', 'حساسه', 'حساسيه', 'هضم', 'هضمي', 'معده' ) ),
			'coat'       => self::stems_of( array( 'coat', 'skin', 'fur', 'جلد', 'فرو', 'لمعان', 'وبر' ) ),
		);
		return $tw;
	}

	/**
	 * Built-in brand transliterations (kept small; the taxonomy supplies the rest).
	 *
	 * @return array slug => aliases
	 */
	private static function brand_aliases() {
		return apply_filters(
			'dokan_app_search_brand_aliases',
			array(
				'royal-canin' => array( 'royal canin', 'royalcanin', 'رويال كانين', 'رويال كنين', 'رويل كانين' ),
				'whiskas'     => array( 'whiskas', 'ويسكاس', 'وسكاس', 'ويسكس' ),
				'felix'       => array( 'felix', 'فيلكس', 'فليكس' ),
				'purina'      => array( 'purina', 'بورينا', 'بيورينا' ),
				'pro-plan'    => array( 'pro plan', 'proplan', 'برو بلان', 'بروبلان' ),
				'josera'      => array( 'josera', 'جوسيرا', 'جوزيرا' ),
				'josicat'     => array( 'josicat', 'josi cat', 'جوسي كات', 'جوسيكات' ),
				'reflex'      => array( 'reflex', 'ريفلكس', 'رفلكس' ),
				'hills'       => array( 'hills', 'hill s', 'هيلز', 'هيلس' ),
				'acana'       => array( 'acana', 'اكانا' ),
				'orijen'      => array( 'orijen', 'اورجين', 'اوريجين' ),
			)
		);
	}

	/**
	 * Is a brand name usable as a matching phrase (not a generic word)?
	 *
	 * @param string $seq Stem sequence.
	 * @return bool
	 */
	private static function brand_seq_ok( $seq ) {
		if ( '' === $seq ) {
			return false;
		}
		$parts = explode( ' ', $seq );
		if ( 1 === count( $parts ) ) {
			$dict = self::dict();
			$stop = self::stop();
			if ( self::len( $seq ) < 3 || isset( $dict[ $seq ] ) || isset( $stop[ $seq ] ) || preg_match( '/^\d+$/', $seq ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Brand entries from index brands + the alias map.
	 *
	 * @param array $brands gid => {names[], slugs[], ids[], k}.
	 * @return array key => {label_ar, label_en, phrases[], ids[], k, slugs[]}
	 */
	public static function brand_entries( $brands ) {
		$entries = array();
		foreach ( (array) $brands as $gid => $b ) {
			$phr = array();
			$ar  = '';
			$en  = '';
			foreach ( (array) $b['names'] as $name ) {
				$name = (string) $name;
				if ( preg_match( '/\p{Arabic}/u', $name ) ) {
					if ( '' === $ar ) {
						$ar = $name;
					}
				} elseif ( '' === $en ) {
					$en = $name;
				}
				$seq = self::stemseq( $name );
				if ( self::brand_seq_ok( $seq ) ) {
					$phr[ $seq ] = 1;
				}
			}
			$slugs = array();
			foreach ( isset( $b['slugs'] ) ? (array) $b['slugs'] : array() as $sl ) {
				$sl      = preg_replace( '/-\d+$/', '', (string) $sl );
				$slugs[] = $sl;
				$seq     = self::stemseq( str_replace( '-', ' ', $sl ) );
				if ( self::brand_seq_ok( $seq ) ) {
					$phr[ $seq ] = 1;
				}
			}
			$entries[ 'b' . $gid ] = array(
				'label_ar' => '' !== $ar ? $ar : $en,
				'label_en' => '' !== $en ? $en : $ar,
				'phrases'  => array_map( 'strval', array_keys( $phr ) ),
				'ids'      => array_map( 'intval', (array) $b['ids'] ),
				'k'        => isset( $b['k'] ) ? (int) $b['k'] : 0,
				'slugs'    => $slugs,
			);
		}
		foreach ( self::brand_aliases() as $slug => $aliases ) {
			$seqs = array();
			$ar   = '';
			$en   = '';
			foreach ( (array) $aliases as $al ) {
				$s = self::stemseq( $al );
				if ( '' !== $s ) {
					$seqs[ $s ] = 1;
				}
				if ( preg_match( '/\p{Arabic}/u', $al ) ) {
					if ( '' === $ar ) {
						$ar = $al;
					}
				} elseif ( '' === $en ) {
					$en = ucwords( $al );
				}
			}
			$seqs  = array_map( 'strval', array_keys( $seqs ) );
			$found = '';
			foreach ( $entries as $k => $e ) {
				if ( array_intersect( $e['phrases'], $seqs ) || in_array( $slug, $e['slugs'], true ) ) {
					$found = $k;
					break;
				}
			}
			if ( '' !== $found ) {
				$entries[ $found ]['phrases'] = array_values( array_unique( array_merge( $entries[ $found ]['phrases'], $seqs ) ) );
				if ( ! preg_match( '/\p{Arabic}/u', $entries[ $found ]['label_ar'] ) && '' !== $ar ) {
					$entries[ $found ]['label_ar'] = $ar;
				}
				if ( preg_match( '/\p{Arabic}/u', $entries[ $found ]['label_en'] ) && '' !== $en ) {
					$entries[ $found ]['label_en'] = $en;
				}
			} else {
				$entries[ 'x:' . $slug ] = array(
					'label_ar' => '' !== $ar ? $ar : $en,
					'label_en' => '' !== $en ? $en : $ar,
					'phrases'  => $seqs,
					'ids'      => array(),
					'k'        => 0,
					'slugs'    => array( $slug ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Brand entries for the current language's index (cached per request).
	 *
	 * @return array
	 */
	public static function brand_entries_cached() {
		$lang = self::lang();
		if ( ! isset( self::$brand_cache[ $lang ] ) ) {
			$idx                        = self::index();
			self::$brand_cache[ $lang ] = self::brand_entries( isset( $idx['brands'] ) ? $idx['brands'] : array() );
		}
		return self::$brand_cache[ $lang ];
	}

	/**
	 * Phrase → brand key map.
	 *
	 * @param array $entries Entries.
	 * @return array
	 */
	private static function brand_phrase_map( $entries ) {
		$map  = array();
		$best = array();
		foreach ( $entries as $key => $e ) {
			foreach ( $e['phrases'] as $p ) {
				if ( ! isset( $map[ $p ] ) || $e['k'] > $best[ $p ] ) {
					$map[ $p ]  = $key;
					$best[ $p ] = $e['k'];
				}
			}
		}
		return $map;
	}

	/**
	 * Brand label in the current language.
	 *
	 * @param array $e Entry.
	 * @return string
	 */
	private static function brand_label( $e ) {
		return (string) self::t( $e['label_ar'], $e['label_en'] );
	}

	/**
	 * Match a free-text brand name (from Claude) to a brand key.
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	public static function match_brand_name( $name ) {
		$seq = self::stemseq( (string) $name );
		if ( '' === $seq ) {
			return null;
		}
		$entries = self::brand_entries_cached();
		$map     = self::brand_phrase_map( $entries );
		if ( isset( $map[ $seq ] ) ) {
			return $map[ $seq ];
		}
		foreach ( $entries as $k => $e ) {
			if ( self::stemseq( $e['label_ar'] ) === $seq || self::stemseq( $e['label_en'] ) === $seq ) {
				return $k;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * Parser
	 * ------------------------------------------------------------------ */

	/**
	 * Empty concepts.
	 *
	 * @return array
	 */
	public static function empty_concepts() {
		return array(
			'pets'      => array(),
			'types'     => array(),
			'stage'     => null,
			'health'    => array(),
			'brands'    => array(),
			'min_price' => null,
			'max_price' => null,
			'sort'      => null,
			'on_sale'   => false,
			'keywords'  => array(),
			'topics'    => array(),
		);
	}

	/**
	 * Parse a query into concepts.
	 *
	 * @param string     $q             Query.
	 * @param array|null $brand_entries Brand entries (null = from the index).
	 * @param string     $ctx_kind      Pet kind from context (resolves the ambiguous "بس").
	 * @return array
	 */
	public static function parse( $q, $brand_entries = null, $ctx_kind = '' ) {
		$c    = self::empty_concepts();
		$toks = self::tokens( $q );
		$n    = count( $toks );
		if ( 0 === $n ) {
			return $c;
		}
		$disp = array();
		$fold = array();
		$stem = array();
		$alt  = array();
		foreach ( $toks as $i => $tk ) {
			$disp[ $i ] = $tk[0];
			$stem[ $i ] = (string) $tk[1];
			$fold[ $i ] = (string) $tk[2];
			$alt[ $i ]  = (string) self::alt_stem( $tk[2] );
		}
		$used = array_fill( 0, $n, false );

		// "+7" / "7+" → senior.
		if ( preg_match( '/\+\s*7(?![\d.])|(?<![\d.])7\s*\+/', self::digits( (string) $q ) ) ) {
			$c['stage'] = 'senior';
			foreach ( $fold as $i => $f ) {
				if ( '7' === $f ) {
					$used[ $i ] = true;
					break;
				}
			}
		}
		self::parse_numbers( $c, $fold, $stem, $used );

		if ( null === $brand_entries ) {
			$brand_entries = self::brand_entries_cached();
		}
		$bmap  = self::brand_phrase_map( $brand_entries );
		$dict  = self::dict();
		$stop  = self::stop();
		$units = self::unit_set();
		$weak  = array();

		for ( $i = 0; $i < $n; $i++ ) {
			if ( $used[ $i ] ) {
				continue;
			}
			$hit = 0;
			for ( $len = min( 3, $n - $i ); $len >= 1 && ! $hit; $len-- ) {
				$free = true;
				for ( $j = $i; $j < $i + $len; $j++ ) {
					if ( $used[ $j ] ) {
						$free = false;
						break;
					}
				}
				if ( ! $free ) {
					continue;
				}
				$tail = $len > 1 ? ' ' . implode( ' ', array_slice( $stem, $i + 1, $len - 1 ) ) : '';
				$keys = array( $stem[ $i ] . $tail );
				if ( '' !== $alt[ $i ] ) {
					$keys[] = $alt[ $i ] . $tail;
				}
				foreach ( $keys as $k ) {
					if ( isset( $bmap[ $k ] ) ) {
						if ( ! in_array( $bmap[ $k ], $c['brands'], true ) ) {
							$c['brands'][] = $bmap[ $k ];
						}
						$hit = $len;
						break;
					}
					if ( isset( $dict[ $k ] ) ) {
						foreach ( $dict[ $k ] as $cc ) {
							self::apply_concept( $c, $cc, $weak );
						}
						$hit = $len;
						break;
					}
				}
			}
			if ( $hit ) {
				for ( $j = $i; $j < $i + $hit; $j++ ) {
					$used[ $j ] = true;
				}
				$i += $hit - 1;
				continue;
			}
			$s = $stem[ $i ];
			$a = $alt[ $i ];
			if ( isset( $stop[ $s ] ) || isset( $stop[ $fold[ $i ] ] ) || ( '' !== $a && isset( $stop[ $a ] ) ) ) {
				continue;
			}
			$is_num = (bool) preg_match( '/^\d+(\.\d+)?$/', $s );
			if ( $is_num ) {
				if ( ! ( $i + 1 < $n && ! $used[ $i + 1 ] && isset( $units[ $stem[ $i + 1 ] ] ) ) ) {
					continue; // Bare numbers are noise unless followed by a unit ("2 كجم").
				}
			} elseif ( isset( $units[ $s ] ) ) {
				if ( ! ( $i > 0 && ! $used[ $i - 1 ] && preg_match( '/^\d/', $stem[ $i - 1 ] ) ) ) {
					continue;
				}
			} elseif ( self::len( $s ) < 2 ) {
				continue;
			}
			$dup = false;
			foreach ( $c['keywords'] as $kw ) {
				if ( $kw['s'] === $s ) {
					$dup = true;
					break;
				}
			}
			if ( ! $dup && count( $c['keywords'] ) < 6 ) {
				$c['keywords'][] = array(
					'w' => $disp[ $i ],
					's' => $s,
					'a' => $a,
				);
			}
		}

		$c['types'] = self::collapse_types( $c['types'] );
		$weak_pets  = array();
		foreach ( $weak as $w ) {
			if ( 0 === strpos( $w, 'stage:' ) ) {
				// "كبير" / "صغير" are a life stage only next to food-like products (or with just a pet);
				// next to cages, beds, bowls … they are sizes.
				$foodish = array( 'food', 'dry', 'wet', 'treats', 'health' );
				if ( null === $c['stage'] && ( array_intersect( $c['types'], $foodish ) || ( empty( $c['types'] ) && ! empty( $c['pets'] ) ) ) ) {
					$c['stage'] = substr( $w, 6 );
				}
			} else {
				$weak_pets[] = $w;
			}
		}
		if ( empty( $c['pets'] ) && ! empty( $weak_pets ) && ( '' === (string) $ctx_kind || in_array( $ctx_kind, $weak_pets, true ) ) ) {
			$c['pets'][] = $weak_pets[0];
		}
		return $c;
	}

	/**
	 * Wet / dry imply food: keep the specific one.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	private static function collapse_types( $types ) {
		$types = array_values( array_unique( $types ) );
		if ( in_array( 'food', $types, true ) && ( in_array( 'wet', $types, true ) || in_array( 'dry', $types, true ) ) ) {
			$types = array_values( array_diff( $types, array( 'food' ) ) );
		}
		if ( in_array( 'litter_box', $types, true ) ) {
			$types = array_values( array_diff( $types, array( 'litter' ) ) );
		}
		return $types;
	}

	/**
	 * Apply one dictionary concept.
	 *
	 * @param array $c    Concepts (by ref).
	 * @param array $cc   Concept.
	 * @param array $weak Weak pets (by ref).
	 */
	private static function apply_concept( &$c, $cc, &$weak ) {
		$field = $cc[0];
		$val   = $cc[1];
		$flag  = isset( $cc[2] ) ? $cc[2] : '';
		switch ( $field ) {
			case 'pet':
				if ( 'weak' === $flag ) {
					$weak[] = $val;
				} elseif ( ! in_array( $val, $c['pets'], true ) ) {
					$c['pets'][] = $val;
				}
				break;
			case 'type':
			case 'health':
			case 'topic':
				$key = 'type' === $field ? 'types' : ( 'topic' === $field ? 'topics' : 'health' );
				if ( ! in_array( $val, $c[ $key ], true ) ) {
					$c[ $key ][] = $val;
				}
				break;
			case 'stage':
				if ( 'weak' === $flag ) {
					$weak[] = 'stage:' . $val;
				} elseif ( null === $c['stage'] ) {
					$c['stage'] = $val;
				}
				break;
			case 'sort':
				if ( null === $c['sort'] ) {
					$c['sort'] = $val;
				}
				break;
			case 'sale':
				$c['on_sale'] = true;
				break;
		}
	}

	/**
	 * Prices ("اقل من 100", "تحت 50", "بحدود 80", "من 50 الى 100", "100 ريال", "under 50"),
	 * ages ("عمرها 3 شهور") and pet weight ("وزنه 4 كيلو").
	 *
	 * @param array $c    Concepts (by ref).
	 * @param array $fold Folded tokens.
	 * @param array $stem Stems.
	 * @param array $used Used flags (by ref).
	 */
	private static function parse_numbers( &$c, $fold, $stem, &$used ) {
		$n     = count( $fold );
		$units = self::unit_set();
		$num   = function ( $i ) use ( $fold, $n, &$used ) {
			if ( $i < $n && ! $used[ $i ] && preg_match( '/^\d+(\.\d+)?$/', $fold[ $i ] ) ) {
				$v = (float) $fold[ $i ];
				return $v > 0 ? $v : null;
			}
			return null;
		};
		$cur     = array_flip( array( 'ريال', 'ريالات', 'رس', 'sar', 'riyal', 'riyals', 'rial', 'sr' ) );
		$eat_cur = function ( $j ) use ( $fold, $n, &$used, $cur ) {
			if ( $j < $n && isset( $cur[ $fold[ $j ] ] ) ) {
				$used[ $j ] = true;
			} elseif ( $j + 1 < $n && 'ر' === $fold[ $j ] && 'س' === $fold[ $j + 1 ] ) {
				$used[ $j ]     = true;
				$used[ $j + 1 ] = true;
			}
		};
		$mark    = function ( $from, $to ) use ( &$used ) {
			for ( $k = $from; $k <= $to; $k++ ) {
				$used[ $k ] = true;
			}
		};
		$words   = array(
			'max'    => array( 'تحت', 'اقل', 'باقل', 'ارخص', 'اقصى', 'under', 'below', 'max', 'maximum', 'less', 'cheaper', 'upto' ),
			'min'    => array( 'فوق', 'اكثر', 'باكثر', 'اكبر', 'اعلى', 'over', 'above', 'min', 'minimum', 'more' ),
			'around' => array( 'بحدود', 'حدود', 'حوالي', 'تقريبا', 'around', 'about', 'approx', 'approximately' ),
		);
		$joiners = array( 'من', 'عن', 'than', 'of', 'ب', 'to' );
		$to_w    = array( 'الى', 'لين', 'ل', 'و', 'to', 'and' );
		$age     = array(
			'm' => array( 'شهر', 'شهور', 'اشهر', 'month', 'months' ),
			'w' => array( 'اسبوع', 'اسابيع', 'week', 'weeks' ),
			'y' => array( 'سنه', 'سنين', 'سنوات', 'year', 'years', 'yr', 'yrs' ),
		);
		// The lists are compared with folded tokens (ى→ي, أ→ا …).
		foreach ( $words as $wk => $wl ) {
			$words[ $wk ] = array_map( array( __CLASS__, 'fold' ), $wl );
		}
		$joiners = array_map( array( __CLASS__, 'fold' ), $joiners );
		$to_w    = array_map( array( __CLASS__, 'fold' ), $to_w );
		$neg_v   = array_map( array( __CLASS__, 'fold' ), array( 'يتعدى', 'تتعدى', 'يتجاوز', 'تتجاوز', 'يزيد', 'تزيد' ) );
		$set_stage = function ( $months ) use ( &$c ) {
			if ( null === $c['stage'] ) {
				$c['stage'] = $months < 12 ? 'junior' : ( $months >= 84 ? 'senior' : 'adult' );
			}
		};

		for ( $i = 0; $i < $n; $i++ ) {
			if ( $used[ $i ] ) {
				continue;
			}
			$t = $fold[ $i ];

			// Range: من|بين|from|between N (الى|و|to|and)? M.
			if ( in_array( $t, array( 'من', 'بين', 'from', 'between' ), true ) ) {
				$a = $num( $i + 1 );
				if ( null !== $a ) {
					$j = $i + 2;
					if ( $j < $n && in_array( $fold[ $j ], $to_w, true ) ) {
						$j++;
					}
					$b = $num( $j );
					if ( null !== $b ) {
						$c['min_price'] = min( $a, $b );
						$c['max_price'] = max( $a, $b );
						$mark( $i, $j );
						$eat_cur( $j + 1 );
						$i = $j;
						continue;
					}
				}
			}
			// "ما يتعدى / لا يتجاوز / ما يزيد عن N".
			if ( in_array( $t, array( 'ما', 'لا' ), true ) && $i + 1 < $n && in_array( $fold[ $i + 1 ], $neg_v, true ) ) {
				$j = $i + 2;
				if ( $j < $n && in_array( $fold[ $j ], $joiners, true ) ) {
					$j++;
				}
				$v = $num( $j );
				if ( null !== $v ) {
					$c['max_price'] = $v;
					$mark( $i, $j );
					$eat_cur( $j + 1 );
					$i = $j;
					continue;
				}
			}
			foreach ( $words as $kind => $list ) {
				if ( ! in_array( $t, $list, true ) ) {
					continue;
				}
				$j = $i + 1;
				if ( $j < $n && in_array( $fold[ $j ], $joiners, true ) ) {
					$j++;
				}
				if ( 'ارخص' === $t && $j === $i + 1 ) {
					break; // "ارخص" alone is a sort word.
				}
				$v = $num( $j );
				if ( null === $v ) {
					break;
				}
				if ( 'max' === $kind ) {
					$c['max_price'] = $v;
				} elseif ( 'min' === $kind ) {
					$c['min_price'] = $v;
				} else {
					$c['min_price'] = round( $v * 0.8, 2 );
					$c['max_price'] = round( $v * 1.2, 2 );
				}
				$mark( $i, $j );
				$eat_cur( $j + 1 );
				$i = $j;
				continue 2;
			}

			$v = $num( $i );
			if ( null !== $v ) {
				// "N ريال" / "N ر.س" → budget.
				if ( $i + 1 < $n && ( isset( $cur[ $fold[ $i + 1 ] ] ) || ( 'ر' === $fold[ $i + 1 ] && $i + 2 < $n && 'س' === $fold[ $i + 2 ] ) ) ) {
					if ( null === $c['max_price'] && null === $c['min_price'] ) {
						$c['max_price'] = $v;
					}
					$used[ $i ] = true;
					$eat_cur( $i + 1 );
					continue;
				}
				// Ages.
				if ( $i + 1 < $n ) {
					$nx = $fold[ $i + 1 ];
					if ( in_array( $nx, $age['m'], true ) || in_array( $nx, $age['w'], true ) || in_array( $nx, $age['y'], true ) ) {
						$months = in_array( $nx, $age['y'], true ) ? $v * 12 : ( in_array( $nx, $age['w'], true ) ? $v / 4 : $v );
						$set_stage( $months );
						$mark( $i, $i + 1 );
						$i++;
						continue;
					}
				}
			}
			if ( in_array( $t, array( 'شهرين', 'اسبوعين' ), true ) ) {
				$set_stage( 2 );
				$used[ $i ] = true;
				continue;
			}
			if ( 'سنتين' === $t ) {
				$set_stage( 24 );
				$used[ $i ] = true;
				continue;
			}
			// Pet weight ("وزنه 4 كيلو", "weighs 4 kg") — not a weight-control need.
			if ( ( 0 === strpos( $t, 'وزن' ) || in_array( $t, array( 'weighs', 'weight' ), true ) ) && null !== $num( $i + 1 ) ) {
				$mark( $i, $i + 1 );
				if ( $i + 2 < $n && isset( $units[ $stem[ $i + 2 ] ] ) ) {
					$used[ $i + 2 ] = true;
				}
				$i++;
				continue;
			}
		}
		if ( null !== $c['min_price'] && null !== $c['max_price'] && $c['min_price'] > $c['max_price'] ) {
			$tmp            = $c['min_price'];
			$c['min_price'] = $c['max_price'];
			$c['max_price'] = $tmp;
		}
	}

	/**
	 * Keyword entries from free strings (used for Claude's keywords).
	 *
	 * @param array $strings Strings.
	 * @param array $c       Current concepts (brand tokens are skipped).
	 * @return array
	 */
	private static function make_keywords( $strings, $c ) {
		$dict  = self::dict();
		$stop  = self::stop();
		$skip  = array();
		$be    = self::brand_entries_cached();
		foreach ( $c['brands'] as $b ) {
			if ( isset( $be[ $b ] ) ) {
				foreach ( $be[ $b ]['phrases'] as $p ) {
					foreach ( explode( ' ', $p ) as $tok ) {
						$skip[ $tok ] = 1;
					}
				}
			}
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $strings as $str ) {
			if ( ! is_string( $str ) ) {
				continue;
			}
			foreach ( self::tokens( self::sub( $str, 0, 40 ) ) as $tk ) {
				$s = (string) $tk[1];
				$a = (string) self::alt_stem( $tk[2] );
				if ( '' === $s || self::len( $s ) < 2 || isset( $seen[ $s ] ) || isset( $skip[ $s ] ) || isset( $stop[ $s ] ) || isset( $dict[ $s ] )
					|| ( '' !== $a && ( isset( $stop[ $a ] ) || isset( $dict[ $a ] ) ) ) || preg_match( '/^\d+(\.\d+)?$/', $s ) ) {
					continue;
				}
				$seen[ $s ] = 1;
				$out[]      = array(
					'w' => $tk[0],
					's' => $s,
					'a' => $a,
				);
				if ( count( $out ) >= 5 ) {
					break 2;
				}
			}
		}
		return $out;
	}

	/**
	 * Merge a validated Claude intent over rule-based concepts (only valid values override).
	 *
	 * @param array $c  Concepts.
	 * @param array $ai Intent from Dokan_App_Claude::validate_intent().
	 * @return array
	 */
	public static function merge_ai( $c, $ai ) {
		if ( ! empty( $ai['pet'] ) ) {
			$c['pets'] = array( $ai['pet'] );
		}
		if ( ! empty( $ai['type'] ) ) {
			$c['types'] = array( $ai['type'] );
		}
		if ( ! empty( $ai['stage'] ) ) {
			$c['stage'] = $ai['stage'];
		}
		if ( ! empty( $ai['health'] ) ) {
			$c['health'] = array_values( array_unique( $ai['health'] ) );
		}
		if ( ! empty( $ai['brand'] ) ) {
			$c['brands'] = array( $ai['brand'] );
		}
		if ( isset( $ai['min_price'] ) ) {
			$c['min_price'] = $ai['min_price'];
		}
		if ( isset( $ai['max_price'] ) ) {
			$c['max_price'] = $ai['max_price'];
		}
		if ( ! empty( $ai['sort'] ) ) {
			$c['sort'] = $ai['sort'];
		}
		if ( ! empty( $ai['on_sale'] ) ) {
			$c['on_sale'] = true;
		}
		if ( isset( $ai['keywords'] ) && is_array( $ai['keywords'] ) ) {
			$c['keywords'] = self::make_keywords( $ai['keywords'], $c );
		}
		$c['types'] = self::collapse_types( $c['types'] );
		return $c;
	}

	/**
	 * Fill missing slots from an earlier turn of the conversation.
	 *
	 * @param array $c Current.
	 * @param array $e Earlier.
	 * @return array
	 */
	public static function fill_from( $c, $e ) {
		foreach ( array( 'pets', 'types', 'health', 'brands' ) as $k ) {
			if ( empty( $c[ $k ] ) && ! empty( $e[ $k ] ) ) {
				$c[ $k ] = $e[ $k ];
			}
		}
		if ( null === $c['stage'] && null !== $e['stage'] ) {
			$c['stage'] = $e['stage'];
		}
		return $c;
	}

	/* ------------------------------------------------------------------
	 * Labels
	 * ------------------------------------------------------------------ */

	/**
	 * Pet chip label.
	 *
	 * @param string $k Kind.
	 * @return string
	 */
	public static function pet_label( $k ) {
		$l = array(
			'cat'   => array( 'قطط', 'Cats' ),
			'dog'   => array( 'كلاب', 'Dogs' ),
			'bird'  => array( 'طيور', 'Birds' ),
			'fish'  => array( 'أسماك', 'Fish' ),
			'small' => array( 'حيوانات صغيرة', 'Small animals' ),
		);
		return isset( $l[ $k ] ) ? self::t( $l[ $k ][0], $l[ $k ][1] ) : $k;
	}

	/**
	 * "for cats" phrase with stage / sterilised.
	 *
	 * @param string      $k     Kind.
	 * @param string|null $stage Stage.
	 * @param bool        $ster  Sterilised.
	 * @return string
	 */
	private static function pet_phrase( $k, $stage = null, $ster = false ) {
		if ( self::t( 'ar', 'en' ) === 'en' ) {
			$nouns = array(
				'cat'   => 'cats',
				'dog'   => 'dogs',
				'bird'  => 'birds',
				'fish'  => 'fish',
				'small' => 'small animals',
			);
			$noun  = isset( $nouns[ $k ] ) ? $nouns[ $k ] : 'pets';
			if ( 'junior' === $stage ) {
				$noun = 'cat' === $k ? 'kittens' : ( 'dog' === $k ? 'puppies' : 'young ' . $noun );
			}
			$adj = '';
			if ( 'adult' === $stage ) {
				$adj = 'adult ';
			} elseif ( 'senior' === $stage ) {
				$adj = 'senior ';
			}
			if ( $ster ) {
				$adj .= 'sterilised ';
			}
			return 'for ' . $adj . $noun;
		}
		$base = array(
			'cat'   => 'للقطط',
			'dog'   => 'للكلاب',
			'bird'  => 'للطيور',
			'fish'  => 'لأسماك الزينة',
			'small' => 'للحيوانات الصغيرة',
		);
		$p    = isset( $base[ $k ] ) ? $base[ $k ] : 'للحيوانات الأليفة';
		if ( 'junior' === $stage ) {
			$p = 'dog' === $k ? 'للجراء' : $p . ' الصغيرة';
		} elseif ( 'adult' === $stage ) {
			$p .= ' البالغة';
		} elseif ( 'senior' === $stage ) {
			$p .= ' كبار السن';
		}
		if ( $ster ) {
			$p .= ' المعقمة';
		}
		return $p;
	}

	/**
	 * Stage chip label.
	 *
	 * @param string $stage Stage.
	 * @param array  $pets  Pets.
	 * @return string
	 */
	private static function stage_label( $stage, $pets ) {
		if ( 'junior' === $stage ) {
			if ( in_array( 'dog', $pets, true ) ) {
				return self::t( 'جراء', 'Puppy' );
			}
			if ( in_array( 'cat', $pets, true ) ) {
				return self::t( 'صغار (كيتن)', 'Kitten' );
			}
			return self::t( 'صغار', 'Young' );
		}
		return 'adult' === $stage ? self::t( 'بالغة', 'Adult' ) : self::t( 'كبار السن', 'Senior' );
	}

	/**
	 * Health labels: [chip ar, chip en, phrase ar, phrase en].
	 *
	 * @return array
	 */
	private static function health_defs() {
		return array(
			'sterilised' => array( 'معقمة', 'Sterilised', 'للحيوانات المعقمة', 'for sterilised pets' ),
			'hairball'   => array( 'كرات الشعر', 'Hairball', 'لكرات الشعر', 'for hairballs' ),
			'weight'     => array( 'التحكم بالوزن', 'Weight control', 'للتحكم بالوزن', 'for weight control' ),
			'urinary'    => array( 'المسالك البولية', 'Urinary', 'لصحة المسالك البولية', 'for urinary health' ),
			'sensitive'  => array( 'معدة حساسة', 'Sensitive', 'للمعدة الحساسة', 'for sensitive digestion' ),
			'coat'       => array( 'الجلد والفرو', 'Skin & coat', 'لصحة الجلد والفرو', 'for skin & coat' ),
		);
	}

	/**
	 * Type label.
	 *
	 * @param string $t Type.
	 * @return string
	 */
	public static function type_label( $t ) {
		$defs = self::type_defs();
		return isset( $defs[ $t ] ) ? self::t( $defs[ $t ]['label'][0], $defs[ $t ]['label'][1] ) : $t;
	}

	/**
	 * Number for display.
	 *
	 * @param float|int $v Value.
	 * @return string
	 */
	private static function num_label( $v ) {
		$s = ( floor( $v ) == $v ) ? (string) (int) $v : rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' ); // phpcs:ignore
		return self::t( self::ar_digits( $s ), $s );
	}

	/**
	 * Price chip label.
	 *
	 * @param array $c Concepts.
	 * @return string
	 */
	private static function price_label( $c ) {
		$cur = self::t( 'ر.س', 'SAR' );
		if ( null !== $c['min_price'] && null !== $c['max_price'] ) {
			return self::t( 'من ', '' ) . self::num_label( $c['min_price'] ) . self::t( ' إلى ', '–' ) . self::num_label( $c['max_price'] ) . ' ' . $cur;
		}
		if ( null !== $c['max_price'] ) {
			return self::t( 'أقل من ', 'Under ' ) . self::num_label( $c['max_price'] ) . ' ' . $cur;
		}
		return self::t( 'أكثر من ', 'Over ' ) . self::num_label( $c['min_price'] ) . ' ' . $cur;
	}

	/**
	 * Price phrase for replies.
	 *
	 * @param array $c Concepts.
	 * @return string
	 */
	private static function price_phrase( $c ) {
		$cur = self::t( 'ر.س', 'SAR' );
		if ( null !== $c['min_price'] && null !== $c['max_price'] ) {
			return self::t( 'بين ', 'between ' ) . self::num_label( $c['min_price'] ) . self::t( ' و', ' and ' ) . self::num_label( $c['max_price'] ) . ' ' . $cur;
		}
		if ( null !== $c['max_price'] ) {
			return self::t( 'بأقل من ', 'under ' ) . self::num_label( $c['max_price'] ) . ' ' . $cur;
		}
		if ( null !== $c['min_price'] ) {
			return self::t( 'بأكثر من ', 'over ' ) . self::num_label( $c['min_price'] ) . ' ' . $cur;
		}
		return '';
	}

	/**
	 * "understood" chips.
	 *
	 * @param array      $c  Concepts.
	 * @param array|null $be Brand entries.
	 * @return array
	 */
	public static function understood( $c, $be = null ) {
		if ( null === $be ) {
			$be = self::brand_entries_cached();
		}
		$u = array();
		foreach ( $c['pets'] as $k ) {
			$u[] = array( 'type' => 'pet', 'label' => self::pet_label( $k ), 'value' => $k );
		}
		foreach ( $c['types'] as $t ) {
			$u[] = array( 'type' => 'type', 'label' => self::type_label( $t ), 'value' => $t );
		}
		if ( $c['stage'] ) {
			$u[] = array( 'type' => 'stage', 'label' => self::stage_label( $c['stage'], $c['pets'] ), 'value' => $c['stage'] );
		}
		$hd = self::health_defs();
		foreach ( $c['health'] as $h ) {
			if ( isset( $hd[ $h ] ) ) {
				$u[] = array( 'type' => 'health', 'label' => self::t( $hd[ $h ][0], $hd[ $h ][1] ), 'value' => $h );
			}
		}
		foreach ( $c['brands'] as $b ) {
			if ( isset( $be[ $b ] ) ) {
				$u[] = array( 'type' => 'brand', 'label' => self::brand_label( $be[ $b ] ), 'value' => (string) $b );
			}
		}
		if ( null !== $c['min_price'] || null !== $c['max_price'] ) {
			$u[] = array( 'type' => 'price', 'label' => self::price_label( $c ), 'value' => ( null !== $c['min_price'] ? (string) $c['min_price'] : '' ) . '-' . ( null !== $c['max_price'] ? (string) $c['max_price'] : '' ) );
		}
		if ( $c['sort'] ) {
			$sl  = array(
				'price_asc'  => array( 'الأرخص', 'Cheapest' ),
				'popularity' => array( 'الأكثر مبيعًا', 'Best sellers' ),
				'date'       => array( 'الأحدث', 'Newest' ),
			);
			$u[] = array( 'type' => 'sort', 'label' => self::t( $sl[ $c['sort'] ][0], $sl[ $c['sort'] ][1] ), 'value' => $c['sort'] );
		}
		if ( $c['on_sale'] ) {
			$u[] = array( 'type' => 'sort', 'label' => self::t( 'عروض وخصومات', 'On sale' ), 'value' => 'on_sale' );
		}
		foreach ( $c['keywords'] as $k ) {
			$u[] = array( 'type' => 'keyword', 'label' => (string) $k['w'], 'value' => (string) $k['s'] );
		}
		return $u;
	}

	/**
	 * Short description of what was searched, e.g. "أكل رطب للقطط البالغة".
	 *
	 * @param array  $c      Concepts.
	 * @param array  $be     Brand entries.
	 * @param string $q      Query (for keyword-only searches).
	 * @param bool   $use_kw Mention keywords.
	 * @return string
	 */
	private static function describe( $c, $be, $q, $use_kw, $skip_types = false ) {
		$en    = 'en' === self::t( 'ar', 'en' );
		$parts = array();
		if ( $c['types'] && ! $skip_types ) {
			$labels = array();
			foreach ( $c['types'] as $t ) {
				$labels[] = self::type_label( $t );
			}
			$parts[] = $en ? strtolower( implode( ' & ', $labels ) ) : implode( ' و', $labels );
		}
		foreach ( $c['brands'] as $b ) {
			if ( isset( $be[ $b ] ) ) {
				$parts[] = self::t( 'من ', 'from ' ) . self::brand_label( $be[ $b ] );
				break;
			}
		}
		$ster = in_array( 'sterilised', $c['health'], true );
		if ( $c['pets'] ) {
			$parts[] = self::pet_phrase( $c['pets'][0], $c['stage'], $ster );
		} elseif ( $c['stage'] ) {
			$sp      = array(
				'junior' => array( 'للصغار', 'for young pets' ),
				'adult'  => array( 'للبالغين', 'for adult pets' ),
				'senior' => array( 'لكبار السن', 'for senior pets' ),
			);
			$parts[] = self::t( $sp[ $c['stage'] ][0], $sp[ $c['stage'] ][1] ) . ( $ster ? self::t( ' المعقمة', '' ) : '' );
		} elseif ( $ster ) {
			$parts[] = self::t( 'للحيوانات المعقمة', 'for sterilised pets' );
		}
		$hd = self::health_defs();
		foreach ( $c['health'] as $h ) {
			if ( 'sterilised' !== $h && isset( $hd[ $h ] ) ) {
				$parts[] = self::t( $hd[ $h ][2], $hd[ $h ][3] );
			}
		}
		$pp = self::price_phrase( $c );
		if ( '' !== $pp ) {
			$parts[] = $pp;
		}
		if ( $c['on_sale'] ) {
			$parts[] = self::t( 'عليها خصم', 'on sale' );
		}
		if ( empty( $parts ) && empty( $c['types'] ) && ! empty( $c['keywords'] ) && $use_kw && '' !== $q ) {
			$short   = self::sub( $q, 0, 40 );
			$parts[] = self::t( 'لـ«' . $short . '»', 'for “' . $short . '”' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * "٢٤ منتج" / "24 products".
	 *
	 * @param int $n Count.
	 * @return string
	 */
	private static function count_phrase( $n, $types_en = '' ) {
		if ( 'en' === self::t( 'ar', 'en' ) ) {
			return $n . ' ' . ( '' !== $types_en ? $types_en . ' ' : '' ) . ( 1 === $n ? 'product' : 'products' );
		}
		if ( 1 === $n ) {
			return 'منتج واحد';
		}
		if ( 2 === $n ) {
			return 'منتجين';
		}
		return self::ar_digits( (string) $n ) . ' منتج';
	}

	/* ------------------------------------------------------------------
	 * Index
	 * ------------------------------------------------------------------ */

	/**
	 * Brand taxonomy (first that exists).
	 *
	 * @return string
	 */
	public static function brand_taxonomy() {
		$tax = '';
		foreach ( (array) apply_filters( 'dokan_app_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'brand', 'berocket_brand' ) ) as $t ) {
			if ( taxonomy_exists( $t ) ) {
				$tax = $t;
				break;
			}
		}
		return (string) apply_filters( 'dokan_app_brand_taxonomy', $tax );
	}

	/**
	 * Mark the index stale and schedule a background rebuild.
	 */
	public static function invalidate() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		update_option( self::OPT_DIRTY, time(), false );
		if ( ! wp_next_scheduled( self::CRON_REBUILD ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_REBUILD );
		}
	}

	/**
	 * Post trashed / deleted.
	 *
	 * @param int $post_id Post.
	 */
	public static function invalidate_post( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			self::invalidate();
		}
	}

	/**
	 * Term created / edited / deleted.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function invalidate_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_visibility', self::brand_taxonomy() ), true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Cron: rebuild every language.
	 */
	public static function cron_rebuild() {
		foreach ( self::index_languages() as $lang ) {
			self::build_and_store( $lang );
		}
	}

	/**
	 * Languages to index.
	 *
	 * @return string[]
	 */
	private static function index_languages() {
		return class_exists( 'Dokan_App_Helpers' ) ? Dokan_App_Helpers::languages() : array( 'ar' );
	}

	/**
	 * Option name of the index for a language.
	 *
	 * @param string $lang Lang.
	 * @return string
	 */
	private static function index_key( $lang ) {
		return self::OPT_INDEX . DOKAN_APP_VERSION . '_' . $lang;
	}

	/**
	 * Load (or build) the index for the current language.
	 *
	 * @return array
	 */
	public static function index() {
		$lang = self::lang();
		if ( isset( self::$index[ $lang ] ) ) {
			return self::$index[ $lang ];
		}
		$idx = get_option( self::index_key( $lang ) );
		$now = time();
		if ( is_array( $idx ) && isset( $idx['built'], $idx['p'] ) ) {
			$dirty = (int) get_option( self::OPT_DIRTY, 0 );
			$built = (int) $idx['built'];
			$stale = $dirty >= $built;
			if ( $now - $built > self::INDEX_TTL || ( $stale && $now - $dirty > 600 ) ) {
				if ( ! get_transient( self::LOCK . '_' . $lang ) ) {
					$fresh = self::build_and_store( $lang );
					if ( is_array( $fresh ) ) {
						$idx = $fresh;
					}
				}
			} elseif ( $stale && ! wp_next_scheduled( self::CRON_REBUILD ) ) {
				wp_schedule_single_event( $now + 30, self::CRON_REBUILD );
			}
		} else {
			$idx = self::build_and_store( $lang );
		}
		self::$index[ $lang ] = is_array( $idx ) ? $idx : self::empty_index();
		return self::$index[ $lang ];
	}

	/**
	 * Empty index shape.
	 *
	 * @return array
	 */
	private static function empty_index() {
		return array(
			'v'      => defined( 'DOKAN_APP_VERSION' ) ? DOKAN_APP_VERSION : '',
			'built'  => time(),
			'lang'   => 'ar',
			'p'      => array(),
			'vocab'  => array(),
			'cats'   => array(),
			'brands' => array(),
			'kinds'  => array(),
			'btax'   => '',
		);
	}

	/**
	 * Build and save.
	 *
	 * @param string $lang Lang.
	 * @return array|null
	 */
	public static function build_and_store( $lang ) {
		set_transient( self::LOCK . '_' . $lang, 1, 120 );
		try {
			$idx = self::build( $lang );
			update_option( self::index_key( $lang ), $idx, false );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Dokan_App_Helpers' ) ) {
				Dokan_App_Helpers::log( 'search index build failed: ' . $e->getMessage() );
			}
			$idx = null;
		}
		delete_transient( self::LOCK . '_' . $lang );
		unset( self::$index[ $lang ], self::$brand_cache[ $lang ] );
		return $idx;
	}

	/**
	 * Object ids attached to a term (by taxonomy + slug).
	 *
	 * @param string $tax  Taxonomy.
	 * @param string $slug Slug.
	 * @return int[]
	 */
	private static function objects_in_term( $tax, $slug ) {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s",
				$tax,
				$slug
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Polylang term translation groups: term id => ids in its group.
	 *
	 * @return array
	 */
	private static function term_translation_groups() {
		global $wpdb;
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$rows = $wpdb->get_col( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'term_translations'" ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$arr = maybe_unserialize( $r );
			if ( ! is_array( $arr ) ) {
				continue;
			}
			$ids = array();
			foreach ( $arr as $l => $id ) {
				if ( is_string( $l ) && 'sync' !== $l && is_numeric( $id ) ) {
					$ids[] = (int) $id;
				}
			}
			foreach ( $ids as $id ) {
				$out[ $id ] = $ids;
			}
		}
		return $out;
	}

	/**
	 * Build the index for one language with direct queries (no WC_Product objects).
	 *
	 * @param string $lang Lang.
	 * @return array
	 */
	private static function build( $lang ) {
		global $wpdb;
		$pll       = function_exists( 'pll_languages_list' );
		$brand_tax = self::brand_taxonomy();
		$taxes     = array( 'product_cat' );
		if ( '' !== $brand_tax ) {
			$taxes[] = $brand_tax;
		}

		$titles = array();
		$dates  = array();
		$rows   = $wpdb->get_results( "SELECT ID, post_title, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'", ARRAY_N ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$id            = (int) $r[0];
			$titles[ $id ] = (string) $r[1];
			$dates[ $id ]  = ( $r[2] && '0000-00-00 00:00:00' !== $r[2] ) ? (int) strtotime( $r[2] . ' UTC' ) : 0;
		}
		unset( $rows );

		if ( $pll && taxonomy_exists( 'language' ) ) {
			$in = self::objects_in_term( 'language', $lang );
			if ( ! empty( $in ) ) {
				$titles = array_intersect_key( $titles, array_flip( $in ) );
			} elseif ( 'ar' !== $lang ) {
				$titles = array(); // No products in that language.
			}
		}
		foreach ( self::objects_in_term( 'product_visibility', 'exclude-from-search' ) as $hid ) {
			unset( $titles[ $hid ] );
		}

		$price = array();
		$sales = array();
		$stock = array();
		$meta  = $wpdb->get_results( "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_key IN ('_price','total_sales','_stock_status')", ARRAY_N ); // phpcs:ignore WordPress.DB
		foreach ( (array) $meta as $m ) {
			$id = (int) $m[0];
			if ( ! isset( $titles[ $id ] ) ) {
				continue;
			}
			if ( '_price' === $m[1] ) {
				if ( '' !== $m[2] && is_numeric( $m[2] ) ) {
					$v = (float) $m[2];
					if ( $v > 0 && ( ! isset( $price[ $id ] ) || $v < $price[ $id ] ) ) {
						$price[ $id ] = $v;
					}
				}
			} elseif ( 'total_sales' === $m[1] ) {
				$sales[ $id ] = (int) $m[2];
			} else {
				$stock[ $id ] = (string) $m[2];
			}
		}
		unset( $meta );
		$on_sale = function_exists( 'wc_get_product_ids_on_sale' ) ? array_flip( array_map( 'intval', (array) wc_get_product_ids_on_sale() ) ) : array();

		$ph    = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		$term  = array();
		$trows = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.parent, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($ph)", $taxes ), ARRAY_N ); // phpcs:ignore WordPress.DB
		foreach ( (array) $trows as $t ) {
			$term[ (int) $t[0] ] = array( html_entity_decode( (string) $t[1], ENT_QUOTES, 'UTF-8' ), urldecode( (string) $t[2] ), (int) $t[3], (string) $t[4] );
		}
		unset( $trows );
		$pcats   = array();
		$pbrands = array();
		$rels    = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ($ph)", $taxes ), ARRAY_N ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rels as $r ) {
			$oid = (int) $r[0];
			$tid = (int) $r[1];
			if ( ! isset( $titles[ $oid ], $term[ $tid ] ) ) {
				continue;
			}
			if ( 'product_cat' === $term[ $tid ][3] ) {
				$pcats[ $oid ][] = $tid;
			} else {
				$pbrands[ $oid ][] = $tid;
			}
		}
		unset( $rels );
		$trans = self::term_translation_groups();

		$anc_cache = array();
		$ancestors = function ( $tid ) use ( &$anc_cache, $term ) {
			if ( isset( $anc_cache[ $tid ] ) ) {
				return $anc_cache[ $tid ];
			}
			$out  = array();
			$cur  = $tid;
			$hops = 0;
			while ( isset( $term[ $cur ] ) && $term[ $cur ][2] > 0 && $hops < 10 ) {
				$cur   = $term[ $cur ][2];
				$out[] = $cur;
				$hops++;
			}
			$anc_cache[ $tid ] = $out;
			return $out;
		};

		$P           = array();
		$vocab       = array();
		$cat_count   = array();
		$brand_count = array();
		foreach ( $titles as $id => $title ) {
			$cs = array();
			foreach ( isset( $pcats[ $id ] ) ? $pcats[ $id ] : array() as $tid ) {
				$cs[ $tid ] = 1;
				foreach ( $ancestors( $tid ) as $a ) {
					$cs[ $a ] = 1;
				}
			}
			$bs = isset( $pbrands[ $id ] ) ? array_values( array_unique( $pbrands[ $id ] ) ) : array();
			$st = array();
			foreach ( self::tokens( $title ) as $tk ) {
				$s = (string) $tk[1];
				if ( '' === $s || isset( $st[ $s ] ) ) {
					continue;
				}
				$st[ $s ] = 1;
				if ( ! isset( $vocab[ $s ] ) ) {
					$vocab[ $s ] = array( $tk[0], 0, self::len( $s ) );
				}
				$vocab[ $s ][1]++;
			}
			foreach ( $cs as $tid => $_ ) {
				$cat_count[ $tid ] = ( isset( $cat_count[ $tid ] ) ? $cat_count[ $tid ] : 0 ) + 1;
			}
			foreach ( $bs as $tid ) {
				$brand_count[ $tid ] = ( isset( $brand_count[ $tid ] ) ? $brand_count[ $tid ] : 0 ) + 1;
			}
			$P[ $id ] = array(
				't' => array_map( 'strval', array_keys( $st ) ),
				'c' => array_keys( $cs ),
				'b' => $bs,
				's' => isset( $sales[ $id ] ) ? $sales[ $id ] : 0,
				'i' => ( isset( $stock[ $id ] ) && 'outofstock' === $stock[ $id ] ) ? 0 : 1,
				'r' => isset( $price[ $id ] ) ? round( $price[ $id ], 2 ) : 0.0,
				'd' => isset( $dates[ $id ] ) ? $dates[ $id ] : 0,
				'o' => isset( $on_sale[ $id ] ) ? 1 : 0,
			);
		}
		unset( $titles );

		$cats = array();
		foreach ( $cat_count as $tid => $n ) {
			$names = array( $term[ $tid ][0], str_replace( '-', ' ', $term[ $tid ][1] ) );
			foreach ( isset( $trans[ $tid ] ) ? $trans[ $tid ] : array() as $o ) {
				if ( $o !== $tid && isset( $term[ $o ] ) ) {
					$names[] = $term[ $o ][0];
					$names[] = str_replace( '-', ' ', $term[ $o ][1] );
				}
			}
			$top = $tid;
			$anc = $ancestors( $tid );
			if ( ! empty( $anc ) ) {
				$top = end( $anc );
			}
			$cats[ $tid ] = array(
				'n'   => $term[ $tid ][0],
				'sl'  => $term[ $tid ][1],
				'p'   => $term[ $tid ][2],
				'k'   => $n,
				's'   => array_keys( self::stems_of( $names ) ),
				'top' => $top,
			);
			foreach ( self::tokens( $term[ $tid ][0] ) as $tk ) {
				$s = (string) $tk[1];
				if ( '' !== $s && ! isset( $vocab[ $s ] ) ) {
					$vocab[ $s ] = array( $tk[0], 1, self::len( $s ) );
				}
			}
		}
		foreach ( $cats as $tid => $ci ) {
			$cats[ $tid ]['s'] = array_map( 'strval', $ci['s'] );
		}

		$brands = array();
		foreach ( $brand_count as $tid => $n ) {
			$group = isset( $trans[ $tid ] ) ? $trans[ $tid ] : array( $tid );
			$gid   = min( $group );
			if ( ! isset( $brands[ $gid ] ) ) {
				$brands[ $gid ] = array(
					'names' => array(),
					'slugs' => array(),
					'ids'   => array(),
					'k'     => 0,
				);
			}
			foreach ( $group as $o ) {
				if ( isset( $term[ $o ] ) ) {
					$brands[ $gid ]['names'][] = $term[ $o ][0];
					$brands[ $gid ]['slugs'][] = $term[ $o ][1];
					$brands[ $gid ]['ids'][]   = $o;
				}
			}
			$brands[ $gid ]['k'] += $n;
		}
		foreach ( $brands as $gid => $b ) {
			$brands[ $gid ]['names'] = array_values( array_unique( $b['names'] ) );
			$brands[ $gid ]['slugs'] = array_values( array_unique( $b['slugs'] ) );
			$brands[ $gid ]['ids']   = array_values( array_unique( $b['ids'] ) );
			foreach ( $brands[ $gid ]['names'] as $nm ) {
				foreach ( self::tokens( $nm ) as $tk ) {
					$s = (string) $tk[1];
					if ( '' !== $s && ! isset( $vocab[ $s ] ) ) {
						$vocab[ $s ] = array( $tk[0], 1, self::len( $s ) );
					}
				}
			}
		}

		$kinds = array();
		foreach ( $cats as $tid => $ci ) {
			if ( 0 !== (int) $ci['p'] ) {
				continue;
			}
			$k = self::kind_for_stems( $ci['s'] );
			if ( '' !== $k ) {
				$kinds[ $k ][ $tid ] = $ci['k'];
			}
		}
		foreach ( $kinds as $k => $list ) {
			arsort( $list );
			$kinds[ $k ] = array_keys( $list );
		}

		return array(
			'v'      => DOKAN_APP_VERSION,
			'built'  => time(),
			'lang'   => $lang,
			'p'      => $P,
			'vocab'  => $vocab,
			'cats'   => $cats,
			'brands' => $brands,
			'kinds'  => $kinds,
			'btax'   => $brand_tax,
		);
	}

	/**
	 * Kind keyword sequences (reuses Dokan_App_Orders::kind_keywords()).
	 *
	 * @return array kind => list of token arrays
	 */
	private static function kind_seqs() {
		static $seqs = null;
		if ( null !== $seqs ) {
			return $seqs;
		}
		$kw   = class_exists( 'Dokan_App_Orders' ) ? Dokan_App_Orders::kind_keywords() : array();
		$seqs = array();
		foreach ( (array) $kw as $kind => $words ) {
			foreach ( (array) $words as $w ) {
				$s = self::stemseq( $w );
				if ( '' !== $s ) {
					$seqs[ $kind ][] = explode( ' ', $s );
				}
			}
		}
		return $seqs;
	}

	/**
	 * Pet kind of a category from its name stems.
	 *
	 * @param string[] $stems Stems.
	 * @return string
	 */
	private static function kind_for_stems( $stems ) {
		$set = array_flip( array_map( 'strval', (array) $stems ) );
		foreach ( self::kind_seqs() as $kind => $seqs ) {
			foreach ( $seqs as $seq ) {
				$all = true;
				foreach ( $seq as $tok ) {
					if ( ! isset( $set[ $tok ] ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return (string) $kind;
				}
			}
		}
		return '';
	}

	/**
	 * pet_categories for /config: kind => top-level category id (largest match) or null.
	 *
	 * @param array $cats Config categories [{id,name,slug,count}].
	 * @return array
	 */
	public static function pet_categories( $cats ) {
		$out  = array(
			'cat'   => null,
			'dog'   => null,
			'bird'  => null,
			'fish'  => null,
			'small' => null,
		);
		$best = array();
		foreach ( (array) $cats as $cat ) {
			$stems = array_keys( self::stems_of( array( (string) $cat['name'], str_replace( '-', ' ', (string) $cat['slug'] ) ) ) );
			$k     = self::kind_for_stems( $stems );
			if ( '' === $k || ! array_key_exists( $k, $out ) ) {
				continue;
			}
			if ( null === $out[ $k ] || (int) $cat['count'] > $best[ $k ] ) {
				$out[ $k ]  = (int) $cat['id'];
				$best[ $k ] = (int) $cat['count'];
			}
		}
		return $out;
	}

	/**
	 * Index status for the admin page.
	 *
	 * @return array
	 */
	public static function index_status() {
		$out = array();
		foreach ( self::index_languages() as $lang ) {
			$idx          = get_option( self::index_key( $lang ) );
			$out[ $lang ] = is_array( $idx ) ? array(
				'products' => count( $idx['p'] ),
				'built'    => (int) $idx['built'],
				'btax'     => (string) $idx['btax'],
				'brands'   => count( $idx['brands'] ),
			) : null;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Engine
	 * ------------------------------------------------------------------ */

	/**
	 * Soft context from a pet.
	 *
	 * @param array|null $pet Pet shape.
	 * @return array
	 */
	public static function context( $pet ) {
		$ctx = array(
			'kind'       => '',
			'young'      => false,
			'age_months' => null,
		);
		if ( is_array( $pet ) ) {
			$ctx['kind'] = isset( $pet['kind'] ) ? (string) $pet['kind'] : '';
			if ( ! empty( $pet['birth_date'] ) ) {
				$d = DateTime::createFromFormat( '!Y-m-d', (string) $pet['birth_date'] );
				if ( $d ) {
					$diff              = $d->diff( new DateTime( 'now' ) );
					$m                 = $diff->y * 12 + $diff->m;
					$ctx['age_months'] = $m;
					$ctx['young']      = $m < 12;
				}
			}
		}
		return $ctx;
	}

	/**
	 * Any key of $a in $b?
	 *
	 * @param array $a Set.
	 * @param array $b Set.
	 * @return bool
	 */
	private static function any( $a, $b ) {
		if ( count( $a ) > count( $b ) ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}
		foreach ( $a as $k => $_ ) {
			if ( isset( $b[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve types to categories (within the pet's top categories when given).
	 *
	 * @param string[] $types    Types.
	 * @param array    $pet_cats Pet top category ids (set).
	 * @param array    $idx      Index.
	 * @return array
	 */
	private static function resolve_types( $types, $pet_cats, $idx ) {
		$defs  = self::type_defs();
		$tw    = self::title_words();
		$cache = array();
		$find  = function ( $key ) use ( $defs, $idx, $pet_cats, &$cache ) {
			if ( isset( $cache[ $key ] ) ) {
				return $cache[ $key ];
			}
			$want = self::stems_of( $defs[ $key ]['cat'] );
			$excl = self::stems_of( isset( $defs[ $key ]['exclude'] ) ? $defs[ $key ]['exclude'] : array() );
			$res  = array();
			foreach ( $idx['cats'] as $cid => $ci ) {
				if ( $pet_cats && ! isset( $pet_cats[ $ci['top'] ] ) ) {
					continue;
				}
				$hit = false;
				foreach ( $ci['s'] as $s ) {
					if ( isset( $excl[ $s ] ) ) {
						$hit = false;
						break;
					}
					if ( isset( $want[ $s ] ) ) {
						$hit = true;
					}
				}
				if ( $hit ) {
					$res[ $cid ] = 1;
				}
			}
			$cache[ $key ] = $res;
			return $res;
		};
		$out   = array();
		foreach ( $types as $t ) {
			if ( ! isset( $defs[ $t ] ) ) {
				continue;
			}
			$cats   = $find( $t );
			$parent = isset( $defs[ $t ]['parent'] ) ? $defs[ $t ]['parent'] : '';
			$out[]  = array(
				'key'         => $t,
				'cats'        => $cats,
				'parent_cats' => ( '' !== $parent && isset( $defs[ $parent ] ) ) ? $find( $parent ) : array(),
				'words'       => isset( $tw['type'][ $t ] ) ? $tw['type'][ $t ] : array(),
			);
		}
		return $out;
	}

	/**
	 * Per-keyword maps: matching vocabulary stems with scores (exact 10 / alt 9 / prefix 6 / fuzzy 4)
	 * and matching category ids.
	 *
	 * @param array $keywords Keywords.
	 * @param array $vocab    Vocabulary.
	 * @param array $cats     Categories.
	 * @return array
	 */
	private static function keyword_maps( $keywords, $vocab, $cats ) {
		$out  = array();
		$last = count( $keywords ) - 1;
		foreach ( array_values( $keywords ) as $i => $k ) {
			$s      = (string) $k['s'];
			$a      = isset( $k['a'] ) ? (string) $k['a'] : '';
			$sl     = self::len( $s );
			$num    = (bool) preg_match( '/^\d/', $s );
			$map    = array();
			$strong = false;
			if ( isset( $vocab[ $s ] ) ) {
				$map[ $s ] = 10;
				$strong    = true;
			}
			if ( '' !== $a && isset( $vocab[ $a ] ) && ! isset( $map[ $a ] ) ) {
				$map[ $a ] = 9;
				$strong    = true;
			}
			$pre_ok = ! $num && ( $sl >= 3 || ( $i === $last && $sl >= 2 ) );
			$fz     = $num ? 0 : ( $sl >= 8 ? 2 : ( $sl >= 4 ? 1 : 0 ) );
			$fs     = $fz ? self::fold_bytes( $s ) : '';
			if ( $pre_ok || $fz ) {
				foreach ( $vocab as $v => $info ) {
					$v = (string) $v;
					if ( isset( $map[ $v ] ) ) {
						continue;
					}
					if ( $pre_ok && 0 === strpos( $v, $s ) ) {
						$map[ $v ] = 6;
						$strong    = true;
						continue;
					}
					if ( $fz && abs( (int) $info[2] - $sl ) <= $fz && levenshtein( $fs, self::fold_bytes( $v ) ) <= $fz ) {
						$map[ $v ] = 4;
					}
				}
			}
			$kc = array();
			foreach ( $cats as $cid => $ci ) {
				foreach ( $ci['s'] as $cs ) {
					if ( $cs === $s || ( '' !== $a && $cs === $a ) || ( $pre_ok && 0 === strpos( $cs, $s ) ) ) {
						$kc[ $cid ] = 1;
						break;
					}
				}
			}
			$out[] = array(
				'map'    => $map,
				'cats'   => $kc,
				'strong' => $strong || ! empty( $kc ),
				'k'      => $k,
			);
		}
		return $out;
	}

	/**
	 * Run a search.
	 *
	 * @param array  $c        Concepts.
	 * @param array  $ctx      Context from context().
	 * @param int    $per_page Max ids.
	 * @param string $q        Original query (for replies / suggestions).
	 * @return array {ids,total,relaxed,understood,reply,suggestions,categories}
	 */
	public static function run( $c, $ctx, $per_page, $q = '' ) {
		$idx = self::index();
		$be  = self::brand_entries_cached();
		$out = array(
			'ids'         => array(),
			'total'       => 0,
			'relaxed'     => '',
			'understood'  => self::understood( $c, $be ),
			'reply'       => '',
			'suggestions' => array(),
			'categories'  => array(),
		);
		$P   = $idx['p'];
		$tw  = self::title_words();

		$pet_cats  = array();
		$pet_words = array();
		foreach ( $c['pets'] as $k ) {
			foreach ( isset( $idx['kinds'][ $k ] ) ? $idx['kinds'][ $k ] : array() as $cid ) {
				$pet_cats[ $cid ] = 1;
			}
			$pet_words += isset( $tw['pet'][ $k ] ) ? $tw['pet'][ $k ] : array();
		}
		$soft_cats = array();
		$soft_kind = ( empty( $c['pets'] ) && ! empty( $ctx['kind'] ) ) ? $ctx['kind'] : '';
		if ( '' !== $soft_kind ) {
			foreach ( isset( $idx['kinds'][ $soft_kind ] ) ? $idx['kinds'][ $soft_kind ] : array() as $cid ) {
				$soft_cats[ $cid ] = 1;
			}
		}
		$young    = ! empty( $ctx['young'] ) && null === $c['stage'];
		$types    = self::resolve_types( $c['types'], $pet_cats, $idx );
		$kws      = self::keyword_maps( $c['keywords'], $idx['vocab'], $idx['cats'] );
		$nk       = count( $kws );
		$bids     = array();
		$bphr     = array();
		foreach ( $c['brands'] as $bk ) {
			if ( isset( $be[ $bk ] ) ) {
				foreach ( $be[ $bk ]['ids'] as $bid ) {
					$bids[ $bid ] = 1;
				}
				foreach ( $be[ $bk ]['phrases'] as $p ) {
					$bphr[] = explode( ' ', $p );
				}
			}
		}
		$has_brand = ! empty( $c['brands'] );
		$stage_w   = $c['stage'] && isset( $tw['stage'][ $c['stage'] ] ) ? $tw['stage'][ $c['stage'] ] : array();
		$health_w  = array();
		foreach ( $c['health'] as $h ) {
			if ( isset( $tw['health'][ $h ] ) ) {
				$health_w[] = $tw['health'][ $h ];
			}
		}
		$nh       = count( $health_w );
		$junior_w = $tw['stage']['junior'];
		$min      = $c['min_price'];
		$max      = $c['max_price'];

		$has_hard = ! empty( $c['pets'] ) || ! empty( $types ) || null !== $min || null !== $max || $c['on_sale'];
		$has_req  = $nk > 0 || $has_brand || $c['stage'] || $nh > 0;
		if ( ! $has_hard && ! $has_req && ! $c['sort'] ) {
			$out['reply']       = '';
			$out['suggestions'] = self::suggestions( $c, $kws, $idx, $q, $ctx, true );
			return $out;
		}

		$rows = array();
		foreach ( $P as $id => $p ) {
			$pcs = array_flip( $p['c'] );
			$ts  = array_flip( $p['t'] );

			$f_pet = empty( $pet_cats ) || self::any( $pcs, $pet_cats );
			if ( ! $f_pet ) {
				continue; // Pet kind is never relaxed.
			}
			$score  = 0.0;
			$f_type = true;
			if ( $types ) {
				$f_type = false;
				foreach ( $types as $t ) {
					$in_cat    = $t['cats'] && self::any( $pcs, $t['cats'] );
					$tw_hit    = $t['words'] && self::any( $ts, $t['words'] );
					$in_parent = empty( $t['parent_cats'] ) || self::any( $pcs, $t['parent_cats'] );
					if ( $in_cat || ( $tw_hit && $in_parent ) ) {
						$f_type = true;
					}
					$score += ( $in_cat ? 5 : 0 ) + ( $tw_hit ? 4 : 0 );
				}
			}
			$f_price = ( null === $min || $p['r'] >= $min ) && ( null === $max || ( $p['r'] > 0 && $p['r'] <= $max ) );
			$f_sale  = ! $c['on_sale'] || $p['o'];

			$kh = 0;
			foreach ( $kws as $k ) {
				$best = 0;
				foreach ( $p['t'] as $s ) {
					if ( isset( $k['map'][ $s ] ) && $k['map'][ $s ] > $best ) {
						$best = $k['map'][ $s ];
					}
				}
				if ( $best < 5 && $k['cats'] && self::any( $pcs, $k['cats'] ) ) {
					$best = 5;
				}
				if ( $best > 0 ) {
					$kh++;
					$score += $best;
				}
			}
			if ( $nk >= 2 && $kh === $nk ) {
				$score += 5;
			}
			$bh = false;
			if ( $has_brand ) {
				if ( $bids && $p['b'] && self::any( array_flip( $p['b'] ), $bids ) ) {
					$bh     = true;
					$score += 25;
				} else {
					foreach ( $bphr as $phr ) {
						$all = true;
						foreach ( $phr as $tok ) {
							if ( ! isset( $ts[ $tok ] ) ) {
								$all = false;
								break;
							}
						}
						if ( $all ) {
							$bh     = true;
							$score += 18;
							break;
						}
					}
				}
			}
			$sh = $stage_w && self::any( $ts, $stage_w );
			if ( $sh ) {
				$score += 6;
			}
			$hh = 0;
			foreach ( $health_w as $hw ) {
				if ( self::any( $ts, $hw ) ) {
					$hh++;
					$score += 6;
				}
			}
			if ( $pet_words && self::any( $ts, $pet_words ) ) {
				$score += 2;
			}
			if ( $soft_cats && self::any( $pcs, $soft_cats ) ) {
				$score += 8;
			}
			if ( $young && self::any( $ts, $junior_w ) ) {
				$score += 4;
			}
			$score += min( 10, 1.5 * log( 1 + max( 0, $p['s'] ) ) );

			$rows[] = array(
				'id' => (int) $id,
				'sc' => $score,
				'i'  => (int) $p['i'],
				'r'  => (float) $p['r'],
				's'  => (int) $p['s'],
				'd'  => (int) $p['d'],
				'ft' => $f_type,
				'fp' => $f_price,
				'fs' => $f_sale,
				'kh' => $kh,
				'bh' => $bh,
				'sh' => $sh || ! $c['stage'],
				'hh' => $hh,
			);
		}

		// Match what the store shows: when out-of-stock products are hidden on the site,
		// leave them out of results and counts too (the app lists only in-stock products).
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) || apply_filters( 'dokan_app_search_instock_only', true ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) {
						return $r['i'] > 0;
					}
				)
			);
		}
		$full   = function ( $r ) use ( $nk, $has_brand, $nh ) {
			return $r['kh'] === $nk && ( ! $has_brand || $r['bh'] ) && $r['sh'] && $r['hh'] === $nh;
		};
		$levels = array(
			''      => function ( $r ) use ( $full ) {
				return $r['ft'] && $r['fp'] && $r['fs'] && $full( $r );
			},
		);
		if ( $nk >= 2 ) {
			$half              = (int) ceil( $nk / 2 );
			$levels['partial'] = function ( $r ) use ( $half, $has_brand, $nh ) {
				return $r['ft'] && $r['fp'] && $r['fs'] && $r['kh'] >= $half && ( ! $has_brand || $r['bh'] ) && $r['sh'] && $r['hh'] === $nh;
			};
		}
		if ( $has_req && $has_hard ) {
			$levels['keywords'] = function ( $r ) {
				return $r['ft'] && $r['fp'] && $r['fs'];
			};
		}
		if ( $types && ( ! empty( $c['pets'] ) || null !== $min || null !== $max || $c['on_sale'] ) ) {
			$levels['type'] = function ( $r ) {
				return $r['fp'] && $r['fs'];
			};
		}
		if ( ( null !== $min || null !== $max ) && ! empty( $c['pets'] ) ) {
			$levels['price'] = function ( $r ) {
				return $r['fs'];
			};
		}
		$sel     = array();
		$relaxed = '';
		foreach ( $levels as $name => $fn ) {
			$sel = array_values( array_filter( $rows, $fn ) );
			if ( ! empty( $sel ) ) {
				$relaxed = (string) $name;
				break;
			}
		}
		$mode = $c['sort'];
		usort(
			$sel,
			function ( $a, $b ) use ( $mode ) {
				if ( $a['i'] !== $b['i'] ) {
					return $b['i'] - $a['i'];
				}
				if ( 'price_asc' === $mode ) {
					$pa = $a['r'] > 0 ? $a['r'] : PHP_INT_MAX;
					$pb = $b['r'] > 0 ? $b['r'] : PHP_INT_MAX;
					if ( $pa != $pb ) { // phpcs:ignore
						return $pa < $pb ? -1 : 1;
					}
				} elseif ( 'popularity' === $mode && $a['s'] !== $b['s'] ) {
					return $b['s'] - $a['s'];
				} elseif ( 'date' === $mode && $a['d'] !== $b['d'] ) {
					return $b['d'] - $a['d'];
				}
				if ( $a['sc'] != $b['sc'] ) { // phpcs:ignore
					return $a['sc'] < $b['sc'] ? 1 : -1;
				}
				if ( $a['s'] !== $b['s'] ) {
					return $b['s'] - $a['s'];
				}
				return $b['id'] - $a['id'];
			}
		);
		$ids                = array();
		foreach ( $sel as $r ) {
			$ids[] = $r['id'];
		}
		$out['total']       = count( $ids );
		$out['ids']         = array_slice( $ids, 0, max( 1, (int) $per_page ) );
		$out['relaxed']     = $relaxed;
		$out['categories']  = self::result_categories( $types, $pet_cats, $kws, $ids, $idx );
		$out['suggestions'] = self::suggestions( $c, $kws, $idx, $q, $ctx, empty( $ids ) );
		$out['reply']       = self::reply( $c, $be, $q, $out['total'], $relaxed, $out['suggestions'] );
		return $out;
	}

	/**
	 * Reply line.
	 *
	 * @param array  $c       Concepts.
	 * @param array  $be      Brands.
	 * @param string $q       Query.
	 * @param int    $total   Total.
	 * @param string $relaxed Relaxation level.
	 * @param array  $sugg    Suggestions.
	 * @return string
	 */
	private static function reply( $c, $be, $q, $total, $relaxed, $sugg ) {
		if ( 0 === $total ) {
			if ( ! empty( $sugg ) ) {
				$two = array_slice( $sugg, 0, 2 );
				return self::t(
					'ما لقينا شي بهالاسم، جرّب: «' . implode( '» أو «', $two ) . '»',
					'We couldn\'t find anything for that. Try: “' . implode( '” or “', $two ) . '”'
				);
			}
			return self::t( 'ما لقينا شي بهالاسم، جرّب كلمة ثانية أو تصفّح الأقسام', 'We couldn\'t find anything for that. Try another word or browse the categories.' );
		}
		$suffix = '';
		if ( 'price_asc' === $c['sort'] ) {
			$suffix = self::t( '، من الأرخص', ', cheapest first' );
		} elseif ( 'popularity' === $c['sort'] ) {
			$suffix = self::t( '، الأكثر مبيعًا أول', ', best sellers first' );
		} elseif ( 'date' === $c['sort'] ) {
			$suffix = self::t( '، الأحدث أول', ', newest first' );
		}
		$en    = 'en' === self::t( 'ar', 'en' );
		$types = array();
		foreach ( $c['types'] as $t ) {
			$types[] = strtolower( self::type_label( $t ) );
		}
		if ( '' === $relaxed ) {
			if ( $en ) {
				// "Found 24 wet food products for adult cats".
				$desc = self::describe( $c, $be, $q, true, true );
				return 'Found ' . self::count_phrase( $total, implode( ' & ', $types ) ) . ( '' !== $desc ? ' ' . $desc : '' ) . $suffix;
			}
			$desc = self::describe( $c, $be, $q, true );
			return 'لقينا ' . self::count_phrase( $total ) . ( '' !== $desc ? ' ' . $desc : '' ) . $suffix;
		}
		// Describe only what is still applied.
		$kept = $c;
		if ( in_array( $relaxed, array( 'keywords', 'type', 'price' ), true ) ) {
			$kept['keywords'] = array();
			$kept['brands']   = array();
			$kept['stage']    = null;
			$kept['health']   = array();
		}
		if ( in_array( $relaxed, array( 'type', 'price' ), true ) ) {
			$kept['types'] = array();
		}
		if ( 'price' === $relaxed ) {
			$kept['min_price'] = null;
			$kept['max_price'] = null;
		}
		$desc  = self::describe( $kept, $be, $q, false );
		$short = self::sub( $q, 0, 40 );
		return self::t(
			'ما لقينا نتيجة مطابقة تمامًا لـ«' . $short . '»، وهذي أقرب ' . self::count_phrase( $total ) . ( '' !== $desc ? ' ' . $desc : '' ),
			'No exact match for “' . $short . '” — here ' . ( 1 === $total ? 'is the closest product' : 'are the ' . $total . ' closest products' ) . ( '' !== $desc ? ' (' . $desc . ')' : '' )
		);
	}

	/**
	 * Matching categories (up to 4).
	 *
	 * @param array $types    Resolved types.
	 * @param array $pet_cats Pet top cats.
	 * @param array $kws      Keyword maps.
	 * @param int[] $ids      Result ids.
	 * @param array $idx      Index.
	 * @return array
	 */
	private static function result_categories( $types, $pet_cats, $kws, $ids, $idx ) {
		$cats = $idx['cats'];
		$out  = array();
		$push = function ( $cid ) use ( &$out, $cats ) {
			if ( isset( $cats[ $cid ] ) && ! isset( $out[ $cid ] ) && count( $out ) < 4 ) {
				$out[ $cid ] = $cats[ $cid ]['n'];
			}
		};
		$by_count = function ( $set ) use ( $cats ) {
			$list = array();
			foreach ( array_keys( $set ) as $cid ) {
				if ( isset( $cats[ $cid ] ) ) {
					$list[ $cid ] = $cats[ $cid ]['k'];
				}
			}
			arsort( $list );
			return array_keys( $list );
		};
		foreach ( $types as $t ) {
			foreach ( array_slice( $by_count( $t['cats'] ? $t['cats'] : $t['parent_cats'] ), 0, 2 ) as $cid ) {
				$push( $cid );
			}
		}
		foreach ( array_keys( $pet_cats ) as $cid ) {
			$push( $cid );
		}
		foreach ( $kws as $k ) {
			foreach ( array_slice( $by_count( $k['cats'] ), 0, 2 ) as $cid ) {
				$push( $cid );
			}
		}
		$tally = array();
		foreach ( array_slice( $ids, 0, 40 ) as $id ) {
			foreach ( $idx['p'][ $id ]['c'] as $cid ) {
				if ( isset( $cats[ $cid ] ) && 0 !== (int) $cats[ $cid ]['p'] ) {
					$tally[ $cid ] = ( isset( $tally[ $cid ] ) ? $tally[ $cid ] : 0 ) + 1;
				}
			}
		}
		arsort( $tally );
		foreach ( array_keys( $tally ) as $cid ) {
			$push( $cid );
		}
		$res = array();
		foreach ( $out as $cid => $name ) {
			$res[] = array(
				'id'   => (int) $cid,
				'name' => (string) $name,
			);
		}
		return $res;
	}

	/**
	 * Did-you-mean + related searches (up to 6).
	 *
	 * @param array  $c     Concepts.
	 * @param array  $kws   Keyword maps.
	 * @param array  $idx   Index.
	 * @param string $q     Query.
	 * @param array  $ctx   Context.
	 * @param bool   $empty No results.
	 * @return string[]
	 */
	private static function suggestions( $c, $kws, $idx, $q, $ctx, $empty ) {
		$out   = array();
		$qnorm = self::normalize( $q );
		$add   = function ( $s ) use ( &$out, $qnorm ) {
			$s = trim( (string) $s );
			if ( '' !== $s && self::normalize( $s ) !== $qnorm && ! in_array( $s, $out, true ) && count( $out ) < 6 ) {
				$out[] = $s;
			}
		};
		// Did you mean.
		$disp    = explode( ' ', self::clean_text( $q ) );
		$changed = false;
		foreach ( $kws as $k ) {
			if ( $k['strong'] ) {
				continue;
			}
			$s  = (string) $k['k']['s'];
			$sl = self::len( $s );
			if ( $sl < 3 || preg_match( '/^\d/', $s ) ) {
				continue;
			}
			$max  = $sl >= 6 ? 2 : 1;
			$fs   = self::fold_bytes( $s );
			$best = null;
			$bd   = 99;
			$bf   = -1;
			foreach ( $idx['vocab'] as $v => $info ) {
				if ( abs( (int) $info[2] - $sl ) > $max ) {
					continue;
				}
				$d = levenshtein( $fs, self::fold_bytes( (string) $v ) );
				if ( $d > 0 && $d <= $max && ( $d < $bd || ( $d === $bd && $info[1] > $bf ) ) ) {
					$best = $info[0];
					$bd   = $d;
					$bf   = $info[1];
				}
			}
			if ( null !== $best ) {
				$pos = array_search( $k['k']['w'], $disp, true );
				if ( false !== $pos ) {
					$disp[ $pos ] = $best;
					$changed      = true;
				}
			}
		}
		if ( $changed ) {
			$add( implode( ' ', $disp ) );
		}
		// Related.
		$kind = ! empty( $c['pets'] ) ? $c['pets'][0] : ( isset( $ctx['kind'] ) ? $ctx['kind'] : '' );
		if ( '' !== $kind ) {
			$rel = array(
				'cat'   => array( 'wet', 'dry', 'litter', 'toys', 'treats', 'scratchers' ),
				'dog'   => array( 'dry', 'wet', 'treats', 'toys', 'accessories', 'grooming' ),
				'bird'  => array( 'food', 'carriers', 'toys', 'health' ),
				'fish'  => array( 'food', 'health' ),
				'small' => array( 'food', 'carriers', 'beds' ),
			);
			foreach ( isset( $rel[ $kind ] ) ? $rel[ $kind ] : array() as $t ) {
				if ( in_array( $t, $c['types'], true ) ) {
					continue;
				}
				$add( self::type_label( $t ) . ' ' . self::pet_phrase( $kind ) );
			}
		} else {
			foreach ( self::popular_searches() as $p ) {
				$add( $p );
			}
		}
		return $out;
	}

	/**
	 * Full /search response.
	 *
	 * @param string     $q        Query.
	 * @param array|null $pet      Pet.
	 * @param bool       $ai       Allow Claude.
	 * @param bool       $log      Log query.
	 * @param int        $per_page Per page.
	 * @return array
	 */
	public static function search_response( $q, $pet, $ai, $log, $per_page ) {
		$q        = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $q ) ) );
		$q        = self::sub( $q, 0, 120 );
		$per_page = max( 1, min( 60, (int) $per_page ) );
		$ctx      = self::context( $pet );
		$res      = array(
			'query'       => $q,
			'understood'  => array(),
			'reply'       => '',
			'product_ids' => array(),
			'total'       => 0,
			'suggestions' => array(),
			'categories'  => array(),
			'ai'          => false,
		);
		if ( '' === self::normalize( $q ) ) {
			$res['suggestions'] = array_slice( self::popular_searches(), 0, 6 );
			return $res;
		}
		$c    = self::parse( $q, null, $ctx['kind'] );
		$used = false;
		if ( $ai && class_exists( 'Dokan_App_Claude' ) && Dokan_App_Claude::enabled() && self::rate_ok( 'ais', 60 ) ) {
			$intent = Dokan_App_Claude::intent( $q, $pet );
			if ( is_array( $intent ) ) {
				$c    = self::merge_ai( $c, $intent );
				$used = true;
			}
		}
		$r = self::run( $c, $ctx, $per_page, $q );
		if ( $log ) {
			self::log_query( $q, 0 === $r['total'] || '' !== $r['relaxed'] );
		}
		$res['understood']  = $r['understood'];
		$res['reply']       = $r['reply'];
		$res['product_ids'] = array_values( array_map( 'intval', $r['ids'] ) );
		$res['total']       = (int) $r['total'];
		$res['suggestions'] = array_values( $r['suggestions'] );
		$res['categories']  = $r['categories'];
		$res['ai']          = $used;
		return $res;
	}

	/**
	 * Fixed-window rate limit per user (or IP for guests). Returns true when allowed.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Limit.
	 * @param int    $window Seconds.
	 * @return bool
	 */
	public static function rate_ok( $bucket, $limit, $window = 3600 ) {
		$uid = get_current_user_id();
		$who = $uid ? 'u' . $uid : 'i' . Dokan_App_Helpers::client_ip();
		$key = 'dokan_app_rl_' . $bucket . '_' . md5( $who );
		$now = time();
		$rec = get_transient( $key );
		if ( ! is_array( $rec ) || empty( $rec['s'] ) || $now - (int) $rec['s'] >= $window ) {
			$rec = array(
				's' => $now,
				'n' => 0,
			);
		}
		if ( (int) $rec['n'] >= $limit ) {
			return false;
		}
		$rec['n'] = (int) $rec['n'] + 1;
		set_transient( $key, $rec, max( 60, $window - ( $now - (int) $rec['s'] ) ) );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Search log / popular searches
	 * ------------------------------------------------------------------ */

	/**
	 * Record a submitted query.
	 *
	 * @param string $q    Query as typed.
	 * @param bool   $zero No (exact) results.
	 */
	public static function log_query( $q, $zero ) {
		$key = self::normalize( $q );
		if ( '' === $key ) {
			return;
		}
		$key = self::lang() . ':' . $key;
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$day = (int) floor( time() / DAY_IN_SECONDS );
		$e   = isset( $log[ $key ] ) && is_array( $log[ $key ] ) ? $log[ $key ] : array(
			'q' => $q,
			'c' => 0,
			'z' => 0,
			't' => 0,
			'd' => array(),
		);
		$e['q'] = $q;
		$e['c']++;
		if ( $zero ) {
			$e['z']++;
		}
		$e['t'] = time();
		if ( ! isset( $e['d'][ $day ] ) ) {
			$e['d'][ $day ] = array( 0, 0 );
		}
		$e['d'][ $day ][0]++;
		if ( $zero ) {
			$e['d'][ $day ][1]++;
		}
		foreach ( array_keys( $e['d'] ) as $d ) {
			if ( $d < $day - 30 ) {
				unset( $e['d'][ $d ] );
			}
		}
		$log[ $key ] = $e;
		if ( count( $log ) > self::LOG_MAX ) {
			uasort(
				$log,
				function ( $a, $b ) {
					return (int) $b['t'] - (int) $a['t'];
				}
			);
			$log = array_slice( $log, 0, self::LOG_MAX, true );
		}
		update_option( self::OPT_LOG, $log, false );
	}

	/**
	 * Top searches over the last N days.
	 *
	 * @param int         $limit     Limit.
	 * @param bool        $zero_only Only those with zero results.
	 * @param int         $days      Days.
	 * @param string|null $lang      Language filter (null = all).
	 * @return array [{q, lang, count, zero, last}]
	 */
	public static function top_searches( $limit = 30, $zero_only = false, $days = 30, $lang = null ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$from = (int) floor( time() / DAY_IN_SECONDS ) - $days;
		$rows = array();
		foreach ( $log as $key => $e ) {
			$l = strpos( (string) $key, ':' ) === 2 ? substr( (string) $key, 0, 2 ) : 'ar';
			if ( null !== $lang && $l !== $lang ) {
				continue;
			}
			$c = 0;
			$z = 0;
			foreach ( (array) $e['d'] as $d => $v ) {
				if ( $d >= $from ) {
					$c += (int) $v[0];
					$z += (int) $v[1];
				}
			}
			if ( $c < 1 || ( $zero_only && $z < 1 ) ) {
				continue;
			}
			$rows[] = array(
				'q'     => (string) $e['q'],
				'lang'  => $l,
				'count' => $c,
				'zero'  => $z,
				'last'  => (int) $e['t'],
			);
		}
		usort(
			$rows,
			function ( $a, $b ) use ( $zero_only ) {
				$ka = $zero_only ? $a['zero'] : $a['count'];
				$kb = $zero_only ? $b['zero'] : $b['count'];
				return $kb !== $ka ? $kb - $ka : $b['last'] - $a['last'];
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Clear the log.
	 */
	public static function clear_log() {
		delete_option( self::OPT_LOG );
	}

	/**
	 * Popular searches: owner list (per language), else top logged with results, else top
	 * category names.
	 *
	 * @param array|null $fallback_names Category names to fall back to.
	 * @return string[]
	 */
	public static function popular_searches( $fallback_names = null ) {
		$raw  = class_exists( 'Dokan_App_Helpers' ) ? (string) Dokan_App_Helpers::setting_l( 'popular_searches' ) : '';
		$list = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line && ! in_array( $line, $list, true ) ) {
				$list[] = $line;
			}
			if ( count( $list ) >= 12 ) {
				break;
			}
		}
		if ( empty( $list ) ) {
			$top = self::top_searches( 50, false, 30, self::lang() );
			usort(
				$top,
				function ( $a, $b ) {
					return ( $b['count'] - $b['zero'] ) - ( $a['count'] - $a['zero'] );
				}
			);
			foreach ( $top as $r ) {
				if ( $r['count'] - $r['zero'] > 0 ) {
					$list[] = $r['q'];
				}
				if ( count( $list ) >= 8 ) {
					break;
				}
			}
		}
		if ( empty( $list ) ) {
			if ( null === $fallback_names ) {
				$fallback_names = array();
				$idx            = self::index();
				$tops           = array();
				foreach ( $idx['cats'] as $ci ) {
					if ( 0 === (int) $ci['p'] ) {
						$tops[ $ci['n'] ] = $ci['k'];
					}
				}
				arsort( $tops );
				$fallback_names = array_keys( $tops );
			}
			$list = array_slice( array_values( array_map( 'strval', (array) $fallback_names ) ), 0, 8 );
		}
		return array_values( (array) apply_filters( 'dokan_app_popular_searches', $list ) );
	}
}
