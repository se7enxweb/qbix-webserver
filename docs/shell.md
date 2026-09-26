## Q Shell

Every server view under `/Q/` — the dashboard, the control panel, the
documentation, PHP info and the error pages — carries a drop-down console.
Press <kbd>`</kbd> or <kbd>~</kbd> to open and close it (not while typing
in a form field), or click **Shell** in the toolbar. It needs a signed-in
control panel session and is on by default.

```
qsh> uptime
qsh> workers -o pid,state,mem -H | sort -k3
qsh> get Q.shell.timeout
qsh> for n in 2 4 8; do workers resize $n -f; sleep 5; health; done
qsh> man jobs
```

### The terminal

- **The Shell item**: every server view's toolbar has a Shell pill, styled
  like the others. Clicking it (or `` ` ``) shows the console; while the
  console runs hidden the pill is dashed, and its dot shows the connection
  (green WebSocket, amber polling, orange reconnecting, red signed out).
  Signed out it is disabled and says to sign in to the Control Panel.
- **Window controls** in the title bar: `+` starts or shows the shell, `−`
  hides it (the session and its jobs keep running; `Esc` does the same),
  `m` maximises it to the full height and back (remembered in this
  browser), and `×` closes it, ending the session and its jobs. `×` lists
  any running jobs and asks before ending them.
- **Tabs and splits**: `Ctrl-Shift-T` opens a tab, `Ctrl-Shift-D` splits
  the pane; each pane is its own session with its own jobs (the history
  is shared).
- **Line editing**: Emacs keys (`Ctrl-A/E/K/U/W`, `Alt-B/F`), `↑/↓`
  history, `Ctrl-R` reverse search, `Tab` completion of commands, options,
  settings and aliases, `Ctrl-C` to interrupt, `Ctrl-L` to clear.
- **Themes**: `theme list`, `theme quake`. Themes are JSON files
  (`theme-<name>.json`) in a design's `shell/` directory, so a design or a
  provider can add its own.
- **Phones**: soft keys for Tab, Ctrl, the arrows and `|`; the console
  fills the screen.
- **Transport**: a WebSocket at `/Q/ws/shell`, falling back to HTTP
  polling when the socket cannot connect.

### The language

A zsh-like language with a real parser — nothing is handed to a shell as a
string:

| | |
|---|---|
| Lists | `a; b`, `a && b`, `a \|\| b`, `a \| b`, `a &` |
| Quoting | `'literal'`, `"with $vars"`, `\x` |
| Expansion | `$x`, `${x:-default}`, `$(command)`, `$((1 + 2))` (no eval), `a{1,2,3}` |
| Control | `if … then … elif … else … fi`, `for x in …; do … done`, `while`, `until`, `[ … ]` / `test` |
| History | `!!`, `!n`, `!-n`, `!prefix`, `^old^new`; `history`, `history N`, `history -a`, `history -c` |
| Scripts | `source name`, `exec name` from the shell's directory or `Q.shell.scriptsDir` |

Loops stop after 10 000 rounds. `man syntax`, `man quoting` and
`man expansion` have the details.

### Commands and output

