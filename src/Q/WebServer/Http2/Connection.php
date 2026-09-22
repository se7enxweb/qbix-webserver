<?php

/**
 * One HTTP/2 connection (RFC 9113).
 *
 * Holds everything that is per-connection -- the two HPACK contexts, the
 * settings each side declared, the flow-control windows and the open streams
 * -- and turns a stream of frames into whole requests. It does not know how a
 * request is answered: it calls a handler with a request array and is given a
 * response array back, which is what lets the same connection sit in front of
 * the worker pool, the fork path or an in-process handler without knowing
 * which.
 *
 * Deliberately not implemented, and not silently ignored either:
 *
 *   PUSH_PROMISE   this side never pushes, and says so in SETTINGS. A client
 *                  that sends one gets PROTOCOL_ERROR, as the RFC requires.
 *   PRIORITY       accepted and discarded. The scheduling advice it carries is
 *                  optional, widely ignored, and deprecated in RFC 9113.
 *
 * @module Q
 */
class Q_WebServer_Http2_Connection
{
	/** @var resource the client socket */
	public $socket;

	/** @var string bytes read but not yet consumed */
	protected $buffer = '';

	/** @var boolean whether the connection preface has been seen */
	protected $prefaceSeen = false;

	/** @var Q_WebServer_Http2_Hpack decoding context, fed by the peer */
	public $inbound;

	/** @var Q_WebServer_Http2_Hpack encoding context, ours */
	public $outbound;

	/** @var array stream id => state */
	public $streams = array();

	/**
	 * Bytes written but not yet accepted by the socket.
	 *
	 * @property $outBuffer
	 * @type {string}
	 */
	public $outBuffer = '';

	/**
	 * Resource limits, and why each one exists.
	 *
	 * An HTTP/2 connection lets one peer ask a server to hold state on its
	 * behalf: open streams, a header block being assembled, a body being
	 * received, bytes queued to send. Every one of those is unbounded unless
	 * something bounds it, and a peer that never finishes what it starts turns
	 * each into a way to exhaust the process. None of them require an
	 * authenticated user or a malformed frame; they are ordinary protocol use,
	 * taken to excess.
	 *
	 * Each is overridable under Q.web.http2.limits, because the right value
	 * depends on what the application does -- an installation accepting large
	 * uploads needs a larger body cap than one serving pages.
	 *
	 * @property $limits
	 * @type {array}
	 */
	public $limits = array(
		// Streams open at once. We already advertise 128 in SETTINGS and never
		// enforced it, which is the part that matters: a peer is entitled to
		// believe an advertised limit is real.
		'concurrentStreams' => 128,

		// Total header block for one request, across CONTINUATION frames.
		// Unbounded, a peer sends CONTINUATION forever and the block grows
		// until the process dies -- with no request ever completing, so
		// nothing logs it. This is CVE-2024-27316 in Apache and was the same
		// shape in several other servers the same week.
		'headerListSize' => 65536,

		// One request body. Generous, because uploads are a legitimate use.
		'bodySize' => 67108864,

		// Undecoded inbound bytes. A peer can otherwise announce frames it
		// never completes and make us hold the fragments.
		'readBuffer' => 4194304,

		// Bytes queued for a peer that is not reading them. This is the
		// write-side slowloris, and it arrived with the output buffer that
		// fixed truncation: what will not fit in the socket waits in memory,
		// and a peer that simply stops reading makes it grow without end.
		'writeBuffer' => 8388608,

		// Streams reset without being answered. Opening a stream costs real
		// work; cancelling it immediately and opening another costs the peer
		// almost nothing. That asymmetry is CVE-2023-44487, and the defence is
		// to notice the ratio rather than the rate.
		'resetStreams' => 256,

		// Frames that oblige us to write something back: PING wants a PONG,
		// SETTINGS wants an ACK. Cheap to send, not free to answer.
		'reflexFrames' => 1000,

		// Seconds a connection may sit with nothing happening on it.
		'idleSeconds' => 120,
	);

	/** @var integer Streams opened on this connection, ever. */
	public $streamsOpened = 0;

	/** @var integer Streams reset by the peer before we answered them. */
	public $streamsReset = 0;

	/** @var integer PING and SETTINGS frames answered. */
	public $reflexFrames = 0;

	/** @var float When something last arrived, for the idle sweep. */
	public $lastActivity = 0;

