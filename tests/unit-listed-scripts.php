<?php

/**
 * Only the scripts an installation lists run when asked for by name, and only
 * the files it lists are sent as they are.
 *
 * Every .php file inside the document root ran when its path was requested:
 * libraries, installers, command-line tools, a package's settings. An
 * application whose .htaccess serves the assets and sends everything else to
 * index.php was protected under Apache and not here -- a pooled worker runs
 * the script the server hands it and never reads .htaccess.
 *
 * Q.webserver.scripts lists the scripts that may run under their own name;
 * any other goes to the front controller. Q.webserver.frontControllers sends
 * a URL pattern to a script other than index.php, which is how a rule such as
 * "RewriteRule ^api/ index_rest.php" is said to the server. Q.web.static.paths
 * lists the files sent as they are -- the "- [L]" rules; any other goes to the
 * front controller, so a protected upload in the document root is handed out
 * only by the script that checks who is asking.
 *
 * Both worker modes, over HTTP/1.1 and (with curl's HTTP/2 and openssl)
 * HTTP/2, which resolves its script on a path of its own. A server without
 * the keys is checked as well, so the default stays what it was.
 *
 *   php tests/unit-listed-scripts.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;
$skipped = array();

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }

$server = __DIR__ . '/../qbixserver.php';
$http2 = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS')
	&& (curl_version()['features'] & CURL_VERSION_HTTP2)
	&& function_exists('openssl_pkey_new');

/** A port nothing else holds. */
function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** HTTP/1.1: the body, trimmed, or null. */
function fetch1($port, $path, $method = 'GET', array $headers = array())
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return null;
	stream_set_timeout($s, 10);
	$headers += array('Host' => isset($GLOBALS['cacheHost']) ? $GLOBALS['cacheHost'] : "127.0.0.1:$port");
	$lines = '';
	foreach ($headers as $name => $value) $lines .= "$name: $value\r\n";
	fwrite($s, "$method $path HTTP/1.1\r\n{$lines}Connection: close\r\n\r\n");
	$raw = '';
	while (!feof($s)) {
		$chunk = fread($s, 8192);
		if ($chunk === false or $chunk === '') {
			if (stream_get_meta_data($s)['timed_out']) break;
			continue;
		}
		$raw .= $chunk;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return null;
	$body = substr($raw, $split + 4);
	if (stripos(substr($raw, 0, $split), "transfer-encoding: chunked") !== false) {
		$out = '';
		while ($body !== '') {
			$nl = strpos($body, "\r\n");
			if ($nl === false) break;
			$len = hexdec(substr($body, 0, $nl));
			if ($len === 0) break;
			$out .= substr($body, $nl + 2, $len);
			$body = substr($body, $nl + 2 + $len + 2);
		}
		$body = $out;
	}
	return trim($body);
}

/** HTTP/2 over TLS: the body, trimmed, or null. */
function fetch2($port, $path)
{
	$ch = curl_init("https://127.0.0.1:$port$path");
	// The path exactly as written: curl would otherwise tidy "/./" and "//"
	// away, and those are among the paths the list must hold against.
	if (defined('CURLOPT_PATH_AS_IS')) curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_TIMEOUT        => 10,
	));
	$body = curl_exec($ch);
	$version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
	curl_close($ch);
	if ($body === false or $version !== CURL_HTTP_VERSION_2_0) return null;
	return trim((string) $body);
}

/** A self-signed certificate for 127.0.0.1, written to $dir. */
function makeCert($dir)
{
	$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
	$crt = openssl_csr_sign($csr, null, $key, 1, array('digest_alg' => 'sha256'));
	openssl_x509_export($crt, $crtPem);
	openssl_pkey_export($key, $keyPem);
	file_put_contents($dir . DS . 'fullchain.pem', $crtPem);
	file_put_contents($dir . DS . 'privkey.pem', $keyPem);
}

/** Each script names itself, so the answer shows which one ran. */
$files = array(
	'index.php'          => '<?php echo "front";',
	'rest.php'           => '<?php echo "rest";',
	'lib/tool.php'       => '<?php echo "tool";',
	'sub/index.php'      => '<?php echo "subindex";',
	'assets/app.css'     => 'body{}',
	'private/secret.txt' => 'secret',
	// A script with its extension in capitals, and a precompressed sibling
	// of the protected file: neither may be reached around the lists.
	'lib/upper.PHP'         => '<?php echo "upper";',
	'private/secret.txt.gz' => 'gzsecret',
	// A page that says it may be kept, for the cache case below.
	'lib/cached.php'        => '<?php header("Cache-Control: public, max-age=600"); echo "cachedtool";',
);

