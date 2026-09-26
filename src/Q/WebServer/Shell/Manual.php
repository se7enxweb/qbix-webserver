<?php
/**
 * @module Q
 */
/**
 * The shell's manual: a page for every command, every noun (the commands it
 * groups), every built-in and every subject -- the syntax, the keywords, the
 * tiers, jobs, keys, settings, themes -- with examples throughout.
 *
 *   man <command>      man server status, man get, man grep
 *   man <noun>         man server: every verb of it, with its synopsis
 *   man <subject>      man syntax, man if, man quoting, man jobs, man sudo ...
 *   man -k <word>      apropos: every page that mentions a word
 *   <command> --help   a command's usage, options and examples
 *
 * @class Q_WebServer_Shell_Manual
 * @static
 */
class Q_WebServer_Shell_Manual
{
	/** Examples, by command or built-in name. */
	const EXAMPLES = array(
		'server status' => array('server status', 'status', 'server:status --config=/etc/qbix/sites-enabled/example.conf'),
		'server reload' => array('server reload', 'graceful -f'),
		'server restart' => array('server restart', 'sudo server restart'),
		'server stop' => array('server stop'),
		'server configtest' => array('configtest'),
		'cache clear' => array('cache clear', 'cache clear -f'),
		'cache stats' => array('cache stats', 'cache stats -H -p', 'cache stats -j'),
		'site enable' => array('site enable example.com', 'ensite example.com'),
		'site disable' => array('site disable example.com -f'),
		'ext check' => array('ext check', 'ext check --variant=full', 'ext check --format=json'),
		'ext list' => array('ext list --variant=standard', 'ext list --variant=mini --format=spc'),
		'ext plan' => array('ext plan --variant=full --platform=windows-x64 --php=8.3'),
		'ext install-hint' => array('ext install-hint intl redis', 'ext install-hint oci8 --manager=apt'),
		'ssl show' => array('ssl show', 'ssl'),
		'ssl renew' => array('ssl renew'),
		'health' => array('health', 'health -o name,value | grep -i worker', 'health -j'),
		'uptime' => array('uptime'),
		'workers list' => array('workers list', 'workers list -H -o pid,state', 'workers list -j', 'workers list | grep busy | wc -l'),
		'workers resize' => array('workers resize 32', 'workers resize 32 -f'),
		'logs tail' => array('logs tail', 'logs tail error -n 50', 'logs tail | grep " 500 "'),
		'get' => array('get all', 'get workers,requestTimeout', 'get all -o name,value,source,applies', 'get all -H -p'),
		'set' => array('set workers=16', 'set requestTimeout=60', 'set workers=32 -p -f', 'set design=dark -p'),
		'grep' => array('workers list | grep busy', 'logs tail | grep -i -c error', 'help | grep -w cache'),
		'sort' => array('workers list -H | sort -k4 -n -r', 'history | sort -u'),
		'history' => array('history', 'history 50', '!!', '!3', '!-2', '!workers', '^16^32'),
		'alias' => array("alias ll='workers list -o pid,state,requests'", 'alias', 'unalias ll'),
		'echo' => array('echo hello', 'echo -e "a\\tb"', 'echo {1..3} $((6 * 7))'),
		'printf' => array('printf "%-10s %5d\\n" workers 16'),
		'for' => array('for s in alpha beta; do site enable $s -f; done'),
		'if' => array('if [ "$(workers list -H | wc -l)" -lt 4 ]; then set workers=4; fi'),
		'test' => array('[ -n "$USER" ] && echo set', 'test 3 -gt 2 && echo yes'),
		'time' => array('time ext check'),
		'repeat' => array('repeat 3 uptime'),
		'theme' => array('theme list', 'theme use dark', 'theme use quake'),
		'bind' => array('bind F2 workers list', 'bind ctrl+g get all', 'bind', 'bind -r F2'),
		'layout' => array('layout vertical', 'layout horizontal'),
		'exec' => array('exec daily.qsh', 'source checks.qsh alpha'),
		'sudo' => array('sudo -v', 'sudo server restart', 'sudo -k'),
		'sys' => array('sys uptime', '! df -h', 'sys ls -la /var/log'),
		'use' => array('use site example.com', 'use', 'use -'),
		'jobs' => array('ext check &', 'jobs', 'fg %1', 'kill %1', 'wait'),
		'type' => array('type status', 'type server restart', 'which ll'),
		'man' => array('man server', 'man workers list', 'man syntax', 'man -k cache'),
		'help' => array('help', 'help get', 'set --help'),
	);

