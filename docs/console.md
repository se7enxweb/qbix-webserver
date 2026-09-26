## ⌨️ Console and Command Line

The server is run and managed from three command lines. `qbixserver.php` is the
server itself. `qbixconsole` is its command console, in the manner of Symfony's:
named commands grouped by namespace, with `list` and `help`. `qbixctl` answers to
the words of `apache2ctl` and the `a2ensite` family, and runs the matching console
command.

- [qbixserver.php](#qbixserverphp)
- [qbixconsole](#qbixconsole)
- [qbixctl](#qbixctl)
- [Options every command shares](#options-every-command-shares)
- [Option styles](#option-styles)

---

### qbixserver.php

```sh
php qbixserver.php --root=web --port=8080 --workers=8
php qbixserver.php --config=/etc/qbix/sites-enabled/example.com.conf
php qbixserver.php --help
```

| Option | Meaning |
|---|---|
| `--root=DIR` | Document root (default `./web`). |
| `--app=DIR` | A Qbix app directory, served with the full Q framework. |
| `--host=IP`, `--port=PORT` | Bind address (default `0.0.0.0`) and HTTP port (default `80`). |
| `--https-port=PORT` | HTTPS port. See [https.md](https.md). |
| `--socket=PATH`, `--socket-mode=MODE` | Also listen on a Unix domain socket, with these permissions (default `0660`). |
| `--workers=N` | Persistent workers. See [workers.md](workers.md). |
| `--config=FILE` | JSON configuration, normally `<conf dir>/sites-enabled/<site>.conf`. |
| `--conf-dir=DIR` | The configuration directory. See [layout.md](layout.md). |
| `--distribution=NAME` | Load a distribution's additions. |
| `--layout` | Print the configuration files that would be loaded, as JSON, and exit. |
| `--preset=NAME` | Framework preset: `laravel`, `symfony`, `wordpress`, `drupal`, `exponential`. |
| `--pid=PATH` | The pid file. |
| `--stop`, `--reload` | Stop the running server, or re-exec it, through its pid file. |
| `-t` | Test the configuration and exit. |
| `--hotreload` | Watch files and restart when they change. |
| `-q`/`--quiet`, `--verbose`, `--debug` | Errors only; also every certificate provider tried; every event. `--debug` also puts the file, line, trace and previous exceptions of an uncaught error into its 500 response (`webserver.debug`). |
| `--open[=/path]` | Open a browser once the server is ready. |
| `--version`, `-v` | Print the version. |

Signing, verifying and packing (`--sign`, `--verify`, `--generate-key`,
`--sign-binary`, `--verify-binary`, `--pack`) are described in
[binaries.md](binaries.md); `--deploy` in [deploy.md](deploy.md). `--help` lists
every option.

---

### qbixconsole

```sh
qbixconsole list                     # every command
qbixconsole help server:start        # one command's options
qbixconsole server:status
qbixconsole ser:stat                 # a unique abbreviation works
```

| Command | Alias | What it does |
|---|---|---|
| `server:start` | `start` | Start the server detached, and wait until its ports listen. Anything after `--` goes to `qbixserver.php`. |
| `server:stop` | `stop` | Stop the server and wait for it to exit. |
| `server:reload` | `graceful`, `reload` | Re-exec the server without dropping its listening sockets. |
| `server:restart` | `restart` | Stop, then start. |
| `server:status` | `status` | Whether the server runs, its pid file, and which configured ports listen. `--json` for a program. |
| `server:configtest` | `configtest` | Check that every configuration file parses. |
| `layout:show` | `layout` | The configuration trees, their files and what is enabled. |
| `site:enable`, `site:disable` | `ensite`, `dissite` | Enable or disable a site. |
| `conf:enable`, `conf:disable` | `enconf`, `disconf` | Enable or disable a conf snippet. |
| `mod:enable`, `mod:disable` | `enmod`, `dismod` | Enable or disable a module. |
| `cache:clear` | — | Invalidate every page in the response cache. See [cache.md](cache.md). |
| `ssl:show`, `ssl:check`, `ssl:issue`, `ssl:renew` | `ssl` | Certificates. See [https.md](https.md#the-console). |

Every command exits `0` on success. `server:status` exits `3` when the server is not
running, as LSB init scripts report it, so a monitoring script can tell "stopped"
from "failed". Output is coloured only when it goes to a terminal.

The server commands find the running server by its pid file: `--pid`, else
`Q.webserver.pidFile`, else `/run/qbix/qbixserver.pid` when that directory is
writable, else the temporary directory. `server:start` writes the server's output
to `--log` and waits up to `--wait` seconds (`20`) for it to listen.

When the pid file is missing or stale, `status`, `stop` and `graceful` look for
the server in the process table instead (on systems with `/proc`), and
`status` then says `(discovered)`. Only a server the options describe is
taken: the same pid file, the same root (and port, when given) or the same
`--config`; with none of those given, the one server of this engine that is
running. Another site served by the same engine is never stopped by mistake,
and `start` refuses only when this site is already running. A discovered server
started without `--pid` is signalled directly.

A distribution of the engine can add commands of its own when it registers.

---

### qbixctl

```sh
qbixctl start | stop | restart | graceful | status
qbixctl -t                  # configtest
qbixctl -S                  # layout:show
qbixctl ensite example.com  # site:enable example.com
```

Each word is translated to its console command and everything after it is passed
on unchanged, so `qbixctl` takes the same options as the command it runs.

| qbixctl | qbixconsole |
|---|---|
| `start`, `stop`, `restart`, `status` | `server:start`, `server:stop`, `server:restart`, `server:status` |
| `graceful`, `reload` | `server:reload` |
| `-t`, `configtest` | `server:configtest` |
| `-S` | `layout:show` |
| `ensite`, `dissite` | `site:enable`, `site:disable` |
| `enconf`, `disconf` | `conf:enable`, `conf:disable` |
| `enmod`, `dismod` | `mod:enable`, `mod:disable` |

---

### Options every command shares

Every command that reads the configuration sees what the server would: the
distribution, the stack of configuration trees and the site file are loaded the
way `qbixserver.php` loads them.

| Option | Default | Meaning |
|---|---|---|
| `--conf-dir` | from `--config`, `QBIX_CONF_DIR`, or none | The configuration directory. |
| `--config` | — | The site file, normally `<conf dir>/sites-enabled/<site>.conf`. |
| `--distribution` | `QBIX_DISTRIBUTION`, then the `DISTRIBUTION` file | The distribution to load. |
| `--pid` | see above | The pid file (server commands). |

The enable and disable commands change the top tree of the stack. The operations
behind the commands are plain static methods of `Q_WebServer_Ctl`, so a program
driving the server can call them instead of running the console.

---

### Option styles

Every command line here takes GNU and BSD spellings alike:

| Form | Meaning |
|---|---|
| `--name=V`, `--name V`, `-name=V`, `-name V` | an option that takes a value |
| `--name`, `-name` | a flag |
| `--no-name` | a flag turned off (the last spelling wins) |
| `-abc` | one-letter flags bundled (`qbixconsole`) |
| `--` | ends the options; the rest is passed on as plain arguments |

A single-dash word is read as a long option only when it is a known option name,
so one-letter options (`-t`, `-h`, `-v`) keep their meaning. A value option takes
the next argument only when that does not start with a dash: give an optional value
with `=`, as in `--open=/admin`. See also [running.md](running.md#option-styles).

---
[← Back to README](../README.md)
