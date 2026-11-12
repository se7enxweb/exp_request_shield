<?php
/**
 * cjw-network/request-shield 0.4.0 -- cache: the HTTP cache plugin, loaded after request-shield.php.
 * One file, built by build/single-file.php (build: v0.4.0) from the repository's sources; do not edit.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see https://github.com/cjw-network/request-shield/blob/main/LICENSE
 *
 * Load it after request-shield.php; the cache is switched on in the rules (set http-cache on).
 */

declare(strict_types=1);

namespace {
    // Loaded already: nothing more. Without request-shield.php first, its classes could not be declared.
    if (class_exists('CjwNetwork\\RequestShield\\Cache\\Api\\Purge', false)) {
        return;
    }
    if (!class_exists('CjwNetwork\\RequestShield\\Shield', false)) {
        error_log('request-shield: ' . basename(__FILE__) . ' needs request-shield.php loaded first -- nothing loaded');
        return;
    }
}

// plugins/cache/src/Api/Purge.php
namespace CjwNetwork\RequestShield\Cache\Api {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Cache\MemoryCache;
use CjwNetwork\RequestShield\Cache\Tags;
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /cache/purge: everything, the addresses below a path, or the answers with a tag (a CMS after publishing). */
final class Purge implements ApiService
{
    public const PARAMS = ['path' => ['type' => 'string', 'about' => 'only the addresses below it: /news/ (every website); none: everything'],
        'tags' => ['type' => 'string', 'about' => 'only the answers with one of these tags: c52 l2 (as xkey names them); out of date at once, removed when they run out']];

    public const SCHEMA = ['type' => 'object', 'required' => ['removed'], 'properties' => ['removed' => ['type' => 'integer']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $tags = is_string($params['tags'] ?? null) ? Tags::split($params['tags']) : [];
        if ($tags !== []) {
            if (!(new Tags(CacheExtension::of($s)['dir'], Capability::apcu()))->purge($tags, microtime(true))) {
                throw new ApiProblem(500, 'Not purged', 'the purge could not be written');
            }
            return ['removed' => 0];
        }
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        if ($path !== '' && $path[0] !== '/') {
            throw new ApiProblem(400, 'Bad request', 'path starts with /: /news/');
        }
        $dir = CacheExtension::of($s)['dir'];
        $removed = (new FileCache($dir))->purge($path !== '' ? $path : null);
        if (!MemoryCache::forget($dir, Capability::apcu(), microtime(true))) {
            throw new ApiProblem(500, 'Not purged', 'the purge of the answers in memory could not be written');
        }
        return ['removed' => $removed];
    }
}
}
}

// plugins/cache/src/Api/Status.php
namespace CjwNetwork\RequestShield\Cache\Api {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /cache: on or off, how many answers, how many bytes. */
final class Status implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['enabled', 'entries', 'bytes', 'ttl'], 'properties' => [
        'enabled' => ['type' => 'boolean'], 'entries' => ['type' => 'integer'], 'bytes' => ['type' => 'integer'], 'ttl' => ['type' => 'integer'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $o = CacheExtension::of($s);
        return ['enabled' => $o['enabled'], 'ttl' => $o['ttl']] + (new FileCache($o['dir']))->stats();
    }
}
}
}

// plugins/cache/src/CacheExtension.php
namespace CjwNetwork\RequestShield\Cache {
use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The HTTP cache as a plugin (0031 G.2, RSF04-03): `set http-cache on` keeps
 * the public answers of the addresses a cache may keep (cache-path,
 * cache-query) and answers them before the application starts. Off by
 * default; a request pays nothing for it then -- not even the plugin's class.
 *
 *   set http-cache on
 *   set http-cache-hosts www.example.org example.org   the site's names, as sent (required: nothing is kept without)
 *   set http-cache-ttl 5m              when the answer says nothing (its s-maxage or max-age wins)
 *   set http-cache-cookies _ga* _pk_*  cookies that do not make a page someone's own (default: analytics, the pass)
 *   set http-cache-max-object 1M       the largest answer kept
 *   set http-cache-dir /var/cache/rs   where (default: <store-dir>/http-cache)
 *   set http-cache-purgers 127.0.0.1 ::1   who may purge with a request (PURGE, PURGEKEYS; default: nobody)
 *   set http-cache-purge-token …       or anyone who sends it as X-Invalidate-Token (16 characters or more)
 *   set http-cache-tag-headers X-My-Tags   a header with tags besides the known ones (TAG_HEADERS)
 *   set http-cache-session-cookie eZSESSID*   the session cookies a page per role is kept for
 *   set http-cache-user-context on     the role from FOSHttpCache's user hash (/_fos_user_context_hash), or a URL to ask
 *   set http-cache-user-hash-header X-User-Hash   its header (default X-User-Context-Hash; Exponential Platform: X-User-Hash)
 */
final class CacheExtension implements Extension, ApiProvider
{
    /** Cookies that do not make a page someone's own: analytics, and the shield's pass. */
    public const COOKIES = ['_ga', '_ga_*', '_gid', '_gat*', '_pk_*', '_fbp', 'rsp'];

    /**
     * The headers an answer's tags come in (0039): Varnish's xkey (Ibexa,
     * Exponential Platform), FOSHttpCache's X-Cache-Tags, LiteSpeed's, the
     * CDNs', Magento's. Read, and taken out of the answer: they name content.
     */
    public const TAG_HEADERS = ['xkey', 'x-cache-tags', 'x-litespeed-tag', 'surrogate-key', 'cache-tag', 'edge-cache-tag', 'x-magento-tags'];

    /**
     * Who may purge with a request when nothing is set: nobody. Not this
     * machine, as Symfony's AppCache has it: behind a local proxy that adds
     * no forwarding header (nginx's proxy_pass by default) every visitor
     * comes from 127.0.0.1, and anyone could empty the cache.
     */
    public const PURGERS = [];

    public static function id(): string
    {
        return 'cache';
    }

    /** @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int, page: string} */
    public static function of(Settings $s): array
    {
        $o = $s->ext['cache'] ?? null;
        /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int, page: string} $o */
        $o = is_array($o) && isset($o['dir']) ? $o : ['enabled' => false, 'ttl' => 300, 'cookies' => self::COOKIES, 'maxObject' => 1048576, 'dir' => '', 'hosts' => [],
            'purgers' => self::PURGERS, 'token' => '', 'tagHeaders' => self::TAG_HEADERS, 'sessionCookies' => [], 'contextTtl' => 600, 'userContext' => '', 'hashHeader' => 'x-user-context-hash',
            'memoryObject' => 262144, 'memory' => 33554432, 'disk' => 268435456, 'page' => '/rs/cache'];
        // The folder: the one set, else below the store directory -- resolved here, so the compiled
        // settings do not depend on where they were compiled.
        $o['dir'] = $o['dir'] !== '' ? $o['dir'] : $s->storeDir . '/http-cache';
        return $o;
    }

    public static function vocabulary(Vocabulary $v): void
    {
        $v->set('http-cache', 'bool', 'the HTTP cache: on or off (default)', null, 'enabled');
        $v->set('http-cache-ttl', 'seconds', 'how long an answer is kept when it says nothing itself (default 5m)', null, 'ttl');
        $v->set('http-cache-cookies', 'words', 'cookies that do not make a page someone\'s own (default: analytics, the pass)', null, 'cookies');
        $v->set('http-cache-max-object', 'words', 'the largest answer kept: 1M, 500K (default 1M)', self::size('http-cache-max-object'), 'maxObject');
        $v->set('http-cache-memory-object', 'words', 'the largest answer kept in memory, with APCu (default 256K; 0: none in memory)', self::size('http-cache-memory-object'), 'memoryObject');
        $v->set('http-cache-memory', 'words', 'the most of APCu the cache\'s answers may take (default 32M)', self::size('http-cache-memory'), 'memory');
        $v->set('http-cache-disk', 'words', 'the most the cache\'s folder may hold -- above it the oldest answers go (default 256M, at least 1M; 0: no cap)', self::size('http-cache-disk'), 'disk');
        $v->set('http-cache-hosts', 'words', 'the site\'s host names as visitors send them, a port written out (www.example.org example.org:8080) -- only these are kept', null, 'hosts');
        $v->set('http-cache-dir', 'path', 'where the answers are kept (default <store-dir>/http-cache)', null, 'dir');
        $v->set('http-cache-purgers', 'words', 'the addresses that may purge with a request -- PURGE, PURGEKEYS (default: nobody; 127.0.0.1 ::1 for a CMS on this machine, when no proxy runs on it)', null, 'purgers');
        $v->set('http-cache-purge-token', 'string', 'or anyone who sends this as X-Invalidate-Token (16 characters or more; never shown)', null, 'token');
        $v->set('http-cache-session-cookie', 'words', 'the session cookies a page per role is kept for (wordpress_logged_in_* eZSESSID* PHPSESSID); the application names the role with Shield::active()?->cacheContext() (default: none, no pages per role)', null, 'sessionCookies');
        $v->set('http-cache-context-ttl', 'seconds', 'how long a session\'s role is remembered after the application last named it (default 10m)', null, 'contextTtl');
        $v->set('http-cache-user-context', 'string', 'the role of a visitor with a session from FOSHttpCache\'s user hash: on (asks the site itself, /_fos_user_context_hash) or the address to ask (http://127.0.0.1:8080); off by default', static function ($value, string $at): string {
            $s = is_scalar($value) ? trim((string) $value) : '';
            if (in_array(strtolower($s), ['off', 'on'], true)) {
                return strtolower($s) === 'off' ? '' : 'on';
            }
            if (preg_match('~^(https?://[a-z0-9.:\[\]-]+)(/[^\s?#]*)?$~i', $s, $m) === 1) {
                return strtolower($m[1]) . rtrim($m[2] ?? '', '/');      // the scheme and host in small letters, the path as given
            }
            throw new RuleFileException("$at: http-cache-user-context is on, off or the address to ask (http://127.0.0.1:8080), not \"$s\"");
        }, 'userContext');
        $v->set('http-cache-user-hash-header', 'string', 'the header of the user hash: X-User-Context-Hash (default, Ibexa) or X-User-Hash (Exponential Platform)', static function ($value, string $at): string {
            $s = is_scalar($value) ? strtolower(trim((string) $value)) : '';
            if ($s !== 'x-user-context-hash' && $s !== 'x-user-hash') {
                throw new RuleFileException("$at: http-cache-user-hash-header is X-User-Context-Hash or X-User-Hash, not \"$s\"");
            }
            return $s;
        }, 'hashHeader');
        $v->set('http-cache-tag-headers', 'words', 'a header with an answer\'s tags besides the known ones (xkey, X-Cache-Tags, X-LiteSpeed-Tag, Surrogate-Key, Cache-Tag, Edge-Cache-Tag, X-Magento-Tags)', null, 'tagHeaders');
    }

