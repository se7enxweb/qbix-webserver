<?php
/**
 * @module Q
 */

/**
 * Where the control panel keeps its credentials and sessions, and the rule
 * that decides whether they can be believed.
 *
 * Two directories:
 *
 *   acl/       panel.json: the password hash, the default-key state, and
 *              the panel's own settings. Written rarely, under a lock.
 *   sessions/  one file per signed-in session, named by the SHA-256 of its
 *              token (a directory listing gives no token away), holding its
 *              expiry and whether it must change the default key. Sign-ins
 *              in different workers never write the same file.
 *
 * Where they are: Q.panel.aclDir and Q.panel.sessionsDir when set. Otherwise,
 * with a configuration tree in use, <confDir>/acl and <state dir>/sessions
 * (Q_WebServer_Layout::stateDir(): /etc/qbix/acl and /var/lib/qbix/sessions
 * for the base tree, each overlay naming its own). With no configuration
 * tree, beside the application as before: APP_DIR/local, and its sessions/.
 *
 * The rule, checked before anything is read or written, and failing closed:
 * every directory from the file up to / is owned by root or by the user the
 * server runs as, and is not writable by group or others (a sticky
 * directory such as /tmp excepted); the two directories themselves and the
 * files in them belong to the server's user with no access for anyone else
 * (0700 and 0600; a laxer mode on a file we own is tightened, never trusted
 * as it is); and nothing on the way is a symbolic link. A directory someone
 * else could rename or replace -- and plant a password file in -- is never
 * believed. When the rule fails, sign-in, the default key and every session
 * are refused, and the reason names the path; there is no fallback to a
 * looser place.
 *
 * A panel.json left in the old place (APP_DIR/local) is moved here once,
 * automatically, when it passes the same ownership test; a copy stays in
 * acl/ and the old file is renamed, never deleted.
 *
 * @class Q_WebServer_Panel_Store
 * @static
 */
class Q_WebServer_Panel_Store
{
	const ACL_FILE = 'panel.json';

	/** @var string|null the application directory, when a caller (the CLI) names one */
	protected static $appDir = null;

	/** @var array cache: key => array(time, problem) */
	protected static $checked = array();

	/** @var bool whether migration has run in this process */
	protected static $migrated = false;

	/** @var array the last migration's result, for panel:check */
	static $lastMigration = array();

	/** Use this application directory instead of APP_DIR (for the CLI). */
	static function setAppDir($dir)
	{
		self::$appDir = ($dir === null or $dir === '') ? null : rtrim((string) $dir, '/\\');
		self::reset();
	}

	/** Forget what was checked (tests, and after a directory changed). */
	static function reset()
	{
		self::$checked = array();
		self::$migrated = false;
		self::$lastMigration = array();
	}

	// ── Where ───────────────────────────────────────────────────────────

	/** The old single file: APP_DIR/local/panel.json. */
	static function legacyFile()
	{
		if (self::$appDir !== null) return self::$appDir . '/local/panel.json';
		if (defined('APP_DIR')) return rtrim(APP_DIR, '/\\') . '/local/panel.json';
		return function_exists('qbix_data_path') ? qbix_data_path('local/panel.json') : getcwd() . '/local/panel.json';
	}

