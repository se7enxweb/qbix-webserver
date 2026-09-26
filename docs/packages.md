## 📦 OS Packages (deb and rpm)

Each release carries a package for Debian 12 and 13, Ubuntu 22.04 and 24.04, and
Enterprise Linux 9 and 10 (Rocky, AlmaLinux, RHEL). A package installs the server,
its console tools, a systemd unit and the configuration tree, and runs on the
distribution's own PHP -- whose extensions it pulls in as package dependencies.

- [Distributions](#distributions)
- [Installing](#installing)
- [Running the service](#running-the-service)
- [What goes where](#what-goes-where)
- [Dependencies and the extension baseline](#dependencies-and-the-extension-baseline)
- [Upgrading and removing](#upgrading-and-removing)

---

### Distributions

| Distribution | Package | PHP it uses |
|---|---|---|
| Debian 12 (bookworm) | `qbix-webserver_<release>-1+deb12_all.deb` | 8.2 |
| Debian 13 (trixie) | `qbix-webserver_<release>-1+deb13_all.deb` | 8.4 |
| Ubuntu 22.04 | `qbix-webserver_<release>-1+ubuntu22.04_all.deb` | 8.1 |
| Ubuntu 24.04 | `qbix-webserver_<release>-1+ubuntu24.04_all.deb` | 8.3 |
| EL 9 | `qbix-webserver-<release>-1.el9.noarch.rpm` | 8.2 (module stream) |
| EL 10 | `qbix-webserver-<release>-1.el10.noarch.rpm` | 8.4 |

The packages are architecture-independent: the server is PHP, so the same package
serves `x86_64` and `aarch64`. They are attached to each release, with the other
files, in `SHA256SUMS`.

---

### Installing

Debian and Ubuntu -- `apt` resolves the PHP packages it depends on:

```bash
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/download/v<release>/qbix-webserver_<release>-1+deb12_all.deb
sudo apt install ./qbix-webserver_<release>-1+deb12_all.deb
```

EL 10:

```bash
sudo dnf install ./qbix-webserver-<release>-1.el10.noarch.rpm
```

EL 9 ships PHP 8.0 by default and the server needs 8.1 or later, so enable a newer
PHP stream first:

```bash
sudo dnf module enable -y php:8.2
sudo dnf install ./qbix-webserver-<release>-1.el9.noarch.rpm
```

Some recommended extensions (`mongodb`, `redis`, `memcached`) come from EPEL or
Remi on EL; without those repositories they are simply not installed, and
`qbixctl ext:check` names them.

---

### Running the service

Nothing starts on install. When the configuration is ready:

```bash
sudo systemctl enable --now qbix-webserver
systemctl status qbix-webserver
sudo systemctl reload qbix-webserver     # graceful: finishes requests in flight
```

The service runs as the `qbix` system user (created on install), with
`/var/lib/qbix-webserver` as its working and state directory, and can bind ports
below 1024. Its settings are in `/etc/default/qbix-webserver`:

| Setting | Default | Meaning |
|---|---|---|
| `QBIX_ROOT` | `/usr/share/qbix-webserver/web` | The document root: the welcome page until you point it at your application |
| `QBIX_SITE` | `/etc/qbix/sites-enabled/default.conf` | The site file, whose settings sit on top of `qbix.conf` and `ports.conf` |
| `QBIX_OPTS` | (empty) | Any other `qbixserver` options, e.g. `--workers=16 --https-port=8443` |

The server listens on 8080 until `/etc/qbix/ports.conf` says otherwise.

---

### What goes where

```
/usr/share/qbix-webserver/          the server: phar, console tools, baseline, designs, docs
/usr/bin/qbixserver                 the server
/usr/bin/qbixctl                    control: start/stop/status, sites, ext:check ...
/usr/bin/qbixconsole                every console command
/etc/qbix/                          the configuration tree (layout.md)
  qbix.conf  ports.conf  envvars
  sites-available/default.conf      enabled by the symlink in sites-enabled/
  conf-*, mods-*, sites-*, designs/, ssl/
/etc/default/qbix-webserver         the service's settings
/lib/systemd/system/qbix-webserver.service   (/usr/lib/systemd/system on EL, Debian 13, Ubuntu 24.04)
/var/lib/qbix-webserver/            state, owned by qbix
```

Everything under `/etc` is configuration: an upgrade never overwrites a file you
changed. Enable and disable sites, snippets and modules with `qbixctl ensite`,
`dissite`, `enconf`, `disconf`, `enmod` and `dismod` ([layout.md](layout.md)).

---

### Dependencies and the extension baseline

A package depends on the distribution's PHP packages for the **lite** variant of
the extension baseline ([requirements.md](requirements.md)) -- everything a typical
application platform requires -- and recommends the rest of **standard**. `apt`
installs recommendations by default; `dnf` treats them as weak dependencies and
installs those it can find. Both lists come from the baseline in each
distribution's own package names, not from a list kept in the package.

After installing, ask the server what this PHP has and lacks:

```bash
qbixctl ext:check                      # the standard variant
qbixctl ext:check --variant=lite
```

It prints each missing extension with the exact command that installs it on this
system. A distribution that does not package an extension for its PHP version at
all is the one exception this form has; the check names it.

---

### Upgrading and removing

Install the new release's package over the old one (`apt install ./...`,
`dnf install ./...`), then `systemctl restart qbix-webserver`.

Removing the package stops and disables the service. It leaves `/etc/qbix`,
`/etc/default/qbix-webserver`, `/var/lib/qbix-webserver` and the `qbix` user in
place, for you to remove when you are sure (`apt purge` also removes the
configuration files on Debian and Ubuntu).
