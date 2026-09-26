## 📊 Live Dashboard

Open `http://localhost/Q/dashboard` in your browser for a real-time server dashboard. Updates live via WebSocket — no polling, no page refreshes.

**What it shows:**

| Panel | Metrics |
|---|---|
| **Overview cards** | Total requests, current RPS (5-sec window), avg response time, slowest request, memory usage + peak, worker status, WebSocket connections, active rooms, data transferred, open connections |
| **Throughput sparkline** | Per-second request rate for the last 60 seconds — see traffic patterns at a glance |
| **Top paths** | Most-requested URLs with hit count and average response time — find your hot paths |
| **Active rooms** | WebSocket room workers with member count — monitor real-time features |
| **Live request log** | Scrolling feed of every request: timestamp, status code (color-coded), method, URI, response time in ms |

**Endpoints:**

| URL | Format | Use case |
|---|---|---|
| `/Q/dashboard` | HTML | Browser — the visual dashboard |
| `/Q/health` | JSON | Load balancers, uptime monitors (lightweight) |
| `/Q/stats` | JSON | Monitoring systems — full stats payload |
| `/Q/metrics` | Text | Prometheus scrapers — request, latency, worker and memory figures |
| `/Q/phpinfo` | HTML | PHP's own report: version, extensions, ini settings |

The dashboard, the control panel and the documentation each carry a toolbar near
the top — Dashboard, Control Panel, Documentation, PHP Info, Health and Metrics —
with the current view marked, so each view is a click from the others.

The dashboard, `/Q/stats`, `/Q/metrics` and `/Q/phpinfo` are for an admin: they
answer requests from this machine, and from elsewhere only with the dashboard token
or a control panel session (or when `Q.dashboard.remote` is set). `/Q/phpinfo`
shows the server process's environment, so from elsewhere it always needs the
credential. `/Q/health` answers anyone with
`{"status":"ok"}` and gives the figures to an admin only.

The `/Q/stats` JSON includes everything the dashboard shows, plus `sparkline` (60 data points), `topPaths`, `activeRooms`, `statusCodes` breakdown, and `cache` stats. Feed it to Grafana, Datadog, or your own monitoring.

**Reading the memory cards — they report what is true, not what is easy:**

- **Worker Memory (COW)** is **PSS** (proportional set size), summed over the
  parent and its workers, not each worker's RSS added up. Workers are forked, so
  RSS counts every page shared after the fork once per worker — at a few hundred
  workers that reads as *ten times* the real memory and does not fall on a
  restart. PSS divides each shared page by the number sharing it, so the sum is
  the actual resident memory. To keep it cheap the card **samples** a bounded set
  of workers rather than reading `/proc` for every one, which at scale would
  stall the event loop that serves the dashboard.
- **System RAM** is used = Total − MemAvailable (reclaimable cache counts as
  free, as `free` reports it), and it **also shows swap when any is in use** —
  and tints red then, however low the RAM percentage looks. A box can sit at a
  comfortable 42% while it has pushed gigabytes to disk under earlier pressure,
  which the percentage alone hides.
- **Durations** — the slowest-request figure and every row in the live log — are
  rounded to one decimal; a `microtime()` difference is otherwise thirteen.
- **The live log** carries column headings, and the **status filter** lists every
  code the server has recorded since start, not only those that streamed past
  after the page opened.

### Hiding the panel's own requests

An open dashboard or panel makes requests of its own -- its WebSocket, the
panel's API calls, `/Q/health` from a monitor, the server's icons -- and by
default they are counted like any other: they fill the live log and the top
paths and add to requests per second, so on a quiet site the page mostly shows
itself. To see only the site's traffic:

```json
{ "Q": { "dashboard": { "hidePanelRequests": true } } }
```

