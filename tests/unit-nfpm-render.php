#!/usr/bin/env php
<?php
/**
 * packaging/nfpm/render.php: the deb and rpm package is exponential-velocity,
 * installs under its own name everywhere, takes the place of its former name
 * qbix-webserver (deb Replaces/Breaks/Provides, rpm Obsoletes/Provides via
 * nfpm's replaces), and carries the scripts that move the old package's
 * settings, state and service across -- posttrans on rpm, where the obsoleted
 * package is removed after %post.
 *
 *   php tests/unit-nfpm-render.php
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
$root = dirname(__DIR__);
$render = function ($distro) use ($root) {
	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/packaging/nfpm/render.php") . ' '
		. escapeshellarg($distro) . ' 0.0.4.99 2>/dev/null', $out, $rc);
	return $rc === 0 ? json_decode(implode("\n", $out), true) : null;
};

foreach (array('debian-12', 'ubuntu-24.04', 'el-9', 'el-10') as $distro) {
	$s = $render($distro);
	check("$distro renders", is_array($s), true);
	if (!is_array($s)) continue;
	$deb = strncmp($distro, 'el-', 3) !== 0;
	check("$distro is named exponential-velocity", $s['name'], 'exponential-velocity');
	check("$distro replaces the former name", $s['replaces'] ?? null, array('qbix-webserver'));
	check("$distro provides the former name", $s['provides'] ?? null, array('qbix-webserver'));
	check("$distro runs the takeover scripts", array($s['scripts']['preinstall'] ?? null, $s['scripts']['postinstall'] ?? null),
		array('packaging/nfpm/preinstall.sh', 'packaging/nfpm/postinstall.sh'));
	if ($deb) {
		check("$distro breaks the former package (so dpkg may take its files)", $s['deb']['breaks'] ?? null, array('qbix-webserver'));
	} else {
		check("$distro finishes the takeover in posttrans", $s['rpm']['scripts']['posttrans'] ?? null, 'packaging/nfpm/posttrans.sh');
	}
	$dst = array_column($s['contents'], 'dst');
	$unit = array_values(array_filter($dst, function ($d) { return substr($d, -8) === '.service'; }));
	check("$distro ships the unit under the new name", count($unit) === 1 && basename($unit[0]) === 'exponential-velocity.service', true);
	check("$distro ships its settings under the new name", in_array('/etc/default/exponential-velocity', $dst, true), true);
	check("$distro installs the phar under /usr/share/exponential-velocity", in_array('/usr/share/exponential-velocity/bin/qbixserver.phar', $dst, true), true);
	check("$distro ships nothing under the former name", count(array_filter($dst, function ($d) { return strpos($d, 'qbix-webserver') !== false; })), 0);
	foreach ($s['contents'] as $c) {
		if (isset($c['src']) && !is_file("$root/{$c['src']}")) { check("$distro source exists: {$c['src']}", false, true); break; }
	}
}

// The scripts it names exist and are shell scripts sh can parse.
foreach (array('preinstall', 'postinstall', 'posttrans', 'preremove') as $n) {
	$f = "$root/packaging/nfpm/$n.sh";
	check("$n.sh exists", is_file($f), true);
	exec('sh -n ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
	check("$n.sh parses", $rc, 0);
}
// An upgrade of the package itself must not stop and disable its service.
$pre = file_get_contents("$root/packaging/nfpm/preremove.sh");
check('preremove stops the service only when removing', (bool) preg_match('/remove\|purge\|0\)/', $pre), true);

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
