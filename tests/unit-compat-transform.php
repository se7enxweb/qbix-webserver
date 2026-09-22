<?php

/**
 * The source transform, which is the whole of the compatibility claim.
 *
 * Under the CLI SAPI, header(), setcookie() and their kin do nothing. The
 * server makes unmodified application code work by rewriting those calls as
 * each file is included. That rewrite runs over every line of every third-party
 * package an installation happens to contain, so it has two jobs of equal
 * weight: change the calls that must change, and leave everything else exactly
 * as it was.
 *
 * The second job is the one worth testing. A transform that rewrites a method
 * named header(), or a word inside a string, or a function definition, does not
 * fail loudly -- it produces code that runs and is wrong, in a file the author
 * never looks at because the author did not write the bug.
 *
 *   php tests/unit-compat-transform.php
 */

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

/** Transform a fragment, with no file path so nothing is cached. */
function t($source)
{
	return Q_WebServer_Compat::transformSource("<?php\n" . $source, '');
}

/** Does the transformed source still parse? */
function parses($source)
{
	$file = tempnam(sys_get_temp_dir(), 'qbixtx') . '.php';
	file_put_contents($file, $source);
	$out = array(); $rc = 0;
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
	@unlink($file);
	return $rc === 0;
}

$REWRITTEN = 'Q_WebServer_Compat::_header';

// ── What must be rewritten ───────────────────────────────────────────────

check('a plain call is rewritten',
	strpos(t("header('X-A: 1');"), $REWRITTEN) !== false, true);

check('a call with no arguments is rewritten',
	strpos(t("headers_list();"), 'Q_WebServer_Compat::_headers_list') !== false, true);

check('a call explicitly in the global namespace is rewritten',
	strpos(t("\\header('X-A: 1');"), $REWRITTEN) !== false, true);

// Inside a namespace an unqualified call still falls back to the global
// function, so it still has to be rewritten. Missing this is the subtle one:
// the file works everywhere except under this server.
check('an unqualified call inside a namespace is rewritten',
	strpos(t("namespace App;\nheader('X-A: 1');"), $REWRITTEN) !== false, true);

check('a call inside a function body is rewritten',
	strpos(t("function send() { header('X-A: 1'); }"), $REWRITTEN) !== false, true);

check('a call inside a class method is rewritten',
	strpos(t("class C { function go() { header('X-A: 1'); } }"), $REWRITTEN) !== false, true);

check('setcookie is rewritten',
	strpos(t("setcookie('a', 'b');"), 'Q_WebServer_Compat::_setcookie') !== false, true);

// ── What must NOT be rewritten ───────────────────────────────────────────

check('a method call on an object is left alone',
	strpos(t("\$response->header('X-A: 1');"), $REWRITTEN), false);

check('a static method call is left alone',
	strpos(t("Response::header('X-A: 1');"), $REWRITTEN), false);

// "?->" is a method call too. Missing it did not merely rewrite something it
// should not have -- it produced $o?->\Q_WebServer_Compat::_header(...), which
// is a parse error, so the file did not load at all.
check('a nullsafe method call is left alone',
	strpos(t("\$o?->header('X-A: 1');"), $REWRITTEN), false);

check('a nullsafe call on a rewritable name still parses',
	parses(t("\$o?->header('X-A: 1');")), true);

check('a nullsafe call to ini_get still parses',
	parses(t("\$c?->ini_get('x');")), true);

check('a function definition is left alone',
	strpos(t("function header(\$h) { return \$h; }"), $REWRITTEN), false);

check('a method definition is left alone',
	strpos(t("class C { public function header(\$h) {} }"), $REWRITTEN), false);

// A word inside a string is text, not a call. Rewriting it changes data.
check('the word inside a double-quoted string is left alone',
	strpos(t("\$s = \"header('X-A: 1');\";"), $REWRITTEN), false);

check('the word inside a single-quoted string is left alone',
	strpos(t("\$s = 'header';"), $REWRITTEN), false);

check('the word inside a heredoc is left alone',
	strpos(t("\$s = <<<EOT\nheader('X-A: 1');\nEOT;\n"), $REWRITTEN), false);

check('the word inside a line comment is left alone',
	strpos(t("// call header() here\n\$x = 1;"), $REWRITTEN), false);

check('the word inside a block comment is left alone',
	strpos(t("/* header('X-A: 1'); */\n\$x = 1;"), $REWRITTEN), false);

// A name qualified into another namespace is a different function entirely.
check('a call qualified into another namespace is left alone',
	strpos(t("App\\header('X-A: 1');"), $REWRITTEN), false);

check('a variable named like the function is left alone',
	strpos(t("\$header = 1;"), $REWRITTEN), false);

check('a constant named like the function is left alone',
	strpos(t("const header = 1;"), $REWRITTEN), false);

// ── The transform must not break the file ────────────────────────────────

$samples = array(
	'a namespaced class with a trait' =>
		"namespace App\\Http;\nuse App\\Kernel;\ntrait T { public function boot() { header('X: 1'); } }\nclass C extends Kernel { use T; }",
	'closures and arrow functions' =>
		"\$f = function (\$x) { header(\"X: \$x\"); return fn(\$y) => \$y + 1; };",
	'match, enums and named arguments' =>
		"enum E: string { case A = 'a'; }\n\$v = match(E::A) { E::A => 1, default => 0 };\nsetcookie(name: 'a', value: 'b');",
	'nullsafe calls and spread' =>
		"\$o?->header('X: 1');\n\$a = [...[1,2], 3];\nheader('X: 2');",
	'first-class callable syntax' =>
		"\$c = strlen(...);\nheader('X: 1');",
	'heredoc with interpolation next to a real call' =>
		"\$h = <<<TXT\n  header(\$x) is not a call here\nTXT;\nheader('X: 1');",
	'a string containing what looks like PHP close' =>
		"\$s = '?' . '>';\nheader('X: 1');",
);
foreach ($samples as $what => $code) {
	$result = t($code);
	check("$what still parses after the transform", parses($result), true);
}

// ── Nothing to do means nothing changed ──────────────────────────────────

$untouched = "<?php\nnamespace App;\nclass C {\n\tpublic function go(\$x) {\n\t\treturn \$x * 2;\n\t}\n}\n";
check('a file with no rewritable call is returned unchanged',
	Q_WebServer_Compat::transformSource($untouched, ''), $untouched);

// Whitespace and formatting inside untouched regions must survive, because a
// transform that reformats makes every stack trace line number a lie.
$spaced = "<?php\n\n\n\$a   =    1;   // spaced\n\nheader('X: 1');\n";
$result = Q_WebServer_Compat::transformSource($spaced, '');
check('formatting around a rewritten call survives',
	strpos($result, "\$a   =    1;   // spaced") !== false, true);
check('and the blank lines survive, so line numbers hold',
	substr_count($result, "\n"), substr_count($spaced, "\n"));

// ── Idempotence ──────────────────────────────────────────────────────────
// A file may be transformed twice -- once warmed, once on a cache miss. The
// second pass must not rewrite the rewrite.
$once = t("header('X: 1');");
$twice = Q_WebServer_Compat::transformSource($once, '');
check('transforming twice is the same as transforming once', $twice, $once);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
