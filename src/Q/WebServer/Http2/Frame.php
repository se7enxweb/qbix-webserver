<?php

/**
 * HTTP/2 frames (RFC 9113 section 4 and 6).
 *
 * A frame is a nine-octet header and a payload: 24 bits of length, 8 of type,
 * 8 of flags, one reserved bit and 31 of stream identifier. Everything in the
 * protocol is carried this way, so this stays deliberately small -- it reads
 * and writes frames and knows nothing about what they mean.
 *
 * The length field is 24 bits, but a peer may not send more than the
 * SETTINGS_MAX_FRAME_SIZE it was told. Reading is written to tolerate a
 * partial frame arriving, because that is the normal case on a socket: the
 * reader returns null and the caller keeps the buffer until more arrives.
 *
 * @module Q
 */
class Q_WebServer_Http2_Frame
{
	const DATA          = 0x0;
	const HEADERS       = 0x1;
	const PRIORITY      = 0x2;
	const RST_STREAM    = 0x3;
	const SETTINGS      = 0x4;
	const PUSH_PROMISE  = 0x5;
	const PING          = 0x6;
	const GOAWAY        = 0x7;
	const WINDOW_UPDATE = 0x8;
	const CONTINUATION  = 0x9;

	const FLAG_END_STREAM  = 0x1;
	const FLAG_ACK         = 0x1;
	const FLAG_END_HEADERS = 0x4;
	const FLAG_PADDED      = 0x8;
	const FLAG_PRIORITY    = 0x20;

	const SETTINGS_HEADER_TABLE_SIZE      = 0x1;
	const SETTINGS_ENABLE_PUSH            = 0x2;
	const SETTINGS_MAX_CONCURRENT_STREAMS = 0x3;
	const SETTINGS_INITIAL_WINDOW_SIZE    = 0x4;
	const SETTINGS_MAX_FRAME_SIZE         = 0x5;
	const SETTINGS_MAX_HEADER_LIST_SIZE   = 0x6;

	const NO_ERROR            = 0x0;
	const PROTOCOL_ERROR      = 0x1;
	const INTERNAL_ERROR      = 0x2;
	const FLOW_CONTROL_ERROR  = 0x3;
	const FRAME_SIZE_ERROR    = 0x6;
	const COMPRESSION_ERROR   = 0x9;
	const ENHANCE_YOUR_CALM   = 0xb;

	/** The 24 octets every connection opens with, RFC 9113 section 3.4. */
	const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

	/**
	 * Read one frame from the front of a buffer.
	 *
	 * @method read
	 * @static
	 * @param {string} $buf consumed bytes are removed from it
	 * @return {array|null} type, flags, stream, payload -- or null if
	 *                      the buffer does not yet hold a whole frame
	 */
	static function read(&$buf)
	{
		if (strlen($buf) < 9) return null;

		$length = (ord($buf[0]) << 16) | (ord($buf[1]) << 8) | ord($buf[2]);
		if (strlen($buf) < 9 + $length) return null;

		$type   = ord($buf[3]);
		$flags  = ord($buf[4]);
		// The top bit of the stream id is reserved and must be ignored on
		// receipt rather than treated as part of the number.
		$stream = ((ord($buf[5]) & 0x7f) << 24) | (ord($buf[6]) << 16)
			| (ord($buf[7]) << 8) | ord($buf[8]);

		$payload = $length ? substr($buf, 9, $length) : '';
		$buf = substr($buf, 9 + $length);

		return array(
			'type'    => $type,
			'flags'   => $flags,
			'stream'  => $stream,
			'payload' => $payload,
		);
	}

	/**
	 * Build one frame.
	 *
	 * @method build
	 * @static
	 * @param {integer} $type
	 * @param {integer} $flags
	 * @param {integer} $stream
	 * @param {string} $payload
	 * @return {string}
	 */
	static function build($type, $flags, $stream, $payload = '')
	{
		$length = strlen($payload);
		return chr(($length >> 16) & 0xff)
			. chr(($length >> 8) & 0xff)
			. chr($length & 0xff)
			. chr($type & 0xff)
			. chr($flags & 0xff)
			. chr(($stream >> 24) & 0x7f)
			. chr(($stream >> 16) & 0xff)
			. chr(($stream >> 8) & 0xff)
			. chr($stream & 0xff)
			. $payload;
	}

