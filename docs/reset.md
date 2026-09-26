# Octane Mode: What Gets Reset Between Requests

Qbix Server's persistent-worker mode ("octane mode") keeps workers alive across requests, like php-fpm and Laravel Octane. Unlike fork-per-request, no process creation happens per request — the worker loops, handling one request after another with a snapshot restore between them.

## What gets reset (automatic)

| State | How | Cost |
|---|---|---|
| **Static class properties** | Snapshot at startup, restore via cached `ReflectionProperty` handles | ~0.03ms (cached handles, no per-request Reflection lookups) |
| **$_GET, $_POST, $_COOKIE, $_FILES** | Re-populated from the request | 0 (overwritten) |
| **$_SERVER** | Re-populated from the request | 0 (overwritten) |
| **$_REQUEST** | Rebuilt from $_GET + $_POST | 0 |
| **$_SESSION** | `session_write_close()` + `$_SESSION = []` | ~0 |
| **Output buffers** | One capture buffer per process (`Q_WebServer_Capture`), emptied each request; buffers a script leaves open are part of its response. See [below](#output-buffers-and-why-a-workers-memory-is-flat) | ~0 |
| **Error state** | `error_clear_last()` | ~0 |
| **Custom globals** | Snapshot + restore (skipping superglobals) | ~0.01ms |
| **Shutdown functions** | Shimmed `register_shutdown_function()` — collected per request, fired at request end, cleared | ~0 |
| **Error/exception handlers** | Shimmed `set_error_handler()` / `set_exception_handler()` — restored to boot state between requests | ~0 |
| **Autoloaders** | Shimmed `spl_autoload_register()` — request autoloaders unregistered, boot stack preserved | ~0 |
| **ini values** | Shimmed `ini_set()` — tracked per request, restored to boot values | ~0 |
| **Environment variables** | Shimmed `putenv()` — tracked per request, restored to boot values | ~0 |
| **Response headers** | `Q_WebServer_State::clear()` + `header_remove()` + `http_response_code(200)` | ~0 |

**Total reset cost: ~0.06ms** — compared to 8ms for `pcntl_fork()`.

**44 PHP functions shimmed** via source transformation (stream wrapper + `token_get_all()`): `header`, `setcookie`, `setrawcookie`, `http_response_code`, `headers_sent`, `headers_list`, `header_remove`, `session_start`, `session_id`, `session_name`, `session_set_cookie_params`, `session_get_cookie_params`, `session_write_close`, `session_regenerate_id`, `session_destroy`, `session_status`, `move_uploaded_file`, `is_uploaded_file`, `ini_get`, `ini_set`, `set_time_limit`, `getallheaders`, `phpinfo`, `apache_request_headers`, `register_shutdown_function`, `set_error_handler`, `set_exception_handler`, `restore_error_handler`, `restore_exception_handler`, `spl_autoload_register`, `spl_autoload_unregister`, `putenv`, and the file stat functions `file_exists`, `is_dir`, `is_file`, `filemtime`, `filesize`, `clearstatcache`, and the process functions `exec`, `system`, `passthru`, `shell_exec`, `proc_close`, `pclose` (they run the real function and then forget what the file wrapper remembered -- see [compatibility.md](compatibility.md#remembered-file-facts)). `exit` and `die` are rewritten too, so they end the request rather than the worker.

## What persists (by design)

| State | Why | Risk | Mitigation |
|---|---|---|---|
| **OPcache** | Bytecode is read-only, shared | None | Same as fpm |
| **Loaded classes** | That's the whole point — COW sharing | None | Immutable after load |
| **APCu cache** | Shared memory, not per-process | None | Same as fpm |
| **Interned strings** | PHP internal optimization | None | Read-only |
| **Autoloader maps** | Registered once at startup | None | Same as fpm |

## What persists (needs care)

| State | Risk | What we do | What the developer should do |
|---|---|---|---|
| **Database connections** | Previous request's transaction state | `ROLLBACK` after each request on the Qbix Platform's `Db` connections; nothing for any other database layer | Use a connection pooler (PgBouncer, ProxySQL) or close per request |
| **File handles** | Open descriptors leak across requests | Nothing between requests (a newly forked worker closes the sockets it inherited, nothing more) | Use `fclose()` in shutdown handlers |
| **Stream contexts** | Custom SSL/proxy settings persist | Nothing | Avoid `stream_context_set_default()` |
| **Signal handlers** | Previous request's handlers persist | Nothing | Don't call `pcntl_signal()` in request code |
| **cURL handles** | Cookies, auth headers persist | Nothing | Don't reuse `curl_init()` across requests |
| **Framework registries in globals** | A registry filled once via `include_once` empties for the worker's life if its global is cleared | Preserve them by name in `Q.webserver.keepGlobals` | Name any global that holds an include-populated registry; clear everything else |

Previously risky items **now handled automatically** by the compat layer:

| State | Was | Now |
|---|---|---|
| **Shutdown functions** | Accumulated across requests | Shimmed — collected, fired at request end, cleared |
| **Error/exception handlers** | Leaked across requests | Shimmed — restored to boot state |
| **Autoloaders** | Accumulated across requests | Shimmed — request autoloaders unregistered |
| **ini_set values** | Persisted across requests | Shimmed — tracked and restored |
| **Environment variables** | Persisted via putenv() | Shimmed — tracked and restored |
| **Response headers** | Could leak to next response | Cleared between requests |

## How it compares

### vs php-fpm

| | php-fpm | Qbix octane mode |
|---|---|---|
| Static properties | **Persist** — leak between requests | **Reset** via snapshot restore |
| Globals | **Persist** — leak between requests | **Reset** via snapshot restore |
| Superglobals | Reset by the SAPI | Reset by the server |
| Memory per worker | ~50 MB (independent bootstrap) | ~5 MB warmed, up to full working set unwarmed (see *Warming the pool* below) |
| DB connections | Persist (risk) | Persist (same risk, same mitigation) |
| OPcache | Shared across workers | Shared via parent process |
| `max_requests` recycling | Worker dies and respawns periodically | Kept as a safety net: `maxRequests` (default 1000), plus replacement on the memory ceiling or unbalanced output buffers, and on request |

php-fpm's `pm.max_requests` exists because statics and globals leak. The snapshot restore cleans those, so a worker here is replaced for what it cannot reach -- C extension state, closures holding references, memory the heap does not give back -- after `maxRequests` requests (default 1000), or at once if its health check fails. See [workers.md](workers.md).

### vs Laravel Octane (Swoole/RoadRunner)

| | Laravel Octane | Qbix octane mode |
|---|---|---|
| Reset mechanism | App-level: `$app->flush()`, `Container::forgetInstances()` | Language-level: `ReflectionProperty::setValue` on all statics + 44 function shims |
| Coverage | Only what Laravel's flusher knows about | **All user-defined classes**, automatically |
| Lifecycle functions | Must audit manually | **Shimmed**: `register_shutdown_function`, `set_error_handler`, `set_exception_handler`, `spl_autoload_register`, `ini_set`, `putenv` — all tracked and restored |
| Third-party packages | Must implement `ResetScope` interface | **Covered automatically** — their statics are reset too |
| Memory model | Swoole: shared memory, coroutines | COW fork: isolated memory per worker |
| New class detection | Manual registration | Automatic via `get_declared_classes()` |
| Requires extension | Swoole (PECL) or RoadRunner (Go binary) | **No** — pure PHP |
| Risk of missing a reset | High — depends on package authors | Low — reflection + shims cover everything PHP can introspect |

Laravel Octane's biggest problem is that third-party packages often DON'T implement the reset interface, causing subtle state leaks. The reflection-based approach resets everything regardless of whether the package author thought about it.

### What reflection CAN'T reset

These are the same in all persistent-worker systems (fpm, Octane, Swoole):

1. **C extension internal state** — PHP has no API to introspect it 2. **Closures capturing references** — if a closure captured `&$static`,
   resetting the property doesn't affect the closure's binding
3. **Resources** — file handles, DB connections, sockets are kernel objects;
   PHP can only close them, not "reset" them
4. **State in external services** — Redis keys, database rows, message queues

For (1) and (2), `pcntl_fork()` is the only bulletproof solution. For (3) and (4), no execution model helps — the developer must manage external state.

## Output buffers, and why a worker's memory is flat

A worker collects each response in an output buffer rather than writing it to
stdout, and that buffer has to survive the script: applications end their own
buffers with loops like `while (@ob_end_clean());`. So it cannot be removable.

It used to be opened per request with `ob_start(null, 0, 0)`. Flags `0` make a
buffer unremovable, but also uncleanable, so the `ob_clean()` meant to empty it
failed silently and the next request stacked another on top -- one buffer, with
that request's whole page in it, left behind per request for the life of the
worker. On an Exponential install that was ~2 MB a request: a worker at 1.1 GB
after 600 requests. Every response was still right, because the body was read
from whichever buffer was on top, so nothing visible pointed at it. Userland
could not see it either: no static, global or object held the memory, and
`gc_collect_cycles()` found nothing, because PHP's output stack is not a PHP
value.

`Q_WebServer_Capture` replaces it. There is one capture buffer per process,
opened once and reused. It is cleanable and flushable but not removable, and
its handler moves flushed output out into a string, so the buffer is empty
between requests. Buffers a script leaves open are flushed down into it at the
end, as PHP does at the end of a request. Its own statics are exempt from the
snapshot restore -- restored to the parent's "no buffer yet", every request
would open another and the leak would be back.

## Code that can run only once per process

Some application code cannot run twice in one process -- it defines
constants from the request, or declares functions that depend on it. It can
ask for its worker to be replaced after it answers:

    if (class_exists('Q_WebServer_Pool', false)) {
        Q_WebServer_Pool::retireAfterResponse('defines request constants');
    }

The request is answered normally; the parent retires the worker before its
next request, forks a clean one, and logs
`worker N replaced after M requests: asked by the application: ...`.

## Cycles are collected between requests

Objects that point at each other are freed only by PHP's cycle collector,
which runs on its own when its root buffer fills -- and raises that threshold
each time a run finds little. A normal request ends before that matters; a
worker never ends one, so a request's cyclic garbage outlived it (~47 KB a
request on one application). The between-request reset calls
`gc_collect_cycles()`, which is cheap there because the buffer only ever holds
one request's candidates. `tests/unit-worker-memory-bounded.php` makes ~96 MB
of cyclic garbage over 150 requests and requires the worker not to keep it.

## A worker that grows is replaced

Memory that grows per request is not something to find by watching a graph. So
every request ends with a check, in the worker, before it answers:

- the output stack must be back to exactly the capture buffer, empty;
- the heap must be under `Q.webserver.workerMemoryCeiling` (MB; by default
  256, or three quarters of `memory_limit` if that is lower). It is absolute on
  purpose: with hundreds of workers, a ceiling derived from a generous
  `memory_limit` would let each grow to gigabytes first.

A worker that fails either still answers the request, and asks to be replaced.
The parent retires it before giving it another request, forks a fresh one from
the clean parent, and logs why:

    worker 12345 replaced after 812 requests: heap 402 MB over the 384 MB ceiling

A leak somewhere -- in the server, the application, an extension -- then costs
a periodic re-fork and a log line naming it, instead of a machine that runs out
of memory. `tests/unit-worker-memory-bounded.php` holds both halves: hundreds
of requests on one worker leave it with one buffer and an unchanged heap, and a
ceiling it cannot stay under gets every request answered by a fresh worker.

## Warming the pool in the parent, and the one trap in it

The reset above runs *between requests*, and its baseline is a snapshot taken
after preload, when nothing has been served — so it is clean. There is a second,
optional mechanism that shares far more memory, and it has a subtlety the
between-request reset does not.

A worker builds its whole working set — compiled templates, resolved routes, the
object graph — on its first request and keeps it: tens to a couple hundred MB,
private, once per worker. That set lives in the allocator's arena, which is
anonymous memory, which `fork()` shares copy-on-write. So `Q.webserver.warmup`
names a script the pool runs **in the parent, before it forks**, typically one
that renders a representative page. Every worker then inherits the warmed arena
shared; measured on a heavy app, ~21 MB private per warm worker against ~209
unwarmed.

It is not `Q.webserver.preload`, and must not be. `preload` is older and means
an autoloader, which the server requires before the pool exists -- before the
source-code transform is installed. A class is compiled once and every worker
inherits it, so an application loaded that way keeps its real `exit` and
`header()` for the life of the pool: `exit` ends the *worker* rather than the
request (the client gets `502 Worker died`), and `header()` under the CLI SAPI
does nothing. Files a worker loads for itself are still transformed, so only
some paths break, which makes it look like anything but a load-order problem.
The warm-up runs after the transform is in place. The server warns at startup
when `preload` is set with the transform on.

The trap: **the snapshot is taken after the warm-up, so whatever the render left
becomes every worker's baseline.** The between-request reset then faithfully
restores workers *to that dirtied baseline*. A render leaves a great deal — the
parsed request, routing state, template-override caches, a framework's
"assets already emitted" flag, the current node a breadcrumb reads — and any of
it, frozen, serves something wrong to every worker at once.

The fix is the same reflection this doc already relies on, applied once more
before the snapshot: **the warm-up script resets every user-class static to its
declared default and clears the request globals, keeping only the pure config
and type registries** (the statics counterpart to `keepGlobals`). Naming the
leaks instead does not converge — on one app, a fourth surfaced after three were
fixed. The rule that keeps it correct and fast: keep *only* configuration and
registries in the skip-list; resetting those too makes every request rebuild
them (slow, and the memory returns), and keeping the template or content caches
lets request state leak straight back.

Verify a warm-up by behaviour, with the pair that exposes a frozen route or
node: **several distinct URLs must return distinct content, each must carry a
resolving stylesheet.** A single URL never shows a reset bug — it appears only on
the second, different request.

## Configuration

```json
{
    "Q": {
        "webserver": {
            "workers": 40,
            "forkPerRequest": false,
            "maxRequests": 1000
        },
        "compat": {
            "skipSourceCodeTransform": false
        }
    }
}
```

`skipSourceCodeTransform` defaults to `false` — compat is on by default. Set `"skipSourceCodeTransform": true` only if your code already uses the Q APIs directly and doesn't call `header()`, `session_start()`, etc.

Setting `maxRequests` to a non-zero value recycles workers after N requests, as a safety net against any state the snapshot can't reach (C extension internals, accumulated closures). Set to 0 to disable recycling.

## The escape hatch

If a specific script is known to leak state that snapshot restore can't clean (e.g., a C extension that caches aggressively), route it to fork-per-request mode:

```json
{
    "Q": {
        "webserver": {
            "fork": { "patterns": ["legacy/.*", "unsafe-extension.php"] }
        }
    }
}
```

These scripts still get the preloaded classes via COW, but each request forks a fresh child process — guaranteed clean state at the cost of ~8ms fork overhead.
