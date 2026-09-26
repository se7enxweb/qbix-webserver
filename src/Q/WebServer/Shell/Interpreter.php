<?php
/**
 * @module Q
 */
/**
 * Runs a parsed command line: expansion, control flow, pipes, aliases, the
 * built-in commands and the registered ones, with the tier guard and the
 * confirmation of disruptive commands in front of every command it starts.
 *
 * It runs inside a runner process of its own (qshell.php), never in the
 * serving parent: one runner per command line, which the server can cancel,
 * time out and kill as a process group.
 *
 * @class Q_WebServer_Shell_Interpreter
 */
class Q_WebServer_Shell_Interpreter
{
	/** Tiers, in order: each includes the ones before it. */
	const TIERS = array('basic' => 1, 'expanded' => 2, 'advanced' => 3);

	/** Loop iterations one line may run, and results one brace may make. */
	const MAX_LOOP = 10000;
	const MAX_BRACE = 1000;

	/** @var array shell variables, name => string */
	public $vars = array();

	/** @var array names of exported variables, name => true */
	public $exported = array();

	/** @var array positional parameters for source'd scripts */
	public $args = array();

	/** @var integer $? */
	public $status = 0;

	/** @var array cd-like context: array('site' => name) */
	public $context = array();

	/** @var Q_WebServer_Shell_Io */
	public $io;

	/** @var Q_WebServer_Shell_Registry */
	public $registry;

	/** @var Q_WebServer_Shell_History */
	public $history;

	/** @var Q_WebServer_Shell_Builtins */
	public $builtins;

	/**
	 * @var array options: tier (the highest allowed), allowSystem, force
	 *   (confirmations answered yes), interactive, maxOutput, user
	 */
	public $opts;

	/** @var integer depth of confirmation-free script execution (exec/source) */
	public $scriptDepth = 0;

	private $aliasGuard = array();
	private $loopCount = 0;

	function __construct(Q_WebServer_Shell_Io $io, Q_WebServer_Shell_Registry $registry,
		Q_WebServer_Shell_History $history, array $opts = array())
	{
		$this->io = $io;
		$this->registry = $registry;
		$this->history = $history;
		$this->opts = $opts + array('tier' => 'advanced', 'allowSystem' => false, 'force' => false,
			'maxOutput' => 8388608, 'user' => 'panel');
		$this->builtins = new Q_WebServer_Shell_Builtins($this);
	}

	/**
	 * Run one line as typed: raw escape, history expansion, then the parser.
	 * @method runLine
	 * @param {string} $line
	 * @param {boolean} [$record=true] add it to the history
	 * @return {integer} exit status
	 */
	function runLine($line, $record = true)
	{
		$sink = new Q_WebServer_Shell_Sink($this->io, false, (int) $this->opts['maxOutput']);
		$trim = ltrim($line);
		if ($trim === '') return $this->status;
		// "! command" (a space after the !) is the raw OS escape; "!word" is history.
		if (strncmp($trim, '! ', 2) === 0) {
			if ($record) $this->history->add($line);
			return $this->status = $this->runSystem(substr($trim, 2), '', $sink);
		}
		try {
			$expanded = $this->history->expand($line);
		} catch (Exception $e) {
			$sink->error('qsh: ' . $e->getMessage() . "\n");
			return $this->status = 1;
		}
		if ($expanded !== $line) {
			$sink->write($expanded . "\n");
			if ($this->io instanceof Q_WebServer_Shell_JsonIo) $this->io->send(array('t' => 'line', 'd' => $expanded));
		}
		if ($record) $this->history->add($expanded);
		return $this->status = $this->runText($expanded, $sink);
	}

	/**
	 * Parse and run text (a line, an alias body, a sourced file).
	 * @return {integer}
	 */
	function runText($text, Q_WebServer_Shell_Sink $sink, $stdin = '')
	{
		try {
			$list = Q_WebServer_Shell_Parser::parse($text);
		} catch (Q_WebServer_Shell_SyntaxError $e) {
			$sink->error('qsh: syntax error: ' . $e->getMessage() . "\n");
			return $this->status = 2;
		}
		try {
			return $this->execList($list, $sink, $stdin);
		} catch (Q_WebServer_Shell_SyntaxError $e) {
			$sink->error('qsh: ' . $e->getMessage() . "\n");
			return $this->status = 1;
		}
	}

