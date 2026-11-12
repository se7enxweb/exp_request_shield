<?php
/**
 * cjw-network/request-shield 0.4.0 -- stats: the statistics plugin, loaded after request-shield.php.
 * One file, built by build/single-file.php (build: v0.4.0) from the repository's sources; do not edit.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see https://github.com/cjw-network/request-shield/blob/main/LICENSE
 *
 * Load it after request-shield.php; the statistics are switched on in the rules (set stats on).
 */

declare(strict_types=1);

namespace {
    // Loaded already: nothing more. Without request-shield.php first, its classes could not be declared.
    if (class_exists('CjwNetwork\\RequestShield\\Stats\\Api\\Period', false)) {
        return;
    }
    if (!class_exists('CjwNetwork\\RequestShield\\Shield', false)) {
        error_log('request-shield: ' . basename(__FILE__) . ' needs request-shield.php loaded first -- nothing loaded');
        return;
    }
}

// plugins/stats/src/Api/Period.php
namespace CjwNetwork\RequestShield\Stats\Api {
use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsExtension;

if (true) {
/** The parameters the statistics' endpoints share: the period, the website, a path, a crawler. */
final class Period
{
    public const PARAMS = [
        'days' => ['type' => 'int', 'about' => 'the last days: 1 to 400 (default 7)'],
        'from' => ['type' => 'string', 'about' => 'a period instead: from YYYY-MM-DD (with to)'],
        'to' => ['type' => 'string', 'about' => 'to YYYY-MM-DD'],
        'by' => ['type' => 'string', 'about' => 'day (default), week, month or year'],
        'site' => ['type' => 'string', 'about' => 'one website (stats-hosts), or group:<id>; a customer sees its group only'],
    ];

    /** The statistics are on, or a 409 that says how to switch them on. */
    public static function on(Settings $s): void
    {
        if (StatsExtension::of($s)['enabled'] !== true) {
            throw new ApiProblem(409, 'Conflict', 'The statistics are off: set stats on in the rule file.');
        }
    }

    /**
     * The period asked for: [from, to] as YYYYMMDD, and how it is grouped.
     *
     * @param array<string, mixed> $p
     * @return array{0: string, 1: string, 2: string, 3: int}
     */
    public static function of(array $p, int $now): array
    {
        $days = $p['days'] ?? 7;
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > 400) {
            throw new ApiProblem(400, 'Bad request', 'days is a whole number from 1 to 400.');
        }
        $by = is_string($p['by'] ?? null) && $p['by'] !== '' ? $p['by'] : 'day';
        if (!in_array($by, ['day', 'week', 'month', 'year'], true)) {
            throw new ApiProblem(400, 'Bad request', 'by is day, week, month or year.');
        }
        $from = is_string($p['from'] ?? null) ? $p['from'] : '';
        $to = is_string($p['to'] ?? null) ? $p['to'] : '';
        if ($from !== '' || $to !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to
                || (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) > 3660) {
                throw new ApiProblem(400, 'Bad request', 'from and to are days (YYYY-MM-DD), from not after to, at most ten years apart.');
            }
            $f = str_replace('-', '', $from);
            $t = str_replace('-', '', $to);
            return [$f, $t, $by, (int) round(((int) strtotime($t . ' UTC') - (int) strtotime($f . ' UTC')) / 86400) + 1];
        }
        $t = gmdate('Ymd', $now);
        return [gmdate('Ymd', $now - ((int) $days - 1) * 86400), $t, $by, (int) $days];
    }

    /** @param array<string, mixed> $p */
    public static function site(Settings $s, array $p, string $who): ?string
    {
        $asked = is_string($p['site'] ?? null) && $p['site'] !== '' ? $p['site'] : null;
        return StatsExtension::siteFor($s, $who, $asked);
    }
}
}
}

