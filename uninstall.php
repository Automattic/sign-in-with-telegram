<?php
/**
 * Uninstall handler. Invoked by WordPress when the plugin is deleted from
 * the Plugins screen — not on deactivation. The file is only included by
 * Core after `WP_UNINSTALL_PLUGIN` is set to the path of this plugin's main
 * file, so the guards below both block direct-URL access and confirm we're
 * being included for *our* uninstall rather than another plugin's.
 *
 * See https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

// Bail if uninstall.php is not being invoked by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Defense in depth: confirm Core is calling *this* plugin's uninstall.
// WP only includes one uninstall.php per delete operation, so this should
// always match, but the wp.org plugin reviewer expects the explicit guard.
if ( dirname( WP_UNINSTALL_PLUGIN ) !== dirname( plugin_basename( __FILE__ ) ) ) {
	exit;
}

$telegram_auth_settings = get_option( 'telegram_auth_settings', array() );
if ( ! is_array( $telegram_auth_settings ) || empty( $telegram_auth_settings['clean_uninstall'] ) ) {
	return;
}

// Settings option itself.
delete_option( 'telegram_auth_settings' );

// Cached OIDC discovery + JWKS + the JWKS-refresh lockout.
delete_transient( 'telegram_auth_oidc_discovery' );
delete_transient( 'telegram_auth_jwks' );
delete_transient( 'telegram_auth_jwks_refresh_lockout' );

global $wpdb;

/*
 * Live login transactions live under the `telegram_auth_tx_<random>`
 * prefix as both `_transient_<key>` and `_transient_timeout_<key>`
 * rows in the options table. WP doesn't expose a "delete by prefix"
 * API, so a couple of LIKE-scoped deletes are the cleanest path.
 */
$telegram_auth_tx_prefix = $wpdb->esc_like( '_transient_telegram_auth_tx_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $telegram_auth_tx_prefix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$telegram_auth_tx_timeout_prefix = $wpdb->esc_like( '_transient_timeout_telegram_auth_tx_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $telegram_auth_tx_timeout_prefix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/*
 * Per-user metadata we own outright. The fourth `$delete_all = true`
 * argument drops the rows for every user in one go without needing
 * to paginate through the users table.
 */
delete_metadata( 'user', 0, 'telegram_auth_sub', '', true );
delete_metadata( 'user', 0, 'telegram_auth_picture_url', '', true );
delete_metadata( 'user', 0, 'telegram_auth_phone', '', true );
delete_metadata( 'user', 0, 'telegram_auth_granted_scopes', '', true );
