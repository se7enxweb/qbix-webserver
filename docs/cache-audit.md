## Response cache audit (APCu layer)

An audit of the reverse response cache, with the focus on keeping small entries
in APCu: what the code does today, what it costs per hit, where it falls short,
and what is worth changing. Line numbers refer to the tree this was written
against (`origin/main` at `07f7a28`).

Each finding is marked:

- **OK** — works as intended.
- **BUG** — does something other than what the documentation says.
- **PERF** — correct, but leaves speed on the table.
- **RISK** — could serve a wrong or unsafe answer in some condition.

- [1. The files](#1-the-files)
- [2. A cache hit, step by step](#2-a-cache-hit-step-by-step)
- [3. The APCu integration](#3-the-apcu-integration)
- [4. An in-process layer in front of APCu](#4-an-in-process-layer-in-front-of-apcu)
- [5. Static files and precompressed output](#5-static-files-and-precompressed-output)
- [6. What /Q/health reports](#6-what-qhealth-reports)
- [7. Findings at a glance](#7-findings-at-a-glance)
- [8. Benchmark](#8-benchmark)
- [9. Proposal](#9-proposal)

---

### 1. The files

| File | What it holds |
|---|---|
| `src/Q/WebServer/Cache.php` | The whole response cache: settings (`init()`, 199), lookup (`get()`, 384), store (`put()`, 519), purge (727), clear (772), sweep (822), the generation marker (`generation()` 155, `bumpGeneration()` 175, `isOldGeneration()` 187), stale-while-revalidate claims (`claimRevalidation()` 306, `releaseRevalidation()` 348), the validator index (`indexValidators()` 946, `notModifiedFromIndex()` 1004), conditional answers (`notModified()` 1055), store-time compression (`encodeBody()` 1164), the on-disk format (`encodeEntry()` 1278, `decodeEntry()` 1312), the key (`cacheKey()` 1372), the personal-request checks (`isPersonal()` 1360, `hasSkipCookie()` 1431) and the counters (`stats()` 1490). |
| `src/Q/WebServer/Cache/Components.php` | Component-level caching helpers; not on the page-hit path. |
| `src/Q/WebServer.php` | Calls into the cache: `init()` at startup (294); lookups on the HTTP/1.1 connection path (2911), in `route()` for external drivers (2316) and on HTTP/2 (1155); stores after a render (1178, 2654, 2689, 3642, 4024). Also the separate static-file cache `$fileCache` (6774–6779, used 4315–4330 and 4506–4523). |
| `src/Q/WebServer/Pool.php` | Stores a pooled worker's response from the parent (1917), so every `put()` runs in the server process. |
| `src/Q/WebServer/MiddleOut.php` | Optional dictionary compression of stored bodies (`middleOut`). |
| `src/Q/WebServer/Minify.php` | Optional HTML minification at store time (`minifyHtml`). |
| `src/Q/WebServer/Dashboard.php` | `getStats()`; the cache's share is `Q_WebServer_Cache::stats()` (276). |
| `src/Q/WebServer/Ctl.php` | `cache:clear` touches the generation marker (860). |

---

### 2. A cache hit, step by step

The common path: HTTP/1.1, a `GET` from a browser sending
`Accept-Encoding: gzip, deflate, br`, no cookies, page held in APCu. Everything
below runs in the server (parent) process; no worker is involved.

| Step | Where | I/O and work |
|---|---|---|
| 1. accept, read, parse the request | `WebServer.php` event loop | socket read |
| 2. method, server path, skip cookies, `Authorization` | `Cache::get()` 386–392 | string scans only |
| 3. refresh header | `isRefreshRequest()` 1419 | a `Q_Config::get()` lookup per request |
| 4. key | `cacheKey()` 1372 | `Q_WebServer_Domains::resolveRoot()`, `strpos` on Accept-Encoding, one `md5` |
| 5. conditional request only | `notModifiedFromIndex()` 1004 | `apcu_fetch('qcache:v:…')` — a small array, unserialised |
| 6. the entry | `get()` 430 | `apcu_fetch('qcache:…')` — the whole entry array is unserialised (`apc.serializer=php`) and the body copied out of shared memory |
| 7. generation | `isOldGeneration()` → `generation()` 155 | one `filemtime()` of the marker, at most once a second |
| 8. expiry, `X-Cache`, `Age` | `get()` 450–454 | `time()` |
| 9. conditional answer | `WebServer.php` 2913 `notModified()` | string compare |
| 10. HSTS, send | `sendResponse()` 5705 | builds the header block, concatenates header and body, one `writeAll()` |

No hashing and no compression happen on a hit: the ETag and the gzip body were
made once, in `put()`.

When the entry is **not** in APCu (step 6 misses), the disk copy is read:
`file_exists()` (a stat), `file_get_contents()`, `decodeEntry()` (a `strpos`,
`json_decode` of the metadata line, `substr` copy of the body, and a
`MiddleOut` decompress when the entry was packed). The entry is then promoted
into APCu if its stored body fits (`get()` 455–461).

---

### 3. The APCu integration

**Is APCu actually used in the CLI server? — BUG (C1).**
`init()` 229 sets `apcu.enabled` to `function_exists('apcu_fetch')`. That is true
whenever the extension is loaded, including in the CLI with `apc.enable_cli=0`,
which is PHP's default. There every `apcu_store()` returns `false` and every
`apcu_fetch()` misses, silently: the cache runs from disk while its settings say
APCu is on. Nothing checks `apcu_enabled()` or `apc.enable_cli`, and nothing is
logged. This alone explains a gain that shows up on one machine and not on the
next.

**Is APCu initialised before `pcntl_fork()`? — OK.**
APCu allocates its segment at module startup, in the server process, before any
fork, so the parent, the zygote and every worker share one segment. In practice
only the parent reads and writes page entries (every `get()` and `put()` runs
there, see `Pool.php` 1917); the sharing matters for `purge()` and
`bumpGeneration()` called from application code in a worker.

**Does `maxSize` apply to the raw or the stored body? — OK.**
Both checks (`get()` 456, `put()` 679) measure `$entry['body']`, which is the
stored form after `encodeBody()` compressed it. A 200 KB page that gzips to
44 KB fits the default 64 KB limit.

**Are the encoding variants stored separately? — OK, with PERF (C2).**
The key carries `|br`, `|gzip` or nothing (`cacheKey()` 1383–1388), so the three
are separate entries. But `encodeBody()` (1164) only ever produces gzip. A
browser that accepts brotli gets a `|br` entry holding gzip bytes, and a client
that accepts only gzip gets a `|gzip` entry holding the same bytes. Every page
is rendered, stored on disk and held in APCu twice for no difference in output.

**Are metadata stored apart from the body, so a 304 needs no body? — OK.**
`indexValidators()` (946) keeps the validators and freshness headers under
`qcache:v:<key>`, and `notModifiedFromIndex()` (1004) answers a conditional
request from that alone, before the entry is touched. It only works with APCu
on: with APCu off (or silently unusable, C1) every 304 reads the whole entry
from disk.

**Is `apcu_store()`'s return value checked? — RISK (C3).**
No, in any of the three places (460, 680, 981). A full segment, a failed
expunge or a CLI with APCu disabled all look like success. There is no counter
for failed stores or evictions, so a cache that has quietly stopped using memory
cannot be told from one that is working.

**Is the generation marker respected for APCu entries? — OK.**
`get()` checks `isOldGeneration()` on the entry wherever it came from (443–449)
and deletes the APCu copy, the index and the file. The index is checked the same
way (1018–1022). Entries stored in the same second as the bump count as old. The
marker is re-read at most once a second, so a hit can be served for up to one
second after a bump (documented). Since only the parent serves hits, that is one
process, not one per worker.

**Is data serialised more than once per hit? — PERF (C4).**
Once. With `apc.serializer=php` an array is stored serialised, so each
`apcu_fetch()` unserialises the whole entry, copying the body out of shared
memory; a conditional request adds a second, small unserialise for the index.
Storing the body as its own string key would avoid the serialiser on the body.
A PHP-array layer in the parent would avoid both.

**Other APCu observations.**

- **PERF (C5).** The APCu copy is stored with the fresh lifetime as its TTL
  (`put()` 680). When `staleWhileRevalidate` is set, APCu drops the entry at the
  moment it expires, so during the stale window, exactly when a page is busy,
  every stale hit is a disk read and a decode.
- **RISK (C6).** APCu TTLs are measured from the request time when
  `apc.use_request_time=1`. In a long-running CLI server the "request" is the
  whole process lifetime, so an entry stored after the server has run longer
  than its TTL expires immediately. It is off by default in APCu 5.1.28 (the
  version here), but a site that turned it on for FPM gets it in the CLI too.
  Neither setting is checked.
- **PERF.** `HEAD` is passed to `get()` (`WebServer.php` 2910) but `get()`
  answers only `GET` (387), so a `HEAD` for a cached page always renders.

---

### 4. An in-process layer in front of APCu

**PERF (C7).** There is none. Every hit goes to `apcu_fetch()` (or disk). A
bounded PHP array in the parent, holding the hottest entries ready to send
(headers already joined, body as a string), would skip the unserialise and the
copy. It is safe to add because the parent is the only process that serves hits,
so its array can never be stale for longer than the generation check already
allows.

---

### 5. Static files and precompressed output

**OK (S1).** Small static files are served from memory. `$fileCache`
(`WebServer.php` 6774) keeps the complete response, headers and body, per file,
encoding, keep-alive mode and HSTS header, for files up to 1 MB and 64 MB in
total. The file's mtime is re-checked at most once a second (4318–4328).

**BUG (S2).** Once the static cache is full it stops taking new files, and the
eviction loop below it never runs. The insert is guarded by
`fileCacheSize + size*2 < fileCacheMaxSize` (4507), so a file that would push it
over the limit is simply not stored, and the `while (… > max)` eviction
(4518–4522) can never find anything to do. On a site with more than 64 MB of
small assets, whichever files were asked for first stay in memory for good, and
everything else is read from disk on every request. When it does evict, it
drops the oldest insert, not the least recently used.

**RISK (S3).** The static cache and the page cache both choose an encoding with
a plain `strpos()` on Accept-Encoding (`WebServer.php` 4298, `Cache.php` 1383,
1189). A client that sends `br;q=0` or `gzip;q=0` to refuse an encoding is still
given it.

---

### 6. What /Q/health reports

**PERF (C8).** `/Q/health` (for an admin) includes `cache` from
`Q_WebServer_Cache::stats()` (1490): `hits`, `misses` and `hitRate`. It does not
say how many hits came from APCu and how many from disk, how many stores APCu
refused, or how full the segment is (`apcu_sma_info()`: memory, fragmentation;
`apcu_cache_info(true)`: entries, expunges). Nor does the server say at startup
that the cache is on but APCu cannot be used. Put together with C1 and C3,
there is currently no way to see from the outside whether APCu is doing anything.

---

### 7. Findings at a glance

| ID | Mark | Finding |
|---|---|---|
| C1 | BUG | `apcu.enabled` defaults on when APCu is loaded but unusable (`apc.enable_cli=0`); every APCu call silently fails. |
| C2 | PERF | `|br` and `|gzip` keys hold identical gzip bytes; every page is rendered and stored twice. |
| C3 | RISK | `apcu_store()` results unchecked; failed stores and evictions invisible. |
| C4 | PERF | Whole entry unserialised per hit (body included). |
| C5 | PERF | APCu copy dropped at expiry, so stale-while-revalidate hits read disk. |
| C6 | RISK | `apc.use_request_time=1` would expire entries early in a long-running server; unchecked. |
| C7 | PERF | No in-process layer in front of APCu. |
| C8 | PERF | `/Q/health` has no APCu/disk split, memory or eviction figures; no startup warning. |
| S1 | OK | Small static files served from memory with a 1 s mtime check. |
| S2 | BUG | Static cache stops accepting files once full; eviction never runs. |
| S3 | RISK | `q=0` in Accept-Encoding ignored by both caches. |
| — | OK | Variants keyed separately; `maxSize` measures the stored body; 304 answered from the index; generation marker respected in every layer; APCu shared across forks. |
| — | PERF | `HEAD` never served from the page cache. |

---

### 8. Benchmark

Measured before any change, on `origin/main` at `07f7a28`.

**Machine.** PHP 8.5.11, 11 cores, 46.8 GB RAM (31 GB available), load 6–7
throughout (a shared host), APCu 5.1.28, curl 7.76.1. Server started from the
source tree, 8 workers, `opcache.enable_cli=1` in every configuration so the
uncached case is not handicapped:

```sh
php [-d apc.enable_cli=1 -d apc.shm_size=128M] -d opcache.enable_cli=1 qbixserver.php \
    --config=<config> --root=<root> --host=127.0.0.1 --port=8090 --workers=8
php tests/bench-load.php http://127.0.0.1:8090/<page> --http1 --levels=1,4,16,64 --requests=2000
```

(Port 8090 because 8088 was taken on this host.)

**Pages.** Two PHP scripts sending `Cache-Control: public, max-age=3600` and a
fixed HTML file: *small* 10,369 bytes (2,932 gzipped) and *large* 204,882 bytes
(44,480 gzipped, so it fits `apcu.maxSize`). The client sends
`Accept-Encoding: gzip, deflate, br`, so hits use the `|br` key (C2).

**Configurations.** *off*: `enabled: false`. *disk*: cache on, `apcu.enabled: false`,
`apc.enable_cli=0`. *apcu*: cache on, APCu on.

#### bench-load.php (req/s, p50 / p99 ms)

| page | conc | off | disk | apcu |
|---|---|---|---|---|
| small | 1 | 335 (2.6 / 5.9) | 840 (0.8 / 4.6) | 1,525 (0.4 / 2.2) |
| small | 4 | 781 (3.4 / 8.7) | 1,834 (1.0 / 5.5) | 4,954 (0.5 / 1.5) |
| small | 16 | 1,025 (13.6 / 24.5) | 2,858 (3.9 / 19.5) | **6,200** (2.2 / 5.5) |
| small | 64 | 976 (63.0 / 88.3) | 3,236 (13.7 / 226.7) | 5,551 (9.4 / 28.4) |
| large | 1 | 76 (12.8 / 19.5) | 480 (1.8 / 5.1) | 584 (1.5 / 3.5) |
| large | 4 | 101 (29.8 / 48.8) | 604 (4.1 / 14.4) | 729 (3.7 / 8.5) |
| large | 16 | 105 (142.9 / 185.4) | 736 (14.4 / 49.6) | 863 (13.7 / 19.3) |
| large | 64 | 99 (631.7 / 795.6) | 776 (47.2 / 95.2) | 804 (62.0 / 80.3) |

No errors in any run.

**The large page is client-bound in this benchmark.** `bench-load.php` asks curl
to decode the response, so at ~800 req/s it is decompressing about 160 MB/s of
HTML in one PHP process. Measured again with `ab` (which does not decode), at
concurrency 16, 20,000 requests, with the server's own CPU read from `/proc`:

| page | disk | apcu | gain | server CPU per hit, disk → apcu |
|---|---|---|---|---|
| small | 3,547 req/s | 5,120 req/s | +44% | 0.26 → 0.20 ms |
| large | 3,189 req/s | 4,579 req/s | +44% | 0.31 → 0.22 ms |

**Reading.**

- APCu roughly doubles a small page over disk at low and moderate concurrency
  (1.8–2.7× in `bench-load`, +44% under `ab`), confirming the manual test. It
  also removes the disk tail: p99 at concurrency 64 is 28 ms against 227 ms.
- The gain only appears when APCu is actually usable. With the PHP default
  `apc.enable_cli=0` the *apcu* configuration **is** the *disk* configuration,
  without a word said (C1). That is the most likely reason the gain looks
  unreliable from one machine to the next.
- Uncached, the server compresses with brotli (`Content-Encoding: br`), while
  every cached copy is gzip (C2): a cached page is 9% larger than the uncached
  one on the small page, 8% smaller on the large one (low-quality brotli).
- What is left on a hit is about 0.2 ms of server CPU. The remaining steps in
  section 2 (key, config lookup, unserialise, header build) are the target of
  the in-process layer below.

---

### 9. Proposal

Ranked by expected gain against risk. Nothing here is implemented yet.

| # | Change | Findings | Expected gain | Risk | Default |
|---|---|---|---|---|---|
| 1 | **Decide APCu from `apcu_enabled()`, not `function_exists()`.** Log once at startup when the cache is on and APCu is loaded but unusable (`apc.enable_cli=0`), when `apc.use_request_time=1`, and when `apcu.maxSize` exceeds the segment. Count failed `apcu_store()` calls. | C1, C3, C6 | The whole APCu gain (~2× small pages) on every host where it silently did nothing today | Low: only stops calling functions that already fail | behaviour unchanged where APCu works |
| 2 | **`/Q/health` → `cache`**: `hits.memory`, `hits.apcu`, `hits.disk`, `misses`, `apcuStoreFailures`, and when APCu is usable its memory (`apcu_sma_info(true)`: size, available) and expunges/entries (`apcu_cache_info(true)`). | C8 | None directly; makes 1, 3 and 4 measurable in production | Low: additive keys | on |
| 3 | **File the entry under the encoding actually stored**: a brotli-accepting client uses the `gzip` entry while `encodeBody()` produces gzip only. (Storing real brotli is a separate, later option.) | C2 | Halves renders, disk and APCu memory for the same pages; more pages fit `shm_size` | Low: the old `|br` entries become unreachable and age out; no generation bump needed | on |
| 4 | **Keep the APCu copy through the stale window**: TTL = lifetime + `staleWhileRevalidate`. | C5 | Stale hits served from memory instead of disk, exactly on busy pages | Low: `get()` already decides staleness from `expires`, not from APCu's TTL | on |
| 5 | **Bounded in-process L1 in the parent** in front of APCu: an array of ready-to-send entries (status, joined header block, body), limited by entry count and total bytes (e.g. `apcu.l1.maxEntries: 0`, `l1.maxBytes`), LRU by moving a hit to the end. Every L1 hit still checks `expires` and `isOldGeneration()`; `purge()`/`clear()` drop it; skip-cookie, `Authorization` and refresh checks stay in front of it unchanged. | C4, C7 | Estimated 10–25% less CPU per hit (no unserialise, no body copy out of shared memory, no header rebuild); to be measured, not assumed | Medium: parent memory, and one more place that must honour the generation marker | **off** (`0` entries) |
| 6 | **Fix the static file cache's eviction**: insert, then evict least-recently-used until under the limit, instead of refusing inserts once full. | S2 | Sites with >64 MB of small assets keep serving the hot ones from memory | Low | on |
| 7 | **Honour `q=0`** in Accept-Encoding for both caches' key and `encodeBody()`. | S3 | Correctness only | Low | on |
| 8 | **Serve `HEAD` from the page cache** (headers of the `GET` entry, no body). | — | Small | Low | on |

Suggestions from the brief that the code already does:

- *(b) `maxSize` on the stored variant* — already so (C-section 3).
- *(c) separate metadata for 304s* — already so (`qcache:v:`); it becomes
  effective on more hosts once #1 lands.
- *(d) encodings stored separately, ready to serve* — separate already; "ready
  to serve" is part of #5.
- *(e) small static files from memory* — already so (S1); only its eviction
  (#6) needs fixing.

Order of work if approved: 1, 2 (so everything after is measurable), 3, 4, 6,
7, 8, then 5 last and off by default. Each as its own commit with its own tests,
the full suite and this benchmark run again after each.

---
[← Back to README](../README.md)
