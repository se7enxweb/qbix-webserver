#!/usr/bin/env php
<?php
/**
 * Q_WebServer_Extensions and the ext:* commands: variants resolve to the
 * right extensions per platform, a static build and a host PHP are judged
 * by different rules, the check and its exit codes follow what is loaded,
 * install hints name the right packages per package manager, and the CLI
 * prints what a build pipeline consumes.
 *
 *   php tests/unit-extensions-resolve.php
 */
require __DIR__ . '/../src/Q/WebServer/Extensions.php';
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
$X = 'Q_WebServer_Extensions';
$m = $X::manifest();

// ── Resolving variants ─────────────────────────────────────
$mini = $X::resolve('mini', 'linux-x86_64', '8.3');
check('mini is the server tier', $mini['include'], array('ctype', 'filter', 'mbstring', 'openssl', 'pcntl', 'phar', 'posix', 'session', 'sockets', 'sqlite3', 'tokenizer'));
check('...with no gd libraries', $mini['libs'], array());
$std = $X::resolve('standard', 'linux-x86_64', '8.3');
check('standard on static Linux leaves out ODBC, with the reason', array_keys($std['exclude']), array('odbc', 'pdo_odbc'));
check('...and carries gd with its image libraries', array($std['libs'], in_array('gd', $std['include'], true)), array(array('libjpeg', 'libpng', 'libwebp', 'freetype'), true));
check('...tiers in order: the server tier comes first', array_slice($std['include'], 0, 1), array('ctype'));
check('...the spc list has one entry per included extension', count($std['spc']), count($std['include']));
$mac = $X::resolve('standard', 'macos-arm64', '8.3');
check('standard on macOS keeps ODBC', in_array('odbc', $mac['include'], true) && in_array('pdo_odbc', $mac['include'], true), true);
$win = $X::resolve('standard', 'windows-x64', '8.3');
foreach (array('pcntl', 'posix', 'intl', 'xsl', 'mongodb', 'memcached', 'ldap') as $n) {
	check("standard on Windows leaves out $n", isset($win['exclude'][$n]), true);
}
check('...and still carries redis, apcu and gd', array_values(array_intersect(array('apcu', 'gd', 'redis'), $win['include'])), array('apcu', 'gd', 'redis'));
$full = $X::resolve('full', 'linux-x86_64', '8.4');
check('full carries the extra tier', in_array('yaml', $full['include'], true) && in_array('ssh2', $full['include'], true), true);
check('...but no development tools', isset($full['exclude']['xdebug']) || !in_array('xdebug', $full['include'], true), true);
$with = $X::resolve('standard', 'linux-x86_64', '8.3', array('xdebug', 'nosuchext'));
check('--with adds a development tool', in_array('xdebug', $with['include'], true), true);
check('--with an unknown extension is reported', $with['exclude']['nosuchext'] ?? null, 'Not in the manifest');
check('source is a kit', $X::resolve('source', 'linux-x86_64', '8.3')['kit'], true);
$threw = false;
try { $X::resolve('huge', 'linux-x86_64', '8.3'); } catch (InvalidArgumentException $e) { $threw = true; }
check('an unknown variant is refused', $threw, true);

// ── Static build versus a host PHP ─────────────────────────
$host = $X::resolve('standard', 'linux-x86_64', '8.3', array(), false);
check('a host PHP on Linux is expected to have ODBC', in_array('odbc', $host['include'], true), true);
$hostWin = $X::resolve('standard', 'windows-x64', '8.3', array(), false);
check('a host PHP on Windows still cannot have pcntl', isset($hostWin['exclude']['pcntl']), true);
check('...but can have intl (only the static build lacks it)', in_array('intl', $hostWin['include'], true), true);

// ── Platforms ──────────────────────────────────────────────
check('platform: Linux x86_64', $X::platform('Linux', 'x86_64'), 'linux-x86_64');
check('platform: Linux arm64', $X::platform('Linux', 'aarch64'), 'linux-aarch64');
check('platform: macOS', $X::platform('Darwin', 'arm64'), 'macos-arm64');
check('platform: Windows', $X::platform('Windows', 'AMD64'), 'windows-x64');
check('platform: FreeBSD', $X::platform('BSD', 'amd64'), 'bsd-x86_64');