	/**
	 * Watcher id while waiting for the socket to become writable, else null.
	 *
	 * @property $writeWatcher
	 * @type {mixed}
	 */
	protected $writeWatcher = null;

	/** @var integer the highest stream id the peer has opened */
	public $lastStream = 0;

	/** @var integer connection-level flow-control window for sending */
	public $sendWindow = 65535;

	/** @var integer what a newly opened stream may send, from the peer's SETTINGS */
	public $initialSendWindow = 65535;

	/** @var integer the largest frame the peer will accept */
	public $maxFrameSize = 16384;

	/** @var boolean set once GOAWAY has been sent */
	public $closed = false;

	/** @var callable given a request array, returns a response array */
	public $handler;

	/**
	 * @method __construct
	 * @param {resource} $socket
	 * @param {callable} $handler
	 */
	function __construct($socket, $handler)
	{
		$this->socket = $socket;
		$this->handler = $handler;
		$this->inbound = new Q_WebServer_Http2_Hpack();
		$this->outbound = new Q_WebServer_Http2_Hpack();
	}

	/**
	 * Announce what this side accepts. Sent as soon as the connection opens,
	 * before anything is read, which is what the RFC requires.
	 *
	 * Push is refused explicitly rather than left at its default, because a
	 * client is entitled to assume the default otherwise and this server has
	 * no way to honour a promise.
	 *
	 * @method start
	 */
	function start()
	{
		// Limits may be tuned per installation, but never switched off: a
		// value of 0 or less would mean "unbounded", which is the state this
		// exists to leave behind.
		if (class_exists('Q_Config')) {
			foreach ($this->limits as $name => $default) {
				$configured = Q_Config::get('Q', 'web', 'http2', 'limits', $name, null);
				if (is_numeric($configured) and (int) $configured > 0) {
					$this->limits[$name] = (int) $configured;
				}
			}
		}
		$this->lastActivity = microtime(true);

		$this->write(Q_WebServer_Http2_Frame::build(
			Q_WebServer_Http2_Frame::SETTINGS, 0, 0,
			Q_WebServer_Http2_Frame::buildSettings(array(
				Q_WebServer_Http2_Frame::SETTINGS_ENABLE_PUSH => 0,
				Q_WebServer_Http2_Frame::SETTINGS_MAX_CONCURRENT_STREAMS => $this->limits['concurrentStreams'],
				Q_WebServer_Http2_Frame::SETTINGS_INITIAL_WINDOW_SIZE => 1048576,
				Q_WebServer_Http2_Frame::SETTINGS_MAX_FRAME_SIZE => 16384,
			))
		));

		// Raise the connection window from its 65535 default so a client
		// uploading a body does not stall after the first 64KB waiting for
		// this side to say more is welcome.
		$this->write(Q_WebServer_Http2_Frame::build(
			Q_WebServer_Http2_Frame::WINDOW_UPDATE, 0, 0,
			Q_WebServer_Http2_Frame::uint32(1048576 - 65535)
		));
	}

	/**
	 * Take bytes off the socket and act on every whole frame in them.
	 *
	 * @method feed
	 * @param {string} $data
	 * @return {boolean} false once the connection should be closed
	 */
	function feed($data)
	{
		$this->buffer .= $data;
		$this->lastActivity = microtime(true);

		// Undecoded bytes are bytes a peer has made us hold. A frame header
		// announces a length, and until that many bytes arrive the fragment
		// waits here; a peer that announces and dawdles, or simply streams
		// faster than we parse, otherwise grows this without end.
		if (strlen($this->buffer) > $this->limits['readBuffer']) {
			$this->goaway(Q_WebServer_Http2_Frame::ENHANCE_YOUR_CALM);
			return false;
		}

		if (!$this->prefaceSeen) {
			$len = strlen(Q_WebServer_Http2_Frame::PREFACE);
			if (strlen($this->buffer) < $len) return true;
			if (strncmp($this->buffer, Q_WebServer_Http2_Frame::PREFACE, $len) !== 0) {
				// Not HTTP/2 after all. Nothing to say in a protocol the peer
				// is not speaking, so just close.
				return false;
			}
			$this->buffer = substr($this->buffer, $len);
			$this->prefaceSeen = true;
		}

		while (($frame = Q_WebServer_Http2_Frame::read($this->buffer)) !== null) {
			if (!$this->handle($frame)) return false;
			if ($this->closed) return false;
		}

		return true;
	}

