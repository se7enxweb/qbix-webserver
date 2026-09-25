<?php
/**
 * @module Q
 */
/**
 * The control panel's SSL tab: what is configured and what is served, every
 * certificate the server knows sorted by expiry, the settings the panel may
 * change, renewal and reload, and a bounded history of certificate events.
 *
 * Certificates are read through Q_WebServer_Certificate_Inspector, which
 * parses the public certificate only: no key material is ever read into an
 * answer. Key files are named by path, never shown.
 *
 * Settings changed here are kept in the panel's store (acl/panel.json, key
 * "ssl", written under its lock) and laid over Q.web.https when the server
 * starts and at once when they change; they win over the configuration file,
 * and each value says where it came from.
 *
 * The history lives in the state directory (<stateDir>/ssl/history.json, or
 * next to the certificates when there is none), bounded to HISTORY_MAX
 * entries, written through a lock by whichever process saw the event.
 *
 * @class Q_WebServer_Certificate_Admin
 * @static
 */
class Q_WebServer_Certificate_Admin
{
	/** The modes Q.web.https.mode accepts. */
	const MODES = array('manual', 'files', 'archive', 'pkcs12', 'acme', 'letsencrypt', 'certbot', 'remote', 'self-signed');

	/** Entries kept in the history; older ones are dropped. */
	const HISTORY_MAX = 200;

	/** Days left under which a certificate is critical (warning below Inspector::EXPIRING_DAYS). */
	const CRITICAL_DAYS = 7;

	/** @var string|null the history file, for tests; null for the default */
	static $historyFile = null;

	/** @var callable|null what issuing calls instead of Q_WebServer_Acme::issue, for tests */
	static $issuer = null;

	/** The effective https settings, panel overrides included. */
	static function config()
	{
		return (array) Q_Config::get('Q', 'web', 'https', array());
	}

	/** The settings the panel has stored, or an empty array. */
	static function overrides()
	{
		if (!class_exists('Q_WebServer_Panel_Store')) return array();
		$acl = Q_WebServer_Panel_Store::aclLoad();
		return is_array($acl['ssl'] ?? null) ? $acl['ssl'] : array();
	}

	/**
	 * Lay the panel's settings over Q.web.https. Called when the server
	 * starts, after the configuration file, and after a change here.
	 * @param {array|null} $o null for what the store holds
	 */
	static function applyOverrides(array $o = null)
	{
		if ($o === null) $o = self::overrides();
		if (isset($o['mode'])) Q_Config::set('Q', 'web', 'https', 'mode', $o['mode']);
		foreach (array('email', 'directory', 'renewAt', 'domains') as $k) {
			if (array_key_exists($k, $o)) Q_Config::set('Q', 'web', 'https', 'acme', $k, $o[$k]);
		}
	}

	/** expired, critical, warning or ok, from the days left. */
	static function state($daysLeft)
	{
		if ($daysLeft === null) return 'unknown';
		if ($daysLeft < 0) return 'expired';
		if ($daysLeft <= self::CRITICAL_DAYS) return 'critical';
		if ($daysLeft <= Q_WebServer_Certificate_Inspector::EXPIRING_DAYS) return 'warning';
		return 'ok';
	}