	// ── Execution ───────────────────────────────────────────────────────

	function execList(array $list, Q_WebServer_Shell_Sink $sink, $stdin = '')
	{
		$status = $this->status;
		foreach ($list['items'] as $item) {
			// A background marker reaching the runner is not the last command
			// of the line (the server runs that one as a job): run it in turn.
			$status = $this->execAndOr($item['node'], $sink, $stdin);
			$this->status = $status;
		}
		return $status;
	}

	private function execAndOr(array $node, Q_WebServer_Shell_Sink $sink, $stdin)
	{
		$status = $this->execPipeline($node['first'], $sink, $stdin);
		foreach ($node['rest'] as $pair) {
			list($op, $pipeline) = $pair;
			if (($op === '&&' && $status !== 0) || ($op === '||' && $status === 0)) continue;
			$this->status = $status;
			$status = $this->execPipeline($pipeline, $sink, $stdin);
		}
		return $status;
	}

	private function execPipeline(array $node, Q_WebServer_Shell_Sink $sink, $stdin)
	{
		$input = $stdin;
		$cmds = $node['cmds'];
		$last = count($cmds) - 1;
		$status = 0;
		foreach ($cmds as $i => $cmd) {
			if ($i < $last) {
				$cap = $sink->capturing();
				$status = $this->execCommand($cmd, $cap, $input);
				$input = $cap->buffer;
			} else {
				$status = $this->execCommand($cmd, $sink, $input);
			}
		}
		if ($node['neg']) $status = $status === 0 ? 1 : 0;
		return $status;
	}

	function execCommand(array $cmd, Q_WebServer_Shell_Sink $sink, $stdin)
	{
		switch ($cmd['type']) {
			case 'simple':
				return $this->execSimple($cmd, $sink, $stdin);
			case 'group':
				return $this->execList($cmd['body'], $sink, $stdin);
			case 'subshell':
				// Variables changed inside ( ) do not leak out, as in a subshell.
				$saved = array($this->vars, $this->exported, $this->context);
				$status = $this->execList($cmd['body'], $sink, $stdin);
				list($this->vars, $this->exported, $this->context) = $saved;
				return $status;
			case 'if':
				foreach ($cmd['clauses'] as $clause) {
					if ($this->execList($clause[0], $sink, $stdin) === 0) {
						return $this->execList($clause[1], $sink, $stdin);
					}
				}
				return $cmd['else'] ? $this->execList($cmd['else'], $sink, $stdin) : 0;
			case 'for':
				$items = $cmd['words'] === null ? $this->args : $this->expandWords($cmd['words']);
				$status = 0;
				foreach ($items as $value) {
					$this->tick();
					$this->vars[$cmd['var']] = $value;
					$status = $this->execList($cmd['body'], $sink, $stdin);
				}
				return $status;
			case 'while':
				$status = 0;
				while (true) {
					$this->tick();
					$c = $this->execList($cmd['cond'], $sink, $stdin);
					if ($cmd['until'] ? $c === 0 : $c !== 0) break;
					$status = $this->execList($cmd['body'], $sink, $stdin);
				}
				return $status;
		}
		$sink->error("qsh: unknown node\n");
		return 2;
	}

	/** Count a loop iteration against the limit. */
	private function tick()
	{
		if (++$this->loopCount > self::MAX_LOOP) {
			throw new Q_WebServer_Shell_SyntaxError('loop limit of ' . self::MAX_LOOP . ' iterations reached');
		}
	}

	private function execSimple(array $cmd, Q_WebServer_Shell_Sink $sink, $stdin)
	{
		try {
			$assigns = array();
			foreach ($cmd['assigns'] as $a) {
				$assigns[$a[0]] = implode(' ', $this->expandWord($a[1], false));
			}
			$argv = $this->expandWords($cmd['words']);
			if ($cmd['here'] !== null) $stdin = implode(' ', $this->expandWord($cmd['here'], false)) . "\n";
		} catch (Q_WebServer_Shell_SyntaxError $e) {
			$sink->error('qsh: ' . $e->getMessage() . "\n");
			return 1;
		}
		if (!$argv) {
			foreach ($assigns as $k => $v) $this->vars[$k] = $v;
			return 0;
		}
		// An alias is its text, re-read, with the remaining words after it.
		$name = $argv[0];
		$aliases = $this->history->aliases();
		if (isset($aliases[$name]) && !isset($this->aliasGuard[$name])) {
			$rest = array_map(array('Q_WebServer_Shell_Interpreter', 'quote'), array_slice($argv, 1));
			$this->aliasGuard[$name] = true;
			$status = $this->runText(trim($aliases[$name] . ' ' . implode(' ', $rest)), $sink, $stdin);
			unset($this->aliasGuard[$name]);
			return $status;
		}
		// NAME=value before a command: visible to that command only.
		$saved = $this->vars;
		foreach ($assigns as $k => $v) $this->vars[$k] = $v;
		try {
			$status = $this->dispatch($argv, $stdin, $sink, $assigns);
		} finally {
			if ($assigns) $this->vars = $saved;
		}
		return $status;
	}

