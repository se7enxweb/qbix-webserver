<?php
/**
 * Autohost — auto-provision domains on first request.
 *
 * When a request arrives with an unknown Host header:
 *   1. Validate the hostname format
 *   2. Check rate limits
 *   3. Run authorization hook (if configured)
 *   4. Verify DNS points at this server
 *   5. Provision a TLS cert via ACME
 *   6. Write the domain config
 *   7. Serve the app
 *
 * Config (Q.webserver.autohost):
 *   enabled: true
 *   authorize: "open" | "allowlist" | "/path/to/hook.php"
 *   allowlist: ["*.example.com", "app.acme.com"]
 *   dnsCheck: true
 *   dnsResolvers: ["1.1.1.1", "8.8.8.8"]
 *   rateLimit:
 *     perIpPerHour: 10
 *     globalPerHour: 100
 *   splash: true
 *   defaultRoot: null  (uses the server's current root)
 *   defaultApp: null   (uses the server's current app dir)
 *   acmeEmail: "admin@example.com"
 *   log: "local/autohost.log"
 */
class Q_WebServer_Autohost
{
	static $provisioning = [];  // hostname => timestamp (in-progress)
	static $rateCounts = [];    // ip => [timestamps]
	static $globalCount = [];   // [timestamps]
	static $dnsMismatchCache = []; // hostname => expiry
	static $certFailCache = [];    // hostname => expiry
	static $provisionedLog = [];   // recent provisions for panel

	/**
	 * Check if autohost is enabled.
	 */
	static function enabled()
	{
		return (bool) Q_Config::get('Q', 'webserver', 'autohost', 'enabled', false);
	}

	/**
	 * Check if a hostname is known (already configured).
	 */
	static function isKnownHost($hostname)
	{
		$domains = Q_Config::get('Q', 'webserver', 'domains', []);
		if (isset($domains[$hostname])) return true;

		// Check the panel's store
		$panelDomains = class_exists('Q_WebServer_Panel_Store')
			? Q_WebServer_Panel_Store::setting('domains', []) : [];
		return is_array($panelDomains) && isset($panelDomains[$hostname]);
	}

	/**
	 * Handle a request for an unknown host.
	 * Returns true if handled (splash served or provisioning started).
	 * Returns false if autohost declined (host not authorized, etc).
	 */
	static function handleUnknownHost($hostname, $clientIp, $parsed)
	{
		if (!self::enabled()) return false;

		// Validate hostname format
		if (!self::validateHostname($hostname)) return false;

		// Strip port if present
		$hostname = strtolower(preg_replace('/:\d+$/D', '', $hostname));

		// Already provisioning?
		if (isset(self::$provisioning[$hostname])) {
			return self::serveSplash($parsed, $hostname);
		}

		// In negative cache?
		if (isset(self::$dnsMismatchCache[$hostname]) && self::$dnsMismatchCache[$hostname] > time()) {
			return false;
		}
		if (isset(self::$certFailCache[$hostname]) && self::$certFailCache[$hostname] > time()) {
			return false;
		}

		// Rate limit check
		if (!self::checkRateLimit($clientIp)) {
			self::log("Rate limited: $hostname from $clientIp");
			return false;
		}

		// Authorization check
		if (!self::authorize($hostname, $clientIp)) {
			self::log("Denied: $hostname from $clientIp");
			return false;
		}

		// DNS check
		$dnsCheck = Q_Config::get('Q', 'webserver', 'autohost', 'dnsCheck', true);
		if ($dnsCheck && !self::verifyDns($hostname)) {
			$ttl = Q_Config::get('Q', 'webserver', 'autohost', 'dnsMismatchCacheSec', 300);
			self::$dnsMismatchCache[$hostname] = time() + $ttl;
			self::log("DNS mismatch: $hostname does not resolve to this server");
			return false;
		}

		// Start provisioning in the background
		self::$provisioning[$hostname] = time();
		self::log("Provisioning: $hostname");

		// Provision synchronously (in fork-per-request or inline)
		// The splash is served on subsequent requests while this runs
		$result = self::provision($hostname);

		unset(self::$provisioning[$hostname]);

		if ($result['success'] ?? false) {
			self::log("Provisioned: $hostname");
			self::$provisionedLog[] = [
				'hostname' => $hostname,
				'time' => date('c'),
				'ip' => $clientIp,
			];
			// Trim log
			if (count(self::$provisionedLog) > 100) {
				self::$provisionedLog = array_slice(self::$provisionedLog, -50);
			}
			return false; // Let the normal request handler serve it now
		} else {
			$ttl = Q_Config::get('Q', 'webserver', 'autohost', 'certFailCacheSec', 3600);
			self::$certFailCache[$hostname] = time() + $ttl;
			self::log("Failed: $hostname — " . ($result['error'] ?? 'unknown'));
			return false;
		}
	}

