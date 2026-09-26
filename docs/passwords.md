## 🔑 Panel Passwords

The control panel (`/Q/panel`) is the one page on this server that changes it:
workers, apps, scripts, the pool size. Its password is therefore held to strict
rules, stored the strongest way PHP offers, and guarded against guessing. This page
says exactly how, so an operator can choose one that passes the first time.

- [How passwords are stored](#how-passwords-are-stored)
- [The rules](#the-rules)
- [Settings](#settings)
- [The default key, and changing it on first sign-in](#the-default-key-and-changing-it-on-first-sign-in)
- [Lockout](#lockout)
- [From the command line](#from-the-command-line)

---

### How passwords are stored

- **bcrypt**, through `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`,
  checked with `password_verify()`. Nothing is ever compared with `==`.
- **The cost** is `Q.panel.bcryptCost`, `12` by default, held between `10` and `15`.
  Each step doubles the time a guess takes, and the time a sign-in takes: at `12` a
  sign-in costs a noticeable fraction of a second of one core.
- **Rehashing.** When the cost changes, the stored hash is replaced with one at the
  new cost the next time the password signs in (`password_needs_rehash()`). An older
  format -- an unsalted SHA-256 or SHA-1 hex digest -- is accepted once, at sign-in,
  and replaced with bcrypt at that moment.
- **72 bytes.** bcrypt reads only the first 72 bytes of a password; anything after
  them is ignored. So nothing longer is ever accepted, as a new password or at
  sign-in, rather than silently cut. (Most characters are one byte; an accented
  letter is two, most other scripts three.)
- **Where.** `acl/panel.json`: under the configuration tree (`/etc/qbix/acl`) when
  the server uses one, else `local/panel.json` above the document root. Written
  `0600`, in a `0700` directory that must pass the panel's ownership rule up to `/`,
  or the panel refuses every sign-in -- see
  [dashboard.md](dashboard.md#where-the-panel-keeps-its-credentials).

### The rules

Every way of setting a password checks the same rules, in one place
(`Q_WebServer_Panel_PasswordPolicy`): the page's change form, the first-time setup,
the forced change of the default key, and `qbixctl panel:password`. The change form
ticks them off as you type; the server has the last word, and lists every rule a
password breaks.

| Rule | Passes | Fails |
|---|---|---|
| At least 16 characters | `Vx7#qLm2!Rt9@Kw4` | `Vx7#qLm2!Rt9@Kw` (15) |
| No more than 72 bytes | 16 to 72 ASCII characters | 80 ASCII characters |
| An uppercase letter | `Vx7#qLm2!Rt9@Kw4` | `vx7#qlm2!rt9@kw4` |
| A lowercase letter | `Vx7#qLm2!Rt9@Kw4` | `VX7#QLM2!RT9@KW4` |
| A digit | `Vx7#qLm2!Rt9@Kw4` | `Vxh#qLmz!Rtj@Kwp` |
| A symbol | `Vx7#qLm2!Rt9@Kw4` | `Vx7kqLm2cRt9dKw4` |
| At least 10 different characters | `Vx7#qLm2!Rt9@Kw4` | `Ab1!Ab1!Ab1!Ab1!Ab1!Cd` |
| No letter three times in a row (digits, symbols and separators may repeat) | `app-cp-alpha-demo-2999-VC-7X-$0*#(&0);[0]` | `Vx7#qLm2!Rt9@Kwww4` |
| No run of four in order, either way | `Vx7#qLm2!Rt9@Kw4` | `Vx7#abcdL2!Rt9@K`, `Vx#qL4321m!Rt@Kw`, `Vx7#QwerL2!t9@K4` |
| None of: the default key, `qbix`, `password`, `admin`, the server's name, the full host name | `Vx7#qLm2!Rt9@Kw4` | `Vx7#PaNeL2!Rt9@K`, `Vx7#AdMiN2!Rt9@K` |
| Not a common password | `Vx7#qLm2!Rt9@Kw4` | `Sunshine!!2024##` |
| At least 80 bits of estimated strength | `Vx7#qLm2!Rt9@Kw4` (about 105) | anything short or drawn from one or two kinds of character |
| Different from the current password | a new one | the one already set |

How they are read:

- **Classes.** A letter outside ASCII counts as its case (`É` is uppercase); one with
  no case, such as a CJK character, counts as a symbol.
- **Runs** are along the alphabet, the digits and the keyboard rows (`qwertyuiop`,
  `asdfghjkl`, `zxcvbnm`), forwards or backwards, whatever the case.
- **Words** are matched anywhere in the password, whatever the case. The host name
  is the whole name the page was opened with (a word that only matches part of it,
  such as `alpha` in `alpha.example.com`, is fine); the command line does not know
  it, so it is checked in the page only.
- **Common passwords** are a list of about a thousand, shipped with the server
  (`src/Q/WebServer/Panel/common-passwords.txt`). A password is refused when it is
  one of them, or becomes one once digits and symbols at the start and end are taken
  off: `Sunshine!!2024##` is `sunshine`.
- **Strength** is estimated as log₂(the size of the character pool it draws on) ×
  its length: 26 each for lower and upper case, 10 for digits, 33 for symbols.

### Settings

Under `Q.panel`:

| Setting | Default | Meaning |
|---|---|---|
| `bcryptCost` | `12` | bcrypt cost, held between 10 and 15. |
| `passwordMinLength` | `16` | Minimum characters. May be raised, never lowered. |
| `passwordMinDistinct` | `10` | Minimum different characters. May be raised, never lowered. |
| `passwordMinBits` | `80` | Minimum estimated strength in bits. May be raised, never lowered. |
| `defaultPassword` | `"panel"` | The default key; `null` or `""` switches it off. |
| `defaultLocalOnly` | `false` | Accept the default key only from this machine or with the dashboard token. |

A minimum set below its floor is ignored, and the server logs a warning saying so.

### The default key, and changing it on first sign-in

Until a password is chosen, the panel signs in with the default key, `panel` -- from
anywhere, so a server can be set up from outside. The sign-in page says so. The
session it gives can do only one thing: change the password. Every other API call is
refused with `403 {"error":"change the default password first","mustChange":true}`
until the new password is accepted, and the page shows nothing but the change form.
The new password must pass every rule above, which the default key itself of course
does not.

**While the default is unchanged, anyone who knows it can sign in first** and choose
the password. Change it as soon as the server is up, set one beforehand with
`qbixctl panel:password`, or set `Q.panel.defaultLocalOnly` so the default works only
from the server itself. With `Q.panel.defaultPassword` set to `null` the default is
off altogether: the first password is then set from this machine, with the dashboard
token, or on the command line.

The default is never written to disk. Every sign-in with it is logged as a warning,
with the address it came from.

This is built on an observer pattern like the certificate subsystem's:
`Q_WebServer_Panel_Events` notifies `login.attempt`, `login.succeeded`,
`login.failed`, `password.changed` and `session.request`. The default-key observer
marks a default sign-in must-change and vetoes its requests; the lockout observer
counts failures; the log observer writes a line for each event.

### Lockout

Five failed sign-ins from one address lock it out for 60 seconds. Each lockout after
that doubles -- 120, 240, and so on -- up to an hour. A successful sign-in clears the
address's record. A locked-out sign-in gets `429` and the seconds left, and every
lockout is logged as a warning. The record is kept in the server's memory; a restart
clears it.

### From the command line

```sh
qbixctl panel:password --root=/srv/site/web            # asks twice, without echo
qbixctl panel:password --root=/srv/site/web --generate # makes one, sets it, prints it once
echo 'your password' | qbixctl panel:password --root=/srv/site/web
```

Pass the same `--root` the server runs with (or `--app=DIR`), and its
`--conf-dir`/`--config` if it has them; the password lands in the panel's
`acl/panel.json` (`qbixctl panel:check` shows where that is). A password that breaks a rule is refused
with the list of rules it breaks and a non-zero exit. `--generate` makes a 24-character
password from a cryptographically secure source that passes every rule, sets it, and
prints it once. Either way the password is stored as a chosen one: the default key
stops working, every existing session ends, and a running server uses it on the next
request.

### A second factor

The panel can also require a **time-based one-time code** (TOTP) after the
password. It is off by default and enforced only once enrolled and switched on
with `Q.panel.twofactor`. See [2fa.md](2fa.md).

---
[← Back to README](../README.md)