Every request whose path starts with `/Q/` is then left out of the dashboard
entirely -- counts, status codes, top paths, the live log -- and counted apart:
the live log's heading reads *"1,204 total · 318 panel /Q/ requests hidden"*, and
`/Q/health` reports `hiddenPanelRequests`. Only the dashboard is affected: the
access log, `/Q/metrics` and the traffic figures per domain still record every
request. A path that merely contains `/Q/` further in (`/blog/Q/notes`) is the
site's and is still shown. Off by default.

---

## ⚙️ Control Panel

Password-protected admin panel at `/Q/panel`. Until a password is chosen it signs in with the default key `panel` and asks for a new one straight away; or set one with `qbixctl panel:password` (below).

**Apps tab** — two lists:

- **Installations**: every PHP application the server can see, whatever it is
  built on, with the one it is serving first ("serving on this port"). Each
  card shows the name and release (for example *Laravel 11.9.2*), where it
  lives, its web root, and what it says about itself.
- **Your Apps**: the apps directory's Qbix apps, which can be created, served
  (hot-switches the document root), configured and opened from here. The apps
  directory path is editable.

**Frameworks tab** — the same detections, minus plain PHP sites, each with the
tools its own command line offers (clear caches, migrations, route lists, …)
and its installed packages. A command marked `…` changes the running
installation and asks before it runs; the server refuses it without that
confirmation too.

### What is detected, and how

One registry (`Q_WebServer_Framework`) answers the Apps tab, the Frameworks
tab and the autohost, so the three always agree. It looks in:

1. the document root being served, and its parent (a `public/` or `web/` root
   inside a project counts as that project);
2. the panel's apps directory and each directory directly inside it;
3. every directory in `Q.panel.appRoots`, and each directory directly inside.

It never walks deeper, and stops after 400 directories in one scan.

| Recognised | By | Release read from |
|---|---|---|
| Laravel | `artisan` | `vendor/laravel/framework/…/Application.php` (`VERSION`), else `composer.lock` |
| Symfony | `bin/console` + Symfony in `composer.json` | `vendor/symfony/http-kernel/Kernel.php` (`VERSION`) |
| WordPress | `wp-config.php` or `wp-includes/version.php` | `$wp_version` in `wp-includes/version.php` |
| Drupal | `core/lib/Drupal.php` (in the root or `web/`) | `Drupal::VERSION` |
| Joomla | `administrator/` + `configuration.php` | `libraries/src/Version.php` |
| Magento, TYPO3, Craft CMS, Moodle, MediaWiki, Nextcloud, PrestaShop, Laminas, FuelPHP | each one's own layout | each one's own version file or `composer.lock` |
| Qbix | `config/app.json` or `web/Q.php` | — |
| Composer app | `composer.json` + an `index.php` to serve | `composer.json` |
| PHP site | `index.php` | — |

A distribution of the server can add detectors for applications it supports
(`Q_WebServer_Framework::register()`), and they are tried before the generic
ones.

Version files are **parsed, never included**: including one would declare its
class or constants inside the server, where a second installation of the same
application would then collide. Only literal values are taken — class
constants, `define()`s, top-level variables, and functions that return a
literal or a constant.

Commands run as argument lists (no shell) in the application's own
directory, with a 120-second limit and 1 MB of output; only the commands the
detector lists can run. Composer is not run from the panel for an application
whose detector marks it as not composer-managed (its packages may be live
checkouts), unless `Q.panel.allowComposerWrite` is set.

### Domains tab

**In use** lists every host name this server answers to, and where each name
comes from:

- **certificate**: the names (CN and DNS SANs) on the certificate the HTTPS
  listener presents, with its days left;
- **record**, **record alias**: the domain records (below), and config's
  `Q.webserver.domains`;
- **site file**: an enabled site file named after a host;
- **Host headers seen**: requests since the server started, with a count and
  the time of the last one;
- providers: a distribution can add its application's own host map (this is
  how an installation's siteaccess host matching shows up).

