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
`\Throwable` and would take that for its own failure ("shield error"), although
it has already written its answer (a refusal, the browser check, a page of its
dashboard): the debug header would read `X-RS: allow-uncached shield error` and
PHP's error log would get a false "the shield failed" line.

So in a Velocity worker `expRequestShield::protect()` runs the library's own
steps (the settings of this request's website, then the decision) with exactly
that one difference: Velocity's exit signal passes on and ends the request; any
other failure is handled by the library as always (the request goes on to the
site). The answers, the headers and the log are the same as under PHP-FPM.

Should a future library change its inner steps, the extension notices it (it
checks them once per worker) and falls back to `Shield::protectFile()`, ending
the request when the shield has written an answer of its own; only the debug
header and the error log then show the "shield error" again.
