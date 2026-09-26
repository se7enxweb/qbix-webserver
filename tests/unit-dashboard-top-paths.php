<?php

/**
 * The "Top paths" and "System RAM" cards read at a glance.
 *
 * Top paths wrote the path, the request count and the average time into three
 * bare adjacent spans, so the card read
 *
 *   GET /643636.2ms
 *
 * for 64 requests averaging 3636.2 ms. Each row now carries the count and the
 * average as separate labelled values ("64 req", "3.64 s avg"), the path in
 * its own truncating element, escaped.
 *
 * System RAM is coloured by severity: the percentage green under 70%, amber
 * from 70%, red from 90%. The swap part reads "swap used / total" and is
 * coloured by what hurts, not by how much is parked: dim while swap is idle
 * (cold pages cost nothing), amber from 1 MB/s read back or 90% full, red
 * from 10 MB/s read back. Its tooltip says which, in plain words.
 *
 * Both cards are rendered twice: by PHP for the first paint, and by the page
 * script on every live refresh. This runs the PHP helpers directly, then runs
 * the script's copies under node (when available) on the same inputs and
 * requires identical markup, so the two cannot drift apart.
 *
 *   php tests/unit-dashboard-top-paths.php
 */

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

$src = __DIR__ . '/../src/Q/WebServer/Dashboard.php';
require_once $src;
$dash = file_get_contents($src);
// Its page is a design on disk now (designs/default/dashboard).
foreach (array('page.html', 'style.css', 'script.js') as $__f) $dash .= "\n" . file_get_contents(__DIR__ . '/../designs/default/dashboard/' . $__f);
$D = 'Q_WebServer_Dashboard';

// ── Time formatting ─────────────────────────────────────────────

check('3636.2 ms reads as seconds, two decimals', $D::fmtMs(3636.2), '3.64 s');
check('593.7 ms stays in ms, one decimal', $D::fmtMs(593.7), '593.7 ms');
check('10.6 ms', $D::fmtMs(10.6), '10.6 ms');
check('a whole number has no ".0"', $D::fmtMs(12), '12 ms');
check('1000 ms is a second', $D::fmtMs(1000), '1.00 s');
check('999.96 rounds up into seconds, not "1000 ms"', $D::fmtMs(999.96), '1.00 s');

// ── Top paths row ───────────────────────────────────────────────

$rows = array(
	array('path' => 'GET /', 'count' => 64, 'avgMs' => 3636.2),
	array('path' => 'GET /dse/dashboard', 'count' => 1, 'avgMs' => 593.7),
	array('path' => 'GET /sw.js', 'count' => 1, 'avgMs' => 10.6),
	array('path' => 'GET /<script>alert("x")</script>&y', 'count' => 3, 'avgMs' => 0.4),
);

$row = $D::topPathRow($rows[0]);
check('the row separates path, count and average into labelled elements', $row,
	'<div class="tp"><span class="p" title="GET /">GET /</span>'
	. '<span class="c">64 req</span><span class="a">3.64 s avg</span></div>');
check('593.7 ms row', strpos($D::topPathRow($rows[1]), '<span class="a">593.7 ms avg</span>') !== false, true);
check('10.6 ms row', strpos($D::topPathRow($rows[2]), '<span class="a">10.6 ms avg</span>') !== false, true);

$evil = $D::topPathRow($rows[3]);
check('the path is escaped: no raw tag', strpos($evil, '<script>'), false);
check('...escaped in text and title', substr_count($evil,
	'GET /&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;y'), 2);

check('the first paint fills the card server-side',
	strpos($dash, '<div class="pb" id="paths">{{topPathsHtml}}</div>') !== false, true);
check('the live refresh uses the same row builder',
	strpos($dash, 's.topPaths.map(tpRow)') !== false, true);
check('the script escapes the path before writing it',
	strpos($dash, 'function tpRow(p){var h=esc(String(p.path))') !== false, true);
check('long paths truncate with an ellipsis and may shrink',
	(bool) preg_match('/\.tp \.p\{[^}]*min-width:0[^}]*text-overflow:ellipsis[^}]*white-space:nowrap/', $dash), true);
check('count and average never wrap or shrink',
	preg_match_all('/\.tp \.[ca]\{[^}]*white-space:nowrap;flex-shrink:0/', $dash), 2);