	/** Subjects: name => array(title, text). */
	const TOPICS = array(
		'shell' => array('The Q shell', "A console for this server, opened with ` (or ~) on any of its own pages.\nIt reads like zsh and zfs: a line editor with history, completion and Emacs keys;\ncommands as noun verb [options] target; settings as get and set.\n\nStart with: help, man <command>, man syntax, man keys, man tiers.\nEvery page: man -k <word>."),
		'syntax' => array('Command line syntax', "cmd arg ...           a command and its arguments\na ; b                 one after the other\na && b                b only if a succeeded;   a || b   b only if a failed\na | b                 a's output is b's input (b can be grep, sort, head, ...)\n( list )              a subshell: variable changes stay inside\n{ list; }             a group\ncmd &                 run as a background job (see: man jobs)\nNAME=value            set a variable;  NAME=value cmd  only for that command\n\$NAME \${NAME:-def}   variables (see: man expansion)\n\$(cmd)                the output of cmd\n\$(( 1 + 2 ))         arithmetic\n{a,b} {1..5}          brace expansion\n'...' \"...\" \\\\c       quoting (see: man quoting)\ncmd <<< word          word as the command's input\n# comment             to the end of the line\n\nCompound commands: man if, man for, man while, man until."),
		'quoting' => array('Quoting', "'single quotes'    everything literal, no expansion\n\"double quotes\"    one word, with \$VAR, \$(cmd) and \$((..)) expanded\n\\\\c                 one character literal\n\nUnquoted \$VAR and \$(cmd) are split into words on white space; quoted ones are not.\nBraces {a,b} expand only when unquoted."),
		'expansion' => array('Expansion', "\$NAME \${NAME}         the variable's value\n\${NAME:-word}         word when NAME is unset or empty\n\${NAME:=word}         the same, and set NAME to it\n\${NAME:+word}         word when NAME is set and not empty\n\${NAME:?message}      an error when NAME is unset or empty\n\${#NAME}              its length\n\$? \$# \$@ \$1..\$9       last status, arguments of a sourced script\n\$(command)            the command's output, trailing newlines removed\n\$(( expr ))           integers: + - * / % ** == != < <= > >= && || ! ( )\n{a,b,c} {1..10} {a..e} {10..0..2}   brace expansion"),
		'variables' => array('Variables', "NAME=value sets a shell variable for this session; export NAME passes it to the\ncommands the shell runs; env lists them; unset NAME removes one.\nThe server's own environment is never shown to the shell.\n\nExamples:\n  site=example.com; site enable \$site\n  export LANG=C"),
		'pipes' => array('Pipes and filters', "a | b feeds a's output to b. The built-in filters work on any output:\n  grep sort uniq head tail wc cut tr tee less seq\n\nExamples:\n  workers list | grep busy | wc -l\n  logs tail -n 200 | grep ' 500 ' | cut -d' ' -f7 | sort | uniq -c | sort -rn | head\n  health | tee snapshot | grep -i rps"),
		'if' => array('if', "if list; then list; [elif list; then list;] [else list;] fi\n\nThe condition is the status of its last command (0 is true).\n\nExample:\n  if ext check; then echo ok; else ext install-hint; fi"),
		'for' => array('for', "for NAME in word ...; do list; done\nfor NAME; do list; done          (the arguments of a sourced script)\n\nExample:\n  for n in 1 2 3; do echo \"try \$n\"; done"),
		'while' => array('while', "while list; do list; done\n\nRuns while the condition succeeds (at most 10000 times per line).\n\nExample:\n  i=0; while [ \$i -lt 3 ]; do i=\$((i + 1)); echo \$i; done"),
		'until' => array('until', "until list; do list; done\n\nRuns until the condition succeeds."),
		'test' => array('test and [ ]', "test expr    [ expr ]\n  -z s  -n s          empty / not empty\n  a = b  a != b       strings\n  a -eq b -ne -lt -le -gt -ge   integers\n  ! expr   expr -a expr   expr -o expr"),
		'arithmetic' => array('Arithmetic', "\$(( expression )) with integers:\n  + - * / % **   == != < <= > >=   && || !   ( )\nNames are variables, with or without \$.\n\nExample:  echo \$(( (3 + 4) * 6 ))"),
		'history' => array('History', "Up/Down walk the history; Ctrl-R searches it; history lists it.\n  !!        the last line          !n    line n\n  !-n       n lines back           !abc  the latest line starting with abc\n  ^old^new  the last line with old replaced by new\nA line starting with a space is not recorded, nor a repeat of the last.\n  history      the last 20       history N   the last N\n  history -a   all of it         history -c  clear it\n  history | grep word            every entry, into a pipe\nUp past the oldest loaded line fetches older ones; Ctrl-R searches all of it.\nUp to Q.shell.historySize entries are kept (100000 by default)."),
		'aliases' => array('Aliases', "alias name='text' makes name run text (plus any arguments after it).\nAliases are kept per user. alias lists them; unalias name removes one.\n\nExample:  alias w='workers list -o pid,state,requests'"),
		'jobs' => array('Jobs', "cmd &        runs cmd as a background job; the prompt comes back at once\njobs         lists this session's jobs\nfg [%n]      brings a job's output to the front and waits for it\nbg [%n]      lets a stopped job carry on\nkill [-SIG] %n    signals a job (INT, TERM, KILL, HUP, STOP, CONT)\nwait [%n]    waits for jobs to finish\n\nCtrl-C cancels the foreground command (SIGINT, then SIGKILL after 2 s).\nForeground commands stop after Q.shell.timeout seconds (120); jobs do not."),
		'keys' => array('Keys', "`  or  ~          open / close the shell (Esc closes too)\nEnter              run the line\nTab                complete: commands, verbs, --options, settings, themes, files\nUp / Down          history          Ctrl-R    search the history\nCtrl-A / Ctrl-E    start / end      Ctrl-B / Ctrl-F, Alt-B / Alt-F   move\nCtrl-K / Ctrl-U    cut to end / to start    Ctrl-W / Alt-D   cut a word\nCtrl-Y             paste what was cut        Ctrl-L   clear\nCtrl-C             cancel           Ctrl-D   close (on an empty line)\nCtrl-Shift-T       new tab          Ctrl-Shift-D   split the pane\nCtrl-Shift-W       close the tab or pane\nbind <key> <cmd>   your own keys"),
		'tiers' => array('Tiers', "Every command has a tier:\n  basic      reads only: status, health, get, logs tail, ext check ...\n  expanded   changes the running server but keeps it up: cache clear, set,\n             site/conf/mod enable|disable, ssl renew, panel password ...\n  advanced   everything else, including the raw OS tier (sys, ! cmd) where\n             Q.shell.allowSystem is on\nQ.shell.tier caps what sessions may use; the API may lower it per call.\nCommands that change the running server ask \"proceed? [y/N]\" (-f skips);\ndangerous ones need the password again (see: man sudo)."),
		'sudo' => array('sudo and dangerous commands', "Some commands can do lasting damage: rm, dd, fdisk, mkfs, shred, shutdown,\nkill, userdel, chmod -R, iptables, . and source, eval, a download piped into a\nshell, \$(...) inside an OS command, writing to a disk -- and server stop,\nserver restart and panel password. The shell says what they can do and asks\nfor the control panel password again, as sudo does; it is then kept for\nQ.shell.elevateMinutes (5). Wrong passwords count towards the sign-in lockout.\n\n  sudo -v          confirm now\n  sudo <command>   confirm, then run\n  sudo -k          forget the confirmation\n\nThe habit worth having: almost never run these at all."),
		'settings' => array('Settings', "get all                     every setting: NAME VALUE SOURCE\nget workers,requestTimeout  some of them\nget all -o name,value,source,applies,description\nset name=value              change it in the running server (when it can)\nset name=value -p           ... and save it to the site configuration\nSOURCE is default, config (the site file) or runtime (changed since start).\nSecurity settings are shown but cannot be changed from the shell."),
		'themes' => array('Themes', "theme list          the installed themes\ntheme use <name>    switch (kept in this browser)\nThemes are plugins: a theme-<name>.json file in designs/<design>/shell/ of the\nserver or of its configuration tree, or added by a distribution. Keys:\nlabel, background, foreground, cursor, selection, prompt, accent, opacity,\nand the 16 colours black red green yellow blue magenta cyan white and\nbright* of each."),
		'scripting' => array('Scripts', "exec file / source file [args]  runs a file of shell commands from the\nshell's own directory or the scripts directory; inside, \$1.. are its\narguments. autoexec.qsh in the shell directory runs when a session opens.\nDisruptive commands in scripts must carry -f.\n\nExecutables in Q.shell.scriptsDir become commands; a header line\n# qshell-tier: basic|expanded|advanced sets the tier (advanced by default)."),
		'api' => array('The shell API', "The same commands over HTTP, for automation, with the control panel session\ntoken in an Authorization: Bearer or X-Panel-Token header:\n  POST   /Q/api/shell/exec   {\"command\": \"ext check\", \"force\": false, \"tier\": \"basic\"}\n  GET    /Q/api/shell/jobs/<id>      state, exit, duration, stdout, stderr\n  GET    /Q/api/shell/jobs           DELETE /Q/api/shell/jobs/<id>\n  GET    /Q/api/shell/poll?since=N   the terminal's message stream\n  POST   /Q/api/shell/elevate {\"password\": \"...\"}   for dangerous commands\nDisruptive commands need \"force\": true."),
		'completion' => array('Completion', "Tab completes the word under the cursor: commands and their verbs, console\nnames (server:status), aliases, --options of the command being typed, setting\nnames after get/set, themes after theme use, script files after exec. Several\nmatches show a menu: Tab or arrows to choose, Enter to take it, Esc to close."),
		'nouns' => array('Nouns', "Commands are noun verb, like zfs and zpool. The nouns:\n%NOUNS%\nman <noun> lists its verbs; man <noun> <verb> is one command."),
		'verbs' => array('Verbs', "The verbs, and the nouns that take them:\n%VERBS%"),
		'security' => array('Security', "Only a signed-in control panel session opens the shell (the panel's password,\nlockout and default-key change stand in front of it). Every command runs as a\nprocess of its own, never inside the server, and is recorded in the audit log\nwith who, where, what and how it ended. Tiers limit what runs; the raw OS\ntier is off unless Q.shell.allowSystem is on; dangerous commands need the\npassword again (man sudo). The HTTP API takes the token only from a header;\nthe WebSocket opens only from this server's own pages."),
	);