// plugins/stats/src/Api/Report.php
namespace CjwNetwork\RequestShield\Stats\Api {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;

if (true) {
/**
 * GET /stats/report: what the counters say about a period -- as `request-shield
 * stats --json` and the statistics pages: requests, what the shield did, the
 * answers, pages, sections, not found, crawlers, bots, sitemaps, forms, the
 * sentences. A customer gets its group only, and not the rules that decided.
 */
final class Report implements ApiService
{
    public const PARAMS = Period::PARAMS + [
        'path' => ['type' => 'string', 'about' => 'only pages below it: /news/'],
        'crawler' => ['type' => 'string', 'about' => 'one known crawler: CRAWL-GOOGLE'],
        'sort' => ['type' => 'string', 'about' => 'views (default), blocked, refused, checked or throttled'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['from', 'to', 'days', 'by', 'totals', 'periods', 'rules', 'crawlers', 'pages', 'notFound', 'sentences'], 'properties' => [
        'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'days' => ['type' => 'integer'], 'by' => ['type' => 'string'],
        'totals' => ['type' => 'object'], 'periods' => ['type' => 'object'], 'rules' => ['type' => 'object'], 'crawlers' => ['type' => 'object'],
        'pages' => ['type' => 'object'], 'notFound' => ['type' => 'object'], 'sentences' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Period::on($s);
        [$from, $to, $by, $days] = Period::of($params, $ctx['now']);
        $o = ['from' => $from, 'to' => $to, 'by' => $by, 'lang' => $ctx['lang']];
        $site = Period::site($s, $params, $ctx['who']);
        if ($site !== null) {
            $o['site'] = $site;
        }
        $crawler = is_string($params['crawler'] ?? null) ? $params['crawler'] : '';
        if ($crawler !== '' && isset($s->crawlers[$crawler])) {
            $o['crawler'] = $crawler;
        }
        $path = is_string($params['path'] ?? null) ? $params['path'] : '';
        if ($path !== '') {
            $o['path'] = preg_match('#^[a-z0-9*+()][a-z0-9.*+()-]*/#i', $path) === 1 ? $path : '/' . ltrim($path, '/');
        }
        $sort = is_string($params['sort'] ?? null) ? $params['sort'] : '';
        if (in_array($sort, ['views', 'blocked', 'refused', 'checked', 'throttled'], true)) {
            $o['sort'] = $sort;
        }
        $report = StatsReport::build($s, null, $days, $ctx['now'], $o);
        // Objects, not lists, where the keys are names: an empty one is {} in JSON too.
        foreach (['periods', 'totals', 'monitor', 'daily', 'hourly', 'rules', 'crawlers', 'bots', 'statuses', 'notFound', 'sitemaps', 'pages', 'folders', 'stopped', 'forms', 'backend'] as $k) {
            if (array_key_exists($k, $report) && $report[$k] === []) {
                $report[$k] = new \stdClass();
            }
        }
        if ($ctx['who'] !== '*') {
            $report['rules'] = new \stdClass();       // a customer: the protection's numbers, not the rules behind them
            $report['monitor'] = new \stdClass();
        }
        return $report;
    }
}
}
}

// plugins/stats/src/Api/Sites.php
namespace CjwNetwork\RequestShield\Stats\Api {
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;

if (true) {
/**
 * GET /stats/sites: all websites at a glance (stats-hosts, stats-group) --
 * page views, people, crawlers, bots, stopped, not found and the period
 * before, for each website and group. A customer gets its group's websites.
 */
final class Sites implements ApiService
{
    public const PARAMS = ['days' => Period::PARAMS['days'], 'from' => Period::PARAMS['from'], 'to' => Period::PARAMS['to'], 'by' => Period::PARAMS['by']];

    public const SCHEMA = ['type' => 'object', 'required' => ['from', 'to', 'sites', 'groups', 'all'], 'properties' => [
        'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'sites' => ['type' => 'object'], 'groups' => ['type' => 'object'],
        'all' => ['type' => ['object', 'null']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Period::on($s);
        [$from, $to, $by] = Period::of($params, $ctx['now']);
        $x = StatsReport::sites($s, $from, $to, $by, $ctx['who']);
        return ['from' => $from, 'to' => $to, 'sites' => $x['sites'] === [] ? new \stdClass() : $x['sites'], 'groups' => $x['groups'] === [] ? new \stdClass() : $x['groups'], 'all' => $x['all']];
    }
}
}
}

// plugins/stats/src/Cli/StatsCommand.php
namespace CjwNetwork\RequestShield\Stats\Cli {
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;

if (true) {
/**
 * `request-shield stats <main.rules>`: what the counters (set stats on) say
 * about the last days -- requests let through, checked, refused, the rules
 * behind them, the answers' status codes, pages not found and who links to
 * them, what each known crawler did, other bots -- in words, or as JSON for a
 * CMS. The statistics extension's command (Extension::commands(), 0031 D.1).
 */
final class StatsCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield stats <main.rules> [--days=7 | --from=YYYY-MM-DD --to=YYYY-MM-DD] [--by=day|week|month|year] [--crawler=<ID>] [--path=/news/] [--sort=views|blocked|refused|checked|throttled] [--site=<name>|--group=<name>] [--json]';
    }

    public static function run(Context $c): int
    {
        /** @var array{from?: string, to?: string, by?: string, crawler?: string, path?: string, sort?: string, site?: string} $period */
        $period = is_array($c->option('period')) ? $c->option('period') : [];
        $days = is_int($c->option('days')) ? $c->option('days') : 7;
        $json = $c->option('json') === true;
        $so = StatsExtension::of($c->settings);
        if (!$so['enabled']) {
            fwrite(STDERR, "no statistics: switch them on with \"set stats on\" in the rule file\n");
            return 1;
        }
        if (isset($period['crawler']) && !isset($c->settings->crawlers[$period['crawler']])) {
            fwrite(STDERR, "no crawler {$period['crawler']} -- see bin/request-shield crawlers " . basename($c->file) . "\n");
            return 2;
        }
        if (isset($period['site']) && $period['site'] !== 'other' && !Stats::known($c->settings, $period['site'])) {
            fwrite(STDERR, $so['hosts'] === [] ? "no statistics per website: set stats-hosts (or stats-group) in the rule file\n"
                : (strncmp($period['site'], 'group:', 6) === 0 ? 'no group ' . substr($period['site'], 6) . ' (' . implode(', ', array_keys($so['groups'])) . ")\n"
                : "no website {$period['site']} in stats-hosts (" . implode(', ', $so['hosts']) . ", other)\n"));
            return 2;
        }
        if (($period['site'] ?? null) === 'other') {
            $period['site'] = Stats::OTHER;
        }
        /** @var array{from?: string, to?: string, by?: string, crawler?: string, path?: string, sort?: string, site?: string} $period */
        $r = StatsReport::build($c->settings, null, $days, null, $period);
        if ($json) {
            echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }
        $n = static fn (int $v): string => number_format($v);
        $t = $r['totals'];
        $all = array_sum($t);
        echo ($period === [] ? "Last $days day(s), " : '') . substr($r['from'], 0, 4) . '-' . substr($r['from'], 4, 2) . '-' . substr($r['from'], 6) . ' to ' . substr($r['to'], 0, 4) . '-' . substr($r['to'], 4, 2) . '-' . substr($r['to'], 6) . ":\n\n";
        echo '  ' . $n($all) . ' requests: ' . $n(($t['allow'] ?? 0) + ($t['allow-uncached'] ?? 0)) . ' let through, ' . $n($t['challenge'] ?? 0) . ' checked, '
            . $n($t['throttle'] ?? 0) . ' told to wait, ' . $n($t['reject'] ?? 0) . " refused\n";
        if ($r['monitor'] !== []) {
            echo '  monitor mode would have: ' . implode(', ', array_map(static fn (string $a, int $v): string => "$a $v", array_keys($r['monitor']), $r['monitor'])) . "\n";
        }
        if ($r['statuses'] !== []) {
            echo '  answers: ' . implode(', ', array_map(static fn (string $c, int $v): string => "$c: $v", array_map('strval', array_keys($r['statuses'])), $r['statuses'])) . "\n";
        }
        $tm = $r['times'];
        if ($tm !== null) {
            // How long the site took (0046): what did not come from the cache; the cache; the shield.
            $d = static fn (?int $us): string => $us === null ? '-' : StatsReport::duration($us);
            $site = $tm['site'];
            echo '  the site took: median ' . $d($site['p50']) . ', slow end (p95) ' . $d($site['p95']) . ', average ' . $d($site['avg']) . ' -- ' . $n($site['count']) . " requests\n";
            $hit = $tm['kinds']['hit'];
            if ($tm['hitShare'] !== null) {
                echo '  HTTP cache: ' . (int) round(100 * $tm['hitShare']) . ' % hits (hit ' . $d($hit['p50']) . ', miss ' . $d($tm['kinds']['miss']['p50']) . '), saved ' . $d($tm['saved'])
                    . ($tm['reasons'] !== [] ? '; not kept: ' . implode(', ', array_map(static fn (string $w, int $v): string => "$w $v", array_map('strval', array_keys($tm['reasons'])), $tm['reasons'])) : '') . "\n";
            }
            if ($tm['shield'] !== null) {
                echo '  the shield: ' . $d($tm['shield']) . " a request\n";
            }
            if ($tm['pages'] !== []) {
                echo "\nSlowest pages (at least " . StatsReport::PAGE_VIEWS . " views)     views   median      p95  from the cache\n";
                foreach ($tm['pages'] as $path => $p) {
                    printf("  %-36s %7s %8s %8s %8d %%\n", (string) $path, $n($p['count']), $d($p['p50']), $d($p['p95']), (int) round(100 * $p['hits'] / max(1, $p['count'])));
                }
            }
            if ($tm['slow'] !== []) {
                echo "\nSlow requests (the last):\n  " . implode("\n  ", $tm['slow']) . "\n";
            }
        }
        if (isset($period['by']) || isset($period['crawler'])) {
            $cols = isset($period['crawler']) ? ['verified' => 'visits', 'allowed' => 'let through', 'checked' => 'checked', 'refused' => 'refused', 'claimed' => 'claimed']
                : ['passed' => 'cacheable', 'uncached' => 'uncached', 'checked' => 'checked', 'throttled' => 'waited', 'refused' => 'refused'];
            echo "\n" . str_pad('', 20) . implode('', array_map(static fn (string $c): string => str_pad($c, 13, ' ', STR_PAD_LEFT), $cols)) . "\n";
            foreach ($r['periods'] as $label => $b) {
                echo '  ' . str_pad((string) $label, 18) . implode('', array_map(static fn (string $k): string => str_pad($n($b[$k] ?? 0), 13, ' ', STR_PAD_LEFT), array_keys($cols))) . "\n";
            }
        }
        if ($r['rules'] !== []) {
            echo "\nRules that decided most:\n";
            foreach (array_slice($r['rules'], 0, 10, true) as $rule => $v) {
                echo '  ' . str_pad($n($v), 8, ' ', STR_PAD_LEFT) . "  $rule\n";
            }
        }
        // A page's or section's numbers: its views by who came, what the shield stopped by how.
        $cols = static fn (array $v): string => implode('', array_map(static fn (string $k, int $w): string => str_pad($n(is_int($v[$k] ?? null) ? $v[$k] : 0), $w, ' ', STR_PAD_LEFT),
            ['total', 'people', 'crawlers', 'bots', 'blocked', 'refused', 'checked', 'throttled'], [8, 9, 9, 8, 9, 8, 8, 8]));
        if ($r['subtree'] !== null) {
            $st = $r['subtree'];
            echo "\nSubtree {$st['path']}: " . $n($st['total']) . ' views (people ' . $n($st['people']) . ', crawlers ' . $n($st['crawlers']) . ', bots ' . $n($st['bots']) . ')'
                . ($st['exact'] ? '' : ' -- the sum of its most visited pages') . ($st['blocked'] > 0 ? '; stopped ' . $n($st['blocked']) . ' (refused ' . $n($st['refused']) . ', checked ' . $n($st['checked']) . ', told to wait ' . $n($st['throttled']) . ')' : '') . "\n";
        }
        if ($r['folders'] !== []) {
            echo "\n" . str_pad($r['sort'] === 'views' ? 'Most visited sections' : 'Sections stopped most', 24) . "    total   people crawlers    bots  stopped refused checked  waited\n";
            foreach (array_slice($r['folders'], 0, 10, true) as $folder => $v) {
                echo '  ' . str_pad((string) $folder, 24) . $cols($v) . "\n";
            }
        }
        if ($r['pages'] !== []) {
            echo "\n" . str_pad($r['sort'] === 'views' ? 'Most visited pages' : 'Pages stopped most', 24) . "    total   people crawlers    bots  stopped refused checked  waited\n";
            foreach (array_slice($r['pages'], 0, 10, true) as $path => $v) {
                echo '  ' . str_pad((string) $path, 24) . $cols($v) . "\n";
            }
        }
        // Forms (proposal 0028): sent, from where, how they ended; the editors' area apart.
        foreach (['forms' => 'forms', 'backend' => "forms in the editors' area"] as $key => $title) {
            if ($r[$key] === []) {
                continue;
            }
            echo "\n$title:\n";
            foreach ($r[$key] as $path => $f) {
                $from = [];
                foreach ($f['from'] as $src => $count) {
                    $src = (string) $src;
                    $from[] = ($src === '-' ? 'no page given' : ($src === '=' ? 'this website' : ($src[0] === '@' ? 'another website ' . substr($src, 1) : $src))) . " $count";
                }
                printf("  %-40s %6d sent   %d saved · %d errors · %d stopped%s%s\n", $path, $f['sent'], $f['saved'], $f['error'], $f['stopped'],
                    $f['cross-site'] > 0 ? ' (' . $f['cross-site'] . ' from another website)' : '', $from !== [] ? '   from ' . implode(' · ', $from) : '');
            }
        }
        if ($r['notFound'] !== []) {
            echo "\nNot found:\n";
            foreach ($r['notFound'] as $path => $x) {
                echo '  ' . str_pad($n($x['count']), 8, ' ', STR_PAD_LEFT) . "  $path" . ($x['referrers'] !== [] ? '   linked from: ' . implode(', ', array_map(static fn (string $f, int $v): string => "$f ($v)", array_map('strval', array_keys($x['referrers'])), $x['referrers'])) : '') . "\n";
            }
        }
        echo "\nKnown crawlers                  seen  verified claimed allowed checked refused  last\n";
        foreach ($r['crawlers'] as $id => $cr) {
            if ($cr['seen'] === 0) {
                continue;
            }
            echo '  ' . str_pad($id, 26) . str_pad($n($cr['seen']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['verified']), 10, ' ', STR_PAD_LEFT) . str_pad($n($cr['claimed']), 8, ' ', STR_PAD_LEFT)
                . str_pad($n($cr['allowed']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['checked']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['refused']), 8, ' ', STR_PAD_LEFT)
                . '  ' . (is_array($cr['last']) ? date('Y-m-d H:i', (int) $cr['last'][0]) : '-') . "\n";
        }
        if ($r['bots'] !== []) {
            echo "\nOther bots (by what they say they are): " . implode(', ', array_map(static fn (string $f, int $v): string => "$f $v", array_keys($r['bots']), $r['bots'])) . "\n";
        }
        if ($r['sentences'] !== []) {
            echo "\nIn short:\n  " . implode("\n  ", $r['sentences']) . "\n";
        }
        return 0;    }
}
}
}

// plugins/stats/src/Report/StatsPage.php
namespace CjwNetwork\RequestShield\Stats\Report {
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Access;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Routes;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Texts;

if (true) {
/**
 * The statistics as a page (set stats on): who came -- people, crawlers,
 * bots --, what the shield did, the answers' status codes, what each known
 * crawler did, pages not found and who links to them. Charts drawn as inline
 * SVG and CSS (no script library, no external file); dark mode; English and
 * German. It refreshes itself every minute.
 *
 *   echo StatsPage::render($settings, ['action' => '/stats', 'days' => 7]);
 *
 * Print it where only the site's people see it: behind the CMS's login, or a
 * path restricted to some addresses (restrict /stats to 192.0.2.0/24).
 */
final class StatsPage implements \CjwNetwork\RequestShield\RoutePage
{
    private const T = [
        'en' => [
            'title' => 'Statistics', 'today' => '24 hours', 'd7' => '7 days', 'd30' => '30 days', 'm12' => '12 months', 'thisMonth' => 'This month', 'lastMonth' => 'Last month', 'from' => 'from', 'to' => 'to', 'show' => 'Show', 'nowPeople' => 'now: %s requests by people in the last 5 minutes',
            'allSites' => 'All websites', 'otherHosts' => 'other hosts (names the rules do not know)', 'website' => 'Website',
            'groupAll' => '%s: all %d websites', 'groupOne' => '%s: its website', 'ungrouped' => 'In no group', 'tabSites' => 'All websites',
            'sitesIntro' => 'where the traffic is: page views by people, against the period before', 'pageViews' => 'Page views', 'change' => 'Change', 'stoppedCol' => 'Stopped',
            'new' => 'new', 'before' => 'the period before: %s', 'peopleReq' => 'Requests by people', 'crawlerVisits' => 'Crawler visits', 'botReq' => 'Bot requests',
            'signOut' => 'Sign out', 'hViews' => 'Views', 'hPeople' => 'People', 'hCrawlers' => 'Crawlers', 'hBots' => 'Bots', 'hStopped' => 'Stopped', 'hCurve' => 'Trend', 'otherShort' => 'other hosts', 'hSearch' => 'Search', 'hAi' => 'AI',
            'searchTip' => 'visits of search engines\' crawlers (Googlebot, Bingbot …)', 'aiTip' => 'visits of AI crawlers: AI search, AI assistants fetching for a user, AI training',
            'websiteTip' => 'a website, a group of websites (stats-group) or the names the rules do not know (other hosts)',
            'viewsTip' => 'page views by people: pages shown (GET, answered 200 with HTML)', 'changeTip' => 'page views against the period before, as long as this one',
            'crawlersTip' => 'requests from a known crawler (Googlebot …), proved by its address or only claimed', 'stoppedTip' => 'refused, checked or told to wait by the shield',
            'notFoundTip' => 'pages not found (404, 410)', 'curveTip' => 'page views per day (or week, month) of the period',
            'peopleTip' => 'every request by a person, not only pages: forms sent, redirects, not found, JSON -- the page views are the pages shown (GET, 200, HTML)',
            'botsTip' => 'requests from tools and scripts that say so (curl, python-requests, wget …), not a known crawler', 'nSites' => '%d websites', 'oneSite' => '1 website',
            'requests' => 'Requests', 'people' => 'People', 'crawlers' => 'Crawlers', 'bots' => 'Bots', 'checked' => 'Checked', 'refused' => 'Refused',
            'notFound' => 'Not found', 'through' => 'Let through', 'throttled' => 'Told to wait', 'who' => 'Who came', 'what' => 'What the shield did',
            'answers' => 'Answers', 'short' => 'In short', 'known' => 'Known crawlers', 'missing' => 'Pages not found', 'linked' => 'linked from',
            'rules' => 'Rules that decided most', 'botfam' => 'Other bots', 'nothing' => 'Nothing yet: the numbers come as requests arrive, counted per hour.', 'claimed' => 'only claimed',
            'allowed' => 'let through', 'last' => 'last visit', 'updated' => 'Updated', 'refresh' => 'refreshes every minute', 'hours48' => 'last 48 hours',
            'all' => 'all crawlers', 'kind.search' => 'search', 'kind.ai-search' => 'AI search', 'kind.ai-user' => 'AI, for a person', 'kind.ai-training' => 'AI training',
            'noStats' => 'No statistics: switch them on with "set stats on" in the rule file.', 'sitemaps' => 'Sitemaps', 'noMaps' => 'No sitemap was asked for: search engines ask for one once robots.txt names it (Sitemap: https://…/sitemap.xml).',
            'noReader' => 'not read by a verified crawler', 'times' => '×', 'top' => 'Most visited pages', 'noPages' => 'No page views counted yet: with set stats on, each page a person, a crawler or a bot reads is counted (an answer with 200 and HTML).', 'sections' => 'Most visited sections', 'topBlocked' => 'Pages the shield stopped most', 'sectionsBlocked' => 'Sections the shield stopped most', 'noBlocked' => 'The shield stopped no page in this period.', 'sortBy' => 'sorted by', 'blocked' => 'stopped', 'byViews' => 'views', 'byBlocked' => 'stopped (all)',
            'speed' => 'How fast the site answered', 'spSite' => 'The site (not from the cache)', 'spCount' => 'requests', 'spMedian' => 'median', 'spP95' => 'slow end (95 %)', 'spAvg' => 'average',
            'spHit' => 'from the HTTP cache', 'spMiss' => 'asked the site, kept', 'spNostore' => 'asked the site, not to be kept', 'spPast' => 'not for the cache',
            'spHits' => '%s of the cacheable pages came from the cache (%s instead of %s) — %s of server time saved.', 'spWhy' => 'Not kept, because: %s.',
            'spShield' => 'The shield took %s a request.', 'spHours' => 'Per hour (the period\'s last 48): median and slow end', 'spSlow' => 'Slow requests (the last)', 'spPages' => 'Slowest pages (at least %d views)', 'spPage' => 'Page', 'spFromCache' => 'from the cache',
            'spNone' => 'Not measured yet: "set stats … times" counts how long the site takes.',
            'why.status' => 'not 200 (an error, a redirect)', 'why.cookie' => 'it sets a cookie', 'why.private' => 'private / no-store', 'why.encoded' => 'compressed by the site', 'why.expired' => 'expired', 'why.vary' => 'it varies', 'why.ttl' => 'no lifetime', 'why.app-bypass' => 'the site\'s own cache passes it by', 'why.appcache' => 'the site\'s own cache keeps it',
            'tabAll' => 'Overview', 'tabSite' => 'Visitors & pages', 'tabShield' => 'Protection', 'tabRules' => 'Rules & setup', 'ruleDetails' => 'all rules and settings', 'builtIn' => 'the fixed checks: kind of request, sizes, disguised addresses', 'filter' => 'Filter', 'pathStarts' => 'path starts with', 'subtree' => 'Subtree', 'views' => 'views', 'exact' => 'exact', 'approx' => 'the sum of its most visited pages', 'clear' => 'all pages', 'per' => 'per', 'hour' => 'hour', 'day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year',
        ],
        'de' => [
            'title' => 'Statistik', 'today' => '24 Stunden', 'd7' => '7 Tage', 'd30' => '30 Tage', 'm12' => '12 Monate', 'thisMonth' => 'Dieser Monat', 'lastMonth' => 'Letzter Monat', 'from' => 'von', 'to' => 'bis', 'show' => 'Anzeigen', 'nowPeople' => 'jetzt: %s Anfragen von Menschen in den letzten 5 Minuten',
            'allSites' => 'Alle Websites', 'otherHosts' => 'andere Hosts (Namen, die die Regeln nicht kennen)', 'website' => 'Website',
            'groupAll' => '%s: alle %d Websites', 'groupOne' => '%s: seine Website', 'ungrouped' => 'In keiner Gruppe', 'tabSites' => 'Alle Websites',
            'sitesIntro' => 'wo der Verkehr ist: Seitenaufrufe von Menschen, gegenüber dem Zeitraum davor', 'pageViews' => 'Seitenaufrufe', 'change' => 'Veränderung', 'stoppedCol' => 'Gestoppt',
            'new' => 'neu', 'before' => 'der Zeitraum davor: %s', 'peopleReq' => 'Anfragen von Menschen', 'crawlerVisits' => 'Crawler-Besuche', 'botReq' => 'Bot-Anfragen',
            'signOut' => 'Abmelden', 'hViews' => 'Aufrufe', 'hPeople' => 'Menschen', 'hCrawlers' => 'Crawler', 'hBots' => 'Bots', 'hStopped' => 'Gestoppt', 'hCurve' => 'Verlauf', 'otherShort' => 'andere Hosts', 'hSearch' => 'Suche', 'hAi' => 'KI',
            'searchTip' => 'Besuche der Crawler von Suchmaschinen (Googlebot, Bingbot …)', 'aiTip' => 'Besuche von KI-Crawlern: KI-Suche, KI-Assistenten für einen Nutzer, KI-Training',
            'websiteTip' => 'eine Website, eine Gruppe von Websites (stats-group) oder die Namen, die die Regeln nicht kennen (andere Hosts)',
            'viewsTip' => 'Seitenaufrufe von Menschen: gezeigte Seiten (GET, mit 200 und HTML beantwortet)', 'changeTip' => 'Seitenaufrufe gegenüber dem gleich langen Zeitraum davor',
            'crawlersTip' => 'Anfragen bekannter Crawler (Googlebot …), durch ihre Adresse bestätigt oder nur behauptet', 'stoppedTip' => 'vom Schutz abgewiesen, geprüft oder zum Warten geschickt',
            'notFoundTip' => 'nicht gefundene Seiten (404, 410)', 'curveTip' => 'Seitenaufrufe pro Tag (oder Woche, Monat) im Zeitraum',
            'peopleTip' => 'jede Anfrage eines Menschen, nicht nur Seiten: gesendete Formulare, Weiterleitungen, nicht gefunden, JSON -- Seitenaufrufe sind die gezeigten Seiten (GET, 200, HTML)',
            'botsTip' => 'Anfragen von Werkzeugen und Skripten, die sich so nennen (curl, python-requests, wget …), kein bekannter Crawler', 'nSites' => '%d Websites', 'oneSite' => '1 Website',
            'requests' => 'Anfragen', 'people' => 'Menschen', 'crawlers' => 'Crawler', 'bots' => 'Bots', 'checked' => 'Geprüft', 'refused' => 'Abgewiesen',
            'notFound' => 'Nicht gefunden', 'through' => 'Durchgelassen', 'throttled' => 'Gebremst', 'who' => 'Wer kam', 'what' => 'Was der Schutz tat',
            'answers' => 'Antworten', 'short' => 'Kurz gesagt', 'known' => 'Bekannte Crawler', 'missing' => 'Nicht gefundene Seiten', 'linked' => 'verlinkt von',
            'rules' => 'Regeln, die am meisten entschieden', 'botfam' => 'Andere Bots', 'nothing' => 'Noch nichts: Die Zahlen kommen mit den Anfragen, gezählt pro Stunde.', 'claimed' => 'nur behauptet',
            'allowed' => 'durchgelassen', 'last' => 'zuletzt', 'updated' => 'Stand', 'refresh' => 'aktualisiert sich jede Minute', 'hours48' => 'letzte 48 Stunden',
            'all' => 'alle Crawler', 'kind.search' => 'Suche', 'kind.ai-search' => 'KI-Suche', 'kind.ai-user' => 'KI, für eine Person', 'kind.ai-training' => 'KI-Training',
            'noStats' => 'Keine Statistik: mit "set stats on" in der Regeldatei einschalten.', 'sitemaps' => 'Sitemaps', 'noMaps' => 'Keine Sitemap wurde abgefragt: Suchmaschinen fragen danach, sobald robots.txt sie nennt (Sitemap: https://…/sitemap.xml).',
            'noReader' => 'von keinem bestätigten Crawler gelesen', 'times' => '×', 'top' => 'Meistbesuchte Seiten', 'noPages' => 'Noch keine Seitenaufrufe gezählt: Mit set stats on zählt jede Seite, die ein Mensch, ein Crawler oder ein Bot liest (eine Antwort mit 200 und HTML).', 'sections' => 'Meistbesuchte Bereiche', 'topBlocked' => 'Am häufigsten blockierte Seiten', 'sectionsBlocked' => 'Am häufigsten blockierte Bereiche', 'noBlocked' => 'Der Schutz hat in diesem Zeitraum keine Seite blockiert.', 'sortBy' => 'sortiert nach', 'blocked' => 'blockiert', 'byViews' => 'Aufrufe', 'byBlocked' => 'blockiert (alle)',
            'speed' => 'Wie schnell die Website antwortete', 'spSite' => 'Die Website (nicht aus dem Cache)', 'spCount' => 'Anfragen', 'spMedian' => 'Median', 'spP95' => 'langsames Ende (95 %)', 'spAvg' => 'Durchschnitt',
            'spHit' => 'aus dem HTTP-Cache', 'spMiss' => 'Website gefragt, gespeichert', 'spNostore' => 'Website gefragt, nicht speicherbar', 'spPast' => 'nicht für den Cache',
            'spHits' => '%s der cachebaren Seiten kamen aus dem Cache (%s statt %s) — %s Server-Zeit gespart.', 'spWhy' => 'Nicht gespeichert, weil: %s.',
            'spShield' => 'Der Schutz brauchte %s pro Anfrage.', 'spHours' => 'Pro Stunde (die letzten 48 des Zeitraums): Median und langsames Ende', 'spSlow' => 'Langsame Anfragen (die letzten)', 'spPages' => 'Langsamste Seiten (mindestens %d Aufrufe)', 'spPage' => 'Seite', 'spFromCache' => 'aus dem Cache',
            'spNone' => 'Noch nicht gemessen: "set stats … times" zählt, wie lange die Website braucht.',
            'why.status' => 'nicht 200 (ein Fehler, eine Weiterleitung)', 'why.cookie' => 'setzt ein Cookie', 'why.private' => 'private / no-store', 'why.encoded' => 'von der Website komprimiert', 'why.expired' => 'abgelaufen', 'why.vary' => 'variiert', 'why.ttl' => 'keine Lebensdauer', 'why.app-bypass' => 'der eigene Cache der Website lässt sie aus', 'why.appcache' => 'der eigene Cache der Website hält sie',
            'tabAll' => 'Übersicht', 'tabSite' => 'Besucher & Seiten', 'tabShield' => 'Schutz', 'tabRules' => 'Regeln & Aufbau', 'ruleDetails' => 'alle Regeln und Einstellungen', 'builtIn' => 'die festen Prüfungen: Art der Anfrage, Größen, getarnte Adressen', 'filter' => 'Filtern', 'pathStarts' => 'Pfad beginnt mit', 'subtree' => 'Unterbaum', 'views' => 'Aufrufe', 'exact' => 'genau', 'approx' => 'Summe seiner meistbesuchten Seiten', 'clear' => 'alle Seiten', 'per' => 'pro', 'hour' => 'Stunde', 'day' => 'Tag', 'week' => 'Woche', 'month' => 'Monat', 'year' => 'Jahr',
        ],
    ];

    /**
     * @param array{action?: string, api?: string, view?: string, tabs?: bool, links?: array<string, string>, days?: int, by?: string, crawler?: ?string, path?: ?string, sort?: string, lang?: string, accept?: ?string, home?: string, homeLabel?: string,
     *   title?: string, fragment?: bool, now?: int, stats?: Stats, check?: array<mixed>, ip?: string, from?: string, to?: string, store?: \CjwNetwork\RequestShield\Store\Store, site?: string|null, who?: string} $o
     *   who: who reads (Access::gate()): '*' everything (the default), a group's ID only its statistics;
     *   site: with stats-hosts, one website's numbers (a name of stats-hosts, or Stats::OTHER); without, all added up;
     *   action: the page's own address (links, refresh); view: site (visitors and pages, for editors), shield
     *   (what the protection did, for admins) or all (everything); links: an address per view -- 'all', 'site',
     *   'shield' => '/rs/stats/protection' … -- for the tabs (without: ?view=); tabs: the tabs between them; lang: en, de, or auto (the browser's, from accept);
     *   check: the rule tester's values in the rules view (method, url, ip, ua -- usually $_GET), ip: the address it starts with;
     *   fragment: only the content, for the refresh; sort: the pages by views, or by what the shield stopped there
     *   (blocked, refused, checked, throttled -- the protection's view starts with blocked)
     */
    public static function render(Settings $s, array $o = []): string
    {
        $lang = $o['lang'] ?? 'auto';
        if (!isset(self::T[$lang])) {
            $lang = Texts::language('auto', $o['accept'] ?? null);
            $lang = isset(self::T[$lang]) ? $lang : 'en';
        }
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        // Each section's "?" into the docs (0031 F.9), the title's to the page as a whole.
        $help = static function (string $anchor, string $id = 'RSF06-03') use ($s, $lang): string {
            $link = Help::link($id, $anchor, $s->docsUrl, $lang);
            return $link !== '' ? ' ' . $link : '';
        };
        $o['help'] = Help::link('RSF06-03', 'the-statistics-page', $s->docsUrl, $lang);
        $days = max(1, min(3660, $o['days'] ?? 7));
        $now = $o['now'] ?? time();
        // A range instead of the last days: from and to (YYYY-MM-DD), at most ten years.
        $from = self::day($o['from'] ?? null);
        $to = self::day($o['to'] ?? null);
        if ($from !== null && $to !== null && $from <= $to) {
            $from = max($from, (int) strtotime('-3659 days', $to));
            $days = (int) round(($to - $from) / 86400) + 1;
        } else {
            $from = $to = null;
        }
        $by = $o['by'] ?? ($days === 1 && $from === null ? 'hour' : ($days > 62 ? 'month' : 'day'));
        $range = $from !== null ? ['from' => gmdate('Y-m-d', $from), 'to' => gmdate('Y-m-d', (int) $to)] : [];
        $crawler = $o['crawler'] ?? null;
        // A path, or with several websites read together a website and its path (a.de/news/).
        $path = isset($o['path']) && $o['path'] !== '' ? (preg_match('#^[a-z0-9*+()][a-z0-9.*+()-]*/#i', (string) $o['path']) === 1 ? (string) $o['path'] : '/' . ltrim((string) $o['path'], '/')) : null;
        $filter = $path;                    // the subtree filter ($path is reused by the loops below)
        $view = in_array($o['view'] ?? 'site', ['site', 'shield', 'all', 'sites'], true) ? ($o['view'] ?? 'site') : 'site';
        // Who reads (Access::gate()): '*' everything; a customer's group only its statistics --
        // never Rules & setup or the server's overview, whatever address was asked for.
        $who = is_string($o['who'] ?? null) ? $o['who'] : '*';
        if ($who !== '*' && $view === 'all') {
            $view = 'site';
        }
        if ($who !== '*' && isset(StatsExtension::of($s)['groups'][$who])) {
            $t['tabSites'] = StatsExtension::of($s)['groups'][$who]['name'];     // a customer's tab: its group, not "all websites"
        }
        if ($view === 'sites' && StatsExtension::of($s)['hosts'] === []) {
            $view = 'all';                  // no websites to compare: the overview
        }
        // The pages by views, or by what the shield stopped (the protection's view starts there).
        $sorts = ['views', 'blocked', 'refused', 'checked', 'throttled'];
        $sortDefault = $view === 'shield' ? 'blocked' : 'views';
        $sort = in_array($o['sort'] ?? $sortDefault, $sorts, true) ? ($o['sort'] ?? $sortDefault) : $sortDefault;
        // stats-hosts: one website's numbers, or all added up (no "site").
        $site = is_string($o['site'] ?? null) && \CjwNetwork\RequestShield\Stats\Stats::known($s, $o['site']) ? $o['site'] : null;
        if ($who !== '*') {
            $site = StatsExtension::siteFor($s, $who, $site);
        }
        // What every link carries along besides the period and the language.
        $extra = $range + ($site !== null ? ['site' => $site] : []) + ($crawler !== null ? ['crawler' => $crawler] : []) + ($path !== null ? ['path' => $path] : []) + ($sort !== $sortDefault ? ['sort' => $sort] : []);
        $action = $o['action'] ?? '';
        // One address per view ('links' => ['all' => '/rs/stats/overview', 'site' => '/rs/stats/visitors', 'shield' => '/rs/stats/protection']):
        // the path names the view, GET parameters filter. Without links: ?view=.
        $links = [];
        foreach ((array) ($o['links'] ?? []) as $v => $u) {
            if (in_array($v, ['sites', 'all', 'site', 'shield', 'rules', 'live', 'lists'], true) && $u !== '' && ($v !== 'sites' || StatsExtension::of($s)['hosts'] !== [])
                && ($who === '*' || in_array($v, ['sites', 'site', 'shield'], true))) {
                $links[$v] = $u;
            }
        }
        $action = $links[$view] ?? $action;
        $query = static fn (array $q): string => $action . '?' . http_build_query($links !== [] ? array_diff_key($q, ['view' => 1]) : $q);

        if (!StatsExtension::of($s)['enabled']) {
            $body = '<p class="note">' . $e($t['noStats']) . '</p>';
            return ($o['fragment'] ?? false) ? $body : self::page($body, $t['title'], $lang, $o, $e);
        }
        $r = StatsReport::build($s, $o['stats'] ?? null, $days, $now, ['by' => $by === 'hour' ? 'day' : $by, 'lang' => $lang, 'sort' => $sort]
            + ($range !== [] ? ['from' => gmdate('Ymd', (int) $from), 'to' => gmdate('Ymd', (int) $to)] : []) + array_diff_key($extra, $range));
        // Hours: the last 48 for the small curves, today's for a "today" chart.
        $hours = [];
        for ($i = 47; $i >= 0; $i--) {
            $k = gmdate('YmdH', $now - $i * 3600);
            $hours[$k] = $r['hourly'][$k] ?? [];
        }
        $periods = $r['periods'];
        if ($by === 'hour') {
            // "Today": the last 24 hours, labelled in the server's time.
            $periods = [];
            foreach (array_slice($hours, 24, null, true) as $k => $b) {
                $k = (string) $k;           // PHP makes "2026093010" an integer key
                $ts = (int) gmmktime((int) substr($k, 8, 2), 0, 0, (int) substr($k, 4, 2), (int) substr($k, 6, 2), (int) substr($k, 0, 4));
                $periods[date('H', $ts) . ':00'] = $b;
            }
        }
        // Hours of today, or the periods (days, weeks, months -- old months from their files).
        $src = $by === 'hour' ? $periods : $r['periods'];
        $total = self::sum($src, 'passed') + self::sum($src, 'uncached') + self::sum($src, 'checked') + self::sum($src, 'throttled') + self::sum($src, 'refused');
        $tile = static fn (string $key, int $value, string $cls, array $curve): string => '<div class="tile ' . $cls . '"><span class="n">' . $e($n($value)) . '</span><span class="l">' . $e($t[$key]) . '</span>'
            . self::spark(self::ints($curve)) . '</div>';

        // The tabs: visitors and pages (editors) -- protection (admins). An
        // embedding page can show one only ('tabs' => false).
        // Live and lists are the WAF's pages (Waf\LivePage, ListsPage): tabs here when the site has them.
        // Its own labels for its own views; the other pages' (live, lists, an extension's) from the routes.
        $tabs = $links !== [] ? array_intersect_key(['sites' => $t['tabSites'], 'all' => $t['tabAll'], 'site' => $t['tabSite'], 'shield' => $t['tabShield'], 'rules' => $t['tabRules']]
            + array_map(static fn (array $tab): string => $tab[$lang === 'de' ? 1 : 0], Routes::tabs($s)), $links)
            : ['site' => $t['tabSite'], 'shield' => $t['tabShield'], 'rules' => $t['tabRules']];
        $h = '';
        if (($o['tabs'] ?? true) && count($tabs) > 1) {
            $h .= '<nav class="tabs">';
            foreach ($tabs as $v => $label) {
                $href = isset($links[$v]) ? $links[$v] . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => $v, 'days' => $days, 'lang' => $lang]);
                $h .= '<a class="tab' . ($v === $view ? ' on' : '') . '" href="' . $e($href) . '">' . $e($label) . '</a>';
            }
            $h .= '</nav>';
        }
        $h .= '<div class="bar"><div class="pills">';
        $plain = array_diff_key($extra, $range);
        foreach ([[1, 'hour', 'today'], [7, 'day', 'd7'], [30, 'day', 'd30'], [365, 'month', 'm12']] as [$d, $b, $label]) {
            $h .= '<a class="pill' . ($d === $days && $range === [] ? ' on' : '') . '" href="' . $e($query(['view' => $view, 'days' => $d, 'by' => $b, 'lang' => $lang] + $plain)) . '">' . $e($t[$label]) . '</a>';
        }
        // This month, last month: a range each.
        $first = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
        foreach ([['thisMonth', $first, (int) $now], ['lastMonth', (int) strtotime('-1 month', $first), $first - 86400]] as [$label, $a, $z]) {
            $span = ['from' => gmdate('Y-m-d', $a), 'to' => gmdate('Y-m-d', $z)];
            $h .= '<a class="pill' . ($span === $range ? ' on' : '') . '" href="' . $e($query(['view' => $view] + $span + ['lang' => $lang] + $plain)) . '">' . $e($t[$label]) . '</a>';
        }
        // Any range: two dates (the browser's date picker), sent as GET.
        $h .= '<form class="range" method="get" action="' . $e($action) . '">' . ($links === [] ? '<input type="hidden" name="view" value="' . $e($view) . '">' : '')
            . '<input type="hidden" name="lang" value="' . $e($lang) . '">'
            . '<label>' . $e($t['from']) . ' <input type="date" name="from" value="' . $e($range['from'] ?? gmdate('Y-m-d', (int) strtotime('-6 days', (int) $now))) . '"></label> '
            . '<label>' . $e($t['to']) . ' <input type="date" name="to" value="' . $e($range['to'] ?? gmdate('Y-m-d', (int) $now)) . '"></label> <button type="submit">' . $e($t['show']) . '</button></form>';
        $h .= '</div><div class="pills">';
        foreach (['de' => 'DE', 'en' => 'EN'] as $l => $label) {
            $h .= '<a class="pill' . ($l === $lang ? ' on' : '') . '" href="' . $e($query(['view' => $view, 'days' => $days, 'by' => $by, 'lang' => $l] + $extra)) . '">' . $label . '</a>';
        }
        // The same as JSON: the API's report (0031 G.0), where the API is.
        $api = $o['api'] ?? '';
        $h .= ($api !== '' ? '<a class="pill" href="' . $e($api . '/stats/report?' . http_build_query(['days' => $days, 'by' => $by === 'hour' ? 'day' : $by] + $range + ($site !== null ? ['site' => $site] : []))) . '">JSON</a>' : '') . '</div></div>';
        // The website switch (stats-hosts): all added up, one website, or the names the rules do not know.
        if (StatsExtension::of($s)['hosts'] !== [] && $view !== 'sites') {
            $chosen = false;
            $option = static function (string $value, string $label) use ($site, $e, &$chosen): string {
                $on = !$chosen && $value === (string) $site;
                $chosen = $chosen || $on;
                return '<option value="' . $e($value) . '"' . ($on ? ' selected' : '') . '>' . $e($label) . '</option>';
            };
            $opts = $option('', $t['allSites']);
            $grouped = [];
            // A section per group (stats-group): the whole group, then each of its websites.
            foreach ($who === '*' ? StatsExtension::of($s)['groups'] : array_intersect_key(StatsExtension::of($s)['groups'], [$who => 1]) as $id => $g) {
                $opts .= '<optgroup label="' . $e($g['name']) . '">' . $option('group:' . $id, count($g['sites']) === 1 ? sprintf($t['groupOne'], $g['name']) : sprintf($t['groupAll'], $g['name'], count($g['sites'])));
                foreach ($g['sites'] as $name) {
                    $opts .= $option($name, $name);
                    $grouped[$name] = true;
                }
                $opts .= '</optgroup>';
            }
            $rest = $who !== '*' ? [] : array_values(array_filter(StatsExtension::of($s)['hosts'], static fn (string $n): bool => !isset($grouped[$n])));
            if ($rest !== []) {
                $opts .= StatsExtension::of($s)['groups'] !== [] ? '<optgroup label="' . $e($t['ungrouped']) . '">' : '';
                foreach ($rest as $name) {
                    $opts .= $option($name, $name);
                }
                $opts .= StatsExtension::of($s)['groups'] !== [] ? '</optgroup>' : '';
            }
            if ($who === '*') {
                $opts .= $option(\CjwNetwork\RequestShield\Stats\Stats::OTHER, $t['otherHosts']);
            } else {
                $opts = (string) preg_replace('#^<option value=""[^>]*>[^<]*</option>#', '', $opts);    // a customer: no "all websites"
            }
            // The form keeps the view, the period, the language and the filters; only the website changes.
            $keepSite = ['view' => $links === [] ? $view : null, 'days' => $range === [] ? $days : null, 'by' => $range === [] ? $by : null, 'lang' => $lang] + $range
                + array_diff_key($plain, ['site' => 1]);
            $h .= '<form class="range sites" method="get" action="' . $e($action) . '"><label>' . $e($t['website']) . ' <select name="site">' . $opts . '</select></label>';
            foreach ($keepSite as $k => $v) {
                if ($v !== null) {
                    $h .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
                }
            }
            $h .= ' <button type="submit">' . $e($t['show']) . '</button></form>';
        }
        $h .= '<p class="sub">' . $e(self::date($r['from'], $lang) . ' – ' . self::date($r['to'], $lang))
            . (StatsExtension::of($s)['hosts'] !== [] ? ' · ' . $e(self::siteName($s, $site, $t)) : '')
            . ($who !== '*' ? ' · <a href="?' . $e(http_build_query(['rs-logout' => 1, 'lang' => $lang])) . '">' . $e($t['signOut']) . '</a>' : '') . ($crawler !== null ? ' · ' . $e($crawler) . ' · <a href="' . $e($query(['days' => $days, 'by' => $by, 'lang' => $lang])) . '">' . $e($t['all']) . '</a>' : '') . '</p>';
        if ($view === 'sites') {
            // All websites: the groups with their websites, the rest, where the traffic is.
            $h .= self::sitesTable($s, StatsReport::sites($s, $r['from'], $r['to'], $by === 'hour' ? 'day' : $by, $who), $t, $lang,
                static fn (string $site): string => isset($links['site']) ? $links['site'] . '?' . http_build_query(['site' => $site, 'days' => $days, 'lang' => $lang] + $range)
                    : $query(['view' => 'site', 'site' => $site, 'days' => $days, 'lang' => $lang] + $range),
                (int) strtotime($r['from'] . ' UTC'), (int) strtotime($r['to'] . ' UTC'), $by === 'hour' ? 'day' : $by);
            $h .= '<p class="foot">' . $e($t['updated'] . ' ' . date($lang === 'de' ? 'd.m.Y H:i:s' : 'Y-m-d H:i:s', $now)) . '</p>';
            return ($o['fragment'] ?? false) ? $h : self::page($h, $o['title'] ?? $t['title'], $lang, $o, $e);
        }
        $tiles = [
            'requests' => $tile('requests', $total, 'req', array_map(static fn (int $a, int $b, int $c, int $d, int $x): int => $a + $b + $c + $d + $x, self::curve($hours, 'passed'), self::curve($hours, 'uncached'), self::curve($hours, 'checked'), self::curve($hours, 'throttled'), self::curve($hours, 'refused'))),
            'people' => $tile('people', self::sum($src, 'people'), 'people', self::curve($hours, 'people')),
            'crawlers' => $tile('crawlers', self::sum($src, 'crawlers'), 'crawlers', self::curve($hours, 'crawlers')),
            'bots' => $tile('bots', self::sum($src, 'bots'), 'bots', self::curve($hours, 'bots')),
            'checked' => $tile('checked', self::sum($src, 'checked'), 'checked', self::curve($hours, 'checked')),
            'refused' => $tile('refused', self::sum($src, 'refused') + self::sum($src, 'throttled'), 'refused', array_map(static fn (int $a, int $b): int => $a + $b, self::curve($hours, 'refused'), self::curve($hours, 'throttled'))),
            'notFound' => $tile('notFound', self::sum($src, 'notFound'), 'nf', self::curve($hours, 'notFound')),
        ];
        $per = ' <small>' . $e($t['per'] . ' ' . $t[$by === 'hour' ? 'hour' : $by]) . '</small>';
        $chartWho = '<section class="card"><h2>' . $e($t['who']) . $per . $help('what-is-counted-how') . '</h2>'
            . self::stacked($src, ['people' => [$t['people'], 'people'], 'crawlers' => [$t['crawlers'], 'crawlers'], 'bots' => [$t['bots'], 'bots']], $lang) . '</section>';
        $chartWhat = '<section class="card"><h2>' . $e($t['what']) . $per . $help('what-is-counted-how') . '</h2>'
            . self::stacked(array_map(static fn (array $b): array => ['through' => self::sum([$b], 'passed') + self::sum([$b], 'uncached'), 'checked' => self::sum([$b], 'checked'), 'throttled' => self::sum([$b], 'throttled'), 'refused' => self::sum([$b], 'refused')], $src),
                ['through' => [$t['through'], 'through'], 'checked' => [$t['checked'], 'checked'], 'throttled' => [$t['throttled'], 'throttled'], 'refused' => [$t['refused'], 'refused']], $lang)
            . '</section>';

        // The answers as a ring, and the sentences.
        $groups = [];
        foreach ($r['statuses'] as $code => $count) {
            $g = substr((string) $code, 0, 1) . 'xx';
            $groups[$g] = ($groups[$g] ?? 0) + $count;
        }
        ksort($groups);
        $answers = '<section class="card"><h2>' . $e($t['answers']) . $help('what-is-counted-how') . '</h2>' . ($r['statuses'] === [] ? '<p class="note">' . $e($t['nothing']) . '</p>'
            : '<div class="donut">' . self::donut($groups, $lang) . '<ul class="legend">' . implode('', array_map(static fn ($code, int $c): string => '<li><span class="dot s' . $e(substr((string) $code, 0, 1)) . '"></span><b>' . $e((string) $code) . '</b> ' . $e($n($c)) . '</li>',
                array_keys($r['statuses']), $r['statuses'])) . '</ul></div>') . '</section>';
        $short = '<section class="card"><h2>' . $e($t['short']) . $help('what-it-does') . '</h2>' . ($r['sentences'] === [] ? '<p class="note">' . $e($t['nothing']) . '</p>'
            : '<ul class="short"><li>' . implode('</li><li>', array_map($e, $r['sentences'])) . '</li></ul>') . '</section>';
        $body = $h;
        $h = '';

        // The most visited pages and sections: one bar each, by who came; a
        // subtree filter ("path starts with") with its views.
        // What the filter form carries along: the period, the language -- and the
        // view only where no address names it (with links the path does).
        $keep = ($links !== [] ? [] : ['view' => $view]) + ($range !== [] ? $range : ['days' => $days, 'by' => $by]) + ['lang' => $lang] + ($site !== null ? ['site' => $site] : []) + ($crawler !== null ? ['crawler' => $crawler] : []);
        $sorted = $sort !== $sortDefault ? ['sort' => $sort] : [];
        $link = static fn (string $p): string => $query($keep + ['path' => $p] + $sorted);
        $h = '';
        $views = $sort === 'views';
        $h .= '<section class="card"><h2>' . $e($t[$views ? 'top' : 'topBlocked']) . $help('the-statistics-page') . '</h2><form class="filter" method="get" action="' . $e($action) . '">';
        foreach ($keep as $k => $v) {
            $h .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
        }
        $h .= '<label>' . $e($t['pathStarts']) . ' <input type="text" name="path" value="' . $e((string) $path) . '" placeholder="/news/"></label> <label class="sort">' . $e($t['sortBy']) . ' <select name="sort">'
            . implode('', array_map(static fn (string $v): string => '<option value="' . $v . '"' . ($v === $sort ? ' selected' : '') . '>'
                . $e($t[['views' => 'byViews', 'blocked' => 'byBlocked'][$v] ?? $v]) . '</option>', $sorts)) . '</select></label> <button type="submit">' . $e($t['filter']) . '</button>'
            . ($path !== null ? ' <a href="' . $e($query($keep + $sorted)) . '">' . $e($t['clear']) . '</a>' : '') . '</form>';
        if ($r['subtree'] !== null) {
            $st = $r['subtree'];
            $h .= '<p class="subtree"><b>' . $e($t['subtree'] . ' ' . $st['path']) . ':</b> ' . $e($n($st['total']) . ' ' . $t['views']) . ' — '
                . $e($t['people'] . ' ' . $n($st['people']) . ' · ' . $t['crawlers'] . ' ' . $n($st['crawlers']) . ' · ' . $t['bots'] . ' ' . $n($st['bots']))
                . ' <span class="note">(' . $e($st['exact'] ? $t['exact'] : $t['approx']) . ')</span>'
                . ($st['blocked'] > 0 ? ' — <span class="stop">' . $e($n($st['blocked']) . ' ' . $t['blocked'] . ': ' . self::stopped($st, $t, $lang)) . '</span>' : '') . '</p>';
        }
        $h .= $r['pages'] === [] ? '<p class="note">' . $e($t[$views ? 'noPages' : 'noBlocked']) . '</p>' : self::rows($r['pages'], null, $t, $lang, $views);
        if ($r['folders'] !== []) {
            $h .= '<h2 class="sub2">' . $e($t[$views ? 'sections' : 'sectionsBlocked']) . $help('the-statistics-page') . '</h2>' . self::rows($r['folders'], $link, $t, $lang, $views);
        }
        $h .= '<p class="legend inline">' . ($views ? '<span class="dot people"></span>' . $e($t['people']) . ' <span class="dot crawlers"></span>' . $e($t['crawlers']) . ' <span class="dot bots"></span>' . $e($t['bots'])
            : '<span class="dot refused"></span>' . $e($t['refused']) . ' <span class="dot checked"></span>' . $e($t['checked']) . ' <span class="dot throttled"></span>' . $e($t['throttled'])) . '</p></section>';
        $topBlock = $h;
        $h = '';

        // Crawlers: one bar each -- let through, checked or told to wait, refused; and how often the name was only claimed.
        $seen = array_filter($r['crawlers'], static fn (array $c): bool => $c['seen'] > 0);
        uasort($seen, static fn (array $a, array $b): int => $b['seen'] <=> $a['seen']);
        $max = max(1, ...array_map(static fn (array $c): int => $c['seen'], array_values($seen) ?: [['seen' => 1]]));
        $h .= '<section class="card"><h2>' . $e($t['known']) . $help('', 'RSF01-04') . '</h2>';
        if ($seen === []) {
            $h .= '<p class="note">' . $e($t['nothing']) . '</p>';
        }
        foreach ($seen as $id => $c) {
            $w = static fn (int $v): string => number_format(100 * $v / $max, 2, '.', '');
            $h .= '<div class="crow"><div class="cname"><a href="' . $e($query(['days' => $days, 'by' => $by, 'lang' => $lang, 'crawler' => (string) $id])) . '">' . $e((string) $id) . '</a>'
                . ' <span class="kind">' . $e($t['kind.' . $c['kind']] ?? $c['kind']) . '</span><br><span class="note">' . $e($c['name'])
                . ($c['last'] !== null ? ' · ' . $e($t['last']) . ' ' . $e(date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', $c['last'][0])) : '') . '</span></div>'
                . '<div class="hbar" title="' . $e($n($c['allowed']) . ' ' . $t['allowed'] . ' · ' . $n($c['checked'] + $c['throttled']) . ' ' . $t['checked'] . ' · ' . $n($c['refused']) . ' ' . $t['refused'] . ' · ' . $n($c['claimed']) . ' ' . $t['claimed']) . '">'
                . '<i class="through" style="width:' . $w($c['allowed']) . '%"></i><i class="checked" style="width:' . $w($c['checked'] + $c['throttled']) . '%"></i>'
                . '<i class="refused" style="width:' . $w($c['refused']) . '%"></i><i class="claimed" style="width:' . $w($c['claimed']) . '%"></i></div>'
                . '<div class="cnum">' . $e($n($c['verified'])) . ($c['claimed'] > 0 ? ' <span class="note">+' . $e($n($c['claimed'])) . ' ' . $e($t['claimed']) . '</span>' : '') . '</div></div>';
        }
        $h .= '<p class="legend inline"><span class="dot through"></span>' . $e($t['through']) . ' <span class="dot checked"></span>' . $e($t['checked']) . ' <span class="dot refused"></span>' . $e($t['refused'])
            . ' <span class="dot claimed"></span>' . $e($t['claimed']) . '</p></section>';

        $crawlersBlock = $h;
        $h = '';
        // Sitemaps: which exist (the answers), who read each, when last.
        $h .= '<section class="card"><h2>' . $e($t['sitemaps']) . $help('what-it-does') . '</h2>';
        if ($r['sitemaps'] === []) {
            $h .= '<p class="note">' . $e($t['noMaps']) . '</p>';
        } else {
            $h .= '<table class="list">';
            foreach ($r['sitemaps'] as $path => $x) {
                $h .= '<tr><td><code>' . $e($path) . '</code><br>' . implode(' ', array_map(static fn ($code, int $c): string => '<span class="badge s' . $e(substr((string) $code, 0, 1)) . '">' . $e((string) $code) . ' · ' . $e($n($c)) . '</span>',
                    array_keys($x['statuses']), $x['statuses'])) . '</td><td>'
                    . ($x['crawlers'] === [] ? '<span class="note">' . $e($t['noReader']) . '</span>' : implode('<br>', array_map(static fn ($id, array $c): string => '<b>' . $e((string) $id) . '</b> '
                        . $e($n($c['count']) . $t['times']) . ($c['last'] !== null ? ' <span class="note">· ' . $e($t['last']) . ' ' . $e(date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', $c['last'])) . '</span>' : ''),
                        array_keys($x['crawlers']), $x['crawlers'])))
                    . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section>';

        $sitemapsBlock = $h;
        $h = '';
        // Not found, rules, bots: lists with a bar each.
        $missing = [];
        foreach ($r['notFound'] as $path => $x) {
            $from = [];
            foreach ($x['referrers'] as $source => $c) {
                $from[] = "$source ($c)";
            }
            $missing[] = ['<code>' . $e((string) $path) . '</code>' . ($from !== [] ? '<br><span class="note">' . $e($t['linked'] . ' ' . implode(', ', $from)) . '</span>' : ''), $x['count']];
        }
        // The rules that decided most: their ID (a link to the rule in "Rules & setup"), what they do, where written.
        $rules = [];
        $setup = $links['rules'] ?? ($links === [] ? $action : null);
        foreach (array_slice($r['rules'], 0, 10, true) as $rule => $c) {
            $info = Describe::ruleInfo($s, (string) $rule);
            $fixed = $info['id'] === 'built-in';          // method, sizes, disguised addresses: steps, not rules
            $info['text'] ??= $fixed ? $t['builtIn'] : null;
            $id = '<code>' . $e($info['id']) . '</code>';
            $href = $setup !== null ? ($links !== [] ? $setup . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => 'rules', 'days' => $days, 'lang' => $lang])) . ($fixed ? '#way' : '#rule-' . Describe::anchor($info['id'])) : null;
            $rules[] = [($href !== null ? '<a href="' . $e($href) . '">' . $id . '</a>' : $id)
                . ($info['text'] !== null ? '<br>' . $e($info['text']) : '') . ($info['where'] !== null ? '<br><span class="note">' . $e($info['where']) . '</span>' : ''), $c];
        }
        $bots = [];
        foreach ($r['bots'] as $family => $c) {
            $bots[] = [$e((string) $family), $c];
        }
        $missingBlock = '<section class="card"><h2>' . $e($t['missing']) . $help('what-it-does') . '</h2>' . self::bars($missing, $lang, $t['nothing']) . '</section>';
        $rulesBlock = $who !== '*' ? '<section class="card"><h2>' . $e($t['botfam']) . $help('what-is-counted-how') . '</h2>' . self::bars($bots, $lang, $t['nothing']) . '</section>'
            : '<section class="card"><h2>' . $e($t['rules']) . $help('', 'RSF05-05') . '</h2>' . self::bars($rules, $lang, $t['nothing'])
            . ($setup !== null ? '<p class="note"><a href="' . $e($links !== [] ? $setup . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => 'rules', 'days' => $days, 'lang' => $lang])) . '">' . $e($t['ruleDetails']) . ' →</a></p>' : '')
            . '<h2>' . $e($t['botfam']) . $help('what-is-counted-how') . '</h2>' . self::bars($bots, $lang, $t['nothing']) . '</section>';
        $speedBlock = self::speed($r['times'], in_array('times', StatsExtension::of($s)['parts'], true), $t, $lang, $help('how-fast-the-site-answered'));
        // Two views: the site's (for editors: visitors, pages, links, crawlers,
        // sitemaps) and the shield's (for admins: what it did, answers, rules, bots).
        $grid = static fn (string ...$cards): string => '<div class="grid2">' . implode('', $cards) . '</div>';
        $hint = '<p class="hint">' . $e($t['hours48']) . '</p>';
        if ($view === 'shield') {
            $h = $body . '<div class="tiles">' . $tiles['requests'] . $tiles['bots'] . $tiles['checked'] . $tiles['refused'] . '</div>' . $hint
                . $grid($chartWhat, $answers) . $speedBlock . $topBlock . $crawlersBlock . $grid($rulesBlock, $chartWho);
        } elseif ($view === 'site') {
            // Visitors & pages (0022): six numbers against the period before, one chart, the pages and crawlers & AI.
            if ($by === 'hour') {
                $cur = $periods;                                        // the last 24 hours
                $prev = array_values(array_slice($hours, 0, 24));       // the 24 before
            } else {
                $a = (int) strtotime($r['from'] . ' UTC');
                $z = (int) strtotime($r['to'] . ' UTC');
                $span = $z - $a + 86400;
                $cur = self::filled($r['periods'], $a, $z, $by);
                $prev = array_values(self::filled(StatsReport::periods($s, $o['stats'] ?? null, gmdate('Ymd', $a - $span), gmdate('Ymd', $a - 86400), $by, $site), $a - $span, $a - 86400, $by));
            }
            $soon = null;
            foreach (isset($o['stats']) ? [$o['stats']] : \CjwNetwork\RequestShield\Stats\Stats::all($s, $site) as $one) {
                $m = $one->lastMinutes(5, (float) $now);
                $soon = $m === null ? $soon : (int) $soon + $m;
            }
            $h = $body . ($soon !== null ? '<p class="vnowl"><span class="dot crawlers"></span> ' . $e(sprintf($t['nowPeople'], $n($soon))) . '</p>' : '')
                . VisitorsPage::render($r, $cur, $prev, ['lang' => $lang, 'docs' => $s->docsUrl, 'by' => $by, 'action' => $action, 'keep' => $keep, 'link' => $link, 'clear' => $query($keep + $sorted), 'path' => $filter,
                    // A form's stops, in the live view (the admin's only): its address, its website when several are read together.
                    'live' => isset($links['live']) ? static function (string $p) use ($links): string {
                        $slash = strpos($p, '/');
                        return $links['live'] . '?' . http_build_query($slash > 0 ? ['host' => (string) substr($p, 0, (int) $slash), 'q' => (string) substr($p, (int) $slash)] : ['q' => $p]);
                    } : null])
                . $short . $sitemapsBlock;                   // full width: sentences and addresses need room
        } else {
            $h = $body . '<div class="tiles">' . implode('', $tiles) . '</div>' . $hint . $grid($chartWho, $chartWhat) . $short . $grid($answers, $rulesBlock) . $speedBlock . $topBlock . $crawlersBlock
                . $sitemapsBlock . $missingBlock;
        }
        $h .= '<p class="foot">' . $e($t['updated'] . ' ' . date($lang === 'de' ? 'd.m.Y H:i:s' : 'Y-m-d H:i:s', $now) . ' · ' . $t['refresh']) . '</p>';

        if ($o['fragment'] ?? false) {
            return $h;
        }
        return self::page($h, $o['title'] ?? $t['title'], $lang, $o + ['refresh' => $query(['view' => $view, 'days' => $days, 'by' => $by, 'lang' => $lang, 'fragment' => 1] + $extra)], $e);
    }

    /**
     * How fast the site answered (0046): the site's median, slow end and
     * average, by kind of visitor and by what the HTTP cache did; the cache's
     * share and what it saved, why misses were not kept; the shield's share;
     * the last two days per hour; the slow log's last lines. Nothing when
     * times is off; a note while it has nothing yet.
     *
     * @param array{kinds: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, site: array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int},
     *   who: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, shield: ?int, reasons: array<string, int>, hitShare: ?float, saved: int,
     *   hourly: array<string, array{count: int, p50: ?int, p95: ?int}>, slow: list<string>, pages: array<string, array{count: int, avg: ?int, p50: ?int, p95: ?int, hits: int}>}|null $tm
     * @param array<string, string> $t
     */
    private static function speed(?array $tm, bool $on, array $t, string $lang, string $help): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($tm === null) {
            return $on ? '<section class="card"><h2>' . $e($t['speed']) . $help . '</h2><p class="note">' . $e($t['nothing']) . '</p></section>'
                : '';
        }
        $d = static fn (?int $us): string => $us === null ? '–' : StatsReport::duration($us, $lang);
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $row = static function (string $label, array $x, bool $strong = false) use ($e, $d, $n): string {
            $count = is_int($x['count'] ?? null) ? $x['count'] : 0;
            $at = static fn (string $k): ?int => is_int($x[$k] ?? null) ? $x[$k] : null;
            return $count === 0 ? '' : '<tr' . ($strong ? ' class="strong"' : '') . '><td>' . $e($label) . '</td><td class="num">' . $e($n($count))
                . '</td><td class="num">' . $e($d($at('p50'))) . '</td><td class="num">' . $e($d($at('p95'))) . '</td><td class="num">' . $e($d($at('avg'))) . '</td></tr>';
        };
        $h = '<section class="card"><h2>' . $e($t['speed']) . $help . '</h2><div class="wrap"><table class="list speed"><thead><tr><th></th><th class="num">' . $e($t['spCount']) . '</th><th class="num">'
            . $e($t['spMedian']) . '</th><th class="num">' . $e($t['spP95']) . '</th><th class="num">' . $e($t['spAvg']) . '</th></tr></thead><tbody>'
            . $row($t['spSite'], $tm['site'], true);
        foreach (['people', 'crawlers', 'bots'] as $w) {
            if (isset($tm['who'][$w]) && count($tm['who']) > 1) {
                $h .= $row('· ' . $t[$w], $tm['who'][$w]);
            }
        }
        foreach (['hit' => 'spHit', 'miss' => 'spMiss', 'nostore' => 'spNostore', 'past' => 'spPast'] as $k => $label) {
            $h .= $row($t[$label], $tm['kinds'][$k]);
        }
        $h .= '</tbody></table></div>';
        if ($tm['hitShare'] !== null) {
            $share = $lang === 'de' ? number_format(100 * $tm['hitShare'], 0, ',', '.') . ' %' : number_format(100 * $tm['hitShare']) . ' %';
            $h .= '<p>' . $e(sprintf($t['spHits'], $share, $d($tm['kinds']['hit']['p50']), $d($tm['kinds']['miss']['p50'] ?? $tm['site']['p50']), $d($tm['saved']))) . '</p>';
        }
        if ($tm['reasons'] !== []) {
            $h .= '<p class="note">' . $e(sprintf($t['spWhy'], implode(', ', array_map(static fn (string $w, int $v): string => ($t['why.' . $w] ?? $w) . ' ' . $n($v), array_map('strval', array_keys($tm['reasons'])), $tm['reasons'])))) . '</p>';
        }
        if ($tm['shield'] !== null) {
            $h .= '<p class="note">' . $e(sprintf($t['spShield'], $d($tm['shield']))) . '</p>';
        }
        if ($tm['pages'] !== []) {
            // The slowest pages (step 2): a page that is slow and never comes from the cache is the first to look at.
            $h .= '<h3 class="sub2">' . $e(sprintf($t['spPages'], StatsReport::PAGE_VIEWS)) . '</h3><div class="wrap"><table class="list speed"><thead><tr><th>' . $e($t['spPage']) . '</th><th class="num">'
                . $e($t['spCount']) . '</th><th class="num">' . $e($t['spMedian']) . '</th><th class="num">' . $e($t['spP95']) . '</th><th class="num">' . $e($t['spFromCache']) . '</th></tr></thead><tbody>';
            foreach ($tm['pages'] as $path => $p) {
                $h .= '<tr><td><code>' . $e(rawurldecode((string) $path)) . '</code></td><td class="num">' . $e($n($p['count'])) . '</td><td class="num">' . $e($d($p['p50']))
                    . '</td><td class="num">' . $e($d($p['p95'])) . '</td><td class="num">' . $e((string) (int) round(100 * $p['hits'] / max(1, $p['count']))) . ' %</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        if (count($tm['hourly']) > 1) {
            $h .= '<h3 class="sub2">' . $e($t['spHours']) . '</h3><div class="speedcurves"><span class="dot through"></span>' . self::spark(array_values(array_map(static fn (array $x): int => (int) $x['p50'], $tm['hourly'])))
                . '<span class="dot refused"></span>' . self::spark(array_values(array_map(static fn (array $x): int => (int) $x['p95'], $tm['hourly']))) . '</div>';
        }
        if ($tm['slow'] !== []) {
            $h .= '<h3 class="sub2">' . $e($t['spSlow']) . '</h3><ul class="short slow"><li><code>' . implode('</code></li><li><code>', array_map($e, array_reverse(array_slice($tm['slow'], -10)))) . '</code></li></ul>';
        }
        return $h . '</section>';
    }

    /**
     * The table of all websites: a row for all, each group (and below it its
     * websites), the websites in no group, the other hosts -- sorted by page
     * views; each a link to its statistics.
     *
     * @param array{sites: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>, groups: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>, all: array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}|null} $x
     * @param array<string, string> $t
     * @param callable(string): string $href the statistics of a website, a group (group:<id>), all ('')
     */
    private static function sitesTable(Settings $s, array $x, array $t, string $lang, callable $href, int $from, int $to, string $by): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $most = 1;
        foreach (array_merge($x['sites'], $x['groups']) as $r) {
            $most = max($most, $r['views']);
        }
        $views = static fn (array $a, array $b): int => $b['views'] <=> $a['views'];
        $ctx = [$t, $lang, $most, $from, $to, $by];
        // Short headings, each explained on hover: the table fits, the curve at the end stays in view.
        $th = static fn (string $short, string $tip, string $class = 'n'): string => '<th class="' . $class . '" title="' . $e($tip) . '">' . $e($short) . '</th>';
        $h = '<section class="card"><h2>' . $e($t['tabSites']) . ' <small>' . $e($t['sitesIntro']) . '</small>' . (($l = Help::link('RSF06-03', 'all-websites-at-a-glance-rsstatssites', $s->docsUrl, $lang)) !== '' ? ' ' . $l : '') . '</h2><div class="wrap"><table class="sites"><thead><tr>'
            . $th($t['website'], $t['websiteTip'], 'sname') . $th($t['hViews'], $t['viewsTip'], 'snum') . $th('±', $t['changeTip']) . $th($t['hPeople'], $t['peopleTip'])
            . $th($t['hSearch'], $t['searchTip']) . $th($t['hAi'], $t['aiTip']) . $th($t['hBots'], $t['botsTip']) . $th($t['hStopped'], $t['stoppedTip']) . $th('404', $t['notFoundTip'])
            . $th($t['hCurve'], $t['curveTip'], 'scurve') . '</tr></thead><tbody>';
        if ($x['all'] !== null) {
            $h .= self::sitesRow($t['allSites'], $x['all'], $href(''), 'sall', '', ...$ctx);
        }
        $groups = $x['groups'];
        uasort($groups, $views);
        $grouped = [];
        foreach ($groups as $id => $g) {
            $def = StatsExtension::of($s)['groups'][$id];
            $h .= self::sitesRow($def['name'], $g, $href('group:' . $id), 'sgroup', count($def['sites']) === 1 ? $t['oneSite'] : sprintf($t['nSites'], count($def['sites'])), ...$ctx);
            $members = array_intersect_key($x['sites'], array_flip($def['sites']));
            uasort($members, $views);
            foreach ($members as $name => $r) {
                $h .= self::sitesRow((string) $name, $r, $href((string) $name), 'ssite', '', ...$ctx);
                $grouped[$name] = true;
            }
        }
        $rest = array_diff_key($x['sites'], $grouped, [\CjwNetwork\RequestShield\Stats\Stats::OTHER => 1]);
        uasort($rest, $views);
        foreach ($rest as $name => $r) {
            $h .= self::sitesRow((string) $name, $r, $href((string) $name), 'ssite top', '', ...$ctx);
        }
        if (isset($x['sites'][\CjwNetwork\RequestShield\Stats\Stats::OTHER])) {
            $h .= self::sitesRow($t['otherShort'], $x['sites'][\CjwNetwork\RequestShield\Stats\Stats::OTHER], $href(\CjwNetwork\RequestShield\Stats\Stats::OTHER), 'sother', '', ...$ctx);
        }
        return $h . '</tbody></table></div></section>';
    }

    /**
     * One row of the overview of all websites.
     *
     * @param array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>} $r
     * @param array<string, string> $t
     */
    private static function sitesRow(string $label, array $r, string $link, string $class, string $note, array $t, string $lang, int $most, int $from, int $to, string $by): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);

            $cur = $r['views'];
            $prev = $r['prev'];
            if ($prev === 0) {
                $change = $cur > 0 ? '<span class="up">' . $e($t['new']) . '</span>' : '–';
            } else {
                $pct = (int) round(($cur - $prev) * 100 / $prev);
                $change = '<span class="' . ($pct >= 0 ? 'up' : 'down') . '" title="' . $e(sprintf($t['before'], $n($prev))) . '">' . ($pct >= 0 ? '+' : '−') . abs($pct) . ' %</span>';
            }
            $periods = [];
            foreach ($r['curve'] as $day => $v) {
                $periods[$day] = ['views' => $v];
            }
            $curve = [];
            foreach (self::filled($periods, $from, $to, $by) as $b) {
                $curve[] = (int) ($b['views'] ?? 0);
            }
            return '<tr class="' . $class . '"><td class="sname"><a href="' . $e($link) . '">' . $e($label) . '</a>' . ($note !== '' ? ' <span class="note">' . $e($note) . '</span>' : '') . '</td>'
                . '<td class="snum">' . ($class === 'sall' ? '' : '<div class="hbar thin"><i class="people" style="width:' . number_format(min(100, 100 * $cur / $most), 2, '.', '') . '%"></i></div>') . $e($n($cur)) . '</td>'
                . '<td class="schange">' . $change . '</td><td>' . $e($n($r['people'])) . '</td><td>' . $e($n($r['search'])) . '</td><td>' . $e($n($r['ai'])) . '</td><td>' . $e($n($r['bots'])) . '</td>'
                . '<td>' . ($r['stopped'] > 0 ? '<span class="stop">' . $e($n($r['stopped'])) . '</span>' : '0') . '</td><td>' . $e($n($r['notFound'])) . '</td>'
                . '<td class="scurve">' . self::spark($curve) . '</td></tr>';
    }

    /**
     * What a choice of the website switch is called: all websites, a group
     * (with how many websites), other hosts, a website.
     *
     * @param array<string, string> $t
     */
    private static function siteName(Settings $s, ?string $site, array $t): string
    {
        if ($site === null) {
            return $t['allSites'];
        }
        if ($site === \CjwNetwork\RequestShield\Stats\Stats::OTHER) {
            return $t['otherHosts'];
        }
        if (strncmp($site, 'group:', 6) === 0 && isset(StatsExtension::of($s)['groups'][substr($site, 6)])) {
            $g = StatsExtension::of($s)['groups'][substr($site, 6)];
            return (count($g['sites']) === 1 ? sprintf($t['groupOne'], $g['name']) : sprintf($t['groupAll'], $g['name'], count($g['sites']))) . ': ' . implode(', ', $g['sites']);
        }
        return $site;
    }

    /**
     * The addresses of the views this page renders, from the compiled routes:
     * the statistics' own (StatsExtension::routes(): sites with stats-hosts,
     * all -- the dashboard --, site -- visitors and pages --, shield -- the
     * protection) below stats-path, and the core's rules (the way of a
     * request, every rule and setting) -- for 'links', and for a site's routes.
     *
     * @return array<string, string> sites?, all, site, shield, rules => address
     */
    public static function links(Settings $s, string $prefix = ''): array
    {
        $out = [];
        foreach ($s->routes as $path => $r) {
            // Its own pages, and the WAF's Rules & setup (when that plugin is there): the statistics' tabs lead there too.
            if ($r['tab'] !== null && (self::owns($r) || ($r['ext'] === 'waf' && $r['key'] === 'rules')) && !isset($out[$r['key']])) {
                $out[$r['key']] = $prefix . $path;
            }
        }
        return $out;
    }

    /**
     * Which view a path asks for (sites, all, site, shield), or null;
     * capitals do not matter. stats-path itself is the plugin's start: all
     * websites with stats-hosts, else the overview.
     */
    public static function viewFor(Settings $s, string $path): ?string
    {
        $p = strtolower(rtrim($path, '/'));
        foreach ($s->routes as $route => $r) {
            if (self::owns($r) && $p === strtolower(rtrim($route, '/'))) {
                return $r['key'];
            }
        }
        return null;
    }

    /**
     * A route this page renders: the statistics extension's own (Rules & setup is the core's, SetupPage).
     *
     * @param array{key: string, ext: ?string} $r
     */
    private static function owns(array $r): bool
    {
        return $r['ext'] === 'stats';
    }

    /**
     * Pages or sections, a bar each: by who came (people, crawlers, bots), or
     * by what the shield did (refused, checked, told to wait).
     *
     * @param array<array-key, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $list
     * @param (callable(string): string)|null $href a link for each (a section: its subtree)
     * @param array<string, string> $t
     */
    private static function rows(array $list, ?callable $href, array $t, string $lang, bool $views = true): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $most = 1;
        foreach ($list as $v) {
            $most = max($most, $views ? $v['total'] : $v['blocked']);
        }
        $out = '';
        foreach ($list as $p => $v) {
            $w = static fn (int $x): string => number_format(100 * $x / $most, 2, '.', '');
            $name = '<code>' . $e((string) $p) . '</code>';
            $out .= '<div class="prow"><div class="pname">' . ($href !== null ? '<a href="' . $e($href((string) $p)) . '">' . $name . '</a>' : $name) . '</div>';
            if ($views) {
                // Views by who came; what the shield stopped there as a note.
                $out .= '<div class="hbar thin" title="' . $e($n($v['people']) . ' ' . $t['people'] . ' · ' . $n($v['crawlers']) . ' ' . $t['crawlers'] . ' · ' . $n($v['bots']) . ' ' . $t['bots']) . '">'
                    . '<i class="people" style="width:' . $w($v['people']) . '%"></i><i class="crawlers" style="width:' . $w($v['crawlers']) . '%"></i><i class="bots" style="width:' . $w($v['bots']) . '%"></i></div>'
                    . '<div class="cnum">' . $e($n($v['total'])) . ' <span class="note">' . $e($n($v['people']) . ' · ' . $n($v['crawlers']) . ' · ' . $n($v['bots'])) . '</span>'
                    . ($v['blocked'] > 0 ? ' <span class="stop" title="' . $e(self::stopped($v, $t, $lang)) . '">' . $e($n($v['blocked']) . ' ' . $t['blocked']) . '</span>' : '') . '</div></div>';
            } else {
                // What the shield stopped, by how; the page's views as a note.
                $out .= '<div class="hbar thin" title="' . $e(self::stopped($v, $t, $lang)) . '">'
                    . '<i class="refused" style="width:' . $w($v['refused']) . '%"></i><i class="checked" style="width:' . $w($v['checked']) . '%"></i><i class="throttled" style="width:' . $w($v['throttled']) . '%"></i></div>'
                    . '<div class="cnum"><span class="stop">' . $e($n($v['blocked'])) . '</span> <span class="note">' . $e($n($v['refused']) . ' · ' . $n($v['checked']) . ' · ' . $n($v['throttled']))
                    . ' — ' . $e($n($v['total']) . ' ' . $t['views']) . '</span></div></div>';
            }
        }
        return $out;
    }

