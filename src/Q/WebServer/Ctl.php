<?php
/**
 * @module Q
 */
/**
 * The server's control commands, for qbixconsole and qbixctl.
 *
 * What apache2ctl and the a2ensite family do for Apache, against the same
 * kind of tree (see Q_WebServer_Layout): start, stop, graceful reload,
 * restart, status and a configuration test; enabling and disabling sites,
 * conf snippets and modules; clearing the response cache. Every command sees
 * the configuration the server would: the distribution, the tree stack and
 * the --config file are loaded the way qbixserver.php loads them.
 *
 * The operations are plain static methods as well, so a program driving the
 * server (a CMS's own control script, say) can call them instead of
 * re-implementing them.
 *
 * @class Q_WebServer_Ctl
 * @static
 */
class Q_WebServer_Ctl
{
	/** @var string the engine's source tree (where qbixserver.php lives) */
	public static $sourceDir = '';

	/** Options every command that reads the configuration accepts. */
	private static $contextOptions = array(
		'conf-dir' => 'Configuration directory (default: from --config, QBIX_CONF_DIR, or none)',
		'config' => 'The site file, normally <conf dir>/sites-enabled/<site>.conf',
		'distribution' => 'Distribution to load (default: QBIX_DISTRIBUTION, then the DISTRIBUTION file)',
	);

	// ── Context ──────────────────────────────────────────────────────────

	/**
	 * Load the configuration the server would see for these options.
	 * @method context
	 * @static
	 * @param {array} $opts
	 * @return {array} confDir, stack, config, files
	 */
	static function context(array $opts)
	{
		Q_WebServer_Layout::loadDistribution(isset($opts['distribution']) ? (string) $opts['distribution'] : null, self::$sourceDir);
		$config = isset($opts['config']) && is_string($opts['config']) ? $opts['config'] : null;
		$confDir = Q_WebServer_Layout::resolve(isset($opts['conf-dir']) ? (string) $opts['conf-dir'] : null, $config);
		$stack = Q_WebServer_Layout::stack($confDir);
		$files = array();
		// Every file of each tree, loaded or not: one that does not parse is
		// skipped by load(), and configtest has to see it to report it.
		foreach ($stack as $d) { Q_WebServer_Layout::load($d); $files = array_merge($files, Q_WebServer_Layout::files($d)); }
		if ($config !== null and is_file($config)) { Q_Config::load($config); $files[] = $config; }
		// As qbixserver.php records it: what reads the trees later (the server's
		// designs, the self-signed certificate's ssl/) looks here.
		if ($stack) { Q_Config::set('Q', 'webserver', 'confDirs', $stack); Q_Config::set('Q', 'webserver', 'confDir', end($stack)); }
		return array('confDir' => $confDir, 'stack' => $stack, 'config' => $config, 'files' => $files);
	}

	/** The directory whose pairs a/dis-en* commands change: the top of the stack. */
	static function targetDir(array $ctx)
	{
		return $ctx['stack'] ? end($ctx['stack']) : null;
	}

	// ── Processes and ports, without ps or ss ────────────────────────────

	/**
	 * The pid file: --pid, else Q.webserver.pidFile, else /run/qbix, else the
	 * temporary directory.
	 */
	static function pidFile(array $opts)
	{
		if (!empty($opts['pid']) and is_string($opts['pid'])) return $opts['pid'];
		$conf = Q_Config::get('Q', 'webserver', 'pidFile', null);
		if (is_string($conf) and $conf !== '') return $conf;
		return (is_dir('/run/qbix') and is_writable('/run/qbix')) ? '/run/qbix/qbixserver.pid'
			: sys_get_temp_dir() . '/qbixserver.pid';
	}

	/** The pid in a pid file, or 0. */
	static function readPid($file)
	{
		$pid = is_file($file) ? (int) trim((string) @file_get_contents($file)) : 0;
		return $pid > 0 ? $pid : 0;
	}

	/** Whether a process is running (not a zombie). */
	static function isAlive($pid)
	{
		$pid = (int) $pid;
		if ($pid <= 0) return false;
		$stat = @file_get_contents("/proc/$pid/stat");
		if ($stat !== false) {
			$state = substr($stat, strrpos($stat, ')') + 2, 1);
			return $state !== 'Z' and $state !== 'X';
		}
		return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
	}

	/** Parse the options out of a qbixserver.php command line read from /proc. */
	static function parseProcArgs(array $args)
	{
		$opts = array();
		for ($i = 0, $n = count($args); $i < $n; ++$i) {
			$a = $args[$i];
			if (!preg_match('/^--?([^=]+)(?:=(.*))?$/', $a, $m)) continue;
			$name = $m[1];
			if (isset($m[2])) {
				$value = $m[2];
			} elseif ($i + 1 < $n and !preg_match('/^-/', $args[$i + 1])) {
				$value = $args[++$i];
			} else {
				$value = true;
			}
			$opts[$name] = $value;
		}
		return $opts;
	}

	/** The composer project root, when the source tree is inside vendor/<vendor>/<package>. */
	static function projectRoot()
	{
		$dir = self::$sourceDir;
		if (!preg_match('#/vendor/[^/]+/[^/]+$#', $dir)) return null;
		$root = @realpath($dir . '/../../..') ?: dirname($dir, 3);
		return is_dir($root) ? $root : null;
	}

