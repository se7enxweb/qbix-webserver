<?php
/**
 * @module Q
 */
/**
 * The Q shell: a drop-down console in the server's own pages, opened with `
 * (or ~), in the manner of the Quake console, with zsh-style line editing
 * and zfs-style commands. See docs/shell.md.
 *
 * This class holds what the parts share: the settings (Q.shell.*), the data
 * directory, the providers and themes, and the two things the pages need --
 * the Shell item in their toolbar and the terminal's own files.
 *
 *   Q.shell.enabled      true      the shell at all
 *   Q.shell.allowSystem  false     raw OS commands (! cmd, sys cmd) in advanced
 *   Q.shell.tier         advanced  the highest tier a session may use
 *   Q.shell.timeout      120       seconds a foreground command may run
 *   Q.shell.toggleKey    `         the key that opens and closes it
 *   Q.shell.scriptsDir   null      executables that become commands
 *   Q.shell.maxOutput    8388608   bytes one command may print
 *   Q.shell.maxJobs      8         background jobs per session
 *   Q.shell.elevateMinutes 5       how long a password confirmation (sudo) lasts
 *   Q.shell.user         null      the unprivileged user commands run as when the server is root
 *                                  (unset: the owner of the document root, else nobody)
 *   Q.shell.allowRoot    false     let "sudo <command>" run one command as root (password first)
 *
 * @class Q_WebServer_Shell
 * @static
 */
class Q_WebServer_Shell
{
	/** @var array Q_WebServer_Shell_Provider instances */
	private static $providers = array();

	/**
	 * A setting, with its default.
	 * @method config
	 * @static
	 */
	static function config($name)
	{
		$defaults = array('enabled' => true, 'allowSystem' => false, 'tier' => 'advanced', 'timeout' => 120,
			'toggleKey' => '`', 'scriptsDir' => null, 'maxOutput' => 8388608, 'maxJobs' => 8, 'elevateMinutes' => 5, 'user' => null, 'allowRoot' => false, 'allowedOrigins' => array(),
			'historySize' => 100000);
		$v = class_exists('Q_Config', false) ? Q_Config::get('Q', 'shell', $name, $defaults[$name] ?? null) : ($defaults[$name] ?? null);
		if ($name === 'tier' && !isset(Q_WebServer_Shell_Interpreter::TIERS[$v])) $v = 'basic';
		if ($name === 'toggleKey' && (!is_string($v) || $v === '' || strlen($v) > 12)) $v = '`';
		return $v;
	}

	static function enabled() { return self::config('enabled') !== false; }

