<?php
/**
 * exp_request_shield: decides one request. Run by hook.php (PHP-FPM and other
 * web SAPIs, from config.php) or by index.php through EXP_REQUEST_PREPEND_FILE
 * (Exponential Velocity, once per request). Needs nothing from the kernel.
 *
 * Switch the shield off without editing config.php: create the file
 * var/request-shield/PAUSED, or "set mode off" in the main rule file (read
 * again within its "recheck" seconds).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

if ( !class_exists( 'expRequestShield', false ) )
    require __DIR__ . '/../classes/exprequestshield.php';

expRequestShield::protect();
