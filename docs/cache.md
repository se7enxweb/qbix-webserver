## 🗃️ Response Cache

The server can keep the pages your PHP renders and answer the next request for
the same page itself, from memory or disk, without running PHP again. It decides
what to keep from the `Cache-Control` header your script sends, never caches a
response meant for one visitor, and can be told to forget everything at once by
touching a single file.

- [Turning it on](#turning-it-on)
- [What is kept](#what-is-kept)
- [How long](#how-long)
- [Where it is kept](#where-it-is-kept)
- [The generation marker](#the-generation-marker)
- [Settings reference](#settings-reference)
- [Response headers](#response-headers)

---

### Turning it on

The cache is off until it is enabled. Settings live under `Q.web.cache`:

```json
{ "Q": { "web": { "cache": {
  "enabled": true,
  "dir": "/var/cache/qbix/example.com"
} } } }
```

Then send a lifetime from the script:

```php
<?php
Q_Response::header('Cache-Control: public, max-age=300');
echo renderFeed();
```

The cache is consulted in the server process, before a worker is chosen, so a hit
costs no worker at all.

---

### What is kept

A response is stored only when all of these hold:

- the request is a `GET`, for a path that is not the server's own (`/Q/...` and `/.well-known/...` are never stored or served from the cache, whatever answered them);
- the status is `200`, or `404`/`410` when `negativeTtl` is set; an error is never stored;
- the request carries no `Authorization` header and none of the `skip.cookies`;
- the response sets no cookie;
- `Cache-Control` says neither `no-store` nor `private`.

A cached page is served only to a request that could have produced it: a `GET`
with no `Authorization` header and no skip cookie. A `HEAD` for a cached page is
answered from the same entry over HTTP/1.1, with the headers the `GET` gets and no
body; a `HEAD` is never stored. A skip cookie matches any cookie
whose name begins with it, so `PHPSESSID` also covers a name with a suffix added.

The key is the host, the path, the query string and the coding the stored body is
in: gzip for a client that accepts it, otherwise the body as rendered. A browser
that also offers `br` shares the gzip entry, since gzip is what the cache stores.
A coding refused with `q=0` (`gzip;q=0`) counts as not accepted, here and
everywhere else the server compresses.
Two query strings that differ only in order are two pages.

A client's own `Cache-Control: no-cache` does not bypass the cache, so a visitor
pressing reload cannot make the server render every page again. A cache warmer
that must render a page sends the refresh header (`x-cache-refresh` by default)
instead; the page is rendered and stored again.

---

### How long

The lifetime is `s-maxage`, else `max-age`, else `defaultTtl`. A lifetime of `0`
stores nothing, and `defaultTtl` is `0`, so by default only a response that states
a lifetime is kept.

When `staleWhileRevalidate` is set, a page that has just expired is still served,
marked `STALE`, for that many seconds; one request renders the new copy while the
others are answered from the old one, so a busy page never sends every visitor to
PHP at the same moment. Past that window the entry is removed. APCu keeps its copy
for the lifetime plus the window, so the stale answers come from memory too.

`404` and `410` answers are kept for `negativeTtl` seconds, whatever their
`Cache-Control` says, and only when it is set.

Every entry carries an `ETag`, the application's own when it sent one, so a
conditional request for a cached page is answered `304 Not Modified`.

---

### Where it is kept

Small entries are kept in APCu when it can be used, and every entry is written to
disk under `dir`, one file per page in a two-level directory:

```
<dir>/
  .generation            the generation marker
  ab/abcdef0123….json    one page: a line of metadata, then the body
```

The body is stored as it is sent: compressed with gzip when the client accepts it
and the type is worth compressing.

APCu is used only when it can hold entries in the server's process, which under
the CLI means `apc.enable_cli=1`; PHP's default is off. Loaded but disabled, it
would accept nothing and say nothing, so the server checks `apcu_enabled()` and
says at startup when the cache is on and APCu is not doing its part:

```
  cache: APCu is loaded but disabled in this process (apc.enable_cli is off), so cached pages are read from disk. Start PHP with -d apc.enable_cli=1, or set it in php.ini.
```

It also warns when `apc.use_request_time=1` (APCu would measure lifetimes from
the server's start, so entries expire early) and when `apcu.maxSize` is not
smaller than `apc.shm_size`. Setting `apcu.enabled` to `false` turns APCu off
without any warning.

In front of APCu the server can keep the hottest pages in its own memory, so a
hit costs neither an unserialise nor a copy out of shared memory. It is off until
`memory.maxEntries` is set:

```json
{ "Q": { "web": { "cache": { "memory": { "maxEntries": 1000, "maxBytes": 33554432 } } } } }
```

It holds only fresh copies of pages the stores below hold, least recently used
out first, within `maxEntries` pages and `maxBytes` of bodies, each body at most
`maxEntrySize`. Every hit from it still checks the page's expiry and the
generation marker, and the skip cookies, `Authorization` and the refresh header
still bypass it. Only the server process answers from it; a worker never serves
the copy it inherited. When application code in a worker calls `purge()` or
`clear()`, or a page is stored outside the server, a token in `.memory-epoch`
beside the entries changes, and the server empties its memory within a second --
the same delay as the generation marker.

What it saves depends on the size of the page. Measured on a 12-core host,
server CPU per cached hit: about the same for a 3 KB gzipped page (~0.19 ms
either way), about 12% less for a 44 KB one (0.23 → 0.20 ms) in most rounds --
a gain close to the noise of a busy shared host. Worth trying for large cached
pages under heavy traffic, measured on your own site; otherwise APCu alone is as
fast. A scheduled sweep removes expired files every
`sweep.every` seconds.

---

### The generation marker

The cache judges a page by what the application says about it. A template or a
stylesheet changed on disk is invisible to that, so a deploy needs a way to say
"all of it". Touching the generation marker says it:

```sh
touch /var/cache/qbix/example.com/.generation     # from a deploy script
qbixconsole cache:clear                           # the same, through the console
```

```php
Q_WebServer_Cache::bumpGeneration();              // the same, from PHP
```

Only the file's modification time counts; its content is never read. Every entry
stored at or before that time is treated as a miss: it is rendered again on its
next request and the old copy is removed then. Entries stored in the same second as
the touch count as older, which is the safe side.

Every process reads the marker by itself, at most once a second, so the workers and
any other server pointed at the same file see the new generation within a second.
No signal is sent and nothing reaches into APCu, which only the server's own
processes could clear. Nothing is deleted in bulk: a page that is never asked for
again stays on disk until the sweep removes it.

`cache:clear` touches `generationFile` when one is configured, otherwise
`.generation` in `--cache-dir` or `dir`, creating the directory when it is missing.

---

### From the control panel

The panel's **Cache** tab (`/Q/panel/(tab)/cache`) does all of this without
editing a file: switches for the cache, APCu and the memory layer, live hit
rate and memory figures, presets for the lifetimes, **Clear everything** (the
generation marker above), purging and warming a page, and a browser of the
stored pages. See the Cache tab in [dashboard.md](dashboard.md).

Settings saved there are kept in the panel store (`acl/panel.json`, key
`cache`, shaped like `Q.web.cache`) and **win over the configuration file**:
they are laid over `Q.web.cache` when the server starts, before the cache
reads it, and at once when saved. Only the ten settings the tab offers are
taken from there (`enabled`, `defaultTtl`, `staleWhileRevalidate`,
`negativeTtl`, `skip.cookies`, `apcu.enabled`, `apcu.maxSize`,
`memory.maxEntries`, `memory.maxBytes`, `minifyHtml`); a value that does not
pass the same checks as the tab's is ignored. To go back to the file's value,
save the setting as `null` through the API, or remove it from the `cache` key.

`purge()` now returns how many stored entries it removed, and takes an optional
second argument: `true` for a regular expression, `false` for an exact URL even
when it starts and ends with `/` (which the guess otherwise takes for a regex).

---

### Settings reference

Every setting, with its default. All are under `Q.web.cache`.

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Turn the response cache on. |
| `dir` | `<app>/files/cache/reverse` | Where entries and the marker are kept. |
| `defaultTtl` | `0` | Lifetime in seconds for a response that states none. `0` stores nothing. |
| `skip.cookies` | `["Q_sid", "PHPSESSID"]` | Cookie names, matched by prefix, that mark a request as personal. |
| `staleWhileRevalidate` | `0` | Seconds an expired page is still served while one request renders the new copy. |
| `revalidateLockSeconds` | `30` | After this long, a request rendering a stale page is presumed gone and another may take over. |
| `negativeTtl` | `0` | Seconds to keep `404` and `410` answers. `0` keeps none. |
| `refreshHeader` | `"x-cache-refresh"` | A request header that renders and stores the page again. |
| `generationFile` | `<dir>/.generation` | The generation marker. |
| `apcu.enabled` | when APCu is usable | Keep small entries in APCu as well as on disk. Usable means `apcu_enabled()`, so `apc.enable_cli=1` under the CLI. `true` when it is not usable warns and stays off. |
| `apcu.maxSize` | `65536` | Largest body, in bytes, kept in APCu. |
| `memory.maxEntries` | `0` | Pages kept in the server's own memory in front of APCu. `0` turns the layer off. |
| `memory.maxBytes` | `33554432` | Most bytes of bodies kept in memory. |
| `memory.maxEntrySize` | `65536` | Largest body, in bytes, kept in memory. |
| `minifyHtml` | `false` | Minify HTML before it is stored. |
| `middleOut` | `false` | Compress stored bodies against a shared dictionary. |
| `fileMode` | `0666` less the umask | Mode of entry files. |
| `dirMode` | `0755` | Mode of cache directories. |
| `sweep.every` | `300` | Seconds between sweeps of expired files. `0` turns the sweep off. |
| `sweep.budget` | `2000` | Most files one sweep looks at. |
| `sweep.maxAge` | `0` | Also remove files older than this many seconds. `0` means no limit. |

---

### Response headers

| Header | When |
|---|---|
| `X-Cache: HIT` | Served from the cache. |
| `X-Cache: STALE` | Served from an expired copy while a new one is rendered. |
| `Age` | Seconds since the entry was stored, on every cached answer. |
| `ETag` | On every stored page; the application's own when it sent one. |

`/Q/health` reports the cache under `cache`, read at most once a second:

| Key | Meaning |
|---|---|
| `hits`, `misses`, `hitRate` | Since the server started. |
| `stale` | Hits answered with an expired copy while it was rendered again. |
| `hitsFrom.index` | Conditional requests answered `304` from the validator index, without the page. |
| `hitsFrom.memory`, `hitsFrom.apcu`, `hitsFrom.disk` | Where the other hits were read from. Mostly `disk` with APCu enabled means it is not doing its part. |
| `memory` | The in-process layer: `enabled`, `entries`, `bytes`, `maxEntries`, `maxBytes`, `evictions`. |
| `apcu.enabled` | Whether APCu is in use, after the checks under [Where it is kept](#where-it-is-kept). |
| `apcu.warnings` | What those checks found, as said at startup. |
| `apcu.storeFailures` | Stores APCu refused: a full segment, or APCu unusable. |
| `apcu.memory` | `size`, `available` (bytes) and `usedPercent` of the shared segment. Only while APCu is in use. |
| `apcu.entries`, `apcu.expunges` | Entries held, and times APCu emptied a full segment. Only while APCu is in use. | See [headers.md](headers.md) for
what your PHP can send to steer caching.

The opcode cache, caches written during a fault, and other lessons: [lessons.md](lessons.md), [time-consuming-lessons.md](time-consuming-lessons.md).

---
[← Back to README](../README.md)