/**
 * Ways round the lists. None may answer with an unlisted script's output or
 * an unlisted file; the front controller, a 400 or a 404 are all fine.
 */
$bypasses = array(
	'/lib/upper.PHP', '/lib/tool%2ephp', '/lib%2ftool.php', '/lib/tool%252ephp',
	'/lib//tool.php', '/./lib/tool.php', '/lib/./tool.php', '/lib\\tool.php',
	'/lib/tool.php;x', '/lib/tool.php.', '/lib/tool.php%20', '/lib/tool.php%00',
	'/lib/tool.php/extra/more', '/sub/index.php', '/sub/index.php/x',
	'/private//secret.txt', '/./private/secret.txt', '/private/./secret.txt',
	'/private/secret.txt%00', '/private/secret.txt.gz', '/private%2fsecret.txt',
	'/assets/%2e%2e/private/secret.txt', '/assets/..%2fprivate/secret.txt',
	'/assets%2f..%2fprivate%2fsecret.txt', '/assets/..\\private\\secret.txt',
	'/assets/../private/secret.txt', '/linked/../private/secret.txt',
);
$forbidden = array('tool', 'secret', 'upper', 'subindex', 'gzsecret', 'cachedtool');

/**
 * Start a server, ask it for each path, stop it.
 *
 * @param array $expect path => the script that should answer
 */
function run($server, $forkPerRequest, $listed, $http2, array $expect, $cacheDir = null)
{
	global $files, $skipped, $bypasses, $forbidden;
	$mode = ($forkPerRequest ? 'fresh worker per request' : 'persistent workers')
		. ($listed ? ', scripts listed' : ', no list');
	$base = sys_get_temp_dir() . DS . 'qbix-scripts-' . getmypid() . '-' . (int) $forkPerRequest . (int) $listed;
	$root = $base . DS . 'web';
	foreach ($files as $name => $content) {
		@mkdir(dirname($root . DS . $name), 0700, true);
		file_put_contents($root . DS . $name, $content);
	}

	$port = freePort();
	$tlsPort = freePort();
	if (!$port or !$tlsPort or $port === $tlsPort) { check("$mode: free ports", 0, 1); return; }

	$config = array('webserver' => array('forkPerRequest' => $forkPerRequest));
	if ($cacheDir !== null) {
		$config['web']['cache'] = array('enabled' => true, 'dir' => $cacheDir);
	}
	if ($listed) {
		$config['webserver']['scripts'] = array('/index.php', 'rest.php');
		$config['webserver']['frontControllers'] = array('^/api/' => 'rest.php');
		$config['web']['static']['paths'] = array('^/assets/', '^/linked/');
		// An asset directory that is a link out of the root: listed, so
		// served, although the file it resolves to lies outside.
		$config['webserver']['followSymlinks'] = true;
		@mkdir($base . DS . 'shared', 0700, true);
		file_put_contents($base . DS . 'shared' . DS . 'theme.css', 'linked{}');
		@symlink($base . DS . 'shared', $root . DS . 'linked');
	}
	if ($http2) {
		makeCert($base);
		$config['web'] = ($config['web'] ?? array()) + array(
			'https' => array('mode' => 'manual', 'port' => $tlsPort,
				'cert' => $base . DS . 'fullchain.pem', 'key' => $base . DS . 'privkey.pem',
				'certsDir' => $base),
			'http2' => array('enabled' => true),
		);
	}
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => $config)));

	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($server)
		. ' --config=' . escapeshellarg($base . DS . 'config.json')
		. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=2';
	// Descriptors rather than shell redirection, so the pid is the server's.
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', $base . '/log', 'w'),
		2 => array('file', $base . '/log', 'a'),
	), $pipes);
	if (!is_resource($proc)) { check("$mode: server started", false, true); return; }

	$up = false;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); $up = true; break; }
		usleep(500000);
	}

	if (!$up) {
		check("$mode: server listened", false, true);
		fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	} else {
		foreach ($expect as $path => $want) {
			check("$mode, HTTP/1.1: $path", fetch1($port, $path), $want);
			if ($http2) {
				check("$mode, HTTP/2: $path", fetch2($tlsPort, $path), $want);
			}
		}
		if ($listed) {
			// Starts like an asset; refused or sent to the application, but
			// never answered with the file.
			check("$mode, HTTP/1.1: dot segments do not reach an unlisted file",
				fetch1($port, '/assets/../private/secret.txt') !== 'secret', true);
			foreach ($bypasses as $p) {
				check("$mode, HTTP/1.1: $p reaches nothing unlisted",
					in_array(fetch1($port, $p), $forbidden, true), false);
				if ($http2) {
					check("$mode, HTTP/2: $p reaches nothing unlisted",
						in_array(fetch2($tlsPort, $p), $forbidden, true), false);
				}
			}
			// Other methods, and an encoding that would pick a precompressed copy.
			foreach (array('HEAD', 'POST', 'OPTIONS') as $m) {
				check("$mode, HTTP/1.1: $m /lib/tool.php reaches nothing unlisted",
					in_array(fetch1($port, '/lib/tool.php', $m), $forbidden, true), false);
			}
			check("$mode, HTTP/1.1: gzip asked for, the protected file is still not sent",
				in_array(fetch1($port, '/private/secret.txt', 'GET', array('Accept-Encoding' => 'gzip, br')), $forbidden, true), false);
			check("$mode, HTTP/1.1: a range of the protected file is not sent",
				in_array(fetch1($port, '/private/secret.txt', 'GET', array('Range' => 'bytes=0-3')), array('secr', 'secret'), true), false);
			// The server's own pages are not the application's to list.
			check("$mode, HTTP/1.1: /Q/health is still the server's",
				strpos((string) fetch1($port, '/Q/health'), 'front') === false, true);
		}
		if (!$http2 and empty($GLOBALS['http2'])) $skipped['http2'] = 'no HTTP/2 in curl, or no openssl extension';
	}

	$st = @proc_get_status($proc);
	$pid = (int) ($st['pid'] ?? 0);
	if ($st and !empty($st['running']) and $pid > 0) {
		@proc_terminate($proc, 15);
		for ($i = 0; $i < 40; ++$i) {
			$now = @proc_get_status($proc);
			if (!$now or empty($now['running'])) break;
			usleep(250000);
		}
		$now = @proc_get_status($proc);
		if ($now and !empty($now['running'])) {
			@proc_terminate($proc, 9);
			if (function_exists('posix_kill')) @posix_kill($pid, 9);
		}
	}
	@proc_close($proc);

	foreach (array_keys($files) as $name) @unlink($root . DS . $name);
	foreach (array('lib', 'sub', 'assets', 'private') as $dir) @rmdir($root . DS . $dir);
	@unlink($root . DS . 'linked');
	@unlink($base . DS . 'shared' . DS . 'theme.css');
	@rmdir($base . DS . 'shared');
	foreach (array('config.json', 'log', 'fullchain.pem', 'privkey.pem') as $f) @unlink($base . DS . $f);
	@rmdir($root);
	@rmdir($base);
}

