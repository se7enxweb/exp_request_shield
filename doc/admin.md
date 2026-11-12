# Setup > Request shield

Three pages in the admin, module `requestshield`: they show what the shield
does, and the dashboard has switches for it. The rules themselves are edited on
the server ([configuration.md](configuration.md)); the switches change exactly
the files described there, nothing else.

## Access

| Policy | Allows |
|---|---|
| `requestshield/read` | every page |
| `requestshield/manage` | the switches on the dashboard (view `requestshield/switch`) |

Only the Administrator role, which holds every policy, has them in a new
installation. Give them to another role in Roles and policies.

The link is in the Setup menu (in admin4's grouped Setup menu under "System"),
shown only to a user with the policy (`menu.ini [Leftmenu_setup]`).

## Switches (top of the dashboard)

Shown to a user with `requestshield/manage`. Each is a button in a form posted
with the form token, and ends back on the dashboard with what was done.

| Switch | What it changes | Takes effect |
|---|---|---|
| **Pause** / **Switch on** | creates / removes `var/request-shield/PAUSED` | on the next request, on every server |
| **Enforce**, **Monitor**, **Off** | rewrites the `set mode` line of the site's main rule file | within the rules' `recheck` seconds (default 10) |

A mode switch:

- uses the site's main rule file (`settings/request-shield/request-shield.rules`,
  or the file named by `EXP_REQUEST_SHIELD_RULES`). While the extension's
  default is in use, the first switch **makes the site's own file** from it and
  copies the three `exponential*.rules` next to it; from then on the site's file
  is used;
- keeps the previous file in `var/request-shield/backup/` (name, date, short hash);
- compiles the new rules and, when they do not compile, puts the previous file
  back and says why;
- is refused, with the reason, when the web server cannot write the file or its
  directory (the buttons are then disabled).

`strict` mode stays a decision of the rule file. Every switch is written to
`var/log/requestshield.log` with the user's login and id and the outcome.

## Dashboard (requestshield/dashboard)

- **Notices**: the rules cannot be read (the shield then lets everything
  through, see [how-it-works.md](how-it-works.md)); the shield is paused; it did
  not run for this page (no hook, or this server does not run it); monitor mode
  (nobody is refused).
- **Figures**: the mode, the log lines of the period and how many were refused,
  sent to the browser check, watched (monitor) or other.
- **Decisions per hour**: a stacked bar per hour for the period
  (`DashboardHours`). Hover a bar for its hour and total.
- **Setup**: the library's version and build, the extension's version, the main
  rule file and where it comes from, the store and its tier (S0 stateless, S1
  file, S2 APCu) with what is off and why, the log, the host names, the parts
  loaded.
- **Rules that decided most**, with the time of the last decision.
- **Newest log lines**, linked to the whole log.

## Log (requestshield/log)

The log lines of a period (`LogHours[]`), newest first, filtered by decision
group, a page at a time. Every choice is a link with view parameters, for
example `requestshield/log/(hours)/72/(group)/refused/(offset)/50`.

The client column is the address as the log wrote it: by default its network
(IPv4 /24, IPv6 /48, `set log-ip masked`), never a person's address.

## Rules (requestshield/rules)

Whether the rules compile, the main file and the files it includes with their
text (`ShowRuleText`), and the commands that check them on the server.
Read-only.

## Designs

The page bodies are in `design/standard/templates/requestshield/` and are built
from the administration's shared components (`components.css`, `.a4-*`). Each
admin design has its own frame, `requestshield/parts/page_open.tpl` and
`page_close.tpl`:

| Design | Siteaccess on a standard installation | Frame |
|---|---|---|
| admin4 (and admin4l, which falls back to it) | `admin` | `design/admin4/templates/requestshield/parts/` |
| adminui (exp_adminui) | `adminui` | `design/adminui/templates/requestshield/parts/` |
| admin (classic) | `classic` | `design/admin/templates/requestshield/parts/` |
| any other | | `design/standard/templates/requestshield/parts/` |

What the shared components cannot do is in
`design/standard/stylesheets/modules/requestshield.css` (`.a4-rs-*` classes on
the `--a4-*` tokens, so the pages follow light and dark mode), loaded through
`design.ini [StylesheetSettings] BackendCSSFileList[]` and packed by ezjscore.
No inline CSS and no inline script: the pages work under a
Content-Security-Policy without `'unsafe-inline'`. After a stylesheet change,
clear the `ezjscore-packer` and `template-block` caches.

## Translations

The strings use the context `extension/exp_request_shield`;
`translations/untranslated/translation.ts` lists them.