    /** A size in a rule file: 1M, 500K, 2G, a number of bytes. */
    private static function size(string $name): \Closure
    {
        return static function ($value, string $at) use ($name): int {
            $first = is_array($value) ? ($value[0] ?? '') : '';
            $s = is_scalar($first) ? (string) $first : '';
            if (preg_match('/^(\d{1,9})([kmg])?$/i', $s, $m) !== 1) {     // at most 999999999G: an integer
                throw new RuleFileException("$at: $name is a size (1M, 500K), not \"$s\"");
            }
            return (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2] ?? '')];
        };
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int, page: string}
     */
    public static function compile(array $raw, Settings $base): array
    {
        $enabled = $raw['enabled'] ?? false;
        $ttl = $raw['ttl'] ?? 300;
        $cookies = $raw['cookies'] ?? self::COOKIES;
        $max = $raw['maxObject'] ?? 1048576;
        $dir = $raw['dir'] ?? '';
        $hosts = $raw['hosts'] ?? [];
        $purgers = $raw['purgers'] ?? self::PURGERS;
        $token = $raw['token'] ?? '';
        $tagHeaders = $raw['tagHeaders'] ?? [];
        $sessionCookies = $raw['sessionCookies'] ?? [];
        $contextTtl = $raw['contextTtl'] ?? 600;
        $userContext = $raw['userContext'] ?? '';
        $hashHeader = $raw['hashHeader'] ?? 'x-user-context-hash';
        $memoryObject = $raw['memoryObject'] ?? 262144;
        $memory = $raw['memory'] ?? 33554432;
        $disk = $raw['disk'] ?? 268435456;
        if (!is_int($memoryObject) || $memoryObject < 0 || !is_int($memory) || $memory < 0 || !is_int($disk) || $disk < 0 || ($disk > 0 && $disk < 1048576)) {
            throw Settings::wrong('ext.cache.memory', 'memoryObject and memory sizes in bytes (0 or more), disk 0 (no cap) or 1M or more');
        }
        if (!is_string($userContext) || ($userContext !== '' && $userContext !== 'on' && preg_match('~^https?://[a-z0-9.:\[\]-]+(/[^\s?#]*)?$~D', $userContext) !== 1)
            || !in_array($hashHeader, ['x-user-context-hash', 'x-user-hash'], true)) {
            throw Settings::wrong('ext.cache.userContext', 'userContext "", "on" or an http(s) address; hashHeader x-user-context-hash or x-user-hash');
        }
        if (!is_bool($enabled) || !is_int($ttl) || $ttl < 0 || !is_int($max) || $max < 1 || !is_string($dir) || !is_array($cookies) || !is_array($hosts)
            || !is_array($purgers) || !is_string($token) || !is_array($tagHeaders) || !is_array($sessionCookies) || !is_int($contextTtl) || $contextTtl < 1) {
            throw Settings::wrong('ext.cache', 'enabled on/off, ttl seconds, maxObject bytes, dir a folder, cookies names, hosts names, purgers addresses, token a word, tagHeaders names, sessionCookies names, contextTtl seconds');
        }
        $sessions = [];
        foreach ($sessionCookies as $c) {
            if (!is_string($c) || preg_match('/^[A-Za-z0-9_.*-]{1,128}$/', $c) !== 1) {
                throw Settings::wrong('ext.cache.sessionCookies', 'cookie names, * for any characters (wordpress_logged_in_* eZSESSID*)');
            }
            $sessions[] = $c;
        }
        $ranges = [];
        foreach ($purgers as $p) {
            if (!is_string($p) || !self::range($p)) {
                throw Settings::wrong('ext.cache.purgers', 'addresses or ranges (127.0.0.1 ::1 10.0.0.0/8)');
            }
            $ranges[] = $p;
        }
        if ($token !== '' && preg_match('/^[\x21-\x7e]{16,}$/', $token) !== 1) {
            throw Settings::wrong('ext.cache.token', 'a word of 16 characters or more');   // never the value: it is a credential
        }
        $headers = self::TAG_HEADERS;
        foreach ($tagHeaders as $h) {
            if (!is_string($h) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $h) !== 1) {
                throw Settings::wrong('ext.cache.tagHeaders', 'header names (X-My-Tags)');
            }
            $headers[] = strtolower($h);
        }
        $names2 = [];
        foreach ($hosts as $h) {
            if (!is_string($h) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,252})(:\d{1,5})?$/', strtolower($h)) !== 1) {
                throw Settings::wrong('ext.cache.hosts', 'host names (www.example.org), a port written out (example.org:8080)');
            }
            $names2[] = strtolower($h);
        }
        $names = [];
        foreach ($cookies as $c) {
            if (!is_string($c) || preg_match('/^[A-Za-z0-9_.*-]{1,64}$/', $c) !== 1) {
                throw Settings::wrong('ext.cache.cookies', 'cookie names, * as a wildcard (_ga*)');
            }
            $names[] = $c;
        }
        return ['enabled' => $enabled, 'ttl' => $ttl, 'cookies' => $names, 'maxObject' => $max, 'dir' => $dir, 'hosts' => $names2,
            'purgers' => $ranges, 'token' => $token, 'tagHeaders' => array_values(array_unique($headers)), 'sessionCookies' => $sessions, 'contextTtl' => $contextTtl,
            'userContext' => $userContext, 'hashHeader' => $hashHeader, 'memoryObject' => min($memoryObject, $max), 'memory' => $memory, 'disk' => $disk,
            'page' => $base->dashboardPath . '/cache'];
    }

    /** An address, or a range of them (10.0.0.0/8). */
    private static function range(string $p): bool
    {
        $parts = explode('/', $p, 2);
        $bin = @inet_pton($parts[0]);
        return $bin !== false && (!isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= strlen($bin) * 8));
    }

    public static function plugins(array $compiled): array
    {
        return ($compiled['enabled'] ?? false) === true ? [CachePlugin::class] : [];
    }

    /** The cache's page in the dashboard (0047's area "Cache"): only when the cache is on. */
    public static function routes(array $compiled): array
    {
        if (($compiled['enabled'] ?? false) !== true || !is_string($compiled['page'] ?? null)) {
            return [];
        }
        return [$compiled['page'] => ['key' => 'cache', 'tab' => ['HTTP cache', 'HTTP-Cache'], 'role' => 'admin', 'order' => 80, 'page' => CachePage::class]];
    }

    public static function commands(): array
    {
        return ['cache' => Cli\CacheCommand::class];
    }

    /** What makes a cache keep what it should not. */
    public static function check(Settings $s): array
    {
        if (!self::of($s)['enabled']) {
            return [];
        }
        $out = [];
        if (self::of($s)['hosts'] === []) {
            $out[] = 'http-cache on, but no http-cache-hosts: nothing is kept -- name the site\'s host names as visitors send them (http-cache-hosts www.example.org example.org)';
        }
        if (self::of($s)['userContext'] !== '' && self::of($s)['sessionCookies'] === []) {
            $out[] = 'http-cache-user-context on, but no http-cache-session-cookie: no visitor has a session to ask the role for -- name the session cookie (http-cache-session-cookie eZSESSID*)';
        }
        if (self::of($s)['userContext'] !== '' && \CjwNetwork\RequestShield\Http::offline() !== null) {
            $out[] = 'http-cache-user-context on, but this PHP cannot ask anything (allow_url_fopen off, no curl): no role is found';
        }
        if ($s->cacheableQuery === null) {
            $out[] = 'http-cache on, and every query parameter is cacheable (no cache-query): made-up parameters fill the cache -- name the ones the site uses (cache-query page sort)';
        }
        return $out;
    }

    /** The cache in the API (RSF06-05): what it holds, and emptying it. */
    public static function api(): array
    {
        return [
            new Endpoint('GET', '/cache', 'admin', false, Api\Status::class, Api\Status::SCHEMA, 'RSF04-03', 'The HTTP cache: on or off, how many answers it holds, how many bytes.'),
            new Endpoint('POST', '/cache/purge', 'admin', true, Api\Purge::class, Api\Purge::SCHEMA, 'RSF04-03',
                'Empties the HTTP cache, or the addresses below a path -- after a page changed.', Api\Purge::PARAMS),
        ];
    }
}
}
}

