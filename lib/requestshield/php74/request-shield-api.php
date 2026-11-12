<?php
/**
 * cjw-network/request-shield 0.4.0 -- api: the API plugin, loaded after request-shield.php.
 * One file, built by build/single-file.php (build: v0.4.0) from the repository's sources; do not edit.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see https://github.com/cjw-network/request-shield/blob/main/LICENSE
 *
 * Load it after request-shield.php; the API is on below <dashboard-path>/api/v1, guarded like the dashboard (set api off, set api-write on).
 */

declare(strict_types=1);

namespace {
    // Loaded already: nothing more. Without request-shield.php first, its classes could not be declared.
    if (class_exists('CjwNetwork\\RequestShield\\Api\\Api', false)) {
        return;
    }
    if (!class_exists('CjwNetwork\\RequestShield\\Shield', false)) {
        error_log('request-shield: ' . basename(__FILE__) . ' needs request-shield.php loaded first -- nothing loaded');
        return;
    }
}

// plugins/api/src/Api.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Tier;

if (true) {
/**
 * The API in the same process (RSF06-05): Api::call() answers exactly what
 * the HTTP endpoint sends as JSON -- the same dispatch, the same service,
 * the same check of who may ask. A CMS's backend calls it with its
 * administrator ('*') or a customer's group; a JavaScript page uses HTTP.
 *
 *   $answer = Api::call(Shield::active()->settings, 'GET', '/status');
 *   // ['data' => [...], 'meta' => ['version' => …, 'generated' => …, 'tier' => …]]
 *   // or a problem: ['type' => 'about:blank', 'title' => …, 'status' => 403, 'detail' => …]
 */
final class Api
{
    /**
     * @param array<string, mixed> $params the query (GET) or the JSON body (POST)
     * @param array{ruleFile?: ?string, now?: int, ip?: string, lang?: string} $ctx
     * @return array<string, mixed> the answer's body: the envelope, or a problem
     */
    public static function call(Settings $s, string $method, string $path, array $params = [], string $who = '*', array $ctx = []): array
    {
        return self::dispatch($s, $method, $path, $params, $who, $ctx)['body'];
    }

    /**
     * The answer: its status, headers of its own (Allow), and its body.
     *
     * @param array<string, mixed> $params
     * @param array{ruleFile?: ?string, now?: int, ip?: string, lang?: string} $ctx
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, data: bool}
     */
    public static function dispatch(Settings $s, string $method, string $path, array $params, string $who, array $ctx = []): array
    {
        $api = self::slot($s);
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($api === null || $api['enabled'] !== true) {
            return self::problem(new ApiProblem(404, 'Not found', 'The API is off here (set api off).'));
        }
        $ep = $api['endpoints']["$method $path"] ?? null;
        if ($ep === null) {
            $allow = [];
            foreach ($api['endpoints'] as $e) {
                if ($e['path'] === $path) {
                    $allow[] = $e['method'];
                }
            }
            return $allow === []
                ? self::problem(new ApiProblem(404, 'Not found', "No endpoint $path in this API (GET {$api['base']}/openapi.json lists them)."))
                : self::problem(new ApiProblem(405, 'Method not allowed', "$path takes " . implode(', ', $allow) . '.'), ['Allow: ' . implode(', ', $allow)]);
        }
        if ($ep['role'] === 'admin' && $who !== '*') {
            return self::problem(new ApiProblem(403, 'Forbidden', "$method $path is the administrator's."));
        }
        if ($ep['write'] && !$api['write']) {
            return self::problem(new ApiProblem(403, 'Forbidden', "$method $path changes something: the rule file has to allow it (set api-write on)."));
        }
        $service = $ep['service'];
        if (!class_exists($service) || !is_subclass_of($service, ApiService::class)) {
            return self::problem(new ApiProblem(501, 'Not implemented', "$method $path is not part of this build."));
        }
        $now = $ctx['now'] ?? time();
        $c = ['who' => $who, 'ruleFile' => $ctx['ruleFile'] ?? null, 'now' => $now, 'ip' => $ctx['ip'] ?? '', 'lang' => $ctx['lang'] ?? 'en'];
        try {
            $data = $service::handle($s, $params, $c);
        } catch (ApiProblem $p) {
            return self::problem($p);
        } catch (\Throwable $e) {
            // Fail safe: the API says that it failed, never why in detail (paths, values); the error log does.
            Shield::failed('api', "$method $path: " . $e->getMessage());
            return self::problem(new ApiProblem(500, 'Internal error', "$method $path failed; the server's error log has one line about it."));
        }
        if ($ep['write']) {
            self::audit($s, $method, $api['base'] . $path, $c);
        }
        $tier = Tier::of($s, is_string($c['ruleFile']) ? Settings::cacheDirFor($c['ruleFile']) : sys_get_temp_dir());
        return ['status' => 200, 'headers' => [], 'data' => true,
            'body' => ['data' => $data, 'meta' => ['version' => Shield::VERSION, 'generated' => gmdate('Y-m-d\TH:i:s\Z', $now), 'tier' => $tier['tier']]]];
    }

    /**
     * A write, for the record (0031 G.0): one line in the log (set log, at
     * every log level) and an event to every sink -- what changed, by
     * whom (the administrator), from which address. Never the parameters.
     *
     * @param array{who: string, ruleFile: ?string, now: int, ip: string, lang: string} $c
     */
    private static function audit(Settings $s, string $method, string $path, array $c): void
    {
        try {
            $request = Request::fromServer(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'HTTP_HOST' => 'request-shield-api',
                'REMOTE_ADDR' => @inet_pton($c['ip']) !== false ? $c['ip'] : '127.0.0.1', 'HTTP_USER_AGENT' => 'request-shield API (' . $c['who'] . ')']);
            $d = Decision::allowUncached('api write');
            Log::note($s, $request, $d, 'api-write', (float) $c['now']);
            if ($s->logFile !== null && !Log::wants($s->logLevel, $d)) {
                Log::write($s, $request, $d, 'api-write', (float) $c['now']);   // a change is on the record at every log level
            }
        } catch (\Throwable $e) {
            Shield::failed('api', "the write $method $path was done, its record failed: " . $e->getMessage());
        }
    }

    /**
     * The API's compiled slot, or null without the extension.
     *
     * @return array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}|null
     */
    public static function slot(Settings $s): ?array
    {
        $api = $s->ext['api'] ?? null;
        /** @var array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}|null */
        return is_array($api) && isset($api['endpoints'], $api['base']) ? $api : null;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, data: bool}
     */
    private static function problem(ApiProblem $p, array $headers = []): array
    {
        return ['status' => $p->status, 'headers' => $headers, 'body' => $p->body(), 'data' => false];
    }
}
}
}

// plugins/api/src/ApiExtension.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The API (RSF06-05, 0031 G.0): the shield's data as JSON below
 * <dashboard-path>/api/v1, for a CMS's backend, a JavaScript page, a script
 * or a language model -- guarded like every page of the dashboard (a
 * restrict rule, or a token: Authorization: Bearer). The core's endpoints
 * are this extension's own (ApiProvider); every other offered extension that
 * is an ApiProvider adds its own (the statistics: /stats/…). Compiled into
 * ext.api: which endpoints, where; a request that is none of them pays
 * nothing -- the routes lie below dashboard-path, as the pages.
 *
 *   set api off                 no API (on by default: it is as guarded as the pages)
 *   set api-write on            the endpoints that change something (lists, reload, feeds update): off by default
 *   set api-origins https://cms.example.org   other websites' pages that may call it from a browser (CORS); none by default
 */
final class ApiExtension implements Extension, ApiProvider
{
    /** Where the API lives below dashboard-path; its version. */
    public const BASE = '/api/v1';

    public static function id(): string
    {
        return 'api';
    }

