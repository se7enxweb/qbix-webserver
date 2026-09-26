<?php
/**
 * @module Q
 */
/**
 * The shell's per-user files: command history, aliases and the autoexec
 * script, all under <data dir>/shell/.
 *
 *   history        one entry per line, the newest last, as zsh's extended
 *                  history writes it: ": <unix time>:0;<command>". Lines
 *                  without the prefix (the older format) read as commands
 *                  with no time, and are rewritten with one on the next add.
 *   history.lock   taken while the history is written
 *   aliases.json   name => text
 *   autoexec.qsh   run when a shell session opens
 *
 * The history is only ever appended to; it is cut back to the newest
 * Q.shell.historySize entries (default MAX, 0 = keep everything) once it has
 * grown a tenth past that, so an add stays cheap however long it is. Entries
 * are numbered from 1, the oldest kept. Reading the newest entries seeks from
 * the end of the file, and searching streams it backwards, so neither reads
 * more than it must.
 *
 * With no directory (tests, a read-only disk) everything is kept in memory.
 *
 * @class Q_WebServer_Shell_History
 */
class Q_WebServer_Shell_History
{
	/** The default number of entries kept (Q.shell.historySize). */
	const MAX = 100000;

	/** How many of the newest entries a page holds when none is asked for. */
	const PAGE = 1000;

	/** The most entries one page may hold. */
	const PAGE_MAX = 5000;

	/** @var string|null */
	public $dir;

	/** @var array|null entries (n, c, t) kept in memory: no directory, or a runner's newest */
	private $mem = null;

	/** @var integer a runner's count of entries on the server */
	private $total = 0;

	private $aliases = null;

	/** @var callable|null in a runner: sends changes to the server, which keeps the files */
	private $remote = null;

	/** @var callable|null in a runner: asks the server for entries it was not handed */
	private $ask = null;

	/** @var array name => text of the shell directory's scripts, handed over by the server */
	private $files = array();

	/** @var array path => array(inode, size, lines): newline counts already made */
	private static $counted = array();

	/**
	 * History and aliases a runner got from the server. The runner may run as
	 * a user who cannot reach the shell directory, so it never touches the
	 * files: every change goes back to the server as a message, and entries
	 * older than the ones it was handed are asked for.
	 * @method remote
	 * @static
	 * @param {array} $entries the newest entries (n, c, t), or plain commands
	 * @param {array} $aliases
	 * @param {array} $files
	 * @param {callable} $send
	 * @param {integer} [$total] how many entries the server has
	 * @param {callable} [$ask] message => the server's answer (an array)
	 */
	static function remote(array $entries, array $aliases, array $files, callable $send, $total = null, $ask = null)
	{
		$h = new self(null);
		$list = array();
		foreach ($entries as $e) {
			if (is_string($e)) $list[] = array('n' => 0, 'c' => $e, 't' => 0);
			elseif (is_array($e) && isset($e['c']) && is_string($e['c'])) $list[] = array('n' => (int) ($e['n'] ?? 0), 'c' => $e['c'], 't' => (int) ($e['t'] ?? 0));
		}
		$h->total = max(count($list), (int) $total);
		// Plain commands (an older server) are the newest, numbered backwards.
		$n = $h->total - count($list);
		foreach ($list as $i => $e) $list[$i]['n'] = ++$n;
		$h->mem = $list;
		$h->aliases = array_filter($aliases, 'is_string');
		$h->files = array_filter($files, 'is_string');
		$h->remote = $send;
		$h->ask = is_callable($ask) ? $ask : null;
		return $h;
	}

	function __construct($dir = null)
	{
		$this->dir = ($dir !== null && $dir !== '') ? rtrim($dir, '/') : null;
		if ($this->dir !== null && !is_dir($this->dir)) @mkdir($this->dir, 0700, true);
		if ($this->dir !== null && !is_dir($this->dir)) $this->dir = null;
		if ($this->dir === null) $this->mem = array();
	}

	/**
	 * How many entries are kept: Q.shell.historySize, 0 for no limit.
	 * @method limit
	 * @static
	 * @return {integer}
	 */
	static function limit()
	{
		$n = class_exists('Q_WebServer_Shell') ? Q_WebServer_Shell::config('historySize') : self::MAX;
		if ($n === null || !is_numeric($n)) return self::MAX;
		return max(0, (int) $n);
	}

