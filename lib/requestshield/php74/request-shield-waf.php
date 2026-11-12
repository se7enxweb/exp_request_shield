<?php
/**
 * cjw-network/request-shield 0.4.0 -- waf: the firewall's pages (Rules & setup, Live, Lists) and the examples command, loaded after request-shield.php and request-shield-api.php.
 * One file, built by build/single-file.php (build: v0.4.0) from the repository's sources; do not edit.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see https://github.com/cjw-network/request-shield/blob/main/LICENSE
 *
 * Load it after request-shield.php; the pages are below <dashboard-path>/waf, guarded like the dashboard; they read their data through the API's classes (request-shield-api.php).
 */

declare(strict_types=1);

namespace {
    // Loaded already: nothing more. Without request-shield.php first, its classes could not be declared.
    if (class_exists('CjwNetwork\\RequestShield\\Waf\\Cli\\ExamplesCommand', false)) {
        return;
    }
    if (!class_exists('CjwNetwork\\RequestShield\\Shield', false)) {
        error_log('request-shield: ' . basename(__FILE__) . ' needs request-shield.php loaded first -- nothing loaded');
        return;
    }
}

// plugins/waf/src/Cli/ExamplesCommand.php
namespace CjwNetwork\RequestShield\Waf\Cli {
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Waf\DemoSite;
use CjwNetwork\RequestShield\Waf\ExamplesPage;

if (true) {
/**
 * `request-shield examples <main.rules>`: the "# demo:" groups of a rule file
 * (one per feature, 0031 F.4/F.5) -- as Markdown for the docs, as a page that
 * needs no server (recorded by test), or what is covered.
 */
final class ExamplesCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield examples <main.rules> --markdown [--feature=RSF02-06] | --html [--out=<file>] | --coverage';
    }

    public static function run(Context $c): int
    {
        $show = $c->option('show', []);
        $show = is_array($show) ? $show : [];
        $feature = is_string($show['feature'] ?? null) ? $show['feature'] : null;
        $out = is_string($show['out'] ?? null) ? $show['out'] : null;
        try {
            $groups = DemoSite::groups($c->file);
            if ($feature !== null) {
                // One feature's groups, in every form; an id with none is a mistake, never an empty table.
                $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['id'] === $feature));
                if ($groups === []) {
                    fwrite(STDERR, "request-shield: no \"# demo: $feature\" group in {$c->file}\n");
                    return 2;
                }
            }
            $run = ($show['html'] ?? false) || ($show['coverage'] ?? false) ? Examples::run(array_merge($c->sources, [$c->file])) : ['results' => [], 'without' => []];
        } catch (RuleFileException | \InvalidArgumentException $e) {
            fwrite(STDERR, 'request-shield: ' . $e->getMessage() . "\n");
            return 2;
        }
        if ($show['markdown'] ?? false) {
            echo ExamplesPage::markdown($groups);
            return 0;
        }
        if ($show['html'] ?? false) {
            $results = [];
            foreach ($run['results'] as $r) {
                $results[$r['example']['at']] = ['status' => $r['status'], 'got' => $r['got'], 'gotRule' => $r['gotRule'], 'http' => $r['http']];
            }
            $page = ExamplesPage::html($groups, $results, 'request-shield demo -- ' . basename($c->file),
                'Recorded by request-shield test, ' . Shield::VERSION . ' (' . Shield::BUILD . '): every row decided on a fresh store, as the rules say. The live demo answers the same: php -S 127.0.0.1:8080 examples/demo/router.php');
            if ($out !== null) {
                if (@file_put_contents($out, $page) === false) {
                    fwrite(STDERR, "request-shield: cannot write $out\n");
                    return 2;
                }
                echo "written: $out\n";
            } else {
                echo $page;
            }
            return 0;
        }
        if ($show['coverage'] ?? false) {
            // Per feature group: rows, decided, passing; then the rules without an example.
            foreach ($groups as $g) {
                $expects = array_filter($g['rows'], static fn (array $r): bool => $r['kind'] === 'expect');
                $ok = 0;
                foreach ($run['results'] as $r) {
                    foreach ($expects as $x) {
                        $ok += $r['example']['at'] === $x['at'] && $r['status'] === 'pass' ? 1 : 0;
                    }
                }
                echo str_pad($g['id'], 10) . str_pad((string) count($expects), 4, ' ', STR_PAD_LEFT) . ' examples, ' . $ok . ' pass, ' . (count($g['rows']) - count($expects)) . ' to look at  ' . $g['title'] . "\n";
            }
            echo "\n" . count($run['without']) . ' rule(s) without an example' . ($run['without'] !== [] ? ': ' . implode(', ', $run['without']) : '') . "\n";
            return 0;
        }
        fwrite(STDERR, 'usage: ' . self::usage() . "\n");
        return 2;
    }
}
}
}

// plugins/waf/src/DemoSite.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Responder;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