// ── Checking what is loaded ────────────────────────────────
$all = function ($n) { return true; };
$c = $X::check('standard', $all);
check('everything loaded: nothing missing', array($c['missing_required'], $c['missing_recommended']), array(array(), array()));
check('...the full set is detected', $c['variant_detected'], 'full');
$noIntl = function ($n) { return $n !== 'intl'; };
$c = $X::check('standard', $noIntl);
check('intl missing is a required gap', $c['missing_required'], array('intl'));
check('...and the detected set drops to mini', $c['variant_detected'], 'mini');
$noRedis = function ($n) { return $n !== 'redis'; };
$c = $X::check('standard', $noRedis);
check('redis missing is a recommended gap only', array($c['missing_required'], $c['missing_recommended']), array(array(), array('redis')));
check('...and the detected set is lite', $c['variant_detected'], 'lite');
check('opcache is found under its real name', $X::loaded('opcache'), extension_loaded('Zend OPcache'));

// ── Hard needs ─────────────────────────────────────────────
$none = function ($n) { return false; };
check('no tokenizer with the transform on is a hard need', array_column($X::hardNeeds(array('transform' => true), $none), 0), array('tokenizer'));
check('no openssl matters only when HTTPS is configured', array_column($X::hardNeeds(array('https' => true), $none), 0), array('openssl'));
check('nothing needed when both are present', $X::hardNeeds(array('transform' => true, 'https' => true), $all), array());

// ── Package managers and hints ─────────────────────────────
check('Debian is apt', $X::packageManager("ID=debian\n", '/usr/bin/php'), 'apt');
check('Ubuntu is apt', $X::packageManager("ID=ubuntu\nID_LIKE=debian\n", '/usr/bin/php'), 'apt');
check('Rocky is dnf', $X::packageManager("ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\n", '/usr/bin/php'), 'dnf');
check('Alpine is apk', $X::packageManager("ID=alpine\n", '/usr/bin/php'), 'apk');
check('a Remi collection is dnf-scl', $X::packageManager("ID=rocky\n", '/opt/remi/php83/root/usr/bin/php'), 'dnf-scl');
check('a Plesk PHP is plesk', $X::packageManager("ID=almalinux\n", '/opt/plesk/php/8.3/bin/php'), 'plesk');
$h = $X::installHints(array('intl', 'dom', 'xsl', 'mysqli', 'redis'), 'apt', '8.3');
check('apt: one command, packages de-duplicated', $h['commands'], array('sudo apt-get install -y php8.3-intl php8.3-xml php8.3-mysql php8.3-redis'));
$h = $X::installHints(array('intl', 'pcntl', 'redis'), 'dnf', '8.4');
check('dnf: php-intl, php-process, php-pecl-redis6', $h['commands'], array('sudo dnf install -y php-intl php-process php-pecl-redis6'));
$h = $X::installHints(array('intl', 'redis'), 'dnf-scl', '8.3');
check('Remi collections are prefixed', $h['commands'], array('sudo dnf install -y php83-php-intl php83-php-pecl-redis6'));
$h = $X::installHints(array('intl', 'mongodb'), 'apk', '8.3');
check('apk: php83-intl, php83-pecl-mongodb', $h['commands'], array('apk add php83-intl php83-pecl-mongodb'));
$h = $X::installHints(array('redis'), '', '8.3');
check('unknown system, PECL extension: pecl install', $h['commands'], array('pecl install redis   # then add extension=redis to php.ini'));
$h = $X::installHints(array('redis', 'intl'), 'plesk', '8.3');
check('Plesk: pecl from Plesk\'s PHP, bundled ones are switched on in Plesk', array(count($h['commands']), count($h['notes'])), array(1, 1));
$h = $X::installHints(array('pdo_firebird'), 'apt', '8.3');
check('the Firebird add-on has its own recipe', $h['commands'], array('apt-get install -y php8.3-interbase'));
$h = $X::installHints(array('json'), 'apt', '8.3');
check('a core extension is part of PHP', count($h['commands']) === 0 && strpos($h['notes'][0], 'part of PHP') !== false, true);

