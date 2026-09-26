<?php
/**
 * @module Q
 */

/**
 * TLS certificate management for Q_WebServer.
 *
 * Modes (see also Q_WebServer_Certificate_SelfSigned for "self-signed"):
 *
 * 1. Local certbot: runs `certbot certonly` to obtain/renew
 *    Let's Encrypt certs. Checks expiration via openssl_x509_parse
 *    and renews automatically — no cron needed, runs on a
 *    Q_Evented timer.
 *
 * 2. Remote download: fetches certs from a URL (.zip containing
 *    fullchain.pem + privkey.pem). For dev domains like
 *    local.qbix.com where certs are published centrally.
 *    Checks actual cert expiration, re-downloads when expired.
 *
 * Config:
 *   "Q": {
 *     "web": {
 *       "https": {
 *         "cert": "/path/to/fullchain.pem",   // or auto-managed path
 *         "key": "/path/to/privkey.pem",
 *         "mode": "certbot",                  // "certbot" | "remote" | "manual" | "self-signed"
 *         "fallback": "self-signed",          // when no usable cert: "self-signed" (default) | "none"
 *         "watchInterval": 60,                // seconds between checks of the files in use
 *         "domain": "example.com",
 *         "certbot": {
 *           "email": "you@example.com",
 *           "webroot": "/path/to/app/web",    // for webroot validation
 *           "renewDays": 30                   // renew when < 30 days remain
 *         },
 *         "remote": {
 *           "url": "https://certs.qbix.com/local.qbix.com/certs.zip",
 *           "checkInterval": 86400            // check daily (seconds)
 *         }
 *       }
 *     }
 *   }
 *
 * @class Q_WebServer_Certs
 */
class Q_WebServer_Certs
{
	/**
	 * Path to current fullchain.pem
	 * @property $certPath
	 * @static
	 */
	static $certPath = null;

	/**
	 * Path to current privkey.pem
	 * @property $keyPath
	 * @static
	 */
	static $keyPath = null;

	/**
	 * Initialize cert management. Loads existing certs,
	 * checks expiration, starts renewal timer if needed, falls back to a
	 * self-signed certificate when there is no usable one, and watches the
	 * files so a changed certificate is used without a restart.
	 *
	 * @method init
	 * @static
	 * @param {string} $domain The domain to serve
	 * @param {string} $bindHost The address the HTTPS listener binds, for a self-signed certificate's hosts
	 * @return {boolean} true if valid certs are available
	 */
	static function init($domain = null, $bindHost = null)
	{
		$config = Q_Config::get('Q', 'web', 'https', array());
		if ($domain and empty($config['domain'])) $config['domain'] = $domain;
		$mode = Q::ifset($config, 'mode', 'manual');
		self::$bindHost = $bindHost;
		self::$config = $config;

		if ($mode === 'self-signed') {
			self::$sourceObject = null;
			self::$configuredCert = self::$configuredKey = null;
			$valid = self::useSelfSigned();
		} else {
			self::$sourceObject = self::sourceFor($mode);
			$why = '';
			$pair = self::$sourceObject ? self::$sourceObject->pair($config, $why) : null;
			if (!self::$sourceObject) $why = "unknown mode \"$mode\"";
			self::$configuredCert = $pair ? $pair[0] : null;
			self::$configuredKey = $pair ? $pair[1] : null;
			self::$certPath = self::$configuredCert;
			self::$keyPath = self::$configuredKey;
			self::$watchedSignature = self::signatureOf(self::watchedFiles());
			$valid = self::validateCerts();
			if ($valid) {
				self::$source = 'configured';
			} elseif (self::fallback() === 'self-signed') {
				// HTTPS stays up on a certificate of our own until the source
				// has a usable one; the watcher switches over then.
				Q_WebServer_Certificate_Events::emit('error', array('reason' => $mode . ': '
					. ($why !== '' ? $why : 'no usable certificate at ' . self::$configuredCert)
					. '; using a self-signed one until there is'));
				$valid = self::useSelfSigned();
			}
		}

		if ($valid) $valid = self::activate();
		if ($valid) self::watch();
		return $valid;
	}

