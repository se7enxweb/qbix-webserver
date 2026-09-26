## 🐳 Docker Images

The server is published as container images built on the official PHP images
(Debian bookworm), one per PHP version and variant, for `amd64` and `arm64`.
Each image carries its variant of the extension baseline
([requirements.md](requirements.md)), is checked against it when it is built, and
answers a health check.

- [Tags](#tags)
- [Running it](#running-it)
- [What each variant carries](#what-each-variant-carries)
- [Database add-ons: Oracle, Firebird, ODBC](#database-add-ons-oracle-firebird-odbc)
- [Configuration](#configuration)
- [Health check](#health-check)
- [Compose](#compose)
- [Building your own](#building-your-own)

---

### Tags

```
ghcr.io/se7enxweb/exponential-velocity:<release>-php<version>-<variant>   fixed
ghcr.io/se7enxweb/exponential-velocity:php<version>-<variant>             moves with each release
ghcr.io/se7enxweb/exponential-velocity:latest                             newest PHP, standard variant
```

The images were published as `ghcr.io/se7enxweb/qbix-webserver` until the
repository was renamed. Those tags stay where they are but get no new ones:
change the image name to `ghcr.io/se7enxweb/exponential-velocity` and keep the
same tag. Inside the image the server now lives in `/usr/share/exponential-velocity`,
and `/usr/share/qbix-webserver` is a link to it.

`<version>` is 8.2, 8.3, 8.4 or 8.5; `<variant>` is `mini`, `lite`, `standard` or
`full` ([binaries.md](binaries.md#variants-which-binary-to-download)). Every tag is
multi-architecture: Docker pulls the `amd64` or `arm64` image to match the host.
Pin a fixed tag in production, so an image only changes when you change the tag.

---

### Running it

```bash
docker run -d --name web -p 8080:8080 \
  -v "$PWD/public:/app" \
  ghcr.io/se7enxweb/exponential-velocity:php8.3-standard
```

The document root is `/app`; the image ships the welcome page there, which a
mounted directory replaces. The server listens on 8080 inside the container. Any
server option can be passed by replacing the command:

```bash
docker run -d -p 8080:8080 -v "$PWD:/srv/site" \
  ghcr.io/se7enxweb/exponential-velocity:php8.3-standard \
  qbixserver --root=/srv/site/public --port=8080 --workers=16
```

`qbixctl` and `qbixconsole` are on the PATH inside the image:

```bash
docker exec web qbixctl ext:check          # this image against its baseline
```

---

### What each variant carries

The same variants as the binaries, built for a dynamic PHP instead of a static
one. Two things follow from that:

- **ODBC is in** (`odbc`, `pdo_odbc`), with drivers ([below](#database-add-ons-oracle-firebird-odbc)):
  a static binary cannot load ODBC drivers at run time, an image can.
- **The `full` image is best effort for the extra tier.** Extensions outside the
  server, required and recommended tiers are built from PHP's sources or PECL, and
  one that does not build on a given PHP version is left out of that image rather
  than failing it. The image records exactly what it carries, and why anything was
  left out:

```bash
docker run --rm ghcr.io/se7enxweb/exponential-velocity:php8.3-full \
  cat /usr/share/exponential-velocity/image-extensions.txt
```

The server, required and recommended tiers are never best effort: an image that
misses any of them fails its build.

opcache is enabled for the command line, with the tracing JIT, since the server is
a long-lived CLI process (`$PHP_INI_DIR/conf.d/zz-qbix-opcache.ini`).

---

### Database add-ons: Oracle, Firebird, ODBC

The `standard` and `full` images also carry the drivers a static binary cannot:

| Add-on | Extensions | What it brings |
|---|---|---|
| Oracle | `oci8`, `pdo_oci` | Oracle Instant Client (basic + sdk) in `/opt/oracle/instantclient` |
| Firebird | `pdo_firebird` | The Firebird client library |
| ODBC drivers | for `odbc` and `pdo_odbc` | PostgreSQL, MariaDB/MySQL, SQLite, and FreeTDS for Microsoft SQL Server and Sybase |

On PHP 8.4 and later `oci8` and `pdo_oci` are no longer bundled with PHP and come
from PECL; an add-on that does not build on a PHP version is recorded in
`image-extensions.txt` like any other exception.

**Oracle Instant Client licensing.** Oracle distributes Instant Client under its own
license (at the time of writing the Oracle Free Use Terms and Conditions, which
permit redistribution). Read Oracle's current terms before you redistribute an
image that contains it. To build without it, set `WITH_ADDONS=0`
([Building your own](#building-your-own)).

Microsoft SQL Server through `sqlsrv`/`pdo_sqlsrv` needs Microsoft's ODBC driver,
which is not redistributable, so it is not in any image; FreeTDS over ODBC is.
[requirements.md](requirements.md) describes how to add `sqlsrv` yourself.

---

### Configuration

The server reads `/etc/qbix` inside the container, laid out as described in
[layout.md](layout.md). Mount a directory there, or pass settings on the command
line. Certificates and HTTPS: [https.md](https.md). A mounted directory needs to be
readable by the container's user (root in these images, unless you run them with
`--user`).

---

### Health check

Each image declares a health check that asks the server's `/Q/health` on port 8080
and expects `"status":"ok"`; `/Q/health` is always open to the machine itself.

```bash
docker inspect -f '{{.State.Health.Status}}' web
```

If you run the server on another port, set `QBIX_HEALTH_URL`
(e.g. `http://127.0.0.1:9000/Q/health`).

---

### Compose

```yaml
services:
  web:
    image: ghcr.io/se7enxweb/exponential-velocity:php8.3-standard
    ports:
      - "8080:8080"
    volumes:
      - ./public:/app:ro
    restart: unless-stopped
  db:
    image: mariadb:11
    environment:
      MARIADB_ROOT_PASSWORD: change-me
```

---

### Building your own

The images are built from `packaging/docker/Dockerfile` in the repository, from the
repository's root:

```bash
docker build -f packaging/docker/Dockerfile \
  --build-arg PHP_VERSION=8.3 --build-arg VARIANT=standard \
  -t my/exponential-velocity:php8.3-standard .
```

| Build argument | Default | Meaning |
|---|---|---|
| `PHP_VERSION` | `8.3` | The official `php:<version>-cli-bookworm` image to build on |
| `VARIANT` | `standard` | `mini`, `lite`, `standard` or `full` |
| `WITH_ADDONS` | `auto` | `1` or `0`: the Oracle, Firebird and ODBC add-ons. `auto` is 1 for standard and full |

The extension list is not in the Dockerfile: `packaging/docker/plan.php` asks the
baseline which extensions the variant carries on this PHP, and the build ends with
`qbixctl ext:check`, so an image never ships without something its variant
promises.
