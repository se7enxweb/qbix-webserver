<?php
/**
 * @module Q
 */
/**
 * Every command the shell knows beyond its built-ins, each with its tier:
 *
 *   console   the engine's console commands (Q_Console), run as their own
 *             process -- `server status` and `server:status` alike, their
 *             aliases, and unique abbreviations
 *   internal  noun-verb commands answered from the server's state:
 *             health, uptime, workers list|resize, cache stats, logs tail
 *   script    executables in the scripts directory (Q.shell.scriptsDir),
 *             their tier declared in a "# qshell-tier: basic" header line
 *   provider  commands a distribution registers (Q_WebServer_Shell_Provider)
 *
 * Tiers: basic (read-only), expanded (changes that keep the server up),
 * advanced (everything else; unknown console commands are advanced).
 *
 * @class Q_WebServer_Shell_Registry
 */
class Q_WebServer_Shell_Registry
{
	/** The tier of each engine console command that is not advanced. */
	const CONSOLE_TIERS = array(
		'server:status' => 'basic', 'server:configtest' => 'basic', 'layout:show' => 'basic',
		'ext:check' => 'basic', 'ext:list' => 'basic', 'ext:plan' => 'basic', 'ext:install-hint' => 'basic',
		'ssl:show' => 'basic', 'ssl:check' => 'basic',
		'cache:clear' => 'expanded', 'server:reload' => 'expanded',
		'site:enable' => 'expanded', 'site:disable' => 'expanded',
		'conf:enable' => 'expanded', 'conf:disable' => 'expanded',
		'mod:enable' => 'expanded', 'mod:disable' => 'expanded',
		'ssl:renew' => 'expanded', 'panel:password' => 'expanded',
	);

	/** Console commands that stop, restart or visibly change the running server. */
	const DISRUPTIVE = array('server:stop', 'server:restart', 'server:reload', 'cache:clear',
		'site:disable', 'conf:disable', 'mod:disable', 'ssl:issue', 'panel:password');

	/** Console commands that need the password again (sudo-style), and why. */
	const ELEVATE = array(
		'server:stop' => 'stops this server and every site on it',
		'server:restart' => 'restarts this server; requests in flight are cut off',
		'panel:password' => 'changes the control panel password',
	);

	/** Options a console command gets from the server it is run for, when it takes them. */
	const CONTEXT_OPTIONS = array('config' => 'config', 'conf-dir' => 'confDir', 'root' => 'root',
		'pid' => 'pid', 'distribution' => 'distribution');

	/** @var array the runner's context (see qshell.php) */
	public $ctx;

	/** @var array name => spec */
	private $specs = array();

	/** @var array console name => metadata */
	private $console = array();

	/**
	 * @param {array} $ctx serverDir, startOptions, runtime, scriptsDir, dataDir...
	 * @param {array|null} [$console=null] console metadata (Q_Console::all()); loaded when null
	 */
	function __construct(array $ctx, array $console = null)
	{
		$this->ctx = $ctx;
		$this->console = $console !== null ? $console : self::loadConsole($ctx);
		foreach ($this->console as $name => $c) {
			$spec = array(
				'name' => str_replace(':', ' ', $name),
				'console' => $name,
				'source' => 'console',
				'tier' => self::CONSOLE_TIERS[$name] ?? 'advanced',
				'disruptive' => in_array($name, self::DISRUPTIVE, true),
				'elevate' => isset(self::ELEVATE[$name]),
				'elevateReason' => self::ELEVATE[$name] ?? null,
				'description' => (string) ($c['description'] ?? ''),
				'usage' => (string) ($c['usage'] ?? ''),
				'options' => (array) ($c['options'] ?? array()),
				'aliases' => (array) ($c['aliases'] ?? array()),
			);
			$this->specs[$spec['name']] = $spec;
		}
		foreach (self::internal() as $spec) $this->specs[$spec['name']] = $spec;
		foreach ($this->scripts() as $spec) {
			if (!isset($this->specs[$spec['name']])) $this->specs[$spec['name']] = $spec;
		}
		foreach (Q_WebServer_Shell::providers() as $provider) {
			foreach ((array) $provider->commands($ctx) as $spec) {
				if (!is_array($spec) || empty($spec['name'])) continue;
				$spec += array('source' => 'provider', 'tier' => 'advanced', 'disruptive' => false,
					'description' => '', 'usage' => '', 'options' => array(), 'aliases' => array());
				if (!isset(self::tierRank()[$spec['tier']])) $spec['tier'] = 'advanced';
				$this->specs[$spec['name']] = $spec;
			}
		}
	}

