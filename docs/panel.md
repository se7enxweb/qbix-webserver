## 🛂 Control Panel: signing in

The control panel is at **`/Q/panel`** on the server, e.g.
`https://example.com/Q/panel`.

**Default password: `panel`**

On a new installation, before anyone has chosen a password, the panel signs in
with the default password `panel`. It works once, for one purpose: the page then
shows nothing but a form to choose a real password, and every other panel action
is refused until you do. The sign-in page says so too.

- [First sign-in](#first-sign-in)
- [Choosing the password](#choosing-the-password)
- [Locking the default down](#locking-the-default-down)
- [Setting it without the page](#setting-it-without-the-page)
- [Forgotten password](#forgotten-password)
- [Settings](#settings)

---

### First sign-in

1. Open `/Q/panel`.
2. Sign in with `panel`.
3. Choose a new password (twice). It must pass the rules below.
4. You are signed in with the new password; `panel` no longer works, anywhere.

The default is never written to disk: it is simply what the panel accepts while
no password has been stored. The server's log records every sign-in with it as a
warning, so an unexpected one stands out.

---

### Choosing the password

At least 16 characters, not a common password, and none of `panel`, `qbix`,
`password`, `admin`, the server's name or the host name inside it. The full rules,
with examples that pass and fail, are in [passwords.md](passwords.md).
`qbixctl panel:password --generate` makes one that passes (below).

---

### Locking the default down

While no password has been chosen, anyone who reaches `/Q/panel` and knows the
default can sign in first and choose the password themselves. On a server
reachable from the internet, do one of these before it goes live:

| Setting | Effect |
|---|---|
| Choose the password straight away | The default stops working the moment a password is stored. |
| `Q.panel.defaultLocalOnly: true` | The default is accepted only from this machine (or with the dashboard token). |
| `Q.panel.defaultPassword: null` | No default at all. The first password is then set from this machine, with the dashboard token, or on the command line. |
| `Q.panel.defaultPassword: "something else"` | A default of your own, e.g. from a provisioning system. It is treated exactly like `panel`: one use, then a forced change. |

```json
{ "Q": { "panel": { "defaultLocalOnly": true } } }
```

---

### Setting it without the page

```sh
qbixctl panel:password --root=/path/to/web              # asks twice, without echo
qbixctl panel:password --root=/path/to/web --generate   # makes a strong one, prints it once
```

Use the same `--root` (or `--app`, `--conf-dir`, `--config`) the server runs with,
so the password lands where the server reads it. A running server uses it on the
next request. Setting a password this way also ends the default. More options in
[dashboard.md](dashboard.md#setting-the-password-from-the-command-line).

---

### Forgotten password

Set a new one on the command line as above; it replaces the old one and signs out
every session. If the panel says it is **locked**, its credential store failed the
ownership check: `qbixctl panel:check --root=...` shows which directory or file is
at fault and the commands that fix it (see
[Where the panel keeps its credentials](dashboard.md#where-the-panel-keeps-its-credentials)).

For a second factor after the password, see [2fa.md](2fa.md).

---

### Settings

All under `Q.panel`:

| Setting | Default | Meaning |
|---|---|---|
| `defaultPassword` | `"panel"` | The one-use default. `null` or `""` switches it off. |
| `defaultLocalOnly` | `false` | Accept the default only from this machine or with the dashboard token. |
| `remote` | `false` | Let any visitor reach the panel and, with the default switched off, set the first password. Leave it off on a public server. |

The rest -- bcrypt cost, lockout, sessions, where credentials are kept -- is in
[passwords.md](passwords.md) and [dashboard.md](dashboard.md).

---
[← Back to README](../README.md)