    public static function vocabulary(Vocabulary $v): void
    {
        $v->set('api', 'bool', 'the API below <dashboard-path>/api/v1: on (default) or off', null, 'enabled');
        $v->set('api-write', 'bool', 'the API\'s endpoints that change something (lists, reload, feeds update): off (default) or on', null, 'write');
        $v->set('api-origins', 'words', 'websites whose pages may call the API from a browser (CORS): https://cms.example.org …',
            static function ($value, string $at): array {
                $out = [];
                foreach (is_array($value) ? $value : [] as $o) {
                    $o = is_scalar($o) ? rtrim(strtolower((string) $o), '/') : '';
                    if (preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $o) !== 1) {
                        throw new RuleFileException("$at: api-origins takes websites as a browser names them (https://cms.example.org), not \"$o\"");
                    }
                    $out[] = $o;
                }
                return $out;
            }, 'origins', true);
    }

    /**
     * The endpoints of every offered ApiProvider, where they are and who may
     * call them; the service classes by name. Two on one method and path refuse to compile.
     *
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}
     */
    public static function compile(array $raw, Settings $base): array
    {
        $enabled = $raw['enabled'] ?? true;
        $write = $raw['write'] ?? false;
        $origins = $raw['origins'] ?? [];
        if (!is_bool($enabled) || !is_bool($write)) {
            throw Settings::wrong('ext.api', 'enabled and write are true or false');
        }
        if (!is_array($origins) || array_filter($origins, static fn ($o): bool => !is_string($o) || preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $o) !== 1) !== []) {
            throw Settings::wrong('ext.api.origins', 'websites such as https://cms.example.org');
        }
        $endpoints = [];
        foreach (self::all() as $ep) {
            $key = $ep->method . ' ' . $ep->path;
            if (isset($endpoints[$key])) {
                throw Settings::wrong('ext.api', "two endpoints are $key");
            }
            $endpoints[$key] = ['method' => $ep->method, 'path' => $ep->path, 'role' => $ep->role, 'write' => $ep->write, 'service' => $ep->service, 'feature' => $ep->feature];
        }
        return ['enabled' => $enabled, 'write' => $write, 'origins' => array_values(array_filter($origins, 'is_string')), 'base' => $base->dashboardPath . self::BASE, 'endpoints' => $endpoints];
    }

    /** @return list<Endpoint> the core's endpoints first, then every other offered ApiProvider's */
    public static function all(): array
    {
        $out = self::api();
        foreach (Vocabulary::extensions() as $class) {
            if ($class !== self::class && is_subclass_of($class, ApiProvider::class)) {
                foreach ($class::api() as $ep) {
                    $out[] = $ep;
                }
            }
        }
        return $out;
    }

    public static function plugins(array $compiled): array
    {
        return [];
    }

    /**
     * One route per path (a path may have a GET and a POST): the API's host
     * answers it. The route's role is the least any of its endpoints needs;
     * the host checks each endpoint's own.
     */
    public static function routes(array $compiled): array
    {
        if (($compiled['enabled'] ?? true) !== true || !is_string($compiled['base'] ?? null)) {
            return [];
        }
        $routes = [];
        foreach ((array) ($compiled['endpoints'] ?? []) as $ep) {
            if (!is_array($ep) || !is_string($ep['path'] ?? null)) {
                continue;
            }
            $path = $compiled['base'] . $ep['path'];
            $reader = ($ep['role'] ?? 'admin') === 'reader' || (($routes[$path]['role'] ?? 'admin') === 'reader');
            $routes[$path] = ['key' => 'api', 'tab' => null, 'role' => $reader ? 'reader' : 'admin', 'order' => 900, 'page' => Host::class];
        }
        return $routes;
    }

    public static function commands(): array
    {
        return ['api' => Cli\ApiCommand::class];
    }

    /**
     * What keeps a script from the API: a site's own rules run first, as for
     * every request. Said only where a program may call it -- with tokens,
     * writes or api-origins; the office's browser on the dashboard needs none of it.
     */
    public static function check(Settings $s): array
    {
        $api = is_array($s->ext['api'] ?? null) ? $s->ext['api'] : [];
        // Only where a program may call it: with tokens (dashboard-access), writes, or other websites' pages.
        if (($api['enabled'] ?? true) !== true || ($s->dashboardAccess === [] && ($api['write'] ?? false) !== true && ($api['origins'] ?? []) === [])) {
            return [];
        }
        $base = is_string($api['base'] ?? null) ? $api['base'] : $s->dashboardPath . self::BASE;
        $probe = $base . '/status';
        $apiPath = false;
        foreach ($s->challenge->apiPaths as $p) {
            $apiPath = $apiPath || @preg_match($p, $probe) === 1;
        }
        $out = [];
        if (!$apiPath) {
            $out[] = "the API ($base) is no api-path: a script calling it gets the check page as HTML, and post-origin refuses its POST -- add \"api-path $base/**\"";
        }
        if ($s->methodPaths !== []) {
            $post = false;
            foreach ($s->methodPaths['POST'] ?? [] as $p) {
                $post = $post || @preg_match($p, $base . '/trace') === 1;
            }
            if (!$post) {
                $out[] = "allow POST … does not name the API: its POST endpoints (trace, test …) get 405 -- add \"allow POST $base/**\"";
            }
        }
        if ($s->queryStrict) {
            $out[] = "query strict: the API's parameters (cursor, days, q …) are unknown parameters -- add a match block for $base/** without query strict, or name them with query";
        }
        return $out;
    }

    /** The core's endpoints (RSF06-05): one service each, the same data the command line and the pages show. */
    public static function api(): array
    {
        $change = Service\ListsChange::SCHEMA;
        return [
            new Endpoint('GET', '/status', 'reader', false, Service\Status::class, Service\Status::SCHEMA, 'RSF06-05',
                'The version, the tier, the mode and the rule sets in force -- what request-shield version says.'),
            new Endpoint('GET', '/rules', 'admin', false, Service\Rules::class, Service\Rules::SCHEMA, 'RSF06-01',
                'Every rule with an ID: where it is written, what it does, its revision, how often it decided.', Service\Rules::PARAMS),
            new Endpoint('POST', '/trace', 'admin', false, Service\Trace::class, Service\Trace::SCHEMA, 'RSF06-01',
                'What happens to a request, check by check -- as request-shield trace; nothing is counted.', Service\Trace::PARAMS),
            new Endpoint('POST', '/test', 'admin', false, Service\Test::class, Service\Test::SCHEMA, 'RSF05-04',
                'Every example next to the rules, decided on a fresh store -- as request-shield test.', Service\Test::PARAMS),
            new Endpoint('POST', '/check', 'admin', false, Service\Check::class, Service\Check::SCHEMA, 'RSF05-01',
                'The rule files read again and compiled: the first mistake, or the warnings and the tier -- as request-shield check.'),
            new Endpoint('POST', '/reload', 'admin', true, Service\Reload::class, Service\Reload::SCHEMA, 'RSF05-01',
                'Check, then mark the main rule file changed: every server reads the rules on its next check.'),
            new Endpoint('GET', '/live', 'admin', false, Service\Live::class, Service\Live::SCHEMA, 'RSF06-02',
                'What was stopped since the cursor: the live view\'s rows.', Service\Live::PARAMS),
            new Endpoint('GET', '/lists', 'admin', false, Service\Lists::class, Service\Lists::SCHEMA, 'RSF01-02',
                'The addresses kept out and let in, newest first, searched; and the bans in force.', Service\Lists::PARAMS),
            new Endpoint('POST', '/lists', 'admin', true, Service\ListsChange::class, $change, 'RSF01-02',
                'An address kept out or let in, for a while or for good -- with the dashboard\'s checks.', Service\ListsChange::PARAMS_ADD),
            new Endpoint('POST', '/lists/update', 'admin', true, Service\ListsUpdate::class, $change, 'RSF01-02',
                'Another end or another note for an entry, by its ID.', Service\ListsUpdate::PARAMS),
            new Endpoint('POST', '/lists/remove', 'admin', true, Service\ListsRemove::class, $change, 'RSF01-02',
                'An entry taken out, by its ID.', Service\ListsRemove::PARAMS),
            new Endpoint('POST', '/lists/lift', 'admin', true, Service\ListsLift::class, $change, 'RSF01-02',
                'A ban lifted before its end, by its bucket.', Service\ListsLift::PARAMS),
            new Endpoint('GET', '/feeds', 'admin', false, Service\Feeds::class, Service\Feeds::SCHEMA, 'RSF01-03',
                'The public blocklists the rules name: action, entries, when fetched, whether in force.'),
            new Endpoint('POST', '/feeds/update', 'admin', true, Service\FeedsUpdate::class, Service\FeedsUpdate::SCHEMA, 'RSF01-03',
                'The lists that are due fetched now -- as request-shield feeds update.', Service\FeedsUpdate::PARAMS),
            new Endpoint('GET', '/crawlers', 'admin', false, Service\Crawlers::class, Service\Crawlers::SCHEMA, 'RSF01-04',
                'The known crawlers: kind, what the site does with each, how they are verified.'),
            new Endpoint('GET', '/log', 'admin', false, Service\Log::class, Service\Log::SCHEMA, 'RSF05-05',
                'The log\'s lines since the cursor, parsed: what was refused or checked, and why.', Service\Log::PARAMS),
            new Endpoint('GET', '/openapi.json', 'reader', false, Service\OpenApi::class, ['type' => 'object'], 'RSF06-05',
                'This API described as OpenAPI 3.1 (JSON): every endpoint, what it takes and what it answers -- the document itself, no envelope.'),
            new Endpoint('GET', '/openapi.yaml', 'reader', false, Service\OpenApi::class, ['type' => 'object'], 'RSF06-05',
                'The same as YAML.'),
        ];
    }
}
}
}

// plugins/api/src/Cli/ApiCommand.php
namespace CjwNetwork\RequestShield\Api\Cli {
use CjwNetwork\RequestShield\Api\Api;
use CjwNetwork\RequestShield\Api\OpenApi;
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;

if (true) {
/**
 * `request-shield api <main.rules> GET /status` calls an endpoint in this
 * process, as the administrator, and prints its answer (what a CMS's backend
 * gets from Api::call()); `--openapi` prints the API's description.
 */
final class ApiCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield api <main.rules> "GET|POST </path>" [--<param>=<value>]... | --openapi[=json|yaml]';
    }

