<?php
/**
 * The admin pages of Setup > Request shield follow the administration's styling rules (doc/bc/6.0/admin4-styling.md
 * of Exponential), checked on the files alone; no database:
 *
 * - no <style> block, no style="" attribute and no inline <script> in any template of the extension;
 * - the module stylesheet is listed in design.ini [StylesheetSettings] BackendCSSFileList, names only .a4-rs-*
 *   classes and takes colours, radii, spacing and fonts from the --a4-* tokens only, so every value has its dark mode;
 * - every admin design has its page frame (admin, admin4, adminui and the standard fallback);
 * - every string of the templates and views is in the eng-US, ger-DE and untranslated catalogues;
 * - the extension's own files say GPL-2.0-or-later, the bundled library keeps its MIT license.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version), see LICENSE
 * @package exp_request_shield
 */

class AdminStylingTest extends PHPUnit\Framework\TestCase
{
    const MODULE_CSS = 'design/standard/stylesheets/modules/requestshield.css';

    private static function ext()
    {
        return dirname( __DIR__, 2 );
    }

    private static function files( $dir, $suffix )
    {
        $out = array();
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ext() . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f )
        {
            if ( substr( $f->getPathname(), -strlen( $suffix ) ) === $suffix )
                $out[] = $f->getPathname();
        }
        sort( $out );
        return $out;
    }

    public function testNoInlineCssOrScriptInTheTemplates()
    {
        $templates = self::files( 'design', '.tpl' );
        $this->assertGreaterThanOrEqual( 12, count( $templates ) );
        foreach ( $templates as $file )
        {
            $text = file_get_contents( $file );
            $this->assertDoesNotMatchRegularExpression( '/<style\b/i', $text, "$file has a <style> block" );
            $this->assertDoesNotMatchRegularExpression( '/\sstyle\s*=/i', $text, "$file has a style attribute" );
            $this->assertDoesNotMatchRegularExpression( '/<script\b/i', $text, "$file has a script" );
        }
    }

    public function testEveryAdminDesignHasItsFrame()
    {
        foreach ( array( 'admin', 'admin4', 'adminui', 'standard' ) as $design )
        {
            foreach ( array( 'page_open.tpl', 'page_close.tpl' ) as $part )
                $this->assertFileExists( self::ext() . "/design/$design/templates/requestshield/parts/$part" );
        }
        foreach ( array( 'dashboard', 'log', 'rules' ) as $view )
            $this->assertFileExists( self::ext() . "/design/standard/templates/requestshield/$view.tpl" );
    }

    public function testTheModuleStylesheetUsesOnlyItsClassesAndTheTokens()
    {
        $this->assertStringContainsString( 'BackendCSSFileList[]=modules/requestshield.css', file_get_contents( self::ext() . '/settings/design.ini.append.php' ) );
        $css = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( self::ext() . '/' . self::MODULE_CSS ) );
        foreach ( preg_split( '/\}/', $css ) as $rule )
        {
            if ( trim( $rule ) === '' )
                continue;
            list( $selectors, $body ) = array_pad( explode( '{', $rule, 2 ), 2, '' );
            foreach ( explode( ',', $selectors ) as $selector )
                $this->assertMatchesRegularExpression( '/\.a4-rs-/', $selector, "selector without .a4-rs-: $selector" );
            $this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i', $body, "a colour that is not a token: $body" );
            foreach ( array( 'color', 'background-color', 'fill', 'border-radius', 'font-size', 'font-family', 'margin', 'padding', 'gap' ) as $property )
            {
                if ( preg_match_all( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)/', $body, $m ) )
                {
                    foreach ( $m[1] as $value )
                        $this->assertDoesNotMatchRegularExpression( '/\b\d+(\.\d+)?(px|rem|em)\b|#|rgb/', preg_replace( '/var\([^)]*\)/', '', $value ), "$property: $value is not a token" );
                }
            }
        }
    }

    public function testEveryStringIsTranslated()
    {
        $sources = array();
        foreach ( self::files( 'design', '.tpl' ) as $file )
        {
            preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'\\|i18n\\(\\s*'extension\\/exp_request_shield'/", file_get_contents( $file ), $m );
            foreach ( $m[1] as $s )
                $sources[str_replace( "\\'", "'", $s )] = true;
        }
        foreach ( array_merge( self::files( 'classes', '.php' ), self::files( 'modules', '.php' ) ) as $file )
        {
            preg_match_all( "/ezpI18n::tr\\(\\s*'extension\\/exp_request_shield'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents( $file ), $m );
            foreach ( $m[1] as $s )
                $sources[str_replace( "\\'", "'", $s )] = true;
        }
        $this->assertGreaterThanOrEqual( 60, count( $sources ) );
        foreach ( array( 'eng-US', 'ger-DE', 'untranslated' ) as $language )
        {
            $ts = file_get_contents( self::ext() . "/translations/$language/translation.ts" );
            foreach ( array_keys( $sources ) as $source )
                $this->assertStringContainsString( '<source>' . htmlspecialchars( $source, ENT_NOQUOTES ) . '</source>', $ts, "$language lacks: $source" );
        }
    }

    public function testTheLicensesAreSplitAsDocumented()
    {
        $ext = self::ext();
        $this->assertStringContainsString( '"license": "GPL-2.0-or-later"', file_get_contents( "$ext/composer.json" ) );
        $this->assertStringContainsString( 'GNU General Public License v2.0 (or any later version)', file_get_contents( "$ext/extension.xml" ) );
        $this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', file_get_contents( "$ext/LICENSE" ) );
        $this->assertStringStartsWith( 'MIT License', file_get_contents( "$ext/lib/requestshield/LICENSE" ) );
        foreach ( array_merge( self::files( 'classes', '.php' ), self::files( 'modules', '.php' ), self::files( 'bootstrap', '.php' ) ) as $file )
            $this->assertStringContainsString( '@license GNU General Public License v2.0 (or any later version)', file_get_contents( $file ), $file );
    }
}
