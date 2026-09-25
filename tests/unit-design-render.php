<?php

/**
 * The server's own pages are design files, and they render exactly the pages
 * the PHP used to build.
 *
 * Each view in designs/default/ was extracted from the method that built it
 * -- the dashboard's heredoc, the panel's nowdoc, the listing, the error page
 * and the documentation viewer -- by evaluating that code with markers and
 * with sample values. The sample values and the page the old code produced
 * for them are kept in tests/fixtures/design-<view>-*. Asserted:
 *
 *   - every view renders its golden page byte for byte;
 *   - values are substituted once: a value containing {{name}} stays literal;
 *   - lookup order: the configuration directory's designs/ before the engine's,
 *     the active design before "default", one file at a time;
 *   - {{@view/file}} includes another view's file (common/chrome.css), overridable;
 *   - a view or design name cannot leave the designs directory;
 *   - a missing view renders null, not an error.
 *
 *   php tests/unit-design-render.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Design.php';
$D = 'Q_WebServer_Design';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export(is_string($got) && strlen($got) > 200 ? substr($got, 0, 200) . '...' : $got, true),
		var_export(is_string($want) && strlen($want) > 200 ? substr($want, 0, 200) . '...' : $want, true));
}

// ── Every shipped view is byte-identical to what the old code produced ───
foreach (array('dashboard', 'panel', 'listing', 'error', 'docs', 'metrics', 'phpinfo') as $view) {
	$sample = json_decode(file_get_contents(__DIR__ . "/fixtures/design-$view-sample.json"), true);
	$golden = file_get_contents(__DIR__ . "/fixtures/design-$view-golden.html");
	$got = $D::render($view, $sample);
	check("$view renders byte for byte what the code it replaced produced (" . strlen($golden) . " bytes)",
		$got === $golden ? 'identical' : 'differs at byte ' . strspn($got ^ $golden, "\0"), 'identical');
}

// ── Lookup order and overrides ───────────────────────────────────────────
$conf = sys_get_temp_dir() . '/qbix-design-' . getmypid();
mkdir("$conf/designs/default/error", 0755, true);
mkdir("$conf/designs/dark/error", 0755, true);
Q_Config::set('Q', 'webserver', 'confDir', $conf);
check('with no override, the engine file is used', $D::path('error', 'style.css'), $D::engineDir() . '/default/error/style.css');
file_put_contents("$conf/designs/default/error/style.css", 'body{color:red}');
check('a file in the configuration directory overrides that one file', $D::path('error', 'style.css'), "$conf/designs/default/error/style.css");
check('...and the rest still come from the engine', $D::path('error', 'page.html'), $D::engineDir() . '/default/error/page.html');
check('the override is what renders', strpos($D::render('error', array('code' => '404', 'title' => 'x', 'msg' => 'y', 'brand' => 'b')), 'body{color:red}') !== false, true);
Q_Config::set('Q', 'webserver', 'design', 'dark');
file_put_contents("$conf/designs/dark/error/page.html", 'DARK {{code}} {{@style.css}}');
check('the active design wins over default, file by file', $D::render('error', array('code' => '404')), 'DARK 404 body{color:red}');
// {{@view/file}}: another view's file -- the shared chrome -- overridable the same way.
file_put_contents("$conf/designs/dark/error/page.html", '{{@common/chrome.css}}|{{@style.css}}|{{@../error/page.html}}');
check('{{@view/file}} includes another view\'s file: the shared chrome', strpos($D::render('error', array()), '.qnav a[aria-current=page]') !== false, true);
mkdir("$conf/designs/default/common", 0755, true);
file_put_contents("$conf/designs/default/common/chrome.css", 'CHROME');
check('...overridable file by file; an include that is a path stays literal', $D::render('error', array()), 'CHROME|body{color:red}|{{@../error/page.html}}');
Q_Config::set('Q', 'webserver', 'design', '../../etc');
check('a design name that is a path falls back to default', $D::active(), 'default');
check('a view name that is a path is refused', $D::path('../../../etc', 'passwd'), null);
check('a file name that is a path is refused', $D::path('error', '../../../../etc/passwd'), null);
Q_Config::set('Q', 'webserver', 'design', 'default');

// ── Substitution ─────────────────────────────────────────────────────────
file_put_contents("$conf/designs/default/error/page.html", '{{a}}|{{b}}|{{missing}}');
check('values are substituted once; a value holding {{b}} stays literal; an unknown placeholder is left',
	$D::render('error', array('a' => '{{b}}', 'b' => 'B')), '{{b}}|B|{{missing}}');
check('a view no design has renders null', $D::render('no-such-view', array()), null);

// Clean up.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($conf, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($conf);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
