# The command line: bin/request-shield

`bin/request-shield` is the library's command line with every bundled part
loaded (statistics, API, firewall pages, HTTP cache). Run it as the user the
site's PHP runs as, so the files it writes stay readable for the site:

```bash
sudo -u <web user> php extension/exp_request_shield/bin/request-shield <command> ...
```

| Command | What it does |
|---|---|
| `rules` | prints the main rule file the site uses and where it comes from (this extension's own command) |
| `check <main.rules>` | reads everything, names any mistake by file and line, warns about risky setups, prints the tier |
| `test <main.rules>` | decides every `expect` example next to the rules; run after every change |
| `trace <main.rules> "GET https://www.example.org/path"` | how one request is decided, rule by rule |
| `show <main.rules>` | the compiled settings |
| `crawlers <main.rules> update` | fetches the address lists the search engines publish (the cronjob part `requestshield` does it daily) |
| `access-token <main.rules> '*'` | makes a token for the library's own pages ([dashboard.md](dashboard.md)) |
| `deny`, `allow`, `unlist`, `lists` | the site's deny and allow lists, with an end date |
| `stats <main.rules> --days=7` | the statistics on the command line (`set stats on`) |
| `version` | the library's version and build |
| `help` | every command |

Do not use `self-update` on the bundled files: update the extension instead
([library.md](library.md)), so the files, `ezinfo.php` and the changelog stay together.

`check` and `test` compile the settings next to the rule file
(`.request-shield/` beside it); the web requests use `var/request-shield/cache/`.
The directory next to the extension's own rule files is in `.gitignore`.

The library's reference: https://github.com/cjw-network/request-shield/blob/main/docs/reference/cli.md
