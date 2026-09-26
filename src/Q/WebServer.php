<?php
/**
 * @module Q
 */

/**
 * Pure-PHP web server for Qbix apps.
 *
 * Serves static files with readfile(), ETag/304, companion .headers.
 * Routes .php scripts to pre-forked workers (Q_WebServer_Pool).
 * Upgrades WebSocket connections via Q_WebSocket.
 * Responsive directory listings with media previews.
 * Runs entirely on Q_Evented.
 *
 * @class Q_WebServer
 */
class Q_WebServer
{

	/**
	 * Write a string to a stream socket in full, looping over partial writes.
	 *
	 * PHP's fwrite() on a stream socket is NOT guaranteed to write the entire
	 * buffer in one call. Once the kernel send buffer fills up, fwrite() returns
	 * the number of bytes actually written and the remainder is silently lost.
	 * This was reproduced with a ~47MB file: only the first ~3MB reached the
	 * client. Every response-writing call site must loop until all bytes are
	 * written instead of trusting a single fwrite().
	 *
	 * The server uses non-blocking sockets for the event loop, so we
	 * temporarily switch to blocking mode for the write, then restore.
	 * This is safe because response writes happen synchronously in the
	 * fork child or worker — no other I/O is multiplexed during a write.
	 *
	 * Credit: @jukkakangas (GitHub Issue #26)
	 *
	 * @method writeAll
	 * @static
	 * @param {resource} $stream
	 * @param {string} $data
	 * @return {boolean} true if all bytes were written, false on error
	 */
	static function writeAll($stream, $data)
	{
		$length = strlen($data);
		if ($length === 0) return true;

		// Switch to blocking mode for the write — ensures fwrite()
		// waits for buffer space instead of returning 0 immediately.
		// This is safe in fork/worker mode (single request per process).
		stream_set_blocking($stream, true);

		$written = 0;
		$ok = true;
		while ($written < $length) {
			$chunk = @fwrite($stream, substr($data, $written));
			if ($chunk === false || $chunk === 0) {
				$ok = false;
				break;
			}
			$written += $chunk;
		}

		// Restore non-blocking mode for the event loop
		stream_set_blocking($stream, false);
		return $ok;
	}

	/**
	 * Write every byte to a non-blocking stream without blocking the loop.
	 *
	 * writeAll() above switches the stream to blocking, which is right for a
	 * process that owns one connection and has nothing else to do until the
	 * write finishes. It is wrong wherever a single process is multiplexing
	 * work for many peers: the parent dispatching to a pool of workers, or a
	 * broadcast walking every WebSocket client. Blocking there lets one
	 * unresponsive peer hold up everybody else.
	 *
	 * This waits for writability instead, with a deadline, and reports failure
	 * rather than stalling. The distinction matters because the data at these
	 * call sites is length-prefixed: a short write is not partial delivery, it
	 * is corruption, since the reader consumes the length we declared and then
	 * finds the next record starting mid-payload.
	 *
	 * @method writeFully
	 * @static
	 * @param {resource} $stream a non-blocking stream
	 * @param {string} $data
	 * @param {double} [$timeout=2.0] seconds to wait in total for buffer space
	 * @return {boolean} false if the stream died or stayed full past $timeout
	 */
	static function writeFully($stream, $data, $timeout = 2.0)
	{
		$length = strlen($data);
		if ($length === 0) return true;
		if (!is_resource($stream)) return false;

		$written = 0;
		$deadline = microtime(true) + $timeout;
		while ($written < $length) {
			$n = @fwrite($stream, substr($data, $written));
			if ($n === false) return false;
			if ($n === 0) {
				// The buffer is full. Wait for the reader to drain it.
				$remaining = $deadline - microtime(true);
				if ($remaining <= 0) return false;
				$read = null;
				$except = null;
				$write = array($stream);
				$sec = (int) $remaining;
				$usec = (int) (($remaining - $sec) * 1000000);
				if (@stream_select($read, $write, $except, $sec, $usec) <= 0) {
					return false;
				}
				continue;
			}
			$written += $n;
		}
		return true;
	}

	/**
	 * Search paths for app/user files.
	 *
	 * Q::$paths is declared by the webserver's STANDALONE shim (src/Q.php), not
	 * by the Platform's Q.php. In --app mode the Platform's Q is authoritative,
	 * so dereferencing Q::$paths there raised
	 * "Access to undeclared static property Q::$paths" and turned every request
	 * into a 500. Use this instead of touching the property directly.
	 */
	static function paths()
	{
		// NOTE: read via reflection rather than Q::$paths directly. The property is
		// declared by this repo's standalone shim, NOT by the Platform's Q.php, and
		// naming it directly is exactly the coupling this class exists to avoid.
		if (property_exists('Q', 'paths')) {
			$declared = (new ReflectionClass('Q'))->getStaticPropertyValue('paths', null);
			if (is_array($declared)) {
				return $declared;
			}
		}
		$paths = array();
		if (defined('APP_DIR') && APP_DIR) { $paths[] = APP_DIR; }
		if (defined('Q_DIR') && Q_DIR)     { $paths[] = Q_DIR; }
		return $paths;
	}

	/** @property $pool Q_WebServer_Pool|null */
	static $pool = null;
	/** @property $rootDir Document root with trailing DS */
	public static $rootDir;
	public static $serverDir;
	/** @property $host Bound host */
	public static $host;
	/** @property $port Bound port */
	public static $port;
	/** @property $udsPath Unix domain socket path, if listening on UDS */
	public static $udsPath = null;
	/** @var resource UDS listener socket */
	private static $udsSocket = null;
	/** @var mixed UDS accept watcher ID */
	private static $udsWatcher = null;
	/** @property $onRequest Logging callback(method, uri, status, ms) */
	static $onRequest = null;

	// ── Lifecycle ────────────────────────────────────────

	/**
	 * @method start
	 * @static
	 * @param {string} $dir Document root
	 * @param {string} [$host='0.0.0.0']
	 * @param {int} [$port=80]
	 * @param {int} [$workers=0] 0=in-process, N=prefork pool
	 */
	/**
	 * Refuse to run without per-request process isolation.
	 * Qbix (and ordinary PHP) assume one request per process. Fork gives that
	 * on Unix, php-cgi on Windows. With neither, a process would serve many
	 * requests and inherit the previous one's state -- which fails silently on
	 * the SECOND request, the worst possible shape to debug.
	 * @method requireIsolation
	 * @static
	 */
	static function requireIsolation()
	{
		// Load Fork abstraction
		$forkFile = dirname(__DIR__) . '/Q/WebServer/Fork.php';
		if (!class_exists('Q_WebServer_Fork', false) && is_file($forkFile)) {
			require_once $forkFile;
		}
		if (class_exists('Q_WebServer_Fork', false) && Q_WebServer_Fork::available()) {
			return true;
		}
		$bin = Q_Config::get('Q', 'webserver', 'cgi', 'binary', null);
		if (!$bin) {
			foreach (array('php-cgi','php-cgi8.3','php-cgi8.2','php-cgi8.1') as $b) {
					$whichCmd = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
					$nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
					$w = @shell_exec("$whichCmd $b 2>$nullDev");
					if (trim((string) $w) !== '') { $bin = trim(explode("\n", $w)[0]); break; }
			}
		}
		if ($bin) {
			return true;
		}
		fwrite(STDERR, "\n  ERROR: no per-request process isolation available.\n"
			. "  Neither the pcntl extension (fork) nor a php-cgi binary was found.\n"
			. "  Install one, or set Q.webserver.cgi.binary in your config.\n\n");
		exit(1);
	}