    /**
     * What the shield stopped, in words: "3 Refused · 1 Checked".
     *
     * @param array{refused: int, checked: int, throttled: int} $v
     * @param array<string, string> $t
     */
    private static function stopped(array $v, array $t, string $lang): string
    {
        $out = [];
        foreach (['refused', 'checked', 'throttled'] as $k) {
            if ($v[$k] > 0) {
                $out[] = StatsReport::number($v[$k], $lang) . ' ' . $t[$k];
            }
        }
        return implode(' · ', $out);
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = is_int($v) ? $v : 0;
        }
        return $out;
    }

    /**
     * The sum of one of the numbers over periods.
     *
     * @param array<array-key, array<string, int>> $list
     */
    private static function sum(array $list, string $k): int
    {
        $total = 0;
        foreach ($list as $b) {
            $total += $b[$k] ?? 0;
        }
        return $total;
    }

    /**
     * One number over the hours, for a small curve.
     *
     * @param array<array-key, array<string, int>> $hours
     * @return list<int>
     */
    private static function curve(array $hours, string $k): array
    {
        $out = [];
        foreach ($hours as $b) {
            $out[] = $b[$k] ?? 0;
        }
        return $out;
    }

    /**
     * Rows with a bar each: the label (HTML, escaped already), the count.
     *
     * @param list<array{0: string, 1: int}> $list
     */
    private static function bars(array $list, string $lang, string $nothing): string
    {
        if ($list === []) {
            return '<p class="note">' . htmlspecialchars($nothing, ENT_QUOTES) . '</p>';
        }
        $max = 1;
        foreach ($list as $row) {
            $max = max($max, $row[1]);
        }
        $out = '<table class="list">';
        foreach ($list as [$label, $count]) {
            $out .= '<tr><td>' . $label . '</td><td class="num">' . htmlspecialchars(StatsReport::number($count, $lang), ENT_QUOTES) . '</td>'
                . '<td class="barcell"><i style="width:' . number_format(100 * $count / $max, 2, '.', '') . '%"></i></td></tr>';
        }
        return $out . '</table>';
    }

    /**
     * A day from a form (YYYY-MM-DD), as midnight UTC; null when it is none.
     * @param mixed $v
     */
    private static function day($v): ?int
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return (int) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Every period of a span, the empty ones too, in order: a chart has a point
     * for each day (week, month, year), not only for the ones with numbers.
     *
     * @param array<array-key, array<string, int>> $periods
     * @return array<string, array<string, int>>
     */
    private static function filled(array $periods, int $from, int $to, string $by): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d += 86400) {
            $out[['week' => gmdate('o-\\WW', $d), 'month' => gmdate('Y-m', $d), 'year' => gmdate('Y', $d)][$by] ?? gmdate('Y-m-d', $d)] = [];
        }
        foreach ($periods as $label => $b) {
            $out[(string) $label] = $b;
        }
        uksort($out, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
        return $out;
    }

    /**
     * @param array<string, mixed> $o
     * @param callable(string): string $e
     */
    private static function page(string $body, string $title, string $lang, array $o, callable $e): string
    {
        $refresh = is_string($o['refresh'] ?? null) ? $o['refresh'] : '';
        return '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . VisitorsPage::css() . Help::CSS . '</style></head><body>'
            . (isset($o['home']) && is_string($o['home']) ? '<header><a href="' . $e($o['home']) . '">← ' . $e(is_string($o['homeLabel'] ?? null) ? $o['homeLabel'] : 'Back') . '</a></header>' : '')
            . '<main><h1>' . $e($title) . (is_string($o['help'] ?? null) && $o['help'] !== '' ? ' ' . $o['help'] : '') . '</h1><div id="stats"' . ($refresh !== '' ? ' data-refresh="' . $e($refresh) . '"' : '') . '>' . $body . '</div></main>'
            . '<script>' . self::SCRIPT . '</script></body></html>';
    }

    /**
     * A small curve of the last hours.
     *
     * @param list<int> $values
     */
    private static function spark(array $values): string
    {
        $max = max(1, ...($values ?: [0]));
        $count = count($values);
        if ($count < 2) {
            return '';
        }
        $points = [];
        foreach ($values as $i => $v) {
            $points[] = number_format($i * 120 / ($count - 1), 1, '.', '') . ',' . number_format(26 - 24 * $v / $max, 1, '.', '');
        }
        return '<svg class="spark" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $points) . '"/></svg>';
    }

    /**
     * Stacked bars, one per period; every part has its tooltip.
     *
     * @param array<array-key, array<string, int>> $periods label => series => count
     * @param array<string, array{0: string, 1: string}> $series key => [label, css class]
     */
    private static function stacked(array $periods, array $series, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = count($periods);
        $legend = '<p class="legend inline">' . implode(' ', array_map(static fn (array $x): string => '<span class="dot ' . $x[1] . '"></span>' . $e($x[0]), $series)) . '</p>';
        if ($count === 0) {
            return $legend;
        }
        $max = 1;
        foreach ($periods as $b) {
            $sum = 0;
            foreach (array_keys($series) as $k) {
                $sum += (int) ($b[$k] ?? 0);
            }
            $max = max($max, $sum);
        }
        $w = 600;
        $h = 180;
        $slot = $w / $count;
        $bar = max(2.0, min(40.0, $slot * 0.7));
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" role="img">';
        foreach ([0.25, 0.5, 0.75, 1.0] as $f) {
            $y = number_format($h - $h * $f, 1, '.', '');
            $svg .= '<line class="grid" x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>';
        }
        $svg .= '<text class="axis" x="2" y="11">' . $e(StatsReport::number($max, $lang)) . '</text>';
        $i = 0;
        $every = (int) max(1, ceil($count / 12));
        foreach ($periods as $label => $b) {
            $x = $i * $slot + ($slot - $bar) / 2;
            $y = $h;
            $tip = [(string) $label];
            foreach ($series as $k => [$name, $cls]) {
                $v = (int) ($b[$k] ?? 0);
                $tip[] = $name . ': ' . StatsReport::number($v, $lang);
                if ($v === 0) {
                    continue;
                }
                $bh = $h * $v / $max;
                $y -= $bh;
                $svg .= '<rect class="' . $cls . '" x="' . number_format($x, 1, '.', '') . '" y="' . number_format($y, 1, '.', '') . '" width="' . number_format($bar, 1, '.', '') . '" height="' . number_format($bh, 1, '.', '') . '"><title>'
                    . $e($label . ' · ' . $name . ': ' . StatsReport::number($v, $lang)) . '</title></rect>';
            }
            $svg .= '<rect class="hit" x="' . number_format($i * $slot, 1, '.', '') . '" y="0" width="' . number_format($slot, 1, '.', '') . '" height="' . $h . '"><title>' . $e(implode("\n", $tip)) . '</title></rect>';
            if ($i % $every === 0) {
                $svg .= '<text class="axis" x="' . number_format($i * $slot + $slot / 2, 1, '.', '') . '" y="' . ($h + 16) . '" text-anchor="middle">' . $e(self::short((string) $label, $lang)) . '</text>';
            }
            $i++;
        }
        return $svg . '</svg>' . $legend;
    }

    /**
     * A ring of the answers by class (2xx, 3xx, 4xx, 5xx).
     *
     * @param array<string, int> $groups
     */
    private static function donut(array $groups, string $lang): string
    {
        $total = max(1, array_sum($groups));
        $r = 15.9155;          // circumference 100
        $offset = 25.0;
        $svg = '<svg viewBox="0 0 42 42" class="ring" role="img">';
        foreach ($groups as $g => $count) {
            $part = 100 * $count / $total;
            $svg .= '<circle class="s' . htmlspecialchars(substr($g, 0, 1), ENT_QUOTES) . '" cx="21" cy="21" r="' . $r . '" fill="none" stroke-width="6" stroke-dasharray="'
                . number_format($part, 2, '.', '') . ' ' . number_format(100 - $part, 2, '.', '') . '" stroke-dashoffset="' . number_format($offset, 2, '.', '') . '"><title>'
                . htmlspecialchars($g . ': ' . StatsReport::number($count, $lang), ENT_QUOTES) . '</title></circle>';
            $offset -= $part;
        }
        return $svg . '<text x="21" y="23" text-anchor="middle" class="ringn">' . htmlspecialchars(StatsReport::number(array_sum($groups), $lang), ENT_QUOTES) . '</text></svg>';
    }

    /** A period's label, short: 30.09., KW 40, Sep 2026, 2026, 14:00. */
    private static function short(string $label, string $lang): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $label, $m)) {
            return $lang === 'de' ? "$m[3].$m[2]." : date('M j', (int) strtotime($label));
        }
        if (preg_match('/^(\d{4})-W(\d{2})$/', $label, $m)) {
            return ($lang === 'de' ? 'KW ' : 'W') . (int) $m[2];
        }
        if (preg_match('/^(\d{4})-(\d{2})(?: \(month\))?$/', $label, $m)) {
            return $lang === 'de' ? "$m[2]/$m[1]" : date('M Y', (int) strtotime("$m[1]-$m[2]-01"));
        }
        return $label;
    }

    private static function date(string $ymd, string $lang): string
    {
        $t = (int) strtotime($ymd . ' UTC');
        return $lang === 'de' ? gmdate('d.m.Y', $t) : gmdate('Y-m-d', $t);
    }

    private const SCRIPT = <<<'JS'
