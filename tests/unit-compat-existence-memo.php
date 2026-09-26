<?php
/**
 * A pool worker remembers which paths exist for as long as it remembers
 * stats, and forgets exactly when they are forgotten.
 *
 * Exponential asks about the same paths again and again while it renders a
 * page: 2600 existence questions about 700 paths on the front page of the
 * installation where this was measured, each a glob() and a realpath().
 * Remembering the answers took real existence checks down by three quarters.
 *
 * What must never happen is a remembered answer outliving the truth:
 *   - the server process has no request boundaries, so it remembers nothing
 *     (the response cache's generation marker, created after the first look,
 *     was never seen when it did);
 *   - a worker forgets with the stats: clearstatcache(), dropStats(), the
 *     request boundary -- and after another program ran, because that program
 *     can create or remove files the wrapper never sees (Exponential makes
 *     image variations with ImageMagick through system(), then checks for the
 *     file it expects);
 *   - the process shims behave exactly as the functions they replace, and the
 *     transform rewrites global calls only, never a method called exec();
 *   - Q.compat.statTtl keeps stats across request boundaries for at most that
 *     long, and a freshly forked worker forgets regardless.
 *
 *   php tests/unit-compat-existence-memo.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class Q_Config
{
	static $data = array();

	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $k) {
			if (!is_array($node) or !array_key_exists($k, $node)) return $default;
			$node = $node[$k];
		}
		return $node;
	}

	static function set()
	{
		$args = func_get_args();
		$value = array_pop($args);
		$node = &self::$data;
		foreach ($args as $k) {
			if (!isset($node[$k]) or !is_array($node[$k])) $node[$k] = array();
			$node = &$node[$k];
		}
		$node = $value;
	}
}

require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$W = 'Q_WebServer_CompatFileWrapper';
$C = 'Q_WebServer_Compat';
$dir = sys_get_temp_dir() . DS . 'qbix-existmemo-' . getmypid();
@mkdir($dir);
$f = $dir . DS . 'new.txt';
$sub = $dir . DS . 'sub';

// ── The server process remembers nothing ─────────────────────────────────
check('server process: missing', $W::existsAndIsDir($f), null);
touch($f);
check('server process: a file created after the first look is seen', $W::existsAndIsDir($f), false);
unlink($f);
check('server process: and its removal too', $W::existsAndIsDir($f), null);

// ── A worker remembers until it is told to forget ────────────────────────
$W::rememberExistence(true);
check('worker: missing', $W::existsAndIsDir($f), null);
touch($f);
check('worker: remembered "missing" until forgotten', $W::existsAndIsDir($f), null);
$W::dropStats();
check('worker: dropStats() forgets', $W::existsAndIsDir($f), false);
unlink($f);
check('worker: remembered "there" until forgotten', $W::existsAndIsDir($f), false);
$C::_clearstatcache();
check('worker: clearstatcache() forgets', $W::existsAndIsDir($f), null);
mkdir($sub);
$W::dropStats();
check('worker: a directory is a directory', $W::existsAndIsDir($sub), true);
rmdir($sub);
$W::dropStats();

// ── Another program having run forgets ───────────────────────────────────
check('before exec: missing', $W::existsAndIsDir($f), null);
$out = null; $rc = null;
$C::_exec('touch ' . escapeshellarg($f), $out, $rc);
check('exec(): exit code passed back', $rc, 0);
check('exec(): the file it made is seen', $W::existsAndIsDir($f), false);
unlink($f);
check('removed behind the memo: still remembered', $W::existsAndIsDir($f), false);
ob_start();
$C::_system('true', $rc);
ob_end_clean();
check('system(): forgets', $W::existsAndIsDir($f), null);
$C::_shell_exec('touch ' . escapeshellarg($f));
check('shell_exec(): forgets', $W::existsAndIsDir($f), false);
unlink($f);
ob_start();
$C::_passthru('true', $rc);
ob_end_clean();
check('passthru(): forgets', $W::existsAndIsDir($f), null);
$p = proc_open('touch ' . escapeshellarg($f), array(), $pipes);
check('proc_close(): exit code passed back', $C::_proc_close($p), 0);
check('proc_close(): forgets', $W::existsAndIsDir($f), false);
unlink($f);
$h = popen('true', 'r');
$C::_pclose($h);
check('pclose(): forgets', $W::existsAndIsDir($f), null);

// The shims return what the functions return.
$o1 = array('kept'); $o2 = array('kept');
$r1 = exec('printf "a\nb\n"', $o1, $c1);
$r2 = $C::_exec('printf "a\nb\n"', $o2, $c2);
check('exec(): same return value', $r2, $r1);
check('exec(): same output lines, appended to what was there', $o2, $o1);
check('exec(): same exit code', $c2, $c1);
ob_start(); $s1 = system('printf x', $sc1); $p1 = ob_get_clean();
ob_start(); $s2 = $C::_system('printf x', $sc2); $p2 = ob_get_clean();
check('system(): same return value and output', array($s2, $p2, $sc2), array($s1, $p1, $sc1));
check('shell_exec(): same result', $C::_shell_exec('printf y'), shell_exec('printf y'));

// ── The transform rewrites global calls only ─────────────────────────────
$src = '<?php exec("x"); $db->exec("y"); PDO::exec("z"); \\system("w"); $s?->system(1); shell_exec("v");';
$t = $C::transformSource($src, '');
check('exec() is rewritten', strpos($t, '_exec("x")') !== false, true);
check('a method called exec() is not', strpos($t, '$db->exec("y")') !== false, true);
check('a static method called exec() is not', strpos($t, 'PDO::exec("z")') !== false, true);
check('\\system() is rewritten', strpos($t, '_system("w")') !== false, true);
check('a nullsafe method called system() is not', strpos($t, '$s?->system(1)') !== false, true);
check('shell_exec() is rewritten', strpos($t, '_shell_exec("v")') !== false, true);

// ── Q.compat.statTtl ─────────────────────────────────────────────────────
$reset = function () use ($W) {
	$r = new ReflectionProperty($W, 'statTtl');
	$r->setAccessible(true);
	$r->setValue(null, null);
	$s = new ReflectionProperty($W, 'statsSince');
	$s->setAccessible(true);
	$s->setValue(null, 0.0);
};

$reset();                                   // default: 0
$W::forgetStats(true);
check('ttl 0: missing', $W::existsAndIsDir($f), null);
touch($f);
$W::forgetStats();
check('ttl 0: every request boundary forgets', $W::existsAndIsDir($f), false);
unlink($f);

Q_Config::set('Q', 'compat', 'statTtl', 0.5);
$reset();
$W::forgetStats(true);
check('ttl 0.5: missing', $W::existsAndIsDir($f), null);
touch($f);
$W::forgetStats();
check('ttl 0.5: kept across a boundary inside the window', $W::existsAndIsDir($f), null);
$W::forgetStats(true);
check('ttl 0.5: a forked worker forgets regardless', $W::existsAndIsDir($f), false);
unlink($f);
$W::forgetStats();
check('ttl 0.5: ...and keeps again inside the new window', $W::existsAndIsDir($f), false);
usleep(600000);
$W::forgetStats();
check('ttl 0.5: forgotten once the window has passed', $W::existsAndIsDir($f), null);
$C::_clearstatcache();
touch($f);
$C::_clearstatcache();
check('ttl 0.5: clearstatcache() still forgets at once', $W::existsAndIsDir($f), false);
unlink($f);

Q_Config::set('Q', 'compat', 'statTtl', 99);
$reset();
$r = new ReflectionMethod($W, 'statTtl');
$r->setAccessible(true);
check('ttl is capped at 10 seconds', $r->invoke(null), 10.0);

@rmdir($dir);
echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
