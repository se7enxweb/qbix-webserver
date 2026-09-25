#!/usr/bin/env php
<?php
/**
 * The panel's own settings (appsDir, autohost, domains) and the application
 * files the panel changes (local/app.json, config/deploy.json) are written
 * under a lock and renamed into place -- never a plain read-modify-write --
 * and the shell's files move once from APP_DIR/local/shell into the data
 * directory beside the panel's sessions.
 *
 * Asserted:
 *   - no function in src/ both names the panel's settings file and writes a
 *     file directly: every such write goes through the store;
 *   - apiSetAppsDir and apiAutohostToggle land in acl/panel.json and keep
 *     the password hash and each other's keys;
 *   - 8 workers x 25 changes each, through the store and through
 *     fileUpdate(), lose nothing (every key present, the shared counter at
 *     200);
 *   - the shell move merges history (older first, no repeated neighbours),
 *     merges aliases (the newer file wins a clash), copies what is missing,
 *     keeps a newer old script as <name>.legacy, leaves the old files and a
 *     note behind, and a second run changes nothing;
 *   - an old shell directory another user owns is not read.
 *
 *   php tests/unit-panel-settings-store.php   (as root: the ownership case needs it)
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Auth.php';
require_once __DIR__ . '/../src/Q/WebServer/Shell.php';
require_once __DIR__ . '/../src/Q/WebServer/Shell/History.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('pcntl_fork')) { echo "  skip  needs pcntl\n"; exit(0); }

$S = 'Q_WebServer_Panel_Store';
$base = sys_get_temp_dir() . DS . 'qbix-panel-settings-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
$acl = $base . '/acl';
$sessions = $base . '/state/sessions';
Q_Config::set('Q', 'panel', 'aclDir', $acl);
Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
define('APP_DIR', $base . '/app');
$S::setAppDir($base . '/app');
@mkdir($base . '/app/local', 0700, true);
chmod($base . '/app', 0755);

// ── No plain writes of the settings file ────────────────────────────
$offenders = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	if (substr($f, -4) !== '.php') continue;
	$rel = substr($f->getPathname(), strlen(__DIR__ . '/../src/'));
	if ($rel === 'Q/WebServer/Panel/Store.php') continue;
	$chunks = preg_split('/\n\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+/', (string) file_get_contents($f));
	foreach ($chunks as $c) {
		// Auth::save() writes a caller-named file (the CLI's --config), and
		// hands the store's own file to the store.
		if ($rel === 'Q/WebServer/Panel/Auth.php' and strpos($c, 'isStore(') !== false) continue;
		if (preg_match('/panelConfigPath\(\)|[\'"][^\'"]*panel\.json[\'"]/', $c)
		 and preg_match('/file_put_contents\s*\(|fopen\s*\([^)]*[\'"][wax]/', $c)) {
			$offenders[] = $rel . ': ' . strtok(ltrim($c), '(');
		}
	}
}
check('no function writes the panel settings file directly', $offenders, array());

// ── The API writes land in the store ────────────────────────────────
check('the store is trusted', $S::problem(), null);
$S::aclSave(array('passwordHash' => 'HASH'));
mkdir($base . '/apps', 0755);
$r = Q_WebServer_Panel::apiSetAppsDir(array('body' => json_encode(array('dir' => $base . '/apps'))));
check('apiSetAppsDir answers ok', $r['ok'] ?? null, true);
$r = Q_WebServer_Panel::apiAutohostToggle(array('body' => json_encode(array('enabled' => true, 'allowlist' => "a.example\nb.example\n"))));
check('apiAutohostToggle answers saved', $r['saved'] ?? null, true);
$r = Q_WebServer_Panel::apiAutohostToggle(array('body' => json_encode(array('dnsCheck' => true))));
$c = $S::aclLoad();
check('appsDir is in the store', $c['appsDir'] ?? null, realpath($base . '/apps'));
check('autohost keeps earlier fields', $c['autohost'] ?? null, array('enabled' => true, 'allowlist' => array('a.example', 'b.example'), 'dnsCheck' => true));
check('the password hash survives', $c['passwordHash'] ?? null, 'HASH');
check('setting() reads it', $S::setting('appsDir'), realpath($base . '/apps'));
check('acl/panel.json is 0600', fileperms($acl . '/panel.json') & 0777, 0600);

// ── Concurrent workers lose nothing ─────────────────────────────────
$S::settingSet('counter', 0);
$shared = $base . '/app/local/app.json';
file_put_contents($shared, '{"keep":1}');
chmod($shared, 0640);
$pids = array();
for ($w = 0; $w < 8; ++$w) {
	$pid = pcntl_fork();
	if ($pid === 0) {
		$S::reset();
		for ($i = 0; $i < 25; ++$i) {
			$S::settingSet("w{$w}_$i", $i);
			$S::aclUpdate(function (array $c) { $c['counter'] = ($c['counter'] ?? 0) + 1; return $c; });
			$S::fileUpdate($shared, function (array $d) use ($w, $i) { $d["w{$w}_$i"] = true; $d['n'] = ($d['n'] ?? 0) + 1; return $d; });
		}
		exit(0);
	}
	$pids[] = $pid;
}
foreach ($pids as $pid) pcntl_waitpid($pid, $st);
$S::reset();
$c = $S::aclLoad();
$missing = 0;
for ($w = 0; $w < 8; ++$w) for ($i = 0; $i < 25; ++$i) if (($c["w{$w}_$i"] ?? null) !== $i) ++$missing;
check('every worker\'s setting is kept', $missing, 0);
check('no counter update is lost in the store', $c['counter'] ?? null, 200);
check('the password hash survives the storm', $c['passwordHash'] ?? null, 'HASH');
$d = json_decode(file_get_contents($shared), true);
check('no update is lost in fileUpdate()', $d['n'] ?? null, 200);
check('fileUpdate() keeps other keys', $d['keep'] ?? null, 1);
check('fileUpdate() keeps the file\'s mode', fileperms($shared) & 0777, 0640);
check('no temporary files are left', glob($base . '/app/local/.*.tmp') ?: array(), array());

// ── The shell's files move once ─────────────────────────────────────
$old = $base . '/app/local/shell';
$new = $base . '/state/shell';
mkdir($old, 0700);
file_put_contents($old . '/history', "ls\nstatus\n");
file_put_contents($old . '/aliases.json', '{"ll":"ls -l","st":"old status"}');
file_put_contents($old . '/autoexec.qsh', "echo old\n");
file_put_contents($old . '/tools.qsh', "echo tools\n");
foreach (glob($old . '/*') as $f) { chmod($f, 0600); touch($f, time() - 100); }
touch($old . '/autoexec.qsh', time() + 50);
mkdir($new, 0700);
file_put_contents($new . '/history', "status\nuptime\n");
file_put_contents($new . '/aliases.json', '{"st":"new status"}');
file_put_contents($new . '/autoexec.qsh', "echo new\n");
foreach (glob($new . '/*') as $f) chmod($f, 0600);
$m = Q_WebServer_Shell::moveLegacyData($new, $old);
check('the move ran', $m['moved'], true);
check('history: older lines first, no repeated neighbours', file_get_contents($new . '/history'), "ls\nstatus\nuptime\n");
check('aliases: merged, the newer wins', json_decode(file_get_contents($new . '/aliases.json'), true), array('ll' => 'ls -l', 'st' => 'new status'));
check('a missing script is copied', file_get_contents($new . '/tools.qsh'), "echo tools\n");
check('a present script is kept', file_get_contents($new . '/autoexec.qsh'), "echo new\n");
check('a newer old script is kept beside it', file_get_contents($new . '/autoexec.qsh.legacy'), "echo old\n");
check('moved files are 0600', fileperms($new . '/tools.qsh') & 0777, 0600);
check('the old files stay as a copy', file_get_contents($old . '/history'), "ls\nstatus\n");
check('a note is left', is_file($old . '/.moved'), true);
$before = file_get_contents($new . '/history');
$m = Q_WebServer_Shell::moveLegacyData($new, $old);
check('a second run does nothing', array($m['moved'], file_get_contents($new . '/history')), array(false, $before));

// dataDir() runs it on its own, with the configured directories.
@unlink($old . '/.moved');
file_put_contents($old . '/history', "ls\nstatus\nnewer-old\n");
chmod($old . '/history', 0600);
touch($old . '/history', time() + 100);
$dir = Q_WebServer_Shell::dataDir();
check('dataDir() is beside the sessions', $dir, $new);
check('dataDir() moved the old files', substr(file_get_contents($new . '/history'), -10), "newer-old\n");
check('dataDir() moves only once per process', Q_WebServer_Shell::dataDir() === $dir && is_file($old . '/.moved'), true);

// Another user's old directory is not read.
if (function_exists('posix_geteuid') and posix_geteuid() === 0 and ($nobody = posix_getpwnam('nobody'))) {
	$planted = $base . '/planted';
	mkdir($planted, 0700);
	file_put_contents($planted . '/aliases.json', '{"evil":"x"}');
	chown($planted, $nobody['uid']);
	$m = Q_WebServer_Shell::moveLegacyData($new, $planted);
	check('another user\'s directory is refused', array($m['moved'], $m['why'] !== null), array(false, true));
	check('nothing of it was read', isset(json_decode(file_get_contents($new . '/aliases.json'), true)['evil']), false);
}

// ── Clean up ────────────────────────────────────────────────────────
exec('rm -rf ' . escapeshellarg($base));
printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
