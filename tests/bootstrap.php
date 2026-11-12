<?php
/**
 * PHPUnit bootstrap for the exp_request_shield unit tests.
 *
 * Loads only the extension's plain PHP classes. Nothing of Exponential is booted: no database, no siteaccess, no
 * INI, no session. The library is loaded by the tests that need it (expRequestShield::loadLibrary()). The default
 * time zone is UTC, so a test that needs another one says so.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

date_default_timezone_set( 'UTC' );

$checkout = dirname( __DIR__ );
require_once $checkout . '/classes/exprequestshield.php';
// expRequestShieldAdmin::chart() is plain PHP; its other methods need the kernel and are not called here.
require_once $checkout . '/classes/exprequestshieldadmin.php';
