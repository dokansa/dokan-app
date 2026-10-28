<?php
/**
 * Uninstall: delete plugin options and transients only. User data (pets, devices,
 * token versions) and order meta are intentionally kept.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dokan_app_secret' );
delete_option( 'dokan_app_settings' );
delete_option( 'dokan_app_fcm_service_account' );
delete_transient( 'dokan_app_fcm_access_token' );
delete_transient( 'dokan_app_config_cache' );
delete_transient( 'dokan_app_config_cache_1.0.1' );
foreach ( array( 'all', 'cat', 'dog', 'bird', 'fish', 'small' ) as $dokan_app_kind ) {
	delete_transient( 'dokan_app_rec_' . $dokan_app_kind );
}
wp_clear_scheduled_hook( 'dokan_app_daily_reminders' );
