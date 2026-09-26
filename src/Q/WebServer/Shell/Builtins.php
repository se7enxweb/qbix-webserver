<?php
/**
 * @module Q
 */
/**
 * The shell's built-in commands: the shell itself (help, man, history,
 * alias, echo, printf, test, type, env, export, use, time, repeat, sleep,
 * exec, source, exit, clear), the terminal (theme, bind, layout), settings
 * (get, set, unset) and the filters that work on output (grep, sort, uniq,
 * head, tail, wc, cut, tr, tee, less, seq). All built-ins are basic tier;
 * what they change is guarded where it is changed (set asks, -p persists).
 *
 * @class Q_WebServer_Shell_Builtins
 */
class Q_WebServer_Shell_Builtins
{
	/** name => array(usage, description) */
	const DOCS = array(
		'help' => array('[command]', 'This overview, or one command\'s usage'),
		'man' => array('<command> | <noun> | <subject> | -k <word>', 'Manual pages: commands, nouns, built-ins and subjects (man shell to begin)'),
		'apropos' => array('<word>', 'Every manual page that mentions a word (the same as man -k)'),
		'history' => array('[-c | -a | count]', 'Recent commands (20; all of them into a pipe or with -a); -c clears. Recall with !!, !n, !-n, !prefix, ^old^new'),
		'alias' => array('[name[=text]]', 'List aliases, show one, or define one (kept per user)'),
		'unalias' => array('<name>', 'Remove an alias'),
		'get' => array('[all|name,...] [-H] [-p] [-j] [-o name,value,source,applies]', 'Server settings, zfs-style, with where each value comes from'),
		'set' => array('name=value ... [-p] [-f]', 'Change a setting now; -p also saves it to the site configuration'),
		'unset' => array('<variable>', 'Remove a shell variable'),
		'export' => array('[NAME[=value]]', 'Pass a variable to the commands the shell runs'),
		'env' => array('', 'The shell variables'),
		'echo' => array('[-n] [-e] [text...]', 'Print text; -e reads \\n \\t \\e escapes'),
		'printf' => array('<format> [args...]', 'Formatted output: %s %d %i %f %x %o %c %%'),
		'test' => array('<expression>', 'Also [ expression ]: -z -n = != -eq -ne -lt -le -gt -ge ! -a -o'),
		'[' => array('<expression> ]', 'The same as test'),
		'type' => array('<name>', 'What a name runs: built-in, alias or command (and its tier)'),
		'which' => array('<name>', 'The same as type'),
		'use' => array('[site <name> | -]', 'Set the context later commands default to (use - clears it)'),
		'time' => array('<command...>', 'Run a command and say how long it took'),
		'repeat' => array('<count> <command...>', 'Run a command several times (at most 1000)'),
		'sleep' => array('<seconds>', 'Wait (at most 60 seconds)'),
		'exec' => array('<file> [args...]', 'Run a file of shell commands from the shell or scripts directory'),
		'source' => array('<file> [args...]', 'The same as exec'),
		'theme' => array('[list | use <name> | load <name>]', 'Colour themes: list them, or switch (themes are plugins: theme-<name>.json files)'),
		'bind' => array('[<key> <command...> | -r <key>]', 'Bind a key (e.g. F2, ctrl+g) to a command; with no arguments, list the bindings'),
		'layout' => array('vertical|horizontal', 'Put the shell tabs down the side (vertical) or along the top'),
		'clear' => array('', 'Clear the screen (also Ctrl-L)'),
		'exit' => array('', 'Hide the shell (also Esc or `)'),
		'true' => array('', 'Succeed'),
		'false' => array('', 'Fail'),
		'grep' => array('[-i] [-v] [-c] [-n] [-o] [-w] [-F] <pattern>', 'Lines of the input that match a regular expression'),
		'sort' => array('[-r] [-n] [-u] [-k field] [-t sep]', 'Sort the input\'s lines'),
		'uniq' => array('[-c] [-d]', 'Collapse repeated lines'),
		'head' => array('[-n count]', 'The first lines of the input (10)'),
		'tail' => array('[-n count]', 'The last lines of the input (10)'),
		'wc' => array('[-l] [-w] [-c]', 'Count lines, words and bytes'),
		'cut' => array('-f list [-d sep] | -c list', 'Pick fields or characters of each line'),
		'tr' => array('[-d] <set1> [set2]', 'Translate or delete characters (ranges like a-z)'),
		'tee' => array('<variable>', 'Pass the input through and keep a copy in a variable'),
		'less' => array('', 'Show the input in a scrollable pager block'),
		'seq' => array('[first [step]] last', 'Numbers from first to last'),
		'jobs' => array('', 'Background jobs (answered by the server: run it on a line of its own)'),
		'fg' => array('[%n]', 'Bring a job\'s output to the front'),
		'bg' => array('[%n]', 'Let a stopped job carry on'),
		'kill' => array('[-SIGNAL] %n', 'Signal a job'),
		'wait' => array('[%n]', 'Wait for background jobs to finish'),
		'sudo' => array('[-v | -k | <command...>]', 'Confirm the control panel password for dangerous commands (kept a few minutes); -v confirms now, -k forgets'),
		'sys' => array('<command...>', 'Run an OS command with /bin/sh (advanced; only where Q.shell.allowSystem is on). Also: ! command'),
	);

	/** Answered by the server itself; here only to be documented and refused politely. */
	const SERVER_SIDE = array('jobs', 'fg', 'bg', 'kill', 'wait');

	private $shell;

	function __construct(Q_WebServer_Shell_Interpreter $shell)
	{
		$this->shell = $shell;
	}

