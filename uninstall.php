<?php
/**
 * Removes everything Site Bridge stored.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'site_bridge_settings' );
delete_option( 'site_bridge_log' );
delete_option( 'site_bridge_write_log' );
delete_option( 'site_bridge_errors' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sb\_rl\_%' OR option_name LIKE '\_transient\_timeout\_sb\_rl\_%' OR option_name LIKE '\_transient\_sb\_new\_token\_%' OR option_name LIKE '\_transient\_timeout\_sb\_new\_token\_%'" ); // phpcs:ignore
