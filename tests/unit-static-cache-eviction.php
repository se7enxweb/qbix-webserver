<?php

/**
 * The in-memory static file cache keeps taking files once it is full, by
 * evicting the one asked for longest ago.
 *
 * The insert test used to be "fits in what is left". Once the cache was full
 * that refused every new file, so the eviction loop after it never ran: on a
 * site with more small assets than the limit, whichever files were asked for
 * first stayed in memory for good and every other file was read from disk on
 * every request.
 *
 * Whether a file came from memory is seen from outside by rewriting it with the
 * same length and putting its old mtime back: the cache revalidates by mtime,
 * so a copy in memory still answers with the old bytes, and a read from disk
 * answers with the new ones. Asserted against a running server whose cache
 * holds three files:
 *   - a fourth file is cached although the cache is full;
 *   - the file evicted to make room is the least recently used one;
 *   - a file used again just before is kept.
 *
 *   php tests/unit-static-cache-eviction.php
 */

require __DIR__ . '/fixtures/race-harness.php';

list($base, $root) = rh_setup('static-evict');

// 600-byte files; each costs 1200 in the cache's accounting (two response forms).
function write_file($root, $name, $letter)
{
	file_put_contents("$root/$name", str_repeat($letter, 600));
}
/** Rewrite with other bytes of the same length, keeping the mtime. */
function rewrite_same_mtime($root, $name, $letter)
{
	$m = filemtime("$root/$name");
	file_put_contents("$root/$name", str_repeat($letter, 600));
	touch("$root/$name", $m);
	clearstatcache(true, "$root/$name");
}
function first_byte($port, $name)
{
	$r = rh_get($port, "/$name");
	return substr($r['body'] ?? '', 0, 1);
}

$old = time() - 3600;
foreach (array('a', 'b', 'c', 'd') as $f) { write_file($root, "$f.txt", $f); touch("$root/$f.txt", $old); }

// Room for three files (3 x 1200 = 3600) and not four.
$port = rh_start('evict', array('Q' => array('webserver' => array(
	'fileCache' => array('maxSize' => 4000, 'maxFile' => 1048576)))), 1);

first_byte($port, 'a.txt');
first_byte($port, 'b.txt');
first_byte($port, 'c.txt');          // full now
first_byte($port, 'a.txt');          // a is now the most recently used; b the least
first_byte($port, 'd.txt');          // must be cached, evicting b

rewrite_same_mtime($root, 'a.txt', 'A');
rewrite_same_mtime($root, 'b.txt', 'B');
rewrite_same_mtime($root, 'd.txt', 'D');

check('a file asked for when the cache is full is still cached', first_byte($port, 'd.txt'), 'd');
check('the least recently used file is the one evicted (read from disk now)', first_byte($port, 'b.txt'), 'B');
check('a file used again just before is kept in memory', first_byte($port, 'a.txt'), 'a');

rh_finish();
