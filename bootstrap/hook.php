<?php
/**
 * exp_request_shield: the one line config.php needs.
 *
 *   if ( is_file( __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php' ) )
 *       require __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php';
 *
 * Put it before the HTTP cache's early exit in config.php, so cached pages
 * are protected too.
 *
 * - Apache or nginx with PHP-FPM (and any other web SAPI): config.php runs
 *   on every request, so the shield decides the request right here, before
 *   the kernel, the INI files or the database load.
 * - Exponential Velocity (PHP_SAPI cli with the class Q_WebServer): config.php
 *   runs once per worker, at the warm-up. index.php runs the file named in
 *   EXP_REQUEST_PREPEND_FILE on every request, so the hook names prepend.php
 *   there instead of running it now.
 * - Any other command line (cronjobs, bin/php scripts): nothing.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

if ( defined( 'EXP_REQUEST_SHIELD_DISABLED' ) && constant( 'EXP_REQUEST_SHIELD_DISABLED' ) )
    return;

if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' )
{
    if ( class_exists( 'Q_WebServer', false ) && !defined( 'EXP_REQUEST_PREPEND_FILE' ) )
        define( 'EXP_REQUEST_PREPEND_FILE', __DIR__ . '/prepend.php' );
    return;
}

require __DIR__ . '/prepend.php';