	/** How far past the limit the file may grow before it is cut back. */
	private static function slack($limit)
	{
		return max(100, intdiv($limit, 10));
	}

	private function file()
	{
		return $this->dir . '/history';
	}

	/** One line of the file as an entry. */
	private static function parse($raw, $n)
	{
		if (strncmp($raw, ': ', 2) === 0 && preg_match('/^: (\d+):\d+;(.*)$/s', $raw, $m)) {
			return array('n' => $n, 'c' => $m[2], 't' => (int) $m[1]);
		}
		return array('n' => $n, 'c' => $raw, 't' => 0);
	}

	private static function format($command, $time)
	{
		return ': ' . (int) $time . ':0;' . $command . "\n";
	}

	/**
	 * How many entries there are.
	 * @method count
	 * @return {integer}
	 */
	function count()
	{
		if ($this->remote) return $this->total;
		if ($this->dir === null) return count($this->mem);
		$fh = @fopen($this->file(), 'rb');
		if (!$fh) return 0;
		$n = $this->countIn($fh);
		fclose($fh);
		return $n;
	}

	/**
	 * The complete lines in an open history file, counted once: a later call
	 * counts only what was appended since.
	 */
	private function countIn($fh)
	{
		$st = fstat($fh);
		$size = (int) $st['size'];
		$path = $this->file();
		$c = self::$counted[$path] ?? null;
		$from = 0;
		$n = 0;
		if ($c && $c[0] === $st['ino'] && $c[1] <= $size) {
			if ($c[1] === $size) return $c[2];
			$from = $c[1];
			$n = $c[2];
		}
		fseek($fh, $from);
		$left = $size - $from;
		while ($left > 0) {
			$b = fread($fh, min(1048576, $left));
			if ($b === false || $b === '') break;
			$left -= strlen($b);
			$n += substr_count($b, "\n");
		}
		self::$counted[$path] = array($st['ino'], $size, $n);
		return $n;
	}

	/**
	 * The complete lines of an open file, the last first. A line still being
	 * written (no newline yet) is left out, as count() leaves it out.
	 */
	private static function backward($fh)
	{
		$st = fstat($fh);
		$pos = (int) $st['size'];
		if ($pos === 0) return;
		fseek($fh, $pos - 1);
		$partial = self::readExactly($fh, 1) !== "\n";
		if (!$partial) $pos--;
		$buf = '';
		while ($pos > 0) {
			$len = min(65536, $pos);
			$pos -= $len;
			fseek($fh, $pos);
			$buf = self::readExactly($fh, $len) . $buf;
			$parts = explode("\n", $buf);
			$buf = array_shift($parts);
			for ($i = count($parts) - 1; $i >= 0; $i--) {
				if ($partial) { $partial = false; continue; }
				yield $parts[$i];
			}
		}
		if (!$partial) yield $buf;
	}

	/**
	 * $len bytes from where $fh stands. A stream wrapper (the server's own
	 * file wrapper among them) may hand fread() fewer than asked for.
	 */
	private static function readExactly($fh, $len)
	{
		$out = '';
		while (strlen($out) < $len) {
			$b = fread($fh, $len - strlen($out));
			if ($b === false || $b === '') break;
			$out .= $b;
		}
		return $out;
	}

	/**
	 * Entries numbered from $before - $limit to $before - 1, oldest first.
	 * @method page
	 * @param {integer} [$limit=PAGE]
	 * @param {integer|null} [$before] null for the newest
	 * @return {array} entries: n (number), c (command), t (time, 0 = unknown)
	 */
	function page($limit = self::PAGE, $before = null)
	{
		$limit = max(0, (int) $limit);
		if ($this->mem !== null) {
			$total = $this->count();
			$before = $before === null ? $total + 1 : max(1, min((int) $before, $total + 1));
			$from = max(1, $before - $limit);
			$first = $this->mem ? $this->mem[0]['n'] : $total + 1;
			if ($from >= $first || !$this->ask) {
				$out = array();
				foreach ($this->mem as $e) if ($e['n'] >= $from && $e['n'] < $before) $out[] = $e;
				return $out;
			}
			$r = call_user_func($this->ask, array('t' => 'hist_query', 'op' => 'page', 'limit' => $limit, 'before' => $before));
			return self::entries($r);
		}
		$fh = @fopen($this->file(), 'rb');
		if (!$fh) return array();
		$total = $this->countIn($fh);
		$before = $before === null ? $total + 1 : max(1, min((int) $before, $total + 1));
		$from = max(1, $before - $limit);
		$out = array();
		if ($from < $before) {
			$n = $total + 1;
			foreach (self::backward($fh) as $raw) {
				if (--$n >= $before) continue;
				if ($n < $from) break;
				$out[] = self::parse($raw, $n);
			}
		}
		fclose($fh);
		return array_reverse($out);
	}