	/**
	 * Act on one frame.
	 *
	 * @method handle
	 * @param {array} $frame
	 * @return {boolean}
	 */
	protected function handle($frame)
	{
		$F = 'Q_WebServer_Http2_Frame';
		$type = $frame['type'];
		$stream = $frame['stream'];

		switch ($type) {

		case $F::SETTINGS:
			if ($frame['flags'] & $F::FLAG_ACK) return true;
			$settings = $F::parseSettings($frame['payload']);
			if (isset($settings[$F::SETTINGS_HEADER_TABLE_SIZE])) {
				$this->outbound->resize($settings[$F::SETTINGS_HEADER_TABLE_SIZE]);
			}
			if (isset($settings[$F::SETTINGS_INITIAL_WINDOW_SIZE])) {
				$this->initialSendWindow = $settings[$F::SETTINGS_INITIAL_WINDOW_SIZE];
			}
			if (isset($settings[$F::SETTINGS_MAX_FRAME_SIZE])) {
				$this->maxFrameSize = $settings[$F::SETTINGS_MAX_FRAME_SIZE];
			}
			if (!$this->reflex()) return false;
			$this->write($F::build($F::SETTINGS, $F::FLAG_ACK, 0));
			return true;

		case $F::PING:
			if ($frame['flags'] & $F::FLAG_ACK) return true;
			if (!$this->reflex()) return false;
			// The payload must come back unchanged.
			$this->write($F::build($F::PING, $F::FLAG_ACK, 0, $frame['payload']));
			return true;

		case $F::WINDOW_UPDATE:
			if (strlen($frame['payload']) < 4) return true;
			$increment = $F::readUint31($frame['payload']);
			if ($stream === 0) {
				$this->sendWindow += $increment;
				// Room on the connection may unblock any stream.
				foreach (array_keys($this->streams) as $id) {
					$this->flush($id);
				}
			} elseif (isset($this->streams[$stream])) {
				$this->streams[$stream]['sendWindow'] += $increment;
				$this->flush($stream);
			}
			return true;

		case $F::RST_STREAM:
			// Opening a stream costs this process real work; cancelling it
			// costs the peer three bytes of payload. A peer that opens and
			// immediately resets, over and over, extracts work at a rate no
			// concurrency limit notices, because nothing is ever concurrent.
			//
			// Counted rather than rate-limited: a legitimate client resets
			// streams occasionally -- a navigation away, an aborted fetch --
			// and the shape that matters is thousands of them on one
			// connection, not their timing.
			if (isset($this->streams[$stream])) {
				++$this->streamsReset;
				if ($this->streamsReset > $this->limits['resetStreams']) {
					$this->goaway($F::ENHANCE_YOUR_CALM);
					return false;
				}
			}
			unset($this->streams[$stream]);
			return true;

		case $F::GOAWAY:
			return false;

		case $F::PRIORITY:
			// Advisory, and deprecated. Accepted so the peer is not told it
			// did something wrong, and then ignored.
			return true;

		case $F::PUSH_PROMISE:
			// A client must never send one.
			$this->goaway($F::PROTOCOL_ERROR);
			return false;

		case $F::HEADERS:
			return $this->onHeaders($frame);

		case $F::CONTINUATION:
			if (!isset($this->streams[$stream])) return true;
			$this->streams[$stream]['headerBlock'] .= $frame['payload'];

			// CONTINUATION frames may follow a header block indefinitely, and
			// a peer that never sets END_HEADERS makes the block grow until
			// the process dies -- without ever completing a request, so
			// nothing in an access log shows it happening.
			if (strlen($this->streams[$stream]['headerBlock'])
				> $this->limits['headerListSize']
			) {
				$this->goaway(Q_WebServer_Http2_Frame::ENHANCE_YOUR_CALM);
				return false;
			}
			if ($frame['flags'] & $F::FLAG_END_HEADERS) {
				return $this->endHeaders($stream);
			}
			return true;

		case $F::DATA:
			return $this->onData($frame);

		default:
			// An unknown frame type must be ignored, not treated as an error.
			return true;
		}
	}