	/**
	 * The source for a mode name; null for an unknown one.
	 * @method sourceFor
	 * @static
	 * @param {string} $mode
	 * @return {Q_WebServer_Certificate_Source|null}
	 */
	static function sourceFor($mode)
	{
		$map = array('manual' => 'Files', 'files' => 'Files', 'archive' => 'Archive', 'pkcs12' => 'Pkcs12',
			'acme' => 'Acme', 'letsencrypt' => 'Acme', 'certbot' => 'Certbot', 'remote' => 'Remote');
		if (isset($map[$mode])) {
			$class = 'Q_WebServer_Certificate_Source_' . $map[$mode];
			return new $class();
		}
		// A distribution's own: Q.web.https.sources.<mode> = class name.
		$class = Q_Config::get('Q', 'web', 'https', 'sources', $mode, null);
		if ($class and class_exists($class) and is_subclass_of($class, 'Q_WebServer_Certificate_Source')) return new $class();
		return null;
	}

	/** @var Q_WebServer_Certificate_Source|null the source in use, unless self-signed by choice */
	private static $sourceObject = null;

	/** @var array Q.web.https as init() read it */
	private static $config = array();

	/** @var string|null what the source's own files looked like */
	private static $watchedSignature = null;

	/** The files the source watches. */
	private static function watchedFiles()
	{
		return self::$sourceObject ? self::$sourceObject->watched(self::$config) : array();
	}

	/**
	 * Where the certificate in use came from: "configured" (the files named by
	 * Q.web.https, however they got there) or "self-signed".
	 * @property $source
	 * @static
	 */
	static $source = null;

	/**
	 * When the watcher last looked, and when the certificate in use last
	 * changed (Unix times, null before the first), for the control panel.
	 * @property $lastCheck
	 * @static
	 */
	static $lastCheck = null;
	static $lastChange = null;

	/** @var string|null the configured pair, kept while a self-signed one stands in */
	private static $configuredCert = null;
	private static $configuredKey = null;

	/** @var string|null the address the HTTPS listener binds, for the self-signed hosts */
	private static $bindHost = null;

	/** @var string|null what the certificate files looked like when last loaded */
	private static $signature = null;

	/** @var int|null the Q_Evented timer of watch() */
	private static $watchTimer = null;

	/** @var int when the self-signed certificate is next checked for renewal */
	private static $nextRenewalCheck = 0;

	/**
	 * What to do when the configured certificate is missing or unusable:
	 * "self-signed" (the default) or "none" to leave HTTPS off.
	 * @return {string}
	 */
	static function fallback()
	{
		return (string) Q_Config::get('Q', 'web', 'https', 'fallback', 'self-signed');
	}

	/**
	 * Make sure a self-signed certificate is in the store and use it.
	 * @method useSelfSigned
	 * @static
	 * @param {boolean} $force renew even when nothing calls for it
	 * @return {boolean}
	 */
	static function useSelfSigned($force = false)
	{
		$r = Q_WebServer_Certificate_SelfSigned::ensure(
			Q_WebServer_Certificate_SelfSigned::hosts(self::$bindHost), $force);
		if (!$r) return false;
		self::$certPath = $r[0];
		self::$keyPath = $r[1];
		self::$source = 'self-signed';
		self::$nextRenewalCheck = time() + 3600;
		return true;
	}

	/**
	 * Watch the certificate in use, so nobody has to restart the server for
	 * a certificate to change. Every Q.web.https.watchInterval seconds (60):
	 *
	 *   - files replaced on disk -- a certbot or control-panel renewal, a copy
	 *     made by hand -- are swapped in once the new pair is usable together,
	 *     never while it is half written;
	 *   - a configured certificate that expired is replaced by a self-signed
	 *     one (unless the fallback is "none"), and the configured one is
	 *     switched back to as soon as it is usable again;
	 *   - a self-signed one is renewed before it expires or when the host
	 *     names change (checked hourly).
	 *
	 * @method watch
	 * @static
	 */
	static function watch()
	{
		self::$signature = self::fileSignature();
		if (self::$watchTimer !== null or !class_exists('Q_Evented', false)) return;
		$interval = max(1.0, (float) Q_Config::get('Q', 'web', 'https', 'watchInterval', 60));
		self::$watchTimer = Q_Evented::repeat($interval, function () {
			Q_WebServer_Certs::check();
		});
	}

