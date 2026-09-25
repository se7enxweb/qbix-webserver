<?php
/**
 * @module Q
 */

/**
 * Who gets into the control panel, and with what: the stored password, the
 * default key, sessions, and the events the observers act on.
 *
 * Passwords are kept as bcrypt hashes (Q.panel.bcryptCost, 12 by default,
 * held between 10 and 15) in local/panel.json beside the application. A
 * stored hash made with another cost, or an older format, is replaced on the
 * next successful sign-in. bcrypt reads only 72 bytes, so nothing longer is
 * ever accepted, as a new password or at sign-in.
 *
 * Until a password is chosen, the default key (Q.panel.defaultPassword,
 * "panel") signs in -- once: the session it gives can do nothing but change
 * it (Q_WebServer_Panel_DefaultPasswordObserver). Setting the default to null
 * or "" switches it off, and a password is then set in the page from this
 * machine or with `qbixctl panel:password`. The default is never written to
 * disk.
 *
 * @class Q_WebServer_Panel_Auth
 * @static
 */
class Q_WebServer_Panel_Auth
{
	const SESSION_SECONDS = 604800; // 7 days
	const BUILTIN_DEFAULT = 'panel';

	// ── The settings file ───────────────────────────────────────────────

	/** The settings file: the panel's, unless a caller names another. */
	static function path($path = null)
	{
		return $path ?: Q_WebServer_Panel::panelConfigPath();
	}

	/**
	 * Whether $path means the panel's own store (Q_WebServer_Panel_Store:
	 * acl/ and sessions/, under the trust rule) rather than a single file a
	 * caller names -- a test's, say, which keeps the one-file format.
	 */
	static function isStore($path = null)
	{
		return $path === null or $path === '' or $path === Q_WebServer_Panel_Store::aclFile();
	}

	/** Why the panel's store cannot be believed (sign-in is then refused), or null. */
	static function storageProblem($path = null)
	{
		return self::isStore($path) ? Q_WebServer_Panel_Store::problem() : null;
	}

	/** The answer to a sign-in, setup or change while the store is refused. */
	protected static function lockedAnswer($problem)
	{
		return array(503, array('error' => Q_WebServer_Panel_Store::problemMessage($problem), 'locked' => true));
	}

	/**
	 * Its contents, or an empty array. A file caught half-written by a writer
	 * that does not rename into place (an older server, a hand edit) is read
	 * again rather than taken for "no password, no sessions".
	 */
	static function load($path = null)
	{
		if (self::isStore($path)) return Q_WebServer_Panel_Store::aclLoad();
		$path = self::path($path);
		for ($try = 0; $try < 5; ++$try) {
			if (!is_file($path)) return array();
			$raw = (string) @file_get_contents($path);
			$c = json_decode($raw, true);
			if (is_array($c)) return $c;
			usleep(2000);
		}
		return array();
	}