	/**
	 * A HEADERS frame opens a stream and may complete a request on its own.
	 *
	 * @method onHeaders
	 * @param {array} $frame
	 * @return {boolean}
	 */
	protected function onHeaders($frame)
	{
		$F = 'Q_WebServer_Http2_Frame';
		$stream = $frame['stream'];

		if ($stream === 0 or ($stream % 2) === 0) {
			// Streams a client opens are odd and non-zero.
			$this->goaway($F::PROTOCOL_ERROR);
			return false;
		}

		$payload = $F::unpad($frame['payload'], $frame['flags']);
		if ($payload === null) {
			$this->goaway($F::PROTOCOL_ERROR);
			return false;
		}

		// A priority section, if present, sits in front of the header block.
		if ($frame['flags'] & $F::FLAG_PRIORITY) {
			if (strlen($payload) < 5) {
				$this->goaway($F::PROTOCOL_ERROR);
				return false;
			}
			$payload = substr($payload, 5);
		}

		if ($stream > $this->lastStream) $this->lastStream = $stream;

		// The advertised concurrency limit, actually applied.
		//
		// SETTINGS has always told the peer 128 and nothing counted, so a peer
		// that believed us was the only thing keeping the number down. Each
		// open stream holds a header block, a body and a send queue.
		//
		// REFUSED_STREAM would be the courteous answer, but this connection
		// has already exceeded what it was told; the peer is not listening to
		// SETTINGS, so there is nothing to be gained by a per-stream refusal.
		$open = 0;
		foreach ($this->streams as $each) {
			if (empty($each['finished'])) ++$open;
		}
		if ($open >= $this->limits['concurrentStreams']) {
			$this->goaway(Q_WebServer_Http2_Frame::ENHANCE_YOUR_CALM);
			return false;
		}

		++$this->streamsOpened;

		$this->streams[$stream] = array(
			'headerBlock' => $payload,
			'body'        => '',
			'sendWindow'  => $this->initialSendWindow,
			'endStream'   => (bool) ($frame['flags'] & $F::FLAG_END_STREAM),
			'headers'     => null,
			// What the client will accept, kept so the response can be
			// compressed. HTTP/2 compresses headers for you and does nothing
			// at all about bodies: a response that is 9KB gzipped over
			// HTTP/1.1 goes out as 77KB here unless this is honoured.
			'accept'      => '',
			// Body still to send, and whether the end has been written. A
			// flow-control window can run out mid-body; what is left waits
			// here for the WINDOW_UPDATE that makes room.
			'pending'     => '',
			'finished'    => false,
		);

		if ($frame['flags'] & $F::FLAG_END_HEADERS) {
			return $this->endHeaders($stream);
		}

		// Otherwise CONTINUATION frames follow.
		return true;
	}

	/**
	 * The header block for a stream is complete: decode it, and dispatch if
	 * the request has no body.
	 *
	 * @method endHeaders
	 * @param {integer} $stream
	 * @return {boolean}
	 */
	protected function endHeaders($stream)
	{
		$F = 'Q_WebServer_Http2_Frame';

		$list = $this->inbound->decode($this->streams[$stream]['headerBlock']);
		$this->streams[$stream]['headers'] = $list;
		$this->streams[$stream]['headerBlock'] = '';

		if ($this->streams[$stream]['endStream']) {
			return $this->dispatch($stream);
		}
		return true;
	}

	/**
	 * Body bytes for a stream.
	 *
	 * @method onData
	 * @param {array} $frame
	 * @return {boolean}
	 */
	protected function onData($frame)
	{
		$F = 'Q_WebServer_Http2_Frame';
		$stream = $frame['stream'];
		if (!isset($this->streams[$stream])) return true;

		$payload = $F::unpad($frame['payload'], $frame['flags']);
		if ($payload === null) {
			$this->goaway($F::PROTOCOL_ERROR);
			return false;
		}

		$this->streams[$stream]['body'] .= $payload;

		// A body is held whole before the application sees it, so its size is
		// a memory cost this process pays on the peer's say-so.
		if (strlen($this->streams[$stream]['body']) > $this->limits['bodySize']) {
			$this->goaway(Q_WebServer_Http2_Frame::ENHANCE_YOUR_CALM);
			return false;
		}

		// Say the bytes were consumed, on both the stream and the connection,
		// or a client sending a large body stalls once it has filled the
		// window it was given.
		$size = strlen($frame['payload']);
		if ($size) {
			$this->write($F::build($F::WINDOW_UPDATE, 0, 0, $F::uint32($size)));
			$this->write($F::build($F::WINDOW_UPDATE, 0, $stream, $F::uint32($size)));
		}

		if ($frame['flags'] & $F::FLAG_END_STREAM) {
			return $this->dispatch($stream);
		}
		return true;
	}