// plugins/cache/src/CachePage.php
namespace CjwNetwork\RequestShield\Cache {
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\RoutePage;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

if (true) {
/**
 * The HTTP cache's page in the dashboard (proposal 0047's area "Cache";
 * RSF04-03): what it holds against its caps -- the answers in memory
 * (APCu, an upper bound: MemoryCache's hour counters) and on the disk -- its
 * settings, the way to the response times by hit and miss (the statistics),
 * and purges: by tag, below a path, everything. A purge is a POST with the
 * page's token (an HMAC of the viewer's address and the hour, as the lists'
 * page has it); only the administrator sees the page.
 */
final class CachePage implements RoutePage
{
    private const T = [
        'en' => ['title' => 'HTTP cache', 'intro' => 'The pages the shield keeps and gives out before the application starts -- what they take against the caps, and purging them.',
            'off' => 'off', 'on' => 'on', 'settings' => 'Settings', 'kept' => 'kept for %s unless the answer says otherwise', 'hosts' => 'for %s',
            'memory' => 'Memory (APCu)', 'memNone' => 'No answers in memory: %s.', 'memNoApcu' => 'APCu is not here', 'memOff' => 'http-cache-memory-object 0',
            'memUsed' => 'at most %s of %s (http-cache-memory) -- answers up to %s each; APCu has %s of %s free',
            'disk' => 'Disk', 'diskUsed' => '%s answers, %s of %s (http-cache-disk)', 'diskNoCap' => '%s answers, %s -- no cap (http-cache-disk 0)',
            'daily' => 'Per day', 'dailyOff' => 'With the statistics and their part times (set stats requests pages times) a bar per day shows hits and misses here.',
            'k.hit' => 'from the cache', 'k.miss' => 'asked the site, kept', 'k.nostore' => 'asked the site, not to be kept', 'k.past' => 'not for the cache',
            'rate' => '%s %% hits', 'asTable' => 'As a table', 'day' => 'Day', 'none' => 'Nothing counted in these days yet.',
            'times' => 'Hits and misses', 'timesLink' => 'The response times by hit and miss are in the statistics', 'timesOff' => 'With the statistics and their part times (set stats requests pages times) the dashboard shows the response times by hit and miss.',
            'purge' => 'Purge', 'tags' => 'Tags', 'tagsHint' => 'c52 l2 article-3 -- what the pages carry (xkey, X-Cache-Tags …)', 'path' => 'Below a path', 'pathHint' => '/news/ -- every host',
            'all' => 'Everything', 'purgeTags' => 'Purge the tags', 'purgePath' => 'Remove below the path', 'purgeAll' => 'Empty the cache',
            'doneTags' => 'Purged: %s -- their pages are made again on the next request.', 'donePath' => 'Removed %s answers below %s.', 'doneAll' => 'Emptied: %s answers removed, every page in memory out of date.',
            'bad' => 'The form is out of date (or came from elsewhere) -- nothing purged. Load the page and try again.', 'noTags' => 'No tag to purge.', 'noPath' => 'A path starts with /.',
            'failed' => 'The purge could not be written (the cache\'s folder) -- nothing changed.'],
        'de' => ['title' => 'HTTP-Cache', 'intro' => 'Die Seiten, die der Shield hält und ausgibt, bevor die Anwendung startet -- was sie gegen die Grenzen belegen, und wie man sie verwirft.',
            'off' => 'aus', 'on' => 'an', 'settings' => 'Einstellungen', 'kept' => 'gehalten für %s, wenn die Antwort nichts anderes sagt', 'hosts' => 'für %s',
            'memory' => 'Speicher (APCu)', 'memNone' => 'Keine Antworten im Speicher: %s.', 'memNoApcu' => 'APCu ist nicht da', 'memOff' => 'http-cache-memory-object 0',
            'memUsed' => 'höchstens %s von %s (http-cache-memory) -- Antworten bis %s; APCu hat %s von %s frei',
            'disk' => 'Platte', 'diskUsed' => '%s Antworten, %s von %s (http-cache-disk)', 'diskNoCap' => '%s Antworten, %s -- ohne Grenze (http-cache-disk 0)',
            'daily' => 'Pro Tag', 'dailyOff' => 'Mit der Statistik und ihrem Teil times (set stats requests pages times) zeigt hier ein Balken pro Tag Treffer und Miss.',
            'k.hit' => 'aus dem Cache', 'k.miss' => 'Website gefragt, gespeichert', 'k.nostore' => 'Website gefragt, nicht speicherbar', 'k.past' => 'nicht für den Cache',
            'rate' => '%s %% Treffer', 'asTable' => 'Als Tabelle', 'day' => 'Tag', 'none' => 'In diesen Tagen ist noch nichts gezählt.',
            'times' => 'Treffer und Miss', 'timesLink' => 'Die Antwortzeiten nach Treffer und Miss stehen in der Statistik', 'timesOff' => 'Mit der Statistik und ihrem Teil times (set stats requests pages times) zeigt das Dashboard die Antwortzeiten nach Treffer und Miss.',
            'purge' => 'Verwerfen', 'tags' => 'Tags', 'tagsHint' => 'c52 l2 article-3 -- was die Seiten tragen (xkey, X-Cache-Tags …)', 'path' => 'Unter einem Pfad', 'pathHint' => '/news/ -- jeder Host',
            'all' => 'Alles', 'purgeTags' => 'Tags verwerfen', 'purgePath' => 'Unter dem Pfad entfernen', 'purgeAll' => 'Cache leeren',
            'doneTags' => 'Verworfen: %s -- ihre Seiten werden beim nächsten Aufruf neu gebaut.', 'donePath' => '%s Antworten unter %s entfernt.', 'doneAll' => 'Geleert: %s Antworten entfernt, jede Seite im Speicher veraltet.',
            'bad' => 'Das Formular ist veraltet (oder kam von woanders) -- nichts verworfen. Seite neu laden und noch einmal.', 'noTags' => 'Kein Tag zum Verwerfen.', 'noPath' => 'Ein Pfad beginnt mit /.',
            'failed' => 'Das Verwerfen konnte nicht geschrieben werden (der Ordner des Caches) -- nichts geändert.'],
    ];

    public static function serve(Settings $s, Request $request, array $route, array $ctx): Response
    {
        $links = $ctx['links'];
        $lang = Texts::language($ctx['lang'], $ctx['accept']) === 'de' ? 'de' : 'en';
        $message = $ctx['method'] === 'POST' ? self::handle($s, $ctx['post'], $ctx['ip'], $lang) : null;
        return Response::html(200, self::render($s, ['action' => $links['cache'] ?? $ctx['prefix'] . $route['path'], 'message' => $message, 'links' => $links, 'lang' => $lang,
            'ip' => $ctx['ip'], 'home' => $ctx['home'], 'homeLabel' => $ctx['homeLabel'], 'title' => self::T[$lang]['title'] . ' — ' . $ctx['homeLabel']]));
    }

    /** The form's token: an HMAC of the viewer's address and the hour (the last hour's still counts). */
    public static function token(Settings $s, string $ip, ?int $now = null): string
    {
        return self::sign($s, $ip, intdiv($now ?? time(), 3600));
    }

    private static function sign(Settings $s, string $ip, int $hour): string
    {
        return substr(hash_hmac('sha256', "cache|$ip|$hour", Secret::resolve($s->challenge->secret, $s->storeDir)), 0, 32);
    }

    /**
     * A purge the page's forms sent: do=tags (tags), do=path (path), do=all.
     *
     * @param array<mixed> $post
     * @return array{ok: bool, message: string}
     */
    public static function handle(Settings $s, array $post, string $ip, string $lang, ?int $now = null): array
    {
        $t = self::T[$lang];
        $hour = intdiv($now ?? time(), 3600);
        $token = is_string($post['token'] ?? null) ? $post['token'] : '';
        if ($token === '' || !(hash_equals(self::sign($s, $ip, $hour), $token) || hash_equals(self::sign($s, $ip, $hour - 1), $token))) {
            return ['ok' => false, 'message' => $t['bad']];
        }
        $o = CacheExtension::of($s);
        $nowF = microtime(true);
        $do = is_string($post['do'] ?? null) ? $post['do'] : '';
        if ($do === 'tags') {
            $tags = Tags::split(is_string($post['tags'] ?? null) ? $post['tags'] : '');
            if ($tags === []) {
                return ['ok' => false, 'message' => $t['noTags']];
            }
            return (new Tags($o['dir'], Capability::apcu()))->purge($tags, $nowF)
                ? ['ok' => true, 'message' => sprintf($t['doneTags'], implode(' ', $tags))] : ['ok' => false, 'message' => $t['failed']];
        }
        if ($do === 'path') {
            $path = trim(is_string($post['path'] ?? null) ? $post['path'] : '');
            if ($path === '' || $path[0] !== '/') {
                return ['ok' => false, 'message' => $t['noPath']];
            }
            $removed = (new FileCache($o['dir']))->purge($path);
            return MemoryCache::forget($o['dir'], Capability::apcu(), $nowF)
                ? ['ok' => true, 'message' => sprintf($t['donePath'], (string) $removed, $path)] : ['ok' => false, 'message' => $t['failed']];
        }
        if ($do === 'all') {
            $removed = (new FileCache($o['dir']))->purge();
            return MemoryCache::forget($o['dir'], Capability::apcu(), $nowF)
                ? ['ok' => true, 'message' => sprintf($t['doneAll'], (string) $removed)] : ['ok' => false, 'message' => $t['failed']];
        }
        return ['ok' => false, 'message' => $t['bad']];
    }

    /**
     * The page.
     *
     * @param array<string, mixed> $o action, message (handle()'s answer), links, lang, ip, home, homeLabel, title, now
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mb = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, $lang === 'de' ? ',' : '.', '') . ' MB' : number_format($b / 1024, 1, $lang === 'de' ? ',' : '.', '') . ' KB';
        $c = CacheExtension::of($s);
        $action = is_string($o['action'] ?? null) ? $o['action'] : '';
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $token = self::token($s, is_string($o['ip'] ?? null) ? $o['ip'] : '', is_int($o['now'] ?? null) ? $o['now'] : null);
        $form = static fn (string $do, string $fields, string $button, string $class = ''): string => '<form method="post" action="' . $e($action) . '" class="purge">'
            . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="do" value="' . $do . '">' . $fields
            . '<button' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $e($button) . '</button></form>';
        $o['help'] = Help::link('RSF04-03', '', $s->docsUrl, $lang);

        $h = Frame::tabs($s, $links, 'cache', $lang) . '<p class="note">' . $e($t['intro']) . '</p>';
        $msg = is_array($o['message'] ?? null) ? $o['message'] : null;
        if ($msg !== null && is_string($msg['message'] ?? null)) {
            $h .= '<div class="msg ' . (($msg['ok'] ?? false) ? 'ok' : 'bad') . '" role="status">' . $e($msg['message']) . '</div>';
        }
        // What it holds against its caps.
        $h .= '<div class="grid">';
        $h .= '<div class="card">' . Frame::h2($t['memory'], $s, 'RSF04-03', 'memory-first-the-disk-when-needed', $lang);
        if (!Capability::apcu() || $c['memoryObject'] <= 0 || $c['memory'] <= 0) {
            $h .= '<p class="note">' . $e(sprintf($t['memNone'], !Capability::apcu() ? $t['memNoApcu'] : $t['memOff'])) . '</p>';
        } else {
            $used = (new MemoryCache($c['dir'], $c['memoryObject'], $c['memory']))->bytes(microtime(true));
            $sma = apcu_sma_info(true);
            $num = static fn (string $k): int => is_array($sma) && (is_int($sma[$k] ?? null) || is_float($sma[$k] ?? null)) ? (int) $sma[$k] : 0;
            $total = $num('num_seg') * $num('seg_size');
            $h .= self::bar($used, $c['memory']) . '<p>' . $e(sprintf($t['memUsed'], $mb($used), $mb($c['memory']), $mb($c['memoryObject']), $mb($num('avail_mem')), $mb($total))) . '</p>';
        }
        $h .= '</div><div class="card">' . Frame::h2($t['disk'], $s, 'RSF04-03', 'memory-first-the-disk-when-needed', $lang);
        $st = (new FileCache($c['dir']))->stats();
        $h .= ($c['disk'] > 0 ? self::bar($st['bytes'], $c['disk']) . '<p>' . $e(sprintf($t['diskUsed'], (string) $st['entries'], $mb($st['bytes']), $mb($c['disk']))) . '</p>'
            : '<p>' . $e(sprintf($t['diskNoCap'], (string) $st['entries'], $mb($st['bytes']))) . '</p>') . '</div></div>';
        // Per day: what the cache did, from the statistics' counts (0046's times).
        $h .= '<div class="card">' . Frame::h2($t['daily'], $s, 'RSF06-03', 'how-fast-the-site-answered', $lang);
        $daily = self::daily($s, 14, is_int($o['now'] ?? null) ? $o['now'] : null);
        $h .= $daily === null ? '<p class="note">' . $e($t['dailyOff']) . '</p>' : self::chart($daily, $t, $lang) . '</div>';
        if ($daily === null) {
            $h .= '</div>';
        }
        // The times: the statistics' (0046).
        $h .= '<div class="card">' . Frame::h2($t['times'], $s, 'RSF06-03', 'how-fast-the-site-answered', $lang)
            . (isset($links['all']) ? '<p><a href="' . $e($links['all'] . '?lang=' . $lang) . '">' . $e($t['timesLink']) . ' →</a></p>' : '<p class="note">' . $e($t['timesOff']) . '</p>') . '</div>';
        // Purges.
        $h .= '<div class="card">' . Frame::h2($t['purge'], $s, 'RSF04-03', 'tags-and-purges-what-the-cms-already-sends', $lang)
            . $form('tags', '<label>' . $e($t['tags']) . ' <input name="tags" size="30" placeholder="' . $e($t['tagsHint']) . '" aria-label="' . $e($t['tags']) . '"></label> ', $t['purgeTags'])
            . $form('path', '<label>' . $e($t['path']) . ' <input name="path" size="30" placeholder="' . $e($t['pathHint']) . '" aria-label="' . $e($t['path']) . '"></label> ', $t['purgePath'])
            . $form('all', '<span>' . $e($t['all']) . '</span> ', $t['purgeAll'], 'danger') . '</div>';
        // The settings it runs with.
        $h .= '<div class="card">' . Frame::h2($t['settings'], $s, 'RSF04-03', 'configuration', $lang) . '<p><span class="badge">' . $e($c['enabled'] ? $t['on'] : $t['off']) . '</span> '
            . $e(sprintf($t['kept'], $c['ttl'] . ' s')) . ($c['hosts'] !== [] ? ' · ' . $e(sprintf($t['hosts'], implode(' ', $c['hosts']))) : '') . '</p></div>';
        $title = is_string($o['title'] ?? null) ? $o['title'] : $t['title'];
        return Frame::page($title, $lang, $h, $o, self::CSS);
    }

    /** The cache kinds, bottom to top of a day's bar (the statistics' words). */
    public const KINDS = ['hit', 'miss', 'nostore', 'past'];

    /**
     * What the cache did per day over the last $days days -- the statistics'
     * counts of answers by cache kind (rt: … with the part times), the
     * running hour included; null without the statistics or their times.
     *
     * @return array<string, array{hit: int, miss: int, nostore: int, past: int}>|null day (yyyymmdd) => counts
     */
    public static function daily(Settings $s, int $days = 14, ?int $now = null): ?array
    {
        if (!class_exists(\CjwNetwork\RequestShield\Stats\Report\StatsReport::class) || !class_exists(\CjwNetwork\RequestShield\Stats\StatsExtension::class)) {
            return null;
        }
        $st = \CjwNetwork\RequestShield\Stats\StatsExtension::of($s);
        if (!$st['enabled'] || !in_array('times', $st['parts'], true)) {
            return null;
        }
        $now ??= time();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[gmdate('Ymd', $now - $i * 86400)] = ['hit' => 0, 'miss' => 0, 'nostore' => 0, 'past' => 0];
        }
        $read = \CjwNetwork\RequestShield\Stats\Report\StatsReport::read($s, null, (string) array_key_first($out), gmdate('Ymd', $now));
        foreach ($read['days'] as $day => $counts) {
            if (!isset($out[$day])) {
                continue;
            }
            foreach ($counts as $key => $n) {
                if (strncmp((string) $key, 'rt:', 3) === 0) {
                    $kind = explode('|', (string) $key)[1] ?? '';
                    if (isset($out[$day][$kind])) {
                        $out[$day][$kind] += $n;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The days as stacked bars (one per day, the kinds bottom to top in a
     * fixed order, 2 px apart), each with its share of hits above it, a
     * legend, a tooltip on every part and the numbers as a table.
     *
     * @param array<string, array{hit: int, miss: int, nostore: int, past: int}> $daily
     * @param array<string, string> $t
     */
    private static function chart(array $daily, array $t, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => number_format($v, 0, ',', $lang === 'de' ? '.' : ',');
        $totals = array_map('array_sum', $daily);
        if ($totals === [] || array_sum($totals) === 0) {
            return '<p class="note">' . $e($t['none']) . '</p>';
        }
        $max = max(1, ...array_values($totals));
        $w = 700;
        $top = 22;
        $plot = 150;
        $slot = $w / count($daily);
        $bar = min(34.0, $slot * 0.62);
        $svg = '';
        $i = 0;
        foreach ($daily as $day => $c) {
            $x = $i * $slot + ($slot - $bar) / 2;
            $y = $top + $plot;
            $label = gmdate($lang === 'de' ? 'd.m.' : 'M j', (int) strtotime($day . ' UTC'));      // the days are UTC days
            // Each part at least 1.5 px, so the room for that comes off the scale: the bar stays within the plot.
            $parts = count(array_filter($c, static fn (int $v): bool => $v > 0));
            $scale = ($plot - 1.5 * $parts) / $max;
            foreach (self::KINDS as $k) {
                if ($c[$k] <= 0) {
                    continue;
                }
                $height = 1.5 + $scale * $c[$k];
                $y -= $height;
                $svg .= '<rect class="k-' . $k . '" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar, 1) . '" height="' . round(max(0.5, $height - 2), 1) . '" rx="3">'
                    . '<title>' . $e($label . ' · ' . $t['k.' . $k] . ': ' . $n($c[$k])) . '</title></rect>';
            }
            $asked = $c['hit'] + $c['miss'];
            if ($asked > 0) {
                $svg .= '<text class="rate" x="' . round($x + $bar / 2, 1) . '" y="' . round($y - 5, 1) . '" text-anchor="middle">' . (int) round(100 * $c['hit'] / $asked) . '%</text>';
            }
            if (count($daily) <= 14 || $i % 2 === 0) {
                $svg .= '<text class="day" x="' . round($x + $bar / 2, 1) . '" y="' . ($top + $plot + 16) . '" text-anchor="middle">' . $e($label) . '</text>';
            }
            $i++;
        }
        $legend = '';
        foreach (self::KINDS as $k) {
            $legend .= '<span class="key"><i class="k-' . $k . '"></i>' . $e($t['k.' . $k]) . '</span>';
        }
        $rows = '';
        foreach ($daily as $day => $c) {
            $asked = $c['hit'] + $c['miss'];
            $rows .= '<tr><td>' . $e(gmdate($lang === 'de' ? 'd.m.Y' : 'Y-m-d', (int) strtotime($day . ' UTC'))) . '</td>';
            foreach (self::KINDS as $k) {
                $rows .= '<td class="num">' . $e($n($c[$k])) . '</td>';
            }
            $rows .= '<td class="num">' . ($asked > 0 ? $e(sprintf($t['rate'], (string) (int) round(100 * $c['hit'] / $asked))) : '–') . '</td></tr>';
        }
        return '<div class="legend">' . $legend . '</div>'
            . '<svg class="days" viewBox="0 0 ' . $w . ' ' . ($top + $plot + 24) . '" role="img" aria-label="' . $e($t['daily']) . '">'
            . '<line class="base" x1="0" x2="' . $w . '" y1="' . ($top + $plot) . '" y2="' . ($top + $plot) . '"/>' . $svg . '</svg>'
            . '<details><summary>' . $e($t['asTable']) . '</summary><div class="wrap"><table><thead><tr><th>' . $e($t['day']) . '</th>'
            . implode('', array_map(static fn (string $k): string => '<th class="num">' . $e($t['k.' . $k]) . '</th>', self::KINDS)) . '<th class="num"></th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }

    /** A bar: how much of the cap is taken (0 to 100 %). */
    private static function bar(int $used, int $cap): string
    {
        $p = $cap > 0 ? max(0, min(100, (int) round(100 * $used / $cap))) : 0;
        return '<div class="bar" role="img" aria-label="' . $p . ' %"><span style="width:' . $p . '%"></span></div>';
    }

    private const CSS = '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.grid .card{margin:0}'
        . '.bar{height:10px;border-radius:999px;background:var(--line);overflow:hidden;margin:6px 0}.bar span{display:block;height:100%;background:var(--a)}'
        . 'form.purge{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 8px}'
        // The days' bars: four kinds, fixed order and colours (validated for colour blindness, light and dark).
        . ':root{--k-hit:#1e8a52;--k-miss:#2f62c9;--k-nostore:#c07a00;--k-past:#8b5cf6}'
        . '@media (prefers-color-scheme:dark){:root{--k-hit:#36a873;--k-miss:#5a86e6;--k-nostore:#b98228;--k-past:#9277e6}}'
        . 'svg.days{width:100%;height:auto;display:block;margin:4px 0}svg.days .base{stroke:var(--line);stroke-width:1}'
        . 'svg.days .day,svg.days .rate{fill:var(--m);font-size:11px}svg.days rect:hover{opacity:.8}'
        . '.k-hit{fill:var(--k-hit);background:var(--k-hit)}.k-miss{fill:var(--k-miss);background:var(--k-miss)}.k-nostore{fill:var(--k-nostore);background:var(--k-nostore)}.k-past{fill:var(--k-past);background:var(--k-past)}'
        . '.legend{display:flex;flex-wrap:wrap;gap:14px;font-size:13px;color:var(--m)}.legend .key i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px}'
        . 'details summary{cursor:pointer;color:var(--a);font-size:13px}td.num,th.num{text-align:right}';
}
}
}

// plugins/cache/src/CachePlugin.php
namespace CjwNetwork\RequestShield\Cache {
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\ContextHandler;
use CjwNetwork\RequestShield\Handler;
use CjwNetwork\RequestShield\Http;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\MethodHandler;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Purger;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The HTTP cache (0031 G.2): a page the shield found cacheable is answered
 * from the cache, before the application starts -- or, the first time, the
 * application's answer is kept for the next. Only what is public: no cookie
 * but the ones set http-cache-cookies names, no Authorization, an answer
 * without Set-Cookie and without private, no-store or no-cache. The addresses
 * a cache may keep are the shield's (cache-path, cache-query), on the host
 * names http-cache-hosts lists, exactly as sent (a port is another name).
 *
 * Tags and purges (0031 G.4, proposal 0039): an answer's tags (xkey,
 * X-Cache-Tags, … -- Tags) are kept with it and taken out of what the
 * visitor gets; a purge -- a PURGE or PURGEKEYS request from
 * http-cache-purgers or with the token, an X-LiteSpeed-Purge in any answer --
 * makes every answer with one of its tags out of date at once.
 *
 * Roles (0031 G.4, proposal 0039): the application names the visitor's role
 * through Shield::active()?->cacheContext() (ContextHandler); the cache keeps
 * MAC(secret, the session cookie) -> role in APCu, and a later request with
 * that cookie gets the page kept for the role -- when the application said
 * the page is the same for everyone with it (shared, or Vary: X-User-Hash /
 * X-User-Context-Hash). Such a page leaves as private.
 *
 * Without an adapter (http-cache-user-context): the role is FOSHttpCache's
 * user hash, asked as a Varnish asks it -- GET /_fos_user_context_hash with
 * only the session cookies, once per session for the hash's max-age, the
 * hash kept with its tags (a purge of ez-user-context-hash asks again) and
 * given to the application as the header a Varnish would send.
 */
final class CachePlugin implements Plugin, Handler, MethodHandler, ContextHandler, Purger
{
    /** The headers FOSHttpCache varies a page by role on (Ibexa, Exponential Platform): one page per role, not per visitor. */
    private const HASH_VARY = ['x-user-hash', 'x-user-context-hash'];

    /** The tag of every page kept for a role, and of every role remembered: purging it forgets them all (roles changed). */
    public const CONTEXT = 'rs-context';

    /** Headers never kept: they belong to one answer, or the web server makes them, or they are for a cache (tags: TAG_HEADERS). */
    private const DROP = ['set-cookie', 'date', 'age', 'x-rs', 'x-rs-monitor', 'x-rs-cache', 'server-timing', 'content-length', 'transfer-encoding', 'connection', 'keep-alive',
        'x-litespeed-purge', 'surrogate-control', 'x-location-id'];

    /** At most this many tags on one answer: one with more is not kept (its purges could not all be followed). */
    private const MAX_TAGS = 500;

    /** Where FOSHttpCache answers the user hash, and the Accept it answers to. */
    private const LOOKUP_PATH = '/_fos_user_context_hash';

    private const LOOKUP_ACCEPT = 'application/vnd.fos.user-context-hash';

    /**
     * After a lookup that failed: no lookup for this long -- for every session when the application
     * did not answer (no answer, a timeout, 502, 503, 504), for that session when it answered without a hash.
     */
    private const LOOKUP_PAUSE = 60;

    /** At most this many lookups a minute for new sessions from one address (made-up session cookies cost one request, not two). */
    private const LOOKUP_BUDGET = 30;

    /** A cookie as RFC 6265 has it (no space, quote, comma, semicolon or backslash in its value), at most 512 bytes of value. */
    private const COOKIE_PAIR = '[!#-+\-.\/0-9:<-\[\]-~]+=[!#-+\-.\/0-9:<-\[\]-~]{1,512}';

    /** At most this many lookups at the same time: each holds a worker while it waits for another. */
    private const LOOKUP_PARALLEL = 2;

    /** The tag every answer has for its address (path and query, any host): PURGE <address> purges it. */
    private const ADDRESS = 'rs-url:';

    /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int, page: string} */
    private array $o;

    private string $body = '';

    /** Something of the answer was thrown away, cut off or too large: it is not kept. */
    private bool $spoiled = false;

    /** The script has ended (the shutdown functions run before the last buffers are sent). */
    private bool $ending = false;

    /** @var list<string>|null the answer's headers as the application set them, before the tags were taken out (null: not sent yet) */
    private ?array $sent = null;

    private ?Tags $tags = null;

    /** @var MemoryCache|false|null the memory (null: not asked yet, false: none -- no APCu, or set to 0) */
    private $memory = null;

    private Settings $settings;

    /** The MAC of the visitor's session cookies (a request with only those and harmless ones), else null. */
    private ?string $session = null;

    /** The visitor's role: remembered for the session, or named by the application during this request. */
    private ?string $context = null;

    /** The application said this answer is the same for everyone with the role. */
    private bool $shared = false;

    /** The application named the role during this request (cacheContext()): only then is its answer kept for the role. */
    private bool $named = false;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->o = CacheExtension::of($settings);
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function handle(Request $request, Decision $decision): ?Response
    {
        if (!$this->o['enabled']) {
            return null;
        }
        if ($this->o['userContext'] !== '') {
            // The shield's own lookup (a MAC of the session): the application answers it. Anyone else
            // asking for a hash, or sending one, is refused -- as the Varnish configurations do.
            $asks = stripos((string) $request->header('accept'), self::LOOKUP_ACCEPT) !== false;
            if ($request->header('x-rs-lookup') !== null && $request->path === self::LOOKUP_PATH && $request->method === 'GET' && $this->isLookup($request)
                && $request->header('x-user-hash') === null && $request->header('x-user-context-hash') === null) {
                return null;
            }
            if ($asks || $request->header('x-user-hash') !== null || $request->header('x-user-context-hash') !== null) {
                return new Response(400, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Bad request\n");
            }
        }
        $visitor = $this->visitor($request);
        // cache-unknown-query hit-only (0048): a parameter cache-query does not name -- the page kept
        // without it may answer; nothing made for such a request is kept.
        $s = $this->settings;
        $lookupOnly = !$decision->cacheable() && $decision->reason === 'query parameter' && $s->cacheableUnknown === 'hit-only' && $s->cacheableQuery !== null;
        if ((!$decision->cacheable() && !$lookupOnly) || ($request->method !== 'GET' && $request->method !== 'HEAD') || $visitor === 'own'
            || !$this->ownHost($request) || !self::plainAddress($request)) {
            $this->watchHeaders();      // not for the cache, but its tags go and its purges count
            return null;
        }
        $key = $lookupOnly ? $request->cacheKey($s->cacheableQuery) : $request->cacheKey();
        $cache = new FileCache($this->o['dir'], $this->o['disk'], Capability::apcu());
        $now = microtime(true);
        if ($visitor === 'session') {
            // Signed in: the page of the visitor's role, when the session's role is known.
            $this->session = $this->sessionOf($request);
            if ($this->session === null) {
                $this->watchHeaders();
                return null;
            }
            $known = $this->remembered($this->session);
            if ($known === null && $this->o['userContext'] !== '') {
                $known = $this->lookup($request, $this->session);
            }
            if ($known === null && $this->o['userContext'] !== '') {
                $this->watchHeaders();
                return null;            // no hash: the cache is skipped for this request (fail safe)
            }
            $this->context = $known[0] ?? null;
            if ($known !== null && $known[1]) {
                // The application's own hash for this session, at most its max-age old: as good as a
                // Varnish's -- the answer is kept for it, and the application sees it as behind one.
                $this->named = true;
                $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $this->o['hashHeader']))] = $known[0];
            }
        }
        $hit = $visitor === 'anonymous' || $this->context !== null ? $this->find($cache, $this->keyFor($key), $now) : null;
        if ($hit !== null) {
            $headers = [...$hit['headers'], 'Age: ' . max(0, (int) $now - $hit['stored']), 'X-RS-Cache: hit'];
            $etag = self::header($hit['headers'], 'etag');
            if ($hit['status'] === 200 && $etag !== null && trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
            return new Response($hit['status'], $headers, $request->method === 'HEAD' ? '' : $hit['body']);
        }
        $this->watchHeaders();
        if (headers_sent() || $request->method !== 'GET' || $lookupOnly) {
            return null;
        }
        header('X-RS-Cache: miss');
        register_shutdown_function(function (): void {
            $error = error_get_last();
            $this->ending = true;
            $this->spoiled = $this->spoiled || ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0);
        });
        // The application's answer, caught as it is sent and kept when it is public -- and whole: what
        // the application throws away (ob_clean), an answer it ends before the script does, one larger
        // than http-cache-max-object (not held in memory either) is not kept.
        ob_start(function (string $buffer, int $phase) use ($cache, $key, $request, $now): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                $this->spoiled = true;
            } elseif (!$this->spoiled) {
                $this->body .= $buffer;
                $this->spoiled = strlen($this->body) > $this->o['maxObject'];
            }
            if ($this->spoiled) {
                $this->body = '';
            } elseif (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0 && $this->ending && ($this->session === null || ($this->context !== null && $this->named))) {
                // A signed-in visitor's answer only under the role the application named in this request
                // (a remembered role is no proof: the session may have ended), and only when shared.
                $this->keep($cache, $this->keyFor($key), (int) http_response_code(), $this->sent ?? headers_list(), $this->body, $request->matchPath(), $now,
                    $this->session !== null);
            }
            return $buffer;
        });
        return null;
    }