// With the list: nothing but the listed scripts runs by name, the pattern
// reaches its script, assets are still files.
$listed = array(
	'/'                   => 'front',
	'/index.php'          => 'front',
	'/rest.php'           => 'rest',
	'/api/items'          => 'rest',
	'/lib/tool.php'       => 'front',
	'/lib/tool.php/extra' => 'front',
	'/sub/'               => 'front',
	'/nowhere'            => 'front',
	'/assets/app.css'     => 'body{}',
	'/private/secret.txt' => 'front',
	'/linked/theme.css'   => 'linked{}',
);

// Without it, as before: every script runs, and there is one front controller.
$unlisted = array(
	'/'                   => 'front',
	'/rest.php'           => 'rest',
	'/api/items'          => 'front',
	'/lib/tool.php'       => 'tool',
	'/sub/'               => 'subindex',
	'/assets/app.css'     => 'body{}',
	'/private/secret.txt' => 'secret',
);

foreach (array(false, true) as $fork) {
	run($server, $fork, true, $http2, $listed);
	run($server, $fork, false, $http2, $unlisted);
}

// A page stored while every script ran must not be answered once the list
// stops the script from running: the lookup comes before the list.
$cacheDir = sys_get_temp_dir() . DS . 'qbix-scripts-cache-' . getmypid();
@mkdir($cacheDir, 0700, true);
$GLOBALS['cacheHost'] = 'cache.test';
run($server, false, false, false, array('/lib/cached.php' => 'cachedtool'), $cacheDir);
run($server, false, false, false, array('/lib/cached.php' => 'cachedtool'), $cacheDir);
run($server, false, true, false, array('/lib/cached.php' => 'front'), $cacheDir);
foreach ((array) glob($cacheDir . DS . '{,.}*', GLOB_BRACE) as $f) {
	if (is_file($f)) @unlink($f);
}
foreach ((array) glob($cacheDir . DS . '*', GLOB_ONLYDIR) as $d) {
	foreach ((array) glob($d . DS . '*') as $f) @unlink($f);
	@rmdir($d);
}
@rmdir($cacheDir);

echo "\n";
foreach ($skipped as $what => $why) printf("  skip  %s: %s\n", $what, $why);
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
