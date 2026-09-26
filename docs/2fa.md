## 🔐 Two-Factor Authentication (TOTP)

The control panel (`/Q/panel`) can ask for a second factor after the password:
a time-based one-time code (TOTP, RFC 6238) from an authenticator app, the same
kind Google Authenticator, 1Password, Authy and the rest generate. It is **off
by default** and turns on only when an operator both enrolls a device and sets a
config flag, so nothing about sign-in changes until you ask for it.

- [The short version](#the-short-version)
- [The config flag](#the-config-flag)
- [Enrolling from the panel](#enrolling-from-the-panel)
- [Signing in with a code](#signing-in-with-a-code)
- [Recovery codes](#recovery-codes)
- [How it is stored](#how-it-is-stored)
- [Replay and lockout](#replay-and-lockout)
- [From the command line](#from-the-command-line)
- [If you lose your authenticator](#if-you-lose-your-authenticator)

---

### The short version

1. Sign in to the panel as usual, open **Security**, and set up two-factor: scan
   the QR / enter the secret in your app, then confirm with a code.
2. Save the recovery codes it shows once.
3. When ready, an operator turns enforcement on with `Q.panel.twofactor`.
4. From then on, sign-in is password **then** a code.

### The config flag

- **`Q.panel.twofactor`** — a boolean, **`false`** by default. It is a security
  setting: it is shown by `get all` in the shell but, like the other security
  settings, cannot be changed from the shell — set it in the site configuration
  file (or `--config`) and restart.
- **`Q.panel.twofactorWindow`** — how many 30-second steps of clock skew a code
  may be out, on each side. `1` by default (so the step before and after the
  current one are accepted); `0` is exact-only.

When the flag is **off**, sign-in is password-only and byte-for-byte as it was —
even if a secret has been enrolled. Enforcement begins only when the flag is
**on** *and* a secret is enrolled and enabled. This lets you enroll and test a
device first, then switch enforcement on with no window where nobody can get in.

### Enrolling from the panel

In **Security → Two-Factor Authentication**:

1. **Set up two-factor.** The panel shows a secret (base32) and an `otpauth://`
   URI. Add either to your authenticator app.
2. **Confirm** with a 6-digit code from the app. This proves the app and the
   server agree before two-factor can lock anyone out.
3. The panel then shows your **recovery codes, once.** Save them.

The secret and the recovery codes are shown **only** in these enrollment
answers, to you, the signed-in admin who asked for them. They are never logged,
never returned again, and never put in an error message.

### Signing in with a code

With enforcement on, a correct password no longer opens the panel by itself: it
gives a **pending** session that can do nothing but present a code or sign out.
The page then asks for the 6-digit code (with a "use a recovery code instead"
link). A valid code — or a recovery code — completes the sign-in and opens the
panel. A bookmarked deep link still works: after the code, you land where you
were headed.

### Recovery codes

- Ten codes, each usable **once**. Use one in place of an authenticator code
  when you don't have the app to hand.
- **Regenerate** them from **Security** (needs a current code or your password);
  the old set stops working the moment a new one is made.
- Only their **hashes** are stored, so the store file never reveals a code.

### How it is stored

The second factor lives in the panel's locked credential store,
`acl/panel.json`, beside the password hash — the same root-owned `0600` file, in
the same `0700` directory, behind the same ownership rule that must hold up to
`/` or the panel refuses every sign-in (see
[passwords.md](passwords.md#how-passwords-are-stored) and
[dashboard.md](dashboard.md)). It holds:

- the **secret**, which TOTP needs in the clear to check a code (as every TOTP
  server does) — protected the same way the password hash is;
- the **hashes** of the recovery codes (SHA-256), never their plaintext;
- **`lastStep`**, the newest code-step already accepted, for replay protection.

None of these ever leaves the machine except the secret and recovery codes in
the one-time enrollment views described above.

### Replay and lockout

- **Replay.** A code is tied to a 30-second step. Once a step is accepted, that
  step and every earlier one are refused, so a code seen on the wire cannot be
  used again even within its 30 seconds.
- **Lockout.** A wrong or replayed code feeds the **same** lockout as a wrong
  password: five failures from one address lock it out for 60 seconds, doubling
  up to an hour (see [passwords.md](passwords.md#lockout)). A completed sign-in
  clears the record.

### From the command line

`qbixctl panel:2fa` manages the second factor on the box (root/local, since it
reads and writes the store directly):

```sh
qbixctl panel:2fa status  --root=/srv/site/web   # enrolled? enforced? codes left?
qbixctl panel:2fa enroll  --root=/srv/site/web   # make a secret, print it + otpauth
qbixctl panel:2fa confirm --root=/srv/site/web --code=123456   # enable, print recovery codes
qbixctl panel:2fa recovery --root=/srv/site/web  # new recovery codes (old set void)
qbixctl panel:2fa disable --root=/srv/site/web   # turn it off
```

Pass the same `--root` (or `--app=DIR`) and `--conf-dir`/`--config` the server
runs with, so the CLI writes the store the server reads.

### If you lose your authenticator

Any one of these gets you back in:

- a **recovery code**, at the sign-in prompt;
- on the box, **`qbixctl panel:2fa disable`**, which turns the second factor off
  with no code (whoever can run it already has the store), then re-enroll;
- or turn enforcement off with `Q.panel.twofactor` and restart.

---
[← Back to README](../README.md)
