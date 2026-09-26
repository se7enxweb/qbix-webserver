# ⚡ Exponential Velocity — A Qbix based webserver package

### Run your existing PHP codebase 10–100× faster than nginx + php-fpm

A pure PHP web server. No nginx, no Apache, no php-fpm. One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

## How is Exponential Velocity different from Qbix?

Exponential Velocity is a production-focused distribution built on the Qbix web
server. It keeps everything Qbix does — the copy-on-write worker model,
WebSockets, and the live dashboard — and your PHP still runs unmodified. On top
of that, it adds the packaging, control plane, and site-operations tooling you
need to actually run it in production:

- **Install it however you like.** Static binaries for Linux (x86-64, ARM64),
  macOS (Apple Silicon) and Windows, `.deb` and `.rpm` packages that upgrade in
  place, Docker images, or a single `.phar` — built and tested across PHP
  8.2–8.5 in four sizes (mini, lite, standard, full).
- **A control panel built for the public internet.** Credentialed access to the
  dashboard, logs, metrics and app inspector, a root-owned credential store,
  forced change of the default password, login lockout, and — for a browser —
  a clean redirect to sign in and back to the page you wanted.
- **Manage your whole site from the panel.** Domains, aliases, subdomains and
  document roots; HTTP→HTTPS, `www`/bare and custom redirects; HSTS and custom
  error pages — no config files to hand-edit.
- **HTTPS that manages itself.** Built-in Let's Encrypt (ACME) issuance and
  renewal, per-domain certificates chosen by SNI, and an SSL panel that shows
  what covers each domain and when it expires.
- **Two-factor for the admin panel.** Optional TOTP two-factor sign-in
  (Google Authenticator, Authy, any RFC 6238 app) with one-time recovery
  codes, off by default and enabled with a single setting.
- **A built-in admin shell.** A drop-down console on every panel view runs the
  server's own commands, with a searchable history of over 100,000 entries.
- **Serve only what you list.** An allowlist of entry scripts and static paths
  means only the files you name are served or executed; everything else goes to
  your front controller.
- **Fast, accessible admin views.** Gzip/Brotli-compressed panel pages, a shared
  stylesheet, and a 100/100 Lighthouse accessibility score.
- **Operate it like Apache.** A Debian-style `/etc/vc` layout
  (`sites-available`/`sites-enabled`, `conf`/`mods`), `a2ensite`-style
  enable/disable, and `apache2ctl`-style start/stop/reload/status.
- **Built to stay up.** A worker pool that sizes itself to available RAM, a
  design where a worker dying never fails a request, correct TLS shutdown from
  forked workers, and safe operation under opcache.
- **No stuck connections from a growing pool.** With the zygote (on by default), workers
  forked under load come from a process that never held a visitor's connection,
  so none is kept open in `CLOSE-WAIT` by a worker; honest `Connection` headers at
  the keep-alive limit; and file facts remembered per request (optionally a little
  longer with `Q.compat.statTtl`) to cut CPU per rendered page.
- **Optional peer-to-peer mesh and mobile transports** (experimental, off by
  default) for BLE/Wi-Fi device meshes.
- **Proven across platforms.** A CI matrix boots the server on Linux, the BSDs,
  Alpine/musl, illumos and more, and every release ships tested binaries and
  packages.

### The problem with php-fpm

Whether opcache is enabled or not, the vast majority of production PHP code is I/O-bound. Workers wait for the database, the filesystem, an API call, a cache server. During that wait, the worker is doing nothing — but it's still holding 30–60MB of RAM. That's the bottleneck. On a 4GB server, php-fpm gets maybe 80 workers. Each one blocks on a 200ms query, so you get ~400 req/s. That's the ceiling.

### The problem with Swoole, RoadRunner, and FrankenPHP

They try to solve this by making PHP evented, like Node.js. Swoole's coroutines can multiplex I/O within a single worker — but only if you rewrite your code to use `Swoole\Coroutine\MySQL`, `Swoole\Coroutine\Http\Client`, and so on. Every `PDO::query()`, every `file_get_contents()`, every `curl_exec()` in every WordPress plugin, Laravel package, and Drupal module uses blocking I/O. It doesn't yield. Swoole can't help with code that doesn't cooperate. RoadRunner and FrankenPHP don't even try coroutines — they use the same worker-count-limited model as fpm.

### How Qbix solves it