    /**
     * The answer kept for a key and still good: from memory, else from the
     * disk (and then into memory for the next hits). Out of date: purged
     * since its request began -- or, in memory, the memory purged since it
     * was put there (the disk may still have it).
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int, tags: list<string>, born: float}|null
     */
    private function find(FileCache $cache, string $key, float $now): ?array
    {
        $memory = $this->memory();
        $hit = $memory !== null ? $memory->get($key, $now) : null;
        if ($hit !== null && !$this->tags()->purgedSince($hit['tags'], $hit['born']) && !$this->tags()->purgedSince([MemoryCache::TAG], $hit['kept'])) {
            return $hit;
        }
        $hit = $cache->get($key, $now);
        if ($hit === null || $this->tags()->purgedSince($hit['tags'], $hit['born'])) {
            return null;            // purged since its request began: asked again (and kept anew)
        }
        if ($memory !== null) {
            $memory->put($key, $hit['status'], $hit['headers'], $hit['body'], $hit['stored'], $hit['expires'], $now, $hit['tags'], $hit['born'], $now);
        }
        return $hit;
    }

    /** The memory, with APCu and a size for it; else null. */
    private function memory(): ?MemoryCache
    {
        if ($this->memory === null) {
            $this->memory = Capability::apcu() && $this->o['memoryObject'] > 0 && $this->o['memory'] > 0
                ? new MemoryCache($this->o['dir'], $this->o['memoryObject'], $this->o['memory']) : false;
        }
        return $this->memory === false ? null : $this->memory;
    }