	/**
	 * The two directories, and where they came from.
	 * @return {array} acl, sessions, source ('config', 'layout' or 'app')
	 */
	static function dirs()
	{
		$cfg = function ($k) {
			$v = class_exists('Q_Config', false) ? Q_Config::get('Q', 'panel', $k, null) : null;
			return (is_string($v) and $v !== '') ? rtrim($v, '/\\') : null;
		};
		$confDir = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'confDir', null) : null;
		$confDir = (is_string($confDir) and $confDir !== '') ? rtrim($confDir, '/\\') : null;
		$legacyDir = dirname(self::legacyFile());
		$acl = $cfg('aclDir');
		$sessions = $cfg('sessionsDir');
		$source = ($acl !== null or $sessions !== null) ? 'config' : ($confDir !== null ? 'layout' : 'app');
		if ($acl === null) $acl = $confDir !== null ? $confDir . '/acl' : $legacyDir;
		if ($sessions === null) {
			if ($confDir !== null) {
				$state = class_exists('Q_WebServer_Layout') ? Q_WebServer_Layout::stateDir($confDir) : null;
				$sessions = ($state !== null ? $state : $confDir) . '/sessions';
			} else {
				$sessions = $legacyDir . '/sessions';
			}
		}
		return array('acl' => $acl, 'sessions' => $sessions, 'source' => $source);
	}

	/** acl/panel.json */
	static function aclFile()
	{
		return self::dirs()['acl'] . '/' . self::ACL_FILE;
	}

	/** The file for one session. */
	static function sessionFile($token)
	{
		return self::dirs()['sessions'] . '/' . hash('sha256', (string) $token) . '.json';
	}

	// ── The trust rule ──────────────────────────────────────────────────

	/** The server's effective user id, or null where there is none (Windows). */
	static function euid()
	{
		return function_exists('posix_geteuid') ? posix_geteuid() : null;
	}

	/** Who owns what: "uid 1001 (alpha)". */
	protected static function who($uid)
	{
		$name = (function_exists('posix_getpwuid') and ($pw = @posix_getpwuid($uid))) ? $pw['name'] : null;
		return 'uid ' . $uid . ($name ? " ($name)" : '');
	}

	/**
	 * Every directory above $path, up to /: owned by root or the server's
	 * user, not writable by group or others unless sticky, and no symbolic
	 * link on the way.
	 */
	static function ancestorsTrusted($path, &$why)
	{
		$euid = self::euid();
		$p = dirname($path);
		for ($i = 0; $i < 64; ++$i) {
			$st = @lstat($p);
			if ($st === false) { $why = "$p does not exist"; return false; }
			if (($st['mode'] & 0170000) === 0120000) { $why = "$p is a symbolic link"; return false; }
			if (($st['mode'] & 0170000) !== 0040000) { $why = "$p is not a directory"; return false; }
			if ($euid !== null) {
				if ($st['uid'] !== 0 and $st['uid'] !== $euid) {
					$why = "$p belongs to " . self::who($st['uid']) . ', who could rename or replace what is inside it';
					return false;
				}
				if (($st['mode'] & 0022) and !($st['mode'] & 01000)) {
					$why = sprintf('%s is writable by group or others (mode %o)', $p, $st['mode'] & 0777);
					return false;
				}
			}
			if ($p === '/' or dirname($p) === $p) return true;
			$p = dirname($p);
		}
		$why = "$path is nested too deeply";
		return false;
	}

	/**
	 * A directory of ours: exists (created, 0700, when $create and its
	 * ancestors pass), is not a link, belongs to the server's user, and no one
	 * else can enter it. A laxer mode on a directory we own is tightened.
	 */
	static function dirTrusted($dir, $create, &$why)
	{
		$euid = self::euid();
		$st = @lstat($dir);
		if ($st === false) {
			if (!$create) { $why = "$dir does not exist"; return false; }
			// Create from the nearest existing directory down, which must pass.
			$missing = array();
			$p = $dir;
			while (@lstat($p) === false and dirname($p) !== $p) { $missing[] = $p; $p = dirname($p); }
			if (!self::ancestorsTrusted($p . '/.', $why)) return false;
			$old = umask(077);
			foreach (array_reverse($missing) as $m) {
				if (!@mkdir($m, 0700) and !is_dir($m)) { umask($old); $why = "cannot create $m"; return false; }
			}
			umask($old);
			$st = @lstat($dir);
			if ($st === false) { $why = "cannot create $dir"; return false; }
		}
		if (($st['mode'] & 0170000) === 0120000) { $why = "$dir is a symbolic link"; return false; }
		if (($st['mode'] & 0170000) !== 0040000) { $why = "$dir is not a directory"; return false; }
		if ($euid !== null) {
			if ($st['uid'] !== $euid) {
				$why = "$dir belongs to " . self::who($st['uid']) . ', not to the server\'s ' . self::who($euid);
				return false;
			}
			if ($st['mode'] & 0077) {
				if (!@chmod($dir, 0700)) { $why = sprintf('%s is open to others (mode %o) and cannot be tightened', $dir, $st['mode'] & 0777); return false; }
				self::log(sprintf('tightened %s from mode %o to 700', $dir, $st['mode'] & 0777));
			}
		}
		return self::ancestorsTrusted($dir, $why);
	}

	/**
	 * A file of ours, if it exists: not a link, a regular file, the server's
	 * user's, and no one else's to read or write (a laxer mode is tightened).
	 * @return {bool} true when absent or trusted
	 */
	static function fileTrusted($file, &$why)
	{
		$st = @lstat($file);
		if ($st === false) return true;
		if (($st['mode'] & 0170000) === 0120000) { $why = "$file is a symbolic link"; return false; }
		if (($st['mode'] & 0170000) !== 0100000) { $why = "$file is not a regular file"; return false; }
		$euid = self::euid();
		if ($euid !== null) {
			if ($st['uid'] !== $euid) { $why = "$file belongs to " . self::who($st['uid']); return false; }
			if ($st['mode'] & 0077) {
				if (!@chmod($file, 0600)) { $why = sprintf('%s is open to others (mode %o)', $file, $st['mode'] & 0777); return false; }
				self::log(sprintf('tightened %s from mode %o to 600', $file, $st['mode'] & 0777));
			}
		}
		return true;
	}

	/**
	 * Why the panel's storage cannot be believed, or null when it can.
	 * Creates the directories on first use and runs the migration once.
	 * Answers are kept for two seconds.
	 * @return {string|null}
	 */
	static function problem()
	{
		$d = self::dirs();
		$key = $d['acl'] . '|' . $d['sessions'];
		$now = microtime(true);
		if (isset(self::$checked[$key]) and $now - self::$checked[$key][0] < 2) return self::$checked[$key][1];
		$why = null;
		$ok = self::dirTrusted($d['acl'], true, $why)
			&& self::dirTrusted($d['sessions'], true, $why)
			&& self::fileTrusted($d['acl'] . '/' . self::ACL_FILE, $why);
		if ($ok and !self::$migrated) {
			self::$migrated = true;
			$m = self::migrate();
			self::$lastMigration = $m;
			if (!$m['ok']) { $ok = false; $why = $m['why']; }
		}
		$problem = $ok ? null : (string) $why;
		self::$checked[$key] = array($now, $problem);
		return $problem;
	}

	/** The message a refused sign-in shows. */
	static function problemMessage($problem)
	{
		return 'The control panel is locked: its credential store cannot be trusted (' . $problem . '). '
			. 'Run `qbixctl panel:check` on the server for the fix.';
	}

	// ── Reading and writing ─────────────────────────────────────────────

	/**
	 * A JSON file of ours, read through a handle that is checked to be the
	 * file that passed the rule (same inode, same owner), so a swap between
	 * the check and the read is caught. A half-written file is read again.
	 * @return {array|null} null when absent, unreadable, or not trusted
	 */
	static function readJson($file)
	{
		for ($try = 0; $try < 5; ++$try) {
			$why = null;
			if (!self::fileTrusted($file, $why)) { self::log($why); return null; }
			$st = @lstat($file);
			if ($st === false) return null;
			$h = @fopen($file, 'rb');
			if (!$h) return null;
			$fs = fstat($h);
			if (!$fs or $fs['ino'] !== $st['ino'] or $fs['dev'] !== $st['dev'] or $fs['uid'] !== $st['uid']) {
				fclose($h);
				usleep(2000);
				continue;
			}
			$raw = stream_get_contents($h);
			fclose($h);
			$data = json_decode((string) $raw, true);
			if (is_array($data)) return $data;
			usleep(2000);
		}
		return null;
	}

	/** Write a JSON file of ours: 0600, beside it first, then renamed into place. */
	static function writeJson($file, array $data)
	{
		$dir = dirname($file);
		$tmp = $dir . '/.' . basename($file) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$old = umask(077);
		$h = @fopen($tmp, 'xb');
		umask($old);
		if (!$h) return false;
		$ok = fwrite($h, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
		fflush($h);
		fclose($h);
		@chmod($tmp, 0600);
		if (!$ok or !@rename($tmp, $file)) { @unlink($tmp); return false; }
		return true;
	}

	/** Run $fn holding the acl directory's lock. */
	static function locked(callable $fn)
	{
		$lock = self::dirs()['acl'] . '/.lock';
		$old = umask(077);
		$h = @fopen($lock, 'c');
		umask($old);
		if ($h) flock($h, LOCK_EX);
		try {
			return $fn();
		} finally {
			if ($h) { flock($h, LOCK_UN); fclose($h); }
		}
	}

	// ── The credentials ─────────────────────────────────────────────────

	/** acl/panel.json, or an empty array (also when the store is not trusted). */
	static function aclLoad()
	{
		if (self::problem() !== null) return array();
		$c = self::readJson(self::aclFile());
		if (!is_array($c)) return array();
		unset($c['sessions'], $c['mustChange']);
		return $c;
	}

	/** Write acl/panel.json. Refused when the store is not trusted. */
	static function aclSave(array $acl)
	{
		if (self::problem() !== null) return false;
		unset($acl['sessions'], $acl['mustChange']);
		return self::locked(function () use ($acl) {
			return self::writeJson(self::aclFile(), $acl);
		});
	}

	/** Change acl/panel.json in place, under the lock: $fn gets and returns the array. */
	static function aclUpdate(callable $fn)
	{
		if (self::problem() !== null) return false;
		return self::locked(function () use ($fn) {
			$c = self::readJson(self::aclFile());
			$c = $fn(is_array($c) ? $c : array());
			if (!is_array($c)) return false;
			unset($c['sessions'], $c['mustChange']);
			return self::writeJson(self::aclFile(), $c);
		});
	}

	// ── The panel's settings ────────────────────────────────────────────

	/**
	 * One of the panel's own settings (appsDir, autohost, domains...), kept
	 * in acl/panel.json beside the credentials.
	 * @return {mixed} $default when unset or when the store is not trusted
	 */
	static function setting($key, $default = null)
	{
		$c = self::aclLoad();
		return array_key_exists($key, $c) ? $c[$key] : $default;
	}

	/**
	 * Set one of the panel's settings (null removes it), under the store's
	 * lock, so two workers saving different settings at once keep both.
	 * @return {bool}
	 */
	static function settingSet($key, $value)
	{
		return self::aclUpdate(function (array $c) use ($key, $value) {
			if ($value === null) unset($c[$key]); else $c[$key] = $value;
			return $c;
		});
	}

	/**
	 * Change a JSON file outside the store (an application's local/app.json,
	 * config/deploy.json...) the same way: under a lock beside it, read,
	 * changed by $fn, written beside it and renamed into place, so a
	 * concurrent change is never lost and a reader never sees it half written.
	 * The file keeps its mode ($mode for a new one).
	 * @return {array|false} what was written
	 */
	static function fileUpdate($file, callable $fn, $mode = 0644)
	{
		$dir = dirname($file);
		if (!is_dir($dir) and !@mkdir($dir, 0755, true) and !is_dir($dir)) return false;
		$h = @fopen($file . '.lock', 'c');
		if ($h) flock($h, LOCK_EX);
		try {
			$data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : array();
			$data = $fn(is_array($data) ? $data : array());
			if (!is_array($data)) return false;
			$keep = is_file($file) ? (fileperms($file) & 0777) : $mode;
			$tmp = $dir . '/.' . basename($file) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
			if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) return false;
			@chmod($tmp, $keep);
			if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
			return $data;
		} finally {
			if ($h) { flock($h, LOCK_UN); fclose($h); }
		}
	}

	// ── Sessions ────────────────────────────────────────────────────────

	/** A live session, or null (expired ones are removed). */
	static function sessionGet($token)
	{
		if ($token === null or $token === '' or self::problem() !== null) return null;
		$file = self::sessionFile($token);
		$s = self::readJson($file);
		if (!is_array($s)) return null;
		if ((int) ($s['expires'] ?? 0) <= time()) { @unlink($file); return null; }
		return $s;
	}

	/** Record a session. */
	static function sessionPut($token, $expires, $mustChange = false)
	{
		if (self::problem() !== null) return false;
		return self::writeJson(self::sessionFile($token), array(
			'expires' => (int) $expires, 'mustChange' => (bool) $mustChange, 'created' => time()));
	}

	/** Mark or clear a session's must-change flag. */
	static function sessionSetMustChange($token, $on)
	{
		$s = self::sessionGet($token);
		if ($s === null) return false;
		$s['mustChange'] = (bool) $on;
		return self::writeJson(self::sessionFile($token), $s);
	}

	/** End one session. */
	static function sessionDelete($token)
	{
		if ($token === null or $token === '' or self::problem() !== null) return false;
		$file = self::sessionFile($token);
		return is_file($file) ? @unlink($file) : true;
	}

	/**
	 * End every session but $keep, and clear every must-change flag (a
	 * password has been chosen). Expired sessions go too.
	 */
	static function sessionsRevoke($keep = null, $clearMustChange = true)
	{
		if (self::problem() !== null) return false;
		$keepFile = $keep !== null ? basename(self::sessionFile($keep)) : null;
		foreach (self::sessionFiles() as $f) {
			if ($keepFile !== null and basename($f) === $keepFile) {
				if ($clearMustChange) {
					$s = self::readJson($f);
					if (is_array($s)) { $s['mustChange'] = false; self::writeJson($f, $s); }
				}
				continue;
			}
			@unlink($f);
		}
		return true;
	}

	/** Clear every session's must-change flag (a password has been chosen). */
	static function sessionsClearMustChange()
	{
		if (self::problem() !== null) return false;
		foreach (self::sessionFiles() as $f) {
			$s = self::readJson($f);
			if (is_array($s) and !empty($s['mustChange'])) { $s['mustChange'] = false; self::writeJson($f, $s); }
		}
		return true;
	}

	/** Remove expired sessions. */
	static function sessionsPrune()
	{
		if (self::problem() !== null) return 0;
		$n = 0;
		foreach (self::sessionFiles() as $f) {
			$s = self::readJson($f);
			if (!is_array($s) or (int) ($s['expires'] ?? 0) <= time()) { @unlink($f); ++$n; }
		}
		return $n;
	}

	/** The session files. */
	static function sessionFiles()
	{
		$dir = self::dirs()['sessions'];
		$out = array();
		foreach ((array) @scandir($dir) as $n) {
			if (preg_match('/^[0-9a-f]{64}\.json$/', (string) $n)) $out[] = $dir . '/' . $n;
		}
		return $out;
	}

	// ── Migration ───────────────────────────────────────────────────────

	/**
	 * Move the old single panel.json (APP_DIR/local) into acl/ and
	 * sessions/, once. Only a file that belongs to the server's user, in a
	 * directory that does, is taken: no one else can make one, so a planted
	 * file is refused rather than moved. A root-only copy is kept in acl/ and
	 * the old file is renamed (…migrated-<time>), not deleted. Sessions still
	 * held in acl/panel.json (the layout without a configuration tree, where
	 * acl/ is the old directory) are split out the same way.
	 * @return {array} ok, action, why, from, to, backup
	 */
	static function migrate()
	{
		$aclFile = self::aclFile();
		$legacy = self::legacyFile();
		$stamp = date('Ymd-His');
		// The old file is acl/panel.json itself: only split its sessions out.
		if ($legacy === $aclFile or !is_file($legacy) or is_file($aclFile)) {
			$c = is_file($aclFile) ? self::readJson($aclFile) : null;
			if (is_array($c) and (isset($c['sessions']) or isset($c['mustChange']))) {
				self::splitSessions($c);
				unset($c['sessions'], $c['mustChange']);
				self::locked(function () use ($aclFile, $c) { return self::writeJson($aclFile, $c); });
				return array('ok' => true, 'action' => 'split', 'why' => 'sessions moved out of ' . $aclFile, 'to' => $aclFile);
			}
			if (is_file($legacy) and $legacy !== $aclFile and is_file($aclFile)) {
				return array('ok' => true, 'action' => 'none', 'why' => 'already moved; ' . $legacy . ' is no longer read', 'to' => $aclFile);
			}
			return array('ok' => true, 'action' => 'none', 'why' => 'nothing to move', 'to' => $aclFile);
		}
		// Only a file the server's user made, in a directory of the same user.
		$euid = self::euid();
		$lst = @lstat($legacy);
		$dst = @lstat(dirname($legacy));
		$why = null;
		if ($lst === false or ($lst['mode'] & 0170000) !== 0100000) {
			$why = "$legacy is not a regular file";
		} elseif ($euid !== null and ($lst['uid'] !== $euid or $dst === false or $dst['uid'] !== $euid)) {
			$why = "$legacy (or its directory) does not belong to the server's user, so it is not moved or believed";
		} elseif ($euid !== null and ($lst['mode'] & 0022)) {
			$why = sprintf('%s is writable by group or others (mode %o), so it is not moved or believed', $legacy, $lst['mode'] & 0777);
		}
		if ($why !== null) {
			self::log('refused to move the old panel file: ' . $why);
			return array('ok' => false, 'action' => 'refused', 'why' => $why, 'from' => $legacy);
		}
		$raw = (string) @file_get_contents($legacy);
		$c = json_decode($raw, true);
		if (!is_array($c)) {
			return array('ok' => false, 'action' => 'refused', 'why' => "$legacy is not valid JSON", 'from' => $legacy);
		}
		$backup = dirname($aclFile) . '/panel.json.pre-migration-' . $stamp;
		$old = umask(077);
		$okBackup = @file_put_contents($backup, $raw) !== false;
		umask($old);
		@chmod($backup, 0600);
		if (!$okBackup) return array('ok' => false, 'action' => 'failed', 'why' => "cannot write $backup", 'from' => $legacy);
		self::splitSessions($c);
		unset($c['sessions'], $c['mustChange']);
		$ok = self::locked(function () use ($aclFile, $c) { return self::writeJson($aclFile, $c); });
		if (!$ok) return array('ok' => false, 'action' => 'failed', 'why' => "cannot write $aclFile", 'from' => $legacy);
		$moved = $legacy . '.migrated-' . $stamp;
		@rename($legacy, $moved);
		self::log("moved $legacy to $aclFile (copy kept at $backup; the old file is now $moved)");
		return array('ok' => true, 'action' => 'moved', 'why' => 'moved', 'from' => $legacy, 'to' => $aclFile,
			'backup' => $backup, 'renamed' => $moved);
	}

	/** Write the sessions of an old single-file config as session files. */
	protected static function splitSessions(array $c)
	{
		$must = is_array($c['mustChange'] ?? null) ? $c['mustChange'] : array();
		foreach ((is_array($c['sessions'] ?? null) ? $c['sessions'] : array()) as $token => $exp) {
			if ((int) $exp > time()) {
				self::writeJson(self::sessionFile($token), array('expires' => (int) $exp,
					'mustChange' => !empty($must[$token]), 'created' => time()));
			}
		}
	}

	// ── Reporting ───────────────────────────────────────────────────────

	/**
	 * What `qbixctl panel:check` prints: each path, whether it passes, and
	 * why not, with the commands that fix it.
	 * @return {array} ok, source, rows, problem, fix, migration, sessions
	 */
	static function report()
	{
		self::reset();
		$d = self::dirs();
		$problem = self::problem();
		$rows = array();
		foreach (array('acl dir' => $d['acl'], 'sessions dir' => $d['sessions']) as $label => $path) {
			$why = null;
			$ok = self::dirTrusted($path, false, $why);
			$rows[] = self::row($label, $path, $ok, $why);
		}
		$why = null;
		$f = $d['acl'] . '/' . self::ACL_FILE;
		$rows[] = self::row('credentials', $f, self::fileTrusted($f, $why) && (is_file($f) || ($why = 'not written yet: no password chosen, the default key is in force') !== null), $why);
		$legacy = self::legacyFile();
		if ($legacy !== $f) {
			$rows[] = self::row('old file', $legacy, !is_file($legacy),
				is_file($legacy) ? 'still present: moved on next start if it belongs to the server\'s user' : 'absent (or moved)');
		}
		$fix = array();
		if ($problem !== null) {
			$euid = self::euid();
			$user = ($euid !== null and function_exists('posix_getpwuid') and ($pw = @posix_getpwuid($euid))) ? $pw['name'] : 'root';
			foreach (array($d['acl'], $d['sessions']) as $dir) {
				$fix[] = "chown -R $user " . escapeshellarg($dir) . ' && chmod 700 ' . escapeshellarg($dir)
					. ' && find ' . escapeshellarg($dir) . ' -type f -exec chmod 600 {} +';
			}
			$fix[] = 'every directory above them must belong to root (or ' . $user . ') and not be writable by group or others';
		}
		return array('ok' => $problem === null, 'source' => $d['source'], 'acl' => $d['acl'],
			'sessions' => $d['sessions'], 'rows' => $rows, 'problem' => $problem, 'fix' => $fix,
			'migration' => self::$lastMigration, 'sessionCount' => $problem === null ? count(self::sessionFiles()) : 0);
	}

	protected static function row($label, $path, $ok, $why)
	{
		$st = @lstat($path);
		return array('label' => $label, 'path' => $path, 'ok' => (bool) $ok, 'why' => $why,
			'owner' => $st ? self::who($st['uid']) : null, 'mode' => $st ? sprintf('%o', $st['mode'] & 07777) : null);
	}

	/** One line for the server's log. */
	protected static function log($line)
	{
		if ($line === null or $line === '') return;
		if (defined('STDERR')) @fwrite(STDERR, '  panel storage: ' . $line . "\n");
	}
}
