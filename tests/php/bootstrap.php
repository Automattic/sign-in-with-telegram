<?php
/**
 * PHPUnit bootstrap. Loads Composer + Brain Monkey so unit tests can stub
 * WP functions without booting WordPress.
 *
 * @package Telegram_Auth\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