	/**
	 * The page for a name: a command, a noun, a built-in or a subject.
	 * @return {string|null}
	 */
	static function page($name, Q_WebServer_Shell_Registry $reg)
	{
		$name = trim(preg_replace('/\s+/', ' ', (string) $name));
		$spec = $reg->get($name);
		if ($spec) return $reg->manPage($spec) . self::examples($spec['name'], $spec);
		if (isset(Q_WebServer_Shell_Builtins::DOCS[$name])) {
			$d = Q_WebServer_Shell_Builtins::DOCS[$name];
			$topic = isset(self::TOPICS[$name]) ? "\n" . self::TOPICS[$name][1] . "\n" : '';
			return self::bold('NAME') . "\n    $name - {$d[1]}\n\n" . self::bold('SYNOPSIS') . "\n    " . trim($name . ' ' . $d[0]) . "\n"
				. $topic . "\n" . self::bold('TIER') . "\n    built-in (basic)\n" . self::examples($name);
		}
		if (isset(self::TOPICS[$name])) return self::topic($name, $reg);
		$verbs = self::verbsOf($name, $reg);
		if ($verbs) {
			$s = self::bold('NAME') . "\n    $name - its commands\n\n" . self::bold('COMMANDS') . "\n";
			foreach ($verbs as $v) {
				$s .= sprintf("    %-28s %s%s\n", trim($v['name'] . ' ' . $v['usage']), $v['description'],
					$v['tier'] !== 'basic' ? ' [' . $v['tier'] . ']' : '');
			}
			$ex = array();
			foreach ($verbs as $v) foreach (self::EXAMPLES[$v['name']] ?? array() as $e) $ex[] = $e;
			if ($ex) $s .= "\n" . self::bold('EXAMPLES') . "\n    " . implode("\n    ", array_slice($ex, 0, 8)) . "\n";
			return $s . "\nSee also: man " . $name . ' <verb>, man nouns, man tiers' . "\n";
		}
		return null;
	}

