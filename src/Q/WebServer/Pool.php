<?php
/**
 * @module Q
 */

/**
 * Pre-fork worker pool for PHP script execution.
 *
 * Each worker handles ONE request, then exits. The parent
 * maintains N idle workers at all times. When one finishes,
 * a replacement is forked immediately.
 *
 * Why one-request-per-process:
 *   PHP has no way to fully reset static state — Foo::$bar,
 *   DB connections, registered shutdown functions, output
 *   buffers all persist. The only clean reset is process exit.
 *
 * Why this is fast:
 *   fork() on Linux uses copy-on-write. The child inherits
 *   all loaded classes, opcache, config — everything the
 *   parent loaded during bootstrap — without copying memory.
 *   Cost: ~0.5ms per fork.
 *
 * Important: the parent must NOT open DB connections or
 * stateful resources before forking. Q's DB connections are
 * lazy (opened on first query), so this is natural.
 *
 *   Parent (event loop, Q.inc.php loaded)
 *     ├── Worker 0 [idle, waiting on socketpair]
 *     ├── Worker 1 [busy, processing request]  → exits → replacement forked
 *     ├── Worker 2 [idle]
 *     └── Worker 3 [idle]
 *
 * @class Q_WebServer_Pool
 */
class Q_WebServer_Pool
{
	public $targetSize;

	/**
	 * Spare workers to keep when the pool is dynamic; 0 for a static pool
	 * that forks all $targetSize workers at start (the default).
	 *
	 * Every worker costs its own memory from the moment it is forked -- on an
	 * Exponential install ~10 MB of real RAM each, idle or not, because the
	 * kernel copies that much of the warmed parent at fork. A pool of 590
	 * workers that serves a few dozen requests at a time was paying for 550
	 * that did nothing. A dynamic pool forks $spareWorkers at start, forks
	 * more on demand up to $targetSize, and retires the ones above the spare
	 * count once they have been idle for $idleTimeout seconds.
	 *
	 * @property $spareWorkers
	 * @type integer
	 */
	public $spareWorkers = 0;

	/** Seconds an extra worker may sit idle before it is retired (dynamic pool). */
	public $idleTimeout = 60;

	/**
	 * Workers that were told to go but had not exited yet when asked, by pid.
	 * A worker is reaped without waiting (WNOHANG) so the event loop never
	 * blocks on it; one that is still on its way out would otherwise stay a
	 * zombie for the life of the server. A timer retries these.
	 */
	protected $unreaped = array();

	/**
	 * Reap a worker that has been told to exit, now if it already has,
	 * otherwise later.
	 */
	protected function reap($pid)
	{
		if ($pid <= 0) return;
		$r = Q_WebServer_Fork::waitpid($pid, $st, 1);
		if ($r === 0) $this->unreaped[$pid] = true;
	}

	/**
	 * Retry the workers that had not exited when first reaped.
	 * @method reapPending
	 * @return {integer} how many are still outstanding
	 */
	function reapPending()
	{
		foreach (array_keys($this->unreaped) as $pid) {
			$r = Q_WebServer_Fork::waitpid($pid, $st, 1);
			if ($r !== 0) unset($this->unreaped[$pid]);   // reaped, or not ours any more
		}
		return count($this->unreaped);
	}

	/**
	 * Whether a worker process is gone, without waiting. Reaps it if not yet.
	 *
	 * "Gone" is anything but "still running" (0). The server's SIGCHLD
	 * handler reaps every child with waitpid(-1) -- pool workers included --
	 * so by the time the pool asks about a dead worker the answer is usually
	 * -1 (no such child), not its pid. Testing for the pid alone therefore
	 * called a dead worker alive, and a request was sent to it.
	 *
	 * @method hasExited
	 * @static
	 * @param {integer} $pid
	 * @return {boolean}
	 */
	static function hasExited($pid)
	{
		if ($pid <= 0) return true;
		return Q_WebServer_Fork::waitpid($pid, $st, 1) !== 0;
	}

	/**
	 * In a freshly forked worker: close every socket stream except $keep.
	 *
	 * Only sockets. Files the parent has open (logs, a warm-up's handles)
	 * are harmless to share and may be in use; STDIN/STDOUT/STDERR are
	 * files or pipes and are kept regardless.
	 *
	 * @method closeInheritedSockets
	 * @static
	 * @param {resource} $keep the worker's end of its pair
	 * @return {integer} how many were closed
	 */
	static function closeInheritedSockets($keep)
	{
		if (!function_exists('get_resources')) return 0;
		$keepId = (int) $keep;
		$std = array();
		foreach (array('STDIN', 'STDOUT', 'STDERR') as $c) {
			if (defined($c)) $std[(int) constant($c)] = true;
		}
		$closed = 0;
		foreach (get_resources('stream') as $id => $res) {
			if ($id === $keepId or isset($std[$id])) continue;
			$meta = @stream_get_meta_data($res);
			$type = strtolower((string) ($meta['stream_type'] ?? ''));
			if (strpos($type, 'socket') === false) continue;
			// Never fclose() a TLS stream here: that writes close_notify onto
			// the parent's live connection. See Q_WebServer::releaseInherited().
			if (method_exists('Q_WebServer', 'releaseInherited')) {
				if (Q_WebServer::releaseInherited($res)) ++$closed;
				continue;
			}
			$meta2 = @stream_get_meta_data($res);
			if (!empty($meta2['crypto'])) continue;
			@fclose($res);
			++$closed;
		}
		return $closed;
	}

	/** Times a request may go back on the queue because its worker was dead. */
	const MAX_REQUEUES = 3;

	/** Script path each busy worker was sent, so its request can be re-sent. */
	protected $workerScripts = array();