	/** The key's type and size, read from the certificate's public key. */
	static function keyInfo($certFile)
	{
		if (!is_string($certFile) or !is_file($certFile) or !is_readable($certFile)) return null;
		$pem = (string) @file_get_contents($certFile);
		if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) return null;
		$pub = @openssl_pkey_get_public($m[0]);
		if (!$pub) return null;
		$d = @openssl_pkey_get_details($pub);
		if (!$d) return null;
		$types = array(OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_EC => 'EC');
		$type = $types[$d['type']] ?? 'other';
		$out = array('type' => $type, 'bits' => (int) ($d['bits'] ?? 0));
		if ($type === 'EC' and !empty($d['ec']['curve_name'])) $out['curve'] = (string) $d['ec']['curve_name'];
		return $out;
	}

	/** One certificate as the tab shows it, or null when the file is not a certificate. */
	static function describe($file, $source)
	{
		$c = Q_WebServer_Certificate_Inspector::inspect($file);
		if (!$c) return null;
		$c['source'] = array($source);
		$c['key'] = self::keyInfo($file);
		$c['state'] = (!empty($c['notAfter']) and $c['notAfter'] < time()) ? 'expired' : self::state($c['daysLeft']);
		return $c;
	}

	/** GET ssl/overview */
	static function overview()
	{
		$cfg = self::config();
		$mode = strtolower((string) ($cfg['mode'] ?? 'manual'));
		$servedFile = null;
		if (class_exists('Q_WebServer_Certs', false)) {
			$servedFile = Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath;
		}
		$served = $servedFile ? self::describe($servedFile, 'served') : null;
		$why = '';
		$can = Q_WebServer_Certificate_Inspector::canIssue($cfg, $why);
		$usingFallback = class_exists('Q_WebServer_Certs', false) && Q_WebServer_Certs::$source === 'self-signed';
		return array(
			'mode' => $mode,
			'modes' => self::MODES,
			'configured' => array(
				'cert' => isset($cfg['cert']) ? (string) $cfg['cert'] : null,
				'key' => isset($cfg['key']) ? (string) $cfg['key'] : null,
			),
			'served' => $served,
			'fallback' => class_exists('Q_WebServer_Certs') ? Q_WebServer_Certs::fallback() : 'self-signed',
			'usingFallback' => $usingFallback,
			'watch' => array(
				'interval' => (float) ($cfg['watchInterval'] ?? 60),
				'lastCheck' => self::latest(class_exists('Q_WebServer_Certs', false) ? Q_WebServer_Certs::$lastCheck : null, self::marked('last-check')),
				'lastChange' => self::latest(class_exists('Q_WebServer_Certs', false) ? Q_WebServer_Certs::$lastChange : null, self::marked('last-change')),
			),
			'canIssue' => $can,
			'issueWhy' => $why,
			'settings' => self::settings(),
		);
	}

	private static function latest($a, $b)
	{
		return ($a === null and $b === null) ? null : max((int) $a, (int) $b);
	}

	/** GET ssl/certs -- every certificate the server knows, soonest-expiring first. */
	static function certs()
	{
		$cfg = self::config();
		$files = array();
		if (class_exists('Q_WebServer_Certs', false)) {
			if (Q_WebServer_Certs::$activeCert) $files[] = array(Q_WebServer_Certs::$activeCert, 'served');
			elseif (Q_WebServer_Certs::$certPath) $files[] = array(Q_WebServer_Certs::$certPath, 'served');
		}
		if (!empty($cfg['cert'])) $files[] = array((string) $cfg['cert'], 'configured');
		if (class_exists('Q_WebServer_Certificate_Store')) {
			$store = new Q_WebServer_Certificate_Store();
			$files[] = array($store->certFile(), 'self-signed store');
		}
		if (class_exists('Q_WebServer_Acme')) {
			$dir = Q_WebServer_Acme::dir();
			if (is_dir($dir)) {
				$n = 0;
				foreach ((array) @scandir($dir) as $d) {
					if ($d === '.' or $d === '..' or ++$n > 500) continue;
					$f = $dir . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR . 'fullchain.pem';
					if (is_file($f)) $files[] = array($f, 'issued (' . $d . ')');
				}
			}
		}
		// The same certificate under several roles is listed once, with each role.
		$byPrint = array();
		foreach ($files as $pair) {
			$c = self::describe($pair[0], $pair[1]);
			if (!$c) continue;
			$k = $c['fingerprint'] !== '' ? $c['fingerprint'] : $pair[0];
			if (isset($byPrint[$k])) {
				if (!in_array($pair[1], $byPrint[$k]['source'], true)) $byPrint[$k]['source'][] = $pair[1];
				continue;
			}
			$byPrint[$k] = $c;
		}
		$list = array_values($byPrint);
		usort($list, function ($a, $b) {
			$x = $a['notAfter'] ?? PHP_INT_MAX;
			$y = $b['notAfter'] ?? PHP_INT_MAX;
			return $x <=> $y;
		});
		return array('certificates' => $list, 'count' => count($list));
	}

	/** The settings the panel may change, with their values and where each came from. */
	static function settings()
	{
		$cfg = self::config();
		$o = self::overrides();
		$acme = (array) ($cfg['acme'] ?? array());
		$src = function ($key) use ($o) { return array_key_exists($key, $o) ? 'panel' : 'config'; };
		return array(
			'mode' => array('value' => (string) ($cfg['mode'] ?? 'manual'), 'source' => $src('mode'), 'restart' => true),
			'email' => array('value' => $acme['email'] ?? null, 'source' => $src('email'), 'restart' => false),
			'directory' => array('value' => $acme['directory'] ?? 'letsencrypt', 'source' => $src('directory'), 'restart' => false),
			'renewAt' => array('value' => isset($acme['renewAt']) ? (float) $acme['renewAt'] : 0.33, 'source' => $src('renewAt'), 'restart' => false),
			'domains' => array('value' => array_values((array) ($acme['domains'] ?? array())), 'source' => $src('domains'), 'restart' => false),
		);
	}

	/** Whether $name is a host name a certificate can carry (a leading "*." allowed). */
	static function validHost($name)
	{
		$name = strtolower((string) $name);
		if (strlen($name) > 253) return false;
		return (bool) preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name);
	}

	/**
	 * Check a settings change. Returns the clean values; $errors gets one
	 * message per problem.
	 * @param {array} $in mode, email, directory, renewAt, domains (any subset)
	 * @param {array} $errors
	 * @return {array}
	 */
	static function validateSettings(array $in, &$errors)
	{
		$errors = array();
		$out = array();
		$cfg = self::config();
		if (array_key_exists('mode', $in)) {
			$mode = strtolower(trim((string) $in['mode']));
			if (!in_array($mode, self::MODES, true)) {
				$errors[] = 'mode must be one of ' . implode(', ', self::MODES);
			} else {
				$out['mode'] = $mode;
			}
		}
		if (array_key_exists('email', $in)) {
			$e = trim((string) $in['email']);
			if ($e !== '' and !filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = 'email is not a valid address';
			else $out['email'] = $e === '' ? null : $e;
		}
		if (array_key_exists('directory', $in)) {
			$d = trim((string) $in['directory']);
			if (!in_array($d, array('letsencrypt', 'letsencrypt-staging'), true)
				and !preg_match('#^https://[^\s/]+(/\S*)?$#', $d)) {
				$errors[] = 'directory must be letsencrypt, letsencrypt-staging or an https:// ACME directory URL';
			} else {
				$out['directory'] = $d;
			}
		}
		if (array_key_exists('renewAt', $in)) {
			$r = $in['renewAt'];
			if (!is_numeric($r) or (float) $r < 0.05 or (float) $r > 0.9) {
				$errors[] = 'renewAt is the share of the lifetime left when renewal starts: between 0.05 and 0.9';
			} else {
				$out['renewAt'] = round((float) $r, 3);
			}
		}
		if (array_key_exists('domains', $in)) {
			$list = is_array($in['domains']) ? $in['domains'] : preg_split('/[\s,]+/', (string) $in['domains']);
			$clean = array();
			foreach ((array) $list as $h) {
				$h = strtolower(trim((string) $h));
				if ($h === '') continue;
				if (!self::validHost($h)) { $errors[] = "\"$h\" is not a host name a certificate can carry"; continue; }
				if (!in_array($h, $clean, true)) $clean[] = $h;
			}
			if (count($clean) > 100) $errors[] = 'at most 100 host names';
			else $out['domains'] = $clean;
		}
		// What the new mode needs, with the other values as they will be.
		if (isset($out['mode'])) {
			$mode = $out['mode'];
			if (in_array($mode, array('manual', 'files'), true)) {
				$cert = (string) ($cfg['cert'] ?? '');
				$key = (string) ($cfg['key'] ?? '');
				if ($cert === '' or $key === '' or !is_file($cert) or !is_file($key)) {
					$errors[] = "mode $mode needs https.cert and https.key set to existing files first";
				} elseif (class_exists('Q_WebServer_Certs') and !Q_WebServer_Certs::pairUsable($cert, $key)) {
					$errors[] = "mode $mode: https.cert and https.key are not a usable pair";
				}
			}
			if (in_array($mode, Q_WebServer_Certificate_Inspector::ISSUING_MODES, true)) {
				$domains = $out['domains'] ?? (array) ($cfg['acme']['domains'] ?? array());
				if (!$domains and empty($cfg['domain'])) $errors[] = "mode $mode needs at least one host name to certify";
			}
		}
		return $out;
	}

	/**
	 * POST ssl/settings {mode?, email?, directory?, renewAt?, domains?, confirm}
	 * @return {array} with a status of 400 (invalid), 409 (confirm) or 200
	 */
	static function saveSettings(array $in)
	{
		$errors = array();
		$clean = self::validateSettings($in, $errors);
		if ($errors) return array('status' => 400, 'error' => implode('; ', $errors), 'errors' => $errors);
		if (!$clean) return array('status' => 400, 'error' => 'Nothing to change');
		$current = self::settings();
		$changes = array();
		foreach ($clean as $k => $v) {
			if (($current[$k]['value'] ?? null) !== $v) $changes[$k] = array('from' => $current[$k]['value'] ?? null, 'to' => $v);
		}
		if (!$changes) return array('ok' => true, 'changes' => array(), 'note' => 'Already set that way');
		$restart = isset($changes['mode']);
		if (empty($in['confirm'])) {
			return array('status' => 409, 'confirm' => true, 'changes' => $changes, 'restart' => $restart,
				'error' => 'Changing the certificate settings'
					. ($restart ? ' (the mode takes effect when the server restarts)' : ' (used from the next certificate check)')
					. '. Send confirm to proceed');
		}
		if (!class_exists('Q_WebServer_Panel_Store')) return array('status' => 500, 'error' => 'No panel store');
		$ok = Q_WebServer_Panel_Store::aclUpdate(function ($acl) use ($clean) {
			$ssl = is_array($acl['ssl'] ?? null) ? $acl['ssl'] : array();
			foreach ($clean as $k => $v) $ssl[$k] = $v;
			$acl['ssl'] = $ssl;
			return $acl;
		});
		if (!$ok) {
			$p = Q_WebServer_Panel_Store::problem();
			return array('status' => 503, 'error' => 'The panel store refused the change'
				. ($p ? ': ' . Q_WebServer_Panel_Store::problemMessage($p) : ''));
		}
		self::applyOverrides();
		self::record('settings', array('changes' => array_keys($changes)));
		return array('ok' => true, 'changes' => $changes, 'restart' => $restart,
			'note' => $restart
				? 'Saved. The new mode takes effect when the server restarts.'
				: 'Saved. It is used from the next certificate check.');
	}

	/**
	 * POST ssl/renew {confirm} -- renew or issue the configured certificate
	 * as a background job, in a mode that issues.
	 */
	static function renew(array $in)
	{
		$cfg = self::config();
		$why = '';
		if (!Q_WebServer_Certificate_Inspector::canIssue($cfg, $why)) return array('status' => 400, 'error' => $why);
		$names = class_exists('Q_WebServer_Acme') ? Q_WebServer_Acme::domains($cfg) : array();
		if (!$names) return array('status' => 400, 'error' => 'No host names to certify: set the domains first');
		if (empty($in['confirm'])) {
			return array('status' => 409, 'confirm' => true, 'names' => $names,
				'error' => 'This asks the certificate authority for a certificate naming ' . implode(', ', $names)
					. '; each of them must reach this server over HTTP for the challenge. Send confirm to proceed');
		}
		$files = Q_WebServer_Acme::files($cfg);
		if (!$files) return array('status' => 400, 'error' => 'No host names to certify');
		$work = self::$issuer ?: array('Q_WebServer_Acme', 'issue');
		Q_WebServer_Certificate_Job::start('acme:' . $files[0], $files[2], function () use ($work, $cfg) {
			return call_user_func($work, $cfg);
		}, true);
		self::record('renew requested', array('hosts' => $names));
		return array('status' => 202, 'job' => Q_WebServer_Certificate_Inspector::jobId($files[2]), 'names' => $names)
			+ Q_WebServer_Certificate_Inspector::jobStatus($files[2]);
	}

	/** POST ssl/reload -- look at the certificate files again and put the current pair in front of new connections. */
	static function reload()
	{
		if (!class_exists('Q_WebServer_Certs', false) or !Q_WebServer_Certs::$certPath) {
			return array('status' => 400, 'error' => 'HTTPS is not running on this server');
		}
		$before = Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath;
		$was = $before ? Q_WebServer_Certificate_Inspector::inspect($before) : null;
		$changed = Q_WebServer_Certs::check();
		if (!$changed) Q_WebServer_Certs::reloadServerCerts();
		$now = self::describe(Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath, 'served');
		self::record('reloaded', array('fingerprint' => $now['fingerprint'] ?? null,
			'changed' => ($was['fingerprint'] ?? null) !== ($now['fingerprint'] ?? null)));
		return array('ok' => true, 'served' => $now,
			'changed' => ($was['fingerprint'] ?? null) !== ($now['fingerprint'] ?? null));
	}

	/**
	 * Note when something happened (the watcher's last check, the last
	 * certificate change) beside the history, so the panel sees it from
	 * whichever process answers.
	 */
	static function mark($name)
	{
		// Only where there is a state directory: never beside the certificates.
		if (!self::$historyFile and !self::stateDir()) return false;
		$dir = dirname(self::historyFile());
		if (!is_dir($dir) and !@mkdir($dir, 0700, true)) return false;
		return @touch($dir . DIRECTORY_SEPARATOR . $name);
	}

	/** When mark($name) was last called, or null. */
	static function marked($name)
	{
		$f = dirname(self::historyFile()) . DIRECTORY_SEPARATOR . $name;
		clearstatcache(true, $f);
		return is_file($f) ? (int) filemtime($f) : null;
	}

	// ── History ─────────────────────────────────────────────────────────

	/** The server's state directory, or null when it has none. */
	static function stateDir()
	{
		if (!class_exists('Q_WebServer_Layout') or !class_exists('Q_Config', false)) return null;
		return Q_WebServer_Layout::stateDir(Q_Config::get('Q', 'webserver', 'confDir', null));
	}

	/** Where the history is kept. */
	static function historyFile()
	{
		if (self::$historyFile) return self::$historyFile;
		$state = self::stateDir();
		$dir = $state ? rtrim($state, '/') . DIRECTORY_SEPARATOR . 'ssl' : Q_WebServer_Certs::certsDir();
		return $dir . DIRECTORY_SEPARATOR . 'history.json';
	}

	/** What an event's details may carry into the history: scalars and host lists, never key material. */
	static function cleanInfo(array $info)
	{
		$out = array();
		foreach ($info as $k => $v) {
			$k = (string) $k;
			if (preg_match('/key|pem|secret|password|token/i', $k)) continue;
			if (is_scalar($v) or $v === null) {
				$v = is_string($v) ? substr($v, 0, 500) : $v;
				if (is_string($v) and strpos($v, '-----BEGIN') !== false) continue;
				$out[$k] = $v;
			} elseif (is_array($v)) {
				// Lists of names only: a nested record (a whole certificate
				// with its key, say) is left out, never flattened.
				$flat = array();
				foreach ($v as $i => $x) {
					if (!is_int($i) or !is_scalar($x)) continue;
					$x = (string) $x;
					if (strpos($x, '-----BEGIN') !== false) continue;
					$flat[] = substr($x, 0, 253);
				}
				if ($flat) $out[$k] = array_slice($flat, 0, 100);
			}
		}
		return $out;
	}

	/** Add an entry to the history, keeping the newest HISTORY_MAX. */
	static function record($event, array $info = array())
	{
		$file = self::historyFile();
		$dir = dirname($file);
		if (!is_dir($dir) and !@mkdir($dir, 0700, true)) return false;
		$lock = @fopen($file . '.lock', 'c');
		if (!$lock) return false;
		try {
			@flock($lock, LOCK_EX);
			$list = is_file($file) ? json_decode((string) @file_get_contents($file), true) : array();
			if (!is_array($list)) $list = array();
			$entry = array('time' => time(), 'event' => (string) $event, 'info' => self::cleanInfo($info));
			// The same event again (a watcher reporting one problem each
			// interval) counts on the last entry instead of filling the history.
			$last = $list ? $list[count($list) - 1] : null;
			if ($last and $last['event'] === $entry['event'] and $last['info'] === $entry['info']) {
				$entry['count'] = (int) ($last['count'] ?? 1) + 1;
				$entry['first'] = (int) ($last['first'] ?? $last['time']);
				array_pop($list);
			}
			$list[] = $entry;
			if (count($list) > self::HISTORY_MAX) $list = array_slice($list, -self::HISTORY_MAX);
			return Q_WebServer_Certificate_Store::writeAtomic($file,
				json_encode($list, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0600);
		} finally {
			@flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** GET ssl/history -- newest first, with the current issuing jobs. */
	static function history($limit = 100)
	{
		$file = self::historyFile();
		$list = is_file($file) ? json_decode((string) @file_get_contents($file), true) : array();
		if (!is_array($list)) $list = array();
		$list = array_reverse($list);
		$jobs = array();
		if (class_exists('Q_WebServer_Acme')) {
			$dir = Q_WebServer_Acme::dir();
			if (is_dir($dir)) {
				$n = 0;
				foreach ((array) @scandir($dir) as $d) {
					if ($d === '.' or $d === '..' or ++$n > 500) continue;
					$f = $dir . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR . 'state.json';
					if (is_file($f)) $jobs[] = array('name' => $d, 'job' => Q_WebServer_Certificate_Inspector::jobId($f))
						+ Q_WebServer_Certificate_Inspector::jobStatus($f);
				}
			}
		}
		return array('entries' => array_slice($list, 0, max(1, min((int) $limit, self::HISTORY_MAX))),
			'total' => count($list), 'jobs' => $jobs, 'file' => $file);
	}
}