	/**
	 * The newest entries (before $before) whose command contains $q, or
	 * starts with it; a command already found is not listed again.
	 * @method search
	 * @param {string} $q
	 * @param {integer|null} [$before] null to search from the newest
	 * @param {integer} [$limit=1]
	 * @param {boolean} [$prefix=false]
	 * @return {array} entries, newest first
	 */
	function search($q, $before = null, $limit = 1, $prefix = false)
	{
		$q = (string) $q;
		$limit = max(1, (int) $limit);
		$before = $before === null ? PHP_INT_MAX : (int) $before;
		$match = function ($c) use ($q, $prefix) {
			return $q === '' || ($prefix ? strncmp($c, $q, strlen($q)) === 0 : strpos($c, $q) !== false);
		};
		$out = array();
		$seen = array();
		if ($this->mem !== null) {
			for ($i = count($this->mem) - 1; $i >= 0 && count($out) < $limit; $i--) {
				$e = $this->mem[$i];
				if ($e['n'] >= $before || isset($seen[$e['c']]) || !$match($e['c'])) continue;
				$seen[$e['c']] = true;
				$out[] = $e;
			}
			if (count($out) < $limit && $this->ask && $this->mem && $this->mem[0]['n'] > 1) {
				// The server has every entry, including the ones handed over.
				$r = call_user_func($this->ask, array('t' => 'hist_query', 'op' => 'search', 'q' => $q,
					'before' => $before === PHP_INT_MAX ? null : $before, 'limit' => $limit, 'prefix' => (bool) $prefix));
				return self::entries($r);
			}
			return $out;
		}
		$fh = @fopen($this->file(), 'rb');
		if (!$fh) return array();
		$n = $this->countIn($fh) + 1;
		foreach (self::backward($fh) as $raw) {
			if (--$n >= $before) continue;
			// Most lines do not hold the text at all: skip them unparsed.
			if ($q !== '' && strpos($raw, $q) === false) continue;
			$e = self::parse($raw, $n);
			if (isset($seen[$e['c']]) || !$match($e['c'])) continue;
			$seen[$e['c']] = true;
			$out[] = $e;
			if (count($out) >= $limit) break;
		}
		fclose($fh);
		return $out;
	}

	/** Entries from the server's answer, kept to their shape. */
	private static function entries($r)
	{
		$out = array();
		foreach ((array) ($r['entries'] ?? array()) as $e) {
			if (is_array($e) && isset($e['c']) && is_string($e['c'])) $out[] = array('n' => (int) ($e['n'] ?? 0), 'c' => $e['c'], 't' => (int) ($e['t'] ?? 0));
		}
		return $out;
	}

	/**
	 * Entry $n, or null.
	 * @method get
	 * @return {array|null}
	 */
	function get($n)
	{
		$n = (int) $n;
		if ($n < 1) return null;
		$p = $this->page(1, $n + 1);
		return ($p && $p[0]['n'] === $n) ? $p[0] : null;
	}

	/** Every kept command, oldest first. */
	function lines()
	{
		$out = array();
		foreach ($this->page(PHP_INT_MAX >> 1, null) as $e) $out[] = $e['c'];
		return $out;
	}

	/** Record a line (not a repeat of the one before, not one starting with a space). */
	function add($line)
	{
		$line = str_replace(array("\r", "\n"), array('', ' '), rtrim($line));
		if ($line === '' || $line[0] === ' ') return;
		if ($this->mem !== null) {
			$last = $this->mem ? end($this->mem) : null;
			if ($last && $last['c'] === $line) return;
			$this->mem[] = array('n' => $this->count() + 1, 'c' => $line, 't' => time());
			if ($this->remote) {
				$this->total++;
				if (count($this->mem) > self::PAGE_MAX) $this->mem = array_slice($this->mem, -self::PAGE);
				call_user_func($this->remote, array('t' => 'hist', 'd' => $line));
				return;
			}
			$limit = self::limit();
			if ($limit > 0 && count($this->mem) > $limit) {
				$this->mem = array_slice($this->mem, -$limit);
				foreach ($this->mem as $i => $e) $this->mem[$i]['n'] = $i + 1;
			}
			return;
		}
		$this->locked(function () use ($line) {
			$this->migrate();
			$last = $this->page(1);
			if ($last && $last[0]['c'] === $line) return;
			$file = $this->file();
			$um = umask(077);
			$fh = @fopen($file, 'ab');
			umask($um);
			if (!$fh) return;
			fwrite($fh, self::format($line, time()));
			fclose($fh);
			@chmod($file, 0600);
			$limit = self::limit();
			if ($limit > 0 && $this->count() > $limit + self::slack($limit)) $this->compact($limit);
		});
	}

