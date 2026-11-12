# How it works

## The request path

```
config.php
  └─ extension/exp_request_shield/bootstrap/hook.php
       ├─ web SAPI (PHP-FPM, Apache, nginx): prepend.php now
       ├─ Exponential Velocity: EXP_REQUEST_PREPEND_FILE = prepend.php
       │    └─ index.php runs it at the start of every request
       └─ any other command line: nothing
prepend.php
  └─ expRequestShield::protect()
       ├─ var/request-shield/PAUSED exists: nothing
       ├─ load lib/requestshield/*.php (once per process)
       └─ Shield::protectFile( <main rule file>, null, var/request-shield/cache )
            ├─ allow: the request goes on to the HTTP cache's early exit, then the kernel
            └─ refuse, check page, own page: the shield answers and the request ends
```

Under PHP-FPM, `config.php` runs on every request, so the shield runs there,
before the HTTP cache's early exit, the autoloader, the INI files and the
database. Every entry point that reads `config.php` (index.php, the REST and
tree menu entry points, ezjscore calls) is covered.

## What it costs

The settings are compiled from the rule files once, into
`var/request-shield/cache/`, and rebuilt when a rule file changes (checked
every `recheck` seconds). A request then costs one include of the compiled
settings and the checks themselves, a few microseconds with OPcache. The library
files total about 1.7 MB of PHP; OPcache keeps them compiled.

## Failing open

When the shield itself fails (a rule file that does not compile, a store that
cannot be written), the request goes on to the site, uncached, and PHP's error
log gets one line a minute. A broken shield never takes the site down.
Setup > Request shield shows the error.

## Files on disk

| Path | What | Written by |
|---|---|---|
| `extension/exp_request_shield/lib/requestshield/` | the library | the package |
| `settings/request-shield/` | the site's rule files | you |
| `var/request-shield/cache/` | the compiled settings | the shield |
| `var/request-shield/store/` | counters, the secret of the pass cookie, lists, feeds, statistics | the shield |
| `var/request-shield/log/shield.log` | one line per decision (rotated at `log-max-size`) | the shield |
| `var/request-shield/PAUSED` | switches the shield off while it exists | you |

All of `var/request-shield/` must be writable by the user the site's PHP runs
as, and must never be served by the web server. A standard Exponential virtual
host refuses `var/` except the storage and cache paths it serves on purpose.

## The library's classes

The library's namespace is `CjwNetwork\RequestShield`. The admin pages read the
shield's own data through its classes (`Settings`, `Tier`, `LogStats`), so what
the admin shows is what the shield decides. The extension's classes:

| Class | File | Role |
|---|---|---|
| `expRequestShield` | `classes/exprequestshield.php` | paths, loading the library, protecting a request; the status and the log summary for the admin. Needs nothing from the kernel |
| `expRequestShieldAdmin` | `classes/exprequestshieldadmin.php` | the admin's settings, the chart's geometry, the page result |