	/**
	 * One round of watch(). Public for tests and for a control command.
	 * @method check
	 * @static
	 * @return {boolean} whether the certificate in use changed
	 */
	static function check()
	{
		self::$lastCheck = time();
		if (class_exists('Q_WebServer_Certificate_Admin')) Q_WebServer_Certificate_Admin::mark('last-check');
		try {
			// The source first: a renewal it is due (acme, certbot, remote) is
			// started in the background, and when its own files changed -- an
			// archive or bundle replaced, a file of yours rewritten -- it is
			// read again, and its pair is what the rules below look at.
			$why = '';
			if (self::$sourceObject) {
				self::$sourceObject->tick(self::$config);
				$w = self::signatureOf(self::watchedFiles());
				if ($w !== self::$watchedSignature) {
					self::$watchedSignature = $w;
					$pair = self::$sourceObject->pair(self::$config, $why);
					if ($pair) {
						self::$configuredCert = $pair[0];
						self::$configuredKey = $pair[1];
						if (self::$source === 'configured') {
							self::$certPath = $pair[0];
							self::$keyPath = $pair[1];
						}
					}
				}
			}
			if (self::$source === 'self-signed') {
				// Back to the configured certificate as soon as it is usable.
				if (self::$configuredCert and self::pairUsable(self::$configuredCert, self::$configuredKey)) {
					self::$certPath = self::$configuredCert;
					self::$keyPath = self::$configuredKey;
					self::$source = 'configured';
					self::$pending = null;
					self::reloadServerCerts();
					return true;
				}
				if (time() >= self::$nextRenewalCheck) {
					$before = self::fileSignature();
					if (self::useSelfSigned() and self::fileSignature() !== $before) {
						self::reloadServerCerts();
						return true;
					}
				}
				// Renewed by someone else (ssl:renew): swap it in once whole.
				if (self::fileSignature() !== self::$signature and self::validateCerts()) {
					self::reloadServerCerts();
					return true;
				}
				return false;
			}

			$now = self::fileSignature();
			if ($now !== self::$signature) {
				if (self::validateCerts()) {
					self::$pending = null;
					self::reloadServerCerts();
					return true;
				}
				// Half written, or a key that does not match yet: one interval
				// to settle before it counts as broken.
				if ($now !== self::$pending) {
					self::$pending = $now;
					Q_WebServer_Certificate_Events::emit('error', array('reason' =>
						self::$certPath . ' changed but is not usable yet; keeping the current certificate'));
					return false;
				}
			}
			if (!self::validateCerts() and self::fallback() === 'self-signed') {
				// Missing, expired or broken, and nobody put it right.
				Q_WebServer_Certificate_Events::emit('error', array('reason' =>
					self::$certPath . ' is not usable; using a self-signed one until it is'));
				if (self::useSelfSigned()) {
					self::$pending = null;
					self::reloadServerCerts();
					return true;
				}
			}
		} catch (\Throwable $e) {
			Q_WebServer_Certificate_Events::emit('error', array('reason' => 'certificate check: ' . $e->getMessage()));
		}
		return false;
	}

	/** @var string|null a changed but unusable file signature, given one interval to settle */
	private static $pending = null;


	/**
	 * The files the HTTPS listener reads: a private copy of the pair in use.
	 * @property $activeCert
	 * @static
	 */
	static $activeCert = null;
	static $activeKey = null;

