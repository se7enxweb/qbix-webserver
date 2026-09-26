#!/usr/bin/env php
<?php
/**
 * Qbix app installation info reporter for the Q panel.
 *
 * Run from a Qbix app root, or from anywhere when this script is installed
 * under vendor/se7enxweb/exponential-velocity/bin (or the older vendor/se7enxweb/qbix-webserver/bin) (it walks up to the Composer
 * project root).
 *
 * Prints the Qbix app name and a readable list of config, plugins, composer
 * package, node package, handlers, classes and scripts.
 *
 * @package Q
 */

$rootDir = null;
if (is_file('config/app.json') or is_file('web/Q.php')) {
    $rootDir = getcwd();
} elseif (is_file(__DIR__ . '/../../../../config/app.json') or is_file(__DIR__ . '/../../../../web/Q.php')) {
    $rootDir = dirname(__DIR__, 4);
}
if (!$rootDir) {
    fwrite(STDERR, "Run this script from a Qbix app root (where config/app.json or web/Q.php lives).\n");
    exit(1);
}
chdir($rootDir);

function qbiReadJson($file)
{
    if (!is_file($file)) return null;
    $json = (string) @file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

$app = qbiReadJson('config/app.json') ?: array();
$local = qbiReadJson('local/app.json') ?: array();
$composer = qbiReadJson('composer.json') ?: array();
$package = qbiReadJson('package.json') ?: null;

$q = isset($app['Q']) ? $app['Q'] : array();
if (isset($local['Q']) && is_array($local['Q'])) {
    $q = array_merge($q, $local['Q']);
}

$appName = isset($q['app']) && is_string($q['app']) && $q['app'] !== '' ? $q['app'] : basename($rootDir);
echo "App: $appName\n";

if (is_string($composer['name'] ?? null) && $composer['name'] !== '') {
    echo 'Package: ' . $composer['name'] . (is_string($composer['version'] ?? null) && $composer['version'] !== '' ? ' ' . $composer['version'] : '') . "\n";
}
if (is_string($composer['description'] ?? null) && $composer['description'] !== '') {
    echo "Description: " . $composer['description'] . "\n";
}
if (is_string($q['web']['appRootUrl'] ?? null) && $q['web']['appRootUrl'] !== '') {
    echo "URL: " . $q['web']['appRootUrl'] . "\n";
}

$plugins = isset($q['plugins']) && is_array($q['plugins']) ? $q['plugins'] : array();
$pluginDirs = is_dir('plugins') ? array_values(array_filter(scandir('plugins'), function ($n) { return $n[0] !== '.'; })) : array();
$allPlugins = array_unique(array_merge($plugins, $pluginDirs));
if ($allPlugins) {
    echo "Plugins: " . implode(', ', $allPlugins) . "\n";
}

if ($package) {
    $nodeName = is_string($package['name'] ?? null) ? $package['name'] : '';
    $nodeVer = is_string($package['version'] ?? null) ? $package['version'] : '';
    if ($nodeName !== '') {
        echo "Node package: $nodeName" . ($nodeVer !== '' ? ' ' . $nodeVer : '') . "\n";
    }
}

echo "Web: " . (is_dir('web') ? 'yes' : 'no') . "\n";
echo "Handlers: " . (is_dir('handlers') ? 'yes' : 'no') . "\n";
echo "Classes: " . (is_dir('classes') ? 'yes' : 'no') . "\n";
echo "Scripts: " . (is_dir('scripts') ? 'yes' : 'no') . "\n";

if (isset($q['webserver']) && is_array($q['webserver'])) {
    echo "\nWeb server settings:\n";
    foreach ($q['webserver'] as $k => $v) {
        // Never print a credential the panel's viewer could copy.
        if (preg_match('/pass|secret|token|key|salt/i', (string) $k)) { echo "  $k: (hidden)\n"; continue; }
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        elseif (is_array($v)) $v = json_encode($v);
        echo "  $k: $v\n";
    }
}
