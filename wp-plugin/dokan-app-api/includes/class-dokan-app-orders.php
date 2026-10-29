<?php
/**
 * Orders: shapes, stages, tracking, stock / days-of-supply, reorder, cancel, recommendations,
 * product meta field.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Orders {

	const META_DAYS        = '_dokan_app_days_supply';
	const META_REMIND      = '_dokan_app_remind';
	const META_STAGE_DATES = '_dokan_app_stage_dates';
	const REMIND_DAYS      = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'record_stage_date' ), 5, 4 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
	}

	/* ------------------------------------------------------------------
	 * Product meta field
	 * ------------------------------------------------------------------ */

	/**
	 * Render fields in General tab.
	 */
	public static function product_fields() {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_DAYS,
				'label'             => 'مدة الكفاية بالأيام',
				'description'       => 'كم يومًا تكفي القطعة الواحدة من هذا المنتج (يُستخدم في تطبيق دكان لتذكير العميل بإعادة الطلب). اتركه فارغًا للتقدير التلقائي من الوزن لمنتجات الطعام والرمل.',
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_REMIND,
				'label'       => 'تذكير إعادة الطلب',
				'description' => 'إرسال تذكير للعميل في التطبيق قبل نفاد المنتج',
				'value'       => 'no' === get_post_meta( get_the_ID(), self::META_REMIND, true ) ? 'no' : 'yes',
				'cbvalue'     => 'yes',
			)
		);
		echo '</div>';
	}

	/**
	 * Save fields.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product_fields( $product ) {
		// Nonce is verified by WooCommerce's product save handler.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST[ self::META_DAYS ] ) ) {
			$raw = trim( sanitize_text_field( wp_unslash( $_POST[ self::META_DAYS ] ) ) );
			if ( '' === $raw ) {
				$product->delete_meta_data( self::META_DAYS );
			} else {
				$product->update_meta_data( self::META_DAYS, max( 0, absint( $raw ) ) );
			}
		}
		$product->update_meta_data( self::META_REMIND, isset( $_POST[ self::META_REMIND ] ) ? 'yes' : 'no' );
		// phpcs:enable
	}

	/* ------------------------------------------------------------------
	 * Stages / status
	 * ------------------------------------------------------------------ */

	/**
	 * Status (no wc- prefix) → stage.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function stage_for_status( $status ) {
		$status = preg_replace( '/^wc-/', '', (string) $status );
		$map    = array(
			'pending'          => 1,
			'on-hold'          => 1,
			'checkout-draft'   => 1,
			'processing'       => 2,
			'shipped'          => 3,
			'out-for-delivery' => 4,
			'completed'        => 5,
			'delivered'        => 5,
			'cancelled'        => 0,
			'refunded'         => 0,
			'failed'           => 0,
		);
		$stage  = isset( $map[ $status ] ) ? $map[ $status ] : 1;
		return (int) apply_filters( 'dokan_app_order_stage', $stage, $status );
	}

	/**
	 * Stage labels.
	 *
	 * @return array
	 */
	public static function stage_labels() {
		return apply_filters(
			'dokan_app_stage_labels',
			array(
				1 => 'تم استلام الطلب',
				2 => 'قيد التجهيز',
				3 => 'تم الشحن',
				4 => 'خرج للتوصيل',
				5 => 'تم التوصيل',
			)
		);
	}

	/**
	 * Record the date each stage was reached.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     From.
	 * @param string   $to       To.
	 * @param WC_Order $order    Order.
	 */
	public static function record_stage_date( $order_id, $from, $to, $order = null ) {
		try {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$stage = self::stage_for_status( $to );
			if ( $stage < 1 ) {
				return;
			}
			$dates = $order->get_meta( self::META_STAGE_DATES );
			$dates = is_array( $dates ) ? $dates : array();
			if ( empty( $dates[ $stage ] ) ) {
				$dates[ $stage ] = time();
				$order->update_meta_data( self::META_STAGE_DATES, $dates );
				$order->save_meta_data();
			}
		} catch ( \Throwable $e ) {
			Dokan_App_Helpers::log( 'stage date error: ' . $e->getMessage() );
		}
	}

	/**
	 * Get an order owned by a user, or null.
	 *
	 * @param int $order_id Order id.
	 * @param int $user_id  User id.
	 * @return WC_Order|null
	 */
	public static function get_user_order( $order_id, $user_id ) {
		if ( ! function_exists( 'wc_get_order' ) || $order_id < 1 || $user_id < 1 ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return null;
		}
		if ( (int) $order->get_customer_id() !== (int) $user_id ) {
			return null;
		}
		if ( 'checkout-draft' === $order->get_status() ) {
			return null;
		}
		return $order;
	}

	/**
	 * Visible order statuses (prefixed) for queries.
	 *
	 * @return string[]
	 */
	public static function visible_statuses() {
		$all = array_keys( wc_get_order_statuses() );
		return array_values( array_diff( $all, array( 'wc-checkout-draft' ) ) );
	}

	/**
	 * Order summary shape.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function summary( $order ) {
		$count = 0;
		$first = '';
		foreach ( $order->get_items() as $item ) {
			$count += (int) $item->get_quantity();
			if ( '' === $first && is_callable( array( $item, 'get_product' ) ) ) {
				$first = Dokan_App_Helpers::product_image( $item->get_product() );
			}
		}
		$status = $order->get_status();
		return array(
			'id'           => (int) $order->get_id(),
			'number'       => (string) $order->get_order_number(),
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'date_created' => Dokan_App_Helpers::iso_date( $order->get_date_created() ),
			'total'        => Dokan_App_Helpers::money( $order->get_total() ),
			'currency'     => $order->get_currency(),
			'item_count'   => $count,
			'first_image'  => '' !== $first ? $first : null,
		);
	}

	/**
	 * Order detail shape.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function detail( $order ) {
		$out   = self::summary( $order );
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$pid     = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$img     = Dokan_App_Helpers::product_image( $product );
			$items[] = array(
				'product_id' => (int) $pid,
				'name'       => $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'total'      => Dokan_App_Helpers::money( (float) $item->get_total() + (float) $item->get_total_tax() ),
				'image'      => '' !== $img ? $img : null,
			);
		}
		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fees[] = array(
				'name'  => $fee->get_name(),
				'total' => Dokan_App_Helpers::money( (float) $fee->get_total() + (float) $fee->get_total_tax() ),
			);
		}
		$stage = self::stage_for_status( $order->get_status() );

		$out['items']                = $items;
		$out['shipping_total']       = Dokan_App_Helpers::money( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() );
		$out['fees']                 = $fees;
		$out['payment_method_title'] = $order->get_payment_method_title();
		$out['shipping_address']     = self::address( $order );
		$out['stage']                = $stage;
		$out['tracking']             = self::tracking( $order );
		$out['can_cancel']           = 'processing' === $order->get_status();
		$out['timeline']             = self::timeline( $order, $stage );
		return apply_filters( 'dokan_app_order_detail', $out, $order );
	}

	/**
	 * Shipping address (falls back to billing).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function address( $order ) {
		$type   = $order->has_shipping_address() ? 'shipping' : 'billing';
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		$addr   = array();
		foreach ( $fields as $f ) {
			$getter     = 'get_' . $type . '_' . $f;
			$addr[ $f ] = is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : '';
		}
		$phone = '';
		if ( 'shipping' === $type && is_callable( array( $order, 'get_shipping_phone' ) ) ) {
			$phone = (string) $order->get_shipping_phone();
		}
		if ( '' === $phone ) {
			$phone = (string) $order->get_billing_phone();
		}
		$addr['phone'] = $phone;
		return $addr;
	}

	/**
	 * Tracking info from shipping-platform meta.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function tracking( $order ) {
		$url      = (string) $order->get_meta( '_tracking_url' );
		$number   = (string) $order->get_meta( '_tracking_number' );
		$provider = (string) $order->get_meta( '_tracking_provider' );

		// Fallback: WooCommerce Shipment Tracking plugin format.
		if ( '' === $url && '' === $number ) {
			$st = $order->get_meta( '_wc_shipment_tracking_items' );
			if ( is_array( $st ) && ! empty( $st ) ) {
				$last     = end( $st );
				$number   = isset( $last['tracking_number'] ) ? (string) $last['tracking_number'] : '';
				$provider = ! empty( $last['custom_tracking_provider'] ) ? (string) $last['custom_tracking_provider'] : ( isset( $last['tracking_provider'] ) ? (string) $last['tracking_provider'] : '' );
				$url      = isset( $last['custom_tracking_link'] ) ? (string) $last['custom_tracking_link'] : '';
			}
		}

		$tracking = null;
		if ( '' !== $url || '' !== $number ) {
			$tracking = array(
				'carrier' => $provider,
				'number'  => $number,
				'url'     => '' !== $url ? esc_url_raw( $url ) : '',
			);
		}
		return apply_filters( 'dokan_app_order_tracking', $tracking, $order );
	}

	/**
	 * Timeline (stages 1..5).
	 *
	 * @param WC_Order $order Order.
	 * @param int      $stage Current stage.
	 * @return array
	 */
	private static function timeline( $order, $stage ) {
		$dates = $order->get_meta( self::META_STAGE_DATES );
		$dates = is_array( $dates ) ? $dates : array();
		$tz    = wp_timezone();
		$fmt   = function ( $ts ) use ( $tz ) {
			$d = new DateTime( '@' . (int) $ts );
			$d->setTimezone( $tz );
			return $d->format( 'c' );
		};
		$known = array();
		if ( $order->get_date_created() ) {
			$known[1] = Dokan_App_Helpers::iso_date( $order->get_date_created() );
		}
		if ( $order->get_date_paid() ) {
			$known[2] = Dokan_App_Helpers::iso_date( $order->get_date_paid() );
		}
		if ( $order->get_date_completed() ) {
			$known[5] = Dokan_App_Helpers::iso_date( $order->get_date_completed() );
		}
		foreach ( $dates as $s => $ts ) {
			if ( $ts ) {
				$known[ (int) $s ] = $fmt( $ts );
			}
		}
		$out = array();
		foreach ( self::stage_labels() as $s => $label ) {
			$date = null;
			if ( $stage > 0 && $s <= $stage && isset( $known[ $s ] ) ) {
				$date = $known[ $s ];
			} elseif ( 0 === $stage && 1 === (int) $s && isset( $known[1] ) ) {
				$date = $known[1];
			}
			$out[] = array(
				'stage' => (int) $s,
				'label' => $label,
				'date'  => $date,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Days of supply / stock
	 * ------------------------------------------------------------------ */

	/**
	 * Keywords for food / litter categories.
	 *
	 * @return string[]
	 */
	private static function consumable_keywords() {
		return apply_filters(
			'dokan_app_consumable_category_keywords',
			array( 'food', 'litter', 'treat', 'طعام', 'اكل', 'أكل', 'غذاء', 'رمل', 'فضلات', 'وجبات', 'دراي', 'معلبات' )
		);
	}

	/**
	 * Keywords per pet kind for category matching.
	 *
	 * @return array
	 */
	public static function kind_keywords() {
		return apply_filters(
			'dokan_app_kind_category_keywords',
			array(
				'cat'   => array( 'cat', 'cats', 'kitten', 'قطط', 'قط', 'القطط' ),
				'dog'   => array( 'dog', 'dogs', 'puppy', 'كلاب', 'كلب', 'الكلاب' ),
				'bird'  => array( 'bird', 'birds', 'طيور', 'الطيور', 'عصافير', 'ببغاء', 'كناري' ),
				'fish'  => array( 'fish', 'aquarium', 'أسماك', 'اسماك', 'سمك', 'الأسماك' ),
				'small' => array( 'small', 'rabbit', 'hamster', 'حيوانات صغيرة', 'أرانب', 'ارانب', 'قوارض', 'هامستر', 'سلاحف', 'سلحفاة', 'turtle', 'turtles' ),
			)
		);
	}

	/**
	 * Category terms (incl. ancestors) for a product, as lowercased "slug name" strings.
	 *
	 * @param int $product_id Parent product id.
	 * @return string[]
	 */
	private static function category_strings( $product_id ) {
		static $cache = array();
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}
		$ids   = wc_get_product_term_ids( $product_id, 'product_cat' );
		$all   = $ids;
		foreach ( $ids as $id ) {
			$all = array_merge( $all, get_ancestors( $id, 'product_cat', 'taxonomy' ) );
		}
		$out = array();
		foreach ( array_unique( $all ) as $tid ) {
			$t = get_term( $tid, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$s     = urldecode( $t->slug ) . ' ' . $t->name;
				$out[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
			}
		}
		$cache[ $product_id ] = $out;
		return $out;
	}

	/**
	 * Do the product's categories match any keyword?
	 *
	 * @param int      $product_id Parent id.
	 * @param string[] $keywords   Keywords.
	 * @return bool
	 */
	private static function categories_match( $product_id, $keywords ) {
		$strings = self::category_strings( $product_id );
		foreach ( $strings as $s ) {
			foreach ( $keywords as $kw ) {
				$kw = function_exists( 'mb_strtolower' ) ? mb_strtolower( $kw ) : strtolower( $kw );
				if ( '' !== $kw && false !== strpos( $s, $kw ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Days of supply for one unit of a product (0 = not tracked).
	 *
	 * @param WC_Product $product Product (may be variation).
	 * @return int
	 */
	public static function days_supply( $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return 0;
		}
		$days = $product->get_meta( self::META_DAYS );
		$pid  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( '' === $days && $product->get_parent_id() ) {
			$days = get_post_meta( $product->get_parent_id(), self::META_DAYS, true );
		}
		if ( '' === $days || null === $days || false === $days ) {
			$days   = 0;
			$weight = (float) $product->get_weight();
			if ( $weight > 0 && self::categories_match( $pid, self::consumable_keywords() ) ) {
				$kg   = function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( $weight, 'kg' ) : $weight;
				$days = (int) round( $kg * 15 );
			}
		}
		return max( 0, (int) apply_filters( 'dokan_app_days_supply', (int) $days, $product ) );
	}

	/**
	 * Is the product-level reminder enabled?
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function product_remind( $product ) {
		$pid = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$on  = 'no' !== get_post_meta( $pid, self::META_REMIND, true );
		return (bool) apply_filters( 'dokan_app_product_remind', $on, $product );
	}

	/**
	 * Statuses counted as "bought" for stock tracking.
	 *
	 * @return string[]
	 */
	private static function bought_statuses() {
		$want  = apply_filters( 'dokan_app_stock_statuses', array( 'wc-processing', 'wc-completed', 'wc-shipped', 'wc-out-for-delivery', 'wc-delivered' ) );
		$exist = array_keys( wc_get_order_statuses() );
		return array_values( array_intersect( $want, $exist ) );
	}

	/**
	 * Does an order item belong to a pet?
	 *
	 * @param array    $pet       Pet shape.
	 * @param string   $order_pet Order's pet id.
	 * @param int      $parent_id Product parent id.
	 * @param int      $pet_count Number of pets user has.
	 * @return bool
	 */
	private static function item_for_pet( $pet, $order_pet, $parent_id, $pet_count ) {
		if ( '' !== $order_pet ) {
			return $order_pet === $pet['id'];
		}
		if ( 1 === $pet_count ) {
			return true;
		}
		$kw = self::kind_keywords();
		return isset( $kw[ $pet['kind'] ] ) && self::categories_match( $parent_id, $kw[ $pet['kind'] ] );
	}

	/**
	 * Internal stock list: latest purchase per product with days of supply.
	 *
	 * @param int        $user_id User.
	 * @param array|null $pet     Pet shape to filter by, or null for all.
	 * @return array List with internal keys (order_id, item_id, pet_id, product).
	 */
	public static function stock_internal( $user_id, $pet = null ) {
		$statuses = self::bought_statuses();
		if ( empty( $statuses ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id'  => (int) $user_id,
				'status'       => $statuses,
				'limit'        => 60,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'type'         => 'shop_order',
				'date_created' => '>' . ( time() - YEAR_IN_SECONDS ),
			)
		);
		$pets      = Dokan_App_Pets::list_shapes( $user_id );
		$pet_count = count( $pets );
		$seen      = array();
		$out       = array();
		$now       = time();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$order_pet = (string) $order->get_meta( '_dokan_app_pet_id' );
			$date      = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
			if ( ! $date ) {
				continue;
			}
			$ts = $date->getTimestamp();
			foreach ( $order->get_items() as $item_id => $item ) {
				$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
				if ( ! $product ) {
					continue;
				}
				$pid    = $product->get_id();
				$parent = $product->get_parent_id() ? $product->get_parent_id() : $pid;
				if ( isset( $seen[ $pid ] ) ) {
					continue; // Orders are newest first: keep latest purchase only.
				}
				$per_unit = self::days_supply( $product );
				if ( $per_unit < 1 ) {
					continue;
				}
				if ( null !== $pet && ! self::item_for_pet( $pet, $order_pet, $parent, $pet_count ) ) {
					continue;
				}
				$seen[ $pid ] = true;
				$total_days   = $per_unit * max( 1, (int) $item->get_quantity() );
				$elapsed      = (int) floor( ( $now - $ts ) / DAY_IN_SECONDS );
				$days_left    = max( 0, $total_days - $elapsed );
				$remind_ts    = $ts + max( 0, $total_days - self::REMIND_DAYS ) * DAY_IN_SECONDS;
				$img          = Dokan_App_Helpers::product_image( $product );

				// Pet this item is attributed to (for reminders).
				$item_pet = $order_pet;
				if ( '' === $item_pet && null !== $pet ) {
					$item_pet = $pet['id'];
				} elseif ( '' === $item_pet ) {
					foreach ( $pets as $p ) {
						if ( self::item_for_pet( $p, '', $parent, $pet_count ) ) {
							$item_pet = $p['id'];
							break;
						}
					}
				}

				$out[] = array(
					'product_id'      => (int) $pid,
					'name'            => $product->get_name(),
					'image'           => '' !== $img ? $img : null,
					'last_order_date' => wp_date( 'Y-m-d', $ts ),
					'days_supply'     => (int) $total_days,
					'days_left'       => (int) $days_left,
					'percent_left'    => $total_days > 0 ? (int) round( $days_left / $total_days * 100 ) : 0,
					'remind_on'       => wp_date( 'Y-m-d', $remind_ts ),
					'_order_id'       => (int) $order->get_id(),
					'_item_id'        => (int) $item_id,
					'_pet_id'         => $item_pet,
					'_product'        => $product,
					'_elapsed'        => $elapsed,
				);
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['days_left'] - $b['days_left'];
			}
		);
		return $out;
	}

	/**
	 * Public stock list for a pet.
	 *
	 * @param int   $user_id User.
	 * @param array $pet     Pet.
	 * @return array
	 */
	public static function stock_for_pet( $user_id, $pet ) {
		$items = self::stock_internal( $user_id, $pet );
		$out   = array();
		foreach ( $items as $i ) {
			$out[] = array(
				'product_id'      => $i['product_id'],
				'name'            => $i['name'],
				'image'           => $i['image'],
				'last_order_date' => $i['last_order_date'],
				'days_supply'     => $i['days_supply'],
				'days_left'       => $i['days_left'],
				'percent_left'    => $i['percent_left'],
				'remind_on'       => $i['remind_on'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Reorder / cancel
	 * ------------------------------------------------------------------ */

	/**
	 * Items to re-add (the app adds them via Store API /cart/add-item).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function reorder_items( $order ) {
		$items   = array();
		$skipped = 0;
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || 'publish' !== get_post_status( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ) {
				$skipped++;
				continue;
			}
			$qty = max( 1, (int) $item->get_quantity() );
			if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
				$stock = (int) $product->get_stock_quantity();
				if ( $stock < 1 ) {
					$skipped++;
					continue;
				}
				$qty = min( $qty, $stock );
			}
			if ( $product->is_sold_individually() ) {
				$qty = 1;
			}
			$entry = array(
				'id'       => (int) $product->get_id(),
				'quantity' => $qty,
			);
			if ( $product->is_type( 'variation' ) ) {
				$vars = array();
				foreach ( $product->get_variation_attributes() as $attr => $val ) {
					if ( '' === $val ) {
						$meta_key = str_replace( 'attribute_', '', $attr );
						$val      = (string) $item->get_meta( $meta_key );
					}
					$vars[] = array(
						'attribute' => str_replace( 'attribute_', '', $attr ),
						'value'     => $val,
					);
				}
				$entry['variation'] = $vars;
			}
			$items[] = $entry;
		}
		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Cancel request.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason.
	 * @return array|WP_Error
	 */
	public static function cancel_request( $order, $reason ) {
		if ( 'processing' !== $order->get_status() ) {
			return Dokan_App_Helpers::error( 'dokan_app_cannot_cancel', 'لا يمكن طلب إلغاء هذا الطلب. الإلغاء متاح فقط عندما يكون الطلب قيد التنفيذ وقبل شحنه.', 409 );
		}
		$note = 'طلب إلغاء من العميل عبر التطبيق.';
		if ( '' !== $reason ) {
			$note .= ' السبب: ' . $reason;
		}
		$order->update_meta_data( '_dokan_app_cancel_requested', time() );
		$order->update_status( 'on-hold', $note );
		do_action( 'dokan_app_cancel_requested', $order, $reason );
		return array(
			'ok'     => true,
			'status' => $order->get_status(),
		);
	}

	/* ------------------------------------------------------------------
	 * Recommendations
	 * ------------------------------------------------------------------ */

	/**
	 * Recommended product ids.
	 *
	 * @param int        $user_id User.
	 * @param array|null $pet     Pet.
	 * @return int[]
	 */
	public static function recommendations( $user_id, $pet ) {
		$ids = array();

		// 1) Products running low → rebuy first.
		$stock = self::stock_internal( $user_id, $pet );
		foreach ( $stock as $s ) {
			if ( $s['days_left'] <= 10 ) {
				$p     = $s['_product'];
				$ids[] = $p->get_parent_id() ? (int) $p->get_parent_id() : (int) $p->get_id();
			}
		}

		// 2) Best sellers in the pet's kind categories (or overall).
		$kind      = $pet ? $pet['kind'] : '';
		$cache_key = 'dokan_app_rec_' . ( '' !== $kind ? $kind : 'all' );
		$best      = get_transient( $cache_key );
		if ( ! is_array( $best ) ) {
			$args = array(
				'status'       => 'publish',
				'limit'        => 30,
				'orderby'      => 'meta_value_num',
				'meta_key'     => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery
				'order'        => 'DESC',
				'stock_status' => 'instock',
				'return'       => 'ids',
				'visibility'   => 'catalog',
			);
			if ( '' !== $kind ) {
				$cats = self::kind_category_slugs( $kind );
				if ( ! empty( $cats ) ) {
					$args['category'] = $cats;
				}
			}
			$best = array_map( 'intval', (array) wc_get_products( $args ) );
			set_transient( $cache_key, $best, HOUR_IN_SECONDS );
		}
		$ids = array_merge( $ids, $best );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$ids = array_slice( $ids, 0, 20 );
		return array_values( array_map( 'intval', (array) apply_filters( 'dokan_app_recommendations', $ids, $user_id, $pet ) ) );
	}

	/* ------------------------------------------------------------------
	 * Purchased products (v1.1) / guest order linking (v1.2)
	 * ------------------------------------------------------------------ */

	/**
	 * Product ids the customer bought (parents), most frequent then most recent, only published
	 * and in stock, max 20. Polylang: mapped to the copy in the request language.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function purchased_ids( $user_id ) {
		$want     = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-shipped', 'wc-out-for-delivery', 'wc-delivered' );
		$statuses = array_values( array_intersect( $want, array_keys( wc_get_order_statuses() ) ) );
		$orders   = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'status'      => $statuses,
				'type'        => 'shop_order',
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$freq   = array();
		$recent = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$ts   = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			$seen = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! is_callable( array( $item, 'get_product_id' ) ) ) {
					continue;
				}
				$pid = (int) $item->get_product_id(); // Parent id for variations.
				if ( $pid < 1 || isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ]  = true;
				$freq[ $pid ]  = ( isset( $freq[ $pid ] ) ? $freq[ $pid ] : 0 ) + 1;
				if ( ! isset( $recent[ $pid ] ) ) {
					$recent[ $pid ] = $ts;
				}
			}
		}
		$ids = array_keys( $freq );
		usort(
			$ids,
			function ( $a, $b ) use ( $freq, $recent ) {
				if ( $freq[ $a ] !== $freq[ $b ] ) {
					return $freq[ $b ] - $freq[ $a ];
				}
				return $recent[ $b ] - $recent[ $a ];
			}
		);
		$lang = Dokan_App_Helpers::lang();
		$out  = array();
		foreach ( $ids as $pid ) {
			if ( function_exists( 'pll_get_post' ) ) {
				$tr = (int) pll_get_post( $pid, $lang );
				if ( $tr > 0 ) {
					$pid = $tr;
				}
			}
			if ( in_array( $pid, $out, true ) || 'publish' !== get_post_status( $pid ) ) {
				continue;
			}
			$p = wc_get_product( $pid );
			if ( ! $p || ! $p->is_in_stock() ) {
				continue;
			}
			$out[] = (int) $pid;
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return array_values( array_map( 'intval', (array) apply_filters( 'dokan_app_purchased_ids', $out, $user_id ) ) );
	}

	/**
	 * Attach past guest orders (customer_id = 0) whose billing phone matches the account phone
	 * (last 9 digits) to the account. At most $limit per call; idempotent (attached orders are
	 * no longer guest orders).
	 *
	 * @param int    $user_id User.
	 * @param string $phone   Normalised phone (optional; read from the user otherwise).
	 * @param int    $limit   Batch size.
	 * @return int Number attached now.
	 */
	public static function link_guest_orders( $user_id, $phone = '', $limit = 200 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		if ( '' === $phone ) {
			$phone = Dokan_App_Helpers::user_phone( $user_id );
		}
		$last9 = Dokan_App_Helpers::last9( $phone );
		if ( '' === $last9 ) {
			return 0;
		}
		$like = '%' . $wpdb->esc_like( $last9 );
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$norm = "REPLACE(REPLACE(REPLACE(%s,' ',''),'-',''),'+','')";
		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$addr   = $wpdb->prefix . 'wc_order_addresses';
			$col    = sprintf( $norm, 'a.phone' );
			$sql    = "SELECT o.id, a.phone FROM {$orders} o INNER JOIN {$addr} a ON a.order_id = o.id AND a.address_type = 'billing' WHERE o.type = 'shop_order' AND o.customer_id = 0 AND {$col} LIKE %s ORDER BY o.id DESC LIMIT %d";
		} else {
			$col = sprintf( $norm, 'pm.meta_value' );
			$sql = "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_phone' INNER JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user' WHERE p.post_type = 'shop_order' AND cu.meta_value IN ('0','') AND {$col} LIKE %s ORDER BY p.ID DESC LIMIT %d";
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $like, max( 1, min( 200, (int) $limit ) ) ), ARRAY_N ); // phpcs:ignore WordPress.DB
		$linked = 0;
		foreach ( (array) $rows as $r ) {
			if ( Dokan_App_Helpers::last9( $r[1] ) !== $last9 ) {
				continue;
			}
			$order = wc_get_order( (int) $r[0] );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 0 !== (int) $order->get_customer_id() ) {
				continue;
			}
			if ( ! apply_filters( 'dokan_app_link_guest_order', true, $order, $user_id ) ) {
				continue;
			}
			$order->set_customer_id( $user_id );
			$order->add_order_note( 'تم ربط الطلب بحساب العميل تلقائيًا عند تسجيل الدخول من التطبيق (تطابق رقم الجوال).' );
			$order->save();
			$linked++;
		}
		if ( $linked ) {
			do_action( 'dokan_app_guest_orders_linked', $user_id, $linked );
		}
		return $linked;
	}

	/**
	 * Category slugs matching a pet kind.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	private static function kind_category_slugs( $kind ) {
		$kw = self::kind_keywords();
		if ( empty( $kw[ $kind ] ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$slugs = array();
		foreach ( $terms as $t ) {
			$s = urldecode( $t->slug ) . ' ' . $t->name;
			$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
			foreach ( $kw[ $kind ] as $k ) {
				$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $k ) : strtolower( $k );
				if ( false !== strpos( $s, $k ) ) {
					$slugs[] = $t->slug;
					break;
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}
}
