#!/usr/bin/env php
<?php

/**
 * The shell's history at scale (well past ten thousand entries).
 *
 * Asserted:
 *   - 150 000 entries: the count, the newest page, older pages, single
 *     entries, and a search back to the oldest, newest first and without
 *     repeats, with the time each takes (the newest page under 50 ms);
 *   - an add stays cheap at that size, and writes zsh's extended format;
 *   - a line starting with a space, an empty line and a repeat of the last
 *     line are not recorded; newlines are folded;
 *   - Q.shell.historySize: cut back to the limit once it is a tenth past
 *     it, keeping the newest; 0 keeps everything;
 *   - an older file (one command per line) reads as it is and is rewritten
 *     with times on the next add, once;
 *   - several processes adding at once lose no line and tear none;
 *   - a runner handed only the newest page asks the server for the rest:
 *     history pages, !n, !prefix, history | grep over all of it;
 *   - the history builtin: 20 lines on the terminal, N, -a, all into a pipe;
 *   - a runner's question to the server keeps the visitor's typing that
 *     arrives meanwhile.
 *
 *   php tests/unit-shell-history.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
function ms($t) { return round((microtime(true) - $t) * 1000, 1); }
function cmds(array $entries) { return array_map(function ($e) { return $e['c']; }, $entries); }
function nums(array $entries) { return array_map(function ($e) { return $e['n']; }, $entries); }

$base = sys_get_temp_dir() . DS . 'qsh-history-' . getmypid();
@mkdir($base, 0700, true);
function fresh($name)
{
	global $base;
	$d = $base . DS . $name;
	@mkdir($d, 0700, true);
	@unlink($d . '/history');
	return $d;
}
Q_Config::set('Q', 'shell', 'historySize', 200000);

// ── 150 000 entries ──────────────────────────────────────────────────────
$N = 150000;
$dir = fresh('big');
$fh = fopen($dir . '/history', 'wb');
$t0 = 1700000000;
fwrite($fh, ': ' . ($t0 + 1) . ":0;first-ever command\n");
for ($i = 2; $i <= $N; $i++) fwrite($fh, ': ' . ($t0 + $i) . ':0;cmd ' . $i . ' ' . ($i % 7 === 0 ? 'seven' : 'plain') . "\n");
fclose($fh);
$h = new Q_WebServer_Shell_History($dir);

$t = microtime(true); $count = $h->count(); $coldCount = ms($t);
check('150 000 entries counted', $count, $N);
$t = microtime(true); $page = $h->page(); $warmPage = ms($t);
check('the newest page: 1000 entries, oldest first', array(count($page), $page[0]['n'], $page[999]['n'], $page[999]['c']), array(1000, $N - 999, $N, 'cmd ' . $N . ' plain'));
check('entries carry their time', $page[999]['t'], $t0 + $N);
check('the newest page reads in under 50 ms', $warmPage < 50, true);
$older = $h->page(1000, $N - 999);
check('the page before it', array($older[0]['n'], $older[999]['n']), array($N - 1999, $N - 1000));
$t = microtime(true); $deep = $h->page(10, 11); $deepMs = ms($t);
check('the oldest page', nums($deep), range(1, 10));
check('the first entry, the last, and none past it', array($h->get(1)['c'], $h->get($N)['c'], $h->get($N + 1), $h->get(0)), array('first-ever command', 'cmd ' . $N . ' plain', null, null));
$t = microtime(true); $hit = $h->search('cmd 3 '); $searchMs = ms($t);
check('a search reaches back to the oldest entries', $hit ? $hit[0]['n'] : null, 3);
check('search: newest first, limited', nums($h->search('seven', null, 3)), array(149996, 149989, 149982));
check('search before a number', nums($h->search('seven', 149989, 2)), array(149982, 149975));
check('search by prefix', nums($h->search('cmd 14999', null, 2, true)), array(149999, 149998));
check('a search finding nothing', $h->search('no such command'), array());
check('the whole history reads in order', array_slice($h->lines(), 0, 2), array('first-ever command', 'cmd 2 plain'));

$t = microtime(true);
for ($i = 1; $i <= 200; $i++) $h->add('added ' . $i);
$addMs = ms($t) / 200;
check('adds land at the end', array($h->count(), $h->get($N + 200)['c']), array($N + 200, 'added 200'));
check('an add at this size takes under 20 ms', $addMs < 20, true);
$raw = file($dir . '/history', FILE_IGNORE_NEW_LINES);
check('adds are written as zsh extended history', (bool) preg_match('/^: \d+:0;added 200$/', end($raw)), true);
printf("  info  150k entries: count %.1f ms cold, newest page %.1f ms, oldest page %.1f ms, search to the oldest %.1f ms, add %.2f ms\n",
	$coldCount, $warmPage, $deepMs, $searchMs, $addMs);

// ── Behind a file wrapper that hands out short reads ─────────────────────
// The server serves files through its own file:// wrapper, whose fread()
// may return fewer bytes than asked for (8 KiB here).
class ShortReadFile
{
	public $context;
	private $fh;
	private static function real(callable $fn)
	{
		stream_wrapper_restore('file');
		try { return $fn(); } finally { stream_wrapper_unregister('file'); stream_wrapper_register('file', __CLASS__); }
	}
	function stream_open($path, $mode) { $this->fh = self::real(function () use ($path, $mode) { return @fopen($path, $mode); }); return (bool) $this->fh; }
	function stream_read($n) { return fread($this->fh, min($n, 8192)); }
	function stream_write($d) { return fwrite($this->fh, $d); }
	function stream_seek($o, $w) { return fseek($this->fh, $o, $w) === 0; }
	function stream_tell() { return ftell($this->fh); }
	function stream_eof() { return feof($this->fh); }
	function stream_stat() { return fstat($this->fh); }
	function stream_lock($op) { return flock($this->fh, $op); }
	function stream_close() { fclose($this->fh); }
	function url_stat($path, $flags) { return self::real(function () use ($path) { return @stat($path); }); }
}
$h = new Q_WebServer_Shell_History($dir);
$wantPage = $h->page(1000, 140000);
$wantSearch = $h->search('seven', 120000, 5);
stream_wrapper_unregister('file');
stream_wrapper_register('file', 'ShortReadFile');
$h2 = new Q_WebServer_Shell_History($dir);
$gotPage = $h2->page(1000, 140000);
$gotSearch = $h2->search('seven', 120000, 5);
$gotCount = $h2->count();
stream_wrapper_restore('file');
check('short reads: the same page', $gotPage, $wantPage);
check('short reads: the same search', $gotSearch, $wantSearch);
check('short reads: the same count', $gotCount, $h->count());

// ── What is recorded ─────────────────────────────────────────────────────
$h = new Q_WebServer_Shell_History(fresh('rules'));
$h->add('one'); $h->add(' secret'); $h->add(''); $h->add('one'); $h->add("two\nlines"); $h->add('one');
check('space-led, empty and repeated lines are left out; newlines folded', $h->lines(), array('one', 'two lines', 'one'));

// ── The limit ────────────────────────────────────────────────────────────
Q_Config::set('Q', 'shell', 'historySize', 1000);
$h = new Q_WebServer_Shell_History(fresh('limit'));
for ($i = 1; $i <= 1100; $i++) $h->add('c' . $i);
check('the file may grow a tenth past the limit', $h->count(), 1100);
$h->add('c1101');
check('then it is cut back to the newest entries', array($h->count(), $h->get(1)['c'], $h->get(1000)['c']), array(1000, 'c102', 'c1101'));
check('and it keeps its mode', substr(sprintf('%o', fileperms($base . '/limit/history')), -3), '600');
Q_Config::set('Q', 'shell', 'historySize', 0);
check('0 keeps everything', Q_WebServer_Shell_History::limit(), 0);
$h = new Q_WebServer_Shell_History(fresh('unlimited'));
for ($i = 1; $i <= 1200; $i++) $h->add('u' . $i);
check('nothing is cut with no limit', $h->count(), 1200);
Q_Config::set('Q', 'shell', 'historySize', 200000);

// ── The older format ─────────────────────────────────────────────────────
$d = fresh('old');
file_put_contents($d . '/history', "server status\nlogs tail\nworkers list\n");
$h = new Q_WebServer_Shell_History($d);
check('an older file reads as it is', array($h->lines(), $h->get(1)['t']), array(array('server status', 'logs tail', 'workers list'), 0));
$h->add('ssl show');
$raw = file($d . '/history', FILE_IGNORE_NEW_LINES);
check('the next add gives every line a time', count(preg_grep('/^: \d+:0;/', $raw)), 4);
check('nothing lost, nothing reordered', $h->lines(), array('server status', 'logs tail', 'workers list', 'ssl show'));
check('once: a second migration changes nothing', $h->migrate(), false);

// ── Several processes at once ────────────────────────────────────────────
if (function_exists('pcntl_fork')) {
	$d = fresh('race');
	$kids = array();
	for ($k = 0; $k < 6; $k++) {
		$pid = pcntl_fork();
		if ($pid === 0) {
			$mine = new Q_WebServer_Shell_History($d);
			for ($i = 0; $i < 300; $i++) $mine->add("p$k-$i");
			exit(0);
		}
		$kids[] = $pid;
	}
	foreach ($kids as $pid) pcntl_waitpid($pid, $st);
	$h = new Q_WebServer_Shell_History($d);
	$raw = file($d . '/history', FILE_IGNORE_NEW_LINES);
	check('six processes adding 300 each lose nothing', $h->count(), 1800);
	check('and tear no line', count(preg_grep('/^: \d+:0;p\d-\d+$/', $raw)), 1800);
} else {
	echo "  skip  concurrency (needs pcntl)\n";
}

// ── A runner, handed the newest page ─────────────────────────────────────
$server = new Q_WebServer_Shell_History($base . '/big');
$asked = 0;
$ask = function ($m) use ($server, &$asked) {
	$asked++;
	if ($m['op'] === 'search') return array('entries' => $server->search($m['q'], $m['before'], $m['limit'], $m['prefix']));
	return array('entries' => $server->page($m['limit'], $m['before']));
};
$sent = array();
$remote = Q_WebServer_Shell_History::remote($server->page(), array(), array(), function ($m) use (&$sent) { $sent[] = $m; }, $server->count(), $ask);
$total = $server->count();
check('a runner knows the full count', $remote->count(), $total);
check('the newest page is served without asking', array(nums($remote->page(10))[9], $asked), array($total, 0));
check('an older page is asked for', array(nums($remote->page(5, 6)), $asked), array(range(1, 5), 1));
check('!5 reaches past the page handed over', $remote->expand('echo !5'), 'echo cmd 5 plain');
check('!prefix finds the oldest line', $remote->expand('!first-ever'), 'first-ever command');
check('!! and !-2 need no question', array($remote->expand('!!'), $remote->expand('!-2')), array('added 200', 'added 199'));
$remote->add('new line');
check('an add is sent to the server and counted', array(end($sent), $remote->count()), array(array('t' => 'hist', 'd' => 'new line'), $total + 1));

$io = new Q_WebServer_Shell_BufferIo();
$sh = new Q_WebServer_Shell_Interpreter($io, new Q_WebServer_Shell_Registry(array(), array()), $remote, array('tier' => 'advanced'));
$sh->runLine('history | grep -c seven', false);
check('history | grep covers the whole store', trim($io->out), (string) intdiv($N, 7));

// ── The builtin ──────────────────────────────────────────────────────────
$h = new Q_WebServer_Shell_History(fresh('builtin'));
for ($i = 1; $i <= 50; $i++) $h->add('b' . $i);
$run = function ($line) use ($h) {
	$io = new Q_WebServer_Shell_BufferIo();
	$sh = new Q_WebServer_Shell_Interpreter($io, new Q_WebServer_Shell_Registry(array(), array()), $h, array('tier' => 'advanced'));
	$sh->runLine($line, false);
	return $io->out;
};
$out = $run('history');
check('history: the last 20, numbered', array(substr_count($out, "\n"), strpos($out, '   31  b31') === 0), array(20, true));
check('history 5', substr_count($run('history 5'), "\n"), 5);
check('history -a', substr_count($run('history -a'), "\n"), 50);
check('into a pipe: all of it', trim($run('history | wc -l')), '50');

// ── A question to the server keeps the typing that arrives meanwhile ────
$in = fopen('php://memory', 'w+');
fwrite($in, json_encode(array('t' => 'stdin', 'd' => 'yes')) . "\n" . json_encode(array('t' => 'hist_reply', 'entries' => array())) . "\n");
rewind($in);
$out = fopen('php://memory', 'w+');
$jio = new Q_WebServer_Shell_JsonIo($in, $out, true);
check('the answer is found past other messages', $jio->request(array('t' => 'hist_query'), 'hist_reply')['t'] ?? null, 'hist_reply');
check('and the typing is still there for the prompt', $jio->prompt('Proceed? '), 'yes');

// ── Clean up ─────────────────────────────────────────────────────────────
foreach (glob($base . '/*/*') as $f) @unlink($f);
foreach (glob($base . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
@rmdir($base);

echo $fail ? "\n  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
