<?php
/**
 * @module Q
 */
/**
 * The shell inside the running server: sessions, jobs and the messages
 * between them and the browser. It never runs a command itself -- every
 * command line is a runner process (qshell.php --exec) whose output it reads
 * without blocking, from the event loop, and passes on.
 *
 * Messages to the browser (over the WebSocket /Q/ws/shell, or queued for
 * polling through /Q/api/shell/poll -- the same objects either way):
 *
 *   {"t":"hello","session":S,"tier":..,"allowSystem":..,"toggleKey":..}
 *   {"t":"start","id":J,"n":1,"line":"...","bg":false}   a job began
 *   {"t":"out","id":J,"d":"text"}   {"t":"err","id":J,"d":"text"}
 *   {"t":"prompt","id":J,"d":"proceed? [y/N] "}          answer with stdin
 *   {"t":"ctl","id":J,"op":"clear|hide|theme|bind|binds|layout|pager|context",...}
 *   {"t":"exit","id":J,"code":0,"ms":12}                  a job ended
 *   {"t":"wait","ids":[J..]}   {"t":"fg","id":J}          job control
 *   {"t":"completion","rid":R,"start":N,"items":[{"v":..,"d":..}]}
 *   {"t":"history","rid":R,"lines":[...]}
 *   {"t":"error","d":"text"}
 *
 * Messages from the browser:
 *
 *   {"t":"hello","session":S}
 *   {"t":"exec","session":S,"line":"...","force":false}
 *   {"t":"stdin","id":J,"d":"y"}     {"t":"signal","id":J,"sig":"INT|TERM|KILL|CONT"}
 *   {"t":"complete","session":S,"line":"...","pos":N,"rid":R}
 *   {"t":"history","rid":R}
 *
 * @class Q_WebServer_Shell_Server
 * @static
 */
class Q_WebServer_Shell_Server
{
	const MAX_SESSIONS_PER_USER = 16;
	const MAX_RUNNERS = 32;
	const QUEUE = 2000;
	const JOB_BUFFER = 1048576;
	const AUDIT_ROTATE = 5242880;

	/** @var array key => session */
	private static $sessions = array();

	/** @var array id => job */
	private static $jobs = array();

	private static $nextJob = 1;
	private static $timer = null;
	private static $meta = null;
	private static $metaAt = 0;
	private static $audit = array();

	/** @var array settings changed at runtime from the shell */
	private static $changed = array();

	// ── Sessions ────────────────────────────────────────────────────────

