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
 * And each stream is held to a budget a second -- MAX_PER_SECOND lines and
 * MAX_BYTES_PER_SECOND bytes -- with each line cut at MAX_LINE: a renderer
 * that writes without end, broken or made to, would otherwise fill the disk
 * the application's own lines are written to. Per stream, so one cannot
 * spend the others' share. Past the budget a line is dropped, and the
 * stream's next line in a later second says how many were. Standard error
 * is serve's to pass on whole, as it always was.
 *
 * What a line says of itself is believed no further than it must be. Its
 * level is capped -- at error on standard error, at warning on standard
 * output -- as an unverified browser error is, so a renderer cannot page
 * anyone at critical. Its own fields go under one key, `renderer`, never
 * beside the line's: it cannot set a `digest`, a `user_id` or an
 * `exception` that reads as PHP's.
 */
final class RendererLines
{
    /** The longest line relayed: a render's failure with its stack, redacted, cut past this. */
    public const int MAX_LINE = 8_192;

    /** How deep a line's JSON is read: an error's fields, and a little more. */
    private const int MAX_DEPTH = 8;

    /** Lines a second the log takes from one stream. */
    public const int MAX_PER_SECOND = 500;

    /** Bytes a second the log takes from one stream. */
    public const int MAX_BYTES_PER_SECOND = 1_048_576;

    /** What the line itself owns, taken from the renderer's only as checked. */
    private const array OWNED = ['time', 'level', 'message', 'source', 'request_id'];

    private readonly Logger $logger;

    /** The request id of the line being written: what contextFrom() hands the Logger. */
    private ?string $requestId = null;

    /** @var array<string, string> each stream's bytes since its last newline */
    private array $partial = [];

    /** @var array<string, array{second: int, lines: int, bytes: int, dropped: int}> each stream's budget */
    private array $budgets = [];

    /** @var array<string, string|null> each stream mid-way through a line cut for its length: the request id it named */
    private array $continued = [];

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
            $this->piece(substr($buffer, 0, $end), $error, $stream, false);
            $buffer = substr($buffer, $end + 1);
        }
        // A line that never ends is not held without end: written in pieces,
        // each under the request id its first named.
        if (\strlen($buffer) > self::MAX_LINE) {
            $this->piece($buffer, $error, $stream, true);
            $buffer = '';
        }
        $this->partial[$stream] = $buffer;
    }

    /**
     * The end of a stream: what it left without a newline, written; what
     * its last second dropped, said; and its budget forgotten -- a renderer
     * replaced writes on streams of its own, and the old one's would
     * otherwise be kept, and their count never said.
     */
    public function close(string $stream, bool $error): void
    {
        $rest = $this->partial[$stream] ?? '';
        unset($this->partial[$stream]);
        if ($rest !== '') {
            $this->piece($rest, $error, $stream, false);
        }
        unset($this->continued[$stream]);

        $dropped = $this->budgets[$stream]['dropped'] ?? 0;
        unset($this->budgets[$stream]);
        if ($dropped > 0) {
            $this->dropped($dropped);
        }
    }

    /** One line, rebuilt and written; see the class. */
    public function line(string $line, bool $error, string $stream = ''): void
    {
        $this->rebuild($line, $error, $stream, null);
    }

    private function dropped(int $dropped): void
    {
        $this->logger->log('warning', 'renderer lines dropped', [
            'dropped' => $dropped,
            'per_second' => self::MAX_PER_SECOND,
            'bytes_per_second' => self::MAX_BYTES_PER_SECOND,
        ]);
    }

    /**
     * A piece of a stream: a whole line, or -- $more -- the first part of
     * one cut for its length, whose request id the rest of it is written
     * under too.
     */
    private function piece(string $line, bool $error, string $stream, bool $more): void
    {
        $continuing = \array_key_exists($stream, $this->continued);
        $carried = $this->continued[$stream] ?? null;
        if ($more && !$continuing) {
            $carried = self::idIn($line);
        }
        if ($more) {
            $this->continued[$stream] = $carried;
        } else {
            unset($this->continued[$stream]);
        }

        $this->rebuild($line, $error, $stream, $carried, $more || $continuing);
    }

    /**
     * The request id a line too long to read as JSON names, as PHP's
     * Logger and the renderer write one: read from its text, and only in
     * the one shape PHP gives an id.
     */
    private static function idIn(string $line): ?string
    {
        if (preg_match('/"request_id"\s*:\s*"([^"\\\\]{1,64})"/', substr($line, 0, self::MAX_LINE), $m) !== 1) {
            return null;
        }

        return RequestId::isValid($m[1]) ? $m[1] : null;
    }

    /**
     * @param string|null $carried the request id of the line this is a piece of
     * @param bool $piece whether it is a piece of a line cut for its length
     */
    private function rebuild(string $line, bool $error, string $stream, ?string $carried, bool $piece = false): void
    {
        $cut = $piece || \strlen($line) > self::MAX_LINE;
        if ($cut && !$piece) {
            $carried = self::idIn($line);
        }
        $line = trim(\strlen($line) > self::MAX_LINE ? substr($line, 0, self::MAX_LINE) : $line);
        if ($line === '' || !$this->spend($stream, \strlen($line))) {
            return;
        }

        $ceiling = $error ? 'error' : 'warning';
        $fallback = $error ? 'error' : 'info';
        $decoded = $cut ? null : json_decode($line, true, self::MAX_DEPTH);
        if (!\is_array($decoded) || array_is_list($decoded) || !\is_string($decoded['message'] ?? null)) {
            $this->write($fallback, $line, $cut ? ['truncated' => true] : [], $carried);

            return;
        }

        $level = $decoded['level'] ?? null;
        $level = \is_string($level) && isset(Logger::LEVELS[$level]) ? $level : $fallback;
        if (Logger::LEVELS[$level] > Logger::LEVELS[$ceiling]) {
            $level = $ceiling;
        }
        $id = $decoded['request_id'] ?? null;
        $fields = array_diff_key($decoded, array_flip(self::OWNED));
        $this->write(
            $level,
            $decoded['message'],
            $fields === [] ? [] : ['renderer' => $fields],
            \is_string($id) && RequestId::isValid($id) ? $id : null,
        );
    }

    /**
     * Whether $stream may write a line of $bytes this second, counting it if
     * so. A new second's first line first says how many the last dropped.
     */
    private function spend(string $stream, int $bytes): bool
    {
        $second = (int) floor(($this->clock)());
        $budget = $this->budgets[$stream] ?? ['second' => $second, 'lines' => 0, 'bytes' => 0, 'dropped' => 0];
        if ($budget['second'] !== $second) {
            if ($budget['dropped'] > 0) {
                $this->dropped($budget['dropped']);
            }
            $budget = ['second' => $second, 'lines' => 0, 'bytes' => 0, 'dropped' => 0];
        }

        if ($budget['lines'] + 1 > self::MAX_PER_SECOND || $budget['bytes'] + $bytes > self::MAX_BYTES_PER_SECOND) {
            $budget['dropped']++;
            $this->budgets[$stream] = $budget;

            return false;
        }
        $budget['lines']++;
        $budget['bytes'] += $bytes;
        $this->budgets[$stream] = $budget;

        return true;
    }

    /** @param array<array-key, mixed> $context */
    private function write(string $level, string $message, array $context, ?string $requestId): void
    {
        $this->requestId = $requestId;
        try {
            $this->logger->log($level, $message, $context);
        } finally {
            $this->requestId = null;
        }
    }
}
