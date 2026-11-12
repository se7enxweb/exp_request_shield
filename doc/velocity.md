# Exponential Velocity

Velocity keeps PHP workers running between requests. `config.php` runs once per
worker, at the warm-up, so the hook cannot filter there. Instead it defines
`EXP_REQUEST_PREPEND_FILE`, and Velocity's `index.php` runs
`bootstrap/prepend.php` at the start of every request.

- **Needs** an `index.php` that runs `EXP_REQUEST_PREPEND_FILE` (Exponential's
  6.0.15 line). An older `index.php` simply never runs it: Velocity is then not
  filtered, PHP-FPM still is.
- **After adding or removing the hook**, restart Velocity
  (`./console exp:velocity restart`).
- **Rule changes** need no restart: every worker reads a changed rule file within
  its `recheck` seconds.
- **The store**: Velocity runs several processes. With `store auto` and APCu each
  process would count on its own; `set store file` makes all workers and PHP-FPM
  share the counters in `var/request-shield/store/`.
- **Updating the library** under Velocity: create `var/request-shield/PAUSED`,
  replace the files, restart Velocity, remove `PAUSED`. A worker that already
  loaded the old classes must not meet the new files.

## exit() in a worker

In a Velocity worker `exit()` throws `Q_WebServer_ExitSignal`, so that it ends
the request and not the worker. The library's `Shield::protect()` catches every
`\Throwable` and takes it for its own failure ("shield error"), although it has
already written its answer (a refusal, the browser check, a page of its
dashboard). `expRequestShield::protect()` handles this: a "shield error" after
which the status or a header of an answer changed is that exit, and the request
ends there. A real failure changes neither and goes on to the site.

The visible trace is the debug header (`set debug-header on`): under Velocity a
refusal reads `X-RS: allow-uncached shield error` instead of `X-RS: reject ...`,
with the right status and the shield's own page. The log line is the normal one.
