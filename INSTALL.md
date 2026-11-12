# Installing exp_request_shield

The shield runs before the kernel, so installing it has two halves: the
**extension** (the admin pages, the translations, the settings) and the **hook**
in `config.php` (the shield itself). Each works without the other: without the
hook the admin shows the setup but nothing is filtered; without the activated
extension the hook still filters, but there is no admin page.

## 1. Get the files

With Composer, from the installation's root:

```bash
composer require se7enxweb/exp_request_shield
```

The legacy installer puts the package in `extension/exp_request_shield/`.
Without Composer, clone or unpack it there:

```bash
git clone https://github.com/se7enxweb/exp_request_shield.git extension/exp_request_shield
```

The files must belong to the user the site's PHP runs as (or be readable by it).

## 2. Activate the extension

`settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=exp_request_shield
```

Then regenerate the autoloads and clear the caches:

```bash
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-all
```

On an installation with the `exp:velocity deploy` command, `./console exp:velocity deploy --packer`
does both, reloads PHP-FPM and restarts Velocity.

## 3. Give the site its own rule file

The extension ships a main rule file, `rules/request-shield.rules`, which the
shield uses until the site has its own. Updates replace the extension's files,
so give the site its own. The quickest way: Setup > Request shield, any mode
button. It makes `settings/request-shield/request-shield.rules` from the default
and copies the three `exponential*.rules` next to it. By hand:

```bash
mkdir -p settings/request-shield
cp extension/exp_request_shield/rules/request-shield.rules \
   extension/exp_request_shield/rules/exponential*.rules settings/request-shield/
```

An `include` may not leave its file's directory with `..` (the library refuses
it), so the site's main file includes the copies beside it. In the copy:

1. Pick the admin's file: `include exponential-admin-uri.rules` for the admin as
   `/admin`, `exponential-admin-host.rules` when it has a host name of its own.
   After an update of the extension, compare the copies with
   `extension/exp_request_shield/rules/` and take the new ones over.
2. Fix the paths: from `settings/request-shield/` the installation's root is two
   levels up.
   ```
   set store-dir ../../var/request-shield/store
   set log       ../../var/request-shield/log/shield.log
   ```
3. Name the site's host names, so a request for any other name is refused:
   ```
   host shop.example.com example.com admin.example.com
   host www.example.org                  # the documentation domain the shipped examples use
   ```
   Keep `www.example.org` listed: the library's Exponential examples send
   `Origin: https://www.example.org`, and `test` fails five of them when it is
   not one of the site's names. It is reserved for documentation, so no real
   visitor ever sends it.
4. Name the load balancer or proxy in front, if any (`trust 10.0.0.0/8`).

The web server must never serve `settings/` or `var/request-shield/`. A
standard Exponential virtual host already refuses both.

Details of every line: [doc/configuration.md](doc/configuration.md).

## 4. Check the rules

As the user the site's PHP runs as:

```bash
php extension/exp_request_shield/bin/request-shield check settings/request-shield/request-shield.rules
php extension/exp_request_shield/bin/request-shield test  settings/request-shield/request-shield.rules
```

`check` reads everything and names any mistake by file and line. `test` decides
the examples written next to the rules (`expect` lines) and must pass.

## 5. Add the hook to config.php

`config.php` sits next to `index.php`. Add these lines **above** the HTTP cache's
early exit (`ezphttpcacheearlyexit.php`), so cached pages are protected too:

```php
if ( is_file( __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php' ) )
    require __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php';
```

- **PHP-FPM** (Apache, nginx): the next request is filtered. Nothing to restart.
- **Exponential Velocity**: restart it (`./console exp:velocity restart`), because
  its workers read `config.php` once. See [doc/velocity.md](doc/velocity.md).

## 6. See it work

```bash
curl -sI https://www.example.org/ | grep -i x-rs        # with "set debug-header on"
curl -sI https://www.example.org/settings/site.ini      # 404 (in enforce mode, by the shield)
tail -f var/request-shield/log/shield.log
```

In the admin: **Setup > Request shield**. The dashboard says whether the shield
ran for that page, in which mode, from which rule file, and what it decided in
the last 24 hours.

## 7. Enforce, and monitor when in doubt

The rules ship in **enforce mode**: as soon as the hook is in `config.php`,
scanners, floods, stray forms and system URLs are refused, and the login and
the admin's forms ask for the browser check (a second's work for a real browser,
once per pass). Rules marked `monitor` (`EXP-BAN`, `EXP-STRICT`) only watch.

Read Setup > Request shield > Log in the first days. When a real visitor or
editor was refused (a page alias that starts with a module name, a form of your
own extension), add an exception and an `expect` example for it in the site's
main rule file, then `bin/request-shield check` and `test`.

To watch everything without refusing anybody (a site with many unusual
addresses), write `set mode monitor`: every decision is then logged as it would
be taken. Go back to `set mode enforce` when the log looks right. The servers
read a changed rule file within its `recheck` seconds; no restart.

## Switching it off

- From the admin: Setup > Request shield, **Pause** (or the mode **Off**).
- At once, without editing anything: create `var/request-shield/PAUSED`. Remove
  the file to switch the shield on again.
- In the rules: `set mode off`.
- For good: remove the hook lines from `config.php` (restart Velocity).

## Uninstalling

1. Remove the hook lines from `config.php` (restart Velocity).
2. Remove `ActiveExtensions[]=exp_request_shield`, then `composer remove
   se7enxweb/exp_request_shield` (or delete the directory).
3. Delete `var/request-shield/` and `settings/request-shield/` if you no longer
   need the log, the statistics and the rules.