if (true) {
/**
 * The base a demo site stands on (0031 F.4): its rows -- read from its rule
 * file's "# demo:" groups, not from a PHP table -- and the answer to a row,
 * decided here. The demo's own pages stay the demo's (where it lives is
 * worked out in its index.php, before the shield's classes are loaded).
 *
 * The answer is decided in the same process with the live settings and the
 * live store, nothing counted (Inspector::trace()): a request to the demo's
 * own address from inside a request would never be answered by PHP's built-in
 * server, which serves one request at a time.
 *
 * @phpstan-type Row array{n: string, kind: string, method: string, url: string, outcome: ?string, by: ?string, from: ?string, pass: bool, times: int, ua: ?string, headers: array<string, string>, text: string, at: string, site: ?string}
 * @phpstan-type Group array{n: int, id: string, slug: string, title: string, about: list<string>, rows: list<Row>}
 */
final class DemoSite
{
    /** Where the examples come from when they name no address: a documentation range, as in request-shield test. */
    public const FROM = RuleFile::EXAMPLE_FROM;

    /**
     * The demo's groups, numbered for the page: each "# demo:" group of the
     * rule file with its explanation and its rows -- the expect lines and the
     * "# try:" rows, in the order they are written.
     *
     * @return list<Group>
     */
    public static function groups(string $rulesFile): array
    {
        $read = RuleFile::read([$rulesFile]);
        $base = basename($rulesFile) . ':';
        // The base's examples, then each site block's (as request-shield test reads them): an
        // example inside a site block belongs to that website and is decided with its rules.
        $examples = [];
        foreach ($read['examples'] as $x) {
            $examples[$x['at']] = $x;
        }
        foreach ((array) ($read['config']['sites'] ?? []) as $site) {
            foreach (is_string($site) ? RuleFile::read([$rulesFile], $site)['examples'] : [] as $x) {
                if ($x['site'] !== null) {
                    $examples[$x['at']] = $x;
                }
            }
        }
        $rows = [];
        foreach ($examples as $x) {
            if ($x['demo'] !== null && strncmp($x['at'], $base, strlen($base)) === 0) {
                $rows[] = ['demo' => $x['demo'], 'line' => (int) substr($x['at'], strlen($base)), 'row' => ['kind' => 'expect', 'method' => $x['method'], 'url' => $x['url'],
                    'outcome' => $x['outcome'], 'by' => $x['by'], 'from' => $x['from'], 'pass' => $x['pass'], 'times' => $x['times'], 'ua' => $x['ua'], 'headers' => $x['headers'],
                    'text' => (string) $x['text'], 'at' => $x['at'], 'site' => $x['site']]];
            }
        }
        foreach ($read['tries'] as $t) {
            if ($t['demo'] !== null && strncmp($t['at'], $base, strlen($base)) === 0) {
                $rows[] = ['demo' => $t['demo'], 'line' => (int) substr($t['at'], strlen($base)), 'row' => ['kind' => 'try', 'method' => $t['method'], 'url' => $t['url'],
                    'outcome' => null, 'by' => null, 'from' => null, 'pass' => false, 'times' => 1, 'ua' => null, 'headers' => [], 'text' => $t['text'], 'at' => $t['at'], 'site' => null]];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $out = [];
        foreach ($read['demos'] as $i => $g) {
            $start = (int) substr($g['at'], strlen($base));
            $end = isset($read['demos'][$i + 1]) ? (int) substr($read['demos'][$i + 1]['at'], strlen($base)) : PHP_INT_MAX;
            $mine = [];
            foreach ($rows as $r) {
                if ($r['demo'] === $g['id'] && $r['line'] > $start && $r['line'] < $end) {
                    $mine[] = ['n' => ($i + 1) . '.' . (count($mine) + 1)] + $r['row'];
                }
            }
            $out[] = ['n' => $i + 1, 'id' => $g['id'], 'slug' => $g['slug'], 'title' => $g['title'], 'about' => $g['about'], 'rows' => $mine];
        }
        return $out;
    }

    /**
     * A row by its number ("3.2"), or null.
     *
     * @param list<Group> $groups
     * @return Row|null
     */
    public static function row(array $groups, string $n): ?array
    {
        foreach ($groups as $g) {
            foreach ($g['rows'] as $r) {
                if ($r['n'] === $n) {
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * What a row's request gets, decided now with the live settings and store
     * (nothing counted) -- for a row of a site block, $s is that website's:
     * the request as the browser sends it, the outcome as an expect line
     * writes it, the status and the headers the shield sends, what it does
     * with cookies, the verdict in words, the rule, and the steps.
     *
     * @param Row $row
     * @return array{outcome: string, status: int, statusText: string, headers: list<string>, verdict: string, rule: ?string, watched: ?string, steps: list<string>, from: string,
     *   request: list<string>, cookies: list<string>}
     */
    public static function answer(Settings $s, ?Store $store, array $row, string $front, string $host): array
    {
        $from = $row['from'] ?? self::FROM;
        $headers = $row['headers'];
        // A row of a site block: that website's address (its rules are in $s, see the caller).
        $url = $row['url'][0] === '/' ? 'http://' . ($row['site'] ?? $host) . $front . $row['url'] : $row['url'];
        $request = self::requestLines($row['method'], $url, $headers, $row['ua'], $row['pass']);
        if ($row['times'] > 1) {
            $request[] = "(sent {$row['times']} times in a row from that address; the answer is the last one's)";
        }
        if ($row['times'] > 1 || $row['pass']) {
            // A count or a pass: decided as request-shield test does, on a fresh store
            // (the live one would carry this visitor's own counters).
            $x = ['method' => $row['method'], 'url' => $row['url'], 'outcome' => (string) $row['outcome'], 'by' => $row['by'], 'rule' => null, 'from' => $from,
                'pass' => $row['pass'], 'times' => $row['times'], 'headers' => $row['headers'], 'text' => null, 'at' => $row['at'], 'site' => $row['site'], 'ua' => $row['ua'], 'demo' => null];
            $r = Examples::one($s, $x);
            $how = ($row['pass'] ? 'with a pass' : '') . ($row['pass'] && $row['times'] > 1 ? ', ' : '') . ($row['times'] > 1 ? $row['times'] . ' requests in a row' : '');
            $passed = $r['http'] < 300;
            return ['outcome' => $r['got'], 'status' => $r['http'], 'statusText' => self::statusText($r['http']),
                'headers' => $passed ? self::passingHeaders($s, $r['got'] === 'uncached', $r['gotRule'], $r['got'] === 'uncached' ? 'allow-uncached' : 'allow') : $r['headers'],
                'verdict' => ExamplesPage::expected(['outcome' => $r['got'], 'by' => null, 'from' => null, 'pass' => false, 'times' => 1, 'ua' => null] + $row),
                'rule' => $r['gotRule'], 'watched' => null, 'steps' => ["decided on a fresh store, $how -- as request-shield test does"], 'from' => $from,
                'request' => $request, 'cookies' => self::cookies($s, $r['got'] === 'check', $row['pass'])];
        }
        if ($row['ua'] !== null) {
            $headers['user-agent'] = $row['ua'];
        }
        $t = (new Inspector($s, $store))->trace(Inspector::request($row['method'], $url, $from, $headers, $s->trustedProxies));
        $d = $t['decision'];
        $steps = [];
        foreach ($t['steps'] as $step) {
            $steps[] = (['stop' => '✕ ', 'skip' => '– ', 'note' => '! '][$step['state']] ?? '✓ ') . $step['check'] . ': ' . $step['text'] . ($step['rule'] !== null ? ' [' . $step['rule'] . ']' : '');
        }
        $status = $d->passes() ? 200 : $d->status;
        return ['outcome' => Examples::outcome($d), 'status' => $status, 'statusText' => self::statusText($status),
            'headers' => $d->passes() ? self::passingHeaders($s, !$d->cacheable(), $t['rule'], $d->action . ($d->reason !== '' ? ' ' . $d->reason : '')) : Responder::headerLines($d, $s->debugHeader, $t['rule']),
            'verdict' => $t['verdict'], 'rule' => $t['rule'], 'watched' => $t['watched'], 'steps' => $steps, 'from' => $from,
            'request' => $request, 'cookies' => self::cookies($s, $d->action === Decision::CHALLENGE, false)];
    }

    /**
     * The request as the browser sends it: the request line, Host, the headers
     * of the row, the User-Agent, and the pass cookie when the row has one.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function requestLines(string $method, string $url, array $headers, ?string $ua, bool $pass): array
    {
        $p = parse_url($url);
        $target = (is_array($p) ? ($p['path'] ?? '/') : '/') . (is_array($p) && isset($p['query']) ? '?' . $p['query'] : '');
        $out = [strtoupper($method) . " $target HTTP/1.1", 'Host: ' . (is_array($p) ? ($p['host'] ?? '') : '')];
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'user-agent') {
                $out[] = implode('-', array_map('ucfirst', explode('-', strtolower($name)))) . ': ' . $value;
            }
        }
        $out[] = 'User-Agent: ' . ($ua ?? $headers['user-agent'] ?? Examples::USER_AGENT);
        if ($pass) {
            $out[] = 'Cookie: rsp=2.…   (the pass cookie from an earlier browser check)';
        }
        return $out;
    }

    /**
     * What the shield adds to an answer the site makes: only its debug header
     * (set debug-header on); the rest is the site's own.
     *
     * @return list<string>
     */
    private static function passingHeaders(Settings $s, bool $uncached, ?string $rule, string $what): array
    {
        $out = [];
        if ($s->debugHeader) {
            $out[] = 'X-RS: ' . $what . ($rule !== null ? '; rule=' . $rule : '');
        }
        $out[] = '… the rest is the site\'s own answer' . ($uncached ? ' -- not for a cache: the site asks Shield::current()->cacheable() and sends Cache-Control: no-store' : '');
        return $out;
    }

    /**
     * What the shield does with cookies for this answer: the browser check
     * needs them (an answer for minutes, then the pass); a pass is sent along;
     * else none is set or needed.
     *
     * @return list<string>
     */
    private static function cookies(Settings $s, bool $check, bool $pass): array
    {
        $c = $s->challenge;
        if ($check) {
            return ['Needs cookies: the check page keeps its answer in "' . $c->solutionCookie . '" (minutes, used once); the next request then gets',
                'Set-Cookie: ' . $c->cookie . '=… (the pass, for ' . Describe::span($c->passTtl, 'en') . '; HttpOnly, SameSite=Lax) -- so the visitor is not asked again.',
                'Technically necessary for the security of the site, no tracking: a signed time and a tag of the address, nothing read from the device (docs/privacy.md).'];
        }
        if ($pass) {
            return ['Sends "' . $c->cookie . '", the pass from an earlier check: the request is let through without a new one, until the pass ends.'];
        }
        return ['No cookie: the shield sets none and needs none for this answer.'];
    }

    /** The reason phrase of a status. */
    private static function statusText(int $status): string
    {
        return [200 => 'OK', 204 => 'No Content', 303 => 'See Other', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 414 => 'URI Too Long', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large'][$status] ?? '';
    }
}
}
}

// plugins/waf/src/Diagram.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Decision;

if (true) {
/**
 * Diagrams as SVG, drawn here -- no library, no external file: the path of a
 * request through the checks (Inspector::trace()), the browser check step by
 * step, and how the shield sits in front of a site. Light and dark.
 */
final class Diagram
{
    /** Short names for the checks, to fit under a circle. */
    private const SHORT = [
        'Kept out' => 'Denied', 'Public lists' => 'Lists', 'Banned' => 'Banned', 'Kind of request' => 'Kind', 'Size' => 'Size', 'Disguised address' => 'Disguise', 'Website name' => 'Name',
        'Addresses only attackers ask for' => 'Blocked', 'Where forms may be sent' => 'Forms',
        'Areas for certain visitors' => 'Areas', 'Known crawlers' => 'Crawlers', 'Known parameters' => 'Params', 'Attack patterns' => 'Attacks', 'May a cache keep the answer?' => 'Cache', 'Browser check' => 'Check',
    ];

    /** The same in German, and the diagram's other words. */
    private const SHORT_DE = [
        'Denied' => 'Sperrliste', 'Lists' => 'Listen', 'Banned' => 'Sperre', 'Kind' => 'Art', 'Size' => 'Größe', 'Disguise' => 'Tarnung', 'Name' => 'Name', 'Blocked' => 'Gesperrt', 'Forms' => 'Formulare', 'Areas' => 'Bereiche',
        'Crawlers' => 'Crawler', 'Params' => 'Parameter', 'Attacks' => 'Angriffe', 'Cache' => 'Cache', 'Check' => 'Check', 'Pace' => 'Tempo',
        'Request' => 'Anfrage', 'Your site' => 'Ihre Website', 'a cache may keep it' => 'ein Cache darf sie behalten', 'not kept in a cache' => 'nicht im Cache',
        'Browser check' => 'Browser-Check', 'Please wait' => 'Bitte warten', 'the site never sees it' => 'die Website sieht sie nie',
    ];

    private const STYLE = '<style>'
        . '.rsd{font:13px/1.2 system-ui,sans-serif}.rsd .t{fill:#1d2127}.rsd .m{fill:#5b6470}.rsd .box{fill:#fff;stroke:#c9ced6}'
        . '.rsd .line{stroke:#c9ced6;stroke-width:3;fill:none}.rsd .go{stroke:#1e7b43}.rsd .arrow{stroke:#5b6470;stroke-width:1.6;fill:none}'
        . '.rsd .pass{fill:#1e7b43}.rsd .note{fill:#b07400}.rsd .stop{fill:#a3361f}.rsd .skip{fill:#b5bcc5}'
        . '.rsd .okbox{fill:#e6f4ea;stroke:#1e7b43}.rsd .warnbox{fill:#fdf3dc;stroke:#b07400}.rsd .nobox{fill:#fbe9e5;stroke:#a3361f}'
        . '.rsd .lane{stroke:#c9ced6;stroke-dasharray:4 4}.rsd .w{fill:#fff;font-weight:700}'
        . '@media (prefers-color-scheme:dark){.rsd .t{fill:#e7e9ec}.rsd .m{fill:#a0a8b3}.rsd .box{fill:#1d2127;stroke:#3a414b}'
        . '.rsd .line,.rsd .lane{stroke:#3a414b}.rsd .arrow{stroke:#a0a8b3}.rsd .okbox{fill:#17301f}.rsd .warnbox{fill:#3a2f15}.rsd .nobox{fill:#3d1f19}.rsd .skip{fill:#555c66}}'
        . '</style>';

    /**
     * The house style and the arrow head, for a diagram drawn elsewhere -- the
     * docs' diagrams from their text (docs/tools/diagram.php, 0031 F.7) look
     * like the pages' own, in light and in dark.
     */
    public static function style(string $marker = 'rsd-c'): string
    {
        return self::STYLE . '<defs><marker id="' . $marker . '" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">'
            . '<path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /**
     * A request's path: the request, a circle per check, and where it ends --
     * the site, or the answer the shield gives itself.
     *
     * @param array{steps: list<array{check: string, key?: string, state: string, text: string, rule: ?string}>, decision: Decision, verdict: string, rule: ?string} $trace
     */
    public static function trace(array $trace, string $request, string $lang = 'en'): string
    {
        $tr = static fn (string $en): string => $lang === 'de' ? (self::SHORT_DE[$en] ?? $en) : $en;
        $steps = $trace['steps'];
        $d = $trace['decision'];
        $gap = 76;
        $x0 = 150;
        $w = $x0 + count($steps) * $gap + 170;
        $y = 60;
        $h = '<svg class="rsd" role="img" viewBox="0 0 ' . $w . ' 130" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>' . self::e($request . ': ' . $trace['verdict']) . '</title>' . self::STYLE;
        // The request
        $h .= '<rect class="box" x="4" y="' . ($y - 24) . '" width="118" height="48" rx="8"/>'
            . '<text class="t" x="63" y="' . ($y - 4) . '" text-anchor="middle" font-weight="600">' . self::e($tr('Request')) . '</text>'
            . '<text class="m" x="63" y="' . ($y + 13) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($request, 18)) . '</text>';
        // The line: green as far as the request got, grey after
        $stopAt = null;
        foreach ($steps as $i => $s) {
            if ($s['state'] === 'stop') {
                $stopAt = $i;
                break;
            }
        }
        $lastX = $x0 + (count($steps) - 1) * $gap;
        $goX = $stopAt === null ? $lastX + 60 : $x0 + $stopAt * $gap;
        $h .= '<path class="line" d="M122 ' . $y . ' H' . ($lastX + 60) . '"/>'
            . '<path class="line go" d="M122 ' . $y . ' H' . $goX . '"/>';
        foreach ($steps as $i => $s) {
            $cx = $x0 + $i * $gap;
            $key = $s['key'] ?? $s['check'];              // the step's English name
            $label = isset(self::SHORT[$key]) ? $tr(self::SHORT[$key]) : (strncmp($key, 'Pace', 4) === 0 ? $tr('Pace') : self::cut($s['check'], 9));
            $mark = ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'][$s['state']] ?? '';
            $h .= '<g><title>' . self::e($s['check'] . ': ' . $s['text'] . ($s['rule'] !== null ? ' (' . $s['rule'] . ')' : '')) . '</title>'
                . '<circle class="' . $s['state'] . '" cx="' . $cx . '" cy="' . $y . '" r="16"/>'
                . '<text class="w" x="' . $cx . '" y="' . ($y + 5) . '" text-anchor="middle">' . $mark . '</text>'
                . '<text class="' . ($s['state'] === 'skip' ? 'm' : 't') . '" x="' . $cx . '" y="' . ($y + 38) . '" text-anchor="middle" font-size="12">' . self::e($label) . '</text></g>';
        }
        // Where it ends
        $ex = $lastX + 60;
        if ($d->passes()) {
            $class = $d->action === Decision::ALLOW ? 'okbox' : 'warnbox';
            $title = $tr('Your site');
            $sub = $tr($d->action === Decision::ALLOW ? 'a cache may keep it' : 'not kept in a cache');
        } else {
            $class = $d->action === Decision::CHALLENGE ? 'warnbox' : 'nobox';
            $title = $d->action === Decision::CHALLENGE ? $tr('Browser check') : ($d->action === Decision::THROTTLE ? $tr('Please wait') : (string) $d->status);
            $sub = $tr('the site never sees it');
            if ($stopAt !== null) {
                // From the refusing check down to the shield's own answer
                $sx = $x0 + $stopAt * $gap;
                $h .= '<path class="arrow" d="M' . $sx . ' ' . ($y + 18) . ' V112 H' . ($ex + 75) . ' V' . ($y + 30) . '" marker-end="url(#rsd-a)"/>';
            }
        }
        $h .= '<defs><marker id="rsd-a" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        $h .= '<rect class="' . $class . '" x="' . $ex . '" y="' . ($y - 26) . '" width="150" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 75) . '" y="' . ($y - 4) . '" text-anchor="middle" font-weight="700">' . self::e($title) . '</text>'
            . '<text class="m" x="' . ($ex + 75) . '" y="' . ($y + 14) . '" text-anchor="middle" font-size="11">' . self::e($sub) . '</text>';
        return $h . '</svg>';
    }

    /**
     * How the shield is set up, as one picture: the request, a circle per
     * check (coloured: on, grey: off; each a link to its line below), the site
     * or the shield's own answer, and what comes after -- the log and the
     * statistics. A dot runs along the checks (not with reduced motion).
     *
     * @param list<array{label: string, name: string, on: bool, what: string, stops?: bool, feeds?: bool}> $steps stops: it can answer itself (a line down to the answer); feeds: the statistics use what it found (crawlers)
     * @param array{request: string, before: string, site: string, siteSub: string, answer: string, answerSub: string, after: string, lines: list<string>, feeds: string} $words
     */
    public static function setup(array $steps, array $words): string
    {
        $gap = 64;
        $x0 = 170;
        $y = 50;
        $last = $x0 + (count($steps) - 1) * $gap;
        $ex = $last + 46;
        $w = $ex + 196;
        $bottom = 214;
        $h = '<svg class="rsd setup" role="img" viewBox="0 0 ' . $w . ' ' . ($bottom + 30 + 16 * count($words['lines'])) . '" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>' . self::e($words['request'] . ' → ' . $words['site']) . '</title>' . self::STYLE
            . '<style>.rsd .off{fill:#b5bcc5}.rsd .on{fill:#2f62c9}.rsd a:hover circle,.rsd a:focus circle{stroke:#1d2127;stroke-width:3}.rsd .run{fill:#1e7b43}'
            . '.rsd.setup .lane{fill:none}.rsd .feed{stroke:#8b5cf6;stroke-dasharray:3 4;fill:none;stroke-width:1.6}.rsd .fl{fill:#8b5cf6}@media (prefers-reduced-motion:reduce){.rsd .run{display:none}}</style>'
            . '<defs><marker id="rsd-s" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        // The request, where the visitor's address is worked out ("before").
        $h .= '<rect class="box" x="4" y="' . ($y - 26) . '" width="136" height="52" rx="8"/>'
            . '<text class="t" x="72" y="' . ($y - 6) . '" text-anchor="middle" font-weight="600">' . self::e($words['request']) . '</text>'
            . '<text class="m" x="72" y="' . ($y + 12) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($words['before'], 22)) . '</text>';
        // The track, the way on to the site, and the way down to the shield's answer.
        $h .= '<path class="line" d="M140 ' . $y . ' H' . $ex . '"/><path class="lane" d="M' . $x0 . ' ' . ($y + 62) . ' H' . ($ex + 85) . ' V' . ($y + 90) . '" marker-end="url(#rsd-s)"/>';
        $feedX = null;
        foreach ($steps as $i => $s) {
            $cx = $x0 + $i * $gap;
            if ($s['on'] && ($s['stops'] ?? true)) {
                $h .= '<path class="lane" d="M' . $cx . ' ' . ($y + 17) . ' V' . ($y + 62) . '"/>';
            }
            if ($s['feeds'] ?? false) {
                $feedX = $cx;
            }
            $h .= '<a href="#step-' . ($i + 1) . '"><title>' . self::e($s['name'] . ': ' . $s['what']) . '</title>'
                . '<circle class="' . ($s['on'] ? 'on' : 'off') . '" cx="' . $cx . '" cy="' . $y . '" r="15"/>'
                . '<text class="w" x="' . $cx . '" y="' . ($y + 5) . '" text-anchor="middle" font-size="12">' . ($i + 1) . '</text>'
                . '<text class="' . ($s['on'] ? 't' : 'm') . '" x="' . $cx . '" y="' . ($y + 33) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($s['label'], 10)) . '</text></a>';
        }
        // A dot runs along the checks to the site: the way of every request.
        $h .= '<circle class="run" r="5" cy="0" cx="0"><animateMotion dur="7s" repeatCount="indefinite" path="M140 ' . $y . ' H' . $ex . '"/></circle>';
        // Where it ends: the site, or the shield's own answer.
        $h .= '<rect class="okbox" x="' . $ex . '" y="' . ($y - 26) . '" width="170" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 85) . '" y="' . ($y - 5) . '" text-anchor="middle" font-weight="700">' . self::e($words['site']) . '</text>'
            . '<text class="m" x="' . ($ex + 85) . '" y="' . ($y + 13) . '" text-anchor="middle" font-size="11">' . self::e($words['siteSub']) . '</text>'
            . '<rect class="nobox" x="' . $ex . '" y="' . ($y + 92) . '" width="170" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 85) . '" y="' . ($y + 113) . '" text-anchor="middle" font-weight="700">' . self::e($words['answer']) . '</text>'
            . '<text class="m" x="' . ($ex + 85) . '" y="' . ($y + 131) . '" text-anchor="middle" font-size="11">' . self::e($words['answerSub']) . '</text>';
        // After: the log and the statistics, below everything.
        $top = $bottom;
        $height = 26 + 16 * count($words['lines']);
        $h .= '<path class="arrow" d="M' . ($ex + 170) . ' ' . $y . ' H' . ($w - 12) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
            . '<path class="arrow" d="M' . ($ex + 85) . ' ' . ($y + 144) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
            . '<rect class="box" x="4" y="' . $top . '" width="' . ($w - 8) . '" height="' . $height . '" rx="8"/>'
            . '<text class="t" x="18" y="' . ($top + 19) . '" font-weight="700">' . self::e($words['after']) . '</text>';
        foreach ($words['lines'] as $n => $line) {
            $h .= '<text class="m" x="18" y="' . ($top + 38 + 16 * $n) . '" font-size="12">' . self::e($line) . '</text>';
        }
        if ($feedX !== null) {
            // The statistics use what the crawler check found: people, crawlers, bots.
            $h .= '<path class="feed" d="M' . ($feedX + 8) . ' ' . ($y + 38) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
                . '<text class="fl" x="' . ($feedX + 14) . '" y="' . ($top - 10) . '" font-size="11">' . self::e($words['feeds']) . '</text>';
        }
        return $h . '</svg>';
    }

    /** The browser check, step by step: browser, shield, site. */
    public static function browserCheck(): string
    {
        $lanes = [110 => 'Browser', 390 => 'request-shield', 640 => 'Your site'];
        $h = '<svg class="rsd" role="img" viewBox="0 0 760 430" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>The browser check, step by step</title>' . self::STYLE
            . '<defs><marker id="rsd-b" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        foreach ($lanes as $x => $name) {
            $h .= '<rect class="box" x="' . ($x - 75) . '" y="8" width="150" height="34" rx="8"/>'
                . '<text class="t" x="' . $x . '" y="30" text-anchor="middle" font-weight="700">' . $name . '</text>'
                . '<path class="lane" d="M' . $x . ' 42 V420"/>';
        }
        $arrow = static function (int $from, int $to, int $y, string $n, string $text, string $class = 'arrow') use (&$h): void {
            $h .= '<path class="' . $class . '" d="M' . $from . ' ' . $y . ' H' . $to . '" marker-end="url(#rsd-b)"/>'
                . '<circle class="note" cx="' . (min($from, $to) + 14) . '" cy="' . ($y - 14) . '" r="9"/><text class="w" x="' . (min($from, $to) + 14) . '" y="' . ($y - 10) . '" text-anchor="middle" font-size="11">' . $n . '</text>'
                . '<text class="t" x="' . (min($from, $to) + 28) . '" y="' . ($y - 9) . '">' . $text . '</text>';
        };
        $arrow(110, 390, 82, '1', 'asks for a page — too often, or a checked page');
        $arrow(390, 110, 132, '2', 'a small page with a signed task instead');
        // 3: the browser solves it
        $h .= '<path class="arrow" d="M110 162 h60 v36 h-58" marker-end="url(#rsd-b)"/>'
            . '<circle class="note" cx="186" cy="170" r="9"/><text class="w" x="186" y="174" text-anchor="middle" font-size="11">3</text>'
            . '<text class="t" x="200" y="176">solves it by itself — a fraction of a second</text>';
        $arrow(110, 390, 244, '4', 'the same page again, with the answer');
        $h .= '<rect class="okbox" x="300" y="258" width="180" height="30" rx="6" stroke-width="1.2"/>'
            . '<text class="t" x="390" y="278" text-anchor="middle" font-size="12">5 · answer right: &lt; 1 ms</text>';
        $arrow(390, 640, 316, '6', 'the request goes on');
        $arrow(640, 110, 356, '7', 'the page — and a pass for an hour', 'arrow go');
        $h .= '<text class="m" x="110" y="400" font-size="12">Next pages: with the pass straight to the site. A bot without JavaScript never gets past step 2.</text>';
        return $h . '</svg>';
    }

    /** How the shield sits in front of a site, and what it does with whom. */
    public static function overview(): string
    {
        $h = '<svg class="rsd" role="img" viewBox="0 0 760 300" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>How request-shield sits in front of a site</title>' . self::STYLE
            . '<defs><marker id="rsd-c" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        $box = static function (int $x, int $y, int $w, string $class, string $title, string $sub) use (&$h): void {
            $h .= '<rect class="' . $class . '" x="' . $x . '" y="' . $y . '" width="' . $w . '" height="56" rx="10" stroke-width="1.5"/>'
                . '<text class="t" x="' . ($x + $w / 2) . '" y="' . ($y + 24) . '" text-anchor="middle" font-weight="700">' . $title . '</text>'
                . '<text class="m" x="' . ($x + $w / 2) . '" y="' . ($y + 42) . '" text-anchor="middle" font-size="12">' . $sub . '</text>';
        };
        $box(10, 40, 150, 'box', 'Visitors', 'people, bots, crawlers');
        $box(250, 40, 220, 'warnbox', 'request-shield', 'every request, in microseconds');
        $box(580, 40, 170, 'okbox', 'Your site', 'CMS · 100–200 ms a page');
        $h .= '<path class="arrow" d="M160 68 H246" marker-end="url(#rsd-c)"/><path class="arrow go" d="M470 68 H576" marker-end="url(#rsd-c)"/>'
            . '<text class="m" x="523" y="60" text-anchor="middle" font-size="11">passes</text>';
        $outs = [[40, 'nobox', 'junk, scanners', '404 · 400'], [220, 'nobox', 'not for you', '403 · 405'],
            [400, 'warnbox', 'suspicious', 'browser check'], [580, 'warnbox', 'too fast', 'a pause (429)']];
        foreach ($outs as [$x, $class, $title, $sub]) {
            $h .= '<path class="arrow" d="M360 96 V150 H' . ($x + 70) . ' V186" marker-end="url(#rsd-c)"/>';
            $box($x, 190, 140, $class, $title, $sub);
        }
        $h .= '<text class="m" x="380" y="280" text-anchor="middle" font-size="12">Answered by the shield itself: the site never renders a page for them.</text>';
        return $h . '</svg>';
    }

    private static function cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
}
}

// plugins/waf/src/ExamplesPage.php
namespace CjwNetwork\RequestShield\Waf {
if (true) {
/**
 * The demo's rows as a table (0031 F.4), from its rule file's "# demo:"
 * groups (DemoSite::groups()): one group per feature, its explanation, one
 * numbered row per example -- a link (or a form, for a POST), what the rules
 * decide in words, "Show the answer" (decided on the server, DemoSite::answer())
 * and "See the path" (the active rules page's check). A "# try:" row is a link
 * with what to look at. Everything it embeds is escaped.
 *
 * @phpstan-import-type Group from DemoSite
 * @phpstan-import-type Row from DemoSite
 */
final class ExamplesPage
{
    /**
     * @param list<Group> $groups
     * @param callable(string): string $url the demo's address of one of its paths
     * @param callable(string, string): string $pathOf the rules page's check of a path and method
     * @param string $answer the address that answers a row (?n=3.2)
     */
    public static function render(array $groups, callable $url, callable $pathOf, string $answer): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = '<table class="tests">';
        foreach ($groups as $g) {
            $h .= '<tbody id="g' . $g['n'] . '"><tr class="group"><th colspan="4">' . $g['n'] . ' · ' . $e($g['title'] !== '' ? $g['title'] : $g['id'])
                . ' <span class="feature">' . $e($g['id']) . '</span></th></tr>';
            if ($g['about'] !== []) {
                $h .= '<tr class="about"><td></td><td colspan="3">' . $e(trim(implode(' ', array_map('trim', $g['about'])))) . '</td></tr>';
            }
            foreach ($g['rows'] as $r) {
                $id = 't' . str_replace('.', '-', $r['n']);
                $what = $r['text'] !== '' ? $r['text'] : $r['method'] . ' ' . $r['url'];
                $local = $r['url'][0] === '/' && $r['site'] === null;
                $link = $r['site'] !== null && $r['url'][0] === '/' ? 'https://' . $r['site'] . $r['url'] : ($local ? $url($r['url']) : $r['url']);
                $h .= '<tr id="' . $id . '"' . ($r['kind'] === 'try' ? ' class="try-row"' : '') . '><td class="no"><a href="#' . $id . '">' . $e($r['n']) . '</a></td><td class="what">';
                if ($r['site'] !== null) {
                    // Another website's row (a site block): no address to click here -- "Show the answer" decides it.
                    $h .= '<strong>' . $e($what) . '</strong>';
                } elseif ($r['method'] === 'GET') {
                    $h .= '<a href="' . $e($link) . '">' . $e($what) . '</a>';
                } else {
                    $h .= '<form method="post" action="' . $e($link) . '" class="inline"><input type="hidden" name="message" value="test ' . $e($r['n']) . '">'
                        . '<button type="submit" class="linkish">' . $e($what) . '</button></form>';
                }
                $h .= '<br><code>' . ($r['method'] !== 'GET' ? $e($r['method']) . ' ' : '') . $e($r['url']) . '</code></td>';
                $h .= '<td class="expect">' . $e(self::expected($r)) . '</td><td class="try">';
                if ($r['kind'] === 'expect') {
                    $h .= '<button type="button" class="peek" data-answer="' . $e($answer . (strpos($answer, '?') === false ? '?' : '&') . 'n=' . $r['n']) . '">Show the answer</button>';
                }
                if ($local) {
                    $h .= '<a class="path" href="' . $e($pathOf($r['url'], $r['method'])) . '">See the path →</a>';
                }
                $h .= '</td></tr><tr class="answer-row" hidden><td></td><td colspan="3"><pre class="answer"></pre></td></tr>';
            }
            $h .= '</tbody>';
        }
        return $h . '</table>';
    }

    /**
     * The groups as Markdown tables, for the docs (`request-shield examples
     * --markdown`, docs/tools/sync-examples.php): per group its title, its
     * explanation and a table -- the request, what the rules decide, the row's
     * comment. $only: one feature's groups.
     *
     * @param list<Group> $groups
     */
    public static function markdown(array $groups, ?string $only = null): string
    {
        $cell = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
        $out = [];
        foreach ($groups as $g) {
            if ($only !== null && $g['id'] !== $only) {
                continue;
            }
            $md = '**' . $g['id'] . ($g['title'] !== '' ? ' · ' . $g['title'] : '') . "**\n\n";
            if ($g['about'] !== []) {
                $md .= trim(implode(' ', array_map('trim', $g['about']))) . "\n\n";
            }
            $md .= "| Request | The rules decide | |\n|---|---|---|\n";
            foreach ($g['rows'] as $r) {
                $md .= '| `' . $cell(($r['method'] !== 'GET' ? $r['method'] . ' ' : '') . $r['url']) . '` | ' . $cell(self::expected($r)) . ' | ' . $cell($r['text']) . " |\n";
            }
            $out[] = $md;
        }
        return implode("\n", $out);
    }

    /**
     * The groups as one page that needs no server: each row with what
     * `request-shield test` decided for it, recorded ($results by the line it
     * is written at) -- the demo for a static host (`request-shield examples
     * --html`).
     *
     * @param list<Group> $groups
     * @param array<string, array{status: string, got: string, gotRule: ?string, http: int}> $results by "file:line"
     */
    public static function html(array $groups, array $results, string $title, string $recorded): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title>'
            . '<style>body{margin:0;font:16px/1.55 system-ui,sans-serif;background:#f6f7f9;color:#1d2127}main{max-width:60rem;margin:0 auto;padding:1.2rem 1rem 3rem}'
            . 'h1{font-size:1.5rem}h2{font-size:1.1rem;margin:2rem 0 .3rem}p.about,p.note{color:#5b6470}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe3e8}'
            . 'td,th{padding:.45rem .6rem;border-top:1px solid #dfe3e8;text-align:left;vertical-align:top}code{font:13px ui-monospace,monospace}.ok{color:#1e7b43;font-weight:600}.no{color:#a3361f;font-weight:600}'
            . '@media (prefers-color-scheme:dark){body{background:#15181c;color:#e7e9ec}table{background:#1d2127;border-color:#2d333b}td,th{border-color:#2d333b}p.about,p.note{color:#a0a8b3}}</style></head><body><main>'
            . '<h1>' . $e($title) . '</h1><p class="note">' . $e($recorded) . '</p>';
        foreach ($groups as $g) {
            $h .= '<h2 id="' . $e($g['id']) . '">' . $g['n'] . ' · ' . $e($g['title'] !== '' ? $g['title'] : $g['id']) . ' <small>' . $e($g['id']) . '</small></h2>';
            if ($g['about'] !== []) {
                $h .= '<p class="about">' . $e(trim(implode(' ', array_map('trim', $g['about'])))) . '</p>';
            }
            $h .= '<table><tr><th>#</th><th>Request</th><th>The rules decide</th><th>Recorded</th></tr>';
            foreach ($g['rows'] as $r) {
                $res = $results[$r['at']] ?? null;
                $mark = ['pass' => '<span class="ok">✓</span> ', 'skip' => '– skipped: '][$res['status'] ?? ''] ?? '<span class="no">✕</span> ';
                $rec = $r['kind'] === 'try' ? 'to look at in the live demo' : ($res === null ? '—' : $mark
                    . $e('HTTP ' . $res['http'] . ', ' . $res['got'] . ($res['gotRule'] !== null ? ' by ' . $res['gotRule'] : '')));
                $h .= '<tr><td>' . $e($r['n']) . '</td><td><code>' . $e(($r['method'] !== 'GET' ? $r['method'] . ' ' : '') . $r['url']) . '</code>'
                    . ($r['text'] !== '' ? '<br>' . $e($r['text']) : '') . '</td><td>' . $e(self::expected($r)) . '</td><td>' . $rec . '</td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '</main></body></html>' . "\n";
    }

    /**
     * What a row shows in words: what the rules decide, the rule, and what is
     * different from a click on this page (another address, a pass, a count).
     *
     * @param Row $r
     */
    public static function expected(array $r): string
    {
        if ($r['kind'] === 'try') {
            return 'look at it';
        }
        $words = ['passes' => 'passes; a cache may keep it', 'uncached' => 'passes, but a cache must not keep it', 'answered' => 'the site answers it',
            'check' => 'the invisible browser check', '400' => 'a broken request (400)', '403' => 'no access (403)', '404' => '"not found" (404) — the site never sees it',
            '405' => '"not allowed here" (405)', '429' => 'wait (429)'];
        $outcome = (string) $r['outcome'];
        $out = $words[$outcome] ?? "refused ($outcome)";
        if ($r['by'] !== null) {
            $out .= ' · rule ' . $r['by'];
        }
        $about = [];
        if ($r['site'] !== null) {
            $about[] = 'on the website ' . $r['site'];
        }
        if ($r['from'] !== null && $r['from'] !== DemoSite::FROM) {
            $about[] = 'from ' . $r['from'];
        } elseif ($r['from'] === DemoSite::FROM) {
            $about[] = 'from another address (' . DemoSite::FROM . ')';
        }
        if ($r['pass']) {
            $about[] = 'with a pass';
        }
        if ($r['times'] > 1) {
            $about[] = $r['times'] . ' times in a row';
        }
        if ($r['ua'] !== null) {
            $about[] = 'as "' . $r['ua'] . '"';
        }
        foreach ($r['headers'] as $name => $value) {
            // The headers are what the example is about as often as not (X-Forwarded-For, Origin).
            $about[] = 'with ' . str_replace(' ', '-', ucwords(str_replace('-', ' ', (string) $name))) . ': ' . $value;
        }
        return $out . ($about !== [] ? ' — ' . implode(', ', $about) : '');
    }
}
}
}

// plugins/waf/src/ListsPage.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Api\ListsChanges;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

if (true) {
/**
 * Keeping the lists in the dashboard (proposal 0026): both lists with a
 * search, an entry added with a comment of one's own, extended, its comment
 * changed, removed -- and the active bans, lifted with one click.
 *
 * Writes only the list files (allow.rules, deny.rules), through the same code
 * as the command line, with its guards and one more: never the address of the
 * person clicking. Changes come as POST with a token (ListsChanges::token(): HMAC of
 * the shield's secret, the viewer's address and the hour; an embedding CMS
 * may check its own form token instead, 'csrfChecked' => true). The site decides
 * who may open the page, as for the statistics.
 */
final class ListsPage implements \CjwNetwork\RequestShield\RoutePage
{
    /** Entries shown at most; the search narrows a long list. */
    public const SHOWN = 200;

    private const T = [
        'en' => [
            'title' => 'Lists', 'intro' => 'Addresses kept out (403 before every other check) and let in (never counted or checked, still refused for what only attackers ask for). Every server reads a change within its recheck.',
                        'add' => 'Add an entry', 'kind' => 'List', 'deny' => 'keep out', 'exempt' => 'let in', 'address' => 'Address or range', 'for' => 'For', 'note' => 'Comment',
            'notePh' => 'why — say why, not who', 'f.1h' => '1 hour', 'f.1d' => '1 day', 'f.7d' => '7 days', 'f.30d' => '30 days', 'f.date' => 'until …',             'confirm' => 'a wide range: I mean it', 'save' => 'Add', 'entries' => 'The entries', 'search' => 'Search', 'searchPh' => 'address, ID or comment',
            'none' => 'No entries yet: add an address above, or keep one out from a row of the live view.', 'more' => '%s more — narrow the search.', 'id' => 'ID', 'until' => 'Until', 'added' => 'Added', 'good' => 'for good — review now and then',
            'ended' => 'ended', 'extend' => 'Extend', 'keepTime' => 'as it is', 'change' => 'Save', 'remove' => 'Remove',
            'ruleFiles' => 'Entries written in the rule files themselves are shown on the rules page and are not changed here.',
            'bans' => 'Active bans', 'bansNone' => 'No address is banned right now: a ban rule (ban …) bans an address for a while when it keeps knocking at closed doors.', 'bansApcu' => 'Bans of this server (APCu: each server has its own).',
            'lift' => 'Lift', 'again' => 'banned %d times today — keep out for good?', 'banned' => 'Address (network)',
                                                                                ],
        'de' => [
            'title' => 'Listen', 'intro' => 'Ausgesperrte Adressen (403 vor jeder anderen Prüfung) und hereingelassene (nie gezählt oder geprüft, für das, was nur Angreifer aufrufen, trotzdem abgewiesen). Jeder Server liest eine Änderung bei seiner nächsten Prüfung.',
                        'add' => 'Eintrag hinzufügen', 'kind' => 'Liste', 'deny' => 'aussperren', 'exempt' => 'hereinlassen', 'address' => 'Adresse oder Bereich', 'for' => 'Für', 'note' => 'Kommentar',
            'notePh' => 'warum — sagen Sie warum, nicht wer', 'f.1h' => '1 Stunde', 'f.1d' => '1 Tag', 'f.7d' => '7 Tage', 'f.30d' => '30 Tage', 'f.date' => 'bis …',             'confirm' => 'ein großer Bereich: so gewollt', 'save' => 'Hinzufügen', 'entries' => 'Die Einträge', 'search' => 'Suchen', 'searchPh' => 'Adresse, ID oder Kommentar',
            'none' => 'Noch keine Einträge: oben eine Adresse eintragen, oder eine aus einer Zeile der Live-Ansicht aussperren.', 'more' => '%s weitere — die Suche eingrenzen.', 'id' => 'ID', 'until' => 'Bis', 'added' => 'Eingetragen', 'good' => 'dauerhaft — ab und zu prüfen',
            'ended' => 'abgelaufen', 'extend' => 'Verlängern', 'keepTime' => 'wie es ist', 'change' => 'Speichern', 'remove' => 'Entfernen',
            'ruleFiles' => 'Einträge, die in den Regeldateien selbst stehen, zeigt die Regelseite; sie werden hier nicht geändert.',
            'bans' => 'Aktive Sperren', 'bansNone' => 'Gerade ist keine Adresse gesperrt: Eine Sperrregel (ban …) sperrt eine Adresse für eine Weile, wenn sie immer wieder an verschlossene Türen klopft.', 'bansApcu' => 'Sperren dieses Servers (APCu: jeder Server hat seine eigenen).',
            'lift' => 'Aufheben', 'again' => 'heute %d-mal gesperrt — dauerhaft aussperren?', 'banned' => 'Adresse (Netz)',
                                                                                ],
    ];

    /**
     * The page.
     *
     * @param array<string, mixed> $o action (where the forms are sent), ip (the viewer's address), csrf (a token of the
     *                                site's own; else token()), get ($_GET: address, note, for to fill the form; q to search),
     *                                message (handle()'s answer), links, lang, accept, store, title, home, homeLabel, now
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = Texts::language(is_string($o['lang'] ?? null) ? $o['lang'] : 'auto', is_string($o['accept'] ?? null) ? $o['accept'] : null);
        $lang = $lang === 'de' ? 'de' : 'en';
        $t = self::T[$lang] + ListsChanges::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $ip = is_string($o['ip'] ?? null) ? $o['ip'] : '';
        $action = is_string($o['action'] ?? null) ? $o['action'] : '';
        $get = is_array($o['get'] ?? null) ? $o['get'] : [];
        $g = static fn (string $k): string => is_string($get[$k] ?? null) ? $get[$k] : '';
        $token = is_string($o['csrf'] ?? null) ? $o['csrf'] : ListsChanges::token($s, $ip, $now);
        $hidden = static fn (string $do, array $more = []): string => '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="do" value="' . $do . '">'
            . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e(is_scalar($v) ? (string) $v : '') . '">', array_keys($more), $more));
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $o['help'] = Help::link('RSF06-02', '', $s->docsUrl, $lang);
        $h = Frame::tabs($s, $links, 'lists', $lang) . '<p class="note">' . $e($t['intro']) . '</p>';
        $msg = is_array($o['message'] ?? null) ? $o['message'] : null;
        if ($msg !== null && is_string($msg['message'] ?? null)) {
            $h .= '<div class="msg ' . (($msg['ok'] ?? false) ? 'ok' : 'bad') . '" role="status">' . $e($msg['message']) . '</div>';
        }
        $title = is_string($o['title'] ?? null) ? $o['title'] : $t['title'];
        $dir = $s->listsDir;
        if ($dir === null) {
            return Frame::page($title, $lang, $h . '<div class="msg bad">' . $e($t['noDir']) . '</div>', $o, self::CSS);
        }
        // Add: prefilled from the live view ("keep out" on a row).
        $for = array_key_exists($g('for'), ListsChanges::FOR) ? $g('for') : '7d';
        $fors = '';
        foreach (['1h', '1d', '7d', '30d', 'date', 'good'] as $f) {
            $fors .= '<option value="' . $f . '"' . ($f === $for ? ' selected' : '') . '>' . $e($t['f.' . $f]) . '</option>';
        }
        $h .= '<form class="card add" method="post" action="' . $e($action) . '">' . Frame::h2($t['add'], $s, 'RSF01-02', 'the-list-files', $lang) . $hidden('add')
            . '<label>' . $e($t['kind']) . ' <select name="kind"><option value="deny"' . ($g('kind') !== 'exempt' ? ' selected' : '') . '>' . $e($t['deny']) . '</option><option value="exempt"'
            . ($g('kind') === 'exempt' ? ' selected' : '') . '>' . $e($t['exempt']) . '</option></select></label>'
            . '<label>' . $e($t['address']) . ' <input name="address" required maxlength="64" class="mono" value="' . $e($g('address')) . '" placeholder="203.0.113.7, 198.51.100.0/24"></label>'
            . '<label>' . $e($t['for']) . ' <span class="pair"><select name="for">' . $fors . '</select><input type="date" name="until" aria-label="' . $e($t['f.date']) . '"></span></label>'
            . '<label class="wide">' . $e($t['note']) . ' <input name="note" maxlength="200" value="' . $e(Lists::note($g('note'))) . '" placeholder="' . $e($t['notePh']) . '"></label>'
            . '<label class="small"><input type="checkbox" name="confirm" value="1"> ' . $e($t['confirm']) . '</label>'
            . '<button class="primary">' . $e($t['save']) . '</button></form>';

        // The entries: newest first, searched.
        $q = $g('q');
        $found = Lists::find($dir, $q, self::SHOWN);
        $h .= '<div class="card">' . Frame::h2($t['entries'], $s, 'RSF01-02', 'the-list-files', $lang) . '<form method="get" action="' . $e($action) . '" class="search">'
            . '<input type="search" name="q" value="' . $e($q) . '" placeholder="' . $e($t['searchPh']) . '"><input type="hidden" name="lang" value="' . $lang . '"><button>' . $e($t['search']) . '</button></form>';
        if ($found['entries'] === []) {
            $h .= '<p class="note">' . $e($t['none']) . '</p>';
        } else {
            $h .= '<div class="wrap"><table><thead><tr><th>' . $e($t['kind']) . '</th><th>' . $e($t['id']) . '</th><th>' . $e($t['address']) . '</th><th>' . $e($t['note'])
                . '</th><th>' . $e($t['until']) . '</th><th></th></tr></thead><tbody>';
            foreach ($found['entries'] as $entry) {
                [$note, $stamp] = Lists::split($entry['note']);
                $state = $entry['until'] === null ? '<span class="review">' . $e($t['good']) . '</span>'
                    : ($entry['until'] > $now ? $e(ListsChanges::date($entry['until'], $lang)) : '<span class="note">' . $e($t['ended'] . ' ' . ListsChanges::date($entry['until'], $lang)) . '</span>');
                $extend = '<option value="">' . $e($t['keepTime']) . '</option>';
                foreach (['1d', '7d', '30d'] as $f) {
                    $extend .= '<option value="' . $f . '">+ ' . $e($t['f.' . $f]) . '</option>';
                }
                if ($entry['kind'] === 'deny') {
                    $extend .= '<option value="good">' . $e($t['f.good']) . '</option>';
                }
                $h .= '<tr><td><span class="badge ' . $entry['kind'] . '">' . $e($t[$entry['kind']]) . '</span></td><td class="mono">' . $e($entry['id']) . '</td>'
                    . '<td class="mono">' . $e(implode(' ', $entry['addresses'])) . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '" class="inline">' . $hidden('update', ['id' => $entry['id']])
                    . '<input name="note" maxlength="200" value="' . $e($note) . '" aria-label="' . $e($t['note']) . '"> <select name="for" aria-label="' . $e($t['extend']) . '">' . $extend . '</select>'
                    . ' <button>' . $e($t['change']) . '</button></form><span class="stamp">' . $e($stamp) . '</span></td>'
                    . '<td>' . $state . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '">' . $hidden('remove', ['id' => $entry['id']]) . '<button class="danger">' . $e($t['remove']) . '</button></form></td></tr>';
            }
            $h .= '</tbody></table></div>';
            if ($found['total'] > count($found['entries'])) {
                $h .= '<p class="note">' . $e(sprintf($t['more'], number_format($found['total'] - count($found['entries']), 0, '', $lang === 'de' ? '.' : ','))) . '</p>';
            }
        }
        $h .= '<p class="note">' . $e($t['ruleFiles']) . (isset($links['rules']) ? ' <a href="' . $e($links['rules']) . '">→</a>' : '') . '</p></div>';

        // The active bans, from the store.
        $store = ListsChanges::store($s, $o);
        $bans = $s->bans === [] ? [] : $store->marks('ban:', (float) $now);
        arsort($bans);
        $h .= '<div class="card">' . Frame::h2($t['bans'], $s, 'RSF01-02', 'bans', $lang);
        if ($bans === []) {
            $h .= '<p class="note">' . $e($t['bansNone']) . '</p>';
        } else {
            $h .= '<div class="wrap"><table><thead><tr><th>' . $e($t['banned']) . '</th><th>' . $e($t['until']) . '</th><th></th><th></th></tr></thead><tbody>';
            foreach ($bans as $key => $until) {
                $bucket = (string) substr($key, 4);
                $times = (int) round($store->peek('bans:' . $bucket, 86400, (float) $now));
                $address = $bucket;                                 // IPv4 as it is, IPv6 its /64 (IpAddress::bucket())
                $again = $times >= 3 && $action !== '' ? ' <a href="' . $e($action . (strpos($action, '?') === false ? '?' : '&') . http_build_query(['address' => $address, 'for' => 'good', 'note' => sprintf($t['again'], $times)])) . '">'
                    . $e(sprintf($t['again'], $times)) . '</a>' : '';
                $h .= '<tr><td class="mono">' . $e($address) . '</td><td>' . $e(ListsChanges::date($until, $lang)) . '</td><td>' . $again . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '">' . $hidden('lift', ['bucket' => $bucket]) . '<button>' . $e($t['lift']) . '</button></form></td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        if ($s->store !== 'file' && $s->bans !== []) {
            $h .= '<p class="note">' . $e($t['bansApcu']) . '</p>';
        }
        $h .= '</div>';
        return Frame::page($title, $lang, $h, $o, self::CSS);
    }

    private const CSS = <<<'CSS'
.add{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:end}.add h2{width:100%;margin:0}.add label{display:flex;flex-direction:column;gap:2px;font-size:13px;color:var(--m)}
.add label.wide{flex:1;min-width:240px}.pair{display:flex;gap:4px}.card h2{margin-top:0}.add label.small{flex-direction:row;align-items:center;gap:6px}.add input[name=address]{width:230px}
.search{display:flex;gap:6px;margin:0 0 8px}.search input{flex:1;max-width:360px}.inline{display:flex;flex-wrap:wrap;gap:4px}.inline input{min-width:160px;flex:1}
.stamp{display:block;color:var(--m);font-size:12px;margin-top:2px}.review{color:var(--throttled)}.badge.deny{border-color:var(--refused);color:var(--refused)}.badge.exempt{border-color:var(--through);color:var(--through)}
CSS;

    /**
     * The route (0031 B.6): a POST is a change (handle(), with the page's token), then the page.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $ctx
     */
    public static function serve(Settings $s, \CjwNetwork\RequestShield\Request $request, array $route, array $ctx): \CjwNetwork\RequestShield\Response
    {
        /** @var array<string, string> $links */
        $links = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        /** @var array<mixed> $get */
        $get = is_array($ctx['get'] ?? null) ? $ctx['get'] : [];
        /** @var array<mixed> $post */
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $lang = is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto';
        $accept = is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null;
        $ip = is_string($ctx['ip'] ?? null) ? $ctx['ip'] : '';
        $who = is_string($ctx['who'] ?? null) ? $ctx['who'] : '*';
        $message = ($ctx['method'] ?? 'GET') === 'POST'
            ? ListsChanges::handle($s, $post, ['ip' => $ip, 'ruleFile' => $ctx['ruleFile'] ?? null, 'lang' => Texts::language($lang, $accept), 'user' => $who === '*' ? '' : $who])
            : null;
        $label = is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, self::render($s, ['action' => $links['lists'] ?? ((is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . (is_string($route['path'] ?? null) ? $route['path'] : '')), 'get' => $get, 'message' => $message,
            'links' => $links, 'lang' => $lang, 'accept' => $accept, 'ip' => $ip, 'home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => $label, 'title' => 'Lists — ' . $label]));
    }
}
}
}

// plugins/waf/src/LivePage.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Api\LiveRows;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

if (true) {
/**
 * The live view (proposal 0026): what the shield stops right now, one row per
 * request, newest first -- the website, the address, the request, what
 * happened, why in plain words and where the decision came from (a list, a
 * ban, a feed, the site's own rule, a built-in rule, the pace, the crawler
 * policy). Read from the log's new lines (LogTail), so a request pays
 * nothing for it; the page asks json() every few seconds with its cursor.
 *
 * The site decides who may open it (an address rule, its login), as for the
 * statistics: these are the log's lines, addresses as the log keeps them.
 */
final class LivePage implements \CjwNetwork\RequestShield\RoutePage
{

    private const T = [
        'en' => [
            'title' => 'Live', 'intro' => 'What the shield stops right now — newest first, every %d seconds.',
            'noLog' => 'There is no log to read: switch it on in the rule file (set log <file>), then this page fills.',
            'level' => 'From the log, which keeps %s.', 'lv.stop' => 'refusals and bans (log-level stop) — checks and the uncached are not in it (log-level flag)',
            'lv.flag' => 'refusals, bans, checks and the uncached (log-level flag)', 'lv.all' => 'everything that was not a plain pass (log-level all)', 'lv.off' => 'nothing (log-level off)',
            'time' => 'Time', 'site' => 'Website', 'client' => 'Address', 'request' => 'Request', 'what' => 'What happened', 'why' => 'Why', 'source' => 'From', 'rule' => 'Rule',
            'allSites' => 'all websites', 'allWhat' => 'everything', 'allSources' => 'all sources', 'search' => 'address or request …',
            'pause' => 'Pause', 'resume' => 'Go on', 'waiting' => 'Go on (%d new)', 'shown' => '%d of %d rows', 'empty' => 'Nothing stopped yet: a row appears as soon as the shield refuses, checks or bans a request, such as a scanner asking for /.env.',
            'skipped' => '%d KB of the log were skipped (more than one read): the newest rows are shown.', 'keepOut' => 'keep out', 'unlist' => 'lists',
                                    'whereRule' => 'where this rule is written', 'error' => 'The live view cannot reach the server — trying again.',
            'memory' => 'From the live memory: the last requests stopped, with the full address, kept %s (set live on).', 'skippedRows' => '%d rows skipped (more than one read): the newest are shown.',
            'noMemory' => 'set live on: with set store memory nothing outlasts a request — the log is read instead.',
            'noFeed' => 'The rows as the page was opened: it refreshes itself with the API (request-shield-api.php, set api on).',
        ],
        'de' => [
            'title' => 'Live', 'intro' => 'Was der Schutz gerade aufhält — das Neueste oben, alle %d Sekunden.',
            'noLog' => 'Es gibt kein Log zum Lesen: in der Regeldatei einschalten (set log <Datei>), dann füllt sich diese Seite.',
            'level' => 'Aus dem Log, das %s festhält.', 'lv.stop' => 'Abweisungen und Sperren (log-level stop) — Prüfungen und nicht Gecachtes fehlen (log-level flag)',
            'lv.flag' => 'Abweisungen, Sperren, Prüfungen und nicht Gecachtes (log-level flag)', 'lv.all' => 'alles, was nicht einfach durchging (log-level all)', 'lv.off' => 'nichts (log-level off)',
            'time' => 'Zeit', 'site' => 'Website', 'client' => 'Adresse', 'request' => 'Anfrage', 'what' => 'Was geschah', 'why' => 'Warum', 'source' => 'Woher', 'rule' => 'Regel',
            'allSites' => 'alle Websites', 'allWhat' => 'alles', 'allSources' => 'alle Quellen', 'search' => 'Adresse oder Anfrage …',
            'pause' => 'Anhalten', 'resume' => 'Weiter', 'waiting' => 'Weiter (%d neu)', 'shown' => '%d von %d Zeilen', 'empty' => 'Noch nichts aufgehalten: Eine Zeile erscheint, sobald der Schutz eine Anfrage abweist, prüft oder sperrt, etwa einen Scanner, der nach /.env fragt.',
            'skipped' => '%d KB des Logs übersprungen (mehr als ein Lesen): die neuesten Zeilen stehen hier.', 'keepOut' => 'aussperren', 'unlist' => 'Listen',
                                    'whereRule' => 'wo diese Regel steht', 'error' => 'Die Live-Ansicht erreicht den Server nicht — neuer Versuch.',
            'memory' => 'Aus dem Live-Speicher: die zuletzt aufgehaltenen Anfragen, mit voller Adresse, gehalten %s (set live on).', 'skippedRows' => '%d Zeilen übersprungen (mehr als ein Lesen): die neuesten stehen hier.',
            'noMemory' => 'set live on: mit set store memory überdauert nichts eine Anfrage — stattdessen wird das Log gelesen.',
            'noFeed' => 'Die Zeilen vom Öffnen der Seite: Sie aktualisiert sich mit der API (request-shield-api.php, set api on).',
        ],
    ];

    /**
     * The page: its filters, the table, the script that asks for new rows.
     *
     * @param array<string, mixed> $o feed (the address of json(), required), lists (the lists page: "keep out"),
     *                                links (the dashboard's tabs), lang (auto, en, de), accept (Accept-Language), every (seconds, 3),
     *                                title, home, homeLabel
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = Texts::language(is_string($o['lang'] ?? null) ? $o['lang'] : 'auto', is_string($o['accept'] ?? null) ? $o['accept'] : null);
        $lang = $lang === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $every = max(1, min(60, is_int($o['every'] ?? null) ? $o['every'] : 3));
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $h = Frame::tabs($s, $links, 'live', $lang);
        $o['help'] = Help::link('RSF06-02', '', $s->docsUrl, $lang);
        $memory = LiveRows::fromMemory($s);
        $h .= '<p class="note">' . $e(sprintf($t['intro'], $every)) . ' '
            . ($memory ? $e(sprintf($t['memory'], Describe::span($s->liveKeep, $lang))) : ($s->logFile !== null ? $e(sprintf($t['level'], $t['lv.' . $s->logLevel] ?? $s->logLevel)) : '')) . '</p>'
            . ($s->liveEnabled && !$memory ? '<p class="note">' . $e($t['noMemory']) . '</p>' : '');
        if (!$memory && $s->logFile === null) {
            return Frame::page(is_string($o['title'] ?? null) ? $o['title'] : $t['title'], $lang, $h . '<div class="msg">' . $e($t['noLog']) . '</div>', $o);
        }
        $first = LiveRows::json($s, null, ['lang' => $lang, 'links' => $links] + (is_string($o['ip'] ?? null) ? ['ip' => $o['ip']] : []));
        $opt = static fn (string $v, string $label): string => '<option value="' . $e($v) . '">' . $e($label) . '</option>';
        $whats = $opt('', $t['allWhat']);
        foreach (['refused', 'banned', 'paused', 'checked', 'uncached'] as $w) {
            $whats .= $opt($w, LiveRows::T[$lang]['w.' . $w]);
        }
        $sources = $opt('', $t['allSources']);
        foreach (['list', 'ban', 'feed', 'own', 'builtin', 'pace', 'crawler', 'shield'] as $k) {
            $sources .= $opt($k, LiveRows::T[$lang]['s.' . $k]);
        }
        $h .= '<div class="card live-bar"><select id="f-host" aria-label="' . $e($t['site']) . '">' . $opt('', $t['allSites']) . '</select>'
            . '<select id="f-what" aria-label="' . $e($t['what']) . '">' . $whats . '</select>'
            . '<select id="f-source" aria-label="' . $e($t['source']) . '">' . $sources . '</select>'
            . '<input id="f-q" type="search" placeholder="' . $e($t['search']) . '" aria-label="' . $e($t['search']) . '">'
            . '<button id="pause" type="button">' . $e($t['pause']) . '</button><span id="count" class="note"></span></div>'
            . '<p id="skipped" class="note" hidden></p><p id="error" class="msg bad" hidden>' . $e($t['error']) . '</p>'
            . '<div class="card wrap"><table><thead><tr><th>' . $e($t['time']) . '</th><th>' . $e($t['site']) . '</th><th>' . $e($t['client']) . '</th><th>' . $e($t['request'])
            . '</th><th>' . $e($t['what']) . '</th><th>' . $e($t['why']) . '</th><th>' . $e($t['source']) . '</th><th>' . $e($t['rule']) . '</th><th></th></tr></thead>'
            . '<tbody id="rows"></tbody></table><p id="empty" class="note">' . $e($t['empty']) . '</p></div>';
        $js = ['whereRule' => $t['whereRule'], 'pause' => $t['pause'], 'resume' => $t['resume'], 'waiting' => $t['waiting'], 'shown' => $t['shown'], 'skipped' => $memory ? $t['skippedRows'] : $t['skipped'], 'keepOut' => $t['keepOut']];
        $feed = is_string($o['feed'] ?? null) ? $o['feed'] : '';
        if ($feed === '') {
            $h .= '<p class="note">' . $e($t['noFeed']) . '</p>';        // nothing asks for new rows
        }
        $feed .= $feed === '' ? '' : (strpos($feed, '?') === false ? '?' : '&') . 'lang=' . $lang;   // the rows in the page's language
        $h = '<div id="live" data-feed="' . $e($feed) . '" data-lists="' . $e(is_string($o['lists'] ?? null) ? $o['lists'] : '') . '" data-every="' . $every . '"'
            . ' data-t="' . $e((string) json_encode($js, JSON_UNESCAPED_UNICODE)) . '" data-init="' . $e((string) json_encode($first, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . '">' . $h . '</div>';
        return Frame::page(is_string($o['title'] ?? null) ? $o['title'] : $t['title'], $lang, $h, $o, self::CSS, self::SCRIPT);
    }

    private const CSS = <<<'CSS'
.live-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.live-bar input{flex:1;min-width:180px}
#rows tr.new{animation:fresh 1.6s ease-out}@keyframes fresh{from{background:color-mix(in srgb,var(--a) 18%,transparent)}to{background:transparent}}
.w{font-weight:600;white-space:nowrap}.w.refused,.w.banned{color:var(--refused)}.w.paused{color:var(--throttled)}.w.checked{color:var(--checked)}.w.uncached{color:var(--m)}.w.watched{color:var(--watched)}
.rule{white-space:nowrap}.req{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}td.why{min-width:240px}.host{white-space:nowrap}.site{color:var(--m);font-size:12px;display:block}
.badge.list,.badge.ban{border-color:var(--refused);color:var(--refused)}.badge.feed{border-color:var(--throttled);color:var(--throttled)}.badge.own{border-color:var(--a);color:var(--a)}
.badge.pace{border-color:var(--throttled)}.badge.crawler{border-color:var(--through);color:var(--through)}
CSS;

    private const SCRIPT = <<<'JS'
(function () {
  var box = document.getElementById('live');
  if (!box) { return; }
  var feed = box.getAttribute('data-feed'), lists = box.getAttribute('data-lists'), every = +box.getAttribute('data-every') * 1000;
  var T = JSON.parse(box.getAttribute('data-t')), first = JSON.parse(box.getAttribute('data-init'));
  var cursor = first.cursor, rows = [], pending = [], paused = false, MAX = 500, hosts = {};
  var body = document.getElementById('rows'), empty = document.getElementById('empty'), count = document.getElementById('count');
  var f = { host: document.getElementById('f-host'), what: document.getElementById('f-what'), source: document.getElementById('f-source'), q: document.getElementById('f-q') };
  var pause = document.getElementById('pause'), q = new URLSearchParams(location.search);
  Object.keys(f).forEach(function (k) { if (q.get(k)) { if (k === 'host') { addHost(q.get(k)); } f[k].value = q.get(k); } });
  function addHost(h) {
    if (!h || hosts[h]) { return; }
    hosts[h] = 1;
    var o = document.createElement('option'); o.value = h; o.textContent = h; f.host.appendChild(o);
  }
  function td(tr, text, cls, title) {
    var c = document.createElement('td'); c.textContent = text == null ? '' : text;
    if (cls) { c.className = cls; } if (title) { c.title = title; } tr.appendChild(c); return c;
  }
  function fits(r) {
    var s = f.q.value.toLowerCase();
    return (!f.host.value || r.host === f.host.value) && (!f.what.value || r.what === f.what.value) && (!f.source.value || r.source === f.source.value)
      && (!s || (r.client + ' ' + r.method + ' ' + r.request + ' ' + r.host).toLowerCase().indexOf(s) >= 0);
  }
  function tr(r, fresh) {
    var x = document.createElement('tr'); if (fresh) { x.className = 'new'; }
    td(x, r.time, 'mono', r.day);
    var h = td(x, r.host, 'host'); if (r.site && r.site !== r.host) { var sp = document.createElement('span'); sp.className = 'site'; sp.textContent = r.site; h.appendChild(sp); }
    td(x, r.client, 'mono');
    td(x, r.method + ' ' + r.request, 'req mono', r.method + ' ' + r.request + '\n' + r.agent);
    td(x, r.label, 'w ' + r.what + (r.watched ? ' watched' : ''));
    td(x, r.why, 'why', r.reason);
    var b = document.createElement('span'); b.className = 'badge ' + r.source; b.textContent = r.sourceLabel; td(x, '').appendChild(b);
    var rc = td(x, r.ruleHref ? '' : r.rule, 'mono rule');
    if (r.ruleHref) { var ra = document.createElement('a'); ra.href = r.ruleHref; ra.textContent = r.rule; ra.title = T.whereRule; rc.appendChild(ra); }
    var a = td(x, '');
    if (r.keep && lists) {
      var l = document.createElement('a');
      l.href = lists + (lists.indexOf('?') < 0 ? '?' : '&') + 'address=' + encodeURIComponent(r.keep) + '&note=' + encodeURIComponent(r.why) + '&for=7d';
      l.textContent = T.keepOut; a.appendChild(l);
    }
    return x;
  }
  function draw(fresh) {
    var frag = document.createDocumentFragment(), shown = 0;
    rows.forEach(function (r, i) { if (fits(r)) { shown++; frag.appendChild(tr(r, i < fresh)); } });
    body.textContent = ''; body.appendChild(frag);
    empty.hidden = shown > 0;
    count.textContent = T.shown.replace('%d', shown).replace('%d', rows.length);
  }
  function add(list) {
    list.forEach(function (r) { addHost(r.host); rows.unshift(r); });
    if (rows.length > MAX) { rows.length = MAX; }
  }
  function remember() {
    var p = new URLSearchParams(location.search);
    Object.keys(f).forEach(function (k) { if (f[k].value) { p.set(k, f[k].value); } else { p.delete(k); } });
    history.replaceState(null, '', location.pathname + (p.toString() ? '?' + p : ''));
    draw(0);
  }
  Object.keys(f).forEach(function (k) { f[k].addEventListener(k === 'q' ? 'input' : 'change', remember); });
  pause.addEventListener('click', function () {
    paused = !paused;
    if (!paused && pending.length) { var n = pending.length; add(pending); pending = []; draw(n); }
    pause.textContent = paused ? T.resume : T.pause;
  });
  function skipped(kb) {
    var p = document.getElementById('skipped'); p.hidden = !kb; if (kb) { p.textContent = T.skipped.replace('%d', kb); }
  }
  add(first.rows); draw(0);
  if (!feed || !window.fetch) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(feed + (feed.indexOf('?') < 0 ? '?' : '&') + 'cursor=' + encodeURIComponent(cursor), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw r; } return r.json(); })
      .then(function (j) {
        j = j.data || j;          // the API's envelope, or a site's own feed (LiveRows::json())
        document.getElementById('error').hidden = true;
        cursor = j.cursor; skipped(j.skipped);
        if (!j.rows.length) { return; }
        if (paused) { pending = pending.concat(j.rows); pause.textContent = T.waiting.replace('%d', pending.length); return; }
        add(j.rows); draw(j.rows.length);
      })
      .catch(function () { document.getElementById('error').hidden = false; });
  }, every);
})();
JS;

    /**
     * The route (0031 B.6): the page; it asks the API's GET /live for the new rows
     * (0031 G.0). Without the API the page shows the rows of the moment it was opened.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $ctx
     */
    public static function serve(Settings $s, \CjwNetwork\RequestShield\Request $request, array $route, array $ctx): \CjwNetwork\RequestShield\Response
    {
        /** @var array<string, string> $links */
        $links = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        /** @var array<mixed> $get */
        $get = is_array($ctx['get'] ?? null) ? $ctx['get'] : [];
        $lang = is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto';
        $accept = is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null;
        $ip = is_string($ctx['ip'] ?? null) ? $ctx['ip'] : '';
        // The new rows come from the API (<dashboard-path>/api/v1/live), when it is there.
        $api = $s->ext['api'] ?? null;
        $feed = is_array($api) && ($api['enabled'] ?? false) === true && is_string($api['base'] ?? null) && isset($s->routes[$api['base'] . '/live'])
            ? (is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . $api['base'] . '/live' : '';
        $label = is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, self::render($s, ['links' => $links, 'lang' => $lang, 'accept' => $accept, 'ip' => $ip, 'home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => $label,
            'feed' => $feed, 'lists' => $links['lists'] ?? null, 'title' => 'Live — ' . $label]));
    }
}
}
}

// plugins/waf/src/RulesPage.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Counts;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\LogStats;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

if (true) {
/**
 * "Active rules": the rules in plain words, how often each one decided, what
 * happened lately, and a check that tries any address against them step by
 * step -- for site owners, not only for technicians.
 *
 *   echo RulesPage::render($settings, ['check' => $_GET]);
 *
 * It shows how the site is protected, so it belongs behind the site's admin
 * login or a `restrict` rule. Nothing here runs on a normal request: the
 * classes are loaded only when the page is opened.
 */
final class RulesPage
{
    /**
     * @param array{check?: array<mixed>, action?: string, title?: string, store?: Store, now?: int, ip?: string, home?: string, homeLabel?: string} $o
     *   check: the form's values (method, url, ip), usually $_GET; action: the form's URL;
     *   ip: the address the check starts with (the viewer's own, say)
     */
    public static function render(Settings $s, array $o = []): string
    {
        $now = $o['now'] ?? time();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stats = LogStats::read($s->logFile, $now - 86400);
        $title = $o['title'] ?? 'Active rules';

        $check = $o['check'] ?? [];
        $url = is_string($check['url'] ?? null) ? trim($check['url']) : '';
        $method = is_string($check['method'] ?? null) && preg_match('/^[A-Za-z]{1,10}$/', $check['method']) ? strtoupper($check['method']) : 'GET';
        $ip = is_string($check['ip'] ?? null) && @inet_pton(trim($check['ip'])) !== false ? trim($check['ip']) : ($o['ip'] ?? '198.51.100.7');

        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . '</style></head><body>';
        if (isset($o['home'])) {
            $h .= '<header class="bar"><div><a class="name" href="' . $e($o['home']) . '">← ' . $e($o['homeLabel'] ?? 'Back') . '</a></div></header>';
        }
        $h .= '<main>';
        $help = Help::link('RSF06-01', '', $s->docsUrl);
        $h .= '<h1>' . $e($title) . ($help !== '' ? ' ' . $help : '') . '</h1><p class="lead">How this site is protected, in plain words — and what happens to any address you try.</p>';
        $versions = $s->origins['versions'] ?? [];
        if ($versions !== []) {
            $h .= '<p class="note">Rule sets: ' . implode(' · ', array_map(static fn (string $n, string $v): string => '<code>' . htmlspecialchars("$n $v", ENT_QUOTES) . '</code>', array_keys($versions), $versions)) . '</p>';
        }
        foreach ($s->origins['warnings'] ?? [] as $w) {
            $h .= '<div class="verdict note"><span class="icon">!</span><div><strong>Please check:</strong> ' . $e($w) . '</div></div>';
        }
        $mode = self::mode($s);
        if ($mode !== null) {
            $h .= '<div class="verdict ' . ($s->mode === 'strict' ? 'stop' : 'note') . '" id="mode"><span class="icon">!</span><div>' . $e($mode) . '</div></div>';
        }

        // ── Summary ─────────────────────────────────────────────────────────
        $stopped = ($stats['actions']['reject'] ?? 0) + ($stats['actions']['throttle'] ?? 0);
        $h .= '<div class="tiles" id="tiles" data-live>'
            . self::tile((string) count($s->blockedPaths), 'kinds of address refused')
            . self::tile((string) count($s->restricted), 'restricted areas')
            . self::tile((string) count($s->budgets), 'limits per visitor')
            . self::tile($s->logFile === null ? '—' : (string) $stopped, 'requests refused in 24 h')
            . self::tile($s->logFile === null ? '—' : (string) ($stats['actions']['challenge'] ?? 0), 'browser checks in 24 h')
            . '</div>';

        // ── Try an address ───────────────────────────────────────────────────
        $action = $o['action'] ?? '';
        $h .= '<section class="card" id="check">' . Frame::h2('Try an address', $s, 'RSF06-01', 'the-rule-tester', 'en') . '<form method="get" action="' . $e($action) . '" class="try">'
            . '<select name="method" aria-label="Kind of request">';
        foreach (array_unique(array_merge(['GET', 'POST'], $s->methods)) as $m) {
            $h .= '<option' . ($m === $method ? ' selected' : '') . '>' . $e($m) . '</option>';
        }
        $h .= '</select><input type="text" name="url" value="' . $e($url) . '" placeholder="https://www.example.org/wp-login.php" aria-label="Address">'
            . '<input type="text" name="ip" value="' . $e($ip) . '" aria-label="Visitor\'s address" title="The visitor\'s address (IP)" class="ip">'
            . '<button type="submit">Check</button></form>'
            . '<p class="note">Nothing is counted: the check only looks at the visitor\'s counters.</p>';
        if ($url !== '') {
            $t = (new Inspector($s, $o['store'] ?? null))->trace(Inspector::request($method, $url, $ip), (float) $now);
            $state = $t['decision']->passes() ? ($t['decision']->action === 'allow' && $t['watched'] === null ? 'pass' : 'note')
                : ($t['decision']->action === 'challenge' || $s->mode === 'monitor' ? 'note' : 'stop');
            $h .= '<div class="diagram">' . Diagram::trace($t, $method . ' ' . (string) (parse_url($url, PHP_URL_PATH) ?: $url)) . '</div>';
            $h .= '<div class="verdict ' . $state . '"><span class="icon">' . self::icon($state) . '</span><div><strong>This visitor ' . $e($t['verdict']) . '.</strong>'
                . ($t['rule'] !== null ? '<br><span class="note">Decided by: <code>' . $e($t['rule']) . '</code></span>' : '')
                . ($t['watched'] !== null ? '<br><span class="note">Watched: it ' . $e($t['watched']) . '.</span>' : '') . '</div></div><ol class="steps">';
            foreach ($t['steps'] as $st) {
                $h .= '<li class="' . $st['state'] . '"><span class="icon">' . self::icon($st['state']) . '</span><div><strong>' . $e($st['check']) . '</strong><br>' . $e($st['text'])
                    . ($st['rule'] !== null ? ' <code class="origin">' . $e($st['rule']) . '</code>' : '') . '</div></li>';
            }
            $h .= '</ol>';
        }
        $h .= '</section>';

        // ── The rules ───────────────────────────────────────────────────────
        $h .= Frame::h2('The rules', $s, 'RSF06-01', 'the-rules', 'en') . '<p class="note">Each with its ID, where it is written and, from the log, how often it decided in the last 24 hours. The text is the comment after the rule in the rule file.</p>';
        // The counters of the last 7 days, from the plugins that keep them (RuleCounts, 0031 B.8).
        $counted = Counts::crawlers($s, 7, (float) $now);
        foreach (self::groups($s, $stats['claims'], $counted) as [$heading, $intro, $rows]) {
            $h .= '<section class="card"><h3>' . $e($heading) . '</h3><p class="intro">' . $e($intro) . '</p>';
            if ($rows !== []) {
                $h .= '<table>';
                foreach ($rows as $r) {
                    $log = $r['log'] !== '' ? $r['log'] : null;
                    $hit = $log !== null ? ($stats['rules'][$log] ?? null) : null;
                    $h .= '<tr><td>' . $e($r['text']) . ($r['detail'] !== null ? '<br><code class="rule">' . $e($r['detail']) . '</code>' : '') . '</td>'
                        . '<td class="meta">' . ($r['id'] !== null ? '<code class="origin">' . $e($r['id']) . '</code>' : '')
                        . ($r['where'] !== null ? '<br><span class="note">' . $e($r['where']) . '</span>' : '') . '</td>'
                        . '<td class="hits"' . ($log !== null ? ' data-live id="hits-' . md5($log) . '"' : '') . '>'
                        . ($hit !== null ? '<span class="badge">' . $hit['count'] . '×</span> <span class="note">last ' . $e(self::ago($now - $hit['last'])) . '</span>' : ($s->logFile !== null && $log !== null ? '<span class="note">—</span>' : ''))
                        . '</td></tr>';
                }
                $h .= '</table>';
            }
            if ($heading === 'Browser check') {
                $h .= str_replace('%DIAGRAM%', '<div class="diagram">' . Diagram::browserCheck() . '</div>', self::BROWSER_CHECK);
            }
            $h .= '</section>';
        }

        // ── Lately ──────────────────────────────────────────────────────────
        $h .= Frame::h2('Lately', $s, 'RSF05-05', '', 'en') . '<section class="card" id="lately" data-live>';
        if ($s->logFile === null) {
            $h .= '<p class="intro">No log: <code>set log /path/shield.log</code> in the rule file shows here what was refused or checked.</p>';
        } elseif ($stats['recent'] === []) {
            $h .= '<p class="intro">Nothing refused or checked in the last 24 hours. A row appears as soon as the shield refuses or checks a request, such as a scanner asking for /.env.</p>';
        } else {
            $h .= '<table class="recent"><tr><th>When</th><th>Visitor</th><th>What happened</th><th>Address</th></tr>';
            foreach ($stats['recent'] as $r) {
                $state = $r['action'] === 'reject' || $r['action'] === 'throttle' ? 'stop' : 'note';
                $h .= '<tr class="' . $state . '"><td>' . $e(date('H:i:s', $r['time'])) . '</td><td><code>' . $e($r['client']) . '</code></td>'
                    . '<td><span class="icon">' . self::icon($state) . '</span> ' . $e(self::happened($r['action'], $r['status'])) . ': ' . $e(Describe::reason($r['reason']))
                    . ($r['rule'] !== null ? ' <code class="origin">' . $e($r['rule']) . '</code>' : '') . '</td>'
                    . '<td class="url">' . $e($r['method'] . ' ' . $r['url']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section><p class="note">Updated ' . $e(date('H:i:s', $now)) . ' · the summary, the counts and this list refresh every 10 seconds.</p>';

        return $h . '</main><script>' . self::SCRIPT . '</script></body></html>';
    }

    /**
     * The rules, grouped the way a site owner thinks about them. Each row: its
     * text for people (the rule's description, else what it does), the rule
     * as written when a description stands in front, its ID, where it is
     * written, and the ID the log counts it under. In English or German ($lang);
     * the rules' own descriptions stay as written.
     *
     * @param array<string, int> $claims crawler ID => how often a request named it without coming from it (the log)
     * @param array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>|null $counted what each crawler did in the last days (StatsReport), when counted
     * @return list<array{0: string, 1: string, 2: list<array{text: string, detail: ?string, id: ?string, where: ?string, log: ?string}>}>
     */
    public static function groups(Settings $s, array $claims = [], ?array $counted = null, string $lang = 'en'): array
    {
        $w = static fn (string $en, string ...$a): string => vsprintf($lang === 'de' ? (self::DE[$en] ?? $en) : $en, $a);
        $o = static fn (string $setting, string $what): ?string => $s->origin($setting, $what);
        $row = static function (string $says, ?string $id, ?string $log = null) use ($s, $w): array {
            $text = $id !== null ? $s->origin('text', $id) : null;
            $where = $id !== null ? $s->origin('at', $id) : null;
            if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
            }
            if ($id !== null && $s->origin('area', $id) !== null) {
                $where = ($where ?? $id) . $w(' · in match %s', (string) $s->origin('area', $id));
            }
            return ['text' => $text ?? $says, 'detail' => $text !== null ? $says : null, 'id' => $id, 'where' => $where, 'log' => $log ?? $id];
        };
        $pattern = static fn (string $p): string => Describe::pattern($s, $p);
        $g = [];

        // Kept out and let in: the deny list, exempt (also for a while), the lists' files.
        $rows = [];
        $when = static fn (?int $until): string => $until === null ? '' : $w(' — until %s', date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $until));
        foreach ($s->deny as $e) {
            $rows[] = $row($w('kept out: %s', implode(', ', $e['ips'])) . $when($e['until']), $e['rule'], $e['rule']);
        }
        if ($s->denyCount > count($s->deny)) {
            $rows[] = $row($w('and %s more kept out (bin/request-shield lists)', number_format($s->denyCount - count($s->deny), 0, '', $lang === 'de' ? '.' : ',')), null, '');
        }
        foreach ($s->exemptIps as $ip) {
            $rows[] = $row($w('let in, never counted or checked: %s', $ip), $o('exempt', $ip), '');
        }
        if ($rows !== [] || $s->listsDir !== null) {
            $g[] = [$w('Kept out and let in'), $w('A kept-out address gets 403 before every other check; one let in is never counted or checked, but still refused for blocked addresses and attack patterns.')
                . ($s->listsDir !== null ? $w(' The lists: %s (bin/request-shield deny, allow, unlist, lists).', $s->listsDir) : ''), $rows];
        }

        // Public blocklists (feed …): each with what it does, how many entries, how old.
        $rows = [];
        $actions = ['deny' => $w('kept out: 403'), 'check' => $w('the browser check'), 'signal' => $w('counts towards a ban')];
        foreach (array_merge($s->feeds, $s->monitor !== null ? array_map(static fn (array $f): array => $f + ['watched' => true], $s->monitor->feeds) : []) as $f) {
            $state = $f['state'] === 'in force' ? $w('%s entries, fetched %s', number_format($f['count'], 0, '', $lang === 'de' ? '.' : ','), date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $f['fetched']))
                : ($f['state'] === 'too old' ? $w('too old, not used: fetched %s', $f['fetched'] > 0 ? date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $f['fetched']) : '-') : $w('not fetched yet: bin/request-shield feeds <main.rules> update'));
            $rows[] = $row($w('%s (%s): %s', $f['title'], $f['name'], isset($f['watched']) ? $w('only counted and logged (count)') : ($actions[$f['action']] ?? $f['action'])
                . ($f['action'] === 'signal' ? ' ×' . $f['weight'] : '') . ($f['paths'] !== [] ? ' ' . $w('at %s', implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $f['paths']))) : ''))
                . ' — ' . $state . ($f['terms'] !== '' ? ' — ' . $w('terms: %s', $f['terms']) : ''), $f['rule'], $f['rule']);
        }
        if ($rows !== []) {
            $g[] = [$w('Public blocklists'), $w('Lists fetched by bin/request-shield feeds <main.rules> update (cron), compiled with the rules; nothing is looked up per request and nothing is sent anywhere. Never an address let in, a trusted proxy or a verified crawler; a list older than %s is not used.',
                Describe::span($s->feedsMaxAge, $lang)), $rows];
        }

        // Automatic, temporary bans.
        $rows = [];
        $signals = ['limits' => $w('times past a limit'), 'refusals' => $w('refusals for what only attackers ask for'), 'checks' => $w('check pages not solved')];
        foreach ($s->bans as $b) {
            $rows[] = $row($w('after %s %s in %s: banned for %s', (string) $b['after'], $signals[$b['signal']] ?? $w('"%s" past its limit', $b['signal']),
                Describe::span($b['in'], $lang), Describe::span($b['for'], $lang)), $b['rule'], $b['rule']);
        }
        if ($rows !== []) {
            $g[] = [$w('Banned for a while'), $w('Nothing but 429 with the time to wait, longer each time within a day (%s×, at most %s); never an address let in, a trusted proxy or a verified crawler.',
                (string) $s->banGrowth, Describe::span($s->banMax, $lang)), $rows];
        }

        $rows = [];
        foreach ($s->blockedPaths as $i => $p) {
            $id = $o('blockedPaths', $p);
            $text = $id !== null ? $s->origin('text', $id) : null;
            $text ??= Describe::builtIn($p);
            $says = $pattern($p);
            $where = $id !== null ? $s->origin('at', $id) : null;
            if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
            }
            $rows[] = ['text' => $text ?? $says, 'detail' => $text !== null && $text !== $says ? $says : null, 'id' => $id ?? \CjwNetwork\RequestShield\Config::setName($p),
                'where' => $where, 'log' => $id ?? \CjwNetwork\RequestShield\Config::setName($p) ?? "blockedPaths[$i]"];
        }
        foreach ($s->blockExceptions as $x) {
            $what = $x['patterns'] === null ? $w('every block above') : implode('; ', array_map(static fn (string $p): string => Describe::rule($s, 'blockedPaths', $p), $x['patterns']));
            $rows[] = $row($w('Open at %s: %s', implode(', ', array_map($pattern, $x['paths'])), $what)
                . ($x['ips'] !== [] ? $w(' — only for %s', implode(', ', $x['ips'])) : $w(' — ⚠ for everyone: make sure only admins reach it')),
                $o('blockExceptions', $x['paths'][0] ?? ''), '');
        }
        $g[] = [$w('Addresses only attackers ask for'), $rows === [] ? $w('None are refused.') : $w('Refused with "not found" (404) before the site sees them:'), $rows];

        $rows = [];
        foreach ($s->contentRules as $n => $r) {
            foreach ($r['patterns'] as $p) {
                $id = $o('contentRules', $p);
                $text = $id !== null ? $s->origin('text', $id) : null;
                $where = $id !== null ? $s->origin('at', $id) : null;
                if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                    $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
                }
                // The written form already says where it looks: "query …", "header:user-agent …".
                $says = $pattern($p);
                $rows[] = ['text' => $text ?? $says, 'detail' => $text !== null && $text !== $says ? $says : null, 'id' => $id,
                    'where' => $where, 'log' => $id ?? "contentRules[$n]"];
            }
        }
        if ($rows !== []) {
            $g[] = [$w('Attack patterns in the request'), $w('Refused with "no access" (403), matched on the normalised values:'), $rows];
        }

        $rows = [];
        foreach ($s->queryParams as $n => $q) {
            $names = [];
            foreach ($q['exact'] + $q['globs'] as $name => $type) {
                $shown = strncmp((string) $name, '#^', 2) === 0 ? str_replace('.*', '*', (string) substr((string) $name, 2, -2)) : (string) $name;
                $names[] = stripslashes($shown) . ' (' . (strncmp($type, '#', 1) === 0 ? $w('pattern') : $type) . ')';
            }
            $first = (string) array_key_first($q['exact'] + $q['globs']);
            $rows[] = $row(implode(', ', $names) . ($q['paths'] !== null ? $w(' — at %s', implode(', ', array_map($pattern, $q['paths']))) : ''),
                $o('queryParams', '#' . $n) ?? $o('query', $first), "queryParams[$n]");
        }
        foreach ($s->queryDrop as $n => $p) {
            $rows[] = $row($w('Any other parameter, or a value not of its type, is left out — %s', $pattern($p)), $o('queryDrop', $p) ?? $o('query', 'drop'), "queryDrop[$n]");
        }
        if ($rows !== [] || $s->queryStrict) {
            $g[] = [$w('Known parameters'), $s->queryStrict ? $w('Any other parameter, or a value not of its type, gets "not found" (404):')
                : $w('Values of these types are not scanned by the attack patterns; any other parameter is answered, but not cached:'), $rows];
        }

        $rows = [];
        foreach ($s->restricted as $n => $r) {
            foreach ($r['paths'] as $p) {
                $rows[] = $row($pattern($p) . $w(' — only for %s', implode(', ', $r['ips'])), $o('restricted', $p), $o('restricted', $p) ?? "restricted[$n]");
            }
        }
        $g[] = [$w('Areas for certain visitors'), $rows === [] ? $w('No area is restricted.') : $w('Everyone else gets "no access" (403):'), $rows];

        $rows = [];
        if ($s->postOrigin !== null) {
            $rows[] = $row($w('Forms only from this website (Origin, else Referer); neither: %s', $w($s->postOrigin['missing'] === 'check' ? 'the browser check' : ($s->postOrigin['missing'] === 'allow' ? 'let through' : 'refused')))
                . ($s->postOrigin['except'] !== [] ? $w('; not at: %s', implode(', ', array_map($pattern, $s->postOrigin['except']))) : ''), $o('postOrigin', '*'), $o('postOrigin', '*') ?? 'postOrigin');
        }
        foreach ($s->methodPaths as $m => $patterns) {
            $rows[] = $row($w('%s only at: %s', (string) $m, implode(', ', array_map($pattern, $patterns))), $o('methodPaths', $m), $o('methodPaths', $m) ?? "methodPaths.$m");
        }
        $g[] = [$w('Where forms may be sent'), $w('Accepted kinds of request: %s', implode(', ', $s->methods)) . ($rows === [] ? $w('; forms may be sent anywhere.') : $w('; anywhere else "not allowed here" (405):')), $rows];

        $g[] = [$w('Website names and sizes'), ($s->hosts === [] ? $w('Any website name is accepted. ') : $w('The site answers as %s; any other name gets "not found". ', implode(', ', $s->hosts)))
            . $w('Addresses up to %s characters and %s parameters, headers up to %s KB; disguised addresses and attempts to leave the site\'s folder are refused.',
                (string) $s->maxUri, (string) $s->maxQueryParameters, (string) round($s->maxHeaderBytes / 1024)),
            $s->hosts === [] ? [] : [$row($w('Website names: %s', implode(', ', $s->hosts)), $o('hosts', '*'), $o('hosts', '*') ?? 'hosts')]];

        // One row per rule: a cache-path line often lists several paths.
        $byRule = [];
        foreach ($s->cacheablePaths ?? [] as $p) {
            $byRule[$o('cacheable.paths', $p) ?? ''][] = $pattern($p);
        }
        $rows = [];
        foreach ($byRule as $id => $paths) {
            $rows[] = $row(implode('  ', $paths), $id !== '' ? $id : null, '');
        }
        $query = $s->cacheableQuery === null ? $w('with any parameters') : ($s->cacheableQuery === [] ? $w('without parameters')
            : $w(count($s->cacheableQuery) > 1 ? 'only with the parameters %s' : 'only with the parameter %s', implode(', ', array_map(static fn (string $q): string => "\"$q\"", $s->cacheableQuery))));
        $g[] = [$w('What a cache may keep'), ($s->cacheablePaths === null ? $w('Every address, %s.', $query) : $w('These addresses, %s.', $query))
            . $w(' Anything else is answered by the site, but not kept — so made-up addresses cannot fill a cache.'), $rows];

        $rows = [];
        foreach ($s->budgets as $b) {
            $then = $b->earnBack ? $w(', then the check — solved, the counter starts again') : $w(', then a pause');
            $per = Describe::duration($b->window, $lang);
            $rows[] = $row($b->onDemand
                ? $w('"%s": at most %s per %s, counted by the site itself (searches, failed sign-ins, cache misses)', $b->name, (string) $b->limit, $per) . $then
                : $w('"%s": %s requests per %s', $b->name, (string) $b->limit, $per) . ($b->challengeAt !== null ? $w(', the browser check from %s', (string) $b->challengeAt) : '') . $then,
                $o('budgets', $b->name), $o('budgets', $b->name) ?? "budgets.$b->name");
        }
        $g[] = [$w('Pace per visitor'), $w('Counted per address (IPv6: per /%s network)', (string) $s->ipv6Prefix)
            . ($s->exemptIps === [] ? '.' : $w('; never counted: %s.', implode(', ', $s->exemptIps))), $rows];

        $rows = [];
        foreach ($s->challenge->alwaysPaths as $p) {
            $age = $s->challenge->alwaysMaxAge[$p] ?? null;
            $methods = $s->challenge->alwaysMethods[$p] ?? [];
            $rows[] = $row($w('always checked: %s', $pattern($p)) . ($methods !== [] ? $w(' — only %s', implode(', ', $methods)) : '')
                . ($age !== null ? $w(' — a pass from the last %s', Describe::span($age, $lang)) : ''),
                $o('challenge.alwaysPaths', $p), $o('challenge.alwaysPaths', $p) ?? 'challenge.alwaysPaths');
        }
        foreach ($s->challenge->exemptPaths as $p) {
            $rows[] = $row($w('never checked: %s', $pattern($p)), $o('challenge.exemptPaths', $p), '');
        }
        $g[] = [$w('Browser check'), $w('An invisible check that a real browser passes in a moment; a passed check is valid for %s', Describe::span($s->challenge->passTtl, $lang))
            . ($s->crawlers !== [] ? $w('. Known crawlers (search engines, AI crawlers) are recognised by their address, see below.') : '.'), $rows];

        $rows = [];
        $kinds = ['search' => $w('search engine'), 'ai-search' => $w('AI search'), 'ai-user' => $w('fetches what a person asks for'), 'ai-training' => $w('collects for AI training')];
        $policies = ['allow' => $w('let through (never the browser check)'), 'check' => $w('checked like any visitor'), 'block' => $w('refused (403)')];
        foreach ($s->crawlers as $id => $x) {
            $how = [];
            if ($x['ranges'] !== [] && $s->crawlerVerify !== 'dns') {
                $dates = array_filter(array_map(static fn (array $l): string => substr((string) ($l['created'] ?? ''), 0, 10), $x['lists']));
                $how[] = $w('its published address list (%s ranges', (string) count($x['ranges'])) . ($dates !== [] ? $w(', of %s', min($dates)) : '') . ')';
            }
            if ($x['dns'] !== [] && $s->crawlerVerify !== 'ranges') {
                $how[] = 'DNS (' . implode(', ', $x['dns']) . ')';
            }
            $claimed = $claims[$id] ?? 0;
            $rows[] = $row(($kinds[$x['kind']] ?? $x['kind']) . ': ' . ($policies[$x['policy']] ?? $x['policy'])
                . ($how === [] ? $w(' — verified by nothing here (crawler-verify %s): an ordinary visitor', $s->crawlerVerify) : $w(' — verified by %s', implode($w(' or '), $how)))
                . ($claimed > 0 ? $w(' · %s× only claimed in 24 h', (string) $claimed) : '') . self::counted($counted[$id] ?? null), (string) $id, '');
        }
        if ($rows !== []) {
            $g[] = [$w('Known crawlers'), $w('Search engines and AI crawlers that behave are recognised by where they come from — the name a request sends proves nothing. ')
                . $w('One that only borrows a name is an ordinary visitor (the log notes it: claimed=…). Change it per kind or crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.'), $rows];
        }

        $rows = [];
        foreach ($s->origins['monitor'] ?? [] as $rid => $rule) {
            $rows[] = $row('monitor ' . $rule, (string) $rid);
        }
        if ($rows !== []) {
            $g[] = [$w('Watched, not enforced'), $w('Rules marked "monitor": checked on every request and logged as they would decide ("monitor-reject" …), but nobody is refused. When the log shows no false hits, remove the word.'), $rows];
        }

        $g[] = [$w('Proxies and the log'), ($s->trustedProxies === [] ? $w('No proxy: the visitor\'s address is taken from the connection. ') : $w('The visitor\'s real address is believed only from %s. ', implode(', ', $s->trustedProxies)))
            . ($s->logFile === null ? $w('No log.') : $w('Log: level "%s", addresses %s.', $s->logLevel, $s->logIp === 'full' ? $w('in full') : $w('anonymised'))), []];
        return $g;
    }

    /** groups() in German: the English text => its translation (%s: the same values). */
    private const DE = [
        ' — until %s' => ' — bis %s', 'kept out: %s' => 'ausgesperrt: %s', 'and %s more kept out (bin/request-shield lists)' => 'und %s weitere ausgesperrt (bin/request-shield lists)', 'let in, never counted or checked: %s' => 'hereingelassen, nie gezählt oder geprüft: %s', 'Kept out and let in' => 'Ausgesperrt und hereingelassen',
        'A kept-out address gets 403 before every other check; one let in is never counted or checked, but still refused for blocked addresses and attack patterns.' => 'Eine ausgesperrte Adresse bekommt 403 vor jeder anderen Prüfung; eine hereingelassene wird nie gezählt oder geprüft, aber bei gesperrten Adressen und Angriffsmustern trotzdem abgewiesen.',
        ' The lists: %s (bin/request-shield deny, allow, unlist, lists).' => ' Die Listen: %s (bin/request-shield deny, allow, unlist, lists).', 'times past a limit' => 'Mal über einer Grenze',
        'refusals for what only attackers ask for' => 'Abweisungen für das, was nur Angreifer aufrufen', 'check pages not solved' => 'nicht gelöste Check-Seiten', '"%s" past its limit' => '„%s“ über seiner Grenze',
        'after %s %s in %s: banned for %s' => 'nach %s %s in %s: gesperrt für %s',
        'kept out: 403' => 'ausgesperrt: 403', 'the browser check' => 'der Browser-Check', 'counts towards a ban' => 'zählt für eine Sperre', '%s entries, fetched %s' => '%s Einträge, geholt %s',
        'too old, not used: fetched %s' => 'zu alt, nicht benutzt: geholt %s', 'not fetched yet: bin/request-shield feeds <main.rules> update' => 'noch nicht geholt: bin/request-shield feeds <main.rules> update',
        '%s (%s): %s' => '%s (%s): %s', 'only counted and logged (count)' => 'nur gezählt und geloggt (count)', 'at %s' => 'an %s', 'terms: %s' => 'Bedingungen: %s',
        'Public blocklists' => 'Öffentliche Sperrlisten',
        'Lists fetched by bin/request-shield feeds <main.rules> update (cron), compiled with the rules; nothing is looked up per request and nothing is sent anywhere. Never an address let in, a trusted proxy or a verified crawler; a list older than %s is not used.'
            => 'Listen, geholt von bin/request-shield feeds <main.rules> update (cron), mit den Regeln übersetzt; pro Anfrage wird nichts nachgeschlagen und nichts verschickt. Nie eine hereingelassene Adresse, ein vertrauenswürdiger Proxy oder ein bestätigter Crawler; eine Liste älter als %s wird nicht benutzt.', 'Banned for a while' => 'Für eine Weile gesperrt',
        'Nothing but 429 with the time to wait, longer each time within a day (%s×, at most %s); never an address let in, a trusted proxy or a verified crawler.' => 'Nur noch 429 mit der Wartezeit, bei jeder Wiederholung am selben Tag länger (%s×, höchstens %s); nie eine hereingelassene Adresse, ein vertrauenswürdiger Proxy oder ein bestätigter Crawler.',
        ' · revision %s' => ' · Revision %s', ' · in match %s' => ' · im match %s', 'every block above' => 'jede Sperre oben',
        'Open at %s: %s' => 'Offen unter %s: %s', ' — only for %s' => ' — nur für %s', ' — ⚠ for everyone: make sure only admins reach it' => ' — ⚠ für alle: nur Admins dürfen dorthin kommen',
        'Addresses only attackers ask for' => 'Adressen, die nur Angreifer aufrufen', 'None are refused.' => 'Keine wird abgewiesen.',
        'Refused with "not found" (404) before the site sees them:' => 'Abgewiesen mit „nicht gefunden“ (404), bevor die Website sie sieht:',
        'Attack patterns in the request' => 'Angriffsmuster in der Anfrage', 'Refused with "no access" (403), matched on the normalised values:' => 'Abgewiesen mit „kein Zugriff“ (403), geprüft auf den normalisierten Werten:',
        'pattern' => 'Muster', ' — at %s' => ' — unter %s', 'Known parameters' => 'Bekannte Parameter',
        'Any other parameter, or a value not of its type, gets "not found" (404):' => 'Jeder andere Parameter oder ein Wert nicht seines Typs bekommt „nicht gefunden“ (404):',
        'Values of these types are not scanned by the attack patterns; any other parameter is answered, but not cached:' => 'Werte dieser Typen prüfen die Angriffsmuster nicht; jeder andere Parameter wird beantwortet, aber nicht gecacht:',
        'Areas for certain visitors' => 'Bereiche für bestimmte Besucher', 'No area is restricted.' => 'Kein Bereich ist beschränkt.', 'Everyone else gets "no access" (403):' => 'Alle anderen bekommen „kein Zugriff“ (403):',
        '%s only at: %s' => '%s nur unter: %s',
        'Forms only from this website (Origin, else Referer); neither: %s' => 'Formulare nur von dieser Website (Origin, sonst Referer); ohne beides: %s',
        '; not at: %s' => '; nicht unter: %s', 'let through' => 'durchgelassen', 'refused' => 'abgewiesen', 'Where forms may be sent' => 'Wohin Formulare dürfen', 'Accepted kinds of request: %s' => 'Erlaubte Arten von Anfragen: %s',
        '; forms may be sent anywhere.' => '; Formulare dürfen überallhin.', '; anywhere else "not allowed here" (405):' => '; überall sonst „hier nicht erlaubt“ (405):',
        'Website names and sizes' => 'Namen und Größen', 'Any website name is accepted. ' => 'Jeder Name der Website wird angenommen. ',
        'The site answers as %s; any other name gets "not found". ' => 'Die Website antwortet als %s; jeder andere Name bekommt „nicht gefunden“. ',
        'Addresses up to %s characters and %s parameters, headers up to %s KB; disguised addresses and attempts to leave the site\'s folder are refused.' => 'Adressen bis %s Zeichen und %s Parameter, Header bis %s KB; getarnte Adressen und Versuche, den Ordner der Website zu verlassen, werden abgewiesen.',
        'Website names: %s' => 'Namen der Website: %s', 'with any parameters' => 'mit beliebigen Parametern', 'without parameters' => 'ohne Parameter',
        'only with the parameters %s' => 'nur mit den Parametern %s', 'only with the parameter %s' => 'nur mit dem Parameter %s', 'What a cache may keep' => 'Was ein Cache behalten darf',
        'Every address, %s.' => 'Jede Adresse, %s.', 'These addresses, %s.' => 'Diese Adressen, %s.',
        ' Anything else is answered by the site, but not kept — so made-up addresses cannot fill a cache.' => ' Alles andere beantwortet die Website, aber es wird nicht behalten — so können erfundene Adressen keinen Cache füllen.',
        ', then the check — solved, the counter starts again' => ', dann der Check — gelöst, beginnt der Zähler neu', ', then a pause' => ', dann eine Pause',
        '"%s": at most %s per %s, counted by the site itself (searches, failed sign-ins, cache misses)' => '„%s“: höchstens %s pro %s, gezählt von der Website selbst (Suchen, fehlgeschlagene Anmeldungen, Cache-Fehlgriffe)',
        '"%s": %s requests per %s' => '„%s“: %s Anfragen pro %s', ', the browser check from %s' => ', der Browser-Check ab %s', 'Pace per visitor' => 'Tempo pro Besucher',
        'Counted per address (IPv6: per /%s network)' => 'Gezählt pro Adresse (IPv6: pro /%s-Netz)', '; never counted: %s.' => '; nie gezählt: %s.',
        'always checked: %s' => 'immer geprüft: %s', ' — only %s' => ' — nur %s', ' — a pass from the last %s' => ' — ein Pass aus den letzten %s', 'never checked: %s' => 'nie geprüft: %s', 'Browser check' => 'Browser-Check',
        'An invisible check that a real browser passes in a moment; a passed check is valid for %s' => 'Ein unsichtbarer Check, den ein echter Browser in einem Moment besteht; ein bestandener Check gilt %s',
        '. Known crawlers (search engines, AI crawlers) are recognised by their address, see below.' => '. Bekannte Crawler (Suchmaschinen, KI-Crawler) werden an ihrer Adresse erkannt, siehe unten.',
        'search engine' => 'Suchmaschine', 'AI search' => 'KI-Suche', 'fetches what a person asks for' => 'holt, was eine Person fragt', 'collects for AI training' => 'sammelt für KI-Training',
        'let through (never the browser check)' => 'durchgelassen (nie der Browser-Check)', 'checked like any visitor' => 'geprüft wie jeder Besucher', 'refused (403)' => 'abgewiesen (403)',
        'its published address list (%s ranges' => 'seine veröffentlichte Adressliste (%s Bereiche', ', of %s' => ', vom %s', ' or ' => ' oder ',
        ' — verified by nothing here (crawler-verify %s): an ordinary visitor' => ' — hier durch nichts bestätigt (crawler-verify %s): ein gewöhnlicher Besucher', ' — verified by %s' => ' — bestätigt über %s',
        ' · %s× only claimed in 24 h' => ' · %s× nur behauptet in 24 h', 'Known crawlers' => 'Bekannte Crawler',
        'Search engines and AI crawlers that behave are recognised by where they come from — the name a request sends proves nothing. ' => 'Suchmaschinen und KI-Crawler, die sich benehmen, werden daran erkannt, woher sie kommen — der Name, den eine Anfrage schickt, beweist nichts. ',
        'One that only borrows a name is an ordinary visitor (the log notes it: claimed=…). Change it per kind or crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.' => 'Wer sich nur einen Namen leiht, ist ein gewöhnlicher Besucher (das Log vermerkt es: claimed=…). Ändern pro Art oder Crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.',
        'Watched, not enforced' => 'Beobachtet, nicht durchgesetzt',
        'Rules marked "monitor": checked on every request and logged as they would decide ("monitor-reject" …), but nobody is refused. When the log shows no false hits, remove the word.' => 'Regeln mit „monitor“: bei jeder Anfrage geprüft und so geloggt, wie sie entscheiden würden („monitor-reject“ …), aber niemand wird abgewiesen. Zeigt das Log keine Fehltreffer, das Wort entfernen.',
        'Proxies and the log' => 'Proxys und Log', 'No proxy: the visitor\'s address is taken from the connection. ' => 'Kein Proxy: die Adresse des Besuchers kommt aus der Verbindung. ',
        'The visitor\'s real address is believed only from %s. ' => 'Die echte Adresse des Besuchers wird nur von %s geglaubt. ', 'No log.' => 'Kein Log.',
        'Log: level "%s", addresses %s.' => 'Log: Stufe „%s“, Adressen %s.', 'in full' => 'vollständig', 'anonymised' => 'anonymisiert',
    ];

    /** The mode in plain words, or null for the usual one (enforce, nothing watched). */
    public static function mode(Settings $s, string $lang = 'en'): ?string
    {
        $de = $lang === 'de';
        $watched = count($s->origins['monitor'] ?? []);
        $also = $watched === 0 ? '' : ($de ? " $watched " . ($watched === 1 ? 'Regel wird' : 'Regeln werden') . ' nur beobachtet (monitor): geloggt, wie sie entscheiden würden, nicht durchgesetzt.'
            : " $watched " . ($watched === 1 ? 'rule is' : 'rules are') . ' only watched (monitor): logged as they would decide, not enforced.');
        switch ($s->mode) {
            case 'off':
                return $de ? 'Der Schutz ist abgeschaltet (set mode off): nichts wird geprüft, gezählt oder geloggt.'
                    : 'The shield is switched off (set mode off): nothing is checked, counted or logged.';
            case 'monitor':
                return $de ? 'Beobachtungsmodus (set mode monitor): jede Regel wird geprüft und gezählt, das Log zeigt, was sie entschieden hätte'
                    . ' — niemand wird abgewiesen, nichts Abgewiesenes gecacht. Auf enforce umstellen, wenn das Log keine Fehltreffer zeigt.'
                    : 'Monitor mode (set mode monitor): every rule is checked and counted, and the log shows what it would have decided'
                    . ' — nobody is refused, nothing refused is cached. Switch to enforce when the log shows no false hits.';
            case 'strict':
                return ($de ? 'Strenger Modus (set mode strict), für eine Website unter Angriff: der Browser-Check ab einem Viertel jedes Limits, ein Pass für '
                    . Describe::span($s->challenge->passTtl, 'de') . ', Adressen, die ein Cache nicht behalten darf, zählen doppelt.'
                    : 'Strict mode (set mode strict), for a site under attack: the browser check from a quarter of each limit, a pass for '
                    . Describe::span($s->challenge->passTtl) . ', addresses a cache must not keep count twice.') . $also;
        }
        return $also === '' ? null : trim($also);
    }

    /**
     * What a crawler did in the last 7 days, in words (set stats on).
     *
     * @param array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}|null $c a crawler of StatsReport
     */
    private static function counted(?array $c): string
    {
        if ($c === null) {
            return '';
        }
        $verified = $c['verified'];
        if ($verified === 0) {
            return ' · no verified visit in 7 days';
        }
        $got = [];
        foreach (['allowed' => 'let through', 'checked' => 'checked', 'throttled' => 'told to wait', 'refused' => 'refused'] as $e => $word) {
            if ($c[$e] > 0) {
                $got[] = number_format($c[$e]) . ' ' . $word;
            }
        }
        $last = $c['last'] !== null ? ', last ' . date('Y-m-d H:i', $c['last'][0]) : '';
        return ' · 7 days: ' . number_format($verified) . ' visits (' . implode(', ', $got) . ')' . $last;
    }

    private static function tile(string $n, string $label): string
    {
        return '<div class="tile"><span class="n">' . htmlspecialchars($n, ENT_QUOTES) . '</span>' . htmlspecialchars($label, ENT_QUOTES) . '</div>';
    }

    private static function icon(string $state): string
    {
        return ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'][$state] ?? '';
    }

    private static function happened(string $action, int $status): string
    {
        if (strncmp($action, 'monitor-', 8) === 0) {
            return 'watched, would have been ' . self::happened((string) substr($action, 8), $status);
        }
        return ['reject' => "refused ($status)", 'throttle' => 'told to wait', 'challenge' => 'browser check', 'allow-uncached' => 'answered, not cached', 'allow' => 'answered'][$action] ?? $action;
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ago';
        }
        return intdiv($seconds, 3600) . ' h ago';
    }

    /** The browser check in plain words (docs/explained/browser-check.md has more). */
    private const BROWSER_CHECK = '<details class="explain"><summary>How does it work — and what does it bring?</summary>'
        . '<p>Instead of the page, a visitor who asks too often (or opens a page where everyone is checked) gets a small page for a moment: '
        . '<em>"One moment, please"</em>. Its script solves a small arithmetic task — a real browser needs a fraction of a second, nothing to click — '
        . 'and gets a <strong>pass</strong> for the time shown above. Search engines are recognised and never checked.</p>'
        . '<ol><li>The shield sends a task, signed so it cannot be forged: find the number that gives a certain result.</li>'
        . '<li>The browser tries numbers until it finds it — some tens of thousands of attempts, invisible.</li>'
        . '<li>It sends the answer back; the shield checks it in well under a millisecond, and every answer counts only once.</li>'
        . '<li>A signed cookie is the pass: the next pages go straight through.</li></ol>'
        . '<p><strong>What it brings:</strong> simple bots and scrapers run no JavaScript and never get past the small page — the site renders nothing for them. '
        . 'Bots with a real browser engine pay computing time for every pass, and more the more aggressive they are. '
        . 'Real visitors see it at most once in a while. Nothing comes from or goes to a third party: no Google, no Cloudflare, no tracking.</p>'
        . '%DIAGRAM%'
        . '<p><strong>What it does not do:</strong> it is no CAPTCHA against people, and an attacker with many real browsers gets through — slower and at their own cost.</p>'
        . '</details>';

    private const CSS = <<<'CSS'
