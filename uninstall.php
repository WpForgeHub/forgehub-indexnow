<?php
/**
 * Uninstall cleanup for ForgeHub IndexNow (Free build).
 *
 * @package ForgeHub_IndexNow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpfh_in_settings' );
delete_option( 'wpfh_in_pro_settings' );
delete_option( 'wpfh_in_logs' );
delete_option( 'wpfh_in_retry_queue' );
delete_option( 'wpfh_in_review_nudge' );
wp_clear_scheduled_hook( 'wpfh_in_retry_event' );
