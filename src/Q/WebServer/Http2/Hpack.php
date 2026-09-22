<?php

/**
 * HPACK, the header compression HTTP/2 requires (RFC 7541).
 *
 * This is the piece everything else in HTTP/2 stands on, and the piece most
 * likely to be quietly wrong. A framing bug usually shows up as a connection
 * that dies; an HPACK bug shows up as a header whose value is subtly corrupt,
 * or as a dynamic table that drifts out of step with the peer's so that
 * requests decode correctly for a while and then stop. Neither produces a
 * useful error, because a browser that has committed to h2 at ALPN will not
 * fall back to HTTP/1.1 -- it just shows a blank page.
 *
 * So this file is written to be checkable rather than clever, and the test
 * beside it runs the worked examples from the RFC's own Appendix C. If those
 * pass, the integer codec, the Huffman table, the static table and the dynamic
 * table are all behaving as the specification says.
 *
 * Decoding must support Huffman: browsers and curl always use it. Encoding
 * does not have to, and this does not, because emitting literals keeps the
 * encoder simple and the cost is a few dozen bytes per response on a
 * connection that is about to carry hundreds of kilobytes.
 *
 * @module Q
 */
/**
 * A header block that cannot be decoded.
 *
 * Thrown rather than returned, because there is no partial answer worth
 * having: a block that lies about a length has already cost the connection its
 * HPACK state, and the dynamic table is shared by every subsequent block on
 * that connection. RFC 7541 calls this a connection error of type
 * COMPRESSION_ERROR, and the connection layer closes accordingly.
 *
 * @class Q_WebServer_Http2_HpackException
 */
class Q_WebServer_Http2_HpackException extends Exception
{
}

class Q_WebServer_Http2_Hpack
{
	/**
	 * The static table, RFC 7541 Appendix A. Index 1 is the first entry;
	 * index 0 does not exist, which is why the array starts at 1.
	 */
	static $staticTable = array(
		1 => array(':authority', ''),
		2 => array(':method', 'GET'),
		3 => array(':method', 'POST'),
		4 => array(':path', '/'),
		5 => array(':path', '/index.html'),
		6 => array(':scheme', 'http'),
		7 => array(':scheme', 'https'),
		8 => array(':status', '200'),
		9 => array(':status', '204'),
		10 => array(':status', '206'),
		11 => array(':status', '304'),
		12 => array(':status', '400'),
		13 => array(':status', '404'),
		14 => array(':status', '500'),
		15 => array('accept-charset', ''),
		16 => array('accept-encoding', 'gzip, deflate'),
		17 => array('accept-language', ''),
		18 => array('accept-ranges', ''),
		19 => array('accept', ''),
		20 => array('access-control-allow-origin', ''),
		21 => array('age', ''),
		22 => array('allow', ''),
		23 => array('authorization', ''),
		24 => array('cache-control', ''),
		25 => array('content-disposition', ''),
		26 => array('content-encoding', ''),
		27 => array('content-language', ''),
		28 => array('content-length', ''),
		29 => array('content-location', ''),
		30 => array('content-range', ''),
		31 => array('content-type', ''),
		32 => array('cookie', ''),
		33 => array('date', ''),
		34 => array('etag', ''),
		35 => array('expect', ''),
		36 => array('expires', ''),
		37 => array('from', ''),
		38 => array('host', ''),
		39 => array('if-match', ''),
		40 => array('if-modified-since', ''),
		41 => array('if-none-match', ''),
		42 => array('if-range', ''),
		43 => array('if-unmodified-since', ''),
		44 => array('last-modified', ''),
		45 => array('link', ''),
		46 => array('location', ''),
		47 => array('max-forwards', ''),
		48 => array('proxy-authenticate', ''),
		49 => array('proxy-authorization', ''),
		50 => array('range', ''),
		51 => array('referer', ''),
		52 => array('refresh', ''),
		53 => array('retry-after', ''),
		54 => array('server', ''),
		55 => array('set-cookie', ''),
		56 => array('strict-transport-security', ''),
		57 => array('transfer-encoding', ''),
		58 => array('user-agent', ''),
		59 => array('vary', ''),
		60 => array('via', ''),
		61 => array('www-authenticate', ''),
	);

