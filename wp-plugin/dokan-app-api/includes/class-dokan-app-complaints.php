<?php
/**
 * Complaints & cancellation from the app.
 *
 * Cases are saved in the store's own customer-service desk (theme class DKS_Customer_Service,
 * post type dks_service_case), so they show up in "خدمة العملاء" in wp-admin next to the
 * website's cases, with the same WhatsApp notifications. The app adds:
 *  - understanding the customer's words (Claude when enabled, Arabic/English rules otherwise)
 *    into a category, a clear subject and the policy checks that apply;
 *  - enforcing the complaints policy (order + photo for "not arrived"/"damaged", 24h for
 *    damage, cancellation only while processing);
 *  - photos, the case thread and replies, and a push when the store replies.
 *
 * @package DokanApp
 */

defined( 'ABSPATH' ) || exit;

class Dokan_App_Complaints {

	const MAX_PHOTOS = 4;
	const MAX_BYTES  = 6291456; // 6 MB per photo.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'added_comment_meta', array( __CLASS__, 'on_comment_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_status_meta' ), 10, 4 );
	}

	/* ------------------------------------------------------------------
	 * Categories and policy
	 * ------------------------------------------------------------------ */

	/**
	 * Complaint categories. need: order (order required), photo (photo required).
	 *
	 * @return array
	 */
	public static function categories() {
		return array(
			'not_arrived' => array( 'ar' => 'الطلب ما وصل', 'en' => 'Order did not arrive', 'order' => true, 'photo' => true, 'kind' => 'complaint' ),
			'damaged'     => array( 'ar' => 'منتج تالف أو فيه عيب', 'en' => 'Damaged or faulty product', 'order' => true, 'photo' => true, 'kind' => 'complaint' ),
			'wrong_item'  => array( 'ar' => 'وصلني منتج غلط', 'en' => 'Received the wrong item', 'order' => true, 'photo' => true, 'kind' => 'complaint' ),
			'missing'     => array( 'ar' => 'منتج ناقص من الطلب', 'en' => 'Item missing from the order', 'order' => true, 'photo' => false, 'kind' => 'complaint' ),
			'late'        => array( 'ar' => 'تأخر التوصيل', 'en' => 'Late delivery', 'order' => true, 'photo' => false, 'kind' => 'complaint' ),
			'cancel'      => array( 'ar' => 'إلغاء الطلب', 'en' => 'Cancel the order', 'order' => true, 'photo' => false, 'kind' => 'complaint' ),
			'payment'     => array( 'ar' => 'الدفع أو الاسترداد', 'en' => 'Payment or refund', 'order' => false, 'photo' => false, 'kind' => 'complaint' ),
			'service'     => array( 'ar' => 'التعامل وخدمة العملاء', 'en' => 'Service experience', 'order' => false, 'photo' => false, 'kind' => 'complaint' ),
			'inquiry'     => array( 'ar' => 'استفسار', 'en' => 'Question', 'order' => false, 'photo' => false, 'kind' => 'inquiry' ),
			'other'       => array( 'ar' => 'شي ثاني', 'en' => 'Something else', 'order' => false, 'photo' => false, 'kind' => 'complaint' ),
		);
	}

	private static function cat_label( $key ) {
		$c = self::categories();
		$c = isset( $c[ $key ] ) ? $c[ $key ] : $c['other'];
		return Dokan_App_Helpers::t( $c['ar'], $c['en'] );
	}

	/** Words that point to each category (normalized Arabic + English). */
	private static function rules() {
		return array(
			'cancel'      => array( 'الغ', 'إلغ', 'الغاء', 'كنسل', 'كانسل', 'ابي الغي', 'ما ابغاه', 'مابغاه', 'رجعوا فلوسي قبل', 'cancel' ),
			'damaged'     => array( 'تالف', 'خربان', 'مكسور', 'انكسر', 'مفتوح', 'مقطوع', 'منتهي', 'منتهيه', 'انتهاء', 'عفن', 'ريحه', 'رائحه', 'حشرات', 'سوس', 'عيب', 'معيب', 'مضروب', 'متضرر', 'تسريب', 'مسرب', 'damaged', 'broken', 'expired', 'leak', 'torn', 'defect' ),
			'wrong_item'  => array( 'غلط', 'خطا', 'خطأ', 'غير اللي طلبت', 'مو اللي طلبت', 'مختلف', 'نكهه ثانيه', 'مقاس ثاني', 'بدل', 'wrong', 'different', 'not what i ordered' ),
			'missing'     => array( 'ناقص', 'نقص', 'ما لقيت', 'مالقيت', 'مو موجود', 'ناقصه', 'missing', 'not in the box', 'incomplete' ),
			'not_arrived' => array( 'ما وصل', 'ماوصل', 'لم يصل', 'ما جاني', 'ماجاني', 'ما استلمت', 'مااستلمت', 'لم استلم', 'وين الطلب', 'وين طلبي', 'مكتوب تم التوصيل', 'تم التسليم وما', 'not arrived', 'never arrived', "didn't arrive", 'not delivered', 'did not receive' ),
			'late'        => array( 'تاخر', 'تأخر', 'متاخر', 'متأخر', 'تأخير', 'تاخير', 'بطيء', 'بطء', 'كم يوم', 'متى يوصل', 'late', 'delay', 'slow' ),
			'payment'     => array( 'دفعت', 'خصم مرتين', 'انخصم', 'مبلغ', 'استرداد', 'استرجاع المبلغ', 'فلوسي', 'تابي', 'تمارا', 'بطاقه', 'فاتوره', 'refund', 'charged', 'payment', 'paid twice', 'invoice' ),
			'service'     => array( 'المندوب', 'السائق', 'تعامل', 'اسلوب', 'أسلوب', 'ما ردوا', 'ماردوا', 'محد رد', 'سيء', 'سيئ', 'rude', 'driver', 'no one answered', 'service' ),
			'inquiry'     => array( 'ابي اعرف', 'ابغى اعرف', 'ودي اعرف', 'هل', 'كيف', 'متى', 'question', 'how', 'when', 'do you' ),
		);
	}

	private static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
		$s = preg_replace( '/[\x{064B}-\x{065F}\x{0640}]/u', '', $s );
		$s = strtr( $s, array( 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي' ) );
		return preg_replace( '/\s+/u', ' ', $s );
	}

	/**
	 * Rule-based understanding.
	 *
	 * @param string $text Customer text.
	 * @return array {category, confidence}
	 */
	private static function rule_category( $text ) {
		$t     = self::norm( $text );
		$best  = 'other';
		$score = 0;
		// Order matters on ties: earlier = more specific.
		foreach ( self::rules() as $cat => $words ) {
			$s = 0;
			foreach ( $words as $w ) {
				$w = self::norm( $w );
				if ( '' !== $w && false !== strpos( $t, $w ) ) {
					$s += ( function_exists( 'mb_strlen' ) ? mb_strlen( $w ) : strlen( $w ) ) > 3 ? 2 : 1;
				}
			}
			if ( 'inquiry' === $cat ) {
				$s = $s > 0 ? 1 : 0; // A question word alone is a weak signal.
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = $cat;
			}
		}
		return array(
			'category'   => $best,
			'confidence' => $score >= 2 ? 'high' : ( $score > 0 ? 'low' : 'none' ),
		);
	}

	/**
	 * The policy checks for a category + order (shared by /analyze and submit).
	 *
	 * @param string        $cat   Category.
	 * @param WC_Order|null $order Order.
	 * @return array {blocked:?string, warnings:string[], need_order, need_photo, cancel_ok}
	 */
	public static function policy( $cat, $order ) {
		$cats = self::categories();
		$def  = isset( $cats[ $cat ] ) ? $cats[ $cat ] : $cats['other'];
		$out  = array(
			'blocked'    => null,
			'warnings'   => array(),
			'need_order' => (bool) $def['order'],
			'need_photo' => (bool) $def['photo'],
			'cancel_ok'  => false,
		);
		if ( ! $order ) {
			return $out;
		}
		$status = $order->get_status();

		if ( 'cancel' === $cat ) {
			if ( 'processing' === $status ) {
				$out['cancel_ok']  = true;
				$out['warnings'][] = Dokan_App_Helpers::t(
					'بنوقف الطلب فورًا لين يتم إلغاؤه، وإذا كان مدفوع إلكترونيًا يرجع المبلغ لنفس وسيلة الدفع حسب سياسة الإلغاء.',
					'We will put the order on hold right away until it is cancelled. If it was paid online, the amount goes back to the same payment method under the cancellation policy.'
				);
			} elseif ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				$out['blocked'] = Dokan_App_Helpers::t( 'هذا الطلب ملغي أو مسترد من قبل.', 'This order is already cancelled or refunded.' );
			} elseif ( 'on-hold' === $status && $order->get_meta( '_dokan_app_cancel_requested' ) ) {
				$out['blocked'] = Dokan_App_Helpers::t( 'وصلنا طلب إلغاء هذا الطلب من قبل وهو موقوف لين يتم إلغاؤه.', 'We already have a cancellation request for this order; it is on hold until it is cancelled.' );
			} elseif ( 'pending' === $status ) {
				$out['blocked'] = Dokan_App_Helpers::t( 'هذا الطلب بانتظار الدفع، ما يحتاج إلغاء — إذا ما كمّلت الدفع يُلغى تلقائيًا.', 'This order is awaiting payment; if it is not paid it is cancelled automatically.' );
			} else {
				$out['blocked'] = Dokan_App_Helpers::t(
					'حسب سياسة الإلغاء، الإلغاء متاح فقط والطلب «قيد التنفيذ». بعد الشحن أو إصدار بوليصة الشحن ما نقدر نلغيه — تقدر بعد الاستلام تطلب إرجاع حسب سياسة الإرجاع.',
					'Under the cancellation policy an order can only be cancelled while it is "Processing". Once it has shipped or a waybill has been issued it cannot be cancelled — after delivery you can request a return under the returns policy.'
				);
			}
			return $out;
		}

		$done      = $order->get_date_completed();
		$delivered = $done ? $done->getTimestamp() : 0;

		if ( 'damaged' === $cat ) {
			if ( $delivered > 0 && time() - $delivered > DAY_IN_SECONDS ) {
				$out['warnings'][] = Dokan_App_Helpers::t(
					'تنبيه: السياسة تطلب الإبلاغ عن التلف خلال ٢٤ ساعة من الاستلام، وطلبك مستلم من أكثر من ٢٤ ساعة — بنرسل الشكوى للمراجعة لكن ما نضمن قبولها.',
					'Note: the policy asks for damage to be reported within 24 hours of delivery, and this order was delivered more than 24 hours ago — we will send it for review but cannot promise it will be accepted.'
				);
				$out['late_damage'] = true;
			} elseif ( $delivered < 1 && 'completed' !== $status ) {
				$out['warnings'][] = Dokan_App_Helpers::t( 'الطلب ما تسجّل استلامه للحين — تأكد إنك اخترت الطلب الصح.', 'This order is not marked as delivered yet — make sure you picked the right order.' );
			}
		}
		if ( 'not_arrived' === $cat && in_array( $status, array( 'processing', 'pending', 'on-hold' ), true ) ) {
			$out['warnings'][] = Dokan_App_Helpers::t( 'الطلب للحين ما انشحن — تقدر تتابعه من صفحة الطلب.', 'This order has not shipped yet — you can follow it from the order page.' );
		}
		if ( 'late' === $cat && 'completed' === $status ) {
			$out['warnings'][] = Dokan_App_Helpers::t( 'الطلب مسجّل إنه وصل. إذا ما استلمته اختر «الطلب ما وصل».', 'This order is marked as delivered. If you did not get it, choose "Order did not arrive".' );
		}
		if ( in_array( $cat, array( 'wrong_item', 'missing', 'damaged' ), true ) && $delivered > 0 && time() - $delivered > 3 * DAY_IN_SECONDS ) {
			$out['warnings'][] = Dokan_App_Helpers::t( 'مرّ أكثر من ٣ أيام على الاستلام — بنراجع الشكوى حسب السياسة.', 'More than 3 days have passed since delivery — the complaint will be reviewed under the policy.' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Understanding (AI)
	 * ------------------------------------------------------------------ */

	private static function policy_text() {
		$ar = (string) Dokan_App_Helpers::setting( 'complaints_ar' );
		$id = (int) Dokan_App_Rest::policy_page( 'returns' );
		$p  = $id > 0 ? get_post( $id ) : null;
		$pg = $p ? trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$pg = mb_substr( $pg, 0, 3500 );
		}
		return trim( $ar . "\n" . $pg );
	}

	/**
	 * Understand the customer's words.
	 *
	 * @param string        $text  Text.
	 * @param WC_Order|null $order Order.
	 * @return array {category, subject, summary, reply, ai}
	 */
	public static function understand( $text, $order ) {
		$rule = self::rule_category( $text );
		$out  = array(
			'category'   => $rule['category'],
			'confidence' => $rule['confidence'],
			'subject'    => '',
			'reply'      => '',
			'urgency'    => in_array( $rule['category'], array( 'cancel', 'not_arrived', 'damaged' ), true ) ? 'high' : 'normal',
			'ai'         => false,
		);
		if ( class_exists( 'Dokan_App_Claude' ) && Dokan_App_Claude::enabled() && ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) >= 6 ) {
			$cats  = implode( ', ', array_keys( self::categories() ) );
			$en    = Dokan_App_Helpers::is_en();
			$state = $order ? ( 'Order #' . $order->get_order_number() . ' status: ' . $order->get_status() . ( $order->get_date_completed() ? ', delivered ' . human_time_diff( $order->get_date_completed()->getTimestamp() ) . ' ago' : '' ) ) : 'No order selected';
			$sys   = "You triage customer complaints for Dokan (dokansa.com), a Saudi pet-supplies store. Store policy:\n" . self::policy_text() .
				"\n\nReturn ONLY compact JSON: {\"category\": one of [$cats], \"subject\": short title (max 60 chars) in " . ( $en ? 'English' : 'Arabic' ) .
				', "urgency": "high"|"normal", "reply": one or two warm sentences to the customer in ' . ( $en ? 'English' : 'Saudi Arabic' ) .
				' saying what will happen next under the policy and what to attach (order number / photo) if needed. Never promise refunds, compensation or discount codes, never invent facts}.';
			$raw = Dokan_App_Claude::request( $sys, array( array( 'role' => 'user', 'content' => $state . "\nCustomer: " . $text ) ), 300 );
			if ( is_string( $raw ) && preg_match( '/\{.*\}/s', $raw, $m ) ) {
				$j = json_decode( $m[0], true );
				if ( is_array( $j ) && isset( $j['category'] ) && isset( self::categories()[ $j['category'] ] ) ) {
					$out['category']   = $j['category'];
					$out['confidence'] = 'high';
					$out['subject']    = isset( $j['subject'] ) ? sanitize_text_field( (string) $j['subject'] ) : '';
					$out['reply']      = isset( $j['reply'] ) ? sanitize_textarea_field( (string) $j['reply'] ) : '';
					$out['urgency']    = isset( $j['urgency'] ) && 'high' === $j['urgency'] ? 'high' : 'normal';
					$out['ai']         = true;
				}
			}
		}
		if ( '' === $out['subject'] ) {
			$out['subject'] = self::cat_label( $out['category'] ) . ( $order ? ' #' . $order->get_order_number() : '' );
		}
		if ( '' === $out['reply'] ) {
			$out['reply'] = self::default_reply( $out['category'] );
		}
		return $out;
	}

	private static function default_reply( $cat ) {
		switch ( $cat ) {
			case 'cancel':
				return Dokan_App_Helpers::t( 'فهمت إنك تبي تلغي الطلب. اختر الطلب ونتأكد إنه قابل للإلغاء حسب السياسة.', 'Got it, you want to cancel. Pick the order and we will check it can be cancelled under the policy.' );
			case 'damaged':
				return Dokan_App_Helpers::t( 'آسفين على هذا! أرفق صورة واضحة للمنتج والتغليف واختر الطلب — شكاوى التلف تُقبل خلال ٢٤ ساعة من الاستلام.', 'Sorry about that! Attach a clear photo of the product and packaging and pick the order — damage complaints are accepted within 24 hours of delivery.' );
			case 'not_arrived':
				return Dokan_App_Helpers::t( 'نعتذر عن هذا. اختر الطلب وأرفق صورة (مثل صورة الباب أو رسالة التوصيل) ونتابع مع شركة الشحن.', 'We are sorry. Pick the order and attach a photo (e.g. of the door or the delivery message) and we will follow up with the courier.' );
			case 'wrong_item':
			case 'missing':
				return Dokan_App_Helpers::t( 'اختر الطلب وأرفق صورة للي وصلك عشان نصلح الموضوع بسرعة.', 'Pick the order and attach a photo of what you received so we can fix it quickly.' );
		}
		return Dokan_App_Helpers::t( 'وصلت الفكرة. كمّل التفاصيل وبنرد عليك في أقرب وقت.', 'Understood. Add the details and we will get back to you soon.' );
	}

	/* ------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public static function routes() {
		$ns   = 'dokan-app/v1';
		$auth = array( 'Dokan_App_Auth', 'permission_user' );
		register_rest_route( $ns, '/complaints', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_cases' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'submit' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $ns, '/complaints/options', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'options' ), 'permission_callback' => $auth ) );
		register_rest_route( $ns, '/complaints/analyze', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'analyze' ), 'permission_callback' => $auth ) );
		register_rest_route( $ns, '/complaints/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'detail' ), 'permission_callback' => $auth ) );
		register_rest_route( $ns, '/complaints/(?P<id>\d+)/reply', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reply' ), 'permission_callback' => $auth ) );
	}

	private static function wrap( $fn ) {
		try {
			$r = call_user_func( $fn );
			return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'complaints error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			return Dokan_App_Helpers::error( 'dokan_app_server_error', 'حدث خطأ غير متوقع، يرجى المحاولة لاحقًا.', 500 );
		}
	}

	private static function body( $r ) {
		$b = $r->get_json_params();
		if ( ! is_array( $b ) || empty( $b ) ) {
			$b = $r->get_body_params();
		}
		return is_array( $b ) ? $b : array();
	}

	private static function str( $b, $k, $max ) {
		$v = isset( $b[ $k ] ) && is_scalar( $b[ $k ] ) ? sanitize_textarea_field( (string) $b[ $k ] ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( trim( $v ), 0, $max ) : substr( trim( $v ), 0, $max );
	}

	private static function order_for( $id ) {
		$id = absint( $id );
		return $id ? Dokan_App_Orders::get_user_order( $id, get_current_user_id() ) : null;
	}

	private static function desk() {
		return class_exists( 'DKS_Customer_Service' );
	}

	/** GET /complaints/options — categories + recent orders to pick from. */
	public static function options() {
		return self::wrap( function () {
			$cats = array();
			foreach ( self::categories() as $k => $c ) {
				$cats[] = array( 'key' => $k, 'label' => Dokan_App_Helpers::t( $c['ar'], $c['en'] ), 'need_order' => $c['order'], 'need_photo' => $c['photo'] );
			}
			$orders = array();
			if ( function_exists( 'wc_get_orders' ) ) {
				foreach ( wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 15, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ) ) as $o ) {
					$orders[] = array(
						'id'           => $o->get_id(),
						'number'       => $o->get_order_number(),
						'status'       => $o->get_status(),
						'status_label' => wc_get_order_status_name( $o->get_status() ),
						'date'         => Dokan_App_Helpers::iso_date( $o->get_date_created() ),
						'total'        => Dokan_App_Helpers::money( $o->get_total() ),
						'items'        => count( $o->get_items() ),
					);
				}
			}
			return array(
				'categories' => $cats,
				'orders'     => $orders,
				'policy'     => (string) Dokan_App_Helpers::setting( Dokan_App_Helpers::is_en() ? 'complaints_en' : 'complaints_ar' ),
				'max_photos' => self::MAX_PHOTOS,
				'ai'         => class_exists( 'Dokan_App_Claude' ) && Dokan_App_Claude::enabled(),
			);
		} );
	}

	/** POST /complaints/analyze {text, order_id?, category?} */
	public static function analyze( $r ) {
		return self::wrap( function () use ( $r ) {
			$b     = self::body( $r );
			$text  = self::str( $b, 'text', 2000 );
			$order = self::order_for( isset( $b['order_id'] ) ? $b['order_id'] : 0 );
			$cat   = isset( $b['category'] ) ? sanitize_key( (string) $b['category'] ) : '';
			$u     = '' !== $text ? self::understand( $text, $order ) : array( 'category' => $cat ? $cat : 'other', 'confidence' => 'none', 'subject' => '', 'reply' => '', 'urgency' => 'normal', 'ai' => false );
			// A category the customer picked themselves wins over the guess.
			if ( '' !== $cat && isset( self::categories()[ $cat ] ) ) {
				$u['suggested'] = $u['category'];
				$u['category']  = $cat;
			}
			$u['category_label'] = self::cat_label( $u['category'] );
			$u['policy']         = self::policy( $u['category'], $order );
			return $u;
		} );
	}

	/** POST /complaints {category, order_id, details, subject?, photos:[base64], token} */
	public static function submit( $r ) {
		return self::wrap( function () use ( $r ) {
			$uid   = get_current_user_id();
			$b     = self::body( $r );
			$cat   = isset( $b['category'] ) ? sanitize_key( (string) $b['category'] ) : 'other';
			$cats  = self::categories();
			if ( ! isset( $cats[ $cat ] ) ) {
				$cat = 'other';
			}
			$details = self::str( $b, 'details', 5000 );
			$subject = sanitize_text_field( self::str( $b, 'subject', 170 ) );
			$oid     = isset( $b['order_id'] ) ? absint( $b['order_id'] ) : 0;
			$order   = self::order_for( $oid );
			$photos  = isset( $b['photos'] ) && is_array( $b['photos'] ) ? array_slice( $b['photos'], 0, self::MAX_PHOTOS ) : array();
			$token   = isset( $b['token'] ) && is_string( $b['token'] ) && preg_match( '/^[a-f0-9-]{36}$/D', $b['token'] ) ? $b['token'] : wp_generate_uuid4();

			if ( $oid && ! $order ) {
				return Dokan_App_Helpers::error( 'dokan_app_order_not_found', 'الطلب غير موجود.', 404 );
			}
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $details ) : strlen( $details );
			if ( 'cancel' !== $cat && $len < 10 ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_details', 'اكتب تفاصيل الشكوى (١٠ أحرف على الأقل).', 400, 'Please describe the problem (at least 10 characters).' );
			}
			$pol = self::policy( $cat, $order );
			if ( $pol['need_order'] && ! $order ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_order', 'حسب السياسة لازم تختار الطلب لهذا النوع من الشكاوى.', 400, 'Under the policy you need to pick the order for this kind of complaint.' );
			}
			if ( $pol['need_photo'] && empty( $photos ) ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_photo', 'حسب السياسة لازم ترفق صورة لهذا النوع من الشكاوى.', 400, 'Under the policy you need to attach a photo for this kind of complaint.' );
			}
			if ( $pol['blocked'] ) {
				return new WP_Error( 'dokan_app_cs_blocked', $pol['blocked'], array( 'status' => 409 ) );
			}

			// Cancellation: put the order on hold now (same as the order screen).
			$cancelled = false;
			if ( 'cancel' === $cat ) {
				$res = Dokan_App_Orders::cancel_request( $order, $details );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$cancelled = true;
			}

			$ai = '' !== $details ? self::understand( $details, $order ) : array( 'subject' => '', 'urgency' => 'high', 'ai' => false );
			if ( '' === $subject ) {
				$subject = '' !== $ai['subject'] ? $ai['subject'] : self::cat_label( $cat ) . ( $order ? ' #' . $order->get_order_number() : '' );
			}
			// Admin always reads Arabic.
			$label_ar = $cats[ $cat ]['ar'];
			if ( $order && false === strpos( $subject, '#' . $order->get_order_number() ) ) {
				$subject .= ' #' . $order->get_order_number();
			}

			// Photos → media library, attached to the case later.
			$att = array();
			foreach ( $photos as $p ) {
				$a = self::save_photo( $p, $uid );
				if ( is_wp_error( $a ) ) {
					foreach ( $att as $done ) {
						wp_delete_attachment( $done, true );
					}
					return $a;
				}
				$att[] = $a;
			}

			$lines = array( $details );
			$meta  = array( '— من تطبيق دكان', 'النوع: ' . $label_ar );
			if ( $cancelled ) {
				$meta[] = 'تم إيقاف الطلب (طلب إلغاء) — الطلب بانتظار الإلغاء.';
			}
			if ( ! empty( $pol['late_damage'] ) ) {
				$meta[] = 'تنبيه السياسة: بلاغ التلف بعد ٢٤ ساعة من الاستلام.';
			}
			if ( 'high' === $ai['urgency'] ) {
				$meta[] = 'الأولوية: عالية';
			}
			foreach ( $att as $i => $a ) {
				$meta[] = 'صورة ' . ( $i + 1 ) . ': ' . wp_get_attachment_url( $a );
			}
			$full = trim( implode( "\n", array_filter( $lines ) ) . "\n\n" . implode( "\n", $meta ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $full ) < 10 ) {
				$full .= "\n" . $label_ar;
			}

			$kind = $cats[ $cat ]['kind'];
			if ( self::desk() ) {
				$data = array(
					'kind'     => $kind,
					'order_id' => $order ? $order->get_id() : 0,
					'subject'  => $subject,
					'details'  => $full,
					'items'    => array(),
					'reason'   => '',
					'channel'  => 'app',
					'token'    => $token,
					'phone'    => DKS_Customer_Service::customer_phone( $uid, $order ? $order : null ),
				);
				$id = DKS_Customer_Service::create( $uid, $data, false );
				if ( is_wp_error( $id ) ) {
					foreach ( $att as $done ) {
						wp_delete_attachment( $done, true );
					}
					return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 409 ) );
				}
			} else {
				// No desk on this site: keep it as an order note + email so nothing is lost.
				$id = 0;
				if ( $order ) {
					$order->add_order_note( 'شكوى من التطبيق: ' . $subject . "\n" . $full );
				}
				wp_mail( get_option( 'admin_email' ), 'شكوى جديدة من التطبيق: ' . $subject, $full );
			}
			if ( $id ) {
				update_post_meta( $id, '_dokan_app_category', $cat );
				update_post_meta( $id, '_dokan_app_urgency', $ai['urgency'] );
				update_post_meta( $id, '_dokan_app_photos', $att );
				foreach ( $att as $a ) {
					wp_update_post( array( 'ID' => $a, 'post_parent' => $id ) );
				}
			}
			if ( $order ) {
				$order->add_order_note( 'فتح العميل ' . ( 'inquiry' === $kind ? 'استفسار' : 'شكوى' ) . ' من التطبيق' . ( $id ? ': CS-' . $id : '' ) . ' — ' . $label_ar );
			}
			return array(
				'ok'        => true,
				'id'        => (int) $id,
				'ref'       => $id ? ( self::desk() ? DKS_Customer_Service::ref( $id ) : 'CS-' . $id ) : '',
				'cancelled' => $cancelled,
				'message'   => $cancelled
					? Dokan_App_Helpers::t( 'وصلنا طلب الإلغاء وأوقفنا الطلب. بنبلغك أول ما يتم الإلغاء.', 'We got your cancellation request and put the order on hold. We will let you know once it is cancelled.' )
					: Dokan_App_Helpers::t( 'وصلتنا شكواك وبنرد عليك في أقرب وقت. تقدر تتابعها من «شكاواي».', 'We got your complaint and will reply soon. You can follow it under "My complaints".' ),
			);
		} );
	}

	/**
	 * Save one base64 photo as a private-ish attachment (random file name).
	 *
	 * @param mixed $b64 Data (optionally data: URI).
	 * @param int   $uid User.
	 * @return int|WP_Error
	 */
	private static function save_photo( $b64, $uid ) {
		$bad = Dokan_App_Helpers::error( 'dokan_app_cs_photo_bad', 'تعذّر رفع الصورة، جرّب صورة ثانية.', 400, 'Could not upload the photo, try another one.' );
		if ( ! is_string( $b64 ) || '' === $b64 ) {
			return $bad;
		}
		$b64 = preg_replace( '#^data:image/[a-z]+;base64,#i', '', $b64 );
		$bin = base64_decode( $b64, true );
		if ( false === $bin || strlen( $bin ) < 200 || strlen( $bin ) > self::MAX_BYTES ) {
			return $bad;
		}
		$info = @getimagesizefromstring( $bin ); // phpcs:ignore
		$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic' );
		if ( ! $info || empty( $info['mime'] ) || ! isset( $ext[ $info['mime'] ] ) ) {
			return $bad;
		}
		$name = 'cs-' . wp_generate_password( 20, false, false ) . '.' . $ext[ $info['mime'] ];
		$up   = wp_upload_bits( $name, null, $bin );
		if ( ! empty( $up['error'] ) ) {
			return $bad;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $info['mime'],
				'post_title'     => 'صورة شكوى',
				'post_status'    => 'inherit',
				'post_author'    => $uid,
			),
			$up['file']
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return $bad;
		}
		update_post_meta( $id, '_dokan_app_cs_photo', 1 );
		return (int) $id;
	}

	private static function state_label( $s ) {
		$en = array(
			'new' => 'Received', 'review' => 'Under review', 'awaiting_customer' => 'Waiting for your reply', 'awaiting_admin' => 'Waiting for our reply',
			'approved' => 'Approved', 'in_transit' => 'On its way to the store', 'received' => 'Product received', 'completed' => 'Resolved',
			'rejected' => 'Declined', 'closed' => 'Closed',
		);
		if ( Dokan_App_Helpers::is_en() ) {
			return isset( $en[ $s ] ) ? $en[ $s ] : $s;
		}
		$ar = self::desk() ? DKS_Customer_Service::states() : array();
		return isset( $ar[ $s ] ) ? $ar[ $s ] : $s;
	}

	private static function shape( $p ) {
		$id    = $p->ID;
		$kind  = (string) get_post_meta( $id, '_dks_cs_kind', true );
		$state = (string) get_post_meta( $id, '_dks_cs_status', true );
		$cat   = (string) get_post_meta( $id, '_dokan_app_category', true );
		$oid   = (int) get_post_meta( $id, '_dks_cs_order', true );
		$o     = $oid && function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : null;
		$upd   = (int) get_post_meta( $id, '_dks_cs_updated', true );
		return array(
			'id'           => $id,
			'ref'          => DKS_Customer_Service::ref( $id ),
			'kind'         => $kind,
			'kind_label'   => 'return' === $kind ? Dokan_App_Helpers::t( 'طلب إرجاع', 'Return' ) : ( 'inquiry' === $kind ? Dokan_App_Helpers::t( 'استفسار', 'Question' ) : Dokan_App_Helpers::t( 'شكوى', 'Complaint' ) ),
			'category'     => $cat,
			'category_label' => '' !== $cat ? self::cat_label( $cat ) : '',
			'subject'      => get_the_title( $p ),
			'status'       => $state,
			'status_label' => self::state_label( $state ),
			'open'         => ! in_array( $state, array( 'completed', 'closed', 'rejected' ), true ),
			'needs_reply'  => 'awaiting_customer' === $state,
			'order_id'     => $oid,
			'order_number' => $o ? $o->get_order_number() : ( $oid ? (string) $oid : '' ),
			'created'      => get_post_time( 'c', true, $p ),
			'updated'      => $upd ? gmdate( 'c', $upd ) : get_post_modified_time( 'c', true, $p ),
		);
	}

	/** GET /complaints */
	public static function list_cases() {
		return self::wrap( function () {
			if ( ! self::desk() ) {
				return array( 'items' => array() );
			}
			$out = array();
			foreach ( DKS_Customer_Service::cases( get_current_user_id(), array( 'posts_per_page' => 30 ) ) as $p ) {
				$out[] = self::shape( $p );
			}
			return array( 'items' => $out );
		} );
	}

	/** GET /complaints/<id> */
	public static function detail( $r ) {
		return self::wrap( function () use ( $r ) {
			$id  = absint( $r['id'] );
			$uid = get_current_user_id();
			if ( ! self::desk() || ! DKS_Customer_Service::owned_case( $id, $uid ) ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_not_found', 'الشكوى غير موجودة.', 404, 'Complaint not found.' );
			}
			$p   = get_post( $id );
			$out = self::shape( $p );
			$det = (string) get_post_meta( $id, '_dks_cs_details', true );
			// Hide the internal footer the app adds for the admin.
			$det = preg_replace( '/\n*— من تطبيق دكان.*$/su', '', $det );
			$out['details'] = trim( $det );
			$out['photos']  = array();
			foreach ( (array) get_post_meta( $id, '_dokan_app_photos', true ) as $a ) {
				$u = $a ? wp_get_attachment_url( (int) $a ) : '';
				if ( $u ) {
					$out['photos'][] = $u;
				}
			}
			$out['messages'] = array();
			$comments = get_comments( array( 'post_id' => $id, 'type' => 'dks_cs_message', 'status' => 'approve', 'orderby' => 'comment_ID', 'order' => 'ASC', 'number' => 200 ) );
			foreach ( $comments as $c ) {
				$admin               = (bool) get_comment_meta( $c->comment_ID, '_dks_cs_admin', true );
				$out['messages'][]   = array(
					'id'    => (int) $c->comment_ID,
					'from'  => $admin ? 'store' : 'me',
					'text'  => $c->comment_content,
					'date'  => mysql_to_rfc3339( $c->comment_date_gmt ),
				);
			}
			return $out;
		} );
	}

	/** POST /complaints/<id>/reply {text} */
	public static function reply( $r ) {
		return self::wrap( function () use ( $r ) {
			$id   = absint( $r['id'] );
			$uid  = get_current_user_id();
			$text = self::str( self::body( $r ), 'text', 3000 );
			if ( ! self::desk() || ! DKS_Customer_Service::owned_case( $id, $uid ) ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_not_found', 'الشكوى غير موجودة.', 404, 'Complaint not found.' );
			}
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) < 2 ) {
				return Dokan_App_Helpers::error( 'dokan_app_cs_reply', 'اكتب ردك.', 400, 'Write your reply.' );
			}
			$res = DKS_Customer_Service::add_reply( $id, $uid, $text, 'awaiting_admin', wp_generate_uuid4(), false );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 409 ) );
			}
			return array( 'ok' => true );
		} );
	}

	/* ------------------------------------------------------------------
	 * Push when the store replies / changes the case
	 * ------------------------------------------------------------------ */

	private static $pushed = array();

	private static function notify( $case_id, $body_ar, $body_en ) {
		if ( isset( self::$pushed[ $case_id ] ) || ! class_exists( 'Dokan_App_Push' ) ) {
			return;
		}
		$p = get_post( $case_id );
		if ( ! $p || 'dks_service_case' !== $p->post_type ) {
			return;
		}
		self::$pushed[ $case_id ] = 1;
		$uid = (int) $p->post_author;
		$en  = 'en' === Dokan_App_Helpers::user_lang( $uid );
		Dokan_App_Push::send_to_user(
			$uid,
			$en ? 'Dokan customer care' : 'خدمة عملاء دكان',
			$en ? $body_en : $body_ar,
			array( 'type' => 'link', 'link' => '/complaint/' . $case_id )
		);
	}

	public static function on_comment_meta( $meta_id, $comment_id, $key, $value ) {
		if ( '_dks_cs_admin' !== $key || ! $value ) {
			return;
		}
		$c = get_comment( $comment_id );
		if ( $c && 'dks_cs_message' === $c->comment_type ) {
			self::notify( (int) $c->comment_post_ID, 'وصلك رد على شكواك ' . 'CS-' . $c->comment_post_ID, 'You have a reply on your case CS-' . $c->comment_post_ID );
		}
	}

	public static function on_status_meta( $meta_id, $post_id, $key, $value ) {
		if ( '_dks_cs_status' !== $key || ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( in_array( $value, array( 'completed', 'approved', 'rejected', 'closed', 'awaiting_customer', 'review' ), true ) ) {
			$ar = self::desk() ? DKS_Customer_Service::states() : array();
			$en = 'Your case CS-' . $post_id . ' was updated';
			self::notify( (int) $post_id, 'تحديث على شكواك CS-' . $post_id . ': ' . ( isset( $ar[ $value ] ) ? $ar[ $value ] : $value ), $en );
		}
	}
}
Dokan_App_Complaints::init();
