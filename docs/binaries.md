# Building and Distributing Binaries

Qbix Server can be packaged as a single executable file containing the PHP runtime, the web server, and your entire application. The binary runs on any machine — nothing to install.

## Why is the binary 5 MB, not 200 MB?

Electron ships an entire copy of Chromium and Node.js inside every app. That's a full browser engine duplicated for each application on the user's machine.

Qbix Server opens the browser that's already there. `--open` calls the system browser — the one the user already has open, already has their passwords saved in, already has their extensions. We ship zero rendering code.

| Component | Electron app | Qbix Server binary |
|---|---|---|
| Browser engine | ~120 MB (Chromium) | 0 — uses system browser |
| Language runtime | ~30 MB (Node.js + V8) | ~4 MB (static PHP) |
| Server | bundled in Node | included in the 4 MB |
| Dependencies | ~10 MB (node_modules) | 0 — no Composer, no npm |
| App code | varies | varies |
| **Typical total** | **~165 MB** | **~5 MB** |

The static PHP binary from [static-php-cli](https://github.com/crazywhalecc/static-php-cli) includes pcntl, sockets, sqlite3, openssl, phar, and mbstring in about 4 MB. The phar with all server code adds ~1 MB. Your app code goes on top.

The tradeoff: Electron gives a controlled rendering environment — same browser engine on every machine. Qbix Server gives whatever browser the user has. For a web app that already works in a browser — which is what PHP apps are — there's no reason to ship another one.

## Variants: Which Binary to Download

Every release carries each platform's binary in four variants, for each PHP
version the server supports (8.2, 8.3, 8.4 and 8.5), plus a source kit. A
variant is a set of PHP extensions compiled in; which extensions each one
carries is defined once, in the extension baseline ([requirements.md](requirements.md)),
and every build takes its list from there.

| Variant | Carries | Size (Linux x86-64, PHP 8.3) | For |
|---|---|---|---|
| `mini` | Only what the server itself needs: process isolation, sockets, TLS, sessions, its own metrics store | 16 MB | Static sites, small scripts, the smallest footprint |
| `lite` | mini + everything a typical application platform requires: XML, images (gd), MySQL/MariaDB, SQLite, cURL, intl, zip, opcache | 68 MB | Most applications |
| `standard` | lite + the recommended tier: PostgreSQL, MongoDB, the caches (apcu, redis, memcached, igbinary), LDAP, SOAP, bcmath and more | 73 MB | The default: what the documentation assumes |
| `full` | Everything static-php-cli can build on that platform, best effort | ~90-120 MB | When you need an extension outside standard |
| `source` | A build kit, not a binary: the phar, the baseline, the console tool and a ready spc recipe for every platform x PHP x variant | — | A platform, architecture or PHP version no release covers |

Most of lite's size is the ICU data `intl` carries; Windows builds are smaller
(35 MB for standard), since `intl` is not in them. Sizes vary a little by platform
and PHP version; each release page lists the exact files.

`full` is built on a best-effort basis: it depends on every third-party source
static-php-cli knows, and one that fails on a platform leaves that platform's
`full` binary out of the release rather than holding back the other variants. The
release's file list shows which were built.

What `full` leaves out, because static-php-cli cannot build it there or builds it
without registering it (checked in CI with PHP 8.3; `ext:plan` shows the current
list with reasons):

| Extension | Left out on | Why |
|---|---|---|
| `rar` | every platform | static-php-cli fetches it from an upstream branch that no longer exists |
| `gmssl` | every platform | the library builds, but the extension is never registered (`php --ri gmssl` fails) |
| `mysqlnd_ed25519`, `mysqlnd_parsec` | every platform | static-php-cli builds them only as shared extensions |
| `protobuf` | every platform | conflicts with `grpc`, which `full` carries |
| `yac` | linux-aarch64, macos-arm64 | finds no atomic compare-and-swap it recognises on arm64 |
| `yac` | windows-x64 | its bundled igbinary breaks `redis`' igbinary detection |
| `xlswriter`, `ds` | windows-x64 | static-php-cli's Windows patch or source path no longer matches |
| `xz` | windows-x64 | the library builds, but the extension is never registered |
To see exactly what a variant carries on a platform, and what it leaves out and
why:

```bash
php qbixctl.php ext:list --variant=standard --platform=linux-x86_64 --php=8.3
php qbixctl.php ext:plan --variant=full --platform=windows-x64
```

### File names

```
qbixserver-<platform>-php<version>-<variant>[.exe]   the server, one file
php-<platform>-php<version>-<variant>[.exe]          the same PHP, as a plain interpreter
qbixserver-windows-x64-php<version>-<variant>-gui.exe  Windows, no console window
qbixserver-source-kit-<release>.tar.gz               the source kit
exponential-velocity_<release>-1+<distro>_all.deb          OS packages (packages.md)
exponential-velocity-<release>-1.<distro>.noarch.rpm
SHA256SUMS                                           checksums of every file above
```

Platforms are `linux-x86_64`, `linux-aarch64`, `macos-arm64` and `windows-x64`.
The names earlier releases used -- `qbixserver-linux-x86_64`,
`qbixserver-windows-x64.exe` and so on -- are still published, as copies of the
`standard` variant on PHP 8.3, so links to `releases/latest/download/<name>`
keep working.

```bash
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64-php8.3-standard
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/SHA256SUMS
sha256sum --ignore-missing -c SHA256SUMS
```

### Checking a binary against its variant

Every release build is checked before it is published: the PHP it was built
with is asked whether it loads everything its variant lists, and a build that
dropped an extension fails by name. You can ask the same of any binary's PHP,
from a checkout of this repository:

```bash
QBIX_STATIC_BUILD=1 ./php-linux-x86_64-php8.3-standard qbixctl.php ext:check --variant=standard
```

### What each platform leaves out

The variants are the same on every platform except where a platform cannot
have an extension: Windows has no `fork()` (so no `pcntl` or `posix`) and a
shorter list from static-php-cli, and the fully static Linux binaries cannot
load ODBC drivers at run time. [requirements.md](requirements.md#forms-of-distribution-and-their-exceptions)
lists every exception with its reason; the Windows section below says what
would bring each Windows gap back. For ODBC, Oracle or Firebird, use the Docker
image ([docker.md](docker.md)) or the OS packages ([packages.md](packages.md)).

### Known platform limitations

- **omnios / illumos (SunOS):** the phar builds and boots — the server binds
  and prints that it is listening — but the serve check fails: a request over
  loopback is accepted at the kernel (curl reports the connection established)
  yet the server returns nothing and curl times out (exit 28, HTTP 000, 0 bytes)
  after ten seconds. It fails for a static file exactly as for a `.php`, and no
  access-log line is ever written, so the request is never serviced at all. The
  `Platforms` workflow runs omnios and shows this, but marks it experimental so
  it does not fail the run (the same treatment as Haiku); the workflow does not
  gate releases in any case.

  This was narrowed on the CI VM over several rounds; the cause is not yet
  fully isolated and no fix has landed, so what is known is recorded here for
  whoever next has an illumos host to work on.

  What is established:

  - **It is not the worker pool.** A static file, served entirely by the parent
    with no worker involved, returns 0 bytes just like a pooled `.php`. Making
    the parent↔worker channel a loopback TCP pair instead of an AF_UNIX
    `stream_socket_pair` changed nothing, as expected once static was ruled out.
    `--workers=1` and `--workers=2` behave identically.
  - **`stream_select()` never reports readiness.** With the accept path traced,
    `stream_select()` returned `0` on every call while a connection was pending
    and had been accepted by the kernel, even as the loop's own timers kept
    firing. So the readable callback (`onAccept`) was never invoked, and in the
    default event-loop mode nothing is ever accepted.
  - **`stream_socket_accept()` does not help by itself.** Bypassing the select
    and polling the callbacks directly (so `onAccept` runs every tick) made
    `stream_socket_accept($listener, 0)` return `false` every time — it selects
    for readability internally before it calls `accept()`, and that inner select
    is the same one that does not work here. Accepting instead through the
    sockets extension (`socket_import_stream()` + `socket_accept()`) on the
    server's listener returned `false` every time too.
  - **Yet every accept mechanism works in isolation on the same host.** A
    standalone probe on the omnios VM — a listener plus a forked client that
    connects — accepted the connection with all of: `stream_socket_accept()`
    (non-blocking, polled), `socket_import_stream()` + `socket_accept()`, a
    native `socket_create()` listener with `socket_select()`, the same native
    listener polled with `socket_accept()`, and `socket_import_stream()` +
    `socket_select()`. Notably `socket_select()` (the sockets extension) works
    where `stream_select()` does not.

  So the accept primitives themselves are fine on illumos; the failure is
  specific to how the **server** sets up and accepts on its listener, and the
  isolated probe differs from the server in exactly three ways, one of which is
  the real cause: the server binds `0.0.0.0` (the probe bound `127.0.0.1`); the
  server passes a `backlog` socket-context option to `stream_socket_server()`
  (the probe used the default); and the server pre-forks its workers, which
  inherit the listener fd, before it begins accepting (the probe did neither).
  Each is a one-line change to test, but bisecting them needs a shell on an
  illumos host to iterate against, which the CI VM does not provide. A promising
  fix, once the cause is confirmed, is to drive the event loop with
  `socket_select()` (which works here) rather than `stream_select()` on illumos,
  or to accept through a native `socket_create()` listener.

  `tests/phar-serves.sh` prints fetch diagnostics on failure (the client used,
  its exit status, and the connection headers over both `127.0.0.1` and
  `localhost`) so the symptom stays visible on every run.

## Creating a Binary

### From the phar (requires static-php-cli)

```bash
# Download spc and build a static PHP with the extensions you need
./spc build "pcntl,sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,posix,session" --build-cli --build-micro

# Build the phar
php -d phar.readonly=0 build-phar.php

# Combine into a single binary
./spc micro:combine bin/qbixserver.phar -O qbixserver
chmod +x qbixserver
```

### Pack your app into the binary

```bash
# Bundle app files — the binary serves them directly
./qbixserver --pack=./my-app --output=myapp
./myapp
```

The `--pack` flag appends your app files as a zip to the binary. At startup the server detects the appended zip, extracts to a temp directory, and serves from there.

For a GUI app that opens a browser automatically:

```bash
./qbixserver --pack=./my-app --gui --output=myapp
# On run: browser opens, no terminal interaction needed
```

## Managing Binaries Like Zip Files

The packed binary is a valid zip file (with the executable prepended). Standard zip tools can list, extract, and add files.

### List contents

```bash
unzip -l myapp
# or
7z l myapp
```

### Extract a file

```bash
unzip -p myapp web/config.json
```

### Add or update files

```bash
# Adjust zip offsets first (one-time)
zip -A myapp

# Add files
cd my-updates && zip -r ../myapp web/new-page.html web/style.css
```

### Cross-platform support

| OS | Tool | Read | Write |
|---|---|---|---|
| Linux | unzip, 7z | ✅ | ✅ |
| macOS | unzip, Keka, 7-Zip | ✅ | ✅ |
| Windows | 7-Zip, WinRAR | ✅ | ✅ |

Windows Explorer needs the file renamed to `.zip` to browse it natively. 7-Zip opens it regardless of extension.

### Customizing a binary

Because the packed binary is a zip, you can create your own distribution by modifying the bundled files. This is how you'd white-label the server, ship a patched version of your app, or swap out configuration for a specific customer.

```bash
# Start from the release binary
cp qbixserver-linux-x86_64 myapp
chmod +x myapp

# Pack your app into it
./myapp --pack=./my-app --output=myapp

# Later, update a single file without rebuilding
zip -A myapp                                  # fix offsets (one-time)
zip myapp web/config.json                    # replace config
zip myapp web/templates/header.html          # update a template
zip -d myapp web/old-page.html               # remove a file

# On Windows with 7-Zip (right-click → Open Archive):
# drag files in and out, save, done.
```

The workflow is: start with the official binary, pack your app in, then treat the result as a zip you can edit freely. Add pages, swap assets, update config — all without rebuilding from source.

After modifying a binary, its hash changes and any existing signatures become invalid. If you're distributing the binary to others, re-sign it:

```bash
./myapp --sign-binary --key=mykey.pem --signer="My Company"
./myapp --publish-rekor   # optional: publish to transparency log
```

If you're running it yourself on a trusted machine, signing is optional — the binary works regardless.

## Where Data Is Stored

When running as a packed binary, the server stores runtime data next to the binary:

```
/opt/myapp              ← the binary
/opt/myapp.data/        ← created automatically
  panel.json            ← control panel config and password
  metrics.db            ← time-series stats (SQLite)
  logs/access.log       ← buffered access log
  logs/error.log        ← error log
  certs/                ← TLS certificates
  keys/                 ← signing keys
```

Override with `--data-dir=/path` or set `QBIX_DATA_DIR`.

## Signing Binaries

### Generate a key

```bash
./qbixserver --generate-key=alice
# → local/keys/alice.pem (private, ECDSA P-256)
# → local/keys/alice.pub.pem (public, share this)
```

### Sign

```bash
./qbixserver --sign-binary --key=local/keys/alice.pem --signer=Alice
```

Multiple people can sign independently. Each signature is appended to `<binary>.signatures.json`:

```bash
# Alice signs on her machine
./qbixserver --sign-binary --key=alice.pem --signer=Alice

# Bob signs on his machine
./qbixserver --sign-binary --key=bob.pem --signer=Bob
```

### Verify

```bash
# Check that at least 1 signature is valid
./qbixserver --verify-binary

# Require 2 of N signatures (M-of-N threshold)
./qbixserver --verify-binary --m=2
```

### Publish to Sigstore Rekor

For independent verification, publish the attestation to Sigstore's public transparency log:

```bash
./qbixserver --publish-rekor
# → Published! Rekor UUID: ...
# → Verify: https://search.sigstore.dev/?uuid=...
```

Rekor provides a tamper-evident record. Anyone can fetch the entry and confirm the hash matches what the server is running. The server can't fake this — Rekor's Merkle tree is append-only.

### Runtime attestation

The server exposes `GET /Q/attestation` returning the binary hash, all signatures, verification status, and the Rekor log entry (if published). Monitoring tools or browser extensions can verify the deployment without trusting the server alone.

### From the control panel

The Security tab lets you sign, verify, and publish to Rekor from the browser. Paste a PEM private key, name the signer, click Sign. Adjust the M threshold and click Verify. One-click Rekor publishing with confirmation.


## The Windows Build: What It Leaves Out, and What Would Bring It Back

The Windows binary is built by the same release workflow as the others, with
static-php-cli (spc) on a GitHub `windows-2022` runner. It is marked
experimental: a failure there never blocks the Linux and macOS binaries, and
when it fails the job prints spc's own build logs, where the compiler's
errors are, instead of only "exited with code 2".

Some of what the other platforms carry is left out on Windows, or replaced.
Each entry says why, and exactly what has to change before it can come back.

| What | On Windows | Why | Needed to bring it back |
|---|---|---|---|
| `pcntl`, `posix` | Not built | POSIX-only PHP extensions: there is no `fork()` on Windows. | Nothing can: these do not exist for Windows. Per-request isolation comes from `qbix_fork.dll`, or from php-cgi when that is missing (see below). |
| `intl` | Left out | The ICU bundle spc ships for Windows is ICU 78, whose headers require C++17. PHP 8.3's Windows build compiles `ext/intl` with the compiler's default standard, so the build dies in `intl_convertcpp.cpp` with `icu_78::UnicodeString: use of undefined type`. Raising the standard globally (`CL=/std:c++17`) does not work: it also reaches the C libraries, and libxml2's `/std:c11` rejects it (MSVC error D8016). | Any one of: the PHP Windows build passing `/std:c++17` to `ext/intl`'s C++ sources only; spc supporting that per extension; or spc offering an ICU 74 or earlier bundle for Windows. Then add `intl` back to the Windows `extensions:` list in `.github/workflows/release.yml`. |
| `xsl` | Left out | spc reports "library [libxslt] is in the lib.json list but not supported to compile" on Windows. | libxslt support in spc's Windows builder. |
| `ffi` | Built when available | spc's download step asks for `ffi` first and retries without it if that fails. Without FFI there is no `qbix_fork.dll`. | Nothing; it is attempted on every build. |
| `qbix_fork.dll` | Best effort | A small native shim (`src/fork_shim.c`) that gives copy-on-write worker processes through FFI. It is compiled after PHP, and a failure there does not fail the job. Without it the server uses php-cgi per request, which is slower but correct. | Nothing; it is attempted on every build. |
| Runner image | Pinned to `windows-2022` | `windows-latest` moved to an image with Visual Studio 2026 (version 18), which spc does not look for: it checks fixed paths for VS 2022 and 2019 only, and failed with "Current VS version  is not supported yet!". Its Windows libraries also carry VS 2022 project files. | spc supporting the VS 18 toolset; then the pin can move. |

Releases up to and including v0.0.4.27 were published without a Windows
binary. Two things stopped it: the `intl` failure above, and, once PHP built,
a packaging step that looked for the new `php.exe` under `spc-src\buildroot`
when static-php-cli had put it in `buildroot` at the checkout's root, so it
skipped itself and the output check failed a build that had worked. Both are
fixed; the next release carries the Windows binaries, without `intl`.

When a Windows build fails, open the job's "Show why the PHP build failed"
step: it prints the error lines and the end of `log\spc.output.log` and
`log\spc.shell.log`.
## Windows GUI Mode

On Windows, the release includes two variants:

- `qbixserver-windows-x64.exe` — console app (shows a terminal window)
- `qbixserver-windows-x64-gui.exe` — GUI subsystem (no terminal window)

The GUI variant is identical except the PE header's subsystem flag is set to WINDOWS via `editbin`. Double-click it and the browser opens with your app — no console visible.

When using `--pack --gui`, a `.vbs` launcher is also created for machines without the GUI binary:

```
myapp.exe    ← the packed binary
myapp.vbs    ← double-click this, runs myapp.exe hidden
```

## Platform Code Signing

The ECDSA signatures described above verify that the code was approved by your team. They don't satisfy the operating system's own code signing requirements. For distribution to end users, you'll also need platform-specific signing so the OS doesn't block the binary on download.

### macOS (Apple Developer ID)

macOS Gatekeeper blocks unsigned binaries downloaded from the internet. To distribute a packed binary, sign it with an Apple Developer ID certificate ($99/year) and submit it for notarization:

```bash
# Sign with your Developer ID
codesign --force --options runtime \
  --sign "Developer ID Application: Your Name (TEAM_ID)" \
  myapp

# Submit for notarization (requires Xcode command line tools)
xcrun notarytool submit myapp \
  --apple-id you@example.com \
  --team-id TEAM_ID \
  --password @keychain:notarytool \
  --wait

# Staple the notarization ticket to the binary
xcrun stapler staple myapp
```

After stapling, any Mac can run the binary without Gatekeeper warnings. Without notarization, users have to right-click → Open → "Open Anyway", which is not acceptable for production distribution.

The GitHub Actions workflow can automate this: store the Developer ID certificate as a repository secret, import it into the macOS runner's keychain, and sign + notarize as part of the release build.

### Windows (Authenticode)

Windows SmartScreen flags unsigned executables downloaded from the internet. To avoid the "Windows protected your PC" warning, sign with an Authenticode certificate from a CA like DigiCert, Sectigo, or SSL.com (typically $200-400/year, or free through SignPath for open source):

```powershell
# Sign with signtool (from Windows SDK)
signtool sign /tr http://timestamp.digicert.com /td sha256 /fd sha256 /a myapp.exe
```

EV (Extended Validation) certificates bypass SmartScreen immediately. Standard certificates build reputation over time — after enough users run the signed binary without issues, SmartScreen stops warning.

### Linux

Linux distributions don't enforce code signing at the OS level. The ECDSA signatures and optional Rekor attestation are sufficient for verifying binary integrity. For distribution via package managers, sign the `.deb` or `.rpm` package with GPG as those ecosystems expect.

### Relationship to ECDSA signatures

Platform signing (codesign, Authenticode) and ECDSA signing serve different purposes:

- **Platform signing** tells the OS "this binary comes from a known developer." It's about the distributor's identity.
- **ECDSA M-of-N signing** tells your users "this specific code was reviewed and approved by 2 of 3 authorized signers." It's about the code's integrity.
- **Rekor** tells anyone "this code existed in this exact form at this timestamp." It's about the deployment's provenance.

You'd typically use all three for a production release: platform-sign so the OS allows it, ECDSA-sign so your team can verify the code, Rekor-publish so auditors can confirm nothing changed after release.
