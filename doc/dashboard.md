# The library's own pages (/rs)

Besides the admin's Setup > Request shield, the library serves pages of its own
below `dashboard-path` (default `/rs`), straight from the shield, before
Exponential:

| Path | What | Needs |
|---|---|---|
| `/rs/stats/overview`, `visitors`, `protection`, `sites` | statistics per hour, day and month | `set stats on` |
| `/rs/waf/rules`, `/rs/waf/live`, `/rs/waf/lists` | the active rules, the live view, the lists | `set live on` for the live view |
| `/rs/api/v1/...` | the JSON API the pages read | |

The extension's default rule file closes them to everyone but the server
(`[SITE-DASH] restrict /rs/** to 127.0.0.1 ::1`). To open them:

- **For your addresses**: `restrict /rs/** to 192.0.2.10 2001:db8::/48`.
- **With a login**: make a token with
  `php extension/exp_request_shield/bin/request-shield access-token <main.rules> '*'`, and put the
  line it prints (`dashboard-access * sha256:...`) in a rule file that only the
  site's owner can read. Opening `/rs/` then asks for the token.

`bin/request-shield check` warns while the pages are open to everyone.

The statistics count into `var/request-shield/store/`. The admin's dashboard
reads the log instead, so it works with `stats off`.
