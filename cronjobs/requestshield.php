<?php
/**
 * Cronjob part requestshield (runcronjobs.php requestshield, once a day): brings the request shield's downloaded
 * lists up to date for the site's main rule file -- the public blocklists its "feed" rules name (request-shield feeds
 * update) and the crawlers' published address lists (request-shield crawlers update). Each list keeps its own
 * shortest interval, so running more often fetches nothing new. The lists go to the rules' store-dir.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

if ( !class_exists( 'expRequestShield' ) )
    require_once __DIR__ . '/../classes/exprequestshield.php';

$file = expRequestShield::ruleFile();
if ( !is_file( $file ) )
{
    $cli->error( "request shield: no main rule file ($file)" );
    return;
}
// The library's command line ends with exit() in several places (it is meant to run on its own): it runs as a
// process of its own with this PHP, so it can never end the cronjob run.
$bin = expRequestShield::extensionDir() . '/bin/request-shield';
foreach ( array( 'feeds', 'crawlers' ) as $command )
{
    $lines = array();
    $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $bin ) . ' ' . $command . ' ' . escapeshellarg( $file ) . ' update 2>&1', $lines, $code );
    $cli->output( "request shield: $command update, exit $code" . ( $lines ? ': ' . end( $lines ) : '' ) );
    if ( $code !== 0 )
        foreach ( array_slice( $lines, -5 ) as $line )
            $cli->output( '  ' . $line );
}