	/**
	 * The shell's directory for history, aliases, autoexec and the audit log:
	 * beside the panel's password file.
	 * @method dataDir
	 * @static
	 */
	/**
	 * When the server runs as root: the first directory, from the shell's
	 * data directory up to /, that someone other than root could change --
	 * owned by another user, or writable by group or others without the
	 * sticky bit. Such a user could swap the directory for their own, with
	 * their own panel password and autoexec, and be let in. Null when the
	 * path is sound, or the server is not root.
	 * @method untrustedPath
	 * @static
	 * @param {string} [$dir] default dataDir()
	 * @return {string|null}
	 */
	static function untrustedPath($dir = null)
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return null;
		$dir = $dir ?? self::dataDir();
		$path = realpath(is_dir($dir) ? $dir : dirname($dir));
		while ($path !== false && $path !== '') {
			$st = @stat($path);
			if ($st && ($st['uid'] !== 0 || (($st['mode'] & 0022) && !($st['mode'] & 01000)))) return $path;
			if ($path === '/' || $path === dirname($path)) break;
			$path = dirname($path);
		}
		return null;
	}

	static function dataDir()
	{
		// Beside the panel's sessions: <state dir>/shell with a configuration
		// tree (/var/lib/qbix/shell), APP_DIR/local/shell without one.
		$sessions = (class_exists('Q_WebServer_Panel_Store') and (defined('APP_DIR') or function_exists('qbix_data_path')))
			? Q_WebServer_Panel_Store::dirs()['sessions'] : null;
		$base = $sessions ? dirname($sessions) : sys_get_temp_dir();
		$dir = $base . DIRECTORY_SEPARATOR . 'shell';
		if ($sessions and !isset(self::$moved[$dir])) {
			self::$moved[$dir] = true;
			self::moveLegacyData($dir);
		}
		return $dir;
	}

	/** @var array data dirs whose old files have been looked at in this process */
	protected static $moved = array();

	/** @var array the last moveLegacyData() result */
	static $lastMove = array();

	/**
	 * Bring the shell's files from where they were kept before the panel's
	 * store (APP_DIR/local/shell) into $dir, once. Nothing is lost and the
	 * old directory is left as it was, as a copy, with a note naming where
	 * its files went (which also stops this running again):
	 *
	 *   history       merged: the older file's lines first, the newer's after
	 *   aliases.json  merged: on a clash the newer file's alias wins
	 *   anything else copied when $dir has no such file; when it has one and
	 *                 the old copy is newer, the old one is kept beside it
	 *                 as <name>.legacy
	 *
	 * Both directories must pass the store's rule (the server's own, closed
	 * to others, no link on the way), and so must every file read.
	 * @method moveLegacyData
	 * @static
	 * @param {string} $dir the data directory
	 * @param {string} [$old] default APP_DIR/local/shell
	 * @return {array} moved (bool), files (names), why (when not moved)
	 */
	static function moveLegacyData($dir, $old = null)
	{
		$old = rtrim($old ?? dirname(Q_WebServer_Panel_Store::legacyFile()) . '/shell', '/');
		$dir = rtrim($dir, '/');
		$r = array('moved' => false, 'files' => array(), 'from' => $old, 'to' => $dir, 'why' => null);
		$note = $old . '/.moved';
		if (!is_dir($old) or is_file($note)) return self::$lastMove = $r;
		if (realpath($old) === realpath($dir)) return self::$lastMove = $r;
		$why = null;
		if (!Q_WebServer_Panel_Store::dirTrusted($old, false, $why)
		 or !Q_WebServer_Panel_Store::dirTrusted($dir, true, $why)) {
			$r['why'] = $why;
			return self::$lastMove = $r;
		}
		foreach ((array) @scandir($old) as $name) {
			$name = (string) $name;
			if ($name === '' or $name[0] === '.' or $name === '..') continue;
			$src = $old . '/' . $name;
			$dst = $dir . '/' . $name;
			if (!Q_WebServer_Panel_Store::fileTrusted($src, $why) or !is_file($src)
			 or !Q_WebServer_Panel_Store::fileTrusted($dst, $why)) continue;
			$srcTime = (int) @filemtime($src);
			$dstTime = is_file($dst) ? (int) @filemtime($dst) : 0;
			$text = (string) @file_get_contents($src);
			if (!is_file($dst)) {
				$out = $text;
			} elseif ($name === 'history') {
				$split = function ($t) { return array_values(array_filter(explode("\n", $t), 'strlen')); };
				$a = $split($text);
				$b = $split((string) @file_get_contents($dst));
				if ($srcTime > $dstTime) list($a, $b) = array($b, $a);
				$lines = array();
				foreach (array_merge($a, $b) as $l) if (!$lines or end($lines) !== $l) $lines[] = $l;
				if (Q_WebServer_Shell_History::limit() > 0) $lines = array_slice($lines, -Q_WebServer_Shell_History::limit());
				$out = $lines ? implode("\n", $lines) . "\n" : '';
			} elseif ($name === 'aliases.json') {
				$a = json_decode($text, true);
				$b = json_decode((string) @file_get_contents($dst), true);
				$a = is_array($a) ? $a : array();
				$b = is_array($b) ? $b : array();
				$m = $srcTime > $dstTime ? array_merge($b, $a) : array_merge($a, $b);
				$out = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
			} elseif ($srcTime > $dstTime and $text !== (string) @file_get_contents($dst)) {
				$dst .= '.legacy';
				$out = $text;
			} else {
				continue;
			}
			$tmp = $dir . '/.' . basename($dst) . '.' . getmypid() . '.tmp';
			$um = umask(077);
			$ok = @file_put_contents($tmp, $out) !== false;
			umask($um);
			if ($ok) { @chmod($tmp, 0600); $ok = @rename($tmp, $dst); }
			if (!$ok) { @unlink($tmp); continue; }
			@touch($dst, max($srcTime, $dstTime));
			$r['files'][] = basename($dst);
		}
		@file_put_contents($note, 'Moved to ' . $dir . ' on ' . date('c') . "; these files are the copy left behind.\n");
		@chmod($note, 0600);
		$r['moved'] = true;
		return self::$lastMove = $r;
	}


	/**
	 * The user commands run as. By design never root: when the server runs
	 * as root, every runner switches to Q.shell.user (nobody unless set)
	 * before it runs anything, and refuses to run if it cannot. Root is only
	 * ever used through "sudo <command>", and only where Q.shell.allowRoot is
	 * on (it is off unless configured). A server that is not root runs
	 * commands as its own user.
	 * @method user
	 * @static
	 * @return {array} array('name', 'uid', 'gid', 'switch' => bool, 'error' => string|null)
	 */
	static function user()
	{
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		$self = ($euid >= 0 && function_exists('posix_getpwuid')) ? posix_getpwuid($euid) : false;
		if ($euid === 0) {
			$want = self::config('user');
			if (!is_string($want) || $want === '') {
				// The site's own user -- who owns the document root and can read
				// the site -- else nobody.
				$want = 'nobody';
				$root = (array) (class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'startOptions', array()) : array());
				$owner = !empty($root['root']) ? @fileowner($root['root']) : false;
				if ($owner && $owner > 0 && function_exists('posix_getpwuid') && ($o = posix_getpwuid($owner))) $want = $o['name'];
			}
			$pw = function_exists('posix_getpwnam') ? posix_getpwnam($want) : false;
			if (!$pw || (int) $pw['uid'] === 0) {
				return array('name' => $want, 'uid' => -1, 'gid' => -1, 'switch' => true,
					'error' => 'the shell user "' . $want . '" does not exist or is root; set Q.shell.user to an unprivileged user');
			}
			return array('name' => $pw['name'], 'uid' => (int) $pw['uid'], 'gid' => (int) $pw['gid'], 'switch' => true, 'error' => null);
		}
		return array('name' => $self ? $self['name'] : (string) get_current_user(), 'uid' => $euid, 'gid' => -1, 'switch' => false, 'error' => null);
	}

	/** Whether "sudo <command>" may run a command as root here. */
	static function rootAllowed()
	{
		return self::config('allowRoot') === true && function_exists('posix_geteuid') && posix_geteuid() === 0;
	}

	/** The audit log: next to the shell directory, owned by the server. */
	static function auditFile()
	{
		return dirname(self::dataDir()) . DIRECTORY_SEPARATOR . 'shell-audit.log';
	}
	/** Register a distribution's provider. */
	static function addProvider(Q_WebServer_Shell_Provider $p)
	{
		self::$providers[get_class($p)] = $p;
	}

	/** @return {array} */
	static function providers() { return array_values(self::$providers); }

	/**
	 * Every theme: the shipped and installed theme-<name>.json design files,
	 * then the providers'. name => theme array.
	 * @method themes
	 * @static
	 */
	static function themes()
	{
		$out = array();
		$dirs = array();
		foreach (Q_WebServer_Design::roots() as $root) {
			foreach (array_unique(array(Q_WebServer_Design::active(), 'default')) as $design) {
				$dirs[] = "$root/$design/shell";
			}
		}
		foreach (array_reverse($dirs) as $dir) {
			foreach ((array) @glob($dir . '/theme-*.json') as $file) {
				if (!preg_match('/theme-([A-Za-z0-9_-]+)\.json$/', (string) $file, $m)) continue;
				$t = json_decode((string) @file_get_contents($file), true);
				if (is_array($t)) $out[$m[1]] = $t;
			}
		}
		foreach (self::$providers as $p) {
			foreach ((array) $p->themes() as $name => $t) {
				if (is_array($t) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $name)) $out[$name] = $t;
			}
		}
		ksort($out);
		return $out;
	}

	/**
	 * Add the Shell item to a server page's toolbar, and the terminal's
	 * files. Pages without a toolbar (PHP info, error pages) get a small one.
	 * Only for the server's own pages, under /Q/.
	 * @method decorate
	 * @static
	 * @param {string} $html
	 * @return {string}
	 */
	static function decorate($html)
	{
		if (!self::enabled() || !is_string($html) || strpos($html, 'data-qshell-open') !== false) return $html;
		$key = htmlspecialchars((string) self::config('toggleKey'), ENT_QUOTES);
		// A toolbar pill like the page's own links (it lives in the same
		// .qnav, so it takes their CSS). The dot shows the connection once
		// the shell has started; aria-expanded says whether it is showing.
		$item = '<a href="#shell" class="qshell-item" data-qshell-open role="button" aria-haspopup="dialog" aria-expanded="false" '
			. 'aria-label="Shell" title="Shell (press ' . $key . ')"><span class="qshell-dot" aria-hidden="true"></span>Shell <kbd>' . $key . '</kbd></a>';
		$count = 0;
		$html = preg_replace('/(<div class="qnav"[^>]*>.*?)(<\/div>)/s', '$1' . $item . '$2', $html, 1, $count);
		$assets = '<link rel="stylesheet" href="/Q/shell/shell.css">'
			. '<script src="/Q/shell/shell.js" defer data-toggle-key="' . $key . '"></script>';
		if (!$count) {
			$assets .= '<div class="qnav qshell-float" role="navigation" aria-label="Server views">' . $item . '</div>';
		}
		$pos = stripos($html, '</body>');
		return $pos === false ? $html . $assets : substr($html, 0, $pos) . $assets . substr($html, $pos);
	}

	/**
	 * The terminal's files: /Q/shell/shell.js, shell.css and
	 * /Q/shell/theme/<name>.json. Public (the page itself decides what it
	 * shows); nothing here runs anything.
	 * @method asset
	 * @static
	 * @param {string} $path
	 * @return {array|null} a response, or null when not an asset path
	 */
	static function asset($path)
	{
		if (strpos($path, '/Q/shell/') !== 0) return null;
		$name = substr($path, 9);
		if ($name === 'shell.js' || $name === 'shell.css') {
			$body = Q_WebServer_Design::read('shell', $name);
			if ($body === null) return array('status' => 404, 'body' => 'Not found', 'headers' => array('Content-Type' => 'text/plain'));
			return array('status' => 200, 'body' => $body, 'headers' => array(
				'Content-Type' => ($name === 'shell.js' ? 'application/javascript' : 'text/css') . '; charset=utf-8',
				'Cache-Control' => 'no-cache'));
		}
		if ($name === 'themes') {
			$list = array();
			foreach (self::themes() as $n => $t) $list[$n] = (string) ($t['label'] ?? $n);
			return array('status' => 200, 'body' => json_encode($list),
				'headers' => array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'));
		}
		if (preg_match('/^theme\/([A-Za-z0-9_-]+)\.json$/', $name, $m)) {
			$themes = self::themes();
			if (!isset($themes[$m[1]])) return array('status' => 404, 'body' => '{}', 'headers' => array('Content-Type' => 'application/json'));
			return array('status' => 200, 'body' => json_encode($themes[$m[1]]),
				'headers' => array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'));
		}
		return array('status' => 404, 'body' => 'Not found', 'headers' => array('Content-Type' => 'text/plain'));
	}
}