Each host gets its states: **serving** (requests answered), **certificate
covers it** (wildcards count), **seen, not configured** and **configured, not
seen**. The listening addresses are shown above the table.

**Status**, per domain: `active`, `suspended` (visitors get a 503 "temporarily
suspended" page with `Retry-After`) or `disabled` (the server does not serve
the host: 404). Aliases share their domain's status. The server's own `/Q/`
and `/.well-known/` paths are never gated, so the panel and certificate
renewals keep working on a suspended host. Suspending or disabling asks for
confirmation (the API answers 409 without `confirm`); so does removing a
domain.

API (signed in): `GET domains/usage`, `GET domains`, `POST domains/status`
`{domain, status, note?, confirm}`, `POST domains/add`, `POST domains/remove`
`{domain, confirm}`, `POST domains/alias {domain, add|remove}`,
`POST domains/subdomain {domain, name, root?, remove?, create?, confirm?}`,
`POST domains/root {domain, root, confirm?}`, `POST domains/defaults
{domain, name?}` (the root a new domain or subdomain would get, and whether it
exists). Changing or clearing a root, or removing a subdomain, answers 409
without `confirm`; a bad name or root answers 400 with the reason.

**Aliases, subdomains and document roots.** Each domain card has an editor for
its document root, its aliases and its subdomains. Routing follows the
request's Host, port and case ignored:

| Host | Served from |
|---|---|
| the domain, or one of its aliases | the domain's `root` |
| a subdomain (`blog` → `blog.example.com`) | that subdomain's root |
| anything else | the server's default root, as before |

A subdomain's root may be relative (taken under the domain's root) or
absolute. It must resolve, symlinks followed, to an existing directory inside
the domain's folder: the domain's root, or the folder above it when the root
follows the standard layout below. A root that fails that, or no longer exists,
is not used: the request is served from the default root. The response cache
keys on the resolved root, so two domains never share a cached page. The
server's own `/Q/` and `/.well-known/` paths are never rerouted.

**The standard layout for new domains.** Adding a domain without a root gives
it `<base>/<domain>/doc`, and a subdomain without one gets
`<base>/<domain>/<name>/doc`. The form shows the path before you save.

| Setting | Default | Meaning |
|---|---|---|
| `Q.domains.baseDir` | the folder above the server's own domain folder, when its document root looks like `<base>/<domain>/...`; otherwise `/var/www/vhosts` | where domain folders live |
| `Q.domains.docDirName` | `doc` | the document root's directory name (a plain name; anything else is ignored) |

When the default folder does not exist the panel offers to create it (0755,
each new folder owned like its parent). Creating needs `create` and `confirm`;
nothing that already exists is ever touched, and a file in the way is refused.
Existing domains keep the roots they have; nothing is migrated.

**The domain record**, stored under `domains` in the panel's credential store
(`acl/panel.json`, written under its lock):

| Field | Meaning |
|---|---|
| `status` | `active` (default), `suspended`, `disabled` |
| `since` | when the status last changed (unix time) |
| `note` | free text |
| `root`, `app`, `tls`, `aliases` | as `Q.webserver.domains` |
| `subdomains` | `{ name or full host: root }` |
| `redirects` | `{ https, preferredHost: "www"\|"bare", rules: [{ match, from, to, code, keepQuery }] }` |
| `hsts` | `{ enabled, maxAge, includeSubDomains }` |
| `errorDocs` | `{ "404": "errors/404.html", ... }` for 403, 404, 500, 503 |
| `certificate` | `{ acme: true, names: [...] }`: this server issues the domain's certificate; `names` are the host names asked for with it |

Unknown fields are kept when a record is updated, so later versions can add
to it without migrating anything.

**Redirects, HSTS and error documents.** Each domain card also has an editor
for these. A request is handled in this order: the domain's status, then its
redirects, then routing to its document root, and the error documents last.
The server's own `/Q/` and `/.well-known/` (ACME challenges) are exempt from
all of them, so a certificate can always be issued and the panel always reached.