	/** A subject's page, with the lists that are made up on the spot. */
	static function topic($name, Q_WebServer_Shell_Registry $reg)
	{
		list($title, $text) = self::TOPICS[$name];
		if (strpos($text, '%NOUNS%') !== false || strpos($text, '%VERBS%') !== false) {
			$nouns = array();
			$verbs = array();
			foreach ($reg->all() as $spec) {
				$parts = explode(' ', $spec['name'], 2);
				$nouns[$parts[0]][] = $parts[1] ?? '';
				if (isset($parts[1])) $verbs[$parts[1]][] = $parts[0];
			}
			ksort($nouns); ksort($verbs);
			$n = '';
			foreach ($nouns as $k => $vs) $n .= sprintf("  %-12s %s\n", $k, implode(' ', array_filter($vs)) ?: '(a command of its own)');
			$v = '';
			foreach ($verbs as $k => $ns) $v .= sprintf("  %-14s %s\n", $k, implode(' ', $ns));
			$text = str_replace(array('%NOUNS%', '%VERBS%'), array(rtrim($n), rtrim($v)), $text);
		}
		return self::bold(strtoupper($title)) . "\n\n" . $text . "\n\nSee also: man -k <word>, help\n";
	}

	/** The EXAMPLES section: listed ones, or ones made from the options. */
	static function examples($name, array $spec = null)
	{
		$ex = self::EXAMPLES[$name] ?? array();
		if (!$ex && $spec) {
			$ex[] = $spec['name'];
			foreach (array_slice(array_keys((array) $spec['options']), 0, 2) as $o) {
				$d = $spec['options'][$o];
				$flag = is_array($d) && isset($d[1]) && $d[1] === false;
				$ex[] = $spec['name'] . ' ' . (strlen($o) === 1 ? '-' : '--') . $o . ($flag ? '' : '=' . strtoupper(preg_replace('/[^a-z]/', '', $o) ?: 'value'));
			}
		}
		if (!$ex) return '';
		return "\n" . self::bold('EXAMPLES') . "\n    " . implode("\n    ", $ex) . "\n";
	}

