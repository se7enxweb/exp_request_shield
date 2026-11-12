<?php
/**
 * exp_request_shield: the Rector configuration that turns the library's PHP 8.0 single files into the PHP 7.4 build
 * in lib/requestshield/php74/. Used by bin/update-library.sh (RECTOR=<path to vendor/bin/rector>); the files to
 * change are passed on the command line. Only the syntax and the PHP 8.0 functions are downgraded; the behavior is
 * proven by running check and test of the shipped rules on PHP 7.4 and comparing them with PHP 8.x.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

return RectorConfig::configure()
    ->withSets( array( DowngradeLevelSetList::DOWN_TO_PHP_74 ) )
    ->withCache( getenv( 'EXP_RS_RECTOR_CACHE' ) ?: dirname( __DIR__ ) . '/.rector-cache' );
