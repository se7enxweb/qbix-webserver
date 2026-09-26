<?php
/**
 * A locked control panel says exactly how to unlock it.
 *
 * The credential store fails closed: a directory above it that group or
 * others can write is enough to refuse every sign-in. An application
 * directory left 0775 by a umask of 002 is the common case after an upgrade.
 * The refusal and `qbixctl panel:check` must name that directory and the
 * command that fixes it (`chmod g-w,o-w <dir>`) first, and offer keeping the
 * panel's files elsewhere (Q.panel.aclDir / Q.panel.sessionsDir, --conf-dir),
 * rather than a blanket chown of the store that leaves the cause in place.
 *
 *   php tests/unit-panel-lock-fix-message.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') || DS !== '/') { echo "  skip  needs a Unix system\n"; exit(0); }

$S = 'Q_WebServer_Panel_Store';
$base = sys_get_temp_dir() . DS . 'qbix-panel-lockfix-' . getmypid();
$app = $base . '/app';
@mkdir($app, 0755, true);
chmod($base, 0755);
register_shutdown_function(function () use ($base) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
	@rmdir($base);
});

Q_Config::set('Q', 'panel', 'aclDir', "$app/local/acl");
Q_Config::set('Q', 'panel', 'sessionsDir', "$app/local/sessions");

// A group-writable application directory: locked, and told how.
chmod($app, 0775);
$S::reset();
$p = $S::problem();
check('a group-writable app directory locks the panel', is_string($p) && strpos($p, "$app is writable by group or others") === 0, true);
check('the fix names that directory and the command', $S::fixFor($p), array('chmod g-w,o-w ' . escapeshellarg($app)));
$msg = $S::problemMessage($p);
check('the refusal carries the exact command', strpos($msg, "chmod g-w,o-w '$app'") !== false, true);
check('...and the other way out', strpos($msg, 'Q.panel.aclDir') !== false && strpos($msg, '--conf-dir') !== false, true);
$r = $S::report();
check('panel:check lists the real cause first', $r['fix'][0] ?? null, 'chmod g-w,o-w ' . escapeshellarg($app));
check('...without the blanket chown of the store', count(preg_grep('/^chown -R /', $r['fix'])), 0);

// Other-writable too.
chmod($app, 0757);
$S::reset();
check('an other-writable directory is named the same way', $S::fixFor($S::problem()), array('chmod g-w,o-w ' . escapeshellarg($app)));

// The fix works: the same directory, tightened, is trusted.
chmod($app, 0755);
$S::reset();
check('after the fix the store is trusted', $S::problem(), null);

// A directory of the store owned by someone else (as root only).
if (posix_geteuid() === 0 && ($nobody = posix_getpwnam('nobody'))) {
	chown("$app/local/acl", $nobody['uid']);
	$S::reset();
	$fix = $S::fixFor($S::problem());
	check('a store directory owned by another user: chown to the server\'s user',
		isset($fix[0]) && strpos($fix[0], 'chown root ' . escapeshellarg("$app/local/acl")) === 0, true);
	chown("$app/local/acl", 0);
}

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
