<?php

/**
 * The compat file wrapper's path checks are string comparisons, and they
 * answer exactly as the regular expressions they replaced.
 *
 * Every file the hosted application opens passes through the wrapper, so it
 * used to run preg_replace('/^file:\/\//', ...) on each path, and
 * preg_match('/\.php$/i', ...) on each open, before anything was decided.
 * Both are anchored, exact tests: a known prefix and a case-insensitive
 * suffix. Q_WebServer_CompatFileWrapper::bare() and substr_compare() say the
 * same thing without a regular expression.
 *
 * Checked here, against the old expressions on the same inputs: file://,
 * FILE:// (left alone, as before), file:// twice (stripped once), empty,
 * shorter than the prefix, trailing slashes, Windows paths, phar://, and
 * suffixes .php, .PHP, .phpx, php without the dot, and names shorter than
 * ".php". The one input where the old suffix test differed is a name ending
 * in a newline: $ also matched before a final "\n", so "a.php\n" counted as
 * PHP. Its extension is not .php, and it is no longer transformed. Then the
 * wrapper itself, registered for file://, opens, stats, renames, unlinks and
 * creates and removes directories given file:// paths.
 *
 *   php tests/unit-compat-wrapper-path-checks.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
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

// ── The prefix: bare() against the old preg_replace ─────────────────────
$prefixes = array(
	'file:///var/www/a.php', 'FILE:///var/www/a.php', 'File:///x', 'file://file:///x',
	'', 'f', 'file:/', 'file://', 'file:///', '/var/www/dir/', '/var/www/dir//',
	'C:\\www\\a.php', 'file://C:/www/a.php', 'C:/www/a.php', 'phar:///x.phar/a.php',
	'file:///x.phar/a.php', ' file:///x', 'relative/path.php', "file:///caf\xc3\xa9/\xe2\x82\xac.php",
);
foreach ($prefixes as $p) {
	check('bare(' . var_export($p, true) . ')', Q_WebServer_CompatFileWrapper::bare($p),
		preg_replace('/^file:\/\//', '', $p));
}

// ── The suffix: substr_compare against the old preg_match ───────────────
$suffixes = array(
	'a.php', 'A.PHP', 'a.Php', '/x/y/z.php', 'a.phpx', 'a.php.txt', 'aphp', 'php', '.php',
	'.PHP', 'hp', '', 'p', 'a.ph', 'C:\\www\\a.php', 'phar:///x.phar/a.php', 'dir.php/',
	"caf\xc3\xa9.php", "a.php\x00",
);
foreach ($suffixes as $p) {
	check('suffix(' . var_export($p, true) . ')', substr_compare($p, '.php', -4, 4, true) === 0,
		preg_match('/\.php$/i', $p) === 1);
}
// The one deliberate difference: a name ending in a newline.
check('a name ending in a newline was PHP to the old test', preg_match('/\.php$/i', "a.php\n"), 1);
check('and is not PHP now: its extension is not .php', substr_compare("a.php\n", '.php', -4, 4, true) === 0, false);

// ── Through the wrapper, with file:// paths ─────────────────────────────
$base = sys_get_temp_dir() . DS . 'qbix-wrappath-' . getmypid();
@mkdir($base, 0700, true);
stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
$u = 'file://' . $base;
check('write through file://', file_put_contents($u . '/a.txt', 'hello'), 5);
check('read through file://', file_get_contents($u . '/a.txt'), 'hello');
check('exists through file://', file_exists($u . '/a.txt'), true);
check('missing through file://', file_exists($u . '/nope.txt'), false);
check('rename through file://', rename($u . '/a.txt', $u . '/b.txt'), true);
check('renamed file is there', file_get_contents($base . '/b.txt'), 'hello');
check('mkdir through file://', mkdir($u . '/d'), true);
check('is_dir through file://', is_dir($u . '/d'), true);
check('rmdir through file://', rmdir($u . '/d'), true);
check('touch through file://', touch($u . '/c.txt'), true);
check('unlink through file://', unlink($u . '/b.txt'), true);
check('unlink the touched file', unlink($u . '/c.txt'), true);
stream_wrapper_restore('file');
rmdir($base);
check('nothing left behind', is_dir($base), false);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
