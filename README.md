# Exponential Request Shield (exp_request_shield)

A filter in front of an Exponential site. It decides every request **before the
kernel starts**: it turns away scanners, floods and stray form posts, keeps page
caches clean and makes bots pay with a browser check, for a few microseconds per
request and on any hosting, shared hosting included.

The filter is [request-shield](https://github.com/cjw-network/request-shield)
by JAC Systeme GmbH (CJW Network). This extension packages it for Exponential:

- **The library, bundled.** `lib/requestshield/` holds request-shield v0.4.0 as
  single PHP files (the core plus the statistics, API, firewall-pages and HTTP
  cache parts), and a PHP 7.4 build of the same files. Nothing else to install,
  no Composer autoloader needed.
- **One line in `config.php`.** The hook protects every request under Apache or
  nginx with PHP-FPM and under Exponential Velocity, before the HTTP cache's
  early exit, so cached pages are protected too.
- **Rules for Exponential, ready to use.** The library's Exponential rule set
  (system URLs, internal files, forms, the search, the login, the admin, image
  aliases made on demand), **enforcing as installed**: the protection is on and
  stays on.
- **Setup > Request shield in the admin.** Switches at the top: pause and
  switch on, enforce, monitor or off. A dashboard with a chart of the
  decisions per hour, the rules that decided most and the newest log lines; the
  whole log by period and decision; the rule files and whether they compile. In
  the admin4 and admin4l designs, exp_adminui's adminui design and the classic
  admin design, in light and dark mode.
- **The installation's own requests pass.** The preloader, the address checks
  after an upgrade and static publishing sign their requests (`X-Exp-Self`, an
  HMAC with a key only the installation has); scripts of your own can too.
- **The library's command line** with every part loaded:
  `bin/request-shield check|test|trace|replay|show ...`.

## What it does, as installed

| Request | What the shield does | Rule |
|---|---|---|
| `/wp-login.php`, `/.env`, `/backup.zip`, `/package.json` | 404 before the kernel starts | built-in scanner, WordPress and devtools sets |
| `/settings/site.ini`, `/var/site/cache/...`, `*.tpl` | 404 | `EXP-INTERNAL`, `EXP-INI` |
| `/content/view/full/2` in the frontend | 404 (pages are reached by their alias; only the admin needs node numbers) | `EXP-SYSVIEW` |
| `/setup/info`, `/class/grouplist` in the frontend | 404 (admin modules) | `EXP-MODULES` |
| a POST to a page that takes no form | 405 | `EXP-POST` |
| a form posted from another website | 403 | `EXP-ORIGIN` |
| the login, registration, password forms | the browser check first (a second's work for a real browser) | `EXP-LOGIN` |
| contact forms, comments, newsletter sign-up | the browser check once per half hour | `EXP-FORMS` |
| more than 10 searches a minute | the browser check; solved, the counter starts again | `EXP-SEARCHES` |
| more than 120 pages a minute | the browser check | `EXP-PACE` |
| more than 3000 image aliases a minute | the browser check | `EXP-IMAGES` |
| SQL, script or path attacks in the query or headers | 403 | `@attacks` |
| an unknown host name | 404 | `host` (in the site's file) |
| the administration | the browser check once per pass, every admin module open | `EXP-ADMIN-*`, `SITE-ADMIN-*` |
| Google, Bing and AI crawlers verified by their published addresses | welcome, never the browser check | `crawlers ... allow` |
| networks on Spamhaus DROP and abuse.ch Feodo | 403 | `feed` |

Every decision names its rule in the log, on the dashboard and in `trace`.

## What you can configure

Each of these is a few lines in the site's rule file, with examples that prove
them. [doc/cookbook.md](doc/cookbook.md) has every one ready to copy;
[doc/reference.md](doc/reference.md) lists every rule line and setting with its
default.

| You want to | With |
|---|---|
| answer only the site's own host names, refuse admin modules behind every URI siteaccess | `host`, `replace [EXP-MODULES]` |
| make the search, downloads, PDFs, help PDFs or keyword lists cost a bot more than a person | a budget per area (`limit ... at`, `match`), or `challenge` |
| check the login more often, limit registrations | `replace [EXP-LOGIN]`, `limit registrations` |
| take forms of your own extensions, the REST API with PUT, PATCH and DELETE, webhooks | `allow`, `method`, `post-origin except`, `challenge-exempt` |
| open the administration only to the office or the VPN | `restrict /admin/** to ...`, `exempt` |
| let monitors, partners and your own scripts through | `exempt ... until`, `expRequestShield::selfHeader()` |
| give image-heavy pages and busy intranets room | `replace [EXP-IMAGES]`, `replace [EXP-PACE]` |
| run behind Cloudflare, a load balancer, nginx or LiteSpeed | `trust`, `set secret`, `set store file` |
| watch a rule before it refuses anybody | `set mode monitor`, `monitor <rule>` |
| ban scanners, keep addresses out, welcome or block crawlers | `ban`, `deny`, `feed`, `crawlers` |
| show your own texts and error pages | `set text.<lang>.<key>`, `set error-page` |
| prove every rule before it is live | `expect` lines, `bin/request-shield test`, `trace`, `replay` |

A short example, in `settings/request-shield/request-shield.rules`:

```
ids SITE
version 2026-11-15.1

include     exponential-admin-uri.rules                     # the shipped rules, copied next to this file
set         mode enforce
host        www.example.org example.org
set         store-dir ../../var/request-shield/store
set         log       ../../var/request-shield/log/shield.log
set         log-level flag
[SITE-DASH] restrict /rs/** to 127.0.0.1 ::1                 # the library's own pages: only from the server
set         api off

match /content/download/** {
  [SITE-DOWNLOADS] limit downloads 30/min on-exceeded challenge   # downloads: 30 a minute per visitor, then the check
  expect GET /content/download/12/34/file/report.zip  times 30   answered
  expect GET /content/download/12/34/file/report.zip  times 31   check
}

[SITE-HOOK-POST]    allow POST /webhook/**                  # a payment provider posts here
[SITE-HOOK-ORIGIN]  post-origin except /webhook/**          # from its servers, not from a page of the site
[SITE-HOOK-NOCHECK] challenge-exempt /webhook/**            # a server solves no browser check
expect POST /webhook/payments                               answered
```

```bash
php extension/exp_request_shield/bin/request-shield check settings/request-shield/request-shield.rules
php extension/exp_request_shield/bin/request-shield test  settings/request-shield/request-shield.rules
```

## Requirements

- Exponential 6.0. Under PHP-FPM (Apache, nginx) the hook needs nothing from the
  kernel. Under Exponential Velocity, `index.php` must run the file named in
  `EXP_REQUEST_PREPEND_FILE` (the 6.0.15 line; see [doc/velocity.md](doc/velocity.md)).
  The signed own requests need the kernel from 2026-11-14 on.
- PHP 7.4 to 8.5. On PHP 8.0 and newer the library's own files are loaded; on PHP
  7.4 the same files with their syntax downgraded (`lib/requestshield/php74/`).
  APCu is optional (counters in shared memory; files without it).

## Quick start

```bash
composer require se7enxweb/exp_request_shield
```

1. Activate the extension (`ActiveExtensions[]=exp_request_shield` in
   `settings/override/site.ini.append.php`).
2. Add the hook to `config.php`, above the HTTP cache's early exit:
   ```php
   if ( is_file( __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php' ) )
       require __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php';
   ```
3. Give the site its own rules: copy `rules/request-shield.rules` and the three
   `rules/exponential*.rules` to `settings/request-shield/`, then name your host
   names and fix the paths ([doc/configuration.md](doc/configuration.md)). Or
   press any mode switch in Setup > Request shield, which makes the copies.
4. Regenerate the autoloads, clear the caches, and open Setup > Request shield.
5. Read Setup > Request shield > Log for anything a real visitor or editor did,
   and add an exception for it (or `set mode monitor` while you look).

The full procedure, with Velocity, permissions and checks: [INSTALL.md](INSTALL.md).

## After an update

The site's copies in `settings/request-shield/` are written once and never
changed by an update. Compare them with `extension/exp_request_shield/rules/`
after every update, and read [doc/CHANGELOG.md](doc/CHANGELOG.md) for rules that
changed ([doc/cookbook.md](doc/cookbook.md#after-an-update-of-the-extension)).

## Documentation

| Document | What it covers |
|---|---|
| [INSTALL.md](INSTALL.md) | Installing, activating, the hook, first checks, uninstalling |
| [doc/configuration.md](doc/configuration.md) | The main rule file, where it is found, the paths, the constants, monitor and enforce |
| [doc/reference.md](doc/reference.md) | Every setting, every rule line and `set` key with its default, the order of the checks, every shipped rule by ID, the `expect` line, the log line, signed own requests |
| [doc/cookbook.md](doc/cookbook.md) | Recipes to copy, each with its examples: search, downloads and PDFs, login, forms, REST API, admin, webhooks, monitoring, own scripts, images, Cloudflare, nginx, LiteSpeed, several servers, shared hosting, monitor rollout, bans, deny lists, texts and error pages, the log, testing |
| [doc/troubleshooting.md](doc/troubleshooting.md) | Symptoms, causes and fixes: 429 for real visitors, 405, preloaders, proxies, shared hosting |
| [doc/admin.md](doc/admin.md) | Setup > Request shield: the dashboard, the log, the rules; the policy; the designs |
| [doc/how-it-works.md](doc/how-it-works.md) | The request path under PHP-FPM and under Velocity, own requests, failing open, the files on disk |
| [doc/velocity.md](doc/velocity.md) | Exponential Velocity specifics |
| [doc/dashboard.md](doc/dashboard.md) | The library's own pages under `/rs` and how to open them safely |
| [doc/cli.md](doc/cli.md) | `bin/request-shield`: check, test, trace and the rest |
| [doc/library.md](doc/library.md) | The bundled library, its version, and how to update it |
| [doc/CHANGELOG.md](doc/CHANGELOG.md) | Changes per release |

The same reference, cookbook and troubleshooting pages, with the administrator's
help for Setup > Request shield, are part of the Exponential documentation in
English and German:
[Request shield: start here](https://github.com/se7enxweb/exponential/blob/main/doc/eng-us/help/request-shield/README.md).
The library's own documentation (every rule, every setting, the use case for
Exponential): https://github.com/cjw-network/request-shield/tree/main/docs

## License

The extension is licensed under the GNU General Public License v2.0 or any later
version ([LICENSE](LICENSE)). The bundled request-shield library in
`lib/requestshield/` and the three Exponential rule files copied from it stay
under their own MIT License. See [LICENSE.md](LICENSE.md).
