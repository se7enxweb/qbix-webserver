<?php

/**
 * Can a request path leave the document root?
 *
 * The HTTP/1.1 path has its own guard and the server suite exercises it with
 * real requests. The HTTP/2 path has a different guard, and nothing covered it
 * -- which is the shape of gap worth caring about, because two code paths that
 * look alike drift apart quietly.
 *
 * curl is no use for this: it normalises "../" out of a URL before sending, so
 * it tests the client. HTTP/2 carries the path as a header field, so a peer
 * sends whatever it likes. These cases are the strings such a peer would send.
 *
 * The guard is judged before the path becomes a filename, so nothing is
 * stat'd, opened or passed to realpath() on the strength of it.
 *
 *   php tests/unit-path-safety.php
 */

// The guard only. Loading WebServer.php whole would drag in the event loop.
if (!class_exists('Q_WebServer', false)) {
	$source = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
	if (preg_match('/\tstatic function pathEscapesRoot\(\$decoded\)\s*\{.*?\n\t\}/s',
		$source, $m)) {
		eval('class Q_WebServer_PathGuard { public ' . trim($m[0]) . ' }');
	} else {
		fwrite(STDERR, "  could not find pathEscapesRoot() in WebServer.php\n");
		exit(2);
	}
}

$pass = 0;
$fail = 0;

function refuses($path, $why = '')
{
	global $pass, $fail;
	$got = Q_WebServer_PathGuard::pathEscapesRoot($path);
	if ($got === true) { ++$pass; return; }
	++$fail;
	printf("  FAIL  should refuse %s%s\n", var_export($path, true),
		$why ? "  ($why)" : '');
}

function allows($path)
{
	global $pass, $fail;
	$got = Q_WebServer_PathGuard::pathEscapesRoot($path);
	if ($got === false) { ++$pass; return; }
	++$fail;
	printf("  FAIL  should allow %s\n", var_export($path, true));
}

echo "\n";

// ── Must be refused ──────────────────────────────────────────────────────
refuses('/../../../etc/passwd');
refuses('/..');
refuses('/../');
refuses('/site/../../../../etc/passwd');
refuses('/./././../../../etc/passwd');
refuses('/var/site/../../../../etc/passwd');
refuses('/extension/../../../../etc/passwd');

// Percent-decoding happens before the guard, so these arrive already decoded.
refuses("/\x2e\x2e/\x2e\x2e/etc/passwd", 'decoded %2e%2e');
refuses('/..\\..\\..\\etc\\passwd', 'backslashes');
refuses('/....//....//etc/passwd', 'doubled dots');
refuses('/..;/..;/etc/passwd', 'segment parameters');

// A null byte is refused for its own reason: PHP string functions carry it and
// the filesystem calls beneath them stop at it, so one string can pass a check
// as one path and open as another.
refuses("/safe.txt\x00/../../etc/passwd", 'null byte');
refuses("/\x00", 'a bare null byte');

// Nothing at all is not a path.
refuses('', 'empty');

// Refusing every ".." costs a filename nobody uses and buys not having to
// reason about how many ways a segment can be spelled.
refuses('/notes..txt', 'conservative by design');
refuses('/a..b');

// ── Must be allowed ──────────────────────────────────────────────────────
allows('/');
allows('/site');
allows('/site/');
allows('/site/fitness');
allows('/index.php');
allows('/var/site/cache/public/stylesheets/abc_123_all.css');
allows('/extension/theme/design/media/images/logo.png');

// A dot that is not two dots.
allows('/.well-known/acme-challenge/token');
allows('/file.name.with.dots.js');
allows('/a.b');

// Characters that look alarming but do not climb.
allows('/search?q=x');          // the query is split off before this
allows('/path with spaces.txt');
allows('/ünïcödé/page');
allows('/%20already-decoded');
allows('/-/_/~/file');

// ── Types other than a string ────────────────────────────────────────────
// A guard that returns false for a non-string would let one through.
refuses(null, 'null is not a path');
refuses(false, 'false is not a path');
refuses(array('/'), 'an array is not a path');

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
