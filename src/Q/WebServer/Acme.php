<?php
/**
 * @module Q
 */

/**
 * Certificates from an ACME CA -- Let's Encrypt by default -- issued and
 * renewed by the server itself, with nothing else installed.
 *
 *   "https": {
 *     "mode": "acme",                          // or "letsencrypt"
 *     "acme": {
 *       "email": "admin@example.com",
 *       "domains": ["example.com", "www.example.com"],   // default: https.domain
 *       "directory": "letsencrypt",            // letsencrypt | letsencrypt-staging | zerossl
 *                                              // | google | google-staging | any directory URL
 *       "challenge": "http-01",                // or "dns-01" (needed for *.example.com)
 *       "webroot": "/var/www/html",            // optional: also answer through another server on :80
 *       "dnsHook": "/usr/local/bin/dns-txt",   // dns-01: run as HOOK add|remove NAME VALUE
 *       "dnsWait": 30,                         // dns-01: seconds to wait after adding the record
 *       "keyType": "ec256",                    // ec256 | ec384 | rsa2048 | rsa3072 | rsa4096
 *       "renewAt": 0.33,                       // renew when this share of the lifetime is left
 *       "eab": {"kid": "...", "hmacKey": "..."},   // for CAs that require account binding
 *       "caBundle": null, "verify": true       // for a private CA's own TLS
 *     }
 *   }
 *
 * Issuance runs as a background job (Q_WebServer_Certificate_Job), never in
 * the event loop: the CA's validation request is answered by this same
 * server while the job waits. Challenge answers are files, so whichever
 * process receives the request can answer it. Until the first certificate
 * arrives HTTPS runs on a self-signed one; the new certificate is swapped in
 * by Q_WebServer_Certs' watcher, like any other renewal.
 *
 * Files, under <ssl dir>/acme/:
 *   account-<ca>.pem                the account key for each CA (0600)
 *   <first domain>/fullchain.pem    the certificate and its chain
 *   <first domain>/privkey.pem      its key (0600), new with every issuance
 *   <first domain>/state.json       last attempt, last error, next attempt
 *   challenges/<token>              pending http-01 answers
 *
 * @class Q_WebServer_Acme
 */
class Q_WebServer_Acme
{
	const LE_DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';
	const LE_STAGING   = 'https://acme-staging-v02.api.letsencrypt.org/directory';

	/** @var array short names for well-known ACME directories */
	static $directories = array(
		'letsencrypt' => self::LE_DIRECTORY,
		'letsencrypt-staging' => self::LE_STAGING,
		'zerossl' => 'https://acme.zerossl.com/v2/DV90',
		'google' => 'https://dv.acme-v02.api.pki.goog/directory',
		'google-staging' => 'https://dv.acme-v02.test-api.pki.goog/directory',
	);

	/** @var array challenges set up in this process, for callers of the old API */
	protected static $pendingChallenges = array();

	/**
	 * The answer to an HTTP-01 challenge, or null. Called by the router on
	 * /.well-known/acme-challenge/<token>.
	 * @method getChallengeResponse
	 * @static
	 * @param {string} $token
	 * @return {string|null}
	 */
	static function getChallengeResponse($token)
	{
		if (!preg_match('/^[A-Za-z0-9_-]{8,256}$/', (string) $token)) return null;
		if (isset(self::$pendingChallenges[$token])) return self::$pendingChallenges[$token];
		$file = self::challengeDir() . DIRECTORY_SEPARATOR . $token;
		if (!is_file($file)) return null;
		$answer = @file_get_contents($file);
		return $answer === false ? null : $answer;
	}

	/** @return {string} where pending http-01 answers are kept */
	static function challengeDir()
	{
		$dir = Q_Config::get('Q', 'web', 'https', 'acme', 'challengeDir', null);
		return $dir ?: self::dir() . DIRECTORY_SEPARATOR . 'challenges';
	}

	/** @return {string} <ssl dir>/acme, or Q.web.https.acme.dir */
	static function dir()
	{
		$dir = Q_Config::get('Q', 'web', 'https', 'acme', 'dir', null);
		return $dir ?: Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'acme';
	}