- **HTTP → HTTPS**: a permanent 301 to the same host, path and query on the
  HTTPS port (no port in the URL when it is 443). It can only be switched on
  when the server has an HTTPS listener and its certificate covers the domain;
  otherwise the panel refuses and says which of the two is missing.
- **Preferred host**: `www` or `bare`. A request for the other form of the
  domain gets a 301 to the preferred one. When the scheme changes too, both
  happen in one hop. Aliases are left alone unless they are that other form.
- **Custom rules**, first match wins, each a 301 or a 302:

  | `match` | `from` | Target |
  |---|---|---|
  | `exact` | a path | `to`, as written |
  | `prefix` | a path | `to` with the rest of the path appended (`/old/a` → `…/new/a`) |
  | `host` | the domain, an alias or a subdomain host | `to` with the whole path appended |

  `keepQuery` carries the query string over. A rule whose target would match
  the rule again (the same host and path, including through an alias) is
  refused, so a rule can never loop.
- **HSTS**: when on, `Strict-Transport-Security: max-age=N[; includeSubDomains]`
  is added to every HTTPS response for that domain, over HTTP/1.1 and HTTP/2,
  and never to a plain HTTP response. `maxAge` defaults to 31536000 (one year)
  and may be 0 to 63072000. An application's own header is not replaced.
- **Error documents**: a path under the domain's document root for 403, 404,
  500 or 503. The server's own error page for that status is replaced by the
  file, which is read and never executed, and it keeps the original status. A
  path that climbs out of the root (`..`, or a symlink pointing outside) or does
  not exist is refused when you save it, and ignored if it disappears later: the
  built-in page is served instead. An application's own error responses are its
  business and are not replaced.

API (signed in): `POST domains/redirects {domain, https?, preferredHost?, rules?, confirm?}`,
`POST domains/hsts {domain, enabled, maxAge?, includeSubDomains?, confirm?}`,
`POST domains/errordocs {domain, docs: {code: path}}`. A problem answers 400
with the reason. Switching on the HTTPS redirect or HSTS answers 409 until it is
sent again with `confirm`, since browsers remember both (HSTS for `maxAge`
seconds).

**Certificate.** Each domain card shows the certificate the HTTPS listener
presents: issuer, the names on it, valid from and to, days left, whether it is
self-signed, and a tick or cross for each of the domain's host names (itself,
its aliases and subdomains). It warns when the certificate expires within 21
days or has expired, when it is self-signed, when nothing is served, and when a
host name is not covered.

**Issue / renew for this domain** asks the ACME certificate authority for a
certificate that names the configured names plus this domain and its aliases,
the configured first name kept first so the certificate being served is the one
that is replaced. It runs as a background job, like every issuance (see
[HTTPS](https.md)); the card shows it queued, running, issued, or failed with
the reason and the next try, and the new certificate is swapped into the
listener without a restart. The domain is remembered in its record
(`certificate`), so later renewals keep naming it. Each name must reach this
server over HTTP for the challenge, which is why the request is confirmed
first. It is only offered in the `acme` (`letsencrypt`) mode: with certificate
files managed elsewhere (`manual`, `files`, `archive`, `pkcs12`), `certbot`,
`remote` or `self-signed`, the card says why it cannot issue and what to change.

API (signed in): `GET domains/cert?domain=…` (the report), `POST domains/cert/issue
{domain, confirm}` (202 with a job id; 409 until confirmed; 400 when the mode
cannot issue; `domains/provision` is the older name), `GET domains/cert/job?id=…`
(`state`: queued, running, succeeded or failed, with `error` and `nextAttempt`).
The inspection and job status live in `Q_WebServer_Certificate_Inspector`, for
the SSL view to reuse.

