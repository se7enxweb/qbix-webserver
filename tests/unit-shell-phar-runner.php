<?php
/**
 * The shell works from the phar, run as an ordinary user.
 *
 * The shell's runner (qshell.php) and the console tools are embedded in the
 * phar, and the server starts them through it (--qshell, --qconsole): a script
 * inside a phar cannot be handed to PHP by path. Before, the phar carried
 * neither, and every command answered "the shell runner is missing" -- as a
 * 429 the console read as "too many jobs". The packages, the container image
 * and the static binaries all start this same phar.
 *
 * Starts bin/qbixserver.phar on 127.0.0.1 as a non-root user (nobody, when run
 * as root), sets the panel's password, signs in, and runs `help`, `server
 * status` (a console command the server runs for the runner) and `history`
 * through the shell's API.
 *
 *   php tests/unit-shell-phar-runner.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$fails = 0; $cases = 0;
function check($what, $got, $want)
{
	global $fails, $cases;
	++$cases;
	if ($got === $want) { echo "  ok    $what\n"; return; }
	++$fails;
	echo "  FAIL  $what\n        got:  " . var_export($got, true) . "\n        want: " . var_export($want, true) . "\n";
}
function done()
{
	global $fails, $cases;
	echo $fails ? "  FAIL - $fails of $cases case(s)\n" : "  PASS - $cases case(s)\n";
	exit($fails ? 1 : 0);
}

$phar = dirname(__DIR__) . '/bin/qbixserver.phar';
if (!is_file($phar) || !class_exists('Phar')) { echo "  skip  no phar or no phar extension\n"; exit(0); }
if (!function_exists('proc_open') || DS !== '/') { echo "  skip  needs proc_open on a Unix system\n"; exit(0); }

// ── What the phar carries ────────────────────────────────────────────────
$p = new Phar($phar);
foreach (array('qshell.php', 'qbixconsole.php', 'qbixctl.php') as $f) check("the phar carries $f", isset($p[$f]), true);
unset($p);

// ── How each build starts the runner (Shell_Entry::packed) ───────────────
if (!class_exists('Q_WebServer_Shell_Entry', false)) require_once dirname(__DIR__) . '/src/Q/WebServer/Shell/Entry.php';
$inside = 'phar://' . $phar . '/src/Q/WebServer/Shell';
check('from the phar under PHP: PHP, then the phar', Q_WebServer_Shell_Entry::packed($inside, '/usr/bin/php', 'cli'), array('/usr/bin/php', $phar));
// A static binary: micro has PHP_BINARY = '' and the phar is the executable.
check('from a static binary (micro, no PHP_BINARY): the binary alone', Q_WebServer_Shell_Entry::packed($inside, '', 'micro'), array($phar));
check('an empty PHP_BINARY alone also means the binary', Q_WebServer_Shell_Entry::packed($inside, '', 'cli'), array($phar));
check('the phar run as itself: the binary alone', Q_WebServer_Shell_Entry::packed($inside, $phar, 'cli'), array($phar));
check('a real directory is not packed', Q_WebServer_Shell_Entry::packed(__DIR__, '', 'micro'), null);

// ── Who runs it: never root ──────────────────────────────────────────────
$asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
$user = null; $prefix = array();
if ($asRoot) {
	$user = function_exists('posix_getpwnam') ? posix_getpwnam('nobody') : false;
	$runuser = trim((string) @shell_exec('command -v runuser 2>/dev/null'));
	if (!$user || $runuser === '') { echo "  skip  root, but no user nobody or no runuser\n"; exit(0); }
	$prefix = array($runuser, '-u', 'nobody', '--');
}

$tmp = sys_get_temp_dir() . DS . 'qbix-pharshell-' . getmypid();
@mkdir("$tmp/app/web", 0755, true);
@mkdir("$tmp/app/local", 0700, true);
file_put_contents("$tmp/app/web/index.php", '<?php echo "hello";');
copy($phar, "$tmp/qbixserver.phar");
chmod($tmp, 0755); chmod("$tmp/app", 0755); chmod("$tmp/app/web", 0755);
if ($user) {
	foreach (array($tmp, "$tmp/app", "$tmp/app/web", "$tmp/app/local", "$tmp/app/web/index.php", "$tmp/qbixserver.phar") as $f) chown($f, $user['uid']);
}

$port = 0;
for ($i = 0; $i < 40 && !$port; ++$i) {
	$try = 19900 + random_int(0, 600);
	$probe = @stream_socket_server("tcp://127.0.0.1:$try", $e1, $e2);
	if ($probe) { fclose($probe); $port = $try; }
}
$log = "$tmp/server.log";
$cmd = array_merge($prefix, array(PHP_BINARY, '-d', 'opcache.enable_cli=0', "$tmp/qbixserver.phar",
	'--root=' . "$tmp/app/web", '--host=127.0.0.1', '--port=' . $port, '--workers=2'));
$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'w'), 2 => array('file', $log, 'a')), $pipes, "$tmp/app");
register_shutdown_function(function () use ($proc, $tmp, $port) {
	// The server by its PID and the workers it started, then the files.
	if (is_resource($proc)) {
		$st = proc_get_status($proc);
		$pids = array((int) $st['pid']);
		foreach (glob('/proc/[1-9]*/stat') ?: array() as $f) {
			$s = @file_get_contents($f);
			if ($s && preg_match('/^(\d+) \(.*?\) \S (\d+)/', $s, $m) && in_array((int) $m[2], $pids, true)) $pids[] = (int) $m[1];
		}
		foreach ($pids as $pid) if ($pid > 0) @posix_kill($pid, 15);
		usleep(800000);
		foreach ($pids as $pid) if ($pid > 0 && @posix_kill($pid, 0)) @posix_kill($pid, 9);
		@proc_close($proc);
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
	@rmdir($tmp);
});
$up = false;
for ($i = 0; $i < 60 && !$up; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; } else usleep(500000);
}
check('the phar serves as ' . ($user ? 'nobody' : 'this user'), $up, true);
if (!$up) { echo @file_get_contents($log); done(); }