	private static function tierRank() { return Q_WebServer_Shell_Interpreter::TIERS; }

	/**
	 * The engine's console commands, registered here only to be described
	 * (and run later as a process of their own).
	 */
	static function loadConsole(array $ctx)
	{
		$dir = (string) ($ctx['serverDir'] ?? '');
		if ($dir === '' || !is_file($dir . '/src/Q/WebServer/Ctl.php')) return array();
		require_once $dir . '/src/Q/Console.php';
		require_once $dir . '/src/Q/WebServer/Layout.php';
		require_once $dir . '/src/Q/WebServer/Ctl.php';
		if (!Q_Console::has('server:status')) {
			Q_WebServer_Ctl::register($dir);
			$dist = $ctx['startOptions']['distribution'] ?? null;
			if (method_exists('Q_WebServer_Layout', 'loadDistribution')) {
				Q_WebServer_Layout::loadDistribution($dist ? (string) $dist : null, $dir);
			}
		}
		return Q_Console::all();
	}

	/** The noun-verb commands answered from the server's state. */
	static function internal()
	{
		$fmt = array('o' => 'Columns, comma-separated', 'H' => array('Scripted: no header, tab-separated', false),
			'p' => array('Exact values', false), 'j' => array('JSON', false));
		return array(
			array('name' => 'health', 'source' => 'internal', 'tier' => 'basic', 'disruptive' => false,
				'description' => 'The server\'s health and counters, as properties', 'usage' => '[-H] [-p] [-j] [-o name,value]',
				'options' => $fmt, 'aliases' => array(), 'handler' => 'health'),
			array('name' => 'uptime', 'source' => 'internal', 'tier' => 'basic', 'disruptive' => false,
				'description' => 'How long the server has been up, and how busy it is', 'usage' => '',
				'options' => array(), 'aliases' => array(), 'handler' => 'uptime'),
			array('name' => 'workers list', 'source' => 'internal', 'tier' => 'basic', 'disruptive' => false,
				'description' => 'The worker processes: pid, state and requests served', 'usage' => '[-H] [-p] [-j] [-o pid,state,requests]',
				'options' => $fmt, 'aliases' => array(), 'handler' => 'workersList'),
			array('name' => 'workers resize', 'source' => 'internal', 'tier' => 'expanded', 'disruptive' => true,
				'description' => 'Change the number of workers now (the same as set workers=N)', 'usage' => '<count> [-f]',
				'options' => array(), 'aliases' => array(), 'handler' => 'workersResize'),
			array('name' => 'cache stats', 'source' => 'internal', 'tier' => 'basic', 'disruptive' => false,
				'description' => 'Response cache hits, misses and hit rate', 'usage' => '[-H] [-p] [-j]',
				'options' => $fmt, 'aliases' => array(), 'handler' => 'cacheStats'),
			array('name' => 'logs tail', 'source' => 'internal', 'tier' => 'basic', 'disruptive' => false,
				'description' => 'The end of the access or error log', 'usage' => '[access|error] [-n lines]',
				'options' => array('n' => 'Lines to show (default 20, at most 1000)'), 'aliases' => array(), 'handler' => 'logsTail'),
		);
	}

