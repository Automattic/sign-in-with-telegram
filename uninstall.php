<?php
/**
 * Uninstall handler.
 *
 * @package Telegram_Auth
 */

declare(strict_types=1);

if (
	! defined( 'WP_UNINSTALL_PLUGIN' ) ||
	! WP_UNINSTALL_PLUGIN ||
	dirname( WP_UNINSTALL_PLUGIN ) !== dirname( plugin_basename( __FILE__ ) )
) {
		status_header( 404 );
		exit( 0 );
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
 *
 * billing_phone is intentionally left alone: WooCommerce, BuddyPress,
 * several themes, and the user themselves all write to it, and there
 * is no way at uninstall time to know which value originated with
 * this plugin.
 */
delete_metadata( 'user', 0, 'telegram_auth_sub', '', true );
delete_metadata( 'user', 0, 'telegram_auth_picture_url', '', true );
delete_metadata( 'user', 0, 'telegram_auth_granted_scopes', '', true );