	/**
	 * Validate a hostname (RFC 1123).
	 *
	 * This is the gate between a stranger's Host header and everything the
	 * autohost path does with it: a DNS lookup, a provisioning run, a certbot
	 * invocation naming it, and a line in a log file. A name that gets through
	 * here is treated as trustworthy from then on, so the check has to be exact
	 * rather than approximately right.
	 */
	static function validateHostname($h, $requireDot = true)
	{
		// PCRE's $ matches before a string's final newline unless the D
		// modifier says otherwise. Both anchors here meant "end of the name",
		// so without it "example.com\n" validated: the port-stripping pattern
		// ignored the newline and the shape check accepted it. The name then
		// reached a log as two lines, and a command line as an argument nobody
		// wrote.
		$h = strtolower(preg_replace('/:\d+$/D', '', (string) $h));
		if (strlen($h) > 253 || strlen($h) < 1) return false;

		// Must have at least one dot (not a bare hostname). A name being
		// provisioned for needs one, because a certificate cannot be issued
		// for a bare label; an entry in a hosts file is a different question,
		// so callers there pass false rather than keeping a second validator.
		if ($requireDot && strpos($h, '.') === false) return false;

		// RFC 1123: every label is 1 to 63 characters, begins and ends with an
		// alphanumeric, and may carry hyphens between them. Testing the whole
		// name against one pattern only established that the *name* began and
		// ended alphanumerically, which says nothing about the labels inside
		// it -- "example-.com" passed, because the name ends in "m".
		foreach (explode('.', $h) as $label) {
			if (strlen($label) > 63) return false;
			if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $label)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check authorization for a hostname.
	 */
	static function authorize($hostname, $clientIp)
	{
		$mode = Q_Config::get('Q', 'webserver', 'autohost', 'authorize', 'open');

		if ($mode === 'open') return true;

		if ($mode === 'allowlist') {
			$list = Q_Config::get('Q', 'webserver', 'autohost', 'allowlist', []);
			foreach ($list as $pattern) {
				// Support wildcards: *.example.com
				$regex = '/^' . str_replace('\*', '[a-z0-9\-]+', preg_quote($pattern, '/')) . '$/';
				if (preg_match($regex, $hostname)) return true;
			}
			return false;
		}

		// Hook file path
		if (is_file($mode)) {
			$hookFn = require $mode;
			if (is_callable($hookFn)) {
				$result = $hookFn($hostname, ['ip' => $clientIp]);
				return !empty($result['allowed']);
			}
		}

		return false;
	}

	/**
	 * Check per-IP and global rate limits.
	 */
	static function checkRateLimit($ip)
	{
		$now = time();
		$perIp = Q_Config::get('Q', 'webserver', 'autohost', 'rateLimit', 'perIpPerHour', 10);
		$global = Q_Config::get('Q', 'webserver', 'autohost', 'rateLimit', 'globalPerHour', 100);
		$window = 3600;

		// Clean old entries
		if (!isset(self::$rateCounts[$ip])) self::$rateCounts[$ip] = [];
		self::$rateCounts[$ip] = array_filter(self::$rateCounts[$ip], function($t) use ($now, $window) {
			return $t > $now - $window;
		});
		self::$globalCount = array_filter(self::$globalCount, function($t) use ($now, $window) {
			return $t > $now - $window;
		});

		if (count(self::$rateCounts[$ip]) >= $perIp) return false;
		if (count(self::$globalCount) >= $global) return false;

		self::$rateCounts[$ip][] = $now;
		self::$globalCount[] = $now;
		return true;
	}

	/**
	 * Multi-resolver DNS check. Verifies the hostname resolves to one of our IPs.
	 */
	static function verifyDns($hostname)
	{
		$ourIps = self::getOurIps();
		if (empty($ourIps)) return true; // Can't determine our IPs — skip check

		// System resolver
		$resolved = gethostbyname($hostname);
		if ($resolved !== $hostname && in_array($resolved, $ourIps)) return true;

		// Additional resolvers via dig/nslookup if available
		$resolvers = Q_Config::get('Q', 'webserver', 'autohost', 'dnsResolvers', ['1.1.1.1', '8.8.8.8']);
		foreach ($resolvers as $ns) {
			$ip = self::resolveWith($hostname, $ns);
			if ($ip && in_array($ip, $ourIps)) return true;
		}
		return false;
	}

	/**
	 * Resolve a hostname using a specific nameserver.
	 */
	static function resolveWith($hostname, $ns)
	{
		// Try dig first
		if (self::commandExists('dig')) {
			$out = shell_exec("dig +short @$ns " . escapeshellarg($hostname) . " A 2>/dev/null");
			if ($out) {
				foreach (explode("\n", trim($out)) as $line) {
					if (filter_var(trim($line), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						return trim($line);
					}
				}
			}
		}
		// Fallback: nslookup
		if (self::commandExists('nslookup')) {
			$out = shell_exec("nslookup " . escapeshellarg($hostname) . " $ns 2>/dev/null");
			if ($out && preg_match('/Address:\s*(\d+\.\d+\.\d+\.\d+)/s', $out, $m)) {
				return $m[1];
			}
		}
		return null;
	}

	/**
	 * Get this server's public IPs.
	 */
	static function getOurIps()
	{
		static $cached = null;
		if ($cached !== null) return $cached;

		$ips = Q_Config::get('Q', 'webserver', 'autohost', 'ourIps', null);
		if ($ips) {
			$cached = is_array($ips) ? $ips : explode(',', $ips);
			return $cached;
		}

		$cached = [];

		// Local interfaces
		if (function_exists('net_get_interfaces')) {
			foreach (net_get_interfaces() as $iface) {
				foreach ($iface['unicast'] ?? [] as $addr) {
					$ip = $addr['address'] ?? '';
					if ($ip && $ip !== '127.0.0.1' && $ip !== '::1' && filter_var($ip, FILTER_VALIDATE_IP)) {
						$cached[] = $ip;
					}
				}
			}
		}

		// Try external discovery (AWS IMDS, then public echo)
		$sources = [
			'http://169.254.169.254/latest/meta-data/public-ipv4',
			'https://api.ipify.org',
			'https://icanhazip.com',
		];
		$ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
		foreach ($sources as $url) {
			$ip = @file_get_contents($url, false, $ctx);
			if ($ip) {
				$ip = trim($ip);
				if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $cached)) {
					$cached[] = $ip;
					break;
				}
			}
		}

		return $cached;
	}