	/**
	 * Run a command by name: built-in first, then the registry.
	 * @return {integer}
	 */
	function dispatch(array $argv, $stdin, Q_WebServer_Shell_Sink $sink, array $env = array())
	{
		$name = $argv[0];
		if ($name === 'sys') return $this->runSystem(implode(' ', array_map(array(__CLASS__, 'quote'), array_slice($argv, 1))), $stdin, $sink);
		$wantsHelp = isset($argv[1]) && ($argv[1] === '--help' || ($argv[1] === '-h' && $name !== 'head' && $name !== 'sort'));
		// A built-in that is also a noun (layout: the shell's tabs, and
		// "layout show" of the server) yields to that noun's own verbs.
		$isBuiltin = $this->builtins->has($name)
			&& !(isset($argv[1]) && in_array($argv[1], $this->registry->subcommands($name), true));
		if ($isBuiltin) {
			if ($wantsHelp && $name !== 'help') return $this->builtins->run('help', array($name), '', $sink);
			return $this->builtins->run($name, array_slice($argv, 1), $stdin, $sink);
		}
		$found = $this->registry->resolve($argv, $this->context);
		$preForced = false;
		if ($found === null) {
			// -f / --force anywhere on the line, also between the noun and its
			// verb (exp -f cron:frequent), confirms like -f at the end.
			$plain = array_values(array_filter($argv, function ($a) { return $a !== '-f' && $a !== '--force'; }));
			if ($plain && count($plain) !== count($argv) && ($found = $this->registry->resolve($plain, $this->context)) !== null) {
				$own = array_keys((array) ($found[0]['options'] ?? array()));
				if (in_array('f', $own, true) || in_array('force', $own, true)) {
					// The command has its own -f: it is the command's, not ours.
					foreach (array_diff($argv, $plain) as $flag) $found[1][] = $flag;
				} else {
					$preForced = true;
				}
			}
		}
		if ($found === null) {
			list($message, $code) = $this->registry->notFound($argv);
			$sink->error($message);
			return $code;
		}
		list($spec, $args) = $found;
		// --help on a command the console does not answer itself.
		if ($spec['source'] !== 'console' && $args && ($args[0] === '--help' || $args[0] === '-h')) {
			return $this->builtins->run('help', explode(' ', $spec['name']), '', $sink);
		}
		if (!$this->allowed($spec['tier'])) {
			$sink->error('qsh: ' . $spec['name'] . ' is a ' . $spec['tier'] . ' command; this session allows ' . $this->opts['tier'] . "\n");
			return 126;
		}
		list($args, $forced) = self::takeForce($args, $spec);
		$forced = $forced || $preForced;
		if (!empty($spec['elevate'])) {
			if (!$this->elevate($spec['name'], array($spec['name'] . ': ' . ($spec['elevateReason'] ?? 'changes the running server')), $sink)) return 1;
		} elseif (!empty($spec['disruptive']) && !$this->confirm($spec['name'], $forced, $sink)) {
			return 1;
		}
		$exported = array();
		foreach ($this->exported as $k => $_) if (isset($this->vars[$k])) $exported[$k] = $this->vars[$k];
		foreach ($env as $k => $v) $exported[$k] = $v;
		return $this->registry->run($spec, $args, $stdin, $sink, $this, $exported);
	}

	/** Whether this session may run a command of $tier. */
	function allowed($tier)
	{
		$have = self::TIERS[$this->opts['tier']] ?? 1;
		return (self::TIERS[$tier] ?? 3) <= $have;
	}

