<?php
/**
 * File containing the expRequestShield class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

/**
 * Where request-shield's files are on this installation, and what the admin
 * pages show about it.
 *
 * The first half (paths, loading the library, protecting a request) needs
 * nothing from the kernel: bootstrap/prepend.php calls it before the kernel
 * starts, from config.php, where no autoloader and no INI exist yet. The second
 * half (status(), activity()) is for the admin views and reads the shield's
 * own data through the library's classes (Settings, Tier, LogStats), so what
 * the admin shows is what the shield decides.
 *
 * The paths, each overridable by a constant defined in config.php before the
 * hook line:
 *
 *   EXP_REQUEST_SHIELD_ROOT    the installation's root (default: three levels above this extension's bootstrap/)
 *   EXP_REQUEST_SHIELD_RULES   the main rule file (default: settings/request-shield/request-shield.rules in the
 *                              root when it exists, else rules/request-shield.rules of this extension)
 *   EXP_REQUEST_SHIELD_VAR     where the compiled settings, the store and the log go (default: var/request-shield)
 */
class expRequestShield
{
    /** The extension's version, as in ezinfo.php and extension.xml. */
    const VERSION = '0.0.1.2';

    /**
     * The library files, in the order they must be loaded: the core first, the API before the firewall pages. They
     * keep the suffix .php: Exponential Velocity turns exit() into its exit signal only in included *.php files, so
     * under another suffix a refusal would end the worker (a 502). The kernel's .autoloadignore keeps the folder out of
     * the autoload array (both builds declare the same classes).
     */
    const LIBRARY_FILES = array( 'request-shield.php', 'request-shield-stats.php', 'request-shield-api.php', 'request-shield-waf.php', 'request-shield-cache.php' );

    /** The file whose presence in the var directory switches the shield off without touching config.php. */
    const PAUSE_FILE = 'PAUSED';

    /** This extension's directory. */
    public static function extensionDir()
    {
        return dirname( __DIR__ );
    }

    /** The installation's root directory. */
    public static function rootDir()
    {
        if ( defined( 'EXP_REQUEST_SHIELD_ROOT' ) )
            return rtrim( (string)constant( 'EXP_REQUEST_SHIELD_ROOT' ), '/' );
        return dirname( self::extensionDir(), 2 );
    }

    /** The directory the shield writes to: compiled settings (cache/), and by the shipped rules the store and the log. */
    public static function varDir()
    {
        if ( defined( 'EXP_REQUEST_SHIELD_VAR' ) )
            return rtrim( (string)constant( 'EXP_REQUEST_SHIELD_VAR' ), '/' );
        return self::rootDir() . '/var/request-shield';
    }

    /** Where the compiled settings are kept. */
    public static function cacheDir()
    {
        return self::varDir() . '/cache';
    }

    /** The site's own main rule file, whether or not it exists. */
    public static function siteRuleFile()
    {
        return self::rootDir() . '/settings/request-shield/request-shield.rules';
    }

    /** The main rule file the shield loads, and where it came from: constant, site or extension. */
    public static function ruleFileSource()
    {
        if ( defined( 'EXP_REQUEST_SHIELD_RULES' ) )
            return array( 'file' => (string)constant( 'EXP_REQUEST_SHIELD_RULES' ), 'source' => 'constant' );
        $site = self::siteRuleFile();
        if ( is_file( $site ) )
            return array( 'file' => $site, 'source' => 'site' );
        return array( 'file' => self::extensionDir() . '/rules/request-shield.rules', 'source' => 'extension' );
    }

    /** The main rule file the shield loads. */
    public static function ruleFile()
    {
        $r = self::ruleFileSource();
        return $r['file'];
    }

    /** Whether the shield is paused by the file var/request-shield/PAUSED. */
    public static function isPaused()
    {
        return is_file( self::varDir() . '/' . self::PAUSE_FILE );
    }

    /**
     * The directory of the library build for this PHP: the library's own files
     * on PHP 8.0 and newer, the same files downgraded to PHP 7.4 syntax
     * (build/rector-php74.php) below that.
     */
    public static function libraryDir()
    {
        return self::extensionDir() . '/lib/requestshield' . ( PHP_VERSION_ID < 80000 ? '/php74' : '' );
    }