	/**
	 * Refuse to start without an extension this configuration needs
	 * (tokenizer for the source transform, openssl for HTTPS), printing the
	 * install command; warn once, with the commands, about extensions missing
	 * from the standard set. Q.webserver.extensionsCheck = false silences
	 * the warning (never the refusal).
	 * @method requireExtensions
	 * @static
	 * @param {boolean} $https whether HTTPS is configured
	 */
	static function requireExtensions($https)
	{
		if (!class_exists('Q_WebServer_Extensions', false)) {
			$f = __DIR__ . '/WebServer/Extensions.php';
			if (!is_file($f)) return;
			require_once $f;
		}
		$transform = !Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false);
		$need = Q_WebServer_Extensions::hardNeeds(array('https' => (bool) $https, 'transform' => $transform));
		if ($need) {
			$msg = "\n  ERROR: this PHP lacks what the server needs to start:\n";
			foreach ($need as $n) $msg .= "    $n[0]: $n[1]\n";
			try {
				$h = Q_WebServer_Extensions::installHints(array_column($need, 0));
				foreach ($h['commands'] as $c) $msg .= "  fix: $c\n";
			} catch (Throwable $e) {}
			fwrite(STDERR, $msg . "\n");
			exit(1);
		}
		// The control panel's store: created and, once, moved from the old
		// place here, in the parent, before any worker reads it. A store that
		// fails the trust rule locks the panel; say so at start, not first
		// at sign-in.
		try {
			$d = Q_WebServer_Panel_Store::dirs();
			$problem = Q_WebServer_Panel_Store::problem();
			fwrite(STDERR, $problem === null
				? "  panel: credentials in {$d['acl']}, sessions in {$d['sessions']}\n"
				: "  panel: LOCKED, sign-in refused: $problem\n  panel: run `qbixctl panel:check` for the fix\n");
		} catch (Throwable $e) {
			fwrite(STDERR, '  panel: cannot check its storage (' . $e->getMessage() . ")\n");
		}
		if (Q_Config::get('Q', 'webserver', 'extensionsCheck', true) === false) return;
		try {
			$w = Q_WebServer_Extensions::startupWarning();
		} catch (Throwable $e) {
			$w = '  extensions: cannot check (' . $e->getMessage() . ")\n";
		}
		if ($w !== '') fwrite(STDERR, $w);
	}

	static function start($dir, $host = '0.0.0.0', $port = 80, $workers = 0)
	{
		self::requireIsolation();
		if (self::$running) {
			throw new Exception("Q_WebServer already running");
		}

		// Ignore SIGPIPE — prevents crash when writing to a closed socket
		// (e.g. client disconnects, or worker dies mid-response)
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGPIPE, SIG_IGN);
		}

		// realpath() doesn't work on phar:// paths
		if (strpos($dir, 'phar://') === 0) {
			$root = $dir;
		} else {
			$root = realpath($dir);
		}
		if (!$root || !is_dir($root)) {
			throw new Exception("Invalid document root: $dir");
		}
		self::$rootDir = rtrim(str_replace(array('/','\\'), DS, $root), DS) . DS;
		self::$serverDir = defined('QBIX_SERVER_DIR') ? QBIX_SERVER_DIR : dirname(__DIR__, 2);
		self::$host = $host;
		self::$port = $port;

		// Initialize dashboard stats (uptime tracking)
		Q_WebServer_Dashboard::init();
		Q_WebServer_Log::init();
		// Cache settings saved in the control panel win over the configuration
		// file: laid over Q.web.cache here, before init() reads it, so they
		// survive a restart. Only Q.web.cache; nothing else is taken from there.
		try {
			if (!class_exists('Q_WebServer_Panel_Cache', false)) require_once __DIR__ . '/WebServer/Panel/Cache.php';
			Q_WebServer_Panel_Cache::applySaved();
		} catch (Throwable $e) {
			fwrite(STDERR, '  cache: panel settings not applied (' . $e->getMessage() . ")\n");
		}
		Q_WebServer_Cache::init();
		// Q.web.cache.components.enabled was read by nothing: init() was never
		// called, so the setting did nothing and the layer stayed off.
		Q_WebServer_Cache_Components::init();

		if ($ext = Q_Config::get('Q', 'webserver', 'extensions', null)) {
			self::$allowedExtensions = $ext;
		}

		// Read PHP ini limits — respect the same limits php-fpm would use
		self::$maxPostSize = self::parseIniBytes(
			Q_Config::get('Q', 'webserver', 'postMaxSize', ini_get('post_max_size') ?: '8M')
		);
		self::$maxUploadSize = self::parseIniBytes(
			Q_Config::get('Q', 'webserver', 'uploadMaxFilesize', ini_get('upload_max_filesize') ?: '2M')
		);
		self::$maxFileUploads = (int) Q_Config::get(
			'Q', 'webserver', 'maxFileUploads', ini_get('max_file_uploads') ?: 20
		);
		self::$fileUploads = (bool) Q_Config::get(
			'Q', 'webserver', 'fileUploads', ini_get('file_uploads') !== '0'
		);

		// File response cache config
		self::$fileCacheMaxSize = Q_Config::get('Q', 'webserver', 'fileCache', 'maxSize', 67108864);
		self::$fileCacheMaxFile = Q_Config::get('Q', 'webserver', 'fileCache', 'maxFile', 1048576);
		self::$fileCacheCheckInterval = Q_Config::get('Q', 'webserver', 'fileCache', 'checkInterval', 1);

		// ── HTTPS listener, first ─────────────────────────
		// Before plain HTTP, so there is never a moment when the server answers
		// on HTTP but not yet on HTTPS: the certificate is loaded -- or made,
		// see Q_WebServer_Certs::init() -- and HTTPS bound, then HTTP.
		// Starts automatically if:
		//   1. Q.web.https is configured (explicit), OR
		//   2. Cert files exist at the default location (auto-detect)
		$httpsConfig = Q_Config::get('Q', 'web', 'https', array());
		$httpsPort = (int) Q::ifset($httpsConfig, 'port', 443);
		$explicitHttps = !empty($httpsConfig);

		// Auto-detect: check for certs even without config
		if (!$explicitHttps) {
			$certsDir = Q_WebServer_Certs::certsDir();
			$defaultCert = $certsDir . DS . 'fullchain.pem';
			$defaultKey = $certsDir . DS . 'privkey.pem';
			if (is_file($defaultCert) && is_file($defaultKey)) {
				$explicitHttps = true; // certs found, enable HTTPS
			}
		}

		// The extensions this configuration cannot run without stop the start
		// here, with the fix; missing ones from the standard set are a warning.
		self::requireExtensions($explicitHttps);

		if ($explicitHttps) {
			self::$httpsPort = $httpsPort;

			$domain = Q::ifset($httpsConfig, 'domain', '');
			$certsReady = Q_WebServer_Certs::init($domain, $host);

			// Per-domain certificates already on disk (autohost / ACME) join the
			// SNI map before the listener binds, so the right certificate is
			// presented for each host name from the first handshake.
			if (class_exists('Q_WebServer_Autohost')
				and method_exists('Q_WebServer_Autohost', 'registerDomainCerts')) {
				Q_WebServer_Autohost::registerDomainCerts();
			}

			if ($certsReady) {
				self::startTls($host, $httpsPort);
			} else {
				fwrite(STDERR, "[HTTPS] No usable certificate, HTTPS disabled. "
					. "HTTP will still listen on port $port.\n");
			}
		}

		// ── HTTP listener ────────────────────────────────
		$errno = $errstr = 0;
		$socketPath = Q_Config::get('Q', 'webserver', 'socket', null);

		// TCP listener — always bind unless port is explicitly 0
		self::$socket = stream_socket_server(
			"tcp://{$host}:{$port}", $errno, $errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
			stream_context_create(array('socket' => array(
				'backlog' => self::listenBacklog(),
			)))
		);
		if (!self::$socket) {
			throw new Exception("Could not bind to {$host}:{$port} — $errstr");
		}
		stream_set_blocking(self::$socket, false);
		self::$acceptWatcher = Q_Evented::onReadable(
			self::$socket, array(__CLASS__, 'onAccept')
		);
		echo "[HTTP] Listening on http://{$host}:{$port}\n";

		// UDS listener — in addition to TCP, for the proxy hop
		if ($socketPath) {
			// Validate sun_path length (108 on Linux, 104 on macOS)
			$maxPath = PHP_OS_FAMILY === 'Darwin' ? 104 : 108;
			if (strlen($socketPath) >= $maxPath) {
				throw new Exception("Socket path too long (" . strlen($socketPath)
					. " >= $maxPath): $socketPath");
			}
			@unlink($socketPath); // clear stale socket from a prior run
			$socketDir = dirname($socketPath);
			if (!is_dir($socketDir)) {
				@mkdir($socketDir, 0755, true);
			}
			self::$udsSocket = stream_socket_server(
				"unix://{$socketPath}", $errno, $errstr,
				STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
			);
			if (!self::$udsSocket) {
				throw new Exception("Could not bind UDS {$socketPath} — $errstr");
			}
			stream_set_blocking(self::$udsSocket, false);
			self::$udsWatcher = Q_Evented::onReadable(
				self::$udsSocket, array(__CLASS__, 'onAccept')
			);
			// Set explicit permissions (default: 0660 — owner + group only)
			$mode = Q_Config::get('Q', 'webserver', 'socketMode', '0660');
			@chmod($socketPath, intval($mode, 8));
			self::$udsPath = $socketPath;
			echo "[HTTP] Listening on unix:{$socketPath}\n";
		}

		// ── Auto-TLS: provision certificates for configured domains ──
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		$acmeEmail = Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', '');
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$acmeStaging = (bool) Q_Config::get('Q', 'webserver', 'tls', 'acmeStaging', false);
		if (!empty($domains) && $acmeEmail) {
			foreach ($domains as $domainName => $domainConf) {
				$tlsMode = $domainConf['tls'] ?? null;
				if ($tlsMode !== 'auto') continue;

				$certPath = rtrim($certDir, '/') . '/' . $domainName . '/fullchain.pem';
				if (!Q_WebServer_Acme::needsRenewal($certPath)) continue;

				$action = is_file($certPath) ? 'Renewing' : 'Provisioning';
				$alts = $domainConf['aliases'] ?? [];
				$allDomains = array_merge([$domainName], $alts);
				// In the background: the CA validates by requesting a file from
				// this server, which a blocked event loop could never answer.
				$started = Q_WebServer_Certificate_Job::start('domain:' . $domainName,
					rtrim($certDir, '/') . '/' . $domainName . '/state.json',
					function () use ($allDomains, $certDir, $acmeEmail, $acmeStaging) {
						$r = Q_WebServer_Acme::provision($allDomains, $certDir, $acmeEmail, $acmeStaging);
						return array('ok' => !empty($r['success']), 'error' => $r['error'] ?? null);
					});
				if ($started) fwrite(STDERR, "  [ACME] {$action} certificate for {$domainName} in the background\n");
				// Start HTTPS once it is there, if nothing else started it.
				$keyPath = rtrim($certDir, '/') . '/' . $domainName . '/privkey.pem';
				$timer = null;
				$timer = Q_Evented::repeat(10.0, function () use (&$timer, $certPath, $keyPath, $domainName, $host, $httpsPort) {
					if (self::$tlsSocket) { Q_Evented::cancel($timer); return; }
					if (!Q_WebServer_Certs::pairUsable($certPath, $keyPath)) return;
					Q_Evented::cancel($timer);
					fwrite(STDERR, "  [ACME] Certificate ready for {$domainName}\n");
					Q_WebServer_Certs::init($domainName);
					self::$httpsPort = $httpsPort ?: 443;
					self::startTls($host, self::$httpsPort);
				});
			}
		}

		// ── Preload classes (before forking) ─────────────
		$preload = Q_Config::get('Q', 'webserver', 'preload', array());
		if (!empty($preload)) {
			// Load the autoloader first (e.g. Composer's)
			$autoload = is_string($preload)
				? $preload
				: (isset($preload['autoload']) ? $preload['autoload'] : null);
			if ($autoload) {
				$autoloadPath = $autoload;
				// Resolve relative to the document root's parent (project root)
				if ($autoloadPath[0] !== '/' && $autoloadPath[0] !== '\\') {
					$projectRoot = dirname(rtrim(self::$rootDir, DS));
					$autoloadPath = $projectRoot . DS . $autoloadPath;
				}
				if (file_exists($autoloadPath)) {
					require_once $autoloadPath;
					$count = count(get_declared_classes());
					echo "  Autoloader: " . basename($autoload) . "\n";
					// This runs before the pool exists, so before the source
					// transform is installed. With the transform on, whatever
					// the file loads keeps its real exit and header() in every
					// worker -- exit then ends the worker, not the request. A
					// script meant to warm the application in the parent
					// belongs in Q.webserver.warmup, which runs after it.
					if (!Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false)) {
						fwrite(STDERR, "  Warning: Q.webserver.preload is loaded before the source"
							. " transform; code it loads keeps real exit/header()."
							. " Use Q.webserver.warmup for a parent warm-up.\n");
					}
				} else {
					echo "  Warning: autoload file not found: $autoloadPath\n";
				}
			}
			// Then load each named class (triggers the autoloader)
			$classes = isset($preload['classes']) ? $preload['classes'] : array();
			if (!empty($classes)) {
				$loaded = 0;
				foreach ($classes as $class) {
					if (!class_exists($class, true) && !interface_exists($class, true)
						&& !trait_exists($class, true)
					) {
						echo "  Warning: could not preload $class\n";
					} else {
						$loaded++;
					}
				}
				echo "  Preloaded: $loaded classes\n";
			}
		}

		// ── Worker pool ──────────────────────────────────
		// Workers are auto-detected in qbixserver.php before start() is called.
		// If $workers > 0, create the pool.
		if ($workers > 0 && Q_WebServer_Fork::available()) {
			self::$pool = new Q_WebServer_Pool($workers);
		}

		self::$running = true;

		// Register getallheaders() / apache_request_headers() for PHP scripts
		$gahFile = __DIR__ . '/WebServer/GetAllHeaders.php';
		if (!class_exists('Q_WebServer_GetAllHeaders', false) && is_file($gahFile)) {
			require_once $gahFile;
		}
		if (class_exists('Q_WebServer_GetAllHeaders', false)) {
			Q_WebServer_GetAllHeaders::register();
		}
	}

	/**
	 * Start or restart the TLS listener.
	 * Uses tcp:// + stream_socket_enable_crypto() for non-blocking
	 * TLS handshake. The handshake happens per-connection in the
	 * event loop, not during accept.
	 *
	 * @method startTls
	 * @static
	 */
	static function startTls($host, $port)
	{
		self::$tlsHost = $host;
		if (self::$tlsWatcher) {
			Q_Evented::cancel(self::$tlsWatcher);
			self::$tlsWatcher = null;
		}
		if (self::$tlsSocket) {
			if (is_resource(self::$tlsSocket)) @fclose(self::$tlsSocket);
			self::$tlsSocket = null;
		}

		if (!Q_WebServer_Certs::validateCerts()) return;

		// Listen on plain tcp:// — TLS handshake happens after accept
		$errno = $errstr = 0;
		// One SSL context for the listener, inherited by every connection it
		// accepts, rather than a fresh one built per socket after accept.
		//
		// A context is what holds the session cache and the ticket key, so a
		// per-connection context means no client can ever resume: every visit,
		// and every new connection within a visit, pays a full handshake
		// including the certificate chain. Measured on this server: openssl
		// -reconnect reported 0 resumed handshakes and no session ticket
		// offered at all.
		//
		// The handshake itself still happens on the accepted socket through
		// stream_socket_enable_crypto(), which is what keeps accept()
		// non-blocking; only the configuration moves.
		//
		// A renewed certificate is put on this same context by reloadTls(),
		// so the next handshake presents it without re-binding, and every
		// connection accepted after that point agrees on which one it got.
		// Per-domain certificates for SNI, if any were registered (autohost /
		// ACME provisioning, or Q.web.https.domains). OpenSSL presents the one
		// whose name matches the client's SNI; local_cert above stays the
		// default for every unmatched name, so a single-cert server -- an empty
		// map -- keeps exactly the behaviour it had. This does not change the
		// cipher suite or the minimum TLS version: only which certificate is
		// presented. The context is shared by every accepted connection, which
		// is what still lets a session be resumed.
		$sniCerts = Q_WebServer_Certs::sniCerts();

		$tlsContext = stream_context_create(array('ssl' => array(
			'local_cert' => Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath,
			'local_pk' => Q_WebServer_Certs::$activeKey ?: Q_WebServer_Certs::$keyPath,
			'allow_self_signed' => true,
			'verify_peer' => false,
			'verify_peer_name' => false,
			'honor_cipher_order' => true,
			'single_ecdh_use' => true,
			'single_dh_use' => true,
		) + ($sniCerts ? array('SNI_server_certs' => $sniCerts) : array())
		  + (self::http2Enabled()
			? array('alpn_protocols' => 'h2,http/1.1')
			: array()),
			'socket' => array('backlog' => self::listenBacklog()),
		));

		self::$tlsSocket = stream_socket_server(
			"tcp://{$host}:{$port}", $errno, $errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
			$tlsContext
		);
		if (!self::$tlsSocket) {
			echo "[HTTPS] Could not bind to {$host}:{$port} — $errstr\n";
			return;
		}
		stream_set_blocking(self::$tlsSocket, false);

		self::$tlsWatcher = Q_Evented::onReadable(
			self::$tlsSocket,
			function ($sock) { Q_WebServer::onAcceptTls($sock); }
		);
		echo "[HTTPS] Listening on https://{$host}:{$port}\n";
	}

	/**
	 * Accept a connection on the TLS port and begin
	 * non-blocking crypto handshake.
	 *
	 * @method onAcceptTls
	 * @static
	 */
	static function onAcceptTls($serverSocket)
	{
		$client = @stream_socket_accept($serverSocket, 0);
		if (!$client) return;

		stream_set_blocking($client, false);

		// Disable Nagle's algorithm, exactly as the plain accept path does.
		//
		// A response goes out as more than one write -- HEADERS, then DATA, and
		// for HTTP/2 a SETTINGS and WINDOW_UPDATE before either. With Nagle on,
		// the second small write is held until the first is acknowledged, and
		// the peer's delayed-ACK timer does not fire for 40ms. The result is a
		// fixed 40ms added to a request whose actual work is closer to one.
		//
		// The plain listener has disabled it since the beginning, with a
		// comment naming this exact figure. This listener was added later and
		// did not, so every TLS request paid it while every plain one did not.
		// Measured on the same cached page, same server, same moment:
		//
		//     loopback, HTTP/1.1     0.0 ms
		//     LAN address, HTTP/1.1  1.6 ms
		//     TLS, after handshake  41.7 ms
		//
		// It hides in plain sight because the number looks like network
		// latency, and because under concurrency it disappears: other streams
		// supply the bytes that would have waited, so the stall only afflicts
		// a server that is not busy. A benchmark at high concurrency shows
		// nothing wrong.
		if (function_exists('socket_import_stream')) {
			$rawSocket = @socket_import_stream($client);
			if ($rawSocket) {
				@socket_set_option($rawSocket, SOL_TCP, TCP_NODELAY, 1);
			}
		}

		// Nothing is set on this socket. It inherits the listener's context,
		// which is the point: a shared context is what allows a session to be
		// resumed, and setting an option here would give this connection one
		// of its own again. The certificate, the key and the ALPN list are all
		// configured once, where the listener is created.

		$key = (int) $client;
		self::$clients[$key] = $client;
		self::$buffers[$key] = '';
		self::$tlsPending[$key] = true;

		// Start the handshake — may need multiple attempts
		self::continueTlsHandshake($key);
	}

	/** @var array client key => Q_WebServer_Http2_Connection */
	static $http2 = array();

	/**
	 * Whether HTTP/2 may be negotiated.
	 *
	 * Off unless asked for. A server that offers h2 and then mishandles a
	 * frame is worse than one that never offered it, because a client commits
	 * to the protocol during the handshake and will not fall back to HTTP/1.1
	 * afterwards -- the page simply does not arrive.
	 *
	 * @method http2Enabled
	 * @static
	 * @return {boolean}
	 */
	static function http2Enabled()
	{
		static $enabled = null;
		if ($enabled === null) {
			$enabled = (bool) Q_Config::get('Q', 'web', 'http2', 'enabled', false);
		}
		return $enabled;
	}

	/**
	 * Hand a socket that negotiated h2 to an HTTP/2 connection, and read it
	 * through the event loop like any other client.
	 *
	 * @method startHttp2
	 * @static
	 * @param {integer} $key
	 * @param {resource} $client
	 */
	static function startHttp2($key, $client)
	{
		$conn = new Q_WebServer_Http2_Connection($client, function ($request) use ($key) {
			return Q_WebServer::http2Request($key, $request);
		});
		self::$http2[$key] = $conn;
		$conn->start();

		self::$clientWatchers[$key] = Q_Evented::onReadable(
			$client,
			function ($c) { Q_WebServer::onHttp2Data($c); }
		);

		// Read now, not only when the socket next says it is readable. With
		// TLS 1.3 the browser sends its last handshake message and its first
		// requests in one flight, and the handshake that just finished read
		// them off the socket into OpenSSL's buffer. The socket then has
		// nothing to report, so those requests waited until the browser sent
		// something else -- over real latency, until it gave up and reported
		// the stylesheets, header images and scripts as aborted. On an empty
		// read this simply returns.
		self::onHttp2Data($client);
	}

	/**
	 * Readable data on an HTTP/2 connection.
	 *
	 * @method onHttp2Data
	 * @static
	 * @param {resource} $client
	 */
	static function onHttp2Data($client)
	{
		$key = (int) $client;
		if (!isset(self::$http2[$key])) return;

		// Drain, rather than read once.
		//
		// stream_select() reports on the socket, and TLS decrypts into a
		// buffer inside OpenSSL that the socket knows nothing about. One read
		// per event therefore leaves whatever did not fit sitting in that
		// buffer, invisible to the loop, until fresh network traffic happens
		// to wake it -- which for a request that has already arrived in full
		// means waiting on a timer. Measured on a live page: 797ms per request
		// over HTTP/2 against 2ms for the same page over HTTP/1.1 on the same
		// server, which is the whole of the difference.
		$data = '';
		for ($reads = 0; $reads < 1024; ++$reads) {
			$chunk = @fread($client, 65536);
			if ($chunk === false or $chunk === '') break;
			$data .= $chunk;
			// Only an EMPTY read means the buffer is drained. A short read
			// used to stop this too, but over TLS fread() returns at most one
			// record -- 16KB -- whatever is waiting, so a burst of requests
			// spanning several records left all but the first unread, with
			// nothing on the socket to wake the loop for them. A browser that
			// sends a page's worth of requests at once (a hard reload, over
			// real latency) then waited on them until it gave up: stylesheets,
			// header images and scripts aborted, an admin page with no header
			// and no sub-items in Firefox. The socket is non-blocking, so the
			// last read returns '' rather than waiting; the bound keeps one
			// fast sender from holding the loop.
		}

		if ($data === '') {
			if (feof($client)) self::closeHttp2($key);
			return;
		}

		if (!self::$http2[$key]->feed($data)) {
			self::closeHttp2($key);
		}
	}

	/**
	 * Finish with an HTTP/2 connection.
	 *
	 * @method closeHttp2
	 * @static
	 * @param {integer} $key
	 */
	static function closeHttp2($key)
	{
		// Drop the writability watcher with the connection. An idle socket is
		// always writable, so a watcher left behind would fire on every pass of
		// the loop, forever, against a connection nobody is using.
		if (isset(self::$http2[$key])) {
			self::$http2[$key]->shutdown();
		}
		unset(self::$http2[$key]);
		self::closeClient($key);
	}

	/**
	 * Answer one request that arrived over HTTP/2.
	 *
	 * A file is read and returned here and now, which is the case this whole
	 * exercise is for: a page referencing dozens of stylesheets, scripts and
	 * images costs one connection instead of waves of six.
	 *
	 * A script is handed to the worker pool and answered later, on the stream
	 * it arrived on -- so null is returned to say "not yet", and the pool is
	 * given a callback rather than a socket to write to.
	 *
	 * @method http2Request
	 * @static
	 * @param {integer} $key
	 * @param {array} $request
	 * @return {array|null}
	 */
	static function http2Request($key, $request)
	{
		$started = microtime(true);
		try {
			$response = self::http2Route($key, $request);
		} catch (\Throwable $e) {
			$response = Q_WebServer_Http2_ErrorPage::response(
				get_class($e) . ': ' . $e->getMessage(),
				isset($request['stream']) ? $request['stream'] : 0
			);
		}
		// Record it here, which is the one place every directly-returned HTTP/2
		// response passes through.
		//
		// Until this was added the dashboard could not see most of HTTP/2. A
		// script is handed to the worker pool, and the pool calls
		// recordCompleted() when it answers -- so PHP was counted on both
		// protocols and looked fine. Everything http2Route() answers itself was
		// not counted at all: static files, and every refusal.
		//
		// Refusals are the ones that matter. Five requests for /.git/config
		// over HTTP/1.1 moved the 4xx counter from 2 to 7; five identical
		// requests over HTTP/2 moved it from 7 to 7. A browser negotiates
		// HTTP/2, so somebody probing this server with any modern client
		// produced a dashboard showing nothing had happened.
		//
		// null means the pool has taken it and will record it on its own
		// event, so recording here too would count it twice.
		if (is_array($response)) {
			self::recordCompleted(
				array(
					'method' => $request['method'] ?? 'GET',
					'uri' => $request['path'] ?? '/',
					'headers' => $request['headers'] ?? array(),
					'clientIp' => self::$clientInfo[$key]['ip'] ?? '',
					'cookies' => array(),
				),
				$response['status'] ?? 200,
				isset($response['body']) ? strlen((string) $response['body']) : 0,
				(microtime(true) - $started) * 1000,
				false
			);
			// HSTS for a domain that asks for it (HTTP/2 is TLS here).
			if (!isset($response['headers']) or !is_array($response['headers'])) $response['headers'] = array();
			Q_WebServer_Domains::addHsts($response['headers'], $request['headers']['host'] ?? '', true);
		}
		return $response;
	}

	/**
	 * Where an HTTP/2 request is actually resolved. Separate from
	 * http2Request() only so that everything it can throw is caught in one
	 * place rather than at each return.
	 *
	 * @method http2Route
	 * @static
	 * @param {integer} $key
	 * @param {array} $request
	 * @return {array|null}
	 */
	/**
	 * Does this request path try to leave the document root?
	 *
	 * Judged on the decoded path and before it is turned into a filename, so
	 * nothing is stat'd, opened or handed to realpath() on the strength of a
	 * string a peer chose. Anything that reaches the filesystem at all has
	 * already been decided about.
	 *
	 * Refuses any "..", not only a "../" that would resolve upwards. A file
	 * legitimately named "notes..txt" is refused with it, which is a fair
	 * trade: the cost is a filename nobody uses, and the alternative is
	 * reasoning about how many ways a segment can be spelled.
	 *
	 * A null byte is refused for its own reason: PHP's string functions carry
	 * it happily and the filesystem calls beneath them stop at it, so
	 * "/safe.txt\0/../../etc/passwd" can pass a check as one path and open as
	 * another.
	 *
	 * Extracted from http2Route so it can be tested against hostile input
	 * without a socket, a certificate or a document root.
	 *
	 * @method pathEscapesRoot
	 * @static
	 * @param {string} $decoded a percent-decoded request path
	 * @return {boolean} true when the request must be refused
	 */
	static function pathEscapesRoot($decoded)
	{
		if (!is_string($decoded) or $decoded === '') return true;
		if (strpos($decoded, "\0") !== false) return true;
		if (strpos($decoded, '..') !== false) return true;
		return false;
	}

	/**
	 * Does this filename, after every link is followed, still lie in the root?
	 *
	 * pathEscapesRoot() above judges the request string, which stops "..", a
	 * null byte and their encodings. It cannot stop a symbolic link, because
	 * nothing about the request says there is one: "/notes.txt" is an entirely
	 * ordinary path whether the file it names is a file or a door to somewhere
	 * else on the disk.
	 *
	 * Without this, a link inside the document root served whatever it pointed
	 * at -- and a link named *.php was executed, which turns the ability to
	 * create one file into the ability to run code from anywhere the server
	 * user can read. Upload directories, shared asset trees and dependency
	 * installers all create links, so this does not require anyone to be
	 * careless in an unusual way.
	 *
	 * Comparison is on the resolved paths and requires a separator after the
	 * root, so a sibling directory whose name merely starts the same way --
	 * /srv/www-old beside /srv/www -- is outside, not inside.
	 *
	 * Installations that deliberately serve through links can say so with
	 * Q.webserver.followSymlinks, which restores the old behaviour.
	 *
	 * @method insideRoot
	 * @static
	 * @param {string} $fsPath
	 * @return {boolean} false when the file lies outside and must be refused
	 */
	static function insideRoot($fsPath)
	{
		static $follow = null;
		if ($follow === null) {
			$follow = class_exists('Q_Config', false)
				? (bool) Q_Config::get('Q', 'webserver', 'followSymlinks', false)
				: false;
		}
		if ($follow) return true;

		$root = realpath(self::$rootDir);
		if ($root === false) return true;   // nothing to compare against
		$real = realpath($fsPath);
		if ($real === false) return true;   // does not exist; other code answers

		$root = rtrim($root, DIRECTORY_SEPARATOR);
		if ($real === $root) return true;
		return strncmp($real, $root . DIRECTORY_SEPARATOR,
			strlen($root) + 1) === 0;
	}

	/**
	 * Point the document root at the request's domain (its own root, an
	 * alias's or a subdomain's), leaving /Q/ and /.well-known/ alone.
	 * @param {array} $parsed
	 */
	private static function applyDomainRoot(array $parsed)
	{
		$path = (string) ($parsed['path'] ?? '/');
		if (strncmp($path, '/Q/', 3) === 0 or strncmp($path, '/.well-known/', 13) === 0) return;
		$root = Q_WebServer_Domains::resolveRoot($parsed['headers']['host'] ?? '');
		if ($root !== null) self::$rootDir = $root . DS;
	}

	/**
	 * An error answer on the HTTP/2 route: the domain's own error document
	 * for that status when it has one, the route's short plain text otherwise.
	 * @method http2Error
	 * @static
	 * @private
	 */
	private static function http2Error($code, $text)
	{
		if (($doc = Q_WebServer_Domains::errorDocument($code)) !== null) {
			return array('status' => $code,
				'headers' => array('content-type' => 'text/html; charset=utf-8'), 'body' => $doc);
		}
		// The same designed page HTTP/1.1 sends. This used to be the bare
		// word ("Forbidden", nine bytes of text/plain), and HTTP/2 is what a
		// browser speaks, so it was the page most visitors actually saw.
		return array('status' => $code,
			'headers' => array('content-type' => 'text/html; charset=utf-8'),
			'body' => self::renderErrorPage($code));
	}

	static function http2Route($key, $request)
	{
		// The domain's document root holds for the synchronous part of the
		// route -- which is where a worker's document root is taken -- and
		// the default root is restored whatever happens.
		$savedRoot = self::$rootDir;
		try {
			if (!isset(self::$http2[$key])) {
				return Q_WebServer_Http2_ErrorPage::response(
					'the connection was closed before the request could be answered', 0
				);
			}
			$conn = self::$http2[$key];
			$stream = $request['stream'];

			// A suspended or disabled domain, or a redirect: see handleRequest().
			// HTTP/2 is only ever spoken after a TLS handshake here.
			$request['_https'] = true;
			if (($gate = Q_WebServer_Domains::gate($request)) !== null) {
				return $gate;
			}
			self::applyDomainRoot($request);

			$path = $request['path'];
			$query = '';
			if (($q = strpos($path, '?')) !== false) {
				$query = substr($path, $q + 1);
				$path = substr($path, 0, $q);
			}

			$root = rtrim(self::$rootDir, '/\\');
			$decoded = rawurldecode($path);

			// A path that climbs out of the document root is refused before it is
			// touched, not after realpath() has been asked about it.
			if (self::pathEscapesRoot($decoded)) {
				return array('status' => 400, 'headers' => array(), 'body' => 'Bad Request');
			}

			$fsPath = $root . str_replace('/', DIRECTORY_SEPARATOR, $decoded);

			if (is_file($fsPath) and !self::insideRoot($fsPath)) {
				return self::http2Error(403, 'Forbidden');
			}

			// The same two refusals HTTP/1.1 makes, which this route did not.
			//
			// A browser speaks HTTP/2 by default, so this is the ordinary path and
			// the other one is the exception -- yet /settings/site.ini answered 403
			// over HTTP/1.1 and 200 with the file over HTTP/2, and so did
			// /.git/config. Anything the allow-list and the blocked list were
			// protecting was protected only from clients old enough to ask for it
			// in the older protocol.
			if (self::isBlocked($decoded)) {
				return self::http2Error(403, 'Forbidden');
			}

			// A file Q.web.static.paths does not list goes to the application
			// below, like a path that names no file at all.
			if (is_file($fsPath) and self::servedAsFile($decoded)) {
				$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
				if ($ext !== 'php' and !in_array($ext, self::$allowedExtensions)) {
					return self::http2Error(403, 'Forbidden');
				}
				if ($ext !== 'php') {
					$built = self::buildFileResponse(
						$fsPath, $ext, $request['method'], $request['headers']
					);
					if (is_array($built)) return $built;
				}
			}

			// And only now the server's own routes -- /Q/dashboard, /Q/health,
			// /Q/metrics and the rest. handleRequest() answers them on HTTP/1.1 and
			// this route did not answer them at all, so the dashboard returned the
			// application's 404 to every browser that ever asked for it while
			// answering 200 to curl --http1.1. A browser negotiates HTTP/2, which
			// makes that the ordinary case rather than the exception.
			//
			// route() returns response arrays, which is this route's contract
			// exactly; it was written for that and then left unwired. Only the two
			// prefixes it owns are handed to it, so an ordinary content path never
			// reaches it.
			//
			// The position matters as much as the call. route() can fall through to
			// resolveStatic(), so it belongs behind every refusal above rather than
			// in front of them -- ahead of them, a document root that happened to
			// contain a Q directory would have its files served around the
			// allow-list and the blocked list.
			// Who is asking, before the server's own routes: the panel and the
			// dashboard decide by the client's address, and without one route()
			// could not tell this machine from anyone else. See below for why the
			// address comes off the socket.
			$peer = @stream_socket_get_name($conn->socket, true);
			$directIp = self::$clientInfo[$key]['ip']
				?? ($peer ? trim(substr($peer, 0, strrpos($peer, ':')), '[]') : '0.0.0.0');
			$clientIp = Q_WebServer_Proxy::clientIp($directIp, $request['headers']);

			if (strpos($decoded, '/Q/') === 0 or strpos($decoded, '/.well-known/') === 0) {
				$builtin = self::route(array(
					'method' => $request['method'],
					'path' => $decoded,
					'query' => $query,
					'headers' => $request['headers'],
					'body' => $request['body'] ?? '',
					'clientIp' => $clientIp,
					'_remoteAddr' => $clientIp,
					'cookies' => isset($request['headers']['cookie'])
						? self::parseCookieHeader($request['headers']['cookie']) : array(),
					// The server's own routes only; the rest goes to a worker below.
					'_builtinOnly' => true,
				));
				if (is_array($builtin)) return $builtin;
			}

			// Anything else is the application's. Resolve it the way the HTTP/1.1
			// path does, then hand it to a worker.
			$scriptPath = self::resolveScript($decoded, $fsPath);
			if ($scriptPath === null or !self::$pool) {
				return self::http2Error(404, "Not Found\n");
			}

			// Who is asking, worked out the way the HTTP/1.1 path works it out:
			// the address of the connection, replaced by a forwarded one only
			// when the connection comes from a configured trusted proxy.
			//
			// This used to take X-Real-IP from the request, from anyone, and
			// fall back to 127.0.0.1. So any client speaking HTTP/2 could name
			// its own address -- to the rate limiter, the panel's address rules,
			// the access log and the application -- and every client that did
			// not was reported as the server itself.
			//
			// The address ($clientIp, above) comes off the socket: clientInfo is filled in when a
			// plain connection is accepted, and a TLS one never passes there.

			$parsed = array(
				'method' => $request['method'],
				'uri' => $request['path'],
				'path' => $path,
				'query' => $query,
				'headers' => $request['headers'],
				'rawHeaders' => array(),
				'body' => $request['body'],
				'httpVersion' => '2',
				// Carried so the worker and the log see what the HTTP/1.1 path
				// gives them.
				'clientIp' => $clientIp,
				'_remoteAddr' => $clientIp,
				'_remotePort' => $peer ? (int) substr(strrchr($peer, ':'), 1) : 0,
				'cookies' => isset($request['headers']['cookie'])
					? self::parseCookieHeader($request['headers']['cookie']) : array(),
			);

			// Ask the response cache before waking a worker.
			//
			// This is where HTTP/2 was losing, and by an enormous margin. The
			// HTTP/1.1 path consults this cache; this one did not, so every
			// request rendered the page from scratch while the same page over
			// HTTP/1.1 was answered from store. Measured on this installation:
			// 2ms against roughly 1000ms, and the worker's own log confirmed it
			// was doing the full render every single time.
			//
			// The cache is on by default in this server and honours the response's
			// own Cache-Control, so a page that says it may be held is held, and a
			// request carrying a session cookie bypasses it.
			$cached = Q_WebServer_Cache::get($parsed);
			if ($cached !== null) {
				// A returning visitor who already holds this gets told so, rather
				// than being sent it again. The body is the part that scales with
				// the page; without it a reload is a round trip and a couple of
				// hundred bytes, whatever the page weighs.
				$fresh = Q_WebServer_Cache::notModified($cached, $parsed['headers']);
				return $fresh !== null ? $fresh : $cached;
			}

			self::$pool->dispatch($conn->socket, $parsed, $scriptPath,
				function ($resp) use ($key, $stream, $parsed) {
					if (!isset(Q_WebServer::$http2[$key])) return;
					if (!is_array($resp)
						or (($resp['status'] ?? 200) >= 500 and ($resp['body'] ?? '') === '')
					) {
						$resp = Q_WebServer_Http2_ErrorPage::response(
							'the worker returned no usable response', $stream
						);
					}
					// Offer it to the cache, exactly as the HTTP/1.1 path does.
					// Without this the store is never filled from HTTP/2 and every
					// request pays the full render.
					$resp = Q_WebServer_Cache::put($parsed, $resp);
					if (!isset($resp['headers']) or !is_array($resp['headers'])) $resp['headers'] = array();
					Q_WebServer_Domains::addHsts($resp['headers'], $parsed['headers']['host'] ?? '', true);
					Q_WebServer::$http2[$key]->respond($stream, $resp);
				}
			);

			return null; // answered later, on this stream
		} finally {
			self::$rootDir = $savedRoot;
			Q_WebServer_Domains::$currentHost = null;
		}
	}

	/**
	 * Whether a script may run because it was asked for by name.
	 *
	 * Q.webserver.scripts lists those that may, relative to the document root
	 * ("/index.php"). Unset, every script inside the root may -- as before the
	 * key existed. Set, any other .php file is treated as though it were not
	 * there, and the request goes to the front controller: what an .htaccess
	 * that serves the assets and sends everything else to index.php does under
	 * Apache. Without it, an application whose entry points are a handful of
	 * scripts also exposes every library, installer and command-line tool it
	 * ships, each of which runs when its path is requested.
	 *
	 * @method scriptRunnable
	 * @static
	 * @param {string} $fsPath
	 * @return {boolean}
	 */
	static function scriptRunnable($fsPath)
	{
		$list = Q_Config::get('Q', 'webserver', 'scripts', null);
		if (!is_array($list)) return true;

		// The path may come from realpath(), and the root may be a link:
		// compared with the root as given and as resolved.
		$file = str_replace('\\', '/', (string) $fsPath);
		$relative = null;
		foreach (array(self::$rootDir, realpath(self::$rootDir)) as $root) {
			if (!is_string($root) or $root === '') continue;
			$root = rtrim(str_replace('\\', '/', $root), '/');
			if (strncmp($file, $root . '/', strlen($root) + 1) === 0) {
				$relative = substr($file, strlen($root));
				break;
			}
		}
		if ($relative === null) return false;
		foreach ($list as $script) {
			if ('/' . ltrim(str_replace('\\', '/', (string) $script), '/') === $relative) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a file may be sent as it is.
	 *
	 * Q.web.static.paths lists patterns on the request's path
	 * ("^/design/[^/]+/stylesheets/"). Unset, every file with a served
	 * extension may -- as before the key existed. Set, a file no pattern
	 * matches is treated as though it were not there and the request goes to
	 * the front controller: the "- [L]" rules of an .htaccess followed by
	 * ".* index.php". An application that keeps protected uploads in the
	 * document root and hands them out through a script that checks who is
	 * asking is otherwise bypassed by anyone who knows the file's path.
	 *
	 * Judged on the path as requested -- as an .htaccess rule is -- not on
	 * the file it resolves to: an asset directory that is a symbolic link
	 * resolves outside the root, and is still an asset directory. Whether
	 * such a file may be read at all is insideRoot()'s question. Dot segments
	 * are resolved first, so /design/x/stylesheets/../../../var/private.pdf
	 * is judged as /var/private.pdf and not as a stylesheet.
	 *
	 * @method servedAsFile
	 * @static
	 * @param {string} $path The decoded request path
	 * @return {boolean}
	 */
	static function servedAsFile($path)
	{
		$patterns = Q_Config::get('Q', 'web', 'static', 'paths', null);
		if (!is_array($patterns)) return true;

		$segments = array();
		foreach (explode('/', str_replace('\\', '/', (string) $path)) as $segment) {
			if ($segment === '' or $segment === '.') continue;
			if ($segment === '..') {
				if (!$segments) return false;
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		$relative = '/' . implode('/', $segments);
		foreach ($patterns as $pattern) {
			if (@preg_match(self::ensureRegex((string) $pattern), $relative)) return true;
		}
		return false;
	}

	/**
	 * The script a URL that names no runnable file goes to.
	 *
	 * Q.webserver.frontControllers maps patterns on the path to scripts,
	 * checked in order ({"^/api/": "index_rest.php"}); the first match whose
	 * script exists inside the root wins. Otherwise index.php, as always.
	 *
	 * These are decided here, in the server, because a pooled worker runs the
	 * script it is handed: an .htaccess rule that sends a URL to a script
	 * other than index.php is never consulted on that path.
	 *
	 * @method frontController
	 * @static
	 * @param {string} $path
	 * @return {string|null}
	 */
	static function frontController($path)
	{
		$routes = Q_Config::get('Q', 'webserver', 'frontControllers', array());
		if (is_array($routes)) {
			foreach ($routes as $pattern => $script) {
				if (!@preg_match(self::ensureRegex((string) $pattern), $path)) continue;
				$candidate = self::$rootDir . ltrim(str_replace(array('/', '\\'), DS, (string) $script), DS);
				if (is_file($candidate) and self::insideRoot($candidate)) return $candidate;
			}
		}
		$front = self::$rootDir . 'index.php';
		return is_file($front) ? $front : null;
	}

	/**
	 * The script that should answer a path, or null.
	 *
	 * Kept separate so the HTTP/2 path and the HTTP/1.1 path agree about what
	 * a URL means rather than each deciding for itself.
	 *
	 * @method resolveScript
	 * @static
	 * @param {string} $path
	 * @param {string} $fsPath
	 * @return {string|null}
	 */
	static function resolveScript($path, $fsPath)
	{
		// Whatever this resolves to is about to be executed, so the question of
		// whether it lies in the document root is asked here, once, for every
		// route into it. A symbolic link named *.php pointed anywhere the
		// server user can read was otherwise run: that turns being able to
		// create one file into being able to run code from outside the site
		// altogether, which is a long way past serving a file one should not.
		if (is_file($fsPath) and strtolower(pathinfo($fsPath, PATHINFO_EXTENSION)) === 'php'
			and self::scriptRunnable($fsPath)) {
			return self::insideRoot($fsPath) ? $fsPath : null;
		}

		if (is_dir($fsPath)) {
			foreach (array('index.php', 'index.html') as $index) {
				$candidate = rtrim($fsPath, '/\\') . DIRECTORY_SEPARATOR . $index;
				if (is_file($candidate) and ($index !== 'index.php' or self::scriptRunnable($candidate))) {
					return self::insideRoot($candidate) ? $candidate : null;
				}
			}
		}

		// The front controller, which is how a framework URL is served.
		$front = self::frontController($path);
		if ($front === null) return null;
		return self::insideRoot($front) ? $front : null;
	}

	/**
	 * Continue a non-blocking TLS handshake.
	 * stream_socket_enable_crypto() returns:
	 *   true  → handshake complete
	 *   false → handshake failed
	 *   0     → handshake in progress, try again
	 *
	 * @method continueTlsHandshake
	 * @static
	 */
	static function continueTlsHandshake($key)
	{
		if (!isset(self::$clients[$key])) return;
		$client = self::$clients[$key];

		$cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
		if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
			$cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
		}

		// Re-attempt while progress is possible, before going back to the loop.
		//
		// stream_socket_enable_crypto() returns 0 to mean "call me again", and
		// it says that whenever OpenSSL needs another turn -- including when
		// the bytes it needs are already sitting in its own buffer. Waiting
		// for the socket to become readable in that case waits for something
		// that has already happened, so the handshake stalls until an
		// unrelated timer wakes the loop.
		//
		// Measured with a real browser against this server over loopback,
		// where a handshake should cost almost nothing: 304ms of a 347ms
		// time-to-first-byte was the handshake. Several flights, each stalled.
		//
		// The bound matters. Retrying forever would spin a CPU on a client
		// that has genuinely stopped talking, so after a few immediate turns
		// this falls back to waiting for readability, which is correct when
		// the peer really does owe us bytes.
		$result = 0;
		for ($attempt = 0; $attempt < 4; $attempt++) {
			$result = @stream_socket_enable_crypto($client, true, $cryptoMethod);
			if ($result !== 0) break;

			// No early break on unread_bytes.
			//
			// That was the first attempt at this and it did nothing: for a
			// socket in the middle of a TLS handshake the bytes live inside
			// OpenSSL, not in the stream's own buffer, so unread_bytes is 0
			// even when the next flight has arrived in full. The loop broke
			// immediately every time and the handshake still waited for a
			// readability event that had already been and gone.
			//
			// Asking again costs a function call. A handshake needs only a
			// few turns, and when the peer genuinely owes us a flight the
			// bounded loop below falls through to waiting for readability,
			// which is correct.
		}

		if ($result === true) {
			// Handshake complete — treat like a normal client
			unset(self::$tlsPending[$key]);

			// What did the two sides agree to speak? A client that chose h2
			// will send the HTTP/2 preface and nothing the HTTP/1.1 reader
			// could make sense of, so it has to be routed now.
			$meta = @stream_get_meta_data($client);
			$alpn = isset($meta['crypto']['alpn_protocol'])
				? $meta['crypto']['alpn_protocol'] : '';

			if ($alpn === 'h2' and self::http2Enabled()) {
				self::startHttp2($key, $client);
				return;
			}

			self::$clientWatchers[$key] = Q_Evented::onReadable(
				$client,
				function ($c) { Q_WebServer::onClientData($c); }
			);
		} elseif ($result === 0) {
			// In progress — watch for readability to retry
			self::$clientWatchers[$key] = Q_Evented::onReadable(
				$client,
				function ($c) {
					$k = (int) $c;
					// Cancel this watcher and retry handshake
					if (isset(Q_WebServer::$clientWatchers[$k])) {
						Q_Evented::cancel(Q_WebServer::$clientWatchers[$k]);
						unset(Q_WebServer::$clientWatchers[$k]);
					}
					Q_WebServer::continueTlsHandshake($k);
				}
			);
		} else {
			// Failed
			self::closeClient($key);
		}
	}

	/**
	 * Put Q_WebServer_Certs' current cert and key in front of new connections.
	 *
	 * The listener keeps its socket; only its context changes. Every accepted
	 * connection inherits the listener's context and does its handshake here,
	 * in this process, so the next handshake presents the new certificate and
	 * there is no moment without HTTPS. (It used to only say so: the context
	 * had moved from per-connection to per-listener, and a renewed certificate
	 * was never used until a restart.) When HTTPS never came up for want of a
	 * certificate, it is started now.
	 * @method reloadTls
	 * @static
	 */
	static function reloadTls()
	{
		$cert = Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath;
		$key = Q_WebServer_Certs::$activeKey ?: Q_WebServer_Certs::$keyPath;
		if (!self::$httpsPort) return;
		if (!self::$tlsSocket or !is_resource(self::$tlsSocket)) {
			if (self::$tlsHost !== null and Q_WebServer_Certs::validateCerts()) {
				self::startTls(self::$tlsHost, self::$httpsPort);
			}
			return;
		}
		$ok = @stream_context_set_option(self::$tlsSocket, 'ssl', 'local_cert', $cert)
			&& @stream_context_set_option(self::$tlsSocket, 'ssl', 'local_pk', $key);
		if ($ok) {
			Q_WebServer_Certificate_Events::emit('swapped', array('cert' => $cert, 'key' => $key,
				'source' => Q_WebServer_Certs::$source));
		} else {
			Q_WebServer_Certificate_Events::emit('error', array('reason' => 'could not update the HTTPS listener with ' . $cert));
		}
	}

	/**
	 * Register a certificate to present for $hostname's TLS handshakes (SNI).
	 * Called by autohost after provisioning a certificate for a domain, and by
	 * configuration at startup. The certificate is stored as a private
	 * per-process copy by Q_WebServer_Certs (the same discipline as the default
	 * pair), and the live listener's SNI map is updated so the next handshake
	 * for that name presents it -- no restart, and the default certificate is
	 * left in front of every other name.
	 *
	 * @method registerDomainCert
	 * @static
	 * @param {string} $hostname
	 * @param {string} $certPath source certificate (fullchain) path
	 * @param {string} $keyPath source private key path
	 * @return {boolean}
	 */
	static function registerDomainCert($hostname, $certPath, $keyPath)
	{
		$ok = Q_WebServer_Certs::registerDomain($hostname, $certPath, $keyPath);
		if ($ok and self::$tlsSocket and is_resource(self::$tlsSocket)) {
			@stream_context_set_option(self::$tlsSocket, 'ssl',
				'SNI_server_certs', Q_WebServer_Certs::sniCerts());
		}
		return $ok;
	}

	/** @var string|null the address startTls() bound, for a late start */
	private static $tlsHost = null;

	/**
	 * Graceful shutdown: stop accepting new connections,
	 * wait for in-flight requests to complete (up to timeout),
	 * then close everything.
	 * @method stop
	 * @static
	 * @param {float} $drainTimeout Max seconds to wait for in-flight requests
	 */
	static function stop($drainTimeout = 5.0)
	{
		if (!self::$running) return;
		self::$running = false;

		// 1. Stop accepting new connections
		if (self::$acceptWatcher) {
			Q_Evented::cancel(self::$acceptWatcher);
			self::$acceptWatcher = null;
		}
		if (self::$tlsWatcher) {
			Q_Evented::cancel(self::$tlsWatcher);
			self::$tlsWatcher = null;
		}
		if (self::$socket) { if (is_resource(self::$socket)) @fclose(self::$socket); self::$socket = null; }
		if (self::$tlsSocket) { if (is_resource(self::$tlsSocket)) @fclose(self::$tlsSocket); self::$tlsSocket = null; }

		// Close UDS listener and clean up socket file
		if (self::$udsWatcher) {
			Q_Evented::cancel(self::$udsWatcher);
			self::$udsWatcher = null;
		}
		if (self::$udsSocket) { if (is_resource(self::$udsSocket)) @fclose(self::$udsSocket); self::$udsSocket = null; }
		if (self::$udsPath && file_exists(self::$udsPath)) {
			@unlink(self::$udsPath);
			self::$udsPath = null;
		}

		// 2. Wait for in-flight connections to drain (up to timeout)
		$deadline = microtime(true) + $drainTimeout;
		while (!empty(self::$clients) && microtime(true) < $deadline) {
			Q_Evented::tick(0.1); // process pending I/O briefly
		}

		// 3. Force-close remaining connections
		foreach (self::$timeoutWatchers as $id) Q_Evented::cancel($id);
		self::$timeoutWatchers = array();
		foreach (self::$clientWatchers as $id) Q_Evented::cancel($id);
		self::$clientWatchers = array();
		foreach (self::$clients as $c) { if (is_resource($c)) @fclose($c); }
		self::$clients = array();
		self::$buffers = array();
		self::$clientInfo = array();
		self::$keepAliveCount = array();

		// 4. Disconnect WebSockets
		Q_WebSocket::disconnectAll();

		// 5. Gracefully shut down worker pool (SIGTERM → wait → SIGKILL)
		if (self::$pool) { self::$pool->shutdown(); self::$pool = null; }
	}

	static function run()
	{
		if (!self::$running) return;
		if (function_exists('pcntl_signal')) {
			// A shutdown signal has to end the process, not merely ask the
			// loop to wind down. Asking was what these did, and it was not
			// enough: stop() closes the listeners and takes the pool down, and
			// the handler then returned into a loop that still held timers --
			// metrics flushing, cache maintenance -- so hasWatchers() stayed
			// true and the process went on sleeping and waking for ever. From
			// the outside that is a server that ignores SIGTERM: `velocity
			// stop` waited out its timeout and reported "still running; try
			// kill" every single time, with the listening socket already gone.
			//
			// Exiting here is safe because everything that needed ordering has
			// already happened inside stop(): connections drained, workers
			// signalled and reaped, sockets closed, the UDS path unlinked.
			Q_Evented::onSignal(SIGINT, function () {
				echo "\n  Graceful shutdown (SIGINT)...\n";
				self::stop();
				Q_Evented::stop();
				exit(0);
			});
			Q_Evented::onSignal(SIGTERM, function () {
				echo "\n  Graceful shutdown (SIGTERM)...\n";
				self::stop();
				Q_Evented::stop();
				exit(0);
			});
			Q_Evented::onSignal(SIGHUP, function () {
				echo "\n  Reloading (SIGHUP)...\n";
				self::stop();
				Q_Evented::stop();
				// Re-exec with same arguments
				$args = $_SERVER['argv'] ?? array();
				if (function_exists('pcntl_exec')) {
					pcntl_exec(PHP_BINARY, $args);
				}
			});
			// Reap zombie children from fork-per-request PHP execution
			Q_Evented::onSignal(SIGCHLD, function () {
				while (($pid = Q_WebServer_Fork::waitpid(-1, $st, 1)) > 0) {
					$info = Q_WebServer::$workerPids[$pid] ?? null;
					unset(Q_WebServer::$workerPids[$pid]);
					if (!$info) { Q_WebServer::noteReaped($pid, $st); continue; }

					$startTime = is_array($info) ? $info['time'] : $info;
					$ms = round((microtime(true) - $startTime) * 1000, 1);
					$method = is_array($info) ? ($info['method'] ?? 'GET') : 'GET';
					$uri = is_array($info) ? ($info['uri'] ?? '/') : '/';
					// IP and UA only -- never cookies; these feed the admin-gated
					// error metrics, never a response served to a visitor.
					$ip = is_array($info) ? ($info['clientIp'] ?? '') : '';
					$ua = is_array($info) ? ($info['userAgent'] ?? '') : '';

					// Check if child exited abnormally
					if (!pcntl_wifexited($st) || pcntl_wexitstatus($st) !== 0) {
						// Worker crashed or was killed — record as 502
						Q_WebServer_Dashboard::recordRequest($method, $uri, 502, $ms, 0, true);
							if (class_exists('Q_WebServer_Metrics', false)) {
								Q_WebServer_Metrics::recordRequest(502, $ms, $method, $uri, $ip, $ua, 0);
							}
					} else {
						// Normal exit — child already sent the response and recorded nothing
						// The child handles recording on its own via exit(0)
					}
				}
			});
		}
		// Engine.IO ping timer — keeps Socket.IO connections alive
		Q_Evented::repeat(25, function () {
			Q_WebSocket::pingSocketIO();
		});

		// Sweep idle HTTP/2 connections.
		//
		// A held socket costs a file descriptor whether or not anything
		// travels over it, and descriptors are the scarcest resource this
		// process has. A peer that connects, completes the TLS handshake and
		// then says nothing occupies one indefinitely -- slowloris in its
		// oldest form, and it needs no valid request at all.
		//
		// Swept here rather than per connection because only the loop can see
		// them together, and because a timer per connection is itself a
		// resource a peer could multiply.
		Q_Evented::repeat(10, function () {
			if (!self::$http2) return;
			$now = microtime(true);
			foreach (self::$http2 as $key => $conn) {
				if (!$conn or !$conn->isIdle($now)) continue;
				$conn->goaway(Q_WebServer_Http2_Frame::NO_ERROR);
				self::closeHttp2($key);
			}
		});

		// Request timeout — kill workers that exceed the configured limit
		$timeout = Q_Config::get('Q', 'webserver', 'requestTimeout', 30);
		if ($timeout > 0) {
			Q_Evented::repeat(1, function () use ($timeout) {
				$now = microtime(true);
				foreach (Q_WebServer::$workerPids as $pid => $info) {
					$startTime = is_array($info) ? $info['time'] : $info;
					if ($now - $startTime > $timeout) {
						$ms = round(($now - $startTime) * 1000, 1);
						$method = is_array($info) ? ($info['method'] ?? 'GET') : 'GET';
						$uri = is_array($info) ? ($info['uri'] ?? '/') : '/';
						// IP and UA only -- never cookies; these feed the
						// admin-gated error metrics, never a visitor response.
						$ip = is_array($info) ? ($info['clientIp'] ?? '') : '';
						$ua = is_array($info) ? ($info['userAgent'] ?? '') : '';
						@posix_kill($pid, SIGKILL);
						unset(Q_WebServer::$workerPids[$pid]);
						Q_WebServer_Dashboard::recordRequest($method, $uri, 504, $ms, 0, true);
						if (class_exists('Q_WebServer_Metrics', false)) {
							Q_WebServer_Metrics::recordRequest(504, $ms, $method, $uri, $ip, $ua, 0);
						}
					}
				}
				// Pooled workers too: the loop above sees only fork-per-request
				// children, so the limit used to mean nothing to a pool.
				if (self::$pool and method_exists(self::$pool, 'killOverdue')) {
					self::$pool->killOverdue($timeout);
				}
			});
		}

		// Scheduler — run tasks on intervals or at specific times
		$schedule = Q_Config::get('Q', 'scheduler', array());
		// Built-in: cert renewal check every 12 hours
		if (!isset($schedule['_certRenewal'])) {
			$schedule['_certRenewal'] = array(
				'handler' => '_certRenewal',
				'every' => 43200, // 12 hours
			);
			Q_Config::set('Q', 'scheduler', '_certRenewal', $schedule['_certRenewal']);
		}
		// Built-in: sweep expired reverse proxy cache entries.
		// get() only unlinks an expired entry when that URL is asked for
		// again, so without this a directory grows for as long as the
		// server runs. Off unless the cache is on.
		if (!isset($schedule['_cacheSweep'])
		and Q_Config::get('Q', 'web', 'cache', 'enabled', false)) {
			$every = (int) Q_Config::get('Q', 'web', 'cache', 'sweep', 'every', 300);
			if ($every > 0) {
				$schedule['_cacheSweep'] = array(
					'handler' => '_cacheSweep',
					'every' => $every,
				);
				Q_Config::set('Q', 'scheduler', '_cacheSweep', $schedule['_cacheSweep']);
			}
		}
		if (!empty($schedule)) {
			Q_Scheduler::init($schedule);
			Q_Evented::repeat(1, function () {
				Q_Scheduler::tick();
			});
		}

		// Hot reload — watch for file changes in classes/, handlers/, config/
		if (Q_Config::get('Q', 'webserver', 'hotReload', false)) {
			Q_HotReload::init();
			Q_Evented::repeat(2, function () {
				Q_HotReload::check();
				if (class_exists('Q_WebServer_Compat', false)) {
					Q_WebServer_Compat::invalidateStale();
				}
			});
		}

		// Config file watcher — re-read config on change (no restart needed)
		$configFiles = array_filter([
			'local/app.json',
			'local/panel.json',
			'config/app.json',
		], 'is_file');
		if ($configFiles) {
			$configMtimes = [];
			foreach ($configFiles as $cf) {
				$configMtimes[$cf] = filemtime($cf);
			}
			Q_Evented::repeat(3, function () use (&$configMtimes) {
				foreach ($configMtimes as $cf => $mtime) {
					clearstatcache(true, $cf);
					$newMtime = @filemtime($cf);
					if ($newMtime && $newMtime !== $mtime) {
						$configMtimes[$cf] = $newMtime;
						// Re-read the config
						$data = @json_decode(file_get_contents($cf), true);
						if ($data && is_array($data)) {
							Q_Config::merge($data);
							fwrite(STDERR, date('H:i:s') . " config reloaded: $cf\n");
						}
					}
				}
			});
		}

		// Metrics — buffered logging and time-series stats
		$metricsFile = __DIR__ . '/WebServer/Metrics.php';
		if (is_file($metricsFile)) {
			require_once $metricsFile;
			Q_WebServer_Metrics::init();
			$flushInterval = Q_Config::get('Q', 'webserver', 'metrics', 'flushInterval', 10);
			Q_Evented::repeat($flushInterval, function () {
				Q_WebServer_Metrics::tick();
			});
			// Daily purge of old metrics
			Q_Evented::repeat(86400, function () {
				Q_WebServer_Metrics::purgeOld();
			});
		}

		Q_Evented::run();
	}

	// ── Connection handling ──────────────────────────────

	static function onAccept($socket)
	{
		// Max connections check (cached)
		static $maxConn = null;
		if ($maxConn === null) $maxConn = Q_Config::get('Q', 'webserver', 'maxConnections', 1024);
		if (count(self::$clients) >= $maxConn) {
			$reject = @stream_socket_accept($socket, 0);
			if ($reject) {
				@fwrite($reject, "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
				if (is_resource($reject)) @fclose($reject);
			}
			return;
		}

		$client = @stream_socket_accept($socket, 0);
		if (!$client) return;
		stream_set_blocking($client, false);

		// Detect if this connection came from the UDS listener
		$isUds = (self::$udsSocket && $socket === self::$udsSocket);

		// Disable Nagle's algorithm — eliminates 40ms delayed ACK on keep-alive
		// Skip on UDS: TCP socket options are meaningless on Unix domain sockets
		if (!$isUds && function_exists('socket_import_stream')) {
			$rawSocket = socket_import_stream($client);
			if ($rawSocket) {
				@socket_set_option($rawSocket, SOL_TCP, TCP_NODELAY, 1);
			}
		}
		$key = (int) $client;
		self::$clients[$key] = $client;
		self::$buffers[$key] = '';
		self::$keepAliveCount[$key] = 0;

		// Store remote IP for logging + proxy resolution
		$peer = stream_socket_get_name($client, true);
		if ($isUds || !$peer) {
			// UDS: no peer IP — will be resolved from X-Forwarded-For/X-Real-IP
			// in parseRequest(), defaulting to 127.0.0.1
			$ip = '127.0.0.1';
		} else {
			$ip = self::peerIp($client);
		}
		self::$clientInfo[$key] = array(
			'ip' => $ip,
			'connectTime' => microtime(true)
		);

		// Rate limit check
		if (!self::checkRateLimit($ip)) {
			self::writeAll($client, "HTTP/1.1 429 Too Many Requests\r\n"
				. "Retry-After: 60\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
			if (is_resource($client)) @fclose($client);
			unset(self::$clients[$key], self::$buffers[$key], self::$keepAliveCount[$key],
				self::$clientInfo[$key]);
			return;
		}

		self::$clientWatchers[$key] = Q_Evented::onReadable(
			$client, function ($c) { Q_WebServer::onClientData($c); }
		);

		// Read timeout — close if no complete request within N seconds
		static $readTimeout = null;
		if ($readTimeout === null) $readTimeout = (float) Q_Config::get('Q', 'webserver', 'timeout', 'read', 30);
		self::$timeoutWatchers[$key] = Q_Evented::delay($readTimeout, function () use ($key) {
			Q_WebServer::closeClient($key);
		});
	}


	/**
	 * Whether a buffer holds a COMPLETE request (headers plus, for methods
	 * that carry one, the whole body). Used to decide if another socket read
	 * is needed. Checking only for "\r\n\r\n" treats a partially-received
	 * POST as finished, which truncates bodies larger than one read.
	 *
	 * @method requestComplete
	 * @static
	 * @param {string} $buf
	 * @return {boolean}
	 */
	/**
	 * Where a request's headers end, allowing for bare LF line endings.
	 *
	 * Only "\r\n\r\n" was recognised. A client sending bare LF -- which some
	 * do, and every attacker will if it helps -- therefore never completed a
	 * request at all: the connection sat in the read loop holding a worker slot
	 * until it timed out. Answering such a request with 400 costs nothing;
	 * never answering it is a way to tie the server up with one short write.
	 *
	 * @method headerEnd
	 * @static
	 * @param {string} $buf
	 * @param {integer} $sepLen set to the length of the terminator found
	 * @return {integer|false}
	 */
	static function headerEnd($buf, &$sepLen)
	{
		$crlf = strpos($buf, "\r\n\r\n");
		$lf = strpos($buf, "\n\n");
		if ($crlf === false and $lf === false) { $sepLen = 0; return false; }
		if ($crlf === false or ($lf !== false and $lf < $crlf)) {
			$sepLen = 2;
			return $lf;
		}
		$sepLen = 4;
		return $crlf;
	}

	/**
	 * Why this request's framing cannot be trusted, or null if it can.
	 *
	 * A message that declares its length twice, and differently, has no single
	 * answer to where it ends. RFC 9112 section 6.1 requires such a message to
	 * be rejected rather than resolved, and the reason is request smuggling: a
	 * front-end and a back-end that break the tie differently can be made to
	 * see different request boundaries in one stream, so one visitor's request
	 * is read as the tail of another's. Choosing a winner is what makes that
	 * possible; refusing is what prevents it.
	 *
	 * Bare LF is refused for the same reason -- a proxy that accepts only CRLF
	 * and a server that accepts both do not agree about where a header stops.
	 *
	 * @method framingFault
	 * @static
	 * @param {string} $head the request head, without the blank line
	 * @return {string|null}
	 */
	static function framingFault($head)
	{
		$lines = preg_split("/\r\n|\n/", $head);
		array_shift($lines);            // the request line is not a header

		$lengths = array();
		$chunked = false;
		foreach ($lines as $line) {
			if ($line === '') continue;
			$colon = strpos($line, ':');
			if ($colon === false) continue;
			$name = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, $colon + 1));
			if ($name === 'content-length') {
				// A list in one header is the same conflict written shorter.
				foreach (explode(',', $value) as $one) {
					$lengths[trim($one)] = true;
				}
			} else if ($name === 'transfer-encoding') {
				$chunked = true;
			}
		}

		if ($chunked and $lengths) {
			return 'both Content-Length and Transfer-Encoding';
		}
		if (count($lengths) > 1) {
			return 'conflicting Content-Length values';
		}
		foreach (array_keys($lengths) as $one) {
			// A numeric key comes back as an int, and ctype_digit() reads an
			// int from -128 to 255 as an ASCII code: Content-Length: 100 would
			// be chr(100), "d", and refused. Compare the text that was sent.
			$one = (string) $one;
			if ($one === '' or !ctype_digit($one)) {
				return 'Content-Length is not a number';
			}
		}
		// Any LF not preceded by CR, anywhere in the head -- not only a head
		// written wholly in bare LF, which was all this used to catch. A
		// CRLF-framed request with a bare LF inside a header value passed,
		// the LF stayed in the value, and the access log wrote it as a real
		// line break: anyone could forge log lines, and a proxy in front that
		// treats bare LF as a line end would read different headers than this
		// server does. A bare CR is the same fault the other way round.
		if (preg_match('/(?<!\r)\n|\r(?!\n)/', $head)) {
			return 'bare LF line endings';
		}
		// Obsolete line folding: a header line starting with a space or tab
		// continues the previous one. RFC 9112 lets a server refuse it, and
		// accepting it is another way for two parsers to disagree about
		// where one header ends.
		if (preg_match('/\r\n[ \t]/', $head)) {
			return 'obsolete line folding';
		}
		return null;
	}

	static function requestComplete($buf)
	{
		$sepLen = 0;
		$headerEnd = self::headerEnd($buf, $sepLen);
		if ($headerEnd === false) return false;
		if ($buf[0] !== 'P') return true;   // only POST/PUT/PATCH carry a body
		if (preg_match('/transfer-encoding:\s*chunked/i', $buf)) {
			$bodyPart = substr($buf, $headerEnd + $sepLen);
			return strpos($bodyPart, "\r\n0\r\n") !== false
				|| strpos($bodyPart, "\n0\n") !== false;
		}
		$cl = 0;
		if (preg_match('/content-length:\s*(\d+)/i', $buf, $m)) $cl = (int) $m[1];
		if ($cl <= 0) return true;
		return (strlen($buf) - $headerEnd - $sepLen) >= $cl;
	}

	static function onClientData($client)
	{
		$key = (int) $client;
		if (!isset(self::$clients[$key])) return;

		// If this connection is waiting for a worker response, just buffer
		// any incoming data (HTTP pipelining) — don't try to parse yet.
		if ((self::$clientState[$key] ?? 'reading') === 'waiting') {
			$chunk = @fread($client, 65536);
			if ($chunk === false || $chunk === '') {
				// Client disconnected while we were processing their request.
				// Mark as dead — the Pool will detect this when it tries to write.
				self::$clientState[$key] = 'dead';
				return;
			}
			self::$buffers[$key] .= $chunk;
			return;
		}

		self::$clientState[$key] = 'reading';

		// Check if we already have a complete request from pipelining.
		//
		// Headers alone are NOT enough: a large POST arrives over several TCP
		// segments, so after the first read the buffer holds "\r\n\r\n" plus a
		// partial body. Treating that as pipelined skipped the next fread(),
		// and the loop then returned waiting for bytes it had stopped reading
		// -- so any body larger than one 64KB read was silently truncated.
		// Only skip the read when the FULL body is already buffered.
		$buf = self::$buffers[$key] ?? '';
		$havePipelined = ($buf !== '' && self::requestComplete($buf));

		if (!$havePipelined) {
			$chunk = @fread($client, 65536);
			if ($chunk === false || $chunk === '') {
				self::closeClient($key);
				return;
			}
			self::$buffers[$key] .= $chunk;
			// Drain what TLS has already decrypted: fread() returns at most
			// one record (16KB), and records after it sit in OpenSSL's buffer
			// where stream_select() cannot see them -- a body spanning several
			// records stopped arriving after the first. Non-blocking, so the
			// read that finds nothing returns '' at once.
			for ($reads = 0; $reads < 1024; ++$reads) {
				$more = @fread($client, 65536);
				if ($more === false or $more === '') break;
				self::$buffers[$key] .= $more;
			}
			$buf = self::$buffers[$key];
		}

		// Wait for complete headers
		$sepLen = 0;
		$headerEnd = self::headerEnd($buf, $sepLen);
		if ($headerEnd === false) {
			if (strlen($buf) > 65536) self::closeClient($key);
			return;
		}

		// A message whose length is declared twice, and differently, has no
		// single answer to where it ends. Refusing is what stops the two ends
		// of a chain disagreeing about it.
		$fault = self::framingFault(substr($buf, 0, $headerEnd));
		if ($fault !== null) {
			self::sendResponse($client, 400, 'Bad Request: ' . $fault);
			self::closeClient($key);
			return;
		}

		// Wait for complete body on POST/PUT/PATCH
		$firstChar = $buf[0];
		if ($firstChar === 'P') { // POST, PUT, PATCH all start with P
			$cl = 0;
			$isChunked = (bool) preg_match('/transfer-encoding:\s*chunked/i', $buf);
			if (preg_match('/content-length:\s*(\d+)/i', $buf, $m)) {
				$cl = (int) $m[1];
			}
			// Respect PHP's post_max_size (default 8M)
			if ($cl > self::$maxPostSize) {
				self::sendResponse($client, 413, 'Payload Too Large');
				self::closeClient($key);
				return;
			}
			if ($isChunked) {
				// Chunked: wait for terminating 0\r\n\r\n
				$bodyPart = substr($buf, $headerEnd + $sepLen);
				if (strpos($bodyPart, "\r\n0\r\n") === false && strpos($bodyPart, "\n0\n") === false) return;
			} elseif ($cl > 0) {
				if (strlen($buf) - $headerEnd - $sepLen < $cl) return;
			}
		}

		// Cancel read timeout (request received)
		if (isset(self::$timeoutWatchers[$key])) {
			Q_Evented::cancel(self::$timeoutWatchers[$key]);
			unset(self::$timeoutWatchers[$key]);
		}

		// Calculate consumed bytes for pipelining support
		$headerEnd = strpos($buf, "\r\n\r\n");
		$bodyLen = 0;
		$firstChar = $buf[0];
		if ($firstChar === 'P') { // POST/PUT/PATCH
			if (preg_match('/content-length:\s*(\d+)/i', $buf, $clm)) {
				$bodyLen = (int) $clm[1];
			}
		}
		$consumed = $headerEnd + 4 + $bodyLen;

		$start = microtime(true);
		$parsed = self::parseRequest($buf);

		// Parse cookies into $parsed for auth checks in parent process
		$parsed['cookies'] = self::parseCookieHeader(
			$parsed['headers']['cookie'] ?? ''
		);

		// Reject malformed request lines
		if (!empty($parsed['_malformed'])) {
			self::sendResponse($client, 400, 'Bad Request');
			self::closeClient($key);
			return;
		}

		// Issue #1: reject wrong-case methods (e.g. "get" instead of "GET")
		if (!empty($parsed['_badMethod'])) {
			self::sendResponse($client, 501, 'Not Implemented');
			self::closeClient($key);
			return;
		}

		// Issue #2: reject invalid header names
		if (!empty($parsed['_badHeaders'])) {
			self::sendResponse($client, 400, 'Bad Request: Invalid header name');
			self::closeClient($key);
			return;
		}

		// Issue #2: reject duplicate Host headers (common attack vector)
		if (!empty($parsed['_duplicateHost'])) {
			self::sendResponse($client, 400, 'Bad Request: Multiple Host headers');
			self::closeClient($key);
			return;
		}

		// Reject oversized headers (>64KB total)
		$headerEnd = strpos($buf, "\r\n\r\n");
		if ($headerEnd > 65536) {
			self::sendResponse($client, 431, 'Request Header Fields Too Large',
				'text/plain; charset=utf-8', array('Connection' => 'close'));
			self::closeClient($key);
			return;
		}

		// Resolve proxy headers for real client IP. A TLS connection is
		// accepted by a path that never fills clientInfo, so every HTTPS
		// visitor was 0.0.0.0 -- in the access log, in REMOTE_ADDR and to
		// the admin surface's local/remote check. Asked of the socket then.
		$directIp = self::$clientInfo[$key]['ip'] ?? self::peerIp($client);
		$parsed['clientIp'] = Q_WebServer_Proxy::clientIp($directIp, $parsed['headers']);
		$parsed['_remoteAddr'] = $parsed['clientIp'];
		$peer = stream_socket_get_name($client, true);
		$parsed['_remotePort'] = $peer ? (int) substr(strrchr($peer, ':'), 1) : 0;

		// Determine keep-alive before handling request
		static $maxKeepAlive = null;
		if ($maxKeepAlive === null) $maxKeepAlive = (int) Q_Config::get('Q', 'webserver', 'keepAlive', 'max', 100);
		$connHeader = strtolower($parsed['headers']['connection'] ?? 'keep-alive');
		self::$keepAliveCount[$key] = (self::$keepAliveCount[$key] ?? 0) + 1;
		$parsed['_keepAlive'] = ($connHeader !== 'close')
			&& self::$keepAliveCount[$key] < $maxKeepAlive;
		// Propagate into headers array so processResponse can access it
		$parsed['headers']['_keepAlive'] = $parsed['_keepAlive'];
		// And to sendResponse(), which the cache hit, the static and error
		// pages and every other synchronous answer go through without the
		// parsed request in hand. Reset in the finally below, so a response
		// written later from a callback keeps the old default.
		self::$requestKeepAlive = $parsed['_keepAlive'];

		try {
			$savedRoot = self::$rootDir;
			$keepOpen = self::handleRequest($client, $parsed);
		} catch (\Throwable $e) {
			// Never let a request crash the event loop. The response says
			// what went wrong (where, only with --debug); the log says where.
			if (class_exists('Q_WebServer_ErrorReport')) Q_WebServer_ErrorReport::log($e, 'server');
			$msg = htmlspecialchars(class_exists('Q_WebServer_ErrorReport')
				? Q_WebServer_ErrorReport::body($e) : $e->getMessage());
			self::sendResponse($client, 500, "Internal Server Error: $msg");
			self::closeClient($key);
			$ms = round((microtime(true) - $start) * 1000, 1);
			Q_WebServer_Dashboard::recordRequest(
				$parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', 500, $ms
			);
			if (self::$onRequest) {
				(self::$onRequest)($parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', 500, $ms);
			}
			return;
		} finally {
			self::$requestKeepAlive = true;
			// Restore rootDir after vhost override
			if (self::$rootDir !== $savedRoot) {
				self::$rootDir = $savedRoot;
			}
			if (class_exists('Q_WebServer_Domains', false)) Q_WebServer_Domains::$currentHost = null;
			self::$compressFor = null;
		}
		$ms = round((microtime(true) - $start) * 1000, 1);

		if ($keepOpen) {
			// WebSocket upgraded — Q_WebSocket owns this socket now
			if (isset(self::$clientWatchers[$key])) {
				Q_Evented::cancel(self::$clientWatchers[$key]);
			}
			unset(self::$clientWatchers[$key], self::$clients[$key],
				self::$buffers[$key], self::$clientInfo[$key],
				self::$keepAliveCount[$key]);
			return;
		}

		// Stats + logging
		// Skip if request was delegated to a forked child (-1)
		// The child's exit status is recorded in the SIGCHLD handler
		if (self::$lastStatus !== -1) {
			// No memory figure here. What was measured was the parent's own
			// heap moving while it served a static file, a cache hit or the
			// answer from a PHP subprocess -- not memory any script used.
			// Pooled PHP reports the worker's peak through recordCompleted().
			$memUsed = 0;
			$bytes = self::$lastBytes; // the reset below precedes the log line
			Q_WebServer_Dashboard::recordRequest(
				$parsed['method'], $parsed['uri'], self::$lastStatus, $ms, $bytes,
				false, '', $memUsed, $parsed['cookies'] ?? array()
			);
			// Metrics: buffered logging, clickstream, time-series
			if (class_exists('Q_WebServer_Metrics', false)) {
				Q_WebServer_Metrics::recordRequest(
					self::$lastStatus, $ms,
					$parsed['method'], $parsed['uri'],
					$parsed['clientIp'] ?? ($parsed['_remoteAddr'] ?? ''),
					$parsed['headers']['user-agent'] ?? '',
					$bytes,
					$parsed['cookies'] ?? []
				);
			}
			self::$lastBytes = 0;
			if (self::$onRequest) {
				(self::$onRequest)($parsed['method'], $parsed['uri'], self::$lastStatus, $ms);
			}

			// Log to file. $lastBody is only ever assigned by sendResponse(),
			// so anything else logged the previous response's size.
			Q_WebServer_Log::access(
				$parsed['clientIp'], $parsed['method'], $parsed['uri'],
				self::$lastStatus, $bytes,
				$parsed['headers']['referer'] ?? '',
				$parsed['headers']['user-agent'] ?? '',
				$ms,
				array(
					'path' => $parsed['path'] ?? null,
					'query' => $parsed['query'] ?? '',
					// The line used to say HTTP/1.1 whatever the request was.
					'protocol' => 'HTTP/' . ($parsed['httpVersion'] ?? '1.1'),
					'headers' => $parsed['headers'] ?? array(),
				)
			);
		}
		self::$lastStatus = 200; // reset for next request

		// ── Keep-alive decision ──────────────────────────
		static $keepAliveTimeout = null;
		if ($keepAliveTimeout === null) $keepAliveTimeout = (float) Q_Config::get('Q', 'webserver', 'keepAlive', 'timeout', 15);
		$shouldKeepAlive = !empty($parsed['_keepAlive']) && self::$lastStatus < 500;

		if ($shouldKeepAlive) {
			// Keep leftover data for pipelined requests
			$leftover = strlen($buf) > $consumed ? substr($buf, $consumed) : '';
			self::$buffers[$key] = $leftover;

			// Set idle timeout — close if no new request arrives
			self::$timeoutWatchers[$key] = Q_Evented::delay(
				$keepAliveTimeout,
				function () use ($key) {
					Q_WebServer::closeClient($key);
				}
			);

			// If there's already a complete request in the buffer, process it now
			if ($leftover !== '' && strpos($leftover, "\r\n\r\n") !== false) {
				Q_Evented::defer(function () use ($client) {
					Q_WebServer::onClientData($client);
				});
			}
		} else {
			self::closeClient($key);
		}
	}

	// ── Request routing ──────────────────────────────────

	/**
	 * Route a parsed request and return a response array.
	 *
	 * This is the clean interface that external HTTP drivers
	 * (like amphp/http-server) call. Handles all routing:
	 * blocked paths, static files, PHP dispatch, directory
	 * listings, X-Accel-Redirect, compression.
	 *
	 * The built-in server uses handleRequest() which writes
	 * directly to sockets. amphp calls route() and converts
	 * the response to its own format.
	 *
	 * @method route
	 * @static
	 * @param {array} $parsed [method, uri, path, query, headers, body, clientIp]
	 * @return {array} [status, headers, body]
	 */
	static function route($parsed)
	{
		$method = $parsed['method'];
		$path = $parsed['path'];

		// Reverse cache check (before any dispatch)
		$cached = Q_WebServer_Cache::get($parsed);
		if ($cached) {
			$fresh = Q_WebServer_Cache::notModified($cached, $parsed['headers']);
			return $fresh !== null ? $fresh : $cached;
		}

		// The server's own icons, manifest and link-preview image, and the
		// origin its pages put in absolute preview URLs. This path serves
		// HTTP/2, which is TLS in practice.
		if (strncmp($path, '/Q/', 3) === 0) {
			Q_WebServer_Brand::noteRequest($parsed,
				!empty($parsed['_https']) || !empty($parsed['https']) || ($parsed['httpVersion'] ?? '') === '2');
			$brandResponse = Q_WebServer_Brand::route($path);
			if ($brandResponse !== null) return $brandResponse;
		}

		if ($path === '/Q/event' && $method === 'POST') {
			return self::handleRemoteEvent($parsed);
		}

		// Server discovery — .well-known/qbix
		if (strpos($path, '/.well-known/') === 0) {
			$wellKnown = substr($path, 13);
			if ($wellKnown === 'qbix' || $wellKnown === 'qbix.json') {
				return self::wellKnownQbix($parsed);
			}
			if ($wellKnown === 'openapi.json' || $wellKnown === 'openapi') {
				return self::wellKnownOpenAPI($parsed);
			}
			if ($wellKnown === 'mcp.json' || $wellKnown === 'mcp') {
				return self::wellKnownMCP($parsed);
			}
			if (strpos($wellKnown, 'openclaiming/') === 0) {
				return self::wellKnownOpenClaiming($parsed, $wellKnown);
			}
		}

		if ($path === '/Q/health') {
			if (Q_Config::get('Q', 'dashboard', null) === false) {
				return array('status' => 404, 'body' => 'Not found');
			}
			// Liveness for anyone (load balancers, cluster peers); the stats,
			// which name request paths, for an admin only.
			if (!self::adminAllowed($parsed)) return self::healthMinimal();
			$stats = Q_WebServer_Dashboard::getStats();
			$result = array('status' => 'ok') + $stats;
			if (class_exists('Q_WebServer_Cluster', false) && Q_WebServer_Cluster::isActive()) {
				$result['cluster'] = Q_WebServer_Cluster::status();
			}
			return array('status'=>200, 'body'=>json_encode($result),
				'headers'=>array('Content-Type'=>'application/json'));
		}
		if (($path === '/Q/metrics' || $path === '/Q/attestation') and !self::adminAllowed($parsed)) {
			return self::adminRefusal($parsed);
		}
		if ($path === '/Q/metrics') {
			return self::metricsResponse($parsed);
		}
		if ($path === '/Q/attestation') {
			$attFile = __DIR__ . '/WebServer/Trust.php';
			if (is_file($attFile)) {
				require_once $attFile;
				return array('status'=>200,
					'body' => json_encode(Q_WebServer_Trust::attestation()),
					'headers'=>array('Content-Type'=>'application/json'));
			}
			return array('status'=>404, 'body'=>'Attestation not available');
		}
		// ── Mesh sync endpoints (opt-in; OFF by default) ──
		// Bootstrapped by an unauthenticated handshake and then relay requests
		// between servers, so they stay 404 unless Q.webserver.mesh is set.
		if (strpos($path, '/Q/sync/') === 0) {
			if (!class_exists('Q_WebServer_Mesh', false)) {
				require_once __DIR__ . '/WebServer/Mesh.php';
			}
			if (!Q_WebServer_Mesh::enabled()) {
				return array('status' => 404, 'body' => 'Not found',
					'headers' => array('Content-Type' => 'text/plain'));
			}
			$syncAction = substr($path, 8); // strip '/Q/sync/'
			$syncData = array();
			if (!empty($parsed['body'])) {
				$syncData = json_decode($parsed['body'], true) ?: array();
			}
			// $parsed['query'] is the raw query string; array_merge() on a
			// string throws a TypeError, so normalise it to an array first.
			$syncQuery = $parsed['query'] ?? array();
			if (!is_array($syncQuery)) {
				$qp = array();
				parse_str((string) $syncQuery, $qp);
				$syncQuery = $qp;
			}
			$syncData = array_merge($syncData, $syncQuery);
			Q_WebServer_Mesh::init();
			$result = Q_WebServer_Mesh::handleSyncApi($syncAction, $syncData);
			return array('status' => 200,
				'body' => json_encode($result),
				'headers' => array('Content-Type' => 'application/json'));
		}
		if ($path === '/Q/cluster/join' && $method === 'POST') {
			if (class_exists('Q_WebServer_Cluster', false)) {
				$body = $parsed['body'] ?? '';
				$data = json_decode($body, true) ?: array();
				if (!Q_WebServer_Cluster::joinAllowed($parsed, $data)) {
					return array('status' => 403, 'body' => 'Forbidden',
						'headers' => array('Content-Type' => 'text/plain'));
				}
				Q_WebServer_Cluster::handleJoin($data);
				return array('status' => 200, 'body' => '{"ok":true}',
					'headers' => array('Content-Type' => 'application/json'));
			}
			return array('status' => 404, 'body' => 'Clustering not active');
		}
		if ($path === '/Q/cluster/status') {
			if (class_exists('Q_WebServer_Cluster', false) && Q_WebServer_Cluster::isActive()) {
				return array('status' => 200,
					'body' => json_encode(Q_WebServer_Cluster::status()),
					'headers' => array('Content-Type' => 'application/json'));
			}
			return array('status' => 200,
				'body' => '{"active":false,"mode":"standalone"}',
				'headers' => array('Content-Type' => 'application/json'));
		}
		if ($path === '/Q/trust') {
			if (class_exists('Q_WebServer_Trust', false) && Q_WebServer_Trust::isEnabled()) {
				return array('status' => 200,
					'body' => json_encode(Q_WebServer_Trust::status()),
					'headers' => array('Content-Type' => 'application/json'));
			}
			return array('status' => 200,
				'body' => '{"enabled":false}',
				'headers' => array('Content-Type' => 'application/json'));
		}
		// ── Documentation viewer ──
		if ($path === '/Q/docs/marked.min.js') {
			$jsPath = self::$serverDir . '/web/js/marked.min.js';
			if (is_file($jsPath)) {
				return array('status' => 200,
					'body' => file_get_contents($jsPath),
					'headers' => array('Content-Type' => 'application/javascript; charset=utf-8',
						'Cache-Control' => 'public, max-age=86400'));
			}
		}
		// The shell's terminal files (public: they run nothing).
		if (strpos($path, '/Q/shell/') === 0 && ($shellAsset = Q_WebServer_Shell::asset($path)) !== null) {
			return $shellAsset;
		}
		if ($path === '/Q/docs' || $path === '/Q/docs/') {
			return array('status' => 200,
				'body' => self::renderDocsViewer(),
				'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
		}
		if (strpos($path, '/Q/docs/raw/') === 0) {
			$docFile = substr($path, strlen('/Q/docs/raw/'));
			$docFile = str_replace('..', '', $docFile); // prevent traversal
			$docPath = self::$serverDir . '/docs/' . $docFile;
			if (is_file($docPath) && pathinfo($docPath, PATHINFO_EXTENSION) === 'md') {
				return array('status' => 200,
					'body' => file_get_contents($docPath),
					'headers' => array('Content-Type' => 'text/plain; charset=utf-8'));
			}
			// Also check README.md at root
			if ($docFile === 'README.md') {
				$readmePath = self::$serverDir . '/README.md';
				if (is_file($readmePath)) {
					return array('status' => 200,
						'body' => file_get_contents($readmePath),
						'headers' => array('Content-Type' => 'text/plain; charset=utf-8'));
				}
			}
			return array('status' => 404, 'body' => 'Not found');
		}
		if ($path === '/Q/docs/index.json') {
			// List available doc files
			$docsDir = self::$serverDir . '/docs';
			$files = array();
			if (is_dir($docsDir)) {
				foreach (scandir($docsDir) as $f) {
					if (pathinfo($f, PATHINFO_EXTENSION) === 'md') {
						$files[] = $f;
					}
				}
			}
			return array('status' => 200,
				'body' => json_encode(array('files' => $files, 'hasReadme' => is_file(self::$serverDir . '/README.md'))),
				'headers' => array('Content-Type' => 'application/json'));
		}

		if ($path === '/Q/phpinfo') {
			// The process environment is in it: never remote without a credential.
			if (!self::adminAllowed($parsed, true)) return self::adminRefusal($parsed);
			ob_start();
			phpinfo();
			$html = Q_WebServer_Shell::decorate(Q_WebServer_PhpInfo::page(ob_get_clean()));
			return array('status' => 200, 'body' => $html,
				'headers' => array('Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'));
		}

		if ($path === '/Q/dashboard' || $path === '/Q/dashboard/') {
			if (Q_Config::get('Q', 'dashboard', null) === false) {
				return array('status' => 404, 'body' => 'Not found');
			}
			if (!self::adminAllowed($parsed)) return self::adminRefusal($parsed);
			// Static dashboard token (from config)
			$token = Q_Config::get('Q', 'dashboard', 'token', null);
			if ($token !== null) {
				$qp = array();
				if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
				$given = $qp['token'] ?? '';
				if (!is_string($given) or !hash_equals((string) $token, $given)) {
					return array('status' => 403, 'body' => 'Forbidden — token required',
						'headers' => array('Content-Type' => 'text/plain'));
				}
			}
			// Panel password — require a valid session cookie
			// This machine is let in, as adminAllowed() lets it in everywhere else.
			if (Q_WebServer_Panel::hasPassword() && !self::isLocalRequest($parsed)) {
				// The same credential check as every other admin view: a panel
				// session in the cookie, a header or ?token=, or the dashboard token.
				if (!self::hasAdminCredential($parsed)) {
					// Redirect to panel (which has the login form)
					return self::adminLoginRedirect($parsed);
				}
			}
			return array('status'=>200, 'body'=>Q_WebServer_Dashboard::renderHtml($parsed),
				'headers'=>array('Content-Type'=>'text/html; charset=utf-8', 'Cache-Control'=>'no-store'));
		}
		// The control panel and its API, answered here as they are on the
		// HTTP/1.1 path (Q_WebServer_Panel::handle()). Nothing here answered
		// them, so over HTTP/2 -- every browser -- /Q/panel was declined,
		// handed to the application, and showed its 404.
		if (strpos($path, '/Q/panel') === 0 || strpos($path, '/Q/api/') === 0) {
			$panel = Q_WebServer_Panel::respond($parsed);
			if ($panel !== null) return $panel;
		}
		if (self::isBlocked($path)) {
			return array('status'=>403, 'body'=>self::renderErrorPage(403, $path),
				'headers'=>array('Content-Type'=>'text/html; charset=utf-8'));
		}

		// Asked only for the server's own routes (http2Route(), for /Q/ and
		// /.well-known/): everything the server answers itself has been
		// answered above, so what is left is the application's. Falling
		// through ran it right here -- index.php inside the server process,
		// not in a worker: /Q/panel took 838ms of the event loop, and a second
		// such request spun forever on the capture buffer and stopped the
		// server answering at all (2026-09-24). Declined, the caller hands it
		// to a worker like any other script.
		if (!empty($parsed['_builtinOnly'])) {
			return null;
		}

		$fsPath = self::resolveStatic($path);

		// Directory
		if ($fsPath && is_dir($fsPath)) {
			if (substr($path, -1) !== '/') {
				return array('status'=>301, 'body'=>'',
					'headers'=>array('Location'=>$path.'/'));
			}
			foreach (array('index.html','index.php') as $idx) {
				$ip = $fsPath.DS.$idx;
				if (is_file($ip)) { $fsPath = $ip; break; }
			}
			if (is_dir($fsPath)) {
				// Root path with no index → welcome page, unless the root was
				// explicitly made listable. The welcome page used to return
				// unconditionally, which left the listing below unreachable
				// for '/' however it was configured.
				if ($path === '/' and !self::isIndexed($path)) {
					$welcome = __DIR__ . DS . 'welcome.php';
					if (file_exists($welcome)) {
						ob_start();
						include $welcome;
						return array('status' => 200, 'body' => ob_get_clean(),
							'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
					}
				}
				// Show directory listing if indexed
				if (self::isIndexed($path)) {
					return array('status'=>200,
						'body'=>self::renderDirectoryListing($fsPath, $path),
						'headers'=>array('Content-Type'=>'text/html; charset=utf-8',
							'Cache-Control'=>'no-store'));
				}
				return array('status'=>403, 'body'=>self::renderErrorPage(403, $path),
					'headers'=>array('Content-Type'=>'text/plain'));
			}
		}

		// BUG #37: split "script.php/extra/path" into SCRIPT + PATH_INFO, the way
		// nginx's fastcgi_split_path_info does. Without this, a request for
		// /action.php/Safebox/workload is not a file, so it fell through to the
		// clean-URL front controller and every framework action URL rendered the
		// notFound page instead of dispatching. That broke the entire Node->PHP
		// contract (Q.Utils.sendToPHP posts to action.php/<Module>/<action>).
		if (!$fsPath || !is_file($fsPath)) {
			$_pi = self::splitPathInfo($path);
			if ($_pi !== null and (!self::insideRoot($_pi['scriptPath'])
				or !self::scriptRunnable($_pi['scriptPath']))) {
				$_pi = null;
			}
			if ($_pi !== null) {
				$parsed['_scriptPath'] = $_pi['scriptPath'];
				$parsed['_pathInfo']   = $_pi['pathInfo'];
				$response = self::dispatchToQ($parsed);
				$response = self::processPhpResponse($response, $parsed['headers']);
				return $response;
			}
		}

		// File
		// Take the extension from the RESOLVED file, not the URL. A directory
		// request like "/" has no extension in $path, but $fsPath has already been
		// resolved to the directory index (.../index.php) above. Reading $path here
		// meant "/" fell past the PHP branch into the static branch, where an empty
		// extension is not in $allowedExtensions — so the app's own home page came
		// back 403 while "/index.php" served fine.
		$ext = strtolower(pathinfo($fsPath ? $fsPath : $path, PATHINFO_EXTENSION));
		if ($fsPath && is_file($fsPath)) {

			if ($ext === 'php' && self::scriptRunnable($fsPath)) {
				// A link named *.php can point anywhere the server user can
				// read, and running it turns the ability to create one file
				// into the ability to run code from outside the site. The
				// request string cannot show this -- "/report.php" says nothing
				// about what the name leads to -- so it is asked of the
				// resolved path, here, before anything is executed.
				if (!self::insideRoot($fsPath)) {
					self::sendResponse($client, 403,
						self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
					return false;
				}
				// PHP dispatch (in-process — amphp uses fibers for concurrency)
				// Run the *requested* script (e.g. action.php), not index.php.
				$parsed['_scriptPath'] = $fsPath;
				$response = self::dispatchToQ($parsed);
				$response = self::processPhpResponse($response, $parsed['headers']);
				$response = Q_WebServer_Cache::put($parsed, $response);
				return $response;
			}

			if ($ext !== 'php' && in_array($ext, self::$allowedExtensions)
				&& ($method === 'GET' || $method === 'HEAD')
				&& self::servedAsFile($path)
			) {
				// Image resize/convert: ?w=300 or ?w=300&h=200
				if (in_array($ext, array('png','jpg','jpeg','gif','webp','bmp','avif'))
					&& !empty($parsed['query'])
				) {
					$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $parsed);
					if ($imgResponse) return $imgResponse;
				}
				return self::buildFileResponse($fsPath, $ext, $method, $parsed['headers']);
			}
		}

		// Image format conversion: /photo.webp when only /photo.png exists
		$imgExts = array('webp', 'avif', 'jpg', 'jpeg', 'png', 'gif');
		if (in_array($ext, $imgExts) && ($method === 'GET' || $method === 'HEAD')
			&& self::servedAsFile($path)) {
			$imgResponse = Q_WebServer_Image::handle(null, $path, $parsed);
			if ($imgResponse) return $imgResponse;
		}

		// Clean URL → the front controller (index.php unless configured)
		$front = self::frontController($path);
		if ($front !== null) {
			if ($front !== self::$rootDir . 'index.php') {
				$parsed['_scriptPath'] = $front;
			}
			$response = self::dispatchToQ($parsed);
			$response = self::processPhpResponse($response, $parsed['headers']);
			$response = Q_WebServer_Cache::put($parsed, $response);
			return $response;
		}

		return array('status'=>404, 'body'=>self::render404($path),
			'headers'=>array('Content-Type'=>'text/html; charset=utf-8'));
	}

	/**
	 * Process a PHP response: X-Accel-Redirect + compression.
	 * Used by both route() and handlePhp().
	 */
	static function processPhpResponse($response, $reqHeaders)
	{
		$passthrough = Q_Config::get(
			'Q', 'webserver', 'accel', 'passthrough', false
		);
		$headers = $passthrough
			? Q_WebServer_Headers::stripInternalExceptAccel($response['headers'] ?? array())
			: Q_WebServer_Headers::stripInternal($response['headers'] ?? array());
		$body = $response['body'] ?? '';

		// X-Accel-Redirect
		if (!$passthrough) {
			foreach ($response['headers'] ?? array() as $k => $v) {
				if (strtolower($k) === 'x-accel-redirect') {
					// Standalone: read the file and replace the body
					$af = Q_WebServer_Headers::resolveAccelPath($v);
					if ($af && is_file($af)) {
						$body = file_get_contents($af);
						$ext = strtolower(pathinfo($af, PATHINFO_EXTENSION));
						if (!Q_WebServer_Headers::hasHeader($headers, 'Content-Type')) {
							$headers['Content-Type'] = self::mimeType($ext);
						}
					}
					$headers = Q_WebServer_Headers::stripInternal($headers);
					break;
				}
			}
		}
		// In passthrough mode, X-Accel-Redirect stays in $headers for the
		// reverse proxy (nginx) to intercept and serve with sendfile().

		$ct = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'content-type') $ct = $v;
		}
		$body = Q_WebServer_Headers::maybeCompress($body, $ct, $reqHeaders, $headers);
		return array('status'=>$response['status']??200, 'body'=>$body, 'headers'=>$headers);
	}

	/**
	 * Cache-Control for a static file.
	 *
	 * The server answered every static file with
	 * "public, max-age=0, must-revalidate", which permits caching and then
	 * requires the client to ask about it anyway. For a page carrying dozens of
	 * stylesheets, scripts and images that is dozens of conditional requests on
	 * every view, each a round trip, each answered 304. A browser ends up
	 * asking whether anything changed more often than it asks for the page.
	 *
	 * The default is unchanged, so an installation that configures nothing
	 * behaves exactly as before. Set Q.web.static.maxAge to the number of
	 * seconds a client may keep a file without asking again.
	 *
	 * Staleness is the trade, and it is real: a file replaced in place is not
	 * noticed until the lifetime expires. It costs nothing for assets whose URL
	 * changes when their contents do, and it is why this is opt-in rather than
	 * a new default.
	 *
	 * @method staticCacheControl
	 * @static
	 * @return {string}
	 */
	static function staticCacheControl($path = null)
	{
		// Some files must not be held even when every other static file may be.
		//
		// A service worker is the clearest case: the browser installs it and it
		// then outlives the page that installed it, deciding what every later
		// navigation is answered with. Served with a year's max-age, a bug in
		// one cannot be withdrawn -- the fix sits on the server while browsers
		// keep running the old copy from their own cache. The specification
		// caps how long a worker script may be trusted for exactly this reason,
		// and it is not something to leave to the cap.
		//
		// The same applies to anything else whose whole job is to say what the
		// current state of the site is: a version manifest, an app manifest, a
		// kill switch.
		if ($path !== null) {
			foreach (self::revalidateAlways() as $pattern) {
				if (fnmatch($pattern, $path)
				or fnmatch($pattern, basename($path))) {
					return 'no-cache, must-revalidate';
				}
			}
		}

		static $value = null;
		if ($value !== null) return $value;

		$maxAge = (int) Q_Config::get('Q', 'web', 'static', 'maxAge', 0);
		$value = $maxAge > 0
			? 'public, max-age=' . $maxAge
			: 'public, max-age=0, must-revalidate';

		return $value;
	}

	/**
	 * Paths that must always be revalidated, whatever the static lifetime is.
	 *
	 * Defaults cover the files that decide how a client behaves afterwards. An
	 * installation can add to the list, and setting it to an empty array turns
	 * the behaviour off entirely.
	 *
	 * @method revalidateAlways
	 * @static
	 * @return {array} of fnmatch patterns
	 */
	static function revalidateAlways()
	{
		static $patterns = null;
		if ($patterns !== null) return $patterns;

		$configured = Q_Config::get('Q', 'web', 'static', 'revalidate', null);
		if (is_array($configured)) {
			$patterns = $configured;
		} else {
			$patterns = array(
				'sw.js',                // service worker, by convention
				'service-worker.js',
				'*.webmanifest',
				'manifest.json'
			);
		}
		return $patterns;
	}

	/**
	 * Build a static file response with ETag/compression.
	 * Used by route() for amphp compatibility.
	 */
	static function buildFileResponse($fsPath, $ext, $method, $reqHeaders)
	{
		clearstatcache(true, $fsPath);
		$mtime = filemtime($fsPath);
		$size = filesize($fsPath);
		$ct = self::mimeType($ext);
		$headers = array(
			'Content-Type' => $ct,
			'ETag' => '"' . dechex($mtime) . '-' . dechex($size) . '"',
			'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
			'Cache-Control' => self::staticCacheControl($fsPath)
		);
		if ($method === 'HEAD') {
			$body = '';
		} else {
			// Try the pre-compressed LRU cache first (build once, serve cached).
			$body = Q_WebServer_Precompress::serve(
				$fsPath, $ct, $mtime, $size, $reqHeaders, $headers
			);
			if ($body === null) {
				// Not eligible / disabled — read and compress on the fly as before.
				$body = file_get_contents($fsPath);
				$body = Q_WebServer_Headers::maybeCompress($body, $ct, $reqHeaders, $headers);
			}
		}
		return array('status'=>200, 'body'=>$body, 'headers'=>$headers);
	}

	// ── Built-in server: socket-based routing ────────────

	/**
	 * Route a request (built-in server).
	 * Returns true if the connection should stay open (WebSocket).
	 * @return {boolean}
	 */
	private static function handleRequest($client, $parsed)
	{
		$method = $parsed['method'];
		$path = $parsed['path'];
		self::$compressFor = null;

		// A suspended or disabled domain, or one of its redirects, is answered
		// here, before the reverse cache could serve a page stored earlier.
		$meta = is_resource($client) ? @stream_get_meta_data($client) : array();
		$parsed['_https'] = !empty($meta['crypto']);
		if (($gate = Q_WebServer_Domains::gate($parsed)) !== null) {
			$gateHeaders = $gate['headers'];
			$gateType = $gateHeaders['Content-Type'];
			unset($gateHeaders['Content-Type']);
			self::sendResponse($client, $gate['status'], $gate['body'], $gateType, $gateHeaders);
			return false;
		}

		// The domain's document root, for this request (restored by the
		// caller's finally). Before the reverse cache, whose key carries it.
		self::applyDomainRoot($parsed);

		// Reverse cache, before anything else this method would do.
		//
		// It used to sit 322 lines down, after host-config resolution, a
		// realpath(), a favicon file_exists(), the bundled-asset checks and
		// the well-known handlers -- all of which a cache hit then threw away.
		// Measured on this installation at concurrency 16, per request:
		//
		//     static file, 10 KB        0.34 ms of CPU
		//     cached 404, tiny body     1.30 ms
		//     cached page, 9.3 KB       3.80 ms
		//
		// The 404 carries almost no body and still cost four times a static
		// file of 10 KB, so most of that was the walk to the cache rather than
		// the cache itself.
		//
		// Safe to hoist because nothing above it decided whether the response
		// may be served: the cache key already includes the Host, and an entry
		// only exists because a previous request passed every check below and
		// produced a cacheable 200. The one thing that must still precede it
		// is the ACME challenge, which is answered from a store of its own and
		// must work even when the cache is confused.
		if ($method === 'GET' or $method === 'HEAD') {
			// A HEAD is answered from the GET's entry, headers only: without
			// this it went to a worker and rendered the page to throw it away.
			$cached = Q_WebServer_Cache::get($parsed, true);
			if ($cached) {
				$fresh = Q_WebServer_Cache::notModified($cached, $parsed['headers']);
				if ($fresh !== null) $cached = $fresh;
				if (!isset($cached['headers']) or !is_array($cached['headers'])) $cached['headers'] = array();
				Q_WebServer_Domains::addHsts($cached['headers'], $parsed['headers']['host'] ?? '', !empty($parsed['_https']));
				self::sendResponse($client, $cached['status'],
					$cached['body'],
					$cached['headers']['Content-Type'] ?? 'text/html',
					$cached['headers'], $method === 'HEAD');
				return false;
			}
		}

		// ACME HTTP-01 challenge handler (for automatic TLS)
		if (strpos($path, '/.well-known/acme-challenge/') === 0) {
			$token = substr($path, strlen('/.well-known/acme-challenge/'));
			$response = Q_WebServer_Acme::getChallengeResponse($token);
			if ($response !== null) {
				return self::sendResponse($client, 200, $response, array(
					'Content-Type' => 'text/plain'
				));
			}
		}

		// Virtual hosts — override rootDir based on Host header
		$host = $parsed['headers']['host'] ?? '';
		$host = strtolower(preg_replace('/:\d+$/', '', $host)); // strip port
		$hostConfig = Q_Config::get('Q', 'webserver', 'hosts', $host, null);
		if (!$hostConfig) {
			$hostConfig = Q_Config::get('Q', 'webserver', 'domains', $host, null);
		}
		if ($hostConfig && isset($hostConfig['root'])) {
			$vroot = realpath($hostConfig['root']);
			if ($vroot && is_dir($vroot)) {
				self::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $vroot), DS) . DS;
			}
		} elseif ($host && !$hostConfig) {
			// Unknown host — try autohost
			$autohostFile = __DIR__ . '/WebServer/Autohost.php';
			if (is_file($autohostFile)) {
				require_once $autohostFile;
				if (Q_WebServer_Autohost::enabled() && !Q_WebServer_Autohost::isKnownHost($host)) {
					$clientIp = $parsed['headers']['x-forwarded-for']
						?? ($parsed['headers']['x-real-ip'] ?? '127.0.0.1');
					$clientIp = explode(',', $clientIp)[0];
					$result = Q_WebServer_Autohost::handleUnknownHost($host, trim($clientIp), $parsed);
					if ($result && !empty($result['splash'])) {
						return self::sendResponse($client, 503, $result['html'], array(
							'Content-Type' => 'text/html; charset=utf-8',
							'Retry-After' => '5',
						));
					}
					// If provisioning succeeded, re-check the host config
					$hostConfig = Q_Config::get('Q', 'webserver', 'domains', $host, null);
					if ($hostConfig && isset($hostConfig['root'])) {
						$vroot = realpath($hostConfig['root']);
						if ($vroot && is_dir($vroot)) {
							self::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $vroot), DS) . DS;
						}
					}
				}
			}
		}

		// The server's own icons, manifest and link-preview image, and the
		// origin its pages put in absolute preview URLs.
		if (strncmp($path, '/Q/', 3) === 0) {
			$clientMeta = is_resource($client) ? @stream_get_meta_data($client) : array();
			Q_WebServer_Brand::noteRequest($parsed, !empty($clientMeta['crypto']));
			$brandResponse = Q_WebServer_Brand::route($path);
			if ($brandResponse !== null) {
				$brandHeaders = $brandResponse['headers'];
				$brandType = $brandHeaders['Content-Type'];
				unset($brandHeaders['Content-Type']);
				self::sendResponse($client, $brandResponse['status'], $brandResponse['body'],
					$brandType, $brandHeaders);
				return false;
			}
		}

		// 1. Dashboard + Panel + WebSocket + Health (/Q/*)
		// 1. Serve built-in assets (JS clients, logo, bundled frontend)
		$assetMap = array();
		$jsPath = Q_Config::get('Q', 'socket', 'js', '/Q/socket.js');
		if ($jsPath !== false) $assetMap[$jsPath] = array(__DIR__ . DS . 'socket.js', 'application/javascript');
		$ioPath = Q_Config::get('Q', 'socket', 'io', '/socket.io');
		if ($ioPath !== false) $assetMap[$ioPath . '/socket.io.js'] = array(__DIR__ . DS . 'socket.io.js', 'application/javascript');
		$assetMap['/Q/logo.svg'] = array(__DIR__ . DS . 'logo.svg', 'image/svg+xml');
		$assetMap['/Q/logo.png'] = array(__DIR__ . DS . 'logo.png', 'image/png');
		$assetMap['/Q/prism.js'] = array(__DIR__ . DS . 'prism.js', 'application/javascript');
		$assetMap['/Q/prism.css'] = array(__DIR__ . DS . 'prism.css', 'text/css');
		// Issue #17: only fall back to built-in favicon when the app has none
		$appFavicon = self::$rootDir . 'favicon.ico';
		if (!file_exists($appFavicon)) {
			$assetMap['/favicon.ico'] = array(__DIR__ . DS . 'logo.png', 'image/png');
		}
		if (isset($assetMap[$path])) {
			list($assetFile, $assetType) = $assetMap[$path];
			if (file_exists($assetFile)) {
				self::sendResponse($client, 200, file_get_contents($assetFile),
					$assetType, array('Cache-Control' => 'public, max-age=604800'));
			} else {
				self::sendResponse($client, 404, 'Not found');
			}
			return false;
		}

		// Serve bundled Qbix frontend: /Q/plugins/Q/js/*, /Q/plugins/Q/text/*
		if (strpos($path, '/Q/plugins/') === 0) {
			$relPath = substr($path, 11); // strip /Q/plugins/
			$bundledFile = __DIR__ . DS . 'plugins' . DS . str_replace('/', DS, $relPath);
			if (file_exists($bundledFile) && is_file($bundledFile)) {
				$ext = strtolower(pathinfo($bundledFile, PATHINFO_EXTENSION));
				$mimeMap = array(
					'js' => 'application/javascript', 'json' => 'application/json',
					'css' => 'text/css', 'html' => 'text/html',
					'png' => 'image/png', 'jpg' => 'image/jpeg',
					'svg' => 'image/svg+xml', 'woff2' => 'font/woff2',
				);
				$mime = $mimeMap[$ext] ?? 'application/octet-stream';
				self::sendResponse($client, 200, file_get_contents($bundledFile),
					$mime, array('Cache-Control' => 'public, max-age=86400'));
				return false;
			}
		}

		// 2. Server discovery + federation endpoints
		// RFC 8615 .well-known endpoints
		if (strpos($path, '/.well-known/') === 0) {
			$wellKnown = substr($path, 13);

			$wkResponse = null;
			if ($wellKnown === 'qbix' || $wellKnown === 'qbix.json') {
				$wkResponse = self::wellKnownQbix($parsed);
			} elseif ($wellKnown === 'openapi.json' || $wellKnown === 'openapi') {
				$wkResponse = self::wellKnownOpenAPI($parsed);
			} elseif ($wellKnown === 'mcp.json' || $wellKnown === 'mcp') {
				$wkResponse = self::wellKnownMCP($parsed);
			} elseif (strpos($wellKnown, 'openclaiming/') === 0) {
				$wkResponse = self::wellKnownOpenClaiming($parsed, $wellKnown);
			}

			if ($wkResponse) {
				self::sendResponse($client, $wkResponse['status'] ?? 200,
					$wkResponse['body'] ?? '',
					$wkResponse['headers']['Content-Type'] ?? 'application/json',
					$wkResponse['headers'] ?? array());
				return false;
			}
			// Fall through for other .well-known files (apple-app-site-association, acme, etc.)
		}
		if ($path === '/Q/event' && $method === 'POST') {
			$response = self::handleRemoteEvent($parsed);
			self::sendResponse($client, $response['status'] ?? 200, $response['body'] ?? '',
				'application/json');
			return false;
		}

		// 3. Dashboard + Panel + WebSocket + Health (/Q/*)
		if (strpos($path, '/Q/') === 0) {
			self::$compressFor = $parsed['headers'] ?? array();
			// The shell's terminal: its own socket, its own checks (Origin, a
			// signed-in panel session; see Q_WebServer_Shell_Api::upgrade()).
			if ($path === '/Q/ws/shell') {
				$r = Q_WebServer_Shell_Api::upgrade($client, $parsed);
				if ($r === true) return true;
				self::sendResponse($client, $r[0], $r[1], 'text/plain; charset=utf-8');
				return false;
			}
			if (strpos($path, '/Q/shell/') === 0 && ($shellAsset = Q_WebServer_Shell::asset($path)) !== null) {
				self::sendResponse($client, $shellAsset['status'], $shellAsset['body'],
					$shellAsset['headers']['Content-Type'] ?? 'text/plain', $shellAsset['headers']);
				return false;
			}
			if ($path === '/Q/ws') {
				if (Q_Config::get('Q', 'dashboard', null) === false) {
					self::sendResponse($client, 404, 'Not found');
					return false;
				}
				// The same rule as the dashboard page (adminAllowed()). This
				// used to let anyone connect while no panel password was set,
				// even with a dashboard token configured, and compared the
				// token with === rather than in constant time.
				$authed = self::adminAllowed($parsed);
				if (!$authed) {
					self::sendResponse($client, 403, 'Forbidden — token required');
					return false;
				}
				$upgraded = Q_WebSocket::upgrade(
					$client, $parsed['headers'], null, 'dashboard'
				);
				return $upgraded; // true = keep open
			}
			// Documentation viewer
			if ($path === '/Q/docs/marked.min.js') {
				$jsPath = self::$serverDir . '/web/js/marked.min.js';
				if (is_file($jsPath)) {
					self::sendResponse($client, 200, file_get_contents($jsPath),
						'application/javascript; charset=utf-8',
						array('Cache-Control' => 'public, max-age=86400'));
					return false;
				}
			}
			if ($path === '/Q/phpinfo') {
				// The process environment is in it: never remote without a credential.
				if (!self::adminAllowed($parsed, true)) {
					$f = self::adminRefusal($parsed);
					$h = $f['headers']; $ct = $h['Content-Type']; unset($h['Content-Type']);
					self::sendResponse($client, $f['status'], $f['body'], $ct, $h);
					return false;
				}
				ob_start();
				phpinfo();
				$html = Q_WebServer_Shell::decorate(Q_WebServer_PhpInfo::page(ob_get_clean()));
				self::sendResponse($client, 200, $html, 'text/html; charset=utf-8', array('Cache-Control' => 'no-store'));
				return false;
			}
			// The rest of the admin surface, before anything answers it.
			if (in_array(rtrim($path, '/'), array('/Q/dashboard', '/Q/stats', '/Q/metrics', '/Q/attestation'), true)
				and !self::adminAllowed($parsed)) {
				$f = self::adminRefusal($parsed);
				$h = $f['headers']; $ct = $h['Content-Type']; unset($h['Content-Type']);
				self::sendResponse($client, $f['status'], $f['body'], $ct, $h);
				return false;
			}
			if ($path === '/Q/docs' || $path === '/Q/docs/') {
				self::sendResponse($client, 200, self::renderDocsViewer(), 'text/html; charset=utf-8');
				return false;
			}
			if (strpos($path, '/Q/docs/raw/') === 0) {
				$docFile = str_replace('..', '', substr($path, strlen('/Q/docs/raw/')));
				$docPath = ($docFile === 'README.md')
					? self::$serverDir . '/README.md'
					: self::$serverDir . '/docs/' . $docFile;
				if (is_file($docPath) && pathinfo($docPath, PATHINFO_EXTENSION) === 'md') {
					self::sendResponse($client, 200, file_get_contents($docPath), 'text/plain; charset=utf-8');
				} else {
					self::sendResponse($client, 404, 'Not found');
				}
				return false;
			}
			if ($path === '/Q/docs/index.json') {
				$docsDir = self::$serverDir . '/docs';
				$files = array();
				if (is_dir($docsDir)) {
					foreach (scandir($docsDir) as $f) {
						if (pathinfo($f, PATHINFO_EXTENSION) === 'md') $files[] = $f;
					}
				}
				self::sendResponse($client, 200,
					json_encode(array('files' => $files, 'hasReadme' => is_file(self::$serverDir . '/README.md'))),
					'application/json');
				return false;
			}
			if ($path === '/Q/health') {
				if (Q_Config::get('Q', 'dashboard', null) === false) {
					self::sendResponse($client, 404, 'Not found');
					return false;
				}
				// Liveness for anyone (load balancers, cluster peers); the stats,
				// which name request paths, for an admin only.
				if (!self::adminAllowed($parsed)) {
					self::sendResponse($client, 200, '{"status":"ok"}', 'application/json');
					return false;
				}
				$stats = Q_WebServer_Dashboard::getStats();
				$result = array('status' => 'ok') + $stats;
				if (class_exists('Q_WebServer_Cluster', false) && Q_WebServer_Cluster::isActive()) {
					$result['cluster'] = Q_WebServer_Cluster::status();
				}
				self::sendResponse($client, 200,
					json_encode($result),
					'application/json');
				return false;
			}
			if ($path === '/Q/metrics') {
				$r = self::metricsResponse($parsed);
				$type = $r['headers']['Content-Type'];
				unset($r['headers']['Content-Type']);
				self::sendResponse($client, $r['status'], $r['body'], $type, $r['headers']);
				return false;
			}
			if ($path === '/Q/attestation') {
				$trustFile = __DIR__ . '/WebServer/Trust.php';
				if (is_file($trustFile)) {
					require_once $trustFile;
					self::sendResponse($client, 200,
						json_encode(Q_WebServer_Trust::attestation()),
						'application/json');
				} else {
					self::sendResponse($client, 404, 'Not available');
				}
				return false;
			}
			// ── Mesh sync endpoints (opt-in; OFF by default) — see HTTP/1.1 path ──
			if (strpos($path, '/Q/sync/') === 0) {
				if (!class_exists('Q_WebServer_Mesh', false)) {
					require_once __DIR__ . '/WebServer/Mesh.php';
				}
				if (!Q_WebServer_Mesh::enabled()) {
					self::sendResponse($client, 404, 'Not found');
					return false;
				}
				$sa = substr($path, 8);
				$sd = !empty($parsed['body']) ? (json_decode($parsed['body'], true) ?: array()) : array();
				$sq = $parsed['query'] ?? array();
				if (!is_array($sq)) { $qp = array(); parse_str((string) $sq, $qp); $sq = $qp; }
				$sd = array_merge($sd, $sq);
				Q_WebServer_Mesh::init();
				$r = Q_WebServer_Mesh::handleSyncApi($sa, $sd);
				self::sendResponse($client, 200, json_encode($r), 'application/json');
				return false;
			}
			if ($path === '/Q/cluster/join' && $method === 'POST') {
				if (class_exists('Q_WebServer_Cluster', false)) {
					$data = json_decode($parsed['body'] ?? '', true) ?: array();
					if (!Q_WebServer_Cluster::joinAllowed($parsed, $data)) {
						self::sendResponse($client, 403, 'Forbidden');
						return false;
					}
					Q_WebServer_Cluster::handleJoin($data);
					self::sendResponse($client, 200, '{"ok":true}', 'application/json');
				} else {
					self::sendResponse($client, 404, 'Clustering not active');
				}
				return false;
			}
			if ($path === '/Q/cluster/status') {
				if (class_exists('Q_WebServer_Cluster', false) && Q_WebServer_Cluster::isActive()) {
					self::sendResponse($client, 200,
						json_encode(Q_WebServer_Cluster::status()),
						'application/json');
				} else {
					self::sendResponse($client, 200,
						'{"active":false,"mode":"standalone"}',
						'application/json');
				}
				return false;
			}
			// Panel (control panel + API)
			if ($path === '/Q/api/images/zip' && $method === 'POST') {
				self::handleBulkImageZip($client, $parsed);
				return false;
			}
			$handled = Q_WebServer_Panel::handle($client, $parsed);
			if ($handled) return false;
			// Dashboard (live stats)
			$handled = Q_WebServer_Dashboard::handle($client, $parsed);
			if ($handled) return false;
			self::$compressFor = null;
		}

		// 2. WebSocket upgrade on any path
		$upgrade = strtolower($parsed['headers']['upgrade'] ?? '');
		if ($upgrade === 'websocket' && $path !== '/Q/ws') {
			$upgraded = Q_WebSocket::upgrade(
				$client, $parsed['headers'],
				function ($sk, $msg) use ($path) {
					Q_WebSocket::dispatchEvent($sk, $msg, $path);
				},
				null, $path
			);
			return $upgraded;
		}

		// 3. Blocked paths
		if (self::isBlocked($path)) {
			self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
			return false;
		}

		// The component cache stores no pages -- only hashes and the data
		// each page depends on (Q_WebServer_Cache_Components). A page it knows
		// is served by the response cache, consulted at the top of this
		// method, until an invalidation purges it there. This used to call a
		// getPage() that class has never had, which would have been a fatal
		// error on every request once the layer was switched on.

		// Reverse cache: already consulted at the top of this method. What
		// reaches here has missed, so this is left as the single place the
		// miss path continues from.

		// 4. Resolve filesystem path
		$fsPath = self::resolveStatic($path);

		// 4. Directory handling
		if ($fsPath && is_dir($fsPath)) {
			if (substr($path, -1) !== '/') {
				self::sendRedirect($client, $path . '/');
				return false;
			}
			// Check for index files
			foreach (array('index.html', 'index.php') as $idx) {
				$indexPath = $fsPath . DS . $idx;
				if (is_file($indexPath)) {
					$fsPath = $indexPath;
					break;
				}
			}
			if (is_dir($fsPath)) {
				// Root path with no index → welcome page, unless the root was
				// explicitly made listable. The welcome page used to return
				// unconditionally, which left the listing below unreachable
				// for '/' however it was configured.
				if ($path === '/' and !self::isIndexed($path)) {
					$welcome = __DIR__ . DS . 'welcome.php';
					if (file_exists($welcome)) {
						ob_start();
						include $welcome;
						self::sendResponse($client, 200, ob_get_clean(),
							'text/html; charset=utf-8');
						return false;
					}
				}
				// Show directory listing if indexed
				if (self::isIndexed($path)) {
					$html = self::renderDirectoryListing($fsPath, $path);
					self::sendResponse($client, 200, $html, 'text/html; charset=utf-8',
						array('Cache-Control' => 'no-store'));
				} else {
					self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
				}
				return false;
			}
		}

		// BUG #37: split "script.php/extra/path" into SCRIPT + PATH_INFO, the way
		// nginx's fastcgi_split_path_info does. /action.php/Safebox/workload is
		// not itself a file, so without this it fell through to the clean-URL
		// branch (index.php) and every framework action URL rendered the notFound
		// page instead of dispatching — which broke the whole Node->PHP contract,
		// since Q.Utils.sendToPHP posts to action.php/<Module>/<action>.
		if (!$fsPath || !is_file($fsPath)) {
			$_pi = self::splitPathInfo($path);
			if ($_pi !== null and self::scriptRunnable($_pi['scriptPath'])) {
				$parsed['_pathInfo'] = $_pi['pathInfo'];
				return self::handlePhp($client, $parsed, $_pi['scriptPath']);
			}
		}

		// 5. File handling
		// Extension from the RESOLVED file, not the URL. "/" carries no
		// extension, but $fsPath was already resolved to the directory index
		// (.../index.php). Reading $path here sent "/" down the STATIC path,
		// where serveStaticFile() rejects an unlisted extension — so the app's
		// home page returned 403 while "/index.php" served fine.
		$ext = strtolower(pathinfo($fsPath ? $fsPath : $path, PATHINFO_EXTENSION));
		if ($fsPath && is_file($fsPath)) {

			// PHP scripts → worker pool or in-process; one not listed in
			// Q.webserver.scripts goes to the front controller below.
			if ($ext === 'php' && self::scriptRunnable($fsPath)) {
				return self::handlePhp($client, $parsed, $fsPath);
			}

			// Static file, if Q.web.static.paths lets it be one
			if ($ext !== 'php' && ($method === 'GET' || $method === 'HEAD')
				&& self::servedAsFile($path)) {
				// Image resize/convert: ?w=300 or ?w=300&h=200
				if (in_array($ext, array('png','jpg','jpeg','gif','webp','bmp','avif'))
					&& !empty($parsed['query'])
				) {
					$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $parsed);
					if ($imgResponse) {
						Q_WebServer_Headers::processResponse($client, $imgResponse, $parsed['headers']);
						return false;
					}
				}
				self::serveStaticFile($client, $fsPath, $method, $parsed['headers'], !empty($parsed['_keepAlive']));
				return false;
			}
		}

		// Image format conversion: /photo.webp when only /photo.png exists
		$imgExts = array('webp', 'avif', 'jpg', 'jpeg', 'png', 'gif');
		if (in_array($ext, $imgExts) && ($method === 'GET' || $method === 'HEAD')
			&& self::servedAsFile($path)) {
			$imgResponse = Q_WebServer_Image::handle(null, $path, $parsed);
			if ($imgResponse) {
				Q_WebServer_Headers::processResponse($client, $imgResponse, $parsed['headers']);
				return false;
			}
		}

		// 6. Route dispatch — if Q.routes configured, match URL to handler
		//    Q_Uri caches compiled patterns and path→URI results in memory.
		// Q_WebServer_Router picks whichever Q_Uri entry point exists: our
		// fromPath() standalone, the Platform's from() in --app mode. Both read
		// the same Q/routes table, so the webserver's cases behave identically.
		$uri = Q_WebServer_Router::resolve($path);
		if ($uri) {
			return self::handleRoute($client, $parsed, $uri);
		}

		// 7. Clean URL → the front controller (index.php unless configured)
		$indexPhp = self::frontController($path);
		if ($indexPhp !== null) {
			return self::handlePhp($client, $parsed, $indexPhp);
		}

		// 8. Configurable fallback (SPA routing, custom 404 page, etc.)
		//    Q.webserver.fallback can be:
		//    - string: path to a static file relative to web/ (e.g. "index.html")
		//    - object with "handler": event name to dispatch via Q::event()
		//    - object with "file": static file + auto-detect Content-Type
		static $fallback = null;
		if ($fallback === null) $fallback = Q_Config::get('Q', 'webserver', 'fallback', null);
		if ($fallback !== null) {
			if (is_string($fallback)) {
				// Static file (SPA catch-all: serve index.html for all routes)
				$fbPath = self::$rootDir . str_replace('/', DS, $fallback);
				if (is_file($fbPath)) {
					return self::serveFile($client, $parsed, $fbPath);
				}
			} elseif (is_array($fallback)) {
				if (!empty($fallback['handler'])) {
					// Route to a handler — build a synthetic Q_Uri
					$uri = Q_Uri::from(array(
						'module' => dirname($fallback['handler']),
						'action' => basename($fallback['handler']),
						'_originalPath' => $path,
					));
					if ($uri) {
						return self::handleRoute($client, $parsed, $uri);
					}
				} elseif (!empty($fallback['file'])) {
					$fbPath = self::$rootDir . str_replace('/', DS, $fallback['file']);
					if (is_file($fbPath)) {
						return self::serveFile($client, $parsed, $fbPath);
					}
				}
			}
		}

		// 9. Not found
		self::sendResponse($client, 404, self::render404($path), 'text/html; charset=utf-8');
		return false;
	}

	/**
	 * Handle a routed request via Q::event() dispatch pipeline.
	 * Fires the same events as Qbix Platform's Q_Dispatcher:
	 *   {module}/{action}/validate → validate input
	 *   {module}/{action}/{method} → handle GET/POST/PUT/DELETE
	 *   {module}/{action}/response → render response
	 *
	 * @method handleRoute
	 * @static
	 * @private
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @param {Q_Uri} $uri
	 * @return {boolean}
	 */
	private static function handleRoute($client, $parsed, $uri)
	{
		$module = $uri->module;
		$action = $uri->action;
		$routed = $uri->toArray();
		$method = $parsed['method']; // GET, POST, PUT, DELETE (case-sensitive per RFC 9110 §9.1)
		$methodLower = strtolower($method); // for handler file lookup (handlers/module/action/get.php)

		// Set up superglobals
		$parsed['_scriptPath'] = ''; // no script — handler-based
		$saved = array($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE);		$_SERVER['REQUEST_METHOD'] = $parsed['method'];
		$_SERVER['REQUEST_URI'] = $parsed['uri'];
		$_SERVER['QUERY_STRING'] = $parsed['query'];
		$_SERVER['SERVER_NAME'] = explode(':', $parsed['headers']['host'] ?? 'localhost')[0];
		$_hostPort = explode(':', $parsed['headers']['host'] ?? 'localhost');
		$_SERVER['SERVER_PORT'] = isset($_hostPort[1]) ? $_hostPort[1] : '80';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/1.0';
		$_SERVER['DOCUMENT_ROOT'] = rtrim(self::$rootDir, DS);
		$_SERVER['REMOTE_ADDR'] = $parsed['_remoteAddr'] ?? '127.0.0.1';
		$_SERVER['REQUEST_TIME'] = time();
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
		foreach ($parsed['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		// Populate getallheaders() with original-case names and raw values
		if (class_exists('Q_WebServer_GetAllHeaders', false)) {
			Q_WebServer_GetAllHeaders::set(
				$parsed['headers'],
				$parsed['rawHeaders'] ?? array()
			);
		}

		$_GET = $_POST = $_REQUEST = $_FILES = array();
		if ($parsed['query']) parse_str($parsed['query'], $_GET);
		$ct = strtolower($parsed['headers']['content-type'] ?? '');
		$rawBody = $parsed['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($rawBody, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($rawBody, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			$origCt = $parsed['headers']['content-type'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
			self::parseMultipart($origCt, $rawBody, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// Make raw body available via Q_Request::input() and php://input
		Q_WebServer_State::setInput($rawBody);
		if (class_exists('Q_Request', false)) {
			if (property_exists('Q_Request', 'input')) Q_Request::$input = $rawBody;
		}

		// If pcntl available, fork to isolate
		if (Q_WebServer_Fork::available()) {
			$pid = Q_WebServer_Fork::fork();
			if ($pid === 0) {
				// ── CHILD: run dispatch pipeline ──
				self::dropOutputBuffers();
				ob_start();
				$status = 200;
				$headers = array();

				try {
					// 1. Validate
					Q::event("$module/$action/validate", $routed, false, true);

					// 2. Method handler (get, post, put, delete)
					if (Q::canHandle("$module/$action/$methodLower")) {
						Q::event("$module/$action/$methodLower", $routed);
					} elseif ($method !== 'GET') {
						$status = 405;
						echo 'Method Not Allowed';
					}

					// 3. Response
					Q::event("$module/$action/response", $routed, false, true);

			$headers = Q_WebServer::getResponseHeaders();
					$code = http_response_code();
					if ($code && $code !== 200) $status = $code;
					if (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();
				} catch (\Throwable $e) {
					$status = 500;
					ob_clean();
					echo json_encode(array('error' => $e->getMessage()));
					$headers['Content-Type'] = 'application/json';
				}

				$body = ob_get_clean();
				$_method = $parsed['method'] ?? 'GET';
				$response = compact('status', 'body', 'headers', '_method');
				Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
				if (is_resource($client)) @fclose($client);
				exit($status >= 500 ? 1 : 0);
			} elseif ($pid > 0) {
				if (is_resource($client)) @fclose($client);
				$key = (int) $client;
				if (isset(self::$clientWatchers[$key])) {
					Q_Evented::cancel(self::$clientWatchers[$key]);
				}
				unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
				self::$workerPids[$pid] = array(
					'time' => microtime(true),
					'method' => $parsed['method'],
					'uri' => $parsed['uri'],
					// Client IP and user agent so a worker that crashes (502) or
					// is killed for running too long (504) is recorded with the
					// request it was serving. Deliberately not the cookies: a
					// session id must never enter the error telemetry.
					'clientIp' => $parsed['clientIp'] ?? ($parsed['_remoteAddr'] ?? ''),
					'userAgent' => $parsed['headers']['user-agent'] ?? '',
				);
				Q_WebServer_Fork::waitpid($pid, $st, 1);
				self::$lastStatus = -1; // -1 = delegated to child, don't record in parent
				list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;
				return false;
			}
			// Fork failed — fall through to in-process
		}

		// EMERGENCY in-process fallback -- reached only when fork() itself failed
		// (EAGAIN/ENOMEM). This is the ONE path where a process serves more than
		// one request, so it must explicitly discard the previous request's
		// captured state. Everything else relies on per-request processes.
		// See requireIsolation(): we never get here without pcntl or php-cgi.
		if (class_exists('Q_Response', false)
		and method_exists('Q_Response', 'clear')) {
			Q_WebServer_State::clear();
		}
		if (class_exists('Q_Sapi', false)) {
			Q_Sapi::$captured = null;
			Q_Sapi::$delivered = false;
			Q_Sapi::$entered = false;
		}
		@error_log('Q_WebServer: fork failed, serving in-process (state cleared)');

		self::dropOutputBuffers();
		header_remove();
		http_response_code(200);
		ob_start();
		$status = 200;
		$headers = array();

		try {
			Q::event("$module/$action/validate", $routed, false, true);
			if (Q::canHandle("$module/$action/$methodLower")) {
				Q::event("$module/$action/$methodLower", $routed);
			} elseif ($method !== 'GET') {
				$status = 405;
				echo 'Method Not Allowed';
			}
			Q::event("$module/$action/response", $routed, false, true);
			$headers = Q_WebServer::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			if (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();
		} catch (\Throwable $e) {
			$status = 500;
			ob_clean();
			// The message, or with --debug where and how (see ErrorReport);
			// the log gets the details either way.
			echo json_encode(array('error' => class_exists('Q_WebServer_ErrorReport')
				? Q_WebServer_ErrorReport::body($e) : $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
			if (class_exists('Q_WebServer_ErrorReport')) Q_WebServer_ErrorReport::log($e, 'in-process');
		}

		$body = ob_get_clean();
		header_remove();
		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		$_method = $parsed['method'] ?? 'GET';
		$response = compact('status', 'body', 'headers', '_method');
		Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
		self::$lastStatus = $status;
		$response = Q_WebServer_Cache::put($parsed, $response);
		return false;
	}

	/**
	 * Route a .php script to the worker pool or dispatch in-process.
	 * @return {boolean} false (connection closes after response)
	 */
	private static function handlePhp($client, $parsed, $scriptPath)
	{
		// Every HTTP/1 route that ends in running a PHP file arrives here, so
		// this is where the file is asked whether it is actually part of the
		// site. A symbolic link named *.php can point anywhere the server user
		// can read, and nothing in the request shows it: "/report.php" says
		// nothing about where that name leads. Running it turns the ability to
		// create one file into the ability to run code from outside the
		// document root entirely.
		if (!self::insideRoot($scriptPath)) {
			self::sendResponse($client, 403,
				self::renderErrorPage(403, $parsed['uri'] ?? ''),
				'text/html; charset=utf-8');
			return false;
		}

		// ── CGI carveout: check if this script should use php-cgi ──
		// Scripts matching Q.webserver.cgi.patterns run via php-cgi subprocess
		// where native header(), setcookie(), headers_list() all work.
		// Use for legacy/third-party code (WordPress, etc.) that calls header() directly.
		static $cgiPatterns = null;
		static $cgiBinary = null;
		if ($cgiPatterns === null) {
			// Item 2: without pcntl there is no fork, so per-request process
			// isolation can only come from php-cgi. Default to routing ALL php
			// through it there; `patterns` then means "exceptions". With pcntl
			// available the carveout stays opt-in.
			$_cgiDefault = Q_WebServer_Fork::available() ? array() : array('*');
			$cgiPatterns = Q_Config::get('Q', 'webserver', 'cgi', 'patterns', $_cgiDefault);
			$cgiBinary = Q_Config::get('Q', 'webserver', 'cgi', 'binary', null);
			if (!$cgiBinary) {
				// Auto-detect php-cgi
				foreach (array('php-cgi', 'php-cgi8.3', 'php-cgi8.2', 'php-cgi8.1') as $bin) {
					$whichCmd = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
					$nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
					$path = trim(shell_exec("$whichCmd $bin 2>$nullDev") ?? '');
					if ($path) {
						$path = explode("\n", $path)[0]; // Windows 'where' may return multiple lines
						if (is_file($path)) { $cgiBinary = trim($path); break; }
					}
				}
			}
		}
		if (!empty($cgiPatterns) && $cgiBinary) {
			$relPath = '/' . ltrim(str_replace(DS, '/', substr($scriptPath, strlen(self::$rootDir))), '/');
			foreach ($cgiPatterns as $pattern) {
				if (@preg_match(self::ensureRegex($pattern), $relPath)) {
					return self::handlePhpCgi($client, $parsed, $scriptPath, $cgiBinary);
				}
			}
		}

		if (self::$pool) {
			// -1 = handed on, the parent must not record it now. The pool
			// answers from its own event and calls recordCompleted() then;
			// recording here would log dispatch time, status 200 and the
			// previous response's size for every script.
			self::$lastStatus = -1;
			self::$pool->dispatch($client, $parsed, $scriptPath);
			$key = (int) $client;
			if (isset(self::$clientWatchers[$key])) {
				Q_Evented::cancel(self::$clientWatchers[$key]);
			}
			unset(self::$clientWatchers[$key], self::$clients[$key],
				self::$buffers[$key], self::$clientState[$key]);
			return false;
		}

		// No pool — fork a single child if pcntl is available.
		// This protects the server from exit()/die() in scripts
		// and prevents blocking the event loop during PHP execution.
		if (Q_WebServer_Fork::available()) {
			$pid = Q_WebServer_Fork::fork();
			if ($pid === -1) {
				// Fork failed — fall through to in-process execution
			} elseif ($pid === 0) {
				// ── CHILD: handle request, write response to client, exit ──
				$parsed['_scriptPath'] = $scriptPath;
				$parsed['_client'] = $client; // for SSE/streaming in dispatchToQ

				// Populate getallheaders() for PHP scripts
				if (class_exists('Q_WebServer_GetAllHeaders', false)) {
					Q_WebServer_GetAllHeaders::set(
						$parsed['headers'],
						$parsed['rawHeaders'] ?? array()
					);
				}

				// A script calling exit()/die() unwinds straight past
				// dispatchToQ()'s return, so processResponse() below never ran
				// and the client received ZERO bytes -- while the access log
				// still said 200, because the parent had already assumed
				// success. Register the emit as a shutdown function so it
				// happens on every termination path: normal return, exit(),
				// uncaught exception and fatal error.
				$emitted = false;
				$emit = function ($response) use (&$emitted, $client, $parsed) {
					if ($emitted) return;      // exactly once
					$emitted = true;
					// If streaming already sent headers, just terminate and close
					if (Q_WebServer_State::isStreaming()
						&& !empty($parsed['_streamingSent'])) {
						self::writeAll($client, "0\r\n\r\n"); // chunked terminator
						if (is_resource($client)) @fclose($client);
						return;
					}
					// Forked child always closes — can't reuse connection across processes
					$parsed['headers']['_keepAlive'] = false;
					Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
					if (is_resource($client)) @fclose($client);
				};
				register_shutdown_function(function () use ($emit) {
					// Reached only when dispatchToQ() did NOT return normally --
					// the Platform's exception handler echoes and then exits.
					// dispatchToQ() captures into Q_WebServer_Capture's
					// buffer, which cannot be removed; end() collects it.
					$body = '';
					if (class_exists('Q_WebServer_Capture', false)
					and Q_WebServer_Capture::active()) {
						$body = Q_WebServer_Capture::end();
					} elseif (ob_get_level() > 0) {
						$body = (string) ob_get_contents();
					}
					while (@ob_end_clean()) { /* drop what we can */ }
					if ($body === '') {
						$body = Q_WebServer::$_capturedOutput;
					}
					Q_WebServer::$_capturedOutput = '';
					$status = Q_WebServer::responseCode();
					// Same recovery as dispatchToQ(): the Platform's exception
					// handler echoes the error page and then exits, so we never
					// reach the normal status resolution. The code lives on the
					// exception objects, not in http_response_code().
					if ((!$status or $status === 200)
					and class_exists('Q_Response', false)
					and method_exists('Q_Response', 'getErrors')) {
						try {
							foreach ((array) Q_Response::getErrors() as $err) {
								if (is_object($err) and !empty($err->httpResponseCode)) {
									$status = (int) $err->httpResponseCode;
									break;
								}
							}
						} catch (\Throwable $e) { /* non-fatal */ }
					}
					$err = error_get_last();
					if ($err && in_array($err['type'],
						array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
						$status = 500;
					}
					$emit(array(
						'status'  => $status ?: 200,
						'body'    => $body,
						'headers' => Q_WebServer::getResponseHeaders(),
					));
				});

				$response = self::dispatchToQ($parsed);
				$emit($response);
				exit(0);
			} else {
				// ── PARENT: close client socket (child owns it now), reap later ──
				if (is_resource($client)) @fclose($client);
				$key = (int) $client;
				if (isset(self::$clientWatchers[$key])) {
					Q_Evented::cancel(self::$clientWatchers[$key]);
				}
				unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
				self::$workerPids[$pid] = microtime(true);
				// Non-blocking reap — don't wait for child
				Q_WebServer_Fork::waitpid($pid, $st, 1);
				self::$lastStatus = 200;
				return false;
			}
		}

		// Fallback: use proc_open to run PHP in a subprocess (Windows,
		// or fork failed). Safe against exit()/die() — the subprocess
		// dies, not the server. Slower than fork (no shared classes)
		// but still provides isolation.
		return self::handlePhpSubprocess($client, $parsed, $scriptPath);
	}

	/**
	 * Execute a PHP script in a subprocess via proc_open.
	 * Used on Windows (no pcntl_fork) or as fallback when fork fails.
	 * The subprocess gets request data via stdin, returns response via stdout.
	 * @method handlePhpSubprocess
	 * @static
	 * @private
	 */
	private static function handlePhpSubprocess($client, $parsed, $scriptPath)
	{
		// Build a small inline PHP worker that:
		// 1. Reads request JSON from stdin
		// 2. Sets up superglobals
		// 3. Loads Q.php for autoloader/events
		// 4. Includes the target script
		// 5. Writes response JSON to stdout
		$qFile = dirname(__FILE__) . DS . 'Q.php';
		$workerCode = <<<'WORKER'
<?php
$json = '';
while (!feof(STDIN)) { $c = fread(STDIN, 65536); if ($c === false || $c === '') break; $json .= $c; }
$req = json_decode($json, true);
if (!$req) { echo json_encode(['status'=>500,'body'=>'Bad request','headers'=>[]]); exit; }
if (isset($req['qFile']) && file_exists($req['qFile'])) {
    require_once $req['qFile'];
    if (isset($req['projectRoot']) && method_exists('Q', 'init')) Q::init($req['projectRoot']);
}
$_SERVER['REQUEST_METHOD'] = $req['method'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $req['uri'] ?? '/';
$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
$_SERVER['SCRIPT_FILENAME'] = $req['scriptPath'] ?? '';
$_SERVER['SCRIPT_NAME'] = '/' . basename($req['scriptPath'] ?? 'index.php');
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['PATH_TRANSLATED'] = $req['scriptPath'] ?? '';
$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
$_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SERVER_NAME'] = $req['serverName'] ?? 'localhost';
$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/1.0';
$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
$_SERVER['REDIRECT_STATUS'] = 200;
$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';
$_SERVER['REMOTE_PORT'] = $req['remotePort'] ?? 0;
$_SERVER['REQUEST_TIME'] = time();
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$_SERVER['REQUEST_SCHEME'] = ($req['https'] ?? false) ? 'https' : 'http';
$_SERVER['HTTPS'] = ($req['https'] ?? false) ? 'on' : '';
foreach ($req['headers'] ?? [] as $k=>$v) $_SERVER['HTTP_'.strtoupper(str_replace('-','_',$k))] = $v;
if (isset($req['headers']['content-type'])) $_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
if (isset($req['headers']['content-length'])) $_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];
// Populate getallheaders() for PHP scripts
// This file is written to a temp directory and run from there, so __DIR__ is
// that temp directory and never the source tree. Paths come from the parent,
// which knows where it is, and the guards below mean a miss degrades rather
// than fatals.
$_srcDir = $req['serverDir'] ?? __DIR__;
if (!class_exists('Q_WebServer', false)) {
	$_ws = $_srcDir . '/WebServer.php';
	if (is_file($_ws)) require_once $_ws;
}
if (!class_exists('Q_WebServer_GetAllHeaders', false)) {
	$_gah = $_srcDir . '/WebServer/GetAllHeaders.php';
	if (is_file($_gah)) require_once $_gah;
}
if (class_exists('Q_WebServer_GetAllHeaders', false)) {
	Q_WebServer_GetAllHeaders::register();
	Q_WebServer_GetAllHeaders::set($req['headers'] ?? [], $req['rawHeaders'] ?? []);
}
// Parse cookies
$_COOKIE = [];
$ck = $req['headers']['cookie'] ?? '';
if ($ck) { foreach (explode(';',$ck) as $p) { $p=trim($p); if(!$p)continue; $e=strpos($p,'='); if($e===false)continue; $_COOKIE[urldecode(trim(substr($p,0,$e)))]=urldecode(trim(substr($p,$e+1))); } }
// Parse Basic auth
$auth = $req['headers']['authorization'] ?? '';
if (stripos($auth,'Basic ')===0) { $d=base64_decode(substr($auth,6)); if($d&&strpos($d,':')!==false) { [$u,$pw]=explode(':',$d,2); $_SERVER['PHP_AUTH_USER']=$u; $_SERVER['PHP_AUTH_PW']=$pw; $_SERVER['AUTH_TYPE']='Basic'; } }
$_GET = $_POST = $_REQUEST = $_FILES = [];
if (!empty($req['query'])) parse_str($req['query'], $_GET);
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$raw = $req['body'] ?? '';
if (strpos($ct,'application/x-www-form-urlencoded') !== false) parse_str($raw, $_POST);
elseif (strpos($ct,'application/json') !== false) $_POST = json_decode($raw, true) ?: [];
elseif (strpos($ct,'multipart/form-data') !== false && class_exists('Q_WebServer', false)) { $oct=$req['headers']['content-type']??''; Q_WebServer::parseMultipart($oct, $raw, $_POST, $_FILES); }
$_REQUEST = array_merge($_COOKIE, $_GET, $_POST);
if (class_exists('Q_Request',false)) Q_WebServer_State::setInput($raw);
ob_start(); $status = 200; $headers = [];
try {
    if (is_file($req['scriptPath'])) include $req['scriptPath']; else { $status = 404; echo 'Not Found'; }
    $headers = class_exists('Q_WebServer', false)
        ? Q_WebServer::getResponseHeaders() : [];
    $code = http_response_code();
    if (class_exists('Q_WebServer', false) && Q_WebServer::responseCode() !== 200) {
        $status = Q_WebServer::responseCode();
    }
    if ($code) $status = $code;
} catch (Throwable $e) {
    $status = 500; ob_clean(); $headers['Content-Type']='text/plain';
    fwrite(STDERR, 'uncaught ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");
    if (empty($req['debug'])) echo $e->getMessage();
    else for ($x = $e, $n = 0; $x && $n <= 10; $x = $x->getPrevious(), $n++)
        echo ($n ? "\nCaused by " : ''), get_class($x), ': ', $x->getMessage(), ' in ', $x->getFile(), ':', $x->getLine(), "\n", $x->getTraceAsString(), "\n";
}
$body = ob_get_clean();
echo json_encode(compact('status','body','headers'), JSON_UNESCAPED_SLASHES);
WORKER;

		// Write worker code to a temp file (reuse across requests)
		static $workerFile = null;
		if (!$workerFile || !file_exists($workerFile)) {
			$workerFile = tempnam(sys_get_temp_dir(), 'qbix_worker_');
			file_put_contents($workerFile, $workerCode);
			register_shutdown_function(function () use (&$workerFile) {
				@unlink($workerFile);
				Q_WebServer::cleanupUploadFiles();
			});
		}

		// Build request payload
		$host = $parsed['headers']['host'] ?? 'localhost';
		$payload = json_encode(array(
			'method'      => $parsed['method'],
			'uri'         => $parsed['uri'],
			'query'       => $parsed['query'],
			'headers'     => $parsed['headers'],
			'body'        => $parsed['body'] ?? '',
			'scriptPath'  => $scriptPath,
			'documentRoot'=> rtrim(self::$rootDir, DS),
			'serverName'  => explode(':', $host)[0],
			'serverPort'  => (string) self::$port,
			'remoteAddr'  => $parsed['_remoteAddr'] ?? '127.0.0.1',
			'remotePort'  => $parsed['_remotePort'] ?? 0,
			'https'       => !empty(self::$tlsSocket),
			'qFile'       => $qFile,
			'projectRoot' => dirname(rtrim(self::$rootDir, DS)),
			// Where this class lives, so the worker can load it. Inside a phar
			// this is a phar:// path, which require_once handles.
			'serverDir'   => __DIR__,
			// Whether an uncaught error shows where it happened (--debug).
			'debug'       => class_exists('Q_WebServer_ErrorReport') && Q_WebServer_ErrorReport::debug(),
		), JSON_UNESCAPED_SLASHES);

		// Launch subprocess
		$descriptors = array(
			0 => array('pipe', 'r'), // stdin
			1 => array('pipe', 'w'), // stdout
			2 => array('pipe', 'w'), // stderr
		);

		$phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
		$process = proc_open($phpBin . ' ' . escapeshellarg($workerFile), $descriptors, $pipes);

		if (!is_resource($process)) {
			// proc_open failed — last resort, run in-process
			$parsed['_scriptPath'] = $scriptPath;
			$response = self::dispatchToQ($parsed);
			Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
			self::$lastStatus = $response['status'] ?? 200;
			return false;
		}

		// Send request data to child's stdin. The worker reads to EOF and
		// json_decodes the lot, so a short write does not lose one field -- it
		// truncates the JSON, the decode fails, and the worker answers "Bad
		// request" with a 500 to a request that was perfectly good.
		self::writeAll($pipes[0], $payload);
		fclose($pipes[0]);

		// Read response from child's stdout
		$stdout = '';
		while (!feof($pipes[1])) {
			$chunk = fread($pipes[1], 65536);
			if ($chunk === false || $chunk === '') break;
			$stdout .= $chunk;
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		// Parse response
		$response = json_decode($stdout, true);
		if (!$response) {
			$response = array(
				'status' => 502,
				'body' => 'Worker subprocess failed',
				'headers' => array('Content-Type' => 'text/plain'),
			);
		}

		Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
		self::$lastStatus = $response['status'] ?? 200;
		$response = Q_WebServer_Cache::put($parsed, $response);
		return false;
	}

	/**
	 * Execute a PHP script via php-cgi binary for full header() compatibility.
	 * Used for legacy/third-party code (WordPress, Laravel, etc.) that calls
	 * header() and setcookie() directly. The php-cgi binary outputs real HTTP
	 * headers followed by the body — we parse and forward them.
	 *
	 * Slower than fork mode (no preload benefit) but 100% compatible.
	 *
	 * @method handlePhpCgi
	 * @static
	 * @private
	 */
	private static function handlePhpCgi($client, $parsed, $scriptPath, $cgiBinary)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostParts = explode(':', $host);
		$isHttps = !empty(self::$tlsSocket);
		$fwdProto = strtolower($parsed['headers']['x-forwarded-proto'] ?? '');
		if ($fwdProto === 'https') $isHttps = true;
		$cfVisitor = $parsed['headers']['cf-visitor'] ?? '';
		if (strpos($cfVisitor, '"https"') !== false) $isHttps = true;

		// Compute SCRIPT_NAME and PATH_INFO (frameworks need correct PATH_INFO)
		// Issue #14: realpath-normalise both sides to prevent segment loss.
		$requestPath = parse_url($parsed['uri'], PHP_URL_PATH) ?: '/';
		$docRoot = rtrim(realpath(self::$rootDir) ?: self::$rootDir, DS);
		$scriptReal = realpath($scriptPath) ?: $scriptPath;
		if (strncmp($scriptReal, $docRoot, strlen($docRoot)) === 0) {
			$scriptRel = '/' . ltrim(str_replace(DS, '/', substr($scriptReal, strlen($docRoot))), '/');
		} else {
			$scriptRel = $requestPath;
		}
		$pathInfo = '';
		if (strlen($requestPath) > strlen($scriptRel)) {
			$pathInfo = substr($requestPath, strlen($scriptRel));
		}

		// Build CGI environment variables — full set matching nginx fastcgi_params
		$env = array(
			'REDIRECT_STATUS'    => '200',
			'GATEWAY_INTERFACE'  => 'CGI/1.1',
			'SERVER_SOFTWARE'    => 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0'),
			'SERVER_PROTOCOL'    => 'HTTP/' . ($parsed['httpVersion'] ?? '1.1'),
			'SERVER_NAME'        => $hostParts[0],
			'SERVER_PORT'        => isset($hostParts[1]) ? $hostParts[1] : '80',
			'SERVER_ADDR'        => self::$host === '0.0.0.0' ? '127.0.0.1' : self::$host,
			'REQUEST_METHOD'     => $parsed['method'],
			'REQUEST_URI'        => $parsed['uri'],
			'QUERY_STRING'       => $parsed['query'],
			'SCRIPT_FILENAME'    => $scriptPath,
			'SCRIPT_NAME'        => $scriptRel,
			'PHP_SELF'           => $scriptRel . $pathInfo,
			'PATH_INFO'          => $pathInfo,
			'PATH_TRANSLATED'    => $pathInfo ? $docRoot . $pathInfo : '',
			'DOCUMENT_ROOT'      => $docRoot,
			'DOCUMENT_URI'       => $scriptRel,
			'REMOTE_ADDR'        => $parsed['_remoteAddr'] ?? '127.0.0.1',
			'REMOTE_PORT'        => (string) ($parsed['_remotePort'] ?? 0),
			'REQUEST_SCHEME'     => $isHttps ? 'https' : 'http',
			'HTTPS'              => $isHttps ? 'on' : '',
			'REQUEST_TIME'       => (string) time(),
			'REQUEST_TIME_FLOAT' => (string) microtime(true),
		);

		// Forward ALL request headers as HTTP_* env vars
		foreach ($parsed['headers'] as $k => $v) {
			$envKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
			$env[$envKey] = $v;
		}
		// Content-Type and Content-Length are special (no HTTP_ prefix per CGI spec)
		if (isset($parsed['headers']['content-type'])) {
			$env['CONTENT_TYPE'] = $parsed['headers']['content-type'];
		}
		if (isset($parsed['headers']['content-length'])) {
			$env['CONTENT_LENGTH'] = $parsed['headers']['content-length'];
		}
		// Basic auth
		$auth = $parsed['headers']['authorization'] ?? '';
		if (stripos($auth, 'Basic ') === 0) {
			$decoded = base64_decode(substr($auth, 6));
			if ($decoded && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$env['PHP_AUTH_USER'] = $user;
				$env['PHP_AUTH_PW'] = $pass;
				$env['AUTH_TYPE'] = 'Basic';
			}
		}
		// Inherit essential system env vars
		foreach (array('PATH', 'HOME', 'TEMP', 'TMP', 'TMPDIR', 'SYSTEMROOT') as $sysVar) {
			if (isset($_ENV[$sysVar])) $env[$sysVar] = $_ENV[$sysVar];
			elseif (($v = getenv($sysVar)) !== false) $env[$sysVar] = $v;
		}

		// Launch php-cgi
		$descriptors = array(
			0 => array('pipe', 'r'), // stdin (request body)
			1 => array('pipe', 'w'), // stdout (CGI response)
			2 => array('pipe', 'w'), // stderr (errors)
		);

		$cwd = dirname($scriptPath); // run in the script's directory
		$process = proc_open($cgiBinary, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			self::sendResponse($client, 502, self::renderErrorPage(502), 'text/html; charset=utf-8');
			return false;
		}

		// Non-blocking reads for timeout support
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		// Send request body to stdin. A short write here hands the CGI script a
		// truncated body while CONTENT_LENGTH still claims the full size, so
		// the script blocks waiting for bytes that were never sent.
		if (!empty($parsed['body'])) {
			self::writeAll($pipes[0], $parsed['body']);
		}
		fclose($pipes[0]);

		// Read CGI response with timeout
		static $timeout = null;
		if ($timeout === null) $timeout = Q_Config::get('Q', 'webserver', 'cgi', 'timeout', 30);
		$deadline = microtime(true) + $timeout;
		$stdout = '';
		$stderr = '';
		while (true) {
			$read = array($pipes[1], $pipes[2]);
			$write = $except = null;
			$remaining = max(0.1, $deadline - microtime(true));
			if ($remaining <= 0) break; // timeout
			$ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
			if ($ready === false) break;
			if ($ready === 0) continue;
			foreach ($read as $pipe) {
				$chunk = @fread($pipe, 65536);
				if ($chunk === false || $chunk === '') {
					if ($pipe === $pipes[1] && feof($pipes[1])) break 2;
					continue;
				}
				if ($pipe === $pipes[1]) $stdout .= $chunk;
				else $stderr .= $chunk;
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		// Log stderr if non-empty
		if ($stderr !== '') {
			Q_WebServer_Log::error("CGI stderr ($scriptPath): " . trim($stderr),
				'', $parsed['headers']['host'] ?? null);
		}

		// Handle timeout
		if (microtime(true) >= $deadline && $stdout === '') {
			self::sendResponse($client, 504, self::renderErrorPage(504), 'text/html; charset=utf-8');
			return false;
		}

		// Handle empty response
		if ($stdout === '') {
			$status = ($exitCode !== 0) ? 500 : 200;
			$response = array('status' => $status, 'body' => '', 'headers' => array());
			Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
			self::$lastStatus = $status;
			return false;
		}

		// Parse CGI output: headers separated by blank line from body
		$headerEnd = strpos($stdout, "\r\n\r\n");
		$sep = 4;
		if ($headerEnd === false) {
			$headerEnd = strpos($stdout, "\n\n");
			$sep = 2;
		}

		if ($headerEnd === false) {
			$body = $stdout;
			$headers = array();
			$status = 200;
			$extraHeaders = array();
		} else {
			$headerBlock = substr($stdout, 0, $headerEnd);
			$body = substr($stdout, $headerEnd + $sep);
			$headers = array();
			$extraHeaders = array(); // for multiple Set-Cookie headers
			$status = 200;

			foreach (explode("\n", $headerBlock) as $line) {
				$line = rtrim($line, "\r");
				if ($line === '') continue;

				// Status line: "Status: 404 Not Found"
				if (stripos($line, 'Status:') === 0) {
					$status = (int) trim(substr($line, 7));
					continue;
				}
				// Location header implies redirect status
				$colonPos = strpos($line, ':');
				if ($colonPos === false) continue;

				$name = trim(substr($line, 0, $colonPos));
				$value = trim(substr($line, $colonPos + 1));

				// Multiple Set-Cookie headers must all be forwarded
				if (strtolower($name) === 'set-cookie') {
					$extraHeaders[] = array($name, $value);
				} else {
					$headers[$name] = $value;
				}

				if (strtolower($name) === 'location' && $status === 200) {
					$status = 302; // implicit redirect
				}
			}
		}

		// Build and send response
		// We bypass processResponse for Set-Cookie to handle multiples
		$headers['Content-Length'] = strlen($body);
		$headers['Connection'] = 'close';

		static $reasons = array(
			200=>'OK', 201=>'Created', 204=>'No Content',
			301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
			400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
			404=>'Not Found', 405=>'Method Not Allowed',
			413=>'Payload Too Large', 500=>'Internal Server Error',
			502=>'Bad Gateway', 503=>'Service Unavailable', 504=>'Gateway Timeout',
		);
		$reason = $reasons[$status] ?? 'OK';
		$out = "HTTP/1.1 $status $reason\r\n";
		$out .= self::headerLines($headers);
		// Append all Set-Cookie headers (can't use associative array).
		// A cookie value is as capable of carrying CRLF as any other header,
		// and more likely to hold something a visitor chose, so the pairs go
		// through the same filter rather than around it.
		foreach ($extraHeaders as $pair) {
			if (preg_match('/[\r\n]/', (string) $pair[0] . (string) $pair[1])) continue;
			$out .= $pair[0] . ': ' . $pair[1] . "\r\n";
		}
		self::writeAll($client, $out . "\r\n" . $body);

		self::$lastStatus = $status;
		return false;
	}

	private static function serveStaticFile($client, $fsPath, $method, $reqHeaders, $keepAlive = false)
	{
		$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
		if (!in_array($ext, self::$allowedExtensions)) {
			self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
			return;
		}
		// A link inside the root may still point outside it.
		if (!self::insideRoot($fsPath)) {
			self::sendResponse($client, 403, self::renderErrorPage(403, $fsPath), 'text/html; charset=utf-8');
			return;
		}

		$connKey = $keepAlive ? 'ka' : 'cl';
		$now = microtime(true);

		// The cache key MUST include the negotiated content-encoding.
		// Keyed on $fsPath alone, whichever variant was stored first was then
		// served to everyone: a client that sent no Accept-Encoding poisoned
		// the entry so later gzip-capable clients got the uncompressed body --
		// and in the reverse order a client that cannot decompress received
		// gzipped bytes, which it has no way to read.
		// Read as findPreCompressed() and Precompress::serve() read it, so the
		// key names the coding actually sent; q=0 refuses a coding.
		$aeRaw = (string) ($reqHeaders['accept-encoding'] ?? '');
		$encKey = Q_WebServer_Headers::acceptsCoding($aeRaw, 'br') ? 'br'
			: (Q_WebServer_Headers::acceptsCoding($aeRaw, 'gzip') ? 'gzip' : 'id');
		$cacheKey = $fsPath . '|' . $encKey;

		// The domain's HSTS header is part of the stored response, so it is
		// part of the key too: two domains can share a root, and the same
		// file goes out over HTTP (never with it) and HTTPS.
		$hsts = null;
		if (class_exists('Q_WebServer_Domains', false)) {
			$staticMeta = is_resource($client) ? @stream_get_meta_data($client) : array();
			$hsts = Q_WebServer_Domains::hstsHeader(
				Q_WebServer_Domains::normalize($reqHeaders['host'] ?? ''), !empty($staticMeta['crypto'])
			);
			if ($hsts !== null) $cacheKey .= '|' . $hsts;
		}

		// ── Try response cache ──
		if (isset(self::$fileCache[$cacheKey])) {
			$cached = &self::$fileCache[$cacheKey];
			// Revalidate mtime periodically
			if (($now - $cached['checked']) >= self::$fileCacheCheckInterval) {
				clearstatcache(true, $fsPath);
				if (filemtime($fsPath) !== $cached['mtime']) {
					self::$fileCacheSize -= $cached['bodyLen'] * 2;
					unset(self::$fileCache[$cacheKey]);
				} else {
					$cached['checked'] = $now;
				}
			}
		}

		if (isset(self::$fileCache[$cacheKey])) {
			// Most recently used goes last, so eviction, which takes from the
			// front, drops the file asked for longest ago.
			if (array_key_last(self::$fileCache) !== $cacheKey) {
				$entry = self::$fileCache[$cacheKey];
				unset(self::$fileCache[$cacheKey]);
				self::$fileCache[$cacheKey] = $entry;
				unset($entry);
			}
			$cached = &self::$fileCache[$cacheKey];
			$etag = $cached['etag'];

			// 304 against cached etag
			if (isset($reqHeaders['if-none-match']) && trim($reqHeaders['if-none-match']) === $etag) {
				self::sendNotModified($client, $etag, $cached['mtime'], $keepAlive);
				return;
			}
			if (isset($reqHeaders['if-modified-since'])) {
				$since = strtotime($reqHeaders['if-modified-since']);
				if ($since !== false && $cached['mtime'] <= $since) {
					self::sendNotModified($client, $etag, $cached['mtime'], $keepAlive);
					return;
				}
			}

			// Serve from cache — single fwrite
			self::$lastStatus = 200;
			self::$lastBytes = $cached['bodyLen'];
			if ($method === 'HEAD') {
				self::writeAll($client, $cached['head'][$connKey]);
			} else {
				self::writeAll($client, $cached['full'][$connKey]);
			}
			return;
		}

		// ── Cache miss — build from disk ──
		clearstatcache(true, $fsPath);
		$mtime = filemtime($fsPath);
		$size = filesize($fsPath);
		$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

		// 304 Not Modified
		if (isset($reqHeaders['if-none-match']) && trim($reqHeaders['if-none-match']) === $etag) {
			self::sendNotModified($client, $etag, $mtime, $keepAlive);
			return;
		}
		if (isset($reqHeaders['if-modified-since'])) {
			$since = strtotime($reqHeaders['if-modified-since']);
			if ($since !== false && $mtime <= $since) {
				self::sendNotModified($client, $etag, $mtime, $keepAlive);
				return;
			}
		}

		$contentType = self::mimeType($ext);
		$baseHeaders = "Content-Type: $contentType\r\n"
			. "ETag: $etag\r\n"
			. "Last-Modified: " . gmdate('D, d M Y H:i:s', $mtime) . " GMT\r\n"
			. "Cache-Control: " . self::staticCacheControl($fsPath) . "\r\n";
		if ($hsts !== null) $baseHeaders .= "Strict-Transport-Security: $hsts\r\n";

		// ── Large file fork ──
		// Files over 1MB are served by a forked child process so the parent's
		// event loop isn't blocked by the writeAll() loop. The child inherits
		// the client socket, writes the full response, and exits.
		// Threshold: 1MB (below this, inline write is faster than fork overhead).
		// Never over TLS. A forked child gets a copy of the OpenSSL state,
		// and record sequence numbers are part of that state: once two
		// processes are emitting records for one connection the numbering no
		// longer agrees with what the client expects, and the client drops
		// the connection reporting a MAC failure --
		//
		//     SSL_ERROR_BAD_MAC_READ
		//
		// -- for the one asset that happened to be over the threshold, while
		// every smaller asset on the same page loaded normally. Serving it
		// inline costs the event loop one writeAll(); serving it from a child
		// costs the whole connection.
		$meta = is_resource($client) ? @stream_get_meta_data($client) : array();
		$isTls = !empty($meta['crypto']);

		if (!$isTls && $size > 1048576 && $method !== 'HEAD' && Q_WebServer_Fork::available()) {
			$connHeader = 'close'; // forked child always closes
			$out = "HTTP/1.1 200 OK\r\n" . $baseHeaders
				. "Content-Length: $size\r\n"
				. "Connection: close\r\n\r\n";
			$pid = Q_WebServer_Fork::fork();
			if ($pid === 0) {
				// Child: write headers + stream file in chunks
				stream_set_blocking($client, true);
				$ok = self::writeAll($client, $out);
				if ($ok !== false) {
					$fp = fopen($fsPath, 'rb');
					if ($fp) {
						while (!feof($fp)) {
							$chunk = fread($fp, 65536);
							if ($chunk === false || $chunk === '') break;
							if (!self::writeAll($client, $chunk)) break;
						}
						fclose($fp);
					}
				}
				if (is_resource($client)) @fclose($client);
				exit(0);
			}
			if ($pid > 0) {
				// Parent: close our copy of the socket, back to event loop
				if (is_resource($client)) @fclose($client);
				self::$lastStatus = 200;
				self::$lastBytes = $size;
				return;
			}
			// Fork failed — fall through to inline write
		}

		// Companion .headers file
		$hf = $fsPath . '.headers';
		if (file_exists($hf)) {
			foreach (file($hf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
				if ($line[0] === '#' || strpos($line, ':') === false) continue;
				$baseHeaders .= trim($line) . "\r\n";
			}
		}

		$connHeader = $keepAlive ? 'keep-alive' : 'close';

		// Pre-compressed siblings — not cached (different per Accept-Encoding)
		$preComp = Q_WebServer_Headers::findPreCompressed($fsPath, $reqHeaders);
		if ($preComp) {
			$out = "HTTP/1.1 200 OK\r\n" . $baseHeaders
				. "Content-Encoding: " . $preComp['encoding'] . "\r\n"
				. "Content-Length: " . $preComp['size'] . "\r\n"
				. "Vary: Accept-Encoding\r\n"
				. "Connection: $connHeader\r\n\r\n";
			self::$lastStatus = 200;
			self::$lastBytes = $preComp['size'];
			self::writeAll($client, $method === 'HEAD' ? $out : $out . file_get_contents($preComp['path']));
			return;
		}

		// On-the-fly gzip — use Precompress if enabled (caches to disk)
		if ($size < 5242880) {
			$gzHeaders = array();
			if (Q_WebServer_Headers::shouldCompress($contentType, $size, $reqHeaders)) {
				// Issue #10: Precompress::serve() was wired into buildFileResponse()
				// but static GETs go through serveStaticFile(). Wire it here too.
				if (!class_exists('Q_WebServer_Precompress', false)) {
					$_pcf = __DIR__ . '/WebServer/Precompress.php';
					if (is_file($_pcf)) require_once $_pcf;
				}
				$precompressEnabled = class_exists('Q_WebServer_Precompress', false)
					&& Q_Config::get('Q', 'webserver', 'precompress', 'enabled', false);
				if ($precompressEnabled) {
					$body = Q_WebServer_Precompress::serve(
						$fsPath, $contentType, $mtime, $size, $reqHeaders, $gzHeaders
					);
				} else {
					$body = file_get_contents($fsPath);
					$body = Q_WebServer_Headers::maybeCompress($body, $contentType, $reqHeaders, $gzHeaders);
				}
				$out = "HTTP/1.1 200 OK\r\n" . $baseHeaders;
				$out .= self::headerLines($gzHeaders);
				$out .= "Content-Length: " . strlen($body) . "\r\n"
					. "Connection: $connHeader\r\n\r\n";
				self::$lastStatus = 200;
				self::$lastBytes = strlen($body);
				self::writeAll($client, $method === 'HEAD' ? $out : $out . $body);
				return;
			}
		}

		// ── Uncompressed — serve and cache ──
		$body = file_get_contents($fsPath);
		$kaHead = "HTTP/1.1 200 OK\r\n" . $baseHeaders
			. "Content-Length: $size\r\nConnection: keep-alive\r\n\r\n";
		$clHead = "HTTP/1.1 200 OK\r\n" . $baseHeaders
			. "Content-Length: $size\r\nConnection: close\r\n\r\n";

		self::$lastStatus = 200;
		self::$lastBytes = $size;
		$headStr = $keepAlive ? $kaHead : $clHead;
		self::writeAll($client, $method === 'HEAD' ? $headStr : $headStr . $body);

		// Cache if small enough, making room by evicting the least recently
		// used. The test used to be "fits in what is left", which refused every
		// file once the cache was full, so the eviction below never ran and
		// whichever files were asked for first stayed in memory for good.
		if ($size <= self::$fileCacheMaxFile
			&& $size * 2 <= self::$fileCacheMaxSize
		) {
			self::$fileCache[$cacheKey] = array(
				'mtime'   => $mtime,
				'bodyLen' => $size,
				'etag'    => $etag,
				'checked' => $now,
				'head'    => array('ka' => $kaHead, 'cl' => $clHead),
				'full'    => array('ka' => $kaHead . $body, 'cl' => $clHead . $body),
			);
			self::$fileCacheSize += $size * 2;

			// Evict least recently used until under the limit
			while (self::$fileCacheSize > self::$fileCacheMaxSize && self::$fileCache) {
				$evict = array_key_first(self::$fileCache);
				self::$fileCacheSize -= self::$fileCache[$evict]['bodyLen'] * 2;
				unset(self::$fileCache[$evict]);
			}
		}
	}

	// ── Privacy / access control ─────────────────────────

	/**
	 * Check if a URL path is blocked entirely (403 Forbidden).
	 *
	 * These paths cannot be accessed by any URL. They contain
	 * server-side code, config, and internal data.
	 *
	 * Blocked: /config/, /classes/, /handlers/, /scripts/
	 * Also: dotfiles/dotdirs (except /.well-known/)
	 * Also: paths in Q.web.blocked.paths config
	 *
	 * For true access control on files, use X-Accel-Redirect
	 * (PHP checks permissions, server does file I/O).
	 *
	 * @method isBlocked
	 * @static
	 * @param {string} $urlPath
	 * @return {boolean}
	 */
	static function isBlocked($urlPath)
	{
		// Core blocked directories (server internals)
		//
		// /settings/ carries an application's configuration, including the
		// credentials it connects with. Not every layout puts it out of reach:
		// where the document root is the project root -- which is how an
		// Exponential installation is served -- it is simply a directory below
		// the root like any other.
		//
		// /var/ is deliberately absent. An eZ installation serves its
		// stylesheets, scripts and images from var/<site>/cache and
		// var/<site>/storage, so blocking it would take the site down. Logs
		// under it are covered instead by 'log' no longer being a served
		// extension.
		$blocked = array('/config/', '/classes/', '/handlers/', '/scripts/',
			'/settings/');
		foreach ($blocked as $prefix) {
			if (strpos($urlPath, $prefix) === 0) return true;
		}

		// Files that describe the installation rather than serve it. json has
		// to stay a served extension -- manifests and APIs use it -- so these
		// are named rather than covered by type.
		$blockedFiles = array('/composer.json', '/composer.lock',
			'/package-lock.json', '/yarn.lock', '/Dockerfile', '/Makefile');
		foreach ($blockedFiles as $file) {
			if (strcasecmp($urlPath, $file) === 0) return true;
		}

		// Dotfiles/dotdirs (except /.well-known/)
		if (preg_match('#/\.(?!well-known)#', $urlPath)) return true;

		// Config-based blocked paths
		static $blockedPaths = null;
		if ($blockedPaths === null) $blockedPaths = Q_Config::get('Q', 'web', 'blocked', 'paths', array());
		foreach ($blockedPaths as $pp => $v) {
			if ($v && strpos($urlPath, '/' . ltrim($pp, '/')) === 0) return true;
		}

		return false;
	}

	/**
	 * Check if a URL path allows directory listing.
	 *
	 * Directory listings are OFF by default (more secure).
	 * Only paths matching regexes in
	 * Q.web.indexed.paths get listings. Default: /img/.
	 *
	 * Config:
	 *   "Q": { "web": { "indexed": { "paths": {
	 *     "#^/img/#": true,
	 *     "#^/downloads/#": true
	 *   }}}}
	 *
	 * For actual access control, use X-Accel-Redirect.
	 *
	 * @method isIndexed
	 * @static
	 * @param {string} $urlPath
	 * @return {boolean}
	 */
	static function isIndexed($urlPath)
	{
		static $patterns = null;
		if ($patterns === null) {
			$patterns = Q_Config::get('Q', 'web', 'indexed', 'paths', array(
				'/img/' => true
			));
		}
		foreach ($patterns as $regex => $enabled) {
			if (preg_match(self::ensureRegex($regex), $urlPath)) return (bool) $enabled;
		}
		return false;
	}

	// ── Directory listing ────────────────────────────────

	/**
	 * Render a responsive directory listing with media previews.
	 * Only called for paths that pass isIndexed().
	 * Dotfiles are always hidden from listings.
	 *
	 * @method renderDirectoryListing
	 * @static
	 * @param {string} $dir Filesystem path
	 * @param {string} $urlPath URL path
	 * @return {string} HTML
	 */
	static function renderDirectoryListing($dir, $urlPath)
	{
		$maxImages = (int) Q_Config::get('Q', 'webserver', 'listing', 'images', 'max', 100);
		$items = scandir($dir);
		$dirs = array();
		$files = array();
		$images = array();

		$imageExts = array('png','jpg','jpeg','gif','webp','svg','bmp');
		$videoExts = array('mp4','webm','ogg');
		$audioExts = array('mp3','wav','ogg');

		foreach ($items as $name) {
			if ($name === '.' || $name === '..') continue;
			if ($name[0] === '.') continue;

			$full = $dir . DS . $name;
			$href = htmlspecialchars($urlPath . $name, ENT_QUOTES);
			$safe = htmlspecialchars($name, ENT_QUOTES);

			if (is_dir($full)) {
				$dirs[] = array('name' => $safe, 'href' => $href . '/');
				continue;
			}

			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!in_array($ext, self::$allowedExtensions)) continue;

			$size = filesize($full);
			$sizeStr = $size < 1024 ? "{$size} B"
				: ($size < 1048576 ? round($size/1024,1).' KB'
				: round($size/1048576,1).' MB');

			$isImage = in_array($ext, $imageExts);
			$isVideo = in_array($ext, $videoExts);
			$isAudio = in_array($ext, $audioExts);

			$fileInfo = array('name' => $safe, 'href' => $href, 'size' => $sizeStr, 'ext' => $ext,
				'isImage' => $isImage, 'isVideo' => $isVideo, 'isAudio' => $isAudio);
			$files[] = $fileInfo;

			if ($isImage && count($images) < $maxImages) {
				$dim = @getimagesize($full);
				$fileInfo['width'] = $dim ? $dim[0] : 0;
				$fileInfo['height'] = $dim ? $dim[1] : 0;
				$images[] = $fileInfo;
			}
		}

		// Check for user override: listing.php or listing.html
		// in the app root, similar to errors/ override
		$_path = $urlPath;
		$_dirs = $dirs;
		$_files = $files;
		$_images = $images;
		$_dir = $dir;

		foreach (Q_WebServer::paths() as $base) {
			$listingPhp = $base . DS . 'listing.php';
			if (file_exists($listingPhp)) {
				ob_start();
				include $listingPhp;
				return ob_get_clean();
			}
			$listingHtml = $base . DS . 'listing.html';
			if (file_exists($listingHtml)) {
				return file_get_contents($listingHtml);
			}
		}

		// Built-in listing
		$safePath = htmlspecialchars($urlPath, ENT_QUOTES);
		$isRoot = ($urlPath === '/');

		// Build file list HTML
		$listHtml = '';
		if (!$isRoot) {
			$listHtml .= '<a href="../" class="item dir up"><span class="icon">⬆</span><span class="name">Parent Directory</span></a>';
		}
		foreach ($dirs as $d) {
			$listHtml .= '<a href="' . $d['href'] . '" class="item dir"><span class="icon">📁</span><span class="name">' . $d['name'] . '/</span></a>';
		}
		foreach ($files as $f) {
			$icon = in_array($f['ext'], $imageExts) ? '🖼' : (in_array($f['ext'], $videoExts) ? '🎬' : (in_array($f['ext'], $audioExts) ? '🎵' : '📄'));
			$listHtml .= '<a href="' . $f['href'] . '" class="item file"><span class="icon">' . $icon . '</span><span class="name">' . $f['name'] . '</span><span class="size">' . $f['size'] . '</span></a>';
		}

		// Build image grid data as JSON for JS
		$imagesJson = json_encode($images, JSON_UNESCAPED_SLASHES);

		// The page is a design on disk -- designs/default/listing/ (page.html,
		// style.css, script.js), or the same files in the configuration
		// directory's designs/ -- byte for byte what this method used to
		// return from a heredoc here. A listing.php or listing.html in the
		// application's paths, above, still takes precedence.
		$page = Q_WebServer_Design::render('listing', compact('safePath', 'listHtml', 'imagesJson'));
		return $page !== null ? $page
			: '<!DOCTYPE html><html><body><p>The listing design is missing (designs/default/listing).</p></body></html>';
	}

	// ── MIME types ────────────────────────────────────────

	static function mimeType($ext)
	{
		static $types = array(
			'html'=>'text/html; charset=utf-8', 'htm'=>'text/html; charset=utf-8',
			'css'=>'text/css; charset=utf-8', 'js'=>'application/javascript; charset=utf-8',
			'mjs'=>'application/javascript; charset=utf-8', 'json'=>'application/json; charset=utf-8',
			'xml'=>'application/xml', 'txt'=>'text/plain; charset=utf-8',
			'md'=>'text/plain; charset=utf-8', 'csv'=>'text/csv; charset=utf-8',
			'yaml'=>'text/yaml', 'yml'=>'text/yaml', 'log'=>'text/plain; charset=utf-8',
			'map'=>'application/json',
			'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
			'gif'=>'image/gif', 'webp'=>'image/webp', 'svg'=>'image/svg+xml',
			'bmp'=>'image/bmp', 'ico'=>'image/x-icon', 'avif'=>'image/avif',
			'woff'=>'font/woff', 'woff2'=>'font/woff2',
			'ttf'=>'font/ttf', 'otf'=>'font/otf',
			'mp3'=>'audio/mpeg', 'wav'=>'audio/wav', 'ogg'=>'audio/ogg',
			'mp4'=>'video/mp4', 'webm'=>'video/webm',
			'pdf'=>'application/pdf', 'zip'=>'application/zip',
			'wasm'=>'application/wasm',
		);
		return $types[$ext] ?? 'application/octet-stream';
	}

	// ── Q_Dispatcher bridge ──────────────────────────────

	static function dispatchToQ($parsed)
	{
		$saved = array($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE);

		// Issue #16: clear accumulated response state from the previous request.
		// In in-process mode (no workers), Q_Response statics like $scripts,
		// $stylesheets, $cookies etc. accumulate across requests. In worker/
		// octane mode, the snapshot resets statics, but classes loaded lazily
		// after the snapshot was taken use getDefaultValue() which may not
		// reflect the correct preload state. Clearing here ensures each
		// request starts clean regardless of execution mode.
		self::clearResponseState();
		if (class_exists('Q_WebServer_State', false)) {
			Q_WebServer_State::clear();
		}
		if (class_exists('Q_Response', false)) {
			// Standalone shim has clear(); Platform's Q_Response may not,
			// but its statics are reset by the snapshot or by Q_Dispatcher.
			if (method_exists('Q_Response', 'clear')) {
				Q_WebServer_State::clear();
			}
		}
		$scriptPath = $parsed['_scriptPath'] ?? self::$rootDir . 'index.php';
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostParts = explode(':', $host);

		// ── Standard CGI variables ──────────────────────
		$_SERVER['REQUEST_METHOD']    = $parsed['method'];
		$_SERVER['REQUEST_URI']       = $parsed['uri'];
		$_SERVER['QUERY_STRING']      = $parsed['query'];
		// Compute SCRIPT_NAME / PATH_INFO relative to docroot. Frameworks (Qbix
		// included) route off PATH_INFO for action.php/{route}-style URLs and off
		// a correct SCRIPT_NAME when the app is served under a subpath — hardcoding
		// PATH_INFO to '' breaks both.
		//
		// Issue #14: $scriptPath may be realpath()-resolved (symlinks expanded)
		// while self::$rootDir is not, causing substr() to eat a segment.
		// Normalise both sides via realpath() before computing the relative path.
		$docRoot     = rtrim(realpath(self::$rootDir) ?: self::$rootDir, DS);
		$scriptReal  = realpath($scriptPath) ?: $scriptPath;
		$requestPath = parse_url($parsed['uri'], PHP_URL_PATH) ?: '/';
		if (strncmp($scriptReal, $docRoot, strlen($docRoot)) === 0) {
			$scriptRel = '/' . ltrim(str_replace(DS, '/', substr($scriptReal, strlen($docRoot))), '/');
		} else {
			// Fallback: derive from REQUEST_URI (strip query string)
			$scriptRel = $requestPath;
		}
		$pathInfo    = '';
		if (isset($parsed['_pathInfo'])) {
			// Precomputed by splitPathInfo() (BUG #37) — authoritative.
			$pathInfo = $parsed['_pathInfo'];
		} else if (strncmp($requestPath, $scriptRel, strlen($scriptRel)) === 0
			&& strlen($requestPath) > strlen($scriptRel)) {
			$pathInfo = substr($requestPath, strlen($scriptRel));
		}
		$_SERVER['SCRIPT_NAME']       = $scriptRel;
		$_SERVER['SCRIPT_FILENAME']   = $scriptPath;
		$_SERVER['PHP_SELF']          = $scriptRel . $pathInfo; // WordPress uses this
		$_SERVER['PATH_TRANSLATED']   = $pathInfo ? $docRoot . $pathInfo : $scriptPath;
		$_SERVER['PATH_INFO']         = $pathInfo;
		$_SERVER['DOCUMENT_ROOT']     = $docRoot;
		$_SERVER['DOCUMENT_URI']      = $scriptRel;
		$_SERVER['SERVER_NAME']       = $hostParts[0];
		// Issue #14: When the Host header has no port, use the scheme default
		// (80 for HTTP, 443 for HTTPS), not the listen port. Behind a proxy or
		// container-published port, the listen port is internal and wrong.
		if (isset($hostParts[1])) {
			$_SERVER['SERVER_PORT'] = $hostParts[1];
		} else {
			$scheme = $parsed['_scheme'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
			$_SERVER['SERVER_PORT'] = ($scheme === 'https') ? '443' : '80';
		}
		$_SERVER['SERVER_ADDR']       = self::$host === '0.0.0.0' ? '127.0.0.1' : self::$host;
		$_SERVER['SERVER_PROTOCOL']   = 'HTTP/' . ($parsed['httpVersion'] ?? '1.1');
		$_SERVER['SERVER_SOFTWARE']   = 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0');
		$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
		$_SERVER['REDIRECT_STATUS']   = 200;
		$_SERVER['REMOTE_ADDR']       = $parsed['_remoteAddr'] ?? '127.0.0.1';
		$_SERVER['REMOTE_PORT']       = $parsed['_remotePort'] ?? 0;
		$_SERVER['REQUEST_TIME']      = time();
		$_SERVER['REQUEST_TIME_FLOAT']= microtime(true);

		// ── HTTPS detection (direct TLS or proxy header) ──
		$isHttps = !empty(self::$tlsSocket);
		$fwdProto = $parsed['headers']['x-forwarded-proto'] ?? '';
		if (strtolower($fwdProto) === 'https') $isHttps = true;
		// CloudFront
		$cfProto = $parsed['headers']['cloudfront-forwarded-proto'] ?? '';
		if (strtolower($cfProto) === 'https') $isHttps = true;
		// Cloudflare
		$cfVisitor = $parsed['headers']['cf-visitor'] ?? '';
		if (strpos($cfVisitor, '"https"') !== false) $isHttps = true;
		$_SERVER['REQUEST_SCHEME']    = $isHttps ? 'https' : 'http';
		$_SERVER['HTTPS']             = $isHttps ? 'on' : '';

		// ── Request headers → HTTP_* ────────────────────
		// All request headers become HTTP_HEADERNAME (uppercase, hyphens→underscores)
		foreach ($parsed['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		// Content-Type and Content-Length are special (no HTTP_ prefix per CGI spec)
		if (isset($parsed['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $parsed['headers']['content-type'];
		if (isset($parsed['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $parsed['headers']['content-length'];

		// ── Basic auth parsing ──────────────────────────
		$auth = $parsed['headers']['authorization'] ?? '';
		if (stripos($auth, 'Basic ') === 0) {
			$decoded = base64_decode(substr($auth, 6));
			if ($decoded && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$_SERVER['PHP_AUTH_USER'] = $user;
				$_SERVER['PHP_AUTH_PW'] = $pass;
				$_SERVER['AUTH_TYPE'] = 'Basic';
			}
		} elseif (stripos($auth, 'Bearer ') === 0) {
			$_SERVER['HTTP_AUTHORIZATION'] = $auth; // already set by loop
			$_SERVER['AUTH_TYPE'] = 'Bearer';
		}

		// ── $_COOKIE ────────────────────────────────────
		$_COOKIE = array();
		$cookieHeader = $parsed['headers']['cookie'] ?? '';
		if ($cookieHeader) {
			$pairs = explode(';', $cookieHeader);
			foreach ($pairs as $pair) {
				$pair = trim($pair);
				if ($pair === '') continue;
				$eqPos = strpos($pair, '=');
				if ($eqPos === false) continue;
				$name = urldecode(trim(substr($pair, 0, $eqPos)));
				$value = urldecode(trim(substr($pair, $eqPos + 1)));
				$_COOKIE[$name] = $value;
			}
		}

		// ── Framework compatibility init (before body parsing) ──
		$compatEnabled = class_exists('Q_WebServer_Compat', false)
			&& !Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false);
		if ($compatEnabled) {
			Q_WebServer_Compat::setRequestHeaders($parsed['headers'] ?? array());
			Q_WebServer_Compat::init();
			// URL rewrite: route non-file requests to front controller
			$rewritten = Q_WebServer_Compat::rewriteUrl(
				$parsed['path'], self::$rootDir
			);
			if ($rewritten) {
				$scriptPath = $rewritten;
			}
		}

		// ── $_GET, $_POST, $_FILES, $_REQUEST ───────────
		$_GET = $_POST = $_REQUEST = $_FILES = array();
		if ($parsed['query']) parse_str($parsed['query'], $_GET);
		$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
		$rawBody = $parsed['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($rawBody, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($rawBody, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			$origCt = $parsed['headers']['content-type'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
			if ($compatEnabled) {
				Q_WebServer_Compat::parseMultipart($rawBody, $origCt);
			} else {
				self::parseMultipart($origCt, $rawBody, $_POST, $_FILES);
			}
		}
		$_REQUEST = array_merge($_COOKIE, $_GET, $_POST); // PHP default order

		// Make raw body available via Q_Request::input() and php://input
		Q_WebServer_State::setInput($rawBody);
		if (class_exists('Q_Request', false)) {
			if (property_exists('Q_Request', 'input')) Q_Request::$input = $rawBody;
		}

		// Clear any stale headers and output from previous in-process requests,
		// then start fresh output buffering. This prevents "headers already sent"
		// errors when scripts call header() after prior output leaked through.
		self::dropOutputBuffers();
		@header_remove();
		@http_response_code(200);
		Q_WebServer::clearResponseState();
		if (class_exists('Q_Response', false)) Q_WebServer_State::clear();

		// Capture in a NON-REMOVABLE buffer for Platform mode.
		//
		// Q_Dispatcher::dispatch() unwinds through Q_OutputBuffer::endFlush(),
		// which calls @ob_end_flush() down to and including our level. A normal
		// buffer is destroyed by that: its contents go to the process's STDOUT
		// and there is nothing left to send. The visible symptom was every
		// application error under --app arriving as "200 with an empty body"
		// while the rendered error page appeared in the server's log.
		//
		// Passing flags=0 (not PHP_OUTPUT_HANDLER_REMOVABLE) makes
		// ob_end_flush()/ob_end_clean() fail on this buffer instead, so it
		// survives however the Platform chooses to unwind. We read it with
		// ob_get_contents(), which works on a protected buffer.
		//
		// For standalone mode (no Q_Dispatcher), use a FLUSHABLE buffer
		// so SSE/streaming scripts can call flush() and have output reach
		// the client incrementally.
		$isStandalone = !class_exists('Q_Dispatcher', false);
		$_streamingClient = $parsed['_client'] ?? null; // set by handlePhp fork child
		$_streamingHeadersSent = false;

		if ($isStandalone && $_streamingClient) {
			// Standalone fork child: use a buffer that can detect streaming.
			// When the script calls flush(), PHP calls ob_flush() which triggers
			// the callback with PHP_OUTPUT_HANDLER_FLUSH flag.
			// With chunk_size=4096, output accumulates until flush() is called.
			ob_start(function ($chunk, $phase) use (
				$_streamingClient, &$_streamingHeadersSent, $parsed
			) {
				// PHP_OUTPUT_HANDLER_FLUSH = 4, PHP_OUTPUT_HANDLER_FINAL = 8
				$isFlushing = ($phase & PHP_OUTPUT_HANDLER_FLUSH)
					|| ($phase & PHP_OUTPUT_HANDLER_FINAL);

				if (!Q_WebServer_State::isStreaming()) {
					// Not streaming — return false to leave buffer unmodified.
					// Returning the $chunk would REPLACE the buffer on every
					// 4096-byte trigger, losing all previous output.
					return false;
				}

				// Streaming mode active
				if (!$_streamingHeadersSent && strlen($chunk) > 0) {
					$_streamingHeadersSent = true;
					$parsed['_streamingSent'] = true;
					$status = Q_WebServer::responseCode() ?: 200;
					$hdrs = Q_WebServer::getResponseHeaders();
					$hdrs['Transfer-Encoding'] = 'chunked';
					if (!isset($hdrs['Cache-Control'])) $hdrs['Cache-Control'] = 'no-cache';
					$hdrs['Connection'] = 'keep-alive';
					if (!isset($hdrs['X-Accel-Buffering'])) $hdrs['X-Accel-Buffering'] = 'no';
					foreach (Q_WebServer_State::cookieHeaders() as $ch) {
						$hdrs['Set-Cookie'] = $ch;
					}
					$out = "HTTP/1.1 $status " . Q_WebServer::statusText($status) . "\r\n";
					$out .= Q_WebServer::headerLines($hdrs);
					$out .= "\r\n";
					Q_WebServer::writeAll($_streamingClient, $out);
				}
				// Chunked framing states a length and then supplies exactly
				// that many bytes. A short write leaves the client reading the
				// next chunk's header as body, and nothing downstream can
				// resynchronise, so these writes have to complete or fail
				// loudly rather than return a count nobody reads.
				if (strlen($chunk) > 0) {
					Q_WebServer::writeAll($_streamingClient,
						dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
				}
				if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
					Q_WebServer::writeAll($_streamingClient, "0\r\n\r\n");
				}
				return ''; // consume
			}, 0, PHP_OUTPUT_HANDLER_FLUSHABLE | PHP_OUTPUT_HANDLER_CLEANABLE
				| PHP_OUTPUT_HANDLER_REMOVABLE);
		} else {
			// Platform mode or no client socket: the process's one capture
			// buffer, reused per request. Opening a non-removable buffer here
			// each time left one behind per request, with the whole response
			// in it, for as long as the process lived -- see Q_WebServer_Capture.
			if (!class_exists('Q_WebServer_Capture', false)) {
				require_once __DIR__ . '/WebServer/Capture.php';
			}
			Q_WebServer_Capture::begin();
			$useCapture = true;
		}

		$status = 200;
		$headers = array();
		try {
			if (class_exists('Q_Dispatcher', false)) {
				// Full Qbix Platform mode
				Q_Dispatcher::dispatch();
			} else {
				// Standalone mode — execute PHP script directly
				$scriptPath = $parsed['_scriptPath'] ?? $_SERVER['SCRIPT_FILENAME'];
				if (is_file($scriptPath)) {
					include $scriptPath;
				} else {
					$status = 404;
					echo 'Not Found';
				}
			}
			$headers = Q_WebServer::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			elseif (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();

			// Recover the status from the Platform's OWN error state.
			//
			// Q_Response::errorHeaderCode() and Q_Response::code() both publish
			// the status by calling http_response_code()/header(), which the CLI
			// SAPI discards -- so a validation failure that should be a 412 went
			// out with a 200 status line and an error page as its body. An API
			// client reads 200, treats it as success, and parses the error page.
			//
			// The code is not actually lost: it is still on the exception
			// objects in Q_Response::getErrors(), which the Platform declares.
			// Read it from there rather than from PHP's SAPI plumbing.
			if ($status === 200
			and class_exists('Q_Response', false)
			and method_exists('Q_Response', 'getErrors')) {
				try {
					foreach ((array) Q_Response::getErrors() as $err) {
						if (is_object($err) and !empty($err->httpResponseCode)) {
							$status = (int) $err->httpResponseCode;
							break;
						}
					}
				} catch (\Throwable $e) { /* never let error reporting throw */ }
			}

			// Cookies: Q_Response::setCookie() stores them.
			// Headers::processResponse() reads them via cookieHeaders()
			// and emits Set-Cookie headers when assembling the response.
			// No action needed here.
		} catch (\Throwable $e) {
			$status = 500;
			if (!empty($useCapture)) Q_WebServer_Capture::discard();
			elseif (ob_get_level()) ob_clean();
			echo json_encode(array('error' => $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
		}
		// ob_get_contents() reads a non-removable buffer; ob_get_clean() would
		// return false on it. Record the content for the shutdown handler in
		// case the Platform exits before we return, then drop the buffer.
		$body = '';
		if (!empty($useCapture)) {
			$body = Q_WebServer_Capture::end();
		} elseif (ob_get_level()) {
			$body = (string) ob_get_contents();
			@ob_clean();
			while (@ob_end_clean()) { /* drop any buffers we can */ }
		}
		self::$_capturedOutput = '';
		@header_remove();

		// Fix 1: Clean up upload temp files
		self::cleanupUploadFiles();

		// Fix 3: Restore native php:// stream wrapper
		if (class_exists('Q_Request', false)) {
			Q_WebServer_State::restoreInput();
		}

		// Shut down framework compatibility layer (cleanup temp files, close sessions)
		if (class_exists('Q_WebServer_Compat', false) && !Q_Config::get("Q", "compat", "skipSourceCodeTransform", false)) {
			Q_WebServer_Compat::shutdown();
		}

		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		// Process Merkle cache headers (strips X-Q-Cache-* from response)
		if (Q_WebServer_Cache_Components::enabled()) {
			$pageKey = Q_WebServer_Cache_Components::pageKey($parsed);
			Q_WebServer_Cache_Components::processResponseHeaders($pageKey, $headers);
		}

		// Default Content-Type. A script that never calls header() leaves none set,
		// so we were emitting a 200 with NO Content-Type at all — browsers sniff and
		// strict clients reject. Error paths set it explicitly, which is why this only
		// ever bit successful PHP dispatches, such as the app's own home page.
		$hasContentType = false;
		foreach ($headers as $_hk => $_hv) {
			if (strcasecmp($_hk, 'Content-Type') === 0) { $hasContentType = true; break; }
		}
		if (!$hasContentType && $status != 204 && $status != 304) {
			$headers['Content-Type'] = 'text/html; charset=utf-8';
		}
		// Carry the request method so processResponse() can honour HEAD.
		$_method = $parsed['method'] ?? 'GET';
		return compact('status', 'body', 'headers', '_method');
	}

	// ── Request parsing ──────────────────────────────────

	static function parseRequest($raw)
	{
		$headerEnd = strpos($raw, "\r\n\r\n");
		$headerBlock = substr($raw, 0, $headerEnd);
		$body = substr($raw, $headerEnd + 4);

		// Trim body to Content-Length if present (prevents pipelined
		// request data from bleeding into this request's body)
		if (preg_match('/content-length:\s*(\d+)/i', $headerBlock, $_clm)) {
			$cl = (int) $_clm[1];
			if (strlen($body) > $cl) {
				$body = substr($body, 0, $cl);
			}
		}

		// Fast request line parse
		$rlEnd = strpos($headerBlock, "\r\n");
		$requestLine = $rlEnd !== false ? substr($headerBlock, 0, $rlEnd) : $headerBlock;

		if (!preg_match('#^(\w+)\s+([^\s]+)\s+HTTP/(\d\.\d)#', $requestLine, $m)) {
			return array(
				'method' => 'GET', 'uri' => '/', 'path' => '/',
				'query' => '', 'headers' => array(), 'body' => '',
				'httpVersion' => '1.0', '_malformed' => true
			);
		}

		// Issue #1: HTTP methods are case-sensitive per RFC 9110 §9.1.
		// Standard methods are uppercase. We reject non-standard casing
		// with 501 Not Implemented (same as nginx/Apache behavior).
		$method = $m[1];
		$knownMethods = array('GET','HEAD','POST','PUT','DELETE','PATCH','OPTIONS','TRACE','CONNECT');
		if (!in_array($method, $knownMethods, true)) {
			// Check if it's a known method with wrong case
			if (in_array(strtoupper($method), $knownMethods, true)) {
				return array(
					'method' => $method, 'uri' => $m[2], 'path' => '/',
					'query' => '', 'headers' => array(), 'body' => '',
					'httpVersion' => $m[3], '_badMethod' => true
				);
			}
			// Unknown method — allow it through (extensions are valid per RFC)
		}

		$uri = $m[2];
		$httpVersion = $m[3];

		// Fast path parsing — avoid parse_url for simple paths
		$qPos = strpos($uri, '?');
		if ($qPos !== false) {
			$path = urldecode(substr($uri, 0, $qPos));
			$query = substr($uri, $qPos + 1);
		} else {
			$path = urldecode($uri);
			$query = '';
		}
		// Collapse double slashes
		if (strpos($path, '//') !== false) {
			$path = preg_replace('#/+#', '/', $path);
		}

		// Issue #2: RFC-compliant header parsing
		$headers = array();
		$rawHeaders = array();
		$hostCount = 0;
		$pos = $rlEnd !== false ? $rlEnd + 2 : strlen($headerBlock);
		$len = strlen($headerBlock);
		while ($pos < $len) {
			$nlPos = strpos($headerBlock, "\r\n", $pos);
			if ($nlPos === false) $nlPos = $len;

			// Check for continuation line (starts with SP or TAB) — obs-fold, RFC 9110 §5.2
			$firstChar = $pos < $len ? $headerBlock[$pos] : '';
			if (($firstChar === ' ' || $firstChar === "\t") && !empty($lastKey)) {
				// Append to previous header value
				$continuation = trim(substr($headerBlock, $pos, $nlPos - $pos), " \t");
				$headers[$lastKey] .= ' ' . $continuation;
				$pos = $nlPos + 2;
				continue;
			}

			$colonPos = strpos($headerBlock, ':', $pos);
			if ($colonPos !== false && $colonPos < $nlPos) {
				$k = substr($headerBlock, $pos, $colonPos - $pos);

				// Validate header name — must be a valid token (RFC 9110 §5.1)
				// token = 1*tchar, tchar = letters, digits, !#$%&'*+-.^_`|~
				if (!preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $k)) {
					// Invalid characters in header name → reject request
					return array(
						'method' => $method, 'uri' => $uri, 'path' => $path,
						'query' => $query, 'headers' => array(), 'body' => '',
						'httpVersion' => $httpVersion, '_badHeaders' => true
					);
				}

				// Trim both sides: SP and HTAB only (RFC 9110 §5.5)
				$v = trim(substr($headerBlock, $colonPos + 1, $nlPos - $colonPos - 1), " \t");

				// Preserve raw header line with original case for getallheaders()
				$rawHeaders[] = $k . ': ' . $v;

				$k = strtolower($k);

				// Duplicate header handling
				if ($k === 'host') {
					$hostCount++;
					if ($hostCount > 1) {
						// Multiple Host headers → reject (RFC 9110 §7.2, common attack vector)
						return array(
							'method' => $method, 'uri' => $uri, 'path' => $path,
							'query' => $query, 'headers' => array(), 'body' => '',
							'httpVersion' => $httpVersion, '_duplicateHost' => true
						);
					}
				}

				// RFC 9110 §5.3: multiple headers → combine with ", "
				// Exceptions: Set-Cookie is special but that's a response header
				if (isset($headers[$k])) {
					$headers[$k] .= ', ' . $v;
				} else {
					$headers[$k] = $v;
				}
				$lastKey = $k;
			}
			$pos = $nlPos + 2;
		}

		// HTTP/1.0 defaults to Connection: close
		if ($httpVersion === '1.0' && !isset($headers['connection'])) {
			$headers['connection'] = 'close';
		}

		// Decode chunked transfer-encoding
		if (isset($headers['transfer-encoding'])
			&& stripos($headers['transfer-encoding'], 'chunked') !== false
			&& $body
		) {
			$decoded = '';
			$pos = 0;
			while ($pos < strlen($body)) {
				$nlPos = strpos($body, "\r\n", $pos);
				if ($nlPos === false) break;
				$chunkSize = hexdec(substr($body, $pos, $nlPos - $pos));
				if ($chunkSize === 0) break;
				$pos = $nlPos + 2;
				$decoded .= substr($body, $pos, $chunkSize);
				$pos += $chunkSize + 2; // skip chunk data + \r\n
			}
			$body = $decoded;
			unset($headers['transfer-encoding']);
			$headers['content-length'] = (string) strlen($body);
		}

		return compact('method', 'uri', 'path', 'query', 'headers', 'body', 'httpVersion', 'rawHeaders');
	}

	/**
	 * Parse multipart/form-data body into $_POST and $_FILES arrays.
	 * Handles file uploads by writing to temp files (same as php-fpm).
	 * @method parseMultipart
	 * @static
	 * @param {string} $contentType Full Content-Type header value
	 * @param {string} $body Raw request body
	 * @param {array} &$post Populated with form field values
	 * @param {array} &$files Populated with file upload entries
	 */
	static function parseMultipart($contentType, $body, &$post, &$files)
	{
		// Check if file uploads are enabled
		if (!self::$fileUploads) {
			// Parse form fields only, skip files
		}

		// Extract boundary from Content-Type
		if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $bm)) {
			return;
		}
		$boundary = '--' . ($bm[1] ?: $bm[2]);
		$endBoundary = $boundary . '--';

		$parts = explode($boundary, $body);
		array_shift($parts); // before first boundary
		$fileCount = 0;

		foreach ($parts as $part) {
			$part = ltrim($part, "\r\n");
			if ($part === '--' || $part === "--\r\n" || $part === '') continue;
			if (strpos($part, '--') === 0) continue; // end boundary

			// Split headers from body
			$headerEnd = strpos($part, "\r\n\r\n");
			if ($headerEnd === false) continue;

			$headerBlock = substr($part, 0, $headerEnd);
			$partBody = substr($part, $headerEnd + 4);
			// Remove trailing \r\n
			if (substr($partBody, -2) === "\r\n") {
				$partBody = substr($partBody, 0, -2);
			}

			// Parse part headers
			$partHeaders = array();
			foreach (explode("\r\n", $headerBlock) as $line) {
				$colonPos = strpos($line, ':');
				if ($colonPos !== false) {
					$k = strtolower(trim(substr($line, 0, $colonPos)));
					$v = trim(substr($line, $colonPos + 1));
					$partHeaders[$k] = $v;
				}
			}

			$disp = $partHeaders['content-disposition'] ?? '';
			if (strpos($disp, 'form-data') === false) continue;

			// Extract name
			$name = null;
			if (preg_match('/\bname="([^"]*)"/', $disp, $nm)) {
				$name = $nm[1];
			} elseif (preg_match("/\bname='([^']*)'/", $disp, $nm)) {
				$name = $nm[1];
			}
			if ($name === null) continue;

			// Check if it's a file upload
			$filename = null;
			if (preg_match('/\bfilename="([^"]*)"/', $disp, $fm)) {
				$filename = $fm[1];
			} elseif (preg_match("/\bfilename='([^']*)'/", $disp, $fm)) {
				$filename = $fm[1];
			}

			if ($filename !== null) {
				// Enforce file upload limits
				if (!self::$fileUploads) continue; // uploads disabled
				$fileCount++;
				if ($fileCount > self::$maxFileUploads) continue; // too many files

				$error = UPLOAD_ERR_OK;
				if (strlen($partBody) > self::$maxUploadSize) {
					$error = UPLOAD_ERR_INI_SIZE;
					$partBody = ''; // don't write oversized file
				}

				// File upload — write to temp file
				$tmpPath = tempnam(sys_get_temp_dir(), 'qbix_upload_');
				if ($error === UPLOAD_ERR_OK) {
					file_put_contents($tmpPath, $partBody);
				}
				self::$uploadTempFiles[] = $tmpPath;
				// Tell the compat layer this is an upload. Both
				// is_uploaded_file() and move_uploaded_file() are shimmed,
				// because the real ones answer from a list that only the
				// SAPI's own multipart parser fills and which is therefore
				// always empty here. The shims answer from this registry
				// instead, and nothing on this path ever wrote to it: the
				// other multipart parser, Q_WebServer_Compat::parseMultipart(),
				// registers its files, and the worker does not use it.
				//
				// So the file arrived whole, $_FILES was correct, and every
				// application that checks an upload before accepting it --
				// which is every careful one, and the check is the documented
				// way to do it -- refused it. In an editor with a progress
				// dialog that shows as an upload that never finishes.
				if ($error === UPLOAD_ERR_OK && class_exists('Q_WebServer_Compat', false)) {
					\Q_WebServer_Compat::$uploadedFiles[$tmpPath] = true;
				}

				$fileEntry = array(
					'name'     => $filename,
					'type'     => $partHeaders['content-type'] ?? 'application/octet-stream',
					'tmp_name' => $tmpPath,
					'error'    => $error,
					'size'     => strlen($partBody),
				);

				// Handle array notation: files[0], files[photo], etc.
				if (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $am)) {
					$files[$am[1]]['name'][$am[2]] = $fileEntry['name'];
					$files[$am[1]]['type'][$am[2]] = $fileEntry['type'];
					$files[$am[1]]['tmp_name'][$am[2]] = $fileEntry['tmp_name'];
					$files[$am[1]]['error'][$am[2]] = $fileEntry['error'];
					$files[$am[1]]['size'][$am[2]] = $fileEntry['size'];
				} else {
					$files[$name] = $fileEntry;
				}
			} else {
				// Regular form field
				// Handle array notation: tags[], data[key], etc.
				if (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $am)) {
					if ($am[2] === '') {
						$post[$am[1]][] = $partBody;
					} else {
						$post[$am[1]][$am[2]] = $partBody;
					}
				} else {
					$post[$name] = $partBody;
				}
			}
		}
	}

	// ── Response helpers ─────────────────────────────────

	/**
	 * Make phpinfo() output presentable.
	 *
	 * The CLI-family SAPIs, phpmicro among them, make phpinfo() emit plain
	 * text rather than the HTML page mod_php produces, and no ini setting
	 * changes that. Q_WebServer_PhpInfo parses the text back into tables;
	 * output that is already markup comes back untouched.
	 *
	 * @method phpinfoHtml
	 * @static
	 * @param {string} $out Raw phpinfo() output
	 * @return {string}
	 */
	static function phpinfoHtml($out)
	{
		return Q_WebServer_PhpInfo::render($out);
	}

	/**
	 * Record a finished request in the dashboard, the metrics and the access log.
	 *
	 * The inline block in the read loop can only do this for responses the
	 * parent writes itself. A pooled worker answers later, from its own event,
	 * so the loop ran long before the status and the size were known -- it
	 * logged the 200 that dispatch() had just assigned and the byte count left
	 * over from the previous static response, and counted the request as
	 * static. The pool calls this when the response actually goes out.
	 *
	 * @method recordCompleted
	 * @static
	 * @param {array} $parsed The request
	 * @param {integer} $status
	 * @param {integer} $bytes Body bytes handed to the client
	 * @param {float} $ms
	 * @param {boolean} [$isPhp=true]
	 * @param {integer} [$memUsed=0] The worker's heap peak for the request, in bytes; 0 when unknown
	 */
	static function recordCompleted($parsed, $status, $bytes, $ms, $isPhp = true, $memUsed = 0)
	{
		$status = (int) $status;
		$bytes = (int) $bytes;
		Q_WebServer_Dashboard::recordRequest(
			$parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', $status, $ms, $bytes,
			$isPhp, '', (int) $memUsed, $parsed['cookies'] ?? array()
		);
		if (class_exists('Q_WebServer_Metrics', false)) {
			Q_WebServer_Metrics::recordRequest(
				$status, $ms,
				$parsed['method'] ?? 'GET', $parsed['uri'] ?? '/',
				$parsed['clientIp'] ?? ($parsed['_remoteAddr'] ?? ''),
				$parsed['headers']['user-agent'] ?? '',
				$bytes,
				$parsed['cookies'] ?? array()
			);
		}
		if (self::$onRequest) {
			(self::$onRequest)($parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', $status, $ms);
		}
		Q_WebServer_Log::access(
			$parsed['clientIp'] ?? '', $parsed['method'] ?? 'GET', $parsed['uri'] ?? '/',
			$status, $bytes,
			$parsed['headers']['referer'] ?? '',
			$parsed['headers']['user-agent'] ?? '',
			$ms,
			// Without the headers the line cannot be attributed to a
			// virtual host, and these are the requests -- cache hits and
			// static files -- that a busy vhost has most of.
			array(
				'path' => $parsed['path'] ?? null,
				'query' => $parsed['query'] ?? '',
				'protocol' => 'HTTP/' . ($parsed['httpVersion'] ?? '1.1'),
				'headers' => $parsed['headers'] ?? array(),
			)
		);
	}

	/**
	 * How many established connections may wait to be accepted.
	 *
	 * PHP's default is 32, which is not a queue so much as a cliff. A burst
	 * larger than that does not queue, and does not fail either: the kernel
	 * drops the SYN and the client retransmits it a second later. Measured
	 * here on 2026-09-22, serving one cached page:
	 *
	 *     concurrency 16    2961 req/s   p99    4.7 ms
	 *     concurrency 32    2899 req/s   p99   13.5 ms
	 *     concurrency 64     745 req/s   p99 1067.0 ms
	 *
	 * Throughput did not degrade at 64, it collapsed to a quarter, and the p99
	 * became a round thousand milliseconds -- the retransmit timer, not the
	 * server, which sat mostly idle while it waited. A server that is fast
	 * until exactly 32 concurrent connections and then appears to hang is hard
	 * to diagnose from outside, because nothing in it is slow.
	 *
	 * The queue is only a queue: each entry costs a few hundred bytes of kernel
	 * memory and is served at whatever rate accept() manages. It is bounded by
	 * net.core.somaxconn, which the kernel silently clamps to, so asking for
	 * more than the system allows is not an error.
	 *
	 * @method listenBacklog
	 * @static
	 * @return {integer}
	 */
	static function listenBacklog()
	{
		$backlog = (int) Q_Config::get('Q', 'webserver', 'backlog', 1024);
		if ($backlog < 1) $backlog = 1024;
		return $backlog;
	}

	/**
	 * Close every descriptor a forked worker has no business holding.
	 *
	 * A child of fork() inherits the parent's whole descriptor table. The
	 * worker needs exactly one of them -- its half of the socket pair the pool
	 * created for it -- and inherits, in addition: the listening socket, the
	 * TLS listener, the Unix socket, and every client connection the parent had
	 * open at that moment, including other people's requests in flight.
	 *
	 * That is an isolation failure on its own, with no multi-tenancy involved.
	 * A worker serving one request holds the socket of another visitor's
	 * request and can read from it. It is also a correctness problem: a
	 * listening socket held open by sixteen workers is not closed when the
	 * parent closes it, so a restart can find the port still bound, and a
	 * client socket the parent has finished with is not released until every
	 * worker that inherited it exits.
	 *
	 * Workers never accept: stream_socket_accept() appears only in this class,
	 * and the pool dispatches over its socket pair. So the listeners are safe
	 * to close here and must be.
	 *
	 * Called from the child branch of Pool::forkWorker(), before the worker
	 * runs any application code.
	 *
	 * @method closeInheritedDescriptors
	 * @static
	 * @return {integer} how many were closed, for the test to assert on
	 */
	static function closeInheritedDescriptors()
	{
		$closed = 0;

		foreach (array('socket', 'tlsSocket', 'udsSocket') as $name) {
			if (isset(self::$$name) and is_resource(self::$$name)) {
				self::releaseInherited(self::$$name);
				$closed++;
			}
			self::$$name = null;
		}

		// Client connections the parent had open at the moment of the fork.
		// Another visitor's request is not this worker's to hold, let alone
		// to read.
		foreach (self::$clients as $key => $client) {
			if (is_resource($client)) {
				self::releaseInherited($client);
				$closed++;
			}
		}
		self::$clients = array();
		self::$buffers = array();

		// HTTP/2 connections wrap a client socket of their own.
		foreach (self::$http2 as $key => $conn) {
			if (is_object($conn) and isset($conn->socket) and is_resource($conn->socket)) {
				self::releaseInherited($conn->socket);
				$closed++;
			}
		}
		self::$http2 = array();

		return $closed;
	}

	/**
	 * Let go of a stream a forked worker inherited, without touching the
	 * connection it belongs to.
	 *
	 * A plain socket is closed: on Linux that only drops this process's
	 * reference. A TLS stream must NOT be: PHP closes one with SSL_shutdown(),
	 * which WRITES a close_notify alert onto the socket -- the same socket the
	 * parent is still using for that visitor. Every worker forked while TLS
	 * connections were open therefore ended them: the visitor saw "connection
	 * closed without response". Rare while workers were only forked to
	 * replace one; constant once a dynamic pool forked under load (239 of 360
	 * requests in a burst on alpha).
	 *
	 * So a TLS stream is parked in a function-level static (outside anything
	 * the per-request snapshot restores) and never destroyed by PHP. The
	 * worker, once it has parked any, ends with SIGKILL after its shutdown
	 * functions have run, so the kernel closes those descriptors silently.
	 * The parent's own close of the connection still sends close_notify, so
	 * the visitor still sees it end.
	 *
	 * @method releaseInherited
	 * @static
	 * @param {resource} $stream
	 * @return {boolean} true if closed, false if parked (or not a resource)
	 */
	static function releaseInherited($stream)
	{
		static $parked = array();
		if (!is_resource($stream)) return false;
		$meta = @stream_get_meta_data($stream);
		if (empty($meta['crypto'])) {
			@fclose($stream);
			return true;
		}
		if (!$parked and function_exists('posix_kill') and defined('SIGKILL')) {
			// Registered from inside a shutdown function so it runs after
			// every other one, including any the worker adds later.
			register_shutdown_function(function () {
				register_shutdown_function(function () {
					posix_kill(getmypid(), SIGKILL);
				});
			});
		}
		$parked[] = $stream;
		return false;
	}

	/**
	 * The request headers of a request for one of the server's own pages
	 * (/Q/...), while it is being answered on HTTP/1.1, so sendResponse()
	 * can compress what it writes; null otherwise. HTTP/2 compresses in
	 * Q_WebServer_Http2_Connection::respond().
	 * @property $compressFor
	 * @type {array|null}
	 * @static
	 */
	static $compressFor = null;

	/**
	 * The Connection header that tells the truth about what happens next.
	 *
	 * After answering, the server keeps the connection only when the request
	 * allowed it and the status is below 500 (see the keep-alive decision in
	 * onClientData). sendResponse() used to say "keep-alive" regardless, so
	 * the last response before keepAlive.max -- the 1000th on a connection,
	 * as configured here -- promised a connection the server then closed. A
	 * client that believed it sent its next request into a closed socket:
	 * ApacheBench counted about one "Length" or "Exception" failure per
	 * thousand requests, and a proxy reusing the connection gets an error.
	 *
	 * @method connectionHeader
	 * @static
	 * @param {boolean} $keepAlive whether this request may keep the connection
	 * @param {integer} $status the response status
	 * @return {string} "keep-alive" or "close"
	 */
	static function connectionHeader($keepAlive, $status)
	{
		return ($keepAlive && $status < 500) ? 'keep-alive' : 'close';
	}

	static function sendResponse($client, $status, $body, $type = 'text/plain; charset=utf-8', $extra = array(), $headOnly = false)
	{
		static $reasons = array(
			200=>'OK', 301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
			307=>'Temporary Redirect', 308=>'Permanent Redirect',
			400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden', 404=>'Not Found',
			409=>'Conflict', 413=>'Payload Too Large', 429=>'Too Many Requests',
			431=>'Request Header Fields Too Large',
			500=>'Internal Server Error', 502=>'Bad Gateway',
			503=>'Service Unavailable', 504=>'Gateway Timeout'
		);
		self::$lastStatus = $status;
		self::$lastBody = $body;
		$body = (string) $body;
		$conn = $extra['Connection'] ?? self::connectionHeader(self::$requestKeepAlive, $status);
		unset($extra['Connection']);
		if (!is_array($extra)) $extra = array();
		// The server's own pages: HTML, CSS, JavaScript and JSON compressed
		// for a client that accepts it (never tiny bodies, images or event
		// streams -- see Q_WebServer_Headers::shouldCompress()).
		if (self::$compressFor !== null and $body !== '') {
			$ct = $type;
			foreach ($extra as $k => $v) {
				if (strcasecmp($k, 'Content-Type') === 0) $ct = (string) $v;
			}
			$body = Q_WebServer_Headers::maybeCompress($body, $ct, self::$compressFor, $extra);
		}
		self::$lastBytes = strlen($body);
		// The server's own pages (404, 403 ...) for a domain with HSTS: the
		// host is the one the gate saw for this request.
		if (class_exists('Q_WebServer_Domains', false) and Q_WebServer_Domains::$currentHost !== null
			and is_resource($client) and !empty(@stream_get_meta_data($client)['crypto'])) {
			Q_WebServer_Domains::addHsts($extra, Q_WebServer_Domains::$currentHost, true);
		}

		// Content-Type was written from the argument and the caller's headers
		// were appended after it, so a caller that had one -- every cached
		// response does -- produced two. RFC 9110 leaves a recipient free to
		// pick either, which means two intermediaries can disagree about what
		// a page is.
		//
		// Content-Length is worse: the stored one describes the body as it was
		// when it was stored, and the body being written here may have been
		// compressed since. Two different lengths on one message is how a
		// parser is taught to read the next response out of the middle of this
		// one. The body in hand is the only length that can be right, so it is
		// computed here and any supplied one is dropped.
		$out = self::http1Head(
			$status, $reasons[$status] ?? 'OK',
			$type, strlen($body), $conn, $extra
		);
		// A HEAD gets the headers a GET would, Content-Length included, and
		// no body.
		self::writeAll($client, $out . "\r\n" . ($headOnly ? '' : $body));
	}

	/**
	 * Build the status line and headers of an HTTP/1.1 response.
	 *
	 * Separate from sendResponse because a socket cannot be asserted on and
	 * this can. What it decides is worth asserting on: see the note above
	 * about Content-Type and Content-Length.
	 *
	 * @method http1Head
	 * @static
	 * @param {integer} $status
	 * @param {string} $reason
	 * @param {string} $type fallback Content-Type
	 * @param {integer} $length the length of the body actually being sent
	 * @param {string} $conn the Connection value
	 * @param {array} $extra the caller's headers
	 * @return {string} ending with a single CRLF, not the blank line
	 */
	static function http1Head($status, $reason, $type, $length, $conn, $extra = array())
	{
		if (!is_array($extra)) $extra = array();

		foreach ($extra as $k => $v) {
			if (strcasecmp($k, 'Content-Type') === 0) {
				if ($v !== '') $type = $v;
				unset($extra[$k]);
			} else if (strcasecmp($k, 'Content-Length') === 0) {
				unset($extra[$k]);
			} else if (strcasecmp($k, 'Connection') === 0) {
				unset($extra[$k]);
			} else if (strcasecmp($k, 'Date') === 0) {
				// Ours is authoritative; a stored one states when the page was
				// rendered while claiming to state when it was sent.
				unset($extra[$k]);
			}
		}

		$out = "HTTP/1.1 $status $reason"
			. "\r\nDate: " . gmdate('D, d M Y H:i:s') . " GMT"
			. "\r\nContent-Type: $type\r\nContent-Length: " . (int) $length
			. "\r\nConnection: $conn\r\n";

		return $out . self::headerLines($extra);
	}

	/**
	 * Serialise a header map into response lines.
	 *
	 * Every place that writes headers to a client goes through here. That is
	 * the point of it: a header value carrying CR or LF lets whoever supplied
	 * it write headers of its own, or a second response entirely, and values
	 * reach us from application output and from stored cache entries, so this
	 * is not a theoretical source. A guard that lives in one of six
	 * hand-written loops protects one of six responses, which is why the loops
	 * were replaced by a call rather than by six copies of the check.
	 *
	 * A header that cannot be represented is dropped rather than truncated or
	 * escaped. Truncating keeps an attacker-chosen prefix, and escaping invents
	 * a value the caller never asked to send; omitting it is the only option
	 * that states nothing false.
	 */

	/**
	 * The product name shown in the served views -- the dashboard heading and
	 * title, the docs chrome, the boot banner, the manifest.
	 *
	 * A parameter with a default, not a literal, so a fork can carry its own
	 * name without editing a dozen views and without diverging from upstream
	 * everywhere the string appears. Upstream keeps "Qbix Server"; this branch
	 * sets Q.webserver.brand to "Exponential Velocity" in its config.
	 *
	 * This renames only the *product*. The wire identifiers -- the
	 * .well-known/qbix path, qbix.json, the "qbix" framework key, isQbixApp --
	 * are protocol, not brand, and are left exactly as they are: renaming them
	 * would break federation and app detection for a cosmetic gain, the same
	 * distinction the rest of this codebase draws between a name and a symbol.
	 *
	 * @method brand
	 * @static
	 * @return {string}
	 */
	static $brand = null;

	/**
	 * The address at the far end of a connection, without the port.
	 *
	 * "203.0.113.9:51000" and "[2001:db8::1]:51000" alike. It used to be
	 * explode(':')[0], which turned any IPv6 peer into "[".
	 *
	 * @method peerIp
	 * @static
	 * @param {resource} $client
	 * @return {string} '0.0.0.0' when the socket cannot say
	 */
	static function peerIp($client)
	{
		$peer = is_resource($client) ? @stream_socket_get_name($client, true) : false;
		if (!is_string($peer) or $peer === '') return '0.0.0.0';
		if ($peer[0] === '[') {
			$end = strpos($peer, ']');
			return $end === false ? '0.0.0.0' : substr($peer, 1, $end - 1);
		}
		$colons = substr_count($peer, ':');
		if ($colons === 0) return $peer;
		if ($colons === 1) return substr($peer, 0, strpos($peer, ':'));
		// Unbracketed IPv6 with a port: the port follows the last colon.
		return substr($peer, 0, strrpos($peer, ':'));
	}

	/**
	 * Whether a request comes from this machine.
	 *
	 * Taken from the address the server resolved for the request (the
	 * connection's, or a trusted proxy's forwarded one). An address that is
	 * missing or unknown counts as remote: the admin surface fails closed.
	 *
	 * @method isLocalRequest
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function isLocalRequest($parsed)
	{
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		return $ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1';
	}

	/**
	 * Whether a request carries a valid admin credential: the static
	 * Q.dashboard.token, or a control-panel session token, as ?token=, as
	 * "Authorization: Bearer", or as the Q_panel_token cookie. The static
	 * token is compared in constant time.
	 *
	 * @method hasAdminCredential
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function hasAdminCredential($parsed)
	{
		$qp = array();
		if (!empty($parsed['query'])) parse_str((string) $parsed['query'], $qp);
		$auth = (string) ($parsed['headers']['authorization'] ?? '');
		$given = array(
			is_string($qp['token'] ?? null) ? $qp['token'] : '',
			strncmp($auth, 'Bearer ', 7) === 0 ? substr($auth, 7) : '',
			(string) ($parsed['cookies']['Q_panel_token'] ?? ''),
		);
		// A control panel session, wherever the request carries it -- the same
		// answer the panel and the shell get (Q_WebServer_Panel_Auth::sessionFromRequest).
		if (class_exists('Q_WebServer_Panel_Auth') && ($ps = Q_WebServer_Panel_Auth::sessionFromRequest($parsed)) !== null && !$ps['mustChange']) return true;
		$static = Q_Config::get('Q', 'dashboard', 'token', null);
		foreach ($given as $t) {
			if ($t === '') continue;
			if (is_string($static) and $static !== '' and hash_equals($static, $t)) return true;
			if (Q_WebServer_Panel::validateToken($t)) return true;
		}
		return false;
	}

	/**
	 * Who may use the server's own admin surface: /Q/dashboard, /Q/stats,
	 * the dashboard WebSocket, the full /Q/health, /Q/metrics,
	 * /Q/attestation and, as a secret, /Q/phpinfo.
	 *
	 * They were open to anyone who could reach the port: phpinfo() printed
	 * the server process's environment, and the dashboard listed every
	 * visitor's paths and session-id prefixes. Now:
	 *
	 *   - this machine: always, except that a secret needs the credential
	 *     once one is configured;
	 *   - anywhere else: the credential when a password or token is
	 *     configured; without one, only if Q.dashboard.remote is true, and
	 *     never a secret.
	 *
	 * @method adminAllowed
	 * @static
	 * @param {array} $parsed
	 * @param {boolean} [$secret=false] true for what must never be remote without a credential
	 * @return {boolean}
	 */
	static function adminAllowed($parsed, $secret = false)
	{
		$local = self::isLocalRequest($parsed);
		if ($local and !$secret) return true;
		$static = Q_Config::get('Q', 'dashboard', 'token', null);
		$configured = Q_WebServer_Panel::hasPassword() || (is_string($static) and $static !== '');
		if ($configured) return self::hasAdminCredential($parsed);
		if ($local) return true;
		if ($secret) return false;
		return (bool) Q_Config::get('Q', 'dashboard', 'remote', false);
	}

	/** The answer to a request adminAllowed() refused. */
	static function adminForbidden()
	{
		return array('status' => 403,
			'body' => "Forbidden. This page is available from this machine, or with the dashboard token "
				. "or a control panel session; to allow it remotely without one, set Q.dashboard.remote.",
			'headers' => array('Content-Type' => 'text/plain; charset=utf-8'));
	}

	/**
	 * Whether this request is a browser navigation that a refused admin view
	 * should send to the login page instead of a plain 403: a GET or HEAD
	 * whose Accept offers text/html, and not an API/XHR call. The SPA's own
	 * JSON calls (X-Panel-Token or X-Requested-With, a Bearer token) and
	 * scrapers keep the 403/JSON they can act on.
	 */
	static function wantsHtml($parsed)
	{
		$m = strtoupper((string) ($parsed['method'] ?? 'GET'));
		if ($m !== 'GET' and $m !== 'HEAD') return false;
		$h = (array) ($parsed['headers'] ?? array());
		if (isset($h['x-panel-token']) or isset($h['x-requested-with'])) return false;
		if (strncmp((string) ($h['authorization'] ?? ''), 'Bearer ', 7) === 0) return false;
		return stripos((string) ($h['accept'] ?? ''), 'text/html') !== false;
	}

	/**
	 * The original request as one same-origin path to return to after login:
	 * the path, plus its query. Anything that is not a single-slash-rooted
	 * relative path -- a scheme, a protocol-relative "//", a backslash, a
	 * control character -- falls back to /Q/dashboard, so the value can never
	 * become an open redirect.
	 */
	static function safeNextPath($parsed)
	{
		$path = (string) ($parsed['path'] ?? '');
		$query = (string) ($parsed['query'] ?? '');
		$full = $query !== '' ? $path . '?' . $query : $path;
		if ($full === '' or $full[0] !== '/') return '/Q/dashboard';
		if (isset($full[1]) and ($full[1] === '/' or $full[1] === '\\')) return '/Q/dashboard';
		if (strpbrk($full, "\r\n\t\0") !== false) return '/Q/dashboard';
		if (strpos($full, '\\') !== false) return '/Q/dashboard';
		return $full;
	}

	/** base64url (RFC 4648, no padding): safe as one URL path segment. */
	static function base64urlEncode($s)
	{
		return rtrim(strtr(base64_encode((string) $s), '+/', '-_'), '=');
	}

	/**
	 * The control-panel login URL that returns to the requested page. The page
	 * travels as the `next` view parameter (/Q/panel/(next)/<enc>), base64url
	 * so its slashes and query survive as one segment -- the same addressable
	 * view-parameter scheme the panel's tabs use.
	 */
	static function loginRedirectUrl($parsed)
	{
		return '/Q/panel/(next)/' . self::base64urlEncode(self::safeNextPath($parsed));
	}

	/** A 302 that sends a browser to the login page, and back afterwards. */
	static function adminLoginRedirect($parsed)
	{
		$loc = self::loginRedirectUrl($parsed);
		$body = '<!DOCTYPE html><meta charset="utf-8"><title>Sign in</title>'
			. '<p>Redirecting to the control panel to sign in&hellip; '
			. '<a href="' . htmlspecialchars($loc, ENT_QUOTES) . '">Continue</a>.</p>';
		return array('status' => 302, 'body' => $body,
			'headers' => array('Location' => $loc,
				'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'));
	}

	/**
	 * The single answer every gated admin view gives when it refuses for want
	 * of a credential: a browser navigation is redirected to the login page
	 * (and returned here after signing in); an API/XHR call or a scraper gets
	 * the plain 403 unchanged. Both transports call this, so the behaviour
	 * cannot drift between views.
	 */
	static function adminRefusal($parsed)
	{
		if (self::wantsHtml($parsed)) return self::adminLoginRedirect($parsed);
		return self::adminForbidden();
	}

	/** /Q/health for a caller that may not see the stats: alive, and nothing else. */
	static function healthMinimal()
	{
		return array('status' => 200, 'body' => '{"status":"ok"}',
			'headers' => array('Content-Type' => 'application/json'));
	}

	static function brand()
	{
		if (self::$brand === null) {
			$v = class_exists('Q_Config', false)
				? Q_Config::get('Q', 'webserver', 'brand', 'Qbix Server')
				: 'Qbix Server';
			$v = is_string($v) ? trim($v) : '';
			self::$brand = ($v === '') ? 'Qbix Server' : $v;
		}
		return self::$brand;
	}

	/**
	 * A URL for a brand or maintainer label to link to, or '' for no link.
	 *
	 * Three keys, all empty by default so upstream shows plain text: brandUrl
	 * (the product's home, linked from the product name), maintainer (a short
	 * name; when set, the views show "Maintained by <name>") and maintainerUrl
	 * (where that name links). A fork fills them; the server invents none.
	 *
	 * The values reach HTML, so a caller escapes them at output. A value that
	 * is not an http/https URL is dropped here rather than linked, so a
	 * malformed setting cannot produce a javascript: or data: link.
	 *
	 * @method brandLink
	 * @static
	 * @param {string} $which one of 'brandUrl', 'maintainer', 'maintainerUrl'
	 * @return {string}
	 */
	static $brandLinks = array();
	static function brandLink($which)
	{
		if (!array_key_exists($which, self::$brandLinks)) {
			$v = class_exists('Q_Config', false)
				? Q_Config::get('Q', 'webserver', $which, '') : '';
			$v = is_string($v) ? trim($v) : '';
			// A label passes through; a URL must be http/https to be used.
			if ($which !== 'maintainer' && $v !== ''
				&& !preg_match('#^https?://#i', $v)) {
				$v = '';
			}
			self::$brandLinks[$which] = $v;
		}
		return self::$brandLinks[$which];
	}

	/**
	 * @method headerLines
	 * @static
	 * @param {array} $headers name => value
	 * @return {string} zero or more "Name: value\r\n" lines, no blank line
	 */
	static function headerLines($headers)
	{
		if (!is_array($headers)) return '';
		$out = '';
		foreach ($headers as $k => $v) {
			if (preg_match('/[\r\n]/', (string) $k . (string) $v)) continue;
			$out .= "$k: $v\r\n";
		}
		return $out;
	}

	static function sendRedirect($client, $loc, $permanent = false) {
		$code = $permanent ? 301 : 302;
		$text = $permanent ? 'Moved Permanently' : 'Found';
		self::writeAll($client, "HTTP/1.1 $code $text\r\nLocation: $loc\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
		self::$lastStatus = $code;
	}

	private static function sendNotModified($client, $etag, $mtime, $keepAlive = false) {
		$conn = $keepAlive ? 'keep-alive' : 'close';
		self::writeAll($client, "HTTP/1.1 304 Not Modified\r\nETag: $etag\r\n"
			. "Last-Modified: " . gmdate('D, d M Y H:i:s', $mtime) . " GMT\r\n"
			. "Cache-Control: " . self::staticCacheControl() . "\r\nContent-Length: 0\r\nConnection: $conn\r\n\r\n");
		self::$lastStatus = 304;
	}

	/**
	 * Render an error page. Checks for user override at errors/$code.php
	 * (or errors/$code.html), then falls back to built-in styled page.
	 * @method renderErrorPage
	 * @static
	 * @param {integer} $code HTTP status code
	 * @param {string} $path The requested path (for display)
	 * @return {string} HTML
	 */
	static function renderErrorPage($code, $path = '', $message = null)
	{
		// The domain's own error document, when it has one for this status
		// (the caller keeps the status; this only chooses the body).
		if (class_exists('Q_WebServer_Domains', false)
			and ($doc = Q_WebServer_Domains::errorDocument($code)) !== null) {
			return $doc;
		}
		$safe = htmlspecialchars($path, ENT_QUOTES);

		// Check user overrides: errors/404.php, errors/404.html
		foreach (Q_WebServer::paths() as $base) {
			$phpFile = $base . DS . 'errors' . DS . $code . '.php';
			if (file_exists($phpFile)) {
				ob_start();
				$_code = $code; $_path = $path;
				include $phpFile;
				return ob_get_clean();
			}
			$htmlFile = $base . DS . 'errors' . DS . $code . '.html';
			if (file_exists($htmlFile)) {
				return file_get_contents($htmlFile);
			}
		}

		// Built-in error pages: what happened, in words a visitor uses, and
		// what to do next. The status code is on the page as well, for anyone
		// who needs it; the title does not have to be the protocol's name.
		$titles = array(
			403 => 'You can\'t open this page',
			404 => 'We couldn\'t find that page',
			413 => 'That is too big to send',
			429 => 'Too many requests',
			500 => 'Something went wrong on our side',
			502 => 'This page didn\'t finish loading',
			503 => 'We\'ll be right back',
			504 => 'This page took too long',
		);
		$messages = array(
			403 => 'This address isn\'t open to visitors. If a link brought you here, it may point somewhere private.',
			404 => "Nothing lives at <code>{$safe}</code>. Check the address for a typo, or start again from the home page.",
			413 => 'What you tried to send is larger than this site accepts. Try a smaller file.',
			429 => 'There have been a lot of requests from you in a short time. Wait a moment, then try again.',
			500 => 'The server could not finish this page. Please try again in a moment.',
			502 => 'The part of the server that builds pages stopped while working on this one. Please try again; it usually works the second time.',
			503 => 'The site is busy or being updated. Please try again in a minute.',
			504 => 'It took longer than the server allows, so it was stopped. Please try again in a moment.',
		);
		$title = $titles[$code] ?? 'Error';
		// $message, when given, is HTML the server wrote itself -- never request data.
		$msg = $message !== null ? $message : ($messages[$code] ?? 'An unexpected error occurred.');

		// The page is a design on disk -- designs/default/error/ (page.html,
		// style.css), or the same files in the configuration directory's
		// designs/ -- byte for byte what this used to concatenate here. An
		// errors/<code>.php or .html in the application's paths, above,
		// still takes precedence.
		$page = Q_WebServer_Design::render('error', array(
			'code'  => (string) $code,
			'title' => $title,
			'msg'   => $msg,
			'brand' => htmlspecialchars(self::brand(), ENT_QUOTES, 'UTF-8'),
		));
		$page = $page !== null ? $page : "<!DOCTYPE html><html><body><h1>{$code} {$title}</h1><p>{$msg}</p></body></html>";
		// The server's own pages (under /Q/) carry the shell; a site's error pages do not.
		return strpos((string) $path, '/Q/') === 0 ? Q_WebServer_Shell::decorate($page) : $page;
	}

	private static function render404($path)
	{
		return self::renderErrorPage(404, $path);
	}

	/**
	 * Render the documentation viewer — a single-page app that fetches
	 * and renders markdown files from /Q/docs/raw/*.
	 */

	/**
	 * Whether a /Q/metrics request is a person's browser rather than a
	 * scraper. ?format=text or ?format=html decide outright. Otherwise HTML
	 * only when text/html is asked for explicitly and ranks above any plain
	 * text or OpenMetrics type; a bare wildcard, no Accept at all, curl and
	 * Prometheus (openmetrics / text/plain first) all get the text format.
	 * @method metricsWantsHtml
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function metricsWantsHtml($parsed)
	{
		$qp = array();
		if (!empty($parsed['query'])) parse_str((string) $parsed['query'], $qp);
		$format = isset($qp['format']) && is_string($qp['format']) ? strtolower($qp['format']) : '';
		if ($format === 'text' || $format === 'prometheus' || $format === 'openmetrics') return false;
		if ($format === 'html') return true;
		$accept = strtolower((string) ($parsed['headers']['accept'] ?? ''));
		if ($accept === '') return false;
		$html = -1.0; $text = -1.0;
		foreach (explode(',', $accept) as $part) {
			$bits = array_map('trim', explode(';', $part));
			$type = array_shift($bits);
			$q = 1.0;
			foreach ($bits as $b) {
				if (strncmp($b, 'q=', 2) === 0) $q = (float) substr($b, 2);
			}
			if ($type === 'text/html' || $type === 'application/xhtml+xml') $html = max($html, $q);
			elseif ($type === 'text/plain' || strpos($type, 'openmetrics') !== false) $text = max($text, $q);
		}
		return $html > 0 && $html > $text;
	}

	/**
	 * The answer to /Q/metrics: the Prometheus text exactly as scrapers have
	 * always had it, or, for a browser, the same text shown in the metrics
	 * view. Both are built from one call, so they cannot disagree.
	 * @method metricsResponse
	 * @static
	 * @param {array} $parsed
	 * @return {array} status, body, headers
	 */
	static function metricsResponse($parsed)
	{
		if (!class_exists('Q_WebServer_Metrics', false)) {
			return array('status' => 404, 'body' => 'Metrics not enabled',
				'headers' => array('Content-Type' => 'text/plain; charset=utf-8'));
		}
		$text = Q_WebServer_Metrics::prometheus();
		if (self::metricsWantsHtml($parsed)) {
			$page = Q_WebServer_Design::render('metrics', array(
				'brand'       => htmlspecialchars(self::brand(), ENT_QUOTES, 'UTF-8'),
				'brandHead'   => Q_WebServer_Brand::headTags(self::brand() . ' Metrics', '/Q/metrics'),
				'metricsJson' => json_encode((string) $text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
			));
			if ($page !== null) {
				return array('status' => 200, 'body' => Q_WebServer_Shell::decorate($page),
					'headers' => array('Content-Type' => 'text/html; charset=utf-8',
						'Cache-Control' => 'no-store', 'Vary' => 'Accept'));
			}
		}
		return array('status' => 200, 'body' => $text,
			'headers' => array('Content-Type' => 'text/plain; version=0.0.4', 'Vary' => 'Accept'));
	}
	private static function renderDocsViewer()
	{
		// The page is a design on disk -- designs/default/docs/ (page.html,
		// style.css, script.js), or the same files in the configuration
		// directory's designs/ -- byte for byte what this used to
		// concatenate here.
		$page = Q_WebServer_Design::render('docs', array(
			'brand'     => htmlspecialchars(self::brand(), ENT_QUOTES, 'UTF-8'),
			'brandHead' => Q_WebServer_Brand::headTags(self::brand() . ' — Documentation', '/Q/docs'),
		));
		return $page !== null ? Q_WebServer_Shell::decorate($page)
			: '<!DOCTYPE html><html><body><p>The documentation design is missing (designs/default/docs).</p></body></html>';
	}

	// ── Path resolution ──────────────────────────────────

	/**
	 * Parse a Cookie header string into an associative array
	 * @method parseCookieHeader
	 * @static
	 * @param {string} $header The raw Cookie header value
	 * @return {array} name => value pairs
	 */
	static function parseCookieHeader($header)
	{
		$cookies = array();
		if (empty($header)) return $cookies;
		$pairs = explode(';', $header);
		foreach ($pairs as $pair) {
			$pair = trim($pair);
			if ($pair === '') continue;
			$eq = strpos($pair, '=');
			if ($eq === false) {
				$cookies[$pair] = '';
			} else {
				$name = trim(substr($pair, 0, $eq));
				$value = trim(substr($pair, $eq + 1));
				$cookies[$name] = urldecode($value);
			}
		}
		return $cookies;
	}

	/**
	 * Check rate limit for a client IP. Returns true if allowed, false if over limit.
	 * Configured via Q.webserver.rateLimit:
	 *   { "enabled": true, "requests": 100, "window": 60, "burstRequests": 20, "burstWindow": 1 }
	 * @method checkRateLimit
	 * @static
	 * @param {string} $ip Client IP address
	 * @return {boolean} true if request is allowed
	 */
	static function checkRateLimit($ip)
	{
		static $rateLimitEnabled = null;
		if ($rateLimitEnabled === null) $rateLimitEnabled = Q_Config::get('Q', 'webserver', 'rateLimit', 'enabled', false);
		if (!$rateLimitEnabled) {
			return true;
		}
		$now = time();
		static $maxReqs = null;
		if ($maxReqs === null) $maxReqs = Q_Config::get('Q', 'webserver', 'rateLimit', 'requests', 100);
		static $window = null;
		if ($window === null) $window = Q_Config::get('Q', 'webserver', 'rateLimit', 'window', 60);
		static $burstReqs = null;
		if ($burstReqs === null) $burstReqs = Q_Config::get('Q', 'webserver', 'rateLimit', 'burstRequests', 20);
		static $burstWindow = null;
		if ($burstWindow === null) $burstWindow = Q_Config::get('Q', 'webserver', 'rateLimit', 'burstWindow', 1);

		// Clean old entries
		if (!isset(self::$rateLimitData[$ip])) {
			self::$rateLimitData[$ip] = array();
		}
		$hits = &self::$rateLimitData[$ip];
		$cutoff = $now - $window;
		$hits = array_filter($hits, function ($t) use ($cutoff) {
			return $t >= $cutoff;
		});

		// Check window limit
		if (count($hits) >= $maxReqs) {
			return false;
		}

		// Check burst limit
		$burstCutoff = $now - $burstWindow;
		$recent = array_filter($hits, function ($t) use ($burstCutoff) {
			return $t >= $burstCutoff;
		});
		if (count($recent) >= $burstReqs) {
			return false;
		}

		$hits[] = $now;

		// Periodic cleanup: remove IPs not seen in the last window
		if (mt_rand(0, 99) < 5) { // 5% chance per request
			foreach (self::$rateLimitData as $k => $v) {
				if (empty($v) || max($v) < $cutoff) {
					unset(self::$rateLimitData[$k]);
				}
			}
		}

		return true;
	}


	/**
	 * Split a URL path into an existing .php script plus the remaining PATH_INFO.
	 * Mirrors nginx's fastcgi_split_path_info: walk the segments and return the
	 * LONGEST prefix that is an actual .php file on disk.
	 * @return {array|null} ['scriptPath'=>..., 'pathInfo'=>...] or null
	 */
	protected static function splitPathInfo($path)
	{
		$segments = explode('/', trim($path, '/'));
		$prefix = '';
		for ($i = 0; $i < count($segments); ++$i) {
			$prefix .= '/' . $segments[$i];
			if (substr($prefix, -4) !== '.php') {
				continue;
			}
			// Resolve against the document root directly. resolveStatic() applies
			// extension allow-listing and a realpath cache that reject a bare
			// script prefix, so it cannot be used here.
			$candidate = rtrim(self::$rootDir, '/\\') . $prefix;
			if (is_file($candidate)) {
				$rest = implode('/', array_slice($segments, $i + 1));
				return array(
					'scriptPath' => $candidate,
					'pathInfo'   => $rest === '' ? '' : '/' . $rest
				);
			}
		}
		return null;
	}

	private static function resolveStatic($urlPath)
	{
		// Path resolution cache — avoids repeated realpath() syscalls
		// Key includes rootDir so vhosts don't cross-contaminate
		static $pathCache = array();
		$cacheKey = self::$rootDir . $urlPath;
		if (isset($pathCache[$cacheKey])) {
			$cached = $pathCache[$cacheKey];
			// Quick mtime check for invalidation (cheaper than realpath)
			if ($cached === null || file_exists($cached)) {
				return $cached;
			}
			unset($pathCache[$cacheKey]);
		}

		$rel = str_replace('/', DS, ltrim($urlPath, '/'));
		// Block null bytes (directory traversal via null byte injection)
		if (strpos($rel, "\0") !== false) return null;

		// realpath() doesn't work on phar:// paths — use file_exists fallback
		$candidate = self::$rootDir . $rel;
		if (strpos(self::$rootDir, 'phar://') === 0) {
			$fsPath = file_exists($candidate) ? $candidate : false;
		} else {
			$fsPath = realpath($candidate);
		}

		// If file not found, check for shortcuts/aliases:
		// .lnk (Windows) or Mac alias with same name
		if (!$fsPath) {
			// Try .lnk extension
			$lnkPath = self::$rootDir . $rel . '.lnk';
			if (file_exists($lnkPath)) {
				$target = Q_WebServer_Shortcut::resolve($lnkPath);
				if ($target) $fsPath = $target;
			}
			// Try without extension — check if it's a Mac alias
			if (!$fsPath && PHP_OS_FAMILY === 'Darwin') {
				$rawPath = self::$rootDir . $rel;
				if (file_exists($rawPath) && Q_WebServer_Shortcut::isShortcut($rawPath)) {
					$target = Q_WebServer_Shortcut::resolve($rawPath);
					if ($target) $fsPath = $target;
				}
			}
			// Also check parent directories for shortcuts
			// e.g. /plugins/Users/js/Users.js where /plugins/Users is an alias
			if (!$fsPath) {
				$parts = explode(DS, $rel);
				$accumulated = self::$rootDir;
				for ($i = 0; $i < count($parts) - 1; $i++) {
					$accumulated .= $parts[$i];
					if (!file_exists($accumulated) && !is_dir($accumulated)) {
						// Check .lnk
						if (file_exists($accumulated . '.lnk')) {
							$target = Q_WebServer_Shortcut::resolve($accumulated . '.lnk');
							if ($target && is_dir($target)) {
								$remaining = implode(DS, array_slice($parts, $i + 1));
								$resolved = realpath($target . DS . $remaining);
								if ($resolved) { $fsPath = $resolved; break; }
							}
						}
						// Check Mac alias
						if (!$fsPath && PHP_OS_FAMILY === 'Darwin'
							&& file_exists($accumulated)
							&& Q_WebServer_Shortcut::isShortcut($accumulated)
						) {
							$target = Q_WebServer_Shortcut::resolve($accumulated);
							if ($target && is_dir($target)) {
								$remaining = implode(DS, array_slice($parts, $i + 1));
								$resolved = realpath($target . DS . $remaining);
								if ($resolved) { $fsPath = $resolved; break; }
							}
						}
					}
					$accumulated .= DS;
				}
			}
			if (!$fsPath) {
				if (count($pathCache) < 10000) $pathCache[$cacheKey] = null;
				return null;
			}
		}

		$fsPath = str_replace(array('/','\\'), DS, $fsPath);
		$result = (is_dir($fsPath) || is_file($fsPath)) ? $fsPath : null;
		if (count($pathCache) < 10000) $pathCache[$cacheKey] = $result;
		return $result;
	}

	/**
	 * End every output buffer that can be ended, and stop at the first that
	 * cannot.
	 *
	 * `while (ob_get_level()) ob_end_clean();` never finished when a buffer
	 * refused to go -- and the capture buffer (Q_WebServer_Capture) is made
	 * not removable on purpose, and kept for the life of the process. A second
	 * request run in the same process spun on it forever: in the parent that
	 * stopped the whole server answering, with a notice per turn in the log
	 * (37 million of them on alpha, 2026-09-24), after one request for an
	 * unknown /Q/ path. What is left is Capture's to clean (Capture::begin()).
	 *
	 * @method dropOutputBuffers
	 * @static
	 */
	static function dropOutputBuffers()
	{
		while (ob_get_level() > 0) {
			if (!@ob_end_clean()) break;
		}
	}

	static function closeClient($key)
	{
		// A live HTTP/2 connection is closed by closeHttp2(), never here: the
		// watcher under its key reads every stream on the connection, and
		// cancelling it on one request's behalf left the rest unread.
		if (isset(self::$http2[$key])) return;

		if (isset(self::$clientWatchers[$key])) {
			Q_Evented::cancel(self::$clientWatchers[$key]);
			unset(self::$clientWatchers[$key]);
		}
		if (isset(self::$timeoutWatchers[$key])) {
			Q_Evented::cancel(self::$timeoutWatchers[$key]);
			unset(self::$timeoutWatchers[$key]);
		}
		if (isset(self::$clients[$key])) {
			if (is_resource(self::$clients[$key])) @fclose(self::$clients[$key]);
			unset(self::$clients[$key]);
		}
		unset(self::$buffers[$key], self::$clientInfo[$key],
			self::$keepAliveCount[$key], self::$clientState[$key]);
	}

	/**
	 * Re-register a client socket in the event loop for keep-alive.
	 * Called by Pool after sending a response when the connection should
	 * stay open for the next request.
	 */
	static function reRegisterClient($client)
	{
		$key = (int) $client;
		if (!is_resource($client)) {
			// Socket died while queued — clean up
			unset(self::$clients[$key], self::$buffers[$key],
				self::$clientInfo[$key], self::$keepAliveCount[$key]);
			return;
		}
		// Re-register the read watcher (socket is already in $clients)
		self::$clients[$key] = $client;
		self::$buffers[$key] = '';  // reset buffer for next request
		if (isset(self::$clientWatchers[$key])) {
			Q_Evented::cancel(self::$clientWatchers[$key]);
		}
		self::$clientWatchers[$key] = Q_Evented::onReadable($client,
			function ($s) use ($client) {
				Q_WebServer::onClientData($client);
			}
		);
	}

	// ── State ────────────────────────────────────────────

	private static $socket = null;
	private static $tlsSocket = null;
	private static $tlsWatcher = null;
	private static $tlsPending = array();
	private static $httpsPort = 0;

	/** The HTTPS port being listened on, or 0. */
	static function httpsPort()
	{
		return (int) self::$httpsPort;
	}

	static $clients = array();
	static $clientWatchers = array();
	static $buffers = array();
	private static $clientInfo = array();      // key => [ip, connectTime]
	static $keepAliveCount = array();   // key => int
	private static $timeoutWatchers = array();  // key => evented timer id
	static $clientState = array();     // key => 'reading'|'waiting'|'idle'
	private static $acceptWatcher = null;
	private static $running = false;
	private static $lastStatus = 200;
	private static $lastBody = '';
	private static $lastBytes = 0;
	private static $requestKeepAlive = true;   // the current request's keep-alive decision, for sendResponse()

	// ── Response state (internal — not Q_Response, to avoid Platform collision) ──

	/** @var array Captured response headers: name => value */
	private static $_responseHeaders = array();
	/** @var integer Captured response status code */
	private static $_responseCode = 200;

	/**
	 * Output accumulated by dispatchToQ()'s callback buffer.
	 *
	 * Public because the buffer callback and the fork child's shutdown handler
	 * both need it. It cannot live in the buffer itself: Q_Dispatcher flushes
	 * and closes buffers on its way out, which would send the response to the
	 * process's stdout and leave the client with an empty body.
	 */
	static $_capturedOutput = '';

	/**
	 * Capture a response header. Called by Q_Response::header() in standalone mode,
	 * or read from headers_list() / http_response_code() in --app mode.
	 * @method setResponseHeader
	 * @static
	 */
	static function setResponseHeader($name, $value, $replace = true)
	{
		if ($replace || !isset(self::$_responseHeaders[$name])) {
			self::$_responseHeaders[$name] = $value;
		}
	}

	/**
	 * Get all captured response headers.
	 * In --app mode (Platform loaded), also reads headers_list().
	 */
	static function getResponseHeaders()
	{
		// Merge headers captured by Q_WebServer_State (webserver-owned, so it
		// exists in BOTH modes). This was previously guarded on
		// method_exists('Q_Response','getHeaders') -- a method only the
		// STANDALONE shim declares -- so under --app the guard was false and
		// every header a script set was silently dropped, even though State
		// had captured them correctly.
		if (class_exists('Q_WebServer_State', false)) {
			foreach (Q_WebServer_State::getHeaders() as $k => $v) {
				if (!isset(self::$_responseHeaders[$k])) {
					self::$_responseHeaders[$k] = $v;
				}
			}
		}
		// NOTE: headers_list() is ALWAYS empty under the CLI SAPI -- PHP
		// discards native header() calls and gives no hook to capture them.
		// So this merge is a no-op in normal operation; it only contributes
		// if the server is run under a SAPI that does record them. Scripts
		// relying on native header() must be routed to php-cgi via
		// Q.webserver.cgi.patterns -- that is the documented escape hatch.
		if (function_exists('headers_list')) {
			foreach (headers_list() as $h) {
				$pos = strpos($h, ':');
				if ($pos !== false) {
					$name = trim(substr($h, 0, $pos));
					$value = trim(substr($h, $pos + 1));
					if (!isset(self::$_responseHeaders[$name])) {
						self::$_responseHeaders[$name] = $value;
					}
				}
			}
		}
		return self::$_responseHeaders;
	}

	/**
	 * Get or set the response status code.
	 * In --app mode, also reads http_response_code().
	 */
	static function responseCode($code = null)
	{
		// Set only our own field. Q_WebServer_State::responseCode() already
		// forwards to Q_Response::code(), which calls back here -- propagating
		// in this direction too would close the cycle into infinite recursion.
		if ($code !== null) {
			self::$_responseCode = (int) $code;
			return $code;
		}
		// Q::header() and Q_Response::code() write to Q_WebServer_State, so it
		// is the authority. Reading only $_responseCode here silently dropped
		// every status a script set, and everything went out as 200.
		if (class_exists('Q_WebServer_State', false)) {
			$state = Q_WebServer_State::responseCode();
			if ($state && $state !== 200) return $state;
		}
		// Check native http_response_code (catches Platform's header() calls)
		$native = http_response_code();
		if ($native && $native !== 200) return $native;
		return self::$_responseCode;
	}

	/**
	 * Clear response state between requests in the event loop.
	 */
	static function clearResponseState()
	{
		self::$_responseHeaders = array();
		self::$_responseCode = 200;
		@header_remove();
	}
	/** @internal pid => start_time for request timeout enforcement */
	static $workerPids = array();
	/** @internal pid => exit code of children reaped here that were not workers */
	static $reaped = array();

	/**
	 * Keep the exit status of a child the SIGCHLD handler reaped but did not
	 * start (a process opened with proc_open, such as a shell job). Once it
	 * is reaped here, proc_get_status() and proc_close() can no longer see
	 * how it ended, so its owner asks reapedExit() instead.
	 * @method noteReaped
	 * @static
	 * @param {integer} $pid
	 * @param {integer} $status the raw status from waitpid()
	 */
	static function noteReaped($pid, $status)
	{
		if (!function_exists('pcntl_wifexited')) return;
		$code = pcntl_wifexited($status) ? pcntl_wexitstatus($status)
			: (pcntl_wifsignaled($status) ? 128 + pcntl_wtermsig($status) : 1);
		self::$reaped[(int) $pid] = (int) $code;
		// Bounded: an owner that never asks must not grow this for ever.
		if (count(self::$reaped) > 512) self::$reaped = array_slice(self::$reaped, -256, null, true);
	}

	/**
	 * The exit code of a child reaped by the SIGCHLD handler (and forget it),
	 * or null when it was not reaped there.
	 * @method reapedExit
	 * @static
	 * @param {integer} $pid
	 * @return {integer|null}
	 */
	static function reapedExit($pid)
	{
		$pid = (int) $pid;
		if (!isset(self::$reaped[$pid])) return null;
		$code = self::$reaped[$pid];
		unset(self::$reaped[$pid]);
		return $code;
	}
	/** @var integer Max POST body size in bytes (from post_max_size ini) */
	static $maxPostSize = 8388608; // 8M default
	/** @var integer Max upload file size in bytes (from upload_max_filesize ini) */
	static $maxUploadSize = 2097152; // 2M default
	/** @var integer Max number of files per upload (from max_file_uploads ini) */
	static $maxFileUploads = 20;
	/** @var boolean Whether file uploads are enabled (from file_uploads ini) */
	static $fileUploads = true;
	/** @var array Temp files created by multipart parsing — cleaned on request end */
	static $uploadTempFiles = array();

	static $allowedExtensions = array(
		// 'log' was here. A log file is never something a site means to
		// serve, and a document root that is also the project root puts
		// var/log/error.log one request away -- ninety kilobytes of paths,
		// queries and stack traces. Nothing references a .log, and an
		// installation that really wants to serve one can add it back through
		// Q.webserver.extensions.
		'html','htm','txt','md','json','xml','yaml','yml','csv','tsv',
		'css','js','mjs','map','wasm',
		'png','gif','webp','jpg','jpeg','svg','bmp','ico','avif',
		'woff','woff2','ttf','otf',
		'mp3','wav','ogg','mp4','webm',
		'pdf','zip'
	);
	private static $rateLimitData = array(); // ip => [timestamps]

	// ── Static file response cache ──────────────────────
	// Caches full response bytes (headers+body) keyed by fsPath.
	// Invalidated on mtime change. Saves stat/read/header-build per request.
	private static $fileCache = array();     // fsPath => [mtime, size, etag, responses => [connType => bytes]]
	private static $fileCacheSize = 0;       // total bytes in cache
	private static $fileCacheMaxSize = 67108864; // 64MB default, configurable
	private static $fileCacheMaxFile = 1048576;  // don't cache files > 1MB
	private static $fileCacheCheckInterval = 1;  // seconds between mtime checks
	private static $fileCacheLastCheck = 0;

	/**
	 * Parse a PHP ini byte value like "8M", "128K", "1G" into bytes.
	 * @method parseIniBytes
	 * @static
	 * @param {string|integer} $value
	 * @return {integer}
	 */
	static function parseIniBytes($value)
	{
		if (is_numeric($value)) return (int) $value;
		$value = trim($value);
		$num = (int) $value;
		$suffix = strtoupper(substr($value, -1));
		switch ($suffix) {
			case 'G': $num *= 1073741824; break;
			case 'M': $num *= 1048576; break;
			case 'K': $num *= 1024; break;
		}
		return $num;
	}

	/**
	 * Remove temp files created during multipart upload parsing.
	 * Called after each request in both in-process and fork modes.
	 * @method cleanupUploadFiles
	 * @static
	 */
	/**
	 * Handle bulk image download as ZIP.
	 * Accepts POST with files[] JSON array of image URLs (with ?w= params).
	 * Generates/caches each image, then streams a ZIP.
	 */
	// ── PHPDoc / YUIDoc parsing for API discovery ────────

	/**
	 * Parse PHPDoc or YUIDoc block from a handler file.
	 * Supports both styles and degrades gracefully on malformed input.
	 *
	 * PHPDoc:  @param string $name Description
	 * YUIDoc:  @param {String} name Description
	 * YUIDoc:  @param name {String} Description
	 * YUIDoc:  @param {Object} [name=default] Description (optional)
	 *
	 * @method parseHandlerDoc
	 * @static
	 */
	static function parseHandlerDoc($filePath)
	{
		$result = array(
			'summary' => '',
			'description' => '',
			'params' => array(),
			'return' => '',
			'method' => '',
			'deprecated' => false,
		);

		try {
			$source = @file_get_contents($filePath, false, null, 0, 16384);
		} catch (\Exception $e) {
			return $result;
		}
		if (!$source) return $result;

		// Find /** ... */ blocks
		if (!preg_match_all('/\/\*\*\s*(.*?)\s*\*\//s', $source, $matches)) {
			// No docblock — try first // comment line(s) as summary
			if (preg_match_all('/^\s*\/\/\s*(.+)/m', $source, $commentMatches)) {
				// Skip shebangs and path comments (// handlers/foo/bar.php)
				foreach ($commentMatches[1] as $comment) {
					$comment = trim($comment);
					if ($comment === '' || strpos($comment, '#!/') === 0) continue;
					if (preg_match('#^[\w/]+\.php#', $comment)) continue;
					$result['summary'] = $comment;
					break;
				}
			}
			return $result;
		}

		// Pick the docblock closest to (and before) the first function
		$funcPos = strpos($source, 'function ');
		$bestBlock = '';
		if ($funcPos !== false) {
			$bestDist = PHP_INT_MAX;
			foreach ($matches[0] as $i => $fullMatch) {
				$blockPos = strpos($source, $fullMatch);
				$blockEnd = $blockPos + strlen($fullMatch);
				$dist = $funcPos - $blockEnd;
				if ($dist >= 0 && $dist < $bestDist) {
					$bestDist = $dist;
					$bestBlock = $matches[1][$i];
				}
			}
		}
		if (!$bestBlock) $bestBlock = $matches[1][0] ?? '';
		if (!$bestBlock) return $result;

		// Clean: strip leading * from each line
		$lines = preg_split('/\r?\n/', $bestBlock);
		$cleaned = array();
		foreach ($lines as $line) {
			$cleaned[] = preg_replace('/^\s*\*\s?/', '', $line);
		}

		// Split into prose (summary + description) and tags
		$proseLines = array();
		$tagLines = array();
		$inTags = false;
		foreach ($cleaned as $line) {
			$trimmed = trim($line);
			if ($trimmed !== '' && $trimmed[0] === '@') {
				$inTags = true;
				$tagLines[] = $trimmed;
			} elseif ($inTags && $trimmed !== '') {
				// Continuation line for the previous tag
				if ($tagLines) {
					$tagLines[count($tagLines) - 1] .= ' ' . $trimmed;
				}
			} else {
				if (!$inTags) $proseLines[] = $trimmed;
			}
		}

		// Summary = first non-empty line(s) up to first blank line
		// Description = everything after the first blank line
		$summary = array();
		$desc = array();
		$pastBlank = false;
		foreach ($proseLines as $line) {
			if (!$pastBlank && $line === '' && $summary) {
				$pastBlank = true;
				continue;
			}
			if ($pastBlank) {
				$desc[] = $line;
			} else if ($line !== '') {
				$summary[] = $line;
			}
		}
		$result['summary'] = trim(implode(' ', $summary));
		$result['description'] = trim(implode(' ', $desc));

		// Parse tags — each pattern wrapped in try/catch for graceful degradation
		foreach ($tagLines as $tag) {
			try {
				$parsed = self::parseDocTag($tag);
				if (!$parsed) continue;

				switch ($parsed['tag']) {
					case 'param':
						// Skip the handler signature params ($params, $result)
						if (in_array($parsed['name'], array('params', 'result', ''))) continue 2;
						$result['params'][] = $parsed;
						break;
					case 'return':
					case 'returns':
						$result['return'] = $parsed['description']
							? ($parsed['type'] ? '{' . $parsed['type'] . '} ' : '') . $parsed['description']
							: ($parsed['type'] ?: '');
						break;
					case 'method':
						$result['method'] = $parsed['value'] ?? '';
						break;
					case 'deprecated':
						$result['deprecated'] = true;
						break;
					case 'private':
					case 'internal':
						$result['private'] = true;
						break;
				}
			} catch (\Exception $e) {
				// Malformed tag — skip it silently
				continue;
			}
		}

		return $result;
	}

	/**
	 * Parse a single @tag line. Handles PHPDoc and YUIDoc variants.
	 * Returns null for unrecognized tags.
	 * @method parseDocTag
	 * @static
	 */
	static function parseDocTag($tag)
	{
		if (!$tag || $tag[0] !== '@') return null;

		// Extract tag name
		if (!preg_match('/^@(\w+)\s*(.*)/s', $tag, $base)) return null;
		$tagName = strtolower($base[1]);
		$rest = trim($base[2]);

		// --- @param variants ---
		if ($tagName === 'param') {
			$name = ''; $type = ''; $desc = ''; $optional = false; $default = null;

			// PHPDoc: @param string $name Description
			if (preg_match('/^(\S+)\s+\$(\w+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $name = $m[2]; $desc = trim($m[3]);
			}
			// YUIDoc: @param {Type} name Description
			// YUIDoc: @param {Type} [name] Description (optional)
			// YUIDoc: @param {Type} [name=default] Description
			elseif (preg_match('/^\{([^}]*)\}\s+(\[?)(\$?\w[\w.]*)\]?(?:\s*=\s*(\S+))?\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $optional = ($m[2] === '['); $name = ltrim($m[3], '$'); $default = $m[4] ?? null; $desc = trim($m[5]);
			}
			// YUIDoc alternate: @param name {Type} Description
			elseif (preg_match('/^(\[?)(\$?\w[\w.]*)\]?(?:\s*=\s*\S+)?\s+\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$optional = ($m[1] === '['); $name = ltrim($m[2], '$'); $type = $m[3]; $desc = trim($m[4]);
			}
			// Bare: @param $name Description (no type)
			elseif (preg_match('/^\$(\w+)\s*(.*)/s', $rest, $m)) {
				$name = $m[1]; $desc = trim($m[2]);
			}
			// Bare YUIDoc: @param name Description (no type)
			elseif (preg_match('/^(\w+)\s+(.*)/s', $rest, $m)) {
				$name = $m[1]; $desc = trim($m[2]);
			}
			else {
				return null; // Unparseable — skip gracefully
			}

			// Detect optional from type or description
			if (strpos($type, '?') === 0 || preg_match('/\bnull\b/i', $type)) {
				$optional = true;
				$type = preg_replace('/^\?|(\|null|null\|)/i', '', $type);
			}
			if ($default !== null) $optional = true;

			// Strip nested param prefix (config.name → name) for dotted YUIDoc params
			// but keep the full name for context
			$type = trim($type);

			return array('tag' => 'param', 'name' => $name, 'type' => $type,
				'description' => $desc, 'optional' => $optional, 'default' => $default);
		}

		// --- @return / @returns ---
		if ($tagName === 'return' || $tagName === 'returns') {
			$type = ''; $desc = '';
			// {Type} Description
			if (preg_match('/^\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			// Type Description (PHPDoc)
			elseif (preg_match('/^(\S+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			return array('tag' => 'return', 'type' => $type, 'description' => $desc);
		}

		// --- @method ---
		if ($tagName === 'method') {
			return array('tag' => 'method', 'value' => $rest);
		}

		// --- @deprecated ---
		if ($tagName === 'deprecated') {
			return array('tag' => 'deprecated', 'value' => $rest);
		}

		// --- @private / @internal / @access private ---
		if ($tagName === 'private' || $tagName === 'internal') {
			return array('tag' => 'private', 'value' => $rest);
		}
		if ($tagName === 'access' && strtolower(trim($rest)) === 'private') {
			return array('tag' => 'private', 'value' => '');
		}

		// --- @throws / @throw ---
		if ($tagName === 'throws' || $tagName === 'throw') {
			$type = ''; $desc = '';
			if (preg_match('/^\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			} elseif (preg_match('/^(\S+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			return array('tag' => 'throws', 'type' => $type, 'description' => $desc);
		}

		// Unknown tag — skip
		return null;
	}

	/**
	 * Map PHP type annotations to JSON Schema types.
	 * @method phpTypeToJsonSchema
	 * @static
	 */
	static function phpTypeToJsonSchema($phpType)
	{
		$phpType = strtolower(trim($phpType));
		// Strip nullable prefix
		$phpType = ltrim($phpType, '?');

		$map = array(
			'string' => 'string',
			'int' => 'integer', 'integer' => 'integer',
			'float' => 'number', 'double' => 'number', 'number' => 'number',
			'bool' => 'boolean', 'boolean' => 'boolean',
			'array' => 'object',
			'object' => 'object',
			'mixed' => 'string',
		);

		// Handle array<type> or type[]
		if (preg_match('/^array\s*<\s*(.+)\s*>$/i', $phpType, $m) || preg_match('/^(\w+)\[\]$/', $phpType, $m)) {
			return 'array';
		}

		return $map[$phpType] ?? 'string';
	}

	/**
	 * Check if a handler should be hidden from API discovery.
	 * @method isHandlerHidden
	 * @static
	 * @param {string} $eventName e.g. "admin/cleanup"
	 * @param {array} $patterns Patterns from Q.api.discover.hidden config
	 * @return {boolean}
	 */
	static function isHandlerHidden($eventName, $patterns)
	{
		foreach ($patterns as $pattern => $hidden) {
			if ($hidden && @preg_match(self::ensureRegex($pattern), $eventName)) return true;
		}
		return false;
	}

	// ── .well-known endpoints ────────────────────────────

	/**
	 * Qbix server manifest — server identity, fingerprint, capabilities.
	 */
	static function wellKnownQbix($parsed)
	{
		if (Q_Config::get('Q', 'federation', 'advertise', true) === false) {
			return array('status' => 404, 'body' => 'Not found');
		}
		$host = $parsed['headers']['host'] ?? 'localhost';
		$identity = Q_WebServer_Identity::serverIdentity();
		$info = array(
			'server' => self::brand(),
			'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
			'fingerprint' => $identity ? $identity['fingerprint'] : null,
			'endpoints' => array(
				'event' => '/Q/event',
				'health' => '/Q/health',
				'openapi' => '/.well-known/openapi.json',
				'mcp' => '/.well-known/mcp.json',
				'websocket' => '/Q/ws',
			),
			'links' => array(
				'openapi' => 'https://' . $host . '/.well-known/openapi.json',
				'mcp' => 'https://' . $host . '/.well-known/mcp.json',
				'health' => 'https://' . $host . '/Q/health',
				'openclaiming' => 'https://' . $host . '/.well-known/openclaiming/' . preg_replace('/:\d+$/', '', $host) . '/server.json',
			),
		);
		if (Q_Config::get('Q', 'federation', 'advertiseApps', false)) {
			$apps = Q_WebServer_Panel::apiListApps();
			$info['apps'] = array_map(function ($a) {
				return array('name' => $a['name'], 'plugins' => $a['plugins'] ?? array());
			}, $apps['apps'] ?? array());
		}
		if (Q_Config::get('Q', 'federation', 'advertisePlugins', true)) {
			$plugins = Q_WebServer_Panel::apiListPlugins();
			$info['plugins'] = array_map(function ($p) {
				return array('name' => $p['name'], 'version' => $p['version'] ?? null);
			}, $plugins['plugins'] ?? array());
		}
		return array('status' => 200,
			'body' => json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * OpenAPI 3.1 spec — compatible with Swagger UI, Postman, Redoc.
	 * Auto-generated from the server's actual endpoints.
	 */
	static function wellKnownOpenAPI($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$spec = array(
			'openapi' => '3.1.0',
			'info' => array(
				'title' => self::brand() . ' API',
				'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
				'description' => 'Auto-generated API spec for this ' . self::brand() . ' instance.',
				'contact' => array('url' => 'https://github.com/Qbix/Server'),
			),
			'servers' => array(
				array('url' => 'https://' . $host, 'description' => 'This server'),
			),
			'paths' => array(
				'/Q/health' => array(
					'get' => array(
						'summary' => 'Server health check',
						'operationId' => 'health',
						'tags' => array('System'),
						'responses' => array(
							'200' => array('description' => 'Server status',
								'content' => array('application/json' => array(
									'schema' => array('type' => 'object',
										'properties' => array(
											'status' => array('type' => 'string', 'example' => 'ok'),
											'uptimeSec' => array('type' => 'integer'),
											'memory' => array('type' => 'number'),
											'php' => array('type' => 'string'),
										))))),
						),
					),
				),
				'/Q/event' => array(
					'post' => array(
						'summary' => 'Forward an event to this server',
						'operationId' => 'event',
						'tags' => array('Federation'),
						'description' => 'Dispatches a Q::event() on this server. Requests must be signed with Q_Utils::sign().',
						'requestBody' => array(
							'required' => true,
							'content' => array('application/json' => array(
								'schema' => array('type' => 'object',
									'required' => array('event'),
									'properties' => array(
										'event' => array('type' => 'string', 'example' => 'Users/login'),
										'params' => array('type' => 'object'),
										'_msgId' => array('type' => 'string', 'description' => 'Unique message ID for loop prevention'),
										'Q.sig' => array('type' => 'string', 'description' => 'HMAC-SHA1 signature'),
									)))),
						),
						'responses' => array(
							'200' => array('description' => 'Event result'),
							'401' => array('description' => 'Invalid signature'),
							'403' => array('description' => 'Unknown peer'),
						),
					),
				),
				'/.well-known/qbix.json' => array(
					'get' => array(
						'summary' => 'Server discovery manifest',
						'operationId' => 'discovery',
						'tags' => array('Discovery'),
						'responses' => array(
							'200' => array('description' => 'Server identity, fingerprint, and capabilities'),
						),
					),
				),
			),
			'tags' => array(
				array('name' => 'System', 'description' => 'Server status and management'),
				array('name' => 'Federation', 'description' => 'Inter-server event forwarding'),
				array('name' => 'Discovery', 'description' => 'Server identity and API discovery'),
			),
		);

		// Add app-defined routes from handlers/ directory, parsed from PHPDoc
		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir)) . DS . 'handlers';
		if (is_dir($handlersDir)) {
			$hiddenPatterns = Q_Config::get('Q', 'api', 'discover', 'hidden', array());
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($handlersDir, RecursiveDirectoryIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if ($file->getExtension() !== 'php') continue;
				$rel = str_replace(DS, '/', substr($file->getPathname(), strlen($handlersDir) + 1));
				$eventName = str_replace('.php', '', $rel);
				$doc = self::parseHandlerDoc($file->getPathname());

				// Skip private/internal handlers
				if (!empty($doc['private'])) continue;
				// Skip config-hidden paths
				if (self::isHandlerHidden($eventName, $hiddenPatterns)) continue;

				$properties = array();
				$required = array();
				foreach ($doc['params'] as $p) {
					$prop = array('description' => $p['description']);
					if ($p['type']) $prop['type'] = self::phpTypeToJsonSchema($p['type']);
					$properties[$p['name']] = $prop;
					if (!$p['optional']) $required[] = $p['name'];
				}

				$schema = array('type' => 'object');
				if ($properties) $schema['properties'] = $properties;
				if ($required) $schema['required'] = $required;

				$operation = array(
					'summary' => $doc['summary'] ?: "Handler: $eventName",
					'operationId' => str_replace('/', '_', $eventName),
					'tags' => array('Handlers'),
					'description' => ($doc['description'] ? $doc['description'] . "\n\n" : '')
						. "Dispatch via POST /Q/event with {\"event\": \"$eventName\", \"params\": {...}}",
					'requestBody' => array(
						'content' => array('application/json' => array(
							'schema' => array('type' => 'object', 'properties' => array(
								'event' => array('type' => 'string', 'example' => $eventName),
								'params' => $schema,
							)),
						)),
					),
					'responses' => array('200' => array(
						'description' => $doc['return'] ?: 'Handler result',
					)),
				);

				$spec['paths']['/handler/' . $eventName] = array('post' => $operation);
			}
			if (count($spec['paths']) > 3) {
				$spec['tags'][] = array('name' => 'Handlers', 'description' => 'App event handlers');
			}
		}

		return array('status' => 200,
			'body' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * MCP (Model Context Protocol) server manifest.
	 * Allows AI tools (Claude, etc.) to discover and call this server's APIs.
	 */
	static function wellKnownMCP($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';

		// Discover available tools from handlers
		$tools = array();

		// Built-in tools
		$tools[] = array(
			'name' => 'health',
			'description' => 'Check server health and uptime',
			'inputSchema' => array('type' => 'object', 'properties' => new \stdClass()),
		);
		$tools[] = array(
			'name' => 'event',
			'description' => 'Dispatch a Q::event() on this server',
			'inputSchema' => array(
				'type' => 'object',
				'required' => array('event'),
				'properties' => array(
					'event' => array('type' => 'string', 'description' => 'Event name (e.g. Users/login)'),
					'params' => array('type' => 'object', 'description' => 'Event parameters'),
				),
			),
		);

		// Add handler-based tools with PHPDoc metadata
		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir)) . DS . 'handlers';
		if (is_dir($handlersDir)) {
			$hiddenPatterns = Q_Config::get('Q', 'api', 'discover', 'hidden', array());
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($handlersDir, RecursiveDirectoryIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if ($file->getExtension() !== 'php') continue;
				$rel = str_replace(DS, '/', substr($file->getPathname(), strlen($handlersDir) + 1));
				$eventName = str_replace('.php', '', $rel);
				$doc = self::parseHandlerDoc($file->getPathname());

				// Skip private/internal handlers
				if (!empty($doc['private'])) continue;
				if (self::isHandlerHidden($eventName, $hiddenPatterns)) continue;

				$properties = array();
				$required = array();
				foreach ($doc['params'] as $p) {
					$prop = array('description' => $p['description']);
					if ($p['type']) $prop['type'] = self::phpTypeToJsonSchema($p['type']);
					$properties[$p['name']] = $prop;
					if (!$p['optional']) $required[] = $p['name'];
				}

				$schema = array('type' => 'object');
				if ($properties) $schema['properties'] = $properties;
				if ($required) $schema['required'] = $required;

				$description = $doc['summary'] ?: "Dispatch event: $eventName";
				if ($doc['description']) {
					$description .= ' — ' . $doc['description'];
				}

				$tools[] = array(
					'name' => str_replace('/', '_', $eventName),
					'description' => $description,
					'inputSchema' => $schema,
				);
			}
		}

		$manifest = array(
			'schema_version' => '2025-01-01',
			'name' => 'qbix-server',
			'display_name' => self::brand() . ' on ' . $host,
			'description' => self::brand() . ' instance — PHP web server with WebSocket, rooms, and federation.',
			'url' => 'https://' . $host,
			'provider' => array(
				'name' => 'Qbix',
				'url' => 'https://qbix.com',
			),
			'tools' => $tools,
			'links' => array(
				'openapi' => 'https://' . $host . '/.well-known/openapi.json',
				'health' => 'https://' . $host . '/Q/health',
			),
		);

		return array('status' => 200,
			'body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * OpenClaiming endpoint. Serves signed claims from three sources:
	 *
	 * 1. claims/{domain}/{name}.php  → dynamic, auto-signed by server
	 * 2. claims/{domain}/{name}.json → template, auto-signed by server
	 * 3. web/.well-known/openclaiming/{domain}/{name}.json → pre-signed, as-is
	 *
	 * Server auto-generates its own claim at {hostname}/server.json.
	 * Signed claims are cached in files/Q/cached/claims/ keyed by mtime.
	 */
	static function wellKnownOpenClaiming($parsed, $wellKnown)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostname = preg_replace('/:\d+$/', '', $host);

		// Strip "openclaiming/" prefix
		$claimPath = substr($wellKnown, strlen('openclaiming/'));
		if (!$claimPath) return null;

		// Security: block traversal
		if (strpos($claimPath, '..') !== false) {
			return array('status' => 400, 'body' => 'Invalid path');
		}

		// 1. Auto-generated server identity claim
		if ($claimPath === $hostname . '/server.json' || $claimPath === 'server.json') {
			$claim = Q_WebServer_Identity::serverClaim($hostname);
			if (!$claim) {
				return array('status' => 500,
					'body' => json_encode(array('error' => 'Could not generate claim')),
					'headers' => array('Content-Type' => 'application/json'));
			}
			return self::claimResponse($claim);
		}

		// Find base directories
		$base = defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir);
		$claimsDir = $base . DS . 'claims';
		$cacheDir = $base . DS . 'files' . DS . 'Q' . DS . 'cached' . DS . 'claims';

		// Strip .json extension for lookup
		$lookupPath = preg_replace('/\.json$/', '', $claimPath);
		$safePath = str_replace('/', DS, $lookupPath);

		// 2. PHP claim (dynamic, auto-signed)
		$phpFile = $claimsDir . DS . $safePath . '.php';
		if (file_exists($phpFile)) {
			$params = $parsed['query'] ?? '';
			$queryParams = array();
			if ($params) parse_str($params, $queryParams);
			$claim = self::loadClaimPhp($phpFile, $queryParams);
			if ($claim) {
				$claim = Q_WebServer_Identity::signClaim($claim);
				return self::claimResponse($claim);
			}
		}

		// 3. JSON template (static, auto-signed with caching)
		$jsonFile = $claimsDir . DS . $safePath . '.json';
		if (file_exists($jsonFile)) {
			$mtime = filemtime($jsonFile);
			$cacheFile = $cacheDir . DS . $safePath . '.' . $mtime . '.json';

			// Cache hit
			if (file_exists($cacheFile)) {
				$cached = json_decode(file_get_contents($cacheFile), true);
				if ($cached) return self::claimResponse($cached);
			}

			// Sign and cache
			$template = json_decode(file_get_contents($jsonFile), true);
			if ($template) {
				$signed = Q_WebServer_Identity::signClaim($template);
				$dir = dirname($cacheFile);
				if (!is_dir($dir)) @mkdir($dir, 0755, true);
				file_put_contents($cacheFile, json_encode($signed, JSON_UNESCAPED_SLASHES));
				return self::claimResponse($signed);
			}
		}

		// 4. Fall through to static file serving (pre-signed claims in web/)
		return null;
	}

	private static function loadClaimPhp($file, $params = array())
	{
		try {
			$claim = include $file;
			if (is_array($claim)) return $claim;
		} catch (\Exception $e) {
			// Log but don't crash
		}
		return null;
	}

	private static function claimResponse($claim)
	{
		return array('status' => 200,
			'body' => json_encode($claim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array(
				'Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*',
				'Cache-Control' => 'public, max-age=300',
			));
	}

	static function handleBulkImageZip($client, $parsed)
	{
		if (!class_exists('ZipArchive')) {
			self::sendResponse($client, 500, 'ZipArchive extension not available');
			return;
		}

		// Parse form data
		$body = $parsed['body'] ?? '';
		parse_str($body, $post);
		$files = json_decode($post['files'] ?? '[]', true);
		if (!$files || !is_array($files) || count($files) > 200) {
			self::sendResponse($client, 400, 'Invalid files list');
			return;
		}

		$tmpZip = tempnam(sys_get_temp_dir(), 'qbix_zip_') . '.zip';
		$zip = new \ZipArchive();
		if ($zip->open($tmpZip, \ZipArchive::CREATE) !== true) {
			self::sendResponse($client, 500, 'Cannot create ZIP');
			return;
		}

		$rename = !empty($post['rename']) && $post['rename'] !== '0';

		foreach ($files as $url) {
			// Parse URL into path + query
			$parts = parse_url($url);
			$path = $parts['path'] ?? '';
			$query = $parts['query'] ?? '';
			$qp = array();
			if ($query) parse_str($query, $qp);

			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$fsPath = self::resolveStatic($path);

			// Try image handler (resize/convert)
			$fakeParsed = array(
				'path' => $path,
				'query' => $query,
				'headers' => $parsed['headers'] ?? array(),
			);
			$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $fakeParsed);

			if ($imgResponse && !empty($imgResponse['body'])) {
				$filename = pathinfo($path, PATHINFO_FILENAME) . '.' . $ext;
				if ($rename && !empty($qp['w'])) {
					$filename = pathinfo($path, PATHINFO_FILENAME) . '-' . $qp['w'] . 'w.' . $ext;
				}
				$zip->addFromString($filename, $imgResponse['body']);
			} elseif ($fsPath && is_file($fsPath)) {
				$zip->addFile($fsPath, pathinfo($path, PATHINFO_BASENAME));
			}
		}

		$zip->close();

		if (!file_exists($tmpZip)) {
			self::sendResponse($client, 500, 'ZIP creation failed');
			return;
		}

		$zipData = file_get_contents($tmpZip);
		@unlink($tmpZip);

		self::sendResponse($client, 200, $zipData, 'application/zip', array(
			'Content-Disposition' => 'attachment; filename="images.zip"',
			'Content-Length' => (string) strlen($zipData),
		));
	}

	static function cleanupUploadFiles()
	{
		foreach (self::$uploadTempFiles as $f) {
			if ($f && file_exists($f)) @unlink($f);
		}
		self::$uploadTempFiles = array();
	}

	/**
	 * Handle an incoming remote event forwarded from another Qbix server.
	 * Verifies HMAC signature if internal secret is configured.
	 * @method handleRemoteEvent
	 * @static
	 */
	/** @var array Message IDs already processed — prevents loops in federation */
	static $seenMessages = array();
	/** @var integer TTL for seen messages in seconds */
	static $seenMessageTTL = 3600; // 1 hour

	static function handleRemoteEvent($parsed)
	{
		$body = json_decode($parsed['body'], true);
		if (!$body || empty($body['event'])) {
			return array('status' => 400,
				'body' => json_encode(array('error' => 'Missing event')),
				'headers' => array('Content-Type' => 'application/json'));
		}

		// Per-message loop prevention
		$msgId = $body['_msgId'] ?? '';
		if ($msgId) {
			// Evict expired entries
			$now = time();
			foreach (self::$seenMessages as $id => $ts) {
				if ($now - $ts > self::$seenMessageTTL) unset(self::$seenMessages[$id]);
			}
			// Check if already seen
			if (isset(self::$seenMessages[$msgId])) {
				return array('status' => 200,
					'body' => json_encode(array('_duplicate' => true, '_msgId' => $msgId)),
					'headers' => array('Content-Type' => 'application/json'));
			}
			// Mark as seen
			self::$seenMessages[$msgId] = $now;
		}

		// Verify signature — check X-Q-HMAC header (Platform convention)
		// or Q.sig in body (Q_Utils::sign convention). Either is valid.
		$secret = Q_Config::get('Q', 'internal', 'secret', null);
		if ($secret) {
			$hmacHeader = $parsed['headers']['x-q-hmac'] ?? '';
			$bodyValid = Q_WebServer_Identity::verify($body, $secret);
			$headerValid = $hmacHeader && hash_equals(
				hash_hmac('sha1', $parsed['body'], $secret), $hmacHeader
			);
			if (!$bodyValid && !$headerValid) {
				return array('status' => 401,
					'body' => json_encode(array('error' => 'Invalid signature')),
					'headers' => array('Content-Type' => 'application/json'));
			}
		}

		// Check fingerprint against known peers
		$fingerprint = $parsed['headers']['x-q-fingerprint'] ?? '';
		$knownPeers = Q_Config::get('Q', 'federation', 'peers', array());
		if ($knownPeers && $fingerprint) {
			$trusted = false;
			foreach ($knownPeers as $peer) {
				if (($peer['fingerprint'] ?? '') === $fingerprint) {
					$trusted = true; break;
				}
			}
			if (!$trusted && Q_Config::get('Q', 'federation', 'requireKnownPeers', false)) {
				return array('status' => 403,
					'body' => json_encode(array('error' => 'Unknown peer')),
					'headers' => array('Content-Type' => 'application/json'));
			}
		}

		$eventName = $body['event'];
		$params = $body['params'] ?? array();

		// Dispatch locally — skip remote forwarding to avoid loops
		$saved = Q_Config::get('Q', 'handlersRemote', $eventName, null);
		if ($saved) Q_Config::set('Q', 'handlersRemote', $eventName, null);

		$result = null;
		Q::event($eventName, $params, false, false, $result);

		if ($saved) Q_Config::set('Q', 'handlersRemote', $eventName, $saved);

		return array('status' => 200,
			'body' => json_encode($result),
			'headers' => array('Content-Type' => 'application/json'));
	}

	/**
	 * Ensure a string is a valid regex. If it already has delimiters
	 * (starts with # / ~ { or another non-alnum), return as-is.
	 * Otherwise wrap with #^ ... $# so plain strings like
	 * "/wp-admin/" work as patterns without manual delimiters.
	 * @method ensureRegex
	 * @static
	 * @param {string} $pattern
	 * @return {string}
	 */
	static function ensureRegex($pattern)
	{
		if ($pattern === '') return "\x01^\$\x01";
		// Only treat # as a pre-existing delimiter (our convention).
		if ($pattern[0] === '#') {
			return $pattern;
		}
		// Use \x01 as delimiter — won't appear in URL patterns
		return "\x01" . $pattern . "\x01";
	}

	/**
	 * Return the standard HTTP reason phrase for a status code.
	 * @method statusText
	 * @static
	 * @param {integer} $code HTTP status code
	 * @return {string} Reason phrase (e.g. "OK", "Not Found")
	 */
	static function statusText($code)
	{
		static $reasons = array(
			200=>'OK', 201=>'Created', 204=>'No Content',
			301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
			400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
			404=>'Not Found', 405=>'Method Not Allowed',
			413=>'Payload Too Large', 429=>'Too Many Requests',
			500=>'Internal Server Error', 502=>'Bad Gateway',
			503=>'Service Unavailable',
		);
		return $reasons[$code] ?? 'OK';
	}
}