	function has($name)
	{
		return isset(self::DOCS[$name]) && $name !== 'sys';
	}

	/** Run a built-in. */
	function run($name, array $args, $stdin, Q_WebServer_Shell_Sink $sink)
	{
		if (in_array($name, self::SERVER_SIDE, true)) {
			$sink->error('qsh: ' . $name . " is answered by the server; run it on a line of its own\n");
			return 2;
		}
		$method = 'b_' . str_replace('[', 'bracket', $name);
		return (int) $this->$method($args, (string) $stdin, $sink);
	}

	private function ctx($key, $default = null)
	{
		return $this->shell->registry->ctx[$key] ?? $default;
	}

	// ── The shell ───────────────────────────────────────────────────────

	private function b_help(array $a, $in, $sink)
	{
		$reg = $this->shell->registry;
		if ($a) {
			$name = implode(' ', $a);
			if (isset(self::DOCS[$a[0]])) {
				$sink->write(trim($a[0] . ' ' . self::DOCS[$a[0]][0]) . "\n    " . self::DOCS[$a[0]][1] . "\n" . Q_WebServer_Shell_Manual::examples($a[0]));
				return 0;
			}
			$spec = $reg->get($name) ?: $reg->get($a[0]);
			if (!$spec) { $sink->error("help: no command named \"$name\"\n"); return 1; }
			$sink->write($reg->usageLine($spec) . "\n");
			foreach ($spec['options'] as $o => $d) {
				$sink->write(sprintf("    %-22s %s\n", (strlen($o) === 1 ? '-' : '--') . $o, is_array($d) ? $d[0] : $d));
			}
			$sink->write('    tier: ' . $spec['tier'] . (!empty($spec['elevate']) ? ' (needs the password again: man sudo)' : (!empty($spec['disruptive']) ? ' (asks before it runs; -f skips)' : '')) . "\n");
			$sink->write(Q_WebServer_Shell_Manual::examples($spec['name'], $spec));
			return 0;
		}
		$s = "\033[1mQ shell\033[0m -- zsh-style line editing, zfs-style commands. `help <command>`, `man <command>`.\n\n";
		$s .= "\033[1mCommands\033[0m  (basic = read-only, \033[33mexpanded\033[0m = changes the server, \033[31madvanced\033[0m = everything else)\n";
		$groups = array();
		$singles = array();
		foreach ($reg->all() as $spec) {
			if (strpos($spec['name'], ' ') === false) { $singles[] = $spec; continue; }
			$noun = explode(' ', $spec['name'])[0];
			$groups[$noun][] = $spec;
		}
		ksort($groups);
		$paint = function ($spec, $label) {
			$color = $spec['tier'] === 'advanced' ? "\033[31m" : ($spec['tier'] === 'expanded' ? "\033[33m" : '');
			return $color . $label . ($color ? "\033[0m" : '');
		};
		foreach ($groups as $noun => $specs) {
			$names = array();
			foreach ($specs as $spec) $names[] = $paint($spec, substr($spec['name'], strlen($noun) + 1));
			$s .= sprintf("  %-10s %s\n", $noun, implode('  ', $names));
		}
		if ($singles) {
			$names = array();
			foreach ($singles as $spec) $names[] = $paint($spec, $spec['name']);
			$s .= sprintf("  %-10s %s\n", '', implode('  ', $names));
		}
		$s .= "\n\033[1mBuilt-ins\033[0m\n  " . wordwrap(implode('  ', array_keys(self::DOCS)), 76, "\n  ") . "\n";
		$s .= "\n\033[1mScripting\033[0m  a && b, a || b, a; b, a | b, ( list ), { list; }, NAME=value, \$NAME, \${NAME:-default},\n";
		$s .= "  \$(command), \$((1 + 2)), {a,b} {1..5}, 'quotes' \"quotes\" \\\\, for/while/until/if, <<< word, cmd &\n";
		$s .= "\n\033[1mKeys\033[0m  Tab complete, Up/Down history, Ctrl-R search, Ctrl-C cancel, Ctrl-L clear, Ctrl-A/E/K/U/W/Y,\n";
		$s .= "  Alt-B/F/D, Ctrl-Shift-T new tab, Ctrl-Shift-D split, Ctrl-Shift-W close, Esc or ` hides the shell\n";
		$sink->write($s);
		return 0;
	}

	private function b_man(array $a, $in, $sink)
	{
		$reg = $this->shell->registry;
		if (!$a) { $sink->error("man: which page? e.g. man server, man get, man syntax, man -k cache\n"); return 1; }
		if ($a[0] === '-k') return $this->b_apropos(array_slice($a, 1), $in, $sink);
		$page = Q_WebServer_Shell_Manual::page(implode(' ', $a), $reg);
		if ($page === null) {
			$hits = Q_WebServer_Shell_Manual::search($a[0], $reg);
			$sink->error('man: no page for ' . implode(' ', $a) . ($hits ? '; try: man ' . $hits[0][0] : '; try: man -k ' . $a[0]) . "\n");
			return 1;
		}
		$this->shell->io->ctl(array('op' => 'pager'));
		$sink->write($page);
		return 0;
	}

	private function b_apropos(array $a, $in, $sink)
	{
		if (!$a) { $sink->error("apropos: a word to look for\n"); return 1; }
		$hits = Q_WebServer_Shell_Manual::search($a[0], $this->shell->registry);
		foreach ($hits as $h) $sink->write(sprintf("%-26s - %s\n", $h[0], $h[1]));
		if (!$hits) { $sink->error('apropos: nothing about ' . $a[0] . "\n"); return 1; }
		return 0;
	}