:root{--bg:#f6f7f9;--fg:#1d2127;--muted:#5b6470;--card:#fff;--line:#dfe3e8;--accent:#2f62c9;--ok:#1e7b43;--okbg:#e6f4ea;--warn:#8a5a00;--warnbg:#fdf3dc;--no:#a3361f;--nobg:#fbe9e5;--skip:#8a929c}
@media (prefers-color-scheme:dark){:root{--bg:#15181c;--fg:#e7e9ec;--muted:#a0a8b3;--card:#1d2127;--line:#2d333b;--accent:#7aa2ff;--ok:#5fcf8a;--okbg:#17301f;--warn:#f0c060;--warnbg:#3a2f15;--no:#ff8a70;--nobg:#3d1f19;--skip:#6b737d}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
header.bar{background:var(--card);border-bottom:1px solid var(--line)}header.bar div{max-width:60rem;margin:0 auto;padding:.7rem 1rem}header.bar a{font-weight:700;color:var(--fg);text-decoration:none}
main{max-width:60rem;margin:0 auto;padding:1.5rem 1rem 3rem}h1{font-size:1.6rem;margin:.2rem 0}h2{font-size:1.2rem;margin:2rem 0 .6rem}h3{font-size:1.02rem;margin:0 0 .3rem}
.lead,.note,.intro{color:var(--muted)}.lead{margin:0 0 1.2rem}.note{font-size:.9rem}.intro{margin:.1rem 0 .6rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.2rem;margin-bottom:.8rem}.card h2{margin-top:0}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.6rem;margin-bottom:1rem}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;color:var(--muted);font-size:.9rem}.tile .n{display:block;font-size:1.6rem;font-weight:650;color:var(--fg)}
table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.45rem .4rem;border-top:1px solid var(--line);vertical-align:top}tr:first-child td,tr:first-child th{border-top:0}
td.meta{width:1%;white-space:nowrap}td.hits{width:1%;white-space:nowrap;text-align:right}td.url{word-break:break-all;font:13px/1.4 ui-monospace,monospace}
code{font:13px/1.4 ui-monospace,monospace}code.rule{color:var(--muted);font-size:12px}code.origin{color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:0 .3rem}
.badge{display:inline-block;min-width:1.6rem;text-align:center;background:var(--accent);color:#fff;border-radius:99px;padding:0 .45rem;font-size:.85rem;font-weight:600}
form.try{display:flex;gap:.5rem;flex-wrap:wrap}form.try input,form.try select{padding:.45rem .6rem;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);font:inherit}
form.try input[name=url]{flex:1 1 18rem}form.try input.ip{flex:0 1 11rem}
button{padding:.45rem 1rem;border:0;border-radius:6px;background:var(--accent);color:#fff;cursor:pointer;font:inherit}
.verdict{display:flex;gap:.7rem;align-items:flex-start;border-radius:8px;padding:.8rem 1rem;margin:1rem 0 .6rem}
.verdict.pass{background:var(--okbg)}.verdict.note{background:var(--warnbg)}.verdict.stop{background:var(--nobg)}
ol.steps{list-style:none;margin:0;padding:0}ol.steps li{display:flex;gap:.7rem;padding:.45rem 0;border-top:1px solid var(--line)}ol.steps li:first-child{border-top:0}
.icon{flex:0 0 1.5rem;height:1.5rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff}
.pass .icon{background:var(--ok)}.note .icon{background:var(--warn)}.stop .icon{background:var(--no)}.skip .icon{background:var(--skip)}.skip{color:var(--skip)}
.diagram{overflow-x:auto;margin:.8rem 0}.diagram svg{min-width:40rem;max-width:100%;height:auto}details.explain{margin-top:.8rem;border-top:1px solid var(--line);padding-top:.6rem}details.explain summary{cursor:pointer;font-weight:600;color:var(--accent)}details.explain ol{padding-left:1.3rem}
table.recent .icon{width:1.3rem;height:1.3rem;display:inline-flex;font-size:.75rem}
@media (max-width:40rem){td.meta,td.hits{white-space:normal}table.recent th:nth-child(2),table.recent td:nth-child(2){display:none}}
CSS . Help::CSS;

    /** Refreshes the summary, the counts and the latest activity every 10 seconds. */
    private const SCRIPT = <<<'JS'
(function () {
  if (!window.fetch || !window.DOMParser) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(location.href, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      if (!html) { return; }
      var fresh = new DOMParser().parseFromString(html, 'text/html');
      document.querySelectorAll('[data-live][id]').forEach(function (el) {
        var n = fresh.getElementById(el.id);
        if (n) { el.innerHTML = n.innerHTML; }
      });
    }).catch(function () {});
  }, 10000);
})();
JS;
}
}
}

