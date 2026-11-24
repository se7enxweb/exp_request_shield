# Changelog

## Unreleased

- Fixed: A page the browser loads itself always gets the HTML check page, never the JSON task. A navigation
  (`Sec-Fetch-Mode: navigate`, or `Sec-Fetch-Dest: document`) was answered as an API when its path matched an
  `api-path` rule or its request came through a service worker, so the browser showed the raw JSON challenge instead
  of "One moment, please" (exponential#367). Background calls (`fetch()`, `XMLHttpRequest`) still get JSON.

## 0.0.1.7

- Fixed: The passkeys, sessions, security, deactivation and two-step sign-in forms are no longer refused with 405.
  `EXP-POST` let a POST through only at the older account forms, so adding or removing a passkey
  (`/user/passkeys`), signing out other sessions (`/user/sessions`), the security page (`/user/security`), deactivating
  an account (`/user/deactivate`) and the second sign-in step and its setup (`/user2fa/...`) were answered 405. They
  are allowed now, with `expect` lines for each. Sites that copied `EXP-POST` into a rule file of their own should
  take the new line.
- Fixed: Setup > Ini settings and the template views work in the administration. `EXP-INI` (`*.ini`, `*.tpl`,
  anywhere) refused `/admin/settings/view/admin/site.ini`, `/admin/settings/edit/...` and
  `/admin/visual/templateview|templateedit|templatecreate/...` with 404: those are module views named after a file,
  not the file. `EXP-ADMIN-INI` (admin as `/admin`) and `EXP-AH-INI` (admin on a host of its own) take the block back
  at those paths only; on an admin host `EXP-AH-SETTINGS` also takes `EXP-INTERNAL` back for `/settings/view|edit/**`
  (the settings module, not the `settings/` folder). Every other address named `.ini` or `.tpl` stays refused.
- Fixed: The layout editor no longer meets the pace check while an editor works. Its API
  (`/admin/explayouts_ui_api/app/api/...`) asks 10 to 30 times for one opening, one drag or one publish, and the
  admin's ezjscore calls poll; counted as pages, `EXP-PACE` answered them 429 past 300 a minute and every call of the
  editor failed. They are left out of `EXP-PACE` and counted by `EXP-ADMIN-API` (1800 a minute per visitor); on an
  admin host `EXP-AH-PAGES` and `EXP-AH-API` do the same. A frontend's editor calls still count as pages. Sites that
  copied the `replace [EXP-PACE]` recipe should take the new one.

## 0.0.1.6

- Added: A configuration reference, `doc/reference.md`: where the configuration lives, the constants, `requestshield.ini`
  and the cronjob part, the files on disk, the rule file format, the order a request is checked in, every rule line and
  every `set` key with its default and effect, every shipped Exponential rule by ID, the `expect` line, the log line
  and the signed own requests.
- Added: A cookbook, `doc/cookbook.md`: 26 recipes to copy, each with `expect` examples that `request-shield test`
  passes against the shipped rules: host names and URI siteaccesses, the search, downloads and PDFs, help PDFs, login
  and registration, forms of your own extensions, the REST API, the administration (office only, other admin
  siteaccesses), webhooks, monitoring and uptime checks, signing your own scripts' requests (`selfHeader()`),
  image-heavy pages and busy intranets, page aliases that look like system paths, print layouts and keyword lists,
  query parameters, Cloudflare and other proxies, nginx, Apache, LiteSpeed and Velocity in front, several web servers,
  shared hosting, a monitor mode rollout, bans, deny and allow lists, own texts and error pages, reading the log,
  testing with `expect`, `trace` and `replay`, and refreshing the rule copies after an update.
- Updated: `doc/troubleshooting.md` covers 429 for real visitors (what each log line means), 405 for forms, the REST
  API and the layout editor, preloaders and own scripts, a proxy, CDN or second web server in front (nginx on the
  same machine made every visitor `127.0.0.1`, which is exempt), shared hosting, a `replace` that counted everywhere,
  and rule copies that an update does not change.
- Updated: The README says what the shield does as installed, rule by rule, and what can be configured, with an
  example; the default rule file `rules/request-shield.rules` ends with the cookbook's recipes, commented out. Its
  rules are unchanged: the compiled settings are the same as in 0.0.1.5.

## 0.0.1.5

- Added: The installation's own requests pass: Setup > Preload sites, the address checks after an upgrade and static
  publishing sign each request to the site's own pages (`X-Exp-Self`, an HMAC with a key only the installation has,
  valid two minutes and only for that address), and `protect()` lets a signed request through before the shield
  runs. Before, the pace limit answered the preloader with the browser check (429) after 120 pages, and the
  upgrade's check of `/user/login` failed. Needs Exponential 6.0.15 from 2026-11-14 on (the kernel sends the header).

## 0.0.1.4

- Updated: The admin rules let the layout editor (Exponential Layouts) save and discard drafts: `PUT`, `PATCH` and
  `DELETE` are allowed for the editor's API only (`EXP-ADMIN-SPA` below `/admin/explayouts_ui_api/`, `EXP-AH-SPA` on
  an admin host), and refused everywhere else as before. Without them, "discard draft" in the editor answered
  405 ("Something went wrong") on every site with the shield. A site's copy of the admin rules is written once,
  when its rule file is made: take these two rules into it, or the new copy.