	/** The commands a noun groups. */
	static function verbsOf($noun, Q_WebServer_Shell_Registry $reg)
	{
		$out = array();
		foreach ($reg->all() as $spec) {
			if (strpos($spec['name'], $noun . ' ') === 0) $out[] = $spec;
		}
		return $out;
	}

	/**
	 * apropos: pages mentioning a word.
	 * @return {array} list of array(name, one line)
	 */
	static function search($word, Q_WebServer_Shell_Registry $reg)
	{
		$w = strtolower((string) $word);
		$hits = array();
		foreach ($reg->all() as $spec) {
			if (strpos(strtolower($spec['name'] . ' ' . $spec['description'] . ' ' . implode(' ', array_keys((array) $spec['options']))), $w) !== false) {
				$hits[$spec['name']] = $spec['description'];
			}
		}
		foreach (Q_WebServer_Shell_Builtins::DOCS as $n => $d) {
			if (strpos(strtolower($n . ' ' . $d[1]), $w) !== false) $hits[$n] = $d[1] . ' (built-in)';
		}
		foreach (self::TOPICS as $n => $t) {
			if (strpos(strtolower($n . ' ' . $t[0] . ' ' . $t[1]), $w) !== false) $hits[$n] = $t[0] . ' (subject)';
		}
		ksort($hits);
		$out = array();
		foreach ($hits as $n => $d) $out[] = array($n, $d);
		return $out;
	}

	private static function bold($s) { return "\033[1m" . $s . "\033[0m"; }
}