Commands come from four places: the server's console commands (`server
status`, `cache clear`, `site enable` …), the shell's built-ins (`echo`,
`grep`, `sort`, `wc`, `alias`, `get`, `set` …), scripts in
`Q.shell.scriptsDir`, and **providers** — PHP classes implementing
`Q_WebServer_Shell_Provider`, registered with
`Q_WebServer_Shell::addProvider()`, which add commands and themes.

Listings follow zfs conventions:

| Option | Meaning |
|---|---|
| `-o a,b,c` | only these columns, in this order |
| `-H` | no header, tab-separated: for scripts |
| `-p` | exact numbers (bytes, milliseconds) instead of readable ones |
| `-j` | JSON |

Every command answers `--help`; `man <command>`, `man <topic>` and
`man -k <word>` (`apropos`) cover the rest. `man nouns` and `man verbs`
list the vocabulary.

### Settings

`get` and `set` work like a game console's variables:

```
qsh> get                      # every setting, with its source: default, config or runtime
qsh> set workers.count 8      # applies to the running server now
qsh> set -p workers.count 8   # and writes it to the config file (a .bak is kept)
```

The shell's own security settings (below) cannot be changed from the shell.

### Tiers

| Tier | May run |
|---|---|
| `basic` | read-only commands: status, health, logs, `get` |
| `expanded` | plus changes to the running server: cache, workers, sites, `set` |
| `advanced` | plus server control and, when allowed, OS commands |

`Q.shell.tier` sets the highest tier a session may use (default
`advanced`).

### Safety

- **Confirmation.** Commands that change the running server ask
  `proceed? [y/N]`; `-f` answers yes. In scripts and through the API,
  where nobody can answer, `-f` is required.
- **OS commands are off.** `! command` or `sys command` runs a program on
  the machine only when `Q.shell.allowSystem` is `true` (default `false`),
  and only in the advanced tier.
- **Threat commands need the password again.** Before an OS command that
  can do lasting damage — `rm`, `dd`, `mkfs`, `fdisk`, `shred`,
  `shutdown`, `kill`, `chmod -R`, `iptables`, `.`/`source`, `eval`,
  `sh -c`, `$(…)`, a download piped into a shell, writing into `/etc` or a
  disk, or a program name hidden behind quotes, backslashes or a variable
  — the shell says what the command can do and asks for the control panel
  password, as `sudo` does. A correct password is kept for
  `Q.shell.elevateMinutes` (default 5); `sudo -v` confirms it in advance.
  Wrong answers count towards the panel's sign-in lockout. Stopping,
  restarting the server and changing the panel password ask the same way.
  The check reads the line as text: it is there so nobody types these out
  of habit, not as a sandbox.
- **Never root.** Each command runs in its own process, as
  `Q.shell.user` — by default the owner of the document root, else
  `nobody`. When the server itself runs as root, the process switches to
  that user before it runs anything, and refuses to run at all if the user
  is missing or is root. The status bar shows *runs as &lt;user&gt;*.
- **sudo.** `sudo <command>` runs a single command as root only when
  `Q.shell.allowRoot` is `true` (default `false`), after the password
  check, and the status bar turns red while it runs.
- **Nothing of the server's.** A command gets none of the server's open
  sockets or files (only its own three pipes) and only the path, locale
  and `QBIX_*`/`VC_*` variables of its environment — no credentials or
  tokens the server was started with. It also drops root's supplementary
  groups.
- **Same origin only.** The WebSocket opens only from a page with the same
  scheme, host and port as the server — another port on the same host is
  another site, even though browsers send it the same cookies. Behind a
  proxy, list the public origin in `Q.shell.allowedOrigins`
  (`["https://example.com"]`).
- **A root-only data directory.** When the server runs as root, the panel's
  data directory (and the shell's beneath it) and every directory above it
  must be changeable by root alone; otherwise the shell says so, skips
  `autoexec.qsh`, and `sudo` will not run scripts from such a directory as
  root. A user who could replace that directory could set the panel
  password.
- **Limits.** Commands time out after `Q.shell.timeout` seconds (default
  120: TERM, then KILL); output is capped at `Q.shell.maxOutput` bytes
  (8 MB); a session holds at most `Q.shell.maxJobs` jobs (8).
- **Audit.** Every command — who, from where, the tier, the exit status and
  how long it took — is appended to `shell-audit.log` beside the panel's
  data, outside the directory the shell user can write. OS commands are
  also written to the server's error log. The dashboard's *Shell activity*
  card shows the latest.

### Jobs

`command &` runs in the background; `jobs`, `fg %n`, `bg %n`, `kill %n`
and `wait` manage them, as in bash. A job keeps running when the console
is hidden (`−` or `Esc`) and its output is waiting when it shows again;
closing it with `×` ends the session and its jobs, after asking.

### REST API

The same sessions over HTTP, under `/Q/api/shell/`. Send the panel session
token in `Authorization: Bearer <token>` or `X-Panel-Token`, and optionally
a `session` name to keep several apart (default `api`) — the cookie
alone is refused, so another site cannot drive the shell from a visitor's
browser.

| Method and path | |
|---|---|
| `GET session` | who, tier, user, whether OS commands and sudo are allowed |
| `DELETE session?session=<name>` | end that session and its jobs (the console's `×`) |
| `POST exec` | `{"command": "...", "force": false, "timeout": 30}` → `202` with a job id |
| `GET poll?since=<seq>` | messages since a sequence number (`next` in the reply) |
| `POST input`, `POST signal` | `{"job": "<id>", "data": "..."}`; `{"job": "<id>", "sig": "INT"}` |
| `GET jobs`, `GET jobs/<id>`, `DELETE jobs/<id>` | list, read, stop |
| `POST elevate` | `{"password": "..."}` before a threat command |
| `GET complete?line=...` | completion candidates |
| `GET history?limit=&before=` | a page of the history: `lines`, the number of the first (`first`), `total` |
| `GET history/search?q=&before=` | the newest entry before `before` holding `q` (`hit`: `n`, `c`, `t`) |
| `GET audit?limit=20` | the latest audit entries |

```
curl -s -H "Authorization: Bearer $TOKEN" -d '{"command":"uptime"}' https://host/Q/api/shell/exec
```

### History

The history lives in the shell's data directory (`<state dir>/shell/history`),
one entry per line in zsh's extended format, `: <unix time>:0;<command>`, the
newest last. It is only ever appended to, under a lock, so several sessions
add to it at once without losing a line. It keeps the newest
`Q.shell.historySize` entries (100 000 by default): the file may grow a tenth
past that, then it is cut back in one pass, so an add costs the same at ten
entries as at a hundred thousand. An older history file (one command per
line) is read as it is and given times on the next add.

Nothing reads the whole file to show the newest entries: they are read from
the end. The console loads the newest 1 000; `Up` past the oldest of them
fetches the 1 000 before, and so on back to the first. `Ctrl-R` searches the
loaded entries first, then asks the server, which searches the whole file
from the end (`Ctrl-R` again goes further back). A command runs with the
newest page too, and asks the server for older entries when it needs them:
`!5`, `!prefix`, `history 5000`, and `history | grep …`, which lists every
entry into a pipe. On the terminal, `history` shows the last 20; `history N`
the last N; `history -a` all; `history -c` clears it.

Measured with 150 000 entries: the newest page in about 1.5 ms, a count of a
file not seen before in about 5 ms, a search back to the oldest entry in
about 30 ms, an add in under 0.2 ms.

### Settings reference

| Setting | Default | |
|---|---|---|
| `Q.shell.enabled` | `true` | the console and the API |
| `Q.shell.tier` | `advanced` | highest tier a session may use |
| `Q.shell.allowSystem` | `false` | raw OS commands |
| `Q.shell.user` | document root owner | who commands run as |
| `Q.shell.allowRoot` | `false` | `sudo <command>` as root |
| `Q.shell.elevateMinutes` | `5` | how long a password check lasts |
| `Q.shell.timeout` | `120` | seconds per command |
| `Q.shell.maxOutput` | `8388608` | bytes of output per command |
| `Q.shell.historySize` | `100000` | history entries kept; `0` keeps every one |
| `Q.shell.maxJobs` | `8` | jobs per session |
| `Q.shell.scriptsDir` | none | directory of scripts the shell can run |
| `Q.shell.toggleKey` | `` ` `` | the key that opens the console |
| `Q.shell.allowedOrigins` | `[]` | more origins allowed to open the shell's WebSocket (a proxy's public URL) |

At a terminal, `php qshell.php` starts the same shell without a server, and
`php qshell.php -c 'health'` runs one line.