    /**
     * Loads the library once per process. Under Exponential Velocity a worker
     * keeps the classes it declared, so the files are never required twice.
     *
     * @return bool whether the core is there
     */
    public static function loadLibrary()
    {
        if ( class_exists( 'CjwNetwork\\RequestShield\\Shield', false ) )
            return true;
        $dir = self::libraryDir();
        if ( !is_file( $dir . '/' . self::LIBRARY_FILES[0] ) )
            return false;
        foreach ( self::LIBRARY_FILES as $file )
        {
            if ( is_file( $dir . '/' . $file ) )
                require_once $dir . '/' . $file;
        }
        return class_exists( 'CjwNetwork\\RequestShield\\Shield', false );
    }

    /** The exception Exponential Velocity throws for exit() in a worker, so that it ends the request, not the worker. */
    const VELOCITY_EXIT = 'Q_WebServer_ExitSignal';

    /**
     * Decides the current request. Called by bootstrap/prepend.php only.
     *
     * Under PHP-FPM this is Shield::protectFile(). Under Exponential Velocity
     * exit() throws Q_WebServer_ExitSignal, and the library's protect() catches
     * every Throwable as its own failure ("shield error"): its answer (a
     * refusal, the check page, a page of its own) would be marked as a failure
     * in the debug header and in PHP's error log. So in a Velocity worker the
     * same steps run here with that one exception let through, as an exit is
     * meant to be (protectVelocity()). Should the library's inner steps ever
     * look different, the request falls back to protectFile() and ends the
     * request when the shield has written an answer of its own.
     *
     * @return void
     */
    public static function protect()
    {
        if ( self::isPaused() || !self::loadLibrary() )
            return;
        $file = self::ruleFile();
        if ( !is_file( $file ) )
            return;

        if ( class_exists( 'Q_WebServer', false ) && class_exists( self::VELOCITY_EXIT, false ) && self::protectVelocity( $file ) )
            return;

        $statusBefore = http_response_code();
        $headersBefore = self::ownHeaderCount();

        $decision = \CjwNetwork\RequestShield\Shield::protectFile( $file, null, self::cacheDir() );

        if ( $decision->reason === 'shield error' && class_exists( 'Q_WebServer', false )
             && ( http_response_code() !== $statusBefore || self::ownHeaderCount() > $headersBefore ) )
        {
            exit;
        }
    }

    /**
     * Shield::protectFile() for a Velocity worker: the same steps (where
     * "already noted" is kept, the rule file, the settings of this request's
     * website, the decision), each failure handled by the library's own
     * failedOpen() -- except Velocity's exit signal, which passes on and ends
     * the request with the answer the shield wrote.
     *
     * @param string $file the main rule file
     * @return bool false when the library's inner steps are not the expected ones (the caller falls back)
     */
    private static function protectVelocity( $file )
    {
        static $usable = null;
        $shield = 'CjwNetwork\\RequestShield\\Shield';
        if ( $usable === null )
        {
            try
            {
                $r = new \ReflectionClass( $shield );
                $usable = $r->hasMethod( 'run' ) && $r->hasMethod( 'failedOpen' )
                          && $r->hasProperty( 'failedDir' ) && $r->hasProperty( 'ruleFile' )
                          && $r->getMethod( 'run' )->getNumberOfParameters() === 2
                          && $r->getMethod( 'failedOpen' )->getNumberOfParameters() === 2;
            }
            catch ( \ReflectionException $e )
            {
                $usable = false;
            }
        }
        if ( !$usable )
            return false;

        $cacheDir = self::cacheDir();
        $exit = self::VELOCITY_EXIT;
        $decide = \Closure::bind( static function () use ( $file, $cacheDir, $exit ) {
            if ( self::$failedDir === null )
                self::$failedDir = $cacheDir;
            self::$ruleFile = $file;
            $settings = null;
            try
            {
                $settings = \CjwNetwork\RequestShield\Settings::loadFor( $file, $_SERVER, $cacheDir, array() );
                return self::run( $settings, null );
            }
            catch ( \Throwable $e )
            {
                if ( $e instanceof $exit )
                    throw $e;
                return self::failedOpen( $e, $settings );
            }
        }, null, $shield );
        $decide();
        return true;
    }

    /** The response headers so far, without the shield's diagnostics and cookies. */
    private static function ownHeaderCount()
    {
        $n = 0;
        foreach ( headers_list() as $h )
        {
            if ( stripos( $h, 'X-RS' ) !== 0 && stripos( $h, 'Set-Cookie:' ) !== 0 )
                $n++;
        }
        return $n;
    }