	/**
	 * Turn a completed stream into a request, ask the handler, and write the
	 * response back on the same stream.
	 *
	 * @method dispatch
	 * @param {integer} $stream
	 * @return {boolean}
	 */
	protected function dispatch($stream)
	{
		$state = $this->streams[$stream];

		$request = array(
			'method'  => 'GET',
			'path'    => '/',
			'scheme'  => 'https',
			'authority' => '',
			'headers' => array(),
			'body'    => $state['body'],
			'stream'  => $stream,
		);

		foreach ((array) $state['headers'] as $pair) {
			list($name, $value) = $pair;
			switch ($name) {
			case ':method':    $request['method'] = $value; break;
			case ':path':      $request['path'] = $value; break;
			case ':scheme':    $request['scheme'] = $value; break;
			case ':authority': $request['authority'] = $value; break;
			default:
				if ($name !== '' and $name[0] === ':') break; // unknown pseudo
				// A field may appear more than once. Cookie is rejoined with
				// "; " as RFC 9113 section 8.2.3 requires; everything else
				// with a comma, which is the general rule.
				if (isset($request['headers'][$name])) {
					$request['headers'][$name] .= ($name === 'cookie' ? '; ' : ', ') . $value;
				} else {
					$request['headers'][$name] = $value;
				}
			}
		}

		// :authority is HTTP/2's spelling of Host, and everything downstream
		// reads Host.
		if ($request['authority'] !== '' and !isset($request['headers']['host'])) {
			$request['headers']['host'] = $request['authority'];
		}

		if (isset($request['headers']['accept-encoding'])) {
			$this->streams[$stream]['accept'] = $request['headers']['accept-encoding'];
		}

		try {
			$response = call_user_func($this->handler, $request);
		} catch (\Throwable $e) {
			// Never let this become silence. A client that agreed to h2 does
			// not fall back, so an uncaught throwable here is a blank window
			// and nothing else -- no status, no log the person looking at it
			// can reach.
			$this->respond($stream, Q_WebServer_Http2_ErrorPage::response(
				get_class($e) . ': ' . $e->getMessage(), $stream
			));
			return true;
		}

		// null means the handler will answer later -- a script handed to a
		// worker, whose reply arrives on another event. The stream stays open
		// and whoever holds it calls respond() when the answer exists. Without
		// this the connection could only ever serve what it could produce
		// synchronously, which is files and nothing else.
		if ($response === null) {
			$this->streams[$stream]['awaiting'] = true;
			return true;
		}

		if (!is_array($response)) {
			$response = Q_WebServer_Http2_ErrorPage::response(
				'the handler returned no response', $stream
			);
		} elseif (($response['status'] ?? 200) >= 500
			and ($response['body'] ?? '') === ''
		) {
			// A 5xx with nothing in it renders exactly like a failed
			// connection. Say what it was instead.
			$response = Q_WebServer_Http2_ErrorPage::response(
				'the application returned ' . (int) $response['status']
					. ' with an empty body',
				$stream, (int) $response['status']
			);
		}

		$this->respond($stream, $response);

		// The stream is deliberately NOT forgotten here.
		//
		// respond() sends what the flow-control window allows and leaves the
		// rest in the stream's 'pending', to go out when the peer grants more
		// room. Dropping the stream at this point threw that remainder away
		// along with the bookkeeping, so the WINDOW_UPDATE that arrived a
		// moment later found nothing to resume and the body simply stopped --
		// at exactly one window, every time, on a connection that stayed
		// healthy enough to answer a PING in about a millisecond.
		//
		// It went unnoticed because curl and Chromium advertise a window large
		// enough to swallow an entire response in one go. Firefox uses 128KB,
		// so it truncated a 183KB script and reported
		// NS_ERROR_NET_PARTIAL_TRANSFER, which is how this was found.
		//
		// flush() removes the stream itself once the last DATA frame carries
		// END_STREAM, and respond() removes it for a response with no body, so
		// nothing leaks by leaving it alone here.
		return true;
	}

