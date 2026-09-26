#!/usr/bin/env php
<?php
/**
 * qbixctl -- control the server the way apache2ctl controls Apache.
 *
 *   qbixctl start | stop | restart | graceful | status
 *   qbixctl -t | configtest           check the configuration parses
 *   qbixctl -S                        show the configuration trees and what is enabled
 *   qbixctl ensite NAME | dissite NAME
 *   qbixctl enconf NAME | disconf NAME
 *   qbixctl enmod NAME  | dismod NAME
 *
 * Options (--conf-dir, --config, --pid, --distribution, ...) are those of the
 * matching qbixconsole command; anything else is passed to qbixconsole.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'micro') { exit("qbixctl runs from the command line\n"); }
$map = array(
	'start' => 'server:start', 'stop' => 'server:stop', 'restart' => 'server:restart',
	'graceful' => 'server:reload', 'reload' => 'server:reload', 'status' => 'server:status',
	'-t' => 'server:configtest', 'configtest' => 'server:configtest', '-S' => 'layout:show',
	'ensite' => 'site:enable', 'dissite' => 'site:disable',
	'enconf' => 'conf:enable', 'disconf' => 'conf:disable',
	'enmod' => 'mod:enable', 'dismod' => 'mod:disable',
);
$args = array_slice($argv, 1);
if (!$args or in_array($args[0], array('-h', '--help', 'help'), true)) {
	fwrite(STDOUT, "Usage: qbixctl start|stop|restart|graceful|status|-t|-S|ensite|dissite|enconf|disconf|enmod|dismod [options]\n"
		. "Run `qbixconsole list` for every command and `qbixconsole help <command>` for its options.\n");
	exit($args ? 0 : 1);
}
if (isset($map[$args[0]])) $args[0] = $map[$args[0]];
$argv = array_merge(array('qbixctl'), $args);
require __DIR__ . '/qbixconsole.php';