    public static function run(Context $c): int
    {
        // Its own options from the arguments as given: --openapi, --<param>=<value>.
        $openapi = null;
        $params = [];
        $words = [];
        foreach (array_slice($c->args, 2) as $a) {
            if ($a === '--openapi' || $a === '--openapi=json' || $a === '--openapi=yaml') {
                $openapi = $a === '--openapi=yaml' ? 'yaml' : 'json';
            } elseif (preg_match('/^--([a-z][a-zA-Z0-9_-]*)=(.*)$/s', $a, $m) === 1) {
                $params[$m[1]] = $m[2];
            } elseif (strncmp($a, '--', 2) !== 0) {
                $words[] = $a;
            }
        }
        if ($openapi !== null) {
            $doc = OpenApi::document($c->settings->dashboardPath);
            echo $openapi === 'yaml' ? OpenApi::yaml($doc) : json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }
        $parts = preg_split('/\s+/', trim(implode(' ', $words))) ?: [];
        if (count($parts) !== 2 || !in_array(strtoupper($parts[0]), ['GET', 'POST'], true)) {
            fwrite(STDERR, 'usage: ' . self::usage() . "\n");
            return 2;
        }
        $answer = Api::call($c->settings, $parts[0], $parts[1], $params, '*', ['ruleFile' => $c->file, 'ip' => '127.0.0.1']);
        echo json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return isset($answer['data']) ? 0 : 1;
    }
}
}
}

// plugins/api/src/Host.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\RoutePage;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The API over HTTP (RSF06-05): the route every endpoint has. Dashboard::serve()
 * has checked who asks (a restrict rule, a token, a session) before it gets
 * here; this adds what an API needs -- the parameters from the query or the
 * JSON body, a cross-site check for a session's POST, CORS for the websites
 * api-origins names, an ETag on the data, and JSON for everything, problems
 * too (RFC 9457).
 */
final class Host implements RoutePage
{
    /** The largest JSON body read: the API takes parameters, not files. */
    public const MAX_BODY = 65536;