// ── The CLI ────────────────────────────────────────────────
$ctl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../qbixctl.php');
$run = function ($args) use ($ctl) {
	$out = array();
	exec("$ctl $args 2>&1", $out, $code);
	return array(implode("\n", $out), $code);
};
list($out, $code) = $run('ext:list --variant=mini --platform=linux-x86_64 --php=8.3 --format=spc');
check('ext:list --format=spc prints the comma list', array($out, $code), array('ctype,filter,mbstring,openssl,pcntl,phar,posix,session,sockets,sqlite3,tokenizer', 0));
list($out, $code) = $run('ext:list --variant=standard --platform=linux-x86_64 --php=8.3 --format=libs');
check('ext:list --format=libs prints the libraries', array($out, $code), array('libjpeg,libpng,libwebp,freetype', 0));
list($out, $code) = $run('ext:list -variant standard -platform windows-x64 -php 8.3 -format json');
$j = json_decode($out, true);
check('ext:list accepts BSD-style options and prints JSON', array($code, $j['platform'] ?? null, isset($j['exclude']['intl'])), array(0, 'windows-x64', true));
list($out, $code) = $run('ext:list --variant=huge');
check('ext:list refuses an unknown variant', array($code, strpos($out, 'unknown variant') !== false), array(1, true));
list($out, $code) = $run('ext:list --php=7.4');
check('ext:list refuses a PHP version that is not built', $code, 1);
list($out, $code) = $run('ext:plan --variant=full --platform=windows-x64 --php=8.4');
check('ext:plan says what is left out and why', array($code, strpos($out, 'left out:') !== false, strpos($out, 'No fork() on Windows') !== false), array(0, true, true));
list($out, $code) = $run('ext:check --format=json');
$j = json_decode($out, true);
check('ext:check --format=json reports and exits by what is missing', array(is_array($j), $code === ($j['exit'] ?? -1)), array(true, true));
list($out, $code) = $run('ext:install-hint --manager=apt --php=8.3 intl xsl');
check('ext:install-hint prints the command', array($code, strpos($out, 'sudo apt-get install -y php8.3-intl php8.3-xml') !== false), array(0, true));
list($out, $code) = $run('ext:build --variant=mini --php=8.3 --platform=linux-x86_64 --spc=/opt/spc --out=/tmp/x --dry-run');
check('ext:build --dry-run prints download, build, phar and combine', array($code, substr_count($out, '$ ')), array(0, 4));
check('...naming the artifact by platform, PHP and variant', strpos($out, 'qbixserver-linux-x86_64-php8.3-mini') !== false, true);
list($out, $code) = $run('ext:build --variant=source --dry-run --out=/tmp/x');
check('ext:build --variant=source lists the kit', array($code, strpos($out, 'qbixserver-source-kit.tar.gz') !== false, strpos($out, 'BUILD.md') !== false), array(0, true, true));


// The full variant builds: nothing static-php-cli refuses reaches its list.
// Each of these stopped a full build in CI (a vanished upstream branch,
// shared-only extensions, a refused pair, a Windows configure clash).
foreach (array('linux-x86_64', 'linux-aarch64', 'macos-arm64', 'windows-x64') as $p) {
	$full = $X::resolve('full', $p, '8.3');
	check("full on $p leaves out rar and the shared-only mysqlnd plugins, with reasons",
		array_values(array_intersect(array('mysqlnd_ed25519', 'mysqlnd_parsec', 'rar'), array_keys($full['exclude']))),
		array('mysqlnd_ed25519', 'mysqlnd_parsec', 'rar'));
	check("...never carries both protobuf and grpc on $p",
		in_array('protobuf', $full['include'], true) && in_array('grpc', $full['include'], true), false);
	// redis is built with igbinary support, so igbinary must be in, and first.
	$ig = array_search('igbinary', $full['include'], true);
	$rd = array_search('redis', $full['include'], true);
	check("...carries igbinary before redis on $p", $ig !== false && $rd !== false && $ig < $rd, true);
}
$fw = $X::resolve('full', 'windows-x64', '8.3');
check('full on Windows leaves out yac, which breaks redis\' igbinary detection there', isset($fw['exclude']['yac']), true);
check('...and xlswriter, whose Windows patch no longer applies', isset($fw['exclude']['xlswriter']), true);
check('...and ds, whose Windows build points at a missing source file', isset($fw['exclude']['ds']), true);
check('...while Linux keeps it', in_array('yac', $X::resolve('full', 'linux-x86_64', '8.3')['include'], true), true);
check('a dynamic PHP is not held to the static exclusions (rar, on Linux)',
	isset($X::resolve('full', 'linux-x86_64', '8.3', array(), false)['exclude']['rar']), false);
if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