function http($port, $method, $path, $body = null, $token = null)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $m, 5);
	if (!$s) return array(0, null);
	stream_set_timeout($s, 20);
	$data = $body === null ? '' : json_encode($body);
	$req = "$method $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n";
	if ($token !== null) $req .= "X-Panel-Token: $token\r\n";
	if ($body !== null) $req .= "Content-Type: application/json\r\nContent-Length: " . strlen($data) . "\r\n";
	fwrite($s, $req . "\r\n" . $data);
	$raw = (string) stream_get_contents($s);
	fclose($s);
	list($head, $rest) = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
	$code = preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int) $m[1] : 0;
	if (stripos($head, "transfer-encoding: chunked") !== false) {
		$out = '';
		while ($rest !== '' && preg_match('/^([0-9a-f]+)\r\n/i', $rest, $m)) {
			$n = hexdec($m[1]);
			if ($n === 0) break;
			$out .= substr($rest, strlen($m[0]), $n);
			$rest = substr($rest, strlen($m[0]) + $n + 2);
		}
		$rest = $out;
	}
	return array($code, json_decode($rest, true));
}

$pw = 'Phar-Shell-Test-9931!';
list($code) = http($port, 'POST', '/Q/api/auth/setup', array('password' => $pw));
check('the panel password is set', in_array($code, array(200, 201), true), true);
list($code, $j) = http($port, 'POST', '/Q/api/auth/login', array('password' => $pw));
$token = is_array($j) ? ($j['token'] ?? null) : null;
check('and signs in', $code === 200 && is_string($token), true);
if (!$token) { echo @file_get_contents($log); done(); }

/** Run one line in the shell; its output and exit code. */
function run($port, $token, $line)
{
	$session = 'phar-' . bin2hex(random_bytes(4));
	list($code, $j) = http($port, 'POST', '/Q/api/shell/exec', array('command' => $line, 'session' => $session), $token);
	if ($code !== 202) return array('HTTP ' . $code . ': ' . json_encode($j), null);
	$out = ''; $exit = null; $since = 0;
	for ($i = 0; $i < 80 && $exit === null; ++$i) {
		list($c, $p) = http($port, 'GET', '/Q/api/shell/poll?session=' . $session . '&since=' . $since, null, $token);
		foreach ((array) ($p['messages'] ?? array()) as $m) {
			if (in_array($m['t'] ?? '', array('out', 'err'), true)) $out .= (string) ($m['d'] ?? '');
			if (($m['t'] ?? '') === 'exit') $exit = (int) $m['code'];
		}
		if (isset($p['next'])) $since = $p['next'];
		if ($exit === null) usleep(250000);
	}
	return array(preg_replace('/\e\[[0-9;]*m/', '', $out), $exit);
}

list($out, $exit) = run($port, $token, 'help');
check('help runs from the phar', $exit, 0);
check('...and prints the command list', strpos($out, 'Q shell') !== false && strpos($out, 'server') !== false, true);
check('...with no "runner is missing"', strpos($out, 'missing') === false, true);

list($out, $exit) = run($port, $token, 'server status');
if ($exit !== 0) echo "        output: " . str_replace("\n", "\n        ", trim($out)) . "\n";
check('server status (a console command the server runs) exits 0', $exit, 0);
check('...and reports this server running', (bool) preg_match('/running\s*:\s*yes/', $out), true);

list($out, $exit) = run($port, $token, 'history');
check('history runs', $exit, 0);
check('...and lists the earlier commands', strpos($out, 'help') !== false && strpos($out, 'server status') !== false, true);

list($out, $exit) = run($port, $token, 'nosuchcommand');
check('an unknown command is a shell error, not a missing runner', $exit === 127 && strpos($out, 'missing') === false, true);

done();