	/**
	 * Remove -f / --force from the arguments when it is ours (the command does
	 * not declare an option of that name itself).
	 * @return {array} array(args, forced)
	 */
	static function takeForce(array $args, array $spec)
	{
		$own = array_keys((array) ($spec['options'] ?? array()));
		$forced = false;
		$out = array();
		foreach ($args as $a) {
			if (($a === '-f' && !in_array('f', $own, true)) || ($a === '--force' && !in_array('force', $own, true))) {
				$forced = true;
				continue;
			}
			$out[] = $a;
		}
		return array($out, $forced);
	}

	/**
	 * Ask before a disruptive command: -f, force from the API, or a yes.
	 * Inside exec/source, and wherever no one can answer, -f is required.
	 */
	function confirm($what, $forced, Q_WebServer_Shell_Sink $sink)
	{
		if ($forced || !empty($this->opts['force'])) return true;
		if ($this->scriptDepth > 0 || !$this->io->interactive()) {
			$sink->error('qsh: ' . $what . " changes the running server; add -f to run it without asking\n");
			return false;
		}
		$answer = $this->io->prompt($what . ': proceed? [y/N] ');
		if ($answer !== null && preg_match('/^\s*y(es)?\s*$/i', $answer)) return true;
		$sink->error("qsh: not confirmed\n");
		return false;
	}

	/**
	 * The raw OS tier: /bin/sh -c as the server's user, only when the server
	 * allows it (Q.shell.allowSystem) and the session is advanced.
	 */
	function runSystem($command, $stdin, Q_WebServer_Shell_Sink $sink)
	{
		if (empty($this->opts['allowSystem'])) {
			$sink->error("qsh: OS commands are off on this server (Q.shell.allowSystem)\n");
			return 126;
		}
		if (!$this->allowed('advanced')) {
			$sink->error("qsh: OS commands need the advanced tier\n");
			return 126;
		}
		if (trim($command) === '') { $sink->error("qsh: sys: a command is needed\n"); return 2; }
		// Dangerous commands need the password again, and say why; the rest
		// are confirmed with y/N like any change to the running server.
		$reasons = Q_WebServer_Shell_Threat::assess($command);
		if ($reasons) {
			if (!$this->elevate('"' . $command . '"', $reasons, $sink)) return 1;
		} elseif (!$this->confirm('OS command "' . $command . '"', false, $sink)) {
			return 1;
		}
		// The server records it, wherever on the line it was.
		if ($this->io instanceof Q_WebServer_Shell_JsonIo) $this->io->send(array('t' => 'sys', 'd' => (string) $command));
		return Q_WebServer_Shell_Exec::run(array('/bin/sh', '-c', $command), $stdin, $sink);
	}

	/** Whether the session has confirmed the password recently (sudo-style). */
	function elevated()
	{
		return (int) ($this->opts['elevatedUntil'] ?? 0) > time();
	}

	/**
	 * Ask for the control panel password again before something dangerous,
	 * as sudo does: once confirmed, the session stays elevated for
	 * Q.shell.elevateMinutes (default 5). Wrong answers count towards the
	 * panel's sign-in lockout.
	 * @param {string} $what
	 * @param {array} $reasons what the command can do, shown before the question
	 * @return {boolean}
	 */
	function elevate($what, array $reasons, Q_WebServer_Shell_Sink $sink)
	{
		if (array_key_exists('elevation', $this->opts) && $this->opts['elevation'] === false) return true;
		if ($reasons) {
			$sink->error("\033[1;31mqsh: " . $what . " can do lasting damage:\033[0m\n");
			foreach ($reasons as $r) $sink->error('  - ' . $r . "\n");
		}
		if ($this->elevated()) return true;
		if (!empty($this->opts['elevationLocked'])) {
			$sink->error("qsh: too many wrong passwords from this address; try again later\n");
			return false;
		}
		if ($this->scriptDepth > 0 || !$this->io->interactive()) {
			$sink->error("qsh: it needs your password: run it at the shell, or confirm first with sudo -v\n");
			return false;
		}
		$sink->error("qsh: confirm with the control panel password to go on (it is kept for "
			. (int) ($this->opts['elevateMinutes'] ?? 5) . " minutes).\n");
		for ($try = 1; $try <= 3; $try++) {
			$answer = $this->io->prompt('[sudo] control panel password: ', true);
			if ($answer === null) return false;
			// The server checks it: it holds the password file, counts wrong
			// answers towards the lockout, and remembers a right one.
			if ($this->io instanceof Q_WebServer_Shell_JsonIo) {
				list($ok, $until, $error) = $this->io->verify($answer);
			} else {
				list($ok, $until, $error) = self::verifyLocally($answer, (string) ($this->registry->ctx['panelFile'] ?? ''), (int) ($this->opts['elevateMinutes'] ?? 5));
			}
			if ($ok) {
				$this->opts['elevatedUntil'] = $until;
				return true;
			}
			if ($error !== null && $error !== 'wrong password') {
				$sink->error('qsh: ' . $error . "\n");
				return false;
			}
			$sink->error("Sorry, try again.\n");
		}
		$sink->error("qsh: 3 incorrect password attempts\n");
		return false;
	}