	/** A directory URL from a short name or a URL. */
	static function directoryUrl($name)
	{
		$name = (string) ($name ?: 'letsencrypt');
		return isset(self::$directories[$name]) ? self::$directories[$name] : $name;
	}

	/**
	 * The domains to certify: acme.domains, else https.domain.
	 * @return {array}
	 */
	static function domains(array $config)
	{
		$acme = isset($config['acme']) ? (array) $config['acme'] : array();
		if (!empty($acme['domains'])) $names = array_values(array_unique(array_map('strtolower', (array) $acme['domains'])));
		else $names = empty($config['domain']) ? array() : array(strtolower($config['domain']));
		// Domains the panel was asked to certify (Q_WebServer_Certificate_Inspector),
		// so a renewal keeps them. The configured first name stays first: it
		// names the files the listener serves.
		if (class_exists('Q_WebServer_Domains') and method_exists('Q_WebServer_Domains', 'acmeHosts')) {
			foreach (Q_WebServer_Domains::acmeHosts() as $h) {
				if (!in_array($h, $names, true)) $names[] = $h;
			}
		}
		return $names;
	}

	/**
	 * Where the certificate for these settings lives.
	 * @return {array|null} array(fullchain, privkey, state file), null without domains
	 */
	static function files(array $config)
	{
		$domains = self::domains($config);
		if (!$domains) return null;
		$d = self::dir() . DIRECTORY_SEPARATOR . str_replace('*', '_', $domains[0]);
		return array($d . DIRECTORY_SEPARATOR . 'fullchain.pem', $d . DIRECTORY_SEPARATOR . 'privkey.pem', $d . DIRECTORY_SEPARATOR . 'state.json');
	}

	/**
	 * Whether a certificate is due for renewal: missing, unusable, not
	 * covering every name asked for, or with less than renewAt of its lifetime
	 * left -- a third by default: 30 days of Let's Encrypt's 90, 15 of 45,
	 * 2 of 6 -- so it keeps working as CAs shorten their certificates.
	 * @method renewalDue
	 * @static
	 * @return {string|null} why, or null when it is not due
	 */
	static function renewalDue($certFile, $keyFile, array $domains, $renewAt = null)
	{
		$c = Q_WebServer_Certificate::fromFiles($certFile, $keyFile);
		if (!$c) return 'there is no certificate yet';
		if (!$c->isUsable()) return 'the certificate is not usable';
		$want = array_map('strtolower', $domains);
		if (array_values(array_diff($want, $c->hosts())) !== array()) return 'the domains changed';
		$p = openssl_x509_parse($c->certPem);
		$from = (int) ($p['validFrom_time_t'] ?? 0);
		$to = (int) ($p['validTo_time_t'] ?? 0);
		$share = $renewAt === null ? 1 / 3 : (float) $renewAt;
		if ($to - time() < ($to - $from) * $share) return 'it expires in ' . $c->daysLeft() . ' days';
		return null;
	}

