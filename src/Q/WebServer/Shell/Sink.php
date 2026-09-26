<?php
/**
 * @module Q
 */
/**
 * Where one command's output goes: straight to the terminal, or into a buffer
 * for the next stage of a pipe or a $(substitution). Errors always go to the
 * terminal, as a shell's stderr does.
 *
 * @class Q_WebServer_Shell_Sink
 */
class Q_WebServer_Shell_Sink
{
	/** @var string what a capturing sink collected */
	public $buffer = '';

	/** @var integer bytes written, against the output cap */
	public $bytes = 0;

	private $io;
	private $capture;
	private $cap;

	/**
	 * @param {Q_WebServer_Shell_Io} $io
	 * @param {boolean} [$capture=false]
	 * @param {integer} [$cap=0] bytes allowed before output is cut, 0 = no cap
	 */
	function __construct(Q_WebServer_Shell_Io $io, $capture = false, $cap = 0)
	{
		$this->io = $io;
		$this->capture = (bool) $capture;
		$this->cap = (int) $cap;
	}

	/** A capturing sink over the same terminal. */
	function capturing()
	{
		return new self($this->io, true, $this->cap);
	}

	/**
	 * Write standard output.
	 * @return {boolean} false once the cap is reached (the writer should stop)
	 */
	function write($text)
	{
		$text = (string) $text;
		if ($text === '') return true;
		if ($this->cap > 0 && $this->bytes + strlen($text) > $this->cap) {
			$text = substr($text, 0, max(0, $this->cap - $this->bytes));
			$this->emit($text);
			$this->bytes = $this->cap;
			$this->io->err("\nqsh: output cut at " . $this->cap . " bytes\n");
			return false;
		}
		$this->bytes += strlen($text);
		$this->emit($text);
		return true;
	}

	private function emit($text)
	{
		if ($text === '') return;
		if ($this->capture) $this->buffer .= $text;
		else $this->io->out($text);
	}

	/** Write standard error. */
	function error($text)
	{
		$this->io->err((string) $text);
	}

	function io() { return $this->io; }

	/** Whether the output goes into a pipe or a $(substitution), not the terminal. */
	function captured() { return $this->capture; }
}