	/**
	 * The Huffman code, RFC 7541 Appendix B: one entry per symbol 0..255 plus
	 * 256 for EOS, each a code value and the number of bits it occupies.
	 *
	 * Stored as "value,bits" pairs in one string rather than a nested array,
	 * because this is parsed once per process and a literal array of 257
	 * two-element arrays costs considerably more memory in every worker.
	 */
	const HUFFMAN = '1ff8,13 7fffd8,23 fffffe2,28 fffffe3,28 fffffe4,28 fffffe5,28 fffffe6,28 fffffe7,28'
		. ' fffffe8,28 ffffea,24 3ffffffc,30 fffffe9,28 fffffea,28 3ffffffd,30 fffffeb,28 fffffec,28'
		. ' fffffed,28 fffffee,28 fffffef,28 ffffff0,28 ffffff1,28 ffffff2,28 3ffffffe,30 ffffff3,28'
		. ' ffffff4,28 ffffff5,28 ffffff6,28 ffffff7,28 ffffff8,28 ffffff9,28 ffffffa,28 ffffffb,28'
		. ' 14,6 3f8,10 3f9,10 ffa,12 1ff9,13 15,6 f8,8 7fa,11'
		. ' 3fa,10 3fb,10 f9,8 7fb,11 fa,8 16,6 17,6 18,6'
		. ' 0,5 1,5 2,5 19,6 1a,6 1b,6 1c,6 1d,6'
		. ' 1e,6 1f,6 5c,7 fb,8 7ffc,15 20,6 ffb,12 3fc,10'
		. ' 1ffa,13 21,6 5d,7 5e,7 5f,7 60,7 61,7 62,7'
		. ' 63,7 64,7 65,7 66,7 67,7 68,7 69,7 6a,7'
		. ' 6b,7 6c,7 6d,7 6e,7 6f,7 70,7 71,7 72,7'
		. ' fc,8 73,7 fd,8 1ffb,13 7fff0,19 1ffc,13 3ffc,14 22,6'
		. ' 7ffd,15 3,5 23,6 4,5 24,6 5,5 25,6 26,6'
		. ' 27,6 6,5 74,7 75,7 28,6 29,6 2a,6 7,5'
		. ' 2b,6 76,7 2c,6 8,5 9,5 2d,6 77,7 78,7'
		. ' 79,7 7a,7 7b,7 7ffe,15 7fc,11 3ffd,14 1ffd,13 ffffffc,28'
		. ' fffe6,20 3fffd2,22 fffe7,20 fffe8,20 3fffd3,22 3fffd4,22 3fffd5,22 7fffd9,23'
		. ' 3fffd6,22 7fffda,23 7fffdb,23 7fffdc,23 7fffdd,23 7fffde,23 ffffeb,24 7fffdf,23'
		. ' ffffec,24 ffffed,24 3fffd7,22 7fffe0,23 ffffee,24 7fffe1,23 7fffe2,23 7fffe3,23'
		. ' 7fffe4,23 1fffdc,21 3fffd8,22 7fffe5,23 3fffd9,22 7fffe6,23 7fffe7,23 ffffef,24'
		. ' 3fffda,22 1fffdd,21 fffe9,20 3fffdb,22 3fffdc,22 7fffe8,23 7fffe9,23 1fffde,21'
		. ' 7fffea,23 3fffdd,22 3fffde,22 fffff0,24 1fffdf,21 3fffdf,22 7fffeb,23 7fffec,23'
		. ' 1fffe0,21 1fffe1,21 3fffe0,22 1fffe2,21 7fffed,23 3fffe1,22 7fffee,23 7fffef,23'
		. ' fffea,20 3fffe2,22 3fffe3,22 3fffe4,22 7ffff0,23 3fffe5,22 3fffe6,22 7ffff1,23'
		. ' 3ffffe0,26 3ffffe1,26 fffeb,20 7fff1,19 3fffe7,22 7ffff2,23 3fffe8,22 1ffffec,25'
		. ' 3ffffe2,26 3ffffe3,26 3ffffe4,26 7ffffde,27 7ffffdf,27 3ffffe5,26 fffff1,24 1ffffed,25'
		. ' 7fff2,19 1fffe3,21 3ffffe6,26 7ffffe0,27 7ffffe1,27 3ffffe7,26 7ffffe2,27 fffff2,24'
		. ' 1fffe4,21 1fffe5,21 3ffffe8,26 3ffffe9,26 ffffffd,28 7ffffe3,27 7ffffe4,27 7ffffe5,27'
		. ' fffec,20 fffff3,24 fffed,20 1fffe6,21 3fffe9,22 1fffe7,21 1fffe8,21 7ffff3,23'
		. ' 3fffea,22 3fffeb,22 1ffffee,25 1ffffef,25 fffff4,24 fffff5,24 3ffffea,26 7ffff4,23'
		. ' 3ffffeb,26 7ffffe6,27 3ffffec,26 3ffffed,26 7ffffe7,27 7ffffe8,27 7ffffe9,27 7ffffea,27'
		. ' 7ffffeb,27 ffffffe,28 7ffffec,27 7ffffed,27 7ffffee,27 7ffffef,27 7fffff0,27 3ffffee,26'
		. ' 3fffffff,30';

