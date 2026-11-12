# Changelog

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
