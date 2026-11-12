<?php
/**
 * Setup > Request shield > Rules (requestshield/rules): which main rule file
 * the shield loads and why, whether it compiles, and the text of the main
 * file and the files it includes. Read-only: the rule files are edited on
 * the server and checked with bin/request-shield (doc/configuration.md).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

$tpl = eZTemplate::factory();
$tpl->setVariable( 'status', expRequestShield::status() );
$tpl->setVariable( 'files', expRequestShield::ruleFiles() );
$tpl->setVariable( 'show_text', expRequestShieldAdmin::setting( 'ShowRuleText', 'enabled' ) === 'enabled' );
$tpl->setVariable( 'extension_dir', expRequestShield::extensionDir() );
$tpl->setVariable( 'view', 'rules' );

$Result = expRequestShieldAdmin::result( 'rules', $tpl->fetch( 'design:requestshield/rules.tpl' ),
                                         ezpI18n::tr( 'extension/exp_request_shield', 'Rules' ) );
