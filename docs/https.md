## 🔐 HTTPS and Certificates

The server looks after its own certificate. Give it your certificate files, an
archive or bundle from your CA, or let it get one from Let's Encrypt — and from
then on it checks, renews and swaps certificates by itself, without a restart and
without anything else installed. When it has nothing usable, it serves HTTPS on a
self-signed certificate of its own rather than switching HTTPS off.

This page is the complete guide: quick starts, how it works, every setting,
Let's Encrypt in depth, your own certificates, the console, troubleshooting and
long-term operation.

- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Where files live](#where-files-live)
- [Settings reference](#settings-reference)
- [Let's Encrypt and other ACME CAs](#lets-encrypt-and-other-acme-cas)
- [Your own certificate](#your-own-certificate)
- [Self-signed](#self-signed)
- [The console](#the-console)
- [Troubleshooting](#troubleshooting)
- [Running it for years](#running-it-for-years)
- [Security notes](#security-notes)

---

### Quick start

All settings live under `Q.web.https` in your site file (for example
`/etc/qbix/sites-enabled/example.com.conf`) or in the `--config` JSON.

**Let's Encrypt** (port 80 of this server reachable from the internet, DNS pointing here):

```json
{ "Q": { "web": { "https": {
  "port": 443,
  "mode": "letsencrypt",
  "acme": { "email": "admin@example.com", "domains": ["example.com", "www.example.com"] }
} } } }
```

**Your own files** (from any CA, or a control panel):

```json
{ "Q": { "web": { "https": {
  "port": 443,
  "mode": "files",
  "cert": "/etc/ssl/example.com/fullchain.pem",
  "key":  "/etc/ssl/example.com/privkey.pem"
} } } }
```

**Development, no certificate at all:**

```json
{ "Q": { "web": { "https": { "port": 8443, "mode": "self-signed" } } } }
```

Start the server as usual. On the console you see one line such as
`tls: certificate ready (EC, openssl-ecdsa, 0.1s, 397 days, localhost ...)`, or,
for Let's Encrypt, a self-signed certificate first and the real one a minute later.
Check at any time with `qbixconsole ssl:show`.

---

### How it works

1. **A source provides the certificate.** `mode` chooses it: `files`, `archive`,
   `pkcs12`, `letsencrypt` (built in; any ACME CA), `certbot`, `remote`, or
   `self-signed`.
2. **The pair is checked before it is used.** The certificate must parse, must not
   have expired, and the key must belong to it. A pair that fails is never put in
   front of a visitor.
3. **The listener reads a private copy.** The checked pair is copied to
   `<ssl dir>/active-<port>-<fingerprint>.pem/.key`, and HTTPS reads only that copy.
   A file being rewritten elsewhere (a renewal half done) can therefore never break
   a handshake.
4. **HTTPS comes up before HTTP**, so there is never a moment when the server
   answers plain HTTP but not HTTPS.
5. **Everything is watched.** Every `watchInterval` seconds (60) the server looks
   at the source's files. When they change and the new pair checks out, the new
   certificate is presented to the next connection — no restart, no dropped
   connections (open connections keep the certificate they were given). A change
   that does not check out yet (a certificate written before its key) gets one
   interval to settle.
6. **Renewal happens by itself.** Let's Encrypt, certbot and remote sources renew
   in the background, when due. A job never blocks the server: it runs in a
   separate process, and a failure is retried with growing pauses (5 minutes,
   doubling up to a day) so a CA's rate limits are never spent.
7. **Self-signed stands in.** When the source has nothing usable — not issued yet,
   expired, deleted, unreadable — HTTPS runs on a self-signed certificate, and
   switches back to the real one as soon as it is usable. Set `"fallback": "none"`
   to leave HTTPS off instead.

---

### Where files live

Laid out like `/etc/apache2`: the certificate files belong to the configuration
tree, in its `ssl/` directory — `/etc/qbix/ssl` (or the top overlay's `ssl/` when a
distribution stacks one, for example `/etc/vc/ssl`). Without a configuration
directory, `config/certs/self-signed/` under the application is used. Set
`https.selfSigned.dir` to choose another place.

```
ssl/
  self-signed.pem            the self-signed certificate (0644)
  self-signed.key            its key (0600)
  self-signed.pem.prev       the pair it last replaced
  active-443-<fp>.pem/.key   the copy HTTPS reads (do not edit)
  imported/                  pairs imported from DER, archives, bundles, remote
    files.pem / files.key
    archive.pem / archive.key
    pkcs12.pem / pkcs12.key
    remote.pem / remote.key, remote-download.<ext>
  acme/
    account-<ca>.pem         one account key per CA (0600) — keep it
    <first domain>/fullchain.pem
    <first domain>/privkey.pem
    <first domain>/state.json   last attempt, last error, next attempt
    challenges/<token>       pending HTTP-01 answers (short-lived)
  certbot-<domain>.json      when certbot was last asked to renew
```

Back up `ssl/` with the rest of your configuration. Everything in it can be made
again except the ACME account keys, which keep your account with the CA.

---

### Settings reference

Every setting, with its default. All are under `Q.web.https`.

| Setting | Default | Meaning |
|---|---|---|
| `port` | `443` | HTTPS port. `--https-port` overrides it. |
| `mode` | `"manual"` | The source: `files` (same as `manual`), `archive`, `pkcs12`, `letsencrypt` (same as `acme`), `certbot`, `remote`, `self-signed`. |
| `fallback` | `"self-signed"` | When the source has nothing usable: `"self-signed"` or `"none"` (HTTPS off). |
| `watchInterval` | `60` | Seconds between checks of the certificate files. |
| `domain` | — | The main host name; the default for Let's Encrypt and certbot domains. |
| `selfSigned.dir` | `<conf dir>/ssl` | Where the self-signed certificate, imported pairs and ACME files live. |
| `selfSigned.hosts` | automatic | Names and addresses of the self-signed certificate. Default: `domain`, the virtual host names and aliases, this machine's host name, `localhost`, `127.0.0.1`, `::1` and the bound address. |
| `sources.<mode>` | — | A class implementing `Q_WebServer_Certificate_Source`, to add a mode of your own. |

**`files`** (and `manual`)

| Setting | Meaning |
|---|---|
| `cert` | The certificate: the leaf alone or with its chain; PEM, DER or PKCS#7. Default `config/certs/fullchain.pem`. |
| `key` | Its private key; may be the same file as `cert`. Default `config/certs/privkey.pem`. |
| `chain` | Optional: the chain, when `cert` holds only the leaf. Any order. |
| `keyPassword` / `keyPasswordFile` / `keyPasswordEnv` | For an encrypted key: the password itself, a file holding it, or the name of an environment variable holding it. |

**`archive`**

| Setting | Meaning |
|---|---|
| `archive` | The archive: `.zip`, `.tar`, `.tar.gz`/`.tgz`, `.tar.bz2`/`.tbz2`, `.tar.xz`/`.txz`, `.rar`, `.7z`. |
| `password` / `passwordFile` / `passwordEnv` | For an encrypted zip, rar or 7z. |
| `keyPassword` / `keyPasswordFile` / `keyPasswordEnv` | For an encrypted key, or a `.p12` inside. |

**`pkcs12`**

| Setting | Meaning |
|---|---|
| `bundle` | The `.p12` / `.pfx` file. |
| `password` / `passwordFile` / `passwordEnv` | Its password. |

**`letsencrypt`** (and `acme`): under `https.acme`

| Setting | Default | Meaning |
|---|---|---|
| `email` | — | Contact address for the account (expiry notices from the CA). Recommended. |
| `domains` | `[domain]` | Names to certify. The first names the directory. `*.example.com` needs `dns-01`. |
| `directory` | `"letsencrypt"` | `letsencrypt`, `letsencrypt-staging`, `zerossl`, `google`, `google-staging`, or any ACME directory URL. |
| `challenge` | `"http-01"` | `http-01` or `dns-01`. |
| `webroot` | — | Also write HTTP-01 answers to `<webroot>/.well-known/acme-challenge/`, for when another server answers port 80. |
| `dnsHook` | — | `dns-01`: a program run as `HOOK add NAME VALUE` and `HOOK remove NAME VALUE`. A string (one executable) or an array (a command with arguments). |
| `dnsWait` | `30` | `dns-01`: seconds to wait after the hook added the record. |
| `keyType` | `"ec256"` | `ec256`, `ec384`, `rsa2048`, `rsa3072`, `rsa4096`. A new key is made for every certificate. |
| `renewAt` | `0.33` | Renew when this share of the certificate's lifetime is left. |
| `eab.kid`, `eab.hmacKey` / `hmacKeyFile` / `hmacKeyEnv` | — | External account binding, for CAs that require it (ZeroSSL, Google). |
| `timeout` | `300` | Seconds to wait for validation and issuance. |
| `caBundle`, `verify` | —, `true` | For a private CA whose own HTTPS uses a CA your system does not know. |
| `dir`, `challengeDir` | `<ssl dir>/acme`, `<acme dir>/challenges` | Where ACME files are kept. |

**`certbot`**: under `https.certbot`

| Setting | Default | Meaning |
|---|---|---|
| `domains` | `[domain]` | Names to certify. |
| `email` | — | Contact address. |
| `webroot` | — | Where certbot writes HTTP-01 answers (must be served at `/.well-known/acme-challenge/`). |
| `binary` | found | The certbot program. |
| `live` | `/etc/letsencrypt/live` | Where certbot keeps certificates. |

**`remote`**: under `https.remote`

| Setting | Default | Meaning |
|---|---|---|
| `url` | — | Where to download from: any archive, a `.p12`, or a PEM file. |
| `checkInterval` | `86400` | Seconds between downloads. |
| `headers` | — | Extra request headers, for example `["Authorization: Bearer ..."]`. |
| `password`, `keyPassword` (and `...File`, `...Env`) | — | For an encrypted archive or key. |

**Command line**: `--https-port=N`; `--quiet`/`-q` (errors only), `--verbose`
(every certificate provider tried), `--debug` (every event).

The older settings keep working unchanged: `mode: "manual"` with `cert`/`key`,
and `Q.webserver.tls.acmeEmail`, `certDir`, `acmeStaging` with
`Q.webserver.domains.<name>.tls = "auto"` for the virtual-host automation.

---

### Let's Encrypt and other ACME CAs

The control panel's Domains tab can also issue for one domain at a time: it
shows the certificate covering each domain and adds the domain (and its
aliases) to the names asked for, as a background job, and later renewals keep
them. See [Dashboard & Panel](dashboard.md), Domains tab, "Certificate".

#### What you need

- **The names resolve to this server.** Check with `dig +short example.com`.
- **For HTTP-01 (the default): port 80 reaches this server** — the CA fetches
  `http://example.com/.well-known/acme-challenge/<token>`. The server answers those
  requests on its HTTP port by itself. If it is not on port 80 (behind a proxy, or
  another web server owns port 80), either proxy `/.well-known/acme-challenge/` to
  it, or set `acme.webroot` to the document root of the server that answers port 80:
  the answers are written there too.
- **For wildcards (`*.example.com`) or no port 80 at all: DNS-01** with a hook
  (below).

#### Try staging first

Let's Encrypt limits how many certificates you may get (currently, among others,
5 duplicate certificates a week). Get the settings right against the staging server,
whose certificates browsers do not trust but which has generous limits:

```sh
qbixconsole ssl:issue --staging --config=/etc/qbix/sites-enabled/example.com.conf
```

When that works, remove `--staging` (or set `"directory": "letsencrypt"`) and the
server gets the real certificate on its own. The staging and production accounts
are separate files, so nothing needs cleaning up.

#### What happens on the first start

HTTPS comes up at once on a self-signed certificate, and a background job asks the
CA. Within a minute or so the real certificate is in `ssl/acme/<domain>/` and the
watcher swaps it in. The console shows each step; `qbixconsole ssl:show` shows the
state, the last error and when the next attempt is.

#### Renewal

Nothing to do. The certificate is renewed when a third of its lifetime is left
(`renewAt`): 30 days before a 90-day certificate expires, 15 days before a 45-day
one, 2 days before a 6-day one — so shorter certificate lifetimes, which CAs are
moving to, need no change here. It is also renewed when you change `domains`. If
renewal fails, the current certificate stays in use while it is valid, and the
attempt is repeated after 5 minutes, then 10, 20 … up to once a day.

#### Wildcards and DNS-01

Set `"challenge": "dns-01"` and a `dnsHook`. The hook is any program you write for
your DNS provider:

```
HOOK add    _acme-challenge.example.com  <value>    # create a TXT record
HOOK remove _acme-challenge.example.com  <value>    # delete it again
```

It receives the same in `QBIX_ACME_ACTION`, `QBIX_ACME_NAME` and
`QBIX_ACME_VALUE`, must exit 0 on success, and may print an error otherwise (shown
in `ssl:show`). After `add`, the server waits `dnsWait` seconds for the record to
spread. An example for a provider with an HTTP API:

```sh
#!/bin/sh
# /usr/local/bin/dns-txt  — add|remove NAME VALUE
set -e
case "$1" in
  add)    curl -fsS -X POST   "https://dns.example.net/api/txt" -d "name=$2" -d "value=$3" -H "Authorization: Bearer $(cat /etc/qbix/dns-token)";;
  remove) curl -fsS -X DELETE "https://dns.example.net/api/txt?name=$2&value=$3"        -H "Authorization: Bearer $(cat /etc/qbix/dns-token)";;
esac
```

```json
"acme": {
  "email": "admin@example.com",
  "domains": ["*.example.com", "example.com"],
  "challenge": "dns-01",
  "dnsHook": "/usr/local/bin/dns-txt",
  "dnsWait": 60
}
```

#### Other CAs

```json
"acme": { "directory": "zerossl", "email": "admin@example.com",
          "eab": { "kid": "YOUR-EAB-KID", "hmacKeyFile": "/etc/qbix/zerossl-hmac" } }
```

ZeroSSL and Google Trust Services require external account binding: create the
EAB credentials in their dashboard and give them as `eab`. Any other ACME CA (a
company CA such as step-ca, for example) works with its directory URL; give its
root in `caBundle` if your system does not trust it.

#### Rate limits and good behaviour

The server never asks more often than needed: one account per CA, a certificate
only when one is due, and a pause that doubles after every failure. If the CA
answers "rate limited", the next attempt waits at least an hour. `ssl:show` tells
you when the next attempt is.

---

### Your own certificate

Whatever your CA or control panel gave you, point the server at it. What each piece
is — key, certificate, chain — is worked out from the contents, not the file names.

| You have | Mode | Notes |
|---|---|---|
| `fullchain.pem` + `privkey.pem` | `files` | Used where they are; replace them and the new ones are used. |
| `certificate.crt` + `ca_bundle.crt` + `private.key` | `files` with `chain` | Any chain order; a root in it is dropped. |
| A `.cer`/`.der` (DER) certificate | `files` | Converted. |
| A `.p7b` (PKCS#7) chain | `files` (as `cert` or `chain`) | Read. |
| One file with key and certificates | `files`, `cert` = `key` = that file | Split. |
| An encrypted key | `files` + `keyPassword` / `keyPasswordFile` / `keyPasswordEnv` | Decrypted into the private copy only. |
| A `.zip`, `.tar.gz`, `.tar.bz2`, `.tar.xz`, `.rar`, `.7z` | `archive` | Any names, sub-directories fine; `.p12` inside works too. |
| A `.p12` / `.pfx` | `pkcs12` | Old RC2/3DES bundles are read too, through the `openssl` program. |
| A URL that serves any of these | `remote` | Downloaded daily. |

To **renew** your own certificate, replace the file(s) or drop the new archive in
place — atomically if you can (write a temporary file and rename it). The server
picks it up within `watchInterval` seconds, checks it, and swaps it in. Before
switching, check what a file holds with `qbixconsole ssl:check <files...>`.

Formats that need a helper: `.rar` and `.7z` are read with `bsdtar`
(libarchive-tools; it reads every format listed) or `unrar` / `7z`; `.tar.xz` with
`bsdtar` or `tar`. `.zip`, `.tar`, `.tar.gz` and `.tar.bz2` need nothing beyond PHP.

---

### Self-signed

`mode: "self-signed"`, or the automatic fallback, makes a certificate for the
server's own names (see `selfSigned.hosts`). It tries, in order, until one works:

1. the openssl extension with an ECDSA P-256 key,
2. the openssl extension with RSA 2048 signed with SHA-256,
3. the system `openssl` program,
4. the system "snakeoil" certificate (`/etc/ssl/certs/ssl-cert-snakeoil.pem`, as
   Debian's `ssl-cert` installs it).

It is valid for 397 days, renewed 30 days before it expires and whenever the names
change, and kept in `ssl/self-signed.*`. Browsers warn about it, as about any
self-signed certificate; it is meant for development, for machines on a private
network, and as a stand-in while a real certificate is on its way.

---

### The console

```sh
qbixconsole ssl:show [--json]            # what HTTPS uses, days left, hosts, ACME state
qbixconsole ssl:check <file>...          # what a certificate file, archive or bundle holds
qbixconsole ssl:issue [--staging] [domain...]   # get the ACME certificate now, in the foreground
qbixconsole ssl:renew [--if-needed] [host...]   # a new self-signed certificate now
```

Each takes `--conf-dir`, `--config` and `--distribution` like the other commands,
and `-q`, `-v`, `-vv`/`--debug` for how much it prints. A certificate changed from
the console reaches a running server within `watchInterval` seconds — no restart.

For monitoring, `ssl:show --json` gives the mode, each certificate's `usable`,
`daysLeft`, `hosts` and fingerprint, and for ACME the last attempt, last error and
next attempt. Alert when `daysLeft` falls below 14 for a CA certificate: renewal
normally happens at 30.

---

### Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| Browser warns "self-signed" | The source has nothing usable yet, so the fallback is serving. | `qbixconsole ssl:show`: it says why (no file, wrong password, ACME error). |
| `ssl:show`: ACME error `Invalid response from http://…/.well-known/acme-challenge/…` | Port 80 does not reach this server. | Open port 80, proxy `/.well-known/acme-challenge/` to it, or set `acme.webroot` for the server that owns port 80. |
| ACME error `DNS problem: NXDOMAIN` | The name does not resolve (yet). | Fix DNS; the next attempt follows by itself. |
| ACME error `rateLimited` | Too many certificates for these names this week. | Wait (the server backs off by itself); test with `letsencrypt-staging`. |
| ACME error `externalAccountRequired` | The CA needs account binding. | Add `acme.eab`. |
| `the DNS hook failed` | The hook exited non-zero. | Run it by hand with `add NAME VALUE`; its output is shown. |
| `no private key found (is it encrypted? …)` | Encrypted key, no password given. | Set `keyPassword`, `keyPasswordFile` or `keyPasswordEnv`. |
| `none of the certificates belongs to the private key` | The key and certificate are from different requests. | Use the key the certificate was issued for. |
| `no tool here reads …rar` | No `bsdtar`/`unrar`. | Install `libarchive-tools` (or `bsdtar`), or repack as `.zip`. |
| A renewed file is not picked up | It is not usable yet (written before its key), or the server watches another path. | Wait one interval; check `ssl:show` for the path in use. |
| HTTPS does not start at all | `fallback` is `"none"` and there is no usable certificate, or the port is taken. | See the server log; `ssl:show`. |

Start the server with `--verbose` to see every provider and step, or `--debug` for
every event with timings.

---

### Running it for years

- **Nothing needs doing for the certificate to stay valid.** Let's Encrypt
  certificates renew themselves; your own files are picked up when you replace them;
  the self-signed one renews itself.
- **No external programs are required** for `files`, `pkcs12`, `.zip`/`.tar*`
  archives, self-signed or Let's Encrypt: PHP's openssl extension is enough (curl is
  used when present). certbot, `bsdtar`, `unrar` and `7z` are optional.
- **Settings are stable.** Every setting on this page keeps its meaning; the older
  ones (`manual`, `Q.webserver.tls.*`) keep working. An upgrade never needs a
  change to your site file for HTTPS to keep working.
- **CA changes need no code.** ACME is a standard (RFC 8555); a different CA is a
  different `directory`. Shorter certificate lifetimes are handled by `renewAt`.
  If a CA stops, point `directory` at another one — a new account is made for it.
- **Watch one thing:** `ssl:show --json` → `daysLeft`. Below 14 for a CA
  certificate means renewal has been failing for two weeks; `acme.lastError` says why.
- **Back up** `ssl/` (especially `acme/account-*.pem`) with your configuration.
- **Clock:** certificates are checked against the system clock; keep NTP running.

---

### Security notes

- Private keys are written `0600`, atomically, and never logged. Imported keys are
  stored decrypted only in the server's private copy under `ssl/`.
- Passwords can stay out of the configuration: `…File` reads them from a file,
  `…Env` from an environment variable.
- Archives are read in memory where PHP can; when a tool extracts one, it is into a
  fresh private directory, member names never decide where anything is written,
  links are not followed, and sizes and member counts are capped.
- External programs (openssl, certbot, archive tools, your DNS hook) are run with
  an argument list, never through a shell, so no name or password can inject a
  command.
- The ACME client verifies the CA's HTTPS certificate (turn `verify` off only for a
  test CA), uses a separate account key per CA and a new certificate key for every
  certificate.
- Challenge answers are served only for well-formed tokens that are pending, from
  their own directory.
