<?php
/**
 * @module Q
 */
/**
 * The server's own pages -- dashboard, documentation and the like -- as
 * design files on disk instead of markup inside the PHP.
 *
 * A design is a directory of views, each a page template plus the files it
 * includes:
 *
 *   designs/<design>/<view>/page.html     the page, with {{placeholders}}
 *   designs/<design>/<view>/style.css     included by {{@style.css}}
 *   designs/<design>/<view>/script.js     included by {{@script.js}}
 *
 * Files are looked up in the configuration trees' designs/ first -- any
 * overlay tree before the base /etc/qbix (see Q_WebServer_Layout::stack())
 * -- then in the engine's own
 * designs/; and in the active
 * design (Q.webserver.design) first, then in "default". So a design only has
 * to contain what it changes, and an operator can override one file of the
 * shipped design by putting that file in <conf dir>/designs/default/<view>/.
 *
 * Rendering is plain substitution, never evaluation: {{@file}} includes a
 * file of the same view and {{@view/file}} one of another view -- the views
 * share designs/<design>/common/chrome.css that way, overridable file by
 * file like the rest (one level) -- then every {{name}} is replaced by its
 * value in a single pass, so a value containing braces is never read as a
 * placeholder. Values arrive already escaped for where they go; the shipped
 * templates are byte-for-byte the pages the PHP used to produce.
 *
 * @class Q_WebServer_Design
 * @static
 */
class Q_WebServer_Design
{
	/** @var array path => array(mtime, size, contents), for files read before */
	private static $files = array();

	/**
	 * The engine's own designs directory.
	 * @method engineDir
	 * @static
	 * @return {string}
	 */
	static function engineDir()
	{
		return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'designs';
	}

	/**
	 * Where designs are looked for, in order.
	 * @method roots
	 * @static
	 * @return {array}
	 */
	static function roots()
	{
		$roots = array();
		$dirs = class_exists('Q_Config', false)
			? Q_Config::get('Q', 'webserver', 'confDirs', null) : null;
		if (!is_array($dirs)) {
			$one = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'confDir', null) : null;
			$dirs = (is_string($one) and $one !== '') ? array($one) : array();
		}
		// Loaded base first, overlay last; looked up the other way round.
		foreach (array_reverse($dirs) as $dir) {
			if (is_string($dir) and $dir !== '' and is_dir($dir . '/designs')) {
				$roots[] = $dir . '/designs';
			}
		}
		$roots[] = self::engineDir();
		return $roots;
	}

	/**
	 * The active design's name: Q.webserver.design, default "default".
	 * Only a plain name -- it becomes a path.
	 * @method active
	 * @static
	 * @return {string}
	 */
	static function active()
	{
		$name = class_exists('Q_Config', false)
			? (string) Q_Config::get('Q', 'webserver', 'design', 'default') : 'default';
		return (preg_match('/^[A-Za-z0-9._-]+$/', $name) && $name[0] !== '.') ? $name : 'default';
	}

	/**
	 * The path of one file of a view, or null if no design has it.
	 * @method path
	 * @static
	 * @param {string} $view
	 * @param {string} $name
	 * @return {string|null}
	 */
	static function path($view, $name)
	{
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $view . $name) or $view[0] === '.' or $name[0] === '.') {
			return null;
		}
		foreach (array_unique(array(self::active(), 'default')) as $design) {
			foreach (self::roots() as $root) {
				$path = "$root/$design/$view/$name";
				if (is_file($path)) return $path;
			}
		}
		return null;
	}

	/**
	 * One file's contents, re-read only when it changes.
	 * @method read
	 * @static
	 * @param {string} $view
	 * @param {string} $name
	 * @return {string|null}
	 */
	static function read($view, $name)
	{
		$path = self::path($view, $name);
		if ($path === null) return null;
		clearstatcache(true, $path);
		$mtime = (int) @filemtime($path);
		$size = (int) @filesize($path);
		$kept = self::$files[$path] ?? null;
		if ($kept and $kept[0] === $mtime and $kept[1] === $size) return $kept[2];
		$contents = @file_get_contents($path);
		if ($contents === false) return null;
		self::$files[$path] = array($mtime, $size, $contents);
		return $contents;
	}

	/**
	 * A view's page with its values in place.
	 *
	 * @method render
	 * @static
	 * @param {string} $view
	 * @param {array} $values name => string, already escaped for its place
	 * @param {string} $pageName the page to render, another view page beside page.html (the panel's refused.html)
	 * @return {string|null} null when no design has the view
	 */
	static function render($view, array $values, $pageName = 'page.html')
	{
		$page = self::read($view, $pageName);
		if ($page === null) return null;
		$page = preg_replace_callback('/\{\{@(?:([A-Za-z0-9._-]+)\/)?([A-Za-z0-9._-]+)\}\}/', function ($m) use ($view) {
			$included = Q_WebServer_Design::read($m[1] !== '' ? $m[1] : $view, $m[2]);
			return $included === null ? '' : $included;
		}, $page);
		$map = array();
		foreach ($values as $name => $value) {
			$map['{{' . $name . '}}'] = (string) $value;
		}
		return strtr($page, $map);
	}
}