	private function b_history(array $a, $in, $sink)
	{
		$h = $this->shell->history;
		if ($a && $a[0] === '-c') { $h->clear(); return 0; }
		if ($a && $a[0] !== '-a' && !ctype_digit($a[0])) { $sink->error("history: usage: history [-c | -a | count]\n"); return 2; }
		// The last 20 on the terminal; everything when asked, or when the
		// output goes on to grep, sort or the like.
		$total = $h->count();
		if ($a && ctype_digit($a[0])) $count = (int) $a[0];
		elseif (($a && $a[0] === '-a') || $sink->captured()) $count = $total;
		else $count = 20;
		$from = max(1, $total - $count + 1);
		for ($b = $from; $b <= $total; $b += Q_WebServer_Shell_History::PAGE_MAX) {
			$k = min(Q_WebServer_Shell_History::PAGE_MAX, $total - $b + 1);
			foreach ($h->page($k, $b + $k) as $e) {
				if (!$sink->write(sprintf("%5d  %s\n", $e['n'], $e['c']))) return 0;
			}
		}
		return 0;
	}

	private function b_alias(array $a, $in, $sink)
	{
		$h = $this->shell->history;
		if (!$a) {
			foreach ($h->aliases() as $k => $v) $sink->write($k . '=' . Q_WebServer_Shell_Interpreter::quote($v) . "\n");
			return 0;
		}
		$status = 0;
		foreach ($a as $arg) {
			if (preg_match('/^([A-Za-z0-9_.:-]+)=(.*)$/s', $arg, $m)) {
				if (isset(self::DOCS[$m[1]])) { $sink->error("alias: {$m[1]} is a built-in\n"); $status = 1; continue; }
				$h->setAlias($m[1], $m[2]);
			} else {
				$al = $h->aliases();
				if (isset($al[$arg])) $sink->write($arg . '=' . Q_WebServer_Shell_Interpreter::quote($al[$arg]) . "\n");
				else { $sink->error("alias: $arg: not found\n"); $status = 1; }
			}
		}
		return $status;
	}

	private function b_unalias(array $a, $in, $sink)
	{
		foreach ($a as $name) $this->shell->history->setAlias($name, null);
		return 0;
	}

