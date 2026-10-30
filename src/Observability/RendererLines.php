<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * The renderers' lines, written into the application's log as well as to
 * `tinvar serve`'s standard error (ADR 0128).
 *
 * The renderer writes one JSON object a line, in the shape PHP's Logger
 * writes, with `source: renderer` and the request id PHP put in its frame
 * (ADR 0041, 0112). It is sandboxed and may write nothing but its standard
 * output and error, which serve reads; serve hands each line here, and it
 * goes into the application's log through a Logger of its own, so the two
 * sides of a request are in one file under one id.
 *
 * The lines are the renderer's, the side that runs third-party npm code, so
 * none is copied. Each is rebuilt: its message and every field go through
 * this side's Redactor -- the renderer redacted them already, and is not
 * what this relies on -- the time is this process's, `source` is always
 * `renderer`, a `request_id` is kept only in the one shape PHP gives one,
 * and a level is kept only when it is one of the Logger's, else the
 * stream's: info for standard output, error for standard error. A line
 * that is not a JSON object -- Node's own words on a crash -- is a message,
 * redacted, at the stream's level. A line past MAX_LINE is cut there, and
 * says so.
 *
 * And at most MAX_PER_SECOND lines a second reach the log: a renderer that
 * writes without end -- broken, or made to -- would otherwise fill the disk
 * the application's own lines are written to. Past the budget a line is
 * dropped, and the next second's first line says how many were. Standard
 * error is serve's to pass on whole, as it always was.
 */
final class RendererLines
{
    /** The longest line kept whole: a render's failure, its stack redacted, with room to spare. */
    public const int MAX_LINE = 65_536;

    /** How deep a line's JSON is read: an error's fields, and a little more. */
    private const int MAX_DEPTH = 8;

    /** Lines a second the log takes from the renderers, all of them together. */
    public const int MAX_PER_SECOND = 500;

    /** What the line itself owns, taken from the renderer's only as checked. */
    private const array OWNED = ['time', 'level', 'message', 'source', 'request_id'];

    private readonly Logger $logger;

    /** The request id of the line being written: what contextFrom() hands the Logger. */
    private ?string $requestId = null;

    /** @var array<string, string> each stream's bytes since its last newline */
    private array $partial = [];

    /** The second the budget is counted in, its lines so far, and how many were dropped in it. */
    private int $second = 0;
    private int $written = 0;
    private int $dropped = 0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param string $level the least severe level written, as the application's log has it
     * @param (\Closure(): float)|null $clock for tests: the time, as microtime(true)
     */
    public function __construct(LogSink $sink, string $level = 'info', Redactor $redactor = new Redactor(), ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->logger = new Logger($sink, $redactor, $level, source: 'renderer');
        $this->logger->contextFrom(fn (): array => $this->requestId === null ? [] : ['request_id' => $this->requestId]);
    }

    /**
     * Bytes read from one of a renderer's streams: each whole line written,
     * the rest held for the next read.
     *
     * @param string $stream which stream of which process, so two never join halves
     * @param bool $error whether it is standard error, which sets the level a line without one gets
     */
    public function feed(string $stream, string $bytes, bool $error): void
    {
        $buffer = ($this->partial[$stream] ?? '') . $bytes;
        while (($end = strpos($buffer, "\n")) !== false) {
            $this->line(substr($buffer, 0, $end), $error);
            $buffer = substr($buffer, $end + 1);
        }
        // A line that never ends is not held without end.
        if (\strlen($buffer) > self::MAX_LINE) {
            $this->line($buffer, $error);
            $buffer = '';
        }
        $this->partial[$stream] = $buffer;
    }

    /** The end of a stream: what it left without a newline, written. */
    public function close(string $stream, bool $error): void
    {
        $rest = $this->partial[$stream] ?? '';
        unset($this->partial[$stream]);
        if ($rest !== '') {
            $this->line($rest, $error);
        }
    }

    /** One line, rebuilt and written; see the class. */
    public function line(string $line, bool $error): void
    {
        $cut = \strlen($line) > self::MAX_LINE;
        $line = trim($cut ? substr($line, 0, self::MAX_LINE) : $line);
        if ($line === '') {
            return;
        }

        $fallback = $error ? 'error' : 'info';
        $decoded = $cut ? null : json_decode($line, true, self::MAX_DEPTH);
        if (!\is_array($decoded) || array_is_list($decoded) || !\is_string($decoded['message'] ?? null)) {
            $this->write($fallback, $line, $cut ? ['truncated' => true] : [], null);

            return;
        }

        $level = $decoded['level'] ?? null;
        $id = $decoded['request_id'] ?? null;
        $context = array_diff_key($decoded, array_flip(self::OWNED));
        $this->write(
            \is_string($level) && isset(Logger::LEVELS[$level]) ? $level : $fallback,
            $decoded['message'],
            $context,
            \is_string($id) && RequestId::isValid($id) ? $id : null,
        );
    }

    /** @param array<array-key, mixed> $context */
    private function write(string $level, string $message, array $context, ?string $requestId): void
    {
        $second = (int) floor(($this->clock)());
        if ($second !== $this->second) {
            $dropped = $this->dropped;
            $this->second = $second;
            $this->written = 0;
            $this->dropped = 0;
            if ($dropped > 0) {
                $this->written++;
                $this->logger->log('warning', 'renderer lines dropped', ['dropped' => $dropped, 'per_second' => self::MAX_PER_SECOND]);
            }
        }
        if (++$this->written > self::MAX_PER_SECOND) {
            $this->dropped++;

            return;
        }

        $this->requestId = $requestId;
        try {
            $this->logger->log($level, $message, $context);
        } finally {
            $this->requestId = null;
        }
    }
}