    /** The modes the admin may switch to (the library knows strict too; that stays a rule-file decision). */
    const SWITCH_MODES = array( 'enforce', 'monitor', 'off' );

    /**
     * Pauses the shield (creates var/request-shield/PAUSED) or switches it on
     * again (removes it). Every server sees it on its next request.
     *
     * @param bool $paused
     * @return array ok (bool), text (what was done or why not)
     */
    public static function setPaused( $paused )
    {
        $file = self::varDir() . '/' . self::PAUSE_FILE;
        if ( $paused )
        {
            if ( is_file( $file ) )
                return array( 'ok' => true, 'text' => 'The shield was already paused.' );
            if ( !is_dir( self::varDir() ) && !@mkdir( self::varDir(), 0750, true ) )
                return array( 'ok' => false, 'text' => 'The directory ' . self::varDir() . ' cannot be created by the web server.' );
            if ( @file_put_contents( $file, 'Paused from Setup > Request shield on ' . gmdate( 'c' ) . "\n" ) === false )
                return array( 'ok' => false, 'text' => 'The file ' . $file . ' cannot be written by the web server.' );
            return array( 'ok' => true, 'text' => 'The shield is paused: every request goes to the site unchecked.' );
        }
        if ( !is_file( $file ) )
            return array( 'ok' => true, 'text' => 'The shield was already on.' );
        if ( !@unlink( $file ) )
            return array( 'ok' => false, 'text' => 'The file ' . $file . ' cannot be removed by the web server.' );
        return array( 'ok' => true, 'text' => 'The shield is on again.' );
    }

    /**
     * Whether the admin can switch the mode, and which file it would change:
     * the main rule file when it is the site's own (or named by
     * EXP_REQUEST_SHIELD_RULES) and writable, or -- while the extension's
     * default is in use -- the site's file it would create from it.
     *
     * @return array can (bool), file, create (bool), why (when it cannot)
     */
    public static function modeTarget()
    {
        $r = self::ruleFileSource();
        if ( $r['source'] !== 'extension' )
        {
            $ok = is_file( $r['file'] ) && is_writable( $r['file'] ) && is_writable( dirname( $r['file'] ) );
            return array( 'can' => $ok, 'file' => $r['file'], 'create' => false,
                          'why' => $ok ? '' : 'The web server cannot write ' . $r['file'] . ' and its directory.' );
        }
        $site = self::siteRuleFile();
        $dir = dirname( $site );
        $ok = is_dir( $dir ) ? is_writable( $dir ) : is_writable( dirname( $dir ) );
        return array( 'can' => $ok, 'file' => $site, 'create' => true,
                      'why' => $ok ? '' : 'The web server cannot create ' . $site . '.' );
    }

    /**
     * The site's own main rule file made from the extension's default: the
     * same rules, now the site's to change; the store and the log stay in
     * var/request-shield/. Its includes name the exponential*.rules copied next
     * to it (an include may not leave its directory with "..").
     *
     * @return string
     */
    public static function siteRulesFromDefault()
    {
        $text = (string)file_get_contents( self::extensionDir() . '/rules/request-shield.rules' );
        $text = str_replace( array( 'set         store-dir ../../../var/', 'set         log ../../../var/' ),
                             array( 'set         store-dir ../../var/', 'set         log ../../var/' ), $text );
        return "# The site's main rule file, made by Setup > Request shield from the extension's default on " . gmdate( 'Y-m-d' ) . ".\n"
             . "# It is the site's own now, as are the exponential*.rules copied next to it: updates of the extension no longer\n"
             . "# change them (compare them with extension/exp_request_shield/rules/ after an update). Relative paths are\n"
             . "# relative to this file (settings/request-shield/).\n#\n" . $text;
    }

