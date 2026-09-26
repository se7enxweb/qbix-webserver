#!/usr/bin/env php
<?php
/**
 * qbixconsole -- the server's command console.
 *
 *   php qbixconsole.php list
 *   php qbixconsole.php help server:start
 *   php qbixconsole.php server:status --config=/etc/qbix/sites-enabled/example.conf
 *   php qbixconsole.php ser:stat          (unique abbreviations work)
 *
 * Commands in the manner of Symfony's console, with no library behind it
 * (Q_Console). The control commands are Q_WebServer_Ctl's; a distribution of
 * the engine can add its own from its register() (see --distribution).
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'micro') { exit("qbixconsole runs from the command line\n"); }
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/src/Q.php';
require_once __DIR__ . '/src/Q/Console.php';
require_once __DIR__ . '/src/Q/WebServer/Layout.php';
require_once __DIR__ . '/src/Q/WebServer/Ctl.php';

Q_Console::$program = basename($argv[0] ?? 'qbixconsole');
Q_Console::$title = 'Qbix server console' . (function_exists('qbix_version_label') ? ' ' . qbix_version_label(true) : '');
Q_WebServer_Ctl::register(__DIR__);

// The distribution, if any, may add commands: registered now, before the
// command runs, so they appear in `list` too.
list(, $__opts) = Q_Console::parse(array_slice($argv, 1));
Q_WebServer_Layout::loadDistribution(isset($__opts['distribution']) ? (string) $__opts['distribution'] : null, __DIR__);

exit(Q_Console::run($argv));