	/**
	 * Write it, readable by the server's user only. Written beside the file
	 * and renamed into place, so a reader in another worker never sees it
	 * truncated: a plain write empties the file first, and a panel request
	 * landing in that moment read "no password, no sessions" and showed a
	 * signed-in visitor the sign-in form.
	 */
	static function save(array $config, $path = null)
	{
		if (self::isStore($path)) return Q_WebServer_Panel_Store::aclSave($config);
		$path = self::path($path);
		$dir = dirname($path);
		if (!is_dir($dir)) @mkdir($dir, 0700, true);
		$tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT)) === false) return false;
		@chmod($tmp, 0600);
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		return true;
	}

	// ── Hashing ─────────────────────────────────────────────────────────

	/** The bcrypt cost: Q.panel.bcryptCost, held between 10 and 15. */
	static function cost()
	{
		$c = class_exists('Q_Config', false) ? Q_Config::get('Q', 'panel', 'bcryptCost', 12) : 12;
		return max(10, min(15, is_numeric($c) ? (int) $c : 12));
	}

	/** A bcrypt hash of a password. */
	static function hash($password)
	{
		return password_hash((string) $password, PASSWORD_BCRYPT, array('cost' => self::cost()));
	}

	/**
	 * Whether a password matches a stored hash. A bcrypt (or any
	 * password_hash()) hash is checked with password_verify(); an unsalted
	 * SHA-256 or SHA-1 hex digest -- an older format -- with hash_equals().
	 * @return {array} array(matched, needsRehash)
	 */
	static function check($password, $hash)
	{
		$password = (string) $password;
		$hash = (string) $hash;
		if ($hash === '' or strlen($password) > Q_WebServer_Panel_PasswordPolicy::MAX_BYTES) return array(false, false);
		$info = password_get_info($hash);
		if (!empty($info['algo'])) {
			if (!password_verify($password, $hash)) return array(false, false);
			return array(true, password_needs_rehash($hash, PASSWORD_BCRYPT, array('cost' => self::cost())));
		}
		if (preg_match('/^[0-9a-f]{64}$/i', $hash)) return array(hash_equals(strtolower($hash), hash('sha256', $password)), true);
		if (preg_match('/^[0-9a-f]{40}$/i', $hash)) return array(hash_equals(strtolower($hash), sha1($password)), true);
		return array(false, false);
	}

	// ── Credentials ─────────────────────────────────────────────────────

	/** Whether a password has been chosen (not the default key). */
	static function hasPassword($path = null)
	{
		$c = self::load($path);
		return !empty($c['passwordHash']);
	}

	/** The default key, or null when it is switched off. */
	static function defaultPassword()
	{
		$d = class_exists('Q_Config', false)
			? Q_Config::get('Q', 'panel', 'defaultPassword', self::BUILTIN_DEFAULT) : self::BUILTIN_DEFAULT;
		return (is_string($d) and $d !== '') ? $d : null;
	}

	/** Whether the default key signs in: no password chosen, and not switched off. */
	static function defaultInForce($path = null)
	{
		// A store that fails the trust rule reads as "no password": the
		// default key must not become the way in.
		if (self::storageProblem($path) !== null) return false;
		return self::defaultPassword() !== null and !self::hasPassword($path);
	}

	/**
	 * Whether the default key is accepted for this request: it is in force,
	 * and -- with Q.panel.defaultLocalOnly -- the request comes from this
	 * machine or carries the dashboard token.
	 */
	static function defaultAllowedFor($parsed)
	{
		if (!self::defaultInForce()) return false;
		if (!Q_Config::get('Q', 'panel', 'defaultLocalOnly', false)) return true;
		return Q_WebServer_Panel::isLocal($parsed) || Q_WebServer::hasAdminCredential($parsed);
	}

	/** Whether this request could sign in at all. */
	static function canSignIn($parsed)
	{
		return self::hasPassword() || self::defaultAllowedFor($parsed);
	}

	/** What a new password is checked against: the default, the brand, the host, the current hash. */
	static function policyContext($parsed = null, $path = null)
	{
		$c = self::load($path);
		return array(
			'default' => self::defaultPassword(),
			'brand' => class_exists('Q_WebServer', false) ? Q_WebServer::brand()
				: (class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'brand', 'Qbix Server') : 'Qbix Server'),
			'host' => is_array($parsed) ? (string) ($parsed['headers']['host'] ?? '') : '',
			'currentHash' => (string) ($c['passwordHash'] ?? ''),
		);
	}

	/**
	 * Store a new password, after the policy: every path that sets one comes
	 * here. The stored credential is marked as chosen (default false), every
	 * must-change mark is cleared, and sessions are ended except $keepToken
	 * (all of them when $revokeSessions and no $keepToken).
	 * @method storePassword
	 * @static
	 * @return {array} ok, and path, or error and failed (the rules it broke)
	 */
	static function storePassword($password, $path = null, $revokeSessions = false, $keepToken = null, array $context = null)
	{
		$store = self::isStore($path);
		$path = self::path($path);
		if ($store and ($problem = Q_WebServer_Panel_Store::problem()) !== null) {
			return array('ok' => false, 'error' => Q_WebServer_Panel_Store::problemMessage($problem), 'locked' => true);
		}
		$failed = Q_WebServer_Panel_PasswordPolicy::check($password, $context ?? self::policyContext(null, $store ? null : $path));
		if ($failed) {
			return array('ok' => false, 'error' => 'The password does not meet the rules.', 'failed' => $failed);
		}
		if ($store) {
			$hash = self::hash($password);
			$ok = Q_WebServer_Panel_Store::aclUpdate(function (array $c) use ($hash) {
				$c['passwordHash'] = $hash;
				$c['default'] = false;
				return $c;
			});
			if (!$ok) return array('ok' => false, 'error' => 'Failed to write ' . $path);
			// Sessions end, but the one kept; every must-change mark is cleared.
			if ($keepToken !== null or $revokeSessions) {
				Q_WebServer_Panel_Store::sessionsRevoke($keepToken);
			} else {
				Q_WebServer_Panel_Store::sessionsClearMustChange();
			}
			return array('ok' => true, 'path' => $path);
		}
		$config = self::load($path);
		$config['passwordHash'] = self::hash($password);
		$config['default'] = false;
		$sessions = is_array($config['sessions'] ?? null) ? $config['sessions'] : array();
		if ($keepToken !== null) {
			$sessions = isset($sessions[$keepToken]) ? array($keepToken => $sessions[$keepToken]) : array();
		} elseif ($revokeSessions) {
			$sessions = array();
		}
		$config['sessions'] = (object) $sessions;
		unset($config['mustChange']);
		if (!self::save($config, $path)) return array('ok' => false, 'error' => 'Failed to write config to ' . $path);
		return array('ok' => true, 'path' => $path);
	}

	// ── Sessions ────────────────────────────────────────────────────────

	/** The session token a request carries: Bearer, X-Panel-Token, or the cookie. */
	static function requestToken($parsed)
	{
		$auth = (string) ($parsed['headers']['authorization'] ?? '');
		if (strpos($auth, 'Bearer ') === 0) return substr($auth, 7);
		$t = (string) ($parsed['headers']['x-panel-token'] ?? '');
		if ($t !== '') return $t;
		return (string) ($parsed['cookies']['Q_panel_token'] ?? '');
	}

	/**
	 * The control panel session this request carries, from any of the places
	 * a browser or a script puts it (Authorization: Bearer, X-Panel-Token, or
	 * the Q_panel_token cookie), checked against the stored sessions. The one
	 * answer every /Q/ view and endpoint asks: the dashboard, phpinfo,
	 * metrics, the panel page, the shell's HTTP routes and its WebSocket.
	 *
	 * @return {array|null} array('token', 'mustChange', 'from' => bearer|header|cookie[N]), or null
	 */
	static function sessionFromRequest($parsed, $path = null)
	{
		$h = (array) ($parsed['headers'] ?? array());
		$candidates = array();
		$auth = (string) ($h['authorization'] ?? '');
		if (strpos($auth, 'Bearer ') === 0) $candidates['bearer'] = substr($auth, 7);
		if ((string) ($h['x-panel-token'] ?? '') !== '') $candidates['header'] = (string) $h['x-panel-token'];
		if ((string) ($parsed['cookies']['Q_panel_token'] ?? '') !== '') $candidates['cookie'] = (string) $parsed['cookies']['Q_panel_token'];
		// A browser can hold more than one Q_panel_token (an old one set for
		// another path, or by an earlier sign-in); the parsed cookies keep one
		// of them. Every value in the raw header is a candidate.
		$raw = $h['cookie'] ?? '';
		foreach ((array) $raw as $line) {
			if (preg_match_all('/(?:^|;)\s*Q_panel_token=([^;]*)/', (string) $line, $m)) {
				foreach ($m[1] as $i => $v) {
					$v = rawurldecode(trim($v));
					if ($v !== '' && !in_array($v, $candidates, true)) $candidates['cookie' . $i] = $v;
				}
			}
		}
		// A stale token in one place (an old cookie, a tab's leftover
		// storage) must not hide a live one in another: the first live one wins.
		foreach ($candidates as $from => $token) {
			if (self::sessionLive($token, $path)) {
				return array('token' => $token, 'mustChange' => self::mustChange($token, $path), 'from' => $from);
			}
		}
		return null;
	}

	/**
	 * The Set-Cookie value that carries a session to every /Q/ view, or that
	 * clears it ($token null). Set by the server on sign-in, so it no longer
	 * depends on the page's script having run: Path=/ so the dashboard,
	 * phpinfo and the shell see it as the panel does; SameSite=Lax so a
	 * top-level visit from a bookmark or another site still carries it (every
	 * state-changing call also needs the token in a header); Secure over TLS.
	 * Readable by script on purpose: the panel and the shell send it back in
	 * the X-Panel-Token header, which is what makes their writes CSRF-safe.
	 */
	static function sessionCookie($token, $parsed)
	{
		$secure = !empty($parsed['_https']) || !empty($parsed['https']) || ($parsed['httpVersion'] ?? '') === '2';
		$v = 'Q_panel_token=' . ($token === null ? '' : rawurlencode($token)) . '; Path=/; SameSite=Lax'
			. ($token === null ? '; Max-Age=0' : '; Max-Age=' . self::SESSION_SECONDS);
		return $v . ($secure ? '; Secure' : '');
	}

	/** Whether a token is a live session. */
	static function sessionLive($token, $path = null)
	{
		if ($token === '' or $token === null) return false;
		if (self::isStore($path)) return Q_WebServer_Panel_Store::sessionGet($token) !== null;
		$s = self::load($path)['sessions'] ?? array();
		return is_array($s) and isset($s[$token]) and $s[$token] > time();
	}

	/** Whether a session must change the default key before anything else. */
	static function mustChange($token, $path = null)
	{
		if (self::isStore($path)) {
			$s = Q_WebServer_Panel_Store::sessionGet($token);
			return $s !== null and !empty($s['mustChange']);
		}
		$m = self::load($path)['mustChange'] ?? array();
		return is_array($m) and !empty($m[$token]);
	}

	/** Mark, or clear, a session as having to change the default key. */
	static function setMustChange($token, $on, $path = null)
	{
		if (self::isStore($path)) {
			Q_WebServer_Panel_Store::sessionSetMustChange($token, $on);
			return;
		}
		$config = self::load($path);
		$m = is_array($config['mustChange'] ?? null) ? $config['mustChange'] : array();
		if ($on) $m[$token] = true; else unset($m[$token]);
		if ($m) $config['mustChange'] = $m; else unset($config['mustChange']);
		self::save($config, $path);
	}

	/**
	 * A session good for everything: live, and not waiting to change the
	 * default key. What the dashboard and the other admin pages accept.
	 */
	static function validateToken($token, $path = null)
	{
		return self::sessionLive($token, $path) && !self::mustChange($token, $path);
	}

	/** The panel API's check of a request's session. */
	static function checkSession($parsed)
	{
		if (!self::canSignIn($parsed)) {
			return array('ok' => false, 'needsSetup' => true, 'error' => 'No password set. Call auth/setup first.');
		}
		$s = self::sessionFromRequest($parsed);
		if ($s !== null) return array('ok' => true, 'token' => $s['token']);
		if (self::requestToken($parsed) === '') return array('ok' => false, 'error' => 'No auth token provided');
		return array('ok' => false, 'error' => 'Token expired or invalid');
	}

	// ── The API ─────────────────────────────────────────────────────────

	/**
	 * auth/login. With no password in the body it says what the page should
	 * show; with one it signs in, or does not.
	 * @return {array} status, body
	 */
	static function login($parsed, array $body)
	{
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		$password = $body['password'] ?? '';
		if (($problem = self::storageProblem()) !== null) return self::lockedAnswer($problem);
		if (!is_string($password) or $password === '') {
			if (self::hasPassword()) return array(200, array('needsSetup' => false));
			if (self::defaultAllowedFor($parsed)) {
				$builtin = self::defaultPassword() === self::BUILTIN_DEFAULT;
				return array(200, array('needsSetup' => false, 'defaultInForce' => true,
					'hint' => $builtin
						? 'No password has been chosen yet: sign in with the default password "panel". You will be asked to change it straight away.'
						: 'No password has been chosen yet: sign in with the configured default password. You will be asked to change it straight away.'));
			}
			return array(200, array('needsSetup' => true) + Q_WebServer_Panel::setupInfo($parsed));
		}

		$veto = Q_WebServer_Panel_Events::notify('login.attempt', array('ip' => $ip));
		if ($veto) return array($veto['status'], $veto['body']);

		$config = self::load();
		$usedDefault = false;
		$ok = false;
		if (!empty($config['passwordHash'])) {
			list($ok, $rehash) = self::check($password, $config['passwordHash']);
			if ($ok and $rehash) $config['passwordHash'] = self::hash($password);
		} elseif (self::defaultAllowedFor($parsed)
			and strlen($password) <= Q_WebServer_Panel_PasswordPolicy::MAX_BYTES) {
			$ok = hash_equals(self::defaultPassword(), $password);
			$usedDefault = $ok;
		}
		if (!$ok) {
			Q_WebServer_Panel_Events::notify('login.failed', array('ip' => $ip, 'default' => self::defaultInForce()));
			return array(401, array('error' => 'Wrong password'));
		}

		$now = time();
		$token = bin2hex(random_bytes(32));
		if (self::isStore()) {
			// One file per session: sign-ins in different workers never meet.
			if (!empty($rehash)) {
				$newHash = $config['passwordHash'];
				Q_WebServer_Panel_Store::aclUpdate(function (array $c) use ($newHash) { $c['passwordHash'] = $newHash; return $c; });
			}
			if (!Q_WebServer_Panel_Store::sessionPut($token, $now + self::SESSION_SECONDS)) {
				return self::lockedAnswer('cannot write the session file in ' . Q_WebServer_Panel_Store::dirs()['sessions']);
			}
			if (mt_rand(1, 20) === 1) Q_WebServer_Panel_Store::sessionsPrune();
		} else {
			$sessions = is_array($config['sessions'] ?? null) ? $config['sessions'] : array();
			foreach ($sessions as $t => $exp) if ($exp < $now) unset($sessions[$t]);
			$sessions[$token] = $now + self::SESSION_SECONDS;
			$config['sessions'] = $sessions;
			self::save($config);
		}
		Q_WebServer_Panel_Events::notify('login.succeeded', array('token' => $token, 'ip' => $ip, 'default' => $usedDefault));
		return array(200, array('ok' => true, 'token' => $token, 'mustChange' => self::mustChange($token),
			'rules' => Q_WebServer_Panel_PasswordPolicy::rules()));
	}

	/** auth/setup: the first password, where no password and no default are in force. */
	static function setup($parsed, array $body)
	{
		if (($problem = self::storageProblem()) !== null) return self::lockedAnswer($problem);
		if (self::hasPassword()) {
			return array(409, array('error' => 'Password already set. Use auth/login.', 'needsSetup' => false));
		}
		$r = self::storePassword((string) ($body['password'] ?? ''), null, true, null, self::policyContext($parsed));
		if (!$r['ok']) return array(400, $r);
		$token = bin2hex(random_bytes(32));
		Q_WebServer_Panel_Store::sessionPut($token, time() + self::SESSION_SECONDS);
		Q_WebServer_Panel_Events::notify('password.changed', array('token' => $token,
			'ip' => (string) ($parsed['clientIp'] ?? '')));
		return array(200, array('ok' => true, 'token' => $token));
	}

	/**
	 * auth/password: change it. A session signed in with the default key
	 * needs only the new password; any other needs the current one too.
	 */
	static function change($parsed, array $body)
	{
		if (($problem = self::storageProblem()) !== null) return self::lockedAnswer($problem);
		$token = self::requestToken($parsed);
		$new = (string) ($body['password'] ?? $body['newPassword'] ?? '');
		$config = self::load();
		if (!self::mustChange($token) and !empty($config['passwordHash'])) {
			list($ok) = self::check((string) ($body['current'] ?? $body['oldPassword'] ?? ''), $config['passwordHash']);
			if (!$ok) return array(403, array('error' => 'Current password is wrong'));
		}
		$r = self::storePassword($new, null, false, $token, self::policyContext($parsed));
		if (!$r['ok']) return array(400, $r);
		Q_WebServer_Panel_Events::notify('password.changed', array('token' => $token,
			'ip' => (string) ($parsed['clientIp'] ?? '')));
		return array(200, array('ok' => true));
	}

	/** auth/logout: end this session. */
	static function logout($parsed)
	{
		$token = self::requestToken($parsed);
		if (self::isStore()) {
			if ($token !== '') Q_WebServer_Panel_Store::sessionDelete($token);
			return array(200, array('ok' => true));
		}
		$config = self::load();
		if ($token !== '' and isset($config['sessions'][$token])) {
			unset($config['sessions'][$token], $config['mustChange'][$token]);
			if (empty($config['mustChange'])) unset($config['mustChange']);
			self::save($config);
		}
		return array(200, array('ok' => true));
	}
}