	/**
	 * Write a response as HEADERS plus DATA.
	 *
	 * @method respond
	 * @param {integer} $stream
	 * @param {array} $response status, headers, body
	 */
	function respond($stream, $response)
	{
		$F = 'Q_WebServer_Http2_Frame';

		$status = isset($response['status']) ? (int) $response['status'] : 200;
		$body = isset($response['body']) ? (string) $response['body'] : '';
		$headers = (array) (isset($response['headers']) ? $response['headers'] : array());

		// Compress the body if the client said it would take it.
		//
		// HTTP/2 compresses headers and does nothing whatever about bodies, so
		// this is not handled for us. Without it a page that goes out as 9KB
		// gzipped over HTTP/1.1 leaves as 77KB here -- eight times the bytes,
		// over a connection whose whole selling point is fewer round trips.
		$accept = isset($this->streams[$stream]['accept'])
			? $this->streams[$stream]['accept'] : '';
		$alreadyEncoded = false;
		$contentType = '';
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			if ($lk === 'content-encoding') $alreadyEncoded = true;
			if ($lk === 'content-type') $contentType = (string) $v;
		}

		if (!$alreadyEncoded and $body !== ''
			and strpos(strtolower($accept), 'gzip') !== false
			and function_exists('gzencode')
			and self::compressible($contentType, strlen($body))
		) {
			$compressed = @gzencode($body, 6);
			if ($compressed !== false and strlen($compressed) < strlen($body)) {
				$body = $compressed;
				$headers['content-encoding'] = 'gzip';
				// Vary matters even here: a cache in front must not serve the
				// compressed copy to a client that did not ask for it.
				$headers['vary'] = isset($headers['vary'])
					? $headers['vary'] . ', Accept-Encoding' : 'Accept-Encoding';
			}
		}

		$list = array(array(':status', (string) $status));
		foreach ($headers as $k => $v) {
			$name = strtolower($k);
			// Connection-specific fields are forbidden in HTTP/2 and a peer is
			// entitled to treat one as a protocol error, so they are dropped
			// rather than passed on.
			if (in_array($name, array(
				'connection', 'keep-alive', 'proxy-connection',
				'transfer-encoding', 'upgrade'
			), true)) continue;

			// Length is restated below, after compression.
			if ($name === 'content-length') continue;

			if (is_array($v)) {
				foreach ($v as $one) $list[] = array($name, (string) $one);
			} else {
				$list[] = array($name, (string) $v);
			}
		}
		if ($body !== '') {
			$list[] = array('content-length', (string) strlen($body));
		}

		// Cookies the script set, which do not arrive in $headers.
		//
		// A worker builds them with setcookie()/setrawcookie() in its own
		// process, so the pooled response carries them separately -- $headers
		// is associative and could not hold two Set-Cookie lines anyway. The
		// HTTP/1.1 path has always merged this list in; this one never looked
		// at it, so every cookie a script set was silently dropped over HTTP/2.
		//
		// Signing in was therefore impossible over HTTP/2: the login answered
		// 302 to the right place and set no session, so the next request was
		// anonymous again. Measured on the same login, same second: two
		// Set-Cookie headers over HTTP/1.1 and none over HTTP/2.
		//
		// HPACK carries each as its own field, so unlike HTTP/1.1 there is
		// nothing to fold or split here.
		if (isset($response['cookies']) and is_array($response['cookies'])) {
			foreach ($response['cookies'] as $cookie) {
				if ($cookie === '' or $cookie === null) continue;
				$list[] = array('set-cookie', (string) $cookie);
			}
		}

		$block = $this->outbound->encode($list);

		$first = substr($block, 0, $this->maxFrameSize);
		$rest = substr($block, $this->maxFrameSize);
		$endHeaders = $rest === '' ? $F::FLAG_END_HEADERS : 0;
		$endStream = ($body === '' and $rest === '') ? $F::FLAG_END_STREAM : 0;

		$this->write($F::build($F::HEADERS, $endHeaders | $endStream, $stream, $first));

		while ($rest !== '') {
			$chunk = substr($rest, 0, $this->maxFrameSize);
			$rest = substr($rest, $this->maxFrameSize);
			$flags = $rest === '' ? $F::FLAG_END_HEADERS : 0;
			$this->write($F::build($F::CONTINUATION, $flags, $stream, $chunk));
		}

		if ($body === '') {
			unset($this->streams[$stream]);
			return;
		}

		if (!isset($this->streams[$stream])) {
			// The stream was reset while the answer was being prepared.
			return;
		}

