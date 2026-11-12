<?php
/**
 * The admin module "requestshield" (Setup > Request shield): what the request
 * filter in front of the site decides, its log and its rules, and the
 * switches for it. The shield is configured in its rule files
 * (doc/configuration.md); the switches change exactly those files.
 *
 * Policies: requestshield/read for every page; requestshield/manage for the
 * switches on the dashboard (requestshield/switch: pause and resume, the
 * mode), which change files on the server. Only the Administrator role, which
 * holds every policy, has them in a new installation.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

$Module = array( 'name' => 'Request shield',
                 'variable_params' => false );

$ViewList = array();

$ViewList['dashboard'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'dashboard.php',
    'functions' => array( 'read' ),
    'params' => array(),
    'unordered_params' => array() );

$ViewList['log'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'log.php',
    'functions' => array( 'read' ),
    'params' => array(),
    'unordered_params' => array( 'hours' => 'Hours', 'group' => 'Group', 'offset' => 'Offset' ) );

$ViewList['rules'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'rules.php',
    'functions' => array( 'read' ),
    'params' => array(),
    'unordered_params' => array() );

$ViewList['switch'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'switch.php',
    'functions' => array( 'manage' ),
    'params' => array(),
    'unordered_params' => array(),
    // the mode buttons are <button name="Mode" value="enforce|monitor|off">: the button's own value is the parameter
    'single_post_actions' => array( 'PauseButton' => 'Pause',
                                    'ResumeButton' => 'Resume',
                                    'Mode' => 'Mode' ),
    'post_action_parameters' => array( 'Mode' => array( 'Mode' => 'Mode' ) ) );

$FunctionList = array();
$FunctionList['read'] = array();
$FunctionList['manage'] = array();