    /**
     * Keeps an answer when it may be kept by anyone: 200, 301 or 308, no
     * cookie set, no private, no-store or no-cache (nor Pragma: no-cache, nor
     * an Expires gone by), not encoded by the application (gzip it made would
     * go to visitors who did not ask for it), no Vary but on encoding; for its
     * own s-maxage or max-age, else http-cache-ttl; at most
     * http-cache-max-object bytes. Every line of a header counts. Its tags (Tags)
     * go with it, and the tag of its address; not kept: a page with ESI (its
     * fragments are not put together here), a LiteSpeed tag "private:", more
     * than MAX_TAGS tags.
     *
     * A page for a role ($role: the visitor is signed in) is kept only when
     * the application said it is the same for everyone with the role -- the
     * adapter's cacheContext(..., shared: true), which also stands for its
     * Cache-Control, or Vary on X-User-Hash / X-User-Context-Hash -- and
     * leaves as "private, no-cache" (no cache behind, no browser keeps one
     * role's page for another). The Vary on the role's hash stays in the
     * answer: a cache in front of the shield still varies by it.
     *
     * @param list<string> $headers headers_list()
     * @param ?float $born when its request began: a purge after it makes it out of date
     */
    public function keep(FileCache $cache, string $key, int $status, array $headers, string $body, string $path = '', ?float $born = null, bool $role = false): bool
    {
        $byRole = $role && $this->shared;
        $why = strlen($body) > $this->o['maxObject'] ? 'size' : self::refusal($status, $headers, $this->o['ttl'], $byRole);
        if ($why === 'appcache') {
            \CjwNetwork\RequestShield\Failure::note('cache', 'http-cache on, and the site\'s own HTTP cache answers too (X-Exp-Cache: Exponential 6\'s exphttpcache) -- '
                . 'the shield keeps none of its pages; switch the shield\'s cache off for this site (set http-cache off)', $this->settings->storeDir);
        }
        if ($why !== null) {
            return false;
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if ($role && !$byRole && array_intersect($vary, self::HASH_VARY) === []) {
            return false;           // a signed-in visitor's page the application did not call the same for the role
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        $ttl = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $this->o['ttl'];
        if ($byRole && ($ttl <= 0 || preg_match('/\b(no-store|no-cache)\b/', $cc) === 1)) {
            $ttl = $this->o['ttl'];     // the adapter's word: the page is the role's, kept for http-cache-ttl
        }
        if ($ttl <= 0 || stripos((string) self::header($headers, 'surrogate-control'), 'ESI/') !== false) {
            return false;           // nothing to keep it for (http-cache-ttl 0); ESI: fragments for a cache to put together
        }
        $tags = $this->tagsOf($headers);
        if ($tags === null) {
            return false;
        }
        $plain = (string) strstr($key . "\n", "\n", true);         // the key without the role's suffix
        $address = self::address($plain);
        $location = self::header($headers, 'location');
        if ($status >= 300 && $status < 400 && $location !== null && !self::elsewhere($plain, $location)) {
            return false;           // a redirect that may come back here (//, %65, ?a=1, a relative one): kept, a loop for everyone
        }
        $tags[] = self::ADDRESS . $address;
        if ($role) {
            $tags[] = self::CONTEXT;
        }
        $kept = [];
        foreach ($headers as $h) {
            $name = strtolower(trim((string) strstr($h, ':', true)));
            if ($role && ($name === 'cache-control' || $name === 'pragma' || $name === 'expires')) {
                continue;
            } elseif ($name !== '' && !in_array($name, self::DROP, true) && !in_array($name, $this->o['tagHeaders'], true)) {
                $kept[] = $h;
            }
        }
        if ($role) {
            $kept[] = 'Cache-Control: private, no-cache';
        }
        $now = microtime(true);
        $ttl = min($ttl, Tags::MAX_AGE);
        $tags = array_values(array_unique($tags));
        $born ??= $now;
        // Memory first; the disk too when it lives longer than memory keeps it, or when memory has no room.
        $memory = $this->memory();
        if ($memory !== null && $memory->put($key, $status, $kept, $body, (int) $now, (int) $now + $ttl, $now, $tags, $born, $born) && $ttl <= MemoryCache::MAX_TTL) {
            return true;
        }
        return $cache->put($key, $status, $kept, $body, $ttl, $now, $path, $tags, $born);
    }

    /**
     * A request with a method the site does not take (MethodHandler): a
     * purge in the dialects of Varnish/FOSHttpCache, Ibexa and Exponential
     * Platform, from http-cache-purgers or with X-Invalidate-Token -- or
     * null, and the rules refuse it as any unknown method (405): no hint that
     * a cache is there.
     *
     *   PURGE <address>                          that address (path and query, every host)
     *   PURGE / + key: a b                       tags (Exponential Platform; key: ez-all, all of it)
     *   PURGE / + X-Cache-Tags: a,b              tags (FOSHttpCache, Ibexa "local")
     *   PURGE / + X-Location-Id: * | 12 | (1|2)  everything, or location-12 … (Exponential's older calls)
     *   PURGEKEYS / + xkey-purge: a b            tags (Ibexa with Varnish; xkey-softpurge: the same, for now)
     */
    public function handleMethod(Request $request): ?Response
    {
        if (!$this->o['enabled'] || ($request->method !== 'PURGE' && $request->method !== 'PURGEKEYS') || !$this->mayPurge($request)) {
            return null;
        }
        $tags = self::purgeOf($request);
        if ($tags === null) {
            return new Response(400, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Nothing to purge\n");
        }
        if (!$this->tags()->purge($tags, microtime(true))) {
            return new Response(500, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Not purged\n");
        }
        return new Response(200, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Purged\n");
    }

    /**
     * What a purge request names: tags, Tags::ALL, or the tag of its address;
     * null when it names nothing that can be purged.
     *
     * @return list<string>|null
     */
    public static function purgeOf(Request $request): ?array
    {
        if ($request->method === 'PURGEKEYS') {
            $tags = Tags::split((string) ($request->header('xkey-purge') ?? $request->header('xkey-softpurge')));
            return $tags !== [] ? $tags : null;
        }
        $key = $request->header('key') ?? $request->header('x-cache-tags');
        if ($key !== null) {
            $tags = Tags::split($key);
            return $tags !== [] ? $tags : null;
        }
        $location = $request->header('x-location-id');
        if ($location !== null) {
            $location = trim($location);
            if ($location === '*' || $location === '.*') {
                return [Tags::ALL];
            }
            if (preg_match('/^\(?(\d+(?:\|\d+)*)\)?$/', $location, $m) !== 1) {
                return null;
            }
            return array_map(static fn (string $id): string => "location-$id", explode('|', $m[1]));
        }
        return [self::ADDRESS . self::address($request->cacheKey())];
    }

    /**
     * The application changed content (Purger, an adapter's
     * Shield::active()?->purge()): these tags out of date, "*" everything.
     *
     * @param list<string> $tags
     */
    public function purge(array $tags): void
    {
        if (!$this->o['enabled']) {
            return;
        }
        $names = [];
        foreach ($tags as $tag) {
            foreach (Tags::split($tag) as $t) {
                $names[] = $t;        // "*" is Tags::ALL
            }
        }
        if ($names !== []) {
            $this->tags()->purge($names, microtime(true));
        }
    }

    /**
     * Purges what the application names in its answer (LiteSpeed's way, any
     * method): "X-LiteSpeed-Purge: tag=c52, /news/, *" -- tags, addresses,
     * everything; "private, …" is a browser's own cache, not this one.
     */
    public function purgeFromAnswer(string $value): void
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $i): bool => $i !== ''));
        if ($items === [] || strtolower($items[0]) === 'private') {
            return;
        }
        $tags = [];
        foreach ($items as $item) {
            if ($item === '*') {
                $tags[] = Tags::ALL;
            } elseif (strncasecmp($item, 'tag=', 4) === 0) {
                foreach (Tags::split(substr($item, 4)) as $t) {
                    $tags[] = strncasecmp($t, 'public:', 7) === 0 ? substr($t, 7) : $t;
                }
            } elseif ($item[0] === '/') {
                $tags[] = self::ADDRESS . self::address($item);
            }
        }
        if ($tags !== []) {
            $this->tags()->purge($tags, microtime(true));
        }
    }

    /**
     * The tags an answer carries in the headers http-cache-tag-headers knows,
     * a LiteSpeed "public:" taken off; null when it may not be kept (a
     * "private:" tag, more than MAX_TAGS).
     *
     * @param list<string> $headers
     * @return list<string>|null
     */
    private function tagsOf(array $headers): ?array
    {
        $tags = [];
        foreach ($this->o['tagHeaders'] as $name) {
            foreach (Tags::split((string) self::header($headers, $name)) as $t) {
                if (strncasecmp($t, 'private:', 8) === 0) {
                    return null;    // LiteSpeed: for one visitor's private cache
                }
                $tags[] = strncasecmp($t, 'public:', 7) === 0 ? substr($t, 7) : $t;
            }
        }
        $location = trim((string) self::header($headers, 'x-location-id'));
        if (ctype_digit($location)) {
            $tags[] = "location-$location";     // Exponential's older header
        }
        return count($tags) > self::MAX_TAGS ? null : $tags;
    }

    /**
     * Before the answer's headers go out (any answer, while the cache is
     * on): its purges are done, its tags and purges taken out -- they name
     * content and are for a cache, not for the visitor. headers_list() as it
     * was is kept for keep(): once sent, the headers cannot change.
     */
    private function watchHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_register_callback(function (): void {
            $list = headers_list();
            $this->sent = $list;
            $purge = self::header($list, 'x-litespeed-purge');
            if ($purge !== null) {
                $this->purgeFromAnswer($purge);
            }
            $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($list, 'vary')))));
            $byHash = array_intersect($vary, self::HASH_VARY) !== [];       // left in the answer: a cache in front varies by it
            foreach ($list as $h) {
                $name = strtolower(trim((string) strstr($h, ':', true)));
                if ($name === 'x-litespeed-purge' || $name === 'x-location-id' || in_array($name, $this->o['tagHeaders'], true)) {
                    header_remove($name);
                }
            }
            if ($this->session !== null && $this->context !== null && $this->named && ($this->shared || $byHash)) {
                // A page for a role: never kept by a cache behind the shield, nor by a browser for another role.
                header('Cache-Control: private, no-cache');
                header_remove('Expires');
                header_remove('Pragma');
                header('X-RS-Cache: miss; role');     // the statistics: this Cache-Control is the shield's, not the site's
            }
        });
    }

    /**
     * The visitor names a role (ContextHandler, from the application's
     * adapter): remembered in APCu for the session cookies the request
     * carries, keyed by their MAC -- the cookie is the credential, and an
     * unknown or forged one finds nothing. With $shared, this answer may be
     * kept for the role. Nothing without APCu, without
     * http-cache-session-cookie, or without a session cookie on the request.
     */
    public function cacheContext(Request $request, string $context, bool $shared): void
    {
        if (!$this->o['enabled'] || $context === '' || strlen($context) > 200 || !Capability::apcu()) {
            return;
        }
        $session = $this->session ?? $this->sessionOf($request);
        if ($session === null) {
            return;
        }
        apcu_store($this->contextKey($session), [microtime(true), $context], $this->o['contextTtl']);
        $this->session = $session;
        $this->context = $context;
        $this->named = true;
        $this->shared = $this->shared || $shared;
    }

    /** The visitor signed out: the session's role is forgotten, and this answer is not kept for it. */
    public function forgetContext(Request $request): void
    {
        $session = $this->session ?? $this->sessionOf($request);
        if ($session !== null && Capability::apcu()) {
            apcu_delete($this->contextKey($session));
        }
        $this->context = null;
        $this->shared = false;
        $this->named = false;
    }

    /**
     * The role remembered for a session -- and whether it is a user hash the
     * application gave (lookup) -- unless it ran out or the roles were purged
     * since (the tag CONTEXT, and the hash answer's own tags).
     *
     * @return array{0: string, 1: bool}|null
     */
    private function remembered(string $session): ?array
    {
        $got = apcu_fetch($this->contextKey($session));
        if (!is_array($got) || !is_float($got[0] ?? null) || !is_string($got[1] ?? null)) {
            return null;
        }
        $tags = [self::CONTEXT];
        foreach (is_array($got[3] ?? null) ? $got[3] : [] as $t) {
            if (is_string($t)) {
                $tags[] = $t;
            }
        }
        return $this->tags()->purgedSince($tags, $got[0]) ? null : [$got[1], ($got[2] ?? false) === true];
    }

    /**
     * FOSHttpCache's user hash for a session, asked of the application as a
     * Varnish asks it: GET /_fos_user_context_hash, Accept
     * application/vnd.fos.user-context-hash, only the session cookies, and
     * X-RS-Lookup (a MAC of the session: the shield knows its own request);
     * no redirect followed. Kept in APCu for the hash's max-age (at most an
     * hour) with the tags of its answer, from the time before it was asked
     * (a purge while it is asked is not missed). No hash: null -- and no
     * lookup for LOOKUP_PAUSE seconds, for every session when the application
     * did not answer (no answer, a timeout, 502, 503, 504), else for this
     * session (a 500 may be what one made-up cookie causes). Not
     * asked at all for a cookie or host a request could not carry, past the
     * address's LOOKUP_BUDGET, or while LOOKUP_PARALLEL others wait.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function lookup(Request $request, string $session): ?array
    {
        $prefix = 'rshield:hc:' . substr(md5($this->o['dir']), 0, 12) . ':lookup';
        $cookie = implode('; ', $this->sessionPairs($request));
        $host = self::sentHost($request);     // on http-cache-hosts (ownHost), with its port
        if (apcu_fetch("$prefix-pause") !== false || apcu_fetch($this->contextKey($session) . ':none') !== false
            || preg_match('/^(?:' . self::COOKIE_PAIR . '(?:; |$))+$/D', $cookie) !== 1
            || preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/D', $host) !== 1) {
            return null;
        }
        $budget = "$prefix-n:" . IpAddress::bucket($request->clientIp, $this->settings->ipv6Prefix);
        apcu_add($budget, 0, 60);
        if (apcu_inc($budget, 1, $ok, 60) > self::LOOKUP_BUDGET) {
            return null;
        }
        apcu_add("$prefix-running", 0, 10);
        if (apcu_inc("$prefix-running", 1, $ok, 10) > self::LOOKUP_PARALLEL) {
            apcu_dec("$prefix-running");
            return null;
        }
        $asked = microtime(true);
        try {
            $base = $this->o['userContext'] === 'on' ? strtolower($request->scheme) . '://' . $host : $this->o['userContext'];
            $got = Http::get($base . self::LOOKUP_PATH, ['Accept' => self::LOOKUP_ACCEPT, 'Host' => $host, 'Cookie' => $cookie,
                'X-RS-Lookup' => $this->lookupMac($session)], 2, 4096, 'request-shield', false);
        } finally {
            if (apcu_dec("$prefix-running") < 0) {
                apcu_store("$prefix-running", 0, 10);     // the key ran out while this one asked: not below 0
            }
        }
        if ($got === null || in_array($got['status'], [502, 503, 504], true)) {
            apcu_store("$prefix-pause", 1, self::LOOKUP_PAUSE);
            return null;
        }
        $hash = $got['status'] === 200 ? trim($got['headers'][$this->o['hashHeader']] ?? '') : '';
        if (preg_match('/^[!-~]{1,200}$/D', $hash) !== 1) {
            apcu_store($this->contextKey($session) . ':none', 1, self::LOOKUP_PAUSE);
            return null;
        }
        $cc = strtolower($got['headers']['cache-control'] ?? '');
        $ttl = preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? min((int) $m[1], 3600) : $this->o['contextTtl'];
        if ($ttl > 0) {
            $tags = $this->tagsOf(array_map(static fn (string $k, string $v): string => "$k: $v", array_keys($got['headers']), $got['headers'])) ?? [];
            apcu_store($this->contextKey($session), [$asked, $hash, true, $tags], $ttl);
        }
        return [$hash, true];
    }

    /** Whether a request is the shield's own lookup: the X-RS-Lookup of its session cookies. */
    private function isLookup(Request $request): bool
    {
        // Without APCu too: the server that answers the lookup may be another than the one asking.
        $pairs = $this->sessionPairs($request);
        return $pairs !== [] && hash_equals($this->lookupMac($this->macOf($pairs)), (string) $request->header('x-rs-lookup'));
    }

    private function lookupMac(string $session): string
    {
        $s = $this->settings;
        return hash_hmac('sha256', 'lookup|' . $session, Secret::resolve($s->challenge->secret, $s->storeDir));
    }

    /**
     * The MAC of the session cookies a request carries (the names
     * http-cache-session-cookie matches, sorted, with their values), or
     * null: none, or no APCu to remember a role in.
     */
    private function sessionOf(Request $request): ?string
    {
        if ($this->o['sessionCookies'] === [] || !Capability::apcu()) {
            return null;
        }
        $pairs = $this->sessionPairs($request);
        return $pairs === [] ? null : $this->macOf($pairs);
    }

    /** @param list<string> $pairs the session cookies, sorted */
    private function macOf(array $pairs): string
    {
        $s = $this->settings;
        return hash_hmac('sha256', 'session|' . implode('; ', $pairs), Secret::resolve($s->challenge->secret, $s->storeDir));
    }

    /**
     * The session cookies of a request, sorted ("name=value").
     *
     * @return list<string>
     */
    private function sessionPairs(Request $request): array
    {
        $pairs = [];
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $name = trim((string) strstr($pair . '=', '=', true));
            if ($name !== '' && !self::matches($name, $this->o['cookies']) && self::matches($name, $this->o['sessionCookies'])) {
                $pairs[] = trim($pair);     // as visitor() reads them: a harmless cookie is never a session
            }
        }
        sort($pairs);
        return $pairs;
    }

    private function contextKey(string $session): string
    {
        return 'rshield:hc:' . substr(md5($this->o['dir']), 0, 12) . ':ctx:' . $session;
    }

    /** The key of the answer: the address's, and the role's when there is one. */
    private function keyFor(string $key): string
    {
        return $this->context === null ? $key : $key . "\nctx=" . hash('sha256', $this->context);
    }

    /**
     * Who the visitor is to the cache: "anonymous" (no cookie but harmless
     * ones, no Authorization), "session" (besides those only session cookies
     * -- with http-cache-session-cookie and APCu), else "own": the page may
     * be someone's own.
     */
    private function visitor(Request $request): string
    {
        if ($request->header('authorization') !== null || $request->header('x-user-hash') !== null || $request->header('x-user-context-hash') !== null) {
            return 'own';       // a role's hash sent by the client: an application that believes it would make a role's page
        }
        if ($request->header('x-original-url') !== null || $request->header('x-rewrite-url') !== null) {
            return 'own';       // another address than the key's: some frameworks route by it (Zend 1, Magento 1, old Symfony)
        }
        $who = 'anonymous';
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $name = trim((string) strstr($pair . '=', '=', true));
            if ($name === '' || self::matches($name, $this->o['cookies'])) {
                continue;
            }
            if ($this->o['sessionCookies'] === [] || !self::matches($name, $this->o['sessionCookies'])) {
                return 'own';       // a cart, another login: the page may be someone's own
            }
            $who = 'session';
        }
        return $who === 'session' && !Capability::apcu() ? 'own' : $who;
    }

    /** @param list<string> $globs */
    private static function matches(string $name, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * May purge: X-Invalidate-Token equal to http-cache-purge-token, or an
     * address on http-cache-purgers -- the client's address as the shield
     * found it; one that came through a proxy the shield does not trust (it
     * sent forwarding headers) never counts as the proxy's own.
     */
    private function mayPurge(Request $request): bool
    {
        $token = $request->header('x-invalidate-token');
        if ($this->o['token'] !== '' && $token !== null && hash_equals($this->o['token'], $token)) {
            return true;
        }
        if (!$request->viaTrustedProxy) {
            foreach (['x-forwarded-for', 'forwarded', 'x-real-ip', 'via'] as $h) {
                if ($request->header($h) !== null) {
                    return false;
                }
            }
        }
        return IpAddress::inRanges($request->clientIp, $this->o['purgers']);
    }

    /**
     * Whether a redirect surely leads elsewhere: another scheme or host, or
     * another path (decoded and collapsed: /news//item is /news/item). Only
     * "/path" and "scheme://host/path" are read; a relative one (p, ?a=1,
     * //host/p) never counts as elsewhere -- whatever the query, cache-ignore
     * or query drop may make it this very key again.
     */
    private static function elsewhere(string $key, string $location): bool
    {
        $location = trim($location);
        $origin = static fn (string $url): string => strtolower((string) preg_replace('/:\d+$/', '', (string) preg_replace('#^([a-z][a-z0-9+.-]*://[^/?\#]*).*$#is', '$1', $url)));
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            if ($origin($location) !== $origin($key)) {
                return true;                // http to https, example.org to www.example.org: another key
            }
            $location = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', '', $location);
        } elseif (strncmp($location, '/', 1) !== 0 || strncmp($location, '//', 2) === 0) {
            return false;
        }
        $path = static fn (string $uri): string => Request::fromServer(['REQUEST_URI' => (string) strtok($uri === '' ? '/' : $uri, '?#'), 'HTTP_HOST' => 'h'])->matchPath();
        return $path($location) !== $path((string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/?]*#i', '', $key));
    }

    /**
     * The address part of a key or of an address as a purge names it -- the
     * path decoded and collapsed (/n%65ws//item is /news/item) and the query
     * sorted, as Request::cacheKey() makes it; no scheme, no host (a purge
     * from 127.0.0.1 names no host the visitors use). The key keeps the path
     * as sent; the address is what all its spellings share, so one purge
     * reaches them all.
     */
    public static function address(string $keyOrUri): string
    {
        $uri = preg_replace('#^[a-z][a-z0-9+.-]*://[^/?]*#i', '', $keyOrUri) ?? $keyOrUri;
        $r = Request::fromServer(['REQUEST_URI' => $uri === '' ? '/' : $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'h']);
        return $r->matchPath() . substr($r->cacheKey(), strlen('http://h' . $r->path));
    }

    private function tags(): Tags
    {
        return $this->tags ??= new Tags($this->o['dir'], Capability::apcu());
    }

    /**
     * Why an answer may not be kept, from its status and headers -- null when
     * it may (keep() then checks its size): "status" (not 200, 301, 308),
     * "cookie" (it sets one), "encoded" (the application compressed it),
     * "private" (private, no-store, no-cache, Pragma: no-cache), "expired" (an
     * Expires gone by), "vary" (on more than the encoding), "appcache" (the
     * application's own HTTP cache answered or keeps it: X-Exp-Cache, Exponential 6), "ttl" (max-age=0,
     * or no ttl at all). The statistics ask it too, for a miss (0046): why the
     * page did not go into the cache. A Vary on the role's hash (X-User-Hash,
     * X-User-Context-Hash) is the shield's to follow. $byRole: the application
     * called the page the same for the visitor's role (cacheContext(...,
     * shared: true)) -- its private, no-cache, Expires and max-age=0 are for
     * caches behind, not this one.
     *
     * @param list<string> $headers headers_list()
     */
    public static function refusal(int $status, array $headers, int $ttl, bool $byRole = false): ?string
    {
        if (!in_array($status, [200, 301, 308], true)) {
            return 'status';
        }
        if (self::header($headers, 'x-exp-cache') !== null) {
            return 'appcache';          // Exponential 6's own HTTP cache (exphttpcache) keeps it: never two caches in a row (0048)
        }
        if (self::header($headers, 'set-cookie') !== null) {
            return 'cookie';
        }
        if (self::header($headers, 'content-encoding') !== null) {
            return 'encoded';
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        if (!$byRole && (preg_match('/\b(private|no-store|no-cache)\b/', $cc) === 1 || preg_match('/\bno-cache\b/i', (string) self::header($headers, 'pragma')) === 1)) {
            return 'private';
        }
        $expires = self::header($headers, 'expires');
        if (!$byRole && $expires !== null && strpos($cc, 'max-age') === false && (int) strtotime($expires) <= time()) {
            return 'expired';           // an Expires gone by (or one that is no date): not for a cache
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if (array_diff($vary, ['accept-encoding'], self::HASH_VARY) !== []) {
            return 'vary';              // an answer that differs by language or cookie: not one page
        }
        $own = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $ttl;
        return $own <= 0 && !$byRole ? 'ttl' : null;
    }

    /**
     * The host name as the visitor sent it -- with its port -- on the list
     * http-cache-hosts: an answer is kept under the name without the port, so
     * a page an application built from a made-up Host (links, a redirect) must
     * never be kept, and made-up names never fill the cache.
     */
    private function ownHost(Request $request): bool
    {
        return in_array(self::sentHost($request), $this->o['hosts'], true);
    }

    /** The host name as the visitor sent it, with its port, in small letters. */
    private static function sentHost(Request $request): string
    {
        $raw = $request->viaTrustedProxy && $request->header('x-forwarded-host') !== null
            ? explode(',', (string) $request->header('x-forwarded-host'))[0] : (string) $request->header('host');
        return strtolower(trim($raw));
    }

    /**
     * An address with one key for one answer: a path from "/" (not a whole
     * URL as the request's target), no "#" in what was sent (PHP passes it
     * on, the key ends before it), no encoded "/", "?" or "#", and no
     * parameter PHP reads twice or folds. (The key holds the path as sent:
     * other spellings are other keys.)
     */
    private static function plainAddress(Request $request): bool
    {
        if (strncmp($request->path, '/', 1) !== 0 || $request->sentFragment()
            || preg_match('/%(2f|3f|23)/i', $request->path) === 1) {
            return false;
        }
        // One key in $_GET for every pair, by PHP's own parser: two spellings of one name (page and " page",
        // page=1&page=2 in either order) or a name PHP drops (one nested too deep can remove another) are no address.
        $keys = [];
        foreach ($request->queryPairs() as [, , $raw]) {
            $key = strpbrk($raw, '[%+. ') === false ? explode('=', $raw, 2)[0] : Request::phpKey($raw);
            if ($key === '' || isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
        }
        return true;
    }

    /**
     * A header's value: every line of it, joined with commas (two Vary lines are one list).
     *
     * @param list<string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $values = [];
        foreach ($headers as $h) {
            if (strncasecmp($h, $name . ':', strlen($name) + 1) === 0) {
                $values[] = trim(substr($h, strlen($name) + 1));
            }
        }
        return $values === [] ? null : implode(', ', $values);
    }
}
}
}

// plugins/cache/src/Cli/CacheCommand.php
namespace CjwNetwork\RequestShield\Cache\Cli {
use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Cache\MemoryCache;
use CjwNetwork\RequestShield\Cache\Tags;
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;

if (true) {
/**
 * `request-shield cache <main.rules>`: what the HTTP cache holds; `purge`
 * empties it (`--path=/news/`: the addresses below it), `expired` removes
 * what has run out -- for cron, a deploy, a CMS after publishing; `purge
 * --tag=c52,l2` makes the answers with one of the tags out of date (Tags).
 */
final class CacheCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield cache <main.rules> [purge [--path=/news/ | --tag=c52,l2] | expired]';
    }

    public static function run(Context $c): int
    {
        $o = CacheExtension::of($c->settings);
        $cache = new FileCache($o['dir'], $o['disk']);
        if ($c->what === 'purge') {
            $period = $c->option('period', []);
            $path = is_array($period) ? ($period['path'] ?? null) : null;
            $tag = is_array($period) && is_string($period['tag'] ?? null) ? Tags::split($period['tag']) : [];
            if ($tag !== []) {
                // The CLI's APCu is not the web server's: the files are the truth, the server's
                // copies run out within Tags::MEMORY seconds.
                $ok = (new Tags($o['dir'], Capability::apcu()))->purge($tag, microtime(true));
                echo ($ok ? 'purged' : 'could not write the purge for') . ' the tags ' . implode(' ', $tag) . "\n";
                return $ok ? 0 : 1;
            }
            echo 'removed ' . $cache->purge(is_string($path) && $path !== '' ? $path : null) . " answers\n";
            // The web server's memory is not this process's: its answers are made out of date by a tag (MemoryCache::TAG).
            if (!MemoryCache::forget($o['dir'], Capability::apcu(), microtime(true))) {
                echo "could not write the purge for the answers in memory\n";
                return 1;
            }
            if ($o['memoryObject'] > 0) {
                echo 'the web server\'s answers in memory: out of date within ' . Tags::MEMORY . " s (a PURGE request or the API: at once)\n";
            }
            return 0;
        }
        if ($c->what === 'expired') {
            echo 'removed ' . $cache->purge(null, microtime(true)) . " expired answers\n";
            if ($o['disk'] > 0) {
                echo 'the folder holds ' . number_format($cache->trimAll(microtime(true)) / 1048576, 1) . ' MB of at most ' . number_format($o['disk'] / 1048576, 1) . " MB\n";
            }
            return 0;
        }
        if ($c->what !== null) {
            fwrite(STDERR, 'usage: ' . self::usage() . "\n");
            return 2;
        }
        $st = $cache->stats();
        echo 'http-cache ' . ($o['enabled'] ? 'on' : 'off (set http-cache on)') . ", kept {$o['ttl']} s unless the answer says otherwise\n"
            . "{$st['entries']} answers, " . number_format($st['bytes'] / 1024, 1) . " KB in {$o['dir']}\n";
        return 0;
    }
}
}
}

