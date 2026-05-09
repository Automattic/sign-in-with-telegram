<?php
/**
 * PHPUnit bootstrap. Loads Composer + Brain Monkey so unit tests can stub
 * WP functions without booting WordPress.
 *
 * @package Telegram_Auth\Tests
 */

declare(strict_types=1);

// Polyfill the WordPress constants our class files reference at load time.
// The plugin uses `defined( 'ABSPATH' ) || exit;` as a direct-access guard,
// and `HOUR_IN_SECONDS` shows up in default cache TTLs. These are core
// constants, not plugin-owned globals, so the prefix sniff is suppressed.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * 60 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