	/** Run $fn holding the history's lock. */
	private function locked(callable $fn)
	{
		$um = umask(077);
		$lock = @fopen($this->dir . '/history.lock', 'c');
		umask($um);
		if ($lock) flock($lock, LOCK_EX);
		try {
			return $fn();
		} finally {
			if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
		}
	}

	/** Replace the history file with $write's output, atomically. */
	private function rewrite(callable $write)
	{
		$file = $this->file();
		$tmp = $this->dir . '/.history.' . getmypid() . '.tmp';
		$um = umask(077);
		$out = @fopen($tmp, 'wb');
		umask($um);
		if (!$out) return false;
		$ok = $write($out) !== false;
		$ok = fclose($out) && $ok;
		if (!$ok || !@rename($tmp, $file)) { @unlink($tmp); return false; }
		@chmod($file, 0600);
		unset(self::$counted[$file]);
		return true;
	}

	/** Keep only the newest $limit entries (under the lock). */
	private function compact($limit)
	{
		$in = @fopen($this->file(), 'rb');
		if (!$in) return;
		$skip = $this->countIn($in) - $limit;
		if ($skip > 0) {
			fseek($in, 0);
			$this->rewrite(function ($out) use ($in, $skip) {
				while ($skip > 0 && fgets($in) !== false) $skip--;
				return stream_copy_to_stream($in, $out);
			});
		}
		fclose($in);
	}

	/**
	 * Give an older history file (one command per line) the times the
	 * current format carries, once: a file whose first line has one is left
	 * alone. The file's own time stands in for the unknown ones.
	 * @method migrate
	 * @return {boolean} whether the file was rewritten
	 */
	function migrate()
	{
		if ($this->dir === null) return false;
		$file = $this->file();
		$in = @fopen($file, 'rb');
		if (!$in) return false;
		$first = fgets($in);
		if ($first === false || $first === "\n" || preg_match('/^: \d+:\d+;/', $first)) { fclose($in); return false; }
		$time = (int) @filemtime($file);
		fseek($in, 0);
		$ok = $this->rewrite(function ($out) use ($in, $time) {
			while (($l = fgets($in)) !== false) {
				$l = rtrim($l, "\r\n");
				if ($l === '') continue;
				if (fwrite($out, preg_match('/^: \d+:\d+;/', $l) ? $l . "\n" : self::format($l, $time)) === false) return false;
			}
			return true;
		});
		fclose($in);
		return $ok;
	}

	/** Forget the history. */
	function clear()
	{
		if ($this->mem !== null) {
			$this->mem = array();
			if ($this->remote) { $this->total = 0; call_user_func($this->remote, array('t' => 'hist_clear')); }
			return;
		}
		$this->locked(function () {
			$this->rewrite(function ($out) { return true; });
		});
	}

