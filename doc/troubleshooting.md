# Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Setup > Request shield: "did not run for this page" | the hook is not in `config.php`, or Velocity was not restarted after adding it | add the hook ([INSTALL.md](../INSTALL.md)); `./console exp:velocity restart` |
| "The rules cannot be read" | a mistake in a rule file | `bin/request-shield check <main.rules>` names the file and line |
| Everything passes in enforce mode, PHP's error log has "the shield failed and let the request through" | the shield fails open: a store or cache directory it cannot write | make `var/request-shield/` writable by the site's PHP user |
| Files in `var/request-shield/` owned by root, the site's PHP cannot read them | the command line was run as root | run `bin/request-shield` as the web user; fix the owner |
| An editor gets the check page in the admin | `EXP-ADMIN-CHECK`: the admin asks for a browser check once per pass | allow JavaScript; for a fixed office address `exempt <address>` |
| Background requests in the admin fail with 429 | a fetch without a pass gets the check as JSON | the page reloads itself; name other background paths with `api-path` |
| A page alias is refused (404 by `EXP-MODULES` or `EXP-SYSVIEW`) | the alias starts with a module name | add the alias as an exception and an `expect` example |
| Under Velocity, `X-RS: allow-uncached shield error` on refusals | the exit() gap in a worker, handled | nothing to do; see [velocity.md](velocity.md) |
| The dashboard chart is empty | `set log` missing, or `log-level off` | name a log file; `log-level flag` |
| `/rs/...` answers 403 | the default rule `SITE-DASH` | [dashboard.md](dashboard.md) |

To see how one request is decided:

```bash
php extension/exp_request_shield/bin/request-shield trace settings/request-shield/request-shield.rules "GET https://www.example.org/the/path"
```

To switch the shield off at once: create `var/request-shield/PAUSED`.
