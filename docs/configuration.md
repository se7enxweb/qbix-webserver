## ⚙️ Configuration

Create `config/server.json` next to your `web/` directory, or pass `--config=path/to/config.json`:

```json
{
    "Q": {
        "webserver": {
            "keepAlive": {
                "max": 100,
                "timeout": 15
            },
            "maxConnections": 1024,
            "fileCache": {
                "maxSize": 67108864,
                "maxFile": 1048576,
                "checkInterval": 1
            },
            "rateLimit": {
                "enabled": true,
                "requests": 100,
                "window": 60
            }
        }
    }
}
```

| Key | Default | What it does |
|---|---|---|
| `keepAlive.max` | 100 | Max requests per keep-alive connection. The last one is answered with `Connection: close`, and so is any `5xx`; the header always says what the server does next. See [http.md](http.md#keep-alive) |
| `keepAlive.timeout` | 15 | Seconds before closing an idle connection |
| `maxConnections` | 1024 | Max simultaneous connections |
| `fileCache.maxSize` | 64MB | Total memory for cached file responses. When full, the file asked for longest ago makes room for the new one. Each file counts twice (a keep-alive and a closing form of its response). |
| `fileCache.maxFile` | 1MB | Largest file to cache in memory |
| `fileCache.checkInterval` | 1 | Seconds between file modification checks |
| `rateLimit.enabled` | false | Enable per-IP rate limiting |
| `rateLimit.requests` | 100 | Requests per window |
| `rateLimit.window` | 60 | Window in seconds |
| `webserver.requestTimeout` | 30 | Seconds a request may run before the client gets 504 and its worker is killed and replaced -- a pooled worker or a forked one alike; the request is not run again (0 = no limit) |
| `webserver.workerMemoryCeiling` | 256, or ¾ of `memory_limit` if lower | Heap size in MB past which a persistent worker answers its current request and is then replaced, with the reason logged. `0` disables. See [reset.md](reset.md#a-worker-that-grows-is-replaced) |
| `webserver.warmup` | (none) | Script run once in the parent, after the source transform and before forking, to warm the application copy-on-write. See [reset.md](reset.md) |
| `webserver.eventLoop` | `"auto"` | Event loop backend: `auto` (`revolt` if installed, else `select`), `iopoll` (native `Io\Poll`; opt-in, not yet tested against the real API), `revolt` or `select` (`stream_select`). The `QBIX_EVENT_LOOP` environment variable overrides it. See [architecture.md](architecture.md#event-loop-backends) |
| `webserver.debug` | false | What an uncaught error in a request shows in its 500 response: the message only, or with `true` (or `--debug`) also its class, file and line, the trace and every previous exception. The error log always gets the file and line of each exception in the chain. Off by default, because it puts server paths in a response |
| `dashboard` | (enabled) | Set to `false` to disable `/Q/dashboard`, `/Q/health`, and `/Q/ws` entirely |
| `dashboard.token` | (none) | When set, dashboard requires `?token=VALUE` in the URL |
| `dashboard.hidePanelRequests` | false | Leave the server's own `/Q/` requests (the dashboard and panel themselves, health checks, icons) out of the dashboard's counts, top paths and live log. See [dashboard.md](dashboard.md#hiding-the-panels-own-requests) |
| `autoload.psr-4` | `{}` | PSR-4 namespace mappings: `{"App\\": "src/"}` |
| `autoload.psr-0` | `{}` | PSR-0 prefix mappings: `{"Legacy_": "vendor/"}` |
| `socket.io` | `"/socket.io"` | Socket.IO endpoint. Protocol detection + client JS at `{path}/socket.io.js`. `false` to disable. |
| `socket.js` | `"/Q/socket.js"` | Path to serve the minimal bare-WebSocket client (3KB). `false` to disable. |
| `app` | `""` | App name — prefixes handler function names (e.g. `"Chess"` → `Chess_chat_message()`) |
| `webserver.fallback` | null | Catch-all: `"index.html"`, `{"handler":"app/notfound"}`, or `{"file":"404.html"}` |
| `webserver.hotReload` | `false` | Watch `classes/`, `handlers/`, `config/` for changes. Auto-restarts on class/config changes. |
| `webserver.cgi.patterns` | [] | Regex patterns for scripts that use php-cgi (legacy compatibility) |
| `webserver.cgi.binary` | auto | Path to php-cgi binary (auto-detected if not set) |
| `webserver.scripts` | (all) | Scripts that run when asked for by name, relative to the root (`["/index.php"]`). Any other `.php` goes to the front controller. See [Only the entry points run](#only-the-entry-points-run) |
| `web.static.paths` | (all) | Patterns on a file's path below the root; only a matching file is sent as it is, any other goes to the front controller. See [Only the entry points run](#only-the-entry-points-run) |
| `webserver.frontControllers` | `{}` | Path patterns to scripts, checked in order (`{"^/api/": "index_rest.php"}`); anything else goes to `index.php` |
| `webserver.zygote` | `false` | Fork workers started after the pool from a zygote -- a process forked before the first connection was accepted -- so a worker forked under load inherits no visitor's connection. Needs the `sockets` extension (SCM_RIGHTS), `pcntl` and `posix`; ignored without them, and if the zygote fails the pool forks from the server as before. See [workers.md](workers.md#forking-from-a-zygote) |
| `compat.statTtl` | `0` | Seconds (at most 10) a worker keeps what it knows about files -- whether a path exists, a file's mtime and size -- across requests, like `opcache.revalidate_freq`. `0` forgets at every request boundary. The worker's own writes, `clearstatcache()` and a program it runs are seen at once either way. See [compatibility.md](compatibility.md#remembered-file-facts) |

### Virtual hosts

Serve multiple domains from one server. Each host can have its own document root:

```json
{
    "Q": {
        "webserver": {
            "hosts": {
                "example.com": {
                    "root": "/var/www/example/web"
                },
                "api.example.com": {
                    "root": "/var/www/api/web"
                },
                "staging.example.com": {
                    "root": "/var/www/staging/web"
                }
            }
        }
    }
}
```

The `Host` header selects the root. Requests for unconfigured hosts use the default `--root` directory. WebSocket, rooms, handlers, and static files all respect the per-host root.

#### Per-host logs

A host can keep its own access and error log, by adding a `log` key to the same entry:

```json
{
    "Q": {
        "webserver": {
            "hosts": {
                "example.com": {
                    "root": "/var/www/example/web",
                    "log": true
                },
                "api.example.com": {
                    "root": "/var/www/api/web",
                    "log": {
                        "dir": "/var/log/api",
                        "accessName": "web.log",
                        "errorName": "web-error.log"
                    }
                }
            }
        }
    }
}
```

`"log": true` names the files after the host — `example.com-access.log` and `example.com-error.log`, in the server's own log directory. The object form overrides the directory, either filename, or all three; whatever is left out is named after the host.

A host with no `log` key writes to the server's access log, as before, and so does a request that arrives without a `Host` header. The default format, `vhost`, ends each line with the Host as one quoted field, so one log can still be split by domain (the control panel's Logs tab filters on it). `qbix` is the same line without that field, as this server wrote it before; ask for it, or any custom format, to keep the older shape:

```json
"log": { "format": "qbix" }
```

A host can also set `fileMode` and `dirMode`, and inherits the server's when it does not. That is what makes per-host logs useful where each site runs as its own user — the host writing the file is the one that owns it:

```json
"a.example.com": {
    "root": "/srv/a",
    "log": { "dir": "/var/log/a", "fileMode": "0660", "dirMode": "2770" }
}
```

See [deploy.md](deploy.md) for what those two do and the order to set them up in.

Each host's files rotate, archive and prune on the same terms as the server's own, and the dashboard's log viewer takes `?host=` to show one of them.

### HTTPS

`Q.web.https` chooses where the certificate comes from — your own files, an
archive or `.p12` from your CA, Let's Encrypt (built in), certbot, a URL, or a
self-signed certificate — and the server checks, renews and swaps it by itself:

```json
"https": { "port": 443, "mode": "letsencrypt", "acme": { "email": "admin@example.com", "domains": ["example.com"] } }
```

Every setting, Let's Encrypt in depth, the supported file formats and
troubleshooting: [HTTPS and Certificates](https.md).

### Hot reload

Watch `classes/`, `handlers/`, and `config/` for file changes:

```bash
php qbixserver.php --hotreload
```

Or via config:

```json
{
    "Q": {
        "webserver": {
            "hotReload": true
        }
    }
}
```

Handler changes take effect immediately — handlers are lazy-loaded, so the next request or connection picks up the new code. Class or config changes trigger a graceful restart (the server re-execs itself with the same arguments).

Changes are logged to stderr:

```
14:32:07 hot-reload: ~ handlers/chat/message.php 14:32:09 hot-reload: + classes/MyApp/NewFeature.php 14:32:09 hot-reload: restarting server...
```

Polls every 2 seconds. Recommended for development.

Even without `--hotreload`, handler changes take effect naturally: HTTP requests fork fresh and load handlers on demand, so the next request gets the new file. WebSocket connections and rooms keep the old code for their lifetime — new connections pick up the change. A natural rolling deploy with no interruption. The `--hotreload` flag adds automatic restart for class and config changes, which are preloaded in the parent process.

If `Q.handlers.preload` is `true` (production mode), handlers are also loaded in the parent — use `--reload` to pick up handler changes in that case.

### Scheduler

Run tasks on intervals or at specific times. Handlers are forked like HTTP requests — they don't block the event loop and respect `requestTimeout`.

```json
{
    "Q": {
        "scheduler": {
            "cleanup": {
                "handler": "tasks/cleanup",
                "every": 3600
            },
            "daily-report": {
                "handler": "tasks/report",
                "times": ["09:00"]
            },
            "business-check": {
                "handler": "tasks/check",
                "times": ["09:00", "12:00", "17:00"],
                "weekdays": ["mon", "wed", "fri"]
            },
            "monthly-invoice": {
                "handler": "tasks/invoice",
                "times": ["00:00"],
                "monthdays": [1]
            }
        }
    }
}
```

| Field | What it does |
|---|---|
| `handler` | Handler path — dispatched via `Q::event()`, same as HTTP handlers |
| `every` | Run every N seconds from startup |
| `times` | Run at specific `HH:MM` times (24h format) |
| `weekdays` | Only fire on these days: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun` |
| `monthdays` | Only fire on these days of the month: `[1]`, `[1, 15]`, etc. |

The handler receives `$params['task']` (the task name) and `$params['scheduled'] = true`:

```php
<?php
// handlers/tasks/cleanup.php function tasks_cleanup(&$params, &$result) {
    MyApp\Sessions::expireOld();
    MyApp\Logs::rotate();
}
```

On restart, tasks scheduled for the current minute are skipped to avoid double-firing. Interval tasks wait one full interval before their first run.

### Only the entry points run

By default every `.php` file inside the document root runs when its path is
requested. An application that keeps its entry points to a few scripts --
and ships libraries, installers and command-line tools beside them -- says
which ones may:

```json
{
    "Q": {
        "web": {
            "static": {
                "paths": ["^/(design/[^/]+/(stylesheets|images|javascript|fonts)/|var/([^/]+/)?storage/images/)"]
            }
        },
        "webserver": {
            "scripts": ["/index.php", "/index_rest.php"],
            "frontControllers": {
                "^/api/": "index_rest.php",
                "^/([^/]+/)?content/treemenu": "index_treemenu.php"
            }
        }
    }
}
```

A request for any other script, `/lib/tool.php` or `/lib/tool.php/extra`
alike, a directory's own `index.php` included, is treated as though the file
were not there: it goes to the front controller, as it would behind an
`.htaccess` that serves the assets and sends everything else to `index.php`.

`web.static.paths` does the same for files: with it set, only a file whose
path matches one of the patterns is sent as it is -- the `- [L]` rules of
such an `.htaccess`. Any other goes to the front controller, so an upload the
application hands out through a script that checks who is asking cannot be
fetched past it by its path. The path is judged after its dot segments are
resolved.

`frontControllers` is how a rule such as `RewriteRule ^api/ index_rest.php`
is said to the server. A pooled worker runs the script the server hands it
and does not read `.htaccess`, so a rewrite to a script other than
`index.php` has to be decided here. The first pattern that matches, and
whose script exists inside the root, wins. Both keys apply to HTTP/1.1 and
HTTP/2, in either worker mode.

A few things to know when setting them:

- **Anchor every pattern.** A pattern is matched anywhere in the path unless
  it says otherwise, so `/images/` also matches
  `/var/storage/original/images/secret.pdf`. Start each one with `^/`.
- **Names are compared exactly.** `/Index.php` is not `/index.php`, and a
  script whose extension is written in capitals is still a script: it runs
  only when listed as written. On a filesystem that ignores case, list the
  spelling your URLs use.
- **The lists hold for the whole server.** Every domain served from it,
  with its own document root or not, is judged by the same lists, relative
  to that domain's root.
- **Changing them drops stored pages.** The reverse cache is looked up before
  these checks, so a page stored while a script could still run would
  otherwise be answered after it no longer may. When the lists differ from
  those the stored entries were made under, the server starts a new cache
  generation at start-up, and every earlier entry is a miss from then on.
- **Server paths are not affected.** `/Q/`, the ACME challenge under
  `/.well-known/acme-challenge/` and a directory listing you have switched on
  for a path answer as before. Any other file under `/.well-known/` is a file
  like the rest, so list `^/\.well-known/` if you serve one.

### CGI carveout mode — legacy PHP compatibility

Scripts matching `Q.webserver.cgi.patterns` run via `php-cgi` subprocess instead of fork. Native `header()`, `setcookie()`, `session_start()` all work — full compatibility with WordPress, Laravel, or any PHP code that calls `header()` directly.

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "/wp-admin/.*\\.php$",
                    "/wp-login\\.php$",
                    "/legacy/.*\\.php$"
                ]
            }
        }
    }
}
```

The tradeoff: CGI mode starts a fresh PHP interpreter per request (~50ms), so you don't get the preload speed benefit. Static files, caching, and everything else still work at full speed. Use this for third-party code you can't modify — your own code should use `Q_Response::header()` and the fork path for 100–300× concurrent capacity (measured).

The server auto-detects `php-cgi` on your system. Override with `cgi.binary`:

```json
{ "Q": { "webserver": { "cgi": { "binary": "/usr/bin/php-cgi8.3" } } } }
```

### Framework presets — the one-flag path

Before the hand-configuration below, there are presets. `--preset=NAME` (or
`Q.compat.preset` in config) loads the front-controller rewrite, the ini limits
and any framework-specific settings that application needs, in one flag:

```
php bin/qbixserver.phar --root=public --preset=laravel
php bin/qbixserver.phar --root=.      --preset=wordpress
php bin/qbixserver.phar --root=.      --preset=exponential
```

| Preset | Rewrite | Notable |
|---|---|---|
| `laravel` | `index.php` | 60s execution, 256M |
| `symfony` | `index.php` | 60s execution, 256M |
| `wordpress` | `index.php` | 64M uploads, 300s execution |
| `drupal` | `index.php` | `?q=` query rewriting, 32M uploads |
| `exponential` | `index.php` | source-code transform kept ON; the eZ type registries kept across requests (`keepGlobals`) — see below |

`exponential` is the one that carries settings a modern framework does not need
and would otherwise be found the hard way: its kernel calls `header()` and
`setcookie()` the SAPI-coupled way, so the source-code transform must stay on to
carry them to the response under a persistent worker; and its datatype, workflow
and notification registries are populated once via `include_once` and must be
preserved between requests, or publishing fails with
`Call to a member function initializeEvent() on null`. The preset sets both. It
is also the one preset that writes under `Q.webserver` (for `keepGlobals`), not
only `Q.compat`.

An unknown preset name is refused and prints the available list, rather than
starting misconfigured.

### Running legacy PHP — WordPress, Laravel, Symfony

You can run existing PHP applications on Qbix Server without modifying their code. The key: put the framework's public directory as `web/`, and use CGI carveout patterns to match all PHP files.

**WordPress:**

```
wordpress-site/ ├── qbixserver.php          ← copy here ├── src/                    ← copy here ├── config/ │   └── server.json └── web/                    ← symlink or copy of WordPress root
    ├── wp-admin/
    ├── wp-content/
    ├── wp-includes/
    ├── wp-login.php
    ├── index.php
    └── wp-config.php
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["\.php$"]
            },
            "fallback": "index.php"
        }
    }
}
```

The pattern `\.php$` sends all PHP files through `php-cgi`. The fallback sends unmatched URLs to `index.php` (WordPress permalink routing). Static files (images, CSS, JS) are served directly at full speed.

**Laravel:**

```
laravel-app/ ├── qbixserver.php ├── src/ ├── config/ │   └── server.json ├── web/                    ← symlink to Laravel's public/ │   ├── index.php │   └── .htaccess           ← ignored (no Apache) ├── app/ ├── routes/ ├── storage/ └── vendor/
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["\.php$"]
            },
            "fallback": "index.php"
        }
    }
}
```

All requests that don't match a static file go to `index.php`. Laravel's router takes over from there. The `app/`, `vendor/`, and `storage/` directories are outside `web/` — inaccessible via URL by default.

**Symfony:**

```
symfony-app/ ├── qbixserver.php ├── src/ ├── config/ │   ├── server.json │   └── ...                 ← Symfony config files ├── web/                    ← symlink to Symfony's public/ │   └── index.php ├── src/                    ← Symfony source (separate from Qbix src/) ├── var/ └── vendor/
```

Same config pattern. Symfony's front controller (`public/index.php`) handles all routing internally.

**Porting your own legacy code:**

For code you control, you have three options — from least effort to best performance:

**Option 1: Full CGI (zero changes, slower)**

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["\.php$"] } } } }
```

Every PHP file runs through `php-cgi`. Native `header()`, `setcookie()`, `session_start()` all work. No code changes. Performance is comparable to nginx + php-fpm (no preload benefit).

**Option 2: Targeted carveouts (minimal changes, mostly fast)**

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "/admin/.*\.php$",
                    "/legacy/.*\.php$"
                ]
            }
        }
    }
}
```

Only specific paths use CGI. New code and simple scripts use fork mode (100–300× concurrent capacity (measured)). Legacy code that calls `header()` directly stays in CGI mode.

**Option 3: Find-replace (one-time effort, full performance)**

In your PHP files, replace:
```
header(       →  Q_Response::header( setcookie(    →  Q_Response::setCookie(
```

Two find-replaces. Your code now uses fork mode everywhere — 30× concurrent capacity capacity, preloaded classes, shared-nothing safety.

### Installing php-cgi

CGI carveout mode requires the `php-cgi` binary:

```bash
# Ubuntu/Debian
sudo apt install php-cgi

# macOS
brew install php    # includes php-cgi

# CentOS/RHEL
sudo yum install php-cgi

# Verify
php-cgi --version
```

---

---
[← Back to README](../README.md)

