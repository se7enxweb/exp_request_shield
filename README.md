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
  (system URLs, internal files, forms, the search, the login, the admin),
  **enforcing as installed**: the protection is on and stays on.
- **Setup > Request shield in the admin.** Switches at the top: pause and
  switch on, enforce, monitor or off. A dashboard with a chart of the
  decisions per hour, the rules that decided most and the newest log lines; the
  whole log by period and decision; the rule files and whether they compile. In
  the admin4 and admin4l designs, exp_adminui's adminui design and the classic
  admin design, in light and dark mode.
- **The library's command line** with every part loaded:
  `bin/request-shield check|test|trace|show ...`.

## Requirements

- Exponential 6.0. Under PHP-FPM (Apache, nginx) the hook needs nothing from the
  kernel. Under Exponential Velocity, `index.php` must run the file named in
  `EXP_REQUEST_PREPEND_FILE` (the 6.0.15 line; see [doc/velocity.md](doc/velocity.md)).
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
3. Copy `rules/request-shield.rules` to `settings/request-shield/` and adapt the
   host names and paths ([doc/configuration.md](doc/configuration.md)).
4. Regenerate the autoloads, clear the caches, and open Setup > Request shield.
5. Read Setup > Request shield > Log for anything a real visitor or editor did,
   and add an exception for it (or `set mode monitor` while you look).

The full procedure, with Velocity, permissions and checks: [INSTALL.md](INSTALL.md).

## Documentation

| Document | What it covers |
|---|---|
| [INSTALL.md](INSTALL.md) | Installing, activating, the hook, first checks, uninstalling |
| [doc/configuration.md](doc/configuration.md) | The main rule file, where it is found, the paths, the constants, monitor and enforce |
| [doc/admin.md](doc/admin.md) | Setup > Request shield: the dashboard, the log, the rules; the policy; the designs |
| [doc/how-it-works.md](doc/how-it-works.md) | The request path under PHP-FPM and under Velocity, failing open, the files on disk |
| [doc/velocity.md](doc/velocity.md) | Exponential Velocity specifics |
| [doc/dashboard.md](doc/dashboard.md) | The library's own pages under `/rs` and how to open them safely |
| [doc/cli.md](doc/cli.md) | `bin/request-shield`: check, test, trace and the rest |
| [doc/library.md](doc/library.md) | The bundled library, its version, and how to update it |
| [doc/troubleshooting.md](doc/troubleshooting.md) | Symptoms, causes and fixes |
| [doc/CHANGELOG.md](doc/CHANGELOG.md) | Changes per release |

The library's own documentation (every rule, every setting, the use case for
Exponential): https://github.com/cjw-network/request-shield/tree/main/docs

## License

The extension is licensed under the GNU General Public License v2.0 or any later
version ([LICENSE](LICENSE)). The bundled request-shield library in
`lib/requestshield/` and the three Exponential rule files copied from it stay
under their own MIT License. See [LICENSE.md](LICENSE.md).
