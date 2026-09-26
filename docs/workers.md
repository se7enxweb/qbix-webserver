## 🏭 Workers and the Pool

The server runs PHP in a pool of persistent workers: processes forked from a parent
that has already loaded your application, each answering one request after another.
Between requests a worker is put back into the state it was forked in, so a request
never sees what the one before it left behind. This page covers how the pool is
sized, how requests reach a worker, and when a worker is replaced.
[architecture.md](architecture.md) explains the model; [reset.md](reset.md) lists
exactly what is reset.

- [How many workers](#how-many-workers)
- [Static and dynamic pools](#static-and-dynamic-pools)
- [Forking from a zygote](#forking-from-a-zygote)
- [How a request reaches a worker](#how-a-request-reaches-a-worker)
- [When a worker is replaced](#when-a-worker-is-replaced)
- [Reload and stop](#reload-and-stop)
- [Settings reference](#settings-reference)
- [Watching the pool](#watching-the-pool)

---

### How many workers

`--workers=N` sets the pool size. Without it, the server sizes the pool from the
machine and from what a worker costs:

- a worker is assumed to hold at least what the parent holds after loading the
  application (never less than 8 MB);
- the count is what fits in the memory left after 1 GB is set aside, no more than
  eight per core, and no more than 64;
- it is never fewer than 4.

The server then checks the count against the file descriptor and process limits
and lowers it when they are too tight; on a terminal it asks before starting.
Anything beyond the automatic ceiling is a deliberate choice, made with
`--workers`.

```sh
php qbixserver.php --root=web --workers=32
```

---

### Static and dynamic pools

By default the pool is static: every worker is forked at start and stays.

Set `spareWorkers` and the pool is dynamic. Only the spare workers are forked at
start; when every worker is busy, one more is forked, up to the pool size; and a
worker beyond the spare count that has been idle for `idleWorkerTimeout` seconds is
retired, longest idle first. A busy worker is never retired, and the pool never
falls below the spare count.

```json
{ "Q": { "webserver": { "spareWorkers": 8, "idleWorkerTimeout": 60 } } }
```

A dynamic pool costs less memory on a quiet machine; a static one never forks
while it serves.

---

### Forking from a zygote

```json
{ "Q": { "webserver": { "zygote": true } } }
```

**The problem it solves.** `fork()` hands a new worker every descriptor the
server holds at that instant, including every visitor's connection. A newly
forked worker closes the plain ones at once. It cannot close a TLS connection:
PHP closes TLS with `SSL_shutdown()`, which writes a close_notify alert onto the
socket the server is still using, and the visitor's connection would end (see
[lessons.md](lessons.md#tls-in-a-forked-child)). So the worker parks it and keeps
the descriptor until it exits. While any worker holds a copy, the kernel cannot
free the connection: when the visitor and the server have both finished with it,
it sits in `CLOSE-WAIT`, held by a worker that will never use it.

A static pool forks only at start, before any visitor has connected, so it never
sees this. A **dynamic pool** forks whenever its workers become busy -- exactly
when connections are open -- and every worker it forks keeps all of them. On an
Exponential installation serving HTTPS with 48 spare workers, a 20-second burst
of 355 rendered pages left up to **50** connections in `CLOSE-WAIT` held by
workers alone, released only when those workers were retired.

**How it works.** With `zygote` on, the pool forks one extra process -- the
zygote -- at the end of starting up: after the warm-up and the first workers,
before the server accepts its first connection. The zygote closes what a worker
closes (the listeners, the server's end of every worker's socket pair, every
other socket) and then only waits. Every worker the pool needs after that is
forked by the zygote instead of the server:

1. the server creates the new worker's socket pair, as it always has;
2. it hands the worker's end to the zygote over a Unix control socket
   (`SCM_RIGHTS`), with a deadline of five seconds for the whole hand-off;
3. the zygote forks, the new worker takes that socket and runs the ordinary
   worker loop, and the zygote answers with the worker's pid.

The zygote never held a client connection, so its workers inherit none. They are
otherwise the same workers: forked from the same warmed-up state, served the same
way, replaced for the same reasons. The zygote is their parent and reaps them the
moment they exit. Because they are not the server's children, the pool checks
that one is alive by sending it signal `0` and reading its state in `/proc` (a
zombie does not count), rather than with `waitpid()`, and leaves the reaping to
the zygote. On stop, the pool asks every worker to exit, waits for them, and then
stops the zygote.

**When the zygote fails.** If a hand-off fails -- the zygote was killed, a send
or the reply fails, or five seconds pass -- the zygote is stopped and the console
log says so:

```
[ERROR] zygote: the zygote did not fork a worker; forking workers from the server from now on
```

From then on workers are forked from the server exactly as they are without the
setting. No request fails on the way: workers the zygote had forked keep serving
until their socket pairs close, and the request that needed a new worker gets
one. A signal arriving at the server during a hand-off (a busy server takes
`SIGCHLD` constantly) is not a failure: an interrupted call is retried within the
same deadline.

**Requirements.** PHP's `sockets` extension with `SCM_RIGHTS`, plus `pcntl` and
`posix`, all present in the Linux builds. Without them the setting is ignored and
workers are forked from the server. It is on by default; set it `false` to fork every worker from the server as before. In Exponential it is
set from `velocity.ini`:

```ini
[ServerSettings]
Zygote=enabled
```

**Checking it.** The zygote is a child of the server whose own children are
workers:

```bash
P=<server pid>
for c in $(ps -o pid= --ppid $P); do n=$(ps -o pid= --ppid $c | wc -l); [ $n -gt 0 ] && echo "zygote $c: $n workers"; done
```

(It has no children until the pool forks its first worker after start.) To see
connections held by workers only -- what the zygote removes -- list the
connections on the HTTPS port and the processes holding each; any held by a pid
other than the server's is one:

```bash
ss -Htanp '( sport = :443 )' | grep -v LISTEN | grep -v "pid=$P,"
```

With the zygote on, the installation above showed none during the same burst,
while the zygote forked 48 workers and every request was answered.
`tests/unit-pool-zygote.php` asserts it over real TLS, together with a killed
worker being reaped at once, a killed zygote costing only itself, signals during
hand-offs, and a stop leaving no process behind.

---

### How a request reaches a worker

The parent accepts every connection and reads every request. Static files, cache
hits and the server's own pages are answered there, and only a request that runs
PHP goes to a worker: the first idle one, checked to be alive before it is used.

When every worker is busy, the request waits in the parent until one is free; it is
never refused for lack of a worker. The limit on load is `maxConnections`, checked
when a connection is accepted, beyond which the server answers `503`.

If a request cannot be handed to a worker, it goes back to the front of the queue,
up to three times, before the client is answered `502`.

---

### When a worker is replaced

A worker answers the request it has, and is then replaced by a fresh fork, when:

| Reason | Setting |
|---|---|
| It has served `maxRequests` requests. | `maxRequests` (`1000`; `0` for no limit) |
| Its heap has grown past the memory ceiling. | `workerMemoryCeiling` (see [reset.md](reset.md#a-worker-that-grows-is-replaced)) |
| Its output buffers were left unbalanced. | — |
| The application asked for it with `Q_WebServer_Pool::retireAfterResponse($reason)`. | — (see [reset.md](reset.md#code-that-can-run-only-once-per-process)) |

Each replacement is logged with its reason.

A worker that dies while serving is replaced as well. When it died before writing
anything and the request was a `GET`, `HEAD` or `OPTIONS`, the request is tried
once more on another worker; otherwise the client is answered `502`. An idle worker
that exits is noticed within two seconds, removed and replaced. Every exited worker
is reaped, so none is left behind as a zombie.

A worker still on one request after `requestTimeout` seconds (default `30`, `0` for
no limit) is killed: the client gets `504`, the kill is logged with the request,
and a fresh worker takes its place. The request is not run again elsewhere, where
it would hang the same way.

The control panel's Workers tab (API `POST /Q/api/workers/resize` with
`{"workers": N}`) changes the pool's size while it runs: a fixed pool forks up to
`N` at once, and above it retires idle workers now and busy ones as each finishes;
a dynamic pool takes `N` as its new maximum.

With `forkPerRequest`, each worker serves one request and exits, and the parent
forks its replacement: the isolation of a fresh process, at the cost of a fork per
request.

---

### Reload and stop

`qbixconsole server:reload` (`qbixctl graceful`) re-executes the server. The
listening sockets are closed first, open connections are given up to five seconds
to finish, and the workers are asked to stop and given three seconds before they are
ended. The new server forks a new pool from the new code and configuration.

The control panel's Workers tab can recycle one worker or all of them without a
reload: an idle worker is replaced at once, a busy one after its current request.

---

### Settings reference

Every setting, with its default. All are under `Q.webserver`.

| Setting | Default | Meaning |
|---|---|---|
| `spareWorkers` | `0` | Workers kept when idle; above `0` makes the pool dynamic. |
| `idleWorkerTimeout` | `60` | Seconds a worker beyond the spare count may stay idle before it is retired. |
| `maxRequests` | `1000` | Requests a worker serves before it is replaced. `0` means no limit. |
| `requestTimeout` | `30` | Seconds a request may run before the client gets `504` and the worker is replaced. `0` means no limit. |
| `workerMemoryCeiling` | `256`, or ¾ of `memory_limit` if lower | Heap size in MB past which a worker is replaced. `0` turns it off. |
| `forkPerRequest` | `false` | One request per worker, then a fresh fork. |
| `zygote` | `true` | Fork workers started after the pool from a zygote, so they inherit no visitor's connection. `false` forks them from the server as before. See [Forking from a zygote](#forking-from-a-zygote). |
| `warmup` | — | A script run once in the parent before the workers are forked. See [reset.md](reset.md#warming-the-pool-in-the-parent-and-the-one-trap-in-it). |
| `keepGlobals` | `[]` | Globals a worker keeps between requests. `--keep-globals` sets it too. |
| `maxConnections` | `1024` | Connections open at once; beyond it the server answers `503`. |

The pool size itself is given with `--workers`.

---

### Watching the pool

| Where | What it shows |
|---|---|
| `/Q/dashboard` | The Workers card (idle and busy, and the maximum of a dynamic pool) and the Worker Memory card, which reports proportional memory so shared pages are counted once. See [dashboard.md](dashboard.md). |
| `/Q/health` | The same figures as JSON for an admin: `workers`, `workersMax`, `workersSpare`, `workerStats`. |
| `/Q/panel`, Workers tab | Every worker with its pid, state and requests served, and the queue length. |
| The console log | One line for each worker replaced, retried or found dead, with the reason, and a `zygote:` line if the pool gives up on its zygote. |
| `ps` and `ss` | Which process forked each worker, and connections held by a worker rather than the server. See [Checking it](#forking-from-a-zygote). |

A worker also remembers what it has found out about files -- whether a path
exists, a file's mtime and size -- for the rest of a request, and with
`Q.compat.statTtl` for a little longer. What makes it forget, and why that
includes running another program: [compatibility.md](compatibility.md#remembered-file-facts).

What carries over between requests and what a forked worker inherits: [lessons.md](lessons.md); problems that took longest to diagnose: [time-consuming-lessons.md](time-consuming-lessons.md).

---
[← Back to README](../README.md)