(function () {
  var box = document.getElementById('stats'), url = box && box.getAttribute('data-refresh');
  if (!url || !window.fetch) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (!html) { return; }
        // The picked number and tabs stay picked: their radio buttons by id.
        var on = [].map.call(box.querySelectorAll('input[type=radio]:checked'), function (i) { return i.id; });
        box.innerHTML = html;
        on.forEach(function (id) { var i = document.getElementById(id); if (i) { i.checked = true; } });
      }, function () {});
  }, 60000);
})();
JS;

    private const CSS = <<<'CSS'
:root{--bg:#f4f6f9;--fg:#1d2127;--m:#5b6470;--card:#fff;--line:#e3e7ec;--a:#2f62c9;--people:#2f62c9;--crawlers:#1e8a52;--bots:#c07a00;--through:#1e8a52;--checked:#2f62c9;--throttled:#c07a00;--refused:#c2412d;--claimed:#9aa3ae;--s2:#1e8a52;--s3:#2f62c9;--s4:#c07a00;--s5:#c2412d;--nf:#8b5cf6}
@media (prefers-color-scheme:dark){:root{--bg:#121519;--fg:#e7e9ec;--m:#9aa4b0;--card:#1b1f24;--line:#2d333b;--a:#7aa2ff;--people:#6f9bff;--crawlers:#4cc38a;--bots:#e0a43c;--through:#4cc38a;--checked:#6f9bff;--throttled:#e0a43c;--refused:#ff7a66;--claimed:#6b7480;--s2:#4cc38a;--s3:#6f9bff;--s4:#e0a43c;--s5:#ff7a66;--nf:#a78bfa}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
header{max-width:1120px;margin:0 auto;padding:14px 16px 0}header a,a{color:var(--a)}main{max-width:1120px;margin:0 auto;padding:6px 16px 28px}
h1{font-size:26px;margin:8px 0 4px}h2{font-size:16px;margin:0 0 10px}h2 small{color:var(--m);font-weight:400}
.bar{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;margin:6px 0}.pills{display:flex;flex-wrap:wrap;gap:6px}
.pill{padding:4px 12px;border:1px solid var(--line);border-radius:999px;background:var(--card);text-decoration:none;color:var(--fg);font-size:14px}.pill.on{background:var(--a);border-color:var(--a);color:#fff}
.sub,.hint,.note,.foot{color:var(--m)}.sub{margin:4px 0 12px}.hint{font-size:12px;margin:4px 0 0;text-align:right}.foot{font-size:13px;margin-top:16px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 12px 6px;position:relative;overflow:hidden}
.tile .n{display:block;font-size:24px;font-weight:700;line-height:1.2}.tile .l{color:var(--m);font-size:13px}
.spark{display:block;width:100%;height:28px;margin-top:4px}.spark polyline{fill:none;stroke:var(--c,var(--a));stroke-width:1.6;vector-effect:non-scaling-stroke}
.tile.people{--c:var(--people)}.tile.crawlers{--c:var(--crawlers)}.tile.bots{--c:var(--bots)}.tile.checked{--c:var(--checked)}.tile.refused{--c:var(--refused)}.tile.nf{--c:var(--nf)}.tile.req{--c:var(--fg)}
.tile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c,var(--a))}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-top:14px;align-items:start}@media (max-width:420px){.grid2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-top:14px;overflow-x:auto}.grid2 .card{margin-top:0}
.chart{width:100%;height:auto;display:block}.chart .grid{stroke:var(--line);stroke-width:1}.chart .axis{fill:var(--m);font-size:11px}.chart .hit{fill:transparent}.chart .hit:hover{fill:rgba(127,127,127,.08)}
.chart .people{fill:var(--people)}.chart .crawlers{fill:var(--crawlers)}.chart .bots{fill:var(--bots)}.chart .through{fill:var(--through)}.chart .checked{fill:var(--checked)}.chart .throttled{fill:var(--throttled)}.chart .refused{fill:var(--refused)}
.legend{list-style:none;padding:0;margin:8px 0 0;font-size:13px;color:var(--m)}.legend.inline{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center}
.dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:middle;background:var(--m)}
.dot.people{background:var(--people)}.dot.crawlers{background:var(--crawlers)}.dot.bots{background:var(--bots)}.dot.through{background:var(--through)}.dot.checked{background:var(--checked)}.dot.throttled{background:var(--throttled)}.dot.refused{background:var(--refused)}.dot.claimed{background:var(--claimed)}
.dot.s2{background:var(--s2)}.dot.s3{background:var(--s3)}.dot.s4{background:var(--s4)}.dot.s5{background:var(--s5)}
.donut{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.ring{width:150px;height:150px}.ring circle{stroke:var(--m)}.ring .s2{stroke:var(--s2)}.ring .s3{stroke:var(--s3)}.ring .s4{stroke:var(--s4)}.ring .s5{stroke:var(--s5)}.ringn{font-size:6px;font-weight:700;fill:var(--fg)}
.short{margin:0;padding-left:18px}.short li{margin:4px 0}
.crow{display:grid;grid-template-columns:minmax(160px,260px) 1fr auto;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line)}.crow:last-of-type{border-bottom:0}
.cname a{font-weight:600;text-decoration:none}.kind{font-size:11px;padding:1px 7px;border-radius:999px;background:var(--bg);color:var(--m);border:1px solid var(--line)}
.hbar{display:flex;height:14px;border-radius:7px;overflow:hidden;background:var(--bg)}.hbar i{display:block;height:100%}
.hbar .through{background:var(--through)}.hbar .checked{background:var(--checked)}.hbar .refused{background:var(--refused)}.hbar .throttled{background:var(--throttled)}.stop{color:var(--refused);font-weight:600}.filter select{font:inherit;padding:3px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg)}.filter label.sort{flex:0 0 auto}.hbar .claimed{background:repeating-linear-gradient(45deg,var(--claimed) 0 4px,transparent 4px 7px)}
.prow{display:grid;grid-template-columns:1fr auto;grid-template-areas:"name num" "bar bar";gap:3px 12px;align-items:baseline;padding:7px 0;border-bottom:1px solid var(--line)}.prow:last-of-type{border-bottom:0}
.prow .pname{grid-area:name;min-width:0}.prow .cnum{grid-area:num}.prow .hbar{grid-area:bar}.hbar.thin{height:6px;border-radius:3px}
table.sites{width:100%;border-collapse:collapse;font-size:14px}table.sites th{text-align:left;font-weight:500;color:var(--m);font-size:12px;padding:4px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
table.sites td{padding:6px 8px;border-bottom:1px solid var(--line);white-space:nowrap;text-align:right;width:1%}table.sites td.sname{text-align:left;width:auto}
table.sites th.n{text-align:right;cursor:help}table.sites th.sname,table.sites th.snum{cursor:help}table.sites td.snum{min-width:110px;text-align:left}table.sites td.snum .hbar{width:100%}table.sites td.snum .hbar{margin-bottom:2px}
table.sites tr.sall td{font-weight:600}table.sites tr.sgroup td{font-weight:600;background:var(--bg)}table.sites tr.ssite td.sname{padding-left:24px}table.sites tr.ssite.top td.sname{padding-left:8px}
table.sites tr.sother td{color:var(--m)}table.sites .up{color:var(--crawlers)}table.sites .down{color:var(--refused)}table.sites td.scurve{width:120px;min-width:120px;text-align:left}table.sites td.scurve .spark{height:22px;margin:0}
.wrap{overflow-x:auto}
.hbar .people{background:var(--people)}.hbar .crawlers{background:var(--crawlers)}.hbar .bots{background:var(--bots)}

.tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin:4px 0 10px}.tab{padding:8px 14px;text-decoration:none;color:var(--m);border-bottom:3px solid transparent;font-weight:600}.tab.on{color:var(--fg);border-color:var(--a)}
.filter{margin:0 0 10px;font-size:14px;color:var(--m);display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center}.filter label{display:flex;gap:8px;align-items:center;flex:1 1 320px}
.filter input[type=text]{font:inherit;padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);flex:1;min-width:0}
.filter button{font:inherit;padding:4px 12px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#fff;cursor:pointer}.subtree{background:var(--bg);border-radius:8px;padding:8px 10px}.sub2{margin-top:16px}
.cnum{font-weight:700;white-space:nowrap}.cnum .note{font-weight:400;font-size:12px}
@media (max-width:640px){.crow{grid-template-columns:1fr auto}.crow .hbar{grid-column:1/3;order:3}}
.list{width:100%;border-collapse:collapse}.list td{padding:5px 6px;border-bottom:1px solid var(--line);vertical-align:top}.list .num{text-align:right;white-space:nowrap;font-weight:600}
.speed th{font-size:12px;color:var(--m);font-weight:600;text-align:left;padding:4px 6px}.speed th.num{text-align:right}.speed tr.strong td{font-weight:700}.speedcurves{display:grid;grid-template-columns:12px 1fr;gap:4px 8px;align-items:center;max-width:420px}.slow code{font-size:12px;word-break:break-all}
.badge{display:inline-block;font-size:12px;padding:0 7px;border-radius:999px;border:1px solid var(--line);margin:2px 4px 0 0}.badge.s2{color:var(--s2)}.badge.s3{color:var(--s3)}.badge.s4{color:var(--s4)}.badge.s5{color:var(--s5)}
.barcell{width:30%}.barcell i{display:block;height:8px;border-radius:4px;background:var(--a);margin-top:7px}code{font-size:13px;word-break:break-all}
CSS;

    /**
     * The route (0031 B.6): what the counters say (as JSON: the API's /stats/report). The
     * route's key names the view (sites, all, site, shield).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $ctx
     */
    public static function serve(Settings $s, \CjwNetwork\RequestShield\Request $request, array $route, array $ctx): \CjwNetwork\RequestShield\Response
    {
        /** @var array<mixed> $get */
        $get = is_array($ctx['get'] ?? null) ? $ctx['get'] : [];
        $who = is_string($ctx['who'] ?? null) ? $ctx['who'] : '*';
        $view = is_string($route['key'] ?? null) ? $route['key'] : 'site';
        $askedSite = isset($get['site']) && $get['site'] !== '' && is_string($get['site']) ? $get['site'] : null;
        $days = max(1, min(400, is_numeric($get['days'] ?? null) ? (int) $get['days'] : 7));
        $by = in_array($get['by'] ?? '', ['hour', 'day', 'week', 'month', 'year'], true) ? (string) $get['by'] : null;
        $only = isset($get['crawler']) && is_string($get['crawler']) && isset($s->crawlers[$get['crawler']]) ? $get['crawler'] : null;
        $path = isset($get['path']) && is_string($get['path']) && $get['path'] !== '' ? $get['path'] : null;
        $from = is_string($get['from'] ?? null) ? $get['from'] : '';
        $to = is_string($get['to'] ?? null) ? $get['to'] : '';
        /** @var array<string, string> $links */
        $links = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        // The report as JSON: the API's /stats/report, where the API is (0031 G.0) -- the page links it.
        $api = $s->ext['api'] ?? null;
        $apiBase = is_array($api) && ($api['enabled'] ?? false) === true && is_string($api['base'] ?? null) && isset($s->routes[$api['base'] . '/stats/report'])
            ? (is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . $api['base'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, self::render($s, ['api' => $apiBase, 'view' => $view, 'who' => $who, 'links' => $links, 'days' => $days, 'crawler' => $only, 'path' => $path,
            'sort' => is_string($get['sort'] ?? null) ? $get['sort'] : '', 'lang' => is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto', 'accept' => is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null,
            'fragment' => isset($get['fragment']), 'check' => $get, 'ip' => is_string($ctx['ip'] ?? null) ? $ctx['ip'] : '', 'from' => $from, 'to' => $to,
            'home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '', 'site' => $askedSite] + ($by !== null ? ['by' => $by] : [])));
    }
}
}
}

// plugins/stats/src/Report/StatsReport.php
namespace CjwNetwork\RequestShield\Stats\Report {
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Stats\StatsPlugin;

if (true) {
/**
 * The counters (Stats) of the last days, summed up for people and for code:
 * what the shield let through, checked and refused, which rules decided most,
 * what each known crawler did -- and a few sentences that answer the usual
 * question ("did GPTBot crawl the site, or was it refused?"). The data of
 * the dashboard (0012) and of "bin/request-shield stats"; json_encode() it
 * for a CMS.
 */
final class StatsReport
{
    /** Views a page needs in the period to be on the slowest pages (0046 step 2): one odd request is no slow page. */
    public const PAGE_VIEWS = 10;

    /**
     * The last $days days, or a period: $o['from'] and $o['to'] (yyyymmdd),
     * grouped by $o['by'] (day, week, month, year) into 'periods', and
     * $o['crawler'] to look at one crawler only. Days older than the stats
     * keep them are only in their month's total: counted under the month.
     *
     * @param array{from?: string, to?: string, by?: string, crawler?: string, lang?: string, path?: string, sort?: string, site?: string|null} $o  lang: the sentences' language, en or de;
     *   site: with stats-hosts, one website's numbers (a name, or Stats::OTHER), else all added up;
     *   path: only pages below it (a subtree: /news/), and how many views it had; sort: views (the default),
     *   blocked (refused + checked + told to wait), refused, checked or throttled -- the pages and sections with most of it
     * @return array{from: string, to: string, days: int, by: string, periods: array<string, array<string, int>>, totals: array<string, int>, monitor: array<string, int>,
     *   daily: array<string, array<string, int>>, hourly: array<string, array<string, int>>, rules: array<string, int>,
     *   crawlers: array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>, bots: array<string, int>, statuses: array<string, int>,
     *   notFound: array<string, array{count: int, referrers: array<string, int>}>,
     *   sitemaps: array<string, array{statuses: array<string, int>, crawlers: array<string, array{count: int, last: ?int}>}>,
     *   pages: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>, folders: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>,
     *   subtree: array{path: string, people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int, exact: bool}|null, sort: string,
     *   stopped: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>, sentences: list<string>,
     *   forms: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>, backend: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>,
     *   times: array{kinds: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, site: array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int},
     *     who: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, shield: ?int, reasons: array<string, int>, hitShare: ?float, saved: int,
     *     hourly: array<string, array{count: int, p50: ?int, p95: ?int}>, slow: list<string>, pages: array<string, array{count: int, avg: ?int, p50: ?int, p95: ?int, hits: int}>}|null}  times (0046, in microseconds): null when nothing was timed
     */
    public static function build(Settings $s, ?Stats $stats = null, int $days = 7, ?int $now = null, array $o = []): array
    {
        $now ??= time();
        $days = max(1, $days);
        $to = $o['to'] ?? gmdate('Ymd', $now);
        $from = $o['from'] ?? gmdate('Ymd', (int) strtotime($to . ' UTC') - ($days - 1) * 86400);
        $days = (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) + 1;
        $by = in_array($o['by'] ?? 'day', ['day', 'week', 'month', 'year'], true) ? ($o['by'] ?? 'day') : 'day';
        $only = $o['crawler'] ?? null;
        $read = self::read($s, $stats, $from, $to, $o['site'] ?? null);
        $sets = self::grouped($read, $by);
        $periods = [];
        foreach ($sets as [$label, $counts]) {
            $b = self::buckets($counts);
            if ($only !== null) {
                foreach (['seen', 'verified', 'claimed', 'allowed', 'checked', 'refused', 'throttled'] as $e) {
                    $b[$e] = $counts["c:$only:$e"] ?? 0;
                }
            }
            $periods[$label] = Stats::add($periods[$label] ?? [], $b);
        }
        ksort($periods);

        $totals = [];
        $monitor = [];
        $rules = [];
        $bots = [];
        $crawlers = [];
        $pages = [];
        $daily = [];
        $statuses = [];
        $missing = [];
        $referrers = [];
        /** @var array<string, array{statuses?: array<string, int>, crawlers?: array<string, array{count?: int}>}> $sitemaps */
        $sitemaps = [];
        $zero = ['people' => 0, 'crawlers' => 0, 'bots' => 0, 'total' => 0, 'refused' => 0, 'checked' => 0, 'throttled' => 0, 'blocked' => 0];
        /** @var array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $views */
        $views = [];
        /** @var array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $folders */
        $folders = [];
        /** @var array<string, array{refused: int, checked: int, throttled: int}> $stopped */
        $stopped = [];
        /** @var array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $forms */
        $forms = [];
        /** @var array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $backend */
        $backend = [];
        $form0 = ['sent' => 0, 'saved' => 0, 'error' => 0, 'refused' => 0, 'checked' => 0, 'throttled' => 0, 'cross-site' => 0, 'from' => []];
        /** @var array<string, array<string, array{count: int, sum: int, bands: list<int>}>> $timed who => cache => counts (0046) */
        $timed = [];
        $shieldUs = 0;
        /** @var array<string, int> $reasons */
        $reasons = [];
        /** @var array<string, array{count: int, sum: int, bands: list<int>, hits: int}> $pageTimes path => its times, all visitors (0046 step 2) */
        $pageTimes = [];
        foreach ($read['days'] as $day => $counts) {
            $daily[(string) $day] = self::buckets($counts);
        }
        foreach ($sets as [, $counts]) {
            foreach ($counts as $k => $n) {
                [$type, $rest] = explode(':', (string) $k, 2) + ['', ''];
                switch ($type) {
                    case 'a':
                        $totals[$rest] = ($totals[$rest] ?? 0) + $n;
                        break;
                    case 'm':
                        $monitor[$rest] = ($monitor[$rest] ?? 0) + $n;
                        break;
                    case 'r':
                        $rules[$rest] = ($rules[$rest] ?? 0) + $n;
                        break;
                    case 'o':
                        $bots[$rest] = ($bots[$rest] ?? 0) + $n;
                        break;
                    case 's':
                        $statuses[$rest] = ($statuses[$rest] ?? 0) + $n;
                        break;
                    case 'n':
                        $missing[$rest] = ($missing[$rest] ?? 0) + $n;
                        break;
                    case 'nr':
                        [$path, $source] = explode('|', $rest, 2) + ['', ''];
                        $referrers[$path][$source] = ($referrers[$path][$source] ?? 0) + $n;
                        break;
                    case 'pg':
                    case 'pd':
                        [$who, $path] = explode('|', $rest, 2) + ['', ''];
                        if (in_array($who, ['people', 'crawlers', 'bots'], true)) {
                            if ($type === 'pg') {
                                $views[$path] ??= $zero;
                                $views[$path][$who] += $n;
                                $views[$path]['total'] += $n;
                            } else {
                                $folders[$path] ??= $zero;
                                $folders[$path][$who] += $n;
                                $folders[$path]['total'] += $n;
                            }
                        }
                        break;
                    case 'pb':
                        // A page the shield stopped: refused, checked or told to wait.
                        [$how, $path] = explode('|', $rest, 2) + ['', ''];
                        if (in_array($how, ['refused', 'checked', 'throttled'], true)) {
                            $stopped[$path] ??= ['refused' => 0, 'checked' => 0, 'throttled' => 0];
                            $stopped[$path][$how] += $n;
                        }
                        break;
                    case 'f':
                    case 'fb':
                        // A form sent (fb: in the editors' area, by area).
                        $at = $type === 'fb' ? rawurldecode($rest) : $rest;
                        if ($type === 'fb') {
                            $backend[$at] ??= $form0;
                            $backend[$at]['sent'] += $n;
                        } else {
                            $forms[$at] ??= $form0;
                            $forms[$at]['sent'] += $n;
                        }
                        break;
                    case 'fo':
                    case 'fbo':
                        $bar = (int) strrpos($rest, '|');
                        $at = (string) substr($rest, 0, $bar);
                        $how = (string) substr($rest, $bar + 1);
                        if (isset($form0[$how]) && $how !== 'sent' && $how !== 'from') {
                            if ($type === 'fbo') {
                                $at = rawurldecode($at);
                                $backend[$at] ??= $form0;
                                $backend[$at][$how] += $n;
                            } else {
                                $forms[$at] ??= $form0;
                                $forms[$at][$how] += $n;
                            }
                        }
                        break;
                    case 'ff':
                        $bar = (int) strrpos($rest, '|');
                        $at = (string) substr($rest, 0, $bar);
                        $forms[$at] ??= $form0;
                        $forms[$at]['from'][substr($rest, $bar + 1)] = ($forms[$at]['from'][substr($rest, $bar + 1)] ?? 0) + $n;
                        break;
                    case 'sm':
                        [$path, $code] = explode('|', $rest, 2) + ['', ''];
                        $sitemaps[$path]['statuses'][$code] = ($sitemaps[$path]['statuses'][$code] ?? 0) + $n;
                        break;
                    case 'smc':
                        [$path, $id] = explode('|', $rest, 2) + ['', ''];
                        $sitemaps[$path]['crawlers'][$id]['count'] = ($sitemaps[$path]['crawlers'][$id]['count'] ?? 0) + $n;
                        break;
                    case 'c':
                        $colon = strrpos($rest, ':');
                        $id = (string) substr($rest, 0, (int) $colon);
                        $crawlers[$id][substr($rest, (int) $colon + 1)] = ($crawlers[$id][substr($rest, (int) $colon + 1)] ?? 0) + $n;
                        break;
                    case 'rt':
                    case 'rs':
                        // How long the site took (0046): a band, or the sum of the microseconds.
                        $parts = explode('|', $rest);
                        if (count($parts) === ($type === 'rt' ? 3 : 2)) {
                            $timed[$parts[0]][$parts[1]] ??= ['count' => 0, 'sum' => 0, 'bands' => array_fill(0, count(Stats::BANDS) + 1, 0)];
                            if ($type === 'rs') {
                                $timed[$parts[0]][$parts[1]]['sum'] += $n;
                            } elseif (isset($timed[$parts[0]][$parts[1]]['bands'][(int) $parts[2]])) {
                                $timed[$parts[0]][$parts[1]]['bands'][(int) $parts[2]] += $n;
                                $timed[$parts[0]][$parts[1]]['count'] += $n;
                            }
                        }
                        break;
                    case 'rq':
                        $shieldUs += $n;
                        break;
                    case 'rn':
                        $reasons[$rest] = ($reasons[$rest] ?? 0) + $n;
                        break;
                    case 'pt':
                    case 'ps':
                    case 'pc':
                        // A page view's time: its band, its sum, a hit -- added up over who came.
                        $parts = explode('|', $rest, 2);
                        if (count($parts) === 2) {
                            $page = $parts[1];
                            $band = null;
                            if ($type === 'pt') {
                                $bar = (int) strrpos($page, '|');
                                [$page, $band] = [(string) substr($page, 0, $bar), (int) substr($page, $bar + 1)];
                            }
                            $pageTimes[$page] ??= ['count' => 0, 'sum' => 0, 'bands' => array_fill(0, count(Stats::BANDS) + 1, 0), 'hits' => 0];
                            if ($type === 'ps') {
                                $pageTimes[$page]['sum'] += $n;
                            } elseif ($type === 'pc') {
                                $pageTimes[$page]['hits'] += $n;
                            } elseif (isset($pageTimes[$page]['bands'][(int) $band])) {
                                $pageTimes[$page]['bands'][(int) $band] += $n;
                                $pageTimes[$page]['count'] += $n;
                            }
                        }
                        break;
                    case 'p':
                        $colon = (int) strpos($rest, ':');
                        $id = (string) substr($rest, 0, $colon);
                        $pages[$id][substr($rest, $colon + 1)] = ($pages[$id][substr($rest, $colon + 1)] ?? 0) + $n;
                        break;
                }
            }
        }
        $hourly = [];
        foreach ($read['hours'] as $hour => $counts) {
            if ((string) $hour >= gmdate('YmdH', $now - 47 * 3600)) {
                $hourly[(string) $hour] = self::buckets($counts);
            }
        }
        arsort($rules);
        arsort($bots);
        ksort($statuses);
        arsort($missing);
        // The most visited pages and sections: by all, the people's number first when equal.
        unset($views['(other)'], $folders['(other)'], $stopped['(other)']);
        // What the shield stopped, on its pages -- and on its sections (their
        // first two folders, from the pages: blocked pages are counted one by one).
        foreach ($stopped as $path => $x) {
            $path = (string) $path;
            $views[$path] = self::stop($views[$path] ?? $zero, $x);
            foreach (StatsPlugin::folders($path, StatsExtension::of($s)['depth']) as $at) {
                $folders[$at] = self::stop($folders[$at] ?? $zero, $x);
            }
        }
        // By views (the people's number first when equal), or by what the shield stopped.
        $sort = in_array($o['sort'] ?? 'views', ['views', 'blocked', 'refused', 'checked', 'throttled'], true) ? ($o['sort'] ?? 'views') : 'views';
        $key = $sort === 'views' ? 'total' : $sort;
        $order = $sort === 'views' ? static fn (array $a, array $b): int => [$b['total'], $b['people']] <=> [$a['total'], $a['people']]
            : static fn (array $a, array $b): int => [$b[$key], $b['blocked'], $b['total']] <=> [$a[$key], $a['blocked'], $a['total']];
        // A subtree: its pages, and its views -- exact where it is a counted folder
        // (the first two levels), else the sum of its pages on the lists.
        $subtree = null;
        $prefix = $o['path'] ?? null;
        if ($prefix !== null && $prefix !== '') {
            $views = array_filter($views, static fn (string $p): bool => strncmp($p, $prefix, strlen($prefix)) === 0, ARRAY_FILTER_USE_KEY);
            $sum = $zero;
            // Views exact where the folder is counted; what was stopped always from the pages.
            $exact = isset($folders[$prefix]) && $folders[$prefix]['total'] > 0;
            foreach ($exact ? [$folders[$prefix]] : $views as $v) {
                foreach (['people', 'crawlers', 'bots', 'total'] as $k) {
                    $sum[$k] += $v[$k];
                }
            }
            foreach ($views as $v) {
                foreach (['refused', 'checked', 'throttled', 'blocked'] as $k) {
                    $sum[$k] += $v[$k];
                }
            }
            $subtree = ['path' => $prefix] + $sum + ['exact' => $exact];
            $folders = array_filter($folders, static fn (string $p): bool => strncmp($p, $prefix, strlen($prefix)) === 0 && $p !== $prefix, ARRAY_FILTER_USE_KEY);
        }
        // The pages the shield stopped most, whatever the list is sorted by (the visitors page's tab).
        $stoppedTop = array_filter($views, static fn (array $v): bool => $v['blocked'] > 0);
        uasort($stoppedTop, static fn (array $a, array $b): int => [$b['blocked'], $b['total']] <=> [$a['blocked'], $a['total']]);
        $stoppedTop = array_slice($stoppedTop, 0, 20, true);
        // Only what has the number sorted by: a page nobody stopped is not on the blocked list.
        $views = array_filter($views, static fn (array $v): bool => $v[$key] > 0);
        $folders = array_filter($folders, static fn (array $v): bool => $v[$key] > 0);
        uasort($views, $order);
        uasort($folders, $order);
        $views = array_slice($views, 0, 20, true);
        $folders = array_slice($folders, 0, 20, true);
        // Sitemaps: the answers, and which crawler read each when last.
        $maps = [];
        foreach ($sitemaps as $path => $x) {
            $readers = [];
            foreach ($x['crawlers'] ?? [] as $id => $c) {
                $readers[(string) $id] = ['count' => (int) ($c['count'] ?? 0), 'last' => $read['last']['sitemap:' . $path . '@' . $id][0] ?? null];
            }
            uasort($readers, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $answers = $x['statuses'] ?? [];
            ksort($answers);
            $maps[(string) $path] = ['statuses' => array_combine(array_map('strval', array_keys($answers)), array_values($answers)), 'crawlers' => $readers];
        }
        ksort($maps);
        // Forms: the most sent first; each with where it was sent from, the most first.
        unset($forms['(other)']);
        $forms = self::byForm(array_filter($forms, static fn (array $f): bool => $f['sent'] > 0));
        $backend = self::byForm($backend);
        $notFound = [];
        foreach (array_slice($missing, 0, 20, true) as $path => $n) {
            $sources = $referrers[(string) $path] ?? [];
            arsort($sources);
            $notFound[(string) $path] = ['count' => $n, 'referrers' => array_slice($sources, 0, Stats::REFERRERS, true)];
        }

        $out = [];
        foreach ($s->crawlers as $id => $x) {
            if ($only !== null && $id !== $only) {
                continue;
            }
            $c = $crawlers[$id] ?? [];
            $top = $pages[$id] ?? [];
            arsort($top);
            $out[$id] = ['kind' => $x['kind'], 'policy' => $x['policy'], 'name' => $s->origin('text', $id) ?? self::names()[$id] ?? $id,
                'seen' => $c['seen'] ?? 0, 'verified' => $c['verified'] ?? 0, 'claimed' => $c['claimed'] ?? 0, 'allowed' => $c['allowed'] ?? 0,
                'checked' => $c['checked'] ?? 0, 'refused' => $c['refused'] ?? 0, 'throttled' => $c['throttled'] ?? 0, 'robots' => $c['robots'] ?? 0,
                'pages' => array_slice($top, 0, 10, true), 'last' => $read['last'][$id] ?? null];
        }
        $times = $timed === [] ? null : self::times($timed, $shieldUs, $reasons, $read['hours'], $now, self::slowLines($s, $stats, $from, $to, $o['site'] ?? null), $pageTimes);
        return ['times' => $times, 'from' => $from, 'to' => $to, 'days' => $days, 'by' => $by, 'periods' => $periods, 'totals' => $totals, 'monitor' => $monitor, 'daily' => $daily, 'hourly' => $hourly,
            'rules' => $rules, 'crawlers' => $out, 'bots' => $bots, 'statuses' => $statuses, 'notFound' => $notFound, 'sitemaps' => $maps, 'pages' => $views, 'folders' => $folders, 'subtree' => $subtree, 'sort' => $sort, 'stopped' => $stoppedTop, 'forms' => $forms, 'backend' => $backend,
            'sentences' => array_merge(self::sentences($out, $days, $o['lang'] ?? 'en'), self::maps($maps, $days, $o['lang'] ?? 'en'), self::missing($notFound, $days, $o['lang'] ?? 'en'))];
    }

    /**
     * How long the site took (0046), from the counters: per cache kind and
     * kind of visitor the bands, the sum, the average and the median and the
     * slow end read from the bands; the site's own (what did not come from the
     * cache) per hour of the last two days; the share of hits and what they
     * saved; the shield's share; why misses were not kept; the slow log.
     *
     * @param array<string, array<string, array{count: int, sum: int, bands: list<int>}>> $timed
     * @param array<string, int> $reasons
     * @param array<string, array<string, int>> $hours
     * @param list<string> $slow
     * @param array<string, array{count: int, sum: int, bands: list<int>, hits: int}> $pageTimes
     * @return array{kinds: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, site: array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int},
     *   who: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, shield: ?int, reasons: array<string, int>, hitShare: ?float, saved: int,
     *   hourly: array<string, array{count: int, p50: ?int, p95: ?int}>, slow: list<string>, pages: array<string, array{count: int, avg: ?int, p50: ?int, p95: ?int, hits: int}>}
     */
    private static function times(array $timed, int $shieldUs, array $reasons, array $hours, int $now, array $slow, array $pageTimes = []): array
    {
        $zero = ['count' => 0, 'sum' => 0, 'bands' => array_fill(0, count(Stats::BANDS) + 1, 0)];
        $kinds = ['hit' => $zero, 'miss' => $zero, 'nostore' => $zero, 'past' => $zero];
        $who = [];
        $site = $zero;
        foreach ($timed as $w => $byCache) {
            foreach ($byCache as $cache => $t) {
                if (!isset($kinds[$cache])) {
                    continue;
                }
                $kinds[$cache] = self::addTimes($kinds[$cache], $t);
                if ($cache !== 'hit') {
                    $site = self::addTimes($site, $t);
                    $who[(string) $w] = self::addTimes($who[(string) $w] ?? $zero, $t);
                }
            }
        }
        $all = $site['count'] + $kinds['hit']['count'];
        $hits = $kinds['hit']['count'];
        $asked = $hits + $kinds['miss']['count'];
        // What the hits saved: each would have taken as long as a miss usually does -- the misses' median (or,
        // without misses, the site's): one slow page does not make every hit look like a great saving.
        $instead = self::percentile($kinds['miss']['bands'], 0.5) ?? self::percentile($site['bands'], 0.5) ?? 0;
        $saved = $hits > 0 ? max(0, (int) round($hits * $instead - $kinds['hit']['sum'])) : 0;
        // The period's last 48 hours (the hours kept and read for it): up to its end, not to now.
        $hourly = [];
        $latest = $hours === [] ? '1970010100' : (string) max(array_map('strval', array_keys($hours)));
        $lastHour = (int) strtotime(substr($latest, 0, 8) . ' UTC') + 3600 * (int) substr($latest, 8, 2);
        foreach ($hours as $hour => $counts) {
            if ((string) $hour < gmdate('YmdH', $lastHour - 47 * 3600)) {
                continue;
            }
            $h = $zero;
            foreach ($counts as $k => $n) {
                $k = (string) $k;
                if (strncmp($k, 'rt:', 3) === 0) {
                    $p = explode('|', (string) substr($k, 3));
                    if (count($p) === 3 && $p[1] !== 'hit' && isset($h['bands'][(int) $p[2]])) {
                        $h['bands'][(int) $p[2]] += $n;
                        $h['count'] += $n;
                    }
                }
            }
            if ($h['count'] > 0) {
                $hourly[(string) $hour] = ['count' => $h['count'], 'p50' => self::percentile($h['bands'], 0.5), 'p95' => self::percentile($h['bands'], 0.95)];
            }
        }
        arsort($reasons);
        // The slowest pages (step 2): those viewed often enough that one odd request is no "slow page",
        // by their median; each with its slow end, its average and how many came from the cache.
        $pages = [];
        foreach ($pageTimes as $path => $x) {
            if ($x['count'] >= self::PAGE_VIEWS && (string) $path !== '(other)') {
                $pages[(string) $path] = ['count' => $x['count'], 'avg' => (int) round($x['sum'] / $x['count']), 'p50' => self::percentile($x['bands'], 0.5),
                    'p95' => self::percentile($x['bands'], 0.95), 'hits' => $x['hits']];
            }
        }
        uasort($pages, static fn (array $a, array $b): int => [$b['p50'], $b['p95']] <=> [$a['p50'], $a['p95']]);
        return ['pages' => array_slice($pages, 0, 20, true), 'kinds' => array_map([self::class, 'summed'], $kinds), 'site' => self::summed($site), 'who' => array_map([self::class, 'summed'], $who), 'shield' => $all > 0 ? (int) round($shieldUs / $all) : null,
            'reasons' => $reasons, 'hitShare' => $asked > 0 ? $hits / $asked : null, 'saved' => $saved, 'hourly' => $hourly, 'slow' => $slow];
    }

    /**
     * Two counts of times added up.
     *
     * @param array{count: int, sum: int, bands: list<int>} $a
     * @param array{count: int, sum: int, bands: list<int>} $b
     * @return array{count: int, sum: int, bands: list<int>}
     */
    private static function addTimes(array $a, array $b): array
    {
        $a['count'] += $b['count'];
        $a['sum'] += $b['sum'];
        foreach ($b['bands'] as $i => $n) {
            if (isset($a['bands'][$i])) {
                $a['bands'][$i] += $n;
            }
        }
        return $a;
    }

    /**
     * A count of times with its average, median and slow end.
     *
     * @param array{count: int, sum: int, bands: list<int>} $t
     * @return array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}
     */
    private static function summed(array $t): array
    {
        return $t + ['avg' => $t['count'] > 0 ? (int) round($t['sum'] / $t['count']) : null, 'p50' => self::percentile($t['bands'], 0.5), 'p95' => self::percentile($t['bands'], 0.95)];
    }

    /**
     * A percentile read from the bands (0046), in microseconds: the band it
     * falls in, and within it by its share -- exact to the band's width; over
     * 10 s it says 10 s. Null without a count.
     *
     * @param list<int> $bands
     */
    public static function percentile(array $bands, float $q): ?int
    {
        $total = array_sum($bands);
        if ($total <= 0) {
            return null;
        }
        $target = $q * $total;
        $below = 0;
        foreach ($bands as $i => $n) {
            if ($n > 0 && $below + $n >= $target) {
                $low = $i === 0 ? 0 : Stats::BANDS[$i - 1];
                if (!isset(Stats::BANDS[$i])) {
                    return $low;            // over the last band's end: "over 10 s"
                }
                return (int) round($low + (Stats::BANDS[$i] - $low) * ($target - $below) / $n);
            }
            $below += $n;
        }
        return Stats::BANDS[count(Stats::BANDS) - 1];
    }

    /**
     * The slow log's last lines of a period (0046): the statistics given, or
     * with stats-hosts one website's or all websites' -- the newest last.
     *
     * @return list<string>
     */
    private static function slowLines(Settings $s, ?Stats $stats, string $from, string $to, ?string $site): array
    {
        $lines = [];
        foreach ($stats !== null ? [$stats] : Stats::all($s, $site !== null && Stats::known($s, $site) ? $site : null) as $one) {
            foreach ($one->slowLines($from, $to, 30) as $line) {
                $lines[] = $line;
            }
        }
        sort($lines);           // each starts with its time
        return array_slice($lines, -30);
    }

    /**
     * Forms, the most sent first (the most stopped when equal), the first 20;
     * each with how many were stopped, and its sources, the most first.
     *
     * @param array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $list
     * @return array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>
     */
    private static function byForm(array $list): array
    {
        $out = [];
        foreach ($list as $k => $f) {
            $from = $f['from'];
            arsort($from);
            $out[$k] = ['from' => array_slice($from, 0, Stats::REFERRERS + 1, true), 'stopped' => $f['refused'] + $f['checked'] + $f['throttled'] + $f['cross-site']] + $f;
        }
        uasort($out, static fn (array $a, array $b): int => [$b['sent'], $b['stopped']] <=> [$a['sent'], $a['stopped']]);
        return array_slice($out, 0, 20, true);
    }

    /**
     * The overview of all websites (stats-hosts, stats-group): for each
     * website, each group (its websites added up), "other hosts" and all --
     * page views, people, crawlers, bots, what was stopped, pages not found,
     * the page views of the period before, and the page views per period
     * for a small curve.
     *
     * @return array{sites: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>,
     *   groups: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>,
     *   all: array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}|null} all: null for a customer
     */
    public static function sites(Settings $s, string $from, string $to, string $by = 'day', string $who = '*'): array
    {
        $len = (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) + 1;
        $pTo = gmdate('Ymd', (int) strtotime($from . ' UTC') - 86400);
        $pFrom = gmdate('Ymd', (int) strtotime($from . ' UTC') - $len * 86400);
        $kinds = [];
        foreach ($s->crawlers as $id => $c) {
            $kinds[(string) $id] = $c['kind'];
        }
        $row = static function (?string $site) use ($s, $from, $to, $pFrom, $pTo, $by, $kinds): array {
            $r = ['views' => 0, 'people' => 0, 'crawlers' => 0, 'search' => 0, 'ai' => 0, 'bots' => 0, 'stopped' => 0, 'notFound' => 0, 'prev' => 0, 'curve' => []];
            $periods = [];
            foreach (self::grouped(self::read($s, null, $from, $to, $site), $by) as [$label, $counts]) {
                $b = self::buckets($counts);
                foreach (['views', 'people', 'crawlers', 'bots', 'notFound'] as $k) {
                    $r[$k] += $b[$k] ?? 0;
                }
                $r['stopped'] += ($b['refused'] ?? 0) + ($b['checked'] ?? 0) + ($b['throttled'] ?? 0);
                $periods[(string) $label] = ($periods[(string) $label] ?? 0) + ($b['views'] ?? 0);
                // Crawlers by kind: search engines apart from the AI crawlers (search, assistants, training).
                foreach ($counts as $k => $n) {
                    $k = (string) $k;
                    if (strncmp($k, 'c:', 2) === 0 && substr($k, -5) === ':seen') {
                        $kind = $kinds[substr($k, 2, -5)] ?? 'search';
                        $r[$kind === 'search' ? 'search' : 'ai'] += $n;
                    }
                }
            }
            ksort($periods);
            $r['curve'] = $periods;
            foreach (self::periods($s, null, $pFrom, $pTo, $by, $site) as $b) {
                $r['prev'] += $b['views'] ?? 0;
            }
            return $r;
        };
        // A customer (who: a group's ID): its group and its websites, nothing else.
        $own = $who === '*' ? null : StatsExtension::of($s)['groups'][$who] ?? ['sites' => []];
        $sites = [];
        foreach ($own === null ? array_merge(StatsExtension::of($s)['hosts'], [Stats::OTHER]) : $own['sites'] as $name) {
            $sites[$name] = $row($name);
        }
        $groups = [];
        foreach ($own === null ? StatsExtension::of($s)['groups'] : array_intersect_key(StatsExtension::of($s)['groups'], [$who => 1]) as $id => $g) {
            $sum = ['views' => 0, 'people' => 0, 'crawlers' => 0, 'search' => 0, 'ai' => 0, 'bots' => 0, 'stopped' => 0, 'notFound' => 0, 'prev' => 0, 'curve' => []];
            foreach ($g['sites'] as $name) {
                foreach ($sites[$name] ?? [] as $k => $v) {
                    if ($k === 'curve') {
                        foreach ((array) $v as $label => $n) {
                            $sum['curve'][(string) $label] = ($sum['curve'][(string) $label] ?? 0) + (int) $n;
                        }
                    } else {
                        $sum[$k] += (int) $v;
                    }
                }
            }
            ksort($sum['curve']);
            $groups[(string) $id] = $sum;
        }
        return ['sites' => $sites, 'groups' => $groups, 'all' => $own === null ? $row(null) : null];
    }

    /**
     * What was counted: the statistics given, or with stats-hosts one
     * website's ($site) or every website's added up (null).
     *
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public static function read(Settings $s, ?Stats $stats, string $from, string $to, ?string $site = null): array
    {
        if ($stats !== null) {
            return $stats->read($from, $to);
        }
        $all = Stats::all($s, $site !== null && Stats::known($s, $site) ? $site : null);
        return count($all) === 1 ? $all[0]->read($from, $to) : Stats::readAll($all, $from, $to);
    }

    /**
     * Only the numbers per period of a span ("the period before" for a
     * comparison): the buckets of each day, week, month or year, nothing else.
     *
     * @return array<string, array<string, int>>
     */
    public static function periods(Settings $s, ?Stats $stats, string $from, string $to, string $by = 'day', ?string $site = null): array
    {
        $periods = [];
        foreach (self::grouped(self::read($s, $stats, $from, $to, $site), $by) as [$label, $counts]) {
            $periods[$label] = Stats::add($periods[$label] ?? [], self::buckets($counts));
        }
        ksort($periods);
        return $periods;
    }

    /**
     * Days and, where the days are gone, their months: each with the period
     * (day, week, month, year) it belongs to.
     *
     * @param array{days: array<string, array<string, int>>, months: array<string, array<string, int>>} $read
     * @return list<array{0: string, 1: array<string, int>}>
     */
    private static function grouped(array $read, string $by): array
    {
        $sets = [];
        foreach ($read['days'] as $day => $counts) {
            $t = (int) strtotime($day . ' UTC');
            $sets[] = [['day' => gmdate('Y-m-d', $t), 'week' => gmdate('o-\\WW', $t), 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by] ?? gmdate('Y-m-d', $t), $counts];
        }
        foreach ($read['months'] as $month => $counts) {
            $t = (int) strtotime($month . '01 UTC');
            $sets[] = [['day' => gmdate('Y-m', $t) . ' (month)', 'week' => gmdate('Y-m', $t) . ' (month)', 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by] ?? gmdate('Y-m', $t), $counts];
        }
        return $sets;
    }

    /**
     * A page's or section's numbers, plus what the shield stopped there.
     *
     * @param array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int} $v
     * @param array{refused: int, checked: int, throttled: int} $x
     * @return array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}
     */
    private static function stop(array $v, array $x): array
    {
        $v['refused'] += $x['refused'];
        $v['checked'] += $x['checked'];
        $v['throttled'] += $x['throttled'];
        $v['blocked'] += $x['refused'] + $x['checked'] + $x['throttled'];
        return $v;
    }

    /**
     * Sitemaps in words: which crawler read which, and when last; a sitemap
     * asked for that does not exist.
     *
     * @param array<string, array{statuses: array<string, int>, crawlers: array<string, array{count: int, last: ?int}>}> $maps
     * @return list<string>
     */
    private static function maps(array $maps, int $days, string $lang): array
    {
        $de = $lang === 'de';
        $out = [];
        foreach ($maps as $path => $x) {
            $ok = 0;
            $missing = 0;
            foreach ($x['statuses'] as $code => $n) {
                $code = (int) $code;
                $ok += $code >= 200 && $code < 300 ? $n : 0;
                $missing += $code === 404 || $code === 410 ? $n : 0;
            }
            if ($missing > 0 && $ok === 0) {
                $out[] = $de ? "$path wurde " . self::number($missing, $lang) . '× abgefragt, gibt es aber nicht (404).'
                    : "$path was asked for " . self::number($missing, $lang) . '× but does not exist (404).';
            }
            foreach ($x['crawlers'] as $id => $c) {
                $when = $c['last'] !== null ? date($de ? 'd.m. H:i' : 'M j, H:i', $c['last']) : '';
                $out[] = $de ? "$id hat $path " . self::number($c['count'], $lang) . '× gelesen, zuletzt ' . $when . '.'
                    : "$id read $path " . self::number($c['count'], $lang) . '×' . ($when !== '' ? ", last on $when" : '') . '.';
            }
        }
        return $out;
    }

    /**
     * Pages the site did not find, in words -- the ones its own pages link to
     * first: those are broken links to fix.
     *
     * @param array<string, array{count: int, referrers: array<string, int>}> $notFound
     * @return list<string>
     */
    private static function missing(array $notFound, int $days, string $lang = 'en'): array
    {
        if ($notFound === []) {
            return [];
        }
        $de = $lang === 'de';
        $out = [];
        foreach ($notFound as $path => $x) {
            foreach ($x['referrers'] as $from => $n) {
                if (strncmp((string) $from, '/', 1) === 0) {
                    $out[] = $de ? "Kaputter Link: $from verweist auf $path, die nicht gefunden wurde ({$n}×)."
                        : "Broken link: $from links to $path, which was not found ({$n}×).";
                }
            }
        }
        $top = array_slice($notFound, 0, 3, true);
        $list = implode(', ', array_map(static fn (string $p, array $x): string => "$p ({$x['count']}×)", array_map('strval', array_keys($top)), $top));
        $count = count($notFound);
        $out[] = $de ? self::number($count, $lang) . ($count === 1 ? ' Seite wurde ' : ' Seiten wurden ') . self::span($days, $lang) . " nicht gefunden; am häufigsten: $list."
            : $count . ' ' . ($count === 1 ? 'page was' : 'pages were') . ' not found ' . self::span($days, $lang) . "; most asked for: $list.";
        return $out;
    }

    /** A time in microseconds as a person reads it: 40 µs, 0.4 ms, 120 ms, 2.4 s, 3.5 min, 1.2 h (German with a comma). */
    public static function duration(int $us, string $lang = 'en'): string
    {
        $f = static fn (float $v, int $d): string => $lang === 'de' ? number_format($v, $d, ',', '.') : number_format($v, $d);
        if ($us < 100) {
            return $us . ' µs';
        }
        if ($us < 10000) {
            return $f($us / 1000, 1) . ' ms';
        }
        if ($us < 1000000) {
            return $f($us / 1000, 0) . ' ms';
        }
        if ($us < 60000000) {
            return $f($us / 1000000, 1) . ' s';
        }
        if ($us < 3600000000) {
            return $f($us / 60000000, 1) . ' min';
        }
        return $f($us / 3600000000, 1) . ' h';
    }

    /** A number as the language writes it: 1,204 or 1.204. */
    public static function number(int $n, string $lang = 'en'): string
    {
        return $lang === 'de' ? number_format($n, 0, ',', '.') : number_format($n);
    }

    private static function span(int $days, string $lang): string
    {
        if ($lang === 'de') {
            return $days === 1 ? 'heute' : "in den letzten $days Tagen";
        }
        return $days === 1 ? 'today' : "in the last $days days";
    }

    /**
     * The shipped crawlers' descriptions (Shipped::crawlers()), for settings
     * that have none of their own.
     *
     * @return array<string, string>
     */
    private static function names(): array
    {
        return \CjwNetwork\RequestShield\Rules\Shipped::crawlers()['names'];
    }

    /**
     * A day's or an hour's counters as numbers: let through (cacheable), let
     * through (not cacheable), checked, told to wait, refused; people,
     * crawlers, bots; pages not found.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function buckets(array $counts): array
    {
        $crawlers = 0;
        $bots = 0;
        $requests = 0;
        $views = 0;
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            if (strncmp($k, 'pg:people|', 10) === 0) {
                $views += $n;               // page views by people (part pages)
            } elseif (strncmp($k, 'a:', 2) === 0) {
                $requests += $n;
            } elseif (strncmp($k, 'o:', 2) === 0) {
                $bots += $n;
            } elseif (strncmp($k, 'c:', 2) === 0 && substr($k, -5) === ':seen') {
                $crawlers += $n;
            }
        }
        return ['passed' => $counts['a:allow'] ?? 0, 'uncached' => $counts['a:allow-uncached'] ?? 0, 'checked' => $counts['a:challenge'] ?? 0,
            'throttled' => $counts['a:throttle'] ?? 0, 'refused' => $counts['a:reject'] ?? 0,
            // Who: people are what is neither a known crawler nor a bot that says so.
            'people' => max(0, $requests - $crawlers - $bots), 'crawlers' => $crawlers, 'bots' => $bots,
            'notFound' => ($counts['s:404'] ?? 0) + ($counts['s:410'] ?? 0), 'views' => $views];
    }

    /**
     * In words: what each crawler did, who only borrowed a name, which AI
     * crawlers did not come.
     *
     * @param array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}> $crawlers
     * @return list<string>
     */
    private static function sentences(array $crawlers, int $days, string $lang = 'en'): array
    {
        $de = $lang === 'de';
        $span = self::span($days, $lang);
        $words = $de ? ['allowed' => 'durchgelassen', 'checked' => 'geprüft', 'throttled' => 'gebremst', 'refused' => 'abgewiesen']
            : ['allowed' => 'let through', 'checked' => 'checked', 'throttled' => 'told to wait', 'refused' => 'refused'];
        $out = [];
        $absent = [];
        foreach ($crawlers as $id => $c) {
            $name = $c['name'] === $id ? $id : $c['name'] . " ($id)";
            $verified = $c['verified'];
            if ($verified > 0) {
                $got = [];
                foreach ($words as $e => $word) {
                    if ($c[$e] > 0) {
                        $got[] = ($c[$e] === $verified ? ($de ? 'jedes Mal ' : 'every time ') : self::number($c[$e], $lang) . '× ') . $word;
                    }
                }
                $out[] = $name . ($de ? ' kam ' : ' came ') . self::number($verified, $lang) . "× $span: " . implode(', ', $got)
                    . ($c['policy'] !== 'allow' ? ($de ? " (so eingestellt: {$c['policy']})" : " (as set: {$c['policy']})") : '')
                    . ($c['robots'] > 0 ? ($de ? '; robots.txt gelesen' : '; it read robots.txt') : '') . '.';
            } elseif ($c['kind'] !== 'search') {
                $absent[] = $id;
            }
            if ($c['claimed'] > 0) {
                $out[] = self::number($c['claimed'], $lang) . ($de
                    ? ($c['claimed'] === 1 ? ' Anfrage gab sich nur als ' : ' Anfragen gaben sich nur als ') . "$name aus — als normale Besucher behandelt."
                    : ($c['claimed'] === 1 ? ' request' : ' requests') . " only pretended to be $name — treated as ordinary visitors.");
            }
        }
        if ($absent !== []) {
            $out[] = ($de ? "Kein bestätigter Besuch $span von: " : "No verified visit $span from: ") . implode(', ', $absent) . '.';
        }
        return $out;
    }
}
}
}

// plugins/stats/src/Report/VisitorsPage.php
namespace CjwNetwork\RequestShield\Stats\Report {
use CjwNetwork\RequestShield\Help;

if (true) {
/**
 * "Visitors & pages" (proposal 0022, phase 1): one calm page -- six numbers,
 * each with its change against the period before, one chart (the number
 * picked), and two cards with tabs: the pages (pages, sections, stopped, not
 * found) and the crawlers & AI assistants that read the site.
 *
 * No script: the number shown in the chart and the cards' tabs are radio
 * buttons and CSS (they work without JavaScript); a point's numbers are its
 * <title>. Everything from a request -- a path, a referrer -- is escaped.
 */
final class VisitorsPage
{
    private const T = [
        'en' => [
            'views' => 'Page views', 'people' => 'Requests by people', 'crawlers' => 'Crawler visits', 'bots' => 'Bot requests', 'stopped' => 'Stopped', 'notFound' => 'Not found',
            'before' => 'before', 'new' => 'new', 'same' => '±0 %', 'dashed' => 'dashed: the period before', 'pages' => 'Pages', 'tPages' => 'Pages', 'tSections' => 'Sections',
            'tStopped' => 'Stopped', 'tMissing' => 'Not found', 'forms' => 'Forms', 'tForms' => 'Visitors\' forms', 'tBackend' => 'Editors (backend)',
            'from' => 'from', 'fromNone' => 'no page given', 'fromOther' => 'another website', 'fromSite' => 'this website (page not said)', 'fSaved' => 'saved', 'fErrors' => 'errors', 'fStopped' => 'stopped',
            'fCross' => 'from another website', 'fSent' => 'sent', 'fLive' => 'stopped: in the live view', 'crawlersAi' => 'Crawlers & AI', 'tSearch' => 'Search', 'tAi' => 'AI assistants', 'tTraining' => 'AI training',
            'none' => 'Nothing in this period: pick a longer one above, or come back after the next hour is counted.', 'lastVisit' => 'last', 'linked' => 'linked from', 'people2' => 'people', 'crawlers2' => 'crawlers', 'bots2' => 'bots',
            'refused' => 'refused', 'checked' => 'checked', 'throttled' => 'told to wait', 'verified' => 'verified visits', 'pathStarts' => 'path starts with', 'filter' => 'Filter',
            'clear' => 'all pages', 'subtree' => 'Subtree', 'viewsOf' => 'views',
        ],
        'de' => [
            'views' => 'Seitenaufrufe', 'people' => 'Anfragen von Menschen', 'crawlers' => 'Crawler-Besuche', 'bots' => 'Bot-Anfragen', 'stopped' => 'Gestoppt', 'notFound' => 'Nicht gefunden',
            'before' => 'vorher', 'new' => 'neu', 'same' => '±0 %', 'dashed' => 'gestrichelt: der Zeitraum davor', 'pages' => 'Seiten', 'tPages' => 'Seiten', 'tSections' => 'Bereiche',
            'tStopped' => 'Gestoppt', 'tMissing' => 'Nicht gefunden', 'forms' => 'Formulare', 'tForms' => 'Formulare der Besucher', 'tBackend' => 'Redaktion (Backend)',
            'from' => 'von', 'fromNone' => 'ohne Angabe', 'fromOther' => 'andere Website', 'fromSite' => 'diese Website (Seite ohne Angabe)', 'fSaved' => 'gespeichert', 'fErrors' => 'Fehler', 'fStopped' => 'gestoppt',
            'fCross' => 'von einer anderen Website', 'fSent' => 'gesendet', 'fLive' => 'gestoppt: in der Live-Ansicht', 'crawlersAi' => 'Crawler & KI', 'tSearch' => 'Suche', 'tAi' => 'KI-Assistenten', 'tTraining' => 'KI-Training',
            'none' => 'Nichts in diesem Zeitraum: oben einen längeren wählen, oder nach der nächsten gezählten Stunde wiederkommen.', 'lastVisit' => 'zuletzt', 'linked' => 'verlinkt von', 'people2' => 'Menschen', 'crawlers2' => 'Crawler', 'bots2' => 'Bots',
            'refused' => 'abgewiesen', 'checked' => 'geprüft', 'throttled' => 'gebremst', 'verified' => 'bestätigte Besuche', 'pathStarts' => 'Pfad beginnt mit', 'filter' => 'Filtern',
            'clear' => 'alle Seiten', 'subtree' => 'Unterbaum', 'viewsOf' => 'Aufrufe',
        ],
    ];

    /** The six numbers, in their order: the chart starts with the first. */
    private const METRICS = ['views', 'people', 'crawlers', 'bots', 'stopped', 'notFound'];

    /**
     * @param array{pages: array<array-key, array{people: int, crawlers: int, bots: int, total: int}>, folders: array<array-key, array{people: int, crawlers: int, bots: int, total: int}>,
     *   stopped: array<array-key, array{refused: int, checked: int, throttled: int, blocked: int}>, notFound: array<array-key, array{count: int, referrers: array<array-key, int>}>,
     *   crawlers: array<array-key, array{kind: string, name: string, verified: int, last: array{0: int, 1: string}|null}>,
     *   subtree: array{path: string, people: int, crawlers: int, bots: int, total: int}|null,
     *   forms?: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>, backend?: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>} $r StatsReport::build()
     * @param array<string, array<string, int>> $cur label => buckets of the period, every label (zeros too), in order
     * @param list<array<string, int>> $prev the period before, by position
     * @param array{lang?: string, docs?: string, by?: string, action?: string, keep?: array<string, string|int>, link?: callable(string): string, clear?: string, path?: ?string, live?: (callable(string): string)|null} $o
     *   by: hour, day, week, month, year (the chart's labels); action, keep: the path filter's form; link: a section's subtree; clear: the address without the filter
     */
    public static function render(array $r, array $cur, array $prev, array $o = []): string
    {
        $lang = isset(self::T[$o['lang'] ?? '']) ? (string) $o['lang'] : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $by = (string) ($o['by'] ?? 'day');
        // The cards' "?" into the docs (0031 F.9): where set docs-url says ("" with the links off).
        $docs = $o['docs'] ?? Help::DOCS;
        $help = static fn (string $anchor, string $id = 'RSF06-03'): string => Help::link($id, $anchor, $docs, $lang);

        // The numbers per point, now and before.
        $series = [];
        $before = [];
        foreach (self::METRICS as $m) {
            $series[$m] = array_values(array_map(static fn (array $b): int => self::value($b, $m), $cur));
            $before[$m] = array_map(static fn (array $b): int => self::value($b, $m), $prev);
        }
        $labels = array_map(static fn ($l): string => self::label((string) $l, $by, $lang), array_keys($cur));

        // Six numbers, each a label for its radio button: picking one shows its chart.
        $h = '<div class="vp">';
        foreach (self::METRICS as $i => $m) {
            $h .= '<input type="radio" name="vp-m" id="vp-m' . $i . '"' . ($i === 0 ? ' checked' : '') . '>';
        }
        $h .= '<div class="vtiles">';
        foreach (self::METRICS as $i => $m) {
            $now = array_sum($series[$m]);
            $was = array_sum($before[$m]);
            $h .= '<label class="vt" for="vp-m' . $i . '"><span class="l">' . $e($t[$m]) . '</span><span class="n">' . $e($n($now)) . '</span>'
                . '<span class="d" title="' . $e($t['before'] . ': ' . $n($was)) . '">' . $e(self::change($now, $was, $t)) . '</span></label>';
        }
        $h .= '</div><div class="vcharts">';
        foreach (self::METRICS as $m) {
            $h .= '<div class="vc"><p class="note">' . $e($t[$m] . ' · ' . $t['dashed']) . '</p>' . self::chart($series[$m], $before[$m], $labels, $t, $lang) . '</div>';
        }
        $h .= '</div></div>';

        // The pages: pages, sections, stopped, not found -- with the subtree filter.
        $pages = [];
        foreach ($r['pages'] as $p => $v) {
            $pages[] = [(string) $p, (int) $v['total'], $n((int) $v['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $v['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $v['bots']) . ' ' . $t['bots2'], null];
        }
        $link = $o['link'] ?? null;
        $sections = [];
        foreach ($r['folders'] as $p => $v) {
            $sections[] = [(string) $p, (int) $v['total'], $n((int) $v['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $v['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $v['bots']) . ' ' . $t['bots2'],
                $link !== null ? $link((string) $p) : null];
        }
        $stopped = [];
        foreach ($r['stopped'] as $p => $v) {
            $stopped[] = [(string) $p, (int) $v['blocked'], $n((int) $v['refused']) . ' ' . $t['refused'] . ' · ' . $n((int) $v['checked']) . ' ' . $t['checked'] . ' · ' . $n((int) $v['throttled']) . ' ' . $t['throttled'], null];
        }
        $missing = [];
        foreach ($r['notFound'] as $p => $x) {
            $from = [];
            foreach ($x['referrers'] as $source => $c) {
                $from[] = $source . ' (' . $n((int) $c) . ')';
            }
            $missing[] = [(string) $p, (int) $x['count'], '', null, $from === [] ? '' : $t['linked'] . ' ' . implode(', ', $from)];
        }
        $form = '';
        if (isset($o['action'])) {
            $form = '<form class="filter" method="get" action="' . $e((string) $o['action']) . '">';
            foreach ($o['keep'] ?? [] as $k => $v) {
                $form .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
            }
            $path = $o['path'] ?? null;
            $form .= '<label>' . $e($t['pathStarts']) . ' <input type="text" name="path" value="' . $e((string) $path) . '" placeholder="/news/"></label> <button type="submit">' . $e($t['filter']) . '</button>'
                . ($path !== null && isset($o['clear']) ? ' <a href="' . $e((string) $o['clear']) . '">' . $e($t['clear']) . '</a>' : '') . '</form>';
            $st = $r['subtree'];
            if ($st !== null) {
                $form .= '<p class="subtree"><b>' . $e($t['subtree'] . ' ' . $st['path']) . ':</b> ' . $e($n((int) $st['total']) . ' ' . $t['viewsOf']) . ' — '
                    . $e($n((int) $st['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $st['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $st['bots']) . ' ' . $t['bots2']) . '</p>';
            }
        }
        $cards = self::card('vp-p', $t['pages'], $form, [$t['tPages'] => $pages, $t['tSections'] => $sections, $t['tStopped'] => $stopped, $t['tMissing'] => $missing], $t, $lang, true, $help('the-statistics-page'));

        // Forms (proposal 0028): each with where it was sent from and how it ended; the editors' area apart.
        $forms = $r['forms'] ?? [];
        $backend = $r['backend'] ?? [];
        if ($forms !== [] || $backend !== []) {
            $live = $o['live'] ?? null;
            $cards .= self::card('vp-f', $t['forms'], '', [$t['tForms'] => self::formRows($forms, true, $t, $lang, $live), $t['tBackend'] => self::formRows($backend, false, $t, $lang, $live)], $t, $lang, true, $help('forms-sent-from-where-how-they-ended'));
        }

        // Crawlers & AI: who reads the site for search engines and AI assistants (verified by address).
        $kinds = ['search' => [], 'ai' => [], 'training' => []];
        foreach ($r['crawlers'] as $id => $c) {
            if ((int) $c['verified'] === 0) {
                continue;
            }
            $kind = $c['kind'] === 'search' ? 'search' : ($c['kind'] === 'ai-training' ? 'training' : 'ai');
            $last = $c['last'] !== null ? $t['lastVisit'] . ' ' . date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', (int) $c['last'][0]) : '';
            $kinds[$kind][] = [$c['name'] . ' (' . $id . ')', (int) $c['verified'], trim($n((int) $c['verified']) . ' ' . $t['verified'] . ($last !== '' ? ' · ' . $last : '')), null];
        }
        foreach ($kinds as $k => $rows) {
            usort($rows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
            $kinds[$k] = $rows;
        }
        $cards .= self::card('vp-c', $t['crawlersAi'], '', [$t['tSearch'] => $kinds['search'], $t['tAi'] => $kinds['ai'], $t['tTraining'] => $kinds['training']], $t, $lang, false, $help('', 'RSF01-04'));

        // One under the other, each the full width: the pages' addresses (with their website) need room.
        return $h . $cards;
    }

    /**
     * The rows of the card "Forms": each form, how many were sent, where from and how they ended.
     *
     * @param array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}> $list
     * @param array<string, string> $t
     * @param (callable(string): string)|null $live a form's stops in the live view
     * @return list<array{0: string, 1: int, 2: string, 3: ?string, 4: string}>
     */
    private static function formRows(array $list, bool $from, array $t, string $lang, ?callable $live): array
    {
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $out = [];
        foreach ($list as $p => $f) {
            $where = [];
            foreach ($from ? $f['from'] : [] as $src => $c) {
                $src = (string) $src;
                $where[] = ($src === '-' ? $t['fromNone'] : ($src === '=' ? $t['fromSite'] : ($src[0] === '@' ? $t['fromOther'] . ' ' . substr($src, 1) : $src))) . ' ' . $n($c);
            }
            $how = $n($f['saved']) . ' ' . $t['fSaved'] . ' · ' . $n($f['error']) . ' ' . $t['fErrors'] . ' · ' . $n($f['stopped']) . ' ' . $t['fStopped']
                . ($f['cross-site'] > 0 ? ' (' . $n($f['cross-site']) . ' ' . $t['fCross'] . ')' : '');
            $out[] = [(string) $p, $f['sent'], $n($f['sent']) . ' ' . $t['fSent'] . ($f['stopped'] > 0 && $live !== null ? ' · ' . $t['fLive'] : ''),
                $f['stopped'] > 0 && $live !== null ? $live((string) $p) : null,
                ($where !== [] ? $t['from'] . ' ' . implode(' · ', $where) . ' — ' : '') . $how];
        }
        return $out;
    }

    /**
     * A bucket's number for one of the six.
     *
     * @param array<string, int> $b
     */
    private static function value(array $b, string $m): int
    {
        return $m === 'stopped' ? ($b['refused'] ?? 0) + ($b['throttled'] ?? 0) : ($b[$m] ?? 0);
    }

    /**
     * "↑ 18 %", "↓ 4 %", "new" (nothing before): the change against the period before.
     *
     * @param array<string, string> $t
     */
    private static function change(int $now, int $was, array $t): string
    {
        if ($was === 0) {
            return $now === 0 ? $t['same'] : $t['new'];
        }
        $pct = (int) round(100 * ($now - $was) / $was);
        return $pct === 0 ? $t['same'] : ($pct > 0 ? '↑ ' : '↓ ') . abs($pct) . ' %';
    }

    /** A point's label: "14:00", "24.09." / "Sep 24", "2026-W39", "Sep 2026". */
    private static function label(string $key, string $by, string $lang): string
    {
        if ($by === 'hour') {
            return $key;
        }
        $ts = strtotime(substr($key, 0, 10) . (strlen($key) === 7 || strpos($key, '(month)') !== false ? '-01' : '') . ' UTC');
        if ($ts === false || $by === 'week') {
            return $key;
        }
        if ($by === 'month' || $by === 'year' || strpos($key, '(month)') !== false) {
            return $by === 'year' ? gmdate('Y', $ts) : gmdate($lang === 'de' ? 'm/Y' : 'M Y', $ts);
        }
        return gmdate($lang === 'de' ? 'd.m.' : 'M j', $ts);
    }

    /**
     * One line, the period before dashed, a column per point with its numbers
     * as the tooltip -- inline SVG, no script.
     *
     * @param list<int> $now
     * @param list<int> $was
     * @param list<string> $labels
     * @param array<string, string> $t
     */
    private static function chart(array $now, array $was, array $labels, array $t, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $count = count($now);
        if ($count === 0) {
            return '<p class="note">' . $e($t['none']) . '</p>';
        }
        [$w, $h, $left, $top, $bottom] = [800, 200, 44, 10, 26];
        $max = max(1, max($now), $was === [] ? 0 : max($was));
        $step = self::niceStep($max / 4);
        $max = $step * 4;
        $x = static fn (int $i): float => $count === 1 ? $left + ($w - $left) / 2 : $left + $i * ($w - $left - 8) / ($count - 1);
        $y = static fn (int $v): float => $top + ($h - $top - $bottom) * (1 - $v / $max);
        $f = static fn (float $v): string => number_format($v, 1, '.', '');
        $svg = '<svg class="vline" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" preserveAspectRatio="none" aria-label="' . $e(implode(', ', array_map(static fn (string $l, int $v): string => "$l: $v", $labels, $now))) . '">';
        for ($g = 0; $g <= 4; $g++) {
            $gy = $f($y($step * $g));
            $svg .= '<path class="vg" d="M' . $left . ' ' . $gy . ' H' . $w . '"/><text class="vax" x="' . ($left - 6) . '" y="' . $f((float) $gy + 4) . '" text-anchor="end">' . $e(StatsReport::number($step * $g, $lang)) . '</text>';
        }
        $line = static function (array $values) use ($x, $y, $f): string {
            $d = '';
            foreach (array_values($values) as $i => $v) {
                $d .= ($d === '' ? 'M' : ' L') . $f($x($i)) . ' ' . $f($y(is_int($v) ? $v : 0));
            }
            return $d;
        };
        if ($was !== []) {
            $svg .= '<path class="vprev" d="' . $line(array_slice($was, 0, $count)) . '"/>';
        }
        $d = $line($now);
        $svg .= '<path class="varea" d="' . $d . ' L' . $f($x($count - 1)) . ' ' . $f($y(0)) . ' L' . $f($x(0)) . ' ' . $f($y(0)) . ' Z"/><path class="vnow" d="' . $d . '"/>';
        // A column per point: its tooltip, and the label under every n-th.
        $every = max(1, (int) ceil($count / 8));
        $col = $count === 1 ? $w - $left : ($w - $left - 8) / ($count - 1);
        foreach ($now as $i => $v) {
            $cx = $x($i);
            $tip = $labels[$i] . ': ' . StatsReport::number($v, $lang) . (isset($was[$i]) ? ' (' . $t['before'] . ': ' . StatsReport::number($was[$i], $lang) . ')' : '');
            $svg .= '<rect class="vcol" x="' . $f(max(0.0, $cx - $col / 2)) . '" y="' . $top . '" width="' . $f($col) . '" height="' . ($h - $top - $bottom) . '"><title>' . $e($tip) . '</title></rect>';
            // Every n-th label, and the last -- not one so close to the last that the two overlap.
            if (($i % $every === 0 && $count - 1 - $i > intdiv($every, 2)) || $i === $count - 1) {
                $anchor = $count > 1 && $i === 0 ? 'start' : ($count > 1 && $i === $count - 1 ? 'end' : 'middle');      // the first and last inside the chart
                $svg .= '<text class="vax" x="' . $f($cx) . '" y="' . ($h - 8) . '" text-anchor="' . $anchor . '">' . $e($labels[$i]) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /** A round step for the grid: 1, 2, 5, 10, 20, 50 … */
    private static function niceStep(float $raw): int
    {
        $p = 10 ** (int) floor(log10(max(1.0, $raw)));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $p >= $raw) {
                return (int) max(1, $m * $p);
            }
        }
        return (int) (10 * $p);
    }

    /**
     * A card with tabs (radio buttons): each tab a list, a bar per row.
     *
     * @param array<string, list<array{0: string, 1: int, 2: string, 3: ?string, 4?: string}>> $tabs title => rows (name, number, tooltip, link, a note shown under the name)
     * @param array<string, string> $t
     */
    private static function card(string $id, string $title, string $top, array $tabs, array $t, string $lang, bool $code = true, string $help = ''): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<section class="card vtab"><h2>' . $e($title) . ($help !== '' ? ' ' . $help : '') . '</h2>';
        $i = 0;
        foreach ($tabs as $name => $_) {
            $h .= '<input type="radio" name="' . $id . '" id="' . $id . $i . '"' . ($i === 0 ? ' checked' : '') . '>';
            $i++;
        }
        $h .= '<div class="vtl">';
        $i = 0;
        foreach ($tabs as $name => $_) {
            $h .= '<label for="' . $id . $i . '">' . $e($name) . '</label>';
            $i++;
        }
        $h .= '</div>' . $top . '<div class="vpanes">';
        foreach ($tabs as $rows) {
            $h .= '<div>';
            if ($rows === []) {
                $h .= '<p class="note">' . $e($t['none']) . '</p>';
            }
            $most = 1;
            foreach ($rows as $row) {
                $most = max($most, $row[1]);
            }
            foreach ($rows as $row) {
                [$name, $count, $tip, $href] = $row;
                $label = $code ? '<code>' . $e($name) . '</code>' : $e($name);
                $h .= '<div class="vrow"' . ($tip !== '' ? ' title="' . $e($tip) . '"' : '') . '><i style="width:' . number_format(100 * $count / $most, 1, '.', '') . '%"></i>'
                    . '<span class="vn">' . ($href !== null ? '<a href="' . $e($href) . '">' . $label . '</a>' : $label)
                    . (($row[4] ?? '') !== '' ? '<br><small class="note">' . $e((string) $row[4]) . '</small>' : '') . '</span><span class="vv">' . $e(StatsReport::number($count, $lang)) . '</span></div>';
            }
            $h .= '</div>';
        }
        return $h . '</div></section>';
    }

    /** The page's styles: tiles, chart, tabs (radio buttons, no script). */
    public const CSS = <<<'CSS'
.vnowl{margin:-4px 0 10px;color:var(--m)}.range{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:var(--m)}.range input{font:inherit;padding:3px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg)}.range button{font:inherit;padding:3px 10px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#fff;cursor:pointer}
.vp>input,.vtab>input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.vtiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin:0 0 12px}
.vt{display:block;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 12px;cursor:pointer}
.vt .l{display:block;color:var(--m);font-size:12px;text-transform:uppercase;letter-spacing:.03em}.vt .n{display:block;font-size:24px;font-weight:700;margin:2px 0}.vt .d{color:var(--m);font-size:13px}
.vcharts{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin:0 0 16px}.vc{display:none}
.vline{display:block;height:220px}.vg{stroke:var(--line)}.vax{fill:var(--m);font-size:11px}.vnow{stroke:var(--a);stroke-width:2;fill:none;vector-effect:non-scaling-stroke}
.vprev{stroke:var(--m);stroke-width:1.5;stroke-dasharray:5 4;fill:none;opacity:.7;vector-effect:non-scaling-stroke}.varea{fill:var(--a);opacity:.08}.vcol{fill:transparent}.vcol:hover{fill:var(--a);opacity:.08}
.vtl{display:flex;flex-wrap:wrap;gap:4px 14px;margin:-4px 0 8px;border-bottom:1px solid var(--line)}.vtl label{cursor:pointer;color:var(--m);padding:4px 0 6px;font-size:14px}
.vpanes>div{display:none}.vrow{position:relative;display:flex;justify-content:space-between;gap:12px;padding:5px 8px;margin:3px 0;border-radius:5px}
.vrow i{position:absolute;left:0;top:0;bottom:0;background:var(--a);opacity:.12;border-radius:5px}.vrow .vn{position:relative;min-width:0;overflow-wrap:anywhere}.vrow .vv{position:relative;font-variant-numeric:tabular-nums;white-space:nowrap}
.vp>input:focus-visible~.vtiles,.vtab>input:focus-visible~.vtl{outline:2px solid var(--a);outline-offset:2px;border-radius:6px}
CSS;

    /** Which tile and tab is picked: one rule per position (up to six numbers, four tabs). */
    public static function css(): string
    {
        $css = self::CSS;
        for ($i = 1; $i <= 6; $i++) {
            $css .= ".vp>input:nth-of-type($i):checked~.vcharts>.vc:nth-child($i){display:block}.vp>input:nth-of-type($i):checked~.vtiles>.vt:nth-child($i){border-color:var(--a);box-shadow:inset 0 -3px 0 var(--a)}";
        }
        for ($i = 1; $i <= 4; $i++) {
            $css .= ".vtab>input:nth-of-type($i):checked~.vpanes>div:nth-child($i){display:block}.vtab>input:nth-of-type($i):checked~.vtl>label:nth-child($i){color:var(--fg);font-weight:600;box-shadow:inset 0 -2px 0 var(--a)}";
        }
        return $css;
    }
}
}
}

// plugins/stats/src/Stats.php
namespace CjwNetwork\RequestShield\Stats {
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * Counters for the dashboard (proposals 0012, 0014): how many requests the
 * shield let through, checked or refused, by which rule, and what each known
 * crawler did -- per hour, kept while requests pass, so nobody has to read
 * the log to see it.
 *
 * With APCu a counter is one apcu_inc() (~0.2 µs); without, a request appends
 * one short line to the hour's file (lock-free, O_APPEND). The first request
 * after an hour is over moves the finished hours into one small JSON file per
 * day (<dir>/d-<yyyymmdd>.json); hours are kept for $hours days, the day's
 * totals for $days days -- then they are added to their month's file
 * (<dir>/m-<yyyymm>.json), kept for $months months (0: for good), so a year
 * is twelve small files.
 *
 * A counter's name ("what"): "a:<action>", "r:<rule>", "c:<crawler>:<event>",
 * "p:<crawler>:<path>", "o:<bot family>", "m:<action>" (what monitor mode would
 * have done), "s:<status>" (the answer's status code), "n:<path>" (a page the
 * site did not find), "nr:<path>|<referrer>" (where a link to it was: a path
 * of the site itself, or another site's host), "sm:<sitemap>|<status>" (a
 * sitemap asked for, and the answer), "pg:<people|crawlers|bots>|<path>" (a
 * page the site answered with 200 and HTML), "pd:<people|crawlers|bots>|<folder>"
 * (its first two folders, for a subtree's views), "pb:<refused|checked|throttled>|<path>"
 * (a page the shield stopped, and how), "smc:<sitemap>|<crawler>" (a verified
 * crawler read it). "l:<crawler>|<time>|<address>" is not counted: the
 * crawler's last visit ("l:sitemap:<path>@<crawler>|…": its last read of a
 * sitemap).
 *
 * How long the site took (part times, proposal 0046): "rt:<who>|<cache>|<band>"
 * (one of the BANDS), "rs:<who>|<cache>" (the sum, in microseconds),
 * "rq:shield" (the shield's own share, in microseconds), "rn:<reason>" (why a
 * miss was not kept: CachePlugin::refusal()) -- cache is hit, miss (asked the
 * site, kept), nostore (asked the site, not to be kept) or past (no cache
 * asked). The slow requests go to slow-<yyyymmdd>.log, a line each. Per page
 * viewed (step 2): "pt:<who>|<path>|<band>", "ps:<who>|<path>" (the sum),
 * "pc:<who>|<path>" (its hits) -- kept for the same pages as its views
 * ("pg:"): a page past the views' limit is "(other)" here too.
 */
final class Stats
{
    private string $dir;
    private bool $apcu;
    private int $hours = 7;
    private int $days = 400;
    private ?string $crawlerLog = null;
    private int $crawlerLogDays = 30;
    private int $flush = 60;
    private int $months = 0;
    /** "rshield:stat:<site>:" -- each statistics directory its own, so sites on one PHP-FPM pool (one APCu) stay apart. */
    private string $prefix;

    /** Pages kept per crawler and hour (and in a day's totals), pages not found, referrers per page not found; the rest count as "(other)". */
    public const PAGES = 50;

    public const REFERRERS = 5;

    /** The most visited pages kept per kind of visitor and hour (and in a day's totals). */
    public const TOP = 100;

    /**
     * How long the site took, in bands (0046): the upper ends, in
     * microseconds -- 1, 5, 10, 25, 50, 100, 250, 500 ms, 1, 2.5, 10 s; a
     * twelfth band holds what took longer. Fixed: hours add up into days and
     * months only while the bands stay the same.
     */
    public const BANDS = [1000, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000, 2500000, 10000000];

    /** The band a time falls in: 0 (up to 1 ms) to 11 (over 10 s). */
    public static function band(int $us): int
    {
        foreach (self::BANDS as $i => $top) {
            if ($us <= $top) {
                return $i;
            }
        }
        return count(self::BANDS);
    }

    /** Sitemaps kept a day (and five times as many sitemap-crawler pairs); the rest count as "(other)". */
    public const SITEMAPS = 20;

    /**
     * "Now": one more request by a person in this minute -- with APCu only,
     * never written to disk (it is gone after ten minutes).
     */
    public function minute(float $now): void
    {
        if ($this->apcu) {
            apcu_inc($this->prefix . 'min:' . intdiv((int) $now, 60), 1, $ok, 600);
        }
    }

    /** People's requests in the last $minutes minutes (this one included); null without APCu. */
    public function lastMinutes(int $minutes, float $now): ?int
    {
        if (!$this->apcu) {
            return null;
        }
        $keys = [];
        for ($m = intdiv((int) $now, 60), $i = 0; $i < $minutes; $i++) {
            $keys[] = $this->prefix . 'min:' . ($m - $i);
        }
        $sum = 0;
        foreach ((array) apcu_fetch($keys) as $n) {
            $sum += is_int($n) ? $n : 0;
        }
        return $sum;
    }

    public function __construct(
        string $dir,
        bool $apcu,
        int $hours = 7,
        int $days = 400,
        ?string $crawlerLog = null,
        int $crawlerLogDays = 30,
        int $flush = 60,
        int $months = 0
    ) {
        $this->dir = $dir;
        $this->apcu = $apcu;
        $this->hours = $hours;
        $this->days = $days;
        $this->crawlerLog = $crawlerLog;
        $this->crawlerLogDays = $crawlerLogDays;
        $this->flush = $flush;
        $this->months = $months;
        $this->prefix = 'rshield:stat:' . substr(md5($dir), 0, 8) . ':';
    }

    /** Where the websites no stats-hosts name are counted (not a name a website can have). */
    public const OTHER = '(other)';

    /**
     * The counters of these settings: in store-dir/stats, with APCu where the
     * store would use it -- with stats-hosts, a website's in
     * store-dir/stats/hosts/<name> ($site: a name of statsHosts, or OTHER).
     */
    public static function of(Settings $s, ?string $site = null): self
    {
        $apcu = $s->store === 'apcu' || ($s->store === 'auto' && \CjwNetwork\RequestShield\Store\ApcuStore::usable());
        $dir = $s->storeDir . '/stats' . ($site === null ? '' : '/hosts/' . str_replace('*', '+', $site));
        $o = StatsExtension::of($s);
        $stats = new self($dir, $apcu, $o['hours'], $o['days'], $o['crawlerLog']['dir'], $o['crawlerLog']['days'], $o['flush'], $o['months']);
        $stats->site = $site;
        return $stats;
    }

    /** The settings whose stats-hosts $names holds (one request, one settings: kept for the next lookup). */
    private static ?Settings $namesFor = null;

    /** @var array<string, true> */
    private static array $names = [];

    /**
     * Whose statistics a request counts in: the website it names (lower case,
     * without port and trailing dot) if stats-hosts names it -- exactly, or by
     * *.domain (one label more) -- else OTHER; null without stats-hosts (one
     * statistics). The Host header comes from the client: a made-up name gets
     * no statistics of its own.
     */
    public static function siteOf(Settings $s, string $host): ?string
    {
        $hosts = StatsExtension::of($s)['hosts'];
        if ($hosts === []) {
            return null;
        }
        if (self::$namesFor !== $s) {
            self::$namesFor = $s;
            self::$names = array_fill_keys($hosts, true);
        }
        $names = self::$names;
        if (isset($names[$host])) {
            return $host;
        }
        $dot = strpos($host, '.');
        if ($dot !== false && $dot > 0 && isset($names['*' . substr($host, $dot)])) {
            return '*' . substr($host, $dot);
        }
        return self::OTHER;
    }

    /**
     * The counters with the website in front of every path: pages, sections,
     * pages stopped, not found and their links, sitemaps, a crawler's pages.
     * Only a real path ("/…"); "(other)" and the like stay as they are.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    public static function withHost(array $counts, string $site): array
    {
        $out = [];
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            $colon = strpos($k, ':');
            $type = $colon === false ? '' : (string) substr($k, 0, $colon);
            switch ($type) {
                case 'pg':
                case 'pd':
                case 'pb':
                    $at = strpos($k, '|');
                    break;
                case 'n':
                case 'nr':
                case 'sm':
                case 'smc':
                case 'f':
                case 'fo':
                case 'ff':
                    $at = $colon;
                    break;
                case 'p':
                    $at = strpos($k, ':', (int) $colon + 1);
                    break;
                default:
                    $at = false;
                    break;
            }
            if ($at !== false && ($k[$at + 1] ?? '') === '/') {
                $k = substr($k, 0, $at + 1) . $site . substr($k, $at + 1);
            }
            $out[$k] = ($out[$k] ?? 0) + $n;
        }
        return $out;
    }

    /** Whether $site names something to read: a website of stats-hosts, OTHER, or group:<id>. */
    public static function known(Settings $s, string $site): bool
    {
        return $site === self::OTHER || in_array($site, StatsExtension::of($s)['hosts'], true) || (strncmp($site, 'group:', 6) === 0 && isset(StatsExtension::of($s)['groups'][substr($site, 6)]));
    }

    /**
     * The statistics to read for $site: that website's, a group's (group:<id>), or (null) every
     * website's and the shared directory's -- what was counted before
     * stats-hosts was set stays in the sum.
     *
     * @return list<self>
     */
    public static function all(Settings $s, ?string $site = null): array
    {
        $hosts = StatsExtension::of($s)['hosts'];
        if ($hosts === []) {
            return [self::of($s)];
        }
        if ($site !== null && strncmp($site, 'group:', 6) === 0) {
            // A group: its websites, added up.
            return array_map(static fn (string $name): self => self::of($s, $name), StatsExtension::of($s)['groups'][substr($site, 6)]['sites'] ?? []);
        }
        if ($site !== null) {
            return [self::of($s, $site)];
        }
        $out = [self::of($s)];
        foreach (array_merge($hosts, [self::OTHER]) as $name) {
            $out[] = self::of($s, $name);
        }
        return $out;
    }

    /**
     * read() of several statistics, added up: the counters exactly; the lists
     * in them (pages, referrers …) are summed per entry, as a day's hours are.
     *
     * @param list<self> $all
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public static function readAll(array $all, string $fromDay, string $toDay): array
    {
        $out = ['days' => [], 'hours' => [], 'months' => [], 'last' => []];
        $several = count($all) > 1;
        foreach ($all as $stats) {
            $r = $stats->read($fromDay, $toDay);
            foreach (['days', 'hours', 'months'] as $part) {
                foreach ($r[$part] as $k => $counts) {
                    // Several websites: each page with its website in front (a.de/news/x), or
                    // the same path on two websites would be one page.
                    if ($several && $stats->site !== null) {
                        $counts = self::withHost($counts, $stats->site);
                    }
                    $out[$part][(string) $k] = self::add($out[$part][(string) $k] ?? [], $counts);
                }
            }
            foreach ($r['last'] as $id => $seen) {
                if ($seen[0] > ($out['last'][$id][0] ?? 0)) {
                    $out['last'][$id] = $seen;
                }
            }
        }
        foreach (['days', 'hours', 'months'] as $part) {
            ksort($out[$part]);
            foreach ($out[$part] as &$counts) {
                ksort($counts);
            }
            unset($counts);
        }
        return $out;
    }

    /** The website these counters are of (stats-hosts), or null: one statistics, or what was counted before. */
    public ?string $site = null;

    /** The directory these counters are kept in. */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * One request: add one to each counter (and note a crawler's last visit),
     * and an amount to each of $amounts (a sum: microseconds).
     *
     * @param list<string> $keys
     * @param array<string, int> $amounts key => amount, keys without a limit only
     */
    public function count(array $keys, float $now, array $amounts = []): void
    {
        // The hour's name, made once an hour (gmdate() costs half a microsecond).
        $n = intdiv((int) $now, 3600);
        if (self::$hourN !== $n) {
            [self::$hourN, self::$hour, self::$closedHour] = [$n, gmdate('YmdH', (int) $now), self::closed($now)];
        }
        $hour = self::$hour;
        if ($this->apcu) {
            $past = [];                 // "<who>|<path>" of a page view past the limit: its times follow it
            foreach ($keys as $k) {
                if (strncmp($k, 'l:', 2) === 0) {
                    [$id, $rest] = explode('|', (string) substr($k, 2), 2) + ['', ''];
                    apcu_store($this->prefix . 'last:' . $id, $rest, 86400 * 8);
                    continue;
                }
                if (self::group($k) !== null) {
                    $was = $k;
                    $k = $this->page($hour, $k);
                    if ($k === '') {
                        continue;
                    }
                    if ($k !== $was && strncmp($was, 'pg:', 3) === 0) {
                        $past[substr($was, 3)] = true;
                    }
                } elseif ($past !== []) {
                    $k = self::pageTime($k, $past);
                }
                apcu_inc($this->prefix . $hour . ':' . $k, 1, $ok, 86400 * 8);
            }
            foreach ($amounts as $k => $n) {
                if ($n > 0) {
                    apcu_inc($this->prefix . $hour . ':' . ($past !== [] ? self::pageTime($k, $past) : $k), $n, $ok, 86400 * 8);
                }
            }
            $this->tend($now);
            return;
        }
        // An amount as the flush writes one: "name*180".
        foreach ($amounts as $k => $n) {
            if ($n > 0) {
                $keys[] = $k . '*' . $n;
            }
        }
        if ($keys === []) {
            $this->tend($now);
            return;
        }
        // A new hour's file in file-mode (Files: once per process and file), the folder made if missing.
        \CjwNetwork\RequestShield\Files::append($this->dir . '/h-' . $hour . '.log', implode(' ', $keys) . "\n");
        $this->tend($now);
    }

    /**
     * Housekeeping, once per process and hour: the finished hour rolled up;
     * with APCu, every $flush seconds what it holds written to the hour's file
     * (a restart of PHP-FPM loses at most that much). Called by count(), and
     * for the websites a request did not count in (a quiet website's hour is
     * rolled up too).
     */
    public function tend(float $now): void
    {
        $n = intdiv((int) $now, 3600);
        if (self::$hourN !== $n) {
            [self::$hourN, self::$hour, self::$closedHour] = [$n, gmdate('YmdH', (int) $now), self::closed($now)];
        }
        $closed = self::$closedHour;
        if ($this->apcu) {
            // Once per process and hour: has the finished hour been rolled up?
            if ((self::$checked[$this->dir] ?? null) !== $closed) {
                self::$checked[$this->dir] = $closed;
                if (apcu_fetch($this->prefix . 'rolled') !== $closed) {
                    $this->later(fn () => $this->roll($now));
                }
            }
            if ($this->flush > 0 && $now - (self::$flushed[$this->dir] ?? 0.0) >= $this->flush) {
                self::$flushed[$this->dir] = $now;
                if (apcu_add($this->prefix . 'flush', 1, $this->flush)) {
                    $this->later(fn () => $this->flush());
                }
            }
            return;
        }
        if ((self::$checked[$this->dir] ?? null) !== $closed) {
            self::$checked[$this->dir] = $closed;
            if (!is_file($this->dir . '/rolled-' . $closed)) {
                $this->later(fn () => $this->roll($now));
            }
        }
    }

    /**
     * Housekeeping (roll-up, flush) after the visitor has the answer: on
     * PHP-FPM (fastcgi_finish_request) and LiteSpeed the request is finished
     * first, so a large hour's roll-up (seconds at 1,000 requests a second in
     * file mode) never makes anyone wait. On the command line: at once.
     */
    private function later(\Closure $work): void
    {
        if (PHP_SAPI === 'cli') {
            $work();
            return;
        }
        register_shutdown_function(static function () use ($work): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $work();
        });
    }

    private static ?int $hourN = null;

    private static string $hour = '';

    private static string $closedHour = '';

    /** @var array<string, float> directory => when this process last looked whether to flush */
    private static array $flushed = [];

    /**
     * A slow request (stats-slow): one line in the day's slow log -- time,
     * method, path (no query), status, milliseconds, peak memory in MB, cache,
     * who. No address. Rotated as the log is (log-max-size).
     */
    public function slow(string $line, float $now, int $maxSize): void
    {
        \CjwNetwork\RequestShield\Log::append($this->dir . '/slow-' . gmdate('Ymd', (int) $now) . '.log', $line . "\n", $maxSize);
    }

    /**
     * The slow log's last $max lines from $fromDay to $toDay (yyyymmdd), the
     * newest last.
     *
     * @return list<string>
     */
    public function slowLines(string $fromDay, string $toDay, int $max = 50): array
    {
        $lines = [];
        $files = glob($this->dir . '/slow-*.log') ?: [];
        sort($files);
        foreach ($files as $file) {
            $day = (string) substr(basename($file, '.log'), 5);
            if ($day < $fromDay || $day > $toDay) {
                continue;
            }
            foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $lines[] = $line;
            }
            $lines = array_slice($lines, -$max);
        }
        return $lines;
    }

    /**
     * A page's time key (pt:, ps:, pc:) as its view was kept: "(other)" when
     * the view went past the limit ($past: "<who>|<path>" => true).
     *
     * @param array<string, true> $past
     */
    private static function pageTime(string $k, array $past): string
    {
        $t = substr($k, 0, 3);
        if ($t !== 'pt:' && $t !== 'ps:' && $t !== 'pc:') {
            return $k;
        }
        $rest = (string) substr($k, 3);
        $band = '';
        if ($t === 'pt:') {
            $bar = (int) strrpos($rest, '|');
            [$rest, $band] = [(string) substr($rest, 0, $bar), (string) substr($rest, $bar)];
        }
        return isset($past[$rest]) ? $t . substr($rest, 0, (int) strpos($rest, '|')) . '|(other)' . $band : $k;
    }

    /** @var array<string, string> directory => the finished hour this process has checked the roll-up for (a PHP-FPM worker serves many requests) */
    private static array $checked = [];

    /**
     * A counter that belongs to a group with a limit (a crawler's pages,
     * pages not found, a missing page's referrers): the group, its limit and
     * the key everything past it is counted under. Null for the others.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private static function group(string $key): ?array
    {
        if (strncmp($key, 'p:', 2) === 0) {
            $g = (string) substr($key, 0, (int) strpos($key, ':', 2));
            return [$g, self::PAGES, $g . ':(other)'];
        }
        if (strncmp($key, 'n:', 2) === 0) {
            return ['n', self::PAGES, 'n:(other)'];
        }
        if (strncmp($key, 'nr:', 3) === 0) {
            $g = (string) substr($key, 0, (int) strpos($key, '|'));
            return [$g, self::REFERRERS, $g . '|(other)'];
        }
        if (strncmp($key, 'pd:', 3) === 0) {
            // A limit for each folder level: the many deep ones never crowd out the few above.
            $bar = (int) strpos($key, '|');
            $g = (string) substr($key, 0, $bar);
            return [$g . '#' . max(0, substr_count($key, '/', $bar) - 1), self::TOP * 2, $g . '|(other)'];
        }
        if (strncmp($key, 'pg:', 3) === 0 || strncmp($key, 'pb:', 3) === 0) {
            $g = (string) substr($key, 0, (int) strpos($key, '|'));
            return [$g, self::TOP, $g . '|(other)'];
        }
        if (strncmp($key, 'sm:', 3) === 0) {
            return ['sm', self::SITEMAPS, 'sm:(other)|0'];
        }
        // Forms (proposal 0028): the most sent kept, as pages; each form's pages before it, a few.
        if (strncmp($key, 'f:', 2) === 0) {
            return ['f', self::TOP, 'f:(other)'];
        }
        if (strncmp($key, 'fo:', 3) === 0) {
            $bar = (int) strrpos($key, '|');
            return ['fo', self::TOP * 6, 'fo:(other)' . substr($key, $bar)];
        }
        if (strncmp($key, 'ff:', 3) === 0) {
            $bar = (int) strrpos($key, '|');
            $g = (string) substr($key, 0, $bar);
            return [$g, self::REFERRERS, $g . '|(other)'];
        }
        if (strncmp($key, 'smc:', 4) === 0) {
            return ['smc', self::SITEMAPS * 5, 'smc:(other)|(other)'];
        }
        return null;
    }

    /**
     * The key, or "(other)" past its group's limit in this hour: two more
     * APCu calls, only for crawlers' pages and pages not found.
     */
    private function page(string $hour, string $key): string
    {
        $g = self::group($key);
        if ($g === null) {
            return $key;
        }
        [$group, $limit, $other] = $g;
        if (apcu_exists($this->prefix . $hour . ':' . $key)) {
            return $key;                    // admitted already
        }
        // A missing page's referrers only for missing pages that made the list:
        // random paths must not open a group each.
        if (strncmp($key, 'nr:', 3) === 0 && !apcu_exists($this->prefix . $hour . ':n:' . substr($group, 3))) {
            return '';
        }
        $counter = $this->prefix . 'pages:' . $hour . ':' . md5((string) $group);
        $taken = apcu_fetch($counter);
        if (is_int($taken) && $taken >= $limit) {
            return (string) $other;         // full: no more guard keys (a flood of random paths stays small)
        }
        if (apcu_add($this->prefix . 'seen:' . $hour . ':' . md5($key), 1, 86400 * 8)) {
            // Seen for the first time this hour: one of the first, or "(other)".
            return apcu_inc($counter, 1, $ok, 86400 * 8) > $limit ? (string) $other : $key;
        }
        return (string) $other;
    }

    /**
     * The newest hour that is over, with a minute's grace for requests still
     * running: at 10:00:30 that is 08, at 10:01 09. It and the hours before
     * it are rolled up.
     */
    private static function closed(float $now): string
    {
        return gmdate('YmdH', (int) $now - 3660);
    }

    /**
     * Writes what APCu holds into the hours' files ("name*count"), and takes
     * exactly that much out of APCu -- counts added meanwhile stay. The files
     * are read with APCu's counters and rolled up like them.
     */
    public function flush(): void
    {
        if (!$this->apcu) {
            return;
        }
        $lines = [];
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/') as $key => $entry) {
            if (!is_array($entry) || !preg_match('/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/', (string) $key, $m)) {
                continue;
            }
            $value = $entry['value'] ?? null;
            if ($m[1] === 'last') {
                if (is_string($value)) {
                    $lines[gmdate('YmdH', (int) $value)][] = 'l:' . $m[2] . '|' . $value;
                }
                continue;
            }
            $n = is_int($value) ? $value : 0;
            if ($n > 0 && apcu_dec((string) $key, $n) !== false) {
                $lines[$m[1]][] = $m[2] . '*' . $n;
            }
        }
        foreach ($lines as $hour => $tokens) {
            \CjwNetwork\RequestShield\Files::append($this->dir . '/h-' . $hour . '.log', implode(' ', $tokens) . "\n");
        }
    }

    /**
     * Moves the finished hours into the day files; drops hours past $hours
     * days and days past $days days, and per-crawler logs past theirs. One
     * request does it; the others go on.
     */
    public function roll(float $now): void
    {
        $closed = self::closed($now);
        if (!\CjwNetwork\RequestShield\Files::dir($this->dir)) {
            return;
        }
        $lock = @fopen($this->dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;             // another request is at it
        }
        try {
            [$hours, $last] = $this->collect($closed, true);
            $byDay = [];
            foreach ($hours as $hour => $counts) {
                // (PHP makes "2026093010" an integer key: back to a string.)
                $hour = (string) $hour;
                $byDay[substr($hour, 0, 8)][substr($hour, 8, 2)] = $counts;
            }
            foreach ($byDay as $day => $hs) {
                $day = (string) $day;
                $file = $this->dir . '/d-' . $day . '.json';
                $d = self::load($file);
                foreach ($hs as $h => $counts) {
                    $h = sprintf('%02d', $h);
                    $d['hours'][$h] = self::cap(self::add($d['hours'][$h] ?? [], $counts));
                }
                foreach ($last as $id => $seen) {
                    if (gmdate('Ymd', (int) $seen[0]) === $day && ($seen[0] > ($d['last'][$id][0] ?? 0))) {
                        $d['last'][$id] = $seen;
                    }
                }
                self::save($file, $d);
            }
            // Every crawler's last visit in one small file: a report of a week
            // need not open every day file of the year to find it.
            if ($last !== []) {
                $all = self::load($this->dir . '/last.json');
                foreach ($last as $id => $seen) {
                    if ($seen[0] > ($all['last'][$id][0] ?? 0)) {
                        $all['last'][$id] = $seen;
                    }
                }
                self::save($this->dir . '/last.json', ['hours' => [], 'total' => [], 'last' => $all['last']]);
            }
            $this->expire($now);
            if ($this->apcu) {
                apcu_store($this->prefix . 'rolled', $closed, 86400 * 8);
            } else {
                foreach (glob($this->dir . '/rolled-*') ?: [] as $old) {
                    @unlink($old);
                }
                @touch($this->dir . '/rolled-' . $closed);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The counters not yet in a day file: hours up to $until (and, for
     * reading, the running one), taken out when $take -- APCu's and the
     * hours' files (without APCu, or written by a flush).
     *
     * @return array{0: array<array-key, array<string, int>>, 1: array<string, array{0: int, 1: string}>} hour => counts, crawler => [time, address]
     */
    private function collect(?string $until, bool $take): array
    {
        $hours = [];
        $last = [];
        if ($this->apcu) {
            $pattern = '/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/';
            foreach (new \APCUIterator($pattern) as $key => $entry) {
                if (!is_array($entry) || !preg_match($pattern, (string) $key, $m)) {
                    continue;
                }
                $value = $entry['value'] ?? null;
                if ($m[1] === 'last') {
                    $v = explode('|', is_string($value) ? $value : '', 2);
                    $last[$m[2]] = [(int) $v[0], $v[1] ?? ''];
                    continue;
                }
                if ($until !== null && $m[1] > $until) {
                    continue;
                }
                if (is_int($value) && $value > 0) {
                    $hours[$m[1]][$m[2]] = ($hours[$m[1]][$m[2]] ?? 0) + $value;
                }
                if ($take) {
                    apcu_delete((string) $key);
                }
            }
            if ($take) {
                foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '(seen|pages):(\d{10}):/') as $key => $entry) {
                    if (preg_match('/:(\d{10}):/', (string) $key, $m) && ($until === null || $m[1] <= $until)) {
                        apcu_delete((string) $key);
                    }
                }
            }
        }
        foreach (glob($this->dir . '/h-*.log') ?: [] as $file) {
            $hour = (string) substr(basename($file, '.log'), 2);
            if ($until !== null && $hour > $until) {
                continue;
            }
            $h = @fopen($file, 'rb');
            if ($h === false) {
                continue;
            }
            while (($line = fgets($h)) !== false) {
                foreach (explode(' ', trim($line)) as $k) {
                    if ($k === '') {
                        continue;
                    }
                    if (strncmp($k, 'l:', 2) === 0) {
                        $v = explode('|', (string) substr($k, 2), 3);
                        if (count($v) === 3 && (int) $v[1] >= ($last[$v[0]][0] ?? 0)) {
                            $last[$v[0]] = [(int) $v[1], $v[2]];
                        }
                        continue;
                    }
                    // "name" is one, "name*12" twelve (written by a flush).
                    $star = strrpos($k, '*');
                    $n = 1;
                    if ($star !== false && ctype_digit(substr($k, $star + 1))) {
                        [$k, $n] = [(string) substr($k, 0, $star), (int) substr($k, $star + 1)];
                    }
                    $hours[$hour][$k] = ($hours[$hour][$k] ?? 0) + $n;
                }
            }
            fclose($h);
            if ($take) {
                @unlink($file);
            }
        }
        return [$hours, $last];
    }

    /** Hours past their days summed into the day's totals; days past theirs removed; old crawler logs removed. */
    private function expire(float $now): void
    {
        $keepHours = gmdate('Ymd', (int) $now - $this->hours * 86400);
        $keepDays = gmdate('Ymd', (int) $now - $this->days * 86400);
        foreach (glob($this->dir . '/d-*.json') ?: [] as $file) {
            $day = (string) substr(basename($file, '.json'), 2);
            if ($day < $keepDays) {
                // Into its month, then gone: the month keeps the total.
                $d = self::load($file);
                $total = $d['total'];
                foreach ($d['hours'] as $counts) {
                    $total = self::add($total, $counts);
                }
                $monthFile = $this->dir . '/m-' . substr($day, 0, 6) . '.json';
                $m = self::load($monthFile);
                $last = $m['last'];
                foreach ($d['last'] as $id => $seen) {
                    if ($seen[0] > ($last[$id][0] ?? 0)) {
                        $last[$id] = $seen;
                    }
                }
                self::save($monthFile, ['hours' => [], 'total' => self::cap(self::add($m['total'], $total)), 'last' => $last, 'days' => array_values(array_unique(array_merge($m['days'], [$day])))]);
                @unlink($file);
            } elseif ($day < $keepHours) {
                $d = self::load($file);
                if ($d['hours'] !== []) {
                    $total = $d['total'];
                    foreach ($d['hours'] as $counts) {
                        $total = self::add($total, $counts);
                    }
                    self::save($file, ['hours' => [], 'total' => self::cap($total), 'last' => $d['last']]);
                }
            }
        }
        if ($this->months > 0) {
            $keepMonths = gmdate('Ym', (int) strtotime(gmdate('Y-m-01', (int) $now) . ' -' . $this->months . ' months'));
            foreach (glob($this->dir . '/m-*.json') ?: [] as $file) {
                if (substr(basename($file, '.json'), 2) < $keepMonths) {
                    @unlink($file);
                }
            }
        }
        // The slow log: as long as the hours (stats-hours days) -- its lines are details, like them.
        foreach (glob($this->dir . '/slow-*.log*') ?: [] as $file) {
            if (substr(basename($file), 5, 8) < $keepHours) {
                @unlink($file);
            }
        }
        if ($this->crawlerLog !== null) {
            $keep = date('Y-m-d', (int) $now - $this->crawlerLogDays * 86400);
            foreach (glob($this->crawlerLog . '/*/*.log') ?: [] as $file) {
                if (basename($file, '.log') < $keep) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * What was counted from $fromDay to $toDay (yyyymmdd, both included): each
     * day's totals, the hours still kept, every crawler's last visit. The
     * running hour included.
     *
     * Days older than $days are only in their month's total ("months": yyyymm =>
     * counts, for every month file the range touches).
     *
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public function read(string $fromDay, string $toDay): array
    {
        $days = [];
        $hours = [];
        $last = [];
        $months = [];
        foreach (glob($this->dir . '/m-*.json') ?: [] as $file) {
            $month = (string) substr(basename($file, '.json'), 2);
            if ($month >= substr($fromDay, 0, 6) && $month <= substr($toDay, 0, 6)) {
                $months[$month] = self::load($file)['total'];
                ksort($months[$month]);
            }
        }
        ksort($months);
        $last = self::load($this->dir . '/last.json')['last'];
        foreach (glob($this->dir . '/d-*.json') ?: [] as $file) {
            $day = (string) substr(basename($file, '.json'), 2);
            if ($day < $fromDay || $day > $toDay) {
                continue;               // only the days asked for are opened
            }
            $d = self::load($file);
            foreach ($d['last'] as $id => $seen) {
                if ($seen[0] > ($last[$id][0] ?? 0)) {
                    $last[$id] = $seen;
                }
            }
            $days[$day] = $d['total'];
            foreach ($d['hours'] as $h => $counts) {
                $hours[$day . sprintf('%02d', $h)] = $counts;
                $days[$day] = self::add($days[$day], $counts);
            }
        }
        [$live, $liveLast] = $this->collect(null, false);
        foreach ($live as $hour => $counts) {
            $hour = (string) $hour;
            $day = substr($hour, 0, 8);
            if ($day >= $fromDay && $day <= $toDay) {
                $hours[$hour] = self::add($hours[$hour] ?? [], $counts);
                $days[$day] = self::add($days[$day] ?? [], $counts);
            }
        }
        foreach ($liveLast as $id => $seen) {
            if ($seen[0] > ($last[$id][0] ?? 0)) {
                $last[$id] = $seen;
            }
        }
        ksort($days);
        ksort($hours);
        foreach ($days as &$counts) {
            ksort($counts);         // the same order from files and APCu
        }
        unset($counts);
        foreach ($hours as &$counts) {
            ksort($counts);
        }
        unset($counts);
        return ['days' => $days, 'hours' => $hours, 'months' => $months, 'last' => $last];
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    public static function add(array $a, array $b): array
    {
        foreach ($b as $k => $n) {
            $a[(string) $k] = ($a[(string) $k] ?? 0) + $n;
        }
        return $a;
    }

    /**
     * Each group at its limit: the most counted kept, the rest as "(other)".
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function cap(array $counts): array
    {
        $groups = [];
        foreach ($counts as $k => $n) {
            $g = self::group((string) $k);
            if ($g !== null && (string) $k !== $g[2]) {
                $groups[$g[0]]['keys'][(string) $k] = $n;
                $groups[$g[0]]['limit'] = $g[1];
                $groups[$g[0]]['other'] = $g[2];
            }
        }
        foreach ($groups as $g) {
            if (count($g['keys']) <= $g['limit']) {
                continue;
            }
            arsort($g['keys']);
            foreach (array_slice($g['keys'], $g['limit'], null, true) as $k => $n) {
                unset($counts[$k]);
                $counts[$g['other']] = ($counts[$g['other']] ?? 0) + $n;
            }
        }
        // Referrers only for the pages not found that stayed on the list; a page's
        // times (0046) only for the pages whose views did -- the others' as "(other)".
        $past = [];
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            if (strncmp($k, 'nr:', 3) === 0 && !isset($counts['n:' . substr($k, 3, (int) strpos($k, '|') - 3)])) {
                unset($counts[$k]);
            } elseif (strncmp($k, 'p', 1) === 0 && ($k[1] === 't' || $k[1] === 's' || $k[1] === 'c') && ($k[2] ?? '') === ':') {
                $rest = (string) substr($k, 3);
                $page = $k[1] === 't' ? (string) substr($rest, 0, (int) strrpos($rest, '|')) : $rest;
                if (!isset($counts['pg:' . $page]) && substr($page, -8) !== '|(other)') {
                    $past[$page] = true;
                }
            }
        }
        if ($past !== []) {
            foreach ($counts as $k => $n) {
                $to = self::pageTime((string) $k, $past);
                if ($to !== (string) $k) {
                    unset($counts[$k]);
                    $counts[$to] = ($counts[$to] ?? 0) + $n;
                }
            }
        }
        return $counts;
    }

    /**
     * A day's or a month's file (a month: the days summed into it).
     *
     * @return array{hours: array<string, array<string, int>>, total: array<string, int>, last: array<string, array{0: int, 1: string}>, days: list<string>}
     */
    private static function load(string $file): array
    {
        $d = @json_decode((string) @file_get_contents($file), true);
        $d = is_array($d) ? $d : [];
        /** @var array{hours: array<string, array<string, int>>, total: array<string, int>, last: array<string, array{0: int, 1: string}>, days: list<string>} */
        return ['hours' => is_array($d['hours'] ?? null) ? $d['hours'] : [], 'total' => is_array($d['total'] ?? null) ? $d['total'] : [],
            'last' => is_array($d['last'] ?? null) ? $d['last'] : [], 'days' => is_array($d['days'] ?? null) ? array_values(array_filter($d['days'], 'is_string')) : []];
    }

    /** @param array<string, mixed> $d */
    private static function save(string $file, array $d): void
    {
        \CjwNetwork\RequestShield\Files::write($file, (string) json_encode($d, JSON_UNESCAPED_SLASHES));
    }

    /**
     * The family of a client that says it is a tool, not a browser, and is no
     * known crawler: python, curl, wget, go, java, node, php, perl, headless,
     * scrapy, empty, other (a "bot", "crawler", "spider" of its own). Null
     * for a browser.
     */
    public static function botFamily(string $userAgent): ?string
    {
        return Seen::family($userAgent);
    }
}
}
}

// plugins/stats/src/StatsExtension.php
namespace CjwNetwork\RequestShield\Stats {
use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

if (true) {
/**
 * The statistics' own settings, as an extension (0031 B.3, ADR 0008): the
 * `stats` words and `set` keys of the rule file, checked when the rules are
 * compiled into `ext.stats`, which StatsPlugin and the statistics' pages
 * read. The bootstrap names it (REQUEST_SHIELD_EXTENSIONS) and the registry
 * offers it on its first lookup; without plugins/stats (a smaller build)
 * `set stats on` is an unknown setting.
 *
 * Its pages (routes(), 0031 B.5) live below `set stats-path` (default
 * <dashboard-path>/stats): the registry in the compiled settings knows them,
 * the frame's tabs and the pace's exemption follow.
 */
final class StatsExtension implements Extension, ApiProvider
{
    /** What the statistics can count (set stats <parts>); `set stats on` counts these. */
    public const PARTS = ['requests', 'crawlers', 'not-found', 'bots', 'pages', 'forms'];

    /** Parts only counted when named (0046): `times`, how long the site took. */
    public const NAMED = ['times'];

    public static function id(): string
    {
        return 'stats';
    }

    /**
     * The compiled slot: every key there, the statistics off. What a reader
     * sees when the extension did not compile (no slot at all).
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, slow: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function defaults(): array
    {
        return ['enabled' => false, 'parts' => self::PARTS, 'hours' => 7, 'days' => 400, 'months' => 0, 'flush' => 60, 'depth' => 2, 'slow' => 2, 'path' => '/rs/stats', 'hosts' => [], 'skip' => [], 'groups' => [],
            'crawlerLog' => ['dir' => null, 'kinds' => [], 'days' => 30, 'query' => true]];
    }

    /**
     * The statistics' settings of $s: its compiled slot, or the defaults when
     * there is none -- a reader never sees a missing key.
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, slow: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function of(Settings $s): array
    {
        /** @var array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, slow: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}} */
        return $s->ext['stats'] ?? self::defaults();
    }

    public static function vocabulary(Vocabulary $v): void
    {
        // set stats on|off|<parts>: on, off, or what to count -- enabled and parts at once.
        $all = array_merge(self::PARTS, self::NAMED);
        $v->set('stats', 'words', 'on, off or what to count: ' . implode(', ', $all), static function ($words, string $at) use ($all): array {
            $words = array_map(static fn ($w): string => is_scalar($w) ? strtolower((string) $w) : '', is_array($words) ? $words : []);
            if ($words === ['on'] || $words === ['off']) {
                return ['enabled' => $words === ['on']];
            }
            foreach ($words as $w) {
                if (!in_array($w, $all, true)) {
                    throw new RuleFileException("$at: stats is on, off or what to count: " . implode(', ', $all) . " -- not \"$w\"");
                }
            }
            return ['enabled' => true, 'parts' => $words];
        }, null, false, true);
        $v->set('stats-flush', 'seconds', 'with APCu, seconds between writes of the counts to disk (0: only hourly)', null, 'flush');
        $v->set('stats-months', 'int', 'months the month totals are kept (0: for good)', null, 'months');
        $v->set('stats-depth', 'int', 'folder levels a section\'s views are counted for exactly: 1 to 4', null, 'depth');
        $v->set('stats-slow', 'seconds', 'with stats times: a request slower than this is written to the slow log (0: none)', null, 'slow');
        $v->set('stats-hours', 'int', 'days the hourly counters are kept', null, 'hours');
        $v->set('stats-days', 'int', 'days the daily counters are kept', null, 'days');
        $v->set('stats-path', 'string', 'where the statistics pages live (default <dashboard-path>/stats): /sites, /overview, /visitors, /protection below it', null, 'path');
        $v->set('stats-hosts', 'words', 'the websites with statistics of their own: names, *.domain, host, sites',
            static fn ($value, string $at): array => self::hostnames('stats-hosts', $value, $at), 'hosts', true);
        $v->set('crawler-log', 'path', 'one log per known crawler and day in this directory', null, 'crawlerLog.dir');
        $v->set('crawler-log-kinds', 'words', 'the kinds whose crawlers are logged', static function ($value, string $at): array {
            $kinds = array_map(static fn ($k): string => is_scalar($k) ? (string) $k : '', is_array($value) ? $value : []);
            foreach ($kinds as $k) {
                if (!in_array($k, RuleFile::KINDS, true)) {
                    throw new RuleFileException("$at: crawler-log-kinds takes kinds of crawler (" . implode(', ', RuleFile::KINDS) . "), not \"$k\"");
                }
            }
            return $kinds;
        }, 'crawlerLog.kinds');
        $v->set('crawler-log-days', 'int', 'days a crawler\'s log is kept', null, 'crawlerLog.days');
        $v->set('crawler-log-query', 'bool', 'whether the crawler logs keep the query string', null, 'crawlerLog.query');
        // stats-skip <paths>: not in the statistics when they pass (a map proxy's tiles); protected all the same.
        // stats-group "<name>" <websites>: a customer's websites, read together and each on its own;
        // the group's id (customer-a) is the principal dashboard-access names.
        $v->word('stats-group', static function (array $args, array $values, string $at, string $rid): array {
            $usage = 'stats-group "<name>" <websites> (stats-group "Customer A" a.de www.a.de)';
            $line = implode(' ', $args);
            if (preg_match('/^(?:"([^"]{1,60})"|([^"\s]{1,60}))\s+(\S.*)$/', trim($line), $m) !== 1) {
                throw new RuleFileException("$at: $usage");
            }
            $name = trim($m[1] !== '' ? $m[1] : $m[2]);
            $sites = [];
            foreach (preg_split('/\s+/', strtolower(trim($m[3]))) ?: [] as $site) {
                $site = rtrim($site, '.');
                if (preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $site) !== 1) {
                    throw new RuleFileException("$at: stats-group takes website names (www.example.org, *.example.org) after its name, not \"$site\"");
                }
                $sites[] = $site;
            }
            if ($name === '' || $sites === []) {
                throw new RuleFileException("$at: $usage");
            }
            $groups = is_array($values['groups'] ?? null) ? $values['groups'] : [];
            $groups[] = ['name' => $name, 'sites' => array_values(array_unique($sites)), 'rule' => $rid];
            $values['groups'] = $groups;
            return $values;
        }, 'stats-group "<name>" <websites>: a customer\'s websites, read together and each on its own', false, true);
        $v->word('stats-skip', static function (array $args, array $values, string $at, string $rid): array {
            if ($args === []) {
                throw new RuleFileException("$at: stats-skip needs at least one value");
            }
            /** @var list<string> $skip */
            $skip = is_array($values['skip'] ?? null) ? array_values($values['skip']) : [];
            if ($args[0] === 'none') {
                $skip = [];
                array_shift($args);
            }
            foreach ($args as $pattern) {
                if (!in_array($pattern, $skip, true)) {
                    $skip[] = $pattern;
                }
            }
            $values['skip'] = $skip;
            return $values;
        }, 'stats-skip <paths>: not counted when they pass', true);
    }

    /**
     * Website names (*.domain: one label), or "host" (the host rule's), "sites"
     * (the site blocks'), as `set stats-hosts` takes them.
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function hostnames(string $key, $value, string $at): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $name) {
            $name = rtrim(strtolower(is_scalar($name) ? (string) $name : ''), '.');
            if ($name !== 'host' && $name !== 'sites' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $name) !== 1) {
                throw new RuleFileException("$at: $key takes website names (www.example.org, *.example.org), host or sites -- not \"$name\"");
            }
            $out[] = $name;
        }
        return $out;
    }

    public static function compile(array $raw, Settings $base): array
    {
        $log = Settings::map($raw, 'crawlerLog', 'ext.stats.crawlerLog');
        $dir = $log['dir'] ?? null;
        if ($dir !== null && (!is_string($dir) || $dir === '')) {
            throw Settings::wrong('ext.stats.crawlerLog.dir', 'null or a directory');
        }
        $kinds = Settings::strings($log, 'kinds', 'ext.stats.crawlerLog.kinds');
        foreach ($kinds as $k) {
            if (!in_array($k, RuleFile::KINDS, true)) {
                throw Settings::wrong('ext.stats.crawlerLog.kinds', 'kinds of crawler: ' . implode(', ', RuleFile::KINDS));
            }
        }
        $depth = Settings::int($raw, 'depth', 'ext.stats.depth', 2);
        if ($depth < 1 || $depth > 4) {
            throw Settings::wrong('ext.stats.depth', '1 to 4 folder levels');
        }
        $parts = array_key_exists('parts', $raw) ? Settings::strings($raw, 'parts', 'ext.stats.parts') : self::PARTS;
        foreach ($parts as $p) {
            if (!in_array($p, array_merge(self::PARTS, self::NAMED), true)) {
                throw Settings::wrong('ext.stats.parts', implode(', ', array_merge(self::PARTS, self::NAMED)));
            }
        }
        $skip = [];
        foreach (is_array($raw['skip'] ?? null) ? $raw['skip'] : [] as $i => $p) {
            if (!is_string($p) || @preg_match($p, '') === false) {
                throw Settings::wrong("ext.stats.skip[$i]", 'a path pattern (stats-skip **/osm-proxy/** in a rule file)');
            }
            $skip[] = $p;
        }
        return [
            'enabled' => Settings::bool($raw, 'enabled', 'ext.stats.enabled'),
            'parts' => $parts,
            'hours' => max(1, Settings::int($raw, 'hours', 'ext.stats.hours', 7)),
            'days' => max(1, Settings::int($raw, 'days', 'ext.stats.days', 400)),
            'months' => max(0, Settings::int($raw, 'months', 'ext.stats.months', 0)),
            'flush' => max(0, Settings::int($raw, 'flush', 'ext.stats.flush', 60)),
            'depth' => $depth,
            'slow' => max(0, Settings::int($raw, 'slow', 'ext.stats.slow', 2)),
            'path' => self::path($raw, $base),
            'hosts' => self::hosts($raw, $base),
            'skip' => $skip,
            'groups' => self::groups($raw),
            'crawlerLog' => ['dir' => $dir, 'kinds' => $kinds, 'days' => max(1, Settings::int($log, 'days', 'ext.stats.crawlerLog.days', 30)),
                'query' => Settings::bool($log, 'query', 'ext.stats.crawlerLog.query', true)],
        ];
    }

    /**
     * Where the pages live: set stats-path, else <dashboard-path>/stats. The
     * plugin owns it; the core's pages stay at <dashboard-path>/waf/.
     *
     * @param array<string, mixed> $raw
     */
    private static function path(array $raw, Settings $base): string
    {
        $p = $raw['path'] ?? null;
        if ($p === null) {
            return $base->dashboardPath . '/stats';
        }
        // Names of letters, digits and . _ ~ -, but no "." or ".." of their own (as dashboard-path).
        if (!is_string($p) || !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $p) || preg_match('#/\.+(/|$)#', $p)) {
            throw Settings::wrong('ext.stats.path', 'a path such as /rs/stats or /admin/statistics');
        }
        return $p;
    }

    /**
     * The groups (stats-group): id (customer-a) => its name, its websites, the
     * rule -- the principal dashboard-access names is the id.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{name: string, sites: list<string>, rule: string}>
     */
    private static function groups(array $raw): array
    {
        $out = [];
        foreach ((array) ($raw['groups'] ?? []) as $i => $g) {
            if (!is_array($g) || !is_string($g['name'] ?? null) || !is_array($g['sites'] ?? null) || $g['sites'] === []) {
                throw Settings::wrong("ext.stats.groups[$i]", "['name' => a name, 'sites' => [websites]]");
            }
            $id = Settings::principal($g['name']);
            if ($id === '' || isset($out[$id])) {
                throw Settings::wrong("ext.stats.groups[$i]", "a name of its own -- \"{$g['name']}\" is " . ($id === '' ? 'empty' : 'used twice (or names the same as another: ' . $id . ')'));
            }
            $sites = [];
            foreach ($g['sites'] as $site) {
                if (is_string($site) && $site !== '') {
                    $sites[] = rtrim(strtolower($site), '.');
                }
            }
            $out[$id] = ['name' => $g['name'], 'sites' => $sites, 'rule' => is_string($g['rule'] ?? null) ? $g['rule'] : "ext.stats.groups[$i]"];
        }
        return $out;
    }

    /**
     * What a reader may see: the website switch's choice for a principal (its
     * own group, or one of the group's websites; else the whole group), null
     * for the administrator ('*'). A principal without a group sees nothing.
     */
    public static function siteFor(Settings $s, string $who, ?string $asked): ?string
    {
        if ($who === '*') {
            return $asked;
        }
        $sites = self::of($s)['groups'][$who]['sites'] ?? [];
        return $asked !== null && ($asked === 'group:' . $who || in_array($asked, $sites, true)) ? $asked : 'group:' . $who;
    }

    /**
     * The websites with statistics of their own: the names given, "host" for
     * the host rule's, "sites" for the site blocks' (not "default") -- and a
     * group's websites (stats-group), counted apart too without naming them twice.
     *
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function hosts(array $raw, Settings $base): array
    {
        $out = [];
        $named = (array) ($raw['hosts'] ?? []);
        foreach (self::groups($raw) as $g) {
            foreach ($g['sites'] as $site) {
                $named[] = $site;
            }
        }
        foreach ($named as $name) {
            if (!is_string($name)) {
                throw Settings::wrong('ext.stats.hosts', 'website names');
            }
            switch ($name) {
                case 'host':
                    $names = $base->hosts;
                    break;
                case 'sites':
                    $names = array_keys($base->sites);
                    break;
                default:
                    $names = [$name];
                    break;
            }
            foreach ($names as $n) {
                $n = rtrim(strtolower((string) $n), '.');
                if ($n !== '' && $n !== 'default' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $n) === 1) {
                    $out[$n] = true;
                }
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** StatsPlugin runs when something is counted or logged. */
    public static function plugins(array $compiled): array
    {
        $log = $compiled['crawlerLog'] ?? null;
        return ($compiled['enabled'] ?? false) === true || (is_array($log) && ($log['dir'] ?? null) !== null) ? [StatsPlugin::class] : [];
    }

    /**
     * The statistics' pages below their path: the start (all websites with
     * stats-hosts, else the overview), all websites (with stats-hosts; a
     * reader's), the overview (the admin's), visitors and pages and the
     * protection (a reader's). The core's Rules & setup, Live and Lists come
     * after them in the tabs.
     */
    public static function routes(array $compiled): array
    {
        $path = is_string($compiled['path'] ?? null) ? $compiled['path'] : '/rs/stats';
        $sites = ($compiled['hosts'] ?? []) !== [];
        return [$path => ['key' => $sites ? 'sites' : 'all', 'tab' => null, 'role' => $sites ? 'reader' : 'admin', 'order' => 5, 'page' => Report\StatsPage::class]]
            + ($sites ? [$path . '/sites' => ['key' => 'sites', 'tab' => ['All websites', 'Alle Websites'], 'role' => 'reader', 'order' => 10, 'page' => Report\StatsPage::class]] : [])
            + [
                $path . '/overview' => ['key' => 'all', 'tab' => ['Dashboard', 'Dashboard'], 'role' => 'admin', 'order' => 20, 'page' => Report\StatsPage::class],
                $path . '/visitors' => ['key' => 'site', 'tab' => ['Visitors & pages', 'Besucher & Seiten'], 'role' => 'reader', 'order' => 30, 'page' => Report\StatsPage::class],
                $path . '/protection' => ['key' => 'shield', 'tab' => ['Protection', 'Schutz'], 'role' => 'reader', 'order' => 40, 'page' => Report\StatsPage::class],
            ];
    }

    /** The statistics in the API (RSF06-05): the report of a period, and all websites at a glance -- a customer gets its group. */
    public static function api(): array
    {
        return [
            new Endpoint('GET', '/stats/report', 'reader', false, Api\Report::class, Api\Report::SCHEMA, 'RSF06-03',
                'What the counters say about a period: requests, what the shield did, pages, not found, crawlers, bots, forms, the sentences.', Api\Report::PARAMS),
            new Endpoint('GET', '/stats/sites', 'reader', false, Api\Sites::class, Api\Sites::SCHEMA, 'RSF06-03',
                'All websites at a glance: page views, people, crawlers, bots, stopped, not found, and the period before.', Api\Sites::PARAMS),
        ];
    }

    /** `request-shield stats <main.rules>`: the counters in words, or as JSON (0031 D.1). */
    public static function commands(): array
    {
        return ['stats' => Cli\StatsCommand::class];
    }

    public static function check(Settings $s): array
    {
        $o = self::of($s);
        $warnings = [];
        // A principal the dashboard lets in, but no group of that id: it sees no statistics.
        foreach ($s->dashboardAccess as $a) {
            if ($a['who'] !== '*' && !isset($o['groups'][$a['who']])) {
                $warnings[] = "{$a['rule']}: dashboard-access \"{$a['who']}\" -- no stats-group has that id" . ($o['groups'] === [] ? '' : ' (' . implode(', ', array_keys($o['groups'])) . ')')
                    . '; the token opens the dashboard, but shows no statistics';
            }
        }
        return $warnings;
    }
}
}
}

// plugins/stats/src/StatsPlugin.php
namespace CjwNetwork\RequestShield\Stats {
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Routes;
use CjwNetwork\RequestShield\RuleCounts;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;

if (true) {
/**
 * The statistics, as a plugin of the shield (proposal 0023): counts what the
 * shield decided per hour -- actions, rules, answers, known crawlers and their
 * pages, bot families, pages not found and their links, sitemaps, page views,
 * what was stopped where -- and writes the per-crawler logs (set crawler-log).
 *
 * Registered by `set stats on` (or `set crawler-log …`) on its own
 * (StatsExtension::plugins()); what it counts is set with the `stats` words
 * of the rule file, compiled into ext.stats (StatsExtension). A request the shield
 * answered itself is counted at once; one the site answers, when it has ended
 * (with the site's status).
 */
final class StatsPlugin implements Plugin, RuleCounts
{
    private Settings $settings;
    /** What the shield did to a page it stopped, as the statistics call it. */
    private const BLOCKED = [Decision::REJECT => 'refused', Decision::CHALLENGE => 'checked', Decision::THROTTLE => 'throttled'];

    private ?Stats $stats = null;

    /** @var list<string> the keys of a request that goes on to the site, counted when it ends */
    private array $pending = [];

    /** @var array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, slow: int, path: string, hosts: list<string>, skip: list<string>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}} the statistics' settings (ext.stats) */
    private array $o;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->o = StatsExtension::of($settings);
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
        $s = $this->settings;
        $o = $this->o;
        // stats-skip: a path that is no page of the site (a map proxy's tiles) -- not counted when it
        // passes; refused or checked, it still is (an attack there stays visible).
        // The dashboard's own pages (the statistics, the live view's feed every few
        // seconds): looking at the numbers must not change them. Two stripos first:
        // only a path under dashboard-path or stats-path asks the registry.
        $path = $request->matchPath();
        if ($decision->passes() && $would === null && (($o['skip'] !== [] && self::skipped($o['skip'], $path))
            || ((stripos($path, $s->dashboardPath) !== false || stripos($path, $o['path']) !== false) && Routes::match($s, $path) !== null))) {
            $this->waiting = false;
            return;
        }
        $parts = $o['enabled'] ? $o['parts'] : [];
        $keys = [];
        if (in_array('requests', $parts, true)) {
            $keys[] = 'a:' . $decision->action;
            if ($decision->action !== Decision::ALLOW && $rule !== null) {
                $keys[] = 'r:' . str_replace(' ', '_', $rule);
            }
            if ($would !== null) {
                $keys[] = 'm:' . $would->action;
            }
        }
        $crawling = in_array('crawlers', $parts, true);
        $paging = in_array('pages', $parts, true);
        // A crawler is looked at only for its statistics, the pages' visitors or its log.
        $log = $o['crawlerLog'];
        $id = !$crawling && !$paging && $log['dir'] === null ? null : $seen->crawler();
        $who = 'people';
        if ($id !== null) {
            $verified = $seen->verified();
            $who = $verified ? 'crawlers' : 'bots';
            if ($crawling) {
                $keys[] = "c:$id:seen";
                $keys[] = "c:$id:" . ($verified ? 'verified' : 'claimed');
            }
            if ($verified && $crawling) {
                $keys[] = "c:$id:" . [Decision::ALLOW => 'allowed', Decision::ALLOW_UNCACHED => 'allowed', Decision::CHALLENGE => 'checked',
                    Decision::THROTTLE => 'throttled', Decision::REJECT => 'refused'][$decision->action];
                if ($request->path === '/robots.txt') {
                    $keys[] = "c:$id:robots";
                }
                // The page, without its query; spaces and the like escaped (a key is one word).
                $keys[] = "p:$id:" . self::word($request->path);
                $keys[] = "l:$id|" . (int) $now . '|' . $request->clientIp;
                if (self::isSitemap($request->path)) {
                    // Which crawler read which sitemap, and when last: does it see the new content?
                    $keys[] = 'smc:' . self::word($request->path) . "|$id";
                    $keys[] = 'l:sitemap:' . self::word($request->path) . "@$id|" . (int) $now . '|-';
                }
            }
            if ($log['dir'] !== null && ($log['kinds'] === [] || in_array($seen->crawlerKind(), $log['kinds'], true))) {
                // A verified crawler's address is its operator's: in full. One that
                // only claims the name may be a person: as the log keeps addresses.
                Log::append($log['dir'] . '/' . $id . '/' . date('Y-m-d', (int) $now) . '.log',
                    Log::line($s, $request, $verified ? $decision : $decision->claiming($id), $rule, $now, false, $verified ? $request->clientIp : null, $log['query']),
                    $s->logMaxSize);
            }
        } elseif (in_array('bots', $parts, true) || $paging) {
            $family = $seen->botFamily();
            if ($family !== null) {
                $who = 'bots';
                if (in_array('bots', $parts, true)) {
                    $keys[] = 'o:' . $family;
                }
            }
        }
        // A page the shield stopped: which, and how (refused, checked, told to
        // wait) -- whoever asked. Its query left out, as for page views.
        if ($paging && isset(self::BLOCKED[$decision->action])) {
            $keys[] = 'pb:' . self::BLOCKED[$decision->action] . '|' . self::word($request->path);
        }
        // A form sent (proposal 0028): which, from which page, how it ended. Never what was typed.
        $this->formOut = null;
        $forms = in_array('forms', $parts, true);
        if ($forms && isset(self::FORM_METHODS[$request->method]) && !self::isApi($s, $request)) {
            $area = self::backendOf($s, $request->matchPath());
            if ($area !== null) {
                // The editors' area: one entry per area, not per address.
                $keys[] = 'fb:' . self::word($area);
                $out = 'fbo:' . self::word($area) . '|';
            } else {
                $form = self::word($request->path);
                $keys[] = 'f:' . $form;
                $keys[] = 'ff:' . $form . '|' . self::sentFrom($s, $request);
                $out = 'fo:' . $form . '|';
            }
            if (isset(self::BLOCKED[$decision->action])) {
                $keys[] = $out . ($decision->reason === 'cross-site' ? 'cross-site' : self::BLOCKED[$decision->action]);
            } else {
                $this->formOut = $out;                  // saved or an error: the site's answer, when it has ended
            }
        }
        if (!$o['enabled']) {
            return;
        }
        $stats = $this->stats;
        if ($stats === null) {
            // stats-hosts: the website's own statistics (a name the rules do not know: "other").
            $site = Stats::siteOf($s, $seen->host());
            $stats = $this->stats = Stats::of($s, $site);
            if ($site !== null) {
                self::tendOthers($s, $site, $now);
            }
        }
        $requests = in_array('requests', $parts, true);
        if ($requests && $who === 'people') {
            $stats->minute($now);           // "now" on the visitors page: one APCu counter a minute
        }
        $timing = in_array('times', $parts, true);
        if (!$continues || (!$requests && !in_array('not-found', $parts, true) && !$crawling && !$paging && $this->formOut === null && !$timing)) {
            if ($requests) {
                $keys[] = 's:' . $decision->status;          // the shield answered itself
            }
            if ($crawling && self::isSitemap($request->path)) {
                $keys[] = 'sm:' . self::word($request->path) . '|' . $decision->status;
            }
            if ($keys !== []) {
                $stats->count($keys, $now);
            }
            $this->pending = [];
            return;
        }
        // Goes on to the site: counted when it ends, with the status the site answered.
        $this->pending = $keys;
        $this->who = $who;
        $this->waiting = true;
        // times (0046): the shield's own share ends here -- its decision is made.
        $this->decidedAt = $timing ? microtime(true) : 0.0;
    }

    /** @var float when the shield had decided a request that goes on to the site (part times); 0: not timed */
    private float $decidedAt = 0.0;

    /** @var int the hour (since 1970) this process last tended the other websites' statistics */
    private static int $tended = -1;

    /**
     * Once per process and hour: the websites this request does not count in
     * have their finished hour rolled up and APCu written out too -- a quiet
     * website would otherwise wait for its next visitor.
     */
    private static function tendOthers(Settings $s, string $site, float $now): void
    {
        $hour = intdiv((int) $now, 3600);
        if (self::$tended === $hour) {
            return;
        }
        self::$tended = $hour;
        foreach (array_merge(StatsExtension::of($s)['hosts'], [Stats::OTHER]) as $name) {
            if ($name !== $site) {
                Stats::of($s, $name)->tend($now);
            }
        }
    }

    private string $who = 'people';

    /** @var ?string the key a form's outcome is counted under ("fo:/kontakt|", "fbo:/admin/**|"), when the site answers it */
    private ?string $formOut = null;

    /** The methods that send a form. */
    private const FORM_METHODS = ['POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true];

    /** Whether a request goes to the site's API (api-path): counted as now, not as a form. */
    private static function isApi(Settings $s, Request $request): bool
    {
        foreach ($s->challenge->apiPaths as $p) {
            if (@preg_match($p, $request->matchPath()) === 1) {
                return true;
            }
        }
        return false;
    }

    /** The editors' area a path is in (backend <paths>), as written: "/admin/**"; null outside. */
    private static function backendOf(Settings $s, string $path): ?string
    {
        foreach ($s->backend as $p) {
            if (@preg_match($p, $path) === 1) {
                return $s->origin('written', $p) ?? $p;
            }
        }
        return null;
    }

    /**
     * Where a form was sent from, as a word: the path of the website's own page
     * (the Referer's, without its query), "@<host>" for another website (its
     * host only), "=" for this website without the page (an Origin, no Referer),
     * "-" when the browser does not say.
     */
    private static function sentFrom(Settings $s, Request $request): string
    {
        $ref = trim((string) $request->header('referer'));
        $origin = trim((string) $request->header('origin'));
        $source = $ref !== '' ? $ref : ($origin !== '' && $origin !== 'null' ? $origin : '');
        if ($source === '') {
            return '-';
        }
        $host = parse_url($source, PHP_URL_HOST);
        $host = is_string($host) ? rtrim(strtolower($host), '.') : '';
        $own = Shield::ownNames($s);
        $mine = $host !== '' && ($own === [] ? $host === $request->host : self::named($host, $own));
        if (!$mine) {
            return '@' . ($host === '' ? '?' : self::word($host));
        }
        if ($ref === '') {
            return '=';                                 // this website (its Origin), the page not said
        }
        $path = parse_url($ref, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? self::word($path) : '/';
    }

    /** @param list<string> $names */
    private static function named(string $host, array $names): bool
    {
        foreach ($names as $name) {
            if ($name === $host || (strncmp($name, '*.', 2) === 0 && strlen($host) > strlen($name) - 1 && (string) substr($host, 1 - strlen($name)) === (string) substr($name, 1)
                && strpos((string) substr($host, 0, 1 - strlen($name)), '.') === false)) {
                return true;
            }
        }
        return false;
    }

    private bool $waiting = false;

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
        if (!$this->waiting || $this->stats === null) {
            return;
        }
        $this->waiting = false;
        $parts = $this->o['parts'];
        $keys = $this->pending;
        $viewed = null;                 // "<who>|<path>" of a page view: its time is counted too (0046 step 2)
        if ($status > 0) {
            $requests = in_array('requests', $parts, true);
            $missing = in_array('not-found', $parts, true);
            $crawling = in_array('crawlers', $parts, true);
            foreach (self::statusKeys($request, $status) as $k) {
                if (strncmp($k, 'sm:', 3) === 0 ? $crawling : (strncmp($k, 's:', 2) === 0 ? $requests : $missing)) {
                    $keys[] = $k;
                }
            }
            // A form: saved (2xx, 3xx: the usual redirect after saving) or an error (4xx, 5xx).
            if ($this->formOut !== null) {
                $keys[] = $this->formOut . ($status < 400 ? 'saved' : 'error');
            }
            // A page view: GET, 200, HTML -- counted by who came.
            if (in_array('pages', $parts, true) && $status === 200 && $request->method === 'GET' && self::isHtml($headers)) {
                $viewed = $this->who . '|' . self::word($request->path);
                $keys[] = 'pg:' . $viewed;
                // Its first folders too (stats-depth, 2: /news/, /news/2026/): how many views a subtree got, exactly.
                foreach (self::folders($request->path, $this->o['depth']) as $folder) {
                    $keys[] = 'pd:' . $this->who . '|' . self::word($folder);
                }
            }
        }
        $amounts = [];
        if ($this->decidedAt > 0.0) {
            // How long the site took (0046): from the web server's start of the request to here -- the
            // site's main script is done; its own shutdown work comes after (it registered later).
            $end = microtime(true);
            $start = is_numeric($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $now;
            $us = max(0, (int) round(($end - $start) * 1e6));
            [$cache, $why] = self::cacheKind($status, $headers, $this->settings);
            $keys[] = 'rt:' . $this->who . '|' . $cache . '|' . Stats::band($us);
            $amounts['rs:' . $this->who . '|' . $cache] = $us;
            $amounts['rq:shield'] = max(0, (int) round(($this->decidedAt - $now) * 1e6));
            if ($why !== null) {
                $keys[] = 'rn:' . $why;
            }
            // A page view's time (step 2): its band, its sum, whether it came from the cache --
            // after its "pg:" key, so the same pages are kept (Stats::count()).
            if ($viewed !== null) {
                $keys[] = 'pt:' . $viewed . '|' . Stats::band($us);
                $amounts['ps:' . $viewed] = $us;
                if ($cache === 'hit') {
                    $keys[] = 'pc:' . $viewed;
                }
            }
            $slow = $this->o['slow'];
            if ($slow > 0 && $us >= $slow * 1000000) {
                // The slow log: which page, how slow, how much memory -- no address, no query.
                $this->stats->slow(gmdate('Y-m-d\TH:i:s\Z', (int) $end) . ' ' . self::word($request->method) . ' ' . self::word($request->path) . ' ' . $status
                    . ' ' . intdiv($us, 1000) . 'ms ' . number_format(memory_get_peak_usage() / 1048576, 1, '.', '') . 'MB ' . $cache . ' ' . $this->who,
                    $end, $this->settings->logMaxSize);
            }
            $this->decidedAt = 0.0;
        }
        if ($keys !== [] || $amounts !== []) {
            $this->stats->count($keys, $now, $amounts);
        }
    }

    /**
     * What the HTTP cache did with a request (0046), from the header it sets:
     * "hit" (answered from it), "miss" (asked the site, the answer may be
     * kept), "nostore" (asked the site, the answer may not be kept -- and why,
     * CachePlugin::refusal(); "miss; role": a role's page -- its Cache-Control is
     * the shield's then, so only what refuses any page counts: a role's page
     * the site sent private, with only a Vary on the hash, counts as a miss), "past" (no cache asked: off, not cacheable, not
     * anonymous, a POST).
     *
     * @param list<string> $headers headers_list()
     * @param ?Settings $s the settings, for the cache's own ttl (http-cache-ttl 0: nothing is kept without a max-age)
     * @return array{0: string, 1: ?string} the kind, and why a miss is not kept
     */
    public static function cacheKind(int $status, array $headers, ?Settings $s = null): array
    {
        $app = null;
        foreach ($headers as $h) {
            if (strncasecmp($h, 'x-exp-cache:', 12) === 0) {
                $app = strtoupper(trim((string) substr($h, 12)));      // Exponential 6's own HTTP cache (exphttpcache, 0048)
            }
            if (strncasecmp($h, 'x-rs-cache:', 11) !== 0) {
                continue;
            }
            $v = strtolower(trim((string) substr($h, 11)));
            if ($v === 'hit') {
                return ['hit', null];
            }
            if ($v === 'miss' || $v === 'miss; role') {
                // The header comes from the cache plugin: its rule says whether the answer may be kept.
                if (!class_exists(\CjwNetwork\RequestShield\Cache\CachePlugin::class)) {
                    return ['miss', null];
                }
                $ttl = $s !== null ? \CjwNetwork\RequestShield\Cache\CacheExtension::of($s)['ttl'] : 1;
                // A role's page (miss; role): its private, no-cache is the cache's own -- what the site sent was
                // checked when it was kept; only what refuses any page counts here.
                $why = \CjwNetwork\RequestShield\Cache\CachePlugin::refusal($status, $headers, $ttl, $v === 'miss; role');
                return $why === null ? ['miss', null] : ['nostore', $why];
            }
        }
        if ($app !== null) {
            // The application's cache (X-Exp-Cache: HIT | STALE | MISS (reason) | BYPASS (reason)): its hits and misses
            // count as the shield's would -- a stale page came from the cache too; a bypass is a page it may not keep.
            if (strncmp($app, 'HIT', 3) === 0 || strncmp($app, 'STALE', 5) === 0) {
                return ['hit', null];
            }
            if (strncmp($app, 'MISS', 4) === 0) {
                return in_array($status, [200, 301, 308], true) ? ['miss', null] : ['nostore', 'status'];     // as the shield's: an error page is not kept
            }
            if (strncmp($app, 'BYPASS', 6) === 0) {
                return ['nostore', 'app-bypass'];
            }
        }
        return ['past', null];
    }

    /** @param list<string> $patterns */
    private static function skipped(array $patterns, string $path): bool
    {
        foreach ($patterns as $p) {
            if (@preg_match($p, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /** A path as one word of a counter's name: spaces, "*" (a count in the file store) and the like escaped. */
    private static function word(string $v): string
    {
        return substr((string) preg_replace_callback('/[^\x21-\x29\x2b-\x7b\x7d\x7e]/', static fn (array $m): string => rawurlencode($m[0]), $v), 0, 200);
    }

    /**
     * The first folders of a path, $depth of them: /news/2026/10/x -> /news/,
     * /news/2026/ (2); /news/ -> /news/ (its own page belongs to it).
     *
     * @return list<string>
     */
    public static function folders(string $path, int $depth = 2): array
    {
        $parts = explode('/', trim($path, '/'));
        if (substr($path, -1) !== '/') {
            array_pop($parts);                  // the page itself; a folder's own page (/news/) belongs to it
        }
        $out = [];
        $prefix = '/';
        foreach (array_slice($parts, 0, $depth) as $part) {
            if ($part === '') {
                break;
            }
            $prefix .= $part . '/';
            $out[] = $prefix;
        }
        return $out;
    }

    /**
     * Whether the answer is a page: its Content-Type is HTML, or none was set
     * (PHP's default is text/html).
     *
     * @param list<string> $headers headers_list()
     */
    public static function isHtml(array $headers): bool
    {
        foreach ($headers as $h) {
            if (strncasecmp($h, 'content-type:', 13) === 0) {
                return stripos($h, 'text/html') !== false || stripos($h, 'application/xhtml') !== false;
            }
        }
        return true;
    }

    /** sitemap.xml, sitemap_index.xml, sitemap-news.xml, …, also .gz, in any folder. */
    public static function isSitemap(string $path): bool
    {
        return preg_match('~(?:^|/)sitemap[\w.-]*\.xml(?:\.gz)?$~i', $path) === 1;
    }

    /**
     * The status the site answered; for a page it did not find (404, 410),
     * the page and where a link to it was: a page of the site itself (a broken
     * link to fix), or the other site's host -- never a whole foreign URL.
     *
     * @return list<string>
     */
    public static function statusKeys(Request $request, int $status): array
    {
        $keys = ['s:' . $status];
        if (self::isSitemap($request->path)) {
            $keys[] = 'sm:' . self::word($request->path) . '|' . $status;     // which sitemaps exist: 200, 404
        }
        if ($status !== 404 && $status !== 410) {
            return $keys;
        }
        $path = self::word($request->path);
        $keys[] = 'n:' . $path;
        $ref = (string) $request->header('referer');
        $parts = $ref === '' ? false : parse_url($ref);
        if (is_array($parts) && isset($parts['host'])) {
            $own = strcasecmp($parts['host'], $request->host) === 0;
            $keys[] = 'nr:' . $path . '|' . ($own ? self::word($parts['path'] ?? '/') : self::word(strtolower($parts['host'])));
        }
        return $keys;
    }

    /** How often each rule decided (RuleCounts, 0031 B.8): from the counters, when they are kept. */
    public function ruleCounts(int $days, float $now): array
    {
        if (!$this->o['enabled']) {
            return [];
        }
        /** @var array<string, int> $rules */
        $rules = Report\StatsReport::build($this->settings, null, $days, (int) $now)['rules'];
        return $rules;
    }

    /** What the known crawlers did (RuleCounts): from the counters, when they are kept. */
    public function crawlerCounts(int $days, float $now): ?array
    {
        if (!$this->o['enabled']) {
            return null;
        }
        /** @var array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}> $crawlers */
        $crawlers = Report\StatsReport::build($this->settings, null, $days, (int) $now)['crawlers'];
        return $crawlers;
    }
}
}
}