	/** Without a server (qshell at a terminal): check the password file directly. */
	private static function verifyLocally($password, $panelFile, $minutes)
	{
		$c = ($panelFile !== '' && is_file($panelFile)) ? json_decode((string) @file_get_contents($panelFile), true) : null;
		$hash = is_array($c) ? (string) ($c['passwordHash'] ?? '') : '';
		if ($hash === '') return array(false, 0, 'set a control panel password first (qbixctl panel:password)');
		list($ok) = Q_WebServer_Panel_Auth::check($password, $hash);
		return $ok ? array(true, time() + 60 * max(1, $minutes), null) : array(false, 0, 'wrong password');
	}

	// ── Expansion ───────────────────────────────────────────────────────

	/** Words to argv: every word expanded, braces and splitting included. */
	function expandWords(array $words)
	{
		$out = array();
		foreach ($words as $w) {
			foreach ($this->expandWord($w) as $s) $out[] = $s;
		}
		return $out;
	}

	/**
	 * One word to the strings it becomes.
	 * @param {array} $parts
	 * @param {boolean} [$split=true] brace expansion and field splitting
	 * @return {array}
	 */
	function expandWord(array $parts, $split = true)
	{
		if (!$split) {
			$s = '';
			foreach ($parts as $p) $s .= $this->partValue($p);
			return array($s);
		}
		// Brace expansion first, over the literal text only: quoted text and
		// expansions become placeholders it cannot see into.
		$template = '';
		$slots = array();
		$quoted = false;
		foreach ($parts as $p) {
			if ($p[0] === 'lit') {
				$template .= str_replace("\x01", '', $p[1]);
			} else {
				if ($p[0] === 'sq' || $p[0] === 'dq') $quoted = true;
				$template .= "\x01" . count($slots) . "\x01";
				$slots[] = $p;
			}
		}
		$values = array();
		foreach ($slots as $k => $p) {
			$values[$k] = array($this->partValue($p), $p[0] === 'var' || $p[0] === 'cmd');
		}
		$fields = array();
		foreach (self::braceExpand($template) as $variant) {
			$cur = array('');
			$has = $quoted;
			foreach (preg_split("/\x01(\d+)\x01/", $variant, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $seg) {
				if ($i % 2 === 0) {
					if ($seg !== '') { $cur[count($cur) - 1] .= $seg; $has = true; }
					continue;
				}
				list($val, $splittable) = $values[(int) $seg];
				if (!$splittable) { $cur[count($cur) - 1] .= $val; $has = true; continue; }
				$pieces = preg_split('/\s+/', trim($val), -1, PREG_SPLIT_NO_EMPTY);
				if (!$pieces) continue;
				$has = true;
				$cur[count($cur) - 1] .= array_shift($pieces);
				foreach ($pieces as $piece) $cur[] = $piece;
			}
			if ($has) foreach ($cur as $f) $fields[] = $f;
		}
		return $fields;
	}

	/** The text of one part. */
	function partValue(array $p)
	{
		switch ($p[0]) {
			case 'lit':
			case 'sq':
				return $p[1];
			case 'dq':
				$s = '';
				foreach ($p[1] as $q) $s .= $this->partValue($q);
				return $s;
			case 'var':
				return $this->varValue($p[1], $p[2], $p[3]);
			case 'cmd':
				$cap = new Q_WebServer_Shell_Sink($this->io, true, (int) $this->opts['maxOutput']);
				$saved = $this->status;
				$this->status = $this->execList($p[1], $cap, '');
				return rtrim($cap->buffer, "\n");
			case 'arith':
				// $VAR inside $(( )) is expanded first, then the arithmetic runs.
				return (string) Q_WebServer_Shell_Arith::evaluate($p[1], $this->vars);
		}
		return '';
	}

	private function varValue($name, $op, $arg)
	{
		$set = true;
		switch ($name) {
			case '?': $v = (string) $this->status; break;
			case '#': $v = (string) count($this->args); break;
			case '$': $v = (string) getmypid(); break;
			case '0': $v = 'qsh'; break;
			case '@': case '*': $v = implode(' ', $this->args); break;
			default:
				if (ctype_digit($name)) {
					$set = isset($this->args[(int) $name - 1]);
					$v = $set ? $this->args[(int) $name - 1] : '';
				} else {
					$set = array_key_exists($name, $this->vars);
					$v = $set ? (string) $this->vars[$name] : '';
				}
		}
		if ($op === '#') return (string) strlen($v);
		if ($op === null) return $v;
		$word = function () use ($arg) {
			return $arg === null ? '' : implode(' ', $this->expandWord($arg, false));
		};
		$empty = ($op[0] === ':') ? ($v === '') : !$set;
		switch (ltrim($op, ':')) {
			case '-': return $empty ? $word() : $v;
			case '=':
				if ($empty) { $v = $word(); if (!ctype_digit($name)) $this->vars[$name] = $v; }
				return $v;
			case '+': return $empty ? '' : $word();
			case '?':
				if ($empty) throw new Q_WebServer_Shell_SyntaxError($name . ': ' . ($word() ?: 'parameter null or not set'));
				return $v;
		}
		return $v;
	}

	/**
	 * Brace expansion: {a,b,c} and {1..5} / {a..e} / {10..1..2}, nested.
	 * @method braceExpand
	 * @static
	 * @param {string} $s
	 * @return {array}
	 */
	static function braceExpand($s)
	{
		$n = strlen($s);
		for ($i = 0; $i < $n; $i++) {
			if ($s[$i] !== '{') continue;
			$depth = 0;
			$commas = array();
			for ($j = $i; $j < $n; $j++) {
				$c = $s[$j];
				if ($c === '{') $depth++;
				elseif ($c === '}') {
					$depth--;
					if ($depth === 0) break;
				} elseif ($c === ',' && $depth === 1) $commas[] = $j;
			}
			if ($j >= $n) break;
			$inner = substr($s, $i + 1, $j - $i - 1);
			$pre = substr($s, 0, $i);
			$post = substr($s, $j + 1);
			$alts = null;
			if ($commas) {
				$alts = array();
				$start = $i + 1;
				foreach ($commas as $c) { $alts[] = substr($s, $start, $c - $start); $start = $c + 1; }
				$alts[] = substr($s, $start, $j - $start);
			} elseif (preg_match('/^(-?\d+)\.\.(-?\d+)(?:\.\.(-?\d+))?$/', $inner, $m)) {
				$step = isset($m[3]) ? abs((int) $m[3]) : 1;
				if ($step === 0) $step = 1;
				$a = (int) $m[1]; $b = (int) $m[2];
				if (abs($b - $a) / $step > self::MAX_BRACE) throw new Q_WebServer_Shell_SyntaxError('brace range too large');
				$alts = array();
				for ($k = $a; $a <= $b ? $k <= $b : $k >= $b; $k += ($a <= $b ? $step : -$step)) $alts[] = (string) $k;
			} elseif (preg_match('/^([A-Za-z])\.\.([A-Za-z])$/', $inner, $m)) {
				$alts = array();
				$a = ord($m[1]); $b = ord($m[2]);
				for ($k = $a; $a <= $b ? $k <= $b : $k >= $b; $k += ($a <= $b ? 1 : -1)) $alts[] = chr($k);
			}
			if ($alts === null) continue;
			$out = array();
			foreach ($alts as $alt) {
				foreach (self::braceExpand($pre . $alt . $post) as $x) {
					$out[] = $x;
					if (count($out) > self::MAX_BRACE) throw new Q_WebServer_Shell_SyntaxError('brace expansion makes too many words');
				}
			}
			return $out;
		}
		return array($s);
	}

	/**
	 * Quote a string so the parser reads it back as one literal word.
	 * @method quote
	 * @static
	 */
	static function quote($s)
	{
		$s = (string) $s;
		if ($s !== '' && preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $s)) return $s;
		return "'" . str_replace("'", "'\\''", $s) . "'";
	}
}
