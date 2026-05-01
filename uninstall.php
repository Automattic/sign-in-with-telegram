<?php
/**
 * Uninstall handler.
 *
 * Real cleanup of options, transients, and (optionally) usermeta lands in
 * the dedicated uninstall task. For now we only enforce that this file is
 * never executed outside the WordPress uninstall flow.
 *
 * @package Telegram_Auth
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