	/**
	 * Issue a certificate for these settings, now, in this process. Blocking:
	 * call it from a job or the console, never from the event loop.
	 * @method issue
	 * @static
	 * @param {array} $config Q.web.https
	 * @return {array} array('ok' => bool, 'error' => string, 'cert' => ..., 'key' => ..., 'retryAfter' => seconds)
	 */
	static function issue(array $config)
	{
		$acme = isset($config['acme']) ? (array) $config['acme'] : array();
		$domains = self::domains($config);
		$files = self::files($config);
		if (!$domains or !$files) return array('ok' => false, 'error' => 'no domains: set https.acme.domains or https.domain');
		$directory = self::directoryUrl(isset($acme['directory']) ? $acme['directory'] : null);
		$dir = self::dir();
		if (!is_dir(dirname($files[0])) and !@mkdir(dirname($files[0]), 0700, true) and !is_dir(dirname($files[0]))) {
			return array('ok' => false, 'error' => 'cannot create ' . dirname($files[0]));
		}
		try {
			$accountFile = $dir . DIRECTORY_SEPARATOR . 'account-' . substr(sha1($directory), 0, 12) . '.pem';
			if (!is_file($accountFile)) {
				// The earlier client kept one account.pem; it keeps its account.
				$legacy = $dir . DIRECTORY_SEPARATOR . 'account.pem';
				$pem = is_file($legacy) ? file_get_contents($legacy) : Q_WebServer_Acme_Client::newAccountKey();
				Q_WebServer_Certificate_Store::writeAtomic($accountFile, $pem, 0600);
			}
			$client = new Q_WebServer_Acme_Client($directory, file_get_contents($accountFile), array(
				'caBundle' => isset($acme['caBundle']) ? $acme['caBundle'] : null,
				'verify' => isset($acme['verify']) ? (bool) $acme['verify'] : true));
			$eab = isset($acme['eab']) ? (array) $acme['eab'] : null;
			if ($eab) $eab['hmacKey'] = Q_WebServer_Certificate_Source_Base::secret($eab, 'hmacKey');
			$client->account(isset($acme['email']) ? $acme['email'] : null, $eab);

			$keyPem = self::newKey(isset($acme['keyType']) ? $acme['keyType'] : 'ec256');
			$type = isset($acme['challenge']) ? $acme['challenge'] : 'http-01';
			$prefer = $type === 'dns-01' ? array('dns-01') : array('http-01', 'dns-01');
			$chain = $client->issue($domains, $keyPem,
				function ($ch, $keyAuth, $identifier, $t) use ($acme) { return self::respond($ch, $keyAuth, $identifier, $t, $acme); },
				function ($ch, $keyAuth, $identifier, $t) use ($acme) { self::cleanup($ch, $keyAuth, $identifier, $t, $acme); },
				$prefer, isset($acme['timeout']) ? (int) $acme['timeout'] : 300);

			$c = new Q_WebServer_Certificate($chain, $keyPem, 'acme');
			if (!$c->isUsable()) return array('ok' => false, 'error' => 'the CA sent a certificate that does not match the key');
			// The key first: a reader that finds the new certificate finds its key.
			if (!Q_WebServer_Certificate_Store::writeAtomic($files[1], $keyPem, 0600)
				or !Q_WebServer_Certificate_Store::writeAtomic($files[0], $chain, 0644)) {
				return array('ok' => false, 'error' => 'could not write ' . dirname($files[0]));
			}
			return array('ok' => true, 'cert' => $files[0], 'key' => $files[1], 'domains' => $domains, 'expires' => $c->expires());
		} catch (\Throwable $e) {
			$retry = preg_match('/rateLimited/', $e->getMessage()) ? 3600 : 0;
			return array('ok' => false, 'error' => $e->getMessage(), 'retryAfter' => $retry);
		}
	}