	/**
	 * Whether a request whose worker died without answering may be run again
	 * on another worker: only methods that change nothing, and only once.
	 *
	 * @method mayRetry
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function mayRetry($parsed)
	{
		$method = strtoupper((string) ($parsed['method'] ?? ''));
		return in_array($method, array('GET', 'HEAD', 'OPTIONS'), true)
			and (int) ($parsed['poolRetries'] ?? 0) < 1;
	}

	/**
	 * Forget everything held for a worker. One place, so no per-worker array
	 * is missed: the parent lives as long as the server, and an entry left
	 * behind for each worker ever replaced is memory it never gets back.
	 */
	protected function forgetWorker($index)
	{
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
		}
		unset($this->workers[$index], $this->workerClients[$index],
			$this->workerBuffers[$index], $this->workerRequestHeaders[$index],
			$this->workerRequests[$index], $this->workerResponders[$index],
			$this->workerStarted[$index], $this->workerScripts[$index],
			$this->watchers[$index]);
	}

	protected $workers = array();       // index => [pid, socket, busy]
	protected $workerClients = array(); // index => HTTP client socket
	protected $workerBuffers = array(); // index => partial response data
	protected $watchers = array();      // index => Q_Evented watcher id
	protected $pending = array();       // queued [client, parsed, scriptPath, responder]
	protected $nextIndex = 0;
	protected static $inputWrapperRegistered = false;

	/**
	 * Whether workers persist between requests, rather than one per request.
	 *
	 * Declared, along with $maxRequests below, because PHP 8.2 deprecates
	 * creating a property by assigning to it. The constructor assigned both,
	 * so every start emitted two deprecation notices -- harmless where they
	 * are only displayed, and fatal where the installation has chosen to treat
	 * a deprecation as an error, which is what stopped the server on FreeBSD
	 * after the banner had already been printed.
	 *
	 * @property $octane
	 * @type {boolean}
	 */
	protected $octane = true;

	/**
	 * How many requests a persistent worker serves before retiring itself.
	 * @property $maxRequests
	 * @type {integer}
	 */
	protected $maxRequests = 1000;

	/**
	 * The zygote: a process forked once the pool has started, before the
	 * server has accepted any connection, that forks every later worker.
	 * array('pid' => int, 'ctl' => Socket) while it serves; null otherwise.
	 *
	 * A worker forked from the server itself inherits every client
	 * connection open at that moment. Plain ones are closed at once; a TLS
	 * stream cannot be (closing it writes close_notify onto the server's own
	 * live connection), so the worker parks it until it exits. Under load
	 * the dynamic pool forks as workers become busy, and each new worker
	 * kept every open connection alive: on alpha a 20-second burst of 355
	 * rendered pages left up to 50 connections in CLOSE-WAIT, held by
	 * workers only, until those workers were retired. Forked from the
	 * zygote, which never held a client connection, a worker inherits none.
	 *
	 * Q.webserver.zygote (on by default; false forks from the server as before); without ext-sockets'
	 * SCM_RIGHTS, or if the zygote fails, workers are forked from the server
	 * as before.
	 *
	 * @property $zygote
	 * @type {array|null}
	 */
	protected $zygote = null;

	/**
	 * Whether this pool may fork its workers from a zygote.
	 * @method zygoteSupported
	 * @static
	 * @return {boolean}
	 */
	static function zygoteSupported()
	{
		return function_exists('socket_create_pair') and function_exists('socket_sendmsg')
			and function_exists('socket_recvmsg') and function_exists('socket_import_stream')
			and function_exists('socket_export_stream') and function_exists('socket_cmsg_space')
			and function_exists('pcntl_fork') and function_exists('posix_kill')
			and defined('SCM_RIGHTS') and defined('AF_UNIX');
	}

	/**
	 * Whether a process is running: signal 0 reaches it and it is not a
	 * zombie. For a worker the zygote forked, which is not this process's
	 * child, so waitpid() cannot answer (see hasExited()).
	 * @method processAlive
	 * @static
	 * @param {integer} $pid
	 * @return {boolean}
	 */
	static function processAlive($pid)
	{
		if ($pid <= 0 or !function_exists('posix_kill')) return false;
		if (!@posix_kill($pid, 0)) return false;
		// A zombie still answers signal 0. The zygote reaps its children as
		// they exit, but not in the same instant.
		$stat = @file_get_contents('/proc/' . $pid . '/stat');
		if (is_string($stat) and ($p = strrpos($stat, ')')) !== false) {
			$state = substr($stat, $p + 2, 1);
			if ($state === 'Z' or $state === 'X') return false;
		}
		return true;
	}

	/**
	 * Whether a worker is gone, asked the way that works for it: waitpid()
	 * for this process's own children, processAlive() for the zygote's.
	 * @method workerGone
	 * @param {array} $w a worker entry
	 * @return {boolean}
	 */
	protected function workerGone($w)
	{
		$pid = (int) ($w['pid'] ?? 0);
		if ($pid <= 0) return true;
		return empty($w['zygote']) ? self::hasExited($pid) : !self::processAlive($pid);
	}

	/**
	 * Reap a worker told to exit -- unless the zygote forked it, in which
	 * case it is the zygote's to reap.
	 * @method reapWorker
	 * @param {array} $w a worker entry
	 */
	protected function reapWorker($w)
	{
		if (!empty($w['zygote'])) return;
		$this->reap((int) ($w['pid'] ?? 0));
	}

	/**
	 * @method __construct
	 * @param {integer} [$size=4]
	 */
	function __construct($size = null)
	{
		// Load Fork abstraction (pcntl on Unix, FFI+dll on Windows)
		$forkFile = dirname(__DIR__) . '/WebServer/Fork.php';
		if (!class_exists('Q_WebServer_Fork', false) && is_file($forkFile)) {
			require_once $forkFile;
		}
		// The response capture buffer. Loaded here, in the parent and before
		// the source transform is installed, so every worker inherits it.
		if (!class_exists('Q_WebServer_Capture', false)) {
			require_once dirname(__DIR__) . '/WebServer/Capture.php';
		}
		if (!Q_WebServer_Fork::available()) {
			throw new Exception(
				"Q_WebServer_Pool requires fork capability. "
				. "Install pcntl (Linux/macOS) or qbix_fork.dll (Windows). "
				. "Or use --workers=0 for php-cgi mode."
			);
		}
		$this->targetSize = $size ?: (int) Q_Config::get(
			'Q', 'webserver', 'workers', 4
		);
		$this->spareWorkers = max(0, min($this->targetSize,
			(int) Q_Config::get('Q', 'webserver', 'spareWorkers', 0)));
		$this->idleTimeout = max(1, (int) Q_Config::get('Q', 'webserver', 'idleWorkerTimeout', 60));
		$this->octane = !Q_Config::get(
			'Q', 'webserver', 'forkPerRequest', false
		);
		$this->maxRequests = (int) Q_Config::get(
			'Q', 'webserver', 'maxRequests', 1000
		);

		// Compat on by default unless explicitly disabled, in either mode.
		// It shims the lifecycle functions for shared-nothing safety, which
		// only a persistent worker needs -- but it also shims header() and
		// setcookie(), which every worker needs. Those are no-ops under the
		// CLI SAPI, so without the shim headers_list() comes back empty and
		// the response goes out with neither Set-Cookie nor Location: the
		// credentials are accepted, the redirect is issued, and the session
		// cookie never reaches the browser.
		// Q_WebServer_Compat::init() returns immediately when compat is
		// configured off, so the decision stays with the config key and is
		// not implied by the fork mode.
		$compatSet = Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', null);
		if ($compatSet === null) {
			Q_Config::set('Q', 'compat', 'skipSourceCodeTransform', false);
		}
		$compatFile = dirname(__DIR__) . '/WebServer/Compat.php';
		if (!class_exists('Q_WebServer_Compat', false) && is_file($compatFile)) {
			require_once $compatFile;
		}
		if (class_exists('Q_WebServer_Compat', false)) {
			Q_WebServer_Compat::init();
		}

		// Parent-side warm-up, before the snapshot and before the fork.
		//
		// This is the one lever that moves per-worker memory. A worker that
		// bootstraps a heavy framework on its first request builds that state in
		// its own heap -- private to it, paid once per worker. Measured on this
		// installation each warm worker held ~209 MB, so 64 of them was ~13 GB of
		// the same kernel loaded 64 times.
		//
		// Loading it HERE, in the parent, before the children exist, means fork()
		// hands every worker the same pages copy-on-write. They stay shared until
		// a worker writes to them, and a warmed framework is mostly read -- class
		// tables, parsed configuration, type registries -- so the shared fraction
		// is large and the private residue small.
		//
		// The warm-up is a script named by Q.webserver.warmup, run once in an
		// isolated scope. It is off unless configured, because what is safe to
		// load before a fork is application-specific: loading classes and parsing
		// configuration is fork-safe, but a database handle opened here would be
		// shared by every child and corrupt. The script's job is to warm the
		// former and open none of the latter; the app provides it.
		//
		// It has its own key, and must. It was first read from
		// Q.webserver.preload, which already meant something else: an autoloader
		// that Q_WebServer::start() requires before the pool exists -- before
		// Compat::init() above has installed the source transform. The script
		// therefore ran twice, and the first run compiled everything it loaded
		// with no transform at all. A class is compiled once and every forked
		// worker inherits it, so the whole kernel kept its real exit and its real
		// header(): exit ended the worker instead of the request (the AJAX
		// endpoints, which finish with eZExecution::cleanExit(), answered
		// "Worker died"), and header() under the CLI SAPI did nothing. Files a
		// worker loaded for itself were transformed as usual, which is why only
		// some paths broke. Here, after init(), the script's code is compiled
		// through the wrapper like everything else.
		$warmup = Q_Config::get('Q', 'webserver', 'warmup', null);
		if (is_string($warmup) && $warmup !== '' && is_file($warmup)) {
			$before = memory_get_usage(true);
			$__run = static function ($__warmupFile) {
				require $__warmupFile;
			};
			try {
				$__run($warmup);
				// The warm-up is a request as far as the file wrapper is
				// concerned: it remembered the stat of every file it included,
				// and those memos are good for that request only. Nothing ended
				// it, so every worker inherited them and compared a file
				// rewritten after start against the warm-up's mtime -- found
				// it unchanged, and ran the old code. In fork-per-request mode
				// that was every request until a restart: a template edit
				// reached Apache at once and this server not at all.
				if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
					Q_WebServer_CompatFileWrapper::forgetStats();
				}
				$grew = (memory_get_usage(true) - $before) / 1048576;
				fwrite(STDERR, sprintf(
					"  warm-up %s warmed %.1f MB in the parent (shared by every worker)\n",
					basename($warmup), $grew));
			} catch (\Throwable $e) {
				// A warm-up that throws must not stop the server from starting --
				// the workers can still warm themselves lazily, the old way.
				if (class_exists('Q_WebServer_Log', false)) {
					Q_WebServer_Log::error('warm-up ' . basename($warmup)
						. ' failed, workers will warm lazily: ' . $e->getMessage());
				}
			}
		}

		if ($this->octane) {
			$snapFile = dirname(__DIR__) . '/WebServer/Snapshot.php';
			if (!class_exists('Q_WebServer_Snapshot', false) && is_file($snapFile)) {
				require_once $snapFile;
			}
			if (class_exists('Q_WebServer_Snapshot', false)) {
				$n = Q_WebServer_Snapshot::take();
			}
		}
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGCHLD, SIG_DFL);
		}
		$initial = $this->spareWorkers > 0 ? $this->spareWorkers : $this->targetSize;
		for ($i = 0; $i < $initial; $i++) {
			$this->forkWorker();
		}
		// Still before the first connection is accepted: the zygote forked
		// now holds no client, and neither will any worker it forks.
		if (Q_Config::get('Q', 'webserver', 'zygote', true) and self::zygoteSupported()) {
			$this->startZygote();
		}
		$pool = $this;
		Q_Evented::repeat(2.0, function () use ($pool) {
			$pool->reapPending();
			$pool->sweepDeadIdle();
		});
		if ($this->spareWorkers > 0) {
			Q_Evented::repeat(min(10.0, max(1.0, $this->idleTimeout / 4)), function () use ($pool) {
				$pool->retireIdleExtras();
			});
		}
	}

	/**
	 * Dynamic pool: retire workers above the spare count that have been idle
	 * for longer than the idle timeout. Longest-idle first. Never touches a
	 * busy worker, and never goes below the spare count.
	 *
	 * @method retireIdleExtras
	 * @return {integer} how many were retired
	 */
	function retireIdleExtras()
	{
		if ($this->spareWorkers <= 0) return 0;
		$now = microtime(true);
		$idle = array();
		foreach ($this->workers as $i => $w) {
			if (empty($w['busy'])) $idle[$i] = $w['idleSince'] ?? $now;
		}
		asort($idle);
		$retired = 0;
		foreach ($idle as $i => $since) {
			if (count($this->workers) <= $this->spareWorkers) break;
			if ($now - $since < $this->idleTimeout) break;
			$this->retire($i);
			$retired++;
		}
		return $retired;
	}

	/**
	 * Take an idle worker out of the pool without forking a replacement.
	 */
	protected function retire($index)
	{
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
			unset($this->watchers[$index]);
		}
		if (isset($this->workers[$index])) {
			$sock = $this->workers[$index]['socket'];
			// Closing its socket ends the worker: its blocking read returns
			// and childRun() leaves the loop.
			if (is_resource($sock)) @fclose($sock);
			$this->reapWorker($this->workers[$index]);
		}
		$this->forgetWorker($index);
	}

	/**
	 * Fork one worker. Child inherits parent's loaded state
	 * via copy-on-write.
	 * @method forkWorker
	 * @return {integer} Worker index
	 */
	protected function forkWorker()
	{
		if ($this->zygote !== null) {
			$index = $this->forkWorkerViaZygote();
			if ($index !== null) return $index;
			// The zygote failed; it is gone and this fork falls through to
			// the server, as before the zygote existed.
		}

		// STREAM_PF_UNIX doesn't exist on Windows — use INET loopback
		$family = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair(
			$family, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
		);
		if (!$pair) throw new Exception("socketpair failed");

		$pid = Q_WebServer_Fork::fork();
		if ($pid === -1 || $pid === false) throw new Exception("fork failed");

		if ($pid === 0) {
			// ── CHILD ──
			//
			// fork() hands the child the parent's entire descriptor table. The
			// worker needs one descriptor -- its half of this pair -- and this
			// used to close exactly one: the other half. Everything else came
			// with it.
			//
			// What it inherited: the listening socket, the TLS listener, the
			// Unix socket, every client connection the parent had open at that
			// instant, and the parent's end of every *other* worker's socket
			// pair. A worker could therefore read another visitor's request,
			// and another worker's replies, on an installation serving one
			// site with one tenant. It also kept the listening socket alive
			// after the parent closed it.
			//
			// Order matters only in that all of it must happen before the
			// worker runs any application code.
			fclose($pair[0]);

			// The parent's end of every worker forked before this one.
			foreach ($this->workers as $other) {
				if (isset($other['socket']) and is_resource($other['socket'])) {
					@fclose($other['socket']);
				}
			}
			$this->workers = array();
			$this->watchers = array();

			// Listeners and client connections live on the server, not here.
			if (method_exists('Q_WebServer', 'closeInheritedDescriptors')) {
				Q_WebServer::closeInheritedDescriptors();
			}

			// And every other socket, whoever held it. The lists above are
			// the ones the server tracks; a worker forked while a request is
			// in flight -- a replacement, or a dynamic pool growing -- also
			// inherited that request's client, the clients of other busy
			// workers and of queued requests, none of which are on them. The
			// visitor then never saw the connection close (the worker held
			// it open, idle, indefinitely), and a worker could write to
			// another visitor's socket. Closing by kind rather than by list
			// cannot miss one that some future path forgets to register.
			self::closeInheritedSockets($pair[1]);

			// Whatever the parent had remembered about files -- from the
			// warm-up, or anything it included since -- describes the moment
			// of the fork, not this worker's first request. Stats are looked
			// up again; kept bytes and transforms are then checked against
			// them, so an entry for a file changed since is not used.
			if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
				Q_WebServer_CompatFileWrapper::forgetStats(true);
				// A worker has request boundaries, so it may also remember
				// which paths exist; see existsAndIsDir().
				Q_WebServer_CompatFileWrapper::rememberExistence(true);
			}

			self::childRun($pair[1], $this->octane, $this->maxRequests);
			exit(0);
		}

		// ── PARENT ──
		fclose($pair[1]);
		return $this->registerWorker($pid, $pair[0], false);
	}

	/**
	 * Enter a freshly forked worker in the pool: its end of the pair, idle,
	 * watched but not yet listened to.
	 * @method registerWorker
	 * @param {integer} $pid
	 * @param {resource} $sock the server's end of the worker's pair
	 * @param {boolean} $viaZygote whether the zygote forked it
	 * @return {integer} Worker index
	 */
	protected function registerWorker($pid, $sock, $viaZygote)
	{
		stream_set_blocking($sock, false);

		$index = $this->nextIndex++;
		$this->workers[$index] = array(
			'pid' => $pid, 'socket' => $sock, 'busy' => false,
			'idleSince' => microtime(true), 'zygote' => (bool) $viaZygote
		);

		$pool = $this;
		$this->watchers[$index] = Q_Evented::onReadable(
			$sock,
			function ($s) use ($pool, $index) {
				$pool->onWorkerData($index, $s);
			}
		);
		Q_Evented::disable($this->watchers[$index]);
		return $index;
	}

	// ── Zygote ───────────────────────────────────────────

	/**
	 * Fork the zygote (see $zygote). Called at the end of the constructor,
	 * before the server accepts its first connection.
	 * @method startZygote
	 * @return {boolean} whether it runs
	 */
	protected function startZygote()
	{
		$ctl = array();
		if (!@socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ctl)) return false;
		$pid = Q_WebServer_Fork::fork();
		if ($pid === -1 || $pid === false) {
			socket_close($ctl[0]);
			socket_close($ctl[1]);
			return false;
		}
		if ($pid === 0) {
			// ── ZYGOTE ──
			// The hygiene a worker gets, so what the zygote passes on is
			// clean: not the server's end of any worker's pair, not the
			// listeners, not any other socket stream.
			socket_close($ctl[0]);
			foreach ($this->workers as $w) {
				if (isset($w['socket']) and is_resource($w['socket'])) @fclose($w['socket']);
			}
			$this->workers = array();
			$this->watchers = array();
			if (method_exists('Q_WebServer', 'closeInheritedDescriptors')) {
				Q_WebServer::closeInheritedDescriptors();
			}
			self::closeInheritedSockets(null);
			self::zygoteMain($ctl[1], $this->octane, $this->maxRequests);
			exit(0);
		}
		socket_close($ctl[1]);
		// A reply is four bytes; never wait long on a zygote that stopped.
		@socket_set_option($ctl[0], SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
		$this->zygote = array('pid' => $pid, 'ctl' => $ctl[0]);
		return true;
	}

	/**
	 * The zygote's life: receive a worker's end of a socket pair from the
	 * server, fork a worker onto it, answer with the worker's pid; reap
	 * workers as they exit; leave when the server closes the control socket.
	 * @method zygoteMain
	 * @static
	 * @param {Socket} $ctl
	 * @param {boolean} $octane
	 * @param {integer} $maxReqs
	 */
	protected static function zygoteMain($ctl, $octane, $maxReqs)
	{
		// Named, so `ps` (and a test) can tell it from the workers. Each
		// worker it forks puts the server's own command line back.
		$title = implode(' ', (array) ($_SERVER['argv'] ?? array()));
		if (function_exists('cli_set_process_title')) @cli_set_process_title('qbixserver: zygote');
		if (function_exists('pcntl_async_signals')) pcntl_async_signals(true);
		if (function_exists('pcntl_signal')) {
			// Not restarting the interrupted call ($restart_syscalls false):
			// the zygote spends its life blocked in socket_recvmsg(), and a
			// restarted call keeps the handler waiting for the next message --
			// every worker that exited meanwhile stayed a zombie until then.
			// Interrupted, recvmsg() returns EINTR and the loop goes round.
			pcntl_signal(SIGCHLD, function () {
				while (pcntl_waitpid(-1, $st, WNOHANG) > 0) {}
			}, false);
			pcntl_signal(SIGTERM, function () { exit(0); }, false);
			pcntl_signal(SIGHUP, SIG_IGN);
		}
		$space = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1);
		while (true) {
			$msg = array('name' => array(), 'buffer_size' => 8, 'controllen' => $space);
			$n = @socket_recvmsg($ctl, $msg, 0);
			if ($n === false) {
				// A child exited and SIGCHLD interrupted the call. recvmsg()
				// records EINTR in the global error, not on the socket as
				// most socket calls do -- read only from the socket, the
				// interruption looked like a failure and the zygote left at
				// the first worker's exit.
				if (socket_last_error() === SOCKET_EINTR or socket_last_error($ctl) === SOCKET_EINTR) {
					socket_clear_error();
					socket_clear_error($ctl);
					continue;
				}
				break;
			}
			if ($n === 0) break;   // the server is gone
			$fd = $msg['control'][0]['data'][0] ?? null;
			if ($fd === null) {
				@socket_write($ctl, pack('N', 0));
				continue;
			}
			$pid = pcntl_fork();
			if ($pid === 0) {
				// ── WORKER, forked from the zygote ──
				socket_close($ctl);
				if ($title !== '' and function_exists('cli_set_process_title')) @cli_set_process_title($title);
				if (function_exists('pcntl_signal')) {
					pcntl_signal(SIGCHLD, SIG_DFL);
					pcntl_signal(SIGTERM, SIG_DFL);
					pcntl_signal(SIGHUP, SIG_DFL);
				}
				if (function_exists('pcntl_async_signals')) pcntl_async_signals(false);
				$stream = is_resource($fd) ? $fd : socket_export_stream($fd);
				if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
					Q_WebServer_CompatFileWrapper::forgetStats(true);
					Q_WebServer_CompatFileWrapper::rememberExistence(true);
				}
				self::childRun($stream, $octane, $maxReqs);
				exit(0);
			}
			if (is_resource($fd)) @fclose($fd);
			else socket_close($fd);
			@socket_write($ctl, pack('N', $pid > 0 ? $pid : 0));
		}
		exit(0);
	}

	/**
	 * Fork a worker through the zygote. Null when the zygote failed -- it is
	 * stopped, and the caller forks from the server instead.
	 * @method forkWorkerViaZygote
	 * @return {integer|null} Worker index
	 */
	protected function forkWorkerViaZygote()
	{
		$family = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = @stream_socket_pair($family, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if (!$pair) return $this->zygoteFailed('socketpair failed');
		// The server takes signals all the time -- SIGCHLD whenever a worker
		// it forked itself exits, every signal the event loop watches -- and
		// any of them interrupts a blocking send or read here. Taken for a
		// failure, one interruption stopped a zygote that was working
		// perfectly: on alpha the first burst after every start. So an
		// interrupted call is retried, within one deadline for the lot.
		$ctl = $this->zygote['ctl'];
		$deadline = microtime(true) + 5.0;
		$interrupted = function () use ($ctl) {
			$e = socket_last_error($ctl) ?: socket_last_error();
			if ($e !== SOCKET_EINTR) return false;
			socket_clear_error($ctl);
			socket_clear_error();
			return true;
		};
		$theirs = @socket_import_stream($pair[1]);
		$sent = false;
		if ($theirs !== false) {
			do {
				$sent = @socket_sendmsg($ctl, array(
					'iov' => array('F'),
					'control' => array(array('level' => SOL_SOCKET, 'type' => SCM_RIGHTS, 'data' => array($theirs))),
				), 0);
			} while ($sent === false and $interrupted() and microtime(true) < $deadline);
		}
		// An imported socket leaves its descriptor to the stream, which is
		// closed here: the zygote holds its own copy now.
		unset($theirs);
		fclose($pair[1]);
		if (!$sent) {
			fclose($pair[0]);
			return $this->zygoteFailed('could not hand a socket to the zygote');
		}
		$reply = '';
		while (strlen($reply) < 4 and microtime(true) < $deadline) {
			$chunk = @socket_read($ctl, 4 - strlen($reply));
			if ($chunk === false) {
				if ($interrupted()) continue;
				break;
			}
			if ($chunk === '') break;   // the zygote is gone
			$reply .= $chunk;
		}
		$pid = strlen($reply) === 4 ? (int) unpack('N', $reply)[1] : 0;
		if ($pid <= 0) {
			fclose($pair[0]);
			return $this->zygoteFailed('the zygote did not fork a worker');
		}
		return $this->registerWorker($pid, $pair[0], true);
	}

	/**
	 * Give up on the zygote: stop it, say why, and fork from the server from
	 * now on.
	 * @method zygoteFailed
	 * @param {string} $why
	 * @return {null}
	 */
	protected function zygoteFailed($why)
	{
		if (class_exists('Q_WebServer_Log', false)) {
			Q_WebServer_Log::error('zygote: ' . $why . '; forking workers from the server from now on');
		}
		$this->stopZygote();
		return null;
	}

	/**
	 * Stop the zygote, if there is one. Its workers keep running: each ends
	 * when its socket pair closes, as any worker does.
	 * @method stopZygote
	 */
	function stopZygote()
	{
		if ($this->zygote === null) return;
		$pid = (int) $this->zygote['pid'];
		@socket_close($this->zygote['ctl']);
		$this->zygote = null;
		if ($pid > 0 and function_exists('posix_kill')) {
			@posix_kill($pid, SIGTERM);
			for ($i = 0; $i < 20; ++$i) {
				if (Q_WebServer_Fork::waitpid($pid, $st, 1) !== 0) return;
				usleep(10000);
			}
			@posix_kill($pid, SIGKILL);
			Q_WebServer_Fork::waitpid($pid, $st, 0);
		}
	}

	// ── Child process ────────────────────────────────────

	/**
	 * Child: handle requests. In octane mode, loops with snapshot restore
	 * between requests (0.05ms) instead of dying and re-forking (8ms).
	 * In classic mode, handles one request and exits.
	 *
	 * @method childRun
	 * @static
	 * @param {resource} $socket  Unix socket pair to the parent
	 * @param {boolean}  $octane  Whether to loop (true) or die after one (false)
	 * @param {integer}  $maxReqs Maximum requests before voluntary exit (0=unlimited)
	 */
	protected static function childRun($socket, $octane = false, $maxReqs = 0)
	{
		stream_set_blocking($socket, true);
		// An octane worker blocks on readExact() between requests, sometimes
		// for minutes. Without this, PHP's default_socket_timeout (60s) makes
		// that read return '' and the worker exits, so the first request after
		// an idle period gets a 502. There is no deadline on waiting for work.
		stream_set_timeout($socket, 86400);
		$handled = 0;

		do {
			// Read length-prefixed request
			$hdr = self::readExact($socket, 4);
			if ($hdr === false) break;
			$len = unpack('N', $hdr)[1];
			if ($len > 10485760) break;
			$json = self::readExact($socket, $len);
			if ($json === false) break;
			$req = json_decode($json, true);
			if (!$req) {
				self::writeMsg($socket, 500, 'Bad message', array());
				if (!$octane) break;
				continue;
			}
			if (!empty($req['requestUndecodable'])) {
				// The parent could not encode this request at all, so there
				// is nothing to run. Say so, rather than running the payload
				// that stands in for it and serving the wrong page.
				self::writeMsg($socket, 400, 'Bad Request', array());
				if (!$octane) break;
				continue;
			}

			// Execute the PHP script. The heap peak is reset first so what
			// is read afterwards is this request's own high-water mark, for
			// the dashboard's live request list. Without the reset (PHP < 8.2)
			// the peak would span the whole process, so nothing is reported.
			$canPeak = function_exists('memory_reset_peak_usage');
			if ($canPeak) memory_reset_peak_usage();
			$resp = self::executeScript($req);
			self::writeMsg($socket, $resp['status'], $resp['body'],
				$resp['headers'], $resp['cookies'] ?? array(),
				$resp['recycle'] ?? '', $canPeak ? memory_get_peak_usage() : 0);
			$handled++;

			if (!$octane) {
				// This worker is about to exit. Compat has taken over
				// register_shutdown_function, so PHP's own shutdown will not
				// fire what the request registered -- and eZ writes its
				// session in one of those callbacks. Without this the session
				// is never stored: the login is accepted, the cookie goes out,
				// and the next request finds nothing behind it, so the user is
				// back at the login form.
				if (class_exists('Q_WebServer_Compat', false)
					and Q_WebServer_Compat::isEnabled()) {
					try {
						Q_WebServer_Compat::shutdown();
					} catch (\Throwable $e) {
						// The response is already on its way; a failure in
						// cleanup must not turn it into a lost request.
					}
				}
				break;
			}

			// ── Octane: reset state for the next request ──

			// Compat layer: fire shutdown callbacks, restore error/exception
			// handlers, unregister request autoloaders, restore env vars,
			// close sessions, clean up uploads — all BEFORE snapshot restore
			// so shutdown callbacks see the request's final state.
			if (class_exists('Q_WebServer_Compat', false)
				&& Q_WebServer_Compat::isEnabled()) {
				Q_WebServer_Compat::shutdown();
				// Re-init for next request (re-registers file:// wrapper)
				Q_WebServer_Compat::init();
			}

			// Static properties: the snapshot captures the clean state the parent
			// had after preloading. restoreStatics() resets them via
			// ReflectionProperty::setValue — 0.05ms, vs 8ms for fork.
			if (class_exists('Q_WebServer_Snapshot', false)) {
				// Scripts may declare classes that were not there at preload
				// time; track those too, so their request state is cleared
				// like everything else.
				Q_WebServer_Snapshot::updateNewClasses();
				Q_WebServer_Snapshot::restoreStatics();
			}

			// Global variables: remove anything the script added.
			// Keep superglobals and the server's own bookkeeping.
			$keepGlobals = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
				'_FILES','_ENV','_SESSION','GLOBALS','argv','argc',
				'_Q_RAW_INPUT');

			// Applications may keep a global of their own across requests.
			// A registry populated by an include_once is the case that needs
			// it: clearing the global leaves the registry empty while
			// include_once refuses to run the file that would fill it again,
			// so every later request in that worker sees an empty registry
			// and no way to refill it. Naming such a global is narrow and
			// reviewable; keeping globals wholesale is not, because the
			// request-scoped ones still have to be cleared.
			$extraGlobals = Q_Config::get('Q', 'webserver', 'keepGlobals', array());
			if (is_string($extraGlobals)) {
				$extraGlobals = preg_split('/\s*,\s*/', $extraGlobals, -1, PREG_SPLIT_NO_EMPTY);
			}
			if (is_array($extraGlobals) && $extraGlobals) {
				$keepGlobals = array_values(array_unique(
					array_merge($keepGlobals, $extraGlobals)
				));
			}
			foreach (array_keys($GLOBALS) as $gk) {
				if (!in_array($gk, $keepGlobals, true)) {
					unset($GLOBALS[$gk]);
				}
			}

			// Cycles the request left behind. Everything it built is now
			// unreachable, but objects that point at each other are freed
			// only by the cycle collector, and PHP runs that on its own only
			// when its root buffer fills -- raising the threshold each time a
			// run finds little, which in a process that never ends a request
			// means garbage piles up: measured at ~47 KB a request on an
			// Exponential install, flat with a collection here. Cheap now,
			// because the buffer only ever holds one request's candidates.
			gc_collect_cycles();

			// Superglobals: overwritten by executeScript() on next iteration.
			// Output buffers: the one capture buffer, emptied by Capture::end().
			// Error state: clear it.
			error_clear_last();

			// Response headers: clear Q_WebServer_State's accumulated headers
			// and any native header() calls from the previous request.
			if (class_exists('Q_WebServer_State', false)) {
				Q_WebServer_State::clear();
			}
			// Issue #16: also clear Q_Response accumulated state (scripts,
			// styles, cookies, errors) so they don't leak between requests.
			if (class_exists('Q_Response', false)
				&& method_exists('Q_Response', 'clear')) {
				Q_Response::clear();
			}
			if (function_exists('header_remove')) {
				@header_remove();
			}

			// DB connections: flush transaction state. A persistent worker that
			// serves request A (which starts a transaction) and then request B
			// would leak A's uncommitted transaction into B. ROLLBACK is safe
			// even if no transaction is active (it's a no-op).
			if (class_exists('Db', false) && method_exists('Db', 'getConnection')) {
				try {
					foreach (Db::getConnections() as $conn) {
						if (method_exists($conn, 'rawQuery')) {
							$conn->rawQuery('ROLLBACK');
						}
					}
				} catch (\Throwable $e) { /* no DB configured — that's fine */ }
			}

			// Voluntary recycling: after N requests, exit so the parent
			// re-forks a clean worker. Safety net for state the snapshot
			// can't reach (C extension internals, accumulated closures).
			if ($maxReqs > 0 && $handled >= $maxReqs) break;

		} while (true);

		fclose($socket);
	}

	/**
	 * Set up superglobals and include the PHP script.
	 * The script (index.php, action.php, etc.) internally calls
	 * Q_WebController::execute() or Q_ActionController::execute().
	 */
	protected static function executeScript($req)
	{
		// The per-request state holder, loaded before anything asks whether
		// it exists. Clearing it and collecting from it below are both
		// guarded by class_exists(..., false), and setcookie() records into
		// Q_Response without ever loading it -- so on a worker's first
		// request both were skipped and every cookie that request set was
		// dropped. A login answered by a freshly forked worker lost its
		// session cookie.
		if (!class_exists('Q_WebServer_State', false) and is_file(__DIR__ . '/State.php')) {
			require_once __DIR__ . '/State.php';
		}

		// ── Reset ALL superglobals to prevent cross-request leaks ──
		// $_SERVER: strip all HTTP_* headers and app-injected keys from
		// the previous request, then repopulate from this request only.
		foreach (array_keys($_SERVER) as $k) {
			if (strncmp($k, 'HTTP_', 5) === 0) unset($_SERVER[$k]);
		}
		unset($_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
		// Remove any keys the previous script injected
		$_serverKeep = array('PATH','HOME','LANG','USER','SHELL','TERM',
			'SHLVL','_','SERVER_SOFTWARE','GATEWAY_INTERFACE',
			'REQUEST_SCHEME','HTTPS','PHP_SELF','argv','argc');
		foreach (array_keys($_SERVER) as $k) {
			if (!in_array($k, $_serverKeep, true)
				&& strncmp($k, 'REQUEST_', 8) !== 0
				&& strncmp($k, 'SERVER_', 7) !== 0
				&& strncmp($k, 'SCRIPT_', 7) !== 0
				&& strncmp($k, 'DOCUMENT_', 9) !== 0
				&& strncmp($k, 'REMOTE_', 7) !== 0
				&& strncmp($k, 'QUERY_', 6) !== 0
			) {
				unset($_SERVER[$k]);
			}
		}

		$_SERVER['REQUEST_METHOD'] = $req['method'];
		$_SERVER['REQUEST_URI'] = $req['uri'];
		$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
		$_SERVER['SCRIPT_FILENAME'] = $req['scriptFilename'];
		$_SERVER['SCRIPT_NAME'] = $req['scriptName'] ?? '/index.php';
		$_pathInfo = (string) ($req['pathInfo'] ?? '');
		$_SERVER['PHP_SELF'] = ($req['scriptName'] ?? '/index.php') . $_pathInfo;
		$_SERVER['PATH_INFO'] = $_pathInfo;
		$_SERVER['PATH_TRANSLATED'] = $_pathInfo !== ''
			? rtrim((string) ($req['documentRoot'] ?? ''), '/') . $_pathInfo : $req['scriptFilename'];
		$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
		// SERVER_NAME is the host, without the port. SERVER_PORT is the port.
		//
		// Assigning the raw Host header here put "example.com:8080" in a
		// variable every CGI-speaking application expects to be a bare
		// hostname, and applications do arithmetic on it. One of them derived a
		// request path by removing the server name from the front of a URL and
		// was left holding ":8080/admin/setup/info", which it then stored as
		// the page to return to after signing in -- so signing in landed on
		// /admin/%3A8080/admin/setup/info and a 404.
		//
		// Split on the last colon so an IPv6 literal in brackets survives.
		$host = (string) ($req['headers']['host'] ?? 'localhost');
		if ($host === '') {
			$host = 'localhost';
		} else if ($host[0] === '[') {
			$end = strpos($host, ']');
			if ($end !== false) $host = substr($host, 0, $end + 1);
		} else {
			$colon = strrpos($host, ':');
			if ($colon !== false) $host = substr($host, 0, $colon);
		}
		$_SERVER['SERVER_NAME'] = $host;
		$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
		$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';
		$_SERVER['REMOTE_PORT'] = (string) ($req['remotePort'] ?? 0);
		$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0');
		$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

		foreach ($req['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		if (isset($req['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
		if (isset($req['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];

		// REQUEST_SCHEME / HTTPS from proxy headers or direct
		$proto = $req['headers']['x-forwarded-proto'] ?? '';
		if (strtolower($proto) === 'https' || ($req['https'] ?? false)) {
			$_SERVER['HTTPS'] = 'on';
			$_SERVER['REQUEST_SCHEME'] = 'https';
		} else {
			$_SERVER['REQUEST_SCHEME'] = 'http';
			unset($_SERVER['HTTPS']);
		}

		// PHP_AUTH_USER / PHP_AUTH_PW from Authorization header
		$authHeader = $req['headers']['authorization'] ?? '';
		if ($authHeader && stripos($authHeader, 'Basic ') === 0) {
			$decoded = base64_decode(substr($authHeader, 6));
			if ($decoded !== false && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$_SERVER['PHP_AUTH_USER'] = $user;
				$_SERVER['PHP_AUTH_PW'] = $pass;
			}
		} else {
			unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
		}

		// Populate getallheaders() / apache_request_headers()
		if (!class_exists('Q_WebServer_GetAllHeaders', false)) {
			$_gah = __DIR__ . '/GetAllHeaders.php';
			if (is_file($_gah)) require_once $_gah;
		}
		if (class_exists('Q_WebServer_GetAllHeaders', false)) {
			Q_WebServer_GetAllHeaders::register();
			Q_WebServer_GetAllHeaders::set(
				$req['headers'] ?? array(),
				$req['rawHeaders'] ?? array()
			);
		}

		// ── Clear and rebuild all input superglobals ──
		$_GET = $_POST = $_REQUEST = $_FILES = array();
		$_COOKIE = array();
		if (!empty($req['query'])) parse_str($req['query'], $_GET);

		// Parse cookies from the Cookie header
		$cookieHeader = $req['headers']['cookie'] ?? '';
		if ($cookieHeader !== '') {
			foreach (explode(';', $cookieHeader) as $c) {
				$c = trim($c);
				if ($c === '') continue;
				$eq = strpos($c, '=');
				if ($eq !== false) {
					$_COOKIE[urldecode(substr($c, 0, $eq))] = urldecode(substr($c, $eq + 1));
				}
			}
		}

		$ct = strtolower($req['headers']['content-type'] ?? '');
		$origCt = $req['headers']['content-type'] ?? '';
		// Restore a body that had to be base64-encoded to survive the
		// journey; see sendTo(). Done before anything looks at the body, so
		// multipart parsing and php://input both see the original bytes.
		if (!empty($req['bodyB64'])) {
			$req['body'] = base64_decode($req['bodyB64']);
		}

		$raw = $req['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($raw, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($raw, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			\Q_WebServer::parseMultipart($origCt, $raw, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// php://input workaround — register a custom stream wrapper
		// so file_get_contents('php://input') works in persistent workers.
		// PHP's built-in php://input is empty in CLI SAPI for included scripts.
		$GLOBALS['_Q_RAW_INPUT'] = $raw;
		if (!self::$inputWrapperRegistered) {
			stream_wrapper_unregister('php');
			stream_wrapper_register('php', 'Q_WebServer_PhpInputStream');
			self::$inputWrapperRegistered = true;
		}

		// Look at the files served so far again before this request runs, not
		// only after the last one ended: a file rewritten while the worker sat
		// idle was otherwise served once more from the opcode cache's old
		// compile, which with opcache.revalidate_freq > 0 does not look at the
		// file itself for that long. See CompatFileWrapper::forgetStats().
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			Q_WebServer_CompatFileWrapper::forgetStats();
		}

		// One capture buffer for the life of the worker, reused per request --
		// see Q_WebServer_Capture for why it is not opened afresh each time.
		Q_WebServer_Capture::begin();
		$status = 200;
		$headers = array();
		// mod_php and fpm run a script with its own directory as the working
		// directory. A persistent worker has no reason to change directory at
		// all, so it kept the one the server was started in -- and an
		// application resolving a relative path got the server's directory
		// instead of its own. eZ Publish wrote its template cache into the
		// server's tree, read a half-written file back and died on a parse
		// error pointing at a file it had never heard of.
		$prevCwd = getcwd();
		$scriptDir = dirname($req['scriptFilename']);
		if ($scriptDir !== '' and is_dir($scriptDir)) {
			@chdir($scriptDir);
		}

		try {
			include($req['scriptFilename']);
			// Collect headers from native header() (works in fpm, no-op in CLI)
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			// Also collect headers from Q_WebServer_State (works in CLI/octane)
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					$headers[$k] = $v;
				}
			}
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;

			// Check Q_WebServer_State for status code (CLI SAPI ignores http_response_code)
			if (class_exists('Q_WebServer_State', false)) {
				$stateCode = \Q_WebServer_State::getStatusCode();
				if ($stateCode && $stateCode !== 200) $status = $stateCode;
			}

			// Recover status from the Platform's own error state.
			// Same fix as dispatchToQ: http_response_code() is a no-op under
			// CLI SAPI, so the Platform's 412/424 errors arrive as 200.
			if ($status === 200
			and class_exists('Q_Response', false)
			and method_exists('Q_Response', 'getErrors')) {
				try {
					foreach ((array) \Q_Response::getErrors() as $err) {
						if (is_object($err) and !empty($err->httpResponseCode)) {
							$status = (int) $err->httpResponseCode;
							break;
						}
					}
				} catch (\Throwable $ignore) {}
			}
		} catch (\Q_WebServer_ExitSignal $e) {
			// The script called exit or die. That is an ordinary end of a
			// request, not a failure: whatever it printed before stopping is
			// the response, and the headers it set still stand. Only the
			// process must not actually end, because the process is a worker
			// that has other requests to serve.
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					$headers[$k] = $v;
				}
				$stateCode = \Q_WebServer_State::getStatusCode();
				if ($stateCode and $stateCode !== 200) $status = $stateCode;
			}
			$code = http_response_code();
			if ($code and $code !== 200 and $status === 200) $status = $code;
		} catch (\Throwable $e) {
			$status = 500;
			Q_WebServer_Capture::discard();
			// The response says what went wrong; where, and how it got there,
			// only with --debug. The log always gets where it happened, the
			// first frames, and every previous exception with its own file
			// and line -- a framework's outer exception is often only
			// "something went wrong while doing X".
			if (class_exists('Q_WebServer_ErrorReport')) {
				echo Q_WebServer_ErrorReport::body($e);
				Q_WebServer_ErrorReport::log($e, 'worker ' . getmypid());
			} else {
				echo $e->getMessage();
				fwrite(STDERR, sprintf("  worker %d: uncaught %s at %s:%d\n",
					getmypid(), get_class($e), $e->getFile(), $e->getLine()));
			}
		}
		// Back to where the worker started, so the next request is not
		// affected by where this one went.
		if ($prevCwd !== false) {
			@chdir($prevCwd);
		}

		// Everything the script printed, including buffers it left open.
		$body = Q_WebServer_Capture::end();

		// Health, checked on every request, so that nothing a script or the
		// server itself holds on to can grow without bound. A worker whose
		// output buffers did not come back to one-and-empty, or whose heap is
		// past the ceiling, answers this request and is then replaced by the
		// parent -- which also logs why, so a leak is named the first time it
		// happens instead of being found as a machine out of memory.
		$recycle = '';
		if (self::$retireReason !== '') {
			$recycle = 'asked by the application: ' . self::$retireReason;
			self::$retireReason = '';
		} elseif (!Q_WebServer_Capture::balanced()) {
			$recycle = 'output buffers did not return to the capture buffer'
				. ' (level ' . ob_get_level() . ')';
		} else {
			$ceiling = self::memoryCeiling();
			$heap = memory_get_usage();
			if ($ceiling > 0 and $heap > $ceiling) {
				$recycle = sprintf('heap %.0f MB over the %.0f MB ceiling',
					$heap / 1048576, $ceiling / 1048576);
			}
		}

		// Cookies live in Q_Response, which is the worker's memory. The
		// parent used to read its own copy when writing the response and so
		// found nothing: setcookie() reached the client from no script at
		// all. Carry them across with the response.
		$cookies = array();
		if (class_exists('Q_WebServer_State', false)
		and method_exists('Q_WebServer_State', 'cookieHeaders')) {
			$cookies = (array) Q_WebServer_State::cookieHeaders();
		}
		return compact('status', 'body', 'headers', 'cookies', 'recycle');
	}

	/** Why the application asked for this worker to be replaced, or ''. */
	protected static $retireReason = '';

	/**
	 * Ask for the worker serving this request to be replaced after it
	 * answers.
	 *
	 * For code that cannot run twice in one process: an application that
	 * declares constants from the request, or declares functions that depend
	 * on it, can be served correctly exactly once per worker. The request is
	 * answered normally; the parent then retires the worker before giving it
	 * another request, forks a fresh one, and logs the reason. Outside a
	 * pool worker -- a CLI script, the in-process server -- it does nothing.
	 *
	 *     if (class_exists('Q_WebServer_Pool', false)) {
	 *         Q_WebServer_Pool::retireAfterResponse('declares request constants');
	 *     }
	 *
	 * @method retireAfterResponse
	 * @static
	 * @param {string} $reason for the log
	 */
	static function retireAfterResponse($reason)
	{
		$reason = trim(preg_replace('/\s+/', ' ', (string) $reason));
		self::$retireReason = $reason !== '' ? substr($reason, 0, 200) : 'no reason given';
	}

	/**
	 * The heap size past which a worker is replaced, in bytes; 0 for never.
	 *
	 * Q.webserver.workerMemoryCeiling, in MB. Unset, it is 256 MB, or three
	 * quarters of memory_limit if that is lower -- below the point where PHP
	 * would end a request with a fatal error. It is an absolute figure on
	 * purpose: a server may run hundreds of workers, and a ceiling derived
	 * from a generous memory_limit alone (4.8 GB on one install) would let
	 * each of them grow to gigabytes before anything noticed. A worker
	 * serving a normal application sits far below 256 MB; one that reaches it
	 * is holding on to something, and is cheaper to replace than to trust.
	 *
	 * @method memoryCeiling
	 * @static
	 * @return {integer}
	 */
	static function memoryCeiling()
	{
		static $ceiling = null;
		if ($ceiling !== null) return $ceiling;
		$mb = Q_Config::get('Q', 'webserver', 'workerMemoryCeiling', null);
		if ($mb !== null and $mb !== '') {
			return $ceiling = max(0, (int) $mb) * 1048576;
		}
		$limit = trim((string) ini_get('memory_limit'));
		$bytes = (int) $limit;
		switch (strtolower(substr($limit, -1))) {
			case 'g': $bytes *= 1024;
			case 'm': $bytes *= 1024;
			case 'k': $bytes *= 1024;
		}
		$ceiling = 256 * 1048576;
		if ($bytes > 0) $ceiling = min($ceiling, (int) ($bytes * 0.75));
		return $ceiling;
	}

	// ── Parent-side dispatch ─────────────────────────────

	/**
	 * The URL path of a script, for SCRIPT_NAME and PHP_SELF.
	 *
	 * This used to be '/' . basename($scriptPath), which is right only for a
	 * script sitting in the document root. Anything in a subdirectory lost
	 * it: an application under /shop/ was told it lived at /, and every
	 * absolute URL it built from SCRIPT_NAME -- stylesheets, form actions,
	 * redirects -- pointed one or more directories too high. The page still
	 * rendered, which is what made it hard to see: it just arrived without
	 * its styling, and posting a form landed somewhere else.
	 *
	 * mod_php and fpm report the script's path below the document root, so
	 * that is what is built here.
	 *
	 * @method scriptName
	 * @static
	 * @protected
	 * @param {string} $scriptPath Absolute path of the script on disk
	 * @return {string}
	 */
	protected static function scriptName($scriptPath)
	{
		$root = rtrim(str_replace('\\', '/', (string) (Q_WebServer::$rootDir ?? '')), '/');
		$path = str_replace('\\', '/', (string) $scriptPath);
		if ($root !== '' and strpos($path, $root . '/') === 0) {
			return '/' . ltrim(substr($path, strlen($root)), '/');
		}
		// Outside the document root -- an alias or a rewrite target. The
		// basename is all that can honestly be said about it.
		return '/' . basename($path);
	}

	/**
	 * Send a request to an idle worker. Queues if all busy.
	 */
	function dispatch($client, $parsed, $scriptPath, $responder = null)
	{
		$idle = $this->findIdle();
		if ($idle === null and $this->spareWorkers > 0
			and count($this->workers) < $this->targetSize) {
			// Dynamic pool: every worker busy and room to grow -- fork one
			// for this request rather than make it wait.
			$idle = $this->forkWorker();
		}
		if ($idle === null) {
			$this->pending[] = array($client, $parsed, $scriptPath, $responder);
			return;
		}
		$this->sendTo($idle, $client, $parsed, $scriptPath, $responder);
	}

	protected function sendTo($index, $client, $parsed, $scriptPath, $responder = null)
	{
		$this->workers[$index]['busy'] = true;
		$this->workerClients[$index] = $client;
		// Where the answer goes. Normally it is written to the client socket
		// as an HTTP/1.1 response; an HTTP/2 connection multiplexes many
		// requests over one socket and has to put the answer on the stream it
		// came from, so it supplies a callback and takes the response array
		// instead.
		$this->workerResponders[$index] = $responder;
		// Kept so a request can be sent again if its worker dies first.
		$this->workerScripts[$index] = $scriptPath;
		$this->workerBuffers[$index] = '';
		// With the method, which the response writer needs: a HEAD answered by a
		// worker was sent its body, because the writer was never told it was HEAD.
		$this->workerRequestHeaders[$index] = $parsed['headers'] + array('_method' => $parsed['method']);
		// The reverse proxy cache needs the whole parsed request, not just
		// the headers: it keys on host, path and query, and checks the
		// method and the bypass cookies.
		$this->workerRequests[$index] = $parsed;
		// When the response goes out we need this to report how long it took.
		$this->workerStarted[$index] = microtime(true);
		// In octane mode, the watcher was cancelled after the previous
		// response to prevent stream_select from firing endlessly on the
		// idle socket. Create a fresh one-shot watcher for this dispatch.
		if (!isset($this->watchers[$index])) {
			$pool = $this;
			$this->watchers[$index] = Q_Evented::onReadable(
				$this->workers[$index]['socket'],
				function ($s) use ($pool, $index) {
					$pool->onWorkerData($index, $s);
				}
			);
		} else {
			Q_Evented::enable($this->watchers[$index]);
		}

		$payload = array(
			'method'         => $parsed['method'],
			'uri'            => $parsed['uri'],
			'path'           => $parsed['path'],
			'query'          => $parsed['query'],
			'headers'        => $parsed['headers'],
			'rawHeaders'     => $parsed['rawHeaders'] ?? array(),
			'body'           => $parsed['body'],
			'scriptFilename' => $scriptPath,
			'scriptName'     => self::scriptName($scriptPath),
			// script.php/extra/path: the part after the script, which
			// splitPathInfo() found. It was never passed on, so a pooled script
			// saw no PATH_INFO and PHP_SELF without it, and every framework URL
			// of that shape (action.php/Module/action) dispatched as the bare script.
			'pathInfo'       => isset($parsed['_pathInfo']) ? (string) $parsed['_pathInfo'] : '',
			'documentRoot'   => rtrim(Q_WebServer::$rootDir ?? '', '/\\'),
			// The port this request arrived on. It was read from the parent's
			// own $_SERVER, which a command-line process does not fill in, so
			// every pooled request was told SERVER_PORT=8080 whatever the
			// server listened on. An application that builds an absolute URL
			// from it -- when the Host header carries no port, as it does not
			// behind a proxy on 80 or 443 -- sent visitors to :8080.
			'serverPort'     => self::serverPortOf($client),
			// Who is asking. The parent has already worked it out -- the
			// connection's address, or a forwarded one from a trusted proxy --
			// and this sent 127.0.0.1 instead, so every application behind
			// the pool saw every visitor as the server itself: its address
			// checks, its logs and anything it limited by address.
			'remoteAddr'     => (string) ($parsed['_remoteAddr']
				?? $parsed['clientIp'] ?? self::peerAddressOf($client, false)),
			'remotePort'     => (int) ($parsed['_remotePort']
				?? self::peerAddressOf($client, true)),
			// Whether this connection is TLS. The worker already looks for
			// this and reports HTTPS and REQUEST_SCHEME from it, but nothing
			// ever sent it -- so every request looked like plain HTTP no
			// matter which listener it arrived on.
			//
			// An application building an absolute URL asks exactly those two
			// variables. Getting them wrong sends a browser that is on
			// https:// a redirect to http:// on the same port, and that port
			// speaks TLS, so the browser opens a plain connection into a TLS
			// listener and the only possible outcome is a reset. It presents
			// as "Secure Connection Failed" with nothing wrong in any log,
			// and it breaks every redirect: after a login, after a publish.
			'https'          => self::isTlsClient($client)
		);
		$msg = json_encode($payload);
		// A request body that is not valid UTF-8 cannot go through
		// json_encode(), which returns false for it. strlen(false) is 0, so
		// the frame went out as a length prefix of zero and nothing else; the
		// worker read an empty message, could not decode it, and answered
		// "Bad message" with a 500.
		//
		// Every file upload is exactly that. An image, a PDF, a zip -- any
		// multipart POST carrying bytes rather than text -- failed before the
		// application saw it. In a browser the upload simply never completes:
		// the editor's progress dialog waits for a result it will never be
		// given.
		//
		// Base64 carries those bytes, and only those: a text body keeps its
		// exact previous shape and cost. This mirrors what writeMsg() does in
		// the other direction, and uses the same flag.
		if ($msg === false) {
			$payload['bodyB64'] = base64_encode((string) $payload['body']);
			$payload['body'] = '';
			$msg = json_encode($payload);
		}

		if ($msg === false) {
			// Something other than the body cannot be encoded. Fail this one
			// request rather than sending a frame the worker cannot read.
			$msg = json_encode(array(
				'method' => 'GET', 'uri' => '/', 'path' => '/', 'query' => '',
				'headers' => array(), 'rawHeaders' => array(), 'body' => '',
				'scriptFilename' => '', 'scriptName' => '',
				'requestUndecodable' => true
			));
		}

		// The request is length-prefixed and the parent's end of the socket is
		// non-blocking, so fwrite() may place only part of it. Testing for
		// false or zero caught a worker that had died but counted a short
		// write as a success: the worker then read the length we declared,
		// consumed fewer bytes than that, and every request it was sent
		// afterwards began mid-message. writeFully() completes the record or
		// reports that it could not, and does not block the parent -- which is
		// dispatching for every other worker and client at the same time.
		$packet = pack('N', strlen($msg)) . $msg;
		if (!Q_WebServer::writeFully($this->workers[$index]['socket'], $packet)) {
			// Worker died before receiving the request — recycle and re-queue.
			// Safe for any method: the worker never had it. The responder goes
			// back on the queue too: without it a request that arrived over
			// HTTP/2 would be answered as HTTP/1.1, written straight into an
			// open h2 connection, which corrupts it. At the front, so it is
			// not overtaken by requests that arrived after it; and a bounded
			// number of times, so a pool that can only produce dead workers
			// answers 502 rather than spinning.
			$parsed['poolRequeues'] = ($parsed['poolRequeues'] ?? 0) + 1;
			// Not the worker's client any more either way: recycle() must
			// neither answer nor close it.
			unset($this->workerClients[$index], $this->workerResponders[$index]);
			if ($parsed['poolRequeues'] <= self::MAX_REQUEUES) {
				array_unshift($this->pending, array($client, $parsed, $scriptPath, $responder));
			} elseif ($responder) {
				call_user_func($responder, array('status' => 502,
					'headers' => array('Content-Type' => 'text/plain; charset=utf-8'),
					'body' => 'No worker could take the request'));
			} elseif (is_resource($client)) {
				Q_WebServer::sendResponse($client, 502, 'No worker could take the request');
				if (is_resource($client)) @fclose($client);
			}
			$this->recycle($index, true);
		}
	}

	/**
	 * Called when data or EOF arrives from a worker.
	 */
	function onWorkerData($index, $sock)
	{
		$chunk = @fread($sock, 65536);

		if ($chunk === false || $chunk === '') {
			// Empty read. In octane mode, the worker stays alive between
			// requests: an empty read means the socket has no new data,
			// NOT that the worker exited. Only recycle if the worker process
			// is actually gone.
			//
			// "Gone" is the socket at EOF -- the worker's end is closed, which
			// only happens when it exits -- or the process already reaped.
			// posix_kill($pid, 0) alone said a dead worker was alive: it
			// succeeds on a zombie, so the parent returned, the watcher kept
			// firing on the dead socket, and the client waited for an answer
			// that could never come.
			if ($this->octane and !feof($sock)) {
				$exited = $this->workerGone($this->workers[$index] ?? array());
				if (!$exited) return; // no data yet; the worker is alive
			}
			$this->recycle($index, true);
			return;
		}

		$this->workerBuffers[$index] .= $chunk;
		$buf = $this->workerBuffers[$index];
		if (strlen($buf) < 4) return;

		$len = unpack('N', substr($buf, 0, 4))[1];
		if (strlen($buf) < 4 + $len) return;

		// Got complete response
		$json = substr($buf, 4, $len);
		$response = json_decode($json, true);

		// Binary bodies travel base64-encoded; see writeMsg().
		if ($response and !empty($response['b64'])) {
			$response['body'] = base64_decode($response['body']);
			unset($response['b64']);
		}

		// Check for cache messages piggybacked on the response
		if ($response && !empty($response['_cacheMessages'])) {
			foreach ($response['_cacheMessages'] as $msg) {
				Q_WebServer_Cache_Components::processChildMessage($msg);
			}
			unset($response['_cacheMessages']);
		}

		// The worker's heap peak is for the dashboard, not the client.
		$mem = 0;
		if ($response and isset($response['_mem'])) {
			$mem = max(0, (int) $response['_mem']);
			unset($response['_mem']);
		}

		// A worker's request to be replaced is for the pool, not the client.
		$recycleReason = '';
		if ($response and isset($response['_recycle'])) {
			$recycleReason = (string) $response['_recycle'];
			unset($response['_recycle']);
		}

		$client = $this->workerClients[$index] ?? null;
		$reqHeaders = $this->workerRequestHeaders[$index] ?? [];
		if ($response && $client && is_resource($client)) {
			$reqHeaders['_keepAlive'] = false;
			$this->workerRequestHeaders[$index] = $reqHeaders;
			$this->sendHttp($client, $response, $index, $mem);
			Q_WebServer::closeClient((int) $client);
			// Answered, so the client is no longer this worker's. recycle()
			// closes whatever is still listed here, and in fork-per-request
			// mode every worker is recycled right after this -- for a request
			// that came over HTTP/2 that was the connection itself, with
			// every other stream on it: fclose() sent close_notify, and a
			// signed-in page lost the stylesheets, header images and scripts
			// still in flight on that connection.
			unset($this->workerClients[$index]);
		}

		// In octane mode the worker is still alive — mark it idle so it
		// can receive the next request. In classic mode, recycle it (the
		// child exited after one request).
		if ($this->octane) {
			$this->workers[$index]['busy'] = false;
			$this->workers[$index]['idleSince'] = microtime(true);
			$this->workers[$index]['requests'] = ($this->workers[$index]['requests'] ?? 0) + 1;
			$this->workerBuffers[$index] = '';
			unset($this->workerClients[$index]);

			// Recycle if marked for graceful recycling, or hit maxRequests
			// A worker that reported a failed health check is replaced now,
			// before it is given another request, and the reason is logged.
			if ($recycleReason !== '') {
				$this->workers[$index]['recycleAfter'] = true;
				fwrite(STDERR, sprintf("  worker %d replaced after %d requests: %s\n",
					(int) ($this->workers[$index]['pid'] ?? 0),
					(int) ($this->workers[$index]['requests'] ?? 0),
					$recycleReason));
			}
			// Above the pool's size -- it was made smaller while this worker
			// was busy -- a worker that falls idle leaves rather than waits.
			if (count($this->workers) > $this->targetSize and empty($this->pending)
				and empty($this->workers[$index]['recycleAfter'])) {
				$this->retire($index);
				return;
			}

			$shouldRecycle = !empty($this->workers[$index]['recycleAfter'])
				|| ($this->maxRequests > 0 && $this->workers[$index]['requests'] >= $this->maxRequests);

			if ($shouldRecycle) {
				$this->recycle($index, false);
				return;
			}

			if (isset($this->watchers[$index])) {
				Q_Evented::cancel($this->watchers[$index]);
				unset($this->watchers[$index]);
			}
			if (!empty($this->pending)) {
				$next = array_shift($this->pending);
				$this->dispatch($next[0], $next[1], $next[2], $next[3] ?? null);
			}
		} else {
			$this->recycle($index, false);
		}
	}

	/**
	 * Clean up a finished worker: close socket, reap pid,
	 * fork replacement, process pending queue.
	 */
	protected function recycle($index, $isEof)
	{
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
			unset($this->watchers[$index]);
		}

		// EOF with no response. The worker died holding this request without
		// writing a byte of the answer.
		if ($isEof && isset($this->workerClients[$index])
			&& empty($this->workerBuffers[$index])
		) {
			$c = $this->workerClients[$index];
			$parsed = $this->workerRequests[$index] ?? null;
			$responder = $this->workerResponders[$index] ?? null;
			if ($parsed !== null and self::mayRetry($parsed)) {
				// A request that changes nothing can be run again, and the
				// visitor never learns a worker was lost: the usual cause is a
				// worker that was killed, ran out of memory or died idle a
				// moment before this request reached it. Once only, so a
				// request that itself kills its worker cannot take the pool
				// down with it.
				$parsed['poolRetries'] = ($parsed['poolRetries'] ?? 0) + 1;
				fwrite(STDERR, sprintf("  worker %d died before answering %s %s; retried on another worker\n",
					(int) ($this->workers[$index]['pid'] ?? 0), $parsed['method'] ?? '?', $parsed['uri'] ?? '?'));
				array_unshift($this->pending, array($c, $parsed,
					$this->workerScripts[$index] ?? '', $responder));
			} elseif ($responder) {
				// HTTP/2: the answer belongs on its stream, never as HTTP/1.1
				// bytes written into the shared connection.
				call_user_func($responder, array('status' => 502,
					'headers' => array('Content-Type' => 'text/plain; charset=utf-8'),
					'body' => 'Worker died'));
			} elseif (is_resource($c)) {
				Q_WebServer::sendResponse($c, 502, 'Worker died');
				if (is_resource($c)) @fclose($c);
			}
		} elseif (isset($this->workerClients[$index])) {
			$c = $this->workerClients[$index];
			$responder = $this->workerResponders[$index] ?? null;
			if ($responder) {
				// HTTP/2, and the worker died part-way through its answer.
				// The stream gets the 502; the connection it shares with the
				// rest of the page is not this request's to close.
				call_user_func($responder, array('status' => 502,
					'headers' => array('Content-Type' => 'text/plain; charset=utf-8'),
					'body' => 'Worker died'));
			} elseif (is_resource($c)) {
				@fclose($c);
			}
		}

		if (isset($this->workers[$index])) {
			$sock = $this->workers[$index]['socket'];
			if (is_resource($sock)) @fclose($sock);
			$this->reapWorker($this->workers[$index]);
		}
		$this->forgetWorker($index);

		// Replace it. A dynamic pool replaces only what keeps it at its spare
		// count or serves a request that is waiting; above that, demand forks
		// workers as it needs them.
		if ($this->spareWorkers > 0 and empty($this->pending)
			and count($this->workers) >= $this->spareWorkers) {
			return;
		}
		// Nor above the pool's size, which resize() may have lowered.
		if (empty($this->pending) and count($this->workers) >= $this->targetSize) {
			return;
		}
		$newIdx = $this->forkWorker();

		// Drain pending queue
		if (!empty($this->pending)) {
			$next = array_shift($this->pending);
			$this->sendTo($newIdx, $next[0], $next[1], $next[2], $next[3] ?? null);
		}
	}

	/**
	 * We need the original request headers for compression
	 * negotiation. Store them alongside the client.
	 * @property $workerRequestHeaders
	 */
	protected $workerRequestHeaders = array();

	/**
	 * Where each worker's answer should go: a callback that takes the response
	 * array, or null to write it to the client socket as HTTP/1.1.
	 *
	 * One socket carries one HTTP/1.1 request, so writing the response to it
	 * is unambiguous. An HTTP/2 connection multiplexes many requests over one
	 * socket, so the answer has to be put on the stream it belongs to, and
	 * only the connection knows which that is.
	 * @property $workerResponders
	 */
	protected $workerResponders = array();

	/**
	 * The parsed request each worker is serving, kept so the response can
	 * be offered to the reverse proxy cache once the worker is done.
	 * @property $workerRequests
	 */
	protected $workerRequests = array();

	/**
	 * When each worker was handed its request, for the response time the
	 * access log and the dashboard report.
	 * @property $workerStarted
	 */
	protected $workerStarted = array();

	protected function sendHttp($client, $resp, $index, $mem = 0)
	{
		// Component cache headers: register the page's tree and dependencies,
		// act on invalidations, and strip them -- they are for this server,
		// not the client. Only the in-process path did this, so under a pool
		// the headers went out to the browser and nothing was ever registered.
		if (isset($this->workerRequests[$index])
			and class_exists('Q_WebServer_Cache_Components', false)
			and Q_WebServer_Cache_Components::enabled()
			and isset($resp['headers']) and is_array($resp['headers'])) {
			Q_WebServer_Cache_Components::processResponseHeaders(
				Q_WebServer_Cache_Components::pageKey($this->workerRequests[$index]),
				$resp['headers']);
		}

		$responder = $this->workerResponders[$index] ?? null;
		if ($responder) {
			$this->workerResponders[$index] = null;
			call_user_func($responder, $resp);
		} else {
			$reqHeaders = $this->workerRequestHeaders[$index] ?? array();
			Q_WebServer_Headers::processResponse($client, $resp, $reqHeaders);
		}

		// Recorded either way. How the response reached the client does not
		// change what the log and the dashboard should say about it.
		//
		// The parent skipped recording this one -- dispatch() marked it as
		// handed on, because back then neither the status nor the size existed.
		if (isset($this->workerRequests[$index])) {
			$started = $this->workerStarted[$index] ?? microtime(true);
			Q_WebServer::recordCompleted(
				$this->workerRequests[$index],
				$resp['status'] ?? 200,
				strlen($resp['body'] ?? ''),
				(microtime(true) - $started) * 1000,
				true, $mem
			);
		}
		// Offer the response to the cache. Every other dispatch path does
		// this; the pooled path did not, so Q_WebServer_Cache::get() in
		// route() could only ever miss -- nothing was ever stored.
		if (isset($this->workerRequests[$index])) {
			Q_WebServer_Cache::put($this->workerRequests[$index], $resp);
		}
	}

	/**
	 * Whether a client socket is running TLS.
	 *
	 * @method isTlsClient
	 * @static
	 * @param {resource} $client
	 * @return {boolean}
	 */
	static function isTlsClient($client)
	{
		if (!is_resource($client)) return false;
		$meta = @stream_get_meta_data($client);
		return !empty($meta['crypto']);
	}

	/**
	 * The far end of a client connection, for when the parsed request does
	 * not already say.
	 *
	 * @method peerAddressOf
	 * @static
	 * @param {resource} $client
	 * @param {boolean} $port true for the port, false for the address
	 * @return {string|integer}
	 */
	static function peerAddressOf($client, $port)
	{
		$name = is_resource($client) ? @stream_socket_get_name($client, true) : false;
		if (!is_string($name) or ($colon = strrpos($name, ':')) === false) {
			return $port ? 0 : '127.0.0.1';
		}
		if ($port) return (int) substr($name, $colon + 1);
		// "[::1]:52000" carries the address in brackets.
		return trim(substr($name, 0, $colon), '[]');
	}

	/**
	 * The local port of the connection a request came in on.
	 *
	 * Read from the socket rather than from configuration, because a server
	 * can listen on more than one port -- plain HTTP and TLS at least -- and
	 * only the connection knows which of them this request used.
	 *
	 * @method serverPortOf
	 * @static
	 * @param {resource} $client
	 * @return {string}
	 */
	static function serverPortOf($client)
	{
		if (is_resource($client)) {
			$name = @stream_socket_get_name($client, false);
			// "127.0.0.1:8484", "[::1]:8484": the port follows the last colon.
			if (is_string($name) and ($colon = strrpos($name, ':')) !== false) {
				$port = substr($name, $colon + 1);
				if ($port !== '' and ctype_digit($port)) return $port;
			}
		}
		// No socket to ask. The configured listener is the best remaining
		// answer, and still better than a number nobody configured.
		return (string) (Q_WebServer::$port ?: 80);
	}

	protected function findIdle()
	{
		// Rescanned after a dead worker is replaced: foreach walks a copy of
		// the list taken before the loop, so the replacement is not in it.
		// Without the rescan, a request arriving when every idle worker had
		// died went on the queue -- with fresh workers idle and nothing left
		// to drain it.
		for ($pass = 0; $pass < 2; ++$pass) {
			$discarded = false;
			foreach ($this->workers as $i => $w) {
				if ($w['busy']) continue;
				// An idle worker's socket is not watched, so one that died
				// while idle (killed, OOM) is not noticed until a request is
				// sent to it -- and that request was lost. Ask first: a
				// non-blocking waitpid is one system call and also reaps it.
				if (!empty($w['pid']) and $this->workerGone($w)) {
					$this->discardDead($i);
					$discarded = true;
					continue;
				}
				return $i;
			}
			if (!$discarded) break;
		}
		return null;
	}

	/**
	 * Find idle workers that have exited and replace them, before a request
	 * is sent to one. Idle sockets are deliberately not in the event loop --
	 * hundreds of them would take stream_select past its descriptor limit --
	 * so this is how a death while idle is noticed ahead of time. findIdle()
	 * checks again at dispatch, and a request whose worker still dies first
	 * is retried (see recycle()); this keeps that path rare.
	 *
	 * @method sweepDeadIdle
	 * @return {integer} how many were found dead
	 */
	function sweepDeadIdle()
	{
		$dead = 0;
		foreach ($this->workers as $i => $w) {
			if (!empty($w['busy']) or empty($w['pid'])) continue;
			if ($this->workerGone($w)) {
				$this->discardDead($i);
				++$dead;
			}
		}
		// Anything queued can go to the replacements now, rather than wait
		// for a busy worker to finish.
		while ($this->pending and ($idle = $this->findIdle()) !== null) {
			$next = array_shift($this->pending);
			$this->sendTo($idle, $next[0], $next[1], $next[2], $next[3] ?? null);
		}
		return $dead;
	}

	/**
	 * Drop a worker that has already exited (and been reaped), replacing it
	 * when the pool needs it: always for a fixed pool, below the spare count
	 * for a dynamic one.
	 */
	protected function discardDead($index)
	{
		fwrite(STDERR, sprintf("  worker %d exited while idle; removed\n",
			(int) ($this->workers[$index]['pid'] ?? 0)));
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
			unset($this->watchers[$index]);
		}
		$sock = $this->workers[$index]['socket'] ?? null;
		if (is_resource($sock)) @fclose($sock);
		$this->forgetWorker($index);
		if ($this->spareWorkers <= 0 or count($this->workers) < $this->spareWorkers) {
			$this->forkWorker();
		}
	}

	/**
	 * Change the pool's size while it runs -- the panel's workers/resize.
	 *
	 * A fixed pool forks up to $count at once, and above it retires idle
	 * workers now and busy ones as each finishes its request. A dynamic pool
	 * takes $count as its new ceiling; its spare count is kept, capped at the
	 * new ceiling. Nothing a worker is doing is interrupted.
	 * @method resize
	 * @param {integer} $count
	 * @return {array} target, workers running now, and extras still busy
	 */
	function resize($count)
	{
		$count = max(1, (int) $count);
		$this->targetSize = $count;
		if ($this->spareWorkers > $count) $this->spareWorkers = $count;
		if ($this->spareWorkers <= 0) {
			while (count($this->workers) < $count) $this->forkWorker();
		}
		if (count($this->workers) > $count) {
			$idle = array();
			foreach ($this->workers as $i => $w) {
				if (empty($w['busy'])) $idle[$i] = $w['idleSince'] ?? 0;
			}
			asort($idle);
			foreach ($idle as $i => $since) {
				if (count($this->workers) <= $count) break;
				$this->retire($i);
			}
		}
		return array('target' => $this->targetSize, 'workers' => count($this->workers),
			'retiring' => max(0, count($this->workers) - $count));
	}

	/**
	 * Answer 504 for, and kill, every worker still on a request after
	 * $timeout seconds -- Q.webserver.requestTimeout. The limit was enforced
	 * only on fork-per-request children, so a pooled script caught in a loop
	 * or waiting on a dead backend held its worker, and its visitor, for
	 * ever. The client is answered and let go first, so the worker's death
	 * is not taken for a lost request and run again on another worker, where
	 * it would hang the same way. The socket then reaches EOF and recycle()
	 * reaps and replaces the worker as for any other death.
	 * @method killOverdue
	 * @param {float} $timeout seconds; 0 or less does nothing
	 * @return {integer} how many workers were killed
	 */
	function killOverdue($timeout)
	{
		if ($timeout <= 0) return 0;
		$now = microtime(true);
		$killed = 0;
		foreach ($this->workers as $index => $w) {
			if (empty($w['busy']) or !empty($w['dying'])) continue;
			$started = $this->workerStarted[$index] ?? null;
			if ($started === null or $now - $started <= $timeout) continue;
			$parsed = $this->workerRequests[$index] ?? array();
			$method = $parsed['method'] ?? 'GET';
			$uri = $parsed['uri'] ?? '/';
			$ms = round(($now - $started) * 1000, 1);
			$body = 'Request timed out';
			$client = $this->workerClients[$index] ?? null;
			$responder = $this->workerResponders[$index] ?? null;
			if ($responder) {
				call_user_func($responder, array('status' => 504,
					'headers' => array('Content-Type' => 'text/plain; charset=utf-8'),
					'body' => $body));
			} elseif ($client and is_resource($client)) {
				Q_WebServer::sendResponse($client, 504, $body);
				Q_WebServer::closeClient((int) $client);
				if (is_resource($client)) @fclose($client);
			}
			unset($this->workerClients[$index], $this->workerResponders[$index]);
			// Still busy, so nothing is dispatched to it while it dies.
			$this->workers[$index]['dying'] = true;
			$pid = (int) ($w['pid'] ?? 0);
			if ($pid > 0) @posix_kill($pid, SIGKILL);
			fwrite(STDERR, sprintf("  worker %d killed after %.1fs on %s %s (requestTimeout %ss)\n",
				$pid, $now - $started, $method, $uri, $timeout));
			Q_WebServer_Dashboard::recordRequest($method, $uri, 504, $ms, 0, true);
			if (class_exists('Q_WebServer_Metrics', false)) {
				Q_WebServer_Metrics::recordRequest(504, $ms, $method, $uri, '', '', 0);
			}
			++$killed;
		}
		return $killed;
	}

	/**
	 * Gracefully recycle a single worker: let it finish its current request,
	 * then replace it with a fresh fork.
	 * If the worker is idle, recycle immediately.
	 */
	function recycleWorker($index)
	{
		if (!isset($this->workers[$index])) return false;
		if ($this->workers[$index]['busy']) {
			// Mark for recycling after current request finishes
			$this->workers[$index]['recycleAfter'] = true;
			return 'pending';
		}
		$this->recycle($index, false);
		return 'recycled';
	}

	/**
	 * Gracefully recycle ALL workers (rolling restart).
	 * Idle workers are replaced immediately. Busy workers are marked
	 * and replaced when their current request finishes.
	 * Returns count of immediately recycled vs pending.
	 */
	function recycleAll()
	{
		$immediate = 0;
		$pending = 0;
		foreach ($this->workers as $i => $w) {
			if ($w['busy']) {
				$this->workers[$i]['recycleAfter'] = true;
				$pending++;
			} else {
				$this->recycle($i, false);
				$immediate++;
			}
		}
		return ['immediate' => $immediate, 'pending' => $pending];
	}

	/**
	 * Get stats for the control panel.
	 */
	function workerStats()
	{
		$stats = [];
		foreach ($this->workers as $i => $w) {
			$stats[] = [
				'index' => $i,
				'pid' => $w['pid'],
				'busy' => $w['busy'],
				'requests' => $w['requests'] ?? 0,
				'recycleAfter' => !empty($w['recycleAfter']),
			];
		}
		return [
			'workers' => $stats,
			'total' => count($this->workers),
			'busy' => count(array_filter($this->workers, function($w) { return $w['busy']; })),
			'idle' => count(array_filter($this->workers, function($w) { return !$w['busy']; })),
			'maxRequests' => $this->maxRequests,
			'mode' => $this->octane ? 'persistent' : 'fork-per-request',
			'pending' => count($this->pending),
		];
	}

	/**
	 * Graceful shutdown: SIGTERM all workers, wait up to $timeout seconds,
	 * then SIGKILL any remaining.
	 * @param {float} $timeout Seconds to wait after SIGTERM before SIGKILL
	 */
	function shutdown($timeout = 3.0)
	{
		// Cancel watchers and close sockets
		foreach ($this->workers as $i => $w) {
			if (isset($this->watchers[$i])) {
				Q_Evented::cancel($this->watchers[$i]);
			}
			if (is_resource($w['socket'])) @fclose($w['socket']);
		}

		// Send SIGTERM to all workers
		foreach ($this->workers as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGTERM);
		}

		// Wait for workers to exit gracefully. One the zygote forked is not
		// this process's child: waitpid() answers -1 for it at once, so it is
		// watched with processAlive() instead, and the zygote reaps it.
		$deadline = microtime(true) + $timeout;
		$remaining = $this->workers;
		while (!empty($remaining) && microtime(true) < $deadline) {
			foreach ($remaining as $i => $w) {
				if (!empty($w['zygote'])) {
					if (!self::processAlive((int) $w['pid'])) unset($remaining[$i]);
					continue;
				}
				$result = Q_WebServer_Fork::waitpid($w['pid'], $st, 1);
				if ($result > 0 || $result === -1) {
					unset($remaining[$i]);
				}
			}
			if (!empty($remaining)) {
				usleep(50000); // 50ms
			}
		}

		// SIGKILL any workers that didn't exit in time
		foreach ($remaining as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGKILL);
			if (empty($w['zygote'])) Q_WebServer_Fork::waitpid($w['pid'], $st, 0);
		}

		$this->workers = array();
		$this->stopZygote();
	}

	function idleCount()
	{
		$n = 0;
		foreach ($this->workers as $w) if (!$w['busy']) $n++;
		return $n;
	}

	/**
	 * Workers running now. Equal to targetSize for a fixed pool; between
	 * spareWorkers and targetSize for a dynamic one.
	 */
	function liveCount()
	{
		return count($this->workers);
	}

	/**
	 * Get per-worker stats: PID, busy status, RSS memory.
	 * Linux: /proc/$pid/statm. macOS: ps -o rss. Windows: tasklist.
	 */
	/** The live workers' pids, from memory. */
	function pids()
	{
		$pids = array();
		foreach ($this->workers as $w) $pids[] = $w['pid'];
		return $pids;
	}

	function getWorkerStats()
	{
		$count = count($this->workers);

		// Memory is SAMPLED, never read per worker. This runs every couple of
		// seconds while a dashboard is open, in the single event-loop process, and
		// reading /proc for hundreds of workers there stalled the loop long enough
		// to wedge the whole server -- the front page timed out, not just the
		// dashboard. Workers are near-identical (same fork, same warmed baseline),
		// so a bounded sample scaled to the count is the right order of magnitude
		// at a fixed cost, where the per-worker read was O(workers) and unbounded.
		$pids = array();
		foreach ($this->workers as $w) $pids[] = $w['pid'];
		$sample = $pids;
		$sampleMax = 24;
		if (count($sample) > $sampleMax) {
			shuffle($sample);
			$sample = array_slice($sample, 0, $sampleMax);
		}

		$rssMap = self::getProcessRss($sample);
		$pssMap = self::getProcessPss($sample);
		$avgRss = $rssMap ? array_sum($rssMap) / count($rssMap) : 0;
		$avgPss = $pssMap ? array_sum($pssMap) / count($pssMap) : 0;
		$totalRss = (int) round($avgRss * $count);
		$totalPss = 0;
		if ($avgPss > 0) {
			// The parent holds the shared pages the workers' PSS is a fraction of;
			// read it exactly and add it.
			$selfMap = self::getProcessPss(array(getmypid()));
			$totalPss = (int) round($avgPss * $count) + ($selfMap[getmypid()] ?? 0);
		}

		return array(
			// Per-worker detail is deliberately omitted: the UI does not use it and
			// a list of hundreds would be built and sent on every tick.
			'workers' => array(),
			// The pids alone are free -- already in memory, no /proc read -- and
			// tell a monitor (or a test) whether workers were replaced.
			'pids' => $pids,
			'count' => $count,
			'target' => $this->targetSize,
			'idle' => $this->idleCount(),
			'sampled' => count($sample) < $count ? count($sample) : 0,
			'totalRssKb' => $totalRss,
			// 0 off Linux; the dashboard then falls back to RSS and says so.
			'totalPssKb' => $totalPss,
		);
	}

	/**
	 * Get RSS in KB for a list of PIDs. Cross-platform.
	 */
	/**
	 * Real memory (PSS) in KB for a list of PIDs, Linux only.
	 *
	 * RSS counts a shared copy-on-write page in full against every process that
	 * maps it, so summing worker RSS reports the warmed baseline once per worker
	 * -- hundreds of workers each showing ~40 MB became ~15 GB, which is the
	 * opposite of what copy-on-write does. PSS ("proportional set size") divides
	 * each shared page by the number of processes sharing it, so the sum over
	 * the parent and its workers is the actual physical memory. That is the
	 * number the "Worker Memory (COW)" card exists to show.
	 *
	 * smaps_rollup is one small read per pid; the caller caches it. Returns an
	 * empty map off Linux, where the card falls back to RSS.
	 */
	static function getProcessPss($pids)
	{
		$map = array();
		if (empty($pids) || PHP_OS_FAMILY !== 'Linux') return $map;
		foreach ($pids as $pid) {
			$roll = @file_get_contents("/proc/$pid/smaps_rollup");
			if ($roll && preg_match('/^Pss:\s+(\d+)/m', $roll, $m)) {
				$map[$pid] = (int) $m[1]; // already KB
			}
		}
		return $map;
	}

	static function getProcessRss($pids)
	{
		$map = array();
		if (empty($pids)) return $map;

		if (PHP_OS_FAMILY === 'Linux') {
			foreach ($pids as $pid) {
				$statm = @file_get_contents("/proc/$pid/statm");
				if ($statm) {
					$fields = explode(' ', $statm);
					$map[$pid] = (int) ($fields[1] ?? 0) * 4; // pages * 4KB
				}
			}
		} elseif (PHP_OS_FAMILY === 'Darwin') {
			// macOS: single ps call for all PIDs
			$pidList = implode(',', $pids);
			$out = @shell_exec("ps -o pid=,rss= -p $pidList 2>/dev/null");
			if ($out) {
				foreach (explode("\n", trim($out)) as $line) {
					$parts = preg_split('/\s+/', trim($line));
					if (count($parts) >= 2) {
						$map[(int) $parts[0]] = (int) $parts[1]; // ps rss is in KB
					}
				}
			}
		} elseif (PHP_OS_FAMILY === 'Windows') {
			// Windows: tasklist for each PID
			foreach ($pids as $pid) {
				$out = @shell_exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH 2>NUL");
				if ($out && preg_match('/"(\d[\d,]+)\s*K"/', $out, $m)) {
					$map[$pid] = (int) str_replace(',', '', $m[1]);
				}
			}
		}
		return $map;
	}

	// ── Wire helpers ─────────────────────────────────────

	protected static function readExact($sock, $n)
	{
		$buf = '';
		while (strlen($buf) < $n) {
			$c = fread($sock, $n - strlen($buf));
			if ($c === false || $c === '') {
				// '' means EOF only when feof() says so. A read timeout —
				// or a signal interrupting the read — also yields '', and
				// treating that as EOF kills a perfectly healthy worker.
				if (feof($sock)) return false;
				$meta = @stream_get_meta_data($sock);
				if (!empty($meta['timed_out'])) continue;
				return false;
			}
			$buf .= $c;
		}
		return $buf;
	}

	protected static function writeMsg($sock, $status, $body, $headers,
		$cookies = array(), $recycle = '', $mem = 0)
	{
		// json_encode() returns false on bytes that are not valid UTF-8, and
		// strlen(false) is 0, so a binary body used to go out as a length
		// prefix of zero and nothing else: the parent read an empty frame and
		// answered with an empty response while still logging 200. Anything a
		// script generated that was not text -- an image, a PDF, a zip --
		// vanished silently. Base64 carries those bytes through, and only
		// those: text responses keep their exact previous shape and cost.
		//
		// The cookies travel with it: they are built in the worker and this
		// is the only thing that carries them out.
		// A worker that failed its health check asks to be replaced. Only
		// present when it did, so a healthy response keeps its exact shape.
		$fields = array('status', 'body', 'headers', 'cookies');
		if ($recycle !== '') {
			$_recycle = $recycle;
			$fields[] = '_recycle';
		}
		// The request's heap peak, when the worker could measure it.
		if ($mem > 0) {
			$_mem = (int) $mem;
			$fields[] = '_mem';
		}
		$j = json_encode(compact($fields));
		if ($j === false) {
			$b64 = true;
			$body = base64_encode($body);
			$fields[] = 'b64';
			$j = json_encode(compact($fields));
		}
		if ($j === false) {
			// Headers themselves are not encodable. Say so rather than
			// hanging up on the client.
			$j = json_encode(array(
				'status' => 500,
				'body' => 'Response could not be encoded',
				'headers' => array('Content-Type' => 'text/plain')
			));
		}
		// The worker's reply is length-prefixed exactly like the request that
		// arrived, so a short write here desynchronises the parent's reader
		// instead of losing a few bytes: it takes the length we declared,
		// finds the next reply beginning inside this one, and every response
		// after it belongs to the wrong request.
		//
		// Written out rather than handed to Q_WebServer::writeAll(), which
		// ends by putting the stream back into non-blocking mode. That is
		// right for the event loop it was written for and wrong here:
		// childRun() sets this socket blocking on purpose and then waits on it
		// for the next request, so a worker that came back non-blocking read
		// an empty string and exited. The first request of each worker
		// succeeded and the second did not.
		//
		// The socket is already blocking, so a plain loop completes the write
		// without touching the mode.
		$packet = pack('N', strlen($j)) . $j;
		$length = strlen($packet);
		$written = 0;
		while ($written < $length) {
			$n = @fwrite($sock, substr($packet, $written));
			if ($n === false or $n === 0) break;
			$written += $n;
		}
	}
}