**Traffic** — each domain card shows its requests, bytes sent, status classes
(2xx, 3xx, 4xx, 5xx), its ten busiest paths and when it was last seen, added up
over the domain, its aliases and its subdomains. The numbers are counted in
memory as requests are logged, since the server started (the card shows when
that was); they are capped at 256 host names and 200 paths per host, so a flood
of invented names or paths cannot grow the server. **View its log lines** opens
the Logs tab filtered to the domain.

API (signed in): `GET domains/traffic?domain=…` returns `requests`, `bytes`,
`classes`, `top` (path, count), `last`, `since`, `window` and the `names` it
covers.

**Planned** (not built yet): password-protected
directories, hotlink protection, a PHP version and settings per domain, limits,
quotas and disk usage, backups, web statistics history, IP address assignment,
a read-only DNS view, cron per domain, and a file manager.

### SSL tab

`/Q/panel/(tab)/ssl` is the server's certificate administration, one page for
what the Domains tab shows per domain.

- **Served certificate**: the mode, the certificate the HTTPS listener hands
  out (issuer, names, validity, days left, SHA-256 fingerprint, key type and
  size, file), the fallback (`https.fallback`) and whether it is in use now
  because the configured certificate could not be loaded, and the watcher: how
  often it looks, when it last looked, when the certificate last changed.
- **Certificates by expiry**: every certificate the server knows (served,
  configured, the self-signed store, each one issued under the ACME
  directory), each listed once with all its roles, soonest-expiring first and
  coloured: red when expired or 7 days or fewer are left, amber at 21 or
  fewer, green otherwise.
- **Settings**: the mode, the ACME email, directory and host names, and
  *renew at* (the share of the lifetime left when renewal starts). Each value
  says whether it comes from the configuration or the panel. Saving asks first,
  listing each change, and says what it takes: a mode change applies when the
  server restarts, the others at the next certificate check. What a mode needs
  is checked before it is saved (`manual` and `files` need a usable
  `https.cert` and `https.key`; `acme` needs a host name). The values are kept
  in the panel store (`acl/panel.json`, key `ssl`), win over the configuration
  file, and are laid over it when the server starts.
- **Actions**: **Renew / issue now** starts the ACME job in the background
  and follows it; it is offered only in a mode this server issues in (`acme`,
  `letsencrypt`). With certificate files managed elsewhere (`manual`, `files`,
  `archive`, `pkcs12`), `certbot`, `remote` or `self-signed`, the tab says so
  and offers no issuing. **Reload certificate** reads the files again and puts
  the current pair in front of new connections at once, without a restart.