// plugins/waf/src/SetupPage.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Counts;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\Store;

if (true) {
/**
 * "Rules & setup", the statistics page's fourth view: the way a request takes
 * through the shield, step by step with what is switched on; every rule in
 * words with its ID, where it is written and how often it decided; and every
 * technical setting -- for admins, in English or German.
 *
 * It shows how the site is protected (and its paths on the server), so it
 * belongs behind the admin login or a restrict rule, like the other views.
 * The challenge secret is never shown.
 */
final class SetupPage implements \CjwNetwork\RequestShield\RoutePage
{
    private const T = [
        'en' => [
            'way' => 'The way of a request', 'wayIntro' => 'Every request passes these checks in this order, before the site\'s code runs. The first that refuses ends it; a browser check or "not kept by a cache" lets the rest still look. Coloured: on, grey: off — a circle leads to its line below.',
            'on' => 'on', 'off' => 'off', 'always' => 'always', 'answers' => 'answers', 'rulesN' => 'rules', 'rules' => 'The rules', 'rulesIntro' => 'As the rule files hold them, one per file, in their order: the rule\'s ID, what it does (the comment after it), how it is written, its line — and how often it decided in this period (statistics). Click a file to open or close it.',
            'settings' => 'Technical settings', 'settingsIntro' => 'Everything the shield runs with, as compiled from the rule files.', 'decided' => 'decided', 'where' => 'where', 'none' => 'none',
            'mode' => 'Mode', 'modeEnforce' => 'enforce: every rule decides', 'modeMonitor' => 'monitor: checked and counted, nobody refused', 'modeStrict' => 'strict: under attack -- checks from a quarter of each limit', 'modeOff' => 'off: nothing is checked',
            'yes' => 'yes', 'no' => 'no', 'files' => 'Rule files', 'versions' => 'Rule sets',
            // The steps
            's.deny' => 'Kept out (deny list)', 's.feed' => 'Public blocklists', 's.ban' => 'Banned for a while', 's.method' => 'Kind of request', 's.size' => 'Sizes', 's.sanity' => 'Disguised addresses', 's.host' => 'Website names',
            's.blocked' => 'Addresses only attackers ask for', 's.methodPaths' => 'Where forms may be sent', 's.restricted' => 'Areas for certain visitors', 's.crawlers' => 'Known crawlers',
            's.query' => 'Known parameters', 's.attacks' => 'Attack patterns', 's.cache' => 'What a cache may keep', 's.budgets' => 'Pace per visitor', 's.check' => 'Browser check',
            'd.client' => 'from the connection%s; IPv6 counted per /%d network%s', 'd.proxies' => ', X-Forwarded-* believed only from %s', 'd.exempt' => '; never counted: %s',
            'd.deny' => '%d entries%s', 'd.feed' => '%d entries', 'd.notfetched' => 'not fetched yet: request-shield feeds … update', 'd.tooold' => 'too old: not used', 'd.paths' => 'paths', 'd.ban' => '%d rules; a ban %d× longer each time within a day, at most %s', 'd.next' => ', the next ends %s', 'd.method' => 'accepted: %s', 'd.size' => 'address up to %d characters, %d parameters, headers up to %d KB', 'd.sanity' => 'no hidden encoding, no way out of the website\'s folder',
            'd.hostAny' => 'any name', 'd.hosts' => 'only %s', 'd.blocked' => '%d patterns%s', 'd.exceptions' => ', %d opened again (unblock)', 'd.methodPaths' => '%s only at certain addresses', 'd.none' => 'none',
            'd.restricted' => '%d areas', 'd.crawlers' => '%d crawlers, verified by %s', 'd.query' => '%d rules%s', 'd.strict' => ', anything else: 404 (query strict)', 'd.loose' => ', anything else answered but not cached',
            'd.attacks' => '%d patterns in %d rules', 'd.cacheAll' => 'every address', 'd.cachePaths' => '%d address patterns', 'd.cacheQ' => ', parameters: %s', 'd.budgets' => '%d budgets: %s',
            'd.check' => 'a pass is valid %s%s', 'd.alwaysAt' => '; always at %d addresses', 'd.widget' => '; in forms at %s',
            'a.429' => '429 (Retry-After)', 'a.405' => '405', 'a.400' => '400, 414, 431', 'a.404' => '404', 'a.403' => '403', 'a.pass' => 'answered, not kept', 'a.check' => 'browser check, then 429', 'a.page' => 'the check page',
            // The settings' groups
            'g.run' => 'Operation', 'g.pages' => 'Error pages', 'g.clients' => 'Visitors and proxies', 'g.limits' => 'Requests', 'g.store' => 'Counters', 'g.check' => 'Browser check', 'g.crawlers' => 'Known crawlers', 'g.stats' => 'Statistics', 'g.log' => 'Log', 'g.files' => 'Rule files',
            'k.mode' => 'mode', 'k.monitor' => 'rules only watched (monitor)', 'k.debug' => 'X-RS header', 'k.app' => 'the site may ask for the check', 'k.errorPages' => 'error pages of the site\'s own', 'k.builtIn' => 'none: the shield\'s own',
            'k.proxies' => 'trusted proxies', 'k.strip' => 'X-Forwarded-* from others', 'k.stripYes' => 'removed', 'k.stripNo' => 'ignored', 'k.ipv6' => 'IPv6 counted per', 'k.exempt' => 'never counted',
            'k.methods' => 'accepted methods', 'k.hosts' => 'website names', 'k.uri' => 'longest address', 'k.params' => 'most parameters', 'k.headers' => 'most header data', 'k.strictQ' => 'query strict',
            'k.store' => 'store', 'k.storeDir' => 'store directory', 'k.weight' => 'a request a cache must not keep counts', 'k.budgets' => 'budgets',
            'k.secret' => 'secret', 'k.secretSet' => 'set (never shown)', 'k.secretNone' => 'not set: one is generated and kept in the store directory (one server only)', 'k.pass' => 'a pass is valid', 'k.solution' => 'an answer is valid', 'k.difficulty' => 'difficulty',
            'k.cookie' => 'cookies', 'k.bindUa' => 'pass bound to the browser', 'k.language' => 'language', 'k.home' => 'link to the home page', 'k.widget' => 'check in forms', 'k.api' => 'API addresses (answer in a header)', 'k.logo' => 'logo', 'k.dns' => 'DNS lookups a minute (all requests)',
            'k.crawlers' => 'known crawlers', 'k.verify' => 'verified by', 'k.policy' => 'policies as written', 'k.crawlerLog' => 'crawler logs', 'k.crawlerLogKeep' => 'kept', 'k.crawlerLogQuery' => 'with the query',
            'k.stats' => 'statistics', 'k.parts' => 'what is counted', 'k.depth' => 'section levels', 'k.sites' => 'websites counted apart (stats-hosts)', 'k.oneStats' => 'one statistics for all', 'k.groups' => 'groups of websites (stats-group)', 'k.skip' => 'not counted when they pass (stats-skip)', 'k.flush' => 'written to disk every', 'k.hours' => 'hours kept', 'k.days' => 'days kept', 'k.months' => 'months kept', 'k.dashboard' => 'dashboard',
            'k.log' => 'log file', 'k.logLevel' => 'level', 'k.logIp' => 'addresses', 'k.logSize' => 'rotated at', 'k.forGood' => 'for good', 'k.full' => 'in full', 'k.masked' => 'anonymised', 'k.days1' => 'days', 'k.seconds' => 's', 'k.onlyHourly' => 'only hourly',
            'k.ranges' => 'published address lists', 'k.dnsV' => 'DNS', 'k.both' => 'address lists or DNS',
            'x.request' => 'Request', 'x.before' => 'the visitor\'s address', 'x.beforeName' => 'Before: the visitor\'s address', 'x.site' => 'Your site', 'x.siteSub' => 'all checks passed',
            'x.answer' => 'The shield answers', 'x.answerSub' => '404 · 403 · 405 · 429 · check', 'x.after' => 'After: log and statistics', 'x.feeds' => 'person · crawler · bot',
            'x.logOff' => 'Log: off.', 'x.log.stop' => 'Log: at once, only what was stopped (level stop).', 'x.log.flag' => 'Log: at once, everything not simply let through — refused, checked, not cached (level flag).',
            'x.log.all' => 'Log: every request, at once (level all).', 'x.log.off' => 'Log: off.',
            'x.stats' => 'Statistics: what the shield stopped is counted at once; what goes on to the site when the request ends, with the site\'s status (page views, 404s, links).',
            'x.parts' => 'Counted: %s; ', 'x.apcu' => 'in APCu, written to disk every %s — after the answer, nobody waits.', 'x.files' => 'one line appended to a file per request; the hour summed up after the answer.',
            'x.statsOff' => 'Statistics: off.', 'x.noFile' => 'built into the settings (no rule file)', 'x.line' => 'line',
            'l.deny' => 'Denied', 'l.feed' => 'Lists', 'l.ban' => 'Banned', 'l.method' => 'Kind', 'l.size' => 'Size', 'l.sanity' => 'Disguise', 'l.host' => 'Name', 'l.blocked' => 'Blocked', 'l.methodPaths' => 'Forms', 'l.restricted' => 'Areas',
            'l.crawlers' => 'Crawlers', 'l.query' => 'Params', 'l.attacks' => 'Attacks', 'l.cache' => 'Cache', 'l.budgets' => 'Pace', 'l.check' => 'Check', 'l.origin' => 'Origin',
            's.origin' => 'Where forms come from', 'd.origin' => 'forms only from the website\'s own pages (post-origin same); without Origin and Referer: %s', 'd.plugin' => 'a plugin\'s own rule', 'a.plugin' => 'what the plugin decides',
            'try' => 'Rule tester', 'tryIntro' => 'Enter an address (a full URL or a path) and the visitor\'s IP: every step shows what it makes of it, and which rule decides. Nothing is counted; the pace uses the visitor\'s real counters.',
            'tryUrl' => 'Address', 'tryIp' => 'Visitor\'s address (IP)', 'tryKind' => 'Kind of request', 'tryUa' => 'User-Agent (optional)', 'tryButton' => 'Test',
            'tryResult' => 'This visitor %s.', 'tryRule' => 'Decided by', 'tryWatched' => 'Watched: it %s.',
        ],
        'de' => [
            'way' => 'Der Weg einer Anfrage', 'wayIntro' => 'Jede Anfrage durchläuft diese Prüfungen in dieser Reihenfolge, bevor der Code der Website läuft. Die erste, die abweist, beendet sie; ein Browser-Check oder „nicht im Cache“ lässt die übrigen noch prüfen. Farbig: an, grau: aus — ein Kreis führt zu seiner Zeile darunter.',
            'on' => 'an', 'off' => 'aus', 'always' => 'immer', 'answers' => 'antwortet', 'rulesN' => 'Regeln', 'rules' => 'Die Regeln', 'rulesIntro' => 'So, wie die Regeldateien sie enthalten, eine pro Datei, in ihrer Reihenfolge: die ID der Regel, was sie tut (der Kommentar dahinter), wie sie geschrieben ist, ihre Zeile — und wie oft sie in diesem Zeitraum entschieden hat (Statistik). Eine Datei anklicken, um sie auf- oder zuzuklappen.',
            'settings' => 'Technische Einstellungen', 'settingsIntro' => 'Alles, womit der Schutz läuft – so, wie es aus den Regeldateien übersetzt wurde.', 'decided' => 'entschieden', 'where' => 'wo', 'none' => 'keine',
            'mode' => 'Modus', 'modeEnforce' => 'enforce: jede Regel entscheidet', 'modeMonitor' => 'monitor: geprüft und gezählt, niemand abgewiesen', 'modeStrict' => 'strict: unter Angriff – Checks ab einem Viertel jedes Limits', 'modeOff' => 'off: nichts wird geprüft',
            'yes' => 'ja', 'no' => 'nein', 'files' => 'Regeldateien', 'versions' => 'Regelsätze',
            's.deny' => 'Ausgesperrt (Sperrliste)', 's.feed' => 'Öffentliche Sperrlisten', 's.ban' => 'Für eine Weile gesperrt', 's.method' => 'Art der Anfrage', 's.size' => 'Größen', 's.sanity' => 'Getarnte Adressen', 's.host' => 'Namen der Website',
            's.blocked' => 'Adressen, die nur Angreifer aufrufen', 's.methodPaths' => 'Wohin Formulare dürfen', 's.restricted' => 'Bereiche für bestimmte Besucher', 's.crawlers' => 'Bekannte Crawler',
            's.query' => 'Bekannte Parameter', 's.attacks' => 'Angriffsmuster', 's.cache' => 'Was ein Cache behalten darf', 's.budgets' => 'Tempo pro Besucher', 's.check' => 'Browser-Check',
            'd.client' => 'aus der Verbindung%s; IPv6 gezählt pro /%d-Netz%s', 'd.proxies' => ', X-Forwarded-* nur von %s geglaubt', 'd.exempt' => '; nie gezählt: %s',
            'd.deny' => '%d Einträge%s', 'd.feed' => '%d Einträge', 'd.notfetched' => 'noch nicht geholt: request-shield feeds … update', 'd.tooold' => 'zu alt: nicht benutzt', 'd.paths' => 'Pfade', 'd.ban' => '%d Regeln; eine Sperre %d× länger bei jeder Wiederholung am selben Tag, höchstens %s', 'd.next' => ', der nächste endet %s', 'd.method' => 'erlaubt: %s', 'd.size' => 'Adresse bis %d Zeichen, %d Parameter, Header bis %d KB', 'd.sanity' => 'keine versteckte Kodierung, kein Weg aus dem Ordner der Website',
            'd.hostAny' => 'jeder Name', 'd.hosts' => 'nur %s', 'd.blocked' => '%d Muster%s', 'd.exceptions' => ', %d wieder geöffnet (unblock)', 'd.methodPaths' => '%s nur an bestimmten Adressen', 'd.none' => 'keine',
            'd.restricted' => '%d Bereiche', 'd.crawlers' => '%d Crawler, erkannt über %s', 'd.query' => '%d Regeln%s', 'd.strict' => ', alles andere: 404 (query strict)', 'd.loose' => ', alles andere beantwortet, aber nicht gecacht',
            'd.attacks' => '%d Muster in %d Regeln', 'd.cacheAll' => 'jede Adresse', 'd.cachePaths' => '%d Adressmuster', 'd.cacheQ' => ', Parameter: %s', 'd.budgets' => '%d Budgets: %s',
            'd.check' => 'ein Pass gilt %s%s', 'd.alwaysAt' => '; immer an %d Adressen', 'd.widget' => '; in Formularen unter %s',
            'a.429' => '429 (Retry-After)', 'a.405' => '405', 'a.400' => '400, 414, 431', 'a.404' => '404', 'a.403' => '403', 'a.pass' => 'beantwortet, nicht gecacht', 'a.check' => 'Browser-Check, dann 429', 'a.page' => 'die Check-Seite',
            'g.run' => 'Betrieb', 'g.pages' => 'Fehlerseiten', 'g.clients' => 'Besucher und Proxys', 'g.limits' => 'Anfragen', 'g.store' => 'Zähler', 'g.check' => 'Browser-Check', 'g.crawlers' => 'Bekannte Crawler', 'g.stats' => 'Statistik', 'g.log' => 'Log', 'g.files' => 'Regeldateien',
            'k.mode' => 'Modus', 'k.monitor' => 'nur beobachtete Regeln (monitor)', 'k.debug' => 'Header X-RS', 'k.app' => 'die Website darf den Check anfordern', 'k.errorPages' => 'eigene Fehlerseiten der Website', 'k.builtIn' => 'keine: die des Schutzes',
            'k.proxies' => 'vertrauenswürdige Proxys', 'k.strip' => 'X-Forwarded-* von anderen', 'k.stripYes' => 'entfernt', 'k.stripNo' => 'ignoriert', 'k.ipv6' => 'IPv6 gezählt pro', 'k.exempt' => 'nie gezählt',
            'k.methods' => 'erlaubte Methoden', 'k.hosts' => 'Namen der Website', 'k.uri' => 'längste Adresse', 'k.params' => 'meiste Parameter', 'k.headers' => 'meiste Header-Daten', 'k.strictQ' => 'query strict',
            'k.store' => 'Speicher', 'k.storeDir' => 'Speicherverzeichnis', 'k.weight' => 'eine nicht cachebare Anfrage zählt', 'k.budgets' => 'Budgets',
            'k.secret' => 'Geheimnis', 'k.secretSet' => 'gesetzt (wird nie angezeigt)', 'k.secretNone' => 'nicht gesetzt: eines wird erzeugt und im Speicherverzeichnis abgelegt (nur für einen Server)', 'k.pass' => 'ein Pass gilt', 'k.solution' => 'eine Antwort gilt', 'k.difficulty' => 'Schwierigkeit',
            'k.cookie' => 'Cookies', 'k.bindUa' => 'Pass an den Browser gebunden', 'k.language' => 'Sprache', 'k.home' => 'Link zur Startseite', 'k.widget' => 'Check in Formularen', 'k.api' => 'API-Adressen (Antwort im Header)', 'k.logo' => 'Logo', 'k.dns' => 'DNS-Abfragen pro Minute (alle Anfragen)',
            'k.crawlers' => 'bekannte Crawler', 'k.verify' => 'erkannt über', 'k.policy' => 'Regeln wie geschrieben', 'k.crawlerLog' => 'Crawler-Logs', 'k.crawlerLogKeep' => 'aufbewahrt', 'k.crawlerLogQuery' => 'mit Query',
            'k.stats' => 'Statistik', 'k.parts' => 'was gezählt wird', 'k.depth' => 'Bereichsebenen', 'k.sites' => 'getrennt gezählte Websites (stats-hosts)', 'k.oneStats' => 'eine Statistik für alle', 'k.groups' => 'Gruppen von Websites (stats-group)', 'k.skip' => 'nicht gezählt, wenn sie durchgehen (stats-skip)', 'k.flush' => 'auf die Platte alle', 'k.hours' => 'Stunden aufbewahrt', 'k.days' => 'Tage aufbewahrt', 'k.months' => 'Monate aufbewahrt', 'k.dashboard' => 'Dashboard',
            'k.log' => 'Logdatei', 'k.logLevel' => 'Stufe', 'k.logIp' => 'Adressen', 'k.logSize' => 'rotiert bei', 'k.forGood' => 'für immer', 'k.full' => 'vollständig', 'k.masked' => 'anonymisiert', 'k.days1' => 'Tage', 'k.seconds' => 's', 'k.onlyHourly' => 'nur stündlich',
            'k.ranges' => 'veröffentlichte Adresslisten', 'k.dnsV' => 'DNS', 'k.both' => 'Adresslisten oder DNS',
            'x.request' => 'Anfrage', 'x.before' => 'Adresse des Besuchers', 'x.beforeName' => 'Vorher: die Adresse des Besuchers', 'x.site' => 'Ihre Website', 'x.siteSub' => 'alle Prüfungen bestanden',
            'x.answer' => 'Der Schutz antwortet', 'x.answerSub' => '404 · 403 · 405 · 429 · Check', 'x.after' => 'Danach: Log und Statistik', 'x.feeds' => 'Mensch · Crawler · Bot',
            'x.logOff' => 'Log: aus.', 'x.log.stop' => 'Log: sofort, nur Gestopptes (Stufe stop).', 'x.log.flag' => 'Log: sofort, alles, was nicht einfach durchging — abgewiesen, geprüft, nicht gecacht (Stufe flag).',
            'x.log.all' => 'Log: jede Anfrage, sofort (Stufe all).', 'x.log.off' => 'Log: aus.',
            'x.stats' => 'Statistik: was der Schutz stoppt, wird sofort gezählt; was zur Website geht, am Ende der Anfrage, mit dem Status der Website (Seitenaufrufe, 404, Verweise).',
            'x.parts' => 'Gezählt: %s; ', 'x.apcu' => 'in APCu, alle %s auf die Platte — nach der Antwort, niemand wartet.', 'x.files' => 'eine Zeile pro Anfrage an eine Datei angehängt; die Stunde nach der Antwort zusammengefasst.',
            'x.statsOff' => 'Statistik: aus.', 'x.noFile' => 'in den Einstellungen eingebaut (keine Regeldatei)', 'x.line' => 'Zeile',
            'l.deny' => 'Sperrliste', 'l.feed' => 'Listen', 'l.ban' => 'Sperre', 'l.method' => 'Art', 'l.size' => 'Größe', 'l.sanity' => 'Tarnung', 'l.host' => 'Name', 'l.blocked' => 'Gesperrt', 'l.methodPaths' => 'Formulare', 'l.restricted' => 'Bereiche',
            'l.crawlers' => 'Crawler', 'l.query' => 'Parameter', 'l.attacks' => 'Angriffe', 'l.cache' => 'Cache', 'l.budgets' => 'Tempo', 'l.check' => 'Check',
            'l.origin' => 'Herkunft', 's.origin' => 'Woher Formulare kommen', 'd.origin' => 'Formulare nur von den eigenen Seiten der Website (post-origin same); ohne Origin und Referer: %s', 'd.plugin' => 'eine eigene Regel eines Plugins', 'a.plugin' => 'was das Plugin entscheidet',
            'try' => 'Regeltester', 'tryIntro' => 'Eine Adresse eingeben (ganze URL oder Pfad) und die IP des Besuchers: jeder Schritt zeigt, was er daraus macht und welche Regel entscheidet. Nichts wird gezählt; das Tempo nutzt die echten Zähler des Besuchers.',
            'tryUrl' => 'Adresse', 'tryIp' => 'Adresse des Besuchers (IP)', 'tryKind' => 'Art der Anfrage', 'tryUa' => 'User-Agent (optional)', 'tryButton' => 'Testen',
            'tryResult' => 'Dieser Besucher %s.', 'tryRule' => 'Entschieden von', 'tryWatched' => 'Beobachtet: er %s.',
        ],
    ];

    /**
     * The view's content: the rule tester, the way, the rules, the settings.
     *
     * @param array<string, int> $decided rule => how often it decided (RuleCounts: Counts::rules())
     * @param array{check?: array<mixed>, action?: string, keep?: array<string, string|int>, ip?: string, store?: Store, now?: float} $o
     *   check: the tester's values (method, url, ip, ua), usually $_GET; action: the form's address; keep: hidden
     *   fields the form carries along (days, lang); ip: the address the tester starts with (the viewer's own)
     */
    public static function render(Settings $s, string $lang, array $decided = [], array $o = []): string
    {
        $lang = isset(self::T[$lang]) ? $lang : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => number_format($v, 0, ',', $lang === 'de' ? '.' : ',');
        $f = static fn (string $key, ...$args): string => vsprintf($t[$key], $args);
        $list = static fn (array $v): string => self::join($v, $t['none']);

        $h = '';
        $mode = RulesPage::mode($s, $lang);
        if ($mode !== null) {
            $h .= '<p class="setupnote' . ($s->mode === 'enforce' ? '' : ' warn') . '">' . $e($mode) . '</p>';
        }

        $h .= self::tester($s, $lang, $o);

        // ── The way: every step in order, on or off, what it answers ─────────
        $query = $s->cacheableQuery === null ? '*' : ($s->cacheableQuery === [] ? '—' : implode(', ', $s->cacheableQuery));
        $patterns = 0;
        foreach ($s->contentRules as $r) {
            $patterns += count($r['patterns']);
        }
        $budgets = array_map(static fn ($b): string => $b->name . ' ' . $b->limit . '/' . Describe::duration($b->window), array_values($s->budgets));
        $verify = ['ranges' => $t['k.ranges'], 'dns' => $t['k.dnsV'], 'both' => $t['k.both']][$s->crawlerVerify] ?? $s->crawlerVerify;
        $so = self::stats($s);
        $client = $f('d.client', $s->trustedProxies === [] ? '' : $f('d.proxies', implode(', ', $s->trustedProxies)), $s->ipv6Prefix, $s->exemptIps === [] ? '' : $f('d.exempt', implode(', ', $s->exemptIps)));
        // The checks, as the chain has them (Shield::chain(), 0031 C.3): per step key its words --
        // on, what it answers, how it is set; a plugin's step gets its own description.
        $info = [
            'deny' => ['deny', $s->denyTable !== [], $t['a.403'], $s->denyCount === 0 ? $t['d.none'] : $f('d.deny', $s->denyCount, $s->listsUntil > 0 ? $f('d.next', date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $s->listsUntil)) : '')],
            'feed' => ['feed', $s->feeds !== [], $t['a.403'], $s->feeds === [] ? $t['d.none'] : implode('; ', array_map(static fn (array $x): string => $x['name'] . ' (' . $x['action']
                . ($x['action'] === 'signal' ? ' ×' . $x['weight'] : '') . ($x['paths'] !== [] ? ', ' . count($x['paths']) . ' ' . $t['d.paths'] : '') . ': '
                . ($x['state'] === 'in force' ? $f('d.feed', $x['count']) : $t['d.' . str_replace(' ', '', $x['state'])]) . ')', $s->feeds))],
            'ban' => ['ban', $s->bans !== [], $t['a.429'], $s->bans === [] ? $t['d.none'] : $f('d.ban', count($s->bans), $s->banGrowth, Describe::span($s->banMax, $lang))],
            'method' => ['method', true, $t['a.405'], $f('d.method', implode(', ', $s->methods))],
            'limits' => ['size', true, $t['a.400'], $f('d.size', $s->maxUri, $s->maxQueryParameters, (int) round($s->maxHeaderBytes / 1024))],
            'path' => ['sanity', true, '400', $t['d.sanity']],
            'host' => ['host', $s->hosts !== [], $t['a.404'], $s->hosts === [] ? $t['d.hostAny'] : $f('d.hosts', implode(', ', $s->hosts))],
            'blocked' => ['blocked', $s->blockedPaths !== [], $t['a.404'], $f('d.blocked', count($s->blockedPaths), $s->blockExceptions === [] ? '' : $f('d.exceptions', count($s->blockExceptions)))],
            'method-path' => ['methodPaths', $s->methodPaths !== [], $t['a.405'], $s->methodPaths === [] ? $t['d.none'] : $f('d.methodPaths', implode(', ', array_keys($s->methodPaths)))],
            'post-origin' => ['origin', $s->postOrigin !== null, $t['a.403'], $s->postOrigin === null ? $t['d.none'] : $f('d.origin', $s->postOrigin['missing'])],
            'restricted' => ['restricted', $s->restricted !== [], $t['a.403'], $f('d.restricted', count($s->restricted))],
            'crawlers' => ['crawlers', $s->crawlers !== [], $t['a.403'], $s->crawlers === [] ? $t['d.none'] : $f('d.crawlers', count($s->crawlers), $verify)],
            'query' => ['query', $s->queryParams !== [] || $s->queryStrict, $s->queryStrict ? $t['a.404'] : $t['a.pass'], $f('d.query', count($s->queryParams), $s->queryStrict ? $t['d.strict'] : $t['d.loose'])],
            'content' => ['attacks', $s->contentRules !== [], $t['a.403'], $f('d.attacks', $patterns, count($s->contentRules))],
            'cache' => ['cache', true, $t['a.pass'], ($s->cacheablePaths === null ? $t['d.cacheAll'] : $f('d.cachePaths', count($s->cacheablePaths))) . $f('d.cacheQ', $query)],
            'budgets' => ['budgets', $s->budgets !== [], $t['a.check'], $f('d.budgets', count($s->budgets), $list($budgets))],
        ];
        /** @var list<array{0: string, 1: bool, 2: string, 3: string, 4: string, 5: string}> $steps key, on, answers, what, label, name */
        $steps = [];
        $budgetsShown = false;
        foreach ((new \CjwNetwork\RequestShield\Shield($s, $o['store'] ?? new \CjwNetwork\RequestShield\Store\MemoryStore()))->chain() as $st) {
            $key = strncmp($st->key, 'budget:', 7) === 0 ? 'budgets' : $st->key;
            if ($key === 'budgets') {
                if ($budgetsShown) {
                    continue;                           // every budget in one step, as the chain runs them together
                }
                $budgetsShown = true;
            }
            if (isset($info[$key])) {
                [$k, $on, $answers, $what] = $info[$key];
                $steps[] = [$k, $on, $answers, $what, $t['l.' . $k], $t['s.' . $k]];
            } else {
                $steps[] = [$st->key, $st->active(), $t['a.plugin'], $t['d.plugin'], $st->key, $st->describe];     // a plugin's step (RuleProvider): it may refuse or tighten
            }
        }
        if (!$budgetsShown) {
            [$k, $on, $answers, $what] = $info['budgets'];
            $steps[] = [$k, $on, $answers, $what, $t['l.' . $k], $t['s.' . $k]];
        }
        $steps[] = ['check', true, $t['a.page'], $f('d.check', Describe::span($s->challenge->passTtl, $lang), $s->challenge->alwaysPaths === [] ? '' : $f('d.alwaysAt', count($s->challenge->alwaysPaths)))
            . ($s->challenge->widgetPath !== null ? $f('d.widget', $s->challenge->widgetPath) : ''), $t['l.check'], $t['s.check']];
        // After the checks: what the log and the statistics write, and when.
        $after = [$s->logFile === null ? $t['x.logOff'] : $t['x.log.' . $s->logLevel]];
        if ($so['enabled']) {
            $after[] = $t['x.stats'];
            $after[] = $f('x.parts', implode(', ', $so['parts'])) . ($s->store === 'apcu' || ($s->store === 'auto' && ApcuStore::usable()) ? $f('x.apcu', $so['flush'] > 0 ? $so['flush'] . ' s' : $t['k.onlyHourly']) : $t['x.files']);
        } else {
            $after[] = $t['x.statsOff'];
        }
        $h .= '<section class="card" id="way">' . Frame::h2($t['way'], $s, 'RSF06-01', 'the-way-of-a-request', $lang) . '<p class="note">' . $e($t['wayIntro']) . '</p>'
            . '<div class="diagram">' . Diagram::setup(array_map(static fn (array $st): array => ['label' => $st[4], 'name' => $st[5], 'on' => $st[1], 'what' => $st[3], 'stops' => $st[2] !== $t['a.pass'], 'feeds' => $st[0] === 'crawlers' && $so['enabled']], $steps),
                ['request' => $t['x.request'], 'before' => $t['x.before'], 'site' => $t['x.site'], 'siteSub' => $t['x.siteSub'], 'answer' => $t['x.answer'], 'answerSub' => $t['x.answerSub'],
                'after' => $t['x.after'], 'lines' => $after, 'feeds' => $t['x.feeds']]) . '</div><ol class="way">';
        $item = static fn (string $id, string $mark, bool $on, string $name, string $answers, string $what, string $feature = ''): string => '<li id="' . $id . '" class="' . ($on ? 'on' : 'off') . '"><span class="step">' . $e($mark) . '</span><div><b>' . $e($name) . '</b>'
            . (($help = Help::link($feature, '', $s->docsUrl, $lang)) !== '' ? ' ' . $help : '') . ' <span class="state">' . $e($on ? $t['on'] : $t['off']) . '</span>'
            . ($answers !== '' ? ' <span class="note">· ' . $e($t['answers'] . ' ' . $answers) . '</span>' : '') . '<br><span class="note">' . $what . '</span></div></li>';
        $h .= $item('step-before', '›', true, $t['x.beforeName'], '', $e($client), self::STEP_FEATURES['before']);
        foreach ($steps as $i => [$key, $on, $answers, $what, , $name]) {
            $h .= $item('step-' . ($i + 1), (string) ($i + 1), $on, $name, $answers, $e($what), self::STEP_FEATURES[$key] ?? 'RSF06-04');
        }
        $h .= $item('step-after', '›', $s->logFile !== null || $so['enabled'], $t['x.after'], '', implode('<br>', array_map($e, $after)), self::STEP_FEATURES['after']);
        $h .= '</ol></section>';

        // ── The rules, as the rule files hold them ───────────────────────────
        $h .= '<section class="card" id="rules">' . Frame::h2($t['rules'], $s, 'RSF06-01', 'the-rules', $lang) . '<p class="note">' . $e($t['rulesIntro']) . '</p>';
        $files = [];
        foreach (RulesPage::groups($s, [], null, $lang) as [$topic, , $rows]) {
            foreach ($rows as $r) {
                // "site.rules:12 · revision 2 · in match …": the file, the line, the rest.
                $where = (string) ($r['where'] ?? '');
                $file = preg_match('/^(.+?):(\d+)(.*)$/', $where, $m) === 1 ? $m[1] : '';
                $files[$file][] = ['line' => $file !== '' ? (int) $m[2] : 0, 'more' => $file !== '' ? trim($m[3], ' ·') : '', 'topic' => $topic] + $r;
            }
        }
        // The site's own files first, then the shipped ones, then what no file holds.
        uksort($files, static fn (string $a, string $b): int => [$a === '', strncmp($a, 'built-in', 8) === 0, $a] <=> [$b === '', strncmp($b, 'built-in', 8) === 0, $b]);
        foreach ($files as $file => $rows) {
            usort($rows, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
            $sum = 0;
            foreach ($rows as $r) {
                $sum += $r['log'] !== null && $r['log'] !== '' ? ($decided[str_replace(' ', '_', $r['log'])] ?? 0) : 0;
            }
            $own = $file !== '' && strncmp($file, 'built-in', 8) !== 0;
            $h .= '<details class="rfile"' . ($own ? ' open' : '') . '><summary><code>' . $e($file !== '' ? $file : $t['x.noFile']) . '</code> <span class="note">· '
                . $e($n(count($rows)) . ' ' . $t['rulesN'] . ($sum > 0 ? ' · ' . $n($sum) . '× ' . $t['decided'] : '')) . '</span></summary><table class="rtable">';
            foreach ($rows as $r) {
                $count = $r['log'] !== null && $r['log'] !== '' ? ($decided[str_replace(' ', '_', $r['log'])] ?? 0) : 0;
                $h .= '<tr' . ($r['id'] !== null ? ' id="rule-' . $e(Describe::anchor($r['id'])) . '"' : '') . '>'
                    . '<td class="rid">' . ($r['id'] !== null ? '<code>' . $e($r['id']) . '</code>' : '') . '</td>'
                    . '<td>' . $e($r['text']) . ($r['detail'] !== null ? '<br><code class="rule">' . $e($r['detail']) . '</code>' : '')
                    . '<br><span class="tag">' . $e($r['topic']) . '</span>' . ($r['more'] !== '' ? ' <span class="note">' . $e($r['more']) . '</span>' : '') . '</td>'
                    . '<td class="rline">' . ($r['line'] > 0 ? $e($t['x.line'] . ' ' . $r['line']) : '') . '</td>'
                    . '<td class="num">' . ($count > 0 ? $e($n($count) . '×') : '') . '</td></tr>';
            }
            $h .= '</table></details>';
        }
        // A link to a rule opens its file.
        $h .= '<script>(function(){function o(){var t=location.hash&&document.getElementById(location.hash.slice(1));if(t){var d=t.closest("details");if(d){d.open=true;t.scrollIntoView();}}}addEventListener("hashchange",o);o();})();</script>';
        $h .= '</section>';

        // ── Every technical setting ──────────────────────────────────────────
        $c = $s->challenge;
        $store = $s->store === 'auto' ? 'auto (' . (ApcuStore::usable() ? 'APCu' : 'files') . ')' : $s->store;
        $yes = static fn (bool $b): string => $b ? $t['yes'] : $t['no'];
        $sec = static fn (int $v): string => Describe::span($v, $lang);
        $policies = [];
        foreach ($s->crawlerPolicy as $who => $p) {
            $policies[] = "$who $p";
        }
        $groups = [
            'run' => [
                'k.mode' => $t['mode' . ucfirst($s->mode)] ?? $s->mode,
                'k.monitor' => (string) count($s->origins['monitor'] ?? []),
                'k.debug' => $yes($s->debugHeader),
                'k.app' => $yes($s->appChallenge),
            ],
            // set error-page (0030): which statuses, in which languages ("*": one page for all).
            'pages' => [
                'k.errorPages' => $s->errorPages === [] ? $t['k.builtIn'] : implode(', ', array_map(static fn (string $st, array $l): string => $st . ' (' . implode(', ', array_map(static fn ($x): string => $x === '' ? '*' : (string) $x, array_keys($l))) . ')',
                    array_map('strval', array_keys($s->errorPages)), $s->errorPages)),
            ],
            'clients' => [
                'k.proxies' => $list($s->trustedProxies),
                'k.strip' => $s->stripUntrustedForwarded ? $t['k.stripYes'] : $t['k.stripNo'],
                'k.ipv6' => '/' . $s->ipv6Prefix,
                'k.exempt' => $list($s->exemptIps),
            ],
            'limits' => [
                'k.methods' => implode(', ', $s->methods),
                'k.hosts' => $s->hosts === [] ? $t['d.hostAny'] : implode(', ', $s->hosts),
                'k.uri' => (string) $s->maxUri,
                'k.params' => (string) $s->maxQueryParameters,
                'k.headers' => $n($s->maxHeaderBytes) . ' B',
                'k.strictQ' => $yes($s->queryStrict),
            ],
            'store' => [
                'k.store' => $store,
                'k.storeDir' => $s->storeDir,
                'k.weight' => $s->uncachedWeight . '×',
                'k.budgets' => $list($budgets),
            ],
            'check' => [
                'k.secret' => $c->secret !== null ? $t['k.secretSet'] : $t['k.secretNone'],
                'k.pass' => $sec($c->passTtl),
                'k.solution' => $sec($c->solutionTtl),
                'k.difficulty' => $n($c->difficultyMin) . ' – ' . $n($c->difficultyMax),
                'k.cookie' => $c->cookie . ', ' . $c->solutionCookie,
                'k.bindUa' => $yes($c->bindUserAgent),
                'k.language' => $c->language,
                'k.home' => $c->home ?? '—',
                'k.widget' => $c->widgetPath !== null ? $c->widgetPath . ' (' . $n($c->widgetDifficulty) . ')' : $t['off'],
                'k.api' => $list(array_map(static fn (string $p): string => Describe::pattern($s, $p), $c->apiPaths)),
                'k.logo' => $yes($c->logo !== null),
                'k.dns' => (string) $c->dnsLookups,
            ],
            'crawlers' => [
                'k.crawlers' => (string) count($s->crawlers),
                'k.verify' => $verify,
                'k.policy' => $list($policies),
                'k.crawlerLog' => $so['crawlerLog']['dir'] === null ? $t['off'] : $so['crawlerLog']['dir'] . ($so['crawlerLog']['kinds'] !== [] ? ' (' . implode(', ', $so['crawlerLog']['kinds']) . ')' : ''),
                'k.crawlerLogKeep' => $so['crawlerLog']['dir'] === null ? '—' : $so['crawlerLog']['days'] . ' ' . $t['k.days1'] . ', ' . $t['k.crawlerLogQuery'] . ': ' . $yes($so['crawlerLog']['query']),
            ],
            'stats' => [
                'k.stats' => $so['enabled'] ? $t['on'] : $t['off'],
                'k.parts' => $list($so['parts']),
                'k.depth' => (string) $so['depth'],
                'k.sites' => $so['hosts'] === [] ? $t['k.oneStats'] : $list($so['hosts']),
                'k.groups' => $so['groups'] === [] ? $t['d.none'] : implode('; ', array_map(static fn (array $g): string => $g['name'] . ': ' . implode(', ', $g['sites']), $so['groups'])),
                'k.skip' => $so['skip'] === [] ? $t['d.none'] : implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $so['skip'])),
                'k.flush' => $so['flush'] > 0 ? $so['flush'] . ' ' . $t['k.seconds'] : $t['k.onlyHourly'],
                'k.hours' => $so['hours'] . ' ' . $t['k.days1'],
                'k.days' => (string) $so['days'],
                'k.months' => $so['months'] === 0 ? $t['k.forGood'] : (string) $so['months'],
                'k.dashboard' => $s->dashboardPath,
            ],
            'log' => [
                'k.log' => $s->logFile ?? $t['off'],
                'k.logLevel' => $s->logLevel,
                'k.logIp' => $s->logIp === 'full' ? $t['k.full'] : $t['k.masked'],
                'k.logSize' => $n((int) round($s->logMaxSize / 1048576)) . ' MB',
            ],
        ];
        $files = self::files($s);
        $versions = [];
        foreach ($s->origins['versions'] ?? [] as $name => $v) {
            $versions[] = "$name $v";
        }
        $groups['files'] = [$t['files'] => $list($files), $t['versions'] => $list($versions)];
        $h .= '<section class="card" id="settings">' . Frame::h2($t['settings'], $s, 'RSF06-01', 'the-technical-settings', $lang) . '<p class="note">' . $e($t['settingsIntro']) . '</p>';
        foreach ($groups as $g => $rows) {
            // One group under the other: a name and its value per line, room for long paths.
            $help = Help::link(self::GROUP_FEATURES[$g], '', $s->docsUrl, $lang);
            $h .= '<h3 class="rgroup">' . $e($t['g.' . $g]) . ($help !== '' ? ' ' . $help : '') . '</h3><table class="settings">';
            foreach ($rows as $k => $v) {
                $h .= '<tr><th>' . $e($t[$k] ?? $k) . '</th><td><code>' . $e($v) . '</code></td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '</section>';
    }

    /**
     * The statistics' settings, for display only: their compiled slot
     * (ext.stats, StatsExtension::compile()), or off without the extension.
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    private static function stats(Settings $s): array
    {
        $o = $s->ext['stats'] ?? [];
        $log = is_array($o['crawlerLog'] ?? null) ? $o['crawlerLog'] : [];
        $strings = static fn ($v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));
        $int = static fn ($v, int $default): int => is_int($v) ? $v : $default;
        /** @var array<string, array{name: string, sites: list<string>, rule: string}> $groups */
        $groups = [];
        foreach (is_array($o['groups'] ?? null) ? $o['groups'] : [] as $id => $g) {
            if (is_array($g) && is_string($g['name'] ?? null)) {
                $groups[(string) $id] = ['name' => $g['name'], 'sites' => $strings($g['sites'] ?? []), 'rule' => is_string($g['rule'] ?? null) ? $g['rule'] : ''];
            }
        }
        return ['enabled' => ($o['enabled'] ?? false) === true, 'parts' => $strings($o['parts'] ?? []), 'hours' => $int($o['hours'] ?? null, 7), 'days' => $int($o['days'] ?? null, 400),
            'months' => $int($o['months'] ?? null, 0), 'flush' => $int($o['flush'] ?? null, 60), 'depth' => $int($o['depth'] ?? null, 2), 'hosts' => $strings($o['hosts'] ?? []), 'skip' => $strings($o['skip'] ?? []),
            'groups' => $groups,
            'crawlerLog' => ['dir' => is_string($log['dir'] ?? null) ? $log['dir'] : null, 'kinds' => $strings($log['kinds'] ?? []), 'days' => $int($log['days'] ?? null, 30), 'query' => ($log['query'] ?? true) !== false]];
    }

    /**
     * The rule tester: an address and a visitor, and what every step makes of
     * them (Inspector) -- the rule that decides linked to its row below.
     *
     * @param array{check?: array<mixed>, action?: string, keep?: array<string, string|int>, ip?: string, store?: Store, now?: float} $o
     */
    private static function tester(Settings $s, string $lang, array $o): string
    {
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $check = $o['check'] ?? [];
        $url = is_string($check['url'] ?? null) ? trim(substr($check['url'], 0, 2048)) : '';
        $method = is_string($check['method'] ?? null) && preg_match('/^[A-Za-z]{1,10}$/', $check['method']) ? strtoupper($check['method']) : 'GET';
        $ip = is_string($check['ip'] ?? null) && @inet_pton(trim($check['ip'])) !== false ? trim($check['ip']) : ($o['ip'] ?? '198.51.100.7');
        $ua = is_string($check['ua'] ?? null) ? trim(substr($check['ua'], 0, 512)) : '';
        $h = '<section class="card" id="try">' . Frame::h2($t['try'], $s, 'RSF06-01', 'the-rule-tester', $lang) . '<p class="note">' . $e($t['tryIntro']) . '</p>'
            . '<form class="filter try" method="get" action="' . $e($o['action'] ?? '') . '#try">';
        foreach ($o['keep'] ?? [] as $k => $v) {
            $h .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
        }
        $h .= '<select name="method" aria-label="' . $e($t['tryKind']) . '">';
        foreach (array_unique(array_merge(['GET', 'POST'], $s->methods)) as $m) {
            $h .= '<option' . ($m === $method ? ' selected' : '') . '>' . $e($m) . '</option>';
        }
        $h .= '</select><label class="wide">' . $e($t['tryUrl']) . ' <input type="text" name="url" value="' . $e($url) . '" placeholder="https://www.example.org/wp-login.php"></label>'
            . '<label>' . $e($t['tryIp']) . ' <input type="text" name="ip" value="' . $e($ip) . '" class="ip"></label>'
            . '<label class="wide">' . $e($t['tryUa']) . ' <input type="text" name="ua" value="' . $e($ua) . '" placeholder="Mozilla/5.0 … / Googlebot/2.1"></label>'
            . '<button type="submit">' . $e($t['tryButton']) . '</button></form>';
        if ($url === '') {
            return $h . '</section>';
        }
        $trace = (new Inspector($s, $o['store'] ?? null, $lang))->trace(Inspector::request($method, $url, $ip, $ua !== '' ? ['User-Agent' => $ua] : []), $o['now'] ?? null);
        $d = $trace['decision'];
        $state = $d->passes() ? ($d->action === 'allow' && $trace['watched'] === null ? 'pass' : 'note') : ($d->action === 'challenge' || $s->mode === 'monitor' ? 'note' : 'stop');
        $rule = static function (?string $id) use ($s, $e): string {
            if ($id === null) {
                return '';
            }
            $info = Describe::ruleInfo($s, $id);
            return ' <a class="rid" href="#' . ($info['id'] === 'built-in' ? 'way' : 'rule-' . $e(Describe::anchor($info['id']))) . '"><code>' . $e($info['id']) . '</code></a>';
        };
        $h .= '<div class="diagram">' . Diagram::trace($trace, $method . ' ' . (string) (parse_url($url, PHP_URL_PATH) ?: $url), $lang) . '</div>'
            . '<p class="verdict ' . $state . '"><b>' . $e(sprintf($t['tryResult'], $trace['verdict'])) . '</b>'
            . ($trace['rule'] !== null ? '<br><span class="note">' . $e($t['tryRule']) . ':</span>' . $rule($trace['rule']) : '')
            . ($trace['watched'] !== null ? '<br><span class="note">' . $e(sprintf($t['tryWatched'], $trace['watched'])) . '</span>' : '') . '</p><ol class="way trace">';
        foreach ($trace['steps'] as $i => $st) {
            $h .= '<li class="' . $st['state'] . '"><span class="step">' . ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'][$st['state']] . '</span><div><b>' . $e($st['check']) . '</b><br>'
                . '<span class="note">' . $e($st['text']) . '</span>' . $rule($st['rule']) . '</div></li>';
        }
        return $h . '</ol></section>';
    }

    /**
     * A list for people: "a, b, c", or what "none" is.
     *
     * @param array<mixed> $values
     */
    private static function join(array $values, string $none): string
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = is_scalar($v) ? (string) $v : '';
        }
        return $out === [] ? $none : implode(', ', $out);
    }

    /**
     * The rule files the settings were read from, from where their rules are
     * written: "site.rules", "built-in scanners.rules".
     *
     * @return list<string>
     */
    private static function files(Settings $s): array
    {
        $files = [];
        foreach ($s->origins['at'] ?? [] as $at) {
            $file = (string) preg_replace('/:\d+$/', '', (string) $at);
            $files[$file] = true;
        }
        ksort($files);
        return array_keys($files);
    }

    /**
     * The route <dashboard-path>/waf/rules (0031 B.6): rules and setup, the tester, the
     * way -- in the core's frame, the numbers from the plugins that count (Counts, B.8).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $ctx
     */
    public static function serve(Settings $s, \CjwNetwork\RequestShield\Request $request, array $route, array $ctx): \CjwNetwork\RequestShield\Response
    {
        /** @var array<string, string> $links */
        $links = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        /** @var array<mixed> $get */
        $get = is_array($ctx['get'] ?? null) ? $ctx['get'] : [];
        $lang = \CjwNetwork\RequestShield\Texts::language(is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto', is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null);
        $lang = $lang === 'de' ? 'de' : 'en';
        $now = microtime(true);
        $action = $links['rules'] ?? ((is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . (is_string($route['path'] ?? null) ? $route['path'] : ''));
        $h = Frame::tabs($s, $links, 'rules', $lang)
            . self::render($s, $lang, Counts::rules($s, 7, $now), ['check' => $get, 'action' => $action, 'now' => $now, 'keep' => ['lang' => $lang]] + (is_string($ctx['ip'] ?? null) ? ['ip' => $ctx['ip']] : []))
            . '<p class="foot">' . htmlspecialchars(($lang === 'de' ? 'Stand ' : 'As of ') . date($lang === 'de' ? 'd.m.Y H:i:s' : 'Y-m-d H:i:s', (int) $now), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $label = is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, Frame::page(($lang === 'de' ? 'Regeln & Einrichtung — ' : 'Rules & setup — ') . $label, $lang, $h,
            ['home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => $label, 'help' => Help::link('RSF06-01', '', $s->docsUrl, $lang)], self::CSS));
    }

    /** Each step of the way, by its key, and the feature that explains it (its "?", 0031 F.9); a plugin's step: plugins. */
    public const STEP_FEATURES = [
        'before' => 'RSF01-01', 'deny' => 'RSF01-02', 'feed' => 'RSF01-03', 'ban' => 'RSF01-02', 'method' => 'RSF02-01', 'size' => 'RSF02-01', 'sanity' => 'RSF02-01',
        'host' => 'RSF02-01', 'blocked' => 'RSF02-02', 'methodPaths' => 'RSF02-03', 'origin' => 'RSF02-04', 'restricted' => 'RSF02-03', 'crawlers' => 'RSF01-04',
        'query' => 'RSF02-05', 'attacks' => 'RSF02-06', 'cache' => 'RSF04-01', 'budgets' => 'RSF03-01', 'check' => 'RSF03-02', 'after' => 'RSF05-05',
    ];

    /** Each group of the technical settings, and the feature that explains it. */
    public const GROUP_FEATURES = [
        'run' => 'RSF05-03', 'pages' => 'RSF05-06', 'clients' => 'RSF01-01', 'limits' => 'RSF02-01', 'store' => 'RSF03-01', 'check' => 'RSF03-02', 'crawlers' => 'RSF01-04',
        'stats' => 'RSF06-03', 'log' => 'RSF05-05', 'files' => 'RSF05-01',
    ];

    /** The page's own styling, on top of the frame's: cards, notes, the tester, tables. */
    private const CSS = <<<'CSS'
h2{font-size:16px;margin:0 0 10px}h2 small{color:var(--m);font-weight:400}h3{font-size:15px;margin:14px 0 6px}
.sub,.hint,.note,.foot{color:var(--m)}.sub{margin:4px 0 12px}.hint{font-size:12px;margin:4px 0 0}.foot{font-size:13px;margin-top:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-top:14px;overflow-x:auto}
.filter{margin:0 0 10px;font-size:14px;color:var(--m);display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center}.filter label{display:flex;gap:8px;align-items:center;flex:1 1 320px}
.filter input[type=text]{font:inherit;padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);flex:1;min-width:0}
.filter button{font:inherit;padding:4px 12px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#fff;cursor:pointer}
table{border-collapse:collapse;font-size:14px}td,th{padding:5px 8px;border-bottom:1px solid var(--line);vertical-align:top;text-align:left}th{font-weight:500;color:var(--m);font-size:12px}
.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}code{font-size:13px;word-break:break-all}details summary{cursor:pointer}.diagram svg{max-width:100%;height:auto}
/* The way, the tester, the rules, the settings (moved here from the statistics' page: the route is the core's, 0031 B.6). */
.way{list-style:none;margin:8px 0 0;padding:0}.way li{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--line)}.way .step{flex:0 0 26px;height:26px;border-radius:13px;background:var(--a);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px}.way li.off{opacity:.55}.trace li.pass .step{background:var(--through)}.trace li.note .step{background:var(--throttled)}.trace li.stop .step{background:var(--refused)}.trace li.skip{opacity:.5}.trace li.skip .step{background:var(--m)}.verdict{padding:10px 12px;border-radius:8px;border-left:4px solid var(--through);background:var(--bg)}.verdict.note{border-left-color:var(--throttled)}.verdict.stop{border-left-color:var(--refused)}.diagram{overflow-x:auto;margin:10px 0}.filter.try label.wide{flex:2 1 320px}.filter.try label{flex:1 1 280px}.filter.try input.ip{min-width:140px}.filter select{flex:0 0 auto}a.rid{text-decoration:none}.way li.off .step{background:var(--m)}.way .state{font-size:12px;padding:1px 7px;border-radius:9px;background:var(--bg)}.way li.on .state{color:var(--through)}.rgroup{font-size:15px;margin:18px 0 2px}.rtable{width:100%;border-collapse:collapse}.rtable td{padding:6px 8px 6px 0;border-bottom:1px solid var(--line);vertical-align:top}.rtable td.rmeta{white-space:nowrap;font-size:13px}.rtable td.rid{white-space:nowrap;width:1%;padding-right:14px}.rtable td.rline{white-space:nowrap;color:var(--m);font-size:13px}.tag{font-size:12px;padding:1px 7px;border-radius:9px;background:var(--bg);color:var(--m)}details.rfile{border:1px solid var(--line);border-radius:8px;margin:8px 0;padding:0 12px}details.rfile summary{cursor:pointer;padding:10px 0;font-weight:600}details.rfile[open] summary{border-bottom:1px solid var(--line)}.way li:target{background:var(--bg)}.rtable tr:target{background:var(--bg)}code.rule{font-size:12px;color:var(--m)}table.settings{width:100%;border-collapse:collapse;margin:2px 0 6px}table.settings th{width:38%;text-align:left;font-weight:400;color:var(--m);padding:5px 12px 5px 0;border-bottom:1px solid var(--line);vertical-align:top}table.settings td{padding:5px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.setupnote{padding:8px 12px;border-radius:8px;background:var(--card);border-left:4px solid var(--a)}.setupnote.warn{border-left-color:var(--throttled)}@media (max-width:640px){.rtable td.rmeta,.rtable td.rid{white-space:normal}table.settings th,table.settings td{display:block;width:auto;border:0;padding:2px 0}table.settings td{padding-bottom:8px;border-bottom:1px solid var(--line)}}
CSS;

}
}
}

// plugins/waf/src/WafExtension.php
namespace CjwNetwork\RequestShield\Waf {
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The firewall's pages (0031 G.3, RSF06-01, RSF06-02): Rules & setup, Live
 * and Lists below <dashboard-path>/waf, and the demo's pages and the
 * `examples` command. A plugin, not the core: the mini file decides and
 * answers without them; the pages read their data where the API reads it
 * (Api\LiveRows, Api\ListsChanges), so this plugin needs the API's.
 */
final class WafExtension implements Extension
{
    public static function id(): string
    {
        return 'waf';
    }

    public static function vocabulary(Vocabulary $v): void
    {
    }

    /** @return array{base: string} */
    public static function compile(array $raw, Settings $base): array
    {
        return ['base' => $base->dashboardPath . '/waf'];
    }

    public static function plugins(array $compiled): array
    {
        return [];
    }

    /** The firewall's start (/waf, which is the live view), Rules & setup, Live and Lists. */
    public static function routes(array $compiled): array
    {
        if (!class_exists(\CjwNetwork\RequestShield\Api\LiveRows::class)) {
            return [];              // the pages read their data through the API's classes: without them, no page (check says so)
        }
        $waf = is_string($compiled['base'] ?? null) ? $compiled['base'] : '/rs/waf';
        return [
            $waf => ['key' => 'live', 'tab' => null, 'role' => 'admin', 'order' => 60, 'page' => LivePage::class],
            "$waf/rules" => ['key' => 'rules', 'tab' => ['Rules & setup', 'Regeln & Einrichtung'], 'role' => 'admin', 'order' => 50, 'page' => SetupPage::class],
            "$waf/live" => ['key' => 'live', 'tab' => ['Live', 'Live'], 'role' => 'admin', 'order' => 60, 'page' => LivePage::class],
            "$waf/lists" => ['key' => 'lists', 'tab' => ['Lists', 'Listen'], 'role' => 'admin', 'order' => 70, 'page' => ListsPage::class],
        ];
    }

    public static function commands(): array
    {
        return ['examples' => Cli\ExamplesCommand::class];
    }

    /** The pages without the API's classes: none is served. */
    public static function check(Settings $s): array
    {
        return class_exists(\CjwNetwork\RequestShield\Api\LiveRows::class) ? []
            : ['the WAF\'s pages need the API plugin (request-shield-api.php, loaded before request-shield-waf.php): without it they are not served'];
    }
}
}
}
