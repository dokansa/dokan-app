<?php
/**
 * Plugin Name:       Dokan App API
 * Plugin URI:        https://dokansa.com
 * Description:       واجهة برمجية لتطبيق دكان للجوال: تسجيل الدخول برمز التحقق، الحيوانات الأليفة، الطلبات والتتبع، الإشعارات، البحث الذكي والمساعد، العربية والإنجليزية، وإعدادات التطبيق.
 * Version:           1.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Dokan Saudi
 * Text Domain:       dokan-app
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 * License:           GPL-2.0-or-later
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOKAN_APP_VERSION', '1.4.1' );
define( 'DOKAN_APP_FILE', __FILE__ );
define( 'DOKAN_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOKAN_APP_NS', 'dokan-app/v1' );

require_once DOKAN_APP_DIR . 'includes/class-dokan-app-helpers.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-auth.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-otp.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-pets.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-orders.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-push.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-reminders.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-store-api.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-search.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-claude.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-rest.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-admin.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-complaints.php';
require_once DOKAN_APP_DIR . 'includes/class-dokan-app-staging-pay.php';

/**
 * Declare HPOS (custom order tables) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Activation: generate the token secret and schedule the reminders cron.
 */
function dokan_app_activate() {
	Dokan_App_Helpers::get_secret();
	Dokan_App_Reminders::schedule();
}
register_activation_hook( __FILE__, 'dokan_app_activate' );

/**
 * One-time update of saved delivery notes (the store's delivery times changed:
 * Riyadh 1-3 days, outside Riyadh 2-7 days by weight).
 */
add_action(
	'init',
	function () {
		if ( get_option( 'dokan_app_delivery_v2' ) ) {
			return;
		}
		$all = get_option( Dokan_App_Helpers::OPT_SETTINGS, array() );
		if ( is_array( $all ) ) {
			$d = Dokan_App_Helpers::defaults();
			foreach ( array( 'delivery_note_riyadh', 'delivery_note_ksa', 'delivery_note_riyadh_en', 'delivery_note_ksa_en' ) as $k ) {
				unset( $all[ $k ] );
			}
			update_option( Dokan_App_Helpers::OPT_SETTINGS, $all );
		}
		update_option( 'dokan_app_delivery_v2', 1, false );
	}
);

/**
 * Deactivation: clear scheduled events.
 */
function dokan_app_deactivate() {
	Dokan_App_Reminders::unschedule();
	wp_clear_scheduled_hook( Dokan_App_Search::CRON_REBUILD );
}
register_deactivation_hook( __FILE__, 'dokan_app_deactivate' );

/**
 * Bootstrap. Auth must be hooked early (before REST dispatch / determine_current_user).
 */
Dokan_App_Auth::init();
Dokan_App_Store_API::init();
Dokan_App_Push::init();
Dokan_App_Reminders::init();
Dokan_App_Orders::init();
Dokan_App_Search::init();
Dokan_App_REST::init();

if ( is_admin() ) {
	Dokan_App_Admin::init();
}

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'dokan-app', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