	/**
	 * Copy the current pair to files only this server writes, and have the
	 * listener read those. PHP reads the certificate files again for every
	 * handshake, so a listener pointed at the configured files would present
	 * whatever is on disk at that moment: a renewal half written -- a new
	 * certificate with the old key -- failed every handshake until it was
	 * finished. With its own copy, a change reaches connections only once
	 * check() has seen the pair usable and swapped it in.
	 *
	 * <ssl dir>/active-<https port>-<fingerprint>.pem and .key, the key 0600.
	 * @method activate
	 * @static
	 * @return {boolean}
	 */
	static function activate()
	{
		$c = Q_WebServer_Certificate::fromFiles(self::$certPath, self::$keyPath);
		if (!$c or !$c->isUsable()) return false;
		$port = (int) Q_Config::get('Q', 'web', 'https', 'port', 443);
		// Named by fingerprint: the listener moves from one complete pair to
		// the next in a single step, never through a moment where it would
		// read a new key with the old certificate.
		$tag = substr(preg_replace('/[^0-9a-f]/', '', strtolower($c->fingerprint())), 0, 16) ?: 'x';
		// And by process: another server on the same directory and port -- a
		// second site, a test instance, a benchmark -- has copies of its own,
		// which this one must never replace or remove. PHP reads the files
		// again for every handshake, so a removed copy fails every new
		// connection of the server that was using it.
		$pid = self::pid();
		foreach (array(Q_WebServer_Certificate_Store::defaultDir(), self::certsDir()) as $dir) {
			if (!is_dir($dir)) @mkdir($dir, 0755, true);
			$cert = $dir . DS . "active-$port-$tag-$pid.pem";
			$key = $dir . DS . "active-$port-$tag-$pid.key";
			if (Q_WebServer_Certificate_Store::writeAtomic($key, $c->keyPem, 0600)
				and Q_WebServer_Certificate_Store::writeAtomic($cert, $c->certPem, 0644)) {
				self::$activeCert = $cert;
				self::$activeKey = $key;
				// This process's earlier copies, and those of processes that
				// are gone. The caller points the listener at the new pair
				// before anything else runs, so no handshake of ours can reach
				// a removed file, and no running server's copies are touched.
				foreach ((array) glob($dir . DS . "active-$port-*") as $old) {
					if ($old === $cert or $old === $key) continue;
					if (self::copyRemovable($old, $pid)) @unlink($old);
				}
				return true;
			}
		}
		Q_WebServer_Certificate_Events::emit('error', array('reason' => 'could not write the active certificate copy'));
		return false;
	}
	/** This process's id; getmypid() can be the parent's after a fork. */
	static function pid()
	{
		return function_exists('posix_getpid') ? posix_getpid() : getmypid();
	}

	/**
	 * Whether an active copy may be removed by the process $pid: its own, or
	 * one whose process is no longer running. A copy named without a process
	 * id (made by an earlier version) is left alone: its server may still be
	 * running. Where liveness cannot be checked (Windows), only its own.
	 * @method copyRemovable
	 * @static
	 * @param {string} $file
	 * @param {int} $pid
	 * @return {boolean}
	 */
	static function copyRemovable($file, $pid)
	{
		if (!preg_match('/^active-\d+-[0-9a-fx]+-(\d+)\.(pem|key)$/', basename($file), $m)) return false;
		$owner = (int) $m[1];
		if ($owner === (int) $pid) return true;
		return self::processGone($owner);
	}

	/** Whether no process with this id is running; false when it cannot be told. */
	static function processGone($pid)
	{
		$pid = (int) $pid;
		if ($pid <= 0) return false;
		if (function_exists('posix_kill')) {
			if (@posix_kill($pid, 0)) return false;
			// EPERM: it exists, it is only someone else's.
			return function_exists('posix_get_last_error') ? posix_get_last_error() !== 1 : false;
		}
		if (DS === '/' and is_dir('/proc/self')) return !is_dir('/proc/' . $pid);
		return false;
	}

	/** Whether a certificate and key file are usable together. */
	static function pairUsable($certFile, $keyFile)
	{
		$c = ($certFile and $keyFile) ? Q_WebServer_Certificate::fromFiles($certFile, $keyFile) : null;
		return $c ? $c->isUsable() : false;
	}

	/** Identity, size and time of the files in use, to notice a replacement. */
	private static function fileSignature()
	{
		return self::signatureOf(array(self::$certPath, self::$keyPath));
	}