Instead of making each worker do more, Qbix runs more workers. The server loads your entire framework into a parent process, then calls `pcntl_fork()`. The kernel marks every page copy-on-write. Each worker shares the parent's loaded classes and pays only for the pages it writes to after the fork. Measured as private memory (not RSS, which counts shared pages once per worker): about 1.3–1.9 MB per worker with nothing loaded, about 10 MB for a full CMS. So the same 4GB that gives fpm 80 workers gives Qbix a few hundred CMS workers, or around two thousand small ones -- within the event loop's ceiling of about 1,000 workers per pool ([what a worker costs](docs/workers.md#what-a-worker-costs)).

Your code runs unmodified, in two modes:

**Persistent workers (default)** — workers stay alive across requests. Between each request, a Reflection-based snapshot restores all static properties, and globals and the shimmed functions' state are reset: about 0.5 ms for a small application, 4–5 ms for a CMS with ~600 classes -- around 1.5% of a typical page render. 44 PHP functions (`header()`, `session_start()`, `ini_set()`, `set_error_handler()`, etc.) are shimmed via source transformation so they reset correctly. This is how you get 2,294 req/s on CPU-bound work and 1,060 req/s under I/O.

**Fork-per-request** — if persistent mode doesn't work for your code (functions with internal static variables, plugins that register global state in ways the shim can't track), set `forkPerRequest: true`. Each request gets a fresh fork. It's slower than persistent mode, but each forked worker still costs a few MB instead of 50MB, so you can run many times more of them than fpm on the same hardware. That's the whole point — blocking I/O doesn't matter when you have enough workers, and COW makes "enough workers" nearly free.

### What it replaces

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 💾 **Memory per worker** | 30–60MB (duplicated) | **1.3–1.9 MB** bare, **~10 MB** full CMS (COW, private, measured) |
| 👥 **Concurrent PHP** (1GB) | ~24 workers | **~530** bare, **~90** full CMS (under ~1,000 per pool with `stream_select`) |
| 🔒 **Isolation** | Statics leak between requests | Snapshot reset — no leaks |
| 🚀 **Throughput** (CPU-bound) | ~400 req/s (Swoole 4w) | **2,294 req/s** (100w) |
| 🚀 **Throughput** (I/O, same RAM) | 78 req/s (fpm/Swoole 4w) | **1,060 req/s** (100w) |
| 🌐 **WebSocket** | Needs a separate server | Built in |
| 🧩 **Cache invalidation** | Whole-page only | `X-Q-Cache-Tree` — per-component |
| ⚙️ **Setup** | nginx + fpm pools + sockets | `php qbixserver.php` |

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and [reset.md](docs/reset.md) for what gets restored between requests.

### What a "real-time PHP app" used to require

**nginx** for reverse proxy and static files. **php-fpm** to run PHP. **Node.js** for a Socket.IO server. **Redis** for pub/sub between fpm and Node. **supervisor** to keep it all running. **Docker** to make it deployable. Six processes, three languages, two runtimes.

Qbix Server replaces all six with one process. HTTP, WebSocket (with Socket.IO protocol), SSE, sessions, uploads, static files, .htaccess — same port, same file. No Redis, no Node, no pub/sub glue. Download a 4.5MB binary, run it, done. Pure PHP.

You can also package your entire app — code, assets, SQLite database — into that binary and distribute it as a single file. Double-click on Windows, `./myapp --open` on Mac or Linux, the browser opens and the app is there. No PHP to install, no web server to configure, no database to set up. 5 MB, not 200 — because we open the browser that's already there instead of shipping Chromium like Electron does. [How it works →](#single-binary-distribution)

---


## Documentation

| | Topic | What it covers |
|---|---|---|
| 📋 | [Changelog](CHANGELOG.md) | Every change worth reading, newest first. A release links here rather than repeating itself |
| 🏎️ | [Why Not php-fpm?](docs/why.md) | COW memory model, comparison with Swoole and FrankenPHP |
| 📋 | [Requirements & Extensions](docs/requirements.md) | The PHP versions built, every extension the server provides by tier and variant, databases, and each form of distribution's exceptions |
| 🧩 | [Extensions: Check & Build](docs/extensions.md) | `qbixctl ext:check`, install hints per system, database add-ons, planning and building a variant, the source kit |
| 🔐 | [HTTPS & Certificates](docs/https.md) | Your own certificates (files, archives, .p12), Let's Encrypt built in, self-signed fallback, live renewal |
| 🔒 | [Server Headers](docs/headers.md) | Cache-Control, X-Q-Cache-Tree, X-Accel-Redirect, ETag |
| 🗃️ | [Response Cache](docs/cache.md) | What is kept and for how long, the generation marker, every setting |
| 🛡️ | [Security](docs/security.md) | What is refused and why: document-root containment, request framing, HTTP/2 frame validation, header injection |
| 🛂 | [Control Panel: signing in](docs/panel.md) | The default password `panel`, the first sign-in, locking the default down, a forgotten password |
| 🔑 | [Panel Passwords](docs/passwords.md) | bcrypt storage, the password rules with examples, the default key and its forced change, lockout, `qbixctl panel:password` |
| 🔐 | [Two-Factor Auth](docs/2fa.md) | Optional TOTP second factor for the panel (off by default): enrollment, recovery codes, the config flag, `qbixctl panel:2fa` |
| 🌐 | [HTTP](docs/http.md) | Fork-per-request mode, request lifecycle |
| 🔌 | [WebSocket & Rooms](docs/websocket.md) | Process per connection, rooms, Socket.IO, SSE, chat example |
| 🛤️ | [Routing](docs/routing.md) | Clean URLs, .htaccess, DirectoryIndex |
| 📂 | [PHP Framework](docs/framework.md) | The micro-framework: handlers, events, Q classes |
| ⚙️ | [Configuration](docs/configuration.md) | JSON config, CLI options, presets |
| 🗂️ | [Configuration Layout](docs/layout.md) | `/etc/qbix` laid out like `/etc/apache2`, load order, overlays, distributions |
| ⌨️ | [Console & Command Line](docs/console.md) | `qbixserver.php` options, `qbixconsole` commands, `qbixctl`, option styles |
| 📦 | [Running & Building](docs/running.md) | Source, phar, binary. Building static binaries. Requirements |
| 📀 | [Binaries & Signing](docs/binaries.md) | Pack apps, manage like zip, ECDSA M-of-N signing, Rekor, platform signing |
| 🏗️ | [Architecture](docs/architecture.md) | Persistent workers, COW, execution model, mental model, benchmarks |
| 🏭 | [Workers & Pool](docs/workers.md) | Pool size, static and dynamic pools, when a worker is replaced, reload |
| 🧭 | [Lessons](docs/lessons.md) | What persists between requests, what workers inherit, memory, opcache, TLS in a forked child |
| ⏳ | [Time-Consuming Lessons](docs/time-consuming-lessons.md) | The problems that took longest to diagnose: symptom, cause, how to recognise it, fix |
| 📊 | [Dashboard & Panel](docs/dashboard.md) | Live stats, control panel tabs |
| 🖥️ | [Q Shell](docs/shell.md) | Drop-down console on every server view: tiers, jobs, sudo, REST API |
| 🎨 | [Designs](docs/designs.md) | Restyle the dashboard, panel, docs, listing and error pages from `designs/` |
| 🚀 | [Deploy & Federation](docs/deploy.md) | Rsync deploy, cluster replication, inter-server trust |
| 🔍 | [API Discovery](docs/api-discovery.md) | OpenAPI, MCP, qbix.json, HTTP/2 |
| 🧩 | [Compatibility](docs/compatibility.md) | SAPI emulation, 44 shimmed functions, class ownership, tests |
| 📈 | [Benchmarks](docs/BENCHMARKS.md) | Full methodology and numbers |
| 🔄 | [State Reset](docs/reset.md) | What gets restored between requests |
| 🔀 | [Migrate from nginx](docs/migrate-nginx.md) | Server blocks, try_files, proxy_pass, gzip |
| 🔀 | [Migrate from Apache](docs/migrate-apache.md) | .htaccess unchanged, VirtualHost mapping |
| 🔀 | [Migrate from Caddy](docs/migrate-caddy.md) | Automatic HTTPS, on-demand TLS → autohost |
| ✅ | [Test Results](docs/TestResults.md) | 140 end-to-end tests |
| 🗺️ | [Roadmap](docs/roadmap.md) | What's next |
| 📄 | [License](docs/license.md) | MIT |

## Quick Start

```bash
git clone https://github.com/Qbix/webserver
cd webserver
php qbixserver.php
```

### Download a binary

Self-contained: PHP is inside the binary, so there is nothing to install and
no version of PHP on the machine to conflict with.

```bash
# Linux x86_64
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64
chmod +x qbixserver-linux-x86_64 && ./qbixserver-linux-x86_64

# Linux aarch64 -- also what a Raspberry Pi 4 or 5 runs
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-aarch64
chmod +x qbixserver-linux-aarch64 && ./qbixserver-linux-aarch64

# Windows x64
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-windows-x64.exe
qbixserver-windows-x64.exe
```

[**All downloads &rarr;**](https://github.com/se7enxweb/exponential-velocity/releases/latest)

### Or run the phar, anywhere PHP runs

The binaries exist for convenience, not necessity. `qbixserver.phar` is the
same server and needs nothing but a PHP 8.1 or later interpreter, which is why
it runs on far more than the handful of platforms we can build binaries for.

```bash
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver.phar
php qbixserver.phar --root=./web
```

## Use With Your Existing Codebase

If you already have a PHP app running on nginx + php-fpm, switching is one command. The server reads your `.htaccess`, rewrites URLs to your front controller, and runs your code with 44 functions shimmed so static variables, sessions, and headers work correctly between requests.

**Laravel:**

```bash
cd my-laravel-app
php /path/to/qbixserver.php --root=public --preset=laravel --port=8080
```

**Symfony:**

```bash
cd my-symfony-app
php /path/to/qbixserver.php --root=public --preset=symfony --port=8080
```

**WordPress:**

```bash
cd my-wordpress-site
php /path/to/qbixserver.php --root=. --preset=wordpress --port=8080
```

**Drupal:**

```bash
cd my-drupal-site
php /path/to/qbixserver.php --root=web --preset=drupal --port=8080
```

**Exponential** (the eZ Publish 4 legacy line):

```bash
cd my-exponential-site
php /path/to/qbixserver.php --root=. --preset=exponential --port=8080
```

The `exponential` preset keeps the source-code transform on and preserves the
kernel's type registries across requests — settings a modern framework does not
need. See [Configuration](docs/configuration.md#framework-presets--the-one-flag-path).

**Any PHP app with a front controller:**

```bash
php /path/to/qbixserver.php --root=public --port=8080
```

If the root directory has an `index.php`, all clean URLs automatically route to it (the same behavior as `try_files $uri $uri/ /index.php` in nginx). If there's a `.htaccess`, its `RewriteRule` and `RewriteCond` directives are applied.

### What `--preset` does

Each preset sets framework-appropriate defaults: the front controller path, upload limits, memory limits, and session GC settings. You can override any of these in a JSON config file. The preset is a convenience — without it, the server still works if your `.htaccess` handles routing.

### What gets shimmed

The server intercepts 44 PHP functions (`header()`, `session_start()`, `setcookie()`, `ini_set()`, `file_exists()`, `exec()`, etc.) via source transformation at include time. Your code calls `header()` and it works — the server captures it. Between requests, all static properties are restored from a snapshot in 0.03ms. See [reset.md](docs/reset.md) for the full list and [Compatibility](docs/compatibility.md#remembered-file-facts) for what the file functions remember.

### What to watch for

Most apps work immediately. A few things to be aware of:

- **`define()` constants** persist between requests in persistent workers. If a plugin defines a constant conditionally, the second request sees it already defined. Rare in practice.
- **`stream_wrapper_register()`** persists. Uncommon outside testing frameworks.
- **Long-running scripts** (migrations, imports) should use `--workers=1` or run via CLI directly.
- **Extensions that store C-level state** (e.g. some custom PECL modules) won't reset between requests. Standard extensions (PDO, curl, mbstring) are fine.

## Platform Support

There are two ways to run Exponential Velocity, and they reach very different
numbers of platforms.

A **binary** carries its own PHP, so there is nothing to install. We ship these
for the targets the build toolchain supports, and that list is short by nature:
PHP is C with a great many statically linked dependencies and does not
cross-compile the way a Go program does.

The **phar** is the same server in one file and carries no PHP of its own, so
the only question it asks of a platform is whether PHP 8.1 or later runs there.
That is a far longer list, and it is the reason the table below is as wide as
it is.

### Binaries — nothing to install

| Platform | Download | State |
|---|---|---|
| **Linux** x86_64 | [`qbixserver-linux-x86_64`](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64) | shipped |
| **Linux** aarch64 &middot; Raspberry Pi 4 / 5 | [`qbixserver-linux-aarch64`](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-aarch64) | shipped |
| **macOS** arm64 &middot; Apple Silicon | [`qbixserver-macos-arm64`](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-macos-arm64) | shipped |
| **Windows** x64 | — | does not build yet |

### The phar — anywhere PHP 8.1+ runs

[`qbixserver.phar`](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver.phar)

```bash
php qbixserver.phar --root=./web
```

[![Platforms](../../actions/workflows/platforms.yml/badge.svg)](../../actions/workflows/platforms.yml)

**Proven.** Each of these boots in CI and is watched serving a page, twice — the
second request goes to a worker that has already served one, which is where a
persistent-worker server goes wrong if it goes wrong anywhere. The badge above
is the authority; a row here is a platform we test, not one we promise.

| Family | Platforms | Notable because |
|---|---|---|
| **Linux** glibc | x86_64, aarch64, armv5, armv7, i386, ppc64le, riscv64, s390x | eight architectures from one file |
| **Linux** musl | Alpine on x86_64, aarch64, i386 | a different C library, not a different build |
| **BSD** | FreeBSD 14, DragonFly BSD | real kernels in a VM, not emulated Linux |
| **illumos** | OmniOS | the surviving OpenSolaris line |

Two of those earn their place for specific reasons. **s390x is big-endian**, and
this server has several binary protocols — `pack('N')` framing, ETags, HPACK,
shared-dictionary compression — so it is where a byte-order mistake shows up;
the full suite passes there. **Alpine** is not exotic at all, it is most of the
Docker images in the world, and musl is where assumptions about DNS, threads
and locales break first.

**Hardware this already covers.** A Raspberry Pi 4 or 5 runs the shipped
aarch64 binary; a Pi 2, 3 or Zero 2 runs the phar on armv7. **IBM Z** is s390x
and **IBM Power** is ppc64le, both proven above; an IBM xSeries is ordinary
x86_64.

**Expected, not yet proven.** These have a maintained PHP 8.1+ and no reason not
to work, but nothing here has watched them do it, so they are listed as what
they are:

| Platform | Why it should work | Why it is not proven |
|---|---|---|
| **AIX** on Power | IBM ships PHP 8 in the AIX Toolbox | no CI runner, no emulator |
| **IBM i** (AS/400) | a maintained PHP 8 is published for it | same |
| **OpenBSD**, **NetBSD** | PHP 8 in ports and pkgsrc | in the matrix; being worked on |
| **Solaris 11** | PHP 8 packages exist | no runner |
| **Haiku** | PHP is in HaikuPorts | its installer is GUI-only, so CI cannot boot it unattended |
| **Windows** via the phar | PHP 8 for Windows is official | the phar path is untested there; only the binary is known broken |

The test for any of them is one line on the machine in question:

```bash
php -v
```

If that says 8.1 or later, download the phar and run it. That really is the
whole requirement, and if it works we would like to hear so we can move the row
up a table.

**Not today.** Worth stating plainly, because these come up and deserve a
straight answer rather than silence. One of them is a hardware limit and the
others are simply work nobody has finished:

| Platform | Why not |
|---|---|
| Commodore 64, Apple II, other 8-bit machines | a 6502 with 64KB. PHP needs an MMU, a 32- or 64-bit CPU and tens of megabytes. Memory is not the binding constraint; nothing about the machine is. |
| AmigaOS 4, MorphOS, AROS | not yet, and not because of the hardware — their PHP ports stop at 5.x. See below: the work is a PHP port, and once someone finishes one the phar runs unchanged. |
| z/OS | no current PHP 8 for USS that we know of |

#### AmigaOS, MorphOS and AROS — what it would take, for anyone tempted

This question gets asked seriously, so here is a serious answer. AmigaOS 4,
MorphOS and AROS are real pre-emptive multitasking systems, a modern machine
running one has gigabytes of memory, and **memory has never been the
constraint.** The gap is simply that PHP on those systems stopped at 5.x, and
the distance from there to 8.1 is library work.

The encouraging part is where that work stops. **Nothing in it is about this
server.** The phar has no architecture and no C library of its own, so the day a
PHP 8.1 exists for one of these systems is the day Velocity can run on it,
with no port of our code at all. One person finishing a PHP port unlocks the
whole thing.

What follows is a map drawn from PHP's requirements and the documented shape of
those systems. Nobody here has attempted it, so treat it as terrain to survey
rather than a report from inside.

**Start with AROS on x86.** It is the shortest path by a distance: ordinary x86
toolchains, no PowerPC cross-compiler to build first, and the most conventional
libc of the three.

**Two things are interesting rather than routine, and worth knowing early:**

*Process creation.* AmigaOS creates processes with `CreateNewProc()`, which
starts a new process running a named function rather than duplicating the
caller. Velocity's fastest mode uses copy-on-write `fork()`, so that mode would
not carry across — but the server already has a path for exactly this, because
Windows has no `fork()` either. It runs there through `php-cgi` subprocesses,
persistent and shimmed, just without the memory sharing. So this costs
performance, not viability.

*Sockets and files.* Networking comes from `bsdsocket.library`, whose handles
live in their own namespace rather than the file descriptor table PHP assumes.
This is the piece that decides whether PHP compiles or actually serves, and it
is where a porter's time is best spent first.

**The rest is ordinary work, and there is a lot of it:**

| Area | What is needed |
|---|---|
| C library | `newlib` and `clib2` need the gaps PHP 8 assumes closed — `getaddrinfo`, full `poll`/`select` semantics, `mmap`-style allocation for the Zend memory manager |
| Toolchain | a current GCC or Clang for the target, building PHP 8's C99/C11 sources |
| Dependencies | current PCRE2, libxml2, OpenSSL, zlib, libiconv — each its own port |
| Signals | POSIX signal semantics, used here for shutdown and reaping workers |
| Extensions | `sockets` needs the bsdsocket reconciliation above; `pcntl` and `posix` follow from process creation |

None of that is exotic. It is the same list every platform has worked through
at some point, and the Amiga community has closed longer ones.

**If you get there, tell us.** The test is one line on the machine:

```bash
php -v
```

If it reports 8.1 or later, then `php qbixserver.phar --root=./web` should
simply work. Send word that it serves a page and the row moves into the proven
table the same day — and it would be the most interesting entry in it.

### How workers behave, per platform

| Platform | Workers | Copy-on-write | Mode |
|---|---|---|---|
| **Linux** x86_64, aarch64 | `pcntl_fork` | yes — a few MB per worker | persistent or fork-per-request |
| **macOS** Intel, Apple Silicon | `pcntl_fork` | yes | persistent or fork-per-request |
| **BSD**, **illumos** | `pcntl_fork` | yes | persistent or fork-per-request |
| **Windows** x64 (via phar) | `php-cgi` subprocess | no | persistent workers, source-transform shimming |

On Linux, the BSDs and macOS the server runs COW-forked workers that share the loaded code and each pay for their own private pages -- 1.3–1.9 MB with nothing loaded, ~10 MB for a full CMS ([measured](docs/workers.md#what-a-worker-costs)).
Windows has no `pcntl`, so it spawns `php-cgi` subprocesses for isolation:
workers are still persistent and still shimmed, you simply do not get the COW
memory saving. The 44-function source transform still clears state between
requests.

**PHP 8.6+ (epoll/kqueue):** the server detects PHP 8.6's native `Io\Poll` API
and uses `epoll` on Linux or `kqueue` on the BSDs and macOS, with no PECL
extensions. On older PHP it uses `stream_select`, which works fine — it is just
O(n) per tick instead of O(1). Revolt is used if installed.

**One platform needs a note.** `riscv64` passes only with PCRE's JIT disabled:
under `qemu-riscv64` a pure-regex test dumps core with `pcre.jit=1` and passes
51 cases with it off. That is the emulator, not RISC-V and not this server, and
real hardware is untested either way.

## Examples

Six example apps are included in `examples/`:

| App | What it demonstrates |
|---|---|
| [todo](examples/todo) | SQLite CRUD, REST API, static HTML |
| [counter](examples/counter) | SQLite persistence, GET/POST |
| [chat](examples/chat) | WebSocket rooms, Socket.IO, 8 handler files |
| [stream](examples/stream) | Server-Sent Events, AI token streaming |
| [swarm](examples/swarm) | Q::event() dispatch, cluster replication |
| [collab](examples/collab) | Collaborative editing |

```bash
php qbixserver.php --root=examples/todo/web --port=8080
```

## Migrating from another server

Already running nginx, Apache, or Caddy? These guides show the config mapping:

- [Migrating from nginx](docs/migrate-nginx.md) — server blocks, try_files, proxy_pass, gzip
- [Migrating from Apache](docs/migrate-apache.md) — .htaccess works unchanged, VirtualHost → domains config
- [Migrating from Caddy](docs/migrate-caddy.md) — automatic HTTPS, on-demand TLS → autohost

## Single-Binary Distribution

Package your app into one executable file — PHP runtime, web server, and all your code. The binary includes SQLite auto-provisioning: if your app bundles a `.sqlite` file, the server copies it to the data directory on first run and writes the framework config to point at it. No external database needed.

Supported out of the box: Qbix (detects plugins, writes `local/app.json` with per-plugin prefixes), Laravel (`.env`), Symfony (`.env`), WordPress (`wp-config.php` + wp-sqlite-db), Craft CMS, and Drupal.

Sign binaries with ECDSA P-256 keys (M-of-N threshold), publish to Sigstore Rekor for independent verification, and customize by editing the binary as a zip file.

- [Building and distributing binaries](docs/binaries.md) — pack, sign, verify, customize, platform code signing


## Configuration

Everything below has a default that works, so a server with no configuration at
all still runs. Configuration is JSON, read into `Q_Config`, and every key can
also be set by a host application that builds the file itself.

```json
{
  "Q": {
    "web": {
      "https": { "mode": "manual",
                 "cert": "/etc/certs/fullchain.pem",
                 "key":  "/etc/certs/privkey.pem" },
      "http2":  { "enabled": true },
      "static": { "maxAge": 31536000 },
      "cache":  {
        "enabled": true,
        "dir": "files/cache/reverse",
        "defaultTtl": 0,
        "staleWhileRevalidate": 60,
        "revalidateLockSeconds": 30,
        "apcu": { "enabled": true, "maxSize": 65536 },
        "skip": { "cookies": ["PHPSESSID", "Q_sid"] }
      }
    },
    "webserver": {
      "backlog": 1024,
      "workers": 16,
      "precompress": { "enabled": true, "dir": "var/tmp/precompress" }
    }
  }
}
```

### The keys that matter most

| Key | Default | What it decides |
|---|---|---|
| `Q.webserver.backlog` | `1024` | How many established connections may wait to be accepted. See below — the old default of 32 was a cliff, not a queue. |
| `Q.webserver.workers` | auto | Process count. Sized from measured per-worker cost rather than a guess. |
| `Q.web.cache.enabled` | `false` | The reverse cache. Off unless asked for. |
| `Q.web.cache.staleWhileRevalidate` | `0` | Seconds past expiry an entry may still be served while one request renders the replacement. `0` keeps the pre-2026-09 behaviour exactly. |
| `Q.web.cache.skip.cookies` | `PHPSESSID`, `Q_sid` | Cookies that mean "this response is personal". **Set this to your framework's session cookie** — see the warning below. |
| `Q.web.static.maxAge` | `0` | Seconds a client may keep a static file without asking again. `0` means `must-revalidate`, which is a conditional request per file per page view. |

> **`skip.cookies` is the setting most likely to cost you.** It names the
> cookies that disable caching. If it does not name *your* session cookie, a
> signed-in visitor sails straight through the cache and is served someone
> else's page — and if it names a cookie your visitors happen to carry for
> unrelated reasons, every one of them bypasses the cache instead. On one
> installation the second failure made the same front page cost 1300 ms
> rendered instead of 74 ms cached, because a stale cookie from an unrelated
> app on the same host matched the default.

---

## Caching, and what a reload actually costs

The reverse cache sits in the parent process, so a hit never reaches a worker
and never forks. That is the difference between the two numbers this server
lives between:

```
cache hit      0.6 ms
full render    1382 ms
```

Almost everything below is about keeping requests on the left-hand side.

### Conditional requests

A returning browser sends the validators it holds. When they still stand, the
answer is `304` and no body at all:

```bash
$ curl -sk --http2 --compressed -D- -o/dev/null https://example.test/
HTTP/2 200
etag: "ae368d8780e9a15dd53c6656ce2-gzip"
last-modified: Tue, 22 Sep 2026 20:48:18 GMT
content-length: 9358

$ curl -sk --http2 -H 'If-None-Match: "ae368d8780e9a15dd53c6656ce2-gzip"' \
       -o/dev/null -w '%{http_code} %{size_download}B body\n' https://example.test/
304 0B body
```

202 bytes of headers instead of 9.4 KB, whatever the page weighs.

### The ETag is derived from the page, not from the clock

An entry that carried only `Last-Modified` recorded when the *entry was stored*.
Rebuild it and the timestamp moves even if the HTML is byte-identical, so the
next reload revalidates, fails, and transfers the whole document — a page
nobody edited costs full price once per lifetime, forever. The tag is a hash of
the body as the application produced it, before compression, so:

```
rebuild 1  etag="ae368d…-gzip"  last-modified=20:48:29
rebuild 2  etag="ae368d…-gzip"  last-modified=20:48:30
rebuild 3  etag="ae368d…-gzip"  last-modified=20:48:31
```

The identity and gzip forms get different tags, because RFC 9110 scopes a
validator to the selected representation and they are two representations.

### Expiry does not stop the site

An entry expires at a moment, not gradually, so every request in flight misses
at the same instant — and without protection each of them renders the page.
With `staleWhileRevalidate` set, exactly one renders and the rest are handed
the copy that already exists:

```
                    before              after
8 simultaneous      8 rendered          1 rendered
requests at the     1815–2131 ms each   1893 ms  (the one)
moment of expiry                        ~62 ms   (the other seven)
```

The claim is a directory, because `mkdir` is atomic and needs no cleanup
protocol to be correct. A claim older than `revalidateLockSeconds` may be taken
from whoever holds it, so a worker that dies mid-render cannot freeze a page at
its last version.

A stale response is labelled honestly — `X-Cache: STALE` and an `Age` header
with the age it actually has — because a cache downstream deserves to know.

### Forcing a refresh

`X-Cache-Refresh: 1` reads past the stored copy so the response is rendered and
stored again. It is deliberately not `Cache-Control: no-cache`: browsers send
that on a plain reload, and honouring it would let any client or crawler make
the server render on demand.

```bash
curl -H 'X-Cache-Refresh: 1' https://example.test/   # warm, don't just read
```

---

## Measuring it yourself

Do not take any number here on trust. The benchmark ships with the server and
needs nothing installed — PHP's curl does HTTP/2 and `curl_multi` does the
concurrency:

```bash
php tests/bench-load.php https://localhost:8443/
php tests/bench-load.php http://127.0.0.1:8088/ --levels=1,4,16,64 --requests=500
php tests/bench-load.php https://host/ --http1        # compare protocols
php tests/bench-load.php https://host/ --json         # for a pipeline
```

It sweeps the concurrency until throughput stops improving, reports where the
knee is, and writes a CSV. It also says what the machine was holding at the
time — cores, memory, load, worker count, resident size, open handles — because
a throughput number without those is not reproducible.

**What it measures and what it cannot:** closed loop, like `ab` and `wrk`. N
requests in flight, a new one as each finishes. That measures capacity honestly
and understates latency under saturation, because a slow response delays the
request that would have followed it — the requests never sent are the ones that
would have been slowest. This is coordinated omission, and it is why the
percentiles read as "how it served what it accepted" rather than "what a user
would have seen".

### A worked example: the backlog

PHP's `stream_socket_server` defaults its backlog to 32. A burst larger than
that does not queue and does not fail — the kernel drops the SYN and the client
retransmits a second later:

```
                  backlog 32                backlog 1024
concurrency  16    2961 req/s  p99    4.7 ms   2358 req/s  p99  9.6 ms
concurrency  32    2899 req/s  p99   13.5 ms   2872 req/s  p99 14.4 ms
concurrency  64     745 req/s  p99 1067.0 ms   2703 req/s  p99 19.5 ms
concurrency 128     603 req/s  p99 1317.0 ms   2773 req/s  p99 37.9 ms
```

Throughput did not degrade at 64, it collapsed to a quarter, and the p99 became
a round thousand milliseconds — the retransmit timer, while the server sat
mostly idle. A server that is fast until exactly 32 concurrent connections and
then appears to hang is very hard to diagnose from outside, because nothing in
it is slow.

### A worked example: TLS is the ceiling

Same page, same server, same moment:

```
                    with TLS          without TLS
concurrency   8     p99  108.1 ms     p99  4.9 ms
concurrency  16     p99  207.8 ms     p99  6.8 ms
peak                1481 req/s        2961 req/s
```

Broken down over six fresh connections:

```
dns 7.99 ms    tcp 0.32 ms    tls 20.96 ms    server 1.46 ms
```

The accept path is fine. The handshake is 21 ms on a link whose round trip is
0.3 ms, so it is CPU and scheduling rather than round trips — and session
resumption is not currently working: `openssl s_client -reconnect` reports
`New` every time and `Reused` never. **This is the largest known open item.**
If you are benchmarking this server against nginx, benchmark both over plain
HTTP as well, or you are mostly measuring OpenSSL.

---

## Tests

```bash
php tests/run-unit.php              # everything self-contained, ~3s
php tests/run-unit.php hpack        # only tests whose name matches
bash tests/run.sh                   # the above, then real sockets and TLS
```

The unit suite needs no server, no network, no certificate and no fixture
directory: each file drives a class directly and asserts on what it returns.
That is the half that can run in CI on a machine with nothing installed but
PHP, and the half that fails fast enough to run before a commit. It exits
non-zero on any failure, so it can gate a merge.

Current coverage includes frame codec, HPACK (including malformed input),
cache keys, cache entry format, cache wire form, conditional revalidation,
stale-while-revalidate, HTTP/1.1 response head construction, path safety,
source transformation, HTTP/2 limits, cookies, flow control, and full-socket
behaviour.

---

## Security posture

The server is written on the assumption that every byte from a peer is hostile
and that a bug here is a bug in everybody's site.

- **Path traversal** — one function decides whether a resolved path escapes the
  root, covering `..`, encoded forms, symlinks and the mixed separators
  Windows accepts. 34 cases pin it.
- **Header injection** — no header name or value containing CR or LF is ever
  written. Values arrive from application output and from stored cache
  entries, so this is not theoretical.
- **Response framing** — `Content-Length` is computed from the body actually
  being sent, and any supplied one is dropped. Two lengths on one message is
  how a parser is taught to find the next response inside this one.
- **HPACK** — bounds-checked integer and string decoding, throwing rather than
  reading past the buffer. 20 malformed-input cases.
- **HTTP/2 resource limits** — eight of them, covering concurrent streams,
  header list size, CONTINUATION frames (CVE-2024-27316) and stream
  creation/reset rate (rapid reset, CVE-2023-44487), answered with
  `ENHANCE_YOUR_CALM` rather than by falling over.
- **Connection-specific headers** are dropped from HTTP/2 responses, where
  they are a protocol error.

Reporting something: please do **not** open a public issue for a
vulnerability. See `SECURITY.md` if present, otherwise contact the maintainers
privately and give them time to release a fix before disclosure.

---

## Contributing

Contributions are welcome, and the bar is deliberately specific rather than
high.

### What a good change looks like

1. **It comes with a test that fails without it.** This is the one firm rule.
   A test that passes before and after the change is not testing the change.
   Check it by reintroducing the bug and watching the test fail — the suite
   here has been wrong that way before, and it cost a day.
2. **It explains why, not what.** The diff says what. A comment earns its place
   by saying what went wrong, what it measured, or what will break if someone
   undoes it. Numbers from a real run are worth more than adjectives.
3. **It changes one thing.** A fix and a refactor in one commit is two reviews
   pretending to be one.
4. **Its default is the old behaviour.** A new capability that alters how an
   existing installation behaves without being asked is a regression to
   somebody. `staleWhileRevalidate` defaults to `0` for exactly this reason.

### Practically

```bash
git clone https://github.com/Qbix/Server.git
cd Server
php tests/run-unit.php          # should be green before you start
# ... make your change, with its test ...
php -l src/Q/WebServer.php      # lint every file you touched
php tests/run-unit.php          # green again, with your test in it
```

- Branch from the current release tag for a fix, or from `main` for a feature.
- One logical change per pull request; say what you measured and how.
- Performance claims want the command that produced them, so a reviewer can
  run it. `tests/bench-load.php` prints everything needed to reproduce a run.
- Please do not reformat code you are not otherwise changing.

### Good first contributions

- Make TLS session resumption work (see *TLS is the ceiling* above) — the
  single highest-value open item.
- An ECDSA certificate path alongside RSA.
- Move the TLS handshake off the event loop so concurrent handshakes do not
  serialize.
- More `bench-load.php` output formats, or an open-loop generator to sit
  beside the closed-loop one.

---

## License

**MIT** — see [LICENSE](LICENSE) for the full text.
Copyright (c) 2024–2026 Qbix, Inc.

In plain terms: you may use, copy, modify, merge, publish, distribute,
sublicense and sell this software, including in closed-source and commercial
products, for free. The two conditions are that the copyright notice and the
licence text travel with any substantial portion of the software, and that the
software comes with no warranty and no liability — if it breaks your site, that
is your risk, not the authors'.

There is no contributor licence agreement. By opening a pull request you are
offering your contribution under the same MIT licence as the rest of the
project, which is the ordinary arrangement for a repository of this kind.

Distributing a single-file binary built with this server still distributes this
software, so the licence text goes in the binary too. `docs/binaries.md` covers
how that is packed.

We [proposed `switch_global_context()` for PHP core](https://discourse.thephp.foundation/t/php-dev-three-proposals-for-php-9/2113). While that works its way through the RFC process, the server does it in userland today.