		$this->streams[$stream]['pending'] = $body;
		$this->flush($stream);
	}

	/**
	 * Whether a body of this type and size is worth compressing.
	 *
	 * Decided here rather than by asking Q_WebServer_Headers, deliberately.
	 * The first attempt called that class behind class_exists(..., false),
	 * which does not autoload -- so whether a page was compressed depended on
	 * whether something else in the process had happened to load that class
	 * first. It had not, and every response went out uncompressed: 77KB where
	 * HTTP/1.1 sent 9KB. A transport decision must not rest on load order.
	 *
	 * @method compressible
	 * @static
	 * @param {string} $contentType
	 * @param {integer} $size
	 * @return {boolean}
	 */
	static function compressible($contentType, $size)
	{
		// Below about a packet there is nothing to win, and gzip has a header.
		if ($size < 512) return false;

		$base = strtolower(trim(strtok((string) $contentType, ';')));
		if ($base === '') return false;

		if (strncmp($base, 'text/', 5) === 0) return true;

		return in_array($base, array(
			'application/json', 'application/javascript', 'application/x-javascript',
			'application/xml', 'application/xhtml+xml', 'application/rss+xml',
			'application/atom+xml', 'application/ld+json', 'application/manifest+json',
			'image/svg+xml', 'application/wasm',
		), true);
	}

	/**
	 * Send as much of a stream's pending body as both windows allow.
	 *
	 * Flow control is not advisory. A peer advertises how much it is prepared
	 * to receive, on the stream and on the connection, and sending past either
	 * is a protocol error it may answer by resetting the stream or the whole
	 * connection. Writing the whole body regardless happens to work with a
	 * tolerant client and fails with a strict one, which is the worst kind of
	 * bug: it works until it does not.
	 *
	 * So what does not fit waits here, and the WINDOW_UPDATE that makes room
	 * calls this again.
	 *
	 * @method flush
	 * @param {integer} $stream
	 */
	function flush($stream)
	{
		$F = 'Q_WebServer_Http2_Frame';
		if (!isset($this->streams[$stream])) return;
		if ($this->streams[$stream]['finished']) return;

		while ($this->streams[$stream]['pending'] !== '') {
			$allowed = min(
				$this->sendWindow,
				$this->streams[$stream]['sendWindow'],
				$this->maxFrameSize
			);
			if ($allowed <= 0) return; // wait for a WINDOW_UPDATE

			$chunk = substr($this->streams[$stream]['pending'], 0, $allowed);
			$this->streams[$stream]['pending'] =
				substr($this->streams[$stream]['pending'], strlen($chunk));

			$last = $this->streams[$stream]['pending'] === '';

			$this->write($F::build(
				$F::DATA, $last ? $F::FLAG_END_STREAM : 0, $stream, $chunk
			));

			$this->sendWindow -= strlen($chunk);
			$this->streams[$stream]['sendWindow'] -= strlen($chunk);

			if ($last) {
				$this->streams[$stream]['finished'] = true;
				unset($this->streams[$stream]);
				return;
			}
		}
	}

	/**
	 * Tell the peer this connection is finished, naming the last stream that
	 * was acted on so it knows what to retry.
	 *
	 * @method goaway
	 * @param {integer} $error
	 */
	function goaway($error = 0)
	{
		if ($this->closed) return;

		// Tell whoever is waiting. GOAWAY is for the other implementation, not
		// for the person watching an empty window, so any stream still open
		// gets a page first -- explaining that the connection itself failed,
		// which is the one thing a browser will never show on its own.
		if ($error !== 0) {
			foreach (array_keys($this->streams) as $stream) {
				$this->respond($stream, Q_WebServer_Http2_ErrorPage::response(
					'the HTTP/2 connection failed with error code ' . $error, $stream
				));
			}
		}

		$this->closed = true;
		$F = 'Q_WebServer_Http2_Frame';
		$this->write($F::build($F::GOAWAY, 0, 0,
			$F::uint32($this->lastStream) . $F::uint32($error)));
	}

	/**
	 * Write to the socket, in full.
	 *
	 * @method write
	 * @param {string} $data
	 * @return {boolean}
	 */
	function write($data)
	{
		if (!is_resource($this->socket)) return false;
		$this->outBuffer .= $data;

		// Bytes a peer is not reading.
		//
		// What will not fit in the socket waits here, which is what makes a
		// large response survive a full send buffer. It also means a peer that
		// requests a great deal and then simply stops reading has handed this
		// process an unbounded allocation -- the write-side of slowloris, and
		// quieter than the read side, because the request was perfectly valid.
		if (strlen($this->outBuffer) > $this->limits['writeBuffer']) {
			$this->outBuffer = '';
			$this->closed = true;
			$this->unwatchWritable();
			return false;
		}

		return $this->drain();
	}

	/**
	 * Push as much of the output buffer onto the socket as it will take.
	 *
	 * The socket is non-blocking, because one event loop serves every
	 * connection and no single peer may be allowed to stop the others. On a
	 * non-blocking socket fwrite() writes what fits in the kernel send buffer
	 * and returns a short count -- or 0 when that buffer is already full.
	 *
	 * Zero is not an error. It means "not now, ask again when the socket is
	 * writable", and the previous code here treated it as fatal and returned,
	 * abandoning every byte that had not gone out. Nothing on this machine ever
	 * noticed: over loopback and on the LAN the peer drains faster than we can
	 * fill, so the buffer never fills and the short-write path is never taken.
	 * Across a real network it fills on any sizeable body, and the response
	 * simply stopped part-way -- a browser reports that as
	 * NS_ERROR_NET_PARTIAL_TRANSFER, with no error anywhere on the server.
	 *
	 * So what will not fit waits here, and a writability watcher sends it.
	 *
	 * @method drain
	 * @return {boolean} false only on a genuinely broken socket
	 */
	function drain()
	{
		if (!is_resource($this->socket)) return false;

		while ($this->outBuffer !== '') {
			$n = @fwrite($this->socket, $this->outBuffer);

			if ($n === false) {
				// A broken pipe, as opposed to a full one.
				return false;
			}
			if ($n === 0) {
				// Full. Keep the rest and wait to be told there is room.
				$this->watchWritable();
				return true;
			}
			$this->outBuffer = substr($this->outBuffer, $n);
		}

		$this->unwatchWritable();
		return true;
	}

	/**
	 * Count a frame that obliges us to write something back.
	 *
	 * PING and SETTINGS are a few bytes to send and require an answer, so a
	 * peer can make this process do bounded work per frame at a rate limited
	 * only by its own bandwidth. Bounded work, unbounded times, is unbounded.
	 *
	 * @method reflex
	 * @return {boolean} false when the connection should be closed
	 */
	protected function reflex()
	{
		if (++$this->reflexFrames <= $this->limits['reflexFrames']) {
			return true;
		}
		$this->goaway(Q_WebServer_Http2_Frame::ENHANCE_YOUR_CALM);
		return false;
	}

	/**
	 * Has this connection sat doing nothing for longer than it may?
	 *
	 * A socket held open costs a file descriptor whether or not anything is
	 * travelling over it, and descriptors are the scarcest thing this process
	 * has. A peer that opens connections and says nothing -- slowloris, in its
	 * oldest form -- exhausts them without sending a single valid request.
	 *
	 * Asked by the event loop rather than enforced here, because only the loop
	 * knows about the other connections and can sweep them together.
	 *
	 * @method isIdle
	 * @param {float} $now
	 * @return {boolean}
	 */
	function isIdle($now = null)
	{
		if ($now === null) $now = microtime(true);
		if (!$this->lastActivity) return false;
		// A connection with work in hand is not idle, however quiet the peer:
		// it may be waiting on us.
		foreach ($this->streams as $each) {
			if (!empty($each['pending'])) return false;
		}
		return ($now - $this->lastActivity) > $this->limits['idleSeconds'];
	}

	/**
	 * Release anything the loop is holding for this connection.
	 *
	 * @method shutdown
	 */
	function shutdown()
	{
		$this->unwatchWritable();
	}

	/**
	 * Ask the loop to call drain() when the socket can take more.
	 *
	 * @method watchWritable
	 */
	protected function watchWritable()
	{
		if ($this->writeWatcher !== null) return;
		if (!class_exists('Q_Evented')) return;

		$self = $this;
		$this->writeWatcher = Q_Evented::onWritable(
			$this->socket,
			function () use ($self) { $self->drain(); }
		);
	}

	/**
	 * Stop being told about writability once there is nothing left to send.
	 *
	 * Left in place it would fire on every pass of the loop for the life of the
	 * connection, since an idle socket is always writable.
	 *
	 * @method unwatchWritable
	 */
	protected function unwatchWritable()
	{
		if ($this->writeWatcher === null) return;
		if (class_exists('Q_Evented')) {
			Q_Evented::cancel($this->writeWatcher);
		}
		$this->writeWatcher = null;
	}
}