	/** @var array|null symbol => array(code, bits), built once */
	static protected $huffman = null;

	/** @var array|null bit string => symbol, built once, for decoding */
	static protected $huffmanDecode = null;

	/** @var array the dynamic table, most recently added first */
	public $dynamic = array();

	/** @var int the peer's declared limit on the dynamic table, in octets */
	public $maxSize = 4096;

	/** @var int the size currently accounted for, by the RFC's formula */
	public $size = 0;

	/**
	 * Build the Huffman lookups once per process.
	 *
	 * @method huffman
	 * @static
	 */
	static function huffman()
	{
		if (self::$huffman !== null) return;

		self::$huffman = array();
		self::$huffmanDecode = array();
		$symbol = 0;
		foreach (explode(' ', preg_replace('/\s+/', ' ', self::HUFFMAN)) as $pair) {
			if ($pair === '') continue;
			list($hex, $bits) = explode(',', $pair);
			$code = hexdec($hex);
			$bits = (int) $bits;
			self::$huffman[$symbol] = array($code, $bits);
			// Keyed by the code's bit string, so decoding is a prefix walk
			// with an array lookup rather than a tree of objects.
			self::$huffmanDecode[str_pad(decbin($code), $bits, '0', STR_PAD_LEFT)] = $symbol;
			$symbol++;
		}
	}

	/**
	 * Decode an integer in HPACK's prefixed form (RFC 7541 section 5.1).
	 *
	 * @method decodeInt
	 * @static
	 * @param {string} $buf
	 * @param {integer} $pos advanced past what was read
	 * @param {integer} $prefixBits how many bits of the first octet belong to the value
	 * @return {integer}
	 */
	static function decodeInt($buf, &$pos, $prefixBits)
	{
		// Every read here is at an offset a peer chose, on a buffer a peer
		// sent, before any request exists to reject. Reading past the end is
		// only a warning in PHP 8 and yields an empty string, but a warning
		// per byte is written to the log with attacker-controlled frequency,
		// and with display_errors on it is written into the response.
		if (!isset($buf[$pos])) {
			throw new Q_WebServer_Http2_HpackException(
				'header block ended inside an integer'
			);
		}

		$max = (1 << $prefixBits) - 1;
		$value = ord($buf[$pos]) & $max;
		$pos++;
		if ($value < $max) return $value;

		$shift = 0;
		do {
			if (!isset($buf[$pos])) return $value; // truncated; caller will fail
			$byte = ord($buf[$pos]);
			$pos++;
			$value += ($byte & 0x7f) << $shift;
			$shift += 7;
		} while ($byte & 0x80);

		return $value;
	}