    /**
     * Switches the mode: rewrites the "set mode" line of the site's main rule
     * file (making that file from the extension's default first when the site
     * has none). The previous file is kept in var/request-shield/backup/; the
     * new one must compile, else the previous one is put back. Every server
     * reads the change within the rules' "recheck" seconds.
     *
     * @param string $mode enforce, monitor or off
     * @return array ok (bool), text, file, backup (path or null)
     */
    public static function setMode( $mode )
    {
        if ( !in_array( $mode, self::SWITCH_MODES, true ) )
            return array( 'ok' => false, 'text' => 'Unknown mode.', 'file' => null, 'backup' => null );
        if ( !self::loadLibrary() )
            return array( 'ok' => false, 'text' => 'The library is missing from lib/requestshield.', 'file' => null, 'backup' => null );
        $target = self::modeTarget();
        $file = $target['file'];
        if ( !$target['can'] )
            return array( 'ok' => false, 'text' => $target['why'], 'file' => $file, 'backup' => null );

        $old = $target['create'] ? null : (string)file_get_contents( $file );
        $text = $old === null ? self::siteRulesFromDefault() : $old;
        $line = sprintf( '%-12s%s', 'set', 'mode ' . $mode );
        $count = 0;
        $text = preg_replace( '/^set[ \t]+mode[ \t]+\S+/m', $line, $text, -1, $count );
        if ( $count === 0 )
            $text = rtrim( $text, "\n" ) . "\n\n# Set from Setup > Request shield.\n" . $line . "\n";
        if ( $old !== null && $text === $old )
            return array( 'ok' => true, 'text' => "The mode was already $mode.", 'file' => $file, 'backup' => null );

        $backup = null;
        if ( $old !== null )
        {
            $dir = self::varDir() . '/backup';
            if ( !is_dir( $dir ) )
                @mkdir( $dir, 0750, true );
            $backup = $dir . '/' . basename( $file ) . '.' . gmdate( 'Ymd-His' ) . '.' . substr( md5( $old ), 0, 6 );
            if ( @file_put_contents( $backup, $old ) === false )
                return array( 'ok' => false, 'text' => 'The previous rules cannot be kept in ' . $dir . ', so nothing was changed.', 'file' => $file, 'backup' => null );
        }
        else
        {
            if ( !is_dir( dirname( $file ) ) && !@mkdir( dirname( $file ), 0750, true ) )
                return array( 'ok' => false, 'text' => 'The directory ' . dirname( $file ) . ' cannot be created.', 'file' => $file, 'backup' => null );
            // the included rules next to the new file, never over a copy the site already has
            foreach ( array( 'exponential.rules', 'exponential-admin-uri.rules', 'exponential-admin-host.rules' ) as $name )
            {
                $to = dirname( $file ) . '/' . $name;
                if ( !is_file( $to ) && !@copy( self::extensionDir() . '/rules/' . $name, $to ) )
                    return array( 'ok' => false, 'text' => 'The rule file ' . $to . ' cannot be written.', 'file' => $file, 'backup' => null );
            }
        }

        if ( !self::writeAtomic( $file, $text ) )
            return array( 'ok' => false, 'text' => 'The rule file ' . $file . ' cannot be written.', 'file' => $file, 'backup' => $backup );
        clearstatcache( true, $file );
        if ( function_exists( 'opcache_invalidate' ) )
            @opcache_invalidate( $file, true );
        try
        {
            \CjwNetwork\RequestShield\Settings::load( $file, self::cacheDir() );
        }
        catch ( \Throwable $e )
        {
            if ( $old !== null )
                self::writeAtomic( $file, $old );
            else
                @unlink( $file );
            return array( 'ok' => false, 'text' => 'The changed rules do not compile, so the previous ones were put back: ' . $e->getMessage(), 'file' => $file, 'backup' => $backup );
        }
        $text = $old === null ? "The site's own rule file was made from the extension's default; the mode is $mode."
                              : "The mode is $mode.";
        return array( 'ok' => true, 'text' => $text, 'file' => $file, 'backup' => $backup );
    }

    /** Writes a file through a temporary file next to it and a rename, keeping the old file's mode. */
    private static function writeAtomic( $file, $text )
    {
        $tmp = $file . '.tmp-' . getmypid();
        if ( @file_put_contents( $tmp, $text ) !== strlen( $text ) )
        {
            @unlink( $tmp );
            return false;
        }
        @chmod( $tmp, is_file( $file ) ? ( fileperms( $file ) & 0777 ) : 0640 );
        if ( !@rename( $tmp, $file ) )
        {
            @unlink( $tmp );
            return false;
        }
        return true;
    }

    /**
     * The library's version and build, or null when it is not there.
     *
     * @return array|null version, build
     */
    public static function libraryVersion()
    {
        if ( !self::loadLibrary() )
            return null;
        $shield = 'CjwNetwork\\RequestShield\\Shield';
        return array(
            'version' => defined( $shield . '::VERSION' ) ? constant( $shield . '::VERSION' ) : '',
            'build' => ( defined( $shield . '::BUILD' ) ? constant( $shield . '::BUILD' ) : '' ) . ( PHP_VERSION_ID < 80000 ? ', PHP 7.4 build' : '' ),
        );
    }

