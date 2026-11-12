<?php
/**
 * expRequestShield and the shipped rules, on the files alone: where the main rule file is found, the PHP build
 * loaded, the groups of log actions, the log summary and the chart geometry of the admin, and the extension's
 * default rules checked and tested with the bundled library (every example passes, enforce mode).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

class RequestShieldTest extends PHPUnit\Framework\TestCase
{
    private static function ext()
    {
        return dirname( __DIR__, 2 );
    }

    public function testThePathsFollowTheExtension()
    {
        $this->assertSame( self::ext(), expRequestShield::extensionDir() );
        if ( !defined( 'EXP_REQUEST_SHIELD_ROOT' ) )
        {
            $this->assertSame( dirname( self::ext(), 2 ), expRequestShield::rootDir() );
            $this->assertSame( dirname( self::ext(), 2 ) . '/var/request-shield/cache', expRequestShield::cacheDir() );
        }
    }

    public function testTheMainRuleFileComesFromTheSiteOrTheExtension()
    {
        $r = expRequestShield::ruleFileSource();
        if ( defined( 'EXP_REQUEST_SHIELD_RULES' ) )
            $this->assertSame( 'constant', $r['source'] );
        elseif ( is_file( expRequestShield::siteRuleFile() ) )
            $this->assertSame( array( 'file' => expRequestShield::siteRuleFile(), 'source' => 'site' ), $r );
        else
            $this->assertSame( array( 'file' => self::ext() . '/rules/request-shield.rules', 'source' => 'extension' ), $r );
    }

    public function testTheBuildForThisPhpIsLoaded()
    {
        $dir = expRequestShield::libraryDir();
        $this->assertSame( PHP_VERSION_ID < 80000 ? self::ext() . '/lib/requestshield/php74' : self::ext() . '/lib/requestshield', $dir );
        foreach ( expRequestShield::LIBRARY_FILES as $file )
        {
            $this->assertFileExists( self::ext() . '/lib/requestshield/' . $file );
            $this->assertFileExists( self::ext() . '/lib/requestshield/php74/' . $file );
        }
        $this->assertTrue( expRequestShield::loadLibrary() );
        $v = expRequestShield::libraryVersion();
        $this->assertSame( '0.4.0', $v['version'] );
    }

    /**
     * The Velocity path (expRequestShield::protectVelocity()) runs the library's inner steps itself: they must be
     * there with the expected shape, in this build and in the PHP 7.4 build, or a worker falls back.
     */
    public function testTheLibraryHasTheStepsTheVelocityPathUses()
    {
        $this->assertTrue( expRequestShield::loadLibrary() );
        $r = new ReflectionClass( 'CjwNetwork\\RequestShield\\Shield' );
        $this->assertSame( 2, $r->getMethod( 'run' )->getNumberOfParameters() );
        $this->assertSame( 2, $r->getMethod( 'failedOpen' )->getNumberOfParameters() );
        $this->assertTrue( $r->hasProperty( 'failedDir' ) && $r->getProperty( 'failedDir' )->isStatic() );
        $this->assertTrue( $r->hasProperty( 'ruleFile' ) && $r->getProperty( 'ruleFile' )->isStatic() );
        foreach ( array( '', '/php74' ) as $build )
        {
            $code = file_get_contents( self::ext() . '/lib/requestshield' . $build . '/request-shield.php' );
            $this->assertMatchesRegularExpression( '/private static function run\\(.*\\$config, \\?callable \\$known\\)/', $code, "run() in build '$build'" );
            $this->assertMatchesRegularExpression( '/private static function failedOpen\\(\\\\Throwable \\$e, \\?Settings \\$s\\)/', $code, "failedOpen() in build '$build'" );
            $this->assertMatchesRegularExpression( '/private static [^;]*\\$failedDir = null;/', $code );
            $this->assertMatchesRegularExpression( '/private static [^;]*\\$ruleFile = null;/', $code );
        }
    }

    public function testTheVersionIsTheSameEverywhere()
    {
        $ext = self::ext();
        $this->assertStringContainsString( "'Version' => \"" . expRequestShield::VERSION . '"', file_get_contents( $ext . '/ezinfo.php' ) );
        $this->assertStringContainsString( '<version>' . expRequestShield::VERSION . '</version>', file_get_contents( $ext . '/extension.xml' ) );
        $this->assertStringContainsString( '## ' . expRequestShield::VERSION, file_get_contents( $ext . '/doc/CHANGELOG.md' ) );
    }

    public function testLogActionsAreGrouped()
    {
        $this->assertSame( 'refused', expRequestShield::group( 'reject' ) );
        $this->assertSame( 'refused', expRequestShield::group( 'ban' ) );
        $this->assertSame( 'refused', expRequestShield::group( 'slow' ) );
        $this->assertSame( 'checked', expRequestShield::group( 'challenge' ) );
        $this->assertSame( 'watched', expRequestShield::group( 'monitor-reject' ) );
        $this->assertSame( 'watched', expRequestShield::group( 'monitor-challenge' ) );
        $this->assertSame( 'other', expRequestShield::group( 'allow' ) );
    }

    public function testTheLogIsSummedUpPerHour()
    {
        $dir = __DIR__ . '/../.tmp-' . getmypid();      // inside the checkout, never the system's temp dir
        @mkdir( $dir );
        $log = $dir . '/shield.log';
        $now = gmmktime( 12, 30, 0, 11, 12, 2026 );
        $line = function ( $t, $action, $status, $rule, $url ) {
            return gmdate( 'Y-m-d\TH:i:sP', $t ) . " 198.51.100.0/24 $action $status \"reason\" rule=$rule \"GET $url\" \"Agent\"\n";
        };
        file_put_contents( $log,
            $line( $now - 30 * 3600, 'reject', 404, 'EXP-SYSVIEW', 'https://www.example.org/old' ) .   // before the period
            $line( $now - 3 * 3600, 'reject', 404, 'EXP-SYSVIEW', 'https://www.example.org/content/view/full/2' ) .
            $line( $now - 2 * 3600, 'challenge', 429, 'EXP-LOGIN', 'https://www.example.org/user/login' ) .
            $line( $now - 600, 'monitor-reject', 404, 'EXP-INI', 'https://www.example.org/site.ini' ) .
            $line( $now - 60, 'reject', 404, 'EXP-SYSVIEW', 'https://www.example.org/content/view/full/3' ) .
            "not a log line\n" );
        try
        {
            $a = expRequestShield::activity( $log, 24, 2, $now );
        }
        finally
        {
            @unlink( $log );
            @rmdir( $dir );
        }
        $this->assertTrue( $a['readable'] );
        $this->assertSame( 4, $a['lines'] );
        $this->assertSame( array( 'refused' => 2, 'checked' => 1, 'watched' => 1, 'other' => 0 ), $a['groups'] );
        $this->assertSame( 'EXP-SYSVIEW', $a['rules'][0]['rule'] );
        $this->assertSame( 2, $a['rules'][0]['count'] );
        $this->assertCount( 2, $a['recent'] );
        $this->assertSame( 'https://www.example.org/content/view/full/3', $a['recent'][0]['url'] );   // newest first
        $this->assertSame( 'refused', $a['recent'][0]['group'] );
        $this->assertCount( 25, $a['buckets'] );
        $this->assertSame( 2, $a['max'] );
        $this->assertSame( 4, array_sum( array_column( $a['buckets'], 'total' ) ) );
    }

    public function testTheChartStacksTheGroupsInsideTheViewBox()
    {
        $buckets = array(
            array( 'time' => 0, 'refused' => 2, 'checked' => 1, 'watched' => 1, 'other' => 0, 'total' => 4 ),
            array( 'time' => 3600, 'refused' => 0, 'checked' => 0, 'watched' => 0, 'other' => 0, 'total' => 0 ),
        );
        $c = expRequestShieldAdmin::chart( array( 'buckets' => $buckets, 'max' => 4 ) );
        $this->assertCount( 2, $c['bars'] );
        $this->assertCount( 3, $c['bars'][0]['rects'] );
        $this->assertCount( 0, $c['bars'][1]['rects'] );
        $this->assertSame( 'refused', $c['bars'][0]['rects'][0]['group'] );
        $top = $c['bars'][0]['rects'][2];
        $this->assertEqualsWithDelta( 0.0, $top['y'], 0.01 );   // the busiest hour fills the height
        foreach ( $c['bars'][0]['rects'] as $r )
        {
            $this->assertGreaterThanOrEqual( 0, $r['x'] );
            $this->assertLessThanOrEqual( $c['width'], $r['x'] + $r['width'] );
            $this->assertLessThanOrEqual( $c['height'] + 0.02, $r['y'] + $r['height'] );   // rounded to two decimals
        }
    }

    public function testTheShippedRulesEnforceAndEveryExamplePasses()
    {
        $rules = self::ext() . '/rules/request-shield.rules';
        $this->assertMatchesRegularExpression( '/^set\s+mode\s+enforce\b/m', file_get_contents( $rules ) );
        $php = escapeshellarg( PHP_BINARY );
        $cli = escapeshellarg( self::ext() . '/bin/request-shield' );
        exec( "$php $cli check " . escapeshellarg( $rules ) . ' 2>&1', $check );
        $this->assertNotEmpty( preg_grep( '/^ok: /', $check ), implode( "\n", $check ) );
        exec( "$php $cli test " . escapeshellarg( $rules ) . ' 2>&1', $test, $code );
        $this->assertSame( 0, $code, implode( "\n", $test ) );
        $this->assertMatchesRegularExpression( '/^\d+ examples: \d+ pass\.$/', end( $test ) );
    }
}
