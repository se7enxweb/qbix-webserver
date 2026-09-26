<?php

/**
 * An uncaught exception goes to the handler the application registered with
 * set_exception_handler(), as in PHP -- and without one, and without debug,
 * the response is the designed page, never the exception's message.
 *
 * The worker catches an uncaught exception itself (it must, to keep serving)
 * and used to answer with the message: an application's own error page never
 * ran, and a visitor saw the developer's text -- for a database that refused
 * its password, the database user's name. Asserted, in both worker modes:
 *   - a script with its own handler gets that handler's status, headers and
 *     page, also when the handler ends with exit;
 *   - a script without one gets 500 and the designed page, with nothing of
 *     the message;
 *   - the next request is served normally.
 *
 *   php tests/unit-app-exception-handler.php
 */

require __DIR__ . '/fixtures/race-harness.php';

list($base, $root) = rh_setup('app-exception-handler');
file_put_contents("$root/handled.php", '<?php
set_exception_handler(function ($e) {
	header("HTTP/1.1 503 Service Unavailable");
	header("Retry-After: 30");
	echo "handled by the application: " . get_class($e);
	exit(1);
});
throw new RuntimeException("Access denied for user secret-db-user");');
file_put_contents("$root/unhandled.php", '<?php
throw new RuntimeException("Access denied for user secret-db-user");');
file_put_contents("$root/fine.php", '<?php echo "fine";');

foreach (array('persistent' => false, 'fork-per-request' => true) as $mode => $fork) {
	$port = rh_start($mode, array('Q' => array('webserver' => array('forkPerRequest' => $fork))), 2);
	$r = rh_get($port, '/handled.php');
	check("$mode: the application's handler sets the status", $r['status'] ?? 0, 503);
	check("$mode: ...and its headers", $r['headers']['retry-after'] ?? '', '30');
	check("$mode: ...and the page is the handler's", $r['body'] ?? '', 'handled by the application: RuntimeException');
	$r = rh_get($port, '/unhandled.php');
	check("$mode: without a handler it is 500", $r['status'] ?? 0, 500);
	check("$mode: ...with the designed page", strpos(html_entity_decode($r['body'] ?? '', ENT_QUOTES), 'Something went wrong on our side') !== false, true);
	check("$mode: ...and nothing of the message", strpos($r['body'] ?? '', 'secret-db-user'), false);
	$r = rh_get($port, '/fine.php');
	check("$mode: the next request is served normally", ($r['status'] ?? 0) . ' ' . ($r['body'] ?? ''), '200 fine');
}
rh_finish();