    public static function serve(Settings $s, Request $request, array $route, array $ctx): Response
    {
        $api = Api::slot($s);
        $path = $api !== null && strncasecmp($route['path'], $api['base'], strlen($api['base'])) === 0 ? (string) substr($route['path'], strlen($api['base'])) : $route['path'];
        $origin = (string) $request->header('origin');
        $cors = $api !== null && $origin !== '' && in_array(strtolower(rtrim($origin, '/')), $api['origins'], true)
            ? ['Access-Control-Allow-Origin: ' . $origin, 'Vary: Origin'] : [];
        if ($request->method === 'OPTIONS') {
            // A browser asks before a call from another website: only those api-origins names.
            return $cors === [] ? self::json(403, ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'Calls from another website need api-origins.'], [], true)
                : new Response(204, array_merge($cors, ['Access-Control-Allow-Methods: GET, POST', 'Access-Control-Allow-Headers: Authorization, Content-Type', 'Access-Control-Max-Age: 600']), '');
        }
        $bearer = strncasecmp((string) $request->header('authorization'), 'Bearer ', 7) === 0;
        if ($request->method === 'POST' && !$bearer && !self::sameSite($request, $origin, $api['origins'] ?? [])) {
            // A session's cookie goes with every request a browser sends: a POST must say it comes from here.
            return self::json(403, ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403,
                'detail' => 'A POST with a session needs an Origin of this website (or of api-origins); a script sends Authorization: Bearer instead.'], $cors, true);
        }
        $params = $request->method === 'POST' ? self::body($ctx['post']) : self::query($ctx['get']);
        if ($params === null) {
            return self::json(400, ['type' => 'about:blank', 'title' => 'Bad request', 'status' => 400, 'detail' => 'The body is no JSON object (Content-Type: application/json, at most 64 KB).'], $cors, true);
        }
        $answer = Api::dispatch($s, $request->method, $path, $params, $ctx['who'],
            ['ruleFile' => $ctx['ruleFile'], 'ip' => $ctx['ip'], 'lang' => $ctx['lang'] === 'de' ? 'de' : 'en']);
        // The description is the document itself, without the envelope: what OpenAPI tools read.
        if ($answer['data'] && $path === '/openapi.json') {
            return self::json(200, (array) ($answer['body']['data'] ?? []), $cors, false);
        }
        if ($answer['data'] && $path === '/openapi.yaml') {
            return new Response(200, array_merge(['Content-Type: application/yaml; charset=utf-8', 'X-Content-Type-Options: nosniff'], $cors), OpenApi::yaml((array) ($answer['body']['data'] ?? [])));
        }
        $headers = array_merge($cors, $answer['headers']);
        if ($answer['data']) {
            // The ETag of the data alone: "generated" changes every second, the data does not.
            $etag = '"' . substr(hash('sha256', (string) json_encode($answer['body']['data'] ?? null)), 0, 20) . '"';
            $headers[] = 'ETag: ' . $etag;
            if (trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
        }
        $response = self::json($answer['status'], $answer['body'], $headers, !$answer['data']);
        return $request->method === 'HEAD' ? new Response($response->status, $response->headers, '') : $response;
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $headers
     */
    private static function json(int $status, array $body, array $headers, bool $problem): Response
    {
        return new Response($status, array_merge(['Content-Type: application/' . ($problem ? 'problem+json' : 'json') . '; charset=utf-8', 'X-Content-Type-Options: nosniff'], $headers), (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * A POST's parameters: the JSON body, else the form's fields; null when the JSON is broken or too large.
     *
     * @param array<mixed> $post
     * @return array<string, mixed>|null
     */
    private static function body(array $post): ?array
    {
        $raw = (string) @file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (trim($raw) === '') {
            /** @var array<string, mixed> */
            return $post;
        }
        if (strlen($raw) > self::MAX_BODY) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) && $post !== []) {
            /** @var array<string, mixed> */
            return $post;                       // a form's fields (application/x-www-form-urlencoded)
        }
        /** @var array<string, mixed>|null */
        return is_array($data) && ($data === [] || array_keys($data) !== range(0, count($data) - 1)) ? $data : null;
    }

    /**
     * A GET's parameters: the query, without the dashboard's own lang.
     *
     * @param array<mixed> $get
     * @return array<string, mixed>
     */
    private static function query(array $get): array
    {
        $out = [];
        foreach ($get as $k => $v) {
            if (is_string($k) && $k !== 'lang') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param list<string> $origins */
    private static function sameSite(Request $request, string $origin, array $origins): bool
    {
        if ($origin === '' || $origin === 'null') {
            return false;
        }
        $o = strtolower(rtrim($origin, '/'));
        return $o === strtolower($request->scheme . '://' . $request->host) || in_array($o, $origins, true);
    }
}
}
}

// plugins/api/src/ListsChanges.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\Store;

if (true) {
/**
 * Changing the lists (proposal 0026; POST /lists/…): an entry added with a
 * comment, extended, changed, removed, a ban lifted -- through the same code
 * as the command line, with its guards and one more: never the address of
 * the person asking. A form's token (token()) is an HMAC of the shield's
 * secret, the viewer's address and the hour. The dashboard's lists page
 * (plugins/waf) sends its forms here.
 */
final class ListsChanges
{
    public const FOR = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000];

    /** What a change answers. */
    public const T = [
        'en' => [
            'f.good' => 'for good (keep out only)',
            'm.added' => '%s: %s added (%s).',
            'm.allowEnd' => 'An address let in needs an end — one let in for good belongs in the rule file (exempt).',
            'm.changed' => '%s changed.',
            'm.goodNote' => 'An entry for good needs a comment: why.',
            'm.lifted' => 'The ban of %s is lifted.',
            'm.noBan' => '%s is not banned.',
            'm.noEntry' => 'There is no entry %s.',
            'm.removed' => '%s removed.',
            'm.token' => 'The form was too old or not from this page — please try again.',
            'm.until' => 'Pick a day in the future.',
            'm.what' => 'Nothing to do.',
            'noDir' => 'There is no lists directory: set lists-dir (or store-dir) in the rule file.',
            'r.proxy' => '%s holds a trusted proxy (%s) — every visitor behind it would be kept out.',
            'r.self' => '%s holds your own address (%s) — you would lock yourself out.',
            'r.wide' => '%s is a wide range — many people may be behind it. Tick "a wide range: I mean it" if that is meant.%s',
            'untilAt' => 'until %s',
        ],
        'de' => [
            'f.good' => 'dauerhaft (nur aussperren)',
            'm.added' => '%s: %s eingetragen (%s).',
            'm.allowEnd' => 'Eine hereingelassene Adresse braucht ein Ende — eine dauerhaft hereingelassene gehört in die Regeldatei (exempt).',
            'm.changed' => '%s geändert.',
            'm.goodNote' => 'Ein dauerhafter Eintrag braucht einen Kommentar: warum.',
            'm.lifted' => 'Die Sperre von %s ist aufgehoben.',
            'm.noBan' => '%s ist nicht gesperrt.',
            'm.noEntry' => 'Es gibt keinen Eintrag %s.',
            'm.removed' => '%s entfernt.',
            'm.token' => 'Das Formular war zu alt oder nicht von dieser Seite — bitte noch einmal.',
            'm.until' => 'Bitte einen Tag in der Zukunft wählen.',
            'm.what' => 'Nichts zu tun.',
            'noDir' => 'Es gibt kein Listen-Verzeichnis: lists-dir (oder store-dir) in der Regeldatei setzen.',
            'r.proxy' => '%s enthält einen vertrauenswürdigen Proxy (%s) — jeder Besucher dahinter wäre ausgesperrt.',
            'r.self' => '%s enthält Ihre eigene Adresse (%s) — Sie würden sich selbst aussperren.',
            'r.wide' => '%s ist ein großer Bereich — dahinter können viele Menschen sein. „Ein großer Bereich: so gewollt“ ankreuzen, wenn das gemeint ist.%s',
            'untilAt' => 'bis %s',
        ],
    ];

    /** The form token for $ip in this hour: an HMAC of the shield's secret -- never the secret itself. */
    public static function token(Settings $s, string $ip, ?int $now = null): string
    {
        return self::sign($s, $ip, intdiv($now ?? time(), 3600));
    }

    /** A token from this hour or the last one. */
    public static function verify(Settings $s, string $ip, string $token, ?int $now = null): bool
    {
        $hour = intdiv($now ?? time(), 3600);
        return $token !== '' && (hash_equals(self::sign($s, $ip, $hour), $token) || hash_equals(self::sign($s, $ip, $hour - 1), $token));
    }

    private static function sign(Settings $s, string $ip, int $hour): string
    {
        return substr(hash_hmac('sha256', "lists|$ip|$hour", Secret::resolve($s->challenge->secret, $s->storeDir)), 0, 32);
    }

    /**
     * A change sent by the page's forms (POST): add, update, remove, lift.
     *
     * @param array<mixed> $post $_POST
     * @param array<string, mixed> $o ip (the viewer's address, required), user (who, for the note), ruleFile (touched after
     *                                writing, so every server reads the lists), store, csrfChecked, lang, now
     * @return array{ok: bool, message: string}
     */
    public static function handle(Settings $s, array $post, array $o): array
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $ip = is_string($o['ip'] ?? null) ? $o['ip'] : '';
        $v = static fn (string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
        if (!($o['csrfChecked'] ?? false) && !self::verify($s, $ip, $v('token'), $now)) {
            return ['ok' => false, 'message' => $t['m.token']];
        }
        $dir = $s->listsDir;
        if ($dir === null) {
            return ['ok' => false, 'message' => $t['noDir']];
        }
        $by = 'dashboard' . (is_string($o['user'] ?? null) && $o['user'] !== '' ? ' ' . $o['user'] : '');
        try {
            switch ($v('do')) {
                case 'add':
                    $kind = $v('kind') === 'exempt' ? 'exempt' : 'deny';
                    $address = $v('address');
                    Lists::check($address);
                    $until = self::until($v('for'), $v('until'), $now);
                    if ($until === false) {
                        return ['ok' => false, 'message' => $t['m.until']];
                    }
                    if ($kind === 'exempt' && $until === null) {
                        return ['ok' => false, 'message' => $t['m.allowEnd']];
                    }
                    if ($until === null && Lists::note($v('note')) === '') {
                        return ['ok' => false, 'message' => $t['m.goodNote']];
                    }
                    if ($kind === 'deny' && ($why = Lists::refusal($address, $s->trustedProxies, $ip, $v('confirm') !== '')) !== null) {
                        switch ($why[0]) {
                            case 'proxy':
                                $text = $t['r.proxy'];
                                break;
                            case 'self':
                                $text = $t['r.self'];
                                break;
                            default:
                                $text = $t['r.wide'];
                                break;
                        }
                        return ['ok' => false, 'message' => sprintf($text, $address, $why[1])];
                    }
                    $id = Lists::add($dir, $kind, $address, $until, $v('note'), $by);
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.added'], $id, $address, $until === null ? $t['f.good'] : sprintf($t['untilAt'], self::date($until, $lang)))];
                case 'update':
                    $id = $v('id');
                    $until = $v('for') === '' ? null : self::until($v('for'), $v('until'), $now);
                    if ($until === false) {
                        return ['ok' => false, 'message' => $t['m.until']];
                    }
                    $entry = null;
                    foreach (Lists::find($dir, "[$id]", 1)['entries'] as $e) {
                        $entry = $e['id'] === $id ? $e : null;
                    }
                    if ($entry === null) {
                        return ['ok' => false, 'message' => sprintf($t['m.noEntry'], $id)];
                    }
                    $note = array_key_exists('note', $post) ? $v('note') : null;
                    if ($v('for') === 'good' && $entry['kind'] === 'exempt') {
                        return ['ok' => false, 'message' => $t['m.allowEnd']];
                    }
                    if ($v('for') === 'good' && Lists::note($note ?? Lists::split($entry['note'])[0]) === '') {
                        return ['ok' => false, 'message' => $t['m.goodNote']];
                    }
                    Lists::update($dir, $id, $v('for') === 'good' ? 0 : $until, $note, $by);
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.changed'], $id)];
                case 'remove':
                    if (!Lists::removeId($dir, $v('id'))) {
                        return ['ok' => false, 'message' => sprintf($t['m.noEntry'], $v('id'))];
                    }
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.removed'], $v('id'))];
                case 'lift':
                    if (!Shield::liftBan($s, self::store($s, $o), $v('bucket'), (float) $now)) {
                        return ['ok' => false, 'message' => sprintf($t['m.noBan'], $v('bucket'))];
                    }
                    return ['ok' => true, 'message' => sprintf($t['m.lifted'], $v('bucket'))];
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => false, 'message' => $t['m.what']];
    }

    /**
     * Seconds from now, a day (to its end), for good (null) -- or false for a day that is not in the future.
     * @return int|false|null
     */
    private static function until(string $for, string $date, int $now)
    {
        if (isset(self::FOR[$for])) {
            return $now + self::FOR[$for];
        }
        if ($for === 'good') {
            return null;
        }
        $until = Lists::time($date);
        return $until !== null && $until > $now ? $until : false;
    }

    public static function date(int $t, string $lang): string
    {
        return date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $t);
    }

    /** @param array<string, mixed> $o */
    public static function store(Settings $s, array $o): Store
    {
        return ($o['store'] ?? null) instanceof Store ? $o['store'] : Shield::storeFor($s);
    }

    /** @param array<string, mixed> $o */
    private static function touch(array $o): void
    {
        if (is_string($o['ruleFile'] ?? null) && is_file($o['ruleFile'])) {
            @touch($o['ruleFile']);
        }
    }
}
}
}

// plugins/api/src/LiveRows.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\LogTail;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The live view's rows (proposal 0026, GET /live): what the shield stopped,
 * one row per request, newest first -- the website, the address, the
 * request, what happened, why in plain words and where the decision came
 * from. Read from the log's new lines (LogTail) or the live memory, so a
 * request pays nothing for it. The dashboard's live page (plugins/waf) shows
 * the same rows.
 */
final class LiveRows
{
    /** Rows one answer carries at most: the newest. */
    public const MAX_ROWS = 300;

    /** The words of a row: what happened, where it came from. */
    public const T = [
        'en' => [
            'w.refused' => 'refused',
            'w.banned' => 'banned',
            'w.paused' => 'told to wait',
            'w.checked' => 'checked',
            'w.uncached' => 'not cached',
            'w.passed' => 'let through',
            'w.watched' => 'watched: would be ',
            's.list' => 'list',
            's.ban' => 'ban',
            's.feed' => 'feed',
            's.own' => 'own rule',
            's.builtin' => 'built-in rule',
            's.pace' => 'pace',
            's.crawler' => 'crawler policy',
            's.shield' => 'basic check',
            'onList' => 'on the deny list',
            'onFeed' => 'on the public list %s',
        ],
        'de' => [
            'w.refused' => 'abgewiesen',
            'w.banned' => 'gesperrt',
            'w.paused' => 'zum Warten geschickt',
            'w.checked' => 'geprüft',
            'w.uncached' => 'nicht gecacht',
            'w.passed' => 'durchgelassen',
            'w.watched' => 'beobachtet: wäre ',
            's.list' => 'Liste',
            's.ban' => 'Sperre',
            's.feed' => 'Feed',
            's.own' => 'eigene Regel',
            's.builtin' => 'eingebaute Regel',
            's.pace' => 'Tempo',
            's.crawler' => 'Crawler-Regel',
            's.shield' => 'Grundprüfung',
            'onList' => 'auf der Sperrliste',
            'onFeed' => 'auf der öffentlichen Liste %s',
        ],
    ];