    /**
     * Everything the dashboard shows about the setup. Compiles the rules the
     * way the shield does (into the same cache), so a mistake in a rule file
     * appears here as the error the shield would hit.
     *
     * @return array
     */
    public static function status()
    {
        $rule = self::ruleFileSource();
        $status = array(
            'extension_version' => self::VERSION,
            'library' => self::libraryVersion(),
            'rule_file' => $rule['file'],
            'rule_source' => $rule['source'],
            'rule_file_exists' => is_file( $rule['file'] ),
            'site_rule_file' => self::siteRuleFile(),
            'var_dir' => self::varDir(),
            'paused' => self::isPaused(),
            'hooked' => self::isHooked(),
            'error' => null,
            'mode' => null,
            'log_file' => null,
            'log_level' => null,
            'store' => null,
            'store_dir' => null,
            'dashboard_path' => null,
            'stats' => false,
            'debug_header' => false,
            'hosts' => array(),
            'tier' => null,
            'plugins' => array(),
        );
        if ( $status['library'] === null )
        {
            $status['error'] = 'The library is missing from lib/requestshield.';
            return $status;
        }
        if ( !$status['rule_file_exists'] )
        {
            $status['error'] = 'The main rule file does not exist.';
            return $status;
        }
        try
        {
            $s = \CjwNetwork\RequestShield\Settings::load( $rule['file'], self::cacheDir() );
        }
        catch ( \Throwable $e )
        {
            $status['error'] = $e->getMessage();
            return $status;
        }
        $status['mode'] = $s->mode;
        $status['log_file'] = $s->logFile;
        $status['log_level'] = $s->logLevel;
        $status['store'] = $s->store;
        $status['store_dir'] = $s->storeDir;
        $status['dashboard_path'] = $s->dashboardPath;
        $status['stats'] = !empty( $s->ext['stats'] );
        $status['debug_header'] = $s->debugHeader;
        $status['hosts'] = array_values( array_map( 'strval', $s->hosts ) );
        $status['plugins'] = array_values( array_map( 'strval', array_keys( $s->ext ) ) );
        try
        {
            $tier = \CjwNetwork\RequestShield\Tier::of( $s, self::cacheDir() );
            $status['tier'] = array( 'tier' => $tier['tier'], 'store' => $tier['store'], 'why' => $tier['why'], 'off' => $tier['off'] );
        }
        catch ( \Throwable $e )
        {
            $status['tier'] = null;
        }
        return $status;
    }

    /**
     * Whether config.php loads the hook: the shield ran for this request
     * (FPM), or Velocity was told to run it (EXP_REQUEST_PREPEND_FILE).
     */
    public static function isHooked()
    {
        if ( isset( $_SERVER['REQUEST_SHIELD'] ) )
            return true;
        return defined( 'EXP_REQUEST_PREPEND_FILE' ) && realpath( (string)constant( 'EXP_REQUEST_PREPEND_FILE' ) ) === realpath( self::extensionDir() . '/bootstrap/prepend.php' );
    }

