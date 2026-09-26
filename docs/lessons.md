## 🧭 Lessons: PHP in Persistent and Forked Workers

Under PHP-FPM or mod_php every request starts from nothing and everything it
built is thrown away at the end. Here a worker answers one request after another,
and every worker is forked from a parent that may already have loaded your
application. Most code never notices. The code that does notice fails in ways
that look like something else, so this page lists what carries over, what is
inherited, and how to measure and diagnose it. The ones that took longest to find
are written up in [time-consuming-lessons.md](time-consuming-lessons.md).
[reset.md](reset.md) lists exactly what the server resets between requests.

- [What persists from one request to the next](#what-persists-from-one-request-to-the-next)
- [What a worker inherits from the parent](#what-a-worker-inherits-from-the-parent)
- [Measuring memory](#measuring-memory)
- [The opcode cache](#the-opcode-cache)
- [Warnings in the page](#warnings-in-the-page)
- [TLS in a forked child](#tls-in-a-forked-child)
- [Signals, blocking calls and a helper process](#signals-blocking-calls-and-a-helper-process)
- [A checklist for a new application](#a-checklist-for-a-new-application)

---

### What persists from one request to the next

The server resets the superglobals, headers, cookies, the session and output
buffering between requests. It cannot reset what PHP itself keeps for the life of
a process:

| Kept for the life of the worker | What goes wrong | What to do |
|---|---|---|
| Class statics and function `static` variables | A value computed for one request (the current user, a site, a design) is used by the next. | Reset them at the start of a request, or keep per-request state in `$GLOBALS` or an object built per request. |
| Constants (`define()`) and functions or classes declared inside a request | The second request that declares them dies with "already defined". | Declare once, guarded by `defined()` / `function_exists()` / `class_exists()` -- or retire the worker, below. |
| Error and exception handlers | PHP keeps each handler that `set_error_handler()` replaces on a hidden stack. A handler that holds a request's log keeps that log alive. | The server unwinds to the boot handler after each request. Do not rely on a handler surviving between requests. |
| Output buffers | A buffer a script leaves open used to stay on the stack with its page in it. | The server keeps one capture buffer per process and folds anything left open into that response. |
| Open resources (files, sockets, DB connections held in statics) | They stay open, which is usually what you want -- until the other end closes them. | Reconnect on "gone away" errors; do not assume a connection is fresh. |

**Code that can run only once per process** -- it defines constants from the
request, or declares functions that depend on it -- can still be served
correctly: ask for the worker to be replaced after it answers.

```php
if (class_exists('Q_WebServer_Pool', false)) {
    Q_WebServer_Pool::retireAfterResponse('declares request constants');
}
```

The request is answered normally; the parent then forks a fresh worker and logs
the reason. Outside a pool worker the call does nothing. See
[workers.md](workers.md#when-a-worker-is-replaced).

---

### What a worker inherits from the parent

With a warm-up (`Q.webserver.warmup`) or a preload hook, the parent loads the
application once and every worker is forked from it. Everything the parent held
at the moment of the fork is in every worker:

- **Sockets and database connections.** A connection the warm-up opened is
  *one* socket shared by every worker. Two workers talking over it at once get
  "server has gone away" or "commands out of sync", and a worker that exits can
  send the database a QUIT on everyone's behalf. **Close connections at the end
  of the warm-up**, not just drop the reference.
- **Class statics and globals set while warming.** If the warm-up renders a
  page, whatever that render cached in statics (the design in use, override
  maps, the site context) is what every worker starts with. Clear globals *and*
  reset statics at the end of the warm-up. A value that reaches a file on disk
  -- a compiled template, a cache entry -- outlives the restart too, so after
  fixing the leak, remove what it wrote.
- **File stat memos.** The server's file wrapper remembers stats -- and, in a
  pool worker, whether paths exist -- for the rest of a request, and with
  `Q.compat.statTtl` for up to that many seconds more. It forgets the warm-up's
  once the warm-up ends, and again in each worker straight after the fork, so a
  file rewritten after start is seen as it is now. Inside a request it forgets
  on a write through the wrapper, on `clearstatcache()` and after the
  application runs another program; see
  [compatibility.md](compatibility.md#remembered-file-facts).

Plain client sockets inherited by a worker are closed in the child; TLS streams
are handled differently -- see [TLS in a forked child](#tls-in-a-forked-child).
With `Q.webserver.zygote` a worker forked after start inherits no client
connection at all ([workers.md](workers.md#forking-from-a-zygote)).

---

### Measuring memory

Forked workers share every page the parent touched before forking,
copy-on-write. **RSS counts a shared page in full against every process that
maps it**, so summing RSS over a pool counts the shared baseline once per worker
and reports several times the real memory. Use **PSS** (proportional set size),
which divides each shared page among the processes sharing it:

```bash
# Real memory held by the server and its workers, in MB (Linux)
for p in $(pgrep -f qbixserver); do
  awk '/^Pss:/ {print $2}' /proc/$p/smaps_rollup
done | awk '{s+=$1} END {printf "%.0f MB\n", s/1024}'
```

The dashboard's Worker Memory card reports PSS. Two figures worth knowing:

- A worker that has not served a request yet costs very little: its pages are
  still the parent's.
- A worker that has served requests has faulted in its own copies of whatever
  it touched after the fork. The more the parent loads *before* forking, the
  more stays shared. That is what the warm-up is for.

A worker whose memory keeps growing request after request is a leak, not a
working set. `Q.webserver.workerMemoryCeiling` (MB) replaces a worker that goes
over it and logs the size, so a leak is named the first time it happens.

---

### The opcode cache

The opcode cache keys a compiled script on its path and whole-second mtime, and
by default it does not look at the file on every include:

| Setting | Effect here |
|---|---|
| `opcache.validate_timestamps=0` | The cache never checks the file. The server drops the compile of any file it has served whose mtime moved, at request boundaries. |
| `opcache.revalidate_freq=N` (default 2) | The cache checks at most every N seconds. The server re-checks the files it has served as each request starts, so an idle worker does not serve an old compile once more. |
| `opcache.revalidate_freq=0` | The cache checks on every include; the server's own sweep costs nothing. |
| Same-second rewrites | Two versions written within one second have the same mtime. The server treats a file that new as unsettled and does not trust any cached copy of it. |
| `opcache.file_update_protection` (default 2) | The cache refuses to store a file modified in the last N seconds. Useful here; leave it on. |

Deploy by writing to a temporary name and renaming over the target. A file
rewritten in place is, for a moment, half written, and anything that includes it
then gets a parse error.

PHP 8.2 and 8.3 with `opcache.jit=1235` had a JIT fault that duplicated the
source transform's output; the transform avoids it since v0.0.4.27. See
[time-consuming-lessons.md](time-consuming-lessons.md#the-jit-that-wrote-the-script-twice).

---

### Warnings in the page

With `display_errors` on, every warning and deprecation a script triggers is
printed into the response body. Under a CLI server there are more of them than
under FPM: `header()` after output, dynamic properties on stream wrapper objects,
extensions that assume a web SAPI. A body that is "almost right" with a line of
text at the top is almost always this.

Stock `php` Docker images ship with **no php.ini**, where `display_errors`
defaults to on. Set it explicitly:

```ini
display_errors = Off
log_errors = On
```

---

### TLS in a forked child

A forked worker inherits the parent's client connections and must let go of
them. For a TLS stream, `fclose()` is not letting go: PHP closes TLS with
`SSL_shutdown()`, which writes a close_notify alert onto the socket the parent
is still using, and that visitor's connection ends. The server parks inherited
TLS streams instead of closing them, and a worker that parked any ends with
`SIGKILL` after its shutdown functions, so the kernel closes the descriptors
without writing anything. **Never `fclose()` an inherited TLS stream in a child.**

The same applies in reverse: a parent that `fclose()`s a connection it shares
with a worker (an HTTP/2 connection carries many requests) ends it for every
stream on it.

To see who closed a TLS connection, trace writes and look for a 24-byte TLS
record starting `\27\3\3\0\23` -- that is a close_notify:

```bash
strace -f -e trace=write -p <server pid> 2>&1 | grep -F '\27\3\3\0\23'
```

**A parked stream still holds the connection.** Parking means the worker keeps
its copy of the descriptor. The visitor is not cut off -- the server's own
`fclose()` sends close_notify, and a client takes that as the end of the stream
whether or not a worker still has the socket -- but the kernel cannot free the
connection while any process holds it. It stays in `CLOSE-WAIT` until every
worker holding it has exited. A dynamic pool forks while connections are open,
so on a busy HTTPS site this adds up: 50 such connections after a 20-second burst
on one installation. The only complete answer is not to inherit them:
`Q.webserver.zygote` forks later workers from a process that never had any.

**There is no way to drop just the descriptor from PHP.** The obvious trick --
`socket_close(socket_import_stream($tls))`, closing the raw socket underneath
without `SSL_shutdown()` -- does not work: `socket_import_stream()` refuses a
stream that has crypto enabled. Without FFI (which would allow libc `close()`),
a TLS stream a PHP process inherited is closed with `SSL_shutdown()` or not at
all.

To count connections held by workers only on a running server:

```bash
ss -Htanp '( sport = :443 )' | grep -v LISTEN | grep -v "pid=<server pid>,"
```

---

### Signals, blocking calls and a helper process

The zygote (a helper process that forks workers on the server's behalf) worked
in every test and then failed on the first busy installation it met. Each cause
was a general PHP trap:

- **`pcntl_signal()` restarts interrupted system calls by default.** A process
  that spends its life blocked in `socket_recvmsg()` never runs its `SIGCHLD`
  handler until the next message arrives, so every child that exits meanwhile
  stays a zombie. Register such handlers with the third argument
  (`$restart_syscalls`) set to `false`: the call returns `EINTR`, the handler
  runs, and the loop goes round.
- **`socket_recvmsg()` records `EINTR` in the global error, not on the socket.**
  Most socket functions set the error on the socket; after an interrupted
  `socket_recvmsg()`, `socket_last_error($socket)` is `0` and only
  `socket_last_error()` (no argument) is `4`. Checking only the socket made an
  interruption look like a failure, and the zygote left at the first worker's
  exit. Check both.
- **A server is interrupted all the time.** The parent's blocking read of the
  zygote's reply was interrupted by `SIGCHLD` from its own exiting workers, and
  the interruption was taken for a dead zygote, which the server then stopped.
  It never showed on a test server, whose pool rarely retires workers; it showed
  on the first burst after every start on a site with a 48-worker dynamic pool.
  Retry interrupted calls within a deadline, and test by showering the process
  with signals (`posix_kill($pid, SIGCHLD)` in a loop) while it works.
- **`$ok = $a !== false and f();` does not do what it reads.** `and` binds more
  loosely than `=`, so `$ok` gets only the comparison and `f()`'s result is
  thrown away. Use `&&` in any assignment.
- **`posix_kill(0, ...)` signals your whole process group.** A test that picks
  a pid from a list and gets `0` because the list was empty kills itself, and on
  a shared runner anything else in its group. Refuse pids below 2 before
  signalling.
- **A process that runs no event loop still inherits the handlers.** A process
  forked from the server gets every signal handler the server installed. Set the
  ones it relies on (`SIGCHLD`, `SIGTERM`) explicitly, and reset them again in
  whatever it forks.

---

### A checklist for a new application

1. Run it with one worker and request the same page twice. The second answer
   must match the first; if not, look for statics and constants.
2. Request two different pages alternately (a public page and a signed-in one).
   Anything from one that shows in the other is state carried in statics.
3. Watch `/Q/health` `workerStats` over a few hundred requests. Memory per worker
   should level off.
4. Rewrite a PHP file while the server runs and request it. The new version must
   appear on the next request.
5. With a warm-up, check the parent holds no database socket after start:
   `ss -xpn | grep "pid=<parent pid>"` and `ss -tpn | grep "pid=<parent pid>"`.
6. Turn `display_errors` off in production.

---
[← Back to README](../README.md)
