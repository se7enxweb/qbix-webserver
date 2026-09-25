<?php
/**
 * @module Q
 */
/**
 * What a certificate says, whether it covers a host, and whether this server
 * can issue one for it -- the read side of the certificate subsystem, for the
 * panel (a domain's certificate, the SSL view) and anything else that has to
 * show certificates rather than serve them.
 *
 * Issuing goes through the existing pieces: Q_WebServer_Acme::issue() in a
 * Q_WebServer_Certificate_Job, so it never blocks the event loop, backs off
 * after failures, and a new certificate reaches the listener through the
 * watcher's live swap. A domain asked for here is remembered in its panel
 * record (certificate.acme), and Q_WebServer_Acme::domains() adds it to every
 * later renewal, so it is not dropped the next time the certificate renews.
 *
 * @class Q_WebServer_Certificate_Inspector
 * @static
 */
class Q_WebServer_Certificate_Inspector
{
	/** Days before expiry from which a certificate is reported as expiring. */
	const EXPIRING_DAYS = 21;

	/** Modes that can issue a certificate from inside the server. */
	const ISSUING_MODES = array('acme', 'letsencrypt');

	/**
	 * A certificate's facts.
	 * @method inspect
	 * @static
	 * @param {string} $pemOrFile PEM text, or a file holding it (a chain: the first is read)
	 * @return {array|null} file, subject, issuer, names, notBefore, notAfter, daysLeft,
	 *   selfSigned, fingerprint (sha256, hex) -- null when it cannot be read
	 */
	static function inspect($pemOrFile)
	{
		$file = null;
		$pem = (string) $pemOrFile;
		if ($pem !== '' and strpos($pem, '-----BEGIN') === false) {
			$file = $pem;
			if (!is_file($file) or !is_readable($file)) return null;
			$pem = (string) @file_get_contents($file);
		}
		if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) return null;
		$pem = $m[0];
		$x = @openssl_x509_parse($pem);
		if (!$x) return null;
		$names = array();
		if (!empty($x['subject']['CN'])) $names[] = strtolower((string) $x['subject']['CN']);
		foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $san) {
			$san = trim($san);
			if (strncmp($san, 'DNS:', 4) === 0) {
				$n = strtolower(substr($san, 4));
				if (!in_array($n, $names, true)) $names[] = $n;
			}
		}
		$from = (int) ($x['validFrom_time_t'] ?? 0);
		$to = (int) ($x['validTo_time_t'] ?? 0);
		$name = function ($dn) {
			$dn = (array) $dn;
			foreach (array('CN', 'O', 'OU') as $k) {
				if (!empty($dn[$k])) return is_array($dn[$k]) ? implode(', ', $dn[$k]) : (string) $dn[$k];
			}
			return '';
		};
		$selfSigned = ($x['subject'] ?? null) == ($x['issuer'] ?? null);
		if ($selfSigned and function_exists('openssl_x509_verify')) {
			$pub = @openssl_pkey_get_public($pem);
			if ($pub) $selfSigned = @openssl_x509_verify($pem, $pub) === 1;
		}
		$fp = function_exists('openssl_x509_fingerprint') ? (string) @openssl_x509_fingerprint($pem, 'sha256') : '';
		return array(
			'file' => $file,
			'subject' => $name($x['subject'] ?? array()),
			'issuer' => $name($x['issuer'] ?? array()),
			'names' => $names,
			'notBefore' => $from ?: null,
			'notAfter' => $to ?: null,
			'daysLeft' => $to ? (int) floor(($to - time()) / 86400) : null,
			'selfSigned' => (bool) $selfSigned,
			'fingerprint' => strtolower($fp),
		);
	}

	/**
	 * The certificate the HTTPS listener presents, inspected.
	 * @return {array|null}
	 */
	static function served()
	{
		if (!class_exists('Q_WebServer_Certs', false)) return null;
		$file = Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath;
		return is_string($file) && $file !== '' ? self::inspect($file) : null;
	}

	/** Whether any of these certificate names covers the host (wildcards count, one label deep). */
	static function covers(array $names, $host)
	{
		foreach ($names as $n) {
			if (Q_WebServer_DomainUsage::covers($n, $host)) return true;
		}
		return false;
	}

	/**
	 * Whether this server can issue a certificate under these settings.
	 * @method canIssue
	 * @static
	 * @param {array} $config Q.web.https
	 * @param {string} &$why when it cannot: what to change
	 * @return {bool}
	 */
	static function canIssue(array $config, &$why = '')
	{
		$mode = strtolower((string) ($config['mode'] ?? 'manual'));
		if (in_array($mode, self::ISSUING_MODES, true)) {
			$why = '';
			return true;
		}
		$switch = 'switch Q.web.https.mode to "acme" (with https.acme.email for expiry notices) to have this server issue and renew them (see docs/https.md)';
		switch ($mode) {
			case 'manual':
			case 'files':
			case 'archive':
			case 'pkcs12':
				$file = (string) ($config['cert'] ?? ($config['files']['cert'] ?? ''));
				$why = 'HTTPS uses certificate files' . ($file !== '' ? " ($file)" : '')
					. ' that are managed outside this server, so it cannot issue one: renew them where they come from'
					. ' (for example the hosting control panel), or ' . $switch;
				break;
			case 'certbot':
				$why = 'certbot manages these certificates: run certbot for this domain, or ' . $switch;
				break;
			case 'remote':
				$why = 'the certificate is downloaded from elsewhere: issue it there, or ' . $switch;
				break;
			case 'self-signed':
				$why = 'this server uses a self-signed certificate: ' . $switch;
				break;
			default:
				$why = "the HTTPS mode \"$mode\" cannot issue certificates: " . $switch;
		}
		return false;
	}

	/**
	 * The settings a certificate for this domain is issued under: the
	 * configured names plus this domain and its aliases, the configured first
	 * name kept first, so the certificate the listener serves is the one that
	 * gets reissued.
	 */
	static function issueConfig(array $config, $domain, array $aliases = array())
	{
		$names = Q_WebServer_Acme::domains($config);
		foreach (array_merge(array($domain), $aliases) as $h) {
			$h = strtolower(trim((string) $h));
			if ($h !== '' and !in_array($h, $names, true)) $names[] = $h;
		}
		$config['acme'] = isset($config['acme']) ? (array) $config['acme'] : array();
		$config['acme']['domains'] = $names;
		return $config;
	}

	/** A job's public id: a short hash of its state file, so no path travels. */
	static function jobId($stateFile)
	{
		return substr(sha1((string) $stateFile), 0, 16);
	}

	/**
	 * The state file for a job id, among the certificates the server issues.
	 * @return {string|null}
	 */
	static function jobFile($id)
	{
		if (!is_string($id) or !preg_match('/^[0-9a-f]{16}$/', $id)) return null;
		$dir = Q_WebServer_Acme::dir();
		if (!is_dir($dir)) return null;
		$n = 0;
		foreach ((array) @scandir($dir) as $d) {
			if ($d === '.' or $d === '..' or ++$n > 500) continue;
			$f = $dir . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR . 'state.json';
			if (is_file($f) and self::jobId($f) === $id) return $f;
		}
		return null;
	}

	/**
	 * A job's state, for display: queued, running, succeeded or failed.
	 * @return {array} state, started, lastAttempt, lastSuccess, error, failures, nextAttempt
	 */
	static function jobStatus($stateFile)
	{
		$s = Q_WebServer_Certificate_Job::state($stateFile);
		if (!$s) return array('state' => 'none');
		// The last attempt's outcome is in lastError (cleared on success), not in
		// comparing times: a failure in the same second as an earlier success
		// has equal timestamps.
		if (!empty($s['running'])) {
			$status = 'running';
		} elseif (empty($s['lastAttempt'])) {
			$status = 'queued';
		} elseif (!empty($s['lastError'])) {
			$status = 'failed';
		} else {
			$status = 'succeeded';
		}
		return array(
			'state' => $status,
			'started' => $s['started'] ?? null,
			'lastAttempt' => $s['lastAttempt'] ?? null,
			'lastSuccess' => $s['lastSuccess'] ?? null,
			'error' => $status === 'failed' ? (string) ($s['lastError'] ?? 'failed') : null,
			'failures' => (int) ($s['failures'] ?? 0),
			'nextAttempt' => $s['nextAttempt'] ?? null,
		);
	}

	/**
	 * Start issuing (or reissuing) a certificate that covers this domain.
	 * @method startIssue
	 * @static
	 * @param {string} $domain
	 * @param {array} $aliases its alias host names, certified with it
	 * @param {array} $config Q.web.https
	 * @param {callable|null} $issuer function(array $config): array, Q_WebServer_Acme::issue by default
	 * @param {string} &$why why it cannot start
	 * @return {array|null} job (id), names, state -- null when it cannot start
	 */
	static function startIssue($domain, array $aliases, array $config, $issuer = null, &$why = '')
	{
		if (!self::canIssue($config, $why)) return null;
		$cfg = self::issueConfig($config, $domain, $aliases);
		$files = Q_WebServer_Acme::files($cfg);
		if (!$files) { $why = 'no names to certify'; return null; }
		// Remembered so renewals keep this domain (Q_WebServer_Acme::domains).
		if (class_exists('Q_WebServer_Domains')) {
			$asked = array_values(array_unique(array_merge(array($domain), $aliases)));
			Q_WebServer_Domains::update($domain, function ($rec) use ($asked) {
				$cert = is_array($rec['certificate'] ?? null) ? $rec['certificate'] : array();
				$cert['acme'] = true;
				$cert['names'] = array_values(array_unique(array_merge((array) ($cert['names'] ?? array()), $asked)));
				$rec['certificate'] = $cert;
				return $rec;
			});
		}
		$work = $issuer ?: array('Q_WebServer_Acme', 'issue');
		Q_WebServer_Certificate_Job::start('acme:' . $files[0], $files[2], function () use ($work, $cfg) {
			return call_user_func($work, $cfg);
		}, true);
		return array('job' => self::jobId($files[2]), 'names' => $cfg['acme']['domains']) + self::jobStatus($files[2]);
	}

	/**
	 * Everything the panel shows about one domain's certificate.
	 * @method forDomain
	 * @static
	 * @param {string} $domain
	 * @param {array} $hosts the domain's host names (itself, aliases, subdomains)
	 * @param {array} $config Q.web.https
	 * @param {array} $opts for tests: certFile (the served certificate)
	 * @return {array} domain, certificate, served, covered (host => bool), notCovered, warnings,
	 *   canIssue, issueWhy, job
	 */
	static function forDomain($domain, array $hosts, array $config, array $opts = array())
	{
		$cert = array_key_exists('certFile', $opts) ? ($opts['certFile'] ? self::inspect($opts['certFile']) : null) : self::served();
		$covered = array();
		foreach (array_values(array_unique(array_merge(array($domain), $hosts))) as $h) {
			$covered[$h] = $cert ? self::covers($cert['names'], $h) : false;
		}
		$notCovered = array_keys(array_filter($covered, function ($c) { return !$c; }));
		$warnings = array();
		if (!$cert) {
			$warnings[] = 'no certificate is being served';
		} else {
			if ($cert['daysLeft'] !== null and $cert['daysLeft'] < 0) {
				$warnings[] = 'the certificate expired ' . (-$cert['daysLeft']) . ' days ago';
			} elseif ($cert['daysLeft'] !== null and $cert['daysLeft'] <= self::EXPIRING_DAYS) {
				$warnings[] = 'the certificate expires in ' . $cert['daysLeft'] . ' days';
			}
			if ($cert['selfSigned']) $warnings[] = 'the certificate is self-signed: browsers will warn';
		}
		if ($notCovered) $warnings[] = 'not covered by the certificate: ' . implode(', ', $notCovered);
		$why = '';
		$can = self::canIssue($config, $why);
		$job = null;
		if ($can) {
			$files = Q_WebServer_Acme::files(self::issueConfig($config, $domain));
			if ($files and is_file($files[2])) $job = array('job' => self::jobId($files[2])) + self::jobStatus($files[2]);
		}
		return array(
			'domain' => $domain,
			'certificate' => $cert,
			'served' => (bool) $cert,
			'covered' => $covered,
			'notCovered' => $notCovered,
			'warnings' => $warnings,
			'canIssue' => $can,
			'issueWhy' => $why,
			'job' => $job,
		);
	}
}