	/** Executables in the scripts directory. */
	function scripts()
	{
		$dir = (string) ($this->ctx['scriptsDir'] ?? '');
		if ($dir === '' || !is_dir($dir)) return array();
		$out = array();
		foreach ((array) @scandir($dir) as $f) {
			if (!is_string($f) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $f)) continue;
			$path = realpath($dir . '/' . $f);
			if ($path === false || !is_file($path) || !is_executable($path)) continue;
			$head = (string) @file_get_contents($path, false, null, 0, 2048);
			$tier = preg_match('/qshell-tier:\s*(basic|expanded|advanced)/', $head, $m) ? $m[1] : 'advanced';
			$desc = preg_match('/qshell-description:\s*(.+)/', $head, $m) ? trim($m[1]) : 'Script ' . $f;
			$name = preg_replace('/\.(sh|php|py)$/', '', $f);
			$out[] = array('name' => $name, 'source' => 'script', 'path' => $path, 'tier' => $tier,
				'disruptive' => (bool) preg_match('/qshell-disruptive:\s*yes/', $head),
				'description' => $desc, 'usage' => '', 'options' => array(), 'aliases' => array());
		}
		return $out;
	}

	/**
	 * Find the command a line names.
	 * @param {array} $argv
	 * @param {array} [$context]
	 * @return {array|null} array(spec, remaining args)
	 */
	function resolve(array $argv, array $context = array())
	{
		$a0 = $argv[0];
		$a1 = $argv[1] ?? null;
		if ($a1 !== null && isset($this->specs[$a0 . ' ' . $a1])) {
			return array($this->specs[$a0 . ' ' . $a1], array_slice($argv, 2));
		}
		if (isset($this->specs[$a0])) return array($this->specs[$a0], array_slice($argv, 1));
		// The console's own names: server:status, aliases (status), abbreviations (se:st).
		$c = $this->findConsole($a0);
		if ($c !== null) return array($this->specs[str_replace(':', ' ', $c)], array_slice($argv, 1));
		if ($a1 !== null) {
			$c = $this->findConsole($a0 . ':' . $a1);
			if ($c !== null) return array($this->specs[str_replace(':', ' ', $c)], array_slice($argv, 2));
		}
		return null;
	}

	/** A console name, alias or unique abbreviation to the command's name. */
	private function findConsole($name)
	{
		if (isset($this->console[$name])) return $name;
		foreach ($this->console as $cmd => $c) {
			if (in_array($name, (array) ($c['aliases'] ?? array()), true)) return $cmd;
		}
		if (strpos($name, ':') === false) return null;
		$want = explode(':', $name);
		$hits = array();
		foreach ($this->console as $cmd => $c) {
			$parts = explode(':', $cmd);
			if (count($parts) !== count($want)) continue;
			$ok = true;
			foreach ($want as $i => $w) {
				if ($w === '' || strncmp($parts[$i], $w, strlen($w)) !== 0) { $ok = false; break; }
			}
			if ($ok) $hits[] = $cmd;
		}
		return count($hits) === 1 ? $hits[0] : null;
	}

	/** Names close to one that was not found (never the name itself). */
	function suggest($name)
	{
		$out = array();
		foreach (array_keys($this->specs) as $n) {
			$first = explode(' ', $n)[0];
			if ($first !== $name && levenshtein($name, $first) <= 2 && !in_array($first, $out, true)) $out[] = $first;
		}
		return array_slice($out, 0, 3);
	}

	/** The subcommands of a noun (server -> status, stop, ...), or none. */
	function subcommands($noun)
	{
		$out = array();
		foreach (array_keys($this->specs) as $n) {
			if (strpos($n, $noun . ' ') === 0) $out[] = substr($n, strlen($noun) + 1);
		}
		return $out;
	}

	/**
	 * What to say when a line names no command: a noun without a verb, a
	 * verb the noun does not have, or an unknown name.
	 * @return {array} array(message, exit status)
	 */
	function notFound(array $argv)
	{
		$name = (string) $argv[0];
		$subs = $this->subcommands($name);
		if ($subs) {
			$list = implode(', ', array_slice($subs, 0, 16)) . (count($subs) > 16 ? ', ...' : '');
			if (!isset($argv[1])) return array('qsh: ' . $name . ': which one? ' . $list . '  (help ' . $name . ")\n", 2);
			$near = array();
			foreach ($subs as $v) {
				if (levenshtein((string) $argv[1], $v) <= 2 || ($argv[1] !== '' && strpos($v, (string) $argv[1]) === 0)) $near[] = $v;
			}
			return array('qsh: ' . $name . ': no subcommand ' . $argv[1] . ($near ? ' (did you mean ' . implode(', ', array_slice($near, 0, 3)) . '?)' : '')
				. '; it has ' . $list . "\n", 127);
		}
		$hint = $this->suggest($name);
		return array('qsh: command not found: ' . $name . ($hint ? ' (did you mean ' . implode(', ', $hint) . '?)' : '') . "\n", 127);
	}

	/** Every spec, by name. */
	function all() { return $this->specs; }

	/** One spec by its name (or its console name). */
	function get($name)
	{
		if (isset($this->specs[$name])) return $this->specs[$name];
		$c = $this->findConsole($name);
		return $c !== null ? $this->specs[str_replace(':', ' ', $c)] : null;
	}

	/**
	 * Run a resolved command.
	 * @return {integer}
	 */
	function run(array $spec, array $args, $stdin, Q_WebServer_Shell_Sink $sink,
		Q_WebServer_Shell_Interpreter $shell, array $env = array())
	{
		switch ($spec['source']) {
			case 'console':
				// Under the server: it runs them with its own rights, which the
				// runner (Q.shell.user) does not have -- its configuration, logs,
				// certificates and process. At a terminal: run here.
				$io = $sink->io();
				if ($io instanceof Q_WebServer_Shell_JsonIo && $io->serverRun) {
					return $io->runOnServer(array('kind' => 'console', 'name' => $spec['console'],
						'args' => array_values($args), 'stdin' => (string) $stdin), $sink);
				}
				$argv = Q_WebServer_Shell_Entry::console((string) ($this->ctx['serverDir'] ?? ''));
				if ($argv === null) { $sink->error("qsh: the console tools are missing from this installation\n"); return 127; }
				$argv[] = $spec['console'];
				foreach ($args as $a) $argv[] = $a;
				foreach ($this->contextFlags($spec, $args) as $f) $argv[] = $f;
				return Q_WebServer_Shell_Exec::run($argv, $stdin, $sink, $env);
			case 'internal':
				return Q_WebServer_Shell_Builtins::internal($spec['handler'], $shell, $args, $stdin, $sink);
			case 'script':
				return Q_WebServer_Shell_Exec::run(array_merge(array($spec['path']), $args), $stdin, $sink, $env, dirname($spec['path']));
			case 'provider':
				if (isset($spec['handler']) && is_callable($spec['handler'])) {
					return (int) call_user_func($spec['handler'], $shell, $args, $stdin, $sink);
				}
				if (isset($spec['argv']) && is_callable($spec['argv'])) {
					$argv = call_user_func($spec['argv'], $args, $this->ctx);
					if (!is_array($argv) || !$argv) { $sink->error('qsh: ' . $spec['name'] . ": nothing to run\n"); return 1; }
					$cwd = isset($spec['cwd']) && is_dir((string) $spec['cwd']) ? (string) $spec['cwd'] : null;
					return Q_WebServer_Shell_Exec::run($argv, $stdin, $sink, $env, $cwd);
				}
		}
		$sink->error('qsh: ' . $spec['name'] . ": cannot be run\n");
		return 1;
	}

	/** --config=... and the like, for a console command that takes them and was not given them. */
	function contextFlags(array $spec, array $args)
	{
		$start = (array) ($this->ctx['startOptions'] ?? array());
		$out = array();
		foreach (self::CONTEXT_OPTIONS as $opt => $key) {
			if (!array_key_exists($opt, (array) $spec['options'])) continue;
			if (empty($start[$key])) continue;
			foreach ($args as $a) {
				if ($a === '--' . $opt || strpos($a, '--' . $opt . '=') === 0 || $a === '-' . $opt || strpos($a, '-' . $opt . '=') === 0) continue 2;
			}
			$out[] = '--' . $opt . '=' . $start[$key];
		}
		return $out;
	}

	/** One line of help: usage and description. */
	function usageLine(array $spec)
	{
		return trim($spec['name'] . ' ' . $spec['usage']) . '  -- ' . $spec['description'];
	}

	/** A command's manual page. */
	function manPage(array $spec)
	{
		$lines = array();
		$lines[] = "\033[1mNAME\033[0m";
		$lines[] = '    ' . $spec['name'] . ' - ' . $spec['description'];
		$lines[] = '';
		$lines[] = "\033[1mSYNOPSIS\033[0m";
		$lines[] = '    ' . trim($spec['name'] . ' ' . ($spec['usage'] !== '' ? $spec['usage'] : ($spec['options'] ? '[options]' : '')));
		if (!empty($spec['console'])) $lines[] = '    ' . $spec['console'] . ' ...   (the console name works too)';
		if (!empty($spec['aliases'])) $lines[] = '    aliases: ' . implode(', ', $spec['aliases']);
		if ($spec['options']) {
			$lines[] = '';
			$lines[] = "\033[1mOPTIONS\033[0m";
			foreach ($spec['options'] as $o => $d) {
				$flag = is_array($d) && isset($d[1]) && $d[1] === false;
				$text = is_array($d) ? $d[0] : $d;
				$lines[] = sprintf('    %-24s %s', (strlen($o) === 1 ? '-' : '--') . $o . ($flag ? '' : '=V'), $text);
			}
		}
		$lines[] = '';
		$lines[] = "\033[1mTIER\033[0m";
		$lines[] = '    ' . $spec['tier'] . (!empty($spec['disruptive']) ? ', disruptive: asks "proceed? [y/N]" unless given -f' : '');
		$lines[] = '';
		$lines[] = "\033[1mSOURCE\033[0m";
		$source = array('console' => 'the engine console, run as its own process', 'internal' => 'the server\'s own state',
			'script' => 'a script in the scripts directory', 'provider' => 'added by this distribution');
		$lines[] = '    ' . ($source[$spec['source']] ?? $spec['source']);
		$lines[] = '';
		$lines[] = 'See also: help, and the Shell page of the documentation (/Q/docs).';
		return implode("\n", $lines) . "\n";
	}
}