    /**
     * What the log says for the last $hours hours: counts by action and by
     * rule, one bucket per hour for the chart, and the newest lines. Reads
     * only the end of the log and the rotated one (LogStats does the same).
     *
     * @param string|null $logFile
     * @param int $hours
     * @param int $recent how many of the newest lines
     * @param int|null $now
     * @return array
     */
    public static function activity( $logFile, $hours = 24, $recent = 50, $now = null )
    {
        $now = $now === null ? time() : (int)$now;
        $hours = max( 1, min( 168, (int)$hours ) );
        $since = $now - $hours * 3600;
        $start = $since - ( $since % 3600 ) + 3600;
        $buckets = array();
        for ( $t = $start - 3600; $t <= $now; $t += 3600 )
            $buckets[$t] = array( 'time' => $t, 'refused' => 0, 'checked' => 0, 'watched' => 0, 'other' => 0, 'total' => 0 );

        $out = array( 'hours' => $hours, 'since' => $since, 'lines' => 0, 'actions' => array(), 'groups' => array( 'refused' => 0, 'checked' => 0, 'watched' => 0, 'other' => 0 ),
                      'rules' => array(), 'recent' => array(), 'buckets' => array(), 'max' => 0, 'readable' => false );
        if ( !$logFile || !self::loadLibrary() )
        {
            $out['buckets'] = array_values( $buckets );
            return $out;
        }
        $out['readable'] = is_readable( $logFile );
        $lines = array();
        foreach ( explode( "\n", self::tail( $logFile . '.1', 4194304 ) . self::tail( $logFile, 4194304 ) ) as $line )
        {
            $e = \CjwNetwork\RequestShield\LogStats::parse( $line );
            if ( $e === null || $e['time'] < $since || $e['time'] > $now )
                continue;
            $group = self::group( $e['action'] );
            $out['actions'][$e['action']] = ( isset( $out['actions'][$e['action']] ) ? $out['actions'][$e['action']] : 0 ) + 1;
            $out['groups'][$group]++;
            if ( $e['rule'] !== null )
            {
                $r = isset( $out['rules'][$e['rule']] ) ? $out['rules'][$e['rule']] : array( 'rule' => $e['rule'], 'count' => 0, 'last' => 0 );
                $r['count']++;
                $r['last'] = max( $r['last'], $e['time'] );
                $out['rules'][$e['rule']] = $r;
            }
            $b = $e['time'] - ( $e['time'] % 3600 );
            if ( isset( $buckets[$b] ) )
            {
                $buckets[$b][$group]++;
                $buckets[$b]['total']++;
            }
            $lines[] = $e;
        }
        $out['lines'] = count( $lines );
        arsort( $out['actions'] );
        usort( $out['rules'], function ( $a, $b ) { return $b['count'] - $a['count']; } );
        $out['rules'] = array_slice( $out['rules'], 0, 25 );
        foreach ( array_reverse( array_slice( $lines, -max( 0, (int)$recent ) ) ) as $e )
        {
            unset( $e['claimed'] );
            $e['group'] = self::group( $e['action'] );
            $out['recent'][] = $e;
        }
        foreach ( $buckets as $bucket )
            $out['max'] = max( $out['max'], $bucket['total'] );
        $out['buckets'] = array_values( $buckets );
        return $out;
    }

    /**
     * The group of a log action, for the colours and the chart: refused
     * (reject, block, ban, slow), checked (the browser check), watched (any
     * monitor- action: what would have happened), other.
     *
     * @param string $action
     * @return string
     */
    public static function group( $action )
    {
        $action = (string)$action;
        if ( strpos( $action, 'monitor' ) === 0 )
            return 'watched';
        if ( strpos( $action, 'challenge' ) !== false || strpos( $action, 'check' ) !== false )
            return 'checked';
        if ( preg_match( '/reject|block|ban|deny|refuse|slow|throttle|limit/', $action ) )
            return 'refused';
        return 'other';
    }

    /** The end of a file, from its first whole line. */
    private static function tail( $file, $maxBytes )
    {
        $size = @filesize( $file );
        if ( !$size )
            return '';
        $h = @fopen( $file, 'rb' );
        if ( $h === false )
            return '';
        if ( $size > $maxBytes )
        {
            fseek( $h, -$maxBytes, SEEK_END );
            fgets( $h );
        }
        $text = stream_get_contents( $h );
        fclose( $h );
        return is_string( $text ) ? rtrim( $text, "\n" ) . "\n" : '';
    }

    /**
     * The main rule file and the files it includes that sit next to it, for
     * the rules page: name, path, size, lines and text.
     *
     * @return array
     */
    public static function ruleFiles()
    {
        $main = self::ruleFile();
        $files = array();
        $seen = array();
        $queue = array( $main );
        while ( $queue && count( $files ) < 20 )
        {
            $file = array_shift( $queue );
            $real = realpath( $file );
            if ( $real === false || isset( $seen[$real] ) || !is_readable( $real ) )
                continue;
            $seen[$real] = true;
            $text = (string)file_get_contents( $real );
            $files[] = array( 'name' => basename( $real ), 'path' => $real, 'main' => $file === $main, 'size' => strlen( $text ),
                              'lines' => substr_count( $text, "\n" ), 'text' => $text );
            if ( preg_match_all( '/^\s*include\s+([^\s#@][^\s#]*)/m', $text, $m ) )
            {
                foreach ( $m[1] as $inc )
                {
                    if ( strpos( $inc, '*' ) === false )
                        $queue[] = ( $inc[0] === '/' ? '' : dirname( $real ) . '/' ) . $inc;
                }
            }
        }
        return $files;
    }
}