- Added: The dashboard shows how many browser checks were passed (the answers that set a pass), next to how many
  were sent, and the activity summary counts both.
- Updated: The default rules give images a budget of their own (`EXP-IMAGES`, 3000 a minute per visitor, then the
  browser check) and leave them out of the pace (`EXP-PACE`, still the check past 120 pages a minute). Exponential
  6.0.15 makes image aliases on demand: a page with hundreds of new images asks `content/image`, or
  `index_image.php` for a storage image that is not there yet, once per image, and the pace counted each of those
  as a page view, so a page of 500 new images got a few hundred answers 429 and never finished loading. The default
  budget `requests` is switched off (`no-limit requests`) and the pace is the budget `pages`. A site rule file that
  restated the pace the same way (a `limit pages` of its own) must drop it when it takes this copy.

## 0.0.1.3

- Updated: The default rules say out loud that search engines and AI crawlers are welcome (`crawlers search`,
  `ai-search`, `ai-user` and `ai-training` `allow`, the library's default): a crawler verified by its operator's
  published address list or by DNS never gets the browser check; one that only sends the name is treated like any
  visitor. Examples prove both for Googlebot.
- Added: The default rules deny two public blocklists, Spamhaus DROP (hijacked and criminal networks) and abuse.ch
  Feodo (botnet command servers).
- Added: The cronjob part `requestshield` (daily at 04:23, written into the crontab by the kernel's crontab
  installer): fetches the blocklists the rules name (`request-shield feeds update`) and the crawlers' published
  address lists (`request-shield crawlers update`), so feed rules and crawler verification stay current. The
  library's command line runs as a process of its own, since it ends with exit().

## 0.0.1.2

- Updated: The default rules include the library's `@wordpress` set: an Exponential site is no WordPress, so
  `/wp-admin`, `/wp-login.php`, `xmlrpc.php`, `wp-content` and the rest are refused (404) before the kernel starts.
  Before, they reached the site, which answered its own "not found" page.
- Updated: The rule file and doc/configuration.md no longer say that a site with an admin host needs absolute paths
  for the store and the log: relative paths (relative to the rule file) work with a `site` block too. What the
  0.0.1.1 notes took for that was the include order: `exponential.rules` sets a store and a log of its own, so the
  site's settings must come after it.

## 0.0.1.1

- Updated: The default rules treat every admin siteaccess of a default installation like `/admin`: `adminui`,
  `editor`, `admin2`, `admin3`, `admin4`, `admin4l` and `admintest_*` may use system URLs and admin modules, save
  forms everywhere, and ask for the browser check once per pass (`SITE-ADMIN-*`, the same rules as the library's
  `match /admin/**`). Before, their system URLs were refused in enforce mode.
- Updated: The default rules keep the library's JSON API off (`set api off`): a `restrict` refuses nobody in
  monitor mode, so a site that switched to monitor had the API open.
- Updated: The default rules include the Exponential rules first, so the settings after them win (the library's
  `exponential.rules` sets a mode, a store and a log of its own), and say how to set up a site whose admin has a
  host of its own (the include order and absolute paths it needs).
- Updated: The library files keep the suffix `.php`, now stated and tested: Exponential Velocity turns `exit()` into
  its exit signal only in included `*.php` files. The kernel's `.autoloadignore` (6.0.15) keeps `lib/` out of the
  autoload array.

## 0.0.1.0

The first release.

- Added: request-shield 0.4.0 (tag v0.4.0) bundled as single files in
  `lib/requestshield/`: the core, statistics, API, firewall pages and HTTP cache.
- Added: `bootstrap/hook.php` for `config.php`: filters every request under
  PHP-FPM before the kernel and the HTTP cache's early exit, and under
  Exponential Velocity through `EXP_REQUEST_PREPEND_FILE`.
- Added: `expRequestShield`: the main rule file from `EXP_REQUEST_SHIELD_RULES`,
  `settings/request-shield/request-shield.rules` or the extension's default; the
  compiled settings in `var/request-shield/cache/`; `var/request-shield/PAUSED`
  switches the shield off; under Velocity the library's exit signal passes
  through, so a refusal, the browser check and the shield's own pages answer
  exactly as under PHP-FPM (same headers, no false "shield failed" in the log).
- Added: the default main rule file (enforce mode: on as installed; the store and log in
  `var/request-shield/`, the library's own pages closed to all but the server)
  and the library's Exponential rules.
- Added: Setup > Request shield (module `requestshield`, policy
  `requestshield/read`): switches on the dashboard (policy `requestshield/manage`:
  pause and switch on, enforce, monitor, off; with a backup of the rule file,
  a compile check and a log of who switched what), a dashboard with a chart of the decisions per hour, the
  log by period and decision, the rule files. Templates for admin4, adminui and
  the classic admin design, light and dark.
- Added: `bin/request-shield`, the library's command line with every part loaded.
- Added: a PHP 7.4 build of the library (`lib/requestshield/php74/`, syntax
  downgraded by Rector), loaded below PHP 8.0: the extension supports PHP 7.4 to 8.5.
- Added: `bin/update-library.sh`, which builds the library and its PHP 7.4 build
  from a release tag and checks the rules on every PHP named.
- The extension is licensed GPL-2.0-or-later; the bundled request-shield files
  stay MIT.