	/**
	 * Find a running qbixserver.php from /proc, for when the pid file is missing
	 * or stale. Returns an array with pid, pidFile and the server's own options,
	 * or null when nothing matching is found.
	 */
	static function discoverServer(array $opts, $lenient = false)
	{
		if (!is_dir('/proc')) return null;
		$serverScript = self::serverScript();
		$realServer = @realpath($serverScript) ?: $serverScript;
		// From the phar (or a binary) the server's command line names the
		// phar file, not qbixserver.php inside it.
		$cmdPrefix = self::serverCommand();
		if (strncmp($serverScript, 'phar://', 7) === 0) {
			$serverScript = end($cmdPrefix);
			$realServer = @realpath($serverScript) ?: $serverScript;
		}
		$projectRoot = self::projectRoot();
		$candidates = array();
		foreach (glob('/proc/[1-9]*', GLOB_ONLYDIR) as $pdir) {
			$pid = (int) basename($pdir);
			if ($pid <= 0 or $pid === getmypid()) continue;
			if (!self::isAlive($pid)) continue;
			$cmd = @file_get_contents("$pdir/cmdline");
			if ($cmd === false or $cmd === '') continue;
			$args = explode("\0", rtrim($cmd, "\0"));
			$found = null;
			foreach ($args as $i => $arg) {
				if ($arg === '') continue;
				if ($arg === $serverScript) { $found = $i; break; }
				if (substr($arg, -14) === 'qbixserver.php' or ($serverScript !== self::serverScript() and basename($arg) === basename($serverScript))) {
					$real = $arg;
					if ($arg[0] !== '/') {
						$cwd = @readlink("$pdir/cwd");
						if ($cwd !== false) $real = $cwd . '/' . $arg;
					}
					$real = @realpath($real) ?: $real;
					if ($real === $realServer) { $found = $i; break; }
				}
			}
			if ($found === null) continue;
			$stat = @file_get_contents("$pdir/stat");
			$ppid = 0;
			if ($stat !== false and preg_match('/^\d+\s+\([^)]+\)\s+\S\s+(\d+)/', $stat, $m)) $ppid = (int) $m[1];
			$procOpts = self::parseProcArgs(array_slice($args, $found + 1));
			$candidates[] = array(
				'pid' => $pid,
				'ppid' => $ppid,
				'opts' => $procOpts,
				'pidFile' => isset($procOpts['pid']) ? (string) $procOpts['pid'] : null,
			);
		}
		if (!$candidates) return null;
		$pids = array_column($candidates, 'pid');
		$masters = array_values(array_filter($candidates, function ($c) use ($pids) {
			return !in_array($c['ppid'], $pids, true);
		}));
		if (!$masters) $masters = $candidates;
		// Only a server that is this one: same pid file, same root (and port,
		// when given), or same configuration file. Another site served by the
		// same engine is not "already running" and must never be stopped.
		$mine = array_values(array_filter($masters, function ($m) use ($opts) {
			return self::sameServer($m, $opts);
		}));
		if (!$mine and $lenient and count($masters) === 1 and !self::identifies($opts)) {
			// Nothing said which server: the only one running is the one meant.
			$mine = $masters;
		}
		if (!$mine) return null;
		$best = null; $bestScore = -1;
		foreach ($mine as $m) {
			$score = self::serverScore($m, $opts, $projectRoot);
			if ($score > $bestScore) { $best = $m; $bestScore = $score; }
		}
		return $best;
	}

	/** Whether the options name a particular server (pid file, root, port or config). */
	static function identifies(array $opts)
	{
		foreach (array('pid', 'root', 'port', 'config', 'conf-dir') as $o) {
			if (isset($opts[$o]) and is_string($opts[$o]) and $opts[$o] !== '') return true;
		}
		return false;
	}

	/** Whether a discovered server is the one these options describe. */
	static function sameServer(array $master, array $opts)
	{
		$p = $master['opts'];
		$real = function ($f) { return @realpath((string) $f) ?: (string) $f; };
		if (isset($opts['pid']) and is_string($opts['pid']) and $opts['pid'] !== '' and !empty($p['pid'])) {
			return $real($opts['pid']) === $real($p['pid']);
		}
		if (isset($opts['config']) and is_string($opts['config']) and $opts['config'] !== '' and !empty($p['config'])) {
			return $real($opts['config']) === $real($p['config']);
		}
		if (isset($opts['root']) and is_string($opts['root']) and $opts['root'] !== '' and !empty($p['root'])) {
			if ($real($opts['root']) !== $real($p['root'])) return false;
			if (isset($opts['port']) and $opts['port'] !== '' and isset($p['port'])) return (string) $opts['port'] === (string) $p['port'];
			return true;
		}
		if (isset($opts['port']) and is_string($opts['port']) and $opts['port'] !== '' and isset($p['port'])) {
			return (string) $opts['port'] === (string) $p['port'];
		}
		return false;
	}

	/** How well a discovered server matches the context we have. */
	static function serverScore(array $master, array $opts, $projectRoot)
	{
		$score = 0;
		$p = $master['opts'];
		if (!empty($p['pid']) and is_file($p['pid']) and self::readPid($p['pid']) === $master['pid']) $score += 100;
		if ($projectRoot !== null and !empty($p['root'])) {
			$rootReal = @realpath($p['root']) ?: $p['root'];
			$projReal = @realpath($projectRoot) ?: $projectRoot;
			if ($rootReal === $projReal) $score += 50;
			elseif (strpos($rootReal, $projReal) === 0) $score += 20;
		}
		foreach (array('conf-dir', 'config', 'root', 'host', 'port', 'https-port', 'workers', 'distribution') as $o) {
			if (isset($opts[$o]) and isset($p[$o]) and (string) $opts[$o] === (string) $p[$o]) $score += 10;
		}
		if (!empty($p['pid'])) $score += 5;
		return $score;
	}

