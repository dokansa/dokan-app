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
delete_option( 'dokan_app_claude_key' );
delete_option( 'dokan_app_search_log' );
delete_option( 'dokan_app_search_dirty' );
delete_transient( 'dokan_app_claude_down' );
global $wpdb;
// Search indexes (per version + language), config caches, Claude intent cache, rate limits.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dokan\\_app\\_search\\_index\\_%' OR option_name LIKE '\\_transient\\_dokan\\_app\\_ai\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dokan\\_app\\_ai\\_%' OR option_name LIKE '\\_transient\\_dokan\\_app\\_config\\_cache%' OR option_name LIKE '\\_transient\\_timeout\\_dokan\\_app\\_config\\_cache%' OR option_name LIKE '\\_transient\\_dokan\\_app\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dokan\\_app\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'dokan_app_search_rebuild' );
foreach ( array( 'all', 'cat', 'dog', 'bird', 'fish', 'small' ) as $dokan_app_kind ) {
	delete_transient( 'dokan_app_rec_' . $dokan_app_kind );
}
wp_clear_scheduled_hook( 'dokan_app_daily_reminders' );
