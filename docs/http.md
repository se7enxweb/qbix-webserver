## 🌐 HTTP — Fork Per Request

Every PHP request forks from the preloaded parent, handles the request, and dies. No cleanup needed — the OS reclaims everything.

### Static files

Drop files in `web/`. They're served directly:

```
web/ ├── index.html        ← GET /index.html ├── style.css         ← GET /style.css └── app.js            ← GET /app.js
```

### PHP scripts

PHP files in `web/` execute as scripts — same as Apache or nginx + php-fpm:

```php
<?php
// web/api/users.php — GET /api/users.php Q_Response::header('Content-Type: application/json');
$users = MyApp\Users::recent(20);
echo json_encode($users);
```

### Clean URL handlers

With [routing configured](#️-clean-url-routing-optional), handlers in `handlers/` map to clean URLs:

```php
<?php
// handlers/api/users/get.php — GET /api/users function api_users_get(&$params, &$result) {
    Q_Response::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::recent(20));
}
```

### What happens per request

```
Browser: GET /api/users
  → Parent forks child process (COW — a worker's own pages come to 1.3–1.9 MB bare, ~10 MB for a full CMS)
  → Child runs handler (classes already loaded)
  → Child sends response and exits
  → OS reclaims all memory
```

No memory leaks. No state from one request bleeding into the next. `exit()` only kills the child — the server keeps running.

### Keep-alive

An HTTP/1.1 connection stays open for further requests unless the client asks
otherwise. Two settings bound it (both under `Q.webserver.keepAlive`):

| Setting | Default | Meaning |
|---|---|---|
| `max` | `100` | Requests one connection may carry. The last of them is answered and the connection is closed. |
| `timeout` | `15` | Seconds an idle connection is kept waiting for its next request. |

**The `Connection` header always says what the server is about to do.** A
response says `Connection: keep-alive` only when the connection really stays
open, and `Connection: close` when it is about to be closed:

- on the last request a connection may carry (`keepAlive.max`);
- when the client sent `Connection: close`;
- after any `5xx` response, because the server closes the connection then too.

Every response the server writes itself goes by this rule: a page from the
response cache, a `304`, an error page, an ACME challenge answer, a static file.
The decision is made once per request, before the request is handled, and
cleared afterwards, so a response written later from a callback is not affected.

Until September 2026 the cache hit and the error pages said `keep-alive` on the
last request as well, and the server then closed the connection. A client that
believed the header sent its next request into a closed socket: ApacheBench
counted about one "Length" or "Exception" failure per thousand requests, always
at request 1000, 2000 and so on on an installation with `keepAlive.max` set to
1000, and a reverse proxy reusing the connection got an error for that request.
`tests/unit-keepalive-connection-header.php` guards it.

To check it on your own installation, send more requests down one connection
than `keepAlive.max` allows and look at the header of the last one before the
limit -- it must be `Connection: close`:

```bash
curl -s -o /dev/null -D - https://example.com/ https://example.com/ https://example.com/ | grep -i '^connection'
```

**A page rendered by a worker closes the connection on HTTP/1.1.** Static
files, cache hits and the server's own pages keep it open; a response that came
back from a PHP worker is sent with `Connection: close`, so an HTTP/1.1 client
opens a new connection (and, over HTTPS, a new TLS handshake) for its next
request. Browsers speak HTTP/2, where one connection carries every request and
is kept open either way. Reverse proxies that talk HTTP/1.1 to the server pay
the reconnect.

---

---
[← Back to README](../README.md)