    /**
     * The new rows since $cursor (null: the end of the log), as the page shows them.
     *
     * @param array<string, mixed> $o lang (en, de), ip (the viewer's address: no "keep out" for a range that holds it),
     *                                links (the dashboard's pages, Frame::links(): a rule's ID links to its line on "rules", a list entry to "lists")
     * @return array{cursor: string, rows: list<array<string, mixed>>, skipped: int, log: bool, memory?: bool}
     */
    public static function json(Settings $s, ?string $cursor, array $o = []): array
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $memory = self::fromMemory($s);
        if (!$memory && $s->logFile === null) {
            return ['cursor' => '', 'rows' => [], 'skipped' => 0, 'log' => false];
        }
        // The memory (set live on: APCu, or live.log in store-dir): full addresses, the last hour; else the log's new lines.
        $tail = $memory ? \CjwNetwork\RequestShield\Live::read($s, $cursor, self::MAX_ROWS)
            : LogTail::read((string) $s->logFile, $cursor);
        // Not the dashboard's own requests that went through (its pages, this
        // feed every few seconds): the view would mostly show itself.
        $raw = [];
        foreach ($tail['rows'] as $r) {
            if (($r['action'] === 'allow-uncached' || $r['action'] === 'allow') && Frame::isPage($s, (string) parse_url($r['url'], PHP_URL_PATH))) {
                continue;
            }
            $raw[] = $r;
        }
        $raw = array_slice($raw, -self::MAX_ROWS);
        // The comments of the list entries named, read once for all rows.
        $ids = [];
        foreach ($raw as $r) {
            if ($r['reason'] === 'denied' && $r['rule'] !== null && strncmp($r['rule'], 'LIST-', 5) === 0) {
                $ids[$r['rule']] = true;
            }
        }
        $notes = $ids !== [] && $s->listsDir !== null ? Lists::notes($s->listsDir, array_keys($ids)) : [];
        $viewer = is_string($o['ip'] ?? null) ? $o['ip'] : null;
        $links = [];
        foreach ((array) ($o['links'] ?? []) as $k => $v) {
            if (is_string($v)) {
                $links[(string) $k] = $v;
            }
        }
        $rows = [];
        foreach ($raw as $r) {
            $row = self::row($s, $r, $lang, $notes, $links);
            if ($viewer !== null && is_string($row['keep']) && \CjwNetwork\RequestShield\IpAddress::inRanges($viewer, [$row['keep']])) {
                $row['keep'] = null;                        // never offered: it would lock out the person looking
            }
            $rows[] = $row;
        }
        return ['cursor' => $tail['cursor'], 'rows' => $rows, 'skipped' => $memory ? $tail['skipped'] : (int) round($tail['skipped'] / 1024), 'log' => true, 'memory' => $memory];
    }

    /** Whether the rows come from the live memory (set live on: the APCu ring, or live.log in store-dir) rather than the log. */
    public static function fromMemory(Settings $s): bool
    {
        return \CjwNetwork\RequestShield\Live::keeps($s);
    }

    /**
     * One log line as a row: website, what happened, why, where from.
     *
     * @param array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string, ref?: ?string} $r
     * @param array<string, string> $notes list entry ID => its comment
     * @param array<string, string> $links the dashboard's pages: rules (a rule's line), lists (a list entry)
     * @return array<string, mixed>
     */
    public static function row(Settings $s, array $r, string $lang, array $notes = [], array $links = []): array
    {
        $t = self::T[$lang];
        $watched = strncmp($r['action'], 'monitor-', 8) === 0;
        $action = $watched ? (string) substr($r['action'], 8) : $r['action'];
        switch ($action) {
            case 'reject':
                $what = 'refused';
                break;
            case 'throttle':
                $what = $r['reason'] === 'banned' ? 'banned' : 'paused';
                break;
            case 'challenge':
                $what = 'checked';
                break;
            case 'allow-uncached':
                $what = 'uncached';
                break;
            default:
                $what = 'passed';
                break;
        }
        $parts = parse_url($r['url']);
        $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : '';
        $request = (is_array($parts) ? ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') : $r['url']);
        $source = self::source($s, $r);
        $reason = Describe::reason($r['reason'], $lang);
        $why = $reason;
        if ($source === 'list' && $r['rule'] !== null) {
            $note = $notes[$r['rule']] ?? ($s->origins['text'][$r['rule']] ?? '');
            $why = $t['onList'] . ($note !== '' ? ': ' . $note : '');
        } elseif ($source === 'feed' && $r['rule'] !== null && !isset($s->origins['text'][$r['rule']])) {
            foreach (array_merge($s->feeds, $s->monitor !== null ? $s->monitor->feeds : []) as $f) {
                if ($f['rule'] === $r['rule']) {
                    $why = sprintf($t['onFeed'], $f['title']);
                    break;
                }
            }
        } elseif ($r['rule'] !== null && isset($s->origins['text'][$r['rule']]) && $s->origins['text'][$r['rule']] !== '') {
            $why = (string) $s->origins['text'][$r['rule']];
        }
        $site = $s->sites === [] || $host === '' ? null : $s->siteFor(['SERVER_NAME' => $host, 'HTTP_HOST' => $host]);
        return [
            't' => $r['time'], 'time' => date('H:i:s', $r['time']), 'day' => date($lang === 'de' ? 'd.m.Y' : 'Y-m-d', $r['time']),
            'host' => $host, 'site' => $site, 'client' => $r['client'], 'method' => $r['method'], 'request' => $request,
            'what' => $what, 'watched' => $watched, 'status' => $r['status'],
            'label' => ($watched ? $t['w.watched'] : '') . $t['w.' . $what] . ($what !== 'passed' && $what !== 'uncached' ? ' ' . $r['status'] : ''),
            'why' => $why, 'reason' => $reason, 'source' => $source, 'sourceLabel' => $t['s.' . $source], 'rule' => $r['rule'],
            'keep' => $r['client'] !== '-' && $source !== 'list' ? $r['client'] : null, 'agent' => $r['agent'],
            'ruleHref' => self::ruleHref($s, $r['rule'], $links),
        ];
    }

    /**
     * Where a rule is written, as a link: a list entry on the lists page
     * (searched for its ID), a basic check on the way of a request, any other
     * rule on its line of the rules page (#rule-<ID>, its file opened there).
     *
     * @param array<string, string> $links
     */
    private static function ruleHref(Settings $s, ?string $rule, array $links): ?string
    {
        if ($rule === null || $rule === '') {
            return null;
        }
        if (strncmp($rule, 'LIST-', 5) === 0 && isset($links['lists'])) {
            return $links['lists'] . '?' . http_build_query(['q' => "[$rule]"]);
        }
        if (!isset($links['rules'])) {
            return null;
        }
        $id = Describe::ruleInfo($s, str_replace(' ', '_', $rule))['id'];
        return $links['rules'] . ($id === 'built-in' || $id === 'application' ? '#way' : '#rule-' . Describe::anchor($id));
    }

    /**
     * Where a decision came from: list, ban, feed, own (the site's rule files),
     * builtin (the shipped rules), pace (a budget), crawler (the crawler
     * policy), shield (a basic check without a rule).
     *
     * @param array{reason: string, rule: ?string, action: string} $r
     */
    public static function source(Settings $s, array $r): string
    {
        $rule = $r['rule'];
        if ($r['reason'] === 'banned') {
            return 'ban';
        }
        if ($r['reason'] === 'denied') {
            return 'list';
        }
        if ($r['reason'] === 'feed' || ($rule !== null && strncmp($rule, 'FEED-', 5) === 0)) {
            return 'feed';
        }
        if ($r['reason'] === 'crawler') {
            return 'crawler';
        }
        if (Describe::isBudget($r['reason'])) {
            return 'pace';
        }
        if ($rule === null) {
            return 'shield';
        }
        $at = (string) ($s->origins['at'][$rule] ?? $rule);
        return strncmp($at, 'built-in ', 9) === 0 || strncmp($rule, 'built-in ', 9) === 0 ? 'builtin' : 'own';
    }
}
}
}

