<?php
/**
 * Daily reorder reminders (wp-cron at 10:00 Asia/Riyadh).
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Reminders {

	const HOOK      = 'dokan_app_daily_reminders';
	const ITEM_META = '_dokan_app_reminded';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		// Self-heal if the event got lost (e.g. plugin updated without reactivation).
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					Dokan_App_Reminders::schedule();
				}
			}
		);
	}

	/**
	 * Next 10:00 Riyadh timestamp.
	 *
	 * @return int
	 */
	public static function next_run() {
		$tz   = Dokan_App_Helpers::riyadh_tz();
		$now  = new DateTime( 'now', $tz );
		$next = new DateTime( 'today 10:00', $tz );
		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Schedule.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::next_run(), 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run reminders.
	 */
	public static function run() {
		if ( ! class_exists( 'WooCommerce' ) || ! Dokan_App_Push::is_configured() ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$users = get_users(
			array(
				'meta_key'     => Dokan_App_Push::META_DEVICES, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);
		foreach ( $users as $uid ) {
			try {
				self::run_for_user( (int) $uid );
			} catch ( \Throwable $e ) {
				Dokan_App_Helpers::log( 'reminder error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Reminders for one user.
	 *
	 * @param int $user_id User.
	 */
	public static function run_for_user( $user_id ) {
		$pets = array();
		foreach ( Dokan_App_Pets::list_shapes( $user_id ) as $p ) {
			$pets[ $p['id'] ] = $p;
		}
		$items = Dokan_App_Orders::stock_internal( $user_id, null );
		foreach ( $items as $s ) {
			if ( $s['days_left'] > Dokan_App_Orders::REMIND_DAYS ) {
				continue;
			}
			// Skip things long finished (bought > 2× supply ago): customer moved on.
			if ( $s['_elapsed'] > $s['days_supply'] * 2 + 7 ) {
				continue;
			}
			$product = $s['_product'];
			if ( ! Dokan_App_Orders::product_remind( $product ) ) {
				continue;
			}
			$pet_name = '';
			if ( '' !== $s['_pet_id'] && isset( $pets[ $s['_pet_id'] ] ) ) {
				if ( ! $pets[ $s['_pet_id'] ]['remind'] ) {
					continue;
				}
				$pet_name = $pets[ $s['_pet_id'] ]['name'];
			} elseif ( ! empty( $pets ) ) {
				// If every pet has reminders off, respect that.
				$any_on = false;
				foreach ( $pets as $p ) {
					if ( $p['remind'] ) {
						$any_on = true;
						break;
					}
				}
				if ( ! $any_on ) {
					continue;
				}
			}
			$item = function_exists( 'wc_get_order_item_meta' ) ? wc_get_order_item_meta( $s['_item_id'], self::ITEM_META, true ) : '';
			if ( $item ) {
				continue;
			}
			// Mark first so a failure can't cause repeated pushes.
			wc_update_order_item_meta( $s['_item_id'], self::ITEM_META, time() );

			$title = 'قرّب يخلص؟ 🐾';
			if ( $s['days_left'] < 1 ) {
				$body = '' !== $pet_name
					? sprintf( 'يبدو أن %1$s خلص عند %2$s. اطلبه الآن بضغطة واحدة.', $s['name'], $pet_name )
					: sprintf( 'يبدو أن %s خلص. اطلبه الآن بضغطة واحدة.', $s['name'] );
			} else {
				$body = '' !== $pet_name
					? sprintf( 'باقي تقريبًا %1$d أيام على نفاد %2$s عند %3$s. اطلبه الآن ليصلك في الوقت.', $s['days_left'], $s['name'], $pet_name )
					: sprintf( 'باقي تقريبًا %1$d أيام على نفاد %2$s. اطلبه الآن ليصلك في الوقت.', $s['days_left'], $s['name'] );
			}
			$data = array(
				'type'       => 'reminder',
				'product_id' => (string) $s['product_id'],
				'order_id'   => (string) $s['_order_id'],
			);
			Dokan_App_Push::send_to_user( $user_id, $title, $body, apply_filters( 'dokan_app_reminder_data', $data, $s, $user_id ) );
		}
	}
}