	/**
	 * Provision a domain: cert + config.
	 */
	static function provision($hostname)
	{
		$email = Q_Config::get('Q', 'webserver', 'autohost', 'acmeEmail',
			Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', ''));

		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$staging = (bool) Q_Config::get('Q', 'webserver', 'tls', 'acmeStaging', false);

		// Provision cert if ACME email is set
		$certResult = null;
		if ($email && class_exists('Q_WebServer_Acme')) {
			// In the background: the CA validates by fetching a file from this
			// server, which cannot answer while it waits here. The domain's
			// configuration is written now; the certificate follows.
			Q_WebServer_Certificate_Job::start('autohost:' . $hostname,
				rtrim($certDir, '/') . '/' . $hostname . '/state.json',
				function () use ($hostname, $certDir, $email, $staging) {
					$r = Q_WebServer_Acme::provision([$hostname], $certDir, $email, $staging);
					return array('ok' => !empty($r['success']), 'error' => $r['error'] ?? null);
				}, true);
			$certResult = ['success' => true, 'pending' => true];
		}

		// Write domain config
		$defaultRoot = Q_Config::get('Q', 'webserver', 'autohost', 'defaultRoot', null);
		$defaultApp = Q_Config::get('Q', 'webserver', 'autohost', 'defaultApp', null);

		$domainConf = ['tls' => $email ? 'auto' : ''];
		if ($defaultRoot) $domainConf['root'] = $defaultRoot;
		if ($defaultApp) $domainConf['app'] = $defaultApp;

		// Into the panel's store, under its lock: a record the panel already
		// holds for this host keeps its other fields.
		Q_WebServer_Domains::update($hostname, function (array $old) use ($domainConf) {
			return array_merge($old, $domainConf);
		});

		// Write to autohost log file
		$logFile = Q_Config::get('Q', 'webserver', 'autohost', 'log', qbix_data_path('local/autohost.log'));
		@mkdir(dirname($logFile), 0755, true);
		$entry = date('c') . " PROVISIONED $hostname" . ($certResult ? " cert=ok" : " cert=none") . "\n";
		file_put_contents($logFile, $entry, FILE_APPEND);

		return ['success' => true, 'hostname' => $hostname, 'cert' => !empty($certResult['success'])];
	}

	/**
	 * Serve the splash page while provisioning.
	 */
	static function serveSplash($parsed, $hostname)
	{
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta http-equiv="refresh" content="5">'
			. '<title>Setting up ' . htmlspecialchars($hostname) . '</title>'
			. '<style>body{font-family:system-ui;display:flex;justify-content:center;align-items:center;'
			. 'min-height:100vh;margin:0;background:#111;color:#fff}'
			. '.box{text-align:center;max-width:400px;padding:40px}'
			. '.spinner{width:40px;height:40px;border:3px solid #333;border-top-color:#4a9eff;'
			. 'border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}'
			. '@keyframes spin{to{transform:rotate(360deg)}}'
			. 'h1{font-size:18px;margin:0 0 8px} p{color:#888;font-size:14px;margin:4px 0}'
			. '</style></head><body><div class="box">'
			. '<div class="spinner"></div>'
			. '<h1>Setting up ' . htmlspecialchars($hostname) . '</h1>'
			. '<p>Provisioning HTTPS certificate...</p>'
			. '<p style="font-size:12px">This page refreshes automatically.</p>'
			. '</div></body></html>';
		return ['splash' => true, 'html' => $html];
	}

	/**
	 * Log a message.
	 */
	static function log($msg)
	{
		$logFile = Q_Config::get('Q', 'webserver', 'autohost', 'log', qbix_data_path('local/autohost.log'));
		@mkdir(dirname($logFile), 0755, true);
		file_put_contents($logFile, date('c') . " $msg\n", FILE_APPEND);
	}

	/**
	 * Check if a command exists (cross-platform).
	 */
	static function commandExists($cmd)
	{
		$check = PHP_OS_FAMILY === 'Windows' ? "where $cmd" : "which $cmd";
		exec($check . ' 2>/dev/null', $out, $ret);
		return $ret === 0;
	}

	/**
	 * Get provisioning status for the panel.
	 */
	static function status()
	{
		$logFile = Q_Config::get('Q', 'webserver', 'autohost', 'log', qbix_data_path('local/autohost.log'));
		$recent = [];
		if (is_file($logFile)) {
			$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$recent = array_slice($lines, -20);
		}
		return [
			'enabled' => self::enabled(),
			'authorize' => Q_Config::get('Q', 'webserver', 'autohost', 'authorize', 'open'),
			'dnsCheck' => Q_Config::get('Q', 'webserver', 'autohost', 'dnsCheck', true),
			'rateLimit' => [
				'perIpPerHour' => Q_Config::get('Q', 'webserver', 'autohost', 'rateLimit', 'perIpPerHour', 10),
				'globalPerHour' => Q_Config::get('Q', 'webserver', 'autohost', 'rateLimit', 'globalPerHour', 100),
			],
			'provisioning' => array_keys(self::$provisioning),
			'recentLog' => $recent,
			'provisionedCount' => count(self::$provisionedLog),
		];
	}

	/**
	 * Renew all certificates expiring within $days.
	 * Called by the server's internal timer (daily).
	 */
	static function renewAll($days = 30)
	{
		if (!class_exists('Q_WebServer_Acme')) return ['renewed' => 0];
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		if (!is_dir($certDir)) return ['renewed' => 0];
		$email = Q_Config::get('Q', 'webserver', 'autohost', 'acmeEmail',
			Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', ''));
		if (!$email) return ['renewed' => 0, 'error' => 'No ACME email configured'];
		$staging = (bool) Q_Config::get('Q', 'webserver', 'tls', 'acmeStaging', false);
		$renewed = 0;
		$errors = [];
		foreach (scandir($certDir) as $d) {
			if ($d === '.' || $d === '..' || $d === 'account.pem') continue;
			$hostDir = $certDir . '/' . $d;
			if (!is_dir($hostDir)) continue;
			$certPath = $hostDir . '/fullchain.pem';
			if (!is_file($certPath)) continue;
			if (!Q_WebServer_Acme::needsRenewal($certPath, $days)) continue;
			self::log("Renewing cert for $d");
			$result = Q_WebServer_Acme::provision([$d], $certDir, $email, $staging);
			if (!empty($result['success'])) {
				$renewed++;
				self::log("Renewed: $d");
			} else {
				$errors[] = $d . ': ' . ($result['error'] ?? 'unknown');
				self::log("Renewal failed: $d — " . ($result['error'] ?? 'unknown'));
			}
		}
		return ['renewed' => $renewed, 'errors' => $errors];
	}

	/**
	 * Get the web root for a framework-aware app directory.
	 * Returns the public-facing directory path.
	 */
	static function detectAppRoot($appDir)
	{
		$appDir = rtrim($appDir, '/\\');
		// Qbix: SomeApp/web
		if (is_dir("$appDir/web") && is_file("$appDir/web/index.php")) {
			return "$appDir/web";
		}
		// Laravel / Symfony: public/
		if (is_dir("$appDir/public") && is_file("$appDir/public/index.php")) {
			return "$appDir/public";
		}
		// Drupal: web/
		if (is_dir("$appDir/web") && is_file("$appDir/web/index.php") && is_file("$appDir/web/core/lib/Drupal.php")) {
			return "$appDir/web";
		}
		// WordPress: root is the web root
		if (is_file("$appDir/wp-config.php") || is_file("$appDir/wp-login.php")) {
			return $appDir;
		}
		// Joomla: root
		if (is_dir("$appDir/administrator") && is_file("$appDir/index.php")) {
			return $appDir;
		}
		// Generic: if index.php exists in root
		if (is_file("$appDir/index.php")) {
			return $appDir;
		}
		return $appDir;
	}

	/**
	 * Detect which framework an app uses, for preset selection.
	 */
	static function detectFramework($appDir)
	{
		// The same registry the panel's Apps and Frameworks tabs use, so the
		// three always agree. Plain PHP and bare Composer projects are not a
		// framework here, as before.
		$d = Q_WebServer_Framework::detect(rtrim($appDir, '/\\'));
		if (!$d or in_array($d['kind'], array('php', 'composer'), true)) return null;
		return $d['kind'];
	}
}