	/**
	 * Encode an integer in HPACK's prefixed form. The high bits of the first
	 * octet are the caller's to set, and are passed in already positioned.
	 *
	 * @method encodeInt
	 * @static
	 * @param {integer} $value
	 * @param {integer} $prefixBits
	 * @param {integer} $flags bits above the prefix, already shifted
	 * @return {string}
	 */
	static function encodeInt($value, $prefixBits, $flags = 0)
	{
		$max = (1 << $prefixBits) - 1;
		if ($value < $max) {
			return chr($flags | $value);
		}
		$out = chr($flags | $max);
		$value -= $max;
		while ($value >= 128) {
			$out .= chr(($value & 0x7f) | 0x80);
			$value >>= 7;
		}
		return $out . chr($value);
	}

	/**
	 * Decode a Huffman-coded string.
	 *
	 * The padding rule matters: the encoder pads to an octet boundary with the
	 * prefix of the EOS code, which is all ones. So a run of fewer than eight
	 * ones at the end is padding and must be dropped rather than decoded, and
	 * anything else at the end is a protocol error.
	 *
	 * @method huffmanDecode
	 * @static
	 * @param {string} $data
	 * @return {string}
	 */
	static function huffmanDecode($data)
	{
		self::huffman();

		$bits = '';
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
		}

		$out = '';
		$cur = '';
		$total = strlen($bits);
		for ($i = 0; $i < $total; $i++) {
			$cur .= $bits[$i];
			if (strlen($cur) > 30) {
				// No code is longer than 30 bits; this is corrupt input.
				return $out;
			}
			if (isset(self::$huffmanDecode[$cur])) {
				$symbol = self::$huffmanDecode[$cur];
				if ($symbol === 256) return $out; // EOS in the stream
				$out .= chr($symbol);
				$cur = '';
			}
		}