- **Events and history**: certificate events (loaded, stored, created,
  failed, error, renewals, jobs, settings changes, reloads) with their time,
  hosts and reason, newest first, plus the state of each issuing job. They are
  kept in the state directory (`/var/lib/qbix/ssl/history.json`, or the
  overlay's own), 0600, the last 200 only.

Private keys are never shown or returned: certificates are read for their
public part, key files appear by path only, and the history drops any key
material an event might carry.

API (signed in): `GET ssl/overview`, `GET ssl/certs`, `GET ssl/history[?limit=]`,
`POST ssl/settings {mode?, email?, directory?, renewAt?, domains?, confirm}`,
`POST ssl/renew {confirm}`, `POST ssl/reload`. `ssl/settings` and `ssl/renew`
answer 409 with what would happen until the body carries `confirm: true`; a
refused value is 400 with the reasons.

### Cache tab

`/Q/panel/(tab)/cache` runs the response cache ([cache.md](cache.md)) from
the browser, one or two clicks for each thing, with no file to edit.

- **Status and switches**: one line says what is on ("Cache on · APCu on ·
  Memory layer off"), with a switch for each of the three; a switch saves and
  takes effect at once. Under each, where its value comes from: set here, the
  configuration file, or the default. Anything wrong with APCu (switched off
  for the CLI, not installed, `apc.use_request_time`, an entry limit as big as
  its memory) shows as a banner with the fix in plain words.
- **Live figures**, refreshed every 2 seconds while the tab is open and the
  page visible (polling stops otherwise): the hit rate over the last interval
  with a two-minute sparkline and the rate since start; where answers came
  from (memory, APCu, disk, or a 304 from the validators) as a stacked bar;
  APCu's memory used, as a gauge; APCu and memory entries, evictions and
  expunges, stores APCu refused, and stale answers.
- **Actions**: **Clear everything** asks first, explaining that it is safe
  (it starts a new generation: nothing is deleted on the spot, every stored
  page is rendered afresh on its next request, within a second). **Purge a
  page** takes a path (`/about`, exact, query included) or, ticked, a regular
  expression (`#^/blog/#`), and says how many copies went. **Warm a page**
  renders a path again and stores it (compressed and plain); it runs in the
  background and the result appears in the tab.
- **Settings with presets**: page lifetime (Off, 1 min, 5 min, 1 hour,
  custom), serve while refreshing (Off, 30 s, 1 min, 5 min, custom), remember
  "not found" (the same), largest page in APCu, memory layer size (Off, small,
  medium, large, custom), minify HTML, and the skip cookies as chips to add
  and remove. Each has a one-line explanation. **Save** shows what will
  change before it is pressed, and what did change after; a refused value is
  shown under its setting.
- **Stored pages**: the entries on disk (the complete store), newest first,
  with address, coding, size, age, time left and where each is held (memory,
  APCu, disk); filter by address, sort by size or age, and purge or warm a
  row. At most 500 rows, and at most 5,000 files are looked at; the tab says
  when it stopped early. Bodies are never sent.

Where the settings live: in the panel store, `acl/panel.json`, under the key
`cache`, nested like `Q.web.cache`. They win over the configuration file,
are applied at once, and are laid over the configuration again every time the
server starts, before the cache is initialised. Nothing else in `Q.web.cache`
is taken from the store. Changes apply in the server process, which is the one
that answers from the cache.

API (signed in, the same session and default-password rules as every panel
route; changes need POST and a JSON object body):

- `GET cache` -- `{stats, settings, sources, warnings[{level, title, fix}],
  dir, generation, refreshHeader, limits, warm[], time}`
- `GET cache/entries?q=&limit=` -- `{entries[{url, status, coding, size,
  stored, age, ttl, expired, cleared, heldIn[]}], matched, scanned,
  truncated, limit}`
- `POST cache/settings {enabled?, defaultTtl?, staleWhileRevalidate?,
  negativeTtl?, "skip.cookies"?, "apcu.enabled"?, "apcu.maxSize"?,
  "memory.maxEntries"?, "memory.maxBytes"?, minifyHtml?}` -- `{saved,
  changes{name: {from, to}}, settings, sources, warnings, note}`; a value of
  `null` goes back to the configuration file; an unknown name, a wrong type
  or a value out of range is 400 with `errors{name: reason}` and nothing is
  saved.
- `POST cache/purge {url}` or `{pattern}` -- `{purged, url|pattern, removed}`
- `POST cache/clear` -- `{cleared, generation, note}`
- `POST cache/warm {url[, host]}` -- 202 `{warming, url, host}`; only a path
  on this server (starting with a single `/`), never a full address or
  another host's URL; the host defaults to the one the panel was reached on.

### Bookmarkable tabs

Every tab has its own address: `/Q/panel/(tab)/logs`, `/Q/panel/(tab)/system`
and so on, `/(name)/value` pairs after `/Q/panel`. Opening one lands on that
tab; changing tab adds a history entry, so Back and Forward move between tabs.
The Logs tab keeps its filters in the address too, for example
`/Q/panel/(tab)/logs/(type)/access/(status)/5xx/(host)/example.com`, and its
**Copy link** button copies exactly that.

### Logs tab

The server's access and error logs, newest last, as a table for the access
log (time, method, path, status coloured by class, size, duration; the user
agent on hover) and as lines for the error log.

- **Filters**: free text, HTTP method, and status: an exact code (`404`) or
  a class (`5` or `5xx`). A bad method or status is refused with the reason,
  not ignored.
- **Host**: the lines for one host name. A host with a log of its own shows
  that log; otherwise its lines are picked out of the server's log by the
  Host field the default format writes at the end of each line. A domain
  matches its aliases and subdomains too. Lines written before the Host was
  logged (the older `qbix` format) have no host and never match.
- **How far back**: without a filter the last 50–500 lines are read; with one,
  the last 2 MB of the file are searched (`Q.panel.logScanBytes`) and the
  newest matches shown, with how many matched and how much was searched. The
  whole file is never read into memory.
- **Tail** refreshes every two seconds while the tab is open; **Download**
  saves the lines shown.

The log API (`/Q/api/logs?type=access|error&lines=&filter=&method=&status=&host=`)
needs a signed-in panel session like every other panel API.

### The other tabs

**Scripts tab** — list and run PHP scripts from `scripts/Q/` (configure, install, translate, etc.)

**Plugins tab** — reads the app's `config/app.json` for declared plugins, `local/plugins.json` for installed versions, and scans the Platform's `plugins/` directory. Shows version, dependencies, and DB connections for each.

**Playground tab** — PHP REPL with all Q classes preloaded. Write code, hit Run (or Ctrl+Enter), see output. Sandboxed in a forked process with disabled filesystem writes, no network, 32MB memory limit, 5 second timeout.

**System tab** — PHP version, OS, extensions, memory limit. One-click Platform install: clones `github.com/Qbix/Platform`, runs `git submodule update --recursive`, sets up `local/paths.json`.

### Who can reach the panel

The same rule on HTTP/1.1 and HTTP/2, for `/Q/panel` and everything under `/Q/api/`:

| From | Allowed when |
|---|---|
| This machine | always |
| Anywhere else | a password is set, or the default key is in force (the page shows its login form; every API call but `auth/login` needs the session it gives), **or** the request carries the dashboard token or a panel session, **or** `Q.panel.remote` or `Q.dashboard.remote` is `true` |

Anything else gets the server's own 403 page, which says how to set a password.

The short version, for a first sign-in: [panel.md](panel.md).

Until a password is chosen, the **default key `panel`** signs in -- from anywhere,
so a server can be set up from outside -- and the session it gives can only change
it: the page shows nothing but a change form until a password that passes the rules
in [passwords.md](passwords.md) is set. While the default is unchanged, anyone who
knows it can sign in first; set `Q.panel.defaultLocalOnly` to accept it only from
this machine (or with the dashboard token), or set `Q.panel.defaultPassword` to
`null` to switch it off, in which case the first password is set from this machine,
with the dashboard token, or on the command line.

With the default switched off, the **first** password can be set in the page only from this machine, with the
dashboard token, or where `Q.panel.remote` is `true` -- never by whoever happens to
reach the page first. A remote visitor who reaches a panel with no password yet is
told how to set it instead of being offered the form.

### Setting the password from the command line

```sh
qbixctl panel:password --root=/path/to/web          # asks twice, without echo
echo 'a new password' | qbixctl panel:password --root=/path/to/web
qbixctl panel:password --root=/path/to/web --password='a new password'
qbixctl panel:password --root=/path/to/web --generate     # makes a strong one, prints it once
```

Pass the same `--root` the server runs with (or `--app=DIR` for a server run with
`--app`), and the same `--conf-dir`/`--config` if it uses a configuration tree:
the password goes where the server keeps it -- `acl/panel.json` under the tree, or
`local/panel.json` above the document root when there is none (see below) --
stored as the page stores it (bcrypt). It must pass
the rules in [passwords.md](passwords.md); `--generate` makes one that does, sets it
and prints it once. Changing it signs
out every existing session. A running server uses it on the next request; nothing
needs restarting. `qbixconsole panel:password` is the same command.

### Where the panel keeps its credentials

Two directories, found in this order:

| | Credentials (`acl/`) | Sessions (`sessions/`) |
|---|---|---|
| Set explicitly | `Q.panel.aclDir` | `Q.panel.sessionsDir` |
| With a configuration tree | `<tree>/acl` (`/etc/qbix/acl`) | `<state dir>/sessions` (`/var/lib/qbix/sessions`) |
| Without one | `local/` above the document root | `local/sessions/` |

`acl/panel.json` holds the password hash, the default-key state and the panel's own
settings. Each signed-in session is its own file in `sessions/`, named by the
SHA-256 of its token, so a directory listing gives no token away and sign-ins in
different workers never write the same file.

**The trust rule.** Before anything is read or written, every directory from these
files up to `/` must belong to root or to the user the server runs as, and must not
be writable by group or others (a sticky directory such as `/tmp` excepted); the two
directories and the files in them must belong to the server's user with no access for
anyone else (`0700` and `0600`); and nothing on the way may be a symbolic link. A
laxer mode on a directory or file the server owns is tightened, never trusted as it
is. If the rule fails -- a directory some other user could rename and replace with
their own, holding a password they know -- the panel is locked: sign-in, the default
key and every session are refused, the start-up log says why, and there is no
fallback to a looser place.

**Checking it.** `qbixctl panel:check --root=... [--conf-dir=... --config=...]`
prints each path with its owner and mode, whether it passes and why not, and the
commands that fix it; it exits 1 when the panel is locked. `--json` gives the same
as JSON.

**The usual lock after an upgrade: a group-writable directory above the store.**
Beside the application (no configuration tree), the store is `local/` in the
application directory, so that directory and every one above it fall under the
rule. A umask of `002` or a shared web group leaves them `0775`, and the panel is
locked. The refusal, the start-up log and `panel:check` name that directory and the
command, first:

```
chmod g-w,o-w /srv/www/app
```

Or keep the panel's files in a directory of their own, away from the application:
set `Q.panel.aclDir` and `Q.panel.sessionsDir`, or start the server with
`--conf-dir` (then they live in `<conf>/acl` and `<state>/sessions`). The rule
itself is not relaxed: a directory others can write is one they can empty and fill
with a password file of their own.

**The panel's settings.** The folder of applications (`appsDir`), the autohost
settings and the domain records live in `acl/panel.json` too, and every change to
them -- from the panel, the API or an autohosted domain -- is made under the store's
lock and renamed into place, so two workers saving different settings at once keep
both. The application files the panel edits (an app's `local/app.json`,
`local/paths.json`, `config/deploy.json`) are changed the same way, under a lock
beside each file.

**The shell's files.** The shell keeps its history, aliases and scripts in
`<state dir>/shell` (`/var/lib/qbix/shell`), beside `sessions/`. Files left in the
old place (`local/shell` above the document root) are brought over once, when the
shell first needs them: the two histories are merged (the older file's lines first),
aliases are merged (the newer file wins a clash), missing scripts are copied, and a
script that is newer in the old place is kept beside the current one as
`<name>.legacy`. The old directory is left as it was, with a `.moved` note naming
where its files went. It is only read if it passes the same trust rule.


**Moving from `local/panel.json`.** A `panel.json` left in the old place (above the
document root) is moved into `acl/` and `sessions/` once, on the server's first
start (or the first `panel:check`/`panel:password`), provided it belongs to the
server's user: a copy is kept in `acl/` as `panel.json.pre-migration-<time>`
(`0600`), and the old file is renamed to `panel.json.migrated-<time>`, not deleted.
A file that belongs to anyone else is never moved or believed, and locks the panel
until it is looked at.

---

---
[← Back to README](../README.md)