	/** Identity, size and time of some files, to notice any change. */
	private static function signatureOf(array $files)
	{
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) Q_WebServer_CompatFileWrapper::dropStats();
		$sig = array();
		foreach ($files as $f) {
			if (!$f) { $sig[] = '-'; continue; }
			clearstatcache(true, $f);
			$s = @stat($f);
			$sig[] = $s ? $s['ino'] . ':' . $s['size'] . ':' . $s['mtime'] : 'missing';
		}
		return implode('|', $sig);
	}

	/**
	 * Build an SSL context for stream_socket_server.
	 *
	 * @method sslContext
	 * @static
	 * @return {resource|null} Stream context or null if no certs
	 */
	static function sslContext()
	{
		if (!self::$certPath || !file_exists(self::$certPath)
			|| !self::$keyPath || !file_exists(self::$keyPath)
		) {
			return null;
		}

		return stream_context_create(array(
			'ssl' => array(
				'local_cert'        => self::$certPath,
				'local_pk'          => self::$keyPath,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
				'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
					| STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
			)
		));
	}

	/**
	 * Check that the current cert and key exist, have not expired, and belong
	 * together -- a certificate whose key does not match would fail every
	 * handshake, which is worse than not listening at all.
	 *
	 * @method validateCerts
	 * @static
	 * @return {boolean}
	 */
	static function validateCerts()
	{
		return self::pairUsable(self::$certPath, self::$keyPath);
	}

	/**
	 * Get cert expiration as Unix timestamp.
	 * Uses openssl_x509_parse() — reads from the actual cert,
	 * not mtime.
	 *
	 * @method certExpiry
	 * @static
	 * @param {string} $certPath Path to PEM cert
	 * @return {integer|null} Expiry timestamp or null
	 */
	static function certExpiry($certPath)
	{
		if (!function_exists('openssl_x509_parse')) return null;

		$pem = file_get_contents($certPath);
		if (!$pem) return null;

		$cert = openssl_x509_parse($pem);
		if (!$cert || !isset($cert['validTo_time_t'])) return null;

		return (int) $cert['validTo_time_t'];
	}

	/**
	 * Days remaining until cert expires.
	 *
	 * @method daysRemaining
	 * @static
	 * @return {integer|null}
	 */
	static function daysRemaining()
	{
		$expiry = self::certExpiry(self::$certPath);
		if ($expiry === null) return null;
		return max(0, (int) floor(($expiry - time()) / 86400));
	}

	// ── Helpers ──────────────────────────────────────────

	/**
	 * Directory for storing cert files.
	 *
	 * @method certsDir
	 * @static
	 * @return {string}
	 */
	static function certsDir()
	{
		$dir = Q_Config::get('Q', 'web', 'https', 'certsDir', null);
		if (!$dir) {
			$dir = (defined('APP_DIR') ? APP_DIR : '.') . DS . 'config' . DS . 'certs';
		}
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}
		return $dir;
	}

	/**
	 * Put the current cert and key in front of new connections. The listener
	 * is not re-bound: its context is updated in place (Q_WebServer::reloadTls),
	 * so there is no moment without HTTPS, and connections already open keep
	 * the certificate they were given.
	 *
	 * @method reloadServerCerts
	 * @static
	 */
	static function reloadServerCerts()
	{
		self::$signature = self::fileSignature();
		if (!self::activate()) return;
		self::$lastChange = time();
		if (class_exists('Q_WebServer_Certificate_Admin')) Q_WebServer_Certificate_Admin::mark('last-change');
		Q_WebServer::reloadTls();
	}

	/**
	 * Format cert info for display.
	 *
	 * @method info
	 * @static
	 * @return {array} [valid, daysRemaining, expiry, subject, issuer]
	 */
	static function info()
	{
		if (!self::$certPath || !file_exists(self::$certPath)) {
			return array('valid' => false);
		}

		$pem = file_get_contents(self::$certPath);
		$cert = openssl_x509_parse($pem);
		if (!$cert) return array('valid' => false);

		$expiry = $cert['validTo_time_t'];
		return array(
			'valid'         => $expiry > time(),
			'daysRemaining' => max(0, (int) floor(($expiry - time()) / 86400)),
			'expiry'        => date('Y-m-d H:i:s', $expiry),
			'subject'       => $cert['subject']['CN'] ?? '',
			'issuer'        => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '',
		);
	}
}
