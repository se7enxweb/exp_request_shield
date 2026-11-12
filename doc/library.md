# The bundled library

`lib/requestshield/` holds request-shield by JAC Systeme GmbH (CJW Network),
https://github.com/cjw-network/request-shield, MIT License
(`lib/requestshield/LICENSE`).

| File | Edition | Contents |
|---|---|---|
| `request-shield.php` | mini | the checks, the answers, the browser check, the log, the rule compiler, the command line, the shipped rule sets |
| `request-shield-stats.php` | stats | the statistics |
| `request-shield-api.php` | api | the JSON API below `/rs/api/v1` |
| `request-shield-waf.php` | waf | the firewall's pages (rules, live, lists) |
| `request-shield-cache.php` | cache | the HTTP cache (`set http-cache on`) |

On PHP 7.4, `expRequestShield::libraryDir()` loads `lib/requestshield/php74/`
instead: the same five files with their syntax downgraded to PHP 7.4 by Rector
(`build/rector-php74.php`, the `DOWN_TO_PHP_74` set). Only the syntax and the PHP
8.0 functions change. The proof that they decide alike: `check`, `test` (all
examples) and `trace` give the same results on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4
and 8.5, and under a web server (PHP's built-in server) the same requests get the
same answers on all seven.

Each file is built, unchanged, by the library's own `build/single-file.php`
from the release tag, so `Shield::BUILD` names the tag:

| exp_request_shield | request-shield | Build |
|---|---|---|
| 0.0.1.0 | 0.4.0 (tag v0.4.0) | `v0.4.0` |

## Updating it

```bash
# once, in a work directory outside the site: composer require --dev rector/rector
RECTOR=<work dir>/vendor/bin/rector \
PHPS="/opt/php/7.4/bin/php /opt/php/8.5/bin/php" \
bin/update-library.sh <request-shield clone> <tag>
```

The script checks out the tag in a temporary worktree, builds the five files
with `--build=<tag>`, copies them, the license and the three
`examples/exponential/*.rules` into the extension, makes the PHP 7.4 build, and
runs `check` and `test` on the shipped rules with every PHP named in `PHPS`. Then note the new version in this file, in `ezinfo.php`
and in [CHANGELOG.md](CHANGELOG.md), and release the extension.

On a running site under Velocity, see [velocity.md](velocity.md) before
replacing the files.