	/**
	 * Parse a SETTINGS payload into identifier => value.
	 *
	 * @method parseSettings
	 * @static
	 * @param {string} $payload
	 * @return {array}
	 */
	static function parseSettings($payload)
	{
		$out = array();
		$len = strlen($payload);
		for ($i = 0; $i + 6 <= $len; $i += 6) {
			$id = (ord($payload[$i]) << 8) | ord($payload[$i + 1]);
			$value = (ord($payload[$i + 2]) << 24) | (ord($payload[$i + 3]) << 16)
				| (ord($payload[$i + 4]) << 8) | ord($payload[$i + 5]);
			$out[$id] = $value;
		}
		return $out;
	}

	/**
	 * Build a SETTINGS payload from identifier => value.
	 *
	 * @method buildSettings
	 * @static
	 * @param {array} $settings
	 * @return {string}
	 */
	static function buildSettings($settings)
	{
		$out = '';
		foreach ($settings as $id => $value) {
			$out .= chr(($id >> 8) & 0xff) . chr($id & 0xff)
				. chr(($value >> 24) & 0xff) . chr(($value >> 16) & 0xff)
				. chr(($value >> 8) & 0xff) . chr($value & 0xff);
		}
		return $out;
	}

	/**
	 * A 32-bit unsigned integer, as GOAWAY, RST_STREAM and WINDOW_UPDATE use.
	 *
	 * @method uint32
	 * @static
	 * @param {integer} $value
	 * @return {string}
	 */
	static function uint32($value)
	{
		return chr(($value >> 24) & 0xff) . chr(($value >> 16) & 0xff)
			. chr(($value >> 8) & 0xff) . chr($value & 0xff);
	}

	/**
	 * Read a 32-bit unsigned integer, with the reserved top bit cleared --
	 * which is what WINDOW_UPDATE and stream identifiers both require.
	 *
	 * @method readUint31
	 * @static
	 * @param {string} $data
	 * @param {integer} $offset
	 * @return {integer}
	 */
	static function readUint31($data, $offset = 0)
	{
		return ((ord($data[$offset]) & 0x7f) << 24) | (ord($data[$offset + 1]) << 16)
			| (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);
	}

	/**
	 * Strip padding from a DATA or HEADERS payload.
	 *
	 * The pad length is one octet at the front and the padding itself is at
	 * the back. A pad length longer than what remains is a connection error,
	 * reported here as null so the caller can send PROTOCOL_ERROR.
	 *
	 * @method unpad
	 * @static
	 * @param {string} $payload
	 * @param {integer} $flags
	 * @return {string|null}
	 */
	static function unpad($payload, $flags)
	{
		if (!($flags & self::FLAG_PADDED)) return $payload;
		if ($payload === '') return null;

		$padLength = ord($payload[0]);
		$payload = substr($payload, 1);
		if ($padLength > strlen($payload)) return null;

		return $padLength ? substr($payload, 0, -$padLength) : $payload;
	}

	/**
	 * A frame type's name, for logs and errors.
	 *
	 * @method name
	 * @static
	 * @param {integer} $type
	 * @return {string}
	 */
	static function name($type)
	{
		static $names = array(
			self::DATA => 'DATA', self::HEADERS => 'HEADERS',
			self::PRIORITY => 'PRIORITY', self::RST_STREAM => 'RST_STREAM',
			self::SETTINGS => 'SETTINGS', self::PUSH_PROMISE => 'PUSH_PROMISE',
			self::PING => 'PING', self::GOAWAY => 'GOAWAY',
			self::WINDOW_UPDATE => 'WINDOW_UPDATE', self::CONTINUATION => 'CONTINUATION',
		);
		return isset($names[$type]) ? $names[$type] : ('UNKNOWN(' . $type . ')');
	}
}
