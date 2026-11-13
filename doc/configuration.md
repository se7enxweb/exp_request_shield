# Configuration

The shield is configured in **rule files**, plain text read by the library. It
runs before the kernel, so it never reads an INI file; `requestshield.ini` only
holds settings of the admin pages.

## Which main rule file is loaded

The first that applies:

| Order | File | When |
|---|---|---|
| 1 | the file named by the constant `EXP_REQUEST_SHIELD_RULES` | defined in `config.php` before the hook line |
| 2 | `settings/request-shield/request-shield.rules` | the site's own file exists |
| 3 | `extension/exp_request_shield/rules/request-shield.rules` | the extension's default |

Setup > Request shield shows which one is in use and why. Use 2 for a real site:
updates of the extension replace the extension's own files.

## The shipped files

| File | What it is |
|---|---|
| `rules/request-shield.rules` | the default main file: includes the Exponential rules, enforce mode (on as installed), the paths below `var/request-shield/`, the library's own pages closed to everyone but the server |
| `rules/exponential.rules` | the frontend: system URLs, internal files, view parameters, forms, the search, the pace. Included, never loaded on its own |
| `rules/exponential-admin-uri.rules` | the admin as the siteaccess `/admin` (URI matching). Includes `exponential.rules` |
| `rules/exponential-admin-host.rules` | the admin on a host of its own (`admin.example.org`). Includes `exponential.rules` |

The three `exponential*.rules` files are copied unchanged from the library's
`examples/exponential/`; why each rule exists is explained in the library's
[Exponential use case](https://github.com/cjw-network/request-shield/blob/main/docs/use-cases/exponential.md).

## A site's main file

`settings/request-shield/request-shield.rules`, for example:

```
ids SITE
version 2026-11-12.1

include exponential-admin-uri.rules                  # the copies next to this file

set mode enforce                                     # monitor: log only, refuse nobody
host www.example.org example.org                     # every other Host: 404
# trust 10.0.0.0/8                                   # the proxy or load balancer in front

set store-dir ../../var/request-shield/store         # relative to this file
set log       ../../var/request-shield/log/shield.log
set log-level flag                                   # refused, checked or would-be (monitor)

[SITE-DASH] restrict /rs/** to 127.0.0.1 ::1         # the library's own pages: see dashboard.md
expect GET /rs/stats/overview from 198.51.100.27 403 by SITE-DASH
```

### An admin on a host of its own

The default covers admins reached by a path (`/admin`, `/adminui`, `/editor`,
`/admin4` ...). When the admin has a host name of its own (`edit.example.org`,
siteaccess matching by host), the site's main file is built like this:

```
include exponential.rules                 # first: it sets a mode, a store and a log of its own
set mode enforce                          # the site's settings after it, so they win
set store-dir /var/www/site/var/request-shield/store   # absolute paths: with a site block,
set log       /var/www/site/var/request-shield/log/shield.log   # relative ones are not honored
...
include exponential-admin-host.rules      # last: it opens the admin's site block
```

with `exponential-admin-host.rules` a copy whose `site admin.example.org {` names
the admin's host names (`site edit.example.org admin.example.org {`) and whose own
`include exponential.rules` line is removed. After the first `site` block only
further `site` blocks, `include`, `ids` and `version` may follow.

The three `exponential*.rules` are copied next to the site's main file: an
`include` may not leave its directory with `..`. After an update of the
extension, compare them with `extension/exp_request_shield/rules/`.

Adapt to the site, each a single line in `exponential.rules` or replaced here
with `replace [ID] ...`:

- `EXP-MODULES`: the URI siteaccesses that may stand in front (`(/(ger|eng))?`).
- `EXP-POST`: the modules of your extensions that take forms.
- `EXP-SEARCHES`: the search budget.

Every rule has an ID, and `expect` lines next to it are examples that
`bin/request-shield test` decides. Add your own site's addresses as examples,
above all near misses (a page alias that must not be refused).

## Paths and constants

Define these in `config.php` **before** the hook line, only when the default
does not fit:

| Constant | Default | Meaning |
|---|---|---|
| `EXP_REQUEST_SHIELD_RULES` | see above | the main rule file |
| `EXP_REQUEST_SHIELD_ROOT` | two levels above the extension | the installation's root |
| `EXP_REQUEST_SHIELD_VAR` | `<root>/var/request-shield` | where the compiled settings go (`cache/`); the shipped rules put the store and the log there too |
| `EXP_REQUEST_SHIELD_DISABLED` | not defined | `true`: the hook does nothing |

Relative paths in a rule file (`store-dir`, `log`, `include`, `lists-dir`) are
relative to that rule file.

## Modes

| Mode | Effect |
|---|---|
| `monitor` | every decision is logged as it would be taken (`monitor-reject`, `monitor-challenge` ...); nobody is refused |
| `enforce` | the decisions are carried out; rules with `monitor` in front of them stay watched |
| `strict` | enforce, and the rules marked strict too |
| `off` | nothing is decided |

The shipped rules **enforce**: the protection is on as soon as the hook is in
`config.php`. A site with unusual addresses (page aliases that start with a
module name, forms of its own extensions) can start in `monitor` instead: read
the log for a few days while editors work and the search is used (Setup >
Request shield > Log, "Watched"), add exceptions for what a real visitor did,
then go back to `enforce`.

A changed rule file is read again within `recheck` seconds by every server; no
restart.

## Pausing and switching the mode from the admin

The file `var/request-shield/PAUSED` switches the shield off at once, on every
server, without editing anything. Remove it to switch it on again.

Setup > Request shield has buttons for both: **Pause** / **Switch on** create and
remove that file, and **Enforce** / **Monitor** / **Off** rewrite the `set mode`
line of the site's main rule file (with a backup, and only if the result
compiles). See [admin.md](admin.md#switches-top-of-the-dashboard).

## Admin settings: requestshield.ini

`[AdminSettings]`, override in `settings/override/requestshield.ini.append.php`:

| Setting | Default | Meaning |
|---|---|---|
| `DashboardHours` | 24 | hours the dashboard sums up and draws (1 to 168) |
| `DashboardRecent` | 15 | newest log lines on the dashboard |
| `LogHours[]` | 1, 6, 24, 72, 168 | the periods of the log page |
| `LogPageSize` | 50 | lines per page of the log page |
| `ShowRuleText` | enabled | show the text of the rule files on the rules page |