/**
 * Custom stream wrapper for php://input in persistent workers.
 * PHP's built-in php://input is empty in CLI SAPI for included scripts.
 * This wrapper reads from $GLOBALS['_Q_RAW_INPUT'] which the Pool sets
 * before each request.
 *
 * Only handles php://input — all other php:// streams pass through to
 * PHP's built-in handler.
 *
 * @class Q_WebServer_PhpInputStream
 */
class Q_WebServer_PhpInputStream
{
	/**
	 * Set by PHP on every stream wrapper instance. Declared, because PHP 8.2
	 * deprecated dynamic properties: undeclared, each php://input read printed
	 * "Creation of dynamic property ...::$context is deprecated" -- into the
	 * response body, wherever notices are displayed.
	 * @var resource|null
	 */
	public $context;
	protected $data = '';
	protected $pos = 0;
	protected $path = '';

	/**
	 * @var resource|null Fallback wrapper handle for non-input streams
	 */
	protected $fallback = null;

	function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->path = $path;
		if ($path === 'php://input') {
			$this->data = $GLOBALS['_Q_RAW_INPUT'] ?? '';
			$this->pos = 0;
			return true;
		}
		// For php://stdout, php://stderr, php://temp, php://memory, etc.
		// restore the built-in wrapper, open, then re-register ours
		stream_wrapper_restore('php');
		$this->fallback = fopen($path, $mode);
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', __CLASS__);
		return $this->fallback !== false;
	}

	function stream_read($count)
	{
		if ($this->fallback) return fread($this->fallback, $count);
		$chunk = substr($this->data, $this->pos, $count);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	function stream_write($data)
	{
		if ($this->fallback) return fwrite($this->fallback, $data);
		return 0;
	}

	function stream_tell()
	{
		if ($this->fallback) return ftell($this->fallback);
		return $this->pos;
	}

	function stream_eof()
	{
		if ($this->fallback) return feof($this->fallback);
		return $this->pos >= strlen($this->data);
	}

	function stream_stat()
	{
		if ($this->fallback) return fstat($this->fallback);
		return ['size' => strlen($this->data)];
	}

	function stream_close()
	{
		if ($this->fallback) { fclose($this->fallback); $this->fallback = null; }
	}

	function stream_seek($offset, $whence = SEEK_SET)
	{
		if ($this->fallback) return fseek($this->fallback, $offset, $whence) === 0;
		switch ($whence) {
			case SEEK_SET: $this->pos = $offset; break;
			case SEEK_CUR: $this->pos += $offset; break;
			case SEEK_END: $this->pos = strlen($this->data) + $offset; break;
		}
		return true;
	}


}
