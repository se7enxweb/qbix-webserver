#!/usr/bin/env php
<?php
/**
 * Writes the nfpm spec for one distribution's package.
 *
 *   php packaging/nfpm/render.php <distro> <version> > nfpm.yaml
 *   php packaging/nfpm/render.php --list            # the distributions, one per line
 *
 * The package installs the server under /usr/share/qbix-webserver (the phar,
 * the console tools, the baseline, the designs and docs), qbixserver /
 * qbixctl / qbixconsole in /usr/bin, a systemd unit, and the /etc/qbix tree
 * (docs/layout.md) as configuration that upgrades never overwrite.
 *
 * Its dependencies are the baseline's, in each distribution's own package
 * names: the lite variant (everything the platform requires) as hard
 * dependencies, the rest of standard as recommendations -- installed by
 * default on Debian and Ubuntu, weak on EL. The names come from the engine's
 * own install hints (Q_WebServer_Extensions::installHints), for the PHP the
 * distribution ships, so no list is kept here.
 *
 * The spec is JSON, which nfpm reads as the YAML it is a subset of.
 */
require dirname(__DIR__) . '/ci/baseline.php';

// distro => [package format, package manager, the PHP it ships, file suffix, systemd unit dir, note]
$distros = array(
	'debian-12'    => array('deb', 'apt', '8.2', 'deb12', '/lib/systemd/system', ''),
	'debian-13'    => array('deb', 'apt', '8.4', 'deb13', '/usr/lib/systemd/system', ''),
	'ubuntu-22.04' => array('deb', 'apt', '8.1', 'ubuntu22.04', '/lib/systemd/system', ''),
	'ubuntu-24.04' => array('deb', 'apt', '8.3', 'ubuntu24.04', '/usr/lib/systemd/system', ''),
	// EL 9's default PHP is 8.0; `dnf module enable php:8.2` gives the 8.1+
	// the server needs (docs/packages.md).
	'el-9'         => array('rpm', 'dnf', '8.2', 'el9', '/usr/lib/systemd/system', 'dnf module enable -y php:8.2'),
	'el-10'        => array('rpm', 'dnf', '8.4', 'el10', '/usr/lib/systemd/system', ''),
);

if (($argv[1] ?? '') === '--list') {
	foreach ($distros as $d => $x) echo "$d {$x[0]} {$x[3]}\n";
	exit(0);
}
$distro = $argv[1] ?? '';
$version = ltrim((string) ($argv[2] ?? ''), 'v');
if (!isset($distros[$distro]) || $version === '') {
	fwrite(STDERR, "usage: render.php <distro> <version>   (distros: " . implode(', ', array_keys($distros)) . ")\n");
	exit(2);
}
list($format, $manager, $php, $suffix, $unitDir, $prepare) = $distros[$distro];

/** The package names an install hint command carries for a set of extensions. */
function packages_for(array $names, $manager, $php)
{
	if (!$names) return array();
	$h = Q_WebServer_Extensions::installHints($names, $manager, $php);
	$out = array();
	foreach ($h['commands'] as $c) {
		if (preg_match('/(?:apt-get|dnf) install -y (.+)$/', $c, $m)) {
			foreach (preg_split('/\s+/', trim($m[1])) as $p) $out[$p] = true;
		}
	}
	return array_keys($out);
}

$lite = baseline_resolve('lite', 'linux-x86_64', $php, false)['include'];
$standard = baseline_resolve('standard', 'linux-x86_64', $php, false)['include'];
$depends = packages_for($lite, $manager, $php);
$recommends = array_values(array_diff(packages_for(array_values(array_diff($standard, $lite)), $manager, $php), $depends));
// The interpreter itself, which the install hints take as given.
if ($format === 'deb') {
	array_unshift($depends, "php$php-cli");
} else {
	array_unshift($depends, 'php-cli >= 8.1');
}
$depends = array_values(array_unique($depends));

// What goes under /usr/share/qbix-webserver: the tracked files of these paths.
$root = dirname(__DIR__, 2);
$share = '/usr/share/qbix-webserver';
$contents = array();
exec('git -C ' . escapeshellarg($root) . ' ls-files -- bin/qbixserver.phar qbixserver.php qbixctl.php qbixconsole.php qshell.php src build designs docs web LICENSE', $files, $rc);
if ($rc !== 0 || !$files) {
	fwrite(STDERR, "git ls-files failed in $root\n");
	exit(1);
}
foreach ($files as $f) {
	$contents[] = array('src' => $f, 'dst' => "$share/$f", 'file_info' => array('mode' => is_executable("$root/$f") ? 0755 : 0644));
}
foreach (array('qbixserver', 'qbixctl', 'qbixconsole') as $t) {
	$contents[] = array('src' => "packaging/bin/$t", 'dst' => "/usr/bin/$t", 'file_info' => array('mode' => 0755));
}
$contents[] = array('src' => 'packaging/systemd/qbix-webserver.service', 'dst' => "$unitDir/qbix-webserver.service", 'file_info' => array('mode' => 0644));
$contents[] = array('src' => 'packaging/systemd/qbix-webserver.default', 'dst' => '/etc/default/qbix-webserver', 'type' => 'config|noreplace', 'file_info' => array('mode' => 0644));
foreach (array('qbix.conf', 'ports.conf', 'envvars', 'sites-available/default.conf') as $f) {
	$contents[] = array('src' => "packaging/etc/qbix/$f", 'dst' => "/etc/qbix/$f", 'type' => 'config|noreplace', 'file_info' => array('mode' => 0644));
}
foreach (array('conf-available', 'conf-enabled', 'mods-available', 'mods-enabled', 'sites-available', 'sites-enabled', 'designs', 'ssl') as $d) {
	$contents[] = array('dst' => "/etc/qbix/$d", 'type' => 'dir', 'file_info' => array('mode' => $d === 'ssl' ? 0750 : 0755));
}

$spec = array(
	'name' => 'qbix-webserver',
	'arch' => 'all',
	'platform' => 'linux',
	'version' => $version,
	'release' => '1',
	'section' => 'httpd',
	'priority' => 'optional',
	'maintainer' => 'se7enxweb <info@se7enx.com>',
	'vendor' => 'se7enxweb',
	'homepage' => 'https://github.com/se7enxweb/qbix-webserver',
	'license' => 'MIT',
	'description' => "A PHP application server: persistent and forking workers, HTTP/1.1, HTTP/2, TLS\n"
		. "and WebSockets, run by the distribution's own PHP. Its extensions are the\n"
		. "distribution's packages; `qbixctl ext:check` reports any the baseline lists\n"
		. "that are missing, with the command to install them.",
	'depends' => $depends,
	'recommends' => $recommends,
	'contents' => $contents,
	'scripts' => array(
		'postinstall' => 'packaging/nfpm/postinstall.sh',
		'preremove' => 'packaging/nfpm/preremove.sh',
	),
);
if ($format === 'rpm') {
	$spec['rpm'] = array('group' => 'System Environment/Daemons', 'summary' => 'A PHP application server');
}
fwrite(STDERR, sprintf("%s: %s, PHP %s, %d dependencies, %d recommended, %d files%s\n",
	$distro, $format, $php, count($depends), count($recommends), count($contents),
	$prepare !== '' ? ", needs: $prepare" : ''));
echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
