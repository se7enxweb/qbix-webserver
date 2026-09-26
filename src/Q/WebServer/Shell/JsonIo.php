<?php
/**
 * @module Q
 */
/**
 * The runner's side of the server protocol: every message is one JSON object
 * on its own line of stdout; answers to prompts arrive the same way on stdin.
 *
 *   out     {"t":"out","d":"text"}          standard output
 *   err     {"t":"err","d":"text"}          standard error
 *   prompt  {"t":"prompt","d":"Proceed? "}  a question; the answer comes as
 *                                           {"t":"stdin","d":"y"} on stdin
 *   ctl     {"t":"ctl","op":"clear"|...}    terminal control (see Io::ctl)
 *   line    {"t":"line","d":"expanded"}     the line after history expansion
 *   state   {"t":"state","vars":{...}}      the session variables at the end
 *   exit    {"t":"exit","code":0}           the last message
 *
 * @class Q_WebServer_Shell_JsonIo
 */
class Q_WebServer_Shell_JsonIo extends Q_WebServer_Shell_Io
{
	private $in;
	private $outStream;
	private $interactive;

	/** @var array messages read from stdin while waiting for another */
	private $pending = array();

	/** @var boolean whether the server runs the engine's commands for this runner (see runOnServer()) */
	public $serverRun = false;

	function __construct($in = null, $out = null, $interactive = true)
	{
		$this->in = $in ?: STDIN;
		$this->outStream = $out ?: STDOUT;
		$this->interactive = (bool) $interactive;
	}

	function send(array $message)
	{
		fwrite($this->outStream, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
		fflush($this->outStream);
	}

	function out($text) { if ($text !== '') $this->send(array('t' => 'out', 'd' => (string) $text)); }
	function err($text) { if ($text !== '') $this->send(array('t' => 'err', 'd' => (string) $text)); }
	function ctl(array $message) { $this->send(array('t' => 'ctl') + $message); }
	function interactive() { return $this->interactive; }


	/**
	 * Have the server check a password (sudo): the runner never reads the
	 * password file itself, and may not be able to (Q.shell.user).
	 * @return {array} array(ok, until, error)
	 */
	function verify($password)
	{
		$m = $this->request(array('t' => 'verify', 'd' => (string) $password), 'verified');
		if ($m === null) return array(false, 0, 'the server did not answer');
		return array(!empty($m['ok']), (int) ($m['until'] ?? 0), isset($m['error']) ? (string) $m['error'] : null);
	}

	/**
	 * Send a message and wait for the server's answer of type $type. What
	 * else arrives meanwhile (the visitor's typing) is kept for later.
	 * @method request
	 * @return {array|null} the answer, or null when the server has gone
	 */
	function request(array $message, $type)
	{
		$this->send($message);
		return $this->next($type);
	}

	/** The next message of $type from stdin: a kept one first. */
	private function next($type)
	{
		foreach ($this->pending as $i => $m) {
			if (($m['t'] ?? '') === $type) { array_splice($this->pending, $i, 1); return $m; }
		}
		while (($line = fgets($this->in)) !== false) {
			$m = json_decode($line, true);
			if (!is_array($m)) continue;
			if (($m['t'] ?? '') === $type) return $m;
			if (count($this->pending) < 1000) $this->pending[] = $m;
		}
		return null;
	}

	/**
	 * Have the server run a command with its own rights -- an engine console
	 * command, or reading its logs -- and pass its output on as it comes.
	 * The server checks the tier and the password again.
	 * @param {array} $request kind (console|logs), name, args, stdin
	 * @return {integer} the command's exit status
	 */
	function runOnServer(array $request, Q_WebServer_Shell_Sink $sink)
	{
		$this->send(array('t' => 'run') + $request);
		$cut = false;
		while (($line = fgets($this->in)) !== false) {
			$m = json_decode($line, true);
			if (!is_array($m)) continue;
			$t = $m['t'] ?? '';
			if ($t === 'run_out') {
				$d = (string) ($m['d'] ?? '');
				if (($m['s'] ?? 'out') === 'err') { $sink->error($d); continue; }
				if ($cut) continue;
				if (!$sink->write($d)) {
					// Over the output cap: the server stops the command.
					$cut = true;
					$this->send(array('t' => 'run_stop'));
				}
				continue;
			}
			if ($t === 'run_exit') {
				if (isset($m['error']) && $m['error'] !== null && $m['error'] !== '') $sink->error('qsh: ' . $m['error'] . "\n");
				return $cut ? 141 : (int) ($m['code'] ?? 1);
			}
			// Anything else (typing meanwhile) is kept for whoever asks next.
			if (count($this->pending) < 1000) $this->pending[] = $m;
		}
		$sink->error("qsh: the server did not answer\n");
		return 1;
	}

	function prompt($question, $secret = false)
	{
		if (!$this->interactive) return null;
		$m = $this->request(array('t' => 'prompt', 'd' => (string) $question, 'secret' => (bool) $secret), 'stdin');
		return $m === null ? null : rtrim((string) ($m['d'] ?? ''), "\r\n");
	}
}