		return $out;
	}

	/**
	 * Encode a string, without Huffman. See the note at the top of the file.
	 *
	 * @method encodeString
	 * @static
	 * @param {string} $value
	 * @return {string}
	 */
	static function encodeString($value)
	{
		return self::encodeInt(strlen($value), 7, 0) . $value;
	}

	/**
	 * Read a string literal, Huffman-coded or not.
	 *
	 * @method decodeString
	 * @static
	 * @param {string} $buf
	 * @param {integer} $pos
	 * @return {string}
	 */
	static function decodeString($buf, &$pos)
	{
		if (!isset($buf[$pos])) {
			throw new Q_WebServer_Http2_HpackException(
				'header block ended where a string was expected'
			);
		}

		$huffman = (ord($buf[$pos]) & 0x80) !== 0;
		$length = self::decodeInt($buf, $pos, 7);

		// A declared length longer than what is left is a lie, and the usual
		// way to tell one: substr() would quietly return what there is, so the
		// block would decode to something plausible that the peer never sent.
		if ($length < 0 or $pos + $length > strlen($buf)) {
			throw new Q_WebServer_Http2_HpackException(
				'header block declares a string longer than itself'
			);
		}

		$raw = substr($buf, $pos, $length);
		$pos += $length;
		return $huffman ? self::huffmanDecode($raw) : $raw;
	}

	/**
	 * An entry by index, from the static table or the dynamic one.
	 *
	 * @method lookup
	 * @param {integer} $index
	 * @return {array|null} array(name, value)
	 */
	function lookup($index)
	{
		if ($index <= 0) return null;
		if ($index <= 61) {
			return isset(self::$staticTable[$index]) ? self::$staticTable[$index] : null;
		}
		$i = $index - 62;
		return isset($this->dynamic[$i]) ? $this->dynamic[$i] : null;
	}

	/**
	 * Add to the dynamic table, evicting from the end until it fits.
	 *
	 * The size of an entry is its name plus its value plus 32, which is the
	 * RFC's allowance for per-entry overhead. An entry larger than the whole
	 * table empties it and is then not stored, which is what the RFC requires
	 * rather than an error.
	 *
	 * @method add
	 * @param {string} $name
	 * @param {string} $value
	 */
	function add($name, $value)
	{
		$entrySize = strlen($name) + strlen($value) + 32;

		while ($this->size + $entrySize > $this->maxSize and count($this->dynamic)) {
			$last = array_pop($this->dynamic);
			$this->size -= strlen($last[0]) + strlen($last[1]) + 32;
		}

		if ($entrySize > $this->maxSize) {
			$this->dynamic = array();
			$this->size = 0;
			return;
		}

		array_unshift($this->dynamic, array($name, $value));
		$this->size += $entrySize;
	}

	/**
	 * Apply a table size update from the peer.
	 *
	 * @method resize
	 * @param {integer} $max
	 */
	function resize($max)
	{
		$this->maxSize = $max;
		while ($this->size > $this->maxSize and count($this->dynamic)) {
			$last = array_pop($this->dynamic);
			$this->size -= strlen($last[0]) + strlen($last[1]) + 32;
		}
	}

	/**
	 * Decode a header block into an ordered list of name/value pairs.
	 *
	 * Ordered, and a list rather than a map, because HTTP/2 allows a field to
	 * appear more than once and cookie in particular arrives split across
	 * several entries that have to be rejoined in order.
	 *
	 * @method decode
	 * @param {string} $block
	 * @return {array} list of array(name, value)
	 */
	function decode($block)
	{
		$out = array();
		$pos = 0;
		$len = strlen($block);

		while ($pos < $len) {
			$byte = ord($block[$pos]);

			if ($byte & 0x80) {
				// Indexed header field: the whole pair comes from a table.
				$index = self::decodeInt($block, $pos, 7);
				$entry = $this->lookup($index);
				if ($entry === null) return $out;
				$out[] = $entry;
				continue;
			}

			if ($byte & 0x40) {
				// Literal with incremental indexing: also joins the table.
				$index = self::decodeInt($block, $pos, 6);
				if ($index === 0) {
					$name = self::decodeString($block, $pos);
				} else {
					$entry = $this->lookup($index);
					if ($entry === null) return $out;
					$name = $entry[0];
				}
				$value = self::decodeString($block, $pos);
				$this->add($name, $value);
				$out[] = array($name, $value);
				continue;
			}

			if (($byte & 0xe0) === 0x20) {
				// Dynamic table size update.
				$this->resize(self::decodeInt($block, $pos, 5));
				continue;
			}

			// Literal without indexing, or never indexed. Both are read the
			// same way; the difference is only that neither joins the table,
			// and "never indexed" must also not be indexed when forwarded.
			$index = self::decodeInt($block, $pos, 4);
			if ($index === 0) {
				$name = self::decodeString($block, $pos);
			} else {
				$entry = $this->lookup($index);
				if ($entry === null) return $out;
				$name = $entry[0];
			}
			$value = self::decodeString($block, $pos);
			$out[] = array($name, $value);
		}

		return $out;
	}

	/**
	 * Encode a header list.
	 *
	 * Uses the static table for a name it recognises and literals for
	 * everything else, and never adds to the dynamic table. That keeps the
	 * encoder stateless: there is no table on this side to drift out of step
	 * with the peer's, which is the failure this design is most exposed to.
	 *
	 * @method encode
	 * @param {array} $headers list of array(name, value)
	 * @return {string}
	 */
	function encode($headers)
	{
		$out = '';
		foreach ($headers as $pair) {
			$name = strtolower($pair[0]);
			$value = (string) $pair[1];

			$nameIndex = 0;
			foreach (self::$staticTable as $i => $entry) {
				if ($entry[0] === $name) { $nameIndex = $i; break; }
			}

			if ($nameIndex) {
				// Literal without indexing, name from the table.
				$out .= self::encodeInt($nameIndex, 4, 0x00);
			} else {
				$out .= chr(0x00) . self::encodeString($name);
			}
			$out .= self::encodeString($value);
		}
		return $out;
	}
}