// plugins/cache/src/FileCache.php
namespace CjwNetwork\RequestShield\Cache {
if (true) {
/**
 * The HTTP cache's store (0031 G.2): one file per address, under a folder of
 * the store directory -- a line of JSON (status, headers, when kept, until
 * when, its tags and when its request began: Tags), then the body. Written to a temporary file and renamed, so a reader
 * never sees half a page; an expired one is removed when it is read.
 *
 * The cap (http-cache-disk, 0031 G.4 part 3) holds per folder: each of the
 * 256 folders keeps at most a 256th of it -- the addresses spread evenly
 * over them (sha1), so no folder list is ever read whole. With APCu each
 * folder's bytes are counted as answers are written; past its share the
 * folder is measured, and the expired and then the oldest answers go
 * until it holds nine tenths. Without APCu the sweep (one store in a
 * hundred) does the same for the folder it sweeps -- the cap then holds
 * on average, not for every folder at every moment.
 */
final class FileCache
{
    /** The folders the answers are spread over (the first two hex digits of the key's sha1). */
    private const FOLDERS = 256;

    /** @param int $cap the most the folder may hold, in bytes (0: no cap) */
    public function __construct(private string $dir, private int $cap = 0, private bool $apcu = false)
    {
    }

    /**
     * What is kept for a key, unless it has expired.
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int, tags: list<string>, born: float}|null
     */
    public function get(string $key, float $now): ?array
    {
        $file = $this->path($key);
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
        $nl = strpos($data, "\n");
        $meta = $nl === false ? null : json_decode(substr($data, 0, $nl), true);
        if (!is_array($meta) || !is_int($meta['expires'] ?? null) || !is_int($meta['status'] ?? null) || !is_int($meta['stored'] ?? null)
            || ($meta['key'] ?? null) !== $key || !is_array($meta['headers'] ?? null)) {
            @unlink($file);
            return null;
        }
        if ($meta['expires'] <= (int) $now) {
            @unlink($file);
            return null;
        }
        $born = $meta['born'] ?? null;
        return ['status' => $meta['status'], 'headers' => array_values(array_filter($meta['headers'], 'is_string')), 'body' => (string) substr($data, $nl + 1),
            'stored' => $meta['stored'], 'expires' => $meta['expires'], 'tags' => array_values(array_filter(is_array($meta['tags'] ?? null) ? $meta['tags'] : [], 'is_string')),
            'born' => is_float($born) || is_int($born) ? (float) $born : (float) $meta['stored']];
    }

    /**
     * Keeps an answer; one store in a hundred also removes what has expired
     * in one of the 256 folders (and the purge times run out there), so the
     * cache cleans up by itself.
     *
     * @param list<string> $headers
     * @param string $path the address's path, for a purge below a path
     * @param list<string> $tags what purges it (Tags)
     * @param ?float $born when its request began (default $now): a purge after it makes it out of date
     */
    public function put(string $key, int $status, array $headers, string $body, int $ttl, float $now, string $path = '', array $tags = [], ?float $born = null): bool
    {
        if (mt_rand(1, 100) === 1) {
            $folder = sprintf('%02x', mt_rand(0, 255));
            (new Tags($this->dir, false))->sweep($folder, $now);
            if ($this->cap > 0 && !$this->apcu) {
                $this->trim($folder, $now);         // sweeps, then the oldest past the folder's share
            } else {
                $this->sweep($folder, $now);
            }
        }
        $file = $this->path($key);
        $meta = json_encode(['key' => $key, 'path' => $path, 'status' => $status, 'headers' => $headers, 'stored' => (int) $now, 'expires' => (int) $now + $ttl,
            'tags' => $tags, 'born' => $born ?? $now], JSON_UNESCAPED_SLASHES);
        if ($meta === false) {
            return false;           // a header that is no UTF-8: not kept, rather than a file that never reads
        }
        $before = $this->cap > 0 && $this->apcu ? (int) @filesize($file) : 0;     // replaced: only the difference counts
        if (!\CjwNetwork\RequestShield\Files::write($file, $meta . "\n" . $body, '.tmp')) {
            return false;
        }
        if ($this->cap > 0 && $this->apcu) {
            $this->count(substr(sha1($key), 0, 2), strlen($meta) + 1 + strlen($body) - $before, $now);
        }
        return true;
    }

    /**
     * Counts what was written into a folder; past its share of the cap the
     * folder is measured and trimmed, and the count set to what it holds. A
     * count that is missing (APCu restarted) measures once.
     */
    private function count(string $folder, int $bytes, float $now): void
    {
        $key = 'rshield:hc:' . substr(md5($this->dir), 0, 12) . ":d:$folder";
        $held = apcu_fetch($key);
        $held = is_int($held) ? ($bytes >= 0 ? apcu_inc($key, $bytes) : apcu_dec($key, -$bytes)) : false;
        if (is_int($held) && $held <= intdiv($this->cap, self::FOLDERS)) {
            return;
        }
        apcu_store($key, $this->trim($folder, $now));
    }

    /**
     * Brings a folder within its share of the cap: the expired answers
     * first, then the oldest (by when they were written) until it holds nine
     * tenths of it. Returns the bytes it holds then.
     */
    public function trim(string $folder, float $now): int
    {
        $limit = intdiv($this->cap, self::FOLDERS);
        $this->sweep($folder, $now);
        $files = [];
        $held = 0;
        foreach ($this->files($folder) as $file) {
            $size = (int) @filesize($file);
            $files[$file] = [(int) @filemtime($file), $size];
            $held += $size;
        }
        if ($this->cap <= 0 || $held <= $limit) {
            return $held;
        }
        uasort($files, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($files as $file => [, $size]) {
            if ($held <= intdiv($limit * 9, 10)) {
                break;
            }
            if (@unlink($file)) {
                $held -= $size;
            }
        }
        return $held;
    }

    /** Every folder within its share of the cap (the command line's "expired"); returns the bytes held. */
    public function trimAll(float $now): int
    {
        $held = 0;
        for ($i = 0; $i < self::FOLDERS; $i++) {
            $held += $this->trim(sprintf('%02x', $i), $now);
        }
        return $held;
    }

    /**
     * Removes what is kept: everything, or the addresses whose path starts
     * with $path, literally (/news/ -- every host; /news also takes
     * /newsletter), or ($now) what has expired. Returns how many. Files a
     * write left half done (*.tmp) go too, once they are a minute old.
     */
    public function purge(?string $path = null, ?float $now = null, string $folder = '*'): int
    {
        $n = 0;
        foreach (glob(rtrim($this->dir, '/') . "/$folder/*/*.tmp") ?: [] as $tmp) {
            if ($path === null && (int) @filemtime($tmp) < (int) ($now ?? microtime(true)) - 60) {
                @unlink($tmp);
            }
        }
        foreach ($this->files($folder) as $file) {
            if ($path !== null || $now !== null) {
                $h = @fopen($file, 'rb');
                $line = $h !== false ? (string) fgets($h) : '';
                if ($h !== false) {
                    fclose($h);
                }
                $meta = json_decode($line, true);
                $key = is_array($meta) && is_string($meta['key'] ?? null) ? $meta['key'] : '';
                $keyPath = is_array($meta) && is_string($meta['path'] ?? null) && $meta['path'] !== '' ? $meta['path'] : (string) parse_url($key, PHP_URL_PATH);
                $expired = $now !== null && is_array($meta) && is_int($meta['expires'] ?? null) && $meta['expires'] <= (int) $now;
                if (!$expired && ($path === null || strncmp($keyPath, $path, strlen($path)) !== 0)) {
                    continue;
                }
            }
            $n += @unlink($file) ? 1 : 0;
        }
        return $n;
    }

    /** @return array{entries: int, bytes: int} */
    public function stats(): array
    {
        $entries = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $entries++;
            $bytes += (int) @filesize($file);
        }
        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /** What has expired in one folder (ab: one 256th of the cache). */
    public function sweep(string $folder, float $now): int
    {
        return $this->purge(null, $now, $folder);
    }

    /** @return list<string> */
    private function files(string $folder = '*'): array
    {
        return glob(rtrim($this->dir, '/') . "/$folder/*/*.cache") ?: [];
    }

    private function path(string $key): string
    {
        $h = sha1($key);
        return rtrim($this->dir, '/') . '/' . substr($h, 0, 2) . '/' . substr($h, 2, 2) . "/$h.cache";
    }
}
}
}

// plugins/cache/src/MemoryCache.php
namespace CjwNetwork\RequestShield\Cache {
if (true) {
/**
 * The HTTP cache's memory (0031 G.4 part 3, proposal 0039): small answers in
 * APCu, ahead of the disk (FileCache) -- a hit is one apcu_fetch, no file.
 *
 * An answer stays here at most MAX_TTL; one that lives longer is on the disk
 * too, and comes back here on its next hit. What the cache may take of APCu
 * is counted per hour of storing: an answer stored in this hour or the one
 * before may still be here, nothing older -- so the two counters are an
 * upper bound of what lies here (a purged or replaced answer is counted
 * until its hour has passed: the bound only errs on the safe side). Above
 * the share, or when APCu would keep less than a quarter free, the answer
 * goes to the disk: a full APCu with apc.ttl 0 is emptied whole, the
 * shield's budgets and roles with it.
 *
 * APCu is the process's own: the command line cannot reach the web server's.
 * Every answer from here counts the tag TAG as well -- from when it was put
 * here ("kept") -- so a purge of it (the command line's, the API's
 * "everything" or "below a path") makes them all out of date at once; the
 * disk keeps the truth, and what is still good there comes back here.
 */
final class MemoryCache
{
    /** The tag every answer in memory carries: purged, the memory's answers are out of date. */
    public const TAG = 'rs-memory';

    /** The longest an answer stays in memory: the counters' hour. */
    public const MAX_TTL = 3600;

    /** What APCu keeps free at least: a quarter. */
    private const RESERVE = 4;

    /** After APCu was short of room: no answer into memory for this long (a disk hit does not ask APCu's free memory every time). */
    private const FULL_PAUSE = 10;

    private string $prefix;

    public function __construct(string $dir, private int $maxObject, private int $share)
    {
        // Sites that share a PHP pool share APCu: the folder tells them apart (as Tags).
        $this->prefix = 'rshield:hc:' . substr(md5($dir), 0, 12) . ':';
    }

    /**
     * What is kept for a key, unless it has expired -- as FileCache::get(),
     * and since when it is here (kept: TAG's purges count from then).
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int, tags: list<string>, born: float, kept: float}|null
     */
    public function get(string $key, float $now): ?array
    {
        $got = apcu_fetch($this->prefix . 'a:' . sha1($key));
        if (!is_array($got) || ($got['key'] ?? null) !== $key || !is_int($got['status'] ?? null) || !is_int($got['expires'] ?? null) || !is_int($got['stored'] ?? null)
            || !is_string($got['body'] ?? null) || !is_array($got['headers'] ?? null) || !is_array($got['tags'] ?? null) || !is_float($got['born'] ?? null)
            || !is_float($got['kept'] ?? null) || $got['expires'] <= (int) $now) {
            return null;
        }
        return ['status' => $got['status'], 'headers' => array_values(array_filter($got['headers'], 'is_string')), 'body' => $got['body'], 'stored' => $got['stored'],
            'expires' => $got['expires'], 'tags' => array_values(array_filter($got['tags'], 'is_string')), 'born' => $got['born'], 'kept' => $got['kept']];
    }

    /**
     * Keeps an answer in memory until $expires (at most MAX_TTL from now),
     * when it is small enough and there is room; false: it was not (the disk
     * takes it). $kept: from when TAG's purges count -- when its request
     * began for a new answer, now for one that came from the disk.
     *
     * @param list<string> $headers
     * @param list<string> $tags
     */
    public function put(string $key, int $status, array $headers, string $body, int $stored, int $expires, float $now, array $tags, float $born, float $kept): bool
    {
        $size = strlen($body) + strlen(implode("\n", $headers)) + strlen(implode(' ', $tags)) + strlen($key);
        $ttl = min($expires - (int) $now, self::MAX_TTL);
        if ($size > $this->maxObject || $ttl <= 0 || $size > $this->share || preg_match('//u', implode("\n", $headers)) !== 1) {
            return false;           // a header that is no UTF-8: as the disk, not kept
        }
        $hour = intdiv((int) $now, 3600);
        $counted = apcu_fetch([$this->prefix . "m:$hour", $this->prefix . 'm:' . ($hour - 1), $this->prefix . 'm:full']);
        $counted = is_array($counted) ? $counted : [];
        if (isset($counted[$this->prefix . 'm:full'])) {
            return false;           // APCu was short of room a moment ago: not asked again for FULL_PAUSE seconds
        }
        $used = array_sum(array_filter($counted, 'is_int'));
        if ($used + $size > $this->share) {
            return false;
        }
        $sma = apcu_sma_info(true);
        // APCu gives the sizes as floats (seg_size, avail_mem), the count as an integer.
        $num = static fn (string $k): int => is_array($sma) && (is_int($sma[$k] ?? null) || is_float($sma[$k] ?? null)) ? (int) $sma[$k] : 0;
        $total = $num('num_seg') * $num('seg_size');
        $avail = $num('avail_mem');
        if ($total <= 0 || $avail - $size < intdiv($total, self::RESERVE)) {
            apcu_store($this->prefix . 'm:full', true, self::FULL_PAUSE);
            return false;
        }
        // Counted first: an answer that is here is always counted (the counter lives two hours).
        apcu_add($this->prefix . "m:$hour", 0, 7200);
        apcu_inc($this->prefix . "m:$hour", $size, $ok, 7200);
        return apcu_store($this->prefix . 'a:' . sha1($key), ['key' => $key, 'status' => $status, 'headers' => $headers, 'body' => $body, 'stored' => $stored,
            'expires' => $expires, 'tags' => $tags, 'born' => $born, 'kept' => $kept], $ttl);
    }

    /**
     * Makes every answer in memory out of date, for every process (the
     * command line's and the API's purges by path, or of everything): after
     * the disk's answers are removed, so an answer read from the disk before
     * that and put into memory counts as out of date too.
     */
    public static function forget(string $dir, bool $apcu, float $now): bool
    {
        return (new Tags($dir, $apcu))->purge([self::TAG], $now);
    }

    /** What the answers in memory take at most, in bytes (the two hours' counters). */
    public function bytes(float $now): int
    {
        $hour = intdiv((int) $now, 3600);
        $counted = apcu_fetch([$this->prefix . "m:$hour", $this->prefix . 'm:' . ($hour - 1)]);
        return is_array($counted) ? array_sum(array_filter($counted, 'is_int')) : 0;
    }
}
}
}

// plugins/cache/src/Tags.php
namespace CjwNetwork\RequestShield\Cache {
if (true) {
/**
 * Purge by tag without an index (0031 G.4, proposal 0039): for each tag the
 * time it was last purged. A kept answer remembers when its request began
 * and its tags; it is out of date when one of them -- or everything (ALL) --
 * was purged since. A purge is one write, whatever the number of pages,
 * and nothing is searched.
 *
 * The times are files (`<dir>/tags/ab/<md5>`), the truth that outlives a
 * restart and that every process sees; with APCu a copy of each time for
 * MEMORY seconds (0: never purged), so a hit asks the memory once for all
 * its tags. A purge from elsewhere -- the command line, whose APCu is its
 * own, another server on a shared folder -- counts once the copy has run
 * out; one APCu dropped is read again from the file. A purge time is kept
 * MAX_AGE -- no answer is kept longer.
 *
 * A hit asks first when anything was purged last (LAST: one file, or one
 * APCu read): before its answer was made, the answer is good, and no tag is
 * read -- the common case costs one read whatever the number of tags.
 */
final class Tags
{
    /** Everything: "purge all" (*, ez-all from X-Location-Id: *). */
    public const ALL = '*';

    /** The time of the last purge of any tag (a name no header can carry: tags are visible characters). */
    private const LAST = "\0last";

    /** The longest an answer is kept, and a purge remembered: 30 days. */
    public const MAX_AGE = 2592000;

    /** How long APCu keeps its copy of a purge time: what a purge from another process may lag behind. */
    public const MEMORY = 10;

    private string $prefix;

    public function __construct(private string $dir, private bool $apcu)
    {
        // Sites that share a PHP pool share APCu: the folder tells them apart.
        $this->prefix = 'rshield:hc:' . substr(md5($dir), 0, 12) . ':';
    }

    /**
     * Whether one of these tags, or everything, was purged at or after
     * $since (when the answer's request began): the time of the last purge
     * of any tag first, the tags only when that is not older.
     *
     * @param list<string> $tags
     */
    public function purgedSince(array $tags, float $since): bool
    {
        return $this->times([self::LAST]) >= $since && $this->newest($tags) >= $since;
    }

    /**
     * When one of these tags, or everything, was purged last (0.0: never).
     *
     * @param list<string> $tags
     */
    public function newest(array $tags): float
    {
        $tags[] = self::ALL;
        return $this->times($tags);
    }

    /**
     * The newest purge time of these names (0.0: none).
     *
     * @param list<string> $tags
     */
    private function times(array $tags): float
    {
        $newest = 0.0;
        if (!$this->apcu) {
            foreach ($tags as $tag) {
                $newest = max($newest, $this->fromFile($tag));
            }
            return $newest;
        }
        $keys = [];
        foreach ($tags as $tag) {
            $keys[$this->prefix . md5($tag)] = $tag;
        }
        $got = apcu_fetch(array_keys($keys));
        $got = is_array($got) ? $got : [];
        foreach ($keys as $key => $tag) {
            if (isset($got[$key]) && (is_float($got[$key]) || is_int($got[$key]))) {
                $time = (float) $got[$key];
            } else {
                // Not in memory (never asked, or dropped): the file says; a purge between
                // the read and this add has stored its time already, and the add does not win.
                $time = $this->fromFile($tag);
                apcu_add($key, $time, self::MEMORY);
            }
            $newest = max($newest, $time);
        }
        return $newest;
    }

    /**
     * Purges tags: every answer that carries one is out of date from now on.
     * Returns false when a time could not be written (nothing is lost for
     * the visitor: the answers then run out by their time to live).
     *
     * @param list<string> $tags
     */
    public function purge(array $tags, float $now): bool
    {
        $ok = true;
        // LAST after the tags: a reader that sees it new finds their times written.
        foreach ([...array_values(array_unique($tags)), self::LAST] as $tag) {
            // In file-mode, its folder in dir-mode (Files); '.tmp' as the cache's own, for the sweep.
            if (!\CjwNetwork\RequestShield\Files::write($this->file($tag), sprintf('%.6F', $now), '.tmp')) {
                $ok = false;
                continue;           // not in memory either: a purge that is said to have failed must not count for a while
            }
            if ($this->apcu) {
                apcu_store($this->prefix . md5($tag), $now, self::MEMORY);
            }
        }
        return $ok;
    }

    /** Removes the purge times older than MAX_AGE in one folder (ab), as FileCache sweeps. */
    public function sweep(string $folder, float $now): int
    {
        $n = 0;
        foreach (glob(rtrim($this->dir, '/') . "/tags/$folder/*") ?: [] as $file) {
            if ((int) @filemtime($file) < (int) $now - self::MAX_AGE) {
                $n += @unlink($file) ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * The tags of a header's value: split at whitespace and commas (as Ibexa's
     * handler does for xkey and X-Cache-Tags), each at most 200 bytes of
     * visible characters -- others are left out.
     *
     * @return list<string>
     * @psalm-taint-specialize (what a caller passes in comes back to that caller only: the dashboard's form is not the command line's)
     */
    public static function split(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            if (strlen($tag) <= 200 && preg_match('/^[\x21-\x7e]+$/', $tag) === 1) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    private function fromFile(string $tag): float
    {
        $v = @file_get_contents($this->file($tag));
        return $v !== false && is_numeric($v) ? (float) $v : 0.0;
    }

    private function file(string $tag): string
    {
        $h = md5($tag);
        return rtrim($this->dir, '/') . '/tags/' . substr($h, 0, 2) . "/$h";
    }
}
}
}