// plugins/api/src/OpenApi.php
namespace CjwNetwork\RequestShield\Api {
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Shield;

if (true) {
/**
 * The API described (RSF06-05): an OpenAPI 3.1 document from the endpoints
 * every ApiProvider offers -- what CMS adapters, scripts and language models
 * read -- and the same as YAML and as the reference page in the docs.
 */
final class OpenApi
{
    /** @return array<string, mixed> */
    public static function document(string $dashboardPath = '/rs'): array
    {
        $paths = [];
        foreach (ApiExtension::all() as $ep) {
            $op = [
                'summary' => $ep->summary,
                'tags' => [$ep->feature],
                'x-role' => $ep->role,
            ];
            $docs = Help::url($ep->feature);
            if ($docs !== null) {
                $op['externalDocs'] = ['url' => $docs];
            }
            if ($ep->write) {
                $op['x-write'] = true;
            }
            $params = [];
            foreach ($ep->params as $name => $p) {
                $params[] = ['name' => $name, 'in' => $ep->method === 'GET' ? 'query' : 'body', 'required' => $p['required'] ?? false, 'description' => $p['about'], 'schema' => ['type' => $p['type'] === 'int' ? 'integer' : ($p['type'] === 'bool' ? 'boolean' : 'string')]];
            }
            if ($ep->method === 'GET' && $params !== []) {
                $op['parameters'] = $params;
            }
            if ($ep->method === 'POST' && $params !== []) {
                $props = [];
                $required = [];
                foreach ($params as $p) {
                    $props[$p['name']] = $p['schema'] + ['description' => $p['description']];
                    if ($p['required']) {
                        $required[] = $p['name'];
                    }
                }
                $op['requestBody'] = ['required' => $required !== [], 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props] + ($required !== [] ? ['required' => $required] : [])]]];
            }
            $op['responses'] = [
                '200' => ['description' => 'The answer', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
                    'data' => $ep->schema, 'meta' => ['$ref' => '#/components/schemas/Meta']]]]]],
                'default' => ['description' => 'A problem (RFC 9457)', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]],
            ];
            $paths[$ep->path][strtolower($ep->method)] = $op;
        }
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'request-shield', 'version' => Shield::VERSION,
                'description' => 'The shield\'s data: status, rules, the live view, the lists, the statistics. Guarded like the dashboard: a restrict rule, or Authorization: Bearer <token>.'],
            'servers' => [['url' => $dashboardPath . ApiExtension::BASE]],
            'security' => [['bearer' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'request-shield access-token <main.rules> "*"']],
                'schemas' => [
                    'Meta' => ['type' => 'object', 'required' => ['version', 'generated', 'tier'], 'properties' => ['version' => ['type' => 'string'], 'generated' => ['type' => 'string'], 'tier' => ['type' => 'string']]],
                    'Problem' => ['type' => 'object', 'required' => ['type', 'title', 'status', 'detail'], 'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'detail' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    /**
     * A document as YAML: maps and lists in blocks, every string in double
     * quotes as JSON writes it (valid YAML), keys bare where YAML lets them be.
     *
     * @param array<mixed> $doc
     */
    public static function yaml(array $doc): string
    {
        return self::block($doc, 0);
    }

    /** @param array<mixed> $value */
    private static function block(array $value, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $list = $value !== [] && array_keys($value) === range(0, count($value) - 1);
        $out = '';
        foreach ($value as $k => $v) {
            $head = $list ? $pad . '- ' : $pad . self::key((string) $k) . ':';
            if (is_object($v)) {
                $v = (array) $v;
            }
            if (is_array($v) && $v !== []) {
                $out .= $list ? $head . "\n" . self::block($v, $depth + 1) : $head . "\n" . self::block($v, $depth + 1);
            } else {
                $out .= $head . ($list ? '' : ' ') . self::scalar($v) . "\n";
            }
        }
        return $out;
    }

    private static function key(string $k): string
    {
        return preg_match('#^[A-Za-z_$][A-Za-z0-9_./{}$-]*$#', $k) === 1 && !in_array(strtolower($k), ['y', 'n', 'yes', 'no', 'true', 'false', 'on', 'off', 'null'], true)
            ? $k : (string) json_encode($k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param mixed $v
     */
    private static function scalar($v): string
    {
        if (is_array($v)) {
            return '[]';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return (string) json_encode(is_scalar($v) ? (string) $v : '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
}
}

// plugins/api/src/Service/Check.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Cli;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Tier;

if (true) {
/** POST /check: the rule files read again and compiled -- the first mistake, or the warnings and the tier; as request-shield check. Nothing is changed. */
final class Check implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['ok', 'error', 'warnings', 'tier'], 'properties' => [
        'ok' => ['type' => 'boolean'], 'error' => ['type' => ['string', 'null']],
        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']], 'tier' => ['type' => ['string', 'null']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return self::run(Params::ruleFile($ctx['ruleFile']));
    }

    /** @return array{ok: bool, error: ?string, warnings: list<string>, tier: ?string} */
    public static function run(string $file): array
    {
        try {
            $read = RuleFile::read([$file]);
            $settings = Settings::from($read['config']);
        } catch (\InvalidArgumentException $e) {
            // The mistake by the file's name and line: never the server's paths.
            return ['ok' => false, 'error' => Params::relative($e->getMessage(), $file), 'warnings' => [], 'tier' => null];
        }
        $tier = Tier::of($settings, Settings::cacheDirFor($file));
        return ['ok' => true, 'error' => null, 'warnings' => array_map(static fn (string $w): string => Params::relative($w, $file), array_merge(Cli::warnings($settings, $read, $file), $tier['off'])),
            'tier' => $tier['tier']];
    }
}
}
}

// plugins/api/src/Service/Crawlers.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /crawlers: the known crawlers -- kind, what the site does with each, how they are verified, how old their address lists are. */
final class Crawlers implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['verify', 'crawlers'], 'properties' => [
        'verify' => ['type' => 'string'],
        'crawlers' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'kind', 'policy', 'dns', 'lists'], 'properties' => [
            'id' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'policy' => ['type' => 'string'],
            'dns' => ['type' => 'array', 'items' => ['type' => 'string']], 'lists' => ['type' => 'object'],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $out = [];
        foreach ($s->crawlers as $id => $x) {
            $lists = [];
            foreach ($x['lists'] as $name => $about) {
                $lists[(string) $name] = ['created' => is_string($about['created'] ?? null) ? $about['created'] : null, 'from' => is_string($about['from'] ?? null) ? $about['from'] : null];
            }
            $out[] = ['id' => (string) $id, 'kind' => $x['kind'], 'policy' => $x['policy'], 'dns' => $x['dns'], 'lists' => (object) $lists];
        }
        return ['verify' => $s->crawlerVerify, 'crawlers' => $out];
    }
}
}
}

// plugins/api/src/Service/Feeds.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /feeds: the public blocklists the rules name -- action, entries, when fetched, whether in force. */
final class Feeds implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['feeds'], 'properties' => [
        'feeds' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name', 'title', 'action', 'count', 'fetched', 'state', 'rule', 'watched'], 'properties' => [
            'name' => ['type' => 'string'], 'title' => ['type' => 'string'], 'action' => ['type' => 'string'], 'count' => ['type' => 'integer'],
            'fetched' => ['type' => 'integer'], 'state' => ['type' => 'string'], 'rule' => ['type' => 'string'], 'watched' => ['type' => 'boolean'],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $out = [];
        foreach ([[$s->feeds, false], [$s->monitor !== null ? $s->monitor->feeds : [], true]] as [$feeds, $watched]) {
            foreach ($feeds as $f) {
                $out[] = ['name' => $f['name'], 'title' => $f['title'], 'action' => $f['action'], 'count' => $f['count'], 'fetched' => $f['fetched'],
                    'state' => $f['state'], 'rule' => $f['rule'], 'watched' => $watched];
            }
        }
        return ['feeds' => $out];
    }
}
}
}

// plugins/api/src/Service/FeedsUpdate.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Http;
use CjwNetwork\RequestShield\Rules\Feeds as FeedFiles;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /feeds/update: the lists that are due fetched now -- as request-shield feeds update (usually cron's job). A write. */
final class FeedsUpdate implements ApiService
{
    public const PARAMS = ['force' => ['type' => 'bool', 'about' => 'keep a list that shrank to less than half, and fetch one not yet due']];

    public const SCHEMA = ['type' => 'object', 'required' => ['feeds'], 'properties' => ['feeds' => ['type' => 'object']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $named = array_merge($s->feeds, $s->monitor !== null ? $s->monitor->feeds : []);
        if ($named === []) {
            return ['feeds' => (object) []];
        }
        if (($offline = Http::offline()) !== null) {
            throw new ApiProblem(503, 'Service unavailable', $offline);
        }
        $done = FeedFiles::update($named, $s->storeDir . '/feeds', null, Params::bool($params, 'force'));
        foreach ($done as $happened) {
            if (strncmp($happened, 'updated', 7) === 0 && is_string($ctx['ruleFile']) && is_file($ctx['ruleFile'])) {
                @touch($ctx['ruleFile']);                     // every server reads them within its recheck
                break;
            }
        }
        return ['feeds' => (object) $done];
    }
}
}
}

// plugins/api/src/Service/Lists.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Rules\Lists as ListFiles;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;