	/**
	 * The session a client names, created on first use.
	 * @param {string} $token the panel session token it belongs to
	 * @param {string} $client the client's own session id
	 * @return {string|null} the key, or null when the user has too many
	 */
	static function session($token, $client, $ip = '')
	{
		$client = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $client) ? $client : 'default';
		$owner = substr(hash('sha256', (string) $token), 0, 32);
		$key = substr(hash('sha256', $owner . ':' . $client), 0, 32);
		if (!isset(self::$sessions[$key])) {
			$mine = 0;
			foreach (self::$sessions as $s) if ($s['owner'] === $owner) $mine++;
			if ($mine >= self::MAX_SESSIONS_PER_USER) self::dropOldest($owner);
			self::$sessions[$key] = array('owner' => $owner, 'token' => (string) $token, 'vars' => array(), 'exported' => array(),
				'context' => array(), 'status' => 0, 'subs' => array(), 'queue' => array(), 'seq' => 0,
				'jobNo' => 0, 'seen' => time(), 'ip' => $ip, 'new' => true, 'elevatedUntil' => 0);
		}
		self::$sessions[$key]['seen'] = time();
		self::$sessions[$key]['token'] = (string) $token;
		self::ensureTimer();
		return $key;
	}

	private static function dropOldest($owner)
	{
		$oldest = null;
		foreach (self::$sessions as $k => $s) {
			if ($s['owner'] !== $owner) continue;
			if ($oldest === null || $s['seen'] < self::$sessions[$oldest]['seen']) $oldest = $k;
		}
		if ($oldest !== null) self::closeSession($oldest);
	}

	static function closeSession($key)
	{
		foreach (self::$jobs as $id => $j) if ($j['session'] === $key) self::signal($id, 'KILL');
		unset(self::$sessions[$key]);
	}

	/**
	 * End a session a client names, with its jobs: the console's close
	 * control. Only the owner's own session is touched, and one that does
	 * not exist is not created just to be closed.
	 * @param {string} $token the panel session token
	 * @param {string} $client the client's own session id
	 * @return {boolean} whether a session was closed
	 */
	static function closeClient($token, $client)
	{
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $client)) return false;
		$owner = self::owner($token);
		$key = substr(hash('sha256', $owner . ':' . $client), 0, 32);
		if (!isset(self::$sessions[$key]) || self::$sessions[$key]['owner'] !== $owner) return false;
		self::closeSession($key);
		return true;
	}

	/** What a client needs to know when it connects. */
	static function hello($key)
	{
		$themes = array();
		foreach (Q_WebServer_Shell::themes() as $n => $t) $themes[$n] = (string) ($t['label'] ?? $n);
		$s = &self::$sessions[$key];
		$msg = array('t' => 'hello', 'session' => $key, 'tier' => Q_WebServer_Shell::config('tier'),
			'allowSystem' => (bool) Q_WebServer_Shell::config('allowSystem'), 'toggleKey' => Q_WebServer_Shell::config('toggleKey'),
			'timeout' => (int) Q_WebServer_Shell::config('timeout'), 'themes' => $themes, 'context' => (object) $s['context'],
			'brand' => Q_WebServer::brand(), 'user' => Q_WebServer_Shell::user()['name'], 'root' => Q_WebServer_Shell::user()['uid'] === 0,
			'allowRoot' => Q_WebServer_Shell::rootAllowed(), 'userError' => Q_WebServer_Shell::user()['error']);
		$weak = Q_WebServer_Shell::untrustedPath();
		if ($weak !== null) {
			$msg['warning'] = $weak . ' can be changed by a user other than root, so the control panel\'s password file and the shell\'s data beneath it are only as safe as that user; move them to a root-only directory.';
		}
		// The per-user autoexec runs once, when a session first opens.
		if (!empty($s['new'])) {
			$s['new'] = false;
			if ($weak === null && is_file(Q_WebServer_Shell::dataDir() . '/autoexec.qsh')) {
				self::exec($key, 'source autoexec.qsh', array('source' => 'autoexec.qsh', 'interactive' => false, 'quiet' => true));
			}
		}
		return $msg;
	}

	/** Send a session's messages to a WebSocket client. */
	static function subscribe($key, $sk)
	{
		if (isset(self::$sessions[$key])) self::$sessions[$key]['subs'][$sk] = true;
	}
	/** Send a message to a session's subscribers, and queue it for polling. */
	static function emit($key, array $msg)
	{
		if (!isset(self::$sessions[$key])) return;
		$s = &self::$sessions[$key];
		$msg['seq'] = ++$s['seq'];
		$msg['session'] = $key;
		$s['queue'][] = $msg;
		if (count($s['queue']) > self::QUEUE) array_splice($s['queue'], 0, count($s['queue']) - self::QUEUE);
		foreach (array_keys($s['subs']) as $sk) {
			if (!isset(Q_WebSocket::$clients[$sk])) { unset($s['subs'][$sk]); continue; }
			Q_WebSocket::send($sk, $msg);
		}
	}

	/** Messages after $since, for a polling client. */
	static function poll($key, $since)
	{
		if (!isset(self::$sessions[$key])) return array(array(), 0);
		$out = array();
		foreach (self::$sessions[$key]['queue'] as $m) if ($m['seq'] > $since) $out[] = $m;
		return array($out, self::$sessions[$key]['seq']);
	}

	// ── Jobs ────────────────────────────────────────────────────────────

	/**
	 * Run a command line in a session: job control is answered here, anything
	 * else starts a runner.
	 * @param {array} $opts interactive, force, tier (may only lower), source, quiet, timeout
	 * @return {array} array('ok' => bool, 'id' => job id|null, 'error' => text|null)
	 */
	static function exec($key, $line, array $opts = array())
	{
		if (!isset(self::$sessions[$key])) return array('ok' => false, 'error' => 'no such session');
		$line = (string) $line;
		if (strlen($line) > 65536) return array('ok' => false, 'error' => 'line too long');
		$bg = false;
		$words = null;
		$user = Q_WebServer_Shell::user();
		if ($user['error'] !== null) return array('ok' => false, 'error' => $user['error']);
		if (empty($opts['source'])) {
			try {
				$list = Q_WebServer_Shell_Parser::parse($line);
				$words = Q_WebServer_Shell_Parser::simpleWords($list);
				if ($words && in_array($words[0], Q_WebServer_Shell_Builtins::SERVER_SIDE, true)) {
					return self::jobControl($key, $words, $line);
				}
				$bg = Q_WebServer_Shell_Parser::isBackground($list);
			} catch (Q_WebServer_Shell_SyntaxError $e) {
				// The runner reports it, in the same words as any other error.
			}
		}
		$running = 0;
		$mineBg = 0;
		foreach (self::$jobs as $j) {
			if ($j['state'] === 'running') $running++;
			if ($j['state'] === 'running' && $j['bg'] && $j['session'] === $key) $mineBg++;
		}
		if ($running >= self::MAX_RUNNERS) return array('ok' => false, 'error' => 'the server is running as many shell commands as it allows; try again');
		if ($bg && $mineBg >= (int) Q_WebServer_Shell::config('maxJobs')) return array('ok' => false, 'error' => 'too many background jobs in this session');

		// Root, only for one plain "sudo <command>" line, and only where the
		// server allows it; its runner still asks for the password first.
		$asRoot = $words && $words[0] === 'sudo' && isset($words[1]) && !in_array($words[1], array('-v', '-k'), true)
			&& !$bg && Q_WebServer_Shell::rootAllowed();
		$s = &self::$sessions[$key];
		$tiers = Q_WebServer_Shell_Interpreter::TIERS;
		$tier = Q_WebServer_Shell::config('tier');
		if (!empty($opts['tier']) && isset($tiers[$opts['tier']]) && $tiers[$opts['tier']] < $tiers[$tier]) $tier = $opts['tier'];
		$request = array(
			'line' => $bg ? preg_replace('/&\s*$/', '', rtrim($line)) : $line,
			'source' => $opts['source'] ?? null,
			'noHistory' => !empty($opts['quiet']),
			'interactive' => array_key_exists('interactive', $opts) ? (bool) $opts['interactive'] : !$bg,
			'force' => !empty($opts['force']),
			'tier' => $tier,
			'allowSystem' => (bool) Q_WebServer_Shell::config('allowSystem'),
			'maxOutput' => (int) Q_WebServer_Shell::config('maxOutput'),
			'user' => 'panel',
			'elevatedUntil' => (int) ($s['elevatedUntil'] ?? 0),
			'elevationLocked' => (bool) Q_WebServer_Panel_Events::notify('login.attempt', array('ip' => (string) ($s['ip'] ?? ''))),
			'elevateMinutes' => (int) Q_WebServer_Shell::config('elevateMinutes'),
			'runAs' => (!$asRoot && $user['switch']) ? $user['name'] : null,
			'root' => $asRoot,
			'allowRoot' => Q_WebServer_Shell::rootAllowed(),
			'runsAs' => $asRoot ? 'root' : $user['name'],
			'history' => self::historyFor(),
			'historyCount' => (new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir()))->count(),
			'aliases' => (object) (new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir()))->aliases(),
			'files' => (object) Q_WebServer_Shell_History::scriptsIn(Q_WebServer_Shell::dataDir()),
			'vars' => (object) $s['vars'], 'exported' => $s['exported'], 'context' => (object) $s['context'], 'status' => $s['status'],
			'ctx' => self::context(),
			// The engine's console commands and the server's logs are the
			// server's to run: the runner asks (see serverRun()).
			'serverRun' => true,
		);
		$runner = self::runnerCommand();
		if ($runner === null) return array('ok' => false, 'status' => 503, 'error' => self::MISSING);
		$pipes = array();
		// Only its three pipes and a trimmed environment: none of the server's
		// sockets or secrets reach a process that will run as another user.
		try {
			$proc = @proc_open(array_merge($runner, array('--exec')), Q_WebServer_Shell_Exec::descriptors(array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'))),
				$pipes, null, Q_WebServer_Shell_Exec::environment(array()));
		} catch (Throwable $e) {
			// proc_open() throws on a malformed command (an empty program
			// name, say): answer with the reason instead of a 500.
			return array('ok' => false, 'status' => 503, 'error' => 'could not start the shell runner: ' . $e->getMessage());
		}
		if (!is_resource($proc)) return array('ok' => false, 'status' => 503, 'error' => 'could not start the shell runner');
		Q_WebServer_Shell_Exec::closeExtra($pipes);
		fwrite($pipes[0], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
		// From here on everything to the runner is queued (toRunner()): a
		// blocking write could stall the loop while the runner is itself
		// waiting for the loop to read its output.
		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$status = proc_get_status($proc);
		$id = 'j' . (self::$nextJob++);
		$n = ++$s['jobNo'];
		self::$jobs[$id] = array('id' => $id, 'n' => $n, 'session' => $key, 'line' => $line, 'proc' => $proc, 'pipes' => $pipes,
			'pid' => (int) $status['pid'], 'started' => microtime(true), 'bg' => $bg, 'state' => 'running', 'code' => null,
			'stdout' => '', 'stderr' => '', 'partial' => '', 'eof' => false, 'watchers' => array(), 'killAt' => null,
			'timeout' => $bg ? 0 : (int) ($opts['timeout'] ?? Q_WebServer_Shell::config('timeout')), 'tier' => $tier,
			'ip' => (string) ($s['ip'] ?? ''), 'quiet' => !empty($opts['quiet']), 'expanded' => null, 'asRoot' => $asRoot,
			'wbuf' => '', 'wwatch' => null, 'run' => null, 'phpErrors' => 0);
		self::$jobs[$id]['watchers'][] = Q_Evented::onReadable($pipes[1], function () use ($id) { Q_WebServer_Shell_Server::readRunner($id); });
		self::$jobs[$id]['watchers'][] = Q_Evented::onReadable($pipes[2], function () use ($id) { Q_WebServer_Shell_Server::readRunnerErr($id); });
		self::emit($key, array('t' => 'start', 'id' => $id, 'n' => $n, 'line' => $line, 'bg' => $bg, 'quiet' => !empty($opts['quiet'])));
		if ($bg) self::emit($key, array('t' => 'out', 'id' => $id, 'd' => '[' . $n . '] ' . $status['pid'] . "\n", 'job' => true));
		return array('ok' => true, 'id' => $id, 'n' => $n, 'bg' => $bg);
	}

	/** jobs, fg, bg, kill %n, wait: answered without a runner. */
	private static function jobControl($key, array $words, $line)
	{
		$cmd = array_shift($words);
		$mine = array();
		foreach (self::$jobs as $id => $j) if ($j['session'] === $key) $mine[$j['n']] = $id;
		ksort($mine);
		$pick = function ($spec) use ($mine) {
			if ($spec === null) { $ids = array_values($mine); return $ids ? end($ids) : null; }
			if (preg_match('/^%?(\d+)$/', $spec, $m) && isset($mine[(int) $m[1]])) return $mine[(int) $m[1]];
			return null;
		};
		$say = function ($text, $isErr = false) use ($key) {
			Q_WebServer_Shell_Server::emit($key, array('t' => $isErr ? 'err' : 'out', 'id' => null, 'd' => $text));
		};
		switch ($cmd) {
			case 'jobs':
				foreach ($mine as $n => $id) {
					$j = self::$jobs[$id];
					$state = $j['state'] === 'running' ? 'running' : 'done(' . $j['code'] . ')';
					$say(sprintf("[%d]  %-10s %5ds  %s\n", $n, $state, (int) (microtime(true) - $j['started']), $j['line']));
				}
				if (!$mine) $say("no jobs\n");
				break;
			case 'fg':
				$id = $pick($words[0] ?? null);
				if (!$id) { $say("fg: no such job\n", true); break; }
				self::$jobs[$id]['bg'] = false;
				self::emit($key, array('t' => 'fg', 'id' => $id, 'n' => self::$jobs[$id]['n'], 'line' => self::$jobs[$id]['line'],
					'state' => self::$jobs[$id]['state'], 'code' => self::$jobs[$id]['code']));
				break;
			case 'bg':
				$id = $pick($words[0] ?? null);
				if (!$id) { $say("bg: no such job\n", true); break; }
				self::signal($id, 'CONT');
				$say('[' . self::$jobs[$id]['n'] . '] ' . self::$jobs[$id]['line'] . " &\n");
				break;
			case 'kill':
				$sig = 'TERM';
				if ($words && preg_match('/^-(INT|TERM|KILL|HUP|STOP|CONT|\d+)$/i', $words[0], $m)) { $sig = strtoupper($m[1]); array_shift($words); }
				$id = $pick($words[0] ?? null);
				if (!$id || !$words) { $say("kill: kill [-SIGNAL] %n (jobs lists them)\n", true); break; }
				self::signal($id, $sig);
				break;
			case 'wait':
				$ids = array();
				if ($words) { $id = $pick($words[0]); if ($id) $ids[] = $id; }
				else foreach ($mine as $id) if (self::$jobs[$id]['state'] === 'running') $ids[] = $id;
				self::emit($key, array('t' => 'wait', 'ids' => array_values(array_filter($ids, function ($i) {
					return Q_WebServer_Shell_Server::$jobs[$i]['state'] === 'running';
				}))));
				break;
		}
		self::audit($key, $line, 0, 0, 'basic');
		self::emit($key, array('t' => 'exit', 'id' => null, 'code' => 0, 'ms' => 0));
		return array('ok' => true, 'id' => null);
	}

	/** Output from a runner: one JSON message per line. */
	static function readRunner($id)
	{
		if (!isset(self::$jobs[$id])) return;
		$j = &self::$jobs[$id];
		$chunk = @fread($j['pipes'][1], 65536);
		if ($chunk === '' || $chunk === false) {
			if (feof($j['pipes'][1])) self::finish($id);
			return;
		}
		$j['partial'] .= $chunk;
		while (($nl = strpos($j['partial'], "\n")) !== false) {
			$line = substr($j['partial'], 0, $nl);
			$j['partial'] = (string) substr($j['partial'], $nl + 1);
			$m = json_decode($line, true);
			if (!is_array($m) || !isset($m['t'])) continue;
			self::onMessage($id, $m);
			if (!isset(self::$jobs[$id])) return;
		}
		if (strlen($j['partial']) > 2 * self::JOB_BUFFER) $j['partial'] = '';
	}

	/** PHP's own errors from a runner, shown as standard error. */
	static function readRunnerErr($id)
	{
		if (!isset(self::$jobs[$id])) return;
		$j = &self::$jobs[$id];
		$chunk = @fread($j['pipes'][2], 65536);
		if ($chunk === '' || $chunk === false) {
			// An ended stderr would keep waking the loop: stop watching it. The
			// stdout watcher is what ends the job.
			if (feof($j['pipes'][2]) && isset($j['watchers'][1])) {
				Q_Evented::cancel($j['watchers'][1]);
				unset($j['watchers'][1]);
			}
			return;
		}
		$chunk = self::filterPhpErrors($chunk, 'the shell runner', $j['phpErrors']);
		if ($chunk !== '') self::onMessage($id, array('t' => 'err', 'd' => $chunk));
	}

	private static function onMessage($id, array $m)
	{
		$j = &self::$jobs[$id];
		$key = $j['session'];
		switch ($m['t']) {
			case 'out':
			case 'err':
				$d = (string) ($m['d'] ?? '');
				$buf = $m['t'] === 'out' ? 'stdout' : 'stderr';
				if (strlen($j[$buf]) < self::JOB_BUFFER) $j[$buf] .= substr($d, 0, self::JOB_BUFFER - strlen($j[$buf]));
				if (!$j['quiet'] || $m['t'] === 'err') self::emit($key, array('t' => $m['t'], 'id' => $id, 'd' => $d, 'job' => $j['bg']));
				return;
			case 'prompt':
				self::emit($key, array('t' => 'prompt', 'id' => $id, 'd' => (string) ($m['d'] ?? ''), 'secret' => !empty($m['secret'])));
				return;
			case 'line':
				$j['expanded'] = (string) ($m['d'] ?? '');
				return;
			case 'state':
				if (isset(self::$sessions[$key])) {
					// Bounded: what a runner says is kept for the session's life.
					$vars = array();
					foreach (array_slice((array) ($m['vars'] ?? array()), 0, 512, true) as $k => $v) {
						if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) && is_scalar($v)) $vars[$k] = substr((string) $v, 0, 8192);
					}
					self::$sessions[$key]['vars'] = $vars;
					self::$sessions[$key]['exported'] = array_slice(array_values(array_filter((array) ($m['exported'] ?? array()), 'is_string')), 0, 512);
					self::$sessions[$key]['context'] = (array) ($m['context'] ?? array());
					self::$sessions[$key]['status'] = (int) ($m['status'] ?? 0);
				}
				return;
			case 'exit':
				$j['code'] = (int) ($m['code'] ?? 0);
				return;
			case 'sys':
				// An OS command about to run, wherever it was on the line (after
				// &&, in a loop, in an alias): the audit marks the job, and the
				// server's log gets the command itself.
				$j['system'] = true;
				if (class_exists('Q_WebServer_Log', false) && method_exists('Q_WebServer_Log', 'error')) {
					@Q_WebServer_Log::error('shell: OS command from ' . $j['ip'] . ': ' . substr((string) ($m['d'] ?? ''), 0, 4096));
				}
				return;
			case 'hist':
			case 'hist_clear':
			case 'alias':
				// The runner's changes to the per-user files; the server keeps them.
				$h = new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir());
				if ($m['t'] === 'hist') $h->add((string) ($m['d'] ?? ''));
				elseif ($m['t'] === 'hist_clear') $h->clear();
				elseif (preg_match('/^[A-Za-z0-9_.:-]+$/', (string) ($m['name'] ?? ''))) $h->setAlias((string) $m['name'], isset($m['text']) ? (string) $m['text'] : null);
				return;
			case 'hist_query':
				// History older than the page the runner was handed: history,
				// history | grep, !n, !prefix.
				self::toRunner($id, array('t' => 'hist_reply') + self::historyQuery($m));
				return;
			case 'verify':
				// A password for sudo: checked here, where the password file is,
				// through the panel's observers (lockout, log).
				list($ok, $error) = self::elevateSession($key, (string) ($m['d'] ?? ''));
				$until = (int) (self::$sessions[$key]['elevatedUntil'] ?? 0);
				self::toRunner($id, array('t' => 'verified', 'ok' => $ok, 'until' => $until, 'error' => $error));
				if ($ok) self::emit($key, array('t' => 'ctl', 'id' => $id, 'op' => 'elevated', 'until' => $until));
				return;
			case 'run':
				self::serverRun($id, $m);
				return;
			case 'run_stop':
				self::stopRun($id, 15);
				return;
			case 'ctl':
				if (($m['op'] ?? '') === 'apply') {
					$err = self::apply((string) ($m['name'] ?? ''), $m['value'] ?? null);
					if ($err !== null) self::emit($key, array('t' => 'err', 'id' => $id, 'd' => 'qsh: ' . $err . "\n"));
					return;
				}
				// Password confirmations (sudo): the session remembers them for a
				// while, and every outcome goes through the panel's observers --
				// wrong answers count towards the sign-in lockout.
				$op = (string) ($m['op'] ?? '');
				// Elevation is granted only here, by verify below; a runner can
				// only give it up (sudo -k).
				if ($op === 'elevated' || $op === 'elevate_failed') return;
				if ($op === 'unelevate' && isset(self::$sessions[$key])) self::$sessions[$key]['elevatedUntil'] = 0;
				if (isset(self::$sessions[$key]) && ($m['op'] ?? '') === 'context') {
					self::$sessions[$key]['context'] = ($m['site'] ?? '') !== '' ? array('site' => (string) $m['site']) : array();
				}
				unset($m['t']);
				self::emit($key, array('t' => 'ctl', 'id' => $id) + $m);
				return;
		}
	}

	/** A runner's output ended: collect it, record it, tell the client. */
	private static function finish($id)
	{
		if (!isset(self::$jobs[$id])) return;
		$j = &self::$jobs[$id];
		if (!$j['eof']) {
			$j['eof'] = true;
			foreach ($j['watchers'] as $w) Q_Evented::cancel($w);
			$j['watchers'] = array();
			if (!empty($j['wwatch'])) { Q_Evented::cancel($j['wwatch']); $j['wwatch'] = null; }
			$j['wbuf'] = '';
			self::stopRun($id, 15);
		}
		$st = proc_get_status($j['proc']);
		if ($st['running']) {
			// Output is over but the process is still leaving: look again shortly.
			Q_Evented::delay(0.1, function () use ($id) { Q_WebServer_Shell_Server::finish($id); });
			return;
		}
		foreach ($j['pipes'] as $p) if (is_resource($p)) @fclose($p);
		$exit = proc_close($j['proc']);
		if ($j['code'] === null) {
			$j['code'] = self::exitCode($st, $exit, $j['pid']);
		}
		$j['state'] = 'done';
		$ms = (int) round((microtime(true) - $j['started']) * 1000);
		$j['ms'] = $ms;
		$j['ended'] = microtime(true);
		// Every job is recorded, the quiet autoexec included: what runs at
		// sign-in is exactly what an audit must not miss.
		self::audit($j['session'], ($j['asRoot'] ? '[root] ' : '') . ($j['quiet'] ? '[autoexec] ' : '') . ($j['expanded'] ?? $j['line']),
			$j['code'], $ms, $j['tier'], $j['ip'], !empty($j['system']));
		if ($j['bg']) self::emit($j['session'], array('t' => 'out', 'id' => $id, 'd' => '[' . $j['n'] . ']  + done (' . $j['code'] . ')  ' . $j['line'] . "\n", 'job' => true));
		self::emit($j['session'], array('t' => 'exit', 'id' => $id, 'code' => $j['code'], 'ms' => $ms, 'bg' => $j['bg']));
	}

	/**
	 * Signal a job's whole process group.
	 * @param {string} $sig INT, TERM, KILL, HUP, STOP, CONT or a number
	 */
	static function signal($id, $sig)
	{
		if (!isset(self::$jobs[$id]) || self::$jobs[$id]['state'] !== 'running') return false;
		$map = array('INT' => 2, 'TERM' => 15, 'KILL' => 9, 'HUP' => 1, 'STOP' => 19, 'CONT' => 18, 'QUIT' => 3, 'USR1' => 10, 'USR2' => 12);
		$sig = strtoupper(preg_replace('/^SIG/i', '', (string) $sig));
		// Only these, by name or number; anything else is TERM.
		$n = ctype_digit($sig) ? (in_array((int) $sig, $map, true) ? (int) $sig : 15) : ($map[$sig] ?? 15);
		$pid = self::$jobs[$id]['pid'];
		if ($pid > 0 && function_exists('posix_kill')) {
			// The runner leads its own group (setsid): the group gets it all.
			if (!@posix_kill(-$pid, $n)) @posix_kill($pid, $n);
		} else {
			@proc_terminate(self::$jobs[$id]['proc'], $n);
		}
		if (in_array($n, array(1, 2, 9, 15), true)) self::stopRun($id, $n);
		if ($n === 2 || $n === 15) self::$jobs[$id]['killAt'] = microtime(true) + 2;
		return true;
	}


	/**
	 * Confirm the password for a session outside a command (the API's
	 * shell/elevate, and the terminal's sudo -v when it has no runner).
	 * @return {array} array(ok, error)
	 */
	static function elevateSession($key, $password)
	{
		if (!isset(self::$sessions[$key])) return array(false, 'no such session');
		$ip = (string) (self::$sessions[$key]['ip'] ?? '');
		$veto = Q_WebServer_Panel_Events::notify('login.attempt', array('ip' => $ip));
		if ($veto) return array(false, (string) ($veto['body']['error'] ?? 'locked out'));
		$config = Q_WebServer_Panel_Auth::load();
		$hash = (string) ($config['passwordHash'] ?? '');
		if ($hash === '') return array(false, 'set a control panel password first; the default key cannot confirm this');
		list($ok) = Q_WebServer_Panel_Auth::check((string) $password, $hash);
		if (!$ok) {
			Q_WebServer_Panel_Events::notify('login.failed', array('ip' => $ip, 'source' => 'shell'));
			self::audit($key, 'sudo (wrong password)', 1, 0, 'advanced', $ip);
			return array(false, 'wrong password');
		}
		self::$sessions[$key]['elevatedUntil'] = time() + 60 * max(1, (int) Q_WebServer_Shell::config('elevateMinutes'));
		Q_WebServer_Panel_Events::notify('shell.elevated', array('ip' => $ip, 'until' => self::$sessions[$key]['elevatedUntil']));
		self::audit($key, 'sudo (password confirmed)', 0, 0, 'advanced', $ip);
		return array(true, null);
	}
	/** An answer to a runner's prompt. */
	static function input($id, $data)
	{
		if (!isset(self::$jobs[$id]) || self::$jobs[$id]['state'] !== 'running') return false;
		if (strlen((string) $data) > 65536) return false;
		self::toRunner($id, array('t' => 'stdin', 'd' => (string) $data));
		return true;
	}

	/** One job, for the API. */
	static function job($id, $owner = null)
	{
		if (!isset(self::$jobs[$id])) return null;
		$j = self::$jobs[$id];
		if ($owner !== null && (self::$sessions[$j['session']]['owner'] ?? null) !== $owner) return null;
		return array('id' => $id, 'n' => $j['n'], 'line' => $j['line'], 'state' => $j['state'], 'exit' => $j['code'],
			'duration' => $j['state'] === 'done' ? ($j['ms'] ?? 0) : (int) round((microtime(true) - $j['started']) * 1000),
			'background' => $j['bg'], 'session' => $j['session'], 'stdout' => $j['stdout'], 'stderr' => $j['stderr']);
	}

	/** Jobs of a user (or of one session). */
	static function jobs($owner, $key = null)
	{
		$out = array();
		foreach (self::$jobs as $id => $j) {
			if ((self::$sessions[$j['session']]['owner'] ?? null) !== $owner) continue;
			if ($key !== null && $j['session'] !== $key) continue;
			$x = self::job($id);
			unset($x['stdout'], $x['stderr']);
			$out[] = $x;
		}
		return $out;
	}

	/** The owner hash of a token, as sessions record it. */
	static function owner($token) { return substr(hash('sha256', (string) $token), 0, 32); }

	// ── Commands the server runs for a runner ───────────────────────────

	/** @var Q_WebServer_Shell_Registry|null the registry the server checks requests against */
	private static $registry = null;

	/**
	 * A command a runner asks the server to run with the server's own rights:
	 * the engine's console commands (server, ssl, conf, site, mod, cache,
	 * panel, layout, ext ...) and reading the server's logs.
	 *
	 * The runner itself runs as Q.shell.user, which cannot read the server's
	 * configuration (often root-only), its logs, or signal and reconfigure
	 * it; the engine's own commands are the server administering itself, as
	 * the panel's buttons are. The runner has asked for confirmation; the
	 * tier and the password are checked again here, where a runner cannot
	 * change them. Site code, scripts and OS commands never come this way:
	 * they stay in the runner, as its user.
	 */
	private static function serverRun($id, array $m)
	{
		$j = &self::$jobs[$id];
		if (!empty($j['run'])) return self::runReply($id, 1, 'a server command is already running for this job');
		$args = array();
		foreach (array_slice((array) ($m['args'] ?? array()), 0, 256) as $a) {
			if (!is_scalar($a) || strlen((string) $a) > 8192 || strpos((string) $a, "\0") !== false) return self::runReply($id, 2, 'bad arguments');
			$args[] = (string) $a;
		}
		$kind = (string) ($m['kind'] ?? '');
		if ($kind === 'logs') {
			list($code, $out, $err) = Q_WebServer_Shell_Builtins::logsTail(self::logPaths(), $args);
			if ($out !== '') self::toRunner($id, array('t' => 'run_out', 's' => 'out', 'd' => $out));
			return self::runReply($id, $code, $err !== '' ? rtrim($err, "\n") : null);
		}
		if ($kind !== 'console') return self::runReply($id, 2, 'unknown kind of server command');
		$name = (string) ($m['name'] ?? '');
		$registry = self::registry();
		$spec = $registry ? $registry->get($name) : null;
		if (!$spec || ($spec['source'] ?? '') !== 'console') return self::runReply($id, 127, 'no such server command: ' . $name);
		$tiers = Q_WebServer_Shell_Interpreter::TIERS;
		if (($tiers[$spec['tier']] ?? 3) > ($tiers[$j['tier']] ?? 1)) {
			return self::runReply($id, 126, $spec['name'] . ' is a ' . $spec['tier'] . ' command; this session allows ' . $j['tier']);
		}
		if (!empty($spec['elevate']) && (int) (self::$sessions[$j['session']]['elevatedUntil'] ?? 0) <= time()) {
			return self::runReply($id, 1, $spec['name'] . ' needs the control panel password again (sudo -v)');
		}
		$stdin = (string) ($m['stdin'] ?? '');
		if (strlen($stdin) > 60000) return self::runReply($id, 2, 'too much input for a server command');
		$runner = self::runnerCommand();
		if ($runner === null) return self::runReply($id, 1, self::MISSING);
		// These replace the running server, and this session with it: say so
		// now, since the command's own answer may never arrive.
		$away = array('server:reload' => 'the server reloads; this shell reconnects by itself in a moment',
			'server:restart' => 'the server restarts; this shell reconnects by itself in a moment',
			'server:stop' => 'the server stops; this shell loses its connection');
		if (isset($away[$spec['console']])) {
			self::emit($j['session'], array('t' => 'err', 'id' => $id, 'd' => 'qsh: ' . $away[$spec['console']] . "\n", 'job' => $j['bg']));
		}
		$argv = array_merge($runner, array('--console', $spec['console']), $args, $registry->contextFlags($spec, $args));
		$pipes = array();
		// None of the server's sockets, and only its own trimmed environment:
		// variables the session exported are not handed to the server's rights.
		$proc = @proc_open($argv, Q_WebServer_Shell_Exec::descriptors(array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'))),
			$pipes, null, Q_WebServer_Shell_Exec::environment(array()));
		if (!is_resource($proc)) return self::runReply($id, 1, 'could not start ' . $spec['name']);
		Q_WebServer_Shell_Exec::closeExtra($pipes);
		if ($stdin !== '') @fwrite($pipes[0], $stdin);
		@fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$st = proc_get_status($proc);
		$j['run'] = array('proc' => $proc, 'pipes' => array(1 => $pipes[1], 2 => $pipes[2]), 'pid' => (int) $st['pid'],
			'name' => $spec['name'], 'line' => trim($spec['name'] . ' ' . implode(' ', $args)), 'started' => microtime(true),
			'watchers' => array(), 'errPartial' => '', 'phpErrors' => 0, 'tier' => $spec['tier'], 'stopped' => false);
		foreach (array(1, 2) as $fd) {
			$j['run']['watchers'][$fd] = Q_Evented::onReadable($pipes[$fd], function () use ($id, $fd) { Q_WebServer_Shell_Server::readRun($id, $fd); });
		}
	}

	/** Output from a server command, passed on to the runner that asked. */
	static function readRun($id, $fd)
	{
		if (empty(self::$jobs[$id]['run'])) return;
		$r = &self::$jobs[$id]['run'];
		$chunk = @fread($r['pipes'][$fd], 65536);
		if ($chunk === '' || $chunk === false) {
			if (!feof($r['pipes'][$fd])) return;
			Q_Evented::cancel($r['watchers'][$fd]);
			unset($r['watchers'][$fd]);
			@fclose($r['pipes'][$fd]);
			unset($r['pipes'][$fd]);
			if ($fd === 2 && $r['errPartial'] !== '') {
				$rest = self::filterPhpErrors($r['errPartial'], $r['name'], $r['phpErrors']);
				$r['errPartial'] = '';
				if ($rest !== '') self::toRunner($id, array('t' => 'run_out', 's' => 'err', 'd' => $rest));
			}
			if (!$r['pipes']) self::endRun($id);
			return;
		}
		if ($fd === 1) {
			self::toRunner($id, array('t' => 'run_out', 's' => 'out', 'd' => $chunk));
			return;
		}
		// Standard error, a line at a time, so a PHP warning is recognised.
		$text = $r['errPartial'] . $chunk;
		$cut = strrpos($text, "\n");
		if ($cut === false) { $r['errPartial'] = strlen($text) > 65536 ? '' : $text; if (strlen($text) > 65536) self::toRunner($id, array('t' => 'run_out', 's' => 'err', 'd' => $text)); return; }
		$r['errPartial'] = (string) substr($text, $cut + 1);
		$lines = self::filterPhpErrors(substr($text, 0, $cut + 1), $r['name'], $r['phpErrors']);
		if ($lines !== '') self::toRunner($id, array('t' => 'run_out', 's' => 'err', 'd' => $lines));
	}

	/** A server command's output is over: collect its status, tell the runner. */
	static function endRun($id)
	{
		if (empty(self::$jobs[$id]['run'])) return;
		$r = &self::$jobs[$id]['run'];
		$st = proc_get_status($r['proc']);
		if ($st['running']) {
			Q_Evented::delay(0.05, function () use ($id) { Q_WebServer_Shell_Server::endRun($id); });
			return;
		}
		$exit = proc_close($r['proc']);
		$code = self::exitCode($st, $exit, $r['pid']);
		$note = $r['phpErrors'] > 0
			? $r['name'] . ' reported ' . $r['phpErrors'] . ' PHP warning(s); they are in the server\'s error log'
			: null;
		$j = self::$jobs[$id];
		self::audit($j['session'], '[server] ' . $r['line'], $code, (int) round((microtime(true) - $r['started']) * 1000), $r['tier'], $j['ip']);
		self::$jobs[$id]['run'] = null;
		self::runReply($id, $code, $note);
	}

	/** Stop a server command a job asked for (cancel, timeout, or the runner is gone). */
	static function stopRun($id, $sig = 15)
	{
		if (empty(self::$jobs[$id]['run'])) return;
		$r = &self::$jobs[$id]['run'];
		// It leads a session of its own (qshell.php --console): signal the group.
		if ($r['pid'] > 0 && function_exists('posix_kill')) {
			if (!@posix_kill(-$r['pid'], $sig)) @posix_kill($r['pid'], $sig);
		} else {
			@proc_terminate($r['proc'], $sig);
		}
		if (!$r['stopped'] && $sig !== 9) {
			$r['stopped'] = true;
			Q_Evented::delay(3, function () use ($id) { Q_WebServer_Shell_Server::stopRun($id, 9); });
		}
	}

	/** The end of a server command, to the runner. */
	private static function runReply($id, $code, $error = null)
	{
		self::toRunner($id, array('t' => 'run_exit', 'code' => (int) $code, 'error' => $error));
	}

	/** Queue a message for a runner's standard input, and write what it takes now. */
	static function toRunner($id, array $msg)
	{
		if (!isset(self::$jobs[$id]) || self::$jobs[$id]['state'] !== 'running' || !empty(self::$jobs[$id]['eof'])) return;
		$j = &self::$jobs[$id];
		if (!is_resource($j['pipes'][0] ?? null)) return;
		$j['wbuf'] .= json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
		// A runner that stopped reading: do not hold its output for ever.
		if (strlen($j['wbuf']) > 4 * self::JOB_BUFFER) self::stopRun($id, 15);
		self::flushRunner($id);
	}

	/** Write queued messages while the runner's pipe takes them. */
	static function flushRunner($id)
	{
		if (!isset(self::$jobs[$id])) return;
		$j = &self::$jobs[$id];
		$pipe = $j['pipes'][0] ?? null;
		while ($j['wbuf'] !== '' && is_resource($pipe)) {
			$n = @fwrite($pipe, $j['wbuf']);
			if (!$n) break;
			$j['wbuf'] = (string) substr($j['wbuf'], $n);
		}
		if ($j['wbuf'] !== '' && is_resource($pipe) && $j['wwatch'] === null) {
			$j['wwatch'] = Q_Evented::onWritable($pipe, function () use ($id) { Q_WebServer_Shell_Server::flushRunner($id); });
		} elseif (($j['wbuf'] === '' || !is_resource($pipe)) && $j['wwatch'] !== null) {
			Q_Evented::cancel($j['wwatch']);
			$j['wwatch'] = null;
		}
	}

	/**
	 * PHP's own warnings and errors out of text meant for the terminal: each
	 * goes to the server's error log, and the terminal gets one plain line
	 * for an error that stopped the command (warnings are counted instead).
	 * @param {string} $text
	 * @param {string} $what whose output it is
	 * @param {integer} &$count warnings taken out so far
	 * @return {string} the text without them
	 */
	static function filterPhpErrors($text, $what, &$count)
	{
		$count = (int) $count;
		if (strpos($text, 'PHP ') === false && !preg_match('/^(Warning|Notice|Deprecated|Fatal error|Parse error):/m', $text)) return $text;
		$out = '';
		foreach (preg_split('/(?<=\n)/', $text) as $line) {
			if ($line === '') continue;
			if (preg_match('/^(?:PHP )?(Warning|Notice|Deprecated|Strict Standards|Fatal error|Parse error|Recoverable fatal error):\s+(.*)$/s', rtrim($line, "\n"), $m)) {
				if (class_exists('Q_WebServer_Log', false) && method_exists('Q_WebServer_Log', 'error')) {
					@Q_WebServer_Log::error('shell: ' . $what . ': PHP ' . $m[1] . ': ' . substr($m[2], 0, 2048));
				}
				if (stripos($m[1], 'error') !== false) {
					$out .= 'qsh: ' . $what . ' stopped on a PHP error (the details are in the server\'s error log)' . "\n";
				} else {
					$count++;
				}
				continue;
			}
			$out .= $line;
		}
		return $out;
	}

	/** The registry a runner's request is checked against: the engine's console commands. */
	private static function registry()
	{
		if (self::$registry === null) {
			$dir = self::engineDir();
			if ($dir === null) return null;
			self::$registry = new Q_WebServer_Shell_Registry(array(
				'serverDir' => $dir,
				'startOptions' => (array) Q_Config::get('Q', 'webserver', 'startOptions', array()),
			));
		}
		return self::$registry;
	}

	/** Where the server writes its logs. */
	private static function logPaths()
	{
		return array('access' => Q_WebServer_Log::$accessPath ?? null, 'error' => Q_WebServer_Log::$errorPath ?? null);
	}

	// ── The loop's housekeeping ─────────────────────────────────────────

	private static function ensureTimer()
	{
		if (self::$timer !== null || !class_exists('Q_Evented')) return;
		self::$timer = Q_Evented::repeat(1, function () { Q_WebServer_Shell_Server::tick(); });
	}

	/** Timeouts, escalation after a cancel, and forgetting old jobs and sessions. */
	static function tick()
	{
		$now = microtime(true);
		foreach (self::$jobs as $id => $j) {
			if ($j['state'] === 'running') {
				if ($j['timeout'] > 0 && !$j['bg'] && $now - $j['started'] > $j['timeout'] && $j['killAt'] === null) {
					self::emit($j['session'], array('t' => 'err', 'id' => $id, 'd' => "\nqsh: stopped after " . $j['timeout'] . "s (Q.shell.timeout); run it as a job with & to let it take longer\n"));
					self::signal($id, 'TERM');
				}
				if ($j['killAt'] !== null && $now > $j['killAt']) {
					self::signal($id, 'KILL');
					self::$jobs[$id]['killAt'] = $now + 5;
				}
				continue;
			}
			if (isset($j['ended']) && $now - $j['ended'] > 600) unset(self::$jobs[$id]);
		}
		foreach (self::$sessions as $key => $s) {
			if (time() - $s['seen'] > 3600 && !$s['subs']) {
				$busy = false;
				foreach (self::$jobs as $j) if ($j['session'] === $key && $j['state'] === 'running') { $busy = true; break; }
				if (!$busy) unset(self::$sessions[$key]);
			}
		}
	}

	// ── What runners are told ───────────────────────────────────────────

	/** The runner script: beside the server. */
	/**
	 * How a process opened here ended. The server's SIGCHLD handler reaps
	 * every child it sees, and one it reaps first leaves proc_get_status()
	 * and proc_close() with -1: its status is then the one the handler kept.
	 */
	private static function exitCode(array $st, $exit, $pid)
	{
		if ($st['signaled'] ?? false) return 128 + (int) $st['termsig'];
		if ($st['exitcode'] >= 0) return (int) $st['exitcode'];
		if ($exit >= 0) return (int) $exit;
		$kept = class_exists('Q_WebServer', false) && method_exists('Q_WebServer', 'reapedExit') ? Q_WebServer::reapedExit((int) $pid) : null;
		return $kept !== null ? $kept : 1;
	}

	/** What a job is told when this installation has no runner. */
	const MISSING = 'the shell runner (qshell.php) is missing from this installation, so the shell cannot run commands here; reinstall the server (a source checkout, the phar and the binaries all carry it)';

	/**
	 * The argv that starts the runner, from source, the phar or a binary
	 * (Q_WebServer_Shell_Entry); null when there is none.
	 */
	private static function runnerCommand()
	{
		$start = (array) Q_Config::get('Q', 'webserver', 'startOptions', array());
		return Q_WebServer_Shell_Entry::shell($start['server'] ?? null);
	}

	/** The directory the engine's files are read from (real, or phar://…). */
	private static function engineDir()
	{
		$start = (array) Q_Config::get('Q', 'webserver', 'startOptions', array());
		return Q_WebServer_Shell_Entry::dir($start['server'] ?? null);
	}


	/** The newest history entries, for a runner. */
	private static function historyFor()
	{
		return (new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir()))->page(Q_WebServer_Shell_History::PAGE);
	}

	/**
	 * Answer a question about the history: a page of it (op "page": limit,
	 * before) or a search (op "search": q, before, limit, prefix). For a
	 * runner, the terminal (WebSocket) and the REST API alike.
	 * @method historyQuery
	 * @static
	 * @param {array} $m
	 * @param {integer} [$maxPage] the most entries one answer may hold
	 * @return {array} entries, total
	 */
	static function historyQuery(array $m, $maxPage = null)
	{
		$h = new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir());
		$before = (isset($m['before']) && is_numeric($m['before'])) ? (int) $m['before'] : null;
		if (($m['op'] ?? 'page') === 'search') {
			$entries = $h->search((string) ($m['q'] ?? ''), $before, max(1, min(100, (int) ($m['limit'] ?? 1))), !empty($m['prefix']));
		} else {
			// A runner may ask for all of it (history | grep); a page for the
			// terminal is kept small.
			$max = $maxPage ?? max(Q_WebServer_Shell_History::limit() ?: 1000000, Q_WebServer_Shell_History::PAGE_MAX);
			$entries = $h->page(max(1, min($max, (int) ($m['limit'] ?? Q_WebServer_Shell_History::PAGE))), $before);
		}
		return array('entries' => $entries, 'total' => $h->count());
	}

	/**
	 * A page of history for the terminal: the commands, the number of the
	 * first one, and how many there are in all.
	 * @method historyPage
	 * @static
	 */
	static function historyPage($limit = null, $before = null)
	{
		$r = self::historyQuery(array('op' => 'page', 'limit' => $limit ?? Q_WebServer_Shell_History::PAGE, 'before' => $before),
			Q_WebServer_Shell_History::PAGE_MAX);
		$lines = array();
		foreach ($r['entries'] as $e) $lines[] = $e['c'];
		return array('lines' => $lines, 'first' => $r['entries'] ? $r['entries'][0]['n'] : $r['total'] + 1, 'total' => $r['total']);
	}

	/** The newest entry before $before holding $q, for the terminal's Ctrl-R. */
	static function historySearch($q, $before = null)
	{
		$r = self::historyQuery(array('op' => 'search', 'q' => (string) $q, 'before' => $before, 'limit' => 1));
		return array('q' => (string) $q, 'hit' => $r['entries'] ? $r['entries'][0] : null, 'total' => $r['total']);
	}
	/** The context a runner gets: how the server was started, and its state now. */
	static function context()
	{
		$start = (array) Q_Config::get('Q', 'webserver', 'startOptions', array());
		$engineDir = self::engineDir();
		$settings = array();
		foreach (Q_WebServer_Shell_Settings::TABLE as $name => $t) {
			$settings[$name] = Q_Config::get(...array_merge($t[0], array(null)));
		}
		$pool = Q_WebServer::$pool ?? null;
		if ($pool && isset($pool->targetSize)) $settings['workers'] = (int) $pool->targetSize;
		$runtime = class_exists('Q_WebServer_Dashboard') ? Q_WebServer_Dashboard::getStats() : array();
		if (class_exists('Q_WebServer_Cache', false)) $runtime['cache'] = Q_WebServer_Cache::stats();
		if ($pool && method_exists($pool, 'workerStats')) $runtime['pool'] = $pool->workerStats();
		$runtime['logs'] = array('access' => Q_WebServer_Log::$accessPath ?? null, 'error' => Q_WebServer_Log::$errorPath ?? null);
		$themes = array();
		foreach (Q_WebServer_Shell::themes() as $n => $t) $themes[$n] = (string) ($t['label'] ?? $n);
		// The per-user files stay the server's: runners get them in the request.
		$dir = Q_WebServer_Shell::dataDir();
		if (!is_dir($dir)) @mkdir($dir, 0700, true);
		return array(
			'serverDir' => $engineDir !== null ? $engineDir : dirname(__DIR__, 4),
			'startOptions' => $start,
			'confDirs' => (array) Q_Config::get('Q', 'webserver', 'confDirs', array()),
			'shellDir' => Q_WebServer_Shell::dataDir(),
			'panelFile' => class_exists('Q_WebServer_Panel') ? Q_WebServer_Panel::panelConfigPath() : null,
			'scriptsDir' => Q_WebServer_Shell::config('scriptsDir'),
			'themes' => $themes,
			'runtime' => $runtime,
			'settings' => $settings,
			'runtimeChanged' => array_keys(self::$changed),
		);
	}

	/**
	 * Apply a setting a runner asked for (set name=value, workers resize).
	 * @return {string|null} an error
	 */
	static function apply($name, $value)
	{
		if (!isset(Q_WebServer_Shell_Settings::TABLE[$name])) return 'unknown setting ' . $name;
		$t = Q_WebServer_Shell_Settings::TABLE[$name];
		if ($t[5]) return $name . ' cannot be changed from the shell';
		if ($t[2] !== 'runtime') return null;
		// Checked again here: the runner runs as another user, so what it
		// sends is a request, not a decision.
		if (!is_scalar($value)) return 'bad value for ' . $name;
		list($value, $error) = Q_WebServer_Shell_Settings::parse($name, is_bool($value) ? ($value ? 'on' : 'off') : (string) $value);
		if ($error !== null) return $error;
		if ($name === 'workers') {
			$pool = Q_WebServer::$pool ?? null;
			if (!$pool || !method_exists($pool, 'resize')) return 'no worker pool to resize (fork-per-request mode)';
			$pool->resize((int) $value);
		}
		Q_Config::set(...array_merge($t[0], array($value)));
		self::$changed[$name] = true;
		return null;
	}

	// ── Audit ───────────────────────────────────────────────────────────

	/** Record a command: who, where, what, how it ended. */
	static function audit($key, $line, $code, $ms, $tier, $ip = null, $system = false)
	{
		$s = self::$sessions[$key] ?? array();
		$rec = array('time' => gmdate('c'), 'user' => 'panel', 'session' => substr((string) $key, 0, 8),
			'ip' => (string) ($ip ?? ($s['ip'] ?? '')), 'tier' => (string) $tier, 'line' => (string) $line,
			'exit' => (int) $code, 'ms' => (int) $ms, 'system' => $system || (bool) preg_match('/^\s*(!\s|sys\s)/', (string) $line));
		self::$audit[] = $rec;
		if (count(self::$audit) > 50) array_shift(self::$audit);
		// Beside the shell's directory, not in it: the shell user owns that
		// directory and must not be able to rewrite the record of what it did.
		$file = Q_WebServer_Shell::auditFile();
		if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
		if (is_file($file) && filesize($file) > self::AUDIT_ROTATE) @rename($file, $file . '.1');
		@file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
		@chmod($file, 0600);
		if (class_exists('Q_WebServer_Log', false) && method_exists('Q_WebServer_Log', 'error') && $rec['system']) {
			// OS commands also reach the server's own log.
			@Q_WebServer_Log::error('shell: OS command from ' . $rec['ip'] . ': ' . $rec['line']);
		}
	}

	/** The latest commands, newest first, for the dashboard. */
	static function recentAudit($n = 8)
	{
		return array_slice(array_reverse(self::$audit), 0, $n);
	}


	/**
	 * The dashboard's "Shell activity" card: sessions, running jobs, how many
	 * commands and OS commands this process has seen, and the latest few.
	 * Lines are cut short; the audit log holds them whole.
	 */
	static function summary()
	{
		$running = 0;
		foreach (self::$jobs as $j) {
			if ($j['state'] === 'running') $running++;
		}
		$recent = array();
		foreach (self::recentAudit(5) as $r) {
			$line = $r['line'];
			if (strlen($line) > 60) $line = substr($line, 0, 57) . '...';
			$recent[] = array('time' => $r['time'], 'ip' => $r['ip'], 'line' => $line,
				'exit' => $r['exit'], 'ms' => $r['ms'], 'system' => $r['system']);
		}
		$system = 0;
		foreach (self::$audit as $r) {
			if ($r['system']) $system++;
		}
		return array('enabled' => Q_WebServer_Shell::enabled(), 'sessions' => count(self::$sessions),
			'running' => $running, 'commands' => count(self::$audit), 'system' => $system,
			'allowSystem' => (bool) Q_WebServer_Shell::config('allowSystem'), 'recent' => $recent);
	}
	// ── Completion ──────────────────────────────────────────────────────

	/** What completion knows: from the runner's --meta, cached for five minutes. */
	static function meta()
	{
		if (self::$meta === null || time() - self::$metaAt > 300) {
			$runner = self::runnerCommand();
			self::$metaAt = time();
			self::$meta = array('commands' => array(), 'builtins' => array_keys(Q_WebServer_Shell_Builtins::DOCS),
				'settings' => array_keys(Q_WebServer_Shell_Settings::TABLE), 'aliases' => array());
			if ($runner) {
				$start = (array) Q_Config::get('Q', 'webserver', 'startOptions', array());
				$argv = array_merge($runner, array('--meta'));
				foreach (array('config' => 'config', 'conf-dir' => 'confDir', 'root' => 'root', 'distribution' => 'distribution') as $o => $k) {
					if (!empty($start[$k])) $argv[] = '--' . $o . '=' . $start[$k];
				}
				$pipes = array();
				$p = @proc_open($argv, Q_WebServer_Shell_Exec::descriptors(array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'))), $pipes, null, Q_WebServer_Shell_Exec::environment(array()));
				Q_WebServer_Shell_Exec::closeExtra($pipes);
				if (is_resource($p)) {
					// Bounded: completion must never hold the loop for long.
					stream_set_timeout($pipes[1], 3);
					$json = stream_get_contents($pipes[1]);
					fclose($pipes[1]); fclose($pipes[2]);
					proc_close($p);
					$m = json_decode((string) $json, true);
					if (is_array($m)) self::$meta = $m;
				}
			}
		}
		return self::$meta;
	}

	/**
	 * Completions for a line at a cursor position.
	 * @return {array} array('start' => offset the items replace from, 'items' => list of array('v', 'd'))
	 */
	static function complete($key, $line, $pos)
	{
		$line = (string) $line;
		$pos = max(0, min(strlen($line), (int) $pos));
		$before = substr($line, 0, $pos);
		// The word under the cursor, and the words before it on this command.
		$cmdStart = max(strrpos($before, '|') ?: -1, strrpos($before, ';') ?: -1, strrpos($before, '&') ?: -1) + 1;
		$segment = ltrim(substr($before, $cmdStart));
		$words = preg_split('/\s+/', $segment);
		$cur = (string) array_pop($words);
		$start = $pos - strlen($cur);
		$meta = self::meta();
		$items = array();
		$add = function ($v, $d = '') use (&$items, $cur) {
			if ($cur === '' || strncmp($v, $cur, strlen($cur)) === 0) $items[$v] = array('v' => $v, 'd' => $d);
		};
		$aliases = (new Q_WebServer_Shell_History(Q_WebServer_Shell::dataDir()))->aliases();
		if (!$words) {
			foreach ($meta['builtins'] as $b) $add($b, 'built-in');
			foreach ($aliases as $a => $t) $add($a, 'alias: ' . $t);
			foreach ((array) $meta['commands'] as $c) {
				$first = explode(' ', $c['name'])[0];
				$add($first, strpos($c['name'], ' ') === false ? $c['description'] : '');
				if (!empty($c['console'])) $add($c['console'], $c['description']);
				foreach ((array) $c['aliases'] as $al) $add($al, $c['description']);
			}
		} else {
			$first = $words[0];
			if (in_array($first, array('get', 'set', 'unset'), true) && strpos($cur, '-') !== 0) {
				foreach ($meta['settings'] as $sName) $add($first === 'set' ? $sName . '=' : $sName, 'setting');
				if ($first === 'get') $add('all', 'every setting');
			} elseif (in_array($first, array('theme'), true)) {
				if (count($words) === 1) { $add('list'); $add('use'); $add('load'); }
				else foreach (Q_WebServer_Shell::themes() as $n => $t) $add($n, (string) ($t['label'] ?? ''));
			} elseif (in_array($first, array('exec', 'source'), true)) {
				foreach (array(Q_WebServer_Shell::dataDir(), Q_WebServer_Shell::config('scriptsDir')) as $dir) {
					if ($dir && is_dir($dir)) foreach ((array) @scandir($dir) as $f) {
						if (is_string($f) && $f[0] !== '.' && is_file("$dir/$f") && preg_match('/\.(qsh|sh|cfg)$/', $f)) $add($f, 'script');
					}
				}
			} elseif (in_array($first, array('help', 'man', 'type', 'which'), true)) {
				foreach ((array) $meta['commands'] as $c) $add($c['name'], $c['description']);
				foreach ($meta['builtins'] as $b) $add($b, 'built-in');
			} elseif ($first === 'layout') {
				$add('vertical'); $add('horizontal');
			} else {
				$joined = implode(' ', $words);
				foreach ((array) $meta['commands'] as $c) {
					// The verb of a noun-verb command.
					if (count($words) === 1 && strpos($c['name'], $first . ' ') === 0) $add(substr($c['name'], strlen($first) + 1), $c['description']);
					// Options of the command named so far.
					$named = $c['name'] === $joined || $c['name'] === $first || ($c['console'] ?? null) === $first
						|| (count($words) >= 2 && $c['name'] === $words[0] . ' ' . $words[1]) || in_array($first, (array) $c['aliases'], true);
					if ($named && strpos($cur, '-') === 0) foreach ((array) $c['options'] as $o) $add((strlen($o) === 1 ? '-' : '--') . $o, '');
				}
			}
		}
		ksort($items);
		return array('start' => $start, 'items' => array_values(array_slice($items, 0, 200)));
	}
}
