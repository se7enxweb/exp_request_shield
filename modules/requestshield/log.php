<?php
/**
 * Setup > Request shield > Log (requestshield/log): the shield's log lines of
 * the chosen period, newest first, by group (refused, checked, watched,
 * other), a page at a time. The choices are view parameters:
 * requestshield/log/(hours)/24/(group)/refused/(offset)/50.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

$Module = $Params['Module'];
$params = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();

$choices = array_values( array_filter( array_map( 'intval', (array)expRequestShieldAdmin::setting( 'LogHours', array( 1, 6, 24, 72, 168 ) ) ) ) );
if ( !$choices )
    $choices = array( 24 );
$hours = isset( $params['hours'] ) && in_array( (int)$params['hours'], $choices, true ) ? (int)$params['hours'] : ( in_array( 24, $choices, true ) ? 24 : $choices[0] );
$group = isset( $params['group'] ) && in_array( $params['group'], expRequestShieldAdmin::GROUPS, true ) ? $params['group'] : '';
$offset = isset( $params['offset'] ) ? max( 0, (int)$params['offset'] ) : 0;
$limit = max( 10, (int)expRequestShieldAdmin::setting( 'LogPageSize', 50 ) );

$status = expRequestShield::status();
// Every line of the period: the newest come first, the page is cut below.
$activity = expRequestShield::activity( $status['error'] === null ? $status['log_file'] : null, $hours, 100000 );
$rows = $activity['recent'];
if ( $group !== '' )
    $rows = array_values( array_filter( $rows, function ( $r ) use ( $group ) { return $r['group'] === $group; } ) );
$total = count( $rows );
if ( $offset >= $total )
    $offset = 0;

$base = 'requestshield/log/(hours)/' . $hours . ( $group !== '' ? '/(group)/' . $group : '' );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'status', $status );
$tpl->setVariable( 'activity', $activity );
$tpl->setVariable( 'rows', array_slice( $rows, $offset, $limit ) );
$tpl->setVariable( 'hours', $hours );
$tpl->setVariable( 'hour_choices', $choices );
$tpl->setVariable( 'group', $group );
$tpl->setVariable( 'groups', expRequestShieldAdmin::GROUPS );
$tpl->setVariable( 'total', $total );
$tpl->setVariable( 'first', $total ? $offset + 1 : 0 );
$tpl->setVariable( 'last', min( $total, $offset + $limit ) );
$tpl->setVariable( 'prev_url', $offset > 0 ? $base . ( $offset - $limit > 0 ? '/(offset)/' . ( $offset - $limit ) : '' ) : '' );
$tpl->setVariable( 'next_url', $offset + $limit < $total ? $base . '/(offset)/' . ( $offset + $limit ) : '' );
$tpl->setVariable( 'view', 'log' );

$Result = expRequestShieldAdmin::result( 'log', $tpl->fetch( 'design:requestshield/log.tpl' ),
                                         ezpI18n::tr( 'extension/exp_request_shield', 'Log' ) );