if (true) {
/** GET /lists: the addresses kept out and let in (the list files), newest first, searched; and the bans in force. */
final class Lists implements ApiService
{
    public const PARAMS = [
        'q' => ['type' => 'string', 'about' => 'only entries whose line holds this: an address, an ID, a word of the note'],
        'limit' => ['type' => 'int', 'about' => 'at most this many entries: 1 to 500 (default 100)'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['entries', 'total', 'bans'], 'properties' => [
        'entries' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['kind', 'id', 'addresses', 'until', 'note'], 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['deny', 'exempt']], 'id' => ['type' => 'string'], 'addresses' => ['type' => 'array', 'items' => ['type' => 'string']],
            'until' => ['type' => ['integer', 'null']], 'note' => ['type' => 'string'],
        ]]],
        'total' => ['type' => 'integer'],
        'bans' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['bucket', 'until'], 'properties' => ['bucket' => ['type' => 'string'], 'until' => ['type' => 'integer']]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        if ($s->listsDir === null) {
            throw new ApiProblem(409, 'Conflict', 'There is no lists directory: set lists-dir (or store-dir) in the rule file.');
        }
        $found = ListFiles::find($s->listsDir, Params::string($params, 'q', '', 128), Params::int($params, 'limit', 100, 1, 500));
        $bans = [];
        if ($s->bans !== []) {
            foreach (Shield::storeFor($s)->marks('ban:', (float) $ctx['now']) as $key => $until) {
                $bans[] = ['bucket' => (string) substr((string) $key, 4), 'until' => (int) $until];
            }
        }
        return ['entries' => $found['entries'], 'total' => $found['total'], 'bans' => $bans];
    }
}
}
}

// plugins/api/src/Service/ListsChange.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Api\ListsChanges;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * POST /lists, /lists/update, /lists/remove, /lists/lift: a change to the
 * lists, as the dashboard's lists page makes it (the same checks: no trusted
 * proxy, not the caller's own address, a wide range only with confirm) --
 * every server reads it on its next check. A write: set api-write on.
 */
final class ListsChange implements ApiService
{
    public const PARAMS_ADD = [
        'kind' => ['type' => 'string', 'about' => 'deny (keep out, default) or exempt (let in)'],
        'address' => ['type' => 'string', 'required' => true, 'about' => 'an address or a range: 203.0.113.7, 198.51.100.0/24'],
        'for' => ['type' => 'string', 'about' => '1h, 1d, 7d (default), 30d, date (with until), good (for good: a note is needed)'],
        'until' => ['type' => 'string', 'about' => 'with for=date: 2026-10-07 or 2026-10-07T15:30'],
        'note' => ['type' => 'string', 'about' => 'why: shown in the lists and the live view'],
        'confirm' => ['type' => 'bool', 'about' => 'a wide range is meant'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['ok', 'message'], 'properties' => ['ok' => ['type' => 'boolean'], 'message' => ['type' => 'string']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return self::change($s, 'add', $params, $ctx);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{who: string, ruleFile: ?string, now: int, ip: string, lang: string} $ctx
     * @return array{ok: bool, message: string}
     */
    public static function change(Settings $s, string $do, array $params, array $ctx): array
    {
        $post = ['do' => $do];
        foreach ($params as $k => $v) {
            if (is_scalar($v)) {
                $post[$k] = $k === 'confirm' ? (Params::bool($params, 'confirm') ? '1' : '') : (string) $v;
            }
        }
        $post['do'] = $do;
        if ($do === 'add' && ($post['for'] ?? '') === '') {
            $post['for'] = '7d';                // as the page's form starts: a week
        }
        $done = ListsChanges::handle($s, $post, ['ip' => $ctx['ip'], 'user' => 'api', 'ruleFile' => $ctx['ruleFile'], 'csrfChecked' => true, 'lang' => $ctx['lang'], 'now' => $ctx['now']]);
        if (!$done['ok']) {
            throw new ApiProblem(409, 'Conflict', $done['message']);
        }
        return $done;
    }
}
}
}

// plugins/api/src/Service/ListsLift.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /lists/lift: a ban lifted before its end, by its bucket (an IPv4 address, an IPv6 /64). */
final class ListsLift implements ApiService
{
    public const PARAMS = ['bucket' => ['type' => 'string', 'required' => true, 'about' => 'as GET /lists names it under bans']];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'bucket', null, 64);
        return ListsChange::change($s, 'lift', $params, $ctx);
    }
}
}
}

// plugins/api/src/Service/ListsRemove.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /lists/remove: an entry taken out, by its ID. */
final class ListsRemove implements ApiService
{
    public const PARAMS = ['id' => ['type' => 'string', 'required' => true, 'about' => 'the entry\'s ID: LIST-D3']];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'id', null, 64);
        return ListsChange::change($s, 'remove', $params, $ctx);
    }
}
}
}

// plugins/api/src/Service/ListsUpdate.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /lists/update: another end or another note for an entry, by its ID. */
final class ListsUpdate implements ApiService
{
    public const PARAMS = [
        'id' => ['type' => 'string', 'required' => true, 'about' => 'the entry\'s ID: LIST-D3'],
        'for' => ['type' => 'string', 'about' => '1h, 1d, 7d, 30d, date (with until), good'],
        'until' => ['type' => 'string', 'about' => 'with for=date: 2026-10-07 or 2026-10-07T15:30'],
        'note' => ['type' => 'string', 'about' => 'the new note'],
    ];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'id', null, 64);
        return ListsChange::change($s, 'update', $params, $ctx);
    }
}
}
}

// plugins/api/src/Service/Live.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Api\LiveRows;
use CjwNetwork\RequestShield\Routes;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /live: what was stopped since the cursor -- the live view's rows (the log, or the live memory with set live on). */
final class Live implements ApiService
{
    public const PARAMS = ['cursor' => ['type' => 'string', 'about' => 'where the last call ended (its answer\'s cursor); none: the newest rows']];

    public const SCHEMA = ['type' => 'object', 'required' => ['cursor', 'rows', 'skipped', 'log'], 'properties' => [
        'cursor' => ['type' => 'string'], 'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
        'skipped' => ['type' => 'integer'], 'log' => ['type' => 'boolean'], 'memory' => ['type' => 'boolean'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $cursor = Params::string($params, 'cursor', '', 64);
        // A rule's ID links to its row on Rules & setup, an entry to the lists -- the dashboard's pages, without a front controller's prefix.
        return LiveRows::json($s, $cursor === '' ? null : $cursor, ['lang' => $ctx['lang'], 'ip' => $ctx['ip'], 'links' => Routes::links($s)]);
    }
}
}
}

// plugins/api/src/Service/Log.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\LogTail;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /log: the log's lines since the cursor, parsed -- what was refused or checked, and why. */
final class Log implements ApiService
{
    public const PARAMS = ['cursor' => ['type' => 'string', 'about' => 'where the last call ended (its answer\'s cursor); none: the end of the log']];

    public const SCHEMA = ['type' => 'object', 'required' => ['cursor', 'rows', 'skipped'], 'properties' => [
        'cursor' => ['type' => 'string'], 'skipped' => ['type' => 'integer'],
        'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['time', 'client', 'action', 'status', 'reason', 'rule', 'method', 'url'], 'properties' => [
            'time' => ['type' => 'integer'], 'client' => ['type' => 'string'], 'action' => ['type' => 'string'], 'status' => ['type' => 'integer'],
            'reason' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']], 'claimed' => ['type' => ['string', 'null']],
            'method' => ['type' => 'string'], 'url' => ['type' => 'string'], 'agent' => ['type' => 'string'], 'ref' => ['type' => ['string', 'null']],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        if ($s->logFile === null) {
            throw new ApiProblem(409, 'Conflict', 'There is no log: set log <file> in the rule file.');
        }
        $cursor = Params::string($params, 'cursor', '', 64);
        return LogTail::read($s->logFile, $cursor === '' ? null : $cursor);
    }
}
}
}

// plugins/api/src/Service/OpenApi.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\Api\OpenApi as Document;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /openapi.json: this API described as OpenAPI 3.1 -- the endpoints the compiled settings have, the plugins' included. */
final class OpenApi implements ApiService
{
    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return Document::document($s->dashboardPath);
    }
}
}
}

// plugins/api/src/Service/Params.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;

if (true) {
/** The parameters of a call, checked: a missing or wrong one is a 400 that names it. */
final class Params
{
    /** @param array<string, mixed> $p */
    public static function string(array $p, string $name, ?string $default = null, int $max = 2048): string
    {
        $v = $p[$name] ?? null;
        if ($v === null || $v === '') {
            if ($default === null) {
                throw new ApiProblem(400, 'Bad request', "$name is missing.");
            }
            return $default;
        }
        if (!is_scalar($v) || strlen((string) $v) > $max) {
            throw new ApiProblem(400, 'Bad request', "$name is a text of at most $max characters.");
        }
        return trim((string) $v);
    }

    /** @param array<string, mixed> $p */
    public static function int(array $p, string $name, int $default, int $min, int $max): int
    {
        $v = $p[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
            throw new ApiProblem(400, 'Bad request', "$name is a whole number from $min to $max.");
        }
        return (int) $v;
    }

    /** @param array<string, mixed> $p */
    public static function bool(array $p, string $name): bool
    {
        $v = $p[$name] ?? false;
        if (is_bool($v)) {
            return $v;
        }
        if (in_array($v, ['1', 'true', 'on', 'yes', 1], true)) {
            return true;
        }
        if (in_array($v, ['', '0', 'false', 'off', 'no', 0, null], true)) {
            return false;
        }
        throw new ApiProblem(400, 'Bad request', "$name is true or false.");
    }

    /**
     * A message without the server's paths: a file below the rule file's
     * folder by its path from there, any other by its name.
     */
    public static function relative(string $text, ?string $ruleFile): string
    {
        $dir = $ruleFile !== null ? rtrim(dirname($ruleFile), '/') . '/' : null;
        if ($dir !== null && $dir !== '/') {
            $text = str_replace($dir, '', $text);
        }
        return (string) preg_replace_callback('#(?<![\w.])/(?:[^\s:"\'/()]+/)+([^\s:"\'/()]+)#', static fn (array $m): string => $m[1], $text);
    }

    /** The main rule file, or a 409: settings from a PHP array have none to read, test or touch. */
    public static function ruleFile(?string $file): string
    {
        if ($file === null || !is_file($file)) {
            throw new ApiProblem(409, 'Conflict', 'The shield runs from no rule file here (settings from a PHP array): nothing to read, test or reload.');
        }
        return $file;
    }
}
}
}