	/** A new certificate key of the given type, PEM. */
	static function newKey($type)
	{
		$types = array(
			'ec256' => array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048),
			'ec384' => array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 2048),
			'rsa2048' => array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048),
			'rsa3072' => array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072),
			'rsa4096' => array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096),
		);
		$k = openssl_pkey_new(isset($types[$type]) ? $types[$type] : $types['ec256']);
		if (!$k or !openssl_pkey_export($k, $pem)) throw new Exception('could not make the certificate key');
		return $pem;
	}

	/** Make a challenge answerable. */
	private static function respond($ch, $keyAuth, $identifier, $type, array $acme)
	{
		if ($type === 'http-01') {
			$ok = self::writeToken(self::challengeDir(), $ch['token'], $keyAuth);
			if (!empty($acme['webroot'])) {
				$ok = self::writeToken(rtrim($acme['webroot'], '/\\') . '/.well-known/acme-challenge', $ch['token'], $keyAuth) || $ok;
			}
			return $ok;
		}
		if ($type === 'dns-01') {
			if (empty($acme['dnsHook'])) throw new Exception('dns-01 needs https.acme.dnsHook');
			$name = '_acme-challenge.' . preg_replace('/^\*\./', '', $identifier);
			$value = Q_WebServer_Acme_Client::dnsValue($keyAuth);
			$code = self::hook($acme['dnsHook'], 'add', $name, $value, $out);
			if ($code !== 0) throw new Exception("the DNS hook failed ($code): " . trim((string) $out));
			sleep(max(0, isset($acme['dnsWait']) ? (int) $acme['dnsWait'] : 30));
			return true;
		}
		return false;
	}

	private static function cleanup($ch, $keyAuth, $identifier, $type, array $acme)
	{
		if ($type === 'http-01') {
			@unlink(self::challengeDir() . DIRECTORY_SEPARATOR . $ch['token']);
			if (!empty($acme['webroot'])) @unlink(rtrim($acme['webroot'], '/\\') . '/.well-known/acme-challenge/' . $ch['token']);
		} elseif ($type === 'dns-01' and !empty($acme['dnsHook'])) {
			$name = '_acme-challenge.' . preg_replace('/^\*\./', '', $identifier);
			self::hook($acme['dnsHook'], 'remove', $name, Q_WebServer_Acme_Client::dnsValue($keyAuth), $out);
		}
	}

	private static function writeToken($dir, $token, $keyAuth)
	{
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) return false;
		if (!is_dir($dir) and !@mkdir($dir, 0755, true) and !is_dir($dir)) return false;
		return Q_WebServer_Certificate_Store::writeAtomic($dir . DIRECTORY_SEPARATOR . $token, $keyAuth, 0644);
	}

	/** Run the DNS hook: HOOK add|remove NAME VALUE, also in QBIX_ACME_* variables. */
	private static function hook($hook, $action, $name, $value, &$out = '')
	{
		$cmd = is_array($hook) ? array_values($hook) : array($hook);
		$cmd = array_merge($cmd, array($action, $name, $value));
		return Q_WebServer_Certificate_Source_Base::run($cmd, $out,
			array('QBIX_ACME_ACTION' => $action, 'QBIX_ACME_NAME' => $name, 'QBIX_ACME_VALUE' => $value));
	}

	// ── The earlier interface, kept for its callers ──────────────────────

	/**
	 * Issue a certificate for domains into $certDir/<first domain>/, as the
	 * earlier client did. Blocking: from a job, a worker or the console.
	 * @method provision
	 * @static
	 * @return {array} array('success' => bool, 'error' => ..., 'cert' => ..., 'key' => ...)
	 */
	static function provision(array $domains, $certDir, $email, $staging = false)
	{
		$config = array('acme' => array('domains' => $domains, 'email' => $email,
			'directory' => $staging ? 'letsencrypt-staging' : 'letsencrypt'));
		$saved = Q_Config::get('Q', 'web', 'https', 'acme', 'dir', null);
		Q_Config::set('Q', 'web', 'https', 'acme', 'dir', $certDir);
		try {
			$r = self::issue($config);
		} finally {
			Q_Config::set('Q', 'web', 'https', 'acme', 'dir', $saved);
		}
		return $r['ok']
			? array('success' => true, 'cert' => $r['cert'], 'key' => $r['key'], 'domains' => $domains, 'expires' => $r['expires'])
			: array('success' => false, 'error' => $r['error']);
	}

	/** Whether a certificate has less than $days left, or is missing. */
	static function needsRenewal($certPath, $days = 30)
	{
		if (!is_file($certPath)) return true;
		$expiry = self::certExpiry($certPath);
		if (!$expiry) return true;
		return ($expiry - time()) < ($days * 86400);
	}

	/** The expiry of a PEM certificate file, as a Unix time. */
	static function certExpiry($certPath)
	{
		$pem = @file_get_contents($certPath);
		if (!$pem) return null;
		$cert = @openssl_x509_parse($pem);
		return $cert['validTo_time_t'] ?? null;
	}

	/** The names in a PEM certificate file: its common name and DNS subjectAltNames. */
	static function certDomains($certPath)
	{
		$pem = @file_get_contents($certPath);
		if (!$pem) return array();
		$cert = @openssl_x509_parse($pem);
		$domains = array();
		if (!empty($cert['subject']['CN'])) $domains[] = $cert['subject']['CN'];
		if (!empty($cert['extensions']['subjectAltName'])) {
			foreach (explode(',', $cert['extensions']['subjectAltName']) as $san) {
				$san = trim($san);
				if (strpos($san, 'DNS:') === 0) {
					$d = substr($san, 4);
					if (!in_array($d, $domains)) $domains[] = $d;
				}
			}
		}
		return $domains;
	}
}