check('the row has a gap between its values',
	(bool) preg_match('/\.tp\{[^}]*gap:\d+px/', $dash), true);

// ── RAM severity ────────────────────────────────────────────────

check('69.9% is ok (green)', $D::ramLevel(69.9), 'ok');
check('70% is warn (amber)', $D::ramLevel(70), 'warn');
check('89.9% is warn', $D::ramLevel(89.9), 'warn');
check('90% is crit (red)', $D::ramLevel(90), 'crit');
check('swap 0 is neutral', $D::swapLevel(0, 8192), 'none');
check('swap in use but idle is neutral, not a warning', $D::swapLevel(4813, 16384, 0), 'none');
check('swap in use, no rate yet, not nearly full, is neutral', $D::swapLevel(4813, 16384), 'none');
check('swap in use, total unknown, is neutral', $D::swapLevel(10, 0), 'none');
check('swap above half the total alone is not red', $D::swapLevel(5000, 8192, 0), 'none');
check('swap 90% full is amber', $D::swapLevel(7400, 8192, 0), 'warn');
check('1 MB/s read back is amber', $D::swapLevel(4813, 16384, 1024), 'warn');
check('10 MB/s read back is red', $D::swapLevel(4813, 16384, 10240), 'crit');
check('a trickle read back stays neutral', $D::swapLevel(4813, 16384, 300), 'none');
check('severity classes use only the existing palette',
	strpos($dash, '.sev-ok{color:var(--grn)}.sev-warn{color:var(--yel)}.sev-crit{color:var(--red)}.sev-none{color:var(--dim)}') !== false, true);

$ram = array('totalMb' => 47923, 'usedMb' => 18944, 'percent' => 40,
	'swapTotalMb' => 16384, 'swapUsedMb' => 4813, 'swapInKBps' => 300);
check('the percentage takes its colour', $D::ramPercentHtml($ram), '<span class="sev-ok">40%</span>');
check('swap reads used / total, neutral while idle, with a tooltip', $D::ramDetailHtml($ram),
	'18.5 / 46.8 GB &#183; <span class="sev-none" title="Memory the kernel moved out earlier and has not needed back.'
	. ' It costs nothing while it stays there; it only slows the server when pages are read back in (now 300 KB/s).">swap 4.7 GB / 16 GB</span>');
check('while pages come back, the rate is shown and the tooltip says why',
	$D::ramDetailHtml(array('totalMb' => 47923, 'usedMb' => 18944, 'percent' => 40,
		'swapTotalMb' => 16384, 'swapUsedMb' => 4813, 'swapInKBps' => 12800)),
	'18.5 / 46.8 GB &#183; <span class="sev-crit" title="Pages are being read back from swap at 12.5 MB/s:'
	. ' the server is short of memory and slows down while it waits on disk.">swap 4.7 GB / 16 GB, 12.5 MB/s in</span>');
check('no swap figures, no swap part',
	$D::ramDetailHtml(array('totalMb' => 16384, 'usedMb' => 8192, 'percent' => 50)), '8 / 16 GB');
check('the first paint fills the RAM card server-side',
	strpos($dash, '<div class="v" id="sysram">{{sysramHtml}}</div><div class="s" id="sysram-detail">{{sysramDetailHtml}}</div>') !== false, true);

$rams = array(
	$ram,
	array('totalMb' => 16384, 'usedMb' => 11469, 'percent' => 70, 'swapTotalMb' => 2048, 'swapUsedMb' => 0),
	array('totalMb' => 16384, 'usedMb' => 15000, 'percent' => 92, 'swapTotalMb' => 2048, 'swapUsedMb' => 1500),
	array('totalMb' => 16384, 'usedMb' => 8000, 'percent' => 49, 'swapTotalMb' => 2048, 'swapUsedMb' => 300),
	array('totalMb' => 16384, 'usedMb' => 8000, 'percent' => 49, 'swapTotalMb' => 2048, 'swapUsedMb' => 1900, 'swapInKBps' => 0),
	array('totalMb' => 16384, 'usedMb' => 15000, 'percent' => 92, 'swapTotalMb' => 2048, 'swapUsedMb' => 1500, 'swapInKBps' => 2048),
	array('totalMb' => 16384, 'usedMb' => 15000, 'percent' => 92, 'swapUsedMb' => 700, 'swapInKBps' => 20480),
	array('totalMb' => 16384, 'usedMb' => 8192, 'percent' => 50),
);