	/**
	 * Which of these TCP ports something is listening on: from /proc/net/tcp
	 * where there is one, else by connecting to it.
	 * @param {array} $ports
	 * @return {array}
	 */
	static function listening(array $ports)
	{
		$open = array();
		$files = array_filter(array('/proc/net/tcp', '/proc/net/tcp6'), 'is_readable');
		foreach ($files as $f) {
			foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), 1) as $row) {
				$c = preg_split('/\s+/', trim($row));
				if (isset($c[3]) and $c[3] === '0A' and strpos($c[1], ':') !== false) {
					$open[hexdec(substr($c[1], strrpos($c[1], ':') + 1))] = true;
				}
			}
		}
		$out = array();
		foreach (array_unique(array_filter(array_map('intval', $ports))) as $p) {
			if ($files ? isset($open[$p]) : (bool) @fsockopen('127.0.0.1', $p, $en, $es, 0.5)) $out[] = $p;
		}
		return $out;
	}

	/** The ports the configuration names (HTTP, HTTPS). */
	static function configuredPorts(array $opts = array())
	{
		return array_values(array_filter(array(
			(int) ($opts['port'] ?? Q_Config::get('Q', 'webserver', 'port', 0)),
			(int) ($opts['https-port'] ?? Q_Config::get('Q', 'web', 'https', 'port', 0)),
		)));
	}

	// ── Built-in operations ──────────────────────────────────────────────

	/**
	 * Enable or disable a site, conf snippet or module, as a2ensite does:
	 * a relative symlink in <pair>-enabled to <pair>-available/<name>.conf.
	 * @return {array} array(ok, message)
	 */
	static function toggle($dir, $pair, $name, $enable)
	{
		$map = array('site' => 'sites', 'conf' => 'conf', 'mod' => 'mods');
		if ($dir === null) return array(false, 'no configuration directory (--conf-dir)');
		if (!isset($map[$pair])) return array(false, "unknown kind $pair");
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) or $name[0] === '.') return array(false, "invalid name $name");
		$p = $map[$pair];
		$available = "$dir/$p-available/$name.conf";
		$link = "$dir/$p-enabled/$name.conf";
		if ($enable) {
			if (!is_file($available)) return array(false, "no $p-available/$name.conf");
			if (is_link($link) or file_exists($link)) return array(true, "$pair $name already enabled");
			if (!is_dir(dirname($link))) @mkdir(dirname($link), 0755, true);
			if (!@symlink("../$p-available/$name.conf", $link)) return array(false, "could not create $link");
			return array(true, "enabled $pair $name; reload to apply");
		}
		if (!is_link($link)) {
			return array(true, is_file($link) ? "$link is a file, not a link; left alone" : "$pair $name already disabled");
		}
		@unlink($link);
		return array(true, "disabled $pair $name; reload to apply");
	}

	/**
	 * Invalidate every cached page: touch the response cache's generation
	 * marker (see Q_WebServer_Cache::$generationFile).
	 * @return {array} array(ok, message)
	 */
	static function clearCache($cacheDir, $marker = null)
	{
		if ($marker === null) {
			if (!is_string($cacheDir) or $cacheDir === '') return array(false, 'no cache directory (--cache-dir, or Q.web.cache.dir)');
			if (!is_dir($cacheDir) and !@mkdir($cacheDir, 0750, true)) return array(false, "cannot create $cacheDir");
			$marker = rtrim($cacheDir, '/') . '/.generation';
		}
		if (!@touch($marker)) return array(false, "could not touch $marker");
		return array(true, 'response cache cleared: every page stored before now is rendered again on its next request');
	}

	/**
	 * Check every configuration file parses.
	 * @return {array} array(ok, list of "OK file" / "BAD file" lines)
	 */
	static function configTest(array $files)
	{
		$ok = true; $lines = array();
		foreach ($files as $f) {
			$good = is_array(json_decode((string) @file_get_contents($f), true));
			$ok = $ok && $good;
			$lines[] = ($good ? 'OK   ' : 'BAD  ') . $f;
		}
		return array($ok, $lines);
	}

	// ── Server processes ─────────────────────────────────────────────────

	/** The server script: qbixserver.php in the source tree. */
	static function serverScript()
	{
		return self::$sourceDir . '/qbixserver.php';
	}

	/**
	 * The argv that starts the server: PHP and qbixserver.php from source.
	 * Run from the phar or a static binary, the script is inside it, and PHP
	 * cannot be handed a phar:// path: the phar (or the binary) itself is
	 * started, and its stub runs qbixserver.php.
	 * @method serverCommand
	 * @static
	 * @return {array}
	 */
	static function serverCommand()
	{
		if (!class_exists('Q_WebServer_Shell_Entry', false)) require_once __DIR__ . '/Shell/Entry.php';
		$packed = Q_WebServer_Shell_Entry::packed(self::$sourceDir);
		return $packed !== null ? $packed : array(PHP_BINARY, self::serverScript());
	}

	/**
	 * Start the server detached. Options it understands are passed on;
	 * anything after `--` goes to qbixserver.php verbatim.
	 * @return {array} array(ok, message)
	 */
	static function start(array $opts, array $extra = array())
	{
		$pidFile = self::pidFile($opts);
		if (self::isAlive(self::readPid($pidFile))) return array(false, 'already running (pid ' . self::readPid($pidFile) . ')');
		$found = self::discoverServer($opts);
		if ($found) return array(false, 'already running (pid ' . $found['pid'] . ')' . ($found['pidFile'] ? ', pid file ' . $found['pidFile'] : ''));
		$args = array_merge(self::serverCommand(), array('--pid=' . $pidFile));
		foreach (array('conf-dir', 'config', 'root', 'host', 'port', 'https-port', 'workers', 'distribution') as $o) {
			if (isset($opts[$o]) and is_string($opts[$o]) and $opts[$o] !== '') $args[] = "--$o=" . $opts[$o];
		}
		$args = array_merge($args, $extra);
		$log = (isset($opts['log']) and is_string($opts['log'])) ? $opts['log'] : sys_get_temp_dir() . '/qbixserver.log';
		$setsid = null;
		foreach (array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), array('/usr/bin', '/bin', '/usr/sbin', '/sbin')) as $d) {
			if ($d !== '' and is_executable("$d/setsid")) { $setsid = "$d/setsid"; break; }
		}
		$cmd = ($setsid ? escapeshellarg($setsid) . ' ' : '') . implode(' ', array_map('escapeshellarg', $args))
			. ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
		@exec($cmd);
		$ports = self::configuredPorts($opts);
		$deadline = microtime(true) + (float) ($opts['wait'] ?? 20);
		while (microtime(true) < $deadline) {
			$pid = self::readPid($pidFile);
			if ($pid and self::isAlive($pid) and (!$ports or self::listening($ports))) {
				return array(true, "started (pid $pid)");
			}
			usleep(250000);
		}
		return array(false, "did not start within the time allowed; see $log");
	}

	/** Ask the server to stop (qbixserver.php --stop) and wait. */
	static function stop(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		$direct = false;
		if (!self::isAlive($pid)) {
			$found = self::discoverServer($opts, true);
			if ($found) {
				$pid = $found['pid'];
				// Started without --pid: there is no file for --stop to read.
				if ($found['pidFile']) $pidFile = $found['pidFile']; else $direct = true;
			}
		}
		if (!self::isAlive($pid)) return array(true, 'not running');
		if (($direct or $pidFile === '') and function_exists('posix_kill')) {
			@posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
		} else {
			@exec(implode(' ', array_map('escapeshellarg', array_merge(self::serverCommand(), array('--stop', '--pid=' . $pidFile)))) . ' > /dev/null 2>&1');
		}
		$deadline = microtime(true) + (float) ($opts['wait'] ?? 15);
		while (microtime(true) < $deadline) {
			if (!self::isAlive($pid)) return array(true, "stopped (pid $pid)");
			usleep(250000);
		}
		return array(false, "pid $pid is still running");
	}

	/** Graceful reload: re-exec without dropping the listening sockets. */
	static function reload(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		$direct = false;
		if (!self::isAlive($pid)) {
			$found = self::discoverServer($opts, true);
			if ($found) {
				$pid = $found['pid'];
				if ($found['pidFile']) $pidFile = $found['pidFile']; else $direct = true;
			}
		}
		if (!self::isAlive($pid)) return array(false, 'not running');
		if (($direct or $pidFile === '') and function_exists('posix_kill')) {
			@posix_kill($pid, defined('SIGHUP') ? SIGHUP : 1);
			return array(true, "reload requested (pid $pid)");
		}
		@exec(implode(' ', array_map('escapeshellarg', array_merge(self::serverCommand(), array('--reload', '--pid=' . $pidFile)))) . ' > /dev/null 2>&1', $o, $code);
		return $code === 0 ? array(true, "reload requested (pid $pid)") : array(false, 'reload failed');
	}

	/** Status: the pid, whether it runs, and which configured ports listen. */
	static function status(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		$alive = self::isAlive($pid);
		$discovered = null;
		if (!$alive) {
			$discovered = self::discoverServer($opts, true);
			if ($discovered) {
				$pid = $discovered['pid'];
				$pidFile = $discovered['pidFile'] ?: $pidFile;
				$alive = true;
			}
		}
		$effective = $opts;
		if ($discovered and is_array($discovered['opts'])) $effective = $discovered['opts'] + $opts;
		$ports = self::configuredPorts($effective);
		return array('running' => $alive, 'pid' => $alive ? $pid : null, 'pidFile' => $pidFile,
			'ports' => $ports, 'listening' => $alive ? self::listening($ports) : array(),
			'discovered' => $discovered ? true : false);
	}

	// ── Commands ─────────────────────────────────────────────────────────


	/**
	 * The panel's settings file for these options, where the server keeps it:
	 * the directory above --root (default ./web), or the --app directory,
	 * then local/panel.json. Null when that directory does not exist.
	 * @method panelPasswordFile
	 * @static
	 * @param {array} $opts
	 * @return {string|null}
	 */
	static function panelPasswordFile(array $opts)
	{
		if (isset($opts['app']) and is_string($opts['app'])) {
			$appDir = realpath($opts['app']);
		} else {
			$root = (isset($opts['root']) and is_string($opts['root'])) ? $opts['root'] : getcwd() . '/web';
			$root = realpath($root);
			$appDir = $root === false ? false : dirname($root);
		}
		if ($appDir === false or !is_dir($appDir)) return null;
		// Where the server keeps it: the panel's store, for this application
		// and the configuration context() loaded (acl/ under the conf tree,
		// or APP_DIR/local without one).
		Q_WebServer_Panel_Store::setAppDir($appDir);
		return Q_WebServer_Panel_Store::aclFile();
	}

	/**
	 * A password from --password, else asked for twice on a terminal (not
	 * echoed), else the first line of standard input. Null, with the reason
	 * printed, when there is none or the two entries differ.
	 */
	static function readPassword(array $opts)
	{
		if (isset($opts['password']) and is_string($opts['password'])) return $opts['password'];
		$tty = function_exists('stream_isatty') && @stream_isatty(STDIN);
		if (!$tty) {
			$line = fgets(STDIN);
			$line = $line === false ? '' : rtrim($line, "\r\n");
			if ($line === '') { Q_Console::err('no password given (use --password, or pipe it on standard input)'); return null; }
			return $line;
		}
		$ask = function ($prompt) {
			fwrite(STDERR, $prompt);
			@shell_exec('stty -echo 2>/dev/null');
			$line = fgets(STDIN);
			@shell_exec('stty echo 2>/dev/null');
			fwrite(STDERR, "\n");
			return $line === false ? '' : rtrim($line, "\r\n");
		};
		$first = $ask('New panel password: ');
		if ($first === '') { Q_Console::err('no password given'); return null; }
		if ($ask('Again: ') !== $first) { Q_Console::err('the two passwords differ; nothing changed'); return null; }
		return $first;
	}
	/**
	 * Register the control commands with Q_Console.
	 * @method register
	 * @static
	 * @param {string} $sourceDir the engine's source tree
	 */
	static function register($sourceDir)
	{
		self::$sourceDir = rtrim($sourceDir, '/');
		$C = 'Q_Console';
		$ctxOpts = self::$contextOptions;
		$srvOpts = $ctxOpts + array('pid' => 'Pid file (default: Q.webserver.pidFile, /run/qbix, or the temp dir)');
		$say = function ($r) { $r[0] ? Q_Console::out($r[1]) : Q_Console::err($r[1]); return $r[0] ? 0 : 1; };

		$C::add('server:start', 'Start the server, detached', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::start($o, $a));
		}, $srvOpts + array('root' => 'Document root', 'port' => 'HTTP port', 'https-port' => 'HTTPS port',
			'workers' => 'Workers', 'host' => 'Bind address', 'log' => 'Where the server writes its output',
			'wait' => 'Seconds to wait for it to listen (20)'), array('start'), '[-- extra qbixserver.php options]');
		$C::add('server:stop', 'Stop the server and wait for it', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::stop($o));
		}, $srvOpts + array('wait' => 'Seconds to wait (15)'), array('stop'));
		$C::add('server:reload', 'Reload without dropping connections (graceful)', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::reload($o));
		}, $srvOpts, array('graceful', 'reload'));
		$C::add('server:restart', 'Stop, then start', function ($a, $o) use ($say) {
			self::context($o);
			$s = self::stop($o);
			if (!$s[0]) return $say($s);
			return $say(self::start($o, $a));
		}, $srvOpts, array('restart'), '[-- extra qbixserver.php options]');
		$C::add('server:status', 'Report whether the server runs, and what listens', function ($a, $o) {
			self::context($o);
			$s = self::status($o);
			if (!empty($o['json'])) { Q_Console::out(json_encode($s)); return $s['running'] ? 0 : 3; }
			Q_Console::out('  running    : ' . ($s['running'] ? 'yes (pid ' . $s['pid'] . ')' : 'no'));
			Q_Console::out('  pid file   : ' . $s['pidFile'] . (!empty($s['discovered']) ? ' (discovered)' : ''));
			Q_Console::out('  listening  : ' . ($s['listening'] ? implode(', ', $s['listening']) : 'nothing'));
			return $s['running'] ? 0 : 3; // as LSB init scripts report "not running"
		}, $srvOpts + array('json' => array('Report as JSON', false)), array('status'));
		$C::add('server:configtest', 'Check that every configuration file parses', function ($a, $o) {
			$ctx = self::context($o);
			list($ok, $lines) = self::configTest($ctx['files']);
			foreach ($lines as $l) Q_Console::out('  ' . $l);
			Q_Console::out($ok ? 'Syntax OK' : 'Syntax error');
			return $ok ? 0 : 1;
		}, $ctxOpts, array('configtest'));
		$C::add('layout:show', 'Show the configuration trees, their files and what is enabled', function ($a, $o) {
			$ctx = self::context($o);
			if (!$ctx['stack']) { Q_Console::out('  no configuration directory in use'); return 0; }
			Q_Console::out('  stack      : ' . implode(' < ', $ctx['stack']) . '   (later wins)');
			foreach ($ctx['stack'] as $d) {
				$info = Q_WebServer_Layout::describe($d, $ctx['config']);
				Q_Console::out('  ' . $d);
				foreach ($info['pairs'] as $p => $l) {
					Q_Console::out(sprintf('    %-6s %s', $p, $l['available'] ? implode(', ', array_map(function ($f) use ($l) {
						return in_array($f, $l['enabled'], true) ? "$f*" : $f; }, $l['available'])) : '-'));
				}
			}
			Q_Console::out('  site file  : ' . ($ctx['config'] ?? '-'));
			Q_Console::out('  (* enabled)');
			return 0;
		}, $ctxOpts, array('layout'));
		foreach (array('site' => 'site', 'conf' => 'conf snippet', 'mod' => 'module') as $pair => $noun) {
			foreach (array('enable' => true, 'disable' => false) as $verb => $on) {
				$C::add("$pair:$verb", ucfirst($verb) . " a $noun (a2" . ($on ? 'en' : 'dis') . "$pair)", function ($a, $o) use ($pair, $on, $say) {
					if (!$a) { Q_Console::err('name required'); return 1; }
					$ctx = self::context($o);
					$code = 0;
					foreach ($a as $name) $code |= $say(self::toggle(self::targetDir($ctx), $pair, $name, $on));
					return $code;
				}, $ctxOpts, array(($on ? 'en' : 'dis') . $pair), '<name>...');
			}
		}
		$verbosity = array('quiet' => array('Errors only', false), 'verbose' => array('Every provider tried', false),
			'debug' => array('Every event', false));
		$C::add('ssl:show', 'Show the certificates HTTPS uses: configured and self-signed', function ($a, $o) {
			self::context($o);
			$https = Q_Config::get('Q', 'web', 'https', array());
			$rows = array();
			$mode = isset($https['mode']) ? $https['mode'] : 'manual';
			$imported = Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'imported' . DIRECTORY_SEPARATOR;
			$acmeState = null;
			if ($mode === 'acme' or $mode === 'letsencrypt') {
				if ($f = Q_WebServer_Acme::files($https)) { $rows['acme'] = array($f[0], $f[1]); $acmeState = Q_WebServer_Certificate_Job::state($f[2]); }
			} elseif (in_array($mode, array('archive', 'pkcs12', 'remote'), true)) {
				$rows[$mode] = array($imported . $mode . '.pem', $imported . $mode . '.key');
			} elseif ($mode === 'certbot') {
				$w = (new Q_WebServer_Certificate_Source_Certbot())->watched($https);
				if ($w) $rows['certbot'] = $w;
			} elseif (!empty($https['cert'])) {
				$rows['configured'] = array($https['cert'], isset($https['key']) ? $https['key'] : '');
			}
			$store = new Q_WebServer_Certificate_Store();
			$rows['self-signed'] = array($store->certFile(), $store->keyFile());
			$report = array('mode' => $mode, 'acme' => $acmeState,
				'fallback' => isset($https['fallback']) ? $https['fallback'] : 'self-signed', 'certificates' => array());
			foreach ($rows as $label => $files) {
				$c = Q_WebServer_Certificate::fromFiles($files[0], $files[1]);
				$report['certificates'][$label] = array('cert' => $files[0], 'key' => $files[1],
					'present' => (bool) $c, 'usable' => $c ? $c->isUsable() : false,
					'daysLeft' => $c ? $c->daysLeft() : null, 'hosts' => $c ? $c->hosts() : array(),
					'key type' => $c ? $c->keyType() : '', 'fingerprint' => $c ? $c->fingerprint() : '');
			}
			if (!empty($o['json'])) { Q_Console::out(json_encode($report, JSON_UNESCAPED_SLASHES)); return 0; }
			Q_Console::out('  mode       : ' . $report['mode'] . ' (fallback: ' . $report['fallback'] . ')');
			if ($acmeState) {
				Q_Console::out('  acme       : last attempt ' . (!empty($acmeState['lastAttempt']) ? date('Y-m-d H:i', $acmeState['lastAttempt']) : 'never')
					. (!empty($acmeState['lastError']) ? ', last error: ' . $acmeState['lastError'] : '')
					. (!empty($acmeState['nextAttempt']) ? ', next attempt ' . date('Y-m-d H:i', $acmeState['nextAttempt']) : ''));
			}
			foreach ($report['certificates'] as $label => $r) {
				Q_Console::out(sprintf('  %-11s: %s', $label, $r['cert']));
				Q_Console::out('               ' . (!$r['present'] ? 'not present'
					: ($r['usable'] ? 'usable' : 'NOT usable') . ', ' . $r['daysLeft'] . ' days left, ' . $r['key type']
						. ', ' . implode(' ', $r['hosts'])));
			}
			return 0;
		}, $ctxOpts + array('json' => array('Report as JSON', false)), array('ssl'));
		$C::add('ssl:renew', 'Make a new self-signed certificate now; a running server swaps it in within a minute', function ($a, $o) {
			self::context($o);
			Q_WebServer_Certificate_Events::attach(new Q_WebServer_Certificate_Reporter(
				Q_WebServer_Certificate_Reporter::levelFromOptions($o)));
			// The hosts of the one in place, which the server chose knowing its own
			// address; a different list would only have the server renew again.
			$current = (new Q_WebServer_Certificate_Store())->load();
			$hosts = $a ? $a : (($current and $current->isUsable()) ? $current->hostsInOrder() : Q_WebServer_Certificate_SelfSigned::hosts());
			$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, empty($o['if-needed']));
			if (!$r) { Q_Console::err('no certificate could be made'); return 1; }
			Q_Console::out(($r[2] ? 'renewed: ' : 'unchanged: ') . $r[0]);
			return 0;
		}, $ctxOpts + $verbosity + array('if-needed' => array('Only when it expires within 30 days, is unusable or the hosts changed', false)),
			array(), '[host...]');
		$C::add('ssl:check', 'Show what a certificate file, archive or bundle holds, and whether it would be served', function ($a, $o) {
			if (!$a) { Q_Console::err('give one or more files: certificate, key, chain, archive or .p12'); return 1; }
			$members = array();
			$why = '';
			foreach ($a as $file) {
				if (!is_file($file)) { Q_Console::err("no such file: $file"); return 1; }
				if (preg_match('/\.(zip|tar|tgz|tbz2?|txz|rar|7z|tar\.(gz|bz2|xz))$/i', $file)) {
					$members += Q_WebServer_Certificate_Source_Archive::members($file, isset($o['password']) ? (string) $o['password'] : null, $why);
				} else {
					$members[basename($file)] = (string) file_get_contents($file);
				}
			}
			$pw = isset($o['password']) ? (string) $o['password'] : null;
			$c = Q_WebServer_Certificate_Source_Archive::fromMembers($members, isset($o['key-password']) ? (string) $o['key-password'] : $pw, $pw, $why);
			if (!$c) { Q_Console::err('not usable: ' . $why); return 1; }
			$chain = max(0, count(Q_WebServer_Certificate_Import::certificates($c->certPem)) - 1);
			$p = openssl_x509_parse($c->certPem);
			Q_Console::out('  usable     : yes (' . $c->keyType() . ' key)');
			Q_Console::out('  hosts      : ' . implode(' ', $c->hosts()));
			Q_Console::out('  issuer     : ' . ($p['issuer']['O'] ?? $p['issuer']['CN'] ?? '?'));
			Q_Console::out('  expires    : ' . date('Y-m-d H:i', $c->expires()) . ' (' . $c->daysLeft() . ' days)');
			Q_Console::out('  chain      : ' . $chain . ' certificate' . ($chain === 1 ? '' : 's') . ' after the leaf');
			Q_Console::out('  fingerprint: ' . $c->fingerprint());
			return 0;
		}, array('password' => 'For an encrypted archive or .p12', 'key-password' => 'For an encrypted key'), array(), '<file>...');
		$C::add('ssl:issue', 'Issue the ACME (Let\'s Encrypt) certificate now, in the foreground', function ($a, $o) {
			self::context($o);
			$config = Q_Config::get('Q', 'web', 'https', array());
			if (!empty($o['staging'])) $config['acme']['directory'] = 'letsencrypt-staging';
			if ($a) $config['acme']['domains'] = $a;
			Q_Console::out('  issuing for ' . implode(' ', Q_WebServer_Acme::domains($config)) . ' from '
				. Q_WebServer_Acme::directoryUrl($config['acme']['directory'] ?? null) . ' ...');
			$r = (new Q_WebServer_Certificate_Source_Acme())->issueNow($config);
			if (!$r['ok']) { Q_Console::err('failed: ' . $r['error']); return 1; }
			Q_Console::out('  issued: ' . $r['cert'] . ' (expires ' . date('Y-m-d', $r['expires']) . '); a running server swaps it in within a minute');
			return 0;
		}, $ctxOpts + array('staging' => array('Use Let\'s Encrypt\'s staging server (for trying things out)', false)), array(), '[domain...]');
		$C::add('panel:password', 'Set or change the control panel password', function ($a, $o) {
			// The configuration the server reads: the default key and the
			// brand are part of the policy, and so is Q.panel.bcryptCost.
			self::context($o);
			$file = self::panelPasswordFile($o);
			if ($file === null) {
				Q_Console::err('no such directory: ' . ($o['app'] ?? $o['root'] ?? getcwd() . '/web'));
				return 1;
			}
			if (($problem = Q_WebServer_Panel_Store::problem()) !== null) {
				Q_Console::err(Q_WebServer_Panel_Store::problemMessage($problem));
				return 1;
			}
			$context = Q_WebServer_Panel_Auth::policyContext(null, $file);
			if (!empty($o['generate'])) {
				$password = Q_WebServer_Panel_PasswordPolicy::generate($context);
			} else {
				$password = self::readPassword($o);
				if ($password === null) return 1;
			}
			// A changed password ends the sessions signed in with the old one,
			// and clears the default key's must-change marks.
			$r = Q_WebServer_Panel_Auth::storePassword($password, $file, true, null, $context);
			if (!$r['ok']) {
				Q_Console::err($r['error']);
				foreach ($r['failed'] ?? array() as $rule) Q_Console::err('  - ' . $rule);
				Q_Console::err('See docs/passwords.md for the rules, or use --generate.');
				return 1;
			}
			if (!empty($o['generate'])) {
				Q_Console::out('generated panel password (shown once, keep it safe):');
				Q_Console::out('  ' . $password);
			}
			Q_Console::out('panel password set in ' . $r['path'] . '; sign in at /Q/panel');
			return 0;
		}, self::$contextOptions + array(
			'root' => 'The document root the server runs with (default: ./web)',
			'app' => 'The application directory, for a server run with --app',
			'password' => 'The password (default: asked for, or read from standard input)',
			'generate' => array('Make a strong random password, set it, and print it once', false),
		));
		$C::add('panel:check', 'Show where the control panel keeps its credentials and sessions, and whether they can be trusted', function ($a, $o) {
			self::context($o);
			if (self::panelPasswordFile($o) === null) {
				Q_Console::err('no such directory: ' . ($o['app'] ?? $o['root'] ?? getcwd() . '/web'));
				return 1;
			}
			$r = Q_WebServer_Panel_Store::report();
			if (!empty($o['json'])) { Q_Console::out(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return $r['ok'] ? 0 : 1; }
			Q_Console::out('control panel storage (' . array('config' => 'set in Q.panel', 'layout' => 'from the configuration tree', 'app' => 'beside the application')[$r['source']] . ')');
			foreach ($r['rows'] as $row) {
				Q_Console::out(sprintf('  %-4s %-13s %s%s', $row['ok'] ? 'ok' : 'FAIL', $row['label'], $row['path'],
					$row['owner'] ? '  [' . $row['owner'] . ', ' . $row['mode'] . ']' : ''));
				if ($row['why']) Q_Console::out('       ' . $row['why']);
			}
			if (!empty($r['migration']['action']) and $r['migration']['action'] !== 'none') {
				Q_Console::out('  migration: ' . $r['migration']['action'] . ' - ' . ($r['migration']['why'] ?? ''));
			}
			if ($r['ok']) {
				Q_Console::out('trusted: sign-in is allowed; ' . $r['sessionCount'] . ' session file(s)');
				return 0;
			}
			Q_Console::err('LOCKED: ' . $r['problem']);
			Q_Console::err('sign-in, the default key and every session are refused until this is fixed:');
			foreach ($r['fix'] as $f) Q_Console::err('  ' . $f);
			return 1;
		}, self::$contextOptions + array(
			'root' => 'The document root the server runs with (default: ./web)',
			'app' => 'The application directory, for a server run with --app',
			'json' => array('Print the report as JSON', false),
		));
		$C::add('panel:2fa', 'Manage the control panel\'s two-factor authentication (status|enroll|confirm|disable|recovery)', function ($a, $o) {
			self::context($o);
			if (self::panelPasswordFile($o) === null) {
				Q_Console::err('no such directory: ' . ($o['app'] ?? $o['root'] ?? getcwd() . '/web'));
				return 1;
			}
			if (($problem = Q_WebServer_Panel_Store::problem()) !== null) {
				Q_Console::err(Q_WebServer_Panel_Store::problemMessage($problem));
				return 1;
			}
			$action = strtolower((string) ($a[0] ?? 'status'));
			$T = 'Q_WebServer_Panel_Totp';
			$S = 'Q_WebServer_Panel_Store';
			$window = Q_WebServer_Panel_Auth::twoFactorWindow();
			switch ($action) {
				case 'status':
					$t = $S::twoFactorGet();
					Q_Console::out('  enabled          : ' . ($S::twoFactorEnabled() ? 'yes' : 'no'));
					Q_Console::out('  pending secret   : ' . ($S::twoFactorPendingGet() !== '' ? 'yes (run: panel:2fa confirm --code=NNNNNN)' : 'no'));
					Q_Console::out('  recovery codes   : ' . count(is_array($t['recovery'] ?? null) ? $t['recovery'] : array()) . ' left');
					Q_Console::out('  config gate      : Q.panel.twofactor is ' . (Q_Config::get('Q', 'panel', 'twofactor', false) ? 'on' : 'off (2FA is not enforced until this is on)'));
					return 0;
				case 'enroll':
					$secret = $T::generateSecret();
					if (!$S::twoFactorPendingSet($secret)) { Q_Console::err('could not store the pending secret'); return 1; }
					$issuer = Q_WebServer_Panel_Auth::twoFactorIssuer();
					Q_Console::out('Add this secret to your authenticator app (shown once):');
					Q_Console::out('  secret : ' . $secret);
					Q_Console::out('  otpauth: ' . $T::provisioningUri($secret, 'panel', $issuer));
					Q_Console::out('Then confirm with a code from the app:');
					Q_Console::out('  qbixctl panel:2fa confirm --code=NNNNNN' . (isset($o['root']) ? ' --root=' . $o['root'] : ''));
					return 0;
				case 'confirm':
					$pending = $S::twoFactorPendingGet();
					if ($pending === '') { Q_Console::err('nothing to confirm; run: panel:2fa enroll'); return 1; }
					$code = (string) ($o['code'] ?? '');
					$step = $T::verify($T::base32Decode($pending), $code, null, $window);
					if ($step === false) { Q_Console::err('that code did not match; check the device clock and try again'); return 1; }
					$rc = $T::generateRecoveryCodes(10);
					$ok = $S::twoFactorUpdate(function (array $tt) use ($pending, $rc, $step) {
						return array('secret' => $pending, 'enabled' => true, 'recovery' => $rc['hashes'], 'lastStep' => $step);
					});
					if (!$ok) { Q_Console::err('could not enable two-factor'); return 1; }
					$S::twoFactorPendingClear();
					Q_Console::out('two-factor is now ON. Recovery codes (each works once, shown only now):');
					foreach ($rc['codes'] as $c) Q_Console::out('  ' . $c);
					Q_Console::out('Set Q.panel.twofactor on to enforce it at sign-in.');
					return 0;
				case 'disable':
					// Local root recovery: no code required, since whoever runs
					// this already has the store. This is the way back in for an
					// admin who has lost their authenticator.
					$S::twoFactorClear();
					Q_Console::out('two-factor is now OFF; sign-in needs only the password again.');
					return 0;
				case 'recovery':
					if (!$S::twoFactorEnabled()) { Q_Console::err('two-factor is not enabled'); return 1; }
					$rc = $T::generateRecoveryCodes(10);
					$ok = $S::twoFactorUpdate(function (array $tt) use ($rc) { $tt['recovery'] = $rc['hashes']; return $tt; });
					if (!$ok) { Q_Console::err('could not store the recovery codes'); return 1; }
					Q_Console::out('new recovery codes (each works once, the old set is void, shown only now):');
					foreach ($rc['codes'] as $c) Q_Console::out('  ' . $c);
					return 0;
				default:
					Q_Console::err('unknown action "' . $action . '"; use status, enroll, confirm, disable or recovery');
					return 1;
			}
		}, self::$contextOptions + array(
			'root' => 'The document root the server runs with (default: ./web)',
			'app' => 'The application directory, for a server run with --app',
			'code' => 'A code from the authenticator, for confirm',
		), array(), '<status|enroll|confirm|disable|recovery>');
		$C::add('cache:clear', 'Invalidate every page in the response cache', function ($a, $o) use ($say) {
			self::context($o);
			$dir = isset($o['cache-dir']) ? (string) $o['cache-dir'] : Q_Config::get('Q', 'web', 'cache', 'dir', null);
			return $say(self::clearCache($dir, Q_Config::get('Q', 'web', 'cache', 'generationFile', null)));
		}, $ctxOpts + array('cache-dir' => 'The cache directory (default: Q.web.cache.dir)'));
		// The PHP extension baseline: ext:list, ext:check, ext:plan, ext:install-hint, ext:build.
		if (!class_exists('Q_WebServer_ExtensionsCtl', false)) require_once __DIR__ . '/ExtensionsCtl.php';
		if (!class_exists('Q_WebServer_Extensions', false)) require_once __DIR__ . '/Extensions.php';
		Q_WebServer_ExtensionsCtl::register();
	}
}
