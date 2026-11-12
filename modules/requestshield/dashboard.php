<?php
/**
 * Setup > Request shield (requestshield/dashboard): whether the shield runs,
 * in which mode, with which rule file and store, and what its log says for
 * the last hours -- a chart per hour, the rules that decided most, the newest
 * lines.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

$status = expRequestShield::status();
$hours = (int)expRequestShieldAdmin::setting( 'DashboardHours', 24 );
$activity = expRequestShield::activity( $status['error'] === null ? $status['log_file'] : null, $hours,
                                        (int)expRequestShieldAdmin::setting( 'DashboardRecent', 15 ) );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'status', $status );
$tpl->setVariable( 'activity', $activity );
$tpl->setVariable( 'chart', expRequestShieldAdmin::chart( $activity ) );
$tpl->setVariable( 'flash', expRequestShieldAdmin::takeFlash() );
$tpl->setVariable( 'can_manage', eZUser::currentUser()->hasAccessTo( 'requestshield', 'manage' )['accessWord'] !== 'no' );
$tpl->setVariable( 'mode_target', expRequestShield::modeTarget() );
$tpl->setVariable( 'switch_modes', expRequestShield::SWITCH_MODES );
$tpl->setVariable( 'view', 'dashboard' );

$Result = expRequestShieldAdmin::result( 'dashboard', $tpl->fetch( 'design:requestshield/dashboard.tpl' ),
                                         ezpI18n::tr( 'extension/exp_request_shield', 'Dashboard' ) );
