<?php
/**
 * File containing the expRequestShieldAdmin class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

/**
 * The parts the views of Setup > Request shield share: the settings of
 * requestshield.ini, the chart's geometry, and the page result with its path.
 * The data comes from expRequestShield, which reads the shield's own files.
 */
class expRequestShieldAdmin
{
    /** The session key the outcome of a switch is carried in to the dashboard after the redirect. */
    const FLASH = 'RequestShieldSwitchFlash';

    /**
     * The outcome of the last switch, once: taken out of the session.
     *
     * @return array|null ok, text
     */
    public static function takeFlash()
    {
        $http = eZHTTPTool::instance();
        if ( !$http->hasSessionVariable( self::FLASH, false ) )
            return null;
        $flash = $http->sessionVariable( self::FLASH );
        $http->removeSessionVariable( self::FLASH );
        return is_array( $flash ) && isset( $flash['text'] ) ? $flash : null;
    }

    /** The groups of log actions, in the order the chart stacks them (bottom first). */
    const GROUPS = array( 'refused', 'checked', 'watched', 'other' );

    /** The chart's drawing area, in the units of its viewBox. */
    const CHART_WIDTH = 720;
    const CHART_HEIGHT = 180;

    /**
     * A setting of [AdminSettings] in requestshield.ini.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function setting( $name, $default )
    {
        $ini = eZINI::instance( 'requestshield.ini' );
        return $ini->hasVariable( 'AdminSettings', $name ) ? $ini->variable( 'AdminSettings', $name ) : $default;
    }

    /**
     * The bars of the activity chart: one stacked bar per hour, a rect per
     * group with its place in the viewBox. Attributes only, so the template
     * needs no style="" (doc/bc/6.0/admin4-styling.md).
     *
     * @param array $activity expRequestShield::activity()
     * @return array width, height, bars (each: time, total, label, rects), ticks
     */
    public static function chart( array $activity )
    {
        $buckets = $activity['buckets'];
        $n = max( 1, count( $buckets ) );
        $max = max( 1, (int)$activity['max'] );
        $slot = self::CHART_WIDTH / $n;
        $barWidth = max( 1, round( $slot * 0.72, 2 ) );
        $bars = array();
        foreach ( $buckets as $i => $b )
        {
            $x = round( $i * $slot + ( $slot - $barWidth ) / 2, 2 );
            $y = self::CHART_HEIGHT;
            $rects = array();
            foreach ( self::GROUPS as $group )
            {
                if ( empty( $b[$group] ) )
                    continue;
                $h = round( $b[$group] / $max * self::CHART_HEIGHT, 2 );
                $y = round( $y - $h, 2 );
                $rects[] = array( 'group' => $group, 'x' => $x, 'y' => $y, 'width' => $barWidth, 'height' => max( 0.5, $h ), 'count' => $b[$group] );
            }
            $bars[] = array( 'time' => $b['time'], 'total' => $b['total'], 'rects' => $rects,
                             'x' => round( $i * $slot, 2 ), 'slot' => round( $slot, 2 ) );
        }
        // Up to seven hour labels under the bars.
        $ticks = array();
        $every = max( 1, (int)ceil( $n / 7 ) );
        foreach ( $bars as $i => $bar )
        {
            if ( $i % $every === 0 )
                $ticks[] = array( 'time' => $bar['time'], 'x' => round( $bar['x'] + $bar['slot'] / 2, 2 ) );
        }
        return array( 'width' => self::CHART_WIDTH, 'height' => self::CHART_HEIGHT, 'max' => $max, 'bars' => $bars, 'ticks' => $ticks );
    }

    /**
     * The module result of a view: its content, the path Setup > Request
     * shield > <title>; the navigation part (ezsetupnavigationpart) draws the Setup menu.
     *
     * @param string $view
     * @param string $content
     * @param string $title
     * @return array
     */
    public static function result( $view, $content, $title )
    {
        $path = array(
            array( 'url' => 'setup/menu', 'text' => ezpI18n::tr( 'design/admin/parts/setup/menu', 'Setup' ) ),
            array( 'url' => 'requestshield/dashboard', 'text' => ezpI18n::tr( 'extension/exp_request_shield', 'Request shield' ) ),
        );
        if ( $view !== 'dashboard' )
            $path[] = array( 'url' => false, 'text' => $title );
        return array(
            'content' => $content,
            'path' => $path,
        );
    }
}