// plugins/api/src/Service/Reload.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /reload: check, then mark the main rule file changed -- every server reads the rules on its next check; as request-shield reload. */
final class Reload implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['reloaded', 'warnings'], 'properties' => [
        'reloaded' => ['type' => 'boolean'], 'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $file = Params::ruleFile($ctx['ruleFile']);
        $check = Check::run($file);
        if (!$check['ok']) {
            throw new ApiProblem(409, 'Conflict', 'The rules do not compile, nothing reloaded: ' . $check['error']);
        }
        if (!@touch($file)) {
            throw new ApiProblem(500, 'Internal error', 'The rule file cannot be marked changed (the web server may not write it).');
        }
        return ['reloaded' => true, 'warnings' => $check['warnings']];
    }
}
}
}

// plugins/api/src/Service/Rules.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Counts;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** GET /rules: every rule with an ID -- where it is written, what it does, its revision, how often it decided. */
final class Rules implements ApiService
{
    public const PARAMS = ['days' => ['type' => 'int', 'about' => 'the days the counts are of: 1 to 400 (default 7)']];

    public const SCHEMA = ['type' => 'object', 'required' => ['days', 'rules'], 'properties' => [
        'days' => ['type' => 'integer'],
        'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'where', 'text', 'builtIn', 'decided'], 'properties' => [
            'id' => ['type' => 'string'], 'where' => ['type' => 'string'], 'text' => ['type' => ['string', 'null']], 'revision' => ['type' => ['integer', 'null']],
            'builtIn' => ['type' => 'boolean'], 'decided' => ['type' => ['integer', 'null']],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $days = Params::int($params, 'days', 7, 1, 400);
        $counts = Counts::rules($s, $days, (float) $ctx['now']);
        $rules = [];
        foreach ((array) ($s->origins['at'] ?? []) as $id => $at) {
            $id = (string) $id;
            $rev = $s->origins['rev'][$id] ?? null;
            $rules[] = ['id' => $id, 'where' => Params::relative((string) $at, $ctx['ruleFile']), 'text' => $s->origin('text', $id), 'revision' => is_numeric($rev) ? (int) $rev : null,
                'builtIn' => strncmp((string) $at, 'built-in ', 9) === 0, 'decided' => $counts === [] ? null : ($counts[$id] ?? 0)];
        }
        return ['days' => $days, 'rules' => $rules];
    }
}
}
}

// plugins/api/src/Service/Status.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Tier;

if (true) {
/** GET /status: what request-shield version says -- the version, PHP, the tier and store, the mode, the rule sets. */
final class Status implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['version', 'php', 'apcu', 'tier', 'store', 'mode', 'ruleSets', 'sites'], 'properties' => [
        'version' => ['type' => 'string'], 'php' => ['type' => 'string'], 'apcu' => ['type' => 'boolean'],
        'tier' => ['type' => 'string', 'enum' => ['S0', 'S1', 'S2']], 'store' => ['type' => 'string'],
        'mode' => ['type' => 'string', 'enum' => ['off', 'monitor', 'enforce', 'strict']],
        'ruleSets' => ['type' => 'object'], 'sites' => ['type' => 'integer'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $tier = Tier::of($s, is_string($ctx['ruleFile']) ? Settings::cacheDirFor($ctx['ruleFile']) : sys_get_temp_dir());
        $sets = [];
        foreach ((array) ($s->origins['versions'] ?? []) as $name => $v) {
            $sets[(string) $name] = (string) $v;
        }
        return [
            'version' => Shield::VERSION, 'php' => PHP_VERSION, 'apcu' => ApcuStore::usable(), 'tier' => $tier['tier'], 'store' => $tier['store'],
            'mode' => $s->mode, 'ruleSets' => (object) $sets, 'sites' => count(array_unique(array_values($s->sites))),
        ];
    }
}
}
}

// plugins/api/src/Service/Test.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /test: every example next to the rules, decided on a fresh store -- as request-shield test; nothing is counted. */
final class Test implements ApiService
{
    public const PARAMS = [
        'only' => ['type' => 'string', 'about' => 'the examples of this rule ID only'],
        'asWritten' => ['type' => 'bool', 'about' => 'monitor as written, not as enforce'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['examples', 'pass', 'fail', 'skip', 'results', 'without'], 'properties' => [
        'examples' => ['type' => 'integer'], 'pass' => ['type' => 'integer'], 'fail' => ['type' => 'integer'], 'skip' => ['type' => 'integer'],
        'results' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['status', 'method', 'url', 'expected', 'got', 'at'], 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['pass', 'fail', 'skip']], 'rule' => ['type' => ['string', 'null']], 'method' => ['type' => 'string'], 'url' => ['type' => 'string'],
            'expected' => ['type' => 'string'], 'got' => ['type' => 'string'], 'why' => ['type' => 'string'], 'at' => ['type' => 'string'],
        ]]],
        'without' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $file = Params::ruleFile($ctx['ruleFile']);
        $only = Params::string($params, 'only', '', 64);
        $run = Examples::run([$file], Params::bool($params, 'asWritten'), $only !== '' ? $only : null);
        $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];
        $results = [];
        foreach ($run['results'] as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $x = $r['example'];
            $results[] = ['status' => $r['status'], 'rule' => $r['about'], 'method' => $x['method'], 'url' => $x['url'],
                'expected' => $x['outcome'] . ($x['by'] !== null ? ' by ' . $x['by'] : ''), 'got' => $r['got'] . ($r['gotRule'] !== null ? ' by ' . $r['gotRule'] : ''),
                'why' => $r['why'], 'at' => basename((string) preg_replace('/:\d+$/', '', $x['at'])) . (preg_match('/:(\d+)$/', $x['at'], $m) === 1 ? ':' . $m[1] : '')];
        }
        return ['examples' => count($results)] + $counts + ['results' => $results, 'without' => $only !== '' ? [] : $run['without']];
    }
}
}
}

// plugins/api/src/Service/Trace.php
namespace CjwNetwork\RequestShield\Api\Service {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Settings;

if (true) {
/** POST /trace: what happens to a request, check by check -- as request-shield trace; nothing is counted. */
final class Trace implements ApiService
{
    public const PARAMS = [
        'url' => ['type' => 'string', 'required' => true, 'about' => 'a full address or a path: https://www.example.org/wp-login.php'],
        'method' => ['type' => 'string', 'about' => 'GET (default), POST, …'],
        'ip' => ['type' => 'string', 'about' => 'the visitor\'s address (default 198.51.100.7)'],
        'ua' => ['type' => 'string', 'about' => 'the User-Agent'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['method', 'url', 'ip', 'steps', 'verdict', 'rule', 'status', 'passes'], 'properties' => [
        'method' => ['type' => 'string'], 'url' => ['type' => 'string'], 'ip' => ['type' => 'string'],
        'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['check', 'key', 'state', 'text', 'rule'], 'properties' => [
            'check' => ['type' => 'string'], 'key' => ['type' => 'string'], 'state' => ['type' => 'string', 'enum' => ['pass', 'note', 'stop', 'skip']],
            'text' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']],
        ]]],
        'verdict' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']], 'watched' => ['type' => ['string', 'null']],
        'status' => ['type' => 'integer'], 'passes' => ['type' => 'boolean'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $method = strtoupper(Params::string($params, 'method', 'GET', 10));
        $url = Params::string($params, 'url');
        $ip = Params::string($params, 'ip', '198.51.100.7', 64);
        $ua = Params::string($params, 'ua', '', 512);
        if (preg_match('/^[A-Z]{1,10}$/', $method) !== 1 || @inet_pton($ip) === false) {
            throw new ApiProblem(400, 'Bad request', 'method is a word such as GET, ip an IP address.');
        }
        $t = (new Inspector($s))->trace(Inspector::request($method, $url, $ip, $ua !== '' ? ['User-Agent' => $ua] : []));
        return ['method' => $method, 'url' => $url, 'ip' => $ip, 'steps' => $t['steps'], 'verdict' => $t['verdict'], 'rule' => $t['rule'], 'watched' => $t['watched'],
            'status' => $t['decision']->status, 'passes' => $t['decision']->passes()];
    }
}
}
}