	private function b_export(array $a, $in, $sink)
	{
		if (!$a) {
			foreach (array_keys($this->shell->exported) as $k) $sink->write('export ' . $k . '=' . Q_WebServer_Shell_Interpreter::quote($this->shell->vars[$k] ?? '') . "\n");
			return 0;
		}
		foreach ($a as $arg) {
			if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:=(.*))?$/s', $arg, $m)) { $sink->error("export: bad name: $arg\n"); return 1; }
			if (isset($m[2])) $this->shell->vars[$m[1]] = $m[2];
			$this->shell->exported[$m[1]] = true;
		}
		return 0;
	}

	private function b_env(array $a, $in, $sink)
	{
		$vars = $this->shell->vars;
		ksort($vars);
		foreach ($vars as $k => $v) $sink->write($k . '=' . $v . "\n");
		return 0;
	}

	private function b_unset(array $a, $in, $sink)
	{
		foreach ($a as $name) {
			if (isset(Q_WebServer_Shell_Settings::TABLE[$name])) {
				$sink->error("unset: $name is a setting; use: set $name=<value> -p\n");
				return 1;
			}
			unset($this->shell->vars[$name], $this->shell->exported[$name]);
		}
		return 0;
	}

	private function b_echo(array $a, $in, $sink)
	{
		$newline = true;
		$escapes = false;
		while ($a && preg_match('/^-[neE]+$/', $a[0])) {
			$f = array_shift($a);
			if (strpos($f, 'n') !== false) $newline = false;
			if (strpos($f, 'e') !== false) $escapes = true;
			if (strpos($f, 'E') !== false) $escapes = false;
		}
		$s = implode(' ', $a);
		if ($escapes) $s = self::escapes($s);
		$sink->write($s . ($newline ? "\n" : ''));
		return 0;
	}

	/** \n \t \r \\ \e \033 \xHH in text. */
	static function escapes($s)
	{
		return preg_replace_callback('/\\\\(n|t|r|\\\\|e|0[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|a|b)/', function ($m) {
			$c = $m[1];
			$map = array('n' => "\n", 't' => "\t", 'r' => "\r", '\\' => '\\', 'e' => "\033", 'a' => "\007", 'b' => "\010");
			if (isset($map[$c])) return $map[$c];
			if ($c[0] === 'x') return chr(hexdec(substr($c, 1)));
			return chr(octdec(substr($c, 1)));
		}, $s);
	}

	private function b_printf(array $a, $in, $sink)
	{
		if (!$a) { $sink->error("printf: a format is needed\n"); return 1; }
		$format = self::escapes(array_shift($a));
		$out = '';
		// Reuse the format while arguments remain, as printf(1) does.
		do {
			$used = 0;
			$out .= preg_replace_callback('/%([-+ 0#]*)(\d*)(?:\.(\d+))?([sdifxXoc%])/', function ($m) use (&$a, &$used) {
				if ($m[4] === '%') return '%';
				$arg = array_shift($a);
				$used++;
				if ($arg === null) $arg = in_array($m[4], array('s', 'c'), true) ? '' : '0';
				$spec = '%' . $m[1] . $m[2] . ($m[3] !== '' ? '.' . $m[3] : '');
				switch ($m[4]) {
					case 's': return sprintf($spec . 's', $arg);
					case 'c': return substr($arg, 0, 1);
					case 'f': return sprintf($spec . 'f', (float) $arg);
					case 'x': case 'X': case 'o': return sprintf($spec . $m[4], (int) $arg);
					default: return sprintf($spec . 'd', (int) $arg);
				}
			}, $format);
		} while ($a && $used > 0);
		$sink->write($out);
		return 0;
	}

	private function b_true() { return 0; }
	private function b_false() { return 1; }

	private function b_bracket(array $a, $in, $sink)
	{
		if (end($a) !== ']') { $sink->error("[: missing ]\n"); return 2; }
		array_pop($a);
		return $this->b_test($a, $in, $sink);
	}

	private function b_test(array $a, $in, $sink)
	{
		try {
			return $this->testExpr($a) ? 0 : 1;
		} catch (Exception $e) {
			$sink->error('test: ' . $e->getMessage() . "\n");
			return 2;
		}
	}

	private function testExpr(array $a)
	{
		// -o binds loosest, then -a, then !.
		$i = array_search('-o', $a, true);
		if ($i !== false) return $this->testExpr(array_slice($a, 0, $i)) || $this->testExpr(array_slice($a, $i + 1));
		$i = array_search('-a', $a, true);
		if ($i !== false) return $this->testExpr(array_slice($a, 0, $i)) && $this->testExpr(array_slice($a, $i + 1));
		if ($a && $a[0] === '!') return !$this->testExpr(array_slice($a, 1));
		$n = count($a);
		if ($n === 0) return false;
		if ($n === 1) return $a[0] !== '';
		if ($n === 2) {
			switch ($a[0]) {
				case '-z': return $a[1] === '';
				case '-n': return $a[1] !== '';
			}
			throw new Exception('unknown operator ' . $a[0]);
		}
		if ($n === 3) {
			list($l, $op, $r) = $a;
			switch ($op) {
				case '=': case '==': return $l === $r;
				case '!=': return $l !== $r;
				case '-eq': return (int) $l === (int) $r;
				case '-ne': return (int) $l !== (int) $r;
				case '-lt': return (int) $l < (int) $r;
				case '-le': return (int) $l <= (int) $r;
				case '-gt': return (int) $l > (int) $r;
				case '-ge': return (int) $l >= (int) $r;
			}
			throw new Exception('unknown operator ' . $op);
		}
		throw new Exception('too many arguments');
	}

	private function b_type(array $a, $in, $sink)
	{
		// "type server status" names one command, not two.
		if (count($a) > 1 && ($spec = $this->shell->registry->get(implode(' ', $a))) !== null && strpos($spec['name'], ' ') !== false) {
			$a = array(implode(' ', $a));
		}
		$status = 0;
		foreach ($a as $name) {
			$al = $this->shell->history->aliases();
			if (isset($al[$name])) { $sink->write("$name is an alias for {$al[$name]}\n"); continue; }
			if (isset(self::DOCS[$name])) { $sink->write("$name is a shell built-in\n"); continue; }
			$spec = $this->shell->registry->get($name);
			if ($spec) {
				$sink->write($name . ' is ' . ($spec['source'] === 'console' ? 'the console command ' . $spec['console'] : 'a ' . $spec['source'] . ' command')
					. ' (' . $spec['tier'] . (!empty($spec['disruptive']) ? ', disruptive' : '') . ")\n");
				continue;
			}
			$sink->error("$name not found\n");
			$status = 1;
		}
		return $status;
	}

	private function b_which(array $a, $in, $sink) { return $this->b_type($a, $in, $sink); }

	private function b_use(array $a, $in, $sink)
	{
		if (!$a) {
			$ctx = $this->shell->context;
			$sink->write($ctx ? 'site ' . ($ctx['site'] ?? '') . "\n" : "no context (use site <name>)\n");
			return 0;
		}
		if ($a[0] === '-') { $this->shell->context = array(); $this->shell->io->ctl(array('op' => 'context', 'site' => '')); return 0; }
		if ($a[0] === 'site' && isset($a[1]) && preg_match('/^[A-Za-z0-9._-]+$/', $a[1])) {
			$this->shell->context = array('site' => $a[1]);
			$this->shell->io->ctl(array('op' => 'context', 'site' => $a[1]));
			return 0;
		}
		$sink->error("use: use site <name>, or use - to clear\n");
		return 2;
	}

	private function b_time(array $a, $in, $sink)
	{
		if (!$a) { $sink->error("time: a command is needed\n"); return 2; }
		$t = microtime(true);
		$status = $this->shell->dispatch($a, $in, $sink);
		$sink->error(sprintf("\nreal  %.3fs\n", microtime(true) - $t));
		return $status;
	}

	private function b_repeat(array $a, $in, $sink)
	{
		if (count($a) < 2 || !ctype_digit($a[0])) { $sink->error("repeat: repeat <count> <command...>\n"); return 2; }
		$n = min(1000, (int) array_shift($a));
		$status = 0;
		for ($i = 0; $i < $n; $i++) $status = $this->shell->dispatch($a, $in, $sink);
		return $status;
	}

	private function b_sleep(array $a, $in, $sink)
	{
		$s = isset($a[0]) && is_numeric($a[0]) ? (float) $a[0] : -1;
		if ($s < 0 || $s > 60) { $sink->error("sleep: 0 to 60 seconds\n"); return 2; }
		usleep((int) ($s * 1000000));
		return 0;
	}

	private function b_source(array $a, $in, $sink)
	{
		if (!$a) { $sink->error("source: a file is needed\n"); return 2; }
		$roots = array_filter(array($this->ctx('shellDir'), $this->ctx('scriptsDir')));
		$text = $this->shell->history->scriptText($a[0], $roots);
		if ($text === null) { $sink->error('source: ' . $a[0] . ": not found in the shell or scripts directory\n"); return 1; }
		$saved = $this->shell->args;
		$this->shell->args = array_slice($a, 1);
		$this->shell->scriptDepth++;
		try {
			$status = $this->shell->runText($text, $sink, $in);
		} finally {
			$this->shell->scriptDepth--;
			$this->shell->args = $saved;
		}
		return $status;
	}

	private function b_exec(array $a, $in, $sink) { return $this->b_source($a, $in, $sink); }

	private function b_sudo(array $a, $in, $sink)
	{
		if ($a && $a[0] === '-k') {
			$this->shell->opts['elevatedUntil'] = 0;
			$this->shell->io->ctl(array('op' => 'unelevate'));
			return 0;
		}
		$opts = $this->shell->opts;
		if (!empty($opts['root']) && $a && $a[0] !== '-v') {
			// This runner is root (Q.shell.allowRoot, a plain sudo line): say so
			// before asking, every time.
			$sink->error("\033[1;33mqsh: sudo runs this one command as root.\033[0m\n");
		}
		if (!$this->shell->elevated() && !$this->shell->elevate('sudo', array(), $sink)) return 1;
		if (!$a || $a[0] === '-v') {
			$sink->write('confirmed until ' . date('H:i', (int) $this->shell->opts['elevatedUntil']) . "\n");
			return 0;
		}
		if (empty($opts['root'])) {
			$sink->error('qsh: note: ' . (!empty($opts['allowRoot'])
				? 'sudo gives root only to a line of its own with plain words (no pipes, variables or ;)'
				: 'root use is off on this server (Q.shell.allowRoot)')
				. '; this runs as ' . ($opts['runsAs'] !== '' ? $opts['runsAs'] : 'the shell user') . "\n");
		}
		if (!empty($opts['root'])) {
			// As root, never a script someone other than root could have edited.
			$r = $this->shell->registry->resolve($a);
			$path = $r ? (string) ($r[0]['path'] ?? '') : '';
			if ($path !== '' && ($weak = Q_WebServer_Shell::untrustedPath(dirname($path))) !== null) {
				$sink->error('qsh: sudo: ' . $a[0] . ' is a script under ' . $weak . ", which a user other than root can change; not run as root\n");
				return 1;
			}
		}
		return $this->shell->dispatch($a, $in, $sink);
	}
	private function b_exit(array $a, $in, $sink) { $this->shell->io->ctl(array('op' => 'hide')); return 0; }
	private function b_clear(array $a, $in, $sink) { $this->shell->io->ctl(array('op' => 'clear')); return 0; }

	// ── The terminal ────────────────────────────────────────────────────

	private function b_theme(array $a, $in, $sink)
	{
		$themes = (array) $this->ctx('themes', array());
		$verb = $a[0] ?? 'list';
		if ($verb === 'list') {
			foreach ($themes as $name => $label) $sink->write(sprintf("%-16s %s\n", $name, $label));
			return 0;
		}
		if (($verb === 'use' || $verb === 'load') && isset($a[1])) {
			if (!isset($themes[$a[1]])) { $sink->error('theme: no theme named ' . $a[1] . " (theme list)\n"); return 1; }
			$this->shell->io->ctl(array('op' => 'theme', 'name' => $a[1]));
			return 0;
		}
		$sink->error("theme: theme list | theme use <name>\n");
		return 2;
	}

	private function b_bind(array $a, $in, $sink)
	{
		if (!$a) { $this->shell->io->ctl(array('op' => 'binds')); return 0; }
		if ($a[0] === '-r' && isset($a[1])) { $this->shell->io->ctl(array('op' => 'bind', 'key' => $a[1], 'command' => null)); return 0; }
		if (count($a) < 2 || !preg_match('/^((ctrl|alt|shift|meta)\+)*([A-Za-z0-9]|F\d{1,2}|Enter|Tab|Home|End|PageUp|PageDown|Insert|Delete)$/i', $a[0])) {
			$sink->error("bind: bind <key> <command...>, keys like F2, ctrl+g, alt+shift+k\n");
			return 2;
		}
		$this->shell->io->ctl(array('op' => 'bind', 'key' => $a[0], 'command' => implode(' ', array_map(array('Q_WebServer_Shell_Interpreter', 'quote'), array_slice($a, 1)))));
		return 0;
	}

	private function b_layout(array $a, $in, $sink)
	{
		if (!$a || !in_array($a[0], array('vertical', 'horizontal'), true)) { $sink->error("layout: vertical | horizontal\n"); return 2; }
		$this->shell->io->ctl(array('op' => 'layout', 'value' => $a[0]));
		return 0;
	}

	// ── Settings ────────────────────────────────────────────────────────

	private function b_get(array $a, $in, $sink)
	{
		list($opts, $rest) = Q_WebServer_Shell_Format::options($a);
		$rows = Q_WebServer_Shell_Settings::rows((array) $this->ctx('settings', array()),
			(array) $this->ctx('runtimeChanged', array()), $this->ctx('startOptions', array())['config'] ?? null);
		$want = ($rest && $rest[0] !== 'all') ? explode(',', implode(',', $rest)) : null;
		if ($want) {
			$rows = array_values(array_filter($rows, function ($r) use ($want) { return in_array($r['name'], $want, true); }));
			if (!$rows) { $sink->error('get: no such setting: ' . implode(',', $want) . " (get all)\n"); return 1; }
		}
		$sink->write(Q_WebServer_Shell_Format::table($rows, array('name', 'value', 'source'), $opts));
		return 0;
	}

	private function b_set(array $a, $in, $sink)
	{
		$persist = false;
		$forced = false;
		$pairs = array();
		foreach ($a as $arg) {
			if ($arg === '-p') { $persist = true; continue; }
			if ($arg === '-f' || $arg === '--force') { $forced = true; continue; }
			if (!preg_match('/^([A-Za-z][A-Za-z0-9.]*)=(.*)$/s', $arg, $m)) { $sink->error("set: name=value, e.g. set workers=16 (get all lists them)\n"); return 2; }
			$pairs[$m[1]] = $m[2];
		}
		if (!$pairs) return $this->b_get(array(), $in, $sink);
		$parsed = array();
		foreach ($pairs as $name => $raw) {
			list($value, $error) = Q_WebServer_Shell_Settings::parse($name, $raw);
			if ($error !== null) { $sink->error('set: ' . $error . "\n"); return 1; }
			$parsed[$name] = $value;
		}
		// Saving, and resizing the pool, change the running server: asked first.
		if (($persist || isset($parsed['workers'])) && !$this->shell->confirm('set ' . implode(' ', array_keys($parsed)) . ($persist ? ' -p' : ''), $forced, $sink)) {
			return 1;
		}
		$config = $this->ctx('startOptions', array())['config'] ?? null;
		foreach ($parsed as $name => $value) {
			$applies = Q_WebServer_Shell_Settings::TABLE[$name][2];
			$notes = array();
			if ($applies === 'runtime') {
				$this->shell->io->ctl(array('op' => 'apply', 'name' => $name, 'value' => $value));
				$notes[] = 'applied now';
			} else {
				$notes[] = 'takes effect at the next start';
			}
			if ($persist) {
				$err = Q_WebServer_Shell_Settings::persist($config, $name, $value);
				if ($err !== null) { $sink->error('set: ' . $err . "\n"); return 1; }
				$notes[] = 'saved to ' . basename((string) $config);
			} elseif ($applies === 'restart') {
				$notes[] = 'not saved: add -p to keep it';
			}
			$sink->write($name . ' = ' . Q_WebServer_Shell_Settings::show($value) . '  (' . implode(', ', $notes) . ")\n");
		}
		return 0;
	}

	// ── Filters ─────────────────────────────────────────────────────────

	private static function lines($in)
	{
		if ($in === '') return array();
		$lines = explode("\n", $in);
		if (end($lines) === '') array_pop($lines);
		return $lines;
	}

	private static function flags(array &$a, $valued = '')
	{
		$f = array();
		$rest = array();
		for ($i = 0, $n = count($a); $i < $n; $i++) {
			$x = $a[$i];
			if ($x === '--') { $rest = array_merge($rest, array_slice($a, $i + 1)); break; }
			if (strlen($x) > 1 && $x[0] === '-' && !is_numeric($x)) {
				$chars = substr($x, 1);
				for ($j = 0; $j < strlen($chars); $j++) {
					$c = $chars[$j];
					if (strpos($valued, $c) !== false) {
						$f[$c] = $j + 1 < strlen($chars) ? substr($chars, $j + 1) : ($a[++$i] ?? '');
						break;
					}
					$f[$c] = true;
				}
				continue;
			}
			$rest[] = $x;
		}
		$a = $rest;
		return $f;
	}

	private function b_grep(array $a, $in, $sink)
	{
		$f = self::flags($a);
		if (!$a) { $sink->error("grep: a pattern is needed\n"); return 2; }
		$pat = $a[0];
		$body = !empty($f['F']) ? preg_quote($pat, '/') : str_replace('/', '\/', $pat);
		if (!empty($f['w'])) $body = '\b(?:' . $body . ')\b';
		$re = '/' . $body . '/' . (!empty($f['i']) ? 'i' : '') . 'u';
		if (@preg_match($re, '') === false) { $sink->error("grep: bad pattern: $pat\n"); return 2; }
		$count = 0;
		foreach (self::lines($in) as $i => $line) {
			$hit = preg_match($re, $line, $m);
			if (!empty($f['v'])) $hit = !$hit;
			if (!$hit) continue;
			$count++;
			if (!empty($f['c'])) continue;
			if (!empty($f['o']) && empty($f['v'])) {
				preg_match_all($re, $line, $all);
				foreach ($all[0] as $x) $sink->write($x . "\n");
				continue;
			}
			$sink->write((!empty($f['n']) ? ($i + 1) . ':' : '') . $line . "\n");
		}
		if (!empty($f['c'])) $sink->write($count . "\n");
		return $count ? 0 : 1;
	}

	private function b_sort(array $a, $in, $sink)
	{
		$f = self::flags($a, 'kt');
		$lines = self::lines($in);
		$key = isset($f['k']) ? max(1, (int) $f['k']) : 0;
		$sep = isset($f['t']) && $f['t'] !== '' ? $f['t'] : null;
		$field = function ($l) use ($key, $sep) {
			if (!$key) return $l;
			$parts = $sep === null ? preg_split('/\s+/', trim($l)) : explode($sep, $l);
			return $parts[$key - 1] ?? '';
		};
		usort($lines, function ($x, $y) use ($f, $field) {
			$a = $field($x); $b = $field($y);
			$c = !empty($f['n']) ? ((float) $a <=> (float) $b) : strcmp($a, $b);
			return !empty($f['r']) ? -$c : $c;
		});
		if (!empty($f['u'])) $lines = array_values(array_unique($lines));
		foreach ($lines as $l) $sink->write($l . "\n");
		return 0;
	}

	private function b_uniq(array $a, $in, $sink)
	{
		$f = self::flags($a);
		$prev = null; $n = 0;
		$emit = function ($line, $n) use ($f, $sink) {
			if ($line === null) return;
			if (!empty($f['d']) && $n < 2) return;
			$sink->write((!empty($f['c']) ? sprintf('%7d ', $n) : '') . $line . "\n");
		};
		foreach (self::lines($in) as $l) {
			if ($l === $prev) { $n++; continue; }
			$emit($prev, $n);
			$prev = $l; $n = 1;
		}
		$emit($prev, $n);
		return 0;
	}

	private function count(array &$a, $default = 10)
	{
		foreach ($a as $i => $x) {
			if (preg_match('/^-(\d+)$/', $x, $m)) { unset($a[$i]); $a = array_values($a); return (int) $m[1]; }
		}
		$f = self::flags($a, 'n');
		return isset($f['n']) ? max(0, (int) $f['n']) : $default;
	}

	private function b_head(array $a, $in, $sink)
	{
		foreach (array_slice(self::lines($in), 0, $this->count($a)) as $l) $sink->write($l . "\n");
		return 0;
	}

	private function b_tail(array $a, $in, $sink)
	{
		$n = $this->count($a);
		$lines = self::lines($in);
		foreach ($n ? array_slice($lines, -$n) : array() as $l) $sink->write($l . "\n");
		return 0;
	}

	private function b_wc(array $a, $in, $sink)
	{
		$f = self::flags($a);
		$all = !$f;
		$out = array();
		if ($all || !empty($f['l'])) $out[] = substr_count($in, "\n");
		if ($all || !empty($f['w'])) $out[] = count(preg_split('/\s+/', trim($in), -1, PREG_SPLIT_NO_EMPTY));
		if ($all || !empty($f['c'])) $out[] = strlen($in);
		$sink->write(implode(' ', array_map(function ($n) { return sprintf('%7d', $n); }, $out)) . "\n");
		return 0;
	}

	/** "1,3-5,7-" to a test for a 1-based index. */
	private static function ranges($list)
	{
		$parts = array();
		foreach (explode(',', $list) as $p) {
			if (preg_match('/^(\d*)-(\d*)$/', $p, $m)) $parts[] = array($m[1] === '' ? 1 : (int) $m[1], $m[2] === '' ? PHP_INT_MAX : (int) $m[2]);
			elseif (ctype_digit($p)) $parts[] = array((int) $p, (int) $p);
		}
		return function ($i) use ($parts) {
			foreach ($parts as $r) if ($i >= $r[0] && $i <= $r[1]) return true;
			return false;
		};
	}

	private function b_cut(array $a, $in, $sink)
	{
		$f = self::flags($a, 'dfc');
		if (!isset($f['f']) && !isset($f['c'])) { $sink->error("cut: -f list or -c list\n"); return 2; }
		$sep = isset($f['d']) ? (string) $f['d'] : "\t";
		if ($sep === '') $sep = "\t";
		foreach (self::lines($in) as $l) {
			if (isset($f['c'])) {
				$in_ = self::ranges($f['c']);
				$chars = preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY);
				$o = '';
				foreach ($chars as $i => $ch) if ($in_($i + 1)) $o .= $ch;
				$sink->write($o . "\n");
				continue;
			}
			$in_ = self::ranges($f['f']);
			$fields = explode($sep, $l);
			$o = array();
			foreach ($fields as $i => $x) if ($in_($i + 1)) $o[] = $x;
			$sink->write(implode($sep, $o) . "\n");
		}
		return 0;
	}

	/** a-z style ranges spelled out. */
	private static function trSet($s)
	{
		$s = self::escapes($s);
		return preg_replace_callback('/(.)-(.)/s', function ($m) {
			$o = '';
			for ($c = ord($m[1]); $c <= ord($m[2]); $c++) $o .= chr($c);
			return $o;
		}, $s);
	}

	private function b_tr(array $a, $in, $sink)
	{
		$f = self::flags($a);
		if (!$a) { $sink->error("tr: tr [-d] set1 [set2]\n"); return 2; }
		$from = self::trSet($a[0]);
		if (!empty($f['d'])) { $sink->write(str_replace(str_split($from), '', $in)); return 0; }
		if (!isset($a[1])) { $sink->error("tr: set2 is needed\n"); return 2; }
		$to = self::trSet($a[1]);
		if (strlen($to) < strlen($from)) $to = str_pad($to, strlen($from), substr($to, -1));
		$sink->write(strtr($in, $from, substr($to, 0, strlen($from))));
		return 0;
	}

	private function b_tee(array $a, $in, $sink)
	{
		if ($a) {
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $a[0])) { $sink->error("tee: tee <variable>\n"); return 2; }
			$this->shell->vars[$a[0]] = $in;
		}
		$sink->write($in);
		return 0;
	}

	private function b_less(array $a, $in, $sink)
	{
		$this->shell->io->ctl(array('op' => 'pager'));
		$sink->write($in);
		return 0;
	}

	private function b_seq(array $a, $in, $sink)
	{
		$n = array_map('floatval', array_filter($a, 'is_numeric'));
		if (!$n) { $sink->error("seq: seq [first [step]] last\n"); return 2; }
		list($first, $step, $last) = count($n) === 1 ? array(1, 1, $n[0]) : (count($n) === 2 ? array($n[0], 1, $n[1]) : array($n[0], $n[1], $n[2]));
		if ($step == 0 || abs(($last - $first) / $step) > 100000) { $sink->error("seq: too many numbers\n"); return 2; }
		for ($x = $first; $step > 0 ? $x <= $last : $x >= $last; $x += $step) $sink->write((floor($x) == $x ? (string) (int) $x : (string) $x) . "\n");
		return 0;
	}

	// ── Internal noun-verb commands ─────────────────────────────────────

	/**
	 * Commands answered from the server's state (the runtime snapshot the
	 * server hands the runner).
	 * @method internal
	 * @static
	 */
	static function internal($handler, Q_WebServer_Shell_Interpreter $shell, array $args, $stdin, Q_WebServer_Shell_Sink $sink)
	{
		$rt = (array) ($shell->registry->ctx['runtime'] ?? array());
		list($opts, $rest) = Q_WebServer_Shell_Format::options($args);
		switch ($handler) {
			case 'health':
				$props = array();
				foreach ($rt as $k => $v) {
					if (is_scalar($v) || $v === null) $props[$k] = $v === null ? '-' : $v;
				}
				ksort($props);
				if ($opts['j']) { $sink->write(json_encode($rt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); return 0; }
				$sink->write(Q_WebServer_Shell_Format::properties($props, $opts));
				return 0;
			case 'uptime':
				$sec = (int) ($rt['uptimeSec'] ?? 0);
				$sink->write(sprintf("up %s, %s requests, %s rps now, workers %s\n", self::duration($sec),
					$rt['requests'] ?? 0, $rt['currentRps'] ?? 0, $rt['workers'] ?? '-'));
				return 0;
			case 'workersList':
				$ws = (array) (($rt['pool']['workers'] ?? null) ?: array());
				$rows = array();
				foreach ($ws as $w) {
					$rows[] = array('index' => $w['index'] ?? '', 'pid' => $w['pid'] ?? '', 'state' => !empty($w['busy']) ? 'busy' : 'idle',
						'requests' => $w['requests'] ?? 0, 'recycle' => !empty($w['recycleAfter']) ? 'yes' : 'no');
				}
				if (!$rows) { $sink->error("workers: no worker pool (fork-per-request mode, or not reported)\n"); return 1; }
				$sink->write(Q_WebServer_Shell_Format::table($rows, array('index', 'pid', 'state', 'requests', 'recycle'), $opts));
				return 0;
			case 'workersResize':
				if (!$rest || !ctype_digit($rest[0])) { $sink->error("workers resize <count>\n"); return 2; }
				list($value, $error) = Q_WebServer_Shell_Settings::parse('workers', $rest[0]);
				if ($error !== null) { $sink->error('workers: ' . $error . "\n"); return 1; }
				$shell->io->ctl(array('op' => 'apply', 'name' => 'workers', 'value' => $value));
				$sink->write("workers = $value  (applied now; set workers=$value -p to keep it)\n");
				return 0;
			case 'cacheStats':
				$c = (array) ($rt['cache'] ?? array());
				if (!$c) { $sink->error("cache: no statistics reported\n"); return 1; }
				$sink->write(Q_WebServer_Shell_Format::properties($c, $opts));
				return 0;
			case 'logsTail':
				// The server's logs are the server's to read (they are often
				// root-only): under the server it reads them; at a terminal, here.
				$io = $sink->io();
				if ($io instanceof Q_WebServer_Shell_JsonIo && $io->serverRun) {
					return $io->runOnServer(array('kind' => 'logs', 'args' => array_values($rest)), $sink);
				}
				list($code, $out, $err) = self::logsTail((array) ($rt['logs'] ?? array()), $rest);
				if ($out !== '') $sink->write($out);
				if ($err !== '') $sink->error($err);
				return $code;
		}
		$sink->error("qsh: unknown internal command\n");
		return 1;
	}

	static function duration($sec)
	{
		$d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
		return ($d ? $d . 'd ' : '') . sprintf('%02d:%02d:%02d', $h, $m, $sec % 60);
	}

	/**
	 * logs tail [access|error] [-n N]: the last lines of one of the server's logs.
	 * @param {array} $logs access => path, error => path
	 * @param {array} $rest the arguments
	 * @return {array} array(exit status, output, error text)
	 */
	static function logsTail(array $logs, array $rest)
	{
		$which = 'access';
		$n = 20;
		for ($i = 0; $i < count($rest); $i++) {
			if ($rest[$i] === '-n' && isset($rest[$i + 1])) { $n = (int) $rest[++$i]; continue; }
			if (preg_match('/^-(\d+)$/', $rest[$i], $m)) { $n = (int) $m[1]; continue; }
			$which = $rest[$i];
		}
		if ($which !== 'access' && $which !== 'error') return array(2, '', "logs: access or error, not $which\n");
		$n = max(1, min(1000, $n));
		$path = (string) ($logs[$which] ?? '');
		if ($path === '') return array(1, '', "logs: this server writes no $which log (Q.webserver.log)\n");
		if (!is_file($path)) return array(1, '', "logs: the $which log is not there yet ($path)\n");
		if (!is_readable($path)) return array(1, '', "logs: the $which log cannot be read here ($path)\n");
		return array(0, self::tailFile($path, $n), '');
	}

	/** The last $n lines of a file, read from its end. */
	static function tailFile($path, $n)
	{
		$f = @fopen($path, 'rb');
		if (!$f) return '';
		fseek($f, 0, SEEK_END);
		$size = ftell($f);
		$buf = '';
		$pos = $size;
		while ($pos > 0 && substr_count($buf, "\n") <= $n) {
			$read = min(65536, $pos);
			$pos -= $read;
			fseek($f, $pos);
			$buf = fread($f, $read) . $buf;
			if (strlen($buf) > 8388608) break;
		}
		fclose($f);
		$lines = explode("\n", rtrim($buf, "\n"));
		return implode("\n", array_slice($lines, -$n)) . "\n";
	}
}