// ── The script's copies agree, run under node ───────────────────

function jsFunction($dash, $name)
{
	// From "function name(" to the brace that closes its body. None of the
	// helpers has a brace inside a string, so counting is enough.
	if (!preg_match('/^function ' . $name . '\(/m', $dash, $m, PREG_OFFSET_CAPTURE)) return null;
	$start = $m[0][1];
	$open = strpos($dash, '{', $start);
	for ($i = $open, $depth = 0, $n = strlen($dash); $i < $n; ++$i) {
		if ($dash[$i] === '{') ++$depth;
		elseif ($dash[$i] === '}' and --$depth === 0) return substr($dash, $start, $i - $start + 1);
	}
	return null;
}

function findNode()
{
	foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
		if ($dir !== '' and is_executable($dir . '/node')) return $dir . '/node';
	}
	return null;
}

$node = findNode();
if (!$node or !function_exists('proc_open')) {
	printf("  skip  node not found; script copies checked by source only\n");
} else {
	$names = array('esc', 'fmtMs', 'tpRow', 'ramLevel', 'swapLevel', 'n1', 'fmtRate', 'fmtSwapMb', 'swapTitle', 'ramPct', 'ramDetail');
	$js = '';
	foreach ($names as $n) {
		$f = jsFunction($dash, $n);
		check("the script defines $n()", $f !== null, true);
		$js .= $f . "\n";
	}
	$cases = array(
		'ms' => array(3636.2, 593.7, 10.6, 12, 1000, 999.96),
		'rows' => $rows,
		'pct' => array(69.9, 70, 89.9, 90),
		'swap' => array(array(0, 8192), array(4813, 16384, 0), array(10, 0), array(5000, 8192, 0),
			array(7400, 8192, 0), array(4813, 16384, 1024), array(4813, 16384, 10240), array(4813, 16384, null)),
		'rams' => $rams,
	);
	$js .= 'var C=' . json_encode($cases) . ";\n"
		. 'process.stdout.write(JSON.stringify({'
		. 'ms:C.ms.map(fmtMs),rows:C.rows.map(tpRow),pct:C.pct.map(ramLevel),'
		. 'swap:C.swap.map(function(a){return swapLevel(a[0],a[1],a[2])}),'
		. 'ramPct:C.rams.map(ramPct),ramDetail:C.rams.map(ramDetail)}));';

	$proc = proc_open(array($node), array(0 => array('pipe', 'r'),
		1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	fwrite($pipes[0], $js); fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
	$got = json_decode($out, true);
	check('node ran the script helpers', is_array($got), true);
	if (!is_array($got)) {
		printf("        %s\n", trim($err));
	} else {
		check('script fmtMs matches PHP', $got['ms'], array_map(array($D, 'fmtMs'), $cases['ms']));
		check('script fmtMs: 3636.2 -> 3.64 s', $got['ms'][0], '3.64 s');
		check('script fmtMs: 593.7 -> 593.7 ms', $got['ms'][1], '593.7 ms');
		check('script fmtMs: 10.6 -> 10.6 ms', $got['ms'][2], '10.6 ms');
		check('script tpRow matches PHP, escaping included',
			$got['rows'], array_map(array($D, 'topPathRow'), $rows));
		check('script ramLevel matches PHP', $got['pct'], array('ok', 'warn', 'warn', 'crit'));
		check('script swapLevel matches PHP', $got['swap'], array_map(function ($a) use ($D) {
			return $D::swapLevel($a[0], $a[1], $a[2] ?? null); }, $cases['swap']));
		check('script swapLevel: idle, full, 1 MB/s, 10 MB/s', $got['swap'],
			array('none', 'none', 'none', 'none', 'warn', 'warn', 'crit', 'none'));
		check('script ramPct matches PHP', $got['ramPct'], array_map(array($D, 'ramPercentHtml'), $rams));
		check('script ramDetail matches PHP', $got['ramDetail'], array_map(array($D, 'ramDetailHtml'), $rams));
	}
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
