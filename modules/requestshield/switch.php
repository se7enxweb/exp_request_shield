<?php
/**
 * Setup > Request shield, the switches (requestshield/switch, policy requestshield/manage): pause the shield and
 * switch it on again (var/request-shield/PAUSED), and its mode (the "set mode" line of the site's main rule file,
 * made from the extension's default when the site has none). POST only, with the form token of ezformtoken; every
 * action ends in a redirect to the dashboard, which shows its outcome once, so a reload repeats nothing. Every action
 * is written to var/log/requestshield.log with the user who did it.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();

$outcome = null;
$what = '';
if ( $Module->isCurrentAction( 'Pause' ) )
{
    $what = 'pause';
    $outcome = expRequestShield::setPaused( true );
}
elseif ( $Module->isCurrentAction( 'Resume' ) )
{
    $what = 'resume';
    $outcome = expRequestShield::setPaused( false );
}
elseif ( $Module->isCurrentAction( 'Mode' ) )
{
    $mode = $Module->hasActionParameter( 'Mode' ) ? (string)$Module->actionParameter( 'Mode' ) : '';
    $what = 'mode ' . preg_replace( '/[^a-z]/', '', $mode );
    $outcome = expRequestShield::setMode( $mode );
}

if ( $outcome !== null )
{
    $user = eZUser::currentUser();
    eZLog::write( sprintf( '%s by %s (#%d): %s%s', $what, $user->attribute( 'login' ), $user->attribute( 'contentobject_id' ),
                           $outcome['ok'] ? 'done. ' : 'refused. ', $outcome['text'] ), 'requestshield.log' );
    $http->setSessionVariable( expRequestShieldAdmin::FLASH, array( 'ok' => (bool)$outcome['ok'], 'text' => $outcome['text'] ) );
}

return $Module->redirectTo( 'requestshield/dashboard' );
