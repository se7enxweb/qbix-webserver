<?php

/**
 * An exception that escapes a script is answered 500 AND logged with where it
 * came from.
 *
 * The response carried only the message, and nothing reached the log, so a
 * 500 read "array_unique(): Argument #1 ($array) must be of type array,
 * string given" with no clue which of thousands of files raised it. The log
 * line now names the class, file:line and a short trace, in both worker modes.
 *
 *   php tests/unit-uncaught-exception-logged.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('uncaught-logged');

file_put_contents($root . DS . 'lib.php', '<?php
function deepHelper($x) { return array_unique($x); }
');
file_put_contents($root . DS . 'index.php', '<?php
require __DIR__ . "/lib.php";
if (isset($_GET["boom"])) { deepHelper("not an array"); }
echo "ok";
');

foreach (array('persistent' => false, 'fork-per-request' => true) as $mode => $fork) {
	$port = rh_start($mode, array('Q' => array('webserver' => array('forkPerRequest' => $fork))), 2);
	$r = rh_get($port, '/index.php?boom=1');
	check("$mode: the request is answered 500", $r['status'], 500);
	check("$mode: ...without the message, which only the log has", strpos($r['body'], 'array_unique()') !== false, false);
	$log = '';
	for ($i = 0; $i < 20 and strpos($log, 'uncaught') === false; ++$i) { usleep(100000); $log = rh_log($mode); }
	check("$mode: the log names the exception class", strpos($log, 'uncaught TypeError') !== false, true);
	check("$mode: ...the file and line it was raised at", (bool) preg_match('~lib\.php:2~', $log), true);
	check("$mode: ...and the call that led there", strpos($log, 'deepHelper') !== false, true);
	$r = rh_get($port, '/index.php');
	check("$mode: the next request is served normally", $r['status'] . ' ' . $r['body'], '200 ok');
}

rh_finish();