	/**
	 * History expansion, as zsh and bash do it: !! (the last line), !n (line
	 * n), !-n (n lines back), !prefix (the latest line starting with it) and
	 * ^old^new (the last line with old replaced by new). Nothing inside single
	 * quotes is touched.
	 * @method expand
	 * @param {string} $line
	 * @return {string}
	 * @throws Exception when an event is not found
	 */
	function expand($line)
	{
		if (preg_match('/^\^([^^]*)\^([^^]*)\^?$/', $line, $m)) {
			$e = $this->get($this->count());
			$last = $e ? $e['c'] : null;
			if ($last === null || strpos($last, $m[1]) === false) throw new Exception('^' . $m[1] . ': substitution failed');
			return preg_replace('/' . preg_quote($m[1], '/') . '/', addcslashes($m[2], '\\$'), $last, 1);
		}
		if (strpos($line, '!') === false) return $line;
		$out = '';
		$n = strlen($line);
		$inSq = false;
		for ($i = 0; $i < $n; $i++) {
			$c = $line[$i];
			if ($c === "'") $inSq = !$inSq;
			if ($c === '\\' && $i + 1 < $n) { $out .= $c . $line[++$i]; continue; }
			if ($c !== '!' || $inSq || $i + 1 >= $n) { $out .= $c; continue; }
			$next = $line[$i + 1];
			if ($next === '!') {
				$e = $this->get($this->count());
				if (!$e) throw new Exception('!!: event not found');
				$out .= $e['c'];
				$i++;
				continue;
			}
			if (preg_match('/\G(-?\d+)/', $line, $m, 0, $i + 1)) {
				$k = (int) $m[1];
				$e = $this->get($k < 0 ? $this->count() + $k + 1 : $k);
				if (!$e) throw new Exception('!' . $m[1] . ': event not found');
				$out .= $e['c'];
				$i += strlen($m[1]);
				continue;
			}
			if (preg_match('/\G([A-Za-z_][A-Za-z0-9_:.-]*)/', $line, $m, 0, $i + 1)) {
				$hit = $this->search($m[1], null, 1, true);
				if (!$hit) throw new Exception('!' . $m[1] . ': event not found');
				$out .= $hit[0]['c'];
				$i += strlen($m[1]);
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	// ── Aliases ─────────────────────────────────────────────────────────

	function aliases()
	{
		if ($this->aliases === null) {
			$this->aliases = array();
			if ($this->dir !== null && is_file($this->dir . '/aliases.json')) {
				$a = json_decode((string) file_get_contents($this->dir . '/aliases.json'), true);
				if (is_array($a)) $this->aliases = array_filter($a, 'is_string');
			}
		}
		return $this->aliases;
	}

	function setAlias($name, $text)
	{
		$this->aliases();
		if ($text === null) unset($this->aliases[$name]);
		else $this->aliases[$name] = (string) $text;
		ksort($this->aliases);
		if ($this->remote) { call_user_func($this->remote, array('t' => 'alias', 'name' => (string) $name, 'text' => $text)); return; }
		if ($this->dir !== null) {
			$file = $this->dir . '/aliases.json';
			@file_put_contents($file . '.tmp', json_encode($this->aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
			@rename($file . '.tmp', $file);
			@chmod($file, 0600);
		}
	}


	/**
	 * The text of a script to exec or source: from the shell directory (as
	 * the server handed it over), else from one of $roots.
	 * @return {string|null}
	 */
	function scriptText($name, array $roots)
	{
		if (isset($this->files[$name]) && preg_match('/^[A-Za-z0-9._-]+$/', (string) $name)) return $this->files[$name];
		$path = self::scriptPath($name, $roots);
		return $path === null ? null : (string) @file_get_contents($path, false, null, 0, 262144);
	}

	/**
	 * The shell directory's scripts (*.qsh, *.sh, *.cfg), for a runner.
	 * @method files
	 * @static
	 */
	static function scriptsIn($dir)
	{
		$out = array();
		$total = 0;
		foreach ((array) @scandir((string) $dir) as $f) {
			if (!is_string($f) || !preg_match('/^[A-Za-z0-9._-]+\.(qsh|sh|cfg)$/', $f) || !is_file("$dir/$f")) continue;
			$text = (string) @file_get_contents("$dir/$f", false, null, 0, 65536);
			$total += strlen($text);
			if ($total > 262144) break;
			$out[$f] = $text;
		}
		return $out;
	}
	// ── Scripts ─────────────────────────────────────────────────────────

	/**
	 * The path of a script the shell may exec or source: a plain name in the
	 * shell's own directory, or in the scripts directory; never a path that
	 * climbs out of either.
	 * @param {string} $name
	 * @param {array} $roots directories to look in
	 * @return {string|null}
	 */
	static function scriptPath($name, array $roots)
	{
		$name = (string) $name;
		if ($name === '' || strpos($name, "\0") !== false) return null;
		if (!preg_match('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/', $name) || preg_match('/(^|\/)\.\.?(\/|$)/', $name)) return null;
		foreach ($roots as $root) {
			if (!$root || !is_dir($root)) continue;
			$base = realpath($root);
			$full = realpath($base . '/' . $name);
			if ($full !== false && strpos($full, $base . DIRECTORY_SEPARATOR) === 0 && is_file($full)) return $full;
		}
		return null;
	}
}
